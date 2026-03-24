<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_my_coaching] shortcode.
 *
 * Mentor view of coaching: coach info card, upcoming sessions,
 * past sessions, and schedule new session via modal with session
 * number dropdown (linked to pathway coaching_session_attendance components).
 *
 * @package HL_Core
 */
class HL_Frontend_My_Coaching {

    /**
     * Render the shortcode.
     */
    public function render($atts) {
        ob_start();

        $user_id = get_current_user_id();

        $enrollment_repo = new HL_Enrollment_Repository();
        $all_enrollments = $enrollment_repo->get_all(array('status' => 'active'));
        $enrollments = array_values(array_filter($all_enrollments, function ($e) use ($user_id) {
            return (int) $e->user_id === $user_id;
        }));

        if (empty($enrollments)) {
            echo '<div class="hlmc-wrap"><div class="hlmc-empty">You are not enrolled in any programs.</div></div>';
            return ob_get_clean();
        }

        $selected_enrollment_id = isset($_GET['enrollment']) ? absint($_GET['enrollment']) : 0;
        $enrollment = null;
        if ($selected_enrollment_id) {
            foreach ($enrollments as $e) {
                if ((int) $e->enrollment_id === $selected_enrollment_id && (int) $e->user_id === $user_id) {
                    $enrollment = $e;
                    break;
                }
            }
        }
        if (!$enrollment) $enrollment = $enrollments[0];

        $cycle_id      = (int) $enrollment->cycle_id;
        $enrollment_id = (int) $enrollment->enrollment_id;

        $coach_service    = new HL_Coach_Assignment_Service();
        $coaching_service = new HL_Coaching_Service();
        $coach    = $coach_service->get_coach_for_enrollment($enrollment_id, $cycle_id);
        $upcoming = $coaching_service->get_upcoming_sessions($enrollment_id, $cycle_id);
        $past     = $coaching_service->get_past_sessions($enrollment_id, $cycle_id);

        // Get available session numbers from pathway.
        $available_sessions = $this->get_available_session_numbers($enrollment, $cycle_id, $coaching_service);

        self::render_styles();
        ?>
        <div class="hlmc-wrap">
            <?php $this->render_messages(); ?>

            <?php if (count($enrollments) > 1) : ?>
                <?php $this->render_enrollment_switcher($enrollments, $enrollment_id); ?>
            <?php endif; ?>

            <!-- Coach Card -->
            <?php $this->render_coach_card($coach); ?>

            <!-- Schedule Button -->
            <?php if (!empty($available_sessions) && $coach) : ?>
                <div class="hlmc-schedule-bar">
                    <button type="button" class="hlmc-btn-schedule" onclick="document.getElementById('hlmc-modal').style.display='flex'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <?php esc_html_e('Schedule Session', 'hl-core'); ?>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Upcoming Sessions -->
            <div class="hlmc-section">
                <h3 class="hlmc-section-title"><?php esc_html_e('Upcoming Sessions', 'hl-core'); ?></h3>
                <?php if (empty($upcoming)) : ?>
                    <div class="hlmc-empty-section"><?php esc_html_e('No upcoming sessions scheduled.', 'hl-core'); ?></div>
                <?php else : ?>
                    <div class="hlmc-sessions">
                        <?php foreach ($upcoming as $s) : ?>
                            <?php $this->render_session_card($s, 'upcoming', $coaching_service); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Past Sessions -->
            <div class="hlmc-section">
                <h3 class="hlmc-section-title"><?php esc_html_e('Past Sessions', 'hl-core'); ?></h3>
                <?php if (empty($past)) : ?>
                    <div class="hlmc-empty-section"><?php esc_html_e('No past sessions.', 'hl-core'); ?></div>
                <?php else : ?>
                    <div class="hlmc-sessions">
                        <?php foreach ($past as $s) : ?>
                            <?php $this->render_session_card($s, 'past', $coaching_service); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Schedule Modal -->
            <?php if (!empty($available_sessions) && $coach) : ?>
                <?php $this->render_schedule_modal($enrollment_id, $cycle_id, $coach, $available_sessions); ?>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Get coaching session numbers from the pathway that haven't been scheduled yet.
     */
    private function get_available_session_numbers($enrollment, $cycle_id, $coaching_service) {
        global $wpdb;
        $t = $wpdb->prefix;

        $pathway_id = ! empty($enrollment->assigned_pathway_id) ? (int) $enrollment->assigned_pathway_id : 0;
        if (!$pathway_id) return array();

        // Get coaching_session_attendance components from the pathway.
        $components = $wpdb->get_results($wpdb->prepare(
            "SELECT component_id, title, external_ref FROM {$t}hl_component
             WHERE pathway_id = %d AND component_type = 'coaching_session_attendance' AND status = 'active'
             ORDER BY ordering_hint ASC",
            $pathway_id
        ), ARRAY_A);

        if (empty($components)) return array();

        // Get already scheduled/attended session numbers.
        $enrollment_id = (int) $enrollment->enrollment_id;
        $used_numbers = $wpdb->get_col($wpdb->prepare(
            "SELECT session_number FROM {$t}hl_coaching_session
             WHERE mentor_enrollment_id = %d AND cycle_id = %d
             AND session_number IS NOT NULL
             AND session_status IN ('scheduled', 'attended')",
            $enrollment_id, $cycle_id
        ));
        $used = array_map('intval', $used_numbers);

        $available = array();
        foreach ($components as $c) {
            $ext = json_decode($c['external_ref'], true);
            $num = isset($ext['session_number']) ? (int) $ext['session_number'] : 0;
            if ($num > 0 && !in_array($num, $used, true)) {
                $available[] = array(
                    'number' => $num,
                    'title'  => $c['title'],
                );
            }
        }

        return $available;
    }

    // =========================================================================
    // POST handlers (unchanged logic, added session_number)
    // =========================================================================

    public static function handle_post_actions() {
        if (!is_user_logged_in()) return;
        if (isset($_POST['hl_schedule_session_nonce'])) self::handle_schedule_session();
        if (isset($_POST['hl_cancel_session_nonce']))   self::handle_cancel_session();
        if (isset($_POST['hl_reschedule_session_nonce'])) self::handle_reschedule_session();
    }

    private static function handle_schedule_session() {
        if (!wp_verify_nonce($_POST['hl_schedule_session_nonce'], 'hl_schedule_session')) return;

        $enrollment_id = absint($_POST['enrollment_id']);
        $cycle_id      = absint($_POST['cycle_id']);
        $session_number = absint($_POST['session_number'] ?? 0);
        $user_id       = get_current_user_id();

        $enrollment_repo = new HL_Enrollment_Repository();
        $enrollment = $enrollment_repo->get_by_id($enrollment_id);
        if (!$enrollment || (int) $enrollment->user_id !== $user_id) return;

        // Check session_number not already used.
        if ($session_number > 0) {
            global $wpdb;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}hl_coaching_session
                 WHERE mentor_enrollment_id = %d AND cycle_id = %d AND session_number = %d
                 AND session_status IN ('scheduled', 'attended')",
                $enrollment_id, $cycle_id, $session_number
            ));
            if ($exists > 0) {
                wp_safe_redirect(add_query_arg('hl_msg', 'already_scheduled'));
                exit;
            }
        }

        $coach_service = new HL_Coach_Assignment_Service();
        $coach = $coach_service->get_coach_for_enrollment($enrollment_id, $cycle_id);
        $coach_user_id = $coach ? absint($coach['coach_user_id']) : 0;

        $coaching_service = new HL_Coaching_Service();
        $result = $coaching_service->create_session(array(
            'cycle_id'             => $cycle_id,
            'mentor_enrollment_id' => $enrollment_id,
            'coach_user_id'        => $coach_user_id,
            'session_number'       => $session_number ?: null,
            'session_title'        => $session_number ? sprintf(__('Coaching Session #%d', 'hl-core'), $session_number) : sanitize_text_field($_POST['session_title'] ?? ''),
            'session_datetime'     => sanitize_text_field($_POST['session_datetime'] ?? ''),
        ));

        wp_safe_redirect(add_query_arg('hl_msg', is_wp_error($result) ? 'schedule_error' : 'scheduled'));
        exit;
    }

    private static function handle_cancel_session() {
        if (!wp_verify_nonce($_POST['hl_cancel_session_nonce'], 'hl_cancel_session')) return;
        $session_id = absint($_POST['session_id']);
        $user_id = get_current_user_id();

        $coaching_service = new HL_Coaching_Service();
        $session = $coaching_service->get_session($session_id);
        if (!$session) return;

        $enrollment_repo = new HL_Enrollment_Repository();
        $enrollment = $enrollment_repo->get_by_id($session['mentor_enrollment_id']);
        if (!$enrollment || (int) $enrollment->user_id !== $user_id) return;

        $result = $coaching_service->cancel_session($session_id);
        wp_safe_redirect(add_query_arg('hl_msg', is_wp_error($result) ? 'cancel_error' : 'cancelled'));
        exit;
    }

    private static function handle_reschedule_session() {
        if (!wp_verify_nonce($_POST['hl_reschedule_session_nonce'], 'hl_reschedule_session')) return;
        $session_id   = absint($_POST['session_id']);
        $new_datetime = sanitize_text_field($_POST['new_datetime'] ?? '');
        $user_id      = get_current_user_id();
        if (empty($new_datetime)) return;

        $coaching_service = new HL_Coaching_Service();
        $session = $coaching_service->get_session($session_id);
        if (!$session) return;

        $enrollment_repo = new HL_Enrollment_Repository();
        $enrollment = $enrollment_repo->get_by_id($session['mentor_enrollment_id']);
        if (!$enrollment || (int) $enrollment->user_id !== $user_id) return;

        $result = $coaching_service->reschedule_session($session_id, $new_datetime);
        wp_safe_redirect(add_query_arg('hl_msg', is_wp_error($result) ? 'reschedule_error' : 'rescheduled'));
        exit;
    }

    // =========================================================================
    // Sub-renders
    // =========================================================================

    private function render_messages() {
        if (!isset($_GET['hl_msg'])) return;
        $msg = sanitize_text_field($_GET['hl_msg']);
        $messages = array(
            'scheduled'         => array('success', __('Session scheduled successfully!', 'hl-core')),
            'schedule_error'    => array('error',   __('Could not schedule session.', 'hl-core')),
            'already_scheduled' => array('error',   __('That session is already scheduled or completed.', 'hl-core')),
            'cancelled'         => array('success', __('Session cancelled.', 'hl-core')),
            'cancel_error'      => array('error',   __('Could not cancel session.', 'hl-core')),
            'rescheduled'       => array('success', __('Session rescheduled.', 'hl-core')),
            'reschedule_error'  => array('error',   __('Could not reschedule session.', 'hl-core')),
        );
        if (!isset($messages[$msg])) return;
        $type = $messages[$msg][0];
        $text = $messages[$msg][1];
        $bg = $type === 'success' ? '#d1fae5;color:#065f46;border-color:#a7f3d0' : '#fee2e2;color:#991b1b;border-color:#fecaca';
        echo '<div class="hlmc-alert" style="background:' . $bg . '">';
        echo '<strong>' . esc_html($text) . '</strong>';
        echo '</div>';
    }

    private function render_enrollment_switcher($enrollments, $current_id) {
        $cycle_repo = new HL_Cycle_Repository();
        $pathway_repo = new HL_Pathway_Repository();
        echo '<div class="hlmc-switcher">';
        echo '<select onchange="if(this.value)window.location.search=\'enrollment=\'+this.value">';
        foreach ($enrollments as $e) {
            $cycle = $cycle_repo->get_by_id($e->cycle_id);
            $pw = !empty($e->assigned_pathway_id) ? $pathway_repo->get_by_id($e->assigned_pathway_id) : null;
            $label = ($pw ? $pw->pathway_name : 'Program') . ' — ' . ($cycle ? $cycle->cycle_name : '');
            $sel = ((int) $e->enrollment_id === $current_id) ? ' selected' : '';
            echo '<option value="' . esc_attr($e->enrollment_id) . '"' . $sel . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';
    }

    private function render_coach_card($coach) {
        ?>
        <div class="hlmc-coach-card">
            <?php if ($coach) :
                $avatar = get_avatar($coach['coach_user_id'], 48, '', '', array('class' => 'hlmc-coach-avatar'));
            ?>
                <?php echo $avatar; ?>
                <div>
                    <div class="hlmc-coach-label"><?php esc_html_e('Your Coach', 'hl-core'); ?></div>
                    <div class="hlmc-coach-name"><?php echo esc_html($coach['coach_name']); ?></div>
                    <a href="mailto:<?php echo esc_attr($coach['coach_email']); ?>" class="hlmc-coach-email"><?php echo esc_html($coach['coach_email']); ?></a>
                </div>
            <?php else : ?>
                <div class="hlmc-coach-placeholder">?</div>
                <div>
                    <div class="hlmc-coach-label"><?php esc_html_e('Coach', 'hl-core'); ?></div>
                    <div class="hlmc-coach-name"><?php esc_html_e('Not yet assigned', 'hl-core'); ?></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_session_card($s, $type, $coaching_service) {
        $dt = !empty($s['session_datetime'])
            ? date_i18n('M j, Y \a\t g:i A', strtotime($s['session_datetime']))
            : __('Date not set', 'hl-core');
        $status = $s['session_status'] ?? 'scheduled';
        $title  = $s['session_title'] ?: __('Coaching Session', 'hl-core');
        $num    = isset($s['session_number']) && $s['session_number'] ? '#' . $s['session_number'] : '';

        $badge_map = array(
            'scheduled'   => 'hlmc-badge-blue',
            'attended'    => 'hlmc-badge-green',
            'missed'      => 'hlmc-badge-red',
            'cancelled'   => 'hlmc-badge-gray',
            'rescheduled' => 'hlmc-badge-yellow',
        );
        $badge_class = isset($badge_map[$status]) ? $badge_map[$status] : 'hlmc-badge-gray';
        ?>
        <div class="hlmc-session-card <?php echo $type === 'past' ? 'hlmc-session-past' : ''; ?>">
            <div class="hlmc-session-top">
                <div>
                    <div class="hlmc-session-title"><?php echo esc_html($title); ?> <?php if ($num) echo '<span class="hlmc-session-num">' . esc_html($num) . '</span>'; ?></div>
                    <div class="hlmc-session-date"><?php echo esc_html($dt); ?><?php if (!empty($s['coach_name'])) echo ' &middot; ' . esc_html($s['coach_name']); ?></div>
                </div>
                <span class="hlmc-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
            </div>
            <?php if ($type === 'upcoming' && $status === 'scheduled') : ?>
                <div class="hlmc-session-actions">
                    <?php if (!empty($s['meeting_url'])) : ?>
                        <a href="<?php echo esc_url($s['meeting_url']); ?>" target="_blank" class="hlmc-btn hlmc-btn-primary"><?php esc_html_e('Join', 'hl-core'); ?></a>
                    <?php endif; ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Cancel this session?')">
                        <?php wp_nonce_field('hl_cancel_session', 'hl_cancel_session_nonce'); ?>
                        <input type="hidden" name="session_id" value="<?php echo esc_attr($s['session_id']); ?>" />
                        <button type="submit" class="hlmc-btn hlmc-btn-outline"><?php esc_html_e('Cancel', 'hl-core'); ?></button>
                    </form>
                </div>
            <?php endif; ?>
            <?php
            // Inline action plan for attended past sessions.
            if ($type === 'past' && $status === 'attended') {
                $sid = isset($s['session_id']) ? (int) $s['session_id'] : 0;
                if ($sid) {
                    $subs = $coaching_service->get_submissions($sid);
                    foreach ($subs as $sub) {
                        if ($sub['role_in_session'] === 'supervisee' && !empty($sub['responses_json'])) {
                            $this->render_inline_action_plan($sub);
                        }
                    }
                }
            }
            ?>
        </div>
        <?php
    }

    private function render_inline_action_plan($submission) {
        $r = json_decode($submission['responses_json'], true);
        if (empty($r)) return;
        echo '<div class="hlmc-action-plan">';
        echo '<div class="hlmc-ap-title">Action Plan</div>';
        foreach ($r as $k => $v) {
            if (is_array($v)) continue;
            echo '<div class="hlmc-ap-row"><span class="hlmc-ap-label">' . esc_html(ucwords(str_replace('_', ' ', $k))) . ':</span> ' . esc_html($v) . '</div>';
        }
        echo '</div>';
    }

    private function render_schedule_modal($enrollment_id, $cycle_id, $coach, $available_sessions) {
        ?>
        <div id="hlmc-modal" class="hlmc-modal-overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
            <div class="hlmc-modal">
                <div class="hlmc-modal-header">
                    <h3><?php esc_html_e('Schedule Coaching Session', 'hl-core'); ?></h3>
                    <button type="button" class="hlmc-modal-close" onclick="document.getElementById('hlmc-modal').style.display='none'">&times;</button>
                </div>
                <form method="post">
                    <?php wp_nonce_field('hl_schedule_session', 'hl_schedule_session_nonce'); ?>
                    <input type="hidden" name="enrollment_id" value="<?php echo esc_attr($enrollment_id); ?>" />
                    <input type="hidden" name="cycle_id" value="<?php echo esc_attr($cycle_id); ?>" />

                    <div class="hlmc-modal-body">
                        <div class="hlmc-form-field">
                            <label><?php esc_html_e('Session', 'hl-core'); ?></label>
                            <select name="session_number" required>
                                <option value=""><?php esc_html_e('-- Select Session --', 'hl-core'); ?></option>
                                <?php foreach ($available_sessions as $s) : ?>
                                    <option value="<?php echo esc_attr($s['number']); ?>"><?php echo esc_html($s['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="hlmc-form-field">
                            <label><?php esc_html_e('Date & Time', 'hl-core'); ?></label>
                            <input type="datetime-local" name="session_datetime" required />
                        </div>
                        <div class="hlmc-form-info">
                            <span class="hlmc-form-coach"><?php printf(esc_html__('Coach: %s', 'hl-core'), esc_html($coach['coach_name'])); ?></span>
                        </div>
                    </div>
                    <div class="hlmc-modal-footer">
                        <button type="button" class="hlmc-btn hlmc-btn-outline" onclick="document.getElementById('hlmc-modal').style.display='none'"><?php esc_html_e('Cancel', 'hl-core'); ?></button>
                        <button type="submit" class="hlmc-btn hlmc-btn-primary"><?php esc_html_e('Schedule', 'hl-core'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Styles
    // =========================================================================

    private static function render_styles() {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
        <style>
        .hlmc-wrap{max-width:720px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}

        .hlmc-alert{padding:12px 16px;border-radius:10px;border:1px solid;margin-bottom:16px;font-size:14px}
        .hlmc-empty{text-align:center;padding:40px;color:#94a3b8;font-size:15px}
        .hlmc-empty-section{padding:20px;text-align:center;color:#94a3b8;font-size:14px;background:#f8fafc;border-radius:10px}

        .hlmc-switcher{margin-bottom:16px}
        .hlmc-switcher select{width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit}

        /* Coach card */
        .hlmc-coach-card{display:flex;align-items:center;gap:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:20px}
        .hlmc-coach-card img.hlmc-coach-avatar{width:48px;height:48px;border-radius:50%;flex-shrink:0}
        .hlmc-coach-placeholder{width:48px;height:48px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:20px;color:#94a3b8;flex-shrink:0}
        .hlmc-coach-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8}
        .hlmc-coach-name{font-size:16px;font-weight:600;color:#1e293b}
        .hlmc-coach-email{font-size:13px;color:#2563eb;text-decoration:none}

        /* Schedule bar */
        .hlmc-schedule-bar{margin-bottom:20px}
        .hlmc-btn-schedule{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#2563eb;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s}
        .hlmc-btn-schedule:hover{background:#1d4ed8}

        /* Sections */
        .hlmc-section{margin-bottom:24px}
        .hlmc-section-title{font-size:16px;font-weight:600;color:#1e293b;margin:0 0 12px;padding-bottom:8px;border-bottom:1px solid #e2e8f0}

        /* Session cards */
        .hlmc-sessions{display:flex;flex-direction:column;gap:10px}
        .hlmc-session-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px;transition:border-color .15s}
        .hlmc-session-card:hover{border-color:#cbd5e1}
        .hlmc-session-past{background:#fafbfc}
        .hlmc-session-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
        .hlmc-session-title{font-size:15px;font-weight:600;color:#1e293b}
        .hlmc-session-num{font-size:12px;font-weight:700;background:#e2e8f0;color:#475569;padding:2px 8px;border-radius:6px;margin-left:6px;vertical-align:middle}
        .hlmc-session-date{font-size:13px;color:#64748b;margin-top:4px}
        .hlmc-session-actions{display:flex;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9}

        /* Badges */
        .hlmc-badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap}
        .hlmc-badge-blue{background:#dbeafe;color:#1e40af}
        .hlmc-badge-green{background:#d1fae5;color:#065f46}
        .hlmc-badge-red{background:#fee2e2;color:#991b1b}
        .hlmc-badge-yellow{background:#fef3c7;color:#92400e}
        .hlmc-badge-gray{background:#f1f5f9;color:#64748b}

        /* Buttons */
        .hlmc-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .15s}
        .hlmc-btn-primary{background:#2563eb;color:#fff}
        .hlmc-btn-primary:hover{background:#1d4ed8;color:#fff}
        .hlmc-btn-outline{background:#fff;color:#475569;border:1px solid #d1d5db}
        .hlmc-btn-outline:hover{background:#f8fafc;border-color:#94a3b8}

        /* Action Plan inline */
        .hlmc-action-plan{margin-top:12px;padding:12px 16px;background:#f0f4f8;border-left:3px solid #2563eb;border-radius:0 8px 8px 0;font-size:13px}
        .hlmc-ap-title{font-weight:700;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#475569}
        .hlmc-ap-row{margin-bottom:2px;color:#334155}
        .hlmc-ap-label{font-weight:600;color:#1e293b}

        /* Modal */
        .hlmc-modal-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px}
        .hlmc-modal{background:#fff;border-radius:16px;width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden}
        .hlmc-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid #e2e8f0}
        .hlmc-modal-header h3{margin:0;font-size:18px;font-weight:600;color:#1e293b}
        .hlmc-modal-close{background:none;border:none;font-size:24px;color:#94a3b8;cursor:pointer;padding:0;line-height:1}
        .hlmc-modal-close:hover{color:#475569}
        .hlmc-modal-body{padding:20px 24px}
        .hlmc-modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:16px 24px;border-top:1px solid #e2e8f0;background:#f8fafc}

        .hlmc-form-field{margin-bottom:16px}
        .hlmc-form-field label{display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px}
        .hlmc-form-field select,.hlmc-form-field input{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
        .hlmc-form-field select:focus,.hlmc-form-field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
        .hlmc-form-info{font-size:13px;color:#64748b}

        @media(max-width:600px){
            .hlmc-session-top{flex-direction:column}
            .hlmc-modal{margin:10px;max-width:none}
        }
        </style>
        <?php
    }
}
