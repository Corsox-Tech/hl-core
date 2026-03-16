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
        // Track → Cycle (longer phrases first)
        'Add New Track'         => 'Add New Cycle',
        'Back to Tracks'        => 'Back to Cycles',
        'Track Dashboard'       => 'Cycle Dashboard',
        'Track Workspace'       => 'Cycle Workspace',
        'Track Name'            => 'Cycle Name',
        'Track Code'            => 'Cycle Code',
        'Create Track'          => 'Create Cycle',
        'Update Track'          => 'Update Cycle',
        'Track created successfully.' => 'Cycle created successfully.',
        'Track updated successfully.' => 'Cycle updated successfully.',
        'Track deleted successfully.' => 'Cycle deleted successfully.',
        'Track not found.'      => 'Cycle not found.',
        'Track is required for recomputing rollups.' => 'Cycle is required for recomputing rollups.',
        'Linked Tracks'         => 'Linked Cycles',
        'Active Tracks'         => 'Active Cycles',
        'Active Track'          => 'Active Cycle',
        'All Tracks'            => 'All Cycles',
        'Clone to Track:'       => 'Clone to Cycle:',
        'My Track'              => 'My Cycle',
        '-- Select Track --'    => '-- Select Cycle --',
        '-- Select Track First --' => '-- Select Cycle First --',
        '-- Into Track --'      => '-- Into Cycle --',
        'Track Context:'        => 'Cycle Context:',
        'Track:'                => 'Cycle:',
        'Track: %s'             => 'Cycle: %s',
        'Tracks'                => 'Cycles',
        'Track'                 => 'Cycle',

        // Phase → Cycle (longer phrases first)
        'Phase saved successfully.'  => 'Cycle saved successfully.',
        'Phase deleted successfully.' => 'Cycle deleted successfully.',
        'Phase not found.'           => 'Cycle not found.',
        'Phase number %d already exists for this track.' => 'Cycle number %d already exists for this cycle.',
        'Program (full: Phases, Pathways, Teams, Coaching, Assessments)' => 'Program (full: Cycles, Pathways, Teams, Coaching, Assessments)',
        'Course (simple: auto-created single Phase + Pathway)' => 'Course (simple: auto-created single Cycle + Pathway)',
        'No phases defined for this track yet.' => 'No cycles defined for this cycle yet.',
        'Add Phase'                  => 'Add Cycle',
        'New Phase'                  => 'New Cycle',
        'Create Phase'               => 'Create Cycle',
        'Update Phase'               => 'Update Cycle',
        'Phase Name'                 => 'Cycle Name',
        'Phase Number'               => 'Cycle Number',
        '-- Default Phase --'        => '-- Default Cycle --',
        'Phases'                     => 'Cycles',
        'Phase'                      => 'Cycle',

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
        'Active Track'  => array('Active Cycle', 'Active Cycles'),
        '%d Active Track' => array('%d Active Cycle', '%d Active Cycles'),
        'Phase'         => array('Cycle', 'Cycles'),
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
