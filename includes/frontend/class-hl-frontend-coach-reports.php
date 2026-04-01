<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_coach_reports] shortcode.
 *
 * Aggregated mentor completion data with filters, summary table,
 * team comparison cards, and CSV export.
 *
 * @package HL_Core
 */
class HL_Frontend_Coach_Reports {

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

        $service    = new HL_Coach_Dashboard_Service();
        $cycle_repo = new HL_Cycle_Repository();

        // Build filter option lists.
        $cycle_ids      = $service->get_coach_cycle_ids($user_id);
        $school_options = $service->get_coach_school_options($user_id);

        // Build cycle options with names.
        $cycle_options = array();
        foreach ($cycle_ids as $cid) {
            $cycle_obj = $cycle_repo->get_by_id($cid);
            if ($cycle_obj) {
                $cycle_options[$cid] = $cycle_obj->cycle_name;
            }
        }

        // Read GET filters.
        $filter_cycle_id = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : 0;
        $filter_school   = isset($_GET['school']) ? sanitize_text_field($_GET['school']) : '';

        // Fetch aggregated report data.
        $mentors = $service->get_aggregated_report($user_id, array(
            'cycle_id'    => $filter_cycle_id,
            'school_name' => $filter_school,
        ));

        // Summary stats.
        $unique_mentors = array();
        $unique_schools = array();
        $total_pct      = 0;
        foreach ($mentors as $m) {
            $unique_mentors[$m['display_name']] = true;
            if (!empty($m['school_name'])) {
                $unique_schools[$m['school_name']] = true;
            }
            $total_pct += floatval($m['completion_pct']);
        }
        $total_mentors = count($unique_mentors);
        $avg_completion = $total_mentors > 0 ? round($total_pct / count($mentors), 1) : 0;
        $schools_count  = count($unique_schools);

        // Team comparison.
        $teams = array();
        foreach ($mentors as $m) {
            $tname = !empty($m['team_name']) ? $m['team_name'] : __('Unassigned', 'hl-core');
            if (!isset($teams[$tname])) {
                $teams[$tname] = array('total_pct' => 0, 'count' => 0, 'members' => array());
            }
            $teams[$tname]['total_pct'] += floatval($m['completion_pct']);
            $teams[$tname]['count']++;
            $teams[$tname]['members'][$m['display_name']] = true;
        }

        // Current page URL for filter form.
        $page_url = $this->find_shortcode_page_url('hl_coach_reports');
        if (!$page_url) {
            $page_url = get_permalink();
        }

        // Back link to dashboard.
        $dashboard_url = $this->find_shortcode_page_url('hl_coach_dashboard');

        ?>
        <div class="hlcr-wrapper">

            <!-- Hero header -->
            <div class="hlcr-hero">
                <div class="hlcr-hero-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="10" x2="8" y2="16"/><line x1="16" y1="12" x2="16" y2="16"/></svg>
                </div>
                <div class="hlcr-hero-text">
                    <h2 class="hlcr-hero-title"><?php esc_html_e('Coach Reports', 'hl-core'); ?></h2>
                    <p class="hlcr-hero-sub"><?php esc_html_e('Aggregated mentor completion data', 'hl-core'); ?></p>
                </div>
                <?php if ($dashboard_url) : ?>
                <a href="<?php echo esc_url($dashboard_url); ?>" class="hlcr-hero-back">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    <?php esc_html_e('Dashboard', 'hl-core'); ?>
                </a>
                <?php endif; ?>
            </div>

