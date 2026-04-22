<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Automation Service
 *
 * Orchestrates the email automation engine: listens for hook-based
 * triggers, polls for cron-based triggers, evaluates conditions,
 * resolves recipients, and enqueues emails via the queue processor.
 *
 * Cron dedup contract (A.1.6, A.7.6): dedup tokens have NO date component.
 * Each (trigger, workflow, user, entity, cycle) tuple fires exactly once per
 * window. Range matches (`complete_by BETWEEN today AND today+7`) tolerate
 * missed cron runs — if wp-cron skips a day, the next run still catches the
 * same enrollment because its date still falls in the range. Downstream:
 * the hl_email_queue.dedup_token unique-ish index (enforced in PHP via
 * enqueue()'s dedup_token guard) suppresses duplicates.
 *
 * Workflows created mid-window fire on the next cron run for all users whose
 * window is currently in range; users whose window already closed are NOT
 * retroactively notified.
 *
 * Timezone contract: all window queries use current_time('Y-m-d') (WP site TZ).
 * WP-Cron irregularity means edge-of-window enrollments may fire up to 24h
 * before/after the exact calendar boundary. Sub-day precision needs a
 * dedicated hourly trigger type.
 *
 * @package HL_Core
 */
class HL_Email_Automation_Service {

    /** Max rows per cron trigger query. Sizing: 200 enrollments x 25 components = 5000. */
    const CRON_QUERY_ROW_CAP = 5000;

    /** @var self|null */
    private static $instance = null;

    /** @return self */
    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_hook_listeners();

        // Email v2 Task 18: cron staleness monitoring (Site Health + admin notice).
        add_filter( 'site_status_tests', array( $this, 'register_site_health_test' ) );
        add_action( 'admin_notices',      array( $this, 'maybe_render_cron_staleness_notice' ) );
    }

    // =========================================================================
    // Hook Registration
    // =========================================================================

    /**
     * Register WordPress action listeners for all hook-based triggers.
     */
    private function register_hook_listeners() {
        $hooks = array(
            'user_register',
            'hl_enrollment_created',
            'hl_pathway_assigned',
            'hl_learndash_course_completed',
            'hl_pathway_completed',
            'hl_coaching_session_created',
            'hl_coaching_session_status_changed',
            'hl_rp_session_created',
            'hl_rp_session_status_changed',
            'hl_classroom_visit_submitted',
            'hl_teacher_assessment_submitted',
            'hl_child_assessment_submitted',
            'hl_coach_assigned',
        );

        foreach ( $hooks as $hook ) {
            add_action( $hook, function () use ( $hook ) {
                $args = func_get_args();
                $this->handle_trigger( $hook, $args );
            }, 20, 10 );
        }
    }

    // =========================================================================
    // Trigger Handler
    // =========================================================================

    /**
     * Handle a triggered event: load workflows, evaluate, resolve, enqueue.
     *
     * @param string $trigger_key WordPress hook name.
     * @param array  $args        Arguments passed to the hook.
     */
    public function handle_trigger( $trigger_key, array $args = array() ) {
        global $wpdb;

        // A.2.26 — Only active workflows fire; deleted/paused rows excluded by status filter.
        $workflows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_email_workflow
             WHERE trigger_key = %s AND status = 'active'",
            $trigger_key
        ) );

        if ( empty( $workflows ) ) {
            return;
        }

        // Build context from hook arguments.
        $context = $this->build_hook_context( $trigger_key, $args );
        if ( empty( $context ) ) {
            return;
        }

        // Hydrate context with DB data.
        $context = $this->hydrate_context( $context );

        $evaluator      = HL_Email_Condition_Evaluator::instance();
        $resolver        = HL_Email_Recipient_Resolver::instance();
        $renderer        = HL_Email_Block_Renderer::instance();
        $merge_registry  = HL_Email_Merge_Tag_Registry::instance();
        $queue_processor = HL_Email_Queue_Processor::instance();

        foreach ( $workflows as $workflow ) {
            // Decode conditions and recipients (enforce defaults for longtext dbDelta limitation).
            $conditions = json_decode( $workflow->conditions, true );
            if ( ! is_array( $conditions ) ) {
                $conditions = array();
            }
            $recipient_config = json_decode( $workflow->recipients, true );
            if ( ! is_array( $recipient_config ) ) {
                $recipient_config = array( 'primary' => array(), 'cc' => array() );
            }

            // Evaluate conditions.
            if ( ! $evaluator->evaluate( $conditions, $context ) ) {
                continue;
            }

            // Resolve recipients.
            $recipients = $resolver->resolve( $recipient_config, $context );
            if ( empty( $recipients ) ) {
                continue;
            }

            // Load template.
            $template = null;
            if ( $workflow->template_id ) {
                $template = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}hl_email_template WHERE template_id = %d AND status = 'active'",
                    $workflow->template_id
                ) );
            }
            if ( ! $template ) {
                continue;
            }

            $blocks = json_decode( $template->blocks_json, true );
            if ( ! is_array( $blocks ) ) {
                $blocks = array();
            }

            // Compute scheduled_at.
            $scheduled_at = $this->compute_scheduled_at( $workflow );

            // Fan out: one queue row per recipient.
            foreach ( $recipients as $recipient ) {
                // Fix 1C: safe null check — get_userdata() returns false for deleted users.
                $recipient_name = '';
                if ( ! empty( $recipient['user_id'] ) ) {
                    $recipient_user = get_userdata( $recipient['user_id'] );
                    $recipient_name = $recipient_user ? $recipient_user->display_name : '';
                }

                // Build per-recipient context for merge tag resolution.
                $recipient_context = array_merge( $context, array(
                    'recipient_user_id' => $recipient['user_id'],
                    'recipient_email'   => $recipient['email'],
                    'recipient_name'    => $recipient_name,
                ) );

                // Resolve merge tags.
                $merge_tags = $merge_registry->resolve_all( $recipient_context );

                // Render body HTML.
                $body_html = $renderer->render( $blocks, $template->subject, $merge_tags );

                // Resolve subject merge tags.
                $subject = $template->subject;
                foreach ( $merge_tags as $tag_key => $tag_value ) {
                    $subject = str_replace( '{{' . $tag_key . '}}', $tag_value, $subject );
                }

                // Build dedup token for hook-based triggers.
                // Includes cycle_id so cross-cycle triggers are independent.
                $entity_id   = $context['entity_id'] ?? 0;
                $dedup_token = md5(
                    $trigger_key . '_' . $workflow->workflow_id . '_'
                    . ( $recipient['user_id'] ?? 0 ) . '_' . $entity_id
                    . '_' . ( $context['cycle_id'] ?? 0 )
                );

                // Build context_data snapshot.
                $context_data = array(
                    'trigger_key'   => $trigger_key,
                    'cycle_id'      => $context['cycle_id'] ?? null,
                    'enrollment_id' => $context['enrollment_id'] ?? null,
                    'entity_id'     => $entity_id,
                    'entity_type'   => $context['entity_type'] ?? null,
                    'workflow_id'   => (int) $workflow->workflow_id,
                    'template_key'  => $template->template_key,
                    'user_id'       => $context['user_id'] ?? null,
                );

                $queue_processor->enqueue( array(
                    'workflow_id'       => (int) $workflow->workflow_id,
                    'template_id'       => (int) $template->template_id,
                    'recipient_user_id' => $recipient['user_id'],
                    'recipient_email'   => $recipient['email'],
                    'subject'           => $subject,
                    'body_html'         => $body_html,
                    'context_data'      => $context_data,
                    'dedup_token'       => $dedup_token,
                    'scheduled_at'      => $scheduled_at,
                ) );
            }
        }
    }

    // =========================================================================
    // Context Building
    // =========================================================================

    /**
     * Build initial context from hook arguments.
     *
     * @param string $trigger_key Hook name.
     * @param array  $args        Hook arguments.
     * @return array Context array or empty if unusable.
     */
    private function build_hook_context( $trigger_key, array $args ) {
        $context = array(
            'trigger_key' => $trigger_key,
        );

        switch ( $trigger_key ) {
            case 'user_register':
                $context['user_id']     = $args[0] ?? null;
                $context['entity_id']   = $args[0] ?? null;
                $context['entity_type'] = 'user';
                break;

            case 'hl_enrollment_created':
                $enrollment_id          = $args[0] ?? null;
                $context['entity_id']   = $enrollment_id;
                $context['entity_type'] = 'enrollment';
                $context['enrollment_id'] = $enrollment_id;
                if ( $enrollment_id ) {
                    $context = $this->load_enrollment_context( $enrollment_id, $context );
                }
                break;

            case 'hl_pathway_assigned':
                // Emitter: class-hl-pathway-assignment-service.php:71
                //   do_action('hl_pathway_assigned', $enrollment_id, $pathway_id)
                // 2 args, not 3. user_id is derived from the enrollment row.
                $enrollment_id          = $args[0] ?? null;
                $pathway_id             = $args[1] ?? null;
                $context['entity_id']   = $enrollment_id;
                $context['entity_type'] = 'enrollment';
                $context['pathway_id']  = $pathway_id;
                if ( $enrollment_id ) {
                    $context = $this->load_enrollment_context( $enrollment_id, $context );
                }
                break;

            case 'hl_learndash_course_completed':
                $user_id                = $args[0] ?? null;
                $course_id              = $args[1] ?? null;
                $context['user_id']     = $user_id;
                $context['entity_id']   = $course_id;
                $context['entity_type'] = 'course';
                $context['course_id']   = $course_id;
                break;

            case 'hl_pathway_completed':
                // Emitter: class-hl-reporting-service.php:157
                //   do_action('hl_pathway_completed', $enrollment_id, $pathway_id, $cycle_id)
                // Args are (enrollment_id, pathway_id, cycle_id), NOT the
                // (user_id, pathway_id, enrollment_id) the listener previously assumed.
                $enrollment_id          = $args[0] ?? null;
                $pathway_id             = $args[1] ?? null;
                $cycle_id               = $args[2] ?? null;
                $context['entity_id']   = $enrollment_id;
                $context['entity_type'] = 'enrollment';
                $context['pathway_id']  = $pathway_id;
                if ( $cycle_id ) {
                    $context['cycle_id'] = (int) $cycle_id;
                }
                if ( $enrollment_id ) {
                    $context = $this->load_enrollment_context( $enrollment_id, $context );
                }
                break;

            case 'hl_coaching_session_created':
                $session_id             = $args[0] ?? null;
                $context['entity_id']   = $session_id;
                $context['entity_type'] = 'coaching_session';
                if ( $session_id ) {
                    $context = $this->load_coaching_session_context( $session_id, $context );
                }
                break;

            case 'hl_coaching_session_status_changed':
                // Emitter: class-hl-coaching-service.php:202
                //   do_action('hl_coaching_session_status_changed',
                //       $session_id, $current, $new_status, $session)
                // $args[1] is the OLD status (pre-update), $args[2] is NEW.
                // Previous code read them backwards, causing row 22
                // ("Coaching No-Show Follow-Up", prod workflow #33) to fire
                // on transitions FROM missed instead of TO missed.
                $session_id             = $args[0] ?? null;
                $old_status             = $args[1] ?? null;
                $new_status             = $args[2] ?? null;
                $context['entity_id']   = $session_id;
                $context['entity_type'] = 'coaching_session';
                $context['session']     = array(
                    'new_status' => $new_status,
                    'old_status' => $old_status,
                );
                if ( $session_id ) {
                    $context = $this->load_coaching_session_context( $session_id, $context );
                }
                break;

            case 'hl_rp_session_created':
                // Emitter: class-hl-rp-session-service.php:68
                //   do_action('hl_rp_session_created', $rp_session_id, $insert_data)
                // $args[1] is the insert-data ARRAY, not a status. The previous
                // combined case mistakenly set session.new_status = that array.
                $rp_session_id          = $args[0] ?? null;
                $context['entity_id']   = $rp_session_id;
                $context['entity_type'] = 'rp_session';
                if ( $rp_session_id ) {
                    $context = $this->load_rp_session_context( (int) $rp_session_id, $context );
                }
                break;

            case 'hl_rp_session_status_changed':
                // Emitter: class-hl-rp-session-service.php:262
                //   do_action('hl_rp_session_status_changed',
                //       $rp_session_id, $current, $new_status, $session)
                // Same layout as coaching-session-status-changed (Bug 4):
                // $args[1] = OLD, $args[2] = NEW. Previous combined case
                // read $args[1] as new_status — also backwards.
                $rp_session_id          = $args[0] ?? null;
                $old_status             = $args[1] ?? null;
                $new_status             = $args[2] ?? null;
                $context['entity_id']   = $rp_session_id;
                $context['entity_type'] = 'rp_session';
                $context['session']     = array(
                    'new_status' => $new_status,
                    'old_status' => $old_status,
                );
                if ( $rp_session_id ) {
                    $context = $this->load_rp_session_context( (int) $rp_session_id, $context );
                }
                break;

            case 'hl_classroom_visit_submitted':
                // Emitter: class-hl-classroom-visit-service.php:330
                //   do_action('hl_classroom_visit_submitted',
                //       $submission_id, $classroom_visit_id, $role, $user_id)
                // Fires for BOTH leader submissions (observation) and teacher
                // submissions (self-reflection); workflows use visit.role to
                // distinguish. Previously this case treated $args[0] as the
                // classroom_visit_id — it is actually the submission_id.
                $submission_id          = $args[0] ?? null;
                $classroom_visit_id     = $args[1] ?? null;
                $role                   = $args[2] ?? null;
                $submitter_user_id      = $args[3] ?? null;
                $context['entity_id']   = $classroom_visit_id;
                $context['entity_type'] = 'classroom_visit';
                if ( $submitter_user_id ) {
                    $context['user_id'] = (int) $submitter_user_id;
                }
                $context['visit'] = array(
                    'role'          => $role,
                    'submission_id' => $submission_id,
                );
                if ( $classroom_visit_id ) {
                    $context = $this->load_classroom_visit_context( (int) $classroom_visit_id, $context );
                }
                break;

            case 'hl_teacher_assessment_submitted':
                $instance_id            = $args[0] ?? null;
                $context['entity_id']   = $instance_id;
                $context['entity_type'] = 'teacher_assessment';
                if ( $instance_id ) {
                    $context = $this->load_assessment_context( $instance_id, 'teacher', $context );
                }
                break;

            case 'hl_child_assessment_submitted':
                $instance_id            = $args[0] ?? null;
                $context['entity_id']   = $instance_id;
                $context['entity_type'] = 'child_assessment';
                break;

            case 'hl_coach_assigned':
                // Emitter: class-hl-coach-assignment-service.php:68
                //   do_action('hl_coach_assigned', $assignment_id, $insert)
                // $insert = coach_user_id, scope_type ('school'|'team'|'enrollment'),
                //           scope_id, cycle_id, effective_from, effective_to.
                // Previously this case set ONLY entity_id/entity_type —
                // user_id, cycle_id, and coach data were all missing, so row 9
                // ("Coach Assigned") never resolved recipients and silently
                // enqueued nothing.
                $assignment_id          = $args[0] ?? null;
                $data                   = is_array( $args[1] ?? null ) ? $args[1] : array();
                $context['entity_id']   = $assignment_id;
                $context['entity_type'] = 'coach_assignment';
                if ( ! empty( $data['coach_user_id'] ) ) {
                    $context['coach_user_id'] = (int) $data['coach_user_id'];
                    $coach = get_userdata( (int) $data['coach_user_id'] );
                    if ( $coach ) {
                        $context['coach_name']  = $coach->display_name;
                        $context['coach_email'] = $coach->user_email;
                    }
                }
                if ( ! empty( $data['cycle_id'] ) ) {
                    $context['cycle_id'] = (int) $data['cycle_id'];
                }
                $context['coach_assignment'] = array(
                    'scope_type' => $data['scope_type'] ?? '',
                    'scope_id'   => isset( $data['scope_id'] ) ? (int) $data['scope_id'] : 0,
                );
                // For enrollment-scoped assignments, the "mentor being assigned
                // a coach" is the user on that enrollment — resolve them as the
                // primary recipient.
                //
                // TODO: team/school scopes currently leave user_id unset. The
                // recipient resolver's role:mentor token SHOULD fan out to all
                // mentors in the scope, but that path has not been end-to-end
                // verified against hl_coach_assigned — no workflow seeded for
                // this trigger today. When a team/school-scoped workflow is
                // authored, verify the resolver emits one queue row per mentor,
                // or fan-out here by enumerating enrollments in scope and
                // calling load_enrollment_context per-recipient.
                if ( ( $data['scope_type'] ?? '' ) === 'enrollment' && ! empty( $data['scope_id'] ) ) {
                    $context = $this->load_enrollment_context( (int) $data['scope_id'], $context );
                }
                break;

            default:
                return array();
        }

        return $context;
    }

    /**
     * Load enrollment data into context.
     */
    private function load_enrollment_context( $enrollment_id, array $context ) {
        global $wpdb;
        $enrollment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $enrollment_id
        ) );
        if ( ! $enrollment ) {
            return $context;
        }
        $context['user_id']       = (int) $enrollment->user_id;
        $context['cycle_id']      = (int) $enrollment->cycle_id;
        $context['enrollment_id'] = (int) $enrollment->enrollment_id;
        $context['enrollment']    = array(
            'role'   => $enrollment->roles ?? '',
            'status' => $enrollment->status ?? '',
        );
        return $context;
    }

    /**
     * Load coaching session data into context.
     *
     * Called from two paths:
     *   - Hook handlers (hl_coaching_session_created / _status_changed): caller
     *     sets only entity_id/entity_type first; this method populates user_id,
     *     cycle_id, enrollment_id from the session row.
     *   - hydrate_context() cron branch (cron:session_upcoming, action_plan_24h,
     *     session_notes_24h): caller already set user_id and enrollment_id from
     *     the cron query (which for session_notes_24h deliberately routes to the
     *     COACH, not the mentor — user_id = coach_user_id there). The !isset
     *     guards below preserve those caller-set values.
     */
    private function load_coaching_session_context( $session_id, array $context ) {
        global $wpdb;
        // Schema note: hl_coaching_session stores mentor_enrollment_id, NOT
        // mentor_user_id. Prior versions of this loader read $session->mentor_user_id
        // directly — that column does not exist, so user_id silently became 0 and
        // hook-triggered coaching emails (rows 21/22) never resolved a mentor
        // recipient. The JOIN below resolves the mentor's user_id via hl_enrollment,
        // aliased as mentor_user_id so the rest of the method (and @since-callers)
        // keep working.
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*,
                    mentor_e.user_id AS mentor_user_id
             FROM {$wpdb->prefix}hl_coaching_session s
             LEFT JOIN {$wpdb->prefix}hl_enrollment mentor_e
                    ON mentor_e.enrollment_id = s.mentor_enrollment_id
             WHERE s.session_id = %d",
            $session_id
        ) );
        if ( ! $session ) {
            return $context;
        }
        if ( empty( $context['user_id'] ) && ! empty( $session->mentor_user_id ) ) {
            $context['user_id'] = (int) $session->mentor_user_id;
        }
        if ( empty( $context['cycle_id'] ) ) {
            $context['cycle_id'] = (int) $session->cycle_id;
        }
        if ( ! array_key_exists( 'enrollment_id', $context ) ) {
            // mentor_enrollment_id is the canonical enrollment link; the earlier
            // code read a non-existent `enrollment_id` column and always set null.
            $context['enrollment_id'] = $session->mentor_enrollment_id ? (int) $session->mentor_enrollment_id : null;
        }
        if ( ! empty( $session->component_id ) ) {
            $context['component_id'] = (int) $session->component_id;
        }
        $context['meeting_url']   = $session->meeting_url ?? '';
        $context['zoom_link']     = $session->meeting_url ?? '';

        // Format session date in recipient's timezone (mentor by default).
        if ( ! empty( $session->session_datetime ) ) {
            $recipient_tz = $session->mentor_timezone ?: ( $session->coach_timezone ?: wp_timezone_string() );
            $auto_fmt = HL_Timezone_Helper::format_session_time( $session->session_datetime, $recipient_tz );
            $context['session_date'] = $auto_fmt['full'] ?: $session->session_datetime;
        }

        // Load coach data.
        if ( ! empty( $session->coach_user_id ) ) {
            $coach = get_userdata( (int) $session->coach_user_id );
            if ( $coach ) {
                $context['coach_name']  = $coach->display_name;
                $context['coach_email'] = $coach->user_email;
            }
        }

        // Load mentor data (now that $session->mentor_user_id is populated via the JOIN above).
        if ( ! empty( $session->mentor_user_id ) ) {
            $mentor = get_userdata( (int) $session->mentor_user_id );
            if ( $mentor ) {
                $context['mentor_name'] = $mentor->display_name;
            }
        }

        return $context;
    }

    /**
     * Load RP (Reflective Practice) session data into context.
     *
     * The hl_rp_session table stores participants as enrollment IDs
     * (mentor_enrollment_id, teacher_enrollment_id). This loader joins
     * hl_enrollment to resolve user_ids for the recipient resolver.
     *
     * The mentor is the primary recipient for RP notifications
     * (spreadsheet rows 17/18); the teacher is exposed via
     * `observed_teacher_user_id` for CC / alternate-recipient tokens.
     *
     * @trigger-keys hl_rp_session_created, hl_rp_session_status_changed
     *
     * @param int   $rp_session_id
     * @param array $context Initial context (may carry session.new_status / old_status).
     * @return array
     */
    private function load_rp_session_context( $rp_session_id, array $context ) {
        global $wpdb;
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*,
                    mentor_e.user_id AS mentor_user_id,
                    teacher_e.user_id AS teacher_user_id
             FROM {$wpdb->prefix}hl_rp_session s
             LEFT JOIN {$wpdb->prefix}hl_enrollment mentor_e
                    ON mentor_e.enrollment_id = s.mentor_enrollment_id
             LEFT JOIN {$wpdb->prefix}hl_enrollment teacher_e
                    ON teacher_e.enrollment_id = s.teacher_enrollment_id
             WHERE s.rp_session_id = %d",
            $rp_session_id
        ) );
        if ( ! $session ) {
            return $context;
        }
        if ( empty( $context['user_id'] ) && ! empty( $session->mentor_user_id ) ) {
            $context['user_id'] = (int) $session->mentor_user_id;
        }
        if ( empty( $context['enrollment_id'] ) && ! empty( $session->mentor_enrollment_id ) ) {
            $context['enrollment_id'] = (int) $session->mentor_enrollment_id;
        }
        if ( empty( $context['cycle_id'] ) ) {
            $context['cycle_id'] = (int) $session->cycle_id;
        }
        if ( ! empty( $session->teacher_user_id ) ) {
            $context['observed_teacher_user_id'] = (int) $session->teacher_user_id;
            $context['cc_teacher_user_id']       = (int) $session->teacher_user_id;
        }
        if ( ! empty( $session->mentor_user_id ) ) {
            $mentor = get_userdata( (int) $session->mentor_user_id );
            if ( $mentor ) {
                $context['mentor_name'] = $mentor->display_name;
            }
        }
        // Merge; preserve session.new_status / old_status set by the hook case.
        $context['session'] = array_merge( $context['session'] ?? array(), array(
            'session_number'        => (int) $session->session_number,
            'status'                => $session->status ?? '',
            'session_date'          => $session->session_date,
            'mentor_enrollment_id'  => (int) $session->mentor_enrollment_id,
            'teacher_enrollment_id' => (int) $session->teacher_enrollment_id,
        ) );
        return $context;
    }

    /**
     * Load classroom visit data into context.
     *
     * The hl_classroom_visit table stores participants as enrollment IDs
     * (leader_enrollment_id, teacher_enrollment_id). This loader joins
     * hl_enrollment to resolve user_ids for the recipient resolver.
     *
     * Sets `observed_teacher_user_id` (canonical post-rename key) and
     * mirrors to `cc_teacher_user_id` (legacy alias) — both are read by
     * HL_Email_Recipient_Resolver::resolve_observed_teacher().
     *
     * @param int   $classroom_visit_id
     * @param array $context Initial context (may carry visit.role from the hook).
     * @return array
     */
    private function load_classroom_visit_context( $classroom_visit_id, array $context ) {
        global $wpdb;
        $visit = $wpdb->get_row( $wpdb->prepare(
            "SELECT v.*,
                    leader_e.user_id AS leader_user_id,
                    teacher_e.user_id AS teacher_user_id
             FROM {$wpdb->prefix}hl_classroom_visit v
             LEFT JOIN {$wpdb->prefix}hl_enrollment leader_e
                    ON leader_e.enrollment_id = v.leader_enrollment_id
             LEFT JOIN {$wpdb->prefix}hl_enrollment teacher_e
                    ON teacher_e.enrollment_id = v.teacher_enrollment_id
             WHERE v.classroom_visit_id = %d",
            $classroom_visit_id
        ) );
        if ( ! $visit ) {
            return $context;
        }
        $context['cycle_id'] = (int) $visit->cycle_id;
        if ( ! empty( $visit->teacher_user_id ) ) {
            $context['observed_teacher_user_id'] = (int) $visit->teacher_user_id;
            // Legacy alias — HL_Email_Recipient_Resolver::resolve_observed_teacher
            // falls back to this key. Keep it populated until the alias is retired.
            $context['cc_teacher_user_id']       = (int) $visit->teacher_user_id;
        }
        if ( ! empty( $visit->leader_user_id ) ) {
            $context['leader_user_id'] = (int) $visit->leader_user_id;
        }
        $context['visit'] = array_merge( $context['visit'] ?? array(), array(
            'visit_number'          => (int) $visit->visit_number,
            'status'                => $visit->status ?? '',
            'leader_enrollment_id'  => (int) $visit->leader_enrollment_id,
            'teacher_enrollment_id' => (int) $visit->teacher_enrollment_id,
        ) );
        return $context;
    }

    /**
     * Load teacher assessment data into context.
     */
    private function load_assessment_context( $instance_id, $type, array $context ) {
        global $wpdb;
        $table = "{$wpdb->prefix}hl_teacher_assessment_instance";
        $instance = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE instance_id = %d",
            $instance_id
        ) );
        if ( ! $instance ) {
            return $context;
        }
        $context['user_id']           = (int) $instance->user_id;
        $context['cycle_id']          = (int) $instance->cycle_id;
        $context['assessment_phase']  = $instance->phase ?? '';
        return $context;
    }

    /**
     * Hydrate context with full DB data for condition evaluation and merge tags.
     *
     * @param array $context Initial context.
     * @return array Enriched context.
     */
    private function hydrate_context( array $context ) {
        global $wpdb;

        // Load cycle data.
        if ( ! empty( $context['cycle_id'] ) && ! isset( $context['cycle'] ) ) {
            $cycle = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
                $context['cycle_id']
            ) );
            if ( $cycle ) {
                $context['cycle_name'] = $cycle->cycle_name;
                $context['cycle']      = array(
                    'cycle_type'       => $cycle->cycle_type ?? '',
                    'is_control_group' => (bool) ( $cycle->is_control_group ?? false ),
                    'status'           => $cycle->status ?? '',
                );

                // Load partnership.
                if ( ! empty( $cycle->partnership_id ) ) {
                    $partnership = $wpdb->get_row( $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}hl_partnership WHERE partnership_id = %d",
                        $cycle->partnership_id
                    ) );
                    if ( $partnership ) {
                        $context['partnership_name'] = $partnership->partnership_name;
                    }
                }
            }
        }

        // Load user account activation status.
        if ( ! empty( $context['user_id'] ) ) {
            $activated = get_user_meta( (int) $context['user_id'], 'hl_account_activated', true );
            $context['user'] = array(
                'account_activated' => $activated ?: null,
            );
        }

        // Load enrollment data if we have enrollment_id but not enrollment.
        if ( ! empty( $context['enrollment_id'] ) && ! isset( $context['enrollment'] ) ) {
            $context = $this->load_enrollment_context( $context['enrollment_id'], $context );
        }

        // Load school data from enrollment.
        if ( ! empty( $context['enrollment_id'] ) ) {
            $enrollment = $wpdb->get_row( $wpdb->prepare(
                "SELECT school_id, roles FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
                $context['enrollment_id']
            ) );
            if ( $enrollment && $enrollment->school_id ) {
                $school = $wpdb->get_row( $wpdb->prepare(
                    "SELECT o.*, p.name AS parent_name FROM {$wpdb->prefix}hl_orgunit o
                     LEFT JOIN {$wpdb->prefix}hl_orgunit p ON p.orgunit_id = o.parent_orgunit_id
                     WHERE o.orgunit_id = %d",
                    $enrollment->school_id
                ) );
                if ( $school ) {
                    $context['school_name']    = $school->name;
                    $context['school_district'] = $school->parent_name ?? '';
                }
                $context['enrollment_role'] = $enrollment->roles ?? '';
            }
        }

        // Load pathway data.
        if ( ! empty( $context['pathway_id'] ) && ! isset( $context['pathway_name'] ) ) {
            $pathway = $wpdb->get_row( $wpdb->prepare(
                "SELECT pathway_name FROM {$wpdb->prefix}hl_pathway WHERE pathway_id = %d",
                $context['pathway_id']
            ) );
            if ( $pathway ) {
                $context['pathway_name'] = $pathway->pathway_name;
            }
        }

        // A.2.28 — Track 3's assigned_mentor resolver requires $context['cycle_id'].
        // Backfill from enrollment_id when earlier sub-loaders didn't populate it
        // (e.g. hl_pathway_assigned, hl_learndash_course_completed, hl_child_assessment_submitted,
        // hl_coach_assigned — none of these call load_enrollment_context).
        if ( empty( $context['cycle_id'] ) && ! empty( $context['enrollment_id'] ) ) {
            $cycle_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT cycle_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
                (int) $context['enrollment_id']
            ) );
            if ( $cycle_id > 0 ) {
                $context['cycle_id'] = $cycle_id;
            }
        }

        // Propagate component_id from entity context (cron triggers).
        if ( ! empty( $context['entity_type'] ) && $context['entity_type'] === 'component' && ! empty( $context['entity_id'] ) ) {
            $context['component_id'] = (int) $context['entity_id'];
        } elseif ( ! empty( $context['entity_type'] ) && $context['entity_type'] === 'coaching_session' && ! empty( $context['entity_id'] ) ) {
            // cron:session_upcoming / cron:action_plan_24h / cron:session_notes_24h
            // return entity_type=coaching_session, entity_id=session_id. Load the
            // full session context (session_date, coach_name/email, mentor_name,
            // zoom_link) — same data the hook path gets via build_hook_context().
            // load_coaching_session_context has guards that preserve the caller's
            // user_id and enrollment_id (session_notes_24h routes to the COACH,
            // whose user_id is NOT the mentor — must not be overwritten).
            $context = $this->load_coaching_session_context( (int) $context['entity_id'], $context );
        }

        // Lazy hydration: coaching.session_status (component-scoped enum).
        if ( ! empty( $context['_needs_coaching_check'] ) && ! empty( $context['enrollment_id'] ) ) {
            $session_status = 'not_scheduled';

            if ( ! empty( $context['component_id'] ) ) {
                $status = $wpdb->get_var( $wpdb->prepare(
                    "SELECT session_status FROM {$wpdb->prefix}hl_coaching_session
                     WHERE mentor_enrollment_id = %d AND component_id = %d
                     ORDER BY created_at DESC LIMIT 1",
                    $context['enrollment_id'],
                    $context['component_id']
                ) );
                if ( $status ) {
                    $session_status = $status;
                }
            } elseif ( ! empty( $context['cycle_id'] ) ) {
                // Fallback for triggers without component_id (e.g., enrollment-level hooks).
                $status = $wpdb->get_var( $wpdb->prepare(
                    "SELECT session_status FROM {$wpdb->prefix}hl_coaching_session
                     WHERE mentor_enrollment_id = %d AND cycle_id = %d
                     ORDER BY created_at DESC LIMIT 1",
                    $context['enrollment_id'],
                    $context['cycle_id']
                ) );
                if ( $status ) {
                    $session_status = $status;
                }
            }

            $context['coaching'] = array_merge(
                $context['coaching'] ?? array(),
                array( 'session_status' => $session_status )
            );
        }

        return $context;
    }

    /**
     * Pre-load the cycle + partnership context fragment once per cycle.
     *
     * Returns an array that, when merged into a per-user context BEFORE
     * hydrate_context() runs, short-circuits the cycle/partnership DB
     * queries via the existing `if (!isset($context['cycle']))` guard.
     *
     * @param int $cycle_id Cycle ID.
     * @return array Partial context array with cycle + partnership keys.
     */
    private function load_cycle_context_fragment( $cycle_id ) {
        global $wpdb;

        $fragment = array();

        $cycle = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
            $cycle_id
        ) );

        if ( $cycle ) {
            $fragment['cycle_name'] = $cycle->cycle_name;
            $fragment['cycle']      = array(
                'cycle_type'       => $cycle->cycle_type ?? '',
                'is_control_group' => (bool) ( $cycle->is_control_group ?? false ),
                'status'           => $cycle->status ?? '',
            );

            if ( ! empty( $cycle->partnership_id ) ) {
                $partnership = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}hl_partnership WHERE partnership_id = %d",
                    $cycle->partnership_id
                ) );
                if ( $partnership ) {
                    $fragment['partnership_name'] = $partnership->partnership_name;
                }
            }
        }

        return $fragment;
    }

    // =========================================================================
    // Scheduling
    // =========================================================================

    /**
     * Compute the scheduled_at datetime for a workflow.
     *
     * @param object $workflow Workflow row.
     * @return string UTC datetime string.
     */
    public function compute_scheduled_at( $workflow ) {
        $now = new DateTime( 'now', new DateTimeZone( 'UTC' ) );

        // Apply delay.
        $delay = (int) $workflow->delay_minutes;
        if ( $delay > 0 ) {
            $now->modify( "+{$delay} minutes" );
        }

        // Apply send window.
        if ( ! empty( $workflow->send_window_start ) && ! empty( $workflow->send_window_end ) ) {
            $now = $this->apply_send_window( $now, $workflow );
        }

        return $now->format( 'Y-m-d H:i:s' );
    }

    /**
     * Adjust a datetime to fit within a send window.
     *
     * @param DateTime $dt       Current scheduled datetime (UTC).
     * @param object   $workflow Workflow with send_window_* fields.
     * @return DateTime Adjusted datetime.
     */
    private function apply_send_window( DateTime $dt, $workflow ) {
        $et_tz = new DateTimeZone( 'America/New_York' );

        // Convert to ET for comparison.
        $dt_et = clone $dt;
        $dt_et->setTimezone( $et_tz );

        $today_str = $dt_et->format( 'Y-m-d' );

        try {
            $window_start = new DateTime( $today_str . ' ' . $workflow->send_window_start, $et_tz );
            $window_end   = new DateTime( $today_str . ' ' . $workflow->send_window_end, $et_tz );
        } catch ( Exception $e ) {
            return $dt; // Invalid window times — send immediately.
        }

        // DST validation: if window_start_utc >= window_end_utc after conversion, skip window.
        $start_utc = clone $window_start;
        $start_utc->setTimezone( new DateTimeZone( 'UTC' ) );
        $end_utc = clone $window_end;
        $end_utc->setTimezone( new DateTimeZone( 'UTC' ) );
        if ( $start_utc >= $end_utc ) {
            return $dt; // DST gap makes window invalid — send immediately.
        }

        // Check allowed days.
        $allowed_days = array();
        if ( ! empty( $workflow->send_window_days ) ) {
            $allowed_days = array_map( 'trim', explode( ',', strtolower( $workflow->send_window_days ) ) );
        }

        // If within window and on an allowed day, return as-is.
        $current_day = strtolower( substr( $dt_et->format( 'D' ), 0, 3 ) );
        if ( $dt_et >= $window_start && $dt_et <= $window_end ) {
            if ( empty( $allowed_days ) || in_array( $current_day, $allowed_days, true ) ) {
                return $dt;
            }
        }

        // Push to the next valid window opening.
        $max_iterations = 14; // Don't loop forever.
        for ( $i = 0; $i < $max_iterations; $i++ ) {
            // If we're past today's window, advance to tomorrow.
            if ( $dt_et > $window_end || $i > 0 ) {
                $dt_et->modify( '+1 day' );
                $today_str    = $dt_et->format( 'Y-m-d' );
                $window_start = new DateTime( $today_str . ' ' . $workflow->send_window_start, $et_tz );
                $window_end   = new DateTime( $today_str . ' ' . $workflow->send_window_end, $et_tz );
            }

            $day = strtolower( substr( $dt_et->format( 'D' ), 0, 3 ) );
            if ( empty( $allowed_days ) || in_array( $day, $allowed_days, true ) ) {
                // Found a valid day — set time to window start.
                $result = clone $window_start;
                $result->setTimezone( new DateTimeZone( 'UTC' ) );
                return $result;
            }
        }

        return $dt; // Fallback: send at the computed time.
    }

    // =========================================================================
    // Cron Handlers
    // =========================================================================

    /**
     * Run daily cron checks for time-based triggers.
     * Called by the hl_email_cron_daily action.
     */
    public function run_daily_checks() {
        global $wpdb;

        // Load all active cycles within date bounds.
        $today_daily = wp_date( 'Y-m-d' );
        $cycles = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_cycle
             WHERE status = 'active'
               AND start_date <= %s
               AND (end_date IS NULL OR end_date >= %s)",
            $today_daily,
            $today_daily
        ) );
        if ( empty( $cycles ) ) {
            return;
        }

        // Load all daily cron workflows.
        $daily_triggers = array(
            // New generic keys:
            'cron:component_upcoming',
            'cron:component_overdue',
            // Note: cron:session_upcoming is intentionally NOT here — it runs hourly
            // because session reminders (e.g., 1h before) need sub-day precision.
            // See $hourly_triggers in run_hourly_checks().
            // Retained non-offset keys:
            'cron:coaching_pre_end',
            'cron:action_plan_24h',
            'cron:session_notes_24h',
            'cron:low_engagement_14d',
            'cron:client_success',
            // Legacy aliases (kept until next release for in-flight workflows):
            'cron:cv_window_7d',
            'cron:cv_overdue_1d',
            'cron:rp_window_7d',
            'cron:coaching_window_7d',
            'cron:coaching_session_5d',
        );

        $placeholders = implode( ',', array_fill( 0, count( $daily_triggers ), '%s' ) );
        // A.2.26 — Only active workflows fire; deleted/paused rows excluded by status filter.
        $workflows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_email_workflow
             WHERE trigger_key IN ({$placeholders}) AND status = 'active'",
            ...$daily_triggers
        ) );

        if ( ! empty( $workflows ) ) {
            foreach ( $workflows as $workflow ) {
                $this->run_cron_workflow( $workflow, $cycles );
            }
        }

        // Email v2: sweep stale builder drafts — runs even when there are
        // no active email workflows so abandoned drafts don't accumulate.
        $this->cleanup_stale_drafts();

        // Email v2 Task 18: record successful run timestamp for staleness
        // monitoring — runs even when there are no active workflows so the
        // Site Health staleness check doesn't fire a false alarm.
        update_option( 'hl_email_last_cron_run_at', gmdate( 'c' ), false );
    }

    /**
     * Run hourly cron checks for session reminders.
     * Called by the hl_email_cron_hourly action.
     */
    public function run_hourly_checks() {
        global $wpdb;

        $today_hourly = wp_date( 'Y-m-d' );
        $cycles = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_cycle
             WHERE status = 'active'
               AND start_date <= %s
               AND (end_date IS NULL OR end_date >= %s)",
            $today_hourly,
            $today_hourly
        ) );
        if ( empty( $cycles ) ) {
            return;
        }

        $hourly_triggers = array(
            'cron:session_upcoming',
            // Legacy aliases (kept until next release):
            'cron:session_24h',
            'cron:session_1h',
        );

        $placeholders = implode( ',', array_fill( 0, count( $hourly_triggers ), '%s' ) );
        // A.2.26 — Only active workflows fire; deleted/paused rows excluded by status filter.
        $workflows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_email_workflow
             WHERE trigger_key IN ({$placeholders}) AND status = 'active'",
            ...$hourly_triggers
        ) );

        if ( empty( $workflows ) ) {
            return;
        }

        foreach ( $workflows as $workflow ) {
            $this->run_cron_workflow( $workflow, $cycles );
        }
    }

    /**
     * Execute a cron-based workflow by polling the DB for qualifying users.
     *
     * @param object $workflow Workflow row.
     * @param array  $cycles   Active cycles.
     */
    private function run_cron_workflow( $workflow, array $cycles ) {
        global $wpdb;

        $trigger_key = $workflow->trigger_key;

        // --- Fix 1B: hoist template load out of user loop (one query per workflow). ---
        $template = null;
        if ( $workflow->template_id ) {
            $template = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_email_template WHERE template_id = %d AND status = 'active'",
                $workflow->template_id
            ) );
        }
        if ( ! $template ) {
            return; // No template — skip entire workflow.
        }

        $blocks = json_decode( $template->blocks_json, true );
        if ( ! is_array( $blocks ) ) {
            $blocks = array();
        }

        // Decode conditions and recipients once per workflow (immutable across cycles/users).
        $conditions = json_decode( $workflow->conditions, true );
        if ( ! is_array( $conditions ) ) {
            $conditions = array();
        }
        $recipient_config = json_decode( $workflow->recipients, true );
        if ( ! is_array( $recipient_config ) ) {
            $recipient_config = array( 'primary' => array(), 'cc' => array() );
        }

        // Detect whether any condition references coaching.session_status (lazy hydration flag).
        $needs_coaching_check = false;
        foreach ( $conditions as $cond ) {
            if ( isset( $cond['field'] ) && strpos( $cond['field'], 'coaching.session_status' ) === 0 ) {
                $needs_coaching_check = true;
                break;
            }
        }

        // Singleton instances — once per workflow.
        $evaluator       = HL_Email_Condition_Evaluator::instance();
        $resolver        = HL_Email_Recipient_Resolver::instance();
        $renderer        = HL_Email_Block_Renderer::instance();
        $merge_registry  = HL_Email_Merge_Tag_Registry::instance();
        $queue_processor = HL_Email_Queue_Processor::instance();
        $scheduled_at    = $this->compute_scheduled_at( $workflow );

        foreach ( $cycles as $cycle ) {
            $users = $this->get_cron_trigger_users( $trigger_key, $cycle, $workflow );
            if ( empty( $users ) ) {
                continue;
            }

            // --- Fix 1A: hoist cycle + partnership query out of user loop. ---
            $cycle_context_fragment = $this->load_cycle_context_fragment( (int) $cycle->cycle_id );

            foreach ( $users as $user_data ) {
                // Explicit null cast: cron queries sometimes return string "NULL"
                // for `SELECT NULL AS enrollment_id` under certain PDO/driver
                // configurations (MySQLi returns PHP null, PDO can return the
                // literal string). Force PHP-null so the array_key_exists guard
                // in load_coaching_session_context treats this as "caller
                // deliberately set null, don't overwrite." This matters for the
                // session_notes_24h cron path which intentionally routes to the
                // COACH, whose enrollment_id is null.
                $cron_enrollment_id = $user_data['enrollment_id'] ?? null;
                if ( $cron_enrollment_id === '' || ( is_string( $cron_enrollment_id ) && strtoupper( $cron_enrollment_id ) === 'NULL' ) ) {
                    $cron_enrollment_id = null;
                } elseif ( $cron_enrollment_id !== null ) {
                    $cron_enrollment_id = (int) $cron_enrollment_id;
                }
                $context = array_merge( $cycle_context_fragment, array(
                    'trigger_key'          => $trigger_key,
                    'user_id'              => $user_data['user_id'],
                    'cycle_id'             => (int) $cycle->cycle_id,
                    'enrollment_id'        => $cron_enrollment_id,
                    'entity_id'            => $user_data['entity_id'] ?? null,
                    'entity_type'          => $user_data['entity_type'] ?? null,
                    '_needs_coaching_check' => $needs_coaching_check,
                ) );
                $context = $this->hydrate_context( $context );

                // Evaluate conditions.
                if ( ! $evaluator->evaluate( $conditions, $context ) ) {
                    continue;
                }

                // Resolve recipients.
                $recipients = $resolver->resolve( $recipient_config, $context );
                if ( empty( $recipients ) ) {
                    continue;
                }

                foreach ( $recipients as $recipient ) {
                    // --- Fix 1C: safe null check for deleted users. ---
                    $recipient_name = '';
                    if ( ! empty( $recipient['user_id'] ) ) {
                        $recipient_user = get_userdata( $recipient['user_id'] );
                        $recipient_name = $recipient_user ? $recipient_user->display_name : '';
                    }

                    $recipient_context = array_merge( $context, array(
                        'recipient_user_id' => $recipient['user_id'],
                        'recipient_email'   => $recipient['email'],
                        'recipient_name'    => $recipient_name,
                    ) );

                    $merge_tags = $merge_registry->resolve_all( $recipient_context );
                    $body_html  = $renderer->render( $blocks, $template->subject, $merge_tags );

                    $subject = $template->subject;
                    foreach ( $merge_tags as $tag_key => $tag_value ) {
                        $subject = str_replace( '{{' . $tag_key . '}}', $tag_value, $subject );
                    }

                    // A.1.6: no date component — one reminder per (trigger, workflow, user, entity, cycle).
                    $dedup_token = md5(
                        $trigger_key . '|'
                        . $workflow->workflow_id . '|'
                        . ( $recipient['user_id'] ?? 0 ) . '|'
                        . ( $context['entity_id'] ?? 0 ) . '|'
                        . ( $context['cycle_id'] ?? 0 )
                    );

                    $context_data = array(
                        'trigger_key'   => $trigger_key,
                        'cycle_id'      => $context['cycle_id'] ?? null,
                        'enrollment_id' => $context['enrollment_id'] ?? null,
                        'entity_id'     => $context['entity_id'] ?? null,
                        'entity_type'   => $context['entity_type'] ?? null,
                        'workflow_id'   => (int) $workflow->workflow_id,
                        'template_key'  => $template->template_key,
                        'user_id'       => $context['user_id'] ?? null,
                    );

                    $queue_processor->enqueue( array(
                        'workflow_id'       => (int) $workflow->workflow_id,
                        'template_id'       => (int) $template->template_id,
                        'recipient_user_id' => $recipient['user_id'],
                        'recipient_email'   => $recipient['email'],
                        'subject'           => $subject,
                        'body_html'         => $body_html,
                        'context_data'      => $context_data,
                        'dedup_token'       => $dedup_token,
                        'scheduled_at'      => $scheduled_at,
                    ) );
                }
            }
        }
    }

    /**
     * Return the date column used as a trigger anchor for a given trigger type.
     *
     * Coaching-session components use display_window_start (opening) or
     * display_window_end (overdue). All other components use complete_by.
     *
     * @param string $trigger_type  'window' or 'overdue'.
     * @param string $component_type Component type string.
     * @return array { column: string, table_alias: string }
     */
    private function get_date_anchor( $trigger_type, $component_type = '' ) {
        if ( $component_type === 'coaching_session_attendance' ) {
            return $trigger_type === 'overdue'
                ? array( 'column' => 'display_window_end', 'table_alias' => 'c' )
                : array( 'column' => 'display_window_start', 'table_alias' => 'c' );
        }
        return array( 'column' => 'complete_by', 'table_alias' => 'c' );
    }

    /**
     * NOT EXISTS subquery for component-type-specific completion check.
     *
     * Used by cron:component_upcoming and cron:component_overdue to exclude
     * users who have already completed the component.
     *
     * $trigger_type scopes classroom_visit completion:
     *   - 'upcoming': cycle-scoped — any submitted CV in the cycle suppresses
     *     the reminder. Fires the "window opens" nudge once per cycle.
     *   - 'overdue': per-component — only the CV matching this component's
     *     visit_number is checked. An unsubmitted CV #3 still fires even if
     *     CV #1 has been submitted. Required for ELCPB-Y2 pathways where
     *     mentors have multiple CV components (visit_number 1..N) per cycle.
     *
     * Per-component match uses the same external_ref LIKE pattern as
     * HL_Classroom_Visit_Service::update_component_state() at lines 400-404:
     * hl_classroom_visit.visit_number is a tinyint unsigned, safe to embed
     * unquoted in a CONCAT literal.
     *
     * @param string $component_type Component type slug.
     * @param object $wpdb           Global wpdb instance.
     * @param string $trigger_type   'upcoming' or 'overdue'.
     * @return string SQL NOT EXISTS clause (empty string if no check applicable).
     */
    private function component_completion_subquery( $component_type, $wpdb, $trigger_type = 'upcoming' ) {
        switch ( $component_type ) {
            case 'classroom_visit':
                if ( $trigger_type === 'overdue' ) {
                    return "AND NOT EXISTS (
                        SELECT 1
                        FROM {$wpdb->prefix}hl_classroom_visit cv
                        INNER JOIN {$wpdb->prefix}hl_classroom_visit_submission cvs
                            ON cvs.classroom_visit_id = cv.classroom_visit_id
                           AND cvs.status = 'submitted'
                        WHERE cv.cycle_id = en.cycle_id
                          AND (cv.leader_enrollment_id = en.enrollment_id OR cv.teacher_enrollment_id = en.enrollment_id)
                          AND c.external_ref LIKE CONCAT('%\"visit_number\":', cv.visit_number, '%')
                    )";
                }
                return "AND NOT EXISTS (
                    SELECT 1
                    FROM {$wpdb->prefix}hl_classroom_visit cv
                    LEFT JOIN {$wpdb->prefix}hl_classroom_visit_submission cvs
                        ON cvs.classroom_visit_id = cv.classroom_visit_id
                       AND cvs.status = 'submitted'
                    WHERE cv.cycle_id = en.cycle_id
                      AND (cv.leader_enrollment_id = en.enrollment_id OR cv.teacher_enrollment_id = en.enrollment_id)
                      AND cvs.submission_id IS NOT NULL
                )";

            case 'reflective_practice_session':
                return "AND NOT EXISTS (
                    SELECT 1
                    FROM {$wpdb->prefix}hl_rp_session rps
                    LEFT JOIN {$wpdb->prefix}hl_rp_session_submission rpss
                        ON rpss.rp_session_id = rps.rp_session_id
                       AND rpss.status = 'submitted'
                    WHERE rps.cycle_id = en.cycle_id
                      AND rpss.submitted_by_user_id = en.user_id
                      AND rpss.submission_id IS NOT NULL
                )";

            case 'coaching_session_attendance':
                return "AND NOT EXISTS (
                    SELECT 1
                    FROM {$wpdb->prefix}hl_coaching_session cs_check
                    WHERE cs_check.component_id = c.component_id
                      AND cs_check.mentor_enrollment_id = en.enrollment_id
                      AND cs_check.session_status IN ('scheduled','attended')
                )";

            // Intentionally no completion check for: learndash_course, self_reflection,
            // teacher_self_assessment, child_assessment.
            //
            // Rationale: these are NOT one-time events. "Your course is due soon" is valid
            // even if the user has started (but not completed) the course. Self-reflections
            // and assessments may have multiple submissions or partial states that don't
            // map cleanly to "completed." Only the three event-based types above (CV, RP,
            // coaching) are true one-time submissions where a "you still need to do this"
            // reminder after completion would be incorrect.
            //
            // If a completion guard is needed for these types later, add the subquery here
            // with the appropriate table joins.
            default:
                return '';
        }
    }

    /**
     * Get users qualifying for a cron-based trigger.
     *
     * @param string      $trigger_key Cron trigger key.
     * @param object      $cycle       Cycle row.
     * @param object|null $workflow    Workflow row (needed for configurable offset triggers).
     * @return array Array of [ user_id, enrollment_id, entity_id, entity_type ].
     */
    private function get_cron_trigger_users( $trigger_key, $cycle, $workflow = null ) {
        global $wpdb;
        $cycle_id = (int) $cycle->cycle_id;

        switch ( $trigger_key ) {
            case 'cron:low_engagement_14d': {
                // Users who haven't logged in for 14+ days.
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT e.user_id, e.enrollment_id, e.enrollment_id AS entity_id, 'enrollment' AS entity_type
                     FROM {$wpdb->prefix}hl_enrollment e
                     LEFT JOIN {$wpdb->usermeta} um ON um.user_id = e.user_id AND um.meta_key = 'last_login'
                     WHERE e.cycle_id = %d AND e.status IN ('active','warning')
                       AND (um.meta_value IS NULL OR um.meta_value < %s)
                     LIMIT " . self::CRON_QUERY_ROW_CAP,
                    $cycle_id,
                    gmdate( 'Y-m-d H:i:s', strtotime( '-14 days' ) )
                ), ARRAY_A );
                if ( is_array( $rows ) && count( $rows ) >= self::CRON_QUERY_ROW_CAP && class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_cron_safety_cap_hit', array(
                        'entity_type' => 'email_workflow',
                        'reason'      => 'cron:low_engagement_14d returned ' . self::CRON_QUERY_ROW_CAP . ' rows — may be truncated.',
                    ) );
                }
                return is_array( $rows ) ? $rows : array();
            }

            // =====================================================================
            // New generic cron triggers with configurable offsets (Task 7).
            // Old trigger keys are kept as fallthrough aliases.
            // =====================================================================

            case 'cron:cv_window_7d':    /* fallthrough -- legacy alias */
            case 'cron:rp_window_7d':    /* fallthrough -- legacy alias */
            case 'cron:coaching_window_7d': /* fallthrough -- legacy alias */
            case 'cron:component_upcoming': {
                if ( ! $workflow ) {
                    return array();
                }
                $offset_minutes = (int) ( $workflow->trigger_offset_minutes ?? 10080 ); // default 7 days
                $offset_seconds = $offset_minutes * 60;
                $range_start    = wp_date( 'Y-m-d', time() );
                $range_end      = wp_date( 'Y-m-d', time() + $offset_seconds );

                $comp_type      = $workflow->component_type_filter ?? '';
                $anchor         = $this->get_date_anchor( 'upcoming', $comp_type );
                $col            = $anchor['column'];

                // SQL column whitelist -- prevent injection.
                $allowed_cols = array( 'complete_by', 'display_window_start', 'display_window_end' );
                if ( ! in_array( $col, $allowed_cols, true ) ) {
                    return array();
                }

                $type_clause = '';
                $type_params = array();
                if ( $comp_type !== '' ) {
                    $type_clause = 'AND c.component_type = %s';
                    $type_params = array( $comp_type );
                }

                // Component-type-specific completion check. Use cycle-scoped
                // CV suppression for the "window opens" reminder (one ping per
                // cycle regardless of visit_number count).
                $completion_clause = $this->component_completion_subquery( $comp_type, $wpdb, 'upcoming' );

                $cap = self::CRON_QUERY_ROW_CAP;
                $sql = "SELECT DISTINCT en.user_id,
                                en.enrollment_id AS enrollment_id,
                                c.component_id AS entity_id,
                                'component' AS entity_type
                        FROM {$wpdb->prefix}hl_component c
                        INNER JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = c.pathway_id
                        INNER JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.pathway_id = p.pathway_id
                        INNER JOIN {$wpdb->prefix}hl_enrollment en
                            ON en.enrollment_id = pa.enrollment_id
                           AND en.status IN ('active','warning')
                        WHERE c.cycle_id = %d
                          AND c.{$col} IS NOT NULL
                          AND c.{$col} BETWEEN %s AND %s
                          {$type_clause}
                          {$completion_clause}
                        LIMIT {$cap}";

                $params = array_merge( array( $cycle_id, $range_start, $range_end ), $type_params );
                $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
                if ( is_array( $rows ) && count( $rows ) >= $cap && class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_cron_safety_cap_hit', array(
                        'entity_type' => 'email_workflow',
                        'reason'      => 'cron:component_upcoming returned ' . $cap . ' rows — may be truncated.',
                    ) );
                    set_transient(
                        'hl_email_cron_cap_warning',
                        sprintf(
                            'Email cron trigger "%s" hit the %d-row safety cap for cycle %s. Some recipients may have been skipped. Contact your developer to review.',
                            $trigger_key,
                            $cap,
                            $cycle->cycle_code ?? $cycle->cycle_id
                        ),
                        24 * HOUR_IN_SECONDS
                    );
                }
                return is_array( $rows ) ? $rows : array();
            }

            case 'cron:cv_overdue_1d': /* fallthrough -- legacy alias */
            case 'cron:component_overdue': {
                if ( ! $workflow ) {
                    return array();
                }
                $offset_minutes = (int) ( $workflow->trigger_offset_minutes ?? 1440 ); // default 1 day
                $offset_seconds = $offset_minutes * 60;

                // 48-hour tolerance window: catches overdue components even if cron
                // skipped a day (server outage, low traffic). Dedup prevents double-sends.
                $overdue_earliest = wp_date( 'Y-m-d', time() - $offset_seconds - ( 48 * 3600 ) );
                $overdue_latest   = wp_date( 'Y-m-d', time() - $offset_seconds );

                $comp_type      = $workflow->component_type_filter ?? '';
                $anchor         = $this->get_date_anchor( 'overdue', $comp_type );
                $col            = $anchor['column'];

                $allowed_cols = array( 'complete_by', 'display_window_start', 'display_window_end' );
                if ( ! in_array( $col, $allowed_cols, true ) ) {
                    return array();
                }

                $type_clause = '';
                $type_params = array();
                if ( $comp_type !== '' ) {
                    $type_clause = 'AND c.component_type = %s';
                    $type_params = array( $comp_type );
                }

                // Component-type-specific completion check. Use per-component
                // CV suppression for the overdue path so CV #3 still fires
                // when CV #1 has been submitted (ELCPB-Y2 multi-visit pathways).
                $completion_clause = $this->component_completion_subquery( $comp_type, $wpdb, 'overdue' );

                $cap = self::CRON_QUERY_ROW_CAP;
                $sql = "SELECT DISTINCT en.user_id,
                                en.enrollment_id AS enrollment_id,
                                c.component_id AS entity_id,
                                'component' AS entity_type
                        FROM {$wpdb->prefix}hl_component c
                        INNER JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = c.pathway_id
                        INNER JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.pathway_id = p.pathway_id
                        INNER JOIN {$wpdb->prefix}hl_enrollment en
                            ON en.enrollment_id = pa.enrollment_id
                           AND en.status IN ('active','warning')
                        WHERE c.cycle_id = %d
                          AND c.{$col} IS NOT NULL
                          AND c.{$col} BETWEEN %s AND %s
                          {$type_clause}
                          {$completion_clause}
                        LIMIT {$cap}";

                $params = array_merge( array( $cycle_id, $overdue_earliest, $overdue_latest ), $type_params );
                $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
                if ( is_array( $rows ) && count( $rows ) >= $cap && class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_cron_safety_cap_hit', array(
                        'entity_type' => 'email_workflow',
                        'reason'      => 'cron:component_overdue returned ' . $cap . ' rows — may be truncated.',
                    ) );
                    set_transient(
                        'hl_email_cron_cap_warning',
                        sprintf(
                            'Email cron trigger "%s" hit the %d-row safety cap for cycle %s. Some recipients may have been skipped. Contact your developer to review.',
                            $trigger_key,
                            $cap,
                            $cycle->cycle_code ?? $cycle->cycle_id
                        ),
                        24 * HOUR_IN_SECONDS
                    );
                }
                return is_array( $rows ) ? $rows : array();
            }

            case 'cron:coaching_session_5d': /* fallthrough -- legacy alias */
            case 'cron:session_24h':         /* fallthrough -- legacy alias */
            case 'cron:session_1h':          /* fallthrough -- legacy alias */
            case 'cron:session_upcoming': {
                if ( ! $workflow ) {
                    return array();
                }
                $offset_minutes = (int) ( $workflow->trigger_offset_minutes ?? 1440 ); // default 24h
                // Note: session_datetime is stored in site timezone (WordPress "Timezone"
                // setting). Use current_time() to get "now" in the same timezone so the
                // BETWEEN comparison is apples-to-apples. Do NOT use gmdate()/time() here.
                $now            = current_time( 'mysql' );

                // Scale fuzz window proportionally: 10% of offset, clamped 5min-30min.
                $fuzz_seconds   = min( 1800, max( 300, $offset_minutes * 60 * 0.1 ) );
                $target_time    = strtotime( $now ) + ( $offset_minutes * 60 );
                $window_start   = wp_date( 'Y-m-d H:i:s', $target_time - $fuzz_seconds );
                $window_end     = wp_date( 'Y-m-d H:i:s', $target_time + $fuzz_seconds );

                $cap = self::CRON_QUERY_ROW_CAP;
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT DISTINCT e.user_id, e.enrollment_id,
                            cs.session_id AS entity_id, 'coaching_session' AS entity_type
                     FROM {$wpdb->prefix}hl_coaching_session cs
                     JOIN {$wpdb->prefix}hl_enrollment e ON e.enrollment_id = cs.mentor_enrollment_id
                     JOIN {$wpdb->users} u ON u.ID = e.user_id
                     WHERE cs.cycle_id = %d
                       AND cs.session_status = 'scheduled'
                       AND cs.session_datetime BETWEEN %s AND %s
                       AND e.status IN ('active','warning')
                     LIMIT {$cap}",
                    $cycle_id, $window_start, $window_end
                ), ARRAY_A );
                if ( is_array( $rows ) && count( $rows ) >= $cap && class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_cron_safety_cap_hit', array(
                        'entity_type' => 'email_workflow',
                        'reason'      => 'cron:session_upcoming returned ' . $cap . ' rows — may be truncated.',
                    ) );
                    set_transient(
                        'hl_email_cron_cap_warning',
                        sprintf(
                            'Email cron trigger "%s" hit the %d-row safety cap for cycle %s. Some recipients may have been skipped. Contact your developer to review.',
                            $trigger_key,
                            $cap,
                            $cycle->cycle_code ?? $cycle->cycle_id
                        ),
                        24 * HOUR_IN_SECONDS
                    );
                }
                return is_array( $rows ) ? $rows : array();
            }

            case 'cron:coaching_pre_end': {
                // Cycles ending in 0-14 days; enrollments with coaching components and no attended session.
                $today  = current_time( 'Y-m-d' );
                $plus14 = wp_date( 'Y-m-d', strtotime( $today . ' +14 days' ) );
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT DISTINCT en.user_id,
                            en.enrollment_id AS enrollment_id,
                            c.component_id AS entity_id,
                            'component' AS entity_type
                     FROM {$wpdb->prefix}hl_cycle cy
                     INNER JOIN {$wpdb->prefix}hl_enrollment en
                         ON en.cycle_id = cy.cycle_id AND en.status IN ('active','warning')
                     INNER JOIN {$wpdb->prefix}hl_pathway_assignment pa ON pa.enrollment_id = en.enrollment_id
                     INNER JOIN {$wpdb->prefix}hl_pathway p ON p.pathway_id = pa.pathway_id
                     INNER JOIN {$wpdb->prefix}hl_component c
                         ON c.pathway_id = p.pathway_id
                        AND c.component_type = 'coaching_session_attendance'
                     LEFT JOIN {$wpdb->prefix}hl_coaching_session cs
                         ON cs.component_id = c.component_id
                        AND cs.mentor_enrollment_id = en.enrollment_id
                        AND cs.session_status = 'attended'
                     WHERE cy.cycle_id = %d
                       AND cy.status = 'active'
                       AND cy.end_date IS NOT NULL
                       AND cy.end_date BETWEEN %s AND %s
                       AND cs.session_id IS NULL
                     LIMIT " . self::CRON_QUERY_ROW_CAP,
                    $cycle_id, $today, $plus14
                ), ARRAY_A );
                if ( is_array( $rows ) && count( $rows ) >= self::CRON_QUERY_ROW_CAP && class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_cron_safety_cap_hit', array(
                        'entity_type' => 'email_workflow',
                        'reason'      => 'cron:coaching_pre_end returned ' . self::CRON_QUERY_ROW_CAP . ' rows — may be truncated. Review cycle scope or add ORDER BY + cursor pagination.',
                    ) );
                }
                return is_array( $rows ) ? $rows : array();
            }

            case 'cron:action_plan_24h': {
                // Mentors who haven't filed an action plan ≥24h after an
                // attended coaching session. Action plans are stored in
                // hl_coaching_session_submission with role_in_session='supervisee'
                // (mentor-authored) — same pattern used by
                // HL_Coaching_Service::get_previous_coaching_action_plans().
                //
                // Anchor clock: cs.session_datetime (close enough to
                // "24h after attended" for same-day attendance marking;
                // a late back-dated attendance would fire immediately).
                // 30-day lookback clamps the scan window.
                //
                // Timezone: session_datetime is stored in site TZ, so use
                // current_time('mysql') as the clock — NOT gmdate/UTC.
                $cutoff_24h   = wp_date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -24 hours' ) );
                $lookback_30d = wp_date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -30 days' ) );
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT e.user_id, cs.mentor_enrollment_id AS enrollment_id,
                            cs.session_id AS entity_id, 'coaching_session' AS entity_type
                     FROM {$wpdb->prefix}hl_coaching_session cs
                     INNER JOIN {$wpdb->prefix}hl_enrollment e ON e.enrollment_id = cs.mentor_enrollment_id
                     LEFT JOIN {$wpdb->prefix}hl_coaching_session_submission sub
                       ON sub.session_id = cs.session_id
                      AND sub.role_in_session = 'supervisee'
                      AND sub.status = 'submitted'
                     WHERE cs.cycle_id = %d AND cs.session_status = 'attended'
                       AND cs.session_datetime < %s
                       AND cs.session_datetime > %s
                       AND sub.submission_id IS NULL
                     LIMIT " . self::CRON_QUERY_ROW_CAP,
                    $cycle_id, $cutoff_24h, $lookback_30d
                ), ARRAY_A );
                if ( is_array( $rows ) && count( $rows ) >= self::CRON_QUERY_ROW_CAP && class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_cron_safety_cap_hit', array(
                        'entity_type' => 'email_workflow',
                        'reason'      => 'cron:action_plan_24h returned ' . self::CRON_QUERY_ROW_CAP . ' rows — may be truncated.',
                    ) );
                }
                return is_array( $rows ) ? $rows : array();
            }

            case 'cron:session_notes_24h': {
                // Coaches who haven't filed session notes ≥24h after an
                // attended session. Notes are stored in
                // hl_coaching_session_submission with role_in_session='supervisor'.
                //
                // Coaches are direct staff users (cs.coach_user_id is a WP
                // user_id, NOT an enrollment). The returned row intentionally
                // has enrollment_id = NULL — this propagates into the cron
                // pipeline's context and is handled by the downstream
                // ?? null patterns in run_cron_workflow() and the recipient
                // resolver. Covered end-to-end by test-email-phase2-stubs.php
                // §5.3 NULL-enrollment assertions.
                //
                // Anchor + lookback + timezone: same as cron:action_plan_24h above.
                $cutoff_24h   = wp_date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -24 hours' ) );
                $lookback_30d = wp_date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -30 days' ) );
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT cs.coach_user_id AS user_id,
                            NULL AS enrollment_id,
                            cs.session_id AS entity_id, 'coaching_session' AS entity_type
                     FROM {$wpdb->prefix}hl_coaching_session cs
                     LEFT JOIN {$wpdb->prefix}hl_coaching_session_submission sub
                       ON sub.session_id = cs.session_id
                      AND sub.role_in_session = 'supervisor'
                      AND sub.status = 'submitted'
                     WHERE cs.cycle_id = %d AND cs.session_status = 'attended'
                       AND cs.session_datetime < %s
                       AND cs.session_datetime > %s
                       AND sub.submission_id IS NULL
                     LIMIT " . self::CRON_QUERY_ROW_CAP,
                    $cycle_id, $cutoff_24h, $lookback_30d
                ), ARRAY_A );
                if ( is_array( $rows ) && count( $rows ) >= self::CRON_QUERY_ROW_CAP && class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_cron_safety_cap_hit', array(
                        'entity_type' => 'email_workflow',
                        'reason'      => 'cron:session_notes_24h returned ' . self::CRON_QUERY_ROW_CAP . ' rows — may be truncated.',
                    ) );
                }
                return is_array( $rows ) ? $rows : array();
            }

            case 'cron:client_success':
                // TODO: Define client success touchpoint criteria.
                return array();

            default:
                return array();
        }
    }

    /**
     * Seconds elapsed since the last successful run_daily_checks() execution.
     *
     * @return int|null Seconds since last successful daily cron run, or null if never.
     */
    public static function cron_staleness_seconds() {
        $last = get_option( 'hl_email_last_cron_run_at', null );
        if ( ! $last ) return null;
        $ts = strtotime( $last );
        return $ts ? ( time() - $ts ) : null;
    }

    /**
     * Register the HL Email daily cron freshness test with WordPress Site Health.
     *
     * @param array $tests Site Health test registry.
     * @return array
     */
    public function register_site_health_test( $tests ) {
        $tests['direct']['hl_email_cron_fresh'] = array(
            'label' => __( 'HL Email daily cron', 'hl-core' ),
            'test'  => array( $this, 'site_health_cron_test' ),
        );
        return $tests;
    }

    /**
     * Site Health synchronous test: checks whether run_daily_checks() ran in the last 36 hours.
     *
     * @return array
     */
    public function site_health_cron_test() {
        $gap = self::cron_staleness_seconds();
        $ok  = $gap !== null && $gap < 36 * HOUR_IN_SECONDS;
        return array(
            'label'       => $ok
                ? __( 'HL Email daily cron has run recently', 'hl-core' )
                : __( 'HL Email daily cron is stale', 'hl-core' ),
            'status'      => $ok ? 'good' : 'recommended',
            'badge'       => array( 'label' => __( 'HL Email', 'hl-core' ), 'color' => $ok ? 'green' : 'orange' ),
            'description' => sprintf(
                '<p>%s</p>',
                esc_html( $ok
                    ? __( 'Last run within the last 36 hours.', 'hl-core' )
                    : __( 'No successful run in the last 36 hours. Check wp-cron or trigger manually with `wp cron event run hl_email_cron_daily`.', 'hl-core' )
                )
            ),
            'actions'     => '',
            'test'        => 'hl_email_cron_fresh',
        );
    }

    /**
     * Render an admin warning on hl-emails* screens when the daily cron is stale.
     */
    public function maybe_render_cron_staleness_notice() {
        if ( ! function_exists( 'get_current_screen' ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || strpos( (string) $screen->id, 'hl-emails' ) === false ) return;

        $gap = self::cron_staleness_seconds();
        if ( $gap === null || $gap < 36 * HOUR_IN_SECONDS ) return;

        $hours = floor( $gap / HOUR_IN_SECONDS );
        echo '<div class="notice notice-warning"><p><strong>' .
            esc_html__( 'HL Email daily cron is stale', 'hl-core' ) .
            '</strong> &mdash; ' .
            esc_html( sprintf( __( 'last successful run was %d hours ago.', 'hl-core' ), $hours ) ) .
            ' <code>wp cron event run hl_email_cron_daily</code></p></div>';
    }

    /**
     * Cached column-exists check for hl_component.available_from (Rev 35).
     */
    private static function has_component_window_column() {
        static $cached = null;
        if ( $cached !== null ) return $cached;

        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND COLUMN_NAME = 'available_from' LIMIT 1",
            $wpdb->prefix . 'hl_component'
        ) );
        $cached = ! empty( $row );
        return $cached;
    }

    /**
     * Sweep stale builder drafts from wp_options.
     *
     * Deletes hl_email_draft_* options whose envelope updated_at is older
     * than 30 days. Corrupt envelopes (non-array JSON) are skipped and
     * audit-logged but never deleted. First run uses a larger cap to
     * absorb backlog; subsequent runs are capped at 500 rows each.
     */
    private function cleanup_stale_drafts() {
        global $wpdb;

        $first_run    = ! (bool) get_option( 'hl_email_draft_cleanup_seen', 0 );
        $cap          = $first_run ? 5000 : 500;
        $threshold_ts = time() - 30 * DAY_IN_SECONDS;
        $cutoff       = gmdate( 'c', $threshold_ts ); // kept for audit log readability

        $like = $wpdb->esc_like( 'hl_email_draft_' ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_id, option_name, option_value
             FROM {$wpdb->options}
             WHERE option_name LIKE %s
             LIMIT %d",
            $like, $cap
        ) );

        if ( empty( $rows ) ) {
            update_option( 'hl_email_draft_cleanup_seen', 1, false );
            return;
        }

        $to_delete = array();
        $skipped   = 0;

        foreach ( $rows as $row ) {
            $decoded = json_decode( $row->option_value, true );

            if ( ! is_array( $decoded ) ) {
                // Corrupt payload — skip + audit, never delete.
                $skipped++;
                if ( class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_draft_cleanup_skip', array(
                        'entity_type' => 'wp_options',
                        'entity_id'   => (int) $row->option_id,
                        'reason'      => 'corrupt_envelope',
                    ) );
                }
                continue;
            }

            $updated_at = isset( $decoded['updated_at'] )
                ? $decoded['updated_at']
                : ( isset( $decoded['created_at'] ) ? $decoded['created_at'] : '2000-01-01T00:00:00+00:00' );
            // strtotime handles non-UTC offsets (e.g. +05:30, Z) correctly;
            // a naive strcmp against a +00:00 cutoff would be wrong at the
            // offset boundary. Task 8's writer uses gmdate('c') so all
            // envelopes are +00:00 today, but future-proof against that.
            $ts_updated = strtotime( (string) $updated_at );
            if ( $ts_updated !== false && $ts_updated < $threshold_ts ) {
                $to_delete[] = (int) $row->option_id;
            }
        }

        if ( ! empty( $to_delete ) ) {
            $ids_sql = implode( ',', array_map( 'intval', $to_delete ) );
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_id IN ({$ids_sql})" );
            // Invalidate the autoloaded options cache so deleted rows don't
            // resurrect from a stale alloptions snapshot on the next request.
            wp_cache_delete( 'alloptions', 'options' );
        }

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_draft_cleanup', array(
                'entity_type' => 'wp_options',
                'reason'      => sprintf( 'deleted=%d skipped=%d cap=%d', count( $to_delete ), $skipped, $cap ),
            ) );
        }

        update_option( 'hl_email_draft_cleanup_seen', 1, false );
    }
}
