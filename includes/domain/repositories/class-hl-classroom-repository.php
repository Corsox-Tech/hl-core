<?php
if (!defined('ABSPATH')) exit;

class HL_Classroom_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_classroom';
    }

    public function get_all($school_id = null, $cycle_id = null) {
        global $wpdb;
        $where = array();
        $params = array();
        if ($school_id) {
            $where[] = 'school_id = %d';
            $params[] = $school_id;
        }
        if ($cycle_id) {
            $where[] = 'cycle_id = %d';
            $params[] = $cycle_id;
        }
        $sql = "SELECT * FROM {$this->table()}";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY classroom_name ASC';
        if (!empty($params)) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($sql, ARRAY_A);
        }
        return array_map(function($row) { return new HL_Classroom($row); }, $rows ?: array());
    }

    public function get_by_id($classroom_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE classroom_id = %d", $classroom_id
        ), ARRAY_A);
        return $row ? new HL_Classroom($row) : null;
    }

    public function create($data) {
        global $wpdb;
        if (empty($data['classroom_uuid'])) {
            $data['classroom_uuid'] = HL_DB_Utils::generate_uuid();
        }
        $wpdb->insert($this->table(), $data);
        return $wpdb->insert_id;
    }

    public function update($classroom_id, $data) {
        global $wpdb;
        $wpdb->update($this->table(), $data, array('classroom_id' => $classroom_id));
        return $this->get_by_id($classroom_id);
    }

    public function delete($classroom_id) {
        global $wpdb;
        return $wpdb->delete($this->table(), array('classroom_id' => $classroom_id));
    }
}
