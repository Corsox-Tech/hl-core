# Beginnings Seeder Rebuild — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rewrite the Beginnings CLI seeder as a single command that creates Cycle 1 (closed, 70% completion) + Cycle 2 (active, fresh) with child assessment instruments/instances and demo coach assignment.

**Architecture:** Single PHP class `HL_CLI_Seed_Beginnings` registered as `wp hl-core seed-beginnings`. Seeds shared org structure first, then Cycle 1 with historical data, then Cycle 2 with fresh roster. Clean mode nukes both cycles. All data keyed by constants for idempotent cleanup.

**Tech Stack:** PHP 7.4+, WordPress/WP-CLI, MySQL (via $wpdb), HL Core services (repositories, services).

**Spec:** `docs/superpowers/specs/2026-03-26-beginnings-seeder-rebuild-design.md`

**Environment:** No local PHP — code is edited locally, deployed to test server via SSH, run via `wp hl-core seed-beginnings` on the server.

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Rewrite | `includes/cli/class-hl-cli-seed-beginnings.php` | Full seeder: org, users, Cycle 1, Cycle 2, instruments, instances, coach, clean |
| Delete | `includes/cli/class-hl-cli-seed-beginnings-y2.php` | No longer needed |
| Modify | `hl-core.php` (lines 217, 295) | Remove Y2 require + register |

---

## Task 1: Scaffold — Constants, Registration, Run Method, Data Arrays

**Files:**
- Rewrite: `includes/cli/class-hl-cli-seed-beginnings.php` (full file)

This task creates the class shell with all static data arrays and the `run()` orchestrator. No DB logic yet — just the skeleton that subsequent tasks fill in.

- [ ] **Step 1: Write the class shell**

```php
<?php
/**
 * WP-CLI command: wp hl-core seed-beginnings
 *
 * Creates complete Beginnings School test dataset with two cycles:
 * - Cycle 1 (closed): ~70% full completion, historical child assessments
 * - Cycle 2 (active): fresh roster with turnover, pending child assessments
 *
 * Use --clean to remove all Beginnings data first.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_CLI_Seed_Beginnings {

    // ── Constants ────────────────────────────────────────────────────
    const PARTNERSHIP_CODE = 'BEGINNINGS-2025';
    const CYCLE_1_CODE     = 'BEGINNINGS-Y1-2025';
    const CYCLE_2_CODE     = 'BEGINNINGS-Y2-2026';
    const META_KEY         = '_hl_beginnings_seed';

    // Real LearnDash course IDs — Phase 1.
    const TC0   = 31037;
    const TC1   = 30280;
    const TC2   = 30284;
    const TC3   = 30286;
    const TC4   = 30288;
    const MC1   = 30293;
    const MC2   = 30295;

    // Phase 2.
    const TC5   = 39724;
    const TC6   = 39726;
    const TC7   = 39728;
    const TC8   = 39730;
    const MC3   = 39732;
    const MC4   = 39734;

    // Streamlined versions.
    const TC1_S = 31332;
    const TC2_S = 31333;
    const TC3_S = 31334;
    const TC4_S = 31335;
    const TC5_S = 31336;
    const TC6_S = 31337;
    const TC7_S = 31338;
    const TC8_S = 31339;
    const MC1_S = 31387;
    const MC2_S = 31388;
    const MC3_S = 31389;
    const MC4_S = 31390;

    // ── Static Data ─────────────────────────────────────────────────

    private static function get_schools() {
        return array(
            'boston'    => array(
                'name'           => 'Beginnings Boston',
                'leader_email'   => 'boston-school-leader@yopmail.com',
                'leader_first'   => 'Beth',
                'leader_last'    => 'Boston-Leader',
                'teams'          => 2,
            ),
            'florida'  => array(
                'name'           => 'Beginnings Florida',
                'leader_email'   => 'florida-school-leader@yopmail.com',
                'leader_first'   => 'Fiona',
                'leader_last'    => 'Florida-Leader',
                'teams'          => 2,
            ),
            'texas'    => array(
                'name'           => 'Beginnings Texas',
                'leader_email'   => 'texas-school-leader@yopmail.com',
                'leader_first'   => 'Tina',
                'leader_last'    => 'Texas-Leader',
                'teams'          => 1,
            ),
            'colombia' => array(
                'name'           => 'Beginnings Colombia',
                'leader_email'   => 'colombia-school-leader@yopmail.com',
                'leader_first'   => 'Carmen',
                'leader_last'    => 'Colombia-Leader',
                'teams'          => 1,
            ),
        );
    }

    private static function get_classrooms() {
        return array(
            'boston'    => array(
                array( 'name' => 'Boston - Infant Room',     'age_band' => 'infant' ),
                array( 'name' => 'Boston - Toddler Room',    'age_band' => 'toddler' ),
            ),
            'florida'  => array(
                array( 'name' => 'Florida - Preschool Room', 'age_band' => 'preschool' ),
                array( 'name' => 'Florida - Pre-K Room',     'age_band' => 'pre_k' ),
            ),
            'texas'    => array(
                array( 'name' => 'Texas - Toddler Room',    'age_band' => 'toddler' ),
                array( 'name' => 'Texas - Preschool Room',  'age_band' => 'preschool' ),
            ),
            'colombia' => array(
                array( 'name' => 'Colombia - Infant Room',    'age_band' => 'infant' ),
                array( 'name' => 'Colombia - Preschool Room', 'age_band' => 'preschool' ),
            ),
        );
    }

    private static function get_teams() {
        return array(
            array(
                'school' => 'boston', 'num' => 1,
                'mentor_email' => 'mentor-T_01-boston@yopmail.com',
                'mentor_first' => 'Marco', 'mentor_last' => 'Mentor-T01-Boston',
                'teachers' => array(
                    array( 'john_teacher-T_01-boston@yopmail.com',  'John',  'Teacher-T01-Boston' ),
                    array( 'mary_teacher-T_01-boston@yopmail.com',  'Mary',  'Teacher-T01-Boston' ),
                    array( 'steve_teacher-T_01-boston@yopmail.com', 'Steve', 'Teacher-T01-Boston' ),
                    array( 'lisa_teacher-T_01-boston@yopmail.com',  'Lisa',  'Teacher-T01-Boston' ),
                ),
            ),
            array(
                'school' => 'boston', 'num' => 2,
                'mentor_email' => 'mentor-T_02-boston@yopmail.com',
                'mentor_first' => 'Monica', 'mentor_last' => 'Mentor-T02-Boston',
                'teachers' => array(
                    array( 'carlos_teacher-T_02-boston@yopmail.com', 'Carlos', 'Teacher-T02-Boston' ),
                    array( 'ana_teacher-T_02-boston@yopmail.com',    'Ana',    'Teacher-T02-Boston' ),
                    array( 'mike_teacher-T_02-boston@yopmail.com',   'Mike',   'Teacher-T02-Boston' ),
                    array( 'sarah_teacher-T_02-boston@yopmail.com',  'Sarah',  'Teacher-T02-Boston' ),
                ),
            ),
            array(
                'school' => 'florida', 'num' => 1,
                'mentor_email' => 'mentor-T_01-florida@yopmail.com',
                'mentor_first' => 'Marta', 'mentor_last' => 'Mentor-T01-Florida',
                'teachers' => array(
                    array( 'david_teacher-T_01-florida@yopmail.com',  'David',  'Teacher-T01-Florida' ),
                    array( 'rachel_teacher-T_01-florida@yopmail.com', 'Rachel', 'Teacher-T01-Florida' ),
                    array( 'james_teacher-T_01-florida@yopmail.com',  'James',  'Teacher-T01-Florida' ),
                    array( 'emma_teacher-T_01-florida@yopmail.com',   'Emma',   'Teacher-T01-Florida' ),
                ),
            ),
            array(
                'school' => 'florida', 'num' => 2,
                'mentor_email' => 'mentor-T_02-florida@yopmail.com',
                'mentor_first' => 'Miguel', 'mentor_last' => 'Mentor-T02-Florida',
                'teachers' => array(
                    array( 'tom_teacher-T_02-florida@yopmail.com',   'Tom',   'Teacher-T02-Florida' ),
                    array( 'nina_teacher-T_02-florida@yopmail.com',  'Nina',  'Teacher-T02-Florida' ),
                    array( 'leo_teacher-T_02-florida@yopmail.com',   'Leo',   'Teacher-T02-Florida' ),
                    array( 'grace_teacher-T_02-florida@yopmail.com', 'Grace', 'Teacher-T02-Florida' ),
                ),
            ),
            array(
                'school' => 'texas', 'num' => 1,
                'mentor_email' => 'mentor-T_01-texas@yopmail.com',
                'mentor_first' => 'Manuel', 'mentor_last' => 'Mentor-T01-Texas',
                'teachers' => array(
                    array( 'ryan_teacher-T_01-texas@yopmail.com', 'Ryan', 'Teacher-T01-Texas' ),
                    array( 'mia_teacher-T_01-texas@yopmail.com',  'Mia',  'Teacher-T01-Texas' ),
                    array( 'jake_teacher-T_01-texas@yopmail.com', 'Jake', 'Teacher-T01-Texas' ),
                    array( 'lily_teacher-T_01-texas@yopmail.com', 'Lily', 'Teacher-T01-Texas' ),
                ),
            ),
            array(
                'school' => 'colombia', 'num' => 1,
                'mentor_email' => 'mentor-T_01-colombia@yopmail.com',
                'mentor_first' => 'Maria', 'mentor_last' => 'Mentor-T01-Colombia',
                'teachers' => array(
                    array( 'ben_teacher-T_01-colombia@yopmail.com',   'Ben',   'Teacher-T01-Colombia' ),
                    array( 'chloe_teacher-T_01-colombia@yopmail.com', 'Chloe', 'Teacher-T01-Colombia' ),
                    array( 'zoe_teacher-T_01-colombia@yopmail.com',   'Zoe',   'Teacher-T01-Colombia' ),
                    array( 'diego_teacher-T_01-colombia@yopmail.com', 'Diego', 'Teacher-T01-Colombia' ),
                ),
            ),
        );
    }

    /**
     * Emails of people who leave after Cycle 1 (not enrolled in Cycle 2).
     * Lisa is also here as teacher — she re-enrolls as mentor.
     */
    private static function get_cycle2_departures() {
        return array(
            'lisa_teacher-T_01-boston@yopmail.com',   // promoted to mentor
            'emma_teacher-T_01-florida@yopmail.com',  // left
            'leo_teacher-T_02-florida@yopmail.com',   // left
            'lily_teacher-T_01-texas@yopmail.com',    // left
            'mentor-T_01-colombia@yopmail.com',       // Maria left
        );
    }

    /**
     * Cycle 1 stragglers (~30% of enrollments, partial completion).
     */
    private static function get_cycle1_stragglers() {
        return array(
            // Teachers (7):
            'lisa_teacher-T_01-boston@yopmail.com',
            'sarah_teacher-T_02-boston@yopmail.com',
            'emma_teacher-T_01-florida@yopmail.com',
            'leo_teacher-T_02-florida@yopmail.com',
            'grace_teacher-T_02-florida@yopmail.com',
            'lily_teacher-T_01-texas@yopmail.com',
            'diego_teacher-T_01-colombia@yopmail.com',
            // Mentors (2):
            'mentor-T_01-colombia@yopmail.com',   // Maria
            'mentor-T_02-florida@yopmail.com',    // Miguel
            // School leaders (1):
            'texas-school-leader@yopmail.com',    // Tina
        );
    }

    /**
     * Child name arrays (deterministic).
     */
    private static function get_child_first_names() {
        return array( 'Emma', 'Liam', 'Olivia', 'Noah', 'Ava', 'Elijah', 'Sophia', 'James', 'Isabella', 'Lucas' );
    }

    private static function get_child_last_names() {
        return array( 'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez' );
    }

    // ── Registration ────────────────────────────────────────────────

    public static function register() {
        WP_CLI::add_command( 'hl-core seed-beginnings', array( new self(), 'run' ) );
    }

    /**
     * Seed Beginnings School test data (Cycle 1 + Cycle 2).
     *
     * ## OPTIONS
     *
     * [--clean]
     * : Remove all Beginnings data before seeding.
     *
     * ## EXAMPLES
     *
     *     wp hl-core seed-beginnings
     *     wp hl-core seed-beginnings --clean
     */
    public function run( $args, $assoc_args ) {
        if ( isset( $assoc_args['clean'] ) ) {
            $this->clean();
            WP_CLI::success( 'Beginnings data cleaned.' );
            return;
        }

        if ( $this->data_exists() ) {
            WP_CLI::warning( 'Beginnings data already exists. Run with --clean first to reseed.' );
            return;
        }

        WP_CLI::line( '' );
        WP_CLI::line( '=== HL Core Beginnings School Seeder ===' );
        WP_CLI::line( '' );

        // Phase A: Shared infrastructure.
        $partnership_id = $this->seed_partnership();
        $org            = $this->seed_orgunits();
        $classrooms     = $this->seed_classrooms( $org['schools'] );
        $this->seed_children( $classrooms );
        $users          = $this->seed_users();
        $instruments    = $this->seed_child_instruments();

        // Phase B: Cycle 1 (closed).
        WP_CLI::line( '' );
        WP_CLI::line( '--- Cycle 1 (closed) ---' );
        $c1 = $this->seed_cycle_1( $partnership_id, $org, $users, $classrooms, $instruments );

        // Phase C: Cycle 2 (active).
        WP_CLI::line( '' );
        WP_CLI::line( '--- Cycle 2 (active) ---' );
        $c2 = $this->seed_cycle_2( $partnership_id, $org, $users, $classrooms, $instruments );

        // Phase D: Coach assignment.
        $this->seed_coach_assignment( $c2['cycle_id'], $org['schools']['boston'] );

        // Summary.
        $this->print_summary( $partnership_id, $org, $classrooms, $users, $c1, $c2 );
    }

    // ... (methods implemented in subsequent tasks)
}
```

