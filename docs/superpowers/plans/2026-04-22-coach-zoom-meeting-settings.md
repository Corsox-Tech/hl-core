# Coach Zoom Meeting Settings — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hard-coded Zoom meeting `settings` payload with a per-coach configurable system (admin defaults + per-coach overrides), so Chris can stop the LMS from overwriting his standardized Zoom settings without sacrificing per-session recording attribution.

**Architecture:** New `hl_coach_zoom_settings` table + `hl_zoom_coaching_defaults` WP option, both consumed by a new `HL_Coach_Zoom_Settings_Service` whose `resolve_for_coach()` is called from `HL_Scheduling_Service::book_session()` and `reschedule_session_with_integrations()` and passed into `HL_Zoom_Integration::build_meeting_payload()`.

**Tech Stack:** PHP 7.4+ / WordPress 6.0+ / `$wpdb` / Zoom API v2 (Server-to-Server OAuth) / vanilla JS (no jQuery dependency) / Playwright for UI smoke.

**Spec:** `docs/superpowers/specs/2026-04-22-coach-zoom-meeting-settings.md` (v0.4)

**Branch:** Create `feature/ticket-31-coach-zoom-settings` off `main` BEFORE any work begins.

> **STATUS.md / README.md (CLAUDE.md Rule #3):** updated once at the end in §I (Task I-final), not after every section commit, to avoid 9 separate noise commits.

---

## Section A — Foundation: branch + version bump + schema migration + service skeleton

Goal: Land the table + an empty service class that's reachable from the rest of the codebase + bump `HL_CORE_VERSION` upfront so every test deploy in §B-§H serves cache-busted assets.

### Task A1: Create branch + bump HL_CORE_VERSION (cache-bust upfront)

**Files:** `hl-core.php` (HL_CORE_VERSION).

- [ ] **Step 1: Switch to main and pull (don't branch from current HEAD)**

```bash
git status                                  # confirm any in-progress work is committed/stashed
git checkout main
git pull origin main
```

Expected: clean working tree on `main` at latest origin SHA.

- [ ] **Step 2: Create + check out branch from main**

```bash
git checkout -b feature/ticket-31-coach-zoom-settings
git push -u origin feature/ticket-31-coach-zoom-settings
```

- [ ] **Step 3: Bump HL_CORE_VERSION NOW (not at end)**

CSS/JS in §F + §G/H enqueue with `HL_CORE_VERSION` for cache-bust. If the version isn't bumped before those deploys, every Playwright UI smoke step will load stale cached assets.

```bash
grep -n "HL_CORE_VERSION" hl-core.php | head -3
```

Edit `hl-core.php:22` (verify the actual current value first — likely `1.2.14`):

```php
define( 'HL_CORE_VERSION', '1.3.0' );
```

Coordinate with the team lead per `project_prod_branch_divergence_2026_04_22.md` to avoid conflicting with parallel branches.

- [ ] **Step 4: Commit**

```bash
git add hl-core.php
git commit -m "chore(coach-zoom): bump HL_CORE_VERSION to 1.3.0 (cache-bust upfront)"
```

---

### Task A2: Add `hl_coach_zoom_settings` table to installer schema

**Files:**
- Modify: `includes/class-hl-installer.php` — bump revision; add table to `get_schema()`.

- [ ] **Step 1: Bump schema revision 44 → 45**

Edit `includes/class-hl-installer.php:156`:

```php
$current_revision = 45;
```

- [ ] **Step 2: Add the table CREATE statement to `get_schema()`**

The schema-array variable is `$tables[]` (returned at the bottom of `get_schema()`). All existing entries use **lowercase** column types and **bare** `$charset_collate` (no curly braces). Append, matching that style exactly:

```php
$tables[] = "CREATE TABLE {$wpdb->prefix}hl_coach_zoom_settings (
    coach_user_id bigint(20) unsigned NOT NULL,
    waiting_room tinyint(1) NULL,
    mute_upon_entry tinyint(1) NULL,
    join_before_host tinyint(1) NULL,
    alternative_hosts varchar(1024) NULL,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id bigint(20) unsigned NULL,
    PRIMARY KEY  (coach_user_id),
    KEY updated_at (updated_at)
) $charset_collate;";
```

Do NOT add a guarded `if ($stored < 45)` migrate_* helper — `dbDelta` via `create_tables()` is the single canonical pattern.

- [ ] **Step 3: Deploy to test and force the upgrade to run**

```bash
bash bin/deploy.sh test
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval "HL_Installer::maybe_upgrade();" && wp option get hl_core_schema_revision'
```

Expected: option returns `45`.

- [ ] **Step 4: Verify table exists**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp db query "DESCRIBE wp_hl_coach_zoom_settings"'
```

Expected: 7-row DESCRIBE. `coach_user_id PRI`, `updated_at MUL`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat(coach-zoom): add hl_coach_zoom_settings table (schema rev 45)"
```

---

### Task A3: Create `HL_Coach_Zoom_Settings_Service` skeleton

**Files:**
- Create: `includes/services/class-hl-coach-zoom-settings-service.php`

- [ ] **Step 1: Create the file with skeleton + DEFAULTS constant**

Stubs return safe defaults (not `null`) so a stray early caller doesn't fatal on type juggling.

```php
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

    public static function get_admin_defaults() {
        return self::DEFAULTS; // TODO Task B3
    }
    public static function save_admin_defaults( array $values, $actor_user_id ) {
        return new WP_Error( 'not_implemented', 'Pending Task B3' );
    }
    public static function get_coach_overrides( $coach_user_id ) {
        return array(); // TODO Task B4
    }
    public static function save_coach_overrides( $coach_user_id, array $overrides, $actor_user_id, array $reset_fields = array() ) {
        return new WP_Error( 'not_implemented', 'Pending Task B5' );
    }
    public static function resolve_for_coach( $coach_user_id ) {
        return self::DEFAULTS; // TODO Task B6
    }
    public static function validate( array $values, $coach_user_id ) {
        return new WP_Error( 'not_implemented', 'Pending Task B2' );
    }
    public static function preflight_alternative_hosts( $coach_user_id, $alternative_hosts_csv ) {
        return true; // TODO Task C1
    }
}
```

- [ ] **Step 2: Register in `hl-core.php` BEFORE `HL_Scheduling_Service`**

In `hl-core.php`, find line 132 (`require_once … class-hl-scheduling-service.php;`). Insert immediately above:

```php
require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-coach-zoom-settings-service.php';
```

- [ ] **Step 3: Verify autoload order**

```bash
grep -n 'coach-zoom-settings\|scheduling-service' hl-core.php
```

Expected: the `coach-zoom-settings` line appears **before** the `scheduling-service` line.

- [ ] **Step 4: Verify class loads on test**

```bash
bash bin/deploy.sh test
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval "var_export(class_exists(\"HL_Coach_Zoom_Settings_Service\"));"'
```

Expected: `true`.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-hl-coach-zoom-settings-service.php hl-core.php
git commit -m "feat(coach-zoom): add HL_Coach_Zoom_Settings_Service skeleton"
```

---

## Section B — Service core: validate, resolve, save, get

Goal: Implement every method on `HL_Coach_Zoom_Settings_Service` except preflight (deferred to §C). After this section the service can read/write/resolve correctly with full audit logging.

> **Test harness convention:** This codebase has no PHPUnit setup. "Tests" in this plan are WP-CLI `wp eval-file` snippets stored under `bin/test-snippets/`, re-runnable, producing literal `PASS:` / `FAIL:` lines.

### Task B1: Test-harness directory

- [ ] **Step 1: Create the directory + placeholder**

```bash
mkdir -p bin/test-snippets
touch bin/test-snippets/.gitkeep
```

- [ ] **Step 2: Commit**

```bash
git add bin/test-snippets/.gitkeep
git commit -m "chore(coach-zoom): add test-snippets directory"
```

---

### Task B2: Implement `validate()` with structured WP_Error

**Files:**
- Modify: `includes/services/class-hl-coach-zoom-settings-service.php`
- Create: `bin/test-snippets/test-coach-zoom-validate.php`

- [ ] **Step 1: Write the test snippet**

`bin/test-snippets/test-coach-zoom-validate.php`:

```php
<?php
function _test_assert( $label, $condition ) {
    echo $condition ? "PASS: $label\n" : "FAIL: $label\n";
}

// 1. Bool coercion
$out = HL_Coach_Zoom_Settings_Service::validate( array( 'waiting_room' => 'yes' ), 0 );
_test_assert( 'bool coercion: truthy string -> 1', is_array( $out ) && $out['waiting_room'] === 1 );

$out = HL_Coach_Zoom_Settings_Service::validate( array( 'waiting_room' => '0' ), 0 );
_test_assert( 'bool coercion: "0" -> 0', is_array( $out ) && $out['waiting_room'] === 0 );

// 2. waiting_room + join_before_host conflict normalization
$out = HL_Coach_Zoom_Settings_Service::validate(
    array( 'waiting_room' => 1, 'join_before_host' => 1 ), 0
);
_test_assert( 'waiting_room=1 AND join_before_host=1 -> jbh=0',
    is_array( $out ) && $out['waiting_room'] === 1 && $out['join_before_host'] === 0 );

// 3. alt_hosts: invalid email rejected with structured error_data
$out = HL_Coach_Zoom_Settings_Service::validate(
    array( 'alternative_hosts' => 'good@example.com, not-an-email' ), 0
);
_test_assert( 'invalid alt_hosts -> WP_Error', is_wp_error( $out ) );
_test_assert( 'invalid alt_hosts -> error_data.field == alternative_hosts',
    is_wp_error( $out )
    && ($d = $out->get_error_data()) && isset( $d['field'] ) && $d['field'] === 'alternative_hosts' );
_test_assert( 'invalid alt_hosts -> error_data.invalid_emails listed',
    is_wp_error( $out )
    && ($d = $out->get_error_data()) && in_array( 'not-an-email', $d['invalid_emails'], true ) );

// 4. alt_hosts > 10 addresses rejected
$many = implode( ',', array_map( function( $i ) { return "u$i@e.com"; }, range( 1, 11 ) ) );
$out = HL_Coach_Zoom_Settings_Service::validate( array( 'alternative_hosts' => $many ), 0 );
_test_assert( 'alt_hosts > 10 -> WP_Error', is_wp_error( $out ) && $out->get_error_code() === 'too_many_alternative_hosts' );

// 5. alt_hosts > 1024 chars rejected
$long = str_repeat( 'a', 1020 ) . '@e.com';
$out = HL_Coach_Zoom_Settings_Service::validate( array( 'alternative_hosts' => $long ), 0 );
_test_assert( 'alt_hosts > 1024 chars -> WP_Error', is_wp_error( $out ) && $out->get_error_code() === 'alternative_hosts_too_long' );

// 6. Empty string preserved (distinct from NULL)
$out = HL_Coach_Zoom_Settings_Service::validate( array( 'alternative_hosts' => '' ), 0 );
_test_assert( 'empty alt_hosts preserved', is_array( $out ) && $out['alternative_hosts'] === '' );

echo "DONE\n";
```

- [ ] **Step 2: Implement `validate()` (REPLACES Task A3 stub)**

```php
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
```

- [ ] **Step 3: Deploy and run**

```bash
bash bin/deploy.sh test
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-validate.php'
```

Expected: every line begins `PASS:`. Any `FAIL:` blocks the commit.

- [ ] **Step 4: Manual verification — coach's own email rejected**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval "
    \$out = HL_Coach_Zoom_Settings_Service::validate( array( \"alternative_hosts\" => \"shernandez@housmanlearning.com\" ), 1508 );
    var_export( is_wp_error( \$out ) && \$out->get_error_code() === \"self_in_alternative_hosts\" );
  "'
```

Expected: `true` (1508 = Shannon Hernandez).

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-hl-coach-zoom-settings-service.php bin/test-snippets/test-coach-zoom-validate.php
git commit -m "feat(coach-zoom): implement validate() with structured WP_Error"
```

---

### Task B3: Implement admin defaults (`get_admin_defaults` + `save_admin_defaults`)

**Files:**
- Modify: `includes/services/class-hl-coach-zoom-settings-service.php`
- Create: `bin/test-snippets/test-coach-zoom-admin-defaults.php`

- [ ] **Step 1: Implement (REPLACES Task A3 stubs)**

```php
public static function get_admin_defaults() {
    $stored = get_option( self::OPTION_KEY, array() );
    if ( ! is_array( $stored ) ) {
        $stored = array();
    }
    return wp_parse_args( $stored, self::DEFAULTS );
}

public static function save_admin_defaults( array $values, $actor_user_id ) {
    $sanitized = self::validate( $values, 0 ); // coach_user_id=0 = no self-email check
    if ( is_wp_error( $sanitized ) ) {
        return $sanitized;
    }

    $before = self::get_admin_defaults();
    $after  = wp_parse_args( $sanitized, $before );

    update_option( self::OPTION_KEY, $after, true ); // autoload=yes

    // NOTE: HL_Audit_Service::log() hardcodes actor = get_current_user_id().
    // We do NOT pass actor_user_id in the data array (would be silently dropped).
    if ( class_exists( 'HL_Audit_Service' ) ) {
        $diff = array();
        foreach ( $after as $k => $v ) {
            if ( ! isset( $before[ $k ] ) || $before[ $k ] !== $v ) {
                $diff[ $k ] = array( 'before' => $before[ $k ] ?? null, 'after' => $v );
            }
        }
        HL_Audit_Service::log( 'coach_zoom_defaults_updated', array(
            'entity_type' => 'coach_zoom_defaults',
            'after_data'  => array( 'diff' => $diff ),
        ) );
    }

    return true;
}
```

- [ ] **Step 2: Test snippet**

`bin/test-snippets/test-coach-zoom-admin-defaults.php`:

```php
<?php
function _t( $l, $c ) { echo $c ? "PASS: $l\n" : "FAIL: $l\n"; }

delete_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY );

// 1. get without stored value returns DEFAULTS.
$d = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
_t( 'no option -> DEFAULTS', $d === HL_Coach_Zoom_Settings_Service::DEFAULTS );

// 2. save -> get round-trips.
$ok = HL_Coach_Zoom_Settings_Service::save_admin_defaults( array( 'mute_upon_entry' => 1 ), 1 );
_t( 'save returns true', $ok === true );

$d = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
_t( 'after save: mute_upon_entry=1', (int) $d['mute_upon_entry'] === 1 );
_t( 'after save: other defaults preserved', (int) $d['waiting_room'] === 1 );

// 3. invalid input rejected.
$err = HL_Coach_Zoom_Settings_Service::save_admin_defaults( array( 'alternative_hosts' => 'not-an-email' ), 1 );
_t( 'invalid save -> WP_Error', is_wp_error( $err ) );

// 4. wp_parse_args merges new keys (forward compat).
update_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY, array( 'waiting_room' => 0 ) );
$d = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
_t( 'partial stored merges with DEFAULTS', $d['waiting_room'] === 0 && $d['mute_upon_entry'] === 0 );

delete_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY );
echo "DONE\n";
```

- [ ] **Step 3: Deploy and run**

```bash
bash bin/deploy.sh test
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-admin-defaults.php'
```

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-coach-zoom-settings-service.php bin/test-snippets/test-coach-zoom-admin-defaults.php
git commit -m "feat(coach-zoom): admin defaults get/save with audit log"
```

---

### Task B4: Implement `get_coach_overrides()` with defensive table-missing fallback

**Files:**
- Modify: `includes/services/class-hl-coach-zoom-settings-service.php`
- Create: `bin/test-snippets/test-coach-zoom-get-overrides.php`

- [ ] **Step 1: Implement (REPLACES Task A3 stub)**

```php
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
```

- [ ] **Step 2: Test snippet** (`bin/test-snippets/test-coach-zoom-get-overrides.php`)

