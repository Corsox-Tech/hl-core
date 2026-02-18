# Housman Learning Core Plugin — AI Library
## File: 05_UNLOCKING_LOGIC_PREREQS_DRIP_OVERRIDES.md
Version: 1.0
Last Updated: 2026-02-13
Timezone: America/Bogota

---

# 0) Purpose

This document defines the HL Core rules engine for Activity availability and completion gating:
- Prerequisites (dependency graph)
- Drip / release rules (time-based and completion-based)
- Overrides (exempt, manual unlock, optional grace unlock)
- Edge cases (late enrollments, pathway changes mid-Cohort, staffing replacements)

Rules:
- Unlocking logic must be deterministic and auditable.
- "Most restrictive wins" means all applicable gates must pass.
- Unlock checks must be evaluated per Enrollment (User ↔ Cohort).

---

# 1) Core Concepts

## 1.1 Activity Availability
An Activity is either:
- Locked
- Available
- Completed

Availability is evaluated per:
- cohort_id
- enrollment_id
- activity_id

## 1.2 Gates (Constraints)
Availability is controlled by gates:
1) Prerequisite Gate
2) Drip Gate(s)
3) Manual Lock Gate (optional)
4) Override Gate(s)

All gates must pass for "Available", unless an override explicitly bypasses certain gates.

---

# 2) Prerequisites (Dependency Graph)

## 2.1 Definition
Prerequisites define dependencies between Activities.

A prerequisite rule is an edge (or group) in a directed acyclic graph (DAG) preferred, but cycles must be prevented.

Supported patterns:
- One Activity unlocks one Activity (1 → 1)
- One Activity unlocks multiple (1 → many)
- Multiple Activities unlock one (many → 1)
- Multiple unlock multiple (many → many)

## 2.2 Prereq Rule Types
HL Core must support these canonical prerequisite types:

### 2.2.1 ALL_OF
Target Activity requires completion of all listed prerequisite Activities.
- prereq_type = "all_of"
- prerequisites = [activity_id, activity_id, ...]

### 2.2.2 ANY_OF (Optional v2)
Target Activity requires completion of at least one of listed Activities.
- prereq_type = "any_of"
- prerequisites = [...]

Note:
- v1 can omit ANY_OF if not needed, but structure should allow future addition.

### 2.2.3 N_OF_M (Optional v2)
Target requires N completions among M prerequisites.
- prereq_type = "n_of_m"
- n_required = integer
- prerequisites = [...]

---

## 2.3 Prereq Evaluation Output
For a given (enrollment_id, target_activity_id), the prereq gate produces:
- prereq_satisfied: boolean
- prereq_blockers: list of incomplete prerequisite activity_ids

---

# 3) Drip / Release Rules

## 3.1 Definition
Drip rules restrict availability based on time.
HL Core supports:
- Fixed calendar date release (absolute)
- Completion-based delay release (relative)

A target Activity may have:
- no drip rules
- one drip rule
- multiple drip rules

## 3.2 Drip Rule Types

### 3.2.1 FIXED_DATE
Activity is locked until a configured calendar date (in Cohort timezone).
- drip_type = "fixed_date"
- release_at_date = YYYY-MM-DD (date) OR YYYY-MM-DD HH:MM (datetime)
- timezone = Cohort timezone (default: America/Bogota)

Evaluation:
- date_satisfied = now >= release_at_date

Notes:
- Cohort start_date may be earlier or later than release_at_date.
- If release_at_date is missing, this gate is ignored.

---

### 3.2.2 AFTER_COMPLETION_DELAY
Activity is locked until X days after completion of another Activity.
- drip_type = "after_completion_delay"
- base_activity_id
- delay_days (integer >= 0)

Evaluation:
- if base activity not completed → delay gate not satisfied
- else satisfied when now >= (base_completed_at + delay_days)

Notes:
- delay_days=0 means immediate upon completion of base.

---

## 3.3 Most Restrictive Wins (Drip Gate)
If multiple drip rules exist, ALL must be satisfied (AND).

Example:
- FIXED_DATE release: Mar 15
- AFTER_COMPLETION_DELAY: 14 days after Activity A completion
Then availability requires:
- now >= Mar 15
AND
- now >= A.completed_at + 14 days

---

# 4) Full Availability Evaluation (Canonical Algorithm)

For each Activity, compute:

