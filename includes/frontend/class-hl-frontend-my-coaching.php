<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_my_coaching] shortcode.
 *
 * Coaching sessions hub: component-based table showing all coaching
 * session components with status (completed/scheduled/not scheduled),
 * drip rule locking, complete_by dates, grouped by cycle.
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

        // Get user's active enrollments.
        $enrollment_repo = new HL_Enrollment_Repository();
        $all_enrollments = $enrollment_repo->get_all(array('status' => 'active'));
        $enrollments = array_values(array_filter($all_enrollments, function ($e) use ($user_id) {
            return (int) $e->user_id === $user_id;
        }));

        if (empty($enrollments)) {
            echo '<div class="hlmc-wrap"><div class="hlmc-empty">' . esc_html__('You are not enrolled in any programs.', 'hl-core') . '</div></div>';
            return ob_get_clean();
        }

        self::render_styles();

        echo '<div class="hlmc-wrap">';

        // Group by cycle.
        $cycle_repo   = new HL_Cycle_Repository();
        $pathway_repo = new HL_Pathway_Repository();

        foreach ($enrollments as $enrollment) {
            $cycle_id      = (int) $enrollment->cycle_id;
            $enrollment_id = (int) $enrollment->enrollment_id;
            $pathway_id    = !empty($enrollment->assigned_pathway_id) ? (int) $enrollment->assigned_pathway_id : 0;

            if (!$pathway_id) {
                continue;
            }

            // Load cycle/pathway info.
            $cycle   = $cycle_repo->get_by_id($cycle_id);
            $pathway = $pathway_repo->get_by_id($pathway_id);

            // Skip closed/archived cycles — only show active cycles.
            if ($cycle && !empty($cycle->status) && !in_array($cycle->status, array('active', 'draft'), true)) {
                continue;
            }

            // Get coach assignment.
            $coach_service = new HL_Coach_Assignment_Service();
            $coach         = $coach_service->get_coach_for_enrollment($enrollment_id, $cycle_id);

            // Get coaching session components for this pathway.
            $components = $this->get_coaching_components($pathway_id);

            if (empty($components)) {
                continue;
            }

            // Get session data for these components.
            $sessions   = $this->get_sessions_for_enrollment($enrollment_id, $components);
            $drip_rules = $this->get_drip_rules($components);

            // Cycle header (show if multiple enrollments).
            if (count($enrollments) > 1) {
                $cycle_name   = $cycle ? $cycle->cycle_name : '';
                $pathway_name = $pathway ? $pathway->pathway_name : '';
                echo '<div class="hlmc-cycle-header">';
                echo '<h3 style="margin:0;font-size:16px;color:#1e3a5f;">' . esc_html($pathway_name) . '</h3>';
                if ($cycle_name) {
                    echo '<span style="font-size:13px;color:#64748b;">' . esc_html($cycle_name) . '</span>';
                }
                echo '</div>';
            }

            // Coach card.
            $this->render_coach_card($coach);

            // Sessions table.
            $this->render_sessions_table($components, $sessions, $drip_rules, $enrollment, $pathway_id);
        }

        echo '</div>';

        return ob_get_clean();
    }

    // =========================================================================
    // Data Loading
    // =========================================================================

    /**
     * Get coaching_session_attendance components for a pathway, ordered.
     */
    private function get_coaching_components($pathway_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT component_id, title, ordering_hint, complete_by, external_ref
             FROM {$wpdb->prefix}hl_component
             WHERE pathway_id = %d AND component_type = 'coaching_session_attendance' AND status = 'active'
             ORDER BY ordering_hint ASC",
            $pathway_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get session records keyed by component_id for an enrollment.
     */
    private function get_sessions_for_enrollment($enrollment_id, $components) {
        global $wpdb;

        $component_ids = array_map(function ($c) { return (int) $c['component_id']; }, $components);
        if (empty($component_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($component_ids), '%d'));
        $params       = array_merge($component_ids, array($enrollment_id));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cs.*
             FROM {$wpdb->prefix}hl_coaching_session cs
             WHERE cs.component_id IN ($placeholders)
               AND cs.mentor_enrollment_id = %d
               AND cs.session_status IN ('scheduled', 'attended')
             ORDER BY cs.session_status = 'attended' DESC, cs.created_at DESC",
            ...$params
        ), ARRAY_A);

        // Key by component_id (first match wins — attended takes priority via ORDER BY).
        $by_component = array();
        foreach ($rows as $row) {
            $cid = (int) $row['component_id'];
            if (!isset($by_component[$cid])) {
                $by_component[$cid] = $row;
            }
        }
        return $by_component;
    }

    /**
     * Get drip rules keyed by component_id.
     */
    private function get_drip_rules($components) {
        global $wpdb;

        $component_ids = array_map(function ($c) { return (int) $c['component_id']; }, $components);
        if (empty($component_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($component_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_component_drip_rule WHERE component_id IN ($placeholders)",
            ...$component_ids
        ), ARRAY_A);

        $by_component = array();
        foreach ($rows as $row) {
            $by_component[(int) $row['component_id']] = $row;
        }
        return $by_component;
    }

    // =========================================================================
    // Rendering
    // =========================================================================

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

    private function render_sessions_table($components, $sessions, $drip_rules, $enrollment, $pathway_id) {
        $component_page_url = $this->find_shortcode_page_url('hl_component_page');
        $enrollment_id      = (int) $enrollment->enrollment_id;
        $now                = current_time('mysql');
        $session_num        = 0;

        echo '<div class="hlmc-sessions-table">';

        foreach ($components as $comp) {
            $session_num++;
            $cid     = (int) $comp['component_id'];
            $session = isset($sessions[$cid]) ? $sessions[$cid] : null;
            $rule    = isset($drip_rules[$cid]) ? $drip_rules[$cid] : null;
            $title   = !empty($comp['title']) ? $comp['title'] : sprintf(__('Coaching Session #%d', 'hl-core'), $session_num);

            // Determine status.
            $status_html = '';
            $is_locked   = false;
            $badge_class = '';

            if ($session && $session['session_status'] === 'attended') {
                $date_display = !empty($session['session_datetime'])
                    ? date_i18n('m/d/Y', strtotime($session['session_datetime']))
                    : '';
                $status_html = '<span class="hlmc-badge hlmc-badge-green">' . esc_html__('Completed', 'hl-core') . '</span>';
                if ($date_display) {
                    $status_html .= ' <span class="hlmc-status-date">' . esc_html($date_display) . '</span>';
                }
            } elseif ($session && $session['session_status'] === 'scheduled') {
                $date_display = !empty($session['session_datetime'])
                    ? date_i18n('m/d/Y', strtotime($session['session_datetime']))
                    : '';
                $status_html = '<span class="hlmc-badge hlmc-badge-blue">' . esc_html__('Scheduled', 'hl-core') . '</span>';
                if ($date_display) {
                    $status_html .= ' <span class="hlmc-status-date">' . esc_html($date_display) . '</span>';
                }
            } else {
                // Not scheduled — check drip rule.
                $lock = $this->check_drip_lock($rule, $enrollment_id, $now);
                if ($lock['locked']) {
                    $is_locked   = true;
                    $status_html = '<span class="hlmc-badge hlmc-badge-gray">' . esc_html__('Not Scheduled', 'hl-core') . '</span>';
                    $status_html .= ' <span class="hlmc-status-note">' . esc_html($lock['note']) . '</span>';
                } else {
                    $status_html = '<span class="hlmc-badge hlmc-badge-gray">' . esc_html__('Not Scheduled', 'hl-core') . '</span>';
                    if (!empty($comp['complete_by'])) {
                        try {
                            $cb_date = new DateTime($comp['complete_by'], wp_timezone());
                            $status_html .= ' <span class="hlmc-status-note">' . sprintf(
                                esc_html__('Complete by: %s', 'hl-core'),
                                $cb_date->format('m/d/Y')
                            ) . '</span>';
                        } catch (Exception $e) {
                            // Ignore.
                        }
                    }
                }
            }

            // View button.
            $view_url = '';
            if (!empty($component_page_url)) {
                $view_url = add_query_arg(array(
                    'id'         => $cid,
                    'enrollment' => $enrollment_id,
                ), $component_page_url);
            }

            ?>
            <div class="hlmc-session-row <?php echo $is_locked ? 'hlmc-session-locked' : ''; ?>">
                <div class="hlmc-session-info">
                    <div class="hlmc-session-title">
                        <span class="hlmc-session-num">#<?php echo esc_html($session_num); ?></span>
                        <?php echo esc_html($title); ?>
                    </div>
                    <div class="hlmc-session-status">
                        <?php echo $status_html; ?>
                    </div>
                </div>
                <div class="hlmc-session-action">
                    <?php if ($view_url && !$is_locked) : ?>
                        <a href="<?php echo esc_url($view_url); ?>" class="hlmc-btn hlmc-btn-outline">
                            <?php esc_html_e('View', 'hl-core'); ?>
                        </a>
                    <?php elseif ($is_locked) : ?>
                        <span class="hlmc-btn hlmc-btn-disabled">
                            <span class="dashicons dashicons-lock" style="font-size:14px;width:14px;height:14px;"></span>
                            <?php esc_html_e('View', 'hl-core'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }

        echo '</div>';
    }

    // =========================================================================
    // Drip Rule Check
    // =========================================================================

    private function check_drip_lock($rule, $enrollment_id, $now) {
        if (!$rule) {
            return array('locked' => false, 'note' => '');
        }

        if ($rule['drip_type'] === 'fixed_date' && !empty($rule['release_at_date'])) {
            if ($rule['release_at_date'] > $now) {
                try {
                    $dt = new DateTime($rule['release_at_date'], wp_timezone());
                    return array('locked' => true, 'note' => sprintf(__('Release: %s', 'hl-core'), $dt->format('m/d/Y')));
                } catch (Exception $e) {
                    return array('locked' => true, 'note' => __('Not yet released', 'hl-core'));
                }
            }
        }

        if ($rule['drip_type'] === 'after_completion_delay' && !empty($rule['base_component_id'])) {
            global $wpdb;
            $base_completed = $wpdb->get_var($wpdb->prepare(
                "SELECT completed_at FROM {$wpdb->prefix}hl_component_state
                 WHERE component_id = %d AND enrollment_id = %d AND completion_status = 'complete'",
                $rule['base_component_id'], $enrollment_id
            ));

            if (!$base_completed) {
                return array('locked' => true, 'note' => __('Prerequisite required', 'hl-core'));
            }

            $delay_days = (int) ($rule['delay_days'] ?? 0);
            if ($delay_days > 0) {
                try {
                    $release = new DateTime($base_completed, wp_timezone());
                    $release->modify('+' . $delay_days . ' days');
                    $now_dt = new DateTime($now, wp_timezone());
                    if ($now_dt < $release) {
                        return array('locked' => true, 'note' => sprintf(__('Release: %s', 'hl-core'), $release->format('m/d/Y')));
                    }
                } catch (Exception $e) {
                    // Fall through.
                }
            }
        }

        return array('locked' => false, 'note' => '');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function find_shortcode_page_url($shortcode) {
        global $wpdb;
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
             AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like($shortcode) . '%'
        ));
        return $page_id ? get_permalink($page_id) : '';
    }

    // =========================================================================
    // Legacy POST handlers (kept for backward compat but largely unused now)
    // =========================================================================

    public static function handle_post_actions() {
        // Legacy handlers removed — scheduling now uses AJAX via HL_Scheduling_Service.
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
        .hlmc-empty{text-align:center;padding:40px;color:#94a3b8;font-size:15px}

        /* Cycle header */
        .hlmc-cycle-header{margin-bottom:12px;padding:12px 0;border-bottom:1px solid #e2e8f0}

        /* Coach card */
        .hlmc-coach-card{display:flex;align-items:center;gap:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:20px}
        .hlmc-coach-card img.hlmc-coach-avatar{width:48px;height:48px;border-radius:50%;flex-shrink:0}
        .hlmc-coach-placeholder{width:48px;height:48px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:20px;color:#94a3b8;flex-shrink:0}
        .hlmc-coach-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8}
        .hlmc-coach-name{font-size:16px;font-weight:600;color:#1e293b}
        .hlmc-coach-email{font-size:13px;color:#2563eb;text-decoration:none}

        /* Sessions table */
        .hlmc-sessions-table{display:flex;flex-direction:column;gap:8px;margin-bottom:28px}
        .hlmc-session-row{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;transition:border-color .15s}
        .hlmc-session-row:hover{border-color:#cbd5e1}
        .hlmc-session-locked{opacity:.65}
        .hlmc-session-info{flex:1;min-width:0}
        .hlmc-session-title{font-size:15px;font-weight:600;color:#1e293b;margin-bottom:4px}
        .hlmc-session-num{display:inline-block;font-size:12px;font-weight:700;background:#e2e8f0;color:#475569;padding:2px 8px;border-radius:6px;margin-right:6px;vertical-align:middle}
        .hlmc-session-status{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .hlmc-status-date{font-size:13px;color:#475569}
        .hlmc-status-note{font-size:12px;color:#94a3b8;font-style:italic}

        /* Badges */
        .hlmc-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap}
        .hlmc-badge-blue{background:#dbeafe;color:#1e40af}
        .hlmc-badge-green{background:#d1fae5;color:#065f46}
        .hlmc-badge-gray{background:#f1f5f9;color:#64748b}

        /* Buttons */
        .hlmc-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .15s}
        .hlmc-btn-outline{background:#fff;color:#475569;border:1px solid #d1d5db}
        .hlmc-btn-outline:hover{background:#f8fafc;border-color:#94a3b8;color:#1e293b}
        .hlmc-btn-disabled{background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0;cursor:default}

        @media(max-width:600px){
            .hlmc-session-row{flex-direction:column;align-items:flex-start}
            .hlmc-session-action{width:100%}
            .hlmc-session-action .hlmc-btn{width:100%;justify-content:center}
        }
        </style>
        <?php
    }
}
