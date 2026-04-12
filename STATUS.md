# STATUS.md — HL Core Build Status

**Phases 1-32 + 35 complete. Deployed to production (March 2026).** 40 shortcode pages (+ 4 backward-compatible aliases), 22 admin controllers, 54 DB tables, 33 services, 19 CLI commands. Lutheran control group provisioned (39 enrollments, 286 children, 11 schools). **Email system deployed to test (April 2026).**

---

## Build Queue (Ordered — work top to bottom)

Pick up from the first unchecked `[ ]` item each session.

### ELCPB Data & Operations (Active — March 2026)
- [x] **Fix admin menu duplicate** — Rename auto-generated "HL Core" submenu to "Cycles", remove duplicate entry.
- [x] **Link ELCPB Partnership ↔ Cycle** — Set `partnership_id` on Year 1 Cycle to point to ELCPB Partnership.
- [~] **Import ELCPB Year 1 remaining data** — TSA complete (52 pre, 40 post). Child assessments imported from WPForms: 261 children, 27 teaching assignments, 45 instances, 494 childrows. 4 Life Span teachers (311/315/317/321) have no enrollment — child assessment entries skipped. Life Span VPK classroom has no form.
- [x] **Create ELCPB Year 2 Cycle + Pathways** — Cycle `ELCPB-Y2-2026` (id=5) linked to Partnership 4, 2026-03-30 to 2026-09-12. 8 pathways: Teacher Phase 1 (15 cmp), Teacher Phase 2 (14), Mentor Phase 1 (9), Mentor Phase 2 (16), Mentor Transition (16), Mentor Completion (2), Streamlined Phase 1 (9), Streamlined Phase 2 (8). CLI: `wp hl-core setup-elcpb-y2`.
- [x] **ECSELent Adventures setup** — Partnership EA-2025, Cycle EA-TRAINING-2025, 2 pathways (Preschool/Pre-K + K-2), 3 shared training course components, enrollment from LD group, pathway assignment by materials group, completion import. CLI: `wp hl-core setup-ea`.
- [x] **Short courses migration** — 3 course-type Cycles (SC-EEW, SC-RP, SC-MMST), each with 1 pathway + 1 component, enrollment discovery from LD activity, completion import. CLI: `wp hl-core setup-short-courses`.
- [x] **Frontend: Resource card for syllabus_url** — Enhanced rendering on Program Page: styled card with accent border instead of plain link. Generic for any pathway with syllabus_url.

### Cross-Pathway Events, Forms & Coaching (Active — March 2026)
- [x] **DB: 5 new tables** — `hl_rp_session`, `hl_rp_session_submission`, `hl_classroom_visit`, `hl_classroom_visit_submission`, `hl_coaching_session_submission`. ENUM migration adds 3 component types.
- [x] **3 new services** — `HL_RP_Session_Service`, `HL_Classroom_Visit_Service`, `HL_Session_Prep_Service` + coaching service enhancements for form submissions.
- [x] **5 new frontend renderers** — RP Notes, Action Plan, Self-Reflection, Classroom Visit, RP Session page controller with role-based views.
- [x] **Component Page dispatchers** — 3 new component type branches (`self_reflection`, `reflective_practice_session`, `classroom_visit`).
- [x] **Coaching Hub enhancements** — Coaches tab (admin), coaches grid + calendar view (frontend), calendar widget on My Coaching.
- [x] **Cycle Editor subtabs** — RP Sessions + Classroom Visits subtabs in Coaching tab.
- [x] **6 instruments seeded** — coaching_rp_notes, mentoring_rp_notes, coaching_action_plan, mentoring_action_plan, classroom_visit_form, self_reflection_form.
- [x] **ELCPB Y2 CLI rebuild** — `setup-elcpb-y2-v2` with correct 8 pathways, new component types, prerequisites, demo coach (Lauren Orf).
- [x] **Integration testing** — Deployed to test server 2026-03-23. DB tables, ENUM types, 8 pathways, 6 instruments, demo coach all verified. Fixed ENUM schema definition in get_schema() to include 3 new types (prevents dbDelta regression). Manual browser testing pending (Tasks 5.3-5.5).

### Coach Frontend Pages (Active — March 2026)
- [x] **Coach Dashboard Service** — Data queries for stats, mentor roster, availability CRUD. `HL_Coach_Dashboard_Service`.
- [x] **DB: hl_coach_availability table** — Weekly recurring schedule blocks for coaches. Schema revision 22→23.
- [x] **Coach Dashboard [hl_coach_dashboard]** — Welcome hero, stats cards, quick links. Coach role only.
- [x] **My Mentors [hl_coach_mentors]** — Card grid of assigned mentors with search/filter.
- [x] **Mentor Detail [hl_coach_mentor_detail]** — 4-tab mentor profile (sessions, team, RP, reports + CSV).
- [x] **Coach Reports [hl_coach_reports]** — Aggregated completion table with cycle/school filters, CSV export.
- [x] **Coach Availability [hl_coach_availability]** — Weekly schedule grid with 30-min toggle blocks.
- [x] **Wiring** — 5 shortcodes registered, 5 pages in create-pages CLI, 3 BuddyBoss sidebar items for Coach role.

