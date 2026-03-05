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
        add_action('in_admin_header', array($this, 'render_docs_link'));
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
            case 'hl-tracks':
                HL_Admin_Tracks::instance()->handle_early_actions();
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
            case 'hl-reporting':
                HL_Admin_Reporting::instance()->handle_early_actions();
                break;
            case 'hl-cohorts':
                HL_Admin_Cohorts::instance()->handle_early_actions();
                break;
            case 'hl-settings':
                HL_Admin_Settings::instance()->handle_early_actions();
                break;
        }
    }

    public function create_menu() {
        // Top-level menu — "HL Core" with dashicon
        add_menu_page('HL Core', 'HL Core', 'manage_hl_core', 'hl-tracks', array(HL_Admin_Tracks::instance(), 'render_page'), 'dashicons-welcome-learn-more', 30);

        // ── Primary entities (hierarchical order) ────────────────────
        add_submenu_page('hl-tracks', 'Partnerships', 'Partnerships', 'manage_hl_core', 'hl-tracks', array(HL_Admin_Tracks::instance(), 'render_page'));
        add_submenu_page('hl-tracks', 'Cohorts', 'Cohorts', 'manage_hl_core', 'hl-cohorts', array(HL_Admin_Cohorts::instance(), 'render_page'));
        add_submenu_page('hl-tracks', 'Org Units', 'Org Units', 'manage_hl_core', 'hl-orgunits', array(HL_Admin_OrgUnits::instance(), 'render_page'));
        add_submenu_page('hl-tracks', 'Enrollments', 'Enrollments', 'manage_hl_core', 'hl-enrollments', array(HL_Admin_Enrollments::instance(), 'render_page'));

        // ── Program structure ────────────────────────────────────────
        add_submenu_page('hl-tracks', 'Pathways', 'Pathways & Components', 'manage_hl_core', 'hl-pathways', array(HL_Admin_Pathways::instance(), 'render_page'));
        add_submenu_page('hl-tracks', 'Teams', 'Teams', 'manage_hl_core', 'hl-teams', array(HL_Admin_Teams::instance(), 'render_page'));
        add_submenu_page('hl-tracks', 'Classrooms', 'Classrooms', 'manage_hl_core', 'hl-classrooms', array(HL_Admin_Classrooms::instance(), 'render_page'));

        // ── Coaching & Assessments ──────────────────────────────────
        add_submenu_page('hl-tracks', 'Coaching Hub', 'Coaching Hub', 'manage_hl_core', 'hl-coaching', array(HL_Admin_Coaching::instance(), 'render_page'));
        add_submenu_page('hl-tracks', 'Assessments', 'Assessments', 'manage_hl_core', 'hl-assessments', array(HL_Admin_Assessments::instance(), 'render_page'));
        add_submenu_page('hl-tracks', 'Instruments', 'Instruments', 'manage_hl_core', 'hl-instruments', array(HL_Admin_Instruments::instance(), 'render_page'));

        // ── Reporting & Admin tools ──────────────────────────────────
        add_submenu_page('hl-tracks', 'Reports', 'Reports', 'manage_hl_core', 'hl-reporting', array(HL_Admin_Reporting::instance(), 'render_page'));
        add_submenu_page('hl-tracks', 'Settings', 'Settings', 'manage_hl_core', 'hl-settings', array(HL_Admin_Settings::instance(), 'render_page'));
    }

    /**
     * Render a small documentation link in the admin header bar.
     * Only visible on HL Core admin pages.
     */
    public function render_docs_link() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'hl-') === false) {
            return;
        }

        $docs_url = home_url('/documentation/');
        printf(
            '<div style="text-align:right;padding:4px 20px 0;margin-bottom:-8px;">'
            . '<a href="%s" target="_blank" style="text-decoration:none;color:#646970;font-size:13px;">'
            . '<span class="dashicons dashicons-media-document" style="font-size:16px;vertical-align:text-bottom;margin-right:3px;"></span>'
            . '%s</a></div>',
            esc_url($docs_url),
            esc_html__('Documentation', 'hl-core')
        );
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

        // Teacher instrument visual editor (only on hl-instruments page)
        if (strpos($hook, 'hl-instruments') !== false) {
            wp_enqueue_style('hl-admin-teacher-editor', HL_CORE_ASSETS_URL . 'css/admin-teacher-editor.css', array('hl-admin'), HL_CORE_VERSION);
            wp_enqueue_script('hl-admin-teacher-editor', HL_CORE_ASSETS_URL . 'js/admin-teacher-editor.js', array(), HL_CORE_VERSION, true);
        }

        // Import wizard assets (on hl-settings page, imports tab)
        $is_imports = strpos($hook, 'hl-imports') !== false
                   || (strpos($hook, 'hl-settings') !== false && (!isset($_GET['tab']) || $_GET['tab'] === 'imports'));
        if ($is_imports) {
            wp_enqueue_style('hl-admin-import-wizard', HL_CORE_ASSETS_URL . 'css/admin-import-wizard.css', array('hl-admin'), HL_CORE_VERSION);
            wp_enqueue_script('hl-admin-import-wizard', HL_CORE_ASSETS_URL . 'js/admin-import-wizard.js', array('jquery'), HL_CORE_VERSION, true);
            wp_localize_script('hl-admin-import-wizard', 'hl_import_i18n', array(
                'ajax_url'           => admin_url('admin-ajax.php'),
                'nonce_upload'       => wp_create_nonce('hl_import_upload'),
                'nonce_commit'       => wp_create_nonce('hl_import_commit'),
                'nonce_error_report' => wp_create_nonce('hl_import_error_report'),
                'select_track'       => __('Please select a track.', 'hl-core'),
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
