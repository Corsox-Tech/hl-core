<?php
/**
 * Capabilities management
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Capabilities {
    
    /**
     * Get all capabilities
     */
    public static function get_all_capabilities() {
        return array(
            'manage_hl_core',
            'hl_view_cohorts',
            'hl_edit_cohorts',
            'hl_view_enrollments',
            'hl_edit_enrollments',
            'hl_view_assessments',
            'hl_view_assessment_responses',
            'hl_edit_assessments',
        );
    }
    
    /**
     * Get coach capabilities
     */
    public static function get_coach_capabilities() {
        return array(
            'manage_hl_core',
            'hl_view_cohorts',
            'hl_view_enrollments',
            'hl_view_assessments',
            'hl_view_assessment_responses',
        );
    }
}
