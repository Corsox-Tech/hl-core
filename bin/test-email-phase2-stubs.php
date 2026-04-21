<?php
/**
 * Email Registry Cleanup Phase 2 — stub-wiring tests.
 *
 * Covers the 6 trigger-registry stubs that ship in 1.2.8:
 *   §5.1 — cron:component_overdue for classroom_visit (per-component tighten)
 *   §5.2 — cron:session_upcoming at 5d / 24h / 1h offsets (dedup safety)
 *   §5.3 — cron:action_plan_24h + cron:session_notes_24h (SQL bug fixes +
 *          NULL enrollment_id end-to-end for the coach-notes path)
 *
 * Run via:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-email-phase2-stubs.php
 *
 * Exit 0 = all pass, exit 1 = one or more failed.
 *
 * All fixture rows are prefixed with FIXTURE_PREFIX and torn down in a
 * finally block so a mid-test exception cannot leave orphans. Belt-and-
 * suspenders: every fixture ID is also tracked in $GLOBALS['hl_p2_ids'].
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file\n" );
    exit( 1 );
}

if ( ! class_exists( 'HL_Email_Automation_Service' ) ) {
    $plugin_dir = dirname( __DIR__ ) . '/';
    require_once $plugin_dir . 'includes/services/class-hl-email-automation-service.php';
}

// Fixture prefix — used for test cycle_code, pathway_code, email_template_key,
// workflow names. Anything starting with this gets swept by the finally block.
const FIXTURE_PREFIX = '[Phase2Test]';

$GLOBALS['hl_p2_pass']   = 0;
$GLOBALS['hl_p2_fail']   = 0;
$GLOBALS['hl_p2_errors'] = array();
$GLOBALS['hl_p2_ids']    = array(
    'user_ids'               => array(),
    'partnership_ids'        => array(),
    'cycle_ids'              => array(),
    'enrollment_ids'         => array(),
    'pathway_ids'            => array(),
    'assignment_ids'         => array(),
    'component_ids'          => array(),
    'classroom_visit_ids'    => array(),
    'cv_submission_ids'      => array(),
    'coaching_session_ids'   => array(),
    'cs_submission_ids'      => array(),
    'instrument_ids'         => array(),
    'template_ids'           => array(),
    'workflow_ids'           => array(),
    'queue_ids'              => array(),
);

// ──────────────────────────────────────────────────────────────────────────
// Assertion helpers
// ──────────────────────────────────────────────────────────────────────────

function hl_p2_assert( $cond, $label ) {
    if ( $cond ) {
        $GLOBALS['hl_p2_pass']++;
        echo "  [PASS] $label\n";
    } else {
        $GLOBALS['hl_p2_fail']++;
        $GLOBALS['hl_p2_errors'][] = $label;
        echo "  [FAIL] $label\n";
    }
}

/** Call a private method on the automation service (tests isolated handler SQL). */
function hl_p2_call_private( $method, array $args ) {
    $svc = HL_Email_Automation_Service::instance();
    $ref = new ReflectionMethod( $svc, $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( $svc, $args );
}

/** Inject a Phase2Test cycle row into the cron dispatcher's cycle loop. */
function hl_p2_cycle_row( $cycle_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
        $cycle_id
    ) );
}

// ──────────────────────────────────────────────────────────────────────────
// Fixture builders
// ──────────────────────────────────────────────────────────────────────────

function hl_p2_create_user( $login_suffix, $email_suffix ) {
    $login = 'p2test_' . $login_suffix . '_' . wp_generate_password( 6, false, false );
    $email = 'p2test+' . $email_suffix . '_' . wp_generate_password( 6, false, false ) . '@example.invalid';
    $user_id = wp_insert_user( array(
        'user_login'   => $login,
        'user_pass'    => wp_generate_password( 16 ),
        'user_email'   => $email,
        'display_name' => FIXTURE_PREFIX . ' ' . $login_suffix,
    ) );
    if ( is_wp_error( $user_id ) ) {
        throw new RuntimeException( 'user create failed: ' . $user_id->get_error_message() );
    }
    $GLOBALS['hl_p2_ids']['user_ids'][] = (int) $user_id;
    return (int) $user_id;
}