The `run()` method is the orchestrator. Each `seed_*` method will be implemented in the following tasks. The `seed_cycle_1()` and `seed_cycle_2()` methods are high-level wrappers that call sub-methods for enrollments, teams, pathways, component states, and child assessment instances within each cycle.

- [ ] **Step 2: Commit scaffold**

```bash
git add includes/cli/class-hl-cli-seed-beginnings.php
git commit -m "refactor: scaffold Beginnings seeder with dual-cycle orchestrator"
```

---

## Task 2: Shared Infrastructure — Partnership, Org, Classrooms, Children, Users, Instruments

**Files:**
- Modify: `includes/cli/class-hl-cli-seed-beginnings.php`

Implements all the `seed_*` methods that create shared data (used by both cycles).

- [ ] **Step 1: Implement `data_exists()` — checks both cycle codes**

```php
private function data_exists() {
    global $wpdb;
    $t = $wpdb->prefix;
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT cycle_id FROM {$t}hl_cycle WHERE cycle_code IN (%s, %s) LIMIT 1",
        self::CYCLE_1_CODE,
        self::CYCLE_2_CODE
    ) );
}
```

- [ ] **Step 2: Implement `seed_partnership()`**

Same as current seeder — find or create Partnership with `BEGINNINGS-2025` code.

```php
private function seed_partnership() {
    global $wpdb;
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT partnership_id FROM {$wpdb->prefix}hl_partnership WHERE partnership_code = %s LIMIT 1",
        self::PARTNERSHIP_CODE
    ) );
    if ( $existing ) {
        WP_CLI::log( "  [A1] Partnership exists: id={$existing}" );
        return (int) $existing;
    }
    $wpdb->insert( $wpdb->prefix . 'hl_partnership', array(
        'partnership_name' => 'Beginnings School - 2025-2026',
        'partnership_code' => self::PARTNERSHIP_CODE,
        'description'      => 'Test partnership for end-to-end demo and testing.',
        'status'           => 'active',
    ) );
    $id = $wpdb->insert_id;
    WP_CLI::log( "  [A1] Partnership created: id={$id}" );
    return $id;
}
```

- [ ] **Step 3: Implement `seed_orgunits()`**

Same as current — creates district + 4 schools via `HL_OrgUnit_Repository`.

```php
private function seed_orgunits() {
    $repo = new HL_OrgUnit_Repository();
    $district_id = $repo->create( array(
        'name'         => 'Beginnings School District',
        'orgunit_type' => 'district',
    ) );
    $schools = array();
    foreach ( self::get_schools() as $key => $def ) {
        $schools[ $key ] = $repo->create( array(
            'name'              => $def['name'],
            'orgunit_type'      => 'school',
            'parent_orgunit_id' => $district_id,
        ) );
    }
    WP_CLI::log( '  [A2] Org units: district=' . $district_id . ', schools=' . implode( ',', $schools ) );
    return array( 'district_id' => $district_id, 'schools' => $schools );
}
```

- [ ] **Step 4: Implement `seed_classrooms()`**

2 classrooms per school, 8 total. Returns array keyed by school_key with classroom info.

```php
private function seed_classrooms( $schools ) {
    $svc = new HL_Classroom_Service();
    $all = array();
    foreach ( self::get_classrooms() as $school_key => $defs ) {
        $school_id = $schools[ $school_key ];
        foreach ( $defs as $def ) {
            $id = $svc->create_classroom( array(
                'classroom_name' => $def['name'],
                'school_id'      => $school_id,
                'age_band'       => $def['age_band'],
            ) );
            if ( ! is_wp_error( $id ) ) {
                $all[] = array(
                    'classroom_id' => $id,
                    'school_key'   => $school_key,
                    'school_id'    => $school_id,
                    'age_band'     => $def['age_band'],
                );
            }
        }
    }
    WP_CLI::log( '  [A3] Classrooms created: ' . count( $all ) );
    return $all;
}
```

- [ ] **Step 5: Implement `seed_children()` — deterministic, 5 per classroom**

```php
private function seed_children( $classrooms ) {
    $repo  = new HL_Child_Repository();
    $svc   = new HL_Classroom_Service();
    $fns   = self::get_child_first_names();
    $lns   = self::get_child_last_names();
    $total = 0;

    foreach ( $classrooms as $c_idx => $cr ) {
        for ( $i = 0; $i < 5; $i++ ) {
            $first = $fns[ ( $c_idx * 5 + $i ) % 10 ];
            $last  = $lns[ ( $c_idx * 5 + $i + 3 ) % 10 ];
            $age   = $this->get_age_midpoint( $cr['age_band'] );
            // Deterministic DOB: offset each child by (i * 73) days for spread.
            $dob   = gmdate( 'Y-m-d', strtotime( "-{$age} years -" . ( $i * 73 ) . ' days' ) );

            $child_id = $repo->create( array(
                'first_name' => $first,
                'last_name'  => $last,
                'dob'        => $dob,
                'school_id'  => $cr['school_id'],
            ) );
            if ( $child_id ) {
                $svc->assign_child_to_classroom( $child_id, $cr['classroom_id'], 'Beginnings seed' );
                $total++;
            }
        }
    }
    WP_CLI::log( "  [A4] Children created: {$total}" );
    return $total;
}

private function get_age_midpoint( $band ) {
    switch ( $band ) {
        case 'infant':    return 1;
        case 'toddler':   return 2;
        case 'preschool': return 3;
        case 'pre_k':     return 5;
        default:          return 3;
    }
}
```

- [ ] **Step 6: Implement `seed_users()` — all 36 WP users**

Same pattern as current seeder's `create_user()` helper, but includes Grace (T02-Florida), Diego (T01-Colombia), and Natalia (new mentor). Returns structured array with `district_leader`, `school_leaders`, `mentors`, `teachers`, plus a `by_email` lookup.

