<?php
/**
 * Age Group Helper — system-wide age range constants and calculation
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_Age_Group_Helper {

    /**
     * Get age range definitions.
     *
     * @return array Keyed by age group slug.
     */
    public static function get_age_ranges() {
        return array(
            'infant'    => array(
                'min_months'      => 0,
                'max_months'      => 11,
                'instrument_type' => 'children_infant',
                'label'           => 'Infant',
            ),
            'toddler'   => array(
                'min_months'      => 12,
                'max_months'      => 35,
                'instrument_type' => 'children_toddler',
                'label'           => 'Toddler',
            ),
            'preschool' => array(
                'min_months'      => 36,
                'max_months'      => 59,
                'instrument_type' => 'children_preschool',
                'label'           => 'Preschool',
            ),
            'k2'        => array(
                'min_months'      => 60,
                'max_months'      => null,
                'instrument_type' => 'children_k2',
                'label'           => 'K-2',
            ),
        );
    }

    /**
     * Calculate age in months from DOB.
     *
     * @param string      $dob            Date of birth (Y-m-d).
     * @param string|null $reference_date  Reference date (Y-m-d). Defaults to today.
     * @return int Age in months.
     */
    public static function calculate_age_months( $dob, $reference_date = null ) {
        $dob_dt = new DateTime( $dob );
        $ref_dt = $reference_date ? new DateTime( $reference_date ) : new DateTime();

        $diff = $dob_dt->diff( $ref_dt );

        return ( $diff->y * 12 ) + $diff->m;
    }

    /**
     * Calculate age group from DOB.
     *
     * @param string      $dob            Date of birth (Y-m-d).
     * @param string|null $reference_date  Reference date (Y-m-d). Defaults to today.
     * @return string Age group slug (infant, toddler, preschool, k2).
     */
    public static function calculate_age_group( $dob, $reference_date = null ) {
        $months = self::calculate_age_months( $dob, $reference_date );
        $ranges = self::get_age_ranges();

        foreach ( $ranges as $group => $range ) {
            if ( $months >= $range['min_months'] && ( $range['max_months'] === null || $months <= $range['max_months'] ) ) {
                return $group;
            }
        }

        // Fallback: 60+ months
        return 'k2';
    }

    /**
     * Get the instrument_type string for an age group.
     *
     * @param string $age_group Age group slug.
     * @return string|null Instrument type or null if invalid group.
     */
    public static function get_instrument_type_for_age_group( $age_group ) {
        $ranges = self::get_age_ranges();

        if ( isset( $ranges[ $age_group ] ) ) {
            return $ranges[ $age_group ]['instrument_type'];
        }

        return null;
    }

    /**
     * Get display label for an age group.
     *
     * @param string $age_group Age group slug.
     * @return string Display label.
     */
    public static function get_label( $age_group ) {
        $ranges = self::get_age_ranges();

        if ( isset( $ranges[ $age_group ] ) ) {
            return $ranges[ $age_group ]['label'];
        }

        return ucfirst( $age_group );
    }

    /**
     * Get all valid age group slugs.
     *
     * @return array
     */
    public static function get_valid_groups() {
        return array_keys( self::get_age_ranges() );
    }
}
