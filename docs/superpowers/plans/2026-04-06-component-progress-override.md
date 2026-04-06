# Admin Component Progress Override — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow admins to reset component progress to "Not Started" or mark as "Complete" from the enrollment edit form in WP Admin, with full LearnDash sync.

**Architecture:** Two files modified — add LD sync methods to `HL_LearnDash_Integration`, then add POST handler + UI table to `HL_Admin_Enrollments`. Component progress table renders as a separate `<form>` below the enrollment form. One supporting change in `HL_Admin_Cycles` for message notices.

**Tech Stack:** PHP 7.4+, WordPress, LearnDash, existing HL Core services (Rules Engine, Reporting, Audit, Course Catalog).

**Spec:** `docs/superpowers/specs/2026-04-06-component-progress-override-design.md`

---

## Task 1: Add LD Sync Methods to `HL_LearnDash_Integration`

**Files:**
- Modify: `includes/integrations/class-hl-learndash-integration.php:238` (insert before closing `}` on line 239)

- [ ] **Step 1.1: Add `reset_course_progress()` method**

Insert after the `batch_get_progress()` method (after line 238, before the closing `}` on line 239):

```php
    /**
     * Reset a user's LearnDash course progress.
     *
     * Clears usermeta/quiz history via LD API, then also resets the
     * wp_learndash_user_activity row which learndash_delete_course_progress
     * does NOT clear.
     *
     * @param int $user_id
     * @param int $course_id LD course post ID.
     * @return bool True on success, false if LD functions unavailable.
     */
    public function reset_course_progress($user_id, $course_id) {
        if (!function_exists('learndash_delete_course_progress')) {
            error_log(sprintf('[HL Core] reset_course_progress: learndash_delete_course_progress not available (user=%d, course=%d)', $user_id, $course_id));
            return false;
        }

        // Argument order is ($course_id, $user_id) — confirmed in sfwd-lms source.
        learndash_delete_course_progress($course_id, $user_id);

        // Also reset wp_learndash_user_activity row (not cleared by the above).
        if (function_exists('learndash_get_user_activity') && function_exists('learndash_update_user_activity')) {
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
        }

        return true;
    }
```

- [ ] **Step 1.2: Add `mark_course_complete()` method**

Insert immediately after `reset_course_progress()`:

```php
    /**
     * Mark a LearnDash course as complete for a user.
     *
     * Uses learndash_update_user_activity() directly — does NOT use
     * learndash_process_mark_complete() (takes step ID, not course ID)
     * and does NOT iterate steps (would re-fire learndash_course_completed
     * hook causing duplicate downstream actions).
     *
     * @param int $user_id
     * @param int $course_id LD course post ID.
     * @return bool True on success, false if LD functions unavailable.
     */
    public function mark_course_complete($user_id, $course_id) {
        if (!function_exists('learndash_update_user_activity')) {
            error_log(sprintf('[HL Core] mark_course_complete: learndash_update_user_activity not available (user=%d, course=%d)', $user_id, $course_id));
            return false;
        }

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

        return true;
    }
```

- [ ] **Step 1.3: Commit**

```
git add includes/integrations/class-hl-learndash-integration.php
git commit -m "feat(ld): add reset_course_progress and mark_course_complete methods"
```

---

## Task 2: Add Component Action Handler to Enrollments Admin

**Files:**
- Modify: `includes/admin/class-hl-admin-enrollments.php:125-131` (wire handler)
- Modify: `includes/admin/class-hl-admin-enrollments.php:324` (insert new methods after `handle_delete`)

**Reuse:**
- `HL_Audit_Service::log()` — existing audit pattern
- `HL_Course_Catalog::resolve_ld_course_id($component, $enrollment)` — `includes/domain/class-hl-course-catalog.php:102`
- `HL_Course_Catalog::get_language_course_ids()` — `includes/domain/class-hl-course-catalog.php:30`
- `HL_Course_Catalog_Repository::get_by_id()` — existing repo
- `HL_LearnDash_Integration::instance()` — singleton
- `HL_Component` constructor — `includes/domain/class-hl-component.php:16`

- [ ] **Step 2.1: Wire `handle_component_actions()` into `handle_early_actions()`**

