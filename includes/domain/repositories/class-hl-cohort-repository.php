<?php
/**
 * Cohort Repository
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Cohort_Repository {

    /**
     * Get all cohorts
     */
    public function get_all() {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_cohort ORDER BY created_at DESC",
            ARRAY_A
        );

        $cohorts = array();
        foreach ($results as $row) {
            $cohorts[] = new HL_Cohort($row);
        }
        return $cohorts;
    }

    /**
     * Get cohort by ID
     */
    public function get_by_id($cohort_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_cohort WHERE cohort_id = %d",
            $cohort_id
        ), ARRAY_A);

        return $row ? new HL_Cohort($row) : null;
    }

    /**
     * Create cohort
     */
    public function create($data) {
        global $wpdb;

        // Generate UUID if not provided
        if (empty($data['cohort_uuid'])) {
            $data['cohort_uuid'] = HL_DB_Utils::generate_uuid();
        }

        // Generate code if not provided
        if (empty($data['cohort_code']) && !empty($data['cohort_name'])) {
            $data['cohort_code'] = HL_Normalization::generate_code($data['cohort_name']);
        }

        $wpdb->insert(
            $wpdb->prefix . 'hl_cohort',
            $data
        );

        return $wpdb->insert_id;
    }

    /**
     * Update cohort
     */
    public function update($cohort_id, $data) {
        global $wpdb;

        // Build explicit format array so $wpdb doesn't rely on auto-detection.
        $formats = array();
        foreach ($data as $key => $value) {
            if (is_null($value)) {
                // wpdb handles NULL values specially (SET col = NULL) —
                // the format entry is ignored for NULLs but we still need
                // a placeholder to keep the arrays aligned.
                $formats[] = '%s';
            } elseif (is_int($value) || is_bool($value)) {
                $formats[] = '%d';
            } elseif (is_float($value)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'hl_cohort',
            $data,
            array('cohort_id' => $cohort_id),
            $formats,
            array('%d')
        );

        if ($result === false && !empty($wpdb->last_error)) {
            error_log('[HL Core] Cohort update failed for ID ' . $cohort_id . ': ' . $wpdb->last_error);
        }

        return $this->get_by_id($cohort_id);
    }

    /**
     * Delete cohort
     */
    public function delete($cohort_id) {
        global $wpdb;
        return $wpdb->delete(
            $wpdb->prefix . 'hl_cohort',
            array('cohort_id' => $cohort_id)
        );
    }
}
