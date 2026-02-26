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

1. **Track Workspace (operational, time-bound):** Where daily work happens — pathways, progress, reports, teams. Everything scoped to ONE track. This is what teachers, mentors, and leaders use.
2. **Organizational Directory (CRM-like, permanent):** Districts and Schools exist across tracks. This is the admin/coach view for managing the organization and navigating between tracks.

---

# 1) Front-End Label Mapping

The internal data model uses technical terms. The front-end uses participant-friendly labels:

| Internal Model | Front-End Label | Notes |
|----------------|----------------|-------|
| Pathway | Program | "My Programs" page, "Program" in cards/headers |
| Activity | Activity or Step | Contextual — "Activity" in reports, "Step" acceptable in pathway view |
| Track | Track | The time-bounded run — used in workspace and listing pages |
| Cohort | Cohort | The container entity — used for cross-track aggregation and comparison |
| OrgUnit (district) | School District | |
| OrgUnit (school) | Institution / School | Use whichever the client prefers; currently "Institution" in production |
| Enrollment | (not shown) | Internal concept, never surfaced to participants |

---

# 2) Page Architecture Overview

## 2.1 Participant Pages (Teachers, Mentors)

```
My Programs (list of assigned pathways)
  └─ Program Page (pathway detail + activity cards with progress)
       └─ Activity Page (JFB form, child assessment, or redirect to LearnDash course)
```

## 2.2 Leader Pages (School Leaders, District Leaders)

```
My Track (auto-scoped track workspace)
  ├─ Tab: Teams
  ├─ Tab: Staff
  ├─ Tab: Reports (with drill-down and export)
  └─ Tab: Classrooms
```

Leaders land directly in their scoped view — a School Leader sees their school's data, a District Leader sees their district's data. No need to "navigate to" their org unit.

## 2.3 Staff/Admin/Coach Pages (CRM Directory)