In `includes/admin/class-hl-admin-enrollments.php`, replace lines 125-131:

```php
    public function handle_early_actions() {
        $this->handle_actions();
```

With:

```php
    public function handle_early_actions() {
        $this->handle_actions();
        $this->handle_component_actions();
```

(The rest of `handle_early_actions()` — the delete check on lines 128-130 — stays unchanged.)

- [ ] **Step 2.2: Add `handle_component_actions()` method**

Insert after `handle_delete()` (after line 324, before the `render_list()` comment block at line 326):

```php
    /**
     * Handle component progress override actions (reset / mark complete).
     *
     * Uses its own nonce (hl_component_progress_nonce), independent of the
     * enrollment form's hl_enrollment_nonce. Called from handle_early_actions().
     */
    private function handle_component_actions() {
        if (!isset($_POST['hl_component_action'])) {
            return;
        }

        if (!isset($_POST['hl_component_progress_nonce']) || !wp_verify_nonce($_POST['hl_component_progress_nonce'], 'hl_component_progress')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;

        $action        = sanitize_text_field($_POST['hl_component_action']);
        $enrollment_id = absint($_POST['hl_component_enrollment_id'] ?? 0);
        $component_id  = absint($_POST['hl_component_id'] ?? 0);

        if (!$enrollment_id || !$component_id) {
            return;
        }

        // Validate enrollment exists.
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $enrollment_id
        ));
        if (!$enrollment) {
            return;
        }

        // Validate component belongs to enrollment's current pathway (via pathway_assignment join).
        $valid_component = $wpdb->get_row($wpdb->prepare(
            "SELECT c.component_id, c.title, c.component_type, c.catalog_id, c.external_ref
             FROM {$wpdb->prefix}hl_component c
             JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.pathway_id = c.pathway_id
             WHERE c.component_id = %d AND pa.enrollment_id = %d",
            $component_id, $enrollment_id
        ));
        if (!$valid_component) {
            return;
        }

        $now           = current_time('mysql');
        $cycle_context = isset($_POST['_hl_cycle_context']) ? absint($_POST['_hl_cycle_context']) : 0;
        $ld_warning    = false;

        if ($action === 'reset_component') {
            // 1. Check for and delete exempt override.
            $exempt_override = $wpdb->get_row($wpdb->prepare(
                "SELECT override_id FROM {$wpdb->prefix}hl_component_override
                 WHERE enrollment_id = %d AND component_id = %d AND override_type = 'exempt'",
                $enrollment_id, $component_id
            ));
            if ($exempt_override) {
                $wpdb->delete($wpdb->prefix . 'hl_component_override', array('override_id' => $exempt_override->override_id));
                HL_Audit_Service::log('component_override.removed', array(
                    'entity_type' => 'component_override',
                    'entity_id'   => $exempt_override->override_id,
                    'after_data'  => array(
                        'admin_user_id' => get_current_user_id(),
                        'enrollment_id' => $enrollment_id,
                        'component_id'  => $component_id,
                        'override_type' => 'exempt',
                        'reason'        => 'Removed during component progress reset',
                    ),
                ));
            }

            // 2. Upsert component_state to not_started.
            $existing_state = $wpdb->get_row($wpdb->prepare(
                "SELECT state_id FROM {$wpdb->prefix}hl_component_state
                 WHERE enrollment_id = %d AND component_id = %d",
                $enrollment_id, $component_id
            ));

            if ($existing_state) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}hl_component_state
                     SET completion_status = 'not_started', completion_percent = 0,
                         completed_at = NULL, last_computed_at = %s
                     WHERE state_id = %d",
                    $now, $existing_state->state_id
                ));
            } else {
                $wpdb->insert($wpdb->prefix . 'hl_component_state', array(
                    'enrollment_id'      => $enrollment_id,
                    'component_id'       => $component_id,
                    'completion_status'  => 'not_started',
                    'completion_percent' => 0,
                    'last_computed_at'   => $now,
                ));
            }

            // 3. LD sync: reset ALL language variants.
            if ($valid_component->component_type === 'learndash_course' && !empty($valid_component->catalog_id)) {
                $repo = new HL_Course_Catalog_Repository();
                $catalog_entry = $repo->get_by_id($valid_component->catalog_id);
                if ($catalog_entry) {
                    $ld = HL_LearnDash_Integration::instance();
                    $lang_ids = $catalog_entry->get_language_course_ids();
                    foreach ($lang_ids as $lang => $ld_course_id) {
                        if (!$ld->reset_course_progress($enrollment->user_id, $ld_course_id)) {
                            $ld_warning = true;
                        }
                    }
                }
            } elseif ($valid_component->component_type === 'learndash_course' && empty($valid_component->catalog_id)) {
                // Fallback: legacy component with external_ref only.
                $comp_obj = new HL_Component(array(
                    'catalog_id'     => null,
                    'component_type' => 'learndash_course',
                    'external_ref'   => $valid_component->external_ref,
                ));
                $ld_course_id = HL_Course_Catalog::resolve_ld_course_id($comp_obj, $enrollment);
                if ($ld_course_id) {
                    $ld = HL_LearnDash_Integration::instance();
                    if (!$ld->reset_course_progress($enrollment->user_id, $ld_course_id)) {
                        $ld_warning = true;
                    }
                }
            }

            // 4. Recompute rollups.
            do_action('hl_core_recompute_rollups', $enrollment_id);

            // 5. Audit log.
            HL_Audit_Service::log('component_progress.reset', array(
                'entity_type' => 'component',
                'entity_id'   => $component_id,
                'after_data'  => array(
                    'admin_user_id'   => get_current_user_id(),
                    'enrollment_id'   => $enrollment_id,
                    'component_id'    => $component_id,
                    'component_title' => $valid_component->title,
                ),
            ));

            // 6. Redirect.
            $msg = $ld_warning ? 'component_reset_ld_warning' : 'component_reset';
            $this->redirect_to_enrollment_edit($enrollment_id, $cycle_context, $msg);

        } elseif ($action === 'complete_component') {
            // 1. Upsert component_state to complete.
            $existing_state = $wpdb->get_row($wpdb->prepare(
                "SELECT state_id FROM {$wpdb->prefix}hl_component_state
                 WHERE enrollment_id = %d AND component_id = %d",
                $enrollment_id, $component_id
            ));

            $state_data = array(
                'completion_status'  => 'complete',
                'completion_percent' => 100,
                'completed_at'       => $now,
                'last_computed_at'   => $now,
            );

            if ($existing_state) {
                $wpdb->update(
                    $wpdb->prefix . 'hl_component_state',
                    $state_data,
                    array('state_id' => $existing_state->state_id)
                );
            } else {
                $state_data['enrollment_id'] = $enrollment_id;
                $state_data['component_id']  = $component_id;
                $wpdb->insert($wpdb->prefix . 'hl_component_state', $state_data);
            }

            // 2. LD sync: mark preferred language variant complete.
            if ($valid_component->component_type === 'learndash_course') {
                $comp_obj = new HL_Component(array(
                    'component_id'   => $valid_component->component_id,
                    'catalog_id'     => $valid_component->catalog_id,
                    'component_type' => 'learndash_course',
                    'external_ref'   => $valid_component->external_ref,
                ));
                $ld_course_id = HL_Course_Catalog::resolve_ld_course_id($comp_obj, $enrollment);
                if ($ld_course_id) {
                    $ld = HL_LearnDash_Integration::instance();
                    if (!$ld->mark_course_complete($enrollment->user_id, $ld_course_id)) {
                        $ld_warning = true;
                    }
                }
            }

            // 3. Recompute rollups.
            do_action('hl_core_recompute_rollups', $enrollment_id);

            // 4. Audit log.
            HL_Audit_Service::log('component_progress.manual_complete', array(
                'entity_type' => 'component',
                'entity_id'   => $component_id,
                'after_data'  => array(
                    'admin_user_id'   => get_current_user_id(),
                    'enrollment_id'   => $enrollment_id,
                    'component_id'    => $component_id,
                    'component_title' => $valid_component->title,
                ),
            ));

            // 5. Redirect.
            $msg = $ld_warning ? 'component_complete_ld_warning' : 'component_complete';
            $this->redirect_to_enrollment_edit($enrollment_id, $cycle_context, $msg);
        }
    }

    /**
     * Redirect back to enrollment edit page with a message parameter.
     *
     * @param int    $enrollment_id
     * @param int    $cycle_context  Cycle ID if embedded in Cycle Editor, 0 otherwise.
     * @param string $message        Message key for admin notice.
     */
    private function redirect_to_enrollment_edit($enrollment_id, $cycle_context, $message) {
        if ($cycle_context) {
            $redirect = admin_url('admin.php?page=hl-cycles&action=edit&id=' . $cycle_context
                . '&tab=enrollments&sub=edit&enrollment_id=' . $enrollment_id
                . '&message=' . $message);
        } else {
            $redirect = admin_url('admin.php?page=hl-enrollments&action=edit&id=' . $enrollment_id
                . '&message=' . $message);
        }
        wp_redirect($redirect);
        exit;
    }
```

