# Course Catalog Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce a Course Catalog that maps logical courses to their EN/ES/PT LearnDash variants, making the entire HL Core system language-aware.

**Architecture:** New `hl_course_catalog` table with stable `catalog_code` keys. Components reference catalog entries via `catalog_id` FK. Routing service, LD integration, and frontend resolve language variants from the catalog. Enrollments get `language_preference` for display.

**Tech Stack:** PHP 7.4+, WordPress/wpdb, LearnDash hooks, vanilla JS for admin AJAX.

**Spec:** `docs/superpowers/specs/2026-04-04-course-catalog-design.md` (Rev 3)

**Environment:** No local PHP runtime. Verification = deploy to test server via SCP + WP-CLI queries over SSH. See `.claude/skills/deploy.md` for SSH commands.

---

## File Structure

### New Files
| File | Responsibility |
|---|---|
| `includes/domain/class-hl-course-catalog.php` | Domain model (properties, helper methods) |
| `includes/domain/repositories/class-hl-course-catalog-repository.php` | CRUD operations against `hl_course_catalog` |
| `includes/admin/class-hl-admin-course-catalog.php` | Admin page: list view, add/edit form, AJAX, validation |
| `assets/js/admin-course-catalog.js` | AJAX search dropdowns for LD courses |

### Modified Files
| File | What Changes |
|---|---|
| `hl-core.php` | Require new class files, init admin page |
| `includes/class-hl-installer.php` | New table DDL, rev 30 migrations, seed data |
| `includes/domain/class-hl-component.php` | Add `catalog_id` property |
| `includes/services/class-hl-pathway-routing-service.php` | `$stages` uses catalog_codes, new `is_catalog_entry_completed()` |
| `includes/integrations/class-hl-learndash-integration.php` | Catalog-first completion matching |
| `includes/services/class-hl-import-participant-handler.php` | `language` CSV column, re-import diff |
| `includes/admin/class-hl-admin-enrollments.php` | `language_preference` field in edit form |
| `includes/admin/class-hl-admin-pathways.php` | Catalog dropdown for LD components |
| `includes/admin/class-hl-admin-reporting.php` | Use catalog title for LD components |
| `includes/frontend/class-hl-frontend-program-page.php` | Language-aware course links + progress |
| `includes/frontend/class-hl-frontend-my-progress.php` | Language-aware progress |
| `includes/frontend/class-hl-frontend-my-programs.php` | Language-aware progress |
| `includes/frontend/class-hl-frontend-component-page.php` | Language-aware course redirect |

---

## Task 1: Domain Model + Repository

**Files:**
- Create: `includes/domain/class-hl-course-catalog.php`
- Create: `includes/domain/repositories/class-hl-course-catalog-repository.php`

- [ ] **Step 1: Create domain model**

Create `includes/domain/class-hl-course-catalog.php`:

```php
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
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Get all non-null LD course IDs as an associative array.
     * @return array e.g. ['en' => 30280, 'es' => 30304]
     */
    public function get_language_course_ids() {
        $ids = array();
        if ($this->ld_course_en) $ids['en'] = absint($this->ld_course_en);
        if ($this->ld_course_es) $ids['es'] = absint($this->ld_course_es);
        if ($this->ld_course_pt) $ids['pt'] = absint($this->ld_course_pt);
        return $ids;
    }

    /**
     * Resolve the correct LD course ID for a given language preference.
     * Falls back to English if preferred language variant is not available.
     *
     * @param string $lang 'en', 'es', or 'pt'
     * @return int|null LD course post ID
     */
    public function resolve_course_id($lang = 'en') {
        $field = 'ld_course_' . $lang;
        if (!empty($this->$field)) {
            return absint($this->$field);
        }
        // Fallback to English
        if (!empty($this->ld_course_en)) {
            return absint($this->ld_course_en);
        }
        return null;
    }

    /**
     * Get language badges string for admin display.
     * @return string e.g. "[EN] [ES]"
     */
    public function get_language_badges() {
        $badges = array();
        if ($this->ld_course_en) $badges[] = 'EN';
        if ($this->ld_course_es) $badges[] = 'ES';
        if ($this->ld_course_pt) $badges[] = 'PT';
        return implode(' ', array_map(function($b) { return '[' . $b . ']'; }, $badges));
    }

    public function to_array() {
        return get_object_vars($this);
    }
}
```

- [ ] **Step 2: Create repository**

Create `includes/domain/repositories/class-hl-course-catalog-repository.php`:

