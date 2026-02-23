<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_institutions_listing] shortcode.
 *
 * Combined districts + centers view with toggle, search, scope filtering.
 *
 * @package HL_Core
 */
class HL_Frontend_Institutions_Listing {

    /** @var HL_OrgUnit_Repository */
    private $orgunit_repo;

    public function __construct() {
        $this->orgunit_repo = new HL_OrgUnit_Repository();
    }

    public function render( $atts ) {
        ob_start();

        if ( ! is_user_logged_in() ) {
            echo '<div class="hl-notice hl-notice-warning">'
                . esc_html__( 'Please log in to view this page.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        $scope = HL_Scope_Service::get_scope();

        // Must be staff or have an enrollment with leader role.
        if ( ! $scope['is_staff'] && empty( $scope['center_ids'] ) && empty( $scope['district_ids'] ) ) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'You do not have access to this page.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        $districts    = $this->orgunit_repo->get_districts();
        $all_centers  = $this->orgunit_repo->get_centers();

        // Scope filter.
        $districts = HL_Scope_Service::filter_by_ids( $districts, 'orgunit_id', $scope['district_ids'], $scope['is_admin'] );
        $centers   = HL_Scope_Service::filter_by_ids( $all_centers, 'orgunit_id', $scope['center_ids'], $scope['is_admin'] );

        // Pre-compute stats.
        $center_counts = $this->get_center_counts_by_district();
        $cohort_counts = $this->get_active_cohort_counts_by_district();
        $leaders       = $this->get_center_leaders();
        $district_map  = $this->build_district_map( $districts );

        $district_page_url = $this->find_shortcode_page_url( 'hl_district_page' );
        $center_page_url   = $this->find_shortcode_page_url( 'hl_center_page' );

        ?>
        <div class="hl-dashboard hl-institutions-listing hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Institutions', 'hl-core' ); ?></h2>
            </div>

            <div class="hl-filters-bar">
                <input type="text" class="hl-search-input" id="hl-inst-search"
                       placeholder="<?php esc_attr_e( 'Search institutions...', 'hl-core' ); ?>">
                <div class="hl-toggle-group">
                    <button class="hl-tab hl-inst-toggle active" data-view="all"><?php esc_html_e( 'All', 'hl-core' ); ?></button>
                    <button class="hl-tab hl-inst-toggle" data-view="districts"><?php esc_html_e( 'Districts', 'hl-core' ); ?></button>
                    <button class="hl-tab hl-inst-toggle" data-view="centers"><?php esc_html_e( 'Centers', 'hl-core' ); ?></button>
                </div>
            </div>

            <?php if ( empty( $districts ) && empty( $centers ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No institutions found.', 'hl-core' ); ?></p></div>
            <?php else : ?>

                <!-- Districts Section -->
                <?php if ( ! empty( $districts ) ) : ?>
                    <div class="hl-inst-section hl-inst-districts" data-type="districts">
                        <h3 class="hl-section-title">
                            <?php esc_html_e( 'School Districts', 'hl-core' ); ?>
                            <span class="hl-section-count">(<?php echo count( $districts ); ?>)</span>
                        </h3>
                        <div class="hl-crm-card-grid">
                            <?php foreach ( $districts as $district ) :
                                $did         = (int) $district->orgunit_id;
                                $num_centers = isset( $center_counts[ $did ] ) ? $center_counts[ $did ] : 0;
                                $num_cohorts = isset( $cohort_counts[ $did ] ) ? $cohort_counts[ $did ] : 0;
                                $detail_url  = $district_page_url ? add_query_arg( 'id', $did, $district_page_url ) : '';
                            ?>
                                <div class="hl-crm-card hl-inst-card"
                                     data-name="<?php echo esc_attr( strtolower( $district->name ) ); ?>"
                                     data-type="district">
                                    <div class="hl-crm-card-body">
                                        <h3 class="hl-crm-card-title">
                                            <?php if ( $detail_url ) : ?>
                                                <a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $district->name ); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html( $district->name ); ?>
                                            <?php endif; ?>
                                        </h3>
                                        <div class="hl-crm-card-meta">
                                            <span class="hl-crm-card-stat">
                                                <strong><?php echo esc_html( $num_centers ); ?></strong>
                                                <?php echo esc_html( _n( 'Center', 'Centers', $num_centers, 'hl-core' ) ); ?>
                                            </span>
                                            <span class="hl-crm-card-stat">
                                                <strong><?php echo esc_html( $num_cohorts ); ?></strong>
                                                <?php echo esc_html( _n( 'Active Cohort', 'Active Cohorts', $num_cohorts, 'hl-core' ) ); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if ( $detail_url ) : ?>
                                        <div class="hl-crm-card-action">
                                            <a href="<?php echo esc_url( $detail_url ); ?>" class="hl-btn hl-btn-sm hl-btn-secondary">
                                                <?php esc_html_e( 'View District', 'hl-core' ); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Centers Section -->
                <?php if ( ! empty( $centers ) ) : ?>
                    <div class="hl-inst-section hl-inst-centers" data-type="centers">
                        <h3 class="hl-section-title">
                            <?php esc_html_e( 'Centers', 'hl-core' ); ?>
                            <span class="hl-section-count">(<?php echo count( $centers ); ?>)</span>
                        </h3>
                        <div class="hl-crm-card-grid">
                            <?php foreach ( $centers as $center ) :
                                $cid         = (int) $center->orgunit_id;
                                $parent_name = isset( $district_map[ (int) $center->parent_orgunit_id ] )
                                    ? $district_map[ (int) $center->parent_orgunit_id ]
                                    : '';
                                $leader_list = isset( $leaders[ $cid ] ) ? $leaders[ $cid ] : array();
                                $detail_url  = $center_page_url ? add_query_arg( 'id', $cid, $center_page_url ) : '';
                            ?>
                                <div class="hl-crm-card hl-inst-card"
                                     data-name="<?php echo esc_attr( strtolower( $center->name . ' ' . $parent_name ) ); ?>"
                                     data-type="center">
                                    <div class="hl-crm-card-body">
                                        <h3 class="hl-crm-card-title">
                                            <?php if ( $detail_url ) : ?>
                                                <a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $center->name ); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html( $center->name ); ?>
                                            <?php endif; ?>
                                        </h3>
                                        <?php if ( $parent_name ) : ?>
                                            <div class="hl-crm-card-subtitle">
                                                <?php echo esc_html( $parent_name ); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $leader_list ) ) : ?>
                                            <div class="hl-crm-card-meta">
                                                <span class="hl-crm-card-stat">
                                                    <?php echo esc_html( implode( ', ', $leader_list ) ); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $detail_url ) : ?>
                                        <div class="hl-crm-card-action">
                                            <a href="<?php echo esc_url( $detail_url ); ?>" class="hl-btn hl-btn-sm hl-btn-secondary">
                                                <?php esc_html_e( 'View Center', 'hl-core' ); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="hl-empty-state hl-no-results">
                    <p><?php esc_html_e( 'No institutions match your search.', 'hl-core' ); ?></p>
                </div>

