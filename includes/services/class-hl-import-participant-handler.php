<?php
if (!defined('ABSPATH')) exit;

/**
 * Import Participant Handler
 *
 * Validates and commits participant import rows. Auto-creates
 * classrooms, teaching assignments, teams, team memberships,
 * coach assignments, and pathway assignments.
 *
 * @package HL_Core
 */
class HL_Import_Participant_Handler {

    /** @var HL_Import_Service */
    private $import_service;

    public function __construct() {
        $this->import_service = new HL_Import_Service();
    }

    /**
     * Validate participant rows against database.
     *
     * @param array $parsed_rows Associative arrays from CSV parser.
     * @param int   $cycle_id
     * @return array Preview rows with status, messages, parsed data.
     */
    public function validate($parsed_rows, $cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $preview_rows = array();
        $seen_emails  = array();

        // Pre-load lookups scoped to Partnership
        $partnership_schools = $this->import_service->load_partnership_schools($cycle_id);

        // Build name-based and code-based school indexes for matching
        $school_by_name = array();
        $school_by_code = array();
        foreach ($partnership_schools as $s) {
            $school_by_name[strtolower(trim($s->name))] = $s;
            if (!empty($s->orgunit_code)) {
                $school_by_code[strtolower(trim($s->orgunit_code))] = $s;
            }
        }

        // Pre-load pathways for this cycle
        $pathways = $wpdb->get_results($wpdb->prepare(
            "SELECT pathway_id, pathway_name, pathway_code, target_roles
             FROM {$prefix}hl_pathway WHERE cycle_id = %d AND active_status = 1",
            $cycle_id
        ), ARRAY_A) ?: array();

        $pathway_by_name = array();
        $pathway_by_code = array();
        foreach ($pathways as $p) {
            $pathway_by_name[strtolower(trim($p['pathway_name']))] = $p;
            if (!empty($p['pathway_code'])) {
                $pathway_by_code[strtolower(trim($p['pathway_code']))] = $p;
            }
        }

        // Pre-load existing enrollments for this cycle
        $existing_enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, e.user_id, e.roles, e.school_id, e.status, e.language_preference,
                    u.user_email
             FROM {$prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cycle_id = %d",
            $cycle_id
        ), ARRAY_A) ?: array();

        $enrollment_by_email = array();
        foreach ($existing_enrollments as $ee) {
            $enrollment_by_email[strtolower($ee['user_email'])] = $ee;
        }

        // Pre-load existing teams for this cycle
        $existing_teams = $wpdb->get_results($wpdb->prepare(
            "SELECT team_id, team_name, school_id FROM {$prefix}hl_team WHERE cycle_id = %d AND status = 'active'",
            $cycle_id
        ), ARRAY_A) ?: array();

        $team_lookup = array(); // "school_id|team_name_lower" => team row
        foreach ($existing_teams as $t) {
            $key = $t['school_id'] . '|' . strtolower(trim($t['team_name']));
            $team_lookup[$key] = $t;
        }

        // Pre-load existing classrooms
        $classrooms_by_school = $this->import_service->load_classrooms_by_school($cycle_id);

