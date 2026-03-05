<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Settings Hub
 *
 * Groups administrative utility pages (Imports, Audit Log) under
 * a single menu item with tab navigation.
 *
 * @package HL_Core
 */
class HL_Admin_Settings {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Handle POST/redirect actions before HTML output.
     * Delegates to the active tab's handler.
     */
    public function handle_early_actions() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'imports';

        switch ($tab) {
            case 'audit':
                // Audit Log has no early actions.
                break;

            default:
                // Imports early actions are AJAX-based (no handle_early_actions needed).
                break;
        }
    }

    /**
     * Main render entry point.
     */
    public function render_page() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'imports';

        $wrap_class = 'wrap';
        if ($tab === 'imports') {
            $wrap_class .= ' hl-admin-wrap hl-import-wizard-wrap';
        }

        echo '<div class="' . esc_attr($wrap_class) . '">';
        echo '<h1>' . esc_html__('Settings', 'hl-core') . '</h1>';
        $this->render_tabs($tab);

        switch ($tab) {
            case 'audit':
                HL_Admin_Audit::instance()->render_page_content();
                break;

            default:
                HL_Admin_Imports::instance()->render_page_content();
                break;
        }

        echo '</div>';
    }

    /**
     * Render the tab navigation.
     *
     * @param string $active_tab Currently active tab slug.
     */
    private function render_tabs($active_tab) {
        $tabs = array(
            'imports' => __('Imports', 'hl-core'),
            'audit'   => __('Audit Log', 'hl-core'),
        );
        $base_url = admin_url('admin.php?page=hl-settings');

        echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
        foreach ($tabs as $slug => $label) {
            $url   = add_query_arg('tab', $slug, $base_url);
            $class = ($slug === $active_tab) ? 'nav-tab nav-tab-active' : 'nav-tab';
            printf('<a href="%s" class="%s">%s</a>', esc_url($url), esc_attr($class), esc_html($label));
        }
        echo '</nav>';
    }
}