            <!-- Filter bar -->
            <div class="hlcr-filter-bar">
                <form method="get" action="<?php echo esc_url($page_url); ?>" class="hlcr-filter-form">
                    <div class="hlcr-filter-group">
                        <label class="hlcr-filter-label" for="hlcr-cycle"><?php esc_html_e('Cycle', 'hl-core'); ?></label>
                        <select name="cycle_id" id="hlcr-cycle" class="hlcr-filter-select">
                            <option value="0"><?php esc_html_e('All Cycles', 'hl-core'); ?></option>
                            <?php foreach ($cycle_options as $cid => $cname) : ?>
                            <option value="<?php echo esc_attr($cid); ?>" <?php selected($filter_cycle_id, $cid); ?>><?php echo esc_html($cname); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hlcr-filter-group">
                        <label class="hlcr-filter-label" for="hlcr-school"><?php esc_html_e('School', 'hl-core'); ?></label>
                        <select name="school" id="hlcr-school" class="hlcr-filter-select">
                            <option value=""><?php esc_html_e('All Schools', 'hl-core'); ?></option>
                            <?php foreach ($school_options as $school) : ?>
                            <option value="<?php echo esc_attr($school); ?>" <?php selected($filter_school, $school); ?>><?php echo esc_html($school); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hlcr-filter-group hlcr-filter-group-btn">
                        <button type="submit" class="hlcr-btn hlcr-btn-filter">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                            <?php esc_html_e('Filter', 'hl-core'); ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Summary stats row -->
            <div class="hlcr-stats-grid">
                <div class="hlcr-stat-card">
                    <div class="hlcr-stat-accent"></div>
                    <div class="hlcr-stat-body">
                        <div class="hlcr-stat-icon hlcr-stat-icon-mentors">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <div class="hlcr-stat-value"><?php echo esc_html($total_mentors); ?></div>
                        <div class="hlcr-stat-label"><?php esc_html_e('Total Mentors', 'hl-core'); ?></div>
                    </div>
                </div>

                <div class="hlcr-stat-card">
                    <div class="hlcr-stat-accent hlcr-stat-accent-completion"></div>
                    <div class="hlcr-stat-body">
                        <div class="hlcr-stat-icon hlcr-stat-icon-completion">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        </div>
                        <div class="hlcr-stat-value"><?php echo esc_html($avg_completion); ?>%</div>
                        <div class="hlcr-stat-label"><?php esc_html_e('Average Completion', 'hl-core'); ?></div>
                    </div>
                </div>

