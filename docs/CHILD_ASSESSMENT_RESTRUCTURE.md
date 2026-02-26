# Phase 23: Child Assessment Restructure — Per-Child Age Groups + Roster Management

## Overview
Three major changes:
1. **Per-child age group assignment** based on DOB (not classroom age band)
2. **Frozen age group snapshots** per track for research consistency (PRE = POST)
3. **Teacher roster management** — add/remove children from Classroom Page + assessment reconciliation

## New Architecture

### Age Group Ranges (system-wide constants)
| Age Group | Age Range | Instrument Type |
|-----------|-----------|-----------------|
| Infant | 0 – 11 months (< 1.0 yr) | children_infant |
| Toddler | 12 – 35 months (1.0 – 2.99 yr) | children_toddler |
| Preschool | 36 – 59 months (3.0 – 4.99 yr) | children_preschool |
| K-2 | 60+ months (5.0+ yr) | children_k2 |

### New Table: hl_child_track_snapshot
Freezes each child's age group when assessments are first generated for a track.
- child_id (FK), track_id (FK)
- frozen_age_group (varchar: infant/toddler/preschool/k2)
- dob_at_freeze (date), age_months_at_freeze (int)
- frozen_at (datetime)
- UNIQUE(child_id, track_id)

PRE and POST both use the same frozen value. Next track recalculates.

### Modified: hl_child_classroom_current
Soft-removal support for teacher roster management:
- status ENUM('active','teacher_removed') DEFAULT 'active'
- removed_by_enrollment_id (FK nullable)
- removed_at (datetime nullable)
- removal_reason ENUM('left_school','moved_classroom','other') nullable
- removal_note (text nullable)
- added_by_enrollment_id (FK nullable — null=imported, non-null=teacher-added)
- added_at (datetime nullable)

### Modified: hl_child_assessment_childrow
- status ENUM('active','skipped','not_in_classroom','stale_at_submit') DEFAULT 'active'
- skip_reason VARCHAR(255) nullable
- frozen_age_group VARCHAR(20) — which instrument was used for this child
- instrument_id (FK) — which instrument's question was answered

### Modified: hl_child_assessment_instance
- instrument_id becomes nullable (instruments resolved per-child from snapshot)

### Assessment Form UX
One form per classroom, sections grouped by frozen age group:
- Section: Infant (N children) — infant instrument question + Likert
- Section: Toddler (N children) — toddler instrument question + Likert
- Section: Preschool (N children) — preschool instrument question + Likert
- etc.

Each section has its own behavior key table. Single age group = one section (same as today).

Bottom of form: "Missing a child from your classroom?" link → auto-saves draft → redirects to Classroom Page.

### Classroom Page Teacher Features
- "Add Child" button (visible to assigned teachers)
- "Remove" action per child row with reason dropdown
- Add child form: first name, last name, DOB, gender
- Duplicate detection: match on (first_name + last_name + DOB + school_id)
- return_to_assessment URL param for seamless back-navigation

### Roster Reconciliation at Render Time
When assessment form loads, system compares draft childrows against current active roster:
- Removed children: draft answers hidden (kept with status flag)
- New children: appear with blank answers in correct age group section
- Already-submitted assessments: untouched (locked)

### Race Condition Handling at Submit Time
- Child removed between load and submit: answer accepted with status='stale_at_submit'
- Child added between load and submit: submission accepted, new child has status='not_yet_assessed'
- Submission always succeeds — never blocked by roster changes

### Admin Backend
- Classroom admin: removed children section with badges ("Removed by Jane — Left school")
- Teacher-added children: badge ("Added by Jane Smith")
- hl_child_track_snapshot visible on child detail for audit

## See README.md Phase 23 for the Build Queue