```php
<?php
if (!defined('ABSPATH')) exit;

class HL_Course_Catalog_Repository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_course_catalog';
    }

    public function get_all($status = null) {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table()}";
        if ($status) {
            $sql = $wpdb->prepare($sql . " WHERE status = %s", $status);
        }
        $sql .= " ORDER BY catalog_code ASC";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return array_map(function($row) { return new HL_Course_Catalog($row); }, $rows ?: array());
    }

    public function get_by_id($catalog_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE catalog_id = %d", $catalog_id
        ), ARRAY_A);
        return $row ? new HL_Course_Catalog($row) : null;
    }

    public function get_by_code($catalog_code) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE catalog_code = %s", $catalog_code
        ), ARRAY_A);
        return $row ? new HL_Course_Catalog($row) : null;
    }

    /**
     * Find catalog entry by any LD course ID (reverse lookup).
     * Used by on_course_completed() hook.
     */
    public function find_by_ld_course_id($course_id) {
        global $wpdb;
        $course_id = absint($course_id);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE ld_course_en = %d OR ld_course_es = %d OR ld_course_pt = %d
             LIMIT 1",
            $course_id, $course_id, $course_id
        ), ARRAY_A);
        return $row ? new HL_Course_Catalog($row) : null;
    }

    /**
     * Get all catalog entries indexed by catalog_code.
     * Used for static caching in routing service.
     */
    public function get_all_indexed_by_code() {
        $entries = $this->get_all();
        $indexed = array();
        foreach ($entries as $entry) {
            $indexed[$entry->catalog_code] = $entry;
        }
        return $indexed;
    }

    public function create($data) {
        global $wpdb;
        if (empty($data['catalog_uuid'])) {
            $data['catalog_uuid'] = HL_DB_Utils::generate_uuid();
        }
        $result = $wpdb->insert($this->table(), $data);
        if ($result === false) {
            return new WP_Error('db_insert_failed', $wpdb->last_error);
        }
        return $wpdb->insert_id;
    }

    public function update($catalog_id, $data) {
        global $wpdb;
        $result = $wpdb->update($this->table(), $data, array('catalog_id' => $catalog_id));
        if ($result === false) {
            return new WP_Error('db_update_failed', $wpdb->last_error);
        }
        return $this->get_by_id($catalog_id);
    }

    /**
     * Check if a LD course ID is already used by another catalog entry.
     * @return int|null catalog_id of the conflicting entry, or null.
     */
    public function find_duplicate_course_id($course_id, $lang_column, $exclude_catalog_id = 0) {
        global $wpdb;
        $allowed = array('ld_course_en', 'ld_course_es', 'ld_course_pt');
        if (!in_array($lang_column, $allowed, true)) {
            return null;
        }
        return $wpdb->get_var($wpdb->prepare(
            "SELECT catalog_id FROM {$this->table()}
             WHERE ($lang_column = %d OR ld_course_en = %d OR ld_course_es = %d OR ld_course_pt = %d)
               AND catalog_id != %d
             LIMIT 1",
            $course_id, $course_id, $course_id, $course_id, $exclude_catalog_id
        ));
    }

    /**
     * Count active components referencing a catalog entry.
     */
    public function count_active_components($catalog_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hl_component
             WHERE catalog_id = %d AND status = 'active'",
            $catalog_id
        ));
    }

    /**
     * Check if the catalog table exists.
     */
    public function table_exists() {
        global $wpdb;
        static $exists = null;
        if ($exists === null) {
            $exists = (bool) $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $this->table())
            );
        }
        return $exists;
    }
}
```

- [ ] **Step 3: Add `catalog_id` property to component model**

Edit `includes/domain/class-hl-component.php` — add `public $catalog_id;` after the `$external_ref` property.

- [ ] **Step 4: Register new files in hl-core.php**

Edit `hl-core.php` — add require_once lines in the domain section (after `class-hl-component.php`):

```php
require_once HL_CORE_INCLUDES_DIR . 'domain/class-hl-course-catalog.php';
```

And in the repositories section (after `class-hl-component-repository.php` or similar):

```php
require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-course-catalog-repository.php';
```

- [ ] **Step 5: Commit**

```bash
git add includes/domain/class-hl-course-catalog.php includes/domain/repositories/class-hl-course-catalog-repository.php includes/domain/class-hl-component.php hl-core.php
git commit -m "feat(catalog): add Course Catalog domain model and repository"
```

---

## Task 2: Installer — Table, Migrations, Seed Data

**Files:**
- Modify: `includes/class-hl-installer.php`

- [ ] **Step 1: Add `hl_course_catalog` table to `create_tables()`**

In the `create_tables()` method, add the new table DDL to the `$tables` array (after the `hl_pathway` table, before `hl_component`):

```php
// Course Catalog table
$tables[] = "CREATE TABLE {$wpdb->prefix}hl_course_catalog (
    catalog_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    catalog_uuid char(36) NOT NULL,
    catalog_code varchar(50) NOT NULL COMMENT 'Stable lookup key e.g. TC1, MC3, TC1_S',
    title varchar(255) NOT NULL COMMENT 'Always English course name',
    ld_course_en bigint(20) unsigned NULL COMMENT 'English LD course post ID',
    ld_course_es bigint(20) unsigned NULL COMMENT 'Spanish LD course post ID',
    ld_course_pt bigint(20) unsigned NULL COMMENT 'Portuguese LD course post ID',
    status enum('active','archived') NOT NULL DEFAULT 'active',
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (catalog_id),
    UNIQUE KEY catalog_uuid (catalog_uuid),
    UNIQUE KEY catalog_code (catalog_code),
    UNIQUE KEY ld_course_en (ld_course_en),
    UNIQUE KEY ld_course_es (ld_course_es),
    UNIQUE KEY ld_course_pt (ld_course_pt),
    KEY status (status)
) $charset_collate;";
```

