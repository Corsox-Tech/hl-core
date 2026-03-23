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
     * Render the Self-Reflection form.
     *
     * @param array       $visit_entity        Visit row from hl_classroom_visit (or synthetic row for standalone)
     * @param object      $enrollment          Teacher's enrollment object
     * @param object      $instrument          Instrument row (self_reflection_form)
     * @param array|null  $existing_submission Existing submission row, or null
     * @return string HTML
     */
    public function render($visit_entity, $enrollment, $instrument, $existing_submission = null) {
        ob_start();

        $sections    = json_decode($instrument->sections, true) ?: array();
        $responses   = $existing_submission ? json_decode($existing_submission['responses_json'], true) : array();
        $is_readonly = ($existing_submission && $existing_submission['status'] === 'submitted');

        $visit_id      = isset($visit_entity['classroom_visit_id']) ? (int) $visit_entity['classroom_visit_id'] : 0;
        $instrument_id = (int) $instrument->instrument_id;

        $user = wp_get_current_user();

        ?>
        <div class="hl-form hl-self-reflection-form">
            <h3><?php esc_html_e('Self-Reflection', 'hl-core'); ?></h3>

            <?php if ($is_readonly) : ?>
                <div class="hl-notice hl-notice-info">
                    <p><?php esc_html_e('This form has been submitted and is read-only.', 'hl-core'); ?></p>
                </div>
            <?php endif; ?>

            <!-- Auto-populated header -->
            <div class="hl-fieldset hl-auto-populated">
                <h4><?php esc_html_e('Self-Reflection Information', 'hl-core'); ?></h4>
                <div class="hl-info-grid">
                    <div class="hl-info-item">
                        <span class="hl-info-label"><?php esc_html_e('Teacher:', 'hl-core'); ?></span>
                        <span class="hl-info-value"><?php echo esc_html($user->display_name); ?></span>
                    </div>
                    <div class="hl-info-item">
                        <span class="hl-info-label"><?php esc_html_e('Date:', 'hl-core'); ?></span>
                        <span class="hl-info-value"><?php echo esc_html(date_i18n(get_option('date_format'))); ?></span>
                    </div>
                    <div class="hl-info-item">
                        <span class="hl-info-label"><?php esc_html_e('Visit #:', 'hl-core'); ?></span>
                        <span class="hl-info-value"><?php echo esc_html(isset($visit_entity['visit_number']) ? $visit_entity['visit_number'] : '—'); ?></span>
                    </div>
                    <?php
                    // Show indicator if a leader has visited
                    if (!empty($visit_entity['status']) && $visit_entity['status'] === 'completed') : ?>
                        <div class="hl-info-item">
                            <span class="hl-badge hl-badge-green"><?php esc_html_e('Your leader has visited your classroom', 'hl-core'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
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
                    <div class="hl-form-actions">
                        <button type="submit" name="hl_sr_action" value="draft" class="hl-btn hl-btn-secondary">
                            <?php esc_html_e('Save Draft', 'hl-core'); ?>
                        </button>
                        <button type="submit" name="hl_sr_action" value="submit" class="hl-btn hl-btn-primary">
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

        // Update component state on submit
        if ($status === 'submitted' && !is_wp_error($result)) {
            $visit = $service->get_visit($visit_id);
            if ($visit) {
                $service->update_component_state(
                    (int) $visit['teacher_enrollment_id'],
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
}