```php
private function seed_users() {
    $users = array(
        'district_leader' => null,
        'school_leaders'  => array(),
        'mentors'         => array(),
        'teachers'        => array(),
        'by_email'        => array(), // email => user data
        'count'           => 0,
    );

    // District leader.
    $dl = $this->create_user( 'district-lead-beginnings@yopmail.com', 'Diana', 'District-Lead-Beginnings', 'subscriber' );
    $users['district_leader'] = $dl;
    $users['by_email'][ $dl['email'] ] = $dl;
    $users['count']++;

    // School leaders.
    foreach ( self::get_schools() as $key => $def ) {
        $u = $this->create_user( $def['leader_email'], $def['leader_first'], $def['leader_last'], 'subscriber' );
        $users['school_leaders'][ $key ] = $u;
        $users['by_email'][ $u['email'] ] = $u;
        $users['count']++;
    }

    // Mentors + Teachers per team.
    foreach ( self::get_teams() as $t_idx => $team ) {
        $m = $this->create_user( $team['mentor_email'], $team['mentor_first'], $team['mentor_last'], 'subscriber' );
        $users['mentors'][ $t_idx ] = $m;
        $users['by_email'][ $m['email'] ] = $m;
        $users['count']++;

        $users['teachers'][ $t_idx ] = array();
        foreach ( $team['teachers'] as $t ) {
            $u = $this->create_user( $t[0], $t[1], $t[2], 'subscriber' );
            $users['teachers'][ $t_idx ][] = $u;
            $users['by_email'][ $u['email'] ] = $u;
            $users['count']++;
        }
    }

    // Natalia — Cycle 2 only mentor.
    $nat = $this->create_user( 'new-hire-mentor-boston@yopmail.com', 'Natalia', 'NewHire-Mentor', 'subscriber' );
    $users['natalia'] = $nat;
    $users['by_email'][ $nat['email'] ] = $nat;
    $users['count']++;

    WP_CLI::log( "  [A5] WP users created: {$users['count']}" );
    return $users;
}

private function create_user( $email, $first_name, $last_name, $role ) {
    $existing = get_user_by( 'email', $email );
    if ( $existing ) {
        update_user_meta( $existing->ID, self::META_KEY, 'found' );
        return array( 'user_id' => $existing->ID, 'email' => $email, 'first_name' => $first_name, 'last_name' => $last_name );
    }
    $username = explode( '@', $email )[0];
    $user_id  = wp_insert_user( array(
        'user_login'   => $username,
        'user_email'   => $email,
        'user_pass'    => $email,
        'display_name' => "{$first_name} {$last_name}",
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'role'         => $role,
    ) );
    if ( is_wp_error( $user_id ) ) {
        WP_CLI::warning( "Could not create user {$email}: " . $user_id->get_error_message() );
        return array( 'user_id' => 0, 'email' => $email, 'first_name' => $first_name, 'last_name' => $last_name );
    }
    update_user_meta( $user_id, self::META_KEY, 'created' );
    return array( 'user_id' => $user_id, 'email' => $email, 'first_name' => $first_name, 'last_name' => $last_name );
}
```

- [ ] **Step 7: Implement `seed_child_instruments()` — 4 instruments**

```php
private function seed_child_instruments() {
    global $wpdb;
    $t = $wpdb->prefix;

    $age_bands   = array( 'infant', 'toddler', 'preschool', 'pre_k' );
    $instruments = array();

    foreach ( $age_bands as $ab ) {
        $label = str_replace( '_', '-', ucfirst( $ab ) );
        if ( $ab === 'pre_k' ) $label = 'Pre-K';
        $name = "Beginnings {$label} Assessment";

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT instrument_id FROM {$t}hl_instrument WHERE name = %s", $name
        ) );
        if ( $existing ) {
            $instruments[ $ab ] = (int) $existing;
            WP_CLI::log( "  [A6] Instrument exists: {$name} (ID {$existing})" );
            continue;
        }

        $questions = array( array(
            'key'            => 'q1',
            'prompt'         => 'Rate this child\'s social-emotional development on a scale of 1-5.',
            'type'           => 'likert',
            'allowed_values' => array( '1', '2', '3', '4', '5' ),
            'required'       => true,
        ) );

        $wpdb->insert( $t . 'hl_instrument', array(
            'instrument_uuid' => wp_generate_uuid4(),
            'name'            => $name,
            'instrument_type' => "children_{$ab}",
            'version'         => '1.0',
            'questions'       => wp_json_encode( $questions ),
        ) );
        $instruments[ $ab ] = (int) $wpdb->insert_id;
        WP_CLI::log( "  [A6] Instrument created: {$name} (ID {$instruments[$ab]})" );
    }

    return $instruments;
}
```

- [ ] **Step 8: Commit shared infrastructure**

```bash
git add includes/cli/class-hl-cli-seed-beginnings.php
git commit -m "feat: implement shared infrastructure for Beginnings seeder"
```

---

## Task 3: Cycle 1 — Enrollments, Teams, Pathways, Assignments

**Files:**
- Modify: `includes/cli/class-hl-cli-seed-beginnings.php`

Implements `seed_cycle_1()` and its sub-methods for the closed cycle.

- [ ] **Step 1: Implement `seed_cycle_1()` orchestrator**

```php
private function seed_cycle_1( $partnership_id, $org, $users, $classrooms, $instruments ) {
    global $wpdb;
    $t = $wpdb->prefix;

    // Create cycle (closed).
    $repo     = new HL_Cycle_Repository();
    $cycle_id = $repo->create( array(
        'cycle_name'     => 'Beginnings - Cycle 1 (2025)',
        'cycle_code'     => self::CYCLE_1_CODE,
        'partnership_id' => $partnership_id,
        'cycle_type'     => 'program',
        'district_id'    => $org['district_id'],
        'status'         => 'closed',
        'start_date'     => '2025-01-15',
        'end_date'       => '2025-09-30',
    ) );
    foreach ( $org['schools'] as $school_id ) {
        $wpdb->insert( $t . 'hl_cycle_school', array(
            'cycle_id' => $cycle_id, 'school_id' => $school_id,
        ) );
    }
    WP_CLI::log( "  [B1] Cycle 1 created: id={$cycle_id}, status=closed" );

    // Enrollments (all 35 Cycle 1 users — does NOT include Natalia).
    $enrollments = $this->seed_enrollments_c1( $cycle_id, $org, $users );

    // Teams.
    $this->seed_teams( $cycle_id, $org['schools'], $enrollments, self::get_teams() );

    // Pathways (Phase 1 only).
    $pathways = $this->seed_pathways_c1( $cycle_id );

    // Assign pathways.
    $this->assign_pathways_c1( $enrollments, $pathways );

    // Teaching assignments.
    $this->seed_teaching_assignments( $enrollments, $classrooms, $org['schools'] );

    // Freeze child age groups.
    $frozen = HL_Child_Snapshot_Service::freeze_age_groups( $cycle_id );
    WP_CLI::log( "  [B7] Frozen age group snapshots: {$frozen}" );

    // Component states (~70% full completion).
    $this->seed_component_states_c1( $enrollments, $pathways );

    // Completion rollups.
    $this->seed_rollups( $enrollments, $cycle_id );

    // Child assessment instances (historical, submitted).
    $this->seed_child_assessments_c1( $cycle_id, $enrollments, $classrooms, $instruments );

    return array( 'cycle_id' => $cycle_id, 'enrollments' => $enrollments, 'pathways' => $pathways );
}
```

- [ ] **Step 2: Implement `seed_enrollments_c1()`**

Enrolls all 35 users from Cycle 1 (all from get_teams() plus school leaders and district leader). Same logic as current seeder. Returns structured array with `all`, `district_leader`, `school_leaders`, `mentors`, `teachers`.

```php
private function seed_enrollments_c1( $cycle_id, $org, $users ) {
    $repo        = new HL_Enrollment_Repository();
    $enrollments = array(
        'district_leader' => null,
        'school_leaders'  => array(),
        'mentors'         => array(),
        'teachers'        => array(),
        'all'             => array(),
        'by_email'        => array(),
    );
    $district_id = $org['district_id'];
    $schools     = $org['schools'];
    $teams_def   = self::get_teams();

    // District leader.
    $uid = $users['district_leader']['user_id'];
    if ( $uid ) {
        $eid = $repo->create( array(
            'user_id' => $uid, 'cycle_id' => $cycle_id,
            'roles' => array( 'district_leader' ), 'status' => 'active',
            'district_id' => $district_id,
        ) );
        $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'district_leader',
                     'email' => $users['district_leader']['email'] );
        $enrollments['district_leader'] = $e;
        $enrollments['all'][]           = $e;
        $enrollments['by_email'][ $e['email'] ] = $e;
    }

    // School leaders.
    foreach ( self::get_schools() as $key => $def ) {
        $uid = $users['school_leaders'][ $key ]['user_id'];
        if ( ! $uid ) continue;
        $eid = $repo->create( array(
            'user_id' => $uid, 'cycle_id' => $cycle_id,
            'roles' => array( 'school_leader' ), 'status' => 'active',
            'school_id' => $schools[ $key ], 'district_id' => $district_id,
        ) );
        $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'school_leader',
                     'school_key' => $key, 'email' => $def['leader_email'] );
        $enrollments['school_leaders'][ $key ] = $e;
        $enrollments['all'][]                  = $e;
        $enrollments['by_email'][ $e['email'] ] = $e;
    }

    // Mentors + Teachers.
    foreach ( $teams_def as $t_idx => $team ) {
        $school_id = $schools[ $team['school'] ];

        $uid = $users['mentors'][ $t_idx ]['user_id'];
        if ( $uid ) {
            $eid = $repo->create( array(
                'user_id' => $uid, 'cycle_id' => $cycle_id,
                'roles' => array( 'mentor' ), 'status' => 'active',
                'school_id' => $school_id, 'district_id' => $district_id,
            ) );
            $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'mentor',
                         'school_key' => $team['school'], 'team_idx' => $t_idx,
                         'email' => $team['mentor_email'] );
            $enrollments['mentors'][ $t_idx ] = $e;
            $enrollments['all'][]             = $e;
            $enrollments['by_email'][ $e['email'] ] = $e;
        }

        $enrollments['teachers'][ $t_idx ] = array();
        foreach ( $users['teachers'][ $t_idx ] as $t_data ) {
            $uid = $t_data['user_id'];
            if ( ! $uid ) continue;
            $eid = $repo->create( array(
                'user_id' => $uid, 'cycle_id' => $cycle_id,
                'roles' => array( 'teacher' ), 'status' => 'active',
                'school_id' => $school_id, 'district_id' => $district_id,
            ) );
            $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'teacher',
                         'school_key' => $team['school'], 'team_idx' => $t_idx,
                         'email' => $t_data['email'] );
            $enrollments['teachers'][ $t_idx ][] = $e;
            $enrollments['all'][]                = $e;
            $enrollments['by_email'][ $e['email'] ] = $e;
        }
    }

    WP_CLI::log( '  [B2] Cycle 1 enrollments: ' . count( $enrollments['all'] ) );
    return $enrollments;
}
```

