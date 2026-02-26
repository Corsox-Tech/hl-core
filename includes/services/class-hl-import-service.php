<?php
if (!defined('ABSPATH')) exit;

class HL_Import_Service {

    /** @var array Column header synonyms → canonical name */
    private static $header_synonyms = array(
        'email'          => 'email',
        'email_address'  => 'email',
        'e_mail'         => 'email',
        'correo'         => 'email',

        'track_roles'   => 'track_roles',
        'track_role'    => 'track_roles',
        'role'           => 'track_roles',
        'roles'          => 'track_roles',
        'rol'            => 'track_roles',

        'school_name'    => 'school_name',
        'school'         => 'school_name',
        'centro'         => 'school_name',

        'school_code'    => 'school_code',

        'district_name'  => 'district_name',
        'district'       => 'district_name',
        'distrito'       => 'district_name',

        'district_code'  => 'district_code',

        'first_name'     => 'first_name',
        'firstname'      => 'first_name',
        'first'          => 'first_name',
        'nombre'         => 'first_name',

        'last_name'      => 'last_name',
        'lastname'       => 'last_name',
        'last'           => 'last_name',
        'apellido'       => 'last_name',

        // Children import
        'date_of_birth'      => 'date_of_birth',
        'dob'                => 'date_of_birth',
        'birth_date'         => 'date_of_birth',
        'child_identifier'   => 'child_identifier',
        'child_id'           => 'child_identifier',
        'external_id'        => 'child_identifier',

        // Classroom import
        'classroom_name'     => 'classroom_name',
        'classroom'          => 'classroom_name',
        'room'               => 'classroom_name',
        'age_band'           => 'age_band',

        // Teaching assignment import
        'is_lead_teacher'    => 'is_lead_teacher',
        'lead_teacher'       => 'is_lead_teacher',
        'lead'               => 'is_lead_teacher',
    );

    /** @var array Role synonyms → canonical role name */
    private static $role_synonyms = array(
        'teacher'         => 'Teacher',
        'maestro'         => 'Teacher',
        'maestra'         => 'Teacher',
        'mentor'          => 'Mentor',
        'school leader'   => 'School Leader',
        'school_leader'   => 'School Leader',
        'lider de centro' => 'School Leader',
        'district leader' => 'District Leader',
        'district_leader' => 'District Leader',
    );

    /** @var string[] Valid track roles */
    private static $valid_roles = array('Teacher', 'Mentor', 'School Leader', 'District Leader');

    /** @var int Maximum rows per import */
    const MAX_ROWS = 5000;

    /** @var int Maximum file size in bytes (2MB) */
    const MAX_FILE_SIZE = 2097152;

    /**
     * Get import runs for a track
     */
    public function get_runs($track_id = null) {
        global $wpdb;
        $sql = "SELECT ir.*, u.display_name AS actor_name, t.track_name
                FROM {$wpdb->prefix}hl_import_run ir
                LEFT JOIN {$wpdb->users} u ON ir.actor_user_id = u.ID
                LEFT JOIN {$wpdb->prefix}hl_track t ON ir.track_id = t.track_id";
        if ($track_id) {
            $sql = $wpdb->prepare($sql . " WHERE ir.track_id = %d", $track_id);
        }
        $sql .= " ORDER BY ir.created_at DESC LIMIT 50";
        return $wpdb->get_results($sql, ARRAY_A) ?: array();
    }

