<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_coach_dashboard] shortcode.
 *
 * Front-end Coach Dashboard with hero header, stats cards, and quick links.
 *
 * @package HL_Core
 */
class HL_Frontend_Coach_Dashboard {

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

        $service = new HL_Coach_Dashboard_Service();
        $stats   = $service->get_dashboard_stats($user_id);

        $first_name = $user->first_name ?: $user->display_name;

        // Resolve quick-link URLs.
        $mentors_url      = $this->find_shortcode_page_url('hl_coach_mentors');
        $reports_url      = $this->find_shortcode_page_url('hl_coach_reports');
        $availability_url = $this->find_shortcode_page_url('hl_coach_availability');
        $profile_url      = $this->find_shortcode_page_url('hl_user_profile');
        $component_page_url = $this->find_shortcode_page_url('hl_component_page');
        $mentor_detail_url  = $this->find_shortcode_page_url('hl_coach_mentor_detail');

        // Load upcoming + needs-attention sessions.
        $sessions = $this->get_coach_sessions($user_id);

        ?>
        <div class="hlcd-wrapper">

            <!-- Hero header -->
            <div class="hlcd-hero">
                <div class="hlcd-hero-avatar">
                    <?php echo get_avatar($user_id, 64); ?>
                </div>
                <div class="hlcd-hero-text">
                    <h2 class="hlcd-hero-title"><?php echo esc_html(sprintf(__('Welcome back, %s', 'hl-core'), $first_name)); ?></h2>
                    <p class="hlcd-hero-sub"><?php esc_html_e('Coach Dashboard', 'hl-core'); ?></p>
                </div>
            </div>

            <!-- Stats cards (horizontal layout) -->
            <div class="hlcd-stats-grid">

