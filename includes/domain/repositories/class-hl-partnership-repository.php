<?php
/**
 * Partnership Repository — CRUD for the container entity (hl_partnership).
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Partnership_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_partnership';
    }

    /**
     * Get all partnerships.
     *
     * @param array $filters Optional. Keys: status.
     * @return HL_Partnership[]
     */
    public function get_all($filters = array()) {
        global $wpdb;

        $where  = array('1=1');
        $params = array();

        if (!empty($filters['status'])) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT * FROM {$this->table()} WHERE {$where_sql} ORDER BY partnership_name ASC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: array();
        return array_map(function ($row) { return new HL_Partnership($row); }, $rows);
    }

    /**
     * Get a single partnership by ID.
     *
     * @param int $partnership_id
     * @return HL_Partnership|null
     */
    public function get_by_id($partnership_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE partnership_id = %d",
            $partnership_id
        ), ARRAY_A);
        return $row ? new HL_Partnership($row) : null;
    }

    /**
     * Create a new partnership.
     *
     * @param array $data
     * @return int Insert ID.
     */
    public function create($data) {
        global $wpdb;

        if (empty($data['partnership_uuid'])) {
            $data['partnership_uuid'] = HL_DB_Utils::generate_uuid();
        }
        if (empty($data['partnership_code']) && !empty($data['partnership_name'])) {
            $data['partnership_code'] = HL_Normalization::generate_code($data['partnership_name']);
        }

        $wpdb->insert($this->table(), $data);
        return $wpdb->insert_id;
    }

    /**
     * Update a partnership.
     *
     * @param int   $partnership_id
     * @param array $data
     * @return bool
     */
    public function update($partnership_id, $data) {
        global $wpdb;
        return (bool) $wpdb->update(
            $this->table(),
            $data,
            array('partnership_id' => absint($partnership_id))
        );
    }

    /**
     * Delete a partnership.
     *
     * @param int $partnership_id
     * @return bool
     */
    public function delete($partnership_id) {
        global $wpdb;
        return (bool) $wpdb->delete(
            $this->table(),
            array('partnership_id' => absint($partnership_id))
        );
    }
}
