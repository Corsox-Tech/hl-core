<?php
if (!defined('ABSPATH')) exit;

class HL_Activity {
    public $activity_id;
    public $activity_uuid;
    public $cohort_id;
    public $pathway_id;
    public $activity_type;
    public $title;
    public $description;
    public $ordering_hint;
    public $weight;
    public $external_ref;
    public $visibility;
    public $status;
    public $created_at;
    public $updated_at;

    public function __construct($data = array()) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function get_external_ref_array() {
        if (is_array($this->external_ref)) return $this->external_ref;
        return HL_DB_Utils::json_decode($this->external_ref);
    }

    public function to_array() {
        return get_object_vars($this);
    }
}
