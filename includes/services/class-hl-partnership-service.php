<?php
/**
 * Partnership Service (Container Entity)
 *
 * CRUD and queries for hl_partnership — the top-level container that groups
 * related cycles (e.g. program + control cycles under one umbrella).
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) exit;

class HL_Partnership_Service {

    /**
     * Get all partnerships.
     *
     * @param array $filters Optional. Keys: status.
     * @return array Array of partnership rows (ARRAY_A).
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
        $sql = "SELECT * FROM {$prefix}hl_partnership WHERE {$where_sql} ORDER BY partnership_name ASC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: array();
    }

    /**
     * Get a single partnership by ID.
     *
     * @param int $partnership_id
     * @return array|null Partnership row or null.
     */
    public function get_partnership($partnership_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_partnership WHERE partnership_id = %d",
            $partnership_id
        ), ARRAY_A);
    }

    /**
     * Create a new partnership.
     *
     * @param array $data Keys: partnership_name (required), partnership_code, description, status.
     * @return int|WP_Error partnership_id on success.
     */
    public function create_partnership($data) {
        global $wpdb;

        if (empty($data['partnership_name'])) {
            return new WP_Error('missing_name', __('Partnership name is required.', 'hl-core'));
        }

        $insert = array(
            'partnership_uuid' => HL_DB_Utils::generate_uuid(),
            'partnership_name' => sanitize_text_field($data['partnership_name']),
            'partnership_code' => !empty($data['partnership_code'])
                ? sanitize_text_field($data['partnership_code'])
                : HL_Normalization::generate_code($data['partnership_name']),
            'description' => !empty($data['description']) ? sanitize_textarea_field($data['description']) : null,
            'status'      => !empty($data['status']) ? sanitize_text_field($data['status']) : 'active',
        );

        $result = $wpdb->insert($wpdb->prefix . 'hl_partnership', $insert);

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to create partnership.', 'hl-core'));
        }

        $partnership_id = $wpdb->insert_id;

        HL_Audit_Service::log('partnership.created', array(
            'entity_type' => 'partnership',
            'entity_id'   => $partnership_id,
            'after_data'  => $insert,
        ));

        return $partnership_id;
    }

    /**
     * Update a partnership.
     *
     * @param int   $partnership_id
     * @param array $data
     * @return bool True on success.
     */
    public function update_partnership($partnership_id, $data) {
        global $wpdb;

        $allowed = array('partnership_name', 'partnership_code', 'description', 'status');
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
            $wpdb->prefix . 'hl_partnership',
            $update,
            array('partnership_id' => absint($partnership_id))
        );
    }

    /**
     * Delete a partnership.
     *
     * Only allowed if no cycles are linked to this partnership.
     *
     * @param int $partnership_id
     * @return true|WP_Error
     */
    public function delete_partnership($partnership_id) {
        global $wpdb;

        $cycle_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_cycle WHERE partnership_id = %d",
            $partnership_id
        ));

        if ($cycle_count > 0) {
            return new WP_Error('has_cycles', __('Cannot delete a partnership that has linked cycles. Remove or unlink the cycles first.', 'hl-core'));
        }

        $wpdb->delete($wpdb->prefix . 'hl_partnership', array('partnership_id' => absint($partnership_id)));

        return true;
    }

    /**
     * Get all cycles belonging to a partnership.
     *
     * @param int $partnership_id
     * @return array Array of cycle rows (ARRAY_A).
     */
    public function get_cycles_for_partnership($partnership_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE partnership_id = %d ORDER BY cycle_name ASC",
            $partnership_id
        ), ARRAY_A) ?: array();
    }
}
