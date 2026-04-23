# Housman Learning Core Plugin — AI Library
## File: 10_FRONTEND_PAGES_NAVIGATION_UX.md
Version: 5.1
Last Updated: 2026-03-29
Timezone: America/Bogota

---

# 0) Purpose

This document specifies the front-end (participant-facing) pages that HL Core must provide. These are WordPress pages rendered via shortcodes, visible to enrolled participants and staff based on role and scope.

Admin management pages (Partnerships CRUD, Org Units CRUD, Pathway configuration, Import wizard, Audit log, etc.) live in WP Admin and are NOT covered here.

**Key design principle:** The front-end has TWO separate concerns:

1. **Cycle Workspace (operational, time-bound):** Where daily work happens — pathways, progress, reports, teams. Everything scoped to ONE cycle. This is what teachers, mentors, and leaders use.
2. **Organizational Directory (CRM-like, permanent):** Districts and Schools exist across cycles. This is the admin/coach view for managing the organization and navigating between cycles.

---

# 1) Front-End Label Mapping

The internal data model uses technical terms. The front-end uses participant-friendly labels:

| Internal Model | Front-End Label | Notes |
|----------------|----------------|-------|
| Pathway | Program | "My Programs" page, "Program" in cards/headers |
| Component | Activity or Step | Contextual — "Activity" in reports, "Step" acceptable in pathway view |
| Cycle | Cycle | The time-bounded run — used in workspace and listing pages |
| Partnership | Partnership | The container entity — used for cross-cycle aggregation and comparison |
| OrgUnit (district) | School District | |
| OrgUnit (school) | Institution / School | Use whichever the client prefers; currently "Institution" in production |
| Enrollment | (not shown) | Internal concept, never surfaced to participants |

---

# 2) Page Architecture Overview

## 2.0 Dashboard (LMS Home)

```
Dashboard (role-aware home page — replaces Elementor LMS home)
  ├─ Welcome banner (all users)
  ├─ Participant cards: My Programs, My Classrooms, My Coaching*, My Team*, My Cycle*
  └─ Staff Administration cards: Cycles, Institutions, Learners, Pathways, Coaching Hub, Reports
```

*Cards shown/hidden based on enrollment roles and control group status.

## 2.1 Participant Pages (Teachers, Mentors)

```
My Programs (list of assigned pathways)
  └─ Program Page (pathway detail + component cards with progress)
       └─ Component Page (JFB form, child assessment, or redirect to LearnDash course)
```

## 2.2 Leader Pages (School Leaders, District Leaders)

```
My Cycle (auto-scoped cycle workspace)
  ├─ Tab: Teams
  ├─ Tab: Staff
  ├─ Tab: Reports (with drill-down and export)
  └─ Tab: Classrooms
```

Leaders land directly in their scoped view — a School Leader sees their school's data, a District Leader sees their district's data. No need to "navigate to" their org unit.

## 2.3 Staff/Admin/Coach Pages (CRM Directory)

```
Districts Listing
  └─ District Page (info, cycles in this district, schools)
       └─ Cycle Workspace (full operational view for one cycle)

Schools Listing
  └─ School Page (info, cycles, classrooms, staff)

Cycle Workspace (the "command center" for one cycle)
  ├─ Tab: Teams
  ├─ Tab: Staff
  ├─ Tab: Reports
  ├─ Tab: Classrooms
  └─ Tab: Dashboard (stats overview)
```

## 2.4 HL User Profile (Implemented)

**Shortcode:** `[hl_user_profile]`
**Page URL:** `/user-profile/` — accessed via `?user_id=X`, `?enrollment_id=X`, or no parameter (own profile).
**Class:** `HL_Frontend_User_Profile` (`includes/frontend/class-hl-frontend-user-profile.php`)

A unified profile page that replaces BuddyBoss profiles as the primary profile for all users. BuddyBoss profile URLs (forum name clicks, @mentions) are redirected to this page via `template_redirect`.

**6 tabs with role-based visibility:**

| Tab | Content | Who Sees It |
|-----|---------|------------|
| Overview | Photo, name, email, school, district, role(s), classroom(s), coach, enrollment dates | All permitted viewers |
| Progress | Pathway enrollments, component-by-component completion, LearnDash %, rollups | All permitted viewers |
| Coaching | Sessions (upcoming/past), action plans, Schedule Next Session button | Self, Admin, Coach |
| Assessments | TSA/CA completion status; responses for staff/coach only | All (responses gated) |
| RP & Observations | RP sessions, classroom visits, self-reflections | Self, Admin, Coach |
| Manage | Profile edit, enrollment settings, pathway assign/unassign, password reset, deactivation | Admin only |

**Access control:** Own profile (always), Admin/Staff (any profile), Coach (assigned mentors), Mentor (team members), School Leader (school staff), District Leader (district staff). Cycle selector for multi-enrollment users.

**Entry points:** User names clickable in Staff tab, Reports tab, Team Page, Coach Mentors grid, Admin Enrollments. "My Profile" link in BuddyBoss sidebar. Breadcrumb navigation (Dashboard > My School > User Name).

---

# 3) Visibility Rules

All front-end pages require the user to be logged in.