- [ ] **Step 2.3: Commit**

```
git add includes/admin/class-hl-admin-enrollments.php
git commit -m "feat(admin): add component progress reset/complete POST handler"
```

---

## Task 3: Render Component Progress Table UI

**Files:**
- Modify: `includes/admin/class-hl-admin-enrollments.php:607` (add notices)
- Modify: `includes/admin/class-hl-admin-enrollments.php:827` (add table after `</form>`)
- Modify: `includes/admin/class-hl-admin-cycles.php:391-419` (add message keys)

**Reuse:**
- `HL_Rules_Engine_Service::check_eligibility()` — `includes/services/class-hl-rules-engine-service.php:13`
- `HL_Component` constructor — `includes/domain/class-hl-component.php:16`

- [ ] **Step 3.1: Add component progress notices in `render_form()`**

In `includes/admin/class-hl-admin-enrollments.php`, insert after line 607 (`$is_edit = ($enrollment !== null);`), before line 608 (`$title = ...`):

```php
        // Component progress action notices.
        if ($is_edit && isset($_GET['message'])) {
            $cp_msg = sanitize_text_field($_GET['message']);
            $cp_notices = array(
                'component_reset'              => array('success', __('Component progress reset to Not Started.', 'hl-core')),
                'component_complete'           => array('success', __('Component marked as Complete.', 'hl-core')),
                'component_reset_ld_warning'   => array('warning', __('Component progress reset, but LearnDash course progress could not be synced. The user\'s LearnDash course page may show stale data.', 'hl-core')),
                'component_complete_ld_warning' => array('warning', __('Component marked as Complete, but LearnDash course progress could not be synced.', 'hl-core')),
            );
            if (isset($cp_notices[$cp_msg])) {
                $type = $cp_notices[$cp_msg][0] === 'warning' ? 'notice-warning' : 'notice-success';
                echo '<div class="notice ' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($cp_notices[$cp_msg][1]) . '</p></div>';
            }
        }
```

