# ELCPB Test Mirror Clone — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a WP-CLI seeder that clones the entire ELCPB partnership (both cycles, all users, all data) into a test mirror partnership with yopmail.com email addresses, running on production.

**Architecture:** Single PHP CLI class (`HL_CLI_Clone_ELCPB_Test_Mirror`) following the existing seeder pattern. Reads all ELCPB data from DB via `$wpdb` queries, creates new WP users with yopmail emails, then inserts cloned records with in-memory ID maps to maintain referential integrity. LearnDash enrollments and completions are mirrored via `ld_update_course_access()` and `learndash_update_user_activity()`. LD hooks are temporarily removed during bulk operations to prevent side effects.

**Tech Stack:** PHP 7.4+, WordPress WP-CLI, LearnDash API functions, `$wpdb` direct queries.

**Command name:** `wp hl-core clone-elcpb-test-mirror` (intentionally long to prevent accidental execution).

---

## File Structure

| File | Action | Purpose |
|------|--------|---------|
| `includes/cli/class-hl-cli-clone-elcpb-test-mirror.php` | Create | The entire seeder — single file, ~1500-2000 lines |
| `hl-core.php` | Modify (2 lines) | Add require + register call |

This is a single-file CLI command. No services, no repositories beyond what already exists. All logic is self-contained in the CLI class, following the pattern of `class-hl-cli-import-elcpb.php`.

---

## Constants & Configuration

```php
const CLONE_META_KEY = '_hl_clone_elcpb_test';

// Source partnership/cycles (read from).
const SOURCE_PARTNERSHIP_CODE = 'ELCPB-B2E-2025';
const SOURCE_Y1_CYCLE_CODE    = 'ELCPB-Y1-2025';
const SOURCE_Y2_CYCLE_CODE    = 'ELCPB-Y2-2026';

// Target partnership/cycles (write to).
const TARGET_PARTNERSHIP_CODE = 'ELCPB-TEST-2025';
const TARGET_PARTNERSHIP_NAME = 'ELCPB Test Mirror';
const TARGET_Y1_CYCLE_CODE   = 'ELCPB-TEST-Y1';
const TARGET_Y1_CYCLE_NAME   = 'Test Mirror Year 1';
const TARGET_Y2_CYCLE_CODE   = 'ELCPB-TEST-Y2';
const TARGET_Y2_CYCLE_NAME   = 'Test Mirror Year 2';

const EMAIL_DOMAIN = 'yopmail.com';
```

## ID Map Strategy

The class maintains these in-memory maps as instance properties:

```php
private $user_map       = []; // old_user_id => new_user_id
private $cycle_map      = []; // old_cycle_id => new_cycle_id
private $enrollment_map = []; // old_enrollment_id => new_enrollment_id
private $pathway_map    = []; // old_pathway_id => new_pathway_id
private $component_map  = []; // old_component_id => new_component_id
private $team_map       = []; // old_team_id => new_team_id
private $classroom_map  = []; // old_classroom_id => new_classroom_id
private $child_map      = []; // old_child_id => new_child_id
private $session_map    = []; // old_coaching_session_id => new_coaching_session_id
private $rp_session_map = []; // old_rp_session_id => new_rp_session_id
private $visit_map      = []; // old_classroom_visit_id => new_classroom_visit_id
private $observation_map = []; // old_observation_id => new_observation_id
```

---

## Task 1: File Scaffold + Registration + Clean Command

**Files:**
- Create: `includes/cli/class-hl-cli-clone-elcpb-test-mirror.php`
- Modify: `hl-core.php` (lines ~247 and ~348)

- [ ] **Step 1: Create the CLI class file with scaffold**

```php
<?php
/**
 * WP-CLI command: wp hl-core clone-elcpb-test-mirror
 *
 * Clones the entire ELCPB partnership into a test mirror with yopmail.com emails.
 * Creates new WP users, new partnership/cycles, and copies ALL data.
 * Intentionally long command name to prevent accidental execution.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_CLI_Clone_ELCPB_Test_Mirror {

    // ---- Tagging ----
    const CLONE_META_KEY = '_hl_clone_elcpb_test';

    // ---- Source codes (read from) ----
    const SOURCE_PARTNERSHIP_CODE = 'ELCPB-B2E-2025';
    const SOURCE_Y1_CYCLE_CODE   = 'ELCPB-Y1-2025';
    const SOURCE_Y2_CYCLE_CODE   = 'ELCPB-Y2-2026';

    // ---- Target codes (write to) ----
    const TARGET_PARTNERSHIP_CODE = 'ELCPB-TEST-2025';
    const TARGET_PARTNERSHIP_NAME = 'ELCPB Test Mirror';
    const TARGET_Y1_CYCLE_CODE   = 'ELCPB-TEST-Y1';
    const TARGET_Y1_CYCLE_NAME   = 'Test Mirror Year 1';
    const TARGET_Y2_CYCLE_CODE   = 'ELCPB-TEST-Y2';
    const TARGET_Y2_CYCLE_NAME   = 'Test Mirror Year 2';

    const EMAIL_DOMAIN = 'yopmail.com';

    // ---- ID maps ----
    private $user_map        = array();
    private $cycle_map       = array();
    private $enrollment_map  = array();
    private $pathway_map     = array();
    private $component_map   = array();
    private $team_map        = array();
    private $classroom_map   = array();
    private $child_map       = array();
    private $session_map     = array();
    private $rp_session_map  = array();
    private $visit_map       = array();
    private $observation_map = array();

    /** @var int New partnership ID. */
    private $target_partnership_id = 0;

    /**
     * Register the WP-CLI command.
     */
    public static function register() {
        WP_CLI::add_command( 'hl-core clone-elcpb-test-mirror', array( new self(), 'run' ) );
    }

    /**
     * Clone ELCPB partnership into a test mirror.
     *
     * ## OPTIONS
     *
     * [--clean]
     * : Remove all test mirror data (users, partnership, cycles, everything).
     *
     * [--dry-run]
     * : Preview what would be created without writing to DB.
     *
     * ## EXAMPLES
     *
     *     wp hl-core clone-elcpb-test-mirror
     *     wp hl-core clone-elcpb-test-mirror --clean
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Named args.
     */
    public function run( $args, $assoc_args ) {
        $clean = isset( $assoc_args['clean'] );

        if ( $clean ) {
            $this->clean();
            return;
        }

        if ( $this->clone_exists() ) {
            WP_CLI::warning( 'Test mirror already exists (cycle code ' . self::TARGET_Y1_CYCLE_CODE . '). Run with --clean first.' );
            return;
        }

        WP_CLI::line( '' );
        WP_CLI::line( '╔══════════════════════════════════════════════╗' );
        WP_CLI::line( '║   ELCPB TEST MIRROR CLONE                   ║' );
        WP_CLI::line( '║   Creating test copy with yopmail emails     ║' );
        WP_CLI::line( '╚══════════════════════════════════════════════╝' );
        WP_CLI::line( '' );

        $this->suppress_hooks();

        // Phase 1: Structure
        $this->clone_partnership();
        $source_cycles = $this->load_source_cycles();
        $this->clone_cycles( $source_cycles );

        // Phase 2: Users
        $this->clone_users( $source_cycles );

        // Phase 3: Per-cycle data (runs for Y1 and Y2)
        foreach ( $source_cycles as $src_cycle ) {
            $target_cycle_id = $this->cycle_map[ $src_cycle->cycle_id ];
            WP_CLI::line( '' );
            WP_CLI::line( "--- Cloning cycle: {$src_cycle->cycle_code} → " . ( $src_cycle->cycle_code === self::SOURCE_Y1_CYCLE_CODE ? self::TARGET_Y1_CYCLE_CODE : self::TARGET_Y2_CYCLE_CODE ) . " ---" );

            $this->clone_pathways_and_components( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_enrollments( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_pathway_assignments( $src_cycle->cycle_id );
            $this->clone_teams( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_classrooms( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_teaching_assignments( $src_cycle->cycle_id );
            $this->clone_coach_assignments( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_children( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_component_states( $src_cycle->cycle_id );
            $this->clone_component_overrides( $src_cycle->cycle_id );
            $this->clone_completion_rollups( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_teacher_assessments( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_child_assessments( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_coaching_sessions( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_rp_sessions( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_classroom_visits( $src_cycle->cycle_id, $target_cycle_id );
            $this->clone_observations( $src_cycle->cycle_id, $target_cycle_id );
        }

        // Phase 4: LearnDash sync
        $this->sync_learndash();

        // Phase 5: Coach availability (not cycle-scoped)
        $this->clone_coach_availability();

        $this->restore_hooks();
        $this->print_summary();
    }

    // === IDEMPOTENCY ===

    private function clone_exists() {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT cycle_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_code = %s LIMIT 1",
                self::TARGET_Y1_CYCLE_CODE
            )
        );
    }

    // === HOOK SUPPRESSION ===

    private function suppress_hooks() {
        // Prevent LD hooks from firing during bulk completion writes.
        remove_all_actions( 'learndash_course_completed' );
        // Prevent pathway assignment hook from auto-enrolling in LD (we do it ourselves).
        remove_all_actions( 'hl_pathway_assigned' );
        // Prevent coaching session hook side effects.
        remove_all_actions( 'hl_coaching_session_created' );
        // Suppress new user notification emails.
        add_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
        add_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );
    }

    private function restore_hooks() {
        remove_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
        remove_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );
        // LD hooks will re-register on next request. No need to restore for CLI.
    }

    // Below: all clone_* and clean methods (Tasks 2-11)
}
```

