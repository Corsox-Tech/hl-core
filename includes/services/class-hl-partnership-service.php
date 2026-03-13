<?php
/**
 * Partnership Service
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Partnership_Service {

    private $repository;

    public function __construct() {
        $this->repository = new HL_Partnership_Repository();
    }

    public function get_all_partnerships() {
        return $this->repository->get_all();
    }

    public function get_partnership($partnership_id) {
        return $this->repository->get_by_id($partnership_id);
    }

    public function create_partnership($data) {
        // Validation
        if (empty($data['partnership_name'])) {
            return new WP_Error('missing_name', __('Partnership name is required.', 'hl-core'));
        }

        if (empty($data['start_date'])) {
            return new WP_Error('missing_start_date', __('Start date is required.', 'hl-core'));
        }

        $partnership_id = $this->repository->create($data);

        // Audit log
        do_action('hl_partnership_created', $partnership_id);

        return $partnership_id;
    }

    public function update_partnership($partnership_id, $data) {
        return $this->repository->update($partnership_id, $data);
    }
}
