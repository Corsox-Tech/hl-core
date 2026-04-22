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
        // Disable LD Focus Mode redirect BEFORE it fires (priority < 99).
        // Focus Mode does a 302 redirect from lesson URLs to course URLs,
        // which prevents our lesson template from ever loading.
        add_action('template_redirect', array($this, 'disable_ld_focus_mode_redirect'), 1);
        // Priority 99999: must run AFTER all other template_include filters.
        add_filter('template_include', array($this, 'use_hl_template'), 99999);
        add_action('wp_enqueue_scripts', array($this, 'dequeue_bb_ld_assets_on_ld_pages'), 9999);
        // Second pass: catch styles enqueued after wp_enqueue_scripts (e.g. on wp_print_styles).
        add_action('wp_print_styles', array($this, 'dequeue_bb_ld_assets_on_ld_pages'), 9999);
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

    /**
     * Intercept HL pages and serve through the plugin's own template.
     * Bypasses the active theme entirely for pages with [hl_*] shortcodes.
     *
     * @param string $template Theme template path.
     * @return string Template path to use.
     */
    /**
     * Disable LD Focus Mode redirect on lesson pages.
     *
     * LD Focus Mode does a 302 redirect from lesson URLs to the parent
     * course URL, preventing our lesson template from loading. We remove
     * the Focus Mode template_include filter entirely — our ld-lesson.php
     * template is the focus experience.
     */
    public function disable_ld_focus_mode_redirect() {
        if (!is_singular(array('sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'))) {
            return;
        }
        // Remove LD's focus mode template filter (priority 99).
        remove_filter('template_include', 'learndash_30_focus_mode', 99);

        // Remove BB's focus mode override (priority 999).
        if (function_exists('buddyboss_theme')) {
            $bb_theme = buddyboss_theme();
            if ($bb_theme && method_exists($bb_theme, 'learndash_helper')) {
                $bb_ld = $bb_theme->learndash_helper();
                if ($bb_ld) {
                    remove_filter('template_include', array($bb_ld, 'ld_30_focus_mode_template'), 999);
                }
            }
        }
    }

    public function use_hl_template($template) {
        // Auth shortcode pages — full-bleed template (no sidebar/topbar).
        if (is_singular('page')) {
            global $post;
            $auth_shortcodes = array('[hl_login]', '[hl_password_reset]', '[hl_profile_setup]');
            foreach ($auth_shortcodes as $sc) {
                if (strpos($post->post_content, $sc) !== false) {
                    return HL_CORE_PLUGIN_DIR . 'templates/hl-auth.php';
                }
            }
            // Existing: regular HL shortcode pages
            if (strpos($post->post_content, '[hl_') !== false) {
                return HL_CORE_PLUGIN_DIR . 'templates/hl-page.php';
            }
        }

        // LearnDash lesson pages — custom template with course outline panel.
        // Focus Mode redirect is disabled by disable_ld_focus_mode_redirect()
        // so is_singular('sfwd-lessons') now works correctly.
        if (is_singular('sfwd-lessons')) {
            return HL_CORE_PLUGIN_DIR . 'templates/ld-lesson.php';
        }

        // LearnDash course pages — custom template with course info sidebar.
        if (is_singular('sfwd-courses')) {
            return HL_CORE_PLUGIN_DIR . 'templates/ld-course.php';
        }

        return $template;
    }

    /**
     * Dequeue ALL BuddyBoss CSS/JS on LD template pages.
     *
     * Runs at priority 9999 on wp_enqueue_scripts so everything is already
     * enqueued. Applies to both sfwd-lessons and sfwd-courses (both use
     * our custom templates that provide complete styling via frontend.css).
     *
     * Uses dynamic detection: dequeues any handle whose name or source URL
     * contains "buddyboss" — no handle list to maintain.
     *
     * KEEPS: LearnDash core CSS/JS, GrassBlade xAPI, jQuery, WP core.
     */
    public function dequeue_bb_ld_assets_on_ld_pages() {
        if (!is_singular(array('sfwd-lessons', 'sfwd-courses'))) {
            return;
        }

        global $wp_styles, $wp_scripts;

        // --- CSS: dequeue ALL BuddyBoss theme + platform styles ---
        if (!empty($wp_styles->registered)) {
            foreach ($wp_styles->registered as $handle => $style) {
                $src = $style->src ?? '';
                if (
                    strpos($handle, 'buddyboss') !== false ||
                    strpos($handle, 'bp-nouveau') !== false ||
                    strpos($src, 'buddyboss-theme') !== false ||
                    strpos($src, 'buddyboss-platform') !== false
                ) {
                    wp_dequeue_style($handle);
                    wp_deregister_style($handle);
                }
            }
        }

        // --- JS: dequeue ALL BuddyBoss theme + platform scripts ---
        if (!empty($wp_scripts->registered)) {
            foreach ($wp_scripts->registered as $handle => $script) {
                $src = $script->src ?? '';
                if (
                    strpos($handle, 'buddyboss-theme') !== false ||
                    strpos($handle, 'buddyboss-platform') !== false ||
                    strpos($handle, 'bp-nouveau') !== false ||
                    strpos($src, 'buddyboss-theme') !== false ||
                    strpos($src, 'buddyboss-platform') !== false
                ) {
                    wp_dequeue_script($handle);
                    wp_deregister_script($handle);
                }
            }
        }

        // --- BB child theme CSS (handle may be enqueued after dynamic scan) ---
        wp_dequeue_style('buddyboss-child-css');
        wp_deregister_style('buddyboss-child-css');

        // --- Non-essential LD styles (quiz chrome, pager, presenter) ---
        $ld_css_dequeue = array(
            'learndash_quiz_front_css',
            'jquery-dropdown-css',
            'learndash_pager_css',
            'learndash-presenter-mode-style',
        );
        foreach ($ld_css_dequeue as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }

        // --- Non-essential LD JS ---
        wp_dequeue_script('wpProQuiz_front_javascript');
        wp_deregister_script('wpProQuiz_front_javascript');

        // NOTE: The following are intentionally KEPT:
        // CSS: learndash_style, sfwd_front_css, learndash_template_style_css,
        //      learndash-ld30-shortcodes-style (LD content rendering).
        // JS:  learndash_template_script_js, learndash-ld30-shortcodes-script,
        //      learndash_video_script_js, learndash_cookie_script_js,
        //      jquery, jquery-cookie, all grassblade/gb-* handles.
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
        add_shortcode('hl_feature_tracker', array($this, 'render_feature_tracker'));
        add_shortcode('hl_login',           array('HL_Frontend_Login', 'render'));
        add_shortcode('hl_password_reset',  array('HL_Frontend_Password_Reset', 'render'));
        add_shortcode('hl_profile_setup',   array('HL_Frontend_Profile_Setup', 'render'));

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

        // Template handles its own assets — skip WP enqueue for HL pages.
        if (strpos($post->post_content, '[hl_') !== false) return;

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
            // Also enqueue dashicons for sidebar icons.
            wp_enqueue_style('dashicons');
        }

        // TinyMCE editor assets for RP Notes rich-text fields (tickets #8/#10).
        // Must be enqueued on wp_enqueue_scripts (before wp_head) so the editor
        // bundle prints correctly; calling wp_enqueue_editor() from inside the
        // shortcode render is too late. RP Notes appears on the Component Page
        // (reflective_practice_session dispatcher) and My Coaching (Schedule
        // Session dispatcher).
        if (function_exists('wp_enqueue_editor')
            && (has_shortcode($post->post_content, 'hl_component_page')
                || has_shortcode($post->post_content, 'hl_my_coaching'))) {
            wp_enqueue_editor();
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
     * [hl_component_page] - Single component page (form, redirect, etc.)
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
     * [hl_feature_tracker] - Feature Tracker page for admins and coaches
     */
    public function render_feature_tracker($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $renderer = HL_Frontend_Feature_Tracker::instance();
        return $renderer->render($atts);
    }

    /**
     * Ensure frontend CSS and JS are enqueued.
     * Called from shortcode render methods as a fallback in case
     * has_shortcode() detection failed (e.g. page builders, widgets).
     */
    private function ensure_frontend_assets() {
        // Template hardcodes these assets — don't double-enqueue.
        global $post;
        if (is_a($post, 'WP_Post') && strpos($post->post_content, '[hl_') !== false) return;

        if (!wp_style_is('hl-frontend', 'enqueued')) {
            wp_enqueue_style('hl-frontend', HL_CORE_ASSETS_URL . 'css/frontend.css', array(), HL_CORE_VERSION);
        }
        if (!wp_script_is('hl-frontend', 'enqueued')) {
            wp_enqueue_script('hl-frontend', HL_CORE_ASSETS_URL . 'js/frontend.js', array('jquery'), HL_CORE_VERSION, true);
        }
    }
}
