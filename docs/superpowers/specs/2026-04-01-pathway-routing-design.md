# Pathway Routing Engine — Design Spec

**Date:** 2026-04-01
**Status:** Approved
**Scope:** Automatic pathway assignment based on user-level course completion (stages) and enrollment role. Includes bug fixes to role normalization, sync_role_defaults, audit logging, and pathway data that would undermine routing if left unfixed.

---

## Problem

When enrolling participants in a multi-phase cycle (like ELCPB Year 2), the system doesn't know which pathway to assign. The existing `sync_role_defaults()` has three compounding bugs:
1. It assigns **ALL** matching pathways instead of one (inner `break` doesn't exit the pathway loop)
2. Role case mismatch: maps to Title Case but pathways store lowercase — School Leader and District Leader never match
3. District Leaders are not in Streamlined pathway `target_roles` at all

Admins must manually specify pathways in the CSV or admin form, which requires them to know the program's progression rules. For 50+ participants, this is error-prone.

## Solution

A **Pathway Routing Service** that determines the correct pathway based on:
1. The participant's **role** in the new enrollment
2. Which **course stages** the participant has already completed (checked at user-level via LearnDash)

Plus **bug fixes** to role normalization, sync_role_defaults, audit logging, and pathway data.

No new database tables. No schema migration. Stages and routing rules are defined as PHP configuration arrays in the service class.

---

## Architecture

### Files

| File | Action | Responsibility |
|------|--------|----------------|
| `includes/services/class-hl-pathway-routing-service.php` | **Create** | Stage definitions, routing rules, `resolve_pathway()`, `get_completed_stages()` |
| `includes/services/class-hl-pathway-assignment-service.php` | **Modify** | Fix `sync_role_defaults` (one pathway, not all), try routing first then target_roles fallback, fix audit logging |
| `includes/services/class-hl-import-participant-handler.php` | **Modify** | Call routing during validate + commit, normalize roles to lowercase, clear stale pathways on UPDATE |
| `includes/admin/class-hl-admin-enrollments.php` | **Modify** | AJAX auto-suggest pathway, normalize roles to lowercase on save |
| `hl-core.php` | **Modify** | Add require_once for routing service |

### Integration Flow

```
resolve_pathway($user_id, $role, $cycle_id)
    │
    ├─ Get completed stages (LearnDash queries, user-level)
    ├─ Match role + stages against routing rules (priority order)
    ├─ Look up pathway_code in target cycle
    │
    ├─ Match found → return pathway_id
    └─ No match → return null (caller falls back to target_roles)
```

**Three consumers call the same method:**
1. CSV Import — during validate (preview suggestion) and commit (assignment)
2. Admin Enrollment Form — AJAX auto-suggest when cycle + role selected
3. `sync_role_defaults` — tries routing first, falls back to target_roles for non-B2E cycles

---

## Stage Definitions

Stages are groups of LearnDash courses representing user-level milestones. A stage is "completed" when **ALL** courses in the group are completed by the user. Completion is user-level in LearnDash and persists across enrollments/cycles.

| Stage | Label | LearnDash Course IDs |
|-------|-------|---------------------|
| A | Mentor Stage 1 | MC1 (30293), MC2 (30295) |
| C | Teacher Stage 1 | TC1 (30280), TC2 (30284), TC3 (30286), TC4 (30288) |
| E | Streamlined Stage 1 | TC0 (31037), TC1_S (31332), TC2_S (31333), TC3_S (31334), TC4_S (31335), MC1_S (31387), MC2_S (31388) |

**Only 3 stages.** Stages B (MC3-MC4) and D (TC5-TC8) were removed — no routing rule references them.

**Stage matching is inclusive:** "user has completed ALL required stages (and possibly others)." A user with stages [A, C] matches rules requiring [A], [C], or [A, C].

**Course IDs are shared across all cycles.** A user who completed TC1 in Cycle 1 has it completed for all future cycles.

---

## Routing Rules

Rules are evaluated in priority order. The **first matching rule** wins. More specific stage requirements come first.

| Priority | Role | Required Stages | Pathway Code | Scenario |
|----------|------|----------------|--------------|----------|
| 1 | mentor | C + A | b2e-mentor-completion | Has both teacher + mentor stage 1 courses |
| 2 | mentor | C | b2e-mentor-transition | Teacher promoted to mentor |
| 3 | mentor | A | b2e-mentor-phase-2 | Returning mentor |
| 4 | mentor | (none) | b2e-mentor-phase-1 | New mentor |
| 5 | teacher | C | b2e-teacher-phase-2 | Returning teacher |
| 6 | teacher | (none) | b2e-teacher-phase-1 | New teacher |
| 7 | school_leader | E | b2e-streamlined-phase-2 | Returning school leader |
| 8 | school_leader | (none) | b2e-streamlined-phase-1 | New school leader |
| 9 | district_leader | E | b2e-streamlined-phase-2 | Returning district leader |
| 10 | district_leader | (none) | b2e-streamlined-phase-1 | New district leader |

**Precedence matters for mentors:** Rule 1 (C+A) must come before Rule 2 (C) and Rule 3 (A). A mentor with both C and A gets Mentor Completion, not Transition or Phase 2.

**Rule evaluation algorithm:**
1. Normalize role to lowercase (e.g., "School Leader" → "school_leader")
2. Get user's completed stages via LearnDash queries
3. Filter rules to matching role
4. For each rule in priority order: check if user has ALL required stages (inclusive)
5. First match → look up pathway by `pathway_code` in the target `cycle_id`
6. No match → return null (caller falls back to legacy target_roles matching)

---

## The `resolve_pathway` Method

```php
/**
 * Resolve the correct pathway for a user being enrolled in a cycle.
 *
 * @param int|null $user_id   WordPress user ID. Null for new users (no account yet).
 * @param string   $role      Role string (any case — normalized internally).
 * @param int      $cycle_id  Target cycle.
 * @return int|null            Pathway ID if a routing rule matches, null otherwise.
 */
public static function resolve_pathway($user_id, $role, $cycle_id)
```

**Edge case behavior:**
| Input | Behavior |
|-------|----------|
| `$user_id = null` (new user) | All stages incomplete → entry-level pathway |
| User exists, no LD completions | Same → entry-level |
| Partial stage (3 of 4 courses) | Stage NOT complete → treated as missing |
| Pathway code not in target cycle | Return null → caller uses legacy fallback |
| Pathway exists but `active_status = 0` | Return null |
| LearnDash not active | Return null (graceful degradation) |
| Role not in routing rules | Return null |
| Multiple roles on enrollment | Caller passes one role; use first role that matches a rule |

---

## Bug Fixes (Required for Routing to Work)

### Fix 1: Role Format Normalization

**Problem:** Import handler stores roles as Title Case `["Teacher"]`, admin form stores lowercase `["teacher"]`. `sync_role_defaults` comparison fails for multi-word roles.

**Fix:**
- All role storage normalizes to **lowercase with underscores**: `teacher`, `mentor`, `school_leader`, `district_leader`
- `HL_Import_Participant_Handler::resolve_role()` returns lowercase
- `HL_Admin_Enrollments` save handler stores lowercase
- `sync_role_defaults` role comparison uses lowercase on both sides
- `HL_Import_Participant_Handler::validate()` role-change detection uses case-insensitive comparison

### Fix 2: `sync_role_defaults` Assigns ONE Pathway, Not All

**Problem:** Inner `break` only exits the role loop. All role-matching pathways get assigned.

**Fix:** Restructure to:
1. Try `HL_Pathway_Routing_Service::resolve_pathway()` first
2. If routing returns a pathway_id → assign it, skip to next enrollment
3. If routing returns null → use target_roles matching but assign only the **first** match (add `break 2` or restructure loops)

### Fix 3: Audit Logging in `HL_Pathway_Assignment_Service`

**Problem:** `assign_pathway()` and `unassign_pathway()` call `HL_Audit_Service::log()` with 6 positional args. The correct signature is `log($action_type, $data_array)`.

**Fix:** Update both methods to use the array-based format, matching the pattern used in the import handlers.

### Fix 4: District Leader Added to Streamlined `target_roles`

**Problem:** Streamlined pathways have `target_roles = ["school_leader"]` only. District Leaders get no match from `sync_role_defaults`.

**Fix:** Update Streamlined pathway `target_roles` to `["school_leader", "district_leader"]` in:
- ELCPB Y2 seeder (`class-hl-cli-setup-elcpb-y2-v2.php`)
- Beginnings seeder (`class-hl-cli-seed-beginnings.php`)
- Live database (UPDATE query for existing pathways)

### Fix 5: Import UPDATE Clears Stale Pathway on Role Change

**Problem:** If a Teacher is re-imported as a Mentor, the role updates but the old "Teacher Phase 1" pathway stays assigned.

**Fix:** In the import commit, when a row is UPDATE and the role changed, unassign all existing pathways for that enrollment before assigning the new routed one.

---

## Consumer Integration

### 1. CSV Import (`HL_Import_Participant_Handler`)

**During `validate()`:**
- If CSV row has explicit pathway → use it, show as "Pathway: X (from CSV)"
- If CSV row has no pathway AND user exists → call `resolve_pathway()` → show as "Pathway: X (auto-routed based on course history)"
- If CSV row has no pathway AND user is new → call `resolve_pathway(null, ...)` → entry-level → show as "Pathway: X (default for new participants)"
- Add `pathway_source` field to preview rows: `'csv'`, `'routed'`, or `'default'`

**During `commit()`:**
- Priority: explicit CSV pathway > routing service > null (no pathway assigned, admin handles later)
- Remove `sync_role_defaults($cycle_id)` call at end of commit — routing handles B2E, and non-B2E cycles don't go through this import flow
- On UPDATE rows with role change: unassign existing pathways first, then route

### 2. Admin Enrollment Form (`HL_Admin_Enrollments`)

**New AJAX endpoint:** `wp_ajax_hl_suggest_pathway`
- Params: `user_id` (0 for new), `role`, `cycle_id`
- Calls `HL_Pathway_Routing_Service::resolve_pathway()`
- Returns: `{ pathway_id, pathway_name, source: 'routed'|'default' }`

**Frontend behavior:**
- When admin changes cycle dropdown OR role checkboxes → fire AJAX
- Auto-select the suggested pathway in the dropdown
- Show label: "Auto-suggested" or "Default for new participants"
- Admin can override → saved as `'explicit'` type (existing behavior)

**On save (no pathway selected):** If admin leaves pathway blank, call routing service server-side as fallback before saving.

### 3. `sync_role_defaults` (`HL_Pathway_Assignment_Service`)

**Updated algorithm:**
```
For each enrollment without any pathway assignment:
    For each role in enrollment.roles:
        1. Try routing: resolve_pathway(user_id, role, cycle_id)
        2. If routing returns pathway_id → assign as 'role_default', DONE (next enrollment)
        3. If routing returns null → try target_roles matching (ONE match only)
        4. If target_roles match → assign as 'role_default', DONE
        5. No match → skip (no assignment)
```

This ensures:
- B2E cycles: routing service picks the correct phase
- Non-B2E cycles (Short Courses, EA): routing returns null, target_roles fallback works
- Only ONE pathway per enrollment (never all matching)

---

## What's NOT in Scope

- Admin UI for editing stages or routing rules (they're in code)
- Stage completion display on user profiles or dashboards
- Automatic pathway re-assignment when courses are completed mid-cycle (routing is one-shot at enrollment time)
- Partial stage credit
- Handling users with changed email addresses (new WP account = no LearnDash history; admin must assign manually)

---

## Known Limitations

### `wp_create_user` does not participate in MySQL transaction

The import handler's all-or-nothing transaction wraps `hl_*` table operations. But `wp_create_user()` commits to `wp_users` and `wp_usermeta` immediately (WordPress doesn't use plugin transactions). If the transaction rolls back, orphaned WP user accounts may persist. This is a pre-existing limitation of the import system, not introduced by this feature. Re-running the import after failure handles this gracefully (user already exists → create enrollment only).

### Routing is one-shot

Routing runs at enrollment time. If a participant completes more courses mid-cycle, their pathway does NOT automatically change. This is by design — mid-cycle pathway changes would disrupt progress tracking and component state. Admin can manually reassign via the User Profile Manage tab if needed.

---

## Validation Against Real ELCPB Data

All 9 scenario types from the actual Cycle 1 → Cycle 2 roster:

| Person | C1 Role | C2 Role | Stages Completed | Routing Result | Correct? |
|--------|---------|---------|-----------------|----------------|----------|
| Antkeria Smith | Teacher | Teacher | C | Teacher Phase 2 | Yes |
| Adyerenys Gonzalez | (new) | Teacher | None | Teacher Phase 1 | Yes |
| La'Quittia Johnson | Mentor | Mentor | A+C | Mentor Completion | Yes |
| Martanae Lurry | Teacher | Mentor | C | Mentor Transition | Yes |
| Marquita Brown | Mentor | Teacher | A+C | Teacher Phase 2 (has C) | Yes |
| Akia Davis | Director | District Leader | E | Streamlined Phase 2 | Yes |
| Erin Gallagher | (new) | District Leader | None | Streamlined Phase 1 | Yes |
| Angela Brown | Director | School Leader | E | Streamlined Phase 2 | Yes |
| Iyana Baugh | (new) | Teacher | None | Teacher Phase 1 | Yes |

---

## Adversarial Testing Summary

Two independent adversarial agents stress-tested this design. Issues found and resolution:

| Finding | Severity | Resolution |
|---------|----------|------------|
| sync_role_defaults assigns ALL matching pathways | CRITICAL | Fix 2: restructure to assign ONE + routing first |
| "Teacher + Stage A" rule is unsafe | CRITICAL | Removed from rules (Stage C covers it) |
| District Leaders not in Streamlined target_roles | CRITICAL | Fix 4: add to target_roles |
| Role case mismatch breaks sync for leaders | CRITICAL | Fix 1: normalize all roles to lowercase |
| Audit logging wrong signature in assignment service | CRITICAL | Fix 3: update to array format |
| Import UPDATE doesn't clear stale pathway | IMPORTANT | Fix 5: unassign on role change |
| Stage E missing MC1_S/MC2_S courses | IMPORTANT | Fixed in stage definition |
| Stages B/D defined but unused | IMPORTANT | Removed |
| wp_create_user outside transaction | IMPORTANT | Documented as known limitation |
| No re-routing after course completion | IMPORTANT | Documented as design decision |
| New email = no LearnDash history | IMPORTANT | Documented as known limitation |
| Non-B2E cycles need target_roles fallback | IMPORTANT | sync_role_defaults tries routing then falls back |
