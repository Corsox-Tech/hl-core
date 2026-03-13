# Grand Rename V2 — Execution Log

## Renames
- hl_track → hl_partnership
- hl_activity → hl_component
- hl_phase → hl_cycle
- Delete HL_Label_Remap
- Remove JetFormBuilder
- Hide Cohort from UI

## Log

| Task | Description | Timestamp | Files Changed | Notes |
|------|-------------|-----------|---------------|-------|
| A1 | DB migration track→partnership | 2026-03-13 | 1 file | Tables: hl_partnership, hl_partnership_school, hl_child_partnership_snapshot + ~15 FK columns |
| A2 | Domain models + repos track→partnership | 2026-03-13 | 12 files | HL_Track→HL_Partnership, 5 models, 4 repos, hl-core.php require paths |
| A3 | Services track→partnership | 2026-03-13 | 19 files | HL_Track_Service→HL_Partnership_Service + 16 other services, 629 lines changed |
| A4 | Admin pages track→partnership | 2026-03-13 | 17 files | HL_Admin_Tracks→HL_Admin_Partnerships, deleted class-hl-admin-tracks.php, cleaned 12 admin files + import wizard JS; remaining: track_roles (CSV format) |
| A5 | Frontend shortcodes + BuddyBoss track→partnership | 2026-03-13 | 14 files | 4 frontend classes renamed, shortcodes updated, CSS selectors, BuddyBoss menu items, hl-core.php require paths; 384 lines changed |
| A6 | CLI & REST API track→partnership | 2026-03-13 | 8 files | 4 seeders, provision-lutheran, create-pages, REST API, hl-core.php hook; ~650 lines changed |
| A-FIX | Rename missed hl_track_email_log table | 2026-03-13 | 2 files | Missed in A1 |
| A-CLEANUP | Frontend + integrations stale track refs | 2026-03-13 | 23 files | Bulk rename track→partnership in all frontend page classes, shortcodes, instrument renderer, and LearnDash integration; 252+ lines changed |
| A-CLEANUP2 | CLI scripts stale track refs | 2026-03-13 | 5 files | provision-test-teachers, provision-test-users, send-test-emails, send-maria-email, send-test-emails-v2 |
