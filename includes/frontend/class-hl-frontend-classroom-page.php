<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_classroom_page] shortcode.
 *
 * Displays a single classroom detail view with header info, children table,
 * and teacher add/remove functionality.
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

        // Handle POST actions early via template_redirect.
        add_action( 'template_redirect', array( $this, 'handle_post_actions' ) );
    }

    // ========================================================================
    // POST Handlers
    // ========================================================================

    public function handle_post_actions() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            return;
        }

        $classroom_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $classroom_id ) {
            return;
        }

        // Add child.
        if ( isset( $_POST['hl_action'] ) && $_POST['hl_action'] === 'add_child' ) {
            $this->handle_add_child( $classroom_id );
        }

        // Remove child.
        if ( isset( $_POST['hl_action'] ) && $_POST['hl_action'] === 'remove_child' ) {
            $this->handle_remove_child( $classroom_id );
        }
    }

    private function handle_add_child( $classroom_id ) {
        if ( ! wp_verify_nonce( $_POST['_hl_nonce'] ?? '', 'hl_add_child_' . $classroom_id ) ) {
            return;
        }

        $enrollment_id = $this->get_teacher_enrollment_for_classroom( get_current_user_id(), $classroom_id );
        if ( ! $enrollment_id && ! HL_Security::can_manage() ) {
            return;
        }

        // Staff without enrollment can still add children.
        if ( ! $enrollment_id ) {
            $enrollment_id = 0;
        }

        $data = array(
            'first_name' => $_POST['first_name'] ?? '',
            'last_name'  => $_POST['last_name'] ?? '',
            'dob'        => $_POST['dob'] ?? '',
            'gender'     => $_POST['gender'] ?? '',
        );

        $result = $this->classroom_service->teacher_add_child( $classroom_id, $enrollment_id, $data );

        $redirect = $this->get_classroom_page_url( $classroom_id );

        if ( is_wp_error( $result ) ) {
            $redirect = add_query_arg( 'hl_error', urlencode( $result->get_error_message() ), $redirect );
        } else {
            $return_to = isset( $_POST['return_to_assessment'] ) ? absint( $_POST['return_to_assessment'] ) : 0;
            $redirect  = add_query_arg( 'hl_success', 'child_added', $redirect );
            if ( $return_to ) {
                $redirect = add_query_arg( 'return_to_assessment', $return_to, $redirect );
            }
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    private function handle_remove_child( $classroom_id ) {
        if ( ! wp_verify_nonce( $_POST['_hl_nonce'] ?? '', 'hl_remove_child_' . $classroom_id ) ) {
            return;
        }

        $enrollment_id = $this->get_teacher_enrollment_for_classroom( get_current_user_id(), $classroom_id );
        if ( ! $enrollment_id && ! HL_Security::can_manage() ) {
            return;
        }

        if ( ! $enrollment_id ) {
            $enrollment_id = 0;
        }

        $child_id = absint( $_POST['child_id'] ?? 0 );
        $reason   = sanitize_text_field( $_POST['removal_reason'] ?? 'other' );
        $note     = sanitize_textarea_field( $_POST['removal_note'] ?? '' );

        if ( ! $child_id ) {
            return;
        }

        $result  = $this->classroom_service->teacher_remove_child( $classroom_id, $child_id, $enrollment_id, $reason, $note );
        $redirect = $this->get_classroom_page_url( $classroom_id );

        if ( is_wp_error( $result ) ) {
            $redirect = add_query_arg( 'hl_error', urlencode( $result->get_error_message() ), $redirect );
        } else {
            $redirect = add_query_arg( 'hl_success', 'child_removed', $redirect );
        }

        wp_safe_redirect( $redirect );
        exit;
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

        // Detect if user can manage roster.
        $is_assigned_teacher = (bool) $this->get_teacher_enrollment_for_classroom( $user_id, $classroom_id );
        $can_manage_roster   = $is_assigned_teacher || HL_Security::can_manage();

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

        // Success/error notices.
        $return_to_assessment = isset( $_GET['return_to_assessment'] ) ? absint( $_GET['return_to_assessment'] ) : 0;

        ?>
        <div class="hl-dashboard hl-classroom-page hl-frontend-wrap">

            <?php if ( ! empty( $back_url ) ) : ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="hl-back-link">&larr; <?php echo esc_html( $back_label ); ?></a>
            <?php endif; ?>

            <?php $this->render_header( $classroom, $school, $teacher_names ); ?>

            <?php $this->render_notices( $return_to_assessment ); ?>

            <div class="hl-table-container">
                <div class="hl-table-header">
                    <h3 class="hl-section-title">
                        <?php
                        printf(
                            esc_html__( 'Children (%d)', 'hl-core' ),
                            count( $children )
                        );
                        ?>
                    </h3>
                    <div class="hl-table-filters" style="display:flex; gap:8px; align-items:center;">
                        <?php if ( ! empty( $children ) ) : ?>
                            <input type="text" class="hl-search-input" data-table="hl-children-table"
                                   placeholder="<?php esc_attr_e( 'Search by name...', 'hl-core' ); ?>">
                        <?php endif; ?>
                        <?php if ( $can_manage_roster ) : ?>
                            <button type="button" class="hl-btn hl-btn-primary hl-btn-sm" id="hl-toggle-add-child">
                                + <?php esc_html_e( 'Add Child', 'hl-core' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $can_manage_roster ) : ?>
                    <?php $this->render_add_child_form( $classroom_id, $return_to_assessment ); ?>
                <?php endif; ?>

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
                                <?php if ( $can_manage_roster ) : ?>
                                    <th style="width:100px;"><?php esc_html_e( 'Actions', 'hl-core' ); ?></th>
                                <?php endif; ?>
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
                                    <?php if ( $can_manage_roster ) : ?>
                                        <td>
                                            <button type="button" class="hl-btn hl-btn-sm hl-btn-danger hl-remove-child-btn"
                                                    data-child-id="<?php echo absint( $child->child_id ); ?>"
                                                    data-child-name="<?php echo esc_attr( $name ); ?>">
                                                <?php esc_html_e( 'Remove', 'hl-core' ); ?>
                                            </button>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if ( $can_manage_roster ) : ?>
                <?php $this->render_remove_modal( $classroom_id ); ?>
            <?php endif; ?>

        </div>

        <?php if ( $can_manage_roster ) : ?>
            <?php $this->render_roster_js(); ?>
        <?php endif; ?>
        <?php

        return ob_get_clean();
    }

    // ========================================================================
    // Notices
    // ========================================================================

    private function render_notices( $return_to_assessment ) {
        $success = isset( $_GET['hl_success'] ) ? sanitize_text_field( $_GET['hl_success'] ) : '';
        $error   = isset( $_GET['hl_error'] ) ? sanitize_text_field( $_GET['hl_error'] ) : '';

        if ( $success === 'child_added' ) {
            echo '<div class="hl-notice hl-notice-success">';
            esc_html_e( 'Child added successfully.', 'hl-core' );
            if ( $return_to_assessment ) {
                $assessment_url = $this->find_shortcode_page_url( 'hl_child_assessment' );
                if ( $assessment_url ) {
                    $assessment_url = add_query_arg( 'instance_id', $return_to_assessment, $assessment_url );
                    echo ' <a href="' . esc_url( $assessment_url ) . '">' . esc_html__( 'Return to your assessment &rarr;', 'hl-core' ) . '</a>';
                }
            }
            echo '</div>';
        }

        if ( $success === 'child_removed' ) {
            echo '<div class="hl-notice hl-notice-success">';
            esc_html_e( 'Child removed from classroom.', 'hl-core' );
            echo '</div>';
        }

        if ( $error ) {
            echo '<div class="hl-notice hl-notice-error">' . esc_html( $error ) . '</div>';
        }
    }

    // ========================================================================
    // Add Child Form
    // ========================================================================

    private function render_add_child_form( $classroom_id, $return_to_assessment ) {
        ?>
        <div id="hl-add-child-form" style="display:none; padding:20px; background:var(--hl-bg-secondary, #f9f9f9); border:1px solid var(--hl-border, #ddd); border-radius:6px; margin-bottom:16px;">
            <h4 style="margin-top:0;"><?php esc_html_e( 'Add a Child', 'hl-core' ); ?></h4>
            <form method="post">
                <input type="hidden" name="hl_action" value="add_child">
                <input type="hidden" name="_hl_nonce" value="<?php echo wp_create_nonce( 'hl_add_child_' . $classroom_id ); ?>">
                <?php if ( $return_to_assessment ) : ?>
                    <input type="hidden" name="return_to_assessment" value="<?php echo absint( $return_to_assessment ); ?>">
                <?php endif; ?>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label for="hl-first-name"><strong><?php esc_html_e( 'First Name', 'hl-core' ); ?> *</strong></label>
                        <input type="text" id="hl-first-name" name="first_name" required style="width:100%;">
                    </div>
                    <div>
                        <label for="hl-last-name"><strong><?php esc_html_e( 'Last Name', 'hl-core' ); ?> *</strong></label>
                        <input type="text" id="hl-last-name" name="last_name" required style="width:100%;">
                    </div>
                    <div>
                        <label for="hl-dob"><strong><?php esc_html_e( 'Date of Birth', 'hl-core' ); ?> *</strong></label>
                        <input type="date" id="hl-dob" name="dob" required style="width:100%;">
                    </div>
                    <div>
                        <label for="hl-gender"><strong><?php esc_html_e( 'Gender', 'hl-core' ); ?></strong></label>
                        <select id="hl-gender" name="gender" style="width:100%;">
                            <option value=""><?php esc_html_e( '— Select —', 'hl-core' ); ?></option>
                            <option value="male"><?php esc_html_e( 'Male', 'hl-core' ); ?></option>
                            <option value="female"><?php esc_html_e( 'Female', 'hl-core' ); ?></option>
                            <option value="other"><?php esc_html_e( 'Other', 'hl-core' ); ?></option>
                            <option value="prefer_not_to_say"><?php esc_html_e( 'Prefer not to say', 'hl-core' ); ?></option>
                        </select>
                    </div>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="hl-btn hl-btn-primary hl-btn-sm"><?php esc_html_e( 'Add Child', 'hl-core' ); ?></button>
                    <button type="button" class="hl-btn hl-btn-sm" id="hl-cancel-add-child"><?php esc_html_e( 'Cancel', 'hl-core' ); ?></button>
                </div>
            </form>
        </div>
        <?php
    }

    // ========================================================================
    // Remove Modal
    // ========================================================================

    private function render_remove_modal( $classroom_id ) {
        ?>
        <div id="hl-remove-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:8px; padding:24px; max-width:400px; width:90%; margin:auto; position:relative; top:50%; transform:translateY(-50%);">
                <h4 style="margin-top:0;"><?php esc_html_e( 'Remove Child', 'hl-core' ); ?></h4>
                <p id="hl-remove-confirm-text"></p>
                <form method="post">
                    <input type="hidden" name="hl_action" value="remove_child">
                    <input type="hidden" name="_hl_nonce" value="<?php echo wp_create_nonce( 'hl_remove_child_' . $classroom_id ); ?>">
                    <input type="hidden" name="child_id" id="hl-remove-child-id" value="">
                    <div style="margin-bottom:12px;">
                        <label for="hl-removal-reason"><strong><?php esc_html_e( 'Reason', 'hl-core' ); ?></strong></label>
                        <select name="removal_reason" id="hl-removal-reason" style="width:100%;">
                            <option value="left_school"><?php esc_html_e( 'No longer at this school', 'hl-core' ); ?></option>
                            <option value="moved_classroom"><?php esc_html_e( 'Moved to another classroom', 'hl-core' ); ?></option>
                            <option value="other"><?php esc_html_e( 'Other', 'hl-core' ); ?></option>
                        </select>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label for="hl-removal-note"><strong><?php esc_html_e( 'Note (optional)', 'hl-core' ); ?></strong></label>
                        <textarea name="removal_note" id="hl-removal-note" rows="2" style="width:100%;"></textarea>
                    </div>
                    <div style="display:flex; gap:8px; justify-content:flex-end;">
                        <button type="button" class="hl-btn hl-btn-sm" id="hl-cancel-remove"><?php esc_html_e( 'Cancel', 'hl-core' ); ?></button>
                        <button type="submit" class="hl-btn hl-btn-danger hl-btn-sm"><?php esc_html_e( 'Confirm Remove', 'hl-core' ); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    // ========================================================================
    // Inline JS for roster management
    // ========================================================================

    private function render_roster_js() {
        ?>
        <script>
        (function(){
            // Toggle add child form.
            var toggleBtn = document.getElementById('hl-toggle-add-child');
            var addForm   = document.getElementById('hl-add-child-form');
            var cancelBtn = document.getElementById('hl-cancel-add-child');

            if (toggleBtn && addForm) {
                toggleBtn.addEventListener('click', function() {
                    addForm.style.display = addForm.style.display === 'none' ? 'block' : 'none';
                });
            }
            if (cancelBtn && addForm) {
                cancelBtn.addEventListener('click', function() {
                    addForm.style.display = 'none';
                });
            }

            // Remove child modal.
            var modal = document.getElementById('hl-remove-modal');
            var removeButtons = document.querySelectorAll('.hl-remove-child-btn');
            var cancelRemove  = document.getElementById('hl-cancel-remove');
            var childIdInput  = document.getElementById('hl-remove-child-id');
            var confirmText   = document.getElementById('hl-remove-confirm-text');

            removeButtons.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var childId   = btn.getAttribute('data-child-id');
                    var childName = btn.getAttribute('data-child-name');
                    childIdInput.value = childId;
                    confirmText.textContent = 'Remove ' + childName + ' from this classroom?';
                    modal.style.display = 'flex';
                });
            });

            if (cancelRemove && modal) {
                cancelRemove.addEventListener('click', function() {
                    modal.style.display = 'none';
                });
            }

            // Close modal on outside click.
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) modal.style.display = 'none';
                });
            }
        })();
        </script>
        <?php
    }

    // ========================================================================
    // Access Control
    // ========================================================================

    /**
     * Check if the current user can view this classroom.
     */
    private function verify_access( $classroom, $user_id ) {
        if ( HL_Security::can_manage() ) {
            return true;
        }

        $assignments = $this->classroom_service->get_teaching_assignments( $classroom->classroom_id );
        foreach ( $assignments as $ta ) {
            if ( isset( $ta->user_id ) && (int) $ta->user_id === $user_id ) {
                return true;
            }
        }

        global $wpdb;
        $enrollments = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_enrollment
             WHERE user_id = %d AND status = 'active'",
            $user_id
        ) );

        foreach ( $enrollments as $row ) {
            $enrollment = new HL_Enrollment( (array) $row );
            $roles      = $enrollment->get_roles_array();

            if ( in_array( 'school_leader', $roles, true ) && $enrollment->school_id ) {
                if ( (int) $enrollment->school_id === (int) $classroom->school_id ) {
                    return true;
                }
            }

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

    /**
     * Get the enrollment_id for a teacher assigned to this classroom.
     *
     * @param int $user_id
     * @param int $classroom_id
     * @return int|null
     */
    private function get_teacher_enrollment_for_classroom( $user_id, $classroom_id ) {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT ta.enrollment_id
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             WHERE ta.classroom_id = %d AND e.user_id = %d AND e.status = 'active'
             LIMIT 1",
            $classroom_id,
            $user_id
        ) );
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

    private function get_classroom_page_url( $classroom_id ) {
        $page_url = $this->find_shortcode_page_url( 'hl_classroom_page' );
        if ( ! $page_url ) {
            $page_url = home_url();
        }
        return add_query_arg( 'id', $classroom_id, $page_url );
    }

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
                    _n( '%d yr', '%d yrs', $diff->y, 'hl-core' ),
                    $diff->y
                );
            }
            return sprintf(
                _n( '%d mo', '%d mos', $diff->m, 'hl-core' ),
                $diff->m
            );
        } catch ( Exception $e ) {
            return '—';
        }
    }

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
