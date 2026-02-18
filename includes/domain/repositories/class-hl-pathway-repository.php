<?php
if (!defined('ABSPATH')) exit;

class HL_Pathway_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_pathway';
    }

    public function get_all($cohort_id = null) {
        global $wpdb;
        if ($cohort_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE cohort_id = %d ORDER BY pathway_name ASC",
                $cohort_id
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$this->table()} ORDER BY cohort_id DESC, pathway_name ASC",
                ARRAY_A
            );
        }
        return array_map(function($row) { return new HL_Pathway($row); }, $rows ?: array());
    }

    public function get_by_id($pathway_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE pathway_id = %d", $pathway_id
        ), ARRAY_A);
        return $row ? new HL_Pathway($row) : null;
    }

    public function create($data) {
        global $wpdb;
        if (empty($data['pathway_uuid'])) {
            $data['pathway_uuid'] = HL_DB_Utils::generate_uuid();
        }
        if (empty($data['pathway_code']) && !empty($data['pathway_name'])) {
            $data['pathway_code'] = HL_Normalization::generate_code($data['pathway_name']);
        }
        if (isset($data['target_roles']) && is_array($data['target_roles'])) {
            $data['target_roles'] = HL_DB_Utils::json_encode($data['target_roles']);
        }
        $wpdb->insert($this->table(), $data);
        return $wpdb->insert_id;
    }

    public function update($pathway_id, $data) {
        global $wpdb;
        if (isset($data['target_roles']) && is_array($data['target_roles'])) {
            $data['target_roles'] = HL_DB_Utils::json_encode($data['target_roles']);
        }
        $wpdb->update($this->table(), $data, array('pathway_id' => $pathway_id));
        return $this->get_by_id($pathway_id);
    }

    public function delete($pathway_id) {
        global $wpdb;
        return $wpdb->delete($this->table(), array('pathway_id' => $pathway_id));
    }
}
