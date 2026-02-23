# Housman Learning Core (HL Core) Plugin

**Version:** 1.0.0
**Requires:** WordPress 6.0+, PHP 7.4+, JetFormBuilder (for assessment/observation forms)
**Status:** v1 complete — Phases 1-11 done, Phase 14 (Admin UX) done, Phase 15 (Architecture: Pathway Assignments + Cohort Groups) done, Phase 17 (Org Units hierarchy) done (25 shortcode pages, 15 admin pages, 34 DB tables, tabbed cohort editor)

## Overview

HL Core is the system-of-record plugin for Housman Learning Academy Cohort management. It manages organizations, cohort enrollment, learning pathways, assessments, observations, coaching sessions, imports, and reporting.

## What's Implemented

### Database Schema (34 custom tables)
- **Org & Cohort:** `hl_orgunit`, `hl_cohort`, `hl_cohort_center`, `hl_cohort_group`
- **Participation:** `hl_enrollment`, `hl_team`, `hl_team_membership`
- **Classrooms:** `hl_classroom`, `hl_teaching_assignment`, `hl_child`, `hl_child_classroom_current`, `hl_child_classroom_history`
- **Learning Config:** `hl_pathway`, `hl_pathway_assignment`, `hl_activity`, `hl_activity_prereq_group`, `hl_activity_prereq_item`, `hl_activity_drip_rule`, `hl_activity_override`
- **State/Rollups:** `hl_activity_state`, `hl_completion_rollup`
- **Instruments:** `hl_instrument` (children assessment instruments only; teacher self-assessment and observation forms are in JetFormBuilder)
- **Assessments:** `hl_teacher_assessment_instance` (completion tracking + jfb_form_id/jfb_record_id), `hl_teacher_assessment_response` (DEPRECATED — retained for dbDelta safety; JFB Form Records handles response storage), `hl_children_assessment_instance`, `hl_children_assessment_childrow`
- **Observations:** `hl_observation` (+ jfb_form_id/jfb_record_id), `hl_observation_response` (DEPRECATED — retained for dbDelta safety; JFB Form Records handles response storage), `hl_observation_attachment`
- **Coaching:** `hl_coaching_session`, `hl_coaching_session_observation`, `hl_coaching_attachment`
- **System:** `hl_import_run`, `hl_audit_log`

### Domain Models & Repositories
All 8 core entities have domain model classes with proper properties and repository classes with full CRUD:
- OrgUnit, Cohort, Enrollment, Team, Classroom, Child, Pathway, Activity

### Services (Business Logic)
- **CohortService** - Cohort CRUD with validation
- **EnrollmentService** - Enrollment management with uniqueness checks
- **TeamService** - Team + membership management with constraints: hard enforcement of 1 team per enrollment per cohort, soft max-2-mentors-per-team (override-capable)
- **ClassroomService** - Full classroom CRUD, teaching assignment CRUD (create/update/delete with audit logging + auto-trigger children assessment instance generation), child classroom assignment/reassignment with history tracking
- **PathwayService** - Pathway + Activity CRUD
- **RulesEngineService** - Full prerequisite + drip rule evaluation engine
  - ALL_OF, ANY_OF, and N_OF_M prerequisite group types
  - Fixed date and completion-delay drip rules
  - "Most restrictive wins" logic
  - Override handling (exempt, manual_unlock, grace_unlock)
  - Prerequisite cycle detection (DFS-based) with admin validation on save
- **AssessmentService** - Teacher/Children assessment queries + completion checks + children assessment activity_state updates + rollup triggering on submission + instance auto-generation for cohort teaching assignments
- **ObservationService** - Full observation CRUD: create draft observations, query by cohort/mentor/user with joined data, get observable teachers from team membership, teacher classroom lookup, observation activity/form lookup for JFB integration
- **CoachingService** - Full coaching session CRUD (create/update/delete) + attendance marking with activity_state updates + rollup triggering + observation linking/unlinking + attachment management (WP Media)
- **AuditService** - Full audit logging to `hl_audit_log` table
- **ImportService** - CSV import with preview/commit workflow supporting four import types: participants (identity matching, WP user creation, enrollment), children (fingerprint-based matching, classroom assignment), classrooms (duplicate detection by center+name), teaching assignments (email-to-enrollment lookup). Header synonym mapping, error reporting with remediation suggestions
- **PathwayAssignmentService** - Explicit pathway-to-enrollment assignments: assign/unassign/bulk_assign/bulk_unassign, get_pathways_for_enrollment (explicit first, role-based fallback), get_enrollments_for_pathway, get_unassigned_enrollments, sync_role_defaults (auto-assign by target_roles matching), enrollment_has_pathway check, legacy assigned_pathway_id sync
- **ReportingService** - Completion rollup engine (weighted average, upsert to hl_completion_rollup, live LD fallback), cohort batch recompute, on-the-fly computation, activity-level detail query, cohort summary, scope-filtered participant report (cohort/center/district/team/role filters), cohort activity detail (batch per-enrollment per-activity), center summary (grouped by center), team summary (grouped by team), group summary (cross-cohort aggregation), group aggregate metrics, CSV exports (participant completion with per-activity columns, center summary, team summary, group summary)

### Admin Pages (WP Admin UI)
Full CRUD admin pages with WordPress-styled tables and forms:
- **Cohorts** - List, create, edit/delete cohorts. Group column in list. **Tabbed Editor:** 7-tab interface for existing cohorts (Details with group dropdown, Centers, Pathways, Teams, Enrollments with pathway column, Coaching, Classrooms). **Inline sub-entity CRUD:** Pathways/Teams/Enrollments tabs now support `&sub=new|edit|view|activity` for full inline CRUD — forms render inside the cohort editor with breadcrumbs, cohort-locked dropdown, and save/delete redirects back to the correct tab. Standalone pages still work independently. Centers tab with link/unlink. Enrollments tab with completion bars + pagination + pathway names + inline edit/enroll. Coaching tab with assignments + recent sessions. Classrooms tab scoped to linked centers.
- **Cohort Groups** - Full CRUD for program-level cohort grouping. Create/edit/delete groups with name, code, status, description. Linked cohorts display on edit page. Cohorts assigned via group dropdown on cohort Details form.
- **Org Units** - Hierarchical district-centric view: districts as collapsible sections with nested center tables showing leader names, classroom/cohort counts. "Add District" and "Add Center" header buttons. Edit form enhancements: dynamic titles, contextual breadcrumbs, district edit shows child centers table with "Add Center" button, center edit shows linked cohorts/classrooms/staff tables. Pre-fill support for type and parent from URL params.
- **Enrollments** - Enroll users in cohorts with role assignment, cohort filter
- **Pathways & Activities** - Configure pathways per cohort, add activities with type-specific dropdowns (JFB form selector, HL instrument selector, LearnDash course selector), auto-built external_ref JSON, prerequisite group editor (all_of/any_of/n_of_m with cycle detection), prereq summary column in activity list, drip rule UI (fixed release date + delay-after-activity with base_activity selector and delay_days). **Clone/Template:** Clone pathway to any cohort (copies activities, prereqs, drip rules with ID remapping). Save as Template / Templates tab. Start from Template on new pathway form. **Pathway Assignments:** Assigned Enrollments section on pathway detail with count badge, sync role defaults button, bulk assign multi-select, quick assign dropdown, assignment table with type badges (Explicit/Role Default) and remove button.
- **Teams** - Create teams within cohorts/centers, view team members
- **Classrooms** - Full CRUD with detail view: teaching assignments (cohort-scoped add/remove) + children roster (assign/reassign/remove with history)
- **Imports** - AJAX-based 3-step wizard (Upload > Preview & Select > Results) for CSV import with import type selector (participants, children, classrooms, teaching assignments), dynamic column rendering per type, row-level status badges, bulk actions, commit, error report download, column hints per type, and import history table
- **Audit Log** - Searchable audit log viewer with cohort and action type filters, pagination

