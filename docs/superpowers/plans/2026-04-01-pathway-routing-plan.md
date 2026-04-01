# Pathway Routing Engine — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatic pathway assignment based on user-level LearnDash course completion and enrollment role, plus bug fixes to role normalization, sync_role_defaults, and audit logging.

**Architecture:** A stateless `HL_Pathway_Routing_Service` with hardcoded stage definitions and routing rules. Three consumers call `resolve_pathway($user_id, $role, $cycle_id)`. Five pre-existing bugs are fixed alongside.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, LearnDash API, jQuery (admin form AJAX)

**Spec:** `docs/superpowers/specs/2026-04-01-pathway-routing-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `includes/services/class-hl-pathway-routing-service.php` | **Create** | Stage definitions, routing rules, `resolve_pathway()`, `get_completed_stages()` |
| `includes/services/class-hl-pathway-assignment-service.php` | **Modify** | Fix audit logging, fix sync_role_defaults (routing first + one pathway only) |
| `includes/services/class-hl-import-participant-handler.php` | **Modify** | Role normalization to lowercase, call routing in validate + commit, clear stale pathways on role-change UPDATE |
| `includes/admin/class-hl-admin-enrollments.php` | **Modify** | AJAX auto-suggest pathway, auto-route on save when no pathway selected |
| `hl-core.php` | **Modify** | Add require_once for routing service |

---

### Task 1: Create Pathway Routing Service

**Files:**
- Create: `includes/services/class-hl-pathway-routing-service.php`
- Modify: `hl-core.php`

- [ ] **Step 1: Create the routing service class**

Create `includes/services/class-hl-pathway-routing-service.php`:

```php
<?php
if (!defined('ABSPATH')) exit;

/**
 * Pathway Routing Service
 *
 * Determines the correct pathway for a participant based on their role
 * and which LearnDash course stages they have completed (user-level).
 *
 * Stage definitions and routing rules are hardcoded for the B2E program.
 * For non-B2E cycles, returns null (callers fall back to target_roles matching).
 *
 * @package HL_Core
 */
class HL_Pathway_Routing_Service {

    /**
     * Stage definitions: groups of LearnDash course IDs.
     * A stage is "completed" when ALL courses in the group are done.
     */
    private static $stages = array(
        'A' => array(
            'label'      => 'Mentor Stage 1',
            'course_ids' => array(30293, 30295), // MC1, MC2
        ),
        'C' => array(
            'label'      => 'Teacher Stage 1',
            'course_ids' => array(30280, 30284, 30286, 30288), // TC1, TC2, TC3, TC4
        ),
        'E' => array(
            'label'      => 'Streamlined Stage 1',
            'course_ids' => array(31037, 31332, 31333, 31334, 31335, 31387, 31388), // TC0, TC1_S-TC4_S, MC1_S, MC2_S
        ),
    );

    /**
     * Routing rules evaluated in priority order. First match wins.
     * Stage matching is INCLUSIVE: user must have completed ALL listed stages (and possibly others).
     *
     * Each rule: array( role, required_stage_keys, pathway_code )
     */
    private static $routing_rules = array(
        array('mentor',          array('C', 'A'), 'b2e-mentor-completion'),
        array('mentor',          array('C'),      'b2e-mentor-transition'),
        array('mentor',          array('A'),      'b2e-mentor-phase-2'),
        array('mentor',          array(),          'b2e-mentor-phase-1'),
        array('teacher',         array('C'),      'b2e-teacher-phase-2'),
        array('teacher',         array(),          'b2e-teacher-phase-1'),
        array('school_leader',   array('E'),      'b2e-streamlined-phase-2'),
        array('school_leader',   array(),          'b2e-streamlined-phase-1'),
        array('district_leader', array('E'),      'b2e-streamlined-phase-2'),
        array('district_leader', array(),          'b2e-streamlined-phase-1'),
    );