- [ ] **Step 2: Register in hl-core.php**

Add the require after the existing ELCPB CLI includes (around line 247):

```php
require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-clone-elcpb-test-mirror.php';
```

Add the register call after the existing ELCPB registrations (around line 348):

```php
HL_CLI_Clone_ELCPB_Test_Mirror::register();
```

- [ ] **Step 3: Implement the `clean()` method**

This goes inside the class. It deletes all cloned data in reverse dependency order, then deletes tagged WP users.

```php
private function clean() {
    global $wpdb;
    $p = $wpdb->prefix;

    WP_CLI::line( '' );
    WP_CLI::line( '=== Cleaning ELCPB Test Mirror ===' );

    // Find target cycles.
    $target_cycle_ids = $wpdb->get_col(
        "SELECT c.cycle_id FROM {$p}hl_cycle c
         JOIN {$p}hl_partnership p ON c.partnership_id = p.partnership_id
         WHERE p.partnership_code = '" . self::TARGET_PARTNERSHIP_CODE . "'"
    );

    if ( ! empty( $target_cycle_ids ) ) {
        $cycle_in = implode( ',', array_map( 'intval', $target_cycle_ids ) );

        // Delete in reverse dependency order.
        $tables_cycle_scoped = array(
            'hl_classroom_visit_submission' => "classroom_visit_id IN (SELECT classroom_visit_id FROM {$p}hl_classroom_visit WHERE cycle_id IN ({$cycle_in}))",
            'hl_classroom_visit'            => "cycle_id IN ({$cycle_in})",
            'hl_rp_session_submission'      => "rp_session_id IN (SELECT rp_session_id FROM {$p}hl_rp_session WHERE cycle_id IN ({$cycle_in}))",
            'hl_rp_session'                 => "cycle_id IN ({$cycle_in})",
            'hl_coaching_session_observation' => "session_id IN (SELECT session_id FROM {$p}hl_coaching_session WHERE cycle_id IN ({$cycle_in}))",
            'hl_coaching_session_submission' => "session_id IN (SELECT session_id FROM {$p}hl_coaching_session WHERE cycle_id IN ({$cycle_in}))",
            'hl_coaching_session'           => "cycle_id IN ({$cycle_in})",
            'hl_observation'                => "cycle_id IN ({$cycle_in})",
            'hl_child_assessment_childrow'  => "instance_id IN (SELECT instance_id FROM {$p}hl_child_assessment_instance WHERE cycle_id IN ({$cycle_in}))",
            'hl_child_assessment_instance'  => "cycle_id IN ({$cycle_in})",
            'hl_teacher_assessment_instance' => "cycle_id IN ({$cycle_in})",
            'hl_child_cycle_snapshot'       => "cycle_id IN ({$cycle_in})",
            'hl_child_classroom_current'    => "classroom_id IN (SELECT classroom_id FROM {$p}hl_classroom WHERE cycle_id IN ({$cycle_in}))",
            'hl_child_classroom_history'    => "classroom_id IN (SELECT classroom_id FROM {$p}hl_classroom WHERE cycle_id IN ({$cycle_in}))",
            'hl_component_override'         => "enrollment_id IN (SELECT enrollment_id FROM {$p}hl_enrollment WHERE cycle_id IN ({$cycle_in}))",
            'hl_completion_rollup'          => "cycle_id IN ({$cycle_in})",
            'hl_component_state'            => "enrollment_id IN (SELECT enrollment_id FROM {$p}hl_enrollment WHERE cycle_id IN ({$cycle_in}))",
            'hl_coach_assignment'           => "cycle_id IN ({$cycle_in})",
            'hl_teaching_assignment'        => "enrollment_id IN (SELECT enrollment_id FROM {$p}hl_enrollment WHERE cycle_id IN ({$cycle_in}))",
            'hl_team_membership'            => "team_id IN (SELECT team_id FROM {$p}hl_team WHERE cycle_id IN ({$cycle_in}))",
            'hl_team'                       => "cycle_id IN ({$cycle_in})",
            'hl_pathway_assignment'         => "enrollment_id IN (SELECT enrollment_id FROM {$p}hl_enrollment WHERE cycle_id IN ({$cycle_in}))",
            'hl_enrollment'                 => "cycle_id IN ({$cycle_in})",
            'hl_component_drip_rule'        => "component_id IN (SELECT component_id FROM {$p}hl_component WHERE cycle_id IN ({$cycle_in}))",
            'hl_component_prereq_item'      => "group_id IN (SELECT group_id FROM {$p}hl_component_prereq_group WHERE component_id IN (SELECT component_id FROM {$p}hl_component WHERE cycle_id IN ({$cycle_in})))",
            'hl_component_prereq_group'     => "component_id IN (SELECT component_id FROM {$p}hl_component WHERE cycle_id IN ({$cycle_in}))",
            'hl_component'                  => "cycle_id IN ({$cycle_in})",
            'hl_pathway'                    => "cycle_id IN ({$cycle_in})",
            'hl_classroom'                  => "cycle_id IN ({$cycle_in})",
            'hl_cycle_school'               => "cycle_id IN ({$cycle_in})",
            'hl_cycle'                      => "cycle_id IN ({$cycle_in})",
        );

        foreach ( $tables_cycle_scoped as $table => $where ) {
            $deleted = $wpdb->query( "DELETE FROM {$p}{$table} WHERE {$where}" );
            if ( $deleted > 0 ) {
                WP_CLI::log( "  Deleted {$deleted} rows from {$table}" );
            }
        }
    }

    // Delete partnership.
    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$p}hl_partnership WHERE partnership_code = %s",
            self::TARGET_PARTNERSHIP_CODE
        )
    );
    if ( $deleted ) {
        WP_CLI::log( "  Deleted partnership: " . self::TARGET_PARTNERSHIP_CODE );
    }

    // Delete cloned children (tagged by fingerprint prefix 'TEST-').
    $child_deleted = $wpdb->query(
        "DELETE FROM {$p}hl_child WHERE child_fingerprint LIKE 'TEST-%'"
    );
    if ( $child_deleted > 0 ) {
        WP_CLI::log( "  Deleted {$child_deleted} cloned children" );
    }

    // Delete tagged WP users + remove LD course access.
    $tagged_users = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            self::CLONE_META_KEY
        )
    );

    $users_deleted = 0;
    foreach ( $tagged_users as $row ) {
        if ( $row->meta_value === 'created' ) {
            // Remove LD course access before deleting user.
            if ( function_exists( 'ld_update_course_access' ) ) {
                $ld_courses = learndash_user_get_enrolled_courses( (int) $row->user_id );
                if ( is_array( $ld_courses ) ) {
                    foreach ( $ld_courses as $course_id ) {
                        ld_update_course_access( (int) $row->user_id, $course_id, true ); // true = remove
                    }
                }
            }
            wp_delete_user( (int) $row->user_id );
            $users_deleted++;
        }
    }

    // Delete coach availability for cloned coaches.
    // (Coach availability is user-scoped, cleaned up when user is deleted via FK cascade or we do it here.)
    // WP doesn't cascade, so clean explicitly.
    if ( ! empty( $tagged_users ) ) {
        $tagged_ids = array_map( function( $r ) { return (int) $r->user_id; }, $tagged_users );
        $uid_in = implode( ',', $tagged_ids );
        $avail_deleted = $wpdb->query( "DELETE FROM {$p}hl_coach_availability WHERE coach_user_id IN ({$uid_in})" );
        if ( $avail_deleted > 0 ) {
            WP_CLI::log( "  Deleted {$avail_deleted} coach availability rows" );
        }
    }

    WP_CLI::log( "  Deleted {$users_deleted} cloned WP users" );
    WP_CLI::success( 'ELCPB Test Mirror cleaned.' );
}
```

- [ ] **Step 4: Commit scaffold**

```bash
git add includes/cli/class-hl-cli-clone-elcpb-test-mirror.php hl-core.php
git commit -m "feat(cli): scaffold clone-elcpb-test-mirror command with clean + hook suppression"
```

---

## Task 2: Clone Partnership + Cycles + Users

**Files:**
- Modify: `includes/cli/class-hl-cli-clone-elcpb-test-mirror.php`

- [ ] **Step 1: Implement `clone_partnership()`**

```php
private function clone_partnership() {
    $repo = new HL_Partnership_Repository();
    $this->target_partnership_id = $repo->create( array(
        'partnership_name' => self::TARGET_PARTNERSHIP_NAME,
        'partnership_code' => self::TARGET_PARTNERSHIP_CODE,
        'description'      => 'Test mirror of ELCPB — cloned data with yopmail emails.',
        'status'           => 'active',
    ) );

    WP_CLI::log( "  [1] Partnership created: id={$this->target_partnership_id} code=" . self::TARGET_PARTNERSHIP_CODE );
}
```

- [ ] **Step 2: Implement `load_source_cycles()`**

```php
private function load_source_cycles() {
    global $wpdb;
    $cycles = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE cycle_code IN (%s, %s) ORDER BY start_date ASC",
            self::SOURCE_Y1_CYCLE_CODE,
            self::SOURCE_Y2_CYCLE_CODE
        )
    );

    if ( empty( $cycles ) ) {
        WP_CLI::error( 'No source ELCPB cycles found. Expected codes: ' . self::SOURCE_Y1_CYCLE_CODE . ', ' . self::SOURCE_Y2_CYCLE_CODE );
    }

    WP_CLI::log( '  [2] Source cycles loaded: ' . count( $cycles ) );
    return $cycles;
}
```

