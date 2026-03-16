<?php
/**
 * Provision 2 test teachers for Housman client testing.
 *
 * Run via: wp eval-file wp-content/plugins/hl-core/includes/cli/provision-test-users.php
 *
 * Creates:
 * - 2 WP users (yopmail addresses, no email notifications)
 * - 1 test school under LSF district
 * - 2 classrooms (mixed age groups for thorough testing)
 * - 8 fake children across 4 age groups
 * - 2 enrollments in the Lutheran control cycle
 * - Teaching assignments, component states, pathway assignments
 * - TSA + CA assessment instances
 *
 * Teacher A ("Jane Test") = simulates OLD teacher (set user_registered to May 2025)
 * Teacher B ("Maria Test") = simulates NEW teacher (set user_registered to now)
 */

if (!defined('ABSPATH')) {
    echo "Must be run via wp eval-file.\n";
    exit(1);
}

global $wpdb;

// ── Constants ──────────────────────────────────────────────────────────
$cycle_id       = 1;
$pathway_id     = 1;
$district_id    = 1; // LSF_PALM_BEACH

// Components (from the Lutheran cycle)
$act_tsa_pre_id  = 1; // Teacher Self-Assessment (Pre)
$act_ca_pre_id   = 2; // Child Assessment (Pre)
$act_tsa_post_id = 3; // Teacher Self-Assessment (Post)
$act_ca_post_id  = 4; // Child Assessment (Post)

// Instruments
$tsa_pre_instrument_id  = 1; // b2e_self_assessment_pre
$tsa_post_instrument_id = 2; // b2e_self_assessment_post
// Child instruments: 1=infant, 2=toddler, 3=preschool, 4=k2

$now = current_time('mysql');

echo "=== Provisioning Test Users ===\n\n";

// ── Step 1: Create WP Users (silently — no email) ─────────────────────
// Suppress new user notification emails
add_filter('wp_send_new_user_notification_to_user', '__return_false');
add_filter('wp_send_new_user_notification_to_admin', '__return_false');
// Also suppress wp_new_user_notification emails
remove_action('register_new_user', 'wp_send_new_user_notification');
remove_action('edit_user_created_user', 'wp_send_new_user_notification', 10);

$teachers = array(
    array(
        'email'      => 'jane.test.housman@yopmail.com',
        'first_name' => 'Jane',
        'last_name'  => 'Test',
        'type'       => 'old', // Simulate existing teacher
        'registered' => '2025-05-05 19:03:06',
    ),
    array(
        'email'      => 'maria.test.housman@yopmail.com',
        'first_name' => 'Maria',
        'last_name'  => 'Test',
        'type'       => 'new', // Simulate new teacher
        'registered' => $now,
    ),
);

$user_ids = array();

foreach ($teachers as $t) {
    $existing = get_user_by('email', $t['email']);
    if ($existing) {
        echo "  User already exists: {$t['email']} (ID {$existing->ID})\n";
        $user_ids[] = $existing->ID;
        continue;
    }

    $password = wp_generate_password(16, true, true);
    $user_id = wp_insert_user(array(
        'user_login'   => sanitize_user($t['email'], true),
        'user_email'   => $t['email'],
        'user_pass'    => $password,
        'first_name'   => $t['first_name'],
        'last_name'    => $t['last_name'],
        'display_name' => $t['first_name'] . ' ' . $t['last_name'],
        'role'         => 'subscriber',
    ));

    if (is_wp_error($user_id)) {
        echo "  ERROR creating {$t['email']}: " . $user_id->get_error_message() . "\n";
        $user_ids[] = null;
        continue;
    }

    // Set registration date to simulate old/new
    $wpdb->update($wpdb->users, array('user_registered' => $t['registered']), array('ID' => $user_id));

    echo "  Created user: {$t['first_name']} {$t['last_name']} ({$t['email']}) — ID {$user_id} [{$t['type']}]\n";
    $user_ids[] = $user_id;
}

if (count(array_filter($user_ids)) < 2) {
    echo "\nERROR: Could not create both users. Aborting.\n";
    exit(1);
}

