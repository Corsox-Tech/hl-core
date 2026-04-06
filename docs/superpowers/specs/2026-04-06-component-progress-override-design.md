# Admin Component Progress Override — Design Spec

**Date:** 2026-04-06
**Status:** Approved

## Problem

Admins need the ability to reset a user's component progress back to "Not Started" or manually mark a component as "Complete" on behalf of a user. Currently, component states are only set by organic triggers (LearnDash course completion, coaching session attendance, etc.) with no admin override UI.

## Requirements

1. **Admin-only** — gated behind `manage_hl_core` capability.
2. **Reset to Not Started** — clears completion status, percent, and completed_at timestamp.
3. **Mark as Complete** — sets status to complete, percent to 100, records completed_at.
4. **LearnDash sync** — for `learndash_course` components, reset also resets LD course progress; mark complete also marks the LD course complete for the user.
5. **Rollup recomputation** — both actions trigger `hl_core_recompute_rollups` so pathway/cycle percentages stay accurate.
6. **Audit trail** — log who performed the action (admin user ID), which component, which enrollment. No free-text reason field required.

## Design

### Location

Below the existing enrollment edit form in `HL_Admin_Enrollments::render_form()`, visible only in **edit mode** (`$is_edit === true`). A new section titled "Component Progress" appears after the Save button.

### UI — Component Progress Table

A standard WP admin table displaying the user's components from their assigned pathway:

| Column | Content |
|--------|---------|
| Component | Title of the component |
| Type | Human-readable component type (e.g., "LearnDash Course", "Coaching Session") |
| Status | Badge: Not Started / In Progress / Complete |
| Progress | Percentage (0–100%) |
| Actions | Reset and/or Mark Complete buttons |

**Button visibility:**
- **Reset** — shown when status is `in_progress` or `complete`.
- **Mark Complete** — shown when status is `not_started` or `in_progress`.

**Confirmation:** JavaScript `confirm()` dialog before each action:
- Reset: "Reset [component name] to Not Started for this user?"
- Complete: "Mark [component name] as Complete for this user?"

**Edge case:** If no pathway is assigned to the enrollment, display a notice: "No pathway assigned — assign a pathway to manage component progress."

### Backend — POST Handlers

Two new actions handled in `HL_Admin_Enrollments::handle_actions()`, keyed by `$_POST['hl_component_action']`:

#### `reset_component`

1. Validate nonce (`hl_component_progress_nonce`) and `manage_hl_core` capability.
2. Upsert `hl_component_state`: `completion_status = 'not_started'`, `completion_percent = 0`, `completed_at = NULL`, `last_computed_at = now()`.
3. If component type is `learndash_course`: resolve the LD course ID (via catalog or external_ref) and delete the user's LD course progress using LearnDash API.
4. Fire `do_action('hl_core_recompute_rollups', $enrollment_id)`.
5. Audit log: `component_progress.reset` with `{ admin_user_id, enrollment_id, component_id, component_title }`.
6. Redirect back to enrollment edit with success notice.

#### `complete_component`

1. Validate nonce (`hl_component_progress_nonce`) and `manage_hl_core` capability.
2. Upsert `hl_component_state`: `completion_status = 'complete'`, `completion_percent = 100`, `completed_at = current_datetime()`, `last_computed_at = now()`.
3. If component type is `learndash_course`: resolve the LD course ID and mark the LD course as complete for the user using LearnDash API.
4. Fire `do_action('hl_core_recompute_rollups', $enrollment_id)`.
5. Audit log: `component_progress.manual_complete` with `{ admin_user_id, enrollment_id, component_id, component_title }`.
6. Redirect back to enrollment edit with success notice.

### LearnDash Sync Details

**Reset:** Use `learndash_user_course_progress_delete($user_id, $course_id)` if available, or direct usermeta cleanup (`_sfwd-course_progress`, `course_completed_*`).

**Mark Complete:** Use `learndash_process_mark_complete($user_id, $course_id)` or the `learndash_update_user_activity()` pattern already used in the codebase.

Both paths resolve the LD course ID through `HL_Course_Catalog::resolve_ld_course_id()` for catalog-linked components, falling back to `external_ref.course_id` for legacy components.

### Security

- `manage_hl_core` capability check on render and on action handling.
- Nonce verification (`hl_component_progress_nonce`) on all POST actions.
- Enrollment existence validation.
- Component must belong to the enrollment's pathway.

### Data Model

No new tables or columns. Uses existing:
- `hl_component_state` — status/percent updates
- `hl_completion_rollup` — recomputed via existing `compute_rollups()`
- `HL_Audit_Service::log()` — action audit trail

### Files Modified

1. `includes/admin/class-hl-admin-enrollments.php` — render component progress table in `render_form()`, handle POST actions in `handle_actions()`.
2. `includes/integrations/class-hl-learndash-integration.php` — add `reset_course_progress($user_id, $course_id)` and `mark_course_complete($user_id, $course_id)` methods for admin sync.
