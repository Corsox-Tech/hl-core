# Housman Learning Core (HL Core) Plugin

**Version:** 1.0.0
**Requires:** WordPress 6.0+, PHP 7.4+, LearnDash
**Status:** v1 complete — Phases 1-32 done. **Deployed to production** (March 2026). Architecture expansion in progress: Individual Enrollments (hl_individual_enrollment), Program Progress Matrix report — see B2E_MASTER_REFERENCE.md and Build Queue Phases 33-34.
(28 shortcode pages incl. dashboard + documentation, 12 admin pages, 39 DB tables, Cycle entity (hl_cycle) with cycle_type (program/course), paginated TSA, child assessment instruments with admin-customizable instructions + behavior key + display styles, teacher assessment visual editor + modern frontend design, separate PRE/POST teacher instruments, role-aware dashboard shortcode, instrument nuke protection with `--include-instruments` opt-in, in-site documentation system with CPT, glossary, search, cross-linking, K-2nd grade age group, instrument preview, **Coaching Hub** with Sessions+Assignments tabs, **Settings hub** with Imports+Audit Log+Doc Articles tabs, **Assessment Hub** with vertical sidebar nav (Teacher/Child Assessments + Child/Teacher Instruments), **Admin CSS design system** with modern card layout + status badges + design tokens, **BuddyBoss login fix** suppressing bpnoaccess error/shake)

## Overview

HL Core is the system-of-record plugin for Housman Learning Academy Cycle and Partnership management. It manages organizations, cycle enrollment, learning pathways, assessments, observations, coaching sessions, imports, and reporting.

## What's Implemented

### Database Schema (37 custom tables + 1 planned)
- **Org & Partnership/Cycle:** `hl_orgunit`, `hl_cycle` (with `is_control_group` flag + `cycle_type` column: program/course), `hl_cycle_school`, `hl_partnership` (program-level container)
- **Individual Enrollments (PLANNED):** `hl_individual_enrollment` (user_id, course_id, enrolled_at, expires_at, status) — for standalone course purchases
- **Participation:** `hl_enrollment`, `hl_team`, `hl_team_membership`
- **Classrooms:** `hl_classroom`, `hl_teaching_assignment`, `hl_child`, `hl_child_classroom_current`, `hl_child_classroom_history`
- **Learning Config:** `hl_pathway`, `hl_pathway_assignment`, `hl_component`, `hl_component_prereq_group`, `hl_component_prereq_item`, `hl_component_drip_rule`, `hl_component_override`
- **State/Rollups:** `hl_component_state`, `hl_completion_rollup`
- **Instruments:** `hl_instrument` (child assessment instruments with `instructions` + `behavior_key` + `styles_json` columns for admin-customizable content and display styles), `hl_teacher_assessment_instrument` (custom teacher self-assessment instruments with structured sections JSON + `instructions` + `styles_json` columns for per-instrument rich text instructions and admin-customizable display styles)
- **Assessments:** `hl_teacher_assessment_instance` (completion tracking + responses_json for custom instruments), `hl_teacher_assessment_response` (DEPRECATED), `hl_child_assessment_instance`, `hl_child_assessment_childrow`
- **Observations:** `hl_observation`, `hl_observation_response` (DEPRECATED), `hl_observation_attachment`
- **Coaching:** `hl_coaching_session`, `hl_coaching_session_observation`, `hl_coaching_attachment`
- **System:** `hl_import_run`, `hl_audit_log`

### Domain Models & Repositories
All 10 core entities have domain model classes with proper properties and repository classes with full CRUD:
- OrgUnit, Partnership, Enrollment, Team, Classroom, Child, Pathway, Component, Teacher_Assessment_Instrument, Cycle

### Services (Business Logic)
- **CycleService** - Cycle CRUD with validation
- **PartnershipService** - Partnership (program container) CRUD with validation
- **EnrollmentService** - Enrollment management with uniqueness checks
- **TeamService** - Team + membership management with constraints: hard enforcement of 1 team per enrollment per cycle, soft max-2-mentors-per-team (override-capable)
- **ClassroomService** - Full classroom CRUD, teaching assignment CRUD (create/update/delete with audit logging + auto-trigger child assessment instance generation), child classroom assignment/reassignment with history tracking
- **PathwayService** - Pathway + Component CRUD (cycle-aware)
- **RulesEngineService** - Full prerequisite + drip rule evaluation engine
  - ALL_OF, ANY_OF, and N_OF_M prerequisite group types
  - Fixed date and completion-delay drip rules
  - "Most restrictive wins" logic
  - Override handling (exempt, manual_unlock, grace_unlock)
  - Prerequisite cycle detection (DFS-based) with admin validation on save
