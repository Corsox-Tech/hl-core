<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_center_page] shortcode.
 *
 * Center-level CRM view showing header, active cohorts, classrooms, and staff.
 *
 * Access: Housman Admin, Coach, Center Leader(s) of this center,
 *         District Leader(s) of the parent district.
 * URL: ?id={orgunit_id}
 *
 * @package HL_Core
 */
class HL_Frontend_Center_Page {

    /** @var HL_OrgUnit_Repository */
    private $orgunit_repo;

    /** @var HL_Classroom_Service */
    private $classroom_service;

    public function __construct() {
        $this->orgunit_repo      = new HL_OrgUnit_Repository();
        $this->classroom_service = new HL_Classroom_Service();
    }

    // ========================================================================
    // Render
    // ========================================================================

    public function render( $atts ) {
        ob_start();

        $user_id   = get_current_user_id();
        $center_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( ! $center_id ) {
            echo '<div class="hl-dashboard hl-center-page hl-frontend-wrap">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'Invalid center link.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $center = $this->orgunit_repo->get_by_id( $center_id );
        if ( ! $center || ! $center->is_center() ) {
            echo '<div class="hl-dashboard hl-center-page hl-frontend-wrap">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'Center not found.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        if ( ! $this->verify_access( $center, $user_id ) ) {
            echo '<div class="hl-dashboard hl-center-page hl-frontend-wrap">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'You do not have access to this center.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $parent_district = $center->parent_orgunit_id
            ? $this->orgunit_repo->get_by_id( $center->parent_orgunit_id )
            : null;

        $cohorts    = $this->get_active_cohorts_for_center( $center_id );
        $classrooms = $this->classroom_service->get_classrooms( $center_id );
        $staff      = $this->get_staff_at_center( $center_id );

        // URLs.
        $district_page_url  = $this->find_shortcode_page_url( 'hl_district_page' );
        $workspace_page_url = $this->find_shortcode_page_url( 'hl_cohort_workspace' );
        $classroom_page_url = $this->find_shortcode_page_url( 'hl_classroom_page' );

        $back_url = '';
        if ( $parent_district && $district_page_url ) {
            $back_url = add_query_arg( 'id', $parent_district->orgunit_id, $district_page_url );
        } elseif ( $this->find_shortcode_page_url( 'hl_centers_listing' ) ) {
            $back_url = $this->find_shortcode_page_url( 'hl_centers_listing' );
        }

        ?>
        <div class="hl-dashboard hl-center-page hl-frontend-wrap">

            <?php if ( ! empty( $back_url ) ) : ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="hl-back-link">&larr;
                    <?php
                    if ( $parent_district ) {
                        printf( esc_html__( 'Back to %s', 'hl-core' ), esc_html( $parent_district->name ) );
                    } else {
                        esc_html_e( 'Back to Institutions', 'hl-core' );
                    }
                    ?>
                </a>
            <?php endif; ?>

            <?php $this->render_header( $center, $parent_district, $district_page_url ); ?>

            <?php $this->render_cohorts_section( $cohorts, $workspace_page_url, $center_id ); ?>

            <?php $this->render_classrooms_section( $classrooms, $classroom_page_url ); ?>

            <?php $this->render_staff_section( $staff ); ?>

        </div>
        <?php

        return ob_get_clean();
    }

    // ========================================================================
    // Access Control
    // ========================================================================

    private function verify_access( $center, $user_id ) {
        if ( HL_Security::can_manage() ) {
            return true;
        }

        global $wpdb;

        // Center leaders of this center.
        $is_center_leader = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_enrollment
             WHERE user_id = %d AND status = 'active'
               AND center_id = %d
               AND roles LIKE %s",
            $user_id,
            $center->orgunit_id,
            '%"center_leader"%'
        ) );

        if ( (int) $is_center_leader > 0 ) {
            return true;
        }

        // District leaders of the parent district.
        if ( $center->parent_orgunit_id ) {
            $is_district_leader = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}hl_enrollment
                 WHERE user_id = %d AND status = 'active'
                   AND district_id = %d
                   AND roles LIKE %s",
                $user_id,
                $center->parent_orgunit_id,
                '%"district_leader"%'
            ) );

