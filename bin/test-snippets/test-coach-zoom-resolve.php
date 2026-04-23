<?php
/**
 * Coach Zoom Settings Service — resolve_for_coach() test snippet (Task B6).
 *
 * Run via:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-resolve.php
 *
 * Prints PASS/FAIL lines for each assertion. Any FAIL blocks the commit.
 *
 * Covers the 3-tier fallback:
 *   1. No admin option, no coach row -> DEFAULTS.
 *   2. Admin default set, no coach row -> admin values over DEFAULTS.
 *   3. Sparse coach override -> that field wins, others come from admin/DEFAULTS.
 *   4. Empty-string alternative_hosts override wins over admin's non-empty value.
 *   5. Admin-only fields (password_required, meeting_authentication) flow through
 *      from tier 2 even when coach has overrides.
 *   6. Return shape is fully-populated with DEFAULTS keys and contains no `_meta`.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file\n" );
    exit( 1 );
}

function _t( $label, $condition ) {
    echo $condition ? "PASS: $label\n" : "FAIL: $label\n";
}

global $wpdb;
$table = $wpdb->prefix . 'hl_coach_zoom_settings';

// Clean slate.
delete_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY );
$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );

// 1. No option, no override -> DEFAULTS.
$r = HL_Coach_Zoom_Settings_Service::resolve_for_coach( 999999 );
_t( 'no option, no row -> DEFAULTS', $r['waiting_room'] === 1 && $r['alternative_hosts'] === '' );

// Return shape: no _meta, all DEFAULTS keys present.
_t( 'resolved array contains no _meta key', ! array_key_exists( '_meta', $r ) );
_t(
    'resolved array has all DEFAULTS keys',
    array_keys( HL_Coach_Zoom_Settings_Service::DEFAULTS ) === array_keys( $r )
);

// 2. Admin default set.
HL_Coach_Zoom_Settings_Service::save_admin_defaults(
    array( 'mute_upon_entry' => 1, 'alternative_hosts' => 'a@b.com' ),
    1
);
$r = HL_Coach_Zoom_Settings_Service::resolve_for_coach( 999999 );
_t( 'admin default applied', $r['mute_upon_entry'] === 1 && $r['alternative_hosts'] === 'a@b.com' );

// 3. Coach override (sparse): only waiting_room.
HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'waiting_room' => 0 ), 1 );
$r = HL_Coach_Zoom_Settings_Service::resolve_for_coach( 999999 );
_t( 'override wins on overridden field', $r['waiting_room'] === 0 );
_t( 'admin default preserved on non-overridden', $r['mute_upon_entry'] === 1 );
_t( 'admin alt_hosts preserved on non-overridden', $r['alternative_hosts'] === 'a@b.com' );

// 5. Admin-only fields flow through from tier 2 even with coach overrides present.
HL_Coach_Zoom_Settings_Service::save_admin_defaults(
    array( 'password_required' => 1, 'meeting_authentication' => 1 ),
    1
);
$r = HL_Coach_Zoom_Settings_Service::resolve_for_coach( 999999 );
_t( 'admin-only password_required flows through', $r['password_required'] === 1 );
_t( 'admin-only meeting_authentication flows through', $r['meeting_authentication'] === 1 );

// 4. Empty-string alt_hosts override wins over admin's non-empty.
HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'alternative_hosts' => '' ), 1 );
$r = HL_Coach_Zoom_Settings_Service::resolve_for_coach( 999999 );
_t( 'empty-string alt_hosts override wins', $r['alternative_hosts'] === '' );

// Mixed provenance report (for human eyeball in logs).
echo "\nMixed-provenance resolved output:\n";
echo "  waiting_room           = {$r['waiting_room']}           (coach override)\n";
echo "  mute_upon_entry        = {$r['mute_upon_entry']}        (admin default)\n";
echo "  join_before_host       = {$r['join_before_host']}       (DEFAULTS via admin merge)\n";
echo "  alternative_hosts      = '{$r['alternative_hosts']}'     (coach override — empty string)\n";
echo "  password_required      = {$r['password_required']}      (admin-only; tier 2)\n";
echo "  meeting_authentication = {$r['meeting_authentication']} (admin-only; tier 2)\n";

// Cleanup.
delete_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY );
$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE entity_id = %d", 999999 ) );
echo "DONE\n";
