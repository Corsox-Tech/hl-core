<?php
if (!defined('ABSPATH')) exit;

/**
 * Import Children Handler
 *
 * Validates and commits children import rows.
 * Classroom is required. School can be inferred from classroom.
 *
 * @package HL_Core
 */
class HL_Import_Children_Handler {

    /** @var HL_Import_Service */
    private $import_service;

    public function __construct() {
        $this->import_service = new HL_Import_Service();
    }

    /**
     * Validate children rows.
     *
     * @param array $parsed_rows
     * @param int   $cycle_id
     * @return array Preview rows.
     */
    public function validate($parsed_rows, $cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $child_repo   = new HL_Child_Repository();
        $preview_rows = array();

        // Load Partnership-scoped schools
        $partnership_schools = $this->import_service->load_partnership_schools($cycle_id);
        $school_by_name = array();
        foreach ($partnership_schools as $s) {
            $school_by_name[strtolower(trim($s->name))] = $s;
        }

        // Load all classrooms grouped by school, filtered to Partnership schools
        $all_classrooms = $wpdb->get_results(
            "SELECT c.classroom_id, c.classroom_name, c.school_id
             FROM {$prefix}hl_classroom c
             WHERE c.status = 'active'
             ORDER BY c.classroom_name",
            ARRAY_A
        ) ?: array();

        // Build lookup: classroom_name_lower => array of {classroom_id, school_id}
        $classroom_lookup = array();
        $partnership_school_ids = array_keys($partnership_schools);
        foreach ($all_classrooms as $cr) {
            if (!in_array((int) $cr['school_id'], $partnership_school_ids, true)) {
                continue;
            }
            $key = strtolower(trim($cr['classroom_name']));
            $classroom_lookup[$key][] = $cr;
        }

        foreach ($parsed_rows as $index => $row) {
            $preview = array(
                'row_index'              => $index,
                'raw_data'               => $row,
                'status'                 => 'ERROR',
                'matched_school_id'      => null,
                'matched_classroom_id'   => null,
                'matched_child_id'       => null,
                'validation_messages'    => array(),
                'proposed_actions'       => array(),
                'parsed_first_name'      => '',
                'parsed_last_name'       => '',
                'parsed_dob'             => '',
                'parsed_child_identifier' => '',
                'parsed_classroom_name'  => '',
                'parsed_ethnicity'       => '',
                'raw_school'             => '',
                'selected'               => false,
            );

            // Names (required: at least one)
            $first_name = isset($row['first_name']) ? trim($row['first_name']) : '';
            $last_name  = isset($row['last_name']) ? trim($row['last_name']) : '';
            $preview['parsed_first_name'] = $first_name;
            $preview['parsed_last_name']  = $last_name;

            if (empty($first_name) && empty($last_name)) {
                $preview['validation_messages'][] = __('Missing required field: first_name or last_name', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            }

            // Classroom (required)
            $classroom_name = isset($row['classroom_name']) ? trim($row['classroom_name']) : '';
            $preview['parsed_classroom_name'] = $classroom_name;

            if (empty($classroom_name)) {
                $preview['validation_messages'][] = __('Missing required field: classroom', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            }

            // School (optional — infer from classroom if unambiguous)
            $raw_school = isset($row['school_name']) ? trim($row['school_name']) : '';
            $preview['raw_school'] = $raw_school;

            $cr_key = strtolower($classroom_name);
            $matching_classrooms = isset($classroom_lookup[$cr_key]) ? $classroom_lookup[$cr_key] : array();

            if (!empty($raw_school)) {
                // School provided — find classroom at that school
                $school_key = strtolower($raw_school);
                $matched_school = isset($school_by_name[$school_key]) ? $school_by_name[$school_key] : null;
                if (!$matched_school) {
                    $preview['validation_messages'][] = sprintf(__('School not found: "%s"', 'hl-core'), $raw_school);
                    $preview_rows[] = $preview;
                    continue;
                }
                $preview['matched_school_id'] = (int) $matched_school->orgunit_id;

                // Find classroom at this school
                $found_cr = null;
                foreach ($matching_classrooms as $mc) {
                    if ((int) $mc['school_id'] === (int) $matched_school->orgunit_id) {
                        $found_cr = $mc;
                        break;
                    }
                }
                if (!$found_cr) {
                    $preview['validation_messages'][] = sprintf(
                        __('Classroom "%s" not found at school "%s"', 'hl-core'),
                        $classroom_name, $raw_school
                    );
                    $preview_rows[] = $preview;
                    continue;
                }
                $preview['matched_classroom_id'] = (int) $found_cr['classroom_id'];
            } else {
                // No school — infer from classroom
                if (count($matching_classrooms) === 1) {
                    $preview['matched_classroom_id'] = (int) $matching_classrooms[0]['classroom_id'];
                    $preview['matched_school_id']    = (int) $matching_classrooms[0]['school_id'];
                } elseif (count($matching_classrooms) > 1) {
                    $preview['validation_messages'][] = sprintf(
                        __('Classroom "%s" exists at multiple schools. Please add a school column.', 'hl-core'),
                        $classroom_name
                    );
                    $preview_rows[] = $preview;
                    continue;
                } else {
                    $preview['validation_messages'][] = sprintf(
                        __('Classroom "%s" not found in this Partnership.', 'hl-core'),
                        $classroom_name
                    );
                    $preview_rows[] = $preview;
                    continue;
                }
            }

            // DOB (optional)
            $raw_dob = isset($row['date_of_birth']) ? trim($row['date_of_birth']) : '';
            $parsed_dob = '';
            if (!empty($raw_dob)) {
                $ts = strtotime($raw_dob);
                if ($ts !== false) {
                    $parsed_dob = date('Y-m-d', $ts);
                } else {
                    $preview['validation_messages'][] = sprintf(__('Invalid date format: %s', 'hl-core'), $raw_dob);
                }
            }
            $preview['parsed_dob'] = $parsed_dob;

            // Child identifier (optional)
            $preview['parsed_child_identifier'] = isset($row['child_identifier']) ? trim($row['child_identifier']) : '';

            // Ethnicity (optional)
            $preview['parsed_ethnicity'] = isset($row['ethnicity']) ? trim($row['ethnicity']) : '';

            // Fingerprint matching
            $school_id = (int) $preview['matched_school_id'];
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
                $preview['matched_child_id'] = $matches[0]['child_id'];
                $preview['status'] = 'UPDATE';
                $preview['proposed_actions'][] = sprintf(__('Update existing child (ID: %d)', 'hl-core'), $matches[0]['child_id']);
                $preview['selected'] = true;
            } elseif (count($matches) > 1) {
                $preview['status'] = 'WARNING';
                $preview['validation_messages'][] = sprintf(
                    __('Warning: Ambiguous match — %d existing children match this fingerprint.', 'hl-core'),
                    count($matches)
                );
            } else {
                $preview['status'] = 'CREATE';
                $preview['proposed_actions'][] = __('Create new child record', 'hl-core');
                $preview['proposed_actions'][] = sprintf(__('Assign to classroom: %s', 'hl-core'), $classroom_name);
                $preview['selected'] = true;
            }

            $preview_rows[] = $preview;
        }

        return $preview_rows;
    }

    /**
     * Commit selected children rows. All-or-nothing transaction.
     *
     * @param int   $run_id
     * @param int[] $selected_row_indices
     * @return array Results summary.
     */
    public function commit($run_id, $selected_row_indices) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $import_service = new HL_Import_Service();
        $run = $import_service->get_preview($run_id);
        if (!$run || $run['status'] !== 'preview') {
            return array(
                'created_count' => 0, 'updated_count' => 0,
                'skipped_count' => 0, 'error_count'   => 1,
                'errors' => array(array('message' => __('Invalid import run or already committed.', 'hl-core'))),
            );
        }

        $preview_rows = $run['preview_data'];
        $cycle_id     = (int) $run['cycle_id'];
        $child_repo   = new HL_Child_Repository();
        $classroom_service = new HL_Classroom_Service();

        $selected_set = array_flip($selected_row_indices);
        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = array();

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($preview_rows as $row) {
                if (!isset($selected_set[$row['row_index']])) {
                    $skipped++;
                    continue;
                }
                if ($row['status'] === 'ERROR') {
                    $skipped++;
                    continue;
                }

                $school_id    = (int) $row['matched_school_id'];
                $classroom_id = (int) $row['matched_classroom_id'];

                if ($row['status'] === 'CREATE') {
                    $child_data = array(
                        'school_id'         => $school_id,
                        'first_name'        => $row['parsed_first_name'],
                        'last_name'         => $row['parsed_last_name'],
                        'dob'               => !empty($row['parsed_dob']) ? $row['parsed_dob'] : null,
                        'internal_child_id' => !empty($row['parsed_child_identifier']) ? $row['parsed_child_identifier'] : null,
                        'ethnicity'         => !empty($row['parsed_ethnicity']) ? $row['parsed_ethnicity'] : null,
                    );
                    $child_id = $child_repo->create($child_data);
                    if (!$child_id) {
                        throw new Exception(sprintf('Failed to create child: %s %s', $row['parsed_first_name'], $row['parsed_last_name']));
                    }

                    // Assign to classroom
                    $classroom_service->assign_child_to_classroom($child_id, $classroom_id);

                    HL_Audit_Service::log('import_child_created', array(
                        'cycle_id'    => $cycle_id,
                        'entity_type' => 'child',
                        'entity_id'   => $child_id,
                        'reason'      => sprintf('Child created via import: %s %s → classroom %d', $row['parsed_first_name'], $row['parsed_last_name'], $classroom_id),
                    ));

                    $created++;

                } elseif ($row['status'] === 'UPDATE' || $row['status'] === 'WARNING') {
                    $child_id = (int) $row['matched_child_id'];
                    $update_data = array(
                        'first_name' => $row['parsed_first_name'],
                        'last_name'  => $row['parsed_last_name'],
                    );
                    if (!empty($row['parsed_dob'])) {
                        $update_data['dob'] = $row['parsed_dob'];
                    }
                    if (!empty($row['parsed_child_identifier'])) {
                        $update_data['internal_child_id'] = $row['parsed_child_identifier'];
                    }
                    if (!empty($row['parsed_ethnicity'])) {
                        $update_data['ethnicity'] = $row['parsed_ethnicity'];
                    }
                    $child_repo->update($child_id, $update_data);

                    // Update classroom assignment
                    $classroom_service->assign_child_to_classroom($child_id, $classroom_id);

                    HL_Audit_Service::log('import_child_updated', array(
                        'cycle_id'    => $cycle_id,
                        'entity_type' => 'child',
                        'entity_id'   => $child_id,
                        'reason'      => sprintf('Child updated via import: ID %d', $child_id),
                    ));

                    $updated++;
                }
            }

            $wpdb->query('COMMIT');

            $import_service->update_run_status($run_id, 'committed', array(
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'error_count'   => count($errors),
                'errors'        => $errors,
            ));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');

            $errors[] = array('message' => $e->getMessage());

            $import_service->update_run_status($run_id, 'failed', array(
                'created_count' => 0, 'updated_count' => 0,
                'skipped_count' => 0, 'error_count'   => 1,
                'errors'        => $errors,
            ));

            return array(
                'created_count' => 0, 'updated_count' => 0,
                'skipped_count' => 0, 'error_count'   => 1,
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
}
