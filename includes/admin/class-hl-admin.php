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
    }

    public function create_menu() {
        add_menu_page('HL Core', 'HL Core', 'manage_hl_core', 'hl-core', array(HL_Admin_Cohorts::instance(), 'render_page'), 'dashicons-welcome-learn-more', 30);
        add_submenu_page('hl-core', 'Cohorts', 'Cohorts', 'manage_hl_core', 'hl-core', array(HL_Admin_Cohorts::instance(), 'render_page'));
        add_submenu_page('hl-core', 'Org Units', 'Org Units', 'manage_hl_core', 'hl-orgunits', array(HL_Admin_OrgUnits::instance(), 'render_page'));
        add_submenu_page('hl-core', 'Enrollments', 'Enrollments', 'manage_hl_core', 'hl-enrollments', array(HL_Admin_Enrollments::instance(), 'render_page'));
        add_submenu_page('hl-core', 'Pathways', 'Pathways & Activities', 'manage_hl_core', 'hl-pathways', array(HL_Admin_Pathways::instance(), 'render_page'));
        add_submenu_page('hl-core', 'Teams', 'Teams', 'manage_hl_core', 'hl-teams', array(HL_Admin_Teams::instance(), 'render_page'));
        add_submenu_page('hl-core', 'Classrooms', 'Classrooms', 'manage_hl_core', 'hl-classrooms', array(HL_Admin_Classrooms::instance(), 'render_page'));
        add_submenu_page('hl-core', 'Imports', 'Imports', 'manage_hl_core', 'hl-imports', array(HL_Admin_Imports::instance(), 'render_page'));
        add_submenu_page('hl-core', 'Assessments', 'Assessments', 'manage_hl_core', 'hl-assessments', array(HL_Admin_Assessments::instance(), 'render_page'));
        add_submenu_page('hl-core', 'Instruments', 'Instruments', 'manage_hl_core', 'hl-instruments', array(HL_Admin_Instruments::instance(), 'render_page'));
        add_submenu_page('hl-core', 'Coaching', 'Coaching Sessions', 'manage_hl_core', 'hl-coaching', array(HL_Admin_Coaching::instance(), 'render_page'));
        add_submenu_page('hl-core', 'Reports', 'Reports', 'manage_hl_core', 'hl-reporting', array(HL_Admin_Reporting::instance(), 'render_page'));
        add_submenu_page('hl-core', 'Audit Log', 'Audit Log', 'manage_hl_core', 'hl-audit', array(HL_Admin_Audit::instance(), 'render_page'));
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

        // Import wizard assets (only on hl-imports page)
        if (strpos($hook, 'hl-imports') !== false) {
            wp_enqueue_style('hl-admin-import-wizard', HL_CORE_ASSETS_URL . 'css/admin-import-wizard.css', array('hl-admin'), HL_CORE_VERSION);
            wp_enqueue_script('hl-admin-import-wizard', HL_CORE_ASSETS_URL . 'js/admin-import-wizard.js', array('jquery'), HL_CORE_VERSION, true);
            wp_localize_script('hl-admin-import-wizard', 'hl_import_i18n', array(
                'ajax_url'           => admin_url('admin-ajax.php'),
                'nonce_upload'       => wp_create_nonce('hl_import_upload'),
                'nonce_commit'       => wp_create_nonce('hl_import_commit'),
                'nonce_error_report' => wp_create_nonce('hl_import_error_report'),
                'select_cohort'      => __('Please select a cohort.', 'hl-core'),
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
                'col_center'         => __('Center', 'hl-core'),
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
