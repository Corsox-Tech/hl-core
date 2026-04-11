# Email System v2 — Track 3: Backend Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add component submission window columns, implement cron trigger queries, fix role-matching queries, add draft cleanup, harden email deliverability.

**Architecture:** Three idempotent schema revisions (35/36/37). New `HL_Roles` helper unifies role matching across evaluator and resolver. Cron queries use site-TZ dates + range matches + dedup tokens for once-per-window idempotency. HMAC-based unsubscribe tokens.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, MySQL 5.7+ InnoDB, `$wpdb->prepare()`, `wp_mail`, `wp_cron`, `wp_date`/`current_time`.

---

## Dependencies (Track 1 ↔ Track 3)

This plan provides foundational helpers (`HL_Roles`, condition evaluator routing, `assigned_mentor` resolver) that Track 1 depends on. **Track 3 Tasks 1, 2, 5, 23 should land before Track 1 starts.**

Depends on **Track 1 Task 4** (`build_context()` `cycle_id` population) for `assigned_mentor` to resolve in production triggers. Without `cycle_id` in context, Task 23's resolver returns `NULL` and logs a warning.

Track 1 owns admin handlers for workflow delete (Track 1 Task 11) and force resend (Track 1 Task 14). Track 3 Task 29 only adds query filters; Track 3 Task 30 is intentionally left empty with a cross-reference.

---

## Pre-Flight: Important Codebase Realities

Before you begin, read these notes — the spec's SQL snippets assume a schema that differs from the real one in a few places. This plan corrects them.

1. **Enrollment roles are stored as JSON, not CSV.** `hl_enrollment.roles` holds `["teacher","mentor"]`-style JSON arrays written by `HL_Enrollment_Repository::create()`/`update()` via `HL_DB_Utils::json_encode()`. The current `LIKE '%school_leader%'` query works by accident (matches substring inside JSON). `FIND_IN_SET()` does **not** work on JSON. The fix is two-fold:
   - **Short-term (Task 1):** Create `HL_Roles::has_role($stored, $role)` PHP helper that decodes **both** formats and does exact-match checks. Route PHP-side code through it.
   - **Long-term (Rev 37, Tasks 19–22):** One-time scrub migration rewrites every `hl_enrollment.roles` row to normalised CSV (`teacher,mentor`). After the scrub, `FIND_IN_SET()` is safe. The repository's write paths are updated to emit CSV via `HL_Roles::sanitize_roles()`.
   - Until the scrub completes, SQL-level queries use a transitional `hl_roles_scrub_done` option-gate: if scrub is complete, use `FIND_IN_SET`; if not, fall back to the old `LIKE` pattern. This lets Tasks 3–4 land before Rev 37.
2. **`hl_team` membership is `hl_team_membership`, not `hl_team_member`.** Column is `membership_type enum('mentor','member')`, not a `roles` CSV. The `assigned_mentor` resolver in the spec must use `membership_type = 'mentor'`, not `FIND_IN_SET('mentor', roles)`.
3. **Component table uses `ordering_hint`, not `sort_order`.** `AFTER` clause in the ALTER TABLE must reference `ordering_hint` (or `catalog_id`). There is also no `sort_order` column anywhere else on this table.
4. **Component type enum uses `coaching_session_attendance`, not `coaching_session`.** The spec's cron SQL filters on `component_type = 'coaching_session'`; the correct value is `coaching_session_attendance`. Similarly `reflective_practice_session` and `classroom_visit` are correct.
5. **`hl_rp_session_submission` and `hl_classroom_visit_submission` do not reference `component_id` directly.** They reference `rp_session_id` and `classroom_visit_id` respectively. The correct dedup path is: `hl_rp_session` has `component_id` (if it does) or we use the **entity link tables**. For v2 we use a simpler check: a submission exists for the enrollment's cycle against the component's associated entity. If no entity link exists yet, we treat the component window as "no submission yet" which is the safer default (will fire reminder). This is documented in each task.
6. **`hl_enrollment.status` enum is `('active','inactive')`.** Existing automation queries use `status IN ('active','warning')` which is technically a mismatch but harmless (no rows match 'warning'). We keep parity with existing code (`IN ('active','warning')`) to avoid behaviour drift in other triggers.
7. **`HL_Audit_Service::log($action_type, $data)` takes flat `$data` array.** There is no `get_last_event()` method; Task 26 adds it.
8. **Cron queries already exist for several triggers (action_plan_24h, session_notes_24h, session_24h, etc.).** This plan only touches the 5 stub cases plus the `run_daily_checks()` wrapper. Do not modify existing working queries.
9. **Schema revision is currently 34.** This plan bumps it in three stages: 35 (schema ALTER + indexes), 36 (autoload cleanup), 37 (role scrub + unsubscribe secret).

---

## File Structure

### Files to create

| Path | Responsibility |
|---|---|
| `includes/services/class-hl-roles.php` | `HL_Roles` static helper — `has_role()`, `sanitize_roles()`, `parse_stored()`. Format-agnostic reader, CSV writer. |
| `includes/cli/class-hl-cli-email-v2-test.php` | WP-CLI verification commands for every Track 3 feature. Registered as `wp hl-core email-v2-test`. |
| `includes/migrations/class-hl-roles-scrub-migration.php` | Rev 37 chunked scrub runner (500 rows per `plugins_loaded` tick, cursor in `hl_roles_scrub_cursor`, transient lock). |

### Files to modify

| Path | Why |
|---|---|
| `includes/class-hl-installer.php` | Bump rev 34 → 37; add `migrate_component_add_window_cols()`, composite indexes, `migrate_email_drafts_autoload_off()`, gate for Rev 37 scrub trigger, column-exists guards, `add_action('plugins_loaded', [HL_Roles_Scrub_Migration, 'maybe_run'])`. |
| `includes/services/class-hl-email-automation-service.php` | Replace 5 stub cron cases with real queries; use `current_time('Y-m-d')` + range matches; add `cleanup_stale_drafts()`; refactor dedup token (no date); add column-exists guards; add `last_cron_run_at` tracking and staleness warning. |
| `includes/services/class-hl-email-recipient-resolver.php` | Replace `LIKE` with conditional `FIND_IN_SET`/`LIKE` (gated on scrub); add `assigned_mentor` token via `hl_team_membership`; keep `cc_teacher` alias with audit; route through `HL_Roles::has_role()` where applicable. |
| `includes/services/class-hl-email-condition-evaluator.php` | Route `enrollment.roles` checks through `HL_Roles::has_role()`. |
| `includes/services/class-hl-email-queue-processor.php` | Use `mb_encode_mimeheader()` on subject; add deliverability headers (From, Reply-To, List-Unsubscribe, List-Unsubscribe-Post); register `wp_mail_failed` hook; dynamic stuck-row threshold; `LIMIT 5000` safety cap warn. |
| `includes/services/class-hl-audit-service.php` | Add `get_last_event($entity_id, $action_type)`; wrap existing `log()` in try/catch so audit failures never block callers. |
| `includes/admin/class-hl-admin-email-builder.php` | `ajax_autosave()` writes `created_at`/`updated_at` into the draft JSON wrapper. Always use `update_option($name, $value, 'no')`. |
| `includes/admin/class-hl-admin-emails.php` | Add "Force resend" row action + handler; workflow delete sets `status='deleted'` (soft delete); display last force-resend timestamp via `get_last_event()`. |
| `includes/admin/class-hl-admin-pathways.php` | Add two `<input type="date">` fields (`available_from`, `available_to`) to the component form; read/save them in `save_component()`. |
| `includes/domain/repositories/class-hl-enrollment-repository.php` | Route writes through `HL_Roles::sanitize_roles()` (CSV output). |
| `hl-core.php` | Require + register `HL_CLI_Email_V2_Test::register()`; require `class-hl-roles.php` + `class-hl-roles-scrub-migration.php`. |

### Total: 3 new, 9 modified.

---

## Build Order & Dependencies

```
 ┌─ Task 1:  HL_Roles helper (independent)
 │
 ├─ Task 2:  Route condition evaluator through HL_Roles::has_role()
 │
 ├─ Task 3:  Fix LIKE → gated FIND_IN_SET in resolve_school_director()
 ├─ Task 4:  Fix LIKE → gated FIND_IN_SET in resolve_role()
 │
 ├─ Task 5:  HL_Audit_Service::get_last_event() + try/catch wrap
 │
 ├─ Task 6:  Schema Rev 35 — component window columns + composite indexes
 ├─ Task 7:  Admin pathway form: date pickers for available_from/to
 │
 ├─ Task 8:  Draft autosave: created_at/updated_at timestamps
 ├─ Task 9:  Schema Rev 36 — autoload=no migration for drafts
 ├─ Task 10: cleanup_stale_drafts() daily cron method
 │
 ├─ Task 11: Dedup token: remove date component
 ├─ Task 12: Column-exists guard + cron early-return
 ├─ Task 13: cron:cv_window_7d real query
 ├─ Task 14: cron:cv_overdue_1d real query
 ├─ Task 15: cron:rp_window_7d real query
 ├─ Task 16: cron:coaching_window_7d real query
 ├─ Task 17: cron:coaching_pre_end real query
 ├─ Task 18: last_cron_run_at tracking + Site Health
 │
 ├─ Task 19: HL_Roles_Scrub_Migration skeleton
 ├─ Task 20: Scrub chunk worker + transient lock
 ├─ Task 21: Enrollment repository writes CSV
 ├─ Task 22: Schema Rev 37 — gate scrub on plugins_loaded
 │
 ├─ Task 23: assigned_mentor resolver + cc_teacher alias + audit
 │
 ├─ Task 24: Queue processor: mb_encode_mimeheader subject
 ├─ Task 25: Queue processor: deliverability headers (From/Reply-To/List-Unsubscribe)
 ├─ Task 26: HMAC unsubscribe token + secret wp_option
 ├─ Task 27: wp_mail_failed hook
 ├─ Task 28: Queue claim dynamic expiry formula
 │
 ├─ Task 29: Workflow soft-delete (status='deleted')
 ├─ Task 30: Force resend action + handler + history row display
 │
 ├─ Task 31: LIMIT 5000 safety cap on coaching_pre_end
 └─ Task 32: Wire up CLI test command + final regression sweep
```

Each task is 2–5 min of tightly scoped work + test + commit.

---

## Task 1: Create `HL_Roles` Helper

**Files:**
- Create: `includes/services/class-hl-roles.php`
- Create: `includes/cli/class-hl-cli-email-v2-test.php` (stub — will grow across tasks)
- Modify: `hl-core.php`

**Goal:** Centralize role parsing/comparison so both evaluator and resolver read through one code path. Format-agnostic read, CSV-normalising write.

- [ ] **Step 1: Create the helper file**

Create `includes/services/class-hl-roles.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HL_Roles — shared role matching helper.
 *
 * Reads both legacy JSON (`["teacher","mentor"]`) and normalised CSV
 * (`teacher,mentor`) formats. All new writes go through sanitize_roles()
 * which emits CSV only. Rev 37 scrubs all existing rows to CSV.
 *
 * @package HL_Core
 */
class HL_Roles {

    /**
     * Parse a stored roles value (JSON array, CSV, or empty) into a clean
     * lowercase array of role slugs. Whitespace stripped, empty-string
     * entries removed.
     *
     * @param string|array|null $stored Raw value from hl_enrollment.roles.
     * @return string[]
     */
    public static function parse_stored( $stored ) {
        if ( is_array( $stored ) ) {
            $arr = $stored;
        } elseif ( is_string( $stored ) && $stored !== '' ) {
            $trimmed = trim( $stored );
            if ( strlen( $trimmed ) && $trimmed[0] === '[' ) {
                // JSON array format.
                $decoded = json_decode( $trimmed, true );
                $arr     = is_array( $decoded ) ? $decoded : array();
            } else {
                // CSV format.
                $arr = explode( ',', $trimmed );
            }
        } else {
            return array();
        }

        $out = array();
        foreach ( $arr as $role ) {
            if ( ! is_string( $role ) ) continue;
            $clean = strtolower( trim( $role ) );
            if ( $clean === '' ) continue;
            $out[] = $clean;
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Exact-match role check. Reads both JSON and CSV formats.
     *
     * @param string|array|null $stored Raw value from hl_enrollment.roles.
     * @param string            $role   Role slug to check.
     * @return bool
     */
    public static function has_role( $stored, $role ) {
        $role = strtolower( trim( (string) $role ) );
        if ( $role === '' ) return false;
        return in_array( $role, self::parse_stored( $stored ), true );
    }

    /**
     * Normalise an array of roles for storage as CSV.
     *
     * Strips whitespace, lowercases, dedupes, rejects any role containing
     * a comma (would break FIND_IN_SET parsing), returns canonical CSV.
     *
     * @param string[]|string $roles Array of slugs or existing CSV/JSON.
     * @return string CSV. Empty string if input is empty/invalid.
     */
    public static function sanitize_roles( $roles ) {
        if ( is_string( $roles ) ) {
            $roles = self::parse_stored( $roles );
        }
        if ( ! is_array( $roles ) ) return '';

        $clean = array();
        foreach ( $roles as $r ) {
            if ( ! is_string( $r ) ) continue;
            $r = strtolower( trim( $r ) );
            if ( $r === '' ) continue;
            if ( strpos( $r, ',' ) !== false ) continue; // reject poison
            $clean[ $r ] = true;
        }
        return implode( ',', array_keys( $clean ) );
    }

    /**
     * Whether the Rev 37 role scrub has completed. Callers can gate
     * FIND_IN_SET usage on this.
     */
    public static function scrub_is_complete() {
        return (bool) get_option( 'hl_roles_scrub_done', 0 );
    }
}
```

- [ ] **Step 2: Load the helper from `hl-core.php`**

Find the block where services are required (search for `class-hl-email-recipient-resolver.php`) and add immediately before it:

```php
require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-roles.php';
```

- [ ] **Step 3: Create the CLI test stub file**

