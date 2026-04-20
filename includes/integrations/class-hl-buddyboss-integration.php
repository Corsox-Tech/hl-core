<?php
if (!defined('ABSPATH')) exit;

/**
 * BuddyBoss Integration
 *
 * Handles BuddyBoss-specific redirects, login flow, role detection,
 * and builds the role-conditional menu item list consumed by the
 * HL page template (templates/hl-page.php).
 *
 * Sidebar rendering, BuddyPanel injection, profile dropdown injection,
 * and JS fallback code were removed — the HL page template now owns
 * all layout and navigation rendering.
 *
 * @package HL_Core
 */
class HL_BuddyBoss_Integration {

    private static $instance = null;

    /**
     * Cached user HL roles keyed by user_id.
     *
     * @var array<int, string[]>
     */
    private static $role_cache = array();

    /**
     * Cached shortcode → page URL map.
     *
     * @var array<string, string>
     */
    private static $page_url_cache = array();

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Invalidate badge cache when any component state changes (works even without BuddyBoss).
        add_action('hl_core_recompute_rollups', array($this, 'invalidate_badge_cache'), 10, 1);

        if (!$this->is_active()) {
            return;
        }

        // 0. Fix BuddyBoss login page: remove error styling for bpnoaccess
        //    (the redirect is correct but it shouldn't look like an error).
        add_filter('bp_wp_login_error', array($this, 'soften_bpnoaccess_message'), 10, 2);
        add_filter('shake_error_codes', array($this, 'remove_bp_shake_code'));
        add_filter('login_message', array($this, 'add_bpnoaccess_welcome_message'));

        // 0a. Login redirect — HL-enrolled users go to the HL Dashboard.
        add_filter('login_redirect', array($this, 'hl_login_redirect'), 999, 3);

        // 0a. Template redirect — redirect enrolled users from BB Dashboard to HL Dashboard.
        add_action('template_redirect', array($this, 'redirect_bb_dashboard_to_hl'));

        // 0a2. Template redirect — redirect BB member profiles to HL User Profile.
        add_action('template_redirect', array($this, 'redirect_bb_profile_to_hl'));

