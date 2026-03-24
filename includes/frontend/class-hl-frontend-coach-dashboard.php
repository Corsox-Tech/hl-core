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

        $this->render_styles();
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

            <!-- Stats cards -->
            <div class="hlcd-stats-grid">

                <div class="hlcd-stat-card">
                    <div class="hlcd-stat-accent"></div>
                    <div class="hlcd-stat-body">
                        <div class="hlcd-stat-icon hlcd-stat-icon-mentors">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <div class="hlcd-stat-value"><?php echo esc_html($stats['assigned_mentors']); ?></div>
                        <div class="hlcd-stat-label"><?php esc_html_e('Assigned Mentors', 'hl-core'); ?></div>
                    </div>
                </div>

                <div class="hlcd-stat-card">
                    <div class="hlcd-stat-accent hlcd-stat-accent-sessions"></div>
                    <div class="hlcd-stat-body">
                        <div class="hlcd-stat-icon hlcd-stat-icon-sessions">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <div class="hlcd-stat-value"><?php echo esc_html($stats['upcoming_sessions']); ?></div>
                        <div class="hlcd-stat-label"><?php esc_html_e('Upcoming Sessions', 'hl-core'); ?></div>
                    </div>
                </div>

                <div class="hlcd-stat-card">
                    <div class="hlcd-stat-accent hlcd-stat-accent-month"></div>
                    <div class="hlcd-stat-body">
                        <div class="hlcd-stat-icon hlcd-stat-icon-month">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div class="hlcd-stat-value"><?php echo esc_html($stats['sessions_this_month']); ?></div>
                        <div class="hlcd-stat-label"><?php esc_html_e('Sessions This Month', 'hl-core'); ?></div>
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

            </div>

        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * All CSS for the Coach Dashboard (inline to avoid external CSS dependency).
     */
    private function render_styles() {
        ?>
        <style>
        .hlcd-wrapper{max-width:1100px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}

        /* Hero */
        .hlcd-hero{display:flex;align-items:center;gap:20px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;padding:28px 32px;border-radius:16px;margin-bottom:28px}
        .hlcd-hero-avatar{flex-shrink:0}
        .hlcd-hero-avatar img{width:64px;height:64px;border-radius:50%;border:3px solid rgba(255,255,255,.25);display:block}
        .hlcd-hero-title{font-size:22px;font-weight:700;margin:0;letter-spacing:-.3px}
        .hlcd-hero-sub{font-size:14px;opacity:.75;margin:4px 0 0}

        /* Stats grid */
        .hlcd-stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:36px}
        .hlcd-stat-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;transition:box-shadow .25s ease,transform .25s ease}
        .hlcd-stat-card:hover{box-shadow:0 8px 25px rgba(0,0,0,.08);transform:translateY(-2px)}
        .hlcd-stat-accent{height:4px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%)}
        .hlcd-stat-accent-sessions{background:linear-gradient(135deg,#059669 0%,#34d399 100%)}
        .hlcd-stat-accent-month{background:linear-gradient(135deg,#d97706 0%,#fbbf24 100%)}
        .hlcd-stat-body{padding:24px 20px;text-align:center}
        .hlcd-stat-icon{display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:12px;margin-bottom:16px}
        .hlcd-stat-icon-mentors{background:rgba(30,58,95,.08);color:#1e3a5f}
        .hlcd-stat-icon-sessions{background:rgba(5,150,105,.08);color:#059669}
        .hlcd-stat-icon-month{background:rgba(217,119,6,.08);color:#d97706}
        .hlcd-stat-value{font-size:36px;font-weight:700;color:#1e293b;line-height:1;margin-bottom:6px}
        .hlcd-stat-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:#8896a6}

        /* Section title */
        .hlcd-section-title{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#475569;margin-bottom:16px}

        /* Quick links grid */
        .hlcd-links-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:20px}
        .hlcd-link-card{display:flex;align-items:center;gap:16px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px 24px;text-decoration:none;color:inherit;transition:box-shadow .25s ease,transform .25s ease}
        .hlcd-link-card:hover{box-shadow:0 8px 25px rgba(0,0,0,.08);transform:translateY(-2px);text-decoration:none;color:inherit}
        .hlcd-link-icon{flex-shrink:0;width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center}
        .hlcd-link-icon-mentors{background:rgba(30,58,95,.08);color:#1e3a5f}
        .hlcd-link-icon-reports{background:rgba(5,150,105,.08);color:#059669}
        .hlcd-link-icon-availability{background:rgba(217,119,6,.08);color:#d97706}
        .hlcd-link-text{flex:1;min-width:0}
        .hlcd-link-title{font-size:15px;font-weight:600;color:#1e293b;margin-bottom:4px}
        .hlcd-link-desc{font-size:13px;color:#8896a6;line-height:1.4}
        .hlcd-link-arrow{flex-shrink:0;color:#cbd5e1;transition:color .2s,transform .2s}
        .hlcd-link-card:hover .hlcd-link-arrow{color:#1e3a5f;transform:translateX(2px)}

        /* Responsive */
        @media(max-width:600px){
            .hlcd-hero{flex-direction:column;text-align:center;padding:24px 20px}
            .hlcd-stats-grid{grid-template-columns:1fr}
            .hlcd-links-grid{grid-template-columns:1fr}
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
