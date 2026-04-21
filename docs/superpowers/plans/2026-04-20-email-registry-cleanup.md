# Email Trigger Registry Cleanup — Plan

**Status:** Shipped. Phase 1 on 2026-04-21 (v1.2.7 SHA `eb1c7c9`); Phase 2 on 2026-04-21 (v1.2.9 SHA `7b2ea94`).
**Branch:** originally `feature/email-registry-cleanup`; both phases actually shipped on `feature/workflow-ux-m1` (rebase never executed, work merged forward).
**Date:** 2026-04-20 (plan) — 2026-04-21 (shipped both phases)
**Author:** Mateo + Claude, synthesized from two-agent debate
**Progress log:** `2026-04-20-email-registry-cleanup-progress.md` — read this for the executed change-log entries.
**Spreadsheet reference:** `data/LMS Email Notification List - reorganized.xlsx` (sheet: "Updated - LMS Master")

---

## 1. Background & motivation

Chris (client) asked us to build 19 email workflows for him. While prepping, we spotted two problems with the email workflow builder:

1. **The "Component Type" picker is redundant.** After picking Category → Event (cascade), the picker auto-fills but stays editable. It's a UX artifact left behind when commit `3c53332` (Apr 16, M2 cascade) was bolted on top of commit `f41ff51` (Apr 14, Rev 39 generic triggers). The spec says the cascade is "purely presentational" — so an editable override contradicts the invariant.

2. **Several trigger categories are hidden behind `hidden: true`.** This flag was introduced in the M2 cascade commit to limit the initial rollout to Coaching/Enrollment/Course. The rest (Classroom Visit, RP Session, Assessment, Schedule) are fully wired in the backend but invisible in the cascade.

Mateo's objection to continuing the `hidden` approach: **hidden stuff becomes stale**. Without visible state, nobody knows if a category is "wired and waiting" or "half-built and abandoned."

Chris's 19 workflows also revealed **genuinely missing backend wiring**:
- Classroom Visit overdue cron (row 11)
- Session-datetime-anchored reminders (rows 17, 18, 19)
- Post-completion compound triggers (rows 20, 21)

## 2. Decision: wiring_status enum (not a hidden flag)

Replace the binary `hidden: true/false` with an explicit three-state enum:

```php
'wiring_status' => 'wired' | 'stub' | 'deprecated'
```

| Value | Meaning | UI behavior |
|---|---|---|
| `wired` | Hook/cron + recipient resolver + anchor + condition fields all functional end-to-end. | Selectable in cascade normally. |
| `stub` | Intentionally declared in the registry because we know it's coming. Hook/cron does NOT fire yet. | Appears in cascade as **disabled** option with tooltip: "Coming: [date or plan link]". Cannot be saved. |
| `deprecated` | Legacy entry kept only so existing workflows can reverse-map to readable category/event labels in edit mode. | **Never** appears in the cascade for new workflows. Edit-mode only. |

### Why this is better than `hidden`

- **Stubs are visible + dated + linked.** No silent rot — if a stub sits past its ETA, it's visible in the UI itself.
- **Deprecated is explicit.** Legacy reverse-map lookup separated from "usable" state.
- **No foot-gun.** Stub entries can't accidentally be saved because they're disabled server-side too.
- **Single source of truth.** Registry entries always declare their state; STATUS.md and plan docs don't need to carry duplicate schema.

### Alternative rejected: delete unwired entries

Rejected because deletion destroys institutional knowledge (hook name, recipient shape, anchor) and forces re-derivation when wiring catches up. Stubs preserve the schema shape while making incompleteness visible.

## 3. Migration strategy

### 3.1 Registry schema

Each event entry currently looks like:
```php
'booked' => array(
    'label' => 'Session Booked',
    'key'   => 'hl_coaching_session_created',
    'type'  => 'hook',
),
```

After migration:
```php
'booked' => array(
    'label'          => 'Session Booked',
    'key'            => 'hl_coaching_session_created',
    'type'           => 'hook',
    'wiring_status'  => 'wired',
),
```

Category-level `hidden: true/false` is **removed entirely.** Events self-declare their state.

### 3.2 Legacy trigger-key reverse-map

Moves from a hidden-category-at-registry-level hack into a dedicated method:

```php
public static function get_legacy_trigger_aliases() {
    // Maps deprecated trigger_key values to { category, event } for edit-mode
    // reverse display. Never rendered in new-workflow cascade.
    return array(
        'user_register' => array(
            'category' => 'legacy',
            'event'    => 'account_activated_legacy',
            'label'    => 'Account Activated (deprecated)',
        ),
        // etc.
    );
}
```

Edit mode: if `$workflow->trigger_key` is in `get_legacy_trigger_aliases()`, render a yellow notice + read-only field ("This workflow uses a deprecated trigger. Re-select a current trigger to continue editing."). The existing reverse-mapping fallback UI already does this pattern.

