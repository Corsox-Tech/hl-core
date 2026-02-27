<?php
/**
 * WP-CLI command: wp hl-core nuke
 *
 * DESTRUCTIVE: Deletes ALL HL Core data — every row in every hl_* table,
 * all WP users created by seeders, and resets auto-increment counters.
 *
 * Safety gate: only runs on staging or local environments.
 * Requires --confirm="DELETE ALL DATA" flag.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_CLI_Nuke {

    /**
     * User meta keys used by seeders to tag created users.
     */
    private static $seeder_meta_keys = array(
        '_hl_demo_seed',
        '_hl_palm_beach_seed',
        '_hl_lutheran_seed',
    );

    /**
     * Register the WP-CLI command.
     */
    public static function register() {
        WP_CLI::add_command( 'hl-core nuke', array( new self(), 'run' ) );
    }

    /**
     * Delete ALL HL Core data. Staging/local only.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be exactly "DELETE ALL DATA" to proceed.
     *
     * [--include-instruments]
     * : Also truncate instrument tables (hl_instrument, hl_teacher_assessment_instrument).
     *   By default these are preserved so admin customizations survive nuke+reseed cycles.
     *
     * ## EXAMPLES
     *
     *     wp hl-core nuke --confirm="DELETE ALL DATA"
     *     wp hl-core nuke --confirm="DELETE ALL DATA" --include-instruments
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Named args.
     */
    public function run( $args, $assoc_args ) {
        // --- Safety Gate: environment check ---
        $site_url = get_site_url();
        if ( strpos( $site_url, 'staging.academy.housmanlearning.com' ) === false
            && strpos( $site_url, '.local' ) === false ) {
            WP_CLI::error( 'REFUSED: This command only runs on staging or local environments. Current URL: ' . $site_url );
            return;
        }

        // --- Safety Gate: confirmation flag ---
        if ( ! isset( $assoc_args['confirm'] ) || $assoc_args['confirm'] !== 'DELETE ALL DATA' ) {
            WP_CLI::error( 'This command requires --confirm="DELETE ALL DATA" to proceed. This will PERMANENTLY delete ALL HL Core data.' );
            return;
        }

        global $wpdb;

        WP_CLI::line( '' );
        WP_CLI::line( '=== HL Core Nuclear Clean ===' );
        WP_CLI::line( 'Site: ' . $site_url );
        WP_CLI::line( '' );

        // Step 1: Delete seeder-created WP users.
        $this->delete_seeder_users();

        // Step 2: Truncate all hl_* tables and reset auto-increment.
        $include_instruments = isset( $assoc_args['include-instruments'] );
        $this->truncate_tables( $include_instruments );

        // Step 3: Clean up HL Core user meta.
        $this->clean_user_meta();

        // Step 4: Clear HL Core transients.
        $this->clear_transients();

        WP_CLI::line( '' );
        WP_CLI::success( 'All HL Core data has been deleted. Tables are empty with auto-increment reset to 1.' );
    }

    /**
     * Delete all WP users tagged by any seeder meta key.
     * Protects user ID 1 and the current CLI user.
     */
    private function delete_seeder_users() {
        global $wpdb;

        WP_CLI::line( '--- Removing seeder-created WP users ---' );

        // Protect the current user running the command.
        $current_user_id = get_current_user_id();
        $protected_ids   = array( 1 );
        if ( $current_user_id > 0 ) {
            $protected_ids[] = $current_user_id;
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        $total_deleted = 0;

        foreach ( self::$seeder_meta_keys as $meta_key ) {
            $user_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
                $meta_key
            ) );

            if ( empty( $user_ids ) ) {
                continue;
            }

            $key_deleted = 0;
            foreach ( $user_ids as $user_id ) {
                $user_id = intval( $user_id );

                if ( in_array( $user_id, $protected_ids, true ) ) {
                    WP_CLI::warning( "Skipping user ID {$user_id} (protected)." );
                    continue;
                }

                $result = wp_delete_user( $user_id );
                if ( $result ) {
                    $key_deleted++;
                    $total_deleted++;
                } else {
                    WP_CLI::warning( "Failed to delete user ID {$user_id}." );
                }
            }

            WP_CLI::line( "  {$meta_key}: {$key_deleted} users deleted" );
        }

        WP_CLI::line( "  Total WP users deleted: {$total_deleted}" );
    }

    /**
     * Discover and truncate every hl_* table, with per-table row counts.
     * Uses dynamic SHOW TABLES discovery to catch any tables not in the hardcoded list.
     *
     * By default, instrument tables are preserved so admin customizations
     * (instructions, behavior keys, styles) survive nuke+reseed cycles.
     *
     * @param bool $include_instruments Whether to also truncate instrument tables.
     */
    private function truncate_tables( $include_instruments = false ) {
        global $wpdb;

        WP_CLI::line( '--- Truncating hl_* tables ---' );

        // Dynamically discover ALL hl_* tables in the database.
        $prefix  = $wpdb->prefix . 'hl_';
        $tables  = $wpdb->get_col( "SHOW TABLES LIKE '{$prefix}%'" );

        if ( empty( $tables ) ) {
            WP_CLI::line( '  No hl_* tables found.' );
            return;
        }

        // Instrument tables are skipped by default to preserve admin customizations.
        $skip_tables = array();
        if ( ! $include_instruments ) {
            $skip_tables = array(
                $wpdb->prefix . 'hl_instrument',
                $wpdb->prefix . 'hl_teacher_assessment_instrument',
            );
        }

        $total_rows = 0;
        $truncated  = 0;
        $skipped    = 0;

        foreach ( $tables as $full_table ) {
            $short_name = str_replace( $wpdb->prefix, '', $full_table );

            if ( in_array( $full_table, $skip_tables, true ) ) {
                $row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table}`" );
                WP_CLI::line( "  {$short_name}: SKIPPED ({$row_count} rows preserved — use --include-instruments to truncate)" );
                $skipped++;
                continue;
            }

            // Get row count before truncating.
            $row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table}`" );
            $total_rows += $row_count;

            // TRUNCATE resets auto-increment and is faster than DELETE.
            $wpdb->query( "TRUNCATE TABLE `{$full_table}`" );

            WP_CLI::line( "  {$short_name}: {$row_count} rows deleted" );
            $truncated++;
        }

        WP_CLI::line( "  ---" );
        WP_CLI::line( "  Tables truncated: {$truncated} | Skipped: {$skipped} | Total rows deleted: {$total_rows}" );
    }

    /**
     * Clean up any remaining HL Core user meta from non-seeder users.
     */
    private function clean_user_meta() {
        global $wpdb;

        WP_CLI::line( '--- Cleaning HL Core user meta ---' );

        $hl_meta_keys = array_merge(
            self::$seeder_meta_keys,
            array( '_hl_core_user' )
        );

        $deleted = 0;
        foreach ( $hl_meta_keys as $meta_key ) {
            $count = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
                $meta_key
            ) );
            $deleted += intval( $count );
        }

        WP_CLI::line( "  Removed {$deleted} HL Core user meta rows." );
    }

    /**
     * Clear all HL Core transients from wp_options.
     */
    private function clear_transients() {
        global $wpdb;

        WP_CLI::line( '--- Clearing HL Core transients ---' );

        $count = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_hl_%' OR option_name LIKE '_transient_timeout_hl_%'"
        );

        WP_CLI::line( "  Removed " . intval( $count ) . " transient rows." );
    }
}