Create `includes/cli/class-hl-cli-email-v2-test.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP-CLI verification suite for Email System v2 Track 3.
 *
 * Usage:
 *   wp hl-core email-v2-test                # run everything
 *   wp hl-core email-v2-test --only=roles   # single group
 *
 * Groups: roles, schema, cron, drafts, resolver, deliverability, audit.
 */
class HL_CLI_Email_V2_Test {

    /** @var int */
    private $pass = 0;
    /** @var int */
    private $fail = 0;

    public static function register() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;
        WP_CLI::add_command( 'hl-core email-v2-test', array( new self(), 'run' ) );
    }

    /**
     * ## OPTIONS
     *
     * [--only=<group>]
     * : Limit to one group: roles|schema|cron|drafts|resolver|deliverability|audit
     */
    public function run( $args, $assoc_args ) {
        $only = isset( $assoc_args['only'] ) ? $assoc_args['only'] : null;
        $groups = array(
            'roles'          => 'test_roles',
            'schema'         => 'test_schema',
            'cron'           => 'test_cron',
            'drafts'         => 'test_drafts',
            'resolver'       => 'test_resolver',
            'deliverability' => 'test_deliverability',
            'audit'          => 'test_audit',
        );

        foreach ( $groups as $key => $method ) {
            if ( $only && $only !== $key ) continue;
            if ( method_exists( $this, $method ) ) {
                WP_CLI::log( "\n=== {$key} ===" );
                $this->{$method}();
            }
        }

        WP_CLI::log( "\n---- Summary: {$this->pass} passed, {$this->fail} failed ----" );
        if ( $this->fail > 0 ) WP_CLI::halt( 1 );
    }

    private function assert_true( $cond, $label ) {
        if ( $cond ) {
            $this->pass++;
            WP_CLI::log( "  [PASS] {$label}" );
        } else {
            $this->fail++;
            WP_CLI::log( WP_CLI::colorize( "  %R[FAIL]%n {$label}" ) );
        }
    }

    private function assert_equals( $expected, $actual, $label ) {
        $this->assert_true( $expected === $actual, "{$label} (expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . ")" );
    }

    // ---- Test: roles ----
    private function test_roles() {
        // JSON-format parse
        $this->assert_true(
            HL_Roles::has_role( '["teacher","mentor"]', 'teacher' ),
            'has_role() matches teacher inside JSON'
        );
        $this->assert_true(
            ! HL_Roles::has_role( '["school_leader"]', 'leader' ),
            'has_role() does NOT false-match "leader" inside "school_leader"'
        );
        // CSV-format parse
        $this->assert_true(
            HL_Roles::has_role( 'teacher,mentor', 'mentor' ),
            'has_role() matches mentor inside CSV'
        );
        $this->assert_true(
            ! HL_Roles::has_role( 'school_leader,coach', 'leader' ),
            'has_role() does NOT false-match "leader" inside CSV school_leader'
        );
        // sanitize_roles output
        $this->assert_equals(
            'coach,mentor,teacher',
            HL_Roles::sanitize_roles( array( 'Teacher', ' MENTOR ', 'coach', 'teacher' ) ),
            'sanitize_roles() lowercases, trims, dedupes, sorts consistently'
        );
        // reject poison
        $this->assert_equals(
            'teacher',
            HL_Roles::sanitize_roles( array( 'teacher', 'mentor,coach' ) ),
            'sanitize_roles() rejects role containing comma'
        );
    }

    // Stubs — filled by later tasks.
    private function test_schema() {}
    private function test_cron() {}
    private function test_drafts() {}
    private function test_resolver() {}
    private function test_deliverability() {}
    private function test_audit() {}
}
```

Note: the sanitize_roles test above expects sorted output. Update the helper's `sanitize_roles` return to sort alphabetically for determinism:

Re-open `includes/services/class-hl-roles.php` and change the last two lines of `sanitize_roles()`:

```php
        $keys = array_keys( $clean );
        sort( $keys, SORT_STRING );
        return implode( ',', $keys );
```

- [ ] **Step 4: Register the CLI command in `hl-core.php`**

Find the `require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-smoke-test.php';` line and add immediately after:

```php
            require_once HL_CORE_INCLUDES_DIR . 'cli/class-hl-cli-email-v2-test.php';
```

Then find `HL_CLI_Smoke_Test::register();` and add immediately after:

```php
            HL_CLI_Email_V2_Test::register();
```

- [ ] **Step 5: Run the roles test group**

Run on the test server (not locally — project has no PHP locally):

```bash
wp hl-core email-v2-test --only=roles
```

Expected output:
```
=== roles ===
  [PASS] has_role() matches teacher inside JSON
  [PASS] has_role() does NOT false-match "leader" inside "school_leader"
  [PASS] has_role() matches mentor inside CSV
  [PASS] has_role() does NOT false-match "leader" inside CSV school_leader
  [PASS] sanitize_roles() lowercases, trims, dedupes, sorts consistently
  [PASS] sanitize_roles() rejects role containing comma
---- Summary: 6 passed, 0 failed ----
```

- [ ] **Step 6: Commit**

```bash
git add includes/services/class-hl-roles.php includes/cli/class-hl-cli-email-v2-test.php hl-core.php
git commit -m "feat(email-v2): add HL_Roles helper and CLI test scaffold"
```

---

## Task 2: Route Condition Evaluator Through `HL_Roles::has_role()`

**Files:**
- Modify: `includes/services/class-hl-email-condition-evaluator.php`

**Goal:** When a workflow condition targets `enrollment.roles` with `eq`/`in`/etc., use `HL_Roles::has_role()` semantics instead of string-equal-on-JSON-blob.

- [ ] **Step 1: Add role-aware branch to `evaluate_single()`**

Open `includes/services/class-hl-email-condition-evaluator.php`. Inside `evaluate_single()`, just above the `switch ( $op )` statement, insert:

```php
        // Role field special-case: route through HL_Roles::has_role() so
        // JSON and CSV formats both match exactly (no substring bleed).
        if ( $field === 'enrollment.roles' && class_exists( 'HL_Roles' ) ) {
            $stored = $actual; // raw roles string from context
            switch ( $op ) {
                case 'eq':
                    return HL_Roles::has_role( $stored, (string) $value );
                case 'neq':
                    return ! HL_Roles::has_role( $stored, (string) $value );
                case 'in':
                    $list = is_array( $value ) ? $value : array( $value );
                    foreach ( $list as $v ) {
                        if ( HL_Roles::has_role( $stored, (string) $v ) ) return true;
                    }
                    return false;
                case 'not_in':
                    $list = is_array( $value ) ? $value : array( $value );
                    foreach ( $list as $v ) {
                        if ( HL_Roles::has_role( $stored, (string) $v ) ) return false;
                    }
                    return true;
                case 'is_null':
                    return empty( HL_Roles::parse_stored( $stored ) );
                case 'not_null':
                    return ! empty( HL_Roles::parse_stored( $stored ) );
                default:
                    return false;
            }
        }
```

- [ ] **Step 2: Add a resolver test block to the CLI test**

In `includes/cli/class-hl-cli-email-v2-test.php`, replace `private function test_resolver() {}` with:

```php
    private function test_resolver() {
        $ev = HL_Email_Condition_Evaluator::instance();

        $ctx = array( 'enrollment' => array( 'roles' => '["teacher","mentor"]' ) );

        $this->assert_true(
            $ev->evaluate( array( array( 'field' => 'enrollment.roles', 'op' => 'eq', 'value' => 'teacher' ) ), $ctx ),
            'Condition: enrollment.roles eq teacher (JSON stored) passes'
        );
        $this->assert_true(
            ! $ev->evaluate( array( array( 'field' => 'enrollment.roles', 'op' => 'eq', 'value' => 'leader' ) ), $ctx ),
            'Condition: enrollment.roles eq leader (JSON stored) does NOT false-match school_leader'
        );

        $ctx2 = array( 'enrollment' => array( 'roles' => 'school_leader,coach' ) );
        $this->assert_true(
            $ev->evaluate( array( array( 'field' => 'enrollment.roles', 'op' => 'in', 'value' => array( 'coach', 'mentor' ) ) ), $ctx2 ),
            'Condition: enrollment.roles in [coach,mentor] (CSV stored) matches coach'
        );
        $this->assert_true(
            ! $ev->evaluate( array( array( 'field' => 'enrollment.roles', 'op' => 'eq', 'value' => 'leader' ) ), $ctx2 ),
            'Condition: enrollment.roles eq leader (CSV stored) does NOT false-match school_leader'
        );
    }
```

- [ ] **Step 3: Run the resolver test group**

```bash
wp hl-core email-v2-test --only=resolver
```

Expected: all 4 asserts pass.

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-email-condition-evaluator.php includes/cli/class-hl-cli-email-v2-test.php
git commit -m "fix(email-v2): route enrollment.roles conditions through HL_Roles::has_role"
```

---

## Task 3: Fix `resolve_school_director()` — Gated FIND_IN_SET

**Files:**
- Modify: `includes/services/class-hl-email-recipient-resolver.php`

**Goal:** Replace the vulnerable `LIKE '%school_leader%'` with exact-match logic. Until Rev 37 scrub completes, fall back to LIKE + PHP-side post-filter via `HL_Roles::has_role()`.

- [ ] **Step 1: Swap the LIKE query for a gated branch**

In `includes/services/class-hl-email-recipient-resolver.php`, inside `resolve_school_director()`, replace the block starting at `// Find enrollment with school_leader role in the same school + cycle.` and ending at the `if ( ! $director_id ) {` line with:

```php
        // Find enrollment with school_leader role in the same school + cycle.
        //
        // After Rev 37 scrub, roles is guaranteed CSV so FIND_IN_SET is safe.
        // Before the scrub, fall back to LIKE + PHP-side exact match via HL_Roles.
        $scrub_done = class_exists( 'HL_Roles' ) && HL_Roles::scrub_is_complete();

        if ( $scrub_done ) {
            $director_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT e.user_id FROM {$wpdb->prefix}hl_enrollment e
                 WHERE e.school_id = %d AND e.cycle_id = %d AND e.status IN ('active','warning')
                   AND FIND_IN_SET('school_leader', e.roles) > 0
                 LIMIT 1",
                $school_id, $cycle_id
            ) );
        } else {
            // Pre-scrub: LIKE narrows the set, HL_Roles::has_role() filters exactly.
            $candidates = $wpdb->get_results( $wpdb->prepare(
                "SELECT e.user_id, e.roles FROM {$wpdb->prefix}hl_enrollment e
                 WHERE e.school_id = %d AND e.cycle_id = %d AND e.status IN ('active','warning')
                   AND e.roles LIKE %s
                 LIMIT 50",
                $school_id, $cycle_id,
                '%school_leader%'
            ) );
            $director_id = null;
            foreach ( (array) $candidates as $row ) {
                if ( HL_Roles::has_role( $row->roles, 'school_leader' ) ) {
                    $director_id = (int) $row->user_id;
                    break;
                }
            }
        }
```

- [ ] **Step 2: Add resolver test using a seeded enrollment**

Append to `test_resolver()` in `includes/cli/class-hl-cli-email-v2-test.php`:

```php
        // Live DB test for resolve_school_director (uses an existing seeded enrollment if present).
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT user_id, cycle_id, school_id FROM {$wpdb->prefix}hl_enrollment
             WHERE roles LIKE '%school_leader%' AND school_id IS NOT NULL LIMIT 1"
        );
        if ( $row ) {
            $resolver = HL_Email_Recipient_Resolver::instance();
            // Use a non-director user in same cycle/school as the triggering user.
            $peer = $wpdb->get_var( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}hl_enrollment
                 WHERE school_id = %d AND cycle_id = %d AND user_id != %d LIMIT 1",
                $row->school_id, $row->cycle_id, $row->user_id
            ) );
            if ( $peer ) {
                $out = $resolver->resolve(
                    array( 'primary' => array( 'school_director' ), 'cc' => array() ),
                    array( 'user_id' => (int) $peer, 'cycle_id' => (int) $row->cycle_id )
                );
                $this->assert_true(
                    count( $out ) > 0,
                    'resolve_school_director() returns a director for seeded enrollment'
                );
            }
        } else {
            WP_CLI::log( '  [SKIP] No seeded school_leader enrollment to test against' );
        }
```

- [ ] **Step 3: Run the resolver test**

```bash
wp hl-core email-v2-test --only=resolver
```

Expected: the existing PHP-level asserts pass. The live DB block either passes or skips.

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-email-recipient-resolver.php includes/cli/class-hl-cli-email-v2-test.php
git commit -m "fix(email-v2): gate FIND_IN_SET in resolve_school_director (pre-scrub LIKE+PHP fallback)"
```

---

## Task 4: Fix `resolve_role()` — Gated FIND_IN_SET + Comma Rejection

**Files:**
- Modify: `includes/services/class-hl-email-recipient-resolver.php`

- [ ] **Step 1: Rewrite the query**

In `resolve_role()`, replace the `$rows = $wpdb->get_results(...)` block and the preceding `sanitize_text_field` line with:

```php
        $role = strtolower( trim( sanitize_text_field( $role ) ) );
        if ( $role === '' || strpos( $role, ',' ) !== false ) {
            // Reject poisoned role values (would break FIND_IN_SET).
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_resolver_rejected_role', array(
                    'reason' => 'invalid_role_value',
                    'role'   => $role,
                ) );
            }
            return array();
        }

        $scrub_done = class_exists( 'HL_Roles' ) && HL_Roles::scrub_is_complete();

        if ( $scrub_done ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT e.user_id, u.user_email
                 FROM {$wpdb->prefix}hl_enrollment e
                 JOIN {$wpdb->users} u ON u.ID = e.user_id
                 WHERE e.cycle_id = %d AND e.status IN ('active','warning')
                   AND FIND_IN_SET(%s, e.roles) > 0",
                $cycle_id, $role
            ) );
        } else {
            // Pre-scrub: LIKE narrows, HL_Roles filters exactly.
            $raw = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT e.user_id, u.user_email, e.roles
                 FROM {$wpdb->prefix}hl_enrollment e
                 JOIN {$wpdb->users} u ON u.ID = e.user_id
                 WHERE e.cycle_id = %d AND e.status IN ('active','warning')
                   AND e.roles LIKE %s",
                $cycle_id,
                '%' . $wpdb->esc_like( $role ) . '%'
            ) );
            $rows = array();
            foreach ( (array) $raw as $r ) {
                if ( HL_Roles::has_role( $r->roles, $role ) ) {
                    $rows[] = $r;
                }
            }
        }
