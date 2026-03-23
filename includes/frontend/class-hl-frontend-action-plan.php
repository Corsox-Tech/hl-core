<?php
if (!defined('ABSPATH')) exit;

/**
 * Action Plan & Results form renderer.
 *
 * Used by both coaching (Mentor fills) and mentoring (Teacher fills) contexts.
 * Reads instrument sections JSON for domain/skills and renders the form with
 * conditional dropdowns, draft save via AJAX, and submit via POST.
 *
 * @package HL_Core
 */
class HL_Frontend_Action_Plan {

    /**
     * Render the Action Plan form.
     *
     * @param string      $context             'coaching' or 'mentoring'
     * @param array       $session_entity      Session row (coaching_session or rp_session)
     * @param object      $enrollment          Enrollment object
     * @param object      $instrument          Instrument row from hl_teacher_assessment_instrument
     * @param array|null  $existing_submission Existing submission row, or null
     * @return string HTML
     */
    public function render($context, $session_entity, $enrollment, $instrument, $existing_submission = null) {
        ob_start();

        $sections    = json_decode($instrument->sections, true) ?: array();
        $responses   = $existing_submission ? json_decode($existing_submission['responses_json'], true) : array();
        $is_readonly = ($existing_submission && $existing_submission['status'] === 'submitted');
        $is_draft    = ($existing_submission && $existing_submission['status'] === 'draft');

        // Build domain/skills maps from instrument sections
        $planning_section = null;
        $results_section  = null;
        foreach ($sections as $section) {
            if ($section['key'] === 'planning') {
                $planning_section = $section;
            } elseif ($section['key'] === 'results') {
                $results_section = $section;
            }
        }

        $domain_options   = array();
        $skills_by_domain = array();
        if ($planning_section) {
            foreach ($planning_section['fields'] as $field) {
                if ($field['key'] === 'domain' && !empty($field['options'])) {
                    $domain_options = $field['options'];
                }
                if ($field['key'] === 'skills' && !empty($field['options_by_domain'])) {
                    $skills_by_domain = $field['options_by_domain'];
                }
            }
        }

        // Session identifiers for form submission
        $session_id_key   = ($context === 'coaching') ? 'session_id' : 'rp_session_id';
        $session_id_value = isset($session_entity[$session_id_key]) ? (int) $session_entity[$session_id_key] : 0;
        $instrument_id    = (int) $instrument->instrument_id;

        $selected_domain = isset($responses['domain']) ? $responses['domain'] : '';
        $selected_skills = isset($responses['skills']) && is_array($responses['skills']) ? $responses['skills'] : array();

        ?>
        <div class="hl-form hl-action-plan-form">
            <h3><?php esc_html_e('Action Plan & Results', 'hl-core'); ?></h3>

            <?php if ($is_readonly) : ?>
                <div class="hl-notice hl-notice-info">
                    <p><?php esc_html_e('This form has been submitted and is read-only.', 'hl-core'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" id="hl-action-plan-form">
                <?php if (!$is_readonly) : ?>
                    <?php wp_nonce_field('hl_action_plan_submit', 'hl_action_plan_nonce'); ?>
                    <input type="hidden" name="hl_action_plan_context" value="<?php echo esc_attr($context); ?>">
                    <input type="hidden" name="hl_action_plan_session_id" value="<?php echo esc_attr($session_id_value); ?>">
                    <input type="hidden" name="hl_action_plan_instrument_id" value="<?php echo esc_attr($instrument_id); ?>">
                <?php endif; ?>

                <!-- Planning Section -->
                <fieldset class="hl-fieldset">
                    <legend><?php esc_html_e('Planning', 'hl-core'); ?></legend>

                    <!-- Domain -->
                    <div class="hl-field-group">
                        <label for="hl-ap-domain"><?php esc_html_e('Domain', 'hl-core'); ?></label>
                        <?php if ($is_readonly) : ?>
                            <div class="hl-field-value"><?php echo esc_html(isset($domain_options[$selected_domain]) ? $domain_options[$selected_domain] : $selected_domain); ?></div>
                        <?php else : ?>
                            <select name="hl_ap[domain]" id="hl-ap-domain" class="hl-select">
                                <option value=""><?php esc_html_e('— Select a domain —', 'hl-core'); ?></option>
                                <?php foreach ($domain_options as $key => $label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($selected_domain, $key); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <!-- Skills/Strategy (conditional on domain) -->
                    <div class="hl-field-group" id="hl-ap-skills-group" style="<?php echo empty($selected_domain) ? 'display:none;' : ''; ?>">
                        <label><?php esc_html_e('Skills/Strategy', 'hl-core'); ?></label>
                        <?php if ($is_readonly) : ?>
                            <div class="hl-field-value">
                                <?php if (!empty($selected_skills)) : ?>
                                    <ul class="hl-list">
                                        <?php foreach ($selected_skills as $skill) : ?>
                                            <li><?php echo esc_html($skill); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else : ?>
                                    <span class="hl-muted"><?php esc_html_e('None selected', 'hl-core'); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php else : ?>
                            <?php foreach ($skills_by_domain as $domain_key => $skills) : ?>
                                <div class="hl-skills-options" data-domain="<?php echo esc_attr($domain_key); ?>"
                                     style="<?php echo ($selected_domain !== $domain_key) ? 'display:none;' : ''; ?>">
                                    <?php foreach ($skills as $idx => $skill) : ?>
                                        <label class="hl-checkbox-label">
                                            <input type="checkbox"
                                                   name="hl_ap[skills][]"
                                                   value="<?php echo esc_attr($skill); ?>"
                                                   <?php checked(in_array($skill, $selected_skills, true)); ?>>
                                            <?php echo esc_html($skill); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- HOW -->
                    <div class="hl-field-group">
                        <label for="hl-ap-how"><?php esc_html_e('Describe HOW you will practice the skill(s)', 'hl-core'); ?></label>
                        <?php if ($is_readonly) : ?>
                            <div class="hl-field-value"><?php echo wp_kses_post(isset($responses['how']) ? $responses['how'] : ''); ?></div>
                        <?php else : ?>
                            <textarea name="hl_ap[how]" id="hl-ap-how" rows="4" class="hl-textarea"><?php echo esc_textarea(isset($responses['how']) ? $responses['how'] : ''); ?></textarea>
                        <?php endif; ?>
                    </div>

                    <!-- WHAT -->
                    <div class="hl-field-group">
                        <label for="hl-ap-what"><?php esc_html_e('WHAT behaviors will you track to know this is effective?', 'hl-core'); ?></label>
                        <?php if ($is_readonly) : ?>
                            <div class="hl-field-value"><?php echo wp_kses_post(isset($responses['what']) ? $responses['what'] : ''); ?></div>
                        <?php else : ?>
                            <textarea name="hl_ap[what]" id="hl-ap-what" rows="4" class="hl-textarea"><?php echo esc_textarea(isset($responses['what']) ? $responses['what'] : ''); ?></textarea>
                        <?php endif; ?>
                    </div>
                </fieldset>

                <!-- Results Section -->
                <fieldset class="hl-fieldset">
                    <legend><?php esc_html_e('Results', 'hl-core'); ?></legend>

                    <!-- Practice Reflection -->
                    <div class="hl-field-group">
                        <label for="hl-ap-practice-reflection"><?php esc_html_e('From your perspective, how has your practice gone?', 'hl-core'); ?></label>
                        <?php if ($is_readonly) : ?>
                            <div class="hl-field-value"><?php echo wp_kses_post(isset($responses['practice_reflection']) ? $responses['practice_reflection'] : ''); ?></div>
                        <?php else : ?>
                            <textarea name="hl_ap[practice_reflection]" id="hl-ap-practice-reflection" rows="4" class="hl-textarea"><?php echo esc_textarea(isset($responses['practice_reflection']) ? $responses['practice_reflection'] : ''); ?></textarea>
                        <?php endif; ?>
                    </div>

                    <!-- Degree of Success (Likert) -->
                    <div class="hl-field-group">
                        <label><?php esc_html_e('Degree of success', 'hl-core'); ?></label>
                        <?php
                        $scale = array(
                            1 => __('Not at all Successful', 'hl-core'),
                            2 => __('Slightly Successful', 'hl-core'),
                            3 => __('Moderately Successful', 'hl-core'),
                            4 => __('Very Successful', 'hl-core'),
                            5 => __('Extremely Successful', 'hl-core'),
                        );
                        $current_rating = isset($responses['success_degree']) ? (int) $responses['success_degree'] : 0;
                        ?>
                        <?php if ($is_readonly) : ?>
                            <div class="hl-field-value">
                                <?php echo esc_html(isset($scale[$current_rating]) ? $scale[$current_rating] : '—'); ?>
                            </div>
                        <?php else : ?>
                            <div class="hl-likert-scale">
                                <?php foreach ($scale as $value => $label) : ?>
                                    <label class="hl-likert-option">
                                        <input type="radio" name="hl_ap[success_degree]" value="<?php echo esc_attr($value); ?>"
                                            <?php checked($current_rating, $value); ?>>
                                        <span class="hl-likert-label"><?php echo esc_html($label); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Impact Observations -->
                    <div class="hl-field-group">
                        <label for="hl-ap-impact"><?php esc_html_e('Observations of impact on students', 'hl-core'); ?></label>
                        <?php if ($is_readonly) : ?>
                            <div class="hl-field-value"><?php echo wp_kses_post(isset($responses['impact_observations']) ? $responses['impact_observations'] : ''); ?></div>
                        <?php else : ?>
                            <textarea name="hl_ap[impact_observations]" id="hl-ap-impact" rows="4" class="hl-textarea"><?php echo esc_textarea(isset($responses['impact_observations']) ? $responses['impact_observations'] : ''); ?></textarea>
                        <?php endif; ?>
                    </div>

                    <!-- What Learned -->
                    <div class="hl-field-group">
                        <label for="hl-ap-learned"><?php esc_html_e('What you learned', 'hl-core'); ?></label>
                        <?php if ($is_readonly) : ?>
                            <div class="hl-field-value"><?php echo wp_kses_post(isset($responses['what_learned']) ? $responses['what_learned'] : ''); ?></div>
                        <?php else : ?>
                            <textarea name="hl_ap[what_learned]" id="hl-ap-learned" rows="4" class="hl-textarea"><?php echo esc_textarea(isset($responses['what_learned']) ? $responses['what_learned'] : ''); ?></textarea>
                        <?php endif; ?>
                    </div>

                    <!-- Still Wondering -->
                    <div class="hl-field-group">
                        <label for="hl-ap-wondering"><?php esc_html_e('What you\'re still wondering', 'hl-core'); ?></label>
                        <?php if ($is_readonly) : ?>
                            <div class="hl-field-value"><?php echo wp_kses_post(isset($responses['still_wondering']) ? $responses['still_wondering'] : ''); ?></div>
                        <?php else : ?>
                            <textarea name="hl_ap[still_wondering]" id="hl-ap-wondering" rows="4" class="hl-textarea"><?php echo esc_textarea(isset($responses['still_wondering']) ? $responses['still_wondering'] : ''); ?></textarea>
                        <?php endif; ?>
                    </div>
                </fieldset>

                <?php if (!$is_readonly) : ?>
                    <div class="hl-form-actions">
                        <button type="submit" name="hl_action_plan_action" value="draft" class="hl-btn hl-btn-secondary">
                            <?php esc_html_e('Save Draft', 'hl-core'); ?>
                        </button>
                        <button type="submit" name="hl_action_plan_action" value="submit" class="hl-btn hl-btn-primary">
                            <?php esc_html_e('Submit', 'hl-core'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- JS for conditional skills -->
        <script>
        (function(){
            var domainSelect = document.getElementById('hl-ap-domain');
            var skillsGroup = document.getElementById('hl-ap-skills-group');
            if (!domainSelect || !skillsGroup) return;

            function updateSkills() {
                var val = domainSelect.value;
                skillsGroup.style.display = val ? '' : 'none';
                var blocks = skillsGroup.querySelectorAll('.hl-skills-options');
                for (var i = 0; i < blocks.length; i++) {
                    blocks[i].style.display = (blocks[i].getAttribute('data-domain') === val) ? '' : 'none';
                    if (blocks[i].getAttribute('data-domain') !== val) {
                        var checkboxes = blocks[i].querySelectorAll('input[type="checkbox"]');
                        for (var j = 0; j < checkboxes.length; j++) {
                            checkboxes[j].checked = false;
                        }
                    }
                }
            }
            domainSelect.addEventListener('change', updateSkills);
        })();
        </script>
        <?php

        return ob_get_clean();
    }

    /**
     * Handle form POST submission.
     *
     * Called from the page controller (RP Session or My Coaching).
     *
     * @param string $context 'coaching' or 'mentoring'
     * @return array|WP_Error Result with redirect URL on success.
     */
    public static function handle_submission($context = 'mentoring') {
        if (empty($_POST['hl_action_plan_nonce']) || !wp_verify_nonce($_POST['hl_action_plan_nonce'], 'hl_action_plan_submit')) {
            return new WP_Error('nonce_failed', __('Security check failed.', 'hl-core'));
        }

        $user_id       = get_current_user_id();
        $session_id    = absint($_POST['hl_action_plan_session_id']);
        $instrument_id = absint($_POST['hl_action_plan_instrument_id']);
        $action        = sanitize_text_field($_POST['hl_action_plan_action']);
        $status        = ($action === 'submit') ? 'submitted' : 'draft';
        $role          = 'supervisee';

        // Collect responses
        $raw = isset($_POST['hl_ap']) && is_array($_POST['hl_ap']) ? $_POST['hl_ap'] : array();
        $responses = array();
        $responses['domain']              = isset($raw['domain']) ? sanitize_text_field($raw['domain']) : '';
        $responses['skills']              = isset($raw['skills']) && is_array($raw['skills']) ? array_map('sanitize_text_field', $raw['skills']) : array();
        $responses['how']                 = isset($raw['how']) ? wp_kses_post($raw['how']) : '';
        $responses['what']                = isset($raw['what']) ? wp_kses_post($raw['what']) : '';
        $responses['practice_reflection'] = isset($raw['practice_reflection']) ? wp_kses_post($raw['practice_reflection']) : '';
        $responses['success_degree']      = isset($raw['success_degree']) ? absint($raw['success_degree']) : 0;
        $responses['impact_observations'] = isset($raw['impact_observations']) ? wp_kses_post($raw['impact_observations']) : '';
        $responses['what_learned']        = isset($raw['what_learned']) ? wp_kses_post($raw['what_learned']) : '';
        $responses['still_wondering']     = isset($raw['still_wondering']) ? wp_kses_post($raw['still_wondering']) : '';

        $responses_json = wp_json_encode($responses);

        if ($context === 'coaching') {
            $service = new HL_Coaching_Service();
            $result  = $service->submit_form($session_id, $user_id, $instrument_id, $role, $responses_json, $status);
        } else {
            $service = new HL_RP_Session_Service();
            $result  = $service->submit_form($session_id, $user_id, $instrument_id, $role, $responses_json, $status);

            // Update component state on submit
            if ($status === 'submitted' && !is_wp_error($result)) {
                $session = $service->get_session($session_id);
                if ($session) {
                    $enrollment_id = (int) $session['teacher_enrollment_id'];
                    $cycle_id      = (int) $session['cycle_id'];
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
