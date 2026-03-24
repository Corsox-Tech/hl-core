<?php
if (!defined('ABSPATH')) exit;

/**
 * Coach Dashboard Service
 *
 * Provides data queries for coach frontend pages: stats, mentor lists,
 * aggregated reporting, and availability management.
 *
 * @package HL_Core
 */
class HL_Coach_Dashboard_Service {

    /**
     * Get dashboard stats for a coach.
     *
     * @param int $coach_user_id
     * @return array {assigned_mentors: int, upcoming_sessions: int, sessions_this_month: int}
     */
    public function get_dashboard_stats($coach_user_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $today = current_time('Y-m-d');
        $now   = current_time('mysql');
        $month_start = date('Y-m-01', strtotime($today));
        $month_end   = date('Y-m-t', strtotime($today));

        $cycle_ids = $this->get_coach_cycle_ids($coach_user_id);
        if (empty($cycle_ids)) {
            return array('assigned_mentors' => 0, 'upcoming_sessions' => 0, 'sessions_this_month' => 0);
        }

        $assignment_service = new HL_Coach_Assignment_Service();
        $total_mentors = 0;
        foreach ($cycle_ids as $cid) {
            $roster = $assignment_service->get_coach_roster($coach_user_id, $cid);
            foreach ($roster as $r) {
                $roles = json_decode($r['roles'] ?? '[]', true);
                if (is_array($roles) && in_array('mentor', $roles, true)) {
                    $total_mentors++;
                }
            }
        }

        $upcoming = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}hl_coaching_session
             WHERE coach_user_id = %d AND session_status = 'scheduled' AND session_datetime >= %s",
            $coach_user_id, $now
        ));

        $this_month = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}hl_coaching_session
             WHERE coach_user_id = %d
               AND session_datetime >= %s AND session_datetime <= %s
               AND session_status != 'cancelled'",
            $coach_user_id, $month_start . ' 00:00:00', $month_end . ' 23:59:59'
        ));

        return array(
            'assigned_mentors'   => $total_mentors,
            'upcoming_sessions'  => $upcoming,
            'sessions_this_month' => $this_month,
        );
    }

    /**
     * Get mentors assigned to a coach with enriched data for the mentor cards.
     */
    public function get_mentors_for_coach($coach_user_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $now = current_time('mysql');

        $cycle_ids = $this->get_coach_cycle_ids($coach_user_id);
        if (empty($cycle_ids)) {
            return array();
        }

        $assignment_service = new HL_Coach_Assignment_Service();
        $mentors = array();

        foreach ($cycle_ids as $cid) {
            $roster = $assignment_service->get_coach_roster($coach_user_id, $cid);
            foreach ($roster as $r) {
                $roles = json_decode($r['roles'] ?? '[]', true);
                if (!is_array($roles) || !in_array('mentor', $roles, true)) {
                    continue;
                }

                $eid = (int) $r['enrollment_id'];

                $school_name = '—';
                if (!empty($r['school_id'])) {
                    $school_name = $wpdb->get_var($wpdb->prepare(
                        "SELECT orgunit_name FROM {$prefix}hl_orgunit WHERE orgunit_id = %d",
                        $r['school_id']
                    )) ?: '—';
                }

                $team_name = $wpdb->get_var($wpdb->prepare(
                    "SELECT t.team_name FROM {$prefix}hl_team_membership tm
                     JOIN {$prefix}hl_team t ON tm.team_id = t.team_id
                     WHERE tm.enrollment_id = %d AND t.cycle_id = %d LIMIT 1",
                    $eid, $cid
                )) ?: '—';

                $pathway_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT p.pathway_name,
                            ROUND(IFNULL(
                                (SELECT COUNT(*) FROM {$prefix}hl_component_state cs
                                 JOIN {$prefix}hl_component c ON cs.component_id = c.component_id
                                 WHERE cs.enrollment_id = %d AND c.pathway_id = pa.pathway_id AND cs.completion_status = 'complete')
                                * 100.0 /
                                NULLIF((SELECT COUNT(*) FROM {$prefix}hl_component c2 WHERE c2.pathway_id = pa.pathway_id AND c2.status = 'active'), 0)
                            , 0)) AS completion_pct
                     FROM {$prefix}hl_pathway_assignment pa
                     JOIN {$prefix}hl_pathway p ON pa.pathway_id = p.pathway_id
                     WHERE pa.enrollment_id = %d LIMIT 1",
                    $eid, $eid
                ), ARRAY_A);

                $last_session = $wpdb->get_var($wpdb->prepare(
                    "SELECT MAX(session_datetime) FROM {$prefix}hl_coaching_session
                     WHERE mentor_enrollment_id = %d AND session_status = 'attended'",
                    $eid
                ));

                $next_session = $wpdb->get_var($wpdb->prepare(
                    "SELECT MIN(session_datetime) FROM {$prefix}hl_coaching_session
                     WHERE mentor_enrollment_id = %d AND session_status = 'scheduled' AND session_datetime >= %s",
                    $eid, $now
                ));

                $mentors[] = array(
                    'enrollment_id'   => $eid,
                    'user_id'         => (int) $r['user_id'],
                    'display_name'    => $r['display_name'],
                    'user_email'      => $r['user_email'] ?? '',
                    'school_name'     => $school_name,
                    'team_name'       => $team_name,
                    'pathway_name'    => $pathway_data['pathway_name'] ?? '—',
                    'completion_pct'  => (int) ($pathway_data['completion_pct'] ?? 0),
                    'last_session'    => $last_session,
                    'next_session'    => $next_session,
                    'cycle_id'        => $cid,
                );
            }
        }

        return $mentors;
    }

    /**
     * Get full mentor detail data for the Mentor Detail page.
     */
    public function get_mentor_detail($mentor_enrollment_id, $coach_user_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, u.display_name, u.user_email,
                    o.orgunit_name AS school_name
             FROM {$prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$prefix}hl_orgunit o ON e.school_id = o.orgunit_id
             WHERE e.enrollment_id = %d AND e.status = 'active'",
            $mentor_enrollment_id
        ), ARRAY_A);

        if (!$enrollment) {
            return null;
        }

        $coach_service = new HL_Coach_Assignment_Service();
        $resolved = $coach_service->get_coach_for_enrollment($mentor_enrollment_id, (int) $enrollment['cycle_id']);
        if (!$resolved || (int) $resolved['coach_user_id'] !== $coach_user_id) {
            return null;
        }

        $eid = $mentor_enrollment_id;
        $cid = (int) $enrollment['cycle_id'];

        $team_name = $wpdb->get_var($wpdb->prepare(
            "SELECT t.team_name FROM {$prefix}hl_team_membership tm
             JOIN {$prefix}hl_team t ON tm.team_id = t.team_id
             WHERE tm.enrollment_id = %d AND t.cycle_id = %d LIMIT 1",
            $eid, $cid
        )) ?: '—';

        $pathway = $wpdb->get_row($wpdb->prepare(
            "SELECT p.pathway_id, p.pathway_name FROM {$prefix}hl_pathway_assignment pa
             JOIN {$prefix}hl_pathway p ON pa.pathway_id = p.pathway_id
             WHERE pa.enrollment_id = %d LIMIT 1",
            $eid
        ), ARRAY_A);

        $completion_pct = 0;
        if ($pathway) {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}hl_component WHERE pathway_id = %d AND status = 'active'",
                $pathway['pathway_id']
            ));
            $done = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}hl_component_state cs
                 JOIN {$prefix}hl_component c ON cs.component_id = c.component_id
                 WHERE cs.enrollment_id = %d AND c.pathway_id = %d AND cs.completion_status = 'complete'",
                $eid, $pathway['pathway_id']
            ));
            $completion_pct = $total > 0 ? round($done * 100 / $total) : 0;
        }

        return array_merge($enrollment, array(
            'team_name'      => $team_name,
            'pathway_name'   => $pathway['pathway_name'] ?? '—',
            'pathway_id'     => $pathway['pathway_id'] ?? 0,
            'completion_pct' => $completion_pct,
        ));
    }

    /**
     * Get team members for a mentor's team.
     */
    public function get_team_members($mentor_enrollment_id, $cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $team_id = $wpdb->get_var($wpdb->prepare(
            "SELECT tm.team_id FROM {$prefix}hl_team_membership tm
             JOIN {$prefix}hl_team t ON tm.team_id = t.team_id
             WHERE tm.enrollment_id = %d AND t.cycle_id = %d LIMIT 1",
            $mentor_enrollment_id, $cycle_id
        ));

        if (!$team_id) {
            return array();
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, u.display_name, u.user_email, e.roles,
                    pa.pathway_id,
                    p.pathway_name,
                    ROUND(IFNULL(
                        (SELECT COUNT(*) FROM {$prefix}hl_component_state cs
                         JOIN {$prefix}hl_component c ON cs.component_id = c.component_id
                         WHERE cs.enrollment_id = e.enrollment_id AND c.pathway_id = pa.pathway_id AND cs.completion_status = 'complete')
                        * 100.0 /
                        NULLIF((SELECT COUNT(*) FROM {$prefix}hl_component c2 WHERE c2.pathway_id = pa.pathway_id AND c2.status = 'active'), 0)
                    , 0)) AS completion_pct
             FROM {$prefix}hl_team_membership tm
             JOIN {$prefix}hl_enrollment e ON tm.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$prefix}hl_pathway_assignment pa ON pa.enrollment_id = e.enrollment_id
             LEFT JOIN {$prefix}hl_pathway p ON pa.pathway_id = p.pathway_id
             WHERE tm.team_id = %d AND e.status = 'active'
             ORDER BY u.display_name ASC",
            $team_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get RP sessions for a mentor.
     */
    public function get_mentor_rp_sessions($mentor_enrollment_id, $cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT rs.*, mentor.display_name AS mentor_name, teacher.display_name AS teacher_name
             FROM {$prefix}hl_rp_session rs
             LEFT JOIN {$prefix}hl_enrollment me ON rs.mentor_enrollment_id = me.enrollment_id
             LEFT JOIN {$wpdb->users} mentor ON me.user_id = mentor.ID
             LEFT JOIN {$prefix}hl_enrollment te ON rs.teacher_enrollment_id = te.enrollment_id
             LEFT JOIN {$wpdb->users} teacher ON te.user_id = teacher.ID
             WHERE rs.mentor_enrollment_id = %d AND rs.cycle_id = %d
             ORDER BY rs.session_date DESC",
            $mentor_enrollment_id, $cycle_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get aggregated report data across all mentors for a coach.
     */
    public function get_aggregated_report($coach_user_id, $filters = array()) {
        $mentors = $this->get_mentors_for_coach($coach_user_id);

        if (!empty($filters['cycle_id'])) {
            $mentors = array_filter($mentors, function ($m) use ($filters) {
                return (int) $m['cycle_id'] === (int) $filters['cycle_id'];
            });
        }
        if (!empty($filters['school_name'])) {
            $mentors = array_filter($mentors, function ($m) use ($filters) {
                return $m['school_name'] === $filters['school_name'];
            });
        }

        return array_values($mentors);
    }

    /**
     * Get active cycle IDs where this coach has assignments.
     */
    public function get_coach_cycle_ids($coach_user_id) {
        global $wpdb;
        $today = current_time('Y-m-d');

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT cycle_id FROM {$wpdb->prefix}hl_coach_assignment
             WHERE coach_user_id = %d
               AND effective_from <= %s
               AND (effective_to IS NULL OR effective_to >= %s)",
            $coach_user_id, $today, $today
        ));

        return array_map('absint', $ids);
    }

    /**
     * Get distinct school names from a coach's mentor roster (for filters).
     */
    public function get_coach_school_options($coach_user_id) {
        $mentors = $this->get_mentors_for_coach($coach_user_id);
        $schools = array();
        foreach ($mentors as $m) {
            if ($m['school_name'] !== '—') {
                $schools[$m['school_name']] = true;
            }
        }
        ksort($schools);
        return array_keys($schools);
    }

    // =========================================================================
    // Availability CRUD
    // =========================================================================

    /**
     * Get availability blocks for a coach.
     */
    public function get_availability($coach_user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_coach_availability
             WHERE coach_user_id = %d ORDER BY day_of_week ASC, start_time ASC",
            $coach_user_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Save availability blocks (replace all for this coach).
     */
    public function save_availability($coach_user_id, $blocks) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_coach_availability';

        $wpdb->delete($table, array('coach_user_id' => $coach_user_id));

        foreach ($blocks as $block) {
            $wpdb->insert($table, array(
                'coach_user_id' => absint($coach_user_id),
                'day_of_week'   => absint($block['day_of_week']),
                'start_time'    => sanitize_text_field($block['start_time']),
                'end_time'      => sanitize_text_field($block['end_time']),
            ));
        }

        return true;
    }
}
