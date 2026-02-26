<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_observations] shortcode.
 *
 * Shows a logged-in mentor their observations across all tracks.
 * Supports three views:
 *   1. List view (default) -- table of all observations with status badges
 *   2. New observation flow (?action=new) -- select teacher, create record
 *   3. Form view (?observation_id=X) -- render JFB form or submitted summary
 *
 * @package HL_Core
 */
class HL_Frontend_Observations {

    /** @var HL_Observation_Service */
    private $observation_service;

    /**
     * Status badge CSS class mapping.
     */
    private static $status_classes = array(
        'draft'     => 'blue',
        'submitted' => 'green',
    );

    /**
     * Status display labels.
     */
    private static $status_labels = array(
        'draft'     => 'Draft',
        'submitted' => 'Submitted',
    );

    public function __construct() {
        $this->observation_service = new HL_Observation_Service();
    }

    /**
     * Render the Observations shortcode.
     *
     * @param array $atts Shortcode attributes (unused currently).
     * @return string HTML output.
     */
    public function render( $atts ) {
        $atts = shortcode_atts( array(), $atts, 'hl_observations' );

        ob_start();

        // Must be logged in
        if ( ! is_user_logged_in() ) {
            ?>
            <div class="hl-notice hl-notice-warning">
                <?php esc_html_e( 'Please log in to view your observations.', 'hl-core' ); ?>
            </div>
            <?php
            return ob_get_clean();
        }

        $user_id = get_current_user_id();

        // Determine which view to render
        $action         = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
        $observation_id = isset( $_GET['observation_id'] ) ? absint( $_GET['observation_id'] ) : 0;

        if ( $observation_id > 0 ) {
            $this->render_form_view( $observation_id, $user_id );
        } elseif ( $action === 'new' ) {
            $this->render_new_observation( $user_id );
        } else {
            $this->render_list_view( $user_id );
        }

        return ob_get_clean();
    }

    // =========================================================================
    // 1. List View
    // =========================================================================

