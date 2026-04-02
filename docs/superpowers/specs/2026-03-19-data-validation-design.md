# Data Validation Tool — Design Spec

**Date:** 2026-03-19
**Status:** Draft (rev 3 — all spec review issues resolved)
**Scope:** WP-CLI command + Claude Code skill for post-import data integrity validation across hl_* tables

---

## Problem

After running import/seed/setup CLI commands (ELCPB, ECSELent Adventures, Short Courses, Lutheran, Palm Beach), there is no systematic way to verify data integrity. Orphaned records, missing assessment instances, and cross-cycle mismatches can silently break frontend pages without any visible error. Currently, the only way to catch these is to manually spot-check the site or hit a bug in production.

## Solution

A registry-based validation service (`HL_Validation_Service`) invoked via `wp hl-core validate`, with a companion Claude Code skill that teaches Claude when/how to run it and interpret results.

## Architecture

### Files

| File | Purpose |
|------|---------|
| `includes/services/class-hl-validation-service.php` | Check registry, execution engine, result collection |
| `includes/cli/class-hl-cli-validate.php` | WP-CLI command — thin wrapper calling the service |
| `.claude/skills/data-validation.md` | Claude Code skill — when/how/interpret |

### Integration

- `HL_Validation_Service` follows existing service patterns (static methods, `$wpdb` for queries)
- CLI class registers via `WP_CLI::add_command()` in `hl-core.php` alongside existing commands
- No new DB tables, no new admin pages, no new REST endpoints

## Schema Reference (Primary Keys)

All query patterns below use actual column names from `HL_Installer::create_tables()`:

| Table | PK Column | Notes |
|-------|-----------|-------|
| `hl_cycle` | `cycle_id` | |
| `hl_partnership` | `partnership_id` | |
| `hl_enrollment` | `enrollment_id` | |
| `hl_pathway` | `pathway_id` | |
| `hl_component` | `component_id` | |
| `hl_component_state` | `state_id` | |
| `hl_team` | `team_id` | |
| `hl_team_membership` | (composite) | UNIQUE on `(team_id, enrollment_id)` — no auto-increment PK |
| `hl_classroom` | `classroom_id` | |
| `hl_teaching_assignment` | `assignment_id` | |
| `hl_child` | `child_id` | |
| `hl_child_classroom_current` | `id` | Generic PK |
| `hl_child_cycle_snapshot` | `snapshot_id` | |
| `hl_orgunit` | `orgunit_id` | |
| `hl_instrument` | `instrument_id` | Child assessment instruments |
| `hl_teacher_assessment_instrument` | `instrument_id` | Teacher assessment instruments |
| `hl_child_assessment_instance` | `instance_id` | FKs: `enrollment_id`, `classroom_id`, `cycle_id`, `component_id`, `school_id` — **no `teaching_assignment_id`** |
| `hl_child_assessment_childrow` | `row_id` | FKs: `instance_id`, `child_id`, `instrument_id` |
| `hl_teacher_assessment_instance` | `instance_id` | FKs: `enrollment_id`, `cycle_id`, `component_id`, `instrument_id` |
| `hl_observation` | `observation_id` | FKs: `cycle_id`, `mentor_enrollment_id`, `teacher_enrollment_id`, `school_id`, `classroom_id` |
| `hl_observation_attachment` | `attachment_id` | FK: `observation_id` |
| `hl_coaching_session` | `session_id` | FKs: `cycle_id`, `coach_user_id`, `mentor_enrollment_id` |
| `hl_coaching_session_observation` | `link_id` | FKs: `session_id`, `observation_id` |
| `hl_coaching_attachment` | `attachment_id` | FK: `session_id` |
| `hl_coach_assignment` | `coach_assignment_id` | FKs: `coach_user_id`, `cycle_id`, `scope_id` |
| `hl_pathway_assignment` | `assignment_id` | FKs: `enrollment_id`, `pathway_id` |
| `hl_completion_rollup` | `rollup_id` | FKs: `enrollment_id`, `cycle_id` |
| `hl_cycle_school` | `id` | FKs: `cycle_id`, `school_id` |
| `hl_component_prereq_group` | `group_id` | FK: `component_id` |
| `hl_component_prereq_item` | `item_id` | FKs: `group_id`, `prerequisite_component_id` |
| `hl_component_drip_rule` | `rule_id` | FKs: `component_id`, `base_component_id` |
| `hl_component_override` | `override_id` | FKs: `enrollment_id`, `component_id`, `applied_by_user_id` |

