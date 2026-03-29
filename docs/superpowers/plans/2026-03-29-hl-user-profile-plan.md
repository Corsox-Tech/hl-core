# HL User Profile — Design & Implementation Plan

**Date:** 2026-03-29
**Author:** Mateo Gonzalez + Claude
**Status:** Ready for implementation

> **Session handoff:** Check the boxes below as you complete each phase. Update STATUS.md checkboxes to match. Commit after each phase so the next session can pick up cleanly.

| Phase | Status |
|-------|--------|
| Phase 1: Page + Overview + BB redirect | [x] |
| Phase 2: Progress tab + entry links | [ ] |
| Phase 3: Coaching tab | [ ] |
| Phase 4: Assessments tab | [ ] |
| Phase 5: RP & Observations tab | [ ] |
| Phase 6: Manage tab (admin) | [ ] |
| Phase 7: Wiring + polish | [ ] |
| Phase 8: Documentation updates | [ ] |

---

## Context

The HLA platform currently has no unified user profile page. User data is scattered:
- Coaches see mentor details on a dedicated Coach Mentor Detail page (4 tabs)
- Admins see user data across multiple admin pages (Enrollments, Assessments, etc.)
- School Leaders see only completion % in the Reports tab
- Teachers/Mentors have no way to view their own consolidated progress
- BuddyBoss provides a social profile (avatar + bio) that shows almost no LMS data

When a Coach needs to prepare for a Coaching Session, they can't find a single place that shows a mentor's progress, assessment status, coaching history, and RP sessions. They have to navigate 3-4 different pages.

BuddyBoss profiles exist but are essentially empty — just a photo and 2 fields. Nobody uses BB's social features (activity feed, photos, albums, connections) except forums.

## Goal

Build a single, comprehensive HL User Profile page that:
1. Replaces the BuddyBoss profile as the primary profile for all users
2. Shows role-appropriate information based on who is viewing
3. Consolidates all user data into one page with tabs
4. Provides admin management actions (edit enrollment, assign pathways, etc.)
5. Becomes the default profile link throughout the platform (forum name clicks, staff tables, coach pages, etc.)

## Reasoning

### Why Not BuddyBoss Profiles?
- BB license expires July 2026; planning to evaluate dropping it
- BB profiles are designed for social data, not operational/LMS data
- BB's profile tab API is fragile and poorly documented
- Building on BB locks us deeper into a dependency we may remove
- BB profiles currently show almost nothing useful

### Why Not Two Profiles?
- Confusing UX — coaches would click a name, land on an empty BB profile, then have to click again to reach the HL profile
- No one uses BB profiles for anything meaningful
- Maintaining two profile systems is unnecessary complexity

### Why a Single HL Profile + BB Redirect?
- One profile experience for all users
- HL Core has full control over data and permissions
- Forum name clicks (which go to BB profile URLs) get redirected transparently
- When BB is eventually removed, the redirect just disappears and forum links update to HL URLs
- The existing Coach Mentor Detail page proves this pattern works — it's already a mini-profile

## Architecture

### Page & Routing
- **Shortcode:** `[hl_user_profile]`
- **Page URL:** `/user-profile/` (created via `create-pages` CLI)
- **Parameters:** `?user_id=<wp_user_id>` or `?enrollment_id=<enrollment_id>`
- **No parameter:** Shows current user's own profile
- **Class:** `HL_Frontend_User_Profile` in `includes/frontend/class-hl-frontend-user-profile.php`

### BB Profile Redirect
- In `HL_BuddyBoss_Integration`, hook `template_redirect`
- When a BB member profile page is loaded, redirect to `/user-profile/?user_id=<displayed_user_id>`
- This makes forum name clicks, @mentions, and any BB profile links land on the HL profile

### Access Control Rules
Only these users can view someone's profile:
- **The user themselves** (always can see own profile)
- **Admins/Staff** (`manage_hl_core`) — can see any profile
- **Coaches** — can see profiles of their assigned mentors (via `hl_coach_assignment`)
- **Mentors** — can see profiles of their team members (via `hl_team_membership`)
- **School Leaders** — can see profiles of staff in their school (via enrollment `school_id`)
- **Everyone else** — access denied

