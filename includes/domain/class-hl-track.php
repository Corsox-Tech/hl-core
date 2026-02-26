<?php
/**
 * Track domain model (a time-bounded run within a Cohort)
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Track {
    public $track_id;
    public $track_uuid;
    public $track_code;
    public $track_name;
    public $district_id;
    public $cohort_id;
    public $is_control_group;
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
            'track_id' => $this->track_id,
            'track_uuid' => $this->track_uuid,
            'track_code' => $this->track_code,
            'track_name' => $this->track_name,
            'district_id' => $this->district_id,
            'cohort_id' => $this->cohort_id,
            'is_control_group' => $this->is_control_group,
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
