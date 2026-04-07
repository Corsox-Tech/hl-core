# Auth System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Replace BuddyBoss login/reset with custom HL pages and add first-time profile completion form with enforcement gate.

**Architecture:** New `includes/auth/` directory with Manager (hooks), Service (logic), Repository (DB). New `hl-auth.php` template for full-bleed auth pages. New `hl_user_profile` table (schema rev 32). 3 frontend shortcode renderers.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, jQuery, vanilla JS, MySQL/MariaDB

**Spec:** `docs/superpowers/specs/2026-04-07-auth-login-profile-design.md`

**Deployment note:** Tasks 1-11 form a single deployable unit. Task 3 (Auth Manager) references constants from Task 8 (Profile Setup renderer) at runtime. Do NOT deploy partially -- deploy all tasks through Task 11 together. Tasks 12-13 are safe to deploy independently after.

---

## Task 1: DB Schema + Auth Repository

**Files:**
- MODIFY: `includes/class-hl-installer.php` (lines 114-119 for `get_schema()`, lines 133-189 for `maybe_upgrade()`)
- CREATE: `includes/auth/class-hl-auth-repository.php`

**Steps:**

- [ ] 1a. Create directory `includes/auth/` and create `includes/auth/class-hl-auth-repository.php` with the following complete content:

```php
<?php
if (!defined('ABSPATH')) exit;

/**
 * Repository for the hl_user_profile table.
 *
 * Handles all direct DB operations for user profile data.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE to avoid data loss (spec C1).
 *
 * @package HL_Core
 */
class HL_Auth_Repository {

    /**
     * Get a user's profile row.
     *
     * @param int $user_id
     * @return object|null Profile row or null.
     */
    public static function get($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_user_profile';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Insert or update a user's profile (spec C1).
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to preserve created_at
     * and avoid the DELETE+INSERT behavior of REPLACE INTO.
     *
     * @param int   $user_id
     * @param array $data Column => value pairs.
     * @return bool True on success.
     */
    public static function upsert($user_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_user_profile';

        // Allowlist of columns (spec FC5: use esc_sql + int cast instead of
        // $wpdb->prepare to avoid vsprintf garbling values containing literal %).
        $allowed_string_cols = array(
            'nickname', 'phone_country_code', 'phone_number', 'gender',
            'ethnicity', 'location_state', 'age_range', 'preferred_language',
            'years_exp_industry', 'years_exp_position', 'job_title',
            'social_instagram', 'social_twitter', 'social_linkedin',
            'social_facebook', 'social_website',
            'consent_given_at', 'consent_version', 'profile_completed_at',
        );

        // Build column/value pairs for INSERT
        $columns = array('`user_id`');
        $values  = array((int) $user_id);
        $update_parts = array();

        foreach ($allowed_string_cols as $col) {
            if (array_key_exists($col, $data)) {
                $columns[]      = "`{$col}`";
                $values[]       = "'" . esc_sql($data[$col]) . "'";
                $update_parts[] = "`{$col}` = VALUES(`{$col}`)";
            }
        }

        if (empty($update_parts)) {
            return false;
        }

        $cols_str   = implode(', ', $columns);
        $vals_str   = implode(', ', $values);
        $update_str = implode(', ', $update_parts);

        $sql = "INSERT INTO `{$table}` ({$cols_str}) VALUES ({$vals_str})
                ON DUPLICATE KEY UPDATE {$update_str}";

        // Direct query -- no $wpdb->prepare() because the column set is dynamic
        // and values are pre-escaped via esc_sql() / int cast.
        $result = $wpdb->query($sql);

        // Invalidate cache on every write (spec I12)
        wp_cache_delete('profile_complete_' . $user_id, 'hl_profiles');

        return $result !== false;
    }

    /**
     * Delete a user's profile row + cache (spec I19).
     *
     * @param int $user_id
     */
    public static function delete($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_user_profile';

        $wpdb->delete($table, array('user_id' => $user_id), array('%d'));
        wp_cache_delete('profile_complete_' . $user_id, 'hl_profiles');
    }

    /**
     * Check if a user's profile has all required fields (spec Section G).
     *
     * Required fields for completion:
     * - WP: first_name, last_name (checked via get_userdata)
     * - Profile: nickname, gender, ethnicity (non-empty JSON array), location_state,
     *   age_range, preferred_language, years_exp_industry, years_exp_position, consent_given_at
     *
     * Optional fields (NOT required for completion):
     * - phone_number, phone_country_code, job_title
     * - social_instagram, social_twitter, social_linkedin, social_facebook, social_website
     *
     * @param int $user_id
     * @return bool
     */
    public static function is_complete($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_user_profile';

        // Check WP user fields first
        $user = get_userdata($user_id);
        if (!$user || empty($user->first_name) || empty($user->last_name)) {
            return false;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT nickname, gender, ethnicity, location_state, age_range,
                    preferred_language, years_exp_industry, years_exp_position,
                    consent_given_at, profile_completed_at
             FROM `{$table}` WHERE user_id = %d",
            $user_id
        ));

        if (!$row) {
            return false;
        }

        // Fast path: profile_completed_at is set atomically on submission (spec FI6)
        if (!empty($row->profile_completed_at) && !empty($row->consent_given_at)) {
            return true;
        }

        // Fallback: check individual required fields
        if (empty($row->nickname))            return false;
        if (empty($row->gender))              return false;
        if (empty($row->location_state))      return false;
        if (empty($row->age_range))           return false;
        if (empty($row->preferred_language))  return false;
        if (empty($row->years_exp_industry))  return false;
        if (empty($row->years_exp_position))  return false;
        if (empty($row->consent_given_at))    return false;

        // Ethnicity: must be a non-empty JSON array
        $ethnicity = json_decode($row->ethnicity ?? '', true);
        if (empty($ethnicity) || !is_array($ethnicity)) {
            return false;
        }

        return true;
    }
}
```

- [ ] 1b. In `includes/class-hl-installer.php`, add the `hl_user_profile` table to `get_schema()`. Insert immediately BEFORE the `return $tables;` line (currently line 2019):

```php
        // User Profile table (auth system: profile completion + demographics)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_user_profile (
            profile_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            nickname varchar(100) DEFAULT NULL,
            phone_country_code varchar(5) DEFAULT '+1',
            phone_number varchar(20) DEFAULT NULL,
            gender varchar(60) DEFAULT NULL,
            ethnicity text DEFAULT NULL COMMENT 'JSON array of selected values',
            location_state varchar(100) DEFAULT NULL,
            age_range varchar(20) DEFAULT NULL,
            preferred_language varchar(5) DEFAULT 'en',
            years_exp_industry varchar(20) DEFAULT NULL,
            years_exp_position varchar(20) DEFAULT NULL,
            job_title varchar(255) DEFAULT NULL,
            social_instagram varchar(255) DEFAULT NULL,
            social_twitter varchar(255) DEFAULT NULL,
            social_linkedin varchar(500) DEFAULT NULL,
            social_facebook varchar(500) DEFAULT NULL,
            social_website varchar(500) DEFAULT NULL,
            consent_given_at datetime DEFAULT NULL,
            consent_version varchar(20) DEFAULT NULL,
            profile_completed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (profile_id),
            UNIQUE KEY user_id (user_id),
            KEY profile_completed_at (profile_completed_at)
        ) $charset_collate;";
```

- [ ] 1c. In `includes/class-hl-installer.php`, bump the revision number in `maybe_upgrade()`. Change line 136 from `$current_revision = 31;` to `$current_revision = 32;`. Also add the rev 32 block after the rev 31 block (after line 186):

```php
            // Rev 32: Add hl_user_profile table for auth/profile system.
            if ( (int) $stored < 32 ) {
                // Table created by dbDelta in get_schema(). No ALTER TABLE needed.
            }
```

**Verify:**
```bash
# Deploy to test server, then:
ssh test-server "cd /path/to/wp && wp db query 'DESCRIBE wp_hl_user_profile'"
# Should show all 22 columns: profile_id, user_id, nickname, phone_country_code,
# phone_number, gender, ethnicity, location_state, age_range, preferred_language,
# years_exp_industry, years_exp_position, job_title, social_instagram, social_twitter,
# social_linkedin, social_facebook, social_website, consent_given_at, consent_version,
# profile_completed_at, created_at, updated_at

ssh test-server "cd /path/to/wp && wp eval 'echo get_option(\"hl_core_schema_revision\");'"
# Should output: 32
```

**Commit:** `git add includes/auth/class-hl-auth-repository.php includes/class-hl-installer.php && git commit -m "feat(auth): add hl_user_profile table (rev 32) + Auth Repository"`

---

## Task 2: Auth Service (Business Logic)

**Files:**
- CREATE: `includes/auth/class-hl-auth-service.php`

**Steps:**

- [ ] 2a. Create `includes/auth/class-hl-auth-service.php` with the following complete content:

```php
<?php
if (!defined('ABSPATH')) exit;

/**
 * Auth business logic service.
 *
 * Static methods for login, password reset, profile gate, rate limiting,
 * and post-login redirect resolution.
 *
 * @package HL_Core
 */
class HL_Auth_Service {

    /**
     * Max failed login attempts before lockout.
     */
    const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Lockout window in seconds (15 minutes).
     */
    const LOCKOUT_WINDOW = 900;

    /**
     * Get the real client IP, accounting for AWS load balancer (spec C3).
     *
     * @return string Sanitized IP address.
     */
    public static function get_client_ip() {
        // AWS Lightsail LB sets X-Forwarded-For
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // X-Forwarded-For can be "client, proxy1, proxy2"
            // Leftmost is the original client IP
            $ips = explode(',', sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']));
            $client_ip = trim($ips[0]);
            if (filter_var($client_ip, FILTER_VALIDATE_IP)) {
                return $client_ip;
            }
        }

        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    /**
     * Check if an IP is rate-limited (spec C3).
     *
     * Uses transients (not wp_cache) because rate limit state MUST persist
     * across requests and survive page redirects.
     *
     * @param string $ip Client IP.
     * @return bool True if rate-limited.
     */
    public static function check_rate_limit($ip) {
        $key = 'hl_login_attempts_' . md5($ip);
        $data = get_transient($key);

        if (!$data) {
            return false;
        }

        return $data['count'] >= self::MAX_FAILED_ATTEMPTS;
    }

    /**
     * Record a failed login attempt.
     *
     * @param string $ip Client IP.
     */
    public static function record_failed_attempt($ip) {
        $key = 'hl_login_attempts_' . md5($ip);
        $data = get_transient($key);

        if (!$data) {
            $data = array('count' => 0, 'first_at' => time());
        }

        $data['count']++;
        set_transient($key, $data, self::LOCKOUT_WINDOW);
    }

    /**
     * Clear rate limit on successful login.
     *
     * @param string $ip Client IP.
     */
    public static function clear_rate_limit($ip) {
        $key = 'hl_login_attempts_' . md5($ip);
        delete_transient($key);
    }

    /**
     * Check if user has completed their profile (spec I17).
     *
     * Uses wp_cache to avoid repeated DB queries within a request.
     *
     * @param int $user_id
     * @return bool
     */
    public static function is_profile_complete($user_id) {
        // Check object cache first
        $cached = wp_cache_get('profile_complete_' . $user_id, 'hl_profiles');
        if ($cached !== false) {
            return (bool) $cached;
        }

        // Query DB
        $is_complete = HL_Auth_Repository::is_complete($user_id);

        // Cache for 1 hour (or until invalidated by upsert)
        wp_cache_set('profile_complete_' . $user_id, $is_complete ? 1 : 0, 'hl_profiles', 3600);

        return $is_complete;
    }

    /**
     * Resolve where to send the user after login (spec C4).
     *
     * Extracted from HL_BuddyBoss_Integration::hl_login_redirect().
     *
     * @param WP_User $user The logged-in user.
     * @return string Redirect URL.
     */
    public static function resolve_post_login_redirect($user, $redirect_to = '') {
        if (!($user instanceof \WP_User)) {
            return home_url();
        }

        // BB is optional (CLAUDE.md) -- guard against fatal if deactivated (PC1).
        if (class_exists('HL_BuddyBoss_Integration')) {
            $bb = HL_BuddyBoss_Integration::instance();
            $hl_roles = $bb->get_user_hl_roles($user->ID);
        } else {
            global $wpdb;
            $roles_json = $wpdb->get_var($wpdb->prepare(
                "SELECT roles FROM {$wpdb->prefix}hl_enrollment WHERE user_id = %d AND status = 'active' LIMIT 1",
                $user->ID
            ));
            $hl_roles = $roles_json ? json_decode($roles_json, true) : array();
        }

        // Coach-only users (no enrollment): send to Coach Dashboard.
        $is_coach    = in_array('coach', (array) $user->roles, true);
        $is_staff    = user_can($user, 'manage_options');

        if ($is_coach && empty($hl_roles) && !$is_staff) {
            $coach_url = self::find_shortcode_page_url('hl_coach_dashboard');
            if ($coach_url) {
                return $coach_url;
            }
        }

        // Non-enrolled, non-coach users: honour redirect_to if provided,
        // otherwise fall back to wp-admin (PI3).
        if (empty($hl_roles) && !$is_coach) {
            return $redirect_to ?: admin_url();
        }

        // Enrolled users: HL Dashboard
        $dashboard_url = HL_Core::get_dashboard_url();
        if ($dashboard_url) {
            return $dashboard_url;
        }

        return home_url();
    }

    /**
     * Get the URL of the custom login page.
     *
     * @return string URL or empty string.
     */
    public static function get_login_page_url() {
        return self::find_shortcode_page_url('hl_login');
    }

    /**
     * Get the URL of the custom password reset page.
     *
     * @return string URL or empty string.
     */
    public static function get_password_reset_page_url() {
        return self::find_shortcode_page_url('hl_password_reset');
    }

    /**
     * Get the URL of the profile setup page.
     *
     * @return string URL or empty string.
     */
    public static function get_profile_setup_page_url() {
        return self::find_shortcode_page_url('hl_profile_setup');
    }

    /**
     * Find the URL of a page containing a specific shortcode.
     * WPML-aware via wpml_object_id filter.
     *
     * @param string $shortcode Shortcode tag (without brackets).
     * @return string Page URL or empty string.
     */
    private static function find_shortcode_page_url($shortcode) {
        global $wpdb;
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page'
             AND post_status = 'publish'
             AND post_content LIKE %s
             LIMIT 1",
            '%[' . $wpdb->esc_like($shortcode) . ']%'
        ));

        if ($page_id) {
            $page_id = apply_filters('wpml_object_id', $page_id, 'page', true);
        }

        return $page_id ? get_permalink($page_id) : '';
    }

    /**
     * Sync preferred language to all active enrollments (spec I16).
     *
     * Non-critical -- logs failures but does not abort the profile save.
     *
     * @param int    $user_id
     * @param string $language Language code (en, es, pt).
     * @param bool   $skip     If true, skips the sync.
     */
    public static function sync_enrollment_language($user_id, $language, $skip = false) {
        if ($skip) return;

        global $wpdb;
        $table = $wpdb->prefix . 'hl_enrollment';

        // Get current values for audit logging (spec I16)
        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT enrollment_id, language_preference FROM `{$table}` WHERE user_id = %d AND status = 'active'",
            $user_id
        ));

        if (empty($enrollments)) return;

        foreach ($enrollments as $enrollment) {
            if ($enrollment->language_preference === $language) {
                continue; // No change needed
            }

            $result = $wpdb->update(
                $table,
                array('language_preference' => $language),
                array('enrollment_id' => $enrollment->enrollment_id),
                array('%s'),
                array('%d')
            );

            if ($result === false) {
                error_log('[HL Auth] Language sync failed for enrollment ' . $enrollment->enrollment_id);
            } else {
                HL_Audit_Service::log('enrollment.language_synced', array(
                    'entity_type' => 'enrollment',
                    'entity_id'   => $enrollment->enrollment_id,
                    'before_data' => array('language_preference' => $enrollment->language_preference),
                    'after_data'  => array('language_preference' => $language),
                ));
            }
        }
    }
}
```