- [ ] **Step 3: Implement shared `seed_teams()` — reusable for both cycles**

```php
private function seed_teams( $cycle_id, $schools, $enrollments, $teams_def ) {
    $svc   = new HL_Team_Service();
    $count = 0;

    foreach ( $teams_def as $t_idx => $team ) {
        $school_id = $schools[ $team['school'] ];
        $team_name = 'Team ' . str_pad( $team['num'], 2, '0', STR_PAD_LEFT )
                   . ' - ' . ucfirst( $team['school'] ) . ' - Beginnings';

        $team_id = $svc->create_team( array(
            'team_name' => $team_name,
            'cycle_id'  => $cycle_id,
            'school_id' => $school_id,
        ) );
        if ( is_wp_error( $team_id ) ) {
            WP_CLI::warning( 'Team error: ' . $team_id->get_error_message() );
            continue;
        }

        // Add mentor.
        if ( isset( $enrollments['mentors'][ $t_idx ] ) ) {
            $svc->add_member( $team_id, $enrollments['mentors'][ $t_idx ]['enrollment_id'], 'mentor' );
        }

        // Add teachers.
        if ( isset( $enrollments['teachers'][ $t_idx ] ) ) {
            foreach ( $enrollments['teachers'][ $t_idx ] as $t ) {
                $svc->add_member( $team_id, $t['enrollment_id'], 'member' );
            }
        }

        // Add extra members (e.g., Natalia on T01-Boston in Cycle 2).
        if ( isset( $enrollments['extra_team_members'][ $t_idx ] ) ) {
            foreach ( $enrollments['extra_team_members'][ $t_idx ] as $extra ) {
                $svc->add_member( $team_id, $extra['enrollment_id'], 'member' );
            }
        }

        $count++;
    }

    WP_CLI::log( "  Teams created: {$count}" );
}
```

- [ ] **Step 4: Implement `seed_pathways_c1()` — Phase 1 pathways**

Copy the 3 pathway creation methods from the current seeder (Teacher Phase 1, Mentor Phase 1, Streamlined Phase 1). These are identical to the existing code. Use the shared `cmp()` and `add_prereq()` helpers.

```php
private function seed_pathways_c1( $cycle_id ) {
    $svc      = new HL_Pathway_Service();
    $pathways = array();

    $pathways['teacher']     = $this->create_teacher_phase1( $svc, $cycle_id );
    $pathways['mentor']      = $this->create_mentor_phase1( $svc, $cycle_id );
    $pathways['streamlined'] = $this->create_streamlined_phase1( $svc, $cycle_id );

    WP_CLI::log( '  [B4] Cycle 1 pathways: ' . count( $pathways ) );
    return $pathways;
}
```

Bring over `cmp()`, `add_prereq()`, `create_teacher_phase1()`, `create_mentor_phase1()`, `create_streamlined_phase1()` verbatim from the current seeder (lines 656-787).

- [ ] **Step 5: Implement `assign_pathways_c1()`**

Same as current seeder — Teachers→Teacher Phase 1, Mentors→Mentor Phase 1, Leaders→Streamlined Phase 1.

```php
private function assign_pathways_c1( $enrollments, $pathways ) {
    global $wpdb;
    $t     = $wpdb->prefix;
    $count = 0;

    foreach ( $enrollments['teachers'] as $team_teachers ) {
        foreach ( $team_teachers as $e ) {
            $wpdb->update( $t . 'hl_enrollment',
                array( 'assigned_pathway_id' => $pathways['teacher']['pathway_id'] ),
                array( 'enrollment_id' => $e['enrollment_id'] ) );
            $count++;
        }
    }
    foreach ( $enrollments['mentors'] as $e ) {
        $wpdb->update( $t . 'hl_enrollment',
            array( 'assigned_pathway_id' => $pathways['mentor']['pathway_id'] ),
            array( 'enrollment_id' => $e['enrollment_id'] ) );
        $count++;
    }
    foreach ( $enrollments['school_leaders'] as $e ) {
        $wpdb->update( $t . 'hl_enrollment',
            array( 'assigned_pathway_id' => $pathways['streamlined']['pathway_id'] ),
            array( 'enrollment_id' => $e['enrollment_id'] ) );
        $count++;
    }
    if ( $enrollments['district_leader'] ) {
        $wpdb->update( $t . 'hl_enrollment',
            array( 'assigned_pathway_id' => $pathways['streamlined']['pathway_id'] ),
            array( 'enrollment_id' => $enrollments['district_leader']['enrollment_id'] ) );
        $count++;
    }
    WP_CLI::log( "  [B5] Pathway assignments: {$count}" );
}
```

- [ ] **Step 6: Implement shared `seed_teaching_assignments()`**

Same as current seeder — round-robin teachers across school classrooms.

```php
private function seed_teaching_assignments( $enrollments, $classrooms, $schools ) {
    remove_all_actions( 'hl_core_teaching_assignment_changed' );
    $svc   = new HL_Classroom_Service();
    $count = 0;

    $cr_by_school = array();
    foreach ( $classrooms as $cr ) {
        $cr_by_school[ $cr['school_key'] ][] = $cr;
    }

    $teams_def = self::get_teams();
    foreach ( $teams_def as $t_idx => $team ) {
        if ( empty( $enrollments['teachers'][ $t_idx ] ) ) continue;
        $school_crs = isset( $cr_by_school[ $team['school'] ] ) ? $cr_by_school[ $team['school'] ] : array();
        if ( empty( $school_crs ) ) continue;

        foreach ( $enrollments['teachers'][ $t_idx ] as $idx => $t ) {
            $cr = $school_crs[ $idx % count( $school_crs ) ];
            $result = $svc->create_teaching_assignment( array(
                'enrollment_id'   => $t['enrollment_id'],
                'classroom_id'    => $cr['classroom_id'],
                'is_lead_teacher' => ( $idx === 0 ) ? 1 : 0,
            ) );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            }
        }
    }

    WP_CLI::log( "  Teaching assignments: {$count}" );
}
```

- [ ] **Step 7: Commit Cycle 1 structure**

```bash
git add includes/cli/class-hl-cli-seed-beginnings.php
git commit -m "feat: Cycle 1 enrollments, teams, pathways, teaching assignments"
```

---

## Task 4: Cycle 1 — Component States, Rollups, Child Assessments

**Files:**
- Modify: `includes/cli/class-hl-cli-seed-beginnings.php`

- [ ] **Step 1: Implement `seed_component_states_c1()` — 70% completion**

Uses the `get_cycle1_stragglers()` email list to determine who gets partial completion. Non-stragglers get 100%, stragglers get 50-70% (deterministic based on enrollment position).

```php
private function seed_component_states_c1( $enrollments, $pathways ) {
    global $wpdb;
    $t          = $wpdb->prefix;
    $count      = 0;
    $stragglers = self::get_cycle1_stragglers();
    $base_date  = '2025-02-01';

    foreach ( $enrollments['all'] as $e_idx => $e ) {
        $eid  = $e['enrollment_id'];
        $role = $e['role'];

        $pw_key = 'teacher';
        if ( $role === 'mentor' ) $pw_key = 'mentor';
        if ( in_array( $role, array( 'school_leader', 'district_leader' ), true ) ) $pw_key = 'streamlined';

        $pw        = $pathways[ $pw_key ];
        $comp_ids  = $pw['component_ids'];
        $total     = count( $comp_ids );
        $email     = isset( $e['email'] ) ? $e['email'] : '';
        $is_strag  = in_array( $email, $stragglers, true );

        // Deterministic cutoff for stragglers: 50-70% based on position in enrollment list.
        $cutoff = $total;
        if ( $is_strag ) {
            $pct    = 50 + ( ( $e_idx % 3 ) * 10 ); // 50%, 60%, or 70%
            $cutoff = (int) round( $total * $pct / 100 );
        }

        for ( $i = 0; $i < $total; $i++ ) {
            $comp_id = $comp_ids[ $i ];
            if ( $i < $cutoff ) {
                $days_offset  = (int) round( ( $i / max( $total, 1 ) ) * 200 );
                $completed_at = gmdate( 'Y-m-d H:i:s', strtotime( $base_date . " +{$days_offset} days" ) );
                $wpdb->insert( $t . 'hl_component_state', array(
                    'enrollment_id'     => $eid,
                    'component_id'      => $comp_id,
                    'completion_status'  => 'complete',
                    'completion_percent' => 100,
                    'completed_at'      => $completed_at,
                    'last_computed_at'  => $completed_at,
                ) );
            } else {
                $wpdb->insert( $t . 'hl_component_state', array(
                    'enrollment_id'     => $eid,
                    'component_id'      => $comp_id,
                    'completion_status'  => 'not_started',
                    'completion_percent' => 0,
                    'last_computed_at'  => current_time( 'mysql' ),
                ) );
            }
            $count++;
        }
    }

    $strag_count = count( array_filter( $enrollments['all'], function( $e ) use ( $stragglers ) {
        return in_array( $e['email'] ?? '', $stragglers, true );
    } ) );
    WP_CLI::log( "  [B8] Component states: {$count} (stragglers: {$strag_count})" );
}
```

- [ ] **Step 2: Implement `seed_rollups()`**

Same as current seeder — creates completion rollup for fully completed enrollments.

