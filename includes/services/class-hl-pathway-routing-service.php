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
 * Routing rules reference `routing_type` values (e.g., 'teacher_phase_1'),
 * which are looked up in the `hl_pathway` table by (routing_type, cycle_id).
 * This decouples routing from pathway_code, which can vary across clones/renames.
 *
 * @package HL_Core
 */
class HL_Pathway_Routing_Service {

    /**
     * Stage definitions: groups of catalog_codes from hl_course_catalog.
     * A stage is "completed" when ALL catalog entries in the group are done
     * (at least one language variant completed per entry).
     */
    private static $stages = array(
        'A' => array(
            'label'         => 'Mentor Stage 1',
            'catalog_codes' => array( 'MC1', 'MC2' ),
        ),
        'B' => array(
            'label'         => 'Mentor Stage 2',
            'catalog_codes' => array( 'MC3', 'MC4' ),
        ),
        'C' => array(
            'label'         => 'Teacher Stage 1',
            'catalog_codes' => array( 'TC1', 'TC2', 'TC3', 'TC4' ),
        ),
        'D' => array(
            'label'         => 'Teacher Stage 2',
            'catalog_codes' => array( 'TC5', 'TC6', 'TC7', 'TC8' ),
        ),
        'E' => array(
            'label'         => 'Streamlined Stage 1',
            'catalog_codes' => array( 'TC0', 'TC1_S', 'TC2_S', 'TC3_S', 'TC4_S', 'MC1_S', 'MC2_S' ),
        ),
    );

    /** @var array<string, HL_Course_Catalog>|null Null = not yet loaded. */
    private static $catalog_cache = null;

    /**
     * Routing rules evaluated in priority order. First match wins.
     * Stage matching is INCLUSIVE: user must have completed ALL listed stages (and possibly others).
     *
     * Each rule: array( role, required_stage_keys, routing_type )
     */
    private static $routing_rules = array(
        // Mentor rules (most specific first)
        array('mentor',          array('C', 'A', 'D'), 'mentor_completion'),       // Teacher→Mentor Transition→now just needs MC3+MC4
        array('mentor',          array('C', 'A'),      'mentor_phase_2'),          // Returning mentor (completed Mentor Phase 1)
        array('mentor',          array('C'),           'mentor_transition'),        // Teacher promoted to mentor
        array('mentor',          array(),              'mentor_phase_1'),           // New mentor
        // Teacher rules
        array('teacher',         array('C'),           'teacher_phase_2'),          // Returning teacher
        array('teacher',         array(),              'teacher_phase_1'),          // New teacher
        // Leader rules — school_leader and district_leader share streamlined pathways
        array('school_leader',   array('E'),           'streamlined_phase_2'),
        array('school_leader',   array(),              'streamlined_phase_1'),
        array('district_leader', array('E'),           'streamlined_phase_2'),
        array('district_leader', array(),              'streamlined_phase_1'),
    );

    /**
     * Valid routing_type values and their human-readable labels.
     * Used by admin UI dropdowns and validation.
     *
     * @return array Associative array of routing_type => label.
     */
    public static function get_valid_routing_types() {
        return array(
            'teacher_phase_1'      => 'Teacher Phase 1 (new teachers)',
            'teacher_phase_2'      => 'Teacher Phase 2 (returning teachers)',
            'mentor_phase_1'       => 'Mentor Phase 1 (new mentors)',
            'mentor_phase_2'       => 'Mentor Phase 2 (returning mentors)',
            'mentor_transition'    => 'Mentor Transition (teacher promoted to mentor)',
            'mentor_completion'    => 'Mentor Completion (completing mentors)',
            'streamlined_phase_1'  => 'Streamlined Phase 1 (new leaders)',
            'streamlined_phase_2'  => 'Streamlined Phase 2 (returning leaders)',
        );
    }

    /**
     * Lazy-load all catalog entries into a static cache indexed by catalog_code.
     *
     * Does NOT cache when the table is absent — the table may not exist yet
     * during plugin activation (mirrors HL_Course_Catalog_Repository::table_exists() pattern).
     *
     * @return array<string, HL_Course_Catalog>
     */
    private static function load_catalog_cache() {
        if ( self::$catalog_cache !== null ) {
            return self::$catalog_cache;
        }

        $repo = new HL_Course_Catalog_Repository();

        if ( ! $repo->table_exists() ) {
            // Do NOT assign to self::$catalog_cache — table may appear later in this request.
            return array();
        }

        self::$catalog_cache = $repo->get_all_indexed_by_code();
        return self::$catalog_cache;
    }

