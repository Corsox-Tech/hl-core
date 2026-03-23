<?php
if (!defined('ABSPATH')) exit;

/**
 * RP Notes form renderer.
 *
 * Used by both coaching (Coach fills about Mentor) and mentoring (Mentor fills
 * about Teacher) contexts. Has auto-populated sections from HL_Session_Prep_Service
 * plus editable session notes with rich text fields.
 *
 * @package HL_Core
 */
class HL_Frontend_RP_Notes {

    /**
     * Render the RP Notes form.
     *
     * @param string      $context             'coaching' or 'mentoring'
     * @param array       $session_entity      Session row (coaching_session or rp_session)
     * @param object      $enrollment          Supervisor's enrollment object
     * @param object      $instrument          Instrument row
     * @param int         $supervisee_enrollment_id  The supervisee's enrollment_id
     * @param int         $cycle_id
     * @param array|null  $existing_submission Existing submission row, or null
     * @return string HTML
     */
    public function render($context, $session_entity, $enrollment, $instrument, $supervisee_enrollment_id, $cycle_id, $existing_submission = null) {
        ob_start();

        $responses   = $existing_submission ? json_decode($existing_submission['responses_json'], true) : array();
        $is_readonly = ($existing_submission && $existing_submission['status'] === 'submitted');

        // Session identifiers
        $session_id_key   = ($context === 'coaching') ? 'session_id' : 'rp_session_id';
        $session_id_value = isset($session_entity[$session_id_key]) ? (int) $session_entity[$session_id_key] : 0;
        $instrument_id    = (int) $instrument->instrument_id;

        // Load auto-populated data
        $prep_service = new HL_Session_Prep_Service();
        $progress     = $prep_service->get_supervisee_progress($supervisee_enrollment_id, $cycle_id);
        $prev_plans   = $prep_service->get_previous_action_plans($supervisee_enrollment_id, $cycle_id, $context);

        // Classroom visit review
        if ($context === 'coaching') {
            $cv_review = $prep_service->get_classroom_visit_for_mentor_context($enrollment->enrollment_id, $cycle_id);
        } else {
            $cv_review = $prep_service->get_classroom_visit_review($supervisee_enrollment_id, $cycle_id);
        }

        // Labels based on context
        $supervisor_label  = ($context === 'coaching') ? __('Coach', 'hl-core') : __('Mentor', 'hl-core');
        $supervisee_label  = ($context === 'coaching') ? __('Mentor', 'hl-core') : __('Teacher', 'hl-core');

        ?>
        <div class="hl-form hl-rp-notes-form">
            <h3><?php esc_html_e('RP Session Notes', 'hl-core'); ?></h3>

            <?php if ($is_readonly) : ?>
                <div class="hl-notice hl-notice-info">
                    <p><?php esc_html_e('This form has been submitted and is read-only.', 'hl-core'); ?></p>
                </div>
            <?php endif; ?>

            <!-- Section 1: Session Information (auto-populated, read-only) -->
            <div class="hl-fieldset hl-auto-populated">
                <h4><?php esc_html_e('Session Information', 'hl-core'); ?></h4>
                <div class="hl-info-grid">
                    <div class="hl-info-item">
                        <span class="hl-info-label"><?php echo esc_html($supervisor_label); ?>:</span>
                        <span class="hl-info-value"><?php
                            if ($context === 'coaching') {
                                echo esc_html(isset($session_entity['coach_name']) ? $session_entity['coach_name'] : '—');
                            } else {
                                echo esc_html(isset($session_entity['mentor_name']) ? $session_entity['mentor_name'] : '—');
                            }
                        ?></span>
                    </div>
                    <div class="hl-info-item">
                        <span class="hl-info-label"><?php echo esc_html($supervisee_label); ?>:</span>
                        <span class="hl-info-value"><?php
                            if ($context === 'coaching') {
                                echo esc_html(isset($session_entity['mentor_name']) ? $session_entity['mentor_name'] : '—');
                            } else {
                                echo esc_html(isset($session_entity['teacher_name']) ? $session_entity['teacher_name'] : '—');
                            }
                        ?></span>
                    </div>
                    <div class="hl-info-item">
                        <span class="hl-info-label"><?php esc_html_e('Date:', 'hl-core'); ?></span>
                        <span class="hl-info-value"><?php
                            $date_field = ($context === 'coaching') ? 'session_datetime' : 'session_date';
                            $date_val = isset($session_entity[$date_field]) ? $session_entity[$date_field] : '';
                            echo esc_html($date_val ? date_i18n(get_option('date_format'), strtotime($date_val)) : '—');
                        ?></span>
                    </div>
                    <div class="hl-info-item">
                        <span class="hl-info-label"><?php esc_html_e('Session #:', 'hl-core'); ?></span>
                        <span class="hl-info-value"><?php
                            $num_field = ($context === 'coaching') ? 'session_number' : 'session_number';
                            echo esc_html(isset($session_entity[$num_field]) ? $session_entity[$num_field] : '—');
                        ?></span>
                    </div>
                    <?php if (!empty($progress['current_course'])) : ?>
                        <div class="hl-info-item">
                            <span class="hl-info-label"><?php printf(esc_html__('%s\'s Current Course:', 'hl-core'), esc_html($supervisee_label)); ?></span>
                            <span class="hl-info-value"><?php echo esc_html($progress['current_course']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" id="hl-rp-notes-form">
                <?php if (!$is_readonly) : ?>
                    <?php wp_nonce_field('hl_rp_notes_submit', 'hl_rp_notes_nonce'); ?>
                    <input type="hidden" name="hl_rp_notes_context" value="<?php echo esc_attr($context); ?>">
                    <input type="hidden" name="hl_rp_notes_session_id" value="<?php echo esc_attr($session_id_value); ?>">
                    <input type="hidden" name="hl_rp_notes_instrument_id" value="<?php echo esc_attr($instrument_id); ?>">
                <?php endif; ?>

                <!-- Section 2: Personal Notes (supervisor-only) -->
                <fieldset class="hl-fieldset">
                    <legend><?php esc_html_e('Personal Notes', 'hl-core'); ?></legend>
                    <p class="hl-field-hint"><?php esc_html_e('These notes are private and not shared with the supervisee.', 'hl-core'); ?></p>
                    <?php if ($is_readonly) : ?>
                        <div class="hl-field-value"><?php echo wp_kses_post(isset($responses['personal_notes']) ? $responses['personal_notes'] : ''); ?></div>
                    <?php else : ?>
                        <?php
                        wp_editor(
                            isset($responses['personal_notes']) ? $responses['personal_notes'] : '',
                            'hl_rp_personal_notes',
                            array(
                                'textarea_name' => 'hl_rp_notes[personal_notes]',
                                'media_buttons' => false,
                                'textarea_rows' => 4,
                                'teeny'         => true,
                                'quicktags'     => false,
                            )
                        );
                        ?>
                    <?php endif; ?>
                </fieldset>

                <!-- Section 3: Session Prep (auto-populated, read-only) -->
                <fieldset class="hl-fieldset hl-auto-populated">
                    <legend><?php esc_html_e('Session Prep', 'hl-core'); ?></legend>

                    <!-- Pathway Progress -->
                    <div class="hl-prep-card">
                        <h5><?php printf(esc_html__('%s\'s Pathway Progress', 'hl-core'), esc_html($supervisee_label)); ?></h5>
                        <div class="hl-progress-summary">
                            <span class="hl-progress-count">
                                <?php
                                $completed = (int) $progress['completed_components'];
                                $total     = (int) $progress['total_components'];
                                printf(
                                    esc_html__('%d of %d components completed', 'hl-core'),
                                    $completed,
                                    $total
                                );
                                ?>
                            </span>
                            <?php if ($total > 0) : ?>
                                <div class="hl-progress-bar">
                                    <div class="hl-progress-fill" style="width:<?php echo esc_attr(round(($completed / $total) * 100)); ?>%"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Previous Action Plans -->
                    <div class="hl-prep-card">
                        <h5><?php esc_html_e('Previous Action Plans', 'hl-core'); ?></h5>
                        <?php if (empty($prev_plans)) : ?>
                            <p class="hl-muted"><?php esc_html_e('No previous action plans submitted.', 'hl-core'); ?></p>
                        <?php else : ?>
                            <div class="hl-scrollable-list" style="max-height:300px;overflow-y:auto;">
                                <?php foreach ($prev_plans as $plan) :
                                    $plan_data = json_decode($plan['responses_json'], true);
                                    $plan_date = !empty($plan['submitted_at']) ? date_i18n(get_option('date_format'), strtotime($plan['submitted_at'])) : '—';
                                ?>
                                    <div class="hl-prep-item">
                                        <div class="hl-prep-item-header">
                                            <strong><?php echo esc_html($plan_date); ?></strong>
                                            <?php if (!empty($plan_data['domain'])) : ?>
                                                <span class="hl-badge hl-badge-blue"><?php echo esc_html($plan_data['domain']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($plan_data['how'])) : ?>
                                            <p class="hl-prep-excerpt"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($plan_data['how']), 30)); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </fieldset>

