<?php
/**
 * Cohort Service (Container Entity)
 *
 * CRUD and queries for hl_cohort — the top-level container that groups
 * related tracks (e.g. program + control tracks under one umbrella).
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) exit;

class HL_Cohort_Service {

    /**
     * Get all cohorts.
     *
     * @param array $filters Optional. Keys: status.
     * @return array Array of cohort rows (ARRAY_A).
     */
    public function get_all($filters = array()) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $where = array('1=1');
        $params = array();

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT * FROM {$prefix}hl_cohort WHERE {$where_sql} ORDER BY cohort_name ASC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: array();
    }

    /**
     * Get a single cohort by ID.
     *
     * @param int $cohort_id
     * @return array|null Cohort row or null.
     */
    public function get_cohort($cohort_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_cohort WHERE cohort_id = %d",
            $cohort_id
        ), ARRAY_A);
    }

    /**
     * Create a new cohort.
     *
     * @param array $data Keys: cohort_name (required), cohort_code, description, status.
     * @return int|WP_Error cohort_id on success.
     */
    public function create_cohort($data) {
        global $wpdb;

        if (empty($data['cohort_name'])) {
            return new WP_Error('missing_name', __('Cohort name is required.', 'hl-core'));
        }

        $insert = array(
            'cohort_uuid' => HL_DB_Utils::generate_uuid(),
            'cohort_name' => sanitize_text_field($data['cohort_name']),
            'cohort_code' => !empty($data['cohort_code'])
                ? sanitize_text_field($data['cohort_code'])
                : HL_Normalization::generate_code($data['cohort_name']),
            'description' => !empty($data['description']) ? sanitize_textarea_field($data['description']) : null,
            'status'      => !empty($data['status']) ? sanitize_text_field($data['status']) : 'active',
        );

        $result = $wpdb->insert($wpdb->prefix . 'hl_cohort', $insert);

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to create cohort.', 'hl-core'));
        }

        $cohort_id = $wpdb->insert_id;

        HL_Audit_Service::log('cohort.created', array(
            'entity_type' => 'cohort',
            'entity_id'   => $cohort_id,
            'after_data'  => $insert,
        ));

        return $cohort_id;
    }

    /**
     * Update a cohort.
     *
     * @param int   $cohort_id
     * @param array $data
     * @return bool True on success.
     */
    public function update_cohort($cohort_id, $data) {
        global $wpdb;

        $allowed = array('cohort_name', 'cohort_code', 'description', 'status');
        $update = array();
        foreach ($allowed as $key) {
            if (isset($data[$key])) {
                $update[$key] = sanitize_text_field($data[$key]);
            }
        }

        if (empty($update)) {
            return false;
        }

        return (bool) $wpdb->update(
            $wpdb->prefix . 'hl_cohort',
            $update,
            array('cohort_id' => absint($cohort_id))
        );
    }

    /**
     * Delete a cohort.
     *
     * Only allowed if no tracks are linked to this cohort.
     *
     * @param int $cohort_id
     * @return true|WP_Error
     */
    public function delete_cohort($cohort_id) {
        global $wpdb;

        $track_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_track WHERE cohort_id = %d",
            $cohort_id
        ));

        if ($track_count > 0) {
            return new WP_Error('has_tracks', __('Cannot delete a cohort that has linked tracks. Remove or unlink the tracks first.', 'hl-core'));
        }

        $wpdb->delete($wpdb->prefix . 'hl_cohort', array('cohort_id' => absint($cohort_id)));

        return true;
    }

    /**
     * Get all tracks belonging to a cohort.
     *
     * @param int $cohort_id
     * @return array Array of track rows (ARRAY_A).
     */
    public function get_tracks_for_cohort($cohort_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_track WHERE cohort_id = %d ORDER BY track_name ASC",
            $cohort_id
        ), ARRAY_A) ?: array();
    }
}