        foreach ($parsed_rows as $index => $row) {
            $preview = array(
                'row_index'           => $index,
                'raw_data'            => $row,
                'status'              => 'ERROR',
                'matched_user_id'     => null,
                'matched_school_id'   => null,
                'existing_enrollment_id' => null,
                'validation_messages' => array(),
                'proposed_actions'    => array(),
                'parsed_email'        => '',
                'parsed_role'         => '',
                'parsed_first_name'   => '',
                'parsed_last_name'    => '',
                'parsed_classroom'    => '',
                'parsed_team'         => '',
                'parsed_pathway'      => '',
                'parsed_coach'        => '',
                'parsed_language'     => 'en',
                'parsed_age_group'    => '',
                'parsed_is_primary'   => false,
                'raw_school'          => '',
                'selected'            => false,
            );

            // --- Email ---
            $raw_email = isset($row['email']) ? $row['email'] : '';
            $email = HL_Normalization::normalize_email($raw_email);
            $preview['parsed_email'] = $email;

            if (empty($email)) {
                $preview['validation_messages'][] = __('Missing required field: email', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            }
            if (!is_email($email)) {
                $preview['validation_messages'][] = sprintf(__('Invalid email format: %s', 'hl-core'), $email);
                $preview_rows[] = $preview;
                continue;
            }
            // TODO: Support multi-row same email for different classrooms (spec allows it for
            // mixed-age-group scenarios). Currently rejects as ERROR; needs merge logic to combine
            // classroom assignments into a single enrollment. See STATUS.md Import follow-up.
            if (isset($seen_emails[$email])) {
                $preview['status'] = 'ERROR';
                $preview['validation_messages'][] = sprintf(
                    __('Duplicate email in file (first seen on row %d)', 'hl-core'),
                    $seen_emails[$email] + 1
                );
                $preview_rows[] = $preview;
                continue;
            }
            $seen_emails[$email] = $index;

            // --- Role ---
            $raw_role = isset($row['cycle_roles']) ? trim($row['cycle_roles']) : '';
            $parsed_role = $this->resolve_role($raw_role);
            $preview['parsed_role'] = $parsed_role;

            if (empty($parsed_role)) {
                $preview['validation_messages'][] = sprintf(
                    __('Invalid or missing role: "%s". Valid: Teacher, Mentor, School Leader, District Leader', 'hl-core'),
                    $raw_role
                );
                $preview_rows[] = $preview;
                continue;
            }

            // --- Names (optional with warning) ---
            $first_name = isset($row['first_name']) ? trim($row['first_name']) : '';
            $last_name  = isset($row['last_name']) ? trim($row['last_name']) : '';
            $preview['parsed_first_name'] = $first_name;
            $preview['parsed_last_name']  = $last_name;

            if (empty($first_name) && empty($last_name)) {
                $preview['validation_messages'][] = __('Warning: No first_name or last_name provided. User will be created without a name.', 'hl-core');
            }

            // --- School ---
            $raw_school = isset($row['school_name']) ? trim($row['school_name']) : '';
            if (empty($raw_school) && isset($row['school_code'])) {
                $raw_school = trim($row['school_code']);
            }
            $preview['raw_school'] = $raw_school;

            $matched_school = null;
            if (!empty($raw_school)) {
                $key_name = strtolower($raw_school);
                if (isset($school_by_name[$key_name])) {
                    $matched_school = $school_by_name[$key_name];
                } elseif (isset($school_by_code[$key_name])) {
                    $matched_school = $school_by_code[$key_name];
                }
            }

            $is_district_leader = ($parsed_role === 'district_leader');

            if ($matched_school) {
                $preview['matched_school_id'] = (int) $matched_school->orgunit_id;
            } elseif ($is_district_leader && !empty($raw_school)) {
                // District Leaders with unrecognized school -> warning, not error
                $preview['validation_messages'][] = sprintf(
                    __('Warning: "%s" is not a school in this Partnership. District Leaders may not need a school.', 'hl-core'),
                    $raw_school
                );
            } elseif ($is_district_leader && empty($raw_school)) {
                // District Leader with no school — fine
            } else {
                // Non-DL without valid school -> error
                if (empty($raw_school)) {
                    $preview['validation_messages'][] = __('Missing required field: school', 'hl-core');
                } else {
                    $preview['validation_messages'][] = sprintf(
                        __('School not found in this Partnership: "%s"', 'hl-core'),
                        $raw_school
                    );
                }
                $preview_rows[] = $preview;
                continue;
            }

            // --- Classroom (optional, semicolon-separated) ---
            $raw_classroom = isset($row['classroom_name']) ? trim($row['classroom_name']) : '';
            $preview['parsed_classroom'] = $raw_classroom;

            // --- Age Group (optional) ---
            $raw_age = isset($row['age_group']) ? strtolower(trim($row['age_group'])) : '';
            $valid_ages = array('infant', 'toddler', 'preschool', 'k2', 'mixed', 'preschool/pre-k');
            if (!empty($raw_age)) {
                // Normalize common variants
                if ($raw_age === 'preschool/pre-k' || $raw_age === 'pre-k' || $raw_age === 'prek') {
                    $raw_age = 'preschool';
                }
                if (!in_array($raw_age, array('infant', 'toddler', 'preschool', 'k2', 'mixed'), true)) {
                    $preview['validation_messages'][] = sprintf(
                        __('Warning: Unrecognized age_group "%s". Valid: infant, toddler, preschool, k2, mixed', 'hl-core'),
                        $raw_age
                    );
                    $raw_age = '';
                }
            }
            $preview['parsed_age_group'] = $raw_age;

            // --- Is Primary Teacher (optional) ---
            $raw_primary = isset($row['is_primary_teacher']) ? strtolower(trim($row['is_primary_teacher'])) : '';
            $preview['parsed_is_primary'] = in_array($raw_primary, array('y', 'yes', '1', 'true'), true);
            if (!empty($raw_primary) && !in_array($raw_primary, array('y', 'yes', '1', 'true', 'n', 'no', '0', 'false', ''), true)) {
                $preview['validation_messages'][] = sprintf(
                    __('Warning: Unrecognized is_primary_teacher value "%s". Expected Y/N.', 'hl-core'),
                    $raw_primary
                );
            }

            // --- Team (optional) ---
            $raw_team = isset($row['team']) ? trim($row['team']) : '';
            $preview['parsed_team'] = $raw_team;

            // --- Assigned Mentor (optional) ---
            $raw_mentor = isset($row['assigned_mentor']) ? trim($row['assigned_mentor']) : '';
            if (!empty($raw_mentor) && is_email($raw_mentor)) {
                // Validate mentor exists in file or system
                $mentor_email = HL_Normalization::normalize_email($raw_mentor);
                $mentor_in_file = false;
                foreach ($parsed_rows as $pr) {
                    $pe = isset($pr['email']) ? HL_Normalization::normalize_email($pr['email']) : '';
                    if ($pe === $mentor_email) {
                        $mentor_in_file = true;
                        break;
                    }
                }
                if (!$mentor_in_file && !isset($enrollment_by_email[$mentor_email])) {
                    $preview['validation_messages'][] = sprintf(
                        __('Warning: Assigned mentor "%s" not found in file or existing enrollments.', 'hl-core'),
                        $raw_mentor
                    );
                }
            }

            // --- Assigned Coach (optional) ---
            $raw_coach = isset($row['assigned_coach']) ? trim($row['assigned_coach']) : '';
            $preview['parsed_coach'] = $raw_coach;
            if (!empty($raw_coach)) {
                $coach_email = HL_Normalization::normalize_email($raw_coach);
                if (!empty($coach_email)) {
                    $coach_user = get_user_by('email', $coach_email);
                    if (!$coach_user) {
                        $preview['validation_messages'][] = sprintf(
                            __('Warning: Coach email "%s" not found as a WordPress user.', 'hl-core'),
                            $raw_coach
                        );
                    }
                }
            }

            // --- Language Preference (optional) ---
            $raw_language = isset($row['language']) ? strtolower(trim($row['language'])) : '';
            if (!empty($raw_language) && !in_array($raw_language, array('en', 'es', 'pt'), true)) {
                $preview['validation_messages'][] = sprintf(
                    __('Warning: Unrecognized language "%s". Valid: en, es, pt. Defaulting to en.', 'hl-core'),
                    $raw_language
                );
                $raw_language = 'en';
            }
            $preview['parsed_language'] = !empty($raw_language) ? $raw_language : 'en';

            // --- Pathway (optional) ---
            $raw_pathway = isset($row['pathway']) ? trim($row['pathway']) : '';
            $preview['parsed_pathway'] = $raw_pathway;
            if (!empty($raw_pathway)) {
                $pw_key = strtolower($raw_pathway);
                if (!isset($pathway_by_name[$pw_key]) && !isset($pathway_by_code[$pw_key])) {
                    $msg = sprintf(
                        __('Warning: Pathway "%s" not found in this cycle.', 'hl-core'),
                        $raw_pathway
                    );
                    // Suggest closest match via Levenshtein distance
                    $all_pathway_keys = array_unique(array_merge(array_keys($pathway_by_name), array_keys($pathway_by_code)));
                    if (!empty($all_pathway_keys)) {
                        $best_key  = null;
                        $best_dist = PHP_INT_MAX;
                        foreach ($all_pathway_keys as $option) {
                            $dist = levenshtein($pw_key, $option);
                            if ($dist < $best_dist) {
                                $best_dist = $dist;
                                $best_key  = $option;
                            }
                        }
                        // Only suggest if edit distance is within 40% of input length (minimum threshold of 3)
                        $max_dist = max(3, (int) floor(strlen($pw_key) * 0.4));
                        if ($best_key !== null && $best_dist <= $max_dist) {
                            $suggestion_name = isset($pathway_by_name[$best_key])
                                ? $pathway_by_name[$best_key]['pathway_name']
                                : (isset($pathway_by_code[$best_key]) ? $pathway_by_code[$best_key]['pathway_name'] : $best_key);
                            $msg .= ' ' . sprintf(__('Did you mean "%s"?', 'hl-core'), $suggestion_name);
                        }
                    }
                    $preview['validation_messages'][] = $msg;
                }
            }

            // --- Determine Status ---
            $has_errors = false;
            foreach ($preview['validation_messages'] as $msg) {
                if (strpos($msg, 'Warning:') === false) {
                    $has_errors = true;
                    break;
                }
            }

            if ($has_errors) {
                $preview['status'] = 'ERROR';
            } elseif (isset($enrollment_by_email[$email])) {
                $existing = $enrollment_by_email[$email];
                $preview['existing_enrollment_id'] = (int) $existing['enrollment_id'];
                $preview['matched_user_id'] = (int) $existing['user_id'];

                // Check if data differs
                $existing_roles = HL_Roles::parse_stored($existing['roles']);
                $existing_roles_normalized = array_map(function($r) {
                    return strtolower(str_replace(' ', '_', trim($r)));
                }, $existing_roles);
                $role_changed   = !in_array($parsed_role, $existing_roles_normalized, true);
                $school_changed = $preview['matched_school_id'] && (int) $existing['school_id'] !== $preview['matched_school_id'];
                $existing_lang  = !empty($existing['language_preference']) ? $existing['language_preference'] : 'en';
                $language_changed = $preview['parsed_language'] !== $existing_lang;

                // Check if CSV has side-effect data (classroom, team, pathway, coach)
                $has_side_effects = !empty($raw_classroom) || !empty($raw_team) || !empty($raw_pathway) || !empty($raw_coach);

                if ($role_changed || $school_changed || $language_changed) {
                    $preview['status'] = 'UPDATE';
                    $preview['role_changed'] = $role_changed;
                    $preview['proposed_actions'][] = __('Update enrollment (role, school, or language change)', 'hl-core');
                    if ($language_changed) {
                        $preview['validation_messages'][] = sprintf(
                            __('Language: %s → %s', 'hl-core'),
                            $existing_lang, $preview['parsed_language']
                        );
                    }
                    $preview['selected'] = true;
                } elseif ($has_side_effects) {
                    $preview['status'] = 'UPDATE';
                    $preview['proposed_actions'][] = __('Enrollment exists. Will process classroom, team, pathway, or coach assignments.', 'hl-core');
                    $preview['selected'] = true;
                } else {
                    $preview['status'] = 'SKIP';
                    $preview['proposed_actions'][] = __('Already enrolled with identical data', 'hl-core');
                }
            } else {
                // Check if WP user exists
                $wp_user = get_user_by('email', $email);
                if ($wp_user) {
                    $preview['matched_user_id'] = $wp_user->ID;
                    $preview['proposed_actions'][] = __('User exists. Create enrollment in this cycle.', 'hl-core');
                } else {
                    $preview['proposed_actions'][] = __('Create new WordPress user and enrollment.', 'hl-core');
                }
                $preview['status'] = 'CREATE';
                $preview['selected'] = true;
            }

            // --- Auto-route pathway if not provided in CSV ---
            $preview['pathway_source'] = '';
            if (!empty($raw_pathway)) {
                $preview['pathway_source'] = 'csv';
            } elseif (!$has_errors && $preview['status'] !== 'SKIP') {
                $routed_user_id = $preview['matched_user_id'] ? (int) $preview['matched_user_id'] : null;
                $routed_pathway_id = HL_Pathway_Routing_Service::resolve_pathway($routed_user_id, $parsed_role, $cycle_id);
                if ($routed_pathway_id) {
                    $routed_pw = $wpdb->get_row($wpdb->prepare(
                        "SELECT pathway_name, pathway_code FROM {$prefix}hl_pathway WHERE pathway_id = %d",
                        $routed_pathway_id
                    ));
                    if ($routed_pw) {
                        $preview['parsed_pathway'] = $routed_pw->pathway_name;
                        $preview['routed_pathway_id'] = $routed_pathway_id;
                        $preview['pathway_source'] = $routed_user_id ? 'routed' : 'default';
                        $preview['proposed_actions'][] = sprintf(
                            __('Pathway: %s (%s)', 'hl-core'),
                            $routed_pw->pathway_name,
                            $routed_user_id ? __('auto-routed based on course history', 'hl-core') : __('default for new participants', 'hl-core')
                        );
                    }
                } else {
                    $preview['validation_messages'][] = sprintf(
                        __('Warning: No pathway auto-assigned for role "%s". Ensure pathways exist in this cycle or assign manually via CSV.', 'hl-core'),
                        $parsed_role
                    );
                    $preview['proposed_actions'][] = __('Pathway: none (auto-routing found no match)', 'hl-core');
                }
            }

            // Add proposed actions for side effects
            if (!empty($raw_classroom) && !$has_errors) {
                $classrooms = array_map('trim', explode(';', $raw_classroom));
                foreach ($classrooms as $cn) {
                    if (empty($cn)) continue;
                    $preview['proposed_actions'][] = sprintf(__('Classroom: %s (create if needed + teaching assignment)', 'hl-core'), $cn);
                }
            }
            if (!empty($raw_team) && !$has_errors) {
                $membership_type = ($parsed_role === 'mentor') ? 'mentor' : 'member';
                $preview['proposed_actions'][] = sprintf(__('Team: %s (create if needed, as %s)', 'hl-core'), $raw_team, $membership_type);
            }
            if (!empty($raw_pathway) && !$has_errors) {
                $preview['proposed_actions'][] = sprintf(__('Pathway: %s', 'hl-core'), $raw_pathway);
            }
            if (!empty($raw_coach) && !$has_errors && $parsed_role === 'mentor') {
                $preview['proposed_actions'][] = sprintf(__('Coach assignment: %s', 'hl-core'), $raw_coach);
            }

            // Mark as WARNING if only warnings exist
            if ($preview['status'] === 'ERROR' && !$has_errors && !empty($preview['validation_messages'])) {
                $preview['status'] = 'WARNING';
                $preview['selected'] = true;
            }

            $preview_rows[] = $preview;
        }

        return $preview_rows;
    }