**Verify:**
```bash
# After deploying:
ssh test-server "cd /path/to/wp && wp eval 'echo HL_Auth_Service::get_client_ip();'"
# Should output an IP address

ssh test-server "cd /path/to/wp && wp eval 'echo HL_Auth_Service::is_profile_complete(1) ? \"complete\" : \"incomplete\";'"
# Should output: incomplete (no profile row yet for user 1)
```

**Commit:** `git add includes/auth/class-hl-auth-service.php && git commit -m "feat(auth): add Auth Service with rate limiting, redirect, profile check"`

---

## Task 3: Auth Manager (Hook Orchestrator)

**Files:**
- CREATE: `includes/auth/class-hl-auth-manager.php`

**Steps:**

- [ ] 3a. Create `includes/auth/class-hl-auth-manager.php` with the following complete content:

```php
<?php
if (!defined('ABSPATH')) exit;

/**
 * Auth hook orchestrator.
 *
 * Registers all WordPress hooks related to authentication:
 * login_init, template_redirect, admin_init, login_redirect,
 * password_reset_expiration, login_url, lostpassword_url, delete_user.
 *
 * Contains NO business logic -- delegates everything to HL_Auth_Service.
 *
 * @package HL_Core
 */
class HL_Auth_Manager {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 1. Redirect wp-login.php to custom login (spec I9)
        add_action('login_init', array($this, 'intercept_wp_login'));

        // 2. Profile gate (frontend) + auth page redirects + POST handlers
        add_action('template_redirect', array($this, 'handle_auth_redirects'), 5);

        // 3. Profile gate (wp-admin) (spec C6)
        add_action('admin_init', array($this, 'enforce_profile_gate_admin'));

        // 4. Post-login redirect (priority 1000 to override BB's 999) (PC3)
        add_filter('login_redirect', array($this, 'filter_login_redirect'), 1000, 3);

        // 5. Password reset expiration (7 days) (spec I14)
        add_filter('password_reset_expiration', array($this, 'extend_reset_expiration'));

        // 6. PII cleanup on user deletion (spec I19)
        add_action('delete_user', array($this, 'cleanup_user_profile'));

        // 7. Filter wp_login_url() and wp_lostpassword_url() (spec Appendix F)
        add_filter('login_url', array($this, 'filter_login_url'), 10, 3);
        add_filter('lostpassword_url', array($this, 'filter_lostpassword_url'), 10, 2);
    }

    // =========================================================================
    // 1. wp-login.php Interception (spec I9)
    // =========================================================================

    /**
     * Redirect wp-login.php to our custom login page.
     * Actions in the allowlist stay on wp-login.php.
     */
    public function intercept_wp_login() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        // Also check REQUEST for POST actions
        if (empty($action) && isset($_REQUEST['action'])) {
            $action = sanitize_text_field($_REQUEST['action']);
        }

        // Actions that MUST stay on wp-login.php
        $allowlist = array(
            'rp',                        // Password reset form (from email link)
            'resetpass',                 // Password reset POST handler
            'postpass',                  // Password-protected post access
            'logout',                    // Logout handler
            'confirm_admin_email',       // WP admin email confirmation
            'confirm_new_admin_email',   // WP new admin email confirmation
            'interim-login',             // Modal re-authentication
        );

        if (in_array($action, $allowlist, true)) {
            return; // Let WordPress handle it
        }

        // For 'lostpassword' action, redirect to our custom page
        if ($action === 'lostpassword') {
            $reset_url = HL_Auth_Service::get_password_reset_page_url();
            if ($reset_url) {
                wp_safe_redirect($reset_url);
                exit;
            }
            return; // Fall back to WP default if our page doesn't exist
        }

        // Default: redirect to custom login page
        $login_url = HL_Auth_Service::get_login_page_url();
        if ($login_url) {
            wp_safe_redirect($login_url);
            exit;
        }
        // If custom login page doesn't exist, fall through to default wp-login.php
    }

    // =========================================================================
    // 2. template_redirect: POST handlers + redirects + profile gate
    // =========================================================================

    /**
     * Main template_redirect handler for all auth functionality.
     * Priority 5 (early) so it runs before other template_redirect handlers.
     */
    public function handle_auth_redirects() {
        // --- Set session token cookie for auth pages (before any output) ---
        // Spec Q1 fix #2: cookies must be set before output, not in render()
        if (is_page()) {
            global $post;
            $needs_session = false;
            $auth_shortcodes = array('[hl_login]', '[hl_password_reset]', '[hl_profile_setup]');
            foreach ($auth_shortcodes as $sc) {
                if (strpos($post->post_content, $sc) !== false) {
                    $needs_session = true;
                    break;
                }
            }
            if ($needs_session && !isset($_COOKIE['hl_auth_session'])) {
                $token = wp_generate_password(32, false);
                setcookie('hl_auth_session', $token, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
                $_COOKIE['hl_auth_session'] = $token; // Make available in same request
            }
        }

        // --- Auth page POST handlers ---
        $this->handle_login_post();
        $this->handle_reset_request_post();
        $this->handle_profile_setup_post();

        // --- Already-logged-in redirect on auth pages (spec I22) ---
        if (is_user_logged_in() && is_page()) {
            global $post;
            if (strpos($post->post_content, '[hl_login]') !== false ||
                strpos($post->post_content, '[hl_password_reset]') !== false) {

                $redirect_url = HL_Auth_Service::resolve_post_login_redirect(wp_get_current_user());
                wp_safe_redirect($redirect_url);
                exit;
            }

            // PC2: Redirect away from profile setup if already complete
            // (moved from render() -- redirects must happen before output)
            if (strpos($post->post_content, '[hl_profile_setup]') !== false) {
                if (!current_user_can('manage_options') &&
                    HL_Auth_Service::is_profile_complete(get_current_user_id())) {
                    wp_safe_redirect(HL_Core::get_dashboard_url());
                    exit;
                }
            }
        }

        // --- Redirect non-logged-in users away from profile setup ---
        if (!is_user_logged_in() && is_page()) {
            global $post;
            if (strpos($post->post_content, '[hl_profile_setup]') !== false) {
                wp_safe_redirect(HL_Auth_Service::get_login_page_url() ?: wp_login_url());
                exit;
            }
        }

        // --- Nocache headers for auth pages (spec I8) ---
        if (is_page()) {
            global $post;
            $auth_shortcodes = array('[hl_login]', '[hl_password_reset]', '[hl_profile_setup]');
            foreach ($auth_shortcodes as $sc) {
                if (strpos($post->post_content, $sc) !== false) {
                    nocache_headers();
                    break;
                }
            }
        }

        // --- Profile gate (frontend) (spec C6, C7) ---
        if (!is_user_logged_in()) {
            return;
        }

        // Don't gate admins
        if (current_user_can('manage_options')) {
            return;
        }

        // Don't gate the profile setup page itself or other auth pages
        if (is_page()) {
            global $post;
            if (strpos($post->post_content, '[hl_profile_setup]') !== false) {
                return;
            }
            if (strpos($post->post_content, '[hl_login]') !== false ||
                strpos($post->post_content, '[hl_password_reset]') !== false) {
                return;
            }
        }

        // Only gate pages that have [hl_*] shortcodes (our pages)
        if (!is_page()) {
            return;
        }
        global $post;
        if (strpos($post->post_content, '[hl_') === false) {
            return;
        }

        // Check if profile is complete
        $user_id = get_current_user_id();
        if (!HL_Auth_Service::is_profile_complete($user_id)) {
            $setup_url = HL_Auth_Service::get_profile_setup_page_url();

            // C7: Guard against empty URL = infinite redirect
            if (empty($setup_url)) {
                error_log('[HL Auth] Profile setup page URL is empty. Skipping profile gate for user ' . $user_id . '. Create a page with [hl_profile_setup] shortcode.');
                return; // Fail open
            }

            wp_safe_redirect($setup_url);
            exit;
        }
    }

    // =========================================================================
    // Login POST Handler (spec C5 PRG pattern)
    // =========================================================================

    private function handle_login_post() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (!is_page()) return;
        if (!isset($_POST['hl_auth_action']) || $_POST['hl_auth_action'] !== 'login') return;

        if (!wp_verify_nonce($_POST['hl_login_nonce'] ?? '', 'hl_login_action')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        $email    = sanitize_email($_POST['hl_login_email'] ?? '');
        $password = $_POST['hl_login_password'] ?? '';
        $session_token = sanitize_text_field($_POST['hl_session_token'] ?? '');

        // Empty fields check
        if (empty($email) || empty($password)) {
            $this->store_auth_error($session_token, 'empty_fields');
            wp_safe_redirect(add_query_arg('hl_auth_error', '1', HL_Auth_Service::get_login_page_url()));
            exit;
        }

        // Rate limit check (spec C3)
        $client_ip = HL_Auth_Service::get_client_ip();
        if (HL_Auth_Service::check_rate_limit($client_ip)) {
            $this->store_auth_error($session_token, 'rate_limited');
            wp_safe_redirect(add_query_arg('hl_auth_error', '1', HL_Auth_Service::get_login_page_url()));
            exit;
        }

        // Attempt login
        $user = wp_signon(array(
            'user_login'    => $email,
            'user_password' => $password,
            'remember'      => true,
        ), is_ssl());

        if (is_wp_error($user)) {
            HL_Auth_Service::record_failed_attempt($client_ip);
            $this->store_auth_error($session_token, 'invalid_credentials');
            wp_safe_redirect(add_query_arg('hl_auth_error', '1', HL_Auth_Service::get_login_page_url()));
            exit;
        }

        // Success -- clear rate limit, redirect
        // Note: wp_signon() already sets auth cookies (spec Q1 fix #6)
        HL_Auth_Service::clear_rate_limit($client_ip);
        wp_set_current_user($user->ID);

        // PI3: Honour redirect_to from query string if present
        $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : '';
        $redirect_url = HL_Auth_Service::resolve_post_login_redirect($user, $redirect_to);
        wp_safe_redirect($redirect_url);
        exit;
    }

    // =========================================================================
    // Password Reset Request POST Handler (spec I11, I21)
    // =========================================================================

    private function handle_reset_request_post() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (!is_page()) return;
        if (!isset($_POST['hl_auth_action']) || $_POST['hl_auth_action'] !== 'reset_request') return;

        if (!wp_verify_nonce($_POST['hl_reset_nonce'] ?? '', 'hl_reset_request_action')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        $email = sanitize_email($_POST['hl_reset_email'] ?? '');

        if (!empty($email)) {
            // Find user by email, call retrieve_password (spec I21)
            $user = get_user_by('email', $email);
            if ($user) {
                $result = retrieve_password($user->user_login);
                if (is_wp_error($result)) {
                    // Log error but show neutral message to user (spec I11)
                    error_log('[HL Auth] retrieve_password failed for user ' . $user->ID . ': ' . $result->get_error_message());
                }
            }
            // Always show success (prevents user enumeration)
        }

        $reset_url = HL_Auth_Service::get_password_reset_page_url();
        wp_safe_redirect(add_query_arg('hl_reset_sent', '1', $reset_url));
        exit;
    }

    // =========================================================================
    // Profile Setup POST Handler (spec I15 query sequence)
    // =========================================================================

    private function handle_profile_setup_post() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (!is_page()) return;
        if (!isset($_POST['hl_auth_action']) || $_POST['hl_auth_action'] !== 'profile_setup') return;

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        if (!wp_verify_nonce($_POST['hl_profile_nonce'] ?? '', 'hl_profile_setup_action')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        $user_id = get_current_user_id();
        $session_token = sanitize_text_field($_POST['hl_session_token'] ?? '');

        // -------------------------------------------------------
        // Sanitize ALL fields
        // -------------------------------------------------------

        // Step 1: Personal Information
        $first_name    = sanitize_text_field($_POST['hl_first_name'] ?? '');
        $last_name     = sanitize_text_field($_POST['hl_last_name'] ?? '');
        $nickname      = sanitize_text_field($_POST['hl_nickname'] ?? '');
        $phone_cc      = sanitize_text_field($_POST['hl_phone_country_code'] ?? '+1');
        $phone_num     = sanitize_text_field($_POST['hl_phone_number'] ?? '');
        $gender        = sanitize_text_field($_POST['hl_gender'] ?? '');
        $ethnicity     = isset($_POST['hl_ethnicity']) ? array_map('sanitize_text_field', (array) $_POST['hl_ethnicity']) : array();
        $location      = sanitize_text_field($_POST['hl_location_state'] ?? '');
        $age_range     = sanitize_text_field($_POST['hl_age_range'] ?? '');
        $language      = sanitize_text_field($_POST['hl_preferred_language'] ?? 'en');

        // Step 2: Professional Information
        $years_industry = sanitize_text_field($_POST['hl_years_exp_industry'] ?? '');
        $years_position = sanitize_text_field($_POST['hl_years_exp_position'] ?? '');
        $job_title      = sanitize_text_field($_POST['hl_job_title'] ?? '');

        // Step 3: Social Media (all optional)
        // Spec FI3: Strip leading @ from social handles
        $social_instagram = ltrim(sanitize_text_field($_POST['hl_social_instagram'] ?? ''), '@');
        $social_twitter   = ltrim(sanitize_text_field($_POST['hl_social_twitter'] ?? ''), '@');
        $social_linkedin  = esc_url_raw($_POST['hl_social_linkedin'] ?? '');
        $social_facebook  = esc_url_raw($_POST['hl_social_facebook'] ?? '');
        $social_website   = esc_url_raw($_POST['hl_social_website'] ?? '');

        // Consent
        $consent = !empty($_POST['hl_consent']);

        // -------------------------------------------------------
        // Validate against allowlists
        // -------------------------------------------------------

        // Phone country code
        $valid_cc = array('+1', '+52', '+55');
        if (!in_array($phone_cc, $valid_cc, true)) {
            $phone_cc = '+1';
        }

        // Gender
        $valid_gender_keys = array_keys(HL_Frontend_Profile_Setup::GENDER_OPTIONS);
        if (!empty($gender) && !in_array($gender, $valid_gender_keys, true)) {
            $gender = '';
        }

        // Ethnicity against allowlist (spec I13)
        $valid_eth_keys = array_keys(HL_Frontend_Profile_Setup::ETHNICITY_OPTIONS);
        $ethnicity = array_values(array_intersect($ethnicity, $valid_eth_keys));

        // Location against allowlist
        $valid_location_keys = array_keys(HL_Frontend_Profile_Setup::LOCATION_OPTIONS);
        if (!empty($location) && !in_array($location, $valid_location_keys, true)) {
            $location = '';
        }

        // Age range
        $valid_age_keys = array_keys(HL_Frontend_Profile_Setup::AGE_RANGE_OPTIONS);
        if (!empty($age_range) && !in_array($age_range, $valid_age_keys, true)) {
            $age_range = '';
        }

        // Language (spec FI4)
        $valid_lang_keys = array_keys(HL_Frontend_Profile_Setup::LANGUAGE_OPTIONS);
        if (!in_array($language, $valid_lang_keys, true)) {
            $language = 'en';
        }

        // Years exp (both fields, same allowlist)
        $valid_years_keys = array_keys(HL_Frontend_Profile_Setup::YEARS_EXP_OPTIONS);
        if (!empty($years_industry) && !in_array($years_industry, $valid_years_keys, true)) {
            $years_industry = '';
        }
        if (!empty($years_position) && !in_array($years_position, $valid_years_keys, true)) {
            $years_position = '';
        }

        // -------------------------------------------------------
        // Required field checks
        // -------------------------------------------------------
        $errors = array();

        if (empty($first_name)) {
            $errors[] = __('First name is required.', 'hl-core');
        }
        if (empty($last_name)) {
            $errors[] = __('Last name is required.', 'hl-core');
        }
        if (empty($nickname)) {
            $errors[] = __('Nickname is required.', 'hl-core');
        }
        if (empty($gender)) {
            $errors[] = __('Gender is required.', 'hl-core');
        }
        if (empty($ethnicity)) {
            $errors[] = __('Please select at least one ethnicity option.', 'hl-core');
        }
        if (empty($location)) {
            $errors[] = __('Location is required.', 'hl-core');
        }
        if (empty($age_range)) {
            $errors[] = __('Age range is required.', 'hl-core');
        }
        if (empty($language)) {
            $errors[] = __('Preferred language is required.', 'hl-core');
        }
        if (empty($years_industry)) {
            $errors[] = __('Years of experience in industry is required.', 'hl-core');
        }
        if (empty($years_position)) {
            $errors[] = __('Years of experience in current position is required.', 'hl-core');
        }
        if (!$consent) {
            $errors[] = __('You must agree to the research participation terms.', 'hl-core');
        }

        if (!empty($errors)) {
            if (!empty($session_token)) {
                $transient_key = 'hl_profile_err_' . substr(wp_hash($session_token), 0, 20);
                set_transient($transient_key, $errors, 30);
            }
            $setup_url = HL_Auth_Service::get_profile_setup_page_url();
            wp_safe_redirect(add_query_arg('hl_profile_error', '1', $setup_url));
            exit;
        }

        // -------------------------------------------------------
        // I15: Strict query sequence
        // -------------------------------------------------------

        // Step 1: Update WP user (first_name, last_name; nickname synced to usermeta)
        $wp_update = wp_update_user(array(
            'ID'         => $user_id,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'nickname'   => $nickname,
        ));

        if (is_wp_error($wp_update)) {
            error_log('[HL Auth] wp_update_user failed for user ' . $user_id . ': ' . $wp_update->get_error_message());
            if (!empty($session_token)) {
                $transient_key = 'hl_profile_err_' . substr(wp_hash($session_token), 0, 20);
                set_transient($transient_key, array(__('Failed to save profile. Please try again.', 'hl-core')), 30);
            }
            $setup_url = HL_Auth_Service::get_profile_setup_page_url();
            wp_safe_redirect(add_query_arg('hl_profile_error', '1', $setup_url));
            exit;
        }

        // Step 2: Upsert profile (spec C1: INSERT ... ON DUPLICATE KEY UPDATE)
        $profile_data = array(
            'nickname'             => $nickname,
            'phone_country_code'   => $phone_cc,
            'phone_number'         => $phone_num,
            'gender'               => $gender,
            'ethnicity'            => wp_json_encode($ethnicity),
            'location_state'       => $location,
            'age_range'            => $age_range,
            'preferred_language'   => $language,
            'years_exp_industry'   => $years_industry,
            'years_exp_position'   => $years_position,
            'job_title'            => $job_title,
            'social_instagram'     => $social_instagram,
            'social_twitter'       => $social_twitter,
            'social_linkedin'      => $social_linkedin,
            'social_facebook'      => $social_facebook,
            'social_website'       => $social_website,
            'profile_completed_at' => current_time('mysql'),
        );

        // Spec FI5: Only set consent_given_at when consent is checked AND no existing timestamp.
        $existing_profile = HL_Auth_Repository::get($user_id);
        $has_existing_consent = $existing_profile && !empty($existing_profile->consent_given_at);
        if ($consent && !$has_existing_consent) {
            $profile_data['consent_given_at'] = current_time('mysql');
            $profile_data['consent_version']  = '1.0';
        }

        $upsert_result = HL_Auth_Repository::upsert($user_id, $profile_data);

        if (!$upsert_result) {
            error_log('[HL Auth] Profile upsert failed for user ' . $user_id);
            if (!empty($session_token)) {
                $transient_key = 'hl_profile_err_' . substr(wp_hash($session_token), 0, 20);
                set_transient($transient_key, array(__('Failed to save profile. Please try again.', 'hl-core')), 30);
            }
            $setup_url = HL_Auth_Service::get_profile_setup_page_url();
            wp_safe_redirect(add_query_arg('hl_profile_error', '1', $setup_url));
            exit;
        }

        // Step 3: Enrollment language sync (non-critical) (spec I16)
        HL_Auth_Service::sync_enrollment_language($user_id, $language);

        // Set profile complete cache (spec I17)
        wp_cache_set('profile_complete_' . $user_id, true, 'hl_profiles', 3600);

        // Audit log
        HL_Audit_Service::log('user.profile_completed', array(
            'entity_type' => 'user',
            'entity_id'   => $user_id,
        ));

        // Redirect to dashboard
        wp_safe_redirect(HL_Core::get_dashboard_url());
        exit;
    }

    // =========================================================================
    // 3. wp-admin Profile Gate (spec C6)
    // =========================================================================

    public function enforce_profile_gate_admin() {
        // Let AJAX through
        if (wp_doing_ajax()) {
            return;
        }

        // Let cron through
        if (wp_doing_cron()) {
            return;
        }

        // Let WP-CLI through
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }

        // Don't gate admins
        if (!is_user_logged_in() || current_user_can('manage_options')) {
            return;
        }

        // Check profile completion
        $user_id = get_current_user_id();
        if (HL_Auth_Service::is_profile_complete($user_id)) {
            return;
        }

        $setup_url = HL_Auth_Service::get_profile_setup_page_url();

        // Spec C7: Fail open if setup page doesn't exist
        if (empty($setup_url)) {
            error_log('[HL Auth] Profile setup page URL is empty. Skipping admin gate for user ' . $user_id);
            return;
        }

        wp_safe_redirect($setup_url);
        exit;
    }

    // =========================================================================
    // 4. Post-Login Redirect Filter
    // =========================================================================

    public function filter_login_redirect($redirect_to, $requested, $user) {
        if (!($user instanceof \WP_User)) {
            return $redirect_to;
        }
        // PI3: Pass redirect_to so the service can honour it for non-enrolled users
        return HL_Auth_Service::resolve_post_login_redirect($user, $redirect_to);
    }

    // =========================================================================
    // 5. Password Reset Expiration (spec I14)
    // =========================================================================

    public function extend_reset_expiration() {
        return 7 * DAY_IN_SECONDS;
    }

    // =========================================================================
    // 6. PII Cleanup on User Deletion (spec I19)
    // =========================================================================

    public function cleanup_user_profile($user_id) {
        HL_Auth_Repository::delete($user_id);
    }

    // =========================================================================
    // 7. URL Filters (spec Appendix F)
    // =========================================================================

    public function filter_login_url($login_url, $redirect, $force_reauth) {
        $custom_url = HL_Auth_Service::get_login_page_url();
        if ($custom_url) {
            if (!empty($redirect)) {
                $custom_url = add_query_arg('redirect_to', urlencode($redirect), $custom_url);
            }
            return $custom_url;
        }
        return $login_url;
    }

    public function filter_lostpassword_url($lostpassword_url, $redirect) {
        $custom_url = HL_Auth_Service::get_password_reset_page_url();
        if ($custom_url) {
            return $custom_url;
        }
        return $lostpassword_url;
    }

    // =========================================================================
    // Helper: Store Auth Error in Transient (spec C5 PRG)
    // =========================================================================

    private function store_auth_error($session_token, $error_code) {
        if (empty($session_token)) return;
        $transient_key = 'hl_auth_err_' . substr(wp_hash($session_token), 0, 20);
        set_transient($transient_key, $error_code, 30); // 30 second TTL
    }
}
```