    /**
     * Resolve the correct pathway for a user being enrolled in a cycle.
     *
     * @param int|null $user_id   WordPress user ID. Null for new users (no account yet).
     * @param string   $role      Role string (any format — normalized internally).
     * @param int      $cycle_id  Target cycle.
     * @return int|null            Pathway ID if a routing rule matches, null otherwise.
     */
    public static function resolve_pathway($user_id, $role, $cycle_id) {
        $normalized_role = self::normalize_role($role);
        if (empty($normalized_role)) {
            return null;
        }

        // Get user's completed stages
        $completed_stages = self::get_completed_stages($user_id);

        // Find first matching rule
        foreach (self::$routing_rules as $rule) {
            list($rule_role, $required_stages, $pathway_code) = $rule;

            if ($rule_role !== $normalized_role) {
                continue;
            }

            // Check if user has ALL required stages (inclusive match)
            $has_all = true;
            foreach ($required_stages as $stage_key) {
                if (!in_array($stage_key, $completed_stages, true)) {
                    $has_all = false;
                    break;
                }
            }

            if (!$has_all) {
                continue;
            }

            // Match found — look up pathway by code in this cycle
            $pathway_id = self::lookup_pathway_by_code($pathway_code, $cycle_id);
            if ($pathway_id) {
                return $pathway_id;
            }
            // Pathway code doesn't exist in this cycle — continue to next rule
            // (this allows non-B2E cycles to fall through gracefully)
        }

        return null;
    }

    /**
     * Get which stages a user has completed.
     *
     * @param int|null $user_id
     * @return string[] Array of completed stage keys (e.g., ['A', 'C']).
     */
    public static function get_completed_stages($user_id) {
        if (!$user_id) {
            return array();
        }

        $ld = HL_LearnDash_Integration::instance();
        if (!$ld->is_active()) {
            return array();
        }

        $completed = array();

        foreach (self::$stages as $key => $stage) {
            $all_done = true;
            foreach ($stage['course_ids'] as $course_id) {
                if (!$ld->is_course_completed($user_id, $course_id)) {
                    $all_done = false;
                    break;
                }
            }
            if ($all_done) {
                $completed[] = $key;
            }
        }

        return $completed;
    }

    /**
     * Look up a pathway by code within a specific cycle.
     *
     * @param string $pathway_code
     * @param int    $cycle_id
     * @return int|null Pathway ID or null.
     */
    private static function lookup_pathway_by_code($pathway_code, $cycle_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT pathway_id FROM {$wpdb->prefix}hl_pathway
             WHERE pathway_code = %s AND cycle_id = %d AND active_status = 1",
            $pathway_code, $cycle_id
        ));
    }

    /**
     * Normalize a role string to lowercase snake_case.
     *
     * Accepts: "Teacher", "teacher", "School Leader", "school_leader", "MENTOR", etc.
     *
     * @param string $role
     * @return string Normalized role or empty string.
     */
    public static function normalize_role($role) {
        $role = strtolower(trim($role));
        $role = str_replace(' ', '_', $role);

        $valid = array('teacher', 'mentor', 'school_leader', 'district_leader');
        return in_array($role, $valid, true) ? $role : '';
    }

    /**
     * Get stage definitions (for display/debugging).
     *
     * @return array
     */
    public static function get_stage_definitions() {
        return self::$stages;
    }
}
```

- [ ] **Step 2: Add require_once in hl-core.php**

In `hl-core.php`, after the pathway assignment service require (line 119), add:

```php
require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-pathway-routing-service.php';
```

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-pathway-routing-service.php hl-core.php
git commit -m "feat(routing): create pathway routing service with stage definitions and rules"
```

---

### Task 2: Fix Audit Logging in Pathway Assignment Service

**Files:**
- Modify: `includes/services/class-hl-pathway-assignment-service.php`

The `assign_pathway()` and `unassign_pathway()` methods call `HL_Audit_Service::log()` with 6 positional args. The correct signature is `log($action_type, $data_array)`.

- [ ] **Step 1: Fix audit logging in assign_pathway()**

In `includes/services/class-hl-pathway-assignment-service.php`, find the audit block in `assign_pathway()` (lines 58-70). Replace:

```php
        if (class_exists('HL_Audit_Service')) {
            $enrollment = $wpdb->get_row($wpdb->prepare(
                "SELECT cycle_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d", $enrollment_id
            ));
            HL_Audit_Service::log(
                'pathway_assigned',
                get_current_user_id(),
                $enrollment ? $enrollment->cycle_id : null,
                null,
                $assignment_id,
                sprintf('Pathway #%d assigned to enrollment #%d (%s)', $pathway_id, $enrollment_id, $type)
            );
        }
```

