# B2E Master Reference — Authoritative Source of Truth

**Created:** March 3, 2026
**Context:** This document was produced during a comprehensive planning session between the project owner (Mateo, CEO of Corsox / IT manager for Housman Institute) and Claude Opus. It captures the complete domain knowledge, architectural decisions, and structural changes that must be reflected across all HL Core documentation, code, and seeders.

**Status:** AUTHORITATIVE. When this document conflicts with existing docs, THIS DOCUMENT WINS.

---

## TABLE OF CONTENTS

1. [Housman Learning Academy — Product Catalog](#1-housman-learning-academy--product-catalog)
2. [B2E Mastery Program — Deep Dive](#2-b2e-mastery-program--deep-dive)
3. [Revised System Architecture](#3-revised-system-architecture)
4. [Phase as a Business Concept (Not a DB Entity)](#4-phase-as-a-business-concept-not-a-db-entity)
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

Housman Learning Academy offers the following products. HL Core must be aware of ALL of these, even though only some require full Cycle management.

### 1.1 B2E Mastery Program (Complex — Full HL Core Management)
- **Buyer:** Institutions/school districts only (not sold online to individuals)
- **Duration:** 2 years (Phase 1 + Phase 2), though pilots can be shorter
- **Structure:** 25 LearnDash courses, 3 Learning Plans, organized into year-based groupings (Phase 1 / Phase 2)
- **Requires:** Full Cycle management with Pathways, Teams, Coaching, Assessments, Research
- **This is the primary product HL Core was built for**

### 1.2 Short Courses (Simple — Lightweight HL Core or LearnDash Native)
Three standalone 2-3 hour courses:
- **Educators' Emotional Well-Being**
- **Making the Most of Storytime**
- **Reflective Practice for School Leaders**

Purchase options:
- **Institutional:** District buys for a group of teachers → needs HL Core for roster oversight and progress tracking
- **Individual:** A person buys for themselves → needs HL Core only for access/expiration management

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
| B2E Mastery Program | Cycle (type: program) with Pathways, Teams, etc. | N/A — not sold to individuals |
| Short Courses | Cycle (type: course) — simple roster + completion | Individual Enrollment — access + expiration |
| EA Curriculum Training | Cycle (type: course) — simple roster + completion | Individual Enrollment — access + expiration |

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
- These customizations are why Pathways need to be configurable per Cycle, not hardcoded globally

---

## 3. Revised System Architecture

### 3.1 The Problem With the Old Model

The previous assumption was: one Cycle per year/phase. This created critical problems:
- Teams can't span Cycles → mixed-year teams (Year 2 scenario) are impossible
- A mentor on Year 2 can't have Year 1 teachers in their team
- Cross-year reporting requires aggregating across separate Cycles
- A mentor would need TWO enrollments for different Cycles

### 3.2 The New Model

**Cycle = the full program engagement** for a district/institution, spanning all years.

**Phase 1 / Phase 2 are business terms** for Year 1 / Year 2 — handled via pathway naming and configuration, NOT a separate DB entity.

**Partnership = optional grouping** of Cycles for organizational purposes (NOT required).

### 3.3 New Hierarchy

```
Cycle: "ELCPB B2E Mastery 2025-2027" (cycle_type: program)
  ├── Year 1 Pathways (named "Phase 1" in UI)
  │     ├── Teacher Pathway   (TC0, TC1-TC4, TSA Pre/Post, CA Pre/Post)
  │     ├── Mentor Pathway    (TC0, TC1, MC1, TC2, TC3, MC2, TC4, TSA, CA, Coaching)
  │     └── Leader Pathway    (TC0, TC1(S), MC1(S), TC2(S), TC3(S), MC2(S), TC4(S), TSA, CA)
  │
  └── Year 2 Pathways (named "Phase 2" in UI) — 8 pathways
        ├── Teacher Phase 1   (TSA Pre, CA Pre, TC0, TC1, SR#1, RP#1, TC2, SR#2, RP#2,
        │                      TC3, SR#3, RP#3, TC4, SR#4, RP#4, CA Post, TSA Post)
        ├── Teacher Phase 2   (TSA Pre, CA Pre, TC5, SR#1, RP#1, TC6, SR#2, RP#2,
        │                      TC7, SR#3, RP#3, TC8, SR#4, RP#4, CA Post, TSA Post)
        ├── Mentor Phase 1    (TSA Pre, CA Pre, TC0, TC1, CS#1, MC1, RP#1, TC2, CS#2,
        │                      RP#2, TC3, CS#3, MC2, RP#3, TC4, CS#4, RP#4, CA Post, TSA Post)
        ├── Mentor Phase 2    (TSA Pre, CA Pre, TC5, CS#1, MC3, RP#1, TC6, CS#2, RP#2,
        │                      TC7, CS#3, MC4, RP#3, TC8, CS#4, RP#4, CA Post, TSA Post)
        ├── Mentor Transition (TSA Pre, CA Pre, TC5, CS#1, MC1, RP#1, TC6, CS#2,
        │                      RP#2, TC7, CS#3, MC2, RP#3, TC8, CS#4, RP#4,
        │                      CA Post, TSA Post)  — 18 components
        ├── Mentor Completion (TSA Pre, MC3, MC4, TSA Post)  — 4 components
        ├── Streamlined Ph 1  (TC0, TC1(S), MC1(S), CV#1, TC2(S), CV#2, TC3(S), CV#3,
        │                      TC4(S), MC2(S), CV#4)
        └── Streamlined Ph 2  (TC5(S), MC3(S), CV#1, TC6(S), CV#2, TC7(S), CV#3,
                               TC8(S), MC4(S), CV#4)

Legend: SR=Self-Reflection, RP=Reflective Practice Session, CS=Coaching Session,
        CV=Classroom Visit, TSA=Teacher Self-Assessment, CA=Child Assessment
```

**Key Y2 additions:**
- **Self-Reflection (SR):** Teacher pathways include a self-reflection form after each course
- **Reflective Practice Session (RP):** Structured mentor-teacher reflection sessions, interleaved with courses in Teacher, Mentor, and Transition pathways
- **Classroom Visit (CV):** Leader/Streamlined pathways include classroom visits (leader observes teacher) instead of RP sessions
- **Mentor Transition:** New pathway for mentors entering Year 2 who need to catch up on Phase 1 content
- **Mentor Completion:** Minimal pathway for mentors who only need remaining mentor courses

Key principles:
- **Enrollment stays at Cycle level** (User ↔ Cycle, unique per user per cycle)
- **Pathway assignment tells you both the role AND the year** (via pathway naming and configuration)
- **Teams belong to Cycle** (so they can contain participants from different years)
- **Always 3 pathways per year grouping** regardless of how many years exist (no pathway multiplication)
- **Pathways belong directly to Cycles** — no intermediate Phase entity in the DB

### 3.4 Cycle Types

`cycle_type` field on `hl_cycle`:

| Type | Usage | Complexity |
|------|-------|-----------|
| `program` | B2E Mastery Program and similar multi-year, multi-pathway programs | Full: Pathways, Teams, Coaching, Assessments |
| `course` | Institutional short course purchases | Minimal: auto-created single Pathway + single Component |

When `cycle_type = 'course'`:
- System auto-creates one Pathway and one LearnDash Course Component
- Admin UI hides: Teams tab, Coaching tab, Assessment tabs, Pathway editor
- Admin only needs to: link schools, enroll participants, optionally set a School Leader for oversight
- Creating a short course Cycle becomes a 2-minute task

---

## 4. Phase as a Business Concept (Not a DB Entity)

### 4.1 Clarification

"Phase 1" and "Phase 2" are **business terms** for Year 1 and Year 2 of the B2E Mastery Program. They are NOT a separate database entity. There is no `hl_phase` table.

In the V3 architecture, Pathways belong **directly to Cycles** via `hl_pathway.cycle_id`. The year/phase distinction is handled through:
- **Pathway naming:** e.g., "Phase 1 — Teacher Learning Plan", "Phase 2 — Mentor Learning Plan"
- **Pathway configuration:** start/end dates, sequencing, and grouping
- **Multiple pathway assignments per enrollment:** a participant can be assigned Year 1 and Year 2 pathways within the same Cycle

### 4.2 How It Works in Practice

- **B2E program Cycle:** Admin creates 6 pathways (3 per year) directly in the Cycle. Pathway names indicate the year grouping.
- **Short course Cycle:** System auto-creates one Pathway and one Component. No year concept needed.
- **Pilot program Cycle:** Admin creates only the pathways for the piloted subset.
- **Flexible:** If Housman ever sells a 3-year program, admin just creates additional pathways for Year 3.

### 4.3 Admin UI

The Cycle admin editor shows all pathways in a flat list (or optionally grouped by a `phase_label` metadata field if visual grouping is desired). There is no separate Phase CRUD.

### 4.4 Frontend Impact

- My Programs page shows pathway names (which may include "Phase 1" / "Phase 2" text)
- Reports can filter by pathway grouping or naming convention
- The "Phase 1 / Phase 2" business concept is preserved in the UI through pathway naming, just not as a separate DB entity

---

## 5. Individual Enrollments (NEW)

### 5.1 Why This Is Needed

HL Core replaced the native LearnDash course listing pages. Individual learners who purchase short courses or EA Curriculum training directly can no longer find/access their courses through LearnDash's native UI. Additionally, LearnDash only supports global expiration (X days for everyone), not per-person expiration dates.

### 5.2 What It Covers

Individual Enrollments handle the case where a PERSON (not an institution) purchases access to a standalone LearnDash course. This is separate from Cycle-based institutional enrollments. This includes:
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

On the Dashboard / My Programs page, add a "My Courses" section below the Cycle-based programs:
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
  ├── Cycles
  ├── Partnerships
  ├── Org Units
  ├── ... existing pages ...
  └── Individual Enrollments  ← NEW (2 pages: course list + course detail)
```

---

## 6. The Year 2 Problem — Solved

This was the original motivation for the architectural review. Here's the scenario and how the new model handles it.

### 6.1 The Scenario

ELCPB started Phase 1 (Year 1) in 2025 with 6 schools. Each school has 1-2 Teams. Each team has a Mentor and 3-6 Teachers. Everyone took Phase 1 Learning Plans.

Now entering Year 2:
- Most people advance to Phase 2 (Year 2) pathways
- New teachers have joined and must start at Phase 1 (Year 1)
- New and old teachers are mixed in Teams
- A Mentor on Phase 2 may have teachers on BOTH Phase 1 AND Phase 2 in their team

### 6.2 How The Model Handles It

**One Cycle:** "ELCPB B2E Mastery 2025-2027"
**Multiple Pathways:** Phase 1 pathways (3) + Phase 2 pathways (3), all belonging directly to the Cycle

- Teacher 21 (veteran): Enrollment in Cycle, assigned Phase 1 Teacher Pathway (completed) + Phase 2 Teacher Pathway
- Teacher 52 (new): Enrollment in Cycle, assigned Phase 1 Teacher Pathway only
- Mentor 113 (veteran): Enrollment in Cycle, assigned Phase 1 Mentor Pathway (completed) + Phase 2 Mentor Pathway
- All three are in the same Team, same Cycle

**Why this works:**
- Teams belong to Cycle → everyone can be in the same team ✓
- Pathway assignment is per-enrollment, multiple allowed → different year groupings coexist ✓
- Phase 1 completion is preserved (separate pathway, separate components) ✓
- Reports can filter by pathway grouping or show the full program matrix ✓

### 6.3 The Program Progress Matrix Report

Housman and Leaders need to see a unified view across the entire program:

```
Name       | TC0 | TC1 | TC2 | TC3 | TC4 | TC5 | TC6 | TC7 | TC8 | MC1 | MC2 | MC3 | MC4
Teacher 21 |  ✓  |  ✓  |  ✓  |  ✓  |  ✓  |     |     |     |     |  –  |  –  |  –  |  –
Teacher 52 |     |     |     |     |     |     |     |     |     |  –  |  –  |  –  |  –
Mentor 113 |  ✓  |  ✓  |  ✓  |  ✓  |  ✓  |     |     |     |     |  ✓  |  ✓  |     |
```

Where: ✓ = completed, empty = not yet completed, – = not applicable for this role/pathway.

This report works by querying all LearnDash Course components across all pathways in a Cycle, then mapping completion per participant. Currently this report doesn't exist — it needs to be built.

---

## 7. Control Group Design — Clarified

### 7.1 Key Clarification

The control group (e.g., Lutheran Services Florida) is **NOT tied to one specific client/program**. It exists as an independent research asset that can be compared against ANY or MULTIPLE program Cycles. The actual statistical comparison (Cohen's d, etc.) happens in **Stata**, not in WordPress. HL Core's job is to:

1. Store control group assessment data (TSA Pre/Post, CA Pre/Post)
2. Export it as CSV for Stata import
3. Optionally show basic comparison in admin reports (nice-to-have, not critical)

### 7.2 Implications

- **Partnership as a "research grouping" container is optional, not required.** The control Cycle doesn't need to be grouped with any specific program Cycle in HL Core. It can stand alone.
- `hl_cycle.partnership_id` is already nullable — Cycles can exist without a Partnership. This is fine.
- The Cohen's d comparison reports we built (doc 08 §5.7) still work if an admin creates a Partnership and puts both program + control Cycles in it, but they're not essential for the workflow.
- CSV export of assessment data is the critical path for research.

### 7.3 Revised Model for Control Groups

```
Cycle: "Lutheran Control 2025-2027" (is_control_group: true)
  └── Control Assessment Pathway (TSA Pre, CA Pre, TSA Post, CA Post)
```

Simple. One Cycle, one assessment-only Pathway. Optionally placed in a Partnership for admin organization, but not required.

---

## 8. Reporting Requirements — Updated

### 8.1 New Report: Program Progress Matrix

A course-by-course completion grid for all participants in a Cycle.

**Rows:** All participants in the Cycle
**Columns:** All courses in the B2E catalog (TC0–TC8, MC1–MC4 for full; streamlined variant columns for Leader)
**Values:**
- ✓ or 100% = completed
- X% = in progress
- (empty) = not yet started
- – = not applicable (course not in participant's pathway)

**Filters:** Pathway group (Year 1 / Year 2), School, Team, Role
**Export:** CSV

### 8.2 Year-Aware Reporting

All existing reports should support pathway grouping/filtering:
- "Show me Phase 2 progress" → filter to Year 2 pathways
- "Show me full program" → aggregate across all pathways
- Default view: current active pathways

### 8.3 Existing Reports — Still Valid

All reports documented in doc 08 remain valid. The main addition is pathway grouping as a filter dimension and the new Program Progress Matrix.

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

Even though assessments are research tools, they're modeled as Pathway Components because:
- TSA Pre should be completed BEFORE courses begin (prerequisite gating)
- TSA Post should be completed AFTER courses end (drip rule: after last course)
- Child assessments follow the same pattern
- The Pathway/Component/Prerequisite system naturally enforces this ordering

### 9.3 Similarly for Coaching

Coaching Sessions aren't courses either, but they're expected at specific points in the program:
- After each course, OR after each mentor course (varies by contract)
- Modeled as Pathway Components for sequencing and tracking

---

## 10. Documentation Issues Found

### 10.1 Stale/Incorrect References

| Location | Issue |
|----------|-------|
| doc 00 §3 (Non-Goals) | Lists "custom form rendering for teacher self-assessments" as non-goal — this IS implemented since Phase 19 |
| doc 00 §4.2 | Says teacher self-assessments use JetFormBuilder — they now use custom PHP instrument system |
| doc 04 §3.2.2 | Titled "Teacher Self-Assessment Activity (JetFormBuilder-powered)" — should reference custom instrument system |
| doc 01 | No glossary entries for: Learning Plan, Course Catalog, B2E Mastery Program, Pilot |
| doc 01 | Cycle definition should reflect "full program engagement" scope |

### 10.2 Missing Domain Context

NONE of the 11 spec docs mention:
- The B2E Mastery Program (the actual product)
- The 25-course catalog
- The 3 Learning Plans (Teacher, Mentor, Leader)
- The Phase 1 / Phase 2 split rationale
- Interleaving logic for Mentor courses
- Short courses, EA Curriculum, or individual enrollments
- Pilot programs and their variability

This means any AI working from these docs has zero understanding of what the system is FOR.

### 10.3 README Build Queue

The README.md has a 31+ phase build queue that mixes completed work with future work. It's hard to find current state. Should be cleaned up to clearly separate "Implemented" from "Planned."

---

## 11. Entity Naming — Final Decisions

**V3 Rename applied.** Final entity naming:

| Entity | Meaning | DB Table |
|--------|---------|----------|
| **Cycle** | The full program engagement for a district/institution | `hl_cycle` (was `hl_track`) |
| **Partnership** | Optional grouping of Cycles for organization | `hl_partnership` (was `hl_cohort`) |
| **Pathway** | Learning plan for a role, belongs directly to a Cycle | `hl_pathway` (now has `cycle_id`) |
| **Component** | Individual requirement in a pathway | `hl_component` (was `hl_activity`) |
| **Enrollment** | User ↔ Cycle participation | `hl_enrollment` (stays at Cycle level) |
| **Individual Enrollment** | User ↔ LearnDash Course (standalone, no Cycle) | `hl_individual_enrollment` |

**Removed entities:**
- **Phase** — No longer a DB entity. "Phase 1" / "Phase 2" are business terms for Year 1 / Year 2, handled via pathway naming and configuration.

**Note:** Table is now `hl_component_state`. Column `component_type` replaced `activity_type`. The `activity_state` column name is no longer used.

---

## 12. What Changes vs. What Stays

### 12.1 DB Schema Changes (V3 Rename)

| Change | Type | Details |
|--------|------|---------|
| `hl_track` → `hl_cycle` | RENAME TABLE | `track_id` → `cycle_id`, `track_type` → `cycle_type` |
| `hl_cohort` → `hl_partnership` | RENAME TABLE | `cohort_id` → `partnership_id` |
| `hl_activity` → `hl_component` | RENAME TABLE | `activity_id` → `component_id` |
| `hl_track_school` → `hl_cycle_school` | RENAME TABLE | FK updated |
| `hl_child_track_snapshot` → `hl_child_cycle_snapshot` | RENAME TABLE | FK updated |
| `hl_activity_prereq_group` → `hl_component_prereq_group` | RENAME TABLE | FK updated |
| `hl_activity_prereq_item` → `hl_component_prereq_item` | RENAME TABLE | FK updated |
| `hl_activity_drip_rule` → `hl_component_drip_rule` | RENAME TABLE | FK updated |
| `hl_activity_override` → `hl_component_override` | RENAME TABLE | FK updated |
| `hl_phase` | DELETED | Phase is not a DB entity |
| `hl_pathway.cycle_id` | FK CHANGE | Pathways belong directly to Cycles (not via Phase) |
| `hl_cycle.cycle_type` | COLUMN | ENUM('program','course') DEFAULT 'program' |
| New `hl_individual_enrollment` | NEW TABLE | user_id, course_id, enrolled_at, expires_at, status |

**Renamed in V2:** `hl_activity_state` → `hl_component_state`, `activity_type` → `component_type`.

### 12.2 Service Layer Changes

| Service | Change |
|---------|--------|
| `HL_Track_Service` → `HL_Cycle_Service` | Renamed. Cycle management, cycle_type handling |
| `HL_Cohort_Service` → `HL_Partnership_Service` | Renamed. Container management |
| `HL_Phase_Service` | DELETED — no Phase entity |
| `HL_Pathway_Service` | Pathways belong directly to Cycles |
| New `HL_Individual_Enrollment_Service` | CRUD, expiration checks, LearnDash progress queries |
| `HL_Reporting_Service` | Pathway grouping filter support, new Program Progress Matrix report |

### 12.3 Admin Pages Changes

| Page | Change |
|------|--------|
| Cycle Editor | Pathways listed directly (no Phase tab) |
| New: Individual Enrollments | 2 pages: course list + course detail with enrollment table |
| Cycle list | Show cycle_type badge |
| Admin reporting | Pathway grouping filter, Program Progress Matrix report |

### 12.4 Frontend Changes

| Page | Change |
|------|--------|
| Dashboard | Add "My Courses" section for individual enrollments |
| My Programs | Show pathway names (which may include Phase 1/Phase 2 text) |
| Reports (all) | Pathway grouping filter support |
| New: Program Progress Matrix | Course-by-course completion grid |

### 12.5 What Does NOT Change

- Enrollment stays at Cycle level (User ↔ Cycle)
- Team stays at Cycle level (can span years)
- School/District/Classroom hierarchy — no change
- Assessment system (TSA, Child Assessment) — no change
- Coaching system — enhanced with `hl_coaching_session_submission` for structured form responses (RP Notes, Action Plan)
- Control group design — no change (just clarified)
- Import system — no change
- Security/Scope model — no change (scope still derives from Cycle enrollment)
- BuddyBoss integration — HL User Profile replaces BB profiles; BB profile URLs redirect to `/user-profile/`. My Profile added to sidebar.

---

## 13. Glossary of New/Updated Terms

### New/Updated Terms (V3 Rename)

**B2E Mastery Program**
The primary professional development product sold by Housman Learning Academy to school districts and institutions. A 2-year (minimum) program consisting of 25 LearnDash courses organized into 3 Learning Plans across 2 year groupings (Phase 1 / Phase 2).

**Phase (business term, NOT a DB entity)**
"Phase 1" and "Phase 2" are business terms for Year 1 and Year 2 of the B2E program. They are NOT stored as a separate entity. The year distinction is handled via pathway naming and configuration within a Cycle.

**Learning Plan**
Housman's client-facing term for what HL Core calls a Pathway. There are 3 Learning Plans: Teacher, Mentor, and Leader (Streamlined). The frontend may use "Learning Plan" or "Program" as labels.

**Course Catalog**
The complete set of LearnDash courses available in the B2E Mastery Program: TC0 (welcome), TC1-TC8 (full teacher), TC1(S)-TC8(S) (streamlined teacher), MC1-MC4 (full mentor), MC1(S)-MC4(S) (streamlined mentor). Total: 25 courses.

**Pilot**
A variable-scope program engagement where a district tries a subset of the B2E program before committing to the full contract. Can range from TC1-TC3 + MC1 to the entire Phase 1 content.

**Individual Enrollment**
A direct user-to-LearnDash-course association managed by HL Core for individual (non-institutional) course purchases. Stored in `hl_individual_enrollment`. Supports per-person expiration dates.

**Cycle Type**
A classification on `hl_cycle` distinguishing between full program Cycles (`program`) and simple single-course institutional Cycles (`course`). Course-type Cycles auto-generate a single Pathway + Component.

**Component**
An individual requirement within a Pathway. Stored in `hl_component` (was `hl_activity`). Each component has a type: LearnDash course, self-assessment, child assessment, coaching session, observation, self-reflection, reflective practice session, or classroom visit. The DB column is `component_type` (ENUM with 8 values).

### Core Entity Definitions

**Cycle (was Track)**
A Cycle represents the full program engagement for a district/institution. For B2E, this spans the entire 2-year contract. A Cycle contains Pathways directly (no intermediate Phase entity). Cycle is the level at which participants are enrolled, teams are formed, and scope is defined. Stored in `hl_cycle`.

**Partnership (was Cohort)**
An optional container that groups related Cycles for organizational purposes. Not required for any Cycle to function. Useful for visual organization in admin lists or for comparison reporting if desired. The control group research workflow does NOT require Partnership grouping — statistical analysis happens in Stata from CSV exports. Stored in `hl_partnership`.

**Pathway (REVISED)**
A configurable set of required Components assigned to Participants. Pathways belong directly to a Cycle (not via Phase). A program-type Cycle typically has 6 Pathways (3 per year grouping: Teacher, Mentor, Leader), though this is configurable. For course-type Cycles, the Pathway is auto-generated.

---

*End of B2E Master Reference*