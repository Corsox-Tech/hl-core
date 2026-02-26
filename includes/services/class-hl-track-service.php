<?php
/**
 * Track Service
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Track_Service {

    private $repository;

    public function __construct() {
        $this->repository = new HL_Track_Repository();
    }

    public function get_all_tracks() {
        return $this->repository->get_all();
    }

    public function get_track($track_id) {
        return $this->repository->get_by_id($track_id);
    }

    public function create_track($data) {
        // Validation
        if (empty($data['track_name'])) {
            return new WP_Error('missing_name', __('Track name is required.', 'hl-core'));
        }

        if (empty($data['start_date'])) {
            return new WP_Error('missing_start_date', __('Start date is required.', 'hl-core'));
        }

        $track_id = $this->repository->create($data);

        // Audit log
        do_action('hl_track_created', $track_id);

        return $track_id;
    }

    public function update_track($track_id, $data) {
        return $this->repository->update($track_id, $data);
    }
}