- [ ] **Step 2: Add migration calls to `create_tables()`**

Add these calls in `create_tables()` alongside other migrations (after `migrate_classroom_add_cycle_id`):

```php
// Add catalog_id column to hl_component.
self::migrate_add_component_catalog_id();

// Add language_preference column to hl_enrollment.
self::migrate_add_enrollment_language_preference();
```

- [ ] **Step 3: Bump revision to 30 and add seed + backfill calls in `maybe_upgrade()`**

Change `$current_revision = 29;` to `$current_revision = 30;`.

Add inside the `if ((int) $stored < $current_revision)` block, after the rev 28 block:

```php
// Rev 30: Course Catalog — seed data + backfill components + language_preference.
if ( (int) $stored < 30 ) {
    self::seed_course_catalog();
    self::backfill_component_catalog_ids();
    self::backfill_enrollment_language_preference();
}
```

- [ ] **Step 4: Write `migrate_add_component_catalog_id()` method**

```php
private static function migrate_add_component_catalog_id() {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_component';
    $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'catalog_id'");
    if (empty($columns)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN catalog_id bigint(20) unsigned NULL AFTER external_ref");
        $wpdb->query("ALTER TABLE {$table} ADD KEY catalog_id (catalog_id)");
    }
}
```

- [ ] **Step 5: Write `migrate_add_enrollment_language_preference()` method**

```php
private static function migrate_add_enrollment_language_preference() {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_enrollment';
    $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'language_preference'");
    if (empty($columns)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN language_preference varchar(5) NOT NULL DEFAULT 'en' AFTER status");
    }
}
```

- [ ] **Step 6: Write `seed_course_catalog()` method**

```php
private static function seed_course_catalog() {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_course_catalog';

    $entries = array(
        // Mastery courses
        array('TC0', 'TC0: Welcome to begin to ECSEL', 31037, 31039, null),
        array('TC1', 'TC1: Intro to begin to ECSEL', 30280, 30304, null),
        array('TC2', 'TC2: Your Own Emotionality', 30284, 30307, null),
        array('TC3', 'TC3: Getting to Know Emotion', 30286, 30309, null),
        array('TC4', 'TC4: Emotion in the Heat of the Moment', 30288, 30312, null),
        array('TC5', 'TC5: Connecting Emotion and Early Learning', 39724, 39736, null),
        array('TC6', 'TC6: Empathy, Acceptance & Prosocial Behaviors', 39726, 39738, null),
        array('TC7', 'TC7: begin to ECSEL Tools', 39728, 39740, null),
        array('TC8', 'TC8: ECSEL in the Everyday Classroom', 39730, 39742, null),
        array('MC1', 'MC1: Introduction to Reflective Practice', 30293, 30364, null),
        array('MC2', 'MC2: A Deeper Dive into Reflective Practice', 30295, 31537, null),
        array('MC3', 'MC3: Extending RP to Co-Workers', 39732, 39254, null),
        array('MC4', 'MC4: Extending RP to Families', 39734, 39488, null),
        // Streamlined courses (no Spanish equivalents confirmed yet)
        array('TC1_S', 'TC1: Intro to begin to ECSEL (Streamlined)', 31332, null, null),
        array('TC2_S', 'TC2: Your Own Emotionality (Streamlined)', 31333, null, null),
        array('TC3_S', 'TC3: Getting to Know Emotion (Streamlined)', 31334, null, null),
        array('TC4_S', 'TC4: Emotion in the Heat of the Moment (Streamlined)', 31335, null, null),
        array('TC5_S', 'TC5: Connecting Emotion and Early Learning (Streamlined)', 31336, null, null),
        array('TC6_S', 'TC6: Empathy, Acceptance & Prosocial Behaviors (Streamlined)', 31337, null, null),
        array('TC7_S', 'TC7: begin to ECSEL Tools (Streamlined)', 31338, null, null),
        array('TC8_S', 'TC8: ECSEL in the Everyday Classroom (Streamlined)', 31339, null, null),
        array('MC1_S', 'MC1: Introduction to Reflective Practice (Streamlined)', 31387, null, null),
        array('MC2_S', 'MC2: A Deeper Dive into Reflective Practice (Streamlined)', 31388, null, null),
        array('MC3_S', 'MC3: Extending RP to Co-Workers (Streamlined)', 31389, null, null),
        array('MC4_S', 'MC4: Extending RP to Families (Streamlined)', 31390, null, null),
    );

    foreach ($entries as $entry) {
        list($code, $title, $en, $es, $pt) = $entry;
        // INSERT IGNORE keyed on catalog_code UNIQUE — idempotent per entry
        $data = array(
            'catalog_uuid'  => HL_DB_Utils::generate_uuid(),
            'catalog_code'  => $code,
            'title'         => $title,
            'ld_course_en'  => $en,
            'ld_course_es'  => $es,
            'ld_course_pt'  => $pt,
        );
        $cols = implode(', ', array_keys($data));
        $vals = implode(', ', array_map(function($v) use ($wpdb) {
            return $v === null ? 'NULL' : $wpdb->prepare('%s', $v);
        }, array_values($data)));
        $wpdb->query("INSERT IGNORE INTO {$table} ({$cols}) VALUES ({$vals})");
    }
}
```