### Tab Structure & Visibility

| Tab | What It Shows | Self | Admin | Coach | Mentor | School Leader |
|-----|--------------|:----:|:-----:|:-----:|:------:|:-------------:|
| **Overview** | Photo, name, email, school, district, role(s), classroom(s), assigned coach/mentor, enrollment dates, demographic data | Yes | Yes (all fields) | Yes (assigned) | Yes (team) | Yes (school) |
| **Progress** | Pathway enrollments, component-by-component completion with status badges, LearnDash course %, overall completion | Yes | Yes | Yes (assigned) | Yes (team) | Yes (school) |
| **Coaching** | Coaching sessions (upcoming/past), session details, action plans, next session scheduling link | Yes | Yes | Yes (assigned) | No | No |
| **Assessments** | TSA completion status. TSA responses (staff/coach only until teacher consent obtained). CA completion status. | Status only | Full + responses | Full + responses | Status only | Status only |
| **RP & Observations** | Self-reflections, classroom visits, RP session notes, observation records | Yes | Yes | Yes (team) | No | No |
| **Manage** | Edit profile fields, assign/unassign pathways, manage enrollment (activate/deactivate), change school, send password reset email, send welcome email | No | Yes | No | No | No |

### Data Sources (Existing Services to Reuse)

| Data | Service/Repository | Key Method(s) |
|------|-------------------|---------------|
| User basics | `wp_users` + `HL_Enrollment_Repository` | `get_all(['user_id' => X])` |
| School/District | `HL_OrgUnit_Repository` | `get_by_id()` |
| Coach assignment | `HL_Coach_Assignment_Service` | `get_coach_for_enrollment()` |
| Team membership | `HL_Team_Repository` | `get_members()`, team from enrollment |
| Pathway assignments | `HL_Pathway_Assignment_Service` | `get_assignments_for_enrollment()` |
| Component completion | `HL_Reporting_Service` | `get_cycle_component_detail()`, `get_enrollment_completion()` |
| Coaching sessions | `HL_Coaching_Service` | `get_sessions(['enrollment_id' => X])` |
| TSA instances | `HL_Assessment_Service` | `get_teacher_assessments($enrollment_id)` |
| CA instances | `HL_Assessment_Service` | `get_child_assessments($enrollment_id)` |
| TSA responses | `HL_Assessment_Service` | `get_teacher_assessment($instance_id)` — `responses_json` |
| RP sessions | `HL_RP_Session_Service` | Existing query methods |
| Classroom visits | `HL_Classroom_Visit_Service` | Existing query methods |
| Self-reflections | `HL_Classroom_Visit_Service` | Filtered by teacher enrollment |
| Classrooms | `HL_Classroom_Service` | Via `hl_teaching_assignment` |
| Completion rollups | `hl_completion_rollup` table | Direct query |

### Entry Points (Where Profile Links Appear)

These existing pages should link user names to the HL profile:

1. **Reports tab** (My School page) — teacher names become clickable
2. **Staff tab** (My School page) — teacher names become clickable
3. **Coach Mentor Detail** — either replace entirely with HL profile, or link to it
4. **Coach Mentors grid** — mentor cards link to profile
5. **Team Page** — member names link to profile
6. **Classrooms** — teacher names link to profile
7. **Admin Enrollments** — user names link to profile (in addition to Edit)
8. **Forum posts** — via BB redirect (automatic)
9. **Dashboard cards** — where applicable

---

## Implementation Phases

### Phase 1: Page Foundation + Overview Tab
**Goal:** Create the profile page with the Overview tab and BB redirect.

1. Create `includes/frontend/class-hl-frontend-user-profile.php`
   - Constructor: inject enrollment repo, orgunit repo, scope service
   - `render($atts)` method: resolve user, check access, determine tabs, render active tab
   - `render_overview_tab()`: user photo (WP avatar), name, email, school, district, role(s), classroom(s), assigned coach/mentor, enrollment dates
   - Access control: check if current viewer can see this profile (use scope service + enrollment data)
   - Tab system: use URL-based tabs like My Cycle page (`?tab=overview`, `?tab=progress`, etc.)

2. Register shortcode `[hl_user_profile]` in `class-hl-shortcodes.php`