    /**
     * Resolve a raw role string to a canonical role name.
     *
     * @param string $raw
     * @return string Canonical role or empty string if invalid.
     */
    private function resolve_role($raw) {
        $normalized = strtolower(trim($raw));
        $synonyms = array(
            'teacher'         => 'teacher',
            'maestro'         => 'teacher',
            'maestra'         => 'teacher',
            'mentor'          => 'mentor',
            'school leader'   => 'school_leader',
            'school_leader'   => 'school_leader',
            'lider de centro' => 'school_leader',
            'director'        => 'school_leader',
            'district leader' => 'district_leader',
            'district_leader' => 'district_leader',
        );
        return isset($synonyms[$normalized]) ? $synonyms[$normalized] : '';
    }

    /**
     * Commit selected participant rows. All-or-nothing transaction.
     *
     * @param int   $run_id
     * @param int[] $selected_row_indices
     * @return array Results summary.
     */
    public function commit($run_id, $selected_row_indices) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $import_service = $this->import_service;
        $run = $import_service->get_preview($run_id);
        if (!$run || $run['status'] !== 'preview') {
            return $this->error_result(__('Invalid import run or already committed.', 'hl-core'));
        }

        $preview_rows = $run['preview_data'];
        $cycle_id     = (int) $run['cycle_id'];
        $selected_set = array_flip($selected_row_indices);

