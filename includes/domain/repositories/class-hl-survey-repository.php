<?php
/**
 * Repository for hl_survey table.
 */
if (!defined('ABSPATH')) exit;

class HL_Survey_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_survey';
    }

    public function get_by_id( $survey_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE survey_id = %d",
            $survey_id
        ), ARRAY_A );
        return $row ? new HL_Survey( $row ) : null;
    }

    public function get_all( $status = null, $type = null ) {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table()} WHERE 1=1";
        $args = array();
        if ( $status ) {
            $sql .= ' AND status = %s';
            $args[] = $status;
        }
        if ( $type ) {
            $sql .= ' AND survey_type = %s';
            $args[] = $type;
        }
        $sql .= ' ORDER BY version DESC';
        $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
        return array_map( function( $row ) { return new HL_Survey( $row ); }, $rows ?: array() );
    }

    public function get_published_by_type( $type = 'end_of_course' ) {
        return $this->get_all( 'published', $type );
    }

    public function create( $data ) {
        global $wpdb;
        if ( empty( $data['survey_uuid'] ) ) {
            $data['survey_uuid'] = HL_DB_Utils::generate_uuid();
        }
        $result = $wpdb->insert( $this->table(), $data );
        if ( false === $result ) {
            return new WP_Error( 'insert_failed', $wpdb->last_error );
        }
        return $wpdb->insert_id;
    }

    public function update( $survey_id, $data ) {
        global $wpdb;
        $result = $wpdb->update( $this->table(), $data, array( 'survey_id' => $survey_id ) );
        if ( false === $result ) {
            return new WP_Error( 'update_failed', $wpdb->last_error );
        }
        return $this->get_by_id( $survey_id );
    }

    public function has_responses( $survey_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT EXISTS(SELECT 1 FROM {$wpdb->prefix}hl_course_survey_response WHERE survey_id = %d)",
            $survey_id
        ) );
    }

    public function get_response_count( $survey_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_course_survey_response WHERE survey_id = %d",
            $survey_id
        ) );
    }

    public function get_next_version( $survey_type ) {
        global $wpdb;
        $max = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(version) FROM {$this->table()} WHERE survey_type = %s",
            $survey_type
        ) );
        return $max + 1;
    }

    public function get_cycles_using_survey( $survey_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle WHERE survey_id = %d",
            $survey_id
        ), ARRAY_A ) ?: array();
    }

    public function delete( $survey_id ) {
        global $wpdb;
        return $wpdb->delete( $this->table(), array( 'survey_id' => $survey_id ) );
    }
}
