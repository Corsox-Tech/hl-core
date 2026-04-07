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

    /**
     * Whether the current user should see archived cycle data hidden.
     *
     * Returns true for non-privileged users (teachers, mentors, leaders).
     * Returns false for admins and coaches — both have the manage_hl_core
     * capability (coaches granted in HL_Installer).
     *
     * @return bool
     */
    public static function should_hide_archived(): bool {
        return ! current_user_can( 'manage_hl_core' );
    }
}