**Tables intentionally excluded from validation:**
- `hl_audit_log`, `hl_import_run`, `hl_cycle_email_log` — system/logging tables with no referential risk to frontend
- `hl_teacher_assessment_response`, `hl_observation_response` — DEPRECATED tables
- `hl_child_classroom_history` — append-only history table for audit trail; orphaned history rows don't affect functionality

## Check Registry

Each check is a flat array entry:

```php
[
    'key'         => 'orphaned_enrollments',
    'category'    => 'referential-core',
    'description' => 'Enrollments referencing nonexistent cycles',
    'callable'    => [self::class, 'check_orphaned_enrollments'],
]
```

Each check method signature:

```php
private static function check_orphaned_enrollments( ?int $cycle_id = null ): array {
    // Returns:
    return [
        'key'      => 'orphaned_enrollments',
        'passed'   => true|false,
        'count'    => 0,        // number of failures
        'failures' => [         // empty if passed
            ['enrollment_id' => 42, 'cycle_id' => 99, 'detail' => 'Cycle does not exist'],
        ],
    ];
}
```

## Check Categories (59 checks)

### referential-core (13 checks)
Validates foreign key integrity for the core entity chain.

| Check | Query Pattern |
|-------|---------------|
| Enrollment → Cycle | `hl_enrollment e LEFT JOIN hl_cycle c ON e.cycle_id = c.cycle_id WHERE c.cycle_id IS NULL` |
| Enrollment → User | `hl_enrollment e LEFT JOIN wp_users u ON e.user_id = u.ID WHERE u.ID IS NULL` |
| Cycle → Partnership | `hl_cycle c LEFT JOIN hl_partnership p ON c.partnership_id = p.partnership_id WHERE c.partnership_id IS NOT NULL AND p.partnership_id IS NULL` |
| CycleSchool → Cycle | `hl_cycle_school cs LEFT JOIN hl_cycle c ON cs.cycle_id = c.cycle_id WHERE c.cycle_id IS NULL` |
| CycleSchool → School | `hl_cycle_school cs LEFT JOIN hl_orgunit o ON cs.school_id = o.orgunit_id WHERE o.orgunit_id IS NULL` |
| Pathway → Cycle | `hl_pathway p LEFT JOIN hl_cycle c ON p.cycle_id = c.cycle_id WHERE c.cycle_id IS NULL` |
| Component → Pathway | `hl_component c LEFT JOIN hl_pathway p ON c.pathway_id = p.pathway_id WHERE p.pathway_id IS NULL` |
| ActivityState → Enrollment | `hl_component_state s LEFT JOIN hl_enrollment e ON s.enrollment_id = e.enrollment_id WHERE e.enrollment_id IS NULL` |
| ActivityState → Component | `hl_component_state s LEFT JOIN hl_component c ON s.component_id = c.component_id WHERE c.component_id IS NULL` |
| CompletionRollup → Enrollment | `hl_completion_rollup r LEFT JOIN hl_enrollment e ON r.enrollment_id = e.enrollment_id WHERE e.enrollment_id IS NULL` |
| CompletionRollup → Cycle | `hl_completion_rollup r LEFT JOIN hl_cycle c ON r.cycle_id = c.cycle_id WHERE c.cycle_id IS NULL` |
| PathwayAssignment → Enrollment | `hl_pathway_assignment pa LEFT JOIN hl_enrollment e ON pa.enrollment_id = e.enrollment_id WHERE e.enrollment_id IS NULL` |
| PathwayAssignment → Pathway | `hl_pathway_assignment pa LEFT JOIN hl_pathway p ON pa.pathway_id = p.pathway_id WHERE p.pathway_id IS NULL` |

