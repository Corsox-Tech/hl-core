<?php
if (!defined('ABSPATH')) exit;

class HL_Classroom {
    public $classroom_id;
    public $classroom_uuid;
    public $center_id;
    public $classroom_name;
    public $age_band;
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