### Course Catalog — Multilingual Course Mapping (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-04-course-catalog-design.md` | **Plan:** `docs/superpowers/plans/2026-04-04-course-catalog.md`
- [x] **Domain model + Repository** — `HL_Course_Catalog` (10 properties, `resolve_course_id()` with language fallback, `resolve_ld_course_id()` static helper with per-request cache). `HL_Course_Catalog_Repository` (CRUD, reverse lookup by LD course ID, duplicate detection, archive, `get_active_for_dropdown()`). Defensive: null guards on constructors, catalog_code format validation, 0/empty coercion on language columns.
- [x] **Installer — table, migrations, seed data (rev 30)** — `hl_course_catalog` table (10 columns, 6 indexes incl. UNIQUE on each `ld_course_*`). `catalog_id` column on `hl_component` + `language_preference` on `hl_enrollment` (DDL + ALTER TABLE migration). 25 seed entries (13 mastery + 12 streamlined). Backfill: components matched by `external_ref.course_id`, enrollments by Spanish LD group membership.
- [x] **Routing service refactor** — `$stages` uses `catalog_codes` (string keys) instead of hardcoded LD course IDs. `load_catalog_cache()` (lazy, non-caching on table-absent). `is_catalog_entry_completed()` checks any language variant. `is_catalog_ready()` health check. `get_completed_stages()` catalog-aware with empty-catalog guard.
- [x] **LearnDash integration — catalog-aware completion** — `on_course_completed()` uses catalog-first path (find by LD course ID → query components by `catalog_id`), fallback to `external_ref` gated behind `hl_catalog_migration_complete` option. Already-complete components skipped (spec: "nothing changes"). Multi-cycle enrollment support.
- [x] **Admin Course Catalog page** — `HL_Admin_Course_Catalog` singleton under Housman LMS menu. List view with status filter pills, archive with component-count confirm. Add/edit form with AJAX LD course search (debounced, title-only LIKE), clear buttons for optional languages, auto-uppercase catalog code. Nonce + capability checks on all paths. Audit logging on create/update/archive.
- [x] **Import module — language column** — Optional `language` CSV column (en/es/pt, default en). Warning on unrecognized values. Re-import diff-check detects language changes. CREATE + UPDATE paths include `language_preference`.
- [x] **Enrollment edit form — language preference** — Dropdown (en/es/pt) after status field. Strict enum validation on save.
- [x] **Pathway admin — catalog dropdown** — Component form: catalog entry select with language badges (`[EN] [ES]`). Auto-fills title + `external_ref` from catalog for backward compat.
- [x] **Frontend language resolution** — `HL_Course_Catalog::resolve_ld_course_id()` static helper with per-request cache. 7 call sites across 4 files (program-page, my-progress, my-programs, component-page). Spanish users see Spanish course links/progress.
- [x] **Reporting — catalog titles** — `get_component_states()` includes `catalog_id`. Report detail view resolves canonical English title from catalog (batch-loaded, no N+1).
- [x] **Deployed to test** — Schema rev 30 verified. 25 catalog entries, 103 components backfilled, 4 Spanish enrollments, `catalog_id` index confirmed.

### Phase 33: Individual Enrollments (B2E Master Reference)
- [ ] **33.1 — DB: `hl_individual_enrollment` table** — Create table with user_id, course_id, enrolled_at, expires_at, status, enrolled_by, notes.
- [ ] **33.2 — Individual Enrollment Service** — CRUD, expiration checks, LearnDash progress queries.
- [ ] **33.3 — Admin: Individual Enrollments pages** — Course List page + Course Detail page under HL Core menu.
- [ ] **33.4 — Frontend: My Courses on Dashboard** — Add "My Courses" section to `[hl_dashboard]` for individual enrollments.
- [ ] **33.5 — Expiration enforcement** — Check on course access, auto-mark expired, optional LearnDash unenroll.

### Phase 34: Program Progress Matrix Report (B2E Master Reference)
- [ ] **34.1 — Report query** — Query all LearnDash Course activities across all Cycles, map completion per participant.
- [ ] **34.2 — Admin report view** — Course-by-course grid with Cycle/School/Team/Role filters.
- [ ] **34.3 — CSV export** — Export the matrix as CSV.

### Coaching Session Scheduling Integration (Active — March 2026)
- [x] **DB: 6 new columns on hl_coaching_session** — component_id, zoom_meeting_id, outlook_event_id, booked_by_user_id, mentor_timezone, coach_timezone. Schema revision 23→24.
- [x] **Admin Settings: Scheduling & Integrations tab** — Scheduling rules (duration, lead times, cancel window), Microsoft 365 credentials (AES-256-CBC encrypted), Zoom credentials, Test Connection buttons.
- [x] **Microsoft Graph API client** — `HL_Microsoft_Graph`: client credentials OAuth2, calendar CRUD, token caching, coach email resolution.
- [x] **Zoom S2S OAuth client** — `HL_Zoom_Integration`: S2S OAuth, meeting CRUD, token caching, coach email resolution.
- [x] **Scheduling Email Service** — `HL_Scheduling_Email_Service`: branded HTML emails for booked/rescheduled/cancelled + API failure fallbacks.
- [x] **Scheduling Orchestration Service** — `HL_Scheduling_Service`: slot calculation (availability - Outlook conflicts - existing sessions), book/reschedule/cancel with Zoom + Outlook + email, 4 AJAX endpoints.
- [x] **Coaching Service updates** — `create_session()` accepts scheduling columns + uniqueness check, `reschedule_session()` forwards component/timezone data.
- [x] **Frontend: Component Page scheduling UI** — Date picker, AJAX slot loading, booking, two-state view (schedule vs details+action plan), drip rule locking, reschedule/cancel flows.
- [x] **Frontend: My Coaching rewrite** — Component-based sessions hub with status badges, drip rule locking, complete_by dates, multi-cycle grouping.
- [x] **Coach Mentor Detail: Schedule Next Session** — Button links to next unscheduled coaching component for streamlined coach-initiated scheduling.
- [x] **Deployed to test** — Schema revision 24 verified, all 6 columns present. Manual browser testing pending (API credentials needed for end-to-end Zoom/Outlook tests).
- [x] **Admin: Email Templates tab** — `HL_Admin_Email_Templates` in Settings hub. Edit subjects + body copy for 6 coaching session emails (Booked/Rescheduled/Cancelled x Mentor/Coach). Merge tags, Send Test, Reset to Default. `HL_Scheduling_Email_Service` updated to pull from `hl_email_templates` wp_option with defaults fallback.
- [x] **Fix: Coaching session component buttons** — Program Page coaching cards now filter by `component_id` (fixes all sessions showing "Scheduled" when only one is). Button changed from "Join Meeting" to "View Session" linking to Component Page. Completed sessions get "View Session" link.
- [x] **Fix: Action Plan domain dropdown clipping** — `.hlap-select` CSS: added `line-height:1.4`, `height:auto`, reduced padding.

