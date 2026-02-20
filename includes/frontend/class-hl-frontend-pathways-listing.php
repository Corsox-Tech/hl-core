<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_pathways_listing] shortcode.
 *
 * Staff-only pathway browser with search, filters, card grid.
 * Scope: admin/coach only.
 *
 * @package HL_Core
 */
class HL_Frontend_Pathways_Listing {

    public function render( $atts ) {
        ob_start();

        $scope = HL_Scope_Service::get_scope();

        if ( ! $scope['is_staff'] ) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'You do not have access to this page.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        $pathways = $this->get_pathways( $scope );
        $cohorts  = $this->get_cohort_options( $scope );

        $program_page_url = $this->find_shortcode_page_url( 'hl_program_page' );

        ?>
        <div class="hl-dashboard hl-pathways-listing hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Pathways', 'hl-core' ); ?></h2>
            </div>

            <div class="hl-filters-bar" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:20px;">
                <input type="text" class="hl-search-input" id="hl-pathway-search"
                       placeholder="<?php esc_attr_e( 'Search pathways...', 'hl-core' ); ?>"
                       style="flex:1; min-width:200px;">
                <?php if ( count( $cohorts ) > 1 ) : ?>
                    <select class="hl-select" id="hl-pathway-cohort-filter">
                        <option value=""><?php esc_html_e( 'All Cohorts', 'hl-core' ); ?></option>
                        <?php foreach ( $cohorts as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['cohort_id'] ); ?>">
                                <?php echo esc_html( $c['cohort_name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <?php if ( empty( $pathways ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No pathways found.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-crm-card-grid">
                    <?php foreach ( $pathways as $pw ) :
                        $pid        = (int) $pw['pathway_id'];
                        $image_url  = '';
                        if ( ! empty( $pw['featured_image_id'] ) ) {
                            $image_url = wp_get_attachment_image_url( $pw['featured_image_id'], 'medium' );
                        }
                        $target_roles = $pw['target_roles'] ? json_decode( $pw['target_roles'], true ) : array();
                        $roles_label  = ! empty( $target_roles )
                            ? implode( ', ', array_map( function( $r ) { return ucfirst( str_replace( '_', ' ', $r ) ); }, $target_roles ) )
                            : __( 'All Roles', 'hl-core' );
                    ?>
                        <div class="hl-crm-card hl-pathway-card"
                             data-name="<?php echo esc_attr( strtolower( $pw['pathway_name'] . ' ' . $pw['cohort_name'] ) ); ?>"
                             data-cohort="<?php echo esc_attr( $pw['cohort_id'] ); ?>">
                            <?php if ( $image_url ) : ?>
                                <div class="hl-crm-card-image" style="height:140px; overflow:hidden; border-radius:8px 8px 0 0;">
                                    <img src="<?php echo esc_url( $image_url ); ?>" alt=""
                                         style="width:100%; height:100%; object-fit:cover;">
                                </div>
                            <?php endif; ?>
                            <div class="hl-crm-card-body">
                                <h3 class="hl-crm-card-title"><?php echo esc_html( $pw['pathway_name'] ); ?></h3>
                                <div style="font-size:13px; color:#6c757d; margin-bottom:6px;">
                                    <?php echo esc_html( $pw['cohort_name'] ); ?>
                                </div>
                                <div class="hl-crm-card-meta">
                                    <span class="hl-crm-card-stat">
                                        <strong><?php echo esc_html( $pw['activity_count'] ); ?></strong>
                                        <?php echo esc_html( _n( 'Activity', 'Activities', (int) $pw['activity_count'], 'hl-core' ) ); ?>
                                    </span>
                                    <span class="hl-crm-card-stat" style="font-size:12px;">
                                        <?php echo esc_html( $roles_label ); ?>
                                    </span>
                                </div>
                                <?php if ( ! empty( $pw['avg_completion_time'] ) ) : ?>
                                    <div style="font-size:12px; color:#6c757d; margin-top:6px;">
                                        <?php
                                        printf(
                                            esc_html__( 'Avg time: %s', 'hl-core' ),
                                            esc_html( $pw['avg_completion_time'] )
                                        );
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="hl-empty-state hl-no-results" style="display:none;">
                    <p><?php esc_html_e( 'No pathways match your filters.', 'hl-core' ); ?></p>
                </div>
            <?php endif; ?>

        </div>

        <script>
        (function($){
            var $cards = $('.hl-pathway-card');
            var $noResults = $('.hl-pathways-listing .hl-no-results');

            function filterCards() {
                var query  = $('#hl-pathway-search').val().toLowerCase();
                var cohort = $('#hl-pathway-cohort-filter').val();
                var visible = 0;

                $cards.each(function(){
                    var $c = $(this);
                    var matchSearch = !query || $c.data('name').indexOf(query) !== -1;
                    var matchCohort = !cohort || String($c.data('cohort')) === cohort;
                    var show = matchSearch && matchCohort;
                    $c.toggle(show);
                    if (show) visible++;
                });
                $noResults.toggle(visible === 0 && $cards.length > 0);
            }

            $('#hl-pathway-search').on('input', filterCards);
            $('#hl-pathway-cohort-filter').on('change', filterCards);
        })(jQuery);
        </script>
        <?php

        return ob_get_clean();
    }

    // =========================================================================
    // Data helpers
    // =========================================================================

    private function get_pathways( $scope ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $sql = "SELECT p.pathway_id, p.pathway_name, p.cohort_id, p.target_roles,
                       p.featured_image_id, p.avg_completion_time,
                       co.cohort_name,
                       COALESCE(ac.activity_count, 0) AS activity_count
                FROM {$prefix}hl_pathway p
                LEFT JOIN {$prefix}hl_cohort co ON p.cohort_id = co.cohort_id
                LEFT JOIN (
                    SELECT pathway_id, COUNT(*) AS activity_count
                    FROM {$prefix}hl_activity
                    GROUP BY pathway_id
                ) ac ON p.pathway_id = ac.pathway_id";

        $where  = array();
        $values = array();

        if ( ! $scope['is_admin'] && ! empty( $scope['cohort_ids'] ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $scope['cohort_ids'] ), '%d' ) );
            $where[]      = "p.cohort_id IN ({$placeholders})";
            $values       = array_merge( $values, $scope['cohort_ids'] );
        }

        if ( ! empty( $where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        $sql .= ' ORDER BY co.cohort_name ASC, p.pathway_name ASC';

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, $values );
        }

        return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
    }

    private function get_cohort_options( $scope ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        if ( $scope['is_admin'] ) {
            return $wpdb->get_results(
                "SELECT cohort_id, cohort_name FROM {$prefix}hl_cohort ORDER BY cohort_name ASC",
                ARRAY_A
            ) ?: array();
        }
        if ( empty( $scope['cohort_ids'] ) ) return array();
        $in = implode( ',', $scope['cohort_ids'] );
        return $wpdb->get_results(
            "SELECT cohort_id, cohort_name FROM {$prefix}hl_cohort WHERE cohort_id IN ({$in}) ORDER BY cohort_name ASC",
            ARRAY_A
        ) ?: array();
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