**Verify:**
```bash
# After deploying:
ssh test-server "cd /path/to/wp && wp eval 'HL_Auth_Manager::instance(); echo \"Auth Manager loaded\";'"
# Should output: Auth Manager loaded
```

- [ ] 3b. (PI4) In `hl-core.php`, remove the `password_reset_expiration` filter from `init_hooks()` NOW (same commit as the Auth Manager that replaces it). Delete these lines:

```php
        // Extend password reset key expiration to 7 days (default is 24 hours).
        add_filter('password_reset_expiration', function () {
            return 7 * DAY_IN_SECONDS;
        });
```

This is now handled by `HL_Auth_Manager::extend_reset_expiration()` (spec I14). Removing it here avoids double-registration during the window between Task 3 and Task 10.

**Commit:** `git add includes/auth/class-hl-auth-manager.php hl-core.php && git commit -m "feat(auth): add Auth Manager with all hook registrations"`

---

## Task 4: Auth Template (hl-auth.php)

**Files:**
- CREATE: `templates/hl-auth.php`

**Steps:**

- [ ] 4a. Create `templates/hl-auth.php` with the following complete content:

```php
<?php
/**
 * HL Auth Template
 *
 * Full-bleed template for login, password reset, and profile setup pages.
 * No sidebar, no topbar. Centered card layout on gradient background.
 *
 * @package HL_Core
 */
if (!defined('ABSPATH')) exit;

// Prevent caching (spec I8)
nocache_headers();

global $post;
$page_content = do_shortcode($post->post_content);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?php echo esc_html(get_the_title()); ?> &mdash; Housman Learning</title>
    <?php wp_site_icon(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo esc_url(HL_CORE_ASSETS_URL . 'css/frontend.css'); ?>?ver=<?php echo esc_attr(HL_CORE_VERSION); ?>">
    <?php
    // PI1: Use wp_head() instead of wp_print_styles/scripts so that
    // jQuery and other dependencies are enqueued properly and plugin
    // hooks (wp_enqueue_scripts) fire correctly.
    wp_enqueue_style('dashicons');
    wp_enqueue_script('jquery');
    wp_enqueue_script('hl-auth', HL_CORE_ASSETS_URL . 'js/hl-auth.js', array('jquery'), HL_CORE_VERSION, true);
    wp_head();
    ?>
</head>
<body class="hl-auth-page">
    <?php
    // Spec FI1: Profile setup needs wider container (680px vs 480px default)
    $wrapper_class = 'hl-auth-wrapper';
    if (strpos($post->post_content, '[hl_profile_setup]') !== false) {
        $wrapper_class .= ' hl-auth-wrapper--wide';
    }
    ?>
    <div class="<?php echo esc_attr($wrapper_class); ?>">
        <?php echo $page_content; ?>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
```

