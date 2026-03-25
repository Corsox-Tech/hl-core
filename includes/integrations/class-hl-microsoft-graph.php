<?php
if (!defined('ABSPATH')) exit;

/**
 * Microsoft Graph API Client
 *
 * Client credentials OAuth2 flow for calendar CRUD operations.
 * Uses wp_remote_* for HTTP, WordPress transients for token caching.
 *
 * @package HL_Core
 */
class HL_Microsoft_Graph {

    private static $instance = null;

    const API_BASE  = 'https://graph.microsoft.com/v1.0';
    const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    const TRANSIENT = 'hl_graph_token';

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
     * Check if Microsoft 365 credentials are configured.
     *
     * @return bool
     */
    public function is_configured() {
        $settings = HL_Admin_Scheduling_Settings::get_microsoft_settings();
        return !empty($settings['tenant_id'])
            && !empty($settings['client_id'])
            && !empty($settings['client_secret']);
    }

    /**
     * Resolve a coach's Microsoft 365 email address.
     *
     * @param int $coach_user_id
     * @return string
     */
    public function get_coach_email($coach_user_id) {
        $override = get_user_meta($coach_user_id, 'hl_microsoft_email', true);
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
     * Get an OAuth2 access token using client credentials flow.
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

        $settings = HL_Admin_Scheduling_Settings::get_microsoft_settings();
        if (empty($settings['tenant_id']) || empty($settings['client_id']) || empty($settings['client_secret'])) {
            return new WP_Error('graph_not_configured', __('Microsoft 365 credentials are not configured.', 'hl-core'));
        }

        $url      = sprintf(self::TOKEN_URL, $settings['tenant_id']);
        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'body'    => array(
                'grant_type'    => 'client_credentials',
                'client_id'     => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'scope'         => 'https://graph.microsoft.com/.default',
            ),
        ));

        if (is_wp_error($response)) {
            $this->log_error('graph_token_error', $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['access_token'])) {
            $error_msg = isset($body['error_description']) ? $body['error_description'] : 'Unknown error (HTTP ' . $code . ')';
            $this->log_error('graph_token_error', $error_msg);
            return new WP_Error('graph_token_error', $error_msg);
        }

        $ttl = isset($body['expires_in']) ? (int) $body['expires_in'] - 300 : 3300;
        set_transient(self::TRANSIENT, $body['access_token'], max($ttl, 60));

        return $body['access_token'];
    }

    // =========================================================================
    // Calendar Operations
    // =========================================================================

    /**
     * Get calendar events for a user within a time range.
     *
     * @param string $user_email  Microsoft 365 email.
     * @param string $start_datetime ISO 8601 UTC datetime.
     * @param string $end_datetime   ISO 8601 UTC datetime.
     * @return array|WP_Error Array of event objects.
     */
    public function get_calendar_events($user_email, $start_datetime, $end_datetime) {
        $url = sprintf(
            '%s/users/%s/calendar/calendarView?startDateTime=%s&endDateTime=%s&$top=100&$select=subject,start,end,showAs',
            self::API_BASE,
            rawurlencode($user_email),
            rawurlencode($start_datetime),
            rawurlencode($end_datetime)
        );

        $result = $this->api_request('GET', $url);
        if (is_wp_error($result)) {
            return $result;
        }

        return isset($result['value']) ? $result['value'] : array();
    }

    /**
     * Create a calendar event on a user's calendar.
     *
     * @param string $organizer_email Microsoft 365 email.
     * @param array  $event_data      Event payload.
     * @return array|WP_Error Created event (includes 'id').
     */
    public function create_calendar_event($organizer_email, $event_data) {
        $url = sprintf(
            '%s/users/%s/calendar/events',
            self::API_BASE,
            rawurlencode($organizer_email)
        );

        return $this->api_request('POST', $url, $event_data);
    }

    /**
     * Update an existing calendar event.
     *
     * @param string $organizer_email Microsoft 365 email.
     * @param string $event_id        Graph event ID.
     * @param array  $event_data      Fields to update.
     * @return array|WP_Error Updated event.
     */
    public function update_calendar_event($organizer_email, $event_id, $event_data) {
        $url = sprintf(
            '%s/users/%s/calendar/events/%s',
            self::API_BASE,
            rawurlencode($organizer_email),
            rawurlencode($event_id)
        );

        return $this->api_request('PATCH', $url, $event_data);
    }

    /**
     * Delete a calendar event.
     *
     * @param string $organizer_email Microsoft 365 email.
     * @param string $event_id        Graph event ID.
     * @return true|WP_Error
     */
    public function delete_calendar_event($organizer_email, $event_id) {
        $url = sprintf(
            '%s/users/%s/calendar/events/%s',
            self::API_BASE,
            rawurlencode($organizer_email),
            rawurlencode($event_id)
        );

        $result = $this->api_request('DELETE', $url);
        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    // =========================================================================
    // Event Payload Builder
    // =========================================================================

    /**
     * Build a Graph API calendar event payload from session data.
     *
     * @param array $session_data {
     *     @type string $mentor_name
     *     @type string $coach_name
     *     @type string $mentor_email
     *     @type string $start_datetime  e.g. "2026-03-30T09:00:00"
     *     @type string $end_datetime    e.g. "2026-03-30T09:30:00"
     *     @type string $timezone        IANA timezone, e.g. "America/New_York"
     *     @type string $meeting_url     Zoom join URL (optional).
     * }
     * @return array
     */
    public function build_event_payload($session_data) {
        $subject = sprintf('Coaching Session - %s/%s', $session_data['mentor_name'], $session_data['coach_name']);

        $body_parts = array('<p>Please use the link below to access your coaching session.</p>');
        $body_parts[] = sprintf('<p>Coach: %s<br>', esc_html($session_data['coach_name']));

        // Format date/time for display.
        try {
            $tz  = new DateTimeZone($session_data['timezone']);
            $dt  = new DateTime($session_data['start_datetime'], $tz);
            $body_parts[] = sprintf('Date: %s<br>', $dt->format('F j, Y'));
            $body_parts[] = sprintf('Time: %s</p>', $dt->format('g:i A T'));
        } catch (Exception $e) {
            $body_parts[] = sprintf('Date/Time: %s</p>', esc_html($session_data['start_datetime']));
        }

        if (!empty($session_data['meeting_url'])) {
            $body_parts[] = sprintf(
                '<p><a href="%s" style="display:inline-block;padding:10px 20px;background:#2d8cff;color:#fff;border-radius:5px;text-decoration:none;">Join Zoom Meeting</a></p>',
                esc_url($session_data['meeting_url'])
            );
        }

        $payload = array(
            'subject' => $subject,
            'start'   => array(
                'dateTime' => $session_data['start_datetime'],
                'timeZone' => $session_data['timezone'],
            ),
            'end' => array(
                'dateTime' => $session_data['end_datetime'],
                'timeZone' => $session_data['timezone'],
            ),
            'location' => array(
                'displayName' => 'Zoom Meeting',
            ),
            'body' => array(
                'contentType' => 'HTML',
                'content'     => implode("\n", $body_parts),
            ),
            'isOnlineMeeting' => false,
        );

        if (!empty($session_data['mentor_email'])) {
            $payload['attendees'] = array(
                array(
                    'emailAddress' => array(
                        'address' => $session_data['mentor_email'],
                    ),
                    'type' => 'required',
                ),
            );
        }

        return $payload;
    }

    // =========================================================================
    // HTTP Helper
    // =========================================================================

    /**
     * Make an authenticated API request to Microsoft Graph.
     *
     * @param string     $method  HTTP method (GET, POST, PATCH, DELETE).
     * @param string     $url     Full Graph API URL.
     * @param array|null $body    JSON body (for POST/PATCH).
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
            $this->log_error('graph_api_error', $response->get_error_message(), array('url' => $url, 'method' => $method));
            return $response;
        }

        $code         = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // 401: token may have been revoked server-side — force-refresh and retry once.
        if ($code === 401 && !$is_retry) {
            delete_transient(self::TRANSIENT);
            return $this->api_request($method, $url, $body, true);
        }

        // DELETE returns 204 No Content on success.
        if ($method === 'DELETE' && $code === 204) {
            return array();
        }

        $decoded = json_decode($response_body, true);

        if ($code >= 400) {
            $error_msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'HTTP ' . $code;
            $this->log_error('graph_api_error', $error_msg, array(
                'url'    => $url,
                'method' => $method,
                'code'   => $code,
            ));
            return new WP_Error('graph_api_error', $error_msg);
        }

        return $decoded ? $decoded : array();
    }

    // =========================================================================
    // Logging
    // =========================================================================

    /**
     * Log a Graph API error via the audit service.
     *
     * @param string $type    Error type identifier.
     * @param string $message Error message.
     * @param array  $context Additional context data.
     */
    private function log_error($type, $message, $context = array()) {
        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log($type, array(
                'entity_type' => 'integration',
                'reason'      => 'Microsoft Graph: ' . $message,
                'after_data'  => $context,
            ));
        }
    }
}
