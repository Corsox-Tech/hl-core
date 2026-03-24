# Housman Learning Core Plugin — AI Library
## File: 05_UNLOCKING_LOGIC_PREREQS_DRIP_OVERRIDES.md
Version: 3.0
Last Updated: 2026-03-17
Timezone: America/Bogota

---

# 0) Purpose

This document defines the HL Core rules engine for Component availability and completion gating:
- Prerequisites (dependency graph)
- Drip / release rules (time-based and completion-based)
- Overrides (exempt, manual unlock, optional grace unlock)
- Edge cases (late enrollments, pathway changes mid-Cycle, staffing replacements)

Rules:
- Unlocking logic must be deterministic and auditable.
- "Most restrictive wins" means all applicable gates must pass.
- Unlock checks must be evaluated per Enrollment (User ↔ Cycle).

---

# 1) Core Concepts

## 1.1 Component Availability
A Component is either:
- Locked
- Available
- Completed

Availability is evaluated per:
- cycle_id
- enrollment_id
- component_id

Note: Components belong to Pathways, which belong to Cycles. Availability is evaluated per (enrollment_id, component_id).

## 1.2 Gates (Constraints)
Availability is controlled by gates:
1) Prerequisite Gate
2) Drip Gate(s)
3) Override Gate(s)

All gates must pass for "Available", unless an override explicitly bypasses certain gates.

---

# 2) Prerequisites (Dependency Graph)

## 2.1 Definition
Prerequisites define dependencies between Components.

A prerequisite rule is an edge (or group) in a directed acyclic graph (DAG) preferred, but cycles must be prevented.

Supported patterns:
- One Component unlocks one Component (1 → 1)
- One Component unlocks multiple (1 → many)
- Multiple Components unlock one (many → 1)
- Multiple unlock multiple (many → many)

## 2.2 Prereq Rule Types
HL Core must support these canonical prerequisite types:

### 2.2.1 ALL_OF
Target Component requires completion of all listed prerequisite Components.
- prereq_type = "all_of"
- prerequisites = [component_id, component_id, ...]

### 2.2.2 ANY_OF (Optional v2)
Target Component requires completion of at least one of listed Components.
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
For a given (enrollment_id, target_component_id), the prereq gate produces:
- prereq_satisfied: boolean
- prereq_blockers: list of incomplete prerequisite component_ids

---

# 3) Drip / Release Rules

## 3.1 Definition
Drip rules restrict availability based on time.
HL Core supports:
- Fixed calendar date release (absolute)
- Completion-based delay release (relative)

A target Component may have:
- no drip rules
- one drip rule
- multiple drip rules

## 3.2 Drip Rule Types

### 3.2.1 FIXED_DATE
Component is locked until a configured calendar date (in Cycle timezone).
- drip_type = "fixed_date"
- release_at_date = YYYY-MM-DD (date) OR YYYY-MM-DD HH:MM (datetime)
- timezone = Cycle timezone (default: America/Bogota)

Evaluation:
- date_satisfied = now >= release_at_date

Notes:
- Cycle start_date may be earlier or later than release_at_date.
- If release_at_date is missing, this gate is ignored.

---

### 3.2.2 AFTER_COMPLETION_DELAY
Component is locked until X days after completion of another Component.
- drip_type = "after_completion_delay"
- base_component_id
- delay_days (integer >= 0)

Evaluation:
- if base component not completed → delay gate not satisfied
- else satisfied when now >= (base_completed_at + delay_days)

Notes:
- delay_days=0 means immediate upon completion of base.

---

## 3.3 Most Restrictive Wins (Drip Gate)
If multiple drip rules exist, ALL must be satisfied (AND).

Example:
- FIXED_DATE release: Mar 15
- AFTER_COMPLETION_DELAY: 14 days after Component A completion
Then availability requires:
- now >= Mar 15
AND
- now >= A.completed_at + 14 days

---

