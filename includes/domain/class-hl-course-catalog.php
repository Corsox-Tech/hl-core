<?php
if (!defined('ABSPATH')) exit;

class HL_Course_Catalog {
    public $catalog_id;
    public $catalog_uuid;
    public $catalog_code;
    public $title;
    public $ld_course_en;
    public $ld_course_es;
    public $ld_course_pt;
    public $status;
    public $created_at;
    public $updated_at;

    public function __construct($data = array()) {
        $data = is_array($data) ? $data : array();
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Get associative array of non-null LD course IDs keyed by language.
     *
     * @return array e.g. ['en' => 30280, 'es' => 30304]
     */
    public function get_language_course_ids() {
        $ids = array();
        if (!empty($this->ld_course_en)) {
            $ids['en'] = absint($this->ld_course_en);
        }
        if (!empty($this->ld_course_es)) {
            $ids['es'] = absint($this->ld_course_es);
        }
        if (!empty($this->ld_course_pt)) {
            $ids['pt'] = absint($this->ld_course_pt);
        }
        return $ids;
    }

    /**
     * Resolve the LD course ID for a given language, falling back to English.
     *
     * @param string $lang Language code: en, es, or pt.
     * @return int|null LD course ID or null if nothing available.
     */
    public function resolve_course_id($lang = 'en') {
        if ($lang === null) {
            $lang = 'en';
        }
        $lang = strtolower(trim($lang));
        $allowed = array('en', 'es', 'pt');
        if (!in_array($lang, $allowed, true)) {
            $lang = 'en';
        }

        $property = 'ld_course_' . $lang;
        if (!empty($this->$property)) {
            return absint($this->$property);
        }

        // Fall back to English
        if ($lang !== 'en' && !empty($this->ld_course_en)) {
            return absint($this->ld_course_en);
        }

        return null;
    }

    /**
     * Get admin-display language badges string, e.g. "[EN] [ES]".
     *
     * @return string
     */
    public function get_language_badges() {
        $badges = array();
        if (!empty($this->ld_course_en)) {
            $badges[] = '[EN]';
        }
        if (!empty($this->ld_course_es)) {
            $badges[] = '[ES]';
        }
        if (!empty($this->ld_course_pt)) {
            $badges[] = '[PT]';
        }
        return implode(' ', $badges);
    }

    /**
     * Resolve the LD course ID for a component based on enrollment language.
     *
     * Catalog path: uses catalog_id → resolve_course_id(language_preference).
     * Fallback: external_ref['course_id'] for non-catalog components.
     *
     * @param HL_Component  $component
     * @param object|null   $enrollment  Must have ->language_preference property.
     * @return int|null LD course post ID.
     */
    public static function resolve_ld_course_id($component, $enrollment = null) {
        if (!empty($component->catalog_id)) {
            $repo  = new HL_Course_Catalog_Repository();
            $entry = $repo->get_by_id($component->catalog_id);
            if ($entry) {
                $lang = ($enrollment && !empty($enrollment->language_preference))
                    ? $enrollment->language_preference
                    : 'en';
                return $entry->resolve_course_id($lang);
            }
        }
        // Fallback to external_ref.
        $ref = $component->get_external_ref_array();
        return isset($ref['course_id']) ? absint($ref['course_id']) : null;
    }

    public function to_array() {
        return get_object_vars($this);
    }
}
