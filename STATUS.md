# STATUS.md — HL Core Build Status

**Phases 1-32 + 35 complete. Deployed to production (March 2026).** 34 shortcode pages (+ 4 backward-compatible aliases), 18 admin controllers, 44 DB tables, 23 services, 16 CLI commands. Lutheran control group provisioned (39 enrollments, 286 children, 11 schools).

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

### Lower Priority (Future)
- [ ] Scope-based user creation for client leaders
- [ ] Import templates (downloadable CSV)

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
