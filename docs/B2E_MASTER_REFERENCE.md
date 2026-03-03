# B2E Master Reference — Authoritative Source of Truth

**Created:** March 3, 2026
**Context:** This document was produced during a comprehensive planning session between the project owner (Mateo, CEO of Corsox / IT manager for Housman Institute) and Claude Opus. It captures the complete domain knowledge, architectural decisions, and structural changes that must be reflected across all HL Core documentation, code, and seeders.

**Status:** AUTHORITATIVE. When this document conflicts with existing docs, THIS DOCUMENT WINS.

---

## TABLE OF CONTENTS

1. [Housman Learning Academy — Product Catalog](#1-housman-learning-academy--product-catalog)
2. [B2E Mastery Program — Deep Dive](#2-b2e-mastery-program--deep-dive)
3. [Revised System Architecture](#3-revised-system-architecture)
4. [The Phase Entity (NEW)](#4-the-phase-entity-new)
5. [Individual Enrollments (NEW)](#5-individual-enrollments-new)
6. [The Year 2 Problem — Solved](#6-the-year-2-problem--solved)
7. [Control Group Design — Clarified](#7-control-group-design--clarified)
8. [Reporting Requirements — Updated](#8-reporting-requirements--updated)
9. [Research vs. Curriculum — Clarified](#9-research-vs-curriculum--clarified)
10. [Documentation Issues Found](#10-documentation-issues-found)
11. [Entity Naming — Final Decisions](#11-entity-naming--final-decisions)
12. [What Changes vs. What Stays](#12-what-changes-vs-what-stays)
13. [Glossary of New/Updated Terms](#13-glossary-of-newupdated-terms)

---

## 1. Housman Learning Academy — Product Catalog

Housman Learning Academy offers the following products. HL Core must be aware of ALL of these, even though only some require full Track management.

### 1.1 B2E Mastery Program (Complex — Full HL Core Management)
- **Buyer:** Institutions/school districts only (not sold online to individuals)
- **Duration:** 2 years (Phase 1 + Phase 2), though pilots can be shorter
- **Structure:** 25 LearnDash courses, 3 Learning Plans, organized into Phases
- **Requires:** Full Track management with Phases, Pathways, Teams, Coaching, Assessments, Research
- **This is the primary product HL Core was built for**

### 1.2 Short Courses (Simple — Lightweight HL Core or LearnDash Native)
Three standalone 2-3 hour courses:
- **Educators' Emotional Well-Being**
- **Making the Most of Storytime**
- **Reflective Practice for School Leaders**

Purchase options:
- **Institutional:** District buys for a group of teachers → needs HL Core for roster oversight and progress tracking
- **Individual:** A person buys for themselves → needs HL Core only for access/expiration management (since HL Core replaced the native LearnDash course listing)

### 1.3 ECSELent Adventures Curriculum
A physical product (printed guides, activity tools) with an accompanying online training course:
- **Two versions:** PreK-K and K-2nd Grade
- **Online component:** A LearnDash course teaching how to use the physical kit
- Purchase of the physical kit grants access to the online training
- **Institutional purchase:** Same as short course institutional
- **Individual purchase:** Same as short course individual

### 1.4 How Each Product Maps to HL Core

| Product | Institutional | Individual |
|---------|--------------|------------|
| B2E Mastery Program | Track (type: program) with Phases, Pathways, etc. | N/A — not sold to individuals |
| Short Courses | Track (type: course) — simple roster + completion | Individual Enrollment — access + expiration |
| EA Curriculum Training | Track (type: course) — simple roster + completion | Individual Enrollment — access + expiration |

---

## 2. B2E Mastery Program — Deep Dive

### 2.1 Course Catalog (25 LearnDash Courses)

All 25 courses exist in LearnDash already.

**TC0:** Welcome to begin to ECSEL Training Program (Mastery) — 1 lesson. Shared across all Learning Plans. No streamlined version.

**Teacher Courses (Full):** TC1 through TC8
- TC1: Intro to begin to ECSEL (12 lessons)
- TC2: Your Own Emotionality (12 lessons)
- TC3: Getting to Know Emotion (11 lessons)
- TC4: Emotion in the Heat of the Moment (14 lessons)
- TC5–TC8: Exist in LearnDash (lesson counts not yet cataloged)

**Teacher Courses (Streamlined):** TC1(S) through TC8(S)
- Shortened versions of each full teacher course
- Used only in the Leader Learning Plan
- All 8 exist in LearnDash

**Mentor Courses (Full):** MC1 through MC4
- MC1: Introduction to Reflective Practice (14 lessons)
- MC2: A Deeper Dive into Reflective Practice in Action (10 lessons)
- MC3–MC4: Exist in LearnDash (lesson counts not yet cataloged)

**Mentor Courses (Streamlined):** MC1(S) through MC4(S)
- Shortened versions of each full mentor course
- Used only in the Leader Learning Plan
- All 4 exist in LearnDash

### 2.2 Three Learning Plans

A "Learning Plan" is Housman's term for what HL Core calls a **Pathway**. There are 3 Learning Plans, each following a specific course sequence.

**Teacher Learning Plan (9 courses total):**
Phase 1: TC0 → TC1 → TC2 → TC3 → TC4
Phase 2: TC5 → TC6 → TC7 → TC8

**Mentor Learning Plan (13 courses, interleaved):**
Phase 1: TC0 → TC1 → MC1 → TC2 → TC3 → MC2 → TC4
Phase 2: TC5 → MC3 → TC6 → TC7 → MC4 → TC8

**Leader Learning Plan (13 courses, streamlined + interleaved):**
Phase 1: TC0 → TC1(S) → MC1(S) → TC2(S) → TC3(S) → MC2(S) → TC4(S)
Phase 2: TC5(S) → MC3(S) → TC6(S) → TC7(S) → MC4(S) → TC8(S)

### 2.3 Why Mentor Courses Are Interleaved

Mentors learn teaching content FIRST, then receive the corresponding mentorship techniques. This is intentional pedagogy, not just sequencing. For example, a mentor takes TC1 (Intro to B2E as a teacher), then MC1 (how to mentor teachers through that content). This repeats through the program.

### 2.4 Why Leaders Get Streamlined Versions

School and district leaders need to understand the ENTIRE program (both teacher and mentor content) to effectively oversee it, but they don't have time for full-length courses. Streamlined versions provide the same sequence as the Mentor pathway but with condensed content.

### 2.5 Phase Split (2 Years)

The B2E program is divided into two phases as a **business decision** to extend contracts to 2 years and increase revenue retention. The course distribution:

**Phase 1 (Year 1):**
- TC0 (Welcome) + TC1–TC4 (Teacher courses 1-4) + MC1–MC2 (Mentor courses 1-2)
- Typically runs ~10 months (one course every ~6 weeks)

**Phase 2 (Year 2):**
- TC5–TC8 (Teacher courses 5-8) + MC3–MC4 (Mentor courses 3-4)
- Same cadence as Phase 1

### 2.6 Course Format (From Public Sources)

Each B2E course consists of:
- Three 30-minute self-paced modules (hosted in LearnDash)
- One 45-minute live webinar
- Recommended cadence: one course every 6 weeks
- CEU credits provided through Bertelsen Education

### 2.7 Customization Between Contracts

While the core Learning Plans are "basically the same" for every institution, Housman customizes details per contract:
- **Coaching frequency:** Some contracts require coaching after every course; others only after each mentor course
- **Assessment timing:** When TSA Pre/Post and Child Assessment Pre/Post are administered
- **Pilot programs:** Can be anything from TC1–TC3 + MC1 to the entire Phase 1
- These customizations are why Pathways need to be configurable per Track, not hardcoded globally

---

## 3. Revised System Architecture

### 3.1 The Problem With the Old Model

The previous assumption was: **Track = one Phase/Year**. This created critical problems:
- Teams can't span Tracks → mixed-phase teams (Year 2 scenario) are impossible
- A mentor on Phase 2 can't have Phase 1 teachers in their team
- Cross-phase reporting requires aggregating across separate Tracks
- A mentor would need TWO enrollments for different Tracks

### 3.2 The New Model

**Track = the full program engagement** for a district/institution, spanning all years.

**Phase = a NEW entity** inside Track that groups a specific year/period and its pathways.

**Cohort = optional grouping** of Tracks for organizational purposes (NOT required).

### 3.3 New Hierarchy

```
Track: "ELCPB B2E Mastery 2025-2027" (track_type: program)
  ├── Phase 1 (Year 1, Sep 2025 – Jun 2026)
  │     ├── Teacher Pathway   (TC0, TC1-TC4, TSA Pre/Post, CA Pre/Post)
  │     ├── Mentor Pathway    (TC0, TC1, MC1, TC2, TC3, MC2, TC4, TSA, CA, Coaching)
  │     └── Leader Pathway    (TC0, TC1(S), MC1(S), TC2(S), TC3(S), MC2(S), TC4(S), TSA, CA)
  │
  └── Phase 2 (Year 2, Sep 2026 – Jun 2027)
        ├── Teacher Pathway   (TC5-TC8, TSA Pre/Post, CA Pre/Post)
        ├── Mentor Pathway    (TC5, MC3, TC6, TC7, MC4, TC8, TSA, CA, Coaching)
        └── Leader Pathway    (TC5(S), MC3(S), TC6(S), TC7(S), MC4(S), TC8(S), TSA, CA)
```

Key principles:
- **Enrollment stays at Track level** (User ↔ Track, unique per user per track)
- **Pathway assignment tells you both the role AND the phase** (because pathways live inside phases)
- **Teams belong to Track** (so they can contain participants from different phases)
- **Always 3 pathways per phase** regardless of how many phases exist (no pathway multiplication)

### 3.4 Track Types

New `track_type` field on `hl_track`:

| Type | Usage | Complexity |
|------|-------|-----------|
| `program` | B2E Mastery Program and similar multi-phase, multi-pathway programs | Full: Phases, Pathways, Teams, Coaching, Assessments |
| `course` | Institutional short course purchases | Minimal: auto-created single Phase + single Pathway + single Activity |

When `track_type = 'course'`:
- System auto-creates one Phase, one Pathway, one LearnDash Course Activity
- Admin UI hides: Phase management, Teams tab, Coaching tab, Assessment tabs, Pathway editor
- Admin only needs to: link schools, enroll participants, optionally set a School Leader for oversight
- Creating a short course Track becomes a 2-minute task

---

## 4. The Phase Entity (NEW)

### 4.1 Definition

A **Phase** is a time-bounded period within a Track that groups a set of Pathways. It represents a year or segment of the program.

### 4.2 DB Schema

```sql
CREATE TABLE hl_phase (
    phase_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phase_uuid CHAR(36) NOT NULL,
    track_id BIGINT UNSIGNED NOT NULL,
    phase_name VARCHAR(200) NOT NULL,
    phase_number INT UNSIGNED NOT NULL DEFAULT 1,
    start_date DATE NULL,
    end_date DATE NULL,
    status ENUM('upcoming','active','completed') NOT NULL DEFAULT 'upcoming',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_track (track_id),
    UNIQUE KEY uk_track_number (track_id, phase_number)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.3 Relationship Changes

**Current:** `hl_pathway.track_id` → Track
**New:** `hl_pathway.phase_id` → Phase (and Phase belongs to Track)

This means:
- Pathway is no longer directly tied to Track — it goes through Phase
- To get all pathways for a Track: join Phase → Pathway
- Phase inherits its Track context (so scope filtering still works)

### 4.4 How It's Used

- **B2E program Track:** Admin creates Phase 1 and Phase 2, then creates 3 pathways in each
- **Short course Track:** System auto-creates one Phase (named "Default" or matching the course name), one pathway inside it
- **Pilot program Track:** Admin creates just one Phase with the subset of courses being piloted
- **Flexible:** If Housman ever sells a 3-year program, admin just creates Phase 3 — still just 3 pathways in it

### 4.5 Admin UI Impact

The Track admin editor gets a new **Phases** tab (for program-type Tracks only):
- List of phases with name, number, dates, status
- Click into a Phase to see its pathways
- Pathways tab now shows pathways grouped by Phase
- Course-type Tracks skip this — phase is auto-managed

### 4.6 Frontend Impact

- My Programs page shows which Phase a pathway belongs to
- Reports can filter by Phase
- Phase status (active/completed/upcoming) helps determine default report view

---

## 5. Individual Enrollments (NEW)

### 5.1 Why This Is Needed

HL Core replaced the native LearnDash course listing pages. Individual learners who purchase short courses or EA Curriculum training directly can no longer find/access their courses through LearnDash's native UI. Additionally, LearnDash only supports global expiration (X days for everyone), not per-person expiration dates.

### 5.2 What It Covers

Individual Enrollments handle the case where a PERSON (not an institution) purchases access to a standalone LearnDash course. This includes:
- Short course individual purchases
- ECSELent Adventures online training individual purchases
- Any future standalone course individual access

### 5.3 DB Schema

```sql
CREATE TABLE hl_individual_enrollment (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    status ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
    enrolled_by BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_course (user_id, course_id),
    KEY idx_course (course_id),
    KEY idx_status (status)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.4 Admin Pages (2 pages total)

**Individual Enrollments — Course List:**
- Shows LearnDash courses that have individual enrollments
- Columns: Course Name, Total Enrolled, Active, Expired, Avg Completion %
- Click into a course → detail view

**Individual Enrollments — Course Detail:**
- Header: course name, summary stats
- Enrollment table: Name, Email, Enrolled Date, Expires At, Completion %, Status badge
- Actions: Add Enrollment (user picker + optional expiration date), Edit Expiration, Revoke
- Bulk CSV import (email + optional expiration)

### 5.5 Frontend

On the Dashboard / My Programs page, add a "My Courses" section below the Track-based programs:
- Query `hl_individual_enrollment` for the current user (status = active, expires_at not passed)
- Cross-reference with LearnDash progress
- Show simple course cards: course name, completion %, "Continue Course" button → LD course URL
- If expired: show "Access Expired" badge, no action button

### 5.6 Expiration Enforcement

When user accesses a course:
- Check `hl_individual_enrollment` for that user + course
- If `expires_at < NOW()` and status is still 'active', mark as 'expired'
- Block access at frontend level (show "Your access has expired" message with contact info)
- Optionally unenroll from LearnDash to prevent backdoor access

### 5.7 Admin Menu Placement

```
HL Core
  ├── Tracks
  ├── Cohorts
  ├── Org Units
  ├── ... existing pages ...
  └── Individual Enrollments  ← NEW (2 pages: course list + course detail)
```

---

## 6. The Year 2 Problem — Solved

This was the original motivation for the architectural review. Here's the scenario and how the new model handles it.

### 6.1 The Scenario

ELCPB started Phase 1 in 2025 with 6 schools. Each school has 1-2 Teams. Each team has a Mentor and 3-6 Teachers. Everyone took Phase 1 Learning Plans.

Now entering Year 2:
- Most people advance to Phase 2 pathways
- New teachers have joined and must start at Phase 1
- New and old teachers are mixed in Teams
- A Mentor on Phase 2 may have teachers on BOTH Phase 1 AND Phase 2 in their team

### 6.2 How The New Model Handles It

**One Track:** "ELCPB B2E Mastery 2025-2027"
**Two Phases:** Phase 1 (3 pathways), Phase 2 (3 pathways)

- Teacher 21 (veteran): Enrollment in Track, assigned Phase 1 Teacher Pathway (completed) + Phase 2 Teacher Pathway
- Teacher 52 (new): Enrollment in Track, assigned Phase 1 Teacher Pathway only
- Mentor 113 (veteran): Enrollment in Track, assigned Phase 1 Mentor Pathway (completed) + Phase 2 Mentor Pathway
- All three are in the same Team, same Track

**Why this works:**
- Teams belong to Track → everyone can be in the same team ✓
- Pathway assignment is per-enrollment, multiple allowed → different phases coexist ✓
- Phase 1 completion is preserved (separate pathway, separate activities) ✓
- Reports can filter by Phase or show the full program matrix ✓

### 6.3 The Program Progress Matrix Report

Housman and Leaders need to see a unified view across the entire program:

```
Name       | TC0 | TC1 | TC2 | TC3 | TC4 | TC5 | TC6 | TC7 | TC8 | MC1 | MC2 | MC3 | MC4
Teacher 21 |  ✓  |  ✓  |  ✓  |  ✓  |  ✓  |     |     |     |     |  –  |  –  |  –  |  –
Teacher 52 |     |     |     |     |     |     |     |     |     |  –  |  –  |  –  |  –
Mentor 113 |  ✓  |  ✓  |  ✓  |  ✓  |  ✓  |     |     |     |     |  ✓  |  ✓  |     |
```

Where: ✓ = completed, empty = not yet completed, – = not applicable for this role/pathway.

This report works by querying all LearnDash Course activities across all pathways in all phases of a Track, then mapping completion per participant. Currently this report doesn't exist — it needs to be built.

---

## 7. Control Group Design — Clarified

### 7.1 Key Clarification

The control group (e.g., Lutheran Services Florida) is **NOT tied to one specific client/program**. It exists as an independent research asset that can be compared against ANY or MULTIPLE program Tracks. The actual statistical comparison (Cohen's d, etc.) happens in **Stata**, not in WordPress. HL Core's job is to:

1. Store control group assessment data (TSA Pre/Post, CA Pre/Post)
2. Export it as CSV for Stata import
3. Optionally show basic comparison in admin reports (nice-to-have, not critical)

### 7.2 Implications

- **Cohort as a "research grouping" container is optional, not required.** The control Track doesn't need to be grouped with any specific program Track in HL Core. It can stand alone.
- `hl_track.cohort_id` is already nullable — Tracks can exist without a Cohort. This is fine.
- The Cohen's d comparison reports we built (doc 08 §5.7) still work if an admin creates a Cohort and puts both program + control Tracks in it, but they're not essential for the workflow.
- CSV export of assessment data is the critical path for research.

### 7.3 Revised Model for Control Groups

```
Track: "Lutheran Control 2025-2027" (is_control_group: true)
  └── Phase 1
        └── Control Assessment Pathway (TSA Pre, CA Pre, TSA Post, CA Post)
```

Simple. One Track, one Phase, one assessment-only Pathway. Optionally placed in a Cohort for admin organization, but not required.

---

## 8. Reporting Requirements — Updated

### 8.1 New Report: Program Progress Matrix

A course-by-course completion grid for all participants in a Track.

**Rows:** All participants in the Track
**Columns:** All courses in the B2E catalog (TC0–TC8, MC1–MC4 for full; streamlined variant columns for Leader)
**Values:**
- ✓ or 100% = completed
- X% = in progress
- (empty) = not yet started
- – = not applicable (course not in participant's pathway)

**Filters:** Phase, School, Team, Role
**Export:** CSV

### 8.2 Phase-Aware Reporting

All existing reports should support Phase filtering:
- "Show me Phase 2 progress" → filter to Phase 2 pathways
- "Show me full program" → aggregate across all phases
- Default view: current active phase

### 8.3 Existing Reports — Still Valid

All reports documented in doc 08 remain valid. The main addition is Phase as a filter dimension and the new Program Progress Matrix.

---

## 9. Research vs. Curriculum — Clarified

### 9.1 The Distinction

**Curriculum (Learning Content):**
- TC0–TC8 courses (teacher training)
- MC1–MC4 courses (mentor training)
- These are what participants are here to learn

**Research (Impact Measurement):**
- Teacher Self-Assessment (TSA) Pre/Post — measures teacher practice changes
- Child Assessment (CA) Pre/Post — measures child outcome changes
- These exist to prove the program works, not to teach anything

### 9.2 Why Assessments Are In Pathways

Even though assessments are research tools, they're modeled as Pathway Activities because:
- TSA Pre should be completed BEFORE courses begin (prerequisite gating)
- TSA Post should be completed AFTER courses end (drip rule: after last course)
- Child assessments follow the same pattern
- The Pathway/Activity/Prerequisite system naturally enforces this ordering

### 9.3 Similarly for Coaching

Coaching Sessions aren't courses either, but they're expected at specific points in the program:
- After each course, OR after each mentor course (varies by contract)
- Modeled as Pathway Activities for sequencing and tracking

---

## 10. Documentation Issues Found

### 10.1 Stale/Incorrect References

| Location | Issue |
|----------|-------|
| doc 00 §3 (Non-Goals) | Lists "custom form rendering for teacher self-assessments" as non-goal — this IS implemented since Phase 19 |
| doc 00 §4.2 | Says teacher self-assessments use JetFormBuilder — they now use custom PHP instrument system |
| doc 04 §3.2.2 | Titled "Teacher Self-Assessment Activity (JetFormBuilder-powered)" — should reference custom instrument system |
| doc 01 | No glossary entries for: Phase, Learning Plan, Course Catalog, B2E Mastery Program, Pilot |
| doc 01 | Track definition implies per-phase scope ("time-bounded run/implementation") — needs revision to "full program engagement" |

### 10.2 Missing Domain Context

NONE of the 11 spec docs mention:
- The B2E Mastery Program (the actual product)
- The 25-course catalog
- The 3 Learning Plans (Teacher, Mentor, Leader)
- The Phase split rationale
- Interleaving logic for Mentor courses
- Short courses, EA Curriculum, or individual enrollments
- Pilot programs and their variability

This means any AI working from these docs has zero understanding of what the system is FOR.

### 10.3 README Build Queue

The README.md has a 31+ phase build queue that mixes completed work with future work. It's hard to find current state. Should be cleaned up to clearly separate "Implemented" from "Planned."

---

## 11. Entity Naming — Final Decisions

**No renames needed.** The current naming is correct:

| Entity | Meaning | Stays? |
|--------|---------|--------|
| **Track** | The full program engagement for a district/institution | Yes — just redefine scope from "one phase" to "full program" |
| **Cohort** | Optional grouping of Tracks for organization | Yes — remains optional container |
| **Phase** | NEW — time period within a Track (year/segment) | New entity |
| **Pathway** | Learning plan for a role within a phase | Yes — moves from Track-level to Phase-level |
| **Activity** | Individual requirement in a pathway | Yes — no change |
| **Enrollment** | User ↔ Track participation | Yes — stays at Track level |
| **Individual Enrollment** | User ↔ LearnDash Course (standalone, no Track) | New entity |

---

## 12. What Changes vs. What Stays

### 12.1 DB Schema Changes

| Change | Type | Details |
|--------|------|---------|
| New `hl_phase` table | NEW TABLE | phase_id, track_id, phase_name, phase_number, start/end dates, status |
| `hl_pathway` gets `phase_id` | MIGRATION | Add phase_id FK, migrate existing pathways (create a default Phase per Track for existing data) |
| `hl_track` gets `track_type` | ADD COLUMN | ENUM('program','course') DEFAULT 'program' |
| New `hl_individual_enrollment` table | NEW TABLE | user_id, course_id, enrolled_at, expires_at, status |

### 12.2 Service Layer Changes

| Service | Change |
|---------|--------|
| New `HL_Phase_Service` | CRUD for phases, get_phases_for_track, get_active_phase |
| `HL_Pathway_Service` | Pathways now belong to Phase not Track — queries go through Phase |
| `HL_Track_Service` | Add track_type handling, auto-create Phase for course-type Tracks |
| New `HL_Individual_Enrollment_Service` | CRUD, expiration checks, LearnDash progress queries |
| `HL_Reporting_Service` | Phase filter support, new Program Progress Matrix report |

### 12.3 Admin Pages Changes

| Page | Change |
|------|--------|
| Track Editor | New Phases tab (program-type only). Pathways tab now grouped by Phase |
| New: Individual Enrollments | 2 pages: course list + course detail with enrollment table |
| Track list | Show track_type badge |
| Admin reporting | Phase filter dropdown, Program Progress Matrix report |

### 12.4 Frontend Changes

| Page | Change |
|------|--------|
| Dashboard | Add "My Courses" section for individual enrollments |
| My Programs | Show Phase context for each program card |
| Reports (all) | Phase filter support |
| New: Program Progress Matrix | Course-by-course completion grid |

### 12.5 What Does NOT Change

- Enrollment stays at Track level (User ↔ Track)
- Team stays at Track level (can span phases)
- School/District/Classroom hierarchy — no change
- Assessment system (TSA, Child Assessment) — no change
- Coaching system — no change
- Control group design — no change (just clarified)
- Import system — no change
- Security/Scope model — no change (scope still derives from Track enrollment)
- BuddyBoss integration — minimal change (add My Courses to sidebar if needed)

---

## 13. Glossary of New/Updated Terms

### New Terms to Add to doc 01

**B2E Mastery Program**
The primary professional development product sold by Housman Learning Academy to school districts and institutions. A 2-year (minimum) program consisting of 25 LearnDash courses organized into 3 Learning Plans across 2 Phases.

**Phase**
A time-bounded period within a Track that groups related Pathways. For the B2E Mastery Program, Phase 1 = Year 1 courses and Phase 2 = Year 2 courses. Tracks may have 1 or more Phases. Stored in `hl_phase`.

**Learning Plan**
Housman's client-facing term for what HL Core calls a Pathway. There are 3 Learning Plans: Teacher, Mentor, and Leader (Streamlined). The frontend may use "Learning Plan" or "Program" as labels.

**Course Catalog**
The complete set of LearnDash courses available in the B2E Mastery Program: TC0 (welcome), TC1–TC8 (full teacher), TC1(S)–TC8(S) (streamlined teacher), MC1–MC4 (full mentor), MC1(S)–MC4(S) (streamlined mentor). Total: 25 courses.

**Pilot**
A variable-scope program engagement where a district tries a subset of the B2E program before committing to the full contract. Can range from TC1–TC3 + MC1 to the entire Phase 1.

**Individual Enrollment**
A direct user-to-LearnDash-course association managed by HL Core for individual (non-institutional) course purchases. Stored in `hl_individual_enrollment`. Supports per-person expiration dates.

**Track Type**
A classification on `hl_track` distinguishing between full program Tracks (`program`) and simple single-course institutional Tracks (`course`). Course-type Tracks auto-generate a single Phase + Pathway + Activity.

### Updated Definitions

**Track (REVISED)**
A Track represents the full program engagement for a district/institution. For B2E, this spans the entire 2-year contract (both phases). A Track contains one or more Phases, each containing Pathways. Track is the level at which participants are enrolled, teams are formed, and scope is defined.

Previously, Track was described as "a time-bounded run/implementation" which implied one Track per phase/year. This is revised: Track = full engagement, Phase = year/period within it.

**Cohort (CLARIFIED)**
An optional container that groups related Tracks for organizational purposes. Not required for any Track to function. Useful for visual organization in admin lists or for comparison reporting if desired. The control group research workflow does NOT require Cohort grouping — statistical analysis happens in Stata from CSV exports.

**Pathway (REVISED)**
A configurable set of required Activities assigned to Participants. Pathways now belong to a Phase (not directly to a Track). A Phase typically has 3 Pathways (Teacher, Mentor, Leader), though this is configurable. For course-type Tracks, the Pathway is auto-generated.

---

*End of B2E Master Reference*