# 4) Full Availability Evaluation (Canonical Algorithm)

For each Component, compute:

## 4.1 Inputs
- enrollment_id
- component_id
- component configuration (prereqs + drip rules)
- completion state of all referenced components
- overrides state for this enrollment/component

## 4.2 Gates (in order)
1) If Component is completed → status = Completed (no need to unlock)
2) Evaluate Prereq Gate:
   - if not satisfied → status = Locked (reason=prereq; blockers list)
3) Evaluate Drip Gate(s):
   - if any drip rule not satisfied → status = Locked (reason=drip; next_available_at)
4) If reached here → status = Available

## 4.3 Output fields
- availability_status ∈ { "locked", "available", "completed" }
- locked_reason ∈ { "prereq", "drip", null }
- blockers (list of component_ids) when prereq locked
- next_available_at (datetime optional) when drip locked and computable

---

# 5) Overrides

Overrides are administrative changes logged in audit logs.

## 5.1 Exempt (Admin/Coach)
- override_type = "exempt"
Effect:
- Marks the Component complete for this enrollment, regardless of normal completion method.
- Completion percent becomes 100.
- Must store: who exempted, when, reason (optional)

Use cases:
- participant has prior credit
- remediation path

---

## 5.2 Manual Unlock (Admin)
- override_type = "manual_unlock"
Effect:
- Makes Component Available even if gates would lock it.
- Scope: specify which gates it bypasses.

Recommended v1 behavior:
- Manual Unlock bypasses only Drip Gate(s), NOT prerequisites, unless explicitly configured.

Rationale:
- Keeps learning sequence integrity intact while allowing pacing exceptions.

---

## 5.3 Optional Grace Unlock (Nice-to-have)
- override_type = "grace_unlock"
Effect:
- Makes Component Available even if prerequisites incomplete.

This is NOT required for v1.
If implemented, it must be:
- explicit (admin must confirm)
- auditable (reason required)
- safe (should not auto-complete prerequisites)

---

# 6) Late Enrollment and Replacement Rules

## 6.1 Late Enrollments
If an enrollment is added after a Cycle starts:
- Availability is computed using current time and configured rules.
- FIXED_DATE gates may already be satisfied; prerequisites still required.

No special acceleration is implied unless Admin uses overrides.

---

## 6.2 Teacher Replacement Mid-Cycle
Scenario:
- Teacher quits; replacement teacher joins and is assigned to classrooms.
Rules:
- Replacement gets a new Enrollment (same user if they already exist; new enrollment in that Cycle).
- Replacement's Child Assessment requirements are generated based on current TeachingAssignments.
- Replacement does NOT inherit completion unless Admin exempts.

---

# 7) Pathway / Component Changes Mid-Cycle

## 7.1 When a Pathway is edited
Edits may include:
- adding components
- removing components
- changing prerequisites
- changing drip rules

Required behavior:
- HL Core must be able to apply configuration changes without corrupting existing completion history.
- Completion records must remain immutable history; configuration changes affect future availability.

Recommended approach:
- Version pathway configurations or log configuration snapshots per Cycle.
- If versioning is too heavy for v1, record a "config_changed_at" and recompute availability dynamically.

## 7.2 Removing a Component
If a Component is removed from a Pathway:
- For reporting: it should not count in completion % for participants assigned to the updated pathway.
- Historical exports may optionally include removed components if "include_removed" flag is set (staff-only).

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
- apply overrides per enrollment/component
- see audit log entries for overrides

---

# 10) Audit Log Requirements (Unlock-related)

Log events:
- prerequisite rule created/updated/deleted
- drip rule created/updated/deleted
- component manually unlocked
- override applied (exempt/manual unlock/grace unlock)
Each log entry includes:
- actor_user_id
- cycle_id
- enrollment_id (if applicable)
- component_id (if applicable)
- timestamp
- reason/notes (optional but recommended)

---

End of file.