- [ ] **Step 7: Write `backfill_component_catalog_ids()` method**

```php
private static function backfill_component_catalog_ids() {
    global $wpdb;
    $prefix = $wpdb->prefix;

    $components = $wpdb->get_results(
        "SELECT component_id, external_ref FROM {$prefix}hl_component
         WHERE component_type = 'learndash_course' AND catalog_id IS NULL AND status = 'active'"
    );

    if (empty($components)) return;

    foreach ($components as $comp) {
        $ref = json_decode($comp->external_ref, true);
        if (!is_array($ref) || empty($ref['course_id'])) continue;

        $course_id = absint($ref['course_id']);
        $catalog_id = $wpdb->get_var($wpdb->prepare(
            "SELECT catalog_id FROM {$prefix}hl_course_catalog
             WHERE ld_course_en = %d OR ld_course_es = %d
             LIMIT 1",
            $course_id, $course_id
        ));

        if ($catalog_id) {
            $wpdb->update(
                $prefix . 'hl_component',
                array('catalog_id' => $catalog_id),
                array('component_id' => $comp->component_id, 'catalog_id' => null),
                array('%d'),
                array('%d', null)
            );
        } else {
            error_log(sprintf(
                '[HL Migration] Component %d has course_id %d not found in catalog',
                $comp->component_id, $course_id
            ));
        }
    }
}
```

- [ ] **Step 8: Write `backfill_enrollment_language_preference()` method**

```php
private static function backfill_enrollment_language_preference() {
    global $wpdb;
    $prefix = $wpdb->prefix;

    // Spanish LearnDash group IDs
    $spanish_groups = array(33639, 33667);

    // Find user_ids in Spanish groups via usermeta
    $placeholders = implode(',', array_fill(0, count($spanish_groups), '%s'));
    $meta_keys = array_map(function($gid) {
        return 'learndash_group_users_' . $gid;
    }, $spanish_groups);

    $spanish_user_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT user_id FROM {$prefix}usermeta
         WHERE meta_key IN ($placeholders)",
        $meta_keys
    ));

    if (empty($spanish_user_ids)) return;

    $user_placeholders = implode(',', array_fill(0, count($spanish_user_ids), '%d'));
    $wpdb->query($wpdb->prepare(
        "UPDATE {$prefix}hl_enrollment
         SET language_preference = 'es'
         WHERE user_id IN ($user_placeholders) AND language_preference = 'en'",
        $spanish_user_ids
    ));
}
```

- [ ] **Step 9: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat(catalog): add hl_course_catalog table, migrations, and seed data (rev 30)"
```

- [ ] **Step 10: Deploy to test and verify**

Deploy via SCP, then verify:

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SELECT catalog_code, title, ld_course_en, ld_course_es FROM wp_hl_course_catalog ORDER BY catalog_code"'
```

Expected: 25 rows with correct EN/ES course IDs.

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SELECT COUNT(*) as backfilled FROM wp_hl_component WHERE catalog_id IS NOT NULL"'
```

Expected: Non-zero count of backfilled components.

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SHOW COLUMNS FROM wp_hl_enrollment LIKE \"language_preference\""'
```

Expected: Column exists with default 'en'.

---

## Task 3: Routing Service Refactor

**Files:**
- Modify: `includes/services/class-hl-pathway-routing-service.php`

- [ ] **Step 1: Replace `$stages` with catalog-code-based definitions**

Replace the entire `$stages` array (lines 25-46) with:

```php
private static $stages = array(
    'A' => array(
        'label'         => 'Mentor Stage 1',
        'catalog_codes' => array('MC1', 'MC2'),
    ),
    'B' => array(
        'label'         => 'Mentor Stage 2',
        'catalog_codes' => array('MC3', 'MC4'),
    ),
    'C' => array(
        'label'         => 'Teacher Stage 1',
        'catalog_codes' => array('TC1', 'TC2', 'TC3', 'TC4'),
    ),
    'D' => array(
        'label'         => 'Teacher Stage 2',
        'catalog_codes' => array('TC5', 'TC6', 'TC7', 'TC8'),
    ),
    'E' => array(
        'label'         => 'Streamlined Stage 1',
        'catalog_codes' => array('TC0', 'TC1_S', 'TC2_S', 'TC3_S', 'TC4_S', 'MC1_S', 'MC2_S'),
    ),
);
```

- [ ] **Step 2: Add catalog cache and `load_catalog_cache()` method**

Add a static property and loader:

