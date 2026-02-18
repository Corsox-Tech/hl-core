<?php
if (!defined('ABSPATH')) exit;

class HL_Pathway {
    public $pathway_id;
    public $pathway_uuid;
    public $cohort_id;
    public $pathway_name;
    public $pathway_code;
    public $target_roles;
    public $description;
    public $objectives;
    public $syllabus_url;
    public $featured_image_id;
    public $avg_completion_time;
    public $expiration_date;
    public $active_status;
    public $created_at;
    public $updated_at;

    public function __construct($data = array()) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function get_target_roles_array() {
        if (is_array($this->target_roles)) return $this->target_roles;
        return HL_DB_Utils::json_decode($this->target_roles);
    }

    public function to_array() {
        return get_object_vars($this);
    }
}
