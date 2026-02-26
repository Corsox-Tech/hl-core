<?php
if (!defined('ABSPATH')) exit;

class HL_Enrollment {
    public $enrollment_id;
    public $enrollment_uuid;
    public $track_id;
    public $user_id;
    public $roles;
    public $assigned_pathway_id;
    public $school_id;
    public $district_id;
    public $status;
    public $enrolled_at;
    public $created_at;
    public $updated_at;

    public function __construct($data = array()) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function get_roles_array() {
        if (is_array($this->roles)) return $this->roles;
        return HL_DB_Utils::json_decode($this->roles);
    }

    public function has_role($role) {
        return in_array($role, $this->get_roles_array());
    }

    public function to_array() {
        return get_object_vars($this);
    }
}