### 3.3 The six stubs to add

| Spreadsheet row | Stub event | Key | Linked issue |
|---|---|---|---|
| 11 | `classroom_visit.overdue` | `cron:component_overdue` + `componentType: classroom_visit` | Plan §5.1 |
| 17 | `coaching.reminder_5d_before_session` | `cron:session_upcoming` + offset 5 days | Plan §5.2 |
| 18 | `coaching.reminder_24h_before_session` | `cron:session_upcoming` + offset 24 hours | Plan §5.2 |
| 19 | `coaching.reminder_1h_before_session` | `cron:session_upcoming` + offset 1 hour | Plan §5.2 |
| 20 | `coaching.action_plan_incomplete_24h_after` | `cron:post_session_form_pending` + form type `action_plan` | Plan §5.3 |
| 21 | `coaching.notes_incomplete_24h_after` | `cron:post_session_form_pending` + form type `coaching_notes` | Plan §5.3 |

Each stub entry includes a `stub_reason` field pointing to the plan section that describes the missing wiring.

## 4. Component Type picker decision tree

Audit first:
```sql
SELECT workflow_id, name, trigger_key, component_type_filter
FROM wp_hl_email_workflow
WHERE status = 'active'
  AND component_type_filter IS NOT NULL
  AND component_type_filter != ''
```

Then cross-check each row against its event's declared `componentType` in the registry.

**If zero workflows have a non-default filter** → remove the visible field entirely. JS continues to auto-set the hidden input from the event. Column stays for backward compat.

**If any workflows have a non-default filter** → move the field behind an `<details>` "Advanced" disclosure. Pre-filled from Event; editable only if expanded. A JS warning fires when a user changes it: "This overrides the category-event mapping. Only proceed if you know what you're doing."

**Never do:** hide a column entirely with no UI. That's the exact silent-drift problem we're fixing — "hidden-but-present columns with no UI cause the exact silent-drift problem Mateo is trying to avoid" (skeptic critique).

## 5. Phase 2 — Backend wiring (SHIPPED 2026-04-21, v1.2.9 SHA `7b2ea94`)

> **See the progress log for the full change-log entry:** `2026-04-20-email-registry-cleanup-progress.md` (`2026-04-21 — Phase 2 backend wiring shipped to prod`).

**Actual effort: ~0.5 dev-days. Original estimate: 4-6 dev-days.** The handoff spec under-inspected the existing code: §5.1 and §5.2 were already mostly implemented (the stub flags were hiding working handlers), and §5.3 could reuse existing keys once three latent SQL bugs were fixed. Each sub-section below retains its original spec language with an annotated **ACTUAL** block describing what was actually needed.

### 5.1 `cron:component_overdue` for `classroom_visit` (~1 dev-day)
Extend existing `cron:component_overdue` to handle `classroom_visit` componentType. Currently only `learndash_course` is supported. New SQL query against `hl_component_state` for components where `available_to < now - 1 day` AND state != `complete`.

**ACTUAL (2026-04-21):** The generic handler already supported arbitrary `component_type_filter` and `component_completion_subquery()` already had a `classroom_visit` case. Only missing piece was per-component scoping for the overdue path so CV #3 still fires when CV #1 is done (ELCPB-Y2 multi-visit mentor pathways). Added a `$trigger_type` parameter to the completion subquery helper; overdue path now matches via `c.external_ref LIKE CONCAT('%"visit_number":', cv.visit_number, '%')` mirroring the pattern in `HL_Classroom_Visit_Service::update_component_state()`. Reminder (upcoming) path retained cycle-scoped suppression. Commits `2b7c692` + `7ca8760`.

### 5.2 Session-datetime-anchored reminders (~2 dev-days)
New cron: `cron:session_upcoming`. Anchors on `hl_coaching_session.session_datetime` (actual booked time), not `display_window_start` as current cron does. Offset configurable (5 days / 24h / 1h). Must handle same-session multiple triggers (one workflow for 5d, another for 24h, another for 1h) without dedup collisions.

**ACTUAL (2026-04-21):** `cron:session_upcoming` was already fully implemented in Rev 39 generic triggers — configurable `trigger_offset_minutes`, scaled fuzz (5–30 min), `session_status='scheduled'` filter, hourly cron loop. Per-workflow dedup token already included `workflow_id`, so 3 different-offset workflows for the same session cannot collide — the handoff spec's claim that dedup needed to include offset was incorrect. Pure flag-flip, no handler change. 1h offset has an effective ~30–90 min send window (hourly WP-Cron + 6 min fuzz); documented inline in the registry event. Commit `fa1d38a`.

### 5.3 Post-completion compound trigger (~1.5 dev-days)
New cron: `cron:post_session_form_pending`. Fires 24h after a coaching session is marked `attended` IF the specified form (`action_plan` or `coaching_notes`) has not been submitted. Requires: (a) form-submission timestamp lookup helper, (b) 24h-after-status-change cron query, (c) dedup-by-session so we don't re-send after admin bypasses.