            <?php endif; ?>

        </div>

        <script>
        (function($){
            var $cards = $('.hl-inst-card');
            var $sections = $('.hl-inst-section');
            var $noResults = $('.hl-institutions-listing .hl-no-results');
            var currentView = 'all';

            $('.hl-inst-toggle').on('click', function(){
                $('.hl-inst-toggle').removeClass('active');
                $(this).addClass('active');
                currentView = $(this).data('view');
                filterAll();
            });

            function filterAll() {
                var query = $('#hl-inst-search').val().toLowerCase();
                var visible = 0;

                $sections.each(function(){
                    var $sec = $(this);
                    var secType = $sec.data('type');
                    var showSection = currentView === 'all' || currentView === secType;
                    $sec.toggle(showSection);

                    if (showSection) {
                        $sec.find('.hl-inst-card').each(function(){
                            var $c = $(this);
                            var matchSearch = !query || $c.data('name').indexOf(query) !== -1;
                            $c.toggle(matchSearch);
                            if (matchSearch) visible++;
                        });
                    }
                });

                $noResults.toggle(visible === 0 && $cards.length > 0);
            }

            $('#hl-inst-search').on('input', filterAll);
            filterAll();
        })(jQuery);
        </script>
        <?php

        return ob_get_clean();
    }

    // =========================================================================
    // Data helpers
    // =========================================================================

    private function get_center_counts_by_district() {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT parent_orgunit_id, COUNT(*) AS cnt
             FROM {$wpdb->prefix}hl_orgunit
             WHERE orgunit_type = 'center' AND parent_orgunit_id IS NOT NULL
             GROUP BY parent_orgunit_id",
            ARRAY_A
        );
        $map = array();
        foreach ( $results ?: array() as $row ) {
            $map[ (int) $row['parent_orgunit_id'] ] = (int) $row['cnt'];
        }
        return $map;
    }

    private function get_active_cohort_counts_by_district() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $results = $wpdb->get_results(
            "SELECT ou.parent_orgunit_id AS district_id,
                    COUNT(DISTINCT cc.cohort_id) AS cnt
             FROM {$prefix}hl_cohort_center cc
             INNER JOIN {$prefix}hl_orgunit ou ON cc.center_id = ou.orgunit_id
             INNER JOIN {$prefix}hl_cohort c ON cc.cohort_id = c.cohort_id
             WHERE ou.parent_orgunit_id IS NOT NULL AND c.status = 'active'
             GROUP BY ou.parent_orgunit_id",
            ARRAY_A
        );
        $map = array();
        foreach ( $results ?: array() as $row ) {
            $map[ (int) $row['district_id'] ] = (int) $row['cnt'];
        }
        return $map;
    }

    private function get_center_leaders() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT e.center_id, u.display_name
             FROM {$prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.status = 'active' AND e.roles LIKE '%center_leader%'
             ORDER BY u.display_name ASC",
            ARRAY_A
        );
        $map = array();
        foreach ( $rows ?: array() as $row ) {
            $cid = (int) $row['center_id'];
            if ( ! isset( $map[ $cid ] ) ) {
                $map[ $cid ] = array();
            }
            $map[ $cid ][] = $row['display_name'];
        }
        return $map;
    }

    private function build_district_map( $districts ) {
        $map = array();
        foreach ( $districts as $d ) {
            $map[ (int) $d->orgunit_id ] = $d->name;
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