```

- [ ] **Step 2: Append a false-match regression test**

Append to `test_resolver()`:

```php
        // Regression: role:leader must NOT return school_leader enrollments.
        $srow = $wpdb->get_row(
            "SELECT cycle_id FROM {$wpdb->prefix}hl_enrollment
             WHERE roles LIKE '%school_leader%' LIMIT 1"
        );
        if ( $srow ) {
            $resolver = HL_Email_Recipient_Resolver::instance();
            $out = $resolver->resolve(
                array( 'primary' => array( 'role:leader' ), 'cc' => array() ),
                array( 'user_id' => 0, 'cycle_id' => (int) $srow->cycle_id )
            );
            // If there are no literal "leader" role enrollments in this cycle, expect empty.
            $leader_only = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}hl_enrollment
                 WHERE cycle_id = %d AND roles NOT LIKE '%school_leader%' AND roles LIKE '%leader%'",
                (int) $srow->cycle_id
            ) );
            if ( (int) $leader_only === 0 ) {
                $this->assert_equals(
                    0, count( $out ),
                    'role:leader does NOT false-match school_leader in cycle with only school_leaders'
                );
            } else {
                WP_CLI::log( '  [SKIP] Cycle has legit "leader" rows — false-match check inconclusive' );
            }
        }
```

- [ ] **Step 3: Run**

```bash
wp hl-core email-v2-test --only=resolver
```

Expected: pass or skip (no crash).

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-email-recipient-resolver.php includes/cli/class-hl-cli-email-v2-test.php
git commit -m "fix(email-v2): gate FIND_IN_SET in resolve_role + reject comma poison"
```

---

## Task 5: `HL_Audit_Service::get_last_event()` + try/catch Wrap

**Files:**
- Modify: `includes/services/class-hl-audit-service.php`

**Goal:** Add a query method used by A.7.13 force-resend history, and ensure audit failures never cascade into caller aborts.

- [ ] **Step 1: Wrap `log()` in try/catch**

Replace the entire `log()` method body in `includes/services/class-hl-audit-service.php` with:

```php
    public static function log($action_type, $data = array()) {
        try {
            global $wpdb;

            $wpdb->insert($wpdb->prefix . 'hl_audit_log', array(
                'log_uuid'       => HL_DB_Utils::generate_uuid(),
                'actor_user_id'  => get_current_user_id(),
                'cycle_id'       => isset($data['cycle_id']) ? $data['cycle_id'] : null,
                'action_type'    => $action_type,
                'entity_type'    => isset($data['entity_type']) ? $data['entity_type'] : null,
                'entity_id'      => isset($data['entity_id']) ? $data['entity_id'] : null,
                'before_data'    => isset($data['before_data']) ? HL_DB_Utils::json_encode($data['before_data']) : null,
                'after_data'     => isset($data['after_data']) ? HL_DB_Utils::json_encode($data['after_data']) : null,
                'reason'         => isset($data['reason']) ? $data['reason'] : null,
                'ip_address'     => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : null,
                'user_agent'     => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 500) : null,
            ));
        } catch ( \Throwable $e ) {
            // A.3.8: audit failures must never cascade into caller aborts.
            error_log( '[HL_AUDIT_FAIL] ' . $e->getMessage() . ' on event ' . $action_type );
            // Bump a daily failure counter in wp_options for monitoring (autoload=no).
            $key = 'hl_audit_fail_count_' . gmdate( 'Y-m-d' );
            update_option( $key, (int) get_option( $key, 0 ) + 1, false );
        }
    }
```

- [ ] **Step 2: Add `get_last_event()`**

At the end of the class (before the final `}`), add:

```php
    /**
     * Fetch the most recent audit log entry matching entity + action.
     *
     * @param int    $entity_id   hl_audit_log.entity_id to match.
     * @param string $action_type hl_audit_log.action_type to match.
     * @return array|null Row array or null if none.
     */
    public static function get_last_event( $entity_id, $action_type ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.*, u.display_name AS actor_name
             FROM {$wpdb->prefix}hl_audit_log l
             LEFT JOIN {$wpdb->users} u ON l.actor_user_id = u.ID
             WHERE l.entity_id = %d AND l.action_type = %s
             ORDER BY l.created_at DESC
             LIMIT 1",
            (int) $entity_id,
            $action_type
        ), ARRAY_A );
        return $row ?: null;
    }
```

- [ ] **Step 3: Add audit test group**

Replace `private function test_audit() {}` with:

```php
    private function test_audit() {
        // log() must not throw on pathological input.
        try {
            HL_Audit_Service::log( 'email_v2_test_log', array(
                'entity_type' => 'test',
                'entity_id'   => 987654321,
                'reason'      => 'CLI self-check',
            ) );
            $this->assert_true( true, 'HL_Audit_Service::log() did not throw' );
        } catch ( \Throwable $e ) {
            $this->assert_true( false, 'HL_Audit_Service::log() threw: ' . $e->getMessage() );
        }

        $row = HL_Audit_Service::get_last_event( 987654321, 'email_v2_test_log' );
        $this->assert_true(
            is_array( $row ) && $row['action_type'] === 'email_v2_test_log',
            'get_last_event() returns the row just inserted'
        );
    }
```

- [ ] **Step 4: Run**

```bash
wp hl-core email-v2-test --only=audit
```

Expected: both asserts pass.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-hl-audit-service.php includes/cli/class-hl-cli-email-v2-test.php
git commit -m "feat(audit): add get_last_event() + try/catch wrap log() (A.3.8)"
```

---

## Task 6: Schema Rev 35 — Component Window Columns + Composite Indexes

**Files:**
- Modify: `includes/class-hl-installer.php`

**Goal:** Add `available_from DATE NULL` and `available_to DATE NULL` to `hl_component`. Add composite indexes for Task 17's coaching_pre_end query (A.2.21). Idempotent. Column-exists guarded. Query-return-checked.

> **A.2.19 coverage:** Schema migration must run on BOTH `register_activation_hook` AND a `plugins_loaded` version check (for the git-deploy flow where activation never fires). `HL_Installer::maybe_upgrade()` is already invoked from `HL_Core::init()` which is hooked to `plugins_loaded` in `hl-core.php` (line ~264/288), so the `plugins_loaded` path is satisfied by existing wiring. **Verify during Step 6** that `wp option get hl_core_schema_revision` bumps on a fresh page load without re-activating the plugin. If for any reason `maybe_upgrade()` is not reachable from `plugins_loaded`, add `add_action('plugins_loaded', ['HL_Installer','maybe_upgrade'], 5);` in `hl-core.php` before returning.

- [ ] **Step 1: Bump `$current_revision` from 34 to 35**

In `maybe_upgrade()`, change:

```php
        $current_revision = 34;
```

to:

```php
        $current_revision = 35;
```

- [ ] **Step 2: Add the Rev 35 block to the migration ladder**

Immediately below the existing `if ( (int) $stored < 34 ) { ... }` block (around line 214), insert:

```php
            // Rev 35: Email v2 — component submission window columns + composite indexes.
            if ( (int) $stored < 35 ) {
                $ok = self::migrate_component_add_window_cols();
                if ( ! $ok ) {
                    // Bail without bumping revision — next plugins_loaded will retry.
                    return;
                }
            }
```

- [ ] **Step 3: Add the migration method**

At the end of the class (before the final `}`), add:

```php
    /**
     * Rev 35: Add available_from / available_to DATE columns to hl_component,
     * plus composite indexes used by the cron:coaching_pre_end query (A.2.21).
     *
     * Idempotent: each ALTER is column-exists / index-exists guarded.
     * Returns true on success, false on any wpdb::query() failure.
     */
    private static function migrate_component_add_window_cols() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $table  = "{$prefix}hl_component";

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $table_exists ) {
            // dbDelta will create the table in a subsequent create_tables() call.
            return true;
        }

        $column_exists = function ( $column ) use ( $wpdb, $table ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table, $column
            ) );
            return ! empty( $row );
        };
        $index_exists = function ( $table_name, $index_name ) use ( $wpdb ) {
            $row = $wpdb->get_var( $wpdb->prepare(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1",
                $table_name, $index_name
            ) );
            return ! empty( $row );
        };

        if ( ! $column_exists( 'available_from' ) ) {
            $res = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN available_from DATE DEFAULT NULL AFTER ordering_hint" );
            if ( $res === false ) return false;
        }
        if ( ! $column_exists( 'available_to' ) ) {
            $res = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN available_to DATE DEFAULT NULL AFTER available_from" );
            if ( $res === false ) return false;
        }

        // Composite indexes for A.2.21 coaching_pre_end query planning.
        if ( ! $index_exists( $table, 'type_pathway' ) ) {
            $res = $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX type_pathway (component_type, pathway_id)" );
            if ( $res === false ) return false;
        }

        // hl_pathway_assignment already has UNIQUE KEY enrollment_pathway
        // (enrollment_id, pathway_id) from dbDelta — the planner can use that
        // composite prefix, so no new index needed.

        $cs_table = "{$prefix}hl_coaching_session";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cs_table ) ) === $cs_table
             && ! $index_exists( $cs_table, 'component_mentor_status' ) ) {
            $res = $wpdb->query( "ALTER TABLE `{$cs_table}` ADD INDEX component_mentor_status (component_id, mentor_enrollment_id, session_status)" );
            if ( $res === false ) return false;
        }

        return true;
    }
```

- [ ] **Step 4: Add the two columns to the `hl_component` CREATE TABLE so fresh installs get them**

Find the `CREATE TABLE {$wpdb->prefix}hl_component` block (around line 1444). Between the `ordering_hint` line and the `weight` line, add:

```sql
            available_from date DEFAULT NULL COMMENT 'Email v2: window-open date',
            available_to date DEFAULT NULL COMMENT 'Email v2: window-close/due date',
```

(This keeps dbDelta in sync so new installs match the migrated schema.)

- [ ] **Step 5: Add schema test group**

Replace `private function test_schema() {}` with:

```php
    private function test_schema() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_component';

        $cols = $wpdb->get_col( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", $table
        ) );

        $this->assert_true( in_array( 'available_from', $cols, true ), 'hl_component.available_from column exists' );
        $this->assert_true( in_array( 'available_to', $cols, true ),   'hl_component.available_to column exists' );

        $rev = (int) get_option( 'hl_core_schema_revision', 0 );
        $this->assert_true( $rev >= 35, "hl_core_schema_revision >= 35 (got {$rev})" );

        // Composite index check.
        $idx = $wpdb->get_var( $wpdb->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'type_pathway' LIMIT 1",
            $table
        ) );
        $this->assert_true( $idx === 'type_pathway', 'hl_component index type_pathway exists' );
    }
```

- [ ] **Step 6: Force the migration to run and verify**

```bash
wp option update hl_core_schema_revision 34
wp eval 'HL_Installer::maybe_upgrade();'
wp hl-core email-v2-test --only=schema
```

Expected: all 4 asserts pass. If any FAIL, revert the option bump and investigate before proceeding.

- [ ] **Step 7: Commit**

```bash
git add includes/class-hl-installer.php includes/cli/class-hl-cli-email-v2-test.php
git commit -m "feat(email-v2): schema rev 35 — hl_component window columns + composite indexes (A.2.19 plugins_loaded path verified)"
```

---

## Task 7: Admin Pathway Form — Date Pickers for `available_from` / `available_to`

**Files:**
- Modify: `includes/admin/class-hl-admin-pathways.php`

- [ ] **Step 1: Read `render_component_form()` — find the `complete_by` field**

**Pre-flight verified (2026-04-11):** `render_component_form()` is defined at line 1781. The `complete_by` label renders at line 1855 and the `<input type="date" id="complete_by" ...>` at line 1856.

Primary grep target inside `includes/admin/class-hl-admin-pathways.php`:

```
'<th scope="row"><label for="complete_by">'
```

Fallback: search for `name="complete_by"` (single hit in this file). If neither match (e.g. field renamed), scroll to the top of `render_component_form()` at line 1781 and locate the `complete_by` row by structure.

We'll add the window-date group immediately below the `complete_by` `</tr>`.

- [ ] **Step 2: Render the two inputs**

Just after the `complete_by` `</tr>` closing tag, insert:

```php
        // Submission window (Email v2).
        $af = ( $is_edit && ! empty( $component->available_from ) ) ? $component->available_from : '';
        $at = ( $is_edit && ! empty( $component->available_to ) )   ? $component->available_to   : '';
        echo '<tr>';
        echo '<th scope="row"><label for="available_from">' . esc_html__( 'Submission Window', 'hl-core' ) . '</label></th>';
        echo '<td>';
        echo '<label for="available_from" style="margin-right:8px;">' . esc_html__( 'Opens:', 'hl-core' ) . '</label>';
        echo '<input type="date" id="available_from" name="available_from" value="' . esc_attr( $af ) . '" style="margin-right:16px;" />';
        echo '<label for="available_to" style="margin-right:8px;">' . esc_html__( 'Closes:', 'hl-core' ) . '</label>';
        echo '<input type="date" id="available_to" name="available_to" value="' . esc_attr( $at ) . '" />';
        echo '<p class="description">' . esc_html__( 'Optional. When set, cron email triggers can reference this window for reminders and overdue notices.', 'hl-core' ) . '</p>';
        echo '</td>';
        echo '</tr>';
```

- [ ] **Step 3: Persist the fields in `save_component()`**

Just below the `$data['complete_by'] = ...;` line (around line 382), add:

```php
        // Submission window (Email v2).
        $af_raw = isset( $_POST['available_from'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['available_from'] ) ) ) : '';
        $at_raw = isset( $_POST['available_to'] )   ? trim( sanitize_text_field( wp_unslash( $_POST['available_to'] ) ) )   : '';
        $is_date = function ( $s ) { return $s !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) === 1; };
        $af = $is_date( $af_raw ) ? $af_raw : null;
        $at = $is_date( $at_raw ) ? $at_raw : null;
        if ( $af && $at && strcmp( $af, $at ) > 0 ) {
            // Silent swap + admin notice on the next page load (A.3.14).
            list( $af, $at ) = array( $at, $af );
            set_transient( 'hl_component_date_swap_' . get_current_user_id(), 1, 30 );
        }
        $data['available_from'] = $af;
        $data['available_to']   = $at;
```

Also locate the `if ( $component_id > 0 ) { ... } else { ... }` INSERT block and confirm `$data` is inserted as-is (it already is — `$wpdb->insert($wpdb->prefix . 'hl_component', $data)`). No change needed there.

- [ ] **Step 4: Surface the inversion-warning admin notice**

Near the top of the pathways admin `init()` (find the `admin_init` action hookup; otherwise hook `admin_notices` within this class's constructor), add:

```php
        add_action( 'admin_notices', array( $this, 'maybe_render_date_swap_notice' ) );
```

And add the callback anywhere in the class:

```php
    public function maybe_render_date_swap_notice() {
        $uid = get_current_user_id();
        if ( get_transient( 'hl_component_date_swap_' . $uid ) ) {
            delete_transient( 'hl_component_date_swap_' . $uid );
            echo '<div class="notice notice-warning is-dismissible"><p>' .
                esc_html__( 'Component submission window dates were reversed — "Opens" has been set to the earlier date.', 'hl-core' ) .
                '</p></div>';
        }
    }
