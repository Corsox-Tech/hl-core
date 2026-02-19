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
        add_shortcode('hl_observations', array($this, 'render_observations'));
        add_shortcode('hl_my_programs', array($this, 'render_my_programs'));
        add_shortcode('hl_program_page', array($this, 'render_program_page'));
        add_shortcode('hl_activity_page', array($this, 'render_activity_page'));
        add_shortcode('hl_my_cohort', array($this, 'render_my_cohort'));
        add_shortcode('hl_team_page', array($this, 'render_team_page'));
        add_shortcode('hl_classroom_page', array($this, 'render_classroom_page'));
        add_shortcode('hl_districts_listing', array($this, 'render_districts_listing'));
        add_shortcode('hl_district_page', array($this, 'render_district_page'));
        add_shortcode('hl_centers_listing', array($this, 'render_centers_listing'));
        add_shortcode('hl_center_page', array($this, 'render_center_page'));
        add_shortcode('hl_cohort_workspace', array($this, 'render_cohort_workspace'));
        add_shortcode('hl_my_coaching', array($this, 'render_my_coaching'));
    }

    public function enqueue_assets() {
        global $post;
        if (!is_a($post, 'WP_Post')) return;

        $has_shortcode = has_shortcode($post->post_content, 'hl_my_progress')
            || has_shortcode($post->post_content, 'hl_team_progress')
            || has_shortcode($post->post_content, 'hl_cohort_dashboard')
            || has_shortcode($post->post_content, 'hl_children_assessment')
            || has_shortcode($post->post_content, 'hl_observations')
            || has_shortcode($post->post_content, 'hl_my_programs')
            || has_shortcode($post->post_content, 'hl_program_page')
            || has_shortcode($post->post_content, 'hl_activity_page')
            || has_shortcode($post->post_content, 'hl_my_cohort')
            || has_shortcode($post->post_content, 'hl_team_page')
            || has_shortcode($post->post_content, 'hl_classroom_page')
            || has_shortcode($post->post_content, 'hl_districts_listing')
            || has_shortcode($post->post_content, 'hl_district_page')
            || has_shortcode($post->post_content, 'hl_centers_listing')
            || has_shortcode($post->post_content, 'hl_center_page')
            || has_shortcode($post->post_content, 'hl_cohort_workspace')
            || has_shortcode($post->post_content, 'hl_my_coaching');

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
     * [hl_centers_listing] - Staff CRM directory of centers
     */
    public function render_centers_listing($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_centers_listing');
        $renderer = new HL_Frontend_Centers_Listing();
        return $renderer->render($atts);
    }

    /**
     * [hl_center_page] - Center detail page
     */
    public function render_center_page($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_center_page');
        $renderer = new HL_Frontend_Center_Page();
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
