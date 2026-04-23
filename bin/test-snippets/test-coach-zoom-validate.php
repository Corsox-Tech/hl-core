<?php
/**
 * Coach Zoom Settings Service — validate() test snippet (Task B2).
 *
 * Run via:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-validate.php
 *
 * Prints PASS/FAIL lines for each assertion. Any FAIL blocks the commit.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file\n" );
    exit( 1 );
}

function _test_assert( $label, $condition ) {
    echo $condition ? "PASS: $label\n" : "FAIL: $label\n";
}

// 1. Bool coercion
$out = HL_Coach_Zoom_Settings_Service::validate( array( 'waiting_room' => 'yes' ), 0 );
_test_assert( 'bool coercion: truthy string -> 1', is_array( $out ) && $out['waiting_room'] === 1 );

$out = HL_Coach_Zoom_Settings_Service::validate( array( 'waiting_room' => '0' ), 0 );
_test_assert( 'bool coercion: "0" -> 0', is_array( $out ) && $out['waiting_room'] === 0 );

// 2. waiting_room + join_before_host conflict normalization
$out = HL_Coach_Zoom_Settings_Service::validate(
    array( 'waiting_room' => 1, 'join_before_host' => 1 ), 0
);
_test_assert( 'waiting_room=1 AND join_before_host=1 -> jbh=0',
    is_array( $out ) && $out['waiting_room'] === 1 && $out['join_before_host'] === 0 );

// 3. alt_hosts: invalid email rejected with structured error_data
$out = HL_Coach_Zoom_Settings_Service::validate(
    array( 'alternative_hosts' => 'good@example.com, not-an-email' ), 0
);
_test_assert( 'invalid alt_hosts -> WP_Error', is_wp_error( $out ) );
_test_assert( 'invalid alt_hosts -> error_data.field == alternative_hosts',
    is_wp_error( $out )
    && ($d = $out->get_error_data()) && isset( $d['field'] ) && $d['field'] === 'alternative_hosts' );
_test_assert( 'invalid alt_hosts -> error_data.invalid_emails listed',
    is_wp_error( $out )
    && ($d = $out->get_error_data()) && in_array( 'not-an-email', $d['invalid_emails'], true ) );

// 4. alt_hosts > 10 addresses rejected
$many = implode( ',', array_map( function( $i ) { return "u$i@e.com"; }, range( 1, 11 ) ) );
$out = HL_Coach_Zoom_Settings_Service::validate( array( 'alternative_hosts' => $many ), 0 );
_test_assert( 'alt_hosts > 10 -> WP_Error', is_wp_error( $out ) && $out->get_error_code() === 'too_many_alternative_hosts' );

// 5. alt_hosts > 1024 chars rejected
$long = str_repeat( 'a', 1020 ) . '@e.com';
$out = HL_Coach_Zoom_Settings_Service::validate( array( 'alternative_hosts' => $long ), 0 );
_test_assert( 'alt_hosts > 1024 chars -> WP_Error', is_wp_error( $out ) && $out->get_error_code() === 'alternative_hosts_too_long' );

// 6. Empty string preserved (distinct from NULL)
$out = HL_Coach_Zoom_Settings_Service::validate( array( 'alternative_hosts' => '' ), 0 );
_test_assert( 'empty alt_hosts preserved', is_array( $out ) && $out['alternative_hosts'] === '' );

echo "DONE\n";
