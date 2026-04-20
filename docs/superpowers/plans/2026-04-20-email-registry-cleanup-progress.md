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

### 2026-04-20 — Audit: component_type_filter usage

**Prod:** `SELECT COUNT(*) FROM wp_hl_email_workflow` → **0 rows.** Clean slate.
**Test:** 4 rows exist, all with `component_type_filter = NULL`. Two are in `deleted` status and reference the legacy `user_register` trigger_key — confirms the `$legacy_trigger_aliases` design is needed for clean reverse-mapping even though nothing production-facing uses it.

**Decision:** Remove the `component_type_filter` visible form field entirely. JS auto-sets the hidden input from the chosen Event's `componentType`. No Advanced disclosure needed since there is no legitimate use case for a non-default override in the existing data.

**Column stays in `hl_email_workflow` table** — backend still uses it. The change is purely UI: the field becomes non-editable, set only by cascade JS.

---

### 2026-04-20 — Plan doc + progress log

Commit `c5f6e40`. Plan at `2026-04-20-email-registry-cleanup.md`. Progress log (this file) created alongside.

