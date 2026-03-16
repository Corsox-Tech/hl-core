<?php
/**
 * Child Snapshot Service — freezes age groups per child per cycle
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_Child_Snapshot_Service {

    /**
     * Freeze age groups for all children in classrooms linked to a cycle.
     *
     * Skips children that already have a snapshot for this cycle.
     *
     * @param int         $cycle_id       Cycle ID.
     * @param string|null $reference_date Reference date (Y-m-d) for age calculation. Defaults to today.
     * @return int Count of newly frozen snapshots.
     */
    public static function freeze_age_groups( $cycle_id, $reference_date = null ) {
        global $wpdb;

        // Get all children in classrooms linked to this cycle via teaching assignments.
        $children = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT ch.child_id, ch.dob
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             JOIN {$wpdb->prefix}hl_child_classroom_current cc ON ta.classroom_id = cc.classroom_id AND cc.status = 'active'
             JOIN {$wpdb->prefix}hl_child ch ON cc.child_id = ch.child_id
             WHERE e.cycle_id = %d AND e.status = 'active'",
            $cycle_id
        ) );

        if ( empty( $children ) ) {
            return 0;
        }

        // Get existing snapshots for this cycle to skip duplicates.
        $existing = $wpdb->get_col( $wpdb->prepare(
            "SELECT child_id FROM {$wpdb->prefix}hl_child_cycle_snapshot WHERE cycle_id = %d",
            $cycle_id
        ) );
        $existing_map = array_flip( $existing );

        $count = 0;

        foreach ( $children as $child ) {
            if ( isset( $existing_map[ $child->child_id ] ) ) {
                continue;
            }

            if ( empty( $child->dob ) ) {
                continue;
            }

            $age_group  = HL_Age_Group_Helper::calculate_age_group( $child->dob, $reference_date );
            $age_months = HL_Age_Group_Helper::calculate_age_months( $child->dob, $reference_date );

            $wpdb->insert(
                $wpdb->prefix . 'hl_child_cycle_snapshot',
                array(
                    'child_id'             => absint( $child->child_id ),
                    'cycle_id'             => absint( $cycle_id ),
                    'frozen_age_group'     => $age_group,
                    'dob_at_freeze'        => $child->dob,
                    'age_months_at_freeze' => $age_months,
                    'frozen_at'            => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%s', '%s', '%d', '%s' )
            );

            $count++;
        }

        return $count;
    }

    /**
     * Get the frozen age group for a single child in a cycle.
     *
     * @param int $child_id Child ID.
     * @param int $cycle_id Cycle ID.
     * @return string|null Frozen age group slug, or null if no snapshot.
     */
    public static function get_frozen_age_group( $child_id, $cycle_id ) {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT frozen_age_group FROM {$wpdb->prefix}hl_child_cycle_snapshot
             WHERE child_id = %d AND cycle_id = %d",
            $child_id,
            $cycle_id
        ) );
    }

    /**
     * Get full snapshot row for a child in a cycle.
     *
     * @param int $child_id Child ID.
     * @param int $cycle_id Cycle ID.
     * @return object|null Snapshot row.
     */
    public static function get_snapshot( $child_id, $cycle_id ) {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_child_cycle_snapshot
             WHERE child_id = %d AND cycle_id = %d",
            $child_id,
            $cycle_id
        ) );
    }

    /**
     * Get all snapshots for a cycle, keyed by child_id.
     *
     * @param int $cycle_id Cycle ID.
     * @return array Keyed by child_id.
     */
    public static function get_snapshots_for_cycle( $cycle_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_child_cycle_snapshot WHERE cycle_id = %d",
            $cycle_id
        ) );

        $keyed = array();
        foreach ( $rows as $row ) {
            $keyed[ $row->child_id ] = $row;
        }

        return $keyed;
    }

    /**
     * Get snapshots for children currently in a specific classroom for a cycle.
     *
     * Joins snapshots with active classroom assignments.
     *
     * @param int $classroom_id Classroom ID.
     * @param int $cycle_id     Cycle ID.
     * @return array Keyed by child_id.
     */
    public static function get_snapshots_for_classroom( $classroom_id, $cycle_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*
             FROM {$wpdb->prefix}hl_child_cycle_snapshot s
             JOIN {$wpdb->prefix}hl_child_classroom_current cc ON s.child_id = cc.child_id
             WHERE cc.classroom_id = %d AND cc.status = 'active' AND s.cycle_id = %d",
            $classroom_id,
            $cycle_id
        ) );

        $keyed = array();
        foreach ( $rows as $row ) {
            $keyed[ $row->child_id ] = $row;
        }

        return $keyed;
    }

    /**
     * Ensure a snapshot exists for a single child. Creates one if needed.
     *
     * Used when a teacher adds a child mid-cycle.
     *
     * @param int         $child_id Child ID.
     * @param int         $cycle_id Cycle ID.
     * @param string|null $dob      Date of birth (Y-m-d). If null, reads from hl_child.
     * @return string|null The frozen age group, or null if no DOB available.
     */
    public static function ensure_snapshot( $child_id, $cycle_id, $dob = null ) {
        global $wpdb;

        // Check if snapshot already exists.
        $existing = self::get_frozen_age_group( $child_id, $cycle_id );
        if ( $existing !== null ) {
            return $existing;
        }

        // Get DOB if not provided.
        if ( $dob === null ) {
            $dob = $wpdb->get_var( $wpdb->prepare(
                "SELECT dob FROM {$wpdb->prefix}hl_child WHERE child_id = %d",
                $child_id
            ) );
        }

        if ( empty( $dob ) ) {
            return null;
        }

        $age_group  = HL_Age_Group_Helper::calculate_age_group( $dob );
        $age_months = HL_Age_Group_Helper::calculate_age_months( $dob );

        $wpdb->insert(
            $wpdb->prefix . 'hl_child_cycle_snapshot',
            array(
                'child_id'             => absint( $child_id ),
                'cycle_id'             => absint( $cycle_id ),
                'frozen_age_group'     => $age_group,
                'dob_at_freeze'        => $dob,
                'age_months_at_freeze' => $age_months,
                'frozen_at'            => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%s', '%d', '%s' )
        );

        return $age_group;
    }
}
