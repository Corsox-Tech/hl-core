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
     * Get cohort timezone (default America/Bogota)
     */
    public static function get_cohort_timezone($cohort_id = null) {
        if ($cohort_id) {
            global $wpdb;
            $timezone = $wpdb->get_var($wpdb->prepare(
                "SELECT timezone FROM {$wpdb->prefix}hl_cohort WHERE cohort_id = %d",
                $cohort_id
            ));
            if ($timezone) {
                return $timezone;
            }
        }
        return 'America/Bogota';
    }

    /**
     * Convert date to cohort timezone
     */
    public static function to_cohort_timezone($date, $cohort_id = null) {
        $timezone = self::get_cohort_timezone($cohort_id);
        $dt = new DateTime($date, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($timezone));
        return $dt;
    }

    /**
     * Current datetime in cohort timezone
     */
    public static function now($cohort_id = null) {
        $timezone = self::get_cohort_timezone($cohort_id);
        return new DateTime('now', new DateTimeZone($timezone));
    }
}