                <!-- Section 4: Classroom Visit Review (auto-populated, read-only) -->
                <fieldset class="hl-fieldset hl-auto-populated">
                    <legend><?php esc_html_e('Classroom Visit & Self-Reflection Review', 'hl-core'); ?></legend>
                    <?php if (empty($cv_review)) : ?>
                        <p class="hl-muted"><?php esc_html_e('No classroom visit data available.', 'hl-core'); ?></p>
                    <?php else : ?>
                        <div class="hl-prep-card">
                            <div class="hl-info-grid">
                                <div class="hl-info-item">
                                    <span class="hl-info-label"><?php esc_html_e('Visit #:', 'hl-core'); ?></span>
                                    <span class="hl-info-value"><?php echo esc_html($cv_review['visit_number']); ?></span>
                                </div>
                                <div class="hl-info-item">
                                    <span class="hl-info-label"><?php esc_html_e('Date:', 'hl-core'); ?></span>
                                    <span class="hl-info-value"><?php echo esc_html(!empty($cv_review['visit_date']) ? date_i18n(get_option('date_format'), strtotime($cv_review['visit_date'])) : '—'); ?></span>
                                </div>
                                <div class="hl-info-item">
                                    <span class="hl-info-label"><?php esc_html_e('Leader:', 'hl-core'); ?></span>
                                    <span class="hl-info-value"><?php echo esc_html(isset($cv_review['leader_name']) ? $cv_review['leader_name'] : '—'); ?></span>
                                </div>
                            </div>
                            <?php if (!empty($cv_review['submissions'])) : ?>
                                <?php foreach ($cv_review['submissions'] as $sub) :
                                    $sub_data = json_decode($sub['responses_json'], true);
                                    $role_label = ($sub['role_in_visit'] === 'observer')
                                        ? __('Classroom Visit', 'hl-core')
                                        : __('Self-Reflection', 'hl-core');
                                ?>
                                    <div class="hl-cv-submission">
                                        <h6><?php echo esc_html($role_label); ?>
                                            <span class="hl-badge hl-badge-<?php echo $sub['status'] === 'submitted' ? 'green' : 'gray'; ?>">
                                                <?php echo esc_html($sub['status']); ?>
                                            </span>
                                        </h6>
                                        <?php if ($sub_data && is_array($sub_data)) :
                                            $domains = HL_RP_Session_Service::get_ecsel_domains();
                                            foreach ($domains as $dk => $domain) :
                                                if (!isset($sub_data[$dk])) continue;
                                                $domain_data = $sub_data[$dk];
                                        ?>
                                            <div class="hl-cv-domain-summary">
                                                <strong><?php echo esc_html($domain['label']); ?></strong>
                                                <?php if (is_array($domain_data)) : ?>
                                                    <ul class="hl-list hl-list-compact">
                                                        <?php foreach ($domain_data as $indicator_key => $indicator_val) :
                                                            if (!is_array($indicator_val)) continue;
                                                            $observed = !empty($indicator_val['observed']) ? __('Yes', 'hl-core') : __('No', 'hl-core');
                                                        ?>
                                                            <li>
                                                                <span class="hl-badge hl-badge-<?php echo !empty($indicator_val['observed']) ? 'green' : 'gray'; ?>"><?php echo esc_html($observed); ?></span>
                                                                <?php if (!empty($indicator_val['description'])) : ?>
                                                                    — <?php echo esc_html(wp_trim_words($indicator_val['description'], 20)); ?>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>

