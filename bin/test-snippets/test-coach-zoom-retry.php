<?php
/**
 * Task E1 test snippet: HL_Scheduling_Service::retry_zoom_meeting().
 *
 * Exercises the error paths that do NOT require a live Zoom meeting create.
 * Happy path (Zoom + Outlook + DB write succeed atomically) is covered by
 * manual smoke test §I3 — it cannot be unit-tested without Zoom credentials.
 *
 * Covers:
 *   1. invalid_session — zero / non-numeric ID.
 *   2. not_found — session_id that does not exist in the DB.
 *   3. already_has_meeting — row already has zoom_meeting_id set (no lock taken).
 *   4. retry_inflight — transient lock held -> early return, lock not clobbered.
 *   5. zoom_not_configured — Zoom creds missing (normal on test env).
 *   6. transient cleanup — lock is released after zoom_not_configured path.
 *
 * Run on test:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-retry.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file\n" );
    exit( 1 );
}

function _t( $label, $condition ) {
    echo $condition ? "PASS: $label\n" : "FAIL: $label\n";
}

global $wpdb;
$svc = new HL_Scheduling_Service();

// Elevate to an admin so permission_denied is not the blocker we hit.
$admin_users = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
if ( empty( $admin_users ) ) {
    echo "FAIL: no administrator user found to impersonate; aborting.\n";
    return;
}
wp_set_current_user( (int) $admin_users[0]->ID );

// 1. invalid_session — zero ID.
$r = $svc->retry_zoom_meeting( 0 );
_t(
    'invalid_session — zero ID returns WP_Error invalid_session',
    is_wp_error( $r ) && $r->get_error_code() === 'invalid_session'
);

// 2. not_found — session_id that doesn't exist.
$bogus = 2147483000; // Very unlikely to collide with a real session_id.
$r = $svc->retry_zoom_meeting( $bogus );
_t(
    'not_found — nonexistent session_id returns WP_Error not_found',
    is_wp_error( $r ) && $r->get_error_code() === 'not_found'
);

// Guard: make sure no lock leaked for nonexistent session.
_t(
    'not_found — no transient lock leaked',
    get_transient( 'hl_zoom_retry_lock_' . $bogus ) === false
);

// 3. already_has_meeting — row already has zoom_meeting_id.
$wpdb->insert( $wpdb->prefix . 'hl_coaching_session', array(
    'cycle_id'             => 1,
    'coach_user_id'        => 1,
    'mentor_enrollment_id' => 1,
    'session_title'        => 'TEST RETRY - already has meeting',
    'session_datetime'     => '2026-12-01 10:00:00',
    'session_status'       => 'scheduled',
    'zoom_meeting_id'      => 1234567890,
    'meeting_url'          => 'https://example.com/already-set',
) );
$session_id_with_meeting = (int) $wpdb->insert_id;

$r = $svc->retry_zoom_meeting( $session_id_with_meeting );
_t(
    'already_has_meeting — returns WP_Error already_has_meeting',
    is_wp_error( $r ) && $r->get_error_code() === 'already_has_meeting'
);

// Guard: no lock leaked on the already_has_meeting early-return path.
_t(
    'already_has_meeting — no transient lock leaked (early return before set_transient)',
    get_transient( 'hl_zoom_retry_lock_' . $session_id_with_meeting ) === false
);

$wpdb->delete( $wpdb->prefix . 'hl_coaching_session', array( 'session_id' => $session_id_with_meeting ), array( '%d' ) );

// 4. retry_inflight — lock held before the call.
$wpdb->insert( $wpdb->prefix . 'hl_coaching_session', array(
    'cycle_id'             => 1,
    'coach_user_id'        => 1,
    'mentor_enrollment_id' => 1,
    'session_title'        => 'TEST RETRY - inflight lock',
    'session_datetime'     => '2026-12-01 10:00:00',
    'session_status'       => 'scheduled',
    'zoom_meeting_id'      => null,
    'meeting_url'          => null,
) );
$session_id_locked = (int) $wpdb->insert_id;

set_transient( 'hl_zoom_retry_lock_' . $session_id_locked, 1, 60 );

$r = $svc->retry_zoom_meeting( $session_id_locked );
_t(
    'retry_inflight — held lock returns WP_Error retry_inflight',
    is_wp_error( $r ) && $r->get_error_code() === 'retry_inflight'
);

// Guard: our pre-set lock must still be there (the method must not clobber it).
_t(
    'retry_inflight — pre-existing lock preserved (not clobbered by finally)',
    (int) get_transient( 'hl_zoom_retry_lock_' . $session_id_locked ) === 1
);

delete_transient( 'hl_zoom_retry_lock_' . $session_id_locked );

// 5. zoom_not_configured — test env has broken creds so is_configured() is false.
//    (If prod ever runs this snippet with real creds, this assertion will flip
//     to "live Zoom call attempted" — which is the expected outcome on that env.)
$zoom = HL_Zoom_Integration::instance();
if ( ! $zoom->is_configured() ) {
    $r = $svc->retry_zoom_meeting( $session_id_locked );
    _t(
        'zoom_not_configured — returns WP_Error zoom_not_configured when creds missing',
        is_wp_error( $r ) && $r->get_error_code() === 'zoom_not_configured'
    );

    // Guard: try/finally must release the lock even on the zoom_not_configured return path.
    _t(
        'zoom_not_configured — transient lock released by finally',
        get_transient( 'hl_zoom_retry_lock_' . $session_id_locked ) === false
    );
} else {
    echo "SKIP: zoom_not_configured path (Zoom is_configured() returned true; live env).\n";
}

// Cleanup.
delete_transient( 'hl_zoom_retry_lock_' . $session_id_locked );
$wpdb->delete( $wpdb->prefix . 'hl_coaching_session', array( 'session_id' => $session_id_locked ), array( '%d' ) );

echo "DONE\n";
