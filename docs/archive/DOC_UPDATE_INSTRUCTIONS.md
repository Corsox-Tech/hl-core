# Documentation Update Instructions

**Status:** COMPLETED (March 2026). All docs updated through V3 Grand Rename. This file is retained for historical context only.

**Source of Truth:** `B2E_MASTER_REFERENCE.md` (March 2026 planning session)
**Purpose:** File-by-file instructions for updating all 11 spec docs, CLAUDE.md, and README.md to reflect the new architecture. These instructions were written for V2 (Phase entity, Track Types). V3 Grand Rename subsequently changed all entity names: Track→Cycle, Cohort→Partnership, Activity→Component, Phase entity deleted.

> **RULE:** When B2E_MASTER_REFERENCE.md conflicts with existing docs, the MASTER REFERENCE WINS.

---

## What's Changing (Summary)

### New Entities
1. **Phase** (`hl_phase`) — time-bounded period within a Track grouping Pathways
2. **Individual Enrollment** (`hl_individual_enrollment`) — user-to-LearnDash-course for standalone purchases
3. **Track Type** (`track_type` column on `hl_track`) — `program` or `course`

### Revised Definitions
- **Track** — now means "full program engagement" (not "time-bounded run per phase")
- **Cohort** — clarified as optional (not required for control groups or any Track)
- **Pathway** — now belongs to Phase (not directly to Track)

### New Context (never documented before)
- B2E Mastery Program product description (25 courses, 3 Learning Plans, 2 Phases)
- Short Courses and ECSELent Adventures Curriculum products
- How each product maps to HL Core entities
- Pilot program variability

### Stale References to Fix
- Doc 00 §3: "custom form rendering for teacher self-assessments" listed as non-goal — IS implemented
- Doc 00 §4.2: Says teacher self-assessments use JFB — they use custom PHP since Phase 19
- Doc 04 §3.2.2: Title says "JetFormBuilder-powered" for teacher self-assessment — wrong
- Doc 09 §0 Rules: Says "Use JetFormBuilder for static questionnaire forms (teacher self-assessment, observations)" — teacher assessments no longer use JFB

### What Does NOT Change
- Enrollment stays at Track level (User ↔ Track)
- Team stays at Track level (can span Phases)
- School/District/Classroom hierarchy — no change
- Assessment system (TSA, Child Assessment) — no change
- Coaching system — no change
- Import system — no change
- Security/Scope model — no change
- BuddyBoss integration — minimal change

---

## File-by-File Instructions

---

### FILE 1: `00_README_SCOPE.md`

**Changes needed: 5**

#### 1A. §1 Purpose — Add product context
After "The system is **B2B only**..." paragraph, add a new paragraph:

> **Products managed by HL Core:** The B2E Mastery Program (2-year, 25-course professional development — full Track management with Phases, Pathways, Teams, Coaching, Assessments); Short Courses (standalone 2-3 hour courses — institutional purchase uses simple Track, individual purchase uses Individual Enrollment); ECSELent Adventures Curriculum online training (same model as Short Courses). See `B2E_MASTER_REFERENCE.md` §1 for full product catalog.

#### 1B. §2 Critical Definitions — Revise Track
Replace the Track definition:
- OLD: "A Track is a time-bounded run/implementation within a Cohort."
- NEW: "A Track represents the full program engagement for a district/institution. For B2E Mastery, this spans the entire multi-year contract (all Phases). A Track contains one or more Phases, each containing Pathways. Track is the level at which participants are enrolled, teams are formed, and scope is defined."

Add `track_type` to Track fields:
> - track_type (program or course; see B2E_MASTER_REFERENCE.md §3.4)

#### 1C. §2 Critical Definitions — Clarify Cohort
Add after "A Cohort is the contract/container entity...":
> Cohort is optional — Tracks can exist without a Cohort (`cohort_id` is nullable). Useful for visual organization or comparison reporting, but not required for any Track to function.

#### 1D. §3 Non-Goals — Fix teacher self-assessment line
Remove or strike through:
- OLD: "custom form rendering for teacher self-assessments or observations (JetFormBuilder handles those)"
- NEW: "custom form rendering for observations (JetFormBuilder handles those; teacher self-assessments and child assessments use HL Core's custom PHP instrument system — see doc 06)"

