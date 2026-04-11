<?php
if (!defined('ABSPATH')) exit;

class HL_Audit_Service {

    /**
     * Log an action to the audit log.
     *
     * Audit failures must never cascade into caller aborts (A.3.8). On DB
     * error, we error_log the failure and bump a daily aggregate counter
     * stored in wp_options (autoload=no) for monitoring.
     *
     * @param string $action_type e.g. 'enrollment.created', 'override.applied', 'import.committed'
     * @param array  $data        Optional: cycle_id, entity_type, entity_id, before_data, after_data, reason
     */
    public static function log($action_type, $data = array()) {
        try {
            global $wpdb;

            $result = $wpdb->insert($wpdb->prefix . 'hl_audit_log', array(
                'log_uuid'       => HL_DB_Utils::generate_uuid(),
                'actor_user_id'  => get_current_user_id(),
                'cycle_id'       => isset($data['cycle_id']) ? $data['cycle_id'] : null,
                'action_type'    => $action_type,
                'entity_type'    => isset($data['entity_type']) ? $data['entity_type'] : null,
                'entity_id'      => isset($data['entity_id']) ? $data['entity_id'] : null,
                'before_data'    => isset($data['before_data']) ? HL_DB_Utils::json_encode($data['before_data']) : null,
                'after_data'     => isset($data['after_data']) ? HL_DB_Utils::json_encode($data['after_data']) : null,
                'reason'         => isset($data['reason']) ? $data['reason'] : null,
                'ip_address'     => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : null,
                'user_agent'     => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 500) : null,
            ));

            // $wpdb->insert returns false on SQL errors without throwing —
            // catch that path here so A.3.8 observability still fires.
            if ( $result === false ) {
                self::record_audit_failure( $action_type, $wpdb->last_error ?: 'unknown wpdb->insert failure' );
            }
        } catch ( \Throwable $e ) {
            // A.3.8: audit failures must never cascade into caller aborts.
            self::record_audit_failure( $action_type, $e->getMessage() );
        }
    }

    /**
     * Record an audit-log write failure: error_log the message and bump
     * a daily aggregate counter stored in wp_options (autoload=no).
     *
     * The counter uses an atomic SQL UPDATE so concurrent failure cascades
     * don't lose increments (a read-modify-write via get_option +
     * update_option would drop concurrent bumps on busy sites). When the
     * row doesn't exist yet, add_option is atomic via the UNIQUE index on
     * option_name — concurrent racers both seeing a missing row produce
     * one insert + one ignored duplicate-key error, not a lost increment.
     *
     * Isolated so the try/catch and the `$wpdb->insert === false` branch
     * share one code path. Everything inside is itself wrapped in a
     * try/catch because under a hard DB outage update_option can also
     * fail — there is no recovery path beyond "never propagate".
     */
    private static function record_audit_failure($action_type, $error_message) {
        try {
            global $wpdb;
            error_log( '[HL_AUDIT_FAIL] ' . $error_message . ' on event ' . $action_type );
            $key     = 'hl_audit_fail_count_' . gmdate( 'Y-m-d' );
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options}
                 SET option_value = CAST(option_value AS UNSIGNED) + 1
                 WHERE option_name = %s",
                $key
            ) );
            if ( ! $updated ) {
                // Row didn't exist — insert atomically. add_option relies on
                // the option_name UNIQUE index, so concurrent racers produce
                // one winner + one silently-ignored duplicate-key error.
                add_option( $key, '1', '', 'no' );
            }
        } catch ( \Throwable $e ) {
            // Last-resort swallow. Audit failures must NEVER cascade.
        }
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

        if (!empty($filters['cycle_id'])) {
            $where[] = 'l.cycle_id = %d';
            $values[] = $filters['cycle_id'];
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

    /**
     * Fetch the most recent audit log entry matching entity + action.
     *
     * Used by Track 1 Task 14 (Force Resend history) and any feature that
     * needs "most recent event of type X for entity Y" without scanning
     * the full audit log.
     *
     * @param int    $entity_id   hl_audit_log.entity_id to match.
     * @param string $action_type hl_audit_log.action_type to match.
     * @return array|null Row array (with `actor_name` join) or null if none.
     */
    public static function get_last_event( $entity_id, $action_type ) {
        // Guard against callers passing 0 / null / false — without this,
        // the query matches any row with entity_id = 0 (which includes
        // every audit call that never passed an entity_id at all, e.g.
        // system-level events).
        $entity_id = (int) $entity_id;
        if ( $entity_id <= 0 ) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.*, u.display_name AS actor_name
             FROM {$wpdb->prefix}hl_audit_log l
             LEFT JOIN {$wpdb->users} u ON l.actor_user_id = u.ID
             WHERE l.entity_id = %d AND l.action_type = %s
             ORDER BY l.created_at DESC
             LIMIT 1",
            $entity_id,
            $action_type
        ), ARRAY_A );
        return $row ?: null;
    }
}
