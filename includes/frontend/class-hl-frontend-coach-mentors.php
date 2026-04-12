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
                        $cm_coach_tz = get_user_meta(get_current_user_id(), 'hl_timezone', true) ?: wp_timezone_string();
                        $last_fmt = HL_Timezone_Helper::format_session_time($mentor['last_session'] ?? '', $cm_coach_tz, 'M j, Y');
                        $last_session = $last_fmt['date'] ?: null;
                        $next_fmt = HL_Timezone_Helper::format_session_time($mentor['next_session'] ?? '', $cm_coach_tz, 'M j, Y');
                        $next_session = $next_fmt['date'] ?: null;
                        $pct = (int) ($mentor['completion_pct'] ?? 0);
                        $card_url = $detail_url
                            ? esc_url($detail_url . (strpos($detail_url, '?') !== false ? '&' : '?') . 'mentor_enrollment_id=' . (int) $mentor['enrollment_id'])
                            : '';
                    ?>
                        <div class="hlcm-card-wrapper"
                             data-search="<?php echo esc_attr($search_data); ?>"
                             data-school="<?php echo esc_attr($mentor['school_name'] ?: ''); ?>">
                        <?php if ($card_url) : ?>
                            <a href="<?php echo $card_url; ?>" class="hlcm-card">
                        <?php else : ?>
                            <div class="hlcm-card">
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
                        </div><!-- /.hlcm-card-wrapper -->

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
            var $cards = $('.hlcm-card-wrapper');
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
     * Find the published page that contains a given shortcode.
     *
     * @param string $shortcode Shortcode tag (without brackets).
     * @return string Page permalink or empty string.
     */
    private function find_shortcode_page_url( $shortcode ) {
        return HL_Page_Cache::get_url( $shortcode );
    }

    private function get_profile_url($user_id) {
        static $base_url = null;
        if ($base_url === null) {
            $base_url = $this->find_shortcode_page_url('hl_user_profile');
        }
        return $base_url ? add_query_arg('user_id', (int) $user_id, $base_url) : '';
    }
}
