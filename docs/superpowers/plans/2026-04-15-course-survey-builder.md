# Course Survey Builder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a general-purpose survey system for HL Core, starting with an End of Course Survey triggered as a modal after LearnDash course completion.

**Architecture:** 3 new DB tables (survey definitions, responses, pending queue) + completion gate in LD integration + AJAX-loaded modal on Program Page/Dashboard + admin editor tab on Instruments page + report views with CSV export. Follows existing repository/service/admin-controller patterns.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, MySQL, jQuery AJAX, BuddyBoss Theme

**Spec:** `docs/superpowers/specs/2026-04-15-course-survey-builder-design.md`

---

## File Map

### New Files (9)

| File | Responsibility |
|------|---------------|
| `includes/domain/class-hl-survey.php` | Domain model — properties, JSON parsing, language resolution |
| `includes/domain/repositories/class-hl-survey-repository.php` | CRUD for `hl_survey` table |
| `includes/domain/repositories/class-hl-survey-response-repository.php` | CRUD for `hl_course_survey_response` + `hl_pending_survey`, reporting queries |
| `includes/services/class-hl-survey-service.php` | Trigger logic, pending queue, submission, validation, completion gate |
| `includes/frontend/class-hl-frontend-survey-modal.php` | Modal shell in wp_footer, AJAX endpoints (check pending, submit) |
| `includes/admin/class-hl-admin-survey.php` | Survey editor tab (list, edit, duplicate, preview, delete responses) |
| `includes/admin/class-hl-admin-survey-reports.php` | Report views (summary, open text, per-course), CSV export |
| `assets/js/survey-modal.js` | Survey modal JS — AJAX check, focus trap, Likert keyboard nav, submit, sessionStorage draft save |
| `assets/css/survey-modal.css` | Survey modal styles — overlay, modal box, pills, responsive 576px stack |

### Modified Files (12)

| File | Lines | Change |
|------|-------|--------|
| `includes/class-hl-installer.php` | ~1192 (get_schema), ~150 (maybe_upgrade) | 3 new CREATE TABLEs, 3 migrations, 1 seed method. Rev 40→41. Seed also called from create_tables(). |
| `includes/integrations/class-hl-learndash-integration.php` | ~170-210 (on_course_completed) | Survey gate before component completion (catalog path only). Skip survey_pending re-gate. Lazy-init service only when cycle has survey_id. |
| `includes/admin/class-hl-admin-instruments.php` | ~85-98 (tabs array) | Add "Course Surveys" tab |
| `includes/admin/class-hl-admin-cycles.php` | ~632-697 (form dropdowns), ~110-121 (save) | Survey dropdown field |
| `includes/admin/class-hl-admin-course-catalog.php` | ~355-407 (form), ~54-64 (save) | Requires Survey checkbox |
| `includes/admin/class-hl-admin-enrollments.php` | ~482-543 (handle_component_actions) | Audit event on survey gate bypass |
| `includes/frontend/class-hl-frontend-program-page.php` | ~986-1004 (overlay classes) | survey_pending overlay + sub-label |
| `includes/services/class-hl-reporting-service.php` | rollup compute | Treat survey_pending as not-complete |
| `includes/services/class-hl-rules-engine-service.php` | ~178 (prerequisite check) | survey_pending blocks next component |
| `hl-core.php` | ~73-188 (includes) | Require 9 new files, init modal with !is_admin() guard |

### Review Amendments (applied throughout plan)

The following fixes from the 4-agent review (2 technical + 2 business) are incorporated:

1. **C1:** User ownership check in `ajax_submit()` — verify `$pending['user_id'] === get_current_user_id()`. `get_pending_by_id()` moved to repository as public method.
2. **C2:** Variable name fix in Task 6 — use `$enrollment_id`, `$catalog_entry->catalog_id`, `$component->component_id`. Gate inside catalog path only. Skip `survey_pending` re-gate.
3. **C3:** ENUM migration guarded with `SHOW COLUMNS` check for 'survey_pending' before modifying.
4. **I1:** `get_next_pending_for_user()` converted from recursion to while loop.
5. **I2:** `complete_component()` now sets `completed_at` and `last_computed_at`.
6. **I3:** JS/CSS in separate files (`survey-modal.js`, `survey-modal.css`), conditionally enqueued.
7. **I4:** `init()` registers AJAX hooks globally, but `wp_footer` + `wp_enqueue_scripts` guarded with `!is_admin()`.
8. **I5:** Submit path wrapped in `START TRANSACTION` / `COMMIT`.
9. **I6:** `seed_surveys()` also called from `create_tables()` for fresh installs.
10. **I7:** `yes_no` question type rendering added to `build_modal_html()`.
11. **B1:** sessionStorage draft save/restore in `survey-modal.js` (~20 lines).
12. **B2:** Tasks 10+13 split into explicit sub-steps.
13. **B3:** Orphan detection — admin notice on survey list for pending rows > 30 days old.
14. **B5:** Code comment documenting survey version transition with pending rows.
15. **CEO-1:** Rate limiter (3s transient) on `ajax_submit()`.
16. **CEO-2:** Admin delete survey resolves all pending rows first.
17. **CEO-3:** Lazy-init HL_Survey_Service — check cycle `survey_id` before constructing service.

---

## Task 1: Schema — Tables, Migrations, Seed Data

**Files:**
- Modify: `includes/class-hl-installer.php`

This task adds 3 new tables, 3 ALTER TABLE migrations, 1 seed method, and bumps the schema revision from 40 to 41.

- [ ] **Step 1: Bump schema revision**

In `class-hl-installer.php`, find line 153 (`$current_revision = 40;`) and change to:

```php
$current_revision = 41;
```

- [ ] **Step 2: Add hl_survey CREATE TABLE to get_schema()**

In `get_schema()` (after the last `$tables[]` entry, before `return $tables;` at ~line 2223), add:

```php
        // Survey definitions (general-purpose survey builder)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_survey (
            survey_id       bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            survey_uuid     char(36) NOT NULL,
            internal_name   varchar(255) NOT NULL,
            display_name    varchar(255) NOT NULL,
            survey_type     enum('end_of_course') NOT NULL DEFAULT 'end_of_course',
            version         int unsigned NOT NULL DEFAULT 1,
            questions_json  longtext NOT NULL,
            scale_labels_json longtext DEFAULT NULL,
            intro_text_json longtext DEFAULT NULL COMMENT 'JSON: {en, es, pt} intro paragraph',
            group_labels_json longtext DEFAULT NULL COMMENT 'JSON: {group_key: {en, es, pt}} instruction per question group',
            status          enum('draft','published') NOT NULL DEFAULT 'draft',
            created_at      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (survey_id),
            UNIQUE KEY survey_uuid (survey_uuid),
            KEY idx_type_status (survey_type, status)
        ) $charset_collate;";

        // Survey responses (one per enrollment per course per survey)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_course_survey_response (
            response_id     bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            response_uuid   char(36) NOT NULL,
            survey_id       bigint(20) unsigned NOT NULL,
            user_id         bigint(20) unsigned NOT NULL,
            enrollment_id   bigint(20) unsigned NOT NULL,
            catalog_id      bigint(20) unsigned NOT NULL,
            cycle_id        bigint(20) unsigned NOT NULL,
            responses_json  longtext NOT NULL,
            language        varchar(5) NOT NULL DEFAULT 'en',
            submitted_at    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (response_id),
            UNIQUE KEY response_uuid (response_uuid),
            UNIQUE KEY one_per_enrollment_course_survey (enrollment_id, catalog_id, survey_id),
            KEY idx_survey (survey_id),
            KEY idx_cycle (cycle_id),
            KEY idx_catalog (catalog_id),
            KEY idx_user (user_id),
            KEY idx_submitted (submitted_at),
            KEY idx_survey_cycle (survey_id, cycle_id)
        ) $charset_collate;";

        // Pending survey queue (atomic INSERT/DELETE, replaces user_meta)
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_pending_survey (
            pending_id      bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id         bigint(20) unsigned NOT NULL,
            survey_id       bigint(20) unsigned NOT NULL,
            enrollment_id   bigint(20) unsigned NOT NULL,
            catalog_id      bigint(20) unsigned NOT NULL,
            cycle_id        bigint(20) unsigned NOT NULL,
            component_id    bigint(20) unsigned NOT NULL,
            triggered_at    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (pending_id),
            UNIQUE KEY one_pending (enrollment_id, catalog_id, survey_id),
            KEY idx_user (user_id)
        ) $charset_collate;";
```

- [ ] **Step 3: Add 3 migration methods**

After the last `migrate_*` method in the file, add:

