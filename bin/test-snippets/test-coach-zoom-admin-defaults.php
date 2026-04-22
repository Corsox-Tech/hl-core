<?php
/**
 * Coach Zoom Settings Service — admin defaults get/save test snippet (Task B3).
 *
 * Run via:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-admin-defaults.php
 *
 * Prints PASS/FAIL lines for each assertion. Any FAIL blocks the commit.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file\n" );
    exit( 1 );
}

function _t( $label, $condition ) {
    echo $condition ? "PASS: $label\n" : "FAIL: $label\n";
}

// Clean slate.
delete_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY );

// 1. get without stored value returns DEFAULTS.
$d = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
_t( 'no option -> DEFAULTS', $d === HL_Coach_Zoom_Settings_Service::DEFAULTS );

// 2. save -> get round-trips.
$ok = HL_Coach_Zoom_Settings_Service::save_admin_defaults( array( 'mute_upon_entry' => 1 ), 1 );
_t( 'save returns true', $ok === true );

$d = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
_t( 'after save: mute_upon_entry=1', (int) $d['mute_upon_entry'] === 1 );
_t( 'after save: other defaults preserved (waiting_room=1)', (int) $d['waiting_room'] === 1 );

// 3. Bool coercion persists: save with 'yes' -> reads back as 1.
delete_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY );
$ok = HL_Coach_Zoom_Settings_Service::save_admin_defaults( array( 'mute_upon_entry' => 'yes' ), 1 );
_t( 'bool coercion save returns true', $ok === true );
$d = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
_t( 'bool coercion: "yes" persists as 1', $d['mute_upon_entry'] === 1 );

// 4. Admin-only fields (password_required, meeting_authentication) persist.
$ok = HL_Coach_Zoom_Settings_Service::save_admin_defaults(
    array( 'password_required' => 1, 'meeting_authentication' => 1 ), 1
);
_t( 'admin-only save returns true', $ok === true );
$d = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
_t( 'admin-only: password_required=1', $d['password_required'] === 1 );
_t( 'admin-only: meeting_authentication=1', $d['meeting_authentication'] === 1 );

// 5. Invalid input rejected (validate() passthrough).
$err = HL_Coach_Zoom_Settings_Service::save_admin_defaults(
    array( 'alternative_hosts' => 'not-an-email' ), 1
);
_t( 'invalid save -> WP_Error', is_wp_error( $err ) );
_t( 'invalid save error code -> invalid_alternative_hosts',
    is_wp_error( $err ) && $err->get_error_code() === 'invalid_alternative_hosts' );

// 6. wp_parse_args merges new/missing keys (forward compat).
update_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY, array( 'waiting_room' => 0 ) );
$d = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
_t( 'partial stored merges with DEFAULTS',
    $d['waiting_room'] === 0 && $d['mute_upon_entry'] === 0 && $d['join_before_host'] === 0 );

// 7. Audit log entry is written on save.
global $wpdb;
delete_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY );
$before_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}hl_audit_log WHERE action_type = 'coach_zoom_defaults_updated'"
);
HL_Coach_Zoom_Settings_Service::save_admin_defaults( array( 'mute_upon_entry' => 1 ), 1 );
$after_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}hl_audit_log WHERE action_type = 'coach_zoom_defaults_updated'"
);
_t( 'audit log entry written on save', $after_count === $before_count + 1 );

$last = $wpdb->get_row(
    "SELECT * FROM {$wpdb->prefix}hl_audit_log
     WHERE action_type = 'coach_zoom_defaults_updated'
     ORDER BY log_id DESC LIMIT 1",
    ARRAY_A
);
_t( 'audit log entity_type = coach_zoom_defaults',
    $last && $last['entity_type'] === 'coach_zoom_defaults' );
$after_data = $last ? json_decode( $last['after_data'], true ) : null;
_t( 'audit log after_data.diff has mute_upon_entry change',
    is_array( $after_data )
    && isset( $after_data['diff']['mute_upon_entry']['before'] )
    && isset( $after_data['diff']['mute_upon_entry']['after'] )
    && (int) $after_data['diff']['mute_upon_entry']['before'] === 0
    && (int) $after_data['diff']['mute_upon_entry']['after']  === 1 );

// Cleanup.
delete_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY );
echo "DONE\n";