### HL User Profile Page (Active — March 2026)
> **Plan:** `docs/superpowers/plans/2026-03-29-hl-user-profile-plan.md` — read this FIRST for full context, architecture, access control rules, and data sources.
- [x] **Phase 1 — Page foundation + Overview tab + BB redirect** — `HL_Frontend_User_Profile` class, `[hl_user_profile]` shortcode, Overview tab (hero + 3-4 info cards: Contact, Program, Team & Coaching, Classrooms), role-based access control, cycle selector for multi-enrollment users, BB profile redirect to HL profile, `User Profile` page in CLI create-pages. CSS in frontend.css (`.hlup-*`).
- [x] **Phase 2 — Progress tab + entry point links** — `render_progress_tab()` with enrollment summary bar (ring chart), pathway cards with component-by-component completion (status badges, progress bars, LearnDash live %), type labels. User names now clickable in My School Staff + Reports tabs (link to `/user-profile/?user_id=X`).
- [x] **Phase 3 — Coaching tab** — `render_coaching_tab()` with upcoming/past session cards (status badges, date/time, coach name, Join link), action plan cards (domain, skills), "Schedule Next Session" button for coaches/admins. Coach Mentor Detail kept as-is (Option A per plan).
- [x] **Phase 4 — Assessments tab** — `render_assessments_tab()` with summary stat cards (total/submitted counts), TSA instance cards (PRE/POST phase badges, status, date) with collapsible inline read-only responses via `HL_Teacher_Assessment_Renderer`, CA instance cards (classroom, age band, children count, status). Response data gated behind `manage_hl_core`.
- [x] **Phase 5 — RP & Observations tab** — `render_rp_tab()` with three sections: RP sessions (session number, date, mentor/teacher partner, status), classroom visits (visit number, date, observer, status), self-reflections (date, status). Role-aware queries: mentors see their RP sessions, teachers see visits + reflections.
- [x] **Phase 6 — Manage tab (admin only)** — `render_manage_tab()` + `handle_post_actions()` via template_redirect. Profile edit (name, email), enrollment settings (status, school with auto district_id, roles checkboxes), pathway assign/unassign with dropdown, password reset email, danger zone (deactivate enrollment with confirm). All POST actions nonce-verified, audit-logged, POST-redirect-GET pattern.
- [x] **Phase 7 — Entry point wiring + polish** — Profile links wired: Coach Mentors grid ("View Profile" below cards), Team Page (Members + Report tabs), Admin Enrollments ("Profile" button), BuddyBoss sidebar ("My Profile" for enrolled users + staff). Breadcrumb nav on profile page (Dashboard > My School > User Name). My School Staff + Reports tabs already linked in Phase 2.
- [x] **Phase 8 — Documentation updates** — Updated README.md, STATUS.md, roles/permissions doc (§10 User Profile visibility), frontend pages doc (§2.4 rewritten, BB references updated), architecture skill (file tree, page inventory, sidebar nav), coach plan (Mentor Detail note), plugin architecture doc (§3.4 updated). All BB profile references updated to HL User Profile.

### Component Eligibility Rules (Active — March 2026)
> **Plan:** `docs/superpowers/plans/2026-03-31-component-eligibility-plan.md`
- [x] **Schema: 2 new columns on hl_component** — `requires_classroom` (tinyint, default 0) and `eligible_roles` (text, JSON array or NULL). Schema revision 24→25.
- [x] **Domain + Rules Engine** — `HL_Component` properties + `get_eligible_roles_array()`. `HL_Rules_Engine_Service::check_eligibility()` + eligibility gate in `compute_availability()` returns `not_applicable`.
- [x] **Admin UI** — Eligibility Rules section in component form: checkbox for requires_classroom, checkboxes for eligible_roles (teacher, mentor, school_leader, district_leader). Save handling with JSON encoding.
- [x] **Completion Calculation** — `compute_rollups()` skips ineligible components from weighted average. `get_cycle_component_detail()` adds `is_eligible` flag.
- [x] **Frontend Rendering** — 6 files updated: Program Page, My Progress, My Programs, User Profile, Team Progress, Dashboard. Ineligible components show "Not Applicable" with grayed styling, excluded from completion %.
- [x] **Deployed to test** — Schema verified, smoke test 0 new failures, 5 eval test scenarios pass.

### Import Module Redesign (Active — April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-01-import-redesign-design.md` | **Plan:** `docs/superpowers/plans/2026-04-01-import-redesign-plan.md`
- [x] **Cycle Editor Import tab** — New "Import" tab in Cycle Editor (hidden for control group + course-type cycles). Replaces Settings → Import.
- [x] **Admin UI rewrite** — Cycle-scoped wizard (no cycle dropdown), helper panel showing valid roles/schools/pathways/teams per cycle. 2 import types: Participants, Children.
- [x] **Service decomposition** — `HL_Import_Service` slimmed from 1,824→684 lines (thin orchestrator). New `HL_Import_Participant_Handler` + `HL_Import_Children_Handler`.
- [x] **Participant handler** — Validates + commits enrollments with auto-creation: classrooms (semicolon-separated), teaching assignments, teams + memberships (mentor/member), coach assignments (`hl_coach_assignment`), pathway assignments (explicit or `sync_role_defaults`). All-or-nothing transaction.
- [x] **Children handler** — Classroom required, school inferred if unambiguous. Fingerprint dedup. Partnership-scoped validation. All-or-nothing transaction.
- [x] **JS wizard rewrite** — New columns (role, classroom, team, pathway, coach). WARNING status selectable. Cycle context from hidden input.
- [x] **Settings deprecation** — Settings → Import shows notice pointing to Cycle Editor. Default tab changed to Scheduling.
- [x] **Code review fixes** — Stale comments, helper panel query consistency, dead CSS cleanup.
- [ ] **Follow-up: multi-row same email** — Duplicate email in CSV marked ERROR; spec allows multiple rows with same email for different classrooms. Needs merge logic.
- [ ] **Follow-up: error report field names** — Error report CSV references old field names from pre-redesign handlers.

