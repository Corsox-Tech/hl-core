<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Template Migration
 *
 * Task 6.1: Migrates 6 coaching email templates from wp_options
 * (HL_Admin_Email_Templates format) into hl_email_template rows.
 *
 * Task 6.1b: Backfills hl_account_activated usermeta for all existing
 * enrolled users, preventing invitation email flood on workflow activation.
 *
 * Both migrations are idempotent (check completion flags in wp_options).
 *
 * @package HL_Core
 */
class HL_Email_Template_Migration {

    const FLAG_TEMPLATES = 'hl_email_migration_templates_done';
    const FLAG_BACKFILL  = 'hl_email_migration_backfill_activated';

    /**
     * Run all migrations. Safe to call multiple times.
     */
    public static function run() {
        self::migrate_coaching_templates();
        self::backfill_account_activated();
    }

    // =========================================================================
    // Task 6.1: Coaching Template Migration
    // =========================================================================

    /**
     * Migrate 6 coaching email templates from wp_options to hl_email_template.
     */
    private static function migrate_coaching_templates() {
        if ( get_option( self::FLAG_TEMPLATES ) ) {
            return; // Already done.
        }

        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_template";

        // Check if the table exists.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return; // Table not created yet.
        }

        $stored = get_option( 'hl_email_templates', array() );
        if ( is_string( $stored ) ) {
            $stored = json_decode( $stored, true ) ?: array();
        }

        // Default subjects and bodies from HL_Admin_Email_Templates::get_defaults().
        $template_map = array(
            'session_booked_mentor' => array(
                'name'     => 'Session Booked — Mentor',
                'category' => 'reminder',
            ),
            'session_booked_coach' => array(
                'name'     => 'Session Booked — Coach',
                'category' => 'reminder',
            ),
            'session_rescheduled_mentor' => array(
                'name'     => 'Session Rescheduled — Mentor',
                'category' => 'reminder',
            ),
            'session_rescheduled_coach' => array(
                'name'     => 'Session Rescheduled — Coach',
                'category' => 'reminder',
            ),
            'session_cancelled_mentor' => array(
                'name'     => 'Session Cancelled — Mentor',
                'category' => 'reminder',
            ),
            'session_cancelled_coach' => array(
                'name'     => 'Session Cancelled — Coach',
                'category' => 'reminder',
            ),
        );

        $renderer = HL_Email_Block_Renderer::instance();

        foreach ( $template_map as $key => $meta ) {
            // Skip if already migrated.
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT template_id FROM {$table} WHERE template_key = %s",
                $key
            ) );
            if ( $exists ) {
                continue;
            }

            // Get the stored template (or fall back to defaults via the admin class).
            $tpl = array( 'subject' => '', 'body' => '' );
            if ( class_exists( 'HL_Admin_Email_Templates' ) ) {
                $tpl = HL_Admin_Email_Templates::get_template( $key );
            } elseif ( isset( $stored[ $key ] ) ) {
                $tpl = $stored[ $key ];
            }

            $subject    = $tpl['subject'] ?? '';
            $body_html  = $tpl['body'] ?? '';
            $blocks     = $renderer->build_legacy_template_blocks( $body_html );

            $wpdb->insert( $table, array(
                'template_key' => $key,
                'name'         => $meta['name'],
                'subject'      => $subject,
                'blocks_json'  => wp_json_encode( $blocks ),
                'category'     => $meta['category'],
                'status'       => 'active',
                'created_by'   => 0,
            ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' ) );
        }

        update_option( self::FLAG_TEMPLATES, '1' );
    }

    // =========================================================================
    // Task 6.1b: Backfill hl_account_activated
    // =========================================================================

    /**
     * Set hl_account_activated = '1' for all enrolled users who don't have it.
     * Prevents invitation emails from firing for already-active users when
     * workflows are first activated.
     */
    private static function backfill_account_activated() {
        if ( get_option( self::FLAG_BACKFILL ) ) {
            return; // Already done.
        }

        global $wpdb;

        $wpdb->query(
            "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value)
             SELECT DISTINCT e.user_id, 'hl_account_activated', '1'
             FROM {$wpdb->prefix}hl_enrollment e
             WHERE e.user_id NOT IN (
                 SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'hl_account_activated'
             )"
        );

        update_option( self::FLAG_BACKFILL, '1' );
    }
}