With:

```php
        if (class_exists('HL_Audit_Service')) {
            $enrollment = $wpdb->get_row($wpdb->prepare(
                "SELECT cycle_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d", $enrollment_id
            ));
            HL_Audit_Service::log('pathway_assigned', array(
                'cycle_id'    => $enrollment ? $enrollment->cycle_id : null,
                'entity_type' => 'pathway_assignment',
                'entity_id'   => $assignment_id,
                'reason'      => sprintf('Pathway #%d assigned to enrollment #%d (%s)', $pathway_id, $enrollment_id, $type),
            ));
        }
```

- [ ] **Step 2: Fix audit logging in unassign_pathway()**

Find the audit block in `unassign_pathway()` (around lines 97-109). Replace the same pattern:

```php
        if (class_exists('HL_Audit_Service')) {
            $enrollment = $wpdb->get_row($wpdb->prepare(
                "SELECT cycle_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d", absint($enrollment_id)
            ));
            HL_Audit_Service::log(
                'pathway_unassigned',
                get_current_user_id(),
                $enrollment ? $enrollment->cycle_id : null,
                null,
                absint($enrollment_id),
                sprintf('Pathway #%d unassigned from enrollment #%d', $pathway_id, $enrollment_id)
            );
        }
```

With:

```php
        if (class_exists('HL_Audit_Service')) {
            $enrollment = $wpdb->get_row($wpdb->prepare(
                "SELECT cycle_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d", absint($enrollment_id)
            ));
            HL_Audit_Service::log('pathway_unassigned', array(
                'cycle_id'    => $enrollment ? $enrollment->cycle_id : null,
                'entity_type' => 'pathway_assignment',
                'entity_id'   => absint($enrollment_id),
                'reason'      => sprintf('Pathway #%d unassigned from enrollment #%d', $pathway_id, $enrollment_id),
            ));
        }
```

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-pathway-assignment-service.php
git commit -m "fix(audit): correct audit logging signature in pathway assignment service"
```

---

### Task 3: Fix sync_role_defaults — Routing First, One Pathway Only

**Files:**
- Modify: `includes/services/class-hl-pathway-assignment-service.php:285-337`

- [ ] **Step 1: Rewrite sync_role_defaults()**

Replace the entire `sync_role_defaults()` method (lines 285-337) with:

```php
    /**
     * Sync pathway assignments for a cycle.
     *
     * For each unassigned enrollment:
     * 1. Try HL_Pathway_Routing_Service (stage-based routing for B2E cycles)
     * 2. Fall back to target_roles matching (for non-B2E cycles)
     * 3. Assign at most ONE pathway per enrollment
     *
     * @param int $cycle_id
     * @return array Results with 'created' and 'routed' counts.
     */
    public function sync_role_defaults($cycle_id) {
        global $wpdb;

        $cycle_id = absint($cycle_id);
        $created = 0;
        $routed  = 0;

        // Get all active enrollments without any pathway assignments.
        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, e.user_id, e.roles
             FROM {$wpdb->prefix}hl_enrollment e
             WHERE e.cycle_id = %d AND e.status = 'active'
               AND e.enrollment_id NOT IN (
                   SELECT enrollment_id FROM {$wpdb->prefix}hl_pathway_assignment
               )",
            $cycle_id
        ), ARRAY_A);

        if (empty($enrollments)) {
            return array('created' => 0, 'routed' => 0);
        }

        // Get all active pathways for target_roles fallback.
        $pathways = $wpdb->get_results($wpdb->prepare(
            "SELECT pathway_id, target_roles FROM {$wpdb->prefix}hl_pathway WHERE cycle_id = %d AND active_status = 1",
            $cycle_id
        ), ARRAY_A);

        foreach ($enrollments as $e) {
            $e_roles = json_decode($e['roles'], true);
            if (!is_array($e_roles) || empty($e_roles)) {
                continue;
            }

            $assigned = false;

            // Step 1: Try routing service for each role
            foreach ($e_roles as $role) {
                $pathway_id = HL_Pathway_Routing_Service::resolve_pathway(
                    (int) $e['user_id'],
                    $role,
                    $cycle_id
                );
                if ($pathway_id) {
                    $result = $this->assign_pathway($e['enrollment_id'], $pathway_id, 'role_default');
                    if (!is_wp_error($result)) {
                        $routed++;
                        $assigned = true;
                    }
                    break; // One pathway per enrollment
                }
            }

            if ($assigned) {
                continue;
            }

            // Step 2: Fall back to target_roles matching (one pathway only)
            foreach ($e_roles as $role) {
                $normalized_role = HL_Pathway_Routing_Service::normalize_role($role);
                if (empty($normalized_role)) {
                    continue;
                }

                foreach ($pathways as $pw) {
                    $target_roles = json_decode($pw['target_roles'], true);
                    if (!is_array($target_roles)) {
                        continue;
                    }

                    // Normalize target_roles for comparison (both sides lowercase)
                    $target_normalized = array_map(function($r) {
                        return strtolower(str_replace(' ', '_', trim($r)));
                    }, $target_roles);

                    if (in_array($normalized_role, $target_normalized, true)) {
                        $result = $this->assign_pathway($e['enrollment_id'], $pw['pathway_id'], 'role_default');
                        if (!is_wp_error($result)) {
                            $created++;
                            $assigned = true;
                        }
                        break 2; // One pathway per enrollment — exit both loops
                    }
                }
            }
        }

        return array('created' => $created, 'routed' => $routed);
    }
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-pathway-assignment-service.php
git commit -m "fix(routing): sync_role_defaults uses routing service first, assigns ONE pathway only"
```

---

### Task 4: Fix Role Normalization in Import Participant Handler

**Files:**
- Modify: `includes/services/class-hl-import-participant-handler.php`

- [ ] **Step 1: Update resolve_role() to return lowercase**

In `includes/services/class-hl-import-participant-handler.php`, find `resolve_role()` (lines 377-392). Replace the synonyms map values with lowercase:

```php
    private function resolve_role($raw) {
        $normalized = strtolower(trim($raw));
        $synonyms = array(
            'teacher'         => 'teacher',
            'maestro'         => 'teacher',
            'maestra'         => 'teacher',
            'mentor'          => 'mentor',
            'school leader'   => 'school_leader',
            'school_leader'   => 'school_leader',
            'lider de centro' => 'school_leader',
            'director'        => 'school_leader',
            'district leader' => 'district_leader',
            'district_leader' => 'district_leader',
        );
        return isset($synonyms[$normalized]) ? $synonyms[$normalized] : '';
    }
