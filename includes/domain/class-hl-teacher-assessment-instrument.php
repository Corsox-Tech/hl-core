<?php
/**
 * Teacher Assessment Instrument domain model
 *
 * Represents a custom self-assessment instrument with structured sections
 * (likert scales, numeric scales) for teacher pre/post evaluation.
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Teacher_Assessment_Instrument {
    public $instrument_id;
    public $instrument_name;
    public $instrument_version;
    public $instrument_key;
    public $sections;
    public $scale_labels;
    public $instructions;
    public $styles_json;
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

    /**
     * Get decoded sections array.
     *
     * @return array
     */
    public function get_sections() {
        if (is_string($this->sections)) {
            return json_decode($this->sections, true) ?: array();
        }
        return is_array($this->sections) ? $this->sections : array();
    }

    /**
     * Get decoded scale labels map.
     *
     * @return array
     */
    public function get_scale_labels() {
        if (is_string($this->scale_labels)) {
            return json_decode($this->scale_labels, true) ?: array();
        }
        return is_array($this->scale_labels) ? $this->scale_labels : array();
    }

    /**
     * Get the instructions HTML.
     *
     * @return string
     */
    public function get_instructions() {
        return is_string( $this->instructions ) ? $this->instructions : '';
    }

    /**
     * Get decoded display styles array.
     *
     * @return array
     */
    public function get_styles() {
        if ( is_string( $this->styles_json ) ) {
            return json_decode( $this->styles_json, true ) ?: array();
        }
        return is_array( $this->styles_json ) ? $this->styles_json : array();
    }

    /**
     * Convert to array for database operations.
     *
     * @return array
     */
    public function to_array() {
        return array(
            'instrument_id'      => $this->instrument_id,
            'instrument_name'    => $this->instrument_name,
            'instrument_version' => $this->instrument_version,
            'instrument_key'     => $this->instrument_key,
            'sections'           => $this->sections,
            'scale_labels'       => $this->scale_labels,
            'instructions'       => $this->instructions,
            'status'             => $this->status,
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        );
    }
}
