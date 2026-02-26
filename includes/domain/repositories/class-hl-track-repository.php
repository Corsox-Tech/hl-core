<?php
/**
 * Track Repository
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Track_Repository {

    /**
     * Get all tracks
     */
    public function get_all() {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_track ORDER BY created_at DESC",
            ARRAY_A
        );

        $tracks = array();
        foreach ($results as $row) {
            $tracks[] = new HL_Track($row);
        }
        return $tracks;
    }

    /**
     * Get track by ID
     */
    public function get_by_id($track_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_track WHERE track_id = %d",
            $track_id
        ), ARRAY_A);

        return $row ? new HL_Track($row) : null;
    }

    /**
     * Create track
     */
    public function create($data) {
        global $wpdb;

        // Generate UUID if not provided
        if (empty($data['track_uuid'])) {
            $data['track_uuid'] = HL_DB_Utils::generate_uuid();
        }

        // Generate code if not provided
        if (empty($data['track_code']) && !empty($data['track_name'])) {
            $data['track_code'] = HL_Normalization::generate_code($data['track_name']);
        }

        $wpdb->insert(
            $wpdb->prefix . 'hl_track',
            $data
        );

        return $wpdb->insert_id;
    }

    /**
     * Update track
     */
    public function update($track_id, $data) {
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
            $wpdb->prefix . 'hl_track',
            $data,
            array('track_id' => $track_id),
            $formats,
            array('%d')
        );

        if ($result === false) {
            error_log('[HL Core] Track update FAILED for ID ' . $track_id . ': ' . $wpdb->last_error);
            error_log('[HL Core] Failed query: ' . $wpdb->last_query);
        }

        return $this->get_by_id($track_id);
    }

    /**
     * Delete track
     */
    public function delete($track_id) {
        global $wpdb;
        return $wpdb->delete(
            $wpdb->prefix . 'hl_track',
            array('track_id' => $track_id)
        );
    }
}