    /**
     * Check whether a user has completed a catalog entry in any language variant.
     *
     * @param int                      $user_id
     * @param HL_Course_Catalog        $entry
     * @param HL_LearnDash_Integration $ld
     * @return bool
     */
    private static function is_catalog_entry_completed( $user_id, HL_Course_Catalog $entry, HL_LearnDash_Integration $ld ) {
        $course_ids = $entry->get_language_course_ids();

        if ( empty( $course_ids ) ) {
            return false;
        }

        foreach ( $course_ids as $lang => $course_id ) {
            if ( $ld->is_course_completed( $user_id, $course_id ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Health check: verify every catalog_code referenced in $stages exists in the DB.
     *
     * @return string[] Missing catalog_codes. Empty array = healthy.
     */
    public static function is_catalog_ready() {
        $catalog = self::load_catalog_cache();

        $missing = array();
        foreach ( self::$stages as $key => $stage ) {
            foreach ( $stage['catalog_codes'] as $code ) {
                if ( ! isset( $catalog[ $code ] ) ) {
                    $missing[] = $code;
                }
            }
        }

        return $missing;
    }

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
            list($rule_role, $required_stages, $routing_type) = $rule;

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

            // Match found — look up pathway by routing_type in this cycle
            $pathway_id = self::lookup_pathway_by_routing_type($routing_type, $cycle_id);
            if ($pathway_id) {
                return $pathway_id;
            }
            // routing_type doesn't exist in this cycle — continue to next rule
            // (this allows non-B2E cycles to fall through gracefully)
        }

        error_log(sprintf(
            '[HL Routing] No pathway resolved: user=%s, role=%s, cycle=%d, completed_stages=[%s]',
            $user_id ?: 'new', $normalized_role, $cycle_id, implode(',', $completed_stages)
        ));
        return null;
    }

    /**
     * Get which stages a user has completed.
     *
     * A stage is complete when every catalog entry in its group has been
     * completed in at least one language variant.
     *
     * @param int|null $user_id
     * @return string[] Array of completed stage keys (e.g., ['A', 'C']).
     */
    public static function get_completed_stages( $user_id ) {
        if ( ! $user_id ) {
            return array();
        }

        $ld = HL_LearnDash_Integration::instance();
        if ( ! $ld->is_active() ) {
            return array();
        }

        $catalog = self::load_catalog_cache();

        if ( empty( $catalog ) ) {
            error_log( '[HL Routing] Course catalog is empty — stage completion cannot be evaluated' );
            return array();
        }

        $completed = array();

        foreach ( self::$stages as $key => $stage ) {
            $all_done = true;

            foreach ( $stage['catalog_codes'] as $code ) {
                if ( ! isset( $catalog[ $code ] ) ) {
                    error_log( sprintf( '[HL Routing] catalog_code \'%s\' not found in catalog', $code ) );
                    $all_done = false;
                    break;
                }

                if ( ! self::is_catalog_entry_completed( $user_id, $catalog[ $code ], $ld ) ) {
                    $all_done = false;
                    break;
                }
            }

            if ( $all_done ) {
                $completed[] = $key;
            }
        }

        return $completed;
    }

    /**
     * Look up a pathway by routing_type within a specific cycle.
     *
     * No cross-cycle fallback — each cycle must have its own pathways
     * with routing_type set via admin UI or seeder.
     *
     * @param string $routing_type
     * @param int    $cycle_id
     * @return int|null Pathway ID or null.
     */
    private static function lookup_pathway_by_routing_type($routing_type, $cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $pathway_id = $wpdb->get_var($wpdb->prepare(
            "SELECT pathway_id FROM {$prefix}hl_pathway
             WHERE routing_type = %s AND cycle_id = %d AND active_status = 1
             LIMIT 1",
            $routing_type, $cycle_id
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
        $role = strtolower(trim((string) $role));
        $role = str_replace(' ', '_', $role);

        $valid = array('teacher', 'mentor', 'school_leader', 'district_leader');
        return in_array($role, $valid, true) ? $role : '';
    }

    /**
     * Get stage definitions (for display/debugging).
     *
     * Each stage contains 'catalog_codes' (string[]) — not the former 'course_ids'.
     *
     * @return array Keyed by stage letter. Each: array{ label: string, catalog_codes: string[] }.
     */
    public static function get_stage_definitions() {
        return self::$stages;
    }
}