```php
    /**
     * Rev 41: Add requires_survey column to hl_course_catalog.
     */
    private static function migrate_add_catalog_requires_survey() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_course_catalog';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
        if ( ! in_array( 'requires_survey', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN requires_survey tinyint(1) NOT NULL DEFAULT 1" );
        }
    }

    /**
     * Rev 41: Add survey_id column to hl_cycle.
     */
    private static function migrate_add_cycle_survey_id() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_cycle';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
        if ( ! in_array( 'survey_id', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN survey_id bigint(20) unsigned DEFAULT NULL" );
        }
    }

    /**
     * Rev 41: Add survey_pending to component_state completion_status ENUM.
     * Guarded: only modifies if 'survey_pending' not already in ENUM (safe for future revisions).
     */
    private static function migrate_add_survey_pending_status() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_component_state';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        // Check if survey_pending already exists in the ENUM.
        $col_info = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'completion_status'", ARRAY_A );
        if ( $col_info && strpos( $col_info['Type'], 'survey_pending' ) !== false ) {
            return;
        }
        $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN completion_status
            enum('not_started','in_progress','complete','survey_pending')
            NOT NULL DEFAULT 'not_started'" );
    }
```

- [ ] **Step 4: Add seed method**

```php
    /**
     * Rev 41: Seed initial survey definitions.
     */
    private static function seed_surveys() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_survey';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $surveys = array(
            array(
                'internal_name' => 'End of Course Survey - 2025',
                'display_name'  => 'End of Course Survey',
                'survey_type'   => 'end_of_course',
                'version'       => 1,
                'questions_json' => '[]',
                'status'        => 'draft',
            ),
            array(
                'internal_name' => 'End of Course Survey - 2026',
                'display_name'  => 'End of Course Survey',
                'survey_type'   => 'end_of_course',
                'version'       => 2,
                'questions_json' => json_encode( self::get_eoc_survey_2026_questions() ),
                'scale_labels_json' => json_encode( self::get_eoc_survey_scale_labels() ),
                'intro_text_json' => json_encode( array(
                    'en' => 'Please fill out this end-of-course survey to let us know how we are doing and how we can better support you in the future!',
                    'es' => 'Por favor complete esta encuesta de fin de curso para hacernos saber como lo estamos haciendo y como podemos apoyarle mejor en el futuro.',
                    'pt' => 'Por favor preencha esta pesquisa de final de curso para nos informar como estamos e como podemos apoiar voce melhor no futuro!',
                ) ),
                'group_labels_json' => json_encode( array(
                    'agreement_scale' => array(
                        'en' => 'Please indicate your level of agreement with each statement based on your experiences with this course.',
                        'es' => 'Por favor indique su nivel de acuerdo con cada declaracion basado en sus experiencias con este curso.',
                        'pt' => 'Por favor indique seu nivel de concordancia com cada afirmacao com base em suas experiencias com este curso.',
                    ),
                ) ),
                'status' => 'published',
            ),
        );

        foreach ( $surveys as $survey ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT survey_id FROM `{$table}` WHERE internal_name = %s",
                $survey['internal_name']
            ) );
            if ( $exists ) {
                continue;
            }
            $survey['survey_uuid'] = HL_DB_Utils::generate_uuid();
            $wpdb->insert( $table, $survey );
        }
    }

    /**
     * Returns the 8 questions for the 2026 End of Course Survey.
     */
    private static function get_eoc_survey_2026_questions() {
        return array(
            array(
                'question_key' => 'time_worthwhile',
                'type'         => 'likert_5',
                'required'     => true,
                'group'        => 'agreement_scale',
                'text_en'      => 'The time I spent on this course was worthwhile.',
                'text_es'      => 'El tiempo que dedique a este curso valio la pena.',
                'text_pt'      => 'O tempo que dediquei a este curso valeu a pena.',
            ),
            array(
                'question_key' => 'prepared_to_apply',
                'type'         => 'likert_5',
                'required'     => true,
                'group'        => 'agreement_scale',
                'text_en'      => 'I feel prepared to apply information from this course in real-world situations.',
                'text_es'      => 'Me siento preparado/a para aplicar la informacion de este curso en situaciones del mundo real.',
                'text_pt'      => 'Sinto-me preparado/a para aplicar as informacoes deste curso em situacoes do mundo real.',
            ),
            array(
                'question_key' => 'emotional_development',
                'type'         => 'likert_5',
                'required'     => true,
                'group'        => 'agreement_scale',
                'text_en'      => 'The strategies covered in this course will be useful in supporting my students\' emotional development.',
                'text_es'      => 'Las estrategias cubiertas en este curso seran utiles para apoyar el desarrollo emocional de mis estudiantes.',
                'text_pt'      => 'As estrategias abordadas neste curso serao uteis para apoiar o desenvolvimento emocional dos meus alunos.',
            ),
            array(
                'question_key' => 'emotional_wellbeing',
                'type'         => 'likert_5',
                'required'     => true,
                'group'        => 'agreement_scale',
                'text_en'      => 'The strategies covered in this course will be useful in supporting my own emotional wellbeing.',
                'text_es'      => 'Las estrategias cubiertas en este curso seran utiles para apoyar mi propio bienestar emocional.',
                'text_pt'      => 'As estrategias abordadas neste curso serao uteis para apoiar meu proprio bem-estar emocional.',
            ),
            array(
                'question_key' => 'classroom_routine',
                'type'         => 'likert_5',
                'required'     => true,
                'group'        => 'agreement_scale',
                'text_en'      => 'The strategies covered in this course will fit into my classroom routine.',
                'text_es'      => 'Las estrategias cubiertas en este curso se ajustaran a mi rutina en el aula.',
                'text_pt'      => 'As estrategias abordadas neste curso se encaixarao na minha rotina de sala de aula.',
            ),
            array(
                'question_key' => 'resources_supports',
                'type'         => 'likert_5',
                'required'     => true,
                'group'        => 'agreement_scale',
                'text_en'      => 'I have the necessary resources and supports to implement the strategies covered in this course.',
                'text_es'      => 'Tengo los recursos y apoyos necesarios para implementar las estrategias cubiertas en este curso.',
                'text_pt'      => 'Tenho os recursos e apoios necessarios para implementar as estrategias abordadas neste curso.',
            ),
            array(
                'question_key' => 'liked_most',
                'type'         => 'open_text',
                'required'     => true,
                'group'        => null,
                'text_en'      => 'What did you like the most about this e-learning course?',
                'text_es'      => 'Que fue lo que mas le gusto de este curso en linea?',
                'text_pt'      => 'O que voce mais gostou neste curso online?',
            ),
            array(
                'question_key' => 'could_improve',
                'type'         => 'open_text',
                'required'     => true,
                'group'        => null,
                'text_en'      => 'How could this e-learning course be improved?',
                'text_es'      => 'Como podria mejorarse este curso en linea?',
                'text_pt'      => 'Como este curso online poderia ser melhorado?',
            ),
        );
    }

    /**
     * Returns the scale labels for Likert 5-point scale.
     */
    private static function get_eoc_survey_scale_labels() {
        return array(
            'likert_5' => array(
                '1' => array( 'en' => 'Strongly Disagree', 'es' => 'Totalmente en Desacuerdo', 'pt' => 'Discordo Totalmente' ),
                '2' => array( 'en' => 'Disagree', 'es' => 'En Desacuerdo', 'pt' => 'Discordo' ),
                '3' => array( 'en' => 'No Opinion', 'es' => 'Sin Opinion', 'pt' => 'Sem Opiniao' ),
                '4' => array( 'en' => 'Agree', 'es' => 'De Acuerdo', 'pt' => 'Concordo' ),
                '5' => array( 'en' => 'Strongly Agree', 'es' => 'Totalmente de Acuerdo', 'pt' => 'Concordo Totalmente' ),
            ),
        );
    }
```

- [ ] **Step 5: Wire migrations in maybe_upgrade()**

In the `maybe_upgrade()` method, add a new revision block after the last `if ( (int) $stored < 40 )` block:

```php
        if ( (int) $stored < 41 ) {
            self::migrate_add_catalog_requires_survey();
            self::migrate_add_cycle_survey_id();
            self::migrate_add_survey_pending_status();
            self::seed_surveys();
        }
```

- [ ] **Step 5b: Also call seed_surveys() from create_tables()**

Fresh installs run `create_tables()` via dbDelta but may skip `maybe_upgrade()` if no stored revision exists. Add at the end of `create_tables()`, after the dbDelta call:

```php
        self::seed_surveys();
```

The existence guard in `seed_surveys()` (checks table + internal_name) makes this safe to call multiple times.