        // 1. Inject HL sidebar + topbar on non-HL pages (LearnDash courses, BB community, etc.).
        add_action('wp_body_open', array($this, 'render_nav_on_theme_pages'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_nav_assets_on_theme_pages'));
        add_filter('body_class', array($this, 'add_nav_body_class'));

        // 2. Allow coaches to "View As" participants via BB Members Switching.
        add_filter('map_meta_cap', array($this, 'allow_coach_view_as'), 20, 4);

    }

    // =========================================================================
    // Environment Checks
    // =========================================================================

    /**
     * Check if BuddyBoss / BuddyPress is active.
     *
     * @return bool
     */
    public function is_active() {
        return function_exists('buddypress') && $this->is_bb_theme_active();
    }

    /**
     * Check if the BuddyBoss theme (or a child theme) is in use.
     *
     * @return bool
     */
    private function is_bb_theme_active() {
        $theme = wp_get_theme();
        $name  = strtolower($theme->get('Name'));
        $tpl   = strtolower((string) $theme->get('Template'));

        return strpos($name, 'buddyboss') !== false
            || strpos($tpl, 'buddyboss') !== false;
    }

    // =========================================================================
    // 2. "View As" — allow admins and coaches to switch users
    // =========================================================================

    /**
     * Grant admins and coaches the ability to switch users via BB Members Switching.
     *
     * Admins can switch to anyone except other admins.
     * Coaches (non-admin) can switch to participants only — not admins or coaches.
     * Runs at priority 20 (after User Switching's own map_meta_cap at 10).
     *
     * @param string[] $caps    Required primitive capabilities.
     * @param string   $cap     The meta capability being checked.
     * @param int      $user_id The user being checked.
     * @param array    $args    Additional arguments (target user ID for switch_to_user).
     * @return string[]
     */
    public function allow_coach_view_as( $caps, $cap, $user_id, $args ) {
        if ( 'switch_to_user' !== $cap ) {
            return $caps;
        }

        $user     = new WP_User( $user_id );
        $is_admin = user_can( $user_id, 'manage_options' );
        $is_coach = in_array( 'coach', (array) $user->roles, true );

        if ( ! $is_admin && ! $is_coach ) {
            return $caps;
        }

        $target_user_id = $args[0] ?? 0;
        if ( ! $target_user_id || $target_user_id === $user_id ) {
            return $caps;
        }

        $target_is_admin = user_can( $target_user_id, 'manage_options' );

        // Nobody can switch to admins.
        if ( $target_is_admin ) {
            return array( 'do_not_allow' );
        }

        // Admins can switch to anyone else (including coaches).
        if ( $is_admin ) {
            return array( 'exist' );
        }

        // Coaches (non-admin) cannot switch to other coaches.
        $target = new WP_User( $target_user_id );
        if ( in_array( 'coach', (array) $target->roles, true ) ) {
            return array( 'do_not_allow' );
        }

        return array( 'exist' );
    }

    // =========================================================================
    // 0. Login Redirect
    // =========================================================================

    /**
     * Replace the BuddyBoss bpnoaccess error message with a neutral welcome.
     *
     * BuddyBoss redirects non-logged-in users to wp-login.php with
     * action=bpnoaccess and adds an error via wp_login_errors. This makes
     * the login page look like the user did something wrong. We replace
     * the message with a friendly, non-error string and return it through
     * WordPress's login_message filter instead so it renders as a plain
     * message, not an error.
     *
     * @param string $message BP error message.
     * @param string $redirect_to Where the user will go after login.
     * @return string
     */
    public function soften_bpnoaccess_message($message, $redirect_to) {
        return ''; // Return empty to suppress the error; we add a message via login_message instead.
    }

    /**
     * Remove bp_no_access from the shake error codes list.
     *
     * @param array $codes Shake error codes.
     * @return array
     */
    public function remove_bp_shake_code($codes) {
        return array_diff($codes, array('bp_no_access'));
    }

    /**
     * Show a friendly welcome message on the login page instead of an error
     * when the user arrives via BuddyBoss bpnoaccess redirect.
     *
     * @param string $message Existing login message HTML.
     * @return string
     */
    public function add_bpnoaccess_welcome_message($message) {
        if (empty($_GET['action']) || $_GET['action'] !== 'bpnoaccess') {
            return $message;
        }

        $message .= '<p class="message">' . esc_html__('Welcome to Housman Learning Academy. Please log in to continue.', 'hl-core') . '</p>';
        return $message;
    }

    /**
     * Redirect HL-enrolled users to the HL Dashboard after login.
     *
     * Non-enrolled users (e.g. Short Course subscribers) get the default
     * WordPress/BuddyBoss redirect so their experience is unchanged.
     *
     * @param string           $redirect_to Default redirect URL.
     * @param string           $requested   Requested redirect URL.
     * @param WP_User|WP_Error $user        Logged-in user or error.
     * @return string
     */
    public function hl_login_redirect($redirect_to, $requested, $user) {
        if (!($user instanceof \WP_User)) {
            return $redirect_to;
        }

        // Coach-only users (no enrollment): send to Coach Dashboard.
        $is_coach = in_array('coach', (array) $user->roles, true);
        $hl_roles = $this->get_user_hl_roles($user->ID);
        $is_staff = user_can($user, 'manage_options');

        if ($is_coach && empty($hl_roles) && !$is_staff) {
            $coach_url = $this->find_shortcode_page_url('hl_coach_dashboard');
            if ($coach_url) {
                return $coach_url;
            }
        }

        if (empty($hl_roles) && !$is_coach) {
            return $redirect_to;
        }

        $dashboard_url = $this->find_shortcode_page_url('hl_dashboard');
        if ($dashboard_url) {
            return $dashboard_url;
        }

        return $redirect_to;
    }

    // =========================================================================
    // 0a. BB Dashboard → HL Dashboard Redirect
    // =========================================================================

    /**
     * Redirect enrolled users (and staff) from the BuddyBoss member dashboard
     * to the HL Dashboard shortcode page.
     *
     * The BB Dashboard is an Elementor page that doesn't render our [hl_dashboard]
     * shortcode. Enrolled users should see the HL Dashboard instead.
     */
    public function redirect_bb_dashboard_to_hl() {
        if (!is_user_logged_in() || !is_page()) {
            return;
        }

        $current_page_id = get_queried_object_id();
        $current_slug    = get_post_field('post_name', $current_page_id);

        $user_id  = get_current_user_id();
        $roles    = $this->get_user_hl_roles($user_id);
        $is_staff = current_user_can('manage_hl_core');
        $is_coach = in_array('coach', (array) wp_get_current_user()->roles, true);
        $has_enrollment = !empty($roles);

        // Coach-only users (no enrollment, not staff): redirect any dashboard
        // page to the Coach Dashboard for a seamless coach experience.
        if ($is_coach && !$has_enrollment && !$is_staff) {
            $coach_dashboard_url = $this->find_shortcode_page_url('hl_coach_dashboard');
            if ($coach_dashboard_url) {
                $coach_page_id = url_to_postid($coach_dashboard_url);
                if ($current_page_id !== $coach_page_id) {
                    // Redirect from BB dashboards and HL dashboard to Coach Dashboard.
                    $dashboard_slugs = array('dashboard', 'dashboard-2', 'dashboard-3');
                    if (in_array($current_slug, $dashboard_slugs, true)) {
                        wp_redirect($coach_dashboard_url);
                        exit;
                    }
                }
            }
            return; // Don't redirect coach away from other pages.
        }

        $hl_dashboard_url = $this->find_shortcode_page_url('hl_dashboard');
        if (empty($hl_dashboard_url)) {
            return;
        }

        // Get the HL Dashboard page ID to avoid redirect loops.
        $hl_dashboard_page_id = url_to_postid($hl_dashboard_url);
        if ($current_page_id === $hl_dashboard_page_id) {
            return; // Already on the HL Dashboard — no redirect.
        }

        // Only redirect from known "dashboard" pages (BB Dashboard, duplicates).
        $dashboard_slugs = array('dashboard', 'dashboard-2', 'dashboard-3');
        if (!in_array($current_slug, $dashboard_slugs, true)) {
            return;
        }

        if (!$has_enrollment && !$is_staff) {
            return; // Non-enrolled, non-staff users keep the default BB dashboard.
        }

        wp_redirect($hl_dashboard_url);
        exit;
    }

    /**
     * Redirect BuddyBoss member profile pages to the HL User Profile.
     *
     * When a user clicks a name in the forum (or any link to a BB profile),
     * they land on the HL profile page instead of the empty BB profile.
     *
     * Escape hatch: ?bb=1 skips the redirect (for debugging).
     */
    public function redirect_bb_profile_to_hl() {
        // Skip if not on a BP member page.
        if (!function_exists('bp_is_user') || !bp_is_user()) {
            return;
        }

        // Escape hatch for debugging.
        if (!empty($_GET['bb'])) {
            return;
        }

        // Get the displayed user ID from BuddyPress.
        $displayed_user_id = bp_displayed_user_id();
        if (!$displayed_user_id) {
            return;
        }

        // Find the HL User Profile page.
        $profile_url = $this->find_shortcode_page_url('hl_user_profile');
        if (empty($profile_url)) {
            return;
        }

        $redirect_url = add_query_arg('user_id', $displayed_user_id, $profile_url);

        wp_redirect($redirect_url, 302);
        exit;
    }

    // =========================================================================
    // Shared Menu Builder
    // =========================================================================

    /**
     * Get the menu items for the current logged-in user.
     *
     * Cached per request to avoid rebuilding for multiple hooks.
     *
     * @return array<int, array{slug: string, label: string, url: string, icon: string}>
     */
    public function get_menu_items_for_current_user() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            $cached = array();
            return $cached;
        }

