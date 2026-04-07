<?php
if (!defined('ABSPATH')) exit;

/**
 * Repository for the hl_user_profile table.
 *
 * Handles all direct DB operations for user profile data.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE to avoid data loss (spec C1).
 *
 * @package HL_Core
 */
class HL_Auth_Repository {

    /**
     * Get a user's profile row.
     *
     * @param int $user_id
     * @return object|null Profile row or null.
     */
    public static function get($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_user_profile';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Insert or update a user's profile (spec C1).
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to preserve created_at
     * and avoid the DELETE+INSERT behavior of REPLACE INTO.
     *
     * @param int   $user_id
     * @param array $data Column => value pairs.
     * @return bool True on success.
     */
    public static function upsert($user_id, $data) {
        if ($user_id < 1) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'hl_user_profile';

        // Allowlist of columns (spec FC5: use esc_sql + int cast instead of
        // $wpdb->prepare to avoid vsprintf garbling values containing literal %).
        $allowed_string_cols = array(
            'nickname', 'phone_country_code', 'phone_number', 'gender',
            'ethnicity', 'location_state', 'age_range', 'preferred_language',
            'years_exp_industry', 'years_exp_position', 'job_title',
            'social_instagram', 'social_twitter', 'social_linkedin',
            'social_facebook', 'social_website',
            'consent_given_at', 'consent_version', 'profile_completed_at',
        );

        // Build column/value pairs for INSERT
        $columns = array('`user_id`');
        $values  = array((int) $user_id);
        $update_parts = array();

        foreach ($allowed_string_cols as $col) {
            if (array_key_exists($col, $data)) {
                $columns[]      = "`{$col}`";
                if ($data[$col] === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = "'" . esc_sql($data[$col]) . "'";
                }
                $update_parts[] = "`{$col}` = VALUES(`{$col}`)";
            }
        }

        if (empty($update_parts)) {
            return false;
        }

        $cols_str   = implode(', ', $columns);
        $vals_str   = implode(', ', $values);
        $update_str = implode(', ', $update_parts);

        $sql = "INSERT INTO `{$table}` ({$cols_str}) VALUES ({$vals_str})
                ON DUPLICATE KEY UPDATE {$update_str}";

        // Direct query -- no $wpdb->prepare() because the column set is dynamic
        // and values are pre-escaped via esc_sql() / int cast.
        $result = $wpdb->query($sql);

        // Invalidate cache on every write (spec I12)
        wp_cache_delete('profile_complete_' . $user_id, 'hl_profiles');

        return $result !== false;
    }

    /**
     * Delete a user's profile row + cache (spec I19).
     *
     * @param int $user_id
     */
    public static function delete($user_id) {
        if ($user_id < 1) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'hl_user_profile';

        $wpdb->delete($table, array('user_id' => $user_id), array('%d'));
        wp_cache_delete('profile_complete_' . $user_id, 'hl_profiles');
    }

    /**
     * Check if a user's profile has all required fields (spec Section G).
     *
     * Required fields for completion:
     * - WP: first_name, last_name (checked via get_userdata)
     * - Profile: nickname, gender, ethnicity (non-empty JSON array), location_state,
     *   age_range, preferred_language, years_exp_industry, years_exp_position, consent_given_at
     *
     * Optional fields (NOT required for completion):
     * - phone_number, phone_country_code, job_title
     * - social_instagram, social_twitter, social_linkedin, social_facebook, social_website
     *
     * @param int $user_id
     * @return bool
     */
    public static function is_complete($user_id) {
        // NOTE: Caching is handled by HL_Auth_Service::is_profile_complete().
        // This method does the raw DB check only.

        global $wpdb;
        $table = $wpdb->prefix . 'hl_user_profile';

        // Check WP user fields first
        $user = get_userdata($user_id);
        if (!$user || empty($user->first_name) || empty($user->last_name)) {
            return false;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT nickname, gender, ethnicity, location_state, age_range,
                    preferred_language, years_exp_industry, years_exp_position,
                    consent_given_at, profile_completed_at
             FROM `{$table}` WHERE user_id = %d",
            $user_id
        ));

        if (!$row) {
            return false;
        }

        // Fast path: profile_completed_at is set atomically on submission (spec FI6)
        if (!empty($row->profile_completed_at) && !empty($row->consent_given_at)) {
            return true;
        }

        // Fallback: check individual required fields
        if (empty($row->nickname))            return false;
        if (empty($row->gender))              return false;
        if (empty($row->location_state))      return false;
        if (empty($row->age_range))           return false;
        if (empty($row->preferred_language))  return false;
        if (empty($row->years_exp_industry))  return false;
        if (empty($row->years_exp_position))  return false;
        if (empty($row->consent_given_at))    return false;

        // Ethnicity: must be a non-empty JSON array
        $ethnicity = json_decode($row->ethnicity ?? '', true);
        if (empty($ethnicity) || !is_array($ethnicity)) {
            return false;
        }

        return true;
    }
}
