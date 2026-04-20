# Email Trigger Registry Cleanup — Progress Log

**Branch:** `feature/email-registry-cleanup`
**Started:** 2026-04-20
**Owner:** Claude (with Mateo supervising)

This file is the single source of truth for session progress. Every meaningful change is logged here with a commit SHA and what it did. Optimised for reading after a gap (e.g., flight WiFi).

## Current status
- [ ] Plan doc written
- [ ] Audit: non-default `component_type_filter` usage on prod
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

_Entries added below as work progresses. Each entry = one commit._
