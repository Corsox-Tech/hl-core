<?php
if (!defined('ABSPATH')) exit;

class HL_Survey {
    public $survey_id;
    public $survey_uuid;
    public $internal_name;
    public $display_name;
    public $survey_type;
    public $version;
    public $questions_json;
    public $scale_labels_json;
    public $intro_text_json;
    public $group_labels_json;
    public $status;
    public $created_at;
    public $updated_at;

    /** @var array|null Cached decoded questions */
    private $questions_cache = null;

    public function __construct($data = array()) {
        $data = is_array($data) ? $data : array();
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        // Type-cast IDs
        if ($this->survey_id !== null) {
            $this->survey_id = absint($this->survey_id);
        }
        if ($this->version !== null) {
            $this->version = absint($this->version);
        }
    }

    /**
     * Get decoded questions array.
     *
     * @return array
     */
    public function get_questions() {
        if ($this->questions_cache !== null) {
            return $this->questions_cache;
        }
        if (empty($this->questions_json)) {
            $this->questions_cache = array();
            return $this->questions_cache;
        }
        $decoded = json_decode($this->questions_json, true);
        $this->questions_cache = is_array($decoded) ? $decoded : array();
        return $this->questions_cache;
    }

    /**
     * Get decoded scale labels.
     *
     * @return array e.g. {"likert_5":{"1":{"en":"Strongly Disagree",...},...}}
     */
    public function get_scale_labels() {
        if (empty($this->scale_labels_json)) {
            return array();
        }
        $decoded = json_decode($this->scale_labels_json, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Get intro text for a language, falling back to English.
     *
     * @param string $lang Language code: en, es, or pt.
     * @return string
     */
    public function get_intro_text($lang = 'en') {
        if (empty($this->intro_text_json)) {
            return '';
        }
        $decoded = json_decode($this->intro_text_json, true);
        if (!is_array($decoded)) {
            return '';
        }
        if (!empty($decoded[$lang])) {
            return $decoded[$lang];
        }
        // Fall back to English
        return isset($decoded['en']) ? $decoded['en'] : '';
    }

    /**
     * Get group instruction text for a specific group key and language.
     *
     * @param string $group_key e.g. "agreement_scale"
     * @param string $lang      Language code: en, es, or pt.
     * @return string
     */
    public function get_group_label($group_key, $lang = 'en') {
        if (empty($this->group_labels_json)) {
            return '';
        }
        $decoded = json_decode($this->group_labels_json, true);
        if (!is_array($decoded) || !isset($decoded[$group_key])) {
            return '';
        }
        $group = $decoded[$group_key];
        if (!is_array($group)) {
            return is_string($group) ? $group : '';
        }
        if (!empty($group[$lang])) {
            return $group[$lang];
        }
        // Fall back to English
        return isset($group['en']) ? $group['en'] : '';
    }

    /**
     * Get question text for a language, falling back to English.
     *
     * @param array  $question Question array with text_en, text_es, text_pt keys.
     * @param string $lang     Language code: en, es, or pt.
     * @return string
     */
    public function get_question_text($question, $lang = 'en') {
        if (!is_array($question)) {
            return '';
        }
        $key = 'text_' . $lang;
        if (!empty($question[$key])) {
            return $question[$key];
        }
        // Fall back to English
        return isset($question['text_en']) ? $question['text_en'] : '';
    }

    /**
     * Get scale label text for a specific scale type, value, and language.
     *
     * @param string     $scale_type e.g. "likert_5"
     * @param int|string $value      e.g. 1, 2, 3, 4, 5
     * @param string     $lang       Language code: en, es, or pt.
     * @return string
     */
    public function get_scale_label_text($scale_type, $value, $lang = 'en') {
        $labels = $this->get_scale_labels();
        if (!isset($labels[$scale_type])) {
            return '';
        }
        $scale = $labels[$scale_type];
        $value_key = (string) $value;
        if (!isset($scale[$value_key]) || !is_array($scale[$value_key])) {
            return '';
        }
        $translations = $scale[$value_key];
        if (!empty($translations[$lang])) {
            return $translations[$lang];
        }
        // Fall back to English
        return isset($translations['en']) ? $translations['en'] : '';
    }

    /**
     * Check if the survey is published.
     *
     * @return bool
     */
    public function is_published() {
        return $this->status === 'published';
    }

    /**
     * Convert to associative array.
     *
     * @return array
     */
    public function to_array() {
        $vars = get_object_vars($this);
        unset($vars['questions_cache']);
        return $vars;
    }
}
