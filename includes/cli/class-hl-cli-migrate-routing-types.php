<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CLI command to migrate existing pathways and populate the routing_type column.
 *
 * Uses three strategies in order:
 *   1. Exact pathway_code match against known B2E codes.
 *   2. COPY variant match (codes with __COPY_ suffix).
 *   3. ELCPB Y1 legacy name matching for Cycle 7 oddly-formatted codes.
 *
 * Safe to run multiple times — all UPDATEs include AND routing_type IS NULL.
 *
 * Usage:
 *   wp hl-core migrate-routing-types --dry-run
 *   wp hl-core migrate-routing-types
 */
class HL_CLI_Migrate_Routing_Types {

    /** @var int */
    private $updated = 0;

    /** @var int */
    private $skipped = 0;

    /** @var int */
    private $errors = 0;

    /** @var bool */
    private $dry_run = false;

    /** @var string */
    private $table;

    /**
     * Known B2E pathway_code => routing_type mapping.
     */
    private static $code_mapping = array(
        'B2E_TEACHER_PHASE_1'     => 'teacher_phase_1',
        'B2E_TEACHER_PHASE_2'     => 'teacher_phase_2',
        'B2E_MENTOR_PHASE_1'      => 'mentor_phase_1',
        'B2E_MENTOR_PHASE_2'      => 'mentor_phase_2',
        'B2E_MENTOR_TRANSITION'   => 'mentor_transition',
        'B2E_MENTOR_COMPLETION'   => 'mentor_completion',
        'B2E_STREAMLINED_PHASE_1' => 'streamlined_phase_1',
        'B2E_STREAMLINED_PHASE_2' => 'streamlined_phase_2',
    );

    /**
     * ELCPB Y1 legacy pathway_name => routing_type mapping.
     * These pathways have auto-generated codes that don't match any known pattern.
     */
    private static $name_mapping = array(
        '%Mentor%Phase I%'       => 'mentor_phase_1',
        '%Teacher%Phase I%'      => 'teacher_phase_1',
        '%Streamlined%Phase I%'  => 'streamlined_phase_1',
    );

