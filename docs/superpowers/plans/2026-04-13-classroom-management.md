# Classroom Management for Control Groups — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable classroom editing, teacher reassignment, and classroom creation for control group partnerships by fixing UX gaps in the admin UI.

**Architecture:** Four enhancements to two existing admin files (`class-hl-admin-classrooms.php`, `class-hl-admin-cycles.php`) plus one new service method on `HL_Classroom_Service`. No schema changes — the `cycle_id` column already exists on `hl_classroom`. All changes are server-rendered PHP admin UI.

**Tech Stack:** PHP 7.4+, WordPress admin APIs (nonce, capabilities, `$wpdb`), MySQL transactions.

**Spec:** `docs/superpowers/specs/2026-04-13-classroom-management-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `includes/admin/class-hl-admin-cycles.php` | Modify (lines 1624-1658) | Cycle tab deep links with `cycle_id` params |
| `includes/admin/class-hl-admin-classrooms.php` | Modify (multiple methods) | Cycle filter, cycle form field, reassignment UI, back-link propagation |
| `includes/services/class-hl-classroom-service.php` | Modify (add method after line 188) | New `reassign_teaching_assignment()` with transaction |

---

### Task 1: Cycle Tab Deep Links

Add `cycle_id` to all outbound URLs from the cycle Classrooms tab, and add Edit + Add Classroom buttons.

**Files:**
- Modify: `includes/admin/class-hl-admin-cycles.php:1624-1658`

- [ ] **Step 1: Update "Manage Classrooms" button to pass cycle_id**

In `render_tab_classrooms()`, change the button URL (line 1625):

```php
// BEFORE:
echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms')) . '" class="button button-primary">' . esc_html__('Manage Classrooms', 'hl-core') . '</a>';

// AFTER:
echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms&cycle_id=' . $cycle_id)) . '" class="button button-primary">' . esc_html__('Manage Classrooms', 'hl-core') . '</a>';
echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-classrooms&action=new&cycle_id=' . $cycle_id)) . '" class="button">' . esc_html__('Add Classroom', 'hl-core') . '</a>';
```

- [ ] **Step 2: Update per-row View button and add Edit button**

In the `foreach ($classrooms as $c)` loop, change the View link (line 1653) and add an Edit link:

```php
// BEFORE:
echo '<td><a href="' . esc_url(admin_url('admin.php?page=hl-classrooms&action=view&id=' . $c->classroom_id)) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a></td>';

// AFTER:
echo '<td>';
echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms&action=view&id=' . $c->classroom_id . '&cycle_id=' . $cycle_id)) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a> ';
echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms&action=edit&id=' . $c->classroom_id . '&cycle_id=' . $cycle_id)) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a>';
echo '</td>';
```

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-cycles.php
git commit -m "feat(classrooms): pass cycle_id from cycle tab deep links, add Edit + Add buttons"
```

---

### Task 2: Cycle-Aware Classrooms List Page

Add a Cycle filter dropdown, Cycle column, and auto-select from inbound `cycle_id`.

**Files:**
- Modify: `includes/admin/class-hl-admin-classrooms.php` — `render_list()` (lines 169-265)

- [ ] **Step 1: Add cycle_id filter to the query**

At line 172, add `$filter_cycle` alongside `$filter_school`, and extend the WHERE clause:

```php
private function render_list() {
    global $wpdb;

    $filter_school = isset($_GET['school_id']) ? absint($_GET['school_id']) : 0;
    $filter_cycle  = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : 0;

    $where_parts = array();
    $where_args  = array();

    if ($filter_school) {
        $where_parts[] = 'c.school_id = %d';
        $where_args[]  = $filter_school;
    }
    if ($filter_cycle) {
        $where_parts[] = 'c.cycle_id = %d';
        $where_args[]  = $filter_cycle;
    }

    $where = '';
    if (!empty($where_parts)) {
        $where = 'WHERE ' . implode(' AND ', $where_parts);
    }

    $query = "SELECT c.*, o.name AS school_name, cy.cycle_name
              FROM {$wpdb->prefix}hl_classroom c
              LEFT JOIN {$wpdb->prefix}hl_orgunit o ON c.school_id = o.orgunit_id
              LEFT JOIN {$wpdb->prefix}hl_cycle cy ON c.cycle_id = cy.cycle_id
              {$where}
              ORDER BY c.classroom_name ASC";

    if (!empty($where_args)) {
        $classrooms = $wpdb->get_results($wpdb->prepare($query, $where_args));
    } else {
        $classrooms = $wpdb->get_results($query);
    }

    $schools = $wpdb->get_results(
        "SELECT orgunit_id, name FROM {$wpdb->prefix}hl_orgunit WHERE orgunit_type = 'school' ORDER BY name ASC"
    );

    $cycles = $wpdb->get_results(
        "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle WHERE status != 'archived' ORDER BY cycle_name ASC"
    );
```

