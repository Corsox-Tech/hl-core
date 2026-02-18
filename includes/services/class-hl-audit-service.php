<?php
if (!defined('ABSPATH')) exit;

class HL_Audit_Service {

    /**
     * Log an action to the audit log
     *
     * @param string $action_type e.g. 'enrollment.created', 'override.applied', 'import.committed'
     * @param array  $data        Optional: cohort_id, entity_type, entity_id, before_data, after_data, reason
     */
    public static function log($action_type, $data = array()) {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'hl_audit_log', array(
            'log_uuid'       => HL_DB_Utils::generate_uuid(),
            'actor_user_id'  => get_current_user_id(),
            'cohort_id'     => isset($data['cohort_id']) ? $data['cohort_id'] : null,
            'action_type'    => $action_type,
            'entity_type'    => isset($data['entity_type']) ? $data['entity_type'] : null,
            'entity_id'      => isset($data['entity_id']) ? $data['entity_id'] : null,
            'before_data'    => isset($data['before_data']) ? HL_DB_Utils::json_encode($data['before_data']) : null,
            'after_data'     => isset($data['after_data']) ? HL_DB_Utils::json_encode($data['after_data']) : null,
            'reason'         => isset($data['reason']) ? $data['reason'] : null,
            'ip_address'     => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : null,
            'user_agent'     => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 500) : null,
        ));
    }

    /**
     * Get audit log entries
     */
    public static function get_logs($filters = array(), $limit = 50, $offset = 0) {
        global $wpdb;
        $sql = "SELECT l.*, u.display_name as actor_name
                FROM {$wpdb->prefix}hl_audit_log l
                LEFT JOIN {$wpdb->users} u ON l.actor_user_id = u.ID";
        $where = array();
        $values = array();

        if (!empty($filters['cohort_id'])) {
            $where[] = 'l.cohort_id = %d';
            $values[] = $filters['cohort_id'];
        }
        if (!empty($filters['action_type'])) {
            $where[] = 'l.action_type = %s';
            $values[] = $filters['action_type'];
        }
        if (!empty($filters['entity_type'])) {
            $where[] = 'l.entity_type = %s';
            $values[] = $filters['entity_type'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.created_at DESC';
        $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);

        if ($values) {
            $sql = $wpdb->prepare($sql, array_merge($values, array($limit, $offset)));
            // Re-prepare with all values
        }

        // Simpler approach: build the full query
        $full_sql = "SELECT l.*, u.display_name as actor_name
                     FROM {$wpdb->prefix}hl_audit_log l
                     LEFT JOIN {$wpdb->users} u ON l.actor_user_id = u.ID";
        if ($where) {
            $full_sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $full_sql .= ' ORDER BY l.created_at DESC LIMIT %d OFFSET %d';
        $all_values = array_merge($values, array($limit, $offset));

        if (!empty($all_values)) {
            $full_sql = $wpdb->prepare($full_sql, $all_values);
        }

        return $wpdb->get_results($full_sql, ARRAY_A) ?: array();
    }
}
