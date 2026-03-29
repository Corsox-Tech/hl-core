<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_coach_mentors] shortcode.
 *
 * Front-end "My Mentors" page with card grid, search, and school filter.
 *
 * @package HL_Core
 */
class HL_Frontend_Coach_Mentors {

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
        $mentors    = $service->get_mentors_for_coach($user_id);
        $schools    = $service->get_coach_school_options($user_id);
        $detail_url = $this->find_shortcode_page_url('hl_coach_mentor_detail');
        $count      = count($mentors);

        $this->render_styles();
        ?>
        <div class="hlcm-wrapper">

            <!-- Hero header -->
            <div class="hlcm-hero">
                <div class="hlcm-hero-icon">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div class="hlcm-hero-text">
                    <h2 class="hlcm-hero-title"><?php esc_html_e('My Mentors', 'hl-core'); ?></h2>
                    <p class="hlcm-hero-sub">
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %d: number of mentors */
                            _n('%d mentor assigned', '%d mentors assigned', $count, 'hl-core'),
                            $count
                        ));
                        ?>
                    </p>
                </div>
            </div>

            <?php if (!empty($mentors)) : ?>

                <!-- Filter bar -->
                <div class="hlcm-filters">
                    <input type="text" id="hlcm-search" class="hlcm-search-input"
                           placeholder="<?php esc_attr_e('Search by name, school, or team...', 'hl-core'); ?>">
                    <select id="hlcm-school-filter" class="hlcm-select">
                        <option value=""><?php esc_html_e('All Schools', 'hl-core'); ?></option>
                        <?php foreach ($schools as $school) : ?>
                            <option value="<?php echo esc_attr($school); ?>"><?php echo esc_html($school); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Mentor card grid -->
                <div class="hlcm-grid">
                    <?php foreach ($mentors as $mentor) :
                        $search_data = strtolower(
                            ($mentor['display_name'] ?: '') . ' ' .
                            ($mentor['school_name'] ?: '') . ' ' .
                            ($mentor['team_name'] ?: '')
                        );
                        $last_session = !empty($mentor['last_session'])
                            ? date_i18n('M j, Y', strtotime($mentor['last_session']))
                            : null;
                        $next_session = !empty($mentor['next_session'])
                            ? date_i18n('M j, Y', strtotime($mentor['next_session']))
                            : null;
                        $pct = (int) ($mentor['completion_pct'] ?? 0);
                        $card_url = $detail_url
                            ? esc_url($detail_url . (strpos($detail_url, '?') !== false ? '&' : '?') . 'mentor_enrollment_id=' . (int) $mentor['enrollment_id'])
                            : '';
                    ?>
                        <?php if ($card_url) : ?>
                            <a href="<?php echo $card_url; ?>"
                               class="hlcm-card"
                               data-search="<?php echo esc_attr($search_data); ?>"
                               data-school="<?php echo esc_attr($mentor['school_name'] ?: ''); ?>">
                        <?php else : ?>
                            <div class="hlcm-card"
                                 data-search="<?php echo esc_attr($search_data); ?>"
                                 data-school="<?php echo esc_attr($mentor['school_name'] ?: ''); ?>">
                        <?php endif; ?>

                            <!-- Card top: avatar + identity -->
                            <div class="hlcm-card-top">
                                <div class="hlcm-card-avatar">
                                    <?php echo get_avatar($mentor['user_id'], 48); ?>
                                </div>
                                <div class="hlcm-card-identity">
                                    <div class="hlcm-card-name"><?php echo esc_html($mentor['display_name']); ?></div>
                                    <div class="hlcm-card-school"><?php echo esc_html($mentor['school_name'] ?: __('No school', 'hl-core')); ?></div>
                                </div>
                            </div>

                            <!-- Card middle: badges -->
                            <div class="hlcm-card-badges">
                                <?php if (!empty($mentor['team_name'])) : ?>
                                    <span class="hlcm-badge hlcm-badge-team"><?php echo esc_html($mentor['team_name']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($mentor['pathway_name'])) : ?>
                                    <span class="hlcm-badge hlcm-badge-pathway"><?php echo esc_html($mentor['pathway_name']); ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Progress bar -->
                            <div class="hlcm-progress-row">
                                <div class="hlcm-progress-track">
                                    <div class="hlcm-progress-fill" style="width:<?php echo esc_attr($pct); ?>%"></div>
                                </div>
                                <span class="hlcm-progress-pct"><?php echo esc_html($pct); ?>%</span>
                            </div>

                            <!-- Card bottom: session meta -->
                            <div class="hlcm-card-meta">
                                <div class="hlcm-meta-item">
                                    <span class="hlcm-meta-label"><?php esc_html_e('Last Session', 'hl-core'); ?></span>
                                    <span class="hlcm-meta-value"><?php echo esc_html($last_session ?: __('No sessions yet', 'hl-core')); ?></span>
                                </div>
                                <div class="hlcm-meta-item">
                                    <span class="hlcm-meta-label"><?php esc_html_e('Next', 'hl-core'); ?></span>
                                    <span class="hlcm-meta-value"><?php echo esc_html($next_session ?: __('None scheduled', 'hl-core')); ?></span>
                                </div>
                            </div>

                        <?php if ($card_url) : ?>
                            </a>
                        <?php else : ?>
                            </div>
                        <?php endif; ?>
                        <?php
                        $profile_url = $this->get_profile_url($mentor['user_id']);
                        if ($profile_url) :
                        ?>
                            <a href="<?php echo esc_url($profile_url); ?>" class="hlcm-profile-link">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <?php esc_html_e('View Profile', 'hl-core'); ?>
                            </a>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </div>

                <!-- No results (hidden by default, shown by JS filter) -->
                <div class="hlcm-no-results" style="display:none;">
                    <p><?php esc_html_e('No mentors match your search.', 'hl-core'); ?></p>
                </div>

            <?php else : ?>

                <!-- Empty state -->
                <div class="hlcm-empty">
                    <div class="hlcm-empty-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <p class="hlcm-empty-text"><?php esc_html_e('No mentors assigned to you yet.', 'hl-core'); ?></p>
                </div>

            <?php endif; ?>

        </div>

        <script>
        (function($){
            var $cards = $('.hlcm-card');
            var $noResults = $('.hlcm-no-results');

            function filterCards() {
                var query  = $('#hlcm-search').val().toLowerCase();
                var school = $('#hlcm-school-filter').val();
                var visible = 0;

                $cards.each(function(){
                    var $c = $(this);
                    var matchSearch = !query || $c.data('search').indexOf(query) !== -1;
                    var matchSchool = !school || $c.data('school') === school;
                    var show = matchSearch && matchSchool;
                    $c.toggle(show);
                    if (show) visible++;
                });
                $noResults.toggle(visible === 0 && $cards.length > 0);
            }

            $('#hlcm-search').on('input', filterCards);
            $('#hlcm-school-filter').on('change', filterCards);
        })(jQuery);
        </script>
        <?php

        return ob_get_clean();
    }

    /**
     * All CSS for the My Mentors page (inline to avoid external CSS dependency).
     */
    private function render_styles() {
        ?>
        <style>
        .hlcm-wrapper{max-width:1100px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}

        /* Hero */
        .hlcm-hero{display:flex;align-items:center;gap:20px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;padding:28px 32px;border-radius:16px;margin-bottom:28px}
        .hlcm-hero-icon{flex-shrink:0;display:flex;align-items:center;justify-content:center;width:60px;height:60px;background:rgba(255,255,255,.12);border-radius:14px}
        .hlcm-hero-title{font-size:22px;font-weight:700;margin:0;letter-spacing:-.3px}
        .hlcm-hero-sub{font-size:14px;opacity:.75;margin:4px 0 0}

        /* Filters */
        .hlcm-filters{display:flex;gap:12px;margin-bottom:24px}
        .hlcm-search-input{flex:1;min-width:0;padding:10px 16px;font-size:14px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#1e293b;outline:none;transition:border-color .2s,box-shadow .2s}
        .hlcm-search-input:focus{border-color:#2d5f8a;box-shadow:0 0 0 3px rgba(45,95,138,.12)}
        .hlcm-search-input::placeholder{color:#94a3b8}
        .hlcm-select{padding:10px 16px;font-size:14px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#1e293b;outline:none;cursor:pointer;min-width:180px;transition:border-color .2s,box-shadow .2s}
        .hlcm-select:focus{border-color:#2d5f8a;box-shadow:0 0 0 3px rgba(45,95,138,.12)}

        /* Card grid */
        .hlcm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px}

        /* Card */
        .hlcm-card{display:block;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;text-decoration:none;color:inherit;transition:box-shadow .25s ease,transform .25s ease}
        .hlcm-card:hover{box-shadow:0 8px 25px rgba(0,0,0,.08);transform:translateY(-2px);text-decoration:none;color:inherit}
        a.hlcm-card{cursor:pointer}

        /* Card top: avatar + identity */
        .hlcm-card-top{display:flex;align-items:center;gap:14px;margin-bottom:16px}
        .hlcm-card-avatar{flex-shrink:0}
        .hlcm-card-avatar img{width:48px;height:48px;border-radius:50%;border:2px solid #e2e8f0;display:block}
        .hlcm-card-name{font-size:15px;font-weight:600;color:#1e293b;line-height:1.3}
        .hlcm-card-school{font-size:13px;color:#8896a6;margin-top:2px}

        /* Badges */
        .hlcm-card-badges{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px}
        .hlcm-badge{display:inline-block;padding:4px 12px;font-size:12px;font-weight:600;border-radius:20px;line-height:1.4}
        .hlcm-badge-team{background:rgba(30,58,95,.08);color:#1e3a5f}
        .hlcm-badge-pathway{background:rgba(5,150,105,.08);color:#059669}

        /* Progress bar */
        .hlcm-progress-row{display:flex;align-items:center;gap:12px;margin-bottom:16px}
        .hlcm-progress-track{flex:1;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden}
        .hlcm-progress-fill{height:100%;background:linear-gradient(90deg,#059669,#10b981);border-radius:4px;transition:width .4s ease}
        .hlcm-progress-pct{flex-shrink:0;font-size:13px;font-weight:700;color:#1e293b;min-width:36px;text-align:right}

        /* Card meta */
        .hlcm-card-meta{display:flex;gap:20px;padding-top:14px;border-top:1px solid #f1f5f9}
        .hlcm-meta-item{flex:1;min-width:0}
        .hlcm-meta-label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:#8896a6;margin-bottom:4px}
        .hlcm-meta-value{display:block;font-size:15px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

        /* No results */
        .hlcm-no-results{text-align:center;padding:40px 20px;color:#8896a6;font-size:15px}

        /* Empty state */
        .hlcm-empty{text-align:center;padding:60px 20px;background:#fff;border:1px solid #e2e8f0;border-radius:14px}
        .hlcm-empty-icon{display:inline-flex;align-items:center;justify-content:center;width:80px;height:80px;border-radius:50%;background:rgba(30,58,95,.06);color:#8896a6;margin-bottom:16px}
        .hlcm-empty-text{font-size:16px;color:#64748b;margin:0}

        /* Responsive */
        @media(max-width:600px){
            .hlcm-hero{flex-direction:column;text-align:center;padding:24px 20px}
            .hlcm-filters{flex-direction:column}
            .hlcm-select{min-width:0;width:100%}
            .hlcm-grid{grid-template-columns:1fr}
            .hlcm-card-meta{flex-direction:column;gap:10px}
        }
        .hlcm-profile-link{display:flex;align-items:center;justify-content:center;gap:5px;padding:6px 0;font-size:12px;font-weight:600;color:#2C7BE5;text-decoration:none;transition:color .2s}
        .hlcm-profile-link:hover{color:#1a6ad4;text-decoration:underline}
        .hlcm-profile-link svg{opacity:.6}
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

    private function get_profile_url($user_id) {
        static $base_url = null;
        if ($base_url === null) {
            $base_url = $this->find_shortcode_page_url('hl_user_profile');
        }
        return $base_url ? add_query_arg('user_id', (int) $user_id, $base_url) : '';
    }
}
