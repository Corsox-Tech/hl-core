# Classroom Management for Control Group Partnerships

**Date:** 2026-04-13
**Ticket:** #18 — Classroom Management on B2E Control Group
**Status:** Design
**Files affected:** `class-hl-admin-classrooms.php`, `class-hl-admin-cycles.php`

---

## Problem

An LSF provider requested a teacher classroom reassignment and adding a new user to a classroom in the control group cycle. Yuyan reported this is "not currently possible."

Investigation confirmed the code has no explicit restriction on control group classrooms. The Classrooms tab is visible, the standalone admin page works, and all 51 control group enrollments have the `teacher` role. The problem is a set of UX gaps that combine to make classroom management effectively unusable:

1. **No teacher reassignment flow.** The Add Teaching Assignment form only shows *unassigned* teachers. 8 of 11 LSF schools have 100% of their teachers already assigned, so the form shows "All available teachers are already assigned." To move a teacher between classrooms requires a 6-step, 2-page workaround (navigate to old classroom, select cycle, remove, navigate to new classroom, select cycle, add). Yuyan likely saw the empty form and concluded the feature doesn't work.

2. **No Cycle field on classroom create/edit forms.** The `hl_classroom` table has a `cycle_id` column, but neither the Create nor Edit form includes it. Creating a classroom from the admin page produces one with `cycle_id = NULL`, which won't appear in the cycle Classrooms tab (it filters by `cycle_id`).

3. **No cycle awareness on the Classrooms list page.** The standalone page shows all 99 classrooms across every cycle, sorted alphabetically, with only a School filter. No Cycle column or filter exists. Control group classrooms are mixed with program classrooms from the same schools.

4. **Cycle context lost on navigation.** Both the "Manage Classrooms" button and per-row "View" links on the cycle Classrooms tab link without passing `cycle_id`. Every navigation away from the cycle tab drops the cycle context.

## Solution

Four focused enhancements to the two existing admin files, plus one new service method. No schema changes, no new files, no new DB tables.

### A. Teacher Reassignment in Add Teaching Assignment Form

**File:** `class-hl-admin-classrooms.php` — `render_teaching_assignments_section()`

**Current behavior:** The teacher dropdown queries active enrollments with the `teacher` role at the classroom's school for the selected cycle, then filters out anyone already assigned to *any* classroom. If all teachers are assigned, the form shows "All available teachers are already assigned."

**New behavior:** The teacher dropdown has two optgroups:

1. **"Available Teachers"** — unassigned teachers (current behavior, unchanged).
2. **"Reassign from another classroom"** — teachers already assigned to a *different* classroom at the same school in the selected cycle. Each option shows: `"Teacher Name (currently: Classroom X)"`.

This mirrors the existing "Reassign from Other Classroom" pattern already used in the Children Roster section of the same page (lines 763-824).

**Reassign optgroup query:** To populate the second optgroup, query teachers assigned to other classrooms at the same school in the selected cycle:
```sql
SELECT ta.assignment_id, ta.classroom_id, ta.enrollment_id, cr.classroom_name, u.display_name, u.user_email
FROM {prefix}hl_teaching_assignment ta
JOIN {prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
JOIN {prefix}hl_classroom cr ON ta.classroom_id = cr.classroom_id
JOIN {prefix}wp_users u ON e.user_id = u.ID
WHERE e.cycle_id = %d AND e.school_id = %d AND ta.classroom_id != %d
ORDER BY u.display_name
```

**Form hidden field:** When a teacher from the "Reassign" optgroup is selected, include a hidden `reassign_from_classroom_id` field so the submit handler can detect a reassignment without an extra DB lookup.

**Confirmation dialog:** Add `onclick="return confirm('This will reassign [Teacher] from [Old Classroom] to this classroom. Continue?')"` on submit when the reassign optgroup is selected (JavaScript, consistent with existing Remove button pattern).

**On submit**, if `reassign_from_classroom_id` is present:
- Call the new `HL_Classroom_Service::reassign_teaching_assignment()` method (see below), which wraps both operations in a DB transaction.
- Show a success notice: "Teacher reassigned from [Old Classroom] to [New Classroom]." (new `assignment_reassigned` message key in the messages array).

**New service method:** `HL_Classroom_Service::reassign_teaching_assignment($enrollment_id, $old_classroom_id, $new_classroom_id, $data)`
- Wraps in `$wpdb->query('START TRANSACTION')` / `COMMIT` / `ROLLBACK`.
- Calls `delete_teaching_assignment()` for the old assignment.
- Calls `create_teaching_assignment()` for the new assignment.
- If either fails, rolls back and returns `WP_Error`.
- Fires `hl_core_teaching_assignment_changed` for both classrooms on success.

**Empty state hint:** When both optgroups are empty (no available teachers AND no reassignable teachers), show: "No teachers available. [Create an enrollment](?page=hl-enrollments) for this school first." with a link to the Enrollments admin page.

**Edge cases:**
- Teacher assigned to the *same* classroom: excluded from both optgroups (already assigned here).
- Teacher with assignments in multiple cycles: the reassignment only affects assignments in the *selected cycle*.

