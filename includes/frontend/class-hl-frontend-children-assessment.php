<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_children_assessment] shortcode.
 *
 * Shows a logged-in teacher their children assessment instances across
 * all cohorts. When an instance_id is provided, renders the assessment
 * form (via HL_Instrument_Renderer) or a read-only summary if already
 * submitted.
 *
 * @package HL_Core
 */
class HL_Frontend_Children_Assessment {

    /** @var HL_Assessment_Service */
    private $assessment_service;

    /** @var HL_Classroom_Service */
    private $classroom_service;

    /**
     * Status badge CSS class mapping.
     */
    private static $status_classes = array(
        'not_started' => 'gray',
        'in_progress' => 'blue',
        'submitted'   => 'green',
    );

    /**
     * Status display labels.
     */
    private static $status_labels = array(
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'submitted'   => 'Submitted',
    );

    public function __construct() {
        $this->assessment_service = new HL_Assessment_Service();
        $this->classroom_service  = new HL_Classroom_Service();
    }

    /**
     * Render the Children Assessment shortcode.
     *
     * @param array $atts Shortcode attributes. Optional key: instance_id.
     * @return string HTML output.
     */
    public function render( $atts ) {
        $atts = shortcode_atts( array(
            'instance_id' => '',
        ), $atts, 'hl_children_assessment' );

        ob_start();

        // ── Must be logged in ────────────────────────────────────────
        if ( ! is_user_logged_in() ) {
            ?>
            <div class="hl-notice hl-notice-warning">
                <?php esc_html_e( 'Please log in to view your children assessments.', 'hl-core' ); ?>
            </div>
            <?php
            return ob_get_clean();
        }

        // ── Determine instance_id from atts or query string ──────────
        $instance_id = 0;
        if ( ! empty( $atts['instance_id'] ) ) {
            $instance_id = absint( $atts['instance_id'] );
        } elseif ( ! empty( $_GET['instance_id'] ) ) {
            $instance_id = absint( $_GET['instance_id'] );
        }

        // ── Route: list view vs. single instance view ────────────────
        if ( $instance_id > 0 ) {
            $this->render_single_instance( $instance_id );
        } else {
            $this->render_instance_list();
        }

        return ob_get_clean();
    }

    // =====================================================================
    // Instance List View
    // =====================================================================

