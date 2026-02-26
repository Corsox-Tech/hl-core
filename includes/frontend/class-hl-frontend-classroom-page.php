<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_classroom_page] shortcode.
 *
 * Displays a single classroom detail view with header info and children table.
 *
 * Access: Housman Admin, Coach, School Leaders, District Leaders,
 *         Teachers assigned to this classroom.
 * URL: ?id={classroom_id}
 *
 * @package HL_Core
 */
class HL_Frontend_Classroom_Page {

    /** @var HL_Classroom_Service */
    private $classroom_service;

    /** @var HL_OrgUnit_Repository */
    private $orgunit_repo;

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    public function __construct() {
        $this->classroom_service = new HL_Classroom_Service();
        $this->orgunit_repo      = new HL_OrgUnit_Repository();
        $this->enrollment_repo   = new HL_Enrollment_Repository();
    }

    // ========================================================================
    // Render
    // ========================================================================

    public function render( $atts ) {
        ob_start();

        $user_id      = get_current_user_id();
        $classroom_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( ! $classroom_id ) {
            echo '<div class="hl-dashboard hl-classroom-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'Invalid classroom link.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $classroom = $this->classroom_service->get_classroom( $classroom_id );
        if ( ! $classroom ) {
            echo '<div class="hl-dashboard hl-classroom-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'Classroom not found.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        // Access check.
        if ( ! $this->verify_access( $classroom, $user_id ) ) {
            echo '<div class="hl-dashboard hl-classroom-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'You do not have access to this classroom.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $school = $classroom->school_id ? $this->orgunit_repo->get_by_id( $classroom->school_id ) : null;

        // Get teaching assignments for teacher names.
        $assignments   = $this->classroom_service->get_teaching_assignments( $classroom_id );
        $teacher_names = array();
        foreach ( $assignments as $ta ) {
            if ( ! empty( $ta->display_name ) ) {
                $teacher_names[] = $ta->display_name;
            }
        }

        // Children.
        $children = $this->classroom_service->get_children_in_classroom( $classroom_id );

        // Breadcrumb URL — control group teachers go to My Programs instead of My Track.
        $is_control = $this->is_control_group_classroom( $user_id, $classroom_id );
        if ( $is_control ) {
            $back_url   = $this->find_shortcode_page_url( 'hl_my_programs' );
            $back_label = __( 'Back to My Programs', 'hl-core' );
        } else {
            $back_url   = $this->build_back_url();
            $back_label = __( 'Back to My Track', 'hl-core' );
        }

        ?>
        <div class="hl-dashboard hl-classroom-page hl-frontend-wrap">

            <?php if ( ! empty( $back_url ) ) : ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="hl-back-link">&larr; <?php echo esc_html( $back_label ); ?></a>
            <?php endif; ?>

            <?php $this->render_header( $classroom, $school, $teacher_names ); ?>

            <div class="hl-table-container">
                <div class="hl-table-header">
                    <h3 class="hl-section-title">
                        <?php
                        printf(
                            /* translators: %d: number of children */
                            esc_html__( 'Children (%d)', 'hl-core' ),
                            count( $children )
                        );
                        ?>
                    </h3>
                    <?php if ( ! empty( $children ) ) : ?>
                        <div class="hl-table-filters">
                            <input type="text" class="hl-search-input" data-table="hl-children-table"
                                   placeholder="<?php esc_attr_e( 'Search by name...', 'hl-core' ); ?>">
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ( empty( $children ) ) : ?>
                    <div class="hl-empty-state"><p><?php esc_html_e( 'No children currently assigned to this classroom.', 'hl-core' ); ?></p></div>
                <?php else : ?>
                    <table class="hl-table" id="hl-children-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Date of Birth', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Age', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Gender', 'hl-core' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $children as $child ) :
                                $name   = trim( ( $child->first_name ?? '' ) . ' ' . ( $child->last_name ?? '' ) );
                                $name   = $name ?: ( $child->child_display_code ?: __( 'Unnamed', 'hl-core' ) );
                                $dob    = $this->format_date( $child->dob );
                                $age    = $this->compute_age( $child->dob );
                                $gender = $this->get_gender( $child );
                            ?>
                                <tr data-name="<?php echo esc_attr( strtolower( $name ) ); ?>">
                                    <td><strong><?php echo esc_html( $name ); ?></strong></td>
                                    <td><?php echo esc_html( $dob ?: '—' ); ?></td>
                                    <td><?php echo esc_html( $age ); ?></td>
                                    <td><?php echo esc_html( $gender ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        </div>
        <?php

        return ob_get_clean();
    }

    // ========================================================================
    // Access Control
    // ========================================================================

    /**
     * Check if the current user can view this classroom.
     *
     * Allowed: staff (manage_hl_core), teachers assigned to this classroom,
     * school/district leaders whose scope includes the classroom's school.
     */
    private function verify_access( $classroom, $user_id ) {
        // Staff always has access.
        if ( HL_Security::can_manage() ) {
            return true;
        }

        // Check if user is a teacher assigned to this classroom (any track).
        $assignments = $this->classroom_service->get_teaching_assignments( $classroom->classroom_id );
        foreach ( $assignments as $ta ) {
            if ( isset( $ta->user_id ) && (int) $ta->user_id === $user_id ) {
                return true;
            }
        }

        // Check if user is a leader whose scope includes this classroom's school.
        // We need to check across all tracks the user is enrolled in.
        global $wpdb;
        $enrollments = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_enrollment
             WHERE user_id = %d AND status = 'active'",
            $user_id
        ) );

        foreach ( $enrollments as $row ) {
            $enrollment = new HL_Enrollment( (array) $row );
            $roles      = $enrollment->get_roles_array();

            // School leader — classroom must be in their school.
            if ( in_array( 'school_leader', $roles, true ) && $enrollment->school_id ) {
                if ( (int) $enrollment->school_id === (int) $classroom->school_id ) {
                    return true;
                }
            }

            // District leader — classroom's school must be within their district.
            if ( in_array( 'district_leader', $roles, true ) && $enrollment->district_id ) {
                $schools    = $this->orgunit_repo->get_schools( (int) $enrollment->district_id );
                $school_ids = array_map( function ( $c ) { return (int) $c->orgunit_id; }, $schools );
                if ( in_array( (int) $classroom->school_id, $school_ids, true ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    // ========================================================================
    // Header
    // ========================================================================

    private function render_header( $classroom, $school, $teacher_names ) {
        ?>
        <div class="hl-classroom-page-header">
            <div class="hl-classroom-page-header-info">
                <h2 class="hl-track-title"><?php echo esc_html( $classroom->classroom_name ); ?></h2>
                <?php if ( $school ) : ?>
                    <p class="hl-scope-indicator"><?php echo esc_html( $school->name ); ?></p>
                <?php endif; ?>
                <div class="hl-track-meta">
                    <?php if ( ! empty( $classroom->age_band ) ) : ?>
                        <span class="hl-meta-item">
                            <strong><?php esc_html_e( 'Age Band:', 'hl-core' ); ?></strong>
                            <?php echo esc_html( ucfirst( $classroom->age_band ) ); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ( ! empty( $teacher_names ) ) : ?>
                        <span class="hl-meta-item">
                            <strong><?php esc_html_e( 'Teacher(s):', 'hl-core' ); ?></strong>
                            <?php echo esc_html( implode( ', ', $teacher_names ) ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function format_date( $date_string ) {
        if ( empty( $date_string ) ) {
            return '';
        }
        $timestamp = strtotime( $date_string );
        if ( $timestamp === false ) {
            return $date_string;
        }
        return date_i18n( get_option( 'date_format', 'M j, Y' ), $timestamp );
    }

    private function compute_age( $dob ) {
        if ( empty( $dob ) ) {
            return '—';
        }
        try {
            $birth = new DateTime( $dob );
            $today = new DateTime( 'today' );
            $diff  = $birth->diff( $today );

            if ( $diff->y > 0 ) {
                return sprintf(
                    /* translators: 1: years, 2: months */
                    _n( '%d yr', '%d yrs', $diff->y, 'hl-core' ),
                    $diff->y
                );
            }
            return sprintf(
                /* translators: %d: months */
                _n( '%d mo', '%d mos', $diff->m, 'hl-core' ),
                $diff->m
            );
        } catch ( Exception $e ) {
            return '—';
        }
    }

    /**
     * Get gender from the child's metadata JSON or return dash.
     */
    private function get_gender( $child ) {
        if ( ! empty( $child->metadata ) ) {
            $meta = json_decode( $child->metadata, true );
            if ( is_array( $meta ) && ! empty( $meta['gender'] ) ) {
                return ucfirst( $meta['gender'] );
            }
        }
        return '—';
    }

    private function is_control_group_classroom( $user_id, $classroom_id ) {
        global $wpdb;

        // Check if the user's teaching assignment for this classroom belongs to a control group track.
        $is_control = $wpdb->get_var( $wpdb->prepare(
            "SELECT t.is_control_group
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             JOIN {$wpdb->prefix}hl_track t ON e.track_id = t.track_id
             WHERE ta.classroom_id = %d AND e.user_id = %d AND e.status = 'active'
             LIMIT 1",
            $classroom_id,
            $user_id
        ) );

        return ! empty( $is_control );
    }

    private function build_back_url() {
        $base = apply_filters( 'hl_core_my_track_page_url', '' );
        if ( empty( $base ) ) {
            $base = $this->find_shortcode_page_url( 'hl_my_track' );
        }
        if ( ! empty( $base ) ) {
            return add_query_arg( 'tab', 'classrooms', $base );
        }
        return '';
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