        $team_service     = new HL_Team_Service();
        $pathway_service  = new HL_Pathway_Assignment_Service();
        $classroom_service = new HL_Classroom_Service();

        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = array();

        // Collect rows to process
        $rows_to_process = array();
        foreach ($preview_rows as $row) {
            if (!isset($selected_set[$row['row_index']])) {
                $skipped++;
                continue;
            }
            if ($row['status'] === 'ERROR') {
                $skipped++;
                continue;
            }
            $rows_to_process[] = $row;
        }

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Pre-load lookups
            $partnership_schools = $import_service->load_partnership_schools($cycle_id);
            $school_by_name = array();
            foreach ($partnership_schools as $s) {
                $school_by_name[strtolower(trim($s->name))] = $s;
            }

            // Load pathways
            $pathways = $wpdb->get_results($wpdb->prepare(
                "SELECT pathway_id, pathway_name, pathway_code FROM {$prefix}hl_pathway WHERE cycle_id = %d AND active_status = 1",
                $cycle_id
            ), ARRAY_A) ?: array();
            $pathway_by_name = array();
            $pathway_by_code = array();
            $valid_pathway_ids = array();
            foreach ($pathways as $p) {
                $pathway_by_name[strtolower(trim($p['pathway_name']))] = $p;
                $valid_pathway_ids[(int) $p['pathway_id']] = true;
                if (!empty($p['pathway_code'])) {
                    $pathway_by_code[strtolower(trim($p['pathway_code']))] = $p;
                }
            }