                <div class="hlcr-stat-card">
                    <div class="hlcr-stat-accent hlcr-stat-accent-schools"></div>
                    <div class="hlcr-stat-body">
                        <div class="hlcr-stat-icon hlcr-stat-icon-schools">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        </div>
                        <div class="hlcr-stat-value"><?php echo esc_html($schools_count); ?></div>
                        <div class="hlcr-stat-label"><?php esc_html_e('Schools Covered', 'hl-core'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Completion table -->
            <?php if (!empty($mentors)) : ?>
            <div class="hlcr-section-title"><?php esc_html_e('Mentor Completion', 'hl-core'); ?></div>
            <div class="hlcr-table-wrap">
                <table class="hlcr-table">
                    <thead>
                        <tr>
                            <th class="hlcr-th"><?php esc_html_e('Mentor Name', 'hl-core'); ?></th>
                            <th class="hlcr-th"><?php esc_html_e('School', 'hl-core'); ?></th>
                            <th class="hlcr-th"><?php esc_html_e('Team', 'hl-core'); ?></th>
                            <th class="hlcr-th"><?php esc_html_e('Pathway', 'hl-core'); ?></th>
                            <th class="hlcr-th hlcr-th-pct"><?php esc_html_e('Completion %', 'hl-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mentors as $i => $m) :
                            $pct = floatval($m['completion_pct']);
                            $row_class = ($i % 2 === 0) ? 'hlcr-tr-even' : 'hlcr-tr-odd';
                        ?>
                        <tr class="<?php echo esc_attr($row_class); ?>">
                            <td class="hlcr-td hlcr-td-name"><?php echo esc_html($m['display_name']); ?></td>
                            <td class="hlcr-td"><?php echo esc_html($m['school_name']); ?></td>
                            <td class="hlcr-td"><?php echo esc_html($m['team_name']); ?></td>
                            <td class="hlcr-td"><?php echo esc_html($m['pathway_name']); ?></td>
                            <td class="hlcr-td hlcr-td-pct">
                                <div class="hlcr-pct-cell">
                                    <span class="hlcr-pct-num"><?php echo esc_html($pct); ?>%</span>
                                    <div class="hlcr-progress-track">
                                        <div class="hlcr-progress-fill" style="width:<?php echo esc_attr(min($pct, 100)); ?>%"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else : ?>
            <div class="hlcr-empty">
                <div class="hlcr-empty-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </div>
                <p class="hlcr-empty-text"><?php esc_html_e('No mentor data found for the selected filters.', 'hl-core'); ?></p>
            </div>
            <?php endif; ?>

            <!-- Team comparison -->
            <?php if (!empty($teams)) : ?>
            <div class="hlcr-section-title"><?php esc_html_e('Team Comparison', 'hl-core'); ?></div>
            <div class="hlcr-teams-grid">
                <?php foreach ($teams as $tname => $tdata) :
                    $team_avg    = round($tdata['total_pct'] / $tdata['count'], 1);
                    $member_count = count($tdata['members']);
                ?>
                <div class="hlcr-team-card">
                    <div class="hlcr-team-header">
                        <div class="hlcr-team-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <h4 class="hlcr-team-name"><?php echo esc_html($tname); ?></h4>
                    </div>
                    <div class="hlcr-team-stats">
                        <div class="hlcr-team-stat">
                            <span class="hlcr-team-stat-label"><?php esc_html_e('Avg Completion', 'hl-core'); ?></span>
                            <span class="hlcr-team-stat-value"><?php echo esc_html($team_avg); ?>%</span>
                        </div>
                        <div class="hlcr-team-stat">
                            <span class="hlcr-team-stat-label"><?php esc_html_e('Members', 'hl-core'); ?></span>
                            <span class="hlcr-team-stat-value"><?php echo esc_html($member_count); ?></span>
                        </div>
                    </div>
                    <div class="hlcr-progress-track hlcr-team-progress">
                        <div class="hlcr-progress-fill" style="width:<?php echo esc_attr(min($team_avg, 100)); ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- CSV Export -->
            <?php if (!empty($mentors)) : ?>
            <div class="hlcr-export-bar">
                <form method="post" class="hlcr-export-form--inline">
                    <?php wp_nonce_field('hl_coach_report_export', 'hl_coach_report_export_nonce'); ?>
                    <input type="hidden" name="filter_cycle_id" value="<?php echo esc_attr($filter_cycle_id); ?>">
                    <input type="hidden" name="filter_school" value="<?php echo esc_attr($filter_school); ?>">
                    <button type="submit" class="hlcr-btn hlcr-btn-export">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        <?php esc_html_e('Export CSV', 'hl-core'); ?>
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Handle CSV export via POST.
     */
    public static function handle_export() {
        if (!isset($_POST['hl_coach_report_export_nonce'])) return;
        if (!wp_verify_nonce($_POST['hl_coach_report_export_nonce'], 'hl_coach_report_export')) return;

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();
        if (!in_array('coach', (array) $user->roles, true) && !current_user_can('manage_hl_core')) return;

        $service = new HL_Coach_Dashboard_Service();
        $mentors = $service->get_aggregated_report($user_id, array(
            'cycle_id'    => absint($_POST['filter_cycle_id'] ?? 0),
            'school_name' => sanitize_text_field($_POST['filter_school'] ?? ''),
        ));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=coach-report-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('Mentor', 'School', 'Team', 'Pathway', 'Completion %'));
        foreach ($mentors as $m) {
            fputcsv($out, array($m['display_name'], $m['school_name'], $m['team_name'], $m['pathway_name'], $m['completion_pct'] . '%'));
        }
        fclose($out);
        exit;
    }


    /**
     * Find the published page that contains a given shortcode.
     *
     * @param string $shortcode Shortcode tag (without brackets).
     * @return string Page permalink or empty string.
     */
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
