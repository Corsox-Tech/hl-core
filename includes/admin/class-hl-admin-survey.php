<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Survey Editor Tab
 *
 * CRUD interface for hl_survey table, rendered as a tab within the
 * Instruments admin page. Supports list view, add/edit form with
 * multilingual question editor, duplication, and response management.
 *
 * @package HL_Core
 */
class HL_Admin_Survey {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ─── Early Actions ──────────────────────────────────────────────

    /**
     * Handle POST saves and GET actions before output.
     * Called from HL_Admin_Instruments::handle_early_actions() when tab=surveys.
     */
    public function handle_early_actions() {
        if (isset($_POST['hl_save_survey_nonce'])) {
            $this->handle_save();
        }
        if (isset($_POST['hl_duplicate_survey_nonce'])) {
            $this->handle_duplicate();
        }
        if (isset($_POST['hl_delete_responses_nonce'])) {
            $this->handle_delete_responses();
        }
    }

    // ─── Tab Render Dispatch ────────────────────────────────────────

    /**
     * Render the surveys tab content. Dispatches based on survey_action GET param.
     */
    public function render_tab() {
        $action = sanitize_text_field($_GET['action'] ?? '');

        switch ($action) {
            case 'new':
            case 'edit':
                $this->render_form();
                break;
            default:
                $this->render_list();
                break;
        }
    }

    // ─── List View ──────────────────────────────────────────────────