| Page | Who Can See It |
|------|---------------|
| Dashboard | Any logged-in user — cards shown/hidden by role (see §2.0) |
| My Programs | Any enrolled participant (sees only their own pathways) |
| Program Page | The enrolled participant viewing their own pathway |
| Component Page | The enrolled participant (if component is unlocked) |
| My Cycle | School Leader (scoped to their school), District Leader (scoped to their district) |
| Districts Listing | Housman Admin, Coach |
| Schools Listing | Housman Admin, Coach |
| District Page | Housman Admin, Coach, District Leader(s) enrolled in that District |
| School Page | Housman Admin, Coach, School Leader(s) of that School, District Leader(s) of parent District |
| Cycle Workspace | Housman Admin, Coach, plus leaders scoped to their org unit within that cycle |
| Classroom Page | Housman Admin, Coach, School Leader(s), District Leader(s), Teacher(s) assigned to that Classroom |
| Team Page | Housman Admin, Coach, School Leader(s), District Leader(s), Mentor(s) of that Team |
| My Coaching | Any enrolled participant (sees only their own sessions) |
| User Profile | Own profile (always), Admin/Staff (any), Coach (assigned mentors), Mentor (team), School Leader (school staff), District Leader (district staff) |
| Coach Dashboard | Coach WP role or manage_hl_core |
| My Mentors | Coach WP role |
| Mentor Detail | Coach (authorized via assignment), Admin/Staff |
| Coach Reports | Coach WP role |
| Coach Availability | Coach WP role |

If a user does not have permission, show a "You do not have access to this page" message. Never expose data outside the user's scope.

---

# 3.1) Dashboard Page (LMS Home)

## 3.1.1 Dashboard
**Shortcode:** `[hl_dashboard]`
**Purpose:** Role-aware LMS home page. Replaces the old Elementor-based dashboard that used Dynamic Visibility tied to WP roles. This shortcode uses HL Core enrollment data (`hl_enrollment.roles` JSON) for role-based card visibility, which is essential for control group teachers who have WP role `subscriber`.

### Welcome Banner
- Dark gradient banner (primary → primary-light)
- Time-based greeting: "Good morning/afternoon/evening, [Display Name]"
- User avatar (64px, rounded)
- Subtitle: "Welcome to Housman Learning Academy"

### Participant Section (shown if user has any active enrollment)
Cards shown based on enrollment roles and control group status:

| Card | Condition | Links To |
|------|-----------|----------|
| My Programs | Any enrollment | `[hl_my_programs]` |
| My Classrooms | Any enrollment | `[hl_classrooms_listing]` |
| My Coaching | Has at least one non-control-group enrollment | `[hl_my_coaching]` |
| My Team | Has mentor role in any enrollment | `[hl_my_team]` |
| Coaching Hub | Has mentor role in any enrollment | `[hl_coaching_hub]` |
| My Cycle | Has school_leader or district_leader role | `[hl_my_cycle]` |
| My Courses | Has active individual enrollment(s) | Individual enrollment course cards |

Control group teachers (all enrollments are `is_control_group`) see only My Programs + My Classrooms — no coaching, team, or mentor cards.

**My Courses section** (below the Cycle-based participant cards): Shows individual enrollment course cards for standalone purchases. Each card: course name, completion %, "Continue Course" button → LD course URL. Expired enrollments show "Access Expired" badge. Only shown if user has entries in `hl_individual_enrollment` with status='active'.

### Administration Section (shown if user has `manage_hl_core` capability)
Staff/admin cards:

| Card | Links To |
|------|----------|
| Cycles | `[hl_cycles_listing]` |
| Institutions | `[hl_institutions_listing]` |
| Learners | `[hl_learners]` |
| Pathways | `[hl_pathways_listing]` |
| Coaching Hub | `[hl_coaching_hub]` |
| Reports | `[hl_reports_hub]` |

### Card Behavior
- Cards silently hide if the target shortcode page doesn't exist
- Responsive CSS grid: `repeat(auto-fill, minmax(280px, 1fr))`
- Each card: icon (dashicon), title, description, hover effects (translateY, border color)

---

# 4) Page Specifications — Participant Pages

## 4.1 My Programs Page
**Shortcode:** `[hl_my_programs]`
**Purpose:** Landing page for any enrolled participant. Shows all pathways they're assigned to.

Content:
- Page title: "My Programs"
- Grid of pathway cards, one per assigned pathway
- Each card shows:
  - Pathway featured image (or placeholder)
  - Pathway name (front-end label: "Program name")
  - Cycle name (subtitle)
  - Overall completion % with progress bar
  - Status badge: Not Started / In Progress / Completed
  - "Continue" or "View Program" button → navigates to Program Page
