<?php
/**
 * Partnership domain model (program-level container — groups Cycles)
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Partnership {
    public $partnership_id;
    public $partnership_uuid;
    public $partnership_name;
    public $partnership_code;
    public $description;
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
        return array(
            'partnership_id'   => $this->partnership_id,
            'partnership_uuid' => $this->partnership_uuid,
            'partnership_name' => $this->partnership_name,
            'partnership_code' => $this->partnership_code,
            'description'      => $this->description,
            'status'           => $this->status,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        );
    }
}