- [ ] **Step 3: Implement `clone_cycles()`**

```php
private function clone_cycles( $source_cycles ) {
    global $wpdb;
    $p = $wpdb->prefix;
    $repo = new HL_Cycle_Repository();

    foreach ( $source_cycles as $src ) {
        $is_y1 = ( $src->cycle_code === self::SOURCE_Y1_CYCLE_CODE );
        $target_code = $is_y1 ? self::TARGET_Y1_CYCLE_CODE : self::TARGET_Y2_CYCLE_CODE;
        $target_name = $is_y1 ? self::TARGET_Y1_CYCLE_NAME : self::TARGET_Y2_CYCLE_NAME;

        $new_cycle_id = $repo->create( array(
            'cycle_name'     => $target_name,
            'cycle_code'     => $target_code,
            'partnership_id' => $this->target_partnership_id,
            'cycle_type'     => $src->cycle_type,
            'district_id'    => $src->district_id,
            'is_control_group' => $src->is_control_group,
            'status'         => 'active',
            'start_date'     => $src->start_date,
            'end_date'       => $src->end_date,
            'timezone'       => $src->timezone,
            'settings'       => $src->settings,
        ) );

        $this->cycle_map[ (int) $src->cycle_id ] = $new_cycle_id;

        // Clone cycle_school links (reuse same school orgunits).
        $schools = $wpdb->get_col( "SELECT school_id FROM {$p}hl_cycle_school WHERE cycle_id = " . (int) $src->cycle_id );
        foreach ( $schools as $school_id ) {
            $wpdb->insert( "{$p}hl_cycle_school", array(
                'cycle_id'  => $new_cycle_id,
                'school_id' => (int) $school_id,
            ) );
        }

        WP_CLI::log( "  [2] Cycle cloned: {$src->cycle_code} (id={$src->cycle_id}) → {$target_code} (id={$new_cycle_id}), " . count( $schools ) . " schools linked" );
    }
}
```

- [ ] **Step 4: Implement `clone_users()`**

Discovers all distinct users across both source cycles, creates new WP accounts with yopmail emails.

```php
private function clone_users( $source_cycles ) {
    global $wpdb;
    $p = $wpdb->prefix;

    // Collect all distinct user_ids across source cycles (enrollments + coach assignments).
    $source_cycle_ids = array_map( function( $c ) { return (int) $c->cycle_id; }, $source_cycles );
    $cycle_in = implode( ',', $source_cycle_ids );

    // Enrolled users.
    $enrolled_user_ids = $wpdb->get_col(
        "SELECT DISTINCT user_id FROM {$p}hl_enrollment WHERE cycle_id IN ({$cycle_in})"
    );

    // Coach users (not enrolled, but have assignments).
    $coach_user_ids = $wpdb->get_col(
        "SELECT DISTINCT coach_user_id FROM {$p}hl_coach_assignment WHERE cycle_id IN ({$cycle_in})"
    );

    // Coaching session coaches (may not have formal assignments).
    $session_coach_ids = $wpdb->get_col(
        "SELECT DISTINCT coach_user_id FROM {$p}hl_coaching_session WHERE cycle_id IN ({$cycle_in})"
    );

    $all_user_ids = array_unique( array_merge(
        array_map( 'intval', $enrolled_user_ids ),
        array_map( 'intval', $coach_user_ids ),
        array_map( 'intval', $session_coach_ids )
    ) );

    $created = 0;
    $errors  = 0;

    foreach ( $all_user_ids as $old_user_id ) {
        $wp_user = get_user_by( 'id', $old_user_id );
        if ( ! $wp_user ) {
            WP_CLI::warning( "  User ID {$old_user_id} not found — skipping." );
            $errors++;
            continue;
        }

        $new_user_id = $this->create_yopmail_user( $wp_user );
        if ( $new_user_id ) {
            $this->user_map[ $old_user_id ] = $new_user_id;
            $created++;
        } else {
            $errors++;
        }
    }

    WP_CLI::log( "  [3] Users cloned: {$created} created, {$errors} errors" );
}

/**
 * Create a yopmail clone of a WP user.
 *
 * Email: localpart@yopmail.com
 * Username: localpart-test (with collision handling).
 * Same first/last name, tagged with CLONE_META_KEY.
 *
 * @param WP_User $source_user The original user.
 * @return int|false New user ID or false on failure.
 */
private function create_yopmail_user( $source_user ) {
    $parts     = explode( '@', $source_user->user_email );
    $localpart = sanitize_user( $parts[0], true );
    $new_email = $localpart . '@' . self::EMAIL_DOMAIN;

    // Check email collision.
    $existing_by_email = get_user_by( 'email', $new_email );
    if ( $existing_by_email ) {
        // Already created in a previous run or by someone else.
        update_user_meta( $existing_by_email->ID, self::CLONE_META_KEY, 'found' );
        return $existing_by_email->ID;
    }

    // Handle username collision with suffix loop.
    $username = $localpart . '-test';
    $base     = $username;
    $suffix   = 1;
    while ( username_exists( $username ) ) {
        $existing = get_user_by( 'login', $username );
        if ( $existing && $existing->user_email === $new_email ) {
            update_user_meta( $existing->ID, self::CLONE_META_KEY, 'found' );
            return $existing->ID;
        }
        $username = $base . '-' . $suffix;
        $suffix++;
    }

    $user_id = wp_insert_user( array(
        'user_login'   => $username,
        'user_email'   => $new_email,
        'user_pass'    => wp_generate_password( 24 ),
        'display_name' => $source_user->first_name . ' ' . $source_user->last_name,
        'first_name'   => $source_user->first_name,
        'last_name'    => $source_user->last_name,
        'role'         => 'subscriber',
    ) );

    if ( is_wp_error( $user_id ) ) {
        WP_CLI::warning( "  Could not create user {$new_email}: " . $user_id->get_error_message() );
        return false;
    }

    // Copy WP roles from source.
    $new_user = get_user_by( 'id', $user_id );
    foreach ( $source_user->roles as $role ) {
        $new_user->add_role( $role );
    }
    // Remove default subscriber if they have other roles.
    if ( count( $new_user->roles ) > 1 && in_array( 'subscriber', $new_user->roles, true ) ) {
        $new_user->remove_role( 'subscriber' );
    }

    update_user_meta( $user_id, self::CLONE_META_KEY, 'created' );

    return $user_id;
}
```

- [ ] **Step 5: Commit**

```bash
git add includes/cli/class-hl-cli-clone-elcpb-test-mirror.php
git commit -m "feat(cli): clone-elcpb-test-mirror partnership, cycles, and user creation"
```

---

## Task 3: Clone Pathways, Components, Prereqs, and Drip Rules

**Files:**
- Modify: `includes/cli/class-hl-cli-clone-elcpb-test-mirror.php`

- [ ] **Step 1: Implement `clone_pathways_and_components()`**

```php
private function clone_pathways_and_components( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    // Clone pathways.
    $pathways = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_pathway WHERE cycle_id = %d", $src_cycle_id )
    );

    $pathway_count   = 0;
    $component_count = 0;

    foreach ( $pathways as $pw ) {
        $repo = new HL_Pathway_Repository();
        $new_pw_id = $repo->create( array(
            'cycle_id'            => $target_cycle_id,
            'pathway_name'        => $pw->pathway_name,
            'pathway_code'        => $pw->pathway_code,
            'description'         => $pw->description,
            'objectives'          => $pw->objectives,
            'syllabus_url'        => $pw->syllabus_url,
            'featured_image_id'   => $pw->featured_image_id,
            'avg_completion_time' => $pw->avg_completion_time,
            'expiration_date'     => $pw->expiration_date,
            'is_template'         => $pw->is_template,
            'target_roles'        => $pw->target_roles, // Already JSON string from DB.
            'active_status'       => $pw->active_status,
            'routing_type'        => $pw->routing_type,
        ) );

        $this->pathway_map[ (int) $pw->pathway_id ] = $new_pw_id;
        $pathway_count++;

        // Clone components for this pathway.
        $components = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$p}hl_component WHERE pathway_id = %d", $pw->pathway_id )
        );

        foreach ( $components as $cmp ) {
            $repo_c = new HL_Component_Repository();
            $new_cmp_id = $repo_c->create( array(
                'cycle_id'               => $target_cycle_id,
                'pathway_id'             => $new_pw_id,
                'component_type'         => $cmp->component_type,
                'title'                  => $cmp->title,
                'description'            => $cmp->description,
                'ordering_hint'          => $cmp->ordering_hint,
                'weight'                 => $cmp->weight,
                'external_ref'           => $cmp->external_ref, // JSON string — LD course IDs stay the same.
                'catalog_id'             => $cmp->catalog_id,    // Shared catalog — same IDs.
                'complete_by'            => $cmp->complete_by,
                'scheduling_window_start' => $cmp->scheduling_window_start,
                'scheduling_window_end'  => $cmp->scheduling_window_end,
                'display_window_start'   => $cmp->display_window_start,
                'display_window_end'     => $cmp->display_window_end,
                'visibility'             => $cmp->visibility,
                'requires_classroom'     => $cmp->requires_classroom,
                'eligible_roles'         => $cmp->eligible_roles, // JSON string.
                'status'                 => $cmp->status,
            ) );

            $this->component_map[ (int) $cmp->component_id ] = $new_cmp_id;
            $component_count++;
        }
    }

    // Second pass: clone prereq groups + items (need full component_map).
    $this->clone_prereqs( $src_cycle_id );

    // Third pass: clone drip rules (need full component_map).
    $this->clone_drip_rules( $src_cycle_id );

    WP_CLI::log( "  [4] Pathways: {$pathway_count}, Components: {$component_count}" );
}
```

