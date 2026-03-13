<?php
/**
 * Phase domain model (time-bounded period within a Partnership)
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Phase {
    public $phase_id;
    public $phase_uuid;
    public $partnership_id;
    public $phase_name;
    public $phase_number;
    public $start_date;
    public $end_date;
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