- **Instruments** - Full CRUD for children assessment instruments: question editor (add/edit/remove with type, prompt, allowed_values, required flag), version management with edit warnings when instances exist. Only children_infant, children_toddler, children_preschool types.
- **Coaching Sessions** - Full CRUD with cohort filter, mentor/coach selectors, session title, meeting URL, date/time, session status dropdown (scheduled/attended/missed/cancelled with terminal-state lock), rich-text notes (wp_editor), observation linking from submitted observations, WP Media attachments
- **Coach Assignments** - Full CRUD for coach-to-scope assignments (center/team/enrollment) with cohort filter, scope name resolution, active/ended status badges, effective date management

- **Assessments** - Tabbed staff assessment viewer/exporter: teacher self-assessments (list/detail/CSV), children assessments (list/detail/instance generation from teaching assignments/CSV), summary metric cards
- **Reports** - Full reporting dashboard: scope-based filtering (cohort/center/district/team/role), summary cards, center/team summary tables, participant completion table with progress bars, enrollment detail drill-down with activity-level status, CSV exports (completion, center summary, team summary, teacher assessments, children assessments), rollup recompute action

**Admin fix:** All admin pages use `admin_init` dispatcher for POST saves and GET deletes (redirect-before-output pattern), preventing blank pages after form submissions.

### Front-End Pages (Shortcodes)
- **My Progress** `[hl_my_progress]` - Participant's own cohort progress with pathway/activity completion, progress rings, inline JFB form embedding for teacher self-assessments (via `?hl_open_activity=ID`), contextual action links for observations/children assessment/coaching activities
- **Team Progress** `[hl_team_progress]` - Mentor's team view with member cards, expandable activity details
- **Cohort Dashboard** `[hl_cohort_dashboard]` - Leader/staff cohort overview with center filter, participant completion table
- **Children Assessment** `[hl_children_assessment]` - Teacher's children assessment workflow: instance list, per-child matrix form (via HL_Instrument_Renderer), draft save, final submit, read-only submitted summary
- **Observations** `[hl_observations]` - Mentor observation workflow: observation list, new observation (select teacher from team + optional classroom), JFB form rendering with pre-populated hidden fields, submitted summary view
- **My Programs** `[hl_my_programs]` - Participant's program cards grid: featured image, program name, cohort name, completion %, status badge (Not Started/In Progress/Completed), "Continue"/"Start" button linking to Program Page. Uses PathwayAssignmentService for explicit/role-based pathway resolution with legacy fallback. Auto-discovers `[hl_program_page]` page URL. Empty state for no enrollments. My Coach widget: avatar, name, email, "Schedule a Session" button via CoachAssignmentService resolution.
- **Program Page** `[hl_program_page]` - Single program detail page (via `?id=X&enrollment=Y`): hero image, pathway name, description, cohort name, progress ring, details panel (avg time, expiration, status), objectives, syllabus link, activity cards with per-activity status/progress/actions. Activity actions route to Activity Page, LD course permalink, or children assessment page. Breadcrumb back to My Programs.
- **Activity Page** `[hl_activity_page]` - Single activity page (via `?id=X&enrollment=Y`): renders JFB form for self-assessments/observations (with hidden fields), links to children assessment page, redirects to LD course, shows managed-by-coach notice for coaching. Locked/completed guards with reason display. Breadcrumb back to Program Page.
- **My Cohort** `[hl_my_cohort]` - Auto-scoped cohort workspace for Center Leaders and District Leaders. Cohort switcher for multi-enrollment users. Scope auto-detection (center_leader → center, district_leader → district, staff → all). Four tabs: Teams (cards with mentor names, member count, avg completion, progress bar, "View Team" link), Staff (searchable table with name/email/team/role/completion), Reports (filterable completion table with institution/team/name filters, expandable per-activity detail rows, CSV download), Classrooms (table with center/age band/child count/teacher names, links to classroom page).
- **Team Page** `[hl_team_page]` - Team detail page (via `?id=X`): dark gradient header with team name, center, cohort, member count, mentor names, avg completion metric. Two tabs: Team Members (searchable table with name, email, role badge, completion progress bar), Report (completion report table with per-activity detail expansion and CSV export). Access control: staff, team members, center/district leaders with matching scope. Breadcrumb back to My Cohort.
- **Classroom Page** `[hl_classroom_page]` - Classroom detail page (via `?id=X`): dark gradient header with classroom name, center name, age band, teacher names. Searchable children table with name, date of birth, computed age (years/months), gender (from metadata JSON). Access control: staff, assigned teachers, center/district leaders with matching scope. Breadcrumb back to My Cohort.
- **Districts Listing** `[hl_districts_listing]` - Staff CRM directory card grid of all school districts: district name, # centers, # active cohorts (via cohort_center join). Links to District Page. Staff-only access (manage_hl_core).
- **District Page** `[hl_district_page]` - District detail page (via `?id=X`): dark gradient header with district name and stat boxes (centers, participants). Sections: Active Cohorts (rows with name, status badge, dates, participant count, "Open Cohort" link to Cohort Workspace filtered by district), Centers (card grid with leader names, "View Center" link), Overview stats. Access: staff + district leaders enrolled in that district. Breadcrumb to Districts Listing.
- **Centers Listing** `[hl_centers_listing]` - Staff CRM card grid of all centers: center name, parent district, leader names. Links to Center Page. Staff-only access (manage_hl_core).
- **Center Page** `[hl_center_page]` - Center detail page (via `?id=X`): dark gradient header with center name, parent district link. Sections: Active Cohorts (rows with participant count at this center, "Open Cohort" link to Cohort Workspace filtered by center), Classrooms table (age band, children, teachers), Staff table (name, email, role, cohort). Access: staff + center leaders + district leaders of parent. Breadcrumb to parent district or Centers Listing.
- **Cohort Workspace** `[hl_cohort_workspace]` - Full cohort command center (via `?id=X&orgunit=Y`). My Cohort header with scope indicator and org unit filter dropdown for staff. Five tabs: Dashboard (avg completion %, total participants, completed/in-progress/not-started counts, teacher/mentor/center counts), Teams (card grid reusing team card pattern), Staff (searchable table), Reports (filterable completion table with per-activity detail expansion and CSV export), Classrooms (table). Scope from URL orgunit parameter or enrollment roles. Access: staff + enrolled leaders. Breadcrumb to source district/center page.
- **My Coaching** `[hl_my_coaching]` - Participant coaching page: coach info card (avatar, name, email) via CoachAssignmentService resolution, enrollment switcher, upcoming sessions with meeting link/reschedule/cancel, past sessions with status badges, schedule new session form with auto-suggested title from next coaching activity
- **Cohorts Listing** `[hl_cohorts_listing]` - Scope-filtered cohort card grid with search bar, cohort group filter dropdown, and status filter checkboxes (Active/Future default, Paused, Archived). Shows cohort name, code, status badge, start/end dates, participant count, center count. Links to Cohort Workspace. Scope: admin all, coach assigned cohorts, leaders enrolled cohorts.
- **Institutions Listing** `[hl_institutions_listing]` - Combined districts + centers view with All/Districts/Centers toggle, search bar. Districts show center count and active cohort count. Centers show parent district and leader names. Scope: admin all, leaders see own district/center scope.
- **Coaching Hub** `[hl_coaching_hub]` - Front-end coaching session table with search (participant/coach/title), status filter, cohort filter. Shows title, participant, coach, date/time, status badge, meeting link. Scope: admin all, coach assigned cohorts, enrolled users own sessions.
- **Classrooms Listing** `[hl_classrooms_listing]` - Searchable classroom table with center and age band filters. Shows classroom name, center, age band badge, child count, teacher names. Links to Classroom Page. Scope: admin all, leaders by center, teachers own assignments.
- **Learners** `[hl_learners]` - Participant directory with pagination (25/page), search by name/email, cohort/center/role filters. Shows name (BuddyBoss profile link), email, roles, center, cohort, completion progress bar. Scope: admin all, coach assigned, leaders by scope, mentors team only.
- **Pathways Listing** `[hl_pathways_listing]` - Staff-only pathway browser with search and cohort filter. Card grid: pathway name, cohort, activity count, target roles, featured image, avg completion time. Scope: admin/coach only.
- **Reports Hub** `[hl_reports_hub]` - Card grid of available report types: Completion Report (links to workspace/my-cohort reports tab), Coaching Report (links to coaching hub), Team Summary (links to workspace/my-cohort teams tab), Program Group Report (cross-cohort, coming soon), Assessment Report (coming soon). Role-based card visibility.
- **My Team** `[hl_my_team]` - Auto-detects mentor's team via team_membership. Single team: renders Team Page inline. Multiple teams: team selector card grid with name, center, member count, cohort. No teams: friendly message.