Also remove: "individual consumer enrollment flows (future scope only)"
Replace with: "Individual consumer enrollment is now supported via `hl_individual_enrollment` — see B2E_MASTER_REFERENCE.md §5"

#### 1E. §4.2 JetFormBuilder — Fix teacher self-assessment reference
Replace the §4.2 description to match reality:
- OLD: "**Teacher Self-Assessments** (pre/post) — Admin builds the form in JFB; HL Core links it to an Activity and tracks completion"
- NEW: "**Observations** — Admin builds the observation form in JFB; HL Core manages the observation record (who observed whom) and tracks completion. Teacher self-assessments now use HL Core's custom PHP instrument system (see doc 06) — NOT JetFormBuilder."

Remove teacher self-assessments from the JFB bullet list entirely. Only observations should remain.

#### 1F. §10 Priority of Truth — Add B2E Master Reference
Add at the top of the priority list:
> 0) B2E_MASTER_REFERENCE.md (authoritative when conflicts exist with any doc below)

---

### FILE 2: `01_GLOSSARY_CANONICAL_TERMS.md`

**Changes needed: 4**

#### 2A. §1.1 Cohort — Add "CLARIFIED" note
After the definition, add:
> **Clarification (March 2026):** Cohort is optional. `hl_track.cohort_id` is nullable — Tracks can exist without a Cohort. The control group research workflow does NOT require Cohort grouping; statistical comparison (Cohen's d) happens in Stata from CSV exports. Cohort remains useful for admin organization and optional in-app comparison reporting.

#### 2B. §1.2 Track — Revise definition
Replace the existing Track definition:
- OLD: "A Track is a time-bounded run/implementation within a Cohort, containing participants, configuration, learning requirements, and reporting."
- NEW: "A Track represents the full program engagement for a district/institution. For the B2E Mastery Program, this spans the entire multi-year contract (all Phases). A Track contains one or more Phases, each containing Pathways. Track is the level at which participants are enrolled, teams are formed, and scope is defined."

Add new fields to the "Track contains" list:
> - Phases (time-bounded periods within the Track)

Add to "Minimum required fields":
> - track_type (enum: program, course; default program)

Update FK note:
- OLD: "cohort_id (FK to Cohort container)"
- NEW: "cohort_id (FK to Cohort container, **nullable** — Track can exist without a Cohort)"

#### 2C. §6.1 Pathway — Revise definition
Replace:
- OLD: "A configurable set/graph of required Activities assigned to Participants in a Track."
- NEW: "A configurable set/graph of required Activities assigned to Participants. Pathways belong to a Phase (not directly to a Track). A Phase typically has 3 Pathways (Teacher, Mentor, Leader), though this is configurable. For course-type Tracks, the Pathway is auto-generated."

Update properties:
- OLD: "Defined per Track"
- NEW: "Defined per Phase (Phase belongs to Track)"

#### 2D. Add new glossary terms — Insert new section after §1.5
Add a new section "§1.6 Product & Program Terms" containing these entries (verbatim from B2E_MASTER_REFERENCE.md §13):

- **B2E Mastery Program** — The primary professional development product sold by Housman Learning Academy to school districts and institutions. A 2-year (minimum) program consisting of 25 LearnDash courses organized into 3 Learning Plans across 2 Phases.

- **Phase** — A time-bounded period within a Track that groups related Pathways. For the B2E Mastery Program, Phase 1 = Year 1 courses and Phase 2 = Year 2 courses. Tracks may have 1 or more Phases. Stored in `hl_phase`.

- **Learning Plan** — Housman's client-facing term for what HL Core calls a Pathway. There are 3 Learning Plans: Teacher, Mentor, and Leader (Streamlined). The frontend may use "Learning Plan" or "Program" as labels.

- **Course Catalog** — The complete set of LearnDash courses available in the B2E Mastery Program: TC0 (welcome), TC1–TC8 (full teacher), TC1(S)–TC8(S) (streamlined teacher), MC1–MC4 (full mentor), MC1(S)–MC4(S) (streamlined mentor). Total: 25 courses.

- **Pilot** — A variable-scope program engagement where a district tries a subset of the B2E program before committing to the full contract. Can range from TC1–TC3 + MC1 to the entire Phase 1.

- **Individual Enrollment** — A direct user-to-LearnDash-course association managed by HL Core for individual (non-institutional) course purchases. Stored in `hl_individual_enrollment`. Supports per-person expiration dates.

- **Track Type** — A classification on `hl_track` distinguishing between full program Tracks (`program`) and simple single-course institutional Tracks (`course`). Course-type Tracks auto-generate a single Phase + Pathway + Activity.

---

### FILE 3: `02_DOMAIN_MODEL_ORG_STRUCTURE.md`

**Changes needed: 5**

#### 3A. §1.2 Cohort — Add optional clarification
After "Groups one or more Tracks together":
> Cohort is optional. Tracks can exist without a Cohort (`cohort_id` is nullable). Useful for organizational grouping or comparison reporting, but not required.

Remove or revise the example that implies Cohort is required:
> Example remains valid as an illustration, but add: "A Track can also exist without any Cohort."

#### 3B. §1.3 Track — Revise and add track_type
Replace:
- OLD: "A time-bounded implementation/run within a Cohort (formerly 'Cohort')."
- NEW: "The full program engagement for a district/institution. Contains one or more Phases, each containing Pathways. For the B2E Mastery Program, a Track spans the entire multi-year contract."

Add new field:
> Track flags:
> - track_type (enum: 'program', 'course'; default 'program') — 'program' for full B2E with Phases, Pathways, Teams, etc. 'course' for simple institutional course access (auto-creates one Phase + one Pathway + one Activity)
> - is_control_group (boolean, default false) — [existing text stays]

#### 3C. Add new entity: §1.X Phase (insert after §1.3 Track)
Add new section:

> ## 1.X Phase
> A time-bounded period within a Track that groups Pathways. Represents a year or segment of the program.
>
> Phase relationships:
> - Phase.track_id → Track
> - Phase has many Pathways (via Pathway.phase_id)
>
> Phase fields:
> - phase_id (PK)
> - phase_uuid (CHAR 36)
> - track_id (FK → hl_track)
> - phase_name (VARCHAR 200)
> - phase_number (INT, unique per track)
> - start_date, end_date (DATE, nullable)
> - status (enum: upcoming, active, completed)
> - created_at, updated_at
>
> Notes:
> - Program-type Tracks: admin creates Phases manually (typically Phase 1 + Phase 2)
> - Course-type Tracks: system auto-creates one Phase
> - Pathways belong to Phase, not directly to Track

#### 3D. Add new entity: §1.X Individual Enrollment (insert at end of entity list)
Add new section:

> ## 1.X IndividualEnrollment
> Direct user-to-LearnDash-course association for standalone individual purchases (not institutional).
>
> IndividualEnrollment relationships:
> - IndividualEnrollment.user_id → WP User
> - IndividualEnrollment.course_id → LearnDash Course (post ID)
>
> IndividualEnrollment fields:
> - id (PK)
> - user_id, course_id
> - enrolled_at (datetime)
> - expires_at (datetime, nullable)
> - status (enum: active, expired, revoked)
> - enrolled_by (user_id, nullable)
> - notes (text, nullable)
> - created_at, updated_at
>
> Constraint:
> - Unique per (user_id, course_id)
>
> Notes:
> - Used for Short Courses and ECSELent Adventures individual purchases
> - Supports per-person expiration dates (unlike LearnDash's global expiration)
> - Frontend: "My Courses" section on Dashboard shows active individual enrollments

#### 3E. §2 Relationship Diagram — Update
Update the text diagram to include Phase between Track and Pathways:

```
Track (within a Cohort or standalone; track_type: program or course)
  ├── Phase [1..n] (time period)
  │     └── Pathway [1..n per Phase]
  │           └── Activity [0..n]
  ├── Enrollment [0..n] (User ↔ Track)
  │     ├── PathwayAssignment [1..n] → Pathway (in a Phase)
  │     ├── TeamMembership [0..1 per Track] → Team
  │     └── TeachingAssignment [0..n] → Classroom
  ...
```

Add at the bottom:
```
IndividualEnrollment (User ↔ LearnDash Course, standalone, no Track)
```

#### 3F. §5.3 Track Identity Keys — Add track_type
Add to the Track identity fields:
> - track_type (enum: program, course; default program)

---

### FILE 4: `03_ROLES_PERMISSIONS_REPORT_VISIBILITY.md`

**Changes needed: 1 (minimal)**

#### 4A. §4.1 Track & Configuration capabilities — Add Phase management
Add new capability:
> - phase.manage (create/edit/delete Phases within a Track)

No other changes needed — roles, scopes, and permissions model are unchanged.

---

### FILE 5: `04_COHORT_PATHWAYS_ACTIVITIES_RULES.md`

**Changes needed: 4**

#### 5A. §1.1 Track Configuration Layers — Insert Phase
Replace the 4-layer list:
- OLD: 1) Track → 2) Pathways within Track → 3) Activities → 4) Unlock rules
- NEW: 1) Track → 2) **Phases within the Track** → 3) Pathways within each Phase → 4) Activities within each Pathway → 5) Unlock rules