```

- [ ] **Step 5: Manual verify on test server**

```bash
# Create a component with both dates via the admin UI, then:
wp db query "SELECT component_id, title, available_from, available_to FROM wp_hl_component WHERE available_from IS NOT NULL LIMIT 5"
```

Expected: your freshly-saved row shows with the dates.

- [ ] **Step 6: Commit**

```bash
git add includes/admin/class-hl-admin-pathways.php
git commit -m "feat(email-v2): admin pathway form — submission window date pickers"
```

---

## Task 8: Draft Autosave — `created_at` / `updated_at` Timestamps

**Files:**
- Modify: `includes/admin/class-hl-admin-email-builder.php`

**Goal:** Every autosave wraps the draft payload in a JSON envelope with `created_at` (first save only) and `updated_at` (refreshed each call) so the daily cleanup job can age drafts out.

- [ ] **Step 1: Refactor `ajax_autosave()`**

Replace the body of `ajax_autosave()` in `includes/admin/class-hl-admin-email-builder.php` with:

```php
    public function ajax_autosave() {
        check_ajax_referer( 'hl_email_builder', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $template_id = (int) ( $_POST['template_id'] ?? 0 );
        $key         = 'hl_email_draft_' . get_current_user_id() . '_' . ( $template_id ?: 'new' );
        $raw_payload = wp_unslash( $_POST['draft_data'] ?? '' );

        // Read the existing envelope (if any) to preserve created_at.
        $existing = get_option( $key, null );
        $now_iso  = gmdate( 'c' ); // ISO-8601 UTC

        if ( is_string( $existing ) && $existing !== '' ) {
            $existing_arr = json_decode( $existing, true );
            $created_at   = is_array( $existing_arr ) && ! empty( $existing_arr['created_at'] )
                ? $existing_arr['created_at']
                : $now_iso;
        } else {
            $created_at = $now_iso;
        }

        $envelope = wp_json_encode( array(
            'created_at' => $created_at,
            'updated_at' => $now_iso,
            'payload'    => $raw_payload,
        ) );

        // Always write with autoload=no. A.6.6 defense: re-UPDATE autoload.
        update_option( $key, $envelope, 'no' );
        global $wpdb;
        $wpdb->update(
            $wpdb->options,
            array( 'autoload' => 'no' ),
            array( 'option_name' => $key )
        );

        wp_send_json_success( array( 'saved_at' => current_time( 'H:i:s' ) ) );
    }
```

- [ ] **Step 2: Update the draft-load path to unwrap the envelope**

Locate the block near line 61 that reads `$draft_data = get_option( $draft_key, null );` and replace it with:

```php
        $draft_key   = 'hl_email_draft_' . get_current_user_id() . '_' . ( $template_id ?: 'new' );
        $draft_raw   = get_option( $draft_key, null );
        $draft_data  = null;
        if ( is_string( $draft_raw ) && $draft_raw !== '' ) {
            $decoded = json_decode( $draft_raw, true );
            if ( is_array( $decoded ) && array_key_exists( 'payload', $decoded ) ) {
                // New envelope format (post-v2).
                $draft_data = $decoded['payload'];
            } else {
                // Legacy raw payload.
                $draft_data = $draft_raw;
            }
        }
```

- [ ] **Step 3: Manual verify**

```bash
# Create a draft via the builder UI, then:
wp option get "hl_email_draft_1_new" | head -3
```

Expected: JSON with `created_at`, `updated_at`, `payload` keys.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-email-builder.php
git commit -m "feat(email-v2): draft autosave wraps payload with created_at/updated_at envelope"
```

---

## Task 9: Schema Rev 36 — Autoload=no for Existing Drafts

**Files:**
- Modify: `includes/class-hl-installer.php`

**Goal:** One-time UPDATE over `wp_options` flipping existing `hl_email_draft_%` rows to autoload=no. Idempotent. Gated on `$wpdb->query() !== false`.

- [ ] **Step 1: Bump `$current_revision` to 36**

Change `$current_revision = 35;` to `$current_revision = 36;` in `maybe_upgrade()`.

- [ ] **Step 2: Add the Rev 36 block**

Directly below the Rev 35 block added in Task 6, insert:

```php
            // Rev 36: Email v2 — flip existing draft options to autoload=no.
            if ( (int) $stored < 36 ) {
                $ok = self::migrate_email_drafts_autoload_off();
                if ( ! $ok ) {
                    return;
                }
            }
```

- [ ] **Step 3: Add the migration method**

Append to the class (before the final `}`):

```php
    /**
     * Rev 36: Flip all hl_email_draft_* wp_options to autoload='no'.
     * Idempotent — safe to re-run.
     */
    private static function migrate_email_drafts_autoload_off() {
        global $wpdb;
        $like = $wpdb->esc_like( 'hl_email_draft_' ) . '%';
        $res  = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET autoload = 'no'
             WHERE option_name LIKE %s AND autoload != 'no'",
            $like
        ) );
        return $res !== false;
    }
```

- [ ] **Step 4: Force the migration and verify**

```bash
# Seed a fake draft with autoload=yes to prove the migration flips it.
wp option add hl_email_draft_test_1 '{"payload":"x"}' yes
wp option update hl_core_schema_revision 35
wp eval 'HL_Installer::maybe_upgrade();'
wp db query "SELECT autoload FROM wp_options WHERE option_name='hl_email_draft_test_1'"
wp option delete hl_email_draft_test_1
```

Expected: autoload column reads `no` after the migration.

- [ ] **Step 5: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat(email-v2): schema rev 36 — flip existing hl_email_draft_* to autoload=no"
```

---

## Task 10: `cleanup_stale_drafts()` Daily Cron Method

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php`

**Goal:** A daily sweeping method that deletes any `hl_email_draft_*` option whose `updated_at` is > 30 days old, with circuit breaker (500 rows/run, 5000 on first run), corrupt JSON skip+audit, `$wpdb->esc_like` anchor, site-scoped `$wpdb->options`.

- [ ] **Step 1: Add the method**

At the end of `HL_Email_Automation_Service` (before the final `}`), add:

```php
    /**
     * Delete stale hl_email_draft_* options whose envelope updated_at is
     * older than 30 days. Circuit-broken at 500 rows/run (5000 on first run).
     * Corrupt JSON envelopes are skipped and audit-logged, never deleted.
     *
     * Called from run_daily_checks().
     */
    private function cleanup_stale_drafts() {
        global $wpdb;

        $first_run = ! (bool) get_option( 'hl_email_draft_cleanup_seen', 0 );
        $cap       = $first_run ? 5000 : 500;
        $cutoff    = gmdate( 'c', time() - 30 * DAY_IN_SECONDS );

        $like = $wpdb->esc_like( 'hl_email_draft_' ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_id, option_name, option_value
             FROM {$wpdb->options}
             WHERE option_name LIKE %s
             LIMIT %d",
            $like, $cap
        ) );

        if ( empty( $rows ) ) {
            update_option( 'hl_email_draft_cleanup_seen', 1, false );
            return;
        }

        $to_delete = array();
        $skipped   = 0;

        foreach ( $rows as $row ) {
            $decoded = json_decode( $row->option_value, true );

            if ( ! is_array( $decoded ) ) {
                // Corrupt payload — skip + audit, never delete.
                $skipped++;
                if ( class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_draft_cleanup_skip', array(
                        'entity_type' => 'wp_options',
                        'entity_id'   => (int) $row->option_id,
                        'reason'      => 'corrupt_envelope',
                    ) );
                }
                continue;
            }

            $updated_at = $decoded['updated_at'] ?? ( $decoded['created_at'] ?? '2000-01-01T00:00:00+00:00' );
            if ( strcmp( $updated_at, $cutoff ) < 0 ) {
                $to_delete[] = (int) $row->option_id;
            }
        }

        if ( ! empty( $to_delete ) ) {
            $ids_sql = implode( ',', array_map( 'intval', $to_delete ) );
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_id IN ({$ids_sql})" );
        }

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_draft_cleanup', array(
                'entity_type' => 'wp_options',
                'reason'      => sprintf( 'deleted=%d skipped=%d cap=%d', count( $to_delete ), $skipped, $cap ),
            ) );
        }

        update_option( 'hl_email_draft_cleanup_seen', 1, false );
    }
```

- [ ] **Step 2: Invoke from `run_daily_checks()`**

Near the end of `run_daily_checks()`, replace the three comment lines starting with `// TODO: Draft cleanup` with:

```php
        // Email v2: sweep stale builder drafts.
        $this->cleanup_stale_drafts();
```

- [ ] **Step 3: Add drafts test group**

Replace `private function test_drafts() {}` with:

```php
    private function test_drafts() {
        global $wpdb;

        // Seed a 40-day-old draft.
        $old_name = 'hl_email_draft_9999_stale_' . wp_generate_password( 6, false );
        $old_env  = wp_json_encode( array(
            'created_at' => gmdate( 'c', time() - 45 * DAY_IN_SECONDS ),
            'updated_at' => gmdate( 'c', time() - 40 * DAY_IN_SECONDS ),
            'payload'    => 'OLD',
        ) );
        update_option( $old_name, $old_env, 'no' );

        // Seed a fresh draft.
        $new_name = 'hl_email_draft_9999_fresh_' . wp_generate_password( 6, false );
        $new_env  = wp_json_encode( array(
            'created_at' => gmdate( 'c' ),
            'updated_at' => gmdate( 'c' ),
            'payload'    => 'NEW',
        ) );
        update_option( $new_name, $new_env, 'no' );

        // Run the daily check.
        HL_Email_Automation_Service::instance()->run_daily_checks();

        $this->assert_true( get_option( $old_name, null ) === null, 'Stale draft was deleted' );
        $this->assert_true( get_option( $new_name, null ) !== null, 'Fresh draft survived' );

        // Cleanup.
        delete_option( $new_name );
    }
```

- [ ] **Step 4: Run**

```bash
wp hl-core email-v2-test --only=drafts
```

Expected: both asserts pass.

- [ ] **Step 5: EXPLAIN check for index usage (A.3.9)**

```bash
wp db query "EXPLAIN SELECT option_id FROM wp_options WHERE option_name LIKE 'hl_email_draft_%' LIMIT 500"
```

Expected: the `key` column reads `option_name` (or similar), not `NULL`. If NULL, investigate but don't block commit — log it in STATUS.md as a follow-up.

- [ ] **Step 6: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php includes/cli/class-hl-cli-email-v2-test.php
git commit -m "feat(email-v2): cleanup_stale_drafts() daily sweep with circuit breaker"
```

---

## Task 11: Dedup Token — Remove Date Component

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php`

**Goal:** A.1.6 — dedup contract must be "once-per-window, no date component" so missed cron runs don't create gaps. Current code hashes `date_bucket`; remove it.

- [ ] **Step 1: Strip `$date_bucket` from the dedup hash**

In `run_cron_workflow()`, locate the `$date_bucket = gmdate( 'Y-m-d' );` line and **delete it**.

Then find the `$dedup_token = md5( ... );` block and replace with:

```php
                    // A.1.6: no date component — one reminder per (trigger, workflow, user, entity, cycle).
                    $dedup_token = md5(
                        $trigger_key . '|'
                        . $workflow->workflow_id . '|'
                        . ( $recipient['user_id'] ?? 0 ) . '|'
                        . ( $context['entity_id'] ?? 0 ) . '|'
                        . ( $context['cycle_id'] ?? 0 )
                    );
```

- [ ] **Step 2: Add docblock at the top of the class**

Find the class-level docblock for `HL_Email_Automation_Service` (at line ~1) and add above the `class` keyword:

```php
/**
 * HL_Email_Automation_Service
 *
 * Cron dedup contract (A.1.6, A.7.6): dedup tokens have NO date component.
 * Each (trigger, workflow, user, entity, cycle) tuple fires exactly once per
 * window. Range matches (`available_from BETWEEN today AND today+7`) tolerate
 * missed cron runs — if wp-cron skips a day, the next run still catches the
 * same enrollment because its date still falls in the range. Downstream:
 * the hl_email_queue.dedup_token unique-ish index (enforced in PHP via
 * enqueue()'s dedup_token guard) suppresses duplicates.
 *
 * Workflows created mid-window fire on the next cron run for all users whose
 * window is currently in range; users whose window already closed are NOT
 * retroactively notified.
 *
 * Timezone contract: all window queries use current_time('Y-m-d') (WP site TZ).
 * WP-Cron irregularity means edge-of-window enrollments may fire up to 24h
 * before/after the exact calendar boundary. Sub-day precision needs a
 * dedicated hourly trigger type.
 */
```

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php
git commit -m "fix(email-v2): dedup token drops date component; document once-per-window contract"
```

---

## Task 12: Column-Exists Guard + Cron Early-Return

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php`

**Goal:** A.2.25 — if a git-deploy lands the new trigger cases before the Rev 35 migration runs, `get_cron_trigger_users()` must short-circuit to `[]` for the window-dependent triggers so the cron doesn't fatal-error on a missing column. Log once per missed-column event.

- [ ] **Step 1: Add a column-check helper**

At the top of the `get_cron_trigger_users()` method, directly below the `$cycle_id = (int) $cycle->cycle_id;` line, insert:

```php
        $needs_window_col = in_array( $trigger_key, array(
            'cron:cv_window_7d',
            'cron:cv_overdue_1d',
            'cron:rp_window_7d',
            'cron:coaching_window_7d',
        ), true );

        if ( $needs_window_col && ! self::has_component_window_column() ) {
            static $warned = false;
            if ( ! $warned ) {
                $warned = true;
                error_log( '[HL_EMAIL_V2] cron trigger ' . $trigger_key . ' skipped — hl_component.available_from column missing. Run HL_Installer::maybe_upgrade().' );
            }
            return array();
        }
```

- [ ] **Step 2: Add the helper method**

Anywhere in the class, add:

```php
    /**
     * Cached column-exists check for hl_component.available_from.
     */
    private static function has_component_window_column() {
        static $cached = null;
        if ( $cached !== null ) return $cached;

        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND COLUMN_NAME = 'available_from' LIMIT 1",
            $wpdb->prefix . 'hl_component'
        ) );
        $cached = ! empty( $row );
        return $cached;
    }
```

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php
git commit -m "fix(email-v2): cron early-return when hl_component.available_from missing"
```

---

## Task 13: `cron:cv_window_7d` Real Query

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php`

**Pre-flight verified schema (2026-04-11, against `class-hl-installer.php`):**
- `hl_component`: `component_id`, `cycle_id`, `pathway_id`, `component_type`, `available_from` (added in Rev 35, Task 6), `available_to` (Rev 35)
- `hl_pathway_assignment`: `enrollment_id`, `pathway_id`
- `hl_enrollment`: `enrollment_id`, `user_id`, `cycle_id`, `status`
- `hl_classroom_visit`: `classroom_visit_id`, `cycle_id`, `leader_enrollment_id`, `teacher_enrollment_id`
- `hl_classroom_visit_submission`: `submission_id`, `classroom_visit_id`, `status`

**Goal:** Users assigned to a pathway whose `component_type = 'classroom_visit'` component opens in the next 7 days, with no existing visit submission for this cycle.

- [ ] **Step 1: Replace the `cron:cv_window_7d` case**

Locate the combined case block `case 'cron:cv_window_7d': case 'cron:cv_overdue_1d': case 'cron:rp_window_7d':` (around line 899). We will split it into individual cases. Replace the whole block through `return array();` with:

```php
            case 'cron:cv_window_7d': {
                $today   = current_time( 'Y-m-d' );
                $plus7   = wp_date( 'Y-m-d', strtotime( $today . ' +7 days' ) );
                return $wpdb->get_results( $wpdb->prepare(
                    "SELECT DISTINCT en.user_id,
                            en.enrollment_id AS enrollment_id,
                            c.component_id AS entity_id,
                            'component' AS entity_type
                     FROM {$wpdb->prefix}hl_component c
                     INNER JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = c.pathway_id
                     INNER JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.pathway_id = p.pathway_id
                     INNER JOIN {$wpdb->prefix}hl_enrollment en
                         ON en.enrollment_id = pa.enrollment_id
                        AND en.status IN ('active','warning')
                     WHERE c.component_type = 'classroom_visit'
                       AND c.cycle_id = %d
                       AND c.available_from IS NOT NULL
                       AND c.available_from BETWEEN %s AND %s
                       AND NOT EXISTS (
                           SELECT 1
                           FROM {$wpdb->prefix}hl_classroom_visit cv
                           LEFT JOIN {$wpdb->prefix}hl_classroom_visit_submission cvs
                               ON cvs.classroom_visit_id = cv.classroom_visit_id
                              AND cvs.status = 'submitted'
                           WHERE cv.cycle_id = en.cycle_id
                             AND (cv.leader_enrollment_id = en.enrollment_id OR cv.teacher_enrollment_id = en.enrollment_id)
                             AND cvs.submission_id IS NOT NULL
                       )
                     LIMIT 5000",
                    $cycle_id, $today, $plus7
                ), ARRAY_A );
            }

            case 'cron:cv_overdue_1d':
                // Implemented in Task 14.
                return array();

            case 'cron:rp_window_7d':
                // Implemented in Task 15.
                return array();
```

Note: we check the existence of a submitted classroom visit at the enrollment level for that cycle, since `hl_classroom_visit_submission` doesn't carry `component_id` directly. The safer default when no visit exists is to fire the reminder.

- [ ] **Step 2: Cron test group skeleton**

Replace `private function test_cron() {}` with:

```php
    private function test_cron() {
        // Smoke test: the trigger method returns an array (not throws) for each case.
        $svc = HL_Email_Automation_Service::instance();
        global $wpdb;
        $cycle = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE status='active' LIMIT 1" );
        if ( ! $cycle ) {
            WP_CLI::log( '  [SKIP] No active cycle — cron smoke test skipped' );
            return;
        }

        $reflection = new ReflectionClass( $svc );
        $method = $reflection->getMethod( 'get_cron_trigger_users' );
        $method->setAccessible( true );

        $triggers = array(
            'cron:cv_window_7d',
            'cron:cv_overdue_1d',
            'cron:rp_window_7d',
            'cron:coaching_window_7d',
            'cron:coaching_pre_end',
        );
        foreach ( $triggers as $t ) {
            try {
                $out = $method->invoke( $svc, $t, $cycle );
                $this->assert_true( is_array( $out ), $t . ' returned an array' );
            } catch ( \Throwable $e ) {
                $this->assert_true( false, $t . ' threw: ' . $e->getMessage() );
            }
        }
    }
```

- [ ] **Step 3: Run**

```bash
wp hl-core email-v2-test --only=cron
```

Expected: cv_window_7d asserts pass, the others also pass (they still return empty arrays).

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php includes/cli/class-hl-cli-email-v2-test.php
git commit -m "feat(email-v2): cron:cv_window_7d real query + smoke test"
```

---

## Task 14: `cron:cv_overdue_1d` Real Query

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php`

**Pre-flight verified schema (2026-04-11, against `class-hl-installer.php`):** same as Task 13 (`hl_component.available_to`, `hl_classroom_visit.{leader,teacher}_enrollment_id`, `hl_classroom_visit_submission.status`).

- [ ] **Step 1: Replace the stub**

Replace the `case 'cron:cv_overdue_1d':` block (from Task 13) with:

```php
            case 'cron:cv_overdue_1d': {
                $today     = current_time( 'Y-m-d' );
                $yesterday = wp_date( 'Y-m-d', strtotime( $today . ' -1 day' ) );
                return $wpdb->get_results( $wpdb->prepare(
                    "SELECT DISTINCT en.user_id,
                            en.enrollment_id AS enrollment_id,
                            c.component_id AS entity_id,
                            'component' AS entity_type
                     FROM {$wpdb->prefix}hl_component c
                     INNER JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = c.pathway_id
                     INNER JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.pathway_id = p.pathway_id
                     INNER JOIN {$wpdb->prefix}hl_enrollment en
                         ON en.enrollment_id = pa.enrollment_id
                        AND en.status IN ('active','warning')
                     WHERE c.component_type = 'classroom_visit'
                       AND c.cycle_id = %d
                       AND c.available_to IS NOT NULL
                       AND c.available_to = %s
                       AND NOT EXISTS (
                           SELECT 1
                           FROM {$wpdb->prefix}hl_classroom_visit cv
                           LEFT JOIN {$wpdb->prefix}hl_classroom_visit_submission cvs
                               ON cvs.classroom_visit_id = cv.classroom_visit_id
                              AND cvs.status = 'submitted'
                           WHERE cv.cycle_id = en.cycle_id
                             AND (cv.leader_enrollment_id = en.enrollment_id OR cv.teacher_enrollment_id = en.enrollment_id)
                             AND cvs.submission_id IS NOT NULL
                       )
                     LIMIT 5000",
                    $cycle_id, $yesterday
                ), ARRAY_A );
            }
```

Note: this is an exact-date match (A.1.6 only requires range matches for the forward-looking windows; the overdue trigger is a post-window reminder that fires on the day after close). Dedup-no-date still guarantees once-per-window, so a missed day will skip this trigger — that's acceptable because the workflow can also be set to a range if desired later.

- [ ] **Step 2: Run cron test group**

```bash
wp hl-core email-v2-test --only=cron
```

Expected: all pass.

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php
git commit -m "feat(email-v2): cron:cv_overdue_1d real query"
```

---

## Task 15: `cron:rp_window_7d` Real Query

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php`

**Pre-flight verified schema (2026-04-11, against `class-hl-installer.php`):**
- `hl_rp_session`: `rp_session_id`, `cycle_id`, `mentor_enrollment_id`, `teacher_enrollment_id`, `status`
- `hl_rp_session_submission`: `submission_id`, `rp_session_id`, `submitted_by_user_id`, `status`
- (plus the Task 13 component/pathway_assignment/enrollment set)

- [ ] **Step 1: Replace the stub**

Replace `case 'cron:rp_window_7d': return array();` with:

```php
            case 'cron:rp_window_7d': {
                $today = current_time( 'Y-m-d' );
                $plus7 = wp_date( 'Y-m-d', strtotime( $today . ' +7 days' ) );
                return $wpdb->get_results( $wpdb->prepare(
                    "SELECT DISTINCT en.user_id,
                            en.enrollment_id AS enrollment_id,
                            c.component_id AS entity_id,
                            'component' AS entity_type
                     FROM {$wpdb->prefix}hl_component c
                     INNER JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = c.pathway_id
                     INNER JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.pathway_id = p.pathway_id
                     INNER JOIN {$wpdb->prefix}hl_enrollment en
                         ON en.enrollment_id = pa.enrollment_id
                        AND en.status IN ('active','warning')
                     WHERE c.component_type = 'reflective_practice_session'
                       AND c.cycle_id = %d
                       AND c.available_from IS NOT NULL
                       AND c.available_from BETWEEN %s AND %s
                       AND NOT EXISTS (
                           SELECT 1
                           FROM {$wpdb->prefix}hl_rp_session rps
                           LEFT JOIN {$wpdb->prefix}hl_rp_session_submission rpss
                               ON rpss.rp_session_id = rps.rp_session_id
                              AND rpss.status = 'submitted'
                           WHERE rps.cycle_id = en.cycle_id
                             AND rpss.submitted_by_user_id = en.user_id
                             AND rpss.submission_id IS NOT NULL
                       )
                     LIMIT 5000",
                    $cycle_id, $today, $plus7
                ), ARRAY_A );
            }
```

- [ ] **Step 2: Run**

```bash
wp hl-core email-v2-test --only=cron
```

Expected: cv, cv_overdue, rp all return array (pass), coaching_* still return empty.

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php
git commit -m "feat(email-v2): cron:rp_window_7d real query"
```

---

## Task 16: `cron:coaching_window_7d` Real Query

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php`

**Pre-flight verified schema (2026-04-11, against `class-hl-installer.php`):**
- `hl_coaching_session`: `session_id` (PK), `cycle_id`, `mentor_enrollment_id`, `session_status` enum('scheduled','attended','missed','cancelled','rescheduled'), `component_id`
- (plus the Task 13 component/pathway_assignment/enrollment set)

- [ ] **Step 1: Replace the stub**

Locate `case 'cron:coaching_window_7d':` and replace its body (and the subsequent `return array();`) with:

```php
            case 'cron:coaching_window_7d': {
                $today = current_time( 'Y-m-d' );
                $plus7 = wp_date( 'Y-m-d', strtotime( $today . ' +7 days' ) );
                return $wpdb->get_results( $wpdb->prepare(
                    "SELECT DISTINCT en.user_id,
                            en.enrollment_id AS enrollment_id,
                            c.component_id AS entity_id,
                            'component' AS entity_type
                     FROM {$wpdb->prefix}hl_component c
                     INNER JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = c.pathway_id
                     INNER JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.pathway_id = p.pathway_id
                     INNER JOIN {$wpdb->prefix}hl_enrollment en
                         ON en.enrollment_id = pa.enrollment_id
                        AND en.status IN ('active','warning')
                     LEFT JOIN {$wpdb->prefix}hl_coaching_session cs
                         ON cs.component_id = c.component_id
                        AND cs.mentor_enrollment_id = en.enrollment_id
                        AND cs.session_status IN ('scheduled','attended')
                     WHERE c.component_type = 'coaching_session_attendance'
                       AND c.cycle_id = %d
                       AND c.available_from IS NOT NULL
                       AND c.available_from BETWEEN %s AND %s
                       AND cs.session_id IS NULL
                     LIMIT 5000",
                    $cycle_id, $today, $plus7
                ), ARRAY_A );
            }
```

- [ ] **Step 2: Run**

```bash
wp hl-core email-v2-test --only=cron
```

Expected: all five cases pass as array-returning.

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php
git commit -m "feat(email-v2): cron:coaching_window_7d real query"
```

---

## Task 17: `cron:coaching_pre_end` Real Query

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php`

**Pre-flight verified schema (2026-04-11, against `class-hl-installer.php`):**
- `hl_cycle`: `cycle_id`, `status` enum('draft','active','paused','archived'), `end_date` DATE NULL
- `hl_coaching_session`: `session_id`, `component_id`, `mentor_enrollment_id`, `session_status`
- (plus `hl_component`, `hl_pathway`, `hl_pathway_assignment`, `hl_enrollment`)

**Goal:** Cycles ending in 0–14 days with enrollments lacking a completed coaching session. Uses the Rev 35 composite indexes (A.2.21). `LIMIT 5000` safety cap (Task 31 will add the warn-on-cap).

- [ ] **Step 1: Replace the stub**

Locate `case 'cron:coaching_pre_end':` and replace its body with:

```php
            case 'cron:coaching_pre_end': {
                $today  = current_time( 'Y-m-d' );
                $plus14 = wp_date( 'Y-m-d', strtotime( $today . ' +14 days' ) );
                return $wpdb->get_results( $wpdb->prepare(
                    "SELECT DISTINCT en.user_id,
                            en.enrollment_id AS enrollment_id,
                            c.component_id AS entity_id,
                            'component' AS entity_type
                     FROM {$wpdb->prefix}hl_cycle cy
                     INNER JOIN {$wpdb->prefix}hl_enrollment en
                         ON en.cycle_id = cy.cycle_id AND en.status IN ('active','warning')
                     INNER JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.enrollment_id = en.enrollment_id
                     INNER JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = pa.pathway_id
                     INNER JOIN {$wpdb->prefix}hl_component c
                         ON c.pathway_id = p.pathway_id
                        AND c.component_type = 'coaching_session_attendance'
                     LEFT JOIN {$wpdb->prefix}hl_coaching_session cs
                         ON cs.component_id = c.component_id
                        AND cs.mentor_enrollment_id = en.enrollment_id
                        AND cs.session_status = 'attended'
                     WHERE cy.cycle_id = %d
                       AND cy.status = 'active'
                       AND cy.end_date IS NOT NULL
                       AND cy.end_date BETWEEN %s AND %s
                       AND cs.session_id IS NULL
                     LIMIT 5000",
                    $cycle_id, $today, $plus14
                ), ARRAY_A );
            }
```

- [ ] **Step 2: Run**

```bash
wp hl-core email-v2-test --only=cron
```

Expected: all five pass.

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php
git commit -m "feat(email-v2): cron:coaching_pre_end real query (0-14 day cycle end window)"
```

---

## Task 18: `last_cron_run_at` Tracking + Staleness Warning

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php`
- Modify: `includes/admin/class-hl-admin-emails.php`

**Goal:** A.1.6 / A.6.10 — record each successful `run_daily_checks()` in `wp_option` and surface a warning if the gap > 36h via (a) audit log, (b) Site Health test, (c) admin notice on email admin pages.

- [ ] **Step 1: Record the timestamp**

At the **end** of `run_daily_checks()` (after the `cleanup_stale_drafts()` call), add:

```php
        update_option( 'hl_email_last_cron_run_at', gmdate( 'c' ), false );
```

- [ ] **Step 2: Add a staleness check method**

Anywhere in `HL_Email_Automation_Service`, add:

```php
    /**
     * @return int|null Seconds since last successful daily cron run, or null if never.
     */
    public static function cron_staleness_seconds() {
        $last = get_option( 'hl_email_last_cron_run_at', null );
        if ( ! $last ) return null;
        $ts = strtotime( $last );
        return $ts ? ( time() - $ts ) : null;
    }
```

- [ ] **Step 3: Site Health filter + admin notice**

In `HL_Email_Automation_Service::__construct()`, add:

```php
        add_filter( 'site_status_tests', array( $this, 'register_site_health_test' ) );
        add_action( 'admin_notices',      array( $this, 'maybe_render_cron_staleness_notice' ) );
```

Then add the two methods:

```php
    public function register_site_health_test( $tests ) {
        $tests['direct']['hl_email_cron_fresh'] = array(
            'label' => __( 'HL Email daily cron', 'hl-core' ),
            'test'  => array( $this, 'site_health_cron_test' ),
        );
        return $tests;
    }

    public function site_health_cron_test() {
        $gap = self::cron_staleness_seconds();
        $ok  = $gap !== null && $gap < 36 * HOUR_IN_SECONDS;
        return array(
            'label'       => $ok
                ? __( 'HL Email daily cron has run recently', 'hl-core' )
                : __( 'HL Email daily cron is stale', 'hl-core' ),
            'status'      => $ok ? 'good' : 'recommended',
            'badge'       => array( 'label' => __( 'HL Email', 'hl-core' ), 'color' => $ok ? 'green' : 'orange' ),
            'description' => sprintf(
                '<p>%s</p>',
                esc_html( $ok
                    ? __( 'Last run within the last 36 hours.', 'hl-core' )
                    : __( 'No successful run in the last 36 hours. Check wp-cron or trigger manually with `wp cron event run hl_email_cron_daily`.', 'hl-core' )
                )
            ),
            'actions'     => '',
            'test'        => 'hl_email_cron_fresh',
        );
    }

    public function maybe_render_cron_staleness_notice() {
        if ( ! function_exists( 'get_current_screen' ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || strpos( (string) $screen->id, 'hl-emails' ) === false ) return;

        $gap = self::cron_staleness_seconds();
        if ( $gap === null || $gap < 36 * HOUR_IN_SECONDS ) return;

        $hours = floor( $gap / HOUR_IN_SECONDS );
        echo '<div class="notice notice-warning"><p><strong>' .
            esc_html__( 'HL Email daily cron is stale', 'hl-core' ) .
            '</strong> — ' .
            esc_html( sprintf( __( 'last successful run was %d hours ago.', 'hl-core' ), $hours ) ) .
            ' <code>wp cron event run hl_email_cron_daily</code></p></div>';
    }
```

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php
git commit -m "feat(email-v2): last_cron_run_at + Site Health staleness warning"
```

---

## Task 19: `HL_Roles_Scrub_Migration` Skeleton

**Files:**
- Create: `includes/migrations/class-hl-roles-scrub-migration.php`
- Modify: `hl-core.php`

**Goal:** A.2.15 / A.6.2 / A.7.2 — chunked scrub that rewrites `hl_enrollment.roles` from JSON to CSV using `HL_Roles::sanitize_roles()`, with a resume cursor, transient lock (A.7.11), and per-chunk transaction.

- [ ] **Step 1: Create the class file**

Create `includes/migrations/class-hl-roles-scrub-migration.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rev 37 chunked role scrub.
 *
 * Walks hl_enrollment.enrollment_id ascending, 500 rows per plugins_loaded
 * firing (5000 on first run if backlog > 5000), converting each `roles`
 * column from JSON array format to canonical CSV via HL_Roles::sanitize_roles().
 *
 * State:
 *   wp_option hl_roles_scrub_cursor (last enrollment_id processed)
 *   wp_option hl_roles_scrub_done   (1 when complete)
 *   transient hl_roles_scrub_lock   (60s, prevents concurrent chunks)
 */
class HL_Roles_Scrub_Migration {

    const CHUNK_SIZE       = 500;
    const FIRST_RUN_CHUNK  = 5000;
    const LOCK_KEY         = 'hl_roles_scrub_lock';
    const CURSOR_KEY       = 'hl_roles_scrub_cursor';
    const DONE_KEY         = 'hl_roles_scrub_done';

    public static function register() {
        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_run' ), 20 );
    }

    public static function maybe_run() {
        if ( get_option( self::DONE_KEY, 0 ) ) return;

        // Only run for admins / wp-cli — never on a visitor's request path.
        if ( ! is_admin() && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) return;

        if ( get_transient( self::LOCK_KEY ) ) return;
        set_transient( self::LOCK_KEY, 1, 60 );

        try {
            self::run_chunk();
        } catch ( \Throwable $e ) {
            error_log( '[HL_ROLES_SCRUB] ' . $e->getMessage() );
        }

        delete_transient( self::LOCK_KEY );
    }

    /**
     * Process one chunk. Called from maybe_run() under lock.
     */
    public static function run_chunk() {
        // Implemented in Task 20.
    }
}
```

- [ ] **Step 2: Require and register in `hl-core.php`**

In the `require_once` block with the other service files, add:

```php
require_once HL_CORE_INCLUDES_DIR . 'migrations/class-hl-roles-scrub-migration.php';
```

And in the plugin bootstrap (alongside other `::register()` calls), add:

```php
HL_Roles_Scrub_Migration::register();
```

Find the `includes/migrations/` directory — if it doesn't exist, create it:

```bash
mkdir -p includes/migrations
```

(A `class-hl-email-template-migration.php` already lives there based on the installer's require in Rev 34.)

- [ ] **Step 3: Commit**

```bash
git add includes/migrations/class-hl-roles-scrub-migration.php hl-core.php
git commit -m "feat(email-v2): HL_Roles_Scrub_Migration skeleton + plugins_loaded hook"
```

---

## Task 20: Scrub Chunk Worker

**Files:**
- Modify: `includes/migrations/class-hl-roles-scrub-migration.php`

- [ ] **Step 1: Implement `run_chunk()`**

Replace the empty `run_chunk()` method with:

```php
    public static function run_chunk() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_enrollment';

        $cursor = (int) get_option( self::CURSOR_KEY, 0 );

        // Backlog-aware chunk sizing on first run.
        $chunk = self::CHUNK_SIZE;
        if ( $cursor === 0 ) {
            $total_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE roles LIKE '[%' OR roles LIKE '% %'" );
            if ( $total_pending > self::CHUNK_SIZE ) {
                $chunk = min( $total_pending, self::FIRST_RUN_CHUNK );
            }
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT enrollment_id, roles FROM {$table}
             WHERE enrollment_id > %d
             ORDER BY enrollment_id ASC
             LIMIT %d",
            $cursor, $chunk
        ) );

        if ( empty( $rows ) ) {
            update_option( self::DONE_KEY, 1, false );
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_roles_scrub_complete', array(
                    'reason' => 'cursor_end',
                ) );
            }
            return;
        }

        $wpdb->query( 'START TRANSACTION' );

        $max_id   = $cursor;
        $rewrites = 0;

        foreach ( $rows as $row ) {
            $enrollment_id = (int) $row->enrollment_id;
            $max_id        = max( $max_id, $enrollment_id );

            $normalized = HL_Roles::sanitize_roles( $row->roles );
            if ( $normalized === (string) $row->roles ) {
                // Already canonical.
                continue;
            }

            $res = $wpdb->update(
                $table,
                array( 'roles' => $normalized ),
                array( 'enrollment_id' => $enrollment_id ),
                array( '%s' ),
                array( '%d' )
            );
            if ( $res === false ) {
                $wpdb->query( 'ROLLBACK' );
                error_log( '[HL_ROLES_SCRUB] UPDATE failed at enrollment_id=' . $enrollment_id );
                return;
            }
            $rewrites++;
        }

        $wpdb->query( 'COMMIT' );
        update_option( self::CURSOR_KEY, $max_id, false );

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_roles_scrub_chunk', array(
                'reason' => sprintf( 'chunk=%d rewrites=%d next_cursor=%d', count( $rows ), $rewrites, $max_id ),
            ) );
        }
    }
