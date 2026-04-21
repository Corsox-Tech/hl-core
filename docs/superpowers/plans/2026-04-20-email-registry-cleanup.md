# Email Trigger Registry Cleanup — Plan

**Status:** Draft — ready to execute
**Branch:** `feature/email-registry-cleanup`
**Date:** 2026-04-20
**Author:** Mateo + Claude, synthesized from two-agent debate
**Progress log:** `2026-04-20-email-registry-cleanup-progress.md`
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

## 5. Deferred backend wiring (separate branch, separate PR)

Not part of this cleanup. Each stub flips to `wired` when its wiring ships.

### 5.1 `cron:component_overdue` for `classroom_visit` (~1 dev-day)
Extend existing `cron:component_overdue` to handle `classroom_visit` componentType. Currently only `learndash_course` is supported. New SQL query against `hl_component_state` for components where `available_to < now - 1 day` AND state != `complete`.

### 5.2 Session-datetime-anchored reminders (~2 dev-days)
New cron: `cron:session_upcoming`. Anchors on `hl_coaching_session.session_datetime` (actual booked time), not `display_window_start` as current cron does. Offset configurable (5 days / 24h / 1h). Must handle same-session multiple triggers (one workflow for 5d, another for 24h, another for 1h) without dedup collisions.

### 5.3 Post-completion compound trigger (~1.5 dev-days)
New cron: `cron:post_session_form_pending`. Fires 24h after a coaching session is marked `attended` IF the specified form (`action_plan` or `coaching_notes`) has not been submitted. Requires: (a) form-submission timestamp lookup helper, (b) 24h-after-status-change cron query, (c) dedup-by-session so we don't re-send after admin bypasses.

### 5.4 Testing + QA for the above (~1 dev-day)
CLI test assertions, manual send-test, prod rollout with feature flag.

**Total Phase 2 estimate:** 4-6 dev-days, separate PR.

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
