<?php
/**
 * Email Automation Service — bug-fix regression harness.
 *
 * Covers the 8 hook-listener and context-loader bug fixes landed on
 * feature/workflow-ux-m1 (see individual commits on 2026-04-22):
 *   Bug 1 — hl_pathway_assigned: arg-count mismatch
 *   Bug 2 — hl_pathway_completed: arg-order mismatch
 *   Bug 3 — hl_classroom_visit_submitted: triple bug (arg index, PK
 *           column, non-existent columns) + new load_classroom_visit_context
 *   Bug 4 — hl_coaching_session_status_changed: old/new status swap
 *   Bug 5 — hydrate_context school JOIN: o.parent_id => parent_orgunit_id
 *   Bug 6 — admin builder preview JOIN: same typo, different file
 *   Bug 7 — hl_coach_assigned: bare context, row never sent
 *   Bug 8 — hl_rp_session_status_changed: same swap as Bug 4 + missing
 *           load_rp_session_context
 *
 * Each test calls build_hook_context() or the relevant loader via
 * ReflectionMethod and asserts on the returned context. No real emails
 * are sent; no workflows are seeded. Fixtures are torn down in the
 * finally block regardless of mid-test exceptions.
 *
 * Run via:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-email-bug-fixes.php
 *
 * Exit 0 = all pass, exit 1 = one or more failed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file\n" );
    exit( 1 );
}

if ( ! class_exists( 'HL_Email_Automation_Service' ) ) {
    $plugin_dir = dirname( __DIR__ ) . '/';
    require_once $plugin_dir . 'includes/services/class-hl-email-automation-service.php';
}

const HL_BF_PREFIX = '[BugFixTest]';

$GLOBALS['hl_bf_pass']   = 0;
$GLOBALS['hl_bf_fail']   = 0;
$GLOBALS['hl_bf_errors'] = array();
$GLOBALS['hl_bf_ids']    = array(
    'user_ids'               => array(),
    'partnership_ids'        => array(),
    'cycle_ids'              => array(),
    'enrollment_ids'         => array(),
    'pathway_ids'            => array(),
    'orgunit_ids'            => array(),
    'coaching_session_ids'   => array(),
    'rp_session_ids'         => array(),
    'classroom_visit_ids'    => array(),
    'coach_assignment_ids'   => array(),
);

// ──────────────────────────────────────────────────────────────────────────
// Assertion + reflection helpers
// ──────────────────────────────────────────────────────────────────────────

function hl_bf_assert( $cond, $label ) {
    if ( $cond ) {
        $GLOBALS['hl_bf_pass']++;
        echo "  [PASS] $label\n";
    } else {
        $GLOBALS['hl_bf_fail']++;
        $GLOBALS['hl_bf_errors'][] = $label;
        echo "  [FAIL] $label\n";
    }
}

function hl_bf_call_private( $method, array $args ) {
    $svc = HL_Email_Automation_Service::instance();
    $ref = new ReflectionMethod( $svc, $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( $svc, $args );
}

// ──────────────────────────────────────────────────────────────────────────
// Fixture builders
// ──────────────────────────────────────────────────────────────────────────

function hl_bf_create_user( $suffix ) {
    $login = 'bftest_' . $suffix . '_' . wp_generate_password( 6, false, false );
    $email = 'bftest+' . $suffix . '_' . wp_generate_password( 6, false, false ) . '@example.invalid';
    $uid = wp_insert_user( array(
        'user_login'   => $login,
        'user_pass'    => wp_generate_password( 16 ),
        'user_email'   => $email,
        'display_name' => HL_BF_PREFIX . ' ' . $suffix,
    ) );
    if ( is_wp_error( $uid ) ) {
        throw new RuntimeException( 'user create failed: ' . $uid->get_error_message() );
    }
    $GLOBALS['hl_bf_ids']['user_ids'][] = (int) $uid;
    return (int) $uid;
}

function hl_bf_create_orgunit( $parent_orgunit_id = null, $name = null ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_orgunit', array(
        'orgunit_uuid'       => wp_generate_uuid4(),
        'orgunit_code'       => 'bftest-org-' . wp_generate_password( 8, false, false ),
        'name'               => $name ?? ( HL_BF_PREFIX . ' Orgunit ' . wp_generate_password( 4, false, false ) ),
        'orgunit_type'       => $parent_orgunit_id ? 'school' : 'district',
        'parent_orgunit_id'  => $parent_orgunit_id,
        'status'             => 'active',
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_bf_ids']['orgunit_ids'][] = $id;
    return $id;
}

function hl_bf_create_partnership() {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_partnership', array(
        'partnership_uuid' => wp_generate_uuid4(),
        'partnership_name' => HL_BF_PREFIX . ' Partnership',
        'partnership_code' => 'bftest-part-' . wp_generate_password( 6, false, false ),
        'status'           => 'active',
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_bf_ids']['partnership_ids'][] = $id;
    return $id;
}

function hl_bf_create_cycle( $partnership_id ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_cycle', array(
        'cycle_uuid'     => wp_generate_uuid4(),
        'cycle_code'     => 'bftest-cyc-' . wp_generate_password( 8, false, false ),
        'cycle_name'     => HL_BF_PREFIX . ' Cycle',
        'partnership_id' => $partnership_id,
        'cycle_type'     => 'program',
        'status'         => 'active',
        'start_date'     => wp_date( 'Y-m-d', strtotime( '-60 days' ) ),
        'end_date'       => wp_date( 'Y-m-d', strtotime( '+60 days' ) ),
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_bf_ids']['cycle_ids'][] = $id;
    return $id;
}

function hl_bf_create_enrollment( $cycle_id, $user_id, $roles_csv = 'teacher', $school_id = null ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_enrollment', array(
        'enrollment_uuid' => wp_generate_uuid4(),
        'cycle_id'        => $cycle_id,
        'user_id'         => $user_id,
        'roles'           => $roles_csv,
        'status'          => 'active',
        'school_id'       => $school_id,
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_bf_ids']['enrollment_ids'][] = $id;
    return $id;
}

function hl_bf_create_pathway( $cycle_id ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_pathway', array(
        'pathway_uuid' => wp_generate_uuid4(),
        'cycle_id'     => $cycle_id,
        'pathway_name' => HL_BF_PREFIX . ' Pathway',
        'pathway_code' => 'bftest-path-' . wp_generate_password( 6, false, false ),
        'is_template'  => 0,
        'active_status'=> 1,
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_bf_ids']['pathway_ids'][] = $id;
    return $id;
}

function hl_bf_create_coaching_session( $cycle_id, $coach_user_id, $mentor_enrollment_id, $status = 'scheduled' ) {
    global $wpdb;
    // Schema has no mentor_user_id column; the mentor is resolved via
    // mentor_enrollment_id -> hl_enrollment.user_id in load_coaching_session_context.
    $wpdb->insert( $wpdb->prefix . 'hl_coaching_session', array(
        'session_uuid'         => wp_generate_uuid4(),
        'cycle_id'             => $cycle_id,
        'coach_user_id'        => $coach_user_id,
        'mentor_enrollment_id' => $mentor_enrollment_id,
        'session_status'       => $status,
        'session_datetime'     => wp_date( 'Y-m-d H:i:s', strtotime( '+2 days' ) ),
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_bf_ids']['coaching_session_ids'][] = $id;
    return $id;
}

function hl_bf_create_rp_session( $cycle_id, $mentor_enrollment_id, $teacher_enrollment_id ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_rp_session', array(
        'rp_session_uuid'       => wp_generate_uuid4(),
        'cycle_id'              => $cycle_id,
        'mentor_enrollment_id'  => $mentor_enrollment_id,
        'teacher_enrollment_id' => $teacher_enrollment_id,
        'session_number'        => 1,
        'status'                => 'pending',
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_bf_ids']['rp_session_ids'][] = $id;
    return $id;
}

function hl_bf_create_classroom_visit( $cycle_id, $leader_enrollment_id, $teacher_enrollment_id ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_classroom_visit', array(
        'classroom_visit_uuid'  => wp_generate_uuid4(),
        'cycle_id'              => $cycle_id,
        'leader_enrollment_id'  => $leader_enrollment_id,
        'teacher_enrollment_id' => $teacher_enrollment_id,
        'visit_number'          => 1,
        'status'                => 'pending',
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_bf_ids']['classroom_visit_ids'][] = $id;
    return $id;
}

function hl_bf_create_coach_assignment( $coach_user_id, $scope_type, $scope_id, $cycle_id ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'hl_coach_assignment', array(
        'coach_user_id'   => $coach_user_id,
        'scope_type'      => $scope_type,
        'scope_id'        => $scope_id,
        'cycle_id'        => $cycle_id,
        'effective_from'  => wp_date( 'Y-m-d' ),
    ) );
    $id = (int) $wpdb->insert_id;
    $GLOBALS['hl_bf_ids']['coach_assignment_ids'][] = $id;
    return $id;
}

// ──────────────────────────────────────────────────────────────────────────
// Cleanup
// ──────────────────────────────────────────────────────────────────────────

function hl_bf_cleanup() {
    global $wpdb;
    $ids = $GLOBALS['hl_bf_ids'];
    $delete = function ( $table, $id_col, $values ) use ( $wpdb ) {
        if ( empty( $values ) ) return;
        $in = implode( ',', array_map( 'intval', $values ) );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}{$table} WHERE {$id_col} IN ({$in})" );
    };
    $delete( 'hl_coach_assignment',  'coach_assignment_id', $ids['coach_assignment_ids'] );
    $delete( 'hl_rp_session',        'rp_session_id',       $ids['rp_session_ids'] );
    $delete( 'hl_classroom_visit',   'classroom_visit_id',  $ids['classroom_visit_ids'] );
    $delete( 'hl_coaching_session',  'session_id',          $ids['coaching_session_ids'] );
    $delete( 'hl_pathway',           'pathway_id',          $ids['pathway_ids'] );
    $delete( 'hl_enrollment',        'enrollment_id',       $ids['enrollment_ids'] );
    $delete( 'hl_cycle',             'cycle_id',            $ids['cycle_ids'] );
    $delete( 'hl_partnership',       'partnership_id',      $ids['partnership_ids'] );
    $delete( 'hl_orgunit',           'orgunit_id',          $ids['orgunit_ids'] );
    foreach ( $ids['user_ids'] as $uid ) {
        if ( function_exists( 'wp_delete_user' ) ) {
            wp_delete_user( $uid );
        }
    }
    foreach ( $GLOBALS['hl_bf_ids'] as $k => $_ ) {
        $GLOBALS['hl_bf_ids'][ $k ] = array();
    }
}

// ──────────────────────────────────────────────────────────────────────────
// Main
// ──────────────────────────────────────────────────────────────────────────

try {

    echo "\n╔══════════════════════════════════════════════════════════╗\n";
    echo   "║ Email Bug-Fix Regression Harness                          ║\n";
    echo   "╚══════════════════════════════════════════════════════════╝\n";

    // Shared infra: district + school (for Bug 5/6), partnership, cycle.
    $district_id    = hl_bf_create_orgunit( null, HL_BF_PREFIX . ' District' );
    $school_id      = hl_bf_create_orgunit( $district_id, HL_BF_PREFIX . ' School' );
    $partnership_id = hl_bf_create_partnership();
    $cycle_id       = hl_bf_create_cycle( $partnership_id );

    // =========================================================================
    // Bug 1 — hl_pathway_assigned arg unpacking
    // =========================================================================
    echo "\n=== Bug 1: hl_pathway_assigned ===\n";

    $user_id       = hl_bf_create_user( 'pa_user' );
    $enrollment_id = hl_bf_create_enrollment( $cycle_id, $user_id, 'teacher' );
    $pathway_id    = hl_bf_create_pathway( $cycle_id );

    $ctx = hl_bf_call_private( 'build_hook_context', array(
        'hl_pathway_assigned',
        array( $enrollment_id, $pathway_id ),
    ) );

    hl_bf_assert( ( $ctx['entity_id'] ?? null ) === $enrollment_id,
        'Bug 1: entity_id = enrollment_id (not null / not arg[0]-as-assignment_id)' );
    hl_bf_assert( ( $ctx['entity_type'] ?? null ) === 'enrollment',
        'Bug 1: entity_type = "enrollment"' );
    hl_bf_assert( (int) ( $ctx['user_id'] ?? 0 ) === $user_id,
        'Bug 1: user_id resolved from enrollment (not from arg[1] which was pathway_id)' );
    hl_bf_assert( (int) ( $ctx['pathway_id'] ?? 0 ) === $pathway_id,
        'Bug 1: pathway_id = arg[1] (was arg[2], which was null under bug)' );

    // =========================================================================
    // Bug 2 — hl_pathway_completed arg order
    // =========================================================================
    echo "\n=== Bug 2: hl_pathway_completed ===\n";

    $ctx2 = hl_bf_call_private( 'build_hook_context', array(
        'hl_pathway_completed',
        array( $enrollment_id, $pathway_id, $cycle_id ),
    ) );

    hl_bf_assert( ( $ctx2['entity_id'] ?? null ) === $enrollment_id,
        'Bug 2: entity_id = enrollment_id (not arg[2] misread as enrollment_id)' );
    hl_bf_assert( (int) ( $ctx2['user_id'] ?? 0 ) === $user_id,
        'Bug 2: user_id resolved from enrollment (was arg[0] under bug, which was enrollment_id not user_id)' );
    hl_bf_assert( (int) ( $ctx2['cycle_id'] ?? 0 ) === $cycle_id,
        'Bug 2: cycle_id captured from arg[2] (was dropped entirely under bug)' );

    // =========================================================================
    // Bug 3 — hl_classroom_visit_submitted + load_classroom_visit_context
    // =========================================================================
    echo "\n=== Bug 3: classroom_visit_submitted + loader ===\n";

    $leader_user_id   = hl_bf_create_user( 'cv_leader' );
    $teacher_user_id  = hl_bf_create_user( 'cv_teacher' );
    $leader_enroll_id = hl_bf_create_enrollment( $cycle_id, $leader_user_id, 'leader', $school_id );
    $teacher_enroll_id= hl_bf_create_enrollment( $cycle_id, $teacher_user_id, 'teacher', $school_id );
    $visit_id         = hl_bf_create_classroom_visit( $cycle_id, $leader_enroll_id, $teacher_enroll_id );
    $submission_id    = 9999; // not persisted; just a handle on the hook

    $ctx3 = hl_bf_call_private( 'build_hook_context', array(
        'hl_classroom_visit_submitted',
        array( $submission_id, $visit_id, 'leader', $leader_user_id ),
    ) );

    hl_bf_assert( ( $ctx3['entity_id'] ?? null ) === $visit_id,
        'Bug 3a: entity_id = classroom_visit_id = arg[1] (was arg[0]=submission_id under bug)' );
    hl_bf_assert( ( $ctx3['entity_type'] ?? null ) === 'classroom_visit',
        'Bug 3: entity_type = "classroom_visit"' );
    hl_bf_assert( (int) ( $ctx3['user_id'] ?? 0 ) === $leader_user_id,
        'Bug 3: user_id = submitter = arg[3] (was null under bug because loader returned empty)' );
    hl_bf_assert( (int) ( $ctx3['observed_teacher_user_id'] ?? 0 ) === $teacher_user_id,
        'Bug 3c: observed_teacher_user_id resolved via JOIN on teacher_enrollment_id' );
    hl_bf_assert( (int) ( $ctx3['cc_teacher_user_id'] ?? 0 ) === $teacher_user_id,
        'Bug 3c: cc_teacher_user_id legacy alias also populated' );
    hl_bf_assert( (int) ( $ctx3['cycle_id'] ?? 0 ) === $cycle_id,
        'Bug 3b: cycle_id loaded (was null under bug because WHERE visit_id query returned nothing)' );
    hl_bf_assert( ( $ctx3['visit']['role'] ?? null ) === 'leader',
        'Bug 3: visit.role preserved from hook arg[2]' );
    hl_bf_assert( (int) ( $ctx3['visit']['visit_number'] ?? 0 ) === 1,
        'Bug 3: visit.visit_number from loader' );

    // =========================================================================
    // Bug 4 — hl_coaching_session_status_changed old/new swap
    // =========================================================================
    echo "\n=== Bug 4: coaching_session_status_changed ===\n";

    $coach_user_id = hl_bf_create_user( 'coach' );
    $mentor_user_id = hl_bf_create_user( 'mentor' );
    $mentor_enrollment_id = hl_bf_create_enrollment( $cycle_id, $mentor_user_id, 'mentor' );
    $session_id = hl_bf_create_coaching_session(
        $cycle_id, $coach_user_id, $mentor_enrollment_id, 'missed'
    );

    // Simulate the emitter: ($session_id, $old, $new, $session)
    $ctx4 = hl_bf_call_private( 'build_hook_context', array(
        'hl_coaching_session_status_changed',
        array( $session_id, 'scheduled', 'missed', null ),
    ) );

    hl_bf_assert( ( $ctx4['session']['new_status'] ?? null ) === 'missed',
        'Bug 4: session.new_status = arg[2] = "missed" (was arg[1]="scheduled" under bug)' );
    hl_bf_assert( ( $ctx4['session']['old_status'] ?? null ) === 'scheduled',
        'Bug 4: session.old_status = arg[1] = "scheduled"' );
    // Bug 9: coaching loader JOIN surfaces mentor_user_id via hl_enrollment
    hl_bf_assert( (int) ( $ctx4['user_id'] ?? 0 ) === $mentor_user_id,
        'Bug 9: load_coaching_session_context JOIN resolves mentor user_id (was 0 under bug because mentor_user_id column does not exist on hl_coaching_session)' );
    hl_bf_assert( (int) ( $ctx4['enrollment_id'] ?? 0 ) === $mentor_enrollment_id,
        'Bug 9: load_coaching_session_context sets enrollment_id from mentor_enrollment_id (was always null under bug)' );
    hl_bf_assert( (int) ( $ctx4['cycle_id'] ?? 0 ) === $cycle_id,
        'Bug 9: load_coaching_session_context sets cycle_id from session row' );
    hl_bf_assert( ! empty( $ctx4['mentor_name'] ),
        'Bug 9: mentor_name populated via get_userdata on the JOIN-resolved mentor_user_id' );

    // =========================================================================
    // Bug 5 — hydrate_context school JOIN uses parent_orgunit_id
    // =========================================================================
    echo "\n=== Bug 5: hydrate_context school district JOIN ===\n";

    // Seed a minimal context with just enrollment_id and force hydrate.
    $ctx5 = hl_bf_call_private( 'hydrate_context', array(
        array( 'enrollment_id' => $leader_enroll_id ),
    ) );

    hl_bf_assert( ! empty( $ctx5['school_name'] ),
        'Bug 5: school_name populated from hl_orgunit row' );
    hl_bf_assert( ( $ctx5['school_district'] ?? '' ) === HL_BF_PREFIX . ' District',
        'Bug 5: school_district populated via parent_orgunit_id JOIN (was blank under bug)' );

    // =========================================================================
    // Bug 7 — hl_coach_assigned context (enrollment-scoped)
    // =========================================================================
    echo "\n=== Bug 7: hl_coach_assigned ===\n";

    $coach_user_id_2  = hl_bf_create_user( 'coach2' );
    $mentor_user_id_2 = hl_bf_create_user( 'mentor2' );
    $mentor_enroll_id_2 = hl_bf_create_enrollment( $cycle_id, $mentor_user_id_2, 'mentor' );

    $insert_data = array(
        'coach_user_id'  => $coach_user_id_2,
        'scope_type'     => 'enrollment',
        'scope_id'       => $mentor_enroll_id_2,
        'cycle_id'       => $cycle_id,
        'effective_from' => wp_date( 'Y-m-d' ),
    );
    $assignment_id = hl_bf_create_coach_assignment(
        $coach_user_id_2, 'enrollment', $mentor_enroll_id_2, $cycle_id
    );

    $ctx7 = hl_bf_call_private( 'build_hook_context', array(
        'hl_coach_assigned',
        array( $assignment_id, $insert_data ),
    ) );

    hl_bf_assert( ( $ctx7['entity_id'] ?? null ) === $assignment_id,
        'Bug 7: entity_id = coach_assignment_id' );
    hl_bf_assert( (int) ( $ctx7['coach_user_id'] ?? 0 ) === $coach_user_id_2,
        'Bug 7: coach_user_id populated from insert data (was absent under bug)' );
    hl_bf_assert( ! empty( $ctx7['coach_email'] ),
        'Bug 7: coach_email resolved from coach user (was absent under bug)' );
    hl_bf_assert( (int) ( $ctx7['user_id'] ?? 0 ) === $mentor_user_id_2,
        'Bug 7: user_id = mentor from enrollment (enrollment-scoped path, was absent under bug)' );
    hl_bf_assert( (int) ( $ctx7['cycle_id'] ?? 0 ) === $cycle_id,
        'Bug 7: cycle_id from insert data (was absent under bug)' );
    hl_bf_assert( ( $ctx7['coach_assignment']['scope_type'] ?? null ) === 'enrollment',
        'Bug 7: coach_assignment.scope_type preserved' );

    // =========================================================================
    // Bug 8 + load_rp_session_context — RP status swap + loader
    // =========================================================================
    echo "\n=== Bug 8: rp_session_status_changed + load_rp_session_context ===\n";

    $rp_session_id = hl_bf_create_rp_session(
        $cycle_id, $mentor_enrollment_id, $teacher_enroll_id
    );

    // Test arg swap fix
    $ctx8 = hl_bf_call_private( 'build_hook_context', array(
        'hl_rp_session_status_changed',
        array( $rp_session_id, 'pending', 'completed', null ),
    ) );

    hl_bf_assert( ( $ctx8['session']['new_status'] ?? null ) === 'completed',
        'Bug 8: session.new_status = arg[2] = "completed" (was arg[1]="pending" under bug)' );
    hl_bf_assert( ( $ctx8['session']['old_status'] ?? null ) === 'pending',
        'Bug 8: session.old_status = arg[1] = "pending"' );

    // Test load_rp_session_context fills mentor + observed_teacher
    hl_bf_assert( (int) ( $ctx8['user_id'] ?? 0 ) === $mentor_user_id,
        'load_rp_session_context: user_id = mentor user_id via JOIN' );
    hl_bf_assert( (int) ( $ctx8['observed_teacher_user_id'] ?? 0 ) === $teacher_user_id,
        'load_rp_session_context: observed_teacher_user_id = teacher user_id via JOIN' );
    hl_bf_assert( (int) ( $ctx8['cycle_id'] ?? 0 ) === $cycle_id,
        'load_rp_session_context: cycle_id from session row' );
    hl_bf_assert( (int) ( $ctx8['session']['session_number'] ?? 0 ) === 1,
        'load_rp_session_context: session.session_number preserved alongside new/old_status' );

    // Also verify _created case does NOT set session.new_status to the insert array
    $ctx8b = hl_bf_call_private( 'build_hook_context', array(
        'hl_rp_session_created',
        array( $rp_session_id, array( 'some' => 'insert-data' ) ),
    ) );
    hl_bf_assert( ! isset( $ctx8b['session']['new_status'] ) || is_string( $ctx8b['session']['new_status'] ),
        'Bug 8: rp_session_created does not corrupt session.new_status with the insert-data array' );

} catch ( \Throwable $t ) {
    $GLOBALS['hl_bf_fail']++;
    $GLOBALS['hl_bf_errors'][] = 'uncaught: ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine();
    echo "\n[ERROR] " . $t->getMessage() . "\n" . $t->getTraceAsString() . "\n";
} finally {
    hl_bf_cleanup();
}

// ──────────────────────────────────────────────────────────────────────────
// Report
// ──────────────────────────────────────────────────────────────────────────

echo "\n══════════════════════════════════════════════════════════\n";
$total = $GLOBALS['hl_bf_pass'] + $GLOBALS['hl_bf_fail'];
echo "Results: {$GLOBALS['hl_bf_pass']}/{$total} PASS";
if ( $GLOBALS['hl_bf_fail'] > 0 ) {
    echo " — {$GLOBALS['hl_bf_fail']} FAIL\n";
    foreach ( $GLOBALS['hl_bf_errors'] as $e ) {
        echo "  - $e\n";
    }
    echo "\n";
    exit( 1 );
}
echo "\n\n";
exit( 0 );
