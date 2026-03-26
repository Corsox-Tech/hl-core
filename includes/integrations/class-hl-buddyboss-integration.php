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

        // 0b. Custom CSS for dashicons sizing, spacing, and vertical padding.
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

            /* Collapsed BuddyPanel — BB hides all <span> in links.
               Override for our dashicons so icons remain visible. */
            body:not(.buddypanel-open) .buddypanel ul.buddypanel-menu > li.hl-core-menu-item > a > .dashicons {
                opacity: 1 !important;
                width: 20px !important;
                visibility: visible !important;
            }
            /* Hide section header when sidebar is collapsed */
            body:not(.buddypanel-open) .buddypanel ul.buddypanel-menu > li.hl-buddypanel-section {
                display: none !important;
            }
            /* Hide badge in collapsed mode */
            body:not(.buddypanel-open) .buddypanel ul.buddypanel-menu > li.hl-core-menu-item .hl-menu-badge {
                display: none !important;
            }

            /* Available-component badge on menu items */
            .hl-menu-badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 20px;
                height: 20px;
                padding: 0 6px;
                margin-left: 6px;
                font-size: 12px !important;
                font-weight: 600;
                line-height: 1;
                color: #fff;
                background: #EF4444;
                border-radius: 10px;
                vertical-align: middle;
                position: relative;
                top: -2px;
            }
        </style>
        <?php
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

        $roles = $this->get_user_hl_roles($user->ID);
        if (empty($roles)) {
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
        $current_slug = get_post_field('post_name', $current_page_id);
        if (strpos($current_slug, 'dashboard') === false) {
            return;
        }

        // Check if user has HL enrollment or staff access.
        $user_id = get_current_user_id();
        $roles   = $this->get_user_hl_roles($user_id);
        $is_staff = current_user_can('manage_hl_core');

        if (empty($roles) && !$is_staff) {
            return; // Non-enrolled, non-staff users keep the default BB dashboard.
        }

        wp_redirect($hl_dashboard_url);
        exit;
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
                                <span class="dashicons <?php echo esc_attr($item['icon']); ?>"></span><?php echo esc_html($item['label']); ?><?php if ( ! empty( $item['badge'] ) ) : ?><span class="hl-menu-badge"><?php echo (int) $item['badge']; ?></span><?php endif; ?>
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

        $user_id        = get_current_user_id();
        $is_staff       = current_user_can('manage_hl_core');
        $roles          = $this->get_user_hl_roles($user_id);
        $has_enrollment = !empty($roles);

        $menu_items = $this->get_menu_items_for_current_user();
        if (empty($menu_items)) {
            return $items;
        }

        $this->buddypanel_injected = true;
        $current_url = trailingslashit(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));

        // Section header — uses native bb-menu-section class.
        // BuddyBoss styles li.bb-menu-section a { uppercase, font-weight:600, opacity:0.5 }.
        // No icon — matches native BuddyBoss section headers (e.g. "ACCOUNT").
        $html = '<li class="menu-item bb-menu-section hl-buddypanel-section">';
        $html .= '<a href="#">' . esc_html__('Learning Hub', 'hl-core') . '</a>';
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
            if ( ! empty( $item['badge'] ) ) {
                $html .= '<span class="hl-menu-badge">' . (int) $item['badge'] . '</span>';
            }
            $html .= '</a>';
            $html .= '</li>';
        }

        // For HL-enrolled non-staff users, strip legacy BuddyPanel items
        // (Admin, All Users, New School Leader, etc.) to avoid confusion.
        // Staff keep all items for admin access.
        if ($has_enrollment && !$is_staff) {
            $items = $this->strip_legacy_buddypanel_items($items);
        }

        // Prepend HL items BEFORE remaining items.
        return $html . $items;
    }

    // =========================================================================
    // 2b. Strip Legacy BuddyPanel Items
    // =========================================================================

    /**
     * Strip legacy BuddyPanel menu items for HL-enrolled users.
     *
     * Keeps only essential items (Dashboard, Profile, Log Out) and removes
     * everything else (Admin, All Users, New School Leader, Reports, etc.)
     * so enrolled participants see a clean sidebar.
     *
     * @param string $html The default BuddyPanel <li> items HTML.
     * @return string Filtered HTML with only whitelisted items.
     */
    private function strip_legacy_buddypanel_items($html) {
        if (empty($html)) {
            return $html;
        }

        $keep_patterns = array(
            'action=logout',
            '/dashboard/',
        );

        preg_match_all('/<li[^>]*>.*?<\/li>/s', $html, $matches);
        if (empty($matches[0])) {
            return $html;
        }

        $kept = array();
        foreach ($matches[0] as $li) {
            foreach ($keep_patterns as $pattern) {
                if (strpos($li, $pattern) !== false) {
                    $kept[] = $li;
                    break;
                }
            }
        }

        return implode('', $kept);
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

        $user_id        = get_current_user_id();
        $is_staff       = current_user_can('manage_hl_core');
        $roles          = $this->get_user_hl_roles($user_id);
        $has_enrollment = !empty($roles);

        $menu_items = $this->get_menu_items_for_current_user();
        if (empty($menu_items)) {
            return;
        }

        $current_url = trailingslashit(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
        $strip_legacy = ($has_enrollment && !$is_staff);

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
                'badge'  => ! empty( $item['badge'] ) ? (int) $item['badge'] : 0,
            );
        }

        ?>
        <script>
        (function(){
            var items = <?php echo wp_json_encode($js_items); ?>;
            if (!items || !items.length) return;

            var sectionTitle = <?php echo wp_json_encode(esc_html__('Learning Hub', 'hl-core')); ?>;
            var stripLegacy = <?php echo $strip_legacy ? 'true' : 'false'; ?>;

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
                sectionLink.textContent = sectionTitle;
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
                    var badgeHtml = (items[i].badge > 0) ? '<span class="hl-menu-badge">' + items[i].badge + '</span>' : '';
                    a.innerHTML = '<span class="dashicons ' + items[i].icon + '"></span>'
                        + '<span class="link-text">' + items[i].label + '</span>' + badgeHtml;
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
                    var subBadge = (items[i].badge > 0) ? '<span class="hl-menu-badge">' + items[i].badge + '</span>' : '';
                    subA.innerHTML = '<span class="dashicons ' + items[i].icon + '"></span>' + items[i].label + subBadge;
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

            function stripLegacyItems() {
                if (!stripLegacy) return;
                var keepPatterns = ['action=logout', '/dashboard/'];
                var ul = document.querySelector('.buddypanel .side-panel-menu-container ul.buddypanel-menu, .buddypanel .side-panel-menu-container ul.side-panel-menu');
                if (!ul) return;
                var allItems = ul.querySelectorAll('li:not(.hl-core-menu-item):not(.hl-buddypanel-section)');
                for (var i = 0; i < allItems.length; i++) {
                    var html = allItems[i].innerHTML;
                    var keep = false;
                    for (var j = 0; j < keepPatterns.length; j++) {
                        if (html.indexOf(keepPatterns[j]) !== -1) { keep = true; break; }
                    }
                    if (!keep) allItems[i].parentNode.removeChild(allItems[i]);
                }
            }

            // Run after DOM is ready.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    injectIntoBuddyPanel();
                    stripLegacyItems();
                    <?php if (!$this->profile_dropdown_rendered) : ?>
                    injectIntoProfileDropdown();
                    <?php endif; ?>
                });
            } else {
                injectIntoBuddyPanel();
                stripLegacyItems();
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
     *   Tracks, Institutions: staff OR district_leader OR school_leader
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
        $is_leader  = in_array('school_leader', $roles, true)
                   || in_array('district_leader', $roles, true);
        $is_mentor  = in_array('mentor', $roles, true);
        $is_teacher = in_array('teacher', $roles, true);
        $is_coach   = in_array('coach', (array) wp_get_current_user()->roles, true);

        // Define all possible menu items with their visibility rules.
        // Each entry: [ slug, shortcode, label, icon, show_condition ]
        // Role matrix updated 2026-03-26:
        //   Teacher: My Programs, My Team, Classrooms
        //   Mentor:  My Programs, My Coaching, My Team, Classrooms
        //   Leader:  My Programs, Cycles, Classrooms, Reports
        //   Coach:   Coach Dashboard, My Mentors, My Availability, Coach Reports, Documentation
        //   Admin:   My Programs, Classrooms, Cycles, Institutions, Learners, Pathways, Coaching Hub, Reports, Documentation
        $is_coach_only = $is_coach && !$is_staff;
        $menu_def = array(
            // --- Personal (require active enrollment) ---
            array('my-programs',    'hl_my_programs',          __('My Programs', 'hl-core'),    'dashicons-portfolio',            $has_enrollment),
            array('my-coaching',    'hl_my_coaching',          __('My Coaching', 'hl-core'),    'dashicons-video-alt2',           $is_mentor && !$is_control_only),
            array('my-team',        'hl_my_team',              __('My Team', 'hl-core'),        'dashicons-groups',               $is_mentor || $is_teacher),
            // --- Directories / Management ---
            array('cycles',         'hl_cycles_listing',       __('Cycles', 'hl-core'),         'dashicons-groups',               $is_staff || $is_leader),
            array('classrooms',     'hl_classrooms_listing',   __('Classrooms', 'hl-core'),     'dashicons-welcome-learn-more',   $is_staff || $is_leader || $is_teacher || $is_mentor),
            array('learners',       'hl_learners',             __('Learners', 'hl-core'),       'dashicons-id-alt',               $is_staff),
            // --- Staff tools ---
            array('pathways',       'hl_pathways_listing',     __('Pathways', 'hl-core'),       'dashicons-randomize',            $is_staff),
            array('coaching-hub',   'hl_coaching_hub',         __('Coaching Hub', 'hl-core'),   'dashicons-format-chat',          $is_staff),
            // --- Coach tools ---
            array('coach-dashboard', 'hl_coach_dashboard',      __('Coach Dashboard', 'hl-core'),  'dashicons-dashboard',            $is_coach),
            array('coach-mentors',   'hl_coach_mentors',        __('My Mentors', 'hl-core'),       'dashicons-groups',               $is_coach),
            array('coach-availability', 'hl_coach_availability', __('My Availability', 'hl-core'), 'dashicons-calendar-alt',         $is_coach),
            array('coach-reports',   'hl_coach_reports',        __('Coach Reports', 'hl-core'),    'dashicons-chart-bar',            $is_coach),
            array('reports',        'hl_reports_hub',          __('Reports', 'hl-core'),        'dashicons-chart-bar',            $is_staff || $is_leader),
            // --- Documentation ---
            array('documentation', 'hl_docs',                 __('Documentation', 'hl-core'),  'dashicons-media-document',       current_user_can('manage_options') || $is_coach),
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
     * @return string[] e.g. ['teacher', 'school_leader']
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