```php
private function seed_rollups( $enrollments, $cycle_id ) {
    global $wpdb;
    $t     = $wpdb->prefix;
    $count = 0;

    foreach ( $enrollments['all'] as $e ) {
        $eid       = $e['enrollment_id'];
        $total     = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t}hl_component_state WHERE enrollment_id = %d", $eid ) );
        $completed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t}hl_component_state WHERE enrollment_id = %d AND completion_status = 'complete'", $eid ) );

        if ( $total > 0 && $completed === $total ) {
            $wpdb->insert( $t . 'hl_completion_rollup', array(
                'enrollment_id'              => $eid,
                'cycle_id'                   => $cycle_id,
                'pathway_completion_percent'  => 100.00,
                'cycle_completion_percent'    => 100.00,
                'last_computed_at'           => current_time( 'mysql' ),
            ) );
            $count++;
        }
    }

    WP_CLI::log( "  Completion rollups: {$count}" );
}
```

- [ ] **Step 3: Implement `seed_child_assessments_c1()` — historical, submitted**

Creates Pre + Post instances for every teacher with a teaching assignment. Childrows with deterministic scores.

```php
private function seed_child_assessments_c1( $cycle_id, $enrollments, $classrooms, $instruments ) {
    global $wpdb;
    $t = $wpdb->prefix;

    // Build classroom lookup: classroom_id => classroom data + child_ids.
    $cr_lookup = array();
    foreach ( $classrooms as $cr ) {
        $cr_lookup[ $cr['classroom_id'] ] = $cr;
        $cr_lookup[ $cr['classroom_id'] ]['child_ids'] = $wpdb->get_col( $wpdb->prepare(
            "SELECT child_id FROM {$t}hl_child_classroom_current WHERE classroom_id = %d",
            $cr['classroom_id']
        ) );
    }

    // Get teaching assignments for Cycle 1 enrollments.
    $all_eids = wp_list_pluck( $enrollments['all'], 'enrollment_id' );
    if ( empty( $all_eids ) ) return;

    $in = implode( ',', array_map( 'intval', $all_eids ) );
    $assignments = $wpdb->get_results(
        "SELECT enrollment_id, classroom_id FROM {$t}hl_teaching_assignment WHERE enrollment_id IN ({$in})"
    );

    $instances = 0;
    $childrows = 0;

    foreach ( $assignments as $asgn ) {
        $eid = (int) $asgn->enrollment_id;
        $cid = (int) $asgn->classroom_id;
        $cr  = isset( $cr_lookup[ $cid ] ) ? $cr_lookup[ $cid ] : null;
        if ( ! $cr ) continue;

        $instrument_id = isset( $instruments[ $cr['age_band'] ] ) ? $instruments[ $cr['age_band'] ] : null;

        foreach ( array( 'pre', 'post' ) as $phase ) {
            $date = $phase === 'pre' ? '2025-02-15' : '2025-08-20';

            $wpdb->insert( $t . 'hl_child_assessment_instance', array(
                'instance_uuid'       => wp_generate_uuid4(),
                'cycle_id'            => $cycle_id,
                'enrollment_id'       => $eid,
                'classroom_id'        => $cid,
                'school_id'           => $cr['school_id'],
                'phase'               => $phase,
                'instrument_age_band' => $cr['age_band'],
                'instrument_id'       => $instrument_id,
                'status'              => 'submitted',
                'submitted_at'        => $date,
                'created_at'          => $date,
            ) );
            $instance_id = (int) $wpdb->insert_id;
            $instances++;

            // Childrows with deterministic scores.
            foreach ( $cr['child_ids'] as $c_idx => $child_id ) {
                $score = ( ( $c_idx + $eid ) % 5 ) + 1; // 1-5 deterministic
                $wpdb->insert( $t . 'hl_child_assessment_childrow', array(
                    'instance_id'      => $instance_id,
                    'child_id'         => (int) $child_id,
                    'frozen_age_group' => $cr['age_band'],
                    'instrument_id'    => $instrument_id,
                    'answers_json'     => wp_json_encode( array( 'q1' => (string) $score ) ),
                    'status'           => 'active',
                ) );
                $childrows++;
            }
        }
    }

    WP_CLI::log( "  [B9] Child assessment instances: {$instances}, childrows: {$childrows}" );
}
```

- [ ] **Step 4: Commit Cycle 1 completion data**

```bash
git add includes/cli/class-hl-cli-seed-beginnings.php
git commit -m "feat: Cycle 1 component states (70% completion) + child assessments"
```

---

## Task 5: Cycle 2 — Enrollments, Teams, Pathways, Assignments, Child Assessments

**Files:**
- Modify: `includes/cli/class-hl-cli-seed-beginnings.php`

This is the most complex task — implements `seed_cycle_2()` with roster changes, 8 pathways, and pending child assessment instances.

- [ ] **Step 1: Implement `seed_cycle_2()` orchestrator**

```php
private function seed_cycle_2( $partnership_id, $org, $users, $classrooms, $instruments ) {
    global $wpdb;
    $t = $wpdb->prefix;

    // Create cycle (active).
    $repo     = new HL_Cycle_Repository();
    $cycle_id = $repo->create( array(
        'cycle_name'     => 'Beginnings - Cycle 2 (2026)',
        'cycle_code'     => self::CYCLE_2_CODE,
        'partnership_id' => $partnership_id,
        'cycle_type'     => 'program',
        'district_id'    => $org['district_id'],
        'status'         => 'active',
        'start_date'     => '2026-01-15',
        'end_date'       => '2026-09-30',
    ) );
    foreach ( $org['schools'] as $school_id ) {
        $wpdb->insert( $t . 'hl_cycle_school', array(
            'cycle_id' => $cycle_id, 'school_id' => $school_id,
        ) );
    }
    WP_CLI::log( "  [C1] Cycle 2 created: id={$cycle_id}, status=active" );

    // Enrollments (with roster changes).
    $enrollments = $this->seed_enrollments_c2( $cycle_id, $org, $users );

    // Teams (same 6 teams, different composition).
    $this->seed_teams_c2( $cycle_id, $org['schools'], $enrollments );

    // Pathways (8 total).
    $pathways = $this->seed_pathways_c2( $cycle_id );

    // Assign pathways.
    $this->assign_pathways_c2( $enrollments, $pathways );

    // Teaching assignments (for Cycle 2 teachers).
    $this->seed_teaching_assignments( $enrollments, $classrooms, $org['schools'] );

    // Freeze child age groups for Cycle 2.
    $frozen = HL_Child_Snapshot_Service::freeze_age_groups( $cycle_id );
    WP_CLI::log( "  [C7] Frozen age group snapshots: {$frozen}" );

    // Child assessment instances (pending, Pre only).
    $this->seed_child_assessments_c2( $cycle_id, $enrollments, $classrooms, $instruments );

    return array( 'cycle_id' => $cycle_id, 'enrollments' => $enrollments, 'pathways' => $pathways );
}
```

- [ ] **Step 2: Implement `seed_enrollments_c2()` — roster with turnover**

Key differences from Cycle 1:
- Skip departed teachers (Lisa-as-teacher, Emma, Leo, Lily) and Maria (mentor)
- Lisa re-enrolled as mentor for Colombia
- Natalia enrolled as mentor at Boston
- Remaining teachers/mentors/leaders continue

