<?php
/**
 * Provision Jane & Maria test teachers on any environment.
 * Run via: wp eval-file wp-content/plugins/hl-core/includes/cli/provision-test-teachers.php
 */
if (!defined('ABSPATH')) {
    echo "Must be run via WP-CLI.\n";
    exit(1);
}

global $wpdb;

// ── 1. Create or find Jane (old teacher) ──────────────────────
$jane_user = get_user_by('email', 'jane.test.housman@yopmail.com');
if ($jane_user) {
    $jane_id = $jane_user->ID;
} else {
    $jane_id = wp_create_user('jane.test.housman', 'HousmanTest2026!', 'jane.test.housman@yopmail.com');
    if (is_wp_error($jane_id)) {
        WP_CLI::error("Failed to create Jane: " . $jane_id->get_error_message());
    }
}
wp_update_user(array('ID' => $jane_id, 'first_name' => 'Jane', 'last_name' => 'Thompson', 'display_name' => 'Jane Thompson'));
WP_CLI::log("Jane user ID: $jane_id");

// ── 2. Create or find Maria (new teacher) ─────────────────────
$maria_user = get_user_by('email', 'maria.test.housman@yopmail.com');
if ($maria_user) {
    $maria_id = $maria_user->ID;
} else {
    $maria_id = wp_create_user('maria.test.housman', 'HousmanTest2026!', 'maria.test.housman@yopmail.com');
    if (is_wp_error($maria_id)) {
        WP_CLI::error("Failed to create Maria: " . $maria_id->get_error_message());
    }
}
wp_update_user(array('ID' => $maria_id, 'first_name' => 'Maria', 'last_name' => 'Santos', 'display_name' => 'Maria Santos'));
WP_CLI::log("Maria user ID: $maria_id");

// ── 3. Find the Demo Track ───────────────────────────────────
$cycle_id = $wpdb->get_var("SELECT cycle_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_code = 'DEMO-2026' LIMIT 1");
if (!$cycle_id) {
    // Fallback: use any active cycle
    $cycle_id = $wpdb->get_var("SELECT cycle_id FROM {$wpdb->prefix}hl_cycle LIMIT 1");
}
if (!$cycle_id) {
    WP_CLI::error("No cycle found. Run seed-demo first.");
}
WP_CLI::log("Using cycle ID: $cycle_id");

// ── Helper functions ─────────────────────────────────────────
function ensure_enrollment($wpdb, $user_id, $cycle_id) {
    $prefix = $wpdb->prefix;
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT enrollment_id FROM {$prefix}hl_enrollment WHERE user_id = %d AND cycle_id = %d",
        $user_id, $cycle_id
    ));
    if ($existing) return (int)$existing;

    $roles = array('teacher');
    $wpdb->insert("{$prefix}hl_enrollment", array(
        'enrollment_uuid' => wp_generate_uuid4(),
        'user_id'         => $user_id,
        'cycle_id'        => $cycle_id,
        'roles'           => class_exists('HL_Roles') ? HL_Roles::sanitize_roles($roles) : wp_json_encode($roles),
        'status'          => 'active',
    ));
    return (int)$wpdb->insert_id;
}

function ensure_classroom($wpdb, $name, $school_id, $age_band) {
    $prefix = $wpdb->prefix;
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT classroom_id FROM {$prefix}hl_classroom WHERE classroom_name = %s AND school_id = %d",
        $name, $school_id
    ));
    if ($existing) return (int)$existing;

    $wpdb->insert("{$prefix}hl_classroom", array(
        'classroom_name' => $name,
        'school_id'      => $school_id,
        'age_band'       => $age_band,
    ));
    return (int)$wpdb->insert_id;
}

function ensure_teaching_assignment($wpdb, $eid, $cid) {
    $prefix = $wpdb->prefix;
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT assignment_id FROM {$prefix}hl_teaching_assignment WHERE enrollment_id = %d AND classroom_id = %d",
        $eid, $cid
    ));
    if ($existing) return (int)$existing;

    $wpdb->insert("{$prefix}hl_teaching_assignment", array(
        'enrollment_id'   => $eid,
        'classroom_id'    => $cid,
        'is_lead_teacher' => 1,
    ));
    return (int)$wpdb->insert_id;
}

function add_child($wpdb, $first, $last, $dob, $school_id, $classroom_id, $gender = 'female') {
    $prefix = $wpdb->prefix;
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT child_id FROM {$prefix}hl_child WHERE first_name = %s AND last_name = %s AND school_id = %d",
        $first, $last, $school_id
    ));

    if ($existing) {
        $child_id = (int)$existing;
    } else {
        $wpdb->insert("{$prefix}hl_child", array(
            'first_name'       => $first,
            'last_name'        => $last,
            'date_of_birth'    => $dob,
            'school_id'        => $school_id,
            'gender'           => $gender,
            'fingerprint_hash' => md5(strtolower("$first|$last|$dob|$school_id")),
        ));
        $child_id = (int)$wpdb->insert_id;
    }

    // Ensure in classroom
    $in_class = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$prefix}hl_child_classroom_current WHERE child_id = %d AND classroom_id = %d",
        $child_id, $classroom_id
    ));
    if (!$in_class) {
        $wpdb->insert("{$prefix}hl_child_classroom_current", array(
            'child_id'     => $child_id,
            'classroom_id' => $classroom_id,
            'status'       => 'active',
        ));
    }
    return $child_id;
}