                <!-- Section 5: RP Session Notes (editable) -->
                <fieldset class="hl-fieldset">
                    <legend><?php esc_html_e('RP Session Notes', 'hl-core'); ?></legend>

                    <?php
                    $note_fields = array(
                        'successes'       => __('Successes', 'hl-core'),
                        'challenges'      => __('Challenges / Areas of Growth', 'hl-core'),
                        'supports_needed' => __('Supports Needed', 'hl-core'),
                        'next_steps'      => __('Next Steps', 'hl-core'),
                    );

                    foreach ($note_fields as $field_key => $field_label) :
                        $field_value = isset($responses[$field_key]) ? $responses[$field_key] : '';
                    ?>
                        <div class="hl-field-group">
                            <label><?php echo esc_html($field_label); ?></label>
                            <?php if ($is_readonly) : ?>
                                <div class="hl-field-value"><?php echo wp_kses_post($field_value); ?></div>
                            <?php else : ?>
                                <?php
                                wp_editor(
                                    $field_value,
                                    'hl_rp_notes_' . $field_key,
                                    array(
                                        'textarea_name' => 'hl_rp_notes[' . $field_key . ']',
                                        'media_buttons' => false,
                                        'textarea_rows' => 4,
                                        'teeny'         => true,
                                        'quicktags'     => false,
                                    )
                                );
                                ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <!-- Next Session Date -->
                    <div class="hl-field-group">
                        <label for="hl-rp-next-date"><?php esc_html_e('Next Session Date', 'hl-core'); ?></label>
                        <?php
                        $next_date = isset($responses['next_session_date']) ? $responses['next_session_date'] : '';
                        ?>
                        <?php if ($is_readonly) : ?>
                            <div class="hl-field-value"><?php echo esc_html($next_date ? date_i18n(get_option('date_format'), strtotime($next_date)) : '—'); ?></div>
                        <?php else : ?>
                            <input type="date" name="hl_rp_notes[next_session_date]" id="hl-rp-next-date"
                                   value="<?php echo esc_attr($next_date); ?>" class="hl-input">
                        <?php endif; ?>
                    </div>
                </fieldset>