- [ ] **Step 6: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat(survey): schema rev 41 — 3 tables, 3 migrations, seed data"
```

---

## Task 2: Domain Model — HL_Survey

**Files:**
- Create: `includes/domain/class-hl-survey.php`

- [ ] **Step 1: Create the domain model**

Follow the existing pattern from `class-hl-course-catalog.php` (foreach constructor + public properties + helper methods):

```php
<?php
/**
 * Survey domain model.
 */
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

    /** @var array|null Decoded questions cache */
    private $questions_cache = null;

    public function __construct( $data = array() ) {
        $data = is_array( $data ) ? $data : (array) $data;
        foreach ( $data as $key => $value ) {
            if ( property_exists( $this, $key ) ) {
                $this->$key = $value;
            }
        }
        if ( $this->survey_id ) {
            $this->survey_id = absint( $this->survey_id );
        }
        if ( $this->version ) {
            $this->version = absint( $this->version );
        }
    }

    /**
     * Get decoded questions array.
     */
    public function get_questions() {
        if ( $this->questions_cache === null ) {
            $this->questions_cache = json_decode( $this->questions_json, true ) ?: array();
        }
        return $this->questions_cache;
    }

    /**
     * Get decoded scale labels.
     */
    public function get_scale_labels() {
        return json_decode( $this->scale_labels_json, true ) ?: array();
    }

    /**
     * Get intro text for a given language, fallback to English.
     */
    public function get_intro_text( $lang = 'en' ) {
        $data = json_decode( $this->intro_text_json, true ) ?: array();
        return $data[ $lang ] ?? $data['en'] ?? '';
    }

    /**
     * Get group label for a given group key and language.
     */
    public function get_group_label( $group_key, $lang = 'en' ) {
        $data = json_decode( $this->group_labels_json, true ) ?: array();
        if ( ! isset( $data[ $group_key ] ) ) {
            return '';
        }
        return $data[ $group_key ][ $lang ] ?? $data[ $group_key ]['en'] ?? '';
    }

    /**
     * Get question text in a given language, fallback to English.
     */
    public function get_question_text( $question, $lang = 'en' ) {
        $key = 'text_' . $lang;
        return $question[ $key ] ?? $question['text_en'] ?? '';
    }

    /**
     * Get scale label text for a value in a given language.
     */
    public function get_scale_label_text( $scale_type, $value, $lang = 'en' ) {
        $labels = $this->get_scale_labels();
        if ( ! isset( $labels[ $scale_type ][ (string) $value ] ) ) {
            return (string) $value;
        }
        $label = $labels[ $scale_type ][ (string) $value ];
        return $label[ $lang ] ?? $label['en'] ?? (string) $value;
    }

    /**
     * Check if this survey is published.
     */
    public function is_published() {
        return $this->status === 'published';
    }

    /**
     * Convert to array.
     */
    public function to_array() {
        return array(
            'survey_id'        => $this->survey_id,
            'survey_uuid'      => $this->survey_uuid,
            'internal_name'    => $this->internal_name,
            'display_name'     => $this->display_name,
            'survey_type'      => $this->survey_type,
            'version'          => $this->version,
            'questions_json'   => $this->questions_json,
            'scale_labels_json' => $this->scale_labels_json,
            'intro_text_json'  => $this->intro_text_json,
            'group_labels_json' => $this->group_labels_json,
            'status'           => $this->status,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/domain/class-hl-survey.php
git commit -m "feat(survey): HL_Survey domain model with language helpers"
```

---

## Task 3: Repositories — HL_Survey_Repository + HL_Survey_Response_Repository

**Files:**
- Create: `includes/domain/repositories/class-hl-survey-repository.php`
- Create: `includes/domain/repositories/class-hl-survey-response-repository.php`

- [ ] **Step 1: Create HL_Survey_Repository**

Follow the pattern from `class-hl-course-catalog-repository.php`:

```php
<?php
/**
 * Repository for hl_survey table.
 */
class HL_Survey_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_survey';
    }

    public function get_by_id( $survey_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE survey_id = %d",
            $survey_id
        ), ARRAY_A );
        return $row ? new HL_Survey( $row ) : null;
    }

    public function get_all( $status = null, $type = null ) {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table()} WHERE 1=1";
        $args = array();
        if ( $status ) {
            $sql .= ' AND status = %s';
            $args[] = $status;
        }
        if ( $type ) {
            $sql .= ' AND survey_type = %s';
            $args[] = $type;
        }
        $sql .= ' ORDER BY version DESC';
        $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
        return array_map( function( $row ) { return new HL_Survey( $row ); }, $rows ?: array() );
    }

    public function get_published_by_type( $type = 'end_of_course' ) {
        return $this->get_all( 'published', $type );
    }

    public function create( $data ) {
        global $wpdb;
        if ( empty( $data['survey_uuid'] ) ) {
            $data['survey_uuid'] = HL_DB_Utils::generate_uuid();
        }
        $result = $wpdb->insert( $this->table(), $data );
        if ( false === $result ) {
            return new WP_Error( 'insert_failed', $wpdb->last_error );
        }
        return $wpdb->insert_id;
    }

    public function update( $survey_id, $data ) {
        global $wpdb;
        $result = $wpdb->update( $this->table(), $data, array( 'survey_id' => $survey_id ) );
        if ( false === $result ) {
            return new WP_Error( 'update_failed', $wpdb->last_error );
        }
        return $this->get_by_id( $survey_id );
    }

    public function has_responses( $survey_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT EXISTS(SELECT 1 FROM {$wpdb->prefix}hl_course_survey_response WHERE survey_id = %d)",
            $survey_id
        ) );
    }

    public function get_response_count( $survey_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_course_survey_response WHERE survey_id = %d",
            $survey_id
        ) );
    }

    public function get_next_version( $survey_type ) {
        global $wpdb;
        $max = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(version) FROM {$this->table()} WHERE survey_type = %s",
            $survey_type
        ) );
        return $max + 1;
    }

    public function get_cycles_using_survey( $survey_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle WHERE survey_id = %d",
            $survey_id
        ), ARRAY_A ) ?: array();
    }

    public function delete( $survey_id ) {
        global $wpdb;
        return $wpdb->delete( $this->table(), array( 'survey_id' => $survey_id ) );
    }
}
```

- [ ] **Step 2: Create HL_Survey_Response_Repository**

```php
<?php
/**
 * Repository for hl_course_survey_response and hl_pending_survey tables.
 */
class HL_Survey_Response_Repository {

