<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Coach Zoom Meeting Settings Service.
 *
 * Resolves per-coach Zoom meeting settings (admin default + coach override),
 * persists changes with audit logging, and pre-flights alternative_hosts
 * against the Zoom API.
 *
 * @package HL_Core
 */
class HL_Coach_Zoom_Settings_Service {

    const OPTION_KEY = 'hl_zoom_coaching_defaults';
    const TABLE_SLUG = 'hl_coach_zoom_settings';

    /**
     * NOTE: `password_required` and `meeting_authentication` are admin-only
     * fields and intentionally do NOT exist as columns in hl_coach_zoom_settings.
     * They live only in the WP option (admin defaults) — coaches cannot override.
     */
    const DEFAULTS = array(
        'waiting_room'           => 1,
        'mute_upon_entry'        => 0,
        'join_before_host'       => 0,
        'alternative_hosts'      => '',
        'password_required'      => 0,
        'meeting_authentication' => 0,
    );

    public static function get_admin_defaults() {
        return self::DEFAULTS; // TODO Task B3
    }
    public static function save_admin_defaults( array $values, $actor_user_id ) {
        return new WP_Error( 'not_implemented', 'Pending Task B3' );
    }
    public static function get_coach_overrides( $coach_user_id ) {
        return array(); // TODO Task B4
    }
    public static function save_coach_overrides( $coach_user_id, array $overrides, $actor_user_id, array $reset_fields = array() ) {
        return new WP_Error( 'not_implemented', 'Pending Task B5' );
    }
    public static function resolve_for_coach( $coach_user_id ) {
        return self::DEFAULTS; // TODO Task B6
    }
    public static function validate( array $values, $coach_user_id ) {
        return new WP_Error( 'not_implemented', 'Pending Task B2' );
    }
    public static function preflight_alternative_hosts( $coach_user_id, $alternative_hosts_csv ) {
        return true; // TODO Task C1
    }
}
