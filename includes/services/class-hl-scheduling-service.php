<?php
if (!defined('ABSPATH')) exit;

/**
 * Scheduling Orchestration Service
 *
 * Coordinates slot calculation, booking, rescheduling, and cancellation
 * across HL_Coaching_Service, HL_Microsoft_Graph, HL_Zoom_Integration,
 * and HL_Scheduling_Email_Service.
 *
 * @package HL_Core
 */
class HL_Scheduling_Service {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_hl_get_available_slots', array($this, 'ajax_get_available_slots'));
        add_action('wp_ajax_hl_book_session', array($this, 'ajax_book_session'));
        add_action('wp_ajax_hl_reschedule_session', array($this, 'ajax_reschedule_session'));
        add_action('wp_ajax_hl_cancel_session', array($this, 'ajax_cancel_session'));
        add_action('wp_ajax_hl_mark_attendance', array($this, 'ajax_mark_attendance'));
    }

    // =========================================================================
    // Slot Calculation
    // =========================================================================

    /**
     * Get available time slots for a coach on a given date.
     *
     * @param int    $coach_user_id
     * @param string $date_string    Y-m-d date.
     * @param string $mentor_timezone IANA timezone.
     * @return array { slots: array, outlook_unavailable: bool }
     */
    public function get_available_slots($coach_user_id, $date_string, $mentor_timezone) {
        $settings       = HL_Admin_Scheduling_Settings::get_scheduling_settings();
        $duration       = (int) $settings['session_duration'];
        $min_lead_hours = (int) $settings['min_lead_time_hours'];
        $max_lead_days  = (int) $settings['max_lead_time_days'];

        // Coach timezone.
        $coach_tz_string = get_user_meta($coach_user_id, 'hl_timezone', true);
        if (empty($coach_tz_string)) {
            $coach_tz_string = wp_timezone_string();
        }

        try {
            $coach_tz  = new DateTimeZone($coach_tz_string);
            $mentor_tz = new DateTimeZone($mentor_timezone);
        } catch (Exception $e) {
            $coach_tz  = wp_timezone();
            $mentor_tz = wp_timezone();
        }

        // Determine day_of_week in coach's timezone.
        $date_in_coach_tz = new DateTime($date_string, $coach_tz);
        $day_of_week      = (int) $date_in_coach_tz->format('w'); // 0=Sun, 6=Sat.

        // Get coach availability blocks for this day.
        $dashboard_service = new HL_Coach_Dashboard_Service();
        $all_availability  = $dashboard_service->get_availability($coach_user_id);
        $day_blocks        = array_filter($all_availability, function ($block) use ($day_of_week) {
            return (int) $block['day_of_week'] === $day_of_week;
        });

        if (empty($day_blocks)) {
            return array('slots' => array(), 'outlook_unavailable' => false);
        }

        // Slice availability blocks into duration-minute slots.
        $slots = array();
        foreach ($day_blocks as $block) {
            $start = new DateTime($date_string . ' ' . $block['start_time'], $coach_tz);
            $end   = new DateTime($date_string . ' ' . $block['end_time'], $coach_tz);
            $interval = new DateInterval('PT' . $duration . 'M');

            while (true) {
                $slot_end = clone $start;
                $slot_end->add($interval);
                if ($slot_end > $end) {
                    break;
                }
                $slots[] = array(
                    'start' => clone $start,
                    'end'   => clone $slot_end,
                );
                $start = $slot_end;
            }
        }

        if (empty($slots)) {
            return array('slots' => array(), 'outlook_unavailable' => false);
        }

        // Fetch Outlook calendar conflicts.
        $outlook_unavailable = false;
        $outlook_busy        = array();

        $graph = HL_Microsoft_Graph::instance();
        if ($graph->is_configured()) {
            $coach_email = $graph->get_coach_email($coach_user_id);

            // Build UTC range for calendarView, padded +1h for timezone boundaries.
            $range_start = clone $slots[0]['start'];
            $range_start->setTimezone(new DateTimeZone('UTC'));
            $range_end = clone $slots[count($slots) - 1]['end'];
            $range_end->modify('+1 hour');
            $range_end->setTimezone(new DateTimeZone('UTC'));

            // Check transient cache first.
            $cache_key = 'hl_calendar_' . $coach_user_id . '_' . $date_string;
            $cached    = get_transient($cache_key);

            if ($cached !== false) {
                $outlook_busy = $cached;
            } else {
                $events = $graph->get_calendar_events(
                    $coach_email,
                    $range_start->format('Y-m-d\TH:i:s\Z'),
                    $range_end->format('Y-m-d\TH:i:s\Z')
                );

                if (is_wp_error($events)) {
                    $outlook_unavailable = true;
                } else {
                    // Filter by showAs: only block on busy, oof, or unknown.
                    $blocking_statuses = array('busy', 'oof', 'unknown');
                    try {
                        $utc_tz = new DateTimeZone('UTC');
                        foreach ($events as $event) {
                            $show_as = isset($event['showAs']) ? strtolower($event['showAs']) : 'unknown';
                            if (!in_array($show_as, $blocking_statuses, true)) {
                                continue;
                            }
                            $outlook_busy[] = array(
                                'start' => new DateTime($event['start']['dateTime'], $utc_tz),
                                'end'   => new DateTime($event['end']['dateTime'], $utc_tz),
                            );
                        }
                    } catch (Exception $e) {
                        $outlook_unavailable = true;
                        $outlook_busy        = array();
                    }
                    set_transient($cache_key, $outlook_busy, 3 * MINUTE_IN_SECONDS);
                }
            }
        } else {
            // Graph not configured — warn the user.
            $outlook_unavailable = true;
        }

        // Fetch existing HL coaching sessions for this date.
        global $wpdb;
        $wp_tz        = wp_timezone();
        $day_start_wp = new DateTime($date_string . ' 00:00:00', $coach_tz);
        $day_start_wp->setTimezone($wp_tz);
        $day_end_wp = new DateTime($date_string . ' 23:59:59', $coach_tz);
        $day_end_wp->setTimezone($wp_tz);

        $existing_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT session_datetime FROM {$wpdb->prefix}hl_coaching_session
             WHERE coach_user_id = %d AND session_status = 'scheduled'
             AND session_datetime BETWEEN %s AND %s",
            $coach_user_id,
            $day_start_wp->format('Y-m-d H:i:s'),
            $day_end_wp->format('Y-m-d H:i:s')
        ), ARRAY_A);

        $hl_busy = array();
        foreach ($existing_sessions as $sess) {
            $sess_start = new DateTime($sess['session_datetime'], $wp_tz);
            $sess_end   = clone $sess_start;
            $sess_end->modify('+' . $duration . ' minutes');
            $hl_busy[] = array('start' => $sess_start, 'end' => $sess_end);
        }

        // Subtract conflicts from slots.
        $available = array();
        $now       = new DateTime('now', $coach_tz);
        $min_start = clone $now;
        $min_start->modify('+' . $min_lead_hours . ' hours');
        $max_start = clone $now;
        $max_start->modify('+' . $max_lead_days . ' days');

        foreach ($slots as $slot) {
            // Lead time rules.
            if ($slot['start'] < $min_start || $slot['start'] > $max_start) {
                continue;
            }

            // Outlook conflicts.
            if ($this->overlaps_any($slot, $outlook_busy)) {
                continue;
            }

            // Existing HL sessions.
            if ($this->overlaps_any_wp_tz($slot, $hl_busy, $wp_tz, $coach_tz)) {
                continue;
            }

            // Convert to mentor timezone for display.
            $display_start = clone $slot['start'];
            $display_start->setTimezone($mentor_tz);
            $display_end = clone $slot['end'];
            $display_end->setTimezone($mentor_tz);

            $available[] = array(
                'start_time'    => $slot['start']->format('H:i'),
                'end_time'      => $slot['end']->format('H:i'),
                'coach_tz'      => $coach_tz_string,
                'display_label' => $display_start->format('g:i A') . ' – ' . $display_end->format('g:i A'),
                'start_utc'     => (clone $slot['start'])->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            );
        }

        return array('slots' => $available, 'outlook_unavailable' => $outlook_unavailable);
    }

    /**
     * Check if a slot overlaps with any busy period.
     */
    private function overlaps_any($slot, $busy_list) {
        foreach ($busy_list as $busy) {
            if ($slot['start'] < $busy['end'] && $slot['end'] > $busy['start']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a slot (in coach TZ) overlaps with busy periods stored in WP TZ.
     */
    private function overlaps_any_wp_tz($slot, $busy_list, $wp_tz, $coach_tz) {
        // Convert slot to WP timezone for comparison.
        $slot_start_wp = clone $slot['start'];
        $slot_start_wp->setTimezone($wp_tz);
        $slot_end_wp = clone $slot['end'];
        $slot_end_wp->setTimezone($wp_tz);

        foreach ($busy_list as $busy) {
            if ($slot_start_wp < $busy['end'] && $slot_end_wp > $busy['start']) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Booking
    // =========================================================================

    /**
     * Book a coaching session with Zoom + Outlook integration.
     *
     * @param array $data {
     *     @type int    $mentor_enrollment_id
     *     @type int    $coach_user_id
     *     @type int    $component_id
     *     @type string $date          Y-m-d in coach timezone.
     *     @type string $start_time    H:i in coach timezone.
     *     @type string $timezone      Mentor's IANA timezone.
     * }
     * @return array|WP_Error { session_id, meeting_url }
     */
    public function book_session($data) {
        global $wpdb;

        // Validate required fields.
        $required = array('mentor_enrollment_id', 'coach_user_id', 'component_id', 'date', 'start_time', 'timezone');
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'hl-core'), $field));
            }
        }

        $enrollment_id = absint($data['mentor_enrollment_id']);
        $coach_user_id = absint($data['coach_user_id']);
        $component_id  = absint($data['component_id']);
        $mentor_tz     = sanitize_text_field($data['timezone']);

        // Permission check.
        $perm = $this->check_booking_permission($enrollment_id, $coach_user_id);
        if (is_wp_error($perm)) {
            return $perm;
        }

        // Resolve coach timezone.
        $coach_tz_string = get_user_meta($coach_user_id, 'hl_timezone', true);
        if (empty($coach_tz_string)) {
            $coach_tz_string = wp_timezone_string();
        }

        // Build session datetime in WP local time (codebase convention).
        $settings = HL_Admin_Scheduling_Settings::get_scheduling_settings();
        $duration = (int) $settings['session_duration'];

        try {
            $coach_tz       = new DateTimeZone($coach_tz_string);
            $session_start  = new DateTime($data['date'] . ' ' . $data['start_time'], $coach_tz);
            $session_end    = clone $session_start;
            $session_end->modify('+' . $duration . ' minutes');

            // Convert to WP local time for DB storage.
            $wp_tz = wp_timezone();
            $session_start_wp = clone $session_start;
            $session_start_wp->setTimezone($wp_tz);
            $session_datetime = $session_start_wp->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return new WP_Error('invalid_datetime', __('Invalid date or time.', 'hl-core'));
        }

        // Resolve enrollment data.
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, u.display_name AS mentor_name, u.user_email AS mentor_email
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.enrollment_id = %d",
            $enrollment_id
        ), ARRAY_A);

        if (!$enrollment) {
            return new WP_Error('enrollment_not_found', __('Enrollment not found.', 'hl-core'));
        }

        $coach_user = get_userdata($coach_user_id);
        if (!$coach_user) {
            return new WP_Error('coach_not_found', __('Coach not found.', 'hl-core'));
        }

        // Resolve session number from component ordering.
        $session_number = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_component
             WHERE pathway_id = (SELECT pathway_id FROM {$wpdb->prefix}hl_component WHERE component_id = %d)
             AND component_type = 'coaching_session_attendance'
             AND ordering_hint <= (SELECT ordering_hint FROM {$wpdb->prefix}hl_component WHERE component_id = %d)",
            $component_id, $component_id
        ));

        $session_title = sprintf('Coaching Session - %s/%s', $enrollment['mentor_name'], $coach_user->display_name);

        // Step 1: Create DB record.
        $coaching_service = new HL_Coaching_Service();
        $session_id = $coaching_service->create_session(array(
            'cycle_id'              => $enrollment['cycle_id'],
            'coach_user_id'         => $coach_user_id,
            'mentor_enrollment_id'  => $enrollment_id,
            'session_number'        => $session_number ?: null,
            'session_title'         => $session_title,
            'session_datetime'      => $session_datetime,
            'component_id'          => $component_id,
            'booked_by_user_id'     => get_current_user_id(),
            'mentor_timezone'       => $mentor_tz,
            'coach_timezone'        => $coach_tz_string,
        ));

        if (is_wp_error($session_id)) {
            return $session_id;
        }

        $meeting_url    = null;
        $zoom_meeting_id = null;
        $outlook_event_id = null;

        // Prepare common session data for APIs.
        $api_data = array(
            'mentor_name'    => $enrollment['mentor_name'],
            'mentor_email'   => $enrollment['mentor_email'],
            'coach_name'     => $coach_user->display_name,
            'start_datetime' => $session_start->format('Y-m-d\TH:i:s'),
            'end_datetime'   => $session_end->format('Y-m-d\TH:i:s'),
            'timezone'       => $coach_tz_string,
            'duration'       => $duration,
        );

        // Resolve coach Zoom settings ONCE, OUTSIDE the is_configured() guard.
        // Resolution is cheap; keeping it here means a future reviewer cannot accidentally
        // null it out by moving both lines inside the guard.
        $resolved_zoom_settings = HL_Coach_Zoom_Settings_Service::resolve_for_coach($coach_user_id);

        // Step 2: Create Zoom meeting.
        $zoom = HL_Zoom_Integration::instance();
        if ($zoom->is_configured()) {
            $zoom_email   = $zoom->get_coach_email($coach_user_id);
            $zoom_payload = $zoom->build_meeting_payload($api_data, $resolved_zoom_settings);
            $zoom_result  = $zoom->create_meeting($zoom_email, $zoom_payload);

            if (is_wp_error($zoom_result)) {
                HL_Scheduling_Email_Service::instance()->send_zoom_fallback(
                    array_merge($api_data, array(
                        'session_datetime' => $session_datetime,
                        'coach_email'      => $coach_user->user_email,
                        'coach_timezone'   => $coach_tz_string,
                        'mentor_timezone'  => $mentor_tz,
                    )),
                    $zoom_result->get_error_message()
                );
            } else {
                $zoom_meeting_id = isset($zoom_result['id']) ? (int) $zoom_result['id'] : null;
                $meeting_url     = $zoom_result['join_url'] ?? null;

                // Update session with Zoom data.
                $wpdb->update(
                    $wpdb->prefix . 'hl_coaching_session',
                    array(
                        'zoom_meeting_id' => $zoom_meeting_id,
                        'meeting_url'     => $meeting_url,
                    ),
                    array('session_id' => $session_id)
                );
            }
        }

        // Step 3: Create Outlook calendar event.
        $graph = HL_Microsoft_Graph::instance();
        if ($graph->is_configured()) {
            $coach_ms_email       = $graph->get_coach_email($coach_user_id);
            $api_data['meeting_url'] = $meeting_url;
            $event_payload        = $graph->build_event_payload($api_data);
            $event_result         = $graph->create_calendar_event($coach_ms_email, $event_payload);

            if (is_wp_error($event_result)) {
                HL_Scheduling_Email_Service::instance()->send_outlook_fallback(
                    array_merge($api_data, array(
                        'session_datetime' => $session_datetime,
                        'coach_email'      => $coach_user->user_email,
                        'meeting_url'      => $meeting_url,
                        'coach_timezone'   => $coach_tz_string,
                        'mentor_timezone'  => $mentor_tz,
                    )),
                    $event_result->get_error_message()
                );
            } else {
                $outlook_event_id = $event_result['id'] ?? null;
                if ($outlook_event_id) {
                    $wpdb->update(
                        $wpdb->prefix . 'hl_coaching_session',
                        array('outlook_event_id' => $outlook_event_id),
                        array('session_id' => $session_id)
                    );
                }
            }
        }

        // Step 4: Send notification emails.
        HL_Scheduling_Email_Service::instance()->send_session_booked(array(
            'mentor_name'      => $enrollment['mentor_name'],
            'mentor_email'     => $enrollment['mentor_email'],
            'mentor_timezone'  => $mentor_tz,
            'coach_name'       => $coach_user->display_name,
            'coach_email'      => $coach_user->user_email,
            'coach_timezone'   => $coach_tz_string,
            'session_datetime' => $session_datetime,
            'meeting_url'      => $meeting_url,
        ));

        return array(
            'session_id'  => $session_id,
            'meeting_url' => $meeting_url,
        );
    }

    // =========================================================================
    // Reschedule
    // =========================================================================

    /**
     * Reschedule a session with Zoom + Outlook re-creation.
     *
     * @param int    $session_id
     * @param string $new_date       Y-m-d in coach timezone.
     * @param string $new_start_time H:i in coach timezone.
     * @param string $timezone       Mentor's IANA timezone.
     * @return array|WP_Error New session data.
     */
    public function reschedule_session_with_integrations($session_id, $new_date, $new_start_time, $timezone) {
        global $wpdb;

        $coaching_service = new HL_Coaching_Service();
        $old_session      = $coaching_service->get_session($session_id);
        if (!$old_session) {
            return new WP_Error('not_found', __('Session not found.', 'hl-core'));
        }

        // Permission check.
        $perm = $this->check_booking_permission(
            $old_session['mentor_enrollment_id'],
            $old_session['coach_user_id']
        );
        if (is_wp_error($perm)) {
            return $perm;
        }

        // Check cancellation/reschedule notice.
        $settings          = HL_Admin_Scheduling_Settings::get_scheduling_settings();
        $min_cancel_hours  = (int) $settings['min_cancel_notice_hours'];
        $duration          = (int) $settings['session_duration'];

        if (!empty($old_session['session_datetime']) && $min_cancel_hours > 0) {
            $wp_tz        = wp_timezone();
            $session_time = new DateTime($old_session['session_datetime'], $wp_tz);
            $now          = new DateTime('now', $wp_tz);
            $diff_hours   = ($session_time->getTimestamp() - $now->getTimestamp()) / 3600;

            if ($diff_hours < $min_cancel_hours) {
                return new WP_Error('too_late', sprintf(
                    __('Rescheduling requires at least %d hours notice.', 'hl-core'),
                    $min_cancel_hours
                ));
            }
        }

        // Resolve coach timezone.
        $coach_tz_string = $old_session['coach_timezone'] ?? get_user_meta($old_session['coach_user_id'], 'hl_timezone', true);
        if (empty($coach_tz_string)) {
            $coach_tz_string = wp_timezone_string();
        }

        // Build new datetime.
        try {
            $coach_tz      = new DateTimeZone($coach_tz_string);
            $new_start     = new DateTime($new_date . ' ' . $new_start_time, $coach_tz);
            $new_end       = clone $new_start;
            $new_end->modify('+' . $duration . ' minutes');

            $wp_tz           = wp_timezone();
            $new_start_wp    = clone $new_start;
            $new_start_wp->setTimezone($wp_tz);
            $new_datetime_wp = $new_start_wp->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return new WP_Error('invalid_datetime', __('Invalid date or time.', 'hl-core'));
        }

        // Delete old Zoom meeting.
        if (!empty($old_session['zoom_meeting_id'])) {
            $zoom = HL_Zoom_Integration::instance();
            $zoom->delete_meeting($old_session['zoom_meeting_id']);
        }

        // Delete old Outlook event.
        if (!empty($old_session['outlook_event_id'])) {
            $graph         = HL_Microsoft_Graph::instance();
            $coach_ms_email = $graph->get_coach_email($old_session['coach_user_id']);
            $graph->delete_calendar_event($coach_ms_email, $old_session['outlook_event_id']);
        }

        // Reschedule via coaching service (marks old as rescheduled, creates new).
        $new_session_id = $coaching_service->reschedule_session($session_id, $new_datetime_wp);
        if (is_wp_error($new_session_id)) {
            return $new_session_id;
        }

        // Update new session with scheduling fields.
        $wpdb->update(
            $wpdb->prefix . 'hl_coaching_session',
            array(
                'component_id'      => $old_session['component_id'] ?? null,
                'booked_by_user_id' => get_current_user_id(),
                'mentor_timezone'   => $timezone,
                'coach_timezone'    => $coach_tz_string,
            ),
            array('session_id' => $new_session_id)
        );

        // Resolve names/emails.
        $coach_user = get_userdata($old_session['coach_user_id']);
        $mentor_email = $wpdb->get_var($wpdb->prepare(
            "SELECT u.user_email FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.enrollment_id = %d",
            $old_session['mentor_enrollment_id']
        ));

        $api_data = array(
            'mentor_name'    => $old_session['mentor_name'],
            'mentor_email'   => $mentor_email,
            'coach_name'     => $coach_user ? $coach_user->display_name : ($old_session['coach_name'] ?? ''),
            'start_datetime' => $new_start->format('Y-m-d\TH:i:s'),
            'end_datetime'   => $new_end->format('Y-m-d\TH:i:s'),
            'timezone'       => $coach_tz_string,
            'duration'       => $duration,
        );

        // Resolve coach Zoom settings ONCE, OUTSIDE the is_configured() guard.
        // Resolution is cheap; keeping it here means a future reviewer cannot accidentally
        // null it out by moving both lines inside the guard.
        $resolved_zoom_settings = HL_Coach_Zoom_Settings_Service::resolve_for_coach($old_session['coach_user_id']);

        // Create new Zoom meeting.
        $meeting_url     = null;
        $zoom_meeting_id = null;
        $zoom            = HL_Zoom_Integration::instance();
        if ($zoom->is_configured()) {
            $zoom_email   = $zoom->get_coach_email($old_session['coach_user_id']);
            $zoom_payload = $zoom->build_meeting_payload($api_data, $resolved_zoom_settings);
            $zoom_result  = $zoom->create_meeting($zoom_email, $zoom_payload);

            if (!is_wp_error($zoom_result)) {
                $zoom_meeting_id = isset($zoom_result['id']) ? (int) $zoom_result['id'] : null;
                $meeting_url     = $zoom_result['join_url'] ?? null;
                $wpdb->update(
                    $wpdb->prefix . 'hl_coaching_session',
                    array('zoom_meeting_id' => $zoom_meeting_id, 'meeting_url' => $meeting_url),
                    array('session_id' => $new_session_id)
                );
            }
        }

        // Create new Outlook event.
        $graph = HL_Microsoft_Graph::instance();
        if ($graph->is_configured()) {
            $coach_ms_email          = $graph->get_coach_email($old_session['coach_user_id']);
            $api_data['meeting_url'] = $meeting_url;
            $event_payload           = $graph->build_event_payload($api_data);
            $event_result            = $graph->create_calendar_event($coach_ms_email, $event_payload);

            if (!is_wp_error($event_result) && !empty($event_result['id'])) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_coaching_session',
                    array('outlook_event_id' => $event_result['id']),
                    array('session_id' => $new_session_id)
                );
            }
        }

        // Send reschedule emails.
        $email_data = array(
            'mentor_name'      => $old_session['mentor_name'],
            'mentor_email'     => $mentor_email,
            'mentor_timezone'  => $timezone,
            'coach_name'       => $coach_user ? $coach_user->display_name : '',
            'coach_email'      => $coach_user ? $coach_user->user_email : '',
            'coach_timezone'   => $coach_tz_string,
            'session_datetime' => $new_datetime_wp,
            'meeting_url'      => $meeting_url,
        );
        $old_email_data = array(
            'session_datetime' => $old_session['session_datetime'],
        );

        HL_Scheduling_Email_Service::instance()->send_session_rescheduled($old_email_data, $email_data);

        return array(
            'session_id'  => $new_session_id,
            'meeting_url' => $meeting_url,
        );
    }

    // =========================================================================
    // Cancellation
    // =========================================================================

    /**
     * Cancel a session with Zoom + Outlook cleanup.
     *
     * @param int $session_id
     * @return true|WP_Error
     */
    public function cancel_session_with_integrations($session_id) {
        global $wpdb;

        $coaching_service = new HL_Coaching_Service();
        $session          = $coaching_service->get_session($session_id);
        if (!$session) {
            return new WP_Error('not_found', __('Session not found.', 'hl-core'));
        }

        // Only coach and admin can cancel.
        $current_user_id = get_current_user_id();
        $is_admin        = current_user_can('manage_hl_core');
        $is_coach        = (int) $session['coach_user_id'] === $current_user_id;
        if (!$is_admin && !$is_coach) {
            return new WP_Error('permission_denied', __('Only coaches and administrators can cancel sessions.', 'hl-core'));
        }

        // Check cancellation notice window.
        $settings         = HL_Admin_Scheduling_Settings::get_scheduling_settings();
        $min_cancel_hours = (int) $settings['min_cancel_notice_hours'];

        if (!empty($session['session_datetime']) && $min_cancel_hours > 0) {
            $wp_tz        = wp_timezone();
            $session_time = new DateTime($session['session_datetime'], $wp_tz);
            $now          = new DateTime('now', $wp_tz);
            $diff_hours   = ($session_time->getTimestamp() - $now->getTimestamp()) / 3600;

            if ($diff_hours < $min_cancel_hours) {
                return new WP_Error('too_late', sprintf(
                    __('Cancellation requires at least %d hours notice.', 'hl-core'),
                    $min_cancel_hours
                ));
            }
        }

        // Delete Zoom meeting.
        if (!empty($session['zoom_meeting_id'])) {
            $zoom = HL_Zoom_Integration::instance();
            $zoom->delete_meeting($session['zoom_meeting_id']);
        }

        // Delete Outlook event.
        if (!empty($session['outlook_event_id'])) {
            $graph          = HL_Microsoft_Graph::instance();
            $coach_ms_email = $graph->get_coach_email($session['coach_user_id']);
            $graph->delete_calendar_event($coach_ms_email, $session['outlook_event_id']);
        }

        // Cancel in DB.
        $result = $coaching_service->cancel_session($session_id);
        if (is_wp_error($result)) {
            return $result;
        }

        // Send cancellation emails.
        $coach_user   = get_userdata($session['coach_user_id']);
        $mentor_email = $wpdb->get_var($wpdb->prepare(
            "SELECT u.user_email FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.enrollment_id = %d",
            $session['mentor_enrollment_id']
        ));

        $cancelled_by = get_userdata($current_user_id);

        HL_Scheduling_Email_Service::instance()->send_session_cancelled(
            array(
                'mentor_name'      => $session['mentor_name'],
                'mentor_email'     => $mentor_email,
                'mentor_timezone'  => $session['mentor_timezone'] ?? wp_timezone_string(),
                'coach_name'       => $coach_user ? $coach_user->display_name : '',
                'coach_email'      => $coach_user ? $coach_user->user_email : '',
                'coach_timezone'   => $session['coach_timezone'] ?? wp_timezone_string(),
                'session_datetime' => $session['session_datetime'],
            ),
            $cancelled_by ? $cancelled_by->display_name : __('Administrator', 'hl-core')
        );

        return true;
    }

    // =========================================================================
    // Permission Helpers
    // =========================================================================

    /**
     * Check if the current user can book/reschedule for this enrollment + coach.
     *
     * @param int $enrollment_id
     * @param int $coach_user_id
     * @return true|WP_Error
     */
    private function check_booking_permission($enrollment_id, $coach_user_id) {
        $current_user_id = get_current_user_id();

        // Admin can do anything.
        if (current_user_can('manage_hl_core')) {
            return true;
        }

        // Coach can book for assigned mentors.
        if ((int) $coach_user_id === $current_user_id) {
            return true;
        }

        // Mentor can book own sessions.
        global $wpdb;
        $enrollment_user = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $enrollment_id
        ));
        if ((int) $enrollment_user === $current_user_id) {
            return true;
        }

        return new WP_Error('permission_denied', __('You do not have permission to perform this action.', 'hl-core'));
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    /**
     * AJAX: Get available slots for a coach on a date.
     */
    public function ajax_get_available_slots() {
        check_ajax_referer('hl_scheduling_nonce', '_nonce');

        $coach_user_id = absint($_REQUEST['coach_user_id'] ?? 0);
        $date          = sanitize_text_field($_REQUEST['date'] ?? '');
        $timezone      = sanitize_text_field($_REQUEST['timezone'] ?? wp_timezone_string());

        if (!$coach_user_id || !$date) {
            wp_send_json_error(array('message' => __('Missing required parameters.', 'hl-core')));
        }

        // Validate date format.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(array('message' => __('Invalid date format.', 'hl-core')));
        }

        $result = $this->get_available_slots($coach_user_id, $date, $timezone);
        wp_send_json_success($result);
    }

    /**
     * AJAX: Book a coaching session.
     */
    public function ajax_book_session() {
        check_ajax_referer('hl_scheduling_nonce', '_nonce');

        $result = $this->book_session(array(
            'mentor_enrollment_id' => absint($_POST['mentor_enrollment_id'] ?? 0),
            'coach_user_id'        => absint($_POST['coach_user_id'] ?? 0),
            'component_id'         => absint($_POST['component_id'] ?? 0),
            'date'                 => sanitize_text_field($_POST['date'] ?? ''),
            'start_time'           => sanitize_text_field($_POST['start_time'] ?? ''),
            'timezone'             => sanitize_text_field($_POST['timezone'] ?? ''),
        ));

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Reschedule a coaching session.
     */
    public function ajax_reschedule_session() {
        check_ajax_referer('hl_scheduling_nonce', '_nonce');

        $session_id = absint($_POST['session_id'] ?? 0);
        $date       = sanitize_text_field($_POST['date'] ?? '');
        $start_time = sanitize_text_field($_POST['start_time'] ?? '');
        $timezone   = sanitize_text_field($_POST['timezone'] ?? '');

        if (!$session_id || !$date || !$start_time || !$timezone) {
            wp_send_json_error(array('message' => __('Missing required parameters.', 'hl-core')));
        }

        $result = $this->reschedule_session_with_integrations($session_id, $date, $start_time, $timezone);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Cancel a coaching session.
     */
    public function ajax_cancel_session() {
        check_ajax_referer('hl_scheduling_nonce', '_nonce');

        $session_id = absint($_POST['session_id'] ?? 0);
        if (!$session_id) {
            wp_send_json_error(array('message' => __('Missing session ID.', 'hl-core')));
        }

        $result = $this->cancel_session_with_integrations($session_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Session cancelled.', 'hl-core')));
    }

    /**
     * AJAX: Mark attendance for a coaching session (coach/admin only).
     */
    public function ajax_mark_attendance() {
        check_ajax_referer('hl_scheduling_nonce', '_nonce');

        $session_id = absint($_POST['session_id'] ?? 0);
        $status     = sanitize_text_field($_POST['attendance'] ?? '');

        if (!$session_id || !in_array($status, array('attended', 'missed'), true)) {
            wp_send_json_error(array('message' => __('Invalid parameters.', 'hl-core')));
        }

        $coaching_service = new HL_Coaching_Service();
        $session          = $coaching_service->get_session($session_id);
        if (!$session) {
            wp_send_json_error(array('message' => __('Session not found.', 'hl-core')));
        }

        // Only coach and admin can mark attendance.
        $current_user_id = get_current_user_id();
        $is_admin        = current_user_can('manage_hl_core');
        $is_coach        = (int) $session['coach_user_id'] === $current_user_id;
        if (!$is_admin && !$is_coach) {
            wp_send_json_error(array('message' => __('Only coaches and administrators can mark attendance.', 'hl-core')));
        }

        $result = $coaching_service->mark_attendance($session_id, $status);
        if ($result === false) {
            wp_send_json_error(array('message' => __('Failed to update attendance.', 'hl-core')));
        }

        $label = $status === 'attended' ? __('Attended', 'hl-core') : __('No-Show', 'hl-core');
        wp_send_json_success(array('message' => sprintf(__('Marked as: %s', 'hl-core'), $label)));
    }
}
