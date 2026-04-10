<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Rate Limit Service
 *
 * Per-user hourly/daily/weekly send rate limiting. Uses floor-aligned
 * time buckets stored in hl_email_rate_limit. Known tradeoff: a burst
 * at an hour boundary can send up to 2x the hourly limit across 2
 * buckets — acceptable for a safety net, not a hard guarantee.
 *
 * @package HL_Core
 */
class HL_Email_Rate_Limit_Service {

    /** @var self|null */
    private static $instance = null;

    /** Default limits (overridden by wp_options). */
    const DEFAULT_HOURLY  = 5;
    const DEFAULT_DAILY   = 20;
    const DEFAULT_WEEKLY  = 50;

    /** @return self */
    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Check if a user is under all rate limits.
     *
     * @param int $user_id WordPress user ID.
     * @return bool True if sending is allowed.
     */
    public function check( $user_id ) {
        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_rate_limit";

        $windows = $this->get_windows();

        foreach ( $windows as $window_key => $limit ) {
            $window_start = $this->get_window_start( $window_key );
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT send_count FROM {$table} WHERE user_id = %d AND window_key = %s AND window_start = %s",
                $user_id,
                $window_key,
                $window_start
            ) );

            if ( $count >= $limit ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Increment the send count for a user across all windows.
     *
     * @param int $user_id WordPress user ID.
     */
    public function increment( $user_id ) {
        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_rate_limit";

        $windows = $this->get_windows();

        foreach ( $windows as $window_key => $limit ) {
            $window_start = $this->get_window_start( $window_key );

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$table} (user_id, window_key, window_start, send_count)
                 VALUES (%d, %s, %s, 1)
                 ON DUPLICATE KEY UPDATE send_count = send_count + 1",
                $user_id,
                $window_key,
                $window_start
            ) );
        }
    }

    /**
     * Get the configured rate limits.
     *
     * @return array Window key => limit.
     */
    public function get_limits() {
        return $this->get_windows();
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Get window keys and their limits from wp_options (with defaults).
     *
     * @return array Window key => integer limit.
     */
    private function get_windows() {
        return array(
            'hourly'  => (int) get_option( 'hl_email_rate_limit_hour',  self::DEFAULT_HOURLY ),
            'daily'   => (int) get_option( 'hl_email_rate_limit_day',   self::DEFAULT_DAILY ),
            'weekly'  => (int) get_option( 'hl_email_rate_limit_week',  self::DEFAULT_WEEKLY ),
        );
    }

    /**
     * Compute the floor-aligned window start for a given key.
     *
     * @param string $window_key 'hourly', 'daily', or 'weekly'.
     * @return string MySQL datetime string (UTC).
     */
    private function get_window_start( $window_key ) {
        $now = new DateTime( 'now', new DateTimeZone( 'UTC' ) );

        switch ( $window_key ) {
            case 'hourly':
                // Floor to top of current hour.
                return $now->format( 'Y-m-d H:00:00' );

            case 'daily':
                // Floor to midnight UTC.
                return $now->format( 'Y-m-d 00:00:00' );

            case 'weekly':
                // Floor to Monday midnight UTC.
                $day_of_week = (int) $now->format( 'N' ); // 1=Mon, 7=Sun
                $days_since_monday = $day_of_week - 1;
                if ( $days_since_monday > 0 ) {
                    $now->modify( "-{$days_since_monday} days" );
                }
                return $now->format( 'Y-m-d 00:00:00' );

            default:
                return $now->format( 'Y-m-d H:00:00' );
        }
    }
}