```php
<?php
function _t( $l, $c ) { echo $c ? "PASS: $l\n" : "FAIL: $l\n"; }

global $wpdb;
$table = $wpdb->prefix . 'hl_coach_zoom_settings';
$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );

// 1. No row -> empty array.
$o = HL_Coach_Zoom_Settings_Service::get_coach_overrides( 999999 );
_t( 'no row -> []', $o === array() );

// 2. Insert sparse row.
$wpdb->insert( $table, array(
    'coach_user_id'      => 999999,
    'mute_upon_entry'    => 1,
    'updated_by_user_id' => 1,
), array( '%d', '%d', '%d' ) );

$o = HL_Coach_Zoom_Settings_Service::get_coach_overrides( 999999 );
_t( 'sparse row: mute=1', isset( $o['mute_upon_entry'] ) && $o['mute_upon_entry'] === 1 );
_t( 'sparse row: waiting_room not set', ! isset( $o['waiting_room'] ) );
_t( 'sparse row: alt_hosts not set (NULL)', ! isset( $o['alternative_hosts'] ) );
_t( 'sparse row: _meta.updated_by_user_id=1', isset( $o['_meta']['updated_by_user_id'] ) && $o['_meta']['updated_by_user_id'] === 1 );

// 3. Empty-string alt_hosts preserved (distinct from NULL).
$wpdb->update( $table, array( 'alternative_hosts' => '' ), array( 'coach_user_id' => 999999 ) );
$o = HL_Coach_Zoom_Settings_Service::get_coach_overrides( 999999 );
_t( 'empty-string alt_hosts preserved', isset( $o['alternative_hosts'] ) && $o['alternative_hosts'] === '' );

$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );
echo "DONE\n";
```

- [ ] **Step 3: Deploy and run**

```bash
bash bin/deploy.sh test
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-get-overrides.php'
```

- [ ] **Step 4: Verify defensive fallback (manual)**

> ⚠️ **TEST SERVER ONLY — NEVER RUN ON PROD.** This DROPs the table.

```bash
# TEST SERVER ONLY
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress && \
  wp db query "DROP TABLE wp_hl_coach_zoom_settings" && \
  wp eval "var_export( HL_Coach_Zoom_Settings_Service::get_coach_overrides( 1508 ) );" && \
  wp eval "HL_Installer::maybe_upgrade();" && \
  wp eval "var_export( HL_Coach_Zoom_Settings_Service::get_coach_overrides( 1508 ) );"'
```

Expected: first var_export is `array()` (defensive fallback hit), second is also `array()` (table re-created via dbDelta, no row). Both must NOT fatal.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-hl-coach-zoom-settings-service.php bin/test-snippets/test-coach-zoom-get-overrides.php
git commit -m "feat(coach-zoom): get_coach_overrides() with table-missing fallback"
```

---

### Task B5: Implement `save_coach_overrides()` with `START TRANSACTION` audit-diff race fix

**Files:**
- Modify: `includes/services/class-hl-coach-zoom-settings-service.php`
- Create: `bin/test-snippets/test-coach-zoom-save-overrides.php`

- [ ] **Step 1: Implement (REPLACES Task A3 stub)**

Uses `$wpdb->insert` / `$wpdb->update` (both natively bind NULL when value is null) instead of raw `INSERT … ON DUPLICATE KEY UPDATE` (which has subtle NULL semantics through `prepare()`). Accepts a `$reset_fields` array (used by §H AJAX flow) to NULL specific columns inside the same transaction.

```php
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
        $wpdb->prepare( "SELECT waiting_room, mute_upon_entry, join_before_host, alternative_hosts FROM {$table} WHERE coach_user_id = %d FOR UPDATE", $coach_user_id ),
        ARRAY_A
    );
    $row_exists = ( $before_row !== null );

    // Apply resets first (raw SQL for unambiguous NULL binding).
    foreach ( $reset_fields as $f ) {
        if ( ! in_array( $f, $allowed_cols, true ) ) continue;
        if ( ! $row_exists ) continue; // nothing to reset
        $r = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET {$f} = NULL WHERE coach_user_id = %d",
            $coach_user_id
        ) );
        if ( $r === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_write_failed', $wpdb->last_error ?: __( 'Reset failed.', 'hl-core' ) );
        }
        $before_row[ $f ] = null; // reflect in our local copy for diff calc
    }

    // Compute new row.
    $col = function( $field ) use ( $sanitized, $before_row, $row_exists ) {
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
            $result = $wpdb->update( $table, $new_row, array( 'coach_user_id' => $coach_user_id ), null, array( '%d' ) );
        } else {
            $insert = array_merge( array( 'coach_user_id' => $coach_user_id ), $new_row );
            $result = $wpdb->insert( $table, $insert );
        }
        if ( $result === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_write_failed', $wpdb->last_error ?: __( 'Failed to save coach Zoom settings.', 'hl-core' ) );
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
```

- [ ] **Step 2: Test snippet** (`bin/test-snippets/test-coach-zoom-save-overrides.php`)

```php
<?php
function _t( $l, $c ) { echo $c ? "PASS: $l\n" : "FAIL: $l\n"; }

global $wpdb;
$table = $wpdb->prefix . 'hl_coach_zoom_settings';

// Cleanup at START to prevent cross-run pollution.
$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE entity_id = %d AND action_type = %s", 999999, 'coach_zoom_settings_updated' ) );

// 1. INSERT path with sparse override.
$ok = HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'mute_upon_entry' => 1 ), 1 );
_t( 'INSERT returns true', $ok === true );

$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE coach_user_id = 999999", ARRAY_A );
_t( 'INSERT: mute_upon_entry=1', (int) $row['mute_upon_entry'] === 1 );
_t( 'INSERT: waiting_room NULL', $row['waiting_room'] === null );
_t( 'INSERT: alternative_hosts NULL', $row['alternative_hosts'] === null );
_t( 'INSERT: updated_by_user_id=1', (int) $row['updated_by_user_id'] === 1 );

// 2. UPDATE path: change actor.
$ok = HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'waiting_room' => 0 ), 2 );
_t( 'UPDATE returns true', $ok === true );

$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE coach_user_id = 999999", ARRAY_A );
_t( 'UPDATE: waiting_room=0', (int) $row['waiting_room'] === 0 );
_t( 'UPDATE: mute_upon_entry preserved (still 1)', (int) $row['mute_upon_entry'] === 1 );
_t( 'UPDATE: updated_by_user_id=2', (int) $row['updated_by_user_id'] === 2 );

// 3. Empty-string alt_hosts override.
$ok = HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'alternative_hosts' => '' ), 2 );
_t( 'empty-string alt_hosts saves', $ok === true );

$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE coach_user_id = 999999", ARRAY_A );
_t( 'empty-string alt_hosts persisted (not NULL)', $row['alternative_hosts'] === '' );

// 4. Reset path: NULL waiting_room via $reset_fields.
$ok = HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array(), 2, array( 'waiting_room' ) );
_t( 'reset returns true', $ok === true );

$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE coach_user_id = 999999", ARRAY_A );
_t( 'reset: waiting_room is NULL', $row['waiting_room'] === null );
_t( 'reset: mute_upon_entry preserved', (int) $row['mute_upon_entry'] === 1 );

// 5. Audit log row(s) exist.
$audit = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}hl_audit_log WHERE action_type = %s AND entity_id = %d",
    'coach_zoom_settings_updated', 999999
) );
_t( 'audit log rows written', (int) $audit >= 3 );

// 6. Invalid input -> WP_Error, no DB mutation to last good state.
$err = HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'alternative_hosts' => 'bad-email' ), 1 );
_t( 'invalid -> WP_Error', is_wp_error( $err ) );

// Cleanup.
$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE entity_id = %d AND action_type = %s", 999999, 'coach_zoom_settings_updated' ) );
echo "DONE\n";
```

- [ ] **Step 3: Deploy and run**

```bash
bash bin/deploy.sh test
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-save-overrides.php'
```

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-coach-zoom-settings-service.php bin/test-snippets/test-coach-zoom-save-overrides.php
git commit -m "feat(coach-zoom): save_coach_overrides() with transactional audit diff + reset"
```

---

### Task B6: Implement `resolve_for_coach()` with 3-tier fallback

**Files:**
- Modify: `includes/services/class-hl-coach-zoom-settings-service.php`
- Create: `bin/test-snippets/test-coach-zoom-resolve.php`

- [ ] **Step 1: Implement (REPLACES Task A3 stub)**

```php
public static function resolve_for_coach( $coach_user_id ) {
    $defaults  = self::get_admin_defaults();
    $overrides = self::get_coach_overrides( $coach_user_id );

    // Strip metadata before merging (used by admin overview, not by Zoom payload).
    unset( $overrides['_meta'] );

    // Coach override (non-NULL) wins. Admin-only keys (password_required, meeting_authentication)
    // are never present in $overrides and flow through from $defaults unchanged — by design.
    return array_merge( $defaults, $overrides );
}
```

- [ ] **Step 2: Test snippet** (`bin/test-snippets/test-coach-zoom-resolve.php`)

```php
<?php
function _t( $l, $c ) { echo $c ? "PASS: $l\n" : "FAIL: $l\n"; }

global $wpdb;
$table = $wpdb->prefix . 'hl_coach_zoom_settings';
delete_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY );
$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );

// 1. No option, no override -> DEFAULTS.
$r = HL_Coach_Zoom_Settings_Service::resolve_for_coach( 999999 );
_t( 'no option, no row -> DEFAULTS', $r['waiting_room'] === 1 && $r['alternative_hosts'] === '' );

// 2. Admin default set.
HL_Coach_Zoom_Settings_Service::save_admin_defaults( array( 'mute_upon_entry' => 1, 'alternative_hosts' => 'a@b.com' ), 1 );
$r = HL_Coach_Zoom_Settings_Service::resolve_for_coach( 999999 );
_t( 'admin default applied', $r['mute_upon_entry'] === 1 && $r['alternative_hosts'] === 'a@b.com' );

// 3. Coach override (sparse).
HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'waiting_room' => 0 ), 1 );
$r = HL_Coach_Zoom_Settings_Service::resolve_for_coach( 999999 );
_t( 'override wins on overridden field', $r['waiting_room'] === 0 );
_t( 'admin default preserved on non-overridden', $r['mute_upon_entry'] === 1 );

// 4. Empty-string alt_hosts override wins over admin's non-empty.
HL_Coach_Zoom_Settings_Service::save_coach_overrides( 999999, array( 'alternative_hosts' => '' ), 1 );
$r = HL_Coach_Zoom_Settings_Service::resolve_for_coach( 999999 );
_t( 'empty-string alt_hosts override wins', $r['alternative_hosts'] === '' );

// Cleanup.
delete_option( HL_Coach_Zoom_Settings_Service::OPTION_KEY );
$wpdb->query( "DELETE FROM {$table} WHERE coach_user_id = 999999" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE entity_id = %d", 999999 ) );
echo "DONE\n";
```

- [ ] **Step 3: Deploy and run**

```bash
bash bin/deploy.sh test
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-resolve.php'
```

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-coach-zoom-settings-service.php bin/test-snippets/test-coach-zoom-resolve.php
git commit -m "feat(coach-zoom): resolve_for_coach() 3-tier fallback"
```

---

## Section C — Preflight + delete_user cleanup

Goal: Add `preflight_alternative_hosts()` (mandatory hard-reject when alt_hosts non-empty, with debounce + transient inflight rate-limit lock) and the `delete_user` cleanup hook.

### Task C1: Implement `preflight_alternative_hosts()`

**Files:**
- Modify: `includes/services/class-hl-coach-zoom-settings-service.php`
- Create: `bin/test-snippets/test-coach-zoom-preflight.php`

- [ ] **Step 1: Implement (REPLACES Task A3 stub)**

```php
public static function preflight_alternative_hosts( $coach_user_id, $alternative_hosts_csv ) {
    if ( empty( $alternative_hosts_csv ) ) {
        return true; // nothing to verify
    }
    if ( ! class_exists( 'HL_Zoom_Integration' ) ) {
        return true; // integration absent — best-effort
    }

    $zoom = HL_Zoom_Integration::instance();
    if ( ! $zoom->is_configured() ) {
        return true; // no Zoom credentials — best-effort
    }

    // Debounce: same coach + same value within 60s -> skip.
    $debounce_key = 'hl_zoom_alt_preflight_' . absint( $coach_user_id ) . '_' . md5( $alternative_hosts_csv );
    if ( get_transient( $debounce_key ) ) {
        return true;
    }

    // Inflight lock: prevent two concurrent saves from both calling Zoom.
    // Transient (NOT wp_cache_*) for cross-process persistence; 60s TTL exceeds Zoom timeout × retry.
    $inflight_key = 'hl_zoom_inflight_' . absint( $coach_user_id );
    if ( get_transient( $inflight_key ) ) {
        return new WP_Error(
            'preflight_inflight',
            __( 'Verifying with Zoom — try again in a moment.', 'hl-core' ),
            array( 'field' => 'alternative_hosts' )
        );
    }
    set_transient( $inflight_key, 1, 60 );

    try {
        $coach_email = $zoom->get_coach_email( $coach_user_id );
        if ( empty( $coach_email ) ) {
            return true;
        }

        // Minimal payload + the proposed alt_hosts. start_time is +1h; meeting deleted immediately.
        $start = gmdate( 'Y-m-d\TH:i:s', time() + 3600 );
        $payload = array(
            'topic'      => '[HL Preflight] alt_hosts validation',
            'type'       => 2,
            'start_time' => $start,
            'timezone'   => 'UTC',
            'duration'   => 5,
            'settings'   => array(
                'waiting_room'      => true,
                'alternative_hosts' => $alternative_hosts_csv,
            ),
        );

        $created = $zoom->create_meeting( $coach_email, $payload );
        if ( is_wp_error( $created ) ) {
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'coach_zoom_preflight_failed', array(
                    'entity_type' => 'coach_zoom_settings',
                    'entity_id'   => $coach_user_id,
                    'after_data'  => array(
                        'alternative_hosts' => $alternative_hosts_csv,
                        'zoom_error'        => $created->get_error_message(),
                        'zoom_code'         => $created->get_error_code(),
                    ),
                ) );
            }
            return new WP_Error(
                'preflight_failed',
                sprintf(
                    /* translators: %s = Zoom error message */
                    __( 'Zoom rejected one or more alternative hosts: %s', 'hl-core' ),
                    $created->get_error_message()
                ),
                array( 'field' => 'alternative_hosts' )
            );
        }

        // Mark debounce hit only on successful preflight.
        set_transient( $debounce_key, 1, 60 );

        // Cleanup the test meeting. Failure is logged but does not fail the save.
        if ( ! empty( $created['id'] ) ) {
            $delete_result = $zoom->delete_meeting( $created['id'] );
            if ( is_wp_error( $delete_result ) && class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'zoom_api_error', array(
                    'entity_type' => 'integration',
                    'after_data'  => array(
                        'context'    => 'preflight_cleanup',
                        'meeting_id' => $created['id'],
                        'error'      => $delete_result->get_error_message(),
                    ),
                ) );
            }
        }

        return true;
    } finally {
        delete_transient( $inflight_key );
    }
}
```

> **Frontend latency contract (for §H):** This call blocks for up to ~30s in the worst case (Zoom client timeout 15s × single retry on 401). The AJAX caller MUST show a *"Verifying with Zoom…"* spinner and disable the save button.

- [ ] **Step 2: Test snippet** (`bin/test-snippets/test-coach-zoom-preflight.php`)

```php
<?php
function _t( $l, $c ) { echo $c ? "PASS: $l\n" : "FAIL: $l\n"; }

delete_transient( 'hl_zoom_inflight_999999' );

// 1. Empty CSV -> immediate true (no Zoom call).
$r = HL_Coach_Zoom_Settings_Service::preflight_alternative_hosts( 999999, '' );
_t( 'empty CSV -> true', $r === true );

// 2. Inflight lock blocks concurrent calls.
set_transient( 'hl_zoom_inflight_999999', 1, 60 );
$r = HL_Coach_Zoom_Settings_Service::preflight_alternative_hosts( 999999, 'someone@example.com' );
_t( 'inflight lock -> WP_Error preflight_inflight',
    is_wp_error( $r ) && $r->get_error_code() === 'preflight_inflight' );
delete_transient( 'hl_zoom_inflight_999999' );

