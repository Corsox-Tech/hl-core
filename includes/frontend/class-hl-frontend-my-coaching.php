<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_my_coaching] shortcode.
 *
 * Participant view of coaching: coach info card, upcoming sessions,
 * past sessions, and schedule new session form.
 *
 * @package HL_Core
 */
class HL_Frontend_My_Coaching {

    /**
     * Render the shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render($atts) {
        ob_start();

        $user_id = get_current_user_id();

        // Get active enrollments for this user.
        $enrollment_repo = new HL_Enrollment_Repository();
        $all_enrollments = $enrollment_repo->get_all(array('status' => 'active'));
        $enrollments = array_filter($all_enrollments, function ($e) use ($user_id) {
            return (int) $e->user_id === $user_id;
        });
        $enrollments = array_values($enrollments);

        if (empty($enrollments)) {
            echo '<div class="hl-dashboard hl-my-coaching">';
            echo '<h2>' . esc_html__('My Coaching', 'hl-core') . '</h2>';
            echo '<div class="hl-empty-state"><p>' . esc_html__('You are not currently enrolled in any programs.', 'hl-core') . '</p></div>';
            echo '</div>';
            return ob_get_clean();
        }

        // Allow filtering by enrollment via query param, default to first.
        $selected_enrollment_id = isset($_GET['enrollment']) ? absint($_GET['enrollment']) : 0;
        $enrollment = null;
        $cohort_id  = 0;

        if ($selected_enrollment_id) {
            foreach ($enrollments as $e) {
                if ((int) $e->enrollment_id === $selected_enrollment_id && (int) $e->user_id === $user_id) {
                    $enrollment = $e;
                    break;
                }
            }
        }

        if (!$enrollment) {
            $enrollment = $enrollments[0];
        }

        $cohort_id     = (int) $enrollment->cohort_id;
        $enrollment_id = (int) $enrollment->enrollment_id;

        // Resolve coach.
        $coach_service = new HL_Coach_Assignment_Service();
        $coach = $coach_service->get_coach_for_enrollment($enrollment_id, $cohort_id);

        // Get sessions.
        $coaching_service = new HL_Coaching_Service();
        $upcoming = $coaching_service->get_upcoming_sessions($enrollment_id, $cohort_id);
        $past     = $coaching_service->get_past_sessions($enrollment_id, $cohort_id);

        // Cancellation allowed?
        $can_cancel = $coaching_service->is_cancellation_allowed($cohort_id);

        ?>
        <div class="hl-dashboard hl-my-coaching">
            <h2><?php esc_html_e('My Coaching', 'hl-core'); ?></h2>

            <?php $this->render_messages(); ?>

            <?php if (count($enrollments) > 1) : ?>
                <?php $this->render_enrollment_switcher($enrollments, $enrollment_id); ?>
            <?php endif; ?>

            <?php $this->render_coach_card($coach); ?>

            <h3><?php esc_html_e('Upcoming Sessions', 'hl-core'); ?></h3>
            <?php $this->render_upcoming_sessions($upcoming, $can_cancel, $enrollment_id, $cohort_id); ?>

            <h3><?php esc_html_e('Past Sessions', 'hl-core'); ?></h3>
            <?php $this->render_past_sessions($past); ?>

            <h3><?php esc_html_e('Schedule New Session', 'hl-core'); ?></h3>
            <?php $this->render_schedule_form($enrollment_id, $cohort_id, $coach); ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Handle POST actions (schedule, reschedule, cancel).
     * Called from template_redirect so we can redirect after.
     */
    public static function handle_post_actions() {
        if (!is_user_logged_in()) return;

        // Schedule new session.
        if (isset($_POST['hl_schedule_session_nonce'])) {
            self::handle_schedule_session();
        }

        // Cancel session.
        if (isset($_POST['hl_cancel_session_nonce'])) {
            self::handle_cancel_session();
        }

        // Reschedule session.
        if (isset($_POST['hl_reschedule_session_nonce'])) {
            self::handle_reschedule_session();
        }
    }

