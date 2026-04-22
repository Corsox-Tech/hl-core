<?php
/**
 * Task E1b test snippet: HL_Scheduling_Email_Service::send_zoom_link_ready().
 *
 * Captures wp_mail in-process via the `pre_wp_mail` filter (returns true to
 * short-circuit real delivery) and asserts the email produced by
 * send_zoom_link_ready() has:
 *   1. Mentor as recipient (and only mentor — not coach).
 *   2. A subject distinct from the session_booked confirmation so the mentor
 *      doesn't dismiss it as a duplicate.
 *   3. A body that includes the coach name, the Zoom join URL, and the
 *      formatted session time.
 *   4. HTML Content-Type header.
 *
 * Run on test:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-link-ready.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file\n" );
    exit( 1 );
}

function _t( $label, $condition ) {
    echo $condition ? "PASS: $label\n" : "FAIL: $label\n";
}

// -------------------------------------------------------------------------
// Capture wp_mail in-process without touching the MTA.
// -------------------------------------------------------------------------
$GLOBALS['_hl_captured_mail'] = array();
$capture = function( $null, $atts ) {
    // Record then short-circuit. Returning a non-null truthy value bypasses
    // the real PHPMailer path entirely per wp_mail() contract.
    $GLOBALS['_hl_captured_mail'][] = $atts;
    return true;
};
add_filter( 'pre_wp_mail', $capture, 10, 2 );

// -------------------------------------------------------------------------
// Exercise the method with the same data shape retry_zoom_meeting() passes.
// -------------------------------------------------------------------------
$data = array(
    'mentor_name'      => 'Test Mentor',
    'mentor_email'     => 'mentor@example.com',
    'mentor_timezone'  => 'America/New_York',
    'coach_name'       => 'Test Coach',
    'coach_email'      => 'coach@example.com',
    'coach_timezone'   => 'America/New_York',
    'session_datetime' => '2026-05-01 10:00:00',
    'meeting_url'      => 'https://us02web.zoom.us/j/123456789',
);

HL_Scheduling_Email_Service::instance()->send_zoom_link_ready( $data );

remove_filter( 'pre_wp_mail', $capture, 10 );

// -------------------------------------------------------------------------
// Assertions.
// -------------------------------------------------------------------------
$sent = $GLOBALS['_hl_captured_mail'];

_t( 'exactly one email sent (mentor only, not coach)', count( $sent ) === 1 );

if ( count( $sent ) !== 1 ) {
    echo "DONE (aborted — no captured email to inspect)\n";
    return;
}

$mail = $sent[0];

// Recipient.
$to = is_array( $mail['to'] ) ? $mail['to'] : array( $mail['to'] );
_t(
    "recipient is mentor (mentor@example.com), got: " . implode( ',', $to ),
    in_array( 'mentor@example.com', $to, true )
);
_t(
    'coach is NOT a recipient of this email',
    ! in_array( 'coach@example.com', $to, true )
);

// Subject — must be distinct from the booking confirmation.
$subject = $mail['subject'];
_t(
    "subject exact match: [{$subject}]",
    $subject === 'Your Zoom link is ready for your coaching session'
);
_t(
    'subject is not a session_booked duplicate',
    stripos( $subject, 'new coaching session' ) === false
        && stripos( $subject, 'session scheduled' ) === false
        && stripos( $subject, 'booked' ) === false
);

// Body checks.
$body = $mail['message'];
_t( 'body contains mentor greeting "Hi Test Mentor,"', strpos( $body, 'Hi Test Mentor,' ) !== false );
_t( 'body references coach name (Test Coach)', strpos( $body, 'Test Coach' ) !== false );
_t(
    'body contains the Zoom join URL',
    strpos( $body, 'https://us02web.zoom.us/j/123456789' ) !== false
);
_t(
    'body contains a "Join Zoom Meeting" CTA button',
    strpos( $body, 'Join Zoom Meeting' ) !== false
);
_t(
    'body is not the "link coming shortly" fallback',
    strpos( $body, 'link will be sent shortly' ) === false
);
_t(
    'body includes the formatted session time (May 1, 2026)',
    strpos( $body, 'May 1, 2026' ) !== false
);

// Headers — must be HTML.
$headers = $mail['headers'];
$headers_str = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;
_t(
    'Content-Type header is text/html',
    stripos( $headers_str, 'text/html' ) !== false
);

echo "DONE\n";