### Pathway Routing Engine (Active — April 2026)
> **Spec:** docs/superpowers/specs/2026-04-01-pathway-routing-design.md | **Plan:** docs/superpowers/plans/2026-04-01-pathway-routing-plan.md
- [x] **Routing service** — HL_Pathway_Routing_Service with 5 stages (A=Mentor S1, B=Mentor S2, C=Teacher S1, D=Teacher S2, E=Streamlined S1), 10 routing rules. Stage D differentiates Mentor Phase 2 (C+A) from Mentor Completion (C+A+D). resolve_pathway() checks LearnDash completion at user level.
- [x] **Bug fix: audit logging** — Pathway assignment service audit calls corrected to array-based format.
- [x] **Bug fix: sync_role_defaults** — Routing first + target_roles fallback, ONE pathway per enrollment (was assigning ALL matching).
- [x] **Bug fix: role normalization** — All role storage normalized to lowercase. Import handler + sync comparison fixed.
- [x] **Import integration** — Auto-routes pathway in preview + commit. Clears stale pathways on role-change UPDATE.
- [x] **Admin form AJAX** — Auto-suggests pathway when cycle + role selected. Admin can override.
- [x] **Data fix: district_leader** — Added to Streamlined pathway target_roles in seeders + live DB.
- [x] **routing_type column** — New `routing_type` VARCHAR(50) on `hl_pathway` with UNIQUE(cycle_id, routing_type). Schema revision 26→27. Routing rules now use `routing_type` instead of `pathway_code`. Partnership fallback removed. Admin form dropdown with per-cycle uniqueness. Clone copies routing_type with conflict handling. All B2E seeders updated. Migration CLI: `wp hl-core migrate-routing-types`.

### Admin Enrollment Form Enhancement (April 2026)
- [x] **Pathway + Team fields on enrollment form** — Admin enrollment form (standalone + Cycle Editor inline) now includes Pathway and Team dropdowns, filtered by selected cycle. On save, creates `hl_pathway_assignment` (explicit) and `hl_team_membership` records. Edit mode pre-selects current values. Prevents blank Pathway/Team/School columns on Cycle enrollments table.

### Frontend Design System (Active — April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-01-design-system-spec.md` | **Plan:** `docs/superpowers/plans/2026-04-01-design-system-plan.md`
- [x] **Session 1: Foundation** — Consolidated `:root` design tokens (merged Core + CRM + Calm Professional palettes, added `--hl-interactive-*` canonical indigo, backward compat aliases). Global BB override layer (Inter font, link/heading/form/table resets). Component library CSS (cards, buttons, badges, pills, tabs, hero, meta bar, tables, progress, rating, breadcrumb, section divider, empty state, notices). Sidebar CSS + layout shell (240px grid, topbar, responsive). Sidebar PHP renderer (`render_hl_sidebar()` in BB integration, `body_class` filter). Deployed to test + production.
- [x] **Session 2: Forms & Instruments** — Extracted inline CSS from 7 form/instrument PHP files to frontend.css. action-plan (80 lines), classroom-visit (93 lines), rp-notes (141 lines, 2 blocks), self-reflection (4 style attrs), child-assessment (51 lines), teacher-assessment-renderer (527 lines), instrument-renderer (474 lines). All hardcoded hex replaced with design tokens. TSA + instrument renderers keep tiny inline `<style>` for dynamic admin style overrides only. 8 functional `style=""` remain (JS display toggles, progress bar widths). Deployed to test + production.
- [x] **Session 3: Coach Pages** — Extracted inline CSS from 7 coach PHP files to frontend.css. coach-dashboard (71 lines), coach-mentors (68 lines), coach-mentor-detail (530 lines, 2 blocks), coach-reports (101 lines), coach-availability (75 lines), coaching-hub (15 lines), my-coaching (43 lines). All hardcoded hex replaced with design tokens.
- [x] **Session 4: Program & Navigation Pages** — Extracted inline CSS from 9 program PHP files to frontend.css. schedule-session (168 lines), my-cycle (38 inline attrs), rp-session (6 lines), program-page (3 attrs), component-page (1 attr), my-programs (2 attrs), my-progress (1 attr), cycle-workspace (6 attrs), cycle-dashboard (1 attr). V2 CSS sections reviewed, kept active (all hl-pp-/hl-cw-/hl-dash- classes in use).
- [x] **Session 5: Directory & Profile Pages** — Replaced inline `style=` in 4 PHP files (classroom-page 16 attrs, user-profile 11 attrs, docs 1 icon, learners 1 width). New CSS: `.hl-add-child-panel`, `.hl-form-grid-2col`, `.hl-modal-overlay`/`.hl-modal-box`, `.hl-btn-row`, `.hlup-success-banner`, `.hlup-quick-edit-form`. 12 clean files verified (all `hl-` wrapper classes match BB override selectors). Dynamic progress widths + JS-toggled display:none kept inline.
- [x] **Session 6: Review & Polish** — Removed 230 lines dead CSS (old Section 29 PROGRAM PAGE, old DASHBOARD HOME V1). Fixed 2 remaining non-functional inline styles (component-page back-link, my-cycle section margin). Updated consolidation notes. Grep audit: 0 `<style>` blocks in frontend PHP, 0 non-functional `style=""` attrs. Visual review of all pages across 3 roles (mentor, coach, school leader) — sidebar, Inter font, inputs, tabs, badges, heroes, tables all consistent. Deployed to test + production.

### BuddyBoss Detachment — Phase A (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-02-bb-detachment-design.md` | **Plan:** `docs/superpowers/plans/2026-04-02-bb-detachment-plan.md`
- [x] **HL pages bypass BB template entirely** — `templates/hl-page.php` served via `template_include` filter when page contains `[hl_*]` shortcodes. BB theme files (`header.php`, `footer.php`, etc.) never run → zero CSS conflict. Clean HTML shell: Inter font, dashicons, frontend.css, jQuery, no `wp_head()`/`wp_footer()`.
- [x] **BB integration stripped** — `HL_BuddyBoss_Integration` lost 669 lines: all BB DOM hooks, sidebar renderer, body class filter, buddypanel hooks, JS fallback. `get_menu_items_for_current_user()` made public. WP Admin link added for `manage_options` users.
- [x] **frontend.css purged** — All `body.buddyboss-theme` and `body.hl-has-sidebar` selectors removed. 9-step BB DOM takeover deleted. Clean `.hl-app__content` layout shell (margin-left, topbar offset).
- [x] **UI polish** — Direct dashicons link (bypasses wp_print_styles queue), topbar height 56→48px, breadcrumb vertical-centering fix (margin-bottom override in topbar context), sidebar logo uses WP custom logo if set, user avatar via `get_avatar_url()`, user name dropdown (My Account + Logout links), dropdown JS in frontend.js.
- [x] **Deployed to test + production** — Verified on `academy.housmanlearning.com`.
- [x] **Design system consistency overhaul** — Reusable `.hl-page-hero` + `.hl-meta-bar` components replace 3 custom page headers (Team, Classroom, School). 10 hero gradients standardized to `var(--hl-primary)` → `var(--hl-primary-light)` tokens. Icon opacity normalized to 0.12. Coach Dashboard padding corrected. CSS specificity war eliminated (body inheritance + `:where()` defaults). 4 critical color bugs fixed (hlcd-link-card, meta item strong). `.hl-btn-small` CSS added. 23 lines dead BB selectors removed. Spec + code quality review passed.