3. Add page creation to `class-hl-cli-create-pages.php` — "User Profile" with `[hl_user_profile]`

4. Add BB profile redirect in `class-hl-buddyboss-integration.php`
   - Hook `template_redirect`, check if on a BP member profile page
   - Redirect to `/user-profile/?user_id=<displayed_user_id>`
   - Skip redirect for admins if a query param `?bb=1` is set (escape hatch for debugging)

5. Run `create-pages` on test and production to create the page

6. **Test:** Click a user name in forum → should land on HL profile Overview tab

### Phase 2: Progress Tab
**Goal:** Show pathway enrollment and component-by-component completion.

1. `render_progress_tab()` in the profile class
   - List all active enrollments for this user (cycle name, dates, role)
   - For each enrollment: pathway name, component list with completion %, status badges
   - Reuse `HL_Reporting_Service::get_cycle_component_detail()` for data
   - Show LearnDash course completion % where applicable
   - Overall completion rollup

2. Make user names clickable in the **Reports tab** and **Staff tab** on My School page
   - Link `display_name` to `/user-profile/?user_id=X`
   - Only when viewer has permission to see the target profile

3. **Test:** As school leader, click a teacher name in Reports → see their Progress tab with component completion

### Phase 3: Coaching Tab
**Goal:** Show coaching sessions, action plans, and scheduling.

1. `render_coaching_tab()` in the profile class
   - List coaching sessions (upcoming and past) with status, date, coach name, zoom link
   - Show action plan submissions (domain, skills, results)
   - "Schedule Next Session" link (if applicable)
   - Reuse data from `HL_Coaching_Service`

2. Evaluate whether **Coach Mentor Detail** page should be deprecated in favor of this profile page
   - If yes: redirect Coach Mentor Detail to HL profile with `?tab=coaching`
   - If no: keep both but ensure consistency

3. **Test:** As coach, navigate to a mentor's profile → see their Coaching tab with sessions

### Phase 4: Assessments Tab
**Goal:** Show assessment completion and responses (with permission gating).

1. `render_assessments_tab()` in the profile class
   - TSA: list instances (PRE/POST) with status, submitted date
   - TSA responses: show the read-only form (reuse `HL_Teacher_Assessment_Renderer` with `read_only=true`)
   - CA: list instances with classroom, age band, children count, status
   - Permission gating: responses visible only to admin/coach (until teacher consent obtained)
   - Reuse the Assessments tab code from My School page as a starting point

2. **Test:** As admin, view a teacher's profile → see Assessments tab with response data

### Phase 5: RP & Observations Tab
**Goal:** Show self-reflections, classroom visits, RP sessions.

1. `render_rp_tab()` in the profile class
   - Self-reflection submissions: list with date, status, link to view
   - Classroom visit submissions: list with date, observer, status
   - RP session notes: list with date, session number, mentor/coach
   - Reuse data from `HL_RP_Session_Service`, `HL_Classroom_Visit_Service`

2. **Test:** As coach, view a mentor's profile → see their RP sessions and classroom visits

### Phase 6: Manage Tab (Admin Only)
**Goal:** Admin actions for managing users.

1. `render_manage_tab()` in the profile class (admin only)
   - Edit basic profile fields (display name, email) — POST handler to update
   - Assign/unassign pathways — dropdown + add/remove with AJAX or form POST
   - Manage enrollment: change status (active/inactive), change school, change roles
   - Send password reset email — button that triggers WP password reset
   - Send welcome/invite email — button that triggers custom email
   - Danger zone: deactivate enrollment, remove user from cycle

2. **Test:** As admin, view a teacher's profile → Manage tab → change their school → verify enrollment updated

### Phase 7: Entry Point Wiring + Polish
**Goal:** Make profile links appear everywhere and polish the UX.

1. Wire up profile links across all pages:
   - Reports tab (My School): teacher names → profile
   - Coach Mentors grid: mentor cards → profile
   - Team Page: member names → profile
   - Classrooms listing: teacher names → profile
   - Admin Enrollments: add "View Profile" button
   - Sidebar: add "My Profile" link for all enrolled users

2. Add breadcrumb navigation on profile page (e.g., "My School > Staff > Alina Bach Porro")

