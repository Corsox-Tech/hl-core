<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Audit Log Page
 *
 * Displays audit log entries in reverse chronological order with filtering.
 *
 * @package HL_Core
 */
class HL_Admin_Audit {

    /**
     * Singleton instance
     *
     * @var HL_Admin_Audit|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return HL_Admin_Audit
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // No hooks needed.
    }

    /**
     * Main render entry point (standalone page — kept for backward compatibility).
     */
    public function render_page() {
        echo '<div class="wrap hl-admin-wrap">';
        $this->render_page_content();
        echo '</div>';
    }

    /**
     * Render page content without the wrap div (for embedding inside Settings hub).
     */
    public function render_page_content() {
        global $wpdb;

        $filter_cycle       = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : 0;
        $filter_action_type = isset($_GET['action_type']) ? sanitize_text_field($_GET['action_type']) : '';

        $per_page     = 50;
        $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $offset       = ($current_page - 1) * $per_page;

        $where_clauses = array();
        $prepare_args  = array();

        if ($filter_cycle) {
            $where_clauses[] = 'al.cycle_id = %d';
            $prepare_args[]  = $filter_cycle;
        }

        if (!empty($filter_action_type)) {
            $where_clauses[] = 'al.action_type = %s';
            $prepare_args[]  = $filter_action_type;
        }

        $where = '';
        if (!empty($where_clauses)) {
            $where = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        $count_sql   = "SELECT COUNT(*) FROM {$wpdb->prefix}hl_audit_log al {$where}";
        $total_items = !empty($prepare_args)
                     ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $prepare_args))
                     : (int) $wpdb->get_var($count_sql);
        $total_pages = ceil($total_items / $per_page);

        $sql = "SELECT al.*, t.cycle_name, u.display_name AS actor_name
                FROM {$wpdb->prefix}hl_audit_log al
                LEFT JOIN {$wpdb->users} u ON al.actor_user_id = u.ID
                LEFT JOIN {$wpdb->prefix}hl_cycle t ON al.cycle_id = t.cycle_id
                {$where}
                ORDER BY al.created_at DESC
                LIMIT %d OFFSET %d";

        $limit_args = array_merge($prepare_args, array($per_page, $offset));
        $logs       = $wpdb->get_results($wpdb->prepare($sql, $limit_args));

        $action_types = $wpdb->get_col(
            "SELECT DISTINCT action_type FROM {$wpdb->prefix}hl_audit_log ORDER BY action_type ASC"
        );

        $cycles = $wpdb->get_results(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle ORDER BY cycle_name ASC"
        );

        echo '<h1>' . esc_html__('Audit Log', 'hl-core') . '</h1>';

        // Filters
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="hl-settings" />';
        echo '<input type="hidden" name="tab" value="audit" />';

        echo '<label><strong>' . esc_html__('Cycle:', 'hl-core') . '</strong> </label>';
        echo '<select name="cycle_id">';
        echo '<option value="">' . esc_html__('All', 'hl-core') . '</option>';
        if ($cycles) {
            foreach ($cycles as $coh) {
                echo '<option value="' . esc_attr($coh->cycle_id) . '"' . selected($filter_cycle, $coh->cycle_id, false) . '>' . esc_html($coh->cycle_name) . '</option>';
            }
        }
        echo '</select> ';

        echo '<label><strong>' . esc_html__('Action:', 'hl-core') . '</strong> </label>';
        echo '<select name="action_type">';
        echo '<option value="">' . esc_html__('All', 'hl-core') . '</option>';
        if ($action_types) {
            foreach ($action_types as $at) {
                echo '<option value="' . esc_attr($at) . '"' . selected($filter_action_type, $at, false) . '>' . esc_html($at) . '</option>';
            }
        }
        echo '</select> ';

        submit_button(__('Filter', 'hl-core'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<p>' . sprintf(
            esc_html__('Showing %1$d of %2$d entries.', 'hl-core'),
            count($logs),
            $total_items
        ) . '</p>';

        if (empty($logs)) {
            echo '<p>' . esc_html__('No audit log entries found.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actor', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Action', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Entity', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Cycle', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Details', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($logs as $log) {
            $entity_display = '';
            if (!empty($log->entity_type)) {
                $entity_display = $log->entity_type;
                if (!empty($log->entity_id)) {
                    $entity_display .= ' #' . $log->entity_id;
                }
            }

            // Build details summary
            $details = '';
            if (!empty($log->reason)) {
                $details = $log->reason;
            } elseif (!empty($log->after_data)) {
                $after = json_decode($log->after_data, true);
                if (is_array($after)) {
                    $details = wp_trim_words(wp_json_encode($after), 20, '...');
                }
            }

            echo '<tr>';
            echo '<td>' . esc_html($log->created_at) . '</td>';
            echo '<td>' . esc_html($log->actor_name ? $log->actor_name : __('System', 'hl-core')) . '</td>';
            echo '<td><code>' . esc_html($log->action_type) . '</code></td>';
            echo '<td>' . esc_html($entity_display) . '</td>';
            echo '<td>' . esc_html($log->cycle_name ? $log->cycle_name : '') . '</td>';
            echo '<td>' . esc_html($details) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Pagination
        if ($total_pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            $base_url = admin_url('admin.php?page=hl-settings&tab=audit');
            if ($filter_cycle) {
                $base_url = add_query_arg('cycle_id', $filter_cycle, $base_url);
            }
            if (!empty($filter_action_type)) {
                $base_url = add_query_arg('action_type', $filter_action_type, $base_url);
            }

            echo paginate_links(array(
                'base'      => add_query_arg('paged', '%#%', $base_url),
                'format'    => '',
                'current'   => $current_page,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ));

            echo '</div></div>';
        }
    }
}