// ── 4. Get a school_id ───────────────────────────────────────
$school_id = $wpdb->get_var("SELECT school_id FROM {$wpdb->prefix}hl_classroom LIMIT 1");
if (!$school_id) {
    $school_id = $wpdb->get_var($wpdb->prepare(
        "SELECT school_id FROM {$wpdb->prefix}hl_cycle_school WHERE cycle_id = %d LIMIT 1",
        $cycle_id
    ));
}
if (!$school_id) {
    WP_CLI::error("No school found.");
}
WP_CLI::log("Using school ID: $school_id");

// ── 5. Enrollments ───────────────────────────────────────────
$jane_eid  = ensure_enrollment($wpdb, $jane_id, $cycle_id);
$maria_eid = ensure_enrollment($wpdb, $maria_id, $cycle_id);
WP_CLI::log("Jane enrollment: $jane_eid, Maria enrollment: $maria_eid");

// ── 6. Classrooms ────────────────────────────────────────────
$jane_classroom  = ensure_classroom($wpdb, 'Jane Preschool Room', $school_id, 'preschool');
$maria_classroom = ensure_classroom($wpdb, 'Maria Toddler Room', $school_id, 'toddler');
WP_CLI::log("Jane classroom: $jane_classroom, Maria classroom: $maria_classroom");

// ── 7. Teaching assignments ──────────────────────────────────
ensure_teaching_assignment($wpdb, $jane_eid, $jane_classroom);
ensure_teaching_assignment($wpdb, $maria_eid, $maria_classroom);
WP_CLI::log("Teaching assignments created.");

// ── 8. Children for Jane (preschool, ages 3-4) ──────────────
$jane_kids = array(
    array('Sophia', 'Garcia',   '2022-06-15', 'female'),
    array('Liam',   'Johnson',  '2022-09-03', 'male'),
    array('Emma',   'Williams', '2023-01-20', 'female'),
    array('Noah',   'Brown',    '2022-11-08', 'male'),
    array('Olivia', 'Davis',    '2022-08-25', 'female'),
    array('Aiden',  'Martinez', '2023-03-12', 'male'),
);
foreach ($jane_kids as $c) {
    add_child($wpdb, $c[0], $c[1], $c[2], $school_id, $jane_classroom, $c[3]);
}
WP_CLI::log("Added 6 children to Jane's classroom.");

// ── 9. Children for Maria (toddler, ages 1-2) ───────────────
$maria_kids = array(
    array('Isabella', 'Rivera',   '2024-02-10', 'female'),
    array('Ethan',    'Lee',      '2024-05-22', 'male'),
    array('Mia',      'Taylor',   '2023-11-15', 'female'),
    array('Lucas',    'Anderson', '2024-01-30', 'male'),
    array('Ava',      'Thomas',   '2024-04-18', 'female'),
);
foreach ($maria_kids as $c) {
    add_child($wpdb, $c[0], $c[1], $c[2], $school_id, $maria_classroom, $c[3]);
}
WP_CLI::log("Added 5 children to Maria's classroom.");

// ── 10. Freeze age groups ────────────────────────────────────
if (class_exists('HL_Child_Snapshot_Service')) {
    $snapshot_service = new HL_Child_Snapshot_Service();
    $snapshot_service->freeze_age_groups($cycle_id);
    WP_CLI::log("Froze age groups for cycle $cycle_id.");
}

// ── 11. Assign teacher pathway ───────────────────────────────
$teacher_pathway = $wpdb->get_var($wpdb->prepare(
    "SELECT pathway_id FROM {$wpdb->prefix}hl_pathway WHERE cycle_id = %d AND target_roles LIKE %s LIMIT 1",
    $cycle_id, '%teacher%'
));
if ($teacher_pathway) {
    foreach (array($jane_eid, $maria_eid) as $eid) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT assignment_id FROM {$wpdb->prefix}hl_pathway_assignment WHERE enrollment_id = %d AND pathway_id = %d",
            $eid, $teacher_pathway
        ));
        if (!$exists) {
            $wpdb->insert("{$wpdb->prefix}hl_pathway_assignment", array(
                'enrollment_id'      => $eid,
                'pathway_id'         => $teacher_pathway,
                'assigned_by_user_id' => 1,
                'assignment_type'     => 'explicit',
            ));
        }
    }
    WP_CLI::log("Assigned pathway $teacher_pathway to both teachers.");
}

// ── 12. Generate child assessment instances ──────────────────
if (class_exists('HL_Assessment_Service')) {
    $service = new HL_Assessment_Service();
    $service->generate_child_assessment_instances($cycle_id);
    WP_CLI::log("Generated child assessment instances.");
}

// ── Verification ─────────────────────────────────────────────
$jane_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}hl_child_classroom_current cc
     JOIN {$wpdb->prefix}hl_teaching_assignment ta ON cc.classroom_id = ta.classroom_id
     WHERE ta.enrollment_id = %d AND cc.status = 'active'",
    $jane_eid
));
$maria_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}hl_child_classroom_current cc
     JOIN {$wpdb->prefix}hl_teaching_assignment ta ON cc.classroom_id = ta.classroom_id
     WHERE ta.enrollment_id = %d AND cc.status = 'active'",
    $maria_eid
));

WP_CLI::success("Done!");
WP_CLI::log("Jane: $jane_count children, Maria: $maria_count children");
WP_CLI::log("");
WP_CLI::log("Login credentials:");
WP_CLI::log("  Jane:  jane.test.housman@yopmail.com / HousmanTest2026!");
WP_CLI::log("  Maria: maria.test.housman@yopmail.com / HousmanTest2026!");
