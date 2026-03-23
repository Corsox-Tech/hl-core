<?php
if (!defined('ABSPATH')) exit;

/**
 * Classroom Visit form renderer.
 *
 * School Leader fills after observing a Teacher's class. Renders domain/indicator
 * checklist with Yes/No toggles and conditional description textareas.
 *
 * @package HL_Core
 */
class HL_Frontend_Classroom_Visit {

    /**
     * Render from the component page dispatcher.
     *
     * Called by HL_Frontend_Component_Page::render_available_view() with
     * ($component, $enrollment, $cycle_id). Resolves visit entities and
     * instruments, then delegates to render_form().
     *
     * @param object $component  Component domain object
     * @param object $enrollment Enrollment domain object
     * @param int    $cycle_id
     * @return string HTML
     */
    public function render($component, $enrollment, $cycle_id) {
        ob_start();
        global $wpdb;

        // Handle form submissions first
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['hl_cv_nonce'])) {
            $result = self::handle_submission();
            if ($result && !is_wp_error($result)) {
                $redirect = add_query_arg('message', $result['message']);
                wp_safe_redirect($redirect);
                exit;
            }
            if (is_wp_error($result)) {
                echo '<div class="hl-notice hl-notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            }
        }

        $external_ref  = $component->get_external_ref_array();
        $visit_number  = isset($external_ref['visit_number']) ? (int) $external_ref['visit_number'] : 1;
        $enrollment_id = (int) $enrollment->enrollment_id;

        $cv_service = new HL_Classroom_Visit_Service();

        // Load instrument
        $instrument = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s AND status = 'active'",
            'classroom_visit_form'
        ));

        if (!$instrument) {
            echo '<div class="hl-notice hl-notice-warning">' . esc_html__('Classroom Visit instrument not found.', 'hl-core') . '</div>';
            return ob_get_clean();
        }

        // Leader sees list of teachers to visit
        $teachers = $cv_service->get_teachers_for_leader($enrollment_id, $cycle_id);
        $selected_teacher_id = isset($_GET['teacher']) ? absint($_GET['teacher']) : 0;

        if ($selected_teacher_id) {
            // Find or create visit
            $visit = $this->find_or_create_visit($cv_service, $cycle_id, $enrollment_id, $selected_teacher_id, $visit_number);
            if ($visit && !is_wp_error($visit)) {
                $submissions = $cv_service->get_submissions((int) $visit['classroom_visit_id']);
                $existing = null;
                foreach ($submissions as $sub) {
                    if ($sub['role_in_visit'] === 'observer') {
                        $existing = $sub;
                        break;
                    }
                }

                $teacher_info = array('display_name' => isset($visit['teacher_name']) ? $visit['teacher_name'] : '—');
                $back_url = remove_query_arg('teacher');
                echo '<a href="' . esc_url($back_url) . '" class="hl-back-link">&larr; ' . esc_html__('Back to Teacher List', 'hl-core') . '</a>';
                echo $this->render_form($visit, $enrollment, $instrument, $teacher_info, $existing);
            } else {
                echo '<div class="hl-notice hl-notice-error">' . esc_html__('Unable to load classroom visit.', 'hl-core') . '</div>';
            }
        } else {
            // Show teacher list
            $this->render_teacher_list($teachers, $cycle_id, $visit_number, $cv_service);
        }

        return ob_get_clean();
    }

    /**
     * Show list of teachers for the leader to visit.
     */
    private function render_teacher_list($teachers, $cycle_id, $visit_number, $cv_service) {
        ?>
        <div class="hl-classroom-visit-list">
            <h3><?php printf(esc_html__('Classroom Visit #%d', 'hl-core'), $visit_number); ?></h3>
            <p class="hl-field-hint"><?php esc_html_e('Select a teacher to complete the classroom visit form.', 'hl-core'); ?></p>

            <?php if (empty($teachers)) : ?>
                <div class="hl-empty-state">
                    <p><?php esc_html_e('No teachers found in your school(s).', 'hl-core'); ?></p>
                </div>
            <?php else : ?>
                <table class="hl-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Teacher', 'hl-core'); ?></th>
                            <th><?php esc_html_e('Status', 'hl-core'); ?></th>
                            <th><?php esc_html_e('Action', 'hl-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teachers as $teacher) :
                            $teacher_eid = (int) $teacher['enrollment_id'];
                            $visits = $cv_service->get_by_teacher($teacher_eid);
                            $matching = null;
                            foreach ($visits as $v) {
                                if ((int) $v['visit_number'] === $visit_number && (int) $v['cycle_id'] === $cycle_id) {
                                    $matching = $v;
                                    break;
                                }
                            }
                            $status = $matching ? $matching['status'] : 'not_started';
                            $status_class = ($status === 'completed') ? 'green' : 'gray';
                            $detail_url = add_query_arg('teacher', $teacher_eid);
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
                                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $status))); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($detail_url); ?>" class="hl-btn hl-btn-small hl-btn-primary">
                                        <?php echo esc_html($status === 'completed' ? __('View', 'hl-core') : __('Open Visit', 'hl-core')); ?>
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
     * Find an existing classroom visit or create one.
     *
     * @return array|WP_Error
     */
    private function find_or_create_visit($cv_service, $cycle_id, $leader_enrollment_id, $teacher_enrollment_id, $visit_number) {
        $visits = $cv_service->get_by_leader($leader_enrollment_id);
        foreach ($visits as $v) {
            if ((int) $v['teacher_enrollment_id'] === $teacher_enrollment_id
                && (int) $v['visit_number'] === $visit_number
                && (int) $v['cycle_id'] === $cycle_id) {
                return $v;
            }
        }

        $result = $cv_service->create_visit(array(
            'cycle_id'              => $cycle_id,
            'leader_enrollment_id'  => $leader_enrollment_id,
            'teacher_enrollment_id' => $teacher_enrollment_id,
            'visit_number'          => $visit_number,
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        return $cv_service->get_visit($result);
    }

    /**
     * Render the Classroom Visit form.
     *
     * @param array       $visit_entity        Visit row from hl_classroom_visit
     * @param object      $enrollment          Leader's enrollment object
     * @param object      $instrument          Instrument row
     * @param array       $teacher_info        Array with 'display_name', 'user_email'
     * @param array|null  $existing_submission Existing submission row, or null
     * @return string HTML
     */
    public function render_form($visit_entity, $enrollment, $instrument, $teacher_info, $existing_submission = null) {
        ob_start();

        $sections    = json_decode($instrument->sections, true) ?: array();
        $responses   = $existing_submission ? json_decode($existing_submission['responses_json'], true) : array();
        $is_readonly = ($existing_submission && $existing_submission['status'] === 'submitted');

        $visit_id      = isset($visit_entity['classroom_visit_id']) ? (int) $visit_entity['classroom_visit_id'] : 0;
        $instrument_id = (int) $instrument->instrument_id;

        ?>
        <div class="hl-form hl-classroom-visit-form">
            <h3><?php esc_html_e('Classroom Visit Form', 'hl-core'); ?></h3>

            <?php if ($is_readonly) : ?>
                <div class="hl-notice hl-notice-info">
                    <p><?php esc_html_e('This form has been submitted and is read-only.', 'hl-core'); ?></p>
                </div>
            <?php endif; ?>

            <!-- Auto-populated header -->
            <div class="hl-fieldset hl-auto-populated">
                <h4><?php esc_html_e('Visit Information', 'hl-core'); ?></h4>
                <div class="hl-info-grid">
                    <div class="hl-info-item">
                        <span class="hl-info-label"><?php esc_html_e('Teacher:', 'hl-core'); ?></span>
                        <span class="hl-info-value"><?php echo esc_html(isset($teacher_info['display_name']) ? $teacher_info['display_name'] : '—'); ?></span>
                    </div>
                    <div class="hl-info-item">
                        <span class="hl-info-label"><?php esc_html_e('Classroom Visitor:', 'hl-core'); ?></span>
                        <span class="hl-info-value"><?php
                            $user = wp_get_current_user();
                            echo esc_html($user->display_name);
                        ?></span>
                    </div>
                    <div class="hl-info-item">
                        <span class="hl-info-label"><?php esc_html_e('Date:', 'hl-core'); ?></span>
                        <span class="hl-info-value"><?php
                            $visit_date = isset($visit_entity['visit_date']) ? $visit_entity['visit_date'] : '';
                            echo esc_html($visit_date ? date_i18n(get_option('date_format'), strtotime($visit_date)) : date_i18n(get_option('date_format')));
                        ?></span>
                    </div>
                    <div class="hl-info-item">
                        <span class="hl-info-label"><?php esc_html_e('Visit #:', 'hl-core'); ?></span>
                        <span class="hl-info-value"><?php echo esc_html(isset($visit_entity['visit_number']) ? $visit_entity['visit_number'] : '—'); ?></span>
                    </div>
                </div>
            </div>

            <form method="post" id="hl-classroom-visit-form">
                <?php if (!$is_readonly) : ?>
                    <?php wp_nonce_field('hl_classroom_visit_submit', 'hl_cv_nonce'); ?>
                    <input type="hidden" name="hl_cv_visit_id" value="<?php echo esc_attr($visit_id); ?>">
                    <input type="hidden" name="hl_cv_instrument_id" value="<?php echo esc_attr($instrument_id); ?>">
                    <input type="hidden" name="hl_cv_form_type" value="classroom_visit">
                <?php endif; ?>

                <?php
                // Render context and domain sections
                self::render_visit_form_sections($sections, $responses, $is_readonly, 'hl_cv');
                ?>

                <?php if (!$is_readonly) : ?>
                    <div class="hl-form-actions">
                        <button type="submit" name="hl_cv_action" value="draft" class="hl-btn hl-btn-secondary">
                            <?php esc_html_e('Save Draft', 'hl-core'); ?>
                        </button>
                        <button type="submit" name="hl_cv_action" value="submit" class="hl-btn hl-btn-primary">
                            <?php esc_html_e('Submit', 'hl-core'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <?php self::render_toggle_js(); ?>
        <?php

        return ob_get_clean();
    }

    /**
     * Render the context + domain/indicator sections.
     *
     * Shared between Classroom Visit and Self-Reflection renderers.
     *
     * @param array  $sections    Instrument sections array
     * @param array  $responses   Existing responses
     * @param bool   $is_readonly Whether form is read-only
     * @param string $prefix      Form field name prefix ('hl_cv' or 'hl_sr')
     */
    public static function render_visit_form_sections($sections, $responses, $is_readonly, $prefix) {
        foreach ($sections as $section) {
            $type = isset($section['type']) ? $section['type'] : '';
            $key  = isset($section['key']) ? $section['key'] : '';
            $stitle = isset($section['title']) ? strtolower($section['title']) : '';

            // Context section (checkboxes)
            if ($key === 'context' || $type === 'checkboxes' || $stitle === 'context') {
                self::render_context_section($section, $responses, $is_readonly, $prefix);
            }
            // Domain indicators section
            elseif ($type === 'indicator_checklist' || $type === 'domain_indicators') {
                self::render_domain_indicators_section($section, $responses, $is_readonly, $prefix);
            }
        }
    }

    /**
     * Render domain/indicator sections from the domain_indicators JSON format.
     * JSON shape: { title, type: "domain_indicators", domains: [{ name, skills: [...] }] }
     */
    private static function render_domain_indicators_section($section, $responses, $is_readonly, $prefix) {
        $domains = isset($section['domains']) ? $section['domains'] : array();
        foreach ($domains as $d_idx => $domain) {
            $domain_key  = 'domain_' . $d_idx;
            $domain_name = isset($domain['name']) ? $domain['name'] : '';
            $indicators  = isset($domain['skills']) ? $domain['skills'] : array();
            $fake_section = array(
                'key'        => $domain_key,
                'title'      => $domain_name,
                'indicators' => $indicators,
            );
            self::render_indicator_section($fake_section, $responses, $is_readonly, $prefix);
        }
    }

    /**
     * Render the context checkboxes section.
     */
    private static function render_context_section($section, $responses, $is_readonly, $prefix) {
        $selected = isset($responses['context_activities']) && is_array($responses['context_activities'])
            ? $responses['context_activities']
            : array();

        // Support both formats: flat "options" array or nested "fields" with key
        $options = array();
        if (!empty($section['options']) && is_array($section['options'])) {
            // Flat format: ["Free Play", "Formal Group Activities", ...]
            foreach ($section['options'] as $opt) {
                $key = sanitize_title($opt);
                $options[$key] = $opt;
            }
        } elseif (!empty($section['fields'])) {
            foreach ($section['fields'] as $field) {
                if (isset($field['key']) && $field['key'] === 'context_activities' && !empty($field['options'])) {
                    $options = $field['options'];
                }
            }
        }

        ?>
        <fieldset class="hl-fieldset">
            <legend><?php esc_html_e('Context', 'hl-core'); ?></legend>
            <p class="hl-field-hint"><?php esc_html_e('Select all activities observed during the visit.', 'hl-core'); ?></p>
            <?php if ($is_readonly) : ?>
                <div class="hl-field-value">
                    <?php if (!empty($selected)) : ?>
                        <?php
                        $labels = array();
                        foreach ($selected as $key) {
                            $labels[] = isset($options[$key]) ? $options[$key] : $key;
                        }
                        echo esc_html(implode(', ', $labels));
                        ?>
                    <?php else : ?>
                        <span class="hl-muted"><?php esc_html_e('None selected', 'hl-core'); ?></span>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div class="hl-checkbox-group">
                    <?php foreach ($options as $key => $label) : ?>
                        <label class="hl-checkbox-label">
                            <input type="checkbox" name="<?php echo esc_attr($prefix); ?>[context_activities][]"
                                   value="<?php echo esc_attr($key); ?>"
                                   <?php checked(in_array($key, $selected, true)); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    /**
     * Render a domain indicator checklist section.
     */
    private static function render_indicator_section($section, $responses, $is_readonly, $prefix) {
        $domain_key  = $section['key'];
        $indicators  = isset($section['indicators']) ? $section['indicators'] : array();
        $domain_data = isset($responses[$domain_key]) && is_array($responses[$domain_key]) ? $responses[$domain_key] : array();

        ?>
        <fieldset class="hl-fieldset hl-indicator-section">
            <legend>
                <button type="button" class="hl-accordion-toggle" onclick="this.closest('.hl-indicator-section').classList.toggle('hl-accordion-open')">
                    <?php echo esc_html($section['title']); ?>
                    <span class="hl-accordion-icon">&#9660;</span>
                </button>
            </legend>
            <div class="hl-accordion-content">
                <?php foreach ($indicators as $idx => $indicator_label) :
                    $indicator_key = 'indicator_' . $idx;
                    $ind_data      = isset($domain_data[$indicator_key]) && is_array($domain_data[$indicator_key]) ? $domain_data[$indicator_key] : array();
                    $observed      = !empty($ind_data['observed']);
                    $description   = isset($ind_data['description']) ? $ind_data['description'] : '';
                    $field_name    = $prefix . '[' . $domain_key . '][' . $indicator_key . ']';
                ?>
                    <div class="hl-indicator-item" data-indicator="<?php echo esc_attr($domain_key . '_' . $idx); ?>">
                        <div class="hl-indicator-label">
                            <span><?php echo esc_html($indicator_label); ?></span>
                        </div>

                        <?php if ($is_readonly) : ?>
                            <div class="hl-indicator-response">
                                <span class="hl-badge hl-badge-<?php echo $observed ? 'green' : 'gray'; ?>">
                                    <?php echo esc_html($observed ? __('Yes', 'hl-core') : __('No', 'hl-core')); ?>
                                </span>
                                <?php if ($observed && !empty($description)) : ?>
                                    <div class="hl-field-value"><?php echo wp_kses_post($description); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php else : ?>
                            <div class="hl-indicator-toggle">
                                <label class="hl-toggle-btn">
                                    <input type="radio" name="<?php echo esc_attr($field_name); ?>[observed]" value="1"
                                           class="hl-indicator-radio" data-target="<?php echo esc_attr($domain_key . '_' . $idx); ?>"
                                           <?php checked($observed); ?>>
                                    <span class="hl-btn hl-btn-small hl-btn-toggle-yes"><?php esc_html_e('Yes', 'hl-core'); ?></span>
                                </label>
                                <label class="hl-toggle-btn">
                                    <input type="radio" name="<?php echo esc_attr($field_name); ?>[observed]" value="0"
                                           class="hl-indicator-radio" data-target="<?php echo esc_attr($domain_key . '_' . $idx); ?>"
                                           <?php checked(!$observed); ?>>
                                    <span class="hl-btn hl-btn-small hl-btn-toggle-no"><?php esc_html_e('No', 'hl-core'); ?></span>
                                </label>
                            </div>
                            <div class="hl-indicator-description" id="hl-desc-<?php echo esc_attr($domain_key . '_' . $idx); ?>"
                                 style="<?php echo $observed ? '' : 'display:none;'; ?>">
                                <textarea name="<?php echo esc_attr($field_name); ?>[description]" rows="3" class="hl-textarea"
                                          placeholder="<?php esc_attr_e('Describe what you observed...', 'hl-core'); ?>"
                                ><?php echo esc_textarea($description); ?></textarea>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Render JS for Yes/No toggle showing/hiding description textareas.
     */
    public static function render_toggle_js() {
        ?>
        <script>
        (function(){
            document.addEventListener('change', function(e) {
                if (!e.target.classList.contains('hl-indicator-radio')) return;
                var target = e.target.getAttribute('data-target');
                var descEl = document.getElementById('hl-desc-' + target);
                if (!descEl) return;
                descEl.style.display = (e.target.value === '1') ? '' : 'none';
            });
        })();
        </script>
        <?php
    }

    /**
     * Handle form POST submission.
     *
     * @return array|WP_Error
     */
    public static function handle_submission() {
        if (empty($_POST['hl_cv_nonce']) || !wp_verify_nonce($_POST['hl_cv_nonce'], 'hl_classroom_visit_submit')) {
            return new WP_Error('nonce_failed', __('Security check failed.', 'hl-core'));
        }

        $user_id       = get_current_user_id();
        $visit_id      = absint($_POST['hl_cv_visit_id']);
        $instrument_id = absint($_POST['hl_cv_instrument_id']);
        $action        = sanitize_text_field($_POST['hl_cv_action']);
        $status        = ($action === 'submit') ? 'submitted' : 'draft';
        $role          = 'observer';

        $responses = self::collect_visit_responses('hl_cv');
        $responses_json = wp_json_encode($responses);

        $service = new HL_Classroom_Visit_Service();
        $result  = $service->submit_form($visit_id, $user_id, $instrument_id, $role, $responses_json, $status);

        // Mark visit completed and update component state on submit
        if ($status === 'submitted' && !is_wp_error($result)) {
            $service->mark_completed($visit_id);

            $visit = $service->get_visit($visit_id);
            if ($visit) {
                $service->update_component_state(
                    (int) $visit['leader_enrollment_id'],
                    (int) $visit['cycle_id'],
                    (int) $visit['visit_number']
                );
            }
        }

        return array(
            'submission_id' => $result,
            'status'        => $status,
            'message'       => ($status === 'submitted') ? 'submitted' : 'saved',
        );
    }

    /**
     * Collect and sanitize visit form responses from POST data.
     *
     * Shared between Classroom Visit and Self-Reflection handlers.
     *
     * @param string $prefix Form field prefix ('hl_cv' or 'hl_sr')
     * @return array Sanitized responses
     */
    public static function collect_visit_responses($prefix) {
        $raw = isset($_POST[$prefix]) && is_array($_POST[$prefix]) ? $_POST[$prefix] : array();
        $responses = array();

        // Context activities
        $responses['context_activities'] = isset($raw['context_activities']) && is_array($raw['context_activities'])
            ? array_map('sanitize_text_field', $raw['context_activities'])
            : array();

        // Domain indicator data
        $domains = HL_RP_Session_Service::get_ecsel_domains();
        foreach (array_keys($domains) as $domain_key) {
            if (!isset($raw[$domain_key]) || !is_array($raw[$domain_key])) {
                continue;
            }
            $responses[$domain_key] = array();
            foreach ($raw[$domain_key] as $indicator_key => $indicator_data) {
                if (!is_array($indicator_data)) continue;
                $responses[$domain_key][sanitize_key($indicator_key)] = array(
                    'observed'    => !empty($indicator_data['observed']) ? 1 : 0,
                    'description' => isset($indicator_data['description']) ? wp_kses_post($indicator_data['description']) : '',
                );
            }
        }

        return $responses;
    }
}
