<?php
if (!defined('ABSPATH')) exit;

class HL_OrgUnit_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_orgunit';
    }

    public function get_all($type = null) {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table()}";
        if ($type) {
            $sql = $wpdb->prepare($sql . " WHERE orgunit_type = %s", $type);
        }
        $sql .= " ORDER BY orgunit_type ASC, name ASC";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return array_map(function($row) { return new HL_OrgUnit($row); }, $rows ?: array());
    }

    public function get_by_id($orgunit_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE orgunit_id = %d", $orgunit_id
        ), ARRAY_A);
        return $row ? new HL_OrgUnit($row) : null;
    }

    public function get_districts() {
        return $this->get_all('district');
    }

    public function get_centers($district_id = null) {
        global $wpdb;
        if ($district_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE orgunit_type = 'center' AND parent_orgunit_id = %d ORDER BY name ASC",
                $district_id
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$this->table()} WHERE orgunit_type = 'center' ORDER BY name ASC",
                ARRAY_A
            );
        }
        return array_map(function($row) { return new HL_OrgUnit($row); }, $rows ?: array());
    }

    public function create($data) {
        global $wpdb;
        if (empty($data['orgunit_uuid'])) {
            $data['orgunit_uuid'] = HL_DB_Utils::generate_uuid();
        }
        if (empty($data['orgunit_code']) && !empty($data['name'])) {
            $data['orgunit_code'] = HL_Normalization::generate_code($data['name']);
        }
        $wpdb->insert($this->table(), $data);
        return $wpdb->insert_id;
    }

    public function update($orgunit_id, $data) {
        global $wpdb;
        $wpdb->update($this->table(), $data, array('orgunit_id' => $orgunit_id));
        return $this->get_by_id($orgunit_id);
    }

    public function delete($orgunit_id) {
        global $wpdb;
        return $wpdb->delete($this->table(), array('orgunit_id' => $orgunit_id));
    }
}