```
Districts Listing
  └─ District Page (info, tracks in this district, schools)
       └─ Track Workspace (full operational view for one track)

Schools Listing
  └─ School Page (info, tracks, classrooms, staff)

Track Workspace (the "command center" for one track)
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
| My Track | School Leader (scoped to their school), District Leader (scoped to their district) |
| Districts Listing | Housman Admin, Coach |
| Schools Listing | Housman Admin, Coach |
| District Page | Housman Admin, Coach, District Leader(s) enrolled in that District |
| School Page | Housman Admin, Coach, School Leader(s) of that School, District Leader(s) of parent District |
| Track Workspace | Housman Admin, Coach, plus leaders scoped to their org unit within that track |
| Classroom Page | Housman Admin, Coach, School Leader(s), District Leader(s), Teacher(s) assigned to that Classroom |
| Team Page | Housman Admin, Coach, School Leader(s), District Leader(s), Mentor(s) of that Team |
| My Coaching | Any enrolled participant (sees only their own sessions) |

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
  - Track name (subtitle)
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
- Track name (subtitle)
- Pathway description (rich text — admin-editable)

### Sidebar (or collapsible panel)
- **Program Details:**
  - Average Completion Time (admin-editable field)
  - Time to Complete / Expiration Date (from track or pathway end date)
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
  - Activity type icon (course, self-assessment, child assessment, coaching, observation)
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
    - **Child Assessment:** "Start" button → navigates to Activity Page with custom matrix form
    - **Coaching Attendance:** Shows attendance status (attended/missed/pending). No click action for participant
    - **Completed:** Shows completion date. No re-submission. Optionally "View" if viewing past responses is allowed

### Pathway Model — New Admin-Editable Fields Required
These fields need to be added to the `hl_pathway` table or stored as pathway meta:
- `description` (longtext) — rich text description shown on Program Page
- `objectives` (longtext) — rich text objectives section
- `syllabus_url` (varchar) — optional link to syllabus document
- `featured_image_id` (bigint) — WordPress attachment ID for featured image
- `avg_completion_time` (varchar) — display string like "4 hours 30 minutes"
- `expiration_date` (date, nullable) — when the pathway expires (can also inherit from track end date)

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
  - `hl_track_id`
  - `hl_instance_id` (for observations: `hl_observation_id`)
- On submit: JFB fires `hl_core_form_submitted` hook → HL Core updates activity_state
- After submit: redirect back to Program Page with success message

### Child Assessment
- Branded header: Housman logo, assessment title, teacher info (name, school, classroom)
- Instructions section explaining assessment purpose
- Behavior key legend: explains each rating level (Never through Almost Always) with age-appropriate example behaviors
- Custom PHP form rendered by `HL_Instrument_Renderer` with age-group sections:
  - Children grouped by frozen_age_group from `hl_child_track_snapshot`
  - Each section: header (e.g., "Infant (3 children)"), behavior key, question text, transposed Likert matrix
  - Transposed layout: children as columns, rating levels as rows, radio buttons at intersections
  - Per-child "Not in my classroom" checkbox with skip reason dropdown
- "Missing a child?" link at bottom — AJAX auto-saves draft before navigating to Classroom Page
- Render-time reconciliation: new children added, removed children hidden, stale drafts handled
- "Save as Draft" and "Submit" buttons
- On submit: HL Core validates, saves childrows with frozen_age_group/instrument_id/status, updates activity_state
- After submit: read-only summary grouped by age group with skip badges
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

## 5.1 My Track Page
**Shortcode:** `[hl_my_track]`
**Purpose:** Auto-scoped track workspace for School Leaders and District Leaders.

This page automatically detects the logged-in user's enrollment and scopes the view:
- School Leader → sees data for their school within the track
- District Leader → sees data for their entire district within the track
- If user is enrolled in multiple tracks, show a track switcher dropdown at the top

### Header
- Track name
- Org unit scope indicator: "Showing data for [School/District Name]"
- Track status: Active / Completed / Upcoming

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
- Columns: Classroom name, School, Age Band, # Children, Teacher(s) assigned
- Click → navigates to Classroom Page

---

# 6) Page Specifications — Staff/Admin/Coach Pages (CRM Directory)

## 6.1 Districts Listing Page
**Shortcode:** `[hl_districts_listing]`
**Purpose:** CRM-style directory for staff to browse all districts.

Content:
- Grid of District cards
- Each card shows: District name, # of Schools, # of active Tracks
- Click → navigates to District Page

## 6.2 Schools Listing Page
**Shortcode:** `[hl_schools_listing]`
**Purpose:** Flat list of all schools for quick staff navigation.

Content:
- Grid of School cards
- Each card shows: School name, Parent District, School Leader name(s), # of Teams (across tracks)
- Click → navigates to School Page

## 6.3 District Page
**Shortcode:** `[hl_district_page]` with URL parameter `id`
**Purpose:** District-level CRM view showing all tracks and schools in a district.

### Header
- District name
- District logo/image (if available via meta, otherwise placeholder)

### Section: Active Tracks
- List of tracks that include this district (via `hl_track_school` → parent district)
- Each shows: Track name, status (active/completed/upcoming), date range, participant count
- "Open Track" button → navigates to Track Workspace filtered to this district

### Section: Schools
- Grid of School cards within this District
- Each card: School name, School Leader name(s)
- Click → navigates to School Page

### Section: Overview Stats (simple v1)
- Total participants across all active tracks
- Total schools

## 6.4 School Page
**Shortcode:** `[hl_school_page]` with URL parameter `id`
**Purpose:** School-level CRM view.

### Header
- School name
- School logo/image (if available, otherwise placeholder)
- Parent District name (linked to District Page)

### Section: Active Tracks
- List of tracks this school participates in
- Each shows: Track name, status, participant count at this school
- "Open Track" button → navigates to Track Workspace filtered to this school

### Section: Classrooms
- Table of classrooms at this school (across all tracks, or filtered by selected track)
- Columns: Classroom name, Age Band, # Children, Teacher(s) assigned
- Click → navigates to Classroom Page

### Section: Staff
- Table of all users associated with this school (across tracks)
- Columns: Name, Email, Role, Track
- Click → BuddyBoss profile (future) or inline detail

## 6.5 Track Workspace
**Shortcode:** `[hl_track_workspace]` with URL parameter `track_id` and optional `orgunit_id`
**Purpose:** The operational "command hub" for one track. Staff/admin sees everything; when accessed from a District/School page, auto-filters to that org unit.

### Header
- Track name, status, date range
- Scope indicator: "All" or "Filtered to [Org Unit Name]"
- Org unit filter dropdown (for staff who want to narrow scope)

### Tab: Dashboard (v1 — start simple)
- Overall track completion % (average across all participants in scope)
- Participant counts by status:
  - **On Track:** Has completed all activities that are currently unlocked
  - **Behind:** Has unlocked activities that are not yet complete
  - **Not Started:** 0% completion on all activities
- Staff counts: # Teachers, # Mentors, # Schools (in scope)
- Simple bar or progress indicators (no complex charts for v1)

### Tab: Teams
- Same as My Track → Teams tab (section 5.1)

### Tab: Staff
- Same as My Track → Staff tab (section 5.1) but with broader scope for admin/coach

### Tab: Reports
- Same as My Track → Reports tab (section 5.1) but with broader scope
- Additional filter: School/Institution dropdown (when viewing full track)

### Tab: Classrooms
- Same as My Track → Classrooms tab (section 5.1) but with broader scope

---

# 7) Shared Sub-Pages

## 7.1 Team Page
**Shortcode:** `[hl_team_page]` with URL parameter `team_id`
**Purpose:** Team detail view, accessible from track workspace or My Track.

### Header
- Team name
- School name (linked to School Page)
- Breadcrumb: "← Back to [Track Name]" or "← Back to My Track"

### Tab: Team Members
- Table of team members
- Columns: Name, Email, Role (Teacher / Mentor), Completion %
- "View Profile" button per row
- "Add Teacher" button (staff/coach only)

### Tab: Report
- Completion report for team members only
- Same columns and expand behavior as Track Reports tab
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
- Activity Page → "← Back to [Program Name]"
- Team Page → "← Back to [Track/source]"
- Classroom Page → "← Back to [source]"
- School Page → "← Back to [District Name]" (for staff)
- Track Workspace → "← Back to [District/School Name]" (when accessed from CRM)

## 8.2 Sidebar Menu Integration
The following items should appear in the BuddyBoss sidebar menu under "HOUSMAN LEARNING" (or similar section), conditional on user role:

**All enrolled participants:**
- My Programs

**School Leaders, District Leaders:**
- My Programs
- My Track

**Admin, Coach:**
- My Programs (if enrolled)
- School Districts
- Institutions
- (Individual track workspaces accessed via Districts/Schools, not from sidebar)

---

# 9) URL Structure

Use WordPress pages with URL parameters. Recommended slugs:

| Page | Slug | Parameters |
|------|------|-----------|
| My Programs | `/my-programs/` | none |
| Program Page | `/program/` | `?id=X` (pathway_id) |
| Activity Page | `/activity/` | `?id=X&enrollment=Y` |
| My Track | `/my-track/` | optional `?track_id=X` for switcher |
| Districts Listing | `/districts/` | none |
| District Page | `/district/` | `?id=X` |
| Schools Listing | `/institutions/` | none |
| School Page | `/institution/` | `?id=X` |
| Track Workspace | `/track/` | `?id=X` and optional `&orgunit=Y` |
| Team Page | `/team/` | `?id=X` |
| Classroom Page | `/classroom/` | `?id=X` |
| My Coaching | `/my-coaching/` | optional `?track_id=X` |

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
- Districts/Schools: `hl_orgunit` via OrgService
- Cohorts (containers): `hl_cohort` via CohortService
- Tracks (runs): `hl_track` + `hl_track_school` via TrackService
- Teams: `hl_team` + `hl_team_membership` via TeamService
- Staff/Participants: `hl_enrollment` via EnrollmentService
- Pathways & Activities: `hl_pathway` + `hl_activity` via PathwayService
- Activity states: `hl_activity_state` via ActivityStateService
- Completion rollups: `hl_completion_rollup` via ReportingService
- Classrooms: `hl_classroom` + `hl_child_classroom_current` via ClassroomService
- Children: `hl_child` via ClassroomService
- Unlock logic: RulesEngineService (prereqs, drip, overrides)
- Coach assignments: `hl_coach_assignment` via CoachAssignmentService (resolution: enrollment → team → school)
- Coaching sessions: `hl_coaching_session` via CoachingService

Scope filtering must use `HL_Security::assert_can()` and enrollment-based scope resolution.

---

# 12) Implementation Notes

- Implement as WordPress shortcodes registered in HL Core
- Each shortcode checks user capabilities and scope before rendering
- Use show/hide with JS for tab switching (simplest for v1; AJAX optional for large datasets)
- Reports table: implement pagination for large tracks (50+ participants)
- XLSX export: use PhpSpreadsheet or lightweight library (CSV as fallback)
- Reuse existing HL Core services — do NOT write raw SQL in shortcode renderers
- The existing `[hl_my_progress]`, `[hl_team_progress]`, and `[hl_track_dashboard]` shortcodes should be replaced by the new page shortcodes defined here
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
track_id             bigint NOT NULL (FK → hl_track)
effective_from       date NOT NULL
effective_to         date NULL — NULL = currently active
created_at           datetime
updated_at           datetime
```