```

- [ ] **Step 2: Update role-change detection to be case-insensitive**

Find the role-change detection in `validate()` (around line 316 where `$role_changed` is set). The current code does strict comparison between Title Case (from CSV) and whatever is stored. Update to normalize both sides:

Find:
```php
                $existing_roles = json_decode($existing['roles'], true) ?: array();
                $role_changed   = !in_array($parsed_role, $existing_roles, true);
```

Replace with:
```php
                $existing_roles = json_decode($existing['roles'], true) ?: array();
                $existing_roles_normalized = array_map(function($r) {
                    return strtolower(str_replace(' ', '_', trim($r)));
                }, $existing_roles);
                $role_changed = !in_array($parsed_role, $existing_roles_normalized, true);
```

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-import-participant-handler.php
git commit -m "fix(import): normalize roles to lowercase, case-insensitive role-change detection"
```

---

### Task 5: Integrate Routing into Import Handler

**Files:**
- Modify: `includes/services/class-hl-import-participant-handler.php`

- [ ] **Step 1: Add routing call in validate() for pathway suggestion**

Find the section in `validate()` where pathway is processed (look for `parsed_pathway`). After the existing pathway validation code, add routing logic for rows without an explicit pathway. Find the block that sets `parsed_pathway` from the CSV (around where `$raw_pathway` is parsed), and after the pathway name/code matching section, add:

