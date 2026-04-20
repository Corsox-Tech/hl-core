# Email Trigger Registry Cleanup — Progress Log

**Branch:** `feature/email-registry-cleanup`
**Started:** 2026-04-20
**Owner:** Claude (with Mateo supervising)

This file is the single source of truth for session progress. Every meaningful change is logged here with a commit SHA and what it did. Optimised for reading after a gap (e.g., flight WiFi).

## Current status
- [x] Plan doc written (commit `c5f6e40`)
- [x] Audit: non-default `component_type_filter` usage on prod → **zero workflows. Safe to remove field.**
- [ ] Registry schema: replace `hidden: bool` with `wiring_status: string`
- [ ] Promote wired-but-hidden categories (Classroom Visit, RP Session, Schedule/Low Engagement)
- [ ] Delete `schedule.account_activated` entry
- [ ] Add stub entries for 6 missing triggers (rows 11, 17-19, 20-21 of Chris's spreadsheet)
- [ ] Move legacy trigger-key reverse-mapping to separate `$legacy_trigger_aliases`
- [ ] Admin UI: render `wiring_status: stub` entries as disabled with tooltip
- [ ] Remove `component_type_filter` visible field OR move behind Advanced disclosure (decide from audit)
- [ ] Deploy to test + verify
- [ ] Deploy to prod + verify
- [ ] Launch Playwright agent on the 13 wired workflows for Chris

## Change log (newest first)

### 2026-04-20 — BLOCKER: branching strategy decision needed

Discovered while preparing to edit `get_trigger_categories()`: that function does not exist on the current branch (`feature/email-registry-cleanup`, cut from `feature/ticket-18-continuing-pathways`, which was cut from `main`).

`get_trigger_categories()` and the M2 cascade picker were introduced on `feature/workflow-ux-m1` in commits `3c53332` (Apr 16) and `5255e06` (Apr 16). That branch has **30+ commits ahead of main** covering M2 cascade, Course Survey Builder, Ticket QA workflow, Ticket D-1 email notification, several nav/sidebar tickets, etc. None of it has merged.

Options for Mateo to decide:

1. **Rebase cleanup onto `feature/workflow-ux-m1`** — fastest, but this cleanup can't merge until workflow-ux-m1 merges. If workflow-ux-m1 doesn't merge, this doesn't either.
2. **Wait for workflow-ux-m1 to merge to main**, then rebase cleanup onto main. Clean but blocked on external action.
3. **Cherry-pick just the M2 cascade commits** onto current cleanup branch — isolates dependency, but weird history.
4. **Merge workflow-ux-m1 → main first** as a separate action, then cut cleanup from main.

Not my call to make. Logging and waiting for user direction.

---

### 2026-04-20 — Audit: component_type_filter usage

**Prod:** `SELECT COUNT(*) FROM wp_hl_email_workflow` → **0 rows.** Clean slate.
**Test:** 4 rows exist, all with `component_type_filter = NULL`. Two are in `deleted` status and reference the legacy `user_register` trigger_key — confirms the `$legacy_trigger_aliases` design is needed for clean reverse-mapping even though nothing production-facing uses it.

**Decision:** Remove the `component_type_filter` visible form field entirely. JS auto-sets the hidden input from the chosen Event's `componentType`. No Advanced disclosure needed since there is no legitimate use case for a non-default override in the existing data.

**Column stays in `hl_email_workflow` table** — backend still uses it. The change is purely UI: the field becomes non-editable, set only by cascade JS.

---

### 2026-04-20 — Plan doc + progress log

Commit `c5f6e40`. Plan at `2026-04-20-email-registry-cleanup.md`. Progress log (this file) created alongside.

