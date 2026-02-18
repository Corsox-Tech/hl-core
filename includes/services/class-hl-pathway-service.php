<?php
if (!defined('ABSPATH')) exit;

class HL_Pathway_Service {

    private $pathway_repo;
    private $activity_repo;

    public function __construct() {
        $this->pathway_repo = new HL_Pathway_Repository();
        $this->activity_repo = new HL_Activity_Repository();
    }

    public function get_pathways($cohort_id = null) {
        return $this->pathway_repo->get_all($cohort_id);
    }

    public function get_pathway($pathway_id) {
        return $this->pathway_repo->get_by_id($pathway_id);
    }

    public function create_pathway($data) {
        if (empty($data['pathway_name']) || empty($data['cohort_id'])) {
            return new WP_Error('missing_fields', __('Pathway name and cohort are required.', 'hl-core'));
        }
        return $this->pathway_repo->create($data);
    }

    public function update_pathway($pathway_id, $data) {
        return $this->pathway_repo->update($pathway_id, $data);
    }

    public function delete_pathway($pathway_id) {
        return $this->pathway_repo->delete($pathway_id);
    }

    public function get_activities($pathway_id) {
        return $this->activity_repo->get_by_pathway($pathway_id);
    }

    public function create_activity($data) {
        if (empty($data['title']) || empty($data['pathway_id']) || empty($data['activity_type'])) {
            return new WP_Error('missing_fields', __('Title, pathway, and type are required.', 'hl-core'));
        }
        return $this->activity_repo->create($data);
    }

    public function update_activity($activity_id, $data) {
        return $this->activity_repo->update($activity_id, $data);
    }

    public function delete_activity($activity_id) {
        return $this->activity_repo->delete($activity_id);
    }
}