## 4.1 Inputs
- enrollment_id
- activity_id
- activity configuration (prereqs + drip rules)
- completion state of all referenced activities
- overrides state for this enrollment/activity

## 4.2 Gates (in order)
1) If Activity is completed → status = Completed (no need to unlock)
2) If Manual Lock exists (optional) and not bypassed → status = Locked (reason=manual_lock)
3) Evaluate Prereq Gate:
   - if not satisfied → status = Locked (reason=prereq; blockers list)
4) Evaluate Drip Gate(s):
   - if any drip rule not satisfied → status = Locked (reason=drip; next_available_at)
5) If reached here → status = Available

## 4.3 Output fields
- availability_status ∈ { "locked", "available", "completed" }
- locked_reason ∈ { "prereq", "drip", "manual_lock", null }
- blockers (list of activity_ids) when prereq locked
- next_available_at (datetime optional) when drip locked and computable

---

# 5) Overrides

Overrides are administrative changes logged in audit logs.

## 5.1 Exempt (Admin/Coach)
- override_type = "exempt"
Effect:
- Marks the Activity complete for this enrollment, regardless of normal completion method.
- Completion percent becomes 100.
- Must store: who exempted, when, reason (optional)

Use cases:
- participant has prior credit
- remediation path

---

## 5.2 Manual Unlock (Admin)
- override_type = "manual_unlock"
Effect:
- Makes Activity Available even if gates would lock it.
- Scope: specify which gates it bypasses.

Recommended v1 behavior:
- Manual Unlock bypasses only Drip Gate(s), NOT prerequisites, unless explicitly configured.

Rationale:
- Keeps learning sequence integrity intact while allowing pacing exceptions.

---

## 5.3 Optional Grace Unlock (Nice-to-have)
- override_type = "grace_unlock"
Effect:
- Makes Activity Available even if prerequisites incomplete.

This is NOT required for v1.
If implemented, it must be:
- explicit (admin must confirm)
- auditable (reason required)
- safe (should not auto-complete prerequisites)

---

# 6) Late Enrollment and Replacement Rules

## 6.1 Late Enrollments
If an enrollment is added after a Cohort starts:
- Availability is computed using current time and configured rules.
- FIXED_DATE gates may already be satisfied; prerequisites still required.

No special acceleration is implied unless Admin uses overrides.

---

## 6.2 Teacher Replacement Mid-Cohort
Scenario:
- Teacher quits; replacement teacher joins and is assigned to classrooms.
Rules:
- Replacement gets a new Enrollment (same user if they already exist; new enrollment in that Cohort).
- Replacement's Children Assessment requirements are generated based on current TeachingAssignments.
- Replacement does NOT inherit completion unless Admin exempts.

---

# 7) Pathway / Activity Changes Mid-Cohort

## 7.1 When a Pathway is edited
Edits may include:
- adding activities
- removing activities
- changing prerequisites
- changing drip rules

Required behavior:
- HL Core must be able to apply configuration changes without corrupting existing completion history.
- Completion records must remain immutable history; configuration changes affect future availability.

Recommended approach:
- Version pathway configurations or log configuration snapshots per Cohort.
- If versioning is too heavy for v1, record a "config_changed_at" and recompute availability dynamically.

## 7.2 Removing an Activity
If an Activity is removed from a Pathway:
- For reporting: it should not count in completion % for participants assigned to the updated pathway.
- Historical exports may optionally include removed activities if "include_removed" flag is set (staff-only).

---

# 8) Edge Case: Cycles in Prerequisites

Prerequisite graph must not contain cycles.
On save of prerequisite rules:
- validate graph
- if cycle detected → reject save with error listing cycle path

---

# 9) Recommended Admin UX Requirements (No code)

Admin must be able to:
- define prerequisite rules using an interface that supports ALL_OF groups
- define drip rules (fixed date + delay after completion)
- see a preview of unlock logic (e.g., dependency tree)
- apply overrides per enrollment/activity
- see audit log entries for overrides

---

# 10) Audit Log Requirements (Unlock-related)

Log events:
- prerequisite rule created/updated/deleted
- drip rule created/updated/deleted
- activity manually locked/unlocked
- override applied (exempt/manual unlock/grace unlock)
Each log entry includes:
- actor_user_id
- cohort_id
- enrollment_id (if applicable)
- activity_id (if applicable)
- timestamp
- reason/notes (optional but recommended)

---

End of file.
