<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Email Templates
 *
 * Allows admins to edit coaching session email subjects and body copy
 * from WP Admin. Templates stored in wp_options with merge tag support.
 * The branded HTML wrapper (header, logo, footer) stays in code.
 *
 * @package HL_Core
 */
class HL_Admin_Email_Templates {

    private static $instance = null;

    /** Option key for stored templates. */
    const OPTION_KEY = 'hl_email_templates';

    /**
     * Template definitions: key => meta.
     * Each key corresponds to one email (recipient + event).
     */
    const TEMPLATE_DEFS = array(
        'session_booked_mentor' => array(
            'label'    => 'Session Booked — Mentor',
            'group'    => 'Session Booked',
            'tags'     => array('mentor_name', 'coach_name', 'session_date', 'zoom_link'),
        ),
        'session_booked_coach' => array(
            'label'    => 'Session Booked — Coach',
            'group'    => 'Session Booked',
            'tags'     => array('mentor_name', 'coach_name', 'session_date', 'zoom_link'),
        ),
        'session_rescheduled_mentor' => array(
            'label'    => 'Session Rescheduled — Mentor',
            'group'    => 'Session Rescheduled',
            'tags'     => array('mentor_name', 'coach_name', 'old_session_date', 'new_session_date', 'zoom_link'),
        ),
        'session_rescheduled_coach' => array(
            'label'    => 'Session Rescheduled — Coach',
            'group'    => 'Session Rescheduled',
            'tags'     => array('mentor_name', 'coach_name', 'old_session_date', 'new_session_date', 'zoom_link'),
        ),
        'session_cancelled_mentor' => array(
            'label'    => 'Session Cancelled — Mentor',
            'group'    => 'Session Cancelled',
            'tags'     => array('mentor_name', 'coach_name', 'session_date', 'cancelled_by'),
        ),
        'session_cancelled_coach' => array(
            'label'    => 'Session Cancelled — Coach',
            'group'    => 'Session Cancelled',
            'tags'     => array('mentor_name', 'coach_name', 'session_date', 'cancelled_by'),
        ),
    );

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_hl_send_test_email', array($this, 'ajax_send_test_email'));
        add_action('wp_ajax_hl_reset_email_template', array($this, 'ajax_reset_template'));
    }

    // =========================================================================
    // Defaults (current hardcoded text)
    // =========================================================================

    /**
     * Get default templates — mirrors the current hardcoded email copy.
     *
     * @return array Keyed by template slug => array( subject, body ).
     */
    public static function get_defaults() {
        return array(
            'session_booked_mentor' => array(
                'subject' => 'Your Coaching Session Has Been Scheduled',
                'body'    => 'Your coaching session with <strong>{{coach_name}}</strong> has been scheduled.',
            ),
            'session_booked_coach' => array(
                'subject' => 'Coaching Session Scheduled',
                'body'    => 'A coaching session has been scheduled with <strong>{{mentor_name}}</strong>.',
            ),
            'session_rescheduled_mentor' => array(
                'subject' => 'Your Coaching Session Has Been Rescheduled',
                'body'    => 'Your coaching session has been rescheduled from <strong>{{old_session_date}}</strong> to <strong>{{new_session_date}}</strong>.',
            ),
            'session_rescheduled_coach' => array(
                'subject' => 'Coaching Session Rescheduled',
                'body'    => 'A coaching session with <strong>{{mentor_name}}</strong> has been rescheduled from <strong>{{old_session_date}}</strong> to <strong>{{new_session_date}}</strong>.',
            ),
            'session_cancelled_mentor' => array(
                'subject' => 'Your Coaching Session Has Been Cancelled',
                'body'    => 'Your coaching session on <strong>{{session_date}}</strong> has been cancelled.<br>This session was cancelled by {{cancelled_by}}.',
            ),
            'session_cancelled_coach' => array(
                'subject' => 'Coaching Session Cancelled',
                'body'    => 'The coaching session with <strong>{{mentor_name}}</strong> on <strong>{{session_date}}</strong> has been cancelled.<br>This session was cancelled by {{cancelled_by}}.',
            ),
        );
    }

    // =========================================================================
    // Public Getters (used by HL_Scheduling_Email_Service)
    // =========================================================================

    /**
     * Get a single template (stored value merged over defaults).
     *
     * @param string $key Template slug.
     * @return array { subject: string, body: string }
     */
    public static function get_template($key) {
        $defaults = self::get_defaults();
        $default  = isset($defaults[$key]) ? $defaults[$key] : array('subject' => '', 'body' => '');
        $stored   = get_option(self::OPTION_KEY, array());
        if (is_string($stored)) {
            $stored = json_decode($stored, true) ?: array();
        }
        $tpl = isset($stored[$key]) ? $stored[$key] : array();
        return wp_parse_args($tpl, $default);
    }

    /**
     * Replace merge tags in a string.
     *
     * @param string $text   Template text with {{tag}} placeholders.
     * @param array  $values Keyed by tag name (without braces).
     * @return string
     */
    public static function merge($text, $values) {
        foreach ($values as $tag => $val) {
            $text = str_replace('{{' . $tag . '}}', $val, $text);
        }
        return $text;
    }

    // =========================================================================
    // Save Handler
    // =========================================================================

    /**
     * Process POST form submission.
     */
    public function handle_save() {
        if (!current_user_can('manage_hl_core')) {
            return;
        }
        if (!wp_verify_nonce($_POST['hl_email_templates_nonce'], 'hl_email_templates')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        $templates = array();
        foreach (array_keys(self::TEMPLATE_DEFS) as $key) {
            $subj = isset($_POST['tpl_' . $key . '_subject'])
                ? sanitize_text_field($_POST['tpl_' . $key . '_subject'])
                : '';
            $body = isset($_POST['tpl_' . $key . '_body'])
                ? wp_kses_post($_POST['tpl_' . $key . '_body'])
                : '';
            if (!empty($subj) || !empty($body)) {
                $templates[$key] = array(
                    'subject' => $subj,
                    'body'    => $body,
                );
            }
        }

        update_option(self::OPTION_KEY, wp_json_encode($templates));
        add_settings_error('hl_email_templates', 'templates_saved', __('Email templates saved.', 'hl-core'), 'success');
    }

    // =========================================================================
    // AJAX: Send Test Email
    // =========================================================================

    /**
     * Send a test email using the current saved template.
     */
    public function ajax_send_test_email() {
        check_ajax_referer('hl_email_templates_nonce', '_nonce');
        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $key = sanitize_text_field($_POST['template_key'] ?? '');
        if (!isset(self::TEMPLATE_DEFS[$key])) {
            wp_send_json_error(array('message' => __('Invalid template.', 'hl-core')));
        }

        $tpl  = self::get_template($key);
        $user = wp_get_current_user();
        $now  = date_i18n('l, F j, Y \a\t g:i A T');

        // Fill merge tags with sample data.
        $sample = array(
            'mentor_name'      => 'Jane Smith (Sample Mentor)',
            'coach_name'       => 'Dr. Sarah Johnson (Sample Coach)',
            'session_date'     => $now,
            'old_session_date' => $now,
            'new_session_date' => date_i18n('l, F j, Y \a\t g:i A T', strtotime('+7 days')),
            'zoom_link'        => 'https://zoom.us/j/1234567890',
            'cancelled_by'     => 'Dr. Sarah Johnson (Sample Coach)',
        );

        $subject = self::merge($tpl['subject'], $sample) . ' [TEST]';
        $body    = self::merge($tpl['body'], $sample);

        // Use the email service's branded wrapper.
        $email_service = HL_Scheduling_Email_Service::instance();
        $greeting      = sprintf('Hello %s,', esc_html($user->display_name));
        $html_body     = $email_service->build_branded_body($greeting, $body, $now, 'https://zoom.us/j/1234567890');

        $headers = array('Content-Type: text/html; charset=UTF-8');
        $result  = wp_mail($user->user_email, $subject, $html_body, $headers);

        if ($result) {
            wp_send_json_success(array(
                'message' => sprintf(__('Test email sent to %s', 'hl-core'), $user->user_email),
            ));
        } else {
            wp_send_json_error(array('message' => __('wp_mail failed. Check your mail configuration.', 'hl-core')));
        }
    }

    /**
     * Reset a single template to defaults via AJAX.
     */
    public function ajax_reset_template() {
        check_ajax_referer('hl_email_templates_nonce', '_nonce');
        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $key = sanitize_text_field($_POST['template_key'] ?? '');
        if (!isset(self::TEMPLATE_DEFS[$key])) {
            wp_send_json_error(array('message' => __('Invalid template.', 'hl-core')));
        }

        $stored = get_option(self::OPTION_KEY, array());
        if (is_string($stored)) {
            $stored = json_decode($stored, true) ?: array();
        }
        unset($stored[$key]);
        update_option(self::OPTION_KEY, wp_json_encode($stored));

        $defaults = self::get_defaults();
        wp_send_json_success(array(
            'message' => __('Template reset to default.', 'hl-core'),
            'subject' => $defaults[$key]['subject'],
            'body'    => $defaults[$key]['body'],
        ));
    }

    // =========================================================================
    // Render
    // =========================================================================

    /**
     * Render the Email Templates settings page content.
     */
    public function render_page_content() {
        $nonce = wp_create_nonce('hl_email_templates_nonce');
        $defaults = self::get_defaults();

        settings_errors('hl_email_templates');
        ?>
        <form method="post">
            <?php wp_nonce_field('hl_email_templates', 'hl_email_templates_nonce'); ?>

            <p class="description" style="margin-bottom:20px;">
                <?php esc_html_e('Edit the email notifications sent when coaching sessions are booked, rescheduled, or cancelled. The branded header, logo, session time block, Zoom button, and footer are automatic — you only edit the subject and message body.', 'hl-core'); ?>
            </p>

            <!-- Merge Tags Reference -->
            <div class="hl-settings-card" style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:16px 24px;margin-bottom:24px;">
                <h3 style="margin-top:0;font-size:15px;color:#1e3a5f;">
                    <span class="dashicons dashicons-shortcode" style="margin-right:4px;color:#4a90d9;"></span>
                    <?php esc_html_e('Available Merge Tags', 'hl-core'); ?>
                </h3>
                <div style="display:flex;flex-wrap:wrap;gap:8px 24px;font-size:13px;">
                    <code style="background:#e8f0fe;padding:2px 8px;border-radius:4px;">{{mentor_name}}</code>
                    <code style="background:#e8f0fe;padding:2px 8px;border-radius:4px;">{{coach_name}}</code>
                    <code style="background:#e8f0fe;padding:2px 8px;border-radius:4px;">{{session_date}}</code>
                    <code style="background:#e8f0fe;padding:2px 8px;border-radius:4px;">{{old_session_date}}</code>
                    <code style="background:#e8f0fe;padding:2px 8px;border-radius:4px;">{{new_session_date}}</code>
                    <code style="background:#e8f0fe;padding:2px 8px;border-radius:4px;">{{cancelled_by}}</code>
                </div>
                <p class="description" style="margin:8px 0 0;">
                    <?php esc_html_e('Tags are replaced with real values at send time. You can use basic HTML (<strong>, <br>, <em>) in the body.', 'hl-core'); ?>
                </p>
            </div>

            <?php
            $current_group = '';
            foreach (self::TEMPLATE_DEFS as $key => $def) :
                $tpl = self::get_template($key);

                // Group heading.
                if ($def['group'] !== $current_group) :
                    $current_group = $def['group'];
                    $icon = 'dashicons-email-alt';
                    if (strpos($key, 'rescheduled') !== false) $icon = 'dashicons-update';
                    if (strpos($key, 'cancelled') !== false)   $icon = 'dashicons-dismiss';
                    ?>
                    <h2 style="margin:32px 0 8px;font-size:17px;color:#1e3a5f;border-bottom:2px solid #e2e8f0;padding-bottom:8px;">
                        <span class="dashicons <?php echo esc_attr($icon); ?>" style="margin-right:4px;color:#4a90d9;"></span>
                        <?php echo esc_html($current_group); ?>
                    </h2>
                <?php endif; ?>

                <div class="hl-settings-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px 24px;margin-bottom:16px;" id="card-<?php echo esc_attr($key); ?>">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <h3 style="margin:0;font-size:14px;color:#374151;">
                            <?php echo esc_html($def['label']); ?>
                        </h3>
                        <span style="display:flex;gap:6px;">
                            <button type="button" class="button button-small hl-test-email-btn" data-key="<?php echo esc_attr($key); ?>">
                                <span class="dashicons dashicons-email" style="font-size:14px;width:14px;height:14px;margin-top:3px;margin-right:2px;"></span>
                                <?php esc_html_e('Send Test', 'hl-core'); ?>
                            </button>
                            <button type="button" class="button button-small button-link-delete hl-reset-tpl-btn" data-key="<?php echo esc_attr($key); ?>">
                                <?php esc_html_e('Reset', 'hl-core'); ?>
                            </button>
                        </span>
                    </div>

                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th scope="row" style="width:80px;padding:8px 10px 8px 0;"><label><?php esc_html_e('Subject', 'hl-core'); ?></label></th>
                            <td style="padding:8px 0;">
                                <input type="text"
                                       name="tpl_<?php echo esc_attr($key); ?>_subject"
                                       id="tpl_<?php echo esc_attr($key); ?>_subject"
                                       value="<?php echo esc_attr($tpl['subject']); ?>"
                                       class="large-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding:8px 10px 8px 0;"><label><?php esc_html_e('Body', 'hl-core'); ?></label></th>
                            <td style="padding:8px 0;">
                                <textarea name="tpl_<?php echo esc_attr($key); ?>_body"
                                          id="tpl_<?php echo esc_attr($key); ?>_body"
                                          rows="3"
                                          class="large-text"
                                          style="font-family:monospace;font-size:13px;"><?php echo esc_textarea($tpl['body']); ?></textarea>
                            </td>
                        </tr>
                    </table>
                </div>
            <?php endforeach; ?>

            <?php submit_button(__('Save Templates', 'hl-core')); ?>
        </form>

        <!-- AJAX for Send Test / Reset -->
        <script>
        (function() {
            var nonce = '<?php echo esc_js($nonce); ?>';

            // Send Test Email.
            document.querySelectorAll('.hl-test-email-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var key = this.dataset.key;
                    var origText = this.innerHTML;
                    this.disabled = true;
                    this.textContent = '<?php echo esc_js(__('Sending...', 'hl-core')); ?>';

                    var fd = new FormData();
                    fd.append('action', 'hl_send_test_email');
                    fd.append('_nonce', nonce);
                    fd.append('template_key', key);

                    fetch(ajaxurl, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            alert(data.success ? data.data.message : (data.data.message || 'Error'));
                            btn.disabled = false;
                            btn.innerHTML = origText;
                        })
                        .catch(function() {
                            alert('Request failed');
                            btn.disabled = false;
                            btn.innerHTML = origText;
                        });
                });
            });

            // Reset to Default.
            document.querySelectorAll('.hl-reset-tpl-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('<?php echo esc_js(__('Reset this template to the default text?', 'hl-core')); ?>')) return;
                    var key = this.dataset.key;

                    var fd = new FormData();
                    fd.append('action', 'hl_reset_email_template');
                    fd.append('_nonce', nonce);
                    fd.append('template_key', key);

                    fetch(ajaxurl, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                document.getElementById('tpl_' + key + '_subject').value = data.data.subject;
                                document.getElementById('tpl_' + key + '_body').value = data.data.body;
                            }
                            alert(data.success ? data.data.message : (data.data.message || 'Error'));
                        })
                        .catch(function() { alert('Request failed'); });
                });
            });
        })();
        </script>
        <?php
    }
}