    private function response_table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_course_survey_response';
    }

    private function pending_table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_pending_survey';
    }

    // ── Responses ───────────────────────────────────────────

    public function insert_response( $data ) {
        global $wpdb;
        if ( empty( $data['response_uuid'] ) ) {
            $data['response_uuid'] = HL_DB_Utils::generate_uuid();
        }
        $result = $wpdb->insert( $this->response_table(), $data );
        if ( false === $result ) {
            // Check for duplicate entry.
            if ( strpos( $wpdb->last_error, 'Duplicate entry' ) !== false ) {
                return new WP_Error( 'duplicate_response', 'Survey already submitted for this course.' );
            }
            return new WP_Error( 'insert_failed', $wpdb->last_error );
        }
        return $wpdb->insert_id;
    }

    public function response_exists( $enrollment_id, $catalog_id, $survey_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT EXISTS(SELECT 1 FROM {$this->response_table()} WHERE enrollment_id = %d AND catalog_id = %d AND survey_id = %d)",
            $enrollment_id, $catalog_id, $survey_id
        ) );
    }

    public function get_responses_for_report( $survey_id, $filters = array() ) {
        global $wpdb;
        $sql  = "SELECT r.*, e.language_preference, u.display_name AS participant_name, u.user_email,
                        c.catalog_code, c.title AS course_title
                 FROM {$this->response_table()} r
                 JOIN {$wpdb->prefix}hl_enrollment e ON e.enrollment_id = r.enrollment_id
                 JOIN {$wpdb->users} u ON u.ID = r.user_id
                 JOIN {$wpdb->prefix}hl_course_catalog c ON c.catalog_id = r.catalog_id
                 WHERE r.survey_id = %d";
        $args = array( $survey_id );

        if ( ! empty( $filters['cycle_ids'] ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $filters['cycle_ids'] ), '%d' ) );
            $sql .= " AND r.cycle_id IN ({$placeholders})";
            $args = array_merge( $args, array_map( 'absint', $filters['cycle_ids'] ) );
        }
        if ( ! empty( $filters['catalog_id'] ) ) {
            $sql .= ' AND r.catalog_id = %d';
            $args[] = absint( $filters['catalog_id'] );
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $sql .= ' AND r.submitted_at >= %s';
            $args[] = $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $sql .= ' AND r.submitted_at <= %s';
            $args[] = $filters['date_to'] . ' 23:59:59';
        }

        $sql .= ' ORDER BY r.submitted_at DESC';

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) ?: array();
    }

    public function delete_responses( $response_ids ) {
        global $wpdb;
        if ( empty( $response_ids ) ) {
            return 0;
        }
        $ids = array_map( 'absint', $response_ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        return $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->response_table()} WHERE response_id IN ({$placeholders})",
            ...$ids
        ) );
    }

    public function delete_all_responses_for_survey( $survey_id ) {
        global $wpdb;
        return $wpdb->delete( $this->response_table(), array( 'survey_id' => $survey_id ) );
    }

    // ── Pending Queue ───────────────────────────────────────

    public function insert_pending( $data ) {
        global $wpdb;
        $result = $wpdb->insert( $this->pending_table(), $data );
        if ( false === $result ) {
            if ( strpos( $wpdb->last_error, 'Duplicate entry' ) !== false ) {
                return new WP_Error( 'already_pending', 'Survey already pending for this course.' );
            }
            return new WP_Error( 'insert_failed', $wpdb->last_error );
        }
        return $wpdb->insert_id;
    }

    public function get_pending_for_user( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, c.title AS course_title, c.catalog_code
             FROM {$this->pending_table()} p
             JOIN {$wpdb->prefix}hl_course_catalog c ON c.catalog_id = p.catalog_id
             WHERE p.user_id = %d
             ORDER BY p.triggered_at ASC",
            $user_id
        ), ARRAY_A ) ?: array();
    }

    public function delete_pending( $pending_id ) {
        global $wpdb;
        return $wpdb->delete( $this->pending_table(), array( 'pending_id' => $pending_id ) );
    }

    public function get_pending_by_id( $pending_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->pending_table()} WHERE pending_id = %d",
            $pending_id
        ), ARRAY_A );
    }

    public function delete_pending_by_keys( $enrollment_id, $catalog_id, $survey_id ) {
        global $wpdb;
        return $wpdb->delete( $this->pending_table(), array(
            'enrollment_id' => $enrollment_id,
            'catalog_id'    => $catalog_id,
            'survey_id'     => $survey_id,
        ) );
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/domain/repositories/class-hl-survey-repository.php includes/domain/repositories/class-hl-survey-response-repository.php
git commit -m "feat(survey): survey + response repositories with pending queue"
```

---

## Task 4: Service Layer — HL_Survey_Service

**Files:**
- Create: `includes/services/class-hl-survey-service.php`

- [ ] **Step 1: Create the service**

This is the core service handling trigger logic, validation, submission, and completion gate:

```php
<?php
/**
 * Survey service — trigger logic, pending queue, submission, validation.
 */
class HL_Survey_Service {

    private $survey_repo;
    private $response_repo;

    public function __construct() {
        $this->survey_repo   = new HL_Survey_Repository();
        $this->response_repo = new HL_Survey_Response_Repository();
    }

    // ── Completion Gate ─────────────────────────────────────

    /**
     * Called from on_course_completed(). Returns true if survey gate was triggered
     * (meaning the caller should NOT mark the component complete).
     */
    public function check_survey_gate( $enrollment_id, $catalog_id, $cycle_id, $component_id, $user_id ) {
        global $wpdb;

        // 1. Does this cycle have a survey?
        $survey_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT survey_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
            $cycle_id
        ) );
        if ( ! $survey_id ) {
            return false;
        }

        // 2. Does this catalog entry require a survey?
        $requires = $wpdb->get_var( $wpdb->prepare(
            "SELECT requires_survey FROM {$wpdb->prefix}hl_course_catalog WHERE catalog_id = %d",
            $catalog_id
        ) );
        if ( ! $requires ) {
            return false;
        }

        // 3. Is the survey published?
        $survey = $this->survey_repo->get_by_id( $survey_id );
        if ( ! $survey || ! $survey->is_published() ) {
            return false;
        }

        // 4. Already submitted?
        if ( $this->response_repo->response_exists( $enrollment_id, $catalog_id, $survey_id ) ) {
            return false;
        }

        // 5. Gate: write survey_pending state.
        $state_table = $wpdb->prefix . 'hl_component_state';
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$state_table} WHERE enrollment_id = %d AND component_id = %d",
            $enrollment_id, $component_id
        ), ARRAY_A );

        if ( $existing ) {
            $wpdb->update( $state_table, array(
                'completion_status'  => 'survey_pending',
                'completion_percent' => 100,
            ), array(
                'enrollment_id' => $enrollment_id,
                'component_id'  => $component_id,
            ) );
        } else {
            $wpdb->insert( $state_table, array(
                'enrollment_id'      => $enrollment_id,
                'component_id'       => $component_id,
                'completion_status'  => 'survey_pending',
                'completion_percent' => 100,
            ) );
        }

        // 6. Insert pending survey.
        $this->response_repo->insert_pending( array(
            'user_id'       => $user_id,
            'survey_id'     => $survey_id,
            'enrollment_id' => $enrollment_id,
            'catalog_id'    => $catalog_id,
            'cycle_id'      => $cycle_id,
            'component_id'  => $component_id,
        ) );

        return true;
    }

    // ── Submission ──────────────────────────────────────────

    /**
     * Validate and save a survey response. Returns response_id or WP_Error.
     */
    public function submit_response( $pending_id, $raw_responses ) {
        $pending = $this->get_pending_by_id( $pending_id );
        if ( ! $pending ) {
            return new WP_Error( 'not_found', 'Pending survey not found.' );
        }

        $survey = $this->survey_repo->get_by_id( $pending['survey_id'] );
        if ( ! $survey ) {
            // Survey deleted — clean up and complete.
            $this->resolve_orphan_pending( $pending );
            return new WP_Error( 'survey_deleted', 'Survey no longer exists. Component marked complete.' );
        }

        if ( ! $survey->is_published() ) {
            $this->resolve_orphan_pending( $pending );
            return new WP_Error( 'survey_unpublished', 'Survey is no longer active. Component marked complete.' );
        }

        // Validate responses against survey definition.
        $validated = $this->validate_responses( $survey, $raw_responses );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        // Get enrollment language.
        global $wpdb;
        $language = $wpdb->get_var( $wpdb->prepare(
            "SELECT language_preference FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $pending['enrollment_id']
        ) ) ?: 'en';

        // Insert response.
        $result = $this->response_repo->insert_response( array(
            'survey_id'     => $pending['survey_id'],
            'user_id'       => $pending['user_id'],
            'enrollment_id' => $pending['enrollment_id'],
            'catalog_id'    => $pending['catalog_id'],
            'cycle_id'      => $pending['cycle_id'],
            'responses_json' => wp_json_encode( $validated ),
            'language'      => $language,
        ) );

        // Handle duplicate (double-submit from another tab).
        if ( is_wp_error( $result ) && $result->get_error_code() === 'duplicate_response' ) {
            $this->complete_component( $pending );
            $this->response_repo->delete_pending( $pending_id );
            return 'already_submitted';
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Mark component complete and remove pending.
        $this->complete_component( $pending );
        $this->response_repo->delete_pending( $pending_id );

        // Audit log.
        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'survey.submitted', array(
                'survey_id'     => $pending['survey_id'],
                'enrollment_id' => $pending['enrollment_id'],
                'catalog_id'    => $pending['catalog_id'],
                'response_id'   => $result,
            ) );
        }

        return $result;
    }

    // ── Validation ──────────────────────────────────────────

    private function validate_responses( $survey, $raw ) {
        if ( ! is_array( $raw ) ) {
            $raw = json_decode( $raw, true );
        }
        if ( ! is_array( $raw ) || strlen( wp_json_encode( $raw ) ) > 65536 ) {
            return new WP_Error( 'invalid_payload', 'Invalid or oversized response payload.' );
        }

        $questions = $survey->get_questions();
        $valid_keys = array_column( $questions, 'question_key' );
        $validated = array();

        // Reject unknown keys.
        foreach ( array_keys( $raw ) as $key ) {
            if ( ! in_array( $key, $valid_keys, true ) ) {
                return new WP_Error( 'unknown_key', sprintf( 'Unknown question key: %s', $key ) );
            }
        }

        foreach ( $questions as $q ) {
            $key  = $q['question_key'];
            $type = $q['type'];
            $val  = $raw[ $key ] ?? null;

            if ( ! empty( $q['required'] ) && ( $val === null || $val === '' ) ) {
                return new WP_Error( 'required_missing', sprintf( 'Required field missing: %s', $key ) );
            }

            if ( $val === null || $val === '' ) {
                continue;
            }

            switch ( $type ) {
                case 'likert_5':
                    $int_val = (int) $val;
                    if ( $int_val < 1 || $int_val > 5 ) {
                        return new WP_Error( 'invalid_likert', sprintf( 'Likert value must be 1-5 for %s', $key ) );
                    }
                    $validated[ $key ] = $int_val;
                    break;

                case 'open_text':
                    $validated[ $key ] = wp_kses( (string) $val, array() );
                    break;

                case 'yes_no':
                    if ( ! in_array( $val, array( 'yes', 'no' ), true ) ) {
                        return new WP_Error( 'invalid_yes_no', sprintf( 'Yes/No value required for %s', $key ) );
                    }
                    $validated[ $key ] = $val;
                    break;

                default:
                    $validated[ $key ] = sanitize_text_field( (string) $val );
            }
        }

        return $validated;
    }

    // ── Helpers ─────────────────────────────────────────────

    private function complete_component( $pending ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hl_component_state',
            array(
                'completion_status'  => 'complete',
                'completion_percent' => 100,
                'completed_at'       => current_time( 'mysql' ),
                'last_computed_at'   => current_time( 'mysql' ),
            ),
            array(
                'enrollment_id' => $pending['enrollment_id'],
                'component_id'  => $pending['component_id'],
            )
        );
        do_action( 'hl_core_recompute_rollups', $pending['enrollment_id'] );
    }

    private function resolve_orphan_pending( $pending ) {
        $this->complete_component( $pending );
        $this->response_repo->delete_pending( $pending['pending_id'] );
    }

    private function get_pending_by_id( $pending_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_pending_survey WHERE pending_id = %d",
            $pending_id
        ), ARRAY_A );
    }

    /**
     * Check pending surveys for AJAX endpoint.
     * Returns pending data with survey + course info, or null.
     */
    public function get_next_pending_for_user( $user_id ) {
        // While loop instead of recursion to avoid stack overflow with many orphans.
        $max_iterations = 50;
        $i = 0;
        while ( $i++ < $max_iterations ) {
            $pending_list = $this->response_repo->get_pending_for_user( $user_id );
            if ( empty( $pending_list ) ) {
                return null;
            }

            $pending = $pending_list[0]; // FIFO — oldest first.
            $survey  = $this->survey_repo->get_by_id( $pending['survey_id'] );

            // If survey still valid, return it.
            if ( $survey && $survey->is_published() ) {
                return array(
                    'pending'      => $pending,
                    'survey'       => $survey,
                    'course_title' => $pending['course_title'] ?? '',
                    'catalog_code' => $pending['catalog_code'] ?? '',
                );
            }

            // Survey invalid — resolve orphan and loop to next.
            $this->resolve_orphan_pending( $pending );
        }
        return null;
    }

    // ── Admin: Duplicate ────────────────────────────────────

    public function duplicate_survey( $survey_id ) {
        $survey = $this->survey_repo->get_by_id( $survey_id );
        if ( ! $survey ) {
            return new WP_Error( 'not_found', 'Survey not found.' );
        }

        $new_version = $this->survey_repo->get_next_version( $survey->survey_type );

        return $this->survey_repo->create( array(
            'internal_name'    => $survey->internal_name . ' (Copy)',
            'display_name'     => $survey->display_name,
            'survey_type'      => $survey->survey_type,
            'version'          => $new_version,
            'questions_json'   => $survey->questions_json,
            'scale_labels_json' => $survey->scale_labels_json,
            'intro_text_json'  => $survey->intro_text_json,
            'group_labels_json' => $survey->group_labels_json,
            'status'           => 'draft',
        ) );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-survey-service.php
git commit -m "feat(survey): HL_Survey_Service — gate, validation, submission, duplication"
```

---

## Task 5: Class Loading in hl-core.php

**Files:**
- Modify: `hl-core.php`

- [ ] **Step 1: Add require_once entries**

In `hl-core.php`, add the new domain model in the domain section (~line 84), repositories in the repositories section (~line 97), service in the services section (~line 132), and admin/frontend classes in their respective sections:

```php
// Domain model — add after existing domain models (~line 84)
require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-survey.php';

// Repositories — add after existing repositories (~line 97)
require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-survey-repository.php';
require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-survey-response-repository.php';

// Services — add after existing services (~line 132)
require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-survey-service.php';

// Admin — add inside the is_admin() block (~line 188)
require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-survey.php';
require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-survey-reports.php';

// Frontend — add after existing frontend includes
require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-survey-modal.php';
```

- [ ] **Step 2: Commit**

```bash
git add hl-core.php
git commit -m "feat(survey): wire class loading for all survey modules"
```

---

## Task 6: LearnDash Integration — Completion Gate

**Files:**
- Modify: `includes/integrations/class-hl-learndash-integration.php`

- [ ] **Step 1: Add survey gate in on_course_completed()**

In `on_course_completed()`, make TWO changes:

**Change 1:** At ~line 188, update the existing "already complete" skip to also skip `survey_pending`:

```php
                // Skip already-completed or survey-pending components.
                if ( $existing_state && in_array( $existing_state->completion_status, array( 'complete', 'survey_pending' ), true ) ) {
                    continue;
                }
```

**Change 2:** INSIDE the catalog path only (where `$catalog_entry` is available, ~line 122-130), BEFORE the existing completion upsert, add the survey gate. Lazy-init the service only when needed:

```php
                // --- Survey gate: check if survey is required before marking complete ---
                // Check cycle's survey_id first (cheap query) before constructing service.
                $cycle_survey_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT survey_id FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
                    $enrollment->cycle_id
                ) );
                if ( $cycle_survey_id ) {
                    $survey_service = new HL_Survey_Service();
                    $gate_triggered = $survey_service->check_survey_gate(
                        $enrollment_id,
                        $catalog_entry->catalog_id,
                        $enrollment->cycle_id,
                        $component->component_id,
                        $user_id
                    );
                    if ( $gate_triggered ) {
                        continue; // Skip normal completion — survey_pending state written by gate.
                    }
                }
                // --- End survey gate ---