#### 5B. §2.1 Pathway Definition — Add phase_id
Replace:
- OLD: `track_id`
- NEW: `phase_id` (FK → hl_phase; Phase belongs to Track)

Add note:
> Pathways no longer belong directly to a Track — they belong to a Phase. To get all pathways for a Track: join Phase → Pathway.

#### 5C. §3.2.2 Teacher Self-Assessment Activity — Fix title and content
Replace title:
- OLD: "Teacher Self-Assessment Activity (JetFormBuilder-powered)"
- NEW: "Teacher Self-Assessment Activity (Custom PHP Instrument System)"

Replace content to match reality:
- activity_type = "teacher_self_assessment"
- external_ref: `{"teacher_instrument_id": <HL instrument ID>, "phase": "pre"|"post"}`
- Legacy fallback: `{"form_plugin": "jetformbuilder", "form_id": <JFB form ID>, "phase": "pre"|"post"}`
- Rendered by HL_Teacher_Assessment_Renderer using structured instrument definitions from `hl_teacher_assessment_instrument`
- Responses stored in `hl_teacher_assessment_instance.responses_json`
- PRE: single-column; POST: Section 1 has dual-column retrospective

Remove or move the JFB admin workflow description (steps 1-4) to a "Legacy JFB" subsection.

#### 5D. §8 Data Needed — Add Phase CRUD
Add to the list:
> - CRUD for Phases (per Track)
> - Phase → Pathway relationship management
> - Auto-Phase creation for course-type Tracks

