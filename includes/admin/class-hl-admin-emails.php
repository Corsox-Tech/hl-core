<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin Emails Page
 *
 * Four tabs: Automated Workflows, Email Templates, Send Log, Settings.
 *
 * @package HL_Core
 */
class HL_Admin_Emails {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_hl_email_workflow_save', array( $this, 'ajax_workflow_save' ) );
        add_action( 'wp_ajax_hl_email_workflow_delete', array( $this, 'ajax_workflow_delete' ) );
        add_action( 'wp_ajax_hl_email_retry_failed', array( $this, 'ajax_retry_failed' ) );
        add_action( 'wp_ajax_hl_email_cancel_queue', array( $this, 'ajax_cancel_queue' ) );
    }

    // =========================================================================
    // Page Render
    // =========================================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_die( 'Unauthorized' );
        }

        $tab = sanitize_text_field( $_GET['tab'] ?? 'workflows' );

        // Check if we're in the builder.
        if ( $tab === 'builder' ) {
            $template_id = (int) ( $_GET['template_id'] ?? 0 );
            HL_Admin_Email_Builder::instance()->render( $template_id ?: null );
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Emails', 'hl-core' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows' ) ); ?>" class="nav-tab <?php echo $tab === 'workflows' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Automated Workflows', 'hl-core' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=templates' ) ); ?>" class="nav-tab <?php echo $tab === 'templates' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Email Templates', 'hl-core' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=log' ) ); ?>" class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Send Log', 'hl-core' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=settings' ) ); ?>" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'hl-core' ); ?></a>
            </nav>

            <div class="hl-tab-content" style="padding-top:20px;">
                <?php
                switch ( $tab ) {
                    case 'workflows':
                        $this->render_workflows_tab();
                        break;
                    case 'templates':
                        $this->render_templates_tab();
                        break;
                    case 'log':
                        $this->render_log_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Tab: Automated Workflows
    // =========================================================================

    private function render_workflows_tab() {
        global $wpdb;

        $action = sanitize_text_field( $_GET['action'] ?? '' );
        $workflow_id = (int) ( $_GET['workflow_id'] ?? 0 );

        // Edit/Create form.
        if ( $action === 'edit' || $action === 'new' ) {
            $this->render_workflow_form( $workflow_id );
            return;
        }

        // Handle POST save.
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['hl_workflow_nonce'] ) ) {
            $this->handle_workflow_save();
        }

        $status_filter = sanitize_text_field( $_GET['status'] ?? '' );
        $valid_statuses = array( 'draft', 'active', 'paused' );

        if ( $status_filter && in_array( $status_filter, $valid_statuses, true ) ) {
            $workflows = $wpdb->get_results( $wpdb->prepare(
                "SELECT w.*, t.name AS template_name
                 FROM {$wpdb->prefix}hl_email_workflow w
                 LEFT JOIN {$wpdb->prefix}hl_email_template t ON t.template_id = w.template_id
                 WHERE w.status = %s
                 ORDER BY w.updated_at DESC",
                $status_filter
            ) );
        } else {
            $workflows = $wpdb->get_results(
                "SELECT w.*, t.name AS template_name
                 FROM {$wpdb->prefix}hl_email_workflow w
                 LEFT JOIN {$wpdb->prefix}hl_email_template t ON t.template_id = w.template_id
                 ORDER BY w.updated_at DESC"
            );
        }

        ?>
        <div style="margin-bottom:16px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows&action=new' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Add Workflow', 'hl-core' ); ?></a>
            <span style="margin-left:16px;">
                <?php
                $statuses = array( '' => 'All', 'draft' => 'Draft', 'active' => 'Active', 'paused' => 'Paused' );
                foreach ( $statuses as $key => $label ) :
                    $active = $status_filter === $key ? 'font-weight:bold;' : '';
                ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows&status=' . $key ) ); ?>" style="<?php echo $active; ?> margin-right:8px;"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </span>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Trigger', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Template', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Updated', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'hl-core' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $workflows ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No workflows yet.', 'hl-core' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $workflows as $w ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $w->name ); ?></strong></td>
                            <td><code><?php echo esc_html( $w->trigger_key ); ?></code></td>
                            <td><?php echo esc_html( $w->template_name ?: '—' ); ?></td>
                            <td><?php $this->render_status_badge( $w->status ); ?></td>
                            <td><?php echo esc_html( $w->updated_at ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows&action=edit&workflow_id=' . $w->workflow_id ) ); ?>"><?php esc_html_e( 'Edit', 'hl-core' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_workflow_form( $workflow_id ) {
        global $wpdb;

        $workflow = null;
        if ( $workflow_id ) {
            $workflow = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d",
                $workflow_id
            ) );
        }

        $templates = $wpdb->get_results(
            "SELECT template_id, name FROM {$wpdb->prefix}hl_email_template WHERE status = 'active' ORDER BY name"
        );

        $conditions = array();
        if ( $workflow && ! empty( $workflow->conditions ) ) {
            $conditions = json_decode( $workflow->conditions, true ) ?: array();
        }

        $recipients = array( 'primary' => array(), 'cc' => array() );
        if ( $workflow && ! empty( $workflow->recipients ) ) {
            $recipients = json_decode( $workflow->recipients, true ) ?: $recipients;
        }

        ?>
        <h2><?php echo $workflow ? esc_html__( 'Edit Workflow', 'hl-core' ) : esc_html__( 'New Workflow', 'hl-core' ); ?></h2>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows' ) ); ?>">&larr; <?php esc_html_e( 'Back', 'hl-core' ); ?></a>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows' ) ); ?>" style="max-width:700px;margin-top:16px;">
            <?php wp_nonce_field( 'hl_workflow_save', 'hl_workflow_nonce' ); ?>
            <input type="hidden" name="workflow_id" value="<?php echo (int) $workflow_id; ?>">

            <table class="form-table">
                <tr>
                    <th><label><?php esc_html_e( 'Name', 'hl-core' ); ?></label></th>
                    <td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $workflow->name ?? '' ); ?>" required></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Status', 'hl-core' ); ?></label></th>
                    <td>
                        <select name="status">
                            <option value="draft" <?php selected( $workflow->status ?? 'draft', 'draft' ); ?>><?php esc_html_e( 'Draft', 'hl-core' ); ?></option>
                            <option value="active" <?php selected( $workflow->status ?? '', 'active' ); ?>><?php esc_html_e( 'Active', 'hl-core' ); ?></option>
                            <option value="paused" <?php selected( $workflow->status ?? '', 'paused' ); ?>><?php esc_html_e( 'Paused', 'hl-core' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Trigger', 'hl-core' ); ?></label></th>
                    <td>
                        <select name="trigger_key" required>
                            <option value=""><?php esc_html_e( '— Select —', 'hl-core' ); ?></option>
                            <optgroup label="<?php esc_attr_e( 'Hook-Based (Immediate)', 'hl-core' ); ?>">
                                <?php
                                $hook_triggers = array(
                                    'user_register'                       => 'User Registered',
                                    'hl_enrollment_created'               => 'Enrollment Created',
                                    'hl_pathway_assigned'                 => 'Pathway Assigned',
                                    'hl_learndash_course_completed'       => 'Course Completed',
                                    'hl_pathway_completed'                => 'Pathway Completed',
                                    'hl_coaching_session_created'         => 'Coaching Session Created',
                                    'hl_coaching_session_status_changed'  => 'Coaching Session Status Changed',
                                    'hl_rp_session_created'               => 'RP Session Created',
                                    'hl_rp_session_status_changed'        => 'RP Session Status Changed',
                                    'hl_classroom_visit_submitted'        => 'Classroom Visit Submitted',
                                    'hl_teacher_assessment_submitted'     => 'Teacher Assessment Submitted',
                                    'hl_child_assessment_submitted'       => 'Child Assessment Submitted',
                                    'hl_coach_assigned'                   => 'Coach Assigned',
                                );
                                foreach ( $hook_triggers as $key => $label ) :
                                ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $workflow->trigger_key ?? '', $key ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="<?php esc_attr_e( 'Cron-Based (Scheduled)', 'hl-core' ); ?>">
                                <?php
                                $cron_triggers = array(
                                    'cron:cv_window_7d'         => 'CV Window Opens (7d)',
                                    'cron:cv_overdue_1d'        => 'CV Overdue (1d)',
                                    'cron:rp_window_7d'         => 'RP Window Opens (7d)',
                                    'cron:coaching_window_7d'   => 'Coaching Window (7d)',
                                    'cron:coaching_session_5d'  => 'Session in 5 Days',
                                    'cron:coaching_pre_end'     => 'Pre-Cycle-End No Session',
                                    'cron:action_plan_24h'      => 'Action Plan Overdue (24h)',
                                    'cron:session_notes_24h'    => 'Session Notes Overdue (24h)',
                                    'cron:low_engagement_14d'   => 'Low Engagement (14d)',
                                    'cron:client_success'       => 'Client Success Touchpoint',
                                    'cron:session_24h'          => 'Session in 24 Hours',
                                    'cron:session_1h'           => 'Session in 1 Hour',
                                );
                                foreach ( $cron_triggers as $key => $label ) :
                                ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $workflow->trigger_key ?? '', $key ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Template', 'hl-core' ); ?></label></th>
                    <td>
                        <select name="template_id">
                            <option value=""><?php esc_html_e( '— Select —', 'hl-core' ); ?></option>
                            <?php foreach ( $templates as $t ) : ?>
                                <option value="<?php echo (int) $t->template_id; ?>" <?php selected( $workflow->template_id ?? 0, $t->template_id ); ?>><?php echo esc_html( $t->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Conditions (JSON)', 'hl-core' ); ?></label></th>
                    <td><textarea name="conditions" rows="4" class="large-text"><?php echo esc_textarea( wp_json_encode( $conditions, JSON_PRETTY_PRINT ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'JSON array of conditions. All ANDed. Example: [{"field":"cycle.cycle_type","op":"eq","value":"program"}]', 'hl-core' ); ?></p></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Recipients (JSON)', 'hl-core' ); ?></label></th>
                    <td><textarea name="recipients" rows="3" class="large-text"><?php echo esc_textarea( wp_json_encode( $recipients, JSON_PRETTY_PRINT ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Tokens: triggering_user, assigned_coach, school_director, cc_teacher, role:X, static:email', 'hl-core' ); ?></p></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Delay (minutes)', 'hl-core' ); ?></label></th>
                    <td><input type="number" name="delay_minutes" value="<?php echo (int) ( $workflow->delay_minutes ?? 0 ); ?>" min="0"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Send Window', 'hl-core' ); ?></label></th>
                    <td>
                        <label><?php esc_html_e( 'Start (ET)', 'hl-core' ); ?></label>
                        <input type="time" name="send_window_start" value="<?php echo esc_attr( $workflow->send_window_start ?? '' ); ?>">
                        <label><?php esc_html_e( 'End (ET)', 'hl-core' ); ?></label>
                        <input type="time" name="send_window_end" value="<?php echo esc_attr( $workflow->send_window_end ?? '' ); ?>">
                        <br>
                        <label><?php esc_html_e( 'Days', 'hl-core' ); ?></label>
                        <input type="text" name="send_window_days" value="<?php echo esc_attr( $workflow->send_window_days ?? '' ); ?>" placeholder="mon,tue,wed,thu,fri">
                        <p class="description"><?php esc_html_e( 'All times America/New_York (ET). Leave blank for no window constraint.', 'hl-core' ); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Workflow', 'hl-core' ); ?>">
            </p>
        </form>
        <?php
    }

    private function handle_workflow_save() {
        if ( ! wp_verify_nonce( $_POST['hl_workflow_nonce'], 'hl_workflow_save' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_workflow";

        $workflow_id = (int) ( $_POST['workflow_id'] ?? 0 );
        $data = array(
            'name'              => sanitize_text_field( $_POST['name'] ?? '' ),
            'trigger_key'       => sanitize_text_field( $_POST['trigger_key'] ?? '' ),
            'conditions'        => wp_unslash( $_POST['conditions'] ?? '[]' ),
            'recipients'        => wp_unslash( $_POST['recipients'] ?? '{}' ),
            'template_id'       => (int) ( $_POST['template_id'] ?? 0 ) ?: null,
            'delay_minutes'     => (int) ( $_POST['delay_minutes'] ?? 0 ),
            'send_window_start' => sanitize_text_field( $_POST['send_window_start'] ?? '' ) ?: null,
            'send_window_end'   => sanitize_text_field( $_POST['send_window_end'] ?? '' ) ?: null,
            'send_window_days'  => sanitize_text_field( $_POST['send_window_days'] ?? '' ) ?: null,
            'status'            => sanitize_text_field( $_POST['status'] ?? 'draft' ),
        );

        // Validate status.
        if ( ! in_array( $data['status'], array( 'draft', 'active', 'paused' ), true ) ) {
            $data['status'] = 'draft';
        }

        // Validate trigger_key against allowed list.
        $valid_triggers = array(
            'user_register', 'hl_enrollment_created', 'hl_pathway_assigned',
            'hl_learndash_course_completed', 'hl_pathway_completed',
            'hl_coaching_session_created', 'hl_coaching_session_status_changed',
            'hl_rp_session_created', 'hl_rp_session_status_changed',
            'hl_classroom_visit_submitted', 'hl_teacher_assessment_submitted',
            'hl_child_assessment_submitted', 'hl_coach_assigned',
            'cron:cv_window_7d', 'cron:cv_overdue_1d', 'cron:rp_window_7d',
            'cron:coaching_window_7d', 'cron:coaching_session_5d', 'cron:coaching_pre_end',
            'cron:action_plan_24h', 'cron:session_notes_24h', 'cron:low_engagement_14d',
            'cron:client_success', 'cron:session_24h', 'cron:session_1h',
        );
        if ( ! in_array( $data['trigger_key'], $valid_triggers, true ) ) {
            wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&hl_notice=invalid_trigger' ) );
            exit;
        }

        if ( $workflow_id > 0 ) {
            $wpdb->update( $table, $data, array( 'workflow_id' => $workflow_id ) );
        } else {
            $wpdb->insert( $table, $data );
        }

        wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&hl_notice=workflow_saved' ) );
        exit;
    }

    // =========================================================================
    // Tab: Email Templates
    // =========================================================================

    private function render_templates_tab() {
        global $wpdb;

        $status_filter = sanitize_text_field( $_GET['status'] ?? '' );
        $valid_statuses = array( 'draft', 'active', 'archived' );

        if ( $status_filter && in_array( $status_filter, $valid_statuses, true ) ) {
            $templates = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_email_template WHERE status = %s ORDER BY updated_at DESC",
                $status_filter
            ) );
        } else {
            $templates = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}hl_email_template WHERE status != 'archived' ORDER BY updated_at DESC"
            );
        }

        ?>
        <div style="margin-bottom:16px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=builder' ) ); ?>" class="button button-primary"><?php esc_html_e( 'New Template', 'hl-core' ); ?></a>
            <span style="margin-left:16px;">
                <?php
                $statuses = array( '' => 'Active', 'draft' => 'Draft', 'archived' => 'Archived' );
                foreach ( $statuses as $key => $label ) :
                    $active = $status_filter === $key ? 'font-weight:bold;' : '';
                ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=templates&status=' . $key ) ); ?>" style="<?php echo $active; ?> margin-right:8px;"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </span>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Key', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Category', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Updated', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'hl-core' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $templates ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No templates yet.', 'hl-core' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $templates as $t ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $t->name ); ?></strong></td>
                            <td><code><?php echo esc_html( $t->template_key ); ?></code></td>
                            <td><?php echo esc_html( ucwords( str_replace( '_', ' ', $t->category ) ) ); ?></td>
                            <td><?php $this->render_status_badge( $t->status ); ?></td>
                            <td><?php echo esc_html( $t->updated_at ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=builder&template_id=' . $t->template_id ) ); ?>"><?php esc_html_e( 'Edit', 'hl-core' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    // =========================================================================
    // Tab: Send Log
    // =========================================================================

    private function render_log_tab() {
        global $wpdb;

        $status_filter = sanitize_text_field( $_GET['log_status'] ?? '' );
        $valid_statuses = array( 'pending', 'sending', 'sent', 'failed', 'rate_limited', 'cancelled' );

        $page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $limit  = 50;
        $offset = ( $page - 1 ) * $limit;

        if ( $status_filter && in_array( $status_filter, $valid_statuses, true ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue WHERE status = %s",
                $status_filter
            ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT q.*, t.name AS template_name
                 FROM {$wpdb->prefix}hl_email_queue q
                 LEFT JOIN {$wpdb->prefix}hl_email_template t ON t.template_id = q.template_id
                 WHERE q.status = %s
                 ORDER BY q.created_at DESC
                 LIMIT %d OFFSET %d",
                $status_filter, $limit, $offset
            ) );
        } else {
            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue"
            );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT q.*, t.name AS template_name
                 FROM {$wpdb->prefix}hl_email_queue q
                 LEFT JOIN {$wpdb->prefix}hl_email_template t ON t.template_id = q.template_id
                 ORDER BY q.created_at DESC
                 LIMIT %d OFFSET %d",
                $limit, $offset
            ) );
        }

        ?>
        <div style="margin-bottom:16px;">
            <?php
            $log_statuses = array( '' => 'All', 'pending' => 'Pending', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed', 'rate_limited' => 'Rate Limited', 'cancelled' => 'Cancelled' );
            foreach ( $log_statuses as $key => $label ) :
                $active = $status_filter === $key ? 'font-weight:bold;' : '';
            ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=log&log_status=' . $key ) ); ?>" style="<?php echo $active; ?> margin-right:8px;"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
            <span style="margin-left:16px;color:#666;"><?php printf( esc_html__( '%d total', 'hl-core' ), $total ); ?></span>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:40px;">ID</th>
                    <th><?php esc_html_e( 'Recipient', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Subject', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Template', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Scheduled', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Sent', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Reason', 'hl-core' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="8"><?php esc_html_e( 'No emails in the log.', 'hl-core' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $r ) : ?>
                        <tr>
                            <td><?php echo (int) $r->queue_id; ?></td>
                            <td><?php echo esc_html( $r->recipient_email ); ?></td>
                            <td><?php echo esc_html( wp_trim_words( $r->subject, 10 ) ); ?></td>
                            <td><?php echo esc_html( $r->template_name ?: '—' ); ?></td>
                            <td><?php $this->render_status_badge( $r->status ); ?></td>
                            <td><?php echo esc_html( $r->scheduled_at ); ?></td>
                            <td><?php echo esc_html( $r->sent_at ?: '—' ); ?></td>
                            <td><?php echo esc_html( $r->failed_reason ?: '' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total > $limit ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( array(
                        'base'    => add_query_arg( 'paged', '%#%' ),
                        'format'  => '',
                        'current' => $page,
                        'total'   => ceil( $total / $limit ),
                    ) );
                    ?>
                </div>
            </div>
        <?php endif;
    }

    // =========================================================================
    // Tab: Settings
    // =========================================================================

    private function render_settings_tab() {
        // Handle settings save.
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['hl_email_settings_nonce'] ) ) {
            if ( ! current_user_can( 'manage_hl_core' ) ) {
                wp_die( 'Unauthorized' );
            }
            if ( wp_verify_nonce( $_POST['hl_email_settings_nonce'], 'hl_email_settings' ) ) {
                update_option( 'hl_email_rate_limit_hour', (int) ( $_POST['rate_hour'] ?? 5 ) );
                update_option( 'hl_email_rate_limit_day', (int) ( $_POST['rate_day'] ?? 20 ) );
                update_option( 'hl_email_rate_limit_week', (int) ( $_POST['rate_week'] ?? 50 ) );
                echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'hl-core' ) . '</p></div>';
            }
        }

        global $wpdb;

        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue WHERE status = 'pending'"
        );
        $sending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue WHERE status = 'sending'"
        );
        $failed_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue WHERE status = 'failed'"
        );
        $rate_limited_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue WHERE status = 'rate_limited'"
        );

        $rate_hour = (int) get_option( 'hl_email_rate_limit_hour', 5 );
        $rate_day  = (int) get_option( 'hl_email_rate_limit_day', 20 );
        $rate_week = (int) get_option( 'hl_email_rate_limit_week', 50 );

        ?>
        <h2><?php esc_html_e( 'Queue Health', 'hl-core' ); ?></h2>
        <table class="widefat" style="max-width:400px;">
            <tr><td><?php esc_html_e( 'Pending', 'hl-core' ); ?></td><td><strong><?php echo esc_html( $pending_count ); ?></strong></td></tr>
            <tr><td><?php esc_html_e( 'Sending', 'hl-core' ); ?></td><td><strong><?php echo esc_html( $sending_count ); ?></strong></td></tr>
            <tr><td><?php esc_html_e( 'Failed', 'hl-core' ); ?></td><td><strong style="color:red;"><?php echo esc_html( $failed_count ); ?></strong></td></tr>
            <tr><td><?php esc_html_e( 'Rate Limited', 'hl-core' ); ?></td><td><strong><?php echo esc_html( $rate_limited_count ); ?></strong></td></tr>
        </table>

        <?php if ( $failed_count > 0 ) : ?>
            <p><button type="button" class="button" id="hl-retry-failed"><?php esc_html_e( 'Retry Failed', 'hl-core' ); ?></button></p>
            <script>
                jQuery('#hl-retry-failed').on('click', function(){
                    jQuery.post(ajaxurl, {action:'hl_email_retry_failed', nonce:'<?php echo wp_create_nonce('hl_email_retry'); ?>'}, function(r){
                        if(r.success) location.reload();
                        else alert(r.data);
                    });
                });
            </script>
        <?php endif; ?>

        <h2 style="margin-top:24px;"><?php esc_html_e( 'Rate Limits', 'hl-core' ); ?></h2>
        <form method="post" style="max-width:400px;">
            <?php wp_nonce_field( 'hl_email_settings', 'hl_email_settings_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label><?php esc_html_e( 'Per Hour', 'hl-core' ); ?></label></th>
                    <td><input type="number" name="rate_hour" value="<?php echo esc_attr( $rate_hour ); ?>" min="1"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Per Day', 'hl-core' ); ?></label></th>
                    <td><input type="number" name="rate_day" value="<?php echo esc_attr( $rate_day ); ?>" min="1"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Per Week', 'hl-core' ); ?></label></th>
                    <td><input type="number" name="rate_week" value="<?php echo esc_attr( $rate_week ); ?>" min="1"></td>
                </tr>
            </table>
            <p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'hl-core' ); ?>"></p>
        </form>
        <?php
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    public function ajax_workflow_save() {
        check_ajax_referer( 'hl_email_builder', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        // Handled via form POST in render_workflow_form.
        wp_send_json_error( 'Use the form to save workflows.' );
    }

    public function ajax_workflow_delete() {
        check_ajax_referer( 'hl_email_builder', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $id = (int) ( $_POST['workflow_id'] ?? 0 );
        if ( $id ) {
            $wpdb->delete( "{$wpdb->prefix}hl_email_workflow", array( 'workflow_id' => $id ), array( '%d' ) );
        }
        wp_send_json_success();
    }

    public function ajax_retry_failed() {
        check_ajax_referer( 'hl_email_retry', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hl_email_queue
             SET status = 'pending', attempts = 0, claim_token = NULL, scheduled_at = %s
             WHERE status = 'failed'",
            gmdate( 'Y-m-d H:i:s' )
        ) );
        wp_send_json_success();
    }

    public function ajax_cancel_queue() {
        check_ajax_referer( 'hl_email_builder', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $id = (int) ( $_POST['queue_id'] ?? 0 );
        if ( $id ) {
            $wpdb->update(
                "{$wpdb->prefix}hl_email_queue",
                array( 'status' => 'cancelled' ),
                array( 'queue_id' => $id, 'status' => 'pending' ),
                array( '%s' ),
                array( '%d', '%s' )
            );
        }
        wp_send_json_success();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function render_status_badge( $status ) {
        $colors = array(
            'draft'        => '#999',
            'active'       => '#27ae60',
            'paused'       => '#f39c12',
            'archived'     => '#999',
            'pending'      => '#3498db',
            'sending'      => '#f39c12',
            'sent'         => '#27ae60',
            'failed'       => '#e74c3c',
            'cancelled'    => '#999',
            'rate_limited' => '#e67e22',
        );
        $color = $colors[ $status ] ?? '#999';
        echo '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . esc_attr( $color ) . ';color:#fff;font-size:12px;">' . esc_html( $status ) . '</span>';
    }
}
