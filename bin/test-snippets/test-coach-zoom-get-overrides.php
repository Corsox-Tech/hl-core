<?php
/**
 * Coach Zoom Settings Service — get_coach_overrides() test snippet (Task B4).
 *
 * Run via:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-get-overrides.php
 *
 * Prints PASS/FAIL lines for each assertion. Any FAIL blocks the commit.
 *
 * Covers:
 *   1. No row       -> empty array (resolve_for_coach will fall back to defaults).
 *   2. Sparse row   -> only non-NULL columns present; _meta populated.
 *   3. Empty-string alternative_hosts preserved (distinct from NULL).
 *
 * Table-missing fallback is exercised by the manual Step 4 in the plan
 * (DROP TABLE on test, verify no fatal) — not asserted here.
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
$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );

// 1. No row -> empty array.
$o = HL_Coach_Zoom_Settings_Service::get_coach_overrides( 999999 );
_t( 'no row -> []', $o === array() );

// 2. Insert sparse row (only mute_upon_entry overridden).
$wpdb->insert( $table, array(
    'coach_user_id'      => 999999,
    'mute_upon_entry'    => 1,
    'updated_by_user_id' => 1,
), array( '%d', '%d', '%d' ) );

$o = HL_Coach_Zoom_Settings_Service::get_coach_overrides( 999999 );
_t( 'sparse row: mute=1', isset( $o['mute_upon_entry'] ) && $o['mute_upon_entry'] === 1 );
_t( 'sparse row: waiting_room not set', ! isset( $o['waiting_room'] ) );
_t( 'sparse row: alt_hosts not set (NULL)', ! isset( $o['alternative_hosts'] ) );
_t( 'sparse row: _meta.updated_by_user_id=1', isset( $o['_meta']['updated_by_user_id'] ) && $o['_meta']['updated_by_user_id'] === 1 );

// 3. Empty-string alt_hosts preserved (distinct from NULL).
$wpdb->update( $table, array( 'alternative_hosts' => '' ), array( 'coach_user_id' => 999999 ) );
$o = HL_Coach_Zoom_Settings_Service::get_coach_overrides( 999999 );
_t( 'empty-string alt_hosts preserved', isset( $o['alternative_hosts'] ) && $o['alternative_hosts'] === '' );

$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );
echo "DONE\n";
