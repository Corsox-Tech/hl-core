<?php
if (!defined('ABSPATH')) exit;

/**
 * Pathway Assignment Service
 *
 * Manages explicit pathway-to-enrollment assignments.
 * Supports role-based defaults with explicit overrides.
 *
 * @package HL_Core
 */
class HL_Pathway_Assignment_Service {

    /**
     * Assign a pathway to an enrollment.
     *
     * @param int    $enrollment_id
     * @param int    $pathway_id
     * @param string $type 'explicit' or 'role_default'
     * @return int|WP_Error Assignment ID on success.
     */
    public function assign_pathway($enrollment_id, $pathway_id, $type = 'explicit') {
        global $wpdb;

        $enrollment_id = absint($enrollment_id);
        $pathway_id    = absint($pathway_id);

        if (!$enrollment_id || !$pathway_id) {
            return new WP_Error('missing_ids', __('Enrollment ID and Pathway ID are required.', 'hl-core'));
        }

        // Check if assignment already exists.
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT assignment_id FROM {$wpdb->prefix}hl_pathway_assignment WHERE enrollment_id = %d AND pathway_id = %d",
            $enrollment_id, $pathway_id
        ));

        if ($existing) {
            return new WP_Error('already_assigned', __('This pathway is already assigned to this enrollment.', 'hl-core'));
        }

        $wpdb->insert($wpdb->prefix . 'hl_pathway_assignment', array(
            'enrollment_id'      => $enrollment_id,
            'pathway_id'         => $pathway_id,
            'assigned_by_user_id' => get_current_user_id(),
            'assignment_type'    => in_array($type, array('role_default', 'explicit'), true) ? $type : 'explicit',
        ));

        $assignment_id = $wpdb->insert_id;

        if (!$assignment_id) {
            return new WP_Error('insert_failed', __('Failed to create pathway assignment.', 'hl-core'));
        }

        // Also update the legacy assigned_pathway_id on enrollment for backward compatibility.
        $this->sync_enrollment_assigned_pathway($enrollment_id);

        if (class_exists('HL_Audit_Service')) {
            $enrollment = $wpdb->get_row($wpdb->prepare(
                "SELECT cohort_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d", $enrollment_id
            ));
            HL_Audit_Service::log(
                'pathway_assigned',
                get_current_user_id(),
                $enrollment ? $enrollment->cohort_id : null,
                null,
                $assignment_id,
                sprintf('Pathway #%d assigned to enrollment #%d (%s)', $pathway_id, $enrollment_id, $type)
            );
        }

        return $assignment_id;
    }

    /**
     * Unassign a pathway from an enrollment.
     *
     * @param int $enrollment_id
     * @param int $pathway_id
     * @return bool|WP_Error
     */
    public function unassign_pathway($enrollment_id, $pathway_id) {
        global $wpdb;

        $deleted = $wpdb->delete($wpdb->prefix . 'hl_pathway_assignment', array(
            'enrollment_id' => absint($enrollment_id),
            'pathway_id'    => absint($pathway_id),
        ));

        if ($deleted === false) {
            return new WP_Error('delete_failed', __('Failed to remove pathway assignment.', 'hl-core'));
        }

        // Sync legacy column.
        $this->sync_enrollment_assigned_pathway($enrollment_id);

        if (class_exists('HL_Audit_Service')) {
            $enrollment = $wpdb->get_row($wpdb->prepare(
                "SELECT cohort_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d", absint($enrollment_id)
            ));
            HL_Audit_Service::log(
                'pathway_unassigned',
                get_current_user_id(),
                $enrollment ? $enrollment->cohort_id : null,
                null,
                absint($enrollment_id),
                sprintf('Pathway #%d unassigned from enrollment #%d', $pathway_id, $enrollment_id)
            );
        }

        return true;
    }

    /**
     * Bulk assign a pathway to multiple enrollments.
     *
     * @param int   $pathway_id
     * @param int[] $enrollment_ids
     * @param string $type
     * @return array Results with 'assigned' and 'errors' counts.
     */
    public function bulk_assign($pathway_id, $enrollment_ids, $type = 'explicit') {
        $assigned = 0;
        $errors   = 0;

        foreach ($enrollment_ids as $eid) {
            $result = $this->assign_pathway(absint($eid), absint($pathway_id), $type);
            if (is_wp_error($result)) {
                $errors++;
            } else {
                $assigned++;
            }
        }

        return array('assigned' => $assigned, 'errors' => $errors);
    }

    /**
     * Bulk unassign a pathway from multiple enrollments.
     *
     * @param int   $pathway_id
     * @param int[] $enrollment_ids
     * @return array Results with 'removed' and 'errors' counts.
     */
    public function bulk_unassign($pathway_id, $enrollment_ids) {
        $removed = 0;
        $errors  = 0;

        foreach ($enrollment_ids as $eid) {
            $result = $this->unassign_pathway(absint($eid), absint($pathway_id));
            if (is_wp_error($result)) {
                $errors++;
            } else {
                $removed++;
            }
        }

        return array('removed' => $removed, 'errors' => $errors);
    }

    /**
     * Get all pathways assigned to an enrollment.
     *
     * Returns explicit assignments first. If none exist, falls back to
     * role-based matching using the pathway's target_roles vs enrollment roles.
     *
     * @param int $enrollment_id
     * @return array Array of pathway rows (ARRAY_A).
     */
    public function get_pathways_for_enrollment($enrollment_id) {
        global $wpdb;

        $enrollment_id = absint($enrollment_id);

        // Check for explicit/role_default assignments first.
        $assigned = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, pa.assignment_type
             FROM {$wpdb->prefix}hl_pathway_assignment pa
             JOIN {$wpdb->prefix}hl_pathway p ON pa.pathway_id = p.pathway_id
             WHERE pa.enrollment_id = %d AND p.active_status = 1
             ORDER BY pa.assignment_type ASC, p.pathway_name ASC",
            $enrollment_id
        ), ARRAY_A);

        if (!empty($assigned)) {
            return $assigned;
        }

        // Fallback: role-based matching.
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT cohort_id, roles FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $enrollment_id
        ));

        if (!$enrollment) {
            return array();
        }

        $roles = json_decode($enrollment->roles, true);
        if (!is_array($roles) || empty($roles)) {
            return array();
        }

        // Get all active pathways for this cohort.
        $pathways = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_pathway WHERE cohort_id = %d AND active_status = 1 ORDER BY pathway_name ASC",
            $enrollment->cohort_id
        ), ARRAY_A);

        $matched = array();
        foreach ($pathways as $pw) {
            $target_roles = json_decode($pw['target_roles'], true);
            if (!is_array($target_roles) || empty($target_roles)) {
                continue;
            }

            // Map enrollment roles to pathway target_roles format.
            $role_map = array(
                'teacher'          => 'Teacher',
                'mentor'           => 'Mentor',
                'center_leader'    => 'Center Leader',
                'district_leader'  => 'District Leader',
            );

            foreach ($roles as $role) {
                $mapped = isset($role_map[$role]) ? $role_map[$role] : ucfirst($role);
                if (in_array($mapped, $target_roles, true)) {
                    $pw['assignment_type'] = 'role_default';
                    $matched[] = $pw;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * Get all enrollments assigned to a specific pathway.
     *
     * @param int $pathway_id
     * @return array Array of assignment rows with enrollment and user data.
     */
    public function get_enrollments_for_pathway($pathway_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT pa.*, e.user_id, e.roles, e.center_id, e.status AS enrollment_status,
                    u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_pathway_assignment pa
             JOIN {$wpdb->prefix}hl_enrollment e ON pa.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE pa.pathway_id = %d
             ORDER BY u.display_name ASC",
            absint($pathway_id)
        ), ARRAY_A) ?: array();
    }

    /**
     * Get enrollments in a cohort NOT assigned to a specific pathway.
     *
     * @param int $pathway_id
     * @param int $cohort_id
     * @return array
     */
    public function get_unassigned_enrollments($pathway_id, $cohort_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, e.user_id, e.roles, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cohort_id = %d AND e.status = 'active'
               AND e.enrollment_id NOT IN (
                   SELECT enrollment_id FROM {$wpdb->prefix}hl_pathway_assignment WHERE pathway_id = %d
               )
             ORDER BY u.display_name ASC",
            absint($cohort_id), absint($pathway_id)
        ), ARRAY_A) ?: array();
    }

    /**
     * Sync role-based default assignments for a cohort.
     *
     * Creates role_default assignments for enrollments that have no explicit
     * assignments, based on pathway target_roles matching enrollment roles.
     *
     * @param int $cohort_id
     * @return array Results with 'created' count.
     */
    public function sync_role_defaults($cohort_id) {
        global $wpdb;

        $cohort_id = absint($cohort_id);
        $created = 0;

        // Get all active enrollments without any pathway assignments.
        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, e.roles
             FROM {$wpdb->prefix}hl_enrollment e
             WHERE e.cohort_id = %d AND e.status = 'active'
               AND e.enrollment_id NOT IN (
                   SELECT enrollment_id FROM {$wpdb->prefix}hl_pathway_assignment
               )",
            $cohort_id
        ), ARRAY_A);

        // Get all active pathways for this cohort.
        $pathways = $wpdb->get_results($wpdb->prepare(
            "SELECT pathway_id, target_roles FROM {$wpdb->prefix}hl_pathway WHERE cohort_id = %d AND active_status = 1",
            $cohort_id
        ), ARRAY_A);

        $role_map = array(
            'teacher'          => 'Teacher',
            'mentor'           => 'Mentor',
            'center_leader'    => 'Center Leader',
            'district_leader'  => 'District Leader',
        );

        foreach ($enrollments as $e) {
            $e_roles = json_decode($e['roles'], true);
            if (!is_array($e_roles)) continue;

            foreach ($pathways as $pw) {
                $target_roles = json_decode($pw['target_roles'], true);
                if (!is_array($target_roles)) continue;

                foreach ($e_roles as $role) {
                    $mapped = isset($role_map[$role]) ? $role_map[$role] : ucfirst($role);
                    if (in_array($mapped, $target_roles, true)) {
                        $result = $this->assign_pathway($e['enrollment_id'], $pw['pathway_id'], 'role_default');
                        if (!is_wp_error($result)) {
                            $created++;
                        }
                        break;
                    }
                }
            }
        }

        return array('created' => $created);
    }

    /**
     * Check if an enrollment has access to a specific pathway.
     *
     * @param int $enrollment_id
     * @param int $pathway_id
     * @return bool
     */
    public function enrollment_has_pathway($enrollment_id, $pathway_id) {
        $pathways = $this->get_pathways_for_enrollment($enrollment_id);
        foreach ($pathways as $pw) {
            if ((int) $pw['pathway_id'] === (int) $pathway_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sync the legacy assigned_pathway_id column on hl_enrollment.
     *
     * Sets it to the first explicitly assigned pathway, or the first role_default,
     * or NULL if no assignments exist.
     *
     * @param int $enrollment_id
     */
    private function sync_enrollment_assigned_pathway($enrollment_id) {
        global $wpdb;

        $first_pathway_id = $wpdb->get_var($wpdb->prepare(
            "SELECT pathway_id FROM {$wpdb->prefix}hl_pathway_assignment
             WHERE enrollment_id = %d
             ORDER BY FIELD(assignment_type, 'explicit', 'role_default'), assignment_id ASC
             LIMIT 1",
            absint($enrollment_id)
        ));

        $wpdb->update(
            $wpdb->prefix . 'hl_enrollment',
            array('assigned_pathway_id' => $first_pathway_id ? absint($first_pathway_id) : null),
            array('enrollment_id' => absint($enrollment_id))
        );
    }
}
