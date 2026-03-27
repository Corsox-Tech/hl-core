<?php
if (!defined('ABSPATH')) exit;

/**
 * Reflective Practice Session Service
 *
 * Manages RP sessions linking mentors and teachers, plus form submissions.
 *
 * @package HL_Core
 */
class HL_RP_Session_Service {

    const VALID_STATUSES    = array('pending', 'scheduled', 'attended', 'missed', 'cancelled');
    const TERMINAL_STATUSES = array('attended', 'missed', 'cancelled');

    /**
     * Create an RP session.
     *
     * @param array $data Keys: cycle_id, mentor_enrollment_id, teacher_enrollment_id, session_number, session_date, notes.
     * @return int|WP_Error rp_session_id on success.
     */
    public function create_session($data) {
        global $wpdb;

        if (empty($data['cycle_id'])) {
            return new WP_Error('missing_cycle', __('Cycle is required.', 'hl-core'));
        }
        if (empty($data['mentor_enrollment_id'])) {
            return new WP_Error('missing_mentor', __('Mentor enrollment is required.', 'hl-core'));
        }
        if (empty($data['teacher_enrollment_id'])) {
            return new WP_Error('missing_teacher', __('Teacher enrollment is required.', 'hl-core'));
        }

        $insert_data = array(
            'rp_session_uuid'      => wp_generate_uuid4(),
            'cycle_id'             => absint($data['cycle_id']),
            'mentor_enrollment_id' => absint($data['mentor_enrollment_id']),
            'teacher_enrollment_id' => absint($data['teacher_enrollment_id']),
            'session_number'       => !empty($data['session_number']) ? absint($data['session_number']) : 1,
            'status'               => 'pending',
            'session_date'         => !empty($data['session_date']) ? sanitize_text_field($data['session_date']) : null,
            'notes'                => !empty($data['notes']) ? wp_kses_post($data['notes']) : null,
            'created_at'           => current_time('mysql'),
            'updated_at'           => current_time('mysql'),
        );

        $result = $wpdb->insert($wpdb->prefix . 'hl_rp_session', $insert_data);

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to create RP session.', 'hl-core'));
        }

        $rp_session_id = $wpdb->insert_id;

        HL_Audit_Service::log('rp_session.created', array(
            'entity_type' => 'rp_session',
            'entity_id'   => $rp_session_id,
            'cycle_id'    => $insert_data['cycle_id'],
            'after_data'  => array(
                'cycle_id'              => $insert_data['cycle_id'],
                'mentor_enrollment_id'  => $insert_data['mentor_enrollment_id'],
                'teacher_enrollment_id' => $insert_data['teacher_enrollment_id'],
                'session_number'        => $insert_data['session_number'],
            ),
        ));