- [ ] **Step 2: Implement `clone_prereqs()`**

```php
private function clone_prereqs( $src_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $groups = $wpdb->get_results(
        "SELECT pg.* FROM {$p}hl_component_prereq_group pg
         JOIN {$p}hl_component c ON pg.component_id = c.component_id
         WHERE c.cycle_id = " . (int) $src_cycle_id
    );

    $group_map = array(); // old_group_id => new_group_id
    foreach ( $groups as $g ) {
        $new_component_id = isset( $this->component_map[ (int) $g->component_id ] )
            ? $this->component_map[ (int) $g->component_id ]
            : null;

        if ( ! $new_component_id ) {
            continue;
        }

        $wpdb->insert( "{$p}hl_component_prereq_group", array(
            'component_id' => $new_component_id,
            'prereq_type'  => $g->prereq_type,
            'n_required'   => $g->n_required,
        ) );
        $group_map[ (int) $g->group_id ] = $wpdb->insert_id;
    }

    // Clone items.
    foreach ( $groups as $g ) {
        $new_group_id = isset( $group_map[ (int) $g->group_id ] ) ? $group_map[ (int) $g->group_id ] : null;
        if ( ! $new_group_id ) {
            continue;
        }

        $items = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$p}hl_component_prereq_item WHERE group_id = %d", $g->group_id )
        );

        foreach ( $items as $item ) {
            $new_prereq_cmp_id = isset( $this->component_map[ (int) $item->prerequisite_component_id ] )
                ? $this->component_map[ (int) $item->prerequisite_component_id ]
                : null;

            if ( ! $new_prereq_cmp_id ) {
                WP_CLI::warning( "  Prereq component {$item->prerequisite_component_id} not in map — skipping item." );
                continue;
            }

            $wpdb->insert( "{$p}hl_component_prereq_item", array(
                'group_id'                  => $new_group_id,
                'prerequisite_component_id' => $new_prereq_cmp_id,
            ) );
        }
    }

    WP_CLI::log( "      Prereq groups: " . count( $group_map ) );
}
```

- [ ] **Step 3: Implement `clone_drip_rules()`**

```php
private function clone_drip_rules( $src_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $rules = $wpdb->get_results(
        "SELECT dr.* FROM {$p}hl_component_drip_rule dr
         JOIN {$p}hl_component c ON dr.component_id = c.component_id
         WHERE c.cycle_id = " . (int) $src_cycle_id
    );

    $count = 0;
    foreach ( $rules as $r ) {
        $new_component_id = isset( $this->component_map[ (int) $r->component_id ] )
            ? $this->component_map[ (int) $r->component_id ]
            : null;

        if ( ! $new_component_id ) {
            continue;
        }

        // Remap base_component_id if set.
        $new_base = null;
        if ( ! empty( $r->base_component_id ) ) {
            $new_base = isset( $this->component_map[ (int) $r->base_component_id ] )
                ? $this->component_map[ (int) $r->base_component_id ]
                : null;

            if ( ! $new_base ) {
                WP_CLI::warning( "  Drip rule base component {$r->base_component_id} not in map — skipping." );
                continue;
            }
        }

        $wpdb->insert( "{$p}hl_component_drip_rule", array(
            'component_id'      => $new_component_id,
            'drip_type'         => $r->drip_type,
            'release_at_date'   => $r->release_at_date,
            'base_component_id' => $new_base,
            'delay_days'        => $r->delay_days,
        ) );
        $count++;
    }

    WP_CLI::log( "      Drip rules: {$count}" );
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/cli/class-hl-cli-clone-elcpb-test-mirror.php
git commit -m "feat(cli): clone pathways, components, prereqs, drip rules with ID remapping"
```

---

## Task 4: Clone Enrollments, Pathway Assignments, Teams

**Files:**
- Modify: `includes/cli/class-hl-cli-clone-elcpb-test-mirror.php`

- [ ] **Step 1: Implement `clone_enrollments()`**

```php
private function clone_enrollments( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $enrollments = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_enrollment WHERE cycle_id = %d", $src_cycle_id )
    );

    $repo  = new HL_Enrollment_Repository();
    $count = 0;

    foreach ( $enrollments as $e ) {
        $new_user_id = isset( $this->user_map[ (int) $e->user_id ] )
            ? $this->user_map[ (int) $e->user_id ]
            : null;

        if ( ! $new_user_id ) {
            WP_CLI::warning( "  Enrollment user {$e->user_id} not in user map — skipping." );
            continue;
        }

        $new_eid = $repo->create( array(
            'cycle_id'            => $target_cycle_id,
            'user_id'             => $new_user_id,
            'roles'               => $e->roles, // JSON string from DB.
            'school_id'           => $e->school_id, // Same school orgunits.
            'district_id'         => $e->district_id,
            'status'              => $e->status,
            'language_preference' => $e->language_preference,
            'enrolled_at'         => $e->enrolled_at,
        ) );

        $this->enrollment_map[ (int) $e->enrollment_id ] = $new_eid;
        $count++;
    }

    WP_CLI::log( "  [5] Enrollments: {$count}" );
}
```

- [ ] **Step 2: Implement `clone_pathway_assignments()`**

```php
private function clone_pathway_assignments( $src_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $assignments = $wpdb->get_results(
        "SELECT pa.* FROM {$p}hl_pathway_assignment pa
         JOIN {$p}hl_enrollment e ON pa.enrollment_id = e.enrollment_id
         WHERE e.cycle_id = " . (int) $src_cycle_id
    );

    $count = 0;
    foreach ( $assignments as $a ) {
        $new_eid = isset( $this->enrollment_map[ (int) $a->enrollment_id ] )
            ? $this->enrollment_map[ (int) $a->enrollment_id ]
            : null;
        $new_pid = isset( $this->pathway_map[ (int) $a->pathway_id ] )
            ? $this->pathway_map[ (int) $a->pathway_id ]
            : null;

        if ( ! $new_eid || ! $new_pid ) {
            continue;
        }

        $assigned_by = isset( $this->user_map[ (int) $a->assigned_by_user_id ] )
            ? $this->user_map[ (int) $a->assigned_by_user_id ]
            : $a->assigned_by_user_id; // Keep original if not cloned (e.g. admin).

        $wpdb->insert( "{$p}hl_pathway_assignment", array(
            'enrollment_id'      => $new_eid,
            'pathway_id'         => $new_pid,
            'assigned_by_user_id' => $assigned_by,
            'assignment_type'    => $a->assignment_type,
            'created_at'         => $a->created_at,
        ) );
        $count++;
    }

    WP_CLI::log( "  [6] Pathway assignments: {$count}" );
}
```

- [ ] **Step 3: Implement `clone_teams()`**

```php
private function clone_teams( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $teams = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_team WHERE cycle_id = %d", $src_cycle_id )
    );

    $team_count   = 0;
    $member_count = 0;

    foreach ( $teams as $t ) {
        $repo = new HL_Team_Repository();
        $new_team_id = $repo->create( array(
            'cycle_id'  => $target_cycle_id,
            'school_id' => $t->school_id, // Same school orgunits.
            'team_name' => $t->team_name,
            'status'    => $t->status,
        ) );

        $this->team_map[ (int) $t->team_id ] = $new_team_id;
        $team_count++;

        // Clone memberships.
        $members = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$p}hl_team_membership WHERE team_id = %d", $t->team_id )
        );

        foreach ( $members as $m ) {
            $new_eid = isset( $this->enrollment_map[ (int) $m->enrollment_id ] )
                ? $this->enrollment_map[ (int) $m->enrollment_id ]
                : null;

            if ( ! $new_eid ) {
                continue;
            }

            $repo->add_member( $new_team_id, $new_eid, $m->membership_type );
            $member_count++;
        }
    }

    WP_CLI::log( "  [7] Teams: {$team_count}, Members: {$member_count}" );
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/cli/class-hl-cli-clone-elcpb-test-mirror.php
git commit -m "feat(cli): clone enrollments, pathway assignments, teams with ID remapping"
```

---

## Task 5: Clone Classrooms, Teaching Assignments, Coach Assignments, Children

**Files:**
- Modify: `includes/cli/class-hl-cli-clone-elcpb-test-mirror.php`

- [ ] **Step 1: Implement `clone_classrooms()`**

```php
private function clone_classrooms( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $classrooms = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_classroom WHERE cycle_id = %d", $src_cycle_id )
    );

    $repo  = new HL_Classroom_Repository();
    $count = 0;

    foreach ( $classrooms as $cr ) {
        $new_cr_id = $repo->create( array(
            'school_id'      => $cr->school_id,
            'cycle_id'       => $target_cycle_id,
            'classroom_name' => $cr->classroom_name,
            'age_band'       => $cr->age_band,
            'status'         => $cr->status,
        ) );

        $this->classroom_map[ (int) $cr->classroom_id ] = $new_cr_id;
        $count++;
    }

    WP_CLI::log( "  [8] Classrooms: {$count}" );
}
```

