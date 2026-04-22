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
    /**
     * Persist a coach's override row with transactional audit-diff safety.
     *
     * Uses `$wpdb->insert` / `$wpdb->update` (which natively bind NULL when a
     * value is null) rather than raw `INSERT … ON DUPLICATE KEY UPDATE`
     * (whose NULL semantics through `prepare()` are subtle). The SELECT-old-row
     * and write happen inside a `START TRANSACTION` so concurrent saves cannot
     * produce a wrong audit diff.
     *
     * `$reset_fields` (4th arg) NULLs the named columns inside the same
     * transaction BEFORE applying `$overrides` — diff is computed against the
     * final state so the audit log fires once for the merged result.
     *
     * Admin-only keys (`password_required`, `meeting_authentication`) are
     * stripped from sanitized input: coaches cannot override them and the
     * columns don't exist on this table.
     *
     * @param int   $coach_user_id
     * @param array $overrides       Partial values (validated via self::validate()).
     * @param int   $actor_user_id   Written to `updated_by_user_id` on INSERT and UPDATE.
     * @param array $reset_fields    Column names to force-NULL (subset of allowed_cols).
     * @return true|WP_Error
     */
    public static function save_coach_overrides( $coach_user_id, array $overrides, $actor_user_id, array $reset_fields = array() ) {
        global $wpdb;
        $coach_user_id = absint( $coach_user_id );
        if ( ! $coach_user_id ) {
            return new WP_Error( 'invalid_coach', __( 'Invalid coach user ID.', 'hl-core' ) );
        }

        $sanitized = self::validate( $overrides, $coach_user_id );
        if ( is_wp_error( $sanitized ) ) {
            return $sanitized;
        }

        // Strip admin-only keys — coaches can't override these.
        unset( $sanitized['password_required'], $sanitized['meeting_authentication'] );

        // Mandatory preflight when alt_hosts non-empty (per spec §"Service contracts").
        if ( ! empty( $sanitized['alternative_hosts'] ) ) {
            $pf = self::preflight_alternative_hosts( $coach_user_id, $sanitized['alternative_hosts'] );
            if ( is_wp_error( $pf ) ) {
                return $pf;
            }
        }

        $table        = $wpdb->prefix . self::TABLE_SLUG;
        $allowed_cols = array( 'waiting_room', 'mute_upon_entry', 'join_before_host', 'alternative_hosts' );

        $wpdb->query( 'START TRANSACTION' );

        $before_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT waiting_room, mute_upon_entry, join_before_host, alternative_hosts FROM {$table} WHERE coach_user_id = %d FOR UPDATE",
                $coach_user_id
            ),
            ARRAY_A
        );
        $row_exists = ( $before_row !== null );

        // Apply resets first (raw SQL for unambiguous NULL binding).
        foreach ( $reset_fields as $f ) {
            if ( ! in_array( $f, $allowed_cols, true ) ) {
                continue;
            }
            if ( ! $row_exists ) {
                continue; // nothing to reset
            }
            $r = $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET {$f} = NULL WHERE coach_user_id = %d",
                $coach_user_id
            ) );
            if ( $r === false ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'db_write_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Reset failed.', 'hl-core' ) );
            }
            $before_row[ $f ] = null; // reflect in our local copy for diff calc
        }

        // Compute new row (merge sanitized overrides over the post-reset before_row).
        $col = function ( $field ) use ( $sanitized, $before_row, $row_exists ) {
            if ( array_key_exists( $field, $sanitized ) ) {
                return $field === 'alternative_hosts' ? (string) $sanitized[ $field ] : (int) $sanitized[ $field ];
            }
            return $row_exists ? $before_row[ $field ] : null;
        };

        $new_row = array(
            'waiting_room'       => $col( 'waiting_room' ),
            'mute_upon_entry'    => $col( 'mute_upon_entry' ),
            'join_before_host'   => $col( 'join_before_host' ),
            'alternative_hosts'  => $col( 'alternative_hosts' ),
            'updated_by_user_id' => $actor_user_id,
        );

        if ( ! empty( $overrides ) ) {
            if ( $row_exists ) {
                $result = $wpdb->update(
                    $table,
                    $new_row,
                    array( 'coach_user_id' => $coach_user_id ),
                    null,
                    array( '%d' )
                );
            } else {
                $insert = array_merge( array( 'coach_user_id' => $coach_user_id ), $new_row );
                $result = $wpdb->insert( $table, $insert );
            }
            if ( $result === false ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'db_write_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Failed to save coach Zoom settings.', 'hl-core' ) );
            }
        }

        $wpdb->query( 'COMMIT' );

        // Audit diff (excludes updated_at + updated_by_user_id).
        if ( class_exists( 'HL_Audit_Service' ) ) {
            $diff = array();
            foreach ( $allowed_cols as $f ) {
                $b = $row_exists ? $before_row[ $f ] : null;
                $a = $new_row[ $f ];
                if ( $b !== $a ) {
                    $diff[ $f ] = array( 'before' => $b, 'after' => $a );
                }
            }
            if ( ! empty( $diff ) ) {
                HL_Audit_Service::log( 'coach_zoom_settings_updated', array(
                    'entity_type' => 'coach_zoom_settings',
                    'entity_id'   => $coach_user_id,
                    'after_data'  => array( 'diff' => $diff ),
                ) );

                if ( isset( $diff['alternative_hosts'] ) ) {
                    wp_schedule_single_event(
                        time(),
                        'hl_notify_alt_hosts_change',
                        array( $coach_user_id, $actor_user_id, $diff['alternative_hosts']['before'], $diff['alternative_hosts']['after'] )
                    );
                }
            }
        }

        return true;
    }
    /**
     * Resolve the fully-populated Zoom meeting settings for a coach.
     *
     * 3-tier fallback:
     *   1. Coach override (sparse; per-field NON-NULL wins via `get_coach_overrides()`).
     *   2. Admin default (WP option; filled from DEFAULTS for any missing key).
     *   3. Hardcoded `DEFAULTS` constant (ultimate fallback, already seeded into
     *      tier 2 by `get_admin_defaults()`).
     *
     * Admin-only keys (`password_required`, `meeting_authentication`) never appear
     * in the coach overrides array — by design, coaches cannot override them — so
     * they always flow through from the admin defaults (tier 2) unchanged. The
     * returned array is always fully-populated with the same keys as
     * `self::DEFAULTS` and contains NO NULL values and NO `_meta` (which is for
     * the admin overview only, not the Zoom payload).
     *
     * @param int $coach_user_id
     * @return array Fully-populated settings array (same shape as self::DEFAULTS).
     */
    public static function resolve_for_coach( $coach_user_id ) {
        $defaults  = self::get_admin_defaults();
        $overrides = self::get_coach_overrides( $coach_user_id );

        // Strip metadata before merging (used by admin overview, not by Zoom payload).
        unset( $overrides['_meta'] );

        // Coach override (non-NULL) wins. Admin-only keys (password_required,
        // meeting_authentication) are never present in $overrides and flow through
        // from $defaults unchanged — by design.
        return array_merge( $defaults, $overrides );
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