---

### FILE 6: `05_UNLOCKING_LOGIC_PREREQS_DRIP_OVERRIDES.md`

**Changes needed: 1 (minimal)**

#### 6A. §1.1 Activity Availability — Add Phase context note
Add a note:
> Note: Activities belong to Pathways, which belong to Phases, which belong to Tracks. Availability is still evaluated per (enrollment_id, activity_id). The Phase layer does not affect unlock logic — it is a structural grouping only.

No other changes needed — the unlock logic itself is unchanged.

---

### FILE 7: `06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md`

**Changes needed: 2 (minimal — this doc is already mostly current)**

#### 7A. §6.1 Control Group Purpose — Add clarification
After the existing control group pathway description, add:
> **Important clarification:** The control group is NOT tied to one specific client/program. It exists as an independent research asset that can be compared against ANY or MULTIPLE program Tracks. The statistical comparison (Cohen's d) happens in **Stata**, not in WordPress. HL Core's job is to store control group assessment data and export it as CSV. The Cohort-based comparison reports (§6.3) are a nice-to-have convenience, not the critical path for research.

#### 7B. §6.3 Comparison Reporting — Add Stata clarification
Add note after the existing comparison reporting section:
> **Primary research workflow:** Assessment data is exported as CSV from HL Core → imported into Stata for statistical analysis (Cohen's d, etc.). The in-app comparison report is supplementary.

---

### FILE 8: `07_IMPORTS_ROSTERS_IDENTITIES_MATCHING.md`

**Changes needed: 1 (minimal)**

#### 8A. §1.1 Participants Import — Add Phase context
Add note:
> When importing participants for a program-type Track, pathway assignment should specify which Phase the participant's pathway belongs to. For course-type Tracks, pathway assignment is automatic (single auto-generated pathway).

No other changes — the import system itself is unchanged.

---

### FILE 9: `08_REPORTING_METRICS_VIEWS_EXPORTS.md`

**Changes needed: 3**

#### 9A. §1 Canonical Reporting Outputs — Add Program Progress Matrix
Add new report type:

> 8) **Program Progress Matrix (Staff + Leaders)** — Course-by-course completion grid for all participants in a Track. Rows = participants, Columns = all B2E courses (TC0–TC8, MC1–MC4). Values: ✓ (completed), X% (in progress), empty (not started), – (not applicable for role/pathway). Filters: Phase, School, Team, Role. Export: CSV. See B2E_MASTER_REFERENCE.md §8.1.

#### 9B. Add Phase filter section
Add new section after §2:

> ## 2.1 Phase-Aware Reporting
> All reports that operate at the Track level should support a **Phase filter**:
> - "Show me Phase 2 progress" → filter to Phase 2 pathways and activities
> - "Show me full program" → aggregate across all Phases
> - Default view: currently active Phase
>
> Phase filtering applies to: Track Dashboard, District Report, School Report, Team Report, Participant Report, and the new Program Progress Matrix.

#### 9C. §5.7 Program vs Control Comparison — Add Stata note
Add note:
> **Primary analysis workflow:** The critical path for research comparison is CSV export → Stata. The in-app comparison report is a supplementary convenience view. The control Track does NOT need to be in the same Cohort as the program Track for the CSV export workflow to function.

---

### FILE 10: `09_PLUGIN_ARCHITECTURE_CONSTRAINTS_ACCEPTANCE_TESTS.md`

**Changes needed: 4**

#### 10A. §0 Rules — Fix JFB reference
Replace line 23:
- OLD: "Use JetFormBuilder for static questionnaire forms (teacher self-assessment, observations); use custom PHP for dynamic forms (child assessment) and admin CRUD (coaching sessions)."
- NEW: "Use custom PHP instrument system for teacher self-assessments and child assessments; use JetFormBuilder for observations only (mentor-submitted forms); use custom PHP admin CRUD for coaching sessions."

#### 10B. §1 Plugin Structure — Add new services
Add to the services list:
> - PhaseService (Phase CRUD, get_phases_for_track, get_active_phase, auto-create for course Tracks)
> - IndividualEnrollmentService (CRUD, expiration checks, LearnDash progress queries)

#### 10C. §2.2 Minimum Tables — Add new tables
Add to the "Org + Cohort + Track" section:
> - hl_phase (Phase within Track)

Add new section:
> Individual Enrollments:
> - hl_individual_enrollment (user ↔ LearnDash course for standalone purchases)

Add to Track table description:
> - hl_track now has `track_type` column (enum: program, course)

Update Pathway table note:
> - hl_pathway now has `phase_id` (FK → hl_phase) instead of direct `track_id`

#### 10D. §4 Admin UI — Add new pages
Add:

> X) Phases (within Track editor, for program-type Tracks)
> - List phases with name, number, dates, status
> - Click into Phase to see/edit its Pathways
> - Course-type Tracks skip this — Phase is auto-managed