- [ ] **Step 2: Implement `clone_teaching_assignments()`**

```php
private function clone_teaching_assignments( $src_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $assignments = $wpdb->get_results(
        "SELECT ta.* FROM {$p}hl_teaching_assignment ta
         JOIN {$p}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
         WHERE e.cycle_id = " . (int) $src_cycle_id
    );

    $count = 0;
    foreach ( $assignments as $ta ) {
        $new_eid = isset( $this->enrollment_map[ (int) $ta->enrollment_id ] )
            ? $this->enrollment_map[ (int) $ta->enrollment_id ]
            : null;
        $new_crid = isset( $this->classroom_map[ (int) $ta->classroom_id ] )
            ? $this->classroom_map[ (int) $ta->classroom_id ]
            : null;

        if ( ! $new_eid || ! $new_crid ) {
            continue;
        }

        $wpdb->insert( "{$p}hl_teaching_assignment", array(
            'enrollment_id'        => $new_eid,
            'classroom_id'         => $new_crid,
            'is_lead_teacher'      => $ta->is_lead_teacher,
            'effective_start_date' => $ta->effective_start_date,
            'effective_end_date'   => $ta->effective_end_date,
        ) );
        $count++;
    }

    WP_CLI::log( "  [9] Teaching assignments: {$count}" );
}
```

- [ ] **Step 3: Implement `clone_coach_assignments()`**

```php
private function clone_coach_assignments( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $assignments = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_coach_assignment WHERE cycle_id = %d", $src_cycle_id )
    );

    $count = 0;
    foreach ( $assignments as $ca ) {
        $new_coach_uid = isset( $this->user_map[ (int) $ca->coach_user_id ] )
            ? $this->user_map[ (int) $ca->coach_user_id ]
            : null;

        if ( ! $new_coach_uid ) {
            continue;
        }

        // Remap scope_id based on scope_type.
        $new_scope_id = (int) $ca->scope_id;
        switch ( $ca->scope_type ) {
            case 'school':
                // Schools are shared — keep original scope_id.
                break;
            case 'team':
                $new_scope_id = isset( $this->team_map[ (int) $ca->scope_id ] )
                    ? $this->team_map[ (int) $ca->scope_id ]
                    : 0;
                break;
            case 'enrollment':
                $new_scope_id = isset( $this->enrollment_map[ (int) $ca->scope_id ] )
                    ? $this->enrollment_map[ (int) $ca->scope_id ]
                    : 0;
                break;
        }

        if ( ! $new_scope_id ) {
            continue;
        }

        $wpdb->insert( "{$p}hl_coach_assignment", array(
            'coach_user_id'  => $new_coach_uid,
            'scope_type'     => $ca->scope_type,
            'scope_id'       => $new_scope_id,
            'cycle_id'       => $target_cycle_id,
            'effective_from' => $ca->effective_from,
            'effective_to'   => $ca->effective_to,
        ) );
        $count++;
    }

    WP_CLI::log( "  [10] Coach assignments: {$count}" );
}
```

- [ ] **Step 4: Implement `clone_children()`**

Children are school-scoped but their classroom assignments and assessment data are cycle-scoped. We clone children with modified fingerprints to avoid dedup collisions.

```php
private function clone_children( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    // Find children in classrooms belonging to this source cycle.
    $children = $wpdb->get_results(
        "SELECT DISTINCT ch.* FROM {$p}hl_child ch
         JOIN {$p}hl_child_classroom_current cc ON ch.child_id = cc.child_id
         JOIN {$p}hl_classroom cr ON cc.classroom_id = cr.classroom_id
         WHERE cr.cycle_id = " . (int) $src_cycle_id
    );

    // Also get children from child assessment instances for this cycle
    // (some may not have current classroom assignments).
    $assessed_children = $wpdb->get_results(
        "SELECT DISTINCT ch.* FROM {$p}hl_child ch
         JOIN {$p}hl_child_assessment_instance cai ON 1=1
         JOIN {$p}hl_child_assessment_childrow car ON car.instance_id = cai.instance_id AND car.child_id = ch.child_id
         WHERE cai.cycle_id = " . (int) $src_cycle_id
    );

    // Merge, dedup by child_id.
    $all_children = array();
    foreach ( array_merge( $children, $assessed_children ) as $ch ) {
        $all_children[ (int) $ch->child_id ] = $ch;
    }

    $repo  = new HL_Child_Repository();
    $count = 0;

    foreach ( $all_children as $ch ) {
        // Skip if already cloned (from a previous cycle iteration).
        if ( isset( $this->child_map[ (int) $ch->child_id ] ) ) {
            continue;
        }

        $new_child_id = $repo->create( array(
            'school_id'         => $ch->school_id,
            'first_name'        => 'T-' . $ch->first_name, // Prefix to avoid fingerprint collision.
            'last_name'         => $ch->last_name,
            'dob'               => $ch->dob,
            'internal_child_id' => $ch->internal_child_id,
            'ethnicity'         => $ch->ethnicity,
            'metadata'          => $ch->metadata,
        ) );

        // Override fingerprint to be clearly test data.
        $wpdb->update(
            "{$p}hl_child",
            array( 'child_fingerprint' => 'TEST-' . $ch->child_id . '-' . $new_child_id ),
            array( 'child_id' => $new_child_id )
        );

        $this->child_map[ (int) $ch->child_id ] = $new_child_id;
        $count++;
    }

    // Clone classroom assignments for children in this cycle's classrooms.
    $current_assignments = $wpdb->get_results(
        "SELECT cc.* FROM {$p}hl_child_classroom_current cc
         JOIN {$p}hl_classroom cr ON cc.classroom_id = cr.classroom_id
         WHERE cr.cycle_id = " . (int) $src_cycle_id
    );

    $assign_count = 0;
    foreach ( $current_assignments as $ca ) {
        $new_child_id = isset( $this->child_map[ (int) $ca->child_id ] )
            ? $this->child_map[ (int) $ca->child_id ]
            : null;
        $new_crid = isset( $this->classroom_map[ (int) $ca->classroom_id ] )
            ? $this->classroom_map[ (int) $ca->classroom_id ]
            : null;

        if ( ! $new_child_id || ! $new_crid ) {
            continue;
        }

        $added_by = null;
        if ( ! empty( $ca->added_by_enrollment_id ) ) {
            $added_by = isset( $this->enrollment_map[ (int) $ca->added_by_enrollment_id ] )
                ? $this->enrollment_map[ (int) $ca->added_by_enrollment_id ]
                : null;
        }

        $wpdb->insert( "{$p}hl_child_classroom_current", array(
            'child_id'                => $new_child_id,
            'classroom_id'            => $new_crid,
            'assigned_at'             => $ca->assigned_at,
            'status'                  => $ca->status,
            'added_by_enrollment_id'  => $added_by,
            'added_at'                => $ca->added_at,
        ) );
        $assign_count++;
    }

    // Clone cycle snapshots.
    $snapshots = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_child_cycle_snapshot WHERE cycle_id = %d", $src_cycle_id )
    );

    $snap_count = 0;
    foreach ( $snapshots as $s ) {
        $new_child_id = isset( $this->child_map[ (int) $s->child_id ] )
            ? $this->child_map[ (int) $s->child_id ]
            : null;

        if ( ! $new_child_id ) {
            continue;
        }

        $wpdb->insert( "{$p}hl_child_cycle_snapshot", array(
            'child_id'           => $new_child_id,
            'cycle_id'           => $target_cycle_id,
            'frozen_age_group'   => $s->frozen_age_group,
            'dob_at_freeze'      => $s->dob_at_freeze,
            'age_months_at_freeze' => $s->age_months_at_freeze,
            'frozen_at'          => $s->frozen_at,
        ) );
        $snap_count++;
    }

    WP_CLI::log( "  [11] Children: {$count}, Classroom assignments: {$assign_count}, Cycle snapshots: {$snap_count}" );
}
```

- [ ] **Step 5: Commit**

```bash
git add includes/cli/class-hl-cli-clone-elcpb-test-mirror.php
git commit -m "feat(cli): clone classrooms, teaching/coach assignments, children with fingerprint isolation"
```

---

## Task 6: Clone Component States, Overrides, Completion Rollups

**Files:**
- Modify: `includes/cli/class-hl-cli-clone-elcpb-test-mirror.php`

- [ ] **Step 1: Implement `clone_component_states()`**

```php
private function clone_component_states( $src_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $states = $wpdb->get_results(
        "SELECT cs.* FROM {$p}hl_component_state cs
         JOIN {$p}hl_enrollment e ON cs.enrollment_id = e.enrollment_id
         WHERE e.cycle_id = " . (int) $src_cycle_id
    );

    $count = 0;
    foreach ( $states as $s ) {
        $new_eid = isset( $this->enrollment_map[ (int) $s->enrollment_id ] )
            ? $this->enrollment_map[ (int) $s->enrollment_id ]
            : null;
        $new_cid = isset( $this->component_map[ (int) $s->component_id ] )
            ? $this->component_map[ (int) $s->component_id ]
            : null;

        if ( ! $new_eid || ! $new_cid ) {
            continue;
        }

        $wpdb->insert( "{$p}hl_component_state", array(
            'enrollment_id'      => $new_eid,
            'component_id'       => $new_cid,
            'completion_percent' => $s->completion_percent,
            'completion_status'  => $s->completion_status,
            'completed_at'       => $s->completed_at,
            'evidence_ref'       => $s->evidence_ref,
            'last_computed_at'   => $s->last_computed_at,
        ) );
        $count++;
    }

    WP_CLI::log( "  [12] Component states: {$count}" );
}
```

