<?php
if (!defined('ABSPATH')) exit;

/**
 * Zoom Server-to-Server OAuth Integration
 *
 * Meeting CRUD operations via Zoom API v2.
 * Uses wp_remote_* for HTTP, WordPress transients for token caching.
 *
 * @package HL_Core
 */
class HL_Zoom_Integration {

    private static $instance = null;

    const API_BASE  = 'https://api.zoom.us/v2';
    const TOKEN_URL = 'https://zoom.us/oauth/token';
    const TRANSIENT = 'hl_zoom_token';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // =========================================================================
    // Configuration
    // =========================================================================

    /**
     * Check if Zoom credentials are configured.
     *
     * @return bool
     */
    public function is_configured() {
        $settings = HL_Admin_Scheduling_Settings::get_zoom_settings();
        return !empty($settings['account_id'])
            && !empty($settings['client_id'])
            && !empty($settings['client_secret']);
    }

    /**
     * Resolve a coach's Zoom email address.
     *
     * @param int $coach_user_id
     * @return string
     */
    public function get_coach_email($coach_user_id) {
        $override = get_user_meta($coach_user_id, 'hl_zoom_email', true);
        if (!empty($override)) {
            return $override;
        }
        $user = get_userdata($coach_user_id);
        return $user ? $user->user_email : '';
    }

    // =========================================================================
    // Token Management
    // =========================================================================

