<?php
if (!defined('ABSPATH')) exit;

class HL_Import_Service {

    /** @var array Column header synonyms → canonical name */
    private static $header_synonyms = array(
        'email'          => 'email',
        'email_address'  => 'email',
        'e_mail'         => 'email',
        'correo'         => 'email',

        'cycle_roles'   => 'cycle_roles',
        'cycle_role'    => 'cycle_roles',
        'role'           => 'cycle_roles',
        'roles'          => 'cycle_roles',
        'rol'            => 'cycle_roles',
        'b2e_role'       => 'cycle_roles',

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

        // Team
        'team'               => 'team',
        'team_name'          => 'team',

        // Assigned mentor
        'assigned_mentor'    => 'assigned_mentor',
        'mentor'             => 'assigned_mentor',
        'mentor_email'       => 'assigned_mentor',

        // Assigned coach
        'assigned_coach'     => 'assigned_coach',
        'coach'              => 'assigned_coach',
        'coach_email'        => 'assigned_coach',

        // Pathway
        'pathway'            => 'pathway',
        'pathway_name'       => 'pathway',
        'lms_pathway'        => 'pathway',
        'learning_plan'      => 'pathway',

        // Age group
        'age_group'          => 'age_group',

        // Is primary teacher
        'is_primary_teacher' => 'is_primary_teacher',
        'primary_teacher'    => 'is_primary_teacher',
        'primary_teacher_of_the_classroom_(y)' => 'is_primary_teacher',
        'primary_teacher_of_the_classroom' => 'is_primary_teacher',

        // Ethnicity (children)
        'ethnicity'          => 'ethnicity',
        'race'               => 'ethnicity',
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

    /** @var string[] Valid cycle roles */
    private static $valid_roles = array('Teacher', 'Mentor', 'School Leader', 'District Leader');

    /** @var int Maximum rows per import */
    const MAX_ROWS = 5000;

    /** @var int Maximum file size in bytes (2MB) */
    const MAX_FILE_SIZE = 2097152;

    /**
     * Get import runs for a cycle
     */
    public function get_runs($cycle_id = null) {
        global $wpdb;
        $sql = "SELECT ir.*, u.display_name AS actor_name, t.cycle_name
                FROM {$wpdb->prefix}hl_import_run ir
                LEFT JOIN {$wpdb->users} u ON ir.actor_user_id = u.ID
                LEFT JOIN {$wpdb->prefix}hl_cycle t ON ir.cycle_id = t.cycle_id";
        if ($cycle_id) {
            $sql = $wpdb->prepare($sql . " WHERE ir.cycle_id = %d", $cycle_id);
        }
        $sql .= " ORDER BY ir.created_at DESC LIMIT 50";
        return $wpdb->get_results($sql, ARRAY_A) ?: array();
    }

    /**
     * Create a new import run record
     *
     * @param int    $cycle_id
     * @param string $import_type
     * @param string $file_name
     * @return int run_id
     */
    public function create_run($cycle_id, $import_type, $file_name) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'hl_import_run', array(
            'run_uuid'      => HL_DB_Utils::generate_uuid(),
            'actor_user_id' => get_current_user_id(),
            'cycle_id'     => $cycle_id,
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
        $mapped = array();       // index => canonical_name
        $mapped_names = array(); // canonical_name => first index (dedup: first column wins)
        $unmapped = array();     // headers that couldn't be matched

        foreach ($normalized_headers as $index => $header) {
            if (isset(self::$header_synonyms[$header])) {
                $canonical = self::$header_synonyms[$header];
                // If this canonical name is already mapped, skip (first column wins)
                if (isset($mapped_names[$canonical])) {
                    $unmapped[] = $header . ' (duplicate of ' . $canonical . ')';
                    continue;
                }
                $mapped[$index] = $canonical;
                $mapped_names[$canonical] = $index;
            } else {
                $unmapped[] = $header;
            }
        }

        return array('mapped' => $mapped, 'unmapped' => $unmapped);
    }

    // Validate and commit methods removed — now handled by handler classes:
    // - HL_Import_Participant_Handler::validate() / commit()
    // - HL_Import_Children_Handler::validate() / commit()
    // Classroom and Teaching Assignment imports were removed in the redesign;
    // those entities are now auto-created from Participant import.

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
     * Update import run status and results summary.
     *
     * @param int    $run_id
     * @param string $status
     * @param array  $results
     */
    public function update_run_status($run_id, $status, $results) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hl_import_run',
            array(
                'status'          => $status,
                'results_summary' => wp_json_encode($results),
            ),
            array('run_id' => absint($run_id))
        );
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
            'Cycle Roles',
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
                isset($raw['cycle_roles']) ? $raw['cycle_roles'] : '',
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
    // Helpers (public for handler access, private for internal use)
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
    public function load_schools_lookup() {
        $repo = new HL_OrgUnit_Repository();
        return $repo->get_schools();
    }