**Verify:** Template file exists at `templates/hl-auth.php`. Browser verification happens after template interception is wired (Task 13).

**Commit:** `git add templates/hl-auth.php && git commit -m "feat(auth): add hl-auth.php full-bleed template"`

---

## Task 5: CSS Additions to frontend.css

**Files:**
- MODIFY: `assets/css/frontend.css` (line 174 global selector, plus new auth CSS at end of file)

**Steps:**

- [ ] 5a. In `assets/css/frontend.css`, modify the global form selector at line 174. Change:

```css
:where(.hl-app) input[type="text"],
:where(.hl-app) input[type="email"],
:where(.hl-app) input[type="number"],
:where(.hl-app) input[type="search"],
:where(.hl-app) input[type="date"],
:where(.hl-app) select,
:where(.hl-app) textarea {
```

To:

```css
:where(.hl-app) input[type="text"],
:where(.hl-app) input[type="email"],
:where(.hl-app) input[type="password"],
:where(.hl-app) input[type="number"],
:where(.hl-app) input[type="search"],
:where(.hl-app) input[type="date"],
:where(.hl-app) input[type="tel"],
:where(.hl-app) input[type="url"],
:where(.hl-app) select,
:where(.hl-app) textarea {
```

- [ ] 5b. Append ALL auth CSS to the end of `assets/css/frontend.css`. This is the complete auth CSS block -- copy verbatim from the spec sections C, E pill/radio/step/phone/consent/prefix/field-row/readonly/url/step-intro CSS. The full block is:

```css
/* =====================================================
   AUTH PAGES (Login, Password Reset, Profile Setup)
   ===================================================== */

.hl-auth-page {
    margin: 0;
    padding: 0;
    font-family: var(--hl-font);
    background: linear-gradient(135deg, var(--hl-primary) 0%, var(--hl-primary-light) 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}

.hl-auth-wrapper {
    width: 100%;
    max-width: 480px;
    padding: 24px;
}

/* Profile setup needs wider container for multi-step form */
.hl-auth-wrapper--wide {
    max-width: 680px;
}

.hl-auth-card {
    background: var(--hl-surface);
    border-radius: var(--hl-radius);
    box-shadow: var(--hl-shadow-lg);
    padding: 40px;
    text-align: center;
}

.hl-auth-logo {
    max-width: 180px;
    margin: 0 auto 32px;
}

.hl-auth-title {
    font-size: 24px;
    font-weight: 700;
    color: var(--hl-text-heading);
    margin: 0 0 8px;
}

.hl-auth-subtitle {
    font-size: 14px;
    color: var(--hl-text-secondary);
    margin: 0 0 32px;
}

.hl-auth-form {
    text-align: left;
}

.hl-auth-field {
    margin-bottom: 20px;
}

.hl-auth-field label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--hl-text);
    margin-bottom: 6px;
}

.hl-auth-field input[type="text"],
.hl-auth-field input[type="email"],
.hl-auth-field input[type="password"],
.hl-auth-field input[type="tel"],
.hl-auth-field input[type="url"] {
    width: 100%;
    box-sizing: border-box;
    font-family: var(--hl-font);
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-xs);
    padding: 12px 14px;
    font-size: 15px;
    color: var(--hl-text);
    background: var(--hl-surface);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    outline: none;
}

.hl-auth-field input:focus {
    border-color: var(--hl-interactive);
    box-shadow: 0 0 0 3px var(--hl-interactive-bg);
}

.hl-auth-field--error input {
    border-color: var(--hl-error);
}

.hl-auth-field--error input:focus {
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
}

.hl-auth-error {
    background: #FEF2F2;
    border: 1px solid #FECACA;
    border-radius: var(--hl-radius-xs);
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: 14px;
    color: var(--hl-error-dark);
    display: flex;
    align-items: center;
    gap: 8px;
}

.hl-auth-error .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}

.hl-auth-success {
    background: #F0FDF4;
    border: 1px solid #BBF7D0;
    border-radius: var(--hl-radius-xs);
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: 14px;
    color: var(--hl-accent-dark);
}

.hl-auth-btn {
    display: block;
    width: 100%;
    padding: 14px;
    background: var(--hl-accent);
    color: #fff;
    border: none;
    border-radius: var(--hl-radius-xs);
    font-size: 16px;
    font-weight: 600;
    font-family: var(--hl-font);
    cursor: pointer;
    transition: background 0.15s ease;
    position: relative;
}

.hl-auth-btn:hover {
    background: var(--hl-accent-dark);
}

.hl-auth-btn[aria-disabled="true"] {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.hl-auth-btn--submitting .hl-auth-btn-text {
    visibility: hidden;
}

.hl-auth-btn--submitting::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 20px;
    height: 20px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: hl-spin 0.6s linear infinite;
}

@keyframes hl-spin {
    to { transform: translate(-50%, -50%) rotate(360deg); }
}

.hl-auth-links {
    margin-top: 24px;
    text-align: center;
    font-size: 14px;
}

.hl-auth-links a {
    color: var(--hl-secondary);
    text-decoration: none;
}

.hl-auth-links a:hover {
    text-decoration: underline;
}

/* Password strength meter (PI6: reserved for Phase 2 custom reset form) */
.hl-pw-strength {
    margin-top: 8px;
    height: 4px;
    border-radius: 2px;
    background: var(--hl-border);
    overflow: hidden;
}

.hl-pw-strength__bar {
    height: 100%;
    border-radius: 2px;
    transition: width 0.3s ease, background 0.3s ease;
    width: 0;
}

.hl-pw-strength--weak .hl-pw-strength__bar    { width: 25%;  background: var(--hl-error); }
.hl-pw-strength--fair .hl-pw-strength__bar    { width: 50%;  background: var(--hl-warning); }
.hl-pw-strength--good .hl-pw-strength__bar    { width: 75%;  background: #60A5FA; }
.hl-pw-strength--strong .hl-pw-strength__bar  { width: 100%; background: var(--hl-accent); }

.hl-pw-strength__label {
    font-size: 12px;
    margin-top: 4px;
    color: var(--hl-text-secondary);
}

/* Pill checkbox (multi-select) */
.hl-pill-check-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.hl-pill-check {
    display: inline-flex;
    cursor: pointer;
}

.hl-pill-check input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.hl-pill-check__label {
    display: inline-flex;
    align-items: center;
    padding: 8px 16px;
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-pill);
    font-size: 13px;
    font-weight: 500;
    color: var(--hl-text);
    background: var(--hl-surface);
    transition: all 0.15s ease;
    user-select: none;
}

.hl-pill-check__label:hover {
    border-color: var(--hl-interactive);
    background: var(--hl-interactive-bg);
}

.hl-pill-check input:checked + .hl-pill-check__label {
    background: var(--hl-interactive-bg);
    border-color: var(--hl-interactive);
    color: var(--hl-interactive-dark);
    font-weight: 600;
}

.hl-pill-check input:focus-visible + .hl-pill-check__label {
    outline: 2px solid var(--hl-interactive);
    outline-offset: 2px;
}

/* Step indicator */
.hl-steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    margin-bottom: 32px;
}

.hl-steps__item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    cursor: default;
}

.hl-steps__number {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    background: var(--hl-bg);
    color: var(--hl-text-secondary);
    border: 2px solid var(--hl-border);
    transition: all 0.2s ease;
}

.hl-steps__label {
    font-size: 13px;
    font-weight: 500;
    color: var(--hl-text-secondary);
}

.hl-steps__divider {
    width: 32px;
    height: 2px;
    background: var(--hl-border);
}

/* Active step */
.hl-steps__item--active .hl-steps__number {
    background: var(--hl-interactive);
    border-color: var(--hl-interactive);
    color: #fff;
}

.hl-steps__item--active .hl-steps__label {
    color: var(--hl-interactive-dark);
    font-weight: 600;
}

/* Completed step (no errors) */
.hl-steps__item--complete .hl-steps__number {
    background: var(--hl-accent);
    border-color: var(--hl-accent);
    color: #fff;
    font-size: 0;
}

.hl-steps__item--complete .hl-steps__number::after {
    content: '\2713';
    font-size: 14px;
}

/* Step with errors (spec I7) */
.hl-steps__item--error .hl-steps__number {
    background: var(--hl-warning);
    border-color: var(--hl-warning);
    color: #fff;
}

/* Phone group */
.hl-phone-group {
    display: flex;
    gap: 8px;
}

.hl-phone-cc {
    width: 110px;
    flex-shrink: 0;
}

.hl-phone-group input[type="tel"] {
    flex: 1;
    min-width: 0;
}

/* Step navigation */
.hl-step-nav {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}

.hl-step-nav .hl-auth-btn {
    flex: 1;
}

.hl-auth-btn--secondary {
    background: var(--hl-bg);
    color: var(--hl-text);
    border: 1px solid var(--hl-border);
}

.hl-auth-btn--secondary:hover {
    background: var(--hl-bg-hover);
    border-color: var(--hl-border-medium);
}

/* Consent box */
.hl-consent-box {
    background: var(--hl-bg-subtle);
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-sm);
    padding: 24px;
    margin-bottom: 24px;
    text-align: left;
}

.hl-consent-box h3 {
    font-size: 16px;
    font-weight: 600;
    color: var(--hl-text-heading);
    margin: 0 0 12px;
}

.hl-consent-text {
    font-size: 14px;
    line-height: 1.6;
    color: var(--hl-text);
    margin-bottom: 16px;
}

.hl-consent-check {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: var(--hl-text);
}

.hl-consent-check input[type="checkbox"] {
    margin-top: 2px;
    flex-shrink: 0;
}

/* Wide card variant for profile setup */
.hl-auth-card--wide {
    max-width: 640px;
    margin: 0 auto;
}

/* 2-Column Grid for Name Fields */
.hl-field-row--2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

@media (max-width: 480px) {
    .hl-field-row--2col {
        grid-template-columns: 1fr;
    }
}

/* Read-only input (email) */
.hl-input--readonly {
    background: var(--hl-bg) !important;
    color: var(--hl-text-secondary) !important;
    cursor: not-allowed;
}

/* Required / Optional indicators */
.hl-required {
    color: var(--hl-error);
    font-weight: 600;
}

.hl-optional {
    color: var(--hl-text-secondary);
    font-weight: 400;
    font-size: 12px;
}

/* Radio Group */
.hl-radio-group {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.hl-radio {
    display: inline-flex;
    align-items: center;
    cursor: pointer;
}

.hl-radio input[type="radio"] {
    margin-right: 6px;
    accent-color: var(--hl-interactive);
}

.hl-radio__label {
    font-size: 14px;
    font-weight: 500;
    color: var(--hl-text);
}

/* Location Dropdown */
.hl-auth-field select {
    width: 100%;
    box-sizing: border-box;
    font-family: var(--hl-font);
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-xs);
    padding: 12px 14px;
    font-size: 15px;
    color: var(--hl-text);
    background: var(--hl-surface);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    outline: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236B7280' stroke-width='1.5' fill='none'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 36px;
}

.hl-auth-field select:focus {
    border-color: var(--hl-interactive);
    box-shadow: 0 0 0 3px var(--hl-interactive-bg);
}

/* Social Media @ Prefix Input */
.hl-input-prefix-group {
    display: flex;
    align-items: stretch;
}

.hl-input-prefix {
    display: flex;
    align-items: center;
    padding: 0 12px;
    background: var(--hl-bg);
    border: 1px solid var(--hl-border);
    border-right: none;
    border-radius: var(--hl-radius-xs) 0 0 var(--hl-radius-xs);
    font-size: 15px;
    font-weight: 600;
    color: var(--hl-text-secondary);
}

.hl-input-prefix-group input {
    flex: 1;
    min-width: 0;
    border-radius: 0 var(--hl-radius-xs) var(--hl-radius-xs) 0 !important;
}

/* URL inputs (LinkedIn, Facebook, Website) */
.hl-auth-field input[type="url"]:focus {
    border-color: var(--hl-interactive);
    box-shadow: 0 0 0 3px var(--hl-interactive-bg);
}

/* Step intro text */
.hl-step-intro {
    font-size: 14px;
    color: var(--hl-text-secondary);
    margin: 0 0 20px;
}

/* Field-level error indicator */
.hl-field-error label {
    color: var(--hl-error);
}

.hl-field-error input,
.hl-field-error select {
    border-color: var(--hl-error);
}

.hl-field-error .hl-pill-check__label {
    border-color: var(--hl-error);
}

/* PI5: Mobile responsive rules */
@media (max-width: 480px) {
    .hl-steps__label { display: none; }
    .hl-step-nav { flex-direction: column; }
    .hl-step-nav .hl-auth-btn { width: 100%; }
}
```