    /**
     * Get an OAuth2 access token using Server-to-Server account credentials.
     *
     * @param bool $force_refresh Skip cache and request a new token.
     * @return string|WP_Error
     */
    public function get_access_token($force_refresh = false) {
        if (!$force_refresh) {
            $cached = get_transient(self::TRANSIENT);
            if ($cached !== false) {
                return $cached;
            }
        }

        $settings = HL_Admin_Scheduling_Settings::get_zoom_settings();
        if (empty($settings['account_id']) || empty($settings['client_id']) || empty($settings['client_secret'])) {
            return new WP_Error('zoom_not_configured', __('Zoom credentials are not configured.', 'hl-core'));
        }

        $response = wp_remote_post(self::TOKEN_URL, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($settings['client_id'] . ':' . $settings['client_secret']),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'grant_type' => 'account_credentials',
                'account_id' => $settings['account_id'],
            ),
        ));

        if (is_wp_error($response)) {
            $this->log_error('zoom_token_error', $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['access_token'])) {
            $error_msg = isset($body['reason']) ? $body['reason'] : (isset($body['error']) ? $body['error'] : 'Unknown error (HTTP ' . $code . ')');
            $this->log_error('zoom_token_error', $error_msg);
            return new WP_Error('zoom_token_error', $error_msg);
        }

        $ttl = isset($body['expires_in']) ? (int) $body['expires_in'] - 300 : 3300;
        set_transient(self::TRANSIENT, $body['access_token'], max($ttl, 60));

        return $body['access_token'];
    }

    // =========================================================================
    // Meeting Operations
    // =========================================================================

    /**
     * Create a Zoom meeting for a host.
     *
     * @param string $host_email  Zoom user email.
     * @param array  $meeting_data Meeting payload.
     * @return array|WP_Error Meeting object (includes 'id', 'join_url').
     */
    public function create_meeting($host_email, $meeting_data) {
        $url = sprintf('%s/users/%s/meetings', self::API_BASE, rawurlencode($host_email));
        return $this->api_request('POST', $url, $meeting_data);
    }

    /**
     * Update an existing Zoom meeting.
     *
     * @param int|string $meeting_id Zoom meeting ID.
     * @param array      $meeting_data Fields to update.
     * @return true|WP_Error
     */
    public function update_meeting($meeting_id, $meeting_data) {
        $url    = sprintf('%s/meetings/%s', self::API_BASE, rawurlencode((string) $meeting_id));
        $result = $this->api_request('PATCH', $url, $meeting_data);
        if (is_wp_error($result)) {
            return $result;
        }
        return true;
    }

    /**
     * Delete a Zoom meeting.
     *
     * @param int|string $meeting_id Zoom meeting ID.
     * @return true|WP_Error
     */
    public function delete_meeting($meeting_id) {
        $url    = sprintf('%s/meetings/%s', self::API_BASE, rawurlencode((string) $meeting_id));
        $result = $this->api_request('DELETE', $url);
        if (is_wp_error($result)) {
            return $result;
        }
        return true;
    }

    // =========================================================================
    // Meeting Payload Builder
    // =========================================================================

    /**
     * Build a Zoom meeting payload from session data + resolved coach settings.
     *
     * If `$resolved_settings` is empty, falls back to:
     *   1. `HL_Coach_Zoom_Settings_Service::get_admin_defaults()` when the service
     *      class is available, OR
     *   2. Hardcoded safe defaults when the service isn't loaded (boot-safe for
     *      early callers / tests).
     *
     * The `password` key is always omitted from the payload:
     *   - `password_required=1` → omit so Zoom auto-generates a passcode.
     *   - `password_required=0` → omit; account-level passcode policy may still apply.
     *
     * `auto_recording` / `auto_start_meeting_summary` are intentionally NOT set by
     * this builder — per spec, those are not coach-controlled.
     *
     * @param array $session_data {
     *     @type string $mentor_name
     *     @type string $coach_name
     *     @type string $start_datetime  e.g. "2026-03-30T09:00:00"
     *     @type string $timezone        IANA timezone, e.g. "America/New_York"
     *     @type int    $duration        Duration in minutes.
     * }
     * @param array $resolved_settings Pre-resolved coach settings from
     *                                 HL_Coach_Zoom_Settings_Service::resolve_for_coach().
     *                                 Pass array() (the default) to trigger fallback.
     * @return array
     */
    public function build_meeting_payload($session_data, array $resolved_settings = array()) {
        if (empty($resolved_settings)) {
            // Boot-safe fallback (handles early callers / tests that don't pass the arg).
            if (class_exists('HL_Coach_Zoom_Settings_Service')) {
                $resolved_settings = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
            } else {
                $resolved_settings = array(
                    'waiting_room'           => 1,
                    'mute_upon_entry'        => 0,
                    'join_before_host'       => 0,
                    'alternative_hosts'      => '',
                    'password_required'      => 0,
                    'meeting_authentication' => 0,
                );
            }
        }

        $payload = array(
            'topic'      => sprintf('Coaching Session - %s/%s', $session_data['mentor_name'], $session_data['coach_name']),
            'type'       => 2, // Scheduled meeting.
            'start_time' => $session_data['start_datetime'],
            'timezone'   => $session_data['timezone'],
            'duration'   => isset($session_data['duration']) ? (int) $session_data['duration'] : 30,
            'settings'   => array(
                'waiting_room'           => (bool) $resolved_settings['waiting_room'],
                'join_before_host'       => (bool) $resolved_settings['join_before_host'],
                'mute_upon_entry'        => (bool) $resolved_settings['mute_upon_entry'],
                'meeting_authentication' => (bool) $resolved_settings['meeting_authentication'],
            ),
        );

        if (isset($resolved_settings['alternative_hosts']) && $resolved_settings['alternative_hosts'] !== '') {
            $payload['settings']['alternative_hosts'] = $resolved_settings['alternative_hosts'];
        }

        // password key omitted in BOTH cases:
        //   password_required=1 → omit so Zoom auto-generates one.
        //   password_required=0 → omit; account-level passcode policy may still apply.

        return $payload;
    }

    // =========================================================================
    // HTTP Helper
    // =========================================================================

    /**
     * Make an authenticated API request to Zoom.
     *
     * @param string     $method HTTP method.
     * @param string     $url    Full Zoom API URL.
     * @param array|null $body   JSON body (for POST/PATCH).
     * @return array|WP_Error Decoded JSON response or WP_Error.
     */
    private function api_request($method, $url, $body = null, $is_retry = false) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $args = array(
            'method'  => $method,
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log_error('zoom_api_error', $response->get_error_message(), array('url' => $url, 'method' => $method));
            return $response;
        }

        $code          = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // 401: token may have been revoked server-side — force-refresh and retry once.
        if ($code === 401 && !$is_retry) {
            delete_transient(self::TRANSIENT);
            return $this->api_request($method, $url, $body, true);
        }

        // DELETE returns 204, PATCH returns 204 on success.
        if (in_array($code, array(204), true)) {
            return array();
        }

        $decoded = json_decode($response_body, true);

        if ($code >= 400) {
            $error_msg = isset($decoded['message']) ? $decoded['message'] : 'HTTP ' . $code;
            $this->log_error('zoom_api_error', $error_msg, array(
                'url'    => $url,
                'method' => $method,
                'code'   => $code,
            ));
            return new WP_Error('zoom_api_error', $error_msg);
        }

        return $decoded ? $decoded : array();
    }

    // =========================================================================
    // Logging
    // =========================================================================

    /**
     * Log a Zoom API error via the audit service.
     *
     * @param string $type    Error type identifier.
     * @param string $message Error message.
     * @param array  $context Additional context data.
     */
    private function log_error($type, $message, $context = array()) {
        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log($type, array(
                'entity_type' => 'integration',
                'reason'      => 'Zoom: ' . $message,
                'after_data'  => $context,
            ));
        }
    }
}
