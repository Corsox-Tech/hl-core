<?php
/**
 * Cohort Service
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Cohort_Service {

    private $repository;

    public function __construct() {
        $this->repository = new HL_Cohort_Repository();
    }

    public function get_all_cohorts() {
        return $this->repository->get_all();
    }

    public function get_cohort($cohort_id) {
        return $this->repository->get_by_id($cohort_id);
    }

    public function create_cohort($data) {
        // Validation
        if (empty($data['cohort_name'])) {
            return new WP_Error('missing_name', __('Cohort name is required.', 'hl-core'));
        }

        if (empty($data['start_date'])) {
            return new WP_Error('missing_start_date', __('Start date is required.', 'hl-core'));
        }

        $cohort_id = $this->repository->create($data);

        // Audit log
        do_action('hl_cohort_created', $cohort_id);

        return $cohort_id;
    }

    public function update_cohort($cohort_id, $data) {
        return $this->repository->update($cohort_id, $data);
    }
}
