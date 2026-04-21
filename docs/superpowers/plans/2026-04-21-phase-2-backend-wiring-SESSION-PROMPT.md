# Session prompt: Phase 2 — build backend wiring for the 6 email-trigger stubs

> **Paste the entire block below (from the `---` line to the end of this file) into a fresh Claude Code session as the first message.** Memory auto-load will pick up prior context from `MEMORY.md`. Everything else this session needs is inlined.

---

You are continuing work on the HL Core WordPress plugin for Housman Learning Academy. Another Claude session registered 6 stub entries in the email trigger registry for capabilities Chris (the client) needs that don't yet exist on the backend. Your job is to build the backend wiring so those stubs can flip from `wiring_status: stub` to `wiring_status: wired`.

## Do this before you write any code

1. Read `CLAUDE.md` start-to-finish. Pay attention to Rule 0 (deploy via `bin/deploy.sh` — never raw tar/scp) and the 2026-04-20 incident reference.
2. Read `STATUS.md` — look for the "Email Registry Cleanup" section and the "Build Queue" structure. Do not start coding until you understand what's done.
3. Read the plan and progress log for context on why these stubs exist:
   - `docs/superpowers/plans/2026-04-20-email-registry-cleanup.md` (design + §5.1–5.4 scope the exact work you're doing)
   - `docs/superpowers/plans/2026-04-20-email-registry-cleanup-progress.md` (incident log — useful if you need to understand why certain features are on `feature/workflow-ux-m1` and not on `main`)
4. Read the email automation service to understand the existing cron pattern:
   - `includes/services/class-hl-email-automation-service.php` — look for `handle_cron_trigger()`, `get_cron_trigger_users()`, `build_hook_context()`, and how `cron:component_upcoming` / `cron:component_overdue` / `cron:session_upcoming` currently work (the latter is already declared as a valid key but unwired — see §5.2).
5. Read the trigger registry so you know which stub entries map to which backend work:
   - `includes/admin/class-hl-admin-emails.php` → `get_trigger_categories()` — search for `'stub'` to find all 6 entries.
6. Report to the user:
   - What's already on `main` vs `feature/workflow-ux-m1`.
   - What's on test vs prod (read both deploy manifests — commands in `CLAUDE.md`).
   - What the 6 stubs need.
   - Your proposed execution order.
   - Any ambiguities you need Mateo to resolve before coding.

Wait for explicit approval before writing code.

## Current state at session start

- **Branch you should be on:** `feature/workflow-ux-m1` (the de-facto long-lived branch; `main` is 30+ commits behind it and has been since before 2026-04-20).
- **Plugin version:** `1.2.7` in `hl-core.php`. Bump to `1.2.8` when you ship Phase 2.
- **Schema revision:** 42 (`wp_options.hl_core_schema_revision` = 42 on both test and prod). Only touch this if you add new DB columns/indexes, following the existing `HL_Installer::get_schema()` + migration pattern.
- **Test env:** `test.academy.housmanlearning.com` (AWS Lightsail, `/opt/bitnami/wordpress/wp-content/plugins/hl-core/`). Fully up to date with `feature/workflow-ux-m1`.
- **Prod env:** `academy.housmanlearning.com` (Hostinger). Also at HEAD of `feature/workflow-ux-m1`.
- **Deploy manifest exists** on both environments. The pre-deploy descendant check is ACTIVE. You cannot accidentally roll back prod.

## The 6 stubs to build

Each stub is declared in `get_trigger_categories()` with `wiring_status => 'stub'` and a `stub_note`. When your wiring lands and you've verified it fires correctly, flip `wiring_status` from `stub` to `wired` for that entry.

### 5.1 — `cron:component_overdue` for `classroom_visit` (≈1 dev-day)

**Stub:** `classroom_visit.overdue` → `cron:component_overdue` + `componentType: classroom_visit`.

**Problem:** The existing `cron:component_overdue` handler hardcodes or implicitly filters to `learndash_course` only. Chris needs it to fire for overdue classroom visits too (1 day after the visit window closes, if no visit form is submitted).

**Do:**
1. Find the existing `cron:component_overdue` handler in `class-hl-email-automation-service.php`.
2. Generalise it to accept any `componentType` via the workflow's `component_type_filter` column.
3. For `classroom_visit`, "overdue" means: component state is not `complete`, `complete_by < now() - 24 hours`, and no `hl_classroom_visit_submission` row exists for that component.
4. Respect the existing dedup-token pattern (don't re-send if already sent for this component_state_id).
5. Respect the 48h overdue tolerance window already in the cron engine (see the existing course-overdue logic for precedent).

**Don't:**
- Introduce a new cron event key. `cron:component_overdue` already exists; you're just removing the course-only restriction.
- Change schema. The fields you need (`hl_component.complete_by`, `hl_component_state.state`, `hl_classroom_visit_submission.component_id`) already exist.

**Verification:**
- CLI test in the existing `bin/test-email-v2-trackN.php` style that mocks a classroom_visit component overdue by 25 hours and asserts the cron picks it up.
- `wp hl-core` subcommand (or `wp eval`) to manually invoke the cron on test and check the audit log / queue.

### 5.2 — `cron:session_upcoming` anchored to `hl_coaching_session.session_datetime` (≈2 dev-days)

**Stubs (3):** `coaching.reminder_5d_before_session`, `_24h_before_session`, `_1h_before_session`. All map to `cron:session_upcoming` with different offsets.

**Problem:** `cron:session_upcoming` is already in the valid-keys list (pre-existing from Rev 39 work) but the existing upcoming-cron handler anchors on `hl_component.display_window_start`. Chris's reminders anchor on the actual booked `hl_coaching_session.session_datetime` instead. These are different columns and different semantics.

**Do:**
1. Confirm whether `cron:session_upcoming` has an existing handler stub or is fully unwired. Grep for it.
2. Implement the handler: query `hl_coaching_session` rows where `session_datetime` is approaching within the workflow's configured `trigger_offset_minutes` (e.g., 5 days = 7200 min, 24 h = 1440 min, 1 h = 60 min).
3. **Dedup must include the offset value** in the token. A workflow with 5d offset must not dedup against the same session's 24h workflow. Existing dedup tokens look like `<trigger_key>_<workflow_id>_<entity_id>`; you may need `<trigger_key>_<workflow_id>_<entity_id>_<offset>`.
4. Fuzz window (tolerance for cron-timing drift) must scale with offset: 1h reminder needs tighter fuzz (~6 min) than 5d reminder (~30 min). The existing course-upcoming cron has a scaled-fuzz helper; reuse it.
5. Only fire for sessions in `scheduled` status (not `cancelled`, `attended`, `missed`, `rescheduled`).

**Don't:**
- Change `hl_coaching_session` schema. The column you need (`session_datetime`) exists from the scheduling integration work.
- Add new offset presets. The workflow's `trigger_offset_minutes` column already supports arbitrary minute values; let the admin set whatever they want.

**Verification:**
- CLI test mocking 3 sessions at T+5d, T+24h, T+1h and 3 workflows at those offsets. All 3 should fire exactly once each, no cross-dedup.
- CLI test mocking a session in `cancelled` state — should NOT fire.
- Manual: on test, create a coaching session scheduled 2h in the future, run `cron:session_upcoming` via `wp eval`, confirm queue row with offset-scoped dedup token.

### 5.3 — `cron:post_session_form_pending` compound trigger (≈1.5 dev-days)

**Stubs (2):** `coaching.action_plan_incomplete_24h_after` (`formType: action_plan`) and `coaching.notes_incomplete_24h_after` (`formType: coaching_notes`). Both map to a new cron key `cron:post_session_form_pending`.

**Problem:** Need a compound trigger: (a) session was marked `attended` ≥ 24 hours ago, AND (b) the specified form has not been submitted. Chris wants to nudge mentors to complete action plans and coaches to complete session notes.

**Do:**
1. Add `cron:post_session_form_pending` to the valid-keys whitelist — note that `get_valid_trigger_keys()` now derives this from the registry, so it will auto-include the stubs' keys as soon as you flip them to `wired`. But you need a handler to exist before flipping.
2. Implement the handler in `class-hl-email-automation-service.php`:
   - Query `hl_coaching_session` rows with `status = 'attended'` AND `updated_at < now() - 24h` (or a dedicated `attended_at` column if one exists; check).
   - Filter by the workflow's `formType` context — this probably needs a new field or a condition read. Most aligned with existing patterns: store `formType` in the workflow's `conditions` JSON and read it in the handler.
   - For `action_plan`: check `hl_coaching_session_submission` for the session_id with a form-type matching action_plan. If absent, fire.
   - For `coaching_notes`: similar but the coach's notes field on `hl_coaching_session` may already exist (`coach_notes` or similar) — grep to confirm.
3. Dedup key should include session_id + formType so the two workflows don't collide.
4. Respect the admin-bypass audit pattern (if admin manually marked the form submitted, don't fire).

**Don't:**
- Add a new DB table for this. Existing `hl_coaching_session` + `hl_coaching_session_submission` tables have what you need.
- Fire on sessions that are `cancelled` or `missed` — only `attended` matters.

**Verification:**
- CLI test: attended session 25h ago, no action plan submission → fires.
- CLI test: attended session 25h ago, action plan IS submitted → does NOT fire.
- CLI test: cancelled session 25h ago → does NOT fire.
- Manual: on test, use the coaching UI to mark a session attended, wait (or time-travel the DB), run cron, check audit log.

### 5.4 — Tests + QA (≈1 dev-day)

- Write a new file `bin/test-email-phase2-stubs.php` following the structure of the existing `bin/test-email-v2-track1.php` (read it for pattern).
- Each of the 3 cron types gets its own test section with mock data setup, invocation, assertion, cleanup.
- Add a "Test" row to STATUS.md under the Email Registry Cleanup section — marked `[x]` once all assertions pass.
- Run the new test suite on test via `wp eval-file bin/test-email-phase2-stubs.php`.

## Flipping stubs to wired (final step per trigger)

Only after the corresponding backend wiring is verified AND tested on the test environment:

1. Edit `includes/admin/class-hl-admin-emails.php` → `get_trigger_categories()`.
2. For the specific stub entry, change `'wiring_status' => 'stub'` to `'wiring_status' => 'wired'`.
3. Remove the `'stub_note' => '...'` line (optional — doesn't hurt to keep, but clean).
4. Reload the Email Builder admin page and confirm the option is now selectable (not disabled).
5. In a single commit per stub, or all at end — your call based on how you verified.

## Rules

### Deploy rules (HARD)

- **Every deploy goes through `bash bin/deploy.sh test` or `bash bin/deploy.sh prod`.** No exceptions.
- **Deploy to prod requires explicit approval from Mateo** per session. Don't assume prior approval transfers.
- If the deploy script aborts with "NOT a descendant," READ the manifest on the target before trying `--force`. The abort is almost always correct — it means another session shipped work you don't have.
- Do not delete `.deploy-manifest.json` to "force a clean state." That kills the guardrail.

### Doc rules

Every feature commit must update both:
- **STATUS.md** — mark `[~]` during development, `[x]` when complete, with concrete notes (not just checkmarks). Locate the "Email Registry Cleanup" section; add a "Phase 2" sub-block.
- **README.md** — under the existing "Email Registry Cleanup + Deploy Guardrails" section, add a bullet per shipped stub.

Before context compaction: commit, push, update docs, push again, then compact.

### Code conventions

- PHP 7.4+, WordPress coding standards, `HL_` class prefix, `hl_` DB prefix, repository pattern, service layer, `HL_Audit_Service::log()` for audits, `manage_hl_core` capability gate.
- Cron handlers must be idempotent — a re-run must not double-send.
- SQL queries touching user data MUST use `$wpdb->prepare()` with placeholders.
- No `console.log` / `error_log` in shipped code unless gated by `WP_DEBUG` or similar. Use `HL_Audit_Service` for observability.

### Things NOT to touch without explicit approval

- **Schema (`hl_core_schema_revision`):** bump only if you add columns/tables, and only with a migration block in `HL_Installer`.
- **Existing `cron:component_upcoming` handler.** You can GENERALISE it for §5.1 but don't break the course-reminder path that's already in production use.
- **Recipient resolver (`class-hl-email-recipient-resolver.php`).** Your new triggers should emit the same context shape the resolver already knows (user_id, session_id, component_id). No new token types unless the spec demands it.
- **The legacy `trigger_key` aliases in `get_legacy_trigger_aliases()`.** Those exist for reverse-map display; they're not your backend targets.
- **The v1 workflow form (`render_workflow_form()` in `class-hl-admin-emails.php`).** You'll see an old `<select name="component_type_filter">` there — it's intentional rollback-escape-hatch code. Leave it.

## When you're done

Open PR on `feature/workflow-ux-m1` (or merge directly if that's Mateo's preference — confirm). Before claiming done, verify:

- [ ] All 6 stubs flipped from `stub` to `wired` in the registry.
- [ ] CLI test suite passes (all assertions).
- [ ] Deployed to test + verified via Playwright or manual browser check.
- [ ] Deployed to prod (with Mateo's explicit approval).
- [ ] Prod manifest updated.
- [ ] STATUS.md + README.md updated and committed.
- [ ] `wp hl-core` post-deploy checks show no regressions (cron history, audit log health).

## If you get stuck

- Confused about a spec detail: stop and ask Mateo. The spec is `2026-04-20-email-registry-cleanup.md` §5.
- Can't find where an existing hook is wired: grep the whole plugin, then `sfwd-lms/` (LearnDash sibling plugin) if it's LD-adjacent.
- Deploy script aborts unexpectedly: read `project_deploy_guardrails_2026_04.md` in memory, check the manifest on the target, investigate.
- Something on prod looks "missing": read `project_rollback_incident_2026_04_20.md` in memory — there's a known-good procedure.

Memory files are auto-loaded at session start. The four most relevant for this task:
- `project_email_registry_cleanup_2026_04.md` — what shipped, what's still stubbed.
- `project_rollback_incident_2026_04_20.md` — context on branch state.
- `project_deploy_guardrails_2026_04.md` — how deploys actually work.
- `feedback_always_use_deploy_script.md` — the non-negotiable deploy rule.

Acknowledge you've read this prompt, STATUS.md, CLAUDE.md, and the plan doc before you start. Then report your proposed execution order. Wait for Mateo's go-ahead.