### referential-rules (7 checks)
Validates component prerequisite, drip rule, and override references.

| Check | Query Pattern |
|-------|---------------|
| PrereqGroup → Component | `hl_component_prereq_group g LEFT JOIN hl_component c ON g.component_id = c.component_id WHERE c.component_id IS NULL` |
| PrereqItem → PrereqGroup | `hl_component_prereq_item i LEFT JOIN hl_component_prereq_group g ON i.group_id = g.group_id WHERE g.group_id IS NULL` |
| PrereqItem → PrerequisiteComponent | `hl_component_prereq_item i LEFT JOIN hl_component c ON i.prerequisite_component_id = c.component_id WHERE c.component_id IS NULL` |
| DripRule → Component | `hl_component_drip_rule d LEFT JOIN hl_component c ON d.component_id = c.component_id WHERE c.component_id IS NULL` |
| DripRule → BaseComponent | `hl_component_drip_rule d LEFT JOIN hl_component c ON d.base_component_id = c.component_id WHERE d.base_component_id IS NOT NULL AND c.component_id IS NULL` |
| ComponentOverride → Enrollment | `hl_component_override o LEFT JOIN hl_enrollment e ON o.enrollment_id = e.enrollment_id WHERE e.enrollment_id IS NULL` |
| ComponentOverride → Component | `hl_component_override o LEFT JOIN hl_component c ON o.component_id = c.component_id WHERE c.component_id IS NULL` |

### referential-classrooms (7 checks)
Validates classroom/child/teaching entity references.

| Check | Query Pattern |
|-------|---------------|
| Classroom → School | `hl_classroom cl LEFT JOIN hl_orgunit o ON cl.school_id = o.orgunit_id WHERE o.orgunit_id IS NULL` |
| TeachingAssignment → Enrollment | `hl_teaching_assignment ta LEFT JOIN hl_enrollment e ON ta.enrollment_id = e.enrollment_id WHERE e.enrollment_id IS NULL` |
| TeachingAssignment → Classroom | `hl_teaching_assignment ta LEFT JOIN hl_classroom cl ON ta.classroom_id = cl.classroom_id WHERE cl.classroom_id IS NULL` |
| ChildClassroomCurrent → Child | `hl_child_classroom_current cc LEFT JOIN hl_child ch ON cc.child_id = ch.child_id WHERE ch.child_id IS NULL` |
| ChildClassroomCurrent → Classroom | `hl_child_classroom_current cc LEFT JOIN hl_classroom cl ON cc.classroom_id = cl.classroom_id WHERE cl.classroom_id IS NULL` |
| ChildSnapshot → Child | `hl_child_cycle_snapshot s LEFT JOIN hl_child ch ON s.child_id = ch.child_id WHERE ch.child_id IS NULL` |
| ChildSnapshot → Cycle | `hl_child_cycle_snapshot s LEFT JOIN hl_cycle c ON s.cycle_id = c.cycle_id WHERE c.cycle_id IS NULL` |

### referential-teams (9 checks)
Validates team/coaching entity references.

| Check | Query Pattern |
|-------|---------------|
| TeamMembership → Team | `hl_team_membership tm LEFT JOIN hl_team t ON tm.team_id = t.team_id WHERE t.team_id IS NULL` |
| TeamMembership → Enrollment | `hl_team_membership tm LEFT JOIN hl_enrollment e ON tm.enrollment_id = e.enrollment_id WHERE e.enrollment_id IS NULL` |
| Team → Cycle | `hl_team t LEFT JOIN hl_cycle c ON t.cycle_id = c.cycle_id WHERE c.cycle_id IS NULL` |
| Team → School | `hl_team t LEFT JOIN hl_orgunit o ON t.school_id = o.orgunit_id WHERE o.orgunit_id IS NULL` |
| CoachAssignment → Cycle | `hl_coach_assignment ca LEFT JOIN hl_cycle c ON ca.cycle_id = c.cycle_id WHERE c.cycle_id IS NULL` |
| CoachAssignment → CoachUser | `hl_coach_assignment ca LEFT JOIN wp_users u ON ca.coach_user_id = u.ID WHERE u.ID IS NULL` |
| CoachingSession → Cycle | `hl_coaching_session cs LEFT JOIN hl_cycle c ON cs.cycle_id = c.cycle_id WHERE c.cycle_id IS NULL` |
| CoachingSession → CoachUser | `hl_coaching_session cs LEFT JOIN wp_users u ON cs.coach_user_id = u.ID WHERE u.ID IS NULL` |
| CoachingSession → MentorEnrollment | `hl_coaching_session cs LEFT JOIN hl_enrollment e ON cs.mentor_enrollment_id = e.enrollment_id WHERE e.enrollment_id IS NULL` |