// ── Step 2: Create Test School ──────────────────────────────────────────
$test_school_code = 'HOUSMAN_TEST_SCHOOL';
$school_id = $wpdb->get_var($wpdb->prepare(
    "SELECT orgunit_id FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_code = %s",
    $test_school_code
));

if (!$school_id) {
    $wpdb->insert("{$wpdb->prefix}hl_orgunit", array(
        'orgunit_uuid'     => wp_generate_uuid4(),
        'orgunit_code'     => $test_school_code,
        'orgunit_type'     => 'school',
        'parent_orgunit_id' => $district_id,
        'name'             => 'Housman Test School',
        'status'           => 'active',
        'metadata'         => json_encode(array('address' => '123 Test Ave, West Palm Beach, FL', 'test' => true)),
        'created_at'       => $now,
        'updated_at'       => $now,
    ));
    $school_id = $wpdb->insert_id;
    echo "  Created test school: ID {$school_id}\n";
} else {
    echo "  Test school already exists: ID {$school_id}\n";
}

// Link school to cycle
$link_exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}hl_cycle_school WHERE cycle_id = %d AND orgunit_id = %d",
    $cycle_id, $school_id
));
if (!$link_exists) {
    $wpdb->insert("{$wpdb->prefix}hl_cycle_school", array(
        'cycle_id'   => $cycle_id,
        'orgunit_id' => $school_id,
    ));
    echo "  Linked test school to cycle\n";
}

// ── Step 3: Create 2 Classrooms ──────────────────────────────────────
// Classroom A: Jane's — mixed ages (1 Infant, 1 Toddler, 1 Preschool, 1 K-2)
// Classroom B: Maria's — mixed ages (1 Toddler, 1 Preschool, 1 K-2, 1 Infant)
$classrooms = array(
    array(
        'name'     => 'Test Room A',
        'age_band' => 'mixed',
        'teacher_idx' => 0, // Jane
    ),
    array(
        'name'     => 'Test Room B',
        'age_band' => 'mixed',
        'teacher_idx' => 1, // Maria
    ),
);

$classroom_ids = array();
foreach ($classrooms as $c) {
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT classroom_id FROM {$wpdb->prefix}hl_classroom WHERE school_id = %d AND classroom_name = %s",
        $school_id, $c['name']
    ));
    if ($existing) {
        echo "  Classroom already exists: {$c['name']} (ID {$existing})\n";
        $classroom_ids[] = $existing;
        continue;
    }

    $wpdb->insert("{$wpdb->prefix}hl_classroom", array(
        'classroom_uuid' => wp_generate_uuid4(),
        'school_id'      => $school_id,
        'classroom_name' => $c['name'],
        'age_band'       => $c['age_band'],
        'status'         => 'active',
        'created_at'     => $now,
        'updated_at'     => $now,
    ));
    $classroom_ids[] = $wpdb->insert_id;
    echo "  Created classroom: {$c['name']} (ID {$wpdb->insert_id})\n";
}

// ── Step 4: Create Enrollments ───────────────────────────────────────
$enrollment_ids = array();
foreach ($user_ids as $idx => $uid) {
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment WHERE cycle_id = %d AND user_id = %d",
        $cycle_id, $uid
    ));
    if ($existing) {
        echo "  Enrollment already exists for user {$uid}: ID {$existing}\n";
        $enrollment_ids[] = $existing;
        continue;
    }

    $wpdb->insert("{$wpdb->prefix}hl_enrollment", array(
        'enrollment_uuid' => wp_generate_uuid4(),
        'cycle_id'        => $cycle_id,
        'user_id'         => $uid,
        'roles'           => '["teacher"]',
        'assigned_pathway_id' => $pathway_id,
        'school_id'       => $school_id,
        'district_id'     => $district_id,
        'status'          => 'active',
        'enrolled_at'     => $now,
        'created_at'      => $now,
        'updated_at'      => $now,
    ));
    $enrollment_ids[] = $wpdb->insert_id;
    $name = $teachers[$idx]['first_name'] . ' ' . $teachers[$idx]['last_name'];
    echo "  Created enrollment for {$name}: ID {$wpdb->insert_id}\n";
}