- [ ] **Step 2: Implement `clone_component_overrides()`**

```php
private function clone_component_overrides( $src_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $overrides = $wpdb->get_results(
        "SELECT co.* FROM {$p}hl_component_override co
         JOIN {$p}hl_enrollment e ON co.enrollment_id = e.enrollment_id
         WHERE e.cycle_id = " . (int) $src_cycle_id
    );

    $count = 0;
    foreach ( $overrides as $o ) {
        $new_eid = isset( $this->enrollment_map[ (int) $o->enrollment_id ] )
            ? $this->enrollment_map[ (int) $o->enrollment_id ]
            : null;
        $new_cid = isset( $this->component_map[ (int) $o->component_id ] )
            ? $this->component_map[ (int) $o->component_id ]
            : null;

        if ( ! $new_eid || ! $new_cid ) {
            continue;
        }

        $applied_by = isset( $this->user_map[ (int) $o->applied_by_user_id ] )
            ? $this->user_map[ (int) $o->applied_by_user_id ]
            : $o->applied_by_user_id;

        $wpdb->insert( "{$p}hl_component_override", array(
            'override_uuid'      => wp_generate_uuid4(),
            'enrollment_id'      => $new_eid,
            'component_id'       => $new_cid,
            'override_type'      => $o->override_type,
            'applied_by_user_id' => $applied_by,
            'reason'             => $o->reason,
        ) );
        $count++;
    }

    if ( $count > 0 ) {
        WP_CLI::log( "  [13] Component overrides: {$count}" );
    }
}
```

- [ ] **Step 3: Implement `clone_completion_rollups()`**

```php
private function clone_completion_rollups( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $rollups = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_completion_rollup WHERE cycle_id = %d", $src_cycle_id )
    );

    $count = 0;
    foreach ( $rollups as $r ) {
        $new_eid = isset( $this->enrollment_map[ (int) $r->enrollment_id ] )
            ? $this->enrollment_map[ (int) $r->enrollment_id ]
            : null;

        if ( ! $new_eid ) {
            continue;
        }

        $wpdb->insert( "{$p}hl_completion_rollup", array(
            'enrollment_id'              => $new_eid,
            'cycle_id'                   => $target_cycle_id,
            'pathway_completion_percent' => $r->pathway_completion_percent,
            'cycle_completion_percent'   => $r->cycle_completion_percent,
            'last_computed_at'           => $r->last_computed_at,
        ) );
        $count++;
    }

    WP_CLI::log( "  [14] Completion rollups: {$count}" );
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/cli/class-hl-cli-clone-elcpb-test-mirror.php
git commit -m "feat(cli): clone component states, overrides, completion rollups"
```

---

## Task 7: Clone Assessments (TSA + Child)

**Files:**
- Modify: `includes/cli/class-hl-cli-clone-elcpb-test-mirror.php`

- [ ] **Step 1: Implement `clone_teacher_assessments()`**

```php
private function clone_teacher_assessments( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $instances = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_teacher_assessment_instance WHERE cycle_id = %d", $src_cycle_id )
    );

    $count = 0;
    foreach ( $instances as $i ) {
        $new_eid = isset( $this->enrollment_map[ (int) $i->enrollment_id ] )
            ? $this->enrollment_map[ (int) $i->enrollment_id ]
            : null;

        if ( ! $new_eid ) {
            continue;
        }

        $new_cmp_id = null;
        if ( ! empty( $i->component_id ) ) {
            $new_cmp_id = isset( $this->component_map[ (int) $i->component_id ] )
                ? $this->component_map[ (int) $i->component_id ]
                : null;
        }

        $wpdb->insert( "{$p}hl_teacher_assessment_instance", array(
            'instance_uuid'      => wp_generate_uuid4(),
            'cycle_id'           => $target_cycle_id,
            'enrollment_id'      => $new_eid,
            'component_id'       => $new_cmp_id,
            'phase'              => $i->phase,
            'instrument_id'      => $i->instrument_id, // Shared instrument — same ID.
            'instrument_version' => $i->instrument_version,
            'jfb_form_id'        => $i->jfb_form_id,
            'jfb_record_id'      => null, // JFB record is not cloned.
            'responses_json'     => $i->responses_json,
            'status'             => $i->status,
            'started_at'         => $i->started_at,
            'submitted_at'       => $i->submitted_at,
        ) );
        $count++;
    }

    WP_CLI::log( "  [15] Teacher assessment instances: {$count}" );
}
```

- [ ] **Step 2: Implement `clone_child_assessments()`**

```php
private function clone_child_assessments( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $instances = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_child_assessment_instance WHERE cycle_id = %d", $src_cycle_id )
    );

    $inst_count = 0;
    $row_count  = 0;

    foreach ( $instances as $i ) {
        $new_eid = isset( $this->enrollment_map[ (int) $i->enrollment_id ] )
            ? $this->enrollment_map[ (int) $i->enrollment_id ]
            : null;
        $new_crid = isset( $this->classroom_map[ (int) $i->classroom_id ] )
            ? $this->classroom_map[ (int) $i->classroom_id ]
            : null;
        $new_cmp_id = null;
        if ( ! empty( $i->component_id ) ) {
            $new_cmp_id = isset( $this->component_map[ (int) $i->component_id ] )
                ? $this->component_map[ (int) $i->component_id ]
                : null;
        }

        if ( ! $new_eid ) {
            continue;
        }

        $wpdb->insert( "{$p}hl_child_assessment_instance", array(
            'instance_uuid'      => wp_generate_uuid4(),
            'cycle_id'           => $target_cycle_id,
            'enrollment_id'      => $new_eid,
            'component_id'       => $new_cmp_id,
            'classroom_id'       => $new_crid,
            'school_id'          => $i->school_id, // Same school.
            'phase'              => $i->phase,
            'instrument_age_band' => $i->instrument_age_band,
            'instrument_id'      => $i->instrument_id, // Shared instrument.
            'instrument_version' => $i->instrument_version,
            'responses_json'     => $i->responses_json,
            'status'             => $i->status,
            'started_at'         => $i->started_at,
            'submitted_at'       => $i->submitted_at,
        ) );

        $new_instance_id = $wpdb->insert_id;
        $inst_count++;

        // Clone childrows for this instance.
        $childrows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$p}hl_child_assessment_childrow WHERE instance_id = %d", $i->instance_id )
        );

        foreach ( $childrows as $cr ) {
            $new_child_id = isset( $this->child_map[ (int) $cr->child_id ] )
                ? $this->child_map[ (int) $cr->child_id ]
                : null;

            if ( ! $new_child_id ) {
                continue;
            }

            $wpdb->insert( "{$p}hl_child_assessment_childrow", array(
                'instance_id'      => $new_instance_id,
                'child_id'         => $new_child_id,
                'answers_json'     => $cr->answers_json,
                'status'           => $cr->status,
                'skip_reason'      => $cr->skip_reason,
                'frozen_age_group' => $cr->frozen_age_group,
                'instrument_id'    => $cr->instrument_id,
            ) );
            $row_count++;
        }
    }

    WP_CLI::log( "  [16] Child assessment instances: {$inst_count}, Childrows: {$row_count}" );
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/cli/class-hl-cli-clone-elcpb-test-mirror.php
git commit -m "feat(cli): clone teacher and child assessments with full response data"
```

---

## Task 8: Clone Coaching Sessions, RP Sessions, Classroom Visits, Observations

**Files:**
- Modify: `includes/cli/class-hl-cli-clone-elcpb-test-mirror.php`

- [ ] **Step 1: Implement `clone_coaching_sessions()`**

Two-pass approach: insert all sessions first, then update `rescheduled_from_session_id` self-references.