> X) Individual Enrollments (2 pages)
> - Course List: shows LearnDash courses that have individual enrollments, with counts
> - Course Detail: enrollment table with add/edit/revoke actions, bulk CSV import

---

### FILE 11: `10_FRONTEND_PAGES_NAVIGATION_UX.md`

**Changes needed: 3**

#### 11A. §3.1.1 Dashboard — Add "My Courses" section
In the Participant Section table, add:

> | My Courses | Has active individual enrollment(s) | Individual enrollment course cards |

Add description:
> **My Courses section** (below the Track-based participant cards): Shows individual enrollment course cards for standalone purchases. Each card: course name, completion %, "Continue Course" button → LD course URL. Expired enrollments show "Access Expired" badge. Only shown if user has entries in `hl_individual_enrollment` with status='active'.

#### 11B. §4.1 My Programs — Add Phase context
Add to each pathway card's display:
> - Phase name (e.g., "Phase 1" or "Year 1") if the Track has multiple Phases

#### 11C. §5.1 My Track → Reports tab — Add Phase filter
Add to the Filters list:
> - Phase dropdown (for program-type Tracks with multiple Phases)

---

### FILE 12: `CLAUDE.md` (project root)

**Changes needed: 6**

#### 12A. Project Overview — Add product catalog context
After "The primary development target is the **hl-core** custom plugin" add:

> **Products managed by HL Core:**
> - **B2E Mastery Program** — 2-year, 25-course professional development (full Track management with Phases, Pathways, Teams, Coaching, Assessments)
> - **Short Courses** — Standalone 2-3 hour courses (institutional: simple Track; individual: Individual Enrollment)
> - **ECSELent Adventures Curriculum** — Physical product with online training course (same model as Short Courses)
>
> See `docs/B2E_MASTER_REFERENCE.md` for the complete product catalog and course listings.

#### 12B. Add Phase Entity section
Add new section after "Coach Assignment Architecture":

> ## Phase Entity (NEW)
>
> A Phase is a time-bounded period within a Track that groups Pathways. For B2E Mastery: Phase 1 = Year 1 courses, Phase 2 = Year 2 courses.
>
> **Key points:**
> - Stored in `hl_phase` table (phase_id, track_id, phase_name, phase_number, start/end dates, status)
> - Pathways belong to Phase (not directly to Track): `hl_pathway.phase_id` → `hl_phase`
> - Program-type Tracks: admin creates Phases manually
> - Course-type Tracks: system auto-creates one Phase + one Pathway + one Activity
> - Enrollment stays at Track level — Phase is structural grouping only
> - Teams also stay at Track level (can span Phases)

#### 12C. Add Track Types section
Add new section:

> ## Track Types
>
> `hl_track.track_type` distinguishes complexity levels:
>
> | Type | Usage | Complexity |
> |------|-------|-----------|
> | `program` | B2E Mastery and similar multi-phase programs | Full: Phases, Pathways, Teams, Coaching, Assessments |
> | `course` | Institutional short course purchases | Minimal: auto-created single Phase + Pathway + Activity |
>
> Course-type Tracks hide: Phase management, Teams tab, Coaching tab, Assessment tabs, Pathway editor.

#### 12D. Add Individual Enrollments section
Add new section:

> ## Individual Enrollments
>
> For individual (non-institutional) course purchases. Stored in `hl_individual_enrollment` (user_id, course_id, enrolled_at, expires_at, status).
>
> - Admin pages: Course List + Course Detail (under HL Core menu)
> - Frontend: "My Courses" section on Dashboard for active individual enrollments
> - Expiration enforcement: blocks access when expires_at < now

#### 12E. Update Terminology section
Add:
> - **Phase** = time period within a Track (Phase 1, Phase 2). Stored in `hl_phase`.
> - **Learning Plan** = Housman's client-facing term for Pathway. Three plans: Teacher, Mentor, Leader.

Revise Track description:
> - **Track** = full program engagement for a district/institution (spans all Phases/years). NOT a single phase.

Revise Cohort description:
> - **Cohort** = optional container grouping Tracks. Not required for any Track to function.

#### 12F. Architecture Summary — Update
- Add `hl_phase` to the table list
- Add `hl_individual_enrollment` to the table list
- Update table count (35 → 37 after adding hl_phase + hl_individual_enrollment)
- Add Phase and Individual Enrollment related files to the file tree

#### 12G. Documentation Files table — Add B2E Master Reference
Add row:
> | B2E_MASTER_REFERENCE.md | Product catalog, revised architecture (Phase, Track Types, Individual Enrollments), control group clarification |

---

### FILE 13: `README.md` (plugin root)

**Changes needed: 5**

#### 13A. Status line — Update
Update the status line to mention Phase entity, track_type, and Individual Enrollments as planned/upcoming.

#### 13B. Database Schema section — Add new tables
Add:
> - **Phase:** `hl_phase` (track_id, phase_name, phase_number, start/end dates, status)
> - **Individual Enrollments:** `hl_individual_enrollment` (user_id, course_id, enrolled_at, expires_at, status)

