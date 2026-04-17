<?php
/**
 * Survey service — trigger logic, pending queue, submission, validation.
 *
 * @package HL_Core
 */
if (!defined('ABSPATH')) exit;

class HL_Survey_Service {

    private $survey_repo;
    private $response_repo;

    public function __construct() {
        $this->survey_repo   = new HL_Survey_Repository();
        $this->response_repo = new HL_Survey_Response_Repository();
    }

    // ── Completion Gate ─────────────────────────────────────

    /**
     * Called from on_course_completed(). Returns true if survey gate was triggered
     * (meaning the caller should NOT mark the component complete).
     *
     * Steps:
     * 1. Check cycle has a survey_id
     * 2. Check catalog requires survey
     * 3. Check survey is published
     * 4. Check not already submitted
     * 5. TRANSACTION: INSERT pending first, THEN update component_state to survey_pending/100%
     *
     * @param int $enrollment_id
     * @param int $catalog_id
     * @param int $cycle_id
     * @param int $component_id
     * @param int $user_id
     * @return bool True if gate was triggered (caller should NOT mark complete).
     */
    public function check_survey_gate( $enrollment_id, $catalog_id, $cycle_id, $component_id, $user_id ) {
        global $wpdb;

        // 1. Does this cycle have a survey?
        $survey_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT survey_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
            $cycle_id
        ) );
        if ( ! $survey_id ) {
            return false;
        }

        // 2. Does this catalog entry require a survey?
        $requires = $wpdb->get_var( $wpdb->prepare(
            "SELECT requires_survey FROM {$wpdb->prefix}hl_course_catalog WHERE catalog_id = %d",
            $catalog_id
        ) );
        if ( ! $requires ) {
            return false;
        }

        // 3. Is the survey published?
        $survey = $this->survey_repo->get_by_id( $survey_id );
        if ( ! $survey || ! $survey->is_published() ) {
            return false;
        }

        // 4. Already submitted?
        if ( $this->response_repo->response_exists( $enrollment_id, $catalog_id, $survey_id ) ) {
            return false;
        }

        // 5+6. Atomic: INSERT pending first, THEN update state.
        // If INSERT fails, state is unchanged. If state update fails, pending row
        // is harmless (cleaned up by orphan resolver).
        $wpdb->query( 'START TRANSACTION' );

        $pending_result = $this->response_repo->insert_pending( array(
            'user_id'       => $user_id,
            'survey_id'     => $survey_id,
            'enrollment_id' => $enrollment_id,
            'catalog_id'    => $catalog_id,
            'cycle_id'      => $cycle_id,
            'component_id'  => $component_id,
        ) );

        if ( is_wp_error( $pending_result ) ) {
            $wpdb->query( 'ROLLBACK' );
            return false; // Don't gate — let normal completion proceed.
        }

        $state_table = $wpdb->prefix . 'hl_component_state';
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$state_table} WHERE enrollment_id = %d AND component_id = %d",
            $enrollment_id, $component_id
        ), ARRAY_A );

        if ( $existing ) {
            $state_result = $wpdb->update( $state_table, array(
                'completion_status'  => 'survey_pending',
                'completion_percent' => 100,
            ), array(
                'enrollment_id' => $enrollment_id,
                'component_id'  => $component_id,
            ) );
        } else {
            $state_result = $wpdb->insert( $state_table, array(
                'enrollment_id'      => $enrollment_id,
                'component_id'       => $component_id,
                'completion_status'  => 'survey_pending',
                'completion_percent' => 100,
            ) );
        }

        if ( $state_result === false ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        $wpdb->query( 'COMMIT' );
        return true;
    }

    // ── Submission ──────────────────────────────────────────

    /**
     * Validate and save a survey response.
     *
     * Returns response_id (int), 'already_submitted' (string), or WP_Error.
     *
     * @param int          $pending_id    Pending survey row ID.
     * @param array|string $raw_responses Raw response data (array or JSON string).
     * @return int|string|WP_Error
     */
    public function submit_response( $pending_id, $raw_responses ) {
        $pending = $this->response_repo->get_pending_by_id( $pending_id );
        if ( ! $pending ) {
            return new WP_Error( 'not_found', 'Pending survey not found.' );
        }

        $survey = $this->survey_repo->get_by_id( $pending['survey_id'] );
        if ( ! $survey ) {
            // Survey deleted — clean up and complete.
            $this->resolve_orphan_pending( $pending );
            return new WP_Error( 'survey_deleted', 'Survey no longer exists. Component marked complete.' );
        }

        if ( ! $survey->is_published() ) {
            $this->resolve_orphan_pending( $pending );
            return new WP_Error( 'survey_unpublished', 'Survey is no longer active. Component marked complete.' );
        }

        // Validate responses against survey definition.
        $validated = $this->validate_responses( $survey, $raw_responses );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        // Get enrollment language.
        global $wpdb;
        $language = $wpdb->get_var( $wpdb->prepare(
            "SELECT language_preference FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $pending['enrollment_id']
        ) ) ?: 'en';

        // Insert response + complete component + delete pending — in a transaction.
        $wpdb->query( 'START TRANSACTION' );

        $result = $this->response_repo->insert_response( array(
            'survey_id'      => $pending['survey_id'],
            'user_id'        => $pending['user_id'],
            'enrollment_id'  => $pending['enrollment_id'],
            'catalog_id'     => $pending['catalog_id'],
            'cycle_id'       => $pending['cycle_id'],
            'responses_json' => wp_json_encode( $validated ),
            'language'       => $language,
        ) );

        // Handle duplicate (double-submit from another tab).
        if ( is_wp_error( $result ) && $result->get_error_code() === 'duplicate_response' ) {
            $wpdb->query( 'COMMIT' ); // Commit the failed INSERT (no-op).
            $wpdb->query( 'START TRANSACTION' );
            $this->complete_component( $pending );
            $this->response_repo->delete_pending( $pending_id );
            $wpdb->query( 'COMMIT' );
            return 'already_submitted';
        }

        if ( is_wp_error( $result ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $result;
        }

        // Mark component complete and remove pending.
        $this->complete_component( $pending );
        $this->response_repo->delete_pending( $pending_id );
        $wpdb->query( 'COMMIT' );

        // Audit log.
        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'survey.submitted', array(
                'entity_type' => 'survey_response',
                'entity_id'   => $result,
                'after_data'  => array(
                    'survey_id'     => $pending['survey_id'],
                    'enrollment_id' => $pending['enrollment_id'],
                    'catalog_id'    => $pending['catalog_id'],
                    'response_id'   => $result,
                ),
            ) );
        }

        return $result;
    }

    // ── Validation ──────────────────────────────────────────

    /**
     * Validate raw responses against the survey question definitions.
     *
     * @param HL_Survey    $survey Survey domain model.
     * @param array|string $raw    Raw response data (array or JSON string).
     * @return array|WP_Error Validated array keyed by question_key, or WP_Error.
     */
    private function validate_responses( $survey, $raw ) {
        if ( ! is_array( $raw ) ) {
            $raw = json_decode( $raw, true );
        }
        if ( ! is_array( $raw ) || strlen( wp_json_encode( $raw ) ) > 65536 ) {
            return new WP_Error( 'invalid_payload', 'Invalid or oversized response payload.' );
        }

        $questions  = $survey->get_questions();
        $valid_keys = array_column( $questions, 'question_key' );
        $validated  = array();

        // Reject unknown keys.
        foreach ( array_keys( $raw ) as $key ) {
            if ( ! in_array( $key, $valid_keys, true ) ) {
                return new WP_Error( 'unknown_key', sprintf( 'Unknown question key: %s', $key ) );
            }
        }

        foreach ( $questions as $q ) {
            $key  = $q['question_key'];
            $type = $q['type'];
            $val  = isset( $raw[ $key ] ) ? $raw[ $key ] : null;

            if ( ! empty( $q['required'] ) && ( $val === null || $val === '' ) ) {
                return new WP_Error( 'required_missing', sprintf( 'Required field missing: %s', $key ) );
            }

            if ( $val === null || $val === '' ) {
                continue;
            }

            switch ( $type ) {
                case 'likert_5':
                    $int_val = (int) $val;
                    if ( $int_val < 1 || $int_val > 5 ) {
                        return new WP_Error( 'invalid_likert', sprintf( 'Likert value must be 1-5 for %s', $key ) );
                    }
                    $validated[ $key ] = $int_val;
                    break;

                case 'open_text':
                    $validated[ $key ] = wp_kses( (string) $val, array() );
                    break;

                case 'yes_no':
                    if ( ! in_array( $val, array( 'yes', 'no' ), true ) ) {
                        return new WP_Error( 'invalid_yes_no', sprintf( 'Yes/No value required for %s', $key ) );
                    }
                    $validated[ $key ] = $val;
                    break;

                default:
                    $validated[ $key ] = sanitize_text_field( (string) $val );
            }
        }

        return $validated;
    }

    // ── Pending Queue ──────────────────────────────────────

    /**
     * Get the next pending survey for a user.
     *
     * Uses a while loop (not recursion) to resolve orphan pending rows where
     * the survey has been deleted or unpublished. Returns null when no valid
     * pending survey remains.
     *
     * @param int $user_id
     * @return array|null Array with 'pending', 'survey', 'course_title', 'catalog_code' keys, or null.
     */
    public function get_next_pending_for_user( $user_id ) {
        // While loop instead of recursion to avoid stack overflow with many orphans.
        $max_iterations = 50;
        $i = 0;
        while ( $i++ < $max_iterations ) {
            $pending_list = $this->response_repo->get_pending_for_user( $user_id );
            if ( empty( $pending_list ) ) {
                return null;
            }

            $pending = $pending_list[0]; // FIFO — oldest first.
            $survey  = $this->survey_repo->get_by_id( $pending['survey_id'] );

            // If survey still valid, return it.
            if ( $survey && $survey->is_published() ) {
                return array(
                    'pending'      => $pending,
                    'survey'       => $survey,
                    'course_title' => isset( $pending['course_title'] ) ? $pending['course_title'] : '',
                    'catalog_code' => isset( $pending['catalog_code'] ) ? $pending['catalog_code'] : '',
                );
            }

            // Survey invalid — resolve orphan and loop to next.
            $this->resolve_orphan_pending( $pending );
        }
        return null;
    }

    // ── Admin: Duplicate ────────────────────────────────────

    /**
     * Duplicate a survey for creating a new version.
     *
     * Clones questions, scales, labels. Sets status to draft, appends " (Copy)" to name.
     * Version is auto-incremented per survey_type.
     *
     * @param int $survey_id
     * @return int|WP_Error New survey_id or WP_Error.
     */
    public function duplicate_survey( $survey_id ) {
        $survey = $this->survey_repo->get_by_id( $survey_id );
        if ( ! $survey ) {
            return new WP_Error( 'not_found', 'Survey not found.' );
        }

        $new_version = $this->survey_repo->get_next_version( $survey->survey_type );

        return $this->survey_repo->create( array(
            'internal_name'     => $survey->internal_name . ' (Copy)',
            'display_name'      => $survey->display_name,
            'survey_type'       => $survey->survey_type,
            'version'           => $new_version,
            'questions_json'    => $survey->questions_json,
            'scale_labels_json' => $survey->scale_labels_json,
            'intro_text_json'   => $survey->intro_text_json,
            'group_labels_json' => $survey->group_labels_json,
            'status'            => 'draft',
        ) );
    }

    // ── Private Helpers ─────────────────────────────────────

    /**
     * Mark a component as complete after survey submission or orphan resolution.
     *
     * Updates component_state to complete/100% with completed_at and last_computed_at,
     * then triggers rollup recomputation.
     *
     * @param array $pending Pending survey row (must have enrollment_id, component_id).
     */
    private function complete_component( $pending ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hl_component_state',
            array(
                'completion_status'  => 'complete',
                'completion_percent' => 100,
                'completed_at'       => current_time( 'mysql' ),
                'last_computed_at'   => current_time( 'mysql' ),
            ),
            array(
                'enrollment_id' => $pending['enrollment_id'],
                'component_id'  => $pending['component_id'],
            )
        );
        do_action( 'hl_core_recompute_rollups', $pending['enrollment_id'] );
    }

    /**
     * Resolve an orphan pending row (survey deleted/unpublished).
     *
     * Completes the component (so the user isn't stuck) and removes the pending row.
     *
     * @param array $pending Pending survey row.
     */
    private function resolve_orphan_pending( $pending ) {
        $this->complete_component( $pending );
        $this->response_repo->delete_pending( $pending['pending_id'] );
    }
}