    /**
     * Render the observation list view showing all observations for the
     * current mentor across all their tracks.
     *
     * @param int $user_id Current user ID.
     */
    private function render_list_view( $user_id ) {
        // Check that user has at least one mentor enrollment
        $mentor_enrollments = $this->observation_service->get_mentor_enrollments( $user_id );

        if ( empty( $mentor_enrollments ) ) {
            ?>
            <div class="hl-dashboard hl-observations">
                <div class="hl-empty-state">
                    <h3><?php esc_html_e( 'No Mentor Assignments', 'hl-core' ); ?></h3>
                    <p><?php esc_html_e( 'You do not have any active Mentor enrollments. Observations can only be created by mentors. If you believe this is an error, please contact your track administrator.', 'hl-core' ); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        // Get all observations for this mentor user
        $observations = $this->observation_service->get_by_mentor_user( $user_id );

        ?>
        <div class="hl-dashboard hl-observations">
            <div class="hl-observations-header">
                <h2 class="hl-section-title"><?php esc_html_e( 'My Observations', 'hl-core' ); ?></h2>
                <a href="<?php echo esc_url( add_query_arg( 'action', 'new' ) ); ?>" class="hl-btn hl-btn-primary">
                    <?php esc_html_e( 'New Observation', 'hl-core' ); ?>
                </a>
            </div>

            <?php if ( empty( $observations ) ) : ?>
                <div class="hl-empty-state">
                    <p><?php esc_html_e( 'You have not created any observations yet. Click "New Observation" to get started.', 'hl-core' ); ?></p>
                </div>
            <?php else : ?>
                <table class="hl-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Teacher', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Classroom', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Track', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'hl-core' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $observations as $obs ) : ?>
                            <tr>
                                <td><?php echo esc_html( $this->format_date( $obs['created_at'] ) ); ?></td>
                                <td><?php echo esc_html( ! empty( $obs['teacher_name'] ) ? $obs['teacher_name'] : __( 'N/A', 'hl-core' ) ); ?></td>
                                <td><?php echo esc_html( ! empty( $obs['classroom_name'] ) ? $obs['classroom_name'] : __( 'N/A', 'hl-core' ) ); ?></td>
                                <td><?php echo esc_html( ! empty( $obs['track_name'] ) ? $obs['track_name'] : __( 'N/A', 'hl-core' ) ); ?></td>
                                <td><?php $this->render_status_badge( $obs['status'] ); ?></td>
                                <td>
                                    <?php
                                    $link_url = add_query_arg( 'observation_id', $obs['observation_id'], remove_query_arg( 'action' ) );
                                    if ( $obs['status'] === 'submitted' ) :
                                    ?>
                                        <a href="<?php echo esc_url( $link_url ); ?>" class="hl-btn hl-btn-small">
                                            <?php esc_html_e( 'View', 'hl-core' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url( $link_url ); ?>" class="hl-btn hl-btn-small hl-btn-primary">
                                            <?php esc_html_e( 'Continue', 'hl-core' ); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // 2. New Observation Flow
    // =========================================================================

    /**
     * Render the new observation form where the mentor selects a teacher
     * and optionally a classroom, then creates the observation record.
     *
     * @param int $user_id Current user ID.
     */
    private function render_new_observation( $user_id ) {
        // Get mentor enrollments
        $mentor_enrollments = $this->observation_service->get_mentor_enrollments( $user_id );

        if ( empty( $mentor_enrollments ) ) {
            ?>
            <div class="hl-dashboard hl-observations">
                <div class="hl-notice hl-notice-error">
                    <?php esc_html_e( 'You do not have any active Mentor enrollments. Observations can only be created by mentors.', 'hl-core' ); ?>
                </div>
                <p>
                    <a href="<?php echo esc_url( remove_query_arg( array( 'action', 'observation_id' ) ) ); ?>" class="hl-btn">
                        &larr; <?php esc_html_e( 'Back to Observations', 'hl-core' ); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }

        // Handle POST: create the observation record
        $message      = '';
        $message_type = '';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['hl_create_observation'] ) ) {
            $result = $this->handle_create_observation( $user_id );

            if ( is_wp_error( $result ) ) {
                $message      = $result->get_error_message();
                $message_type = 'error';
            } else {
                // Redirect to the form view for the newly created observation
                $redirect_url = add_query_arg(
                    array( 'observation_id' => $result ),
                    remove_query_arg( array( 'action' ) )
                );
                // Use JavaScript redirect since headers are already sent via shortcode
                ?>
                <script>window.location.href = <?php echo wp_json_encode( $redirect_url ); ?>;</script>
                <div class="hl-notice hl-notice-info">
                    <p><?php esc_html_e( 'Observation created. Redirecting...', 'hl-core' ); ?>
                    <a href="<?php echo esc_url( $redirect_url ); ?>"><?php esc_html_e( 'Click here', 'hl-core' ); ?></a>
                    <?php esc_html_e( 'if not redirected automatically.', 'hl-core' ); ?></p>
                </div>
                <?php
                return;
            }
        }

        // Build the teacher options per enrollment
        // If only one mentor enrollment, auto-select it
        $selected_enrollment_id = 0;
        if ( count( $mentor_enrollments ) === 1 ) {
            $first = reset( $mentor_enrollments );
            $selected_enrollment_id = absint( $first['enrollment_id'] );
        } elseif ( ! empty( $_POST['mentor_enrollment_id'] ) ) {
            $selected_enrollment_id = absint( $_POST['mentor_enrollment_id'] );
        }

        // Get observable teachers for each mentor enrollment
        $enrollment_teachers = array();
        foreach ( $mentor_enrollments as $me ) {
            $eid = absint( $me['enrollment_id'] );
            $teachers = $this->observation_service->get_observable_teachers( $eid );
            $enrollment_teachers[ $eid ] = $teachers;
        }

        ?>
        <div class="hl-dashboard hl-observations hl-observation-new">
            <h2 class="hl-section-title"><?php esc_html_e( 'New Observation', 'hl-core' ); ?></h2>

            <?php if ( ! empty( $message ) ) : ?>
                <div class="hl-notice hl-notice-<?php echo esc_attr( $message_type ); ?>">
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" class="hl-observation-create-form">
                <?php wp_nonce_field( 'hl_create_observation', '_hl_observation_nonce' ); ?>
                <input type="hidden" name="hl_create_observation" value="1" />

                <table class="form-table">
                    <?php if ( count( $mentor_enrollments ) > 1 ) : ?>
                        <tr>
                            <th scope="row">
                                <label for="mentor_enrollment_id"><?php esc_html_e( 'Track', 'hl-core' ); ?></label>
                            </th>
                            <td>
                                <select name="mentor_enrollment_id" id="mentor_enrollment_id" required class="hl-select">
                                    <option value=""><?php esc_html_e( '-- Select Track --', 'hl-core' ); ?></option>
                                    <?php foreach ( $mentor_enrollments as $me ) : ?>
                                        <option value="<?php echo esc_attr( $me['enrollment_id'] ); ?>"
                                            <?php selected( $selected_enrollment_id, absint( $me['enrollment_id'] ) ); ?>>
                                            <?php echo esc_html( $me['track_name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Select the track this observation is for.', 'hl-core' ); ?></p>
                            </td>
                        </tr>
                    <?php else : ?>
                        <input type="hidden" name="mentor_enrollment_id" value="<?php echo esc_attr( $selected_enrollment_id ); ?>" />
                    <?php endif; ?>

                    <tr>
                        <th scope="row">
                            <label for="teacher_enrollment_id"><?php esc_html_e( 'Teacher', 'hl-core' ); ?></label>
                        </th>
                        <td>
                            <?php if ( count( $mentor_enrollments ) === 1 && ! empty( $enrollment_teachers[ $selected_enrollment_id ] ) ) : ?>
                                <select name="teacher_enrollment_id" id="teacher_enrollment_id" required class="hl-select">
                                    <option value=""><?php esc_html_e( '-- Select Teacher --', 'hl-core' ); ?></option>
                                    <?php foreach ( $enrollment_teachers[ $selected_enrollment_id ] as $teacher ) : ?>
                                        <option value="<?php echo esc_attr( $teacher['enrollment_id'] ); ?>"
                                                data-enrollment-id="<?php echo esc_attr( $teacher['enrollment_id'] ); ?>">
                                            <?php echo esc_html( $teacher['display_name'] ); ?>
                                            <?php if ( ! empty( $teacher['team_name'] ) ) : ?>
                                                (<?php echo esc_html( $teacher['team_name'] ); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ( count( $mentor_enrollments ) > 1 ) : ?>
                                <?php // Multiple enrollments: build all option groups and toggle with JS ?>
                                <select name="teacher_enrollment_id" id="teacher_enrollment_id" required class="hl-select">
                                    <option value=""><?php esc_html_e( '-- Select Teacher --', 'hl-core' ); ?></option>
                                    <?php foreach ( $enrollment_teachers as $eid => $teachers ) : ?>
                                        <?php foreach ( $teachers as $teacher ) : ?>
                                            <option value="<?php echo esc_attr( $teacher['enrollment_id'] ); ?>"
                                                    class="hl-teacher-option"
                                                    data-mentor-enrollment="<?php echo esc_attr( $eid ); ?>"
                                                    style="<?php echo ( $eid !== $selected_enrollment_id ) ? 'display:none;' : ''; ?>">
                                                <?php echo esc_html( $teacher['display_name'] ); ?>
                                                <?php if ( ! empty( $teacher['team_name'] ) ) : ?>
                                                    (<?php echo esc_html( $teacher['team_name'] ); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </select>
                                <script>
                                (function() {
                                    var mentorSelect = document.getElementById('mentor_enrollment_id');
                                    var teacherSelect = document.getElementById('teacher_enrollment_id');
                                    if (!mentorSelect || !teacherSelect) return;

                                    mentorSelect.addEventListener('change', function() {
                                        var selectedMentor = this.value;
                                        var options = teacherSelect.querySelectorAll('.hl-teacher-option');
                                        teacherSelect.value = '';

                                        for (var i = 0; i < options.length; i++) {
                                            if (options[i].getAttribute('data-mentor-enrollment') === selectedMentor) {
                                                options[i].style.display = '';
                                                options[i].disabled = false;
                                            } else {
                                                options[i].style.display = 'none';
                                                options[i].disabled = true;
                                            }
                                        }
                                    });
                                })();
                                </script>
                            <?php else : ?>
                                <p class="description"><?php esc_html_e( 'No team members found. You must be assigned to a team with members to create observations.', 'hl-core' ); ?></p>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e( 'Select the teacher you are observing.', 'hl-core' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="classroom_id"><?php esc_html_e( 'Classroom (optional)', 'hl-core' ); ?></label>
                        </th>
                        <td>
                            <select name="classroom_id" id="classroom_id" class="hl-select">
                                <option value=""><?php esc_html_e( '-- None / Select Later --', 'hl-core' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'The classroom where the observation takes place. Options appear after selecting a teacher.', 'hl-core' ); ?></p>
                            <?php
                            // Build a JS lookup of teacher_enrollment_id => classrooms
                            $teacher_classrooms_map = array();
                            foreach ( $enrollment_teachers as $eid => $teachers ) {
                                foreach ( $teachers as $teacher ) {
                                    $t_eid = absint( $teacher['enrollment_id'] );
                                    if ( ! isset( $teacher_classrooms_map[ $t_eid ] ) ) {
                                        $classrooms = $this->observation_service->get_teacher_classrooms( $t_eid );
                                        $teacher_classrooms_map[ $t_eid ] = $classrooms;
                                    }
                                }
                            }
                            ?>
                            <script>
                            (function() {
                                var classroomMap = <?php echo wp_json_encode( $teacher_classrooms_map ); ?>;
                                var teacherSelect = document.getElementById('teacher_enrollment_id');
                                var classroomSelect = document.getElementById('classroom_id');
                                if (!teacherSelect || !classroomSelect) return;

                                teacherSelect.addEventListener('change', function() {
                                    var teacherEid = this.value;
                                    // Remove all options except the first (blank)
                                    while (classroomSelect.options.length > 1) {
                                        classroomSelect.remove(1);
                                    }
                                    classroomSelect.value = '';

                                    if (teacherEid && classroomMap[teacherEid]) {
                                        var rooms = classroomMap[teacherEid];
                                        for (var i = 0; i < rooms.length; i++) {
                                            var opt = document.createElement('option');
                                            opt.value = rooms[i].classroom_id;
                                            opt.textContent = rooms[i].classroom_name;
                                            classroomSelect.appendChild(opt);
                                        }
                                    }
                                });
                            })();
                            </script>
                        </td>
                    </tr>
                </table>

                <div class="hl-form-actions">
                    <button type="submit" class="hl-btn hl-btn-primary">
                        <?php esc_html_e( 'Create Observation', 'hl-core' ); ?>
                    </button>
                    <a href="<?php echo esc_url( remove_query_arg( array( 'action', 'observation_id' ) ) ); ?>" class="hl-btn">
                        <?php esc_html_e( 'Cancel', 'hl-core' ); ?>
                    </a>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle the POST request to create a new observation record.
     *
     * Validates nonce and input, determines the track from the mentor
     * enrollment, and delegates to the observation service.
     *
     * @param int $user_id Current user ID.
     * @return int|WP_Error Observation ID on success, WP_Error on failure.
     */
    private function handle_create_observation( $user_id ) {
        // Verify nonce
        if ( ! isset( $_POST['_hl_observation_nonce'] )
             || ! wp_verify_nonce( $_POST['_hl_observation_nonce'], 'hl_create_observation' ) ) {
            return new WP_Error( 'nonce_failed', __( 'Security check failed. Please try again.', 'hl-core' ) );
        }

        $mentor_enrollment_id  = ! empty( $_POST['mentor_enrollment_id'] ) ? absint( $_POST['mentor_enrollment_id'] ) : 0;
        $teacher_enrollment_id = ! empty( $_POST['teacher_enrollment_id'] ) ? absint( $_POST['teacher_enrollment_id'] ) : 0;
        $classroom_id          = ! empty( $_POST['classroom_id'] ) ? absint( $_POST['classroom_id'] ) : 0;

        if ( ! $mentor_enrollment_id ) {
            return new WP_Error( 'missing_enrollment', __( 'Please select a track.', 'hl-core' ) );
        }

        if ( ! $teacher_enrollment_id ) {
            return new WP_Error( 'missing_teacher', __( 'Please select a teacher to observe.', 'hl-core' ) );
        }

        // Verify the mentor enrollment belongs to the current user
        if ( ! $this->observation_service->user_owns_enrollment( $user_id, $mentor_enrollment_id ) ) {
            return new WP_Error( 'not_authorized', __( 'You are not authorized to create observations for this enrollment.', 'hl-core' ) );
        }

        // Get the track_id and school_id from the mentor enrollment
        global $wpdb;
        $enrollment = $wpdb->get_row( $wpdb->prepare(
            "SELECT track_id, school_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $mentor_enrollment_id
        ), ARRAY_A );

        if ( ! $enrollment ) {
            return new WP_Error( 'invalid_enrollment', __( 'Mentor enrollment not found.', 'hl-core' ) );
        }

        return $this->observation_service->create_observation( array(
            'track_id'             => $enrollment['track_id'],
            'mentor_enrollment_id'  => $mentor_enrollment_id,
            'teacher_enrollment_id' => $teacher_enrollment_id,
            'classroom_id'          => $classroom_id ?: null,
            'school_id'             => ! empty( $enrollment['school_id'] ) ? $enrollment['school_id'] : null,
        ) );
    }

    // =========================================================================
    // 3. Form View (single observation)
    // =========================================================================

    /**
     * Render the form view for a single observation.
     *
     * If the observation is in 'draft' status and JFB is active, renders
     * the linked JFB form with hidden fields pre-populated. If already
     * submitted, shows a read-only summary. If JFB is not active, shows
     * a warning message.
     *
     * @param int $observation_id
     * @param int $user_id Current user ID.
     */
    private function render_form_view( $observation_id, $user_id ) {
        // Load the observation with joined data
        $observation = $this->observation_service->get_observation( $observation_id );

        if ( ! $observation ) {
            ?>
            <div class="hl-dashboard hl-observations">
                <div class="hl-notice hl-notice-error">
                    <?php esc_html_e( 'Observation not found.', 'hl-core' ); ?>
                </div>
                <p>
                    <a href="<?php echo esc_url( remove_query_arg( array( 'observation_id', 'action' ) ) ); ?>" class="hl-btn">
                        &larr; <?php esc_html_e( 'Back to Observations', 'hl-core' ); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }

        // Security: verify the current user is the mentor who created this,
        // or has manage_hl_core capability
        $is_owner = isset( $observation['mentor_user_id'] )
                    && (int) $observation['mentor_user_id'] === $user_id;
        $is_staff = current_user_can( 'manage_hl_core' );

        if ( ! $is_owner && ! $is_staff ) {
            ?>
            <div class="hl-dashboard hl-observations">
                <div class="hl-notice hl-notice-error">
                    <?php esc_html_e( 'You do not have permission to view this observation.', 'hl-core' ); ?>
                </div>
                <p>
                    <a href="<?php echo esc_url( remove_query_arg( array( 'observation_id', 'action' ) ) ); ?>" class="hl-btn">
                        &larr; <?php esc_html_e( 'Back to Observations', 'hl-core' ); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }

        // Observation context header
        ?>
        <div class="hl-dashboard hl-observations hl-observation-detail">
            <div class="hl-observation-header">
                <h2 class="hl-section-title">
                    <?php
                    if ( $observation['status'] === 'submitted' ) {
                        esc_html_e( 'Observation - Submitted', 'hl-core' );
                    } else {
                        esc_html_e( 'Observation - In Progress', 'hl-core' );
                    }
                    ?>
                </h2>
                <div class="hl-observation-meta">
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Teacher:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( ! empty( $observation['teacher_name'] ) ? $observation['teacher_name'] : __( 'N/A', 'hl-core' ) ); ?>
                    </span>
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Classroom:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( ! empty( $observation['classroom_name'] ) ? $observation['classroom_name'] : __( 'N/A', 'hl-core' ) ); ?>
                    </span>
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Track:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( ! empty( $observation['track_name'] ) ? $observation['track_name'] : __( 'N/A', 'hl-core' ) ); ?>
                    </span>
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Created:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( $this->format_date( $observation['created_at'] ) ); ?>
                    </span>
                    <span class="hl-meta-item">
                        <?php $this->render_status_badge( $observation['status'] ); ?>
                    </span>
                </div>
            </div>

            <?php
            if ( $observation['status'] === 'submitted' ) {
                $this->render_submitted_summary( $observation );
            } else {
                $this->render_jfb_form( $observation );
            }
            ?>

            <p>
                <a href="<?php echo esc_url( remove_query_arg( array( 'observation_id', 'action' ) ) ); ?>" class="hl-btn">
                    &larr; <?php esc_html_e( 'Back to Observations', 'hl-core' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render the JFB form for a draft observation.
     *
     * Finds the observation activity in the track, extracts the JFB form
     * ID from external_ref, and renders the form with hidden fields
     * pre-populated for the JFB hook listener.
     *
     * @param array $observation Observation row with joined data.
     */
    private function render_jfb_form( $observation ) {
        $jfb = HL_JFB_Integration::instance();

        // Check if JFB is active
        if ( ! $jfb->is_active() ) {
            ?>
            <div class="hl-notice hl-notice-warning">
                <?php esc_html_e( 'JetFormBuilder is required to fill out observations but is not currently active. Please contact your administrator.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        $track_id = absint( $observation['track_id'] );

        // Find the observation activity and form ID for this track
        $form_id     = $this->observation_service->get_observation_form_id( $track_id );
        $activity    = $this->observation_service->get_observation_activity( $track_id );
        $activity_id = $activity ? absint( $activity['activity_id'] ) : 0;

        if ( ! $form_id ) {
            ?>
            <div class="hl-notice hl-notice-warning">
                <?php esc_html_e( 'No observation form has been configured for this track. Please contact your track administrator.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        // Pre-populate hidden fields for the JFB hook listener
        $hidden_fields = array(
            'hl_observation_id' => absint( $observation['observation_id'] ),
            'hl_enrollment_id'  => absint( $observation['mentor_enrollment_id'] ),
            'hl_track_id'      => $track_id,
            'hl_activity_id'    => $activity_id,
        );

        // Render the JFB form
        echo $jfb->render_form( $form_id, $hidden_fields );
    }

    /**
     * Render a read-only summary for a submitted observation.
     *
     * Shows the submission date, JFB record reference, and context info.
     * Actual form responses are stored in JFB Form Records and viewable
     * via the JFB admin interface.
     *
     * @param array $observation Observation row with joined data.
     */
    private function render_submitted_summary( $observation ) {
        ?>
        <div class="hl-observation-summary">
            <div class="hl-notice hl-notice-success">
                <p>
                    <?php
                    printf(
                        /* translators: %s: formatted date of submission */
                        esc_html__( 'This observation was submitted on %s.', 'hl-core' ),
                        '<strong>' . esc_html( $this->format_date( $observation['submitted_at'] ) ) . '</strong>'
                    );
                    ?>
                </p>
            </div>

            <table class="hl-table widefat">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Mentor', 'hl-core' ); ?></th>
                        <td><?php echo esc_html( ! empty( $observation['mentor_name'] ) ? $observation['mentor_name'] : __( 'N/A', 'hl-core' ) ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Teacher Observed', 'hl-core' ); ?></th>
                        <td><?php echo esc_html( ! empty( $observation['teacher_name'] ) ? $observation['teacher_name'] : __( 'N/A', 'hl-core' ) ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Classroom', 'hl-core' ); ?></th>
                        <td><?php echo esc_html( ! empty( $observation['classroom_name'] ) ? $observation['classroom_name'] : __( 'N/A', 'hl-core' ) ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Track', 'hl-core' ); ?></th>
                        <td><?php echo esc_html( ! empty( $observation['track_name'] ) ? $observation['track_name'] : __( 'N/A', 'hl-core' ) ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Created', 'hl-core' ); ?></th>
                        <td><?php echo esc_html( $this->format_date( $observation['created_at'] ) ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Submitted', 'hl-core' ); ?></th>
                        <td><?php echo esc_html( $this->format_date( $observation['submitted_at'] ) ); ?></td>
                    </tr>
                    <?php if ( ! empty( $observation['jfb_record_id'] ) ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Form Record', 'hl-core' ); ?></th>
                            <td>
                                <?php
                                if ( current_user_can( 'manage_hl_core' ) ) {
                                    // Staff can link to the JFB record in admin
                                    $record_url = admin_url( 'admin.php?page=jet-form-builder-records&record_id=' . absint( $observation['jfb_record_id'] ) );
                                    printf(
                                        '<a href="%s" target="_blank">#%d</a>',
                                        esc_url( $record_url ),
                                        absint( $observation['jfb_record_id'] )
                                    );
                                } else {
                                    printf( '#%d', absint( $observation['jfb_record_id'] ) );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ( current_user_can( 'manage_hl_core' ) ) : ?>
                <p class="description">
                    <?php esc_html_e( 'Form responses are stored in JetFormBuilder Form Records. Use the JFB admin interface to view full response details.', 'hl-core' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Render a status badge with colour coding.
     *
     * @param string $status One of: draft, submitted.
     */
    private function render_status_badge( $status ) {
        $color = isset( self::$status_classes[ $status ] ) ? self::$status_classes[ $status ] : 'gray';
        $label = isset( self::$status_labels[ $status ] ) ? self::$status_labels[ $status ] : ucfirst( $status );
        printf(
            '<span class="hl-badge hl-badge-%s">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
    }

    /**
     * Format a date/datetime string for display using the WordPress date
     * format setting.
     *
     * @param string $date_string MySQL date or datetime string.
     * @return string Formatted date.
     */
    private function format_date( $date_string ) {
        if ( empty( $date_string ) ) {
            return '—';
        }
        $timestamp = strtotime( $date_string );
        if ( $timestamp === false ) {
            return $date_string;
        }
        return date_i18n( get_option( 'date_format', 'M j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $timestamp );
    }
}
