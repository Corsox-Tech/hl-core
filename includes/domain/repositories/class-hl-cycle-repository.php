<?php
/**
 * Cycle Repository
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Cycle_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_cycle';
    }

    /**
     * Get all cycles, optionally filtered by partnership.
     *
     * @param int|null $partnership_id
     * @return HL_Cycle[]
     */
    public function get_all($partnership_id = null) {
        global $wpdb;

        if ($partnership_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE partnership_id = %d ORDER BY cycle_number ASC",
                $partnership_id
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$this->table()} ORDER BY partnership_id DESC, cycle_number ASC",
                ARRAY_A
            );
        }

        return array_map(function ($row) { return new HL_Cycle($row); }, $rows ?: array());
    }

    /**
     * Get a single cycle by ID.
     *
     * @param int $cycle_id
     * @return HL_Cycle|null
     */
    public function get_by_id($cycle_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE cycle_id = %d",
            $cycle_id
        ), ARRAY_A);

        return $row ? new HL_Cycle($row) : null;
    }

    /**
     * Get cycles for a partnership, ordered by cycle_number.
     *
     * @param int $partnership_id
     * @return HL_Cycle[]
     */
    public function get_by_partnership($partnership_id) {
        return $this->get_all($partnership_id);
    }

    /**
     * Get the first active cycle for a partnership.
     *
     * @param int $partnership_id
     * @return HL_Cycle|null
     */
    public function get_active_cycle($partnership_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE partnership_id = %d AND status = 'active' ORDER BY cycle_number ASC LIMIT 1",
            $partnership_id
        ), ARRAY_A);

        return $row ? new HL_Cycle($row) : null;
    }

    /**
     * Get the default (first) cycle for a partnership, regardless of status.
     *
     * @param int $partnership_id
     * @return HL_Cycle|null
     */
    public function get_default_cycle($partnership_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE partnership_id = %d ORDER BY cycle_number ASC LIMIT 1",
            $partnership_id
        ), ARRAY_A);

        return $row ? new HL_Cycle($row) : null;
    }

    /**
     * Create a new cycle.
     *
     * @param array $data
     * @return int Insert ID.
     */
    public function create($data) {
        global $wpdb;

        if (empty($data['cycle_uuid'])) {
            $data['cycle_uuid'] = HL_DB_Utils::generate_uuid();
        }

        $wpdb->insert($this->table(), $data);
        return $wpdb->insert_id;
    }

    /**
     * Update a cycle.
     *
     * @param int   $cycle_id
     * @param array $data
     * @return HL_Cycle|null
     */
    public function update($cycle_id, $data) {
        global $wpdb;

        $wpdb->update($this->table(), $data, array('cycle_id' => $cycle_id));
        return $this->get_by_id($cycle_id);
    }

    /**
     * Delete a cycle.
     *
     * @param int $cycle_id
     * @return int|false Number of rows deleted or false on error.
     */
    public function delete($cycle_id) {
        global $wpdb;

        return $wpdb->delete($this->table(), array('cycle_id' => $cycle_id));
    }

    /**
     * Count pathways linked to a cycle.
     *
     * @param int $cycle_id
     * @return int
     */
    public function count_pathways($cycle_id) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_pathway WHERE cycle_id = %d",
            $cycle_id
        ));
    }
}
