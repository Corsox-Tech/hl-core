<?php
if (!defined('ABSPATH')) exit;

class HL_Activity_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_activity';
    }

    public function get_all($pathway_id = null) {
        global $wpdb;
        if ($pathway_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE pathway_id = %d AND status = 'active' ORDER BY ordering_hint ASC, activity_id ASC",
                $pathway_id
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$this->table()} WHERE status = 'active' ORDER BY pathway_id ASC, ordering_hint ASC",
                ARRAY_A
            );
        }
        return array_map(function($row) { return new HL_Activity($row); }, $rows ?: array());
    }

    public function get_by_id($activity_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE activity_id = %d", $activity_id
        ), ARRAY_A);
        return $row ? new HL_Activity($row) : null;
    }

    public function get_by_pathway($pathway_id) {
        return $this->get_all($pathway_id);
    }

    public function create($data) {
        global $wpdb;
        if (empty($data['activity_uuid'])) {
            $data['activity_uuid'] = HL_DB_Utils::generate_uuid();
        }
        $wpdb->insert($this->table(), $data);
        return $wpdb->insert_id;
    }

    public function update($activity_id, $data) {
        global $wpdb;
        $wpdb->update($this->table(), $data, array('activity_id' => $activity_id));
        return $this->get_by_id($activity_id);
    }

    public function delete($activity_id) {
        global $wpdb;
        $wpdb->update($this->table(),
            array('status' => 'removed'),
            array('activity_id' => $activity_id)
        );
    }

    public function hard_delete($activity_id) {
        global $wpdb;
        return $wpdb->delete($this->table(), array('activity_id' => $activity_id));
    }
}
