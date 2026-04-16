<?php
/**
 * Repository for hl_course_survey_response and hl_pending_survey tables.
 */
if (!defined('ABSPATH')) exit;

class HL_Survey_Response_Repository {

    private function response_table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_course_survey_response';
    }

    private function pending_table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_pending_survey';
    }

    // ── Responses ───────────────────────────────────────────

    public function insert_response( $data ) {
        global $wpdb;
        if ( empty( $data['response_uuid'] ) ) {
            $data['response_uuid'] = HL_DB_Utils::generate_uuid();
        }
        $result = $wpdb->insert( $this->response_table(), $data );
        if ( false === $result ) {
            // Check for duplicate entry.
            if ( strpos( $wpdb->last_error, 'Duplicate entry' ) !== false ) {
                return new WP_Error( 'duplicate_response', 'Survey already submitted for this course.' );
            }
            return new WP_Error( 'insert_failed', $wpdb->last_error );
        }
        return $wpdb->insert_id;
    }

    public function response_exists( $enrollment_id, $catalog_id, $survey_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT EXISTS(SELECT 1 FROM {$this->response_table()} WHERE enrollment_id = %d AND catalog_id = %d AND survey_id = %d)",
            $enrollment_id, $catalog_id, $survey_id
        ) );
    }

    public function get_responses_for_report( $survey_id, $filters = array() ) {
        global $wpdb;
        $sql  = "SELECT r.*, e.language_preference, u.display_name AS participant_name, u.user_email,
                        c.catalog_code, c.title AS course_title
                 FROM {$this->response_table()} r
                 JOIN {$wpdb->prefix}hl_enrollment e ON e.enrollment_id = r.enrollment_id
                 JOIN {$wpdb->users} u ON u.ID = r.user_id
                 JOIN {$wpdb->prefix}hl_course_catalog c ON c.catalog_id = r.catalog_id
                 WHERE r.survey_id = %d";
        $args = array( $survey_id );

        if ( ! empty( $filters['cycle_ids'] ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $filters['cycle_ids'] ), '%d' ) );
            $sql .= " AND r.cycle_id IN ({$placeholders})";
            $args = array_merge( $args, array_map( 'absint', $filters['cycle_ids'] ) );
        }
        if ( ! empty( $filters['catalog_id'] ) ) {
            $sql .= ' AND r.catalog_id = %d';
            $args[] = absint( $filters['catalog_id'] );
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $sql .= ' AND r.submitted_at >= %s';
            $args[] = $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $sql .= ' AND r.submitted_at <= %s';
            $args[] = $filters['date_to'] . ' 23:59:59';
        }

        $sql .= ' ORDER BY r.submitted_at DESC';

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) ?: array();
    }

    public function delete_responses( $response_ids ) {
        global $wpdb;
        if ( empty( $response_ids ) ) {
            return 0;
        }
        $ids = array_map( 'absint', $response_ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        return $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->response_table()} WHERE response_id IN ({$placeholders})",
            ...$ids
        ) );
    }

    public function delete_all_responses_for_survey( $survey_id ) {
        global $wpdb;
        return $wpdb->delete( $this->response_table(), array( 'survey_id' => $survey_id ) );
    }

    // ── Pending Queue ───────────────────────────────────────

    public function insert_pending( $data ) {
        global $wpdb;
        $result = $wpdb->insert( $this->pending_table(), $data );
        if ( false === $result ) {
            if ( strpos( $wpdb->last_error, 'Duplicate entry' ) !== false ) {
                return new WP_Error( 'already_pending', 'Survey already pending for this course.' );
            }
            return new WP_Error( 'insert_failed', $wpdb->last_error );
        }
        return $wpdb->insert_id;
    }

    public function get_pending_for_user( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, c.title AS course_title, c.catalog_code
             FROM {$this->pending_table()} p
             LEFT JOIN {$wpdb->prefix}hl_course_catalog c ON c.catalog_id = p.catalog_id
             WHERE p.user_id = %d
             ORDER BY p.triggered_at ASC",
            $user_id
        ), ARRAY_A ) ?: array();
        // Note: NULL course_title means catalog entry was deleted — treated as orphan by get_next_pending_for_user().
    }

    public function get_pending_by_id( $pending_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->pending_table()} WHERE pending_id = %d",
            $pending_id
        ), ARRAY_A );
    }

    public function delete_pending( $pending_id ) {
        global $wpdb;
        return $wpdb->delete( $this->pending_table(), array( 'pending_id' => $pending_id ) );
    }

    public function delete_pending_by_keys( $enrollment_id, $catalog_id, $survey_id ) {
        global $wpdb;
        return $wpdb->delete( $this->pending_table(), array(
            'enrollment_id' => $enrollment_id,
            'catalog_id'    => $catalog_id,
            'survey_id'     => $survey_id,
        ) );
    }
}
