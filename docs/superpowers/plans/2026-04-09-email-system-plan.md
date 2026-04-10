# Email System -- Implementation Plan

**Date:** 2026-04-09
**Spec:** `docs/superpowers/specs/2026-04-09-email-system-design.md`

---

## Phase 1: Foundation (DB + Shared Services)

### Task 1.1: Database Tables (Complexity: M)

**Files:** `includes/class-hl-installer.php`

- Add 4 `CREATE TABLE` statements to `get_schema()`: `hl_email_template`, `hl_email_workflow`, `hl_email_queue`, `hl_email_rate_limit`
- Bump schema revision 33 -> 34 in `maybe_upgrade()`
- Register cron schedules in `activate()` hook (via `register_activation_hook`), **not** in `maybe_upgrade()`:
  - `hl_email_process_queue` (5-min via custom interval)
  - `hl_email_cron_daily`
  - `hl_email_cron_hourly`
- Add cron cleanup to `deactivate()`

**Depends on:** Nothing
**Verify:** `wp db query "SHOW TABLES LIKE '%hl_email%'"` shows 4 tables

---

### Task 1.2: Block Renderer (Complexity: L)

**Files:** Create `includes/services/class-hl-email-block-renderer.php`

- Singleton with `render()`, `render_blocks_only()`, `build_legacy_template_blocks()`
- Outer document shell with branded header/footer, dark mode, MSO conditionals
- 6 block renderers: text, image, button (VML), divider, spacer, columns
- Merge tag substitution with `esc_html()` on values + strip unresolved tags
- Logo URL as class constant

**Depends on:** Nothing
**Verify:** Unit test: render sample blocks array -> verify table-based HTML output

---

### Task 1.3: Merge Tag Registry (Complexity: M)

**Files:** Create `includes/services/class-hl-email-merge-tag-registry.php`

- Singleton. Register all 30+ tags in constructor with label, resolver callback, and category
- `resolve_all($context)`: returns key -> value map for a given context array
- `get_available_tags($category)`: returns tag list for builder UI
- URL tags that are truly global (`login_url`, `dashboard_url`) cache their resolved value in a static variable within the registry resolver. Resolved once per process, reused across all emails in the same batch.
- Per-enrollment URL tags (`program_page_url`, `coaching_schedule_url`, `cv_form_url`, `rp_session_url`) are resolved per-recipient from context and must NOT be cached across a batch.

**Depends on:** Nothing
**Verify:** Call `resolve_all` with test context, verify all tags resolve

---

### Task 1.4: Plugin Wiring (Complexity: S)

**Files:** `hl-core.php`

- Add `require_once` for renderer and registry
- Add 5-min custom cron interval via `cron_schedules` filter
- Add `wp_login` hook for `hl_account_activated` and `last_login` usermeta
- Add `init`-time `wp_next_scheduled()` check for all 3 cron events (`hl_email_process_queue`, `hl_email_cron_daily`, `hl_email_cron_hourly`). Re-register any missing events. This guards against lost cron entries.

**Depends on:** Tasks 1.2, 1.3
**Verify:** Plugin loads without errors, cron schedules appear in `wp cron event list`

---

## Phase 2: Automation Engine (Priority -- Build First)

### Task 2.1: Rate Limit Service (Complexity: S)

**Files:** Create `includes/services/class-hl-email-rate-limit-service.php`

- `check($user_id)`: returns true if under all limits. Uses floor-aligned time buckets: hourly `window_start` = floor to top of hour (e.g., 14:00:00), daily = floor to midnight (UTC), weekly = floor to Monday midnight (UTC). Check: `SELECT send_count WHERE user_id=%d AND window_key=%s AND window_start=%s`. Known tradeoff: a burst at an hour boundary can send up to 2x the hourly limit across 2 buckets. Acceptable for a safety net, not a hard guarantee.
- `increment($user_id)`: `INSERT INTO hl_email_rate_limit (user_id, window_key, window_start, send_count) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE send_count = send_count + 1`
- Reads limits from `wp_options` (`hl_email_rate_limit_hour`/`day`/`week`)

**Depends on:** Task 1.1
**Verify:** Increment 6 times for same user, `check` returns false on 6th (default limit 5/hr)

---

### Task 2.2: Condition Evaluator (Complexity: M)

**Files:** Create `includes/services/class-hl-email-condition-evaluator.php`

- `evaluate(array $conditions, array $context)`: bool
- Evaluate JSON conditions against a pre-populated context array. **No DB lookups in the evaluator** — the automation service owns hydration.
- Resolves field prefixes (`user.*`, `cycle.*`, `enrollment.*`, etc.) from the passed context array
- 8 operators: `eq`, `neq`, `in`, `not_in`, `gt`, `lt`, `is_null`, `not_null`
- All conditions ANDed

