<?php if (!defined('ABSPATH')) exit;

/**
 * Main Admin Class
 *
 * @package HL_Core
 */
class HL_Admin {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'create_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_early_actions'));

        // Eagerly instantiate so AJAX hooks register on admin-ajax.php requests.
        HL_Admin_Cycles::instance();
        HL_Admin_Pathways::instance();
    }

    /**
     * Render a standard page header with title and docs link.
     *
     * Call this from any admin page's render_page() instead of a raw <h1>.
     * The docs link appears inline with the title, safely inside page content.
     *
     * @param string $title   Page title.
     * @param string $actions Optional extra HTML for action buttons.
     */
    public static function render_page_header($title, $actions = '') {
        $docs_url = home_url('/documentation/');
        echo '<div class="hl-page-header">';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<div class="hl-header-actions">';
        if ($actions) {
            echo $actions;
        }
        printf(
            '<a href="%s" target="_blank" class="hl-docs-link">'
            . '<span class="dashicons dashicons-media-document"></span>'
            . '%s</a>',
            esc_url($docs_url),
            esc_html__('Docs', 'hl-core')
        );
        echo '</div>';
        echo '</div>';
    }

    /**
     * Dispatch POST/redirect actions before any HTML output.
     *
     * WordPress has already sent admin page headers by the time render_page()
     * runs, so wp_redirect() would fail silently there. This handler runs on
     * admin_init (before output) and delegates to each admin class.
     */
    public function handle_early_actions() {
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

        switch ($page) {
            case 'hl-partnerships':
                HL_Admin_Partnerships::instance()->handle_early_actions();
                break;
            case 'hl-cycles':
                HL_Admin_Cycles::instance()->handle_early_actions();
                break;
            case 'hl-orgunits':
                HL_Admin_OrgUnits::instance()->handle_early_actions();
                break;
            case 'hl-enrollments':
                HL_Admin_Enrollments::instance()->handle_early_actions();
                break;
            case 'hl-pathways':
                HL_Admin_Pathways::instance()->handle_early_actions();
                break;
            case 'hl-teams':
                HL_Admin_Teams::instance()->handle_early_actions();
                break;
            case 'hl-classrooms':
                HL_Admin_Classrooms::instance()->handle_early_actions();
                break;
            case 'hl-instruments':
                HL_Admin_Instruments::instance()->handle_early_actions();
                break;
            case 'hl-coaching':
                HL_Admin_Coaching::instance()->handle_early_actions();
                break;
            // Coach Assignments now handled via hl-coaching tab=assignments
            case 'hl-assessments':
                HL_Admin_Assessments::instance()->handle_early_actions();
                break;
            case 'hl-assessment-hub':
                HL_Admin_Assessment_Hub::instance()->handle_early_actions();
                break;
            case 'hl-reporting':
                HL_Admin_Reporting::instance()->handle_early_actions();
                break;
            case 'hl-settings':
                HL_Admin_Settings::instance()->handle_early_actions();
                break;
        }
    }

    public function create_menu() {
        // Top-level menu — "HL Core" with dashicon
        add_menu_page('HL Core', 'HL Core', 'manage_hl_core', 'hl-cycles', array(HL_Admin_Cycles::instance(), 'render_page'), 'dashicons-welcome-learn-more', 30);

        // Rename the auto-generated first submenu from "HL Core" to "Cycles"
        // (WordPress duplicates the parent label as the first submenu item)
        global $submenu;
        $submenu['hl-cycles'][0][0] = 'Cycles';

        // ── Primary entities (hierarchical order) ────────────────────
        add_submenu_page('hl-cycles', 'Partnerships', 'Partnerships', 'manage_hl_core', 'hl-partnerships', array(HL_Admin_Partnerships::instance(), 'render_page'));
        add_submenu_page('hl-cycles', 'Org Units', 'Org Units', 'manage_hl_core', 'hl-orgunits', array(HL_Admin_OrgUnits::instance(), 'render_page'));
        add_submenu_page('hl-cycles', 'Enrollments', 'Enrollments', 'manage_hl_core', 'hl-enrollments', array(HL_Admin_Enrollments::instance(), 'render_page'));

        // ── Program structure ────────────────────────────────────────
        add_submenu_page('hl-cycles', 'Pathways', 'Pathways & Components', 'manage_hl_core', 'hl-pathways', array(HL_Admin_Pathways::instance(), 'render_page'));
        add_submenu_page('hl-cycles', 'Teams', 'Teams', 'manage_hl_core', 'hl-teams', array(HL_Admin_Teams::instance(), 'render_page'));
        add_submenu_page('hl-cycles', 'Classrooms', 'Classrooms', 'manage_hl_core', 'hl-classrooms', array(HL_Admin_Classrooms::instance(), 'render_page'));

        // ── Coaching & Assessments ──────────────────────────────────
        add_submenu_page('hl-cycles', 'Coaching Hub', 'Coaching Hub', 'manage_hl_core', 'hl-coaching', array(HL_Admin_Coaching::instance(), 'render_page'));
        add_submenu_page('hl-cycles', 'Assessments', 'Assessments', 'manage_hl_core', 'hl-assessment-hub', array(HL_Admin_Assessment_Hub::instance(), 'render_page'));

        // ── Reporting & Admin tools ──────────────────────────────────
        add_submenu_page('hl-cycles', 'Reports', 'Reports', 'manage_hl_core', 'hl-reporting', array(HL_Admin_Reporting::instance(), 'render_page'));
        add_submenu_page('hl-cycles', 'Settings', 'Settings', 'manage_hl_core', 'hl-settings', array(HL_Admin_Settings::instance(), 'render_page'));

        // ── Hidden pages (no menu entry, but accessible via URL) ────
        add_submenu_page(null, 'Instruments', '', 'manage_hl_core', 'hl-instruments', array(HL_Admin_Instruments::instance(), 'render_page'));
        add_submenu_page(null, 'Assessments (Standalone)', '', 'manage_hl_core', 'hl-assessments', array(HL_Admin_Assessments::instance(), 'render_page'));
        // Partnerships is now a visible menu item (registered above)
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'hl-') === false && strpos($hook, 'hl_') === false) {
            return;
        }
        wp_enqueue_style('hl-admin', HL_CORE_ASSETS_URL . 'css/admin.css', array(), HL_CORE_VERSION);

        // Coaching sessions page: enqueue WP Media for attachment picker
        if (strpos($hook, 'hl-coaching') !== false) {
            wp_enqueue_media();
        }

        // Teacher instrument visual editor (on hl-instruments or assessment-hub teacher-instruments section)
        $is_teacher_editor = strpos($hook, 'hl-instruments') !== false
            || (strpos($hook, 'hl-assessment-hub') !== false && isset($_GET['section']) && $_GET['section'] === 'teacher-instruments');
        if ($is_teacher_editor) {
            wp_enqueue_style('hl-admin-teacher-editor', HL_CORE_ASSETS_URL . 'css/admin-teacher-editor.css', array('hl-admin'), HL_CORE_VERSION);
            wp_enqueue_script('hl-admin-teacher-editor', HL_CORE_ASSETS_URL . 'js/admin-teacher-editor.js', array(), HL_CORE_VERSION, true);
        }

        // Tours admin page.
        if (strpos($hook, 'hl-settings') !== false && isset($_GET['tab']) && $_GET['tab'] === 'tours') {
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            wp_enqueue_script('hl-tour-admin', HL_CORE_ASSETS_URL . 'js/hl-tour-admin.js', array('jquery', 'jquery-ui-sortable', 'wp-color-picker'), HL_CORE_VERSION, true);
            wp_localize_script('hl-tour-admin', 'hlTourAdmin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('hl_tour_admin_nonce'),
                'site_url' => site_url(),
            ));
        }

        // Import wizard assets (on hl-settings page, imports tab, or hl-cycles page, import tab)
        $is_imports = strpos($hook, 'hl-imports') !== false
                   || (strpos($hook, 'hl-settings') !== false && (!isset($_GET['tab']) || $_GET['tab'] === 'imports'))
                   || (strpos($hook, 'hl-cycles') !== false && isset($_GET['tab']) && $_GET['tab'] === 'import');
        if ($is_imports) {
            wp_enqueue_style('hl-admin-import-wizard', HL_CORE_ASSETS_URL . 'css/admin-import-wizard.css', array('hl-admin'), HL_CORE_VERSION);
            wp_enqueue_script('hl-admin-import-wizard', HL_CORE_ASSETS_URL . 'js/admin-import-wizard.js', array('jquery'), HL_CORE_VERSION, true);
            wp_localize_script('hl-admin-import-wizard', 'hl_import_i18n', array(
                'ajax_url'           => admin_url('admin-ajax.php'),
                'nonce_upload'       => wp_create_nonce('hl_import_upload'),
                'nonce_commit'       => wp_create_nonce('hl_import_commit'),
                'nonce_error_report' => wp_create_nonce('hl_import_error_report'),
                'select_cycle'       => __('Please select a cycle.', 'hl-core'),
                'select_file'        => __('Please select a CSV file.', 'hl-core'),
                'uploading'          => __('Uploading and validating...', 'hl-core'),
                'committing'         => __('Committing import...', 'hl-core'),
                'processing'         => __('Processing...', 'hl-core'),
                'generating_report'  => __('Generating error report...', 'hl-core'),
                'unknown_error'      => __('An unexpected error occurred.', 'hl-core'),
                'no_rows_selected'   => __('Please select at least one row to import.', 'hl-core'),
                'confirm_commit'     => __('Are you sure you want to commit %d selected rows?', 'hl-core'),
                'confirm_cancel'     => __('Are you sure you want to cancel? Preview data will be lost.', 'hl-core'),
                'selected'           => __('selected', 'hl-core'),
                'unmapped_columns'   => __('Unmapped columns (ignored)', 'hl-core'),
                'col_status'         => __('Status', 'hl-core'),
                'col_email'          => __('Email', 'hl-core'),
                'col_name'           => __('Name', 'hl-core'),
                'col_roles'          => __('Roles', 'hl-core'),
                'col_school'         => __('School', 'hl-core'),
                'col_details'        => __('Details', 'hl-core'),
                'col_row'            => __('Row', 'hl-core'),
                'col_error'          => __('Error', 'hl-core'),
                'col_dob'            => __('DOB', 'hl-core'),
                'col_child_id'       => __('Child ID', 'hl-core'),
                'col_classroom'      => __('Classroom', 'hl-core'),
                'col_age_band'       => __('Age Band', 'hl-core'),
                'col_lead'           => __('Lead Teacher', 'hl-core'),
                'created'            => __('Created', 'hl-core'),
                'updated'            => __('Updated', 'hl-core'),
                'skipped'            => __('Skipped', 'hl-core'),
                'errors_label'       => __('Errors', 'hl-core'),
                'commit_errors'      => __('Commit Errors', 'hl-core'),
                'all_success'        => __('All selected rows were imported successfully!', 'hl-core'),
            ));
        }
    }
}
