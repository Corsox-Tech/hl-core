<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Email Templates
 *
 * Rich-text editor (TinyMCE via wp_editor) for coaching session email
 * templates. Custom toolbar buttons for merge tags, Housman logo, and
 * styled CTA buttons. Templates stored in wp_options.
 *
 * @package HL_Core
 */
class HL_Admin_Email_Templates {

    private static $instance = null;

    /** Option key for stored templates. */
    const OPTION_KEY = 'hl_email_templates';

    /** Housman Learning logo URL. */
    const LOGO_URL = 'https://academy.housmanlearning.com/wp-content/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg';

    /**
     * Template definitions: key => meta.
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
    // Defaults
    // =========================================================================

    public static function get_defaults() {
        return array(
            'session_booked_mentor' => array(
                'subject' => 'Your Coaching Session Has Been Scheduled',
                'body'    => '<p>Your coaching session with <strong>{{coach_name}}</strong> has been scheduled.</p>',
            ),
            'session_booked_coach' => array(
                'subject' => 'Coaching Session Scheduled',
                'body'    => '<p>A coaching session has been scheduled with <strong>{{mentor_name}}</strong>.</p>',
            ),
            'session_rescheduled_mentor' => array(
                'subject' => 'Your Coaching Session Has Been Rescheduled',
                'body'    => '<p>Your coaching session has been rescheduled from <strong>{{old_session_date}}</strong> to <strong>{{new_session_date}}</strong>.</p>',
            ),
            'session_rescheduled_coach' => array(
                'subject' => 'Coaching Session Rescheduled',
                'body'    => '<p>A coaching session with <strong>{{mentor_name}}</strong> has been rescheduled from <strong>{{old_session_date}}</strong> to <strong>{{new_session_date}}</strong>.</p>',
            ),
            'session_cancelled_mentor' => array(
                'subject' => 'Your Coaching Session Has Been Cancelled',
                'body'    => '<p>Your coaching session on <strong>{{session_date}}</strong> has been cancelled.</p><p>This session was cancelled by {{cancelled_by}}.</p>',
            ),
            'session_cancelled_coach' => array(
                'subject' => 'Coaching Session Cancelled',
                'body'    => '<p>The coaching session with <strong>{{mentor_name}}</strong> on <strong>{{session_date}}</strong> has been cancelled.</p><p>This session was cancelled by {{cancelled_by}}.</p>',
            ),
        );
    }

    // =========================================================================
    // Public Getters (used by HL_Scheduling_Email_Service)
    // =========================================================================

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

    public static function merge($text, $values) {
        foreach ($values as $tag => $val) {
            $text = str_replace('{{' . $tag . '}}', $val, $text);
        }
        return $text;
    }

    // =========================================================================
    // Save Handler
    // =========================================================================

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
            // wp_editor uses the editor ID as the POST key.
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

    public function render_page_content() {
        $nonce    = wp_create_nonce('hl_email_templates_nonce');
        $logo_url = self::LOGO_URL;

        settings_errors('hl_email_templates');
        ?>
        <style>
            .hlet-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px 24px;margin-bottom:16px}
            .hlet-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
            .hlet-card-title{margin:0;font-size:14px;font-weight:600;color:#374151}
            .hlet-card-actions{display:flex;gap:6px}
            .hlet-subject-row{margin-bottom:14px}
            .hlet-subject-label{display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px}
            .hlet-subject-input{width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;color:#1e293b}
            .hlet-subject-input:focus{outline:none;border-color:#2d5f8a;box-shadow:0 0 0 2px rgba(45,95,138,.15)}
            .hlet-body-label{display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
            .hlet-group-heading{margin:28px 0 10px;font-size:17px;color:#1e3a5f;border-bottom:2px solid #e2e8f0;padding-bottom:8px}
            .hlet-group-heading .dashicons{margin-right:4px;color:#4a90d9}
            .hlet-tags-card{background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:16px 24px;margin-bottom:24px}
            .hlet-tags-card h3{margin-top:0;font-size:15px;color:#1e3a5f}
            .hlet-tag{display:inline-block;background:#e8f0fe;padding:3px 10px;border-radius:4px;font-size:13px;font-family:monospace;cursor:pointer;transition:background .15s}
            .hlet-tag:hover{background:#c8ddf8}
            .hlet-tags-list{display:flex;flex-wrap:wrap;gap:8px}
            /* Make wp_editor fit in cards */
            .hlet-card .wp-editor-wrap{border-radius:6px;overflow:hidden}
            .hlet-card .wp-editor-area{min-height:100px !important}
        </style>