Update `hl_track` entry to mention `track_type` column.
Update `hl_pathway` entry to mention `phase_id` (FK to hl_phase).
Update table count from 35 to 37.

#### 13C. Key Design Decisions — Add Phase and track_type
Add bullet points:
> - **Phase entity:** Pathways belong to Phases (not directly to Tracks). This allows a single Track to span multiple years with separate pathway sets per Phase, solving the Year 2 problem where new and returning participants coexist in the same Track.
> - **Track types:** `program` for full B2E management, `course` for simple institutional course access with auto-generated Phase/Pathway/Activity.
> - **Individual Enrollments:** `hl_individual_enrollment` for standalone course purchases by individuals, with per-person expiration dates.

#### 13D. Build Queue — Add new phases
Add new build queue items (after Phase 31):

> ### Phase 32: Phase Entity + Track Types (Architecture — B2E Master Reference)
> - [ ] **32.1 — DB: `hl_phase` table** — Create table with phase_id, track_id, phase_name, phase_number, start/end dates, status. Migration: add phase_id FK to hl_pathway. Create default Phase per existing Track for backward compat.
> - [ ] **32.2 — DB: `track_type` column** — Add `track_type ENUM('program','course') DEFAULT 'program'` to hl_track.
> - [ ] **32.3 — Phase Service** — HL_Phase_Service: CRUD, get_phases_for_track, get_active_phase, auto-create for course-type Tracks.
> - [ ] **32.4 — Pathway migration** — Update HL_Pathway_Service to work through Phase. Pathway queries join Phase → Pathway instead of direct Track → Pathway.
> - [ ] **32.5 — Admin: Track Editor Phases tab** — New tab in track editor (program-type only): list/create/edit Phases, click into Phase to manage Pathways.
> - [ ] **32.6 — Admin: Course-type Track simplification** — When track_type='course': auto-create Phase+Pathway+Activity, hide Phase/Teams/Coaching/Assessment tabs.
> - [ ] **32.7 — Seeder updates** — Update all seeders to create Phases and assign Pathways to Phases.
> - [ ] **32.8 — Frontend Phase context** — My Programs cards show Phase name. Reports support Phase filter.
>
> ### Phase 33: Individual Enrollments (B2E Master Reference)
> - [ ] **33.1 — DB: `hl_individual_enrollment` table** — Create table with user_id, course_id, enrolled_at, expires_at, status, enrolled_by, notes.
> - [ ] **33.2 — Individual Enrollment Service** — CRUD, expiration checks, LearnDash progress queries.
> - [ ] **33.3 — Admin: Individual Enrollments pages** — Course List page + Course Detail page under HL Core menu.
> - [ ] **33.4 — Frontend: My Courses on Dashboard** — Add "My Courses" section to `[hl_dashboard]` for individual enrollments.
> - [ ] **33.5 — Expiration enforcement** — Check on course access, auto-mark expired, optional LearnDash unenroll.
>
> ### Phase 34: Program Progress Matrix Report (B2E Master Reference)
> - [ ] **34.1 — Report query** — Query all LearnDash Course activities across all Phases of a Track, map completion per participant.
> - [ ] **34.2 — Admin report view** — Course-by-course grid with Phase/School/Team/Role filters.
> - [ ] **34.3 — CSV export** — Export the matrix as CSV.

#### 13E. Architecture file tree — Update
Add to the tree:
- `hl_phase` in the schema
- Individual enrollment related files when they're created
- Update table/page counts

---

## Execution Order

Recommended order for making these edits:

1. **Doc 01** (Glossary) — foundation terms must be correct first
2. **Doc 00** (Scope) — high-level framing
3. **Doc 02** (Domain Model) — entity definitions
4. **Doc 04** (Pathways) — Phase integration
5. **Doc 09** (Architecture) — tables and services
6. **Doc 08** (Reporting) — new reports
7. **Doc 03** (Roles) — minimal
8. **Doc 05** (Unlocking) — minimal
9. **Doc 06** (Assessments) — minimal
10. **Doc 07** (Imports) — minimal
11. **Doc 10** (Frontend) — UI changes
12. **CLAUDE.md** — project guide
13. **README.md** — status tracker

---

*End of DOC_UPDATE_INSTRUCTIONS.md*
