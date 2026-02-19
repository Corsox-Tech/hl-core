<?php
if (!defined('ABSPATH')) exit;

/**
 * BuddyBoss Integration
 *
 * Adds a role-conditional sidebar navigation menu to the BuddyBoss
 * theme profile dropdown so enrolled users can reach HL Core pages.
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
        if (!$this->is_active()) {
            return;
        }

        // Render sidebar menu items after the Groups section in the
        // BuddyBoss profile dropdown.
        add_action('buddyboss_theme_after_bb_groups_menu', array($this, 'render_sidebar_menu'));
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
    // Sidebar Menu Rendering
    // =========================================================================

    /**
     * Render the "Housman Learning" collapsible menu section in the
     * BuddyBoss profile sidebar.
     *
     * Hooked to: buddyboss_theme_after_bb_groups_menu
     */
    public function render_sidebar_menu() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $roles = $this->get_user_hl_roles($user_id);

        // If the user has no active HL enrollments AND no manage_hl_core
        // capability, show nothing.
        if (empty($roles) && !current_user_can('manage_hl_core')) {
            return;
        }

        $items = $this->build_menu_items($roles);

        if (empty($items)) {
            return;
        }

        ?>
        <li id="wp-admin-bar-my-account-housman-learning" class="menupop">
            <a class="ab-item" aria-haspopup="true" href="#">
                <i class="bb-icon-l bb-icon-clipboard"></i>
                <span class="wp-admin-bar-arrow" aria-hidden="true"></span><?php esc_html_e('Housman Learning', 'hl-core'); ?>
            </a>
            <div class="ab-sub-wrapper wrapper">
                <ul id="wp-admin-bar-my-account-housman-learning-default" class="ab-submenu">
                    <?php foreach ($items as $item) : ?>
                        <li id="wp-admin-bar-my-account-hl-<?php echo esc_attr($item['slug']); ?>">
                            <a class="ab-item" href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['label']); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </li>
        <?php
    }

    /**
     * Build the list of visible menu items for the current user.
     *
     * @param string[] $roles Active HL enrollment roles for the user.
     * @return array<int, array{slug: string, label: string, url: string}>
     */
    private function build_menu_items(array $roles) {
        $items = array();
        $has_any_enrollment = !empty($roles);

        // 1. My Programs — all enrolled users
        if ($has_any_enrollment) {
            $url = $this->find_shortcode_page_url('hl_my_programs');
            if ($url) {
                $items[] = array(
                    'slug'  => 'my-programs',
                    'label' => __('My Programs', 'hl-core'),
                    'url'   => $url,
                );
            }
        }

        // 2. My Cohort — center leaders and district leaders
        if (
            in_array('center_leader', $roles, true)
            || in_array('district_leader', $roles, true)
        ) {
            $url = $this->find_shortcode_page_url('hl_my_cohort');
            if ($url) {
                $items[] = array(
                    'slug'  => 'my-cohort',
                    'label' => __('My Cohort', 'hl-core'),
                    'url'   => $url,
                );
            }
        }

        // 3 & 4. School Districts + Institutions — manage_hl_core capability
        if (current_user_can('manage_hl_core')) {
            $url = $this->find_shortcode_page_url('hl_districts_listing');
            if ($url) {
                $items[] = array(
                    'slug'  => 'districts',
                    'label' => __('School Districts', 'hl-core'),
                    'url'   => $url,
                );
            }

            $url = $this->find_shortcode_page_url('hl_centers_listing');
            if ($url) {
                $items[] = array(
                    'slug'  => 'institutions',
                    'label' => __('Institutions', 'hl-core'),
                    'url'   => $url,
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
     * @return string[] e.g. ['teacher', 'center_leader']
     */
    public function get_user_hl_roles($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) {
            return array();
        }

        if (isset(self::$role_cache[$user_id])) {
            return self::$role_cache[$user_id];
        }

        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT roles FROM {$wpdb->prefix}hl_enrollment WHERE user_id = %d AND status = 'active'",
            $user_id
        ));

        $all_roles = array();

        foreach ($rows as $roles_json) {
            if (empty($roles_json)) {
                continue;
            }
            $decoded = json_decode($roles_json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $role) {
                    $all_roles[$role] = true;
                }
            }
        }

        $result = array_keys($all_roles);
        self::$role_cache[$user_id] = $result;

        return $result;
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
    private function find_shortcode_page_url($shortcode) {
        if (isset(self::$page_url_cache[$shortcode])) {
            return self::$page_url_cache[$shortcode];
        }

        global $wpdb;

        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like($shortcode) . '%'
        ));

        $url = $page_id ? get_permalink($page_id) : '';

        self::$page_url_cache[$shortcode] = $url;

        return $url;
    }
}