// 3. Debounce: pre-set the debounce transient and confirm we skip.
$csv = 'clove@housmanlearning.com';
set_transient( 'hl_zoom_alt_preflight_999999_' . md5( $csv ), 1, 60 );
$r = HL_Coach_Zoom_Settings_Service::preflight_alternative_hosts( 999999, $csv );
_t( 'debounce hit -> true (skip Zoom)', $r === true );
delete_transient( 'hl_zoom_alt_preflight_999999_' . md5( $csv ) );

echo "DONE\n";
```

- [ ] **Step 3: Deploy and run**

```bash
bash bin/deploy.sh test
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-preflight.php'
```

- [ ] **Step 4: Live preflight smoke (manual; ONE Zoom API call)**

> ⚠️ Makes a real Zoom create+delete API call. Only run when Zoom credentials are configured on test.

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval "
    delete_transient( \"hl_zoom_alt_preflight_1508_\" . md5( \"clove@housmanlearning.com\" ) );
    \$r = HL_Coach_Zoom_Settings_Service::preflight_alternative_hosts( 1508, \"clove@housmanlearning.com\" );
    var_export( \$r );
  "'
```

Expected: `true`. Then verify the inflight lock self-released:

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval "var_export( get_transient( \"hl_zoom_inflight_1508\" ) );"'
```

Expected: `false`.

- [ ] **Step 5: Add admin-defaults preflight wiring**

In `save_admin_defaults()` (Task B3), immediately after `validate()` succeeds and before `update_option()`:

```php
if ( ! empty( $sanitized['alternative_hosts'] ) ) {
    // coach_user_id=0 for the admin path: no specific coach to "self-host"-check against.
    $pf = self::preflight_alternative_hosts( 0, $sanitized['alternative_hosts'] );
    if ( is_wp_error( $pf ) ) {
        return $pf;
    }
}
```

> **§F admin UX note:** `preflight_inflight` user-message is coach-flow copy. The admin form (§F Task F1) should swap the string to *"Another administrator is currently saving these settings. Please retry in a moment."* in the admin context.

(`save_coach_overrides()` already wires preflight per Task B5.)

- [ ] **Step 6: Commit**

```bash
git add includes/services/class-hl-coach-zoom-settings-service.php bin/test-snippets/test-coach-zoom-preflight.php
git commit -m "feat(coach-zoom): preflight alt_hosts with debounce + inflight transient lock"
```

---

### Task C2: `delete_user` hook for row + actor cleanup

**Files:**
- Modify: `hl-core.php` — register the hook inside `HL_Core::register_hooks()`.
- Create: `bin/test-snippets/test-coach-zoom-delete-user.php`

- [ ] **Step 1: Add the hook inside `HL_Core::register_hooks()`**

Hooks live inside `HL_Core::register_hooks()` (NOT at the top level of `hl-core.php`). Insert in the user-lifecycle cluster around line 355, after the `remove_user_role` line:

```php
add_action( 'delete_user', function( $user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_coach_zoom_settings';

    // Drop any settings row keyed on the deleted user.
    $wpdb->delete( $table, array( 'coach_user_id' => (int) $user_id ), array( '%d' ) );

    // NULL the actor reference where the deleted user was the editor.
    // Use raw query for unambiguous NULL binding.
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET updated_by_user_id = NULL WHERE updated_by_user_id = %d",
        (int) $user_id
    ) );
}, 10, 1 );
```

- [ ] **Step 2: Test snippet** (`bin/test-snippets/test-coach-zoom-delete-user.php`)

```php
<?php
function _t( $l, $c ) { echo $c ? "PASS: $l\n" : "FAIL: $l\n"; }

global $wpdb;
$table = $wpdb->prefix . 'hl_coach_zoom_settings';

// Setup throwaway users — wp_insert_user REQUIRES user_email.
$ts = time();
$victim_id = wp_insert_user( array(
    'user_login' => 'czs_victim_' . $ts,
    'user_email' => "czs+victim_{$ts}@example.test",
    'user_pass'  => wp_generate_password(),
) );
$actor_id = wp_insert_user( array(
    'user_login' => 'czs_actor_' . $ts,
    'user_email' => "czs+actor_{$ts}@example.test",
    'user_pass'  => wp_generate_password(),
) );
$other_coach = wp_insert_user( array(
    'user_login' => 'czs_other_' . $ts,
    'user_email' => "czs+other_{$ts}@example.test",
    'user_pass'  => wp_generate_password(),
) );

if ( is_wp_error( $victim_id ) || is_wp_error( $actor_id ) || is_wp_error( $other_coach ) ) {
    echo "FAIL: setup users\n"; exit;
}

$wpdb->insert( $table, array( 'coach_user_id' => $victim_id, 'mute_upon_entry' => 1, 'updated_by_user_id' => $actor_id ), array( '%d', '%d', '%d' ) );
$wpdb->insert( $table, array( 'coach_user_id' => $other_coach, 'waiting_room' => 0, 'updated_by_user_id' => $actor_id ), array( '%d', '%d', '%d' ) );

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $victim_id );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE coach_user_id = %d", $victim_id ) );
_t( 'victim row deleted by hook', $row === null );

wp_delete_user( $actor_id );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE coach_user_id = %d", $other_coach ), ARRAY_A );
_t( 'other_coach row preserved', $row !== null );
_t( 'other_coach updated_by_user_id NULLed', $row['updated_by_user_id'] === null );

// Cleanup.
$wpdb->delete( $table, array( 'coach_user_id' => $other_coach ), array( '%d' ) );
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}hl_audit_log WHERE entity_id IN (%d, %d, %d) AND action_type = 'coach_zoom_settings_updated'",
    $victim_id, $actor_id, $other_coach
) );
wp_delete_user( $other_coach );
echo "DONE\n";
```

- [ ] **Step 3: Deploy and run**

```bash
bash bin/deploy.sh test
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-delete-user.php'
```

- [ ] **Step 4: Commit**

```bash
git add hl-core.php bin/test-snippets/test-coach-zoom-delete-user.php
git commit -m "feat(coach-zoom): delete_user hook cleans up settings rows + actor refs"
```

---

## Section D — Zoom integration + Scheduling Service patches + mentor email fallback

### Task D1: `build_meeting_payload()` 2-arg signature with boot-safe fallback

**File:** `includes/integrations/class-hl-zoom-integration.php` (currently lines 178–191).

- [ ] **Step 1: Replace the method body**

```php
public function build_meeting_payload( $session_data, array $resolved_settings = array() ) {
    if ( empty( $resolved_settings ) ) {
        // Boot-safe fallback (handles early callers / tests that don't pass the arg).
        if ( class_exists( 'HL_Coach_Zoom_Settings_Service' ) ) {
            $resolved_settings = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
        } else {
            $resolved_settings = array(
                'waiting_room' => 1, 'mute_upon_entry' => 0, 'join_before_host' => 0,
                'alternative_hosts' => '', 'password_required' => 0, 'meeting_authentication' => 0,
            );
        }
    }

    $payload = array(
        'topic'      => sprintf( 'Coaching Session - %s/%s', $session_data['mentor_name'], $session_data['coach_name'] ),
        'type'       => 2,
        'start_time' => $session_data['start_datetime'],
        'timezone'   => $session_data['timezone'],
        'duration'   => isset( $session_data['duration'] ) ? (int) $session_data['duration'] : 30,
        'settings'   => array(
            'waiting_room'           => (bool) $resolved_settings['waiting_room'],
            'join_before_host'       => (bool) $resolved_settings['join_before_host'], // already normalized by validate()
            'mute_upon_entry'        => (bool) $resolved_settings['mute_upon_entry'],
            'meeting_authentication' => (bool) $resolved_settings['meeting_authentication'],
        ),
    );

    if ( isset( $resolved_settings['alternative_hosts'] ) && $resolved_settings['alternative_hosts'] !== '' ) {
        $payload['settings']['alternative_hosts'] = $resolved_settings['alternative_hosts'];
    }

    // password key omitted in BOTH cases:
    //   password_required=1 → omit so Zoom auto-generates one.
    //   password_required=0 → omit; account-level passcode policy may still apply.

    return $payload;
}
```

- [ ] **Step 2: Test snippet** (`bin/test-snippets/test-coach-zoom-build-payload.php`)

```php
<?php
function _t( $l, $c ) { echo $c ? "PASS: $l\n" : "FAIL: $l\n"; }

$session = array(
    'mentor_name' => 'Mentor X', 'coach_name' => 'Coach Y',
    'start_datetime' => '2026-05-01T10:00:00', 'timezone' => 'America/New_York', 'duration' => 30,
);

// 1. No second arg → falls back to admin defaults (or literal constants).
$p = HL_Zoom_Integration::instance()->build_meeting_payload( $session );
_t( 'no-arg fallback returns array', is_array( $p ) && isset( $p['settings'] ) );
_t( 'topic populated', $p['topic'] === 'Coaching Session - Mentor X/Coach Y' );
_t( 'no alternative_hosts when empty', ! isset( $p['settings']['alternative_hosts'] ) );
_t( 'no password key in payload', ! array_key_exists( 'password', $p ) );

// 1b. alt_hosts=null is also handled.
$p_null = HL_Zoom_Integration::instance()->build_meeting_payload( $session, array(
    'waiting_room' => 1, 'mute_upon_entry' => 0, 'join_before_host' => 0,
    'alternative_hosts' => null, 'password_required' => 0, 'meeting_authentication' => 0,
) );
_t( 'alt_hosts=null also omits the key', ! isset( $p_null['settings']['alternative_hosts'] ) );

// 1c. Regression vs legacy hard-coded payload.
_t( 'auto_recording removed from payload', ! isset( $p['settings']['auto_recording'] ) );
_t( 'mute_upon_entry key added', array_key_exists( 'mute_upon_entry', $p['settings'] ) );
_t( 'meeting_authentication key added', array_key_exists( 'meeting_authentication', $p['settings'] ) );

// 2. Resolved settings flow through.
$resolved = array(
    'waiting_room' => 0, 'mute_upon_entry' => 1, 'join_before_host' => 1,
    'alternative_hosts' => 'a@b.com', 'password_required' => 1, 'meeting_authentication' => 1,
);
$p = HL_Zoom_Integration::instance()->build_meeting_payload( $session, $resolved );
_t( 'waiting_room=false flows', $p['settings']['waiting_room'] === false );
_t( 'mute_upon_entry=true flows', $p['settings']['mute_upon_entry'] === true );
_t( 'alternative_hosts populated', $p['settings']['alternative_hosts'] === 'a@b.com' );
_t( 'password key still omitted', ! array_key_exists( 'password', $p ) );

echo "DONE\n";
```

- [ ] **Step 3: Deploy and run**

```bash
bash bin/deploy.sh test
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-build-payload.php'
```

- [ ] **Step 4: Commit**

```bash
git add includes/integrations/class-hl-zoom-integration.php bin/test-snippets/test-coach-zoom-build-payload.php
git commit -m "feat(coach-zoom): build_meeting_payload() 2-arg with boot-safe fallback"
```

---

### Task D2: Patch `book_session()` to resolve + pass coach settings

**File:** `includes/services/class-hl-scheduling-service.php` (around line 386).

- [ ] **Step 1: Insert resolution BEFORE the `is_configured()` block**

Modify the Zoom block in `book_session()` so it reads:

```php
// Resolve coach Zoom settings ONCE, OUTSIDE the is_configured() guard.
// Resolution is cheap; keeping it here means a future reviewer cannot accidentally
// null it out by moving both lines inside the guard.
$resolved_zoom_settings = HL_Coach_Zoom_Settings_Service::resolve_for_coach( $coach_user_id );

// Step 2: Create Zoom meeting.
$zoom = HL_Zoom_Integration::instance();
if ( $zoom->is_configured() ) {
    $zoom_email   = $zoom->get_coach_email( $coach_user_id );
    $zoom_payload = $zoom->build_meeting_payload( $api_data, $resolved_zoom_settings );
    $zoom_result  = $zoom->create_meeting( $zoom_email, $zoom_payload );
    // ... (rest of block unchanged)
}
```

- [ ] **Step 2: Verify by booking a real session**

Manual (depends on Task B3 having shipped):
1. Set admin defaults: `wp eval 'HL_Coach_Zoom_Settings_Service::save_admin_defaults( array( "waiting_room" => 0 ), 1 );'`
2. Book a session via the mentor UI on test.
3. Inspect via API: `wp eval 'var_export( $wpdb->get_var( "SELECT zoom_meeting_id FROM wp_hl_coaching_session ORDER BY session_id DESC LIMIT 1" ) );'` then ask Chris to verify in Zoom UI.
4. Reset: `wp eval 'delete_option( "hl_zoom_coaching_defaults" );'`

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-scheduling-service.php
git commit -m "feat(coach-zoom): book_session() resolves + passes coach Zoom settings"
```

---

### Task D3: Patch `reschedule_session_with_integrations()` (same shape)

**File:** `includes/services/class-hl-scheduling-service.php` (around line 591).

- [ ] **Step 1: Insert the same resolve + pass pattern**

```php
// Resolve once for the reschedule's new meeting.
$resolved_zoom_settings = HL_Coach_Zoom_Settings_Service::resolve_for_coach( $old_session['coach_user_id'] );

$meeting_url     = null;
$zoom_meeting_id = null;
$zoom            = HL_Zoom_Integration::instance();
if ( $zoom->is_configured() ) {
    $zoom_email   = $zoom->get_coach_email( $old_session['coach_user_id'] );
    $zoom_payload = $zoom->build_meeting_payload( $api_data, $resolved_zoom_settings );
    $zoom_result  = $zoom->create_meeting( $zoom_email, $zoom_payload );
    // ... (rest unchanged)
}
```

- [ ] **Step 2: Verify by rescheduling a test session**

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-scheduling-service.php
git commit -m "feat(coach-zoom): reschedule_session_with_integrations() passes coach settings"
```

---

### Task D4: Mentor email — *"link coming shortly"* fallback in `build_branded_body()`

**File:** `includes/services/class-hl-scheduling-email-service.php` lines 412-417.

The link block is in the SHARED `build_branded_body()` helper, NOT in `send_session_booked()` directly. That helper is also called by `send_session_rescheduled()` so the fix benefits both flows.

- [ ] **Step 1: Add an `else` branch on the existing `if (!empty($meeting_url))`**

Replace lines 412-417 with:

```php
        // Zoom button (or fallback when meeting_url is empty).
        if (!empty($meeting_url)) {
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:24px 0;"><tr><td align="center">';
            $html .= '<a href="' . esc_url($meeting_url) . '" style="display:inline-block;background:#2d8cff;color:#FFFFFF;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;">Join Zoom Meeting</a>';
            $html .= '</td></tr></table>';
        } else {
            // Zoom create failed (or skipped). Generic copy because the reschedule
            // path does NOT call send_zoom_fallback() today (pre-existing gap).
            $html .= '<p style="margin:24px 0;padding:12px 16px;background:#fff7ed;border-left:4px solid #f97316;border-radius:4px;font-size:14px;color:#9a3412;">'
                . esc_html__( 'Your Zoom meeting link will be sent shortly. We\'ll be in touch.', 'hl-core' )
                . '</p>';
        }
```

> **Known gap (NOT fixed in this ticket):** `reschedule_session_with_integrations()` does NOT call `HL_Scheduling_Email_Service::send_zoom_fallback()` on Zoom failure (silent fail at line 605). Pre-existing; file a follow-up bug; do NOT scope-creep this PR.

- [ ] **Step 2: Smoke test (booking path)**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress && wp eval "
HL_Scheduling_Email_Service::instance()->send_session_booked( array(
    \"mentor_name\" => \"Test Mentor\",
    \"mentor_email\" => \"mateo@corsox.com\",
    \"mentor_timezone\" => \"America/New_York\",
    \"coach_name\" => \"Test Coach\",
    \"coach_email\" => \"mateo@corsox.com\",
    \"coach_timezone\" => \"America/New_York\",
    \"session_datetime\" => \"2026-05-01 10:00:00\",
    \"meeting_url\" => \"\",
) );
"'
```

Check inbox: both copies (mentor + coach) should show the fallback message.