**Depends on:** Nothing
**Verify:** Test with sample conditions against mock context

---

### Task 2.3: Recipient Resolver (Complexity: M)

**Files:** Create `includes/services/class-hl-email-recipient-resolver.php`

- `resolve(array $recipient_config, array $context)`: array of `{email, user_id, type}`
- Token resolution:
  - `triggering_user` -- from context
  - `assigned_coach` -- via `hl_coach_assignment`
  - `school_director` -- via org unit hierarchy
  - `cc_teacher` -- from visit context
  - `role:X` -- all users with WordPress role X enrolled in the triggering cycle (`JOIN hl_enrollment WHERE cycle_id = context.cycle_id`)
  - `static:email` -- literal email address

**Depends on:** Nothing
**Verify:** Test each token type with real DB data

---

### Task 2.4: Queue Processor (Complexity: L)

**Files:** Create `includes/services/class-hl-email-queue-processor.php`

- `enqueue(array $data)`: inserts row with dedup_token check, renders `body_html` at insertion time. When `dedup_token` is NULL, the dedup check is skipped entirely -- the row is inserted unconditionally. This is the mechanism for admin overrides on manual sends.
- `process_batch(int $limit = 50)`: Generate UUID via `wp_generate_uuid4()`. Atomic claim via `UPDATE SET status='sending', claim_token='<UUID>' WHERE status='pending' AND scheduled_at <= NOW() LIMIT 50`, then `SELECT WHERE claim_token='<UUID>'` to fetch claimed rows. For each row: resolve deferred tags using `recipient_user_id` from the queue row (NOT from `context_data`) -- scan `body_html` for `{{password_reset_url}}`, generate fresh key via `get_password_reset_key($user)`, substitute. Then call `wp_mail()`, update status. On failure, store error message in `failed_reason`.
- Stuck-row recovery: reset `sending` > 10 min to `pending`
- Rate limit check before each send
- 3 retry attempts, exponential backoff
- Audit logging on send/failure

**Depends on:** Tasks 1.1, 1.2, 1.3, 2.1
**Verify:** Enqueue test email, run `process_batch`, verify `wp_mail` called and status = `sent`

---

### Task 2.5: Automation Service (Complexity: L)

**Files:** Create `includes/services/class-hl-email-automation-service.php`

- Singleton. Constructor registers `add_action` listeners for all hook-based triggers
- `handle_trigger($trigger_key, ...$args)`: loads matching workflows, evaluates conditions, resolves recipients, enqueues. For hook-based (immediate) triggers, the dedup token includes the triggering entity ID instead of date_bucket: `md5(trigger_key + '_' + workflow_id + '_' + user_id + '_' + entity_id)`. Where `entity_id` is the primary key of the triggering entity (e.g., `session_id` for `coaching_session_created`, `enrollment_id` for `enrollment_created`, `instance_id` for `assessment_submitted`). This ensures two different sessions booked on the same day are not deduped against each other.
- `compute_scheduled_at()`: applies `delay_minutes` + send window logic
- `run_daily_checks()`: polls DB for cron-based triggers (CV windows, RP windows, unscheduled coaching, overdue forms, low engagement). Batch-loads enrollment + cycle + pathway data before iterating cron loops (hydrates context once, not per-user).
- `run_hourly_checks()`: polls for -24h and -1h coaching reminders. Same batch-load pattern.
- Dedup tokens for cron checks: `md5(trigger_key + workflow_id + user_id + cycle_id + date_bucket)`

**Depends on:** Tasks 2.1, 2.2, 2.3, 2.4
**Verify:** Create test workflow, fire trigger, verify queue row created with correct `body_html`

---

### Task 2.6: Plugin Wiring for Automation (Complexity: S)

**Files:** `hl-core.php`

- `require_once` for all 5 new services
- Register cron action hooks:
  - `hl_email_process_queue` -> `queue_processor->process_batch()`
  - `hl_email_cron_daily` -> `automation->run_daily_checks()`
  - `hl_email_cron_hourly` -> `automation->run_hourly_checks()`

**Depends on:** All Phase 2 tasks
**Verify:** `wp cron event run hl_email_process_queue` processes pending rows

---

## Phase 3: Email Builder

### Task 3.1: Admin Email Builder Page (Complexity: L)

**Files:** Create `includes/admin/class-hl-admin-email-builder.php`

- Singleton. Renders two-panel editor layout
- Call `wp_enqueue_media()` on the builder page. Enqueue dependencies: `jquery`, `wp-color-picker`, `sortablejs` (CDN).
- AJAX handlers:
  - `hl_email_template_save` -- save template
  - `hl_email_template_autosave` -- debounced draft save to `hl_email_draft_{user_id}_{template_id}` via `update_option(..., false)` (autoload=no)
  - `hl_email_preview_search` -- enrollment autocomplete
  - `hl_email_preview_render` -- returns a nonce-protected preview URL (not raw HTML); iframe `src` points at this endpoint
