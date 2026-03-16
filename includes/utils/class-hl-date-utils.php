<?php
/**
 * Date utility functions
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Date_Utils {
    
    /**
     * Get partnership timezone (default America/Bogota)
     */
    public static function get_partnership_timezone($partnership_id = null) {
        if ($partnership_id) {
            global $wpdb;
            $timezone = $wpdb->get_var($wpdb->prepare(
                "SELECT timezone FROM {$wpdb->prefix}hl_partnership WHERE partnership_id = %d",
                $partnership_id
            ));
            if ($timezone) {
                return $timezone;
            }
        }
        return 'America/Bogota';
    }

    /**
     * Convert date to partnership timezone
     */
    public static function to_partnership_timezone($date, $partnership_id = null) {
        $timezone = self::get_partnership_timezone($partnership_id);
        $dt = new DateTime($date, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($timezone));
        return $dt;
    }

    /**
     * Current datetime in partnership timezone
     */
    public static function now($partnership_id = null) {
        $timezone = self::get_partnership_timezone($partnership_id);
        return new DateTime('now', new DateTimeZone($timezone));
    }
}