            if ( (int) $is_district_leader > 0 ) {
                return true;
            }
        }

        return false;
    }

    // ========================================================================
    // Header
    // ========================================================================

    private function render_header( $center, $parent_district, $district_page_url ) {
        ?>
        <div class="hl-crm-detail-header">
            <div class="hl-crm-detail-header-info">
                <h2 class="hl-cohort-title"><?php echo esc_html( $center->name ); ?></h2>
                <?php if ( $parent_district ) :
                    $d_url = $district_page_url
                        ? add_query_arg( 'id', $parent_district->orgunit_id, $district_page_url )
                        : '';
                ?>
                    <p class="hl-scope-indicator">
                        <?php if ( $d_url ) : ?>
                            <a href="<?php echo esc_url( $d_url ); ?>">
                                <?php echo esc_html( $parent_district->name ); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html( $parent_district->name ); ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ========================================================================
    // Section: Active Cohorts
    // ========================================================================

    private function render_cohorts_section( $cohorts, $workspace_url, $center_id ) {
        ?>
        <div class="hl-crm-section">
            <h3 class="hl-section-title"><?php esc_html_e( 'Active Cohorts', 'hl-core' ); ?></h3>

            <?php if ( empty( $cohorts ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No active cohorts at this center.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-crm-cohort-list">
                    <?php foreach ( $cohorts as $row ) :
                        $cohort           = $row['cohort'];
                        $participant_count = $row['participant_count'];
                        $status           = $cohort->status ?: 'active';
                        $status_class     = 'hl-badge-' . sanitize_html_class( $status );

                        $cohort_url = $workspace_url
                            ? add_query_arg( array( 'id' => $cohort->cohort_id, 'orgunit' => $center_id ), $workspace_url )
                            : '';
                    ?>
                        <div class="hl-crm-cohort-row">
                            <div class="hl-crm-cohort-info">
                                <strong><?php echo esc_html( $cohort->cohort_name ); ?></strong>
                                <span class="hl-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span>
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
    // Section: Classrooms
    // ========================================================================

    private function render_classrooms_section( $classrooms, $classroom_page_url ) {
        // Batch data.
        $classroom_ids = array_map( function ( $c ) {
            return is_object( $c ) ? $c->classroom_id : $c['classroom_id'];
        }, $classrooms );

        $child_counts  = $this->get_classroom_child_counts( $classroom_ids );
        $teacher_names = $this->get_classroom_teacher_names( $classroom_ids );

        ?>
        <div class="hl-crm-section">
            <h3 class="hl-section-title">
                <?php printf( esc_html__( 'Classrooms (%d)', 'hl-core' ), count( $classrooms ) ); ?>
            </h3>

            <?php if ( empty( $classrooms ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No classrooms at this center.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <table class="hl-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Classroom', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Age Band', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Children', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Teacher(s)', 'hl-core' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $classrooms as $cr ) :
                            $cr  = is_object( $cr ) ? $cr : (object) $cr;
                            $cid = $cr->classroom_id;
                            $count    = isset( $child_counts[ $cid ] ) ? $child_counts[ $cid ] : 0;
                            $teachers = isset( $teacher_names[ $cid ] ) ? $teacher_names[ $cid ] : '—';
                            $url = $classroom_page_url
                                ? add_query_arg( 'id', $cid, $classroom_page_url )
                                : '';
                        ?>
                            <tr>
                                <td>
                                    <?php if ( $url ) : ?>
                                        <a href="<?php echo esc_url( $url ); ?>"><strong><?php echo esc_html( $cr->classroom_name ); ?></strong></a>
                                    <?php else : ?>
                                        <strong><?php echo esc_html( $cr->classroom_name ); ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $cr->age_band ?: '—' ); ?></td>
                                <td><?php echo esc_html( $count ); ?></td>
                                <td><?php echo esc_html( $teachers ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ========================================================================
    // Section: Staff
    // ========================================================================

    private function render_staff_section( $staff ) {
        ?>
        <div class="hl-crm-section">
            <h3 class="hl-section-title">
                <?php printf( esc_html__( 'Staff (%d)', 'hl-core' ), count( $staff ) ); ?>
            </h3>

            <?php if ( empty( $staff ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No staff at this center.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <table class="hl-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Role', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Cohort', 'hl-core' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $staff as $s ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $s['display_name'] ); ?></strong></td>
                                <td><?php echo esc_html( $s['user_email'] ); ?></td>
                                <td><?php echo esc_html( $s['roles_str'] ); ?></td>
                                <td><?php echo esc_html( $s['cohort_name'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ========================================================================
    // Data Helpers
    // ========================================================================

    /**
     * Get active cohorts this center participates in, with participant count at this center.
     */
    private function get_active_cohorts_for_center( $center_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM {$prefix}hl_enrollment e2
                     WHERE e2.cohort_id = c.cohort_id AND e2.status = 'active'
                       AND e2.center_id = %d) AS participant_count
             FROM {$prefix}hl_cohort c
             INNER JOIN {$prefix}hl_cohort_center cc ON c.cohort_id = cc.cohort_id
             WHERE cc.center_id = %d
               AND c.status = 'active'
             ORDER BY c.start_date DESC",
            $center_id,
            $center_id
        ), ARRAY_A );

        return array_map( function ( $row ) {
            $count = isset( $row['participant_count'] ) ? (int) $row['participant_count'] : 0;
            unset( $row['participant_count'] );
            return array(
                'cohort'            => new HL_Cohort( $row ),
                'participant_count' => $count,
            );
        }, $rows ?: array() );
    }

    /**
     * Get all staff enrolled at this center (across all cohorts).
     */
    private function get_staff_at_center( $center_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.enrollment_id, u.display_name, u.user_email, e.roles,
                    c.cohort_name
             FROM {$prefix}hl_enrollment e
             INNER JOIN {$wpdb->users} u ON e.user_id = u.ID
             INNER JOIN {$prefix}hl_cohort c ON e.cohort_id = c.cohort_id
             WHERE e.center_id = %d
               AND e.status = 'active'
             ORDER BY u.display_name ASC",
            $center_id
        ), ARRAY_A );

        return array_map( function ( $row ) {
            $roles_raw  = json_decode( $row['roles'], true );
            $roles_str  = is_array( $roles_raw )
                ? implode( ', ', array_map( function ( $r ) {
                    return ucwords( str_replace( '_', ' ', $r ) );
                }, $roles_raw ) )
                : '';
            $row['roles_str'] = $roles_str;
            return $row;
        }, $rows ?: array() );
    }

    /**
     * Batch-get child counts per classroom.
     */
    private function get_classroom_child_counts( $classroom_ids ) {
        global $wpdb;

        if ( empty( $classroom_ids ) ) {
            return array();
        }

        $classroom_ids = array_map( 'absint', $classroom_ids );
        $placeholders  = implode( ',', array_fill( 0, count( $classroom_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT classroom_id, COUNT(*) AS child_count
             FROM {$wpdb->prefix}hl_child_classroom_current
             WHERE classroom_id IN ({$placeholders})
             GROUP BY classroom_id",
            $classroom_ids
        ), ARRAY_A );

        $map = array();
        foreach ( $results as $row ) {
            $map[ $row['classroom_id'] ] = (int) $row['child_count'];
        }
        return $map;
    }

    /**
     * Batch-get teacher names per classroom (across all cohorts).
     */
    private function get_classroom_teacher_names( $classroom_ids ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        if ( empty( $classroom_ids ) ) {
            return array();
        }

        $classroom_ids = array_map( 'absint', $classroom_ids );
        $placeholders  = implode( ',', array_fill( 0, count( $classroom_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT ta.classroom_id,
                    GROUP_CONCAT(DISTINCT u.display_name ORDER BY u.display_name SEPARATOR ', ') AS teacher_names
             FROM {$prefix}hl_teaching_assignment ta
             JOIN {$prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE ta.classroom_id IN ({$placeholders})
             GROUP BY ta.classroom_id",
            $classroom_ids
        ), ARRAY_A );

        $map = array();
        foreach ( $results as $row ) {
            $map[ $row['classroom_id'] ] = $row['teacher_names'];
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
