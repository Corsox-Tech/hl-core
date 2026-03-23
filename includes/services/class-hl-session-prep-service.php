<?php
if (!defined('ABSPATH')) exit;

/**
 * Session Prep Service
 *
 * Read-only helper for auto-populated sections in RP Notes forms
 * (both coaching and mentoring contexts). Uses eager-loading JOINs
 * to avoid N+1 queries.
 *
 * @package HL_Core
 */
class HL_Session_Prep_Service {

    /**
     * Get supervisee's pathway progress (components completed / total + current course).
     *
     * @param int $enrollment_id
     * @param int $cycle_id
     * @return array Keys: total_components, completed_components, current_course
     */
    public function get_supervisee_progress($enrollment_id, $cycle_id) {
        global $wpdb;

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(c.component_id) AS total_components,
                SUM(CASE WHEN cs.completion_status = 'complete' THEN 1 ELSE 0 END) AS completed_components,
                (SELECT c2.title FROM {$wpdb->prefix}hl_component c2
                 JOIN {$wpdb->prefix}hl_component_state cs2 ON c2.component_id = cs2.component_id AND cs2.enrollment_id = %d
                 WHERE c2.pathway_id = p.pathway_id AND c2.component_type = 'learndash_course' AND c2.status = 'active'
                 ORDER BY cs2.updated_at DESC LIMIT 1) AS current_course
             FROM {$wpdb->prefix}hl_component c
             JOIN {$wpdb->prefix}hl_pathway p ON c.pathway_id = p.pathway_id
             JOIN {$wpdb->prefix}hl_pathway_assignment pa ON p.pathway_id = pa.pathway_id AND pa.enrollment_id = %d
             LEFT JOIN {$wpdb->prefix}hl_component_state cs ON c.component_id = cs.component_id AND cs.enrollment_id = %d
             WHERE p.cycle_id = %d AND c.status = 'active'",
            $enrollment_id, $enrollment_id, $enrollment_id, $cycle_id
        ), ARRAY_A);

        return $result ?: array('total_components' => 0, 'completed_components' => 0, 'current_course' => null);
    }

    /**
     * Get previous action plan submissions for scrollable list.
     *
     * @param int    $enrollment_id
     * @param int    $cycle_id
     * @param string $context 'coaching' or 'mentoring'
     * @return array
     */
    public function get_previous_action_plans($enrollment_id, $cycle_id, $context = 'mentoring') {
        global $wpdb;

        if ($context === 'coaching') {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT sub.responses_json, sub.submitted_at, cs.session_datetime, cs.session_title
                 FROM {$wpdb->prefix}hl_coaching_session_submission sub
                 JOIN {$wpdb->prefix}hl_coaching_session cs ON sub.session_id = cs.session_id
                 WHERE cs.mentor_enrollment_id = %d AND cs.cycle_id = %d
                   AND sub.role_in_session = 'supervisee' AND sub.status = 'submitted'
                 ORDER BY sub.submitted_at DESC",
                $enrollment_id, $cycle_id
            ), ARRAY_A) ?: array();
        }

        // Default: mentoring context — query hl_rp_session_submission
        return $wpdb->get_results($wpdb->prepare(
            "SELECT sub.responses_json, sub.submitted_at, rps.session_date, rps.session_number
             FROM {$wpdb->prefix}hl_rp_session_submission sub
             JOIN {$wpdb->prefix}hl_rp_session rps ON sub.rp_session_id = rps.rp_session_id
             WHERE rps.teacher_enrollment_id = %d AND rps.cycle_id = %d
               AND sub.role_in_session = 'supervisee' AND sub.status = 'submitted'
             ORDER BY sub.submitted_at DESC",
            $enrollment_id, $cycle_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get the most recent classroom visit submissions (observer + self-reflector)
     * for a specific teacher in a cycle.
     *
     * @param int $teacher_enrollment_id
     * @param int $cycle_id
     * @return array|null Visit data with submissions, or null if none found.
     */
    public function get_classroom_visit_review($teacher_enrollment_id, $cycle_id) {
        global $wpdb;

        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT cv.classroom_visit_id, cv.visit_date, cv.visit_number, cv.status,
                    u_leader.display_name AS leader_name
             FROM {$wpdb->prefix}hl_classroom_visit cv
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_leader ON cv.leader_enrollment_id = e_leader.enrollment_id
             LEFT JOIN {$wpdb->users} u_leader ON e_leader.user_id = u_leader.ID
             WHERE cv.teacher_enrollment_id = %d AND cv.cycle_id = %d
             ORDER BY cv.visit_date DESC, cv.created_at DESC
             LIMIT 1",
            $teacher_enrollment_id, $cycle_id
        ), ARRAY_A);

        if (!$visit) {
            return null;
        }

        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT sub.role_in_visit, sub.responses_json, sub.submitted_at, sub.status,
                    u.display_name AS submitted_by_name
             FROM {$wpdb->prefix}hl_classroom_visit_submission sub
             LEFT JOIN {$wpdb->users} u ON sub.submitted_by_user_id = u.ID
             WHERE sub.classroom_visit_id = %d",
            $visit['classroom_visit_id']
        ), ARRAY_A) ?: array();

        $visit['submissions'] = $submissions;
        return $visit;
    }

    /**
     * Get classroom visit data for a mentor's most recent RP teacher.
     *
     * Single join path: hl_rp_session (most recent by session_date for this mentor)
     * → get teacher_enrollment_id → classroom visit + submissions for that teacher.
     *
     * @param int $mentor_enrollment_id
     * @param int $cycle_id
     * @return array|null
     */
    public function get_classroom_visit_for_mentor_context($mentor_enrollment_id, $cycle_id) {
        global $wpdb;

        // Find the most recent RP session teacher for this mentor
        $teacher_enrollment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT teacher_enrollment_id
             FROM {$wpdb->prefix}hl_rp_session
             WHERE mentor_enrollment_id = %d AND cycle_id = %d
             ORDER BY session_date DESC, created_at DESC
             LIMIT 1",
            $mentor_enrollment_id, $cycle_id
        ));

        if (!$teacher_enrollment_id) {
            return null;
        }

        return $this->get_classroom_visit_review((int) $teacher_enrollment_id, $cycle_id);
    }
}