3. CSS polish: ensure consistent styling with rest of HL Core frontend

4. Mobile responsive: verify profile works on mobile screens

### Phase 8: Documentation & Reference Updates
**Goal:** Update all project documentation to reflect the new profile system.

1. **Find and update** all documentation files that reference:
   - User profile / BuddyBoss profiles
   - Coach Mentor Detail page (if deprecated/redirected)
   - Navigation structure / page listings
   - Role-based visibility rules

2. Files likely needing updates (the implementing session should audit these):
   - `README.md` — "What's Implemented" section, architecture tree
   - `STATUS.md` — Add to build queue, mark completed phases
   - `docs/03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md` — Add profile visibility rules
   - `docs/B2E_MASTER_REFERENCE.md` — If it references user views
   - `.claude/skills/architecture.md` — Update page/feature inventory

3. Update the HLA Page Audit XLSX (or regenerate) to include the new profile page

4. Add inline code comments explaining the access control logic

---

## Key Design Decisions

### 1. Multi-Enrollment Profiles
A user can have multiple enrollments (e.g., ELCPB Y1 + Y2, or LSF + Beginnings). The profile should:
- Show a cycle selector (like My School page) if the user has multiple enrollments
- Default to the most recent active enrollment
- Progress, coaching, assessments scoped to the selected cycle/enrollment

### 2. Coach Mentor Detail Deprecation
The existing Coach Mentor Detail page (`hl_coach_mentor_detail`) overlaps significantly with the HL profile. Options:
- **Option A:** Keep both — Coach Mentor Detail as a coach-specific quick view, HL profile as the full view
- **Option B:** Redirect Coach Mentor Detail to HL profile with `?tab=coaching` — fewer pages to maintain
- **Recommendation:** Start with Option A (less risky), migrate to Option B later if the profile proves sufficient

### 3. BB Redirect Scope
The redirect should apply to ALL BB profile views, not just enrolled users. This ensures a consistent experience. For users without HL enrollment, the HL profile shows an "empty state" with basic WP user info.

### 4. Assessment Response Visibility
Currently gated behind `manage_hl_core` (staff only) because teacher consent hasn't been obtained. The profile should respect the same gate. When consent is obtained, the gate can be lowered to include coaches and potentially school leaders.

---

## Testing Checklist

For each phase, the implementing session should verify:

- [ ] Access control: unauthorized users see "Access denied" (not empty page)
- [ ] Own profile: every enrolled user can see their own profile
- [ ] Coach → mentor profile: shows correct data, only assigned mentors accessible
- [ ] Mentor → team member profile: shows correct data, only team members accessible
- [ ] School leader → school staff profile: shows correct data, only school staff accessible
- [ ] Admin → any profile: all tabs visible, manage tab functional
- [ ] Non-enrolled user profile: shows empty state, doesn't crash
- [ ] BB forum name click → redirects to HL profile (not BB profile)
- [ ] Mobile responsive: tabs and content readable on phone screen
- [ ] Multi-enrollment: cycle selector works, data changes per enrollment
- [ ] Direct URL access: `/user-profile/?user_id=999999` (non-existent) shows error gracefully

---

## Dependencies

- Existing HL Core services (all listed in Data Sources above) — no new services needed for Phase 1-2
- `HL_Teacher_Assessment_Renderer` — needed for Phase 4 (read-only form view)
- BuddyBoss `template_redirect` hook — needed for Phase 1 (BB redirect)
- `wp hl-core create-pages` — needed to create the profile page

## Estimated Effort

| Phase | Scope | Estimated Sessions |
|-------|-------|-------------------|
| Phase 1 | Page + Overview + BB redirect | 1 session |
| Phase 2 | Progress tab + entry point links | 1 session |
| Phase 3 | Coaching tab | 1 session |
| Phase 4 | Assessments tab | 1 session |
| Phase 5 | RP & Observations tab | 1 session |
| Phase 6 | Manage tab (admin) | 1 session |
| Phase 7 | Wiring + polish | 1 session |
| Phase 8 | Documentation updates | 0.5 session |

Total: ~7 sessions. Phases 1-2 deliver the most immediate value (overview + progress). Phases can be done incrementally.