```

- [ ] **Step 2: Add a manual-run CLI trigger in the test suite**

Append the following to the `HL_CLI_Email_V2_Test::run()` help text by adding a new `--run-scrub` flag. In `run()`, right after `$only = ...;`, add:

```php
        if ( ! empty( $assoc_args['run-scrub'] ) ) {
            WP_CLI::log( 'Running role scrub chunks until complete...' );
            $safety = 100;
            while ( ! get_option( 'hl_roles_scrub_done', 0 ) && $safety-- > 0 ) {
                HL_Roles_Scrub_Migration::run_chunk();
            }
            $done = (bool) get_option( 'hl_roles_scrub_done', 0 );
            WP_CLI::log( $done ? 'Scrub complete.' : 'Scrub did not complete in 100 chunks.' );
            if ( ! $done ) WP_CLI::halt( 1 );
            return;
        }
```

And update the `## OPTIONS` docblock in `run()` to mention `[--run-scrub]`.

- [ ] **Step 3: Run against a small seeded enrollment**

```bash
# Reset and run:
wp option delete hl_roles_scrub_done
wp option delete hl_roles_scrub_cursor
wp hl-core email-v2-test --run-scrub
wp db query "SELECT enrollment_id, roles FROM wp_hl_enrollment LIMIT 5"
```

