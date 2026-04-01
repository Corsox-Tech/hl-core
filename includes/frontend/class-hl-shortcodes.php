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
        // wp_print_styles fires AFTER all wp_enqueue_scripts callbacks (including
        // BB child theme at 99999 and BP_Nouveau at PHP_INT_MAX). This is the
        // ONLY hook where we can guarantee BB styles are dequeued and ours are present.
        add_action('wp_print_styles', array($this, 'final_style_override'), PHP_INT_MAX);
        add_action('template_redirect', array('HL_Frontend_My_Cycle', 'handle_export'));
        add_action('template_redirect', array('HL_Frontend_Team_Page', 'handle_export'));
        add_action('template_redirect', array('HL_Frontend_Cycle_Workspace', 'handle_export'));
        add_action('template_redirect', array('HL_Frontend_My_Coaching', 'handle_post_actions'));
        add_action('template_redirect', array('HL_Frontend_Classroom_Page', 'handle_post_actions'));
        add_action('template_redirect', array('HL_Frontend_Coach_Mentor_Detail', 'handle_export'));
        add_action('template_redirect', array('HL_Frontend_Coach_Reports', 'handle_export'));
        add_action('template_redirect', array('HL_Frontend_Coach_Availability', 'handle_post_actions'));
        add_action('template_redirect', array('HL_Frontend_User_Profile', 'handle_post_actions'));
    }

    public function register_shortcodes() {
        add_shortcode('hl_my_progress', array($this, 'render_my_progress'));
        add_shortcode('hl_team_progress', array($this, 'render_team_progress'));
        add_shortcode('hl_cycle_dashboard', array($this, 'render_cycle_dashboard'));
        add_shortcode('hl_child_assessment', array($this, 'render_child_assessment'));
        add_shortcode('hl_teacher_assessment', array($this, 'render_teacher_assessment'));
        add_shortcode('hl_observations', array($this, 'render_observations'));
        add_shortcode('hl_my_programs', array($this, 'render_my_programs'));
        add_shortcode('hl_program_page', array($this, 'render_program_page'));
        add_shortcode('hl_component_page', array($this, 'render_component_page'));
        add_shortcode('hl_my_cycle', array($this, 'render_my_cycle'));
        add_shortcode('hl_team_page', array($this, 'render_team_page'));
        add_shortcode('hl_classroom_page', array($this, 'render_classroom_page'));
        add_shortcode('hl_districts_listing', array($this, 'render_districts_listing'));
        add_shortcode('hl_district_page', array($this, 'render_district_page'));
        add_shortcode('hl_schools_listing', array($this, 'render_schools_listing'));
        add_shortcode('hl_school_page', array($this, 'render_school_page'));
        add_shortcode('hl_cycle_workspace', array($this, 'render_cycle_workspace'));
        add_shortcode('hl_my_coaching', array($this, 'render_my_coaching'));
        add_shortcode('hl_cycles_listing', array($this, 'render_cycles_listing'));
        add_shortcode('hl_institutions_listing', array($this, 'render_institutions_listing'));
        add_shortcode('hl_coaching_hub', array($this, 'render_coaching_hub'));
        add_shortcode('hl_classrooms_listing', array($this, 'render_classrooms_listing'));
        add_shortcode('hl_learners', array($this, 'render_learners'));
        add_shortcode('hl_pathways_listing', array($this, 'render_pathways_listing'));
        add_shortcode('hl_reports_hub', array($this, 'render_reports_hub'));
        add_shortcode('hl_my_team', array($this, 'render_my_team'));
        add_shortcode('hl_dashboard', array($this, 'render_dashboard'));
        add_shortcode('hl_docs', array($this, 'render_docs'));
        add_shortcode('hl_coach_dashboard', array($this, 'render_coach_dashboard'));
        add_shortcode('hl_coach_mentors', array($this, 'render_coach_mentors'));
        add_shortcode('hl_coach_mentor_detail', array($this, 'render_coach_mentor_detail'));
        add_shortcode('hl_coach_reports', array($this, 'render_coach_reports'));
        add_shortcode('hl_coach_availability', array($this, 'render_coach_availability'));
        add_shortcode('hl_user_profile', array($this, 'render_user_profile'));

        // Backward-compatible aliases for pre-Rename-V3 shortcode names.
        // Production pages may still contain the old shortcode names.
        add_shortcode('hl_my_track', array($this, 'render_my_cycle'));
        add_shortcode('hl_track_workspace', array($this, 'render_cycle_workspace'));
        add_shortcode('hl_tracks_listing', array($this, 'render_cycles_listing'));
        add_shortcode('hl_track_dashboard', array($this, 'render_cycle_dashboard'));
    }

    public function enqueue_assets() {
        global $post;
        if (!is_a($post, 'WP_Post')) return;

        $has_shortcode = has_shortcode($post->post_content, 'hl_my_progress')
            || has_shortcode($post->post_content, 'hl_team_progress')
            || has_shortcode($post->post_content, 'hl_cycle_dashboard')
            || has_shortcode($post->post_content, 'hl_child_assessment')
            || has_shortcode($post->post_content, 'hl_teacher_assessment')
            || has_shortcode($post->post_content, 'hl_observations')
            || has_shortcode($post->post_content, 'hl_my_programs')
            || has_shortcode($post->post_content, 'hl_program_page')
            || has_shortcode($post->post_content, 'hl_component_page')
            || has_shortcode($post->post_content, 'hl_my_cycle')
            || has_shortcode($post->post_content, 'hl_my_track')
            || has_shortcode($post->post_content, 'hl_team_page')
            || has_shortcode($post->post_content, 'hl_classroom_page')
            || has_shortcode($post->post_content, 'hl_districts_listing')
            || has_shortcode($post->post_content, 'hl_district_page')
            || has_shortcode($post->post_content, 'hl_schools_listing')
            || has_shortcode($post->post_content, 'hl_school_page')
            || has_shortcode($post->post_content, 'hl_cycle_workspace')
            || has_shortcode($post->post_content, 'hl_track_workspace')
            || has_shortcode($post->post_content, 'hl_my_coaching')
            || has_shortcode($post->post_content, 'hl_cycles_listing')
            || has_shortcode($post->post_content, 'hl_tracks_listing')
            || has_shortcode($post->post_content, 'hl_institutions_listing')
            || has_shortcode($post->post_content, 'hl_coaching_hub')
            || has_shortcode($post->post_content, 'hl_classrooms_listing')
            || has_shortcode($post->post_content, 'hl_learners')
            || has_shortcode($post->post_content, 'hl_pathways_listing')
            || has_shortcode($post->post_content, 'hl_reports_hub')
            || has_shortcode($post->post_content, 'hl_my_team')
            || has_shortcode($post->post_content, 'hl_dashboard')
            || has_shortcode($post->post_content, 'hl_docs')
            || has_shortcode($post->post_content, 'hl_coach_dashboard')
            || has_shortcode($post->post_content, 'hl_coach_mentors')
            || has_shortcode($post->post_content, 'hl_coach_mentor_detail')
            || has_shortcode($post->post_content, 'hl_coach_reports')
            || has_shortcode($post->post_content, 'hl_coach_availability')
            || has_shortcode($post->post_content, 'hl_user_profile');

        if ($has_shortcode) {
            wp_enqueue_style('hl-google-fonts-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', array(), null);
            wp_enqueue_style('hl-frontend', HL_CORE_ASSETS_URL . 'css/frontend.css', array('hl-google-fonts-inter'), HL_CORE_VERSION);
            wp_enqueue_script('hl-frontend', HL_CORE_ASSETS_URL . 'js/frontend.js', array('jquery'), HL_CORE_VERSION, true);

        }
    }

    /**
     * Final style override — runs on wp_print_styles at PHP_INT_MAX.
     *
     * This is the LAST possible hook before styles are printed to HTML.
     * BB child theme enqueues at priority 99999 on wp_enqueue_scripts,
     * BP_Nouveau at PHP_INT_MAX on wp_enqueue_scripts. All of those have
     * fired by the time wp_print_styles runs. Nothing can re-add after this.
     *
     * Strategy: dequeue EVERY BB stylesheet by scanning source URLs,
     * then force-enqueue our own CSS if it's missing.
     */
    public function final_style_override() {
        global $post;
        if (!is_a($post, 'WP_Post')) return;

        // Check if this is an HL page.
        $content = $post->post_content;
        $is_hl_page = false;
        // Use a simple strpos check for speed — covers all hl_ shortcodes.
        if (strpos($content, '[hl_') !== false) {
            $is_hl_page = true;
        }

        if (!$is_hl_page) return;

        // Dequeue EVERY stylesheet from BB theme/platform by checking source URL.
        global $wp_styles;
        if (!$wp_styles) return;

        $kill_patterns = array(
            'buddyboss-theme',
            'buddyboss-platform',
            'bp-templates',
            'bp-nouveau',
        );

        foreach ($wp_styles->queue as $idx => $handle) {
            if (!isset($wp_styles->registered[$handle])) continue;
            $src = $wp_styles->registered[$handle]->src ?: '';
            $kill = false;

            // Check handle name.
            if (stripos($handle, 'buddyboss') !== false || stripos($handle, 'bp-') === 0) {
                $kill = true;
            }
            // Check source path.
            foreach ($kill_patterns as $pattern) {
                if (stripos($src, $pattern) !== false) {
                    $kill = true;
                    break;
                }
            }

            if ($kill) {
                unset($wp_styles->queue[$idx]);
            }
        }

        // Force-ensure hl-frontend is in the queue (BB may have removed it via dependency conflicts).
        if (!in_array('hl-frontend', $wp_styles->queue, true)) {
            if (!isset($wp_styles->registered['hl-google-fonts-inter'])) {
                wp_register_style('hl-google-fonts-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', array(), null);
            }
            if (!isset($wp_styles->registered['hl-frontend'])) {
                wp_register_style('hl-frontend', HL_CORE_ASSETS_URL . 'css/frontend.css', array('hl-google-fonts-inter'), HL_CORE_VERSION);
            }
            wp_enqueue_style('hl-google-fonts-inter');
            wp_enqueue_style('hl-frontend');
        }
    }

    /**
     * [hl_my_progress] - Participant's own progress dashboard
     */
    public function render_my_progress($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your progress.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array('cycle_id' => ''), $atts, 'hl_my_progress');
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

        $atts = shortcode_atts(array('cycle_id' => ''), $atts, 'hl_team_progress');
        $renderer = new HL_Frontend_Team_Progress();
        return $renderer->render($atts);
    }

    /**
     * [hl_child_assessment] - Teacher's child assessment form
     */
    public function render_child_assessment($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your child assessments.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array('instance_id' => ''), $atts, 'hl_child_assessment');
        $renderer = new HL_Frontend_Child_Assessment();
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
     * [hl_cycle_dashboard] - Leader/Staff cycle overview
     */
    public function render_cycle_dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view the cycle dashboard.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array('cycle_id' => ''), $atts, 'hl_cycle_dashboard');
        $renderer = new HL_Frontend_Cycle_Dashboard();
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
     * [hl_component_page] - Single component page (JFB form, redirect, etc.)
     */
    public function render_component_page($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this component.', 'hl-core') . '</div>';
        }

        $atts = shortcode_atts(array(), $atts, 'hl_component_page');
        $renderer = new HL_Frontend_Component_Page();
        return $renderer->render($atts);
    }

    /**
     * [hl_my_cycle] - Leader's auto-scoped cycle workspace
     */
    public function render_my_cycle($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your cycle.', 'hl-core') . '</div>';
        }

        // Ensure assets are loaded (fallback if has_shortcode detection missed this page).
        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_my_cycle');
        $renderer = new HL_Frontend_My_Cycle();
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
     * [hl_cycle_workspace] - Full cycle command center with Dashboard tab
     */
    public function render_cycle_workspace($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_cycle_workspace');
        $renderer = new HL_Frontend_Cycle_Workspace();
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
     * [hl_cycles_listing] - Cycle listing with scope filtering
     */
    public function render_cycles_listing($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }

        $this->ensure_frontend_assets();

        $atts = shortcode_atts(array(), $atts, 'hl_cycles_listing');
        $renderer = new HL_Frontend_Cycles_Listing();
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
     * [hl_dashboard] - Role-aware home dashboard
     */
    public function render_dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your dashboard.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_dashboard');
        $renderer = new HL_Frontend_Dashboard();
        return $renderer->render($atts);
    }

    /**
     * [hl_docs] - Documentation browser
     */
    public function render_docs($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view documentation.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_docs');
        $renderer = HL_Frontend_Docs::instance();
        return $renderer->render($atts);
    }

    /**
     * [hl_coach_dashboard] - Coach landing page
     */
    public function render_coach_dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your dashboard.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_coach_dashboard');
        $renderer = new HL_Frontend_Coach_Dashboard();
        return $renderer->render($atts);
    }

    /**
     * [hl_coach_mentors] - Coach's mentor roster
     */
    public function render_coach_mentors($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_coach_mentors');
        $renderer = new HL_Frontend_Coach_Mentors();
        return $renderer->render($atts);
    }

    /**
     * [hl_coach_mentor_detail] - Mentor detail with tabs
     */
    public function render_coach_mentor_detail($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_coach_mentor_detail');
        $renderer = new HL_Frontend_Coach_Mentor_Detail();
        return $renderer->render($atts);
    }

    /**
     * [hl_coach_reports] - Coach aggregated reports
     */
    public function render_coach_reports($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_coach_reports');
        $renderer = new HL_Frontend_Coach_Reports();
        return $renderer->render($atts);
    }

    /**
     * [hl_coach_availability] - Coach weekly schedule
     */
    public function render_coach_availability($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_coach_availability');
        $renderer = new HL_Frontend_Coach_Availability();
        return $renderer->render($atts);
    }

    /**
     * [hl_user_profile] - User profile page
     */
    public function render_user_profile($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this profile.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_user_profile');
        $renderer = new HL_Frontend_User_Profile();
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
