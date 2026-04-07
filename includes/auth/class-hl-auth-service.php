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
     * @param WP_User $user        The logged-in user.
     * @param string  $redirect_to Optional redirect URL from login form.
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