```php
private function clone_coaching_sessions( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $sessions = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_coaching_session WHERE cycle_id = %d", $src_cycle_id )
    );

    // Track which sessions have rescheduled_from references for second pass.
    $reschedule_refs = array();
    $count = 0;

    // Pass 1: Insert all sessions.
    foreach ( $sessions as $s ) {
        $new_coach_uid = isset( $this->user_map[ (int) $s->coach_user_id ] )
            ? $this->user_map[ (int) $s->coach_user_id ]
            : null;
        $new_mentor_eid = isset( $this->enrollment_map[ (int) $s->mentor_enrollment_id ] )
            ? $this->enrollment_map[ (int) $s->mentor_enrollment_id ]
            : null;

        if ( ! $new_coach_uid || ! $new_mentor_eid ) {
            continue;
        }

        $new_cmp_id = null;
        if ( ! empty( $s->component_id ) ) {
            $new_cmp_id = isset( $this->component_map[ (int) $s->component_id ] )
                ? $this->component_map[ (int) $s->component_id ]
                : null;
        }

        $booked_by = null;
        if ( ! empty( $s->booked_by_user_id ) ) {
            $booked_by = isset( $this->user_map[ (int) $s->booked_by_user_id ] )
                ? $this->user_map[ (int) $s->booked_by_user_id ]
                : $s->booked_by_user_id;
        }

        $wpdb->insert( "{$p}hl_coaching_session", array(
            'session_uuid'                => wp_generate_uuid4(),
            'cycle_id'                    => $target_cycle_id,
            'coach_user_id'               => $new_coach_uid,
            'mentor_enrollment_id'        => $new_mentor_eid,
            'session_number'              => $s->session_number,
            'session_title'               => $s->session_title,
            'meeting_url'                 => $s->meeting_url,
            'session_status'              => $s->session_status,
            'attendance_status'           => $s->attendance_status,
            'session_datetime'            => $s->session_datetime,
            'notes_richtext'              => $s->notes_richtext,
            'cancelled_at'                => $s->cancelled_at,
            'rescheduled_from_session_id' => null, // Set in pass 2.
            'component_id'                => $new_cmp_id,
            'zoom_meeting_id'             => null, // Don't clone Zoom meetings.
            'outlook_event_id'            => null, // Don't clone Outlook events.
            'booked_by_user_id'           => $booked_by,
            'mentor_timezone'             => $s->mentor_timezone,
            'coach_timezone'              => $s->coach_timezone,
        ) );

        $new_session_id = $wpdb->insert_id;
        $this->session_map[ (int) $s->session_id ] = $new_session_id;
        $count++;

        if ( ! empty( $s->rescheduled_from_session_id ) ) {
            $reschedule_refs[ $new_session_id ] = (int) $s->rescheduled_from_session_id;
        }

        // Clone submissions for this session.
        $submissions = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$p}hl_coaching_session_submission WHERE session_id = %d", $s->session_id )
        );

        foreach ( $submissions as $sub ) {
            $submitted_by = isset( $this->user_map[ (int) $sub->submitted_by_user_id ] )
                ? $this->user_map[ (int) $sub->submitted_by_user_id ]
                : $sub->submitted_by_user_id;

            $wpdb->insert( "{$p}hl_coaching_session_submission", array(
                'submission_uuid'       => wp_generate_uuid4(),
                'session_id'            => $new_session_id,
                'submitted_by_user_id'  => $submitted_by,
                'instrument_id'         => $sub->instrument_id,
                'role_in_session'       => $sub->role_in_session,
                'responses_json'        => $sub->responses_json,
                'status'                => $sub->status,
                'submitted_at'          => $sub->submitted_at,
            ) );
        }
    }

    // Pass 2: Update rescheduled_from_session_id self-references.
    foreach ( $reschedule_refs as $new_sid => $old_from_sid ) {
        $new_from_sid = isset( $this->session_map[ $old_from_sid ] )
            ? $this->session_map[ $old_from_sid ]
            : null;

        if ( $new_from_sid ) {
            $wpdb->update(
                "{$p}hl_coaching_session",
                array( 'rescheduled_from_session_id' => $new_from_sid ),
                array( 'session_id' => $new_sid )
            );
        }
    }

    WP_CLI::log( "  [17] Coaching sessions: {$count}" . ( ! empty( $reschedule_refs ) ? ', reschedule refs: ' . count( $reschedule_refs ) : '' ) );
}
```

- [ ] **Step 2: Implement `clone_rp_sessions()`**

```php
private function clone_rp_sessions( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $sessions = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_rp_session WHERE cycle_id = %d", $src_cycle_id )
    );

    $count = 0;
    foreach ( $sessions as $s ) {
        $new_mentor_eid = isset( $this->enrollment_map[ (int) $s->mentor_enrollment_id ] )
            ? $this->enrollment_map[ (int) $s->mentor_enrollment_id ]
            : null;
        $new_teacher_eid = isset( $this->enrollment_map[ (int) $s->teacher_enrollment_id ] )
            ? $this->enrollment_map[ (int) $s->teacher_enrollment_id ]
            : null;

        if ( ! $new_mentor_eid || ! $new_teacher_eid ) {
            continue;
        }

        $wpdb->insert( "{$p}hl_rp_session", array(
            'rp_session_uuid'       => wp_generate_uuid4(),
            'cycle_id'              => $target_cycle_id,
            'mentor_enrollment_id'  => $new_mentor_eid,
            'teacher_enrollment_id' => $new_teacher_eid,
            'session_number'        => $s->session_number,
            'status'                => $s->status,
            'session_date'          => $s->session_date,
            'notes'                 => $s->notes,
        ) );

        $new_rp_id = $wpdb->insert_id;
        $this->rp_session_map[ (int) $s->rp_session_id ] = $new_rp_id;
        $count++;

        // Clone submissions.
        $submissions = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$p}hl_rp_session_submission WHERE rp_session_id = %d", $s->rp_session_id )
        );

        foreach ( $submissions as $sub ) {
            $submitted_by = isset( $this->user_map[ (int) $sub->submitted_by_user_id ] )
                ? $this->user_map[ (int) $sub->submitted_by_user_id ]
                : $sub->submitted_by_user_id;

            $wpdb->insert( "{$p}hl_rp_session_submission", array(
                'submission_uuid'      => wp_generate_uuid4(),
                'rp_session_id'        => $new_rp_id,
                'submitted_by_user_id' => $submitted_by,
                'instrument_id'        => $sub->instrument_id,
                'role_in_session'      => $sub->role_in_session,
                'responses_json'       => $sub->responses_json,
                'status'               => $sub->status,
                'submitted_at'         => $sub->submitted_at,
            ) );
        }
    }

    WP_CLI::log( "  [18] RP sessions: {$count}" );
}
```

- [ ] **Step 3: Implement `clone_classroom_visits()`**

```php
private function clone_classroom_visits( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $visits = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_classroom_visit WHERE cycle_id = %d", $src_cycle_id )
    );

    $count = 0;
    foreach ( $visits as $v ) {
        $new_leader_eid = isset( $this->enrollment_map[ (int) $v->leader_enrollment_id ] )
            ? $this->enrollment_map[ (int) $v->leader_enrollment_id ]
            : null;
        $new_teacher_eid = isset( $this->enrollment_map[ (int) $v->teacher_enrollment_id ] )
            ? $this->enrollment_map[ (int) $v->teacher_enrollment_id ]
            : null;
        $new_crid = isset( $this->classroom_map[ (int) $v->classroom_id ] )
            ? $this->classroom_map[ (int) $v->classroom_id ]
            : null;

        if ( ! $new_leader_eid || ! $new_teacher_eid ) {
            continue;
        }

        $wpdb->insert( "{$p}hl_classroom_visit", array(
            'classroom_visit_uuid'  => wp_generate_uuid4(),
            'cycle_id'              => $target_cycle_id,
            'leader_enrollment_id'  => $new_leader_eid,
            'teacher_enrollment_id' => $new_teacher_eid,
            'classroom_id'          => $new_crid,
            'visit_number'          => $v->visit_number,
            'status'                => $v->status,
            'visit_date'            => $v->visit_date,
            'notes'                 => $v->notes,
        ) );

        $new_visit_id = $wpdb->insert_id;
        $this->visit_map[ (int) $v->classroom_visit_id ] = $new_visit_id;
        $count++;

        // Clone submissions.
        $submissions = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$p}hl_classroom_visit_submission WHERE classroom_visit_id = %d", $v->classroom_visit_id )
        );

        foreach ( $submissions as $sub ) {
            $submitted_by = isset( $this->user_map[ (int) $sub->submitted_by_user_id ] )
                ? $this->user_map[ (int) $sub->submitted_by_user_id ]
                : $sub->submitted_by_user_id;

            $wpdb->insert( "{$p}hl_classroom_visit_submission", array(
                'submission_uuid'       => wp_generate_uuid4(),
                'classroom_visit_id'    => $new_visit_id,
                'submitted_by_user_id'  => $submitted_by,
                'instrument_id'         => $sub->instrument_id,
                'role_in_visit'         => $sub->role_in_visit,
                'responses_json'        => $sub->responses_json,
                'status'                => $sub->status,
                'submitted_at'          => $sub->submitted_at,
            ) );
        }
    }

    WP_CLI::log( "  [19] Classroom visits: {$count}" );
}
```

- [ ] **Step 4: Implement `clone_observations()`**

```php
private function clone_observations( $src_cycle_id, $target_cycle_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    $observations = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$p}hl_observation WHERE cycle_id = %d", $src_cycle_id )
    );

    $count = 0;
    foreach ( $observations as $o ) {
        $new_mentor_eid = isset( $this->enrollment_map[ (int) $o->mentor_enrollment_id ] )
            ? $this->enrollment_map[ (int) $o->mentor_enrollment_id ]
            : null;
        $new_teacher_eid = isset( $this->enrollment_map[ (int) $o->teacher_enrollment_id ] )
            ? $this->enrollment_map[ (int) $o->teacher_enrollment_id ]
            : null;
        $new_crid = null;
        if ( ! empty( $o->classroom_id ) ) {
            $new_crid = isset( $this->classroom_map[ (int) $o->classroom_id ] )
                ? $this->classroom_map[ (int) $o->classroom_id ]
                : null;
        }

        if ( ! $new_mentor_eid || ! $new_teacher_eid ) {
            continue;
        }

        $wpdb->insert( "{$p}hl_observation", array(
            'observation_uuid'      => wp_generate_uuid4(),
            'cycle_id'              => $target_cycle_id,
            'mentor_enrollment_id'  => $new_mentor_eid,
            'teacher_enrollment_id' => $new_teacher_eid,
            'school_id'             => $o->school_id,
            'classroom_id'          => $new_crid,
            'instrument_id'         => $o->instrument_id,
            'instrument_version'    => $o->instrument_version,
            'jfb_form_id'           => $o->jfb_form_id,
            'jfb_record_id'         => null,
            'status'                => $o->status,
            'submitted_at'          => $o->submitted_at,
        ) );

        $new_obs_id = $wpdb->insert_id;
        $this->observation_map[ (int) $o->observation_id ] = $new_obs_id;
        $count++;

        // Link to coaching sessions if applicable.
        $links = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$p}hl_coaching_session_observation WHERE observation_id = %d", $o->observation_id )
        );

        foreach ( $links as $link ) {
            $new_session_id = isset( $this->session_map[ (int) $link->session_id ] )
                ? $this->session_map[ (int) $link->session_id ]
                : null;

            if ( $new_session_id ) {
                $wpdb->insert( "{$p}hl_coaching_session_observation", array(
                    'session_id'     => $new_session_id,
                    'observation_id' => $new_obs_id,
                ) );
            }
        }
    }

    if ( $count > 0 ) {
        WP_CLI::log( "  [20] Observations: {$count}" );
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add includes/cli/class-hl-cli-clone-elcpb-test-mirror.php
git commit -m "feat(cli): clone coaching, RP, classroom visits, observations with submissions"
```