### Scope Service
- **HL_Scope_Service** — Shared static helper for role-based scope filtering across all listing pages. Detects user role (admin/coach/leader/mentor/teacher), computes visible cohort_ids, center_ids, district_ids, team_ids, enrollment_ids. Admin sees all (empty arrays = no restriction). Coach filtered by hl_coach_assignment + own enrollments. District leader expands to all centers in district. Static cache per user_id per request. Convenience helpers: can_view_cohort(), can_view_center(), has_role(), filter_by_ids().

### Security
- Custom `manage_hl_core` capability
- Coach WP role created on activation
- `HL_Security::assert_can()` for server-side checks
- Assessment response privacy (staff-only access)
- Import wizard: per-action nonces, capability checks, run ownership verification, file validation (extension + MIME + size)

### WP-CLI Commands
- **`wp hl-core seed-demo`** — Creates a full realistic demo dataset: 1 district, 2 centers, 1 active cohort, 4 classrooms, 3 instruments, 16 users (10 teachers, 2 mentors, 2 center leaders, 1 district leader, 1 coach), 15 enrollments, 2 teams with memberships, 10 teaching assignments, ~26 children, 2 pathways (teacher: 5 activities, mentor: 2 activities), prerequisite and drip rules, partial activity completion states, computed rollups, 3 coach assignments (2 center-level + 1 team-level), and 6 coaching sessions (attended, scheduled, missed, rescheduled, cancelled)
- **`wp hl-core seed-demo --clean`** — Removes all demo data (users, cohort, org units, instruments, children, coach assignments, coaching sessions, and all dependent records) identified by cohort code `DEMO-2026` and `demo-*@example.com` user emails
- **`wp hl-core seed-palm-beach`** — Seeds realistic demo data from the ELC Palm Beach County program: 1 district, 12 centers (3 Head Start + 1 learning center + 8 FCCHs), 1 active cohort, 29 classrooms, 3 instruments, 57 users (47 real teachers with actual names/emails, 4 center leaders, 4 mentors, 1 district leader, 1 coach), 56 enrollments (8 FCCH teachers dual-role as center leaders), 4 teams (WPB Alpha/Beta, Jupiter, South Bay), 47 teaching assignments, 286 real children (with DOBs, gender, ethnicity metadata), 2 pathways (teacher: 5 activities, mentor: 2 activities), prerequisite and drip rules, partial activity states, computed rollups, 4 coach assignments (3 center-level + 1 team-level), and 5 coaching sessions
- **`wp hl-core seed-palm-beach --clean`** — Removes all Palm Beach data (users, cohort, org units, instruments, children, classrooms, coach assignments, coaching sessions, and all dependent records) identified by cohort code `ELC-PB-2026` and `_hl_palm_beach_seed` user meta
- **`wp hl-core create-pages`** — Creates all 24 WordPress pages for HL Core shortcodes (personal, directory, hub, detail, and assessment pages). Skips pages that already exist. `--force` to recreate. `--status=draft` for staging.

### REST API
- `GET /wp-json/hl-core/v1/cohorts`
- `GET /wp-json/hl-core/v1/cohorts/{id}`
- `GET /wp-json/hl-core/v1/enrollments?cohort_id=X`
- `GET /wp-json/hl-core/v1/orgunits?type=center`
- `GET /wp-json/hl-core/v1/pathways?cohort_id=X`
- `GET /wp-json/hl-core/v1/teams?cohort_id=X`

### LearnDash Integration
- Course progress reading via `learndash_course_progress()`
- Course completion event hook (`learndash_course_completed`) — fully wired: finds enrollments, matches activities by course_id, marks complete, triggers rollups, audit logs
- Batch progress reading for reporting