    /**
     * Load all districts indexed for fast lookup
     *
     * @return object[] Array of OrgUnit objects
     */
    public function load_districts_lookup() {
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
    public function match_school($value, $schools, $match_by, $district_id = null) {
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
    public function load_classrooms_by_school($cycle_id = 0) {
        $repo = new HL_Classroom_Repository();

        if ($cycle_id) {
            // Only load classrooms from schools in this cycle's partnership
            $partnership_schools = $this->load_partnership_schools($cycle_id);
            $school_ids = array_map(function($s) { return (int) $s->orgunit_id; }, $partnership_schools);

            $all = array();
            foreach ($school_ids as $sid) {
                $school_classrooms = $repo->get_all($sid);
                $all = array_merge($all, $school_classrooms);
            }
        } else {
            $all = $repo->get_all();
        }

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
     * Load schools linked to the given cycle's Partnership.
     *
     * @param int $cycle_id
     * @return array School rows keyed by orgunit_id.
     */
    public function load_partnership_schools($cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $partnership_id = $wpdb->get_var($wpdb->prepare(
            "SELECT partnership_id FROM {$prefix}hl_cycle WHERE cycle_id = %d",
            $cycle_id
        ));

        if ($partnership_id) {
            $sql = $wpdb->prepare(
                "SELECT DISTINCT o.orgunit_id, o.name, o.orgunit_code, o.status
                 FROM {$prefix}hl_cycle_school cs
                 JOIN {$prefix}hl_orgunit o ON cs.school_id = o.orgunit_id
                 WHERE cs.cycle_id IN (
                     SELECT cycle_id FROM {$prefix}hl_cycle WHERE partnership_id = %d
                 ) AND o.orgunit_type = 'school' AND o.status = 'active'",
                $partnership_id
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT o.orgunit_id, o.name, o.orgunit_code, o.status
                 FROM {$prefix}hl_cycle_school cs
                 JOIN {$prefix}hl_orgunit o ON cs.school_id = o.orgunit_id
                 WHERE cs.cycle_id = %d AND o.orgunit_type = 'school' AND o.status = 'active'",
                $cycle_id
            );
        }

        $rows = $wpdb->get_results($sql);
        $lookup = array();
        foreach ($rows ?: array() as $row) {
            $lookup[(int) $row->orgunit_id] = $row;
        }
        return $lookup;
    }

    /**
     * Match a classroom by name within a school
     *
     * @param string $classroom_name
     * @param int    $school_id
     * @param array  $classrooms_by_school
     * @return object|null HL_Classroom or null
     */
    public function match_classroom($classroom_name, $school_id, $classrooms_by_school) {
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
        if (stripos($messages, 'Missing required field: cycle_roles') !== false) {
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
        if (stripos($messages, 'not enrolled in this cycle') !== false) {
            $suggestions[] = 'Enroll the user in the cycle first, then re-run this import.';
        }

        return implode(' ', $suggestions);
    }
}