- [ ] **Step 3.2: Add component progress table after enrollment `</form>`**

In `includes/admin/class-hl-admin-enrollments.php`, insert between line 827 (`echo '</form>';`) and line 829 (`// User search autocomplete JS.`):

```php
        // =====================================================================
        // Component Progress Table (edit mode, admin only)
        // =====================================================================
        if ($is_edit && current_user_can('manage_hl_core')) {
            echo '<hr style="margin:30px 0 20px;" />';
            echo '<h2>' . esc_html__('Component Progress', 'hl-core') . '</h2>';

            if (!$current_pathway_id) {
                echo '<p class="description">' . esc_html__('No pathway assigned — assign a pathway to manage component progress.', 'hl-core') . '</p>';
            } else {
                // Load active components for the assigned pathway.
                $cp_components = $wpdb->get_results($wpdb->prepare(
                    "SELECT component_id, title, component_type, weight, catalog_id,
                            requires_classroom, eligible_roles, ordering_hint, external_ref
                     FROM {$wpdb->prefix}hl_component
                     WHERE pathway_id = %d AND status = 'active'
                     ORDER BY ordering_hint ASC, component_id ASC",
                    $current_pathway_id
                ));

                if (empty($cp_components)) {
                    echo '<p class="description">' . esc_html__('No active components in this pathway.', 'hl-core') . '</p>';
                } else {
                    // Load component states for this enrollment.
                    $cp_ids = wp_list_pluck($cp_components, 'component_id');
                    $cp_placeholders = implode(',', array_fill(0, count($cp_ids), '%d'));
                    $cp_states = $wpdb->get_results($wpdb->prepare(
                        "SELECT component_id, completion_status, completion_percent, completed_at
                         FROM {$wpdb->prefix}hl_component_state
                         WHERE enrollment_id = %d AND component_id IN ($cp_placeholders)",
                        array_merge(array($enrollment->enrollment_id), $cp_ids)
                    ));
                    $cp_state_map = array();
                    foreach ($cp_states as $s) {
                        $cp_state_map[$s->component_id] = $s;
                    }

                    // Load exempt overrides.
                    $cp_overrides = $wpdb->get_results($wpdb->prepare(
                        "SELECT component_id FROM {$wpdb->prefix}hl_component_override
                         WHERE enrollment_id = %d AND override_type = 'exempt'
                         AND component_id IN ($cp_placeholders)",
                        array_merge(array($enrollment->enrollment_id), $cp_ids)
                    ));
                    $cp_exempt_map = array();
                    foreach ($cp_overrides as $ov) {
                        $cp_exempt_map[$ov->component_id] = true;
                    }

                    // Type labels.
                    $cp_type_labels = array(
                        'learndash_course'            => __('LearnDash Course', 'hl-core'),
                        'teacher_self_assessment'     => __('Teacher Self-Assessment', 'hl-core'),
                        'child_assessment'            => __('Child Assessment', 'hl-core'),
                        'coaching_session_attendance'  => __('Coaching Attendance', 'hl-core'),
                        'classroom_visit'             => __('Classroom Visit', 'hl-core'),
                        'reflective_practice_session' => __('Reflective Practice', 'hl-core'),
                        'self_reflection'             => __('Self-Reflection', 'hl-core'),
                    );

                    $non_ld_types = array('coaching_session_attendance', 'classroom_visit', 'reflective_practice_session', 'self_reflection');
                    $has_non_ld = false;
                    $rules_engine = new HL_Rules_Engine_Service();

                    echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-enrollments')) . '">';
                    wp_nonce_field('hl_component_progress', 'hl_component_progress_nonce');
                    echo '<input type="hidden" name="hl_component_enrollment_id" value="' . esc_attr($enrollment->enrollment_id) . '" />';
                    echo '<input type="hidden" name="hl_component_id" value="" />';
                    if ($in_cycle) {
                        echo '<input type="hidden" name="_hl_cycle_context" value="' . esc_attr($context['cycle_id']) . '" />';
                    }

                    echo '<table class="widefat striped" style="max-width:900px;">';
                    echo '<thead><tr>';
                    echo '<th>' . esc_html__('Component', 'hl-core') . '</th>';
                    echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
                    echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
                    echo '<th>' . esc_html__('Progress', 'hl-core') . '</th>';
                    echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
                    echo '</tr></thead><tbody>';

                    foreach ($cp_components as $comp) {
                        $cid = $comp->component_id;

                        // Check eligibility.
                        $comp_obj = new HL_Component(array(
                            'requires_classroom' => $comp->requires_classroom,
                            'eligible_roles'     => $comp->eligible_roles,
                        ));
                        $eligible = $rules_engine->check_eligibility($enrollment->enrollment_id, $comp_obj);

                        // State.
                        $status  = 'not_started';
                        $percent = 0;
                        if (isset($cp_state_map[$cid])) {
                            $status  = $cp_state_map[$cid]->completion_status;
                            $percent = intval($cp_state_map[$cid]->completion_percent);
                        }

                        $is_exempt = isset($cp_exempt_map[$cid]);

                        // Type label.
                        $type_label = isset($cp_type_labels[$comp->component_type])
                            ? $cp_type_labels[$comp->component_type]
                            : ucwords(str_replace('_', ' ', $comp->component_type));

                        if (in_array($comp->component_type, $non_ld_types, true)) {
                            $has_non_ld = true;
                        }

                        // Status badge.
                        if (!$eligible) {
                            $badge = '<span style="background:#f0f0f1;color:#50575e;padding:2px 8px;border-radius:3px;font-size:12px;">'
                                   . esc_html__('Not Applicable', 'hl-core') . '</span>';
                        } elseif ($status === 'complete') {
                            $badge = '<span style="background:#d4edda;color:#155724;padding:2px 8px;border-radius:3px;font-size:12px;">'
                                   . esc_html__('Complete', 'hl-core') . '</span>';
                        } elseif ($status === 'in_progress') {
                            $badge = '<span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:3px;font-size:12px;">'
                                   . esc_html__('In Progress', 'hl-core') . '</span>';
                        } else {
                            $badge = '<span style="background:#f0f0f1;color:#50575e;padding:2px 8px;border-radius:3px;font-size:12px;">'
                                   . esc_html__('Not Started', 'hl-core') . '</span>';
                        }

                        if ($is_exempt) {
                            $badge .= ' <em style="color:#996800;font-size:11px;">'
                                    . esc_html__('(Exempt override active)', 'hl-core') . '</em>';
                        }

                        echo '<tr>';
                        echo '<td><strong>' . esc_html($comp->title) . '</strong></td>';
                        echo '<td>' . esc_html($type_label) . '</td>';
                        echo '<td>' . $badge . '</td>';
                        echo '<td>' . esc_html($percent) . '%</td>';
                        echo '<td>';

                        if (!$eligible) {
                            echo '<button type="button" class="button button-small" disabled>' . esc_html__('Reset', 'hl-core') . '</button> ';
                            echo '<button type="button" class="button button-small" disabled>' . esc_html__('Mark Complete', 'hl-core') . '</button>';
                        } else {
                            $esc_title = esc_js($comp->title);

                            if ($status === 'in_progress' || $status === 'complete') {
                                echo '<button type="submit" name="hl_component_action" value="reset_component"'
                                   . ' class="button button-small"'
                                   . ' onclick="this.form.hl_component_id.value=\'' . esc_attr($cid) . '\';'
                                   . 'return confirm(\'' . sprintf(esc_js(__('Reset "%s" to Not Started for this user?', 'hl-core')), $esc_title) . '\');">'
                                   . esc_html__('Reset', 'hl-core') . '</button> ';
                            }

                            if ($status === 'not_started' || $status === 'in_progress') {
                                echo '<button type="submit" name="hl_component_action" value="complete_component"'
                                   . ' class="button button-small button-primary"'
                                   . ' onclick="this.form.hl_component_id.value=\'' . esc_attr($cid) . '\';'
                                   . 'return confirm(\'' . sprintf(esc_js(__('Mark "%s" as Complete for this user?', 'hl-core')), $esc_title) . '\');">'
                                   . esc_html__('Mark Complete', 'hl-core') . '</button>';
                            }
                        }

                        echo '</td>';
                        echo '</tr>';
                    }

                    echo '</tbody></table>';
                    echo '</form>';

                    if ($has_non_ld) {
                        echo '<p class="description" style="margin-top:8px;">'
                           . esc_html__('Note: activity-based components may recalculate when new activity occurs.', 'hl-core')
                           . '</p>';
                    }
                }
            }
        }
```

