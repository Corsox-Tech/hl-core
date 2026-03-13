<?php
/**
 * Partnership Repository
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Partnership_Repository {

    /**
     * Get all partnerships
     */
    public function get_all() {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_partnership ORDER BY created_at DESC",
            ARRAY_A
        );

        $partnerships = array();
        foreach ($results as $row) {
            $partnerships[] = new HL_Partnership($row);
        }
        return $partnerships;
    }

    /**
     * Get partnership by ID
     */
    public function get_by_id($partnership_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_partnership WHERE partnership_id = %d",
            $partnership_id
        ), ARRAY_A);

        return $row ? new HL_Partnership($row) : null;
    }

    /**
     * Create partnership
     */
    public function create($data) {
        global $wpdb;

        // Generate UUID if not provided
        if (empty($data['partnership_uuid'])) {
            $data['partnership_uuid'] = HL_DB_Utils::generate_uuid();
        }

        // Generate code if not provided
        if (empty($data['partnership_code']) && !empty($data['partnership_name'])) {
            $data['partnership_code'] = HL_Normalization::generate_code($data['partnership_name']);
        }

        $wpdb->insert(
            $wpdb->prefix . 'hl_partnership',
            $data
        );

        return $wpdb->insert_id;
    }

    /**
     * Update partnership
     */
    public function update($partnership_id, $data) {
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
            $wpdb->prefix . 'hl_partnership',
            $data,
            array('partnership_id' => $partnership_id),
            $formats,
            array('%d')
        );

        if ($result === false) {
            error_log('[HL Core] Partnership update FAILED for ID ' . $partnership_id . ': ' . $wpdb->last_error);
            error_log('[HL Core] Failed query: ' . $wpdb->last_query);
        }

        return $this->get_by_id($partnership_id);
    }

    /**
     * Delete partnership
     */
    public function delete($partnership_id) {
        global $wpdb;
        return $wpdb->delete(
            $wpdb->prefix . 'hl_partnership',
            array('partnership_id' => $partnership_id)
        );
    }
}