### BuddyBoss Integration
- **HL_BuddyBoss_Integration** service (`includes/integrations/class-hl-buddyboss-integration.php`)
- Multi-hook injection strategy for reliable menu rendering:
  1. **Profile Dropdown** — `buddyboss_theme_after_bb_profile_menu` (last hook in header-profile-menu.php)
  2. **BuddyPanel Left Sidebar** — `wp_nav_menu_items` filter on `buddypanel-loggedin` location, appends section divider + items using native BB CSS classes
  3. **JS Fallback** — `wp_footer` injects into BuddyPanel DOM and/or profile dropdown if PHP hooks did not fire (covers empty BuddyPanel menu or custom profile dropdown override)
- 11 menu items with role-based visibility (matches doc 10 section 16):
  - **Personal (require active enrollment):** My Programs, My Coaching (any enrollment), My Team (mentor only), My Cohort (leader or mentor)
  - **Directories:** Cohorts, Institutions (staff or leader), Classrooms (staff, leader, or teacher), Learners (staff, leader, or mentor)
  - **Staff tools:** Pathways (staff only), Coaching Hub (staff or mentor), Reports (staff or leader)
- Staff WITHOUT enrollment see only directory/management pages; staff WITH enrollment see both personal and management pages
- Multi-role users see union of all role menus
- Active page highlighting via `current` / `current-menu-item` CSS class
- Role detection from `hl_enrollment.roles` JSON (not WP roles) with static caching
- Menu items built once per request via `get_menu_items_for_current_user()` (static cache shared across all three hooks)
- Shortcode-based page URL discovery with static caching
- Graceful no-op when BuddyBoss is not active

### JetFormBuilder Integration
- **HL_JFB_Integration** service (`includes/integrations/class-hl-jfb-integration.php`)
- Hook listener for `hl_core_form_submitted` custom action — processes teacher self-assessment and observation submissions
- Automatic activity_state completion on JFB form submit
- Front-end form rendering helper with hidden field pre-population
- Admin notice when JetFormBuilder is inactive
- Available forms query for admin dropdowns
- JFB-powered activity types disabled in admin when JFB not active

---

## Build Queue (Ordered — work top to bottom)

This is the prioritized task list. Each session, pick up from the first unchecked `[ ]` item.

### Phase 1: JetFormBuilder Integration Foundation
_Read docs: 06 (section 2), 04 (section 3.2.2, 3.2.5), 09 (section 3.3)_

- [x] **1.1 DB Schema Cleanup** — In class-hl-installer.php: deprecated `hl_teacher_assessment_response` and `hl_observation_response` table definitions (retained for dbDelta safety). Added `jfb_form_id` and `jfb_record_id` columns to `hl_teacher_assessment_instance` and `hl_observation`. Cleaned `hl_instrument` enum to only children_infant, children_toddler, children_preschool.
- [x] **1.2 JFB Integration Service** — Created `includes/integrations/class-hl-jfb-integration.php`. Hook listener for `jet-form-builder/custom-action/hl_core_form_submitted` that processes teacher_self_assessment and observation submissions: updates instance records, marks activity_state complete, fires rollup recomputation action, logs audit. Includes front-end form rendering helper, available forms query for admin, and admin notice when JFB is inactive.
- [x] **1.3 Activity Admin Enhancement** — Updated Pathways & Activities admin: activity_type dropdown now uses correct DB enum values (learndash_course, teacher_self_assessment, children_assessment, coaching_session_attendance, observation). Conditional dropdowns: JFB form selector + phase for self-assessments, JFB form + required count for observations, HL instrument dropdown for children assessments, LearnDash course selector for courses. Builds external_ref JSON from selections. Activity list shows linked resource names. JFB types disabled in dropdown when JFB not active.

### Phase 2: LearnDash Completion Wiring
_Read docs: 04 (section 3.2.1), 09 (section 8)_

- [x] **2.1 LearnDash Hook Body** — Wired `learndash_course_completed` hook: finds all active enrollments for the user, matches learndash_course activities by course_id in external_ref, upserts hl_activity_state to 100% complete, triggers rollup recomputation for each affected enrollment, audit logs each completion.
- [x] **2.2 Completion Rollup Engine** — Implemented `HL_Reporting_Service::compute_rollups($enrollment_id)` as singleton with `hl_core_recompute_rollups` action listener. Calculates pathway_completion_percent as weighted average of all activity states. Falls back to live LearnDash progress for courses without cached state. Upserts hl_completion_rollup. Added `recompute_cohort_rollups()` for batch recomputation. Wired into: JFB hook listener, LearnDash hook, coaching attendance marking, and children assessment submission. Also enhanced `get_enrollment_completion()` to compute on-the-fly when no cached rollup exists. Updated `HL_Coaching_Service::mark_attendance()` to update coaching_session_attendance activity_state and trigger rollups. Updated `HL_Assessment_Service` to update children_assessment activity_state and trigger rollups on submission.

### Phase 3: Children Assessment (Custom Form)
_Read docs: 06 (sections 4.2–4.8), 04 (section 3.2.3)_

- [x] **3.1 Instruments Admin Page** — Built full CRUD admin page (`class-hl-admin-instruments.php`): list/create/edit instruments with question editor (JS add/remove rows, question_id, type, prompt_text, allowed_values, required flag). Version management with warning when editing instruments that have existing assessment instances. Only instrument_types: children_infant, children_toddler, children_preschool. Registered in admin menu.
- [x] **3.2 Instance Auto-Generation** — `AssessmentService::generate_children_assessment_instances($cohort_id)` already existed. Wired automatic triggering: ClassroomService `create_teaching_assignment()` and `delete_teaching_assignment()` now fire `hl_core_teaching_assignment_changed` action, which is listened to in hl-core.php init and calls `generate_children_assessment_instances()`. Instances are created per (cohort, enrollment, classroom) with matching instrument auto-selection by age band.
- [x] **3.3 HL_Instrument_Renderer** — Created `includes/frontend/class-hl-instrument-renderer.php`: renders per-child matrix form from instrument questions JSON + classroom roster. Supports all 5 question types (likert, text, number, single_select, multi_select). Save Draft and Submit buttons with confirmation. Sticky first column, responsive horizontal scroll.
- [x] **3.4 Children Assessment Front-End Page** — Created `includes/frontend/class-hl-frontend-children-assessment.php` with `[hl_children_assessment]` shortcode. Instance list view for teachers, single instance form (via HL_Instrument_Renderer with inline fallback), POST handling for draft/submit, read-only submitted summary, security (ownership verification + nonce checks). Registered in shortcodes class.

### Phase 4: Observation & Coaching Workflows
_Read docs: 06 (sections 5, 6)_