```php
            // --- Auto-route pathway if not provided in CSV ---
            $preview['pathway_source'] = '';
            if (!empty($raw_pathway)) {
                $preview['pathway_source'] = 'csv';
            } elseif (!$has_errors) {
                // Try routing service
                $routed_user_id = $preview['matched_user_id'] ? $preview['matched_user_id'] : null;
                $routed_pathway_id = HL_Pathway_Routing_Service::resolve_pathway($routed_user_id, $parsed_role, $cycle_id);
                if ($routed_pathway_id) {
                    // Look up pathway name for display
                    $routed_pw = $wpdb->get_row($wpdb->prepare(
                        "SELECT pathway_name, pathway_code FROM {$prefix}hl_pathway WHERE pathway_id = %d",
                        $routed_pathway_id
                    ));
                    if ($routed_pw) {
                        $preview['parsed_pathway'] = $routed_pw->pathway_name;
                        $preview['routed_pathway_id'] = $routed_pathway_id;
                        $preview['pathway_source'] = $routed_user_id ? 'routed' : 'default';
                        $preview['proposed_actions'][] = sprintf(
                            __('Pathway: %s (%s)', 'hl-core'),
                            $routed_pw->pathway_name,
                            $routed_user_id ? __('auto-routed based on course history', 'hl-core') : __('default for new participants', 'hl-core')
                        );
                    }
                }
            }
```

- [ ] **Step 2: Update commit() to use routing and handle stale pathways on role change**

In the `commit()` method, find the pathway assignment section (around line 689 where explicit pathway is assigned). Replace the pathway section AND remove the `sync_role_defaults` call at the end. The new logic:

Find the existing pathway assignment block and the `sync_role_defaults` call (around lines 689-700). Replace with:

```php
                // 6. Pathway Assignment
                // On UPDATE with role change: clear stale pathways first
                if ($row['status'] === 'UPDATE' || $row['status'] === 'WARNING') {
                    // Check if role changed by comparing to existing
                    $existing_roles = $wpdb->get_var($wpdb->prepare(
                        "SELECT roles FROM {$prefix}hl_enrollment WHERE enrollment_id = %d",
                        $enrollment_id
                    ));
                    $old_roles = json_decode($existing_roles, true) ?: array();
                    $old_roles_normalized = array_map(function($r) {
                        return strtolower(str_replace(' ', '_', trim($r)));
                    }, $old_roles);
                    if (!in_array($role, $old_roles_normalized, true)) {
                        // Role changed — clear all existing pathway assignments
                        $wpdb->delete($prefix . 'hl_pathway_assignment', array('enrollment_id' => $enrollment_id));
                        $pathway_service->sync_enrollment_assigned_pathway($enrollment_id);
                    }
                }

                if (!empty($row['parsed_pathway'])) {
                    // Explicit pathway from CSV
                    $pw_key = strtolower(trim($row['parsed_pathway']));
                    $matched_pw = isset($pathway_by_name[$pw_key]) ? $pathway_by_name[$pw_key]
                        : (isset($pathway_by_code[$pw_key]) ? $pathway_by_code[$pw_key] : null);
                    if ($matched_pw) {
                        $pathway_service->assign_pathway($enrollment_id, (int) $matched_pw['pathway_id'], 'explicit');
                    }
                } elseif (isset($row['routed_pathway_id']) && $row['routed_pathway_id']) {
                    // Routed pathway from validate step
                    $pathway_service->assign_pathway($enrollment_id, (int) $row['routed_pathway_id'], 'role_default');
                } else {
                    // Try routing at commit time (user may have been just created)
                    $routed_id = HL_Pathway_Routing_Service::resolve_pathway($user_id, $role, $cycle_id);
                    if ($routed_id) {
                        $pathway_service->assign_pathway($enrollment_id, $routed_id, 'role_default');
                    }
                }
```

Also remove the `$pathway_service->sync_role_defaults($cycle_id);` call that was at the end of the commit (after the foreach loop). It's no longer needed — routing happens per-row now.

**IMPORTANT:** The `sync_enrollment_assigned_pathway` method is private in `HL_Pathway_Assignment_Service`. To call it for clearing stale pathways, either make it public (preferred) or use `$wpdb->update` directly to set `assigned_pathway_id = NULL`. Use the direct approach:

```php
// Instead of calling sync_enrollment_assigned_pathway:
$wpdb->update(
    $prefix . 'hl_enrollment',
    array('assigned_pathway_id' => null),
    array('enrollment_id' => $enrollment_id)
);
```

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-import-participant-handler.php
git commit -m "feat(routing): integrate routing service into import handler, clear stale pathways on role change"
```

---

### Task 6: Add Pathway Auto-Suggest to Admin Enrollment Form

**Files:**
- Modify: `includes/admin/class-hl-admin-enrollments.php`

- [ ] **Step 1: Add AJAX endpoint for pathway suggestion**

In the `__construct()` method of `HL_Admin_Enrollments`, add the AJAX action:

```php
add_action('wp_ajax_hl_suggest_pathway', array($this, 'ajax_suggest_pathway'));
```

Then add the handler method to the class:

```php
    /**
     * AJAX: Suggest pathway based on routing service.
     */
    public function ajax_suggest_pathway() {
        check_ajax_referer('hl_suggest_pathway', 'nonce');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $user_id  = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $role     = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : '';
        $cycle_id = isset($_POST['cycle_id']) ? absint($_POST['cycle_id']) : 0;

        if (!$role || !$cycle_id) {
            wp_send_json_error(array('message' => __('Missing required fields.', 'hl-core')));
        }

        $pathway_id = HL_Pathway_Routing_Service::resolve_pathway(
            $user_id ?: null,
            $role,
            $cycle_id
        );

        if ($pathway_id) {
            global $wpdb;
            $pathway_name = $wpdb->get_var($wpdb->prepare(
                "SELECT pathway_name FROM {$wpdb->prefix}hl_pathway WHERE pathway_id = %d",
                $pathway_id
            ));
            $source = $user_id ? 'routed' : 'default';
            wp_send_json_success(array(
                'pathway_id'   => $pathway_id,
                'pathway_name' => $pathway_name,
                'source'       => $source,
            ));
        } else {
            wp_send_json_success(array(
                'pathway_id'   => 0,
                'pathway_name' => '',
                'source'       => 'none',
            ));
        }
    }
```

- [ ] **Step 2: Add auto-route on save when no pathway selected**

In the `handle_save()` method, after the pathway assignment block (around line 206), add a fallback for when no pathway was selected:

Find the end of the pathway assignment block (after line 206, before team membership handling). Add:

```php
        // Auto-route pathway if admin left it blank and this is a new enrollment.
        if ($enrollment_id && !$new_pathway_id && !$old_pathway_id) {
            $user_id_for_routing = absint($_POST['user_id']);
            $first_role = !empty($roles) ? $roles[0] : '';
            if ($first_role) {
                $routed_id = HL_Pathway_Routing_Service::resolve_pathway(
                    $user_id_for_routing,
                    $first_role,
                    absint($_POST['cycle_id'])
                );
                if ($routed_id) {
                    $pa_service = isset($pa_service) ? $pa_service : new HL_Pathway_Assignment_Service();
                    $pa_service->assign_pathway($enrollment_id, $routed_id, 'role_default');
                }
            }
        }
```

- [ ] **Step 3: Add JavaScript for AJAX pathway suggestion**

Find where the enrollment form's pathway dropdown is rendered. After it, add inline JavaScript that fires an AJAX call when the cycle or role changes. Look for the existing cycle-filtering JS (around line 827-843 based on the explorer's findings). After that block, add:

```php
        // Pathway auto-suggest JS
        ?>
        <script>
        jQuery(function($) {
            var suggestTimeout;
            var $pathwaySelect = $('#pathway_id');
            var $cycleSelect = $('#cycle_id');
            var $roleBoxes = $('input[name="roles[]"]');
            var $suggestLabel = $('<span class="hl-pathway-suggest-label" style="margin-left:8px;font-style:italic;color:#666;font-size:12px;"></span>');
            $pathwaySelect.after($suggestLabel);

            function suggestPathway() {
                clearTimeout(suggestTimeout);
                suggestTimeout = setTimeout(function() {
                    var cycleId = $cycleSelect.val();
                    var checkedRoles = [];
                    $roleBoxes.filter(':checked').each(function() { checkedRoles.push($(this).val()); });
                    var userId = $('#user_id').val() || 0;

                    if (!cycleId || !checkedRoles.length) {
                        $suggestLabel.text('');
                        return;
                    }

                    $.post(ajaxurl, {
                        action: 'hl_suggest_pathway',
                        nonce: '<?php echo wp_create_nonce("hl_suggest_pathway"); ?>',
                        user_id: userId,
                        role: checkedRoles[0],
                        cycle_id: cycleId
                    }, function(resp) {
                        if (resp.success && resp.data.pathway_id) {
                            // Only auto-select if admin hasn't already picked one
                            if (!$pathwaySelect.val() || $pathwaySelect.val() === '0' || $pathwaySelect.val() === '') {
                                $pathwaySelect.val(resp.data.pathway_id);
                            }
                            var label = resp.data.source === 'routed'
                                ? '<?php echo esc_js(__("Auto-suggested based on course history", "hl-core")); ?>'
                                : '<?php echo esc_js(__("Default for new participants", "hl-core")); ?>';
                            $suggestLabel.text(label);
                        } else {
                            $suggestLabel.text('');
                        }
                    });
                }, 300);
            }

            $cycleSelect.on('change', suggestPathway);
            $roleBoxes.on('change', suggestPathway);
        });
        </script>
        <?php
