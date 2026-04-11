<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HL_Roles — shared role matching helper.
 *
 * Reads both legacy JSON (`["teacher","mentor"]`) and normalised CSV
 * (`teacher,mentor`) formats. All new writes go through sanitize_roles()
 * which emits CSV only. Rev 37 scrubs all existing rows to CSV.
 *
 * @package HL_Core
 */
class HL_Roles {

    /**
     * Option key flipped to '1' once Rev 37 role scrub completes.
     * Callers gate FIND_IN_SET usage on this.
     */
    const OPTION_SCRUB_DONE = 'hl_roles_scrub_done';

    /**
     * Parse a stored roles value (JSON array, CSV, or empty) into a clean
     * lowercase array of role slugs. Whitespace stripped, empty-string
     * entries removed. A JSON object (starting with `{`) is rejected and
     * yields an empty array.
     *
     * @param mixed $stored Raw value from hl_enrollment.roles.
     * @return string[]
     */
    public static function parse_stored( $stored ): array {
        if ( is_array( $stored ) ) {
            $arr = $stored;
        } elseif ( is_string( $stored ) && $stored !== '' ) {
            $trimmed = trim( $stored );
            if ( $trimmed === '' ) {
                return array();
            }
            if ( $trimmed[0] === '[' ) {
                // JSON array format.
                $decoded = json_decode( $trimmed, true );
                $arr     = is_array( $decoded ) ? $decoded : array();
            } elseif ( $trimmed[0] === '{' ) {
                // JSON object — not a valid role list.
                return array();
            } else {
                // CSV format.
                $arr = explode( ',', $trimmed );
            }
        } else {
            return array();
        }

        $out = array();
        foreach ( $arr as $role ) {
            if ( ! is_string( $role ) ) continue;
            $clean = strtolower( trim( $role ) );
            if ( $clean === '' ) continue;
            $out[] = $clean;
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Exact-match role check. Reads both JSON and CSV formats via
     * parse_stored(), so it never false-positives on substrings (the
     * canonical `LIKE '%leader%'` matching `school_leader` bug).
     *
     * @param mixed  $stored Raw value from hl_enrollment.roles.
     * @param string $role   Role slug to check.
     * @return bool
     */
    public static function has_role( $stored, string $role ): bool {
        $role = strtolower( trim( $role ) );
        if ( $role === '' ) return false;
        return in_array( $role, self::parse_stored( $stored ), true );
    }

    /**
     * Normalise a role set into canonical CSV suitable for direct storage
     * in `hl_enrollment.roles`. Strips whitespace, lowercases, dedupes,
     * drops any entry containing a comma (which would poison FIND_IN_SET
     * parsing), and sorts the surviving entries alphabetically so the
     * output is deterministic regardless of input order.
     *
     * @param string[]|string $roles Array of slugs, existing CSV, or JSON array.
     * @return string CSV. Empty string if input is empty or entirely invalid.
     */
    public static function sanitize_roles( $roles ): string {
        if ( is_string( $roles ) ) {
            $roles = self::parse_stored( $roles );
        }
        if ( ! is_array( $roles ) ) return '';

        $clean = array();
        foreach ( $roles as $r ) {
            if ( ! is_string( $r ) ) continue;
            $r = strtolower( trim( $r ) );
            if ( $r === '' ) continue;
            if ( strpos( $r, ',' ) !== false ) continue; // reject poison
            $clean[ $r ] = true;
        }
        $values = array_keys( $clean );
        sort( $values, SORT_STRING );
        return implode( ',', $values );
    }

    /**
     * Whether the Rev 37 role scrub has completed. Callers can gate
     * FIND_IN_SET usage on this.
     *
     * @return bool
     */
    public static function scrub_is_complete(): bool {
        return (bool) get_option( self::OPTION_SCRUB_DONE, 0 );
    }
}
