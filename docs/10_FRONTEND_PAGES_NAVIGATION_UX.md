# Housman Learning Core Plugin — AI Library
## File: 10_FRONTEND_PAGES_NAVIGATION_UX.md
Version: 2.0
Last Updated: 2026-02-18
Timezone: America/Bogota

---

# 0) Purpose

This document specifies the front-end (participant-facing) pages that HL Core must provide. These are WordPress pages rendered via shortcodes, visible to enrolled participants and staff based on role and scope.

Admin management pages (Cohorts CRUD, Org Units CRUD, Pathway configuration, Import wizard, Audit log, etc.) live in WP Admin and are NOT covered here.

**Key design principle:** The front-end has TWO separate concerns:

1. **Cohort Workspace (operational, time-bound):** Where daily work happens — pathways, progress, reports, teams. Everything scoped to ONE cohort. This is what teachers, mentors, and leaders use.
2. **Organizational Directory (CRM-like, permanent):** Districts and Centers exist across cohorts. This is the admin/coach view for managing the organization and navigating between cohorts.

---

# 1) Front-End Label Mapping

The internal data model uses technical terms. The front-end uses participant-friendly labels:

| Internal Model | Front-End Label | Notes |
|----------------|----------------|-------|
| Pathway | Program | "My Programs" page, "Program" in cards/headers |
| Activity | Activity or Step | Contextual — "Activity" in reports, "Step" acceptable in pathway view |
| Cohort | Cohort | Kept as-is (admin/leader term) |
| OrgUnit (district) | School District | |
| OrgUnit (center) | Institution / Center | Use whichever the client prefers; currently "Institution" in production |
| Enrollment | (not shown) | Internal concept, never surfaced to participants |

---

# 2) Page Architecture Overview

## 2.1 Participant Pages (Teachers, Mentors)

```
My Programs (list of assigned pathways)
  └─ Program Page (pathway detail + activity cards with progress)
       └─ Activity Page (JFB form, children assessment, or redirect to LearnDash course)
```

## 2.2 Leader Pages (Center Leaders, District Leaders)

```
My Cohort (auto-scoped cohort workspace)
  ├─ Tab: Teams
  ├─ Tab: Staff
  ├─ Tab: Reports (with drill-down and export)
  └─ Tab: Classrooms
```

Leaders land directly in their scoped view — a Center Leader sees their center's data, a District Leader sees their district's data. No need to "navigate to" their org unit.

## 2.3 Staff/Admin/Coach Pages (CRM Directory)

```
Districts Listing
  └─ District Page (info, cohorts in this district, centers)
       └─ Cohort Workspace (full operational view for one cohort)

Centers Listing
  └─ Center Page (info, cohorts, classrooms, staff)

Cohort Workspace (the "command center" for one cohort)
  ├─ Tab: Teams
  ├─ Tab: Staff
  ├─ Tab: Reports
  ├─ Tab: Classrooms
  └─ Tab: Dashboard (stats overview)
```

## 2.4 Future: BuddyBoss Profile Integration

A custom BuddyBoss profile tab ("Housman Learning" or similar) where coaches/admins can manage a specific user:
- Enrollment info and role
- Pathway progress detail
- Team assignment (with ability to change)
- Coaching sessions
- Action buttons: re-send invite, override activity, change team/role

**This is OUT OF SCOPE for v1 but should be designed to integrate cleanly later.**

---

# 3) Visibility Rules

All front-end pages require the user to be logged in.

| Page | Who Can See It |
|------|---------------|
| My Programs | Any enrolled participant (sees only their own pathways) |
| Program Page | The enrolled participant viewing their own pathway |
| Activity Page | The enrolled participant (if activity is unlocked) |
| My Cohort | Center Leader (scoped to their center), District Leader (scoped to their district) |
| Districts Listing | Housman Admin, Coach |
| Centers Listing | Housman Admin, Coach |
| District Page | Housman Admin, Coach, District Leader(s) enrolled in that District |
| Center Page | Housman Admin, Coach, Center Leader(s) of that Center, District Leader(s) of parent District |
| Cohort Workspace | Housman Admin, Coach, plus leaders scoped to their org unit within that cohort |
| Classroom Page | Housman Admin, Coach, Center Leader(s), District Leader(s), Teacher(s) assigned to that Classroom |
| Team Page | Housman Admin, Coach, Center Leader(s), District Leader(s), Mentor(s) of that Team |

