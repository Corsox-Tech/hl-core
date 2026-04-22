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

    /**
     * Return admin defaults, merging any stored option over constant DEFAULTS.
     *
     * `wp_parse_args` guarantees forward compatibility: new keys added to
     * DEFAULTS in future releases surface without a migration, and a partially
     * populated option (e.g. legacy rows missing a newly-added field) still
     * resolves to a complete settings array.
     *
     * @return array
     */
    public static function get_admin_defaults() {
        $stored = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return wp_parse_args( $stored, self::DEFAULTS );
    }

    /**
     * Validate + persist admin defaults. Emits `coach_zoom_defaults_updated`
     * audit event on success. Returns WP_Error (validate() passthrough) on
     * invalid input.
     *
     * Note: `$actor_user_id` is accepted for API symmetry with
     * `save_coach_overrides()`, but is NOT forwarded to HL_Audit_Service::log()
     * — the audit service hardcodes `actor_user_id = get_current_user_id()`,
     * and an entry in the `$data` array would be silently dropped.
     *
     * @param array $values          Raw input (partial allowed — merges over current stored state).
     * @param int   $actor_user_id   Actor for symmetry only (see note above).
     * @return true|WP_Error
     */
    public static function save_admin_defaults( array $values, $actor_user_id ) {
        $sanitized = self::validate( $values, 0 ); // coach_user_id=0 = skip self-email check
        if ( is_wp_error( $sanitized ) ) {
            return $sanitized;
        }

        $before = self::get_admin_defaults();
        $after  = wp_parse_args( $sanitized, $before );

        update_option( self::OPTION_KEY, $after, true ); // autoload=yes

        if ( class_exists( 'HL_Audit_Service' ) ) {
            $diff = array();
            foreach ( $after as $k => $v ) {
                if ( ! array_key_exists( $k, $before ) || $before[ $k ] !== $v ) {
                    $diff[ $k ] = array(
                        'before' => array_key_exists( $k, $before ) ? $before[ $k ] : null,
                        'after'  => $v,
                    );
                }
            }
            HL_Audit_Service::log( 'coach_zoom_defaults_updated', array(
                'entity_type' => 'coach_zoom_defaults',
                'after_data'  => array( 'diff' => $diff ),
            ) );
        }

        return true;
    }
    /**
     * Return the sparse set of fields a coach has explicitly overridden.
     *
     * Sparse output: only columns that are NOT NULL in the row appear as keys.
     * This lets callers distinguish "coach has no override" (key absent) from
     * "coach overrode to 0/off" (key present with value 0). Empty-string
     * `alternative_hosts` is preserved (distinct from NULL).
     *
     * Always includes a `_meta` key with `updated_at` / `updated_by_user_id`
     * (for the admin overview "last edited by X on Y" display). `_meta` is
     * present even when every override field is NULL, as long as the row exists.
     *
     * Defensive: if the table is missing (e.g. failed migration), returns an
     * empty array so `resolve_for_coach()` falls back to admin defaults and
     * the booking flow MUST NOT die.
     *
     * @param int $coach_user_id
     * @return array Sparse override fields + `_meta`, or `array()` if no row / table missing.
     */
    public static function get_coach_overrides( $coach_user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SLUG;

        // Defensive: if the table doesn't exist (failed migration), return empty
        // so resolve_for_coach() falls back to defaults — booking flow MUST NOT die.
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( $exists !== $table ) {
            return array();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT waiting_room, mute_upon_entry, join_before_host, alternative_hosts, updated_at, updated_by_user_id
                 FROM {$table} WHERE coach_user_id = %d",
                absint( $coach_user_id )
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return array();
        }

        // Sparse: drop NULL columns. Empty string for alternative_hosts is preserved.
        $sparse = array();
        foreach ( array( 'waiting_room', 'mute_upon_entry', 'join_before_host' ) as $f ) {
            if ( $row[ $f ] !== null ) {
                $sparse[ $f ] = (int) $row[ $f ];
            }
        }
        if ( $row['alternative_hosts'] !== null ) {
            $sparse['alternative_hosts'] = (string) $row['alternative_hosts'];
        }

        // Metadata for admin overview "last edited by X on Y".
        $sparse['_meta'] = array(
            'updated_at'         => $row['updated_at'],
            'updated_by_user_id' => $row['updated_by_user_id'] !== null ? (int) $row['updated_by_user_id'] : null,
        );

        return $sparse;
    }
    public static function save_coach_overrides( $coach_user_id, array $overrides, $actor_user_id, array $reset_fields = array() ) {
        return new WP_Error( 'not_implemented', 'Pending Task B5' );
    }
    public static function resolve_for_coach( $coach_user_id ) {
        return self::DEFAULTS; // TODO Task B6
    }
    /**
     * Validate + normalize an input array of coach Zoom meeting settings.
     *
     * - Bool fields are coerced to 0|1.
     * - `waiting_room=1` AND `join_before_host=1` is normalized to `join_before_host=0`
     *   (the single canonical place for this rule; payload builders trust the resolved array).
     * - `alternative_hosts` is a CSV string: length <= 1024, each entry must be a valid
     *   email, <= 10 addresses total, and may not contain the coach's own Zoom email.
     *   Empty string is preserved (distinct from NULL/missing).
     *
     * Returns a normalized associative array on success, or a WP_Error with structured
     * `error_data` on failure. All field-level errors include at least
     * `array( 'field' => '<field_name>' )`; invalid-email errors additionally
     * include `invalid_emails`.
     *
     * @param array    $values         Raw input (admin defaults or coach overrides).
     * @param int|null $coach_user_id  Coach user ID for self-email check; 0/null skips it.
     * @return array|WP_Error
     */
    public static function validate( array $values, $coach_user_id ) {
        $out = array();

        // Bool fields: coerce to 0|1.
        foreach ( array( 'waiting_room', 'mute_upon_entry', 'join_before_host', 'password_required', 'meeting_authentication' ) as $bool_field ) {
            if ( array_key_exists( $bool_field, $values ) ) {
                $out[ $bool_field ] = ! empty( $values[ $bool_field ] ) ? 1 : 0;
            }
        }

        // waiting_room=1 AND join_before_host=1 -> jbh=0 (canonical normalization).
        if ( ! empty( $out['waiting_room'] ) && ! empty( $out['join_before_host'] ) ) {
            $out['join_before_host'] = 0;
        }

        // alternative_hosts.
        if ( array_key_exists( 'alternative_hosts', $values ) ) {
            $raw = is_string( $values['alternative_hosts'] ) ? $values['alternative_hosts'] : '';

            if ( strlen( $raw ) > 1024 ) {
                return new WP_Error(
                    'alternative_hosts_too_long',
                    __( 'Alternative hosts list exceeds 1024 characters.', 'hl-core' ),
                    array( 'field' => 'alternative_hosts' )
                );
            }

            $emails  = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
            $cleaned = array();
            $invalid = array();

            foreach ( $emails as $email ) {
                $sanitized = sanitize_email( strtolower( $email ) );
                if ( ! $sanitized || ! is_email( $sanitized ) ) {
                    $invalid[] = $email;
                } else {
                    $cleaned[] = $sanitized;
                }
            }

            if ( ! empty( $invalid ) ) {
                return new WP_Error(
                    'invalid_alternative_hosts',
                    __( 'One or more alternative-host emails are invalid.', 'hl-core' ),
                    array( 'field' => 'alternative_hosts', 'invalid_emails' => $invalid )
                );
            }

            if ( count( $cleaned ) > 10 ) {
                return new WP_Error(
                    'too_many_alternative_hosts',
                    __( 'Up to 10 alternative hosts are allowed.', 'hl-core' ),
                    array( 'field' => 'alternative_hosts' )
                );
            }

            // Reject coach's own Zoom email.
            if ( $coach_user_id && class_exists( 'HL_Zoom_Integration' ) ) {
                $coach_email = strtolower( (string) HL_Zoom_Integration::instance()->get_coach_email( $coach_user_id ) );
                if ( $coach_email && in_array( $coach_email, $cleaned, true ) ) {
                    return new WP_Error(
                        'self_in_alternative_hosts',
                        __( 'You cannot add your own Zoom email as an alternative host.', 'hl-core' ),
                        array( 'field' => 'alternative_hosts' )
                    );
                }
            }

            $out['alternative_hosts'] = implode( ',', $cleaned );
        }

        return $out;
    }
    public static function preflight_alternative_hosts( $coach_user_id, $alternative_hosts_csv ) {
        return true; // TODO Task C1
    }
}
