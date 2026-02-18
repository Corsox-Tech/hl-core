<?php
/**
 * Normalization utility functions
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Normalization {
    
    /**
     * Normalize email
     */
    public static function normalize_email($email) {
        return strtolower(trim($email));
    }
    
    /**
     * Normalize string (trim, lowercase)
     */
    public static function normalize_string($str) {
        return strtolower(trim($str));
    }
    
    /**
     * Normalize classroom name
     */
    public static function normalize_classroom_name($name) {
        return trim($name);
    }
    
    /**
     * Generate code from name
     */
    public static function generate_code($name) {
        $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $name));
        return substr($code, 0, 100);
    }
}