This replaces lines 169-189 of the current `render_list()`.

- [ ] **Step 2: Add Cycle dropdown to the filter form**

Replace the existing filter form (lines 207-220) with one that has both School and Cycle dropdowns:

```php
    // School + Cycle filter
    echo '<form method="get" style="margin-bottom:15px;">';
    echo '<input type="hidden" name="page" value="hl-classrooms" />';

    echo '<label><strong>' . esc_html__('School:', 'hl-core') . '</strong> </label>';
    echo '<select name="school_id">';
    echo '<option value="">' . esc_html__('All', 'hl-core') . '</option>';
    if ($schools) {
        foreach ($schools as $school) {
            echo '<option value="' . esc_attr($school->orgunit_id) . '"' . selected($filter_school, $school->orgunit_id, false) . '>' . esc_html($school->name) . '</option>';
        }
    }
    echo '</select> ';

    echo '<label style="margin-left:10px;"><strong>' . esc_html__('Cycle:', 'hl-core') . '</strong> </label>';
    echo '<select name="cycle_id">';
    echo '<option value="">' . esc_html__('All', 'hl-core') . '</option>';
    if ($cycles) {
        foreach ($cycles as $cycle) {
            echo '<option value="' . esc_attr($cycle->cycle_id) . '"' . selected($filter_cycle, $cycle->cycle_id, false) . '>' . esc_html($cycle->cycle_name) . '</option>';
        }
    }
    echo '</select> ';

    submit_button(__('Filter', 'hl-core'), 'secondary', 'submit', false);
    echo '</form>';
```

- [ ] **Step 3: Add Cycle column to the table**

In the table header (lines 228-235), add a Cycle column between School and Age Band:

```php
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
    echo '<th>' . esc_html__('Classroom Name', 'hl-core') . '</th>';
    echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
    echo '<th>' . esc_html__('Cycle', 'hl-core') . '</th>';
    echo '<th>' . esc_html__('Age Band', 'hl-core') . '</th>';
    echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
    echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
    echo '</tr></thead>';
```

In the row rendering loop (lines 238-262), add the Cycle cell after School:

```php
        echo '<tr>';
        echo '<td>' . esc_html($classroom->classroom_id) . '</td>';
        echo '<td><strong><a href="' . esc_url($view_url) . '">' . esc_html($classroom->classroom_name) . '</a></strong></td>';
        echo '<td>' . esc_html($classroom->school_name) . '</td>';
        echo '<td>' . esc_html($classroom->cycle_name ?: '-') . '</td>';
        echo '<td>' . esc_html($classroom->age_band ? ucfirst($classroom->age_band) : '-') . '</td>';
        echo '<td><span style="' . esc_attr($status_style) . '">' . esc_html(ucfirst($classroom->status)) . '</span></td>';
        echo '<td>';
        echo '<a href="' . esc_url($view_url) . '" class="button button-small">' . esc_html__('View', 'hl-core') . '</a> ';
        echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
        echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this classroom?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
        echo '</td>';
        echo '</tr>';
```

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-classrooms.php
git commit -m "feat(classrooms): add cycle filter and cycle column to classrooms list page"
```

---

### Task 3: Cycle Field on Create/Edit Form + Return Navigation

Add Cycle dropdown to the classroom create/edit form, save it in the handler, and propagate `cycle_id` on back links.

**Files:**
- Modify: `includes/admin/class-hl-admin-classrooms.php` — `render_form()` (lines 270-338), `handle_actions()` (lines 101-139), `render_classroom_detail()` (line 372), and `render_form()` back link (line 281)

- [ ] **Step 1: Add cycle_id to the save handler**

In `handle_actions()`, add `cycle_id` to the `$data` array (after line 119):

```php
    $data = array(
        'classroom_name' => sanitize_text_field($_POST['classroom_name']),
        'school_id'      => absint($_POST['school_id']),
        'cycle_id'       => absint($_POST['cycle_id']),
        'age_band'       => sanitize_text_field($_POST['age_band']),
        'status'         => sanitize_text_field($_POST['status']),
    );
