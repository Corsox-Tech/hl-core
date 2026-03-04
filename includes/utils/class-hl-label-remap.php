<?php
if (!defined('ABSPATH')) exit;

/**
 * UI Label Remapping
 *
 * Intercepts WordPress i18n output for the hl-core text domain and
 * replaces internal terms with client-facing display labels.
 *
 * This is a UI-only layer — database columns, code variables, shortcode
 * tags, and internal identifiers remain unchanged.
 *
 * To revert: set HL_LABEL_REMAP_ENABLED to false, or delete this file
 * and its require_once in hl-core.php.
 *
 * @package HL_Core
 */
class HL_Label_Remap {

    /**
     * Master switch — set to false to disable all remapping instantly.
     */
    const ENABLED = true;

    /**
     * Term mappings: internal → client-facing.
     *
     * Order matters — longer phrases are listed first to avoid partial
     * replacements (e.g., "Track Type" before "Track").
     *
     * @var array<string, string>
     */
    private static $map = array(
        // Track → Partnership (longer phrases first)
        'Add New Track'         => 'Add New Partnership',
        'Back to Tracks'        => 'Back to Partnerships',
        'Track Dashboard'       => 'Partnership Dashboard',
        'Track Workspace'       => 'Partnership Workspace',
        'Track Name'            => 'Partnership Name',
        'Track Code'            => 'Partnership Code',
        'Create Track'          => 'Create Partnership',
        'Update Track'          => 'Update Partnership',
        'Track created successfully.' => 'Partnership created successfully.',
        'Track updated successfully.' => 'Partnership updated successfully.',
        'Track deleted successfully.' => 'Partnership deleted successfully.',
        'Track not found.'      => 'Partnership not found.',
        'Track is required for recomputing rollups.' => 'Partnership is required for recomputing rollups.',
        'Linked Tracks'         => 'Linked Partnerships',
        'Active Tracks'         => 'Active Partnerships',
        'Active Track'          => 'Active Partnership',
        'All Tracks'            => 'All Partnerships',
        'Clone to Track:'       => 'Clone to Partnership:',
        'My Track'              => 'My Partnership',
        '-- Select Track --'    => '-- Select Partnership --',
        '-- Select Track First --' => '-- Select Partnership First --',
        '-- Into Track --'      => '-- Into Partnership --',
        'Track Context:'        => 'Partnership Context:',
        'Track:'                => 'Partnership:',
        'Track: %s'             => 'Partnership: %s',
        'Tracks'                => 'Partnerships',
        'Track'                 => 'Partnership',

        // Activity → Component (longer phrases first)
        'Add New Activity'          => 'Add New Component',
        'Edit Activity'             => 'Edit Component',
        'New Activity'              => 'New Component',
        'Create Activity'           => 'Create Component',
        'Update Activity'           => 'Update Component',
        'Activity saved successfully.'   => 'Component saved successfully.',
        'Activity deleted successfully.' => 'Component deleted successfully.',
        'Activity not found.'       => 'Component not found.',
        'Activity Type'             => 'Component Type',
        'This Activity is Locked'   => 'This Component is Locked',
        'Activity will not be available until this date. Leave blank for no date restriction.' => 'Component will not be available until this date. Leave blank for no date restriction.',
        'Activity becomes available N days after the selected activity is completed.' => 'Component becomes available N days after the selected component is completed.',
        'Activities'                => 'Components',
        'Activity'                  => 'Component',
    );

    /**
     * Plural forms mapping for _n() / ngettext filter.
     * Keys are the singular form, values are [new_singular, new_plural].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private static $plural_map = array(
        'Active Track'  => array('Active Partnership', 'Active Partnerships'),
        '%d Active Track' => array('%d Active Partnership', '%d Active Partnerships'),
        'Activity'      => array('Component', 'Components'),
    );

    /**
     * Register WordPress gettext filters.
     */
    public static function init() {
        if (!self::ENABLED) {
            return;
        }

        add_filter('gettext', array(__CLASS__, 'remap_gettext'), 10, 3);
        add_filter('ngettext', array(__CLASS__, 'remap_ngettext'), 10, 5);
    }

    /**
     * Filter for __(), esc_html__(), esc_html_e(), esc_attr__().
     *
     * @param string $translated Translated text.
     * @param string $text       Original text.
     * @param string $domain     Text domain.
     * @return string
     */
    public static function remap_gettext($translated, $text, $domain) {
        if ($domain !== 'hl-core') {
            return $translated;
        }

        // Direct lookup first (most efficient).
        if (isset(self::$map[$text])) {
            return self::$map[$text];
        }

        return $translated;
    }

    /**
     * Filter for _n() plural forms.
     *
     * @param string $translated Translated text.
     * @param string $single     Singular form.
     * @param string $plural     Plural form.
     * @param int    $number     Count.
     * @param string $domain     Text domain.
     * @return string
     */
    public static function remap_ngettext($translated, $single, $plural, $number, $domain) {
        if ($domain !== 'hl-core') {
            return $translated;
        }

        if (isset(self::$plural_map[$single])) {
            $mapped = self::$plural_map[$single];
            return ($number === 1) ? $mapped[0] : $mapped[1];
        }

        return $translated;
    }
}