- [x] **4.1 Observation Create/Submit Flow** — Expanded `HL_Observation_Service` with full CRUD: `create_observation()`, `get_observation()` with joins, `get_by_mentor_user()`, `get_observable_teachers()` from team membership, `get_teacher_classrooms()`, `get_observation_activity()`, `get_observation_form_id()`. Created `class-hl-frontend-observations.php` with `[hl_observations]` shortcode: list view (all mentor observations), new observation flow (select teacher from team + optional classroom), form view (renders JFB form via `HL_JFB_Integration::render_form()` with pre-populated hidden fields), submitted summary view. Security: mentor enrollment verification, ownership checks, nonce validation. Registered in shortcodes class.
- [x] **4.2 Coaching Session Admin CRUD** — Expanded `HL_Coaching_Service` with full CRUD: `create_session()`, `update_session()`, `delete_session()`, `get_session()` with joins, observation linking/unlinking (`link_observations()`, `unlink_observation()`, `get_linked_observations()`, `get_available_observations()`), attachment management (`add_attachment()`, `remove_attachment()`, `get_attachments()`). Created `class-hl-admin-coaching.php`: list view with cohort filter + attendance badges, create/edit form with cohort/mentor/coach selectors + datetime + attendance radio + wp_editor notes + observation linking section + WP Media attachment picker. Attendance changes trigger existing `mark_attendance()` for activity_state + rollup updates. Registered in admin menu.

### Phase 5: Reporting Dashboard
_Read docs: 08, 09 (section 8)_

- [x] **5.1 Reporting Admin Page** — Replaced stub `class-hl-admin-reporting.php` with full reporting dashboard. Filters bar (cohort required, district/center/team/role optional), summary metric cards (active enrollments, avg completion, centers, teams), center summary table (when no center filter), team summary table (when center selected), participant completion table with inline progress bars and "View Detail" drill-down. Enrollment detail view shows header info + activity-level completion table with status badges. CSV export handling at top of render_page (completion, center summary, team summary, teacher assessment, children assessment). Recompute rollups action with nonce protection. Assessment Exports section (staff only) for teacher self-assessment and children assessment response exports.
- [x] **5.2 CSV Export** — Added `export_completion_csv()` (participant completion with optional per-activity columns), `export_center_summary_csv()`, `export_team_summary_csv()` to HL_Reporting_Service. All use `php://temp` + `fputcsv()`. Participant CSV includes: Name, Email, Roles, Center, Team, Cohort Completion %, Pathway Completion %, plus one column per activity. Children assessment response export (staff only) deferred to Assessments admin page.
- [x] **5.3 Scope-Filtered Queries** — Added `get_participant_report($filters)` with cohort/center/district/team/role/status filters, `get_cohort_activity_detail($cohort_id, $enrollment_ids)` for batch per-enrollment per-activity data, `get_center_summary($cohort_id, $district_id)` grouped by center, `get_team_summary($cohort_id, $center_id)` grouped by team, `get_cohort_activities($cohort_id)` helper. Scope filtering uses team.center_id via team_membership for center resolution, orgunit.parent_orgunit_id for district hierarchy, JSON LIKE for role filtering.

### Phase 6: Constraints & Polish
_Read docs: 02 (teams), 09 (acceptance tests)_

- [x] **6.1 Team Membership Constraints** — Enforced 1-team-per-enrollment-per-cohort as hard constraint in `TeamService::add_member()`: queries existing team memberships for the enrollment's cohort before allowing insertion. Soft constraint: max 2 mentors per team returns WP_Error unless `$force_override = true`. Both constraints return descriptive WP_Error messages.
- [x] **6.2 JFB Dependency Check** — Already implemented in Phase 1.2: `HL_JFB_Integration::maybe_show_inactive_notice()` shows admin notice on HL Core pages when JFB is inactive. `render_form()` returns "form unavailable" fallback. `get_available_forms()` returns empty when JFB inactive. Activity admin disables JFB types in dropdown when JFB not active (Phase 1.3). Acceptance test 9.10.29 satisfied.
- [x] **6.3 Front-End Form Embedding** — Updated `[hl_my_progress]` shortcode: teacher_self_assessment activities show "Open Form" button; clicking it renders the JFB form inline via `?hl_open_activity=ID` with hidden fields (hl_enrollment_id, hl_activity_id, hl_cohort_id) pre-populated from enrollment context. Back-to-Progress navigation, availability validation (locked/completed guard), and proper escaping. Observation activities show "Go to Observations" link/notice, children_assessment shows "Go to Children Assessment" link/notice, coaching_session_attendance shows "Managed by your coach" notice. Added `hl_core_observations_page_url` and `hl_core_children_assessment_page_url` filters for configurable page URLs. Added CSS for buttons, inline form wrapper, and activity action notices.
- [x] **6.4 Additional Import Types** — Extended ImportService with three new import types beyond participants: Children (validate_children_rows + commit_children_import with fingerprint-based identity matching, classroom assignment), Classrooms (validate_classroom_rows + commit_classroom_import with duplicate detection by center+name), Teaching Assignments (validate_teaching_assignment_rows + commit_teaching_assignment_import with email-to-enrollment lookup, duplicate detection). Updated admin import wizard with type dropdown (participants/children/classrooms/teaching_assignments), dynamic column hints, and type-aware AJAX routing. Updated JS preview table to render type-specific columns dynamically. Added header synonyms for date_of_birth, child_identifier, classroom_name, age_band, is_lead_teacher. Added remediation suggestions for all new error types.

### Phase 7: Front-End — Participant Experience
_Read docs: 10 (sections 4.1–4.3, 13 Phase A)_

- [x] **7.1 Pathway Model Expansion** — Added 6 columns to `hl_pathway`: description (longtext), objectives (longtext), syllabus_url (varchar 500), featured_image_id (bigint), avg_completion_time (varchar 100), expiration_date (date). DB migration `migrate_pathway_add_fields()` with column-exists guards. Schema revision bumped to 2. Domain model updated with 6 new properties. Admin Pathways form updated with wp_editor for description/objectives, URL input for syllabus, WP Media uploader for featured image, text input for avg time, date input for expiration. Save + detail display updated.
- [x] **7.2 My Programs Page** — Created `class-hl-frontend-my-programs.php` with `[hl_my_programs]` shortcode. Grid of program cards for logged-in user: featured image (or placeholder), pathway name, cohort name, completion % (weighted average with LD fallback), status badge (Not Started/In Progress/Completed), Continue/Start button. Auto-discovers `[hl_program_page]` page URL. Friendly empty state. Registered in shortcodes class + hl-core.php.
- [x] **7.3 Program Page** — Created `class-hl-frontend-program-page.php` with `[hl_program_page]` shortcode (`?id=X&enrollment=Y`). Validates pathway/enrollment ownership. Hero image, name, description, progress ring. Details panel (avg time, expiration, status with expired/completed/paused detection). Objectives section, syllabus link. Activity cards with per-activity status, progress bars, type badges, lock reasons, action buttons (LD course link, Activity Page link, managed-by-coach notice). Breadcrumb to My Programs.
- [x] **7.4 Activity Page** — Created `class-hl-frontend-activity-page.php` with `[hl_activity_page]` shortcode (`?id=X&enrollment=Y`). Per-type rendering: JFB form embed for self-assessments/observations (with hidden fields), children assessment page link, LD course redirect, coaching notice. Locked view with descriptive reason. Completed view. Breadcrumb to Program Page. Registered in shortcodes class + hl-core.php.

