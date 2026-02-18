<?php
/**
 * Cohort domain model
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Cohort {
    public $cohort_id;
    public $cohort_uuid;
    public $cohort_code;
    public $cohort_name;
    public $district_id;
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
            'cohort_id' => $this->cohort_id,
            'cohort_uuid' => $this->cohort_uuid,
            'cohort_code' => $this->cohort_code,
            'cohort_name' => $this->cohort_name,
            'district_id' => $this->district_id,
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