**Verify:** Open `assets/css/frontend.css` and confirm `input[type="password"]`, `input[type="tel"]`, `input[type="url"]` are in the global selector at line 174, and `.hl-auth-page` CSS exists at the end of the file.

**Commit:** `git add assets/css/frontend.css && git commit -m "feat(auth): add auth CSS (global input fix + all auth components)"`

---

## Task 6: Login Page Shortcode Renderer

**Files:**
- CREATE: `includes/frontend/class-hl-frontend-login.php`

**Steps:**

- [ ] 6a. Create `includes/frontend/class-hl-frontend-login.php` with the following complete content:

```php
<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_login] shortcode.
 *
 * Renders the login form on GET. POST handling is in HL_Auth_Manager.
 * Uses PRG pattern for error display (spec C5).
 *
 * @package HL_Core
 */
class HL_Frontend_Login {

    public static function render($atts) {
        // Check for error from PRG redirect (spec C5)
        $error_message = '';
        $session_token = isset($_COOKIE['hl_auth_session']) ? sanitize_text_field($_COOKIE['hl_auth_session']) : '';
        if (isset($_GET['hl_auth_error']) && $session_token) {
            $transient_key = 'hl_auth_err_' . substr(wp_hash($session_token), 0, 20);
            $error_code = get_transient($transient_key);
            delete_transient($transient_key);

            $error_messages = array(
                'invalid_credentials' => __('Invalid email or password. Please try again.', 'hl-core'),
                'rate_limited'        => __('Too many failed attempts. Please wait a few minutes and try again.', 'hl-core'),
                'empty_fields'        => __('Please enter your email and password.', 'hl-core'),
            );

            $error_message = isset($error_messages[$error_code]) ? $error_messages[$error_code] : '';
        }

        // Logo
        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        // PI7: Session token for hidden field (cookie already set in template_redirect) -- sanitize on read
        $session_token = isset($_COOKIE['hl_auth_session']) ? sanitize_text_field($_COOKIE['hl_auth_session']) : '';

        ob_start();
        ?>
        <div class="hl-auth-card">
            <?php if ($logo_url) : ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="hl-auth-logo">
            <?php endif; ?>

            <h1 class="hl-auth-title"><?php esc_html_e('Welcome Back', 'hl-core'); ?></h1>
            <p class="hl-auth-subtitle"><?php esc_html_e('Sign in to Housman Learning Academy', 'hl-core'); ?></p>

            <?php if ($error_message) : ?>
                <div class="hl-auth-error" role="alert">
                    <span class="dashicons dashicons-warning"></span>
                    <?php echo esc_html($error_message); ?>
                </div>
            <?php endif; ?>

            <form class="hl-auth-form" method="post" action="" id="hl-login-form">
                <?php wp_nonce_field('hl_login_action', 'hl_login_nonce'); ?>
                <input type="hidden" name="hl_auth_action" value="login">
                <input type="hidden" name="hl_session_token" value="<?php echo esc_attr($session_token); ?>">

                <div class="hl-auth-field">
                    <label for="hl-login-email"><?php esc_html_e('Email Address', 'hl-core'); ?></label>
                    <input type="email" id="hl-login-email" name="hl_login_email"
                           autocomplete="username"
                           required
                           placeholder="<?php esc_attr_e('you@example.com', 'hl-core'); ?>">
                </div>

                <div class="hl-auth-field">
                    <label for="hl-login-password"><?php esc_html_e('Password', 'hl-core'); ?></label>
                    <input type="password" id="hl-login-password" name="hl_login_password"
                           autocomplete="current-password"
                           required
                           placeholder="<?php esc_attr_e('Enter your password', 'hl-core'); ?>">
                </div>

                <button type="submit" class="hl-auth-btn" id="hl-login-btn">
                    <span class="hl-auth-btn-text"><?php esc_html_e('Sign In', 'hl-core'); ?></span>
                </button>
            </form>

            <div class="hl-auth-links">
                <a href="<?php echo esc_url(wp_lostpassword_url()); ?>"><?php esc_html_e('Forgot your password?', 'hl-core'); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
```

**Verify:** File exists. Full browser test after Task 13 (wiring).

**Commit:** `git add includes/frontend/class-hl-frontend-login.php && git commit -m "feat(auth): add Login shortcode renderer"`

---

## Task 7: Password Reset Shortcode Renderer

**Files:**
- CREATE: `includes/frontend/class-hl-frontend-password-reset.php`

**Steps:**

- [ ] 7a. Create `includes/frontend/class-hl-frontend-password-reset.php` with the following complete content:

```php
<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_password_reset] shortcode.
 *
 * Renders the "request reset link" form. POST handling is in HL_Auth_Manager.
 * Always shows a neutral success message (prevents user enumeration).
 *
 * Note: The actual new-password form stays on wp-login.php?action=rp
 * because WP core handles key validation there. See spec note after D.
 *
 * @package HL_Core
 */
class HL_Frontend_Password_Reset {

    public static function render($atts) {
        // Check for success state (PRG)
        $show_success = isset($_GET['hl_reset_sent']) && $_GET['hl_reset_sent'] === '1';

        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        ob_start();
        ?>
        <div class="hl-auth-card">
            <?php if ($logo_url) : ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="hl-auth-logo">
            <?php endif; ?>

            <h1 class="hl-auth-title"><?php esc_html_e('Reset Your Password', 'hl-core'); ?></h1>
            <p class="hl-auth-subtitle"><?php esc_html_e('Enter your email and we\'ll send you a reset link.', 'hl-core'); ?></p>

            <?php if ($show_success) : ?>
                <div class="hl-auth-success" role="status">
                    <?php esc_html_e('If an account exists with that email, you\'ll receive a password reset link shortly.', 'hl-core'); ?>
                </div>
            <?php endif; ?>

            <form class="hl-auth-form" method="post" action="">
                <?php wp_nonce_field('hl_reset_request_action', 'hl_reset_nonce'); ?>
                <input type="hidden" name="hl_auth_action" value="reset_request">

                <div class="hl-auth-field">
                    <label for="hl-reset-email"><?php esc_html_e('Email Address', 'hl-core'); ?></label>
                    <input type="email" id="hl-reset-email" name="hl_reset_email"
                           autocomplete="username"
                           required
                           placeholder="<?php esc_attr_e('you@example.com', 'hl-core'); ?>">
                </div>

                <button type="submit" class="hl-auth-btn" id="hl-reset-btn">
                    <span class="hl-auth-btn-text"><?php esc_html_e('Send Reset Link', 'hl-core'); ?></span>
                </button>
            </form>

            <div class="hl-auth-links">
                <a href="<?php echo esc_url(HL_Auth_Service::get_login_page_url() ?: wp_login_url()); ?>"><?php esc_html_e('Back to Sign In', 'hl-core'); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
```

**Verify:** File exists. Full browser test after Task 13.

**Commit:** `git add includes/frontend/class-hl-frontend-password-reset.php && git commit -m "feat(auth): add Password Reset shortcode renderer"`

---

## Task 8: Profile Setup Shortcode Renderer

**Files:**
- CREATE: `includes/frontend/class-hl-frontend-profile-setup.php`

**Steps:**

- [ ] 8a. Create `includes/frontend/class-hl-frontend-profile-setup.php` with the following complete content. This file contains the class constants (all option enums) and the full 3-step HTML form with ALL 18 form fields:

```php
<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_profile_setup] shortcode.
 *
 * Multi-step form: Step 1 (Personal Info), Step 2 (Professional), Step 3 (Social + Consent).
 * Single POST submission on final step; partial saves via localStorage on client.
 * POST handling is in HL_Auth_Manager.
 *
 * @package HL_Core
 */
class HL_Frontend_Profile_Setup {

    /**
     * Allowed ethnicity values (spec I13).
     */
    const ETHNICITY_OPTIONS = array(
        'african_american'        => 'African-American',
        'asian'                   => 'Asian',
        'caucasian'               => 'Caucasian',
        'latino_hispanic'         => 'Latino or Hispanic',
        'native_american'         => 'Native American',
        'native_hawaiian_pacific' => 'Native Hawaiian or Pacific Islander',
        'other_unknown'           => 'Other/Unknown',
        'prefer_not_to_say'       => 'Prefer not to say',
    );

    /**
     * Gender options.
     */
    const GENDER_OPTIONS = array(
        'male'               => 'Male',
        'female'             => 'Female',
        'transgender'        => 'Transgender',
        'different_identity' => 'Different gender identity',
        'other'              => 'Other',
    );

    /**
     * Age range options.
     */
    const AGE_RANGE_OPTIONS = array(
        '18-24' => '18-24',
        '25-34' => '25-34',
        '35-44' => '35-44',
        '45-54' => '45-54',
        '55-64' => '55-64',
        '64+'   => '64+',
    );

    /**
     * Language options.
     */
    const LANGUAGE_OPTIONS = array(
        'en' => 'English',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
    );

    /**
     * Years of experience options (used for BOTH industry and position fields).
     */
    const YEARS_EXP_OPTIONS = array(
        'less_than_1'  => 'Less than 1',
        '1-3'          => '1-3',
        '4-6'          => '4-6',
        '7-9'          => '7-9',
        '10-12'        => '10-12',
        'more_than_12' => 'More than 12',
    );

    /**
     * US States + Mexico/Canada/Other for Location dropdown.
     */
    const LOCATION_OPTIONS = array(
        'AL' => 'Alabama',        'AK' => 'Alaska',        'AZ' => 'Arizona',
        'AR' => 'Arkansas',       'CA' => 'California',     'CO' => 'Colorado',
        'CT' => 'Connecticut',    'DE' => 'Delaware',       'FL' => 'Florida',
        'GA' => 'Georgia',        'HI' => 'Hawaii',         'ID' => 'Idaho',
        'IL' => 'Illinois',       'IN' => 'Indiana',        'IA' => 'Iowa',
        'KS' => 'Kansas',         'KY' => 'Kentucky',       'LA' => 'Louisiana',
        'ME' => 'Maine',          'MD' => 'Maryland',       'MA' => 'Massachusetts',
        'MI' => 'Michigan',       'MN' => 'Minnesota',      'MS' => 'Mississippi',
        'MO' => 'Missouri',       'MT' => 'Montana',        'NE' => 'Nebraska',
        'NV' => 'Nevada',         'NH' => 'New Hampshire',  'NJ' => 'New Jersey',
        'NM' => 'New Mexico',     'NY' => 'New York',       'NC' => 'North Carolina',
        'ND' => 'North Dakota',   'OH' => 'Ohio',           'OK' => 'Oklahoma',
        'OR' => 'Oregon',         'PA' => 'Pennsylvania',   'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota',   'TN' => 'Tennessee',
        'TX' => 'Texas',          'UT' => 'Utah',           'VT' => 'Vermont',
        'VA' => 'Virginia',       'WA' => 'Washington',     'WV' => 'West Virginia',
        'WI' => 'Wisconsin',      'WY' => 'Wyoming',
        // --- International ---
        '--MX' => 'Mexico',
        '--CA' => 'Canada',
        '--OT' => 'Other',
    );

    public static function render($atts) {
        // PC2: No redirects here -- headers are already sent by the time
        // shortcode render() runs. The template_redirect handler in
        // HL_Auth_Manager::handle_auth_redirects() already ensures:
        //   - logged-out users are redirected to login
        //   - users with complete profiles are redirected to dashboard
        // If we reach this point, the user is logged in AND incomplete.

        if (!is_user_logged_in()) {
            // Fallback: return login link instead of redirect (headers already sent)
            return '<p>' . sprintf(
                __('Please <a href="%s">sign in</a> to complete your profile.', 'hl-core'),
                esc_url(HL_Auth_Service::get_login_page_url() ?: wp_login_url())
            ) . '</p>';
        }

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        // Check for validation error from POST
        $errors = array();
        $session_token = isset($_COOKIE['hl_auth_session']) ? sanitize_text_field($_COOKIE['hl_auth_session']) : '';
        if (isset($_GET['hl_profile_error']) && $session_token) {
            $transient_key = 'hl_profile_err_' . substr(wp_hash($session_token), 0, 20);
            $errors = get_transient($transient_key) ?: array();
            delete_transient($transient_key);
        }

        // Existing profile data (partial save from previous attempt)
        $profile = HL_Auth_Repository::get($user_id);

        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        // PI7: Session token (cookie already set in template_redirect) -- sanitize on read
        $session_token = isset($_COOKIE['hl_auth_session']) ? sanitize_text_field($_COOKIE['hl_auth_session']) : '';

        ob_start();
        ?>
        <div class="hl-auth-card hl-auth-card--wide">
            <?php if ($logo_url) : ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="hl-auth-logo">
            <?php endif; ?>

            <h1 class="hl-auth-title"><?php esc_html_e('Complete Your Profile', 'hl-core'); ?></h1>
            <p class="hl-auth-subtitle">
                <?php echo esc_html(sprintf(
                    __('Welcome, %s! Please complete your profile to continue.', 'hl-core'),
                    $user->first_name ?: $user->display_name
                )); ?>
            </p>

            <!-- Step Indicator (spec I7) -->
            <div class="hl-steps" role="tablist">
                <div class="hl-steps__item hl-steps__item--active" data-step="1" role="tab" aria-selected="true">
                    <span class="hl-steps__number">1</span>
                    <span class="hl-steps__label"><?php esc_html_e('Personal Info', 'hl-core'); ?></span>
                </div>
                <div class="hl-steps__divider"></div>
                <div class="hl-steps__item" data-step="2" role="tab" aria-selected="false">
                    <span class="hl-steps__number">2</span>
                    <span class="hl-steps__label"><?php esc_html_e('Professional', 'hl-core'); ?></span>
                </div>
                <div class="hl-steps__divider"></div>
                <div class="hl-steps__item" data-step="3" role="tab" aria-selected="false">
                    <span class="hl-steps__number">3</span>
                    <span class="hl-steps__label"><?php esc_html_e('Social Media', 'hl-core'); ?></span>
                </div>
            </div>

            <?php if (!empty($errors)) : ?>
                <div class="hl-auth-error" role="alert">
                    <span class="dashicons dashicons-warning"></span>
                    <div>
                        <?php foreach ($errors as $err) : ?>
                            <div><?php echo esc_html($err); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form class="hl-auth-form" method="post" action="" id="hl-profile-form"
                  data-user-id="<?php echo esc_attr($user_id); ?>">
                <?php wp_nonce_field('hl_profile_setup_action', 'hl_profile_nonce'); ?>
                <input type="hidden" name="hl_auth_action" value="profile_setup">
                <input type="hidden" name="hl_session_token" value="<?php echo esc_attr($session_token); ?>">

                <!-- ============================================ -->
                <!-- Step 1: Personal Information                  -->
                <!-- ============================================ -->
                <div class="hl-step-panel" data-step="1" role="tabpanel">

                    <!-- First Name + Last Name: 2-column grid -->
                    <div class="hl-field-row hl-field-row--2col">
                        <div class="hl-auth-field">
                            <label for="hl-first-name"><?php esc_html_e('First Name', 'hl-core'); ?> <span class="hl-required">*</span></label>
                            <input type="text" id="hl-first-name" name="hl_first_name"
                                   autocomplete="given-name" required
                                   value="<?php echo esc_attr($user->first_name); ?>">
                        </div>
                        <div class="hl-auth-field">
                            <label for="hl-last-name"><?php esc_html_e('Last Name', 'hl-core'); ?> <span class="hl-required">*</span></label>
                            <input type="text" id="hl-last-name" name="hl_last_name"
                                   autocomplete="family-name" required
                                   value="<?php echo esc_attr($user->last_name); ?>">
                        </div>
                    </div>

                    <!-- Email: read-only -->
                    <div class="hl-auth-field">
                        <label for="hl-email"><?php esc_html_e('Email', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <input type="email" id="hl-email" name="hl_email" readonly
                               class="hl-input--readonly"
                               value="<?php echo esc_attr($user->user_email); ?>">
                    </div>

                    <!-- Nickname -->
                    <div class="hl-auth-field">
                        <label for="hl-nickname"><?php esc_html_e('Nickname', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <input type="text" id="hl-nickname" name="hl_nickname" required
                               placeholder="<?php esc_attr_e('What should we call you?', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->nickname ?? ''); ?>">
                    </div>

                    <!-- Phone Number (OPTIONAL) -->
                    <div class="hl-auth-field">
                        <label for="hl-phone-number"><?php esc_html_e('Phone Number', 'hl-core'); ?> <span class="hl-optional">(<?php esc_html_e('optional', 'hl-core'); ?>)</span></label>
                        <div class="hl-phone-group" role="group" aria-label="<?php esc_attr_e('Phone number', 'hl-core'); ?>">
                            <select name="hl_phone_country_code" id="hl-phone-cc"
                                    autocomplete="tel-country-code" class="hl-phone-cc">
                                <option value="+1" <?php selected($profile->phone_country_code ?? '+1', '+1'); ?>>+1 (US/CA)</option>
                                <option value="+52" <?php selected($profile->phone_country_code ?? '', '+52'); ?>>+52 (MX)</option>
                                <option value="+55" <?php selected($profile->phone_country_code ?? '', '+55'); ?>>+55 (BR)</option>
                            </select>
                            <input type="tel" id="hl-phone-number" name="hl_phone_number"
                                   autocomplete="tel-national"
                                   placeholder="<?php esc_attr_e('(555) 123-4567', 'hl-core'); ?>"
                                   value="<?php echo esc_attr($profile->phone_number ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Gender (radio buttons) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Gender', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::GENDER_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_gender" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->gender ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Ethnicity (pill checkboxes, multi-select) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Ethnicity (select all that apply)', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-pill-check-group">
                            <?php
                            $selected_eth = !empty($profile->ethnicity) ? json_decode($profile->ethnicity, true) : array();
                            foreach (self::ETHNICITY_OPTIONS as $value => $label) :
                            ?>
                                <label class="hl-pill-check">
                                    <input type="checkbox" name="hl_ethnicity[]" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(in_array($value, $selected_eth, true)); ?>>
                                    <span class="hl-pill-check__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Location (dropdown: 50 states + international) -->
                    <div class="hl-auth-field">
                        <label for="hl-location"><?php esc_html_e('Location', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <select name="hl_location_state" id="hl-location" required>
                            <option value=""><?php esc_html_e('-- Select your location --', 'hl-core'); ?></option>
                            <?php
                            $separator_printed = false;
                            foreach (self::LOCATION_OPTIONS as $code => $name) :
                                // Print separator before international options
                                if (!$separator_printed && substr($code, 0, 2) === '--') :
                                    $separator_printed = true;
                                    ?>
                                    <option disabled value="">&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;</option>
                                <?php endif; ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($profile->location_state ?? '', $code); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Age Range (radio buttons) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Age', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::AGE_RANGE_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_age_range" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->age_range ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Preferred Course Language (radio buttons) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Preferred Course Language', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::LANGUAGE_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_preferred_language" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->preferred_language ?? 'en'), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="button" class="hl-auth-btn hl-step-next" data-next="2">
                        <span class="hl-auth-btn-text"><?php esc_html_e('Continue', 'hl-core'); ?></span>
                    </button>
                </div>

                <!-- ============================================ -->
                <!-- Step 2: Professional Information              -->
                <!-- ============================================ -->
                <div class="hl-step-panel" data-step="2" style="display:none;" role="tabpanel">

                    <!-- Years of Experience in Industry (radio buttons) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Years of Experience in Industry', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::YEARS_EXP_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_years_exp_industry" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->years_exp_industry ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Years of Experience in Current Position (radio buttons, SEPARATE field) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Years of Experience in Current Position', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::YEARS_EXP_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_years_exp_position" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->years_exp_position ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Job Title (OPTIONAL) -->
                    <div class="hl-auth-field">
                        <label for="hl-job-title"><?php esc_html_e('Job Title', 'hl-core'); ?> <span class="hl-optional">(<?php esc_html_e('optional', 'hl-core'); ?>)</span></label>
                        <input type="text" id="hl-job-title" name="hl_job_title"
                               autocomplete="organization-title"
                               placeholder="<?php esc_attr_e('e.g., Lead Pre-K Teacher', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->job_title ?? ''); ?>">
                    </div>

                    <div class="hl-step-nav">
                        <button type="button" class="hl-auth-btn hl-auth-btn--secondary hl-step-prev" data-prev="1">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Back', 'hl-core'); ?></span>
                        </button>
                        <button type="button" class="hl-auth-btn hl-step-next" data-next="3">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Continue', 'hl-core'); ?></span>
                        </button>
                    </div>
                </div>

                <!-- ============================================ -->
                <!-- Step 3: Social Media + Consent                -->
                <!-- ============================================ -->
                <div class="hl-step-panel" data-step="3" style="display:none;" role="tabpanel">

                    <p class="hl-step-intro"><?php esc_html_e('All social media fields are optional.', 'hl-core'); ?></p>

                    <!-- Instagram (@ prefix) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-instagram"><?php esc_html_e('Instagram', 'hl-core'); ?></label>
                        <div class="hl-input-prefix-group">
                            <span class="hl-input-prefix">@</span>
                            <input type="text" id="hl-social-instagram" name="hl_social_instagram"
                                   placeholder="<?php esc_attr_e('username', 'hl-core'); ?>"
                                   value="<?php echo esc_attr($profile->social_instagram ?? ''); ?>">
                        </div>
                    </div>

                    <!-- X / Twitter (@ prefix) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-twitter"><?php esc_html_e('X (Twitter)', 'hl-core'); ?></label>
                        <div class="hl-input-prefix-group">
                            <span class="hl-input-prefix">@</span>
                            <input type="text" id="hl-social-twitter" name="hl_social_twitter"
                                   placeholder="<?php esc_attr_e('username', 'hl-core'); ?>"
                                   value="<?php echo esc_attr($profile->social_twitter ?? ''); ?>">
                        </div>
                    </div>

                    <!-- LinkedIn (URL) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-linkedin"><?php esc_html_e('LinkedIn', 'hl-core'); ?></label>
                        <input type="url" id="hl-social-linkedin" name="hl_social_linkedin"
                               placeholder="<?php esc_attr_e('https://linkedin.com/in/yourprofile', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->social_linkedin ?? ''); ?>">
                    </div>

                    <!-- Facebook (URL) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-facebook"><?php esc_html_e('Facebook', 'hl-core'); ?></label>
                        <input type="url" id="hl-social-facebook" name="hl_social_facebook"
                               placeholder="<?php esc_attr_e('https://facebook.com/yourprofile', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->social_facebook ?? ''); ?>">
                    </div>

                    <!-- Website / URL -->
                    <div class="hl-auth-field">
                        <label for="hl-social-website"><?php esc_html_e('Website / URL', 'hl-core'); ?></label>
                        <input type="url" id="hl-social-website" name="hl_social_website"
                               placeholder="<?php esc_attr_e('https://yourwebsite.com', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->social_website ?? ''); ?>">
                    </div>

                    <!-- Consent (required) -->
                    <div class="hl-consent-box">
                        <h3><?php esc_html_e('Research Participation Consent', 'hl-core'); ?></h3>
                        <div class="hl-consent-text">
                            <p><?php esc_html_e('By checking this box, you acknowledge that you have read and agree to participate in the Housman Learning research study. Your data will be used for research purposes and handled in accordance with our privacy policy.', 'hl-core'); ?></p>
                        </div>
                        <label class="hl-consent-check">
                            <input type="checkbox" name="hl_consent" value="1" required>
                            <span><?php esc_html_e('I agree to the research participation terms', 'hl-core'); ?></span>
                        </label>
                    </div>

                    <div class="hl-step-nav">
                        <button type="button" class="hl-auth-btn hl-auth-btn--secondary hl-step-prev" data-prev="2">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Back', 'hl-core'); ?></span>
                        </button>
                        <button type="submit" class="hl-auth-btn" id="hl-profile-submit">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Complete Profile', 'hl-core'); ?></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
```