```php
private static $catalog_cache = null;

private static function load_catalog_cache() {
    if (self::$catalog_cache !== null) {
        return self::$catalog_cache;
    }
    $repo = new HL_Course_Catalog_Repository();
    if (!$repo->table_exists()) {
        self::$catalog_cache = array();
        return self::$catalog_cache;
    }
    self::$catalog_cache = $repo->get_all_indexed_by_code();
    return self::$catalog_cache;
}
```

- [ ] **Step 3: Add `is_catalog_entry_completed()` method**

```php
public static function is_catalog_entry_completed($user_id, $catalog_entry) {
    if (!$user_id || !$catalog_entry) {
        return false;
    }
    $ld = HL_LearnDash_Integration::instance();
    if (!$ld->is_active()) {
        return false;
    }
    $course_ids = $catalog_entry->get_language_course_ids();
    foreach ($course_ids as $lang => $course_id) {
        if ($ld->is_course_completed($user_id, $course_id)) {
            return true;
        }
    }
    return false;
}
```

- [ ] **Step 4: Add `is_catalog_ready()` health check**

```php
public static function is_catalog_ready() {
    $cache = self::load_catalog_cache();
    if (empty($cache)) {
        return false;
    }
    foreach (self::$stages as $key => $stage) {
        foreach ($stage['catalog_codes'] as $code) {
            if (!isset($cache[$code])) {
                return false;
            }
        }
    }
    return true;
}
```

- [ ] **Step 5: Rewrite `get_completed_stages()` to use catalog**

Replace the existing `get_completed_stages()` method (lines 149-175) with:

```php
public static function get_completed_stages($user_id) {
    if (!$user_id) {
        return array();
    }

    $cache = self::load_catalog_cache();
    if (empty($cache)) {
        error_log('[HL Routing] Course catalog is empty — stage completion cannot be evaluated');
        return array();
    }

    $ld = HL_LearnDash_Integration::instance();
    if (!$ld->is_active()) {
        return array();
    }

    $completed = array();

    foreach (self::$stages as $key => $stage) {
        $all_done = true;
        foreach ($stage['catalog_codes'] as $code) {
            if (!isset($cache[$code])) {
                error_log(sprintf('[HL Routing] catalog_code \'%s\' not found in catalog', $code));
                $all_done = false;
                break;
            }
            if (!self::is_catalog_entry_completed($user_id, $cache[$code])) {
                $all_done = false;
                break;
            }
        }
        if ($all_done) {
            $completed[] = $key;
        }
    }

    return $completed;
}
```

- [ ] **Step 6: Commit**

```bash
git add includes/services/class-hl-pathway-routing-service.php
git commit -m "feat(catalog): refactor routing service to use catalog codes for stage completion"
```

---

## Task 4: LearnDash Integration — Catalog-Aware Completion

**Files:**
- Modify: `includes/integrations/class-hl-learndash-integration.php`

- [ ] **Step 1: Rewrite `on_course_completed()` with catalog-first logic**

Replace the body of `on_course_completed()` (lines 74-196) with:

