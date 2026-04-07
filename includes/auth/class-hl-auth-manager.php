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
                if (HL_Auth_Service::is_profile_complete(get_current_user_id())) {
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
        wp_cache_set('profile_complete_' . $user_id, 1, 'hl_profiles', 3600);

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

        if (!is_user_logged_in()) {
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
                $custom_url = add_query_arg('redirect_to', $redirect, $custom_url);
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
