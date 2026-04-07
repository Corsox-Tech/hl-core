<?php
if (!defined('ABSPATH')) exit;

class HL_Component {
    public $component_id;
    public $component_uuid;
    public $cycle_id;
    public $pathway_id;
    public $component_type;
    public $title;
    public $description;
    public $ordering_hint;
    public $weight;
    public $external_ref;
    public $catalog_id;
    public $complete_by;
    public $scheduling_window_start;
    public $scheduling_window_end;
    public $visibility;
    public $requires_classroom;
    public $eligible_roles;
    public $status;
    public $created_at;
    public $updated_at;

    public function __construct($data = array()) {
        $data = is_array($data) ? $data : array();
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function get_eligible_roles_array() {
        if (empty($this->eligible_roles)) return array();
        if (is_array($this->eligible_roles)) return $this->eligible_roles;
        $decoded = json_decode($this->eligible_roles, true);
        return is_array($decoded) ? $decoded : array();
    }

    public function get_external_ref_array() {
        if (is_array($this->external_ref)) return $this->external_ref;
        return HL_DB_Utils::json_decode($this->external_ref);
    }

    public function to_array() {
        return get_object_vars($this);
    }
}
