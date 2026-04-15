<?php
/**
 * Plugin Name: Housman LMS
 * Plugin URI: https://housmanlearning.com
 * Description: System-of-record for Housman Learning Academy Partnership management
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
define('HL_CORE_VERSION', '1.2.2');
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
        require_once HL_CORE_INCLUDES_DIR . 'utils/class-hl-age-group-helper.php';
        require_once HL_CORE_INCLUDES_DIR . 'utils/class-hl-page-cache.php';
        // Helpers
        require_once HL_CORE_INCLUDES_DIR . 'helpers/class-hl-timezone-helper.php';
        
        // Database installer
        require_once HL_CORE_INCLUDES_DIR . 'class-hl-installer.php';
        
        // Domain models
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-orgunit.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-partnership.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-cycle.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-enrollment.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-team.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-classroom.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-child.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-pathway.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-component.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-course-catalog.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-teacher-assessment-instrument.php';
        
        // Repositories
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-orgunit-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-partnership-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-cycle-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-enrollment-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-team-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-classroom-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-child-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-pathway-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-component-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-course-catalog-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-tour-repository.php';

        // Security
        require_once HL_CORE_INCLUDES_DIR . 'security/class-hl-capabilities.php';
        require_once HL_CORE_INCLUDES_DIR . 'security/class-hl-security.php';
        
        // Services
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-cycle-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-partnership-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-enrollment-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-team-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-classroom-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-pathway-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-rules-engine-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-assessment-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-observation-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-coaching-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-import-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-import-participant-handler.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-import-children-handler.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-reporting-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-audit-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-coach-assignment-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-scope-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-pathway-assignment-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-pathway-routing-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-child-snapshot-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-rp-session-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-classroom-visit-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-session-prep-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-coach-dashboard-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-scheduling-email-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-scheduling-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-tour-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-ticket-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-bb-group-sync-service.php';

        // Shared helpers
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-roles.php';

        // Migrations
        require_once HL_CORE_INCLUDES_DIR . 'migrations/class-hl-roles-scrub-migration.php';

        // Email system
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-email-block-renderer.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-email-merge-tag-registry.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-email-rate-limit-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-email-condition-evaluator.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-email-recipient-resolver.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-email-queue-processor.php';
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-email-automation-service.php';

        // Auth system
        require_once HL_CORE_INCLUDES_DIR . 'auth/class-hl-auth-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'auth/class-hl-auth-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'auth/class-hl-auth-manager.php';

        // Integrations
        require_once HL_CORE_INCLUDES_DIR . 'integrations/class-hl-learndash-integration.php';
        require_once HL_CORE_INCLUDES_DIR . 'integrations/class-hl-buddyboss-integration.php';
        require_once HL_CORE_INCLUDES_DIR . 'integrations/class-hl-microsoft-graph.php';
        require_once HL_CORE_INCLUDES_DIR . 'integrations/class-hl-zoom-integration.php';

        // Scheduling & Integrations (loaded outside is_admin for AJAX support)
        require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-scheduling-settings.php';
        require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-email-templates.php';

        // Admin
        if (is_admin()) {
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-partnerships.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-orgunits.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-enrollments.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-pathways.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-teams.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-classrooms.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-imports.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-assessments.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-instruments.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-assessment-hub.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-coaching.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-coach-assignments.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-reporting.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-cycles.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-audit.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-settings.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-tours.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-course-catalog.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-emails.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-email-builder.php';
            require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-bb-groups-settings.php';
        }
        
        // Front-end (shortcodes)
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-shortcodes.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-my-progress.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-team-progress.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-cycle-dashboard.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-instrument-renderer.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-teacher-assessment-renderer.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-child-assessment.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-teacher-assessment.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-observations.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-my-programs.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-program-page.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-component-page.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-my-cycle.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-team-page.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-classroom-page.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-districts-listing.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-district-page.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-schools-listing.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-school-page.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-cycle-workspace.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-my-coaching.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-cycles-listing.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-institutions-listing.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-coaching-hub.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-classrooms-listing.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-learners.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-pathways-listing.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-reports-hub.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-my-team.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-dashboard.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-docs.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-rp-session.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-classroom-visit.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-self-reflection.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-action-plan.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-rp-notes.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-coach-dashboard.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-coach-mentors.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-coach-mentor-detail.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-coach-reports.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-coach-availability.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-schedule-session.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-user-profile.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-feature-tracker.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-login.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-password-reset.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-profile-setup.php';

        // REST API
        require_once HL_CORE_INCLUDES_DIR . 'api/class-hl-rest-api.php';

        // CLI commands
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-seed-demo.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-seed-palm-beach.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-seed-lutheran.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-provision-lutheran.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-nuke.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-create-pages.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-seed-docs.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-import-elcpb.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-import-elcpb-children.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-setup-elcpb-y2.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-setup-short-courses.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-setup-ea.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-setup-elcpb-y2-v2.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-seed-beginnings.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-diagnose-nav.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-smoke-test.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-email-v2-test.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-migrate-routing-types.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-translate-content.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-sync-ld-enrollment.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-test-email-renderer.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-sync-tickets.php';
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-bb-sync.php';
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

        // Page cache: persistent shortcode → page-ID map with save_post_page invalidation.
        HL_Page_Cache::init();

        // Coach list cache: invalidate on role changes.
        add_action( 'set_user_role',    array( 'HL_Frontend_Coaching_Hub', 'invalidate_coach_cache' ) );
        add_action( 'add_user_role',    array( 'HL_Frontend_Coaching_Hub', 'invalidate_coach_cache' ) );
        add_action( 'remove_user_role', array( 'HL_Frontend_Coaching_Hub', 'invalidate_coach_cache' ) );

        // Rev 37: chunked role scrub migration (runs on plugins_loaded@20 in admin/CLI only).
        HL_Roles_Scrub_Migration::register();

        // Hide the WP admin bar on all front-end pages.
        // Priority 9999 ensures this runs after BuddyBoss or other plugins re-enable it.
        add_filter('show_admin_bar', '__return_false', 9999);
        // CSS failsafe — hides the bar even if a theme/plugin overrides the filter.
        add_action('wp_head', array($this, 'hide_admin_bar_css'), 9999);

        // Zoho SalesIQ chat widget — loads on all frontend pages.
        add_action('wp_head', array($this, 'render_zoho_salesiq'), 50);

        // Email system: custom 5-minute cron interval.
        add_filter('cron_schedules', array($this, 'register_cron_schedules'));

        // Email system: track account activation + last login.
        add_action('wp_login', array($this, 'handle_wp_login'), 10, 2);
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
            HL_Admin_Imports::instance(); // Register AJAX hooks for import wizard
            HL_Admin_Enrollments::register_ajax_hooks();
            HL_Admin_Course_Catalog::register_ajax_hooks();
        }
        
        // Initialize front-end shortcodes
        HL_Shortcodes::instance();

        // Initialize documentation CPT + taxonomy
        HL_Frontend_Docs::instance();

        // Initialize REST API
        HL_REST_API::instance();
        
        // Initialize integrations
        HL_LearnDash_Integration::instance();
        HL_BuddyBoss_Integration::instance();

        // BB Group Sync hooks (enrollment events)
        add_action( 'hl_enrollment_created', array( 'HL_BB_Group_Sync_Service', 'on_enrollment_changed' ), 25, 2 );
        add_action( 'hl_enrollment_updated', array( 'HL_BB_Group_Sync_Service', 'on_enrollment_changed' ), 25, 2 );
        add_action( 'hl_enrollment_deleted', array( 'HL_BB_Group_Sync_Service', 'on_enrollment_deleted' ), 25, 2 );

        // BB Group Sync hooks (WP role changes)
        add_action( 'set_user_role',    array( 'HL_BB_Group_Sync_Service', 'on_role_changed' ), 10, 3 );
        add_action( 'add_user_role',    array( 'HL_BB_Group_Sync_Service', 'on_role_added' ),   10, 2 );
        add_action( 'remove_user_role', array( 'HL_BB_Group_Sync_Service', 'on_role_removed' ), 10, 2 );

        // Initialize reporting service (registers rollup listener)
        HL_Reporting_Service::instance();

        // Initialize scheduling service (registers AJAX hooks)
        HL_Scheduling_Service::instance();
        HL_Admin_Scheduling_Settings::instance();

        // Initialize tour service (registers AJAX hooks)
        HL_Tour_Service::instance();

        // Initialize feature tracker (registers AJAX hooks)
        HL_Frontend_Feature_Tracker::instance();

        // Initialize auth manager (registers hooks)
        HL_Auth_Manager::instance();

        // Auto-generate child assessment instances when teaching assignments change
        add_action('hl_core_teaching_assignment_changed', function ($cycle_id) {
            $service = new HL_Assessment_Service();
            $service->generate_child_assessment_instances($cycle_id);
        });

        // Register CLI commands
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            HL_CLI_Seed_Demo::register();
            HL_CLI_Seed_Palm_Beach::register();
            HL_CLI_Seed_Lutheran::register();
            HL_CLI_Provision_Lutheran::register();
            HL_CLI_Nuke::register();
            HL_CLI_Create_Pages::register();
            HL_CLI_Seed_Docs::register();
            HL_CLI_Import_ELCPB::register();
            HL_CLI_Import_ELCPB_Children::register();
            HL_CLI_Setup_ELCPB_Y2::register();
            HL_CLI_Setup_Short_Courses::register();
            HL_CLI_Setup_EA::register();
            HL_CLI_Setup_ELCPB_Y2_V2::register();
            HL_CLI_Seed_Beginnings::register();
            HL_CLI_Smoke_Test::register();
            HL_CLI_Email_V2_Test::register();
            HL_CLI_Migrate_Routing_Types::register();
            HL_CLI_Translate_Content::register();
            HL_CLI_Sync_Tickets::register();
            HL_CLI_BB_Sync::register();
        }

        // Email system: initialize automation service (registers hook listeners).
        HL_Email_Automation_Service::instance();

        // Email system: register cron action handlers.
        add_action( 'hl_email_process_queue', array( HL_Email_Queue_Processor::instance(), 'process_batch' ) );
        add_action( 'hl_email_cron_daily', array( HL_Email_Automation_Service::instance(), 'run_daily_checks' ) );
        add_action( 'hl_email_cron_hourly', array( HL_Email_Automation_Service::instance(), 'run_hourly_checks' ) );

        // Email system: ensure cron events are registered (guards against lost entries).
        // Deferred to wp_loaded so the custom 'hl_every_5_minutes' interval
        // from the cron_schedules filter is available when wp_schedule_event runs.
        add_action( 'wp_loaded', array( $this, 'ensure_email_cron_events' ) );

        do_action('hl_core_init');
    }
    
    /**
     * Load plugin text domain for translations
     */
    /**
     * Render Zoho SalesIQ chat widget with visitor identification.
     * Mirrors the WP Code snippet from production so the widget loads
     * on HL Core pages even when the BB theme is bypassed.
     */
    public function render_zoho_salesiq() {
        static $rendered = false;
        if ($rendered || is_admin()) {
            return;
        }
        $rendered = true;

        $is_logged_in = is_user_logged_in();
        $name    = '';
        $email   = '';
        $user_id = 0;

        if ($is_logged_in) {
            $u       = wp_get_current_user();
            $name    = trim(
                $u->display_name
                ?: trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''))
                ?: $u->user_login
            );
            $email   = $u->user_email ?: '';
            $user_id = (int) $u->ID;
        }
        ?>
        <!-- Zoho SalesIQ widget (HL Core) -->
        <script>
            window.$zoho = window.$zoho || {};
            $zoho.salesiq = $zoho.salesiq || { ready: function(){} };
            $zoho.salesiq.ready = function () {
                try {
                    <?php if ($is_logged_in && !empty($name)) : ?>
                        $zoho.salesiq.visitor.name(<?php echo json_encode($name); ?>);
                    <?php endif; ?>
                    <?php if ($is_logged_in && !empty($email)) : ?>
                        $zoho.salesiq.visitor.email(<?php echo json_encode($email); ?>);
                    <?php endif; ?>
                    <?php if ($is_logged_in) : ?>
                        $zoho.salesiq.visitor.id("wp-<?php echo esc_js($user_id); ?>");
                    <?php endif; ?>
                } catch (e) {
                    console.warn("SalesIQ ready() error:", e);
                }
            };
        </script>
        <script id="zsiqscript"
                src="https://salesiq.zohopublic.com/widget?wc=siq809a2f8618bcb9d5b31dfc458c9c20f363e1d33aa858f2fc74d214ee924df9ee"
                defer></script>
        <?php
    }

    /**
     * Register the custom 5-minute cron interval for email queue processing.
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified schedules.
     */
    public function register_cron_schedules( $schedules ) {
        $schedules['hl_every_5_minutes'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes (HL Email)', 'hl-core' ),
        );
        return $schedules;
    }

    /**
     * Handle wp_login: set hl_account_activated on first login, update last_login.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       User object.
     */
    public function handle_wp_login( $user_login, $user ) {
        $user_id = $user->ID;

        // First login — set activation flag (used by email conditions).
        if ( ! get_user_meta( $user_id, 'hl_account_activated', true ) ) {
            update_user_meta( $user_id, 'hl_account_activated', '1' );
        }

        // Always update last login timestamp.
        update_user_meta( $user_id, 'last_login', current_time( 'mysql', true ) );
    }

    /**
     * Ensure email cron events are registered. Re-schedules any missing
     * events. Guards against lost cron entries from failed updates.
     */
    public function ensure_email_cron_events() {
        if ( ! wp_next_scheduled( 'hl_email_process_queue' ) ) {
            wp_schedule_event( time(), 'hl_every_5_minutes', 'hl_email_process_queue' );
        }
        if ( ! wp_next_scheduled( 'hl_email_cron_daily' ) ) {
            wp_schedule_event( time(), 'daily', 'hl_email_cron_daily' );
        }
        if ( ! wp_next_scheduled( 'hl_email_cron_hourly' ) ) {
            wp_schedule_event( time(), 'hourly', 'hl_email_cron_hourly' );
        }
    }

    /**
     * CSS failsafe to hide the WP admin bar on all front-end pages.
     */
    public function hide_admin_bar_css() {
        echo '<style>#wpadminbar{display:none!important}html{margin-top:0!important}</style>';
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'hl-core',
            false,
            dirname(plugin_basename(HL_CORE_PLUGIN_FILE)) . '/languages'
        );
    }

    /**
     * Get the WPML-aware Dashboard page URL (the LMS "home").
     */
    public static function get_dashboard_url() {
        global $wpdb;
        $page_id = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[hl\_dashboard%' LIMIT 1"
        );
        if ($page_id) {
            $page_id = apply_filters('wpml_object_id', $page_id, 'page', true);
        }
        return $page_id ? get_permalink($page_id) : home_url('/');
    }

    /**
     * Get the WPML-aware User Profile page URL.
     */
    public static function get_profile_url() {
        global $wpdb;
        $page_id = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[hl\_user\_profile%' LIMIT 1"
        );
        if ($page_id) {
            $page_id = apply_filters('wpml_object_id', $page_id, 'page', true);
        }
        return $page_id ? get_permalink($page_id) : '';
    }

    /**
     * Render WPML language switcher as a custom flag + name dropdown.
     * Uses inline SVG flags for cross-platform compatibility.
     */
    public static function render_language_switcher() {
        if (!function_exists('icl_get_languages')) {
            return;
        }
        $languages = icl_get_languages('skip_missing=0&orderby=code');
        if (empty($languages) || count($languages) < 2) {
            return;
        }

        // Inline SVG flag icons (20x15 rounded-rect).
        $flag_map = array(
            'en'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 30" width="20" height="15" style="border-radius:2px"><clipPath id="a"><rect width="60" height="30" rx="2"/></clipPath><g clip-path="url(#a)"><rect width="60" height="30" fill="#00247d"/><path d="M0 0l60 30M60 0L0 30" stroke="#fff" stroke-width="6"/><path d="M0 0l60 30M60 0L0 30" stroke="#cf142b" stroke-width="4" clip-path="url(#a)"/><path d="M30 0v30M0 15h60" stroke="#fff" stroke-width="10"/><path d="M30 0v30M0 15h60" stroke="#cf142b" stroke-width="6"/></g></svg>',
            'es'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 30" width="20" height="15" style="border-radius:2px"><rect width="60" height="30" fill="#fff"/><rect width="60" height="10" fill="#006847"/><rect y="20" width="60" height="10" fill="#ce1126"/><rect x="22" y="10" width="16" height="10" fill="#fff"/><circle cx="30" cy="15" r="3" fill="#8b4513"/></svg>',
            'pt-br' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 30" width="20" height="15" style="border-radius:2px"><rect width="60" height="30" fill="#009b3a"/><polygon points="30,3 57,15 30,27 3,15" fill="#fedf00"/><circle cx="30" cy="15" r="7" fill="#002776"/><path d="M23.5 15.5 Q30 11 36.5 15.5" stroke="#fff" stroke-width="1" fill="none"/></svg>',
        );
        $name_map = array(
            'en'    => 'English',
            'es'    => "Espa\xC3\xB1ol",
            'pt-br' => "Portugu\xC3\xAAs",
        );

        // Preserve current query parameters (e.g. ?id=19&enrollment=162) across language switches.
        // WPML's icl_get_languages() strips custom query params from the returned URLs.
        $preserve_params = $_GET;
        unset($preserve_params['lang']); // WPML's own param — not needed in the URL.

        $active_lang = null;
        $other_langs = array();
        foreach ($languages as $code => $lang) {
            $lang['flag_svg']     = $flag_map[$code] ?? '';
            $lang['display_name'] = $name_map[$code] ?? $lang['native_name'];
            if (!empty($preserve_params) && !$lang['active']) {
                $lang['url'] = add_query_arg($preserve_params, $lang['url']);
            }
            if ($lang['active']) {
                $active_lang = $lang;
            } else {
                $other_langs[] = $lang;
            }
        }
        if (!$active_lang) {
            return;
        }
        ?>
        <div class="hl-lang-switcher" data-open="false">
            <button type="button" class="hl-lang-switcher__toggle" aria-expanded="false" aria-haspopup="listbox">
                <span class="hl-lang-switcher__flag"><?php echo $active_lang['flag_svg']; ?></span>
                <span class="hl-lang-switcher__name"><?php echo esc_html($active_lang['display_name']); ?></span>
                <svg class="hl-lang-switcher__arrow" width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M2.5 4l2.5 2.5L7.5 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <ul class="hl-lang-switcher__menu" role="listbox">
                <?php foreach ($other_langs as $lang) : ?>
                    <li role="option">
                        <a href="<?php echo esc_url($lang['url']); ?>" class="hl-lang-switcher__option">
                            <span class="hl-lang-switcher__flag"><?php echo $lang['flag_svg']; ?></span>
                            <span class="hl-lang-switcher__name"><?php echo esc_html($lang['display_name']); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <script>
        (function(){
            var el = document.querySelector('.hl-lang-switcher');
            if (!el) return;
            var btn = el.querySelector('.hl-lang-switcher__toggle');
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var open = el.getAttribute('data-open') === 'true';
                el.setAttribute('data-open', open ? 'false' : 'true');
                btn.setAttribute('aria-expanded', open ? 'false' : 'true');
            });
            document.addEventListener('click', function() {
                el.setAttribute('data-open', 'false');
                btn.setAttribute('aria-expanded', 'false');
            });
        })();
        </script>
        <?php
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
