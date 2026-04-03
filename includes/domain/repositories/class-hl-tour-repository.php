<?php
if (!defined('ABSPATH')) exit;

class HL_Tour_Repository {

    private function tour_table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_tour';
    }

    private function step_table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_tour_step';
    }

    private function seen_table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_tour_seen';
    }

    // ─── Tour CRUD ───

    public function get_all_tours( $filters = array() ) {
        global $wpdb;

        $sql    = "SELECT t.*, (SELECT COUNT(*) FROM {$this->step_table()} s WHERE s.tour_id = t.tour_id) AS step_count FROM {$this->tour_table()} t";
        $where  = array();
        $values = array();

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 't.status = %s';
            $values[] = $filters['status'];
        }
        if ( ! empty( $filters['trigger_type'] ) ) {
            $where[]  = 't.trigger_type = %s';
            $values[] = $filters['trigger_type'];
        }

        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        $sql .= ' ORDER BY t.sort_order ASC, t.created_at DESC';

        if ( $values ) {
            $sql = $wpdb->prepare( $sql, $values );
        }
        return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
    }

    public function get_tour( $tour_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->tour_table()} WHERE tour_id = %d",
            $tour_id
        ), ARRAY_A );
    }

    public function get_tour_by_slug( $slug ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->tour_table()} WHERE slug = %s",
            $slug
        ), ARRAY_A );
    }

    public function create_tour( $data ) {
        global $wpdb;

        if ( isset( $data['target_roles'] ) && is_array( $data['target_roles'] ) ) {
            $data['target_roles'] = HL_DB_Utils::json_encode( $data['target_roles'] );
        }

        $wpdb->insert( $this->tour_table(), $data );
        return $wpdb->insert_id;
    }

    public function update_tour( $tour_id, $data ) {
        global $wpdb;

        if ( isset( $data['target_roles'] ) && is_array( $data['target_roles'] ) ) {
            $data['target_roles'] = HL_DB_Utils::json_encode( $data['target_roles'] );
        }

        $wpdb->update( $this->tour_table(), $data, array( 'tour_id' => $tour_id ) );
        return $this->get_tour( $tour_id );
    }

    public function delete_tour( $tour_id ) {
        global $wpdb;
        // Delete steps first (cascade).
        $wpdb->delete( $this->step_table(), array( 'tour_id' => $tour_id ) );
        // Delete seen records.
        $wpdb->delete( $this->seen_table(), array( 'tour_id' => $tour_id ) );
        // Delete tour.
        return $wpdb->delete( $this->tour_table(), array( 'tour_id' => $tour_id ) );
    }

    public function duplicate_tour( $tour_id ) {
        $tour = $this->get_tour( $tour_id );
        if ( ! $tour ) {
            return false;
        }

        unset( $tour['tour_id'], $tour['created_at'], $tour['updated_at'] );
        $tour['title']  = $tour['title'] . ' (Copy)';
        $tour['slug']   = $tour['slug'] . '-copy-' . time();
        $tour['status'] = 'draft';

        $new_id = $this->create_tour( $tour );
        if ( ! $new_id ) {
            return false;
        }

        // Copy steps.
        $steps = $this->get_steps( $tour_id );
        foreach ( $steps as $step ) {
            unset( $step['step_id'], $step['created_at'], $step['updated_at'] );
            $step['tour_id'] = $new_id;
            $this->create_step( $step );
        }

        return $new_id;
    }

    // ─── Step CRUD ───

    public function get_steps( $tour_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->step_table()} WHERE tour_id = %d ORDER BY step_order ASC",
            $tour_id
        ), ARRAY_A ) ?: array();
    }

    public function get_step( $step_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->step_table()} WHERE step_id = %d",
            $step_id
        ), ARRAY_A );
    }

    public function create_step( $data ) {
        global $wpdb;
        $wpdb->insert( $this->step_table(), $data );
        return $wpdb->insert_id;
    }

    public function update_step( $step_id, $data ) {
        global $wpdb;
        $wpdb->update( $this->step_table(), $data, array( 'step_id' => $step_id ) );
        return $this->get_step( $step_id );
    }

    public function delete_step( $step_id ) {
        global $wpdb;
        return $wpdb->delete( $this->step_table(), array( 'step_id' => $step_id ) );
    }

    public function reorder_steps( $tour_id, $step_ids_in_order ) {
        global $wpdb;
        foreach ( $step_ids_in_order as $index => $step_id ) {
            $wpdb->update(
                $this->step_table(),
                array( 'step_order' => $index ),
                array( 'step_id' => absint( $step_id ), 'tour_id' => absint( $tour_id ) )
            );
        }
    }

    // ─── Seen Tracking ───

    public function mark_seen( $user_id, $tour_id ) {
        global $wpdb;
        // Use INSERT IGNORE for idempotency (UNIQUE KEY handles dupes).
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$this->seen_table()} (user_id, tour_id, seen_at) VALUES (%d, %d, NOW())",
            $user_id, $tour_id
        ) );
    }

    public function has_seen( $user_id, $tour_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$this->seen_table()} WHERE user_id = %d AND tour_id = %d LIMIT 1",
            $user_id, $tour_id
        ) );
    }

    public function get_unseen_tour_ids( $user_id ) {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT t.tour_id FROM {$this->tour_table()} t
             WHERE t.status = 'active'
             AND t.tour_id NOT IN (
                 SELECT ts.tour_id FROM {$this->seen_table()} ts WHERE ts.user_id = %d
             )
             ORDER BY t.sort_order ASC",
            $user_id
        ) ) ?: array();
    }

    public function count_seen( $tour_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->seen_table()} WHERE tour_id = %d",
            $tour_id
        ) );
    }
}