**Verify:** File exists. Confirm all 18 fields are present by searching the file:
- Step 1 (10 fields): `hl_first_name`, `hl_last_name`, `hl_email` (readonly), `hl_nickname`, `hl_phone_country_code`, `hl_phone_number`, `hl_gender`, `hl_ethnicity[]`, `hl_location_state`, `hl_age_range`, `hl_preferred_language`
- Step 2 (3 fields): `hl_years_exp_industry`, `hl_years_exp_position`, `hl_job_title`
- Step 3 (6 fields): `hl_social_instagram`, `hl_social_twitter`, `hl_social_linkedin`, `hl_social_facebook`, `hl_social_website`, `hl_consent`

**Commit:** `git add includes/frontend/class-hl-frontend-profile-setup.php && git commit -m "feat(auth): add Profile Setup shortcode renderer (3-step, 18 fields)"`

---

## Task 9: hl-auth.js (Step Navigation, localStorage, Submit Guard)

**Files:**
- CREATE: `assets/js/hl-auth.js`

**Steps:**

- [ ] 9a. Create `assets/js/hl-auth.js` with the following complete content:

```javascript
/**
 * HL Auth JS
 *
 * Handles: form submit guards (spec I23), multi-step navigation with
 * per-step validation, localStorage partial saves (spec I18),
 * step error indicators (spec I7).
 */
(function($) {
    'use strict';

    // --- Form Submit Guard (spec I23) ---
    // Generic guard for login + password reset forms (non-profile).
    $('form.hl-auth-form').not('#hl-profile-form').on('submit', function(e) {
        var $btn = $(this).find('button[type="submit"]');
        if ($btn.attr('aria-disabled') === 'true') {
            e.preventDefault();
            return false;
        }
        $btn.attr('aria-disabled', 'true').addClass('hl-auth-btn--submitting');
    });

    // PI6: Password strength meter reserved for Phase 2 custom reset form.
    // The actual password entry happens on wp-login.php?action=rp (WP core),
    // where we cannot inject JS. No-op for now.

    // --- Multi-Step Navigation ---
    var $form = $('#hl-profile-form');
    if ($form.length) {
        var userId = $form.data('user-id');
        var storageKey = 'hl_profile_draft_' + userId;

        // -------------------------------------------------------
        // Restore from localStorage (spec I18)
        // Handles text inputs, selects, radio buttons, checkboxes
        // -------------------------------------------------------
        try {
            var saved = JSON.parse(localStorage.getItem(storageKey));
            if (saved) {
                Object.keys(saved).forEach(function(name) {
                    var $fields = $form.find('[name="' + name + '"]');
                    if (!$fields.length) return;

                    if ($fields.first().is(':radio')) {
                        // Radio: check the one with the matching value
                        $fields.filter('[value="' + saved[name] + '"]').prop('checked', true);
                    } else if ($fields.first().is(':checkbox')) {
                        // Checkboxes (ethnicity): saved value is array
                        if (Array.isArray(saved[name])) {
                            saved[name].forEach(function(val) {
                                $form.find('[name="' + name + '"][value="' + val + '"]').prop('checked', true);
                            });
                        }
                    } else {
                        // Spec FC2: Always restore from localStorage if saved value exists.
                        // Readonly fields are excluded from the save logic.
                        $fields.val(saved[name]);
                    }
                });
            }
        } catch(e) { /* ignore parse errors */ }

        // -------------------------------------------------------
        // Save to localStorage on field change (spec I18)
        // Captures radios, checkboxes, selects, text, URL, tel
        // -------------------------------------------------------
        $form.on('change input', 'input, select, textarea', function() {
            var data = {};
            $form.find('input, select, textarea').each(function() {
                var $el = $(this);
                var name = $el.attr('name');
                if (!name) return;
                // Skip security fields
                if (name.indexOf('nonce') !== -1 || name.indexOf('token') !== -1 || name.indexOf('action') !== -1) return;
                // Skip read-only email
                if ($el.attr('readonly')) return;

                if ($el.is(':radio')) {
                    if ($el.is(':checked')) {
                        data[name] = $el.val();
                    }
                } else if ($el.is(':checkbox')) {
                    if (!data[name]) data[name] = [];
                    if ($el.is(':checked')) data[name].push($el.val());
                } else {
                    data[name] = $el.val();
                }
            });
            try { localStorage.setItem(storageKey, JSON.stringify(data)); } catch(e) {}
        });

        // Spec FC1: Profile form submit handler -- validates step 3 (consent) BEFORE spinner.
        $form.on('submit', function(e) {
            var $btn = $form.find('button[type="submit"]');
            if ($btn.attr('aria-disabled') === 'true') {
                e.preventDefault();
                return false;
            }

            // Validate step 3 (consent checkbox) before adding spinner
            var errors = validateStep(3);
            if (errors.length > 0) {
                var $panel = $form.find('.hl-step-panel[data-step="3"]');
                $panel.find('.hl-step-errors').remove();
                var html = '<div class="hl-step-errors hl-auth-error" role="alert"><span class="dashicons dashicons-warning"></span><div>';
                errors.forEach(function(msg) { html += '<div>' + msg + '</div>'; });
                html += '</div></div>';
                $panel.prepend(html);
                e.preventDefault();
                return false;
            }

            // Validation passed -- add spinner and clear localStorage
            $btn.attr('aria-disabled', 'true').addClass('hl-auth-btn--submitting');
            try { localStorage.removeItem(storageKey); } catch(ex) {}
        });

        // -------------------------------------------------------
        // Per-step validation rules
        // Returns array of error messages; empty = valid
        // -------------------------------------------------------
        function validateStep(step) {
            var errors = [];
            var $panel = $form.find('.hl-step-panel[data-step="' + step + '"]');

            // Clear previous error indicators
            $panel.find('.hl-field-error').removeClass('hl-field-error');

            if (step === 1) {
                if (!$panel.find('[name="hl_first_name"]').val().trim()) {
                    errors.push('First name is required.');
                    $panel.find('[name="hl_first_name"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_last_name"]').val().trim()) {
                    errors.push('Last name is required.');
                    $panel.find('[name="hl_last_name"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_nickname"]').val().trim()) {
                    errors.push('Nickname is required.');
                    $panel.find('[name="hl_nickname"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_gender"]:checked').length) {
                    errors.push('Please select a gender.');
                    $panel.find('[name="hl_gender"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_ethnicity[]"]:checked').length) {
                    errors.push('Please select at least one ethnicity option.');
                    $panel.find('[name="hl_ethnicity[]"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_location_state"]').val()) {
                    errors.push('Location is required.');
                    $panel.find('[name="hl_location_state"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_age_range"]:checked').length) {
                    errors.push('Please select an age range.');
                    $panel.find('[name="hl_age_range"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_preferred_language"]:checked').length) {
                    errors.push('Please select a preferred language.');
                    $panel.find('[name="hl_preferred_language"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                // Phone is OPTIONAL -- no validation
            }

            if (step === 2) {
                // Required: years_exp_industry, years_exp_position. Job title is OPTIONAL.
                if (!$panel.find('[name="hl_years_exp_industry"]:checked').length) {
                    errors.push('Years of experience in industry is required.');
                    $panel.find('[name="hl_years_exp_industry"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_years_exp_position"]:checked').length) {
                    errors.push('Years of experience in current position is required.');
                    $panel.find('[name="hl_years_exp_position"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
            }

            if (step === 3) {
                // Social media fields are ALL optional, but consent is required.
                if (!$panel.find('[name="hl_consent"]').is(':checked')) {
                    errors.push('You must agree to the research participation terms.');
                    $panel.find('.hl-consent-box').addClass('hl-field-error');
                }
            }

            return errors;
        }

        // -------------------------------------------------------
        // Step navigation
        // -------------------------------------------------------
        function showStep(step) {
            $form.find('.hl-step-panel').hide();
            $form.find('.hl-step-panel[data-step="' + step + '"]').show();

            // Update step indicators
            $form.closest('.hl-auth-card').find('.hl-steps__item').each(function() {
                var $item = $(this);
                var itemStep = parseInt($item.data('step'), 10);
                $item.removeClass('hl-steps__item--active hl-steps__item--complete hl-steps__item--error');

                if (itemStep === step) {
                    $item.addClass('hl-steps__item--active');
                    $item.attr('aria-selected', 'true');
                } else if (itemStep < step) {
                    // Check if step has errors (spec I7)
                    var $panel = $form.find('.hl-step-panel[data-step="' + itemStep + '"]');
                    if ($panel.find('.hl-field-error').length) {
                        $item.addClass('hl-steps__item--error');
                    } else {
                        $item.addClass('hl-steps__item--complete');
                    }
                    $item.attr('aria-selected', 'false');
                } else {
                    $item.attr('aria-selected', 'false');
                }
            });

            // Scroll to top of card
            $form.closest('.hl-auth-card')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // Next: validate current step before advancing
        $form.on('click', '.hl-step-next', function() {
            var currentStep = parseInt($(this).closest('.hl-step-panel').data('step'), 10);
            var next = parseInt($(this).data('next'), 10);

            var errors = validateStep(currentStep);
            if (errors.length > 0) {
                // Show inline error summary at top of current step
                var $panel = $form.find('.hl-step-panel[data-step="' + currentStep + '"]');
                $panel.find('.hl-step-errors').remove(); // clear previous
                var html = '<div class="hl-step-errors hl-auth-error" role="alert"><span class="dashicons dashicons-warning"></span><div>';
                errors.forEach(function(msg) { html += '<div>' + msg + '</div>'; });
                html += '</div></div>';
                $panel.prepend(html);
                return; // Don't advance
            }

            // Clear error summary if valid
            $form.find('.hl-step-panel[data-step="' + currentStep + '"] .hl-step-errors').remove();
            showStep(next);
        });

        $form.on('click', '.hl-step-prev', function() {
            var prev = parseInt($(this).data('prev'), 10);
            showStep(prev);
        });
    }

})(jQuery);
```

**Verify:** File exists at `assets/js/hl-auth.js`. Browser test after full wiring.

**Commit:** `git add assets/js/hl-auth.js && git commit -m "feat(auth): add hl-auth.js (step nav, localStorage save, submit guard)"`

---

## Task 10: Template Interception + File Loading + Shortcode Registration

**Files:**
- MODIFY: `includes/frontend/class-hl-shortcodes.php` (lines 72-78 for template, lines 176-211 for shortcodes)
- MODIFY: `hl-core.php` (lines 60-234 for require_once, lines 247-251 for password_reset_expiration, lines 256-292 for init)

**Steps:**

- [ ] 10a. In `includes/frontend/class-hl-shortcodes.php`, modify the `use_hl_template()` method (line 72). Add auth shortcode check BEFORE the existing `[hl_` check. Replace lines 72-78:

```php
    public function use_hl_template($template) {
        // HL shortcode pages — full plugin template takeover.
        if (is_singular('page')) {
            global $post;
            if (strpos($post->post_content, '[hl_') !== false) {
                return HL_CORE_PLUGIN_DIR . 'templates/hl-page.php';
            }
        }
```

With:

```php
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
```

- [ ] 10b. In `includes/frontend/class-hl-shortcodes.php`, add three shortcode registrations inside `register_shortcodes()` (after line 211, before the backward-compatible aliases section):

```php
        add_shortcode('hl_login',           array('HL_Frontend_Login', 'render'));
        add_shortcode('hl_password_reset',  array('HL_Frontend_Password_Reset', 'render'));
        add_shortcode('hl_profile_setup',   array('HL_Frontend_Profile_Setup', 'render'));
```

- [ ] 10c. In `hl-core.php`, add require_once lines for auth files inside `load_dependencies()`. Insert after line 130 (the `require_once` for `class-hl-ticket-service.php`):

```php
        // Auth system
        require_once HL_CORE_INCLUDES_DIR . 'auth/class-hl-auth-repository.php';
        require_once HL_CORE_INCLUDES_DIR . 'auth/class-hl-auth-service.php';
        require_once HL_CORE_INCLUDES_DIR . 'auth/class-hl-auth-manager.php';
```

And insert after line 209 (the `require_once` for `class-hl-frontend-feature-tracker.php`):

```php
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-login.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-password-reset.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-profile-setup.php';
```

- [ ] 10d. **(PI4: Already done in Task 3b.)** Verify that the `password_reset_expiration` anonymous filter has been removed from `hl-core.php` `init_hooks()`. If Task 3b was completed, this is a no-op confirmation step.

- [ ] 10e. In `hl-core.php`, add `HL_Auth_Manager::instance()` to the `init()` method. Insert after line 292 (the `HL_Frontend_Feature_Tracker::instance()` line):

```php
        // Initialize auth manager (registers hooks)
        HL_Auth_Manager::instance();
```

**Verify:**
```bash
# After deploying:
ssh test-server "cd /path/to/wp && wp eval 'echo shortcode_exists(\"hl_login\") ? \"yes\" : \"no\";'"
# Should output: yes

# Check hl-auth.php template is used for login page:
# Navigate to /login/ in browser -- should see full-bleed gradient background with centered card
```

**Commit:** `git add includes/frontend/class-hl-shortcodes.php hl-core.php && git commit -m "feat(auth): wire template interception, file loading, shortcode registration"`