- [ ] **Step 3.3: Add message keys to Cycle Editor**

In `includes/admin/class-hl-admin-cycles.php`, add four entries to the `$messages` array inside `render_tabbed_editor()` (after line 416, before the closing `);` on line 417):

```php
                'component_reset'              => __('Component progress reset to Not Started.', 'hl-core'),
                'component_complete'           => __('Component marked as Complete.', 'hl-core'),
                'component_reset_ld_warning'   => __('Component progress reset, but LearnDash course progress could not be synced.', 'hl-core'),
                'component_complete_ld_warning' => __('Component marked as Complete, but LearnDash course progress could not be synced.', 'hl-core'),
```

And update the notice type logic on line 419. Replace:

```php
                $notice_type = in_array($msg, array('clone_error', 'cycle_delete_error'), true) ? 'notice-error' : 'notice-success';
```

With:

```php
                $ld_warn_msgs = array('component_reset_ld_warning', 'component_complete_ld_warning');
                $error_msgs   = array('clone_error', 'cycle_delete_error');
                if (in_array($msg, $error_msgs, true)) {
                    $notice_type = 'notice-error';
                } elseif (in_array($msg, $ld_warn_msgs, true)) {
                    $notice_type = 'notice-warning';
                } else {
                    $notice_type = 'notice-success';
                }
```

