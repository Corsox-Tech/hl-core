<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_coach_mentor_detail] shortcode.
 *
 * Tabbed mentor profile page: Coaching Sessions, Team Overview, RP Sessions, Reports.
 * Accessed via ?mentor_enrollment_id=X from the My Mentors grid.
 *
 * @package HL_Core
 */
class HL_Frontend_Coach_Mentor_Detail {

    /**
     * Handle CSV export (hooked to template_redirect).
     */
    public static function handle_export() {
        if (!isset($_POST['hl_coach_mentor_export_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['hl_coach_mentor_export_nonce'], 'hl_coach_mentor_export')) {
            return;
        }

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();
        if (!in_array('coach', (array) $user->roles, true) && !current_user_can('manage_hl_core')) {
            return;
        }

        $enrollment_id = absint($_POST['mentor_enrollment_id'] ?? 0);
        if (!$enrollment_id) {
            return;
        }

        $service = new HL_Coach_Dashboard_Service();
        $mentor  = $service->get_mentor_detail($enrollment_id, $user_id);
        if (!$mentor) {
            return;
        }

        $members = $service->get_team_members($enrollment_id, (int) $mentor['cycle_id']);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=mentor-report-' . $enrollment_id . '-' . date('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, array('Name', 'Role', 'Pathway', 'Completion %'));
        // Mentor row first.
        fputcsv($out, array(
            $mentor['display_name'],
            'Mentor',
            $mentor['pathway_name'],
            $mentor['completion_pct'] . '%',
        ));
        // Team members.
        foreach ($members as $m) {
            $roles    = HL_Roles::parse_stored($m['roles'] ?? '');
            $role_str = implode(', ', $roles);
            fputcsv($out, array(
                $m['display_name'],
                $role_str,
                $m['pathway_name'] ?? "\xe2\x80\x94",
                ($m['completion_pct'] ?? 0) . '%',
            ));
        }
        fclose($out);
        exit;
    }

    /**
     * Render the full Mentor Detail page.
     *
     * @param array $atts Shortcode attributes (unused).
     * @return string
     */
    public function render($atts) {
        ob_start();
        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        // Role check: coach WP role or manage_hl_core capability.
        if (!in_array('coach', (array) $user->roles, true) && !current_user_can('manage_hl_core')) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__('You do not have access to this page.', 'hl-core')
                . '</div>';
            return ob_get_clean();
        }

        $enrollment_id = absint($_GET['mentor_enrollment_id'] ?? 0);
        $service       = new HL_Coach_Dashboard_Service();
        $mentor        = $enrollment_id ? $service->get_mentor_detail($enrollment_id, $user_id) : null;
        $back_url      = $this->find_shortcode_page_url('hl_coach_mentors');

        // BB title hiding moved to frontend.css (Session 3 coach pages section)

        if (!$mentor) {
            ?>
            <div class="hlcmd-wrapper">
                <?php if ($back_url) : ?>
                    <a href="<?php echo esc_url($back_url); ?>" class="hlcmd-back">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        <?php esc_html_e('Back to My Mentors', 'hl-core'); ?>
                    </a>
                <?php endif; ?>
                <div class="hlcmd-empty">
                    <div class="hlcmd-empty-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <p class="hlcmd-empty-text"><?php esc_html_e('Mentor not found or you do not have access.', 'hl-core'); ?></p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        $cycle_id = (int) $mentor['cycle_id'];
        $pct      = (int) $mentor['completion_pct'];

        // Load data for all tabs.
        $coaching_service  = new HL_Coaching_Service();
        $coaching_sessions = $coaching_service->get_sessions_for_participant($enrollment_id, $cycle_id);
        $team_members      = $service->get_team_members($enrollment_id, $cycle_id);
        $rp_sessions       = $service->get_mentor_rp_sessions($enrollment_id, $cycle_id);

        // Compute team average for Reports tab.
        $team_total_pct = 0;
        $team_count     = 0;
        foreach ($team_members as $m) {
            $team_total_pct += (int) ($m['completion_pct'] ?? 0);
            $team_count++;
        }
        $team_avg = $team_count > 0 ? round($team_total_pct / $team_count) : 0;
        ?>
        <div class="hlcmd-wrapper">

            <!-- Back link -->
            <?php if ($back_url) : ?>
                <a href="<?php echo esc_url($back_url); ?>" class="hlcmd-back">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    <?php esc_html_e('Back to My Mentors', 'hl-core'); ?>
                </a>
            <?php endif; ?>

            <!-- Hero header -->
            <div class="hlcmd-hero">
                <div class="hlcmd-hero-avatar">
                    <?php echo get_avatar($mentor['user_id'], 64); ?>
                </div>
                <div class="hlcmd-hero-text">
                    <h2 class="hlcmd-hero-title"><?php echo esc_html($mentor['display_name']); ?></h2>
                    <p class="hlcmd-hero-sub"><?php echo esc_html($mentor['school_name'] ?: __('No school', 'hl-core')); ?></p>
                </div>
            </div>

            <!-- Info grid -->
            <div class="hlcmd-info-card">
                <div class="hlcmd-info-row">
                    <div class="hlcmd-info-cell">
                        <span class="hlcmd-info-label"><?php esc_html_e('Team', 'hl-core'); ?></span>
                        <span class="hlcmd-info-value"><?php echo esc_html($mentor['team_name']); ?></span>
                    </div>
                    <div class="hlcmd-info-cell">
                        <span class="hlcmd-info-label"><?php esc_html_e('Learning Plan', 'hl-core'); ?></span>
                        <span class="hlcmd-info-value"><?php echo esc_html($mentor['pathway_name']); ?></span>
                    </div>
                    <div class="hlcmd-info-cell">
                        <span class="hlcmd-info-label"><?php esc_html_e('Completion', 'hl-core'); ?></span>
                        <div class="hlcmd-info-progress">
                            <div class="hlcmd-progress-track">
                                <div class="hlcmd-progress-fill" style="width:<?php echo esc_attr($pct); ?>%"></div>
                            </div>
                            <span class="hlcmd-progress-pct"><?php echo esc_html($pct); ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab navigation -->
            <div class="hlcmd-tabs">
                <button type="button" class="hlcmd-tab-btn hlcmd-tab-active" data-tab="sessions">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?php esc_html_e('Coaching Sessions', 'hl-core'); ?>
                </button>
                <button type="button" class="hlcmd-tab-btn" data-tab="team">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <?php esc_html_e('Team Overview', 'hl-core'); ?>
                </button>
                <button type="button" class="hlcmd-tab-btn" data-tab="rp">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    <?php esc_html_e('RP Sessions', 'hl-core'); ?>
                </button>
                <button type="button" class="hlcmd-tab-btn" data-tab="reports">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <?php esc_html_e('Reports', 'hl-core'); ?>
                </button>
            </div>

            <!-- ================================================================
                 TAB 1: Coaching Sessions
                 ================================================================ -->
            <div id="hlcmd-panel-sessions" class="hlcmd-tab-panel" style="display:block;">
                <?php
                // "Schedule Next Session" button — links to the next unscheduled coaching component.
                $next_component = $this->get_next_unscheduled_component($enrollment_id, $mentor);
                if ($next_component) :
                    $comp_page_url = $this->find_shortcode_page_url('hl_component_page');
                    if ($comp_page_url) :
                        $schedule_url = add_query_arg(array(
                            'id'         => $next_component['component_id'],
                            'enrollment' => $enrollment_id,
                        ), $comp_page_url);
                ?>
                    <div class="hlcmd-schedule-wrap">
                        <a href="<?php echo esc_url($schedule_url); ?>" class="hlcmd-btn-schedule">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <?php printf(esc_html__('Schedule Next Session: %s', 'hl-core'), esc_html($next_component['title'])); ?>
                        </a>
                    </div>
                <?php endif; ?>
                <?php elseif (!empty($coaching_sessions)) : ?>
                    <div class="hlcmd-all-scheduled">
                        <?php esc_html_e('All coaching sessions are scheduled or completed.', 'hl-core'); ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($coaching_sessions)) : ?>
                    <div class="hlcmd-panel-empty">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <p><?php esc_html_e('No coaching sessions found for this mentor.', 'hl-core'); ?></p>
                    </div>
                <?php else : ?>
                    <div class="hlcmd-sessions-list">
                        <?php
                        $coach_tz_for_display = get_user_meta(get_current_user_id(), 'hl_timezone', true) ?: wp_timezone_string();
                        foreach ($coaching_sessions as $session) :
                            $fmt    = HL_Timezone_Helper::format_session_time($session['session_datetime'] ?? '', $session['coach_timezone'] ?? $coach_tz_for_display, 'M j, Y');
                            $date   = $fmt['date'] ?: "\xe2\x80\x94";
                            $time   = $fmt['time'];
                            $title  = !empty($session['session_title']) ? $session['session_title'] : __('Coaching Session', 'hl-core');
                            $status = $session['session_status'] ?? 'scheduled';
                            $coach  = $session['coach_name'] ?? "\xe2\x80\x94";
                            $url    = $session['meeting_url'] ?? '';
                        ?>
                            <div class="hlcmd-session-row">
                                <div class="hlcmd-session-main">
                                    <div class="hlcmd-session-title"><?php echo esc_html($title); ?></div>
                                    <div class="hlcmd-session-meta">
                                        <span class="hlcmd-session-date">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                            <?php echo esc_html($date); ?>
                                            <?php if ($time) : ?>
                                                <span class="hlcmd-session-time"><?php echo esc_html($time); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="hlcmd-session-coach">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                            <?php echo esc_html($coach); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="hlcmd-session-actions">
                                    <?php echo HL_Coaching_Service::render_status_badge($status); ?>
                                    <?php if ($url && $status === 'scheduled') : ?>
                                        <a href="<?php echo esc_url($url); ?>" class="hlcmd-join-btn" target="_blank" rel="noopener noreferrer">
                                            <?php esc_html_e('Join', 'hl-core'); ?>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ================================================================
                 TAB 2: Team Overview
                 ================================================================ -->
            <div id="hlcmd-panel-team" class="hlcmd-tab-panel" style="display:none;">
                <?php if (empty($team_members)) : ?>
                    <div class="hlcmd-panel-empty">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <p><?php esc_html_e('No team members found.', 'hl-core'); ?></p>
                    </div>
                <?php else :
                    // Sort: mentors first, then other roles.
                    usort($team_members, function ($a, $b) {
                        $a_roles = HL_Roles::parse_stored($a['roles'] ?? '');
                        $b_roles = HL_Roles::parse_stored($b['roles'] ?? '');
                        $a_mentor = in_array('mentor', $a_roles, true) ? 0 : 1;
                        $b_mentor = in_array('mentor', $b_roles, true) ? 0 : 1;
                        return $a_mentor - $b_mentor;
                    });
                ?>
                    <div class="hlcmd-rp-table-wrap">
                        <table class="hlcmd-rp-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Name', 'hl-core'); ?></th>
                                    <th><?php esc_html_e('Role', 'hl-core'); ?></th>
                                    <th><?php esc_html_e('Pathway', 'hl-core'); ?></th>
                                    <th><?php esc_html_e('Completion', 'hl-core'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($team_members as $member) :
                                    $m_pct = (int) ($member['completion_pct'] ?? 0);
                                    $roles = HL_Roles::parse_stored($member['roles'] ?? '');
                                ?>
                                    <tr>
                                        <td>
                                            <div class="hlcmd-team-member-name"><?php echo esc_html($member['display_name']); ?></div>
                                            <div class="hlcmd-team-member-email"><?php echo esc_html($member['user_email'] ?? ''); ?></div>
                                        </td>
                                        <td>
                                            <?php foreach ($roles as $role) :
                                                $badge_class = 'hlcmd-badge-gray';
                                                $r = strtolower($role);
                                                if ($r === 'mentor') $badge_class = 'hlcmd-badge-blue';
                                                elseif ($r === 'teacher') $badge_class = 'hlcmd-badge-green';
                                                elseif ($r === 'leader') $badge_class = 'hlcmd-badge-orange';
                                            ?>
                                                <span class="hlcmd-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html(ucfirst($role)); ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td><?php echo esc_html(!empty($member['pathway_name']) ? $member['pathway_name'] : "\xe2\x80\x94"); ?></td>
                                        <td>
                                            <div class="hlcmd-progress-row">
                                                <div class="hlcmd-progress-track">
                                                    <div class="hlcmd-progress-fill" style="width:<?php echo esc_attr($m_pct); ?>%"></div>
                                                </div>
                                                <span class="hlcmd-progress-pct"><?php echo esc_html($m_pct); ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ================================================================
                 TAB 3: RP Sessions
                 ================================================================ -->
            <div id="hlcmd-panel-rp" class="hlcmd-tab-panel" style="display:none;">
                <?php if (empty($rp_sessions)) : ?>
                    <div class="hlcmd-panel-empty">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        <p><?php esc_html_e('No reflective practice sessions found.', 'hl-core'); ?></p>
                    </div>
                <?php else : ?>
                    <div class="hlcmd-rp-table-wrap">
                        <table class="hlcmd-rp-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Date', 'hl-core'); ?></th>
                                    <th><?php esc_html_e('Teacher', 'hl-core'); ?></th>
                                    <th><?php esc_html_e('Status', 'hl-core'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rp_sessions as $rp) :
                                    $rp_date   = !empty($rp['session_date']) ? date_i18n('M j, Y', strtotime($rp['session_date'])) : "\xe2\x80\x94";
                                    $rp_teacher = $rp['teacher_name'] ?? "\xe2\x80\x94";
                                    $rp_status  = $rp['status'] ?? 'pending';
                                ?>
                                    <tr>
                                        <td><?php echo esc_html($rp_date); ?></td>
                                        <td><?php echo esc_html($rp_teacher); ?></td>
                                        <td><?php echo $this->render_rp_status_badge($rp_status); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ================================================================
                 TAB 4: Reports
                 ================================================================ -->
            <div id="hlcmd-panel-reports" class="hlcmd-tab-panel" style="display:none;">

                <!-- Summary cards -->
                <div class="hlcmd-report-grid">
                    <div class="hlcmd-report-card">
                        <div class="hlcmd-report-card-label"><?php esc_html_e('Mentor Completion', 'hl-core'); ?></div>
                        <div class="hlcmd-report-card-value"><?php echo esc_html($pct); ?>%</div>
                        <div class="hlcmd-progress-track hlcmd-progress-track-lg">
                            <div class="hlcmd-progress-fill" style="width:<?php echo esc_attr($pct); ?>%"></div>
                        </div>
                    </div>
                    <div class="hlcmd-report-card">
                        <div class="hlcmd-report-card-label"><?php esc_html_e('Team Average', 'hl-core'); ?></div>
                        <div class="hlcmd-report-card-value"><?php echo esc_html($team_avg); ?>%</div>
                        <div class="hlcmd-progress-track hlcmd-progress-track-lg">
                            <div class="hlcmd-progress-fill hlcmd-progress-fill-alt" style="width:<?php echo esc_attr($team_avg); ?>%"></div>
                        </div>
                    </div>
                    <div class="hlcmd-report-card">
                        <div class="hlcmd-report-card-label"><?php esc_html_e('Team Members', 'hl-core'); ?></div>
                        <div class="hlcmd-report-card-value"><?php echo esc_html($team_count); ?></div>
                    </div>
                    <div class="hlcmd-report-card">
                        <div class="hlcmd-report-card-label"><?php esc_html_e('Coaching Sessions', 'hl-core'); ?></div>
                        <div class="hlcmd-report-card-value"><?php echo esc_html(count($coaching_sessions)); ?></div>
                    </div>
                </div>

                <!-- Comparison bar -->
                <?php if ($team_count > 0) : ?>
                    <div class="hlcmd-comparison-card">
                        <div class="hlcmd-comparison-title"><?php esc_html_e('Mentor vs Team Average', 'hl-core'); ?></div>
                        <div class="hlcmd-comparison-row">
                            <span class="hlcmd-comparison-label"><?php echo esc_html($mentor['display_name']); ?></span>
                            <div class="hlcmd-progress-track hlcmd-progress-track-wide">
                                <div class="hlcmd-progress-fill" style="width:<?php echo esc_attr($pct); ?>%"></div>
                            </div>
                            <span class="hlcmd-comparison-pct"><?php echo esc_html($pct); ?>%</span>
                        </div>
                        <div class="hlcmd-comparison-row">
                            <span class="hlcmd-comparison-label"><?php esc_html_e('Team Average', 'hl-core'); ?></span>
                            <div class="hlcmd-progress-track hlcmd-progress-track-wide">
                                <div class="hlcmd-progress-fill hlcmd-progress-fill-alt" style="width:<?php echo esc_attr($team_avg); ?>%"></div>
                            </div>
                            <span class="hlcmd-comparison-pct"><?php echo esc_html($team_avg); ?>%</span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- CSV export -->
                <div class="hlcmd-export-card">
                    <div class="hlcmd-export-text">
                        <div class="hlcmd-export-title"><?php esc_html_e('Export Report', 'hl-core'); ?></div>
                        <div class="hlcmd-export-desc"><?php esc_html_e('Download a CSV with the mentor and team completion data.', 'hl-core'); ?></div>
                    </div>
                    <form method="post" class="hlcmd-export-form">
                        <?php wp_nonce_field('hl_coach_mentor_export', 'hl_coach_mentor_export_nonce'); ?>
                        <input type="hidden" name="mentor_enrollment_id" value="<?php echo esc_attr($enrollment_id); ?>">
                        <button type="submit" class="hlcmd-export-btn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            <?php esc_html_e('Download CSV', 'hl-core'); ?>
                        </button>
                    </form>
                </div>

            </div>

        </div>

        <!-- Tab JS -->
        <script>
        (function(){
            var tabs = document.querySelectorAll('.hlcmd-tab-btn');
            var panels = document.querySelectorAll('.hlcmd-tab-panel');
            tabs.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var target = this.getAttribute('data-tab');
                    tabs.forEach(function(t) { t.classList.remove('hlcmd-tab-active'); });
                    panels.forEach(function(p) { p.style.display = 'none'; });
                    this.classList.add('hlcmd-tab-active');
                    document.getElementById('hlcmd-panel-' + target).style.display = 'block';
                });
            });
        })();
        </script>
        <?php

        return ob_get_clean();
    }

    /**
     * Render an RP session status badge.
     *
     * @param string $status
     * @return string
     */
    private function render_rp_status_badge($status) {
        $class_map = array(
            'completed' => 'hlcmd-rp-badge--completed',
            'scheduled' => 'hlcmd-rp-badge--scheduled',
            'pending'   => 'hlcmd-rp-badge--pending',
            'cancelled' => 'hlcmd-rp-badge--cancelled',
        );
        $class = isset($class_map[$status]) ? $class_map[$status] : 'hlcmd-rp-badge--cancelled';
        return sprintf(
            '<span class="hlcmd-rp-badge %s">%s</span>',
            esc_attr($class),
            esc_html(ucfirst($status))
        );
    }


    /**
     * Find the published page that contains a given shortcode.
     *
     * @param string $shortcode Shortcode tag (without brackets).
     * @return string Page permalink or empty string.
     */
    /**
     * Find the next unscheduled coaching session component for this mentor.
     *
     * @param int   $enrollment_id
     * @param array $mentor Mentor detail array (needs assigned_pathway_id or pathway lookup).
     * @return array|null Component row or null if all scheduled/completed.
     */
    private function get_next_unscheduled_component($enrollment_id, $mentor) {
        global $wpdb;

        $pathway_id = !empty($mentor['pathway_id']) ? (int) $mentor['pathway_id'] : 0;
        if (!$pathway_id) {
            // Try to resolve from enrollment.
            $pathway_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT pa.pathway_id
                 FROM {$wpdb->prefix}hl_pathway_assignment pa
                 WHERE pa.enrollment_id = %d
                 ORDER BY pa.created_at DESC LIMIT 1",
                $enrollment_id
            ));
        }
        if (!$pathway_id) {
            return null;
        }

        // Get all coaching components for this pathway.
        $components = $wpdb->get_results($wpdb->prepare(
            "SELECT component_id, title FROM {$wpdb->prefix}hl_component
             WHERE pathway_id = %d AND component_type = 'coaching_session_attendance' AND status = 'active'
             ORDER BY ordering_hint ASC",
            $pathway_id
        ), ARRAY_A);

        if (empty($components)) {
            return null;
        }

        // Get component_ids that already have a scheduled or attended session.
        $comp_ids     = array_map(function ($c) { return (int) $c['component_id']; }, $components);
        $placeholders = implode(',', array_fill(0, count($comp_ids), '%d'));
        $params       = array_merge($comp_ids, array($enrollment_id));

        $scheduled_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT component_id FROM {$wpdb->prefix}hl_coaching_session
             WHERE component_id IN ($placeholders)
               AND mentor_enrollment_id = %d
               AND session_status IN ('scheduled', 'attended')",
            ...$params
        ));
        $scheduled_set = array_map('intval', $scheduled_ids);

        // Return the first component without a session.
        foreach ($components as $comp) {
            if (!in_array((int) $comp['component_id'], $scheduled_set, true)) {
                return $comp;
            }
        }

        return null;
    }

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
}
