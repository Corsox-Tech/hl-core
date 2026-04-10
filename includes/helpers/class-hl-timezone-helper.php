<?php
if (!defined('ABSPATH')) exit;

/**
 * Timezone Helper
 *
 * Renders a timezone <select> dropdown with common US timezones grouped
 * at the top, followed by all IANA timezones from PHP's DateTimeZone.
 *
 * @package HL_Core
 */
class HL_Timezone_Helper {

	/**
	 * Render a timezone <select> element.
	 *
	 * @param string $id       HTML id for the select.
	 * @param string $selected Currently selected IANA timezone.
	 * @param string $class    CSS class for the select.
	 */
	public static function render_timezone_select($id, $selected = '', $class = '') {
		$common = array(
			'America/New_York',
			'America/Chicago',
			'America/Denver',
			'America/Los_Angeles',
			'America/Anchorage',
			'Pacific/Honolulu',
		);

		$all = DateTimeZone::listIdentifiers();

		$class_attr = $class ? ' class="' . esc_attr($class) . '"' : '';
		echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($id) . '"' . $class_attr . '>';

		// Common US timezones.
		echo '<optgroup label="' . esc_attr__('Common', 'hl-core') . '">';
		foreach ($common as $tz_name) {
			$label = self::format_tz_label($tz_name);
			$sel   = ($selected === $tz_name) ? ' selected' : '';
			echo '<option value="' . esc_attr($tz_name) . '"' . $sel . '>' . esc_html($label) . '</option>';
		}
		echo '</optgroup>';

		// All timezones.
		echo '<optgroup label="' . esc_attr__('All Timezones', 'hl-core') . '">';
		foreach ($all as $tz_name) {
			// Skip common ones (already listed above).
			if (in_array($tz_name, $common, true)) {
				continue;
			}
			$label = self::format_tz_label($tz_name);
			$sel   = ($selected === $tz_name) ? ' selected' : '';
			echo '<option value="' . esc_attr($tz_name) . '"' . $sel . '>' . esc_html($label) . '</option>';
		}
		echo '</optgroup>';

		echo '</select>';
	}

	/**
	 * Format a session_datetime (stored in WP local time) for display in a target timezone.
	 *
	 * This is the single source of truth for session time formatting across the plugin.
	 * All display sites should use this instead of strtotime() + date_i18n().
	 *
	 * @param string $wp_datetime  Value from session_datetime column (Y-m-d H:i:s in WP local TZ).
	 * @param string $target_tz    IANA timezone string for the viewer (e.g. 'America/Los_Angeles').
	 * @param string $date_format  PHP date format for the date portion.
	 * @param string $time_format  PHP date format for the time portion.
	 * @return array { date: string, time: string, tz_abbr: string, full: string }
	 */
	public static function format_session_time(
		$wp_datetime,
		$target_tz,
		$date_format = 'l, F j, Y',
		$time_format = 'g:i A'
	) {
		$empty = array('date' => '', 'time' => '', 'tz_abbr' => '', 'full' => '');
		if (empty($wp_datetime) || empty($target_tz)) {
			return $empty;
		}
		try {
			$dt = new DateTime($wp_datetime, wp_timezone());
			$dt->setTimezone(new DateTimeZone($target_tz));
			$abbr = $dt->format('T');
			$date = $dt->format($date_format);
			$time = $dt->format($time_format) . ' ' . $abbr;
			return array(
				'date'    => $date,
				'time'    => $time,
				'tz_abbr' => $abbr,
				'full'    => $date . ' at ' . $time,
			);
		} catch (Exception $e) {
			return $empty;
		}
	}

	/**
	 * Format a timezone name into a readable label with abbreviation.
	 *
	 * @param string $tz_name IANA timezone name.
	 * @return string e.g. "America/New_York (EDT)"
	 */
	private static function format_tz_label($tz_name) {
		try {
			$tz  = new DateTimeZone($tz_name);
			$now = new DateTime('now', $tz);
			$abbr = $now->format('T');
			$offset_secs = $tz->getOffset($now);
			$sign = $offset_secs >= 0 ? '+' : '-';
			$offset_secs_abs = abs($offset_secs);
			$offset_h = (int) floor($offset_secs_abs / 3600);
			$offset_m = (int) (($offset_secs_abs % 3600) / 60);
			$offset_str = 'UTC' . $sign . $offset_h . ($offset_m ? sprintf(':%02d', $offset_m) : '');
			return $tz_name . ' (' . $abbr . ', ' . $offset_str . ')';
		} catch (Exception $e) {
			return $tz_name;
		}
	}
}
