<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_child_assessment] shortcode.
 *
 * Shows a logged-in teacher their child assessment instances across
 * all tracks. When an instance_id is provided, renders the assessment
 * form (via HL_Instrument_Renderer) or a read-only summary if already
 * submitted.
 *
 * @package HL_Core
 */
class HL_Frontend_Child_Assessment {

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

        // AJAX draft save endpoint for "Missing a child?" flow.
        add_action( 'wp_ajax_hl_save_assessment_draft', array( $this, 'ajax_save_draft' ) );
    }

    /**
     * AJAX handler: save child assessment draft.
     */
    public function ajax_save_draft() {
        $instance_id = absint( $_POST['hl_instrument_instance_id'] ?? 0 );

        if ( ! $instance_id ) {
            wp_send_json_error( array( 'message' => 'Missing instance ID.' ) );
        }

        if ( ! wp_verify_nonce( $_POST['_hl_assessment_nonce'] ?? '', 'hl_child_assessment_' . $instance_id ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
        }

        $instance = $this->assessment_service->get_child_assessment( $instance_id );
        if ( ! $instance || (int) $instance['user_id'] !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $posted_answers = isset( $_POST['answers'] ) && is_array( $_POST['answers'] ) ? $_POST['answers'] : array();
        $childrow_data  = array();

        foreach ( $posted_answers as $child_id => $answers ) {
            $child_id = absint( $child_id );
            if ( $child_id <= 0 ) continue;

            $age_group     = isset( $answers['_age_group'] ) ? sanitize_text_field( $answers['_age_group'] ) : null;
            $instrument_id = isset( $answers['_instrument_id'] ) ? absint( $answers['_instrument_id'] ) : null;
            $is_skip       = isset( $answers['_skip'] ) && $answers['_skip'] === '1';
            $skip_reason   = isset( $answers['_skip_reason'] ) ? sanitize_text_field( $answers['_skip_reason'] ) : '';
            unset( $answers['_age_group'], $answers['_instrument_id'], $answers['_skip'], $answers['_skip_reason'] );

            $sanitized = array();
            if ( is_array( $answers ) ) {
                foreach ( $answers as $qid => $val ) {
                    $qid = sanitize_text_field( $qid );
                    $sanitized[ $qid ] = is_array( $val ) ? array_map( 'sanitize_text_field', $val ) : sanitize_text_field( $val );
                }
            }

            $childrow_data[] = array(
                'child_id'         => $child_id,
                'answers_json'     => $sanitized,
                'frozen_age_group' => $age_group,
                'instrument_id'    => $instrument_id,
                'status'           => $is_skip ? 'skipped' : null,
                'skip_reason'      => $is_skip ? $skip_reason : null,
            );
        }

        $result = $this->assessment_service->save_child_assessment( $instance_id, $childrow_data, 'draft' );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => 'Draft saved.' ) );
    }

    /**
     * Render the Child Assessment shortcode.
     *
     * @param array $atts Shortcode attributes. Optional key: instance_id.
     * @return string HTML output.
     */
    public function render( $atts ) {
        $atts = shortcode_atts( array(
            'instance_id' => '',
        ), $atts, 'hl_child_assessment' );

        ob_start();

        // ── Must be logged in ────────────────────────────────────────
        if ( ! is_user_logged_in() ) {
            ?>
            <div class="hl-notice hl-notice-warning">
                <?php esc_html_e( 'Please log in to view your child assessments.', 'hl-core' ); ?>
            </div>
            <?php
            return ob_get_clean();
        }

        // ── Determine instance_id from atts, query string, or activity_id ──
        $instance_id = 0;
        if ( ! empty( $atts['instance_id'] ) ) {
            $instance_id = absint( $atts['instance_id'] );
        } elseif ( ! empty( $_GET['instance_id'] ) ) {
            $instance_id = absint( $_GET['instance_id'] );
        } elseif ( ! empty( $_GET['activity_id'] ) ) {
            $instance_id = $this->resolve_instance_from_activity( absint( $_GET['activity_id'] ) );
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

        // ── Route: list view vs. single instance view ────────────────
        if ( $instance_id > 0 ) {
            $this->render_single_instance( $instance_id );
        } else {
            $this->render_instance_list();
        }

        return ob_get_clean();
    }

    /**
     * Resolve an activity_id to an existing (or newly created) instance_id.
     *
     * @param int $activity_id
     * @return int Instance ID, or 0 on failure.
     */
    private function resolve_instance_from_activity( $activity_id ) {
        global $wpdb;

        $user_id = get_current_user_id();

        $activity = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, p.track_id
             FROM {$wpdb->prefix}hl_activity a
             JOIN {$wpdb->prefix}hl_pathway p ON a.pathway_id = p.pathway_id
             WHERE a.activity_id = %d",
            $activity_id
        ) );

        if ( ! $activity || $activity->activity_type !== 'child_assessment' ) {
            return 0;
        }

        $enrollment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_enrollment
             WHERE track_id = %d AND user_id = %d AND status = 'active'
             LIMIT 1",
            $activity->track_id, $user_id
        ) );

        if ( ! $enrollment ) {
            return 0;
        }

        $ref   = json_decode( $activity->external_ref, true );
        $phase = isset( $ref['phase'] ) ? $ref['phase'] : 'pre';

        // Look for existing instance by activity_id
        $instance_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT instance_id FROM {$wpdb->prefix}hl_child_assessment_instance
             WHERE enrollment_id = %d AND activity_id = %d
             LIMIT 1",
            $enrollment->enrollment_id, $activity_id
        ) );

        if ( $instance_id ) {
            $this->backfill_instance_fields( absint( $instance_id ), $enrollment, $ref );
            return absint( $instance_id );
        }

        // Look for existing instance by enrollment + track + phase
        $instance_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT instance_id FROM {$wpdb->prefix}hl_child_assessment_instance
             WHERE enrollment_id = %d AND track_id = %d AND phase = %s
             LIMIT 1",
            $enrollment->enrollment_id, $activity->track_id, $phase
        ) );

        if ( $instance_id ) {
            $wpdb->update(
                $wpdb->prefix . 'hl_child_assessment_instance',
                array( 'activity_id' => $activity_id ),
                array( 'instance_id' => $instance_id )
            );
            $this->backfill_instance_fields( absint( $instance_id ), $enrollment, $ref );
            return absint( $instance_id );
        }

        // Resolve classroom, school, and instrument from teaching assignment
        $teaching = $wpdb->get_row( $wpdb->prepare(
            "SELECT ta.classroom_id, cr.school_id, cr.age_band
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_classroom cr ON ta.classroom_id = cr.classroom_id
             WHERE ta.enrollment_id = %d
             LIMIT 1",
            $enrollment->enrollment_id
        ) );

        $classroom_id = $teaching ? absint( $teaching->classroom_id ) : null;
        $school_id    = $teaching ? absint( $teaching->school_id ) : null;
        $age_band     = ( $teaching && ! empty( $teaching->age_band ) ) ? $teaching->age_band : null;

        // Resolve instrument: try activity external_ref first, then age_band lookup
        $instrument_id      = null;
        $instrument_version = null;

        if ( ! empty( $ref['instrument_id'] ) ) {
            $instrument_id = absint( $ref['instrument_id'] );
            $inst_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT version FROM {$wpdb->prefix}hl_instrument WHERE instrument_id = %d",
                $instrument_id
            ) );
            $instrument_version = $inst_row ? $inst_row->version : null;
        } elseif ( $age_band ) {
            $resolved = $this->resolve_children_instrument( $age_band );
            if ( $resolved ) {
                $instrument_id      = $resolved['instrument_id'];
                $instrument_version = $resolved['version'];
            }
        }

        // Create new instance for this activity
        $wpdb->insert( $wpdb->prefix . 'hl_child_assessment_instance', array(
            'instance_uuid'       => HL_DB_Utils::generate_uuid(),
            'track_id'           => absint( $activity->track_id ),
            'enrollment_id'       => absint( $enrollment->enrollment_id ),
            'activity_id'         => absint( $activity_id ),
            'classroom_id'        => $classroom_id,
            'school_id'           => $school_id,
            'phase'               => $phase,
            'instrument_age_band' => $age_band,
            'instrument_id'       => $instrument_id,
            'instrument_version'  => $instrument_version,
            'status'              => 'not_started',
        ) );

        return $wpdb->insert_id ? absint( $wpdb->insert_id ) : 0;
    }

    // =====================================================================
    // Instance List View
    // =====================================================================

    /**
     * Render a table listing all child assessment instances for the
     * currently logged-in teacher.
     */
    private function render_instance_list() {
        global $wpdb;

        $user_id = get_current_user_id();

        $instances = $wpdb->get_results( $wpdb->prepare(
            "SELECT cai.*, t.track_name, cr.classroom_name, e.user_id
             FROM {$wpdb->prefix}hl_child_assessment_instance cai
             JOIN {$wpdb->prefix}hl_enrollment e ON cai.enrollment_id = e.enrollment_id
             JOIN {$wpdb->prefix}hl_track t ON cai.track_id = t.track_id
             JOIN {$wpdb->prefix}hl_classroom cr ON cai.classroom_id = cr.classroom_id
             WHERE e.user_id = %d
             ORDER BY t.track_name, cr.classroom_name",
            $user_id
        ), ARRAY_A );

        ?>
        <div class="hl-dashboard hl-child-assessment">
            <h2 class="hl-section-title"><?php esc_html_e( 'My Child Assessments', 'hl-core' ); ?></h2>

            <?php if ( empty( $instances ) ) : ?>
                <div class="hl-empty-state">
                    <p><?php esc_html_e( 'You do not have any child assessment instances assigned. If you believe this is an error, please contact your track administrator.', 'hl-core' ); ?></p>
                </div>
            <?php else : ?>
                <table class="hl-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Track', 'hl-core' ); ?></th>
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
                                <td><?php echo esc_html( $row['track_name'] ); ?></td>
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
     * Render a single child assessment instance — either the editable
     * form or a read-only summary.
     *
     * @param int $instance_id
     */
    private function render_single_instance( $instance_id ) {
        $user_id = get_current_user_id();

        // ── Load instance with joined data ───────────────────────────
        $instance = $this->assessment_service->get_child_assessment( $instance_id );

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

        // ── Load children in the classroom (active only) ────────────
        $children = $this->classroom_service->get_children_in_classroom( $instance['classroom_id'] );

        if ( empty( $children ) ) {
            ?>
            <div class="hl-notice hl-notice-warning">
                <?php esc_html_e( 'No children are currently assigned to this classroom. Please contact your track administrator.', 'hl-core' ); ?>
            </div>
            <?php
            return;
        }

        // ── Roster reconciliation ─────────────────────────────────────
        // Ensure all active children have snapshots.
        $track_id = absint( $instance['track_id'] );
        foreach ( $children as $child ) {
            $child = (object) $child;
            if ( ! empty( $child->dob ) ) {
                HL_Child_Snapshot_Service::ensure_snapshot( $child->child_id, $track_id, $child->dob );
            }
        }

        // ── Load existing answers (childrows) ────────────────────────
        $childrows = $this->assessment_service->get_child_assessment_childrows( $instance_id );
        $answers_map = $this->build_answers_map( $childrows );

        // Reconciliation: mark removed children's draft rows as not_in_classroom.
        $active_child_ids = wp_list_pluck( $children, 'child_id' );
        foreach ( $childrows as $row ) {
            if ( ! in_array( (int) $row['child_id'], array_map( 'intval', $active_child_ids ), true ) ) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'hl_child_assessment_childrow',
                    array( 'status' => 'not_in_classroom' ),
                    array( 'row_id' => $row['row_id'] ),
                    array( '%s' ),
                    array( '%d' )
                );
            }
        }

        // ── Load instrument (single or per-age-group) ─────────────────
        $instrument = null;
        if ( ! empty( $instance['instrument_id'] ) ) {
            $instrument = $this->get_instrument( $instance['instrument_id'] );
        }

        // ── Handle POST submission ───────────────────────────────────
        $message      = '';
        $message_type = '';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['hl_instrument_instance_id'] ) ) {
            $posted_instance_id = absint( $_POST['hl_instrument_instance_id'] );

            if ( $posted_instance_id === $instance_id ) {
                // Verify nonce
                if ( ! isset( $_POST['_hl_assessment_nonce'] ) || ! wp_verify_nonce( $_POST['_hl_assessment_nonce'], 'hl_child_assessment_' . $instance_id ) ) {
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

                    // Re-check roster at submit time for race condition handling.
                    $current_active_ids = array_map( 'intval', wp_list_pluck(
                        $this->classroom_service->get_children_in_classroom( $instance['classroom_id'] ),
                        'child_id'
                    ) );

                    foreach ( $posted_answers as $child_id => $answers ) {
                        $child_id = absint( $child_id );
                        if ( $child_id <= 0 ) {
                            continue;
                        }

                        // Extract age group, instrument_id, and skip metadata.
                        $age_group     = isset( $answers['_age_group'] ) ? sanitize_text_field( $answers['_age_group'] ) : null;
                        $instrument_id = isset( $answers['_instrument_id'] ) ? absint( $answers['_instrument_id'] ) : null;
                        $is_skip       = isset( $answers['_skip'] ) && $answers['_skip'] === '1';
                        $skip_reason   = isset( $answers['_skip_reason'] ) ? sanitize_text_field( $answers['_skip_reason'] ) : '';
                        unset( $answers['_age_group'], $answers['_instrument_id'], $answers['_skip'], $answers['_skip_reason'] );

                        // Determine childrow status.
                        $childrow_status = 'active';
                        if ( $is_skip ) {
                            $childrow_status = 'skipped';
                        } elseif ( ! in_array( $child_id, $current_active_ids, true ) ) {
                            $childrow_status = 'stale_at_submit';
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
                            'child_id'         => $child_id,
                            'answers_json'     => $sanitized_answers,
                            'status'           => $childrow_status,
                            'skip_reason'      => $is_skip ? $skip_reason : null,
                            'frozen_age_group' => $age_group,
                            'instrument_id'    => $instrument_id,
                        );
                    }

                    // Save via service
                    $result = $this->assessment_service->save_child_assessment( $instance_id, $childrow_data, $action );

                    if ( is_wp_error( $result ) ) {
                        $message      = $result->get_error_message();
                        $message_type = 'error';
                    } elseif ( $action === 'submit' ) {
                        $message      = __( 'Assessment submitted successfully.', 'hl-core' );
                        $message_type = 'success';

                        // Re-load instance to reflect submitted status
                        $instance = $this->assessment_service->get_child_assessment( $instance_id );

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
                        $childrows   = $this->assessment_service->get_child_assessment_childrows( $instance_id );
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
     * @param array $instance Instance data from get_child_assessment().
     */
    private function render_submitted_summary( $instance ) {
        $instance_id = absint( $instance['instance_id'] );

        // Load childrows with answers.
        $childrows = $this->assessment_service->get_child_assessment_childrows( $instance_id );

        // Group childrows by frozen_age_group.
        $age_order  = array( 'infant', 'toddler', 'preschool', 'k2' );
        $groups     = array();
        $ungrouped  = array();
        foreach ( $childrows as $row ) {
            $group = ! empty( $row['frozen_age_group'] ) ? $row['frozen_age_group'] : '';
            if ( $group ) {
                $groups[ $group ][] = $row;
            } else {
                $ungrouped[] = $row;
            }
        }
        $has_groups = ! empty( $groups );

        // Load instruments + questions per age group.
        $group_questions = array();
        if ( $has_groups ) {
            foreach ( $groups as $grp => $rows ) {
                $instr_id = ! empty( $rows[0]['instrument_id'] ) ? absint( $rows[0]['instrument_id'] ) : null;
                if ( $instr_id ) {
                    $instr = $this->get_instrument( $instr_id );
                    if ( $instr ) {
                        $qs = json_decode( $instr['questions'], true );
                        $group_questions[ $grp ] = is_array( $qs ) ? $qs : array();
                    }
                }
            }
        }

        // Fallback: instance-level instrument for ungrouped rows or old data.
        $instrument = null;
        $questions  = array();
        if ( ! $has_groups || ! empty( $ungrouped ) ) {
            if ( ! empty( $instance['instrument_id'] ) ) {
                $instrument = $this->get_instrument( $instance['instrument_id'] );
                if ( $instrument ) {
                    $questions = json_decode( $instrument['questions'], true );
                    if ( ! is_array( $questions ) ) {
                        $questions = array();
                    }
                }
            }
        }

        // Likert label mapping.
        $likert_labels = array(
            '0' => 'Never', '1' => 'Rarely', '2' => 'Sometimes', '3' => 'Usually', '4' => 'Almost Always',
        );

        // Inline styles for submitted summary (reuse the renderer's design tokens)
        ?>
        <style>
            .hl-ca-summary-wrap {
                max-width: 900px;
                margin: 0 auto 2em;
                background: var(--hl-surface, #fff);
                border: 1px solid var(--hl-border, #E5E7EB);
                border-radius: var(--hl-radius, 12px);
                box-shadow: var(--hl-shadow, 0 2px 8px rgba(0,0,0,0.06));
                padding: 40px 48px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                color: var(--hl-text, #374151);
                line-height: 1.6;
            }
            .hl-ca-summary-wrap .hl-ca-branded-header {
                text-align: center;
                margin-bottom: 28px;
                padding-bottom: 24px;
                border-bottom: 2px solid var(--hl-border-light, #F3F4F6);
            }
            .hl-ca-summary-wrap .hl-ca-brand-logo {
                display: block;
                text-align: center;
                margin-bottom: 16px;
            }
            .hl-ca-summary-wrap .hl-ca-brand-img { max-width: 176px; height: auto; }
            .hl-ca-summary-wrap .hl-ca-title { font-size: 22px; font-weight: 700; color: var(--hl-text-heading, #1A2B47); margin: 0; }
            .hl-ca-summary-wrap .hl-ca-phase-label { color: var(--hl-text-secondary, #6B7280); font-weight: 400; }
            .hl-ca-summary-teacher-info { margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--hl-border-light, #F3F4F6); }
            .hl-ca-summary-teacher-info .hl-ca-info-row { margin-bottom: 6px; font-size: 15px; }
            .hl-ca-summary-teacher-info .hl-ca-info-label { font-weight: 700; color: var(--hl-text-heading, #1A2B47); margin-right: 6px; }
            .hl-ca-summary-teacher-info .hl-ca-info-value { color: var(--hl-text, #374151); }
            .hl-ca-submitted-banner { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: var(--hl-status-complete-bg, #D1FAE5); color: var(--hl-status-complete-text, #065F46); border-radius: var(--hl-radius-sm, 8px); margin-bottom: 24px; font-weight: 600; font-size: 14px; }
            .hl-ca-submitted-banner .hl-ca-submitted-icon { font-size: 18px; }
            .hl-ca-summary-matrix-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 16px; }
            table.hl-ca-summary-matrix { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 500px; }
            table.hl-ca-summary-matrix thead th { padding: 12px 16px; font-weight: 600; font-size: 13px; color: var(--hl-text-secondary, #6B7280); text-align: center; border-bottom: 2px solid var(--hl-border, #E5E7EB); white-space: nowrap; }
            table.hl-ca-summary-matrix thead th:first-child { text-align: left; min-width: 180px; }
            table.hl-ca-summary-matrix tbody td { padding: 10px 16px; vertical-align: middle; text-align: center; border-bottom: 1px solid var(--hl-border-light, #F3F4F6); }
            table.hl-ca-summary-matrix tbody td:first-child { text-align: left; font-weight: 500; color: var(--hl-text-heading, #1A2B47); white-space: nowrap; min-width: 180px; }
            table.hl-ca-summary-matrix tbody tr:nth-child(even) td { background-color: var(--hl-bg-alt, #FAFBFC); }
            table.hl-ca-summary-matrix tbody tr:nth-child(odd) td { background-color: var(--hl-surface, #fff); }
            .hl-ca-summary-child-dob { display: block; font-size: 12px; font-weight: 400; color: var(--hl-text-muted, #9CA3AF); }
            .hl-ca-answer-pill { display: inline-block; padding: 3px 12px; border-radius: var(--hl-radius-pill, 100px); background: var(--hl-bg, #F4F5F7); color: var(--hl-text, #374151); font-size: 13px; font-weight: 500; }
            .hl-ca-answer-dot { display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: var(--hl-primary, #1A2B47); }
            .hl-ca-answer-empty { color: var(--hl-text-muted, #9CA3AF); font-size: 13px; }
            .hl-ca-summary-group-header { font-size: 16px; font-weight: 700; color: var(--hl-primary, #1A2B47); margin: 24px 0 10px; padding: 8px 12px; background: var(--hl-bg-alt, #FAFBFC); border-radius: 8px; border-left: 4px solid var(--hl-secondary, #2C7BE5); }
            .hl-ca-summary-group-header:first-of-type { margin-top: 0; }
            .hl-ca-skip-badge { display: inline-block; padding: 2px 8px; border-radius: 100px; background: var(--hl-bg, #F4F5F7); color: var(--hl-text-muted, #9CA3AF); font-size: 11px; font-weight: 500; font-style: italic; }
            @media (max-width: 768px) {
                .hl-ca-summary-wrap { padding: 24px 20px; margin: 0 -10px 1.5em; }
                .hl-ca-summary-wrap .hl-ca-title { font-size: 18px; }
            }
        </style>

        <div class="hl-dashboard hl-child-assessment hl-assessment-summary">
            <div class="hl-ca-summary-wrap">

                <?php // ── Branded Header ──────────────────────────────── ?>
                <div class="hl-ca-branded-header">
                    <div class="hl-ca-brand-logo">
                        <img src="<?php echo esc_url( content_url( '/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg' ) ); ?>"
                             alt="<?php esc_attr_e( 'Housman Learning', 'hl-core' ); ?>"
                             class="hl-ca-brand-img" />
                    </div>
                    <h2 class="hl-ca-title">
                        <?php echo esc_html( $instrument ? $instrument['name'] : __( 'Child Assessment', 'hl-core' ) ); ?>
                        <?php
                        $phase = isset( $instance['phase'] ) ? $instance['phase'] : '';
                        if ( $phase ) :
                            $phase_label = ( $phase === 'post' ) ? __( 'Post', 'hl-core' ) : __( 'Pre', 'hl-core' );
                        ?>
                            <span class="hl-ca-phase-label">(<?php echo esc_html( $phase_label ); ?>)</span>
                        <?php endif; ?>
                    </h2>
                </div>

                <?php // ── Teacher / School / Classroom ────────────────── ?>
                <div class="hl-ca-summary-teacher-info">
                    <?php if ( ! empty( $instance['display_name'] ) ) : ?>
                        <div class="hl-ca-info-row">
                            <span class="hl-ca-info-label"><?php esc_html_e( 'Teacher:', 'hl-core' ); ?></span>
                            <span class="hl-ca-info-value"><?php echo esc_html( $instance['display_name'] ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $instance['school_name'] ) ) : ?>
                        <div class="hl-ca-info-row">
                            <span class="hl-ca-info-label"><?php esc_html_e( 'School:', 'hl-core' ); ?></span>
                            <span class="hl-ca-info-value"><?php echo esc_html( $instance['school_name'] ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $instance['classroom_name'] ) ) : ?>
                        <div class="hl-ca-info-row">
                            <span class="hl-ca-info-label"><?php esc_html_e( 'Classroom:', 'hl-core' ); ?></span>
                            <span class="hl-ca-info-value"><?php echo esc_html( $instance['classroom_name'] ); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php // ── Submitted Banner ────────────────────────────── ?>
                <div class="hl-ca-submitted-banner">
                    <span class="hl-ca-submitted-icon">&#10003;</span>
                    <?php
                    printf(
                        esc_html__( 'Assessment submitted on %s', 'hl-core' ),
                        esc_html( $this->format_date( $instance['submitted_at'] ) )
                    );
                    ?>
                </div>

                <?php if ( empty( $childrows ) ) : ?>
                    <div class="hl-notice hl-notice-info">
                        <?php esc_html_e( 'No child responses recorded for this assessment.', 'hl-core' ); ?>
                    </div>

                <?php elseif ( $has_groups ) : ?>
                    <?php // ── Age-group-grouped summary ── ?>
                    <?php foreach ( $age_order as $grp ) :
                        if ( ! isset( $groups[ $grp ] ) ) continue;
                        $grp_rows  = $groups[ $grp ];
                        $grp_qs    = isset( $group_questions[ $grp ] ) ? $group_questions[ $grp ] : array();
                        $grp_label = HL_Age_Group_Helper::get_label( $grp );
                        $grp_count = count( $grp_rows );
                    ?>
                        <h3 class="hl-ca-summary-group-header">
                            <?php
                            printf(
                                esc_html__( '%1$s (%2$d %3$s)', 'hl-core' ),
                                esc_html( $grp_label ),
                                $grp_count,
                                _n( 'child', 'children', $grp_count, 'hl-core' )
                            );
                            ?>
                        </h3>
                        <?php $this->render_summary_table( $grp_rows, $grp_qs, $likert_labels ); ?>
                    <?php endforeach; ?>

                    <?php if ( ! empty( $ungrouped ) ) : ?>
                        <h3 class="hl-ca-summary-group-header"><?php esc_html_e( 'Other', 'hl-core' ); ?></h3>
                        <?php $this->render_summary_table( $ungrouped, $questions, $likert_labels ); ?>
                    <?php endif; ?>

                <?php else : ?>
                    <?php // ── Legacy flat summary (no age groups stored) ── ?>
                    <?php $this->render_summary_table( $childrows, $questions, $likert_labels ); ?>
                <?php endif; ?>

            </div>

            <p style="margin-top: 16px;">
                <?php
                $back_url = $this->build_program_back_url( $instance );
                if ( $back_url ) : ?>
                    <a href="<?php echo esc_url( $back_url ); ?>" class="hl-btn">
                        &larr; <?php esc_html_e( 'Back to My Program', 'hl-core' ); ?>
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url( remove_query_arg( 'instance_id' ) ); ?>" class="hl-btn">
                        &larr; <?php esc_html_e( 'Back to Assessments', 'hl-core' ); ?>
                    </a>
                <?php endif; ?>
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
     * Delegates to HL_Instrument_Renderer for the branded form, including
     * header, teacher info, instructions, behavior key, and matrix.
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

        ?>
        <div class="hl-dashboard hl-child-assessment hl-assessment-form">

            <?php if ( ! empty( $message ) ) : ?>
                <div class="hl-notice hl-notice-<?php echo esc_attr( $message_type ); ?>">
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
            <?php endif; ?>

            <?php
            // Use multi-age-group renderer (per-child frozen age groups).
            $track_id     = absint( $instance['track_id'] );
            $classroom_id = absint( $instance['classroom_id'] );
            $renderer = HL_Instrument_Renderer::create_multi_age_group(
                $classroom_id,
                $track_id,
                $instance_id,
                $answers_map,
                $instance,
                $children
            );
            echo $renderer->render();
            ?>

            <p style="margin-top: 16px;">
                <?php
                $back_url = $this->build_program_back_url( $instance );
                if ( $back_url ) : ?>
                    <a href="<?php echo esc_url( $back_url ); ?>" class="hl-btn">
                        &larr; <?php esc_html_e( 'Back to My Program', 'hl-core' ); ?>
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url( remove_query_arg( 'instance_id' ) ); ?>" class="hl-btn">
                        &larr; <?php esc_html_e( 'Back to Assessments', 'hl-core' ); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    // =====================================================================
    // Summary Table Rendering
    // =====================================================================

    /**
     * Render a summary table for a set of childrows with given questions.
     *
     * Detects single-question Likert for transposed dot-matrix display.
     * Handles skipped children with a badge.
     *
     * @param array $rows           Childrow data with answers_json, first_name, etc.
     * @param array $questions      Parsed questions array for these rows.
     * @param array $likert_labels  Likert value-to-label mapping.
     */
    private function render_summary_table( $rows, $questions, $likert_labels ) {
        if ( empty( $rows ) ) {
            return;
        }

        // Detect single-question Likert for transposed display.
        $q_type = '';
        if ( count( $questions ) === 1 ) {
            $q_type = isset( $questions[0]['question_type'] ) ? $questions[0]['question_type'] : ( isset( $questions[0]['type'] ) ? $questions[0]['type'] : '' );
        }
        $is_single_likert = ( $q_type === 'likert' );

        // Resolve allowed values and label mode.
        $allowed_values    = array();
        $use_likert_labels = false;
        if ( $is_single_likert ) {
            $q = $questions[0];
            if ( isset( $q['allowed_values'] ) ) {
                if ( is_array( $q['allowed_values'] ) ) {
                    $allowed_values = $q['allowed_values'];
                } elseif ( is_string( $q['allowed_values'] ) && $q['allowed_values'] !== '' ) {
                    $allowed_values = array_map( 'trim', explode( ',', $q['allowed_values'] ) );
                }
            }
            if ( empty( $allowed_values ) ) {
                $allowed_values = array( '0', '1', '2', '3', '4' );
            }
            $use_likert_labels = true;
            foreach ( $allowed_values as $v ) {
                if ( ! isset( $likert_labels[ (string) $v ] ) ) {
                    $use_likert_labels = false;
                    break;
                }
            }
        }

        if ( $is_single_likert && ! empty( $allowed_values ) ) :
            // ── Transposed Likert summary ──
            $qid = $questions[0]['question_id'];
            ?>
            <div class="hl-ca-summary-matrix-wrap">
                <table class="hl-ca-summary-matrix">
                    <thead>
                        <tr>
                            <th>&nbsp;</th>
                            <?php foreach ( $allowed_values as $val ) : ?>
                                <th><?php echo esc_html( $use_likert_labels && isset( $likert_labels[ (string) $val ] ) ? $likert_labels[ (string) $val ] : $val ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) :
                            $first = isset( $row['first_name'] ) ? trim( $row['first_name'] ) : '';
                            $last  = isset( $row['last_name'] )  ? trim( $row['last_name'] )  : '';
                            if ( $first !== '' ) {
                                $child_label = $last !== '' ? $first . ' ' . mb_strtoupper( mb_substr( $last, 0, 1 ) ) . '.' : $first;
                            } else {
                                $child_label = ! empty( $row['child_display_code'] ) ? $row['child_display_code'] : __( 'Child', 'hl-core' );
                            }
                            $is_skipped  = ( isset( $row['status'] ) && $row['status'] === 'skipped' );
                            $answers     = json_decode( $row['answers_json'], true );
                            if ( ! is_array( $answers ) ) { $answers = array(); }
                            $answer_val = isset( $answers[ $qid ] ) ? $answers[ $qid ] : '';
                            $dob = '';
                            if ( ! empty( $row['dob'] ) ) {
                                $ts = strtotime( $row['dob'] );
                                if ( $ts ) { $dob = date( 'n/j/Y', $ts ); }
                            }
                        ?>
                            <tr<?php echo $is_skipped ? ' style="opacity:0.5;"' : ''; ?>>
                                <td>
                                    <?php echo esc_html( $child_label ); ?>
                                    <?php if ( $dob ) : ?>
                                        <span class="hl-ca-summary-child-dob">DOB: <?php echo esc_html( $dob ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <?php if ( $is_skipped ) : ?>
                                    <td colspan="<?php echo count( $allowed_values ); ?>" style="text-align:center;">
                                        <span class="hl-ca-skip-badge"><?php esc_html_e( 'Skipped', 'hl-core' ); ?></span>
                                    </td>
                                <?php else : ?>
                                    <?php foreach ( $allowed_values as $val ) : ?>
                                        <td>
                                            <?php if ( (string) $answer_val === (string) $val ) : ?>
                                                <span class="hl-ca-answer-dot" title="<?php echo esc_attr( $val ); ?>"></span>
                                            <?php else : ?>
                                                <span class="hl-ca-answer-empty">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ( ! empty( $questions ) ) : ?>
            <?php // ── Multi-question summary ── ?>
            <div class="hl-ca-summary-matrix-wrap">
                <table class="hl-ca-summary-matrix">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Child', 'hl-core' ); ?></th>
                            <?php foreach ( $questions as $question ) : ?>
                                <th><?php echo esc_html( $question['prompt_text'] ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) :
                            $first = isset( $row['first_name'] ) ? trim( $row['first_name'] ) : '';
                            $last  = isset( $row['last_name'] )  ? trim( $row['last_name'] )  : '';
                            if ( $first !== '' ) {
                                $child_label = $last !== '' ? $first . ' ' . mb_strtoupper( mb_substr( $last, 0, 1 ) ) . '.' : $first;
                            } else {
                                $child_label = ! empty( $row['child_display_code'] ) ? $row['child_display_code'] : __( 'Child', 'hl-core' );
                            }
                            $is_skipped  = ( isset( $row['status'] ) && $row['status'] === 'skipped' );
                            $answers     = json_decode( $row['answers_json'], true );
                            if ( ! is_array( $answers ) ) { $answers = array(); }
                        ?>
                            <tr<?php echo $is_skipped ? ' style="opacity:0.5;"' : ''; ?>>
                                <td><?php echo esc_html( $child_label ); ?></td>
                                <?php if ( $is_skipped ) : ?>
                                    <td colspan="<?php echo count( $questions ); ?>" style="text-align:center;">
                                        <span class="hl-ca-skip-badge"><?php esc_html_e( 'Skipped', 'hl-core' ); ?></span>
                                    </td>
                                <?php else : ?>
                                    <?php foreach ( $questions as $question ) :
                                        $qid   = isset( $question['question_id'] ) ? $question['question_id'] : '';
                                        $value = isset( $answers[ $qid ] ) ? $answers[ $qid ] : '';
                                    ?>
                                        <td>
                                            <?php if ( $value !== '' && $value !== null ) : ?>
                                                <span class="hl-ca-answer-pill"><?php echo esc_html( is_array( $value ) ? implode( ', ', $value ) : $value ); ?></span>
                                            <?php else : ?>
                                                <span class="hl-ca-answer-empty">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else : ?>
            <?php // ── No instrument questions — raw answers ── ?>
            <div class="hl-ca-summary-matrix-wrap">
                <table class="hl-ca-summary-matrix">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Child', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Answers', 'hl-core' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) :
                            $first = isset( $row['first_name'] ) ? trim( $row['first_name'] ) : '';
                            $last  = isset( $row['last_name'] )  ? trim( $row['last_name'] )  : '';
                            if ( $first !== '' ) {
                                $child_label = $last !== '' ? $first . ' ' . mb_strtoupper( mb_substr( $last, 0, 1 ) ) . '.' : $first;
                            } else {
                                $child_label = ! empty( $row['child_display_code'] ) ? $row['child_display_code'] : __( 'Child', 'hl-core' );
                            }
                        ?>
                            <tr>
                                <td><?php echo esc_html( $child_label ); ?></td>
                                <td><code><?php echo esc_html( $row['answers_json'] ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php
        endif;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Backfill missing classroom, school, and instrument fields on an existing instance.
     *
     * @param int    $instance_id
     * @param object $enrollment
     * @param array  $ref Decoded activity external_ref.
     */
    private function backfill_instance_fields( $instance_id, $enrollment, $ref ) {
        global $wpdb;

        $instance = $wpdb->get_row( $wpdb->prepare(
            "SELECT instrument_id, classroom_id, school_id FROM {$wpdb->prefix}hl_child_assessment_instance WHERE instance_id = %d",
            $instance_id
        ), ARRAY_A );

        if ( ! $instance ) {
            return;
        }

        // Skip if all fields are already populated.
        if ( ! empty( $instance['instrument_id'] ) && ! empty( $instance['classroom_id'] ) ) {
            return;
        }

        $updates  = array();
        $age_band = null;

        // Resolve classroom and school from teaching assignment.
        if ( empty( $instance['classroom_id'] ) ) {
            $teaching = $wpdb->get_row( $wpdb->prepare(
                "SELECT ta.classroom_id, cr.school_id, cr.age_band
                 FROM {$wpdb->prefix}hl_teaching_assignment ta
                 JOIN {$wpdb->prefix}hl_classroom cr ON ta.classroom_id = cr.classroom_id
                 WHERE ta.enrollment_id = %d
                 LIMIT 1",
                $enrollment->enrollment_id
            ) );

            if ( $teaching ) {
                $updates['classroom_id'] = absint( $teaching->classroom_id );
                if ( empty( $instance['school_id'] ) ) {
                    $updates['school_id'] = absint( $teaching->school_id );
                }
                $age_band = ! empty( $teaching->age_band ) ? $teaching->age_band : null;
                if ( $age_band ) {
                    $updates['instrument_age_band'] = $age_band;
                }
            }
        } else {
            // Classroom exists; fetch age_band for instrument resolution.
            $age_band = $wpdb->get_var( $wpdb->prepare(
                "SELECT age_band FROM {$wpdb->prefix}hl_classroom WHERE classroom_id = %d",
                $instance['classroom_id']
            ) );
            if ( empty( $age_band ) ) {
                $age_band = null;
            }
        }

        // Resolve instrument if missing.
        if ( empty( $instance['instrument_id'] ) ) {
            if ( ! empty( $ref['instrument_id'] ) ) {
                $updates['instrument_id'] = absint( $ref['instrument_id'] );
                $version = $wpdb->get_var( $wpdb->prepare(
                    "SELECT version FROM {$wpdb->prefix}hl_instrument WHERE instrument_id = %d",
                    $ref['instrument_id']
                ) );
                if ( $version ) {
                    $updates['instrument_version'] = $version;
                }
            } elseif ( ! empty( $age_band ) ) {
                $resolved = $this->resolve_children_instrument( $age_band );
                if ( $resolved ) {
                    $updates['instrument_id']      = $resolved['instrument_id'];
                    $updates['instrument_version'] = $resolved['version'];
                }
            }
        }

        if ( ! empty( $updates ) ) {
            $wpdb->update(
                $wpdb->prefix . 'hl_child_assessment_instance',
                $updates,
                array( 'instance_id' => $instance_id )
            );
        }
    }

    /**
     * Resolve a children instrument by age band.
     *
     * Tries the exact type (e.g. children_mixed), then falls back to
     * children_preschool, then any available children_* instrument.
     *
     * @param string $age_band e.g. 'infant', 'toddler', 'preschool', 'mixed'.
     * @return array|null Array with 'instrument_id' and 'version', or null.
     */
    private function resolve_children_instrument( $age_band ) {
        global $wpdb;

        $candidates = array( 'children_' . $age_band );
        if ( $age_band === 'mixed' ) {
            $candidates[] = 'children_preschool';
        }
        // Final fallback: any active children instrument.
        $candidates[] = '__any__';

        foreach ( $candidates as $type ) {
            if ( $type === '__any__' ) {
                $row = $wpdb->get_row(
                    "SELECT instrument_id, version FROM {$wpdb->prefix}hl_instrument
                     WHERE instrument_type LIKE 'children_%'
                     AND (effective_to IS NULL OR effective_to >= CURDATE())
                     ORDER BY effective_from DESC LIMIT 1"
                );
            } else {
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT instrument_id, version FROM {$wpdb->prefix}hl_instrument
                     WHERE instrument_type = %s
                     AND (effective_to IS NULL OR effective_to >= CURDATE())
                     ORDER BY effective_from DESC LIMIT 1",
                    $type
                ) );
            }
            if ( $row ) {
                return array(
                    'instrument_id' => absint( $row->instrument_id ),
                    'version'       => $row->version,
                );
            }
        }

        return null;
    }

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
     * @param array $childrows Result from get_child_assessment_childrows().
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
    private function find_shortcode_page_url( $shortcode ) {
        global $wpdb;
        $page_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like( $shortcode ) . '%'
        ) );
        return $page_id ? get_permalink( $page_id ) : '';
    }

    private function build_program_back_url( $instance ) {
        global $wpdb;

        $activity_id   = isset( $instance['activity_id'] ) ? absint( $instance['activity_id'] ) : 0;
        $enrollment_id = isset( $instance['enrollment_id'] ) ? absint( $instance['enrollment_id'] ) : 0;

        if ( ! $activity_id || ! $enrollment_id ) {
            return '';
        }

        $pathway_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT pathway_id FROM {$wpdb->prefix}hl_activity WHERE activity_id = %d",
            $activity_id
        ) );

        if ( ! $pathway_id ) {
            return '';
        }

        $program_url = $this->find_shortcode_page_url( 'hl_program_page' );
        if ( empty( $program_url ) ) {
            return '';
        }

        return add_query_arg( array(
            'id'         => $pathway_id,
            'enrollment' => $enrollment_id,
        ), $program_url );
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
