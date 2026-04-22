<?php
/**
 * Test snippet: HL_Zoom_Integration::build_meeting_payload() (Task D1)
 *
 * Verifies:
 *   - No-arg fallback path (admin defaults or hardcoded constants).
 *   - Resolved-settings flow through into the Zoom settings block.
 *   - alternative_hosts is omitted when empty / null.
 *   - password key is NEVER emitted (regardless of password_required).
 *   - auto_recording / auto_start_meeting_summary are NOT in payload.
 *   - mute_upon_entry + meeting_authentication keys are present.
 *
 * Run via:
 *   wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-build-payload.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    echo "Must run via wp eval-file\n";
    exit( 1 );
}

function _t( $label, $cond ) {
    echo ( $cond ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
}

$session = array(
    'mentor_name'    => 'Mentor X',
    'coach_name'     => 'Coach Y',
    'start_datetime' => '2026-05-01T10:00:00',
    'timezone'       => 'America/New_York',
    'duration'       => 30,
);

// 1. No second arg → falls back to admin defaults (or literal constants).
$p = HL_Zoom_Integration::instance()->build_meeting_payload( $session );
_t( 'no-arg fallback returns array', is_array( $p ) && isset( $p['settings'] ) );
_t( 'topic populated', $p['topic'] === 'Coaching Session - Mentor X/Coach Y' );
_t( 'type=2 scheduled meeting', $p['type'] === 2 );
_t( 'start_time passthrough', $p['start_time'] === '2026-05-01T10:00:00' );
_t( 'timezone passthrough', $p['timezone'] === 'America/New_York' );
_t( 'duration passthrough', $p['duration'] === 30 );
_t( 'no alternative_hosts when empty', ! isset( $p['settings']['alternative_hosts'] ) );
_t( 'no password key in payload', ! array_key_exists( 'password', $p ) );

// 1b. alt_hosts=null is also handled (not just empty string).
$p_null = HL_Zoom_Integration::instance()->build_meeting_payload( $session, array(
    'waiting_room'           => 1,
    'mute_upon_entry'        => 0,
    'join_before_host'       => 0,
    'alternative_hosts'      => null,
    'password_required'      => 0,
    'meeting_authentication' => 0,
) );
_t( 'alt_hosts=null also omits the key', ! isset( $p_null['settings']['alternative_hosts'] ) );

// 1c. Regression vs legacy hard-coded payload.
_t( 'auto_recording removed from payload', ! isset( $p['settings']['auto_recording'] ) );
_t( 'auto_start_meeting_summary NOT in payload', ! isset( $p['settings']['auto_start_meeting_summary'] ) );
_t( 'mute_upon_entry key added', array_key_exists( 'mute_upon_entry', $p['settings'] ) );
_t( 'meeting_authentication key added', array_key_exists( 'meeting_authentication', $p['settings'] ) );
_t( 'waiting_room key present', array_key_exists( 'waiting_room', $p['settings'] ) );
_t( 'join_before_host key present', array_key_exists( 'join_before_host', $p['settings'] ) );

// 2. Resolved settings flow through.
$resolved = array(
    'waiting_room'           => 0,
    'mute_upon_entry'        => 1,
    'join_before_host'       => 1,
    'alternative_hosts'      => 'a@b.com',
    'password_required'      => 1,
    'meeting_authentication' => 1,
);
$p = HL_Zoom_Integration::instance()->build_meeting_payload( $session, $resolved );
_t( 'waiting_room=false flows', $p['settings']['waiting_room'] === false );
_t( 'mute_upon_entry=true flows', $p['settings']['mute_upon_entry'] === true );
_t( 'join_before_host=true flows', $p['settings']['join_before_host'] === true );
_t( 'meeting_authentication=true flows', $p['settings']['meeting_authentication'] === true );
_t( 'alternative_hosts populated', $p['settings']['alternative_hosts'] === 'a@b.com' );
_t( 'password key still omitted (password_required=1)', ! array_key_exists( 'password', $p ) );

// 3. Dump an example resolved-settings → payload for visual inspection.
echo "\n--- Example resolved-settings → payload ---\n";
echo "resolved: " . wp_json_encode( $resolved ) . "\n";
echo "payload:  " . wp_json_encode( $p ) . "\n";

echo "\nDONE\n";
