<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Survey Reports
 *
 * Reporting dashboard for course survey responses. Provides summary statistics
 * (Likert distribution, per-question means, per-course comparison), open-text
 * browsing, CSV export, and bulk-delete of individual responses.
 *
 * Registered as a hidden submenu page (hl-survey-reports).
 *
 * @package HL_Core
 */
class HL_Admin_Survey_Reports {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ─── Early Actions (before output) ─────────────────────────────

    /**
     * Handle CSV export and bulk-delete before any HTML output.
     */
    public function handle_early_actions() {
        if (!current_user_can('manage_hl_core')) {
            return;
        }
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->handle_csv_export();
        }
        if (isset($_POST['hl_delete_survey_responses_nonce'])) {
            $this->handle_delete_responses();
        }
    }

    // ─── Page Render ───────────────────────────────────────────────

    /**
     * Main render entry point.
     */
    public function render_page() {
        if (!current_user_can('manage_hl_core')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'hl-core'));
        }

        $filters   = $this->get_filters();
        $survey_id = $filters['survey_id'];

        echo '<div class="wrap hl-admin-wrap">';

        HL_Admin::render_page_header('Survey Reports', sprintf(
            '<a href="%s" class="page-title-action">&larr; Back to Surveys</a>',
            esc_url(admin_url('admin.php?page=hl-instruments&tab=surveys'))
        ));

        $this->render_messages();
        $this->render_filter_bar($filters);

        if (!$survey_id) {
            echo '<div class="hl-empty-state">';
            echo '<p>' . esc_html__('Please select a survey to view reports.', 'hl-core') . '</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        $view = sanitize_text_field($_GET['report_view'] ?? 'summary');

        if ($view === 'open_text') {
            $question_key = sanitize_text_field($_GET['question_key'] ?? '');
            $this->render_open_text($survey_id, $filters, $question_key);
        } else {
            $this->render_summary($survey_id, $filters);
        }

        echo '</div>';
    }

    // ─── Filters ───────────────────────────────────────────────────

    /**
     * Extract and sanitize filter parameters from $_GET.
     *
     * @return array
     */
    private function get_filters() {
        $cycle_ids = array();
        if (!empty($_GET['cycle_ids']) && is_array($_GET['cycle_ids'])) {
            $cycle_ids = array_map('absint', $_GET['cycle_ids']);
            $cycle_ids = array_filter($cycle_ids);
        }

        return array(
            'survey_id' => isset($_GET['survey_id']) ? absint($_GET['survey_id']) : 0,
            'cycle_ids' => $cycle_ids,
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to'   => isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '',
        );
    }

    /**
     * Build a URL for the report page with given parameters merged.
     *
     * @param array $args Additional query args.
     * @return string
     */
    private function page_url($args = array()) {
        $base = admin_url('admin.php?page=hl-survey-reports');
        return add_query_arg($args, $base);
    }

    // ─── Filter Bar ────────────────────────────────────────────────

    private function render_filter_bar($filters) {
        $survey_repo = new HL_Survey_Repository();
        $surveys     = $survey_repo->get_all();

        // Get cycles for multi-select.
        $cycle_repo = new HL_Cycle_Repository();
        $cycles     = $cycle_repo->get_all();

        echo '<div class="hl-filters-bar" style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:15px 20px;margin-bottom:20px;">';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">';
        echo '<input type="hidden" name="page" value="hl-survey-reports" />';

        // Survey dropdown.
        echo '<div style="flex:1;min-width:200px;">';
        echo '<label for="survey_id" style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#1e1e1e;">';
        echo esc_html__('Survey', 'hl-core') . ' <span style="color:#d63638;">*</span></label>';
        echo '<select name="survey_id" id="survey_id" style="width:100%;">';
        echo '<option value="">' . esc_html__('-- Select Survey --', 'hl-core') . '</option>';
        foreach ($surveys as $s) {
            $label = $s->internal_name;
            if ($s->display_name && $s->display_name !== $s->internal_name) {
                $label .= ' (' . $s->display_name . ')';
            }
            $label .= ' v' . $s->version . ' [' . ucfirst($s->status) . ']';
            echo '<option value="' . esc_attr($s->survey_id) . '"' . selected($filters['survey_id'], $s->survey_id, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Cycle multi-select.
        echo '<div style="flex:1;min-width:200px;">';
        echo '<label for="cycle_ids" style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#1e1e1e;">';
        echo esc_html__('Cycles', 'hl-core') . '</label>';
        echo '<select name="cycle_ids[]" id="cycle_ids" multiple style="width:100%;min-height:60px;">';
        foreach ($cycles as $c) {
            $sel = in_array($c->cycle_id, $filters['cycle_ids'], true) ? ' selected' : '';
            echo '<option value="' . esc_attr($c->cycle_id) . '"' . $sel . '>';
            echo esc_html($c->cycle_name);
            if ($c->cycle_code) {
                echo ' (' . esc_html($c->cycle_code) . ')';
            }
            echo '</option>';
        }
        echo '</select>';
        echo '<p class="description" style="margin-top:2px;">' . esc_html__('Hold Ctrl/Cmd to select multiple.', 'hl-core') . '</p>';
        echo '</div>';

        // Date range.
        echo '<div style="min-width:140px;">';
        echo '<label for="date_from" style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#1e1e1e;">';
        echo esc_html__('From', 'hl-core') . '</label>';
        echo '<input type="date" name="date_from" id="date_from" value="' . esc_attr($filters['date_from']) . '" style="width:100%;">';
        echo '</div>';

        echo '<div style="min-width:140px;">';
        echo '<label for="date_to" style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#1e1e1e;">';
        echo esc_html__('To', 'hl-core') . '</label>';
        echo '<input type="date" name="date_to" id="date_to" value="' . esc_attr($filters['date_to']) . '" style="width:100%;">';
        echo '</div>';

        // Buttons.
        echo '<div style="display:flex;gap:6px;align-items:flex-end;">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Filter', 'hl-core') . '</button>';
        echo '</div>';
        echo '</form>';

        // Export CSV button (below filters, only when survey is selected).
        if ($filters['survey_id']) {
            echo '<div style="display:flex;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid #eee;">';
            $export_args = array('survey_id' => $filters['survey_id'], 'export' => 'csv');
            if (!empty($filters['cycle_ids'])) {
                $export_args['cycle_ids'] = $filters['cycle_ids'];
            }
            if ($filters['date_from']) {
                $export_args['date_from'] = $filters['date_from'];
            }
            if ($filters['date_to']) {
                $export_args['date_to'] = $filters['date_to'];
            }
            $export_url = $this->page_url($export_args);
            echo '<a href="' . esc_url($export_url) . '" class="button">';
            echo '<span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:4px;"></span>';
            echo esc_html__('Export CSV', 'hl-core');
            echo '</a>';
            echo '</div>';
        }

        echo '</div>';
    }

    // ─── Messages ──────────────────────────────────────────────────

    private function render_messages() {
        $msg = sanitize_text_field($_GET['message'] ?? '');
        $msgs = array(
            'responses_deleted' => array('Selected responses deleted.', 'success'),
        );
        if (isset($msgs[$msg])) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($msgs[$msg][1]),
                esc_html($msgs[$msg][0])
            );
        }
    }

    // ─── Summary View ──────────────────────────────────────────────

    /**
     * Render the summary report: cards, Likert distribution, per-course comparison.
     *
     * @param int   $survey_id
     * @param array $filters
     */
    private function render_summary($survey_id, $filters) {
        $survey_repo   = new HL_Survey_Repository();
        $response_repo = new HL_Survey_Response_Repository();

        $survey    = $survey_repo->get_by_id($survey_id);
        $responses = $response_repo->get_responses_for_report($survey_id, $filters);

        if (!$survey) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Survey not found.', 'hl-core') . '</p></div>';
            return;
        }

        $questions = $survey->get_questions();

        if (empty($responses)) {
            echo '<div class="hl-empty-state">';
            echo '<p>' . esc_html__('No responses found for the selected filters.', 'hl-core') . '</p>';
            echo '</div>';
            return;
        }

        // ── Accumulate statistics in a single pass ──────────────
        $likert_questions = array();
        $open_questions   = array();
        foreach ($questions as $q) {
            if ($q['type'] === 'likert_5') {
                $likert_questions[$q['question_key']] = $q;
            } elseif ($q['type'] === 'open_text') {
                $open_questions[$q['question_key']] = $q;
            }
        }

        // Per-question Likert distribution: counts[key][value] = int.
        $likert_counts  = array();
        $likert_sums    = array();
        $likert_totals  = array();

        // Per-course accumulation: courses[catalog_id][key] = {sum, count}.
        $course_data = array();

        foreach ($likert_questions as $key => $q) {
            $likert_counts[$key] = array_fill(1, 5, 0);
            $likert_sums[$key]   = 0;
            $likert_totals[$key] = 0;
        }

        $open_counts = array();
        foreach ($open_questions as $key => $q) {
            $open_counts[$key] = 0;
        }

        foreach ($responses as $resp) {
            $answers = json_decode($resp['responses_json'] ?? '{}', true);
            if (!is_array($answers)) {
                continue;
            }

            $catalog_id = isset($resp['catalog_id']) ? (int) $resp['catalog_id'] : 0;

            // Initialize course bucket if needed.
            if ($catalog_id && !isset($course_data[$catalog_id])) {
                $course_data[$catalog_id] = array(
                    'catalog_code'  => $resp['catalog_code'] ?? '',
                    'course_title'  => $resp['course_title'] ?? '',
                    'response_count'=> 0,
                    'likert_sums'   => array(),
                    'likert_counts' => array(),
                );
                foreach ($likert_questions as $key => $q) {
                    $course_data[$catalog_id]['likert_sums'][$key]   = 0;
                    $course_data[$catalog_id]['likert_counts'][$key] = 0;
                }
            }
            if ($catalog_id) {
                $course_data[$catalog_id]['response_count']++;
            }

            foreach ($likert_questions as $key => $q) {
                if (isset($answers[$key]) && is_numeric($answers[$key])) {
                    $val = (int) $answers[$key];
                    if ($val >= 1 && $val <= 5) {
                        $likert_counts[$key][$val]++;
                        $likert_sums[$key]   += $val;
                        $likert_totals[$key]++;

                        if ($catalog_id) {
                            $course_data[$catalog_id]['likert_sums'][$key]   += $val;
                            $course_data[$catalog_id]['likert_counts'][$key]++;
                        }
                    }
                }
            }

            foreach ($open_questions as $key => $q) {
                if (!empty($answers[$key]) && is_string($answers[$key]) && trim($answers[$key]) !== '') {
                    $open_counts[$key]++;
                }
            }
        }

        // ── Summary Cards ───────────────────────────────────────
        $total_responses = count($responses);

        // Average Overall Agreement = mean of all per-question means.
        $question_means = array();
        foreach ($likert_questions as $key => $q) {
            if ($likert_totals[$key] > 0) {
                $question_means[] = $likert_sums[$key] / $likert_totals[$key];
            }
        }
        $avg_agreement = !empty($question_means) ? array_sum($question_means) / count($question_means) : 0;

        echo '<div class="hl-metrics-row">';

        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html(number_format_i18n($total_responses)) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Total Responses', 'hl-core') . '</div>';
        echo '</div>';

        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html(number_format($avg_agreement, 2)) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Avg Overall Agreement', 'hl-core') . '</div>';
        echo '<div style="font-size:11px;color:#666;margin-top:4px;">' . esc_html__('Mean of all Likert means (1-5)', 'hl-core') . '</div>';
        echo '</div>';

        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html(count($likert_questions)) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Likert Questions', 'hl-core') . '</div>';
        echo '</div>';

        echo '<div class="hl-metric-card">';
        echo '<div class="metric-value">' . esc_html(count($open_questions)) . '</div>';
        echo '<div class="metric-label">' . esc_html__('Open Text Questions', 'hl-core') . '</div>';
        echo '</div>';

        echo '</div>';

        // ── Per-Question Likert Distribution Table ──────────────
        if (!empty($likert_questions)) {
            echo '<h2>' . esc_html__('Likert Question Distribution', 'hl-core') . '</h2>';
            echo '<table class="widefat striped" style="margin-bottom:30px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Question', 'hl-core') . '</th>';
            echo '<th style="text-align:center;width:60px;">1</th>';
            echo '<th style="text-align:center;width:60px;">2</th>';
            echo '<th style="text-align:center;width:60px;">3</th>';
            echo '<th style="text-align:center;width:60px;">4</th>';
            echo '<th style="text-align:center;width:60px;">5</th>';
            echo '<th style="text-align:center;width:60px;">' . esc_html__('n', 'hl-core') . '</th>';
            echo '<th style="text-align:center;width:70px;">' . esc_html__('Mean', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($likert_questions as $key => $q) {
                $n    = $likert_totals[$key];
                $mean = $n > 0 ? $likert_sums[$key] / $n : 0;

                echo '<tr>';
                echo '<td><strong>' . esc_html($q['text_en'] ?? $key) . '</strong>';
                echo '<br><code style="font-size:11px;color:#888;">' . esc_html($key) . '</code></td>';

                for ($v = 1; $v <= 5; $v++) {
                    $count = $likert_counts[$key][$v];
                    $pct   = $n > 0 ? round(($count / $n) * 100, 1) : 0;
                    echo '<td style="text-align:center;">';
                    echo esc_html($count);
                    echo '<br><span style="font-size:11px;color:#888;">' . esc_html($pct) . '%</span>';
                    echo '</td>';
                }

                echo '<td style="text-align:center;">' . esc_html($n) . '</td>';
                echo '<td style="text-align:center;font-weight:600;">' . esc_html(number_format($mean, 2)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        // ── Per-Course Comparison Table ─────────────────────────
        if (!empty($course_data)) {
            // Compute per-course mean of all Likert items and find the lowest-scoring question.
            $course_rows = array();
            foreach ($course_data as $cat_id => $cd) {
                $all_means       = array();
                $lowest_key      = '';
                $lowest_mean     = PHP_INT_MAX;

                foreach ($likert_questions as $key => $q) {
                    if ($cd['likert_counts'][$key] > 0) {
                        $qm = $cd['likert_sums'][$key] / $cd['likert_counts'][$key];
                        $all_means[] = $qm;
                        if ($qm < $lowest_mean) {
                            $lowest_mean = $qm;
                            $lowest_key  = $key;
                        }
                    }
                }

                $overall_mean = !empty($all_means) ? array_sum($all_means) / count($all_means) : 0;
                $lowest_text  = '';
                if ($lowest_key && isset($likert_questions[$lowest_key])) {
                    $lowest_text = $likert_questions[$lowest_key]['text_en'] ?? $lowest_key;
                }

                $course_rows[] = array(
                    'catalog_code'   => $cd['catalog_code'],
                    'course_title'   => $cd['course_title'],
                    'response_count' => $cd['response_count'],
                    'overall_mean'   => $overall_mean,
                    'lowest_question'=> $lowest_text,
                    'lowest_mean'    => ($lowest_mean < PHP_INT_MAX) ? $lowest_mean : 0,
                );
            }

            // Sort by mean ascending (weakest first).
            usort($course_rows, function ($a, $b) {
                return $a['overall_mean'] <=> $b['overall_mean'];
            });

            echo '<h2>' . esc_html__('Per-Course Comparison', 'hl-core') . '</h2>';
            echo '<p class="description">' . esc_html__('Sorted by mean ascending (weakest courses first).', 'hl-core') . '</p>';
            echo '<table class="widefat striped" style="margin-bottom:30px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Code', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Course Title', 'hl-core') . '</th>';
            echo '<th style="text-align:center;">' . esc_html__('Responses', 'hl-core') . '</th>';
            echo '<th style="text-align:center;">' . esc_html__('Mean', 'hl-core') . '</th>';
            echo '<th>' . esc_html__('Lowest Question', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($course_rows as $row) {
                $mean_color = $row['overall_mean'] >= 4.0 ? '#00a32a' : ($row['overall_mean'] >= 3.0 ? '#dba617' : '#d63638');
                echo '<tr>';
                echo '<td><code>' . esc_html($row['catalog_code']) . '</code></td>';
                echo '<td>' . esc_html($row['course_title']) . '</td>';
                echo '<td style="text-align:center;">' . esc_html($row['response_count']) . '</td>';
                echo '<td style="text-align:center;font-weight:600;color:' . esc_attr($mean_color) . ';">';
                echo esc_html(number_format($row['overall_mean'], 2));
                echo '</td>';
                echo '<td>';
                if ($row['lowest_question']) {
                    echo esc_html($row['lowest_question']);
                    echo ' <span style="color:#888;">(' . esc_html(number_format($row['lowest_mean'], 2)) . ')</span>';
                } else {
                    echo '&mdash;';
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        // ── Open Text Summary ───────────────────────────────────
        if (!empty($open_questions)) {
            echo '<h2>' . esc_html__('Open Text Responses', 'hl-core') . '</h2>';
            echo '<table class="widefat striped" style="margin-bottom:30px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Question', 'hl-core') . '</th>';
            echo '<th style="text-align:center;width:100px;">' . esc_html__('Responses', 'hl-core') . '</th>';
            echo '<th style="width:120px;">' . esc_html__('Actions', 'hl-core') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($open_questions as $key => $q) {
                $view_url_args = array(
                    'survey_id'    => $survey_id,
                    'report_view'  => 'open_text',
                    'question_key' => $key,
                );
                if (!empty($filters['cycle_ids'])) {
                    $view_url_args['cycle_ids'] = $filters['cycle_ids'];
                }
                if ($filters['date_from']) {
                    $view_url_args['date_from'] = $filters['date_from'];
                }
                if ($filters['date_to']) {
                    $view_url_args['date_to'] = $filters['date_to'];
                }
                $view_url = $this->page_url($view_url_args);

                echo '<tr>';
                echo '<td><strong>' . esc_html($q['text_en'] ?? $key) . '</strong>';
                echo '<br><code style="font-size:11px;color:#888;">' . esc_html($key) . '</code></td>';
                echo '<td style="text-align:center;">' . esc_html($open_counts[$key]) . '</td>';
                echo '<td><a href="' . esc_url($view_url) . '" class="button button-small">';
                echo esc_html__('View All', 'hl-core') . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }

    // ─── Open Text View ────────────────────────────────────────────

    /**
     * Render paginated open-text responses for a specific question.
     *
     * @param int    $survey_id
     * @param array  $filters
     * @param string $question_key
     */
    private function render_open_text($survey_id, $filters, $question_key) {
        $survey_repo   = new HL_Survey_Repository();
        $survey        = $survey_repo->get_by_id($survey_id);

        if (!$survey) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Survey not found.', 'hl-core') . '</p></div>';
            return;
        }

        $questions      = $survey->get_questions();
        $open_questions = array();
        foreach ($questions as $q) {
            if ($q['type'] === 'open_text') {
                $open_questions[$q['question_key']] = $q;
            }
        }

        // Default to first open-text question if no key or invalid key.
        if (empty($question_key) || !isset($open_questions[$question_key])) {
            $question_key = !empty($open_questions) ? array_key_first($open_questions) : '';
        }

        if (empty($question_key)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('This survey has no open-text questions.', 'hl-core') . '</p></div>';
            return;
        }

        // Back to summary link.
        $summary_args = array('survey_id' => $survey_id);
        if (!empty($filters['cycle_ids'])) {
            $summary_args['cycle_ids'] = $filters['cycle_ids'];
        }
        if ($filters['date_from']) {
            $summary_args['date_from'] = $filters['date_from'];
        }
        if ($filters['date_to']) {
            $summary_args['date_to'] = $filters['date_to'];
        }
        echo '<p><a href="' . esc_url($this->page_url($summary_args)) . '">&larr; ' . esc_html__('Back to Summary', 'hl-core') . '</a></p>';

        // Question dropdown.
        if (count($open_questions) > 1) {
            echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin-bottom:16px;">';
            echo '<input type="hidden" name="page" value="hl-survey-reports" />';
            echo '<input type="hidden" name="survey_id" value="' . esc_attr($survey_id) . '" />';
            echo '<input type="hidden" name="report_view" value="open_text" />';
            if (!empty($filters['cycle_ids'])) {
                foreach ($filters['cycle_ids'] as $cid) {
                    echo '<input type="hidden" name="cycle_ids[]" value="' . esc_attr($cid) . '" />';
                }
            }
            if ($filters['date_from']) {
                echo '<input type="hidden" name="date_from" value="' . esc_attr($filters['date_from']) . '" />';
            }
            if ($filters['date_to']) {
                echo '<input type="hidden" name="date_to" value="' . esc_attr($filters['date_to']) . '" />';
            }
            echo '<label for="question_key" style="font-weight:600;margin-right:8px;">' . esc_html__('Question:', 'hl-core') . '</label>';
            echo '<select name="question_key" id="question_key" onchange="this.form.submit();">';
            foreach ($open_questions as $qk => $q) {
                echo '<option value="' . esc_attr($qk) . '"' . selected($question_key, $qk, false) . '>';
                echo esc_html($q['text_en'] ?? $qk);
                echo '</option>';
            }
            echo '</select>';
            echo '</form>';
        }

        $current_q   = $open_questions[$question_key];
        $english_text = $current_q['text_en'] ?? $question_key;

        echo '<h2>' . esc_html($english_text) . '</h2>';
        echo '<p class="description"><code>' . esc_html($question_key) . '</code></p>';

        // Fetch responses with school info.
        $rows = $this->get_open_text_rows($survey_id, $filters, $question_key);

        // Pagination.
        $per_page    = 25;
        $total_rows  = count($rows);
        $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $total_pages  = max(1, (int) ceil($total_rows / $per_page));
        $offset       = ($current_page - 1) * $per_page;
        $page_rows    = array_slice($rows, $offset, $per_page);

        if (empty($page_rows)) {
            echo '<div class="hl-empty-state">';
            echo '<p>' . esc_html__('No open-text responses found for this question.', 'hl-core') . '</p>';
            echo '</div>';
            return;
        }

        // Delete form wrapper.
        echo '<form method="post">';
        wp_nonce_field('hl_delete_survey_responses', 'hl_delete_survey_responses_nonce');
        echo '<input type="hidden" name="redirect_survey_id" value="' . esc_attr($survey_id) . '" />';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:30px;"><input type="checkbox" id="hl-select-all-responses"></th>';
        echo '<th>' . esc_html__('Participant', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('School', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Course', 'hl-core') . '</th>';
        echo '<th style="min-width:300px;">' . esc_html__('Response', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Date', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($page_rows as $row) {
            echo '<tr>';
            echo '<td><input type="checkbox" name="response_ids[]" value="' . esc_attr($row['response_id']) . '"></td>';
            echo '<td>' . esc_html($row['participant_name'] ?? '') . '</td>';
            echo '<td>' . esc_html($row['school_name'] ?? '---') . '</td>';
            echo '<td>' . esc_html($row['course_title'] ?? '') . '</td>';
            echo '<td>' . esc_html($row['response_text']) . '</td>';
            echo '<td>' . esc_html($row['submitted_at'] ?? '') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Bulk delete button.
        echo '<div style="margin-top:12px;display:flex;gap:10px;align-items:center;">';
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete selected responses? This cannot be undone.', 'hl-core')) . '\');">';
        echo esc_html__('Delete Selected', 'hl-core');
        echo '</button>';
        echo '<span class="description">' . esc_html(sprintf(
            __('Showing %d-%d of %d responses', 'hl-core'),
            $offset + 1,
            min($offset + $per_page, $total_rows),
            $total_rows
        )) . '</span>';
        echo '</div>';
        echo '</form>';

        // Pagination links.
        if ($total_pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            $pagination_base = $this->page_url(array_merge(
                array(
                    'survey_id'    => $survey_id,
                    'report_view'  => 'open_text',
                    'question_key' => $question_key,
                ),
                !empty($filters['cycle_ids']) ? array('cycle_ids' => $filters['cycle_ids']) : array(),
                $filters['date_from'] ? array('date_from' => $filters['date_from']) : array(),
                $filters['date_to']   ? array('date_to' => $filters['date_to'])     : array()
            ));

            echo paginate_links(array(
                'base'    => add_query_arg('paged', '%#%', $pagination_base),
                'format'  => '',
                'current' => $current_page,
                'total'   => $total_pages,
                'type'    => 'plain',
            ));
            echo '</div></div>';
        }

        // Select-all JS.
        ?>
        <script>
        (function() {
            var selectAll = document.getElementById('hl-select-all-responses');
            if (!selectAll) return;
            selectAll.addEventListener('change', function() {
                var boxes = document.querySelectorAll('input[name="response_ids[]"]');
                for (var i = 0; i < boxes.length; i++) {
                    boxes[i].checked = selectAll.checked;
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Fetch open-text response rows with school name.
     *
     * @param int    $survey_id
     * @param array  $filters
     * @param string $question_key
     * @return array
     */
    private function get_open_text_rows($survey_id, $filters, $question_key) {
        $response_repo = new HL_Survey_Response_Repository();
        $responses     = $response_repo->get_responses_for_report($survey_id, $filters);

        if (empty($responses)) {
            return array();
        }

        // Batch-load school names from enrollments.
        global $wpdb;
        $enrollment_ids = array_unique(array_column($responses, 'enrollment_id'));
        $school_map     = array();
        if (!empty($enrollment_ids)) {
            $placeholders = implode(',', array_fill(0, count($enrollment_ids), '%d'));
            $school_rows  = $wpdb->get_results($wpdb->prepare(
                "SELECT e.enrollment_id, o.name AS school_name
                 FROM {$wpdb->prefix}hl_enrollment e
                 LEFT JOIN {$wpdb->prefix}hl_orgunit o ON o.orgunit_id = e.school_id
                 WHERE e.enrollment_id IN ({$placeholders})",
                ...$enrollment_ids
            ), ARRAY_A) ?: array();
            foreach ($school_rows as $sr) {
                $school_map[(int) $sr['enrollment_id']] = $sr['school_name'] ?? '';
            }
        }

        // Filter to rows that have a non-empty answer for this question key.
        $rows = array();
        foreach ($responses as $resp) {
            $answers = json_decode($resp['responses_json'] ?? '{}', true);
            if (!is_array($answers)) {
                continue;
            }
            $answer_text = isset($answers[$question_key]) ? trim((string) $answers[$question_key]) : '';
            if ($answer_text === '') {
                continue;
            }

            $rows[] = array(
                'response_id'     => $resp['response_id'] ?? 0,
                'participant_name'=> $resp['participant_name'] ?? '',
                'school_name'     => $school_map[(int) ($resp['enrollment_id'] ?? 0)] ?? '',
                'course_title'    => $resp['course_title'] ?? '',
                'response_text'   => $answer_text,
                'submitted_at'    => $resp['submitted_at'] ?? '',
            );
        }

        // Sort by date descending.
        usort($rows, function ($a, $b) {
            return strcmp($b['submitted_at'], $a['submitted_at']);
        });

        return $rows;
    }

    // ─── CSV Export ────────────────────────────────────────────────

    /**
     * Stream CSV export. Must be called before any HTML output.
     */
    private function handle_csv_export() {
        if (!current_user_can('manage_hl_core')) {
            wp_die(esc_html__('Unauthorized.', 'hl-core'));
        }

        $filters   = $this->get_filters();
        $survey_id = $filters['survey_id'];

        if (!$survey_id) {
            return; // Fall through to render page.
        }

        $survey_repo   = new HL_Survey_Repository();
        $response_repo = new HL_Survey_Response_Repository();

        $survey    = $survey_repo->get_by_id($survey_id);
        $responses = $response_repo->get_responses_for_report($survey_id, $filters);

        if (!$survey || empty($responses)) {
            return; // Fall through to render page.
        }

        $questions     = $survey->get_questions();
        $question_keys = array_column($questions, 'question_key');

        // Build question text map.
        $question_text_map = array();
        foreach ($questions as $q) {
            $question_text_map[$q['question_key']] = $q['text_en'] ?? $q['question_key'];
        }

        // Batch-load school names.
        global $wpdb;
        $enrollment_ids = array_unique(array_column($responses, 'enrollment_id'));
        $school_map     = array();
        if (!empty($enrollment_ids)) {
            $placeholders = implode(',', array_fill(0, count($enrollment_ids), '%d'));
            $school_rows  = $wpdb->get_results($wpdb->prepare(
                "SELECT e.enrollment_id, o.name AS school_name
                 FROM {$wpdb->prefix}hl_enrollment e
                 LEFT JOIN {$wpdb->prefix}hl_orgunit o ON o.orgunit_id = e.school_id
                 WHERE e.enrollment_id IN ({$placeholders})",
                ...$enrollment_ids
            ), ARRAY_A) ?: array();
            foreach ($school_rows as $sr) {
                $school_map[(int) $sr['enrollment_id']] = $sr['school_name'] ?? '';
            }
        }

        // Batch-load cycle names.
        $cycle_ids = array_unique(array_filter(array_column($responses, 'cycle_id')));
        $cycle_map = array();
        if (!empty($cycle_ids)) {
            $placeholders = implode(',', array_fill(0, count($cycle_ids), '%d'));
            $cycle_rows   = $wpdb->get_results($wpdb->prepare(
                "SELECT cycle_id, cycle_name FROM {$wpdb->prefix}hl_cycle WHERE cycle_id IN ({$placeholders})",
                ...$cycle_ids
            ), ARRAY_A) ?: array();
            foreach ($cycle_rows as $cr) {
                $cycle_map[(int) $cr['cycle_id']] = $cr['cycle_name'] ?? '';
            }
        }

        $filename = 'survey-responses-' . $survey_id . '-' . gmdate('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // BOM for Excel UTF-8 compatibility.
        fwrite($output, "\xEF\xBB\xBF");

        // Row 1: Column keys.
        $header_keys = array('Participant', 'Email', 'School', 'Cycle', 'Course Code', 'Course Title', 'Survey Version', 'Language');
        $header_keys = array_merge($header_keys, $question_keys);
        $header_keys[] = 'Submitted At';
        fputcsv($output, $header_keys);

        // Row 2: Full English question text for question columns.
        $header_text = array('', '', '', '', '', '', '', '');
        foreach ($question_keys as $key) {
            $header_text[] = $question_text_map[$key] ?? $key;
        }
        $header_text[] = '';
        fputcsv($output, $header_text);

        // Row 3+: Data rows.
        foreach ($responses as $resp) {
            $answers = json_decode($resp['responses_json'] ?? '{}', true);
            if (!is_array($answers)) {
                $answers = array();
            }

            $row = array(
                $resp['participant_name'] ?? '',
                $resp['user_email'] ?? '',
                $school_map[(int) ($resp['enrollment_id'] ?? 0)] ?? '',
                $cycle_map[(int) ($resp['cycle_id'] ?? 0)] ?? '',
                $resp['catalog_code'] ?? '',
                $resp['course_title'] ?? '',
                $survey->version ?? '',
                $resp['language_preference'] ?? ($resp['language'] ?? 'en'),
            );

            foreach ($question_keys as $key) {
                $row[] = isset($answers[$key]) ? $answers[$key] : '';
            }

            $row[] = $resp['submitted_at'] ?? '';
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    // ─── Delete Responses ──────────────────────────────────────────

    /**
     * Handle bulk-delete of selected responses from the open-text view.
     */
    private function handle_delete_responses() {
        if (!wp_verify_nonce($_POST['hl_delete_survey_responses_nonce'], 'hl_delete_survey_responses')) {
            wp_die(esc_html__('Nonce verification failed.', 'hl-core'));
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die(esc_html__('Unauthorized.', 'hl-core'));
        }

        $response_ids = isset($_POST['response_ids']) && is_array($_POST['response_ids'])
            ? array_map('absint', $_POST['response_ids'])
            : array();
        $response_ids = array_filter($response_ids);

        if (empty($response_ids)) {
            wp_redirect(wp_get_referer() ?: admin_url('admin.php?page=hl-survey-reports'));
            exit;
        }

        $response_repo = new HL_Survey_Response_Repository();
        $deleted_count = $response_repo->delete_responses($response_ids);

        if (class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log('survey.responses_bulk_deleted', array(
                'entity_type' => 'survey_response',
                'after_data'  => array(
                    'response_ids'   => $response_ids,
                    'deleted_count'  => $deleted_count,
                ),
            ));
        }

        $survey_id = absint($_POST['redirect_survey_id'] ?? 0);
        $redirect  = admin_url('admin.php?page=hl-survey-reports&survey_id=' . $survey_id . '&message=responses_deleted');
        wp_redirect($redirect);
        exit;
    }
}
