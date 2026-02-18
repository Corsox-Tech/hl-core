<?php
/**
 * Plugin Name: Housman Learning Core
 * Plugin URI: https://housmanlearning.com
 * Description: System-of-record for Housman Learning Academy Cohort management
 * Version: 1.0.0
 * Author: Housman Learning
 * Author URI: https://housmanlearning.com
 * License: Proprietary
 * Text Domain: hl-core
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('HL_CORE_VERSION', '1.0.4');
define('HL_CORE_PLUGIN_FILE', __FILE__);
define('HL_CORE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HL_CORE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HL_CORE_INCLUDES_DIR', HL_CORE_PLUGIN_DIR . 'includes/');
define('HL_CORE_ASSETS_URL', HL_CORE_PLUGIN_URL . 'assets/');

/**
 * Main plugin class
 */
class HL_Core {
    
    /**
     * Single instance
     */
    private static $instance = null;
    
    /**
     * Get singleton instance
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
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        // Utilities (load first)
        require_once HL_CORE_INCLUDES_DIR . 'utils/class-hl-db-utils.php';
        require_once HL_CORE_INCLUDES_DIR . 'utils/class-hl-date-utils.php';
        require_once HL_CORE_INCLUDES_DIR . 'utils/class-hl-normalization.php';
        
        // Database installer
        require_once HL_CORE_INCLUDES_DIR . 'class-hl-installer.php';
        
        // Domain models
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-orgunit.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-cohort.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-enrollment.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-team.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-classroom.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-child.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-pathway.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-activity.php';
        
        // Repositories
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-orgunit-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-cohort-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-enrollment-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-team-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-classroom-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-child-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-pathway-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-activity-repository.php';
        
        // Security
        require_once HL_CORE_INCLUDES_DIR . 'security/class-hl-capabilities.php';
        require_once HL_CORE_INCLUDES_DIR . 'security/class-hl-security.php';
        
        // Services
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-cohort-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-enrollment-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-team-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-classroom-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-pathway-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-rules-engine-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-assessment-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-observation-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-coaching-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-import-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-reporting-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-audit-service.php';
        
        // Integrations
        require_once HL_CORE_INCLUDES_DIR . 'integrations/class-hl-learndash-integration.php';
        require_once HL_CORE_INCLUDES_DIR . 'integrations/class-hl-jfb-integration.php';
        
        // Admin
        if (is_admin()) {
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-cohorts.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-orgunits.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-enrollments.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-pathways.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-teams.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-classrooms.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-imports.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-assessments.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-instruments.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-coaching.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-reporting.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-audit.php';
        }
        
        // Front-end (shortcodes)
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-shortcodes.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-my-progress.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-team-progress.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-cohort-dashboard.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-instrument-renderer.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-children-assessment.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-observations.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-my-programs.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-program-page.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-activity-page.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-my-cohort.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-team-page.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-classroom-page.php';

        // REST API
        require_once HL_CORE_INCLUDES_DIR . 'api/class-hl-rest-api.php';

        // CLI commands
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-seed-demo.php';
        }
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        register_activation_hook(HL_CORE_PLUGIN_FILE, array('HL_Installer', 'activate'));
        register_deactivation_hook(HL_CORE_PLUGIN_FILE, array('HL_Installer', 'deactivate'));
        
        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array($this, 'load_textdomain'));
    }
    
    /**
     * Initialize plugin components
     */
    public function init() {
        // Run schema migrations if needed (checks revision number, no-ops when current).
        HL_Installer::maybe_upgrade();

        // Initialize admin
        if (is_admin()) {
            HL_Admin::instance();
        }
        
        // Initialize front-end shortcodes
        HL_Shortcodes::instance();

        // Initialize REST API
        HL_REST_API::instance();
        
        // Initialize integrations
        HL_LearnDash_Integration::instance();
        HL_JFB_Integration::instance();

        // Initialize reporting service (registers rollup listener)
        HL_Reporting_Service::instance();

        // Auto-generate children assessment instances when teaching assignments change
        add_action('hl_core_teaching_assignment_changed', function ($cohort_id) {
            $service = new HL_Assessment_Service();
            $service->generate_children_assessment_instances($cohort_id);
        });

        // Register CLI commands
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            HL_CLI_Seed_Demo::register();
        }

        do_action('hl_core_init');
    }
    
    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'hl-core',
            false,
            dirname(plugin_basename(HL_CORE_PLUGIN_FILE)) . '/languages'
        );
    }
}

/**
 * Get main plugin instance
 */
function hl_core() {
    return HL_Core::instance();
}

// Initialize plugin
hl_core();