```

Also propagate `cycle_id` on the redirect URLs after create/update:

```php
    $cycle_param = isset($_POST['cycle_id']) ? '&cycle_id=' . absint($_POST['cycle_id']) : '';

    if ($classroom_id > 0) {
        $service->update_classroom($classroom_id, $data);
        $redirect = admin_url('admin.php?page=hl-classrooms&message=updated' . $cycle_param);
    } else {
        $result = $service->create_classroom($data);
        if (is_wp_error($result)) {
            $redirect = admin_url('admin.php?page=hl-classrooms&action=new&message=error' . $cycle_param);
        } else {
            $redirect = admin_url('admin.php?page=hl-classrooms&message=created' . $cycle_param);
        }
    }
```

- [ ] **Step 2: Add Cycle dropdown to the form**

In `render_form()`, query cycles and add the dropdown after the School field. Also propagate `cycle_id` on the "Back to Classrooms" link:

After the `$schools` query (line 276), add:

```php
    $cycles = $wpdb->get_results(
        "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle WHERE status != 'archived' ORDER BY cycle_name ASC"
    );
```

Replace the back link (line 281) with:

```php
    $back_params = '';
    $cycle_id_param = isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : ($is_edit && $classroom->cycle_id ? $classroom->cycle_id : 0);
    if ($cycle_id_param) {
        $back_params = '&cycle_id=' . $cycle_id_param;
    }
    echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms' . $back_params)) . '">&larr; ' . esc_html__('Back to Classrooms', 'hl-core') . '</a>';
```

After the School `</tr>` (line 310), add the Cycle dropdown:

```php
    // Cycle
    $current_cycle = $is_edit ? $classroom->cycle_id : (isset($_GET['cycle_id']) ? absint($_GET['cycle_id']) : '');
    echo '<tr>';
    echo '<th scope="row"><label for="cycle_id">' . esc_html__('Cycle', 'hl-core') . '</label></th>';
    echo '<td><select id="cycle_id" name="cycle_id" required>';
    echo '<option value="">' . esc_html__('-- Select Cycle --', 'hl-core') . '</option>';
    if ($cycles) {
        foreach ($cycles as $cycle) {
            echo '<option value="' . esc_attr($cycle->cycle_id) . '"' . selected($current_cycle, $cycle->cycle_id, false) . '>' . esc_html($cycle->cycle_name) . '</option>';
        }
    }
    echo '</select></td>';
    echo '</tr>';
```

- [ ] **Step 3: Propagate cycle_id on the detail page back link**

In `render_classroom_detail()`, update the "Back to Classrooms" link (line 372):

```php
    // BEFORE:
    echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms')) . '">&larr; ' . esc_html__('Back to Classrooms', 'hl-core') . '</a>';

    // AFTER:
    $back_params = '';
    if (isset($_GET['cycle_id']) && absint($_GET['cycle_id'])) {
        $back_params = '&cycle_id=' . absint($_GET['cycle_id']);
    }
    echo '<a href="' . esc_url(admin_url('admin.php?page=hl-classrooms' . $back_params)) . '">&larr; ' . esc_html__('Back to Classrooms', 'hl-core') . '</a>';
```

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-classrooms.php
git commit -m "feat(classrooms): add cycle dropdown to create/edit form, propagate cycle_id on back links"
```

---

### Task 4: Reassignment Service Method

Add `reassign_teaching_assignment()` to `HL_Classroom_Service` with transaction wrapping.

**Files:**
- Modify: `includes/services/class-hl-classroom-service.php` — add method after `delete_teaching_assignment()` (after line 188)

- [ ] **Step 1: Add the reassign method**