// ── Step 5: Teaching Assignments ─────────────────────────────────────
foreach ($enrollment_ids as $idx => $eid) {
    $cid = $classroom_ids[$idx];
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT assignment_id FROM {$wpdb->prefix}hl_teaching_assignment WHERE enrollment_id = %d AND classroom_id = %d",
        $eid, $cid
    ));
    if (!$existing) {
        $wpdb->insert("{$wpdb->prefix}hl_teaching_assignment", array(
            'enrollment_id'        => $eid,
            'classroom_id'         => $cid,
            'is_lead_teacher'      => 1,
            'effective_start_date' => date('Y-m-d'),
            'created_at'           => $now,
            'updated_at'           => $now,
        ));
        echo "  Created teaching assignment: enrollment {$eid} → classroom {$cid}\n";
    } else {
        echo "  Teaching assignment already exists: enrollment {$eid} → classroom {$cid}\n";
    }
}

// ── Step 6: Children (4 per classroom, covering all age groups) ──────
$children_data = array(
    // Classroom A (Jane's)
    array('classroom_idx' => 0, 'first' => 'Lily',   'last' => 'Testchild', 'dob' => '2025-06-15', 'age_group' => 'infant'),
    array('classroom_idx' => 0, 'first' => 'Noah',   'last' => 'Testchild', 'dob' => '2024-03-10', 'age_group' => 'toddler'),
    array('classroom_idx' => 0, 'first' => 'Sofia',  'last' => 'Testchild', 'dob' => '2022-09-20', 'age_group' => 'preschool'),
    array('classroom_idx' => 0, 'first' => 'Ethan',  'last' => 'Testchild', 'dob' => '2021-01-05', 'age_group' => 'k2'),
    // Classroom B (Maria's)
    array('classroom_idx' => 1, 'first' => 'Mia',    'last' => 'Testchild', 'dob' => '2025-08-22', 'age_group' => 'infant'),
    array('classroom_idx' => 1, 'first' => 'Oliver', 'last' => 'Testchild', 'dob' => '2024-05-14', 'age_group' => 'toddler'),
    array('classroom_idx' => 1, 'first' => 'Emma',   'last' => 'Testchild', 'dob' => '2023-02-28', 'age_group' => 'preschool'),
    array('classroom_idx' => 1, 'first' => 'Liam',   'last' => 'Testchild', 'dob' => '2020-11-11', 'age_group' => 'k2'),
);

$child_ids = array();
$child_age_groups = array(); // child_id => age_group

foreach ($children_data as $cd) {
    $cid = $classroom_ids[$cd['classroom_idx']];
    $fingerprint = md5(strtolower($cd['first'] . $cd['last'] . $cd['dob']));

    $existing_id = $wpdb->get_var($wpdb->prepare(
        "SELECT child_id FROM {$wpdb->prefix}hl_child WHERE child_fingerprint = %s",
        $fingerprint
    ));
    if ($existing_id) {
        echo "  Child already exists: {$cd['first']} {$cd['last']} (ID {$existing_id})\n";
        $child_ids[] = $existing_id;
        $child_age_groups[$existing_id] = $cd['age_group'];
        continue;
    }

    // Generate display code
    $display_code = strtoupper(substr($cd['first'], 0, 2) . substr($cd['last'], 0, 2)) . '-' . substr($cd['dob'], 5, 2) . substr($cd['dob'], 8, 2);

    $wpdb->insert("{$wpdb->prefix}hl_child", array(
        'child_uuid'        => wp_generate_uuid4(),
        'school_id'         => $school_id,
        'first_name'        => $cd['first'],
        'last_name'         => $cd['last'],
        'dob'               => $cd['dob'],
        'internal_child_id' => 'TEST-' . strtoupper($cd['first']),
        'child_fingerprint' => $fingerprint,
        'child_display_code' => $display_code,
        'created_at'        => $now,
        'updated_at'        => $now,
    ));
    $new_child_id = $wpdb->insert_id;
    $child_ids[] = $new_child_id;
    $child_age_groups[$new_child_id] = $cd['age_group'];
    echo "  Created child: {$cd['first']} {$cd['last']} (ID {$new_child_id}, {$cd['age_group']})\n";

    // Child-classroom assignment
    $wpdb->insert("{$wpdb->prefix}hl_child_classroom", array(
        'child_id'     => $new_child_id,
        'classroom_id' => $cid,
        'start_date'   => date('Y-m-d'),
        'created_at'   => $now,
    ));
}