```php
private function seed_enrollments_c2( $cycle_id, $org, $users ) {
    $repo        = new HL_Enrollment_Repository();
    $enrollments = array(
        'district_leader'     => null,
        'school_leaders'      => array(),
        'mentors'             => array(),  // keyed by team_idx
        'teachers'            => array(),  // keyed by team_idx
        'lisa_mentor'         => null,     // Lisa promoted
        'natalia_mentor'      => null,     // Natalia new hire
        'extra_team_members'  => array(),  // for seed_teams() — Natalia on T01-Boston
        'all'                 => array(),
        'by_email'            => array(),
    );
    $departures  = self::get_cycle2_departures();
    $district_id = $org['district_id'];
    $schools     = $org['schools'];
    $teams_def   = self::get_teams();

    // District leader → Streamlined Phase 2.
    $uid = $users['district_leader']['user_id'];
    if ( $uid ) {
        $eid = $repo->create( array(
            'user_id' => $uid, 'cycle_id' => $cycle_id,
            'roles' => array( 'district_leader' ), 'status' => 'active',
            'district_id' => $district_id,
        ) );
        $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'district_leader',
                     'email' => $users['district_leader']['email'] );
        $enrollments['district_leader'] = $e;
        $enrollments['all'][]           = $e;
        $enrollments['by_email'][ $e['email'] ] = $e;
    }

    // School leaders → Streamlined Phase 2 (all return).
    foreach ( self::get_schools() as $key => $def ) {
        $uid = $users['school_leaders'][ $key ]['user_id'];
        if ( ! $uid ) continue;
        $eid = $repo->create( array(
            'user_id' => $uid, 'cycle_id' => $cycle_id,
            'roles' => array( 'school_leader' ), 'status' => 'active',
            'school_id' => $schools[ $key ], 'district_id' => $district_id,
        ) );
        $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'school_leader',
                     'school_key' => $key, 'email' => $def['leader_email'] );
        $enrollments['school_leaders'][ $key ] = $e;
        $enrollments['all'][]                  = $e;
        $enrollments['by_email'][ $e['email'] ] = $e;
    }

    // Returning mentors (skip Maria = index 5, Colombia).
    foreach ( $teams_def as $t_idx => $team ) {
        if ( in_array( $team['mentor_email'], $departures, true ) ) {
            continue; // Maria leaves
        }
        $uid = $users['mentors'][ $t_idx ]['user_id'];
        if ( ! $uid ) continue;
        $school_id = $schools[ $team['school'] ];
        $eid = $repo->create( array(
            'user_id' => $uid, 'cycle_id' => $cycle_id,
            'roles' => array( 'mentor' ), 'status' => 'active',
            'school_id' => $school_id, 'district_id' => $district_id,
        ) );
        $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'mentor',
                     'school_key' => $team['school'], 'team_idx' => $t_idx,
                     'email' => $team['mentor_email'], 'pathway_key' => 'mentor_p2' );
        $enrollments['mentors'][ $t_idx ] = $e;
        $enrollments['all'][]             = $e;
        $enrollments['by_email'][ $e['email'] ] = $e;
    }

    // Lisa — promoted teacher → mentor for Colombia.
    $lisa = $users['by_email']['lisa_teacher-T_01-boston@yopmail.com'];
    if ( $lisa['user_id'] ) {
        $eid = $repo->create( array(
            'user_id' => $lisa['user_id'], 'cycle_id' => $cycle_id,
            'roles' => array( 'mentor' ), 'status' => 'active',
            'school_id' => $schools['colombia'], 'district_id' => $district_id,
        ) );
        $e = array( 'enrollment_id' => $eid, 'user_id' => $lisa['user_id'], 'role' => 'mentor',
                     'school_key' => 'colombia', 'team_idx' => 5,
                     'email' => $lisa['email'], 'pathway_key' => 'mentor_transition' );
        $enrollments['lisa_mentor']    = $e;
        $enrollments['mentors'][5]     = $e; // Colombia team index
        $enrollments['all'][]          = $e;
        $enrollments['by_email'][ $e['email'] ] = $e;
    }

    // Natalia — new mentor at Boston (shadows on T01-Boston).
    $nat = $users['natalia'];
    if ( $nat['user_id'] ) {
        $eid = $repo->create( array(
            'user_id' => $nat['user_id'], 'cycle_id' => $cycle_id,
            'roles' => array( 'mentor' ), 'status' => 'active',
            'school_id' => $schools['boston'], 'district_id' => $district_id,
        ) );
        $e = array( 'enrollment_id' => $eid, 'user_id' => $nat['user_id'], 'role' => 'mentor',
                     'school_key' => 'boston', 'email' => $nat['email'],
                     'pathway_key' => 'mentor_p1' );
        $enrollments['natalia_mentor'] = $e;
        $enrollments['all'][]          = $e;
        $enrollments['by_email'][ $e['email'] ] = $e;
        // She joins T01-Boston as a team member (not mentor role).
        $enrollments['extra_team_members'][0] = array( $e ); // team index 0 = T01-Boston
    }

    // Returning teachers (skip departures).
    foreach ( $teams_def as $t_idx => $team ) {
        $school_id = $schools[ $team['school'] ];
        $enrollments['teachers'][ $t_idx ] = array();

        foreach ( $users['teachers'][ $t_idx ] as $t_data ) {
            if ( in_array( $t_data['email'], $departures, true ) ) {
                continue; // Departed
            }
            $uid = $t_data['user_id'];
            if ( ! $uid ) continue;
            $eid = $repo->create( array(
                'user_id' => $uid, 'cycle_id' => $cycle_id,
                'roles' => array( 'teacher' ), 'status' => 'active',
                'school_id' => $school_id, 'district_id' => $district_id,
            ) );
            $e = array( 'enrollment_id' => $eid, 'user_id' => $uid, 'role' => 'teacher',
                         'school_key' => $team['school'], 'team_idx' => $t_idx,
                         'email' => $t_data['email'] );
            $enrollments['teachers'][ $t_idx ][] = $e;
            $enrollments['all'][]                = $e;
            $enrollments['by_email'][ $e['email'] ] = $e;
        }
    }

    WP_CLI::log( '  [C2] Cycle 2 enrollments: ' . count( $enrollments['all'] ) );
    return $enrollments;
}
```

- [ ] **Step 3: Implement `seed_teams_c2()` — same 6 teams, different composition**

```php
private function seed_teams_c2( $cycle_id, $schools, $enrollments ) {
    // Reuse shared seed_teams() — the enrollments structure already reflects C2 roster.
    $this->seed_teams( $cycle_id, $schools, $enrollments, self::get_teams() );
}
```

- [ ] **Step 4: Implement `seed_pathways_c2()` — 8 pathways**

Create all 8 Phase 2 pathways. Copy pathway creation methods from `class-hl-cli-setup-elcpb-y2-v2.php`:
- `create_teacher_phase1()` — already exists (reuse from Cycle 1)
- `create_teacher_phase2()` — port from ELCPB Y2 V2 (16 components)
- `create_mentor_phase1()` — already exists (reuse from Cycle 1)
- `create_mentor_phase2()` — port from ELCPB Y2 V2 (18 components)
- `create_mentor_transition()` — port from ELCPB Y2 V2 (18 components)
- `create_mentor_completion()` — port from ELCPB Y2 V2 (4 components)
- `create_streamlined_phase1()` — already exists (reuse from Cycle 1)
- `create_streamlined_phase2()` — port from ELCPB Y2 V2 (10 components)

```php
private function seed_pathways_c2( $cycle_id ) {
    $svc      = new HL_Pathway_Service();
    $pathways = array();

    $pathways['teacher_p1']          = $this->create_teacher_phase1( $svc, $cycle_id );
    $pathways['teacher_p2']          = $this->create_teacher_phase2( $svc, $cycle_id );
    $pathways['mentor_p1']           = $this->create_mentor_phase1( $svc, $cycle_id );
    $pathways['mentor_p2']           = $this->create_mentor_phase2( $svc, $cycle_id );
    $pathways['mentor_transition']   = $this->create_mentor_transition( $svc, $cycle_id );
    $pathways['mentor_completion']   = $this->create_mentor_completion( $svc, $cycle_id );
    $pathways['streamlined_p1']      = $this->create_streamlined_phase1( $svc, $cycle_id );
    $pathways['streamlined_p2']      = $this->create_streamlined_phase2( $svc, $cycle_id );

    WP_CLI::log( '  [C4] Cycle 2 pathways: ' . count( $pathways ) );
    return $pathways;
}
```