                <!-- Section 6: RP Steps Guide (expandable accordion) -->
                <fieldset class="hl-fieldset">
                    <legend><?php esc_html_e('RP Steps Guide', 'hl-core'); ?></legend>
                    <?php $this->render_rp_guide_accordion(); ?>
                </fieldset>

                <?php if (!$is_readonly) : ?>
                    <div class="hl-form-actions">
                        <button type="submit" name="hl_rp_notes_action" value="draft" class="hl-btn hl-btn-secondary">
                            <?php esc_html_e('Save Draft', 'hl-core'); ?>
                        </button>
                        <button type="submit" name="hl_rp_notes_action" value="submit" class="hl-btn hl-btn-primary">
                            <?php esc_html_e('Submit', 'hl-core'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render the RP Steps Guide accordion.
     */
    private function render_rp_guide_accordion() {
        $steps = array(
            'description' => array(
                'title'   => __('Description', 'hl-core'),
                'content' => __('What happened? What did you observe? Describe the situation or event without judgment.', 'hl-core'),
            ),
            'feelings' => array(
                'title'   => __('Feelings', 'hl-core'),
                'content' => __('What were you feeling during and after the event? What do you think the children were feeling?', 'hl-core'),
            ),
            'evaluation' => array(
                'title'   => __('Evaluation', 'hl-core'),
                'content' => __('What went well? What didn\'t go so well? What was positive or negative about the experience?', 'hl-core'),
            ),
            'analysis' => array(
                'title'   => __('Analysis', 'hl-core'),
                'content' => __('Why did things go the way they did? What contributed to the outcome? What could you have done differently?', 'hl-core'),
            ),
            'conclusion' => array(
                'title'   => __('Conclusion', 'hl-core'),
                'content' => __('What did you learn from this experience? What would you do differently next time?', 'hl-core'),
            ),
            'action_plan' => array(
                'title'   => __('Action Plan', 'hl-core'),
                'content' => __('What specific steps will you take going forward? How will you implement what you\'ve learned?', 'hl-core'),
            ),
        );

        echo '<div class="hl-accordion">';
        foreach ($steps as $key => $step) {
            ?>
            <div class="hl-accordion-item">
                <button type="button" class="hl-accordion-toggle" onclick="this.parentElement.classList.toggle('hl-accordion-open')">
                    <span><?php echo esc_html($step['title']); ?></span>
                    <span class="hl-accordion-icon">&#9660;</span>
                </button>
                <div class="hl-accordion-content">
                    <p><?php echo esc_html($step['content']); ?></p>
                </div>
            </div>
            <?php
        }
        echo '</div>';
    }

    /**
     * Handle form POST submission.
     *
     * @param string $context 'coaching' or 'mentoring'
     * @return array|WP_Error
     */
    public static function handle_submission($context = 'mentoring') {
        if (empty($_POST['hl_rp_notes_nonce']) || !wp_verify_nonce($_POST['hl_rp_notes_nonce'], 'hl_rp_notes_submit')) {
            return new WP_Error('nonce_failed', __('Security check failed.', 'hl-core'));
        }

        $user_id       = get_current_user_id();
        $session_id    = absint($_POST['hl_rp_notes_session_id']);
        $instrument_id = absint($_POST['hl_rp_notes_instrument_id']);
        $action        = sanitize_text_field($_POST['hl_rp_notes_action']);
        $status        = ($action === 'submit') ? 'submitted' : 'draft';
        $role          = 'supervisor';

        // Collect responses
        $raw = isset($_POST['hl_rp_notes']) && is_array($_POST['hl_rp_notes']) ? $_POST['hl_rp_notes'] : array();
        $responses = array();
        $responses['personal_notes']    = isset($raw['personal_notes']) ? wp_kses_post($raw['personal_notes']) : '';
        $responses['successes']         = isset($raw['successes']) ? wp_kses_post($raw['successes']) : '';
        $responses['challenges']        = isset($raw['challenges']) ? wp_kses_post($raw['challenges']) : '';
        $responses['supports_needed']   = isset($raw['supports_needed']) ? wp_kses_post($raw['supports_needed']) : '';
        $responses['next_steps']        = isset($raw['next_steps']) ? wp_kses_post($raw['next_steps']) : '';
        $responses['next_session_date'] = isset($raw['next_session_date']) ? sanitize_text_field($raw['next_session_date']) : '';

        $responses_json = wp_json_encode($responses);

        if ($context === 'coaching') {
            $service = new HL_Coaching_Service();
            $result  = $service->submit_form($session_id, $user_id, $instrument_id, $role, $responses_json, $status);
        } else {
            $service = new HL_RP_Session_Service();
            $result  = $service->submit_form($session_id, $user_id, $instrument_id, $role, $responses_json, $status);

            // Update component state for mentor on submit
            if ($status === 'submitted' && !is_wp_error($result)) {
                $session = $service->get_session($session_id);
                if ($session) {
                    $enrollment_id  = (int) $session['mentor_enrollment_id'];
                    $cycle_id       = (int) $session['cycle_id'];
                    $session_number = (int) $session['session_number'];
                    $service->update_component_state($enrollment_id, $cycle_id, $session_number);
                }
            }
        }

        return array(
            'submission_id' => $result,
            'status'        => $status,
            'message'       => ($status === 'submitted') ? 'submitted' : 'saved',
        );
    }
}