            // Track created teams to avoid duplicates within this import
            $created_teams = array(); // "school_id|team_name_lower" => team_id

            HL_BB_Group_Sync_Service::begin_bulk();
            try {

            foreach ($rows_to_process as $row) {
                $email      = $row['parsed_email'];
                $role       = $row['parsed_role'];
                $school_id  = isset($row['matched_school_id']) ? (int) $row['matched_school_id'] : null;

                // 1. Create/find WordPress user
                $wp_user = get_user_by('email', $email);
                if (!$wp_user) {
                    $user_id = wp_create_user($email, wp_generate_password(), $email);
                    if (is_wp_error($user_id)) {
                        throw new Exception(sprintf('Failed to create user %s: %s', $email, $user_id->get_error_message()));
                    }
                    // Set names if provided
                    $update_data = array('ID' => $user_id);
                    if (!empty($row['parsed_first_name'])) {
                        $update_data['first_name'] = $row['parsed_first_name'];
                    }
                    if (!empty($row['parsed_last_name'])) {
                        $update_data['last_name'] = $row['parsed_last_name'];
                    }
                    if (!empty($row['parsed_first_name']) || !empty($row['parsed_last_name'])) {
                        $update_data['display_name'] = trim($row['parsed_first_name'] . ' ' . $row['parsed_last_name']);
                        wp_update_user($update_data);
                    }

                    HL_Audit_Service::log('import_user_created', array(
                        'cycle_id'    => $cycle_id,
                        'entity_type' => 'user',
                        'entity_id'   => $user_id,
                        'reason'      => sprintf('User created via import: %s', $email),
                    ));
                } else {
                    $user_id = $wp_user->ID;
                }

                // 2. Create/update enrollment
                $enrollment_repo = new HL_Enrollment_Repository();

                if ($row['status'] === 'CREATE') {
                    $roles = array($role);
                    $enrollment_data = array(
                        'cycle_id'            => $cycle_id,
                        'user_id'             => $user_id,
                        'roles'               => class_exists('HL_Roles') ? HL_Roles::sanitize_roles($roles) : wp_json_encode($roles),
                        'school_id'           => $school_id,
                        'status'              => 'active',
                        'language_preference' => !empty($row['parsed_language']) ? $row['parsed_language'] : 'en',
                    );
                    $enrollment_id = $enrollment_repo->create($enrollment_data);
                    if (!$enrollment_id) {
                        throw new Exception(sprintf('Failed to create enrollment for %s', $email));
                    }

                    do_action('hl_enrollment_created', $enrollment_id, $enrollment_data);

                    HL_Audit_Service::log('import_enrollment_created', array(
                        'cycle_id'    => $cycle_id,
                        'entity_type' => 'enrollment',
                        'entity_id'   => $enrollment_id,
                        'reason'      => sprintf('Enrollment created via import: %s as %s', $email, $role),
                    ));

                    $created++;
                } elseif ($row['status'] === 'UPDATE' || $row['status'] === 'WARNING') {
                    $enrollment_id = (int) $row['existing_enrollment_id'];
                    $roles = array($role);
                    $update_data = array(
                        'roles'               => class_exists('HL_Roles') ? HL_Roles::sanitize_roles($roles) : wp_json_encode($roles),
                        'school_id'           => $school_id,
                        'language_preference' => !empty($row['parsed_language']) ? $row['parsed_language'] : 'en',
                    );
                    $enrollment_service = new HL_Enrollment_Service();
                    $enrollment_service->update_enrollment($enrollment_id, $update_data);

                    HL_Audit_Service::log('import_enrollment_updated', array(
                        'cycle_id'    => $cycle_id,
                        'entity_type' => 'enrollment',
                        'entity_id'   => $enrollment_id,
                        'reason'      => sprintf('Enrollment updated via import: %s to %s', $email, $role),
                    ));

                    $updated++;
                } else {
                    // SKIP — get enrollment_id for side effects
                    $enrollment_id = (int) $row['existing_enrollment_id'];
                    if (!$enrollment_id) {
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT enrollment_id FROM {$prefix}hl_enrollment WHERE cycle_id = %d AND user_id = %d",
                            $cycle_id, $user_id
                        ));
                        $enrollment_id = (int) $existing;
                    }
                    $skipped++;
                }

