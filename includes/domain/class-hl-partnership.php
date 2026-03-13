<?php
/**
 * Partnership domain model (a time-bounded run within a Cohort)
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Partnership {
    public $partnership_id;
    public $partnership_uuid;
    public $partnership_code;
    public $partnership_name;
    public $district_id;
    public $cohort_id;
    public $is_control_group;
    public $partnership_type;
    public $status;
    public $start_date;
    public $end_date;
    public $timezone;
    public $settings;
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
        return array(
            'partnership_id' => $this->partnership_id,
            'partnership_uuid' => $this->partnership_uuid,
            'partnership_code' => $this->partnership_code,
            'partnership_name' => $this->partnership_name,
            'district_id' => $this->district_id,
            'cohort_id' => $this->cohort_id,
            'is_control_group' => $this->is_control_group,
            'partnership_type' => $this->partnership_type,
            'status' => $this->status,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'timezone' => $this->timezone,
            'settings' => $this->settings,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        );
    }
}
