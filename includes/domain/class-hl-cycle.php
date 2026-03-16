<?php
/**
 * Cycle domain model (a time-bounded run within a Cycle)
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Cycle {
    public $cycle_id;
    public $cycle_uuid;
    public $cycle_code;
    public $cycle_name;
    public $district_id;
    public $cohort_id;
    public $is_control_group;
    public $cycle_type;
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
            'cycle_id' => $this->cycle_id,
            'cycle_uuid' => $this->cycle_uuid,
            'cycle_code' => $this->cycle_code,
            'cycle_name' => $this->cycle_name,
            'district_id' => $this->district_id,
            'cohort_id' => $this->cohort_id,
            'is_control_group' => $this->is_control_group,
            'cycle_type' => $this->cycle_type,
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
