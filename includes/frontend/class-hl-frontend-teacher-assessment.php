<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_teacher_assessment] shortcode.
 *
 * Shows a logged-in teacher their self-assessment instances across
 * all cohorts. When an instance_id is provided, renders the assessment
 * form (via HL_Teacher_Assessment_Renderer) or a read-only summary if
 * already submitted.
 *
 * @package HL_Core
 */
class HL_Frontend_Teacher_Assessment {

    /** @var HL_Assessment_Service */
    private $assessment_service;

    private static $status_classes = array(
        'not_started' => 'gray',
        'in_progress' => 'blue',
        'submitted'   => 'green',
    );

    private static $status_labels = array(
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'submitted'   => 'Submitted',
    );

    public function __construct() {
        $this->assessment_service = new HL_Assessment_Service();
    }

    /**
     * Render the Teacher Self-Assessment shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render( $atts ) {
        $atts = shortcode_atts( array(
            'instance_id' => '',
        ), $atts, 'hl_teacher_assessment' );

        ob_start();

        if ( ! is_user_logged_in() ) {
            ?>
            <div class="hl-notice hl-notice-warning">
                <?php esc_html_e( 'Please log in to view your self-assessments.', 'hl-core' ); ?>
            </div>
            <?php
            return ob_get_clean();
        }

        // Determine instance_id from atts or query string
        $instance_id = 0;
        if ( ! empty( $atts['instance_id'] ) ) {
            $instance_id = absint( $atts['instance_id'] );
        } elseif ( ! empty( $_GET['instance_id'] ) ) {
            $instance_id = absint( $_GET['instance_id'] );
        }

        // Flash messages from redirect
        if ( ! empty( $_GET['message'] ) ) {
            $msg_key = sanitize_text_field( $_GET['message'] );
            if ( $msg_key === 'submitted' ) {
                echo '<div class="hl-notice hl-notice-success"><p>' . esc_html__( 'Assessment submitted successfully.', 'hl-core' ) . '</p></div>';
            } elseif ( $msg_key === 'saved' ) {
                echo '<div class="hl-notice hl-notice-success"><p>' . esc_html__( 'Draft saved successfully.', 'hl-core' ) . '</p></div>';
            }
        }

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

    private function render_instance_list() {
        global $wpdb;

        $user_id = get_current_user_id();

        // Get all teacher assessment instances for this user that use custom instruments
        $instances = $wpdb->get_results( $wpdb->prepare(
            "SELECT tai.*, c.cohort_name, e.user_id
             FROM {$wpdb->prefix}hl_teacher_assessment_instance tai
             JOIN {$wpdb->prefix}hl_enrollment e ON tai.enrollment_id = e.enrollment_id
             JOIN {$wpdb->prefix}hl_cohort c ON tai.cohort_id = c.cohort_id
             WHERE e.user_id = %d
               AND tai.instrument_id IS NOT NULL
             ORDER BY c.cohort_name, tai.phase ASC",
            $user_id
        ), ARRAY_A );

        ?>
        <div class="hl-dashboard hl-teacher-assessment">
            <h2 class="hl-section-title"><?php esc_html_e( 'My Self-Assessments', 'hl-core' ); ?></h2>

            <?php if ( empty( $instances ) ) : ?>
                <div class="hl-empty-state">
                    <p><?php esc_html_e( 'You do not have any self-assessment instances assigned. If you believe this is an error, please contact your cohort administrator.', 'hl-core' ); ?></p>
                </div>
            <?php else : ?>
                <table class="hl-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Program', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Phase', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Submitted At', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'hl-core' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $instances as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row['cohort_name'] ); ?></td>
                                <td>
                                    <span class="hl-badge hl-badge-<?php echo $row['phase'] === 'pre' ? 'blue' : 'green'; ?>">
                                        <?php echo esc_html( $row['phase'] === 'pre' ? __( 'Pre-Program', 'hl-core' ) : __( 'Post-Program', 'hl-core' ) ); ?>
                                    </span>
                                </td>
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
                                    <?php elseif ( $row['status'] === 'in_progress' ) : ?>
                                        <a href="<?php echo esc_url( $link_url ); ?>" class="hl-btn hl-btn-small hl-btn-primary">
                                            <?php esc_html_e( 'Continue', 'hl-core' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url( $link_url ); ?>" class="hl-btn hl-btn-small hl-btn-primary">
                                            <?php esc_html_e( 'Start', 'hl-core' ); ?>
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

    private function render_single_instance( $instance_id ) {
        $user_id = get_current_user_id();

        // Load instance with joined data
        $instance = $this->assessment_service->get_teacher_assessment( $instance_id );

        if ( ! $instance ) {
            ?>
            <div class="hl-notice hl-notice-error">
                <?php esc_html_e( 'Assessment instance not found.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        // Security: verify the current user owns the enrollment
        if ( (int) $instance['user_id'] !== $user_id ) {
            ?>
            <div class="hl-notice hl-notice-error">
                <?php esc_html_e( 'You do not have permission to access this assessment.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        // No instrument assigned → not a custom instrument assessment
        if ( empty( $instance['instrument_id'] ) ) {
            ?>
            <div class="hl-notice hl-notice-error">
                <?php esc_html_e( 'No instrument assigned to this assessment. Please contact your cohort administrator.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        // Load the teacher assessment instrument
        $instrument = $this->assessment_service->get_teacher_instrument( absint( $instance['instrument_id'] ) );

        if ( ! $instrument ) {
            ?>
            <div class="hl-notice hl-notice-error">
                <?php esc_html_e( 'The assessment instrument could not be loaded. Please contact your cohort administrator.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        $phase = $instance['phase'];

        // Decode existing responses for this instance
        $existing_responses = array();
        if ( ! empty( $instance['responses_json'] ) ) {
            $decoded = json_decode( $instance['responses_json'], true );
            $existing_responses = is_array( $decoded ) ? $decoded : array();
        }

        // For POST phase, get PRE responses for "Before" column
        $pre_responses = array();
        if ( $phase === 'post' ) {
            $pre_responses = $this->assessment_service->get_pre_responses_for_post(
                absint( $instance['enrollment_id'] ),
                absint( $instance['cohort_id'] )
            );
        }

        // ── Handle POST submission ───────────────────────────────────
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['hl_tsa_instance_id'] ) ) {
            $posted_instance_id = absint( $_POST['hl_tsa_instance_id'] );

            if ( $posted_instance_id === $instance_id ) {
                if ( ! isset( $_POST['hl_teacher_assessment_nonce'] )
                    || ! wp_verify_nonce( $_POST['hl_teacher_assessment_nonce'], 'hl_save_teacher_assessment' ) ) {
                    echo '<div class="hl-notice hl-notice-error"><p>' . esc_html__( 'Security check failed. Please try again.', 'hl-core' ) . '</p></div>';
                } else {
                    $action_type = ! empty( $_POST['hl_tsa_action'] ) ? sanitize_text_field( $_POST['hl_tsa_action'] ) : 'draft';
                    $is_draft    = ( $action_type !== 'submit' );

                    // Sanitize responses recursively
                    $raw_resp = isset( $_POST['resp'] ) && is_array( $_POST['resp'] ) ? $_POST['resp'] : array();
                    $sanitized = $this->sanitize_responses( $raw_resp );

                    $result = $this->assessment_service->save_teacher_assessment_responses(
                        $instance_id,
                        $sanitized,
                        $is_draft
                    );

                    if ( is_wp_error( $result ) ) {
                        echo '<div class="hl-notice hl-notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
                    } else {
                        // Redirect to avoid double-submit
                        $redirect_url = add_query_arg( array(
                            'instance_id' => $instance_id,
                            'message'     => $is_draft ? 'saved' : 'submitted',
                        ) );
                        // Remove message from redirect URL base
                        $redirect_url = remove_query_arg( 'message', $redirect_url );
                        $redirect_url = add_query_arg( 'message', $is_draft ? 'saved' : 'submitted', $redirect_url );
                        echo '<script>window.location.href = ' . wp_json_encode( $redirect_url ) . ';</script>';
                        return;
                    }
                }
            }
        }

        // ── Render form or read-only ──────────────────────────────────
        $is_submitted = ( $instance['status'] === 'submitted' );

        ?>
        <div class="hl-dashboard hl-teacher-assessment">
            <?php if ( $is_submitted ) : ?>
                <?php $this->render_submitted_summary( $instance, $instrument, $existing_responses, $pre_responses ); ?>
            <?php else : ?>
                <div class="hl-assessment-meta">
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Program:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( $instance['cohort_name'] ); ?>
                    </span>
                    <span class="hl-meta-item">
                        <?php $this->render_status_badge( $instance['status'] ); ?>
                    </span>
                </div>

                <?php
                $renderer = new HL_Teacher_Assessment_Renderer(
                    $instrument,
                    (object) $instance,
                    $phase,
                    $existing_responses,
                    $pre_responses,
                    false
                );
                echo $renderer->render();
                ?>
            <?php endif; ?>

            <p>
                <a href="<?php echo esc_url( remove_query_arg( array( 'instance_id', 'message' ) ) ); ?>" class="hl-btn">
                    &larr; <?php esc_html_e( 'Back to Self-Assessments', 'hl-core' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // =====================================================================
    // Read-Only Submitted Summary
    // =====================================================================

    private function render_submitted_summary( $instance, $instrument, $existing_responses, $pre_responses ) {
        $phase_label = ( $instance['phase'] === 'post' )
            ? __( 'Post-Program Self-Assessment', 'hl-core' )
            : __( 'Pre-Program Self-Assessment', 'hl-core' );

        ?>
        <div class="hl-assessment-header">
            <h2 class="hl-section-title"><?php echo esc_html( $phase_label ); ?> — <?php esc_html_e( 'Submitted', 'hl-core' ); ?></h2>
            <div class="hl-assessment-meta">
                <span class="hl-meta-item">
                    <strong><?php esc_html_e( 'Instrument:', 'hl-core' ); ?></strong>
                    <?php echo esc_html( $instrument->instrument_name ); ?>
                </span>
                <span class="hl-meta-item">
                    <strong><?php esc_html_e( 'Program:', 'hl-core' ); ?></strong>
                    <?php echo esc_html( $instance['cohort_name'] ); ?>
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

        <?php
        // Render the instrument in read-only mode
        $renderer = new HL_Teacher_Assessment_Renderer(
            $instrument,
            (object) $instance,
            $instance['phase'],
            $existing_responses,
            $pre_responses,
            true // read-only
        );
        echo $renderer->render();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Recursively sanitize the responses array.
     *
     * Expected structure: [ section_key => [ item_key => value_or_array ] ]
     *
     * @param array $raw
     * @return array
     */
    private function sanitize_responses( $raw ) {
        $clean = array();
        foreach ( $raw as $section_key => $items ) {
            $section_key = sanitize_text_field( $section_key );
            if ( ! is_array( $items ) ) {
                continue;
            }
            $clean[ $section_key ] = array();
            foreach ( $items as $item_key => $value ) {
                $item_key = sanitize_text_field( $item_key );
                if ( is_array( $value ) ) {
                    // POST phase: ['now' => X] or similar sub-keys
                    $clean[ $section_key ][ $item_key ] = array_map( 'sanitize_text_field', $value );
                } else {
                    $clean[ $section_key ][ $item_key ] = sanitize_text_field( $value );
                }
            }
        }
        return $clean;
    }

    private function render_status_badge( $status ) {
        $color = isset( self::$status_classes[ $status ] ) ? self::$status_classes[ $status ] : 'gray';
        $label = isset( self::$status_labels[ $status ] ) ? self::$status_labels[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );
        printf(
            '<span class="hl-badge hl-badge-%s">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
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
}
