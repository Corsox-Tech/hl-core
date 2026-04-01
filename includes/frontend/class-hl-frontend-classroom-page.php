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
    }

    // ========================================================================
    // POST Handlers
    // ========================================================================

    /**
     * Handle POST actions (add child, remove child).
     * Called from template_redirect (registered in HL_Shortcodes) so we can redirect after.
     */
    public static function handle_post_actions() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            return;
        }

        $classroom_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $classroom_id ) {
            return;
        }

        // Add child.
        if ( isset( $_POST['hl_action'] ) && $_POST['hl_action'] === 'add_child' ) {
            self::handle_add_child_post( $classroom_id );
        }

        // Remove child.
        if ( isset( $_POST['hl_action'] ) && $_POST['hl_action'] === 'remove_child' ) {
            self::handle_remove_child_post( $classroom_id );
        }
    }

    private static function handle_add_child_post( $classroom_id ) {
        if ( ! wp_verify_nonce( $_POST['_hl_nonce'] ?? '', 'hl_add_child_' . $classroom_id ) ) {
            return;
        }

        $enrollment_id = self::get_teacher_enrollment_static( get_current_user_id(), $classroom_id );
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

        $service = new HL_Classroom_Service();
        $result  = $service->teacher_add_child( $classroom_id, $enrollment_id, $data );

        $redirect = self::get_classroom_page_url_static( $classroom_id );

        if ( is_wp_error( $result ) ) {
            $redirect = add_query_arg( 'hl_error', urlencode( $result->get_error_message() ), $redirect );
        } else {
            $return_to = isset( $_POST['return_to_assessment'] ) ? absint( $_POST['return_to_assessment'] ) : 0;
            $redirect  = add_query_arg( 'hl_success', 'child_added', $redirect );
            if ( $return_to ) {
                $redirect = add_query_arg( 'return_to_assessment', $return_to, $redirect );
            }
        }

        while ( ob_get_level() ) { ob_end_clean(); }
        if ( ! headers_sent() ) {
            wp_safe_redirect( $redirect );
            exit;
        }
        echo '<script>window.location.href=' . wp_json_encode( $redirect ) . ';</script>';
        exit;
    }

    private static function handle_remove_child_post( $classroom_id ) {
        if ( ! wp_verify_nonce( $_POST['_hl_nonce'] ?? '', 'hl_remove_child_' . $classroom_id ) ) {
            return;
        }

        $enrollment_id = self::get_teacher_enrollment_static( get_current_user_id(), $classroom_id );
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

        $service  = new HL_Classroom_Service();
        $result   = $service->teacher_remove_child( $classroom_id, $child_id, $enrollment_id, $reason, $note );
        $redirect = self::get_classroom_page_url_static( $classroom_id );

        if ( is_wp_error( $result ) ) {
            $redirect = add_query_arg( 'hl_error', urlencode( $result->get_error_message() ), $redirect );
        } else {
            $redirect = add_query_arg( 'hl_success', 'child_removed', $redirect );
        }

        while ( ob_get_level() ) { ob_end_clean(); }
        if ( ! headers_sent() ) {
            wp_safe_redirect( $redirect );
            exit;
        }
        echo '<script>window.location.href=' . wp_json_encode( $redirect ) . ';</script>';
        exit;
    }

    /**
     * Static helper: get teacher enrollment for a classroom.
     */
    private static function get_teacher_enrollment_static( $user_id, $classroom_id ) {
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

    /**
     * Static helper: build classroom page URL.
     */
    private static function get_classroom_page_url_static( $classroom_id ) {
        global $wpdb;
        $page_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
             AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like( 'hl_classroom_page' ) . '%'
        ) );
        $page_url = $page_id ? get_permalink( $page_id ) : home_url();
        return add_query_arg( 'id', $classroom_id, $page_url );
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

        // Get teaching assignments for teacher names + profile links.
        $assignments   = $this->classroom_service->get_teaching_assignments( $classroom_id );
        $teacher_names = array();
        foreach ( $assignments as $ta ) {
            if ( ! empty( $ta->display_name ) ) {
                $teacher_names[] = array(
                    'name'    => $ta->display_name,
                    'user_id' => isset( $ta->user_id ) ? (int) $ta->user_id : 0,
                );
            }
        }

        // Detect if user can manage roster.
        $is_assigned_teacher = (bool) $this->get_teacher_enrollment_for_classroom( $user_id, $classroom_id );
        $can_manage_roster   = $is_assigned_teacher || HL_Security::can_manage();

        // Children.
        $children = $this->classroom_service->get_children_in_classroom( $classroom_id );

        // Breadcrumb URL — control group teachers go to My Programs instead of My Cycle.
        $is_control = $this->is_control_group_classroom( $user_id, $classroom_id );
        if ( $is_control ) {
            $back_url   = $this->find_shortcode_page_url( 'hl_my_programs' );
            $back_label = __( 'Back to My Programs', 'hl-core' );
        } else {
            $back_url   = $this->build_back_url();
            $back_label = __( 'Back to My Cycle', 'hl-core' );
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
                    <div class="hl-table-filters">
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
                                    <th class="hl-col-actions"><?php esc_html_e( 'Actions', 'hl-core' ); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $children as $child ) :
                                $first = trim( $child->first_name ?? '' );
                                $last  = trim( $child->last_name ?? '' );
                                if ( $first !== '' ) {
                                    $name = $last !== '' ? $first . ' ' . mb_strtoupper( mb_substr( $last, 0, 1 ) ) . '.' : $first;
                                } else {
                                    $name = $child->child_display_code ?: __( 'Unnamed', 'hl-core' );
                                }
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
        <div id="hl-add-child-form" class="hl-add-child-panel" style="display:none;">
            <h4><?php esc_html_e( 'Add a Child', 'hl-core' ); ?></h4>
            <form method="post">
                <input type="hidden" name="hl_action" value="add_child">
                <input type="hidden" name="_hl_nonce" value="<?php echo wp_create_nonce( 'hl_add_child_' . $classroom_id ); ?>">
                <?php if ( $return_to_assessment ) : ?>
                    <input type="hidden" name="return_to_assessment" value="<?php echo absint( $return_to_assessment ); ?>">
                <?php endif; ?>
                <div class="hl-form-grid-2col">
                    <div>
                        <label for="hl-first-name"><strong><?php esc_html_e( 'First Name', 'hl-core' ); ?> *</strong></label>
                        <input type="text" id="hl-first-name" name="first_name" required>
                    </div>
                    <div>
                        <label for="hl-last-name"><strong><?php esc_html_e( 'Last Name', 'hl-core' ); ?> *</strong></label>
                        <input type="text" id="hl-last-name" name="last_name" required>
                    </div>
                    <div>
                        <label for="hl-dob"><strong><?php esc_html_e( 'Date of Birth', 'hl-core' ); ?> *</strong></label>
                        <input type="date" id="hl-dob" name="dob" required>
                    </div>
                    <div>
                        <label for="hl-gender"><strong><?php esc_html_e( 'Gender', 'hl-core' ); ?></strong></label>
                        <select id="hl-gender" name="gender">
                            <option value=""><?php esc_html_e( '— Select —', 'hl-core' ); ?></option>
                            <option value="male"><?php esc_html_e( 'Male', 'hl-core' ); ?></option>
                            <option value="female"><?php esc_html_e( 'Female', 'hl-core' ); ?></option>
                            <option value="other"><?php esc_html_e( 'Other', 'hl-core' ); ?></option>
                            <option value="prefer_not_to_say"><?php esc_html_e( 'Prefer not to say', 'hl-core' ); ?></option>
                        </select>
                    </div>
                </div>
                <div class="hl-btn-row">
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
        <div id="hl-remove-modal" class="hl-modal-overlay" style="display:none;">
            <div class="hl-modal-box">
                <h4><?php esc_html_e( 'Remove Child', 'hl-core' ); ?></h4>
                <p id="hl-remove-confirm-text"></p>
                <form method="post">
                    <input type="hidden" name="hl_action" value="remove_child">
                    <input type="hidden" name="_hl_nonce" value="<?php echo wp_create_nonce( 'hl_remove_child_' . $classroom_id ); ?>">
                    <input type="hidden" name="child_id" id="hl-remove-child-id" value="">
                    <div class="hl-form-group">
                        <label for="hl-removal-reason"><strong><?php esc_html_e( 'Reason', 'hl-core' ); ?></strong></label>
                        <select name="removal_reason" id="hl-removal-reason">
                            <option value="left_school"><?php esc_html_e( 'No longer at this school', 'hl-core' ); ?></option>
                            <option value="moved_classroom"><?php esc_html_e( 'Moved to another classroom', 'hl-core' ); ?></option>
                            <option value="other"><?php esc_html_e( 'Other', 'hl-core' ); ?></option>
                        </select>
                    </div>
                    <div class="hl-form-group">
                        <label for="hl-removal-note"><strong><?php esc_html_e( 'Note (optional)', 'hl-core' ); ?></strong></label>
                        <textarea name="removal_note" id="hl-removal-note" rows="2"></textarea>
                    </div>
                    <div class="hl-btn-row hl-btn-row--end">
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
                <h2 class="hl-cycle-title"><?php echo esc_html( $classroom->classroom_name ); ?></h2>
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
                            <?php
                            $links = array();
                            foreach ( $teacher_names as $t ) {
                                $url = $t['user_id'] ? $this->get_profile_url( $t['user_id'] ) : '';
                                if ( $url ) {
                                    $links[] = '<a href="' . esc_url( $url ) . '" class="hl-profile-link">' . esc_html( $t['name'] ) . '</a>';
                                } else {
                                    $links[] = esc_html( $t['name'] );
                                }
                            }
                            echo implode( ', ', $links );
                            ?>
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
             JOIN {$wpdb->prefix}hl_cycle t ON e.cycle_id = t.cycle_id
             WHERE ta.classroom_id = %d AND e.user_id = %d AND e.status = 'active'
             LIMIT 1",
            $classroom_id,
            $user_id
        ) );

        return ! empty( $is_control );
    }

    private function build_back_url() {
        $base = apply_filters( 'hl_core_my_cycle_page_url', '' );
        if ( empty( $base ) ) {
            $base = $this->find_shortcode_page_url( 'hl_my_cycle' );
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

    private function get_profile_url( $user_id ) {
        static $base_url = null;
        if ( $base_url === null ) {
            $base_url = $this->find_shortcode_page_url( 'hl_user_profile' );
        }
        return $base_url ? add_query_arg( 'user_id', (int) $user_id, $base_url ) : '';
    }
}
