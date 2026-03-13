<?php
/**
 * Phase Repository
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Phase_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_phase';
    }

    /**
     * Get all phases, optionally filtered by partnership.
     *
     * @param int|null $partnership_id
     * @return HL_Phase[]
     */
    public function get_all($partnership_id = null) {
        global $wpdb;

        if ($partnership_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE partnership_id = %d ORDER BY phase_number ASC",
                $partnership_id
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$this->table()} ORDER BY partnership_id DESC, phase_number ASC",
                ARRAY_A
            );
        }

        return array_map(function ($row) { return new HL_Phase($row); }, $rows ?: array());
    }

    /**
     * Get a single phase by ID.
     *
     * @param int $phase_id
     * @return HL_Phase|null
     */
    public function get_by_id($phase_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE phase_id = %d",
            $phase_id
        ), ARRAY_A);

        return $row ? new HL_Phase($row) : null;
    }

    /**
     * Get phases for a partnership, ordered by phase_number.
     *
     * @param int $partnership_id
     * @return HL_Phase[]
     */
    public function get_by_partnership($partnership_id) {
        return $this->get_all($partnership_id);
    }

    /**
     * Get the first active phase for a partnership.
     *
     * @param int $partnership_id
     * @return HL_Phase|null
     */
    public function get_active_phase($partnership_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE partnership_id = %d AND status = 'active' ORDER BY phase_number ASC LIMIT 1",
            $partnership_id
        ), ARRAY_A);

        return $row ? new HL_Phase($row) : null;
    }

    /**
     * Get the default (first) phase for a partnership, regardless of status.
     *
     * @param int $partnership_id
     * @return HL_Phase|null
     */
    public function get_default_phase($partnership_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE partnership_id = %d ORDER BY phase_number ASC LIMIT 1",
            $partnership_id
        ), ARRAY_A);

        return $row ? new HL_Phase($row) : null;
    }

    /**
     * Create a new phase.
     *
     * @param array $data
     * @return int Insert ID.
     */
    public function create($data) {
        global $wpdb;

        if (empty($data['phase_uuid'])) {
            $data['phase_uuid'] = HL_DB_Utils::generate_uuid();
        }

        $wpdb->insert($this->table(), $data);
        return $wpdb->insert_id;
    }

    /**
     * Update a phase.
     *
     * @param int   $phase_id
     * @param array $data
     * @return HL_Phase|null
     */
    public function update($phase_id, $data) {
        global $wpdb;

        $wpdb->update($this->table(), $data, array('phase_id' => $phase_id));
        return $this->get_by_id($phase_id);
    }

    /**
     * Delete a phase.
     *
     * @param int $phase_id
     * @return int|false Number of rows deleted or false on error.
     */
    public function delete($phase_id) {
        global $wpdb;

        return $wpdb->delete($this->table(), array('phase_id' => $phase_id));
    }

    /**
     * Count pathways linked to a phase.
     *
     * @param int $phase_id
     * @return int
     */
    public function count_pathways($phase_id) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_pathway WHERE phase_id = %d",
            $phase_id
        ));
    }
}
