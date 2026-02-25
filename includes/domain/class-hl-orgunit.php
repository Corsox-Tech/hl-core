<?php
if (!defined('ABSPATH')) exit;

class HL_OrgUnit {
    public $orgunit_id;
    public $orgunit_uuid;
    public $orgunit_code;
    public $orgunit_type;
    public $parent_orgunit_id;
    public $name;
    public $status;
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

    public function is_district() {
        return $this->orgunit_type === 'district';
    }

    public function is_school() {
        return $this->orgunit_type === 'school';
    }

    public function to_array() {
        return get_object_vars($this);
    }
}