### Guided Tours System (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-03-guided-tours-design.md` | **Plan:** `docs/superpowers/plans/2026-04-03-guided-tours-plan.md`
- [x] **Phase 1 — DB schema + Repository + Service** — 3 new tables (`hl_tour`, `hl_tour_step`, `hl_tour_seen`), schema rev 28→29. `HL_Tour_Repository` (CRUD for tours, steps, seen tracking). `HL_Tour_Service` (context resolution, role matching, global styles, 3 AJAX endpoints: mark_seen, get_steps, save_step_order). Registered in `hl-core.php`.
- [x] **Phase 2A — Admin UI** — `HL_Admin_Tours` class with 3 subtabs: Tours List (status filter pills, row actions: Edit/Duplicate/Archive), Tour Editor (tour settings form + sortable step cards with position pills, type toggles, element picker button), Tour Styles (WP Iris color pickers for tooltip bg/title/description/button/progress bar colors, font size inputs, live preview mockup, reset to defaults). Registered in Settings hub. `hl-tour-admin.js` with jQuery UI Sortable, slug auto-gen, trigger_type visibility toggle, TinyMCE step descriptions. Enum validation on all DB fields. Conditional asset loading.
- [x] **Phase 2B — Frontend Tour Engine** — Driver.js 1.4.0 bundled (MIT, ~5KB) in `assets/js/vendor/driver.js` + `assets/css/vendor/driver.css`. `hl-tour.js` controller: multi-page state via localStorage + `?hl_active_tour` URL param, auto-trigger (first_login + page_visit), exit→redirect→final step flow, skip missing elements, progress bar, mobile responsive. Topbar "?" button with dropdown (mobile bottom sheet). Tour data localized via `wp_json_encode` in `hl-page.php`.
- [x] **Phase 3 — Visual Element Picker** — `hl-element-picker.js` injected into iframe via `?hl_picker=1`. Hover highlighting, click-to-lock, 4-tier selector generation (id > unique hl-* class > ancestor path > full DOM path with instability warning), postMessage with origin validation. Admin modal with "View as Role" dropdown (Teacher/Mentor/School Leader/District Leader/Coach). `is_picker_mode()` + `get_view_as_role()` on `HL_Tour_Service`. Both `HL_Tour_Service` and `HL_BuddyBoss_Integration` `get_user_hl_roles()` respect view-as override.
- [x] **Bug fixes** — Infinite recursion in `onDestroyStarted`, interactive step cross-page navigation (preventDefault + save state before redirect), button text-shadow override in frontend.css, deploy tar exclude fix for `vendor/` directory.
- [x] **Deployed to test + production** — All phases verified on `academy.housmanlearning.com`.

### Feature Tracker (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-06-feature-tracker-design.md` | **Plan:** `docs/superpowers/plans/2026-04-06-feature-tracker.md`
- [x] **DB schema** — `hl_ticket` + `hl_ticket_comment` tables added to `get_schema()`.
- [x] **Ticket Service** — `HL_Ticket_Service` with CRUD, permissions (2hr edit window, admin email constant), search/filter, status transitions, comments, audit logging.
- [x] **Frontend page** — `HL_Frontend_Feature_Tracker` shortcode `[hl_feature_tracker]`, 6 AJAX endpoints, modal UI (detail, create, edit), filter bar, search, toast notifications.
- [x] **Plugin wiring** — Loaded in `hl-core.php`, sidebar menu item for coaches + admins, CLI create-pages entry.
- [x] **CSS + JS** — `.hlft-*` design system section, jQuery AJAX handlers with `esc()` XSS protection, modal logic, debounced search.
- [ ] **Deployed to test** — Pending.

### Suspended User Handling (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-06-suspended-users-design.md` | **Plan:** `docs/superpowers/plans/2026-04-06-suspended-users.md`
- [x] **Central helper** — `HL_BuddyBoss_Integration::is_user_suspended()` + `get_suspend_not_exists_sql()` with per-request cache and graceful degradation.
- [x] **Frontend filtering** — Suspended users hidden from Learners, Team Page, Coach Mentors, Reports. User Profile blocked for non-admins.
- [x] **Admin badges** — Red "Suspended" badge on Enrollments, Assessments, Coaching pages.
- [x] **Admin filter** — Suspension filter dropdown on Enrollments list (All / Suspended Only / Exclude Suspended) with count.
- [ ] **Deployed to test** — Pending.

### Admin Component Progress Override (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-06-component-progress-override-design.md` | **Plan:** `docs/superpowers/plans/2026-04-06-component-progress-override.md`
- [x] **LD sync methods** — `reset_course_progress()` + `mark_course_complete()` on `HL_LearnDash_Integration`. Correct LD API calls (activity table + usermeta), `function_exists()` guards.
- [x] **POST handler** — `handle_component_actions()` with own nonce/form, pathway validation via `hl_pathway_assignment` join, exempt override cleanup on reset, multi-language LD reset, LD sync failure warning notices.
- [x] **UI table** — Component Progress section below enrollment edit form. Eligibility filtering, status badges, exempt override display, non-LD info note. Works in both standalone and Cycle Editor contexts.
- [x] **Deployed to production** — 2026-04-07.