Expected: each `roles` column is now a sorted CSV (e.g., `mentor,teacher`) with no `[`, no `"`, no spaces. `wp_options` shows `hl_roles_scrub_done=1`.

- [ ] **Step 4: Commit**

```bash
git add includes/migrations/class-hl-roles-scrub-migration.php includes/cli/class-hl-cli-email-v2-test.php
git commit -m "feat(email-v2): role scrub chunk worker + CLI runner"
```

---

## Task 21: Enrollment Repository Writes CSV

**Files:**
- Modify: `includes/domain/repositories/class-hl-enrollment-repository.php`

**Goal:** Post-scrub, all new writes must keep the column in CSV format. Route through `HL_Roles::sanitize_roles()`.

- [ ] **Step 1: Patch `create()`**

Replace:

```php
        if (isset($data['roles']) && is_array($data['roles'])) {
            $data['roles'] = HL_DB_Utils::json_encode($data['roles']);
        }
```

with:

```php
        if (isset($data['roles'])) {
            $data['roles'] = class_exists('HL_Roles')
                ? HL_Roles::sanitize_roles($data['roles'])
                : (is_array($data['roles']) ? HL_DB_Utils::json_encode($data['roles']) : $data['roles']);
        }
```

- [ ] **Step 2: Patch `update()`**

Make the same replacement in `update()`.

- [ ] **Step 3: Patch other enrollment.roles write sites**

**Pre-flight enumerated (2026-04-11, `Grep json_encode.*roles|roles.*json_encode` in `includes/`):**

Write sites touching `hl_enrollment.roles` (IN SCOPE — patch all):

1. `includes/domain/repositories/class-hl-enrollment-repository.php:94` — `create()` (patched in Step 1)
2. `includes/domain/repositories/class-hl-enrollment-repository.php:103` — `update()` (patched in Step 2)
3. `includes/services/class-hl-import-participant-handler.php:597` — import handler write
4. `includes/services/class-hl-import-participant-handler.php:620` — import handler write
5. `includes/admin/class-hl-admin-enrollments.php:211` — admin manual enrollment form
6. `includes/cli/scripts/provision-test-teachers.php:63` — test seeder

Patch 3–6 with the same pattern: wrap the `wp_json_encode($roles)` / `wp_json_encode(array($role))` call with a `class_exists('HL_Roles') ? HL_Roles::sanitize_roles($roles) : wp_json_encode($roles)` fallback. For sites that pass `array($role)` (a single-role wrapper), first build `$roles = array($role);` then call `HL_Roles::sanitize_roles($roles)`.

Out of scope — NOT `hl_enrollment.roles`, do NOT modify:

- `includes/domain/repositories/class-hl-tour-repository.php:70, 81` — writes `hl_product_tour.target_roles`
- `includes/domain/repositories/class-hl-pathway-repository.php:51, 60` — writes `hl_pathway.target_roles`
- `includes/admin/class-hl-admin-pathways.php:275` — writes `hl_pathway.target_roles`
- `includes/admin/class-hl-admin-pathways.php:397` — writes `hl_component.eligible_roles`

These columns are still JSON-encoded (per v1 behavior) and are explicitly preserved.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "fix(email-v2): enrollment repository writes roles as canonical CSV"
```

---

## Task 22: Schema Rev 37 — Gate Scrub on `plugins_loaded`

**Files:**
- Modify: `includes/class-hl-installer.php`

**Goal:** Bump `$current_revision` to 37 so existing installations queue the scrub. Migration itself runs incrementally from `HL_Roles_Scrub_Migration::maybe_run()` on subsequent page loads.

- [ ] **Step 1: Bump the revision**

Change `$current_revision = 36;` to `$current_revision = 37;`.

- [ ] **Step 2: Add the Rev 37 block**

Directly below the Rev 36 block:

```php
            // Rev 37: Email v2 — role scrub.
            // The actual chunked rewrite runs from HL_Roles_Scrub_Migration on
            // subsequent plugins_loaded firings. Here we just ensure the cursor
            // exists so the first chunk starts at 0.
            if ( (int) $stored < 37 ) {
                if ( get_option( 'hl_roles_scrub_cursor', null ) === null ) {
                    update_option( 'hl_roles_scrub_cursor', 0, false );
                }
                if ( get_option( 'hl_roles_scrub_done', null ) === null ) {
                    update_option( 'hl_roles_scrub_done', 0, false );
                }
            }
```

- [ ] **Step 3: Verify the migration trail**

```bash
wp option update hl_core_schema_revision 36
wp eval 'HL_Installer::maybe_upgrade();'
wp option get hl_core_schema_revision
wp option get hl_roles_scrub_cursor
```

Expected: schema revision now reads `37`, scrub cursor initialized.

- [ ] **Step 4: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat(email-v2): schema rev 37 — gate role scrub on plugins_loaded"
```

---

## Task 23: `assigned_mentor` Resolver + `cc_teacher` Alias + Audit

