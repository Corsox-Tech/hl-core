<?php
if (!defined('ABSPATH')) exit;

/**
 * Pathway Routing Service
 *
 * Determines the correct pathway for a participant based on their role
 * and which LearnDash course stages they have completed (user-level).
 *
 * Stage definitions and routing rules are hardcoded for the B2E program.
 * For non-B2E cycles, returns null (callers fall back to target_roles matching).
 *
 * @package HL_Core
 */
class HL_Pathway_Routing_Service {

    /**
     * Stage definitions: groups of LearnDash course IDs.
     * A stage is "completed" when ALL courses in the group are done.
     */
    private static $stages = array(
        'A' => array(
            'label'      => 'Mentor Stage 1',
            'course_ids' => array(30293, 30295), // MC1, MC2
        ),
        'C' => array(
            'label'      => 'Teacher Stage 1',
            'course_ids' => array(30280, 30284, 30286, 30288), // TC1, TC2, TC3, TC4
        ),
        'E' => array(
            'label'      => 'Streamlined Stage 1',
            'course_ids' => array(31037, 31332, 31333, 31334, 31335, 31387, 31388), // TC0, TC1_S-TC4_S, MC1_S, MC2_S
        ),
    );

    /**
     * Routing rules evaluated in priority order. First match wins.
     * Stage matching is INCLUSIVE: user must have completed ALL listed stages (and possibly others).
     *
     * Each rule: array( role, required_stage_keys, pathway_code )
     */
    private static $routing_rules = array(
        array('mentor',          array('C', 'A'), 'B2E_MENTOR_COMPLETION'),
        array('mentor',          array('C'),      'B2E_MENTOR_TRANSITION'),
        array('mentor',          array('A'),      'B2E_MENTOR_PHASE_2'),
        array('mentor',          array(),          'B2E_MENTOR_PHASE_1'),
        array('teacher',         array('C'),      'B2E_TEACHER_PHASE_2'),
        array('teacher',         array(),          'B2E_TEACHER_PHASE_1'),
        array('school_leader',   array('E'),      'B2E_STREAMLINED_PHASE_2'),
        array('school_leader',   array(),          'B2E_STREAMLINED_PHASE_1'),
        array('district_leader', array('E'),      'B2E_STREAMLINED_PHASE_2'),
        array('district_leader', array(),          'B2E_STREAMLINED_PHASE_1'),
    );

    /**
     * Resolve the correct pathway for a user being enrolled in a cycle.
     *
     * @param int|null $user_id   WordPress user ID. Null for new users (no account yet).
     * @param string   $role      Role string (any format — normalized internally).
     * @param int      $cycle_id  Target cycle.
     * @return int|null            Pathway ID if a routing rule matches, null otherwise.
     */
    public static function resolve_pathway($user_id, $role, $cycle_id) {
        $normalized_role = self::normalize_role($role);
        if (empty($normalized_role)) {
            return null;
        }

        // Get user's completed stages
        $completed_stages = self::get_completed_stages($user_id);

        // Find first matching rule
        foreach (self::$routing_rules as $rule) {
            list($rule_role, $required_stages, $pathway_code) = $rule;

            if ($rule_role !== $normalized_role) {
                continue;
            }

            // Check if user has ALL required stages (inclusive match)
            $has_all = true;
            foreach ($required_stages as $stage_key) {
                if (!in_array($stage_key, $completed_stages, true)) {
                    $has_all = false;
                    break;
                }
            }

            if (!$has_all) {
                continue;
            }

            // Match found — look up pathway by code in this cycle
            $pathway_id = self::lookup_pathway_by_code($pathway_code, $cycle_id);
            if ($pathway_id) {
                return $pathway_id;
            }
            // Pathway code doesn't exist in this cycle — continue to next rule
            // (this allows non-B2E cycles to fall through gracefully)
        }

        return null;
    }

    /**
     * Get which stages a user has completed.
     *
     * @param int|null $user_id
     * @return string[] Array of completed stage keys (e.g., ['A', 'C']).
     */
    public static function get_completed_stages($user_id) {
        if (!$user_id) {
            return array();
        }

        $ld = HL_LearnDash_Integration::instance();
        if (!$ld->is_active()) {
            return array();
        }

        $completed = array();

        foreach (self::$stages as $key => $stage) {
            $all_done = true;
            foreach ($stage['course_ids'] as $course_id) {
                if (!$ld->is_course_completed($user_id, $course_id)) {
                    $all_done = false;
                    break;
                }
            }
            if ($all_done) {
                $completed[] = $key;
            }
        }

        return $completed;
    }

    /**
     * Look up a pathway by code within a specific cycle.
     * Falls back to other cycles in the same Partnership if not found.
     *
     * @param string $pathway_code
     * @param int    $cycle_id
     * @return int|null Pathway ID or null.
     */
    private static function lookup_pathway_by_code($pathway_code, $cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // First: try exact cycle match
        $pathway_id = $wpdb->get_var($wpdb->prepare(
            "SELECT pathway_id FROM {$prefix}hl_pathway
             WHERE pathway_code = %s AND cycle_id = %d AND active_status = 1",
            $pathway_code, $cycle_id
        ));

        if ($pathway_id) {
            return (int) $pathway_id;
        }

        // Fallback: look in other cycles within the same Partnership
        $partnership_id = $wpdb->get_var($wpdb->prepare(
            "SELECT partnership_id FROM {$prefix}hl_cycle WHERE cycle_id = %d",
            $cycle_id
        ));

        if (!$partnership_id) {
            return null;
        }

        $pathway_id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.pathway_id FROM {$prefix}hl_pathway p
             JOIN {$prefix}hl_cycle c ON p.cycle_id = c.cycle_id
             WHERE p.pathway_code = %s AND c.partnership_id = %d AND p.active_status = 1
             ORDER BY c.start_date DESC LIMIT 1",
            $pathway_code, $partnership_id
        ));

        return $pathway_id ? (int) $pathway_id : null;
    }

    /**
     * Normalize a role string to lowercase snake_case.
     *
     * Accepts: "Teacher", "teacher", "School Leader", "school_leader", "MENTOR", etc.
     *
     * @param string $role
     * @return string Normalized role or empty string.
     */
    public static function normalize_role($role) {
        $role = strtolower(trim($role));
        $role = str_replace(' ', '_', $role);

        $valid = array('teacher', 'mentor', 'school_leader', 'district_leader');
        return in_array($role, $valid, true) ? $role : '';
    }

    /**
     * Get stage definitions (for display/debugging).
     *
     * @return array
     */
    public static function get_stage_definitions() {
        return self::$stages;
    }
}