// ── Step 7: Freeze age group snapshots ───────────────────────────────
foreach ($child_ids as $cid_child) {
    $ag = $child_age_groups[$cid_child];
    $child_row = $wpdb->get_row($wpdb->prepare(
        "SELECT dob FROM {$wpdb->prefix}hl_child WHERE child_id = %d", $cid_child
    ));

    $existing_snap = $wpdb->get_var($wpdb->prepare(
        "SELECT snapshot_id FROM {$wpdb->prefix}hl_child_track_snapshot WHERE child_id = %d AND cycle_id = %d",
        $cid_child, $cycle_id
    ));
    if (!$existing_snap) {
        $dob_dt = new DateTime($child_row->dob);
        $now_dt = new DateTime();
        $age_months = ($now_dt->format('Y') - $dob_dt->format('Y')) * 12 + ($now_dt->format('n') - $dob_dt->format('n'));

        $wpdb->insert("{$wpdb->prefix}hl_child_track_snapshot", array(
            'child_id'           => $cid_child,
            'cycle_id'           => $cycle_id,
            'frozen_age_group'   => $ag,
            'dob_at_freeze'      => $child_row->dob,
            'age_months_at_freeze' => $age_months,
            'frozen_at'          => $now,
            'created_at'         => $now,
        ));
        echo "  Frozen snapshot for child {$cid_child}: {$ag}\n";
    }
}

// ── Step 8: Component States (4 per enrollment) ─────────────────────
$component_ids = array($act_tsa_pre_id, $act_ca_pre_id, $act_tsa_post_id, $act_ca_post_id);
foreach ($enrollment_ids as $eid) {
    foreach ($component_ids as $aid) {
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT state_id FROM {$wpdb->prefix}hl_component_state WHERE enrollment_id = %d AND component_id = %d",
            $eid, $aid
        ));
        if (!$existing) {
            $wpdb->insert("{$wpdb->prefix}hl_component_state", array(
                'enrollment_id'     => $eid,
                'component_id'      => $aid,
                'completion_percent' => 0,
                'completion_status' => 'not_started',
                'last_computed_at'  => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ));
        }
    }
    echo "  Created 4 component states for enrollment {$eid}\n";
}

// ── Step 9: Pathway Assignments ──────────────────────────────────────
foreach ($enrollment_ids as $eid) {
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT assignment_id FROM {$wpdb->prefix}hl_pathway_assignment WHERE enrollment_id = %d AND pathway_id = %d",
        $eid, $pathway_id
    ));
    if (!$existing) {
        $wpdb->insert("{$wpdb->prefix}hl_pathway_assignment", array(
            'enrollment_id'      => $eid,
            'pathway_id'         => $pathway_id,
            'assigned_by_user_id' => 1, // admin
            'assignment_type'    => 'role_default',
            'created_at'         => $now,
        ));
        echo "  Created pathway assignment for enrollment {$eid}\n";
    }
}