```

**Note:** This gate is placed INSIDE the catalog path block only (not the fallback path), because `$catalog_entry` does not exist in the fallback path. The variable names match the actual code: `$enrollment_id`, `$catalog_entry->catalog_id`, `$component->component_id`.

- [ ] **Step 2: Commit**

```bash
git add includes/integrations/class-hl-learndash-integration.php
git commit -m "feat(survey): completion gate in on_course_completed()"
```

---

## Task 7: Rules Engine + Rollup Updates

**Files:**
- Modify: `includes/services/class-hl-rules-engine-service.php`
- Modify: `includes/services/class-hl-reporting-service.php`

- [ ] **Step 1: Update prerequisite checking**

In `class-hl-rules-engine-service.php`, find the prerequisite check (~line 178) where `completion_status === 'complete'` is checked. The `survey_pending` status should NOT satisfy prerequisites. Since the check already requires `=== 'complete'`, this should work without changes. Verify by searching for any `!== 'not_started'` or `in_array(status, ['complete', 'in_progress'])` patterns that could accidentally let `survey_pending` through.

- [ ] **Step 2: Update rollup computation**

In `class-hl-reporting-service.php`, find `compute_rollups()` or equivalent. Ensure `survey_pending` is treated as NOT complete for the "completed components" count. Find where `completion_status === 'complete'` is checked and verify `survey_pending` is excluded. If there's a percentage aggregation, `completion_percent = 100` is correct for individual display, but the component should NOT count toward the "X of Y components completed" numerator.

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-rules-engine-service.php includes/services/class-hl-reporting-service.php
git commit -m "feat(survey): survey_pending exclusion in rules engine + rollups"
```

---

## Task 8: Frontend — Modal Shell + AJAX Endpoints

**Files:**
- Create: `includes/frontend/class-hl-frontend-survey-modal.php`
- Modify: `assets/js/frontend.js`
- Modify: `assets/css/frontend.css`

- [ ] **Step 1: Create HL_Frontend_Survey_Modal**

This class handles: (1) rendering the empty modal shell in wp_footer, (2) AJAX endpoint for checking pending surveys, (3) AJAX endpoint for submitting responses.