    public static function register() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;
        WP_CLI::add_command( 'hl-core migrate-routing-types', array( new self(), 'run' ) );
    }

    /**
     * Migrate existing pathways to populate routing_type from pathway_code patterns.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview changes without modifying data.
     *
     * ## EXAMPLES
     *
     *     wp hl-core migrate-routing-types --dry-run
     *     wp hl-core migrate-routing-types
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function run( $args, $assoc_args ) {
        global $wpdb;

        $this->dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
        $this->table   = $wpdb->prefix . 'hl_pathway';

        $mode_label = $this->dry_run ? ' (DRY RUN)' : '';
        WP_CLI::log( "Migrating routing types...{$mode_label}\n" );

        // Verify the table and column exist before proceeding.
        $col_check = $wpdb->get_results( "SHOW COLUMNS FROM {$this->table} LIKE 'routing_type'" );
        if ( empty( $col_check ) ) {
            WP_CLI::error( "Column 'routing_type' does not exist on {$this->table}. Run schema migration first." );
        }

        // Count pathways with NULL routing_type as a baseline.
        $null_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE routing_type IS NULL" );
        WP_CLI::log( "Pathways with NULL routing_type: {$null_count}" );
        if ( $null_count === 0 ) {
            WP_CLI::success( 'All pathways already have routing_type assigned. Nothing to do.' );
            return;
        }

        // ── Strategy 1: Exact pathway_code match ──
        WP_CLI::log( "\nStrategy 1: Exact pathway_code match" );
        foreach ( self::$code_mapping as $code => $routing_type ) {
            $this->update_by_exact_code( $code, $routing_type );
        }

        // ── Strategy 2: COPY variant match ──
        WP_CLI::log( "\nStrategy 2: COPY variant match" );
        foreach ( self::$code_mapping as $code => $routing_type ) {
            $this->update_copy_variants( $code, $routing_type );
        }

        // ── Strategy 3: ELCPB Y1 legacy name match ──
        WP_CLI::log( "\nStrategy 3: ELCPB Y1 name match" );
        foreach ( self::$name_mapping as $name_pattern => $routing_type ) {
            $this->update_by_name_pattern( $name_pattern, $routing_type );
        }

        // ── Summary ──
        WP_CLI::log( '' );
        $summary = sprintf(
            'Summary: %d updated, %d skipped (conflicts), %d errors',
            $this->updated,
            $this->skipped,
            $this->errors
        );

        if ( $this->errors > 0 ) {
            WP_CLI::warning( $summary );
        } else {
            WP_CLI::success( $summary );
        }
    }

    /**
     * Strategy 1: Update a pathway with an exact pathway_code match.
     *
     * @param string $code         The pathway_code to match exactly.
     * @param string $routing_type The routing_type value to set.
     */
    private function update_by_exact_code( $code, $routing_type ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pathway_id, cycle_id, pathway_code, routing_type
             FROM {$this->table}
             WHERE pathway_code = %s",
            $code
        ) );

        if ( empty( $rows ) ) {
            WP_CLI::log( "  {$routing_type}: No pathway found with code '{$code}'" );
            return;
        }

        foreach ( $rows as $row ) {
            if ( $row->routing_type !== null ) {
                WP_CLI::log( "  {$routing_type}: Pathway #{$row->pathway_id} (cycle {$row->cycle_id}) already has routing_type '{$row->routing_type}' — skipping" );
                continue;
            }

            if ( $this->has_conflict( $row->cycle_id, $routing_type, $row->pathway_id ) ) {
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT pathway_id FROM {$this->table} WHERE cycle_id = %d AND routing_type = %s",
                    $row->cycle_id, $routing_type
                ) );
                WP_CLI::log( "  {$routing_type}: Skipped pathway #{$row->pathway_id} (cycle {$row->cycle_id}) — already assigned to pathway #{$existing}" );
                $this->skipped++;
                continue;
            }

            $this->apply_update( $row->pathway_id, $row->cycle_id, $routing_type, "code: {$code}" );
        }
    }

    /**
     * Strategy 2: Update COPY variant pathways (pathway_code LIKE '{$code}__%').
     *
     * @param string $base_code    The base pathway_code to match COPY variants of.
     * @param string $routing_type The routing_type value to set.
     */
    private function update_copy_variants( $base_code, $routing_type ) {
        global $wpdb;

        $like_pattern = $wpdb->esc_like( $base_code ) . '__%';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pathway_id, cycle_id, pathway_code, routing_type
             FROM {$this->table}
             WHERE pathway_code LIKE %s",
            $like_pattern
        ) );

        if ( empty( $rows ) ) {
            return; // No COPY variants — this is normal for most codes.
        }

        foreach ( $rows as $row ) {
            if ( $row->routing_type !== null ) {
                WP_CLI::log( "  {$routing_type}: Pathway #{$row->pathway_id} (cycle {$row->cycle_id}) already has routing_type — skipping" );
                continue;
            }

            // Check for UNIQUE constraint conflict: does this cycle already have a pathway
            // with this routing_type assigned?
            if ( $this->has_conflict( $row->cycle_id, $routing_type, $row->pathway_id ) ) {
                $existing_id = $this->get_existing_pathway_id( $row->cycle_id, $routing_type );
                WP_CLI::log( "  {$routing_type}: Skipped pathway #{$row->pathway_id} (cycle {$row->cycle_id}) — routing_type already assigned to pathway #{$existing_id}" );
                $this->skipped++;
                continue;
            }

            $this->apply_update( $row->pathway_id, $row->cycle_id, $routing_type, "code: {$row->pathway_code}" );
        }
    }

    /**
     * Strategy 3: Update pathways by name pattern (LIKE match on pathway_name).
     *
     * @param string $name_pattern The LIKE pattern for pathway_name.
     * @param string $routing_type The routing_type value to set.
     */
    private function update_by_name_pattern( $name_pattern, $routing_type ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pathway_id, cycle_id, pathway_name, pathway_code, routing_type
             FROM {$this->table}
             WHERE pathway_name LIKE %s AND routing_type IS NULL",
            $name_pattern
        ) );

        if ( empty( $rows ) ) {
            WP_CLI::log( "  {$routing_type}: No pathways matched name pattern '{$name_pattern}'" );
            return;
        }

        foreach ( $rows as $row ) {
            // Check for UNIQUE constraint conflict.
            if ( $this->has_conflict( $row->cycle_id, $routing_type, $row->pathway_id ) ) {
                $existing_id = $this->get_existing_pathway_id( $row->cycle_id, $routing_type );
                WP_CLI::log( "  {$routing_type}: Skipped pathway #{$row->pathway_id} (cycle {$row->cycle_id}) — routing_type already assigned to pathway #{$existing_id}" );
                $this->skipped++;
                continue;
            }

            $this->apply_update( $row->pathway_id, $row->cycle_id, $routing_type, "name: {$row->pathway_name}" );
        }
    }

    /**
     * Apply the routing_type update (or log it in dry-run mode).
     *
     * @param int    $pathway_id   Pathway ID.
     * @param int    $cycle_id     Cycle ID.
     * @param string $routing_type The routing_type to set.
     * @param string $detail       Extra context for the log line.
     */
    private function apply_update( $pathway_id, $cycle_id, $routing_type, $detail ) {
        global $wpdb;

        $action = $this->dry_run ? 'Would update' : 'Updated';

        if ( $this->dry_run ) {
            WP_CLI::log( "  {$routing_type}: {$action} pathway #{$pathway_id} (cycle {$cycle_id}, {$detail})" );
            $this->updated++;
            return;
        }

        // Use AND routing_type IS NULL for idempotency.
        $result = $wpdb->update(
            $this->table,
            array( 'routing_type' => $routing_type ),
            array(
                'pathway_id'   => $pathway_id,
                'routing_type' => null,
            ),
            array( '%s' ),
            array( '%d', null )
        );

        if ( $result === false ) {
            WP_CLI::warning( "  {$routing_type}: ERROR updating pathway #{$pathway_id} (cycle {$cycle_id}) — {$wpdb->last_error}" );
            $this->errors++;
            return;
        }

        if ( $result === 0 ) {
            // Row wasn't updated — probably routing_type was set between our SELECT and UPDATE.
            WP_CLI::log( "  {$routing_type}: Pathway #{$pathway_id} (cycle {$cycle_id}) — no rows affected (may already be set)" );
            return;
        }

        WP_CLI::log( "  {$routing_type}: {$action} pathway #{$pathway_id} (cycle {$cycle_id}, {$detail})" );
        $this->updated++;
    }

    /**
     * Check if a cycle already has a pathway with the given routing_type.
     *
     * @param int    $cycle_id     The cycle to check.
     * @param string $routing_type The routing_type to look for.
     * @param int    $exclude_id   Pathway ID to exclude from the check.
     * @return bool
     */
    private function has_conflict( $cycle_id, $routing_type, $exclude_id ) {
        global $wpdb;

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE cycle_id = %d AND routing_type = %s AND pathway_id != %d",
            $cycle_id,
            $routing_type,
            $exclude_id
        ) );

        return (int) $existing > 0;
    }

    /**
     * Get the pathway_id that already owns a routing_type in a cycle (for logging).
     *
     * @param int    $cycle_id     The cycle to check.
     * @param string $routing_type The routing_type to look for.
     * @return int|null
     */
    private function get_existing_pathway_id( $cycle_id, $routing_type ) {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT pathway_id FROM {$this->table}
             WHERE cycle_id = %d AND routing_type = %s
             LIMIT 1",
            $cycle_id,
            $routing_type
        ) );
    }
}
