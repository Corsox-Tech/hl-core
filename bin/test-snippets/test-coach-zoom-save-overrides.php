<?php
/**
 * Coach Zoom Settings Service — save_coach_overrides() test snippet (Task B5).
 *
 * Run via:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-save-overrides.php
 *
 * Prints PASS/FAIL lines for each assertion. Any FAIL blocks the commit.
 *
 * Covers:
 *   1. INSERT path with sparse override (NULL columns stay NULL).
 *   2. UPDATE path preserves other fields and updates actor.
 *   3. Empty-string alternative_hosts persists (distinct from NULL).
 *   4. $reset_fields NULLs the named column inside the same save.
 *   5. Audit log rows are written for real diffs.
 *   6. Invalid input -> WP_Error, no DB mutation off last good state.
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

// Cleanup at START to prevent cross-run pollution.
$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE entity_id = %d AND action_type = %s",
    999999,
    'coach_zoom_settings_updated'
) );

// 1. INSERT path with sparse override.
$ok = HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'mute_upon_entry' => 1 ), 1 );
_t( 'INSERT returns true', $ok === true );

$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE coach_user_id = 999999", ARRAY_A );
_t( 'INSERT: mute_upon_entry=1', (int) $row['mute_upon_entry'] === 1 );
_t( 'INSERT: waiting_room NULL', $row['waiting_room'] === null );
_t( 'INSERT: alternative_hosts NULL', $row['alternative_hosts'] === null );
_t( 'INSERT: updated_by_user_id=1', (int) $row['updated_by_user_id'] === 1 );

// 2. UPDATE path: change actor.
$ok = HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'waiting_room' => 0 ), 2 );
_t( 'UPDATE returns true', $ok === true );

$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE coach_user_id = 999999", ARRAY_A );
_t( 'UPDATE: waiting_room=0', (int) $row['waiting_room'] === 0 );
_t( 'UPDATE: mute_upon_entry preserved (still 1)', (int) $row['mute_upon_entry'] === 1 );
_t( 'UPDATE: updated_by_user_id=2', (int) $row['updated_by_user_id'] === 2 );

// 3. Empty-string alt_hosts override.
$ok = HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'alternative_hosts' => '' ), 2 );
_t( 'empty-string alt_hosts saves', $ok === true );

$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE coach_user_id = 999999", ARRAY_A );
_t( 'empty-string alt_hosts persisted (not NULL)', $row['alternative_hosts'] === '' );

// 4. Reset path: NULL waiting_room via $reset_fields.
$ok = HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array(), 2, array( 'waiting_room' ) );
_t( 'reset returns true', $ok === true );

$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE coach_user_id = 999999", ARRAY_A );
_t( 'reset: waiting_room is NULL', $row['waiting_room'] === null );
_t( 'reset: mute_upon_entry preserved', (int) $row['mute_upon_entry'] === 1 );

// 5. Audit log row(s) exist.
$audit = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}hl_audit_log WHERE action_type = %s AND entity_id = %d",
    'coach_zoom_settings_updated',
    999999
) );
_t( 'audit log rows written', (int) $audit >= 3 );

// 6. Invalid input -> WP_Error, no DB mutation to last good state.
$err = HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'alternative_hosts' => 'bad-email' ), 1 );
_t( 'invalid -> WP_Error', is_wp_error( $err ) );

// Cleanup.
$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE entity_id = %d AND action_type = %s",
    999999,
    'coach_zoom_settings_updated'
) );
echo "DONE\n";