- Template CRUD: create, update, archive
- Draft vs published logic
- On template deletion, delete associated draft options via `$wpdb->query($wpdb->prepare('DELETE FROM {$wpdb->options} WHERE option_name LIKE %s', 'hl_email_draft_%_' . $template_id))`

**Depends on:** Tasks 1.1, 1.2, 1.3
**Verify:** Create template, add blocks, save, preview with enrollment

---

### Task 3.2: Builder JavaScript (Complexity: L)

**Files:** Create `assets/js/admin/email-builder.js`

- Sortable.js integration for block drag/drop
- Block CRUD (add, delete, reorder, duplicate)
- Contenteditable text editing with mini-toolbar (bold, italic, link, merge tag dropdown only — alignment and font size deferred to v2)
- Image block with WP Media Library integration
- Button block with URL/WP link selector and color picker
- Autosave: debounce 3s -> AJAX + localStorage backup
- Preview: enrollment search, iframe render, desktop/mobile/dark toggles
- Email Health panel: real-time subject line checks
- SVG upload blocking

**Depends on:** Task 3.1
**Verify:** Full editor workflow in browser: add blocks, edit, save, preview

---

## Phase 4: Admin UI

### Task 4.1: Emails Admin Page (Complexity: L)

**Files:** Create `includes/admin/class-hl-admin-emails.php`

- 4 tabs: Automated Workflows, Email Templates, Send Log, Settings
- **Automated Workflows:** list table with CRUD, status badges, row actions. Workflow editor form (trigger dropdown, conditions builder, recipients, template, delay, send window)
- **Email Templates:** list table with status filter pills, links to builder
- **Send Log:** filterable list with status-based row actions, bulk actions
- **Settings:** rate limits, queue health, migration buttons, retry failed
- Password reset URL expiry warning in workflow editor

**Depends on:** Phase 2 complete, Task 3.1
**Verify:** Full admin workflow: create workflow, assign template, activate, check send log

---

### Task 4.2: Menu Registration (Complexity: S)

**Files:** `includes/admin/class-hl-admin.php`, `hl-core.php`

- Add Emails submenu under Housman LMS
- `require_once` for admin classes

**Depends on:** Task 4.1
**Verify:** Menu item appears, page loads without errors

---

## Phase 5: Manual Sends

### Task 5.1: Cycle Emails Tab Rewrite (Complexity: M)

**Files:** `includes/admin/class-hl-admin-cycles.php`

- Replace `render_tab_emails()` with new universal manual sends UI
- Template select dropdown + custom option
- Recipient filter bar (role, pathway, school) + AJAX-filtered checkbox table
- Send Now / Schedule For with date/time picker
- Send history section (union of `hl_email_queue` + legacy `hl_cycle_email_log`)
- Legacy invitation section collapsed with deprecation notice
- AJAX handler: `hl_cycle_manual_send`
- Manual send dedup token: `md5('manual_' + template_id + '_' + user_id + '_' + cycle_id + '_' + wp_date('Y-m-d', time(), new DateTimeZone('America/New_York')))`. The 24-hour dedup window is calendar-day-based (America/New_York), not rolling. **Important:** Use `wp_date()` with explicit `America/New_York` timezone — `date('Y-m-d')` uses server timezone (UTC on AWS) and will produce wrong dedup keys for ET evening sends. Admin override bypasses dedup by setting `dedup_token` to NULL.

**Depends on:** Phase 2 (queue processor), Phase 3 (templates exist)
**Verify:** Select recipients in a cycle, send test email, verify in Send Log

---

## Phase 6: Migration & Integration

### Task 6.1: Legacy Template Migration (Complexity: S)

**Files:** Create `includes/migrations/class-hl-email-template-migration.php`

- Reads 6 coaching templates from `wp_options`
- Wraps body HTML in single text block
- Inserts as `hl_email_template` rows with `status=active`
- Idempotent (checks completion flag in `wp_options`)

**Depends on:** Tasks 1.1, 1.2
**Verify:** Run migration, verify 6 rows in `hl_email_template`, re-run is no-op

---

### Task 6.1b: Backfill hl_account_activated (Complexity: S)

**Files:** `includes/migrations/class-hl-email-template-migration.php` (or new migration file)

- One-time migration: `INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) SELECT DISTINCT e.user_id, 'hl_account_activated', '1' FROM {$wpdb->prefix}hl_enrollment e WHERE e.user_id NOT IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='hl_account_activated')`. All queries use `$wpdb->usermeta`, `$wpdb->prefix . 'hl_enrollment'`, etc. Never hardcode the table prefix.
- Idempotent (checks completion flag `hl_email_migration_backfill_activated` in `wp_options`)
- Prevents invitation emails from firing for already-active users when workflows are first activated