### referential-observations (5 checks)
Validates observation and attachment references.

| Check | Query Pattern |
|-------|---------------|
| Observation → Cycle | `hl_observation ob LEFT JOIN hl_cycle c ON ob.cycle_id = c.cycle_id WHERE c.cycle_id IS NULL` |
| Observation → MentorEnrollment | `hl_observation ob LEFT JOIN hl_enrollment e ON ob.mentor_enrollment_id = e.enrollment_id WHERE e.enrollment_id IS NULL` |
| ObservationAttachment → Observation | `hl_observation_attachment oa LEFT JOIN hl_observation ob ON oa.observation_id = ob.observation_id WHERE ob.observation_id IS NULL` |
| CoachingSessionObservation → Session | `hl_coaching_session_observation cso LEFT JOIN hl_coaching_session cs ON cso.session_id = cs.session_id WHERE cs.session_id IS NULL` |
| CoachingSessionObservation → Observation | `hl_coaching_session_observation cso LEFT JOIN hl_observation ob ON cso.observation_id = ob.observation_id WHERE ob.observation_id IS NULL` |

### assessments (12 checks)
Validates assessment entity references. Note: `hl_child_assessment_instance` has no `teaching_assignment_id` column — it links to enrollments via `enrollment_id` and classrooms via `classroom_id`.

| Check | Query Pattern |
|-------|---------------|
| ChildAssessmentInstance → Enrollment | `hl_child_assessment_instance ci LEFT JOIN hl_enrollment e ON ci.enrollment_id = e.enrollment_id WHERE e.enrollment_id IS NULL` |
| ChildAssessmentInstance → Classroom | `hl_child_assessment_instance ci LEFT JOIN hl_classroom cl ON ci.classroom_id = cl.classroom_id WHERE ci.classroom_id IS NOT NULL AND cl.classroom_id IS NULL` |
| ChildAssessmentInstance → Cycle | `hl_child_assessment_instance ci LEFT JOIN hl_cycle c ON ci.cycle_id = c.cycle_id WHERE c.cycle_id IS NULL` |
| ChildAssessmentInstance → Component | `hl_child_assessment_instance ci LEFT JOIN hl_component co ON ci.component_id = co.component_id WHERE ci.component_id IS NOT NULL AND co.component_id IS NULL` |
| ChildAssessmentInstance → School | `hl_child_assessment_instance ci LEFT JOIN hl_orgunit o ON ci.school_id = o.orgunit_id WHERE ci.school_id IS NOT NULL AND o.orgunit_id IS NULL` |
| ChildAssessmentChildrow → Instance | `hl_child_assessment_childrow cr LEFT JOIN hl_child_assessment_instance ci ON cr.instance_id = ci.instance_id WHERE ci.instance_id IS NULL` |
| ChildAssessmentChildrow → Child | `hl_child_assessment_childrow cr LEFT JOIN hl_child ch ON cr.child_id = ch.child_id WHERE ch.child_id IS NULL` |
| ChildAssessmentChildrow → Instrument | `hl_child_assessment_childrow cr LEFT JOIN hl_instrument i ON cr.instrument_id = i.instrument_id WHERE i.instrument_id IS NULL` |
| TeacherAssessmentInstance → Enrollment | `hl_teacher_assessment_instance ti LEFT JOIN hl_enrollment e ON ti.enrollment_id = e.enrollment_id WHERE e.enrollment_id IS NULL` |
| TeacherAssessmentInstance → Cycle | `hl_teacher_assessment_instance ti LEFT JOIN hl_cycle c ON ti.cycle_id = c.cycle_id WHERE c.cycle_id IS NULL` |
| TeacherAssessmentInstance → Component | `hl_teacher_assessment_instance ti LEFT JOIN hl_component co ON ti.component_id = co.component_id WHERE ti.component_id IS NOT NULL AND co.component_id IS NULL` |
| TeacherAssessmentInstance → Instrument | `hl_teacher_assessment_instance ti LEFT JOIN hl_teacher_assessment_instrument i ON ti.instrument_id = i.instrument_id WHERE ti.instrument_id IS NOT NULL AND i.instrument_id IS NULL` |