    private function render_list() {
        $repo    = new HL_Survey_Repository();
        $surveys = $repo->get_all();

        // Feedback messages.
        $msgs = array(
            'created'           => array('Survey created.', 'success'),
            'updated'           => array('Survey updated.', 'success'),
            'duplicated'        => array('Survey duplicated as draft.', 'success'),
            'responses_deleted' => array('All responses deleted for this survey.', 'success'),
        );
        $msg_key = sanitize_text_field($_GET['message'] ?? '');
        if (isset($msgs[$msg_key])) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($msgs[$msg_key][1]),
                esc_html($msgs[$msg_key][0])
            );
        }

        // Error messages.
        $errors = get_transient('hl_survey_errors_' . get_current_user_id());
        if ($errors) {
            delete_transient('hl_survey_errors_' . get_current_user_id());
            echo '<div class="notice notice-error"><ul>';
            foreach ($errors as $err) {
                echo '<li>' . esc_html($err) . '</li>';
            }
            echo '</ul></div>';
        }

        // Orphan detection: draft surveys older than 30 days.
        $orphan_count = 0;
        foreach ($surveys as $s) {
            if ($s->status === 'draft' && !empty($s->created_at)) {
                $age_days = (time() - strtotime($s->created_at)) / DAY_IN_SECONDS;
                if ($age_days > 30) {
                    $orphan_count++;
                }
            }
        }
        if ($orphan_count > 0) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html(sprintf('%d draft survey(s) older than 30 days. Consider publishing or deleting them.', $orphan_count))
            );
        }

        $new_url = admin_url('admin.php?page=hl-assessment-hub&section=course-surveys&action=new');

        HL_Admin::render_page_header('Course Surveys', sprintf(
            '<a href="%s" class="page-title-action">Add New Survey</a>',
            esc_url($new_url)
        ));

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>Internal Name</th><th>Display Name</th><th>Type</th><th>Version</th><th>Status</th><th>Responses</th><th>Used By</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        if (empty($surveys)) {
            echo '<tr><td colspan="8">No surveys found.</td></tr>';
        }

        foreach ($surveys as $survey) {
            $survey_id      = $survey->survey_id;
            $has_responses  = $repo->has_responses($survey_id);
            $response_count = $has_responses ? $repo->get_response_count($survey_id) : 0;
            $cycles         = $repo->get_cycles_using_survey($survey_id);

            $edit_url = admin_url('admin.php?page=hl-assessment-hub&section=course-surveys&action=edit&survey_id=' . $survey_id);

            // Status pill.
            $status_class = ($survey->status === 'published') ? 'hl-status-published' : 'hl-status-draft';

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($survey->internal_name) . '</a></strong></td>';
            echo '<td>' . esc_html($survey->display_name) . '</td>';
            echo '<td>' . esc_html($survey->survey_type) . '</td>';
            echo '<td>' . esc_html($survey->version) . '</td>';
            echo '<td><span class="hl-status-pill ' . esc_attr($status_class) . '">' . esc_html(ucfirst($survey->status)) . '</span></td>';
            echo '<td>' . esc_html($response_count) . '</td>';

            // Used By column.
            echo '<td>';
            if (!empty($cycles)) {
                $cycle_names = array_map(function ($c) { return esc_html($c['cycle_name']); }, $cycles);
                echo implode(', ', $cycle_names);
            } else {
                echo '&mdash;';
            }
            echo '</td>';

            // Actions column.
            echo '<td>';
            if ($has_responses) {
                echo '<a href="' . esc_url($edit_url) . '">View (Locked)</a>';
            } else {
                echo '<a href="' . esc_url($edit_url) . '">Edit</a>';
            }

            // Duplicate form (inline POST).
            echo ' | ';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field('hl_duplicate_survey', 'hl_duplicate_survey_nonce');
            echo '<input type="hidden" name="duplicate_survey_id" value="' . esc_attr($survey_id) . '">';
            echo '<button type="submit" class="button-link" onclick="return confirm(\'Duplicate this survey?\')">Duplicate</button>';
            echo '</form>';

            // Reports link (only if has responses).
            if ($has_responses) {
                $report_url = admin_url('admin.php?page=hl-survey-reports&survey_id=' . $survey_id);
                echo ' | <a href="' . esc_url($report_url) . '">Reports</a>';
            }

            // Delete responses (only if has responses).
            if ($has_responses) {
                echo ' | ';
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field('hl_delete_responses', 'hl_delete_responses_nonce');
                echo '<input type="hidden" name="delete_responses_survey_id" value="' . esc_attr($survey_id) . '">';
                echo '<button type="submit" class="button-link button-link-delete" onclick="return confirm(\'Delete ALL ' . esc_js($response_count) . ' response(s) for this survey? This cannot be undone.\')">Delete Responses</button>';
                echo '</form>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // ─── Add/Edit Form ──────────────────────────────────────────────

    private function render_form() {
        $repo      = new HL_Survey_Repository();
        $survey_id = absint($_GET['survey_id'] ?? 0);
        $survey    = $survey_id ? $repo->get_by_id($survey_id) : null;
        $is_edit   = (bool) $survey;

        $has_responses = $is_edit ? $repo->has_responses($survey_id) : false;
        $read_only     = $has_responses;

        // Decode JSON fields for editing.
        $questions    = $is_edit ? $survey->get_questions() : array();
        $scale_labels = $is_edit ? $survey->get_scale_labels() : array();
        $intro_text   = $is_edit ? json_decode($survey->intro_text_json ?: '{}', true) : array();
        $group_labels = $is_edit ? json_decode($survey->group_labels_json ?: '{}', true) : array();

        if (!is_array($intro_text))   $intro_text   = array();
        if (!is_array($group_labels)) $group_labels = array();

        // Retrieve validation errors from transient.
        $errors = get_transient('hl_survey_errors_' . get_current_user_id());
        if ($errors) {
            delete_transient('hl_survey_errors_' . get_current_user_id());
            echo '<div class="notice notice-error"><ul>';
            foreach ($errors as $err) {
                echo '<li>' . esc_html($err) . '</li>';
            }
            echo '</ul></div>';
        }

        HL_Admin::render_page_header($is_edit ? 'Edit Survey' : 'Add New Survey');

        if ($read_only) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>Locked:</strong> This survey has responses and cannot be edited. ';
            echo 'Use <em>Duplicate</em> to create a new version for editing.';
            echo '</p></div>';
        }

        $disabled = $read_only ? ' disabled' : '';

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=hl-assessment-hub&section=course-surveys')); ?>" id="hl-survey-form">
            <?php wp_nonce_field('hl_save_survey', 'hl_save_survey_nonce'); ?>
            <input type="hidden" name="survey_id" value="<?php echo esc_attr($survey_id); ?>">

            <table class="form-table">
                <tr>
                    <th><label for="internal_name">Internal Name</label></th>
                    <td>
                        <input type="text" name="internal_name" id="internal_name"
                               value="<?php echo esc_attr($survey->internal_name ?? ''); ?>"
                               class="regular-text" required<?php echo $disabled; ?>>
                        <p class="description">Used in admin and code references.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="display_name">Display Name</label></th>
                    <td>
                        <input type="text" name="display_name" id="display_name"
                               value="<?php echo esc_attr($survey->display_name ?? ''); ?>"
                               class="regular-text"<?php echo $disabled; ?>>
                        <p class="description">Shown to participants. Falls back to internal name if empty.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="survey_type">Type</label></th>
                    <td>
                        <select name="survey_type" id="survey_type"<?php echo $disabled; ?>>
                            <option value="end_of_course" <?php selected($survey->survey_type ?? 'end_of_course', 'end_of_course'); ?>>End of Course</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="status">Status</label></th>
                    <td>
                        <select name="status" id="status"<?php echo $disabled; ?>>
                            <option value="draft" <?php selected($survey->status ?? 'draft', 'draft'); ?>>Draft</option>
                            <option value="published" <?php selected($survey->status ?? '', 'published'); ?>>Published</option>
                        </select>
                    </td>
                </tr>
            </table>

            <!-- ── Questions Section ────────────────────────────────── -->
            <h2>Questions</h2>
            <p class="description">Define each question with a unique key, type, and multilingual text.</p>

            <div id="hl-survey-questions">
                <?php
                if (!empty($questions)) {
                    foreach ($questions as $idx => $q) {
                        $this->render_question_row($idx, $q, $read_only);
                    }
                } else {
                    // Start with one empty row.
                    $this->render_question_row(0, array(), false);
                }
                ?>
            </div>

            <?php if (!$read_only): ?>
                <p><button type="button" class="button" id="hl-add-question">+ Add Question</button></p>
            <?php endif; ?>

            <!-- ── Scale Labels Section ─────────────────────────────── -->
            <h2>Scale Labels (Likert 5)</h2>
            <p class="description">Define labels for the 1-5 Likert scale in each language.</p>

            <?php
            $likert = isset($scale_labels['likert_5']) && is_array($scale_labels['likert_5']) ? $scale_labels['likert_5'] : array();
            ?>
            <table class="widefat" style="max-width:900px;">
                <thead>
                    <tr><th>Value</th><th>English</th><th>Spanish</th><th>Portuguese</th></tr>
                </thead>
                <tbody>
                    <?php for ($v = 1; $v <= 5; $v++):
                        $en = $likert[(string)$v]['en'] ?? '';
                        $es = $likert[(string)$v]['es'] ?? '';
                        $pt = $likert[(string)$v]['pt'] ?? '';
                    ?>
                    <tr>
                        <td><strong><?php echo $v; ?></strong></td>
                        <td><input type="text" name="scale_labels[<?php echo $v; ?>][en]" value="<?php echo esc_attr($en); ?>" class="regular-text"<?php echo $disabled; ?>></td>
                        <td><input type="text" name="scale_labels[<?php echo $v; ?>][es]" value="<?php echo esc_attr($es); ?>" class="regular-text"<?php echo $disabled; ?>></td>
                        <td><input type="text" name="scale_labels[<?php echo $v; ?>][pt]" value="<?php echo esc_attr($pt); ?>" class="regular-text"<?php echo $disabled; ?>></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <!-- ── Intro Text Section ───────────────────────────────── -->
            <h2>Intro Text</h2>
            <p class="description">Introductory text shown before the survey questions.</p>

            <div class="hl-survey-lang-fields">
                <div>
                    <label><strong>English</strong></label>
                    <textarea name="intro_text[en]" rows="4" class="large-text"<?php echo $disabled; ?>><?php echo esc_textarea($intro_text['en'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label><strong>Spanish</strong></label>
                    <textarea name="intro_text[es]" rows="4" class="large-text"<?php echo $disabled; ?>><?php echo esc_textarea($intro_text['es'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label><strong>Portuguese</strong></label>
                    <textarea name="intro_text[pt]" rows="4" class="large-text"<?php echo $disabled; ?>><?php echo esc_textarea($intro_text['pt'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- ── Group Labels Section ─────────────────────────────── -->
            <h2>Group Labels</h2>
            <p class="description">Instruction text displayed per question group. One block per unique group key used in questions above.</p>

            <div id="hl-survey-group-labels">
                <?php
                // Collect unique groups from questions.
                $group_keys = array();
                foreach ($questions as $q) {
                    if (!empty($q['group'])) {
                        $group_keys[$q['group']] = true;
                    }
                }

                if (!empty($group_keys)) {
                    foreach (array_keys($group_keys) as $gk) {
                        $gl = isset($group_labels[$gk]) && is_array($group_labels[$gk]) ? $group_labels[$gk] : array();
                        ?>
                        <div class="hl-survey-group-label-block" style="margin-bottom:16px; padding:12px; border:1px solid #E5E7EB; border-radius:6px;">
                            <strong><?php echo esc_html($gk); ?></strong>
                            <input type="hidden" name="group_label_keys[]" value="<?php echo esc_attr($gk); ?>">
                            <div class="hl-survey-lang-fields" style="margin-top:8px;">
                                <div>
                                    <label>English</label>
                                    <textarea name="group_label_en[]" rows="2" class="large-text"<?php echo $disabled; ?>><?php echo esc_textarea($gl['en'] ?? ''); ?></textarea>
                                </div>
                                <div>
                                    <label>Spanish</label>
                                    <textarea name="group_label_es[]" rows="2" class="large-text"<?php echo $disabled; ?>><?php echo esc_textarea($gl['es'] ?? ''); ?></textarea>
                                </div>
                                <div>
                                    <label>Portuguese</label>
                                    <textarea name="group_label_pt[]" rows="2" class="large-text"<?php echo $disabled; ?>><?php echo esc_textarea($gl['pt'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                } else {
                    echo '<p class="description" style="color:#9CA3AF;">No groups defined yet. Add a group key to questions above to see group label fields here.</p>';
                }
                ?>
            </div>

            <?php if (!$read_only): ?>
                <?php submit_button('Save Survey'); ?>
            <?php endif; ?>
        </form>

        <p><a href="<?php echo esc_url(admin_url('admin.php?page=hl-assessment-hub&section=course-surveys')); ?>">&larr; Back to Course Surveys</a></p>

        <?php if (!$read_only): ?>
        <script>
        (function() {
            var questionsContainer = document.getElementById('hl-survey-questions');
            var addBtn = document.getElementById('hl-add-question');
            if (!addBtn) return;

            var rowIndex = questionsContainer.querySelectorAll('.hl-survey-question-row').length;

            addBtn.addEventListener('click', function() {
                var row = document.createElement('div');
                row.className = 'hl-survey-question-row';
                var i = rowIndex++;
                row.innerHTML = ''
                    + '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">'
                    + '  <span class="dashicons dashicons-menu" style="cursor:grab;color:#9CA3AF;" title="Drag to reorder"></span>'
                    + '  <strong>Question #' + (i + 1) + '</strong>'
                    + '  <button type="button" class="button-link button-link-delete hl-remove-question" style="margin-left:auto;">Remove</button>'
                    + '</div>'
                    + '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:8px;">'
                    + '  <div><label>Key</label><br><input type="text" name="question_key[]" class="regular-text" placeholder="e.g. q_overall_quality" required></div>'
                    + '  <div><label>Type</label><br>'
                    + '    <select name="question_type[]">'
                    + '      <option value="likert_5">Likert 5</option>'
                    + '      <option value="open_text">Open Text</option>'
                    + '      <option value="yes_no">Yes/No</option>'
                    + '    </select>'
                    + '  </div>'
                    + '  <div><label>Group</label><br><input type="text" name="question_group[]" class="regular-text" placeholder="e.g. agreement_scale"></div>'
                    + '  <div style="display:flex;align-items:center;padding-top:20px;"><label><input type="hidden" name="question_required_key[]" value=""><input type="checkbox" name="question_required_val[_new_' + i + ']" value="1" checked> Required</label></div>'
                    + '</div>'
                    + '<div class="hl-survey-lang-fields">'
                    + '  <div><label>English</label><br><textarea name="question_text_en[]" rows="2" class="large-text" required></textarea></div>'
                    + '  <div><label>Spanish</label><br><textarea name="question_text_es[]" rows="2" class="large-text"></textarea></div>'
                    + '  <div><label>Portuguese</label><br><textarea name="question_text_pt[]" rows="2" class="large-text"></textarea></div>'
                    + '</div>';
                questionsContainer.appendChild(row);
            });

            // Event delegation for remove buttons.
            questionsContainer.addEventListener('click', function(e) {
                if (e.target.classList.contains('hl-remove-question')) {
                    e.target.closest('.hl-survey-question-row').remove();
                }
            });
        })();
        </script>
        <?php endif;
    }

    /**
     * Render a single question row in the editor.
     *
     * @param int   $idx       Row index.
     * @param array $q         Question data.
     * @param bool  $read_only Whether the form is read-only.
     */
    private function render_question_row($idx, $q, $read_only) {
        $disabled = $read_only ? ' disabled' : '';
        $key      = $q['question_key'] ?? '';
        $type     = $q['type'] ?? 'likert_5';
        $group    = $q['group'] ?? '';
        $required = !empty($q['required']);
        $text_en  = $q['text_en'] ?? '';
        $text_es  = $q['text_es'] ?? '';
        $text_pt  = $q['text_pt'] ?? '';
        ?>
        <div class="hl-survey-question-row">
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                <span class="dashicons dashicons-menu" style="cursor:grab;color:#9CA3AF;" title="Drag to reorder"></span>
                <strong>Question #<?php echo ($idx + 1); ?></strong>
                <?php if (!$read_only): ?>
                    <button type="button" class="button-link button-link-delete hl-remove-question" style="margin-left:auto;">Remove</button>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
                <div>
                    <label>Key</label><br>
                    <input type="text" name="question_key[]" value="<?php echo esc_attr($key); ?>"
                           class="regular-text" placeholder="e.g. q_overall_quality" required<?php echo $disabled; ?>>
                </div>
                <div>
                    <label>Type</label><br>
                    <select name="question_type[]"<?php echo $disabled; ?>>
                        <option value="likert_5" <?php selected($type, 'likert_5'); ?>>Likert 5</option>
                        <option value="open_text" <?php selected($type, 'open_text'); ?>>Open Text</option>
                        <option value="yes_no" <?php selected($type, 'yes_no'); ?>>Yes/No</option>
                    </select>
                </div>
                <div>
                    <label>Group</label><br>
                    <input type="text" name="question_group[]" value="<?php echo esc_attr($group); ?>"
                           class="regular-text" placeholder="e.g. agreement_scale"<?php echo $disabled; ?>>
                </div>
                <div style="display:flex;align-items:center;padding-top:20px;">
                    <label>
                        <input type="hidden" name="question_required_key[]" value="<?php echo esc_attr($key); ?>">
                        <input type="checkbox" name="question_required_val[<?php echo esc_attr($key); ?>]" value="1"
                               <?php checked($required); ?><?php echo $disabled; ?>> Required
                    </label>
                </div>
            </div>
            <div class="hl-survey-lang-fields">
                <div>
                    <label>English</label><br>
                    <textarea name="question_text_en[]" rows="2" class="large-text" required<?php echo $disabled; ?>><?php echo esc_textarea($text_en); ?></textarea>
                </div>
                <div>
                    <label>Spanish</label><br>
                    <textarea name="question_text_es[]" rows="2" class="large-text"<?php echo $disabled; ?>><?php echo esc_textarea($text_es); ?></textarea>
                </div>
                <div>
                    <label>Portuguese</label><br>
                    <textarea name="question_text_pt[]" rows="2" class="large-text"<?php echo $disabled; ?>><?php echo esc_textarea($text_pt); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    // ─── POST Save ──────────────────────────────────────────────────

    private function handle_save() {
        if (!wp_verify_nonce($_POST['hl_save_survey_nonce'], 'hl_save_survey')) {
            wp_die('Nonce verification failed.');
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die('Unauthorized.');
        }

        $survey_id     = absint($_POST['survey_id'] ?? 0);
        $internal_name = sanitize_text_field($_POST['internal_name'] ?? '');
        $display_name  = sanitize_text_field($_POST['display_name'] ?? '');
        $survey_type   = sanitize_text_field($_POST['survey_type'] ?? 'end_of_course');
        $status        = in_array($_POST['status'] ?? '', array('draft', 'published'), true)
                            ? $_POST['status'] : 'draft';

        // Validate type — DB ENUM only has 'end_of_course'.
        if (!in_array($survey_type, array('end_of_course'), true)) {
            $survey_type = 'end_of_course';
        }

        // Build questions JSON from POST arrays.
        $questions_json = $this->build_questions_from_post();

        // Build scale_labels_json.
        $scale_labels = array('likert_5' => array());
        if (!empty($_POST['scale_labels']) && is_array($_POST['scale_labels'])) {
            foreach ($_POST['scale_labels'] as $val => $langs) {
                $scale_labels['likert_5'][(string)$val] = array(
                    'en' => sanitize_text_field($langs['en'] ?? ''),
                    'es' => sanitize_text_field($langs['es'] ?? ''),
                    'pt' => sanitize_text_field($langs['pt'] ?? ''),
                );
            }
        }

        // Build intro_text_json.
        $intro_text = array(
            'en' => sanitize_textarea_field($_POST['intro_text']['en'] ?? ''),
            'es' => sanitize_textarea_field($_POST['intro_text']['es'] ?? ''),
            'pt' => sanitize_textarea_field($_POST['intro_text']['pt'] ?? ''),
        );

        // Build group_labels_json.
        $group_labels = array();
        if (!empty($_POST['group_label_keys']) && is_array($_POST['group_label_keys'])) {
            $gl_keys = $_POST['group_label_keys'];
            $gl_en   = $_POST['group_label_en'] ?? array();
            $gl_es   = $_POST['group_label_es'] ?? array();
            $gl_pt   = $_POST['group_label_pt'] ?? array();
            foreach ($gl_keys as $i => $gk) {
                $gk = sanitize_text_field($gk);
                if (empty($gk)) continue;
                $group_labels[$gk] = array(
                    'en' => sanitize_textarea_field($gl_en[$i] ?? ''),
                    'es' => sanitize_textarea_field($gl_es[$i] ?? ''),
                    'pt' => sanitize_textarea_field($gl_pt[$i] ?? ''),
                );
            }
        }

        // Validation.
        $errors = array();
        if (empty($internal_name)) {
            $errors[] = 'Internal name is required.';
        }

        $questions = json_decode($questions_json, true);
        if (empty($questions)) {
            $errors[] = 'At least one question is required.';
        } else {
            foreach ($questions as $idx => $q) {
                if (empty($q['text_en'])) {
                    $errors[] = sprintf('Question #%d is missing English text.', $idx + 1);
                }
                if (empty($q['question_key'])) {
                    $errors[] = sprintf('Question #%d is missing a key.', $idx + 1);
                }
            }
        }

        // Check locked: cannot save if has responses.
        if ($survey_id) {
            $repo = new HL_Survey_Repository();
            if ($repo->has_responses($survey_id)) {
                $errors[] = 'This survey has responses and cannot be edited.';
            }
        }

        if (!empty($errors)) {
            set_transient('hl_survey_errors_' . get_current_user_id(), $errors, 30);
            $redirect = admin_url('admin.php?page=hl-assessment-hub&section=course-surveys&action='
                . ($survey_id ? 'edit&survey_id=' . $survey_id : 'new') . '&error=1');
            wp_redirect($redirect);
            exit;
        }

        $repo = new HL_Survey_Repository();

        $data = array(
            'internal_name'     => $internal_name,
            'display_name'      => $display_name ?: $internal_name,
            'survey_type'       => $survey_type,
            'questions_json'    => $questions_json,
            'scale_labels_json' => wp_json_encode($scale_labels),
            'intro_text_json'   => wp_json_encode($intro_text),
            'group_labels_json' => wp_json_encode($group_labels),
            'status'            => $status,
        );

        if ($survey_id) {
            // Update.
            $result = $repo->update($survey_id, $data);
            if (is_wp_error($result)) {
                set_transient('hl_survey_errors_' . get_current_user_id(), array($result->get_error_message()), 30);
                wp_redirect(admin_url('admin.php?page=hl-assessment-hub&section=course-surveys&action=edit&survey_id=' . $survey_id . '&error=1'));
                exit;
            }
            if (class_exists('HL_Audit_Service')) {
                HL_Audit_Service::log('survey.updated', array(
                    'entity_type' => 'survey',
                    'entity_id'   => $survey_id,
                    'after_data'  => array(
                        'internal_name'  => $internal_name,
                        'survey_type'    => $survey_type,
                        'status'         => $status,
                        'question_count' => is_array($questions) ? count($questions) : 0,
                    ),
                ));
            }
            wp_redirect(admin_url('admin.php?page=hl-assessment-hub&section=course-surveys&message=updated'));
            exit;
        } else {
            // Create — auto-version.
            $data['version'] = $repo->get_next_version($survey_type);
            $result = $repo->create($data);
            if (is_wp_error($result)) {
                set_transient('hl_survey_errors_' . get_current_user_id(), array($result->get_error_message()), 30);
                wp_redirect(admin_url('admin.php?page=hl-assessment-hub&section=course-surveys&action=new&error=1'));
                exit;
            }
            if (class_exists('HL_Audit_Service')) {
                HL_Audit_Service::log('survey.created', array(
                    'entity_type' => 'survey',
                    'entity_id'   => $result,
                    'after_data'  => array(
                        'internal_name'  => $internal_name,
                        'survey_type'    => $survey_type,
                        'status'         => $status,
                        'question_count' => is_array($questions) ? count($questions) : 0,
                    ),
                ));
            }
            wp_redirect(admin_url('admin.php?page=hl-assessment-hub&section=course-surveys&message=created'));
            exit;
        }
    }

    /**
     * Build questions JSON string from POST arrays.
     *
     * @return string JSON-encoded questions array.
     */
    private function build_questions_from_post() {
        $keys     = isset($_POST['question_key']) && is_array($_POST['question_key']) ? $_POST['question_key'] : array();
        $types    = isset($_POST['question_type']) && is_array($_POST['question_type']) ? $_POST['question_type'] : array();
        $groups   = isset($_POST['question_group']) && is_array($_POST['question_group']) ? $_POST['question_group'] : array();
        $texts_en = isset($_POST['question_text_en']) && is_array($_POST['question_text_en']) ? $_POST['question_text_en'] : array();
        $texts_es = isset($_POST['question_text_es']) && is_array($_POST['question_text_es']) ? $_POST['question_text_es'] : array();
        $texts_pt = isset($_POST['question_text_pt']) && is_array($_POST['question_text_pt']) ? $_POST['question_text_pt'] : array();
        $required_val = isset($_POST['question_required_val']) && is_array($_POST['question_required_val']) ? $_POST['question_required_val'] : array();

        $questions = array();
        foreach ($keys as $i => $key) {
            $key = sanitize_text_field($key);
            if (empty($key)) continue; // Skip blank rows.

            $valid_types = array('likert_5', 'open_text', 'yes_no');
            $type = isset($types[$i]) && in_array($types[$i], $valid_types, true) ? $types[$i] : 'likert_5';

            $questions[] = array(
                'question_key' => $key,
                'type'         => $type,
                'group'        => sanitize_text_field($groups[$i] ?? ''),
                'required'     => !empty($required_val[$key]),
                'text_en'      => sanitize_textarea_field($texts_en[$i] ?? ''),
                'text_es'      => sanitize_textarea_field($texts_es[$i] ?? ''),
                'text_pt'      => sanitize_textarea_field($texts_pt[$i] ?? ''),
            );
        }

        return wp_json_encode($questions);
    }

    // ─── Duplicate ──────────────────────────────────────────────────

    private function handle_duplicate() {
        if (!wp_verify_nonce($_POST['hl_duplicate_survey_nonce'], 'hl_duplicate_survey')) {
            wp_die('Nonce verification failed.');
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die('Unauthorized.');
        }

        $survey_id = absint($_POST['duplicate_survey_id'] ?? 0);
        if (!$survey_id) {
            wp_die('Missing survey ID.');
        }

        $service = new HL_Survey_Service();
        $result  = $service->duplicate_survey($survey_id);

        if (is_wp_error($result)) {
            set_transient('hl_survey_errors_' . get_current_user_id(), array($result->get_error_message()), 30);
            wp_redirect(admin_url('admin.php?page=hl-assessment-hub&section=course-surveys&error=1'));
            exit;
        }

        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log('survey.duplicated', array(
                'entity_type' => 'survey',
                'entity_id'   => $result,
                'after_data'  => array(
                    'source_survey_id' => $survey_id,
                    'new_survey_id'    => $result,
                ),
            ));
        }

        wp_redirect(admin_url('admin.php?page=hl-assessment-hub&section=course-surveys&message=duplicated'));
        exit;
    }

    // ─── Delete Responses ───────────────────────────────────────────

    private function handle_delete_responses() {
        if (!wp_verify_nonce($_POST['hl_delete_responses_nonce'], 'hl_delete_responses')) {
            wp_die('Nonce verification failed.');
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die('Unauthorized.');
        }

        $survey_id = absint($_POST['delete_responses_survey_id'] ?? 0);
        if (!$survey_id) {
            wp_die('Missing survey ID.');
        }

        global $wpdb;

        // 1. Get pending rows for this survey (to complete their components first).
        $pending_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_pending_survey WHERE survey_id = %d",
            $survey_id
        ), ARRAY_A) ?: array();

        // 2. Complete any affected components.
        foreach ($pending_rows as $pending) {
            $wpdb->update(
                $wpdb->prefix . 'hl_component_state',
                array(
                    'completion_status'  => 'complete',
                    'completion_percent' => 100,
                    'completed_at'       => current_time('mysql'),
                    'last_computed_at'   => current_time('mysql'),
                ),
                array(
                    'enrollment_id' => $pending['enrollment_id'],
                    'component_id'  => $pending['component_id'],
                )
            );
            do_action('hl_core_recompute_rollups', $pending['enrollment_id']);
        }

        // 3. Delete pending rows.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}hl_pending_survey WHERE survey_id = %d",
            $survey_id
        ));

        // 4. Delete all responses.
        $response_repo = new HL_Survey_Response_Repository();
        $deleted_count = $response_repo->delete_all_responses_for_survey($survey_id);

        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log('survey.responses_deleted', array(
                'entity_type' => 'survey',
                'entity_id'   => $survey_id,
                'after_data'  => array(
                    'responses_deleted' => $deleted_count,
                    'pending_resolved'  => count($pending_rows),
                ),
            ));
        }

        wp_redirect(admin_url('admin.php?page=hl-assessment-hub&section=course-surveys&message=responses_deleted'));
        exit;
    }
}
