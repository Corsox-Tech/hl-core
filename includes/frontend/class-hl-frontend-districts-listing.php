<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_districts_listing] shortcode.
 *
 * CRM-style directory — card grid of all school districts.
 * Each card shows: district name, # schools, # active tracks.
 *
 * Access: Housman Admin, Coach (manage_hl_core).
 *
 * @package HL_Core
 */
class HL_Frontend_Districts_Listing {

    /** @var HL_OrgUnit_Repository */
    private $orgunit_repo;

    public function __construct() {
        $this->orgunit_repo = new HL_OrgUnit_Repository();
    }

    // ========================================================================
    // Render
    // ========================================================================

    public function render( $atts ) {
        ob_start();

        // Staff-only access.
        if ( ! HL_Security::can_manage() ) {
            echo '<div class="hl-dashboard hl-districts-listing hl-frontend-wrap">';
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'You do not have access to this page.', 'hl-core' )
                . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $districts = $this->orgunit_repo->get_districts();

        // Pre-compute counts.
        $school_counts = $this->get_school_counts();
        $cycle_counts = $this->get_active_cycle_counts();

        $district_page_url = $this->find_shortcode_page_url( 'hl_district_page' );

        ?>
        <div class="hl-dashboard hl-districts-listing hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'School Districts', 'hl-core' ); ?></h2>
            </div>

            <?php if ( empty( $districts ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No districts found.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-crm-card-grid">
                    <?php foreach ( $districts as $district ) :
                        $did          = $district->orgunit_id;
                        $num_schools  = isset( $school_counts[ $did ] ) ? $school_counts[ $did ] : 0;
                        $num_tracks  = isset( $cycle_counts[ $did ] ) ? $cycle_counts[ $did ] : 0;
                        $detail_url   = $district_page_url
                            ? add_query_arg( 'id', $did, $district_page_url )
                            : '';
                    ?>
                        <div class="hl-crm-card">
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
                                        <strong><?php echo esc_html( $num_schools ); ?></strong>
                                        <?php echo esc_html( _n( 'School', 'Schools', $num_schools, 'hl-core' ) ); ?>
                                    </span>
                                    <span class="hl-crm-card-stat">
                                        <strong><?php echo esc_html( $num_tracks ); ?></strong>
                                        <?php echo esc_html( _n( 'Active Cycle', 'Active Cycles', $num_tracks, 'hl-core' ) ); ?>
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
            <?php endif; ?>

        </div>
        <?php

        return ob_get_clean();
    }

    // ========================================================================
    // Data Helpers
    // ========================================================================

    /**
     * Count schools per district.
     *
     * @return array [ district_orgunit_id => count ]
     */
    private function get_school_counts() {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT parent_orgunit_id, COUNT(*) AS cnt
             FROM {$wpdb->prefix}hl_orgunit
             WHERE orgunit_type = 'school' AND parent_orgunit_id IS NOT NULL
             GROUP BY parent_orgunit_id",
            ARRAY_A
        );

        $map = array();
        foreach ( $results ?: array() as $row ) {
            $map[ $row['parent_orgunit_id'] ] = (int) $row['cnt'];
        }
        return $map;
    }

    /**
     * Count active tracks per district.
     *
     * A track is linked to a district when it has schools (via hl_cycle_school)
     * whose parent_orgunit_id is the district.
     *
     * @return array [ district_orgunit_id => count ]
     */
    private function get_active_cycle_counts() {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $results = $wpdb->get_results(
            "SELECT ou.parent_orgunit_id AS district_id,
                    COUNT(DISTINCT cs.cycle_id) AS cnt
             FROM {$prefix}hl_cycle_school cs
             INNER JOIN {$prefix}hl_orgunit ou ON cs.school_id = ou.orgunit_id
             INNER JOIN {$prefix}hl_cycle t ON cs.cycle_id = t.cycle_id
             WHERE ou.parent_orgunit_id IS NOT NULL
               AND t.status = 'active'
             GROUP BY ou.parent_orgunit_id",
            ARRAY_A
        );

        $map = array();
        foreach ( $results ?: array() as $row ) {
            $map[ $row['district_id'] ] = (int) $row['cnt'];
        }
        return $map;
    }

    private function find_shortcode_page_url( $shortcode ) {
        return HL_Page_Cache::get_url( $shortcode );
    }
}
