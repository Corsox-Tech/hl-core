<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_centers_listing] shortcode.
 *
 * CRM-style directory — card grid of all centers.
 * Each card shows: center name, parent district, leader name(s).
 *
 * Access: Housman Admin, Coach (manage_hl_core).
 *
 * @package HL_Core
 */
class HL_Frontend_Centers_Listing {

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
            echo '<div class="hl-dashboard hl-centers-listing hl-frontend-wrap">';
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'You do not have access to this page.', 'hl-core' )
                . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $centers = $this->orgunit_repo->get_centers();

        // Pre-compute parent district names.
        $district_map   = $this->get_district_map();
        $leader_map     = $this->get_all_center_leaders();
        $center_page_url = $this->find_shortcode_page_url( 'hl_center_page' );

        ?>
        <div class="hl-dashboard hl-centers-listing hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Institutions', 'hl-core' ); ?></h2>
            </div>

            <?php if ( empty( $centers ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No centers found.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-crm-card-grid">
                    <?php foreach ( $centers as $center ) :
                        $cid           = $center->orgunit_id;
                        $district_name = isset( $district_map[ $center->parent_orgunit_id ] )
                            ? $district_map[ $center->parent_orgunit_id ]
                            : '';
                        $leaders       = isset( $leader_map[ $cid ] ) ? $leader_map[ $cid ] : array();
                        $url           = $center_page_url
                            ? add_query_arg( 'id', $cid, $center_page_url )
                            : '';
                    ?>
                        <div class="hl-crm-card">
                            <div class="hl-crm-card-body">
                                <h3 class="hl-crm-card-title">
                                    <?php if ( $url ) : ?>
                                        <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $center->name ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $center->name ); ?>
                                    <?php endif; ?>
                                </h3>
                                <?php if ( $district_name ) : ?>
                                    <p class="hl-crm-card-subtitle"><?php echo esc_html( $district_name ); ?></p>
                                <?php endif; ?>
                                <?php if ( ! empty( $leaders ) ) : ?>
                                    <p class="hl-crm-card-detail">
                                        <strong><?php esc_html_e( 'Leader(s):', 'hl-core' ); ?></strong>
                                        <?php echo esc_html( implode( ', ', $leaders ) ); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <?php if ( $url ) : ?>
                                <div class="hl-crm-card-action">
                                    <a href="<?php echo esc_url( $url ); ?>" class="hl-btn hl-btn-sm hl-btn-secondary">
                                        <?php esc_html_e( 'View Center', 'hl-core' ); ?>
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
     * Map district orgunit_id => name.
     */
    private function get_district_map() {
        $districts = $this->orgunit_repo->get_districts();
        $map       = array();
        foreach ( $districts as $d ) {
            $map[ $d->orgunit_id ] = $d->name;
        }
        return $map;
    }

    /**
     * Map center_id => [ leader_display_names ].
     */
    private function get_all_center_leaders() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT e.center_id, u.display_name
             FROM {$wpdb->prefix}hl_enrollment e
             INNER JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.status = 'active'
               AND e.center_id IS NOT NULL
               AND e.roles LIKE '%\"center_leader\"%'
             ORDER BY u.display_name ASC",
            ARRAY_A
        );

        $map = array();
        foreach ( $results ?: array() as $row ) {
            $cid = $row['center_id'];
            if ( ! isset( $map[ $cid ] ) ) {
                $map[ $cid ] = array();
            }
            if ( ! in_array( $row['display_name'], $map[ $cid ], true ) ) {
                $map[ $cid ][] = $row['display_name'];
            }
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
