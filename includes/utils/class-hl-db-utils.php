<?php
/**
 * Database utility functions
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_DB_Utils {
    
    /**
     * Generate UUID v4
     */
    public static function generate_uuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Safe JSON encode
     */
    public static function json_encode($data) {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Safe JSON decode
     */
    public static function json_decode($json, $assoc = true) {
        if (empty($json)) {
            return $assoc ? array() : null;
        }
        return json_decode($json, $assoc);
    }
}
