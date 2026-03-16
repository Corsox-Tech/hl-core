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

    /**
     * Get all cycles
     */
    public function get_all() {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_cycle ORDER BY created_at DESC",
            ARRAY_A
        );

        $cycles = array();
        foreach ($results as $row) {
            $cycles[] = new HL_Cycle($row);
        }
        return $cycles;
    }

    /**
     * Get cycle by ID
     */
    public function get_by_id($cycle_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
            $cycle_id
        ), ARRAY_A);

        return $row ? new HL_Cycle($row) : null;
    }

    /**
     * Create cycle
     */
    public function create($data) {
        global $wpdb;

        // Generate UUID if not provided
        if (empty($data['cycle_uuid'])) {
            $data['cycle_uuid'] = HL_DB_Utils::generate_uuid();
        }

        // Generate code if not provided
        if (empty($data['cycle_code']) && !empty($data['cycle_name'])) {
            $data['cycle_code'] = HL_Normalization::generate_code($data['cycle_name']);
        }

        $wpdb->insert(
            $wpdb->prefix . 'hl_cycle',
            $data
        );

        return $wpdb->insert_id;
    }

    /**
     * Update cycle
     */
    public function update($cycle_id, $data) {
        global $wpdb;

        // Build explicit format array so $wpdb doesn't rely on auto-detection.
        $formats = array();
        foreach ($data as $key => $value) {
            if (is_null($value)) {
                $formats[] = '%s';
            } elseif (is_int($value) || is_bool($value)) {
                $formats[] = '%d';
            } elseif (is_float($value)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'hl_cycle',
            $data,
            array('cycle_id' => $cycle_id),
            $formats,
            array('%d')
        );

        if ($result === false) {
            error_log('[HL Core] Cycle update FAILED for ID ' . $cycle_id . ': ' . $wpdb->last_error);
            error_log('[HL Core] Failed query: ' . $wpdb->last_query);
        }

        return $this->get_by_id($cycle_id);
    }

    /**
     * Delete cycle
     */
    public function delete($cycle_id) {
        global $wpdb;
        return $wpdb->delete(
            $wpdb->prefix . 'hl_cycle',
            array('cycle_id' => $cycle_id)
        );
    }
}