After the `delete_teaching_assignment()` method (after line 188), add:

```php
    /**
     * Reassign a teaching assignment from one classroom to another (atomic).
     *
     * @param int   $enrollment_id      The teacher's enrollment ID.
     * @param int   $old_classroom_id   Classroom to remove from.
     * @param int   $new_classroom_id   Classroom to assign to.
     * @param array $data               Optional keys: is_lead_teacher, effective_start_date, effective_end_date.
     * @return int|WP_Error New assignment_id on success, WP_Error on failure.
     */
    public function reassign_teaching_assignment($enrollment_id, $old_classroom_id, $new_classroom_id, $data = array()) {
        global $wpdb;

        // Look up the old assignment.
        $old_assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT assignment_id FROM {$wpdb->prefix}hl_teaching_assignment
             WHERE enrollment_id = %d AND classroom_id = %d",
            $enrollment_id,
            $old_classroom_id
        ));

        if (!$old_assignment) {
            return new WP_Error('not_found', __('Existing teaching assignment not found.', 'hl-core'));
        }

        $wpdb->query('START TRANSACTION');

        // Delete old assignment (with audit + hook).
        $deleted = $this->delete_teaching_assignment($old_assignment->assignment_id);

        if (!$deleted) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('delete_failed', __('Failed to remove old teaching assignment.', 'hl-core'));
        }

        // Create new assignment (with audit + hook).
        $new_assignment_id = $this->create_teaching_assignment(array(
            'enrollment_id'        => absint($enrollment_id),
            'classroom_id'         => absint($new_classroom_id),
            'is_lead_teacher'      => !empty($data['is_lead_teacher']) ? 1 : 0,
            'effective_start_date' => isset($data['effective_start_date']) ? $data['effective_start_date'] : '',
            'effective_end_date'   => isset($data['effective_end_date']) ? $data['effective_end_date'] : '',
        ));

        if (is_wp_error($new_assignment_id)) {
            $wpdb->query('ROLLBACK');
            return $new_assignment_id;
        }

        $wpdb->query('COMMIT');
        return $new_assignment_id;
    }
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-classroom-service.php
git commit -m "feat(classrooms): add reassign_teaching_assignment() with DB transaction"
```

---

### Task 5: Teacher Reassignment UI

Add the "Reassign from another classroom" optgroup, hidden field, confirmation dialog, reassignment handler, empty state hint, and new message key.

**Files:**
- Modify: `includes/admin/class-hl-admin-classrooms.php` — `handle_assignment_actions()` (lines 393-441), `render_teaching_assignments_section()` (lines 446-593), `render_classroom_detail()` messages (lines 356-368)

- [ ] **Step 1: Add `assignment_reassigned` message key**

In `render_classroom_detail()`, add the new message to the `$messages` array (after line 361):

```php
    $messages = array(
        'assignment_created'    => array('success', __('Teaching assignment added.', 'hl-core')),
        'assignment_removed'    => array('success', __('Teaching assignment removed.', 'hl-core')),
        'assignment_reassigned' => array('success', __('Teacher reassigned to this classroom.', 'hl-core')),
        'assignment_error'      => array('error', __('Failed to add teaching assignment.', 'hl-core')),
        'child_assigned'        => array('success', __('Child assigned to classroom.', 'hl-core')),
        'child_unassigned'      => array('success', __('Child removed from classroom.', 'hl-core')),
        'child_error'           => array('error', __('Failed to assign child.', 'hl-core')),
    );
```

- [ ] **Step 2: Update the assignment handler to support reassignment**

In `handle_assignment_actions()`, replace the POST handler (lines 395-418) with:

```php
    // Handle add or reassign (POST)
    if (isset($_POST['hl_teaching_assignment_nonce'])) {
        if (!wp_verify_nonce($_POST['hl_teaching_assignment_nonce'], 'hl_save_teaching_assignment')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $service      = new HL_Classroom_Service();
        $classroom_id = absint($_POST['classroom_id']);
        $cycle_id     = isset($_POST['cycle_id']) ? absint($_POST['cycle_id']) : 0;
        $reassign_from = isset($_POST['reassign_from_classroom_id']) ? absint($_POST['reassign_from_classroom_id']) : 0;

        $assignment_data = array(
            'is_lead_teacher'      => !empty($_POST['is_lead_teacher']) ? 1 : 0,
            'effective_start_date' => sanitize_text_field($_POST['effective_start_date']),
            'effective_end_date'   => sanitize_text_field($_POST['effective_end_date']),
        );

        if ($reassign_from) {
            // Reassignment — atomic delete + create.
            $result = $service->reassign_teaching_assignment(
                absint($_POST['enrollment_id']),
                $reassign_from,
                $classroom_id,
                $assignment_data
            );
            $msg = is_wp_error($result) ? 'assignment_error' : 'assignment_reassigned';
        } else {
            // Normal add.
            $result = $service->create_teaching_assignment(array_merge($assignment_data, array(
                'enrollment_id' => absint($_POST['enrollment_id']),
                'classroom_id'  => $classroom_id,
            )));
            $msg = is_wp_error($result) ? 'assignment_error' : 'assignment_created';
        }

        $redirect = admin_url('admin.php?page=hl-classrooms&action=view&id=' . $classroom_id . '&cycle_id=' . $cycle_id . '&message=' . $msg);
        wp_redirect($redirect);
        exit;
    }
```

- [ ] **Step 3: Add reassignment optgroup and empty state hint to the form**

In `render_teaching_assignments_section()`, replace the section that builds the `$available` list and renders the form (lines 514-592). The new code:

1. Queries all enrollments with teacher role at this school/cycle (existing logic).
2. Splits into `$available` (unassigned) and builds a separate `$reassignable` list from a new query.
3. Renders two optgroups.
4. Adds a hidden `reassign_from_classroom_id` field populated by JavaScript.
5. Shows the empty state hint when both lists are empty.

Replace lines 514-592 with:

