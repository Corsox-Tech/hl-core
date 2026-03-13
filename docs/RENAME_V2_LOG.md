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
| B1 | DB migration activity→component | 2026-03-13 | 1 file | 6 table renames + FK columns |
| B2 | Domain models & repos activity→component | 2026-03-13 | 3 files | HL_Activity→HL_Component, HL_Activity_Repository→HL_Component_Repository, hl-core.php require paths |
| B3 | Services activity→component | 2026-03-13 | 6 files | HL_Pathway_Service, HL_Rules_Engine_Service, HL_Assessment_Service, HL_Reporting_Service, HL_Coaching_Service, HL_Observation_Service; all SQL, method names, params renamed |
| B4 | Admin pages activity→component | 2026-03-13 | 4 files | class-hl-admin-pathways.php (~115 renames: methods, vars, SQL tables/columns, nonces, URL params, form fields, labels, JS), class-hl-admin-partnerships.php (~20 renames: case values, vars, method calls, SQL, labels), class-hl-admin-reporting.php (~13 renames: method, vars, labels), class-hl-admin-coaching.php (1 comment); verified 0 activity refs remain |
| B5 | Frontend + integrations activity→component | 2026-03-13 | 21 files | File rename: class-hl-frontend-activity-page.php→class-hl-frontend-component-page.php; class renamed HL_Frontend_Activity_Page→HL_Frontend_Component_Page; shortcode [hl_activity_page]→[hl_component_page]; 14 frontend files (my-progress, my-programs, program-page, component-page, child-assessment, teacher-assessment, dashboard, team-progress, my-coaching, my-partnership, partnership-workspace, team-page, observations, pathways-listing), 3 integrations (LearnDash, JFB, BuddyBoss), shortcodes, hl-core.php, CLI create-pages, CSS (30+ selectors); verified 0 activity refs remain in frontend/integrations/assets |