```php
public function on_course_completed($data) {
    if (!is_array($data) || empty($data['user']) || empty($data['course'])) {
        return;
    }

    $user_id   = is_object($data['user'])   ? $data['user']->ID   : $data['user'];
    $course_id = is_object($data['course'])  ? $data['course']->ID : $data['course'];

    global $wpdb;
    $now = current_time('mysql');

    // Find all active enrollments for this user
    $enrollments = $wpdb->get_results($wpdb->prepare(
        "SELECT enrollment_id, cycle_id, assigned_pathway_id
         FROM {$wpdb->prefix}hl_enrollment
         WHERE user_id = %d AND status = 'active'",
        $user_id
    ));

    if (empty($enrollments)) {
        return;
    }

    $cycle_ids = array_unique(wp_list_pluck($enrollments, 'cycle_id'));
    $cycle_placeholders = implode(',', array_fill(0, count($cycle_ids), '%d'));
    $cycle_enrollment_map = array();
    foreach ($enrollments as $enrollment) {
        $cycle_enrollment_map[$enrollment->cycle_id] = $enrollment->enrollment_id;
    }

    $matching_components = array();

    // --- Catalog path ---
    $repo = new HL_Course_Catalog_Repository();
    if ($repo->table_exists()) {
        $catalog_entry = $repo->find_by_ld_course_id($course_id);
        if ($catalog_entry) {
            $components = $wpdb->get_results($wpdb->prepare(
                "SELECT component_id, cycle_id, pathway_id
                 FROM {$wpdb->prefix}hl_component
                 WHERE catalog_id = %d
                   AND component_type = 'learndash_course'
                   AND status = 'active'
                   AND cycle_id IN ($cycle_placeholders)",
                array_merge(array($catalog_entry->catalog_id), $cycle_ids)
            ));
            if (!empty($components)) {
                $matching_components = $components;
            }
        }
    }

    // --- Fallback path (only if catalog matched nothing) ---
    if (empty($matching_components) && !get_option('hl_catalog_migration_complete', false)) {
        $components = $wpdb->get_results($wpdb->prepare(
            "SELECT component_id, cycle_id, pathway_id, external_ref
             FROM {$wpdb->prefix}hl_component
             WHERE component_type = 'learndash_course'
               AND cycle_id IN ($cycle_placeholders)
               AND status = 'active'",
            $cycle_ids
        ));
        foreach (($components ?: array()) as $component) {
            if (empty($component->external_ref)) continue;
            $ref = json_decode($component->external_ref, true);
            if (is_array($ref) && isset($ref['course_id']) && absint($ref['course_id']) === absint($course_id)) {
                $matching_components[] = $component;
            }
        }
        if (!empty($matching_components)) {
            error_log(sprintf('[HL LD] Fallback path matched course %d — catalog migration may be incomplete', $course_id));
        }
    }

    if (empty($matching_components)) {
        return;
    }

    // Upsert component states
    $updated_enrollment_ids = array();
    foreach ($matching_components as $component) {
        $enrollment_id = isset($cycle_enrollment_map[$component->cycle_id])
            ? $cycle_enrollment_map[$component->cycle_id]
            : 0;
        if (!$enrollment_id) continue;

        $existing_state_id = $wpdb->get_var($wpdb->prepare(
            "SELECT state_id FROM {$wpdb->prefix}hl_component_state
             WHERE enrollment_id = %d AND component_id = %d",
            $enrollment_id, $component->component_id
        ));

        $state_data = array(
            'completion_percent' => 100,
            'completion_status'  => 'complete',
            'completed_at'       => $now,
            'last_computed_at'   => $now,
        );

        if ($existing_state_id) {
            $wpdb->update(
                $wpdb->prefix . 'hl_component_state',
                $state_data,
                array('state_id' => $existing_state_id)
            );
        } else {
            $state_data['enrollment_id'] = $enrollment_id;
            $state_data['component_id']  = $component->component_id;
            $wpdb->insert($wpdb->prefix . 'hl_component_state', $state_data);
        }

        $updated_enrollment_ids[] = $enrollment_id;

        HL_Audit_Service::log('learndash_course.completed', array(
            'entity_type' => 'component',
            'entity_id'   => $component->component_id,
            'cycle_id'    => $component->cycle_id,
            'after_data'  => array(
                'user_id'       => $user_id,
                'course_id'     => $course_id,
                'enrollment_id' => $enrollment_id,
            ),
        ));
    }

    $updated_enrollment_ids = array_unique($updated_enrollment_ids);
    foreach ($updated_enrollment_ids as $eid) {
        do_action('hl_core_recompute_rollups', $eid);
    }

    do_action('hl_learndash_course_completed', $user_id, $course_id);
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/integrations/class-hl-learndash-integration.php
git commit -m "feat(catalog): catalog-aware on_course_completed with fallback"
```

---

## Task 5: Admin Course Catalog Page

**Files:**
- Create: `includes/admin/class-hl-admin-course-catalog.php`
- Create: `assets/js/admin-course-catalog.js`
- Modify: `hl-core.php`

- [ ] **Step 1: Create the admin page class**

Create `includes/admin/class-hl-admin-course-catalog.php` following the singleton pattern from `class-hl-admin-partnerships.php`. Include:

- Singleton `instance()` method
- `handle_early_actions()` for POST save/archive with nonce verification and `manage_hl_core` capability check
- `render()` method that dispatches to `render_list()` or `render_form()` based on `$_GET['action']`
- `render_list()` — table of catalog entries with Title, Code, EN/ES/PT course names, Status, row actions (Edit, Archive)
- `render_form()` — add/edit form with Title, Code, 3 course search fields, Status
- `ajax_search_ld_courses()` — AJAX handler for course search (min 2 chars, max 20 results)
- Validation: duplicate course ID check, required fields, catalog_code format
- Audit logging on create/update/archive

This is a large file (~400-500 lines). Build it following the exact patterns from `class-hl-admin-partnerships.php` for structure and `class-hl-admin-enrollments.php` for AJAX search.

- [ ] **Step 2: Create the AJAX search JS**

Create `assets/js/admin-course-catalog.js` following the vanilla JS pattern from the enrollment admin page. Implement debounced search for 3 course fields (EN, ES, PT).

- [ ] **Step 3: Register admin page in hl-core.php**

Add `require_once` for the new admin class in the admin section of `hl-core.php`:

```php
require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-course-catalog.php';
```

Add to the admin menu registration (find the `add_submenu_page` calls):

