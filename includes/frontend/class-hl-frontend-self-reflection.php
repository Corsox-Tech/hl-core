<?php
if (!defined('ABSPATH')) exit;

/**
 * Self-Reflection form renderer.
 *
 * Teacher fills a self-assessment about their own classroom practice.
 * Uses the same domain/indicator structure as Classroom Visit but with
 * first-person framing ("I demonstrated..." instead of "Teacher demonstrated...").
 *
 * @package HL_Core
 */
class HL_Frontend_Self_Reflection {

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
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['hl_sr_nonce'])) {
            $result = self::handle_submission();
            if ($result && !is_wp_error($result)) {
                $redirect = add_query_arg('message', $result['message']);
                while (ob_get_level()) { ob_end_clean(); }
                if (!headers_sent()) {
                    wp_safe_redirect($redirect);
                    exit;
                }
                echo '<script>window.location.href=' . wp_json_encode($redirect) . ';</script>';
                exit;
            }
            if (is_wp_error($result)) {
                echo '<div class="hl-notice hl-notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            }
        }

        // Show success/saved message after redirect
        if (isset($_GET['message'])) {
            HL_Frontend_Classroom_Visit::render_form_styles();
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'submitted') {
                echo '<div class="hlcv-alert" style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;margin-bottom:20px;display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:10px;font-size:14px;max-width:820px;margin-left:auto;margin-right:auto">';
                echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
                echo '<strong>' . esc_html__('Self-reflection submitted successfully!', 'hl-core') . '</strong>';
                echo '</div>';
            } elseif ($msg === 'saved') {
                echo '<div class="hlcv-alert" style="background:#e8f4fd;color:#1e5f8a;border:1px solid #b8daef;margin-bottom:20px;display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:10px;font-size:14px;max-width:820px;margin-left:auto;margin-right:auto">';
                echo '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>';
                echo '<strong>' . esc_html__('Draft saved.', 'hl-core') . '</strong>';
                echo '</div>';
            }
        }

        $external_ref  = $component->get_external_ref_array();
        $visit_number  = isset($external_ref['visit_number']) ? (int) $external_ref['visit_number'] : 1;
        $enrollment_id = (int) $enrollment->enrollment_id;

        // Load instrument
        $instrument = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s AND status = 'active'",
            'self_reflection_form'
        ));

        if (!$instrument) {
            echo '<div class="hl-notice hl-notice-warning">' . esc_html__('Self-Reflection instrument not found.', 'hl-core') . '</div>';
            return ob_get_clean();
        }

        // Find if there's a matching classroom visit for this teacher
        $cv_service = new HL_Classroom_Visit_Service();
        $visit = $cv_service->get_most_recent_for_teacher($enrollment_id, $cycle_id);

        // Build a visit entity (real or synthetic)
        $visit_entity = null;
        if ($visit && (int) ($visit['visit_number'] ?? 0) === $visit_number) {
            $visit_entity = $visit;
        }

        if (!$visit_entity) {
            $visit_entity = array(
                'classroom_visit_id' => 0,
                'visit_number'       => $visit_number,
                'status'             => 'pending',
                'visit_date'         => null,
            );
        }

        // Load existing submission
        $existing = null;
        if (!empty($visit_entity['classroom_visit_id'])) {
            $submissions = $cv_service->get_submissions((int) $visit_entity['classroom_visit_id']);
            foreach ($submissions as $sub) {
                if ($sub['role_in_visit'] === 'self_reflector') {
                    $existing = $sub;
                    break;
                }
            }
        }

        // Fallback: for standalone self-reflections (no classroom visit yet),
        // look up by classroom_visit_id=0 + user + instrument.
        if (!$existing && empty($visit_entity['classroom_visit_id'])) {
            $fallback = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_classroom_visit_submission
                 WHERE classroom_visit_id = 0
                   AND submitted_by_user_id = %d
                   AND instrument_id = %d
                   AND role_in_visit = 'self_reflector'
                 ORDER BY submission_id DESC LIMIT 1",
                get_current_user_id(),
                (int) $instrument->instrument_id
            ), ARRAY_A);
            if ($fallback) {
                $existing = $fallback;
            }
        }

        echo $this->render_form($visit_entity, $enrollment, $instrument, $existing);

        return ob_get_clean();
    }

    /**
     * Render the Self-Reflection form.
     *
     * @param array       $visit_entity        Visit row from hl_classroom_visit (or synthetic row for standalone)
     * @param object      $enrollment          Teacher's enrollment object
     * @param object      $instrument          Instrument row (self_reflection_form)
     * @param array|null  $existing_submission Existing submission row, or null
     * @return string HTML
     */
    public function render_form($visit_entity, $enrollment, $instrument, $existing_submission = null) {
        ob_start();

        $sections    = json_decode($instrument->sections, true) ?: array();
        $responses   = $existing_submission ? json_decode($existing_submission['responses_json'], true) : array();
        $is_readonly = ($existing_submission && $existing_submission['status'] === 'submitted');

        $visit_id      = isset($visit_entity['classroom_visit_id']) ? (int) $visit_entity['classroom_visit_id'] : 0;
        $instrument_id = (int) $instrument->instrument_id;

        $user = wp_get_current_user();

        // Load the shared hlcv styles (domain sections, toggles, etc.)
        HL_Frontend_Classroom_Visit::render_form_styles();
        ?>
        <div class="hlcv-form-wrapper">

            <!-- Hero header -->
            <div class="hlcv-hero">
                <div class="hlcv-hero-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M12 16v-4"></path>
                        <path d="M12 8h.01"></path>
                    </svg>
                </div>
                <div>
                    <h2 class="hlcv-hero-title"><?php esc_html_e('Self-Reflection Tool', 'hl-core'); ?></h2>
                    <p class="hlcv-hero-sub"><?php esc_html_e('ECSEL Domains & Indicators — Teacher Self-Assessment', 'hl-core'); ?></p>
                </div>
            </div>

            <?php if ($is_readonly) : ?>
                <div class="hlcv-alert hlcv-alert-info">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <?php esc_html_e('This form has been submitted and is read-only.', 'hl-core'); ?>
                </div>
            <?php endif; ?>

            <!-- Instructions + Notes -->
            <div class="hlcv-instructions">
                <div class="hlcv-instr-section">
                    <div class="hlcv-instr-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    </div>
                    <div>
                        <div class="hlcv-instr-heading"><?php esc_html_e('What this is', 'hl-core'); ?></div>
                        <p><?php esc_html_e('This self-reflection complements the classroom visit observation. It provides an opportunity to reflect on your own practice and identify areas of success and growth from your perspective.', 'hl-core'); ?></p>
                    </div>
                </div>
                <div class="hlcv-instr-section hlcv-instr-highlight">
                    <div class="hlcv-instr-icon hlcv-instr-icon-how">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <div>
                        <div class="hlcv-instr-heading"><?php esc_html_e('Instructions', 'hl-core'); ?></div>
                        <p><?php esc_html_e('Select "Yes" for skills you feel you demonstrated during your teaching. Select "No" if you did not demonstrate or are unsure about a skill. Use the Description field to note specific examples or reflections.', 'hl-core'); ?></p>
                    </div>
                </div>
                <div class="hlcv-notes" style="margin-top:0;margin-bottom:0">
                    <div class="hlcv-notes-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <?php esc_html_e('Notes for Clarification', 'hl-core'); ?>
                    </div>
                    <ul class="hlcv-notes-list">
                        <li><?php esc_html_e('Items marked with an asterisk (*) are defined in the Glossary.', 'hl-core'); ?></li>
                        <li><?php echo wp_kses(
                            __('The <em>begin to ECSEL Support Manual</em> for the Classroom Visit & Self-Reflection Tool includes the Glossary and the Reference Guide to Scoring. <a href="https://dam.housmaninstitute.com/b2E/Course/MC1/Resources/B2E%20Support%20Manual%20for%20the%20Classroom%20Visit%20%26%20Self-Reflection%20Tool.pdf" target="_blank" rel="noopener noreferrer">Open Support Manual</a>', 'hl-core'),
                            array('em' => array(), 'a' => array('href' => array(), 'target' => array(), 'rel' => array()))
                        ); ?></li>
                    </ul>
                </div>
            </div>

            <!-- Info card -->
            <div class="hlcv-info-card">
                <div class="hlcv-info-row">
                    <div class="hlcv-info-cell">
                        <span class="hlcv-info-label"><?php esc_html_e('Teacher', 'hl-core'); ?></span>
                        <span class="hlcv-info-value"><?php echo esc_html($user->display_name); ?></span>
                    </div>
                    <div class="hlcv-info-cell">
                        <span class="hlcv-info-label"><?php esc_html_e('Date', 'hl-core'); ?></span>
                        <span class="hlcv-info-value"><?php echo esc_html(date_i18n(get_option('date_format'))); ?></span>
                    </div>
                    <div class="hlcv-info-cell">
                        <span class="hlcv-info-label"><?php esc_html_e('Visit #', 'hl-core'); ?></span>
                        <span class="hlcv-info-value hlcv-visit-num"><?php echo esc_html(isset($visit_entity['visit_number']) ? $visit_entity['visit_number'] : '1'); ?></span>
                    </div>
                </div>
                <?php if (!empty($visit_entity['status']) && $visit_entity['status'] === 'completed') : ?>
                    <div style="margin-top:12px">
                        <span class="hlcv-ro-badge hlcv-ro-yes">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php esc_html_e('Your leader has visited your classroom', 'hl-core'); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <form method="post" id="hl-self-reflection-form">
                <?php if (!$is_readonly) : ?>
                    <?php wp_nonce_field('hl_self_reflection_submit', 'hl_sr_nonce'); ?>
                    <input type="hidden" name="hl_sr_visit_id" value="<?php echo esc_attr($visit_id); ?>">
                    <input type="hidden" name="hl_sr_instrument_id" value="<?php echo esc_attr($instrument_id); ?>">
                    <input type="hidden" name="hl_sr_form_type" value="self_reflection">
                <?php endif; ?>

                <?php
                // Reuse the shared rendering from Classroom Visit
                HL_Frontend_Classroom_Visit::render_visit_form_sections($sections, $responses, $is_readonly, 'hl_sr');
                ?>

                <?php if (!$is_readonly) : ?>
                    <div class="hlcv-actions">
                        <button type="submit" name="hl_sr_action" value="draft" class="hlcv-btn hlcv-btn-draft">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <?php esc_html_e('Save Draft', 'hl-core'); ?>
                        </button>
                        <button type="submit" name="hl_sr_action" value="submit" class="hlcv-btn hlcv-btn-submit">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php esc_html_e('Submit', 'hl-core'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <?php HL_Frontend_Classroom_Visit::render_toggle_js(); ?>
        <?php

        return ob_get_clean();
    }

    /**
     * Handle form POST submission.
     *
     * @return array|WP_Error
     */
    public static function handle_submission() {
        if (empty($_POST['hl_sr_nonce']) || !wp_verify_nonce($_POST['hl_sr_nonce'], 'hl_self_reflection_submit')) {
            return new WP_Error('nonce_failed', __('Security check failed.', 'hl-core'));
        }

        $user_id       = get_current_user_id();
        $visit_id      = absint($_POST['hl_sr_visit_id']);
        $instrument_id = absint($_POST['hl_sr_instrument_id']);
        $action        = sanitize_text_field($_POST['hl_sr_action']);
        $status        = ($action === 'submit') ? 'submitted' : 'draft';
        $role          = 'self_reflector';

        // Reuse shared response collection
        $responses      = HL_Frontend_Classroom_Visit::collect_visit_responses('hl_sr');
        $responses_json = wp_json_encode($responses);

        $service = new HL_Classroom_Visit_Service();
        $result  = $service->submit_form($visit_id, $user_id, $instrument_id, $role, $responses_json, $status);

        // Update self_reflection component state on submit
        if ($status === 'submitted' && !is_wp_error($result)) {
            self::update_self_reflection_component_state($user_id);
        }

        return array(
            'submission_id' => $result,
            'status'        => $status,
            'message'       => ($status === 'submitted') ? 'submitted' : 'saved',
        );
    }

    /**
     * Mark the self_reflection component complete for the current user.
     *
     * Finds the self_reflection component assigned to this user's enrollment
     * and sets it to 100% complete.
     *
     * @param int $user_id
     */
    private static function update_self_reflection_component_state($user_id) {
        global $wpdb;

        $visit_number = absint($_POST['hl_sr_visit_id'] ?? 0);
        // Get visit_number from the form's hidden field (instrument knows this)
        // Actually, we need the component's visit_number from external_ref.
        // Find the self_reflection component for this user's enrollment.
        $enrollment_id = 0;
        if (!empty($_GET['enrollment'])) {
            $enrollment_id = absint($_GET['enrollment']);
        }
        $component_id = 0;
        if (!empty($_GET['id'])) {
            $component_id = absint($_GET['id']);
        }

        if (!$enrollment_id || !$component_id) {
            return;
        }

        // Verify this is a self_reflection component
        $component_type = $wpdb->get_var($wpdb->prepare(
            "SELECT component_type FROM {$wpdb->prefix}hl_component WHERE component_id = %d",
            $component_id
        ));
        if ($component_type !== 'self_reflection') {
            return;
        }

        $now = current_time('mysql');

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT state_id FROM {$wpdb->prefix}hl_component_state
             WHERE enrollment_id = %d AND component_id = %d",
            $enrollment_id, $component_id
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
            $state_data['component_id']  = $component_id;
            $wpdb->insert($wpdb->prefix . 'hl_component_state', $state_data);
        }

        do_action('hl_core_recompute_rollups', $enrollment_id);
    }
}
