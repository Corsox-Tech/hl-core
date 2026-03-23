<?php
if (!defined('ABSPATH')) exit;

/**
 * RP Session page controller.
 *
 * Renders when a user clicks an RP Session component from their pathway.
 * Determines whether to show the Mentor view (RP Notes + teacher list) or
 * Teacher view (Action Plan) based on the user's enrollment role.
 *
 * @package HL_Core
 */
class HL_Frontend_RP_Session {

    /**
     * Render the RP Session component page.
     *
     * Called from HL_Frontend_Component_Page::render_available_view().
     *
     * @param object $component  Component domain object
     * @param object $enrollment Enrollment domain object
     * @param int    $cycle_id
     * @return string HTML
     */
    public function render($component, $enrollment, $cycle_id) {
        ob_start();

        // Handle form submissions before rendering
        $this->handle_post_submissions();

        $user_id        = get_current_user_id();
        $enrollment_id  = (int) $enrollment->enrollment_id;
        $external_ref   = $component->get_external_ref_array();
        $session_number = isset($external_ref['session_number']) ? (int) $external_ref['session_number'] : 1;

        $rp_service = new HL_RP_Session_Service();

        // Determine role
        $is_mentor = $enrollment->has_role('mentor');

        if ($is_mentor) {
            $this->render_mentor_view($enrollment, $cycle_id, $session_number, $rp_service);
        } else {
            $this->render_teacher_view($enrollment, $cycle_id, $session_number, $rp_service);
        }

        return ob_get_clean();
    }

    /**
     * Mentor view: show list of teachers in team, each with session status.
     * When a specific teacher is selected, show RP Notes form side-by-side with Action Plan.
     */
    private function render_mentor_view($enrollment, $cycle_id, $session_number, $rp_service) {
        $enrollment_id = (int) $enrollment->enrollment_id;

        // Get teachers in mentor's team
        $teachers = $rp_service->get_teachers_for_mentor($enrollment_id);

        // Check if a specific teacher is selected
        $selected_teacher_id = isset($_GET['teacher']) ? absint($_GET['teacher']) : 0;

        if ($selected_teacher_id && !empty($teachers)) {
            $this->render_mentor_session_detail($enrollment, $cycle_id, $session_number, $selected_teacher_id, $rp_service);
            return;
        }

        // Show teacher list
        ?>
        <div class="hl-rp-session-mentor-view">
            <h3><?php printf(esc_html__('Reflective Practice Session #%d', 'hl-core'), $session_number); ?></h3>
            <p class="hl-field-hint"><?php esc_html_e('Select a teacher to open RP Notes for this session.', 'hl-core'); ?></p>

            <?php if (empty($teachers)) : ?>
                <div class="hl-empty-state">
                    <p><?php esc_html_e('No teachers found in your team.', 'hl-core'); ?></p>
                </div>
            <?php else : ?>
                <table class="hl-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Teacher', 'hl-core'); ?></th>
                            <th><?php esc_html_e('Session Status', 'hl-core'); ?></th>
                            <th><?php esc_html_e('Action', 'hl-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teachers as $teacher) :
                            $teacher_enrollment_id = (int) $teacher['enrollment_id'];

                            // Find existing session for this teacher + session number
                            $sessions = $rp_service->get_by_teacher($teacher_enrollment_id);
                            $matching_session = null;
                            foreach ($sessions as $s) {
                                if ((int) $s['session_number'] === $session_number && (int) $s['cycle_id'] === $cycle_id) {
                                    $matching_session = $s;
                                    break;
                                }
                            }

                            $session_status = $matching_session ? $matching_session['status'] : 'not_started';
                            $status_class = 'gray';
                            if ($session_status === 'attended') $status_class = 'green';
                            elseif ($session_status === 'scheduled') $status_class = 'blue';

                            $detail_url = add_query_arg('teacher', $teacher_enrollment_id);
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($teacher['display_name']); ?></strong>
                                    <?php if (!empty($teacher['user_email'])) : ?>
                                        <br><small class="hl-muted"><?php echo esc_html($teacher['user_email']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="hl-badge hl-badge-<?php echo esc_attr($status_class); ?>">
                                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $session_status))); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($detail_url); ?>" class="hl-btn hl-btn-small hl-btn-primary">
                                        <?php esc_html_e('Open RP Session', 'hl-core'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Mentor session detail: RP Notes form + Action Plan side-by-side.
     */
    private function render_mentor_session_detail($enrollment, $cycle_id, $session_number, $teacher_enrollment_id, $rp_service) {
        global $wpdb;

        $enrollment_id = (int) $enrollment->enrollment_id;

        // Find or create the RP session
        $session = $this->find_or_create_session($rp_service, $cycle_id, $enrollment_id, $teacher_enrollment_id, $session_number);
        if (!$session || is_wp_error($session)) {
            echo '<div class="hl-notice hl-notice-error">' . esc_html__('Unable to load RP session.', 'hl-core') . '</div>';
            return;
        }

        $rp_session_id = (int) $session['rp_session_id'];

        // Load instruments
        $mentor_instrument = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s AND status = 'active'",
            'mentoring_rp_notes'
        ));

