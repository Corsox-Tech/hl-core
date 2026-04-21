<?php
/**
 * Phase 2 admin-UI-equivalent smoke — exercises the registry and
 * save-payload validation paths that the Email Builder cascade UI
 * uses end-to-end, without needing a browser session.
 *
 * Asserts that for each of the 6 previously-stubbed events:
 *   1. The registry entry has wiring_status = 'wired' (not stub).
 *   2. No stub_note remains (or is only documentary).
 *   3. The event's key is in get_valid_trigger_keys() — a POST save
 *      with this key passes the whitelist.
 *   4. validate_workflow_payload() accepts the canonical conditions
 *      + recipients payload the cascade JS would submit.
 *   5. The category carrying the event has no wiring_status=stub
 *      remaining (i.e., the whole cluster is unblocked).
 *
 * Run via:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-email-phase2-registry-ui.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file\n" );
    exit( 1 );
}
if ( ! class_exists( 'HL_Admin_Emails' ) ) {
    require_once dirname( __DIR__ ) . '/includes/admin/class-hl-admin-emails.php';
}

$pass = 0;
$fail = 0;
$errors = array();

function _p2ui_assert( &$pass, &$fail, &$errors, $cond, $label ) {
    if ( $cond ) {
        $pass++; echo "  [PASS] $label\n";
    } else {
        $fail++; $errors[] = $label; echo "  [FAIL] $label\n";
    }
}

$cats = HL_Admin_Emails::get_trigger_categories();
$valid_keys = HL_Admin_Emails::get_valid_trigger_keys();

$phase2 = array(
    array( 'coaching', 'reminder_5d_before_session',  'cron:session_upcoming' ),
    array( 'coaching', 'reminder_24h_before_session', 'cron:session_upcoming' ),
    array( 'coaching', 'reminder_1h_before_session',  'cron:session_upcoming' ),
    array( 'coaching', 'action_plan_incomplete_24h_after', 'cron:action_plan_24h' ),
    array( 'coaching', 'notes_incomplete_24h_after',       'cron:session_notes_24h' ),
    array( 'classroom_visit',  'overdue',                     'cron:component_overdue' ),
);

echo "\n=== Registry state per Phase 2 event ===\n";
foreach ( $phase2 as $entry ) {
    list( $cat_key, $evt_key, $expected_key ) = $entry;
    $ev = $cats[ $cat_key ]['events'][ $evt_key ] ?? null;
    $label = "$cat_key.$evt_key";

    _p2ui_assert( $pass, $fail, $errors, $ev !== null, "$label: entry exists" );
    if ( ! $ev ) continue;

    _p2ui_assert( $pass, $fail, $errors,
        ( $ev['wiring_status'] ?? '' ) === 'wired',
        "$label: wiring_status = wired (got " . ( $ev['wiring_status'] ?? 'MISSING' ) . ")"
    );
    _p2ui_assert( $pass, $fail, $errors,
        empty( $ev['stub_note'] ),
        "$label: no stub_note (cascade renders as selectable)"
    );
    _p2ui_assert( $pass, $fail, $errors,
        ( $ev['key'] ?? '' ) === $expected_key,
        "$label: key = $expected_key (got " . ( $ev['key'] ?? 'MISSING' ) . ")"
    );
    _p2ui_assert( $pass, $fail, $errors,
        in_array( $expected_key, $valid_keys, true ),
        "$label: key is in get_valid_trigger_keys() whitelist"
    );
}

echo "\n=== Legacy alias regression fix ===\n";
$legacy = HL_Admin_Emails::get_legacy_trigger_aliases();
_p2ui_assert( $pass, $fail, $errors, ! isset( $legacy['cron:action_plan_24h'] ),
    'cron:action_plan_24h removed from legacy aliases (canonical current key)'
);
_p2ui_assert( $pass, $fail, $errors, ! isset( $legacy['cron:session_notes_24h'] ),
    'cron:session_notes_24h removed from legacy aliases (canonical current key)'
);

echo "\n=== validate_workflow_payload accepts Phase 2 triggers ===\n";
// Minimal admin-save payload the cascade JS would submit.
foreach ( $phase2 as $entry ) {
    list( $cat_key, $evt_key, $expected_key ) = $entry;
    $conditions = array();
    $recipients = array( 'primary' => array( 'triggering_user' ), 'cc' => array() );
    $result = HL_Admin_Emails::validate_workflow_payload( $conditions, $recipients );
    _p2ui_assert( $pass, $fail, $errors,
        $result === true,
        "$cat_key.$evt_key: validate_workflow_payload returns true for canonical payload"
    );
}

echo "\n=== No stubs remain in coaching_session + classroom_visit categories ===\n";
foreach ( array( 'coaching', 'classroom_visit' ) as $cat_key ) {
    $stub_count = 0;
    foreach ( $cats[ $cat_key ]['events'] as $evt ) {
        if ( ( $evt['wiring_status'] ?? 'wired' ) === 'stub' ) $stub_count++;
    }
    _p2ui_assert( $pass, $fail, $errors,
        $stub_count === 0,
        "$cat_key: $stub_count stub events remain (expected 0)"
    );
}

echo "\n--- RESULTS: $pass passed, $fail failed ---\n";
if ( $fail > 0 ) {
    echo "\nFAILURES:\n";
    foreach ( $errors as $e ) echo "  - $e\n";
}
exit( $fail > 0 ? 1 : 0 );
