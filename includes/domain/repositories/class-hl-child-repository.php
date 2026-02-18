<?php
if (!defined('ABSPATH')) exit;

class HL_Child_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_child';
    }

    public function get_all($center_id = null) {
        global $wpdb;
        if ($center_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE center_id = %d ORDER BY last_name ASC, first_name ASC",
                $center_id
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$this->table()} ORDER BY last_name ASC, first_name ASC",
                ARRAY_A
            );
        }
        return array_map(function($row) { return new HL_Child($row); }, $rows ?: array());
    }

    public function get_by_id($child_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE child_id = %d", $child_id
        ), ARRAY_A);
        return $row ? new HL_Child($row) : null;
    }

    public function create($data) {
        global $wpdb;
        if (empty($data['child_uuid'])) {
            $data['child_uuid'] = HL_DB_Utils::generate_uuid();
        }
        if (empty($data['child_fingerprint'])) {
            $data['child_fingerprint'] = self::compute_fingerprint($data);
        }
        if (empty($data['child_display_code']) && !empty($data['center_id'])) {
            $data['child_display_code'] = 'C-' . $data['center_id'] . '-' . substr($data['child_uuid'], 0, 8);
        }
        $wpdb->insert($this->table(), $data);
        return $wpdb->insert_id;
    }

    public function update($child_id, $data) {
        global $wpdb;
        $wpdb->update($this->table(), $data, array('child_id' => $child_id));
        return $this->get_by_id($child_id);
    }

    public function delete($child_id) {
        global $wpdb;
        return $wpdb->delete($this->table(), array('child_id' => $child_id));
    }

    public static function compute_fingerprint($data) {
        $parts = array();
        $parts[] = isset($data['center_id']) ? $data['center_id'] : '';
        $parts[] = isset($data['dob']) ? $data['dob'] : '';
        $parts[] = isset($data['internal_child_id']) ? HL_Normalization::normalize_string($data['internal_child_id']) : '';
        $parts[] = isset($data['first_name']) ? HL_Normalization::normalize_string($data['first_name']) : '';
        $parts[] = isset($data['last_name']) ? HL_Normalization::normalize_string($data['last_name']) : '';
        return hash('sha256', implode('|', $parts));
    }

    public function find_by_fingerprint($fingerprint, $center_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE child_fingerprint = %s AND center_id = %d",
            $fingerprint, $center_id
        ), ARRAY_A) ?: array();
    }
}