### Phase 8: Front-End — Leader Experience
_Read docs: 10 (sections 5.1, 7.1–7.2, 13 Phase B)_

- [x] **8.1 My Cohort Page** — Created `class-hl-frontend-my-cohort.php` with `[hl_my_cohort]` shortcode. Auto-detects user scope (Center Leader → center, District Leader → district, staff → all). Cohort switcher for multi-enrollment users. Four tabs: Teams (card grid with mentor names, member count, avg completion %, progress bars, "View Team" link to team page), Staff (searchable table with name/email/team/role/completion), Reports (filterable completion table with institution/team/name client-side filters, expandable per-activity detail rows, CSV download via template_redirect handler with nonce + scope verification), Classrooms (table with center/age band/child count/teacher names, links to classroom page). Batch queries for age bands, child counts, and teacher names. CSS for tab show/hide, teams grid, search input, report filters, detail rows. JS for search, filter dropdowns, and detail toggle. Registered in shortcodes class + hl-core.php.
- [x] **8.2 Team Page** — Created `class-hl-frontend-team-page.php` with `[hl_team_page]` shortcode (`?id=X`). Dark gradient header with team name, center, cohort, member count, mentor names, avg completion stat. Two tabs: Team Members (searchable table with name/email/role badge/completion progress bar), Report (filterable completion table with per-activity detail expand and CSV export via template_redirect handler). Access control: staff, team members, center/district leaders whose scope includes the team's center. Breadcrumb back to My Cohort (teams tab).
- [x] **8.3 Classroom Page** — Created `class-hl-frontend-classroom-page.php` with `[hl_classroom_page]` shortcode (`?id=X`). Dark gradient header with classroom name, center, age band, teacher names. Searchable children table: name, DOB, age (computed from DOB with yr/mo display), gender (from metadata JSON). Access control: staff, assigned teachers, center/district leaders whose scope includes the classroom's center. Breadcrumb back to My Cohort (classrooms tab).

### Phase 9: Front-End — Staff/Admin CRM Directory
_Read docs: 10 (sections 6.1–6.5, 13 Phase C)_

- [x] **9.1 Districts Listing** — `[hl_districts_listing]` shortcode. Card grid: district name, # centers (via orgunit parent), # active cohorts (via cohort_center join). Click → District Page. Staff-only (manage_hl_core).
- [x] **9.2 District Page** — `[hl_district_page]` shortcode with `?id=X`. Dark gradient header with name + stat boxes. Sections: Active Cohorts (cohort rows with "Open Cohort" → Cohort Workspace filtered by district), Centers (card grid with leader names → Center Page), Overview stats. Access: staff + district leaders.
- [x] **9.3 Centers Listing** — `[hl_centers_listing]` shortcode. Card grid: center name, parent district, center leader names. Click → Center Page. Staff-only (manage_hl_core).
- [x] **9.4 Center Page** — `[hl_center_page]` shortcode with `?id=X`. Dark gradient header with center name, parent district link. Sections: Active Cohorts (with per-center participant count), Classrooms table (age band, children, teachers), Staff table (name, email, role, cohort). Access: staff + center leaders + district leaders of parent.
- [x] **9.5 Cohort Workspace** — `[hl_cohort_workspace]` shortcode with `?id=X&orgunit=Y`. Full command center. Tabs: Dashboard (avg completion %, participant counts by status, staff/center counts), Teams (card grid), Staff (searchable table), Reports (filterable table with per-activity detail expand + CSV export), Classrooms (table). Org unit filter dropdown for staff. Scope from URL or enrollment. CSV export handler.

### Phase 10: Coach Assignment + Coaching Enhancement
_Read docs: 10 (sections 13–15)_

- [x] **10.1 Coach Assignment Table** — Added `hl_coach_assignment` table (coach_user_id, scope_type enum center/team/enrollment, scope_id, cohort_id, effective_from, effective_to) to class-hl-installer.php with indexes (cohort_scope, coach_user_id, cohort_coach). Created `HL_Coach_Assignment_Service` with: `assign_coach()`, `get_coach_for_enrollment()` (most-specific-wins resolution: enrollment → team → center), `reassign_coach()` (closes old + creates new), `delete_assignment()`, `get_assignments_by_cohort()`, `get_all_assignments_by_cohort()`, `get_coach_roster()`, `get_sessions_for_enrollment()`. Audit logging on assign/reassign/delete. Schema revision bumped to 3.
- [x] **10.2 Coaching Session Schema Expansion** — Added session_title, meeting_url, session_status (enum: scheduled/attended/missed/cancelled/rescheduled), cancelled_at, rescheduled_from_session_id to hl_coaching_session. Migration maps attendance_status → session_status. Updated HL_Coaching_Service: transition_status() with terminal state validation, cancel_session(), reschedule_session() (marks old as rescheduled + creates linked new session), is_cancellation_allowed() (reads cohort.settings JSON), get_upcoming_sessions()/get_past_sessions(), get_sessions_for_participant(). Backward-compat mark_attendance() syncs both fields. Static render_status_badge() helper.
- [x] **10.3 Admin UI Updates** — Created `class-hl-admin-coach-assignments.php`: full CRUD list/create/delete page with cohort filter, scope name resolution (center/team/enrollment), active/ended status badges, nonce-protected actions. Updated `class-hl-admin-coaching.php`: replaced attendance_status radio with session_status dropdown (scheduled/attended/missed/cancelled with terminal-state read-only lock), added session_title input and meeting_url input, updated list view with title column and status badges via `HL_Coaching_Service::render_status_badge()`. Registered Coach Assignments submenu page in admin.
- [x] **10.4 My Coaching Page** — Created `class-hl-frontend-my-coaching.php` with `[hl_my_coaching]` shortcode. Coach info card (avatar, name, email via CoachAssignmentService resolution), enrollment switcher for multi-enrollment users. Upcoming Sessions with meeting link, inline reschedule form (datetime-local), cancel button (respects cohort `coaching_allow_cancellation` setting). Past Sessions with status badges (no actions). Schedule New Session form with auto-suggested title from next incomplete coaching activity, datetime-local picker, optional meeting URL. POST handlers for schedule/reschedule/cancel via template_redirect with nonce + ownership verification. Registered shortcode + assets.
- [x] **10.5 My Coach Widget** — Added coach info card to My Programs page (`class-hl-frontend-my-programs.php`): coach avatar, name, email (mailto link), "Schedule a Session" button linking to `[hl_my_coaching]` page. Falls back to "No coach assigned" message when no assignment found. Uses CoachAssignmentService resolution across all user enrollments.
- [x] **10.6 Program Page Coaching Enhancement** — Updated coaching_session_attendance activity cards in `class-hl-frontend-program-page.php`: shows "Upcoming on [date]" badge with meeting link if session is scheduled, "Schedule Session" button linking to My Coaching page if no session, "Missed" badge with "Reschedule" link if session was missed. Falls back to "Managed by your coach" when no coaching page exists.