                <div class="hlcd-stat-card">
                    <div class="hlcd-stat-accent"></div>
                    <div class="hlcd-stat-body">
                        <div class="hlcd-stat-icon hlcd-stat-icon-mentors">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <div class="hlcd-stat-content">
                            <div class="hlcd-stat-value"><?php echo esc_html($stats['assigned_mentors']); ?></div>
                            <div class="hlcd-stat-label"><?php esc_html_e('Assigned Mentors', 'hl-core'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="hlcd-stat-card">
                    <div class="hlcd-stat-accent hlcd-stat-accent-sessions"></div>
                    <div class="hlcd-stat-body">
                        <div class="hlcd-stat-icon hlcd-stat-icon-sessions">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <div class="hlcd-stat-content">
                            <div class="hlcd-stat-value"><?php echo esc_html($stats['upcoming_sessions']); ?></div>
                            <div class="hlcd-stat-label"><?php esc_html_e('Upcoming Sessions', 'hl-core'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="hlcd-stat-card">
                    <div class="hlcd-stat-accent hlcd-stat-accent-month"></div>
                    <div class="hlcd-stat-body">
                        <div class="hlcd-stat-icon hlcd-stat-icon-month">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div class="hlcd-stat-content">
                            <div class="hlcd-stat-value"><?php echo esc_html($stats['sessions_this_month']); ?></div>
                            <div class="hlcd-stat-label"><?php esc_html_e('Sessions This Month', 'hl-core'); ?></div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Quick links -->
            <div class="hlcd-section-title"><?php esc_html_e('Quick Links', 'hl-core'); ?></div>
            <div class="hlcd-links-grid">

                <?php if ($mentors_url) : ?>
                <a href="<?php echo esc_url($mentors_url); ?>" class="hlcd-link-card">
                    <div class="hlcd-link-icon hlcd-link-icon-mentors">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="hlcd-link-text">
                        <div class="hlcd-link-title"><?php esc_html_e('My Mentors', 'hl-core'); ?></div>
                        <div class="hlcd-link-desc"><?php esc_html_e('View and manage your assigned mentors', 'hl-core'); ?></div>
                    </div>
                    <div class="hlcd-link-arrow">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($reports_url) : ?>
                <a href="<?php echo esc_url($reports_url); ?>" class="hlcd-link-card">
                    <div class="hlcd-link-icon hlcd-link-icon-reports">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    </div>
                    <div class="hlcd-link-text">
                        <div class="hlcd-link-title"><?php esc_html_e('Coach Reports', 'hl-core'); ?></div>
                        <div class="hlcd-link-desc"><?php esc_html_e('Aggregated completion data and exports', 'hl-core'); ?></div>
                    </div>
                    <div class="hlcd-link-arrow">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($availability_url) : ?>
                <a href="<?php echo esc_url($availability_url); ?>" class="hlcd-link-card">
                    <div class="hlcd-link-icon hlcd-link-icon-availability">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/></svg>
                    </div>
                    <div class="hlcd-link-text">
                        <div class="hlcd-link-title"><?php esc_html_e('My Availability', 'hl-core'); ?></div>
                        <div class="hlcd-link-desc"><?php esc_html_e('Set your weekly coaching schedule', 'hl-core'); ?></div>
                    </div>
                    <div class="hlcd-link-arrow">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($profile_url) : ?>
                <a href="<?php echo esc_url($profile_url); ?>" class="hlcd-link-card">
                    <div class="hlcd-link-icon hlcd-link-icon-profile">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div class="hlcd-link-text">
                        <div class="hlcd-link-title"><?php esc_html_e('My Profile', 'hl-core'); ?></div>
                        <div class="hlcd-link-desc"><?php esc_html_e('View your profile and account info', 'hl-core'); ?></div>
                    </div>
                    <div class="hlcd-link-arrow">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </div>
                </a>
                <?php endif; ?>

            </div>

            <!-- Upcoming Sessions -->
            <?php
            $needs_attendance = array_filter($sessions, function ($s) {
                return $s['needs_attendance'];
            });
            $upcoming = array_filter($sessions, function ($s) {
                return !$s['needs_attendance'] && $s['session_status'] === 'scheduled';
            });
            ?>

            <?php if (!empty($needs_attendance)) : ?>
            <div class="hlcd-section-title hlcd-section-title--attention">
                <svg class="hlcd-inline-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <?php esc_html_e('Needs Attendance', 'hl-core'); ?>
            </div>
            <div class="hlcd-sessions-list">
                <?php foreach ($needs_attendance as $s) : ?>
                    <?php $this->render_session_row($s, $component_page_url, $mentor_detail_url, true); ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($upcoming)) : ?>
            <div class="hlcd-section-title">
                <svg class="hlcd-inline-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <?php esc_html_e('Upcoming Sessions', 'hl-core'); ?>
            </div>
            <div class="hlcd-sessions-list">
                <?php foreach (array_slice($upcoming, 0, 10) as $s) : ?>
                    <?php $this->render_session_row($s, $component_page_url, $mentor_detail_url, false); ?>
                <?php endforeach; ?>
            </div>
            <?php elseif (empty($needs_attendance)) : ?>
            <div class="hlcd-section-title">
                <?php esc_html_e('Upcoming Sessions', 'hl-core'); ?>
            </div>
            <div class="hlcd-empty-state">
                <p><?php esc_html_e('No upcoming coaching sessions.', 'hl-core'); ?></p>
            </div>
            <?php endif; ?>

        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render a single session row in the dashboard.
     */
    private function render_session_row($s, $component_page_url, $mentor_detail_url, $needs_attendance) {
        $coach_tz = !empty($s['coach_timezone'])
            ? $s['coach_timezone']
            : (get_user_meta(get_current_user_id(), 'hl_timezone', true) ?: wp_timezone_string());
        $fmt  = HL_Timezone_Helper::format_session_time($s['session_datetime'] ?? '', $coach_tz, 'D, M j');
        $date = $fmt['date'];
        $time = $fmt['time'];
        $mentor_name = $s['mentor_name'] ?? '';

        $view_url = '';
        if ($component_page_url && !empty($s['component_id'])) {
            $view_url = add_query_arg(array(
                'id'         => $s['component_id'],
                'enrollment' => $s['mentor_enrollment_id'],
            ), $component_page_url);
        }

        $mentor_url = '';
        if ($mentor_detail_url) {
            $mentor_url = add_query_arg('mentor_enrollment_id', $s['mentor_enrollment_id'], $mentor_detail_url);
        }
        ?>
        <div class="hlcd-session-row <?php echo $needs_attendance ? 'hlcd-session-attention' : ''; ?>">
            <div class="hlcd-session-date-col">
                <div class="hlcd-session-day"><?php echo esc_html($date); ?></div>
                <div class="hlcd-session-time"><?php echo esc_html($time); ?></div>
            </div>
            <div class="hlcd-session-info-col">
                <?php if ($mentor_url) : ?>
                    <a href="<?php echo esc_url($mentor_url); ?>" class="hlcd-session-mentor"><?php echo esc_html($mentor_name); ?></a>
                <?php else : ?>
                    <span class="hlcd-session-mentor"><?php echo esc_html($mentor_name); ?></span>
                <?php endif; ?>
                <span class="hlcd-session-title-text"><?php echo esc_html($s['session_title'] ?? ''); ?></span>
            </div>
            <div class="hlcd-session-action-col">
                <?php if ($needs_attendance) : ?>
                    <span class="hlcd-att-badge"><?php esc_html_e('Record attendance', 'hl-core'); ?></span>
                <?php endif; ?>
                <?php if ($view_url) : ?>
                    <a href="<?php echo esc_url($view_url); ?>" class="hlcd-session-view-btn"><?php esc_html_e('Open', 'hl-core'); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Get all coaching sessions for this coach: upcoming + needs attendance.
     */
    private function get_coach_sessions($coach_user_id) {
        global $wpdb;
        $now      = current_time('mysql');
        $settings = HL_Admin_Scheduling_Settings::get_scheduling_settings();
        $duration = (int) $settings['session_duration'];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cs.session_id, cs.session_title, cs.session_status, cs.session_datetime,
                    cs.component_id, cs.mentor_enrollment_id, cs.meeting_url,
                    cs.coach_timezone,
                    u.display_name AS mentor_name
             FROM {$wpdb->prefix}hl_coaching_session cs
             JOIN {$wpdb->prefix}hl_enrollment e ON cs.mentor_enrollment_id = e.enrollment_id
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE cs.coach_user_id = %d
               AND cs.session_status = 'scheduled'
             ORDER BY cs.session_datetime ASC",
            $coach_user_id
        ), ARRAY_A) ?: array();

        $wp_tz = wp_timezone();
        $now_dt = new DateTime('now', $wp_tz);
        foreach ($rows as &$row) {
            $is_past = false;
            if (!empty($row['session_datetime'])) {
                $session_end = new DateTime($row['session_datetime'], $wp_tz);
                $session_end->modify('+' . $duration . ' minutes');
                $is_past = ($now_dt > $session_end);
            }
            $row['needs_attendance'] = $is_past;
        }
        unset($row);

        return $rows;
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