**Depends on:** Task 1.1
**Verify:** Run migration, verify usermeta rows created for enrolled users. Re-run is no-op.

---

### Task 6.2: Hook Verification Audit (Complexity: S)

**Files:** Review all service files for missing `do_action` calls

- Verify all 13 hook-based triggers exist in codebase (9 added this session + 4 pre-existing)
- Verify argument signatures match what automation service expects
- Document any missing hooks

**Depends on:** Task 2.5
**Verify:** grep for each hook name, confirm exists

---

### Task 6.3: Scheduling Email Service Update (Complexity: S)

**Files:** `includes/services/class-hl-scheduling-email-service.php`

- Update to read from `hl_email_template` table first, fall back to `wp_options`
- Use `HL_Email_Block_Renderer` for rendering when `blocks_json` is available

**Depends on:** Tasks 1.2, 6.1
**Verify:** Send test coaching email, verify uses new renderer

---

## Phase 7: Polish & Security Hardening

### Task 7.1: Security Review (Complexity: S)

- Nonce verification on all AJAX handlers
- Capability checks on all handlers
- Output escaping audit
- Input sanitization audit
- Email header injection prevention
- SQL injection prevention (all queries use `prepare()`)

---

### Task 7.2: Cron Reliability (Complexity: S)

- Verify all cron hooks registered
- Document server cron setup for production
- Add cron cleanup to plugin deactivation
- Add draft cleanup to `run_daily_checks()` in `HL_Email_Automation_Service` (not a separate cron hook). Deletes autosave draft options (`hl_email_draft_%`) older than 30 days.

---

### Task 7.3: Performance Verification (Complexity: S)

- `EXPLAIN` on queue processor batch query
- Verify composite indexes used
- Test with 100+ queue rows

---

### Task 7.4: Component Window Columns (Complexity: S)

- Verify `available_from`/`available_to` exist on `hl_component`
- If missing, add via `ALTER TABLE` in `maybe_upgrade()`
- Required for cron triggers REM-2, REM-4, REM-5

---

## Build Sequence Checklist

```
Phase 1: Foundation
  [ ] 1.1 Database tables (rev 34)
  [ ] 1.2 Block renderer
  [ ] 1.3 Merge tag registry
  [ ] 1.4 Plugin wiring

Phase 2: Automation Engine
  [ ] 2.1 Rate limit service
  [ ] 2.2 Condition evaluator
  [ ] 2.3 Recipient resolver
  [ ] 2.4 Queue processor
  [ ] 2.5 Automation service
  [ ] 2.6 Automation wiring

Phase 3: Email Builder
  [ ] 3.1 Admin builder page
  [ ] 3.2 Builder JavaScript

Phase 4: Admin UI
  [ ] 4.1 Emails admin page
  [ ] 4.2 Menu registration

Phase 5: Manual Sends
  [ ] 5.1 Cycle emails tab rewrite

Phase 6: Migration
  [ ] 6.1 Legacy template migration
  [ ] 6.1b Backfill hl_account_activated
  [ ] 6.2 Hook verification audit
  [ ] 6.3 Scheduling email service update

Phase 7: Hardening
  [ ] 7.1 Security review
  [ ] 7.2 Cron reliability
  [ ] 7.3 Performance verification
  [ ] 7.4 Component window columns
```

---

## New Files to Create (11)

| # | File Path |
|---|-----------|
| 1 | `includes/services/class-hl-email-block-renderer.php` |
| 2 | `includes/services/class-hl-email-merge-tag-registry.php` |
| 3 | `includes/services/class-hl-email-rate-limit-service.php` |
| 4 | `includes/services/class-hl-email-condition-evaluator.php` |
| 5 | `includes/services/class-hl-email-recipient-resolver.php` |
| 6 | `includes/services/class-hl-email-queue-processor.php` |
| 7 | `includes/services/class-hl-email-automation-service.php` |
| 8 | `includes/admin/class-hl-admin-emails.php` |
| 9 | `includes/admin/class-hl-admin-email-builder.php` |
| 10 | `assets/js/admin/email-builder.js` |
| 11 | `includes/migrations/class-hl-email-template-migration.php` |

## Files to Modify (6)

| # | File Path | Changes |
|---|-----------|---------|
| 1 | `hl-core.php` | `require_once`, cron hooks, `wp_login` hook |
| 2 | `includes/class-hl-installer.php` | 4 tables, rev 34, cron schedules |
| 3 | `includes/admin/class-hl-admin.php` | Menu registration |
| 4 | `includes/admin/class-hl-admin-cycles.php` | Emails tab rewrite |
| 5 | `includes/services/class-hl-scheduling-email-service.php` | Use new renderer |
| 6 | `includes/integrations/class-hl-learndash-integration.php` | Verify course completion hook |
