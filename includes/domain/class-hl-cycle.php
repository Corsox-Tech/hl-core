<?php
/**
 * Cycle domain model (time-bounded period within a Partnership)
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Cycle {
    public $cycle_id;
    public $cycle_uuid;
    public $partnership_id;
    public $cycle_name;
    public $cycle_number;
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