### Phase 11: Sidebar Navigation & Listing Pages
_Read docs: 10 (sections 16-17)_

- [x] **11.1 Cohorts Listing** — `[hl_cohorts_listing]` shortcode. Card grid with search bar and status filter checkboxes. Shows cohort name, code, status badge, start/end dates, participant count, center count. Links to Cohort Workspace. Scope via HL_Scope_Service.
- [x] **11.2 Institutions Listing** — `[hl_institutions_listing]` shortcode. Combined districts + centers view with All/Districts/Centers toggle, search. Districts show center/cohort counts. Centers show parent district, leader names. Scope via HL_Scope_Service.
- [x] **11.3 Coaching Hub** — `[hl_coaching_hub]` shortcode. Session table with search, status filter, cohort filter. Shows title, participant, coach, date/time, status badge, meeting link. Scope via HL_Scope_Service.
- [x] **11.4 Classrooms Listing** — `[hl_classrooms_listing]` shortcode. Table with center and age band filters, teacher names, child counts. Links to Classroom Page. Scope via HL_Scope_Service.
- [x] **11.5 Learners** — `[hl_learners]` shortcode. Participant directory with pagination (25/page), search, cohort/center/role filters, completion progress bars, BB profile links. Scope via HL_Scope_Service.
- [x] **11.6 Pathways Listing** — `[hl_pathways_listing]` shortcode. Staff-only card grid with search and cohort filter. Shows pathway name, cohort, activity count, target roles, featured image, avg time.
- [x] **11.7 Reports Hub** — `[hl_reports_hub]` shortcode. Card grid: Completion Report, Coaching Report, Team Summary, Assessment Report (coming soon). Role-based card visibility.
- [x] **11.8 My Team** — `[hl_my_team]` shortcode. Auto-detects mentor's team. Single team → inline Team Page render. Multiple → selector cards. None → friendly message.
- [x] **11.9 Sidebar Menu Rebuild** — Rewrote BuddyBoss sidebar with 11 role-based menu items covering all new listing pages. Union of roles for multi-role users. Active page highlighting. Multi-hook strategy: profile dropdown (`buddyboss_theme_after_bb_profile_menu`), BuddyPanel left sidebar (`wp_nav_menu_items` filter), and JS fallback (`wp_footer`) for reliable rendering regardless of BuddyPanel menu state.
- [x] **11.10 Create WordPress Pages** — `wp hl-core create-pages` CLI command creates all 24 shortcode pages. Skips existing. `--force` to recreate. `--status=draft` for staging.

### Phase 12: MS365 Calendar Integration (Future)
_Read docs: 10 (section 18 Phase E)_

- [ ] **12.1 Azure AD App + OAuth** — Register Azure AD app, implement OAuth consent flow for coach accounts, store refresh tokens securely.
- [ ] **12.2 Availability Endpoint** — Read coach MS365 calendar via Graph API `/me/calendarView`, expose available slots to booking UI.
- [ ] **12.3 Booking Flow** — Replace date-time picker with MS365 availability calendar. Create session in HL Core + MS365 calendar event for both parties.
- [ ] **12.4 Sync** — Reschedule/cancel propagate to MS365 calendar events.

### Phase 13: Front-End — BuddyBoss Profile Tab (Future)
_Read docs: 10 (section 2.4)_

- [~] **13.1 BB Profile Tab** — DONE: Sidebar navigation menu (`HL_BuddyBoss_Integration`) rebuilt in 11.9 with 11 role-based menu items covering all listing pages (Cohorts, Institutions, Classrooms, Learners, Pathways, Coaching Hub, Reports, My Team, My Programs, My Coaching, My Cohort). Multi-role union, active page highlighting. TODO: Custom profile tab for coaches/admins (enrollment info, pathway progress, team assignment, coaching sessions, action buttons).

### Phase 14: Admin UX Improvements

- [x] **14.1 Pathway Clone/Template Feature** — Added `is_template` column to `hl_pathway` (schema revision 4). `HL_Pathway_Service::clone_pathway()` deep-clones pathway + activities + prereq groups/items + drip rules with activity ID remapping (fixed_date drip rules nulled for admin to set new dates). Admin Pathways page: "Clone to Cohort" form on detail view, "Save as Template" / "Remove from Templates" toggle, "Templates" tab on list view with count badge, "Start from Template" dropdown on new pathway form.
- [x] **14.2 Tabbed Cohort Editor** — Redesigned cohort edit page into 7-tab interface: Details (existing form), Centers (link/unlink with district + leader names), Pathways (list + clone from template shortcut), Teams (mentor names + member counts), Enrollments (paginated with roles/team/center/completion bars), Coaching (coach assignments + recent sessions with status badges), Classrooms (scoped to linked centers with child/teacher counts). New cohorts redirect to edit page after first save. Cohort breadcrumbs on Teams, Enrollments, Pathways, Coaching Sessions, and Coach Assignments admin pages when cohort filter is active.

### Phase 15: Architecture — Explicit Pathway Assignments + Cohort Groups
_Read docs: 04 (section 2.2), 08_

- [x] **15.1 Pathway Assignment Table + Cohort Group Table** — Added `hl_pathway_assignment` table (enrollment_id, pathway_id, assigned_by_user_id, assignment_type enum explicit/role_default, unique key enrollment_pathway). Added `hl_cohort_group` table (group_id, group_uuid, group_name, group_code, description, status). Added `cohort_group_id` column to `hl_cohort` with migration. Schema revision bumped to 5.
- [x] **15.2 Pathway Assignment Service** — Created `HL_Pathway_Assignment_Service` with: assign/unassign/bulk_assign/bulk_unassign, get_pathways_for_enrollment (explicit first, role-based fallback via target_roles↔enrollment roles matching), get_enrollments_for_pathway, get_unassigned_enrollments, sync_role_defaults (auto-assign for all unassigned enrollments in a cohort), enrollment_has_pathway boolean check, sync_enrollment_assigned_pathway (legacy column sync). Audit logging on assign/unassign.
- [x] **15.3 Frontend Updates** — My Programs uses PathwayAssignmentService for multi-pathway support with role-based and legacy fallback. Program Page uses service-based access check with legacy fallback. Cohorts Listing adds cohort group filter dropdown with JS filtering.
- [x] **15.4 Admin Pathway Assignments UI** — Pathway detail page: Assigned Enrollments section with count badge, "Sync Role Defaults" button, bulk assign multi-select form, quick assign dropdown, assignment table with type badges (Explicit/Role Default) and remove button. Cohort Enrollments tab: pathway names column via batch query.
- [x] **15.5 Cohort Groups Admin Page** — Created `class-hl-admin-cohort-groups.php`: full CRUD list/create/edit/delete with name, code, status, description. Linked cohorts table on edit page. Group dropdown added to cohort Details form. Group column in cohort list. Registered in admin menu.
- [x] **15.6 Reporting: Group Summary** — Added `get_group_summary()`, `get_group_aggregate()`, `export_group_summary_csv()` to ReportingService. Added group_summary_csv export handler to admin reporting. Reports Hub adds Program Group Report card (staff + district leaders).