If a user does not have permission, show a "You do not have access to this page" message. Never expose data outside the user's scope.

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
  - Cohort name (subtitle)
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
- Cohort name (subtitle)
- Pathway description (rich text — admin-editable)

### Sidebar (or collapsible panel)
- **Program Details:**
  - Average Completion Time (admin-editable field)
  - Time to Complete / Expiration Date (from cohort or pathway end date)
  - Status: Active / Expired / Upcoming
- **Progress:** Overall completion % with visual indicator (progress ring or bar)
- **Certificate:** Link to certificate if pathway is 100% complete (future feature)

### Main Content: Program Overview
- Program Objectives (rich text — admin-editable)
- Program Syllabus link (URL — admin-editable, optional)
- Any other introductory content the admin wants to display

### Main Content: Activities Section
- Heading: "Program Steps" or "Activities"
- Grid or list of activity cards, ordered by sequence
- Each activity card shows:
  - Activity title
  - Activity type icon (course, self-assessment, children assessment, coaching, observation)
  - Completion status: progress bar (0-100%)
  - Status badge:
    - **Completed** (green) — shows completion date
    - **In Progress** (blue/yellow) — shows "Continue" button
    - **Available** (default) — shows "Start" button
    - **Locked** (gray, lock icon) — shows reason: "Requires [prereq activity name]" or "Available on [drip date]"
  - Click action based on type and status:
    - **Locked:** No click action. Shows lock icon + unlock requirements
    - **LearnDash course:** "Start" / "Continue" button → redirects to LearnDash course URL
    - **JFB-powered activity** (self-assessment, observation): "Start" button → navigates to Activity Page with embedded JFB form
    - **Children Assessment:** "Start" button → navigates to Activity Page with custom matrix form
    - **Coaching Attendance:** Shows attendance status (attended/missed/pending). No click action for participant
    - **Completed:** Shows completion date. No re-submission. Optionally "View" if viewing past responses is allowed

### Pathway Model — New Admin-Editable Fields Required
These fields need to be added to the `hl_pathway` table or stored as pathway meta:
- `description` (longtext) — rich text description shown on Program Page
- `objectives` (longtext) — rich text objectives section
- `syllabus_url` (varchar) — optional link to syllabus document
- `featured_image_id` (bigint) — WordPress attachment ID for featured image
- `avg_completion_time` (varchar) — display string like "4 hours 30 minutes"
- `expiration_date` (date, nullable) — when the pathway expires (can also inherit from cohort end date)

---

## 4.3 Activity Page
**Shortcode:** `[hl_activity_page]` with URL parameter `activity_id` and `enrollment_id`
**Purpose:** Renders the actual form or assessment for the participant to complete.

This page's content depends entirely on the activity type:

### JFB-Powered Activities (teacher self-assessment, observation)
- Page header: Activity title, pathway breadcrumb ("← Back to [Pathway Name]")
- Embedded JFB form with hidden fields pre-populated:
  - `hl_enrollment_id`
  - `hl_activity_id`
  - `hl_cohort_id`
  - `hl_instance_id` (for observations: `hl_observation_id`)
- On submit: JFB fires `hl_core_form_submitted` hook → HL Core updates activity_state
- After submit: redirect back to Program Page with success message

### Children Assessment
- Page header: Activity title, pathway breadcrumb
- Custom PHP matrix form rendered by `HL_Instrument_Renderer`
- One row per child in teacher's assigned classroom(s)
- Each row shows the instrument's questions
- "Save as Draft" and "Submit" buttons
- On submit: HL Core updates activity_state
- After submit: redirect back to Program Page with success message

