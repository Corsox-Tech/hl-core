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

        // Note: TinyMCE scripts are enqueued on wp_enqueue_scripts by
        // HL_Shortcodes::enqueue_assets() when the page uses [hl_component_page]
        // or [hl_my_coaching]. Calling wp_enqueue_editor() here would fire during
        // the_content, after wp_head has already printed, so the editor scripts
        // would never make it into the page.

        $responses   = $existing_submission ? json_decode($existing_submission['responses_json'], true) : array();

        // Edit-after-submit state machine (ticket #8):
        //   - Fresh / draft  → editable, Save Draft + Submit buttons.
        //   - Submitted      → readonly by default, Edit Submission button shows;
        //                       clicking Edit reloads with ?rpn_edit=1 which enters
        //                       edit mode; Save Changes / Cancel replace the drafts
        //                       buttons. Status stays 'submitted' on Save Changes;
        //                       service preserves submitted_at on re-submit.
        //   - Admin viewers  → rendered via render_submission_readonly() by the page
        //                       controller, never reaches this renderer.
        $is_submitted = $existing_submission && $existing_submission['status'] === 'submitted';
        $is_edit_mode = $is_submitted && isset($_GET['rpn_edit']) && $_GET['rpn_edit'] === '1';
        $is_readonly  = $is_submitted && !$is_edit_mode;

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

        // Styles loaded via frontend.css (Session 2)
        ?>
        <div class="hlrn-form-wrapper" data-hlrn-state="<?php echo esc_attr($is_edit_mode ? 'editing-submission' : ($is_readonly ? 'submitted' : 'draft')); ?>">

            <!-- Hero header -->
            <div class="hlrn-hero">
                <div class="hlrn-hero-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                </div>
                <div>
                    <h2 class="hlrn-hero-title"><?php esc_html_e('RP Session Notes', 'hl-core'); ?></h2>
                    <p class="hlrn-hero-sub"><?php printf(esc_html__('Reflective Practice — %s Session', 'hl-core'), esc_html(ucfirst($context))); ?></p>
                </div>
            </div>

            <?php if ($is_submitted) :
                $submitted_at = !empty($existing_submission['submitted_at']) ? $existing_submission['submitted_at'] : null;
                $updated_at   = !empty($existing_submission['updated_at'])   ? $existing_submission['updated_at']   : null;
                $was_edited   = $submitted_at && $updated_at && strtotime($updated_at) > strtotime($submitted_at) + 60;
                $edit_url     = esc_url(add_query_arg('rpn_edit', '1'));
                $date_fmt     = function($mysql) { return $mysql ? date_i18n(get_option('date_format'), strtotime($mysql)) : ''; };
                ?>
                <div class="hlrn-status-bar">
                    <div class="hlrn-status-meta">
                        <span class="hlrn-status-pill hlrn-status-pill--submitted">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php esc_html_e('Submitted', 'hl-core'); ?>
                        </span>
                        <?php if ($submitted_at) : ?>
                            <span class="hlrn-status-date"><?php echo esc_html($date_fmt($submitted_at)); ?></span>
                        <?php endif; ?>
                        <?php if ($was_edited) : ?>
                            <span class="hlrn-status-edited"><?php printf(esc_html__('edited %s', 'hl-core'), esc_html($date_fmt($updated_at))); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($is_edit_mode) : ?>
                        <span class="hlrn-status-mode"><?php esc_html_e('Editing submission', 'hl-core'); ?></span>
                    <?php else : ?>
                        <a href="<?php echo $edit_url; ?>" class="hlrn-btn hlrn-btn-edit">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                            <?php esc_html_e('Edit Submission', 'hl-core'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Session info card -->
            <div class="hlrn-info-card">
                <div class="hlrn-info-row">
                    <div class="hlrn-info-cell">
                        <span class="hlrn-info-label"><?php echo esc_html($supervisor_label); ?></span>
                        <span class="hlrn-info-value"><?php
                            if ($context === 'coaching') {
                                echo esc_html(isset($session_entity['coach_name']) ? $session_entity['coach_name'] : '—');
                            } else {
                                echo esc_html(isset($session_entity['mentor_name']) ? $session_entity['mentor_name'] : '—');
                            }
                        ?></span>
                    </div>
                    <div class="hlrn-info-cell">
                        <span class="hlrn-info-label"><?php echo esc_html($supervisee_label); ?></span>
                        <span class="hlrn-info-value"><?php
                            if ($context === 'coaching') {
                                echo esc_html(isset($session_entity['mentor_name']) ? $session_entity['mentor_name'] : '—');
                            } else {
                                echo esc_html(isset($session_entity['teacher_name']) ? $session_entity['teacher_name'] : '—');
                            }
                        ?></span>
                    </div>
                    <div class="hlrn-info-cell">
                        <span class="hlrn-info-label"><?php esc_html_e('Date', 'hl-core'); ?></span>
                        <span class="hlrn-info-value"><?php
                            $date_field = ($context === 'coaching') ? 'session_datetime' : 'session_date';
                            $date_val = isset($session_entity[$date_field]) ? $session_entity[$date_field] : '';
                            echo esc_html($date_val ? date_i18n(get_option('date_format'), strtotime($date_val)) : date_i18n(get_option('date_format')));
                        ?></span>
                    </div>
                </div>
                <div class="hlrn-info-row">
                    <div class="hlrn-info-cell">
                        <span class="hlrn-info-label"><?php esc_html_e('Session #', 'hl-core'); ?></span>
                        <span class="hlrn-info-value hlrn-session-num"><?php
                            echo esc_html(isset($session_entity['session_number']) ? $session_entity['session_number'] : '—');
                        ?></span>
                    </div>
                    <?php if (!empty($progress['current_course'])) : ?>
                        <div class="hlrn-info-cell hlrn-info-cell--wide">
                            <span class="hlrn-info-label"><?php printf(esc_html__('%s\'s Current Course', 'hl-core'), esc_html($supervisee_label)); ?></span>
                            <span class="hlrn-info-value"><?php echo esc_html($progress['current_course']); ?></span>
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

                <!-- Personal Notes -->
                <div class="hlrn-section">
                    <div class="hlrn-section-header">
                        <span class="hlrn-section-num">1</span>
                        <div>
                            <span class="hlrn-section-title"><?php esc_html_e('Personal Notes', 'hl-core'); ?></span>
                            <span class="hlrn-section-hint"><?php esc_html_e('Private — not shared with the supervisee', 'hl-core'); ?></span>
                        </div>
                    </div>
                    <div class="hlrn-section-body">
                        <?php if ($is_readonly) : ?>
                            <div class="hlrn-readonly-value"><?php echo wp_kses_post(isset($responses['personal_notes']) ? $responses['personal_notes'] : ''); ?></div>
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
                    </div>
                </div>

                <!-- Session Prep (auto-populated, read-only) -->
                <div class="hlrn-section hlrn-section-auto">
                    <div class="hlrn-section-header">
                        <span class="hlrn-section-num">2</span>
                        <div>
                            <span class="hlrn-section-title"><?php esc_html_e('Session Prep', 'hl-core'); ?></span>
                            <span class="hlrn-section-hint"><?php esc_html_e('Auto-populated from system data', 'hl-core'); ?></span>
                        </div>
                    </div>
                    <div class="hlrn-section-body">

                        <!-- Pathway Progress -->
                        <div class="hlrn-prep-card">
                            <div class="hlrn-prep-card-title">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                                <?php printf(esc_html__('%s\'s Pathway Progress', 'hl-core'), esc_html($supervisee_label)); ?>
                            </div>
                            <?php
                            $completed = (int) $progress['completed_components'];
                            $total     = (int) $progress['total_components'];
                            $pct       = $total > 0 ? round(($completed / $total) * 100) : 0;
                            ?>
                            <div class="hlrn-progress">
                                <div class="hlrn-progress-info">
                                    <span><?php printf(esc_html__('%d of %d components', 'hl-core'), $completed, $total); ?></span>
                                    <span class="hlrn-progress-pct"><?php echo esc_html($pct . '%'); ?></span>
                                </div>
                                <div class="hlrn-progress-bar">
                                    <div class="hlrn-progress-fill" style="width:<?php echo esc_attr($pct); ?>%"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Previous Action Plans -->
                        <div class="hlrn-prep-card">
                            <div class="hlrn-prep-card-title">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <?php esc_html_e('Previous Action Plans', 'hl-core'); ?>
                            </div>
                            <?php if (empty($prev_plans)) : ?>
                                <p class="hlrn-muted"><?php esc_html_e('No previous action plans submitted.', 'hl-core'); ?></p>
                            <?php else : ?>
                                <div class="hlrn-scroll-list">
                                    <?php foreach ($prev_plans as $plan) :
                                        $plan_data = json_decode($plan['responses_json'], true);
                                        $plan_date = !empty($plan['submitted_at']) ? date_i18n(get_option('date_format'), strtotime($plan['submitted_at'])) : '—';

                                        // Map field keys to labels.
                                        $field_labels = array(
                                            'domain'              => __('Domain', 'hl-core'),
                                            'skills'              => __('Skills', 'hl-core'),
                                            'how'                 => __('How will you practice?', 'hl-core'),
                                            'what'                => __('What will you do?', 'hl-core'),
                                            'practice_reflection' => __('Practice Reflection', 'hl-core'),
                                            'success_degree'      => __('Degree of Success', 'hl-core'),
                                            'impact_observations' => __('Impact & Observations', 'hl-core'),
                                            'what_learned'        => __('What I Learned', 'hl-core'),
                                            'still_wondering'     => __('Still Wondering', 'hl-core'),
                                        );
                                    ?>
                                        <div class="hlrn-plan-item">
                                            <div class="hlrn-plan-header">
                                                <span class="hlrn-plan-date"><?php echo esc_html($plan_date); ?></span>
                                                <?php if (!empty($plan_data['domain'])) : ?>
                                                    <span class="hlrn-badge"><?php echo esc_html($plan_data['domain']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="hlrn-plan-fields">
                                                <?php foreach ($field_labels as $fkey => $flabel) :
                                                    if ($fkey === 'domain') continue; // already shown in header
                                                    $fval = isset($plan_data[$fkey]) ? $plan_data[$fkey] : '';
                                                    if (is_array($fval)) {
                                                        $fval = implode(', ', $fval);
                                                    }
                                                    if ($fkey === 'success_degree' && $fval) {
                                                        $fval = $fval . '/5';
                                                    }
                                                    if (empty($fval) && $fval !== '0') continue;
                                                ?>
                                                    <div class="hlrn-plan-field-row">
                                                        <span class="hlrn-plan-field-label"><?php echo esc_html($flabel); ?></span>
                                                        <span class="hlrn-plan-field-value"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($fval), 40)); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Classroom Visit & Self-Reflection Review -->
                <div class="hlrn-section hlrn-section-auto">
                    <div class="hlrn-section-header">
                        <span class="hlrn-section-num">3</span>
                        <div>
                            <span class="hlrn-section-title"><?php esc_html_e('Classroom Visit & Self-Reflection Review', 'hl-core'); ?></span>
                            <span class="hlrn-section-hint"><?php esc_html_e('Auto-populated from visit data', 'hl-core'); ?></span>
                        </div>
                    </div>
                    <div class="hlrn-section-body">
                        <?php if (empty($cv_review)) : ?>
                            <p class="hlrn-muted"><?php esc_html_e('No classroom visit data available.', 'hl-core'); ?></p>
                        <?php else : ?>
                            <?php
                            // Resolve the best available date.
                            $visit_display_date = '';
                            if (!empty($cv_review['visit_date'])) {
                                $visit_display_date = date_i18n(get_option('date_format'), strtotime($cv_review['visit_date']));
                            } elseif (!empty($cv_review['submissions'])) {
                                foreach ($cv_review['submissions'] as $_s) {
                                    if (!empty($_s['submitted_at'])) {
                                        $visit_display_date = date_i18n(get_option('date_format'), strtotime($_s['submitted_at']));
                                        break;
                                    }
                                }
                            }

                            // Organize submissions by role for tabs.
                            $observer_sub = null;
                            $reflector_sub = null;
                            foreach ($cv_review['submissions'] as $sub) {
                                if ($sub['role_in_visit'] === 'observer') $observer_sub = $sub;
                                else $reflector_sub = $sub;
                            }
                            ?>

                            <div class="hlrn-prep-card hlrn-cvr">
                                <!-- Visit context row -->
                                <div class="hlrn-cvr-meta">
                                    <span><strong><?php esc_html_e('Visit #', 'hl-core'); ?></strong> <?php echo esc_html($cv_review['visit_number']); ?></span>
                                    <span><strong><?php esc_html_e('Date:', 'hl-core'); ?></strong> <?php echo esc_html($visit_display_date ?: '—'); ?></span>
                                    <span><strong><?php esc_html_e('Observer:', 'hl-core'); ?></strong> <?php echo esc_html(isset($cv_review['leader_name']) ? $cv_review['leader_name'] : '—'); ?></span>
                                    <?php if ($observer_sub && !empty($observer_sub['submitted_by_name'])) : ?>
                                        <span><strong><?php esc_html_e('Submitted by:', 'hl-core'); ?></strong> <?php echo esc_html($observer_sub['submitted_by_name']); ?></span>
                                    <?php endif; ?>
                                </div>

                                <!-- Side-by-side columns -->
                                <div class="hlrn-cvr-cols">
                                    <!-- Classroom Visit column -->
                                    <div class="hlrn-cvr-col">
                                        <div class="hlrn-cvr-col-hdr">
                                            <strong><?php esc_html_e('Classroom Visit', 'hl-core'); ?></strong>
                                            <?php if ($observer_sub) : ?>
                                                <span class="hlrn-cvr-col-badge <?php echo $observer_sub['status'] === 'submitted' ? 'hlrn-cvr-col-badge--submitted' : 'hlrn-cvr-col-badge--draft'; ?>"><?php echo esc_html(ucfirst($observer_sub['status'])); ?></span>
                                                <?php if (!empty($observer_sub['submitted_by_name'])) : ?>
                                                    <span class="hlrn-cvr-col-by"><?php echo esc_html($observer_sub['submitted_by_name']); ?><?php if (!empty($observer_sub['submitted_at'])) echo ' &middot; ' . esc_html(date_i18n('M j', strtotime($observer_sub['submitted_at']))); ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="hlrn-cvr-col-body">
                                            <?php if ($observer_sub) :
                                                $obs_data = json_decode($observer_sub['responses_json'], true);
                                                $obs_sections = $this->get_instrument_sections('classroom_visit_form');
                                                if ($obs_data && is_array($obs_data) && $obs_sections) : ?>
                                                    <div class="hlrn-cv-full-data">
                                                        <?php HL_Frontend_Classroom_Visit::render_visit_form_sections($obs_sections, $obs_data, true, 'hl_cv'); ?>
                                                    </div>
                                                <?php else : ?>
                                                    <div class="hlrn-cvr-empty"><?php esc_html_e('No data', 'hl-core'); ?></div>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <div class="hlrn-cvr-empty"><?php esc_html_e('Not yet submitted', 'hl-core'); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Self-Reflection column -->
                                    <div class="hlrn-cvr-col">
                                        <div class="hlrn-cvr-col-hdr">
                                            <strong><?php esc_html_e('Self-Reflection', 'hl-core'); ?></strong>
                                            <?php if ($reflector_sub) : ?>
                                                <span class="hlrn-cvr-col-badge <?php echo $reflector_sub['status'] === 'submitted' ? 'hlrn-cvr-col-badge--submitted' : 'hlrn-cvr-col-badge--draft'; ?>"><?php echo esc_html(ucfirst($reflector_sub['status'])); ?></span>
                                                <?php if (!empty($reflector_sub['submitted_by_name'])) : ?>
                                                    <span class="hlrn-cvr-col-by"><?php echo esc_html($reflector_sub['submitted_by_name']); ?><?php if (!empty($reflector_sub['submitted_at'])) echo ' &middot; ' . esc_html(date_i18n('M j', strtotime($reflector_sub['submitted_at']))); ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="hlrn-cvr-col-body">
                                            <?php if ($reflector_sub) :
                                                $ref_data = json_decode($reflector_sub['responses_json'], true);
                                                $ref_sections = $this->get_instrument_sections('self_reflection_form');
                                                if ($ref_data && is_array($ref_data) && $ref_sections) : ?>
                                                    <div class="hlrn-cv-full-data">
                                                        <?php HL_Frontend_Classroom_Visit::render_visit_form_sections($ref_sections, $ref_data, true, 'hl_sr'); ?>
                                                    </div>
                                                <?php else : ?>
                                                    <div class="hlrn-cvr-empty"><?php esc_html_e('No data', 'hl-core'); ?></div>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <div class="hlrn-cvr-empty"><?php esc_html_e('Not yet submitted', 'hl-core'); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <script>
                                /* Equalize domain block heights across columns so they align for comparison. */
                                (function(){
                                    function equalizeRows(){
                                        var cols = document.querySelectorAll('.hlrn-cvr-col-body');
                                        if (cols.length < 2) return;
                                        var leftDomains = cols[0].querySelectorAll('.hlcv-domain-flat');
                                        var rightDomains = cols[1].querySelectorAll('.hlcv-domain-flat');
                                        var count = Math.max(leftDomains.length, rightDomains.length);
                                        // Reset heights first
                                        for (var i = 0; i < count; i++) {
                                            if (leftDomains[i]) leftDomains[i].style.minHeight = '';
                                            if (rightDomains[i]) rightDomains[i].style.minHeight = '';
                                        }
                                        // Set matching min-heights
                                        for (var i = 0; i < count; i++) {
                                            var lh = leftDomains[i] ? leftDomains[i].offsetHeight : 0;
                                            var rh = rightDomains[i] ? rightDomains[i].offsetHeight : 0;
                                            var max = Math.max(lh, rh);
                                            if (leftDomains[i]) leftDomains[i].style.minHeight = max + 'px';
                                            if (rightDomains[i]) rightDomains[i].style.minHeight = max + 'px';
                                        }
                                        // Also equalize context sections
                                        var leftCtx = cols[0].querySelectorAll('.hlcv-context');
                                        var rightCtx = cols[1].querySelectorAll('.hlcv-context');
                                        var ctxCount = Math.max(leftCtx.length, rightCtx.length);
                                        for (var i = 0; i < ctxCount; i++) {
                                            if (leftCtx[i]) leftCtx[i].style.minHeight = '';
                                            if (rightCtx[i]) rightCtx[i].style.minHeight = '';
                                            var lch = leftCtx[i] ? leftCtx[i].offsetHeight : 0;
                                            var rch = rightCtx[i] ? rightCtx[i].offsetHeight : 0;
                                            var cmax = Math.max(lch, rch);
                                            if (leftCtx[i]) leftCtx[i].style.minHeight = cmax + 'px';
                                            if (rightCtx[i]) rightCtx[i].style.minHeight = cmax + 'px';
                                        }
                                    }
                                    // Run after DOM paint
                                    if (document.readyState === 'complete') { equalizeRows(); }
                                    else { window.addEventListener('load', equalizeRows); }
                                    // Re-equalize on resize
                                    window.addEventListener('resize', equalizeRows);
                                })();
                                </script>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RP Session Notes (editable) -->
                <div class="hlrn-section">
                    <div class="hlrn-section-header">
                        <span class="hlrn-section-num">4</span>
                        <span class="hlrn-section-title"><?php esc_html_e('RP Session Notes', 'hl-core'); ?></span>
                    </div>
                    <div class="hlrn-section-body">
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
                            <div class="hlrn-field">
                                <label class="hlrn-field-label"><?php echo esc_html($field_label); ?></label>
                                <?php if ($is_readonly) : ?>
                                    <div class="hlrn-readonly-value"><?php echo wp_kses_post($field_value); ?></div>
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
                        <div class="hlrn-field">
                            <label class="hlrn-field-label" for="hl-rp-next-date"><?php esc_html_e('Next Session Date', 'hl-core'); ?></label>
                            <?php
                            $next_date = isset($responses['next_session_date']) ? $responses['next_session_date'] : '';
                            ?>
                            <?php if ($is_readonly) : ?>
                                <div class="hlrn-readonly-value"><?php echo esc_html($next_date ? date_i18n(get_option('date_format'), strtotime($next_date)) : '—'); ?></div>
                            <?php else : ?>
                                <input type="date" name="hl_rp_notes[next_session_date]" id="hl-rp-next-date"
                                       value="<?php echo esc_attr($next_date); ?>" class="hlrn-date-input">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($is_edit_mode) : ?>
                    <div class="hlrn-actions hlrn-actions--edit-mode" data-edit-mode="true">
                        <a href="<?php echo esc_url(remove_query_arg('rpn_edit')); ?>" class="hlrn-btn hlrn-btn-cancel" data-hlrn-cancel="1">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            <?php esc_html_e('Cancel', 'hl-core'); ?>
                        </a>
                        <button type="submit" name="hl_rp_notes_action" value="submit" class="hlrn-btn hlrn-btn-submit" data-hlrn-save="1" disabled>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php esc_html_e('Save Changes', 'hl-core'); ?>
                        </button>
                        <span class="hlrn-dirty-hint" aria-live="polite"><?php esc_html_e('No changes yet', 'hl-core'); ?></span>
                    </div>
                <?php elseif (!$is_readonly) : ?>
                    <div class="hlrn-actions">
                        <button type="submit" name="hl_rp_notes_action" value="draft" class="hlrn-btn hlrn-btn-draft">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <?php esc_html_e('Save Draft', 'hl-core'); ?>
                        </button>
                        <button type="submit" name="hl_rp_notes_action" value="submit" class="hlrn-btn hlrn-btn-submit">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php esc_html_e('Submit Notes', 'hl-core'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- RP Steps Guide button (inline, not fixed — avoids chat widget overlap) -->
        <div class="hlrn-guide-trigger">
            <button type="button" id="hlrn-guide-toggle" class="hlrn-guide-btn" title="<?php esc_attr_e('RP Steps Guide', 'hl-core'); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <?php esc_html_e('RP Steps Guide', 'hl-core'); ?>
            </button>
        </div>

        <div id="hlrn-guide-drawer" class="hlrn-guide-drawer" style="display:none">
            <div class="hlrn-guide-drawer-header">
                <h3 class="hlrn-guide-drawer-title"><?php esc_html_e('RP Steps Guide', 'hl-core'); ?></h3>
                <p class="hlrn-guide-drawer-sub"><?php esc_html_e('Use these guiding questions throughout your session', 'hl-core'); ?></p>
                <button type="button" id="hlrn-guide-close" class="hlrn-guide-close">&times;</button>
            </div>
            <div class="hlrn-guide-drawer-body">
                <?php self::render_rp_guide_steps(); ?>
            </div>
        </div>
        <div id="hlrn-guide-overlay" class="hlrn-guide-overlay" style="display:none"></div>

        <script>
        (function(){
            var toggle = document.getElementById('hlrn-guide-toggle');
            var drawer = document.getElementById('hlrn-guide-drawer');
            var overlay = document.getElementById('hlrn-guide-overlay');
            var close = document.getElementById('hlrn-guide-close');
            if (!toggle || !drawer) return;
            function open() { drawer.style.display = ''; overlay.style.display = ''; document.body.style.overflow = 'hidden'; }
            function shut() { drawer.style.display = 'none'; overlay.style.display = 'none'; document.body.style.overflow = ''; }
            toggle.addEventListener('click', open);
            close.addEventListener('click', shut);
            overlay.addEventListener('click', shut);
        })();
        </script>

        <!-- JS for edit-submission mode: dirty tracker (TinyMCE-aware), Cancel confirm, beforeunload warn -->
        <script>
        (function(){
            var wrapper = document.querySelector('.hlrn-form-wrapper[data-hlrn-state="editing-submission"]');
            if (!wrapper) return;
            var form      = wrapper.querySelector('form');
            var saveBtn   = wrapper.querySelector('[data-hlrn-save="1"]');
            var cancelA   = wrapper.querySelector('[data-hlrn-cancel="1"]');
            var dirtyHint = wrapper.querySelector('.hlrn-dirty-hint');
            if (!form || !saveBtn) return;

            var labelClean = <?php echo wp_json_encode(__('No changes yet', 'hl-core')); ?>;
            var labelDirty = <?php echo wp_json_encode(__('Unsaved changes', 'hl-core')); ?>;
            var cancelMsg  = <?php echo wp_json_encode(__('Discard your changes and return to the submitted version?', 'hl-core')); ?>;
            var leaveMsg   = <?php echo wp_json_encode(__('You have unsaved changes to your RP Notes. Leave anyway?', 'hl-core')); ?>;

            function syncTinyMce() {
                if (window.tinymce && window.tinymce.triggerSave) {
                    try { window.tinymce.triggerSave(); } catch(e) {}
                }
            }

            function serialize() {
                syncTinyMce();
                var fd = new FormData(form);
                var pairs = [];
                fd.forEach(function(v, k){ pairs.push(k + '=' + (typeof v === 'string' ? v : '')); });
                pairs.sort();
                return pairs.join('&');
            }
            var initialSerialized = serialize();
            var isDirty = false;
            var suppressBeforeUnload = false;

            function checkDirty() {
                var nowDirty = serialize() !== initialSerialized;
                if (nowDirty !== isDirty) {
                    isDirty = nowDirty;
                    saveBtn.disabled = !isDirty;
                    if (dirtyHint) {
                        dirtyHint.textContent = isDirty ? labelDirty : labelClean;
                        dirtyHint.classList.toggle('hlrn-dirty-hint--dirty', isDirty);
                    }
                }
            }

            form.addEventListener('input',  checkDirty);
            form.addEventListener('change', checkDirty);

            // TinyMCE dispatches its own events on a separate iframe. Hook them.
            function hookTinyMce() {
                if (!window.tinymce) return;
                (window.tinymce.editors || []).forEach(function(ed){
                    if (ed.__hlrnBound) return;
                    ed.__hlrnBound = true;
                    ed.on('input change keyup', checkDirty);
                });
            }
            if (window.tinymce) {
                if (window.tinymce.on) {
                    window.tinymce.on('AddEditor', hookTinyMce);
                }
                // Hook any already-initialised editors shortly after DOM ready.
                setTimeout(hookTinyMce, 300);
                setTimeout(hookTinyMce, 1500);
            }

            if (cancelA) {
                cancelA.addEventListener('click', function(e){
                    if (isDirty && !window.confirm(cancelMsg)) {
                        e.preventDefault();
                        return;
                    }
                    suppressBeforeUnload = true;
                });
            }

            form.addEventListener('submit', function(){
                syncTinyMce();
                suppressBeforeUnload = true;
            });

            window.addEventListener('beforeunload', function(e){
                if (isDirty && !suppressBeforeUnload) {
                    e.preventDefault();
                    e.returnValue = leaveMsg;
                    return leaveMsg;
                }
            });
        })();
        </script>
        <?php

        return ob_get_clean();
    }

    /**
     * Render the RP Steps Guide as styled flat cards (no accordion).
     */
    private static function render_rp_guide_steps() {
        $steps = array(
            array('icon' => 'eye',      'title' => __('Description', 'hl-core'),   'questions' => array(
                __('What happened?', 'hl-core'),
                __('What did you observe?', 'hl-core'),
                __('Describe the situation or event without judgment.', 'hl-core'),
            )),
            array('icon' => 'heart',    'title' => __('Feelings', 'hl-core'),      'questions' => array(
                __('What were you feeling during and after the event?', 'hl-core'),
                __('What do you think the children were feeling?', 'hl-core'),
            )),
            array('icon' => 'check',    'title' => __('Evaluation', 'hl-core'),    'questions' => array(
                __('What went well?', 'hl-core'),
                __('What didn\'t go so well?', 'hl-core'),
                __('What was positive or negative about the experience?', 'hl-core'),
            )),
            array('icon' => 'search',   'title' => __('Analysis', 'hl-core'),      'questions' => array(
                __('Why did things go the way they did?', 'hl-core'),
                __('What contributed to the outcome?', 'hl-core'),
                __('What could you have done differently?', 'hl-core'),
            )),
            array('icon' => 'star',     'title' => __('Conclusion', 'hl-core'),    'questions' => array(
                __('What did you learn from this experience?', 'hl-core'),
                __('What would you do differently next time?', 'hl-core'),
            )),
            array('icon' => 'target',   'title' => __('Action Plan', 'hl-core'),   'questions' => array(
                __('What specific steps will you take going forward?', 'hl-core'),
                __('How will you implement what you\'ve learned?', 'hl-core'),
            )),
        );

        $icons = array(
            'eye'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
            'heart'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
            'check'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
            'search' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
            'star'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
            'target' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
        );

        echo '<div class="hlrn-rp-steps">';
        foreach ($steps as $idx => $step) {
            $num = $idx + 1;
            echo '<div class="hlrn-rp-step">';
            echo '<div class="hlrn-rp-step-icon">' . $icons[$step['icon']] . '</div>';
            echo '<div>';
            echo '<div class="hlrn-rp-step-title">' . esc_html($num . '. ' . $step['title']) . '</div>';
            echo '<ul class="hlrn-rp-step-questions">';
            foreach ($step['questions'] as $q) {
                echo '<li>' . esc_html($q) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    // Styles are in assets/css/frontend.css under "FORMS & INSTRUMENTS (Session 2)"

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
        $session_id    = isset($_POST['hl_rp_notes_session_id']) ? absint(wp_unslash($_POST['hl_rp_notes_session_id'])) : 0;
        $instrument_id = isset($_POST['hl_rp_notes_instrument_id']) ? absint(wp_unslash($_POST['hl_rp_notes_instrument_id'])) : 0;
        $action        = isset($_POST['hl_rp_notes_action']) ? sanitize_text_field(wp_unslash($_POST['hl_rp_notes_action'])) : '';
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

        $service = ($context === 'coaching')
            ? new HL_Coaching_Service()
            : new HL_RP_Session_Service();

        // Pre-check: is the (session, role) row already submitted? If so, this POST
        // is an edit — we keep the original submitted_at and skip completion-state
        // side effects (component_state, rollups) so a re-submit does NOT look like
        // a fresh completion to downstream systems.
        $was_already_submitted = false;
        if ($status === 'submitted') {
            foreach ($service->get_submissions($session_id) as $sub) {
                if ($sub['role_in_session'] === $role && $sub['status'] === 'submitted') {
                    $was_already_submitted = true;
                    break;
                }
            }
        }

        $result = $service->submit_form($session_id, $user_id, $instrument_id, $role, $responses_json, $status);

        // First-time completion side effects (mentoring / RP Session context only).
        if ($context === 'mentoring'
            && $status === 'submitted'
            && !is_wp_error($result)
            && !$was_already_submitted
        ) {
            $session = $service->get_session($session_id);
            if ($session) {
                $enrollment_id  = (int) $session['mentor_enrollment_id'];
                $cycle_id       = (int) $session['cycle_id'];
                $session_number = (int) $session['session_number'];
                $service->update_component_state($enrollment_id, $cycle_id, $session_number);
            }
        }

        return array(
            'submission_id' => $result,
            'status'        => $status,
            'message'       => ($status === 'submitted') ? 'submitted' : 'saved',
        );
    }

    /**
     * Load instrument sections by instrument_key.
     *
     * @param string $instrument_key E.g. 'classroom_visit_form' or 'self_reflection_form'.
     * @return array|null Sections array or null if not found.
     */
    private function get_instrument_sections($instrument_key) {
        global $wpdb;
        $sections_json = $wpdb->get_var($wpdb->prepare(
            "SELECT sections FROM {$wpdb->prefix}hl_teacher_assessment_instrument
             WHERE instrument_key = %s AND status = 'active' LIMIT 1",
            $instrument_key
        ));
        if (!$sections_json) return null;
        $sections = json_decode($sections_json, true);
        return is_array($sections) ? $sections : null;
    }
}