### Phase 16: Cohort Editor — Inline Sub-Entity CRUD

- [x] **16.1 Pathways Class — Cohort Context** — Made 8 methods public (get_pathway, get_activity, format_external_ref, format_prereq_summary, render_pathway_form, render_pathway_detail, render_activity_form, render_activity_form_js). Added `$context` parameter to render methods (suppresses header/back-link, locks cohort dropdown, adds `_hl_cohort_context` hidden field). Added `get_cohort_redirect()` helper. Updated all 10 action handlers (save_pathway, save_activity, handle_delete_pathway, handle_delete_activity, handle_clone_pathway, handle_toggle_template, handle_assign_pathway, handle_unassign_pathway, handle_bulk_assign_pathway, handle_sync_role_defaults) to check for `_hl_cohort_context` / `cohort_context` and redirect back to cohort editor tab.
- [x] **16.2 Teams Class — Cohort Context** — Made 3 methods public (get_team, render_form, render_team_detail). Added `$context` parameter to render methods. Updated save handler and delete handler with cohort context redirect support. Cohort dropdown locked when in cohort context.
- [x] **16.3 Enrollments Class — Cohort Context** — Made 2 methods public (get_enrollment, render_form). Added `$context` parameter to render_form. Updated save and delete handlers with cohort context redirect support. Cohort dropdown locked when in cohort context.
- [x] **16.4 Cohort Editor Tab Routing** — Rewritten Pathways/Teams/Enrollments tabs as routers with `$sub` parameter. Added breadcrumb helper (`render_breadcrumb()`), cohort context helper (`get_cohort_context()`), and 15+ new message keys. Pathways tab: sub=new|edit|view|activity with pathway/activity validation. Teams tab: sub=new|edit|view with team validation. Enrollments tab: sub=new|edit with enrollment validation. List sub-views updated with cohort-context links (view/edit/delete). Added "Full Page View" link on each tab for cross-cohort browsing.

### Phase 17: Admin UX — Hierarchy & Navigation
- [x] **17.1 Org Units Hierarchical View** — Rewrote `class-hl-admin-orgunits.php`: flat list replaced with district-centric collapsible sections. 5 batch query methods (districts, centers grouped, classroom counts, active cohort counts per center, center leader names). District sections show collapse arrow, name/code, center + cohort counts, Edit/Add Center/Delete actions. Nested center tables with leader names, classroom counts, cohort counts, status badges. Unassigned centers section. Edit form enhancements: pre-fill type/parent from GET params, dynamic titles ("Add New District"/"Edit Center: X"), contextual breadcrumbs (center → parent district), district edit extras (child centers table + "Add Center" button), center edit extras (linked cohorts, classrooms with child counts, staff/leaders with roles and cohort). Inline CSS + vanilla JS for collapsible toggle with link/button click guard.

### Lower Priority (Future)
- [x] **ANY_OF and N_OF_M prerequisite types** — Rules engine `check_prerequisites()` rewritten to evaluate all_of, any_of, and n_of_m group types. Admin UI prereq group editor with type selector and activity multi-select. Seed demo includes examples of all three types. Frontend lock messages show type-specific wording with blocker activity names.
- [x] **Grace unlock override type** — `compute_availability()` now recognizes `grace_unlock` override type: bypasses prerequisite gate but NOT drip rules (mirrors `manual_unlock` which bypasses drip but NOT prereqs).
- [x] **Prerequisite cycle detection on save** — `validate_no_cycles()` builds dependency adjacency list scoped to pathway, runs iterative DFS with 3-color marking. Admin `save_activity()` validates before persisting prereqs; cycle detected → transient error message → redirect back with descriptive cycle path.
- [ ] Scope-based user creation for client leaders
- [ ] Import templates (downloadable CSV)

---

## Architecture

```
/hl-core/
  hl-core.php                    # Plugin bootstrap (singleton)
  /includes/
    class-hl-installer.php       # DB schema + activation
    /domain/                     # Entity models (8 classes)
    /domain/repositories/        # CRUD repositories (8 classes)
    /cli/                        # WP-CLI commands (seed-demo, seed-palm-beach, create-pages)
    /services/                   # Business logic (14+ services incl. HL_Scope_Service, HL_Pathway_Assignment_Service)
    /security/                   # Capabilities + authorization
    /integrations/               # LearnDash + JetFormBuilder + BuddyBoss (3 classes)
    /admin/                      # WP admin pages (15+ controllers incl. Cohort Groups)
    /frontend/                   # Shortcode renderers (25 pages + instrument renderer)
    /api/                        # REST API routes
    /utils/                      # DB, date, normalization helpers
  /data/                         # Private data files (gitignored)
  /assets/
    /css/                        # admin.css, admin-import-wizard.css, frontend.css
    /js/                         # admin-import-wizard.js, frontend.js
  /docs/                         # AI library (11 spec documents)
```

## Key Design Decisions
- **Hybrid forms architecture:** JetFormBuilder handles static questionnaire forms (teacher self-assessments, observations) that admins need to edit without a developer. Custom PHP handles dynamic forms (children assessments with per-child matrix) and admin CRUD (coaching sessions). See CLAUDE.md and doc 06 for details.
- Cohort roles stored on Enrollment, NOT WP user roles
- Custom database tables for all core domain data (no post_meta abuse)
- Teacher self-assessment and observation form responses stored in JFB Form Records; HL Core tracks only completion status and instance metadata
- Children assessment responses stored in `hl_children_assessment_childrow` (custom table) because the form is dynamically generated from classroom roster
- Enrollments are unique per (cohort_id, user_id)
- Children identity uses fingerprint hashing for import matching
- Rules engine evaluates prerequisites + drip independently per enrollment
- Child classroom assignments maintain current + history tables for audit trail
- Import wizard uses preview/commit pattern with row-level selection

## Plugin Dependencies
- **WordPress 6.0+**
- **PHP 7.4+**
- **JetFormBuilder** (required for teacher self-assessment and observation forms)
- **LearnDash** (required for course progress tracking)
- **BuddyBoss** (optional, for profile navigation integration)

## Activation
1. Ensure JetFormBuilder and LearnDash are installed and active
2. Go to WordPress Admin > Plugins
3. Activate "Housman Learning Core"
4. Tables are created automatically via `dbDelta()`
5. Coach role and capabilities are added
6. Navigate to **HL Core** menu in the admin sidebar