### B. Cycle-Aware Classrooms List Page

**File:** `class-hl-admin-classrooms.php` — `render_list()`

**Changes:**

1. **Add Cycle filter dropdown** next to the existing School filter. Query `hl_cycle` table for non-archived cycles (`status != 'archived'`), ordered by name. Accept `cycle_id` GET param. When set, add `WHERE c.cycle_id = %d` to the classroom query. Auto-select the inbound `cycle_id` value in the dropdown.

2. **Add Cycle column** to the table between School and Age Band. Join `hl_cycle` to get `cycle_name`. Display cycle name in each row.

3. **Accept `cycle_id` param from inbound links** so the cycle tab's "Manage Classrooms" button auto-filters the list.

### C. Cycle Field on Classroom Create/Edit Form

**File:** `class-hl-admin-classrooms.php` — `render_form()` and `handle_actions()`

**Form changes:**

1. Add a **Cycle dropdown** field after the School field. Query `hl_cycle` for non-archived cycles (`status != 'archived'`), ordered by name. Required on create, editable on edit.
2. Accept `cycle_id` GET param to pre-fill when navigating from the cycle tab's "Add Classroom" link.
3. On edit, pre-select the classroom's current `cycle_id`.

**Save handler changes:**

In `handle_actions()`, add `cycle_id` to the `$data` array:
```php
$data = array(
    'classroom_name' => sanitize_text_field($_POST['classroom_name']),
    'school_id'      => absint($_POST['school_id']),
    'cycle_id'       => absint($_POST['cycle_id']),  // NEW
    'age_band'       => sanitize_text_field($_POST['age_band']),
    'status'         => sanitize_text_field($_POST['status']),
);
```

### D. Cycle Context Preservation (Deep Links + Return Navigation)

**File:** `class-hl-admin-cycles.php` — `render_tab_classrooms()`

**Changes:**

1. **"Manage Classrooms" button:** Add `&cycle_id={$cycle_id}` to the URL so the Classrooms list page auto-filters to this cycle's classrooms.

2. **Per-row "View" button:** Add `&cycle_id={$cycle_id}` so the classroom detail page pre-selects the cycle context in the Teaching Assignments dropdown.

3. **Per-row "Edit" button:** Add an Edit button alongside View, linking to the classroom edit form.

4. **"Add Classroom" button:** Add a secondary button next to "Manage Classrooms" that links to the create form with `&cycle_id={$cycle_id}` pre-filled.

**File:** `class-hl-admin-classrooms.php` — return navigation

5. **"Back to Classrooms" link** on the detail page and edit form: propagate `cycle_id` if present in the current request (e.g., `admin.php?page=hl-classrooms&cycle_id=1`), so the admin returns to the filtered list instead of the unfiltered 99-classroom view.

## Scope Exclusions

- **"Add a new user to a classroom"** (enrolling a brand-new person): This requires creating a WP user + enrollment first, which is a separate workflow. The Enrollments admin page already handles this. Out of scope for this ticket.
- **Inline classroom management on the cycle tab:** The cycle tab remains a summary view. Full CRUD stays on the standalone Classrooms page. The deep links make the navigation seamless enough.
- **Schema changes:** None needed. The `cycle_id` column already exists on `hl_classroom`.

## Testing

After implementation, verify on the test server:

1. **Cycle tab deep links:** Navigate to Cycle admin > LSF_2025-2027_CONTROL > Classrooms tab. Confirm "Manage Classrooms" opens the list pre-filtered to control group classrooms. Confirm "View" links pre-select the cycle context. Confirm "Edit" and "Add Classroom" buttons work.
2. **Cycle filter on list page:** Confirm cycle dropdown appears, auto-selects inbound `cycle_id`, and filters correctly. Confirm Cycle column is visible. Confirm archived cycles are excluded from the dropdown.
3. **Cycle on create form:** Create a new classroom. Confirm Cycle dropdown is present, required, pre-fills from inbound `cycle_id`, and saves correctly.
4. **Cycle on edit form:** Edit an existing classroom. Confirm Cycle dropdown shows current value and saves changes.
5. **Return navigation:** From the cycle tab, click View on a classroom, then click "Back to Classrooms." Confirm it returns to the filtered list (with `cycle_id` preserved), not the unfiltered 99-classroom view.
6. **Reassignment optgroup:** View a fully-assigned school's classroom (e.g., BNFDC28 at Bear Necessities). Select the control group cycle. Confirm the teacher dropdown shows a "Reassign from another classroom" optgroup with teachers from other classrooms at the same school, showing their current classroom name.
7. **Reassignment flow:** Reassign a teacher. Confirm the confirmation dialog appears. After confirming, verify: old assignment removed, new assignment created, audit log entries written, success notice shows "Teacher reassigned from [X] to [Y]."
8. **Empty state hint:** On a school with no enrollments, confirm the "No teachers available. Create an enrollment first" hint appears with a working link.
9. **Transaction safety:** If feasible, test the rollback path (e.g., by temporarily breaking the create query) to confirm the old assignment is preserved on failure.
10. **Regressions:** Verify non-control-group classrooms still work identically — existing CRUD, teaching assignments, and children roster are unaffected.