// ── Step 10: TSA Instances (Pre + Post per enrollment) ───────────────
foreach ($enrollment_ids as $eid) {
    // Pre
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT instance_id FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE enrollment_id = %d AND phase = 'pre'",
        $eid
    ));
    if (!$existing) {
        $wpdb->insert("{$wpdb->prefix}hl_teacher_assessment_instance", array(
            'instance_uuid'      => wp_generate_uuid4(),
            'cycle_id'           => $cycle_id,
            'enrollment_id'      => $eid,
            'component_id'       => $act_tsa_pre_id,
            'phase'              => 'pre',
            'instrument_id'      => $tsa_pre_instrument_id,
            'instrument_version' => '1.0',
            'status'             => 'not_started',
            'created_at'         => $now,
            'updated_at'         => $now,
        ));
    }
    // Post
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT instance_id FROM {$wpdb->prefix}hl_teacher_assessment_instance WHERE enrollment_id = %d AND phase = 'post'",
        $eid
    ));
    if (!$existing) {
        $wpdb->insert("{$wpdb->prefix}hl_teacher_assessment_instance", array(
            'instance_uuid'      => wp_generate_uuid4(),
            'cycle_id'           => $cycle_id,
            'enrollment_id'      => $eid,
            'component_id'       => $act_tsa_post_id,
            'phase'              => 'post',
            'instrument_id'      => $tsa_post_instrument_id,
            'instrument_version' => '1.0',
            'status'             => 'not_started',
            'created_at'         => $now,
            'updated_at'         => $now,
        ));
    }
    echo "  Created TSA instances (pre+post) for enrollment {$eid}\n";
}

// ── Step 11: CA Instances (Pre + Post per enrollment per age group) ──
// Map age_group → instrument_id
$age_instrument_map = array(
    'infant'    => 1,
    'toddler'   => 2,
    'preschool' => 3,
    'k2'        => 4,
);

foreach ($enrollment_ids as $idx => $eid) {
    $cid = $classroom_ids[$idx];
    // Each classroom has 4 children, one per age group
    $age_groups_in_classroom = array('infant', 'toddler', 'preschool', 'k2');

    foreach ($age_groups_in_classroom as $ag) {
        $inst_id = $age_instrument_map[$ag];

        foreach (array('pre', 'post') as $phase) {
            $act_id = ($phase === 'pre') ? $act_ca_pre_id : $act_ca_post_id;

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT instance_id FROM {$wpdb->prefix}hl_child_assessment_instance
                 WHERE enrollment_id = %d AND phase = %s AND instrument_age_band = %s AND classroom_id = %d",
                $eid, $phase, $ag, $cid
            ));
            if (!$existing) {
                $wpdb->insert("{$wpdb->prefix}hl_child_assessment_instance", array(
                    'instance_uuid'      => wp_generate_uuid4(),
                    'cycle_id'           => $cycle_id,
                    'enrollment_id'      => $eid,
                    'component_id'       => $act_id,
                    'classroom_id'       => $cid,
                    'school_id'          => $school_id,
                    'phase'              => $phase,
                    'instrument_age_band' => $ag,
                    'instrument_id'      => $inst_id,
                    'instrument_version' => '1.0',
                    'status'             => 'not_started',
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ));
            }
        }
    }
    echo "  Created CA instances (4 age groups x pre+post) for enrollment {$eid}\n";
}

// ── Done ─────────────────────────────────────────────────────────────
echo "\n=== SUMMARY ===\n";
echo "Users created:     " . count(array_filter($user_ids)) . "\n";
echo "  Jane Test (OLD): {$teachers[0]['email']} — user_id {$user_ids[0]}, registered {$teachers[0]['registered']}\n";
echo "  Maria Test (NEW): {$teachers[1]['email']} — user_id {$user_ids[1]}, registered {$now}\n";
echo "Test school:       Housman Test School (ID {$school_id})\n";
echo "Classrooms:        Test Room A (ID {$classroom_ids[0]}), Test Room B (ID {$classroom_ids[1]})\n";
echo "Children:          " . count($child_ids) . " (4 per classroom, all age groups)\n";
echo "Enrollments:       " . count($enrollment_ids) . "\n";
echo "Component states:  " . (count($enrollment_ids) * 4) . "\n";
echo "TSA instances:     " . (count($enrollment_ids) * 2) . "\n";
echo "CA instances:      " . (count($enrollment_ids) * 8) . "\n";
echo "\nYopmail inboxes:\n";
echo "  OLD teacher: https://yopmail.com/en/?login=jane.test.housman\n";
echo "  NEW teacher: https://yopmail.com/en/?login=maria.test.housman\n";
echo "\nDone!\n";
