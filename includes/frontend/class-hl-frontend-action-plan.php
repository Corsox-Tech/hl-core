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
        // Teachers (mentoring context) can always edit their Action Plan
        $is_readonly = ($context !== 'mentoring') && ($existing_submission && $existing_submission['status'] === 'submitted');
        $is_draft    = ($existing_submission && $existing_submission['status'] === 'draft');

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

        self::render_styles();
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
                        <div class="hlap-field">
                            <label class="hlap-field-label" for="hl-ap-domain"><?php esc_html_e('Domain', 'hl-core'); ?></label>
                            <?php if ($is_readonly) : ?>
                                <div class="hlap-readonly-value">
                                    <span class="hlap-domain-badge"><?php echo esc_html(isset($domain_options[$selected_domain]) ? $domain_options[$selected_domain] : $selected_domain); ?></span>
                                </div>
                            <?php else : ?>
                                <select name="hl_ap[domain]" id="hl-ap-domain" class="hlap-select">
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
                        <div class="hlap-field" id="hl-ap-skills-group" style="<?php echo empty($selected_domain) ? 'display:none;' : ''; ?>">
                            <label class="hlap-field-label"><?php esc_html_e('Skills / Strategy', 'hl-core'); ?></label>
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
                            <?php endif; ?>
                        </div>

                        <!-- HOW -->
                        <div class="hlap-field">
                            <label class="hlap-field-label" for="hl-ap-how"><?php esc_html_e('Describe HOW you will practice the skill(s)', 'hl-core'); ?></label>
                            <?php if ($is_readonly) : ?>
                                <div class="hlap-readonly-value"><?php echo wp_kses_post(isset($responses['how']) ? $responses['how'] : ''); ?></div>
                            <?php else : ?>
                                <textarea name="hl_ap[how]" id="hl-ap-how" rows="4" class="hlap-textarea"
                                          placeholder="<?php esc_attr_e('Describe the specific activities and strategies you plan to use...', 'hl-core'); ?>"
                                ><?php echo esc_textarea(isset($responses['how']) ? $responses['how'] : ''); ?></textarea>
                            <?php endif; ?>
                        </div>

                        <!-- WHAT -->
                        <div class="hlap-field">
                            <label class="hlap-field-label" for="hl-ap-what"><?php esc_html_e('WHAT behaviors will you track to know this is effective?', 'hl-core'); ?></label>
                            <?php if ($is_readonly) : ?>
                                <div class="hlap-readonly-value"><?php echo wp_kses_post(isset($responses['what']) ? $responses['what'] : ''); ?></div>
                            <?php else : ?>
                                <textarea name="hl_ap[what]" id="hl-ap-what" rows="4" class="hlap-textarea"
                                          placeholder="<?php esc_attr_e('What observable changes will indicate success?', 'hl-core'); ?>"
                                ><?php echo esc_textarea(isset($responses['what']) ? $responses['what'] : ''); ?></textarea>
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
        <?php

        return ob_get_clean();
    }

    /**
     * Inline styles for the Action Plan form.
     */
    private static function render_styles() {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        ?>
        <style>
        .hlap-form-wrapper{max-width:820px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
        .hlap-hero{display:flex;align-items:center;gap:16px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;padding:28px 32px;border-radius:16px;margin-bottom:24px;position:relative;z-index:1;overflow:visible}
        .hlap-hero-icon{background:rgba(255,255,255,.15);border-radius:12px;padding:12px;display:flex;align-items:center;justify-content:center}
        .hlap-hero-title{font-size:22px;font-weight:700;margin:0;letter-spacing:-.3px;color:#fff}
        .hlap-hero-sub{font-size:14px;opacity:.8;margin:4px 0 0}

        .hlap-alert{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:10px;font-size:14px;margin-bottom:20px}
        .hlap-alert-info{background:#e8f4fd;color:#1e5f8a;border:1px solid #b8daef}

        /* Sections */
        .hlap-section{background:#fff;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:20px;overflow:hidden}
        .hlap-section-header{display:flex;align-items:center;gap:12px;padding:16px 20px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-bottom:1px solid #e2e8f0}
        .hlap-section-num{background:#1e3a5f;color:#fff;font-size:11px;font-weight:700;width:24px;height:24px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
        .hlap-section-title{font-size:15px;font-weight:600;color:#1e293b}
        .hlap-section-body{padding:20px}

        /* Fields */
        .hlap-field{margin-bottom:20px}
        .hlap-field:last-child{margin-bottom:0}
        .hlap-field-label{display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:8px;letter-spacing:.3px}
        .hlap-readonly-value{background:#f1f5f9;padding:12px 16px;border-radius:10px;font-size:14px;color:#334155;line-height:1.6;min-height:20px}
        .hlap-muted{color:#94a3b8;font-size:14px}

        /* Select */
        .hlap-select{width:100%;padding:12px 16px;border:2px solid #e2e8f0;border-radius:10px;font-size:14px;font-weight:500;color:#1e293b;background:#fff;font-family:inherit;transition:border-color .2s;appearance:auto}
        .hlap-select:focus{outline:none;border-color:#2d5f8a;box-shadow:0 0 0 3px rgba(45,95,138,.1)}

        /* Textarea */
        .hlap-textarea{width:100%;border:2px solid #e2e8f0;border-radius:10px;padding:12px 16px;font-size:14px;font-family:inherit;resize:vertical;min-height:80px;transition:border-color .2s;background:#fafbfc;box-sizing:border-box}
        .hlap-textarea:focus{outline:none;border-color:#2d5f8a;box-shadow:0 0 0 3px rgba(45,95,138,.1);background:#fff}
        .hlap-textarea::placeholder{color:#94a3b8}

        /* Domain badge (read-only) */
        .hlap-domain-badge{display:inline-flex;padding:6px 16px;border-radius:50px;font-size:14px;font-weight:600;background:#1e3a5f;color:#fff}

        /* Pill checkboxes for skills */
        .hlap-pills{display:flex;flex-wrap:wrap;gap:10px}
        .hlap-pill{position:relative}
        .hlap-pill input{position:absolute;opacity:0;pointer-events:none}
        .hlap-pill-label{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border:2px solid #e2e8f0;border-radius:50px;font-size:14px;font-weight:500;color:#64748b;cursor:pointer;transition:all .2s ease;background:#fff;user-select:none}
        .hlap-pill-label:hover{border-color:#94a3b8;color:#334155}
        .hlap-pill input:checked+.hlap-pill-label{background:#1e3a5f;border-color:#1e3a5f;color:#fff;box-shadow:0 2px 8px rgba(30,58,95,.25)}
        .hlap-pill-dot{width:8px;height:8px;border-radius:50%;background:#cbd5e1;transition:background .2s}
        .hlap-pill input:checked+.hlap-pill-label .hlap-pill-dot{background:#6ee7b7}
        .hlap-pills-ro{display:flex;flex-wrap:wrap;gap:8px}
        .hlap-pill-ro{display:inline-flex;padding:6px 14px;border-radius:50px;font-size:13px;font-weight:600;background:#d1fae5;color:#065f46}

        /* Likert scale */
        .hlap-likert{display:flex;flex-direction:column;gap:8px}
        .hlap-likert-option{display:block;cursor:pointer}
        .hlap-likert-option input{position:absolute;opacity:0;pointer-events:none}
        .hlap-likert-btn{display:flex;align-items:center;gap:12px;padding:12px 16px;border:2px solid #e2e8f0;border-radius:10px;transition:all .2s;background:#fff}
        .hlap-likert-option:hover .hlap-likert-btn{border-color:#94a3b8}
        .hlap-likert-option input:checked+.hlap-likert-btn{border-color:#1e3a5f;background:#f0f4f8;box-shadow:0 2px 8px rgba(30,58,95,.1)}
        .hlap-likert-num{width:28px;height:28px;border-radius:50%;background:#e2e8f0;color:#64748b;font-size:13px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0}
        .hlap-likert-option input:checked+.hlap-likert-btn .hlap-likert-num{background:#1e3a5f;color:#fff}
        .hlap-likert-text{font-size:14px;font-weight:500;color:#475569}
        .hlap-likert-badge{display:inline-flex;padding:6px 14px;border-radius:50px;font-size:13px;font-weight:600}
        .hlap-likert-1{background:#fee2e2;color:#991b1b}
        .hlap-likert-2{background:#fef3c7;color:#92400e}
        .hlap-likert-3{background:#fef9c3;color:#854d0e}
        .hlap-likert-4{background:#d1fae5;color:#065f46}
        .hlap-likert-5{background:#a7f3d0;color:#064e3b}

        /* Action buttons */
        .hlap-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:32px;padding-top:24px;border-top:1px solid #e2e8f0}
        .hlap-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:10px;font-size:15px;font-weight:600;border:none;cursor:pointer;transition:all .2s;font-family:inherit}
        .hlap-btn-draft{background:#f1f5f9;color:#475569;border:2px solid #e2e8f0}
        .hlap-btn-draft:hover{background:#e2e8f0;border-color:#cbd5e1}
        .hlap-btn-submit{background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;border:2px solid transparent;box-shadow:0 4px 14px rgba(30,58,95,.3)}
        .hlap-btn-submit:hover{box-shadow:0 6px 20px rgba(30,58,95,.4);transform:translateY(-1px)}

        @media(max-width:600px){
            .hlap-hero{flex-direction:column;text-align:center;padding:24px 20px}
            .hlap-pills{gap:6px}
            .hlap-pill-label{padding:8px 14px;font-size:13px}
            .hlap-likert-btn{padding:10px 12px;gap:8px}
            .hlap-actions{flex-direction:column}
            .hlap-btn{justify-content:center}
        }
        </style>
        <?php
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