Indexes: `(track_id, scope_type, scope_id)`, `(coach_user_id)`, `(track_id, coach_user_id)`

## 13.2 Resolution Logic (Most Specific Wins)

To determine "who is User X's coach in Track Y":
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
- **Enrollment detail / BuddyBoss profile (future):** "Coach Override" — creates enrollment`school`-level assignment

Alternatively, a dedicated "Coach Assignments" admin page under HL Core menu showing all current assignments with filter by track.

---

# 14) Coaching Session Model — Expanded

The existing `hl_coaching_session` table needs additional fields to support the participant-facing experience.

## 14.1 Schema Changes to `hl_coaching_session`

New columns:
- `session_title` varchar(255) NULL — display name (e.g. "Coaching Session 1"). If NULL, auto-generates from the pathway activity title.
- `meeting_url` varchar(500) NULL — video call link (Zoom, Teams, etc.)
- `session_status` enum('scheduled', 'attended', 'missed', 'cancelled', 'rescheduled') NOT NULL DEFAULT 'scheduled'
- `cancelled_at` datetime NULL
- `rescheduled_from_session_id` bigint NULL — links to the original session if this is a reschedule
- `cancellation_allowed` — NOT stored here; controlled at track level via `hl_track.settings` JSON (key: `coaching_allow_cancellation`, default: true)

