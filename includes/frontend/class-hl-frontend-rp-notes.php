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

        self::render_styles();
        ?>
        <div class="hlrn-form-wrapper">

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

            <?php if ($is_readonly) : ?>
                <div class="hlrn-alert hlrn-alert-info">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <?php esc_html_e('This form has been submitted and is read-only.', 'hl-core'); ?>
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
                <div class="hlrn-info-row" style="margin-top:12px">
                    <div class="hlrn-info-cell">
                        <span class="hlrn-info-label"><?php esc_html_e('Session #', 'hl-core'); ?></span>
                        <span class="hlrn-info-value hlrn-session-num"><?php
                            echo esc_html(isset($session_entity['session_number']) ? $session_entity['session_number'] : '—');
                        ?></span>
                    </div>
                    <?php if (!empty($progress['current_course'])) : ?>
                        <div class="hlrn-info-cell" style="grid-column:span 2">
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
                                    ?>
                                        <div class="hlrn-plan-item">
                                            <div class="hlrn-plan-header">
                                                <span class="hlrn-plan-date"><?php echo esc_html($plan_date); ?></span>
                                                <?php if (!empty($plan_data['domain'])) : ?>
                                                    <span class="hlrn-badge"><?php echo esc_html($plan_data['domain']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($plan_data['how'])) : ?>
                                                <p class="hlrn-plan-excerpt"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($plan_data['how']), 30)); ?></p>
                                            <?php endif; ?>
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

                            <style>
                            /* Side-by-side CV review */
                            .hlrn-cvr{font-size:13px}
                            .hlrn-cvr-meta{display:flex;flex-wrap:wrap;gap:4px 14px;margin-bottom:10px;font-size:12px;color:#64748b}
                            .hlrn-cvr-meta strong{color:#334155}
                            .hlrn-cvr-cols{display:grid;grid-template-columns:1fr 1fr;gap:12px}
                            @media(max-width:900px){.hlrn-cvr-cols{grid-template-columns:1fr}}
                            .hlrn-cvr-col{border:1px solid #e2e8f0;border-radius:8px;overflow:hidden}
                            .hlrn-cvr-col-hdr{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
                            .hlrn-cvr-col-hdr strong{font-size:13px;color:#1e293b}
                            .hlrn-cvr-col-badge{display:inline-block;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:600}
                            .hlrn-cvr-col-by{font-size:10px;color:#94a3b8;margin-left:auto;white-space:nowrap}
                            .hlrn-cvr-col-body{padding:8px 10px}
                            .hlrn-cvr-empty{padding:20px;text-align:center;color:#94a3b8;font-size:12px}
                            /* Compact form rendering */
                            .hlrn-cv-full-data .hlcv-context{margin:4px 0 8px;padding:6px 10px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0}
                            .hlrn-cv-full-data .hlcv-context-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#475569;margin-bottom:4px}
                            .hlrn-cv-full-data .hlcv-pills{display:flex;flex-wrap:wrap;gap:3px}
                            .hlrn-cv-full-data .hlcv-ro-badge{display:inline-block;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:500}
                            .hlrn-cv-full-data .hlcv-ro-yes{background:#d1fae5;color:#065f46}
                            .hlrn-cv-full-data .hlcv-ro-no{background:#fee2e2;color:#991b1b}
                            .hlrn-cv-full-data .hlcv-domain-flat{margin:6px 0;padding:6px 8px;background:#fff;border:1px solid #e2e8f0;border-radius:6px}
                            .hlrn-cv-full-data .hlcv-domain-flat-header{display:flex;align-items:center;gap:5px;margin-bottom:4px}
                            .hlrn-cv-full-data .hlcv-domain-num{display:flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:linear-gradient(135deg,#1e3a5f,#2d5f8a);color:#fff;font-size:9px;font-weight:700;flex-shrink:0}
                            .hlrn-cv-full-data .hlcv-domain-flat-title{font-size:12px;font-weight:600;color:#1e293b}
                            .hlrn-cv-full-data .hlcv-indicators-grid{display:flex;flex-direction:column;gap:2px}
                            .hlrn-cv-full-data .hlcv-ind-row{display:flex;align-items:center;justify-content:space-between;padding:3px 6px;background:#f8fafc;border-radius:4px;border:1px solid #f1f5f9}
                            .hlrn-cv-full-data .hlcv-ind-label{font-size:11px;color:#334155;flex:1}
                            .hlrn-cv-full-data .hlcv-domain-desc-ro{margin-top:4px;padding:5px 8px;background:#eff6ff;border-radius:5px;border:1px solid #bfdbfe}
                            .hlrn-cv-full-data .hlcv-domain-desc-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#3b82f6;margin-bottom:1px}
                            .hlrn-cv-full-data .hlcv-domain-desc-text{font-size:11px;color:#1e40af;line-height:1.4}
                            </style>

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
                                                <span class="hlrn-cvr-col-badge" style="background:<?php echo $observer_sub['status'] === 'submitted' ? '#d1fae5;color:#065f46' : '#f1f5f9;color:#64748b'; ?>"><?php echo esc_html(ucfirst($observer_sub['status'])); ?></span>
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
                                                <span class="hlrn-cvr-col-badge" style="background:<?php echo $reflector_sub['status'] === 'submitted' ? '#d1fae5;color:#065f46' : '#f1f5f9;color:#64748b'; ?>"><?php echo esc_html(ucfirst($reflector_sub['status'])); ?></span>
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

                <?php if (!$is_readonly) : ?>
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

        <!-- RP Steps Guide floating drawer -->
        <button type="button" id="hlrn-guide-toggle" class="hlrn-guide-fab" title="<?php esc_attr_e('RP Steps Guide', 'hl-core'); ?>">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span><?php esc_html_e('RP Guide', 'hl-core'); ?></span>
        </button>

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

    /**
     * Inline styles for the RP Notes form.
     */
    private static function render_styles() {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        ?>
        <style>
        .hlrn-form-wrapper{max-width:820px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
        .hlrn-hero{display:flex;align-items:center;gap:16px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff !important;padding:28px 32px;border-radius:16px;margin-bottom:24px;position:relative;z-index:1;overflow:visible}
        .hlrn-hero *{color:#fff !important}
        .hlrn-hero-icon{background:rgba(255,255,255,.15);border-radius:12px;padding:12px;display:flex;align-items:center;justify-content:center}
        .hlrn-hero-title{font-size:22px;font-weight:700;margin:0;letter-spacing:-.3px;color:#fff !important}
        .hlrn-hero-sub{font-size:14px;opacity:.8;margin:4px 0 0;color:rgba(255,255,255,.8) !important}

        .hlrn-alert{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:10px;font-size:14px;margin-bottom:20px}
        .hlrn-alert-info{background:#e8f4fd;color:#1e5f8a;border:1px solid #b8daef}

        .hlrn-info-card{background:#f8f9fb;border:1px solid #e2e8f0;border-radius:14px;padding:20px 24px;margin-bottom:28px}
        .hlrn-info-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px}
        .hlrn-info-cell{display:flex;flex-direction:column;gap:4px}
        .hlrn-info-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:#8896a6}
        .hlrn-info-value{font-size:15px;font-weight:600;color:#1e293b}
        .hlrn-session-num{display:inline-flex;align-items:center;justify-content:center;background:#1e3a5f;color:#fff;width:28px;height:28px;border-radius:8px;font-size:14px}

        /* Sections */
        .hlrn-section{background:#fff;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:20px;overflow:hidden}
        .hlrn-section-auto{background:#fafbfc}
        .hlrn-section-header{display:flex;align-items:center;gap:12px;padding:16px 20px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-bottom:1px solid #e2e8f0}
        .hlrn-section-num{background:#1e3a5f;color:#fff;font-size:11px;font-weight:700;width:24px;height:24px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
        .hlrn-section-title{font-size:15px;font-weight:600;color:#1e293b;display:block}
        .hlrn-section-hint{font-size:12px;color:#8896a6;display:block;margin-top:2px}
        .hlrn-section-body{padding:20px}

        /* Fields */
        .hlrn-field{margin-bottom:20px}
        .hlrn-field:last-child{margin-bottom:0}
        .hlrn-field-label{display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:8px;text-transform:uppercase;letter-spacing:.3px}
        .hlrn-readonly-value{background:#f1f5f9;padding:12px 16px;border-radius:10px;font-size:14px;color:#334155;line-height:1.6;min-height:20px}
        .hlrn-date-input{padding:10px 14px;border:2px solid #e2e8f0;border-radius:10px;font-size:14px;font-weight:500;color:#1e293b;background:#fff;font-family:inherit;transition:border-color .2s}
        .hlrn-date-input:focus{outline:none;border-color:#2d5f8a;box-shadow:0 0 0 3px rgba(45,95,138,.1)}
        .hlrn-muted{color:#94a3b8;font-size:14px;margin:0}

        /* Prep cards */
        .hlrn-prep-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:16px}
        .hlrn-prep-card:last-child{margin-bottom:0}
        .hlrn-prep-card-title{font-size:13px;font-weight:700;color:#475569;margin-bottom:12px;display:flex;align-items:center;gap:8px;text-transform:uppercase;letter-spacing:.5px}

        /* Progress bar */
        .hlrn-progress{margin-top:4px}
        .hlrn-progress-info{display:flex;justify-content:space-between;font-size:13px;color:#64748b;margin-bottom:8px}
        .hlrn-progress-pct{font-weight:700;color:#1e3a5f}
        .hlrn-progress-bar{height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden}
        .hlrn-progress-fill{height:100%;background:linear-gradient(90deg,#1e3a5f,#2d5f8a);border-radius:4px;transition:width .5s ease}

        /* Scroll list */
        .hlrn-scroll-list{max-height:260px;overflow-y:auto}
        .hlrn-plan-item{padding:12px;border:1px solid #f1f5f9;border-radius:8px;margin-bottom:8px;background:#fafbfc}
        .hlrn-plan-item:last-child{margin-bottom:0}
        .hlrn-plan-header{display:flex;align-items:center;gap:8px;margin-bottom:4px}
        .hlrn-plan-date{font-size:13px;font-weight:600;color:#1e293b}
        .hlrn-plan-excerpt{font-size:13px;color:#64748b;margin:0;line-height:1.5}
        .hlrn-badge{display:inline-flex;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:600;background:#e8f4fd;color:#1e5f8a}
        .hlrn-badge-yes{background:#d1fae5;color:#065f46}
        .hlrn-badge-no{background:#f1f5f9;color:#64748b}

        /* CV review */
        .hlrn-cv-sub{border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-top:12px}
        .hlrn-cv-sub-header{display:flex;align-items:center;gap:8px;margin-bottom:8px}
        .hlrn-cv-domain{margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9}
        .hlrn-cv-domain-name{font-size:12px;font-weight:600;color:#475569;margin-bottom:4px}
        .hlrn-cv-indicators{display:flex;flex-wrap:wrap;gap:4px}

        /* RP Steps Guide — drawer */
        .hlrn-guide-fab{position:fixed;bottom:24px;right:24px;z-index:9998;display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:50px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;border:none;cursor:pointer;font-size:14px;font-weight:600;font-family:inherit;box-shadow:0 4px 20px rgba(30,58,95,.4);transition:all .2s}
        .hlrn-guide-fab:hover{box-shadow:0 6px 28px rgba(30,58,95,.5);transform:translateY(-2px)}
        .hlrn-guide-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9998}
        .hlrn-guide-drawer{position:fixed;top:0;right:0;bottom:0;width:420px;max-width:90vw;background:#fff;z-index:9999;box-shadow:-4px 0 24px rgba(0,0,0,.15);display:flex;flex-direction:column;overflow:hidden}
        .hlrn-guide-drawer-header{padding:24px 24px 16px;border-bottom:1px solid #e2e8f0;position:relative}
        .hlrn-guide-drawer-title{font-size:20px;font-weight:700;color:#1e293b;margin:0}
        .hlrn-guide-drawer-sub{font-size:13px;color:#64748b;margin:6px 0 0}
        .hlrn-guide-close{position:absolute;top:16px;right:16px;background:none;border:none;font-size:28px;color:#94a3b8;cursor:pointer;line-height:1;padding:4px}
        .hlrn-guide-close:hover{color:#1e293b}
        .hlrn-guide-drawer-body{padding:20px 24px;overflow-y:auto;flex:1}
        .hlrn-rp-steps{display:flex;flex-direction:column;gap:12px}
        .hlrn-rp-step{display:flex;gap:12px;padding:14px 16px;background:#f8f9fb;border:1px solid #e2e8f0;border-radius:10px}
        .hlrn-rp-step-icon{flex-shrink:0;width:32px;height:32px;border-radius:8px;background:#e2e8f0;display:flex;align-items:center;justify-content:center;color:#64748b}
        .hlrn-rp-step-title{font-size:14px;font-weight:700;color:#1e293b;margin-bottom:6px}
        .hlrn-rp-step-questions{margin:0;padding-left:18px;list-style:disc}
        .hlrn-rp-step-questions li{font-size:13px;color:#64748b;line-height:1.5;margin-bottom:6px}
        .hlrn-rp-step-questions li:last-child{margin-bottom:0}

        /* TinyMCE toolbar fix — prevent theme from darkening icons */
        .hlrn-form-wrapper .mce-toolbar .mce-btn{background:transparent !important;border-color:transparent !important}
        .hlrn-form-wrapper .mce-toolbar .mce-btn button{background-color:transparent !important;color:#555 !important}
        .hlrn-form-wrapper .mce-toolbar .mce-btn:hover,.hlrn-form-wrapper .mce-toolbar .mce-btn.mce-active{background:#e2e8f0 !important}
        .hlrn-form-wrapper .mce-ico{color:#555 !important}
        .hlrn-form-wrapper .mce-toolbar .mce-btn-group{background:transparent !important}
        .hlrn-form-wrapper .mce-panel{background:#f8f9fb !important;border-color:#e2e8f0 !important}
        .hlrn-form-wrapper .mce-edit-area{border-color:#e2e8f0 !important}
        .hlrn-form-wrapper .mce-edit-area iframe{min-height:80px}
        .hlrn-form-wrapper .wp-editor-container{border:2px solid #e2e8f0;border-radius:10px;overflow:hidden}

        /* Action buttons */
        .hlrn-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:32px;padding-top:24px;border-top:1px solid #e2e8f0}
        .hlrn-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:10px;font-size:15px;font-weight:600;border:none;cursor:pointer;transition:all .2s;font-family:inherit}
        .hlrn-btn-draft{background:#f1f5f9;color:#475569;border:2px solid #e2e8f0}
        .hlrn-btn-draft:hover{background:#e2e8f0;border-color:#cbd5e1}
        .hlrn-btn-submit{background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;border:2px solid transparent;box-shadow:0 4px 14px rgba(30,58,95,.3)}
        .hlrn-btn-submit:hover{box-shadow:0 6px 20px rgba(30,58,95,.4);transform:translateY(-1px)}

        @media(max-width:600px){
            .hlrn-hero{flex-direction:column;text-align:center;padding:24px 20px}
            .hlrn-info-row{grid-template-columns:1fr 1fr}
            .hlrn-actions{flex-direction:column}
            .hlrn-btn{justify-content:center}
        }
        </style>
        <?php
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