### LearnDash Course
- This type does NOT render on the Activity Page
- Instead, the Program Page "Start/Continue" button links directly to the LearnDash course URL
- LearnDash handles its own completion → HL Core picks it up via LearnDash hooks

### Locked Activity
- If someone navigates directly to a locked activity URL, show:
  - Activity title
  - Lock icon + reason ("This activity requires [prereq] to be completed first" or "Available on [date]")
  - "← Back to [Pathway Name]" link

---

# 5) Page Specifications — Leader Pages

## 5.1 My Cohort Page
**Shortcode:** `[hl_my_cohort]`
**Purpose:** Auto-scoped cohort workspace for Center Leaders and District Leaders.

This page automatically detects the logged-in user's enrollment and scopes the view:
- Center Leader → sees data for their center within the cohort
- District Leader → sees data for their entire district within the cohort
- If user is enrolled in multiple cohorts, show a cohort switcher dropdown at the top

### Header
- Cohort name
- Org unit scope indicator: "Showing data for [Center/District Name]"
- Cohort status: Active / Completed / Upcoming

### Tab: Teams
- List/grid of Teams within the leader's scope
- Each card: Team name, Mentor name(s), # of members, avg completion %
- "View Team" button → navigates to Team Page

### Tab: Staff
- Table of all enrolled participants within scope
- Columns: Name, Email, Team, Role, Completion %
- "View Profile" button per row (links to BuddyBoss profile in future; for now, expands inline detail)
- Sortable columns
- Search box

### Tab: Reports
- Completion report table for all participants in scope
- Columns: #, Name, Team, Role, Institution, Age Groups, Completed %, Details
- "View Details" button expands inline accordion showing per-activity breakdown:
  - Activity/Course name, Percentage, Status (not_started / in_progress / completed)
- Filters: Institution dropdown (for District Leaders), Team dropdown, Age Groups dropdown, Role dropdown
- Search box (filter by name)
- "Download Report" button → XLSX or CSV export of the filtered view

### Tab: Classrooms
- Table of classrooms within scope
- Columns: Classroom name, Center, Age Band, # Children, Teacher(s) assigned
- Click → navigates to Classroom Page

---

# 6) Page Specifications — Staff/Admin/Coach Pages (CRM Directory)

## 6.1 Districts Listing Page
**Shortcode:** `[hl_districts_listing]`
**Purpose:** CRM-style directory for staff to browse all districts.

Content:
- Grid of District cards
- Each card shows: District name, # of Centers, # of active Cohorts
- Click → navigates to District Page

## 6.2 Centers Listing Page
**Shortcode:** `[hl_centers_listing]`
**Purpose:** Flat list of all centers for quick staff navigation.

Content:
- Grid of Center cards
- Each card shows: Center name, Parent District, Center Leader name(s), # of Teams (across cohorts)
- Click → navigates to Center Page

## 6.3 District Page
**Shortcode:** `[hl_district_page]` with URL parameter `id`
**Purpose:** District-level CRM view showing all cohorts and centers in a district.

### Header
- District name
- District logo/image (if available via meta, otherwise placeholder)

### Section: Active Cohorts
- List of cohorts that include this district (via `hl_cohort_center` → parent district)
- Each shows: Cohort name, status (active/completed/upcoming), date range, participant count
- "Open Cohort" button → navigates to Cohort Workspace filtered to this district

### Section: Centers
- Grid of Center cards within this District
- Each card: Center name, Center Leader name(s)
- Click → navigates to Center Page

### Section: Overview Stats (simple v1)
- Total participants across all active cohorts
- Total centers

## 6.4 Center Page
**Shortcode:** `[hl_center_page]` with URL parameter `id`
**Purpose:** Center-level CRM view.

### Header
- Center name
- Center logo/image (if available, otherwise placeholder)
- Parent District name (linked to District Page)