```php
    // Add form (only when cycle selected)
    if ($selected_cycle) {
        // Get enrollments with Teacher role at this school for the selected cycle.
        $all_enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, e.roles, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cycle_id = %d AND e.school_id = %d AND e.status = 'active'
             ORDER BY u.display_name ASC",
            $selected_cycle,
            $classroom->school_id
        ));

        // Filter to Teacher role — split into available (unassigned here) vs. already assigned here.
        $available = array();
        $assigned_here_ids = array();
        foreach ($assignments as $a) {
            if (isset($a->cycle_id) && (int) $a->cycle_id === (int) $selected_cycle) {
                $assigned_here_ids[] = (int) $a->enrollment_id;
            }
        }

        foreach ($all_enrollments as $e) {
            $roles = HL_Roles::parse_stored($e->roles);
            if (!in_array('teacher', $roles, true)) {
                continue;
            }
            if (in_array((int) $e->enrollment_id, $assigned_here_ids, true)) {
                continue;
            }
            $available[] = $e;
        }

        // Query teachers assigned to OTHER classrooms at the same school in this cycle.
        $reassignable = $wpdb->get_results($wpdb->prepare(
            "SELECT ta.assignment_id, ta.classroom_id, ta.enrollment_id, cr.classroom_name, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_teaching_assignment ta
             JOIN {$wpdb->prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             JOIN {$wpdb->prefix}hl_classroom cr ON ta.classroom_id = cr.classroom_id
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cycle_id = %d AND e.school_id = %d AND ta.classroom_id != %d
             ORDER BY u.display_name ASC",
            $selected_cycle,
            $classroom->school_id,
            $classroom->classroom_id
        ));

        // Also exclude teachers that are already assigned HERE from the reassignable list.
        $reassignable_filtered = array();
        foreach ($reassignable as $r) {
            if (!in_array((int) $r->enrollment_id, $assigned_here_ids, true)) {
                $reassignable_filtered[] = $r;
            }
        }
        $reassignable = $reassignable_filtered;

        if (!empty($available) || !empty($reassignable)) {
            echo '<h3>' . esc_html__('Add Teaching Assignment', 'hl-core') . '</h3>';
            $form_url = admin_url('admin.php?page=hl-classrooms&action=view&id=' . $classroom->classroom_id . '&cycle_id=' . $selected_cycle);
            echo '<form method="post" action="' . esc_url($form_url) . '" id="hl-teaching-assignment-form">';
            wp_nonce_field('hl_save_teaching_assignment', 'hl_teaching_assignment_nonce');
            echo '<input type="hidden" name="classroom_id" value="' . esc_attr($classroom->classroom_id) . '" />';
            echo '<input type="hidden" name="cycle_id" value="' . esc_attr($selected_cycle) . '" />';
            echo '<input type="hidden" name="reassign_from_classroom_id" id="reassign_from_classroom_id" value="" />';

            echo '<table class="form-table">';

            // Teacher dropdown with optgroups
            echo '<tr>';
            echo '<th scope="row"><label for="enrollment_id">' . esc_html__('Teacher', 'hl-core') . '</label></th>';
            echo '<td><select id="enrollment_id" name="enrollment_id" required onchange="hlUpdateReassignField(this)">';
            echo '<option value="">' . esc_html__('-- Select Teacher --', 'hl-core') . '</option>';

            if (!empty($available)) {
                echo '<optgroup label="' . esc_attr__('Available Teachers', 'hl-core') . '">';
                foreach ($available as $t) {
                    echo '<option value="' . esc_attr($t->enrollment_id) . '" data-reassign="">' . esc_html($t->display_name . ' (' . $t->user_email . ')') . '</option>';
                }
                echo '</optgroup>';
            }

            if (!empty($reassignable)) {
                echo '<optgroup label="' . esc_attr__('Reassign from another classroom', 'hl-core') . '">';
                foreach ($reassignable as $r) {
                    echo '<option value="' . esc_attr($r->enrollment_id) . '" data-reassign="' . esc_attr($r->classroom_id) . '" data-from-name="' . esc_attr($r->classroom_name) . '">'
                        . esc_html($r->display_name . ' (currently: ' . $r->classroom_name . ')')
                        . '</option>';
                }
                echo '</optgroup>';
            }

            echo '</select></td>';
            echo '</tr>';

            // Lead teacher
            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Lead Teacher', 'hl-core') . '</th>';
            echo '<td><label><input type="checkbox" name="is_lead_teacher" value="1" /> ' . esc_html__('Yes', 'hl-core') . '</label></td>';
            echo '</tr>';

            // Dates
            echo '<tr>';
            echo '<th scope="row"><label for="effective_start_date">' . esc_html__('Start Date', 'hl-core') . '</label></th>';
            echo '<td><input type="date" id="effective_start_date" name="effective_start_date" /></td>';
            echo '</tr>';
            echo '<tr>';
            echo '<th scope="row"><label for="effective_end_date">' . esc_html__('End Date', 'hl-core') . '</label></th>';
            echo '<td><input type="date" id="effective_end_date" name="effective_end_date" /></td>';
            echo '</tr>';

            echo '</table>';
            submit_button(__('Add Assignment', 'hl-core'), 'primary');
            echo '</form>';

            // JavaScript: update hidden field + confirmation on reassign
            echo '<script>
            function hlUpdateReassignField(select) {
                var opt = select.options[select.selectedIndex];
                document.getElementById("reassign_from_classroom_id").value = opt.getAttribute("data-reassign") || "";
            }
            document.getElementById("hl-teaching-assignment-form").addEventListener("submit", function(e) {
                var reassignFrom = document.getElementById("reassign_from_classroom_id").value;
                if (reassignFrom) {
                    var opt = document.getElementById("enrollment_id").options[document.getElementById("enrollment_id").selectedIndex];
                    var fromName = opt.getAttribute("data-from-name") || "another classroom";
                    if (!confirm("This will reassign " + opt.text.split(" (currently")[0] + " from " + fromName + " to this classroom. Continue?")) {
                        e.preventDefault();
                    }
                }
            });
            </script>';

        } elseif (empty($all_enrollments)) {
            echo '<p>' . esc_html__('No active enrollments found for this cycle and school.', 'hl-core') . '</p>';
        } else {
            // All teachers assigned here and no reassignable teachers from other classrooms.
            echo '<p>' . wp_kses(
                sprintf(
                    __('No teachers available to assign. <a href="%s">Create an enrollment</a> for this school first.', 'hl-core'),
                    esc_url(admin_url('admin.php?page=hl-enrollments'))
                ),
                array('a' => array('href' => array()))
            ) . '</p>';
        }
    } else {
        echo '<p class="description">' . esc_html__('Select a cycle above to add teaching assignments.', 'hl-core') . '</p>';
    }
```

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-classrooms.php
git commit -m "feat(classrooms): teacher reassignment UI with optgroup, confirmation dialog, and empty state hint"
```

---

### Task 6: Deploy and Verify

Deploy all changes to the test server and verify end-to-end.

- [ ] **Step 1: Deploy to test server**

```bash
cd "C:/Users/MateoGonzalez/Dev Projects Mateo/housman-learning-academy/app/public/wp-content/plugins/hl-core"
tar --exclude='.git' --exclude='data' --exclude='./vendor' --exclude='node_modules' --exclude='.superpowers' -czf /tmp/hl-core.tar.gz -C .. hl-core
scp -i ~/.ssh/hla-test-keypair.pem /tmp/hl-core.tar.gz bitnami@44.221.6.201:/tmp/
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress/wp-content/plugins && sudo rm -rf hl-core && sudo tar -xzf /tmp/hl-core.tar.gz && sudo chown -R bitnami:daemon hl-core'
```

- [ ] **Step 2: Verify cycle tab deep links (spec test 1)**

Navigate to Cycle admin > LSF_2025-2027_CONTROL > Classrooms tab. Verify:
- "Manage Classrooms" button URL contains `cycle_id=1`
- Each row has View and Edit buttons, both with `cycle_id=1`
- "Add Classroom" button links to the create form with `cycle_id=1`

- [ ] **Step 3: Verify cycle filter on list page (spec test 2)**

Click "Manage Classrooms" from the cycle tab. Verify:
- Cycle dropdown is visible, auto-selects "LSF_2025-2027_CONTROL"
- Only the 30 control group classrooms are shown
- Cycle column displays the cycle name for each row
- Clearing the filter shows all classrooms again

- [ ] **Step 4: Verify create form (spec test 3)**

Click "Add Classroom" from the cycle tab. Verify:
- Cycle dropdown is present and pre-selects "LSF_2025-2027_CONTROL"
- Cycle field is required (HTML validation)
- Creating a classroom saves the cycle_id

- [ ] **Step 5: Verify edit form (spec test 4)**

Edit an existing classroom (e.g., BNFDC28). Verify:
- Cycle dropdown shows current value (LSF_2025-2027_CONTROL)
- Changing and saving works

- [ ] **Step 6: Verify return navigation (spec test 5)**

From the cycle tab, click View on a classroom, then click "Back to Classrooms." Verify the list is filtered by cycle_id, not showing all 99 classrooms.

- [ ] **Step 7: Verify reassignment optgroup (spec test 6)**

View BNFDC28 at Bear Necessities. Select LSF_2025-2027_CONTROL cycle. Verify:
- "Reassign from another classroom" optgroup appears (Bear Necessities only has 1 teacher so this school won't have reassignable options — test with a school that has multiple classrooms like West Palm Beach or Jupiter Head Start instead)
- Teachers show "currently: [Classroom Name]" in the dropdown

- [ ] **Step 8: Verify reassignment flow (spec test 7)**

Select a teacher from the "Reassign" optgroup. Verify:
- Confirmation dialog appears
- After confirming: success notice "Teacher reassigned successfully"
- Old classroom no longer shows the teacher
- New classroom shows the teacher
- Audit log entries exist (check via WP-CLI: `wp db query "SELECT * FROM wp_hl_audit_log ORDER BY id DESC LIMIT 5"`)

- [ ] **Step 9: Verify empty state hint (spec test 8)**

Navigate to a single-teacher school's classroom where the teacher is already assigned (e.g., Bear Necessities BNFDC28). Verify:
- If no other classrooms at the school have teachers to reassign, the "Create an enrollment" hint with link appears

- [ ] **Step 10: Verify regressions (spec test 10)**

Spot-check a non-control-group classroom (e.g., from ELCPBC cycle). Verify classroom CRUD, teaching assignments, and children roster work normally.

- [ ] **Step 11: Update STATUS.md and README.md**

Per CLAUDE.md rule #3, update both files to reflect the completed work.
