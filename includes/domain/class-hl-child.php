<?php
if (!defined('ABSPATH')) exit;

class HL_Child {
    public $child_id;
    public $child_uuid;
    public $school_id;
    public $first_name;
    public $last_name;
    public $dob;
    public $internal_child_id;
    public $ethnicity;
    public $child_fingerprint;
    public $child_display_code;
    public $metadata;
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