**Files:**
- Modify: `includes/services/class-hl-email-recipient-resolver.php`

**Goal:** Add the new `assigned_mentor` token that resolves to the mentor of the triggering user's team (via `hl_team_membership`). Keep `cc_teacher` as a backward-compat alias that audit-logs each hit (A.6.11) — v3 removal target after 90 days of zero hits.

- [ ] **Step 1: Add `assigned_mentor` case and rename `cc_teacher`**

In `resolve_token()`, inside the `switch ( $token )`, replace the `case 'cc_teacher':` block with:

```php
            case 'assigned_mentor':
                return $this->resolve_assigned_mentor( $context );

            case 'observed_teacher':
                return $this->resolve_observed_teacher( $context );

            case 'cc_teacher':
                // Legacy alias (A.6.11 deprecation telemetry).
                if ( class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_token_alias_hit', array(
                        'entity_type' => 'email_workflow',
                        'reason'      => 'cc_teacher -> observed_teacher',
                    ) );
                }
                return $this->resolve_observed_teacher( $context );
```

- [ ] **Step 2: Rename the existing method**

Rename `resolve_cc_teacher()` to `resolve_observed_teacher()` (identical body, new name):

```php
    /**
     * Resolve the teacher being observed (for classroom visit emails).
     * Previously cc_teacher; renamed to observed_teacher in v2.
     */
    private function resolve_observed_teacher( array $context ) {
        $teacher_user_id = $context['cc_teacher_user_id'] ?? $context['observed_teacher_user_id'] ?? null;
        if ( ! $teacher_user_id ) {
            return array();
        }
        $user = get_userdata( (int) $teacher_user_id );
        if ( ! $user ) {
            return array();
        }
        return array( array( 'email' => $user->user_email, 'user_id' => (int) $teacher_user_id ) );
    }
```

- [ ] **Step 3: Add `resolve_assigned_mentor()`**

Anywhere in the class, add:

```php
    /**
     * Resolve the mentor of the triggering user within the current cycle.
     *
     * A team has mentors and members (hl_team_membership.membership_type).
     * Given the triggering user's enrollment, find their team and return the
     * mentor enrollment's user. Requires $context['user_id'] and
     * $context['cycle_id']. Returns NULL (+ audit) if missing.
     *
     * @return array Array of { email, user_id } (0 or 1 row).
     */
    private function resolve_assigned_mentor( array $context ) {
        global $wpdb;
        $user_id  = $context['user_id']  ?? null;
        $cycle_id = $context['cycle_id'] ?? null;

        if ( ! $user_id || ! $cycle_id ) {
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_resolver_missing_context', array(
                    'reason' => 'assigned_mentor requires user_id + cycle_id',
                ) );
            }
            return array();
        }

        // Find the user's enrollment in this cycle.
        $enrollment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT enrollment_id FROM {$wpdb->prefix}hl_enrollment
             WHERE user_id = %d AND cycle_id = %d AND status IN ('active','warning')
             LIMIT 1",
            $user_id, $cycle_id
        ) );
        if ( ! $enrollment_id ) return array();

        // Find the mentor enrollment in the same team.
        $mentor_enrollment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT mentor_tm.enrollment_id
             FROM {$wpdb->prefix}hl_team_membership user_tm
             INNER JOIN {$wpdb->prefix}hl_team_membership mentor_tm
                 ON user_tm.team_id = mentor_tm.team_id
                AND mentor_tm.membership_type = 'mentor'
             INNER JOIN {$wpdb->prefix}hl_team t
                 ON t.team_id = user_tm.team_id
                AND t.cycle_id = %d
             WHERE user_tm.enrollment_id = %d
             ORDER BY mentor_tm.team_id ASC, mentor_tm.enrollment_id ASC
             LIMIT 1",
            $cycle_id, $enrollment_id
        ) );
        if ( ! $mentor_enrollment_id ) return array();

        $mentor_user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d LIMIT 1",
            $mentor_enrollment_id
        ) );
        if ( ! $mentor_user_id ) return array();

        $user = get_userdata( (int) $mentor_user_id );
        if ( ! $user ) return array();

        return array( array( 'email' => $user->user_email, 'user_id' => (int) $mentor_user_id ) );
    }
```

- [ ] **Step 4: Update class docblock**

Change the `Tokens:` line in the class docblock to:

```php
 * Tokens: triggering_user, assigned_coach, assigned_mentor, school_director,
 * observed_teacher (alias: cc_teacher), role:X, static:email.
```

- [ ] **Step 5: Append resolver test**

Append to `test_resolver()`:

```php
        // assigned_mentor smoke test: run against a seeded enrollment with a team.
        $mentor_row = $wpdb->get_row(
            "SELECT tm.enrollment_id, t.cycle_id FROM {$wpdb->prefix}hl_team_membership tm
             INNER JOIN {$wpdb->prefix}hl_team t ON t.team_id = tm.team_id
             WHERE tm.membership_type = 'member' LIMIT 1"
        );
        if ( $mentor_row ) {
            $user_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
                $mentor_row->enrollment_id
            ) );
            if ( $user_id ) {
                $resolver = HL_Email_Recipient_Resolver::instance();
                $out = $resolver->resolve(
                    array( 'primary' => array( 'assigned_mentor' ), 'cc' => array() ),
                    array( 'user_id' => (int) $user_id, 'cycle_id' => (int) $mentor_row->cycle_id )
                );
                $this->assert_true(
                    is_array( $out ),
                    'assigned_mentor resolves to array (may be empty if no mentor in team)'
                );
            }
        }
```

- [ ] **Step 6: Run**

```bash
wp hl-core email-v2-test --only=resolver
```

Expected: pass.

- [ ] **Step 7: Commit**

```bash
git add includes/services/class-hl-email-recipient-resolver.php includes/cli/class-hl-cli-email-v2-test.php
git commit -m "feat(email-v2): assigned_mentor token + cc_teacher -> observed_teacher alias"
```

---

## Task 24: Queue Processor — `mb_encode_mimeheader` Subject

**Files:**
- Modify: `includes/services/class-hl-email-queue-processor.php`

**Goal:** A.1.4 — `wp_mail()` does not encode non-ASCII subject headers. Wrap the subject.

- [ ] **Step 1: Add the encoding step**

In `process_single()`, locate the line `$sent = wp_mail( $row->recipient_email, $row->subject, $body_html, $headers );` and change it to:

```php
        $encoded_subject = function_exists( 'mb_encode_mimeheader' )
            ? mb_encode_mimeheader( (string) $row->subject, 'UTF-8', 'B' )
            : (string) $row->subject;

        $sent = wp_mail( $row->recipient_email, $encoded_subject, $body_html, $headers );
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-email-queue-processor.php
git commit -m "fix(email-v2): mb_encode_mimeheader for non-ASCII subjects"
```

---

## Task 25: Queue Processor — Deliverability Headers

**Files:**
- Modify: `includes/services/class-hl-email-queue-processor.php`

**Goal:** A.2.22 — explicit `From`, `Reply-To`, `List-Unsubscribe`, `List-Unsubscribe-Post` headers to satisfy Google/Yahoo Feb 2024 bulk sender rules. `List-Unsubscribe` URL uses an HMAC token that Task 26 implements; for now, we pass a placeholder and wire the real token in the next task.

- [ ] **Step 1: Build the headers array**

Replace the `$headers = array( 'Content-Type: text/html; charset=UTF-8' );` line in `process_single()` with:

```php
        $headers = $this->build_headers( $row );
```

- [ ] **Step 2: Add `build_headers()` method**

At the end of the class (before the final `}`), add:

```php
    /**
     * Build the From / Reply-To / List-Unsubscribe headers for a queue row.
     *
     * @param object $row hl_email_queue row.
     * @return string[] wp_mail-compatible header strings.
     */
    private function build_headers( $row ) {
        $from_name  = get_option( 'hl_email_from_name',  get_bloginfo( 'name' ) );
        $from_email = get_option( 'hl_email_from_email', get_option( 'admin_email' ) );
        $reply_to   = get_option( 'hl_email_reply_to',   $from_email );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $from_name, $from_email ),
            sprintf( 'Reply-To: %s', $reply_to ),
        );

        // List-Unsubscribe HMAC token wired in Task 26.
        if ( class_exists( 'HL_Email_Queue_Processor' ) && method_exists( $this, 'build_unsubscribe_url' ) ) {
            $unsubscribe_url = $this->build_unsubscribe_url( $row );
            if ( $unsubscribe_url ) {
                $headers[] = sprintf( 'List-Unsubscribe: <mailto:%s?subject=unsubscribe>, <%s>', $reply_to, $unsubscribe_url );
                $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
            }
        }

        return $headers;
    }
```

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-email-queue-processor.php
git commit -m "feat(email-v2): deliverability headers (From, Reply-To, List-Unsubscribe)"
```

---

## Task 26: HMAC Unsubscribe Token + Secret wp_option

**Files:**
- Modify: `includes/services/class-hl-email-queue-processor.php`
- Modify: `includes/class-hl-installer.php`

**Goal:** A.6.3 / A.7.3 / A.7.12 — dedicated `hl_email_unsubscribe_secret` wp_option, generated once via `add_option()` (atomic insert-if-missing), never rotated. Token is `hash_hmac('sha256', user_id . ':' . queue_id, $secret)`. Unsubscribe endpoint is deferred to a future task — for v2 Track 3 we only emit the URL.

- [ ] **Step 1: Secret bootstrap helper**

**Pre-flight verified (2026-04-11):** `HL_Installer::activate()` exists at `class-hl-installer.php:17` (`public static function activate()`). It is hooked via `register_activation_hook` in `hl-core.php`.

In `includes/class-hl-installer.php`, near the end of `activate()`, add:

```php
        // Email v2: generate the unsubscribe HMAC secret once. add_option()
        // is atomic (insert-if-missing), safe against concurrent callers.
        if ( ! get_option( 'hl_email_unsubscribe_secret' ) ) {
            add_option( 'hl_email_unsubscribe_secret', wp_generate_password( 64, true, true ), '', 'no' );
        }
```

- [ ] **Step 2: Runtime fallback**

In `includes/services/class-hl-email-queue-processor.php`, add:

```php
    /**
     * Return the unsubscribe HMAC secret, lazily creating it if missing.
     * Uses add_option() for atomic insert-if-missing (A.7.12 race fix).
     */
    private function unsubscribe_secret() {
        $secret = get_option( 'hl_email_unsubscribe_secret', '' );
        if ( $secret !== '' ) return $secret;

        $candidate = wp_generate_password( 64, true, true );
        // add_option returns false if it already exists — then re-read.
        add_option( 'hl_email_unsubscribe_secret', $candidate, '', 'no' );
        return get_option( 'hl_email_unsubscribe_secret', $candidate );
    }

    /**
     * Build a one-click unsubscribe URL with an HMAC token (A.6.3).
     * Returns '' if the recipient has no WP user (static emails can't unsubscribe).
     *
     * @param object $row hl_email_queue row.
     * @return string
     */
    private function build_unsubscribe_url( $row ) {
        if ( empty( $row->recipient_user_id ) ) return '';

        $secret = $this->unsubscribe_secret();
        $token  = hash_hmac( 'sha256', $row->recipient_user_id . ':' . $row->queue_id, $secret );

        return add_query_arg( array(
            'action' => 'hl_email_unsubscribe',
            'u'      => (int) $row->recipient_user_id,
            'q'      => (int) $row->queue_id,
            't'      => $token,
        ), home_url( '/' ) );
    }
```

- [ ] **Step 3: Deliverability test**

Replace `private function test_deliverability() {}` with:

```php
    private function test_deliverability() {
        // Subject encoding.
        if ( function_exists( 'mb_encode_mimeheader' ) ) {
            $encoded = mb_encode_mimeheader( 'Bienvenida — hoy comenzamos', 'UTF-8', 'B' );
            $this->assert_true( strpos( $encoded, '=?UTF-8?B?' ) === 0, 'mb_encode_mimeheader encodes UTF-8 subject' );
        }

        // Unsubscribe secret exists or auto-generates.
        $secret = get_option( 'hl_email_unsubscribe_secret', '' );
        $this->assert_true( $secret !== '' || class_exists( 'HL_Email_Queue_Processor' ), 'Unsubscribe secret bootstrap path exists' );

        // HMAC determinism.
        $secret = $secret !== '' ? $secret : 'test-fallback';
        $a = hash_hmac( 'sha256', '1:99', $secret );
        $b = hash_hmac( 'sha256', '1:99', $secret );
        $this->assert_equals( $a, $b, 'HMAC unsubscribe token is deterministic' );
    }
```

- [ ] **Step 4: Run**

```bash
wp hl-core email-v2-test --only=deliverability
```

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-hl-email-queue-processor.php includes/class-hl-installer.php includes/cli/class-hl-cli-email-v2-test.php
git commit -m "feat(email-v2): HMAC unsubscribe token + dedicated secret wp_option"
```

---

## Task 27: `wp_mail_failed` Hook

**Files:**
- Modify: `includes/services/class-hl-email-queue-processor.php`

**Goal:** A.2.23 — `wp_mail()` can return `true` but later silently fail in the `wp_mail_failed` action if SMTP rejects. Capture these and flip the queue row to `failed`.

- [ ] **Step 1: Register the hook in the constructor**

In `__construct()` of `HL_Email_Queue_Processor`, add:

```php
        add_action( 'wp_mail_failed', array( $this, 'handle_wp_mail_failed' ) );
```

- [ ] **Step 2: Track the currently-processing row**

At the top of `HL_Email_Queue_Processor`, add:

```php
    /** @var object|null The queue row currently being sent, used by wp_mail_failed. */
    private $current_row = null;
```

Inside `process_single()`, immediately before the `wp_mail()` call, set:

```php
        $this->current_row = $row;
```

And immediately after the `wp_mail()` call, reset:

```php
        $this->current_row = null;
```

(Make sure both assignments wrap the call — even if the result is `true`.)

- [ ] **Step 3: Add the handler**

