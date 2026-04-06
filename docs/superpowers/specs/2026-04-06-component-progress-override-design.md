# Admin Component Progress Override — Design Spec

**Date:** 2026-04-06
**Status:** Approved (revised after expert review)

## Problem

Admins need the ability to reset a user's component progress back to "Not Started" or manually mark a component as "Complete" on behalf of a user. Currently, component states are only set by organic triggers (LearnDash course completion, coaching session attendance, etc.) with no admin override UI.

## Requirements

1. **Admin-only** — gated behind `manage_hl_core` capability.
2. **Reset to Not Started** — clears completion status, percent, and completed_at timestamp.
3. **Mark as Complete** — sets status to complete, percent to 100, records completed_at.
4. **LearnDash sync** — for `learndash_course` components, reset also resets LD course progress (all language variants); mark complete also marks the LD course complete for the user.
5. **Rollup recomputation** — both actions trigger `hl_core_recompute_rollups` so pathway/cycle percentages stay accurate.
6. **Audit trail** — log who performed the action (admin user ID), which component, which enrollment. No free-text reason field required.

## Design

### Location

Below the existing enrollment edit form in `HL_Admin_Enrollments::render_form()`, visible only in **edit mode** (`$is_edit === true`). A new section titled "Component Progress" renders **after the `</form>` closing tag** (line 827) of the enrollment form — it must NOT be nested inside the enrollment form. The component progress section uses its own `<form>` element with its own nonce.

### UI — Component Progress Table

A standard WP admin table displaying the user's **eligible** components from their assigned pathway:

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

**Confirmation:** JavaScript `confirm()` dialog before each action. Component titles must be escaped with `esc_js()` to prevent breakage from apostrophes/special characters:
- Reset: "Reset [component name] to Not Started for this user?"
- Complete: "Mark [component name] as Complete for this user?"

**Eligibility handling:** Ineligible components (per `HL_Rules_Engine_Service::check_eligibility()`) are shown with a "Not Applicable" badge and **disabled action buttons** — matching the same eligibility logic used by `compute_rollups()`. This prevents admins from marking a component complete that would be ignored by the rollup.

**Exempt override handling:** If a component has an `exempt` override in `hl_component_override`, show a note "(Exempt override active)" next to its status. The Reset action will remove the exempt override as part of the reset (see Backend section).

**Non-LD component note:** For non-LearnDash component types (coaching, classroom visit, RP session, self-reflection), show a subtle info note on the table: "Note: activity-based components may recalculate when new activity occurs." This sets the expectation that organic triggers can re-derive state from underlying records.

**Edge cases:**
- If no pathway is assigned, display a notice: "No pathway assigned — assign a pathway to manage component progress."
- If the enrollment form is embedded in the Cycle Editor (`$context['cycle_id']`), include `_hl_cycle_context` as a hidden field so redirects return to the Cycle Editor, not the standalone enrollments page.

### Backend — POST Handlers

A new private method `handle_component_actions()` is added to `HL_Admin_Enrollments`, called from `handle_early_actions()` **independently** of `handle_actions()`. This is necessary because `handle_actions()` guards all execution behind `$_POST['hl_enrollment_nonce']`, which will not be set for component action submissions (separate form, separate nonce).

Two actions keyed by `$_POST['hl_component_action']`:

#### `reset_component`

1. Validate nonce (`hl_component_progress_nonce`) and `manage_hl_core` capability.
2. Validate component belongs to enrollment's current pathway (join through `hl_pathway_assignment`, not denormalized column):
   ```sql
   SELECT c.component_id FROM hl_component c
   JOIN hl_pathway_assignment pa ON pa.pathway_id = c.pathway_id
   WHERE c.component_id = %d AND pa.enrollment_id = %d
   ```
3. Check for and delete any `hl_component_override` record with `override_type = 'exempt'` for this enrollment+component. Audit log the override removal if one existed.
4. Upsert `hl_component_state`: `completion_status = 'not_started'`, `completion_percent = 0`, `completed_at = NULL`, `last_computed_at = current_time('mysql')`.
5. If component type is `learndash_course`: call `HL_LearnDash_Integration::reset_course_progress($user_id, $course_id)` for **all language variants** of the catalog entry (EN, ES, PT — whichever are non-null). See LearnDash Sync Details.
6. Fire `do_action('hl_core_recompute_rollups', $enrollment_id)`.
7. Audit log: `component_progress.reset` with `{ admin_user_id, enrollment_id, component_id, component_title }`.
8. Redirect back to enrollment edit with success notice — or warning notice if LD sync failed (see LD Sync Failure Handling).

#### `complete_component`

