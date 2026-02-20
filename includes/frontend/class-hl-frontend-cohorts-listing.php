<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_cohorts_listing] shortcode.
 *
 * Card grid of cohorts with search and status filter.
 * Scope: admin sees all, coach sees assigned cohorts, leaders see enrolled cohorts.
 *
 * @package HL_Core
 */
class HL_Frontend_Cohorts_Listing {

    public function render( $atts ) {
        ob_start();

        if ( ! is_user_logged_in() ) {
            echo '<div class="hl-notice hl-notice-warning">'
                . esc_html__( 'Please log in to view this page.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        $scope = HL_Scope_Service::get_scope();

        // Must be staff or have at least one enrollment.
        if ( ! $scope['is_staff'] && empty( $scope['enrollment_ids'] ) ) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'You do not have access to this page.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        $cohort_repo = new HL_Cohort_Repository();
        $all_cohorts = $cohort_repo->get_all();

        // Scope filter.
        $cohorts = HL_Scope_Service::filter_by_ids(
            $all_cohorts,
            'cohort_id',
            $scope['cohort_ids'],
            $scope['is_admin']
        );

        // Pre-compute counts.
        $participant_counts = $this->get_participant_counts();
        $center_counts      = $this->get_center_counts();

        $workspace_url = $this->find_shortcode_page_url( 'hl_cohort_workspace' );

        ?>
        <div class="hl-dashboard hl-cohorts-listing hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Cohorts', 'hl-core' ); ?></h2>
            </div>

            <!-- Search + Status Filters -->
            <div class="hl-filters-bar" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:20px;">
                <input type="text" class="hl-search-input" id="hl-cohort-search"
                       placeholder="<?php esc_attr_e( 'Search cohorts...', 'hl-core' ); ?>"
                       style="flex:1; min-width:200px;">
                <label class="hl-filter-checkbox">
                    <input type="checkbox" class="hl-status-filter" value="active" checked> <?php esc_html_e( 'Active', 'hl-core' ); ?>
                </label>
                <label class="hl-filter-checkbox">
                    <input type="checkbox" class="hl-status-filter" value="future" checked> <?php esc_html_e( 'Future', 'hl-core' ); ?>
                </label>
                <label class="hl-filter-checkbox">
                    <input type="checkbox" class="hl-status-filter" value="paused"> <?php esc_html_e( 'Paused', 'hl-core' ); ?>
                </label>
                <label class="hl-filter-checkbox">
                    <input type="checkbox" class="hl-status-filter" value="archived"> <?php esc_html_e( 'Archived', 'hl-core' ); ?>
                </label>
            </div>

            <?php if ( empty( $cohorts ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No cohorts found.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-crm-card-grid">
                    <?php foreach ( $cohorts as $cohort ) :
                        $cid           = (int) $cohort->cohort_id;
                        $status        = $cohort->status ?: 'active';
                        $num_participants = isset( $participant_counts[ $cid ] ) ? $participant_counts[ $cid ] : 0;
                        $num_centers   = isset( $center_counts[ $cid ] ) ? $center_counts[ $cid ] : 0;
                        $detail_url    = $workspace_url
                            ? add_query_arg( 'id', $cid, $workspace_url )
                            : '';

                        $start = $cohort->start_date ? date_i18n( 'M j, Y', strtotime( $cohort->start_date ) ) : '—';
                        $end   = $cohort->end_date   ? date_i18n( 'M j, Y', strtotime( $cohort->end_date ) )   : '—';
                    ?>
                        <div class="hl-crm-card hl-cohort-card"
                             data-status="<?php echo esc_attr( $status ); ?>"
                             data-name="<?php echo esc_attr( strtolower( $cohort->cohort_name . ' ' . $cohort->cohort_code ) ); ?>">
                            <div class="hl-crm-card-body">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                    <h3 class="hl-crm-card-title" style="margin:0;">
                                        <?php if ( $detail_url ) : ?>
                                            <a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $cohort->cohort_name ); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html( $cohort->cohort_name ); ?>
                                        <?php endif; ?>
                                    </h3>
                                    <span class="hl-badge hl-badge-<?php echo esc_attr( $status ); ?>">
                                        <?php echo esc_html( ucfirst( $status ) ); ?>
                                    </span>
                                </div>
                                <?php if ( $cohort->cohort_code ) : ?>
                                    <div class="hl-crm-card-code" style="color:#6c757d; font-size:13px; margin-bottom:8px;">
                                        <?php echo esc_html( $cohort->cohort_code ); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="hl-crm-card-dates" style="font-size:13px; color:#6c757d; margin-bottom:10px;">
                                    <?php echo esc_html( $start ); ?> &mdash; <?php echo esc_html( $end ); ?>
                                </div>
                                <div class="hl-crm-card-meta">
                                    <span class="hl-crm-card-stat">
                                        <strong><?php echo esc_html( $num_participants ); ?></strong>
                                        <?php echo esc_html( _n( 'Participant', 'Participants', $num_participants, 'hl-core' ) ); ?>
                                    </span>
                                    <span class="hl-crm-card-stat">
                                        <strong><?php echo esc_html( $num_centers ); ?></strong>
                                        <?php echo esc_html( _n( 'Center', 'Centers', $num_centers, 'hl-core' ) ); ?>
                                    </span>
                                </div>
                            </div>
                            <?php if ( $detail_url ) : ?>
                                <div class="hl-crm-card-action">
                                    <a href="<?php echo esc_url( $detail_url ); ?>" class="hl-btn hl-btn-sm hl-btn-secondary">
                                        <?php esc_html_e( 'Open Cohort', 'hl-core' ); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="hl-empty-state hl-no-results" style="display:none;">
                    <p><?php esc_html_e( 'No cohorts match your search or filters.', 'hl-core' ); ?></p>
                </div>
            <?php endif; ?>

        </div>

        <script>
        (function($){
            var $cards = $('.hl-cohort-card');
            var $noResults = $('.hl-cohorts-listing .hl-no-results');

            function filterCards() {
                var query = $('#hl-cohort-search').val().toLowerCase();
                var statuses = [];
                $('.hl-status-filter:checked').each(function(){ statuses.push($(this).val()); });

                var visible = 0;
                $cards.each(function(){
                    var $c = $(this);
                    var matchSearch = !query || $c.data('name').indexOf(query) !== -1;
                    var matchStatus = statuses.length === 0 || statuses.indexOf($c.data('status')) !== -1;
                    var show = matchSearch && matchStatus;
                    $c.toggle(show);
                    if (show) visible++;
                });
                $noResults.toggle(visible === 0 && $cards.length > 0);
            }

            $('#hl-cohort-search').on('input', filterCards);
            $('.hl-status-filter').on('change', filterCards);
            filterCards();
        })(jQuery);
        </script>
        <?php

        return ob_get_clean();
    }

    // =========================================================================
    // Data helpers
    // =========================================================================

    private function get_participant_counts() {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT cohort_id, COUNT(*) AS cnt
             FROM {$wpdb->prefix}hl_enrollment
             WHERE status = 'active'
             GROUP BY cohort_id",
            ARRAY_A
        );
        $map = array();
        foreach ( $results ?: array() as $row ) {
            $map[ (int) $row['cohort_id'] ] = (int) $row['cnt'];
        }
        return $map;
    }

    private function get_center_counts() {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT cohort_id, COUNT(DISTINCT center_id) AS cnt
             FROM {$wpdb->prefix}hl_cohort_center
             GROUP BY cohort_id",
            ARRAY_A
        );
        $map = array();
        foreach ( $results ?: array() as $row ) {
            $map[ (int) $row['cohort_id'] ] = (int) $row['cnt'];
        }
        return $map;
    }

    private function find_shortcode_page_url( $shortcode ) {
        global $wpdb;
        $page_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
             AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like( $shortcode ) . '%'
        ) );
        return $page_id ? get_permalink( $page_id ) : '';
    }
}