---

## Task 11: CLI Page Definitions

**Files:**
- MODIFY: `includes/cli/class-hl-cli-create-pages.php` (lines 111-168, inside `get_page_definitions()`)

**Steps:**

- [ ] 11a. In `includes/cli/class-hl-cli-create-pages.php`, add 3 page definitions to the `get_page_definitions()` array. Insert after the Feature Tracker entry (line 166, before the closing `);`):

```php
            // Auth pages
            array( 'title' => 'Login',            'shortcode' => 'hl_login' ),
            array( 'title' => 'Password Reset',   'shortcode' => 'hl_password_reset' ),
            array( 'title' => 'Profile Setup',    'shortcode' => 'hl_profile_setup' ),
```

**Verify:**
```bash
# After deploying:
ssh test-server "cd /path/to/wp && wp hl create-pages"
# Should create 3 new pages (Login, Password Reset, Profile Setup) or skip if they exist

ssh test-server "cd /path/to/wp && wp post list --post_type=page --post_status=publish --fields=ID,post_title | grep -E 'Login|Password Reset|Profile Setup'"
# Should show the 3 pages
```

**Commit:** `git add includes/cli/class-hl-cli-create-pages.php && git commit -m "feat(auth): add Login, Password Reset, Profile Setup page definitions to CLI"`

---

## Task 12: Hardcoded URL Fixes

**Files:**
- MODIFY: `includes/admin/class-hl-admin-cycles.php` (lines 2372-2373, 2408-2409)
- MODIFY: `includes/cli/scripts/send-maria-email.php` (lines 14, 52)
- MODIFY: `includes/cli/scripts/send-test-emails-v2.php` (lines 8-9, 47)
- MODIFY: `includes/cli/scripts/send-test-emails.php` (lines 15-16, 97, 131)

**Steps:**

- [ ] 12a. In `includes/admin/class-hl-admin-cycles.php`, replace lines 2372-2373 (`build_email_existing` method):

Change:
```php
        $login_url = 'https://academy.housmanlearning.com/wp-login.php';
        $reset_url = 'https://academy.housmanlearning.com/wp-login.php?action=lostpassword';
```
To:
```php
        $login_url = wp_login_url();
        $reset_url = wp_lostpassword_url();
```

- [ ] 12b. In `includes/admin/class-hl-admin-cycles.php`, replace lines 2408-2409 (`build_email_new` method):

Change:
```php
        $invite_url = 'https://academy.housmanlearning.com/wp-login.php?action=rp&key=' . rawurlencode($reset_key) . '&login=' . rawurlencode($user_login);
        $reset_url  = 'https://academy.housmanlearning.com/wp-login.php?action=lostpassword';
```
To:
```php
        $invite_url = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($reset_key) . '&login=' . rawurlencode($user_login), 'login');
        $reset_url  = wp_lostpassword_url();
```

Note: The invite URL must still point to `wp-login.php?action=rp` because WP core validates the reset key there. This action is in our allowlist (spec I9).

- [ ] 12c. In `includes/cli/scripts/send-maria-email.php`, replace the hardcoded URL on line 14:

Change:
```php
$reset_url = 'https://academy.housmanlearning.com/wp-login.php?action=rp&key=' . $reset_key . '&login=' . rawurlencode($maria->user_login);
```
To:
```php
$reset_url = network_site_url('wp-login.php?action=rp&key=' . $reset_key . '&login=' . rawurlencode($maria->user_login), 'login');
```

And on line 52, replace:
```php
<a href="https://academy.housmanlearning.com/wp-login.php?action=lostpassword"
```
With:
```php
<a href="' . esc_url(wp_lostpassword_url()) . '"
```

- [ ] 12d. In `includes/cli/scripts/send-test-emails-v2.php`, replace lines 8-9:

Change:
```php
$login_url  = 'https://academy.housmanlearning.com/wp-login.php';
$reset_page = 'https://academy.housmanlearning.com/wp-login.php?action=lostpassword';
```
To:
```php
$login_url  = wp_login_url();
$reset_page = wp_lostpassword_url();
```

And on line 47, replace the hardcoded invite URL with `network_site_url()` equivalent.

- [ ] 12e. In `includes/cli/scripts/send-test-emails.php`, replace lines 15-16:

Change:
```php
$login_url  = 'https://academy.housmanlearning.com/wp-login.php';
$reset_page = 'https://academy.housmanlearning.com/wp-login.php?action=lostpassword';
```
To:
```php
$login_url  = wp_login_url();
$reset_page = wp_lostpassword_url();
```

And replace the hardcoded URLs on lines 97 and 131 with their `wp_lostpassword_url()` / `network_site_url()` equivalents.

**Verify:**
```bash
# After deploying, grep for any remaining hardcoded wp-login.php URLs:
ssh test-server "cd /path/to/wp-content/plugins/hl-core && grep -rn 'academy.housmanlearning.com/wp-login.php' includes/"
# Should return no results (only spec/docs files if anything)
```

**Commit:** `git add includes/admin/class-hl-admin-cycles.php includes/cli/scripts/ && git commit -m "fix(auth): replace hardcoded wp-login.php URLs with wp_login_url/wp_lostpassword_url"`

---

## Task 13: BB Integration Decoupling (Transition)

**Files:**
- MODIFY: `includes/integrations/class-hl-buddyboss-integration.php` (line 57)

**Steps:**

- [ ] 13a. In `includes/integrations/class-hl-buddyboss-integration.php`, the `hl_login_redirect` filter is registered at line 57 inside the `if (!$this->is_active()) { return; }` guard. This means if BB is deactivated, the redirect stops working. Since `HL_Auth_Manager` now registers its own `login_redirect` filter at priority 1000 (PC3: higher than BB's 999), and `HL_Auth_Manager` loads unconditionally, the BB version is harmlessly overridden. No code change needed in this file.

No code change needed in this task. The `HL_Auth_Manager::filter_login_redirect()` (registered in Task 3) handles this case. When BB is fully removed, the BB class's `hl_login_redirect` method and its filter registration will be deleted as part of the BB detachment project.

**Verify:**
```bash
# After deploying, verify the redirect works with BB active:
# 1. Log in as an enrolled user -- should redirect to HL Dashboard
# 2. Log in as a coach-only user -- should redirect to Coach Dashboard
# 3. Log in as admin -- should redirect to wp-admin
```

**Commit:** No commit needed for this task (no code change).

---

## Self-Review Audit

### Field Audit (18 fields)

| # | Field | DB Schema (Task 1) | Form HTML (Task 8) | POST Handler (Task 3) | Validation (Task 3) | is_complete (Task 1) |
|---|---|---|---|---|---|---|
| 1 | First Name * | wp_users | Step 1 `hl_first_name` | `$first_name` | required | `$user->first_name` |
| 2 | Last Name * | wp_users | Step 1 `hl_last_name` | `$last_name` | required | `$user->last_name` |
| 3 | Email * | wp_users | Step 1 readonly | (not submitted) | N/A | N/A |
| 4 | Nickname * | `nickname` | Step 1 `hl_nickname` | `$nickname` | required | `$row->nickname` |
| 5 | Phone CC | `phone_country_code` | Step 1 select | `$phone_cc` | allowlist | optional |
| 6 | Phone Number | `phone_number` | Step 1 tel | `$phone_num` | sanitize | optional |
| 7 | Gender * | `gender` | Step 1 radios | `$gender` | GENDER allowlist | `$row->gender` |
| 8 | Ethnicity * | `ethnicity` TEXT | Step 1 checkboxes | `$ethnicity` | ETH allowlist (I13) | JSON decode |
| 9 | Location * | `location_state` | Step 1 select | `$location` | LOC allowlist | `$row->location_state` |
| 10 | Age * | `age_range` | Step 1 radios | `$age_range` | AGE allowlist | `$row->age_range` |
| 11 | Language * | `preferred_language` | Step 1 radios | `$language` | LANG allowlist | `$row->preferred_language` |
| 12 | Years Exp Industry * | `years_exp_industry` | Step 2 radios | `$years_industry` | YEARS allowlist | `$row->years_exp_industry` |
| 13 | Years Exp Position * | `years_exp_position` | Step 2 radios | `$years_position` | YEARS allowlist | `$row->years_exp_position` |
| 14 | Job Title | `job_title` | Step 2 text | `$job_title` | sanitize | optional |
| 15 | Instagram | `social_instagram` | Step 3 @prefix | `$social_instagram` | ltrim @ | optional |
| 16 | Twitter | `social_twitter` | Step 3 @prefix | `$social_twitter` | ltrim @ | optional |
| 17 | LinkedIn | `social_linkedin` | Step 3 URL | `$social_linkedin` | esc_url_raw | optional |
| 18 | Facebook | `social_facebook` | Step 3 URL | `$social_facebook` | esc_url_raw | optional |
| 19 | Website | `social_website` | Step 3 URL | `$social_website` | esc_url_raw | optional |
| 20 | Consent * | `consent_given_at` | Step 3 checkbox | `$consent` (FI5) | required | `$row->consent_given_at` |

**All 18 user-facing fields plus consent are present in all 5 columns. Zero gaps.**

### File Audit

| File | Task | Action |
|---|---|---|
| `includes/auth/class-hl-auth-repository.php` | 1 | CREATE |
| `includes/auth/class-hl-auth-service.php` | 2 | CREATE |
| `includes/auth/class-hl-auth-manager.php` | 3 | CREATE |
| `includes/frontend/class-hl-frontend-login.php` | 6 | CREATE |
| `includes/frontend/class-hl-frontend-password-reset.php` | 7 | CREATE |
| `includes/frontend/class-hl-frontend-profile-setup.php` | 8 | CREATE |
| `templates/hl-auth.php` | 4 | CREATE |
| `assets/js/hl-auth.js` | 9 | CREATE |
| `includes/class-hl-installer.php` | 1 | MODIFY |
| `includes/frontend/class-hl-shortcodes.php` | 10 | MODIFY |
| `hl-core.php` | 10 | MODIFY |
| `includes/cli/class-hl-cli-create-pages.php` | 11 | MODIFY |
| `assets/css/frontend.css` | 5 | MODIFY |
| `includes/admin/class-hl-admin-cycles.php` | 12 | MODIFY |
| `includes/cli/scripts/send-maria-email.php` | 12 | MODIFY |
| `includes/cli/scripts/send-test-emails-v2.php` | 12 | MODIFY |
| `includes/cli/scripts/send-test-emails.php` | 12 | MODIFY |

**All 8 new files and 9 modified files are accounted for.**

### Spec Coverage

- Section A (Architecture): Tasks 1-3, 10
- Section B (DB Schema): Task 1
- Section C (Login Page): Tasks 3, 4, 6
- Section D (Password Reset): Tasks 3, 7
- Section E (Profile Setup): Tasks 3, 8
- Section F (Profile Gate): Task 3 (handle_auth_redirects + enforce_profile_gate_admin)
- Section G (Security): Task 2 (rate limiting, client IP, is_complete)
- Section H (Build Sequence): All tasks follow this order
- Appendix A (CSS Fix): Task 5
- Appendix B (JS): Task 9
- Appendix C (Email URLs): Task 12
- Appendix D (Shortcode Reg): Task 10
- Appendix E (File Loading): Task 10
- Appendix F (URL Filters): Task 3

**All spec sections covered. No placeholders found.**

### Review Fixes Applied

| ID | Type | Fix Summary |
|---|---|---|
| PC1 | CRITICAL | `resolve_post_login_redirect()`: wrapped BB call in `class_exists()` guard with direct DB fallback |
| PC2 | CRITICAL | Removed `wp_safe_redirect()/exit` from `render()` in Profile Setup; moved redirects to `handle_auth_redirects()` |
| PC3 | CRITICAL | Changed `login_redirect` filter priority from 999 to 1000 to override BB |
| PI1 | IMPORTANT | Replaced `wp_print_styles/scripts` with `wp_head()/wp_footer()` + `wp_enqueue_script()` in `hl-auth.php` |
| PI2 | IMPORTANT | Added closing `]` to `find_shortcode_page_url()` LIKE pattern |
| PI3 | IMPORTANT | Added `$redirect_to` param to `resolve_post_login_redirect()` and wired through `filter_login_redirect()` + login POST handler |
| PI4 | IMPORTANT | Moved `password_reset_expiration` removal from Task 10d to Task 3b (same commit as replacement) |
| PI5 | IMPORTANT | Added `@media (max-width: 480px)` rules for step labels and nav buttons |
| PI6 | IMPORTANT | Removed password strength meter JS (targets non-existent IDs); reserved for Phase 2 |
| PI7 | IMPORTANT | Added `sanitize_text_field()` to all cookie reads in Login and Profile Setup renderers |
| Q1a | QA | Added `wp_enqueue_style('dashicons')` to `hl-auth.php` (needed for error alert icons on logged-out pages) |
| Q1b | QA | Added deployment note: Tasks 1-11 are a single deployable unit (cross-task class references) |
| Q1c | QA | Updated JSDoc comment and CSS comment to reflect PI6 password meter removal |
| Q1d | QA | Login POST handler now honours `redirect_to` query param via `$_GET['redirect_to']` |