### LearnDash Enrollment Sync (April 2026)
- [x] **Pathway assignment → LD enrollment** — `on_pathway_assigned()` hook on `HL_LearnDash_Integration`. When `assign_pathway()` fires, enrolls user in all `learndash_course` components via `ld_update_course_access()`. Language-aware via catalog. Static cache for bulk ops.
- [x] **New component → LD enrollment** — `on_learndash_component_created()` hook. When a new `learndash_course` component is added to a pathway, enrolls all users assigned to that pathway.
- [x] **Enrollment creation → LD enrollment** — `on_enrollment_created()` hook. Resolves pathways by role-based fallback for users without explicit pathway assignments. Fires from admin form + import handler.
- [x] **CLI: `wp hl-core sync-ld-enrollment`** — Retroactive bulk sync. Covers both explicit assignments and role-based fallback. `--dry-run` support. `sfwd_lms_has_access()` skip for already-enrolled users.
- [x] **Deployed to production** — 2026-04-09. Cycle 6: 132 new LD enrollments, 174 already had access, 0 errors.

### Email System (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-09-email-system-design.md` | **Plan:** `docs/superpowers/plans/2026-04-09-email-system-plan.md`
- [x] **Phase 1: Foundation** — 4 DB tables (hl_email_template, hl_email_workflow, hl_email_queue, hl_email_rate_limit), schema rev 33→34. `HL_Email_Block_Renderer` (6 block types, dark mode, MSO/VML). `HL_Email_Merge_Tag_Registry` (27 tags, 7 categories, deferred tag support). Cron schedules (5-min queue, hourly, daily). wp_login hook for account activation + last login tracking.
- [x] **Phase 2: Automation Engine** — `HL_Email_Rate_Limit_Service` (floor-bucketed hourly/daily/weekly). `HL_Email_Condition_Evaluator` (8 operators, AND logic, dot-path resolution). `HL_Email_Recipient_Resolver` (6 token types, fan-out). `HL_Email_Queue_Processor` (UUID claim, dedup, deferred tags, 3-retry exponential backoff). `HL_Email_Automation_Service` (13 hook listeners, 12 cron triggers, send windows w/ DST validation).
- [x] **Phase 3: Email Builder** — `HL_Admin_Email_Builder` (two-panel editor, AJAX save/autosave/preview). `email-builder.js` (Sortable.js CDN, block CRUD, contenteditable text editing, merge tag toolbar, WP Media Library integration, Email Health panel, desktop/mobile/dark preview).
- [x] **Phase 4: Admin UI** — `HL_Admin_Emails` (4 tabs: Automated Workflows, Email Templates, Send Log, Settings). Emails submenu under Housman LMS. Workflow CRUD form with trigger/condition/recipient/template/delay/send-window fields. Rate limit settings. Queue health dashboard. Retry Failed button.
- [x] **Phase 5: Manual Sends** — Cycle Editor Emails tab rewrite with template select, role filter, recipient checkboxes, dedup badges, Send Now. Legacy invitation UI collapsed in `<details>` with deprecation notice.
- [x] **Phase 6: Migration** — `HL_Email_Template_Migration` (6 coaching templates from wp_options → hl_email_template, hl_account_activated backfill). Scheduling email service `try_block_render()` for new renderer fallback.
- [x] **Admin UI CSS** — 3-column grid builder layout, block cards with toolbars, merge tag pills, Email Health traffic light, status badge pills, filter pill navigation, queue health stat cards, workflow form styling. Version bump 1.2.0→1.2.1 for cache busting.
- [x] **Code Reviews** — 3 review cycles (Phase 1, Phase 2, Phases 3-6). 19 MUST FIX issues caught and resolved: cron race condition, SQL injection patterns, XSS in preview, N+1 queries, dedup token correctness, draft cleanup scope, capability checks, javascript: URL blocking.
- [x] **CLI Testing** — 8 test suites (37 assertions), all pass: block renderer, merge tags, condition evaluator, rate limiter, recipient resolver, queue processor, end-to-end automation, cleanup verification. Zero real emails sent.
- [x] **Deployed to test** — 2026-04-10. Schema rev 34, 4 tables, 6 migrated templates, 3 cron events, all verified.
- [ ] **Phase 7: Hardening** — Security review, cron reliability, performance verification, component window columns (`available_from`/`available_to` on `hl_component` for cron triggers REM-2, REM-4, REM-5). Pending — subsumed into Email System v2 Track 3.

### Email System v2 Build (Active — April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-10-email-system-v2-design.md` (Appendix A 86 items addressed)
> **Handoff:** `docs/superpowers/plans/2026-04-11-email-v2-handoff.md`
> **Plans:** `2026-04-11-email-v2-track{1,2,3}-*.md`
> **Build journal:** `.claude/v2-build-journal.md`
> **Progress:** 10 / 52 tasks complete — **Track 3 foundation merged + Tasks 6 (Rev 35) / 7 / 8 / 9 (Rev 36) in flight on `feature/email-v2-track3-backend`**

**Branches:**
- `feature/email-v2-track3-backend` — backend fixes + Rev 35 + admin UX + draft envelope + Rev 36 (10/32 tasks done — Tasks 1, 2, 3, 4, 5, 6, 7, 8, 9, 23 all landed + polish §3-§9)
- `feature/email-v2-track1-admin-ux` — admin UX (not started; waits on Track 3 prerequisites)
- `feature/email-v2-track2-builder` — builder UX (not started; can run parallel to Track 3)

