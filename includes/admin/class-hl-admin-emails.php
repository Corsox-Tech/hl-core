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

    /**
     * Check whether the v2 workflow UX is active.
     *
     * @return bool
     */
    public static function is_v2_ux() {
        return get_option( 'hl_workflow_ux_version', 'v2' ) === 'v2';
    }

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

    /**
     * Handle POST redirects before any HTML output.
     *
     * Called from HL_Admin::handle_early_actions() on admin_init so
     * wp_redirect() can send headers before WordPress outputs the admin chrome.
     */
    public function handle_early_actions() {
        $tab = sanitize_text_field( $_GET['tab'] ?? 'workflows' );

        if ( $tab === 'workflows' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['hl_workflow_nonce'] ) ) {
            $this->handle_workflow_save(); // Calls wp_redirect + exit.
        }
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
                    'active'   => 'Active',
                    'inactive' => 'Inactive',
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
            // Session group.
            'session.new_status' => array(
                'label'   => 'Session New Status',
                'group'   => 'Session',
                'type'    => 'enum',
                'options' => array(
                    'scheduled'   => 'Scheduled',
                    'attended'    => 'Attended',
                    'missed'      => 'Missed / No Show',
                    'cancelled'   => 'Cancelled',
                    'rescheduled' => 'Rescheduled',
                ),
            ),
            // User group.
            'user.account_activated' => array(
                'label'   => 'Account Activated',
                'group'   => 'User',
                'type'    => 'boolean',
                'options' => array(),
            ),
            // Coaching group.
            'coaching.session_status' => array(
                'label'   => 'Coaching Session Status',
                'group'   => 'Coaching',
                'type'    => 'enum',
                'options' => array(
                    'not_scheduled' => 'Not Scheduled',
                    'scheduled'     => 'Scheduled',
                    'attended'      => 'Attended',
                    'missed'        => 'Missed',
                    'cancelled'     => 'Cancelled',
                    'rescheduled'   => 'Rescheduled',
                ),
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
     * Migrate workflows using the old coaching.session_scheduled condition
     * to the new coaching.session_status enum.
     *
     * Called from HL_Installer::maybe_upgrade() on plugin activation.
     */
    public static function migrate_coaching_session_conditions() {
        if ( get_option( 'hl_coaching_condition_migrated', false ) ) {
            return;
        }

        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_workflow";

        // Guard: table may not exist on fresh installs.
        if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) ) {
            return;
        }

        // Pre-migration backup for rollback safety.
        $backup = "{$table}_pre_coaching_migration";
        if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $backup ) ) ) {
            $wpdb->query( "CREATE TABLE `{$backup}` AS SELECT workflow_id, conditions FROM `{$table}` WHERE conditions LIKE '%coaching.session_scheduled%'" );
        }

        $rows = $wpdb->get_results(
            "SELECT workflow_id, conditions FROM {$table} WHERE conditions LIKE '%coaching.session_scheduled%'"
        );

        foreach ( $rows as $row ) {
            $conditions = json_decode( $row->conditions, true );
            if ( ! is_array( $conditions ) ) continue;

            $changed = false;
            foreach ( $conditions as &$cond ) {
                if ( ( $cond['field'] ?? '' ) !== 'coaching.session_scheduled' ) continue;

                $old_value = $cond['value'] ?? '';
                $cond['field'] = 'coaching.session_status';

                if ( $old_value === 'yes' ) {
                    $cond['op']    = 'in';
                    $cond['value'] = array( 'scheduled', 'attended' );
                } else {
                    $cond['op']    = 'in';
                    $cond['value'] = array( 'not_scheduled', 'cancelled', 'missed', 'rescheduled' );
                }
                $changed = true;
            }
            unset( $cond );

            if ( $changed ) {
                $wpdb->update(
                    $table,
                    array( 'conditions' => wp_json_encode( $conditions ) ),
                    array( 'workflow_id' => $row->workflow_id ),
                    array( '%s' ),
                    array( '%d' )
                );

                if ( class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'workflow_condition_migrated', array(
                        'entity_type' => 'email_workflow',
                        'entity_id'   => (int) $row->workflow_id,
                        'old_field'   => 'coaching.session_scheduled',
                        'new_field'   => 'coaching.session_status',
                    ) );
                }
            }
        }

        update_option( 'hl_coaching_condition_migrated', true );
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
                    // New generic keys:
                    'cron:component_upcoming',
                    'cron:component_overdue',
                    'cron:session_upcoming',
                    // Retained non-offset keys:
                    'cron:coaching_pre_end',
                    'cron:action_plan_24h',
                    'cron:session_notes_24h',
                    'cron:low_engagement_14d',
                    // Legacy aliases (kept until old workflows are fully migrated):
                    'cron:cv_window_7d',
                    'cron:cv_overdue_1d',
                    'cron:rp_window_7d',
                    'cron:coaching_window_7d',
                    'cron:coaching_session_5d',
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

        // POST save is handled in render_page() before any HTML output.

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

        // Task 7 Step 11b: Render LIMIT cap warning transient.
        $cap_warning = get_transient( 'hl_email_cron_cap_warning' );
        if ( $cap_warning ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $cap_warning ) . '</p></div>';
        }

        // Task 7 Step 16: Failed sends warning (last 24h).
        $failed_24h = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue WHERE status = 'failed' AND updated_at >= %s",
            gmdate( 'Y-m-d H:i:s', time() - 86400 )
        ) );
        if ( $failed_24h > 0 ) {
            echo '<div class="notice notice-error"><p>' . sprintf( '%d email(s) failed to send in the last 24 hours.', $failed_24h ) . '</p></div>';
        }

        ?>
        <div class="hl-email-admin">

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

        <?php
        // Task 7 Step 16: Pre-load 24h activity data for all workflows.
        $activity_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT workflow_id, status, COUNT(*) AS cnt FROM {$wpdb->prefix}hl_email_queue WHERE created_at >= %s GROUP BY workflow_id, status",
            gmdate( 'Y-m-d H:i:s', time() - 86400 )
        ) );
        $activity_map = array();
        if ( is_array( $activity_raw ) ) {
            foreach ( $activity_raw as $a ) {
                $wid = (int) $a->workflow_id;
                if ( ! isset( $activity_map[ $wid ] ) ) {
                    $activity_map[ $wid ] = array();
                }
                $activity_map[ $wid ][ $a->status ] = (int) $a->cnt;
            }
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Trigger', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Template', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Updated', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( '24h Activity', 'hl-core' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'hl-core' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $workflows ) ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No workflows yet.', 'hl-core' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $workflows as $w ) :
                        $wid_activity = $activity_map[ (int) $w->workflow_id ] ?? array();
                        $sent_count   = ( $wid_activity['sent'] ?? 0 );
                        $failed_count = ( $wid_activity['failed'] ?? 0 );
                        $pending_count = ( $wid_activity['pending'] ?? 0 ) + ( $wid_activity['claimed'] ?? 0 );
                        $activity_parts = array();
                        if ( $sent_count > 0 ) $activity_parts[] = "Sent: {$sent_count}";
                        if ( $failed_count > 0 ) $activity_parts[] = "Failed: {$failed_count}";
                        if ( $pending_count > 0 ) $activity_parts[] = "Pending: {$pending_count}";
                        $activity_text = ! empty( $activity_parts ) ? implode( ' | ', $activity_parts ) : '—';
                    ?>
                        <tr data-workflow-id="<?php echo (int) $w->workflow_id; ?>">
                            <td><strong><?php echo esc_html( $w->name ); ?></strong></td>
                            <td><code><?php echo esc_html( $w->trigger_key ); ?></code></td>
                            <td><?php echo esc_html( $w->template_name ?: '—' ); ?></td>
                            <td class="hl-wf-status-cell"><?php $this->render_status_badge( $w->status ); ?></td>
                            <td><?php echo esc_html( $w->updated_at ); ?></td>
                            <td><?php echo esc_html( $activity_text ); ?></td>
                            <td class="hl-row-actions">
                                <?php // Edit link. ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows&action=edit&workflow_id=' . $w->workflow_id ) ); ?>"><?php esc_html_e( 'Edit', 'hl-core' ); ?></a>

                                <?php // Duplicate form (POST). ?>
                                | <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="hl_workflow_duplicate">
                                    <input type="hidden" name="workflow_id" value="<?php echo (int) $w->workflow_id; ?>">
                                    <?php wp_nonce_field( 'hl_workflow_duplicate_' . $w->workflow_id ); ?>
                                    <button type="submit" class="button-link"><?php esc_html_e( 'Duplicate', 'hl-core' ); ?></button>
                                </form>

                                <?php // Activate / Pause toggle (AJAX). ?>
                                | <a href="#"
                                     class="hl-wf-toggle-status button-link"
                                     data-workflow-id="<?php echo (int) $w->workflow_id; ?>"
                                     data-nonce="<?php echo esc_attr( wp_create_nonce( 'hl_workflow_toggle_' . $w->workflow_id ) ); ?>"
                                     data-current="<?php echo esc_attr( $w->status ); ?>"
                                ><?php echo $w->status === 'active' ? esc_html__( 'Pause', 'hl-core' ) : esc_html__( 'Activate', 'hl-core' ); ?></a>

                                <?php // Delete form (POST, with confirm). ?>
                                | <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hl-wf-delete-form" style="display:inline;">
                                    <input type="hidden" name="action" value="hl_workflow_delete">
                                    <input type="hidden" name="workflow_id" value="<?php echo (int) $w->workflow_id; ?>">
                                    <?php wp_nonce_field( 'hl_workflow_delete_' . $w->workflow_id ); ?>
                                    <button type="submit" class="button-link hl-wf-delete-btn" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'hl-core' ); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <script>
        (function($){
            // Confirm before delete.
            $('.hl-wf-delete-form').on('submit', function(e){
                if ( ! confirm('<?php echo esc_js( __( 'Are you sure you want to delete this workflow? Pending emails will be cancelled.', 'hl-core' ) ); ?>') ) {
                    e.preventDefault();
                }
            });

            // AJAX toggle status.
            $('.hl-wf-toggle-status').on('click', function(e){
                e.preventDefault();
                var $link = $(this);
                var wfId  = $link.data('workflow-id');
                var nonce = $link.data('nonce');

                $.post(ajaxurl, {
                    action:      'hl_workflow_toggle_status',
                    workflow_id: wfId,
                    _wpnonce:    nonce
                }, function(response){
                    if ( response.success ) {
                        var ns = response.data.new_status;
                        // Update toggle link text.
                        $link.text( ns === 'active' ? '<?php echo esc_js( __( 'Pause', 'hl-core' ) ); ?>' : '<?php echo esc_js( __( 'Activate', 'hl-core' ) ); ?>' );
                        $link.data('current', ns);
                        // Update status badge in the same row.
                        var $badge = $link.closest('tr').find('.hl-wf-status-cell');
                        $badge.html('<span class="hl-email-badge hl-email-badge--' + ns + '">' + ns + '</span>');
                    } else {
                        alert( response.data || 'Error toggling status.' );
                    }
                });
            });
        })(jQuery);
        </script>

        </div><!-- /.hl-email-admin -->
        <?php
    }

    private function render_workflow_form( $workflow_id ) {
        if ( self::is_v2_ux() ) {
            $this->render_workflow_form_v2( $workflow_id );
            return;
        }

        global $wpdb;

        $workflow = null;
        if ( $workflow_id ) {
            $workflow = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d AND status != 'deleted'",
                $workflow_id
            ) );
        }

        // Task 12 — show all non-archived templates, plus the currently assigned
        // template even if it's archived (with "(archived)" suffix in the UI).
        $current_template_id = $workflow ? (int) $workflow->template_id : 0;
        if ( $current_template_id > 0 ) {
            $templates = $wpdb->get_results( $wpdb->prepare(
                "SELECT template_id, name, status FROM {$wpdb->prefix}hl_email_template
                 WHERE status != 'archived' OR template_id = %d
                 ORDER BY name",
                $current_template_id
            ) );
        } else {
            $templates = $wpdb->get_results(
                "SELECT template_id, name, status FROM {$wpdb->prefix}hl_email_template WHERE status != 'archived' ORDER BY name"
            );
        }

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
                                    'cron:component_upcoming'  => 'Component Due Soon',
                                    'cron:component_overdue'   => 'Component Overdue',
                                    'cron:session_upcoming'    => 'Coaching Session Upcoming',
                                    'cron:coaching_pre_end'    => 'Pre-Cycle-End No Session',
                                    'cron:action_plan_24h'     => 'Action Plan Overdue (24h)',
                                    'cron:session_notes_24h'   => 'Session Notes Overdue (24h)',
                                    'cron:low_engagement_14d'  => 'Low Engagement (14d)',
                                    'cron:client_success'      => 'Client Success Touchpoint',
                                );
                                foreach ( $cron_triggers as $key => $label ) :
                                ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $workflow->trigger_key ?? '', $key ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </td>
                </tr>
                <?php
                // Task 7: Compute current offset value + unit for display.
                $offset_raw   = (int) ( $workflow->trigger_offset_minutes ?? 0 );
                $offset_value = 0;
                $offset_unit  = 'days';
                if ( $offset_raw > 0 ) {
                    if ( $offset_raw % 1440 === 0 ) {
                        $offset_value = $offset_raw / 1440;
                        $offset_unit  = 'days';
                    } elseif ( $offset_raw % 60 === 0 ) {
                        $offset_value = $offset_raw / 60;
                        $offset_unit  = 'hours';
                    } else {
                        $offset_value = $offset_raw;
                        $offset_unit  = 'minutes';
                    }
                }
                ?>
                <tr class="hl-wf-offset-row" style="display:none;">
                    <th scope="row"><?php esc_html_e( 'Offset', 'hl-core' ); ?></th>
                    <td>
                        <input type="number" name="trigger_offset_value" min="1" max="9999" value="<?php echo esc_attr( $offset_value ); ?>" style="width:80px;">
                        <select name="trigger_offset_unit">
                            <option value="minutes" <?php selected( $offset_unit, 'minutes' ); ?>>Minutes</option>
                            <option value="hours" <?php selected( $offset_unit, 'hours' ); ?>>Hours</option>
                            <option value="days" <?php selected( $offset_unit, 'days' ); ?>>Days</option>
                        </select>
                        <p class="description">How far before the anchor date (for "upcoming") or after (for "overdue") to trigger this workflow.</p>
                        <p class="description hl-wf-session-fuzz-note" style="display:none;">
                            Session reminders use a tolerance window to account for cron timing.
                            A "1 hour" reminder fires between ~54-66 minutes before the session.
                        </p>
                    </td>
                </tr>
                <tr class="hl-wf-component-type-row" style="display:none;">
                    <th scope="row"><?php esc_html_e( 'Component Type', 'hl-core' ); ?></th>
                    <td>
                        <select name="component_type_filter">
                            <option value="">All Component Types</option>
                            <option value="learndash_course" <?php selected( $workflow->component_type_filter ?? '', 'learndash_course' ); ?>>Course</option>
                            <option value="coaching_session_attendance" <?php selected( $workflow->component_type_filter ?? '', 'coaching_session_attendance' ); ?>>Coaching Session</option>
                            <option value="classroom_visit" <?php selected( $workflow->component_type_filter ?? '', 'classroom_visit' ); ?>>Classroom Visit</option>
                            <option value="reflective_practice_session" <?php selected( $workflow->component_type_filter ?? '', 'reflective_practice_session' ); ?>>Reflective Practice</option>
                            <option value="self_reflection" <?php selected( $workflow->component_type_filter ?? '', 'self_reflection' ); ?>>Self-Reflection</option>
                            <option value="teacher_self_assessment" <?php selected( $workflow->component_type_filter ?? '', 'teacher_self_assessment' ); ?>>Teacher Assessment</option>
                            <option value="child_assessment" <?php selected( $workflow->component_type_filter ?? '', 'child_assessment' ); ?>>Child Assessment</option>
                        </select>
                    </td>
                </tr>
                <?php
                $trigger_status_val = '';
                if ( $workflow ) {
                    $conds = json_decode( $workflow->conditions, true ) ?: array();
                    foreach ( $conds as $c ) {
                        if ( ( $c['field'] ?? '' ) === 'session.new_status' && ( $c['op'] ?? '' ) === 'eq' ) {
                            $trigger_status_val = $c['value'] ?? '';
                            break;
                        }
                    }
                }
                ?>
                <tr class="hl-wf-status-filter-row" style="display:none;">
                    <th scope="row"><?php esc_html_e( 'Status Filter', 'hl-core' ); ?></th>
                    <td>
                        <select name="trigger_status_filter" id="wf-trigger-status-filter" aria-label="Filter by session status">
                            <option value="" <?php selected( $trigger_status_val, '' ); ?>>Any Status Change</option>
                            <option value="scheduled" <?php selected( $trigger_status_val, 'scheduled' ); ?>>Session Booked</option>
                            <option value="attended" <?php selected( $trigger_status_val, 'attended' ); ?>>Session Attended</option>
                            <option value="cancelled" <?php selected( $trigger_status_val, 'cancelled' ); ?>>Session Cancelled</option>
                            <option value="missed" <?php selected( $trigger_status_val, 'missed' ); ?>>Session Missed</option>
                            <option value="rescheduled" <?php selected( $trigger_status_val, 'rescheduled' ); ?>>Session Rescheduled</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Template', 'hl-core' ); ?></label></th>
                    <td>
                        <select name="template_id">
                            <option value=""><?php esc_html_e( '— Select —', 'hl-core' ); ?></option>
                            <?php foreach ( $templates as $t ) :
                                $label = $t->name;
                                if ( $t->status === 'archived' ) {
                                    $label .= ' (archived)';
                                }
                            ?>
                                <option value="<?php echo (int) $t->template_id; ?>" <?php selected( $workflow->template_id ?? 0, $t->template_id ); ?>><?php echo esc_html( $label ); ?></option>
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
        // Task 14 — Force Resend box (only when editing an existing workflow).
        if ( $workflow && $workflow->workflow_id ) :
            $force_resend_notice = sanitize_text_field( $_GET['hl_notice'] ?? '' );
            $force_resend_count  = (int) ( $_GET['hl_count'] ?? 0 );
        ?>
        <div class="hl-email-admin" style="margin-top:24px;">
            <div style="background:#fff8e1;border:1px solid #f9a825;border-radius:6px;padding:16px 20px;">
                <h3 style="margin:0 0 8px;"><?php esc_html_e( 'Force Resend', 'hl-core' ); ?></h3>
                <p class="description" style="margin:0 0 12px;"><?php esc_html_e( 'Clear dedup tokens on pending queue rows so they become eligible for re-sending. Use with caution — recipients may receive duplicate emails.', 'hl-core' ); ?></p>

                <?php if ( $force_resend_notice === 'force_resend_done' ) : ?>
                    <div class="notice notice-success inline" style="margin:0 0 12px;">
                        <p><?php printf( esc_html__( 'Force resend complete. %d pending row(s) had their dedup token cleared.', 'hl-core' ), $force_resend_count ); ?></p>
                    </div>
                <?php endif; ?>

                <?php
                // Show last force-resend event if available.
                if ( class_exists( 'HL_Audit_Service' ) ) {
                    $last_event = HL_Audit_Service::get_last_event( (int) $workflow->workflow_id, 'workflow_force_resend' );
                    if ( $last_event ) {
                        $actor = ! empty( $last_event['actor_name'] ) ? $last_event['actor_name'] : __( 'Unknown', 'hl-core' );
                        $when  = ! empty( $last_event['created_at'] ) ? $last_event['created_at'] : '—';
                        echo '<p style="font-size:12px;color:#6B7280;margin:0 0 12px;">';
                        printf(
                            esc_html__( 'Last force resend: %1$s by %2$s', 'hl-core' ),
                            esc_html( $when ),
                            esc_html( $actor )
                        );
                        echo '</p>';
                    }
                }
                ?>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hl-force-resend-form">
                    <input type="hidden" name="action" value="hl_workflow_force_resend">
                    <input type="hidden" name="workflow_id" value="<?php echo (int) $workflow->workflow_id; ?>">
                    <?php wp_nonce_field( 'hl_workflow_force_resend_' . $workflow->workflow_id ); ?>

                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <label>
                            <input type="radio" name="scope" value="all_pending" checked>
                            <?php esc_html_e( 'All pending', 'hl-core' ); ?>
                        </label>
                        <label>
                            <input type="radio" name="scope" value="user" id="hl-fr-scope-user">
                            <?php esc_html_e( 'Specific user', 'hl-core' ); ?>
                        </label>
                        <input type="number" name="scope_value" id="hl-fr-user-id" placeholder="<?php esc_attr_e( 'User ID', 'hl-core' ); ?>" min="1" style="width:100px;display:none;">
                        <button type="submit" class="button"><?php esc_html_e( 'Force Resend', 'hl-core' ); ?></button>
                    </div>
                </form>

                <script>
                (function($){
                    var $userInput = $('#hl-fr-user-id');
                    $('input[name="scope"]').on('change', function(){
                        if ( $(this).val() === 'user' ) {
                            $userInput.show().focus();
                        } else {
                            $userInput.hide().val('');
                        }
                    });
                    $('#hl-force-resend-form').on('submit', function(e){
                        var scope = $('input[name="scope"]:checked').val();
                        var msg = scope === 'user'
                            ? '<?php echo esc_js( __( 'Force resend pending emails for this user? They may receive duplicates.', 'hl-core' ) ); ?>'
                            : '<?php echo esc_js( __( 'Force resend ALL pending emails for this workflow? Recipients may receive duplicates.', 'hl-core' ) ); ?>';
                        if ( ! confirm( msg ) ) {
                            e.preventDefault();
                        }
                    });
                })(jQuery);
                </script>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render the v2 workflow form (card-based layout).
     *
     * Delegates from render_workflow_form() when is_v2_ux() is true.
     * Uses the same field names as v1 so handle_workflow_save() works unchanged.
     *
     * @param int $workflow_id Workflow ID (0 for new).
     */
    private function render_workflow_form_v2( $workflow_id ) {
        global $wpdb;

        // ── Data loading (same as v1) ──────────────────────────────────────
        $workflow = null;
        if ( $workflow_id ) {
            $workflow = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d AND status != 'deleted'",
                $workflow_id
            ) );
        }

        // Templates: show all non-archived + currently assigned (even if archived).
        $current_template_id = $workflow ? (int) $workflow->template_id : 0;
        if ( $current_template_id > 0 ) {
            $templates = $wpdb->get_results( $wpdb->prepare(
                "SELECT template_id, name, status FROM {$wpdb->prefix}hl_email_template
                 WHERE status != 'archived' OR template_id = %d
                 ORDER BY name",
                $current_template_id
            ) );
        } else {
            $templates = $wpdb->get_results(
                "SELECT template_id, name, status FROM {$wpdb->prefix}hl_email_template WHERE status != 'archived' ORDER BY name"
            );
        }

        $conditions = array();
        if ( $workflow && ! empty( $workflow->conditions ) ) {
            $conditions = json_decode( $workflow->conditions, true ) ?: array();
        }

        $recipients = array( 'primary' => array(), 'cc' => array() );
        if ( $workflow && ! empty( $workflow->recipients ) ) {
            $recipients = json_decode( $workflow->recipients, true ) ?: $recipients;
        }

        // Compute offset value + unit from trigger_offset_minutes.
        $offset_raw   = (int) ( $workflow->trigger_offset_minutes ?? 0 );
        $offset_value = 0;
        $offset_unit  = 'days';
        if ( $offset_raw > 0 ) {
            if ( $offset_raw % 1440 === 0 ) {
                $offset_value = $offset_raw / 1440;
                $offset_unit  = 'days';
            } elseif ( $offset_raw % 60 === 0 ) {
                $offset_value = $offset_raw / 60;
                $offset_unit  = 'hours';
            } else {
                $offset_value = $offset_raw;
                $offset_unit  = 'minutes';
            }
        }

        // Extract trigger status sub-filter from conditions.
        $trigger_status_val = '';
        if ( $workflow ) {
            $conds_check = json_decode( $workflow->conditions, true ) ?: array();
            foreach ( $conds_check as $c ) {
                if ( ( $c['field'] ?? '' ) === 'session.new_status' && ( $c['op'] ?? '' ) === 'eq' ) {
                    $trigger_status_val = $c['value'] ?? '';
                    break;
                }
            }
        }

        $wf_name   = $workflow->name ?? '';
        $wf_status = $workflow->status ?? 'draft';

        // ── Top Bar ────────────────────────────────────────────────────────
        ?>
        <div class="hl-wf-topbar">
            <div class="hl-wf-topbar-left">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows' ) ); ?>">&larr; All Workflows</a>
                <span class="hl-wf-topbar-name"><?php echo esc_html( $wf_name ?: __( 'New Workflow', 'hl-core' ) ); ?></span>
            </div>
            <div class="hl-wf-topbar-right">
                <span class="hl-wf-status-badge hl-wf-status-<?php echo esc_attr( $wf_status ); ?>"><?php echo esc_html( ucfirst( $wf_status ) ); ?></span>
                <button type="submit" form="hl-wf-form-v2" name="save_action" value="draft" class="hl-wf-btn hl-wf-btn-secondary">Save Draft</button>
                <button type="submit" form="hl-wf-form-v2" name="save_action" value="activate" class="hl-wf-btn hl-wf-btn-activate">Activate</button>
            </div>
        </div>

        <?php // ── Form wrapper ─────────────────────────────────────────────── ?>
        <form id="hl-wf-form-v2" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=workflows' ) ); ?>" class="hl-workflow-form">
            <?php wp_nonce_field( 'hl_workflow_save', 'hl_workflow_nonce' ); ?>
            <input type="hidden" name="workflow_id" value="<?php echo (int) $workflow_id; ?>">
            <input type="hidden" name="status" id="hl-wf-status-field" value="<?php echo esc_attr( $wf_status ); ?>">

            <div class="hl-wf-layout">
                <div class="hl-wf-form-panel">

                    <?php // ── Card 1: Basics ──────────────────────────────────── ?>
                    <div class="hl-wf-card">
                        <div class="hl-wf-card-header">
                            <div class="hl-wf-card-title"><?php esc_html_e( 'Basics', 'hl-core' ); ?></div>
                        </div>
                        <div class="hl-wf-card-body">
                            <div class="hl-wf-form-row">
                                <label class="hl-wf-form-label"><?php esc_html_e( 'Workflow Name', 'hl-core' ); ?></label>
                                <input type="text" name="name" class="hl-wf-form-input" value="<?php echo esc_attr( $wf_name ); ?>" required placeholder="<?php esc_attr_e( 'e.g., Coaching Session Reminder — 7 Days', 'hl-core' ); ?>">
                            </div>
                        </div>
                    </div>

                    <?php // ── Card 2: Trigger ─────────────────────────────────── ?>
                    <div class="hl-wf-card">
                        <div class="hl-wf-card-header">
                            <div>
                                <div class="hl-wf-card-title"><?php esc_html_e( 'Trigger', 'hl-core' ); ?></div>
                                <div class="hl-wf-card-subtitle"><?php esc_html_e( 'What event causes this email to be sent?', 'hl-core' ); ?></div>
                            </div>
                            <span class="hl-wf-card-badge hl-wf-badge-required"><?php esc_html_e( 'Required', 'hl-core' ); ?></span>
                        </div>
                        <div class="hl-wf-card-body">
                            <div class="hl-wf-form-row">
                                <label class="hl-wf-form-label"><?php esc_html_e( 'Event', 'hl-core' ); ?></label>
                                <select name="trigger_key" class="hl-wf-form-input" required>
                                    <option value=""><?php esc_html_e( '— Select Trigger —', 'hl-core' ); ?></option>
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
                                            'cron:component_upcoming'  => 'Component Due Soon',
                                            'cron:component_overdue'   => 'Component Overdue',
                                            'cron:session_upcoming'    => 'Coaching Session Upcoming',
                                            'cron:coaching_pre_end'    => 'Pre-Cycle-End No Session',
                                            'cron:action_plan_24h'     => 'Action Plan Overdue (24h)',
                                            'cron:session_notes_24h'   => 'Session Notes Overdue (24h)',
                                            'cron:low_engagement_14d'  => 'Low Engagement (14d)',
                                            'cron:client_success'      => 'Client Success Touchpoint',
                                        );
                                        foreach ( $cron_triggers as $key => $label ) :
                                        ?>
                                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $workflow->trigger_key ?? '', $key ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                            </div>

                            <?php // Offset row — shown/hidden by JS based on trigger selection. ?>
                            <div class="hl-wf-form-row hl-wf-offset-row" style="display:none;">
                                <label class="hl-wf-form-label"><?php esc_html_e( 'Offset', 'hl-core' ); ?></label>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <input type="number" name="trigger_offset_value" min="1" max="9999" value="<?php echo esc_attr( $offset_value ); ?>" class="hl-wf-form-input" style="width:100px;">
                                    <select name="trigger_offset_unit" class="hl-wf-form-input" style="width:auto;">
                                        <option value="minutes" <?php selected( $offset_unit, 'minutes' ); ?>><?php esc_html_e( 'Minutes', 'hl-core' ); ?></option>
                                        <option value="hours" <?php selected( $offset_unit, 'hours' ); ?>><?php esc_html_e( 'Hours', 'hl-core' ); ?></option>
                                        <option value="days" <?php selected( $offset_unit, 'days' ); ?>><?php esc_html_e( 'Days', 'hl-core' ); ?></option>
                                    </select>
                                </div>
                                <p class="hl-wf-form-hint"><?php esc_html_e( 'How far before the anchor date (for "upcoming") or after (for "overdue") to trigger this workflow.', 'hl-core' ); ?></p>
                                <p class="hl-wf-form-hint hl-wf-session-fuzz-note" style="display:none;">
                                    <?php esc_html_e( 'Session reminders use a tolerance window to account for cron timing. A "1 hour" reminder fires between ~54-66 minutes before the session.', 'hl-core' ); ?>
                                </p>
                            </div>

                            <?php // Component type row — shown/hidden by JS. ?>
                            <div class="hl-wf-form-row hl-wf-component-type-row" style="display:none;">
                                <label class="hl-wf-form-label"><?php esc_html_e( 'Component Type', 'hl-core' ); ?></label>
                                <select name="component_type_filter" class="hl-wf-form-input">
                                    <option value=""><?php esc_html_e( 'All Component Types', 'hl-core' ); ?></option>
                                    <option value="learndash_course" <?php selected( $workflow->component_type_filter ?? '', 'learndash_course' ); ?>><?php esc_html_e( 'Course', 'hl-core' ); ?></option>
                                    <option value="coaching_session_attendance" <?php selected( $workflow->component_type_filter ?? '', 'coaching_session_attendance' ); ?>><?php esc_html_e( 'Coaching Session', 'hl-core' ); ?></option>
                                    <option value="classroom_visit" <?php selected( $workflow->component_type_filter ?? '', 'classroom_visit' ); ?>><?php esc_html_e( 'Classroom Visit', 'hl-core' ); ?></option>
                                    <option value="reflective_practice_session" <?php selected( $workflow->component_type_filter ?? '', 'reflective_practice_session' ); ?>><?php esc_html_e( 'Reflective Practice', 'hl-core' ); ?></option>
                                    <option value="self_reflection" <?php selected( $workflow->component_type_filter ?? '', 'self_reflection' ); ?>><?php esc_html_e( 'Self-Reflection', 'hl-core' ); ?></option>
                                    <option value="teacher_self_assessment" <?php selected( $workflow->component_type_filter ?? '', 'teacher_self_assessment' ); ?>><?php esc_html_e( 'Teacher Assessment', 'hl-core' ); ?></option>
                                    <option value="child_assessment" <?php selected( $workflow->component_type_filter ?? '', 'child_assessment' ); ?>><?php esc_html_e( 'Child Assessment', 'hl-core' ); ?></option>
                                </select>
                            </div>

                            <?php // Status filter row — shown/hidden by JS for coaching/RP triggers. ?>
                            <div class="hl-wf-form-row hl-wf-status-filter-row" style="display:none;">
                                <label class="hl-wf-form-label"><?php esc_html_e( 'Status Filter', 'hl-core' ); ?></label>
                                <select name="trigger_status_filter" id="wf-trigger-status-filter" class="hl-wf-form-input" aria-label="<?php esc_attr_e( 'Filter by session status', 'hl-core' ); ?>">
                                    <option value="" <?php selected( $trigger_status_val, '' ); ?>><?php esc_html_e( 'Any Status Change', 'hl-core' ); ?></option>
                                    <option value="scheduled" <?php selected( $trigger_status_val, 'scheduled' ); ?>><?php esc_html_e( 'Session Booked', 'hl-core' ); ?></option>
                                    <option value="attended" <?php selected( $trigger_status_val, 'attended' ); ?>><?php esc_html_e( 'Session Attended', 'hl-core' ); ?></option>
                                    <option value="cancelled" <?php selected( $trigger_status_val, 'cancelled' ); ?>><?php esc_html_e( 'Session Cancelled', 'hl-core' ); ?></option>
                                    <option value="missed" <?php selected( $trigger_status_val, 'missed' ); ?>><?php esc_html_e( 'Session Missed', 'hl-core' ); ?></option>
                                    <option value="rescheduled" <?php selected( $trigger_status_val, 'rescheduled' ); ?>><?php esc_html_e( 'Session Rescheduled', 'hl-core' ); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <?php // Cards 3-5 will be added in Task 5b. ?>

                </div><!-- /.hl-wf-form-panel -->

                <div class="hl-wf-summary-panel">
                    <!-- Summary panel content will come in Task 5b -->
                    <p style="color:#9CA3AF;font-style:italic;"><?php esc_html_e( 'Summary panel — coming soon', 'hl-core' ); ?></p>
                </div>
            </div><!-- /.hl-wf-layout -->

            <?php // ── Hidden textareas for conditions + recipients (JS serialization targets). ?>
            <textarea name="conditions" style="display:none;"><?php echo esc_textarea( wp_json_encode( $conditions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
            <textarea name="recipients" style="display:none;"><?php echo esc_textarea( wp_json_encode( $recipients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>

            <?php // ── Hidden inputs for Card 3-5 fields (replaced with card UI in Task 5b). ?>
            <input type="hidden" name="template_id" value="<?php echo (int) ( $workflow->template_id ?? 0 ); ?>">
            <input type="hidden" name="delay_minutes" value="<?php echo (int) ( $workflow->delay_minutes ?? 0 ); ?>">
            <input type="hidden" name="send_window_start" value="<?php echo esc_attr( $workflow->send_window_start ?? '' ); ?>">
            <input type="hidden" name="send_window_end" value="<?php echo esc_attr( $workflow->send_window_end ?? '' ); ?>">
            <input type="hidden" name="send_window_days" value="<?php echo esc_attr( $workflow->send_window_days ?? '' ); ?>">
        </form>

        <script>
        (function($){
            // Bridge save_action buttons to the hidden status field.
            $('#hl-wf-form-v2').on('click', '[name="save_action"]', function(){
                var action = $(this).val();
                var status = action === 'activate' ? 'active' : 'draft';
                $('#hl-wf-status-field').val( status );
            });
        })(jQuery);
        </script>
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

        // Task 7: persist trigger offset + component type filter.
        $offset_value = absint( $_POST['trigger_offset_value'] ?? 0 );
        $offset_unit  = sanitize_text_field( $_POST['trigger_offset_unit'] ?? 'days' );

        $multiplier = array( 'minutes' => 1, 'hours' => 60, 'days' => 1440 );
        $mult       = $multiplier[ $offset_unit ] ?? 1440;
        $data['trigger_offset_minutes'] = $offset_value > 0 ? $offset_value * $mult : null;

        $data['component_type_filter'] = sanitize_text_field( $_POST['component_type_filter'] ?? '' ) ?: null;

        // Task 8 (A.1): persist trigger status sub-filter for coaching/RP session triggers.
        $status_filter = sanitize_text_field( $_POST['trigger_status_filter'] ?? '' );
        if ( $status_filter !== '' && in_array( $data['trigger_key'], array( 'hl_coaching_session_status_changed', 'hl_rp_session_status_changed' ), true ) ) {
            $conditions = array_filter( $conditions, function ( $c ) {
                return ( $c['field'] ?? '' ) !== 'session.new_status';
            } );
            $conditions[] = array(
                'field' => 'session.new_status',
                'op'    => 'eq',
                'value' => $status_filter,
            );
            $data['conditions'] = wp_json_encode( array_values( $conditions ) );
        }

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
            // New generic keys (v2):
            'cron:component_upcoming', 'cron:component_overdue', 'cron:session_upcoming',
            // Retained non-offset keys:
            'cron:coaching_pre_end',
            'cron:action_plan_24h', 'cron:session_notes_24h', 'cron:low_engagement_14d',
            'cron:client_success',
            // Legacy aliases — kept for backward compat until next release:
            'cron:cv_window_7d', 'cron:cv_overdue_1d', 'cron:rp_window_7d',
            'cron:coaching_window_7d', 'cron:coaching_session_5d',
            'cron:session_24h', 'cron:session_1h',
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
        <div class="hl-email-admin">

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
                            <td class="hl-row-actions">
                                <?php // Edit link. ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=builder&template_id=' . $t->template_id ) ); ?>"><?php esc_html_e( 'Edit', 'hl-core' ); ?></a>

                                <?php // Duplicate form (POST). ?>
                                | <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="hl_template_duplicate">
                                    <input type="hidden" name="template_id" value="<?php echo (int) $t->template_id; ?>">
                                    <?php wp_nonce_field( 'hl_template_duplicate_' . $t->template_id ); ?>
                                    <button type="submit" class="button-link"><?php esc_html_e( 'Duplicate', 'hl-core' ); ?></button>
                                </form>

                                <?php // Archive / Restore form (POST). ?>
                                | <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="hl_template_archive">
                                    <input type="hidden" name="template_id" value="<?php echo (int) $t->template_id; ?>">
                                    <?php wp_nonce_field( 'hl_template_archive_' . $t->template_id ); ?>
                                    <button type="submit" class="button-link"><?php echo $t->status === 'archived' ? esc_html__( 'Restore', 'hl-core' ) : esc_html__( 'Archive', 'hl-core' ); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        </div><!-- /.hl-email-admin -->
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

    /**
     * Duplicate a workflow via admin-post.php (POST).
     * A.2.13 — copies row, generates "(Copy)" name, sets status to draft.
     */
    public function handle_workflow_duplicate() {
        $workflow_id = (int) ( $_POST['workflow_id'] ?? 0 );

        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'hl_workflow_duplicate_' . $workflow_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;
        $table  = "{$wpdb->prefix}hl_email_workflow";
        $source = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE workflow_id = %d AND status != 'deleted'",
            $workflow_id
        ), ARRAY_A );

        if ( ! $source ) {
            wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&hl_notice=not_found' ) );
            exit;
        }

        // Remove keys that should not be copied.
        unset( $source['workflow_id'], $source['created_at'], $source['updated_at'] );

        $source['name']   = self::generate_copy_name( 'hl_email_workflow', $source['name'] );
        $source['status'] = 'draft';

        $wpdb->insert( $table, $source );
        $new_id = (int) $wpdb->insert_id;

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_workflow_duplicated', array(
                'source_workflow_id' => $workflow_id,
                'new_workflow_id'    => $new_id,
            ) );
        }

        wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&action=edit&workflow_id=' . $new_id ) );
        exit;
    }

    /**
     * Soft-delete a workflow via admin-post.php (POST).
     * A.2.13 — uses transaction to block deletion if sent/sending/failed queue rows exist.
     */
    public function handle_workflow_delete() {
        $workflow_id = (int) ( $_POST['workflow_id'] ?? 0 );

        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'hl_workflow_delete_' . $workflow_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_workflow";
        $queue = "{$wpdb->prefix}hl_email_queue";

        $wpdb->query( 'START TRANSACTION' );

        $blocked_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue}
             WHERE workflow_id = %d AND status IN ('sent','sending','failed')
             FOR UPDATE",
            $workflow_id
        ) );

        if ( $blocked_count > 0 ) {
            $wpdb->query( 'ROLLBACK' );
            wp_redirect( add_query_arg( array(
                'page'      => 'hl-emails',
                'tab'       => 'workflows',
                'hl_notice' => 'delete_blocked',
                'hl_count'  => $blocked_count,
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // Soft-delete the workflow.
        $wpdb->update( $table, array( 'status' => 'deleted' ), array( 'workflow_id' => $workflow_id ) );

        // Cancel any pending queue rows for this workflow.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$queue} SET status = 'cancelled'
             WHERE workflow_id = %d AND status IN ('pending','rate_limited')",
            $workflow_id
        ) );

        $wpdb->query( 'COMMIT' );

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_workflow_deleted', array(
                'workflow_id' => $workflow_id,
            ) );
        }

        wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=workflows&hl_notice=workflow_deleted' ) );
        exit;
    }

    /**
     * AJAX toggle workflow status: active↔paused.
     * A.2.13 — per-ID nonce, returns new status in JSON.
     */
    public function ajax_workflow_toggle_status() {
        $workflow_id = (int) ( $_POST['workflow_id'] ?? 0 );

        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'hl_workflow_toggle_' . $workflow_id ) ) {
            wp_send_json_error( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table  = "{$wpdb->prefix}hl_email_workflow";
        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE workflow_id = %d",
            $workflow_id
        ) );

        if ( $current === null ) {
            wp_send_json_error( 'Workflow not found.' );
        }

        // Flip: active→paused, paused→active, anything else→active.
        $new_status = ( $current === 'active' ) ? 'paused' : 'active';

        $wpdb->update( $table, array( 'status' => $new_status ), array( 'workflow_id' => $workflow_id ) );

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_workflow_status_toggled', array(
                'workflow_id' => $workflow_id,
                'old_status'  => $current,
                'new_status'  => $new_status,
            ) );
        }

        wp_send_json_success( array( 'new_status' => $new_status ) );
    }

    /**
     * Duplicate a template via admin-post.php (POST).
     * Task 12 — copies row, generates "(Copy)" name + unique template_key, sets status to draft.
     */
    public function handle_template_duplicate() {
        $template_id = (int) ( $_POST['template_id'] ?? 0 );

        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'hl_template_duplicate_' . $template_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;
        $table  = "{$wpdb->prefix}hl_email_template";
        $source = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE template_id = %d",
            $template_id
        ), ARRAY_A );

        if ( ! $source ) {
            wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=templates&hl_notice=not_found' ) );
            exit;
        }

        // Remove keys that should not be copied.
        unset( $source['template_id'], $source['created_at'], $source['updated_at'] );

        $source['name']   = self::generate_copy_name( 'hl_email_template', $source['name'] );
        $source['status'] = 'draft';

        // Generate a unique template_key.
        $base_key = preg_replace( '/_copy\d*$/', '', $source['template_key'] );
        $unique_key = null;
        for ( $i = 1; $i <= 100; $i++ ) {
            $candidate = $i === 1 ? $base_key . '_copy' : $base_key . '_copy' . $i;
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE template_key = %s",
                $candidate
            ) );
            if ( $exists === 0 ) {
                $unique_key = $candidate;
                break;
            }
        }
        if ( $unique_key === null ) {
            $unique_key = $base_key . '_' . substr( wp_generate_uuid4(), 0, 8 );
        }
        $source['template_key'] = $unique_key;

        $wpdb->insert( $table, $source );
        $new_id = (int) $wpdb->insert_id;

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_template_duplicated', array(
                'entity_type'        => 'email_template',
                'entity_id'          => $new_id,
                'source_template_id' => $template_id,
                'new_template_id'    => $new_id,
            ) );
        }

        wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=builder&template_id=' . $new_id ) );
        exit;
    }

    /**
     * Archive or restore a template via admin-post.php (POST).
     * Task 12 — flips status: archived→draft, anything else→archived.
     */
    public function handle_template_archive() {
        $template_id = (int) ( $_POST['template_id'] ?? 0 );

        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'hl_template_archive_' . $template_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;
        $table   = "{$wpdb->prefix}hl_email_template";
        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE template_id = %d",
            $template_id
        ) );

        if ( $current === null ) {
            wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=templates&hl_notice=not_found' ) );
            exit;
        }

        // Flip: archived→draft, anything else→archived.
        $new_status  = ( $current === 'archived' ) ? 'draft' : 'archived';
        $action_type = ( $new_status === 'archived' ) ? 'email_template_archived' : 'email_template_restored';

        $wpdb->update( $table, array( 'status' => $new_status ), array( 'template_id' => $template_id ) );

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( $action_type, array(
                'entity_type' => 'email_template',
                'entity_id'   => $template_id,
                'old_status'  => $current,
                'new_status'  => $new_status,
            ) );
        }

        wp_redirect( admin_url( 'admin.php?page=hl-emails&tab=templates&hl_notice=template_' . $new_status ) );
        exit;
    }

    /**
     * Force resend pending emails for a workflow via admin-post.php (POST).
     * Task 14 — clears dedup_token on matching pending queue rows so they are re-eligible.
     */
    public function handle_workflow_force_resend() {
        $workflow_id = (int) ( $_POST['workflow_id'] ?? 0 );

        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'hl_workflow_force_resend_' . $workflow_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;
        $queue = "{$wpdb->prefix}hl_email_queue";

        $scope       = sanitize_text_field( $_POST['scope'] ?? 'all_pending' );
        $scope_value = sanitize_text_field( $_POST['scope_value'] ?? '' );

        // Build WHERE clause.
        if ( $scope === 'user' && (int) $scope_value > 0 ) {
            $where = $wpdb->prepare(
                "WHERE workflow_id = %d AND status = 'pending' AND recipient_user_id = %d",
                $workflow_id,
                (int) $scope_value
            );
        } else {
            $scope       = 'all_pending';
            $scope_value = '';
            $where = $wpdb->prepare(
                "WHERE workflow_id = %d AND status = 'pending'",
                $workflow_id
            );
        }

        // Count matching rows.
        $affected = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} {$where}" );

        // Clear dedup_token to allow re-send.
        if ( $affected > 0 ) {
            $wpdb->query( "UPDATE {$queue} SET dedup_token = NULL {$where}" );
        }

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'workflow_force_resend', array(
                'entity_type' => 'email_workflow',
                'entity_id'   => $workflow_id,
                'after_data'  => array(
                    'workflow_id'  => $workflow_id,
                    'scope'        => $scope,
                    'scope_value'  => $scope_value,
                    'affected'     => $affected,
                ),
            ) );
        }

        wp_redirect( add_query_arg( array(
            'page'      => 'hl-emails',
            'tab'       => 'workflows',
            'action'    => 'edit',
            'workflow_id' => $workflow_id,
            'hl_notice' => 'force_resend_done',
            'hl_count'  => $affected,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function render_status_badge( $status ) {
        echo '<span class="hl-email-badge hl-email-badge--' . esc_attr( $status ) . '">' . esc_html( $status ) . '</span>';
    }
}
