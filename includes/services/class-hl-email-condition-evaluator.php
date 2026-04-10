<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Condition Evaluator
 *
 * Evaluates a JSON conditions array against a pre-populated context.
 * All conditions are ANDed (no OR logic in v1). No DB lookups — the
 * automation service owns context hydration.
 *
 * Supported operators: eq, neq, in, not_in, gt, lt, is_null, not_null.
 *
 * @package HL_Core
 */
class HL_Email_Condition_Evaluator {

    /** @var self|null */
    private static $instance = null;

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
     * Evaluate all conditions against context. All must pass (AND logic).
     *
     * @param array $conditions Array of condition objects from workflow JSON.
     *                          Each: { field: string, op: string, value: mixed }
     * @param array $context    Pre-populated context array.
     * @return bool True if all conditions pass (or conditions is empty).
     */
    public function evaluate( array $conditions, array $context ) {
        if ( empty( $conditions ) ) {
            return true;
        }

        foreach ( $conditions as $condition ) {
            if ( ! is_array( $condition ) ) {
                continue;
            }
            if ( ! $this->evaluate_single( $condition, $context ) ) {
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Evaluate a single condition against context.
     *
     * @param array $condition { field, op, value }
     * @param array $context   Pre-populated context.
     * @return bool
     */
    private function evaluate_single( array $condition, array $context ) {
        $field = $condition['field'] ?? '';
        $op    = $condition['op']    ?? '';
        $value = $condition['value'] ?? null;

        // Resolve the field value from context using dot notation.
        $actual = $this->resolve_field( $field, $context );

        switch ( $op ) {
            case 'eq':
                return $this->loose_equals( $actual, $value );

            case 'neq':
                return ! $this->loose_equals( $actual, $value );

            case 'in':
                $haystack = is_array( $value ) ? $value : array( $value );
                return in_array( (string) $actual, array_map( 'strval', $haystack ), true );

            case 'not_in':
                $haystack = is_array( $value ) ? $value : array( $value );
                return ! in_array( (string) $actual, array_map( 'strval', $haystack ), true );

            case 'gt':
                return is_numeric( $actual ) && is_numeric( $value ) && (float) $actual > (float) $value;

            case 'lt':
                return is_numeric( $actual ) && is_numeric( $value ) && (float) $actual < (float) $value;

            case 'is_null':
                return $actual === null || $actual === '';

            case 'not_null':
                return $actual !== null && $actual !== '';

            default:
                return false;
        }
    }

    /**
     * Resolve a dotted field path from the context array.
     * Example: "cycle.cycle_type" => $context['cycle']['cycle_type']
     *          or $context['cycle.cycle_type'] (flat key fallback).
     *
     * @param string $field   Dotted field path.
     * @param array  $context Context array.
     * @return mixed|null
     */
    private function resolve_field( $field, array $context ) {
        // Try flat key first (e.g., "cycle.cycle_type" as literal key).
        if ( array_key_exists( $field, $context ) ) {
            return $context[ $field ];
        }

        // Try dot-path traversal.
        $parts   = explode( '.', $field, 2 );
        $prefix  = $parts[0] ?? '';
        $sub_key = $parts[1] ?? '';

        if ( $sub_key !== '' && isset( $context[ $prefix ] ) && is_array( $context[ $prefix ] ) ) {
            return $context[ $prefix ][ $sub_key ] ?? null;
        }

        return null;
    }

    /**
     * Loose equality: cast both sides to string for comparison.
     * Handles bool/int/string comparisons from JSON data.
     *
     * @param mixed $a Actual value.
     * @param mixed $b Expected value.
     * @return bool
     */
    private function loose_equals( $a, $b ) {
        // Handle boolean comparisons.
        if ( is_bool( $b ) ) {
            if ( is_bool( $a ) ) {
                return $a === $b;
            }
            // Treat "1"/"true"/1 as true, "0"/"false"/0/"" as false.
            $a_bool = filter_var( $a, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
            return $a_bool === $b;
        }

        return (string) $a === (string) $b;
    }
}
