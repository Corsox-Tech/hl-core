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
            $roles    = json_decode($m['roles'] ?? '[]', true);
            $role_str = is_array($roles) ? implode(', ', $roles) : '';
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

        $this->render_styles();

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
                    <div style="margin-bottom:16px;">
                        <a href="<?php echo esc_url($schedule_url); ?>" class="hlcmd-btn-schedule">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <?php printf(esc_html__('Schedule Next Session: %s', 'hl-core'), esc_html($next_component['title'])); ?>
                        </a>
                    </div>
                <?php endif; ?>
                <?php elseif (!empty($coaching_sessions)) : ?>
                    <div style="margin-bottom:16px;padding:10px 16px;background:#d1fae5;border-radius:8px;font-size:14px;color:#065f46;font-weight:500;">
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
                        <?php foreach ($coaching_sessions as $session) :
                            $dt     = !empty($session['session_datetime']) ? strtotime($session['session_datetime']) : 0;
                            $date   = $dt ? date_i18n('M j, Y', $dt) : "\xe2\x80\x94";
                            $time   = $dt ? date_i18n('g:i A', $dt) : '';
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
                <?php else : ?>
                    <div class="hlcmd-team-grid">
                        <?php foreach ($team_members as $member) :
                            $m_pct   = (int) ($member['completion_pct'] ?? 0);
                            $roles   = json_decode($member['roles'] ?? '[]', true);
                            $roles   = is_array($roles) ? $roles : array();
                        ?>
                            <div class="hlcmd-team-card">
                                <div class="hlcmd-team-card-top">
                                    <div class="hlcmd-team-card-name"><?php echo esc_html($member['display_name']); ?></div>
                                    <div class="hlcmd-team-card-email"><?php echo esc_html($member['user_email'] ?? ''); ?></div>
                                </div>
                                <?php if (!empty($roles)) : ?>
                                    <div class="hlcmd-team-card-badges">
                                        <?php foreach ($roles as $role) :
                                            $badge_class = 'hlcmd-badge-gray';
                                            $r = strtolower($role);
                                            if ($r === 'mentor') {
                                                $badge_class = 'hlcmd-badge-blue';
                                            } elseif ($r === 'teacher') {
                                                $badge_class = 'hlcmd-badge-green';
                                            } elseif ($r === 'leader') {
                                                $badge_class = 'hlcmd-badge-orange';
                                            }
                                        ?>
                                            <span class="hlcmd-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html(ucfirst($role)); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($member['pathway_name'])) : ?>
                                    <div class="hlcmd-team-card-pathway"><?php echo esc_html($member['pathway_name']); ?></div>
                                <?php endif; ?>
                                <div class="hlcmd-progress-row">
                                    <div class="hlcmd-progress-track">
                                        <div class="hlcmd-progress-fill" style="width:<?php echo esc_attr($m_pct); ?>%"></div>
                                    </div>
                                    <span class="hlcmd-progress-pct"><?php echo esc_html($m_pct); ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
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
        $map = array(
            'completed' => array('#d1fae5', '#065f46'),
            'scheduled' => array('#dbeafe', '#1e40af'),
            'pending'   => array('#fef3c7', '#92400e'),
            'cancelled' => array('#f1f5f9', '#64748b'),
        );
        $badge = isset($map[$status]) ? $map[$status] : array('#f1f5f9', '#64748b');
        return sprintf(
            '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:%s;color:%s;">%s</span>',
            esc_attr($badge[0]),
            esc_attr($badge[1]),
            esc_html(ucfirst($status))
        );
    }

    /**
     * All CSS for the Mentor Detail page (inline to avoid external CSS dependency).
     */
    private function render_styles() {
        ?>
        <style>
        .hlcmd-wrapper{max-width:1100px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}

        /* Back link */
        .hlcmd-back{display:inline-flex;align-items:center;gap:6px;font-size:14px;font-weight:500;color:#475569;text-decoration:none;margin-bottom:20px;transition:color .2s}
        .hlcmd-back:hover{color:#1e3a5f;text-decoration:none}

        /* Hero */
        .hlcmd-hero{display:flex;align-items:center;gap:20px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;padding:28px 32px;border-radius:16px;margin-bottom:24px}
        .hlcmd-hero-avatar{flex-shrink:0}
        .hlcmd-hero-avatar img{width:64px;height:64px;border-radius:50%;border:3px solid rgba(255,255,255,.25);display:block}
        .hlcmd-hero-title{font-size:22px;font-weight:700;margin:0;letter-spacing:-.3px}
        .hlcmd-hero-sub{font-size:14px;opacity:.75;margin:4px 0 0}

        /* Info card */
        .hlcmd-info-card{background:#f8f9fb;border:1px solid #e2e8f0;border-radius:14px;padding:20px 24px;margin-bottom:24px}
        .hlcmd-info-row{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
        .hlcmd-info-cell{display:flex;flex-direction:column;gap:6px}
        .hlcmd-info-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:#8896a6}
        .hlcmd-info-value{font-size:15px;font-weight:600;color:#1e293b}
        .hlcmd-info-progress{display:flex;align-items:center;gap:10px}

        /* Progress bars (shared) */
        .hlcmd-progress-row{display:flex;align-items:center;gap:10px}
        .hlcmd-progress-track{flex:1;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden}
        .hlcmd-progress-track-lg{height:10px;margin-top:8px}
        .hlcmd-progress-track-wide{flex:1;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden}
        .hlcmd-progress-fill{height:100%;background:linear-gradient(90deg,#059669,#10b981);border-radius:4px;transition:width .4s ease}
        .hlcmd-progress-fill-alt{background:linear-gradient(90deg,#2563eb,#60a5fa)}
        .hlcmd-progress-pct{flex-shrink:0;font-size:13px;font-weight:700;color:#1e293b;min-width:36px;text-align:right}

        /* Tabs */
        .hlcmd-tabs{display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap}
        .hlcmd-tab-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:10px;font-size:14px;font-weight:600;border:2px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .2s;font-family:inherit}
        .hlcmd-tab-btn:hover{border-color:#94a3b8;color:#334155;background:#f8fafc}
        .hlcmd-tab-btn.hlcmd-tab-active{background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;border-color:transparent;box-shadow:0 4px 14px rgba(30,58,95,.25)}
        .hlcmd-tab-btn.hlcmd-tab-active svg{stroke:#fff}

        /* Tab panels */
        .hlcmd-tab-panel{animation:hlcmdFadeIn .3s ease}
        @keyframes hlcmdFadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

        /* Panel empty state */
        .hlcmd-panel-empty{text-align:center;padding:48px 20px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;color:#8896a6}
        .hlcmd-panel-empty svg{margin-bottom:12px;opacity:.5}
        .hlcmd-panel-empty p{font-size:15px;margin:0}

        /* ---- TAB 1: Coaching Sessions ---- */
        .hlcmd-btn-schedule{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#2563eb;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:background .15s}
        .hlcmd-btn-schedule:hover{background:#1d4ed8;color:#fff}
        .hlcmd-sessions-list{display:flex;flex-direction:column;gap:12px}
        .hlcmd-session-row{display:flex;align-items:center;justify-content:space-between;gap:16px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 24px;transition:box-shadow .25s ease}
        .hlcmd-session-row:hover{box-shadow:0 4px 16px rgba(0,0,0,.06)}
        .hlcmd-session-main{flex:1;min-width:0}
        .hlcmd-session-title{font-size:15px;font-weight:600;color:#1e293b;margin-bottom:6px}
        .hlcmd-session-meta{display:flex;flex-wrap:wrap;gap:16px;font-size:13px;color:#64748b}
        .hlcmd-session-meta svg{vertical-align:middle;margin-right:4px;opacity:.6}
        .hlcmd-session-time{margin-left:6px;color:#8896a6}
        .hlcmd-session-actions{display:flex;align-items:center;gap:12px;flex-shrink:0}
        .hlcmd-join-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;background:#059669;color:#fff;text-decoration:none;transition:background .2s,box-shadow .2s}
        .hlcmd-join-btn:hover{background:#047857;box-shadow:0 4px 12px rgba(5,150,105,.3);text-decoration:none;color:#fff}

        /* ---- TAB 2: Team Overview ---- */
        .hlcmd-team-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
        .hlcmd-team-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px 24px;transition:box-shadow .25s ease,transform .25s ease}
        .hlcmd-team-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.06);transform:translateY(-1px)}
        .hlcmd-team-card-top{margin-bottom:12px}
        .hlcmd-team-card-name{font-size:15px;font-weight:600;color:#1e293b;margin-bottom:2px}
        .hlcmd-team-card-email{font-size:13px;color:#8896a6}
        .hlcmd-team-card-badges{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
        .hlcmd-badge{display:inline-block;padding:4px 12px;font-size:12px;font-weight:600;border-radius:20px;line-height:1.4}
        .hlcmd-badge-green{background:#d1fae5;color:#065f46}
        .hlcmd-badge-blue{background:#dbeafe;color:#1e40af}
        .hlcmd-badge-orange{background:#fef3c7;color:#92400e}
        .hlcmd-badge-gray{background:#f1f5f9;color:#64748b}
        .hlcmd-team-card-pathway{font-size:13px;color:#64748b;margin-bottom:12px}

        /* ---- TAB 3: RP Sessions ---- */
        .hlcmd-rp-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden}
        .hlcmd-rp-table{width:100%;border-collapse:collapse}
        .hlcmd-rp-table thead th{text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:#8896a6;padding:14px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
        .hlcmd-rp-table tbody td{padding:14px 20px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9}
        .hlcmd-rp-table tbody tr:last-child td{border-bottom:none}
        .hlcmd-rp-table tbody tr:hover{background:#fafbfc}

        /* ---- TAB 4: Reports ---- */
        .hlcmd-report-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
        .hlcmd-report-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px;text-align:center}
        .hlcmd-report-card-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:#8896a6;margin-bottom:8px}
        .hlcmd-report-card-value{font-size:32px;font-weight:700;color:#1e293b;line-height:1;margin-bottom:4px}

        /* Comparison card */
        .hlcmd-comparison-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;margin-bottom:24px}
        .hlcmd-comparison-title{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#475569;margin-bottom:20px}
        .hlcmd-comparison-row{display:flex;align-items:center;gap:14px;margin-bottom:14px}
        .hlcmd-comparison-row:last-child{margin-bottom:0}
        .hlcmd-comparison-label{font-size:14px;font-weight:500;color:#334155;min-width:140px;flex-shrink:0}
        .hlcmd-comparison-pct{font-size:14px;font-weight:700;color:#1e293b;min-width:44px;text-align:right;flex-shrink:0}

        /* Export card */
        .hlcmd-export-card{display:flex;align-items:center;justify-content:space-between;gap:20px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px}
        .hlcmd-export-title{font-size:15px;font-weight:600;color:#1e293b;margin-bottom:4px}
        .hlcmd-export-desc{font-size:13px;color:#8896a6}
        .hlcmd-export-form{flex-shrink:0}
        .hlcmd-export-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border-radius:10px;font-size:14px;font-weight:600;background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;border:none;cursor:pointer;transition:box-shadow .2s,transform .2s;font-family:inherit}
        .hlcmd-export-btn:hover{box-shadow:0 6px 20px rgba(30,58,95,.3);transform:translateY(-1px)}

        /* Empty state (not-found) */
        .hlcmd-empty{text-align:center;padding:60px 20px;background:#fff;border:1px solid #e2e8f0;border-radius:14px}
        .hlcmd-empty-icon{display:inline-flex;align-items:center;justify-content:center;width:80px;height:80px;border-radius:50%;background:rgba(30,58,95,.06);color:#8896a6;margin-bottom:16px}
        .hlcmd-empty-text{font-size:16px;color:#64748b;margin:0}

        /* Responsive */
        @media(max-width:600px){
            .hlcmd-hero{flex-direction:column;text-align:center;padding:24px 20px}
            .hlcmd-info-row{grid-template-columns:1fr}
            .hlcmd-tabs{flex-direction:column}
            .hlcmd-tab-btn{width:100%;justify-content:center}
            .hlcmd-session-row{flex-direction:column;align-items:flex-start;gap:12px}
            .hlcmd-session-actions{width:100%;justify-content:flex-start}
            .hlcmd-team-grid{grid-template-columns:1fr}
            .hlcmd-report-grid{grid-template-columns:repeat(2,1fr)}
            .hlcmd-comparison-row{flex-direction:column;align-items:flex-start;gap:6px}
            .hlcmd-comparison-label{min-width:0}
            .hlcmd-export-card{flex-direction:column;text-align:center}
            .hlcmd-rp-table thead th,.hlcmd-rp-table tbody td{padding:10px 12px;font-size:13px}
        }
        </style>
        <?php
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