- **AssessmentService** - Teacher/Child assessment queries + completion checks + child assessment activity_state updates + rollup triggering on submission + instance auto-generation for cycle teaching assignments
- **ObservationService** - Full observation CRUD: create draft observations, query by cycle/mentor/user with joined data, get observable teachers from team membership, teacher classroom lookup, observation component/form lookup
- **CoachingService** - Full coaching session CRUD (create/update/delete) + attendance marking with activity_state updates + rollup triggering + observation linking/unlinking + attachment management (WP Media)
- **AuditService** - Full audit logging to `hl_audit_log` table
- **ImportService** - CSV import with preview/commit workflow supporting four import types: participants (identity matching, WP user creation, enrollment), children (fingerprint-based matching, classroom assignment), classrooms (duplicate detection by school+name), teaching assignments (email-to-enrollment lookup). Header synonym mapping, error reporting with remediation suggestions
- **PathwayAssignmentService** - Explicit pathway-to-enrollment assignments: assign/unassign/bulk_assign/bulk_unassign, get_pathways_for_enrollment (explicit first, role-based fallback), get_enrollments_for_pathway, get_unassigned_enrollments, sync_role_defaults (auto-assign by target_roles matching), enrollment_has_pathway check, legacy assigned_pathway_id sync
- **ReportingService** - Completion rollup engine (weighted average, upsert to hl_completion_rollup, live LD fallback), cycle batch recompute, on-the-fly computation, activity-level detail query, cycle summary, scope-filtered participant report (cycle/school/district/team/role filters), cycle activity detail (batch per-enrollment per-component), school summary (grouped by school), team summary (grouped by team), group summary (cross-cycle aggregation), group aggregate metrics, **program vs control group comparison** (per-section/per-item mean aggregation with Cohen's d effect size), CSV exports (participant completion with per-component columns, school summary, team summary, group summary, comparison CSV)
- **CoachAssignmentService** - Coach scope assignment CRUD (school/team/enrollment level)
- **ChildSnapshotService** - Freeze child age groups per cycle for assessment consistency
- **ScopeService** - Role-based data filtering (see Scope Service section below)

### Admin Pages (WP Admin UI)
Full CRUD admin pages with WordPress-styled tables and forms:
- **Cycles** - List, create, edit/delete cycles. Partnership column in list. Control group badge (purple) in list and editor header. **Tabbed Editor:** 7-tab interface for existing cycles (Details with partnership dropdown + control group checkbox, Schools, Pathways, Teams, Enrollments with pathway column, Coaching, Classrooms). Control group cycles auto-hide Coaching and Teams tabs. **Inline sub-entity CRUD:** Pathways/Teams/Enrollments tabs now support `&sub=new|edit|view|component` for full inline CRUD — forms render inside the cycle editor with breadcrumbs, cycle-locked dropdown, and save/delete redirects back to the correct tab. Standalone pages still work independently. Schools tab with link/unlink. Enrollments tab with completion bars + pagination + pathway names + inline edit/enroll. Coaching tab with assignments + recent sessions. Classrooms tab scoped to linked schools.
- **Partnerships** - Full CRUD for program-level partnership containers. Create/edit/delete partnerships with name, code, status, description. Linked cycles display on edit page. Cycles assigned via partnership dropdown on cycle Details form.
- **Org Units** - Hierarchical district-centric view: districts as collapsible sections with nested school tables showing leader names, classroom/cycle counts. "Add District" and "Add School" header buttons. Edit form enhancements: dynamic titles, contextual breadcrumbs, district edit shows child schools table with "Add School" button, school edit shows linked cycles/classrooms/staff tables. Pre-fill support for type and parent from URL params.
- **Enrollments** - Enroll users in cycles with role assignment, cycle filter
- **Pathways & Components** - Configure pathways per cycle, add components with type-specific dropdowns (HL instrument selector, LearnDash course selector), auto-built external_ref JSON, prerequisite group editor (all_of/any_of/n_of_m with cycle detection), prereq summary column in component list, drip rule UI (fixed release date + delay-after-component with base_component selector and delay_days). **Clone/Template:** Clone pathway to any cycle (copies components, prereqs, drip rules with ID remapping). Save as Template / Templates tab. Start from Template on new pathway form. **Pathway Assignments:** Assigned Enrollments section on pathway detail with count badge, sync role defaults button, bulk assign multi-select, quick assign dropdown, assignment table with type badges (Explicit/Role Default) and remove button.
- **Teams** - Create teams within cycles/schools, view team members
- **Classrooms** - Full CRUD with detail view: teaching assignments (cycle-scoped add/remove) + children roster (assign/reassign/remove with history)
- **Instruments** - Full CRUD for child assessment instruments: question editor (add/edit/remove with type, prompt, allowed_values, required flag), version management with edit warnings when instances exist. Types: children_infant, children_toddler, children_preschool, children_k2 (varchar(50) column supports future types). Mixed-age classrooms use per-child age-group instruments automatically on the front-end. **Child instrument admin** includes wp_editor for custom instructions + fixed 5-row behavior key table (label, frequency, description) — blank fields fall back to hard-coded defaults on the frontend. **Teacher Assessment Visual Editor:** structured section builder with collapsible accordion panels, scale label panels (likert ordered labels or numeric low/high anchors), per-item rich text (B/I/U via contenteditable), wp_editor for instructions, dynamic add/remove for sections/items/scales/labels — replaces raw JSON textareas. **Display Styles panel** (both child and teacher forms): collapsible admin section with per-element font-size dropdown (12-24px) and color picker for instructions, section titles, section descriptions, items, scale labels, and behavior key. Stored as `styles_json` on instrument rows. Renderers emit CSS overrides from `styles_json` values; empty/missing keys fall back to built-in defaults.
- **Coaching Hub** - Tabbed admin page merging Sessions + Assignments:
  - **Sessions tab** — Full CRUD with cycle filter, mentor/coach selectors, session title, meeting URL, date/time, session status dropdown (scheduled/attended/missed/cancelled with terminal-state lock), rich-text notes (wp_editor), observation linking from submitted observations, WP Media attachments
  - **Assignments tab** — Full CRUD for coach-to-scope assignments (school/team/enrollment) with cycle filter, scope name resolution, active/ended status badges, effective date management
- **Settings** - Tabbed admin hub grouping utilities:
  - **Imports tab** — AJAX-based 3-step wizard (Upload > Preview & Select > Results) for CSV import
  - **Audit Log tab** — Searchable audit log viewer with cycle and action type filters, pagination
  - **Doc Articles tab** — Links to WP native CPT editor, add new article, and category manager for the in-site documentation system

- **Assessment Hub** - Unified assessment management page with vertical sidebar navigation:
  - **Teacher Assessments** — Staff assessment viewer/exporter with list/detail/CSV, summary metric cards
  - **Child Assessments** — List/detail/instance generation from teaching assignments/CSV, summary metric cards
  - **Child Instruments** — Full CRUD for child assessment instruments (question editor, versions, display styles)
  - **Teacher Instruments** — Visual section editor for teacher self-assessment instruments
  - Assessment phase section headers (PRE/POST grouping with counts), clickable teacher names (link to WP user edit), dev-only switch-to-user feature (gated by `HL_DEV_TOOLS` constant). **Dual CSV exports per type:** "Export Completion CSV" (metadata only) and "Export Responses CSV" (full scored response data). Teacher responses export reads `responses_json` from instances with instrument-derived column headers (section: item_key). Child responses export outputs one row per child with answer columns, excluding skipped children.
- **Reports** - Full reporting dashboard: scope-based filtering (cycle/school/district/team/role + partnership), summary cards, school/team summary tables, participant completion table with progress bars, enrollment detail drill-down with component-level status, **program vs control group comparison section** (per-section/per-item tables with color-coded change values when partnership filter contains both program and control cycles), CSV exports (completion, school summary, team summary, teacher assessments, child assessments, comparison CSV with Cohen's d), rollup recompute action

**Admin menu:** Top-level "HL Core" menu first submenu item renamed from duplicate "HL Core" to "Cycles" (matches the parent page). Menu order: Cycles, Partnerships, Org Units, Enrollments, Pathways, Teams, Classrooms, Coaching Hub, Assessments, Reports, Settings.

**Admin fix:** All admin pages use `admin_init` dispatcher for POST saves and GET deletes (redirect-before-output pattern), preventing blank pages after form submissions.

### Front-End Pages (Shortcodes)
- **My Progress** `[hl_my_progress]` - Participant's own cycle progress with pathway/component completion, progress rings, contextual action links for observations/child assessment/coaching components
- **Team Progress** `[hl_team_progress]` - Mentor's team view with member cards, expandable component details
- **Cycle Dashboard** `[hl_cycle_dashboard]` - Leader/staff cycle overview with school filter, participant completion table
- **Child Assessment** `[hl_child_assessment]` - Teacher's child assessment workflow: instance list, branded assessment form (Housman logo, teacher/school/classroom info, instructions, age-band-specific Key & Example Behavior table, transposed Likert matrix with Never→Almost Always labels mapped from 0-4 numeric values), draft save, final submit, branded read-only submitted summary with answer dots
- **Observations** `[hl_observations]` - Mentor observation workflow: observation list, new observation (select teacher from team + optional classroom), submitted summary view
- **My Programs** `[hl_my_programs]` - Participant's program cards grid: featured image, program name, cycle name, completion %, status badge (Not Started/In Progress/Completed), "Continue"/"Start" button linking to Program Page. Uses PathwayAssignmentService for explicit/role-based pathway resolution with legacy fallback. Auto-discovers `[hl_program_page]` page URL. Empty state for no enrollments. My Coach widget: avatar, name, email, "Schedule a Session" button via CoachAssignmentService resolution.
- **Program Page** `[hl_program_page]` - Single program detail page (via `?id=X&enrollment=Y`): hero image, pathway name, description, cycle name, progress ring, details panel (avg time, expiration, status), objectives, syllabus link, component cards with per-component status/progress/actions. Component actions route to Component Page, LD course permalink, or child assessment page. Breadcrumb back to My Programs.
- **Component Page** `[hl_component_page]` - Single component page (via `?id=X&enrollment=Y`): renders form for self-assessments/observations, links to child assessment page, redirects to LD course, shows managed-by-coach notice for coaching. Locked/completed guards with reason display. Breadcrumb back to Program Page.
- **My Cycle** `[hl_my_cycle]` - Auto-scoped cycle workspace for School Leaders and District Leaders. Cycle switcher for multi-enrollment users. Scope auto-detection (school_leader → school, district_leader → district, staff → all). Four tabs: Teams (cards with mentor names, member count, avg completion, progress bar, "View Team" link), Staff (searchable table with name/email/team/role/completion), Reports (filterable completion table with institution/team/name filters, expandable per-component detail rows, CSV download), Classrooms (table with school/age band/child count/teacher names, links to classroom page).
- **Team Page** `[hl_team_page]` - Team detail page (via `?id=X`): dark gradient header with team name, school, cycle, member count, mentor names, avg completion metric. Two tabs: Team Members (searchable table with name, email, role badge, completion progress bar), Report (completion report table with per-component detail expansion and CSV export). Access control: staff, team members, school/district leaders with matching scope. Breadcrumb back to My Cycle.
- **Classroom Page** `[hl_classroom_page]` - Classroom detail page (via `?id=X`): dark gradient header with classroom name, school name, age band, teacher names. Searchable children table with name, date of birth, computed age (years/months), gender (from metadata JSON). Access control: staff, assigned teachers, school/district leaders with matching scope. Breadcrumb back to My Cycle.
- **Districts Listing** `[hl_districts_listing]` - Staff CRM directory card grid of all school districts: district name, # schools, # active cycles (via cycle_school join). Links to District Page. Staff-only access (manage_hl_core).
- **District Page** `[hl_district_page]` - District detail page (via `?id=X`): dark gradient header with district name and stat boxes (schools, participants). Sections: Active Cycles (rows with name, status badge, dates, participant count, "Open Cycle" link to Cycle Workspace filtered by district), Schools (card grid with leader names, "View School" link), Overview stats. Access: staff + district leaders enrolled in that district. Breadcrumb to Districts Listing.
- **Schools Listing** `[hl_schools_listing]` - Staff CRM card grid of all schools: school name, parent district, leader names. Links to School Page. Staff-only access (manage_hl_core).
- **School Page** `[hl_school_page]` - School detail page (via `?id=X`): dark gradient header with school name, parent district link. Sections: Active Cycles (rows with participant count at this school, "Open Cycle" link to Cycle Workspace filtered by school), Classrooms table (age band, children, teachers), Staff table (name, email, role, cycle). Access: staff + school leaders + district leaders of parent. Breadcrumb to parent district or Schools Listing.
- **Cycle Workspace** `[hl_cycle_workspace]` - Full cycle command center (via `?id=X&orgunit=Y`). My Cycle header with scope indicator and org unit filter dropdown for staff. Five tabs: Dashboard (avg completion %, total participants, completed/in-progress/not-started counts, teacher/mentor/school counts), Teams (card grid reusing team card pattern), Staff (searchable table), Reports (filterable completion table with per-component detail expansion and CSV export), Classrooms (table). Control group cycles auto-hide Teams tab. Scope from URL orgunit parameter or enrollment roles. Access: staff + enrolled leaders. Breadcrumb to source district/school page.
- **My Coaching** `[hl_my_coaching]` - Participant coaching page: coach info card (avatar, name, email) via CoachAssignmentService resolution, enrollment switcher, upcoming sessions with meeting link/reschedule/cancel, past sessions with status badges, schedule new session form with auto-suggested title from next coaching component
- **Cycles Listing** `[hl_cycles_listing]` - Scope-filtered cycle card grid with search bar, partnership filter dropdown, and status filter checkboxes (Active/Future default, Paused, Archived). Shows cycle name, code, status badge, start/end dates, participant count, school count. Links to Cycle Workspace. Scope: admin all, coach assigned cycles, leaders enrolled cycles.
- **Institutions Listing** `[hl_institutions_listing]` - Combined districts + schools view with All/Districts/Schools toggle, search bar. Districts show school count and active cycle count. Schools show parent district and leader names. Scope: admin all, leaders see own district/school scope.
- **Coaching Hub** `[hl_coaching_hub]` - Front-end coaching session table with search (participant/coach/title), status filter, cycle filter. Shows title, participant, coach, date/time, status badge, meeting link. Scope: admin all, coach assigned cycles, enrolled users own sessions.
- **Classrooms Listing** `[hl_classrooms_listing]` - Searchable classroom table with school and age band filters. Shows classroom name, school, age band badge, child count, teacher names. Links to Classroom Page. Scope: admin all, leaders by school, teachers own assignments.
- **Learners** `[hl_learners]` - Participant directory with pagination (25/page), search by name/email, cycle/school/role filters. Shows name (BuddyBoss profile link), email, roles, school, cycle, completion progress bar. Scope: admin all, coach assigned, leaders by scope, mentors team only.
- **Pathways Listing** `[hl_pathways_listing]` - Staff-only pathway browser with search and cycle filter. Card grid: pathway name, cycle, component count, target roles, featured image, avg completion time. Scope: admin/coach only.
- **Reports Hub** `[hl_reports_hub]` - Card grid of available report types: Completion Report (links to workspace/my-cycle reports tab), Coaching Report (links to coaching hub), Team Summary (links to workspace/my-cycle teams tab), Program Group Report (cross-cycle, coming soon), Assessment Report (coming soon). Role-based card visibility.
- **My Team** `[hl_my_team]` - Auto-detects mentor's team via team_membership. Single team: renders Team Page inline. Multiple teams: team selector card grid with name, school, member count, cycle. No teams: friendly message.
- **Dashboard** `[hl_dashboard]` - Role-aware LMS home page replacing Elementor dashboard. Detects user roles from `HL_Scope_Service` + `hl_enrollment.roles` JSON + `hl_cycle.is_control_group`. Welcome banner with time-based greeting and avatar. Participant section: My Programs + My Classrooms for all enrolled; My Coaching for program cycles (hidden for control-group-only users); My Team + Coaching Hub for mentors; My Cycle for leaders. Staff/admin Administration section: Cycles, Institutions, Learners, Pathways, Coaching Hub, Reports. Cards silently hide if target page doesn't exist.
- **Documentation** `[hl_docs]` + `[hl_doc_link]` - In-site documentation browser powered by `hl_doc` CPT and `hl_doc_category` taxonomy. Landing page with category card grid + search bar. Article detail view with left sidebar navigation, auto-generated TOC from h2/h3 headings, scroll spy, prev/next navigation. Glossary page with alphabetized letter nav and definition list. `[hl_doc_link slug="..." text="..."]` inline cross-reference shortcode for use inside article content. Sidebar search filtering, mobile-responsive with slide-out sidebar toggle. Admin creates/edits articles via WP Admin > HL Core > Doc Articles. BuddyBoss sidebar link visible to all enrolled users + staff. `wp hl-core seed-docs` seeds ~22 articles + ~15 glossary terms with real useful content and cross-links.

### Scope Service
- **HL_Scope_Service** — Shared static helper for role-based scope filtering across all listing pages. Detects user role (admin/coach/leader/mentor/teacher), computes visible cycle_ids, school_ids, district_ids, team_ids, enrollment_ids. Admin sees all (empty arrays = no restriction). Coach filtered by hl_coach_assignment + own enrollments. District leader expands to all schools in district. Static cache per user_id per request. Convenience helpers: can_view_cycle(), can_view_school(), has_role(), filter_by_ids().

### Security
- Custom `manage_hl_core` capability
- Coach WP role created on activation
- `HL_Security::assert_can()` for server-side checks
- Assessment response privacy (staff-only access)
- Import wizard: per-action nonces, capability checks, run ownership verification, file validation (extension + MIME + size)

### WP-CLI Commands
- **`wp hl-core seed-demo`** — Creates a full realistic demo dataset: 1 district, 2 centers, 1 active cycle, 4 classrooms, 3 instruments, 16 users (10 teachers, 2 mentors, 2 center leaders, 1 district leader, 1 coach), 15 enrollments, 2 teams with memberships, 10 teaching assignments, ~26 children, 2 pathways (teacher: 5 components, mentor: 2 components), prerequisite and drip rules, partial activity completion states, computed rollups, 3 coach assignments (2 center-level + 1 team-level), and 6 coaching sessions (attended, scheduled, missed, rescheduled, cancelled)
- **`wp hl-core seed-demo --clean`** — Removes all demo data (users, cycle, org units, instruments, children, coach assignments, coaching sessions, and all dependent records) identified by cycle code `DEMO-2026` and `demo-*@example.com` user emails
- **`wp hl-core seed-palm-beach`** — Seeds realistic demo data from the ELC Palm Beach County program: 1 district, 12 centers (3 Head Start + 1 learning center + 8 FCCHs), 1 active cycle, 29 classrooms, 3 instruments, 57 users (47 real teachers with actual names/emails, 4 center leaders, 4 mentors, 1 district leader, 1 coach), 56 enrollments (8 FCCH teachers dual-role as center leaders), 4 teams (WPB Alpha/Beta, Jupiter, South Bay), 47 teaching assignments, 286 real children (with DOBs, gender, ethnicity metadata), 2 pathways (teacher: 5 components, mentor: 2 components), prerequisite and drip rules, partial activity states, computed rollups, 4 coach assignments (3 center-level + 1 team-level), 5 coaching sessions, **1 partnership (B2E Program Evaluation)**, **1 Lutheran Services Florida control cycle (LSF-CTRL-2026)** with 6 control participants and submitted PRE assessments for comparison reporting
- **`wp hl-core seed-palm-beach --clean`** — Removes all Palm Beach data (users, cycle, org units, instruments, children, classrooms, coach assignments, coaching sessions, control cycle, partnership, and all dependent records) identified by cycle code `ELC-PB-2026`/`LSF-CTRL-2026` and `_hl_palm_beach_seed` user meta
- **`wp hl-core seed-lutheran`** — Seeds Lutheran Services Florida control group data: 1 district, 11 centers, 1 control cycle (LUTHERAN_CONTROL_2026) in B2E Evaluation partnership, 29 classrooms with age band normalization, 47 WP users (teachers), 47 enrollments, 47 teaching assignments, 286 children with DOBs/gender/ethnicity metadata, assessment-only pathway (4 components: TSA Pre, CA Pre, TSA Post, CA Post), B2E teacher instrument, 4 child assessment instruments (infant, toddler, preschool, k2), 94 teacher + 94 child assessment instances with proper instrument/phase/component linkage, 188 activity states, POST components time-gated via drip rules (fixed_date 2026-05-05)
- **`wp hl-core seed-lutheran --clean`** — Removes all Lutheran data in reverse dependency order (activity states, assessment instances, children, teaching assignments, classrooms, enrollments, users, pathways, instruments, cycle, org units) identified by cycle code `LUTHERAN_CONTROL_2026` and `_hl_lutheran_seed` user meta
- **`wp hl-core provision-lutheran [--dry-run]`** — **Production-safe** Lutheran provisioning. Finds existing WP users by email (never creates/deletes users), creates HL Core entities (district, schools, cycle, partnership, classrooms, enrollments, teaching assignments, children, pathway + 4 components, instruments, assessment instances, activity states, pathway assignments) only if they don't already exist. Fully idempotent — safe to run multiple times. No `--clean` flag, no safety gate. `--dry-run` shows what would happen without writing. Reports missing users by name + email.
- **`wp hl-core nuke --confirm="DELETE ALL DATA" [--include-instruments]`** — **DESTRUCTIVE: Deletes ALL HL Core data.** Dynamically discovers all `hl_*` tables via `SHOW TABLES LIKE`, shows per-table row counts before truncating, removes seeded WP users (but protects user ID 1 and the current CLI user), resets auto-increment, clears HL Core transients (`_transient_hl_%`). Safety gate: only runs on sites with URL containing `staging.academy.housmanlearning.com`, `test.academy.housmanlearning.com`, or `.local`. **By default, skips `hl_instrument` and `hl_teacher_assessment_instrument` tables** to preserve admin customizations (instructions, questions, styles). Pass `--include-instruments` to truncate instrument tables as well.
- **`wp hl-core seed-docs [--clean]`** — Seeds ~22 documentation articles across 7 categories + ~15 glossary terms for the in-site documentation system. Uses `hl_doc` CPT and `hl_doc_category` taxonomy. Skip-if-exists by slug. `--clean` deletes all existing doc articles before seeding.
- **`wp hl-core create-pages`** — Creates all 28 WordPress pages for HL Core shortcodes (personal, directory, hub, detail, assessment, dashboard, and documentation pages). Skips pages that already exist. `--force` to recreate. `--status=draft` for staging.
- **`wp hl-core import-elcpb-children [--dry-run] [--clean]`** — Imports ELCPB Year 1 child assessment data from WPForms entries. Creates teaching assignments (teacher→classroom from WPForms user_id), children (261 with DOBs from form data), child instruments (3 age groups), and assessment instances + childrows (45 instances, 494 rows). Idempotent — skips existing records. `--dry-run` to preview. `--clean` removes all ELCPB child data. Requires `import-elcpb` to have run first.
- **`wp hl-core setup-elcpb-y2 [--clean]`** — Creates ELCPB Year 2 (2026) cycle and all 8 pathways with components. Cycle `ELCPB-Y2-2026` linked to Partnership `ELCPB-B2E-2025`, dates 2026-03-30 to 2026-09-12, same 6 schools as Year 1. Pathways: Teacher Phase 1 (15 cmp), Teacher Phase 2 (14), Mentor Phase 1 (9), Mentor Phase 2 (16), Mentor Transition (16), Mentor Completion (2), Streamlined Phase 1 (9), Streamlined Phase 2 (8). Phase 2 pathways include observation and coaching components. `--clean` removes Year 2 cycle + pathways + components. Requires `import-elcpb` to have run first.

### REST API
- `GET /wp-json/hl-core/v1/cycles`
- `GET /wp-json/hl-core/v1/cycles/{id}`
- `GET /wp-json/hl-core/v1/enrollments?cycle_id=X`
- `GET /wp-json/hl-core/v1/orgunits?type=center`
- `GET /wp-json/hl-core/v1/pathways?cycle_id=X`
- `GET /wp-json/hl-core/v1/teams?cycle_id=X`

### LearnDash Integration
- Course progress reading via `learndash_course_progress()`
- Course completion event hook (`learndash_course_completed`) — fully wired: finds enrollments, matches components by course_id, marks complete, triggers rollups, audit logs
- Batch progress reading for reporting

### BuddyBoss Integration
- **HL_BuddyBoss_Integration** service (`includes/integrations/class-hl-buddyboss-integration.php`)
- Multi-hook injection strategy for reliable menu rendering:
  1. **Profile Dropdown** — `buddyboss_theme_after_bb_profile_menu` (last hook in header-profile-menu.php)
  2. **BuddyPanel Left Sidebar** — `wp_nav_menu_items` filter on `buddypanel-loggedin` location, appends section divider + items using native BB CSS classes
  3. **JS Fallback** — `wp_footer` injects into BuddyPanel DOM and/or profile dropdown if PHP hooks did not fire (covers empty BuddyPanel menu or custom profile dropdown override)
- 11 menu items with role-based visibility (matches doc 10 section 16):
  - **Personal (require active enrollment):** My Programs, My Coaching (any enrollment), My Team (mentor only), My Cycle (leader or mentor)
  - **Directories:** Cycles, Institutions (staff or leader), Classrooms (staff, leader, or teacher), Learners (staff, leader, or mentor)
  - **Staff tools:** Pathways (staff only), Coaching Hub (staff or mentor), Reports (staff or leader)
- Staff WITHOUT enrollment see only directory/management pages; staff WITH enrollment see both personal and management pages
- Multi-role users see union of all role menus
- Active page highlighting via `current` / `current-menu-item` CSS class
- Role detection from `hl_enrollment.roles` JSON (not WP roles) with static caching
- Menu items built once per request via `get_menu_items_for_current_user()` (static cache shared across all three hooks)
- Shortcode-based page URL discovery with static caching
- Graceful no-op when BuddyBoss is not active
- **Login redirect** (priority 999) — HL-enrolled users and staff redirected to HL Dashboard on login
- **BB Dashboard redirect** — `template_redirect` hook redirects enrolled users from BuddyBoss member dashboard (`/dashboard/`) to HL Dashboard (`/dashboard-3/`) since BB Dashboard is an Elementor page that doesn't render the `[hl_dashboard]` shortcode
- **Collapsed sidebar CSS fix** — Overrides BuddyBoss theme CSS that hides all `<span>` elements in collapsed mode (`body:not(.buddypanel-open) ... opacity:0; visibility:hidden`), keeping HL dashicon icons visible. Section headers and badges hidden in collapsed mode.
- **Login page fix** — Suppresses BuddyBoss `bpnoaccess` error message and shake animation on wp-login.php via `bp_wp_login_error`, `shake_error_codes`, and `login_message` filters. Shows friendly "Welcome to Housman Learning Academy. Please log in to continue." message instead of red error styling.
- **Page header with docs link** — All HL Core admin pages use `HL_Admin::render_page_header()` which renders the page title with an inline "Docs" link to `/documentation/`. Replaces the old `in_admin_header` hook approach that was hidden behind the Screen Options drawer.

### JetFormBuilder Integration (Legacy — pending removal)
- **HL_JFB_Integration** service (`includes/integrations/class-hl-jfb-integration.php`)
- Hook listener for `hl_core_form_submitted` custom action — processes observation submissions
- Front-end form rendering helper with hidden field pre-population
- Admin notice when JetFormBuilder is inactive
- Available forms query for admin dropdowns

---

## Build Queue
See `STATUS.md` for the current build queue and task tracking.

---

## Architecture

```
/hl-core/
  hl-core.php                    # Plugin bootstrap (singleton)
  /includes/
    class-hl-installer.php       # DB schema + activation
    /domain/                     # Entity models (10 classes: OrgUnit, Partnership, Cycle, Enrollment, Team, Classroom, Child, Pathway, Component, Teacher_Assessment_Instrument)
    /domain/repositories/        # CRUD repositories (9 classes: OrgUnit, Partnership, Cycle, Enrollment, Team, Classroom, Child, Pathway, Component)
    /cli/                        # WP-CLI commands (seed-demo, seed-lutheran, seed-palm-beach, nuke, create-pages, seed-docs, provision-lutheran, import-elcpb, import-elcpb-children, setup-elcpb-y2) + data files
    /services/                   # Business logic (17 services incl. HL_Scope_Service, HL_Pathway_Assignment_Service, HL_Cycle_Service, HL_Partnership_Service)
    /security/                   # Capabilities + authorization
    /integrations/               # LearnDash + JetFormBuilder (legacy) + BuddyBoss (3 classes)
    /admin/                      # WP admin pages (17 controllers incl. Partnerships, Cycles, Assessment Hub)
    /frontend/                   # Shortcode renderers (28 pages incl. dashboard + documentation + instrument renderer + teacher assessment renderer)
    /api/                        # REST API routes
    /utils/                      # DB, date, normalization, age group helpers + label remap (legacy)
  /data/                         # Private data files (gitignored)
  /assets/
    /css/                        # admin.css, admin-import-wizard.css, admin-teacher-editor.css, frontend.css, frontend-docs.css
    /js/                         # admin-import-wizard.js, admin-teacher-editor.js, frontend.js, frontend-docs.js
  /docs/                         # AI library (11 spec documents + B2E_MASTER_REFERENCE.md + DOC_UPDATE_INSTRUCTIONS.md)
```

## Key Design Decisions
- **Custom-first assessment architecture:** Teacher Self-Assessments use a custom PHP instrument system (structured JSON definitions in `hl_teacher_assessment_instrument`, responses in `hl_teacher_assessment_instance.responses_json`) because the POST version requires a dual-column retrospective format and structured data is needed for research comparison. Child Assessments use custom PHP (dynamic per-child matrix from classroom roster). Observations are the only remaining JFB-powered form type (legacy — admins need to customize observation questions without a developer). Coaching Sessions are custom PHP admin CRUD. See CLAUDE.md and doc 06 for details.
- Cycle roles stored on Enrollment, NOT WP user roles
- Custom database tables for all core domain data (no post_meta abuse)
- Teacher self-assessment responses stored in `hl_teacher_assessment_instance.responses_json` (custom system); HL Core tracks completion status, instance metadata, and structured response data for research comparison
- Child assessment responses stored in `hl_child_assessment_childrow` (custom table) with per-child frozen_age_group, instrument_id, and status columns. Form dynamically generated from classroom roster.
- **Frozen age groups (hl_child_cycle_snapshot):** Each child's age group is calculated from DOB and frozen per-cycle at the time children are associated with a cycle. This ensures PRE and POST assessments use the same instrument/question per child for research consistency. Mixed-age classrooms render multiple age-group sections in a single form. `HL_Child_Snapshot_Service` manages freeze logic. `HL_Age_Group_Helper` provides age-range definitions (infant <12mo, toddler 12-35mo, preschool 36-59mo, k2 60+mo).
- Enrollments are unique per (cycle_id, user_id)
- Children identity uses fingerprint hashing for import matching
- Rules engine evaluates prerequisites + drip independently per enrollment
- Child classroom assignments maintain current + history tables for audit trail
- Import wizard uses preview/commit pattern with row-level selection
- **Control group research design:** `is_control_group` flag on `hl_cycle` drives UI adaptations (hidden coaching/teams tabs) and enables program-vs-control comparison reporting at the Partnership level. Cohen's d effect size for measuring program effectiveness. Comparison uses `responses_json` from `hl_teacher_assessment_instance`. Primary analysis happens in Stata from CSV exports — control group is an independent research asset, not tied to a specific program Cycle.
- **Cycle types:** `program` for full B2E management, `course` for simple institutional course access with auto-generated Pathway/Component. See B2E_MASTER_REFERENCE.md §3.4.
- **Individual Enrollments (PLANNED):** `hl_individual_enrollment` for standalone course purchases by individuals, with per-person expiration dates. See B2E_MASTER_REFERENCE.md §5.

## Plugin Dependencies
- **WordPress 6.0+**
- **PHP 7.4+**
- **LearnDash** (required for course progress tracking)
- **BuddyBoss** (optional, for profile navigation integration)
- ~~JetFormBuilder~~ — legacy integration still loaded; pending full removal. Do not add new JFB code.

## Activation
1. Ensure LearnDash is installed and active
2. Go to WordPress Admin > Plugins
3. Activate "Housman Learning Core"
4. Tables are created automatically via `dbDelta()`
5. Coach role and capabilities are added
6. Navigate to **HL Core** menu in the admin sidebar
