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
     * Generate a deterministic fingerprint for a child record.
     *
     * Used for duplicate detection: same (first, last, dob) → same fingerprint.
     *
     * @param string $first_name
     * @param string $last_name
     * @param string $dob Date of birth (Y-m-d).
     * @return string SHA-256 hex hash.
     */
    public static function child_fingerprint( $first_name, $last_name, $dob ) {
        $normalized = strtolower( trim( $first_name ) )
            . '|' . strtolower( trim( $last_name ) )
            . '|' . trim( $dob );
        return hash( 'sha256', $normalized );
    }

    /**
     * Generate code from name
     */
    public static function generate_code($name) {
        $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $name));
        return substr($code, 0, 100);
    }
}