Deprecate: `attendance_status` column is replaced by `session_status`. Migration should map: 'attended' → 'attended', 'missed' → 'missed', 'unknown' → 'scheduled'.

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

## 15.2 My Coaching Page
**Shortcode:** `[hl_my_coaching]`
**Purpose:** Participant view of all their coaching sessions and scheduling.

### Header
- Page title: "My Coaching"
- Coach info card: photo, name, email

### Section: Upcoming Sessions
- List of sessions with `session_status = 'scheduled'` and `session_datetime >= now`
- Each row shows:
  - Session title (e.g. "Coaching Session 1")
  - Date and time (formatted for user's timezone)
  - Coach name
  - Status badge: "Scheduled" (blue)
  - Meeting link button (if `meeting_url` is set): "Join Meeting"
  - Action buttons:
    - "Reschedule" → opens reschedule flow (Phase A: date-time picker; Phase B: MS365 availability)
    - "Cancel" → confirmation dialog → sets status to cancelled (ONLY shown if `coaching_allow_cancellation` is true for the track)

### Section: Past Sessions
- List of sessions with `session_datetime < now` OR terminal status
- Each row shows:
  - Session title
  - Date and time
  - Coach name (frozen — shows the coach who was assigned at the time, not current coach)
  - Status badge: Attended (green), Missed (red), Cancelled (gray), Rescheduled (amber)
  - No action buttons on past sessions

### Section: Schedule New Session (Phase A)
- Button: "Schedule New Session"
- Opens inline form:
  - Session name (auto-populated from next available pathway coaching activity, editable)
  - Date picker + time picker (simple HTML datetime-local input for Phase A)
  - Meeting link (optional, text input — coach can add later)
  - "Confirm" button → creates session with status 'scheduled'
- Phase B enhancement: replace date/time picker with MS365 availability calendar

### Visibility
- Any enrolled participant can see their own sessions
- Coaches see this page scoped to the participant they're viewing (future: via BuddyBoss profile tab)

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

# 16) Sidebar Navigation & Role-Based Menu

The BuddyBoss sidebar menu must be fully role-aware. Menu items shown depend on the user's WP capabilities AND their track enrollment roles. Each menu item links to an existing shortcode page.

## 16.1 Menu Structure by Role

### Admin (manage_hl_core + administrator)
| Menu Item | Target Shortcode | Notes |
|---|---|---|
| Tracks | `[hl_tracks_listing]` | All tracks, searchable. Default: active + future |
| Institutions | `[hl_institutions_listing]` | Combined districts + schools, searchable |
| Coaching | `[hl_coaching_hub]` | All coaching sessions, searchable/filterable |
| Classrooms | `[hl_classrooms_listing]` | All classrooms in active/future tracks |
| Learners | `[hl_learners]` | All participants, searchable, name links to profile |
| Programs | `[hl_pathways_listing]` | All pathways across platform, searchable |
| Reports | `[hl_reports_hub]` | Hub of front-end reports (placeholder for now) |

### Coach (manage_hl_core, coach WP role)
Same as Admin but scoped to tracks where they are assigned as coach:
| Menu Item | Target Shortcode | Scope |
|---|---|---|
| Tracks | `[hl_tracks_listing]` | Tracks where user is assigned coach |
| Institutions | `[hl_institutions_listing]` | Districts/schools where user is assigned coach |
| Coaching | `[hl_coaching_hub]` | Own coaching sessions across all tracks |
| Classrooms | `[hl_classrooms_listing]` | Classrooms in tracks where coach |
| Learners | `[hl_learners]` | Participants in tracks where coach |
| Programs | `[hl_pathways_listing]` | All pathways (same as admin) |
| Reports | `[hl_reports_hub]` | Scoped to coach's tracks |

### District Leader (enrollment role 'district_leader')
| Menu Item | Target Shortcode | Scope |
|---|---|---|
| My Track | `[hl_my_track]` | Auto-navigates to active track |
| My Institutions | `[hl_institutions_listing]` | Districts/schools in their scope |
| Coaching | `[hl_coaching_hub]` | Sessions in tracks where district leader |
| Classrooms | `[hl_classrooms_listing]` | Classrooms in their scope |
| Learners | `[hl_learners]` | Participants in their scope |
| My Programs | `[hl_my_programs]` | Own pathways. Future pathways shown as inactive with countdown |
| Reports | `[hl_reports_hub]` | Scoped to district |

### School Leader (enrollment role 'school_leader')
| Menu Item | Target Shortcode | Scope |
|---|---|---|
| My Institution | `[hl_institutions_listing]` | Own school(s) |
| Coaching | `[hl_coaching_hub]` | Sessions in tracks where school leader |
| Classrooms | `[hl_classrooms_listing]` | Classrooms in their school(s) |
| Learners | `[hl_learners]` | Participants in their school(s) |
| My Programs | `[hl_my_programs]` | Own pathways. Future pathways inactive with countdown |
| Reports | `[hl_reports_hub]` | Scoped to school |

### Mentor (enrollment role 'mentor')
| Menu Item | Target Shortcode | Scope |
|---|---|---|
| My Team | `[hl_my_team]` | Auto-detect team from hl_team_membership → team page |
| Coaching | `[hl_coaching_hub]` | Own coaching sessions |
| My Programs | `[hl_my_programs]` | Own pathways. Future pathways inactive with countdown |
| Reports | `[hl_reports_hub]` | Scoped to team |

### Teacher (enrollment role 'teacher')
| Menu Item | Target Shortcode | Scope |
|---|---|---|
| My Programs | `[hl_my_programs]` | Own pathways. Future pathways inactive with countdown |
| Classrooms | `[hl_classrooms_listing]` | Classrooms where assigned as teacher |

## 16.2 Menu Visibility Rules
- Only show a menu item if the target page exists (shortcode page found via `find_shortcode_page_url()`)
- Staff (`manage_hl_core`) sees ALL items regardless of enrollment
- Admins vs Coaches: both have `manage_hl_core`, differentiate by `current_user_can('manage_options')` for admin vs coach-only scope
- Non-staff users: query `hl_enrollment.roles` JSON for the current user
- A user with multiple roles sees the union of all their role menus (no duplicates)
- Highlight the current/active page in the sidebar
- Detail pages (Program, Activity, Team, Classroom detail) are NOT in the menu — they are navigated to from listing pages

---

# 17) New Shortcode Pages — Listing & Hub Pages

These pages serve both the sidebar navigation and provide comprehensive browseable views.

## 17.1 Tracks Listing — `[hl_tracks_listing]`
**Purpose:** Browse all tracks the user has access to.

**Layout:**
- Search bar (filters by track name or code)
- Status filter: Active (default checked), Future (default checked), Paused, Archived
- Card grid or table: track name, code, status badge, start/end dates, participant count, school count
- Click → Track Workspace (`[hl_track_workspace]?id=X`)

**Scope:**
- Admin: all tracks
- Coach: tracks where assigned as coach (via `hl_coach_assignment`)
- District Leader: tracks where enrolled as district_leader
- School Leader: tracks where enrolled as school_leader

## 17.2 Institutions Listing — `[hl_institutions_listing]`
**Purpose:** Combined view of districts and schools. Replaces the separate `[hl_districts_listing]` and `[hl_schools_listing]` for the sidebar.

**Layout:**
- Search bar
- Toggle: "Districts" / "Schools" / "All" (default: All)
- **Districts section:** Card grid — district name, # schools, # active tracks → click to District Page
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
- Filters: Status (Scheduled/Attended/Missed/Cancelled/Rescheduled), Track, Date range
- Table: Session title, Participant name (link to profile), Coach name, Date/time, Status badge, Meeting link, Actions (reschedule/cancel for scheduled sessions)
- "Schedule New Session" button for staff/coaches

**Scope:**
- Admin: all sessions across all tracks
- Coach: own sessions (where coach_user_id = current user)
- District Leader: sessions in tracks where enrolled, scoped to district
- School Leader: sessions in tracks where enrolled, scoped to school
- Mentor: own sessions only (where mentor_enrollment_id matches)

## 17.4 Classrooms Listing — `[hl_classrooms_listing]`
**Purpose:** Browse classrooms across active/future tracks.

**Layout:**
- Search bar (by classroom name, school name, teacher name)
- Filter: School, Age Band, Track
- Table: Classroom name, School, Age band, # children, Teacher names, Track(s)
- Click → Classroom Page (`[hl_classroom_page]?id=X`)

**Scope:**
- Admin: all classrooms
- Coach: classrooms in tracks where assigned as coach
- District Leader: classrooms in schools under their district
- School Leader: classrooms in their school(s)
- Teacher: classrooms where they have teaching assignments

## 17.5 Learners — `[hl_learners]`
**Purpose:** Searchable participant directory with links to profiles.

**Layout:**
- Search bar (by name, email)
- Filters: Track, School, Team, Role (teacher/mentor/school_leader/district_leader), Status
- Table: Name (link to BuddyBoss profile or user page), Email, Role(s), School, Team, Track, Completion %
- Pagination (25 per page)

**Scope:**
- Admin: all participants
- Coach: participants in tracks where assigned as coach
- District Leader: participants in their district scope
- School Leader: participants in their school scope
- Mentor: team members only

## 17.6 Pathways Listing — `[hl_pathways_listing]`
**Purpose:** Browse all pathways/programs across the platform (staff view).

**Layout:**
- Search bar (by pathway name)
- Filters: Track, Target Role, Status (active/inactive)
- Card grid: Pathway name, track name, target roles, # activities, featured image, avg completion time
- Click → Program Page (`[hl_program_page]?id=X`) or admin edit page

**Scope:**
- Admin/Coach: all pathways
- Not shown to non-staff (they use My Programs instead)

## 17.7 Reports Hub — `[hl_reports_hub]`
**Purpose:** Central hub for front-end reports. Placeholder for v1.

**Layout:**
- Card grid of available report types:
  - Completion Report (link to track workspace reports tab or standalone)
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

---

# 18) Build Priority (Updated)

Original Phases A-C are complete (Phases 7-9 in README build queue).

**Phase D: Coach Assignment + Coaching Enhancement**
1. `hl_coach_assignment` table — DB migration + CoachAssignmentService (CRUD, resolution logic, reassignment with history)
2. Schema changes to `hl_coaching_session` — add session_title, meeting_url, session_status, cancelled_at, rescheduled_from_session_id. Migrate attendance_status → session_status.
3. Admin UI updates — Coach assignment management (school/team/enrollment level). Coaching session form updates (status, meeting_url, title, reschedule/cancel actions).
4. `[hl_my_coaching]` — Participant coaching page with session history, upcoming sessions, schedule/reschedule/cancel buttons (functional with date-time pickers)
5. My Coach widget — on My Programs page
6. Program Page coaching activity enhancement — show session status and scheduling link

**Phase E: MS365 Calendar Integration (future)**
7. Azure AD app registration + OAuth flow for coach calendar consent
8. Coach availability endpoint — reads coach's MS365 calendar via Graph API `/me/calendarView`
9. Booking flow — participant selects available slot → creates session in HL Core + MS365 calendar event for both parties
10. Sync — reschedule/cancel updates propagate to MS365 calendar

**Phase F: BuddyBoss Integration (future)**
11. Custom BuddyBoss profile tab for user management (unchanged from original plan)

---

End of file.