### consistency (3 checks)
Validates cross-entity logical consistency (records exist but are wired to the wrong cycle).

| Check | Logic |
|-------|-------|
| PathwayAssignment cycle mismatch | `hl_pathway_assignment pa JOIN hl_enrollment e ON pa.enrollment_id = e.enrollment_id JOIN hl_pathway p ON pa.pathway_id = p.pathway_id WHERE e.cycle_id != p.cycle_id` |
| TeachingAssignment cycle mismatch | Teaching assignments where the enrollment's cycle does not have the classroom's school linked. Join path: `hl_teaching_assignment ta → hl_enrollment e (enrollment_id) → hl_classroom cl (classroom_id)`, then verify `EXISTS (SELECT 1 FROM hl_cycle_school WHERE cycle_id = e.cycle_id AND school_id = cl.school_id)`. Failures = the enrollment's cycle has no link to the classroom's school. |
| TeamMembership cycle mismatch | `hl_team_membership tm JOIN hl_enrollment e ON tm.enrollment_id = e.enrollment_id JOIN hl_team t ON tm.team_id = t.team_id WHERE e.cycle_id != t.cycle_id` |

### completeness (3 checks)
Validates expected records exist.

| Check | Logic |
|-------|-------|
| TeachingAssignments missing assessment instances | Teaching assignments where no `hl_child_assessment_instance` exists with matching `enrollment_id` AND `classroom_id`, in cycles that have at least one child instrument. Join: `hl_teaching_assignment ta` LEFT JOIN `hl_child_assessment_instance ci ON (ta.enrollment_id = ci.enrollment_id AND ta.classroom_id = ci.classroom_id)` WHERE `ci.instance_id IS NULL` AND cycle has instruments. |
| Enrollments missing pathway assignments | Active enrollments with no row in `hl_pathway_assignment`. `hl_enrollment e LEFT JOIN hl_pathway_assignment pa ON e.enrollment_id = pa.enrollment_id WHERE pa.assignment_id IS NULL`. |
| Pathways with zero components | `hl_pathway p LEFT JOIN hl_component c ON p.pathway_id = c.pathway_id GROUP BY p.pathway_id HAVING COUNT(c.component_id) = 0`. |

## CLI Interface

```
wp hl-core validate [--category=<category>] [--cycle=<id>] [--format=<format>] [--verbose]
```

| Flag | Default | Behavior |
|------|---------|----------|
| `--category` | all | Run only one category: `referential-core`, `referential-rules`, `referential-classrooms`, `referential-teams`, `referential-observations`, `assessments`, `consistency`, `completeness` |
| `--cycle` | all | Scope checks to records associated with a specific cycle |
| `--format` | `table` | Output: `table` (WP-CLI table), `json`, `csv` |
| `--verbose` | off | Show passing checks in the output (default shows only failures + summary line) |

### Cycle Scoping

When `--cycle=<id>` is provided:

- **Direct (WHERE cycle_id = %d):** `hl_enrollment`, `hl_pathway`, `hl_team`, `hl_coaching_session`, `hl_coach_assignment`, `hl_observation`, `hl_completion_rollup`, `hl_child_assessment_instance`, `hl_teacher_assessment_instance`, `hl_cycle_school`, `hl_child_cycle_snapshot`
- **One hop (JOIN through parent):** `hl_component` (via pathway), `hl_team_membership` (via team), `hl_pathway_assignment` (via enrollment), `hl_teaching_assignment` (via enrollment), `hl_component_state` (via enrollment), `hl_component_override` (via enrollment), `hl_coaching_session_observation` (via session), `hl_coaching_attachment` (via session), `hl_observation_attachment` (via observation)
- **Two hops:** `hl_child_assessment_childrow` (via instance → enrollment), `hl_component_prereq_group` (via component → pathway), `hl_component_prereq_item` (via group → component → pathway), `hl_component_drip_rule` (via component → pathway)
- **Skipped when cycle-scoped:** Cycle→Partnership (irrelevant for single cycle), Classroom→School (classrooms aren't cycle-specific), ChildClassroomCurrent (children aren't cycle-specific)

### Exit Codes

- `0` — all checks passed
- `1` — one or more checks failed

### Output Example

```
Validation Results: 3 issues found across 59 checks

+-------------------------+-------------------------------------------+--------+-------+
| Category                | Check                                     | Status | Count |
+-------------------------+-------------------------------------------+--------+-------+
| referential-core        | Enrollments → User                        | FAIL   |     2 |
| assessments             | TeachingAssignments missing instances      | FAIL   |     4 |
| consistency             | PathwayAssignment cycle mismatch           | FAIL   |     1 |
+-------------------------+-------------------------------------------+--------+-------+

--- FAILURES ---

[referential-core] Enrollments → User (2 failures)
  • Enrollment #145: user_id=389 — WP user does not exist
  • Enrollment #146: user_id=390 — WP user does not exist

[assessments] TeachingAssignments missing instances (4 failures)
  • TeachingAssignment #23 (cycle: ELCPB-Y1-2025, enrollment_id=12, classroom_id=5) — no child assessment instances found
  • TeachingAssignment #24 (cycle: ELCPB-Y1-2025, enrollment_id=13, classroom_id=5) — no child assessment instances found
  ...

[consistency] PathwayAssignment cycle mismatch (1 failure)
  • PathwayAssignment #8: enrollment #42 (cycle_id=3) assigned to pathway #15 (cycle_id=5)

Validation complete: 56 passed, 3 failed (7 issues total)
```

## Claude Code Skill

File: `.claude/skills/data-validation.md`

### When to invoke

- After running any import/seed/setup CLI command
- When debugging frontend display issues that could be data-related
- When user says "validate", "health check", "check data", or "something looks wrong"

### How to use

- After an import: `wp hl-core validate --cycle=<id>`
- Full system check: `wp hl-core validate`
- Targeted investigation: `wp hl-core validate --category=assessments --cycle=3`

### How to interpret results

| Result Type | Meaning | Recommended Action |
|-------------|---------|-------------------|
| All PASS | Data is clean | Proceed with confidence |
| `referential-*` FAIL | Broken FK references — failed/incomplete import | Recommend re-running import with `--clean`, or manual SQL fix |
| `consistency` FAIL | Records wired to wrong cycle — likely script bug | Flag to user for manual review |
| `completeness` FAIL | Expected records missing — missed import step | Recommend running the specific generation step |

### What NOT to do

- Never auto-fix data based on validation results
- Never run `--clean` commands without user approval
- Always report findings and let the user decide

## Out of Scope (v1)

- Auto-fix mode (`--fix` flag)
- Admin UI page for validation results
- Scheduled/automatic validation runs
- Performance benchmarking of queries
- Validation of data *values* (e.g., date ranges, score bounds) — only structural/referential checks
- `ComponentOverride → applied_by_user_id` — WP user check is low risk; overrides are admin-created
- `Observation → teacher_enrollment_id` — nullable FK; teacher may have been unenrolled from cycle after observation was created; observation is owned by mentor, not teacher
- `CoachingAttachment → Session` — attachments are WP Media references managed through the coaching admin UI; orphaned attachments are a cosmetic issue, not a data integrity risk

## Dependencies

- No new tables or schema changes
- No new PHP dependencies
- Requires `$wpdb` (WordPress database abstraction) — already available
- WP-CLI (already a dependency for all existing CLI commands)
