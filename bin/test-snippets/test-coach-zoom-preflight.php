<?php
/**
 * Task C1 test snippet: HL_Coach_Zoom_Settings_Service::preflight_alternative_hosts().
 *
 * Exercises the guard paths that do NOT hit the live Zoom API:
 *   1. Empty CSV -> immediate true.
 *   2. Inflight transient lock -> WP_Error preflight_inflight.
 *   3. Debounce transient hit -> immediate true.
 *
 * The live Zoom API path is covered by the separate manual smoke in the plan
 * (Step 4) — intentionally kept out of this snippet so `wp eval-file` does
 * not burn API quota on every run.
 *
 * Run on test:
 *   wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-preflight.php
 */

function _t( $l, $c ) { echo $c ? "PASS: $l\n" : "FAIL: $l\n"; }

delete_transient( 'hl_zoom_inflight_999999' );

// 1. Empty CSV -> immediate true (no Zoom call).
$r = HL_Coach_Zoom_Settings_Service::preflight_alternative_hosts( 999999, '' );
_t( 'empty CSV -> true', $r === true );

// 2. Inflight lock blocks concurrent calls.
set_transient( 'hl_zoom_inflight_999999', 1, 60 );
$r = HL_Coach_Zoom_Settings_Service::preflight_alternative_hosts( 999999, 'someone@example.com' );
_t( 'inflight lock -> WP_Error preflight_inflight',
    is_wp_error( $r ) && $r->get_error_code() === 'preflight_inflight' );
delete_transient( 'hl_zoom_inflight_999999' );

// 3. Debounce: pre-set the debounce transient and confirm we skip.
$csv = 'clove@housmanlearning.com';
set_transient( 'hl_zoom_alt_preflight_999999_' . md5( $csv ), 1, 60 );
$r = HL_Coach_Zoom_Settings_Service::preflight_alternative_hosts( 999999, $csv );
_t( 'debounce hit -> true (skip Zoom)', $r === true );
delete_transient( 'hl_zoom_alt_preflight_999999_' . md5( $csv ) );

echo "DONE\n";
