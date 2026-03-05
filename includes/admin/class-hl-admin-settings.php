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

        $wrap_class = 'wrap hl-admin-wrap';
        if ($tab === 'imports') {
            $wrap_class .= ' hl-import-wizard-wrap';
        }

        echo '<div class="' . esc_attr($wrap_class) . '">';
        HL_Admin::render_page_header(__('Settings', 'hl-core'));
        $this->render_tabs($tab);

        switch ($tab) {
            case 'audit':
                HL_Admin_Audit::instance()->render_page_content();
                break;

            case 'docs':
                $this->render_docs_tab();
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
            'docs'    => __('Doc Articles', 'hl-core'),
        );
        $base_url = admin_url('admin.php?page=hl-settings');

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url   = add_query_arg('tab', $slug, $base_url);
            $class = ($slug === $active_tab) ? 'nav-tab nav-tab-active' : 'nav-tab';
            printf('<a href="%s" class="%s">%s</a>', esc_url($url), esc_attr($class), esc_html($label));
        }
        echo '</nav>';
    }

    /**
     * Render the Doc Articles management tab.
     * Links to the WordPress native CPT editor and taxonomy manager.
     */
    private function render_docs_tab() {
        $articles_url   = admin_url('edit.php?post_type=hl_doc');
        $add_new_url    = admin_url('post-new.php?post_type=hl_doc');
        $categories_url = admin_url('edit-tags.php?taxonomy=hl_doc_category&post_type=hl_doc');

        echo '<h2>' . esc_html__('Documentation Articles', 'hl-core') . '</h2>';
        echo '<p>' . esc_html__('Manage the documentation articles displayed on the front-end Documentation page.', 'hl-core') . '</p>';

        echo '<div class="hl-metrics-row">';

        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value"><span class="dashicons dashicons-media-document" style="font-size:28px;width:28px;height:28px;color:var(--hl-a-secondary);"></span></div>';
        echo '<div class="metric-label">' . esc_html__('Articles', 'hl-core') . '</div>';
        echo '<p style="margin:8px 0 0;"><a href="' . esc_url($articles_url) . '" class="button">' . esc_html__('Manage Articles', 'hl-core') . '</a></p>';
        echo '</div>';

        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value"><span class="dashicons dashicons-plus-alt" style="font-size:28px;width:28px;height:28px;color:var(--hl-a-accent);"></span></div>';
        echo '<div class="metric-label">' . esc_html__('Add New', 'hl-core') . '</div>';
        echo '<p style="margin:8px 0 0;"><a href="' . esc_url($add_new_url) . '" class="button button-primary">' . esc_html__('Add Article', 'hl-core') . '</a></p>';
        echo '</div>';

        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value"><span class="dashicons dashicons-category" style="font-size:28px;width:28px;height:28px;color:var(--hl-a-warning);"></span></div>';
        echo '<div class="metric-label">' . esc_html__('Categories', 'hl-core') . '</div>';
        echo '<p style="margin:8px 0 0;"><a href="' . esc_url($categories_url) . '" class="button">' . esc_html__('Manage Categories', 'hl-core') . '</a></p>';
        echo '</div>';

        echo '</div>';
    }
}
