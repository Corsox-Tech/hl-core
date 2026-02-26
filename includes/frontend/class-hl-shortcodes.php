<?php
if (!defined('ABSPATH')) exit;

class HL_Shortcodes {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_shortcodes'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('template_redirect', array('HL_Frontend_My_Cohort', 'handle_export'));
        add_action('template_redirect', array('HL_Frontend_Team_Page', 'handle_export'));
        add_action('template_redirect', array('HL_Frontend_Cohort_Workspace', 'handle_export'));
        add_action('template_redirect', array('HL_Frontend_My_Coaching', 'handle_post_actions'));
    }

    public function register_shortcodes() {
        add_shortcode('hl_my_progress', array($this, 'render_my_progress'));
        add_shortcode('hl_team_progress', array($this, 'render_team_progress'));
        add_shortcode('hl_cohort_dashboard', array($this, 'render_cohort_dashboard'));
        add_shortcode('hl_children_assessment', array($this, 'render_children_assessment'));
        add_shortcode('hl_teacher_assessment', array($this, 'render_teacher_assessment'));
        add_shortcode('hl_observations', array($this, 'render_observations'));
        add_shortcode('hl_my_programs', array($this, 'render_my_programs'));
        add_shortcode('hl_program_page', array($this, 'render_program_page'));
        add_shortcode('hl_activity_page', array($this, 'render_activity_page'));
        add_shortcode('hl_my_cohort', array($this, 'render_my_cohort'));
        add_shortcode('hl_team_page', array($this, 'render_team_page'));
        add_shortcode('hl_classroom_page', array($this, 'render_classroom_page'));
        add_shortcode('hl_districts_listing', array($this, 'render_districts_listing'));
        add_shortcode('hl_district_page', array($this, 'render_district_page'));
        add_shortcode('hl_schools_listing', array($this, 'render_schools_listing'));
        add_shortcode('hl_school_page', array($this, 'render_school_page'));
        add_shortcode('hl_cohort_workspace', array($this, 'render_cohort_workspace'));
        add_shortcode('hl_my_coaching', array($this, 'render_my_coaching'));
        add_shortcode('hl_cohorts_listing', array($this, 'render_cohorts_listing'));
        add_shortcode('hl_institutions_listing', array($this, 'render_institutions_listing'));
        add_shortcode('hl_coaching_hub', array($this, 'render_coaching_hub'));
        add_shortcode('hl_classrooms_listing', array($this, 'render_classrooms_listing'));
        add_shortcode('hl_learners', array($this, 'render_learners'));
        add_shortcode('hl_pathways_listing', array($this, 'render_pathways_listing'));
        add_shortcode('hl_reports_hub', array($this, 'render_reports_hub'));
        add_shortcode('hl_my_team', array($this, 'render_my_team'));
    }

    public function enqueue_assets() {
        global $post;
        if (!is_a($post, 'WP_Post')) return;

        $has_shortcode = has_shortcode($post->post_content, 'hl_my_progress')
            || has_shortcode($post->post_content, 'hl_team_progress')
            || has_shortcode($post->post_content, 'hl_cohort_dashboard')
            || has_shortcode($post->post_content, 'hl_children_assessment')
            || has_shortcode($post->post_content, 'hl_teacher_assessment')
            || has_shortcode($post->post_content, 'hl_observations')
            || has_shortcode($post->post_content, 'hl_my_programs')
            || has_shortcode($post->post_content, 'hl_program_page')
            || has_shortcode($post->post_content, 'hl_activity_page')
            || has_shortcode($post->post_content, 'hl_my_cohort')
            || has_shortcode($post->post_content, 'hl_team_page')
            || has_shortcode($post->post_content, 'hl_classroom_page')
            || has_shortcode($post->post_content, 'hl_districts_listing')
            || has_shortcode($post->post_content, 'hl_district_page')
            || has_shortcode($post->post_content, 'hl_schools_listing')
            || has_shortcode($post->post_content, 'hl_school_page')
            || has_shortcode($post->post_content, 'hl_cohort_workspace')
            || has_shortcode($post->post_content, 'hl_my_coaching')
            || has_shortcode($post->post_content, 'hl_cohorts_listing')
            || has_shortcode($post->post_content, 'hl_institutions_listing')
            || has_shortcode($post->post_content, 'hl_coaching_hub')
            || has_shortcode($post->post_content, 'hl_classrooms_listing')
            || has_shortcode($post->post_content, 'hl_learners')
            || has_shortcode($post->post_content, 'hl_pathways_listing')
            || has_shortcode($post->post_content, 'hl_reports_hub')
            || has_shortcode($post->post_content, 'hl_my_team');

        if ($has_shortcode) {
            wp_enqueue_style('hl-frontend', HL_CORE_ASSETS_URL . 'css/frontend.css', array(), HL_CORE_VERSION);
            wp_enqueue_script('hl-frontend', HL_CORE_ASSETS_URL . 'js/frontend.js', array('jquery'), HL_CORE_VERSION, true);
        }
    }

    /**
     * [hl_my_progress] - Participant's own progress dashboard
     */
    public function render_my_progress($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your progress.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array('cohort_id' => ''), $atts, 'hl_my_progress');
        $renderer = new HL_Frontend_My_Progress();
        return $renderer->render($atts);
    }

    /**
     * [hl_team_progress] - Mentor's team progress view
     */
    public function render_team_progress($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view team progress.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array('cohort_id' => ''), $atts, 'hl_team_progress');
        $renderer = new HL_Frontend_Team_Progress();
        return $renderer->render($atts);
    }

    /**
     * [hl_children_assessment] - Teacher's children assessment form
     */
    public function render_children_assessment($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your children assessments.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array('instance_id' => ''), $atts, 'hl_children_assessment');
        $renderer = new HL_Frontend_Children_Assessment();
        return $renderer->render($atts);
    }

    /**
     * [hl_teacher_assessment] - Teacher self-assessment form
     */
    public function render_teacher_assessment($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your self-assessments.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array('instance_id' => ''), $atts, 'hl_teacher_assessment');
        $renderer = new HL_Frontend_Teacher_Assessment();
        return $renderer->render($atts);
    }

    /**
     * [hl_observations] - Mentor's observation workflow
     */
    public function render_observations($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view observations.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array(), $atts, 'hl_observations');
        $renderer = new HL_Frontend_Observations();
        return $renderer->render($atts);
    }

    /**
     * [hl_cohort_dashboard] - Leader/Staff cohort overview
     */
    public function render_cohort_dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view the cohort dashboard.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array('cohort_id' => ''), $atts, 'hl_cohort_dashboard');
        $renderer = new HL_Frontend_Cohort_Dashboard();
        return $renderer->render($atts);
    }

    /**
     * [hl_my_programs] - Participant's program cards grid
     */
    public function render_my_programs($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your programs.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array(), $atts, 'hl_my_programs');
        $renderer = new HL_Frontend_My_Programs();
        return $renderer->render($atts);
    }

    /**
     * [hl_program_page] - Single program detail page
     */
    public function render_program_page($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this program.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array(), $atts, 'hl_program_page');
        $renderer = new HL_Frontend_Program_Page();
        return $renderer->render($atts);
    }

    /**
     * [hl_activity_page] - Single activity page (JFB form, redirect, etc.)
     */
    public function render_activity_page($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this activity.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array(), $atts, 'hl_activity_page');
        $renderer = new HL_Frontend_Activity_Page();
        return $renderer->render($atts);
    }

    /**
     * [hl_my_cohort] - Leader's auto-scoped cohort workspace
     */
    public function render_my_cohort($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your cohort.', 'hl-core') . '</div>';
        }

        // Ensure assets are loaded (fallback if has_shortcode detection missed this page).
        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_my_cohort');
        $renderer = new HL_Frontend_My_Cohort();
        return $renderer->render($atts);
    }

    /**
     * [hl_team_page] - Team detail page with members and report tabs
     */
    public function render_team_page($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this team.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_team_page');
        $renderer = new HL_Frontend_Team_Page();
        return $renderer->render($atts);
    }

    /**
     * [hl_classroom_page] - Classroom detail page with children table
     */
    public function render_classroom_page($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this classroom.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_classroom_page');
        $renderer = new HL_Frontend_Classroom_Page();
        return $renderer->render($atts);
    }

    /**
     * [hl_districts_listing] - Staff CRM directory of districts
     */
    public function render_districts_listing($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_districts_listing');
        $renderer = new HL_Frontend_Districts_Listing();
        return $renderer->render($atts);
    }

    /**
     * [hl_district_page] - District detail page
     */
    public function render_district_page($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_district_page');
        $renderer = new HL_Frontend_District_Page();
        return $renderer->render($atts);
    }

    /**
     * [hl_schools_listing] - Staff CRM directory of schools
     */
    public function render_schools_listing($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_schools_listing');
        $renderer = new HL_Frontend_Schools_Listing();
        return $renderer->render($atts);
    }

    /**
     * [hl_school_page] - School detail page
     */
    public function render_school_page($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_school_page');
        $renderer = new HL_Frontend_School_Page();
        return $renderer->render($atts);
    }

    /**
     * [hl_cohort_workspace] - Full cohort command center with Dashboard tab
     */
    public function render_cohort_workspace($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_cohort_workspace');
        $renderer = new HL_Frontend_Cohort_Workspace();
        return $renderer->render($atts);
    }

    /**
     * [hl_my_coaching] - Participant's coaching sessions page
     */
    public function render_my_coaching($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your coaching sessions.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_my_coaching');
        $renderer = new HL_Frontend_My_Coaching();
        return $renderer->render($atts);
    }

    /**
     * [hl_cohorts_listing] - Cohort listing with scope filtering
     */
    public function render_cohorts_listing($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_cohorts_listing');
        $renderer = new HL_Frontend_Cohorts_Listing();
        return $renderer->render($atts);
    }

    /**
     * [hl_institutions_listing] - Combined districts + schools view
     */
    public function render_institutions_listing($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_institutions_listing');
        $renderer = new HL_Frontend_Institutions_Listing();
        return $renderer->render($atts);
    }

    /**
     * [hl_coaching_hub] - Front-end coaching session management
     */
    public function render_coaching_hub($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_coaching_hub');
        $renderer = new HL_Frontend_Coaching_Hub();
        return $renderer->render($atts);
    }

    /**
     * [hl_classrooms_listing] - Classroom directory with scope filtering
     */
    public function render_classrooms_listing($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_classrooms_listing');
        $renderer = new HL_Frontend_Classrooms_Listing();
        return $renderer->render($atts);
    }

    /**
     * [hl_learners] - Participant directory with scope filtering
     */
    public function render_learners($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_learners');
        $renderer = new HL_Frontend_Learners();
        return $renderer->render($atts);
    }

    /**
     * [hl_pathways_listing] - Staff-only pathway browser
     */
    public function render_pathways_listing($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_pathways_listing');
        $renderer = new HL_Frontend_Pathways_Listing();
        return $renderer->render($atts);
    }

    /**
     * [hl_reports_hub] - Card grid of available report types
     */
    public function render_reports_hub($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_reports_hub');
        $renderer = new HL_Frontend_Reports_Hub();
        return $renderer->render($atts);
    }

    /**
     * [hl_my_team] - Auto-detect mentor's team
     */
    public function render_my_team($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your team.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_my_team');
        $renderer = new HL_Frontend_My_Team();
        return $renderer->render($atts);
    }

    /**
     * Ensure frontend CSS and JS are enqueued.
     * Called from shortcode render methods as a fallback in case
     * has_shortcode() detection failed (e.g. page builders, widgets).
     */
    private function ensure_frontend_assets() {
        if (!wp_style_is('hl-frontend', 'enqueued')) {
            wp_enqueue_style('hl-frontend', HL_CORE_ASSETS_URL . 'css/frontend.css', array(), HL_CORE_VERSION);
        }
        if (!wp_script_is('hl-frontend', 'enqueued')) {
            wp_enqueue_script('hl-frontend', HL_CORE_ASSETS_URL . 'js/frontend.js', array('jquery'), HL_CORE_VERSION, true);
        }
    }
}
