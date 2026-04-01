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