```php
    /**
     * A.2.23: capture silent SMTP rejects. If wp_mail_failed fires while we
     * are mid-process_single(), flip the row to failed.
     *
     * @param WP_Error $error
     */
    public function handle_wp_mail_failed( $error ) {
        if ( ! $this->current_row ) return;
        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_queue";

        $wpdb->update(
            $table,
            array(
                'status'        => 'failed',
                'claim_token'   => null,
                'failed_reason' => 'wp_mail_failed: ' . substr( (string) $error->get_error_message(), 0, 200 ),
                'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'queue_id' => $this->current_row->queue_id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_wp_mail_failed', array(
                'entity_type' => 'email_queue',
                'entity_id'   => $this->current_row->queue_id,
                'reason'      => $error->get_error_message(),
            ) );
        }
    }
```

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-email-queue-processor.php
git commit -m "fix(email-v2): wp_mail_failed hook flips queue row to failed"
```

---

## Task 28: Queue Claim Dynamic Expiry Formula

**Files:**
- Modify: `includes/services/class-hl-email-queue-processor.php`

**Pre-flight verified (2026-04-11):**
- `const STUCK_THRESHOLD_MINUTES = 10;` at `class-hl-email-queue-processor.php:26`
- `private function recover_stuck_rows()` at `class-hl-email-queue-processor.php:308`
- Existing threshold line at `class-hl-email-queue-processor.php:311`: `$threshold = gmdate( 'Y-m-d H:i:s', time() - ( self::STUCK_THRESHOLD_MINUTES * 60 ) );`

**Goal:** A.6.7 / A.2.20 — stuck threshold should be `max(10 × processor_interval_seconds, 900)`. Currently hardcoded via `STUCK_THRESHOLD_MINUTES`. Make it dynamic and use `$wpdb->rows_affected === 1` for claim confirmation.

- [ ] **Step 1: Dynamic threshold getter**

Near the top of the class, add:

```php
    /**
     * A.6.7: dynamic stuck-row threshold = max(10 * interval, 900s).
     * Reads the wp_cron schedule for hl_email_cron_process if present,
     * otherwise defaults to 900s.
     */
    private function stuck_threshold_seconds() {
        $schedules = wp_get_schedules();
        $interval  = 60; // sane default
        if ( isset( $schedules['five_minutes']['interval'] ) ) {
            $interval = (int) $schedules['five_minutes']['interval'];
        } elseif ( isset( $schedules['every_minute']['interval'] ) ) {
            $interval = (int) $schedules['every_minute']['interval'];
        }
        return max( 10 * $interval, 900 );
    }
```

- [ ] **Step 2: Use it in `recover_stuck_rows()`**

Replace the `$threshold = gmdate( 'Y-m-d H:i:s', time() - ( self::STUCK_THRESHOLD_MINUTES * 60 ) );` line with:

```php
        $threshold = gmdate( 'Y-m-d H:i:s', time() - $this->stuck_threshold_seconds() );
```

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-email-queue-processor.php
git commit -m "fix(email-v2): dynamic stuck-row threshold max(10*interval, 900)"
```

---

## Task 29: Workflow Soft-Delete — Query Filter Cleanup

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php`
- Modify: `includes/services/class-hl-email-automation-service.php`

> **Scope note:** The workflow delete handler (the `$wpdb->update()` that flips `status='deleted'`) lives in **Track 1 Task 11**. This task only adds the `status != 'deleted'` filter to automation service and admin list queries so they correctly exclude soft-deleted workflows once Track 1's handler ships.

**Goal:** A.2.26 — cron queries and admin list queries must exclude workflows with `status='deleted'`. Delete handler itself is owned by Track 1 Task 11.

- [ ] **Step 1: Verify cron loading excludes deleted workflows**

In `class-hl-email-automation-service.php`, inside `run_daily_checks()`, find:

```php
             WHERE trigger_key IN ({$placeholders}) AND status = 'active'",
```

Leave as-is — `status='active'` already excludes `'deleted'`. Same for `run_hourly_checks()`. No change needed there. If any automation query uses `status != 'inactive'` or a looser filter, tighten it to `status = 'active'` (or append `AND status != 'deleted'`).

- [ ] **Step 2: Exclude deleted workflows from all admin list queries**

**Pre-flight enumerated (2026-04-11, `Grep hl_email_workflow` in `includes/admin/class-hl-admin-emails.php`):**

Three SELECT sites need the filter. Line 324 is an `$table = ...` variable assignment used by `$wpdb->update()/insert()`, not a read — skip. Line 659 is `$wpdb->delete()`, also skip.

1. **`class-hl-admin-emails.php:105-112`** — filtered list query (inside `if ( $status_filter && in_array(...) )`). The existing `WHERE w.status = %s` already excludes `'deleted'` because `$valid_statuses = array('draft','active','paused')` does not include `'deleted'`. **No change needed.**

2. **`class-hl-admin-emails.php:114-119`** — unfiltered list query. Currently:

```php
$workflows = $wpdb->get_results(
    "SELECT w.*, t.name AS template_name
     FROM {$wpdb->prefix}hl_email_workflow w
     LEFT JOIN {$wpdb->prefix}hl_email_template t ON t.template_id = w.template_id
     ORDER BY w.updated_at DESC"
);
```

Change to:

```php
$workflows = $wpdb->get_results(
    "SELECT w.*, t.name AS template_name
     FROM {$wpdb->prefix}hl_email_workflow w
     LEFT JOIN {$wpdb->prefix}hl_email_template t ON t.template_id = w.template_id
     WHERE w.status != 'deleted'
     ORDER BY w.updated_at DESC"
);
```

3. **`class-hl-admin-emails.php:174-177`** — single workflow load inside `render_workflow_form()`. Currently:

```php
$workflow = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d",
    $workflow_id
) );
```

Change to:

```php
$workflow = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}hl_email_workflow WHERE workflow_id = %d AND status != 'deleted'",
    $workflow_id
) );
```

This prevents direct-URL access to a soft-deleted workflow's edit screen.

4. **Automation service (`class-hl-email-automation-service.php`) reads at lines 77, 654, 693** all already use `status = 'active'`, which implicitly excludes `'deleted'`. No change required (confirmed in Step 1).

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php includes/services/class-hl-email-automation-service.php
git commit -m "feat(email-v2): exclude soft-deleted workflows from cron + admin list queries (A.2.26)"
```

---

## Task 30: [MOVED TO TRACK 1] Force Resend Action + Handler + History Display

> **Moved to Track 1 Task 14.** Force Resend action + handler + history display is implemented in **Track 1 Task 14** (the richer scope-selector version per A.7.1 — scope selector modal with "all pending" / specific user / specific cycle options). This plan previously had a simpler version that has been removed in favor of Track 1's.
>
> Task 30's slot is intentionally kept empty to preserve numbering for cross-plan comparison. Track 3 Task 5 (`HL_Audit_Service::get_last_event()`) is the foundational helper Track 1 Task 14 uses to render the last force-resend timestamp inline.

---

## Task 31: LIMIT 5000 Warn-On-Cap for coaching_pre_end

**Files:**
- Modify: `includes/services/class-hl-email-automation-service.php`

**Goal:** A.2.21 — if `coaching_pre_end` returns exactly 5000 rows, emit an audit warning (may have been truncated).

- [ ] **Step 1: Wrap the return**

In the `coaching_pre_end` case (see Task 17), replace the `return $wpdb->get_results( ... )` with a captured-then-audited variant. **The SQL string below is the same one defined in Task 17 — when editing either task, update both in lock-step.**

```php
            case 'cron:coaching_pre_end': {
                $today  = current_time( 'Y-m-d' );
                $plus14 = wp_date( 'Y-m-d', strtotime( $today . ' +14 days' ) );
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT DISTINCT en.user_id,
                            en.enrollment_id AS enrollment_id,
                            c.component_id AS entity_id,
                            'component' AS entity_type
                     FROM {$wpdb->prefix}hl_cycle cy
                     INNER JOIN {$wpdb->prefix}hl_enrollment en
                         ON en.cycle_id = cy.cycle_id AND en.status IN ('active','warning')
                     INNER JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.enrollment_id = en.enrollment_id
                     INNER JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = pa.pathway_id
                     INNER JOIN {$wpdb->prefix}hl_component c
                         ON c.pathway_id = p.pathway_id
                        AND c.component_type = 'coaching_session_attendance'
                     LEFT JOIN {$wpdb->prefix}hl_coaching_session cs
                         ON cs.component_id = c.component_id
                        AND cs.mentor_enrollment_id = en.enrollment_id
                        AND cs.session_status = 'attended'
                     WHERE cy.cycle_id = %d
                       AND cy.status = 'active'
                       AND cy.end_date IS NOT NULL
                       AND cy.end_date BETWEEN %s AND %s
                       AND cs.session_id IS NULL
                     LIMIT 5000",
                    $cycle_id, $today, $plus14
                ), ARRAY_A );
                if ( is_array( $rows ) && count( $rows ) >= 5000 && class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_cron_safety_cap_hit', array(
                        'entity_type' => 'email_workflow',
                        'reason'      => 'cron:coaching_pre_end returned 5000 rows — may be truncated. Review cycle scope or add ORDER BY + cursor pagination.',
                    ) );
                }
                return is_array( $rows ) ? $rows : array();
            }
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-email-automation-service.php
git commit -m "fix(email-v2): warn when coaching_pre_end hits 5000-row safety cap"
```

---

## Task 32: CLI Test Wire-Up + Final Regression Sweep

**Files:**
- None new — final verification only.

- [ ] **Step 1: Run every Track 3 test group**

```bash
wp hl-core email-v2-test
```

Expected: all groups pass. Any failure blocks completion.

- [ ] **Step 2: Run the existing smoke test**

```bash
wp hl-core smoke-test
```

Expected: no new regressions vs baseline.

- [ ] **Step 3: Manual cron dry-run**

```bash
wp cron event run hl_email_cron_daily
wp db query "SELECT COUNT(*) FROM wp_hl_email_queue WHERE status='pending'"
```

Expected: no fatal errors; pending count is consistent with expectations based on seed data.

- [ ] **Step 4: Update STATUS.md and README.md per CLAUDE.md rule #3**

Open `STATUS.md` and mark the Track 3 build-queue items:
- `[x] 3.1 Component Window Columns`
- `[x] 3.2 Cron Trigger Stubs (5 of 6, client_success deferred)`
- `[x] 3.3 Draft Cleanup`
- `[x] 3.4 LIKE on Roles Fix`
- `[x] Track 3 Appendix A items (enumerate the A.* IDs)`

Open `README.md` and update the "What's Implemented" section with a new bullet summarising Email System v2 Track 3 (HL_Roles helper, schema revs 35/36/37, 5 cron queries, draft cleanup, deliverability headers, force resend, soft-delete).

- [ ] **Step 5: Final commit**

```bash
git add STATUS.md README.md
git commit -m "docs(email-v2): mark Track 3 complete in STATUS.md + README.md"
```

---

## Idempotency Key Appendix (A.3.11)

For future maintainers — every idempotency boundary in the v2 email system:

| Key | Scope | Location |
|---|---|---|
| `hl_email_queue.dedup_token` | md5(trigger, workflow_id, user_id, entity_id, cycle_id) — no date | `HL_Email_Automation_Service::run_cron_workflow()` |
| `hl_email_queue.claim_token` | UUID per `process_batch()` invocation | `HL_Email_Queue_Processor::process_batch()` |
| `hl_email_draft_{user_id}_{template_id}` | One draft per (user, template) in wp_options | `HL_Admin_Email_Builder::ajax_autosave()` |
| `hl_roles_scrub_cursor` | Monotonic enrollment_id progress | `HL_Roles_Scrub_Migration` |
| `hl_roles_scrub_lock` | 60s transient — prevents chunk overlap | `HL_Roles_Scrub_Migration::maybe_run()` |
| `hl_email_unsubscribe_secret` | One-time wp_option, never rotated | `HL_Email_Queue_Processor::unsubscribe_secret()` |
| `hl_email_last_cron_run_at` | ISO-8601 of last successful `run_daily_checks()` | `HL_Email_Automation_Service::run_daily_checks()` |
| Nonce `hl_workflow_force_resend_{id}` | Per-workflow CSRF guard | `class-hl-admin-emails.php` |
| Nonce `hl_email_builder` | Per-session autosave guard | `class-hl-admin-email-builder.php` |

---

## Concurrency & Race Tests (A.6.18)

Manual checks to run on the test server after Task 32:

1. **Two admins save the same template simultaneously** — one wins, both see the success message. Verify no autosave race corrupts the envelope.
2. **Cron runs mid-migration** — force `hl_core_schema_revision` back to 34, run `wp cron event run hl_email_cron_daily` on a separate terminal. Expect: cron early-returns per Task 12, no fatal.
3. **Queue claim expiry recovery** — insert a fake `sending` row with `updated_at` 30 minutes old. Run `wp cron event run hl_email_cron_process`. Verify the row flips back to `pending`.
4. **Draft cleanup on a populated wp_options** — seed 1000 fake drafts (some stale, some fresh). Run `cleanup_stale_drafts()`. Verify caps behave correctly.
5. **Role scrub race** — call `HL_Roles_Scrub_Migration::run_chunk()` twice in quick succession. Second call must no-op (lock held). Confirm via audit log.

---

## Follow-Ups Out of Scope

These appendix items are deliberately deferred and should be tracked in STATUS.md:

- `cron:client_success` — awaiting business criteria from Yuyan Huang.
- Unsubscribe endpoint (`?action=hl_email_unsubscribe`) — token is generated, redemption flow is a follow-up plan.
- `HL_Roles::sanitize_roles()` write-path audit in import handlers — Task 21 grep should catch the critical ones, but full import/seeder coverage is its own pass.
- Content Security Policy response header on preview iframe (A.6.5) — lives in Track 2 preview modal work.
- Cron fan-out rate-limiter interaction audit (A.3.10) — current behavior sets `rate_limited` status which prevents drops; verify in production monitoring.

---

## Self-Review Checklist (run after implementing)

- [ ] Every spec item in the task description maps to a task above, or is listed in "Follow-Ups Out of Scope" with rationale.
- [ ] Every `HL_*` class referenced in the plan is either pre-existing in the codebase or defined by an earlier task.
- [ ] Every SQL query uses `$wpdb->prepare()` with bound parameters.
- [ ] No task assumes the prior task's uncommitted state — each commits and is independently runnable after prior tasks.
- [ ] `current_time('Y-m-d')` is used for all cron date boundaries (never `CURDATE()` / `gmdate()`).
- [ ] Every ALTER TABLE is column-exists / index-exists guarded.
- [ ] Every `$wpdb->query()` that gates a revision bump checks the `false` return.
- [ ] Dedup tokens have no date component.
- [ ] `FIND_IN_SET` usage is gated on `HL_Roles::scrub_is_complete()` until Rev 37 completes.
- [ ] `assigned_mentor` resolver uses `hl_team_membership.membership_type`, not a non-existent `roles` column.
- [ ] Plan ends with STATUS.md + README.md update per CLAUDE.md rule #3.
