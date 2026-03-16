<?php
/**
 * Cycle Service
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Cycle_Service {

    private $repository;

    public function __construct() {
        $this->repository = new HL_Cycle_Repository();
    }

    public function get_all_cycles() {
        return $this->repository->get_all();
    }

    public function get_cycle($cycle_id) {
        return $this->repository->get_by_id($cycle_id);
    }

    public function create_cycle($data) {
        // Validation
        if (empty($data['cycle_name'])) {
            return new WP_Error('missing_name', __('Cycle name is required.', 'hl-core'));
        }

        if (empty($data['start_date'])) {
            return new WP_Error('missing_start_date', __('Start date is required.', 'hl-core'));
        }

        $cycle_id = $this->repository->create($data);

        // Audit log
        do_action('hl_cycle_created', $cycle_id);

        return $cycle_id;
    }

    public function update_cycle($cycle_id, $data) {
        return $this->repository->update($cycle_id, $data);
    }
}