        <form method="post">
            <?php wp_nonce_field('hl_email_templates', 'hl_email_templates_nonce'); ?>

            <p class="description" style="margin-bottom:20px;">
                <?php esc_html_e('Edit coaching session email notifications with the rich-text editor. The branded header, session time block, Zoom button, and footer are added automatically around your content.', 'hl-core'); ?>
            </p>

            <!-- Merge Tags Reference -->
            <div class="hlet-tags-card">
                <h3>
                    <span class="dashicons dashicons-shortcode" style="margin-right:4px;color:#4a90d9;"></span>
                    <?php esc_html_e('Available Merge Tags', 'hl-core'); ?>
                    <small style="font-weight:400;color:#64748b;font-size:12px;margin-left:8px;"><?php esc_html_e('(click to copy)', 'hl-core'); ?></small>
                </h3>
                <div class="hlet-tags-list">
                    <?php
                    $all_tags = array('mentor_name', 'coach_name', 'session_date', 'old_session_date', 'new_session_date', 'cancelled_by');
                    foreach ($all_tags as $tag) :
                    ?>
                        <code class="hlet-tag" data-tag="{{<?php echo esc_attr($tag); ?>}}" title="<?php esc_attr_e('Click to copy', 'hl-core'); ?>">{{<?php echo esc_html($tag); ?>}}</code>
                    <?php endforeach; ?>
                </div>
                <p class="description" style="margin:10px 0 0;">
                    <?php esc_html_e('Tags are replaced with real values when the email is sent. Use the "Insert Merge Tag" toolbar button in the editor or click a tag above to copy it.', 'hl-core'); ?>
                </p>
            </div>

            <?php
            $current_group = '';
            $editor_index = 0;
            foreach (self::TEMPLATE_DEFS as $key => $def) :
                $tpl = self::get_template($key);

                // Group heading.
                if ($def['group'] !== $current_group) :
                    $current_group = $def['group'];
                    $icon = 'dashicons-email-alt';
                    if (strpos($key, 'rescheduled') !== false) $icon = 'dashicons-update';
                    if (strpos($key, 'cancelled') !== false)   $icon = 'dashicons-dismiss';
                    ?>
                    <h2 class="hlet-group-heading">
                        <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                        <?php echo esc_html($current_group); ?>
                    </h2>
                <?php endif; ?>

