<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_district_page] shortcode.
 *
 * District-level CRM view showing header, active cohorts, centers, and overview stats.
 *
 * Access: Housman Admin, Coach, District Leader(s) enrolled in that district.
 * URL: ?id={orgunit_id}
 *
 * @package HL_Core
 */
class HL_Frontend_District_Page {

    /** @var HL_OrgUnit_Repository */
    private $orgunit_repo;

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    public function __construct() {
        $this->orgunit_repo    = new HL_OrgUnit_Repository();
        $this->enrollment_repo = new HL_Enrollment_Repository();
    }

    // ========================================================================
    // Render
    // ========================================================================

    public function render( $atts ) {
        ob_start();

        $user_id     = get_current_user_id();
        $district_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( ! $district_id ) {
            echo '<div class="hl-dashboard hl-district-page hl-frontend-wrap">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'Invalid district link.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $district = $this->orgunit_repo->get_by_id( $district_id );
        if ( ! $district || ! $district->is_district() ) {
            echo '<div class="hl-dashboard hl-district-page hl-frontend-wrap">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'District not found.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        // Access check.
        if ( ! $this->verify_access( $district, $user_id ) ) {
            echo '<div class="hl-dashboard hl-district-page hl-frontend-wrap">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'You do not have access to this district.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $centers = $this->orgunit_repo->get_centers( $district_id );
        $cohorts = $this->get_active_cohorts_for_district( $district_id );

        // Stats.
        $total_participants = $this->count_participants_in_district( $district_id );

        // URLs.
        $back_url           = $this->find_shortcode_page_url( 'hl_districts_listing' );
        $center_page_url    = $this->find_shortcode_page_url( 'hl_center_page' );
        $workspace_page_url = $this->find_shortcode_page_url( 'hl_cohort_workspace' );

        ?>
        <div class="hl-dashboard hl-district-page hl-frontend-wrap">

            <?php if ( ! empty( $back_url ) ) : ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="hl-back-link">&larr; <?php esc_html_e( 'Back to Districts', 'hl-core' ); ?></a>
            <?php endif; ?>

            <?php $this->render_header( $district, count( $centers ), $total_participants ); ?>

            <?php $this->render_cohorts_section( $cohorts, $workspace_page_url, $district_id ); ?>

            <?php $this->render_centers_section( $centers, $center_page_url ); ?>

            <?php $this->render_stats_section( count( $centers ), $total_participants ); ?>

        </div>
        <?php

        return ob_get_clean();
    }

    // ========================================================================
    // Access Control
    // ========================================================================

    private function verify_access( $district, $user_id ) {
        if ( HL_Security::can_manage() ) {
            return true;
        }

        // District leaders enrolled in this district.
        global $wpdb;
        $has_access = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_enrollment
             WHERE user_id = %d AND status = 'active'
               AND district_id = %d
               AND roles LIKE %s",
            $user_id,
            $district->orgunit_id,
            '%"district_leader"%'
        ) );

        return (int) $has_access > 0;
    }

    // ========================================================================
    // Header
    // ========================================================================

    private function render_header( $district, $num_centers, $total_participants ) {
        ?>
        <div class="hl-crm-detail-header">
            <div class="hl-crm-detail-header-info">
                <h2 class="hl-cohort-title"><?php echo esc_html( $district->name ); ?></h2>
                <p class="hl-scope-indicator"><?php esc_html_e( 'School District', 'hl-core' ); ?></p>
            </div>
            <div class="hl-crm-detail-header-stats">
                <div class="hl-crm-stat-box">
                    <div class="hl-crm-stat-value"><?php echo esc_html( $num_centers ); ?></div>
                    <div class="hl-crm-stat-label"><?php esc_html_e( 'Centers', 'hl-core' ); ?></div>
                </div>
                <div class="hl-crm-stat-box">
                    <div class="hl-crm-stat-value"><?php echo esc_html( $total_participants ); ?></div>
                    <div class="hl-crm-stat-label"><?php esc_html_e( 'Participants', 'hl-core' ); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    // ========================================================================
    // Section: Active Cohorts
    // ========================================================================

    private function render_cohorts_section( $cohorts, $workspace_url, $district_id ) {
        ?>
        <div class="hl-crm-section">
            <h3 class="hl-section-title"><?php esc_html_e( 'Active Cohorts', 'hl-core' ); ?></h3>

            <?php if ( empty( $cohorts ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No active cohorts in this district.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-crm-cohort-list">
                    <?php foreach ( $cohorts as $cohort ) :
                        $status       = $cohort->status ?: 'active';
                        $status_class = 'hl-badge-' . sanitize_html_class( $status );
                        $dates        = array();
                        if ( $cohort->start_date ) $dates[] = date_i18n( 'M j, Y', strtotime( $cohort->start_date ) );
                        if ( $cohort->end_date )   $dates[] = date_i18n( 'M j, Y', strtotime( $cohort->end_date ) );

                        $participant_count = $this->enrollment_repo->count_by_cohort( $cohort->cohort_id );

                        $cohort_url = $workspace_url
                            ? add_query_arg( array( 'id' => $cohort->cohort_id, 'orgunit' => $district_id ), $workspace_url )
                            : '';
                    ?>
                        <div class="hl-crm-cohort-row">
                            <div class="hl-crm-cohort-info">
                                <strong><?php echo esc_html( $cohort->cohort_name ); ?></strong>
                                <span class="hl-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span>
                                <?php if ( ! empty( $dates ) ) : ?>
                                    <span class="hl-crm-cohort-dates"><?php echo esc_html( implode( ' — ', $dates ) ); ?></span>
                                <?php endif; ?>
                                <span class="hl-crm-cohort-count">
                                    <?php printf(
                                        esc_html( _n( '%d participant', '%d participants', $participant_count, 'hl-core' ) ),
                                        $participant_count
                                    ); ?>
                                </span>
                            </div>
                            <?php if ( $cohort_url ) : ?>
                                <a href="<?php echo esc_url( $cohort_url ); ?>" class="hl-btn hl-btn-sm hl-btn-primary">
                                    <?php esc_html_e( 'Open Cohort', 'hl-core' ); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ========================================================================
    // Section: Centers
    // ========================================================================

    private function render_centers_section( $centers, $center_page_url ) {
        ?>
        <div class="hl-crm-section">
            <h3 class="hl-section-title"><?php esc_html_e( 'Centers', 'hl-core' ); ?></h3>

            <?php if ( empty( $centers ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No centers in this district.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-crm-card-grid">
                    <?php foreach ( $centers as $center ) :
                        $leader_names = $this->get_center_leader_names( $center->orgunit_id );
                        $url = $center_page_url
                            ? add_query_arg( 'id', $center->orgunit_id, $center_page_url )
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
                                <?php if ( ! empty( $leader_names ) ) : ?>
                                    <p class="hl-crm-card-detail">
                                        <strong><?php esc_html_e( 'Leader(s):', 'hl-core' ); ?></strong>
                                        <?php echo esc_html( implode( ', ', $leader_names ) ); ?>
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
    }

    // ========================================================================
    // Section: Stats
    // ========================================================================

    private function render_stats_section( $num_centers, $total_participants ) {
        ?>
        <div class="hl-crm-section">
            <h3 class="hl-section-title"><?php esc_html_e( 'Overview', 'hl-core' ); ?></h3>
            <div class="hl-crm-stats-row">
                <div class="hl-metric-card">
                    <div class="hl-metric-value"><?php echo esc_html( $num_centers ); ?></div>
                    <div class="hl-metric-label"><?php esc_html_e( 'Total Centers', 'hl-core' ); ?></div>
                </div>
                <div class="hl-metric-card">
                    <div class="hl-metric-value"><?php echo esc_html( $total_participants ); ?></div>
                    <div class="hl-metric-label"><?php esc_html_e( 'Total Participants', 'hl-core' ); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    // ========================================================================
    // Data Helpers
    // ========================================================================

    /**
     * Get active cohorts that include centers in this district.
     */
    private function get_active_cohorts_for_district( $district_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT c.*
             FROM {$prefix}hl_cohort c
             INNER JOIN {$prefix}hl_cohort_center cc ON c.cohort_id = cc.cohort_id
             INNER JOIN {$prefix}hl_orgunit ou ON cc.center_id = ou.orgunit_id
             WHERE ou.parent_orgunit_id = %d
               AND c.status = 'active'
             ORDER BY c.start_date DESC",
            $district_id
        ), ARRAY_A );

        return array_map( function ( $row ) { return new HL_Cohort( $row ); }, $rows ?: array() );
    }

    /**
     * Count active participants across all active cohorts in this district.
     */
    private function count_participants_in_district( $district_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.enrollment_id)
             FROM {$prefix}hl_enrollment e
             INNER JOIN {$prefix}hl_cohort c ON e.cohort_id = c.cohort_id
             INNER JOIN {$prefix}hl_cohort_center cc ON c.cohort_id = cc.cohort_id
             INNER JOIN {$prefix}hl_orgunit ou ON cc.center_id = ou.orgunit_id
             WHERE ou.parent_orgunit_id = %d
               AND c.status = 'active'
               AND e.status = 'active'",
            $district_id
        ) );
    }

    /**
     * Get center leader display names for a center.
     */
    private function get_center_leader_names( $center_id ) {
        global $wpdb;

        $results = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT u.display_name
             FROM {$wpdb->prefix}hl_enrollment e
             INNER JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.center_id = %d
               AND e.status = 'active'
               AND e.roles LIKE %s
             ORDER BY u.display_name ASC",
            $center_id,
            '%"center_leader"%'
        ) );

        return $results ?: array();
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