Port these methods from `class-hl-cli-setup-elcpb-y2-v2.php` (lines 366-631):
- `create_teacher_phase2()` (16 cmp: TSA Pre, CA Pre, TC5-TC8 with SRs and RPs, CA Post, TSA Post)
- `create_mentor_phase2()` (18 cmp: TSA Pre, CA Pre, TC5-TC8, MC3-MC4, Coaching#1-4, RP#1-4, CA Post, TSA Post)
- `create_mentor_transition()` (18 cmp: TSA Pre, CA Pre, TC5-TC8, MC1-MC2, Coaching#1-4, RP#1-4, CA Post, TSA Post)
- `create_mentor_completion()` (4 cmp: TSA Pre, MC3, MC4, TSA Post)
- `create_streamlined_phase2()` (10 cmp: TC5_S-TC8_S, MC3_S-MC4_S, CV#1-4)

- [ ] **Step 5: Implement `assign_pathways_c2()`**

```php
private function assign_pathways_c2( $enrollments, $pathways ) {
    global $wpdb;
    $t     = $wpdb->prefix;
    $count = 0;

    // Returning teachers → Teacher Phase 2.
    foreach ( $enrollments['teachers'] as $team_teachers ) {
        foreach ( $team_teachers as $e ) {
            $wpdb->update( $t . 'hl_enrollment',
                array( 'assigned_pathway_id' => $pathways['teacher_p2']['pathway_id'] ),
                array( 'enrollment_id' => $e['enrollment_id'] ) );
            $count++;
        }
    }

    // Returning mentors → Mentor Phase 2.
    foreach ( $enrollments['mentors'] as $e ) {
        $pw_key = isset( $e['pathway_key'] ) ? $e['pathway_key'] : 'mentor_p2';
        $wpdb->update( $t . 'hl_enrollment',
            array( 'assigned_pathway_id' => $pathways[ $pw_key ]['pathway_id'] ),
            array( 'enrollment_id' => $e['enrollment_id'] ) );
        $count++;
    }

    // Natalia → Mentor Phase 1.
    if ( $enrollments['natalia_mentor'] ) {
        $wpdb->update( $t . 'hl_enrollment',
            array( 'assigned_pathway_id' => $pathways['mentor_p1']['pathway_id'] ),
            array( 'enrollment_id' => $enrollments['natalia_mentor']['enrollment_id'] ) );
        $count++;
    }

    // School Leaders + District Leader → Streamlined Phase 2.
    foreach ( $enrollments['school_leaders'] as $e ) {
        $wpdb->update( $t . 'hl_enrollment',
            array( 'assigned_pathway_id' => $pathways['streamlined_p2']['pathway_id'] ),
            array( 'enrollment_id' => $e['enrollment_id'] ) );
        $count++;
    }
    if ( $enrollments['district_leader'] ) {
        $wpdb->update( $t . 'hl_enrollment',
            array( 'assigned_pathway_id' => $pathways['streamlined_p2']['pathway_id'] ),
            array( 'enrollment_id' => $enrollments['district_leader']['enrollment_id'] ) );
        $count++;
    }

    WP_CLI::log( "  [C5] Pathway assignments: {$count}" );
}
```

- [ ] **Step 6: Implement `seed_child_assessments_c2()` — pending Pre instances**

```php
private function seed_child_assessments_c2( $cycle_id, $enrollments, $classrooms, $instruments ) {
    global $wpdb;
    $t = $wpdb->prefix;

    // Build classroom lookup with child IDs.
    $cr_lookup = array();
    foreach ( $classrooms as $cr ) {
        $cr_lookup[ $cr['classroom_id'] ] = $cr;
        $cr_lookup[ $cr['classroom_id'] ]['child_ids'] = $wpdb->get_col( $wpdb->prepare(
            "SELECT child_id FROM {$t}hl_child_classroom_current WHERE classroom_id = %d",
            $cr['classroom_id']
        ) );
    }

    // Get Cycle 2 teaching assignments.
    $all_eids = wp_list_pluck( $enrollments['all'], 'enrollment_id' );
    if ( empty( $all_eids ) ) return;

    $in = implode( ',', array_map( 'intval', $all_eids ) );
    $assignments = $wpdb->get_results(
        "SELECT enrollment_id, classroom_id FROM {$t}hl_teaching_assignment WHERE enrollment_id IN ({$in})"
    );

    $instances = 0;
    $childrows = 0;

    foreach ( $assignments as $asgn ) {
        $eid = (int) $asgn->enrollment_id;
        $cid = (int) $asgn->classroom_id;
        $cr  = isset( $cr_lookup[ $cid ] ) ? $cr_lookup[ $cid ] : null;
        if ( ! $cr ) continue;

        $instrument_id = isset( $instruments[ $cr['age_band'] ] ) ? $instruments[ $cr['age_band'] ] : null;

        // Pre only, pending.
        $wpdb->insert( $t . 'hl_child_assessment_instance', array(
            'instance_uuid'       => wp_generate_uuid4(),
            'cycle_id'            => $cycle_id,
            'enrollment_id'       => $eid,
            'classroom_id'        => $cid,
            'school_id'           => $cr['school_id'],
            'phase'               => 'pre',
            'instrument_age_band' => $cr['age_band'],
            'instrument_id'       => $instrument_id,
            'status'              => 'not_started',
            'created_at'          => current_time( 'mysql' ),
        ) );
        $instance_id = (int) $wpdb->insert_id;
        $instances++;

        // Childrows with no answers.
        foreach ( $cr['child_ids'] as $child_id ) {
            $wpdb->insert( $t . 'hl_child_assessment_childrow', array(
                'instance_id'      => $instance_id,
                'child_id'         => (int) $child_id,
                'frozen_age_group' => $cr['age_band'],
                'instrument_id'    => $instrument_id,
                'answers_json'     => null,
                'status'           => 'active',
            ) );
            $childrows++;
        }
    }

    WP_CLI::log( "  [C8] Child assessment instances (pending): {$instances}, childrows: {$childrows}" );
}
```

- [ ] **Step 7: Commit Cycle 2**

```bash
git add includes/cli/class-hl-cli-seed-beginnings.php
git commit -m "feat: Cycle 2 with roster turnover, 8 pathways, pending child assessments"
```

---

## Task 6: Coach Assignment + Summary Output

**Files:**
- Modify: `includes/cli/class-hl-cli-seed-beginnings.php`

- [ ] **Step 1: Implement `seed_coach_assignment()`**

```php
private function seed_coach_assignment( $cycle_id, $boston_school_id ) {
    // Find or create mgonzalez user.
    $coach_email = 'mgonzalez@housmanlearning.com';
    $coach       = get_user_by( 'email', $coach_email );

    if ( ! $coach ) {
        $uid = wp_insert_user( array(
            'user_login'   => 'mgonzalez',
            'user_email'   => $coach_email,
            'user_pass'    => $coach_email,
            'display_name' => 'Mateo Gonzalez',
            'first_name'   => 'Mateo',
            'last_name'    => 'Gonzalez',
            'role'         => 'subscriber',
        ) );
        if ( is_wp_error( $uid ) ) {
            WP_CLI::warning( 'Could not create coach user: ' . $uid->get_error_message() );
            return;
        }
        update_user_meta( $uid, self::META_KEY, 'created' );
        $coach_user_id = $uid;
        WP_CLI::log( "  [D1] Coach user created: {$coach_email} (ID {$uid})" );
    } else {
        update_user_meta( $coach->ID, self::META_KEY, 'found' );
        $coach_user_id = $coach->ID;
        WP_CLI::log( "  [D1] Coach user exists: {$coach_email} (ID {$coach->ID})" );
    }

    // Grant manage_hl_core capability.
    $user_obj = new WP_User( $coach_user_id );
    $user_obj->add_cap( 'manage_hl_core' );

    // Assign as coach for Boston school in Cycle 2.
    $svc    = new HL_Coach_Assignment_Service();
    $result = $svc->assign_coach( array(
        'coach_user_id' => $coach_user_id,
        'scope_type'    => 'school',
        'scope_id'      => $boston_school_id,
        'cycle_id'      => $cycle_id,
        'effective_from' => '2026-01-15',
    ) );
    if ( is_wp_error( $result ) ) {
        WP_CLI::warning( 'Coach assignment error: ' . $result->get_error_message() );
    } else {
        WP_CLI::log( '  [D1] Coach assigned: mgonzalez → Beginnings Boston (school scope)' );
    }
}
```

- [ ] **Step 2: Implement `print_summary()`**

```php
private function print_summary( $partnership_id, $org, $classrooms, $users, $c1, $c2 ) {
    WP_CLI::line( '' );
    WP_CLI::success( 'Beginnings data seeded successfully!' );
    WP_CLI::line( '' );
    WP_CLI::line( 'Summary:' );
    WP_CLI::line( "  Partnership:    {$partnership_id}" );
    WP_CLI::line( "  District:       {$org['district_id']}" );
    WP_CLI::line( '  Schools:        ' . count( $org['schools'] ) );
    WP_CLI::line( '  Classrooms:     ' . count( $classrooms ) );
    WP_CLI::line( '  WP Users:       ' . $users['count'] );
    WP_CLI::line( '' );
    WP_CLI::line( "  Cycle 1 (closed): id={$c1['cycle_id']}, " . count( $c1['enrollments']['all'] ) . ' enrollments, '
                . count( $c1['pathways'] ) . ' pathways' );
    WP_CLI::line( "  Cycle 2 (active): id={$c2['cycle_id']}, " . count( $c2['enrollments']['all'] ) . ' enrollments, '
                . count( $c2['pathways'] ) . ' pathways' );
    WP_CLI::line( '' );
    WP_CLI::line( '=== Demo Accounts ===' );
    WP_CLI::line( '  Coach:          mgonzalez@housmanlearning.com (password: same)' );
    WP_CLI::line( '  School Leader:  boston-school-leader@yopmail.com' );
    WP_CLI::line( '  Mentor:         mentor-T_01-boston@yopmail.com' );
    WP_CLI::line( '  Teacher:        john_teacher-T_01-boston@yopmail.com' );
    WP_CLI::line( '  (Password for yopmail accounts = email address)' );
    WP_CLI::line( '' );
}
```

- [ ] **Step 3: Commit coach + summary**

```bash
git add includes/cli/class-hl-cli-seed-beginnings.php
git commit -m "feat: coach assignment + demo account summary"
```

---

## Task 7: Clean Command

**Files:**
- Modify: `includes/cli/class-hl-cli-seed-beginnings.php`

- [ ] **Step 1: Implement `clean()` — comprehensive nuke for both cycles**

Follows the table deletion order from the spec (Section 10). Finds both cycle IDs, deletes all related data in correct FK order, then cleans org structure and users.

```php
private function clean() {
    global $wpdb;
    $t = $wpdb->prefix;

    WP_CLI::line( 'Cleaning Beginnings data...' );

    // Find both cycle IDs.
    $cycle_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT cycle_id FROM {$t}hl_cycle WHERE cycle_code IN (%s, %s)",
        self::CYCLE_1_CODE, self::CYCLE_2_CODE
    ) );

    foreach ( $cycle_ids as $cycle_id ) {
        $cycle_id = (int) $cycle_id;
        WP_CLI::log( "  Cleaning cycle {$cycle_id}..." );

        // Get enrollment IDs.
        $enrollment_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT enrollment_id FROM {$t}hl_enrollment WHERE cycle_id = %d", $cycle_id
        ) );

        if ( ! empty( $enrollment_ids ) ) {
            $in = implode( ',', array_map( 'intval', $enrollment_ids ) );

            // Enrollment-scoped tables.
            $wpdb->query( "DELETE FROM {$t}hl_completion_rollup WHERE enrollment_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$t}hl_component_state WHERE enrollment_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$t}hl_component_override WHERE enrollment_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$t}hl_team_membership WHERE enrollment_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$t}hl_teaching_assignment WHERE enrollment_id IN ({$in})" );

            // Child assessment instances + childrows.
            $ca_ids = $wpdb->get_col(
                "SELECT instance_id FROM {$t}hl_child_assessment_instance WHERE enrollment_id IN ({$in})"
            );
            if ( ! empty( $ca_ids ) ) {
                $in_ca = implode( ',', array_map( 'intval', $ca_ids ) );
                $wpdb->query( "DELETE FROM {$t}hl_child_assessment_childrow WHERE instance_id IN ({$in_ca})" );
            }
            $wpdb->query( "DELETE FROM {$t}hl_child_assessment_instance WHERE enrollment_id IN ({$in})" );

            // Teacher assessment instances.
            $wpdb->query( "DELETE FROM {$t}hl_teacher_assessment_instance WHERE enrollment_id IN ({$in})" );
        }

        // Observations (cycle-scoped).
        $obs_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT observation_id FROM {$t}hl_observation WHERE cycle_id = %d", $cycle_id
        ) );
        if ( ! empty( $obs_ids ) ) {
            $in_obs = implode( ',', array_map( 'intval', $obs_ids ) );
            $wpdb->query( "DELETE FROM {$t}hl_observation_attachment WHERE observation_id IN ({$in_obs})" );
            $wpdb->query( "DELETE FROM {$t}hl_observation_response WHERE observation_id IN ({$in_obs})" );
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_observation WHERE cycle_id = %d", $cycle_id ) );

        // Coaching sessions (cycle-scoped).
        $session_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT session_id FROM {$t}hl_coaching_session WHERE cycle_id = %d", $cycle_id
        ) );
        if ( ! empty( $session_ids ) ) {
            $in_sess = implode( ',', array_map( 'intval', $session_ids ) );
            $wpdb->query( "DELETE FROM {$t}hl_coaching_session_submission WHERE session_id IN ({$in_sess})" );
            // coaching_session_observation and coaching_attachment may not exist yet — safe to attempt.
            $wpdb->query( "DELETE FROM {$t}hl_coaching_session_observation WHERE session_id IN ({$in_sess})" );
            $wpdb->query( "DELETE FROM {$t}hl_coaching_attachment WHERE session_id IN ({$in_sess})" );
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_coaching_session WHERE cycle_id = %d", $cycle_id ) );

        // RP Sessions.
        $rp_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT session_id FROM {$t}hl_rp_session WHERE cycle_id = %d", $cycle_id
        ) );
        if ( ! empty( $rp_ids ) ) {
            $in_rp = implode( ',', array_map( 'intval', $rp_ids ) );
            $wpdb->query( "DELETE FROM {$t}hl_rp_session_submission WHERE session_id IN ({$in_rp})" );
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_rp_session WHERE cycle_id = %d", $cycle_id ) );

        // Classroom Visits.
        $cv_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT visit_id FROM {$t}hl_classroom_visit WHERE cycle_id = %d", $cycle_id
        ) );
        if ( ! empty( $cv_ids ) ) {
            $in_cv = implode( ',', array_map( 'intval', $cv_ids ) );
            $wpdb->query( "DELETE FROM {$t}hl_classroom_visit_submission WHERE visit_id IN ({$in_cv})" );
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_classroom_visit WHERE cycle_id = %d", $cycle_id ) );

        // Components + prereqs.
        $comp_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT component_id FROM {$t}hl_component WHERE cycle_id = %d", $cycle_id
        ) );
        if ( ! empty( $comp_ids ) ) {
            $in_comp = implode( ',', array_map( 'intval', $comp_ids ) );
            $group_ids = $wpdb->get_col(
                "SELECT group_id FROM {$t}hl_component_prereq_group WHERE component_id IN ({$in_comp})"
            );
            if ( ! empty( $group_ids ) ) {
                $in_grp = implode( ',', array_map( 'intval', $group_ids ) );
                $wpdb->query( "DELETE FROM {$t}hl_component_prereq_item WHERE group_id IN ({$in_grp})" );
            }
            $wpdb->query( "DELETE FROM {$t}hl_component_prereq_group WHERE component_id IN ({$in_comp})" );
            $wpdb->query( "DELETE FROM {$t}hl_component_drip_rule WHERE component_id IN ({$in_comp})" );
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_component WHERE cycle_id = %d", $cycle_id ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_pathway WHERE cycle_id = %d", $cycle_id ) );

        // Teams (also clean memberships by team_id for safety).
        $team_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT team_id FROM {$t}hl_team WHERE cycle_id = %d", $cycle_id
        ) );
        if ( ! empty( $team_ids ) ) {
            $in_teams = implode( ',', array_map( 'intval', $team_ids ) );
            $wpdb->query( "DELETE FROM {$t}hl_team_membership WHERE team_id IN ({$in_teams})" );
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_team WHERE cycle_id = %d", $cycle_id ) );

        // Enrollments + cycle-level records.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_enrollment WHERE cycle_id = %d", $cycle_id ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle_school WHERE cycle_id = %d", $cycle_id ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_coach_assignment WHERE cycle_id = %d", $cycle_id ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_child_cycle_snapshot WHERE cycle_id = %d", $cycle_id ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t}hl_cycle WHERE cycle_id = %d", $cycle_id ) );

        WP_CLI::log( "  Deleted cycle {$cycle_id} and all related records." );
    }

    // Children + classrooms by school.
    $school_ids = $wpdb->get_col(
        "SELECT orgunit_id FROM {$t}hl_orgunit WHERE name LIKE 'Beginnings%' AND orgunit_type = 'school'"
    );
    if ( ! empty( $school_ids ) ) {
        $in_schools = implode( ',', array_map( 'intval', $school_ids ) );
        $cr_ids = $wpdb->get_col(
            "SELECT classroom_id FROM {$t}hl_classroom WHERE school_id IN ({$in_schools})"
        );
        if ( ! empty( $cr_ids ) ) {
            $in_crs = implode( ',', array_map( 'intval', $cr_ids ) );
            $wpdb->query( "DELETE FROM {$t}hl_child_classroom_current WHERE classroom_id IN ({$in_crs})" );
            $wpdb->query( "DELETE FROM {$t}hl_child_classroom_history WHERE classroom_id IN ({$in_crs})" );
        }
        $wpdb->query( "DELETE FROM {$t}hl_child WHERE school_id IN ({$in_schools})" );
        $wpdb->query( "DELETE FROM {$t}hl_classroom WHERE school_id IN ({$in_schools})" );
        WP_CLI::log( '  Deleted children and classrooms.' );
    }

    // WP users.
    $demo_rows = $wpdb->get_results(
        "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '" . self::META_KEY . "'"
    );
    if ( ! empty( $demo_rows ) ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $deleted = 0;
        $untagged = 0;
        foreach ( $demo_rows as $row ) {
            if ( $row->meta_value === 'found' ) {
                delete_user_meta( (int) $row->user_id, self::META_KEY );
                $untagged++;
            } else {
                wp_delete_user( (int) $row->user_id );
                $deleted++;
            }
        }
        WP_CLI::log( "  Deleted {$deleted} seed users, untagged {$untagged} pre-existing." );
    }

    // Org units.
    if ( ! empty( $school_ids ) ) {
        $in_schools = implode( ',', array_map( 'intval', $school_ids ) );
        // Get district (parent of these schools).
        $district_ids = $wpdb->get_col(
            "SELECT DISTINCT parent_orgunit_id FROM {$t}hl_orgunit WHERE orgunit_id IN ({$in_schools}) AND parent_orgunit_id IS NOT NULL"
        );
        $wpdb->query( "DELETE FROM {$t}hl_orgunit WHERE orgunit_id IN ({$in_schools})" );
        if ( ! empty( $district_ids ) ) {
            $wpdb->query( "DELETE FROM {$t}hl_orgunit WHERE orgunit_id IN (" . implode( ',', array_map( 'intval', $district_ids ) ) . ")" );
        }
        WP_CLI::log( '  Deleted org units.' );
    }

    // Partnership.
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$t}hl_partnership WHERE partnership_code = %s", self::PARTNERSHIP_CODE
    ) );
    WP_CLI::log( '  Deleted partnership.' );

    // Instruments.
    $wpdb->query( "DELETE FROM {$t}hl_instrument WHERE name LIKE 'Beginnings%'" );
    WP_CLI::log( '  Deleted instruments.' );
}
```

- [ ] **Step 2: Commit clean command**

```bash
git add includes/cli/class-hl-cli-seed-beginnings.php
git commit -m "feat: comprehensive clean command for both Beginnings cycles"
```

---

## Task 8: Remove Y2 Seeder + Update Plugin Loader

**Files:**
- Delete: `includes/cli/class-hl-cli-seed-beginnings-y2.php`
- Modify: `hl-core.php` (lines 217, 295)

- [ ] **Step 1: Remove Y2 require and register lines from `hl-core.php`**

Delete line 217: `require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-seed-beginnings-y2.php';`
Delete line 295: `HL_CLI_Seed_Beginnings_Y2::register();`

- [ ] **Step 2: Delete the Y2 seeder file**

```bash
rm includes/cli/class-hl-cli-seed-beginnings-y2.php
```

- [ ] **Step 3: Commit cleanup**

```bash
git add hl-core.php
git rm includes/cli/class-hl-cli-seed-beginnings-y2.php
git commit -m "chore: remove obsolete Beginnings Y2 seeder"
```

---

## Task 9: Deploy and Test on Server

**Files:** None (server-side execution)

- [ ] **Step 1: Deploy to test server**

Reference: `.claude/skills/deploy.md` for SSH and deployment commands.

```bash
# From local — push to GitHub, then pull on server
git push origin main
ssh <test-server> "cd /path/to/wp-content/plugins/hl-core && git pull"
```

- [ ] **Step 2: Clean existing Beginnings data**

```bash
ssh <test-server> "cd /path/to/wordpress && wp hl-core seed-beginnings --clean"
```

Expected: Success message listing deleted cycles, users, children, org units, partnership, instruments.

- [ ] **Step 3: Run the seeder**

```bash
ssh <test-server> "cd /path/to/wordpress && wp hl-core seed-beginnings"
```

Expected output:
```
=== HL Core Beginnings School Seeder ===

  [A1] Partnership created: id=...
  [A2] Org units: district=..., schools=...
  [A3] Classrooms created: 8
  [A4] Children created: 40
  [A5] WP users created: 36
  [A6] Instrument created: Beginnings Infant Assessment (ID ...)
  ...

--- Cycle 1 (closed) ---
  [B1] Cycle 1 created: id=..., status=closed
  [B2] Cycle 1 enrollments: 35
  Teams created: 6
  [B4] Cycle 1 pathways: 3
  [B5] Pathway assignments: 35
  Teaching assignments: 24
  [B7] Frozen age group snapshots: ...
  [B8] Component states: ... (stragglers: 10)
  Completion rollups: 25
  [B9] Child assessment instances: 48, childrows: ...

--- Cycle 2 (active) ---
  [C1] Cycle 2 created: id=..., status=active
  [C2] Cycle 2 enrollments: 32
  Teams created: 6
  [C4] Cycle 2 pathways: 8
  [C5] Pathway assignments: 32
  Teaching assignments: 20
  [C7] Frozen age group snapshots: ...
  [C8] Child assessment instances (pending): ..., childrows: ...

  [D1] Coach assigned: mgonzalez → Beginnings Boston (school scope)

Success: Beginnings data seeded successfully!

=== Demo Accounts ===
  Coach:          mgonzalez@housmanlearning.com
  School Leader:  boston-school-leader@yopmail.com
  Mentor:         mentor-T_01-boston@yopmail.com
  Teacher:        john_teacher-T_01-boston@yopmail.com
```

- [ ] **Step 4: Verify in browser**

1. Login as `john_teacher-T_01-boston@yopmail.com` → Dashboard → Pathway shows Teacher Phase 2 → Child Assessment (Pre) shows children ready to score
2. Login as `mentor-T_01-boston@yopmail.com` → Dashboard → Pathway shows Mentor Phase 2
3. Login as `boston-school-leader@yopmail.com` → Dashboard → Pathway shows Streamlined Phase 2
4. Login as `mgonzalez@housmanlearning.com` → Coach Dashboard → sees Boston mentors (Marco, Monica, Natalia)

- [ ] **Step 5: Verify idempotent re-run protection**

```bash
ssh <test-server> "cd /path/to/wordpress && wp hl-core seed-beginnings"
```

Expected: Warning "Beginnings data already exists. Run with --clean first to reseed."
