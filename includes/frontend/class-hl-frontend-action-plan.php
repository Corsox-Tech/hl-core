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
     * Validation errors from a failed submit, keyed by field (domain, skills, how, what).
     * Populated by handle_submission() and consumed by render() on the same request.
     *
     * @var array<string,string>
     */
    public static $validation_errors = array();

    /**
     * Sanitized POST values from a failed submit, so the re-rendered form re-populates
     * the user's input instead of resetting to the stored draft.
     *
     * @var array|null
     */
    public static $posted_values = null;

    /**
     * Labels for the four required fields, used in the top-of-form error summary.
     *
     * @return array<string,string>
     */
    private static function required_field_labels() {
        return array(
            'domain' => __('Domain', 'hl-core'),
            'skills' => __('Skills / Strategy', 'hl-core'),
            'how'    => __('HOW you will practice', 'hl-core'),
            'what'   => __('WHAT behaviors to track', 'hl-core'),
        );
    }

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
        // Teachers (mentoring context) can always edit their Action Plan
        $is_readonly = ($context !== 'mentoring') && ($existing_submission && $existing_submission['status'] === 'submitted');
        $is_draft    = ($existing_submission && $existing_submission['status'] === 'draft');

        // If a submit just failed validation on this request, re-populate the form with
        // what the user typed (rather than the stale draft) and surface the errors.
        $errors = !$is_readonly ? self::$validation_errors : array();
        if (!$is_readonly && is_array(self::$posted_values)) {
            $responses = array_merge(is_array($responses) ? $responses : array(), self::$posted_values);
        }

        // Build domain/skills maps from instrument sections.
        // Sections may use 'key' or 'title' (lowercased) for identification.
        $planning_section = null;
        $results_section  = null;
        foreach ($sections as $section) {
            $section_id = isset($section['key']) ? $section['key'] : strtolower($section['title'] ?? '');
            if ($section_id === 'planning') {
                $planning_section = $section;
            } elseif ($section_id === 'results') {
                $results_section = $section;
            }
        }

        $domain_options   = array();
        $skills_by_domain = array();
        if ($planning_section) {
            foreach ($planning_section['fields'] as $field) {
                if ($field['key'] === 'domain' && !empty($field['options'])) {
                    // Handle flat array (["Label1", "Label2"]) → keyed map (slug => label)
                    if (isset($field['options'][0]) && is_string($field['options'][0])) {
                        foreach ($field['options'] as $label) {
                            $domain_options[sanitize_title($label)] = $label;
                        }
                    } else {
                        $domain_options = $field['options'];
                    }
                }
                if ($field['key'] === 'skills') {
                    if (!empty($field['options_by_domain'])) {
                        $skills_by_domain = $field['options_by_domain'];
                    } elseif (!empty($field['options_map'])) {
                        // Convert [{name, skills}] → {slug => [skills]}
                        foreach ($field['options_map'] as $entry) {
                            $key = sanitize_title($entry['name']);
                            $skills_by_domain[$key] = $entry['skills'];
                        }
                    }
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
        <div class="hlap-form-wrapper">

            <!-- Hero header -->
            <div class="hlap-hero">
                <div class="hlap-hero-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <circle cx="12" cy="12" r="6"></circle>
                        <circle cx="12" cy="12" r="2"></circle>
                    </svg>
                </div>
                <div>
                    <h2 class="hlap-hero-title"><?php esc_html_e('Action Plan & Results', 'hl-core'); ?></h2>
                    <p class="hlap-hero-sub"><?php printf(esc_html__('ECSEL Skill Development — %s Context', 'hl-core'), esc_html(ucfirst($context))); ?></p>
                </div>
            </div>

            <?php if ($is_readonly) : ?>
                <div class="hlap-alert hlap-alert-info">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <?php esc_html_e('This form has been submitted and is read-only.', 'hl-core'); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)) :
                $labels = self::required_field_labels();
                ?>
                <div class="hlap-alert hlap-alert-error" id="hlap-error-summary" role="alert" aria-live="polite">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div>
                        <strong><?php esc_html_e('Please complete the required fields before submitting.', 'hl-core'); ?></strong>
                        <ul class="hlap-error-list">
                            <?php foreach ($errors as $field_key => $msg) :
                                $label = isset($labels[$field_key]) ? $labels[$field_key] : $field_key;
                                ?>
                                <li><a href="#hl-ap-<?php echo esc_attr($field_key); ?>-anchor"><?php echo esc_html($label); ?></a> — <?php echo esc_html($msg); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
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
                <div class="hlap-section">
                    <div class="hlap-section-header">
                        <span class="hlap-section-num">1</span>
                        <span class="hlap-section-title"><?php esc_html_e('Planning', 'hl-core'); ?></span>
                    </div>
                    <div class="hlap-section-body">

                        <!-- Domain -->
                        <div class="hlap-field<?php echo isset($errors['domain']) ? ' has-error' : ''; ?>" data-required-field="domain">
                            <span class="hlap-anchor" id="hl-ap-domain-anchor"></span>
                            <label class="hlap-field-label" for="hl-ap-domain">
                                <?php esc_html_e('Domain', 'hl-core'); ?>
                                <?php if (!$is_readonly) : ?><span class="hlap-required" aria-label="<?php esc_attr_e('required', 'hl-core'); ?>">*</span><?php endif; ?>
                            </label>
                            <?php if ($is_readonly) : ?>
                                <div class="hlap-readonly-value">
                                    <span class="hlap-domain-badge"><?php echo esc_html(isset($domain_options[$selected_domain]) ? $domain_options[$selected_domain] : $selected_domain); ?></span>
                                </div>
                            <?php else : ?>
                                <select name="hl_ap[domain]" id="hl-ap-domain" class="hlap-select"
                                        aria-required="true"
                                        <?php if (isset($errors['domain'])) : ?>aria-invalid="true" aria-describedby="hl-ap-domain-error"<?php endif; ?>>
                                    <option value=""><?php esc_html_e('— Select a domain —', 'hl-core'); ?></option>
                                    <?php foreach ($domain_options as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($selected_domain, $key); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['domain'])) : ?>
                                    <p class="hlap-field-error-text" id="hl-ap-domain-error"><?php echo esc_html($errors['domain']); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Skills/Strategy (conditional on domain) -->
                        <div class="hlap-field<?php echo isset($errors['skills']) ? ' has-error' : ''; ?>" id="hl-ap-skills-group" data-required-field="skills" style="<?php echo empty($selected_domain) ? 'display:none;' : ''; ?>">
                            <span class="hlap-anchor" id="hl-ap-skills-anchor"></span>
                            <label class="hlap-field-label">
                                <?php esc_html_e('Skills / Strategy', 'hl-core'); ?>
                                <?php if (!$is_readonly) : ?><span class="hlap-required" aria-label="<?php esc_attr_e('required', 'hl-core'); ?>">*</span><?php endif; ?>
                            </label>
                            <?php if ($is_readonly) : ?>
                                <div class="hlap-pills-ro">
                                    <?php if (!empty($selected_skills)) : ?>
                                        <?php foreach ($selected_skills as $skill) : ?>
                                            <span class="hlap-pill-ro"><?php echo esc_html($skill); ?></span>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <span class="hlap-muted"><?php esc_html_e('None selected', 'hl-core'); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php else : ?>
                                <?php foreach ($skills_by_domain as $domain_key => $skills) : ?>
                                    <div class="hlap-pills" data-domain="<?php echo esc_attr($domain_key); ?>"
                                         style="<?php echo ($selected_domain !== $domain_key) ? 'display:none;' : ''; ?>">
                                        <?php foreach ($skills as $idx => $skill) : ?>
                                            <label class="hlap-pill">
                                                <input type="checkbox"
                                                       name="hl_ap[skills][]"
                                                       value="<?php echo esc_attr($skill); ?>"
                                                       <?php checked(in_array($skill, $selected_skills, true)); ?>>
                                                <span class="hlap-pill-label">
                                                    <span class="hlap-pill-dot"></span>
                                                    <?php echo esc_html($skill); ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (isset($errors['skills'])) : ?>
                                    <p class="hlap-field-error-text" id="hl-ap-skills-error"><?php echo esc_html($errors['skills']); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- HOW -->
                        <div class="hlap-field<?php echo isset($errors['how']) ? ' has-error' : ''; ?>" data-required-field="how">
                            <span class="hlap-anchor" id="hl-ap-how-anchor"></span>
                            <label class="hlap-field-label" for="hl-ap-how">
                                <?php esc_html_e('Describe HOW you will practice the skill(s)', 'hl-core'); ?>
                                <?php if (!$is_readonly) : ?><span class="hlap-required" aria-label="<?php esc_attr_e('required', 'hl-core'); ?>">*</span><?php endif; ?>
                            </label>
                            <?php if ($is_readonly) : ?>
                                <div class="hlap-readonly-value"><?php echo wp_kses_post(isset($responses['how']) ? $responses['how'] : ''); ?></div>
                            <?php else : ?>
                                <textarea name="hl_ap[how]" id="hl-ap-how" rows="4" class="hlap-textarea"
                                          aria-required="true"
                                          <?php if (isset($errors['how'])) : ?>aria-invalid="true" aria-describedby="hl-ap-how-error"<?php endif; ?>
                                          placeholder="<?php esc_attr_e('Describe the specific activities and strategies you plan to use...', 'hl-core'); ?>"
                                ><?php echo esc_textarea(isset($responses['how']) ? $responses['how'] : ''); ?></textarea>
                                <?php if (isset($errors['how'])) : ?>
                                    <p class="hlap-field-error-text" id="hl-ap-how-error"><?php echo esc_html($errors['how']); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- WHAT -->
                        <div class="hlap-field<?php echo isset($errors['what']) ? ' has-error' : ''; ?>" data-required-field="what">
                            <span class="hlap-anchor" id="hl-ap-what-anchor"></span>
                            <label class="hlap-field-label" for="hl-ap-what">
                                <?php esc_html_e('WHAT behaviors will you track to know this is effective?', 'hl-core'); ?>
                                <?php if (!$is_readonly) : ?><span class="hlap-required" aria-label="<?php esc_attr_e('required', 'hl-core'); ?>">*</span><?php endif; ?>
                            </label>
                            <?php if ($is_readonly) : ?>
                                <div class="hlap-readonly-value"><?php echo wp_kses_post(isset($responses['what']) ? $responses['what'] : ''); ?></div>
                            <?php else : ?>
                                <textarea name="hl_ap[what]" id="hl-ap-what" rows="4" class="hlap-textarea"
                                          aria-required="true"
                                          <?php if (isset($errors['what'])) : ?>aria-invalid="true" aria-describedby="hl-ap-what-error"<?php endif; ?>
                                          placeholder="<?php esc_attr_e('What observable changes will indicate success?', 'hl-core'); ?>"
                                ><?php echo esc_textarea(isset($responses['what']) ? $responses['what'] : ''); ?></textarea>
                                <?php if (isset($errors['what'])) : ?>
                                    <p class="hlap-field-error-text" id="hl-ap-what-error"><?php echo esc_html($errors['what']); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Results Section -->
                <div class="hlap-section">
                    <div class="hlap-section-header">
                        <span class="hlap-section-num">2</span>
                        <span class="hlap-section-title"><?php esc_html_e('Results', 'hl-core'); ?></span>
                    </div>
                    <div class="hlap-section-body">

                        <!-- Practice Reflection -->
                        <div class="hlap-field">
                            <label class="hlap-field-label" for="hl-ap-practice-reflection"><?php esc_html_e('From your perspective, how has your practice gone?', 'hl-core'); ?></label>
                            <?php if ($is_readonly) : ?>
                                <div class="hlap-readonly-value"><?php echo wp_kses_post(isset($responses['practice_reflection']) ? $responses['practice_reflection'] : ''); ?></div>
                            <?php else : ?>
                                <textarea name="hl_ap[practice_reflection]" id="hl-ap-practice-reflection" rows="4" class="hlap-textarea"
                                          placeholder="<?php esc_attr_e('Reflect on your experience implementing the plan...', 'hl-core'); ?>"
                                ><?php echo esc_textarea(isset($responses['practice_reflection']) ? $responses['practice_reflection'] : ''); ?></textarea>
                            <?php endif; ?>
                        </div>

                        <!-- Degree of Success (Likert) -->
                        <div class="hlap-field">
                            <label class="hlap-field-label"><?php esc_html_e('Degree of Success', 'hl-core'); ?></label>
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
                                <div class="hlap-readonly-value">
                                    <?php if (isset($scale[$current_rating])) : ?>
                                        <span class="hlap-likert-badge hlap-likert-<?php echo esc_attr($current_rating); ?>">
                                            <?php echo esc_html($scale[$current_rating]); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="hlap-muted"><?php esc_html_e('Not rated', 'hl-core'); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php else : ?>
                                <div class="hlap-likert">
                                    <?php foreach ($scale as $value => $label) : ?>
                                        <label class="hlap-likert-option">
                                            <input type="radio" name="hl_ap[success_degree]" value="<?php echo esc_attr($value); ?>"
                                                <?php checked($current_rating, $value); ?>>
                                            <span class="hlap-likert-btn">
                                                <span class="hlap-likert-num"><?php echo esc_html($value); ?></span>
                                                <span class="hlap-likert-text"><?php echo esc_html($label); ?></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Impact Observations -->
                        <div class="hlap-field">
                            <label class="hlap-field-label" for="hl-ap-impact"><?php esc_html_e('Observations of impact on students', 'hl-core'); ?></label>
                            <?php if ($is_readonly) : ?>
                                <div class="hlap-readonly-value"><?php echo wp_kses_post(isset($responses['impact_observations']) ? $responses['impact_observations'] : ''); ?></div>
                            <?php else : ?>
                                <textarea name="hl_ap[impact_observations]" id="hl-ap-impact" rows="4" class="hlap-textarea"
                                          placeholder="<?php esc_attr_e('What changes did you notice in student behavior or engagement?', 'hl-core'); ?>"
                                ><?php echo esc_textarea(isset($responses['impact_observations']) ? $responses['impact_observations'] : ''); ?></textarea>
                            <?php endif; ?>
                        </div>

                        <!-- What Learned -->
                        <div class="hlap-field">
                            <label class="hlap-field-label" for="hl-ap-learned"><?php esc_html_e('What you learned', 'hl-core'); ?></label>
                            <?php if ($is_readonly) : ?>
                                <div class="hlap-readonly-value"><?php echo wp_kses_post(isset($responses['what_learned']) ? $responses['what_learned'] : ''); ?></div>
                            <?php else : ?>
                                <textarea name="hl_ap[what_learned]" id="hl-ap-learned" rows="4" class="hlap-textarea"
                                          placeholder="<?php esc_attr_e('Key takeaways from implementing your plan...', 'hl-core'); ?>"
                                ><?php echo esc_textarea(isset($responses['what_learned']) ? $responses['what_learned'] : ''); ?></textarea>
                            <?php endif; ?>
                        </div>

                        <!-- Still Wondering -->
                        <div class="hlap-field">
                            <label class="hlap-field-label" for="hl-ap-wondering"><?php esc_html_e('What you\'re still wondering', 'hl-core'); ?></label>
                            <?php if ($is_readonly) : ?>
                                <div class="hlap-readonly-value"><?php echo wp_kses_post(isset($responses['still_wondering']) ? $responses['still_wondering'] : ''); ?></div>
                            <?php else : ?>
                                <textarea name="hl_ap[still_wondering]" id="hl-ap-wondering" rows="4" class="hlap-textarea"
                                          placeholder="<?php esc_attr_e('Questions or areas you want to explore further...', 'hl-core'); ?>"
                                ><?php echo esc_textarea(isset($responses['still_wondering']) ? $responses['still_wondering'] : ''); ?></textarea>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!$is_readonly) : ?>
                    <div class="hlap-actions">
                        <button type="submit" name="hl_action_plan_action" value="draft" class="hlap-btn hlap-btn-draft">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <?php esc_html_e('Save Draft', 'hl-core'); ?>
                        </button>
                        <button type="submit" name="hl_action_plan_action" value="submit" class="hlap-btn hlap-btn-submit">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php esc_html_e('Submit Action Plan', 'hl-core'); ?>
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
                var blocks = skillsGroup.querySelectorAll('.hlap-pills');
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

        <!-- JS for submit-time required-field validation (Save Draft bypasses) -->
        <script>
        (function(){
            var form = document.getElementById('hl-action-plan-form');
            if (!form) return;
            var submitBtn = form.querySelector('button[value="submit"]');
            if (!submitBtn) return;

            var requiredLabels = {
                domain: <?php echo wp_json_encode(__('Domain is required.', 'hl-core')); ?>,
                skills: <?php echo wp_json_encode(__('Select at least one skill.', 'hl-core')); ?>,
                how:    <?php echo wp_json_encode(__('Describe how you will practice.', 'hl-core')); ?>,
                what:   <?php echo wp_json_encode(__('Describe what behaviors you will track.', 'hl-core')); ?>
            };
            var summaryTitle = <?php echo wp_json_encode(__('Please complete the required fields before submitting.', 'hl-core')); ?>;

            function getField(key) {
                return form.querySelector('[data-required-field="' + key + '"]');
            }

            function clearErrors() {
                var fields = form.querySelectorAll('[data-required-field].has-error');
                for (var i = 0; i < fields.length; i++) {
                    fields[i].classList.remove('has-error');
                    var existing = fields[i].querySelector('.hlap-field-error-text');
                    if (existing) existing.parentNode.removeChild(existing);
                }
                var existingSummary = document.getElementById('hlap-error-summary');
                if (existingSummary) existingSummary.parentNode.removeChild(existingSummary);
            }

            function markError(key, message) {
                var field = getField(key);
                if (!field) return;
                field.classList.add('has-error');
                if (field.querySelector('.hlap-field-error-text')) return;
                var p = document.createElement('p');
                p.className = 'hlap-field-error-text';
                p.id = 'hl-ap-' + key + '-error';
                p.textContent = message;
                field.appendChild(p);
            }

            function renderSummary(invalidKeys) {
                var wrapper = form.parentNode;
                var banner = document.createElement('div');
                banner.className = 'hlap-alert hlap-alert-error';
                banner.id = 'hlap-error-summary';
                banner.setAttribute('role', 'alert');
                banner.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
                var body = document.createElement('div');
                var strong = document.createElement('strong');
                strong.textContent = summaryTitle;
                body.appendChild(strong);
                var ul = document.createElement('ul');
                ul.className = 'hlap-error-list';
                for (var i = 0; i < invalidKeys.length; i++) {
                    var li = document.createElement('li');
                    li.textContent = requiredLabels[invalidKeys[i]];
                    ul.appendChild(li);
                }
                body.appendChild(ul);
                banner.appendChild(body);
                // Insert above form
                wrapper.insertBefore(banner, form);
            }

            function validate() {
                var invalid = [];

                // Domain
                var domainEl = document.getElementById('hl-ap-domain');
                if (!domainEl || !domainEl.value) invalid.push('domain');

                // Skills — at least 1 checked within the currently-visible domain block
                var checked = form.querySelectorAll('input[name="hl_ap[skills][]"]:checked');
                if (checked.length === 0) invalid.push('skills');

                // HOW + WHAT — non-empty trimmed
                var howEl = document.getElementById('hl-ap-how');
                if (!howEl || !howEl.value.replace(/\s+/g, '')) invalid.push('how');
                var whatEl = document.getElementById('hl-ap-what');
                if (!whatEl || !whatEl.value.replace(/\s+/g, '')) invalid.push('what');

                return invalid;
            }

            submitBtn.addEventListener('click', function(e){
                clearErrors();
                var invalid = validate();
                if (invalid.length === 0) return; // let form submit normally

                e.preventDefault();
                for (var i = 0; i < invalid.length; i++) {
                    markError(invalid[i], requiredLabels[invalid[i]]);
                }
                renderSummary(invalid);

                // Scroll to first invalid field
                var first = getField(invalid[0]);
                if (first && first.scrollIntoView) {
                    first.scrollIntoView({behavior: 'smooth', block: 'center'});
                }
            });
        })();
        </script>
        <?php

        return ob_get_clean();
    }

    // Styles are in assets/css/frontend.css under "FORMS & INSTRUMENTS (Session 2)"

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

        // Required-field validation — only enforced on submit. Save Draft bypasses
        // so users can park incomplete work.
        if ($status === 'submitted') {
            $errors = self::validate_required($responses);
            if (!empty($errors)) {
                // Stash state so render() on the same request re-populates the form
                // with the user's input and shows inline + summary errors.
                self::$validation_errors = $errors;
                self::$posted_values     = $responses;
                return new WP_Error(
                    'hlap_validation',
                    __('Please complete the required fields before submitting your Action Plan.', 'hl-core'),
                    array( 'fields' => $errors )
                );
            }
        }

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

    /**
     * Validate the four required Planning fields for a submit action.
     *
     * Returns an empty array when the payload is valid, or a map of
     * field_key => user-facing error message when it isn't.
     *
     * @param array $responses Sanitized responses.
     * @return array<string,string>
     */
    private static function validate_required( array $responses ) {
        $errors = array();

        if ( empty( $responses['domain'] ) ) {
            $errors['domain'] = __( 'Please select a domain.', 'hl-core' );
        }

        $skills = isset( $responses['skills'] ) ? $responses['skills'] : array();
        if ( ! is_array( $skills ) || count( $skills ) === 0 ) {
            $errors['skills'] = __( 'Please select at least one skill.', 'hl-core' );
        }

        if ( ! isset( $responses['how'] ) || trim( wp_strip_all_tags( (string) $responses['how'] ) ) === '' ) {
            $errors['how'] = __( 'Please describe how you will practice the skill(s).', 'hl-core' );
        }

        if ( ! isset( $responses['what'] ) || trim( wp_strip_all_tags( (string) $responses['what'] ) ) === '' ) {
            $errors['what'] = __( 'Please describe what behaviors you will track.', 'hl-core' );
        }

        return $errors;
    }
}
