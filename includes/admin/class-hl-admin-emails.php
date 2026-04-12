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
        add_action( 'wp_ajax_hl_email_recipient_count', array( $this, 'ajax_recipient_count' ) );

        // A.2.13 — duplicate & delete via admin-post.php (POST, not GET).
        add_action( 'admin_post_hl_workflow_duplicate',  array( $this, 'handle_workflow_duplicate' ) );
        add_action( 'admin_post_hl_workflow_delete',     array( $this, 'handle_workflow_delete' ) );
        add_action( 'admin_post_hl_template_duplicate',  array( $this, 'handle_template_duplicate' ) );
        add_action( 'admin_post_hl_template_archive',    array( $this, 'handle_template_archive' ) );
        add_action( 'admin_post_hl_workflow_force_resend', array( $this, 'handle_workflow_force_resend' ) );

        add_action( 'wp_ajax_hl_workflow_toggle_status', array( $this, 'ajax_workflow_toggle_status' ) );
    }

    // =========================================================================
    // Static Registries (v2 Track 1)
    // =========================================================================

    /**
     * Field registry for the visual condition builder.
     *
     * Each field mirrors a context key populated by
     * HL_Email_Automation_Service::build_context() and consumed by
     * HL_Email_Condition_Evaluator::evaluate().
     *
     * @return array<string, array{label:string,group:string,type:string,options:array}>
     */
    public static function get_condition_fields() {
        return array(
            // Cycle group.
            'cycle.cycle_type' => array(
                'label'   => 'Cycle Type',
                'group'   => 'Cycle',
                'type'    => 'enum',
                'options' => array( 'program' => 'Program', 'course' => 'Course' ),
            ),
            'cycle.status' => array(
                'label'   => 'Cycle Status',
                'group'   => 'Cycle',
                'type'    => 'enum',
                'options' => array( 'active' => 'Active', 'archived' => 'Archived' ),
            ),
            'cycle.is_control_group' => array(
                'label'   => 'Is Control Group',
                'group'   => 'Cycle',
                'type'    => 'boolean',
                'options' => array(),
            ),
            // Enrollment group.
            'enrollment.status' => array(
                'label'   => 'Enrollment Status',
                'group'   => 'Enrollment',
                'type'    => 'enum',
                'options' => array(
                    'active'    => 'Active',
                    'warning'   => 'Warning',
                    'withdrawn' => 'Withdrawn',
                    'completed' => 'Completed',
                    'expired'   => 'Expired',
                ),
            ),
            'enrollment.roles' => array(
                'label'   => 'Enrollment Roles',
                'group'   => 'Enrollment',
                'type'    => 'enum',
                'options' => array(
                    'teacher'        => 'Teacher',
                    'mentor'         => 'Mentor',
                    'coach'          => 'Coach',
                    'school_leader'  => 'School Leader',
                    'district_leader'=> 'District Leader',
                ),
                'is_csv' => true, // Tells evaluator to use HL_Roles::has_role.
            ),
            // User group.
            'user.account_activated' => array(
                'label'   => 'Account Activated',
                'group'   => 'User',
                'type'    => 'boolean',
                'options' => array(),
            ),
        );
    }

    /**
     * Operator registry per field type.
     *
     * Keys are the JSON operator values stored in DB; values are
     * human-friendly labels shown in the UI.
     *
     * @return array<string, array<string,string>>
     */
    public static function get_condition_operators() {
        return array(
            'enum' => array(
                'eq'       => 'equals',
                'neq'      => 'not equals',
                'in'       => 'matches any of',
                'not_in'   => 'does not match any of',
                'is_null'  => 'is empty',
                'not_null' => 'is not empty',
            ),
            'boolean' => array(
                'eq' => 'equals',
            ),
            'text' => array(
                'eq'       => 'equals',
                'neq'      => 'not equals',
                'in'       => 'matches any of',
                'not_in'   => 'does not match any of',
                'is_null'  => 'is empty',
                'not_null' => 'is not empty',
            ),
            'numeric' => array(
                'eq'       => 'equals',
                'neq'      => 'not equals',
                'gt'       => 'greater than',
                'lt'       => 'less than',
                'is_null'  => 'is empty',
                'not_null' => 'is not empty',
            ),
        );
    }

    /**
     * Flatten all operator labels to a single dictionary.
     *
     * Used for server-side allowlist checks and error messages
     * that need to say "matches any of" not "in".
     *
     * @return array<string,string>
     */
    public static function get_all_operator_labels() {
        $out = array();
        foreach ( self::get_condition_operators() as $type => $ops ) {
            foreach ( $ops as $key => $label ) {
                $out[ $key ] = $label;
            }
        }
        return $out;
    }

    /**
     * Human-friendly label for an operator key. Used in error messages.
     * A.6.14 — consistent labeling across UI and server-side errors.
     *
     * @param string $op Operator key (e.g. 'eq', 'in').
     * @return string Label (e.g. 'equals', 'matches any of'). Returns $op unchanged if unknown.
     */
    public static function operator_label( $op ) {
        $labels = self::get_all_operator_labels();
        return isset( $labels[ $op ] ) ? $labels[ $op ] : $op;
    }

    /**
     * Recipient token registry.
     *
     * The `triggers` key is either '*' (always visible) or an array
     * of trigger_key values this token is compatible with. Incompatible
     * tokens stay dimmed in the UI but remain in stored JSON — the
     * server-side resolver silently skips them at send time (A.2.10).
     *
     * @return array<string, array{label:string,description:string,triggers:string|array}>
     */
    public static function get_recipient_tokens() {
        return array(
            'triggering_user' => array(
                'label'       => 'Triggering User',
                'description' => 'The user who caused the event.',
                'triggers'    => '*',
            ),
            'assigned_coach' => array(
                'label'       => "User's Coach",
                'description' => 'Coach assigned to this user via hl_coach_assignment.',
                'triggers'    => array(
                    'hl_enrollment_created',
                    'hl_pathway_assigned',
                    'hl_coaching_session_created',
                    'hl_coaching_session_status_changed',
                    'hl_rp_session_created',
                    'hl_rp_session_status_changed',
                    'hl_classroom_visit_submitted',
                    'hl_teacher_assessment_submitted',
                    'hl_child_assessment_submitted',
                    'hl_pathway_completed',
                    'cron:cv_window_7d',
                    'cron:cv_overdue_1d',
                    'cron:rp_window_7d',
                    'cron:coaching_window_7d',
                    'cron:coaching_session_5d',
                    'cron:coaching_pre_end',
                    'cron:action_plan_24h',
                    'cron:session_notes_24h',
                    'cron:low_engagement_14d',
                    'cron:session_24h',
                    'cron:session_1h',
                ),
            ),
            'assigned_mentor' => array(
                'label'       => "User's Mentor",
                'description' => 'Mentor of the triggering user (via team membership in the current cycle).',
                'triggers'    => array(
                    'hl_classroom_visit_submitted',
                    'hl_teacher_assessment_submitted',
                    'hl_child_assessment_submitted',
                    'hl_pathway_completed',
                    'hl_learndash_course_completed',
                ),
            ),
            'school_director' => array(
                'label'       => 'School Director',
                'description' => "School leader for the user's school.",
                'triggers'    => '*',
            ),
            'observed_teacher' => array(
                'label'       => 'Observed Teacher',
                'description' => 'Teacher being observed in a classroom visit.',
                'triggers'    => array( 'hl_classroom_visit_submitted' ),
            ),
        );
    }

    /**
     * Generate a unique "(Copy)" suffix for duplicated rows.
     * A.2.12 — unified helper for both workflow and template duplication.
     *
     * Retries up to 10 times with "(Copy)", "(Copy 2)", ... then falls
     * back to UUID suffix.
     *
     * @param string $table       Table name: 'hl_email_workflow' or 'hl_email_template'.
     * @param string $source_name Original row name.
     * @return string Unique name guaranteed not to collide at call time.
     */
    public static function generate_copy_name( $table, $source_name ) {
        global $wpdb;
        $allowed_tables = array( 'hl_email_workflow', 'hl_email_template' );
        if ( ! in_array( $table, $allowed_tables, true ) ) {
            return trim( (string) $source_name ) . ' (Copy)';
        }
        $full_table = $wpdb->prefix . $table;
        $base       = trim( (string) $source_name );

        for ( $i = 1; $i <= 10; $i++ ) {
            $candidate = $i === 1 ? $base . ' (Copy)' : $base . ' (Copy ' . $i . ')';
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$full_table} WHERE name = %s",
                $candidate
            ) );
            if ( $exists === 0 ) {
                return $candidate;
            }
        }
        // Fallback: UUID suffix — guaranteed unique.
        return $base . ' (Copy ' . substr( wp_generate_uuid4(), 0, 8 ) . ')';
    }

    /**
     * Server-side allowlist validation for workflow save.
     * A.2.27 — rejects any condition field/op or recipient token that
     * does not appear in the static registries. Defence in depth — the
     * UI already constrains this, but a hand-crafted POST could bypass.
     *
     * @param array $conditions Decoded conditions JSON.
     * @param array $recipients Decoded recipients JSON.
     * @return true|WP_Error
     */
    public static function validate_workflow_payload( $conditions, $recipients ) {
        $fields    = self::get_condition_fields();
        $op_labels = self::get_all_operator_labels();
        $tokens    = self::get_recipient_tokens();

        if ( ! is_array( $conditions ) ) {
            return new WP_Error( 'hl_email_invalid_conditions', 'Conditions must be an array.' );
        }
        foreach ( $conditions as $i => $c ) {
            if ( ! is_array( $c ) ) {
                return new WP_Error( 'hl_email_invalid_condition', "Condition #{$i} must be an object." );
            }
            $field = $c['field'] ?? '';
            $op    = $c['op']    ?? '';
            if ( ! isset( $fields[ $field ] ) ) {
                return new WP_Error( 'hl_email_unknown_field', "Unknown condition field: '{$field}'." );
            }
            if ( ! isset( $op_labels[ $op ] ) ) {
                return new WP_Error( 'hl_email_unknown_op', "Unknown operator: '{$op}'." );
            }
            // Op must be valid for this field's type.
            $type    = $fields[ $field ]['type'];
            $allowed = self::get_condition_operators()[ $type ] ?? array();
            if ( ! isset( $allowed[ $op ] ) ) {
                return new WP_Error(
                    'hl_email_op_type_mismatch',
                    "Operator '" . self::operator_label( $op ) . "' is not valid for field type '{$type}'."
                );
            }
        }

        if ( ! is_array( $recipients ) ) {
            return new WP_Error( 'hl_email_invalid_recipients', 'Recipients must be an object.' );
        }
        foreach ( array( 'primary', 'cc' ) as $section ) {
            if ( ! isset( $recipients[ $section ] ) ) continue;
            if ( ! is_array( $recipients[ $section ] ) ) {
                return new WP_Error( 'hl_email_invalid_recipients_section', "Recipients.{$section} must be an array." );
            }
            foreach ( $recipients[ $section ] as $entry ) {
                if ( ! is_string( $entry ) ) continue;
                // role:X and static:email are free-form — only validate bare token names.
                if ( strpos( $entry, 'role:' ) === 0 || strpos( $entry, 'static:' ) === 0 ) continue;
                // Accept legacy cc_teacher alias (A.6.11).
                if ( $entry === 'cc_teacher' ) continue;
                if ( ! isset( $tokens[ $entry ] ) ) {
                    return new WP_Error( 'hl_email_unknown_token', "Unknown recipient token: '{$entry}'." );
                }
            }
        }

        return true;
    }

    /**
     * Safely decode JSON from a posted textarea.
     *
     * @param string $raw    Raw POST value (unslashed).
     * @param mixed  $default Fallback on decode failure.
     * @return mixed
     */
    public static function sanitize_json_payload( $raw, $default ) {
        $decoded = json_decode( (string) $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return $default;
        }
        return $decoded;
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
                 WHERE w.status != 'deleted'
                 ORDER BY w.updated_at DESC"
            );
        }

        ?>
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows&action=new' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Add Workflow', 'hl-core' ); ?></a>
            <div class="hl-email-filters">
                <?php
                $statuses = array( '' => 'All', 'draft' => 'Draft', 'active' => 'Active', 'paused' => 'Paused' );
                foreach ( $statuses as $key => $label ) :
                    $active_class = $status_filter === $key ? ' active' : '';
                ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows&status=' . $key ) ); ?>" class="<?php echo esc_attr( $active_class ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </div>
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
                "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d AND status != 'deleted'",
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

        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows' ) ); ?>" class="hl-workflow-form" style="margin-top:16px;">
        <div class="hl-email-admin">
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
                    <th><label><?php esc_html_e( 'Conditions', 'hl-core' ); ?></label></th>
                    <td>
                        <div class="hl-condition-builder" data-initial="<?php echo esc_attr( wp_json_encode( $conditions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>">
                            <div class="hl-condition-rows" aria-live="polite"></div>
                            <button type="button" class="hl-condition-add button-link">
                                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                <?php esc_html_e( 'Add Condition', 'hl-core' ); ?>
                            </button>
                            <p class="hl-condition-hint">
                                <span class="hl-badge-and"><?php esc_html_e( 'All conditions must match (AND)', 'hl-core' ); ?></span>
                                <?php esc_html_e( 'Empty = matches every event for this trigger.', 'hl-core' ); ?>
                            </p>
                        </div>
                        <!-- A.7.4 / A.7.10 — raw JSON fallback when JS fails to initialise. -->
                        <details class="hl-js-fallback">
                            <summary><?php esc_html_e( 'Raw JSON edit mode (JavaScript required for visual editor)', 'hl-core' ); ?></summary>
                            <textarea name="conditions" rows="4" class="large-text code" spellcheck="false"><?php echo esc_textarea( wp_json_encode( $conditions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Visual builder writes to this textarea automatically. Edit here only if the visual builder is broken.', 'hl-core' ); ?></p>
                        </details>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Recipients', 'hl-core' ); ?></label></th>
                    <td>
                        <div class="hl-recipient-picker" data-initial="<?php echo esc_attr( wp_json_encode( $recipients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>" data-current-trigger="<?php echo esc_attr( isset( $workflow->trigger_key ) ? $workflow->trigger_key : '' ); ?>">
                            <!-- Primary Section -->
                            <section class="hl-recipient-section hl-recipient-primary" aria-labelledby="hl-recip-primary-h">
                                <h4 id="hl-recip-primary-h"><?php esc_html_e( 'Primary Recipients (To:)', 'hl-core' ); ?></h4>
                                <div class="hl-token-grid" role="group" aria-label="<?php esc_attr_e( 'Primary recipient tokens', 'hl-core' ); ?>">
                                </div>
                                <div class="hl-recipient-roles">
                                    <label><?php esc_html_e( 'By Role', 'hl-core' ); ?></label>
                                    <div class="hl-pill-input hl-pill-input-role" role="list" aria-label="<?php esc_attr_e( 'Role-based recipients', 'hl-core' ); ?>">
                                        <input type="text" placeholder="<?php esc_attr_e( 'teacher, mentor, coach... (Enter to add)', 'hl-core' ); ?>">
                                    </div>
                                </div>
                                <div class="hl-recipient-static">
                                    <label><?php esc_html_e( 'Static Emails', 'hl-core' ); ?></label>
                                    <div class="hl-pill-input hl-pill-input-email" role="list" aria-label="<?php esc_attr_e( 'Static email recipients', 'hl-core' ); ?>">
                                        <input type="email" placeholder="<?php esc_attr_e( 'name@example.com (Enter to add)', 'hl-core' ); ?>">
                                    </div>
                                </div>
                            </section>

                            <!-- CC Section -->
                            <section class="hl-recipient-section hl-recipient-cc" aria-labelledby="hl-recip-cc-h">
                                <h4 id="hl-recip-cc-h"><?php esc_html_e( 'CC Recipients', 'hl-core' ); ?></h4>
                                <div class="hl-token-list hl-token-list-cc" role="group" aria-label="<?php esc_attr_e( 'CC recipient tokens', 'hl-core' ); ?>">
                                </div>
                                <div class="hl-recipient-roles">
                                    <label><?php esc_html_e( 'CC By Role', 'hl-core' ); ?></label>
                                    <div class="hl-pill-input hl-pill-input-role" role="list">
                                        <input type="text" placeholder="<?php esc_attr_e( 'Role name (Enter to add)', 'hl-core' ); ?>">
                                    </div>
                                </div>
                                <div class="hl-recipient-static">
                                    <label><?php esc_html_e( 'CC Static Emails', 'hl-core' ); ?></label>
                                    <div class="hl-pill-input hl-pill-input-email" role="list">
                                        <input type="email" placeholder="<?php esc_attr_e( 'name@example.com (Enter to add)', 'hl-core' ); ?>">
                                    </div>
                                </div>
                            </section>

                            <!-- A.2.14 / A.7.7 — live recipient count hint -->
                            <p class="hl-recipient-count-hint" aria-live="polite" role="status"></p>
                        </div>

                        <details class="hl-js-fallback">
                            <summary><?php esc_html_e( 'Raw JSON edit mode (JavaScript required for visual editor)', 'hl-core' ); ?></summary>
                            <textarea name="recipients" rows="3" class="large-text code" spellcheck="false"><?php echo esc_textarea( wp_json_encode( $recipients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Visual picker writes to this textarea automatically.', 'hl-core' ); ?></p>
                        </details>
                    </td>
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
        </div><!-- /.hl-email-admin -->
        </form>
        <?php
    }

    private function handle_workflow_save() {
        if ( ! isset( $_POST['hl_workflow_nonce'] ) || ! wp_verify_nonce( $_POST['hl_workflow_nonce'], 'hl_workflow_save' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_workflow";

        $workflow_id = (int) ( $_POST['workflow_id'] ?? 0 );

        // A.3.7 — trim JSON payload to defeat accidental whitespace bloat.
        $raw_conditions = trim( wp_unslash( $_POST['conditions'] ?? '[]' ) );
        $raw_recipients = trim( wp_unslash( $_POST['recipients'] ?? '{"primary":[],"cc":[]}' ) );

        $conditions = self::sanitize_json_payload( $raw_conditions, array() );
        $recipients = self::sanitize_json_payload( $raw_recipients, array( 'primary' => array(), 'cc' => array() ) );

        // A.2.27 — server-side allowlist validation.
        $valid = self::validate_workflow_payload( $conditions, $recipients );
        if ( is_wp_error( $valid ) ) {
            wp_redirect( add_query_arg( array(
                'page'      => 'hl-emails',
                'tab'       => 'workflows',
                'hl_notice' => 'invalid_payload',
                'hl_error'  => rawurlencode( $valid->get_error_message() ),
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // A.3.5 — re-encode with stable flags before storing.
        $conditions_json = wp_json_encode( $conditions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $recipients_json = wp_json_encode( $recipients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( $conditions_json === false ) $conditions_json = '[]';
        if ( $recipients_json === false ) $recipients_json = '{"primary":[],"cc":[]}';

        $data = array(
            'name'              => sanitize_text_field( $_POST['name'] ?? '' ),
            'trigger_key'       => sanitize_text_field( $_POST['trigger_key'] ?? '' ),
            'conditions'        => $conditions_json,
            'recipients'        => $recipients_json,
            'template_id'       => (int) ( $_POST['template_id'] ?? 0 ) ?: null,
            'delay_minutes'     => (int) ( $_POST['delay_minutes'] ?? 0 ),
            'send_window_start' => sanitize_text_field( $_POST['send_window_start'] ?? '' ) ?: null,
            'send_window_end'   => sanitize_text_field( $_POST['send_window_end'] ?? '' ) ?: null,
            'send_window_days'  => sanitize_text_field( $_POST['send_window_days'] ?? '' ) ?: null,
            'status'            => sanitize_text_field( $_POST['status'] ?? 'draft' ),
        );

        // Validate status — now includes 'deleted' as a valid persisted state
        // for soft-delete, but admins cannot set it via the form.
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
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_workflow_updated', array( 'workflow_id' => $workflow_id ) );
            }
        } else {
            $wpdb->insert( $table, $data );
            $workflow_id = (int) $wpdb->insert_id;
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_workflow_created', array( 'workflow_id' => $workflow_id ) );
            }
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
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=builder' ) ); ?>" class="button button-primary"><?php esc_html_e( 'New Template', 'hl-core' ); ?></a>
            <div class="hl-email-filters">
                <?php
                $statuses = array( '' => 'Active', 'draft' => 'Draft', 'archived' => 'Archived' );
                foreach ( $statuses as $key => $label ) :
                    $active_class = $status_filter === $key ? ' active' : '';
                ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=templates&status=' . $key ) ); ?>" class="<?php echo esc_attr( $active_class ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </div>
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
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
            <div class="hl-email-filters">
                <?php
                $log_statuses = array( '' => 'All', 'pending' => 'Pending', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed', 'rate_limited' => 'Rate Limited', 'cancelled' => 'Cancelled' );
                foreach ( $log_statuses as $key => $label ) :
                    $active_class = $status_filter === $key ? ' active' : '';
                ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=log&log_status=' . $key ) ); ?>" class="<?php echo esc_attr( $active_class ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </div>
            <span style="color:#6B7280;font-size:12px;"><?php printf( esc_html__( '%d total', 'hl-core' ), $total ); ?></span>
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
        <div class="hl-queue-health">
            <div class="hl-queue-health-card">
                <div class="hl-qh-value"><?php echo esc_html( $pending_count ); ?></div>
                <div class="hl-qh-label"><?php esc_html_e( 'Pending', 'hl-core' ); ?></div>
            </div>
            <div class="hl-queue-health-card">
                <div class="hl-qh-value"><?php echo esc_html( $sending_count ); ?></div>
                <div class="hl-qh-label"><?php esc_html_e( 'Sending', 'hl-core' ); ?></div>
            </div>
            <div class="hl-queue-health-card hl-qh-failed">
                <div class="hl-qh-value"><?php echo esc_html( $failed_count ); ?></div>
                <div class="hl-qh-label"><?php esc_html_e( 'Failed', 'hl-core' ); ?></div>
            </div>
            <div class="hl-queue-health-card">
                <div class="hl-qh-value"><?php echo esc_html( $rate_limited_count ); ?></div>
                <div class="hl-qh-label"><?php esc_html_e( 'Rate Limited', 'hl-core' ); ?></div>
            </div>
        </div>

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

    /**
     * Async recipient count preview for the picker UI.
     * A.2.14 / A.7.7 — live estimate of how many addresses the current
     * recipient JSON resolves to for the given trigger.
     *
     * @return void
     */
    public function ajax_recipient_count() {
        check_ajax_referer( 'hl_workflow_recipient_count', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $trigger    = sanitize_text_field( wp_unslash( $_POST['trigger'] ?? '' ) );
        $raw_recip  = wp_unslash( $_POST['recipients'] ?? '{}' );
        $recipients = json_decode( $raw_recip, true );

        if ( ! is_array( $recipients ) ) {
            wp_send_json_success( array( 'count' => 0 ) );
        }

        $tokens = self::get_recipient_tokens();
        global $wpdb;

        $count = 0;
        foreach ( array( 'primary', 'cc' ) as $section ) {
            if ( empty( $recipients[ $section ] ) || ! is_array( $recipients[ $section ] ) ) continue;
            foreach ( $recipients[ $section ] as $entry ) {
                if ( ! is_string( $entry ) ) continue;
                if ( strpos( $entry, 'static:' ) === 0 ) {
                    $count++;
                    continue;
                }
                if ( strpos( $entry, 'role:' ) === 0 ) {
                    $role = substr( $entry, 5 );
                    if ( ! class_exists( 'HL_Roles' ) ) {
                        continue;
                    }
                    $rows = $wpdb->get_results(
                        "SELECT DISTINCT user_id, roles FROM {$wpdb->prefix}hl_enrollment
                         WHERE status = 'active'"
                    );
                    $matched = array();
                    foreach ( $rows as $row ) {
                        if ( HL_Roles::has_role( $row->roles, $role ) ) {
                            $matched[ (int) $row->user_id ] = true;
                        }
                    }
                    $count += count( $matched );
                    continue;
                }
                if ( ! isset( $tokens[ $entry ] ) ) continue;
                $def = $tokens[ $entry ];
                if ( $def['triggers'] !== '*' && is_array( $def['triggers'] ) && ! in_array( $trigger, $def['triggers'], true ) ) {
                    continue;
                }
                $count += 1;
            }
        }

        wp_send_json_success( array( 'count' => $count ) );
    }

    // =========================================================================
    // Row Action Handlers (v2 Track 1 Tasks 11, 12, 14)
    // =========================================================================

    // Stubs — implemented in later tasks.
    public function handle_workflow_duplicate() { wp_die( 'Not yet implemented' ); }
    public function handle_workflow_delete() { wp_die( 'Not yet implemented' ); }
    public function handle_template_duplicate() { wp_die( 'Not yet implemented' ); }
    public function handle_template_archive() { wp_die( 'Not yet implemented' ); }
    public function handle_workflow_force_resend() { wp_die( 'Not yet implemented' ); }
    public function ajax_workflow_toggle_status() { wp_send_json_error( 'Not yet implemented' ); }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function render_status_badge( $status ) {
        echo '<span class="hl-email-badge hl-email-badge--' . esc_attr( $status ) . '">' . esc_html( $status ) . '</span>';
    }
}