        return $rp_session_id;
    }

    /**
     * Get a single RP session by ID with joined names.
     *
     * @param int $rp_session_id
     * @return array|null
     */
    public function get_session($rp_session_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT rps.*,
                    u_mentor.display_name AS mentor_name,
                    u_teacher.display_name AS teacher_name,
                    cy.cycle_name
             FROM {$wpdb->prefix}hl_rp_session rps
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_mentor ON rps.mentor_enrollment_id = e_mentor.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e_mentor.user_id = u_mentor.ID
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_teacher ON rps.teacher_enrollment_id = e_teacher.enrollment_id
             LEFT JOIN {$wpdb->users} u_teacher ON e_teacher.user_id = u_teacher.ID
             LEFT JOIN {$wpdb->prefix}hl_cycle cy ON rps.cycle_id = cy.cycle_id
             WHERE rps.rp_session_id = %d",
            $rp_session_id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Get all RP sessions for a cycle.
     *
     * @param int $cycle_id
     * @return array
     */
    public function get_by_cycle($cycle_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT rps.*, u_mentor.display_name AS mentor_name, u_teacher.display_name AS teacher_name
             FROM {$wpdb->prefix}hl_rp_session rps
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_mentor ON rps.mentor_enrollment_id = e_mentor.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e_mentor.user_id = u_mentor.ID
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_teacher ON rps.teacher_enrollment_id = e_teacher.enrollment_id
             LEFT JOIN {$wpdb->users} u_teacher ON e_teacher.user_id = u_teacher.ID
             WHERE rps.cycle_id = %d
             ORDER BY rps.session_date DESC, rps.created_at DESC",
            $cycle_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get sessions where user is mentor.
     *
     * @param int $mentor_enrollment_id
     * @return array
     */
    public function get_by_mentor($mentor_enrollment_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT rps.*, u_teacher.display_name AS teacher_name
             FROM {$wpdb->prefix}hl_rp_session rps
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_teacher ON rps.teacher_enrollment_id = e_teacher.enrollment_id
             LEFT JOIN {$wpdb->users} u_teacher ON e_teacher.user_id = u_teacher.ID
             WHERE rps.mentor_enrollment_id = %d
             ORDER BY rps.session_date ASC",
            $mentor_enrollment_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get sessions where user is teacher.
     *
     * @param int $teacher_enrollment_id
     * @return array
     */
    public function get_by_teacher($teacher_enrollment_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT rps.*, u_mentor.display_name AS mentor_name
             FROM {$wpdb->prefix}hl_rp_session rps
             LEFT JOIN {$wpdb->prefix}hl_enrollment e_mentor ON rps.mentor_enrollment_id = e_mentor.enrollment_id
             LEFT JOIN {$wpdb->users} u_mentor ON e_mentor.user_id = u_mentor.ID
             WHERE rps.teacher_enrollment_id = %d
             ORDER BY rps.session_date ASC",
            $teacher_enrollment_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get teachers in the mentor's team(s).
     *
     * Join path: enrollment → hl_team_membership (find teams where enrollment is mentor)
     * → return non-mentor team members.
     *
     * @param int $mentor_enrollment_id
     * @return array
     */
    public function get_teachers_for_mentor($mentor_enrollment_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT e.enrollment_id, e.user_id, u.display_name, u.user_email
             FROM {$wpdb->prefix}hl_team_membership tm_mentor
             JOIN {$wpdb->prefix}hl_team_membership tm_teacher
                 ON tm_mentor.team_id = tm_teacher.team_id
                 AND tm_teacher.enrollment_id != %d
             JOIN {$wpdb->prefix}hl_enrollment e ON tm_teacher.enrollment_id = e.enrollment_id
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE tm_mentor.enrollment_id = %d
               AND e.status = 'active'
             ORDER BY u.display_name ASC",
            $mentor_enrollment_id, $mentor_enrollment_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Find the mentor enrollment for a given teacher via team membership.
     *
     * @param int $teacher_enrollment_id
     * @return int|null Mentor enrollment ID or null if not found.
     */
    public function get_mentor_for_teacher($teacher_enrollment_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT tm_mentor.enrollment_id
             FROM {$wpdb->prefix}hl_team_membership tm_teacher
             JOIN {$wpdb->prefix}hl_team_membership tm_mentor
                 ON tm_teacher.team_id = tm_mentor.team_id
                 AND tm_mentor.enrollment_id != %d
             JOIN {$wpdb->prefix}hl_enrollment e ON tm_mentor.enrollment_id = e.enrollment_id
             WHERE tm_teacher.enrollment_id = %d
               AND e.status = 'active'
               AND e.roles LIKE %s
             LIMIT 1",
            $teacher_enrollment_id, $teacher_enrollment_id, '%"mentor"%'
        ));
    }

    /**
     * Transition session status with validation.
     *
     * @param int    $rp_session_id
     * @param string $new_status
     * @return bool|WP_Error
     */
    public function transition_status($rp_session_id, $new_status) {
        global $wpdb;

        if (!in_array($new_status, self::VALID_STATUSES, true)) {
            return new WP_Error('invalid_status', __('Invalid session status.', 'hl-core'));
        }

        $session = $this->get_session($rp_session_id);
        if (!$session) {
            return new WP_Error('not_found', __('RP session not found.', 'hl-core'));
        }

        $current = $session['status'];

        if (in_array($current, self::TERMINAL_STATUSES, true)) {
            return new WP_Error('terminal_status', sprintf(
                __('Cannot change status from "%s" — it is a terminal state.', 'hl-core'),
                $current
            ));
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'hl_rp_session',
            array(
                'status'     => $new_status,
                'updated_at' => current_time('mysql'),
            ),
            array('rp_session_id' => $rp_session_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to update RP session status.', 'hl-core'));
        }

        HL_Audit_Service::log('rp_session.status_changed', array(
            'entity_type' => 'rp_session',
            'entity_id'   => $rp_session_id,
            'cycle_id'    => $session['cycle_id'],
            'before_data' => array('status' => $current),
            'after_data'  => array('status' => $new_status),
        ));

        return true;
    }

    /**
     * Save or submit an RP session form.
     * Upserts based on unique constraint (rp_session_id, role_in_session).
     *
     * @param int    $rp_session_id
     * @param int    $user_id
     * @param int    $instrument_id
     * @param string $role          'supervisor' or 'supervisee'
     * @param string $responses_json
     * @param string $status        'draft' or 'submitted'
     * @return int submission_id
     */
    public function submit_form($rp_session_id, $user_id, $instrument_id, $role, $responses_json, $status = 'draft') {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_rp_session_submission';

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT submission_id FROM {$table} WHERE rp_session_id = %d AND role_in_session = %s",
            $rp_session_id, $role
        ), ARRAY_A);

        $data = array(
            'rp_session_id'        => $rp_session_id,
            'submitted_by_user_id' => $user_id,
            'instrument_id'        => $instrument_id,
            'role_in_session'      => $role,
            'responses_json'       => $responses_json,
            'status'               => $status,
            'updated_at'           => current_time('mysql'),
        );

        if ($status === 'submitted') {
            $data['submitted_at'] = current_time('mysql');
        }

        if ($existing) {
            $wpdb->update($table, $data, array('submission_id' => $existing['submission_id']));
            return (int) $existing['submission_id'];
        }

        $data['submission_uuid'] = wp_generate_uuid4();
        $data['created_at']      = current_time('mysql');
        $wpdb->insert($table, $data);
        return (int) $wpdb->insert_id;
    }

    /**
     * Get all submissions for an RP session.
     *
     * @param int $rp_session_id
     * @return array
     */
    public function get_submissions($rp_session_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT sub.*, u.display_name AS submitted_by_name
             FROM {$wpdb->prefix}hl_rp_session_submission sub
             LEFT JOIN {$wpdb->users} u ON sub.submitted_by_user_id = u.ID
             WHERE sub.rp_session_id = %d
             ORDER BY sub.role_in_session ASC",
            $rp_session_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get previous action plan submissions for a teacher in a cycle.
     *
     * @param int $teacher_enrollment_id
     * @param int $cycle_id
     * @return array
     */
    public function get_previous_action_plans($teacher_enrollment_id, $cycle_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT sub.responses_json, sub.submitted_at, rps.session_date, rps.session_number
             FROM {$wpdb->prefix}hl_rp_session_submission sub
             JOIN {$wpdb->prefix}hl_rp_session rps ON sub.rp_session_id = rps.rp_session_id
             WHERE rps.teacher_enrollment_id = %d AND rps.cycle_id = %d
               AND sub.role_in_session = 'supervisee' AND sub.status = 'submitted'
             ORDER BY sub.submitted_at DESC",
            $teacher_enrollment_id, $cycle_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Update component state when an RP session form is submitted.
     *
     * Finds the component with type 'reflective_practice_session' and matching
     * session_number in external_ref, then marks it complete.
     *
     * @param int $enrollment_id
     * @param int $cycle_id
     * @param int $session_number
     */
    public function update_component_state($enrollment_id, $cycle_id, $session_number) {
        global $wpdb;

        // Find matching component in this enrollment's assigned pathway
        $component = $wpdb->get_row($wpdb->prepare(
            "SELECT c.component_id
             FROM {$wpdb->prefix}hl_component c
             JOIN {$wpdb->prefix}hl_pathway p ON c.pathway_id = p.pathway_id
             JOIN {$wpdb->prefix}hl_pathway_assignment pa ON p.pathway_id = pa.pathway_id
             WHERE p.cycle_id = %d
               AND pa.enrollment_id = %d
               AND c.component_type = 'reflective_practice_session'
               AND c.status = 'active'
               AND c.external_ref LIKE %s",
            $cycle_id,
            $enrollment_id,
            '%"session_number":' . intval($session_number) . '%'
        ));

        if (!$component) {
            return;
        }

        $now = current_time('mysql');

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT state_id FROM {$wpdb->prefix}hl_component_state
             WHERE enrollment_id = %d AND component_id = %d",
            $enrollment_id, $component->component_id
        ));

        $state_data = array(
            'completion_percent' => 100,
            'completion_status'  => 'complete',
            'completed_at'       => $now,
            'last_computed_at'   => $now,
        );

        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'hl_component_state',
                $state_data,
                array('state_id' => $existing)
            );
        } else {
            $state_data['enrollment_id'] = $enrollment_id;
            $state_data['component_id']  = $component->component_id;
            $wpdb->insert($wpdb->prefix . 'hl_component_state', $state_data);
        }

        do_action('hl_core_recompute_rollups', $enrollment_id);
    }

    // ─── Instrument Definitions ─────────────────────────────────────

    /**
     * Get the 6 domain definitions used across Action Plan, Classroom Visit,
     * and Self-Reflection instruments.
     *
     * @return array
     */
    public static function get_ecsel_domains() {
        return array(
            'emotional_climate' => array(
                'label'      => 'Emotional Climate & Teacher Presence',
                'skills'     => array(
                    'Demonstrate calm, emotionally regulated presence',
                    'Model attentive, engaged, and supportive behavior',
                ),
                'indicators' => array(
                    'Teacher demonstrates a calm, emotionally regulated presence',
                    'Teacher models attentive, engaged, and supportive behavior',
                    'Teacher creates a warm, welcoming classroom atmosphere',
                    'Teacher responds to children\'s emotions with empathy',
                    'Teacher maintains composure during challenging moments',
                    'Teacher uses a warm, encouraging tone of voice',
                ),
            ),
            'ecsel_language' => array(
                'label'      => 'ECSEL Language & Emotional Communication',
                'skills'     => array(
                    'Consistently use emotion language to label/validate feelings',
                    'Use Causal Talk (CT) to connect emotions, behavior, experiences',
                ),
                'indicators' => array(
                    'Teacher uses emotion language to label and validate feelings',
                    'Teacher uses Causal Talk (CT) to connect emotions, behavior, and experiences',
                    'Teacher encourages children to express emotions verbally',
                    'Teacher validates children\'s emotional experiences',
                ),
            ),
            'co_regulation' => array(
                'label'      => 'Co-Regulation & Emotional Support',
                'skills'     => array(
                    'Use Causal Talk in Emotional Experience (CTEE) for heightened emotions',
                    'Guide children toward regulation before problem-solving',
                ),
                'indicators' => array(
                    'Teacher uses CTEE strategies during heightened emotional moments',
                    'Teacher guides children toward regulation before problem-solving',
                    'Teacher provides physical comfort and proximity when appropriate',
                    'Teacher helps children identify and use calming strategies',
                ),
            ),
            'social_skills' => array(
                'label'      => 'Social Skills, Empathy & Inclusion',
                'skills'     => array(
                    'Model/encourage empathy, cooperation, respect',
                    'Classroom interactions reflect inclusion and respect',
                    'Guide children through conflict resolution steps',
                ),
                'indicators' => array(
                    'Teacher models and encourages empathy, cooperation, and respect',
                    'Classroom interactions reflect inclusion and respect for all children',
                    'Teacher guides children through conflict resolution steps',
                    'Teacher facilitates positive peer interactions',
                ),
            ),
            'ecsel_tools' => array(
                'label'      => 'Use of Developmentally-Appropriate ECSEL Tools',
                'skills'     => array(
                    'ECSEL tools visible, accessible, intentionally placed',
                    'Use tools appropriately for emotion knowledge/conflict resolution',
                ),
                'indicators' => array(
                    'ECSEL tools are visible, accessible, and intentionally placed',
                    'Teacher uses ECSEL tools appropriately for emotion knowledge',
                    'Teacher uses ECSEL tools for conflict resolution',
                    'Teacher introduces and references tools during daily activities',
                ),
            ),
            'daily_integration' => array(
                'label'      => 'Integration into Daily Learning',
                'skills'     => array(
                    'Embed tools, language, strategies in play/routines/learning',
                    'Use emotional moments as learning opportunities',
                ),
                'indicators' => array(
                    'Teacher embeds ECSEL tools, language, and strategies in play and routines',
                    'Teacher uses emotional moments as learning opportunities',
                    'Teacher integrates SEL into daily classroom activities',
                    'Teacher connects ECSEL practices to curriculum goals',
                ),
            ),
        );
    }

    /**
     * Get Action Plan instrument sections JSON array.
     *
     * @return array
     */
    public static function get_action_plan_sections() {
        $domains = self::get_ecsel_domains();

        $domain_options = array();
        $skills_by_domain = array();
        foreach ($domains as $key => $domain) {
            $domain_options[$key] = $domain['label'];
            $skills_by_domain[$key] = $domain['skills'];
        }

        return array(
            array(
                'key'    => 'planning',
                'title'  => 'Planning',
                'fields' => array(
                    array(
                        'key'     => 'domain',
                        'type'    => 'select',
                        'label'   => 'Domain',
                        'options' => $domain_options,
                    ),
                    array(
                        'key'               => 'skills',
                        'type'              => 'multiselect',
                        'label'             => 'Skills/Strategy',
                        'conditional_on'    => 'domain',
                        'options_by_domain' => $skills_by_domain,
                    ),
                    array('key' => 'how', 'type' => 'textarea', 'label' => 'Describe HOW you will practice the skill(s)'),
                    array('key' => 'what', 'type' => 'textarea', 'label' => 'WHAT behaviors will you track to know this is effective?'),
                ),
            ),
            array(
                'key'    => 'results',
                'title'  => 'Results',
                'fields' => array(
                    array('key' => 'practice_reflection', 'type' => 'textarea', 'label' => 'From your perspective, how has your practice gone?'),
                    array(
                        'key'   => 'success_degree',
                        'type'  => 'likert',
                        'label' => 'Degree of success',
                        'scale' => array(
                            1 => 'Not at all Successful',
                            2 => 'Slightly Successful',
                            3 => 'Moderately Successful',
                            4 => 'Very Successful',
                            5 => 'Extremely Successful',
                        ),
                    ),
                    array('key' => 'impact_observations', 'type' => 'textarea', 'label' => 'Observations of impact on students'),
                    array('key' => 'what_learned', 'type' => 'textarea', 'label' => 'What you learned'),
                    array('key' => 'still_wondering', 'type' => 'textarea', 'label' => 'What you\'re still wondering'),
                ),
            ),
        );
    }

    /**
     * Get RP Notes instrument sections JSON array.
     *
     * @return array
     */
    public static function get_rp_notes_sections() {
        return array(
            array(
                'key'    => 'personal_notes',
                'title'  => 'Personal Notes',
                'fields' => array(
                    array('key' => 'personal_notes', 'type' => 'richtext', 'label' => 'Personal Notes (not shared with supervisee)'),
                ),
            ),
            array(
                'key'    => 'session_notes',
                'title'  => 'RP Session Notes',
                'fields' => array(
                    array('key' => 'successes', 'type' => 'richtext', 'label' => 'Successes'),
                    array('key' => 'challenges', 'type' => 'richtext', 'label' => 'Challenges / Areas of Growth'),
                    array('key' => 'supports_needed', 'type' => 'richtext', 'label' => 'Supports Needed'),
                    array('key' => 'next_steps', 'type' => 'richtext', 'label' => 'Next Steps'),
                    array('key' => 'next_session_date', 'type' => 'date', 'label' => 'Next Session Date'),
                ),
            ),
        );
    }

    /**
     * Get Classroom Visit / Self-Reflection instrument sections JSON array.
     *
     * @param string $perspective 'observer' for Classroom Visit, 'self' for Self-Reflection
     * @return array
     */
    public static function get_visit_form_sections($perspective = 'observer') {
        $domains = self::get_ecsel_domains();
        $sections = array();

        $sections[] = array(
            'key'    => 'context',
            'title'  => 'Context',
            'fields' => array(
                array(
                    'key'     => 'context_activities',
                    'type'    => 'multiselect',
                    'label'   => 'Activities observed',
                    'options' => array(
                        'free_play'      => 'Free Play',
                        'formal_group'   => 'Formal Group Activities',
                        'transition'     => 'Transition',
                        'routine'        => 'Routine',
                    ),
                ),
            ),
        );

        foreach ($domains as $key => $domain) {
            $indicators = array();
            foreach ($domain['indicators'] as $indicator) {
                if ($perspective === 'self') {
                    $indicator = str_replace('Teacher ', 'I ', $indicator);
                    $indicator = str_replace('teacher ', 'I ', $indicator);
                    $indicator = preg_replace('/\bTeacher\'s\b/', 'My', $indicator);
                }
                $indicators[] = $indicator;
            }

            $sections[] = array(
                'key'        => $key,
                'title'      => $domain['label'],
                'type'       => 'indicator_checklist',
                'indicators' => $indicators,
            );
        }

        return $sections;
    }

    /**
     * Seed the 6 new instruments into the DB. Idempotent.
     *
     * Called by the ELCPB Y2 CLI setup command (Session 4).
     *
     * @return array Map of instrument_key => instrument_id
     */
    public static function seed_instruments() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_teacher_assessment_instrument';
        $ids   = array();

        $instruments = array(
            array(
                'instrument_name'    => 'Coaching RP Notes',
                'instrument_key'     => 'coaching_rp_notes',
                'instrument_version' => '1.0',
                'sections'           => wp_json_encode(self::get_rp_notes_sections()),
                'status'             => 'active',
            ),
            array(
                'instrument_name'    => 'Mentoring RP Notes',
                'instrument_key'     => 'mentoring_rp_notes',
                'instrument_version' => '1.0',
                'sections'           => wp_json_encode(self::get_rp_notes_sections()),
                'status'             => 'active',
            ),
            array(
                'instrument_name'    => 'Coaching Action Plan & Results',
                'instrument_key'     => 'coaching_action_plan',
                'instrument_version' => '1.0',
                'sections'           => wp_json_encode(self::get_action_plan_sections()),
                'status'             => 'active',
            ),
            array(
                'instrument_name'    => 'Mentoring Action Plan & Results',
                'instrument_key'     => 'mentoring_action_plan',
                'instrument_version' => '1.0',
                'sections'           => wp_json_encode(self::get_action_plan_sections()),
                'status'             => 'active',
            ),
            array(
                'instrument_name'    => 'Classroom Visit Form',
                'instrument_key'     => 'classroom_visit_form',
                'instrument_version' => '1.0',
                'sections'           => wp_json_encode(self::get_visit_form_sections('observer')),
                'status'             => 'active',
            ),
            array(
                'instrument_name'    => 'Self-Reflection Form',
                'instrument_key'     => 'self_reflection_form',
                'instrument_version' => '1.0',
                'sections'           => wp_json_encode(self::get_visit_form_sections('self')),
                'status'             => 'active',
            ),
        );

        foreach ($instruments as $inst) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT instrument_id FROM {$table} WHERE instrument_key = %s LIMIT 1",
                $inst['instrument_key']
            ));

            if ($existing) {
                $ids[$inst['instrument_key']] = (int) $existing;
                continue;
            }

            $inst['created_at'] = current_time('mysql');
            $wpdb->insert($table, $inst);
            $ids[$inst['instrument_key']] = (int) $wpdb->insert_id;
        }

        return $ids;
    }
}