        $is_staff    = current_user_can('manage_hl_core');
        $roles       = $this->get_user_hl_roles($user_id);
        $has_enrollment = !empty($roles);

        $is_coach = in_array('coach', (array) wp_get_current_user()->roles, true);

        // Users without staff cap AND without active HL enrollments see nothing.
        if (!$has_enrollment && !$is_staff && !$is_coach) {
            $cached = array();
            return $cached;
        }

        $is_control_only = $has_enrollment ? $this->is_control_group_only($user_id) : false;
        $cached = $this->build_menu_items($roles, $is_staff, $has_enrollment, $is_control_only);

        // Inject available-component badge count into the "my-programs" item.
        if ( $has_enrollment ) {
            $badge_count = $this->count_available_components( $user_id );
            foreach ( $cached as &$item ) {
                $item['badge'] = ( $item['slug'] === 'my-programs' && $badge_count > 0 )
                    ? $badge_count
                    : 0;
            }
            unset( $item );
        }

        return $cached;
    }

    /**
     * Build the list of visible menu items for the current user.
     *
     * Role-based visibility (section 16 spec):
     *
     * "My" pages require an active enrollment — staff without enrollment
     * see only management/directory pages:
     *   My Programs, My Coaching: any active enrollment
     *   My Team: mentor role in enrollment
     *   My Cycle: district_leader, school_leader, or mentor role
     *
     * Directory/management pages:
     *   Tracks, Schools: staff OR district_leader OR school_leader
     *   Classrooms: staff OR district_leader OR school_leader OR teacher
     *   Learners: staff OR district_leader OR school_leader OR mentor
     *   Pathways: staff only
     *   Coaching Hub: staff OR mentor
     *   Reports: staff OR district_leader OR school_leader
     *
     * Multi-role users see the union of all their role menus.
     *
     * @param string[] $roles          Active HL enrollment roles for the user.
     * @param bool     $is_staff       Whether user has manage_hl_core capability.
     * @param bool     $has_enrollment Whether user has any active HL enrollment.
     * @return array<int, array{slug: string, label: string, url: string, icon: string}>
     */
    private function build_menu_items(array $roles, bool $is_staff, bool $has_enrollment, bool $is_control_only = false) {
        $is_district_leader = in_array('district_leader', $roles, true);
        $is_leader  = in_array('school_leader', $roles, true) || $is_district_leader;
        $is_mentor  = in_array('mentor', $roles, true);
        $is_teacher = in_array('teacher', $roles, true);
        $is_coach   = in_array('coach', (array) wp_get_current_user()->roles, true);
        // Leader-only = leader but not also a teacher/mentor (no personal program view).
        $is_leader_only = $is_leader && !$is_teacher && !$is_mentor;
        $my_school_label = $is_district_leader ? __('My District', 'hl-core') : __('My School', 'hl-core');
        $is_admin_level = current_user_can('manage_options');

        // Define all possible menu items with their visibility rules.
        // Each entry: [ slug, shortcode, label, icon, show_condition ]
        // Role matrix updated 2026-04-02:
        //   Teacher:      My Programs, Classrooms
        //   Mentor:       My Programs, My Coaching, My Team, Classrooms
        //   Leader-only:  My Programs (Streamlined), My School, Classrooms, Reports
        //   Leader+teach: My Programs, My School, Classrooms, Reports
        //   Coach:        Coaching Home, My Programs, My Mentors, Learners, My Availability, Coach Reports, Documentation
        //   Admin:        My Programs, Classrooms, Cycles, Schools, Learners, Pathways, Coaching Hub, Reports, Documentation

        // Coaches (not admins) get a dedicated, streamlined menu.
        if ($is_coach && !$is_admin_level) {
            $menu_def = array(
                array('coaching-home',      'hl_coach_dashboard',    __('Coaching Home', 'hl-core'),    'dashicons-dashboard',            true),
                array('my-programs',        'hl_my_programs',        __('My Programs', 'hl-core'),      'dashicons-portfolio',            true),
                array('coach-mentors',      'hl_coach_mentors',      __('My Mentors', 'hl-core'),       'dashicons-groups',               true),
                array('learners',           'hl_learners',           __('Learners', 'hl-core'),         'dashicons-id-alt',               true),
                array('coach-availability', 'hl_coach_availability', __('My Availability', 'hl-core'),  'dashicons-calendar-alt',         true),
                array('coach-reports',      'hl_coach_reports',      __('Coach Reports', 'hl-core'),    'dashicons-chart-bar',            true),
                array('documentation',      'hl_docs',               __('Documentation', 'hl-core'),    'dashicons-media-document',       true),
                array('community-global',   null,                    __('Global Community', 'hl-core'),    'dashicons-admin-site-alt3',   true),
                array('community-school',   null,                    __('My School Community', 'hl-core'), 'dashicons-groups',            true),
                array('feature-tracker', 'hl_feature_tracker', __('Feature Tracker', 'hl-core'), 'dashicons-feedback', true),
                array('support',         null,                 __('Support', 'hl-core'),         'dashicons-email-alt',            true),
            );
        } else {
            $menu_def = array(
                // --- Personal (require active enrollment) ---
                array('my-programs',    'hl_my_programs',          __('My Programs', 'hl-core'),    'dashicons-portfolio',            $has_enrollment && ($is_teacher || $is_mentor || $is_leader || $is_staff || $is_coach)),
                array('my-coaching',    'hl_my_coaching',          __('My Coaching', 'hl-core'),    'dashicons-video-alt2',           $is_mentor && !$is_control_only),
                array('my-team',        'hl_my_team',              __('My Team', 'hl-core'),        'dashicons-groups',               $is_mentor),
                // --- Leader ---
                array('my-school',      'hl_my_cycle',             $my_school_label,                'dashicons-building',             $is_leader && !$is_staff),
                // --- Directories / Management ---
                array('cycles',         'hl_cycles_listing',       __('Cycles', 'hl-core'),         'dashicons-groups',               $is_staff),
                array('classrooms',     'hl_classrooms_listing',   __('Classrooms', 'hl-core'),     'dashicons-welcome-learn-more',   $is_staff || $is_leader || $is_teacher || $is_mentor),
                array('learners',       'hl_learners',             __('Learners', 'hl-core'),       'dashicons-id-alt',               $is_staff),
                // --- Staff tools ---
                array('pathways',       'hl_pathways_listing',     __('Pathways', 'hl-core'),       'dashicons-randomize',            false),
                array('coaching-hub',   'hl_coaching_hub',         __('Coaching Hub', 'hl-core'),   'dashicons-format-chat',          $is_staff),
                // --- Coach tools (when admin also has coach role) ---
                array('coaching-home',   'hl_coach_dashboard',      __('Coaching Home', 'hl-core'),    'dashicons-dashboard',            $is_coach),
                array('coach-mentors',   'hl_coach_mentors',        __('My Mentors', 'hl-core'),       'dashicons-groups',               $is_coach),
                array('coach-availability', 'hl_coach_availability', __('My Availability', 'hl-core'), 'dashicons-calendar-alt',         $is_coach),
                array('coach-reports',   'hl_coach_reports',        __('Coach Reports', 'hl-core'),    'dashicons-chart-bar',            $is_coach),
                array('reports',        'hl_reports_hub',          __('Reports', 'hl-core'),        'dashicons-chart-bar',            $is_staff || $is_leader),
                // --- Documentation ---
                array('documentation', 'hl_docs',                 __('Documentation', 'hl-core'),  'dashicons-media-document',       $is_admin_level),
                array('community-global', null,                   __('Global Community', 'hl-core'),    'dashicons-admin-site-alt3',  $has_enrollment || $is_coach || $is_staff),
                array('community-school', null,                   __('My School Community', 'hl-core'), 'dashicons-groups',           $has_enrollment || $is_coach || $is_staff),
                array('feature-tracker', 'hl_feature_tracker', __('Feature Tracker', 'hl-core'), 'dashicons-feedback', $is_staff),
                array('support',        null,                 __('Support', 'hl-core'),         'dashicons-sos',                  true),
                // --- Admin ---
                array('wp-admin', null, __('WP Admin', 'hl-core'), 'dashicons-admin-generic', $is_admin_level),
            );
        }

        $items = array();
        foreach ($menu_def as $def) {
            list($slug, $shortcode, $label, $icon, $visible) = $def;
            if (!$visible) {
                continue;
            }
            if ($shortcode === null) {
                if ($slug === 'wp-admin') {
                    $url = admin_url();
                } elseif ($slug === 'community-global') {
                    $url = home_url('/groups/begin-to-ecsel-global-community/');
                } elseif ($slug === 'community-school') {
                    // Resolve per-user: hide the item if the user has no mapped school.
                    $school_slug = class_exists('HL_BB_Group_Sync_Service')
                        ? HL_BB_Group_Sync_Service::get_user_primary_school_group_slug(get_current_user_id())
                        : null;
                    $url = $school_slug ? home_url('/groups/' . $school_slug . '/') : '';
                } elseif ($slug === 'support') {
                    $support_page = get_page_by_path('support');
                    $url = $support_page ? get_permalink($support_page) : '';
                } else {
                    $url = '';
                }
            } else {
                $url = $this->find_shortcode_page_url($shortcode);
            }
            if ($url) {
                $items[] = array(
                    'slug'  => $slug,
                    'label' => $label,
                    'url'   => $url,
                    'icon'  => $icon,
                );
            }
        }

        return $items;
    }

    // =========================================================================
    // Role Detection
    // =========================================================================

    /**
     * Get the distinct HL enrollment roles for a user.
     *
     * Queries `hl_enrollment` for active enrollments, parses the JSON
     * `roles` column, and returns a flat unique array of role strings.
     * Results are statically cached per user_id within the request.
     *
     * @param int $user_id WordPress user ID.
     * @return string[] e.g. ['teacher', 'school_leader']
     */
    public function get_user_hl_roles($user_id) {
        // Picker mode: override with the view-as role (admin only).
        if ( class_exists( 'HL_Tour_Service' ) && HL_Tour_Service::is_picker_mode() ) {
            return array( HL_Tour_Service::get_view_as_role() );
        }

        $user_id = absint($user_id);
        if (!$user_id) {
            return array();
        }

        if (isset(self::$role_cache[$user_id])) {
            return self::$role_cache[$user_id];
        }

        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT e.roles
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->prefix}hl_cycle c ON e.cycle_id = c.cycle_id
             WHERE e.user_id = %d AND e.status = 'active' AND c.status != 'archived'",
            $user_id
        ));

        $all_roles = array();

        foreach ($rows as $roles_json) {
            if (empty($roles_json)) {
                continue;
            }
            foreach (HL_Roles::parse_stored($roles_json) as $role) {
                $all_roles[$role] = true;
            }
        }

        $result = array_keys($all_roles);
        self::$role_cache[$user_id] = $result;

        return $result;
    }

    // =========================================================================
    // Control Group Detection
    // =========================================================================

    /**
     * Check if all of a user's active enrollments belong to control group tracks.
     *
     * @param int $user_id
     * @return bool True if the user has enrollments AND all are control group.
     */
    private function is_control_group_only($user_id) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.is_control_group
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->prefix}hl_cycle t ON e.cycle_id = t.cycle_id
             WHERE e.user_id = %d AND e.status = 'active'",
            $user_id
        ), ARRAY_A);

        if (empty($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (empty($row['is_control_group'])) {
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // Badge Cache Invalidation
    // =========================================================================

    /**
     * Delete the available-component badge transient for the user who owns
     * the given enrollment.  Hooked to hl_core_recompute_rollups.
     *
     * @param int $enrollment_id
     */
    public function invalidate_badge_cache( $enrollment_id ) {
        global $wpdb;
        $user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            absint( $enrollment_id )
        ) );
        if ( $user_id ) {
            delete_transient( 'hl_avail_count_' . $user_id );
        }
    }

    // =========================================================================
    // Available Component Badge
    // =========================================================================

    /**
     * Count components with availability_status === 'available' across all
     * active enrollments for a user.  Result is cached in a 5-minute
     * transient to avoid per-page-load overhead.
     *
     * @param int $user_id WordPress user ID.
     * @return int Number of available (unlocked + not completed) components.
     */
    private function count_available_components( $user_id ) {
        $user_id       = absint( $user_id );
        $transient_key = 'hl_avail_count_' . $user_id;

        $cached = get_transient( $transient_key );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        $enrollment_repo = new HL_Enrollment_Repository();
        $pa_service      = new HL_Pathway_Assignment_Service();
        $component_repo  = new HL_Component_Repository();
        $rules_engine    = new HL_Rules_Engine_Service();

        $enrollments = $enrollment_repo->get_by_user_id( $user_id, 'active' );
        $count       = 0;

        foreach ( $enrollments as $enrollment ) {
            $pathways = $pa_service->get_pathways_for_enrollment( $enrollment->enrollment_id );

            foreach ( $pathways as $pw ) {
                $components = $component_repo->get_by_pathway( $pw['pathway_id'] );

                foreach ( $components as $component ) {
                    if ( $component->visibility === 'staff_only' ) {
                        continue;
                    }
                    $avail = $rules_engine->compute_availability(
                        $enrollment->enrollment_id,
                        $component->component_id
                    );
                    if ( $avail['availability_status'] === 'available' ) {
                        $count++;
                    }
                }
            }
        }

        set_transient( $transient_key, $count, 5 * MINUTE_IN_SECONDS );

        return $count;
    }

    // =========================================================================
    // Page URL Discovery
    // =========================================================================

    /**
     * Find the URL of a published page containing a given shortcode.
     *
     * Uses the same pattern as HL_Frontend_Program_Page::find_shortcode_page_url().
     * Results are statically cached per shortcode within the request.
     *
     * @param string $shortcode Shortcode tag without brackets, e.g. 'hl_my_programs'.
     * @return string Page permalink or empty string if not found.
     */
    private function find_shortcode_page_url( $shortcode ) {
        return HL_Page_Cache::get_url( $shortcode );
    }

    // =========================================================================
    // Nav Shell on BB Theme Pages (LearnDash, Community, etc.)
    // =========================================================================

    /**
     * Check if the current page uses an HL-managed template.
     *
     * Returns true for pages with [hl_*] shortcodes (rendered by hl-page.php),
     * LearnDash lesson pages (rendered by ld-lesson.php), and LearnDash
     * course pages (rendered by ld-course.php). All of these templates
     * include their own sidebar + topbar, so BB's nav overlay is skipped.
     */
    private function is_hl_template_page() {
        // Lesson + course pages use our custom templates with built-in nav.
        // Focus Mode redirect is disabled by HL_Shortcodes, so is_singular works.
        if (is_singular(array('sfwd-lessons', 'sfwd-courses'))) {
            return true;
        }

        global $post;
        if (!is_a($post, 'WP_Post')) return false;
        return (strpos($post->post_content, '[hl_') !== false);
    }

    /**
     * Check if nav should render: logged in, not HL page, not login/register.
     */
    private function should_render_nav() {
        if (!is_user_logged_in()) return false;
        if ($this->is_hl_template_page()) return false;
        if (!is_singular() && !is_archive() && !is_home() && !is_front_page()
            && !function_exists('bp_is_current_component')) return false;
        return true;
    }

    /**
     * Inject sidebar + topbar HTML on BB theme pages via wp_body_open.
     */
    public function render_nav_on_theme_pages() {
        if (!$this->should_render_nav()) return;

        $menu_items = $this->get_menu_items_for_current_user();
        if (empty($menu_items)) return;

        $user         = wp_get_current_user();
        $display_name = $user->display_name ?: $user->user_login;
        $avatar_url   = get_avatar_url($user->ID, array('size' => 32));

        $initials = '';
        if ($user->first_name) $initials .= strtoupper(substr($user->first_name, 0, 1));
        if ($user->last_name)  $initials .= strtoupper(substr($user->last_name, 0, 1));
        if (!$initials && $display_name) $initials = strtoupper(substr($display_name, 0, 2));

        $current_url   = trailingslashit(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
        $dashboard_url = HL_Core::get_dashboard_url();

        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        // Detect "View As" session.
        $old_user        = function_exists('user_switching_get_old_user') ? user_switching_get_old_user() : null;
        $switch_back_url = '';
        if ($old_user) {
            if (class_exists('BP_Core_Members_Switching') && method_exists('BP_Core_Members_Switching', 'switch_off_url')) {
                $switch_back_url = BP_Core_Members_Switching::switch_off_url($user);
            } elseif (function_exists('user_switching_get_switchback_url')) {
                $switch_back_url = user_switching_get_switchback_url();
            }
        }

        ?>
        <script>
        if(localStorage.getItem('hl-sidebar-collapsed')==='1'){
            document.body.classList.add('hl-sidebar-is-collapsed');
        }
        </script>
        <!-- HL Core Nav Shell (injected on BB theme pages) -->
        <div class="hl-topbar<?php echo $old_user ? ' hl-topbar--view-as' : ''; ?>" id="hl-topbar">
            <div class="hl-breadcrumb" style="margin-bottom:0;">
                <span><?php echo esc_html(get_the_title() ?: 'Course'); ?></span>
            </div>
            <div class="hl-topbar__actions">
                <?php HL_Core::render_language_switcher(); ?>
                <div class="hl-topbar__user-wrap" id="hl-topbar-user-wrap">
                    <button class="hl-topbar__user-btn" id="hl-topbar-user-btn" type="button" aria-expanded="false">
                        <span class="hl-topbar__user-name"><?php echo esc_html($display_name); ?></span>
                        <?php if ($avatar_url) : ?>
                            <img src="<?php echo esc_url($avatar_url); ?>" alt="" class="hl-topbar__avatar">
                        <?php else : ?>
                            <div class="hl-topbar__avatar hl-topbar__avatar--initials"><?php echo esc_html($initials); ?></div>
                        <?php endif; ?>
                    </button>
                    <div class="hl-topbar__dropdown" id="hl-topbar-dropdown" hidden>
                        <?php if ($old_user && $switch_back_url) : ?>
                            <div class="hl-topbar__dropdown-notice">
                                <?php echo esc_html(sprintf(__('Viewing as %s', 'hl-core'), $display_name)); ?>
                            </div>
                            <a href="<?php echo esc_url($switch_back_url); ?>" class="hl-topbar__dropdown-item hl-topbar__dropdown-item--switch-back">
                                <span class="dashicons dashicons-undo"></span>
                                <?php echo esc_html(sprintf(__('Return to %s', 'hl-core'), $old_user->display_name)); ?>
                            </a>
                            <div class="hl-topbar__dropdown-divider"></div>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(admin_url('profile.php')); ?>" class="hl-topbar__dropdown-item">
                            <span class="dashicons dashicons-admin-users"></span>
                            <?php esc_html_e('My Account', 'hl-core'); ?>
                        </a>
                        <a href="<?php echo esc_url(wp_logout_url($dashboard_url)); ?>" class="hl-topbar__dropdown-item">
                            <span class="dashicons dashicons-migrate"></span>
                            <?php esc_html_e('Log Out', 'hl-core'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <nav class="hl-sidebar" id="hl-sidebar">
            <div class="hl-sidebar__brand">
                <?php if ($logo_url) : ?>
                    <a href="<?php echo esc_url($dashboard_url); ?>" class="hl-sidebar__logo-link">
                        <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="hl-sidebar__logo-img">
                    </a>
                <?php else : ?>
                    <div class="hl-sidebar__logo">HL</div>
                    <div class="hl-sidebar__title"><?php esc_html_e('Housman Learning', 'hl-core'); ?></div>
                    <div class="hl-sidebar__subtitle"><?php esc_html_e('Learning Hub', 'hl-core'); ?></div>
                <?php endif; ?>
            </div>
            <div class="hl-sidebar__nav">
                <?php foreach ($menu_items as $item) :
                    $item_path = trailingslashit(wp_parse_url($item['url'], PHP_URL_PATH) ?: '');
                    $is_active = ($item_path && $item_path === $current_url);
                    $active_class = $is_active ? ' hl-sidebar__item--active' : '';
                ?>
                    <a href="<?php echo esc_url($item['url']); ?>" class="hl-sidebar__item<?php echo esc_attr($active_class); ?>" data-tooltip="<?php echo esc_attr($item['label']); ?>">
                        <span class="hl-sidebar__icon dashicons <?php echo esc_attr($item['icon']); ?>"></span>
                        <span><?php echo esc_html($item['label']); ?></span>
                        <?php if (!empty($item['badge'])) : ?>
                            <span class="hl-sidebar__badge"><?php echo (int) $item['badge']; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="hl-sidebar__footer">
                <button class="hl-sidebar__collapse-btn" id="hl-sidebar-collapse-btn" type="button" title="<?php esc_attr_e('Collapse sidebar', 'hl-core'); ?>">
                    <span class="dashicons dashicons-arrow-left-alt2"></span>
                </button>
                <a href="<?php echo esc_url(wp_logout_url($dashboard_url)); ?>" class="hl-sidebar__item">
                    <span class="hl-sidebar__icon dashicons dashicons-migrate"></span>
                    <span><?php esc_html_e('Log Out', 'hl-core'); ?></span>
                </a>
            </div>
        </nav>
        <?php
    }

    /**
     * Enqueue frontend.css + sidebar JS on BB theme pages.
     */
    public function enqueue_nav_assets_on_theme_pages() {
        if (!$this->should_render_nav()) return;

        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'hl-core-frontend',
            HL_CORE_ASSETS_URL . 'css/frontend.css',
            array(),
            HL_CORE_VERSION
        );
        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'hl-core-frontend',
            HL_CORE_ASSETS_URL . 'js/frontend.js',
            array('jquery'),
            HL_CORE_VERSION,
            true
        );
    }

    /**
     * Add body class for content offset on BB theme pages.
     */
    public function add_nav_body_class($classes) {
        if (!$this->should_render_nav()) return $classes;
        $classes[] = 'hl-has-nav';
        return $classes;
    }

    // =========================================================================
    // BuddyBoss Suspension Helpers
    // =========================================================================

    /** @var bool|null Whether wp_bp_suspend table exists (cached per request). */
    private static $bp_suspend_exists = null;

    /**
     * Check if the wp_bp_suspend table exists (BuddyBoss active).
     * Cached per request.
     */
    public static function bp_suspend_table_exists() {
        if ( self::$bp_suspend_exists === null ) {
            global $wpdb;
            self::$bp_suspend_exists = ( $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'bp_suspend' )
            ) !== null );
        }
        return self::$bp_suspend_exists;
    }

    /**
     * Check if a specific user is suspended in BuddyBoss.
     *
     * @param int $user_id WordPress user ID.
     * @return bool True if suspended.
     */
    public static function is_user_suspended( $user_id ) {
        static $cache = array();
        $user_id = absint( $user_id );
        if ( ! $user_id || ! self::bp_suspend_table_exists() ) {
            return false;
        }
        if ( isset( $cache[ $user_id ] ) ) {
            return $cache[ $user_id ];
        }
        global $wpdb;
        $cache[ $user_id ] = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}bp_suspend
             WHERE item_type = 'user' AND item_id = %d AND user_suspended = 1
             LIMIT 1",
            $user_id
        ) );
        return $cache[ $user_id ];
    }

    /**
     * Get a NOT EXISTS SQL snippet to exclude suspended users from queries.
     *
     * @param string $user_id_column SQL column reference, e.g. 'e.user_id'.
     * @return string SQL snippet with leading AND, or empty string if bp_suspend absent.
     */
    public static function get_suspend_not_exists_sql( $user_id_column = 'e.user_id' ) {
        if ( ! self::bp_suspend_table_exists() ) {
            return '';
        }
        global $wpdb;
        return " AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->prefix}bp_suspend
            WHERE item_type = 'user' AND item_id = {$user_id_column} AND user_suspended = 1
        )";
    }
}