        $teacher_instrument = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s AND status = 'active'",
            'mentoring_action_plan'
        ));

        // Load submissions
        $submissions = $rp_service->get_submissions($rp_session_id);
        $mentor_submission  = null;
        $teacher_submission = null;
        foreach ($submissions as $sub) {
            if ($sub['role_in_session'] === 'supervisor') $mentor_submission = $sub;
            if ($sub['role_in_session'] === 'supervisee') $teacher_submission = $sub;
        }

        // Back link
        $back_url = remove_query_arg('teacher');
        ?>
        <div class="hl-rp-session-detail">
            <a href="<?php echo esc_url($back_url); ?>" class="hl-back-link">&larr; <?php esc_html_e('Back to Teacher List', 'hl-core'); ?></a>

            <h3><?php printf(esc_html__('RP Session #%d', 'hl-core'), $session_number); ?></h3>

            <div class="hl-side-by-side">
                <!-- RP Notes (Mentor fills) -->
                <div class="hl-side-by-side-panel">
                    <?php
                    if ($mentor_instrument) {
                        $rp_notes_renderer = new HL_Frontend_RP_Notes();
                        echo $rp_notes_renderer->render(
                            'mentoring',
                            $session,
                            $enrollment,
                            $mentor_instrument,
                            $teacher_enrollment_id,
                            $cycle_id,
                            $mentor_submission
                        );
                    } else {
                        echo '<div class="hl-notice hl-notice-warning">' . esc_html__('RP Notes instrument not found. Please run the instrument seeder.', 'hl-core') . '</div>';
                    }
                    ?>
                </div>

                <!-- Action Plan (Teacher filled, read-only for mentor) -->
                <div class="hl-side-by-side-panel">
                    <?php
                    if ($teacher_instrument && $teacher_submission) {
                        $ap_renderer = new HL_Frontend_Action_Plan();
                        echo $ap_renderer->render(
                            'mentoring',
                            $session,
                            $enrollment,
                            $teacher_instrument,
                            $teacher_submission
                        );
                    } elseif ($teacher_submission) {
                        echo '<div class="hl-notice hl-notice-info">' . esc_html__('Teacher has submitted their Action Plan.', 'hl-core') . '</div>';
                    } else {
                        echo '<div class="hl-notice hl-notice-info">' . esc_html__('Teacher has not yet submitted their Action Plan for this session.', 'hl-core') . '</div>';
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Teacher view: show the Action Plan form for this session.
     */
    private function render_teacher_view($enrollment, $cycle_id, $session_number, $rp_service) {
        global $wpdb;

        $enrollment_id = (int) $enrollment->enrollment_id;

        // Find RP sessions for this teacher
        $sessions = $rp_service->get_by_teacher($enrollment_id);
        $session = null;
        foreach ($sessions as $s) {
            if ((int) $s['session_number'] === $session_number && (int) $s['cycle_id'] === $cycle_id) {
                $session = $s;
                break;
            }
        }

        // Load Action Plan instrument
        $instrument = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s AND status = 'active'",
            'mentoring_action_plan'
        ));

        if (!$instrument) {
            echo '<div class="hl-notice hl-notice-warning">' . esc_html__('Action Plan instrument not found. Please contact your administrator.', 'hl-core') . '</div>';
            return;
        }

        ?>
        <div class="hl-rp-session-teacher-view">
            <h3><?php printf(esc_html__('Reflective Practice Session #%d — Action Plan', 'hl-core'), $session_number); ?></h3>

            <?php if ($session) :
                // Load existing submission
                $submissions = $rp_service->get_submissions((int) $session['rp_session_id']);
                $existing = null;
                foreach ($submissions as $sub) {
                    if ($sub['role_in_session'] === 'supervisee') {
                        $existing = $sub;
                        break;
                    }
                }

                // Show mentor info
                if (!empty($session['mentor_name'])) : ?>
                    <p class="hl-field-hint">
                        <?php printf(esc_html__('Mentor: %s', 'hl-core'), esc_html($session['mentor_name'])); ?>
                    </p>
                <?php endif;

                $ap_renderer = new HL_Frontend_Action_Plan();
                echo $ap_renderer->render('mentoring', $session, $enrollment, $instrument, $existing);
            else : ?>
                <div class="hl-notice hl-notice-info">
                    <p><?php esc_html_e('Your mentor has not yet created an RP session for this session number. Please check back after your mentor sets up the session.', 'hl-core'); ?></p>
                </div>

                <?php if ($instrument) :
                    // Allow teacher to fill Action Plan even without a session entity
                    // Create a placeholder session entity with minimal info
                    $placeholder_session = array(
                        'rp_session_id'  => 0,
                        'session_number' => $session_number,
                        'status'         => 'pending',
                    );

                    echo '<div class="hl-notice hl-notice-warning">' . esc_html__('You can start filling your Action Plan below. It will be linked to your RP session once your mentor creates it.', 'hl-core') . '</div>';
                endif;
            endif; ?>
        </div>
        <?php
    }

    /**
     * Find an existing RP session or create one.
     *
     * @return array|WP_Error
     */
    private function find_or_create_session($rp_service, $cycle_id, $mentor_enrollment_id, $teacher_enrollment_id, $session_number) {
        // Check for existing session
        $sessions = $rp_service->get_by_mentor($mentor_enrollment_id);
        foreach ($sessions as $s) {
            if ((int) $s['teacher_enrollment_id'] === $teacher_enrollment_id
                && (int) $s['session_number'] === $session_number
                && (int) $s['cycle_id'] === $cycle_id) {
                return $s;
            }
        }

        // Create new session
        $result = $rp_service->create_session(array(
            'cycle_id'              => $cycle_id,
            'mentor_enrollment_id'  => $mentor_enrollment_id,
            'teacher_enrollment_id' => $teacher_enrollment_id,
            'session_number'        => $session_number,
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        return $rp_service->get_session($result);
    }

    /**
     * Handle POST submissions from embedded forms.
     */
    private function handle_post_submissions() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $result = null;

        // RP Notes submission
        if (!empty($_POST['hl_rp_notes_nonce'])) {
            $result = HL_Frontend_RP_Notes::handle_submission('mentoring');
        }

        // Action Plan submission
        if (!empty($_POST['hl_action_plan_nonce'])) {
            $result = HL_Frontend_Action_Plan::handle_submission('mentoring');
        }

        if ($result && !is_wp_error($result)) {
            $redirect = add_query_arg('message', $result['message']);
            wp_safe_redirect($redirect);
            exit;
        }

        if (is_wp_error($result)) {
            echo '<div class="hl-notice hl-notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
        }
    }
}
