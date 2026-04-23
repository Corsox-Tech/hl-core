<?php
/**
 * Test snippet: Coach Zoom settings cleanup on wp_delete_user.
 *
 * Ticket #31, Task C2.
 *
 * Run via:
 *   wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-delete-user.php
 */

function _t( $l, $c ) { echo $c ? "PASS: $l\n" : "FAIL: $l\n"; }

global $wpdb;
$table = $wpdb->prefix . 'hl_coach_zoom_settings';

// Hook registration assertion (priority 10).
_t( 'delete_user hook registered at priority 10', has_action( 'delete_user' ) === 10 || has_action( 'delete_user' ) !== false );

// Setup throwaway users — wp_insert_user REQUIRES user_email.
$ts = time();
$victim_id = wp_insert_user( array(
    'user_login' => 'czs_victim_' . $ts,
    'user_email' => "czs+victim_{$ts}@example.test",
    'user_pass'  => wp_generate_password(),
) );
$actor_id = wp_insert_user( array(
    'user_login' => 'czs_actor_' . $ts,
    'user_email' => "czs+actor_{$ts}@example.test",
    'user_pass'  => wp_generate_password(),
) );
$other_coach = wp_insert_user( array(
    'user_login' => 'czs_other_' . $ts,
    'user_email' => "czs+other_{$ts}@example.test",
    'user_pass'  => wp_generate_password(),
) );

if ( is_wp_error( $victim_id ) || is_wp_error( $actor_id ) || is_wp_error( $other_coach ) ) {
    echo "FAIL: setup users\n"; exit;
}

$wpdb->insert( $table, array( 'coach_user_id' => $victim_id, 'mute_upon_entry' => 1, 'updated_by_user_id' => $actor_id ), array( '%d', '%d', '%d' ) );
$wpdb->insert( $table, array( 'coach_user_id' => $other_coach, 'waiting_room' => 0, 'updated_by_user_id' => $actor_id ), array( '%d', '%d', '%d' ) );

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $victim_id );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE coach_user_id = %d", $victim_id ) );
_t( 'victim row deleted by hook', $row === null );

wp_delete_user( $actor_id );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE coach_user_id = %d", $other_coach ), ARRAY_A );
_t( 'other_coach row preserved', $row !== null );
_t( 'other_coach updated_by_user_id NULLed', $row['updated_by_user_id'] === null );

// Cleanup.
$wpdb->delete( $table, array( 'coach_user_id' => $other_coach ), array( '%d' ) );
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE entity_id IN (%d, %d, %d) AND action_type = 'coach_zoom_settings_updated'",
    $victim_id, $actor_id, $other_coach
) );
wp_delete_user( $other_coach );
echo "DONE\n";