1. Validate nonce (`hl_component_progress_nonce`) and `manage_hl_core` capability.
2. Validate component belongs to enrollment's current pathway (same join query as reset).
3. Upsert `hl_component_state`: `completion_status = 'complete'`, `completion_percent = 100`, `completed_at = current_time('mysql')`, `last_computed_at = current_time('mysql')`.
4. If component type is `learndash_course`: call `HL_LearnDash_Integration::mark_course_complete($user_id, $course_id)` for the user's **preferred language variant only**. See LearnDash Sync Details.
5. Fire `do_action('hl_core_recompute_rollups', $enrollment_id)`.
6. Audit log: `component_progress.manual_complete` with `{ admin_user_id, enrollment_id, component_id, component_title }`.
7. Redirect back to enrollment edit with success notice — or warning notice if LD sync failed.

### LearnDash Sync Details

Two new methods on `HL_LearnDash_Integration`:

#### `reset_course_progress($user_id, $course_id)`

1. Guard: `if (!function_exists('learndash_delete_course_progress')) { log warning; return false; }`
2. Call `learndash_delete_course_progress($course_id, $user_id)` — note: argument order is `($course_id, $user_id)`, confirmed at `../sfwd-lms/includes/course/ld-course-progress.php` line 2102. This clears usermeta and quiz history.
3. Also reset the `wp_learndash_user_activity` row (which `learndash_delete_course_progress` does NOT clear):
   ```php
   $activity = learndash_get_user_activity(array(
       'user_id'       => $user_id,
       'course_id'     => $course_id,
       'post_id'       => $course_id,
       'activity_type' => 'course',
   ));
   if (!empty($activity)) {
       learndash_update_user_activity(array_merge(
           (array) $activity,
           array(
               'activity_status'    => false,
               'activity_completed' => 0,
               'activity_updated'   => time(),
           )
       ));
   }
   ```
4. Return `true` on success, `false` on failure.

**Multi-language:** The caller (reset handler) iterates all non-null language course IDs from the catalog entry and calls this method for each. This prevents stale completion in alternate-language courses from re-triggering `on_course_completed`.

#### `mark_course_complete($user_id, $course_id)`

1. Guard: `if (!function_exists('learndash_update_user_activity')) { log warning; return false; }`
2. Use `learndash_update_user_activity()` directly to set the course as complete:
   ```php
   learndash_update_user_activity(array(
       'user_id'            => $user_id,
       'course_id'          => $course_id,
       'post_id'            => $course_id,
       'activity_type'      => 'course',
       'activity_status'    => true,
       'activity_completed' => time(),
       'activity_updated'   => time(),
       'activity_started'   => time(),
   ));
   ```
3. **Do NOT use `learndash_process_mark_complete()`** — that function takes a step (lesson/topic/quiz) post ID, not a course ID. Passing a course ID silently fails.
4. **Do NOT iterate steps** — that would fire `learndash_course_completed` hook, re-entering `on_course_completed()` and potentially triggering duplicate downstream actions (emails, badges).
5. Return `true` on success, `false` on failure.

**Single language:** The caller uses `HL_Course_Catalog::resolve_ld_course_id($component, $enrollment)` to get only the user's preferred language variant. The component parameter must be an `HL_Component` domain object (not raw `stdClass`), and the enrollment must be passed for language resolution.

### LD Sync Failure Handling

If either LD sync method returns `false` (function doesn't exist in installed LD version, or API call fails):
- The HL component state change still applies (HL state is authoritative).
- The redirect URL carries a distinct message parameter (e.g., `message=component_reset_ld_warning`).
- The admin sees a **warning notice** (yellow) instead of success (green): "Component progress updated, but LearnDash course progress could not be synced. The user's LearnDash course page may show stale data."
- A warning is logged via `error_log()` for debugging.

### Security

- `manage_hl_core` capability check on render and on action handling.
- Nonce verification (`hl_component_progress_nonce`) on all POST actions — separate from the enrollment form's `hl_enrollment_nonce`.
- Enrollment existence validation.
- Component must belong to the enrollment's current pathway, validated via `hl_pathway_assignment` join (not denormalized `assigned_pathway_id` column, which can have sync lag).
- Component title in JavaScript `confirm()` escaped via `esc_js()`.

### Data Model

No new tables or columns. Uses existing:
- `hl_component_state` — status/percent updates
- `hl_component_override` — exempt overrides checked and removed on reset
- `hl_completion_rollup` — recomputed via existing `compute_rollups()`
- `HL_Audit_Service::log()` — action audit trail

### Files Modified

1. `includes/admin/class-hl-admin-enrollments.php` — render component progress table after `</form>` in `render_form()`, new `handle_component_actions()` method called from `handle_early_actions()`.
2. `includes/integrations/class-hl-learndash-integration.php` — add `reset_course_progress($user_id, $course_id)` and `mark_course_complete($user_id, $course_id)` methods with correct LD API calls.