```php
<?php
/**
 * Frontend survey modal — shell renderer + AJAX endpoints.
 */
class HL_Frontend_Survey_Modal {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        // AJAX endpoints — must fire on admin-ajax.php for logged-in users.
        add_action( 'wp_ajax_hl_check_pending_surveys', array( $this, 'ajax_check_pending' ) );
        add_action( 'wp_ajax_hl_submit_survey', array( $this, 'ajax_submit' ) );

        // Frontend-only: modal shell + conditional asset enqueue.
        if ( ! is_admin() ) {
            add_action( 'wp_footer', array( $this, 'render_modal_shell' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
        }
    }

    /**
     * Conditionally enqueue survey modal JS/CSS only on trigger pages.
     */
    public function maybe_enqueue_assets() {
        if ( ! $this->is_survey_trigger_page() ) {
            return;
        }
        wp_enqueue_style( 'hl-survey-modal', HL_CORE_PLUGIN_URL . 'assets/css/survey-modal.css', array(), HL_CORE_VERSION );
        wp_enqueue_script( 'hl-survey-modal', HL_CORE_PLUGIN_URL . 'assets/js/survey-modal.js', array( 'jquery' ), HL_CORE_VERSION, true );
        wp_localize_script( 'hl-survey-modal', 'hlSurveyModal', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
        ) );
    }

    /**
     * Render empty modal shell — populated via AJAX (cache-safe).
     */
    public function render_modal_shell() {
        if ( ! is_user_logged_in() ) {
            return;
        }
        // Only on Program Page and Dashboard.
        if ( ! $this->is_survey_trigger_page() ) {
            return;
        }
        ?>
        <div id="hl-survey-modal-shell" style="display:none;"
             data-check-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
             data-check-nonce="<?php echo esc_attr( wp_create_nonce( 'hl_check_pending_surveys' ) ); ?>">
            <div class="hl-survey-overlay" style="display:none;"></div>
            <div class="hl-survey-modal" role="dialog" aria-modal="true" aria-labelledby="hl-survey-title" style="display:none;">
                <div class="hl-survey-loading">
                    <div class="hl-spinner"></div>
                </div>
                <div class="hl-survey-content" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Check if user has pending surveys.
     */
    public function ajax_check_pending() {
        check_ajax_referer( 'hl_check_pending_surveys', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in.' );
        }

        $service = new HL_Survey_Service();
        $data = $service->get_next_pending_for_user( get_current_user_id() );

        if ( ! $data ) {
            wp_send_json_success( array( 'has_pending' => false ) );
        }

        $survey  = $data['survey'];
        $pending = $data['pending'];

        // Get enrollment language.
        global $wpdb;
        $lang = $wpdb->get_var( $wpdb->prepare(
            "SELECT language_preference FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $pending['enrollment_id']
        ) ) ?: 'en';

        // Build modal HTML server-side.
        $html = $this->build_modal_html( $survey, $pending, $lang );

        wp_send_json_success( array(
            'has_pending' => true,
            'html'        => $html,
            'pending_id'  => $pending['pending_id'],
            'submit_nonce' => wp_create_nonce( 'hl_survey_submit_' . $pending['pending_id'] ),
        ) );
    }

    /**
     * AJAX: Submit survey response.
     * Security: nonce + login + user ownership + rate limit.
     */
    public function ajax_submit() {
        $pending_id = absint( $_POST['pending_id'] ?? 0 );
        if ( ! $pending_id ) {
            wp_send_json_error( 'Missing pending ID.' );
        }

        check_ajax_referer( 'hl_survey_submit_' . $pending_id, 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in.' );
        }

        // Rate limit: 3-second cooldown per user.
        $throttle_key = 'hl_survey_throttle_' . get_current_user_id();
        if ( get_transient( $throttle_key ) ) {
            wp_send_json_error( 'Please wait a moment before submitting again.' );
        }

        // User ownership check: verify current user owns this pending survey.
        $response_repo = new HL_Survey_Response_Repository();
        $pending = $response_repo->get_pending_by_id( $pending_id );
        if ( ! $pending || (int) $pending['user_id'] !== get_current_user_id() ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $responses = json_decode( wp_unslash( $_POST['responses'] ?? '{}' ), true );
        if ( ! is_array( $responses ) ) {
            wp_send_json_error( 'Invalid responses.' );
        }

        $service = new HL_Survey_Service();
        $result  = $service->submit_response( $pending_id, $responses );

        if ( $result === 'already_submitted' ) {
            wp_send_json_success( array( 'status' => 'already_submitted', 'message' => __( 'Survey already submitted.', 'hl-core' ) ) );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        set_transient( $throttle_key, 1, 3 ); // 3-second cooldown.
        wp_send_json_success( array( 'status' => 'submitted', 'response_id' => $result ) );
    }

    /**
     * Build the modal inner HTML for a pending survey.
     */
    private function build_modal_html( $survey, $pending, $lang ) {
        ob_start();
        ?>
        <h2 id="hl-survey-title"><?php echo esc_html( $survey->display_name ); ?></h2>
        <p class="hl-survey-course-name"><?php echo esc_html( $pending['course_title'] ); ?></p>

        <?php $intro = $survey->get_intro_text( $lang ); if ( $intro ) : ?>
            <p class="hl-survey-intro"><?php echo esc_html( $intro ); ?></p>
        <?php endif; ?>

        <form id="hl-survey-form" data-pending-id="<?php echo esc_attr( $pending['pending_id'] ); ?>">
        <?php
        $questions = $survey->get_questions();
        $current_group = null;

        foreach ( $questions as $q ) :
            // Group header.
            if ( $q['group'] && $q['group'] !== $current_group ) :
                $current_group = $q['group'];
                $group_label = $survey->get_group_label( $current_group, $lang );
                if ( $group_label ) : ?>
                    <div class="hl-survey-group-header">
                        <p class="hl-survey-group-label"><?php echo esc_html( $group_label ); ?></p>
                    </div>
                <?php endif;
            endif;

            $qtext = $survey->get_question_text( $q, $lang );

            if ( $q['type'] === 'likert_5' ) :
                $scale = $survey->get_scale_labels();
                ?>
                <fieldset class="hl-survey-question hl-survey-likert" data-key="<?php echo esc_attr( $q['question_key'] ); ?>">
                    <legend><?php echo esc_html( $qtext ); ?></legend>
                    <div class="hl-survey-pills" role="radiogroup" aria-label="<?php echo esc_attr( $qtext ); ?>">
                        <?php for ( $v = 1; $v <= 5; $v++ ) :
                            $label = $survey->get_scale_label_text( 'likert_5', $v, $lang );
                            $input_id = 'hl-q-' . esc_attr( $q['question_key'] ) . '-' . $v;
                            ?>
                            <label class="hl-survey-pill" for="<?php echo $input_id; ?>">
                                <input type="radio"
                                       id="<?php echo $input_id; ?>"
                                       name="<?php echo esc_attr( $q['question_key'] ); ?>"
                                       value="<?php echo esc_attr( $v ); ?>"
                                       <?php echo $v === 1 ? 'tabindex="0"' : 'tabindex="-1"'; ?>
                                       role="radio"
                                       aria-checked="false"
                                       required>
                                <span class="hl-pill-text"><?php echo esc_html( $label ); ?></span>
                            </label>
                        <?php endfor; ?>
                    </div>
                </fieldset>

            <?php elseif ( $q['type'] === 'open_text' ) : ?>
                <div class="hl-survey-question hl-survey-open-text" data-key="<?php echo esc_attr( $q['question_key'] ); ?>">
                    <label for="hl-q-<?php echo esc_attr( $q['question_key'] ); ?>"><?php echo esc_html( $qtext ); ?></label>
                    <textarea id="hl-q-<?php echo esc_attr( $q['question_key'] ); ?>"
                              name="<?php echo esc_attr( $q['question_key'] ); ?>"
                              rows="3"
                              <?php echo ! empty( $q['required'] ) ? 'required' : ''; ?>></textarea>
                </div>
            <?php endif;

        endforeach;
        ?>
            <div class="hl-survey-error" aria-live="polite" style="display:none;"></div>
            <div class="hl-survey-actions">
                <button type="submit" class="hl-btn hl-btn-primary" id="hl-survey-submit">
                    <?php echo esc_html__( 'Submit Survey', 'hl-core' ); ?>
                </button>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    private function is_survey_trigger_page() {
        global $post;
        if ( ! $post ) {
            return false;
        }
        // Check for Program Page or Dashboard shortcodes.
        return has_shortcode( $post->post_content, 'hl_program_page' )
            || has_shortcode( $post->post_content, 'hl_dashboard' );
    }
}
```

- [ ] **Step 2: Initialize the modal in hl-core.php or init hooks**

In `hl-core.php`, in the `init()` method or `init_hooks()`, add:

```php
HL_Frontend_Survey_Modal::instance()->init();
```