    /**
     * Create a new import run record
     *
     * @param int    $track_id
     * @param string $import_type
     * @param string $file_name
     * @return int run_id
     */
    public function create_run($track_id, $import_type, $file_name) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'hl_import_run', array(
            'run_uuid'      => HL_DB_Utils::generate_uuid(),
            'actor_user_id' => get_current_user_id(),
            'track_id'     => $track_id,
            'import_type'   => $import_type,
            'file_name'     => $file_name,
            'status'        => 'preview',
        ));
        return $wpdb->insert_id;
    }

    /**
     * Parse a CSV file into headers and rows
     *
     * @param string $file_path Absolute path to CSV file
     * @return array{headers: string[], rows: array[], raw_headers: string[]}|WP_Error
     */
    public function parse_csv($file_path) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return new WP_Error('file_not_found', __('The uploaded file could not be read.', 'hl-core'));
        }

        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return new WP_Error('file_open_failed', __('Failed to open the uploaded file.', 'hl-core'));
        }

        // Read and strip BOM from first line
        $header_line = fgets($handle);
        if ($header_line === false) {
            fclose($handle);
            return new WP_Error('empty_file', __('The uploaded file is empty.', 'hl-core'));
        }

        // Strip UTF-8 BOM
        $header_line = preg_replace('/^\xEF\xBB\xBF/', '', $header_line);

        // Detect delimiter
        $delimiter = $this->detect_delimiter($header_line);

        // Parse header row
        rewind($handle);
        // Re-read skipping BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $raw_headers = fgetcsv($handle, 0, $delimiter);
        if ($raw_headers === false || empty($raw_headers)) {
            fclose($handle);
            return new WP_Error('no_headers', __('Could not parse CSV headers.', 'hl-core'));
        }

        // Normalize headers
        $raw_headers = array_map('trim', $raw_headers);
        $normalized_headers = array_map(function($h) {
            $h = strtolower(trim($h));
            $h = preg_replace('/[\s\-]+/', '_', $h);
            return $h;
        }, $raw_headers);

        // Map headers
        $mapping = $this->map_column_headers($normalized_headers);

        // Parse data rows
        $rows = array();
        $row_count = 0;
        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            // Skip completely empty rows
            if (count($cells) === 1 && trim($cells[0]) === '') {
                continue;
            }

            $row_count++;
            if ($row_count > self::MAX_ROWS) {
                fclose($handle);
                return new WP_Error('too_many_rows', sprintf(
                    __('The file contains more than %d rows. Please split into smaller files.', 'hl-core'),
                    self::MAX_ROWS
                ));
            }

            // Build associative row using mapped headers
            $row = array();
            foreach ($mapping['mapped'] as $index => $canonical_name) {
                $row[$canonical_name] = isset($cells[$index]) ? trim($cells[$index]) : '';
            }
            $rows[] = $row;
        }

        fclose($handle);

        if (empty($rows)) {
            return new WP_Error('no_data_rows', __('The file contains headers but no data rows.', 'hl-core'));
        }

        return array(
            'headers'     => $mapping['mapped'],
            'raw_headers' => $raw_headers,
            'unmapped'    => $mapping['unmapped'],
            'rows'        => $rows,
        );
    }

    /**
     * Map raw normalized headers to canonical column names
     *
     * @param string[] $normalized_headers
     * @return array{mapped: array, unmapped: string[]}
     */
    private function map_column_headers($normalized_headers) {
        $mapped = array();   // index => canonical_name
        $unmapped = array(); // headers that couldn't be matched

        foreach ($normalized_headers as $index => $header) {
            if (isset(self::$header_synonyms[$header])) {
                $mapped[$index] = self::$header_synonyms[$header];
            } else {
                $unmapped[] = $header;
            }
        }

        return array('mapped' => $mapped, 'unmapped' => $unmapped);
    }

    /**
     * Validate and match participant rows against database
     *
     * @param array $parsed_rows Array of associative arrays
     * @param int   $track_id
     * @return array Preview rows
     */
    public function validate_participant_rows($parsed_rows, $track_id) {
        $enrollment_repo = new HL_Enrollment_Repository();
        $preview_rows = array();
        $seen_emails = array();

        // Pre-load schools and districts for matching
        $schools = $this->load_schools_lookup();
        $districts = $this->load_districts_lookup();

        foreach ($parsed_rows as $index => $row) {
            $preview = array(
                'row_index'              => $index,
                'raw_data'               => $row,
                'status'                 => 'ERROR',
                'matched_user_id'        => null,
                'matched_school_id'      => null,
                'matched_district_id'    => null,
                'existing_enrollment_id' => null,
                'validation_messages'    => array(),
                'proposed_actions'       => array(),
                'parsed_email'           => '',
                'parsed_roles'           => array(),
                'parsed_first_name'      => '',
                'parsed_last_name'       => '',
                'selected'               => false,
            );

            // Extract and normalize email
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

            // Check for duplicate email within file
            if (isset($seen_emails[$email])) {
                $preview['status'] = 'NEEDS_REVIEW';
                $preview['validation_messages'][] = sprintf(
                    __('Duplicate email in file (first seen on row %d)', 'hl-core'),
                    $seen_emails[$email] + 1
                );
                $preview_rows[] = $preview;
                continue;
            }
            $seen_emails[$email] = $index;

            // Parse and validate roles
            $raw_roles = isset($row['track_roles']) ? $row['track_roles'] : '';
            if (empty($raw_roles)) {
                $preview['validation_messages'][] = __('Missing required field: track_roles', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            }

            $parsed_roles = $this->parse_roles($raw_roles);
            $role_validation = $this->validate_roles($parsed_roles);
            $preview['parsed_roles'] = $role_validation['valid'];

            if (!empty($role_validation['invalid'])) {
                $preview['status'] = 'NEEDS_REVIEW';
                $preview['validation_messages'][] = sprintf(
                    __('Unrecognized role(s): %s', 'hl-core'),
                    implode(', ', $role_validation['invalid'])
                );
            }

            if (empty($role_validation['valid'])) {
                $preview['status'] = 'ERROR';
                $preview['validation_messages'][] = __('No valid roles found after parsing.', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            }

            // Parse names
            $preview['parsed_first_name'] = isset($row['first_name']) ? trim($row['first_name']) : '';
            $preview['parsed_last_name']  = isset($row['last_name']) ? trim($row['last_name']) : '';

            // Match district (optional)
            $district_value = '';
            if (!empty($row['district_code'])) {
                $district_value = trim($row['district_code']);
                $district = $this->match_district($district_value, $districts, 'code');
            } elseif (!empty($row['district_name'])) {
                $district_value = trim($row['district_name']);
                $district = $this->match_district($district_value, $districts, 'name');
            } else {
                $district = null;
            }

            if ($district) {
                $preview['matched_district_id'] = $district->orgunit_id;
            } elseif (!empty($district_value)) {
                $preview['validation_messages'][] = sprintf(
                    __('District not found: %s', 'hl-core'),
                    $district_value
                );
            }

            // Match school (required)
            $school_value = '';
            if (!empty($row['school_code'])) {
                $school_value = trim($row['school_code']);
                $school = $this->match_school($school_value, $schools, 'code', $preview['matched_district_id']);
            } elseif (!empty($row['school_name'])) {
                $school_value = trim($row['school_name']);
                $school = $this->match_school($school_value, $schools, 'name', $preview['matched_district_id']);
            } else {
                $school = null;
            }

            if ($school) {
                $preview['matched_school_id'] = $school->orgunit_id;
            } elseif (empty($school_value)) {
                $preview['validation_messages'][] = __('Missing required field: school_name or school_code', 'hl-core');
                $preview['status'] = 'ERROR';
                $preview_rows[] = $preview;
                continue;
            } else {
                $preview['validation_messages'][] = sprintf(
                    __('School not found: %s', 'hl-core'),
                    $school_value
                );
                $preview['status'] = 'ERROR';
                $preview_rows[] = $preview;
                continue;
            }

            // Match user by email
            $wp_user = get_user_by('email', $email);

            if ($wp_user) {
                $preview['matched_user_id'] = $wp_user->ID;

                // Check existing enrollment
                $existing = $enrollment_repo->get_by_track_and_user($track_id, $wp_user->ID);

                if ($existing) {
                    $preview['existing_enrollment_id'] = $existing->enrollment_id;
                    $existing_roles = $existing->get_roles_array();

                    // Compare roles
                    $new_roles = $role_validation['valid'];
                    sort($existing_roles);
                    sort($new_roles);

                    if ($existing_roles === $new_roles
                        && (int) $existing->school_id === (int) $preview['matched_school_id']) {
                        $preview['status'] = 'SKIP';
                        $preview['validation_messages'][] = __('Already enrolled with identical roles and school.', 'hl-core');
                        $preview['selected'] = false;
                    } else {
                        $preview['status'] = 'UPDATE';
                        $changes = array();
                        if ($existing_roles !== $new_roles) {
                            $changes[] = sprintf('roles: [%s] → [%s]',
                                implode(', ', $existing_roles),
                                implode(', ', $new_roles)
                            );
                        }
                        if ((int) $existing->school_id !== (int) $preview['matched_school_id']) {
                            $changes[] = 'school updated';
                        }
                        $preview['proposed_actions'][] = sprintf(
                            __('Update enrollment: %s', 'hl-core'),
                            implode('; ', $changes)
                        );
                        $preview['selected'] = true;
                    }
                } else {
                    // User exists but not enrolled
                    $preview['status'] = 'CREATE';
                    $preview['proposed_actions'][] = sprintf(
                        __('Enroll existing user (%s) into track', 'hl-core'),
                        $wp_user->display_name
                    );
                    $preview['selected'] = true;
                }
            } else {
                // New user
                $preview['status'] = 'CREATE';
                $preview['proposed_actions'][] = sprintf(
                    __('Create WP user (%s)', 'hl-core'),
                    $email
                );
                $preview['proposed_actions'][] = __('Enroll into track', 'hl-core');
                $preview['selected'] = true;
            }

            // Override status if there were NEEDS_REVIEW messages set earlier (e.g. bad roles)
            if ($preview['status'] !== 'ERROR'
                && $preview['status'] !== 'SKIP'
                && !empty($preview['validation_messages'])
                && $preview['status'] !== 'NEEDS_REVIEW') {
                // Keep CREATE/UPDATE but add warnings
            }

            $preview_rows[] = $preview;
        }

        return $preview_rows;
    }

    // =========================================================================
    // Children Import
    // =========================================================================

    /**
     * Validate and match children rows against database
     *
     * @param array $parsed_rows Array of associative arrays
     * @param int   $track_id   (used for context; children belong to schools, not tracks directly)
     * @return array Preview rows
     */
    public function validate_children_rows($parsed_rows, $track_id) {
        $child_repo = new HL_Child_Repository();
        $preview_rows = array();

        // Pre-load schools for matching
        $schools = $this->load_schools_lookup();
        // Pre-load all classrooms for matching
        $classrooms_by_school = $this->load_classrooms_by_school();

        foreach ($parsed_rows as $index => $row) {
            $preview = array(
                'row_index'           => $index,
                'raw_data'            => $row,
                'status'              => 'ERROR',
                'matched_school_id'   => null,
                'matched_classroom_id' => null,
                'matched_child_id'    => null,
                'validation_messages' => array(),
                'proposed_actions'    => array(),
                'parsed_first_name'   => '',
                'parsed_last_name'    => '',
                'parsed_dob'          => '',
                'parsed_child_identifier' => '',
                'parsed_classroom_name'   => '',
                'selected'            => false,
            );

            // Parse names
            $first_name = isset($row['first_name']) ? trim($row['first_name']) : '';
            $last_name  = isset($row['last_name']) ? trim($row['last_name']) : '';
            $preview['parsed_first_name'] = $first_name;
            $preview['parsed_last_name']  = $last_name;

            if (empty($first_name) && empty($last_name)) {
                $preview['validation_messages'][] = __('Missing required field: first_name or last_name', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            }

            // Parse date of birth (optional)
            $raw_dob = isset($row['date_of_birth']) ? trim($row['date_of_birth']) : '';
            $parsed_dob = '';
            if (!empty($raw_dob)) {
                $ts = strtotime($raw_dob);
                if ($ts !== false) {
                    $parsed_dob = date('Y-m-d', $ts);
                } else {
                    $preview['validation_messages'][] = sprintf(
                        __('Invalid date format for date_of_birth: %s', 'hl-core'),
                        $raw_dob
                    );
                }
            }
            $preview['parsed_dob'] = $parsed_dob;

            // Parse child identifier (optional)
            $preview['parsed_child_identifier'] = isset($row['child_identifier']) ? trim($row['child_identifier']) : '';

            // Parse classroom name (optional)
            $preview['parsed_classroom_name'] = isset($row['classroom_name']) ? trim($row['classroom_name']) : '';

            // Match school (required)
            $school_value = '';
            if (!empty($row['school_code'])) {
                $school_value = trim($row['school_code']);
                $school = $this->match_school($school_value, $schools, 'code');
            } elseif (!empty($row['school_name'])) {
                $school_value = trim($row['school_name']);
                $school = $this->match_school($school_value, $schools, 'name');
            } else {
                $school = null;
            }

            if ($school) {
                $preview['matched_school_id'] = $school->orgunit_id;
            } elseif (empty($school_value)) {
                $preview['validation_messages'][] = __('Missing required field: school_name or school_code', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            } else {
                $preview['validation_messages'][] = sprintf(
                    __('School not found: %s', 'hl-core'),
                    $school_value
                );
                $preview_rows[] = $preview;
                continue;
            }

            $school_id = (int) $preview['matched_school_id'];

            // Match classroom within school (optional)
            if (!empty($preview['parsed_classroom_name'])) {
                $matched_classroom = $this->match_classroom(
                    $preview['parsed_classroom_name'],
                    $school_id,
                    $classrooms_by_school
                );
                if ($matched_classroom) {
                    $preview['matched_classroom_id'] = $matched_classroom->classroom_id;
                } else {
                    $preview['validation_messages'][] = sprintf(
                        __('Classroom not found in school: %s', 'hl-core'),
                        $preview['parsed_classroom_name']
                    );
                }
            }

            // Identity matching: fingerprint-based
            $fingerprint_data = array(
                'school_id'         => $school_id,
                'dob'               => $parsed_dob,
                'internal_child_id' => $preview['parsed_child_identifier'],
                'first_name'        => $first_name,
                'last_name'         => $last_name,
            );
            $fingerprint = HL_Child_Repository::compute_fingerprint($fingerprint_data);

            $matches = $child_repo->find_by_fingerprint($fingerprint, $school_id);

            if (count($matches) === 1) {
                // Exact match — UPDATE
                $preview['matched_child_id'] = $matches[0]['child_id'];
                $preview['status'] = 'UPDATE';
                $preview['proposed_actions'][] = sprintf(
                    __('Update existing child record (ID: %d)', 'hl-core'),
                    $matches[0]['child_id']
                );
                $preview['selected'] = true;
            } elseif (count($matches) > 1) {
                // Ambiguous match
                $preview['status'] = 'NEEDS_REVIEW';
                $preview['validation_messages'][] = sprintf(
                    __('Ambiguous match: %d existing children match this fingerprint', 'hl-core'),
                    count($matches)
                );
                $preview['selected'] = false;
            } else {
                // No match — CREATE
                $preview['status'] = 'CREATE';
                $preview['proposed_actions'][] = __('Create new child record', 'hl-core');
                if ($preview['matched_classroom_id']) {
                    $preview['proposed_actions'][] = sprintf(
                        __('Assign to classroom: %s', 'hl-core'),
                        $preview['parsed_classroom_name']
                    );
                }
                $preview['selected'] = true;
            }

            $preview_rows[] = $preview;
        }

        return $preview_rows;
    }

    /**
     * Commit selected children import rows
     *
     * @param int   $run_id
     * @param int[] $selected_row_indices
     * @return array Results summary
     */
    public function commit_children_import($run_id, $selected_row_indices) {
        global $wpdb;

        $run = $this->get_preview($run_id);
        if (!$run || $run['status'] !== 'preview') {
            return array(
                'created_count' => 0, 'updated_count' => 0,
                'skipped_count' => 0, 'error_count'   => 1,
                'errors' => array(array('message' => __('Invalid import run or already committed.', 'hl-core'))),
            );
        }

        $preview_rows = $run['preview_data'];
        $track_id = (int) $run['track_id'];
        $child_repo = new HL_Child_Repository();
        $classroom_service = new HL_Classroom_Service();

        $selected_set = array_flip($selected_row_indices);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = array();

        $wpdb->query('START TRANSACTION');

        foreach ($preview_rows as &$row) {
            $idx = $row['row_index'];

            if (!isset($selected_set[$idx]) || !in_array($row['status'], array('CREATE', 'UPDATE'))) {
                $skipped++;
                continue;
            }

            $child_data = array(
                'school_id'         => $row['matched_school_id'],
                'first_name'        => $row['parsed_first_name'],
                'last_name'         => $row['parsed_last_name'],
                'dob'               => !empty($row['parsed_dob']) ? $row['parsed_dob'] : null,
                'internal_child_id' => !empty($row['parsed_child_identifier']) ? $row['parsed_child_identifier'] : null,
            );

            if ($row['status'] === 'CREATE') {
                $child_id = $child_repo->create($child_data);

                if (!$child_id) {
                    $errors[] = array(
                        'row_index' => $idx,
                        'name'      => $row['parsed_first_name'] . ' ' . $row['parsed_last_name'],
                        'message'   => __('Failed to create child record.', 'hl-core'),
                    );
                    $row['commit_status'] = 'error';
                    $row['commit_message'] = __('Failed to create child record.', 'hl-core');
                    continue;
                }

                HL_Audit_Service::log('import.child_created', array(
                    'track_id'   => $track_id,
                    'entity_type' => 'child',
                    'entity_id'   => $child_id,
                    'after_data'  => $child_data,
                    'reason'      => sprintf('Import run #%d', $run_id),
                ));

                // Assign to classroom if matched
                if (!empty($row['matched_classroom_id'])) {
                    $assign_result = $classroom_service->assign_child_to_classroom(
                        $child_id,
                        $row['matched_classroom_id'],
                        sprintf('Import run #%d', $run_id)
                    );
                    if (is_wp_error($assign_result)) {
                        $row['commit_message'] = sprintf(
                            __('Child created but classroom assignment failed: %s', 'hl-core'),
                            $assign_result->get_error_message()
                        );
                    }
                }

                $row['commit_status'] = 'created';
                $created++;

            } elseif ($row['status'] === 'UPDATE') {
                $child_id = $row['matched_child_id'];
                if (!$child_id) {
                    $errors[] = array(
                        'row_index' => $idx,
                        'name'      => $row['parsed_first_name'] . ' ' . $row['parsed_last_name'],
                        'message'   => __('Child record not found for update.', 'hl-core'),
                    );
                    $row['commit_status'] = 'error';
                    continue;
                }

                $update_data = array_filter($child_data, function($v) { return $v !== null; });
                $child_repo->update($child_id, $update_data);

                HL_Audit_Service::log('import.child_updated', array(
                    'track_id'   => $track_id,
                    'entity_type' => 'child',
                    'entity_id'   => $child_id,
                    'after_data'  => $update_data,
                    'reason'      => sprintf('Import run #%d', $run_id),
                ));

                // Assign/reassign to classroom if matched
                if (!empty($row['matched_classroom_id'])) {
                    $classroom_service->assign_child_to_classroom(
                        $child_id,
                        $row['matched_classroom_id'],
                        sprintf('Import run #%d (update)', $run_id)
                    );
                }

                $row['commit_status'] = 'updated';
                $updated++;
            }
        }
        unset($row);

        $wpdb->query('COMMIT');

        $results_summary = array(
            'created_count'   => $created,
            'updated_count'   => $updated,
            'skipped_count'   => $skipped,
            'error_count'     => count($errors),
            'total_processed' => $created + $updated + $skipped + count($errors),
            'errors'          => $errors,
        );

        $wpdb->update(
            $wpdb->prefix . 'hl_import_run',
            array(
                'status'          => 'committed',
                'preview_data'    => HL_DB_Utils::json_encode($preview_rows),
                'results_summary' => HL_DB_Utils::json_encode($results_summary),
            ),
            array('run_id' => $run_id)
        );

        HL_Audit_Service::log('import.committed', array(
            'track_id'   => $track_id,
            'entity_type' => 'import_run',
            'entity_id'   => $run_id,
            'after_data'  => $results_summary,
        ));

        return $results_summary;
    }

    // =========================================================================
    // Classroom Import
    // =========================================================================

    /**
     * Validate classroom rows against database
     *
     * @param array $parsed_rows Array of associative arrays
     * @param int   $track_id   (for context/audit)
     * @return array Preview rows
     */
    public function validate_classroom_rows($parsed_rows, $track_id) {
        global $wpdb;
        $preview_rows = array();

        // Pre-load schools for matching
        $schools = $this->load_schools_lookup();
        // Pre-load existing classrooms
        $classrooms_by_school = $this->load_classrooms_by_school();

        foreach ($parsed_rows as $index => $row) {
            $preview = array(
                'row_index'           => $index,
                'raw_data'            => $row,
                'status'              => 'ERROR',
                'matched_school_id'   => null,
                'existing_classroom_id' => null,
                'validation_messages' => array(),
                'proposed_actions'    => array(),
                'parsed_classroom_name' => '',
                'parsed_age_band'    => '',
                'selected'            => false,
            );

            // Parse classroom name (required)
            $classroom_name = isset($row['classroom_name']) ? trim($row['classroom_name']) : '';
            $preview['parsed_classroom_name'] = $classroom_name;

            if (empty($classroom_name)) {
                $preview['validation_messages'][] = __('Missing required field: classroom_name', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            }

            // Parse age_band (optional)
            $age_band = isset($row['age_band']) ? strtolower(trim($row['age_band'])) : '';
            $valid_age_bands = array('infant', 'toddler', 'preschool', 'mixed');
            if (!empty($age_band) && !in_array($age_band, $valid_age_bands)) {
                $preview['validation_messages'][] = sprintf(
                    __('Invalid age_band: %s (must be infant, toddler, preschool, or mixed)', 'hl-core'),
                    $age_band
                );
                $age_band = '';
            }
            $preview['parsed_age_band'] = $age_band;

            // Match school (required)
            $school_value = '';
            if (!empty($row['school_code'])) {
                $school_value = trim($row['school_code']);
                $school = $this->match_school($school_value, $schools, 'code');
            } elseif (!empty($row['school_name'])) {
                $school_value = trim($row['school_name']);
                $school = $this->match_school($school_value, $schools, 'name');
            } else {
                $school = null;
            }

            if ($school) {
                $preview['matched_school_id'] = $school->orgunit_id;
            } elseif (empty($school_value)) {
                $preview['validation_messages'][] = __('Missing required field: school_name or school_code', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            } else {
                $preview['validation_messages'][] = sprintf(
                    __('School not found: %s', 'hl-core'),
                    $school_value
                );
                $preview_rows[] = $preview;
                continue;
            }

            $school_id = (int) $preview['matched_school_id'];

            // Check for existing classroom by (school_id, classroom_name)
            $existing = $this->match_classroom($classroom_name, $school_id, $classrooms_by_school);

            if ($existing) {
                $preview['existing_classroom_id'] = $existing->classroom_id;
                $preview['status'] = 'SKIP';
                $preview['validation_messages'][] = sprintf(
                    __('Classroom already exists in this school (ID: %d)', 'hl-core'),
                    $existing->classroom_id
                );
                $preview['selected'] = false;
            } else {
                $preview['status'] = 'CREATE';
                $preview['proposed_actions'][] = sprintf(
                    __('Create classroom "%s" in school', 'hl-core'),
                    $classroom_name
                );
                $preview['selected'] = true;
            }

            $preview_rows[] = $preview;
        }

        return $preview_rows;
    }

    /**
     * Commit selected classroom import rows
     *
     * @param int   $run_id
     * @param int[] $selected_row_indices
     * @return array Results summary
     */
    public function commit_classroom_import($run_id, $selected_row_indices) {
        global $wpdb;

        $run = $this->get_preview($run_id);
        if (!$run || $run['status'] !== 'preview') {
            return array(
                'created_count' => 0, 'updated_count' => 0,
                'skipped_count' => 0, 'error_count'   => 1,
                'errors' => array(array('message' => __('Invalid import run or already committed.', 'hl-core'))),
            );
        }

        $preview_rows = $run['preview_data'];
        $track_id = (int) $run['track_id'];
        $classroom_service = new HL_Classroom_Service();

        $selected_set = array_flip($selected_row_indices);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = array();

        $wpdb->query('START TRANSACTION');

        foreach ($preview_rows as &$row) {
            $idx = $row['row_index'];

            if (!isset($selected_set[$idx]) || $row['status'] !== 'CREATE') {
                $skipped++;
                continue;
            }

            $data = array(
                'classroom_name' => $row['parsed_classroom_name'],
                'school_id'      => $row['matched_school_id'],
            );
            if (!empty($row['parsed_age_band'])) {
                $data['age_band'] = $row['parsed_age_band'];
            }

            $result = $classroom_service->create_classroom($data);

            if (is_wp_error($result)) {
                $errors[] = array(
                    'row_index' => $idx,
                    'name'      => $row['parsed_classroom_name'],
                    'message'   => $result->get_error_message(),
                );
                $row['commit_status'] = 'error';
                $row['commit_message'] = $result->get_error_message();
                continue;
            }

            if (!$result) {
                $errors[] = array(
                    'row_index' => $idx,
                    'name'      => $row['parsed_classroom_name'],
                    'message'   => __('Failed to create classroom.', 'hl-core'),
                );
                $row['commit_status'] = 'error';
                $row['commit_message'] = __('Failed to create classroom.', 'hl-core');
                continue;
            }

            HL_Audit_Service::log('import.classroom_created', array(
                'track_id'   => $track_id,
                'entity_type' => 'classroom',
                'entity_id'   => $result,
                'after_data'  => $data,
                'reason'      => sprintf('Import run #%d', $run_id),
            ));

            $row['commit_status'] = 'created';
            $created++;
        }
        unset($row);

        $wpdb->query('COMMIT');

        $results_summary = array(
            'created_count'   => $created,
            'updated_count'   => $updated,
            'skipped_count'   => $skipped,
            'error_count'     => count($errors),
            'total_processed' => $created + $updated + $skipped + count($errors),
            'errors'          => $errors,
        );

        $wpdb->update(
            $wpdb->prefix . 'hl_import_run',
            array(
                'status'          => 'committed',
                'preview_data'    => HL_DB_Utils::json_encode($preview_rows),
                'results_summary' => HL_DB_Utils::json_encode($results_summary),
            ),
            array('run_id' => $run_id)
        );

        HL_Audit_Service::log('import.committed', array(
            'track_id'   => $track_id,
            'entity_type' => 'import_run',
            'entity_id'   => $run_id,
            'after_data'  => $results_summary,
        ));

        return $results_summary;
    }

    // =========================================================================
    // Teaching Assignment Import
    // =========================================================================

    /**
     * Validate teaching assignment rows against database
     *
     * @param array $parsed_rows Array of associative arrays
     * @param int   $track_id
     * @return array Preview rows
     */
    public function validate_teaching_assignment_rows($parsed_rows, $track_id) {
        global $wpdb;
        $enrollment_repo = new HL_Enrollment_Repository();
        $preview_rows = array();

        // Pre-load schools and classrooms for matching
        $schools = $this->load_schools_lookup();
        $classrooms_by_school = $this->load_classrooms_by_school();

        // Pre-load existing teaching assignments for this track for duplicate detection
        $existing_assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT ta.enrollment_id, ta.classroom_id, ta.assignment_id
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             WHERE e.track_id = %d",
            $track_id
        ));
        $assignment_lookup = array();
        foreach ($existing_assignments as $ta) {
            $key = $ta->enrollment_id . '_' . $ta->classroom_id;
            $assignment_lookup[$key] = $ta->assignment_id;
        }

        foreach ($parsed_rows as $index => $row) {
            $preview = array(
                'row_index'              => $index,
                'raw_data'               => $row,
                'status'                 => 'ERROR',
                'matched_school_id'      => null,
                'matched_classroom_id'   => null,
                'matched_enrollment_id'  => null,
                'matched_user_id'        => null,
                'existing_assignment_id' => null,
                'validation_messages'    => array(),
                'proposed_actions'       => array(),
                'parsed_email'           => '',
                'parsed_classroom_name'  => '',
                'parsed_is_lead'         => false,
                'selected'               => false,
            );

            // Parse email (required)
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

            // Parse classroom name (required)
            $classroom_name = isset($row['classroom_name']) ? trim($row['classroom_name']) : '';
            $preview['parsed_classroom_name'] = $classroom_name;

            if (empty($classroom_name)) {
                $preview['validation_messages'][] = __('Missing required field: classroom_name', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            }

            // Parse is_lead_teacher (optional)
            $is_lead_raw = isset($row['is_lead_teacher']) ? strtolower(trim($row['is_lead_teacher'])) : '';
            $preview['parsed_is_lead'] = in_array($is_lead_raw, array('1', 'yes', 'true', 'y', 'si'));

            // Match school (required)
            $school_value = '';
            if (!empty($row['school_code'])) {
                $school_value = trim($row['school_code']);
                $school = $this->match_school($school_value, $schools, 'code');
            } elseif (!empty($row['school_name'])) {
                $school_value = trim($row['school_name']);
                $school = $this->match_school($school_value, $schools, 'name');
            } else {
                $school = null;
            }

            if ($school) {
                $preview['matched_school_id'] = $school->orgunit_id;
            } elseif (empty($school_value)) {
                $preview['validation_messages'][] = __('Missing required field: school_name or school_code', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            } else {
                $preview['validation_messages'][] = sprintf(
                    __('School not found: %s', 'hl-core'),
                    $school_value
                );
                $preview_rows[] = $preview;
                continue;
            }

            $school_id = (int) $preview['matched_school_id'];

            // Match classroom within school
            $matched_classroom = $this->match_classroom($classroom_name, $school_id, $classrooms_by_school);
            if ($matched_classroom) {
                $preview['matched_classroom_id'] = $matched_classroom->classroom_id;
            } else {
                $preview['validation_messages'][] = sprintf(
                    __('Classroom not found in school: %s', 'hl-core'),
                    $classroom_name
                );
                $preview_rows[] = $preview;
                continue;
            }

            // Match teacher: WP user by email, then enrollment in track
            $wp_user = get_user_by('email', $email);
            if (!$wp_user) {
                $preview['validation_messages'][] = sprintf(
                    __('No WordPress user found with email: %s', 'hl-core'),
                    $email
                );
                $preview_rows[] = $preview;
                continue;
            }
            $preview['matched_user_id'] = $wp_user->ID;

            $enrollment = $enrollment_repo->get_by_track_and_user($track_id, $wp_user->ID);
            if (!$enrollment) {
                $preview['validation_messages'][] = sprintf(
                    __('User %s is not enrolled in this track', 'hl-core'),
                    $email
                );
                $preview_rows[] = $preview;
                continue;
            }
            $preview['matched_enrollment_id'] = $enrollment->enrollment_id;

            // Check for existing assignment (enrollment_id + classroom_id)
            $lookup_key = $enrollment->enrollment_id . '_' . $preview['matched_classroom_id'];
            if (isset($assignment_lookup[$lookup_key])) {
                $preview['existing_assignment_id'] = $assignment_lookup[$lookup_key];
                $preview['status'] = 'SKIP';
                $preview['validation_messages'][] = __('Teaching assignment already exists for this teacher and classroom.', 'hl-core');
                $preview['selected'] = false;
            } else {
                $preview['status'] = 'CREATE';
                $preview['proposed_actions'][] = sprintf(
                    __('Assign %s to classroom "%s"%s', 'hl-core'),
                    $wp_user->display_name,
                    $classroom_name,
                    $preview['parsed_is_lead'] ? ' (Lead Teacher)' : ''
                );
                $preview['selected'] = true;
            }

            $preview_rows[] = $preview;
        }

        return $preview_rows;
    }

    /**
     * Commit selected teaching assignment import rows
     *
     * @param int   $run_id
     * @param int[] $selected_row_indices
     * @return array Results summary
     */
    public function commit_teaching_assignment_import($run_id, $selected_row_indices) {
        global $wpdb;

        $run = $this->get_preview($run_id);
        if (!$run || $run['status'] !== 'preview') {
            return array(
                'created_count' => 0, 'updated_count' => 0,
                'skipped_count' => 0, 'error_count'   => 1,
                'errors' => array(array('message' => __('Invalid import run or already committed.', 'hl-core'))),
            );
        }

        $preview_rows = $run['preview_data'];
        $track_id = (int) $run['track_id'];
        $classroom_service = new HL_Classroom_Service();

        $selected_set = array_flip($selected_row_indices);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = array();

        $wpdb->query('START TRANSACTION');

        foreach ($preview_rows as &$row) {
            $idx = $row['row_index'];

            if (!isset($selected_set[$idx]) || $row['status'] !== 'CREATE') {
                $skipped++;
                continue;
            }

            $assignment_data = array(
                'enrollment_id'   => $row['matched_enrollment_id'],
                'classroom_id'    => $row['matched_classroom_id'],
                'is_lead_teacher' => $row['parsed_is_lead'] ? 1 : 0,
            );

            $result = $classroom_service->create_teaching_assignment($assignment_data);

            if (is_wp_error($result)) {
                $errors[] = array(
                    'row_index' => $idx,
                    'email'     => $row['parsed_email'],
                    'message'   => $result->get_error_message(),
                );
                $row['commit_status'] = 'error';
                $row['commit_message'] = $result->get_error_message();
                continue;
            }

            HL_Audit_Service::log('import.teaching_assignment_created', array(
                'track_id'   => $track_id,
                'entity_type' => 'teaching_assignment',
                'entity_id'   => $result,
                'after_data'  => $assignment_data,
                'reason'      => sprintf('Import run #%d', $run_id),
            ));

            $row['commit_status'] = 'created';
            $created++;
        }
        unset($row);

        $wpdb->query('COMMIT');

        $results_summary = array(
            'created_count'   => $created,
            'updated_count'   => $updated,
            'skipped_count'   => $skipped,
            'error_count'     => count($errors),
            'total_processed' => $created + $updated + $skipped + count($errors),
            'errors'          => $errors,
        );

        $wpdb->update(
            $wpdb->prefix . 'hl_import_run',
            array(
                'status'          => 'committed',
                'preview_data'    => HL_DB_Utils::json_encode($preview_rows),
                'results_summary' => HL_DB_Utils::json_encode($results_summary),
            ),
            array('run_id' => $run_id)
        );

        HL_Audit_Service::log('import.committed', array(
            'track_id'   => $track_id,
            'entity_type' => 'import_run',
            'entity_id'   => $run_id,
            'after_data'  => $results_summary,
        ));

        return $results_summary;
    }

    /**
     * Save preview data to the import run
     *
     * @param int   $run_id
     * @param array $preview_rows
     * @return bool
     */
    public function save_preview($run_id, $preview_rows) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'hl_import_run',
            array('preview_data' => HL_DB_Utils::json_encode($preview_rows)),
            array('run_id' => $run_id)
        ) !== false;
    }

    /**
     * Load preview data from the import run
     *
     * @param int $run_id
     * @return array|null
     */
    public function get_preview($run_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_import_run WHERE run_id = %d",
            $run_id
        ), ARRAY_A);

        if (!$row) return null;

        $row['preview_data'] = HL_DB_Utils::json_decode($row['preview_data']);
        $row['results_summary'] = HL_DB_Utils::json_decode($row['results_summary']);
        return $row;
    }

    /**
     * Commit selected preview rows
     *
     * @param int   $run_id
     * @param int[] $selected_row_indices
     * @return array Results summary
     */
    public function commit_import($run_id, $selected_row_indices) {
        global $wpdb;

        $run = $this->get_preview($run_id);
        if (!$run || $run['status'] !== 'preview') {
            return array(
                'created_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'error_count'   => 1,
                'errors'        => array(array('message' => __('Invalid import run or already committed.', 'hl-core'))),
            );
        }

        $preview_rows = $run['preview_data'];
        $track_id = (int) $run['track_id'];
        $enrollment_repo = new HL_Enrollment_Repository();

        $selected_set = array_flip($selected_row_indices);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = array();

        // Begin transaction
        $wpdb->query('START TRANSACTION');

        foreach ($preview_rows as &$row) {
            $idx = $row['row_index'];

            // Only process selected rows with valid status
            if (!isset($selected_set[$idx]) || !in_array($row['status'], array('CREATE', 'UPDATE'))) {
                $skipped++;
                continue;
            }

            $email = $row['parsed_email'];
            $roles = $row['parsed_roles'];
            $school_id = $row['matched_school_id'];
            $district_id = $row['matched_district_id'];

            if ($row['status'] === 'CREATE') {
                $user_id = $row['matched_user_id'];

                // Create WP user if needed
                if (!$user_id) {
                    $display_name = trim($row['parsed_first_name'] . ' ' . $row['parsed_last_name']);
                    if (empty($display_name)) {
                        $display_name = $email;
                    }

                    $user_data = array(
                        'user_login'   => $email,
                        'user_email'   => $email,
                        'first_name'   => $row['parsed_first_name'],
                        'last_name'    => $row['parsed_last_name'],
                        'display_name' => $display_name,
                        'user_pass'    => wp_generate_password(16, true, true),
                        'role'         => 'subscriber',
                    );

                    $user_id = wp_insert_user($user_data);

                    if (is_wp_error($user_id)) {
                        $errors[] = array(
                            'row_index' => $idx,
                            'email'     => $email,
                            'message'   => $user_id->get_error_message(),
                        );
                        $row['commit_status'] = 'error';
                        $row['commit_message'] = $user_id->get_error_message();
                        continue;
                    }

                    HL_Audit_Service::log('import.user_created', array(
                        'track_id'   => $track_id,
                        'entity_type' => 'user',
                        'entity_id'   => $user_id,
                        'after_data'  => array('email' => $email, 'roles' => $roles),
                        'reason'      => sprintf('Import run #%d', $run_id),
                    ));
                }

                // Create enrollment
                $enrollment_id = $enrollment_repo->create(array(
                    'track_id'   => $track_id,
                    'user_id'     => $user_id,
                    'roles'       => $roles,
                    'school_id'   => $school_id,
                    'district_id' => $district_id,
                    'status'      => 'active',
                    'enrolled_at' => current_time('mysql'),
                ));

                if (!$enrollment_id) {
                    $errors[] = array(
                        'row_index' => $idx,
                        'email'     => $email,
                        'message'   => __('Failed to create enrollment.', 'hl-core'),
                    );
                    $row['commit_status'] = 'error';
                    $row['commit_message'] = __('Failed to create enrollment.', 'hl-core');
                    continue;
                }

                HL_Audit_Service::log('import.enrollment_created', array(
                    'track_id'   => $track_id,
                    'entity_type' => 'enrollment',
                    'entity_id'   => $enrollment_id,
                    'after_data'  => array('user_id' => $user_id, 'roles' => $roles, 'school_id' => $school_id),
                    'reason'      => sprintf('Import run #%d', $run_id),
                ));

                $row['commit_status'] = 'created';
                $created++;

            } elseif ($row['status'] === 'UPDATE') {
                $enrollment_id = $row['existing_enrollment_id'];
                if (!$enrollment_id) {
                    $errors[] = array(
                        'row_index' => $idx,
                        'email'     => $email,
                        'message'   => __('Enrollment not found for update.', 'hl-core'),
                    );
                    $row['commit_status'] = 'error';
                    continue;
                }

                // Get current enrollment for audit before_data
                $current = $enrollment_repo->get_by_id($enrollment_id);
                $before_data = $current ? array('roles' => $current->get_roles_array(), 'school_id' => $current->school_id) : array();

                $update_data = array(
                    'roles'     => $roles,
                    'school_id' => $school_id,
                );
                if ($district_id) {
                    $update_data['district_id'] = $district_id;
                }

                $enrollment_repo->update($enrollment_id, $update_data);

                HL_Audit_Service::log('import.enrollment_updated', array(
                    'track_id'   => $track_id,
                    'entity_type' => 'enrollment',
                    'entity_id'   => $enrollment_id,
                    'before_data' => $before_data,
                    'after_data'  => array('roles' => $roles, 'school_id' => $school_id),
                    'reason'      => sprintf('Import run #%d', $run_id),
                ));

                $row['commit_status'] = 'updated';
                $updated++;
            }
        }
        unset($row);

        $wpdb->query('COMMIT');

        $results_summary = array(
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'error_count'   => count($errors),
            'total_processed' => $created + $updated + $skipped + count($errors),
            'errors'        => $errors,
        );

        // Update the import run
        $wpdb->update(
            $wpdb->prefix . 'hl_import_run',
            array(
                'status'          => 'committed',
                'preview_data'    => HL_DB_Utils::json_encode($preview_rows),
                'results_summary' => HL_DB_Utils::json_encode($results_summary),
            ),
            array('run_id' => $run_id)
        );

        // Log run-level audit
        HL_Audit_Service::log('import.committed', array(
            'track_id'   => $track_id,
            'entity_type' => 'import_run',
            'entity_id'   => $run_id,
            'after_data'  => $results_summary,
        ));

        return $results_summary;
    }

    /**
     * Generate error report CSV
     *
     * @param int $run_id
     * @return string|WP_Error Download URL
     */
    public function generate_error_report($run_id) {
        $run = $this->get_preview($run_id);
        if (!$run) {
            return new WP_Error('not_found', __('Import run not found.', 'hl-core'));
        }

        $preview_rows = $run['preview_data'];

        // Create upload directory
        $upload_dir = wp_upload_dir();
        $import_dir = $upload_dir['basedir'] . '/hl-imports';
        if (!file_exists($import_dir)) {
            wp_mkdir_p($import_dir);
            // Protect directory
            file_put_contents($import_dir . '/.htaccess', "Options -Indexes\n");
        }

        $short_uuid = substr(HL_DB_Utils::generate_uuid(), 0, 8);
        $filename = sprintf('error-report-%d-%s.csv', $run_id, $short_uuid);
        $filepath = $import_dir . '/' . $filename;
        $download_url = $upload_dir['baseurl'] . '/hl-imports/' . $filename;

        $handle = fopen($filepath, 'w');
        if (!$handle) {
            return new WP_Error('write_failed', __('Failed to create error report file.', 'hl-core'));
        }

        // Write CSV header
        fputcsv($handle, array(
            'Row Number',
            'Email',
            'Track Roles',
            'School',
            'District',
            'Status',
            'Messages',
            'Remediation',
        ));

        // Write error/review/failed rows
        foreach ($preview_rows as $row) {
            $include = false;
            if (in_array($row['status'], array('ERROR', 'NEEDS_REVIEW'))) {
                $include = true;
            }
            if (isset($row['commit_status']) && $row['commit_status'] === 'error') {
                $include = true;
            }

            if (!$include) continue;

            $raw = $row['raw_data'];
            $messages = $row['validation_messages'];
            if (isset($row['commit_message'])) {
                $messages[] = $row['commit_message'];
            }

            $remediation = $this->suggest_remediation($row);

            fputcsv($handle, array(
                $row['row_index'] + 1,
                isset($raw['email']) ? $raw['email'] : '',
                isset($raw['track_roles']) ? $raw['track_roles'] : '',
                isset($raw['school_name']) ? $raw['school_name'] : (isset($raw['school_code']) ? $raw['school_code'] : ''),
                isset($raw['district_name']) ? $raw['district_name'] : (isset($raw['district_code']) ? $raw['district_code'] : ''),
                $row['status'],
                implode('; ', $messages),
                $remediation,
            ));
        }

        fclose($handle);

        // Store URL in the run
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hl_import_run',
            array('error_report_url' => $download_url),
            array('run_id' => $run_id)
        );

        return $download_url;
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Detect CSV delimiter from first line
     */
    private function detect_delimiter($line) {
        $delimiters = array(',' => 0, ';' => 0, "\t" => 0);
        foreach ($delimiters as $d => &$count) {
            $count = substr_count($line, $d);
        }
        arsort($delimiters);
        $best = key($delimiters);
        return $delimiters[$best] > 0 ? $best : ',';
    }

    /**
     * Parse a roles cell value into an array of role strings
     */
    private function parse_roles($raw) {
        // Split by comma, pipe, or semicolon
        $parts = preg_split('/[,|;]+/', $raw);
        $roles = array();
        foreach ($parts as $part) {
            $part = strtolower(trim($part));
            if (empty($part)) continue;
            if (isset(self::$role_synonyms[$part])) {
                $roles[] = self::$role_synonyms[$part];
            } else {
                // Try to capitalize as-is for validation
                $roles[] = ucwords($part);
            }
        }
        return array_unique($roles);
    }

    /**
     * Validate that roles are in the allowed set
     */
    private function validate_roles($roles) {
        $valid = array();
        $invalid = array();
        foreach ($roles as $role) {
            if (in_array($role, self::$valid_roles)) {
                $valid[] = $role;
            } else {
                $invalid[] = $role;
            }
        }
        return array('valid' => $valid, 'invalid' => $invalid);
    }

    /**
     * Load all schools indexed for fast lookup
     *
     * @return object[] Array of OrgUnit objects
     */
    private function load_schools_lookup() {
        $repo = new HL_OrgUnit_Repository();
        return $repo->get_schools();
    }

    /**
     * Load all districts indexed for fast lookup
     *
     * @return object[] Array of OrgUnit objects
     */
    private function load_districts_lookup() {
        $repo = new HL_OrgUnit_Repository();
        return $repo->get_districts();
    }

    /**
     * Match a school by name or code, optionally scoped to a district
     *
     * @param string   $value
     * @param object[] $schools
     * @param string   $match_by 'name' or 'code'
     * @param int|null $district_id
     * @return object|null
     */
    private function match_school($value, $schools, $match_by, $district_id = null) {
        $normalized = HL_Normalization::normalize_string($value);
        foreach ($schools as $school) {
            $compare = ($match_by === 'code')
                ? HL_Normalization::normalize_string($school->orgunit_code)
                : HL_Normalization::normalize_string($school->name);

            if ($compare === $normalized) {
                // If district filter is set, check parent
                if ($district_id && $school->parent_orgunit_id != $district_id) {
                    continue;
                }
                return $school;
            }
        }
        return null;
    }

    /**
     * Match a district by name or code
     *
     * @param string   $value
     * @param object[] $districts
     * @param string   $match_by 'name' or 'code'
     * @return object|null
     */
    private function match_district($value, $districts, $match_by) {
        $normalized = HL_Normalization::normalize_string($value);
        foreach ($districts as $district) {
            $compare = ($match_by === 'code')
                ? HL_Normalization::normalize_string($district->orgunit_code)
                : HL_Normalization::normalize_string($district->name);

            if ($compare === $normalized) {
                return $district;
            }
        }
        return null;
    }

    /**
     * Load all classrooms grouped by school_id for fast lookup
     *
     * @return array school_id => HL_Classroom[]
     */
    private function load_classrooms_by_school() {
        $repo = new HL_Classroom_Repository();
        $all = $repo->get_all();
        $grouped = array();
        foreach ($all as $classroom) {
            $cid = (int) $classroom->school_id;
            if (!isset($grouped[$cid])) {
                $grouped[$cid] = array();
            }
            $grouped[$cid][] = $classroom;
        }
        return $grouped;
    }

    /**
     * Match a classroom by name within a school
     *
     * @param string $classroom_name
     * @param int    $school_id
     * @param array  $classrooms_by_school
     * @return object|null HL_Classroom or null
     */
    private function match_classroom($classroom_name, $school_id, $classrooms_by_school) {
        if (!isset($classrooms_by_school[$school_id])) {
            return null;
        }
        $normalized = HL_Normalization::normalize_string($classroom_name);
        foreach ($classrooms_by_school[$school_id] as $classroom) {
            if (HL_Normalization::normalize_string($classroom->classroom_name) === $normalized) {
                return $classroom;
            }
        }
        return null;
    }

    /**
     * Generate remediation suggestion for a problem row
     */
    private function suggest_remediation($row) {
        $suggestions = array();
        $messages = implode(' ', $row['validation_messages']);

        if (stripos($messages, 'Missing required field: email') !== false) {
            $suggestions[] = 'Add an email address to this row.';
        }
        if (stripos($messages, 'Invalid email') !== false) {
            $suggestions[] = 'Check that the email address is correctly formatted.';
        }
        if (stripos($messages, 'Missing required field: track_roles') !== false) {
            $suggestions[] = 'Add a role (Teacher, Mentor, School Leader, or District Leader).';
        }
        if (stripos($messages, 'Unrecognized role') !== false) {
            $suggestions[] = 'Use one of: Teacher, Mentor, School Leader, District Leader.';
        }
        if (stripos($messages, 'School not found') !== false) {
            $suggestions[] = 'Check that the school name matches an existing school in the system.';
        }
        if (stripos($messages, 'Missing required field: school') !== false) {
            $suggestions[] = 'Add a school_name or school_code column to the CSV.';
        }
        if (stripos($messages, 'Duplicate email') !== false) {
            $suggestions[] = 'Remove the duplicate row from the CSV file.';
        }

        // Children import remediations
        if (stripos($messages, 'Missing required field: first_name or last_name') !== false) {
            $suggestions[] = 'Add a first_name and/or last_name to this row.';
        }
        if (stripos($messages, 'Invalid date format') !== false) {
            $suggestions[] = 'Use a standard date format (e.g. YYYY-MM-DD or MM/DD/YYYY).';
        }
        if (stripos($messages, 'Ambiguous match') !== false) {
            $suggestions[] = 'Add a child_identifier or date_of_birth to disambiguate.';
        }

        // Classroom import remediations
        if (stripos($messages, 'Missing required field: classroom_name') !== false) {
            $suggestions[] = 'Add a classroom_name column to the CSV.';
        }
        if (stripos($messages, 'Invalid age_band') !== false) {
            $suggestions[] = 'Use one of: infant, toddler, preschool, mixed.';
        }

        // Teaching assignment remediations
        if (stripos($messages, 'Classroom not found in school') !== false) {
            $suggestions[] = 'Check that the classroom name matches an existing classroom in the specified school.';
        }
        if (stripos($messages, 'No WordPress user found') !== false) {
            $suggestions[] = 'Import the participant first, then re-run this teaching assignment import.';
        }
        if (stripos($messages, 'not enrolled in this track') !== false) {
            $suggestions[] = 'Enroll the user in the track first, then re-run this import.';
        }

        return implode(' ', $suggestions);
    }
}