**Track 3 — Backend Fixes (10/32):**
- [x] **Task 1: `HL_Roles` helper** — Format-agnostic role matching (JSON + CSV), fixes `LIKE '%leader%'` substring bug. `HL_Roles::parse_stored`, `has_role`, `sanitize_roles`, `scrub_is_complete` + `OPTION_SCRUB_DONE` const. CLI test harness `wp hl-core email-v2-test` registered, `roles` group filled with 12 assertions. Phase B + Phase D quality gate PASS.
- [x] **Task 2: Route condition evaluator through `HL_Roles`** — `HL_Email_Condition_Evaluator::evaluate_single()` now has a role-aware early-return branch above the generic switch. Routes `enrollment.roles` through `HL_Roles::has_role()` / `parse_stored()` for all 6 supported ops; rejects `gt`/`lt`. `test_resolver()` filled with 13 assertions. Phase B + Phase D quality gate PASS.
- [x] **Task 5: `HL_Audit_Service::get_last_event()` + try/catch `log()`** — `log()` wrapped in try/catch + `$wpdb->insert === false` return-value check (closes the gap the plan's literal try/catch missed — `wpdb->insert` does not throw on SQL errors). New `record_audit_failure()` private helper routes both failure paths to `error_log` + daily `hl_audit_fail_count_YYYY-MM-DD` counter bump, itself wrapped in a last-resort try/catch. New public `get_last_event($entity_id, $action_type): ?array` returns the latest matching row with `actor_name` JOIN, enables Track 1 Task 14 Force Resend history. `test_audit` group filled with 3 assertions + cleanup. Phase B + Phase D quality gate PASS.
- [x] **Task 6: Schema Rev 35 — `hl_component` window columns + composite indexes** — Bumped schema rev 34→35 with idempotent `migrate_component_add_window_cols()` method. Adds `hl_component.available_from`/`available_to` DATE columns (after `ordering_hint`), composite index `hl_component.type_pathway` (component_type, pathway_id) for Task 17 coaching_pre_end planning, `hl_coaching_session.component_mentor_status` (component_id, mentor_enrollment_id, session_status), and — added during foundation polish — `hl_audit_log.entity_action_time` (entity_id, action_type, created_at) so `HL_Audit_Service::get_last_event()` stays off the filesort path on Force Resend history reads. All 5 ALTERs route through a new `run_rev35_alter($sql, $label)` helper that logs `$wpdb->last_error` to `error_log` before bailing (observability fix from Sr SWE review). `CREATE TABLE` bodies updated inline for all three tables so fresh installs match the migrated shape. `test_schema` group filled with 6 assertions covering columns (with `DATA_TYPE = 'date'` checks), revision gate, and all 3 composite indexes. Full quality gate PASS: Phase B (SQL reviewer 9/10 PASS, WP/idempotency reviewer 8/10 PASS_WITH_NITS), Phase D (Sr SWE 7.9/10 APPROVE_WITH_FIXES — 3 should-fixes applied inline; WP Expert 8.6/10 APPROVE_WITH_FIXES — 1 should-fix applied inline), Phase G error-likelihood 0/10. Live verified on test server: `wp eval 'HL_Installer::maybe_upgrade();'` bumps rev 34→35, `wp hl-core email-v2-test --only=schema` 6/6 PASS, full sweep 49/49 PASS.
- [x] **Task 7: Admin pathway form — date pickers for `available_from`/`available_to`** — `HL_Admin_Pathways::render_component_form()` renders a new "Submission Window" `<tr>` with two `<input type="date">` fields (Opens/Closes) immediately after the Complete By row. `save_component()` validates format via anchored regex + `checkdate()` (rejects `2026-02-31` and other pre-strict-mode calendar traps), silently swaps on Opens > Closes with a 60s per-user transient, and writes both columns via the existing `$data` pipeline (no INSERT/UPDATE SQL modification needed). New `maybe_render_date_swap_notice()` hooked to `admin_notices` — gated by `current_user_can('manage_hl_core')` AND `get_current_screen()->id` contains `hl-` so the warning fires in context of the pathway admin, not on an unrelated Dashboard page. `aria-label` on both inputs disambiguates them for screen readers sharing the row header. Full quality gate PASS: Phase B (R1 Admin UX 8/10 PASS_WITH_NITS, R2 Security/escaping 9/10 PASS_WITH_NITS), Phase D combined senior 7.9/10 APPROVE_WITH_FIXES — all 4 should-fixes applied inline (aria-label, screen gate, capability guard, 30s→60s TTL), Phase F flagged `checkdate()` gap → applied inline → Phase G re-check 1/10 READY_TO_COMMIT (no material failure modes remain). **Browser verification deferred** — this is a UI change; I did not load the form in a real browser because admin login credentials are not available in this run. Manual QA script for the Track gate: (1) save inverted dates → warning shows on the next hl-* admin screen, (2) save valid dates → no warning, (3) save blank → nulls persist in DB, (4) navigate to Dashboard after save → warning does NOT appear (screen gate).
- [x] **Task 8: Draft autosave `created_at`/`updated_at` envelope** — `HL_Admin_Email_Builder::ajax_autosave()` now wraps the draft payload in a JSON envelope `{created_at, updated_at, payload}` stored at `hl_email_draft_<user>_<template_id>`. First save stamps `created_at`; subsequent saves preserve it while refreshing `updated_at` so the Task 10 daily cleanup can age-out stale drafts. Draft-load path unwraps the envelope and passes the inner payload string downstream so the existing JS Restore button at `email-builder.js:29` keeps working unchanged — the envelope never leaks across the server/client boundary. Includes empty-payload guard, `wp_json_encode === false` guard (prevents silently persisting `false` as a draft value), and a belt-and-suspenders `$wpdb->update` that flips `autoload='no'` on legacy draft rows each save. Legacy raw-payload drafts continue to load correctly (load-path falls through to `$draft_data = $draft_raw`). Full quality gate PASS: combined Phase B 9/10 PASS_WITH_NITS (round-trip verified for all 4 cases: new / legacy raw / envelope / corrupt), combined Phase D 8/10 APPROVE_WITH_FIXES (2 fixes applied inline: empty-payload + encode-failure guards), Phase G 1/10 READY_TO_COMMIT. Live test server: 49/49 CLI sweep PASS, zero new assertions (pure admin UX change, no CLI-testable surface). Manual browser verification deferred to the Track gate batch UI pass.
- [x] **Task 9: Schema Rev 36 — autoload=no migration for existing drafts** — One-time `UPDATE {wp_options} SET autoload='no' WHERE option_name LIKE 'hl_email_draft_%' AND autoload != 'no'` via new private static `migrate_email_drafts_autoload_off(): bool` appended to `HL_Installer`. Gated on `$wpdb->query() !== false`; error_log prefix matches Rev 35 convention. Rev 36 ladder block mirrors Rev 35's bail-without-bump pattern so partial failure retries cleanly on the next `plugins_loaded`. Pairs with Task 8's per-save belt-and-suspenders autoload flip — this migration cleans the pre-Task-8 rows in one pass so they stop inflating `wp_load_alloptions()`. Filled CLI test `test_drafts` group with 3 assertions (direct method invoke via `Closure::bind(..., null, HL_Installer::class)` mirroring the `test_resolver` pattern, seeded-row autoload flip verification with `wp_generate_password` suffix to avoid collisions, schema_revision ≥ 36 gate). Full 8-phase quality gate PASS (inline-collapsed since pattern mirrors Rev 35 exactly and senior-pass surfaced only the `autoload != 'no'` vs `= 'yes'` choice which was already correct), Phase G 0/10. Live verified on test server: `wp option update hl_core_schema_revision 35 && wp eval 'HL_Installer::maybe_upgrade();'` bumps 35→36 cleanly, `wp hl-core email-v2-test --only=drafts` 3/3 PASS, full sweep 52/52 PASS (49 foundation + 3 drafts).
- [ ] **Task 10: `cleanup_stale_drafts()` daily cron method**
- [ ] **Task 11: Dedup token: remove date component**
- [ ] **Task 12: Column-exists guard + cron early-return**
- [ ] **Tasks 13–17: 5 real cron trigger queries (cv_window, cv_overdue, rp_window, coaching_window, coaching_pre_end)**
- [ ] **Task 18: `last_cron_run_at` tracking + Site Health**
- [ ] **Tasks 19–22: Rev 37 — `HL_Roles` scrub migration + enrollment writes CSV**
- [x] **Task 3: `resolve_school_director()` gated FIND_IN_SET** — Post-Rev-37 branch uses `FIND_IN_SET('school_leader', e.roles) > 0`. Pre-scrub branch keeps LIKE (LIMIT 50) + `HL_Roles::has_role()` PHP post-filter. Private `scrub_done()` helper extracted so both resolver methods share one gate and the `class_exists` defensive check has a single removal point for Task 32 cleanup.
- [x] **Task 4: `resolve_role()` gated FIND_IN_SET + comma rejection** — Same scrub-gate pattern. Rejects comma-poisoned role input with `email_resolver_rejected_role` audit trail. Unified post-filter via `has_role()` runs in BOTH branches as defense-in-depth against partial-scrub rows. CLI assertions prove substring false-match closure and poison-rejection audit emission, with bounded log_id-snapshot cleanup.
- [x] **Task 23: `assigned_mentor` resolver via `hl_team_membership` + `cc_teacher` alias** — New `assigned_mentor` token resolves via 3-step SQL (user enrollment → team mentor exclude-self → user). `cc_teacher` kept as legacy alias that routes to `resolve_observed_teacher()` + emits `email_token_alias_hit` audit. Class docblock updated. Phase B + combined review PASS (one blocking self-mentor bug caught and fixed inline; two test hygiene issues also fixed via `log_id` snapshot pattern).
- [ ] **Tasks 24–28: Queue processor deliverability hardening (`mb_encode_mimeheader`, `From`/`Reply-To`/`List-Unsubscribe`, HMAC unsubscribe, `wp_mail_failed`, dynamic stuck-row threshold)**
- [ ] **Task 29: Workflow soft-delete (`status='deleted'`)**
- [ ] **Task 30: Force resend action + handler + history display** *(depends on Task 5)*
- [ ] **Task 31: `LIMIT 5000` safety cap on `coaching_pre_end`**
- [ ] **Task 32: Wire up CLI test command + final regression sweep**

**Track 3 foundation checkpoint** (run after Tasks 1, 2, 5, 23 land, before fanning out Track 1):
- [ ] Deploy to test server, run `wp hl-core email-v2-test --only=roles`, `wp hl-core smoke-test`
- [ ] Merge `feature/email-v2-track3-backend` to `main`

**Track 1 — Admin UX (0/15):** Waits on Track 3 prerequisites (Tasks 1, 2, 5, 23).

**Track 2 — Builder (0/5):** Can start immediately on its own branch.

### Lower Priority (Future)
- [ ] Scope-based user creation for client leaders
- [ ] Import templates (downloadable CSV)
- [ ] **Certificate download URL** — Program Page cert card "Download" button links to `#`. Wire up real URL once certificates are generated (see `class-hl-frontend-program-page.php`).
- [ ] **Group Report page** — Reports Hub lists "Program Group Report" card with empty URL. Build dedicated page for cross-cycle aggregate metrics (see `class-hl-frontend-reports-hub.php`).

---

## Scheduled reviews

Dated items that fire on a calendar, not on a task completion. Remove each line after the action is taken.

- **2026-07-10** — Grep `hl_audit_log` for `email_token_alias_hit` over the prior 90 days. If zero hits, remove the `cc_teacher` legacy alias case from `HL_Email_Recipient_Resolver::resolve_token()`, remove the corresponding CLI test block in `test_resolver()`, and remove the `observed_teacher/cc_teacher` dual-key reading in `resolve_observed_teacher()`. Spec reference: A.6.11 (90-day deprecation window). Introduced by Email System v2 Track 3 Task 23 (commit `3190f63`). Memory: `project_cc_teacher_deprecation_2026_07.md`.

---

## Completed Phases (1-32 + 35)

Phases 1-11: Foundation (DB schema, LearnDash wiring, assessments, coaching, reporting, frontend, sidebar nav)
Phases 14-18: Admin UX, architecture (pathway assignments, cohort groups, hierarchy nav), CSS design system
Phases 19-21: Custom teacher self-assessment system, control group support, Lutheran seeder, nuke command
Phase 22: Grand Rename (Center→School, Children→Child, Cohort→Track hierarchy restructure)
Phases 23-27: Child assessment restructure (per-child age groups, roster management), teacher assessment editor, CSV exports, separate PRE/POST instruments
Phases 28-31: Dashboard shortcode, instrument nuke protection, admin docs system, K-2nd grade age group
Phase 32: Phase entity + Track types architecture (B2E Master Reference)
Phase 35: Admin UX/UI redesign + menu consolidation
Grand Rename V3: Corrective rename — Partnership↔Cohort swap, Phase entity deleted. Partnership=container, Cycle=yearly run.

Note: Phases 12 (MS365 Calendar) and 13 (BuddyBoss Profile Tab) were deferred.