                <div class="hlet-card" id="card-<?php echo esc_attr($key); ?>">
                    <div class="hlet-card-header">
                        <h3 class="hlet-card-title">
                            <?php echo esc_html($def['label']); ?>
                        </h3>
                        <div class="hlet-card-actions">
                            <button type="button" class="button button-small hl-test-email-btn" data-key="<?php echo esc_attr($key); ?>">
                                <span class="dashicons dashicons-email" style="font-size:14px;width:14px;height:14px;margin-top:3px;margin-right:2px;"></span>
                                <?php esc_html_e('Send Test', 'hl-core'); ?>
                            </button>
                            <button type="button" class="button button-small button-link-delete hl-reset-tpl-btn" data-key="<?php echo esc_attr($key); ?>">
                                <?php esc_html_e('Reset', 'hl-core'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="hlet-subject-row">
                        <label class="hlet-subject-label" for="tpl_<?php echo esc_attr($key); ?>_subject"><?php esc_html_e('Subject Line', 'hl-core'); ?></label>
                        <input type="text"
                               name="tpl_<?php echo esc_attr($key); ?>_subject"
                               id="tpl_<?php echo esc_attr($key); ?>_subject"
                               value="<?php echo esc_attr($tpl['subject']); ?>"
                               class="hlet-subject-input">
                    </div>

                    <div>
                        <label class="hlet-body-label"><?php esc_html_e('Email Body', 'hl-core'); ?></label>
                        <?php
                        $editor_id = 'tpl_' . $key . '_body';
                        wp_editor($tpl['body'], $editor_id, array(
                            'textarea_name' => $editor_id,
                            'textarea_rows' => 6,
                            'media_buttons' => false,
                            'teeny'         => false,
                            'quicktags'     => false,
                            'tinymce'       => array(
                                'toolbar1'       => 'formatselect,fontsizeselect,|,bold,italic,underline,strikethrough,|,forecolor,|,link,|,alignleft,aligncenter,alignright,|,bullist,numlist,|,hl_merge_tag,hl_logo,hl_button,|,removeformat',
                                'toolbar2'       => '',
                                'block_formats'  => 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4',
                                'fontsize_formats' => '12px 13px 14px 15px 16px 18px 20px 24px 28px 32px',
                                'content_style'  => 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 15px; color: #374151; line-height: 1.6; padding: 12px; }',
                                'setup'          => $this->get_tinymce_setup_js($key, $def['tags']),
                            ),
                        ));
                        ?>
                    </div>
                </div>
            <?php
                $editor_index++;
            endforeach;
            ?>

            <?php submit_button(__('Save Templates', 'hl-core')); ?>
        </form>

        <!-- AJAX for Send Test / Reset -->
        <script>
        (function() {
            var nonce = '<?php echo esc_js($nonce); ?>';

            // Click-to-copy merge tags.
            document.querySelectorAll('.hlet-tag').forEach(function(el) {
                el.addEventListener('click', function() {
                    var tag = this.dataset.tag;
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(tag).then(function() {
                            el.style.background = '#bbf7d0';
                            setTimeout(function(){ el.style.background = ''; }, 600);
                        });
                    }
                });
            });

            // Send Test Email.
            document.querySelectorAll('.hl-test-email-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var key = this.dataset.key;
                    var origHTML = this.innerHTML;
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
                            btn.innerHTML = origHTML;
                        })
                        .catch(function() {
                            alert('Request failed');
                            btn.disabled = false;
                            btn.innerHTML = origHTML;
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
                                // Update TinyMCE editor content.
                                var editorId = 'tpl_' + key + '_body';
                                if (typeof tinyMCE !== 'undefined' && tinyMCE.get(editorId)) {
                                    tinyMCE.get(editorId).setContent(data.data.body);
                                } else {
                                    var ta = document.getElementById(editorId);
                                    if (ta) ta.value = data.data.body;
                                }
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

    /**
     * Generate the TinyMCE setup JS callback for custom buttons.
     *
     * @param string $key  Template key.
     * @param array  $tags Available merge tags for this template.
     * @return string JS function body for TinyMCE setup parameter.
     */
    private function get_tinymce_setup_js($key, $tags) {
        $logo_url = esc_js(self::LOGO_URL);

        // Build merge tag menu items JS.
        $menu_items = array();
        foreach ($tags as $tag) {
            $label = str_replace('_', ' ', ucfirst($tag));
            $menu_items[] = sprintf(
                '{text:"%s",onclick:function(){ed.insertContent("{{%s}}")}}',
                esc_js($label),
                esc_js($tag)
            );
        }
        $menu_js = implode(',', $menu_items);

        return <<<JS
function(ed){
    // Merge Tag dropdown button.
    ed.addButton('hl_merge_tag',{
        text:'Merge Tag',
        icon:false,
        type:'menubutton',
        menu:[{$menu_js}]
    });

    // Insert Housman Logo button.
    ed.addButton('hl_logo',{
        text:'Logo',
        icon:false,
        tooltip:'Insert Housman Learning Logo',
        onclick:function(){
            ed.insertContent('<p style="text-align:center;margin:16px 0;"><img src="{$logo_url}" alt="Housman Learning" width="200" style="max-width:200px;height:auto;"></p>');
        }
    });

    // Insert styled CTA Button.
    ed.addButton('hl_button',{
        text:'Button',
        icon:false,
        tooltip:'Insert styled button/link',
        onclick:function(){
            ed.windowManager.open({
                title:'Insert Button',
                body:[
                    {type:'textbox',name:'btnText',label:'Button Text',value:'Click Here'},
                    {type:'textbox',name:'btnUrl',label:'URL',value:'https://'},
                    {type:'listbox',name:'btnColor',label:'Color',values:[
                        {text:'Blue (Primary)',value:'#2C7BE5'},
                        {text:'Dark Navy',value:'#1A2B47'},
                        {text:'Green',value:'#10B981'},
                        {text:'Zoom Blue',value:'#2d8cff'},
                        {text:'Red',value:'#EF4444'},
                        {text:'Orange',value:'#F59E0B'}
                    ]}
                ],
                onsubmit:function(e){
                    var c=e.data.btnColor||'#2C7BE5';
                    ed.insertContent(
                        '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:20px 0;"><tr><td align="center">'
                        +'<a href="'+e.data.btnUrl+'" style="display:inline-block;background:'+c+';color:#FFFFFF;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;">'
                        +e.data.btnText
                        +'</a></td></tr></table>'
                    );
                }
            });
        }
    });
}
JS;
    }
}
