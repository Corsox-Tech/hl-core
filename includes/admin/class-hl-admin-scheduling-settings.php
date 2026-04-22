<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Scheduling & Integrations Settings
 *
 * Manages scheduling rules, Microsoft 365 credentials, and Zoom credentials.
 * Secrets encrypted with AES-256-CBC using AUTH_KEY salt.
 *
 * @package HL_Core
 */
class HL_Admin_Scheduling_Settings {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_hl_test_microsoft_connection', array($this, 'ajax_test_microsoft_connection'));
        add_action('wp_ajax_hl_test_zoom_connection', array($this, 'ajax_test_zoom_connection'));
    }

    // =========================================================================
    // Settings Getters
    // =========================================================================

    /**
     * Get scheduling rules with defaults merged.
     *
     * @return array
     */
    public static function get_scheduling_settings() {
        $defaults = array(
            'session_duration'        => 30,
            'min_lead_time_hours'     => 24,
            'max_lead_time_days'      => 30,
            'min_cancel_notice_hours' => 24,
        );
        $stored = get_option('hl_scheduling_settings', array());
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        return wp_parse_args($stored ? $stored : array(), $defaults);
    }

    /**
     * Get Microsoft 365 settings with decrypted secrets.
     *
     * @return array
     */
    public static function get_microsoft_settings() {
        $stored = get_option('hl_microsoft_graph_settings', array());
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        $settings = wp_parse_args($stored ? $stored : array(), array(
            'tenant_id'     => '',
            'client_id'     => '',
            'client_secret' => '',
        ));
        if (!empty($settings['client_secret'])) {
            $settings['client_secret'] = self::decrypt_value($settings['client_secret']);
        }
        return $settings;
    }

    /**
     * Get Zoom settings with decrypted secrets.
     *
     * @return array
     */
    public static function get_zoom_settings() {
        $stored = get_option('hl_zoom_settings', array());
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        $settings = wp_parse_args($stored ? $stored : array(), array(
            'account_id'    => '',
            'client_id'     => '',
            'client_secret' => '',
        ));
        if (!empty($settings['client_secret'])) {
            $settings['client_secret'] = self::decrypt_value($settings['client_secret']);
        }
        return $settings;
    }

    // =========================================================================
    // Encryption
    // =========================================================================

    /**
     * Encrypt a value using AES-256-CBC with random IV.
     *
     * @param string $plaintext
     * @return string Base64-encoded IV + ciphertext.
     */
    public static function encrypt_value($plaintext) {
        if (empty($plaintext)) {
            return '';
        }
        $key       = substr(hash('sha256', AUTH_KEY), 0, 32);
        $iv        = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a value encrypted with encrypt_value().
     *
     * @param string $ciphertext Base64-encoded IV + ciphertext.
     * @return string
     */
    public static function decrypt_value($ciphertext) {
        if (empty($ciphertext)) {
            return '';
        }
        $key       = substr(hash('sha256', AUTH_KEY), 0, 32);
        $data      = base64_decode($ciphertext);
        if ($data === false || strlen($data) < 17) {
            return '';
        }
        $iv        = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    // =========================================================================
    // Save Handler
    // =========================================================================

    /**
     * Process POST form submission. Called from handle_early_actions().
     */
    public function handle_save() {
        if (!current_user_can('manage_hl_core')) {
            return;
        }
        if (!wp_verify_nonce($_POST['hl_scheduling_settings_nonce'], 'hl_scheduling_settings')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        // Scheduling rules.
        $scheduling = array(
            'session_duration'        => absint($_POST['session_duration'] ?? 30),
            'min_lead_time_hours'     => absint($_POST['min_lead_time_hours'] ?? 24),
            'max_lead_time_days'      => absint($_POST['max_lead_time_days'] ?? 30),
            'min_cancel_notice_hours' => absint($_POST['min_cancel_notice_hours'] ?? 24),
        );
        update_option('hl_scheduling_settings', wp_json_encode($scheduling));

        // Microsoft 365 settings.
        $ms_current = get_option('hl_microsoft_graph_settings', array());
        if (is_string($ms_current)) {
            $ms_current = json_decode($ms_current, true) ?: array();
        }
        $ms_settings = array(
            'tenant_id' => sanitize_text_field($_POST['ms_tenant_id'] ?? ''),
            'client_id' => sanitize_text_field($_POST['ms_client_id'] ?? ''),
        );
        // Only update secret if a new value was provided.
        $ms_secret = $_POST['ms_client_secret'] ?? '';
        if (!empty($ms_secret)) {
            $ms_settings['client_secret'] = self::encrypt_value($ms_secret);
        } else {
            $ms_settings['client_secret'] = $ms_current['client_secret'] ?? '';
        }
        update_option('hl_microsoft_graph_settings', wp_json_encode($ms_settings));

        // Zoom settings.
        $zoom_current = get_option('hl_zoom_settings', array());
        if (is_string($zoom_current)) {
            $zoom_current = json_decode($zoom_current, true) ?: array();
        }
        $zoom_settings = array(
            'account_id' => sanitize_text_field($_POST['zoom_account_id'] ?? ''),
            'client_id'  => sanitize_text_field($_POST['zoom_client_id'] ?? ''),
        );
        $zoom_secret = $_POST['zoom_client_secret'] ?? '';
        if (!empty($zoom_secret)) {
            $zoom_settings['client_secret'] = self::encrypt_value($zoom_secret);
        } else {
            $zoom_settings['client_secret'] = $zoom_current['client_secret'] ?? '';
        }
        update_option('hl_zoom_settings', wp_json_encode($zoom_settings));

        add_settings_error('hl_scheduling', 'settings_saved', __('Settings saved.', 'hl-core'), 'success');

        // NEW: Coaching Session Defaults.
        $defaults_input = array(
            'waiting_room'           => isset( $_POST['hl_zoom_def_waiting_room'] )           ? 1 : 0,
            'mute_upon_entry'        => isset( $_POST['hl_zoom_def_mute_upon_entry'] )        ? 1 : 0,
            'join_before_host'       => isset( $_POST['hl_zoom_def_join_before_host'] )       ? 1 : 0,
            'password_required'      => isset( $_POST['hl_zoom_def_password_required'] )      ? 1 : 0,
            'meeting_authentication' => isset( $_POST['hl_zoom_def_meeting_authentication'] ) ? 1 : 0,
            'alternative_hosts'      => isset( $_POST['hl_zoom_def_alternative_hosts'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['hl_zoom_def_alternative_hosts'] ) )
                : '',
        );

        $r = HL_Coach_Zoom_Settings_Service::save_admin_defaults( $defaults_input, get_current_user_id() );
        if ( is_wp_error( $r ) ) {
            $msg = $r->get_error_message();
            if ( $r->get_error_code() === 'preflight_inflight' ) {
                $msg = __( 'Another administrator is currently saving these settings. Please retry in a moment.', 'hl-core' );
            }
            add_settings_error( 'hl_scheduling', 'zoom_defaults_save_failed', $msg, 'error' );
        } else {
            add_settings_error( 'hl_scheduling', 'zoom_defaults_saved', __( 'Coaching meeting defaults saved.', 'hl-core' ), 'updated' );
        }
    }

    // =========================================================================
    // AJAX: Test Connections
    // =========================================================================

    /**
     * Test Microsoft 365 connection by requesting an access token.
     */
    public function ajax_test_microsoft_connection() {
        check_ajax_referer('hl_scheduling_nonce', '_nonce');
        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $graph = HL_Microsoft_Graph::instance();
        if (!$graph->is_configured()) {
            wp_send_json_error(array('message' => __('Microsoft 365 credentials are not configured. Please save settings first.', 'hl-core')));
        }

        $token = $graph->get_access_token(true);
        if (is_wp_error($token)) {
            wp_send_json_error(array('message' => $token->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Connected successfully!', 'hl-core')));
    }

    /**
     * Test Zoom connection by requesting an access token.
     */
    public function ajax_test_zoom_connection() {
        check_ajax_referer('hl_scheduling_nonce', '_nonce');
        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $zoom = HL_Zoom_Integration::instance();
        if (!$zoom->is_configured()) {
            wp_send_json_error(array('message' => __('Zoom credentials are not configured. Please save settings first.', 'hl-core')));
        }

        $token = $zoom->get_access_token(true);
        if (is_wp_error($token)) {
            wp_send_json_error(array('message' => $token->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Connected successfully!', 'hl-core')));
    }

    // =========================================================================
    // Render
    // =========================================================================

    /**
     * Render the settings page content (called from HL_Admin_Settings).
     */
    public function render_page_content() {
        $scheduling = self::get_scheduling_settings();
        $ms         = self::get_microsoft_settings();
        $zoom       = self::get_zoom_settings();
        $nonce      = wp_create_nonce('hl_scheduling_nonce');

        settings_errors('hl_scheduling');
        ?>
        <form method="post">
            <?php wp_nonce_field('hl_scheduling_settings', 'hl_scheduling_settings_nonce'); ?>

            <!-- Scheduling Rules -->
            <div class="hl-settings-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;margin-bottom:24px;">
                <h2 style="margin-top:0;font-size:18px;color:#1e3a5f;">
                    <span class="dashicons dashicons-calendar-alt" style="margin-right:6px;color:#4a90d9;"></span>
                    <?php esc_html_e('Scheduling Rules', 'hl-core'); ?>
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="session_duration"><?php esc_html_e('Session Duration (minutes)', 'hl-core'); ?></label></th>
                        <td>
                            <input type="number" id="session_duration" name="session_duration" value="<?php echo esc_attr($scheduling['session_duration']); ?>" min="15" max="120" step="15" class="small-text">
                            <p class="description"><?php esc_html_e('Length of each coaching session.', 'hl-core'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="min_lead_time_hours"><?php esc_html_e('Min Booking Lead Time (hours)', 'hl-core'); ?></label></th>
                        <td>
                            <input type="number" id="min_lead_time_hours" name="min_lead_time_hours" value="<?php echo esc_attr($scheduling['min_lead_time_hours']); ?>" min="0" max="168" class="small-text">
                            <p class="description"><?php esc_html_e('Earliest a session can be booked before start time.', 'hl-core'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_lead_time_days"><?php esc_html_e('Max Booking Lead Time (days)', 'hl-core'); ?></label></th>
                        <td>
                            <input type="number" id="max_lead_time_days" name="max_lead_time_days" value="<?php echo esc_attr($scheduling['max_lead_time_days']); ?>" min="1" max="365" class="small-text">
                            <p class="description"><?php esc_html_e('Farthest in advance a session can be booked.', 'hl-core'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="min_cancel_notice_hours"><?php esc_html_e('Min Cancel/Reschedule Notice (hours)', 'hl-core'); ?></label></th>
                        <td>
                            <input type="number" id="min_cancel_notice_hours" name="min_cancel_notice_hours" value="<?php echo esc_attr($scheduling['min_cancel_notice_hours']); ?>" min="0" max="168" class="small-text">
                            <p class="description"><?php esc_html_e('Minimum notice required to cancel or reschedule.', 'hl-core'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Microsoft 365 Integration -->
            <div class="hl-settings-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;margin-bottom:24px;">
                <h2 style="margin-top:0;font-size:18px;color:#1e3a5f;">
                    <span class="dashicons dashicons-cloud" style="margin-right:6px;color:#0078d4;"></span>
                    <?php esc_html_e('Microsoft 365 Integration (Outlook Calendar)', 'hl-core'); ?>
                </h2>
                <p class="description" style="margin-bottom:16px;">
                    <?php esc_html_e('Connect to Microsoft Graph API for automatic Outlook calendar event creation. Requires an Azure AD app registration with Calendars.ReadWrite and User.Read.All application permissions.', 'hl-core'); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="ms_tenant_id"><?php esc_html_e('Tenant ID', 'hl-core'); ?></label></th>
                        <td><input type="text" id="ms_tenant_id" name="ms_tenant_id" value="<?php echo esc_attr($ms['tenant_id']); ?>" class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ms_client_id"><?php esc_html_e('Client ID', 'hl-core'); ?></label></th>
                        <td><input type="text" id="ms_client_id" name="ms_client_id" value="<?php echo esc_attr($ms['client_id']); ?>" class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ms_client_secret"><?php esc_html_e('Client Secret', 'hl-core'); ?></label></th>
                        <td>
                            <?php if (!empty($ms['client_secret'])): ?>
                                <input type="text" value="••••••••••••••••" disabled class="regular-text" style="background:#f7f7f7;">
                                <button type="button" class="button button-small" onclick="this.style.display='none';this.parentElement.querySelector('.hl-secret-input').style.display='inline-block';">
                                    <?php esc_html_e('Change', 'hl-core'); ?>
                                </button>
                                <span class="hl-secret-input" style="display:none;">
                                    <input type="password" id="ms_client_secret" name="ms_client_secret" value="" class="regular-text" placeholder="<?php esc_attr_e('Enter new secret', 'hl-core'); ?>">
                                </span>
                            <?php else: ?>
                                <input type="password" id="ms_client_secret" name="ms_client_secret" value="" class="regular-text">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Connection Status', 'hl-core'); ?></th>
                        <td>
                            <span id="hl-ms-status" class="hl-connection-badge" style="display:inline-block;padding:4px 12px;border-radius:12px;font-size:13px;background:#f0f0f0;color:#666;">
                                <?php esc_html_e('Not tested', 'hl-core'); ?>
                            </span>
                            <button type="button" id="hl-test-ms" class="button button-small" style="margin-left:8px;">
                                <?php esc_html_e('Test Connection', 'hl-core'); ?>
                            </button>
                        </td>
                    </tr>
                </table>

                <details style="margin-top:12px;padding:12px;background:#f8f9fa;border-radius:6px;">
                    <summary style="cursor:pointer;font-weight:600;color:#1e3a5f;"><?php esc_html_e('Setup Guide: Azure AD App Registration', 'hl-core'); ?></summary>
                    <ol style="margin-top:8px;line-height:1.8;">
                        <li><?php esc_html_e('Go to Azure Portal > Azure Active Directory > App Registrations > New Registration', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Name: "HLA Coaching Calendar Integration"', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Supported account types: "Accounts in this organizational directory only"', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Redirect URI: leave blank (not needed for client credentials)', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Copy the Application (client) ID and Directory (tenant) ID', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Go to Certificates & secrets > New client secret > Copy the Value', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Go to API permissions > Add Microsoft Graph > Application permissions', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Add: Calendars.ReadWrite and User.Read.All', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Click "Grant admin consent for [your org]"', 'hl-core'); ?></li>
                    </ol>
                </details>
            </div>

            <!-- Zoom Integration -->
            <div class="hl-settings-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;margin-bottom:24px;">
                <h2 style="margin-top:0;font-size:18px;color:#1e3a5f;">
                    <span class="dashicons dashicons-video-alt2" style="margin-right:6px;color:#2d8cff;"></span>
                    <?php esc_html_e('Zoom Integration (Meeting Auto-Creation)', 'hl-core'); ?>
                </h2>
                <p class="description" style="margin-bottom:16px;">
                    <?php esc_html_e('Connect to Zoom API for automatic meeting creation when sessions are booked. Requires a Server-to-Server OAuth app in the Zoom Marketplace.', 'hl-core'); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="zoom_account_id"><?php esc_html_e('Account ID', 'hl-core'); ?></label></th>
                        <td><input type="text" id="zoom_account_id" name="zoom_account_id" value="<?php echo esc_attr($zoom['account_id']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoom_client_id"><?php esc_html_e('Client ID', 'hl-core'); ?></label></th>
                        <td><input type="text" id="zoom_client_id" name="zoom_client_id" value="<?php echo esc_attr($zoom['client_id']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zoom_client_secret"><?php esc_html_e('Client Secret', 'hl-core'); ?></label></th>
                        <td>
                            <?php if (!empty($zoom['client_secret'])): ?>
                                <input type="text" value="••••••••••••••••" disabled class="regular-text" style="background:#f7f7f7;">
                                <button type="button" class="button button-small" onclick="this.style.display='none';this.parentElement.querySelector('.hl-secret-input').style.display='inline-block';">
                                    <?php esc_html_e('Change', 'hl-core'); ?>
                                </button>
                                <span class="hl-secret-input" style="display:none;">
                                    <input type="password" id="zoom_client_secret" name="zoom_client_secret" value="" class="regular-text" placeholder="<?php esc_attr_e('Enter new secret', 'hl-core'); ?>">
                                </span>
                            <?php else: ?>
                                <input type="password" id="zoom_client_secret" name="zoom_client_secret" value="" class="regular-text">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Connection Status', 'hl-core'); ?></th>
                        <td>
                            <span id="hl-zoom-status" class="hl-connection-badge" style="display:inline-block;padding:4px 12px;border-radius:12px;font-size:13px;background:#f0f0f0;color:#666;">
                                <?php esc_html_e('Not tested', 'hl-core'); ?>
                            </span>
                            <button type="button" id="hl-test-zoom" class="button button-small" style="margin-left:8px;">
                                <?php esc_html_e('Test Connection', 'hl-core'); ?>
                            </button>
                        </td>
                    </tr>
                </table>

                <details style="margin-top:12px;padding:12px;background:#f8f9fa;border-radius:6px;">
                    <summary style="cursor:pointer;font-weight:600;color:#1e3a5f;"><?php esc_html_e('Setup Guide: Zoom Server-to-Server OAuth App', 'hl-core'); ?></summary>
                    <ol style="margin-top:8px;line-height:1.8;">
                        <li><?php esc_html_e('Go to marketplace.zoom.us > Develop > Build App', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Choose "Server-to-Server OAuth"', 'hl-core'); ?></li>
                        <li><?php esc_html_e('App name: "HLA Coaching Scheduling"', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Copy the Account ID, Client ID, and Client Secret', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Go to Scopes > Add: meeting:write:admin', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Activate the app', 'hl-core'); ?></li>
                    </ol>
                </details>
            </div>

            <!-- Coaching Session Defaults -->
            <?php
            $defaults = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
            $zoom_creds = self::get_zoom_settings();
            $zoom_account_label = $zoom_creds['account_id']
                ? sprintf( __( 'Zoom Account ID: %s', 'hl-core' ), esc_html( $zoom_creds['account_id'] ) )
                : __( 'Zoom credentials not configured.', 'hl-core' );
            ?>
            <div class="hl-settings-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;margin-bottom:24px;">
                <h2 style="margin-top:0;font-size:18px;color:#1e3a5f;">
                    <span class="dashicons dashicons-admin-generic" style="margin-right:6px;color:#2d8cff;"></span>
                    <?php esc_html_e( 'Coaching Session Defaults', 'hl-core' ); ?>
                </h2>
                <p class="description" style="background:#f0f6fc;padding:12px 16px;border-left:4px solid #2271b1;border-radius:3px;">
                    <?php esc_html_e( 'Recording and AI Companion are configured in your Zoom account settings, not here.', 'hl-core' ); ?><br>
                    <small><?php echo esc_html( $zoom_account_label ); ?></small>
                </p>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="hl_zoom_def_waiting_room"><?php esc_html_e( 'Waiting room', 'hl-core' ); ?></label></th>
                            <td><label><input type="checkbox" id="hl_zoom_def_waiting_room" name="hl_zoom_def_waiting_room" value="1" <?php checked( ! empty( $defaults['waiting_room'] ) ); ?>>
                                <?php esc_html_e( 'Hold participants in a waiting room until admitted.', 'hl-core' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hl_zoom_def_mute_upon_entry"><?php esc_html_e( 'Mute upon entry', 'hl-core' ); ?></label></th>
                            <td><label><input type="checkbox" id="hl_zoom_def_mute_upon_entry" name="hl_zoom_def_mute_upon_entry" value="1" <?php checked( ! empty( $defaults['mute_upon_entry'] ) ); ?>>
                                <?php esc_html_e( 'Participants are muted when they join.', 'hl-core' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hl_zoom_def_join_before_host"><?php esc_html_e( 'Join before host', 'hl-core' ); ?></label></th>
                            <td><label><input type="checkbox" id="hl_zoom_def_join_before_host" name="hl_zoom_def_join_before_host" value="1" <?php checked( ! empty( $defaults['join_before_host'] ) ); ?>>
                                <?php esc_html_e( 'Participants can join before the host. (Auto-disabled when waiting room is on.)', 'hl-core' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hl_zoom_def_alternative_hosts"><?php esc_html_e( 'Alternative hosts', 'hl-core' ); ?></label></th>
                            <td>
                                <textarea id="hl_zoom_def_alternative_hosts" name="hl_zoom_def_alternative_hosts" rows="2" cols="50" maxlength="1024" class="large-text"><?php echo esc_textarea( $defaults['alternative_hosts'] ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Comma-separated emails. Each must be a Licensed user on the same Zoom account. Leave empty for no alternative hosts.', 'hl-core' ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <details style="margin-top:16px;border:1px solid #c3c4c7;border-radius:4px;padding:8px 16px;">
                    <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Advanced (account-policy interactions)', 'hl-core' ); ?></summary>
                    <p class="description" style="background:#fff7ed;padding:12px;border-left:4px solid #f97316;margin-top:12px;">
                        <?php esc_html_e( 'These settings can be silently overridden by your Zoom account-level policies. If they don\'t take effect, check Zoom admin first.', 'hl-core' ); ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="hl_zoom_def_password_required"><?php esc_html_e( 'Require passcode', 'hl-core' ); ?></label></th>
                            <td><label><input type="checkbox" id="hl_zoom_def_password_required" name="hl_zoom_def_password_required" value="1" <?php checked( ! empty( $defaults['password_required'] ) ); ?>>
                                <?php esc_html_e( 'Require a passcode to join meetings.', 'hl-core' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hl_zoom_def_meeting_authentication"><?php esc_html_e( 'Require Zoom sign-in', 'hl-core' ); ?></label></th>
                            <td><label><input type="checkbox" id="hl_zoom_def_meeting_authentication" name="hl_zoom_def_meeting_authentication" value="1" <?php checked( ! empty( $defaults['meeting_authentication'] ) ); ?>>
                                <?php esc_html_e( 'Participants must be signed in to a Zoom account.', 'hl-core' ); ?></label></td>
                        </tr>
                    </table>
                </details>
            </div>

            <?php submit_button(__('Save Settings', 'hl-core')); ?>
        </form>

        <?php $this->render_coach_overrides_table(); ?>

        <script>
        (function() {
            var nonce = '<?php echo esc_js($nonce); ?>';

            function testConnection(btnId, statusId, action) {
                var btn = document.getElementById(btnId);
                var badge = document.getElementById(statusId);
                btn.disabled = true;
                btn.textContent = '<?php echo esc_js(__('Testing...', 'hl-core')); ?>';
                badge.style.background = '#f0f0f0';
                badge.style.color = '#666';
                badge.textContent = '<?php echo esc_js(__('Testing...', 'hl-core')); ?>';

                fetch(ajaxurl + '?action=' + action + '&_nonce=' + nonce)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            badge.style.background = '#e6f4ea';
                            badge.style.color = '#137333';
                            badge.textContent = data.data.message;
                        } else {
                            badge.style.background = '#fce8e6';
                            badge.style.color = '#c5221f';
                            badge.textContent = data.data.message;
                        }
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js(__('Test Connection', 'hl-core')); ?>';
                    })
                    .catch(function() {
                        badge.style.background = '#fce8e6';
                        badge.style.color = '#c5221f';
                        badge.textContent = '<?php echo esc_js(__('Request failed', 'hl-core')); ?>';
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js(__('Test Connection', 'hl-core')); ?>';
                    });
            }

            document.getElementById('hl-test-ms').addEventListener('click', function() {
                testConnection('hl-test-ms', 'hl-ms-status', 'hl_test_microsoft_connection');
            });
            document.getElementById('hl-test-zoom').addEventListener('click', function() {
                testConnection('hl-test-zoom', 'hl-zoom-status', 'hl_test_zoom_connection');
            });
        })();
        </script>
        <?php
    }

    /**
     * Render the Coach Overrides Overview table.
     *
     * Admin-facing table listing every coach, with override status per Zoom
     * meeting option. Batched read (no N+1), paginated, with "overrides only"
     * filter and sticky header.
     */
    private function render_coach_overrides_table() {
        global $wpdb;

        $overrides_only = ! empty( $_GET['overrides_only'] );
        $page           = max( 1, absint( $_GET['paged_overrides'] ?? 1 ) );
        $per_page       = 50;
        $offset         = ( $page - 1 ) * $per_page;

        // Coach role slug confirmed at class-hl-installer.php:2348.
        $coach_ids = get_users( array(
            'role__in' => array( 'coach' ),
            'fields'   => 'ID',
            'orderby'  => 'display_name',
            'number'   => -1,
        ) );

        if ( empty( $coach_ids ) ) {
            echo '<p>' . esc_html__( 'No coaches found.', 'hl-core' ) . '</p>';
            return;
        }

        // One batched read of override rows (no N+1) — prepare() with %d placeholders.
        $placeholders = implode( ',', array_fill( 0, count( $coach_ids ), '%d' ) );
        $sql          = $wpdb->prepare(
            "SELECT coach_user_id, waiting_room, mute_upon_entry, join_before_host, alternative_hosts, updated_at, updated_by_user_id
             FROM {$wpdb->prefix}hl_coach_zoom_settings WHERE coach_user_id IN ($placeholders)",
            $coach_ids
        );
        $rows = $wpdb->get_results( $sql, OBJECT_K );

        // Warm user cache for both coach IDs and editor IDs to avoid per-row queries.
        $editor_ids = array_filter( wp_list_pluck( $rows, 'updated_by_user_id' ) );
        cache_users( array_unique( array_merge( $coach_ids, $editor_ids ) ) );

        $defaults = HL_Coach_Zoom_Settings_Service::get_admin_defaults();

        $resolved_rows = array();
        foreach ( $coach_ids as $cid ) {
            $row          = $rows[ $cid ] ?? null;
            $has_override = $row && (
                $row->waiting_room      !== null ||
                $row->mute_upon_entry   !== null ||
                $row->join_before_host  !== null ||
                $row->alternative_hosts !== null
            );
            if ( $overrides_only && ! $has_override ) continue;

            $user   = get_userdata( $cid );
            $editor = ( $row && $row->updated_by_user_id ) ? get_userdata( $row->updated_by_user_id ) : null;

            $resolved_rows[] = array(
                'cid'              => $cid,
                'name'             => $user ? $user->display_name : '#' . $cid,
                'waiting_room'     => $row && $row->waiting_room      !== null ? array( 'val' => (int) $row->waiting_room,     'src' => 'override' ) : array( 'val' => $defaults['waiting_room'],     'src' => 'default' ),
                'mute_upon_entry'  => $row && $row->mute_upon_entry   !== null ? array( 'val' => (int) $row->mute_upon_entry,  'src' => 'override' ) : array( 'val' => $defaults['mute_upon_entry'],  'src' => 'default' ),
                'join_before_host' => $row && $row->join_before_host  !== null ? array( 'val' => (int) $row->join_before_host, 'src' => 'override' ) : array( 'val' => $defaults['join_before_host'], 'src' => 'default' ),
                'alt_hosts'        => $row && $row->alternative_hosts !== null ? array( 'val' => (string) $row->alternative_hosts, 'src' => 'override' ) : array( 'val' => $defaults['alternative_hosts'], 'src' => 'default' ),
                'updated_at'       => $row ? $row->updated_at : null,
                'editor'           => $editor ? $editor->display_name : null,
            );
        }

        $total = count( $resolved_rows );
        $rows  = array_slice( $resolved_rows, $offset, $per_page );

        ?>
        <div class="card" style="max-width:1100px;margin-top:24px;">
            <h2><?php esc_html_e( 'Coach Overrides Overview', 'hl-core' ); ?></h2>
            <form method="get" style="margin-bottom:12px;">
                <input type="hidden" name="page" value="<?php echo esc_attr( $_GET['page'] ?? 'hl-settings' ); ?>">
                <input type="hidden" name="tab"  value="<?php echo esc_attr( $_GET['tab']  ?? 'scheduling' ); ?>">
                <label><input type="checkbox" name="overrides_only" value="1" <?php checked( $overrides_only ); ?> onchange="this.form.submit()">
                    <?php esc_html_e( 'Show only coaches with overrides', 'hl-core' ); ?></label>
            </form>
            <div style="max-height:600px;overflow:auto;">
                <table class="wp-list-table widefat fixed striped" style="position:relative;">
                    <thead style="position:sticky;top:0;background:#f6f7f7;z-index:1;">
                        <tr>
                            <th><?php esc_html_e( 'Coach', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Waiting room', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Mute on entry', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Join before host', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Alternative hosts', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Last edited', 'hl-core' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $r ): ?>
                        <tr>
                            <td><?php echo esc_html( $r['name'] ); ?></td>
                            <td><?php echo esc_html( $r['waiting_room']['val'] ? 'On' : 'Off' ); ?> <em>(<?php echo esc_html( $r['waiting_room']['src'] ); ?>)</em></td>
                            <td><?php echo esc_html( $r['mute_upon_entry']['val'] ? 'On' : 'Off' ); ?> <em>(<?php echo esc_html( $r['mute_upon_entry']['src'] ); ?>)</em></td>
                            <td><?php echo esc_html( $r['join_before_host']['val'] ? 'On' : 'Off' ); ?> <em>(<?php echo esc_html( $r['join_before_host']['src'] ); ?>)</em></td>
                            <td><?php echo $r['alt_hosts']['val'] ? esc_html( $r['alt_hosts']['val'] ) : '<em>(none)</em>'; ?> <em>(<?php echo esc_html( $r['alt_hosts']['src'] ); ?>)</em></td>
                            <td><?php echo $r['editor'] ? esc_html( $r['editor'] . ' on ' . $r['updated_at'] ) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            echo paginate_links( array(
                'base'    => add_query_arg( 'paged_overrides', '%#%' ),
                'format'  => '',
                'current' => $page,
                'total'   => max( 1, ceil( $total / $per_page ) ),
            ) );
            ?>
        </div>
        <?php
    }
}