    /**
     * Render a table listing all children assessment instances for the
     * currently logged-in teacher.
     */
    private function render_instance_list() {
        global $wpdb;

        $user_id = get_current_user_id();

        $instances = $wpdb->get_results( $wpdb->prepare(
            "SELECT cai.*, c.cohort_name, cr.classroom_name, e.user_id
             FROM {$wpdb->prefix}hl_children_assessment_instance cai
             JOIN {$wpdb->prefix}hl_enrollment e ON cai.enrollment_id = e.enrollment_id
             JOIN {$wpdb->prefix}hl_cohort c ON cai.cohort_id = c.cohort_id
             JOIN {$wpdb->prefix}hl_classroom cr ON cai.classroom_id = cr.classroom_id
             WHERE e.user_id = %d
             ORDER BY c.cohort_name, cr.classroom_name",
            $user_id
        ), ARRAY_A );

        ?>
        <div class="hl-dashboard hl-children-assessment">
            <h2 class="hl-section-title"><?php esc_html_e( 'My Children Assessments', 'hl-core' ); ?></h2>

            <?php if ( empty( $instances ) ) : ?>
                <div class="hl-empty-state">
                    <p><?php esc_html_e( 'You do not have any children assessment instances assigned. If you believe this is an error, please contact your cohort administrator.', 'hl-core' ); ?></p>
                </div>
            <?php else : ?>
                <table class="hl-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Cohort', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Classroom', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Age Band', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Submitted At', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'hl-core' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $instances as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row['cohort_name'] ); ?></td>
                                <td><?php echo esc_html( $row['classroom_name'] ); ?></td>
                                <td><?php echo esc_html( $row['instrument_age_band'] ? ucfirst( $row['instrument_age_band'] ) : __( 'N/A', 'hl-core' ) ); ?></td>
                                <td>
                                    <?php $this->render_status_badge( $row['status'] ); ?>
                                </td>
                                <td><?php echo esc_html( $row['submitted_at'] ? $this->format_date( $row['submitted_at'] ) : '—' ); ?></td>
                                <td>
                                    <?php
                                    $link_url = add_query_arg( 'instance_id', $row['instance_id'] );
                                    if ( $row['status'] === 'submitted' ) :
                                        ?>
                                        <a href="<?php echo esc_url( $link_url ); ?>" class="hl-btn hl-btn-small">
                                            <?php esc_html_e( 'View', 'hl-core' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url( $link_url ); ?>" class="hl-btn hl-btn-small hl-btn-primary">
                                            <?php esc_html_e( 'Fill Out', 'hl-core' ); ?>
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

    // =====================================================================
    // Single Instance View
    // =====================================================================

    /**
     * Render a single children assessment instance — either the editable
     * form or a read-only summary.
     *
     * @param int $instance_id
     */
    private function render_single_instance( $instance_id ) {
        $user_id = get_current_user_id();

        // ── Load instance with joined data ───────────────────────────
        $instance = $this->assessment_service->get_children_assessment( $instance_id );

        if ( ! $instance ) {
            ?>
            <div class="hl-notice hl-notice-error">
                <?php esc_html_e( 'Assessment instance not found.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        // ── Security: verify the current user owns the enrollment ────
        if ( (int) $instance['user_id'] !== $user_id ) {
            ?>
            <div class="hl-notice hl-notice-error">
                <?php esc_html_e( 'You do not have permission to access this assessment.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        // ── Submitted → read-only summary ────────────────────────────
        if ( $instance['status'] === 'submitted' ) {
            $this->render_submitted_summary( $instance );
            return;
        }

        // ── No instrument assigned → error ───────────────────────────
        if ( empty( $instance['instrument_id'] ) ) {
            ?>
            <div class="hl-notice hl-notice-error">
                <?php esc_html_e( 'No instrument assigned to this assessment. Please contact your cohort administrator.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        // ── Load the instrument ──────────────────────────────────────
        $instrument = $this->get_instrument( $instance['instrument_id'] );

        if ( ! $instrument ) {
            ?>
            <div class="hl-notice hl-notice-error">
                <?php esc_html_e( 'The assessment instrument could not be loaded. Please contact your cohort administrator.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        // ── Load children in the classroom ───────────────────────────
        $children = $this->classroom_service->get_children_in_classroom( $instance['classroom_id'] );

        if ( empty( $children ) ) {
            ?>
            <div class="hl-notice hl-notice-warning">
                <?php esc_html_e( 'No children are currently assigned to this classroom. Please contact your cohort administrator.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        // ── Load existing answers (childrows) ────────────────────────
        $childrows = $this->assessment_service->get_children_assessment_childrows( $instance_id );
        $answers_map = $this->build_answers_map( $childrows );

        // ── Handle POST submission ───────────────────────────────────
        $message      = '';
        $message_type = '';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['hl_instrument_instance_id'] ) ) {
            $posted_instance_id = absint( $_POST['hl_instrument_instance_id'] );

            if ( $posted_instance_id === $instance_id ) {
                // Verify nonce
                if ( ! isset( $_POST['_hl_assessment_nonce'] ) || ! wp_verify_nonce( $_POST['_hl_assessment_nonce'], 'hl_children_assessment_' . $instance_id ) ) {
                    $message      = __( 'Security check failed. Please try again.', 'hl-core' );
                    $message_type = 'error';
                } else {
                    // Determine action: draft or submit
                    $action = 'draft';
                    if ( ! empty( $_POST['hl_assessment_action'] ) && $_POST['hl_assessment_action'] === 'submit' ) {
                        $action = 'submit';
                    }

                    // Build childrows from POST data
                    $posted_answers = isset( $_POST['answers'] ) && is_array( $_POST['answers'] ) ? $_POST['answers'] : array();
                    $childrow_data  = array();

                    foreach ( $posted_answers as $child_id => $answers ) {
                        $child_id = absint( $child_id );
                        if ( $child_id <= 0 ) {
                            continue;
                        }

                        // Sanitize each answer value
                        $sanitized_answers = array();
                        if ( is_array( $answers ) ) {
                            foreach ( $answers as $question_id => $value ) {
                                $question_id = sanitize_text_field( $question_id );
                                if ( is_array( $value ) ) {
                                    $sanitized_answers[ $question_id ] = array_map( 'sanitize_text_field', $value );
                                } else {
                                    $sanitized_answers[ $question_id ] = sanitize_text_field( $value );
                                }
                            }
                        }

                        $childrow_data[] = array(
                            'child_id'     => $child_id,
                            'answers_json' => $sanitized_answers,
                        );
                    }

                    // Save via service
                    $result = $this->assessment_service->save_children_assessment( $instance_id, $childrow_data, $action );

                    if ( is_wp_error( $result ) ) {
                        $message      = $result->get_error_message();
                        $message_type = 'error';
                    } elseif ( $action === 'submit' ) {
                        $message      = __( 'Assessment submitted successfully.', 'hl-core' );
                        $message_type = 'success';

                        // Re-load instance to reflect submitted status
                        $instance = $this->assessment_service->get_children_assessment( $instance_id );

                        if ( $instance && $instance['status'] === 'submitted' ) {
                            // Show the submitted summary instead of the form
                            echo '<div class="hl-notice hl-notice-success"><p>' . esc_html( $message ) . '</p></div>';
                            $this->render_submitted_summary( $instance );
                            return;
                        }
                    } else {
                        $message      = __( 'Draft saved successfully.', 'hl-core' );
                        $message_type = 'success';

                        // Reload answers after saving draft
                        $childrows   = $this->assessment_service->get_children_assessment_childrows( $instance_id );
                        $answers_map = $this->build_answers_map( $childrows );
                    }
                }
            }
        }

        // ── Render the form ──────────────────────────────────────────
        $this->render_assessment_form( $instance, $instrument, $children, $answers_map, $message, $message_type );
    }

    // =====================================================================
    // Read-Only Submitted Summary
    // =====================================================================

    /**
     * Render a read-only summary of a submitted assessment.
     *
     * @param array $instance Instance data from get_children_assessment().
     */
    private function render_submitted_summary( $instance ) {
        $instance_id = absint( $instance['instance_id'] );

        // Load the instrument
        $instrument = null;
        $questions  = array();
        if ( ! empty( $instance['instrument_id'] ) ) {
            $instrument = $this->get_instrument( $instance['instrument_id'] );
            if ( $instrument ) {
                $questions = json_decode( $instrument['questions'], true );
                if ( ! is_array( $questions ) ) {
                    $questions = array();
                }
            }
        }

        // Load childrows with answers
        $childrows = $this->assessment_service->get_children_assessment_childrows( $instance_id );

        ?>
        <div class="hl-dashboard hl-children-assessment hl-assessment-summary">
            <div class="hl-assessment-header">
                <h2 class="hl-section-title"><?php esc_html_e( 'Children Assessment — Submitted', 'hl-core' ); ?></h2>
                <div class="hl-assessment-meta">
                    <?php if ( $instrument ) : ?>
                        <span class="hl-meta-item">
                            <strong><?php esc_html_e( 'Instrument:', 'hl-core' ); ?></strong>
                            <?php echo esc_html( $instrument['name'] ); ?>
                        </span>
                    <?php endif; ?>
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Cohort:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( $instance['cohort_name'] ); ?>
                    </span>
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Classroom:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( $instance['classroom_name'] ); ?>
                    </span>
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Submitted:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( $this->format_date( $instance['submitted_at'] ) ); ?>
                    </span>
                    <span class="hl-meta-item">
                        <?php $this->render_status_badge( 'submitted' ); ?>
                    </span>
                </div>
            </div>

            <?php if ( empty( $childrows ) ) : ?>
                <div class="hl-notice hl-notice-info">
                    <?php esc_html_e( 'No child responses recorded for this assessment.', 'hl-core' ); ?>
                </div>
            <?php elseif ( ! empty( $questions ) ) : ?>
                <div class="hl-instrument-matrix hl-readonly">
                    <table class="hl-table widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Child', 'hl-core' ); ?></th>
                                <?php foreach ( $questions as $question ) : ?>
                                    <th><?php echo esc_html( $question['prompt_text'] ); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $childrows as $row ) :
                                $child_name = trim( esc_html( $row['first_name'] ) . ' ' . esc_html( $row['last_name'] ) );
                                $answers    = json_decode( $row['answers_json'], true );
                                if ( ! is_array( $answers ) ) {
                                    $answers = array();
                                }
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $child_name ); ?></strong>
                                        <?php if ( ! empty( $row['child_display_code'] ) ) : ?>
                                            <br><small><?php echo esc_html( $row['child_display_code'] ); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ( $questions as $question ) :
                                        $qid   = isset( $question['question_id'] ) ? $question['question_id'] : '';
                                        $value = isset( $answers[ $qid ] ) ? $answers[ $qid ] : '';
                                    ?>
                                        <td><?php echo esc_html( is_array( $value ) ? implode( ', ', $value ) : $value ); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <?php // No instrument questions available — show raw answers ?>
                <div class="hl-instrument-matrix hl-readonly">
                    <table class="hl-table widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Child', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Answers', 'hl-core' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $childrows as $row ) :
                                $child_name = trim( esc_html( $row['first_name'] ) . ' ' . esc_html( $row['last_name'] ) );
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $child_name ); ?></strong>
                                        <?php if ( ! empty( $row['child_display_code'] ) ) : ?>
                                            <br><small><?php echo esc_html( $row['child_display_code'] ); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?php echo esc_html( $row['answers_json'] ); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p>
                <a href="<?php echo esc_url( remove_query_arg( 'instance_id' ) ); ?>" class="hl-btn">
                    &larr; <?php esc_html_e( 'Back to Assessments', 'hl-core' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // =====================================================================
    // Editable Assessment Form
    // =====================================================================

    /**
     * Render the editable assessment form (instrument matrix).
     *
     * If HL_Instrument_Renderer is available, delegates to it. Otherwise
     * falls back to an inline matrix rendering.
     *
     * @param array  $instance     Instance data.
     * @param array  $instrument   Instrument row from hl_instrument.
     * @param array  $children     Array of child objects from get_children_in_classroom().
     * @param array  $answers_map  Map of child_id => [ question_id => value ].
     * @param string $message      Flash message text.
     * @param string $message_type Flash message type (success|error).
     */
    private function render_assessment_form( $instance, $instrument, $children, $answers_map, $message = '', $message_type = '' ) {
        $instance_id = absint( $instance['instance_id'] );
        $questions   = json_decode( $instrument['questions'], true );
        if ( ! is_array( $questions ) ) {
            $questions = array();
        }

        ?>
        <div class="hl-dashboard hl-children-assessment hl-assessment-form">
            <div class="hl-assessment-header">
                <h2 class="hl-section-title"><?php esc_html_e( 'Children Assessment', 'hl-core' ); ?></h2>
                <div class="hl-assessment-meta">
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Instrument:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( $instrument['name'] ); ?>
                    </span>
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Cohort:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( $instance['cohort_name'] ); ?>
                    </span>
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Classroom:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( $instance['classroom_name'] ); ?>
                    </span>
                    <span class="hl-meta-item">
                        <?php $this->render_status_badge( $instance['status'] ); ?>
                    </span>
                </div>
            </div>

            <?php if ( ! empty( $message ) ) : ?>
                <div class="hl-notice hl-notice-<?php echo esc_attr( $message_type ); ?>">
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( empty( $questions ) ) : ?>
                <div class="hl-notice hl-notice-error">
                    <?php esc_html_e( 'The instrument has no questions defined. Please contact your cohort administrator.', 'hl-core' ); ?>
                </div>
            <?php else : ?>

                <?php
                // Attempt to use HL_Instrument_Renderer if available
                if ( class_exists( 'HL_Instrument_Renderer' ) ) :
                    $renderer = new HL_Instrument_Renderer();
                    echo $renderer->render( $instrument, $children, $answers_map, array(
                        'instance_id' => $instance_id,
                        'nonce'       => wp_create_nonce( 'hl_children_assessment_' . $instance_id ),
                    ) );
                else :
                    // Fallback: inline matrix rendering
                    $this->render_inline_matrix( $instance_id, $questions, $children, $answers_map );
                endif;
                ?>

            <?php endif; ?>

            <p>
                <a href="<?php echo esc_url( remove_query_arg( 'instance_id' ) ); ?>" class="hl-btn">
                    &larr; <?php esc_html_e( 'Back to Assessments', 'hl-core' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Fallback inline matrix form when HL_Instrument_Renderer is not yet
     * available. Renders a table with one row per child and columns per
     * question, with Save Draft and Submit buttons.
     *
     * @param int   $instance_id
     * @param array $questions    Decoded questions JSON array.
     * @param array $children     Array of child objects.
     * @param array $answers_map  Map of child_id => [ question_id => value ].
     */
    private function render_inline_matrix( $instance_id, $questions, $children, $answers_map ) {
        ?>
        <form method="post" class="hl-assessment-matrix-form">
            <?php wp_nonce_field( 'hl_children_assessment_' . $instance_id, '_hl_assessment_nonce' ); ?>
            <input type="hidden" name="hl_instrument_instance_id" value="<?php echo esc_attr( $instance_id ); ?>" />

            <div class="hl-instrument-matrix">
                <table class="hl-table widefat">
                    <thead>
                        <tr>
                            <th class="hl-child-column"><?php esc_html_e( 'Child', 'hl-core' ); ?></th>
                            <?php foreach ( $questions as $question ) : ?>
                                <th class="hl-question-column">
                                    <?php echo esc_html( $question['prompt_text'] ); ?>
                                    <?php if ( ! empty( $question['required'] ) ) : ?>
                                        <span class="hl-required" title="<?php esc_attr_e( 'Required', 'hl-core' ); ?>">*</span>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $children as $child ) :
                            $child_id   = absint( $child->child_id );
                            $child_name = trim( $child->first_name . ' ' . $child->last_name );
                            $child_answers = isset( $answers_map[ $child_id ] ) ? $answers_map[ $child_id ] : array();
                        ?>
                            <tr>
                                <td class="hl-child-column">
                                    <strong><?php echo esc_html( $child_name ); ?></strong>
                                    <?php if ( ! empty( $child->child_display_code ) ) : ?>
                                        <br><small class="hl-child-code"><?php echo esc_html( $child->child_display_code ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ( $questions as $question ) :
                                    $qid  = isset( $question['question_id'] ) ? $question['question_id'] : '';
                                    $type = isset( $question['type'] ) ? $question['type'] : 'text';
                                    $current_value = isset( $child_answers[ $qid ] ) ? $child_answers[ $qid ] : '';
                                    $field_name    = 'answers[' . $child_id . '][' . esc_attr( $qid ) . ']';
                                    $required      = ! empty( $question['required'] );
                                ?>
                                    <td class="hl-question-column">
                                        <?php $this->render_field( $field_name, $type, $question, $current_value, $required ); ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="hl-form-actions">
                <button type="submit" name="hl_assessment_action" value="draft" class="hl-btn hl-btn-secondary">
                    <?php esc_html_e( 'Save Draft', 'hl-core' ); ?>
                </button>
                <button type="submit" name="hl_assessment_action" value="submit" class="hl-btn hl-btn-primary"
                        onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to submit? You will not be able to edit answers after submission.', 'hl-core' ) ); ?>');">
                    <?php esc_html_e( 'Submit Assessment', 'hl-core' ); ?>
                </button>
            </div>
        </form>
        <?php
    }

    // =====================================================================
    // Field Rendering
    // =====================================================================

    /**
     * Render a single form field for the matrix.
     *
     * @param string $name          Field name attribute.
     * @param string $type          Question type: likert, text, number, single_select, multi_select.
     * @param array  $question      Full question definition.
     * @param mixed  $current_value Current answer value.
     * @param bool   $required      Whether the field is required.
     */
    private function render_field( $name, $type, $question, $current_value, $required ) {
        $req_attr = $required ? ' required' : '';

        switch ( $type ) {
            case 'likert':
                $allowed = isset( $question['allowed_values'] ) ? (array) $question['allowed_values'] : array();
                if ( empty( $allowed ) ) {
                    $allowed = array( '1', '2', '3', '4', '5' );
                }
                echo '<div class="hl-likert-group">';
                foreach ( $allowed as $val ) {
                    $checked = ( (string) $current_value === (string) $val ) ? ' checked' : '';
                    printf(
                        '<label class="hl-likert-option"><input type="radio" name="%s" value="%s"%s%s /> %s</label> ',
                        esc_attr( $name ),
                        esc_attr( $val ),
                        $checked,
                        $req_attr,
                        esc_html( $val )
                    );
                }
                echo '</div>';
                break;

            case 'number':
                printf(
                    '<input type="number" name="%s" value="%s" class="hl-input-number"%s />',
                    esc_attr( $name ),
                    esc_attr( $current_value ),
                    $req_attr
                );
                break;

            case 'single_select':
                $allowed = isset( $question['allowed_values'] ) ? (array) $question['allowed_values'] : array();
                printf( '<select name="%s" class="hl-select"%s>', esc_attr( $name ), $req_attr );
                echo '<option value="">' . esc_html__( '— Select —', 'hl-core' ) . '</option>';
                foreach ( $allowed as $val ) {
                    $selected = ( (string) $current_value === (string) $val ) ? ' selected' : '';
                    printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), $selected, esc_html( $val ) );
                }
                echo '</select>';
                break;

            case 'multi_select':
                $allowed  = isset( $question['allowed_values'] ) ? (array) $question['allowed_values'] : array();
                $selected_vals = is_array( $current_value ) ? $current_value : array();
                echo '<div class="hl-multi-select">';
                foreach ( $allowed as $val ) {
                    $checked = in_array( (string) $val, array_map( 'strval', $selected_vals ), true ) ? ' checked' : '';
                    printf(
                        '<label class="hl-checkbox-option"><input type="checkbox" name="%s[]" value="%s"%s /> %s</label> ',
                        esc_attr( $name ),
                        esc_attr( $val ),
                        $checked,
                        esc_html( $val )
                    );
                }
                echo '</div>';
                break;

            case 'text':
            default:
                printf(
                    '<input type="text" name="%s" value="%s" class="hl-input-text"%s />',
                    esc_attr( $name ),
                    esc_attr( $current_value ),
                    $req_attr
                );
                break;
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Load an instrument record by ID.
     *
     * @param int $instrument_id
     * @return array|null
     */
    private function get_instrument( $instrument_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_instrument WHERE instrument_id = %d",
            $instrument_id
        ), ARRAY_A );
    }

    /**
     * Build a lookup map from childrows: child_id => decoded answers.
     *
     * @param array $childrows Result from get_children_assessment_childrows().
     * @return array
     */
    private function build_answers_map( $childrows ) {
        $map = array();
        foreach ( $childrows as $row ) {
            $child_id = absint( $row['child_id'] );
            $answers  = json_decode( $row['answers_json'], true );
            $map[ $child_id ] = is_array( $answers ) ? $answers : array();
        }
        return $map;
    }

    /**
     * Render a status badge with colour coding.
     *
     * @param string $status One of: not_started, in_progress, submitted.
     */
    private function render_status_badge( $status ) {
        $color = isset( self::$status_classes[ $status ] ) ? self::$status_classes[ $status ] : 'gray';
        $label = isset( self::$status_labels[ $status ] ) ? self::$status_labels[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );
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
            return '';
        }
        $timestamp = strtotime( $date_string );
        if ( $timestamp === false ) {
            return $date_string;
        }
        return date_i18n( get_option( 'date_format', 'M j, Y' ), $timestamp );
    }
}