                // 3. Classrooms + Teaching Assignments
                if (!empty($row['parsed_classroom']) && $school_id) {
                    $classroom_names = array_map('trim', explode(';', $row['parsed_classroom']));
                    foreach ($classroom_names as $cn) {
                        if (empty($cn)) continue;

                        // Find or create classroom
                        $classroom = $wpdb->get_row($wpdb->prepare(
                            "SELECT classroom_id FROM {$prefix}hl_classroom WHERE school_id = %d AND classroom_name = %s AND cycle_id = %d",
                            $school_id, $cn, $cycle_id
                        ));

                        if ($classroom) {
                            $classroom_id = (int) $classroom->classroom_id;
                        } else {
                            $classroom_data = array(
                                'school_id'      => $school_id,
                                'classroom_name' => $cn,
                                'cycle_id'       => $cycle_id,
                            );
                            if (!empty($row['parsed_age_group'])) {
                                $classroom_data['age_band'] = $row['parsed_age_group'];
                            }
                            $classroom_id = $classroom_service->create_classroom($classroom_data);
                            if (is_wp_error($classroom_id)) {
                                throw new Exception(sprintf('Failed to create classroom "%s": %s', $cn, $classroom_id->get_error_message()));
                            }

                            HL_Audit_Service::log('import_classroom_created', array(
                                'cycle_id'    => $cycle_id,
                                'entity_type' => 'classroom',
                                'entity_id'   => $classroom_id,
                                'reason'      => sprintf('Classroom created via import: %s at school %d', $cn, $school_id),
                            ));
                        }

                        // Create teaching assignment (skip if exists)
                        $existing_ta = $wpdb->get_var($wpdb->prepare(
                            "SELECT assignment_id FROM {$prefix}hl_teaching_assignment WHERE enrollment_id = %d AND classroom_id = %d",
                            $enrollment_id, $classroom_id
                        ));
                        if (!$existing_ta) {
                            $ta_data = array(
                                'enrollment_id'  => $enrollment_id,
                                'classroom_id'   => $classroom_id,
                                'is_lead_teacher' => $row['parsed_is_primary'] ? 1 : 0,
                            );
                            $classroom_service->create_teaching_assignment($ta_data);

                            HL_Audit_Service::log('import_teaching_assignment_created', array(
                                'cycle_id'    => $cycle_id,
                                'entity_type' => 'teaching_assignment',
                                'entity_id'   => $enrollment_id,
                                'reason'      => sprintf('Teaching assignment created via import: enrollment %d, classroom %d', $enrollment_id, $classroom_id),
                            ));
                        }
                    }
                }

