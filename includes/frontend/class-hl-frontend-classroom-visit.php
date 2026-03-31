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
                // Redirect back to same page with teacher param preserved
                $redirect_url = remove_query_arg('message');
                $redirect_url = add_query_arg('message', $result['message'], $redirect_url);
                if (!empty($_POST['hl_cv_teacher_enrollment_id'])) {
                    $redirect_url = add_query_arg('teacher', absint($_POST['hl_cv_teacher_enrollment_id']), $redirect_url);
                }
                while (ob_get_level()) { ob_end_clean(); }
                if (!headers_sent()) {
                    wp_safe_redirect($redirect_url);
                    exit;
                }
                echo '<script>window.location.href=' . wp_json_encode($redirect_url) . ';</script>';
                exit;
            }
            if (is_wp_error($result)) {
                echo '<div class="hl-notice hl-notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            }
        }

        // Show success/saved message after redirect
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'submitted') {
                self::render_form_styles();
                echo '<div class="hlcv-alert" style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;margin-bottom:20px;display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:12px;font-size:14px;max-width:820px;margin-left:auto;margin-right:auto">';
                echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
                echo '<strong>' . esc_html__('Classroom visit submitted successfully!', 'hl-core') . '</strong>';
                echo '</div>';
            } elseif ($msg === 'saved') {
                self::render_form_styles();
                echo '<div class="hlcv-alert" style="background:#e8f4fd;color:#1e5f8a;border:1px solid #b8daef;margin-bottom:20px;display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:12px;font-size:14px;max-width:820px;margin-left:auto;margin-right:auto">';
                echo '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>';
                echo '<strong>' . esc_html__('Draft saved.', 'hl-core') . '</strong>';
                echo '</div>';
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
                echo '<a href="' . esc_url($back_url) . '" class="hl-back-link" style="display:block;margin-bottom:8px">&larr; ' . esc_html__('Back to Teacher List', 'hl-core') . '</a>';
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
        self::render_form_styles();
        ?>
        <div class="hl-classroom-visit-list">
            <h3><?php printf(esc_html__('Classroom Visit #%d', 'hl-core'), $visit_number); ?></h3>
            <p class="hl-field-hint"><?php esc_html_e('Select a teacher to complete the classroom visit form.', 'hl-core'); ?></p>

            <!-- What this is / isn't — shown on the list page -->
            <div class="hlcv-instructions" style="margin-bottom:24px">
                <div class="hlcv-instr-section">
                    <div class="hlcv-instr-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    </div>
                    <div>
                        <div class="hlcv-instr-heading"><?php esc_html_e('What this is', 'hl-core'); ?></div>
                        <p><?php esc_html_e('The information collected by this form is used to celebrate areas of success and recognize areas of growth. This tool provides a snapshot of skills demonstrated during a classroom visit. It is expected and appropriate that not all skills will appear in every visit.', 'hl-core'); ?></p>
                    </div>
                </div>
                <div class="hlcv-instr-section">
                    <div class="hlcv-instr-icon hlcv-instr-icon-warn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                    <div>
                        <div class="hlcv-instr-heading"><?php esc_html_e("What this isn't", 'hl-core'); ?></div>
                        <p><?php esc_html_e('This checklist is not designed or intended to formally evaluate individual performance. It should not be interpreted as a summative assessment, and it is not expected or required that every skill listed be demonstrated or identified within a single classroom visit.', 'hl-core'); ?></p>
                    </div>
                </div>
            </div>

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
        $user          = wp_get_current_user();
        $visit_date    = isset($visit_entity['visit_date']) ? $visit_entity['visit_date'] : '';
        $teacher_name  = isset($teacher_info['display_name']) ? $teacher_info['display_name'] : '—';

        // Resolve school, classroom name, and age group from teaching assignments
        global $wpdb;
        $school_name    = '—';
        $classroom_name = '—';
        $age_groups     = array();

        if (isset($visit_entity['teacher_enrollment_id'])) {
            $classrooms = $wpdb->get_results($wpdb->prepare(
                "SELECT cl.classroom_name, cl.age_band, o.name AS school_name
                 FROM {$wpdb->prefix}hl_teaching_assignment ta
                 JOIN {$wpdb->prefix}hl_classroom cl ON ta.classroom_id = cl.classroom_id
                 LEFT JOIN {$wpdb->prefix}hl_orgunit o ON cl.school_id = o.orgunit_id
                 WHERE ta.enrollment_id = %d",
                (int) $visit_entity['teacher_enrollment_id']
            ), ARRAY_A);

            if ($classrooms) {
                // School name (use first, all should be same school)
                $school_name = !empty($classrooms[0]['school_name']) ? $classrooms[0]['school_name'] : '—';

                // Classroom names
                $names = array_filter(array_unique(array_column($classrooms, 'classroom_name')));
                $classroom_name = !empty($names) ? implode(', ', $names) : '—';

                // Age bands from all classrooms
                $bands = array_filter(array_unique(array_column($classrooms, 'age_band')));
                foreach ($bands as $band) {
                    $age_groups[] = ucwords(str_replace('_', ' ', $band));
                }
            }
        }

        self::render_form_styles();
        ?>
        <div class="hlcv-form-wrapper">

            <!-- Hero header -->
            <div class="hlcv-hero">
                <div class="hlcv-hero-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                        <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                    </svg>
                </div>
                <div>
                    <h2 class="hlcv-hero-title"><?php esc_html_e('B2E Classroom Visit & Self-Reflection Tool', 'hl-core'); ?></h2>
                    <p class="hlcv-hero-sub"><?php esc_html_e('ECSEL Domains & Indicators Assessment', 'hl-core'); ?></p>
                </div>
            </div>

            <?php if ($is_readonly) : ?>
                <div class="hlcv-alert hlcv-alert-info">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <?php esc_html_e('This form has been submitted and is read-only.', 'hl-core'); ?>
                </div>
            <?php endif; ?>

            <!-- Instructions + Notes (What this is/isn't moved to teacher list page) -->
            <div class="hlcv-instructions">
                <div class="hlcv-instr-section hlcv-instr-highlight">
                    <div class="hlcv-instr-icon hlcv-instr-icon-how">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <div>
                        <div class="hlcv-instr-heading"><?php esc_html_e('Instructions', 'hl-core'); ?></div>
                        <p><?php esc_html_e('Select "Yes" if you observe an indicator in practice during the classroom visit. Select "No" if the indicator is not observed. In the Description field, describe specific examples, teacher or student behaviors, and any relevant context to support your rating.', 'hl-core'); ?></p>
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

            <!-- Visit info card -->
            <div class="hlcv-info-card">
                <div class="hlcv-info-row">
                    <div class="hlcv-info-cell">
                        <span class="hlcv-info-label"><?php esc_html_e('Center / School', 'hl-core'); ?></span>
                        <span class="hlcv-info-value"><?php echo esc_html($school_name); ?></span>
                    </div>
                    <div class="hlcv-info-cell">
                        <span class="hlcv-info-label"><?php esc_html_e('Teacher', 'hl-core'); ?></span>
                        <span class="hlcv-info-value"><?php echo esc_html($teacher_name); ?></span>
                    </div>
                    <div class="hlcv-info-cell">
                        <span class="hlcv-info-label"><?php esc_html_e('Classroom Visitor', 'hl-core'); ?></span>
                        <span class="hlcv-info-value"><?php echo esc_html($user->display_name); ?></span>
                    </div>
                </div>
                <div class="hlcv-info-row" style="margin-top:12px">
                    <div class="hlcv-info-cell">
                        <span class="hlcv-info-label"><?php esc_html_e('Classroom', 'hl-core'); ?></span>
                        <span class="hlcv-info-value"><?php echo esc_html($classroom_name); ?></span>
                    </div>
                    <div class="hlcv-info-cell">
                        <span class="hlcv-info-label"><?php esc_html_e('Age Group', 'hl-core'); ?></span>
                        <span class="hlcv-info-value"><?php echo esc_html(!empty($age_groups) ? implode(', ', $age_groups) : '—'); ?></span>
                    </div>
                    <div class="hlcv-info-cell">
                        <span class="hlcv-info-label"><?php esc_html_e('Date', 'hl-core'); ?></span>
                        <span class="hlcv-info-value"><?php echo esc_html($visit_date ? date_i18n(get_option('date_format'), strtotime($visit_date)) : date_i18n(get_option('date_format'))); ?></span>
                    </div>
                </div>
                <div class="hlcv-info-row" style="margin-top:12px">
                    <div class="hlcv-info-cell">
                        <span class="hlcv-info-label"><?php esc_html_e('Visit #', 'hl-core'); ?></span>
                        <span class="hlcv-info-value hlcv-visit-num"><?php echo esc_html(isset($visit_entity['visit_number']) ? $visit_entity['visit_number'] : '1'); ?></span>
                    </div>
                </div>
            </div>

            <form method="post" id="hl-classroom-visit-form">
                <?php if (!$is_readonly) : ?>
                    <?php wp_nonce_field('hl_classroom_visit_submit', 'hl_cv_nonce'); ?>
                    <input type="hidden" name="hl_cv_visit_id" value="<?php echo esc_attr($visit_id); ?>">
                    <input type="hidden" name="hl_cv_instrument_id" value="<?php echo esc_attr($instrument_id); ?>">
                    <input type="hidden" name="hl_cv_form_type" value="classroom_visit">
                    <input type="hidden" name="hl_cv_teacher_enrollment_id" value="<?php echo esc_attr(isset($visit_entity['teacher_enrollment_id']) ? $visit_entity['teacher_enrollment_id'] : ''); ?>">
                <?php endif; ?>

                <?php self::render_visit_form_sections($sections, $responses, $is_readonly, 'hl_cv'); ?>

                <?php if (!$is_readonly) : ?>
                    <div class="hlcv-actions">
                        <button type="submit" name="hl_cv_action" value="draft" class="hlcv-btn hlcv-btn-draft">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <?php esc_html_e('Save Draft', 'hl-core'); ?>
                        </button>
                        <button type="submit" name="hl_cv_action" value="submit" class="hlcv-btn hlcv-btn-submit">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
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
     * Modern form styles (inline to avoid external CSS dependency).
     */
    public static function render_form_styles() {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        ?>
        <style>
        .hlcv-form-wrapper{max-width:820px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
        .hlcv-hero{display:flex;align-items:center;gap:16px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff !important;padding:28px 32px;border-radius:16px;margin-bottom:24px;position:relative;z-index:1;overflow:visible}
        .hlcv-hero *{color:#fff !important}
        .hlcv-hero-icon{background:rgba(255,255,255,.15);border-radius:12px;padding:12px;display:flex;align-items:center;justify-content:center}
        .hlcv-hero-title{font-size:22px;font-weight:700;margin:0;letter-spacing:-.3px;color:#fff !important}
        .hlcv-hero-sub{font-size:14px;opacity:.8;margin:4px 0 0;color:rgba(255,255,255,.8) !important}
        .hlcv-alert{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:12px;font-size:14px;margin-bottom:20px}
        .hlcv-alert-info{background:#e8f4fd;color:#1e5f8a;border:1px solid #b8daef}
        .hlcv-info-card{background:#f8f9fb;border:1px solid #e2e8f0;border-radius:14px;padding:20px 24px;margin-bottom:28px}
        .hlcv-info-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px}
        .hlcv-info-cell{display:flex;flex-direction:column;gap:4px}
        .hlcv-info-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:#8896a6}
        .hlcv-info-value{font-size:15px;font-weight:600;color:#1e293b}
        .hlcv-visit-num{display:inline-flex;align-items:center;justify-content:center;background:#1e3a5f;color:#fff;width:28px;height:28px;border-radius:8px;font-size:14px}

        /* Context pills */
        .hlcv-context{margin-bottom:28px}
        .hlcv-context-title{font-size:14px;font-weight:600;color:#475569;margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px}
        .hlcv-pills{display:flex;flex-wrap:wrap;gap:10px}
        .hlcv-pill{position:relative}
        .hlcv-pill input{position:absolute;opacity:0;pointer-events:none}
        .hlcv-pill-label{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border:2px solid #e2e8f0;border-radius:50px;font-size:14px;font-weight:500;color:#64748b;cursor:pointer;transition:all .2s ease;background:#fff;user-select:none}
        .hlcv-pill-label:hover{border-color:#94a3b8;color:#334155}
        .hlcv-pill input:checked+.hlcv-pill-label{background:#1e3a5f;border-color:#1e3a5f;color:#fff;box-shadow:0 2px 8px rgba(0,0,0,.08)}
        .hlcv-pill-dot{width:8px;height:8px;border-radius:50%;background:#cbd5e1;transition:background .2s}
        .hlcv-pill input:checked+.hlcv-pill-label .hlcv-pill-dot{background:#6ee7b7}

        /* Domain sections — flat, no accordion */
        .hlcv-domain-flat{background:#fff;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:20px;overflow:hidden}
        .hlcv-domain-flat-header{display:flex;align-items:center;gap:10px;padding:16px 20px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-bottom:1px solid #e2e8f0}
        .hlcv-domain-flat-title{font-size:15px;font-weight:600;color:#1e293b}
        .hlcv-domain-num{background:#1e3a5f;color:#fff;font-size:11px;font-weight:700;width:24px;height:24px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}

        /* Indicator rows — compact */
        .hlcv-indicators-grid{padding:4px 20px}
        .hlcv-ind-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid #f1f5f9}
        .hlcv-ind-row:last-child{border-bottom:none}
        .hlcv-ind-label{font-size:14px;color:#334155;flex:1;line-height:1.4}
        .hlcv-toggle-group{display:flex;gap:0;border-radius:8px;overflow:hidden;border:2px solid #e2e8f0;flex-shrink:0}
        .hlcv-toggle-group label{margin:0}
        .hlcv-toggle-group input{position:absolute;opacity:0;pointer-events:none}
        .hlcv-toggle-btn{display:inline-flex;align-items:center;justify-content:center;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;background:#fff;color:#94a3b8;user-select:none;border:none}
        .hlcv-toggle-group input[value="1"]:checked+.hlcv-toggle-btn{background:#059669;color:#fff}
        .hlcv-toggle-group input[value="0"]:checked+.hlcv-toggle-btn{background:#e2e8f0;color:#64748b}

        /* One description per domain */
        .hlcv-domain-desc{padding:0 20px 16px;animation:hlcvSlideDown .3s ease}
        .hlcv-domain-desc textarea{width:100%;border:2px solid #e2e8f0;border-radius:12px;padding:12px 16px;font-size:14px;font-family:inherit;resize:vertical;min-height:70px;transition:border-color .2s;background:#fafbfc;box-sizing:border-box}
        .hlcv-domain-desc textarea:focus{outline:none;border-color:#2d5f8a;box-shadow:0 0 0 3px rgba(45,95,138,.1);background:#fff}
        .hlcv-domain-desc textarea::placeholder{color:#94a3b8}
        .hlcv-domain-desc-ro{padding:8px 20px 16px}
        .hlcv-domain-desc-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:#8896a6;margin-bottom:6px}
        .hlcv-domain-desc-text{background:#f1f5f9;padding:10px 14px;border-radius:8px;font-size:14px;color:#334155;line-height:1.5}
        @keyframes hlcvSlideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}

        /* Action buttons */
        .hlcv-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:32px;padding-top:24px;border-top:1px solid #e2e8f0}
        .hlcv-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:12px;font-size:15px;font-weight:600;border:none;cursor:pointer;transition:all .2s;font-family:inherit}
        .hlcv-btn-draft{background:#f1f5f9;color:#475569;border:2px solid #e2e8f0}
        .hlcv-btn-draft:hover{background:#e2e8f0;border-color:#cbd5e1}
        .hlcv-btn-submit{background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;border:2px solid transparent;box-shadow:0 4px 14px rgba(0,0,0,.12)}
        .hlcv-btn-submit:hover{box-shadow:0 6px 20px rgba(0,0,0,.15);transform:translateY(-1px)}

        /* Read-only badges */
        .hlcv-ro-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:50px;font-size:12px;font-weight:600}
        .hlcv-ro-yes{background:#d1fae5;color:#065f46}
        .hlcv-ro-no{background:#f1f5f9;color:#64748b}

        /* Instructions */
        .hlcv-instructions{margin-bottom:28px;display:flex;flex-direction:column;gap:12px}
        .hlcv-instr-section{display:flex;gap:14px;padding:16px 18px;background:#f8f9fb;border:1px solid #e2e8f0;border-radius:12px}
        .hlcv-instr-highlight{background:#eef6ff;border-color:#bfdbfe}
        .hlcv-instr-icon{flex-shrink:0;width:36px;height:36px;border-radius:12px;background:#e2e8f0;display:flex;align-items:center;justify-content:center;color:#64748b}
        .hlcv-instr-icon-warn{background:#fef3c7;color:#d97706}
        .hlcv-instr-icon-how{background:#dbeafe;color:#2563eb}
        .hlcv-instr-heading{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#475569;margin-bottom:4px}
        .hlcv-instr-section p{font-size:14px;line-height:1.6;color:#475569;margin:0}

        /* Notes */
        .hlcv-notes{background:#fafbfc;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-top:24px;margin-bottom:8px}
        .hlcv-notes-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#64748b;margin-bottom:10px;display:flex;align-items:center;gap:6px}
        .hlcv-notes-list{margin:0;padding-left:18px;font-size:13px;color:#64748b;line-height:1.7}
        .hlcv-notes-list li{margin-bottom:4px}
        .hlcv-notes-list em{font-style:italic;color:#475569}

        @media(max-width:600px){
            .hlcv-hero{flex-direction:column;text-align:center;padding:24px 20px}
            .hlcv-info-row{grid-template-columns:1fr 1fr}
            .hlcv-ind-top{flex-direction:column;align-items:flex-start}
            .hlcv-actions{flex-direction:column}
            .hlcv-btn{justify-content:center}
        }
        </style>
        <?php
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
        self::$domain_counter = 0; // Reset for each form render
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
        <div class="hlcv-context">
            <div class="hlcv-context-title"><?php esc_html_e('Context — Activities Observed', 'hl-core'); ?></div>
            <?php if ($is_readonly) : ?>
                <div class="hlcv-pills">
                    <?php foreach ($options as $key => $label) : ?>
                        <?php if (in_array($key, $selected, true)) : ?>
                            <span class="hlcv-ro-badge hlcv-ro-yes"><?php echo esc_html($label); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (empty($selected)) : ?>
                        <span style="color:#94a3b8;font-size:14px"><?php esc_html_e('None selected', 'hl-core'); ?></span>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div class="hlcv-pills">
                    <?php foreach ($options as $key => $label) : ?>
                        <label class="hlcv-pill">
                            <input type="checkbox" name="<?php echo esc_attr($prefix); ?>[context_activities][]"
                                   value="<?php echo esc_attr($key); ?>"
                                   <?php checked(in_array($key, $selected, true)); ?>>
                            <span class="hlcv-pill-label">
                                <span class="hlcv-pill-dot"></span>
                                <?php echo esc_html($label); ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a domain indicator checklist section.
     */
    private static $domain_counter = 0;

    private static function render_indicator_section($section, $responses, $is_readonly, $prefix) {
        self::$domain_counter++;
        $domain_key  = $section['key'];
        $indicators  = isset($section['indicators']) ? $section['indicators'] : array();
        $domain_data = isset($responses[$domain_key]) && is_array($responses[$domain_key]) ? $responses[$domain_key] : array();
        $dom_id      = 'hlcv-domain-' . self::$domain_counter;

        // Check if ANY indicator in this domain has observed=1 (for showing the shared description)
        $any_yes = false;
        $domain_description = isset($domain_data['description']) ? $domain_data['description'] : '';
        foreach ($indicators as $idx => $label) {
            $ik = 'indicator_' . $idx;
            if (isset($domain_data[$ik]) && !empty($domain_data[$ik]['observed'])) {
                $any_yes = true;
                // Migrate old per-indicator descriptions into domain description if needed
                if (empty($domain_description) && !empty($domain_data[$ik]['description'])) {
                    $domain_description = $domain_data[$ik]['description'];
                }
            }
        }

        ?>
        <div class="hlcv-domain-flat" id="<?php echo esc_attr($dom_id); ?>">
            <div class="hlcv-domain-flat-header">
                <span class="hlcv-domain-num"><?php echo esc_html(self::$domain_counter); ?></span>
                <span class="hlcv-domain-flat-title"><?php echo esc_html($section['title']); ?></span>
            </div>

            <div class="hlcv-indicators-grid">
                <?php foreach ($indicators as $idx => $indicator_label) :
                    $indicator_key = 'indicator_' . $idx;
                    $ind_data      = isset($domain_data[$indicator_key]) && is_array($domain_data[$indicator_key]) ? $domain_data[$indicator_key] : array();
                    $observed      = !empty($ind_data['observed']);
                    $field_name    = $prefix . '[' . $domain_key . '][' . $indicator_key . ']';
                ?>
                    <div class="hlcv-ind-row">
                        <div class="hlcv-ind-label"><?php echo esc_html($indicator_label); ?></div>
                        <?php if ($is_readonly) : ?>
                            <span class="hlcv-ro-badge <?php echo $observed ? 'hlcv-ro-yes' : 'hlcv-ro-no'; ?>">
                                <?php echo esc_html($observed ? __('Yes', 'hl-core') : __('No', 'hl-core')); ?>
                            </span>
                        <?php else : ?>
                            <div class="hlcv-toggle-group">
                                <label>
                                    <input type="radio" name="<?php echo esc_attr($field_name); ?>[observed]" value="1"
                                           class="hlcv-domain-radio" data-domain="<?php echo esc_attr($dom_id); ?>"
                                           <?php checked($observed); ?>>
                                    <span class="hlcv-toggle-btn"><?php esc_html_e('Yes', 'hl-core'); ?></span>
                                </label>
                                <label>
                                    <input type="radio" name="<?php echo esc_attr($field_name); ?>[observed]" value="0"
                                           class="hlcv-domain-radio" data-domain="<?php echo esc_attr($dom_id); ?>"
                                           <?php checked(!$observed); ?>>
                                    <span class="hlcv-toggle-btn"><?php esc_html_e('No', 'hl-core'); ?></span>
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // One shared description per domain ?>
            <?php if ($is_readonly && $any_yes && !empty($domain_description)) : ?>
                <div class="hlcv-domain-desc-ro">
                    <div class="hlcv-domain-desc-label"><?php esc_html_e('Description', 'hl-core'); ?></div>
                    <div class="hlcv-domain-desc-text"><?php echo wp_kses_post($domain_description); ?></div>
                </div>
            <?php elseif (!$is_readonly) : ?>
                <div class="hlcv-domain-desc" id="hl-domdesc-<?php echo esc_attr($dom_id); ?>"
                     style="<?php echo $any_yes ? '' : 'display:none;'; ?>">
                    <textarea name="<?php echo esc_attr($prefix . '[' . $domain_key . '][description]'); ?>" rows="3"
                              placeholder="<?php esc_attr_e('Describe specific examples of what you observed for this domain...', 'hl-core'); ?>"
                    ><?php echo esc_textarea($domain_description); ?></textarea>
                </div>
            <?php endif; ?>
        </div>
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
                if (!e.target.classList.contains('hlcv-domain-radio')) return;
                var domId = e.target.getAttribute('data-domain');
                if (!domId) return;
                var container = document.getElementById(domId);
                if (!container) return;
                // Check if ANY radio with value=1 is checked in this domain
                var radios = container.querySelectorAll('.hlcv-domain-radio[value="1"]');
                var anyYes = false;
                for (var i = 0; i < radios.length; i++) {
                    if (radios[i].checked) { anyYes = true; break; }
                }
                var descEl = document.getElementById('hl-domdesc-' + domId);
                if (descEl) {
                    descEl.style.display = anyYes ? '' : 'none';
                }
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

        // Age group (manual selection fallback)
        if (!empty($raw['age_group'])) {
            $responses['age_group'] = sanitize_text_field($raw['age_group']);
        }

        // Context activities
        $responses['context_activities'] = isset($raw['context_activities']) && is_array($raw['context_activities'])
            ? array_map('sanitize_text_field', $raw['context_activities'])
            : array();

        // Domain indicator data — collect any key matching domain_* pattern
        foreach ($raw as $key => $value) {
            if (strpos($key, 'domain_') !== 0 || !is_array($value)) {
                continue;
            }
            $domain_key = sanitize_key($key);
            $responses[$domain_key] = array();

            // Domain-level shared description
            if (isset($value['description']) && is_string($value['description'])) {
                $responses[$domain_key]['description'] = wp_kses_post($value['description']);
            }

            // Per-indicator observed values
            foreach ($value as $indicator_key => $indicator_data) {
                if ($indicator_key === 'description') continue; // already handled
                if (!is_array($indicator_data)) continue;
                $responses[$domain_key][sanitize_key($indicator_key)] = array(
                    'observed' => !empty($indicator_data['observed']) ? 1 : 0,
                );
            }
        }

        return $responses;
    }
}
