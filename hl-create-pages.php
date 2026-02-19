<?php
/**
 * One-time script to create the five HL Core shortcode pages.
 *
 * Run via browser (logged in as admin):
 *   https://your-site.com/wp-content/plugins/hl-core/hl-create-pages.php
 *
 * Delete this file after use.
 */
require_once __DIR__ . '/../../../wp-load.php';

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'You must be logged in as an administrator.' );
}

header( 'Content-Type: text/plain; charset=utf-8' );

$pages = array(
    array( 'title' => 'My Programs',      'shortcode' => 'hl_my_programs' ),
    array( 'title' => 'My Coaching',       'shortcode' => 'hl_my_coaching' ),
    array( 'title' => 'My Cohort',         'shortcode' => 'hl_my_cohort' ),
    array( 'title' => 'School Districts',  'shortcode' => 'hl_districts_listing' ),
    array( 'title' => 'Institutions',      'shortcode' => 'hl_centers_listing' ),
);

echo "HL Core — Creating shortcode pages\n";
echo str_repeat( '=', 40 ) . "\n\n";

foreach ( $pages as $page ) {
    $shortcode = '[' . $page['shortcode'] . ']';

    // Check if a published page with this shortcode already exists.
    global $wpdb;
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
        '%[' . $wpdb->esc_like( $page['shortcode'] ) . '%'
    ) );

    if ( $exists ) {
        echo "[SKIP] \"{$page['title']}\" — already exists (page ID {$exists})\n";
        continue;
    }

    $id = wp_insert_post( array(
        'post_title'   => $page['title'],
        'post_content' => $shortcode,
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ), true );

    if ( is_wp_error( $id ) ) {
        echo "[FAIL] \"{$page['title']}\" — {$id->get_error_message()}\n";
    } else {
        $url = get_permalink( $id );
        echo "[OK]   \"{$page['title']}\" — page ID {$id} — {$url}\n";
    }
}

echo "\nDone. Delete this file from the server and remove from git.\n";