- [ ] **Step 3: Create `assets/js/survey-modal.js`**

Create a new file (NOT appended to frontend.js — conditionally enqueued). Must implement:

1. **DOMContentLoaded**: Find `#hl-survey-modal-shell`, read `data-check-url` and `data-check-nonce`, fire AJAX check
2. **AJAX check handler**: If `has_pending === true`, inject returned HTML into `.hl-survey-content`, show overlay + modal, hide loading spinner
3. **Focus trap**: On modal show, set `inert` on `#page` wrapper, find all focusable elements, loop Tab between first/last, intercept Escape key (refocus first element, do not close)
4. **Likert keyboard nav**: Arrow Left/Right moves selection within `role="radiogroup"`, updates `aria-checked` and `tabindex` (roving)
5. **Form validation**: On submit click, check all `[required]` inputs. If incomplete, show error in `aria-live` region, refocus first empty field
6. **Submit handler**: Disable button, show "Submitting..." text, serialize form to JSON, AJAX POST with pending_id + nonce + responses. On success: close modal, remove inert, reload page. On duplicate: same. On error: re-enable button, show error message.
7. **sessionStorage draft save**: On every input/change event, serialize form state to `sessionStorage.setItem('hl_survey_draft_' + pendingId, JSON.stringify(data))`. On modal populate, check for existing draft and restore values. On successful submit, clear draft.

~220 lines total. jQuery-based (matches existing codebase).

- [ ] **Step 4: Create `assets/css/survey-modal.css`**

Create a new file with:

1. **Overlay**: `.hl-survey-overlay` — fixed, full-screen, `background: rgba(0,0,0,0.6)`, `z-index: 100001`
2. **Modal box**: `.hl-survey-modal` — fixed, centered, `max-width: 640px`, `max-height: 90vh`, `overflow-y: auto`, `z-index: 100002`, white bg, `border-radius: 12px`, shadow
3. **Title + course name**: styled heading + subtitle
4. **Likert pills**: `.hl-survey-pill` — inline-flex, `min-height: 44px`, `min-width: 44px`, border, rounded, pointer cursor. Selected state: filled bg + white text. Visually hidden radio inputs.
5. **Responsive**: `@media (max-width: 576px)` — `.hl-survey-pills` flex-direction: column, pills full-width
6. **Open text**: textarea full-width, min-height 80px
7. **Error region**: `.hl-survey-error` — red text, margin-top
8. **Spinner**: `.hl-spinner` — CSS animation
9. **Submit button**: `.hl-btn-primary` — matches existing HL Core button styles
10. **Group header**: `.hl-survey-group-header` — border-bottom, margin, italic

~150 lines total.

- [ ] **Step 5: Commit**

```bash
git add includes/frontend/class-hl-frontend-survey-modal.php hl-core.php assets/js/survey-modal.js assets/css/survey-modal.css
git commit -m "feat(survey): AJAX modal — shell, check endpoint, submit endpoint, focus trap, draft save"
```

---

## Task 9: Frontend — Program Page survey_pending State

**Files:**
- Modify: `includes/frontend/class-hl-frontend-program-page.php`

- [ ] **Step 1: Add survey_pending overlay class**

In the overlay class resolution block (~lines 986-1004), add a new condition for `survey_pending` BEFORE the check for `completion_percent >= 100`:

```php
            // Survey pending — course done but survey not yet submitted.
            if ( $completion_status === 'survey_pending' ) {
                $overlay_class = 'hl-pp-overlay-survey-pending';
                $status_label  = __( 'Survey Pending', 'hl-core' );
                $sub_label     = __( 'Complete the survey to finish this course', 'hl-core' );
            }
```

- [ ] **Step 2: Render the sub-label in the card HTML**

In the card HTML rendering (~line 1127), after the status label, add the sub-label if set:

```php
            <?php if ( ! empty( $sub_label ) ) : ?>
                <span class="hl-pp-sub-label"><?php echo esc_html( $sub_label ); ?></span>
            <?php endif; ?>
```

- [ ] **Step 3: Add CSS for survey-pending overlay**

In `assets/css/frontend.css`:

```css
.hl-pp-overlay-survey-pending {
    background: rgba(245, 158, 11, 0.12);
    border-left: 4px solid #F59E0B;
}
.hl-pp-overlay-survey-pending .hl-pp-status-text {
    color: #D97706;
}
.hl-pp-sub-label {
    display: block;
    font-size: 0.75rem;
    color: #6B7280;
    margin-top: 2px;
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/frontend/class-hl-frontend-program-page.php assets/css/frontend.css
git commit -m "feat(survey): survey_pending overlay + sub-label on Program Page"
```

---

## Task 10: Admin — Survey Editor Tab on Instruments

**Files:**
- Create: `includes/admin/class-hl-admin-survey.php`
- Modify: `includes/admin/class-hl-admin-instruments.php`

This is the largest admin task. It includes: list view, editor form with multilingual question editing, duplicate workflow, preview, and delete responses.

- [ ] **Step 1: Add "Course Surveys" tab to Instruments**

In `class-hl-admin-instruments.php`, find the tabs array (~line 86-89) and add:

```php
        'surveys' => __( 'Course Surveys', 'hl-core' ),
```

Then in the main render routing, add the case for the surveys tab that delegates to `HL_Admin_Survey`:

```php
        case 'surveys':
            HL_Admin_Survey::instance()->render_tab();
            break;
```

- [ ] **Step 2a: Create HL_Admin_Survey — singleton + scaffolding**

Create `includes/admin/class-hl-admin-survey.php`. Singleton pattern (match `HL_Admin_Course_Catalog`). Methods: `instance()`, `handle_early_actions()`, `render_tab()`. The `render_tab()` dispatches based on `$_GET['survey_action']`:
- `''` (default) → `render_list()`
- `'new'` / `'edit'` → `render_form()`
- `'preview'` → `render_preview()`

`handle_early_actions()` checks for POST nonces: `hl_save_survey_nonce` → `handle_save()`, `hl_duplicate_survey_nonce` → `handle_duplicate()`, `hl_delete_survey_responses_nonce` → `handle_delete_responses()`.

- [ ] **Step 2b: Implement render_list()**

List view table with columns: Internal Name (link to edit), Display Name, Type, Version, Status (pill badge — `<span class="hl-status-pill hl-status-{status}">`), Responses (computed via `HL_Survey_Repository::get_response_count()`), Used By (cycle names from `get_cycles_using_survey()`), Actions (Edit | Duplicate | Preview links).

Add orphan detection admin notice at top: query `SELECT COUNT(*) FROM hl_pending_survey WHERE triggered_at < DATE_SUB(NOW(), INTERVAL 30 DAY)`. If > 0, show warning notice.

When survey has responses: Edit link replaced with "View (Locked)" + "Duplicate as New Version" link.

- [ ] **Step 2c: Implement render_form() — question editor with EN/ES/PT**

Editor form with nonce `hl_save_survey_nonce`:
- Hidden: `survey_id` (0 for new)
- Fields: internal_name (text), display_name (text), survey_type (select), status (select: draft/published)
- **Questions section**: Dynamic rows. Each row has: question_key (text, auto-slug), type (select: likert_5/open_text/yes_no), required (checkbox), group (text), text_en (textarea), text_es (textarea), text_pt (textarea). "Add Question" button appends new row. "Remove" button per row. Drag handle for reorder (use existing jQuery UI sortable).
- **Scale Labels section** (collapsible): editable labels per scale value in EN/ES/PT.
- **Intro Text section**: EN/ES/PT textareas for intro paragraph.
- **Group Labels section**: EN/ES/PT textareas per group key.
- **Locked state**: If `has_responses()` true, all fields `disabled`. Banner: "This survey has X responses and is locked." Buttons: "Duplicate as New Version", "Delete Responses".
- Questions JSON is serialized to a hidden field on form submit via JS.

- [ ] **Step 2d: Implement handle_save()**

Nonce: `wp_verify_nonce($_POST['hl_save_survey_nonce'], 'hl_save_survey')`. Capability: `manage_hl_core`. Sanitize: `sanitize_text_field` for names, `sanitize_text_field` for type/status, `json_decode` + re-encode for questions/scales/labels JSON (reject invalid). Validate: internal_name required, at least one question, all questions have text_en. Create or update via repository. Audit log. Redirect with success message.

- [ ] **Step 2e: Implement handle_duplicate() and handle_delete_responses()**

Duplicate: nonce check, capability check, call `HL_Survey_Service::duplicate_survey()`, audit log, redirect.