---

## Task 9: LearnDash Sync + Coach Availability + Summary

**Files:**
- Modify: `includes/cli/class-hl-cli-clone-elcpb-test-mirror.php`

- [ ] **Step 1: Implement `sync_learndash()`**

Enrolls all new yopmail users in their LD courses and marks completed courses as complete.

```php
private function sync_learndash() {
    if ( ! function_exists( 'ld_update_course_access' ) || ! function_exists( 'learndash_update_user_activity' ) ) {
        WP_CLI::warning( '  LearnDash functions not available — skipping LD sync.' );
        return;
    }

    global $wpdb;
    $p = $wpdb->prefix;

    $enrolled = 0;
    $completed = 0;

    // For each new enrollment, find its pathway components and sync LD.
    foreach ( $this->enrollment_map as $old_eid => $new_eid ) {
        // Get the new user_id for this enrollment.
        $new_user_id = $wpdb->get_var(
            $wpdb->prepare( "SELECT user_id FROM {$p}hl_enrollment WHERE enrollment_id = %d", $new_eid )
        );

        if ( ! $new_user_id ) {
            continue;
        }

        // Get all LD course components for this enrollment's pathway(s).
        $components = $wpdb->get_results(
            "SELECT c.component_id, c.component_type, c.catalog_id, c.external_ref
             FROM {$p}hl_component c
             JOIN {$p}hl_pathway_assignment pa ON c.pathway_id = pa.pathway_id
             WHERE pa.enrollment_id = {$new_eid}
               AND c.component_type = 'learndash_course'
               AND c.status = 'active'"
        );

        // Get this enrollment's language preference for catalog resolution.
        $enrollment = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$p}hl_enrollment WHERE enrollment_id = %d", $new_eid )
        );

        foreach ( $components as $cmp ) {
            // Resolve the LD course ID (language-aware via catalog).
            $ld_course_id = null;

            if ( ! empty( $cmp->catalog_id ) ) {
                $catalog = $wpdb->get_row(
                    $wpdb->prepare( "SELECT * FROM {$p}hl_course_catalog WHERE catalog_id = %d", $cmp->catalog_id )
                );

                if ( $catalog ) {
                    $lang = ! empty( $enrollment->language_preference ) ? $enrollment->language_preference : 'en';
                    $col = 'ld_course_' . $lang;
                    $ld_course_id = ! empty( $catalog->$col ) ? (int) $catalog->$col : (int) $catalog->ld_course_en;
                }
            }

            if ( ! $ld_course_id && ! empty( $cmp->external_ref ) ) {
                $ref = json_decode( $cmp->external_ref, true );
                if ( ! empty( $ref['course_id'] ) ) {
                    $ld_course_id = (int) $ref['course_id'];
                }
            }

            if ( ! $ld_course_id ) {
                continue;
            }

            // Enroll in LD course.
            ld_update_course_access( (int) $new_user_id, $ld_course_id );
            $enrolled++;

            // Check if the source component state was complete.
            $state = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT completion_status FROM {$p}hl_component_state WHERE enrollment_id = %d AND component_id = %d",
                    $new_eid,
                    $cmp->component_id
                )
            );

            if ( $state && $state->completion_status === 'complete' ) {
                learndash_update_user_activity( array(
                    'user_id'            => (int) $new_user_id,
                    'course_id'          => $ld_course_id,
                    'post_id'            => $ld_course_id,
                    'activity_type'      => 'course',
                    'activity_status'    => true,
                    'activity_completed' => time(),
                    'activity_updated'   => time(),
                    'activity_started'   => time(),
                ) );
                $completed++;
            }
        }
    }

    WP_CLI::log( "  [21] LearnDash sync: {$enrolled} course enrollments, {$completed} completions marked" );
}
```

- [ ] **Step 2: Implement `clone_coach_availability()`**

```php
private function clone_coach_availability() {
    global $wpdb;
    $p = $wpdb->prefix;

    // Find coach user IDs that we cloned.
    $coach_user_ids = array();
    foreach ( $this->user_map as $old_uid => $new_uid ) {
        // Check if this old user had any coach_availability rows.
        $has_avail = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}hl_coach_availability WHERE coach_user_id = %d",
                $old_uid
            )
        );
        if ( $has_avail > 0 ) {
            $coach_user_ids[ $old_uid ] = $new_uid;
        }
    }

    $count = 0;
    foreach ( $coach_user_ids as $old_uid => $new_uid ) {
        $slots = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$p}hl_coach_availability WHERE coach_user_id = %d", $old_uid )
        );

        foreach ( $slots as $slot ) {
            $wpdb->insert( "{$p}hl_coach_availability", array(
                'coach_user_id' => $new_uid,
                'day_of_week'   => $slot->day_of_week,
                'start_time'    => $slot->start_time,
                'end_time'      => $slot->end_time,
            ) );
            $count++;
        }
    }

    if ( $count > 0 ) {
        WP_CLI::log( "  [22] Coach availability slots: {$count}" );
    }
}
```

- [ ] **Step 3: Implement `print_summary()`**

```php
private function print_summary() {
    WP_CLI::line( '' );
    WP_CLI::line( '╔══════════════════════════════════════════════╗' );
    WP_CLI::line( '║   CLONE COMPLETE                             ║' );
    WP_CLI::line( '╚══════════════════════════════════════════════╝' );
    WP_CLI::line( '' );
    WP_CLI::line( '  Partnership: ' . self::TARGET_PARTNERSHIP_CODE . " (id={$this->target_partnership_id})" );

    foreach ( $this->cycle_map as $old => $new ) {
        WP_CLI::line( "  Cycle: {$old} → {$new}" );
    }

    WP_CLI::line( '  Users cloned:       ' . count( $this->user_map ) );
    WP_CLI::line( '  Enrollments:        ' . count( $this->enrollment_map ) );
    WP_CLI::line( '  Pathways:           ' . count( $this->pathway_map ) );
    WP_CLI::line( '  Components:         ' . count( $this->component_map ) );
    WP_CLI::line( '  Teams:              ' . count( $this->team_map ) );
    WP_CLI::line( '  Classrooms:         ' . count( $this->classroom_map ) );
    WP_CLI::line( '  Children:           ' . count( $this->child_map ) );
    WP_CLI::line( '  Coaching sessions:  ' . count( $this->session_map ) );
    WP_CLI::line( '  RP sessions:        ' . count( $this->rp_session_map ) );
    WP_CLI::line( '  Classroom visits:   ' . count( $this->visit_map ) );
    WP_CLI::line( '  Observations:       ' . count( $this->observation_map ) );
    WP_CLI::line( '' );
    WP_CLI::line( '  Email domain: @' . self::EMAIL_DOMAIN );
    WP_CLI::line( '  Cleanup: wp hl-core clone-elcpb-test-mirror --clean' );
    WP_CLI::line( '' );
    WP_CLI::success( 'ELCPB Test Mirror created successfully!' );
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/cli/class-hl-cli-clone-elcpb-test-mirror.php
git commit -m "feat(cli): LearnDash sync, coach availability, summary output"
```

---

## Task 10: Final Integration + Deploy

- [ ] **Step 1: Verify file is syntactically correct**

```bash
php -l includes/cli/class-hl-cli-clone-elcpb-test-mirror.php
```

Expected: `No syntax errors detected`

- [ ] **Step 2: Deploy to production**

Follow deploy skill instructions. The command will be available as:

```bash
wp hl-core clone-elcpb-test-mirror
```

To run:
```bash
wp hl-core clone-elcpb-test-mirror
```

To clean up:
```bash
wp hl-core clone-elcpb-test-mirror --clean
```

- [ ] **Step 3: Commit final state**

```bash
git add -A
git commit -m "feat(cli): ELCPB test mirror clone command — complete"
```

---

## Execution Order Dependency Chart

```
Task 1 (scaffold + clean)
  └─► Task 2 (partnership + cycles + users)
       └─► Task 3 (pathways + components + prereqs + drips)
            └─► Task 4 (enrollments + pathway assignments + teams)
                 └─► Task 5 (classrooms + teaching + coach assignments + children)
                      └─► Task 6 (component states + overrides + rollups)
                           └─► Task 7 (assessments)
                                └─► Task 8 (coaching + RP + visits + observations)
                                     └─► Task 9 (LD sync + coach availability + summary)
                                          └─► Task 10 (verify + deploy)
```

All tasks are sequential — each depends on ID maps built by previous tasks.