### Section: Active Cohorts
- List of cohorts this center participates in
- Each shows: Cohort name, status, participant count at this center
- "Open Cohort" button → navigates to Cohort Workspace filtered to this center

### Section: Classrooms
- Table of classrooms at this center (across all cohorts, or filtered by selected cohort)
- Columns: Classroom name, Age Band, # Children, Teacher(s) assigned
- Click → navigates to Classroom Page

### Section: Staff
- Table of all users associated with this center (across cohorts)
- Columns: Name, Email, Role, Cohort
- Click → BuddyBoss profile (future) or inline detail

## 6.5 Cohort Workspace
**Shortcode:** `[hl_cohort_workspace]` with URL parameter `cohort_id` and optional `orgunit_id`
**Purpose:** The operational "command center" for one cohort. Staff/admin sees everything; when accessed from a District/Center page, auto-filters to that org unit.

### Header
- Cohort name, status, date range
- Scope indicator: "All" or "Filtered to [Org Unit Name]"
- Org unit filter dropdown (for staff who want to narrow scope)

### Tab: Dashboard (v1 — start simple)
- Overall cohort completion % (average across all participants in scope)
- Participant counts by status:
  - **On Track:** Has completed all activities that are currently unlocked
  - **Behind:** Has unlocked activities that are not yet complete
  - **Not Started:** 0% completion on all activities
- Staff counts: # Teachers, # Mentors, # Centers (in scope)
- Simple bar or progress indicators (no complex charts for v1)

### Tab: Teams
- Same as My Cohort → Teams tab (section 5.1)

### Tab: Staff
- Same as My Cohort → Staff tab (section 5.1) but with broader scope for admin/coach

### Tab: Reports
- Same as My Cohort → Reports tab (section 5.1) but with broader scope
- Additional filter: Center/Institution dropdown (when viewing full cohort)

### Tab: Classrooms
- Same as My Cohort → Classrooms tab (section 5.1) but with broader scope

---

# 7) Shared Sub-Pages

## 7.1 Team Page
**Shortcode:** `[hl_team_page]` with URL parameter `team_id`
**Purpose:** Team detail view, accessible from cohort workspace or My Cohort.

### Header
- Team name
- Center name (linked to Center Page)
- Breadcrumb: "← Back to [Cohort Name]" or "← Back to My Cohort"

### Tab: Team Members
- Table of team members
- Columns: Name, Email, Role (Teacher / Mentor), Completion %
- "View Profile" button per row
- "Add Teacher" button (staff/coach only)

### Tab: Report
- Completion report for team members only
- Same columns and expand behavior as Cohort Reports tab
- "Download Report" button → XLSX or CSV

## 7.2 Classroom Page
**Shortcode:** `[hl_classroom_page]` with URL parameter `classroom_id`
**Purpose:** View children roster and classroom details.

### Header
- Classroom name
- Center name (linked)
- Age Band
- Teacher(s) assigned (names)
- Breadcrumb: "← Back to [source page]"

### Content
- Table of children in this classroom
- Columns: Child Name (or identifier), Date of Birth, Age, Gender
- Sortable columns
- No edit functionality on front-end (admin-side only)

---

# 8) Navigation

## 8.1 Breadcrumbs
Every detail page includes a breadcrumb link back to its parent:
- Program Page → "← Back to My Programs"
- Activity Page → "← Back to [Program Name]"
- Team Page → "← Back to [Cohort/source]"
- Classroom Page → "← Back to [source]"
- Center Page → "← Back to [District Name]" (for staff)
- Cohort Workspace → "← Back to [District/Center Name]" (when accessed from CRM)

## 8.2 Sidebar Menu Integration
The following items should appear in the BuddyBoss sidebar menu under "HOUSMAN LEARNING" (or similar section), conditional on user role:

**All enrolled participants:**
- My Programs

**Center Leaders, District Leaders:**
- My Programs
- My Cohort

