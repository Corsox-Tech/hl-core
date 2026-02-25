<?php
if (!defined('ABSPATH')) exit;

/**
 * BuddyBoss Integration
 *
 * Injects role-conditional "Learning Hub" navigation items into the
 * BuddyBoss theme using multiple hooks for maximum reliability:
 *
 *  1. Profile Dropdown — via buddyboss_theme_after_bb_profile_menu (last
 *     hook in header-profile-menu.php, fires for all logged-in users).
 *  2. BuddyPanel left sidebar — via wp_nav_menu_items filter on the
 *     buddypanel-loggedin nav menu location.
 *  3. JS fallback — via wp_footer, injects items into the BuddyPanel
 *     DOM if neither PHP hook rendered them.
 *
 * Icons use WordPress dashicons (<span class="dashicons dashicons-xxx">)
 * for reliable rendering across all themes. Custom CSS is injected via
 * wp_head for sizing, spacing, and vertical alignment.
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

    /**
     * Track whether the BuddyPanel items were injected via PHP filter
     * so the JS fallback can be skipped.
     *
     * @var bool
     */
    private $buddypanel_injected = false;

    /**
     * Track whether the profile dropdown items were rendered.
     *
     * @var bool
     */
    private $profile_dropdown_rendered = false;

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

        // 0. Custom CSS for dashicons sizing, spacing, and vertical padding.
        add_action('wp_head', array($this, 'render_custom_css'));

        // 1. Profile Dropdown — last hook in header-profile-menu.php.
        //    Fires unconditionally for logged-in users UNLESS a custom
        //    nav menu is assigned to the "header-my-account" location.
        add_action('buddyboss_theme_after_bb_profile_menu', array($this, 'render_profile_dropdown_menu'));

        // 2. BuddyPanel left sidebar — filter nav menu items for the
        //    buddypanel-loggedin location.
        add_filter('wp_nav_menu_items', array($this, 'filter_buddypanel_menu_items'), 20, 2);

        // 3. JS fallback — inject into BuddyPanel via JavaScript if
        //    the PHP hooks didn't fire.
        add_action('wp_footer', array($this, 'render_js_fallback'), 99);
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
    // 0. Custom CSS
    // =========================================================================

    /**
     * Inject custom CSS for HL menu items in the BuddyBoss sidebar.
     *
     * Handles dashicon sizing, icon-text spacing, and reduced vertical padding
     * to match native BuddyBoss menu item density.
     */
    public function render_custom_css() {
        if (!is_user_logged_in()) {
            return;
        }
        ?>
        <style>
            /* HL Core BuddyPanel menu — dashicon sizing & spacing */
            .buddypanel-menu .hl-core-menu-item .dashicons,
            .buddypanel-menu .hl-buddypanel-section .dashicons {
                font-size: 20px !important;
                width: 20px !important;
                height: 20px !important;
                margin-right: 8px !important;
                vertical-align: middle !important;
            }

            /* Outer li matches native BB item spacing */
            .buddypanel-menu .hl-core-menu-item {
                margin: 0 !important;
                padding: 0 !important;
            }

            /* Reduced vertical padding to match native BB items */
            .buddypanel-menu .hl-core-menu-item > a {
                padding-top: 4px !important;
                padding-bottom: 4px !important;
                line-height: 1.4 !important;
            }

            /* Section heading: match native BB section style */
            .buddypanel-menu .hl-buddypanel-section {
                margin: 0 !important;
                padding: 0 !important;
            }
            .buddypanel-menu .hl-buddypanel-section > a {
                padding: 15px 20px 5px !important;
                font-size: 11px !important;
                text-transform: uppercase !important;
                letter-spacing: 0.5px !important;
                opacity: 0.5 !important;
                pointer-events: none !important;
            }

            /* Profile dropdown dashicon spacing */
            #wp-admin-bar-my-account-housman-learning .dashicons,
            #wp-admin-bar-my-account-housman-learning-default .dashicons {
                font-size: 20px !important;
                width: 20px !important;
                height: 20px !important;
                margin-right: 8px !important;
                vertical-align: middle !important;
            }
        </style>
        <?php
    }

    // =========================================================================
    // 1. Profile Dropdown Menu (header-profile-menu.php)
    // =========================================================================

    /**
     * Render the "Learning Hub" collapsible section in the BuddyBoss
     * profile dropdown (header user menu).
     *
     * Hooked to: buddyboss_theme_after_bb_profile_menu (last hook in template)
     */
    public function render_profile_dropdown_menu() {
        $items = $this->get_menu_items_for_current_user();
        if (empty($items)) {
            return;
        }

        $this->profile_dropdown_rendered = true;
        $current_url = trailingslashit(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));

        ?>
        <li id="wp-admin-bar-my-account-housman-learning" class="menupop">
            <a class="ab-item" aria-haspopup="true" href="#">
                <span class="dashicons dashicons-welcome-learn-more"></span>
                <span class="wp-admin-bar-arrow" aria-hidden="true"></span><?php esc_html_e('Learning Hub', 'hl-core'); ?>
            </a>
            <div class="ab-sub-wrapper wrapper">
                <ul id="wp-admin-bar-my-account-housman-learning-default" class="ab-submenu">
                    <?php foreach ($items as $item) :
                        $item_path = trailingslashit(wp_parse_url($item['url'], PHP_URL_PATH) ?: '');
                        $is_active = ($item_path && $item_path === $current_url);
                        $active_class = $is_active ? ' current' : '';
                    ?>
                        <li id="wp-admin-bar-my-account-hl-<?php echo esc_attr($item['slug']); ?>" class="<?php echo esc_attr(trim($active_class)); ?>">
                            <a class="ab-item" href="<?php echo esc_url($item['url']); ?>">
                                <span class="dashicons <?php echo esc_attr($item['icon']); ?>"></span><?php echo esc_html($item['label']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </li>
        <?php
    }

    // =========================================================================
    // 2. BuddyPanel Left Sidebar (wp_nav_menu filter)
    // =========================================================================

    /**
     * Inject HL menu items into the BuddyPanel left sidebar nav menu.
     *
     * The BuddyPanel template (buddypanel.php) uses wp_nav_menu() for the
     * buddypanel-loggedin location. Since it has no action hooks, we filter
     * the rendered HTML to PREPEND our items so they appear ABOVE any
     * existing ACCOUNT section.
     *
     * @param string   $items HTML of menu items.
     * @param stdClass $args  wp_nav_menu arguments.
     * @return string
     */
    public function filter_buddypanel_menu_items($items, $args) {
        // Only target the BuddyPanel logged-in menu.
        if (!isset($args->theme_location) || $args->theme_location !== 'buddypanel-loggedin') {
            return $items;
        }

        if (!is_user_logged_in()) {
            return $items;
        }

        $menu_items = $this->get_menu_items_for_current_user();
        if (empty($menu_items)) {
            return $items;
        }

        $this->buddypanel_injected = true;
        $current_url = trailingslashit(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));

        // Section header — uses native bb-menu-section class.
        // BuddyBoss CSS handles uppercase, font-weight:600, opacity:0.5, hidden icon.
        $html = '<li class="menu-item bb-menu-section hl-buddypanel-section">';
        $html .= '<a href="#">';
        $html .= '<span class="dashicons dashicons-welcome-learn-more"></span>';
        $html .= '<span class="link-text">' . esc_html__('Learning Hub', 'hl-core') . '</span>';
        $html .= '</a>';
        $html .= '</li>';

        foreach ($menu_items as $item) {
            $item_path = trailingslashit(wp_parse_url($item['url'], PHP_URL_PATH) ?: '');
            $is_active = ($item_path && $item_path === $current_url);
            $classes   = 'menu-item menu-item-type-custom menu-item-object-custom hl-core-menu-item';
            if ($is_active) {
                $classes .= ' current-menu-item';
            }

            $html .= '<li class="' . esc_attr($classes) . '">';
            $html .= '<a href="' . esc_url($item['url']) . '">';
            $html .= '<span class="dashicons ' . esc_attr($item['icon']) . '"></span>';
            $html .= '<span class="link-text">' . esc_html($item['label']) . '</span>';
            $html .= '</a>';
            $html .= '</li>';
        }

        // Prepend HL items BEFORE existing items so they appear above ACCOUNT.
        return $html . $items;
    }

    // =========================================================================
    // 3. JavaScript Fallback (wp_footer)
    // =========================================================================

    /**
     * Render a JS fallback that injects menu items into the BuddyPanel
     * sidebar DOM if the PHP hooks did not fire.
     *
     * This covers edge cases where:
     * - No BuddyPanel nav menu is assigned (buddypanel-loggedin empty)
     * - A custom profile dropdown menu overrides the BB profile hooks
     */
    public function render_js_fallback() {
        // If BuddyPanel was already injected via PHP, skip the fallback.
        if ($this->buddypanel_injected) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        $menu_items = $this->get_menu_items_for_current_user();
        if (empty($menu_items)) {
            return;
        }

        $current_url = trailingslashit(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));

        // Build the items as a JS-safe data structure.
        $js_items = array();
        foreach ($menu_items as $item) {
            $item_path = trailingslashit(wp_parse_url($item['url'], PHP_URL_PATH) ?: '');
            $js_items[] = array(
                'slug'   => $item['slug'],
                'label'  => $item['label'],
                'url'    => $item['url'],
                'icon'   => $item['icon'],
                'active' => ($item_path && $item_path === $current_url),
            );
        }

        ?>
        <script>
        (function(){
            var items = <?php echo wp_json_encode($js_items); ?>;
            if (!items || !items.length) return;

            var sectionTitle = <?php echo wp_json_encode(esc_html__('Learning Hub', 'hl-core')); ?>;

            function injectIntoBuddyPanel() {
                // Find the BuddyPanel sidebar.
                var panel = document.querySelector('.buddypanel .side-panel-menu-container');
                if (!panel) return false;

                // Check if already injected.
                if (panel.querySelector('.hl-buddypanel-section')) return true;

                // Find the existing <ul> or create one.
                var ul = panel.querySelector('ul.buddypanel-menu, ul.side-panel-menu');
                if (!ul) {
                    ul = document.createElement('ul');
                    ul.className = 'buddypanel-menu side-panel-menu has-section-menu';
                    ul.id = 'buddypanel-menu';
                    panel.appendChild(ul);
                }

                // Ensure has-section-menu class is present.
                if (!ul.classList.contains('has-section-menu')) {
                    ul.classList.add('has-section-menu');
                }

                // Build section header.
                var section = document.createElement('li');
                section.className = 'menu-item bb-menu-section hl-buddypanel-section';
                var sectionLink = document.createElement('a');
                sectionLink.href = '#';
                sectionLink.innerHTML = '<span class="dashicons dashicons-welcome-learn-more"></span>'
                    + '<span class="link-text">' + sectionTitle + '</span>';
                section.appendChild(sectionLink);

                // Build item elements.
                var fragment = document.createDocumentFragment();
                fragment.appendChild(section);

                for (var i = 0; i < items.length; i++) {
                    var li = document.createElement('li');
                    li.className = 'menu-item menu-item-type-custom menu-item-object-custom hl-core-menu-item';
                    if (items[i].active) li.className += ' current-menu-item';

                    var a = document.createElement('a');
                    a.href = items[i].url;
                    a.innerHTML = '<span class="dashicons ' + items[i].icon + '"></span>'
                        + '<span class="link-text">' + items[i].label + '</span>';
                    li.appendChild(a);
                    fragment.appendChild(li);
                }

                // Insert BEFORE the first existing bb-menu-section (ACCOUNT)
                // so the Learning Hub section appears above it.
                var firstSection = ul.querySelector('.bb-menu-section:not(.hl-buddypanel-section)');
                if (firstSection) {
                    ul.insertBefore(fragment, firstSection);
                } else {
                    // No existing sections — prepend before all children.
                    if (ul.firstChild) {
                        ul.insertBefore(fragment, ul.firstChild);
                    } else {
                        ul.appendChild(fragment);
                    }
                }

                return true;
            }

            function injectIntoProfileDropdown() {
                // Find the profile dropdown sub-menu.
                var subMenu = document.querySelector('.header-aside .user-wrap .sub-menu .sub-menu-inner');
                if (!subMenu) return false;

                // Check if already injected.
                if (subMenu.querySelector('#wp-admin-bar-my-account-housman-learning')) return true;

                // Build collapsible menu section.
                var li = document.createElement('li');
                li.id = 'wp-admin-bar-my-account-housman-learning';
                li.className = 'menupop';

                var a = document.createElement('a');
                a.className = 'ab-item';
                a.setAttribute('aria-haspopup', 'true');
                a.href = '#';
                a.innerHTML = '<span class="dashicons dashicons-welcome-learn-more"></span>'
                    + '<span class="wp-admin-bar-arrow" aria-hidden="true"></span>'
                    + sectionTitle;
                li.appendChild(a);

                var wrapper = document.createElement('div');
                wrapper.className = 'ab-sub-wrapper wrapper';
                var subUl = document.createElement('ul');
                subUl.id = 'wp-admin-bar-my-account-housman-learning-default';
                subUl.className = 'ab-submenu';

                for (var i = 0; i < items.length; i++) {
                    var subLi = document.createElement('li');
                    subLi.id = 'wp-admin-bar-my-account-hl-' + items[i].slug;
                    if (items[i].active) subLi.className = 'current';
                    var subA = document.createElement('a');
                    subA.className = 'ab-item';
                    subA.href = items[i].url;
                    subA.innerHTML = '<span class="dashicons ' + items[i].icon + '"></span>' + items[i].label;
                    subLi.appendChild(subA);
                    subUl.appendChild(subLi);
                }

                wrapper.appendChild(subUl);
                li.appendChild(wrapper);

                // Insert before the last item (logout).
                var logoutItem = subMenu.querySelector('#wp-admin-bar-logout');
                if (logoutItem) {
                    subMenu.insertBefore(li, logoutItem);
                } else {
                    subMenu.appendChild(li);
                }
                return true;
            }

            // Run after DOM is ready.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    injectIntoBuddyPanel();
                    <?php if (!$this->profile_dropdown_rendered) : ?>
                    injectIntoProfileDropdown();
                    <?php endif; ?>
                });
            } else {
                injectIntoBuddyPanel();
                <?php if (!$this->profile_dropdown_rendered) : ?>
                injectIntoProfileDropdown();
                <?php endif; ?>
            }
        })();
        </script>
        <?php
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
    private function get_menu_items_for_current_user() {
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

        // Users without staff cap AND without active HL enrollments see nothing.
        if (!$is_staff && !$has_enrollment) {
            $cached = array();
            return $cached;
        }

        $is_control_only = $has_enrollment ? $this->is_control_group_only($user_id) : false;
        $cached = $this->build_menu_items($roles, $is_staff, $has_enrollment, $is_control_only);
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
     *   My Cohort: district_leader, center_leader, or mentor role
     *
     * Directory/management pages:
     *   Cohorts, Institutions: staff OR district_leader OR center_leader
     *   Classrooms: staff OR district_leader OR center_leader OR teacher
     *   Learners: staff OR district_leader OR center_leader OR mentor
     *   Pathways: staff only
     *   Coaching Hub: staff OR mentor
     *   Reports: staff OR district_leader OR center_leader
     *
     * Multi-role users see the union of all their role menus.
     *
     * @param string[] $roles          Active HL enrollment roles for the user.
     * @param bool     $is_staff       Whether user has manage_hl_core capability.
     * @param bool     $has_enrollment Whether user has any active HL enrollment.
     * @return array<int, array{slug: string, label: string, url: string, icon: string}>
     */
    private function build_menu_items(array $roles, bool $is_staff, bool $has_enrollment, bool $is_control_only = false) {
        $is_leader  = in_array('center_leader', $roles, true)
                   || in_array('district_leader', $roles, true);
        $is_mentor  = in_array('mentor', $roles, true);
        $is_teacher = in_array('teacher', $roles, true);

        // Define all possible menu items with their visibility rules.
        // Each entry: [ slug, shortcode, label, icon, show_condition ]
        $menu_def = array(
            // --- Personal (require active enrollment) ---
            array('my-programs',    'hl_my_programs',          __('My Programs', 'hl-core'),    'dashicons-portfolio',            $has_enrollment),
            array('my-coaching',    'hl_my_coaching',          __('My Coaching', 'hl-core'),    'dashicons-video-alt2',           $has_enrollment && !$is_control_only),
            array('my-team',        'hl_my_team',              __('My Team', 'hl-core'),        'dashicons-groups',               $is_mentor),
            array('my-cohort',      'hl_my_cohort',            __('My Cohort', 'hl-core'),      'dashicons-networking',           $is_leader || $is_mentor),
            // --- Directories / Management ---
            array('cohorts',        'hl_cohorts_listing',      __('Cohorts', 'hl-core'),        'dashicons-groups',               $is_staff || $is_leader),
            array('institutions',   'hl_institutions_listing', __('Institutions', 'hl-core'),   'dashicons-building',             $is_staff || $is_leader),
            array('classrooms',     'hl_classrooms_listing',   __('Classrooms', 'hl-core'),     'dashicons-welcome-learn-more',   $is_staff || $is_leader || $is_teacher),
            array('learners',       'hl_learners',             __('Learners', 'hl-core'),       'dashicons-id-alt',               $is_staff || $is_leader || $is_mentor),
            // --- Staff tools ---
            array('pathways',       'hl_pathways_listing',     __('Pathways', 'hl-core'),       'dashicons-randomize',            $is_staff),
            array('coaching-hub',   'hl_coaching_hub',         __('Coaching Hub', 'hl-core'),   'dashicons-format-chat',          $is_staff || $is_mentor),
            array('reports',        'hl_reports_hub',          __('Reports', 'hl-core'),        'dashicons-chart-bar',            $is_staff || $is_leader),
        );

        $items = array();
        foreach ($menu_def as $def) {
            list($slug, $shortcode, $label, $icon, $visible) = $def;
            if (!$visible) {
                continue;
            }
            $url = $this->find_shortcode_page_url($shortcode);
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
    // Control Group Detection
    // =========================================================================

    /**
     * Check if all of a user's active enrollments belong to control group cohorts.
     *
     * @param int $user_id
     * @return bool True if the user has enrollments AND all are control group.
     */
    private function is_control_group_only($user_id) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.is_control_group
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->prefix}hl_cohort c ON e.cohort_id = c.cohort_id
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