- [ ] **Step 3.4: Commit**

```
git add includes/admin/class-hl-admin-enrollments.php includes/admin/class-hl-admin-cycles.php
git commit -m "feat(admin): render component progress table with reset/complete buttons"
```

---

## Task 4: Update STATUS.md + README.md, Final Commit

**Files:**
- Modify: `STATUS.md` — add build queue entry
- Modify: `README.md` — update "What's Implemented"

- [ ] **Step 4.1: Add build queue entry to STATUS.md**

Add after the "Feature Tracker" section:

```markdown
### Admin Component Progress Override (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-06-component-progress-override-design.md`
- [x] **LD sync methods** — `reset_course_progress()` + `mark_course_complete()` on `HL_LearnDash_Integration`. Correct LD API calls (activity table + usermeta), `function_exists()` guards.
- [x] **POST handler** — `handle_component_actions()` with own nonce/form, pathway validation via `hl_pathway_assignment` join, exempt override cleanup on reset, multi-language LD reset, LD sync failure warning notices.
- [x] **UI table** — Component Progress section below enrollment edit form. Eligibility filtering, status badges, exempt override display, non-LD info note. Works in both standalone and Cycle Editor contexts.
```

- [ ] **Step 4.2: Update README.md**

Add entry to "What's Implemented" section.

- [ ] **Step 4.3: Final commit**

```
git add STATUS.md README.md
git commit -m "docs: update STATUS.md + README.md for component progress override"
```

---

## Verification Checklist

After deployment to test server:

1. **Standalone enrollment edit** — Navigate to Housman LMS > Enrollments > Edit. Confirm Component Progress table appears below the enrollment form with correct components, statuses, badges.
2. **Cycle Editor enrollment edit** — Navigate to Cycles > [cycle] > Enrollments > Edit. Confirm same table appears.
3. **No pathway** — Edit an enrollment with no pathway. Confirm "No pathway assigned" message.
4. **Reset (non-LD)** — Click Reset on a coaching/visit component. Confirm state resets to Not Started 0%.
5. **Mark Complete (non-LD)** — Click Mark Complete on a not_started component. Confirm state becomes Complete 100%.
6. **Reset (LD course)** — Click Reset on a learndash_course component. Verify both `hl_component_state` and `wp_learndash_user_activity` are cleared.
7. **Mark Complete (LD course)** — Click Mark Complete. Verify `hl_component_state` and `wp_learndash_user_activity` both show complete.
8. **Rollup recomputation** — After any action, check `hl_completion_rollup` for updated percentages.
9. **Audit log** — Check `hl_audit_log` for `component_progress.reset` and `component_progress.manual_complete` entries.
10. **Ineligible components** — Confirm they show "Not Applicable" with disabled buttons.
11. **Exempt override** — If one exists, confirm "(Exempt override active)" label and that Reset removes it.
12. **Security** — Non-admin users cannot see the table. Invalid nonce produces error.

### DB Verification Queries

```sql
-- Check component state after action
SELECT * FROM wp_hl_component_state WHERE enrollment_id = [X] AND component_id = [Y];

-- Check rollup was recomputed
SELECT * FROM wp_hl_completion_rollup WHERE enrollment_id = [X];

-- Check audit trail
SELECT * FROM wp_hl_audit_log WHERE action_type LIKE 'component_progress%' ORDER BY created_at DESC LIMIT 5;

-- Check LD activity (for LD course components)
SELECT * FROM wp_learndash_user_activity WHERE user_id = [X] AND course_id = [Y] AND activity_type = 'course';

-- Check exempt override was removed
SELECT * FROM wp_hl_component_override WHERE enrollment_id = [X] AND component_id = [Y];
```