**ACTUAL (2026-04-21):** Rather than build a new key, re-pointed the 2 stubs at the existing `cron:action_plan_24h` and `cron:session_notes_24h` handlers (which pre-existed from Email v2 Phase 2 and covered the spec's exact semantics). This surfaced **three latent SQL bugs in those handlers** — references to `sub.submission_type`, `cs.mentor_user_id`, and `cs.enrollment_id`, all of which do not exist in the schema. The handlers had never fired because no active workflow used their keys. Rewrote both handlers to distinguish action-plan vs coach-notes via `role_in_session` (`supervisee` = mentor-authored; `supervisor` = coach-authored) per the canonical `HL_Coaching_Service::submit_form()` pattern. Mentor path joins `hl_enrollment` on `mentor_enrollment_id`. Coach path returns `enrollment_id = NULL` since coaches are staff users, not enrollment-scoped — verified end-to-end through the cron pipeline. Also fixed timezone bug (session_datetime is site-TZ; handlers now use `current_time('mysql')` not `gmdate()`). Added 30-day lookback clamp. Removed `cron:action_plan_24h` + `cron:session_notes_24h` from `get_legacy_trigger_aliases()` (they were misclassified as legacy during the Phase 1 refactor). Commits `2b7c692` + `621b1c8`.

### 5.4 Testing + QA for the above (~1 dev-day)
CLI test assertions, manual send-test, prod rollout with feature flag.

**ACTUAL (2026-04-21):** Two CLI test harnesses shipped:
- `bin/test-email-phase2-stubs.php` — 29 assertions. Handler SQL via reflection + NULL-enrollment coach path end-to-end through `run_daily_checks()`. DB fixtures under `[Phase2Test]` prefix with finally-block cleanup.
- `bin/test-email-phase2-registry-ui.php` — 40 assertions. Admin-UI-equivalent registry + save-payload validation. Substitute for Playwright browser check when MCP Chrome profile is locked.

No feature flag used. Shipped directly on `feature/workflow-ux-m1` with tests green on test before each stub flip; all 69 assertions green on prod post-deploy.

**Total Phase 2 actual:** ~0.5 dev-days. Separate PR would have been overkill; shipped as 9 commits on `feature/workflow-ux-m1`.

## 6. Scope

### In scope for this plan
1. `wiring_status` enum migration (registry only — no DB schema change).
2. `$legacy_trigger_aliases` extraction.
3. Promote 3 wired-but-hidden categories to `wired`.
4. Delete `schedule.account_activated`.
5. Add 6 stubs with plan links.
6. Admin UI: disabled-option rendering for stubs.
7. Component Type picker decision (from audit).
8. Deploy to test → verify → deploy to prod.

### Out of scope (separate PR)
- Building the 6 missing backend triggers (§5).
- Any DB schema changes.
- Any changes to `HL_Email_Automation_Service` beyond trivial registry reads.

## 7. Acceptance criteria

- All 21 existing registry entries have a `wiring_status` field.
- `hidden: true/false` appears nowhere in the registry or consumer code.
- Editing any existing workflow reverse-maps correctly (no "missing trigger" errors).
- Stubs render as disabled options with hover tooltip showing the plan link.
- Saving a stub via hand-crafted POST is rejected server-side.
- `component_type_filter` field either (a) is not present in the form, or (b) lives behind an Advanced disclosure — verified by audit.
- Smoke test 0 new failures.
- Chris's 13 currently-wired workflows buildable end-to-end via the cascade (rows 4, 5, 6, 7, 9, 10, 12, 13, 14, 15, 16, 22, 24 — row 5 pending condition-field confirmation).

## 8. Rollout

1. Implement in `feature/email-registry-cleanup` branch.
2. Commit in small logical chunks, pushed after each.
3. Deploy to test, verify via `wp eval` + browser check on one wired + one stub entry.
4. Deploy to prod.
5. Update STATUS.md + README.md.
6. Launch Playwright agent on Chris's 13 buildable workflows.

## 9. Open questions (logged for later — not blockers)

- **Row 5 (Pathway Enrollment Control Group, #1B):** Needs a condition field that identifies control-group cycles. Does `partnership_type` or `cycle_type = control` exist as a condition field? If not, this workflow can't be built precisely. Log for Chris/Mateo decision.
- **Row 8 (Pre-Assessment Documentation):** Is this truly "on pathway assignment, remind about assessment"? Or does it need a separate assessment-window trigger? Log for Chris/Mateo decision.
- **Rows 2, 3 (User Registration in spreadsheet):** Per Mateo's correction, users don't receive emails on registration — these rows are out of scope for Chris. Confirm once more with him so the spreadsheet can be annotated.
