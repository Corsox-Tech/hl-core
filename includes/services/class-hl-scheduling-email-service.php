<?php
if (!defined('ABSPATH')) exit;

/**
 * Scheduling Email Notification Service
 *
 * Branded HTML emails for coaching session booking, rescheduling,
 * cancellation, and API failure fallback notifications.
 *
 * Subjects and body copy are pulled from HL_Admin_Email_Templates
 * (wp_options) with hardcoded defaults as fallback.
 *
 * @package HL_Core
 */
class HL_Scheduling_Email_Service {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // =========================================================================
    // Public Notification Methods
    // =========================================================================

    /**
     * Send "session booked" emails to mentor and coach.
     *
     * @param array $session_data {
     *     @type string $mentor_name
     *     @type string $mentor_email
     *     @type string $mentor_timezone
     *     @type string $coach_name
     *     @type string $coach_email
     *     @type string $coach_timezone
     *     @type string $session_datetime  WordPress local time (Y-m-d H:i:s).
     *     @type string $meeting_url       Zoom join URL (may be empty).
     * }
     */
    public function send_session_booked($session_data) {
        $mentor_time = $this->format_time_in_tz($session_data['session_datetime'], $session_data['mentor_timezone']);
        $coach_time  = $this->format_time_in_tz($session_data['session_datetime'], $session_data['coach_timezone']);
        $meeting_url = $session_data['meeting_url'] ?? '';

        $merge_base = array(
            'mentor_name'  => esc_html($session_data['mentor_name']),
            'coach_name'   => esc_html($session_data['coach_name']),
            'session_date' => $mentor_time,
        );

        // Email to mentor.
        $tpl_mentor = HL_Admin_Email_Templates::get_template('session_booked_mentor');
        $this->send(
            $session_data['mentor_email'],
            HL_Admin_Email_Templates::merge($tpl_mentor['subject'], $merge_base),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['mentor_name'])),
                HL_Admin_Email_Templates::merge($tpl_mentor['body'], $merge_base),
                $mentor_time,
                $meeting_url,
                true
            )
        );

        // Email to coach.
        $merge_coach = array_merge($merge_base, array('session_date' => $coach_time));
        $tpl_coach = HL_Admin_Email_Templates::get_template('session_booked_coach');
        $this->send(
            $session_data['coach_email'],
            HL_Admin_Email_Templates::merge($tpl_coach['subject'], $merge_coach),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['coach_name'])),
                HL_Admin_Email_Templates::merge($tpl_coach['body'], $merge_coach),
                $coach_time,
                $meeting_url,
                true
            )
        );
    }

    /**
     * Send "session rescheduled" emails to mentor and coach.
     *
     * @param array $old_session Previous session data.
     * @param array $new_session New session data.
     */
    public function send_session_rescheduled($old_session, $new_session) {
        $old_mentor_time = $this->format_time_in_tz($old_session['session_datetime'], $new_session['mentor_timezone']);
        $new_mentor_time = $this->format_time_in_tz($new_session['session_datetime'], $new_session['mentor_timezone']);
        $old_coach_time  = $this->format_time_in_tz($old_session['session_datetime'], $new_session['coach_timezone']);
        $new_coach_time  = $this->format_time_in_tz($new_session['session_datetime'], $new_session['coach_timezone']);
        $meeting_url     = $new_session['meeting_url'] ?? '';

        // Mentor email.
        $tpl_mentor = HL_Admin_Email_Templates::get_template('session_rescheduled_mentor');
        $merge_mentor = array(
            'mentor_name'      => esc_html($new_session['mentor_name']),
            'coach_name'       => esc_html($new_session['coach_name']),
            'old_session_date' => $old_mentor_time,
            'new_session_date' => $new_mentor_time,
        );
        $this->send(
            $new_session['mentor_email'],
            HL_Admin_Email_Templates::merge($tpl_mentor['subject'], $merge_mentor),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($new_session['mentor_name'])),
                HL_Admin_Email_Templates::merge($tpl_mentor['body'], $merge_mentor),
                $new_mentor_time,
                $meeting_url,
                true
            )
        );

        // Coach email.
        $tpl_coach = HL_Admin_Email_Templates::get_template('session_rescheduled_coach');
        $merge_coach = array(
            'mentor_name'      => esc_html($new_session['mentor_name']),
            'coach_name'       => esc_html($new_session['coach_name']),
            'old_session_date' => $old_coach_time,
            'new_session_date' => $new_coach_time,
        );
        $this->send(
            $new_session['coach_email'],
            HL_Admin_Email_Templates::merge($tpl_coach['subject'], $merge_coach),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($new_session['coach_name'])),
                HL_Admin_Email_Templates::merge($tpl_coach['body'], $merge_coach),
                $new_coach_time,
                $meeting_url,
                true
            )
        );
    }

    /**
     * Send "session cancelled" emails to mentor and coach.
     *
     * @param array  $session_data    Session data.
     * @param string $cancelled_by_name Name of person who cancelled.
     */
    public function send_session_cancelled($session_data, $cancelled_by_name) {
        $mentor_time = $this->format_time_in_tz($session_data['session_datetime'], $session_data['mentor_timezone']);
        $coach_time  = $this->format_time_in_tz($session_data['session_datetime'], $session_data['coach_timezone']);

        // Mentor email.
        $tpl_mentor = HL_Admin_Email_Templates::get_template('session_cancelled_mentor');
        $merge_mentor = array(
            'mentor_name'  => esc_html($session_data['mentor_name']),
            'coach_name'   => esc_html($session_data['coach_name']),
            'session_date' => $mentor_time,
            'cancelled_by' => esc_html($cancelled_by_name),
        );
        $this->send(
            $session_data['mentor_email'],
            HL_Admin_Email_Templates::merge($tpl_mentor['subject'], $merge_mentor),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['mentor_name'])),
                HL_Admin_Email_Templates::merge($tpl_mentor['body'], $merge_mentor),
                '',
                ''
            )
        );

        // Coach email.
        $tpl_coach = HL_Admin_Email_Templates::get_template('session_cancelled_coach');
        $merge_coach = array(
            'mentor_name'  => esc_html($session_data['mentor_name']),
            'coach_name'   => esc_html($session_data['coach_name']),
            'session_date' => $coach_time,
            'cancelled_by' => esc_html($cancelled_by_name),
        );
        $this->send(
            $session_data['coach_email'],
            HL_Admin_Email_Templates::merge($tpl_coach['subject'], $merge_coach),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['coach_name'])),
                HL_Admin_Email_Templates::merge($tpl_coach['body'], $merge_coach),
                '',
                ''
            )
        );
    }

    /**
     * Send fallback email when Outlook calendar event creation fails.
     * (Fallback emails stay hardcoded — internal/technical, not client-facing.)
     *
     * @param array  $session_data  Session data.
     * @param string $error_message API error message.
     */
    public function send_outlook_fallback($session_data, $error_message) {
        $coach_time = $this->format_time_in_tz($session_data['session_datetime'], $session_data['coach_timezone']);

        // To coach.
        $this->send(
            $session_data['coach_email'],
            __('Action Required: Coaching Session Not Added to Calendar', 'hl-core'),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['coach_name'])),
                sprintf(
                    __('<strong>%s</strong> (%s) scheduled a coaching session with you at <strong>%s</strong>, but it could not be added to your Outlook calendar automatically. Please add it manually.', 'hl-core'),
                    esc_html($session_data['mentor_name']),
                    esc_html($session_data['mentor_email']),
                    $coach_time
                ),
                $coach_time,
                $session_data['meeting_url'] ?? ''
            )
        );

        // To admin.
        $this->send(
            $this->get_admin_email(),
            __('Alert: Outlook Calendar Event Creation Failed', 'hl-core'),
            $this->build_body(
                __('Admin Notice', 'hl-core'),
                sprintf(
                    __('Outlook calendar event creation failed for a coaching session.<br><br><strong>Coach:</strong> %s<br><strong>Mentor:</strong> %s<br><strong>Date/Time:</strong> %s<br><strong>Error:</strong> %s', 'hl-core'),
                    esc_html($session_data['coach_name']),
                    esc_html($session_data['mentor_name']),
                    $coach_time,
                    esc_html($error_message)
                ),
                '',
                ''
            )
        );
    }

    /**
     * Send fallback email when Zoom meeting creation fails.
     * (Fallback emails stay hardcoded — internal/technical, not client-facing.)
     *
     * @param array  $session_data  Session data.
     * @param string $error_message API error message.
     */
    public function send_zoom_fallback($session_data, $error_message) {
        $coach_time = $this->format_time_in_tz($session_data['session_datetime'], $session_data['coach_timezone']);

        // To coach.
        $this->send(
            $session_data['coach_email'],
            __('Action Required: Zoom Meeting Not Created', 'hl-core'),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['coach_name'])),
                sprintf(
                    __('<strong>%s</strong> scheduled a coaching session with you at <strong>%s</strong>, but the Zoom meeting could not be created automatically. Please create one manually and share the link with the mentor.', 'hl-core'),
                    esc_html($session_data['mentor_name']),
                    $coach_time
                ),
                '',
                ''
            )
        );

        // To admin.
        $this->send(
            $this->get_admin_email(),
            __('Alert: Zoom Meeting Creation Failed', 'hl-core'),
            $this->build_body(
                __('Admin Notice', 'hl-core'),
                sprintf(
                    __('Zoom meeting creation failed for a coaching session.<br><br><strong>Coach:</strong> %s<br><strong>Mentor:</strong> %s<br><strong>Date/Time:</strong> %s<br><strong>Error:</strong> %s', 'hl-core'),
                    esc_html($session_data['coach_name']),
                    esc_html($session_data['mentor_name']),
                    $coach_time,
                    esc_html($error_message)
                ),
                '',
                ''
            )
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Get admin email for fallback notifications.
     *
     * @return string
     */
    public function get_admin_email() {
        return get_option('admin_email');
    }

    /**
     * Format a WP local datetime string in a specific timezone for display.
     *
     * @param string $wp_datetime WordPress local time (Y-m-d H:i:s).
     * @param string $timezone    IANA timezone.
     * @return string Formatted display string.
     */
    private function format_time_in_tz($wp_datetime, $timezone) {
        if (empty($wp_datetime)) {
            return '';
        }

        try {
            $wp_tz = new DateTimeZone(wp_timezone_string());
            $dt    = new DateTime($wp_datetime, $wp_tz);

            if (!empty($timezone)) {
                $dt->setTimezone(new DateTimeZone($timezone));
            }

            return $dt->format('l, F j, Y \a\t g:i A T');
        } catch (Exception $e) {
            return $wp_datetime;
        }
    }

    /**
     * Send an HTML email via wp_mail.
     *
     * @param string $to      Recipient email.
     * @param string $subject Email subject.
     * @param string $body    Full HTML body.
     */
    private function send($to, $subject, $body) {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $result  = wp_mail($to, $subject, $body, $headers);

        if (!$result && class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log('email_send_failed', array(
                'entity_type' => 'scheduling_email',
                'reason'      => 'wp_mail failed for: ' . $to . ' — Subject: ' . $subject,
            ));
        }
    }

    /**
     * Build a branded HTML email body.
     *
     * @param string $greeting    e.g. "Hello Jane,"
     * @param string $message     Main message HTML.
     * @param string $time_display Formatted date/time string (for details block).
     * @param string $meeting_url  Zoom link (optional).
     * @param bool   $show_missing_url_notice When true, emit a "link coming shortly"
     *                                        fallback block if $meeting_url is empty.
     *                                        Default false so cancellation / admin
     *                                        callers don't leak client-facing copy.
     * @return string Full HTML.
     */
    private function build_body($greeting, $message, $time_display, $meeting_url, $show_missing_url_notice = false) {
        return $this->build_branded_body($greeting, $message, $time_display, $meeting_url, $show_missing_url_notice);
    }

    /**
     * Try to render via the new block-based renderer if a template exists
     * in hl_email_template with blocks_json. Falls back to legacy rendering.
     *
     * @param string $template_key Template key (e.g., 'session_booked_mentor').
     * @param string $subject      Email subject.
     * @param array  $merge_data   Merge tag key => value map.
     * @return array|null { subject, body_html } or null if no block template.
     */
    public function try_block_render( $template_key, $subject, array $merge_data ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_email_template WHERE template_key = %s AND status = 'active'",
            $template_key
        ) );
        if ( ! $row || empty( $row->blocks_json ) ) {
            return null;
        }
        $blocks = json_decode( $row->blocks_json, true );
        if ( ! is_array( $blocks ) || empty( $blocks ) ) {
            return null;
        }
        // Only use block renderer if there's more than a single legacy text block.
        // Legacy migration creates exactly 1 text block — skip to use the legacy renderer
        // since it has the full branded shell with session details block.
        if ( count( $blocks ) === 1 && $blocks[0]['type'] === 'text' ) {
            return null;
        }
        $renderer = HL_Email_Block_Renderer::instance();
        $body     = $renderer->render( $blocks, $subject, $merge_data );
        return array( 'subject' => $subject, 'body_html' => $body );
    }

    /**
     * Build a branded HTML email body (public for test emails).
     *
     * @param string $greeting    e.g. "Hello Jane,"
     * @param string $message     Main message HTML.
     * @param string $time_display Formatted date/time string (for details block).
     * @param string $meeting_url  Zoom link (optional).
     * @param bool   $show_missing_url_notice When true, emit a "link coming shortly"
     *                                        fallback block if $meeting_url is empty.
     *                                        Default false so cancellation / admin
     *                                        callers don't leak client-facing copy.
     * @return string Full HTML.
     */
    public function build_branded_body($greeting, $message, $time_display, $meeting_url, $show_missing_url_notice = false) {
        $logo_url = 'https://academy.housmanlearning.com/wp-content/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg';

        $html  = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">';

        // Header.
        $html .= '<tr><td style="background:#1A2B47;padding:32px 40px;text-align:center;border-radius:12px 12px 0 0;">';
        $html .= '<img src="' . esc_url($logo_url) . '" alt="Housman Learning" width="200" style="display:inline-block;max-width:200px;width:200px;height:auto;">';
        $html .= '</td></tr>';

        // Body.
        $html .= '<tr><td style="background:#FFFFFF;padding:40px;">';
        $html .= '<p style="margin:0 0 24px;font-size:18px;font-weight:600;color:#1A2B47;">' . $greeting . '</p>';
        $html .= '<div style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">' . $message . '</div>';

        // Session details block.
        if (!empty($time_display)) {
            $html .= '<div style="background:#DBEAFE;border-radius:8px;padding:20px 24px;margin:24px 0;border-left:4px solid #2C7BE5;">';
            $html .= '<p style="margin:0;font-size:14px;font-weight:600;color:#1A2B47;">' . esc_html($time_display) . '</p>';
            $html .= '</div>';
        }

        // Zoom button (or fallback when meeting_url is empty, opt-in by caller).
        if (!empty($meeting_url)) {
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:24px 0;"><tr><td align="center">';
            $html .= '<a href="' . esc_url($meeting_url) . '" style="display:inline-block;background:#2d8cff;color:#FFFFFF;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;">Join Zoom Meeting</a>';
            $html .= '</td></tr></table>';
        } elseif ($show_missing_url_notice) {
            // Zoom create failed (or skipped). Generic copy because the reschedule
            // path does NOT call send_zoom_fallback() today (pre-existing gap).
            $html .= '<p style="margin:24px 0;padding:12px 16px;background:#fff7ed;border-left:4px solid #f97316;border-radius:4px;font-size:14px;color:#9a3412;">'
                . esc_html__( 'Your Zoom meeting link will be sent shortly. We\'ll be in touch.', 'hl-core' )
                . '</p>';
        }

        $html .= '</td></tr>';

        // Footer.
        $html .= '<tr><td style="background:#F4F5F7;padding:24px 40px;text-align:center;border-top:1px solid #E5E7EB;border-radius:0 0 12px 12px;">';
        $html .= '<p style="margin:0 0 8px;font-size:13px;color:#6B7280;">Housman Learning Academy</p>';
        $html .= '<p style="margin:0;font-size:12px;color:#9CA3AF;">' . esc_html__('This is an automated notification. Please do not reply to this email.', 'hl-core') . '</p>';
        $html .= '</td></tr></table>';

        return $html;
    }
}