```

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-enrollments.php
git commit -m "feat(routing): AJAX pathway auto-suggest on admin enrollment form"
```

---

### Task 7: Fix District Leader target_roles in Seeders + Live Data

**Files:**
- Modify: `includes/cli/class-hl-cli-setup-elcpb-y2-v2.php`
- Modify: `includes/cli/class-hl-cli-seed-beginnings.php`

- [ ] **Step 1: Update ELCPB Y2 seeder**

In `includes/cli/class-hl-cli-setup-elcpb-y2-v2.php`, find where Streamlined Phase 1 and Phase 2 pathways set `target_roles`. Change from `array('school_leader')` to `array('school_leader', 'district_leader')`. Search for `'target_roles'` near the Streamlined pathway creation.

- [ ] **Step 2: Update Beginnings seeder**

Same change in `includes/cli/class-hl-cli-seed-beginnings.php` — find Streamlined pathway creation and add `'district_leader'` to `target_roles`.

- [ ] **Step 3: Commit**

```bash
git add includes/cli/class-hl-cli-setup-elcpb-y2-v2.php includes/cli/class-hl-cli-seed-beginnings.php
git commit -m "fix(data): add district_leader to Streamlined pathway target_roles in seeders"
```

---

### Task 8: Update Live Database + Deploy + Test

- [ ] **Step 1: Deploy to test server**

Push to GitHub, SCP to test server, flush cache.

- [ ] **Step 2: Fix live pathway data on test server**

Run via SSH to update existing Streamlined pathways:

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress eval "
global \$wpdb;
\$prefix = \$wpdb->prefix;
// Find Streamlined pathways with only school_leader in target_roles
\$rows = \$wpdb->get_results(\"SELECT pathway_id, pathway_name, target_roles FROM {$prefix}hl_pathway WHERE pathway_name LIKE \\\"%Streamlined%\\\" AND active_status = 1\");
foreach (\$rows as \$r) {
    \$roles = json_decode(\$r->target_roles, true);
    if (is_array(\$roles) && !in_array(\"district_leader\", \$roles)) {
        \$roles[] = \"district_leader\";
        \$wpdb->update(\"{$prefix}hl_pathway\", array(\"target_roles\" => wp_json_encode(\$roles)), array(\"pathway_id\" => \$r->pathway_id));
        WP_CLI::log(\"Updated: \" . \$r->pathway_name);
    }
}
WP_CLI::success(\"Done.\");
"'
```

- [ ] **Step 3: Manual testing checklist**

Test on the test server:

1. **Routing service directly** — Run WP-CLI to test routing for known users:
   - A returning teacher (has TC1-TC4 done) → should route to Teacher Phase 2
   - A new teacher → should route to Teacher Phase 1
   - A mentor with MC1+MC2+TC1-TC4 done → should route to Mentor Completion

2. **Import flow** — Upload a small test CSV (3 rows: new teacher, returning teacher, returning mentor) WITHOUT a pathway column. Preview should show auto-routed pathways with source labels.

3. **Admin enrollment form** — Create new enrollment, select ELCPB Y2 cycle, check Teacher role → pathway dropdown should auto-fill with the routed pathway.

4. **sync_role_defaults** — Run `wp eval` to call `sync_role_defaults` on ELCPB Y2 cycle. Verify each enrollment gets exactly ONE pathway.

- [ ] **Step 4: Update STATUS.md and README.md**

Add Pathway Routing Engine section to STATUS.md build queue. Update README.md services list.

- [ ] **Step 5: Commit docs and push**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md and README.md for pathway routing engine"
git push origin main
```