```php
add_submenu_page(
    'hl-core',
    'Course Catalog',
    'Course Catalog',
    'manage_hl_core',
    'hl-course-catalog',
    array(HL_Admin_Course_Catalog::instance(), 'render')
);
```

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-course-catalog.php assets/js/admin-course-catalog.js hl-core.php
git commit -m "feat(catalog): admin Course Catalog page with CRUD and AJAX search"
```

- [ ] **Step 5: Deploy and verify**

Deploy to test. Navigate to WP Admin > HL Core > Course Catalog. Verify:
- 25 seeded entries visible in the list
- Edit form loads with course data
- AJAX search returns LD course results
- Creating a new entry works
- Archiving works with component-count warning

---

## Task 6: Import Module — Language Column

**Files:**
- Modify: `includes/services/class-hl-import-participant-handler.php`

- [ ] **Step 1: Add `language` to recognized CSV columns**

In the `validate()` method, where CSV columns are mapped, add `language` as an optional recognized column.

- [ ] **Step 2: Add language validation**

In the validation loop, add:

```php
$language = isset($row['language']) ? strtolower(trim($row['language'])) : 'en';
if (!in_array($language, array('en', 'es', 'pt'), true)) {
    $messages[] = "Warning: Unrecognized language '{$language}', defaulting to 'en'.";
    $language = 'en';
}
$row['language_preference'] = $language;
```

- [ ] **Step 3: Add language to UPDATE diff-check**

In the section that compares existing enrollment data to CSV data (the `$has_side_effects` check), add:

```php
if (isset($row['language_preference']) && $existing_enrollment->language_preference !== $row['language_preference']) {
    $has_side_effects = true;
    $messages[] = sprintf('Language: %s -> %s', $existing_enrollment->language_preference, $row['language_preference']);
}
```

- [ ] **Step 4: Set language_preference in commit**

In the `commit()` method, when creating or updating enrollments, include `language_preference`:

For CREATE: add `'language_preference' => $row['language_preference']` to the insert data.
For UPDATE: add `'language_preference' => $row['language_preference']` to the update data.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-hl-import-participant-handler.php
git commit -m "feat(catalog): add language CSV column to import module"
```

---

## Task 7: Enrollment Edit Form — Language Preference

**Files:**
- Modify: `includes/admin/class-hl-admin-enrollments.php`

- [ ] **Step 1: Add language_preference dropdown to edit form**

In the enrollment edit form (find the form fields section), add after the status field:

```php
<tr>
    <th><label for="language_preference">Language Preference</label></th>
    <td>
        <select name="language_preference" id="language_preference">
            <option value="en" <?php selected($enrollment->language_preference ?? 'en', 'en'); ?>>English</option>
            <option value="es" <?php selected($enrollment->language_preference ?? 'en', 'es'); ?>>Spanish</option>
            <option value="pt" <?php selected($enrollment->language_preference ?? 'en', 'pt'); ?>>Portuguese</option>
        </select>
    </td>
</tr>
```

- [ ] **Step 2: Save language_preference on form submit**

In `handle_actions()` where enrollment data is saved, add `language_preference` to the update data:

```php
'language_preference' => sanitize_text_field($_POST['language_preference'] ?? 'en'),
```

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-enrollments.php
git commit -m "feat(catalog): add language_preference field to enrollment edit form"
```

---

## Task 8: Pathway Admin — Catalog Dropdown for Components

**Files:**
- Modify: `includes/admin/class-hl-admin-pathways.php`

- [ ] **Step 1: Replace course ID field with catalog dropdown**

In the component add/edit form, find where `learndash_course` type components show the `external_ref` course ID field. Replace with:

```php
if ($component_type === 'learndash_course') {
    $catalog_repo = new HL_Course_Catalog_Repository();
    $catalog_entries = $catalog_repo->get_all('active');
    ?>
    <tr>
        <th><label for="catalog_id">Course</label></th>
        <td>
            <select name="catalog_id" id="catalog_id" required>
                <option value="">-- Select Course --</option>
                <?php foreach ($catalog_entries as $entry): ?>
                    <option value="<?php echo esc_attr($entry->catalog_id); ?>"
                        <?php selected($component->catalog_id ?? '', $entry->catalog_id); ?>>
                        <?php echo esc_html($entry->title . ' ' . $entry->get_language_badges()); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <?php
}
```

- [ ] **Step 2: Save catalog_id on component create/update**

In the component save handler, when type is `learndash_course`:

```php
$catalog_id = absint($_POST['catalog_id'] ?? 0);
if ($catalog_id) {
    $component_data['catalog_id'] = $catalog_id;
    // Auto-fill title from catalog if not set
    $catalog_repo = new HL_Course_Catalog_Repository();
    $catalog_entry = $catalog_repo->get_by_id($catalog_id);
    if ($catalog_entry && empty($component_data['title'])) {
        $component_data['title'] = $catalog_entry->title;
    }
    // Set external_ref for backward compatibility
    if ($catalog_entry && $catalog_entry->ld_course_en) {
        $component_data['external_ref'] = json_encode(array('course_id' => $catalog_entry->ld_course_en));
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-pathways.php
git commit -m "feat(catalog): catalog dropdown with language badges for LD components"
```

---

## Task 9: Frontend Language Resolution

**Files:**
- Modify: `includes/frontend/class-hl-frontend-program-page.php`
- Modify: `includes/frontend/class-hl-frontend-my-progress.php`
- Modify: `includes/frontend/class-hl-frontend-my-programs.php`
- Modify: `includes/frontend/class-hl-frontend-component-page.php`

- [ ] **Step 1: Add `resolve_ld_course_id()` helper to domain model**

Add to `includes/domain/class-hl-course-catalog.php`:

```php
/**
 * Resolve the LD course ID for a component based on enrollment language.
 *
 * @param HL_Component  $component
 * @param object|null   $enrollment  Must have ->language_preference property.
 * @return int|null LD course post ID
 */
public static function resolve_ld_course_id($component, $enrollment = null) {
    if ($component->catalog_id) {
        $repo = new HL_Course_Catalog_Repository();
        $entry = $repo->get_by_id($component->catalog_id);
        if ($entry) {
            $lang = ($enrollment && !empty($enrollment->language_preference))
                ? $enrollment->language_preference
                : 'en';
            return $entry->resolve_course_id($lang);
        }
    }
    // Fallback to external_ref
    $ref = $component->get_external_ref_array();
    return isset($ref['course_id']) ? absint($ref['course_id']) : null;
}
```

- [ ] **Step 2: Update `class-hl-frontend-program-page.php`**

Find all occurrences of:
```php
$external_ref = $component->get_external_ref_array();
$course_id    = isset($external_ref['course_id']) ? absint($external_ref['course_id']) : 0;
```

Replace each with:
```php
$course_id = HL_Course_Catalog::resolve_ld_course_id($component, $enrollment) ?: 0;
```

Where `$enrollment` is the enrollment object available in the rendering context. There are approximately 4-5 locations in this file.

- [ ] **Step 3: Update `class-hl-frontend-my-progress.php`**

Same pattern — replace `external_ref['course_id']` lookups with `HL_Course_Catalog::resolve_ld_course_id($component, $enrollment)`. Approximately 2 locations.

- [ ] **Step 4: Update `class-hl-frontend-my-programs.php`**

Same pattern. Approximately 1 location (line ~127-129).

- [ ] **Step 5: Update `class-hl-frontend-component-page.php`**

Same pattern. Approximately 2 locations including the `wp_redirect` to the course URL.

- [ ] **Step 6: Commit**

```bash
git add includes/domain/class-hl-course-catalog.php includes/frontend/class-hl-frontend-program-page.php includes/frontend/class-hl-frontend-my-progress.php includes/frontend/class-hl-frontend-my-programs.php includes/frontend/class-hl-frontend-component-page.php
git commit -m "feat(catalog): language-aware course link and progress resolution on frontend"
```

---

## Task 10: Reporting — Catalog Titles

**Files:**
- Modify: `includes/admin/class-hl-admin-reporting.php`

- [ ] **Step 1: Update component title resolution in reports**

Find where reports display component titles for the enrollment detail view. Add catalog title resolution:

```php
// When displaying component title in reports:
if ($component->catalog_id) {
    $catalog_repo = new HL_Course_Catalog_Repository();
    $catalog_entry = $catalog_repo->get_by_id($component->catalog_id);
    $display_title = $catalog_entry ? $catalog_entry->title : $component->title;
} else {
    $display_title = $component->title;
}
```

This applies to the enrollment detail view's component table and any completion report that lists component names.

- [ ] **Step 2: Commit**

```bash
git add includes/admin/class-hl-admin-reporting.php
git commit -m "feat(catalog): use catalog titles in reports for LD components"
```

---

## Task 11: Final Deploy + Verification

- [ ] **Step 1: Deploy to test**

```bash
cd "C:/Users/MateoGonzalez/Dev Projects Mateo/housman-learning-academy/app/public/wp-content/plugins/hl-core"
tar --exclude='.git' --exclude='data' --exclude='vendor' --exclude='node_modules' -czf /tmp/hl-core.tar.gz -C .. hl-core
scp -i ~/.ssh/hla-test-keypair.pem /tmp/hl-core.tar.gz bitnami@44.221.6.201:/tmp/
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress/wp-content/plugins && sudo rm -rf hl-core && sudo tar -xzf /tmp/hl-core.tar.gz && sudo chown -R bitnami:daemon hl-core'
```

- [ ] **Step 2: Verify catalog table and seed data**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SELECT COUNT(*) FROM wp_hl_course_catalog"'
```

Expected: 25.

- [ ] **Step 3: Verify Olga's routing now works**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SELECT option_value FROM wp_options WHERE option_name = \"hl_core_schema_revision\""'
```

Expected: 30.

- [ ] **Step 4: Verify routing service health**

Test the routing service by checking if catalog is ready (will be visible in admin dashboard or error logs if something is wrong). Flush cache:

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress cache flush'
```

- [ ] **Step 5: Update STATUS.md and README.md**

Per CLAUDE.md rules, update both files to reflect the new Course Catalog feature.

- [ ] **Step 6: Final commit with STATUS.md + README.md**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md and README.md for Course Catalog feature"
```

---

## Implementation Priority (if time-boxing)

**v0.1 (core functionality):** Tasks 1-4 — domain model, installer, routing service, LD integration. This fixes the Olga bug and makes the system language-aware.

**v0.2 (admin UI):** Task 5 — Course Catalog admin page. Gives Yuyan the ability to manage catalog entries.

**v0.3 (full feature):** Tasks 6-10 — import, enrollment edit, pathway admin, frontend resolution, reporting.

**v1.0 (production):** Task 11 — full verification and deploy.