                // 4. Teams + Memberships
                if (!empty($row['parsed_team']) && $school_id) {
                    $team_name = trim($row['parsed_team']);
                    $team_key  = $school_id . '|' . strtolower($team_name);

                    // Check in-memory cache first
                    if (isset($created_teams[$team_key])) {
                        $team_id = $created_teams[$team_key];
                    } else {
                        // Check DB
                        $existing_team = $wpdb->get_var($wpdb->prepare(
                            "SELECT team_id FROM {$prefix}hl_team WHERE cycle_id = %d AND school_id = %d AND LOWER(team_name) = %s AND status = 'active'",
                            $cycle_id, $school_id, strtolower($team_name)
                        ));
                        if ($existing_team) {
                            $team_id = (int) $existing_team;
                        } else {
                            $team_id = $team_service->create_team(array(
                                'cycle_id'  => $cycle_id,
                                'school_id' => $school_id,
                                'team_name' => $team_name,
                            ));
                            if (is_wp_error($team_id)) {
                                throw new Exception(sprintf('Failed to create team "%s": %s', $team_name, $team_id->get_error_message()));
                            }

                            HL_Audit_Service::log('import_team_created', array(
                                'cycle_id'    => $cycle_id,
                                'entity_type' => 'team',
                                'entity_id'   => $team_id,
                                'reason'      => sprintf('Team created via import: %s at school %d', $team_name, $school_id),
                            ));
                        }
                        $created_teams[$team_key] = $team_id;
                    }

                    // Add membership
                    $membership_type = ($role === 'mentor') ? 'mentor' : 'member';
                    $result = $team_service->add_member($team_id, $enrollment_id, $membership_type);
                    if (is_wp_error($result) && $result->get_error_code() !== 'already_member') {
                        // already_member is OK (idempotent), other errors are real
                        if ($result->get_error_code() !== 'one_team_per_cycle') {
                            throw new Exception(sprintf('Failed to add team member: %s', $result->get_error_message()));
                        }
                    }
                }

                // 5. Coach Assignment (Mentors only)
                if (!empty($row['parsed_coach']) && $role === 'mentor') {
                    $coach_email = HL_Normalization::normalize_email($row['parsed_coach']);
                    $coach_user = get_user_by('email', $coach_email);
                    if ($coach_user) {
                        // Check if assignment already exists
                        $existing_ca = $wpdb->get_var($wpdb->prepare(
                            "SELECT coach_assignment_id FROM {$prefix}hl_coach_assignment
                             WHERE coach_user_id = %d AND scope_type = 'enrollment' AND scope_id = %d AND cycle_id = %d",
                            $coach_user->ID, $enrollment_id, $cycle_id
                        ));
                        if (!$existing_ca) {
                            $wpdb->insert($prefix . 'hl_coach_assignment', array(
                                'coach_user_id' => $coach_user->ID,
                                'scope_type'    => 'enrollment',
                                'scope_id'      => $enrollment_id,
                                'cycle_id'      => $cycle_id,
                                'effective_from' => current_time('Y-m-d'),
                            ));

                            HL_Audit_Service::log('import_coach_assigned', array(
                                'cycle_id'    => $cycle_id,
                                'entity_type' => 'coach_assignment',
                                'entity_id'   => $enrollment_id,
                                'reason'      => sprintf('Coach %s assigned to mentor enrollment %d via import', $coach_email, $enrollment_id),
                            ));
                        }
                    }
                }