    private static function handle_schedule_session() {
        if (!wp_verify_nonce($_POST['hl_schedule_session_nonce'], 'hl_schedule_session')) {
            return;
        }

        $enrollment_id = absint($_POST['enrollment_id']);
        $cohort_id     = absint($_POST['cohort_id']);
        $user_id       = get_current_user_id();

        // Verify ownership.
        $enrollment_repo = new HL_Enrollment_Repository();
        $enrollment = $enrollment_repo->get_by_id($enrollment_id);
        if (!$enrollment || (int) $enrollment->user_id !== $user_id) {
            return;
        }

        // Resolve coach.
        $coach_service = new HL_Coach_Assignment_Service();
        $coach = $coach_service->get_coach_for_enrollment($enrollment_id, $cohort_id);
        $coach_user_id = $coach ? absint($coach['coach_user_id']) : 0;

        $coaching_service = new HL_Coaching_Service();
        $result = $coaching_service->create_session(array(
            'cohort_id'            => $cohort_id,
            'mentor_enrollment_id' => $enrollment_id,
            'coach_user_id'        => $coach_user_id,
            'session_title'        => sanitize_text_field($_POST['session_title'] ?? ''),
            'meeting_url'          => esc_url_raw($_POST['meeting_url'] ?? ''),
            'session_datetime'     => sanitize_text_field($_POST['session_datetime'] ?? ''),
        ));

        $redirect_url = remove_query_arg(array('hl_msg'));
        $redirect_url = add_query_arg(
            'hl_msg',
            is_wp_error($result) ? 'schedule_error' : 'scheduled',
            $redirect_url
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    private static function handle_cancel_session() {
        if (!wp_verify_nonce($_POST['hl_cancel_session_nonce'], 'hl_cancel_session')) {
            return;
        }

        $session_id = absint($_POST['session_id']);
        $user_id    = get_current_user_id();

        // Verify the session belongs to this user.
        $coaching_service = new HL_Coaching_Service();
        $session = $coaching_service->get_session($session_id);
        if (!$session) return;

        $enrollment_repo = new HL_Enrollment_Repository();
        $enrollment = $enrollment_repo->get_by_id($session['mentor_enrollment_id']);
        if (!$enrollment || (int) $enrollment->user_id !== $user_id) {
            return;
        }

        // Check cancellation allowed.
        if (!$coaching_service->is_cancellation_allowed($session['cohort_id'])) {
            return;
        }

        $result = $coaching_service->cancel_session($session_id);

        $redirect_url = remove_query_arg(array('hl_msg'));
        $redirect_url = add_query_arg(
            'hl_msg',
            is_wp_error($result) ? 'cancel_error' : 'cancelled',
            $redirect_url
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    private static function handle_reschedule_session() {
        if (!wp_verify_nonce($_POST['hl_reschedule_session_nonce'], 'hl_reschedule_session')) {
            return;
        }

        $session_id   = absint($_POST['session_id']);
        $new_datetime = sanitize_text_field($_POST['new_datetime'] ?? '');
        $user_id      = get_current_user_id();

        if (empty($new_datetime)) return;

        // Verify ownership.
        $coaching_service = new HL_Coaching_Service();
        $session = $coaching_service->get_session($session_id);
        if (!$session) return;

        $enrollment_repo = new HL_Enrollment_Repository();
        $enrollment = $enrollment_repo->get_by_id($session['mentor_enrollment_id']);
        if (!$enrollment || (int) $enrollment->user_id !== $user_id) {
            return;
        }

        $result = $coaching_service->reschedule_session($session_id, $new_datetime);

        $redirect_url = remove_query_arg(array('hl_msg'));
        $redirect_url = add_query_arg(
            'hl_msg',
            is_wp_error($result) ? 'reschedule_error' : 'rescheduled',
            $redirect_url
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    // =========================================================================
    // Sub-renders
    // =========================================================================

    private function render_messages() {
        if (!isset($_GET['hl_msg'])) return;

        $msg = sanitize_text_field($_GET['hl_msg']);
        $messages = array(
            'scheduled'        => array('success', __('Session scheduled successfully.', 'hl-core')),
            'schedule_error'   => array('error',   __('Could not schedule session. Please try again.', 'hl-core')),
            'cancelled'        => array('success', __('Session cancelled.', 'hl-core')),
            'cancel_error'     => array('error',   __('Could not cancel session.', 'hl-core')),
            'rescheduled'      => array('success', __('Session rescheduled successfully.', 'hl-core')),
            'reschedule_error' => array('error',   __('Could not reschedule session.', 'hl-core')),
        );

        if (isset($messages[$msg])) {
            $type = $messages[$msg][0];
            $text = $messages[$msg][1];
            $class = $type === 'success' ? 'hl-notice-success' : 'hl-notice-error';
            echo '<div class="hl-notice ' . esc_attr($class) . '"><p>' . esc_html($text) . '</p></div>';
        }
    }

    private function render_enrollment_switcher($enrollments, $current_enrollment_id) {
        $cohort_repo = new HL_Cohort_Repository();
        $pathway_repo = new HL_Pathway_Repository();

        echo '<div class="hl-enrollment-switcher">';
        echo '<label>' . esc_html__('Program:', 'hl-core') . ' </label>';
        echo '<select class="hl-select" onchange="if(this.value){window.location.search=\'enrollment=\'+this.value;}">';
        foreach ($enrollments as $e) {
            $cohort = $cohort_repo->get_by_id($e->cohort_id);
            $pathway = !empty($e->assigned_pathway_id) ? $pathway_repo->get_by_id($e->assigned_pathway_id) : null;
            $label = ($pathway ? $pathway->pathway_name : __('Program', 'hl-core'))
                   . ' — ' . ($cohort ? $cohort->cohort_name : '');
            $selected = ((int) $e->enrollment_id === $current_enrollment_id) ? ' selected' : '';
            echo '<option value="' . esc_attr($e->enrollment_id) . '"' . $selected . '>'
                . esc_html($label)
                . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }

    private function render_coach_card($coach) {
        echo '<div class="hl-coach-info-card">';

        if ($coach) {
            $avatar = get_avatar($coach['coach_user_id'], 56, '', '', array('class' => 'hl-coach-avatar'));
            echo $avatar;
            echo '<div class="hl-coach-details">';
            echo '<p class="hl-coach-name">' . esc_html($coach['coach_name']) . '</p>';
            echo '<p class="hl-coach-email"><a href="mailto:' . esc_attr($coach['coach_email']) . '">'
                . esc_html($coach['coach_email']) . '</a></p>';
            echo '</div>';
        } else {
            echo '<div class="hl-coach-avatar-placeholder">?</div>';
            echo '<div class="hl-coach-details">';
            echo '<p class="hl-coach-name">' . esc_html__('No coach assigned', 'hl-core') . '</p>';
            echo '<p class="hl-coach-no-assignment">' . esc_html__('Contact your administrator for coach assignment.', 'hl-core') . '</p>';
            echo '</div>';
        }

        echo '</div>';
    }

    private function render_upcoming_sessions($sessions, $can_cancel, $enrollment_id, $cohort_id) {
        if (empty($sessions)) {
            echo '<p class="hl-session-no-items">' . esc_html__('No upcoming sessions.', 'hl-core') . '</p>';
            return;
        }

        echo '<div class="hl-sessions-list">';

        foreach ($sessions as $session) {
            $datetime_display = !empty($session['session_datetime'])
                ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($session['session_datetime']))
                : __('Date not set', 'hl-core');

            echo '<div class="hl-session-card">';

            // Title + badge row.
            echo '<div class="hl-session-header">';
            echo '<p class="hl-session-title">' . esc_html($session['session_title'] ?: __('Coaching Session', 'hl-core')) . '</p>';
            echo HL_Coaching_Service::render_status_badge($session['session_status'] ?? 'scheduled');
            echo '</div>';

            // Details.
            echo '<div class="hl-session-date">';
            echo '<span>' . esc_html($datetime_display) . '</span>';
            if (!empty($session['coach_name'])) {
                echo ' &middot; <span>' . esc_html($session['coach_name']) . '</span>';
            }
            echo '</div>';

            // Actions row.
            echo '<div class="hl-session-actions">';

            // Meeting link.
            if (!empty($session['meeting_url'])) {
                echo '<a href="' . esc_url($session['meeting_url']) . '" target="_blank" class="hl-btn hl-btn-sm hl-btn-primary">'
                    . esc_html__('Join Meeting', 'hl-core')
                    . '</a>';
            }

            // Reschedule form (inline toggle).
            echo '<button type="button" class="hl-btn hl-btn-sm hl-btn-secondary" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\'">'
                . esc_html__('Reschedule', 'hl-core')
                . '</button>';

            echo '<div class="hl-reschedule-form">';
            echo '<form method="post">';
            wp_nonce_field('hl_reschedule_session', 'hl_reschedule_session_nonce');
            echo '<input type="hidden" name="session_id" value="' . esc_attr($session['session_id']) . '" />';
            echo '<label class="hl-label">' . esc_html__('New date and time:', 'hl-core') . '</label>';
            echo '<input type="datetime-local" name="new_datetime" required class="hl-input" />';
            echo '<button type="submit" class="hl-btn hl-btn-sm hl-btn-primary">'
                . esc_html__('Confirm Reschedule', 'hl-core')
                . '</button>';
            echo '</form>';
            echo '</div>';

            // Cancel button.
            if ($can_cancel) {
                echo '<form method="post" onsubmit="return confirm(\'' . esc_js(__('Are you sure you want to cancel this session?', 'hl-core')) . '\')">';
                wp_nonce_field('hl_cancel_session', 'hl_cancel_session_nonce');
                echo '<input type="hidden" name="session_id" value="' . esc_attr($session['session_id']) . '" />';
                echo '<button type="submit" class="hl-btn hl-btn-sm hl-btn-danger">'
                    . esc_html__('Cancel', 'hl-core')
                    . '</button>';
                echo '</form>';
            }

            echo '</div>'; // actions row
            echo '</div>'; // session card
        }

        echo '</div>';
    }

    private function render_past_sessions($sessions) {
        if (empty($sessions)) {
            echo '<p class="hl-session-no-items">' . esc_html__('No past sessions.', 'hl-core') . '</p>';
            return;
        }

        echo '<div class="hl-sessions-list">';

        foreach ($sessions as $session) {
            $datetime_display = !empty($session['session_datetime'])
                ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($session['session_datetime']))
                : __('Date not set', 'hl-core');

            echo '<div class="hl-session-card hl-session-card--past">';

            // Title + badge row.
            echo '<div class="hl-session-header">';
            echo '<p class="hl-session-title">' . esc_html($session['session_title'] ?: __('Coaching Session', 'hl-core')) . '</p>';
            echo HL_Coaching_Service::render_status_badge($session['session_status'] ?? 'scheduled');
            echo '</div>';

            // Details.
            echo '<div class="hl-session-date">';
            echo '<span>' . esc_html($datetime_display) . '</span>';
            if (!empty($session['coach_name'])) {
                echo ' &middot; <span>' . esc_html($session['coach_name']) . '</span>';
            }
            echo '</div>';

            echo '</div>'; // session card
        }

        echo '</div>';
    }

    private function render_schedule_form($enrollment_id, $cohort_id, $coach) {
        // Auto-suggest session title from next coaching activity.
        $suggested_title = $this->get_suggested_session_title($enrollment_id, $cohort_id);

        echo '<div class="hl-schedule-form">';
        echo '<form method="post">';
        wp_nonce_field('hl_schedule_session', 'hl_schedule_session_nonce');
        echo '<input type="hidden" name="enrollment_id" value="' . esc_attr($enrollment_id) . '" />';
        echo '<input type="hidden" name="cohort_id" value="' . esc_attr($cohort_id) . '" />';

        // Session title.
        echo '<div class="hl-form-group">';
        echo '<label for="hl-session-title" class="hl-label">' . esc_html__('Session Title', 'hl-core') . '</label>';
        echo '<input type="text" id="hl-session-title" name="session_title" value="' . esc_attr($suggested_title) . '" class="hl-input" />';
        echo '</div>';

        // Date/Time.
        echo '<div class="hl-form-group">';
        echo '<label for="hl-session-datetime" class="hl-label">' . esc_html__('Date and Time', 'hl-core') . '</label>';
        echo '<input type="datetime-local" id="hl-session-datetime" name="session_datetime" required class="hl-input" />';
        echo '</div>';

        // Meeting URL.
        echo '<div class="hl-form-group">';
        echo '<label for="hl-meeting-url" class="hl-label">' . esc_html__('Meeting Link', 'hl-core') . ' <span class="hl-text-muted">(' . esc_html__('optional', 'hl-core') . ')</span></label>';
        echo '<input type="url" id="hl-meeting-url" name="meeting_url" placeholder="https://" class="hl-input" />';
        echo '</div>';

        echo '<button type="submit" class="hl-btn hl-btn-primary">'
            . esc_html__('Schedule Session', 'hl-core')
            . '</button>';

        echo '</form>';
        echo '</div>';
    }

    /**
     * Try to auto-suggest a session title from the next coaching activity.
     *
     * @param int $enrollment_id
     * @param int $cohort_id
     * @return string
     */
    private function get_suggested_session_title($enrollment_id, $cohort_id) {
        global $wpdb;

        // Find coaching activities in this cohort's pathways.
        $coaching_activities = $wpdb->get_results($wpdb->prepare(
            "SELECT a.activity_id, a.activity_name FROM {$wpdb->prefix}hl_activity a
             JOIN {$wpdb->prefix}hl_pathway p ON a.pathway_id = p.pathway_id
             WHERE p.cohort_id = %d
               AND a.activity_type = 'coaching_session_attendance'
               AND a.status = 'active'
             ORDER BY a.sort_order ASC",
            $cohort_id
        ), ARRAY_A);

        if (empty($coaching_activities)) {
            return __('Coaching Session', 'hl-core');
        }

        // Find the first one not yet completed.
        foreach ($coaching_activities as $activity) {
            $state = $wpdb->get_var($wpdb->prepare(
                "SELECT completion_status FROM {$wpdb->prefix}hl_activity_state
                 WHERE enrollment_id = %d AND activity_id = %d",
                $enrollment_id, $activity['activity_id']
            ));

            if ($state !== 'complete') {
                return $activity['activity_name'];
            }
        }

        // All complete, use generic title.
        return __('Coaching Session', 'hl-core');
    }

    /**
     * Find URL of a page containing a given shortcode.
     *
     * @param string $shortcode
     * @return string
     */
    public static function find_shortcode_page_url($shortcode) {
        static $cache = array();
        if (isset($cache[$shortcode])) return $cache[$shortcode];

        global $wpdb;
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like($shortcode) . '%'
        ));

        $cache[$shortcode] = $page_id ? get_permalink($page_id) : '';
        return $cache[$shortcode];
    }
}