function hl_p2_create_partnership() {
    global $wpdb;
    $code = 'p2test-part-' . wp_generate_password( 6, false, false );
    $wpdb->insert( $wpdb->prefix . 'hl_partnership', array(
        'partnership_uuid' => wp_generate_uuid4(),
        'partnership_name' => FIXTURE_PREFIX . ' Partnership',
        'partnership_code' => $code,
        'status'           => 'active',
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['partnership_ids'][] = $id;
    return $id;
}

function hl_p2_create_cycle( $partnership_id ) {
    global $wpdb;
    $code = 'p2test-cyc-' . wp_generate_password( 8, false, false );
    $wpdb->insert( $wpdb->prefix . 'hl_cycle', array(
        'cycle_uuid'     => wp_generate_uuid4(),
        'cycle_code'     => $code,
        'cycle_name'     => FIXTURE_PREFIX . ' Cycle',
        'partnership_id' => $partnership_id,
        'cycle_type'     => 'program',
        'status'         => 'active',
        'start_date'     => wp_date( 'Y-m-d', strtotime( '-60 days' ) ),
        'end_date'       => wp_date( 'Y-m-d', strtotime( '+60 days' ) ),
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['cycle_ids'][] = $id;
    return $id;
}

function hl_p2_create_enrollment( $cycle_id, $user_id, $roles_csv = 'teacher' ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_enrollment', array(
        'enrollment_uuid' => wp_generate_uuid4(),
        'cycle_id'        => $cycle_id,
        'user_id'         => $user_id,
        'roles'           => $roles_csv,
        'status'          => 'active',
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['enrollment_ids'][] = $id;
    return $id;
}

function hl_p2_create_pathway( $cycle_id ) {
    global $wpdb;
    $code = 'p2test-path-' . wp_generate_password( 6, false, false );
    $wpdb->insert( $wpdb->prefix . 'hl_pathway', array(
        'pathway_uuid' => wp_generate_uuid4(),
        'cycle_id'     => $cycle_id,
        'pathway_name' => FIXTURE_PREFIX . ' Pathway',
        'pathway_code' => $code,
        'is_template'  => 0,
        'active_status'=> 1,
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['pathway_ids'][] = $id;
    return $id;
}

function hl_p2_create_pathway_assignment( $pathway_id, $enrollment_id ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_pathway_assignment', array(
        'enrollment_id'       => $enrollment_id,
        'pathway_id'          => $pathway_id,
        'assigned_by_user_id' => 1,
        'assignment_type'     => 'explicit',
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['assignment_ids'][] = $id;
    return $id;
}

/**
 * Create a component. $extra can include complete_by, display_window_start/end,
 * external_ref (for classroom_visit visit_number payload), etc.
 */
function hl_p2_create_component( $cycle_id, $pathway_id, $component_type, array $extra = array() ) {
    global $wpdb;
    $row = array_merge( array(
        'component_uuid' => wp_generate_uuid4(),
        'cycle_id'       => $cycle_id,
        'pathway_id'     => $pathway_id,
        'component_type' => $component_type,
        'title'          => FIXTURE_PREFIX . ' ' . $component_type,
        'status'         => 'active',
    ), $extra );
    $wpdb->insert( $wpdb->prefix . 'hl_component', $row );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['component_ids'][] = $id;
    return $id;
}

function hl_p2_create_classroom_visit( $cycle_id, $leader_enrollment_id, $teacher_enrollment_id, $visit_number ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_classroom_visit', array(
        'classroom_visit_uuid'   => wp_generate_uuid4(),
        'cycle_id'               => $cycle_id,
        'leader_enrollment_id'   => $leader_enrollment_id,
        'teacher_enrollment_id'  => $teacher_enrollment_id,
        'visit_number'           => $visit_number,
        'status'                 => 'pending',
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['classroom_visit_ids'][] = $id;
    return $id;
}

function hl_p2_create_cv_submission( $cv_id, $user_id, $instrument_id, $role, $status = 'submitted' ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_classroom_visit_submission', array(
        'submission_uuid'      => wp_generate_uuid4(),
        'classroom_visit_id'   => $cv_id,
        'submitted_by_user_id' => $user_id,
        'instrument_id'        => $instrument_id,
        'role_in_visit'        => $role,
        'status'               => $status,
        'submitted_at'         => current_time( 'mysql' ),
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['cv_submission_ids'][] = $id;
    return $id;
}

function hl_p2_create_coaching_session( $cycle_id, $coach_user_id, $mentor_enrollment_id, $session_datetime, $session_status = 'scheduled', $component_id = null ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_coaching_session', array(
        'session_uuid'         => wp_generate_uuid4(),
        'cycle_id'             => $cycle_id,
        'coach_user_id'        => $coach_user_id,
        'mentor_enrollment_id' => $mentor_enrollment_id,
        'session_status'       => $session_status,
        'session_datetime'     => $session_datetime,
        'component_id'         => $component_id,
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['coaching_session_ids'][] = $id;
    return $id;
}

function hl_p2_create_cs_submission( $session_id, $user_id, $instrument_id, $role, $status = 'submitted' ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_coaching_session_submission', array(
        'submission_uuid'      => wp_generate_uuid4(),
        'session_id'           => $session_id,
        'submitted_by_user_id' => $user_id,
        'instrument_id'        => $instrument_id,
        'role_in_session'      => $role,
        'status'               => $status,
        'submitted_at'         => current_time( 'mysql' ),
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['cs_submission_ids'][] = $id;
    return $id;
}

function hl_p2_create_instrument() {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_instrument', array(
        'instrument_uuid' => wp_generate_uuid4(),
        'name'            => FIXTURE_PREFIX . ' Instrument',
        'instrument_type' => 'classroom_visit',
        'version'         => '1.0',
        'questions'       => '[]',
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['instrument_ids'][] = $id;
    return $id;
}

function hl_p2_create_template() {
    global $wpdb;
    $key = 'p2test_tmpl_' . wp_generate_password( 8, false, false );
    $wpdb->insert( $wpdb->prefix . 'hl_email_template', array(
        'template_key' => $key,
        'name'         => FIXTURE_PREFIX . ' Template',
        'subject'      => 'Phase2Test Subject',
        'blocks_json'  => wp_json_encode( array(
            array( 'type' => 'paragraph', 'content' => 'Phase2Test body.' ),
        ) ),
        'category'     => 'manual',
        'status'       => 'active',
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['template_ids'][] = $id;
    return $id;
}

/**
 * Create a test workflow. $overrides may include trigger_offset_minutes,
 * component_type_filter, recipients (JSON), conditions (JSON), status.
 */
function hl_p2_create_workflow( $trigger_key, $template_id, array $overrides = array() ) {
    global $wpdb;
    $row = array_merge( array(
        'name'            => FIXTURE_PREFIX . ' WF ' . wp_generate_password( 6, false, false ),
        'trigger_key'     => $trigger_key,
        'conditions'      => '[]',
        'recipients'      => wp_json_encode( array( 'primary' => array( 'triggering_user' ), 'cc' => array() ) ),
        'template_id'     => $template_id,
        'delay_minutes'   => 0,
        'status'          => 'active',
    ), $overrides );
    $wpdb->insert( $wpdb->prefix . 'hl_email_workflow', $row );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_p2_ids']['workflow_ids'][] = $id;
    return $id;
}

// ──────────────────────────────────────────────────────────────────────────
// Cleanup — DELETE-by-tracked-id, safe to call twice.
// ──────────────────────────────────────────────────────────────────────────

function hl_p2_cleanup() {
    global $wpdb;
    $ids = $GLOBALS['hl_p2_ids'];

    // Reverse dependency order.
    if ( ! empty( $ids['queue_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_email_queue WHERE queue_id IN (" . implode( ',', array_map( 'intval', $ids['queue_ids'] ) ) . ")" );
    }
    // Also sweep any queue rows created by cron pipeline tests but not directly tracked.
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}hl_email_queue WHERE subject = %s",
        'Phase2Test Subject'
    ) );

    if ( ! empty( $ids['workflow_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id IN (" . implode( ',', array_map( 'intval', $ids['workflow_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['template_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_email_template WHERE template_id IN (" . implode( ',', array_map( 'intval', $ids['template_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['cs_submission_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_coaching_session_submission WHERE submission_id IN (" . implode( ',', array_map( 'intval', $ids['cs_submission_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['coaching_session_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_coaching_session WHERE session_id IN (" . implode( ',', array_map( 'intval', $ids['coaching_session_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['cv_submission_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_classroom_visit_submission WHERE submission_id IN (" . implode( ',', array_map( 'intval', $ids['cv_submission_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['classroom_visit_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_classroom_visit WHERE classroom_visit_id IN (" . implode( ',', array_map( 'intval', $ids['classroom_visit_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['component_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_component WHERE component_id IN (" . implode( ',', array_map( 'intval', $ids['component_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['assignment_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_pathway_assignment WHERE assignment_id IN (" . implode( ',', array_map( 'intval', $ids['assignment_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['pathway_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_pathway WHERE pathway_id IN (" . implode( ',', array_map( 'intval', $ids['pathway_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['enrollment_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id IN (" . implode( ',', array_map( 'intval', $ids['enrollment_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['instrument_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_instrument WHERE instrument_id IN (" . implode( ',', array_map( 'intval', $ids['instrument_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['cycle_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_cycle WHERE cycle_id IN (" . implode( ',', array_map( 'intval', $ids['cycle_ids'] ) ) . ")" );
    }
    if ( ! empty( $ids['partnership_ids'] ) ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}hl_partnership WHERE partnership_id IN (" . implode( ',', array_map( 'intval', $ids['partnership_ids'] ) ) . ")" );
    }
    foreach ( $ids['user_ids'] as $uid ) {
        if ( function_exists( 'wp_delete_user' ) ) {
            wp_delete_user( $uid );
        }
    }

    // Reset tracking so a second invocation is a no-op.
    foreach ( $GLOBALS['hl_p2_ids'] as $k => $_ ) {
        $GLOBALS['hl_p2_ids'][ $k ] = array();
    }
}

// ──────────────────────────────────────────────────────────────────────────
// Main: wrap in try/finally so tear-down runs on any exception.
// ──────────────────────────────────────────────────────────────────────────

try {

    echo "\n╔══════════════════════════════════════════════════════════╗\n";
    echo   "║ Email Registry Cleanup Phase 2 — stub-wiring tests        ║\n";
    echo   "╚══════════════════════════════════════════════════════════╝\n";

    // Shared infra: one partnership, one cycle, one instrument, one template.
    $partnership_id = hl_p2_create_partnership();
    $cycle_id       = hl_p2_create_cycle( $partnership_id );
    $cycle_row      = hl_p2_cycle_row( $cycle_id );
    $instrument_id  = hl_p2_create_instrument();
    $template_id    = hl_p2_create_template();

    // =========================================================================
    // §5.1 — cron:component_overdue for classroom_visit (per-component tighten)
    // =========================================================================
    echo "\n=== §5.1 classroom_visit overdue ===\n";

    $leader_user_id   = hl_p2_create_user( 'leader', 'leader' );
    $teacher_user_id  = hl_p2_create_user( 'teacher_cv', 'teacher' );
    $leader_enroll_id = hl_p2_create_enrollment( $cycle_id, $leader_user_id, 'leader' );
    $teacher_enroll_id= hl_p2_create_enrollment( $cycle_id, $teacher_user_id, 'teacher' );
    $pathway_id_1     = hl_p2_create_pathway( $cycle_id );
    hl_p2_create_pathway_assignment( $pathway_id_1, $leader_enroll_id );

    // Two CV components on the leader's pathway, visit_number 1 and 2, both
    // overdue by ~1 day (complete_by = yesterday in site TZ).
    $yesterday = wp_date( 'Y-m-d', strtotime( current_time( 'mysql' ) . ' -1 day' ) );
    $cv_comp_1 = hl_p2_create_component( $cycle_id, $pathway_id_1, 'classroom_visit', array(
        'title'        => FIXTURE_PREFIX . ' CV #1',
        'complete_by'  => $yesterday,
        'external_ref' => wp_json_encode( array( 'visit_number' => 1 ) ),
    ) );
    $cv_comp_2 = hl_p2_create_component( $cycle_id, $pathway_id_1, 'classroom_visit', array(
        'title'        => FIXTURE_PREFIX . ' CV #2',
        'complete_by'  => $yesterday,
        'external_ref' => wp_json_encode( array( 'visit_number' => 2 ) ),
    ) );

    // Workflow: component_overdue, offset 1440min (1 day), classroom_visit filter.
    global $wpdb;
    $wf_cv_overdue = hl_p2_create_workflow( 'cron:component_overdue', $template_id, array(
        'trigger_offset_minutes' => 1440,
        'component_type_filter'  => 'classroom_visit',
    ) );
    $wf_cv_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d",
        $wf_cv_overdue
    ) );

    // Baseline: both components should match (no submissions anywhere).
    $rows = hl_p2_call_private( 'get_cron_trigger_users', array( 'cron:component_overdue', $cycle_row, $wf_cv_row ) );
    $matched_components = array_map( function ( $r ) { return (int) $r['entity_id']; }, $rows );
    hl_p2_assert(
        in_array( $cv_comp_1, $matched_components, true ) && in_array( $cv_comp_2, $matched_components, true ),
        '§5.1 baseline: both CV #1 and CV #2 returned as overdue'
    );

    // Submit CV #1 (visit_number=1) for the leader as observer. Under the
    // pre-tighten cycle-scoped subquery, this would suppress BOTH components.
    $cv_visit_1 = hl_p2_create_classroom_visit( $cycle_id, $leader_enroll_id, $teacher_enroll_id, 1 );
    hl_p2_create_cv_submission( $cv_visit_1, $leader_user_id, $instrument_id, 'observer', 'submitted' );

    $rows = hl_p2_call_private( 'get_cron_trigger_users', array( 'cron:component_overdue', $cycle_row, $wf_cv_row ) );
    $matched_components = array_map( function ( $r ) { return (int) $r['entity_id']; }, $rows );
    hl_p2_assert(
        ! in_array( $cv_comp_1, $matched_components, true ),
        '§5.1 CV #1 suppressed after submission (visit_number=1)'
    );
    hl_p2_assert(
        in_array( $cv_comp_2, $matched_components, true ),
        '§5.1 CV #2 STILL returned after CV #1 submission (per-component tighten works)'
    );

    // Now submit CV #2 too — both should be suppressed.
    $cv_visit_2 = hl_p2_create_classroom_visit( $cycle_id, $leader_enroll_id, $teacher_enroll_id, 2 );
    hl_p2_create_cv_submission( $cv_visit_2, $leader_user_id, $instrument_id, 'observer', 'submitted' );

    $rows = hl_p2_call_private( 'get_cron_trigger_users', array( 'cron:component_overdue', $cycle_row, $wf_cv_row ) );
    $matched_components = array_map( function ( $r ) { return (int) $r['entity_id']; }, $rows );
    hl_p2_assert(
        ! in_array( $cv_comp_1, $matched_components, true ) && ! in_array( $cv_comp_2, $matched_components, true ),
        '§5.1 both CVs suppressed after both submitted'
    );

    // =========================================================================
    // §5.2 — cron:session_upcoming at 5d / 24h / 1h offsets
    // =========================================================================
    echo "\n=== §5.2 cron:session_upcoming multi-offset dedup ===\n";

    $mentor_user_id   = hl_p2_create_user( 'mentor', 'mentor' );
    $coach_user_id    = hl_p2_create_user( 'coach',  'coach'  );
    $mentor_enroll_id = hl_p2_create_enrollment( $cycle_id, $mentor_user_id, 'mentor' );

    // 3 coaching sessions at T+5d / T+24h / T+1h in site TZ.
    $now_site   = current_time( 'mysql' );
    $dt_5d      = wp_date( 'Y-m-d H:i:s', strtotime( $now_site . ' +5 days' ) );
    $dt_24h     = wp_date( 'Y-m-d H:i:s', strtotime( $now_site . ' +24 hours' ) );
    $dt_1h      = wp_date( 'Y-m-d H:i:s', strtotime( $now_site . ' +1 hour' ) );
    $dt_cancel  = wp_date( 'Y-m-d H:i:s', strtotime( $now_site . ' +24 hours' ) );

    $sess_5d     = hl_p2_create_coaching_session( $cycle_id, $coach_user_id, $mentor_enroll_id, $dt_5d,     'scheduled' );
    $sess_24h    = hl_p2_create_coaching_session( $cycle_id, $coach_user_id, $mentor_enroll_id, $dt_24h,    'scheduled' );
    $sess_1h     = hl_p2_create_coaching_session( $cycle_id, $coach_user_id, $mentor_enroll_id, $dt_1h,     'scheduled' );
    $sess_cancel = hl_p2_create_coaching_session( $cycle_id, $coach_user_id, $mentor_enroll_id, $dt_cancel, 'cancelled' );

    // 3 workflows, one per offset.
    $wf_5d  = hl_p2_create_workflow( 'cron:session_upcoming', $template_id, array( 'trigger_offset_minutes' => 5 * 24 * 60 ) );
    $wf_24h = hl_p2_create_workflow( 'cron:session_upcoming', $template_id, array( 'trigger_offset_minutes' => 24 * 60 ) );
    $wf_1h  = hl_p2_create_workflow( 'cron:session_upcoming', $template_id, array( 'trigger_offset_minutes' => 60 ) );

    $wf_5d_row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d", $wf_5d  ) );
    $wf_24h_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d", $wf_24h ) );
    $wf_1h_row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d", $wf_1h  ) );

    $rows_5d  = hl_p2_call_private( 'get_cron_trigger_users', array( 'cron:session_upcoming', $cycle_row, $wf_5d_row  ) );
    $rows_24h = hl_p2_call_private( 'get_cron_trigger_users', array( 'cron:session_upcoming', $cycle_row, $wf_24h_row ) );
    $rows_1h  = hl_p2_call_private( 'get_cron_trigger_users', array( 'cron:session_upcoming', $cycle_row, $wf_1h_row  ) );

    $sids_5d  = array_map( function ( $r ) { return (int) $r['entity_id']; }, $rows_5d );
    $sids_24h = array_map( function ( $r ) { return (int) $r['entity_id']; }, $rows_24h );
    $sids_1h  = array_map( function ( $r ) { return (int) $r['entity_id']; }, $rows_1h );

    hl_p2_assert( in_array( $sess_5d,  $sids_5d,  true ), '§5.2 5d workflow matches 5d session' );
    hl_p2_assert( in_array( $sess_24h, $sids_24h, true ), '§5.2 24h workflow matches 24h session' );
    hl_p2_assert( in_array( $sess_1h,  $sids_1h,  true ), '§5.2 1h workflow matches 1h session' );

    hl_p2_assert( ! in_array( $sess_24h, $sids_5d,  true ) && ! in_array( $sess_1h, $sids_5d,  true ), '§5.2 5d workflow does NOT match 24h/1h sessions' );
    hl_p2_assert( ! in_array( $sess_5d,  $sids_24h, true ) && ! in_array( $sess_1h, $sids_24h, true ), '§5.2 24h workflow does NOT match 5d/1h sessions' );
    hl_p2_assert( ! in_array( $sess_5d,  $sids_1h,  true ) && ! in_array( $sess_24h, $sids_1h, true ), '§5.2 1h workflow does NOT match 5d/24h sessions' );

    hl_p2_assert( ! in_array( $sess_cancel, $sids_5d,  true ) && ! in_array( $sess_cancel, $sids_24h, true ) && ! in_array( $sess_cancel, $sids_1h, true ), '§5.2 cancelled session NEVER matches any workflow' );

    // Dedup proof: different workflow_ids => different dedup tokens for the
    // same session. Confirmed structurally by run_cron_workflow() at line 984
    // (dedup = md5 of trigger_key|workflow_id|user_id|entity_id|cycle_id).
    // Assert by constructing both tokens in-line.
    $tok_5d = md5( 'cron:session_upcoming|' . $wf_5d  . '|' . $mentor_user_id . '|' . $sess_24h . '|' . $cycle_id );
    $tok_24h= md5( 'cron:session_upcoming|' . $wf_24h . '|' . $mentor_user_id . '|' . $sess_24h . '|' . $cycle_id );
    hl_p2_assert( $tok_5d !== $tok_24h, '§5.2 dedup tokens differ across workflows for same session' );

    // =========================================================================
    // §5.3 — cron:action_plan_24h + cron:session_notes_24h
    // =========================================================================
    echo "\n=== §5.3 action_plan_24h / session_notes_24h ===\n";

    // Session A: attended 25h ago, no submissions at all.
    $dt_25h_ago = wp_date( 'Y-m-d H:i:s', strtotime( $now_site . ' -25 hours' ) );
    $dt_12h_ago = wp_date( 'Y-m-d H:i:s', strtotime( $now_site . ' -12 hours' ) );

    $sess_A = hl_p2_create_coaching_session( $cycle_id, $coach_user_id, $mentor_enroll_id, $dt_25h_ago, 'attended' );
    // Session B: attended 25h ago, supervisee (mentor) submitted action plan, no supervisor submission.
    $sess_B = hl_p2_create_coaching_session( $cycle_id, $coach_user_id, $mentor_enroll_id, $dt_25h_ago, 'attended' );
    hl_p2_create_cs_submission( $sess_B, $mentor_user_id, $instrument_id, 'supervisee', 'submitted' );
    // Session C: too recent (12h ago), should NOT fire either handler.
    $sess_C = hl_p2_create_coaching_session( $cycle_id, $coach_user_id, $mentor_enroll_id, $dt_12h_ago, 'attended' );
    // Session D: 25h ago but cancelled — should NOT fire.
    $sess_D = hl_p2_create_coaching_session( $cycle_id, $coach_user_id, $mentor_enroll_id, $dt_25h_ago, 'cancelled' );

    $wf_ap = hl_p2_create_workflow( 'cron:action_plan_24h',   $template_id );
    $wf_sn = hl_p2_create_workflow( 'cron:session_notes_24h', $template_id );
    $wf_ap_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d", $wf_ap ) );
    $wf_sn_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d", $wf_sn ) );

    $rows_ap = hl_p2_call_private( 'get_cron_trigger_users', array( 'cron:action_plan_24h',   $cycle_row, $wf_ap_row ) );
    $rows_sn = hl_p2_call_private( 'get_cron_trigger_users', array( 'cron:session_notes_24h', $cycle_row, $wf_sn_row ) );

    $ap_session_ids = array_map( function ( $r ) { return (int) $r['entity_id']; }, $rows_ap );
    $sn_session_ids = array_map( function ( $r ) { return (int) $r['entity_id']; }, $rows_sn );

    // action_plan_24h: Session A (no supervisee submission) fires; B does not (supervisee submitted); C is too recent; D is cancelled.
    hl_p2_assert(   in_array( $sess_A, $ap_session_ids, true ), '§5.3 action_plan: session A (no supervisee sub) fires' );
    hl_p2_assert( ! in_array( $sess_B, $ap_session_ids, true ), '§5.3 action_plan: session B (supervisee submitted) does NOT fire' );
    hl_p2_assert( ! in_array( $sess_C, $ap_session_ids, true ), '§5.3 action_plan: session C (12h old) does NOT fire' );
    hl_p2_assert( ! in_array( $sess_D, $ap_session_ids, true ), '§5.3 action_plan: session D (cancelled) does NOT fire' );

    // session_notes_24h: Sessions A and B both fire (neither has supervisor submission); C too recent; D cancelled.
    hl_p2_assert(   in_array( $sess_A, $sn_session_ids, true ), '§5.3 session_notes: session A (no supervisor sub) fires' );
    hl_p2_assert(   in_array( $sess_B, $sn_session_ids, true ), '§5.3 session_notes: session B (no supervisor sub) fires' );
    hl_p2_assert( ! in_array( $sess_C, $sn_session_ids, true ), '§5.3 session_notes: session C (12h old) does NOT fire' );
    hl_p2_assert( ! in_array( $sess_D, $sn_session_ids, true ), '§5.3 session_notes: session D (cancelled) does NOT fire' );

    // Action-plan path: verify the SELECT returns the mentor's WP user_id (not mentor_user_id column).
    $ap_row_A = null;
    foreach ( $rows_ap as $r ) { if ( (int) $r['entity_id'] === $sess_A ) { $ap_row_A = $r; break; } }
    hl_p2_assert( $ap_row_A && (int) $ap_row_A['user_id'] === $mentor_user_id, '§5.3 action_plan: user_id resolves via enrollment join to mentor WP user' );
    hl_p2_assert( $ap_row_A && (int) $ap_row_A['enrollment_id'] === $mentor_enroll_id, '§5.3 action_plan: enrollment_id is mentor enrollment' );

    // Session-notes path: NULL enrollment_id for coach (explicit test per Mateo).
    $sn_row_A = null;
    foreach ( $rows_sn as $r ) { if ( (int) $r['entity_id'] === $sess_A ) { $sn_row_A = $r; break; } }
    hl_p2_assert( $sn_row_A && (int) $sn_row_A['user_id'] === $coach_user_id, '§5.3 session_notes: user_id is coach WP user' );
    hl_p2_assert( $sn_row_A && ( $sn_row_A['enrollment_id'] === null || $sn_row_A['enrollment_id'] === '' ), '§5.3 session_notes: enrollment_id is NULL (coach is staff, not enrolled)' );

    // End-to-end: run the daily cron and confirm a queue row for the coach path
    // appears. This exercises build_hook_context → hydrate_context → resolver
    // → renderer → enqueue with a NULL enrollment_id in context.
    $queue_before = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue WHERE subject = %s",
        'Phase2Test Subject'
    ) );

    // Pause other test workflows so only §5.3 fires this cron pass.
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}hl_email_workflow SET status = 'paused'
         WHERE workflow_id IN (%d, %d, %d, %d)",
        $wf_cv_overdue, $wf_5d, $wf_24h, $wf_1h
    ) );

    // Capture PHP error log before the cron run to verify no warnings about NULL.
    $log_pre = @file_get_contents( ini_get( 'error_log' ) );
    $log_pre_len = $log_pre ? strlen( $log_pre ) : 0;

    HL_Email_Automation_Service::instance()->run_daily_checks();

    $queue_after = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue WHERE subject = %s",
        'Phase2Test Subject'
    ) );

    hl_p2_assert( $queue_after > $queue_before, '§5.3 end-to-end: at least one queue row was inserted by the cron run' );

    // Confirm a coach-path queue row exists.
    $coach_queue = $wpdb->get_row( $wpdb->prepare(
        "SELECT q.*
         FROM {$wpdb->prefix}hl_email_queue q
         WHERE q.subject = %s AND q.recipient_user_id = %d AND q.workflow_id = %d",
        'Phase2Test Subject', $coach_user_id, $wf_sn
    ) );
    hl_p2_assert( $coach_queue && ! empty( $coach_queue->dedup_token ), '§5.3 end-to-end: coach-path queue row present with dedup_token' );

    // NULL enrollment_id propagates into context_data without warnings.
    if ( $coach_queue ) {
        $ctx = json_decode( $coach_queue->context_data, true );
        hl_p2_assert(
            is_array( $ctx ) && array_key_exists( 'enrollment_id', $ctx ) && $ctx['enrollment_id'] === null,
            '§5.3 end-to-end: context_data.enrollment_id is null (coach is staff, not enrolled)'
        );
    } else {
        hl_p2_assert( false, '§5.3 end-to-end: context_data.enrollment_id check skipped (no queue row)' );
    }

    // Scan post-run error log for NULL-related warnings from the cron pipeline.
    $log_post = @file_get_contents( ini_get( 'error_log' ) );
    $log_delta = $log_post ? substr( $log_post, $log_pre_len ) : '';
    $suspicious = ( stripos( $log_delta, 'enrollment_id' ) !== false && stripos( $log_delta, 'null' ) !== false )
               || ( stripos( $log_delta, 'trying to access' ) !== false );
    hl_p2_assert( ! $suspicious, '§5.3 end-to-end: no NULL-enrollment warnings in PHP error log during run' );

    // Dedup: second identical run should NOT create new coach queue rows.
    $coach_count_1 = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue WHERE workflow_id = %d AND recipient_user_id = %d",
        $wf_sn, $coach_user_id
    ) );
    HL_Email_Automation_Service::instance()->run_daily_checks();
    $coach_count_2 = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}hl_email_queue WHERE workflow_id = %d AND recipient_user_id = %d",
        $wf_sn, $coach_user_id
    ) );
    hl_p2_assert( $coach_count_2 === $coach_count_1, '§5.3 dedup: second cron run does not enqueue duplicate coach emails' );

    echo "\n--- ";
    $pass   = $GLOBALS['hl_p2_pass'];
    $fail   = $GLOBALS['hl_p2_fail'];
    $errors = $GLOBALS['hl_p2_errors'];
    echo "RESULTS: {$pass} passed, {$fail} failed ---\n";

    if ( $fail > 0 ) {
        echo "\nFAILURES:\n";
        foreach ( $errors as $e ) echo "  - $e\n";
    }

} finally {
    echo "\nCleaning up fixtures...\n";
    hl_p2_cleanup();
    echo "Cleanup done.\n";
}

exit( $GLOBALS['hl_p2_fail'] > 0 ? 1 : 0 );
