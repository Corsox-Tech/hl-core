<?php
/**
 * Security and authorization
 *
 * @package HL_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class HL_Security {
    
    /**
     * Check if current user can manage HL Core
     */
    public static function can_manage() {
        return current_user_can('manage_hl_core');
    }
    
    /**
     * Check if current user is staff (admin or coach)
     */
    public static function is_staff() {
        return current_user_can('manage_hl_core');
    }
    
    /**
     * Assert capability or die
     */
    public static function assert_can($capability, $context = array()) {
        if (!current_user_can($capability)) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }
    }
}