- If user has only 1 pathway: still show the card (don't auto-redirect), but make it prominent
- If user has 0 pathways: show a friendly "You're not enrolled in any programs yet" message

## 4.2 Program Page
**Shortcode:** `[hl_program_page]` with URL parameter `pathway_id` or `id`
**Purpose:** The pathway detail view — replaces both "My Progress" and the old LearnDash Group template.

### Header Section
- Pathway featured image (hero banner or sidebar image)
- Pathway name (large heading)
- Cycle name (subtitle)
- Pathway description (rich text — admin-editable)

### Sidebar (or collapsible panel)
- **Program Details:**
  - Average Completion Time (admin-editable field)
  - Time to Complete / Expiration Date (from cycle or pathway end date)
  - Status: Active / Expired / Upcoming
- **Progress:** Overall completion % with visual indicator (progress ring or bar)
- **Certificate:** Link to certificate if pathway is 100% complete (future feature)

### Main Content: Program Overview
- Program Objectives (rich text — admin-editable)
- Program Syllabus link (URL — admin-editable, optional)
- Any other introductory content the admin wants to display

### Main Content: Components Section
- Heading: "Program Steps" or "Activities"
- Grid or list of component cards, ordered by sequence
- Each component card shows:
  - Component title
  - Component type icon (course, self-assessment, child assessment, coaching, observation, self-reflection, reflective practice session, classroom visit)
  - Completion status: progress bar (0-100%)
  - Status badge:
    - **Completed** (green) — shows completion date
    - **In Progress** (blue/yellow) — shows "Continue" button
    - **Available** (default) — shows "Start" button
    - **Locked** (gray, lock icon) — shows reason: "Requires [prereq component name]" or "Available on [drip date]"
  - Click action based on type and status:
    - **Locked:** No click action. Shows lock icon + unlock requirements
    - **LearnDash course:** "Start" / "Continue" button → redirects to LearnDash course URL
    - **JFB-powered component** (self-assessment, observation): "Start" button → navigates to Component Page with embedded JFB form
    - **Child Assessment:** "Start" button → navigates to Component Page with custom matrix form
    - **Coaching Attendance:** Shows attendance status (attended/missed/pending). No click action for participant
    - **Completed:** Shows completion date. No re-submission. Optionally "View" if viewing past responses is allowed

### Pathway Model — New Admin-Editable Fields Required
These fields need to be added to the `hl_pathway` table or stored as pathway meta:
- `description` (longtext) — rich text description shown on Program Page
- `objectives` (longtext) — rich text objectives section
- `syllabus_url` (varchar) — optional link to syllabus document
- `featured_image_id` (bigint) — WordPress attachment ID for featured image
- `avg_completion_time` (varchar) — display string like "4 hours 30 minutes"
- `expiration_date` (date, nullable) — when the pathway expires (can also inherit from cycle end date)

---

## 4.3 Component Page
**Shortcode:** `[hl_component_page]` with URL parameter `component_id` and `enrollment_id`
**Purpose:** Renders the actual form or assessment for the participant to complete.

This page's content depends entirely on the component type:

### Teacher Self-Assessment
- Page header: Component title, pathway breadcrumb ("← Back to [Pathway Name]")
- Custom PHP form rendered by `HL_Teacher_Assessment_Renderer` via `[hl_teacher_assessment]` shortcode
- Structured instrument with sections, scale labels, and instructions from `hl_teacher_assessment_instrument`
- "Save as Draft" and "Submit" buttons
- On submit: HL Core validates, saves to `hl_teacher_assessment_instance.responses_json`, updates component_state

### JFB-Powered Components (observation only)
- Page header: Component title, pathway breadcrumb ("← Back to [Pathway Name]")
- Embedded JFB form with hidden fields pre-populated:
  - `hl_enrollment_id`
  - `hl_component_id`
  - `hl_cycle_id`
  - `hl_observation_id`
- On submit: JFB fires `hl_core_form_submitted` hook → HL Core updates component_state
- After submit: redirect back to Program Page with success message

### Child Assessment
- Branded header: Housman logo, assessment title, teacher info (name, school, classroom)
- Instructions section explaining assessment purpose
- Behavior key legend: explains each rating level (Never through Almost Always) with age-appropriate example behaviors
- Custom PHP form rendered by `HL_Instrument_Renderer` with age-group sections:
  - Children grouped by frozen_age_group from `hl_child_cycle_snapshot`
  - Each section: header (e.g., "Infant (3 children)"), behavior key, question text, transposed Likert matrix
  - Transposed layout: children as columns, rating levels as rows, radio buttons at intersections
  - Per-child "Not in my classroom" checkbox with skip reason dropdown
- "Missing a child?" link at bottom — AJAX auto-saves draft before navigating to Classroom Page
- Render-time reconciliation: new children added, removed children hidden, stale drafts handled
- "Save as Draft" and "Submit" buttons
- On submit: HL Core validates, saves childrows with frozen_age_group/instrument_id/status, updates component_state
- After submit: read-only summary grouped by age group with skip badges
- After submit: redirect back to Program Page with success message

### LearnDash Course
- This type does NOT render on the Component Page
- Instead, the Program Page "Start/Continue" button links directly to the LearnDash course URL
- LearnDash handles its own completion → HL Core picks it up via LearnDash hooks

### Self-Reflection
- Page header: Component title (e.g., "Self-Reflection #1"), pathway breadcrumb
- Auto-populated header fields: school, teacher name, date, age group
- Custom PHP form rendered by `HL_Frontend_Self_Reflection` using the `self_reflection_form` instrument
- Structured editable sections with text areas and rating fields
- "Save as Draft" and "Submit" buttons
- On submit: updates component_state to completed
- After submit: read-only summary view

### Reflective Practice Session
- Page header: Component title (e.g., "Reflective Practice Session #1"), pathway breadcrumb
- Custom PHP page rendered by `HL_Frontend_RP_Session` with **role-based views**:
  - **Coach view**: session prep notes (auto-populated by `HL_Session_Prep_Service` with pathway progress, previous action plans, recent classroom visits), Classroom Visit & Self-Reflection review, editable RP Notes form (`coaching_rp_notes`), editable Action Plan form (`coaching_action_plan`)
  - **Mentor view**: editable RP Notes form (`mentoring_rp_notes`), editable Action Plan form (`mentoring_action_plan`)
  - **Teacher view**: read-only view of completed submissions
- Form submissions stored in `hl_rp_session_submission`
- On submit: updates session status and component_state

### Classroom Visit
- Page header: Component title (e.g., "Classroom Visit #1"), pathway breadcrumb
- Auto-populated header fields: school, teacher name, date, visitor name, age group
- Custom PHP form rendered by `HL_Frontend_Classroom_Visit` using the `classroom_visit_form` instrument
- Structured observation form with domain-based indicators and notes
- "Save as Draft" and "Submit" buttons
- On submit: creates/updates `hl_classroom_visit` record and `hl_classroom_visit_submission`, updates component_state
- After submit: read-only summary view

### Locked Component
- If someone navigates directly to a locked component URL, show:
  - Component title
  - Lock icon + reason ("This component requires [prereq] to be completed first" or "Available on [date]")
  - "← Back to [Pathway Name]" link

---

# 5) Page Specifications — Leader Pages

## 5.1 My Cycle Page
**Shortcode:** `[hl_my_cycle]`
**Purpose:** Auto-scoped cycle workspace for School Leaders and District Leaders.

This page automatically detects the logged-in user's enrollment and scopes the view:
- School Leader → sees data for their school within the cycle
- District Leader → sees data for their entire district within the cycle
- If user is enrolled in multiple cycles, show a cycle switcher dropdown at the top

### Header
- Cycle name
- Org unit scope indicator: "Showing data for [School/District Name]"
- Cycle status: Active / Completed / Upcoming

### Tab: Teams
- List/grid of Teams within the leader's scope
- Each card: Team name, Mentor name(s), # of members, avg completion %
- "View Team" button → navigates to Team Page

### Tab: Staff
- Table of all enrolled participants within scope
- Columns: Name, Email, Team, Role, Completion %
- "View Profile" button per row (links to HL User Profile)
- Sortable columns
- Search box

### Tab: Reports
- Completion report table for all participants in scope
- Columns: #, Name, Team, Role, Institution, Age Groups, Completed %, Details
- "View Details" button expands inline accordion showing per-component breakdown:
  - Component/Course name, Percentage, Status (not_started / in_progress / completed)
- Filters: Institution dropdown (for District Leaders), Team dropdown, Age Groups dropdown, Role dropdown
- Search box (filter by name)
- "Download Report" button → XLSX or CSV export of the filtered view

### Tab: Classrooms
- Table of classrooms within scope
- Columns: Classroom name, School, Age Band, # Children, Teacher(s) assigned
- Click → navigates to Classroom Page

---

# 6) Page Specifications — Staff/Admin/Coach Pages (CRM Directory)

## 6.1 Districts Listing Page
**Shortcode:** `[hl_districts_listing]`
**Purpose:** CRM-style directory for staff to browse all districts.

Content:
- Grid of District cards
- Each card shows: District name, # of Schools, # of active Cycles
- Click → navigates to District Page

## 6.2 Schools Listing Page
**Shortcode:** `[hl_schools_listing]`
**Purpose:** Flat list of all schools for quick staff navigation.

Content:
- Grid of School cards
- Each card shows: School name, Parent District, School Leader name(s), # of Teams (across cycles)
- Click → navigates to School Page

## 6.3 District Page
**Shortcode:** `[hl_district_page]` with URL parameter `id`
**Purpose:** District-level CRM view showing all cycles and schools in a district.

### Header
- District name
- District logo/image (if available via meta, otherwise placeholder)

### Section: Active Cycles
- List of cycles that include this district (via `hl_cycle_school` → parent district)
- Each shows: Cycle name, status (active/completed/upcoming), date range, participant count
- "Open Cycle" button → navigates to Cycle Workspace filtered to this district

### Section: Schools
- Grid of School cards within this District
- Each card: School name, School Leader name(s)
- Click → navigates to School Page

### Section: Overview Stats (simple v1)
- Total participants across all active cycles
- Total schools

## 6.4 School Page
**Shortcode:** `[hl_school_page]` with URL parameter `id`
**Purpose:** School-level CRM view.

### Header
- School name
- School logo/image (if available, otherwise placeholder)
- Parent District name (linked to District Page)

### Section: Active Cycles
- List of cycles this school participates in
- Each shows: Cycle name, status, participant count at this school
- "Open Cycle" button → navigates to Cycle Workspace filtered to this school

### Section: Classrooms
- Table of classrooms at this school (across all cycles, or filtered by selected cycle)
- Columns: Classroom name, Age Band, # Children, Teacher(s) assigned
- Click → navigates to Classroom Page

### Section: Staff
- Table of all users associated with this school (across cycles)
- Columns: Name, Email, Role, Cycle
- Click → HL User Profile

## 6.5 Cycle Workspace
**Shortcode:** `[hl_cycle_workspace]` with URL parameter `cycle_id` and optional `orgunit_id`
**Purpose:** The operational "command hub" for one cycle. Staff/admin sees everything; when accessed from a District/School page, auto-filters to that org unit.

### Header
- Cycle name, status, date range
- Scope indicator: "All" or "Filtered to [Org Unit Name]"
- Org unit filter dropdown (for staff who want to narrow scope)

### Tab: Dashboard (v1 — start simple)
- Overall cycle completion % (average across all participants in scope)
- Participant counts by status:
  - **On Track:** Has completed all components that are currently unlocked
  - **Behind:** Has unlocked components that are not yet complete
  - **Not Started:** 0% completion on all components
- Staff counts: # Teachers, # Mentors, # Schools (in scope)
- Simple bar or progress indicators (no complex charts for v1)

### Tab: Teams
- Same as My Cycle → Teams tab (section 5.1)

### Tab: Staff
- Same as My Cycle → Staff tab (section 5.1) but with broader scope for admin/coach

### Tab: Reports
- Same as My Cycle → Reports tab (section 5.1) but with broader scope
- Additional filter: School/Institution dropdown (when viewing full cycle)

### Tab: Classrooms
- Same as My Cycle → Classrooms tab (section 5.1) but with broader scope

---

# 7) Shared Sub-Pages

## 7.1 Team Page
**Shortcode:** `[hl_team_page]` with URL parameter `team_id`
**Purpose:** Team detail view, accessible from cycle workspace or My Cycle.

### Header
- Team name
- School name (linked to School Page)
- Breadcrumb: "← Back to [Cycle Name]" or "← Back to My Cycle"

### Tab: Team Members
- Table of team members
- Columns: Name, Email, Role (Teacher / Mentor), Completion %
- "View Profile" button per row
- "Add Teacher" button (staff/coach only)

### Tab: Report
- Completion report for team members only
- Same columns and expand behavior as Cycle Reports tab
- "Download Report" button → XLSX or CSV

## 7.2 Classroom Page
**Shortcode:** `[hl_classroom_page]` with URL parameter `classroom_id`
**Purpose:** View children roster and classroom details.

### Header
- Classroom name
- School name (linked)
- Age Band
- Teacher(s) assigned (names)
- Breadcrumb: "← Back to [source page]"

### Content — Children Roster
- Table of active children in this classroom
- Columns: Child Name, Date of Birth, Age, Frozen Age Group (badge), Gender
- For assigned teachers: "Add a Child" form (first name, last name, DOB, gender) with duplicate detection
- For assigned teachers: "Remove" button per child with reason dropdown (left school, moved classroom, other) and optional note
- Removal is soft-delete (status='teacher_removed'); removed children tracked with removed_by, removed_at, reason, note
- `?return_to_assessment=INSTANCE_ID` parameter: shows "Return to Assessment" link for seamless navigation from child assessment form

---

# 8) Navigation

## 8.1 Breadcrumbs
Every detail page includes a breadcrumb link back to its parent:
- Program Page → "← Back to My Programs"
- Component Page → "← Back to [Program Name]"
- Team Page → "← Back to [Cycle/source]"
- Classroom Page → "← Back to [source]"
- School Page → "← Back to [District Name]" (for staff)
- Cycle Workspace → "← Back to [District/School Name]" (when accessed from CRM)
- User Profile → "Dashboard > My School > [User Name]" (breadcrumb navigation)

## 8.2 Sidebar Menu Integration (Implemented)
The BuddyBoss sidebar menu is rendered programmatically by `HL_BuddyBoss_Integration::build_menu_items()`. 16 menu items with role-based visibility:

**Personal (require enrollment):**
- My Profile (all enrolled + staff + coach)
- My Programs (enrolled teacher/mentor/leader/staff)
- My Coaching (mentor, non-control-group)
- My Team (mentor or teacher)

**Leader:**
- My School (leader, non-staff — renders `[hl_my_cycle]`)

**Directories:**
- Cycles (staff only)
- Classrooms (staff/leader/teacher/mentor)
- Learners (staff only)

**Staff tools:**
- Coaching Hub (staff only)
- Reports (staff or leader)

**Coach tools (coach WP role):**
- Coaching Home (`[hl_coach_dashboard]`)
- My Mentors (`[hl_coach_mentors]`)
- My Availability (`[hl_coach_availability]`)
- Coach Reports (`[hl_coach_reports]`)

**Documentation:**
- Documentation (manage_options only)

**Disabled:** Pathways (show_condition = false)

---

# 9) URL Structure

Use WordPress pages with URL parameters. Recommended slugs:

| Page | Slug | Parameters |
|------|------|-----------|
| Dashboard | `/dashboard/` | none |
| My Programs | `/my-programs/` | none |
| Program Page | `/program/` | `?id=X` (pathway_id) |
| Component Page | `/component/` | `?id=X&enrollment=Y` |
| My Cycle | `/my-cycle/` | optional `?cycle_id=X` for switcher |
| Districts Listing | `/districts/` | none |
| District Page | `/district/` | `?id=X` |
| Schools Listing | `/institutions/` | none |
| School Page | `/institution/` | `?id=X` |
| Cycle Workspace | `/cycle/` | `?id=X` and optional `&orgunit=Y` |
| Team Page | `/team/` | `?id=X` |
| Classroom Page | `/classroom/` | `?id=X` |
| My Coaching | `/my-coaching/` | optional `?cycle_id=X` |
| User Profile | `/user-profile/` | `?user_id=X` or `?enrollment_id=X` (no param = own profile) |

For v1, URL parameters are simplest. Pretty permalinks (e.g., `/program/begin-to-ecsel/`) can be added later via rewrite rules.

---

# 10) Styling & Layout Notes

- Match the existing BuddyBoss theme (dark sidebar, clean content area)
- Card-based layouts for listings (similar to existing Institution cards and LearnDash course cards)
- Tables should be responsive (horizontal scroll on mobile or stacked layout)
- Tab navigation: horizontal tab bar (similar to existing Institutions/Staff/Reports/Classrooms tabs)
- "View Details" expand in reports: inline accordion, not a new page
- Download Report buttons: prominent, primary color (green)
- Progress percentages: color-coded — green (80-100%), yellow (40-79%), red (0-39%)
- Component status badges: Completed (green), In Progress (blue), Available (default/white), Locked (gray)
- Component type icons: use dashicons or simple SVG icons to differentiate course vs assessment vs coaching

---

# 11) Data Sources

All data comes from HL Core custom tables and services:
- Districts/Schools: `hl_orgunit` via OrgService
- Partnerships (containers): `hl_partnership` via PartnershipService
- Cycles (runs): `hl_cycle` + `hl_cycle_school` via CycleService
- Teams: `hl_team` + `hl_team_membership` via TeamService
- Staff/Participants: `hl_enrollment` via EnrollmentService
- Pathways & Components: `hl_pathway` + `hl_component` via PathwayService
- Component states: `hl_component_state` via component state queries
- Completion rollups: `hl_completion_rollup` via ReportingService
- Classrooms: `hl_classroom` + `hl_child_classroom_current` via ClassroomService
- Children: `hl_child` via ClassroomService
- Unlock logic: RulesEngineService (prereqs, drip, overrides)
- Coach assignments: `hl_coach_assignment` via CoachAssignmentService (resolution: enrollment → team → school)
- Child cycle snapshots: `hl_child_cycle_snapshot` via ClassroomService
- Coaching sessions: `hl_coaching_session` + `hl_coaching_session_submission` via CoachingService
- RP sessions: `hl_rp_session` + `hl_rp_session_submission` via HL_RP_Session_Service
- Classroom visits: `hl_classroom_visit` + `hl_classroom_visit_submission` via HL_Classroom_Visit_Service
- Session prep data: via HL_Session_Prep_Service (auto-populates pathway progress, action plans, classroom visit history)

Scope filtering must use `HL_Security::assert_can()` and enrollment-based scope resolution.

---

# 12) Implementation Notes

- Implement as WordPress shortcodes registered in HL Core
- Each shortcode checks user capabilities and scope before rendering
- Use show/hide with JS for tab switching (simplest for v1; AJAX optional for large datasets)
- Reports table: implement pagination for large cycles (50+ participants)
- XLSX export: use PhpSpreadsheet or lightweight library (CSV as fallback)
- Reuse existing HL Core services — do NOT write raw SQL in shortcode renderers
- The existing `[hl_my_progress]`, `[hl_team_progress]`, and `[hl_cycle_dashboard]` shortcodes should be replaced by the new page shortcodes defined here
- New pathway fields (section 4.2) require a DB migration and admin UI update

---

# 13) Coach Assignment Model

Coaches are Housman staff assigned to work with participants. Assignment can happen at multiple levels with "most specific wins" resolution.

## 13.1 New Table: `hl_coach_assignment`

```
coach_assignment_id  bigint PK AUTO_INCREMENT
coach_user_id        bigint NOT NULL (FK → wp_users)
scope_type           enum('school', 'team', 'enrollment') NOT NULL
scope_id             bigint NOT NULL — school_id, team_id, or enrollment_id
cycle_id             bigint NOT NULL (FK → hl_cycle)
effective_from       date NOT NULL
effective_to         date NULL — NULL = currently active
created_at           datetime
updated_at           datetime
```

Indexes: `(cycle_id, scope_type, scope_id)`, `(coach_user_id)`, `(cycle_id, coach_user_id)`

## 13.2 Resolution Logic (Most Specific Wins)

To determine "who is User X's coach in Cycle Y":
1. Check for active `enrollment``school`-level assignment where `scope_id` = user's enrollment_id → if found, return that coach
2. Check for active `team``school`-level assignment where `scope_id` = user's team_id → if found, return that coach
3. Check for active `school`-level assignment where `scope_id` = user's school_id → if found, return that coach
4. No coach assigned → return null

"Active" means: `effective_from <= today` AND (`effective_to IS NULL` OR `effective_to >= today`)

## 13.3 Reassignment

When a coach is replaced:
- Set `effective_to` = today on the old assignment
- Create new assignment with `effective_from` = today, `effective_to` = NULL
- Historical assignments remain in the table for audit trail
- Existing coaching sessions retain their original `coach_user_id` (frozen at time of session)

## 13.4 Admin UI for Coach Assignment

Coach assignment is managed in these existing admin/front-end pages:
- **School Page (admin or CRM):** "Default Coach" dropdown — creates/updates school-level assignment
- **Team Page (admin):** "Coach" dropdown — creates/updates team`school`-level assignment (overrides school default)
- **Enrollment detail / HL User Profile (Manage tab):** "Coach Override" — creates enrollment-level assignment

Alternatively, a dedicated "Coach Assignments" admin page under HL Core menu showing all current assignments with filter by cycle.

---

# 14) Coaching Session Model — Expanded

The existing `hl_coaching_session` table needs additional fields to support the participant-facing experience.

## 14.1 Schema Changes to `hl_coaching_session` (Implemented — Schema Revision 24)

Columns added in schema revision 24 (in addition to earlier session_title, meeting_url, session_status, cancelled_at, rescheduled_from_session_id):
- `component_id` bigint NULL — FK to `hl_component`, links session to specific coaching component in pathway
- `zoom_meeting_id` bigint NULL — Zoom API meeting ID for update/delete operations
- `outlook_event_id` varchar(255) NULL — Microsoft Graph calendar event ID for update/delete
- `booked_by_user_id` bigint NULL — FK to WP User who created the booking
- `mentor_timezone` varchar(100) NULL — IANA timezone at booking time
- `coach_timezone` varchar(100) NULL — IANA timezone at booking time

Note: `attendance_status` column is kept alongside `session_status` for backward compatibility.

## 14.2 Session Status Flow

```
scheduled → attended      (coach marks attendance after session)
scheduled → missed        (coach marks no-show after session)
scheduled → cancelled     (participant or coach cancels)
scheduled → rescheduled   (creates new session, old one marked rescheduled)
```

Only `scheduled` sessions can be cancelled or rescheduled. `attended`, `missed`, `cancelled`, `rescheduled` are terminal states.

---

# 15) Coaching — Front-End Pages

## 15.1 My Coach Widget

Displayed on the My Programs page (or as a sidebar component on Program Page).

Content:
- Coach profile photo (WP avatar)
- Coach display name
- Coach email (clickable mailto link)
- "Schedule a Session" button (links to coaching page)

If no coach assigned: show "No coach assigned yet. Contact your administrator."

## 15.2 My Coaching Page (Implemented — Rewritten)
**Shortcode:** `[hl_my_coaching]`
**Purpose:** Component-based coaching sessions hub showing all `coaching_session_attendance` components from the participant's pathways.

### Implementation
- Component-based view: shows all coaching_session_attendance components across pathways with status badges (Completed / Scheduled / Not Scheduled)
- Drip rule locking: components with fixed_date or after_completion_delay drip rules show lock icon + unlock date/reason
- `complete_by` dates displayed when set on the component
- "View" links route to Component Page scheduling UI for each session
- Multi-cycle grouping: participants enrolled in multiple cycles see sessions grouped by cycle
- Coach info card: avatar, name, email via CoachAssignmentService resolution

### Visibility
- Any enrolled participant can see their own sessions
- Coaches see coaching tabs on assigned mentor profiles (via HL User Profile Coaching tab)

## 15.3 Coach/Admin Coaching Management

The existing admin Coaching Sessions page already handles most management. Enhancements needed:
- Add `session_status` dropdown (replacing attendance_status radio)
- Add `meeting_url` field
- Add `session_title` field
- "Schedule on behalf" flow: coach selects participant, picks date/time, creates session
- Reschedule action: creates new session linked to old one, marks old as 'rescheduled'
- Cancel action: sets status to 'cancelled', records `cancelled_at`

## 15.4 Coaching in Program Page

On the Program Page, coaching_session_attendance activities should show enhanced info:
- If session is scheduled: show date/time and "Upcoming on [date]" badge
- If session is attended: show "Completed on [date]" badge with checkmark
- If no session scheduled yet: show "Schedule Session" button → links to My Coaching page
- If session is missed: show "Missed" badge with "Reschedule" link

---

# 16) Sidebar Navigation & Role-Based Menu (Implemented)

The BuddyBoss sidebar menu is rendered programmatically by `HL_BuddyBoss_Integration::build_menu_items()`. The actual code is the source of truth for the role matrix.

## 16.1 Current Menu Structure (16 items, from code)

| Menu Item | Shortcode | Visible To |
|---|---|---|
| My Profile | `hl_user_profile` | Enrolled + staff + coach |
| My Programs | `hl_my_programs` | Enrolled teacher/mentor/leader/staff |
| My Coaching | `hl_my_coaching` | Mentor (non-control-group) |
| My Team | `hl_my_team` | Mentor or teacher |
| My School | `hl_my_cycle` | Leader (non-staff) |
| Cycles | `hl_cycles_listing` | Staff only |
| Classrooms | `hl_classrooms_listing` | Staff, leader, teacher, or mentor |
| Learners | `hl_learners` | Staff only |
| Pathways | `hl_pathways_listing` | **Disabled** (show_condition = false) |
| Coaching Hub | `hl_coaching_hub` | Staff only |
| Coaching Home | `hl_coach_dashboard` | Coach WP role |
| My Mentors | `hl_coach_mentors` | Coach WP role |
| My Availability | `hl_coach_availability` | Coach WP role |
| Coach Reports | `hl_coach_reports` | Coach WP role |
| Reports | `hl_reports_hub` | Staff or leader |
| Documentation | `hl_docs` | manage_options only |

## 16.2 Menu Visibility Rules
- Only show a menu item if the target page exists (shortcode page found via `find_shortcode_page_url()`)
- Staff (`manage_hl_core`) sees directory/management items
- Coach WP role users see dedicated coach tools (Coaching Home, My Mentors, My Availability, Coach Reports)
- Non-staff users: role determined from `hl_enrollment.roles` JSON for the current user
- A user with multiple roles sees the union of all their role menus (no duplicates)
- Highlight the current/active page in the sidebar via `current` / `current-menu-item` CSS class
- Detail pages (Program, Component, Team, Classroom, District, School, User Profile, Mentor Detail) are NOT in the menu — they are navigated to from listing pages
- Control-group-only users: My Coaching hidden (set `$is_control_only` flag)

---

# 17) New Shortcode Pages — Listing & Hub Pages

These pages serve both the sidebar navigation and provide comprehensive browseable views.

## 17.1 Cycles Listing — `[hl_cycles_listing]`
**Purpose:** Browse all cycles the user has access to.

**Layout:**
- Search bar (filters by cycle name or code)
- Status filter: Active (default checked), Future (default checked), Paused, Archived
- Card grid or table: cycle name, code, status badge, start/end dates, participant count, school count
- Click → Cycle Workspace (`[hl_cycle_workspace]?id=X`)

**Scope:**
- Admin: all cycles
- Coach: cycles where assigned as coach (via `hl_coach_assignment`)
- District Leader: cycles where enrolled as district_leader
- School Leader: cycles where enrolled as school_leader

## 17.2 Institutions Listing — `[hl_institutions_listing]`
**Purpose:** Combined view of districts and schools. Replaces the separate `[hl_districts_listing]` and `[hl_schools_listing]` for the sidebar.

**Layout:**
- Search bar
- Toggle: "Districts" / "Schools" / "All" (default: All)
- **Districts section:** Card grid — district name, # schools, # active cycles → click to District Page
- **Schools section:** Card grid — school name, parent district, school leader names → click to School Page

**Scope:**
- Admin: all districts and schools
- Coach: districts/schools where assigned as coach
- District Leader: own district + its schools
- School Leader: own school(s) + parent district

## 17.3 Coaching Hub — `[hl_coaching_hub]`
**Purpose:** Front-end coaching session management and visibility.

**Layout:**
- Search bar (by participant name, coach name, session title)
- Filters: Status (Scheduled/Attended/Missed/Cancelled/Rescheduled), Cycle, Date range
- Table: Session title, Participant name (link to profile), Coach name, Date/time, Status badge, Meeting link, Actions (reschedule/cancel for scheduled sessions)
- "Schedule New Session" button for staff/coaches

**Scope:**
- Admin: all sessions across all cycles
- Coach: own sessions (where coach_user_id = current user)
- District Leader: sessions in cycles where enrolled, scoped to district
- School Leader: sessions in cycles where enrolled, scoped to school
- Mentor: own sessions only (where mentor_enrollment_id matches)

## 17.4 Classrooms Listing — `[hl_classrooms_listing]`
**Purpose:** Browse classrooms across active/future cycles.

**Layout:**
- Search bar (by classroom name, school name, teacher name)
- Filter: School, Age Band, Cycle
- Table: Classroom name, School, Age band, # children, Teacher names, Cycle(s)
- Click → Classroom Page (`[hl_classroom_page]?id=X`)

**Scope:**
- Admin: all classrooms
- Coach: classrooms in cycles where assigned as coach
- District Leader: classrooms in schools under their district
- School Leader: classrooms in their school(s)
- Teacher: classrooms where they have teaching assignments

## 17.5 Learners — `[hl_learners]`
**Purpose:** Searchable participant directory with links to profiles.

**Layout:**
- Search bar (by name, email)
- Filters: Cycle, School, Team, Role (teacher/mentor/school_leader/district_leader), Status
- Table: Name (link to HL User Profile), Email, Role(s), School, Team, Cycle, Completion %
- Pagination (25 per page)

**Scope:**
- Admin: all participants
- Coach: participants in cycles where assigned as coach
- District Leader: participants in their district scope
- School Leader: participants in their school scope
- Mentor: team members only

## 17.6 Pathways Listing — `[hl_pathways_listing]`
**Purpose:** Browse all pathways/programs across the platform (staff view).

**Layout:**
- Search bar (by pathway name)
- Filters: Cycle, Target Role, Status (active/inactive)
- Card grid: Pathway name, cycle name, target roles, # components, featured image, avg completion time
- Click → Program Page (`[hl_program_page]?id=X`) or admin edit page

**Scope:**
- Admin/Coach: all pathways
- Not shown to non-staff (they use My Programs instead)

## 17.7 Reports Hub — `[hl_reports_hub]`
**Purpose:** Central hub for front-end reports. Placeholder for v1.

**Layout:**
- Card grid of available report types:
  - Completion Report (link to cycle workspace reports tab or standalone)
  - Coaching Report (link to coaching hub filtered)
  - Assessment Report (future)
- Each card: title, description, icon, link

**Scope:**
- Different report cards shown based on role
- V1: simple card layout linking to existing pages with report tabs

## 17.8 My Team — `[hl_my_team]`
**Purpose:** Auto-detect shortcut for mentors to reach their team page.

**Logic:**
1. Query `hl_team_membership` joined with `hl_enrollment` for current user where `membership_type = 'mentor'`
2. If exactly one team → redirect or render `[hl_team_page]` with that team_id
3. If multiple teams → show team selector cards
4. If no team → show "You are not assigned as mentor to any team."

## 17.9 Coach Pages (Implemented)

Five dedicated pages for Coach WP role users:

**Coach Dashboard** `[hl_coach_dashboard]` — Landing page with gradient hero (avatar + welcome), 3 stats cards (assigned mentors, upcoming sessions, sessions this month), quick link cards including a "My Meeting Settings" tile (opens a modal to override per-coach Zoom settings: waiting room, mute-on-entry, join-before-host, alternative hosts; dismissible first-visit callout via user_meta `hl_dismissed_coach_zoom_callout`). Coach WP role or manage_hl_core only.

**My Mentors** `[hl_coach_mentors]` — Card grid of assigned mentors with search and school filter. Each card: avatar, name, school, team badge, pathway, progress bar, last/next session dates. Links to Mentor Detail.

**Mentor Detail** `[hl_coach_mentor_detail]` — Full mentor profile via `?mentor_enrollment_id=X`. Header with mentor info + progress. 4 tabs: Coaching Sessions, Team Overview, RP Sessions, Reports + CSV export. "Schedule Next Session" button for the next unscheduled coaching component. Authorization via coach assignment resolution.

**Coach Reports** `[hl_coach_reports]` — Aggregated completion data. Cycle + school filter dropdowns. Summary stats row. Completion table with progress bars. CSV export via POST.

**Coach Availability** `[hl_coach_availability]` — Weekly schedule grid for recurring coaching hours. 7-column grid (Mon-Sun), 30-min slots (7AM-7PM). Click to toggle, saves to `hl_coach_availability` table via POST. Legend with slot count and hours/week.

---

# 18) Build Priority (Updated)

Original Phases A-C are complete (Phases 7-9 in README build queue).

**Phase D: Coach Assignment + Coaching Enhancement — COMPLETE**
All coaching features implemented: `hl_coach_assignment`, coaching session scheduling, `[hl_my_coaching]` rewrite, My Coach widget, Program Page coaching enhancement.

**Phase E: Calendar + Scheduling Integration — COMPLETE**
Microsoft Graph API client (`HL_Microsoft_Graph`), Zoom S2S OAuth client (`HL_Zoom_Integration`), `HL_Scheduling_Service` with slot calculation + booking/reschedule/cancel, `HL_Scheduling_Email_Service` with admin-editable templates, admin Settings > Scheduling & Integrations tab, Component Page scheduling UI, Coach Mentor Detail "Schedule Next Session" button.

**Phase F: HL User Profile — COMPLETE**
Unified profile page with 6 tabs. BB profile URLs redirect. See §2.4.

**Phase G: Component Eligibility Rules — COMPLETE**
`requires_classroom` + `eligible_roles` on `hl_component`. `HL_Rules_Engine_Service::check_eligibility()`. Frontend "Not Applicable" rendering on all pages. Excluded from completion %.

---

End of file.