Delete Responses: nonce check, capability check, call `HL_Survey_Response_Repository::delete_all_responses_for_survey()`, **also resolve any pending rows**: `DELETE FROM hl_pending_survey WHERE survey_id = %d` + mark those components complete. Audit log. Redirect.

- [ ] **Step 2f: Implement render_preview()**

Read-only modal rendering: loads the survey, renders the questions in English using the same `build_modal_html()` pattern from `HL_Frontend_Survey_Modal`. Show in a styled container with a note: "Preview — switch site language (WPML) to preview other translations."

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-survey.php includes/admin/class-hl-admin-instruments.php
git commit -m "feat(survey): admin editor — list, edit, duplicate, delete responses"
```

---

## Task 11: Admin — Cycle Editor Survey Dropdown

**Files:**
- Modify: `includes/admin/class-hl-admin-cycles.php`

- [ ] **Step 1: Add survey dropdown to cycle editor form**

In the details form section (~line 697, after the Partnership dropdown), add:

```php
        // End of Course Survey dropdown.
        $survey_repo = new HL_Survey_Repository();
        $published_surveys = $survey_repo->get_published_by_type( 'end_of_course' );
        $current_survey_id = absint( $cycle->survey_id ?? 0 );

        echo '<div class="hl-field">';
        echo '<label for="survey_id">' . esc_html__( 'End of Course Survey', 'hl-core' ) . '</label>';
        echo '<select id="survey_id" name="survey_id">';
        echo '<option value="">' . esc_html__( '-- None --', 'hl-core' ) . '</option>';
        foreach ( $published_surveys as $s ) {
            echo '<option value="' . esc_attr( $s->survey_id ) . '"' . selected( $current_survey_id, $s->survey_id, false ) . '>' . esc_html( $s->internal_name ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Participants must complete this survey after finishing each course.', 'hl-core' ) . '</p>';
        echo '</div>';
```

- [ ] **Step 2: Save survey_id in the cycle save handler**

In the save/update method (~line 110-121), add `survey_id` to the data array:

```php
        $survey_id = ! empty( $_POST['survey_id'] ) ? absint( $_POST['survey_id'] ) : null;
```

And include it in the `$data` array passed to create/update. Handle the null case (empty string → NULL in DB).

- [ ] **Step 3: Add change warning for cycles with existing responses**

When saving and the `survey_id` is changing from a non-null value, check for existing responses and log audit event if found.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-cycles.php
git commit -m "feat(survey): survey dropdown in cycle editor with change warning"
```

---

## Task 12: Admin — Course Catalog Requires Survey Checkbox

**Files:**
- Modify: `includes/admin/class-hl-admin-course-catalog.php`

- [ ] **Step 1: Add checkbox to catalog form**

In the form rendering (~line 407, after existing fields), add:

```php
        echo '<div class="hl-field">';
        echo '<label>';
        echo '<input type="checkbox" name="requires_survey" value="1"' . checked( $entry->requires_survey ?? 1, 1, false ) . '> ';
        echo esc_html__( 'Requires End of Course Survey', 'hl-core' );
        echo '</label>';
        echo '</div>';
```

- [ ] **Step 2: Save requires_survey in the save handler**

In `handle_save()` (~line 54-64), add:

```php
        $requires_survey = ! empty( $_POST['requires_survey'] ) ? 1 : 0;
```

Include in the `$data` array.

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-course-catalog.php
git commit -m "feat(survey): requires_survey checkbox on course catalog"
```

---

## Task 13: Admin — Survey Reports

**Files:**
- Create: `includes/admin/class-hl-admin-survey-reports.php`

- [ ] **Step 1a: Create HL_Admin_Survey_Reports — scaffolding + filters**

Create `includes/admin/class-hl-admin-survey-reports.php`. Singleton pattern. `render_page()` checks capability, renders filter bar, dispatches to view.

Filter bar: Survey dropdown (all surveys), Cycle multi-select, Partnership dropdown, School dropdown, Date range (from/to). Filters passed to `HL_Survey_Response_Repository::get_responses_for_report()`.

- [ ] **Step 1b: Implement render_summary()**

Summary cards row: Total Responses (COUNT), Average Overall Agreement (mean of all Likert means), Completion Rate (responses / course completions where survey was required).

**Per-question Likert distribution table**: For each Likert question, calculate distribution (% per scale value 1-5) and mean. Decode `responses_json` in PHP (not JSON_EXTRACT — per data agent recommendation). Loop responses once, accumulate counts per question_key per value.

**Per-course comparison table**: GROUP BY `catalog_id` → count responses, compute mean of all Likert items, find lowest-scoring question. Sort by mean ascending (weakest first). Display catalog_code, course_title, response count, mean, lowest question.

- [ ] **Step 1c: Implement render_open_text()**

Table: Participant name, School, Course, Response text, Date. Paginated (25 per page). Filter by specific question dropdown (liked_most / could_improve). Always show English question text in header. Response text shown as-is (in submitted language). Sortable by date.

- [ ] **Step 1d: Implement handle_csv_export()**

CSV with two header rows per spec:
- Row 1: column keys (Participant, Email, School, Cycle, Course Code, Course Title, Survey Version, Language, question_key_1, question_key_2, ..., Submitted At)
- Row 2: full English question text for each question column
- Row 3+: one row per response, Likert as integers 1-5, open text as-is

Set headers: `Content-Type: text/csv`, `Content-Disposition: attachment; filename="survey-responses-{date}.csv"`. Use `fputcsv()` to PHP output stream. Respect active filters.

- [ ] **Step 1e: Implement handle_delete_responses() from report view**

Bulk delete: checkboxes per response row, "Delete Selected" action. Nonce check, capability check, call `HL_Survey_Response_Repository::delete_responses($response_ids)`. Audit log each deletion. Two-step confirmation: "Delete X selected responses? This cannot be undone." with 3-second delay on confirm button.

- [ ] **Step 2: Register the report page**

In `class-hl-admin.php`, add the Course Surveys report as a submenu page or tab in the existing reports section. Route to `HL_Admin_Survey_Reports::instance()->render_page()`.

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-survey-reports.php
git commit -m "feat(survey): admin reports — summary, open text, CSV export"
```

---

## Task 14: Admin Manual Complete Bypass + Audit

**Files:**
- Modify: `includes/admin/class-hl-admin-enrollments.php`

- [ ] **Step 1: Add audit event on manual complete bypass**

In `handle_component_actions()` (~line 482-543), after the component is manually marked complete, check if the component's course had a pending survey:

```php
        // Audit: survey gate bypass on manual complete.
        global $wpdb;
        $pending = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_pending_survey WHERE enrollment_id = %d AND component_id = %d",
            $enrollment_id, $component_id
        ), ARRAY_A );
        if ( $pending ) {
            $wpdb->delete( $wpdb->prefix . 'hl_pending_survey', array( 'pending_id' => $pending['pending_id'] ) );
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'survey_gate.admin_bypass', array(
                    'enrollment_id' => $enrollment_id,
                    'component_id'  => $component_id,
                    'survey_id'     => $pending['survey_id'],
                    'bypassed_by'   => get_current_user_id(),
                ) );
            }
        }
```

- [ ] **Step 2: Commit**

```bash
git add includes/admin/class-hl-admin-enrollments.php
git commit -m "feat(survey): audit event on admin manual-complete bypass"
```

---

## Task 15: Deploy + Smoke Test

- [ ] **Step 1: Update STATUS.md and README.md**

Add the Course Survey Builder section to both files per CLAUDE.md Rule #3.

- [ ] **Step 2: Commit docs**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md + README.md with Course Survey Builder"
```

- [ ] **Step 3: Deploy to test server**

Follow deployment instructions in `.claude/skills/deploy.md`.

- [ ] **Step 4: Verify schema migration**

```bash
wp hl-core smoke-test
```

Check: 3 new tables created, 2 columns added, ENUM extended, 2 seed surveys present.

- [ ] **Step 5: Browser test — admin flows**

Using Playwright or manual browser testing:
1. Navigate to Instruments > Course Surveys tab — verify 2 surveys listed
2. Edit the 2026 survey — verify questions, scales, translations render
3. Go to Cycles > edit a cycle — verify survey dropdown shows published surveys
4. Go to Course Catalog > edit an entry — verify requires_survey checkbox

- [ ] **Step 6: Browser test — participant flow**

1. Log in as a test participant
2. Complete a course in an assigned cycle with survey configured
3. Verify modal appears on Program Page
4. Fill out and submit
5. Verify component flips to Complete
6. Check admin reports for the response

- [ ] **Step 7: Final commit if any fixes needed**

```bash
git add -A
git commit -m "fix(survey): smoke test fixes"
```
