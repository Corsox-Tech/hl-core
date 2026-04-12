<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rev 37 chunked role scrub.
 *
 * Walks hl_enrollment.enrollment_id ascending, 500 rows per plugins_loaded
 * firing (5000 on first run if backlog > 5000), converting each `roles`
 * column from JSON array format to canonical CSV via HL_Roles::sanitize_roles().
 *
 * State:
 *   wp_option hl_roles_scrub_cursor (last enrollment_id processed)
 *   wp_option hl_roles_scrub_done   (1 when complete)
 *   transient hl_roles_scrub_lock   (60s, prevents concurrent chunks)
 */
class HL_Roles_Scrub_Migration {

    const CHUNK_SIZE       = 500;
    const FIRST_RUN_CHUNK  = 5000;
    const LOCK_KEY         = 'hl_roles_scrub_lock';
    const CURSOR_KEY       = 'hl_roles_scrub_cursor';
    const DONE_KEY         = 'hl_roles_scrub_done';

    public static function register() {
        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_run' ), 20 );
    }

    public static function maybe_run() {
        if ( get_option( self::DONE_KEY, 0 ) ) return;

        // Only run for admins / wp-cli — never on a visitor's request path.
        if ( ! is_admin() && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) return;

        if ( get_transient( self::LOCK_KEY ) ) return;
        set_transient( self::LOCK_KEY, 1, 60 );

        try {
            self::run_chunk();
        } catch ( \Throwable $e ) {
            error_log( '[HL_ROLES_SCRUB] ' . $e->getMessage() );
        }

        delete_transient( self::LOCK_KEY );
    }

    public static function run_chunk() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_enrollment';

        $cursor = (int) get_option( self::CURSOR_KEY, 0 );

        // Backlog-aware chunk sizing on first run.
        $chunk = self::CHUNK_SIZE;
        if ( $cursor === 0 ) {
            $total_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE roles LIKE '[%' OR roles LIKE '% %'" );
            if ( $total_pending > self::CHUNK_SIZE ) {
                $chunk = min( $total_pending, self::FIRST_RUN_CHUNK );
            }
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT enrollment_id, roles FROM {$table}
             WHERE enrollment_id > %d
             ORDER BY enrollment_id ASC
             LIMIT %d",
            $cursor, $chunk
        ) );

        if ( empty( $rows ) ) {
            update_option( self::DONE_KEY, 1, false );
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_roles_scrub_complete', array(
                    'reason' => 'cursor_end',
                ) );
            }
            return;
        }

        $wpdb->query( 'START TRANSACTION' );

        $max_id   = $cursor;
        $rewrites = 0;

        foreach ( $rows as $row ) {
            $enrollment_id = (int) $row->enrollment_id;
            $max_id        = max( $max_id, $enrollment_id );

            $normalized = HL_Roles::sanitize_roles( $row->roles );
            if ( $normalized === (string) $row->roles ) {
                // Already canonical.
                continue;
            }

            $res = $wpdb->update(
                $table,
                array( 'roles' => $normalized ),
                array( 'enrollment_id' => $enrollment_id ),
                array( '%s' ),
                array( '%d' )
            );
            if ( $res === false ) {
                $wpdb->query( 'ROLLBACK' );
                error_log( '[HL_ROLES_SCRUB] UPDATE failed at enrollment_id=' . $enrollment_id );
                return;
            }
            $rewrites++;
        }

        $wpdb->query( 'COMMIT' );
        update_option( self::CURSOR_KEY, $max_id, false );

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_roles_scrub_chunk', array(
                'reason' => sprintf( 'chunk=%d rewrites=%d next_cursor=%d', count( $rows ), $rewrites, $max_id ),
            ) );
        }
    }
}