                // 6. Pathway Assignment
                // On UPDATE with role change: clear stale pathways first
                if (!empty($row['role_changed'])) {
                    $wpdb->delete($prefix . 'hl_pathway_assignment', array('enrollment_id' => $enrollment_id));
                    $wpdb->update(
                        $prefix . 'hl_enrollment',
                        array('assigned_pathway_id' => null),
                        array('enrollment_id' => $enrollment_id)
                    );
                }

                // Pathway priority: explicit CSV > routed from validate > resolve at commit time
                $pathway_source = isset($row['pathway_source']) ? $row['pathway_source'] : '';
                if ($pathway_source === 'csv' && !empty($row['parsed_pathway'])) {
                    $pw_key = strtolower(trim($row['parsed_pathway']));
                    $matched_pw = isset($pathway_by_name[$pw_key]) ? $pathway_by_name[$pw_key]
                        : (isset($pathway_by_code[$pw_key]) ? $pathway_by_code[$pw_key] : null);
                    if ($matched_pw) {
                        $pw_result = $pathway_service->assign_pathway($enrollment_id, (int) $matched_pw['pathway_id'], 'explicit');
                        if (is_wp_error($pw_result)) {
                            error_log(sprintf('[HL Import] assign_pathway failed: enrollment=%d, pathway=%d, error=%s', $enrollment_id, (int) $matched_pw['pathway_id'], $pw_result->get_error_message()));
                        }
                    }
                } elseif (in_array($row['status'], array('CREATE', 'UPDATE', 'WARNING'), true) || !empty($row['role_changed'])) {
                    if (!empty($row['routed_pathway_id']) && isset($valid_pathway_ids[(int) $row['routed_pathway_id']])) {
                        $pw_result = $pathway_service->assign_pathway($enrollment_id, (int) $row['routed_pathway_id'], 'role_default');
                        if (is_wp_error($pw_result)) {
                            error_log(sprintf('[HL Import] assign_pathway failed: enrollment=%d, pathway=%d, error=%s', $enrollment_id, (int) $row['routed_pathway_id'], $pw_result->get_error_message()));
                        }
                    } else {
                        $routed_id = HL_Pathway_Routing_Service::resolve_pathway($user_id, $role, $cycle_id);
                        if ($routed_id) {
                            $pw_result = $pathway_service->assign_pathway($enrollment_id, $routed_id, 'role_default');
                            if (is_wp_error($pw_result)) {
                                error_log(sprintf('[HL Import] assign_pathway failed: enrollment=%d, pathway=%d, error=%s', $enrollment_id, $routed_id, $pw_result->get_error_message()));
                            }
                        } else {
                            error_log(sprintf(
                                '[HL Import] Pathway routing returned null at commit: user=%d, role=%s, cycle=%d, email=%s',
                                $user_id, $role, $cycle_id, $email
                            ));
                        }
                    }
                }
            }

            } finally {
                HL_BB_Group_Sync_Service::end_bulk();
            }

            $wpdb->query('COMMIT');

            // Update import run
            $import_service->update_run_status($run_id, 'committed', array(
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'error_count'   => count($errors),
                'errors'        => $errors,
            ));

            HL_Audit_Service::log('import_committed', array(
                'cycle_id'    => $cycle_id,
                'entity_type' => 'import_run',
                'entity_id'   => $run_id,
                'reason'      => sprintf('Participant import committed: %d created, %d updated, %d skipped', $created, $updated, $skipped),
            ));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');

            $errors[] = array(
                'row_index' => null,
                'email'     => '',
                'message'   => $e->getMessage(),
            );

            $import_service->update_run_status($run_id, 'failed', array(
                'created_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'error_count'   => 1,
                'errors'        => $errors,
            ));

            return array(
                'created_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'error_count'   => 1,
                'errors'        => $errors,
            );
        }

        return array(
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'error_count'   => count($errors),
            'errors'        => $errors,
        );
    }

    /**
     * Helper: build error result array.
     */
    private function error_result($message) {
        return array(
            'created_count' => 0, 'updated_count' => 0,
            'skipped_count' => 0, 'error_count'   => 1,
            'errors' => array(array('message' => $message)),
        );
    }
}