- [ ] **Step 3: Smoke test (reschedule path) — same `build_branded_body()` helper**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress && wp eval "
HL_Scheduling_Email_Service::instance()->send_session_rescheduled(
    array( \"session_datetime\" => \"2026-05-01 10:00:00\" ),
    array(
        \"mentor_name\" => \"Test Mentor\", \"mentor_email\" => \"mateo@corsox.com\",
        \"mentor_timezone\" => \"America/New_York\", \"coach_name\" => \"Test Coach\",
        \"coach_email\" => \"mateo@corsox.com\", \"coach_timezone\" => \"America/New_York\",
        \"session_datetime\" => \"2026-05-08 10:00:00\", \"meeting_url\" => \"\",
    )
);
"'
```

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-scheduling-email-service.php
git commit -m "feat(coach-zoom): branded email shows 'link coming shortly' when meeting_url empty"
```

---

## Section E — Retry Zoom creation (admin-only, idempotent)

Goal: Add a "Retry Zoom creation" admin endpoint with: per-session transient lock, atomic `WHERE zoom_meeting_id IS NULL` UPDATE, race-loss orphan cleanup, Outlook event update, and a new `send_zoom_link_ready()` follow-up email.

### Task E1: `retry_zoom_meeting()` method on `HL_Scheduling_Service`

**File:** `includes/services/class-hl-scheduling-service.php`.

- [ ] **Step 1: Add the method** (place after `cancel_session_with_integrations()`)

```php
/**
 * Retry Zoom meeting creation for a session whose initial Zoom create failed.
 * Idempotent: per-session transient lock + atomic WHERE zoom_meeting_id IS NULL.
 *
 * @param int $session_id
 * @return array|WP_Error { meeting_id, meeting_url } on success.
 */
public function retry_zoom_meeting( $session_id ) {
    global $wpdb;
    $session_id = absint( $session_id );
    if ( ! $session_id ) {
        return new WP_Error( 'invalid_session', __( 'Invalid session ID.', 'hl-core' ) );
    }

    if ( ! current_user_can( 'manage_hl_core' ) ) {
        return new WP_Error( 'permission_denied', __( 'Only administrators can retry Zoom creation.', 'hl-core' ) );
    }

    // Load session BEFORE acquiring the lock so early-return cases don't leak a transient.
    $coaching_service = new HL_Coaching_Service();
    $session          = $coaching_service->get_session( $session_id );
    if ( ! $session ) {
        return new WP_Error( 'not_found', __( 'Session not found.', 'hl-core' ) );
    }
    if ( ! empty( $session['zoom_meeting_id'] ) ) {
        return new WP_Error( 'already_has_meeting', __( 'This session already has a Zoom meeting.', 'hl-core' ) );
    }

    $lock_key = 'hl_zoom_retry_lock_' . $session_id;
    if ( get_transient( $lock_key ) ) {
        return new WP_Error( 'retry_inflight', __( 'Retry already in progress for this session.', 'hl-core' ) );
    }
    set_transient( $lock_key, 1, 60 );

    try {
        $resolved = HL_Coach_Zoom_Settings_Service::resolve_for_coach( $session['coach_user_id'] );

        $coach_user   = get_userdata( $session['coach_user_id'] );
        $mentor_email = $wpdb->get_var( $wpdb->prepare(
            "SELECT u.user_email FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.enrollment_id = %d",
            $session['mentor_enrollment_id']
        ) );

        $duration = (int) HL_Admin_Scheduling_Settings::get_scheduling_settings()['session_duration'];
        $coach_tz = $session['coach_timezone'] ?? wp_timezone_string();

        try {
            $start_dt = new DateTime( $session['session_datetime'], wp_timezone() );
            $start_dt->setTimezone( new DateTimeZone( $coach_tz ) );
            $end_dt   = clone $start_dt;
            $end_dt->modify( '+' . $duration . ' minutes' );
        } catch ( Exception $e ) {
            return new WP_Error( 'invalid_datetime', __( 'Invalid stored session datetime.', 'hl-core' ) );
        }

        $api_data = array(
            'mentor_name'    => $session['mentor_name'],
            'mentor_email'   => $mentor_email,
            'coach_name'     => $coach_user ? $coach_user->display_name : '',
            'start_datetime' => $start_dt->format( 'Y-m-d\TH:i:s' ),
            'end_datetime'   => $end_dt->format( 'Y-m-d\TH:i:s' ),
            'timezone'       => $coach_tz,
            'duration'       => $duration,
        );

        $zoom = HL_Zoom_Integration::instance();
        if ( ! $zoom->is_configured() ) {
            return new WP_Error( 'zoom_not_configured', __( 'Zoom integration is not configured.', 'hl-core' ) );
        }

        $zoom_email   = $zoom->get_coach_email( $session['coach_user_id'] );
        $zoom_payload = $zoom->build_meeting_payload( $api_data, $resolved );
        $created      = $zoom->create_meeting( $zoom_email, $zoom_payload );

        if ( is_wp_error( $created ) ) {
            HL_Audit_Service::log( 'zoom_meeting_retried', array(
                'entity_type' => 'coaching_session',
                'entity_id'   => $session_id,
                'after_data'  => array( 'success' => false, 'zoom_code' => $created->get_error_code(), 'zoom_error' => $created->get_error_message() ),
            ) );
            return $created;
        }

        $new_meeting_id  = isset( $created['id'] ) ? (int) $created['id'] : 0;
        $new_meeting_url = $created['join_url'] ?? '';

        $rows = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hl_coaching_session
             SET zoom_meeting_id = %d, meeting_url = %s
             WHERE session_id = %d AND zoom_meeting_id IS NULL",
            $new_meeting_id, $new_meeting_url, $session_id
        ) );

        if ( $rows === false ) {
            $zoom->delete_meeting( $new_meeting_id );
            HL_Audit_Service::log( 'zoom_meeting_retried', array(
                'entity_type' => 'coaching_session', 'entity_id' => $session_id,
                'after_data'  => array( 'success' => false, 'db_error' => $wpdb->last_error, 'orphan_meeting_id' => $new_meeting_id ),
            ) );
            return new WP_Error( 'db_write_failed', $wpdb->last_error ?: __( 'Failed to update session row.', 'hl-core' ) );
        }

        if ( (int) $rows === 0 ) {
            // Race lost — another retry already wrote a meeting_id.
            $delete_orphan = $zoom->delete_meeting( $new_meeting_id );
            $note = is_wp_error( $delete_orphan ) ? 'race_lost_orphan_DELETE_FAILED' : 'race_lost_orphan_deleted';
            $audit_extra = is_wp_error( $delete_orphan )
                ? array( 'orphan_meeting_id' => $new_meeting_id, 'delete_error' => $delete_orphan->get_error_message() )
                : array();
            HL_Audit_Service::log( 'zoom_meeting_retried', array(
                'entity_type' => 'coaching_session', 'entity_id' => $session_id,
                'after_data'  => array_merge( array( 'success' => true, 'note' => $note ), $audit_extra ),
            ) );
            return array( 'meeting_id' => null, 'meeting_url' => null, 'note' => 'already_set_by_concurrent_retry' );
        }

        // Update Outlook event if one exists.
        if ( ! empty( $session['outlook_event_id'] ) && class_exists( 'HL_Microsoft_Graph' ) ) {
            $graph = HL_Microsoft_Graph::instance();
            if ( $graph->is_configured() ) {
                $coach_ms_email          = $graph->get_coach_email( $session['coach_user_id'] );
                $api_data['meeting_url'] = $new_meeting_url;
                $event_payload           = $graph->build_event_payload( $api_data );
                $graph->update_calendar_event( $coach_ms_email, $session['outlook_event_id'], $event_payload );
                // Outlook update failure is non-fatal — Zoom write already succeeded.
            }
        }

        HL_Audit_Service::log( 'zoom_meeting_retried', array(
            'entity_type' => 'coaching_session', 'entity_id' => $session_id,
            'after_data'  => array( 'success' => true, 'meeting_id' => $new_meeting_id ),
        ) );

        // Follow-up email — distinct subject from booking confirmation (Task E1b).
        HL_Scheduling_Email_Service::instance()->send_zoom_link_ready( array(
            'mentor_name' => $session['mentor_name'], 'mentor_email' => $mentor_email,
            'mentor_timezone' => $session['mentor_timezone'] ?? wp_timezone_string(),
            'coach_name' => $coach_user ? $coach_user->display_name : '',
            'coach_email' => $coach_user ? $coach_user->user_email : '',
            'coach_timezone' => $coach_tz,
            'session_datetime' => $session['session_datetime'],
            'meeting_url' => $new_meeting_url,
        ) );

        return array( 'meeting_id' => $new_meeting_id, 'meeting_url' => $new_meeting_url );
    } finally {
        delete_transient( $lock_key );
    }
}
```

- [ ] **Step 2: Test snippet** (`bin/test-snippets/test-coach-zoom-retry.php`)

```php
<?php
function _t( $l, $c ) { echo $c ? "PASS: $l\n" : "FAIL: $l\n"; }

global $wpdb;
$svc = new HL_Scheduling_Service();

// 1. already_has_meeting path.
$wpdb->insert( $wpdb->prefix . 'hl_coaching_session', array(
    'cycle_id' => 1, 'coach_user_id' => 1508, 'mentor_enrollment_id' => 272,
    'session_title' => 'TEST RETRY', 'session_datetime' => '2026-12-01 10:00:00',
    'session_status' => 'scheduled', 'zoom_meeting_id' => 1234567890,
    'meeting_url' => 'https://example.com/already-set',
) );
$session_id = (int) $wpdb->insert_id;

$r = $svc->retry_zoom_meeting( $session_id );
_t( 'race-lost path: returns WP_Error already_has_meeting',
    is_wp_error( $r ) && $r->get_error_code() === 'already_has_meeting' );

$wpdb->delete( $wpdb->prefix . 'hl_coaching_session', array( 'session_id' => $session_id ), array( '%d' ) );

// 2. Lock test.
$wpdb->insert( $wpdb->prefix . 'hl_coaching_session', array(
    'cycle_id' => 1, 'coach_user_id' => 1508, 'mentor_enrollment_id' => 272,
    'session_title' => 'TEST RETRY 2', 'session_datetime' => '2026-12-01 10:00:00',
    'session_status' => 'scheduled', 'zoom_meeting_id' => null, 'meeting_url' => null,
) );
$session_id2 = (int) $wpdb->insert_id;
set_transient( 'hl_zoom_retry_lock_' . $session_id2, 1, 60 );

$r = $svc->retry_zoom_meeting( $session_id2 );
_t( 'lock held → retry_inflight', is_wp_error( $r ) && $r->get_error_code() === 'retry_inflight' );

delete_transient( 'hl_zoom_retry_lock_' . $session_id2 );
$wpdb->delete( $wpdb->prefix . 'hl_coaching_session', array( 'session_id' => $session_id2 ), array( '%d' ) );
echo "DONE\n";
```

> **Manual test required for the success path:** the test snippet above only covers race-loss + lock paths. Success requires Zoom credentials and runs in §I.

- [ ] **Step 3: Deploy and run**

```bash
bash bin/deploy.sh test
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval-file wp-content/plugins/hl-core/bin/test-snippets/test-coach-zoom-retry.php'
```

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-scheduling-service.php bin/test-snippets/test-coach-zoom-retry.php
git commit -m "feat(coach-zoom): retry_zoom_meeting() with transient lock + atomic upsert"
```

---

### Task E1b: Add `send_zoom_link_ready()` email template

**File:** `includes/services/class-hl-scheduling-email-service.php`

- [ ] **Step 1: Add the method** (next to `send_session_booked()`)

```php
/**
 * Send "Your Zoom link is ready" follow-up email after a successful retry.
 * Distinct subject from send_session_booked() so the mentor doesn't think it's a duplicate.
 */
public function send_zoom_link_ready( $data ) {
    $time_display = $this->format_session_time(
        $data['session_datetime'],
        $data['mentor_timezone'] ?? wp_timezone_string()
    );

    $greeting = sprintf( __( 'Hi %s,', 'hl-core' ), $data['mentor_name'] );
    $message  = sprintf(
        /* translators: %s = coach name */
        __( 'Your Zoom link for the coaching session with %s is ready. The link is below.', 'hl-core' ),
        $data['coach_name']
    );

    $html    = $this->build_branded_body( $greeting, $message, $time_display, $data['meeting_url'] );
    $subject = __( 'Your Zoom link is ready for your coaching session', 'hl-core' );

    wp_mail( $data['mentor_email'], $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
}
```

- [ ] **Step 2: Smoke test**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress && wp eval "
HL_Scheduling_Email_Service::instance()->send_zoom_link_ready( array(
    \"mentor_name\" => \"Test Mentor\", \"mentor_email\" => \"mateo@corsox.com\",
    \"mentor_timezone\" => \"America/New_York\", \"coach_name\" => \"Test Coach\",
    \"coach_email\" => \"mateo@corsox.com\", \"coach_timezone\" => \"America/New_York\",
    \"session_datetime\" => \"2026-05-01 10:00:00\",
    \"meeting_url\" => \"https://us02web.zoom.us/j/123\",
) );
"'
```

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-scheduling-email-service.php
git commit -m "feat(coach-zoom): send_zoom_link_ready() follow-up template"
```

---

### Task E2: AJAX handler for retry button

**File:** `includes/services/class-hl-scheduling-service.php`.

- [ ] **Step 1: Register the action in `HL_Scheduling_Service::__construct()`**

```php
add_action( 'wp_ajax_hl_retry_zoom_meeting', array( $this, 'ajax_retry_zoom_meeting' ) );
```

- [ ] **Step 2: Implement the handler**

```php
public function ajax_retry_zoom_meeting() {
    check_ajax_referer( 'hl_retry_zoom_meeting', '_nonce' );

    if ( ! current_user_can( 'manage_hl_core' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hl-core' ) ), 403 );
    }

    $session_id = absint( wp_unslash( $_POST['session_id'] ?? 0 ) );
    if ( ! $session_id ) {
        wp_send_json_error( array( 'message' => __( 'Missing session ID.', 'hl-core' ) ) );
    }

    $r = $this->retry_zoom_meeting( $session_id );
    if ( is_wp_error( $r ) ) {
        wp_send_json_error( array(
            'message'    => $r->get_error_message(),
            'error_code' => $r->get_error_code(),
        ) );
    }

    wp_send_json_success( $r );
}
```

- [ ] **Step 3: Smoke test that the handler is registered**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
  'cd /opt/bitnami/wordpress && wp eval "var_export( has_action( \"wp_ajax_hl_retry_zoom_meeting\" ) );"'
```

Expected: integer (priority).

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-scheduling-service.php
git commit -m "feat(coach-zoom): AJAX handler for retry Zoom meeting"
```

---

## Section F — Admin UI

Goal: 3 pieces — **F1** "Coaching Session Defaults" card on Settings → Scheduling, **F2** Coach Overrides Overview table below it, **F3** Coaching Hub per-coach "Zoom Settings" link + per-session "Retry Zoom creation" button.

### Task F1: "Coaching Session Defaults" card

**File:** `includes/admin/class-hl-admin-scheduling-settings.php`

- [ ] **Step 1: Confirm structure** (anchors verified during plan review)

- `handle_save()` lives at line 142 (cap = `manage_hl_core`; nonce action = `hl_scheduling_settings`).
- `render_page_content()` line 251 calls `settings_errors('hl_scheduling')` at line 257.
- Settings page slug is `hl-settings`. Tab: `?tab=scheduling`.

- [ ] **Step 2: Extend `handle_save()` (existing cap + nonce stay; only ADD the new fields block)**

Add at the end of the method, just before its closing brace:

```php
// NEW: Coaching Session Defaults.
$defaults_input = array(
    'waiting_room'           => isset( $_POST['hl_zoom_def_waiting_room'] )           ? 1 : 0,
    'mute_upon_entry'        => isset( $_POST['hl_zoom_def_mute_upon_entry'] )        ? 1 : 0,
    'join_before_host'       => isset( $_POST['hl_zoom_def_join_before_host'] )       ? 1 : 0,
    'password_required'      => isset( $_POST['hl_zoom_def_password_required'] )      ? 1 : 0,
    'meeting_authentication' => isset( $_POST['hl_zoom_def_meeting_authentication'] ) ? 1 : 0,
    'alternative_hosts'      => isset( $_POST['hl_zoom_def_alternative_hosts'] )
        ? sanitize_textarea_field( wp_unslash( $_POST['hl_zoom_def_alternative_hosts'] ) )
        : '',
);

$r = HL_Coach_Zoom_Settings_Service::save_admin_defaults( $defaults_input, get_current_user_id() );
if ( is_wp_error( $r ) ) {
    $msg = $r->get_error_message();
    if ( $r->get_error_code() === 'preflight_inflight' ) {
        $msg = __( 'Another administrator is currently saving these settings. Please retry in a moment.', 'hl-core' );
    }
    add_settings_error( 'hl_scheduling', 'zoom_defaults_save_failed', $msg, 'error' );
} else {
    add_settings_error( 'hl_scheduling', 'zoom_defaults_saved', __( 'Coaching meeting defaults saved.', 'hl-core' ), 'updated' );
}
```

> **DO NOT** change the existing capability check or nonce check. The pre-existing `isset()` / `wp_unslash()` gap on the nonce read is OUT OF SCOPE for this ticket.

- [ ] **Step 3: Render the card in `render_page_content()`**

Add a new card BELOW the existing Zoom credentials card:

```php
$defaults = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
$zoom_creds = self::get_zoom_settings();
$zoom_account_label = $zoom_creds['account_id']
    ? sprintf( __( 'Zoom Account ID: %s', 'hl-core' ), esc_html( $zoom_creds['account_id'] ) )
    : __( 'Zoom credentials not configured.', 'hl-core' );
?>
<div class="card" style="max-width:780px;margin-top:24px;">
    <h2><?php esc_html_e( 'Coaching Session Defaults', 'hl-core' ); ?></h2>
    <p class="description" style="background:#f0f6fc;padding:12px 16px;border-left:4px solid #2271b1;border-radius:3px;">
        <?php esc_html_e( 'Recording and AI Companion are configured in your Zoom account settings, not here.', 'hl-core' ); ?><br>
        <small><?php echo esc_html( $zoom_account_label ); ?></small>
    </p>

    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row"><label for="hl_zoom_def_waiting_room"><?php esc_html_e( 'Waiting room', 'hl-core' ); ?></label></th>
                <td><label><input type="checkbox" id="hl_zoom_def_waiting_room" name="hl_zoom_def_waiting_room" value="1" <?php checked( ! empty( $defaults['waiting_room'] ) ); ?>>
                    <?php esc_html_e( 'Hold participants in a waiting room until admitted.', 'hl-core' ); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="hl_zoom_def_mute_upon_entry"><?php esc_html_e( 'Mute upon entry', 'hl-core' ); ?></label></th>
                <td><label><input type="checkbox" id="hl_zoom_def_mute_upon_entry" name="hl_zoom_def_mute_upon_entry" value="1" <?php checked( ! empty( $defaults['mute_upon_entry'] ) ); ?>>
                    <?php esc_html_e( 'Participants are muted when they join.', 'hl-core' ); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="hl_zoom_def_join_before_host"><?php esc_html_e( 'Join before host', 'hl-core' ); ?></label></th>
                <td><label><input type="checkbox" id="hl_zoom_def_join_before_host" name="hl_zoom_def_join_before_host" value="1" <?php checked( ! empty( $defaults['join_before_host'] ) ); ?>>
                    <?php esc_html_e( 'Participants can join before the host. (Auto-disabled when waiting room is on.)', 'hl-core' ); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="hl_zoom_def_alternative_hosts"><?php esc_html_e( 'Alternative hosts', 'hl-core' ); ?></label></th>
                <td>
                    <textarea id="hl_zoom_def_alternative_hosts" name="hl_zoom_def_alternative_hosts" rows="2" cols="50" maxlength="1024" class="large-text"><?php echo esc_textarea( $defaults['alternative_hosts'] ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Comma-separated emails. Each must be a Licensed user on the same Zoom account. Leave empty for no alternative hosts.', 'hl-core' ); ?></p>
                </td>
            </tr>
        </tbody>
    </table>

    <details style="margin-top:16px;border:1px solid #c3c4c7;border-radius:4px;padding:8px 16px;">
        <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Advanced (account-policy interactions)', 'hl-core' ); ?></summary>
        <p class="description" style="background:#fff7ed;padding:12px;border-left:4px solid #f97316;margin-top:12px;">
            <?php esc_html_e( 'These settings can be silently overridden by your Zoom account-level policies. If they don\'t take effect, check Zoom admin first.', 'hl-core' ); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="hl_zoom_def_password_required"><?php esc_html_e( 'Require passcode', 'hl-core' ); ?></label></th>
                <td><label><input type="checkbox" id="hl_zoom_def_password_required" name="hl_zoom_def_password_required" value="1" <?php checked( ! empty( $defaults['password_required'] ) ); ?>>
                    <?php esc_html_e( 'Require a passcode to join meetings.', 'hl-core' ); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="hl_zoom_def_meeting_authentication"><?php esc_html_e( 'Require Zoom sign-in', 'hl-core' ); ?></label></th>
                <td><label><input type="checkbox" id="hl_zoom_def_meeting_authentication" name="hl_zoom_def_meeting_authentication" value="1" <?php checked( ! empty( $defaults['meeting_authentication'] ) ); ?>>
                    <?php esc_html_e( 'Participants must be signed in to a Zoom account.', 'hl-core' ); ?></label></td>
            </tr>
        </table>
    </details>
</div>
<?php
```

- [ ] **Step 4: Smoke test on test server**

1. Deploy.
2. Visit `/wp-admin/admin.php?page=hl-settings&tab=scheduling`.
3. Toggle a default → Save → reload → confirm value persists.
4. Confirm success notice fires.
5. `wp eval 'var_export( get_option( "hl_zoom_coaching_defaults" ) );'`

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-hl-admin-scheduling-settings.php
git commit -m "feat(coach-zoom): admin Coaching Session Defaults card"
```

---

### Task F2: Coach Overrides Overview table

**File:** same admin class.

- [ ] **Step 1: Add the rendering helper**

```php
private function render_coach_overrides_table() {
    global $wpdb;

    $overrides_only = ! empty( $_GET['overrides_only'] );
    $page           = max( 1, absint( $_GET['paged_overrides'] ?? 1 ) );
    $per_page       = 50;
    $offset         = ( $page - 1 ) * $per_page;

    // Coach role slug confirmed at class-hl-installer.php:2337.
    $coach_ids = get_users( array(
        'role__in' => array( 'coach' ),
        'fields'   => 'ID',
        'orderby'  => 'display_name',
        'number'   => -1,
    ) );

    if ( empty( $coach_ids ) ) {
        echo '<p>' . esc_html__( 'No coaches found.', 'hl-core' ) . '</p>';
        return;
    }

    // One batched read of override rows (no N+1) — prepare() with %d placeholders.
    $placeholders = implode( ',', array_fill( 0, count( $coach_ids ), '%d' ) );
    $sql          = $wpdb->prepare(
        "SELECT coach_user_id, waiting_room, mute_upon_entry, join_before_host, alternative_hosts, updated_at, updated_by_user_id
         FROM {$wpdb->prefix}hl_coach_zoom_settings WHERE coach_user_id IN ($placeholders)",
        $coach_ids
    );
    $rows = $wpdb->get_results( $sql, OBJECT_K );

    // Warm user cache for both coach IDs and editor IDs to avoid per-row queries.
    $editor_ids = array_filter( wp_list_pluck( $rows, 'updated_by_user_id' ) );
    cache_users( array_unique( array_merge( $coach_ids, $editor_ids ) ) );

    $defaults = HL_Coach_Zoom_Settings_Service::get_admin_defaults();

    $resolved_rows = array();
    foreach ( $coach_ids as $cid ) {
        $row          = $rows[ $cid ] ?? null;
        $has_override = $row && (
            $row->waiting_room      !== null ||
            $row->mute_upon_entry   !== null ||
            $row->join_before_host  !== null ||
            $row->alternative_hosts !== null
        );
        if ( $overrides_only && ! $has_override ) continue;

        $user   = get_userdata( $cid );
        $editor = ( $row && $row->updated_by_user_id ) ? get_userdata( $row->updated_by_user_id ) : null;

        $resolved_rows[] = array(
            'cid'              => $cid,
            'name'             => $user ? $user->display_name : '#' . $cid,
            'waiting_room'     => $row && $row->waiting_room      !== null ? array( 'val' => (int) $row->waiting_room,     'src' => 'override' ) : array( 'val' => $defaults['waiting_room'],     'src' => 'default' ),
            'mute_upon_entry'  => $row && $row->mute_upon_entry   !== null ? array( 'val' => (int) $row->mute_upon_entry,  'src' => 'override' ) : array( 'val' => $defaults['mute_upon_entry'],  'src' => 'default' ),
            'join_before_host' => $row && $row->join_before_host  !== null ? array( 'val' => (int) $row->join_before_host, 'src' => 'override' ) : array( 'val' => $defaults['join_before_host'], 'src' => 'default' ),
            'alt_hosts'        => $row && $row->alternative_hosts !== null ? array( 'val' => (string) $row->alternative_hosts, 'src' => 'override' ) : array( 'val' => $defaults['alternative_hosts'], 'src' => 'default' ),
            'updated_at'       => $row ? $row->updated_at : null,
            'editor'           => $editor ? $editor->display_name : null,
        );
    }

    $total = count( $resolved_rows );
    $rows  = array_slice( $resolved_rows, $offset, $per_page );

    ?>
    <div class="card" style="max-width:1100px;margin-top:24px;">
        <h2><?php esc_html_e( 'Coach Overrides Overview', 'hl-core' ); ?></h2>
        <form method="get" style="margin-bottom:12px;">
            <input type="hidden" name="page" value="<?php echo esc_attr( $_GET['page'] ?? 'hl-settings' ); ?>">
            <input type="hidden" name="tab"  value="<?php echo esc_attr( $_GET['tab']  ?? 'scheduling' ); ?>">
            <label><input type="checkbox" name="overrides_only" value="1" <?php checked( $overrides_only ); ?> onchange="this.form.submit()">
                <?php esc_html_e( 'Show only coaches with overrides', 'hl-core' ); ?></label>
        </form>
        <div style="max-height:600px;overflow:auto;">
            <table class="wp-list-table widefat fixed striped" style="position:relative;">
                <thead style="position:sticky;top:0;background:#f6f7f7;z-index:1;">
                    <tr>
                        <th><?php esc_html_e( 'Coach', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Waiting room', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Mute on entry', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Join before host', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Alternative hosts', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Last edited', 'hl-core' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $r ): ?>
                    <tr>
                        <td><?php echo esc_html( $r['name'] ); ?></td>
                        <td><?php echo esc_html( $r['waiting_room']['val'] ? 'On' : 'Off' ); ?> <em>(<?php echo esc_html( $r['waiting_room']['src'] ); ?>)</em></td>
                        <td><?php echo esc_html( $r['mute_upon_entry']['val'] ? 'On' : 'Off' ); ?> <em>(<?php echo esc_html( $r['mute_upon_entry']['src'] ); ?>)</em></td>
                        <td><?php echo esc_html( $r['join_before_host']['val'] ? 'On' : 'Off' ); ?> <em>(<?php echo esc_html( $r['join_before_host']['src'] ); ?>)</em></td>
                        <td><?php echo $r['alt_hosts']['val'] ? esc_html( $r['alt_hosts']['val'] ) : '<em>(none)</em>'; ?> <em>(<?php echo esc_html( $r['alt_hosts']['src'] ); ?>)</em></td>
                        <td><?php echo $r['editor'] ? esc_html( $r['editor'] . ' on ' . $r['updated_at'] ) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        echo paginate_links( array(
            'base'    => add_query_arg( 'paged_overrides', '%#%' ),
            'format'  => '',
            'current' => $page,
            'total'   => max( 1, ceil( $total / $per_page ) ),
        ) );
        ?>
    </div>
    <?php
}
```

- [ ] **Step 2: Wire it into `render_page_content()`** — call `$this->render_coach_overrides_table();` immediately below the F1 defaults card.

- [ ] **Step 3: Smoke test**

1. Visit page — every cell shows `(default)` initially.
2. Set a coach override: `wp eval 'HL_Coach_Zoom_Settings_Service::save_coach_overrides( 1508, array( "mute_upon_entry" => 1 ), get_current_user_id() );'`
3. Reload → that row's mute cell shows `On (override)` + `Last edited` populated.
4. Toggle "Show only overrides" → only that row visible.
5. Cleanup.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-scheduling-settings.php
git commit -m "feat(coach-zoom): admin Coach Overrides Overview table"
```

---

### Task F3: Coaching Hub — per-coach Zoom Settings link + Retry Zoom button

**Files:**
- Modify: `includes/admin/class-hl-admin-coaching.php`
- Create: `assets/js/admin-coach-zoom-retry.js`

- [ ] **Step 1: Confirm the two locations** (verified during plan review)

- **Coaches list:** `class-hl-admin-coaching.php:1115` (`render_coaches_content()`), per-coach row at line 1142 (actions cell). Tab: `?tab=coaches`.
- **Sessions list:** `class-hl-admin-coaching.php:110` (`render_sessions_content()`), per-session row at line 484 (actions `<td>`). Default tab.

- [ ] **Step 2: Add "Zoom Settings" link in coaches actions cell (line 1142)**

```php
<a href="#" class="hlczs-admin-edit" data-coach-id="<?php echo esc_attr( $coach->ID ); ?>"><?php esc_html_e( 'Zoom Settings', 'hl-core' ); ?></a>
```

> **Cross-section dependency:** non-functional until §H Task H2 wires the modal listener. Only confirm it renders in §F.

- [ ] **Step 3: Add Retry button in sessions actions cell (line 484)**

```php
<?php if ( empty( $session['meeting_url'] ) ) : ?>
    <button type="button"
            class="button hlczs-retry-zoom"
            data-session-id="<?php echo esc_attr( $session['session_id'] ); ?>"
            data-nonce="<?php echo esc_attr( wp_create_nonce( 'hl_retry_zoom_meeting' ) ); ?>">
        <?php esc_html_e( 'Retry Zoom creation', 'hl-core' ); ?>
    </button>
<?php endif; ?>
```

- [ ] **Step 4: Enqueue the retry JS on the Coaching Hub page**

In the admin class's `enqueue_assets()` (or equivalent), conditional on the coaching-hub page:

```php
wp_enqueue_script(
    'hl-admin-coach-zoom-retry',
    HL_CORE_ASSETS_URL . 'js/admin-coach-zoom-retry.js',
    array(),
    HL_CORE_VERSION,
    true
);
wp_localize_script( 'hl-admin-coach-zoom-retry', 'HLCoachZoomRetry', array(
    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
) );
```

- [ ] **Step 5: Create `assets/js/admin-coach-zoom-retry.js`**

```javascript
(function(){
    'use strict';
    document.addEventListener('click', function(e){
        var btn = e.target.closest('.hlczs-retry-zoom');
        if (!btn || btn.disabled) return;

        e.preventDefault();
        btn.disabled = true;
        var origText = btn.textContent;
        btn.textContent = 'Retrying…';

        var fd = new FormData();
        fd.append('action', 'hl_retry_zoom_meeting');
        fd.append('_nonce', btn.dataset.nonce);
        fd.append('session_id', btn.dataset.sessionId);

        fetch(HLCoachZoomRetry.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(json){
                if (json.success) {
                    btn.textContent = 'Zoom link created';
                    setTimeout(function(){ location.reload(); }, 1200);
                } else {
                    btn.disabled = false;
                    btn.textContent = origText;
                    showInlineNotice(btn, (json.data && json.data.message) || 'Retry failed.', 'error');
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.textContent = origText;
                showInlineNotice(btn, 'Network error during retry.', 'error');
            });
    });

    function showInlineNotice(anchor, msg, kind) {
        var existing = anchor.parentNode.querySelector('.hlczs-retry-notice');
        if (existing) existing.remove();
        var n = document.createElement('div');
        n.className = 'notice notice-' + (kind === 'error' ? 'error' : 'success') + ' is-dismissible hlczs-retry-notice';
        n.style.margin = '8px 0 0';
        n.innerHTML = '<p>' + msg.replace(/[<>&]/g, function(c){ return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]; }) + '</p>';
        anchor.parentNode.appendChild(n);
    }
})();
```

- [ ] **Step 6: Smoke test the retry button**

1. Mark a test session NULL: `wp db query "UPDATE wp_hl_coaching_session SET zoom_meeting_id = NULL, meeting_url = NULL WHERE session_id = 8"`
2. Visit Coaching Hub → confirm Retry button appears.
3. Click → spinner → success → reload shows new meeting URL.
4. Click again → `already_has_meeting` inline notice.

- [ ] **Step 7: Commit**

```bash
git add includes/admin/class-hl-admin-coaching.php assets/js/admin-coach-zoom-retry.js
git commit -m "feat(coach-zoom): admin Zoom Settings link + Retry Zoom button"
```

---

## Section G — Coach UI structure (tile + modal markup)

Goal: "My Meeting Settings" tile on the coach dashboard, first-visit callout, modal view file with single-toggle rows + alt-hosts 3-radio + admin-only read-only section. JS+CSS in §H.

### Task G1: Add tile + first-visit callout to Coach Dashboard

**Files:** `includes/frontend/class-hl-frontend-coach-dashboard.php`, `hl-core.php`

- [ ] **Step 1: Confirm dashboard structure** (verified during plan review)

- The dashboard is a shortcode renderer (`HL_Frontend_Coach_Dashboard::render($atts)`).
- Class has **NO `__construct()`** — instantiated only when shortcode renders. AJAX handlers MUST be registered from `HL_Core::register_hooks()`.
- Existing prefix is `hlcd-` (Hl Coach Dashboard). New tile uses `hlczs-`.
- The render method uses `ob_start()` / `ob_get_clean()` — new markup must be appended INSIDE that buffer.

- [ ] **Step 2: Add the tile inside the dashboard render** (after the existing stats grid)

```php
<?php
$current_user_id = $user_id; // already set at the top of render()
$resolved        = HL_Coach_Zoom_Settings_Service::resolve_for_coach( $current_user_id );
$overrides       = HL_Coach_Zoom_Settings_Service::get_coach_overrides( $current_user_id );
unset( $overrides['_meta'] );

$override_count = 0;
foreach ( array( 'waiting_room', 'mute_upon_entry', 'join_before_host', 'alternative_hosts' ) as $f ) {
    if ( array_key_exists( $f, $overrides ) ) $override_count++;
}
?>
<div class="hlczs-tile" data-coach-id="<?php echo esc_attr( $current_user_id ); ?>">
    <h3><?php esc_html_e( 'My Meeting Settings', 'hl-core' ); ?></h3>
    <p class="hlczs-tile-summary">
        <?php
        if ( $override_count === 0 ) {
            esc_html_e( 'You\'re using the company defaults for all meeting settings.', 'hl-core' );
        } else {
            printf(
                /* translators: %d = number of overridden settings */
                esc_html( _n( '%d setting overrides the company default.', '%d settings override the company default.', $override_count, 'hl-core' ) ),
                (int) $override_count
            );
        }
        ?>
    </p>
    <button type="button" class="hlczs-edit-trigger button" data-coach-id="<?php echo esc_attr( $current_user_id ); ?>">
        <?php esc_html_e( 'Edit', 'hl-core' ); ?>
    </button>
</div>
```

- [ ] **Step 3: Add the first-visit callout above the tile**

```php
<?php
$dismissed = (bool) get_user_meta( $current_user_id, 'hl_dismissed_coach_zoom_callout', true );
// Read piggybacks existing user-meta loads — no extra DB query.
if ( ! $dismissed ) :
    ?>
    <div class="hlczs-callout" role="status" data-callout-nonce="<?php echo esc_attr( wp_create_nonce( 'hl_dismiss_coach_zoom_callout' ) ); ?>">
        <p>
            <?php esc_html_e( 'Tip: customize how your Zoom meetings are configured for coaching sessions.', 'hl-core' ); ?>
            <button type="button" class="hlczs-callout-dismiss" aria-label="<?php esc_attr_e( 'Dismiss tip', 'hl-core' ); ?>">×</button>
        </p>
    </div>
    <?php
endif;
?>
```

- [ ] **Step 4: Register the dismiss AJAX handler from `HL_Core::register_hooks()`**

In `hl-core.php` inside `register_hooks()`, alongside the §C `delete_user` hook:

```php
add_action( 'wp_ajax_hl_dismiss_coach_zoom_callout',
    array( 'HL_Frontend_Coach_Dashboard', 'ajax_dismiss_coach_zoom_callout' ) );
```

Add as a **static method** on `HL_Frontend_Coach_Dashboard`:

```php
public static function ajax_dismiss_coach_zoom_callout() {
    check_ajax_referer( 'hl_dismiss_coach_zoom_callout', '_nonce' );
    $uid = get_current_user_id();
    if ( ! $uid ) {
        wp_send_json_error( array( 'message' => __( 'Not signed in.', 'hl-core' ) ), 403 );
    }
    update_user_meta( $uid, 'hl_dismissed_coach_zoom_callout', 1 );
    wp_send_json_success();
}
```

- [ ] **Step 5: Smoke test (without modal yet — modal opens in §H)**

1. Deploy.
2. Visit dashboard as a coach (e.g. shernandez@housmanlearning.com).
3. Confirm tile renders + callout shows.
4. Manually dismiss: `wp user meta update 1508 hl_dismissed_coach_zoom_callout 1`.
5. Reload → callout no longer renders.

- [ ] **Step 6: Commit**

```bash
git add includes/frontend/class-hl-frontend-coach-dashboard.php hl-core.php
git commit -m "feat(coach-zoom): My Meeting Settings tile + first-visit callout"
```

---

### Task G2: Modal view file (PHP markup)

**File:** `includes/frontend/views/coach-zoom-settings-modal.php`

- [ ] **Step 1: Create the file**

```php
<?php
/**
 * Coach Zoom Meeting Settings — Modal view.
 *
 * Expects in scope:
 *   $modal_coach_user_id (int)
 *   $resolved (array)
 *   $overrides (array)
 *   $defaults (array)
 *
 * @package HL_Core
 */
if ( ! defined( 'ABSPATH' ) ) exit;
unset( $overrides['_meta'] );

// Auto-open ONLY in admin context (admin editing a different user). PHP-controlled,
// not querystring-keyed (avoids spurious opens on a coach's own dashboard).
$is_admin_editing_other = ( get_current_user_id() !== (int) $modal_coach_user_id ) && current_user_can( 'manage_hl_core' );
?>
<div class="hlczs-modal-backdrop" hidden></div>
<div class="hlczs-modal" role="dialog" aria-modal="true" aria-labelledby="hlczs-modal-title" data-auto-open="<?php echo $is_admin_editing_other ? '1' : '0'; ?>" hidden>
    <div class="hlczs-modal-header">
        <h2 id="hlczs-modal-title"><?php esc_html_e( 'My Meeting Settings', 'hl-core' ); ?></h2>
        <button type="button" class="hlczs-modal-close" aria-label="<?php esc_attr_e( 'Close', 'hl-core' ); ?>">×</button>
    </div>

    <form id="hlczs-form"
          data-coach-id="<?php echo esc_attr( $modal_coach_user_id ); ?>"
          data-nonce="<?php echo esc_attr( wp_create_nonce( 'hl_save_coach_zoom_settings' ) ); ?>">

        <div class="hlczs-banner" role="alert" hidden></div>

        <?php
        $rows = array(
            'waiting_room'     => __( 'Waiting room', 'hl-core' ),
            'mute_upon_entry'  => __( 'Mute upon entry', 'hl-core' ),
            'join_before_host' => __( 'Join before host', 'hl-core' ),
        );
        foreach ( $rows as $field => $label ) :
            $resolved_val = ! empty( $resolved[ $field ] );
            $is_override  = array_key_exists( $field, $overrides );
            ?>
            <div class="hlczs-row" data-field="<?php echo esc_attr( $field ); ?>">
                <div class="hlczs-row-label"><?php echo esc_html( $label ); ?></div>
                <div class="hlczs-row-control">
                    <button type="button"
                            class="hlczs-toggle"
                            role="switch"
                            aria-pressed="<?php echo $resolved_val ? 'true' : 'false'; ?>"
                            data-field="<?php echo esc_attr( $field ); ?>"
                            data-default-value="<?php echo $defaults[ $field ] ? '1' : '0'; ?>">
                        <span class="hlczs-toggle-track"><span class="hlczs-toggle-thumb"></span></span>
                        <span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
                    </button>
                </div>
                <div class="hlczs-row-meta">
                    <span class="hlczs-row-caption" aria-live="polite">
                        <?php
                        echo esc_html( $is_override
                            ? __( 'Using your override.', 'hl-core' )
                            : __( 'Using the company default.', 'hl-core' )
                        );
                        ?>
                    </span>
                    <button type="button"
                            class="hlczs-row-reset"
                            data-field="<?php echo esc_attr( $field ); ?>"
                            <?php echo $is_override ? '' : 'hidden'; ?>>
                        <?php esc_html_e( 'Reset to default', 'hl-core' ); ?>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>

        <?php
        // Alternative hosts row — same chrome.
        $alt_default        = $defaults['alternative_hosts'];
        $alt_default_label  = $alt_default !== ''
            ? sprintf( '[%s]', $alt_default )
            : __( '(no alternative hosts)', 'hl-core' );
        $alt_override_value = array_key_exists( 'alternative_hosts', $overrides ) ? $overrides['alternative_hosts'] : null;
        $alt_state          = $alt_override_value === null ? 'use_default'
                              : ( $alt_override_value === '' ? 'override_none' : 'override_emails' );
        ?>
        <div class="hlczs-row hlczs-row-althosts" data-field="alternative_hosts">
            <div class="hlczs-row-label"><?php esc_html_e( 'Alternative hosts', 'hl-core' ); ?></div>
            <div class="hlczs-row-control">
                <fieldset>
                    <legend class="screen-reader-text"><?php esc_html_e( 'Alternative hosts mode', 'hl-core' ); ?></legend>
                    <label><input type="radio" name="alt_hosts_mode" value="use_default" <?php checked( $alt_state, 'use_default' ); ?>>
                        <?php
                        printf(
                            /* translators: %s = formatted default value */
                            esc_html__( 'Use the company default %s', 'hl-core' ),
                            esc_html( $alt_default_label )
                        );
                        ?>
                    </label><br>
                    <label><input type="radio" name="alt_hosts_mode" value="override_none" <?php checked( $alt_state, 'override_none' ); ?>>
                        <?php esc_html_e( 'Override: no alternative hosts', 'hl-core' ); ?>
                    </label><br>
                    <label>
                        <input type="radio" name="alt_hosts_mode" value="override_emails" <?php checked( $alt_state, 'override_emails' ); ?>>
                        <?php esc_html_e( 'Override with these emails:', 'hl-core' ); ?>
                    </label>
                    <textarea
                        id="hlczs-alt-hosts-textarea"
                        name="alternative_hosts"
                        rows="2" cols="40" maxlength="1024"
                        placeholder="<?php esc_attr_e( 'comma-separated emails', 'hl-core' ); ?>"
                        <?php echo $alt_state === 'override_emails' ? '' : 'disabled'; ?>><?php echo esc_textarea( $alt_state === 'override_emails' ? $alt_override_value : '' ); ?></textarea>
                </fieldset>
            </div>
            <div class="hlczs-row-meta">
                <button type="button"
                        class="hlczs-row-reset"
                        data-field="alternative_hosts"
                        <?php echo $alt_state === 'use_default' ? 'hidden' : ''; ?>>
                    <?php esc_html_e( 'Reset to default', 'hl-core' ); ?>
                </button>
            </div>
        </div>

        <!-- Read-only "Set by your administrator" section (admin-only fields) -->
        <section class="hlczs-readonly" aria-labelledby="hlczs-readonly-title">
            <h3 id="hlczs-readonly-title"><?php esc_html_e( 'Set by your administrator', 'hl-core' ); ?></h3>
            <p class="hlczs-readonly-help"><?php esc_html_e( 'These settings apply to all coaching sessions. Contact your administrator to change.', 'hl-core' ); ?></p>
            <ul>
                <li>
                    <strong><?php esc_html_e( 'Require passcode:', 'hl-core' ); ?></strong>
                    <?php echo esc_html( ! empty( $resolved['password_required'] ) ? __( 'On', 'hl-core' ) : __( 'Off', 'hl-core' ) ); ?>
                </li>
                <li>
                    <strong><?php esc_html_e( 'Require Zoom sign-in:', 'hl-core' ); ?></strong>
                    <?php echo esc_html( ! empty( $resolved['meeting_authentication'] ) ? __( 'On', 'hl-core' ) : __( 'Off', 'hl-core' ) ); ?>
                </li>
            </ul>
        </section>

        <div class="hlczs-modal-footer">
            <button type="button" class="button hlczs-reset-all"><?php esc_html_e( 'Reset all to defaults', 'hl-core' ); ?></button>
            <button type="submit" class="button button-primary hlczs-save"><?php esc_html_e( 'Save', 'hl-core' ); ?></button>
        </div>
    </form>
</div>

<!-- "Reset all" confirm-modal-in-modal (styled, NOT native confirm) -->
<div class="hlczs-confirm-backdrop" hidden></div>
<div class="hlczs-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="hlczs-confirm-title" hidden>
    <h3 id="hlczs-confirm-title"><?php esc_html_e( 'Reset all settings?', 'hl-core' ); ?></h3>
    <p><?php esc_html_e( 'Reset all your meeting settings to the company defaults? Your overrides will be cleared.', 'hl-core' ); ?></p>
    <div class="hlczs-confirm-actions">
        <button type="button" class="button hlczs-confirm-cancel"><?php esc_html_e( 'Cancel', 'hl-core' ); ?></button>
        <button type="button" class="button button-primary hlczs-confirm-ok"><?php esc_html_e( 'Reset all', 'hl-core' ); ?></button>
    </div>
</div>
```

- [ ] **Step 2: Render the modal from the coach dashboard (and admin context)**

In `class-hl-frontend-coach-dashboard.php` — at the bottom of the dashboard render output:

```php
$modal_coach_user_id = get_current_user_id();
if ( current_user_can( 'manage_hl_core' ) && isset( $_GET['coach_user_id'] ) ) {
    $modal_coach_user_id = absint( $_GET['coach_user_id'] );
}

if ( $modal_coach_user_id > 0 && get_userdata( $modal_coach_user_id ) ) {
    $resolved  = HL_Coach_Zoom_Settings_Service::resolve_for_coach( $modal_coach_user_id );
    $overrides = HL_Coach_Zoom_Settings_Service::get_coach_overrides( $modal_coach_user_id );
    $defaults  = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
    require HL_CORE_PLUGIN_DIR . 'includes/frontend/views/coach-zoom-settings-modal.php';
}
```

> **Admin context from Coaching Hub:** §F's "Zoom Settings" link uses `data-coach-id`. The §H JS will set `window.location` with `?coach_user_id=N` so the server includes the modal pre-populated. PHP emits `data-auto-open="1"` only when admin-context (current_user != modal_coach + manage_hl_core).
>
> **Textarea-when-disabled gotcha for §H:** the alt-hosts textarea has `disabled` set when the radio mode isn't `override_emails`. JS in §H must read the value via `document.getElementById('hlczs-alt-hosts-textarea').value` regardless of disabled state.

- [ ] **Step 3: Smoke test the modal markup (initially hidden)**

1. Deploy + visit dashboard.
2. Inspect DOM: `.hlczs-modal[hidden]` is present.
3. Manually remove `hidden` via DevTools — confirm modal renders with all rows + readonly section + footer.

- [ ] **Step 4: Commit**

```bash
git add includes/frontend/class-hl-frontend-coach-dashboard.php includes/frontend/views/coach-zoom-settings-modal.php
git commit -m "feat(coach-zoom): coach modal markup with admin-only readonly section"
```

---

## Section H — Coach UI: JavaScript + CSS + accessibility

### Task H1: Asset enqueue

**File:** `includes/frontend/class-hl-shortcodes.php` (where coach shortcodes register their assets via `has_shortcode()`).

- [ ] **Step 1: Enqueue CSS + JS conditionally on dashboard pages**

```php
wp_enqueue_style(
    'hl-coach-zoom-settings',
    HL_CORE_ASSETS_URL . 'css/coach-zoom-settings.css',
    array(),
    HL_CORE_VERSION
);

wp_enqueue_script(
    'hl-coach-zoom-settings',
    HL_CORE_ASSETS_URL . 'js/coach-zoom-settings.js',
    array(),
    HL_CORE_VERSION,
    true
);

wp_localize_script( 'hl-coach-zoom-settings', 'HLCoachZoomSettings', array(
    'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
    'dashboardUrl' => get_permalink(), // for ?coach_user_id= page-navigate from admin
) );
```

Trigger only when the page contains `[hl_coach_dashboard]` (use `has_shortcode()`).

- [ ] **Step 2: Smoke test**

```bash
bash bin/deploy.sh test
# Visit dashboard, view source, confirm <link> + <script> tags appear with HL_CORE_VERSION query.
```

- [ ] **Step 3: Combined commit at end of H3.**

---

### Task H2: `assets/js/coach-zoom-settings.js`

- [ ] **Step 1: Create the file**

Single vanilla JS file. Handles: toggle clicks, per-row reset with focus return, alt-hosts radio mode switch, "Reset all" styled confirm, AJAX save with field-level error rendering, modal open from tile button, modal close + focus trap, callout dismiss, admin-context page-navigate, nonce-expiry detection.

```javascript
(function(){
    'use strict';

    var modal           = document.querySelector('.hlczs-modal');
    var backdrop        = document.querySelector('.hlczs-modal-backdrop');
    var form            = document.getElementById('hlczs-form');
    var openTriggers    = document.querySelectorAll('.hlczs-edit-trigger');
    var adminEditLinks  = document.querySelectorAll('.hlczs-admin-edit');
    var closeBtn        = modal ? modal.querySelector('.hlczs-modal-close') : null;
    var resetAllBtn     = modal ? modal.querySelector('.hlczs-reset-all') : null;
    var confirmBackdrop = document.querySelector('.hlczs-confirm-backdrop');
    var confirmModal    = document.querySelector('.hlczs-confirm-modal');
    var confirmCancel   = confirmModal ? confirmModal.querySelector('.hlczs-confirm-cancel') : null;
    var confirmOk       = confirmModal ? confirmModal.querySelector('.hlczs-confirm-ok') : null;
    var lastFocused     = null;

    // Modal open/close + focus trap
    function openModal() {
        if (!modal) return;
        lastFocused = document.activeElement;
        backdrop.hidden = false;
        modal.hidden    = false;
        document.body.style.overflow = 'hidden';
        var focusable = modal.querySelectorAll('button, [href], input, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable[0]) focusable[0].focus();
        document.addEventListener('keydown', trapTab);
        document.addEventListener('keydown', escClose);
    }
    function closeModal() {
        if (!modal) return;
        modal.hidden    = true;
        backdrop.hidden = true;
        document.body.style.overflow = '';
        document.removeEventListener('keydown', trapTab);
        document.removeEventListener('keydown', escClose);
        if (lastFocused) lastFocused.focus();
    }
    function escClose(e) { if (e.key === 'Escape') closeModal(); }
    function trapTab(e) {
        if (e.key !== 'Tab') return;
        var focusable = modal.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
        if (!focusable.length) return;
        var first = focusable[0], last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    [].forEach.call(openTriggers, function(btn){ btn.addEventListener('click', openModal); });
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', closeModal);

    // Admin context: page-navigate to ?coach_user_id=N
    [].forEach.call(adminEditLinks, function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            var coachId = link.dataset.coachId;
            if (!coachId) return;
            window.location = HLCoachZoomSettings.dashboardUrl + '?coach_user_id=' + encodeURIComponent(coachId);
        });
    });

    // Toggle / reset / alt-hosts radio handler
    if (modal) {
        modal.addEventListener('click', function(e){
            var toggle = e.target.closest('.hlczs-toggle');
            if (toggle) {
                var pressed = toggle.getAttribute('aria-pressed') === 'true';
                toggle.setAttribute('aria-pressed', pressed ? 'false' : 'true');
                var toggleRow = toggle.closest('.hlczs-row');
                toggleRow.dataset.dirty = '1';
                updateRowCaption(toggleRow, true);
                return;
            }
            var resetBtn = e.target.closest('.hlczs-row-reset');
            if (resetBtn) {
                var resetTargetRow = resetBtn.closest('.hlczs-row');
                resetRow(resetTargetRow);
                var focusTarget = resetTargetRow.querySelector('.hlczs-toggle, input[type="radio"]');
                if (focusTarget) focusTarget.focus();
                return;
            }
            if (e.target.matches('input[name="alt_hosts_mode"]')) {
                var altRow = modal.querySelector('.hlczs-row-althosts');
                var ta     = document.getElementById('hlczs-alt-hosts-textarea');
                var mode   = e.target.value;
                ta.disabled = (mode !== 'override_emails');
                altRow.dataset.dirty = '1';
                altRow.querySelector('.hlczs-row-reset').hidden = (mode === 'use_default');
                if (mode === 'override_emails') ta.focus();
                return;
            }
        });
    }

    function resetRow(row) {
        var field = row.dataset.field;
        if (field === 'alternative_hosts') {
            row.querySelector('input[value="use_default"]').checked = true;
            var ta = document.getElementById('hlczs-alt-hosts-textarea');
            ta.disabled = true;
            ta.value    = '';
        } else {
            var toggle = row.querySelector('.hlczs-toggle');
            toggle.setAttribute('aria-pressed', toggle.dataset.defaultValue === '1' ? 'true' : 'false');
        }
        row.dataset.dirty = '0';
        row.dataset.reset = '1';
        row.querySelector('.hlczs-row-reset').hidden = true;
        updateRowCaption(row, false);
    }

    function updateRowCaption(row, isOverride) {
        var caption = row.querySelector('.hlczs-row-caption');
        if (!caption) return;
        caption.textContent = isOverride ? 'Using your override.' : 'Using the company default.';
        var resetBtn = row.querySelector('.hlczs-row-reset');
        if (resetBtn) resetBtn.hidden = !isOverride;
    }

    // "Reset all" styled confirm
    if (resetAllBtn) {
        resetAllBtn.addEventListener('click', function(){
            confirmBackdrop.hidden = false;
            confirmModal.hidden    = false;
            confirmOk.focus();
        });
    }
    if (confirmCancel) confirmCancel.addEventListener('click', function(){
        confirmBackdrop.hidden = true;
        confirmModal.hidden    = true;
        resetAllBtn.focus();
    });
    if (confirmOk) confirmOk.addEventListener('click', function(){
        confirmBackdrop.hidden = true;
        confirmModal.hidden    = true;
        modal.querySelectorAll('.hlczs-row').forEach(resetRow);
        // Per spec: one-click intent — submit immediately.
        if (form.requestSubmit) form.requestSubmit();
        else form.dispatchEvent(new Event('submit', { cancelable: true }));
    });

    // Track input on alt-hosts textarea so paste/typing is captured even without radio click.
    var altTextarea = document.getElementById('hlczs-alt-hosts-textarea');
    if (altTextarea) {
        altTextarea.addEventListener('input', function(){
            var altRow = altTextarea.closest('.hlczs-row');
            altRow.dataset.dirty = '1';
        });
        // Client-side blur validation (best-effort; server is authoritative).
        altTextarea.addEventListener('blur', function(){
            var emails = altTextarea.value.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
            var bad = emails.filter(function(e){ return ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e); });
            altTextarea.classList.toggle('hlczs-invalid', bad.length > 0);
        });
    }

    // AJAX save
    if (form) {
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var saveBtn = form.querySelector('.hlczs-save');
            saveBtn.disabled = true;

            // Conditional copy: "Verifying with Zoom…" only when alt_hosts will preflight.
            var altRowEl  = modal.querySelector('.hlczs-row-althosts');
            var altDirty  = altRowEl && altRowEl.dataset.dirty === '1';
            var altMode   = altDirty ? form.querySelector('input[name="alt_hosts_mode"]:checked').value : null;
            var altValue  = altDirty ? document.getElementById('hlczs-alt-hosts-textarea').value : '';
            var willPreflight = altDirty && altMode === 'override_emails' && altValue.trim() !== '';
            saveBtn.textContent = willPreflight ? 'Verifying with Zoom…' : 'Saving…';

            var banner = form.querySelector('.hlczs-banner');
            banner.hidden = true;
            form.querySelectorAll('.hlczs-row-error').forEach(function(e){ e.classList.remove('hlczs-row-error'); });

            var fd = new FormData();
            fd.append('action', 'hl_save_coach_zoom_settings');
            fd.append('_nonce', form.dataset.nonce);
            fd.append('coach_user_id', form.dataset.coachId);

            modal.querySelectorAll('.hlczs-row').forEach(function(row){
                var field = row.dataset.field;
                var dirty = row.dataset.dirty === '1';
                var reset = row.dataset.reset === '1';

                if (reset) { fd.append('reset[]', field); return; }
                if (!dirty) return;

                if (field === 'alternative_hosts') {
                    var mode = form.querySelector('input[name="alt_hosts_mode"]:checked').value;
                    if (mode === 'override_none') {
                        fd.append('alternative_hosts', '');
                    } else if (mode === 'override_emails') {
                        fd.append('alternative_hosts', document.getElementById('hlczs-alt-hosts-textarea').value);
                    }
                } else {
                    var pressed = row.querySelector('.hlczs-toggle').getAttribute('aria-pressed') === 'true';
                    fd.append(field, pressed ? '1' : '0');
                }
            });

            fetch(HLCoachZoomSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(json){
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save';
                    if (json.success) {
                        banner.className   = 'hlczs-banner hlczs-banner-success';
                        banner.textContent = 'Settings saved.';
                        banner.hidden      = false;
                        setTimeout(function(){ closeModal(); window.location.reload(); }, 800);
                    } else {
                        // Detect nonce expiry (WP returns 0/-1).
                        if (json === 0 || json === -1) {
                            banner.className   = 'hlczs-banner hlczs-banner-error';
                            banner.textContent = 'Your session expired. Please reload the page and try again.';
                            banner.hidden      = false;
                            return;
                        }
                        var msg       = (json.data && json.data.message) || 'Save failed.';
                        var errorData = (json.data && json.data.error_data) || {};
                        var field     = errorData.field;
                        if (field) {
                            var row = modal.querySelector('.hlczs-row[data-field="' + field + '"]');
                            if (row) {
                                row.classList.add('hlczs-row-error');
                                var caption = row.querySelector('.hlczs-row-caption');
                                if (caption) caption.textContent = msg;
                            } else {
                                banner.className   = 'hlczs-banner hlczs-banner-error';
                                banner.textContent = msg;
                                banner.hidden      = false;
                            }
                        } else {
                            banner.className   = 'hlczs-banner hlczs-banner-error';
                            banner.textContent = msg;
                            banner.hidden      = false;
                        }
                    }
                })
                .catch(function(){
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save';
                    banner.className   = 'hlczs-banner hlczs-banner-error';
                    banner.textContent = 'Network error. Please try again.';
                    banner.hidden      = false;
                });
        });
    }

    // First-visit callout dismiss
    var calloutDismiss = document.querySelector('.hlczs-callout-dismiss');
    if (calloutDismiss) {
        calloutDismiss.addEventListener('click', function(){
            var callout = calloutDismiss.closest('.hlczs-callout');
            var nonce   = callout.dataset.calloutNonce;
            callout.style.display = 'none';
            var fd = new FormData();
            fd.append('action', 'hl_dismiss_coach_zoom_callout');
            fd.append('_nonce', nonce);
            fetch(HLCoachZoomSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
        });
    }

    // Auto-open modal in admin context (PHP sets data-auto-open="1")
    if (modal && modal.dataset.autoOpen === '1') {
        openModal();
    }
})();
```

- [ ] **Step 2: Add the AJAX save handler** (registered from `HL_Core::register_hooks()`)

In `hl-core.php`:

```php
add_action( 'wp_ajax_hl_save_coach_zoom_settings',
    array( 'HL_Frontend_Coach_Dashboard', 'ajax_save_coach_zoom_settings' ) );
```

Static method on `HL_Frontend_Coach_Dashboard`:

```php
public static function ajax_save_coach_zoom_settings() {
    check_ajax_referer( 'hl_save_coach_zoom_settings', '_nonce' );

    $coach_user_id = absint( wp_unslash( $_POST['coach_user_id'] ?? 0 ) );
    if ( ! $coach_user_id ) {
        wp_send_json_error( array( 'message' => __( 'Missing coach.', 'hl-core' ), 'error_data' => array( 'field' => '' ) ) );
    }

    if ( get_current_user_id() !== $coach_user_id && ! current_user_can( 'manage_hl_core' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hl-core' ) ), 403 );
    }

    $overrides = array();
    foreach ( array( 'waiting_room', 'mute_upon_entry', 'join_before_host' ) as $f ) {
        if ( isset( $_POST[ $f ] ) ) {
            $overrides[ $f ] = ! empty( $_POST[ $f ] ) ? 1 : 0;
        }
    }
    if ( isset( $_POST['alternative_hosts'] ) ) {
        $overrides['alternative_hosts'] = sanitize_textarea_field( wp_unslash( $_POST['alternative_hosts'] ) );
    }

    $reset_fields = array();
    if ( ! empty( $_POST['reset'] ) && is_array( $_POST['reset'] ) ) {
        $allowed = array( 'waiting_room', 'mute_upon_entry', 'join_before_host', 'alternative_hosts' );
        foreach ( $_POST['reset'] as $f ) {
            $f = sanitize_key( wp_unslash( $f ) );
            if ( in_array( $f, $allowed, true ) ) {
                $reset_fields[] = $f;
            }
        }
    }

    if ( ! empty( $overrides ) || ! empty( $reset_fields ) ) {
        $r = HL_Coach_Zoom_Settings_Service::save_coach_overrides(
            $coach_user_id,
            $overrides,
            get_current_user_id(),
            $reset_fields
        );
        if ( is_wp_error( $r ) ) {
            $error_data = $r->get_error_data();
            if ( ! is_array( $error_data ) ) $error_data = array( 'field' => '' );
            wp_send_json_error( array(
                'message'    => $r->get_error_message(),
                'error_code' => $r->get_error_code(),
                'error_data' => $error_data,
            ) );
        }
    }

    wp_send_json_success();
}
```

- [ ] **Step 3: Smoke test the JS interactions**

Visit dashboard as coach (cookie auth required — see `reference_playwright_verify_workflow.md`):
1. Click Edit → modal opens, focus on first focusable element.
2. Toggle a field → caption flips → Reset link appears.
3. Click Reset → toggle reverts; focus returns to toggle.
4. Switch alt-hosts radio → textarea enables → Save → success → reload shows override.
5. Force a server validation error: bad email → red banner with field-level highlight.
6. Click Reset all → styled confirm → Confirm → all rows reset + form submits.
7. Esc closes modal; backdrop click closes modal; Tab cycles focus.
8. Dismiss callout → reload confirms it stays dismissed.
9. Resize to 375px → rows stack vertically; modal becomes full-screen.

---

### Task H3: `assets/css/coach-zoom-settings.css`

- [ ] **Step 1: Create the file**

```css
/* ============================================================================
   Coach Zoom Meeting Settings — tile, modal, callout
   Class prefix: hlczs- (HL Coach Zoom Settings)
   z-indexes 100010+ chosen to clear existing frontend.css overlays (9998-10000)
   ============================================================================ */

/* Tile */
.hlczs-tile {
    background: #fff; border: 1px solid #E5E7EB; border-radius: 12px;
    padding: 24px; margin: 24px 0;
}
.hlczs-tile h3 { margin: 0 0 8px; font-size: 18px; color: #1A2B47; }
.hlczs-tile-summary { margin: 0 0 16px; color: #4B5563; font-size: 14px; }

/* Callout */
.hlczs-callout {
    background: #DBEAFE; border-left: 4px solid #2C7BE5; border-radius: 8px;
    padding: 12px 16px; margin: 16px 0; position: relative;
}
.hlczs-callout p { margin: 0; padding-right: 32px; }
.hlczs-callout-dismiss {
    position: absolute; top: 8px; right: 8px;
    background: transparent; border: 0; cursor: pointer;
    width: 28px; height: 28px; line-height: 1;
    font-size: 20px; color: #6B7280;
}
.hlczs-callout-dismiss:hover { color: #1A2B47; }

/* Modal */
.hlczs-modal-backdrop {
    position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5);
    z-index: 100010;
}
.hlczs-modal {
    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
    background: #fff; border-radius: 12px;
    width: 90%; max-width: 640px; max-height: 90vh; overflow: auto;
    z-index: 100011;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
}
.hlczs-modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 24px; border-bottom: 1px solid #E5E7EB;
}
.hlczs-modal-header h2 { margin: 0; font-size: 20px; color: #1A2B47; }
.hlczs-modal-close {
    background: transparent; border: 0; font-size: 24px; cursor: pointer;
    color: #6B7280; width: 32px; height: 32px; line-height: 1;
}

/* Banner */
.hlczs-banner {
    margin: 16px 24px 0; padding: 12px 16px; border-radius: 6px; font-size: 14px;
}
.hlczs-banner-success { background: #D1FAE5; color: #065F46; border-left: 4px solid #10B981; }
.hlczs-banner-error   { background: #FEE2E2; color: #991B1B; border-left: 4px solid #EF4444; }

/* Rows */
#hlczs-form { padding: 8px 0 16px; }
.hlczs-row {
    display: grid;
    grid-template-columns: 1fr auto 1.6fr;
    align-items: start; gap: 16px;
    padding: 16px 24px; border-bottom: 1px solid #F3F4F6;
}
.hlczs-row-label { font-weight: 600; color: #1A2B47; font-size: 15px; }
.hlczs-row-control { display: flex; align-items: center; }
.hlczs-row-meta { display: flex; flex-direction: column; gap: 4px; }
.hlczs-row-caption { font-size: 13px; color: #6B7280; }
.hlczs-row-reset {
    background: transparent; border: 0; padding: 0; cursor: pointer;
    color: #2C7BE5; font-size: 13px; text-align: left;
}
.hlczs-row-reset:hover { text-decoration: underline; }
.hlczs-row-error { background: #FEF2F2; }
.hlczs-row-error .hlczs-row-caption { color: #991B1B; }

/* Toggle (button[role=switch]) */
.hlczs-toggle {
    background: transparent; border: 0; padding: 0; cursor: pointer;
    display: inline-flex; align-items: center;
}
.hlczs-toggle-track {
    display: inline-block;
    width: 44px; height: 24px; border-radius: 24px;
    background: #D1D5DB; position: relative;
    transition: background 0.15s ease;
}
.hlczs-toggle-thumb {
    position: absolute; top: 2px; left: 2px;
    width: 20px; height: 20px; border-radius: 50%;
    background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    transition: transform 0.15s ease;
}
.hlczs-toggle[aria-pressed="true"] .hlczs-toggle-track { background: #2C7BE5; }
.hlczs-toggle[aria-pressed="true"] .hlczs-toggle-thumb { transform: translateX(20px); }
.hlczs-toggle:focus-visible { outline: 2px solid #2C7BE5; outline-offset: 2px; border-radius: 24px; }

/* Alt-hosts row */
.hlczs-row-althosts fieldset { border: 0; padding: 0; margin: 0; }
.hlczs-row-althosts label { display: inline-block; font-size: 14px; padding: 4px 0; cursor: pointer; }
.hlczs-row-althosts textarea {
    display: block; width: 100%; margin-top: 8px;
    border: 1px solid #D1D5DB; border-radius: 6px; padding: 8px;
    font-family: inherit; font-size: 14px;
}
.hlczs-row-althosts textarea:disabled { background: #F3F4F6; color: #9CA3AF; }
.hlczs-row-althosts textarea.hlczs-invalid { border-color: #EF4444; box-shadow: 0 0 0 1px #EF4444; }

/* Read-only admin-only section */
.hlczs-readonly {
    margin: 8px 24px 0; padding: 16px;
    background: #F9FAFB; border-radius: 8px; border: 1px solid #E5E7EB;
}
.hlczs-readonly h3 { margin: 0 0 8px; font-size: 14px; color: #374151; }
.hlczs-readonly-help { margin: 0 0 12px; font-size: 13px; color: #6B7280; }
.hlczs-readonly ul { margin: 0; padding: 0 0 0 16px; font-size: 14px; color: #374151; }
.hlczs-readonly li { margin-bottom: 4px; }

/* Footer */
.hlczs-modal-footer {
    display: flex; justify-content: space-between; gap: 12px;
    padding: 16px 24px; border-top: 1px solid #E5E7EB;
    background: #F9FAFB; border-radius: 0 0 12px 12px;
}

/* Reset-all confirm modal-in-modal */
.hlczs-confirm-backdrop {
    position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 100020;
}
.hlczs-confirm-modal {
    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
    background: #fff; border-radius: 12px; padding: 24px; max-width: 400px;
    z-index: 100021;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
}
.hlczs-confirm-modal h3 { margin: 0 0 12px; font-size: 18px; color: #1A2B47; }
.hlczs-confirm-modal p { margin: 0 0 16px; color: #4B5563; }
.hlczs-confirm-actions { display: flex; justify-content: flex-end; gap: 8px; }

/* Mobile breakpoints */
@media (max-width: 600px) {
    .hlczs-modal {
        width: 100%; height: 100vh; max-height: none;
        top: 0; left: 0; transform: none; border-radius: 0;
    }
}
@media (max-width: 480px) {
    .hlczs-row {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .hlczs-row-meta { flex-direction: row; justify-content: space-between; align-items: center; }
}

/* Screen-reader-only utility (in case theme doesn't define it) */
.hlczs-modal .screen-reader-text {
    position: absolute !important; clip: rect(1px,1px,1px,1px);
    width: 1px; height: 1px; overflow: hidden;
}
```

- [ ] **Step 2: Combined commit for H1 + H2 + H3**

```bash
git add includes/frontend/class-hl-frontend-coach-dashboard.php hl-core.php \
        assets/js/coach-zoom-settings.js assets/css/coach-zoom-settings.css \
        includes/frontend/class-hl-shortcodes.php \
        includes/services/class-hl-coach-zoom-settings-service.php
git commit -m "feat(coach-zoom): coach modal interactivity + styling + a11y"
```

---

## Section I — WP-Cron alt-hosts notification + finalization

Goal: Wire deferred email notification, update STATUS.md and README.md, run all tests, prepare PR. (Version bump already done in §A1 to keep cache-bust working through intermediate deploys.)

### Task I1: WP-Cron handler `hl_notify_alt_hosts_change`

**Files:**
- Modify: `hl-core.php` — register the cron action.
- Modify: `includes/services/class-hl-coach-zoom-settings-service.php` — add the static handler.

The dispatch is already in `save_coach_overrides()` (per §B5).

- [ ] **Step 1: Register the cron action** in `HL_Core::register_hooks()`:

```php
add_action( 'hl_notify_alt_hosts_change',
    array( 'HL_Coach_Zoom_Settings_Service', 'cron_notify_alt_hosts_change' ),
    10, 4 );
```

- [ ] **Step 2: Add the handler on the service class**

```php
/**
 * WP-Cron handler — emails admins when a coach changes alternative_hosts.
 * Recipients: all manage_hl_core users, capped at 50 defensively.
 */
public static function cron_notify_alt_hosts_change( $coach_user_id, $actor_user_id, $before, $after ) {
    $coach = get_userdata( $coach_user_id );
    if ( ! $coach ) return; // user deleted between schedule + fire — drop silently

    $actor      = get_userdata( $actor_user_id );
    $actor_name = $actor ? $actor->display_name : __( 'Unknown user', 'hl-core' );
    $coach_name = $coach->display_name;

    $recipients = get_users( array(
        'capability__in' => array( 'manage_hl_core' ),
        'fields'         => array( 'user_email' ),
        'number'         => 50,
    ) );
    $emails = array_filter( wp_list_pluck( $recipients, 'user_email' ) );
    if ( empty( $emails ) ) return;

    $subject = sprintf(
        /* translators: %s = coach display name */
        __( '[HL] %s updated their Zoom alternative hosts', 'hl-core' ),
        $coach_name
    );

    $body  = sprintf( __( '%s updated their alternative-hosts list.', 'hl-core' ), $coach_name ) . "\n\n";
    $body .= sprintf( __( 'Edited by: %s', 'hl-core' ), $actor_name ) . "\n";
    $body .= sprintf( __( 'Before: %s', 'hl-core' ), $before === '' ? __( '(none)', 'hl-core' ) : $before ) . "\n";
    $body .= sprintf( __( 'After:  %s', 'hl-core' ), $after  === '' ? __( '(none)', 'hl-core' ) : $after  ) . "\n";

    wp_mail( $emails, $subject, $body );
}
```

- [ ] **Step 3: Smoke test**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress && wp eval "
do_action( \"hl_notify_alt_hosts_change\", 1508, 1, \"old@a.com\", \"new@b.com\" );
"'
```

Verify the AJAX flow dispatches the cron event:
1. Set alt_hosts override via the modal as a coach.
2. `wp cron event list | grep hl_notify_alt_hosts_change` → confirm scheduled.
3. `wp cron event run hl_notify_alt_hosts_change` → email sends.

- [ ] **Step 4: Commit**

```bash
git add hl-core.php includes/services/class-hl-coach-zoom-settings-service.php
git commit -m "feat(coach-zoom): WP-Cron alt_hosts change notification"
```

---

### Task I2: Update `STATUS.md` and `README.md`

**Files:** `STATUS.md`, `README.md`

- [ ] **Step 1: Find anchors**

```bash
grep -n "^## Build Queue\|^### Done\|^### In Progress\|^### Pending" STATUS.md | head
grep -n "^## What's Implemented\|^### Scheduling\|^### Coaching\|^## File Tree\|^## Architecture" README.md | head
```

- [ ] **Step 2: Update STATUS.md** — insert under "Build Queue" section after the most recent ticket entry:

```markdown
- [x] Coach Zoom Meeting Settings (ticket #31) — admin defaults + per-coach overrides for waiting room, mute on entry, join before host, alternative hosts; admin-only passcode + Zoom sign-in toggles; Retry Zoom path with idempotency lock; preflight alt_hosts validation; mentor "link coming shortly" fallback. Recording + AI Companion remain Zoom-account-level. (See `docs/superpowers/specs/2026-04-22-coach-zoom-meeting-settings.md`.)
```

- [ ] **Step 3: Update README.md** — insert under "What's Implemented → Scheduling" subsection. Add to the file tree:

```
includes/services/
  class-hl-coach-zoom-settings-service.php  (new)
includes/frontend/views/
  coach-zoom-settings-modal.php             (new)
assets/js/
  coach-zoom-settings.js                    (new)
  admin-coach-zoom-retry.js                 (new)
assets/css/
  coach-zoom-settings.css                   (new)
```

- [ ] **Step 4: Commit**

```bash
git add STATUS.md README.md
git commit -m "docs(coach-zoom): update STATUS.md + README.md per CLAUDE.md Rule #3"
```

---

### Task I3: Final test run (PHP + UI smoke)

- [ ] **Step 1: Re-run every test snippet**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress && \
  for f in test-coach-zoom-validate test-coach-zoom-admin-defaults test-coach-zoom-get-overrides test-coach-zoom-save-overrides test-coach-zoom-resolve test-coach-zoom-preflight test-coach-zoom-build-payload test-coach-zoom-delete-user test-coach-zoom-retry; do \
    echo "=== $f ==="; \
    wp eval-file wp-content/plugins/hl-core/bin/test-snippets/$f.php; \
  done'
```

Expected: every snippet ends with `DONE` and zero `FAIL:` lines anywhere. Any failure blocks the merge.

- [ ] **Step 2: UI smoke — Playwright per `reference_playwright_verify_workflow.md`**

Cover:
- Admin defaults page: load, toggle, save, persist (Task F1).
- Coach Overrides Overview table: pagination + sticky header + filter (Task F2).
- Coach modal: open, toggle, reset link with focus return, alt-hosts radio mode, save, error rendering, reset-all confirm modal (Task H2).
- Retry button on a NULL session: success + already_has_meeting paths (Task F3).
- Mobile breakpoints: 375px stack vertically; 600px full-screen overlay.

- [ ] **Step 3: Manual integration tests** (Zoom API live calls)

1. Save admin defaults with a typo'd alt_hosts → **preflight HARD-REJECTS the save** (no DB write; coach sees field-level error).
2. Save admin defaults with valid `clove@housmanlearning.com` → preflight succeeds → meeting created and deleted in Zoom → save persists.
3. Book a real coaching session → confirm Zoom meeting settings match resolved values.
4. Reschedule the session → confirm new meeting reflects current resolved settings.
5. Force a NULL meeting_url session → click Retry → confirm meeting created + Outlook event updated + mentor receives "Your Zoom link is ready" email.
6. `meeting_authentication=true` test against Chris's actual Zoom account → confirm no "authentication_option required" error.

- [ ] **Step 4: Final commit (if any test-fix tweaks were needed)**

```bash
git add -A
git commit -m "test(coach-zoom): final smoke test fixes"
```

---

### Task I4: Open the Pull Request

- [ ] **Step 1: Push the branch**

```bash
git push origin feature/ticket-31-coach-zoom-settings
```

- [ ] **Step 2: Open PR**

```bash
gh pr create --title "feat(coach-zoom): per-coach Zoom meeting settings (ticket #31)" --body "$(cat <<'EOF'
## Summary
- Resolves ticket #31 (Christopher Love, prod, critical).
- Adds per-coach Zoom meeting settings (waiting room, mute on entry, join before host, alternative hosts) with admin defaults + per-coach overrides.
- Admin-only fields (passcode, Zoom sign-in) under Advanced disclosure.
- Recording + AI Companion stay configured at the Zoom account level (out of LMS scope).
- Each coaching session keeps a unique Zoom meeting ID/URL → unblocks future recording-attribution work.
- Mandatory preflight validation of alternative_hosts (with debounce + transient inflight lock).
- New "Retry Zoom creation" admin action for failed-Zoom sessions, with idempotency lock + Outlook event update.
- Mentor email no longer goes blank on Zoom failure.
- Single-toggle UX with reset-link + a11y (aria-pressed, aria-live); admin-only fields shown read-only on coach modal.
- Schema rev 44 → 45.

## Test plan
- [ ] All 9 PHP test snippets pass (Task I3 Step 1)
- [ ] Playwright UI smoke passes (admin defaults, overview table, coach modal, retry button, mobile)
- [ ] Manual: alt_hosts preflight HARD-REJECTS typo'd email, allows valid
- [ ] Manual: real booking → Zoom settings match resolved values
- [ ] Manual: reschedule picks up new settings
- [ ] Manual: retry button creates meeting + updates Outlook + sends "link ready" email
- [ ] Manual: meeting_authentication=true works against Chris's Zoom account

## Spec & plan
- Spec: docs/superpowers/specs/2026-04-22-coach-zoom-meeting-settings.md (v0.4 — multi-agent reviewed)
- Plan: docs/superpowers/plans/2026-04-22-coach-zoom-meeting-settings.md (sections A–I)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Mark ticket #31 as ready_for_test on prod**

```bash
ssh -p 65002 u665917738@145.223.76.150 \
  'cd ~/domains/academy.housmanlearning.com/public_html && wp db query "
    UPDATE wp_hl_ticket SET status='\''ready_for_test'\'', status_updated_at=NOW(), updated_at=NOW()
    WHERE ticket_id = 31"'
```

(This writes to **prod**, not test. Final transition to `resolved` happens after Chris approves on prod, per the Feature Tracker workflow.)

---

## Done.

The full plan is ~21 task commits across 9 sections. Estimated implementation effort: 2-3 focused days.