**Admin, Coach:**
- My Programs (if enrolled)
- School Districts
- Institutions
- (Individual cohort workspaces accessed via Districts/Centers, not from sidebar)

---

# 9) URL Structure

Use WordPress pages with URL parameters. Recommended slugs:

| Page | Slug | Parameters |
|------|------|-----------|
| My Programs | `/my-programs/` | none |
| Program Page | `/program/` | `?id=X` (pathway_id) |
| Activity Page | `/activity/` | `?id=X&enrollment=Y` |
| My Cohort | `/my-cohort/` | optional `?cohort_id=X` for switcher |
| Districts Listing | `/districts/` | none |
| District Page | `/district/` | `?id=X` |
| Centers Listing | `/institutions/` | none |
| Center Page | `/institution/` | `?id=X` |
| Cohort Workspace | `/cohort/` | `?id=X` and optional `&orgunit=Y` |
| Team Page | `/team/` | `?id=X` |
| Classroom Page | `/classroom/` | `?id=X` |

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
- Activity status badges: Completed (green), In Progress (blue), Available (default/white), Locked (gray)
- Activity type icons: use dashicons or simple SVG icons to differentiate course vs assessment vs coaching

---

# 11) Data Sources

All data comes from HL Core custom tables and services:
- Districts/Centers: `hl_orgunit` via OrgService
- Cohorts: `hl_cohort` + `hl_cohort_center` via CohortService
- Teams: `hl_team` + `hl_team_membership` via TeamService
- Staff/Participants: `hl_enrollment` via EnrollmentService
- Pathways & Activities: `hl_pathway` + `hl_activity` via PathwayService
- Activity states: `hl_activity_state` via ActivityStateService
- Completion rollups: `hl_completion_rollup` via ReportingService
- Classrooms: `hl_classroom` + `hl_child_classroom_current` via ClassroomService
- Children: `hl_child` via ClassroomService
- Unlock logic: RulesEngineService (prereqs, drip, overrides)

Scope filtering must use `HL_Security::assert_can()` and enrollment-based scope resolution.

---

# 12) Implementation Notes

- Implement as WordPress shortcodes registered in HL Core
- Each shortcode checks user capabilities and scope before rendering
- Use show/hide with JS for tab switching (simplest for v1; AJAX optional for large datasets)
- Reports table: implement pagination for large cohorts (50+ participants)
- XLSX export: use PhpSpreadsheet or lightweight library (CSV as fallback)
- Reuse existing HL Core services — do NOT write raw SQL in shortcode renderers
- The existing `[hl_my_progress]`, `[hl_team_progress]`, and `[hl_cohort_dashboard]` shortcodes should be replaced by the new page shortcodes defined here
- New pathway fields (section 4.2) require a DB migration and admin UI update

---

# 13) Build Priority

Recommended build order:

**Phase A: Participant Experience (highest priority)**
1. Add new pathway fields (description, objectives, syllabus_url, featured_image_id, avg_completion_time, expiration_date) — DB migration + admin UI
2. `[hl_my_programs]` — My Programs listing page
3. `[hl_program_page]` — Program detail page with activity cards
4. `[hl_activity_page]` — Activity page (JFB embed + children assessment)

**Phase B: Leader Experience**
5. `[hl_my_cohort]` — Auto-scoped cohort view for leaders (tabs: Teams, Staff, Reports, Classrooms)
6. `[hl_team_page]` — Team detail page
7. `[hl_classroom_page]` — Classroom detail page

**Phase C: Staff/Admin CRM Directory**
8. `[hl_districts_listing]` — Districts listing
9. `[hl_district_page]` — District detail with cohorts and centers
10. `[hl_centers_listing]` — Centers listing
11. `[hl_center_page]` — Center detail with cohorts and classrooms
12. `[hl_cohort_workspace]` — Full cohort command center with Dashboard tab

**Phase D: BuddyBoss Integration (future)**
13. Custom BuddyBoss profile tab for user management

---

End of file.
