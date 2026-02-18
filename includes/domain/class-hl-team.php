<?php
if (!defined('ABSPATH')) exit;

class HL_Team {
    public $team_id;
    public $team_uuid;
    public $cohort_id;
    public $center_id;
    public $team_name;
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

    public function to_array() {
        return get_object_vars($this);
    }
}
