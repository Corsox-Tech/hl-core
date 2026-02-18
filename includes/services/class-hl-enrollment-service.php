<?php
if (!defined('ABSPATH')) exit;

class HL_Enrollment_Service {

    private $repository;

    public function __construct() {
        $this->repository = new HL_Enrollment_Repository();
    }

    public function get_enrollments($filters = array()) {
        return $this->repository->get_all($filters);
    }

    public function get_enrollment($enrollment_id) {
        return $this->repository->get_by_id($enrollment_id);
    }

    public function get_by_cohort($cohort_id, $role = null) {
        return $this->repository->get_by_cohort($cohort_id, $role);
    }

    public function create_enrollment($data) {
        if (empty($data['cohort_id']) || empty($data['user_id'])) {
            return new WP_Error('missing_fields', __('Cohort and User are required.', 'hl-core'));
        }

        // Check uniqueness
        $existing = $this->repository->get_by_cohort_and_user($data['cohort_id'], $data['user_id']);
        if ($existing) {
            return new WP_Error('duplicate', __('User is already enrolled in this Cohort.', 'hl-core'));
        }

        $enrollment_id = $this->repository->create($data);
        do_action('hl_enrollment_created', $enrollment_id, $data);
        return $enrollment_id;
    }

    public function update_enrollment($enrollment_id, $data) {
        $result = $this->repository->update($enrollment_id, $data);
        do_action('hl_enrollment_updated', $enrollment_id, $data);
        return $result;
    }

    public function delete_enrollment($enrollment_id) {
        return $this->repository->delete($enrollment_id);
    }
}
