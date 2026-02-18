<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Instruments Page
 *
 * Full CRUD admin page for managing Children Assessment Instruments.
 * Supports question editor with dynamic add/remove rows (no page reload).
 *
 * Instrument types: children_infant, children_toddler, children_preschool.
 * Questions stored as JSON array in hl_instrument.questions column.
 *
 * @package HL_Core
 */
class HL_Admin_Instruments {

    /**
     * Singleton instance
     *
     * @var HL_Admin_Instruments|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return HL_Admin_Instruments
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // No hooks needed.
    }

    /**
     * Main render entry point
     */
    public function render_page() {
        $this->handle_actions();

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';

        echo '<div class="wrap">';

        switch ($action) {
            case 'new':
                $this->render_form();
                break;

            case 'edit':
                $instrument_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $instrument    = $this->get_instrument($instrument_id);
                if ($instrument) {
                    $this->render_form($instrument);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Instrument not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;

            case 'delete':
                $this->handle_delete();
                $this->render_list();
                break;

            default:
                $this->render_list();
                break;
        }

        echo '</div>';
    }

    /**
     * Get a single instrument by ID
     *
     * @param int $instrument_id
     * @return object|null
     */
    private function get_instrument($instrument_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_instrument WHERE instrument_id = %d",
            $instrument_id
        ));
    }

    /**
     * Handle form submissions (create/update)
     */
    private function handle_actions() {
        if (!isset($_POST['hl_instrument_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['hl_instrument_nonce'], 'hl_save_instrument')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $instrument_id = isset($_POST['instrument_id']) ? absint($_POST['instrument_id']) : 0;

        // Validate instrument_type
        $valid_types = array('children_infant', 'children_toddler', 'children_preschool');
        $instrument_type = sanitize_text_field($_POST['instrument_type']);
        if (!in_array($instrument_type, $valid_types, true)) {
            wp_die(__('Invalid instrument type.', 'hl-core'));
        }

        // Build questions JSON from POST data
        $questions = $this->build_questions_from_post();

        $data = array(
            'name'            => sanitize_text_field($_POST['name']),
            'instrument_type' => $instrument_type,
            'version'         => sanitize_text_field($_POST['version'] ?: '1.0'),
            'questions'       => wp_json_encode($questions),
            'effective_from'  => !empty($_POST['effective_from']) ? sanitize_text_field($_POST['effective_from']) : null,
            'effective_to'    => !empty($_POST['effective_to']) ? sanitize_text_field($_POST['effective_to']) : null,
        );

        global $wpdb;

        if ($instrument_id > 0) {
            // Update
            $data['updated_at'] = current_time('mysql');
            $wpdb->update($wpdb->prefix . 'hl_instrument', $data, array('instrument_id' => $instrument_id));

            HL_Audit_Service::log('instrument.updated', array(
                'entity_type' => 'instrument',
                'entity_id'   => $instrument_id,
                'after_data'  => array(
                    'name'            => $data['name'],
                    'instrument_type' => $data['instrument_type'],
                    'version'         => $data['version'],
                    'question_count'  => count($questions),
                ),
            ));

            $redirect = admin_url('admin.php?page=hl-instruments&message=updated');
        } else {
            // Create
            $data['instrument_uuid'] = HL_DB_Utils::generate_uuid();
            $data['created_at']      = current_time('mysql');
            $data['updated_at']      = current_time('mysql');
            $wpdb->insert($wpdb->prefix . 'hl_instrument', $data);
            $new_id = $wpdb->insert_id;

            HL_Audit_Service::log('instrument.created', array(
                'entity_type' => 'instrument',
                'entity_id'   => $new_id,
                'after_data'  => array(
                    'name'            => $data['name'],
                    'instrument_type' => $data['instrument_type'],
                    'version'         => $data['version'],
                    'question_count'  => count($questions),
                ),
            ));

            $redirect = admin_url('admin.php?page=hl-instruments&message=created');
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Build the questions array from POST data
     *
     * Expects: questions[0][question_id], questions[0][question_type], etc.
     *
     * @return array
     */
    private function build_questions_from_post() {
        $questions = array();

        if (empty($_POST['questions']) || !is_array($_POST['questions'])) {
            return $questions;
        }

        foreach ($_POST['questions'] as $q) {
            // Skip empty/incomplete rows
            $question_id = isset($q['question_id']) ? sanitize_text_field(trim($q['question_id'])) : '';
            $prompt_text = isset($q['prompt_text']) ? sanitize_text_field(trim($q['prompt_text'])) : '';

            if (empty($question_id) || empty($prompt_text)) {
                continue;
            }

            $valid_question_types = array('likert', 'text', 'number', 'single_select', 'multi_select');
            $question_type = isset($q['question_type']) ? sanitize_text_field($q['question_type']) : 'text';
            if (!in_array($question_type, $valid_question_types, true)) {
                $question_type = 'text';
            }

            // Parse allowed_values from comma-separated string
            $allowed_values = array();
            if (!empty($q['allowed_values'])) {
                $raw = sanitize_textarea_field($q['allowed_values']);
                $parts = array_map('trim', explode(',', $raw));
                $allowed_values = array_filter($parts, function($v) { return $v !== ''; });
                $allowed_values = array_values($allowed_values);
            }

            $questions[] = array(
                'question_id'    => $question_id,
                'question_type'  => $question_type,
                'prompt_text'    => $prompt_text,
                'allowed_values' => $allowed_values,
                'required'       => !empty($q['required']) ? true : false,
            );
        }

        return $questions;
    }

    /**
     * Handle delete action
     */
    private function handle_delete() {
        $instrument_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if (!$instrument_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_instrument_' . $instrument_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;

        // Get instrument info before deleting for audit log
        $instrument = $this->get_instrument($instrument_id);

        $wpdb->delete($wpdb->prefix . 'hl_instrument', array('instrument_id' => $instrument_id));

        if ($instrument) {
            HL_Audit_Service::log('instrument.deleted', array(
                'entity_type' => 'instrument',
                'entity_id'   => $instrument_id,
                'before_data' => array(
                    'name'            => $instrument->name,
                    'instrument_type' => $instrument->instrument_type,
                    'version'         => $instrument->version,
                ),
            ));
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Instrument deleted successfully.', 'hl-core') . '</p></div>';
    }

    /**
     * Render the instruments list table
     */
    private function render_list() {
        global $wpdb;

        $instruments = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_instrument
             WHERE instrument_type IN ('children_infant','children_toddler','children_preschool')
             ORDER BY name ASC"
        );

        // Show success messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'created') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Instrument created successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'updated') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Instrument updated successfully.', 'hl-core') . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Children Assessment Instruments', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-instruments&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        if (empty($instruments)) {
            echo '<p>' . esc_html__('No instruments found. Create your first children assessment instrument to get started.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Version', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Questions', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Effective From', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Effective To', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        $type_labels = array(
            'children_infant'    => __('Infant', 'hl-core'),
            'children_toddler'   => __('Toddler', 'hl-core'),
            'children_preschool' => __('Preschool', 'hl-core'),
        );

        foreach ($instruments as $inst) {
            $edit_url   = admin_url('admin.php?page=hl-instruments&action=edit&id=' . $inst->instrument_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-instruments&action=delete&id=' . $inst->instrument_id),
                'hl_delete_instrument_' . $inst->instrument_id
            );

            // Count questions
            $questions = json_decode($inst->questions, true);
            $question_count = is_array($questions) ? count($questions) : 0;

            $type_display = isset($type_labels[$inst->instrument_type]) ? $type_labels[$inst->instrument_type] : $inst->instrument_type;

            echo '<tr>';
            echo '<td>' . esc_html($inst->instrument_id) . '</td>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($inst->name) . '</a></strong></td>';
            echo '<td>' . esc_html($type_display) . '</td>';
            echo '<td><code>' . esc_html($inst->version) . '</code></td>';
            echo '<td>' . esc_html($question_count) . '</td>';
            echo '<td>' . esc_html($inst->effective_from ?: '-') . '</td>';
            echo '<td>' . esc_html($inst->effective_to ?: '-') . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this instrument? This cannot be undone.', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    /**
     * Render the create/edit form
     *
     * @param object|null $instrument Instrument object for edit, null for create.
     */
    private function render_form($instrument = null) {
        $is_edit = ($instrument !== null);
        $title   = $is_edit ? __('Edit Instrument', 'hl-core') : __('Add New Instrument', 'hl-core');

        // Parse existing questions for edit
        $questions = array();
        if ($is_edit && !empty($instrument->questions)) {
            $decoded = json_decode($instrument->questions, true);
            if (is_array($decoded)) {
                $questions = $decoded;
            }
        }

        // Check if instrument has been used (instances exist)
        $has_instances = false;
        if ($is_edit) {
            global $wpdb;
            $instance_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}hl_children_assessment_instance WHERE instrument_id = %d",
                $instrument->instrument_id
            ));
            $has_instances = ($instance_count > 0);
        }

        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-instruments')) . '">&larr; ' . esc_html__('Back to Instruments', 'hl-core') . '</a>';

        // Version management warning
        if ($has_instances) {
            echo '<div class="notice notice-warning" style="margin-top:15px;">';
            echo '<p><strong>' . esc_html__('Warning:', 'hl-core') . '</strong> ';
            echo esc_html__('This instrument has been used in existing assessment instances. Editing questions may affect data consistency. Consider creating a new instrument with an incremented version instead.', 'hl-core');
            echo '</p>';
            echo '</div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-instruments')) . '" id="hl-instrument-form">';
        wp_nonce_field('hl_save_instrument', 'hl_instrument_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="instrument_id" value="' . esc_attr($instrument->instrument_id) . '" />';
        }

        echo '<table class="form-table">';

        // Name
        echo '<tr>';
        echo '<th scope="row"><label for="name">' . esc_html__('Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="name" name="name" value="' . esc_attr($is_edit ? $instrument->name : '') . '" class="regular-text" required /></td>';
        echo '</tr>';

        // Instrument Type
        $current_type = $is_edit ? $instrument->instrument_type : '';
        $instrument_types = array(
            'children_infant'    => __('Children - Infant', 'hl-core'),
            'children_toddler'   => __('Children - Toddler', 'hl-core'),
            'children_preschool' => __('Children - Preschool', 'hl-core'),
        );

        echo '<tr>';
        echo '<th scope="row"><label for="instrument_type">' . esc_html__('Instrument Type', 'hl-core') . '</label></th>';
        echo '<td><select id="instrument_type" name="instrument_type" required>';
        echo '<option value="">' . esc_html__('-- Select Type --', 'hl-core') . '</option>';
        foreach ($instrument_types as $type_value => $type_label) {
            echo '<option value="' . esc_attr($type_value) . '"' . selected($current_type, $type_value, false) . '>' . esc_html($type_label) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';

        // Version
        echo '<tr>';
        echo '<th scope="row"><label for="version">' . esc_html__('Version', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="version" name="version" value="' . esc_attr($is_edit ? $instrument->version : '1.0') . '" class="small-text" />';
        echo '<p class="description">' . esc_html__('e.g. 1.0, 2.0, 1.1', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Effective From
        echo '<tr>';
        echo '<th scope="row"><label for="effective_from">' . esc_html__('Effective From', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="effective_from" name="effective_from" value="' . esc_attr($is_edit && $instrument->effective_from ? $instrument->effective_from : '') . '" />';
        echo '<p class="description">' . esc_html__('Optional. When this instrument version becomes active.', 'hl-core') . '</p></td>';
        echo '</tr>';

        // Effective To
        echo '<tr>';
        echo '<th scope="row"><label for="effective_to">' . esc_html__('Effective To', 'hl-core') . '</label></th>';
        echo '<td><input type="date" id="effective_to" name="effective_to" value="' . esc_attr($is_edit && $instrument->effective_to ? $instrument->effective_to : '') . '" />';
        echo '<p class="description">' . esc_html__('Optional. When this instrument version expires.', 'hl-core') . '</p></td>';
        echo '</tr>';

        echo '</table>';

        // =====================================================================
        // Questions Editor
        // =====================================================================

        echo '<h2>' . esc_html__('Questions', 'hl-core') . '</h2>';
        echo '<p class="description">' . esc_html__('Define the questions for this children assessment instrument. Each question will appear as a column in the per-child assessment matrix.', 'hl-core') . '</p>';

        echo '<table class="widefat" id="hl-questions-table">';
        echo '<thead><tr>';
        echo '<th style="width:140px;">' . esc_html__('Question ID', 'hl-core') . '</th>';
        echo '<th style="width:130px;">' . esc_html__('Type', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Prompt Text', 'hl-core') . '</th>';
        echo '<th style="width:200px;">' . esc_html__('Allowed Values', 'hl-core') . '</th>';
        echo '<th style="width:60px;">' . esc_html__('Required', 'hl-core') . '</th>';
        echo '<th style="width:80px;">' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody id="hl-questions-body">';

        // Render existing questions
        if (!empty($questions)) {
            foreach ($questions as $index => $q) {
                $this->render_question_row($index, $q);
            }
        }

        echo '</tbody>';
        echo '</table>';

        // Add Question button
        echo '<p><button type="button" class="button" id="hl-add-question">' . esc_html__('+ Add Question', 'hl-core') . '</button></p>';

        // Hidden template row (used by JavaScript to clone)
        echo '<table style="display:none;" id="hl-question-template-wrapper">';
        echo '<tbody>';
        $this->render_question_row('__INDEX__', array(
            'question_id'    => '',
            'question_type'  => 'likert',
            'prompt_text'    => '',
            'allowed_values' => array(),
            'required'       => false,
        ));
        echo '</tbody>';
        echo '</table>';

        submit_button($is_edit ? __('Update Instrument', 'hl-core') : __('Create Instrument', 'hl-core'));

        echo '</form>';

        // Render the JavaScript for the questions editor
        $this->render_questions_editor_js(count($questions));
    }

    /**
     * Render a single question row in the questions editor table
     *
     * @param int|string $index Row index (integer or '__INDEX__' for template)
     * @param array      $q     Question data
     */
    private function render_question_row($index, $q) {
        $question_id    = isset($q['question_id']) ? $q['question_id'] : '';
        $question_type  = isset($q['question_type']) ? $q['question_type'] : 'likert';
        $prompt_text    = isset($q['prompt_text']) ? $q['prompt_text'] : '';
        $allowed_values = isset($q['allowed_values']) && is_array($q['allowed_values']) ? implode(', ', $q['allowed_values']) : '';
        $required       = !empty($q['required']);

        $name_prefix = 'questions[' . $index . ']';

        $question_types = array(
            'likert'       => __('Likert', 'hl-core'),
            'text'         => __('Text', 'hl-core'),
            'number'       => __('Number', 'hl-core'),
            'single_select' => __('Single Select', 'hl-core'),
            'multi_select'  => __('Multi Select', 'hl-core'),
        );

        echo '<tr class="hl-question-row">';

        // Question ID
        echo '<td><input type="text" name="' . esc_attr($name_prefix . '[question_id]') . '" value="' . esc_attr($question_id) . '" class="widefat" placeholder="e.g. q1, motor_skills_1" required /></td>';

        // Question Type
        echo '<td><select name="' . esc_attr($name_prefix . '[question_type]') . '" class="widefat">';
        foreach ($question_types as $type_val => $type_lbl) {
            echo '<option value="' . esc_attr($type_val) . '"' . selected($question_type, $type_val, false) . '>' . esc_html($type_lbl) . '</option>';
        }
        echo '</select></td>';

        // Prompt Text
        echo '<td><input type="text" name="' . esc_attr($name_prefix . '[prompt_text]') . '" value="' . esc_attr($prompt_text) . '" class="widefat" placeholder="' . esc_attr__('Question prompt text', 'hl-core') . '" required /></td>';

        // Allowed Values
        echo '<td><textarea name="' . esc_attr($name_prefix . '[allowed_values]') . '" class="widefat" rows="2" placeholder="' . esc_attr__('1,2,3,4,5 or Never,Sometimes,Often,Always', 'hl-core') . '">' . esc_textarea($allowed_values) . '</textarea></td>';

        // Required
        echo '<td style="text-align:center;"><input type="checkbox" name="' . esc_attr($name_prefix . '[required]') . '" value="1"' . checked($required, true, false) . ' /></td>';

        // Remove button
        echo '<td><button type="button" class="button button-link-delete hl-remove-question">' . esc_html__('Remove', 'hl-core') . '</button></td>';

        echo '</tr>';
    }

    /**
     * Render the JavaScript for the questions editor
     *
     * Handles adding/removing question rows dynamically.
     *
     * @param int $initial_count Number of existing questions
     */
    private function render_questions_editor_js($initial_count = 0) {
        ?>
        <script type="text/javascript">
        (function() {
            var questionIndex = <?php echo intval($initial_count); ?>;
            var addButton     = document.getElementById('hl-add-question');
            var tbody         = document.getElementById('hl-questions-body');
            var templateRow   = document.querySelector('#hl-question-template-wrapper tbody tr');

            if (!addButton || !tbody || !templateRow) return;

            // Add Question
            addButton.addEventListener('click', function() {
                var newRow = templateRow.cloneNode(true);
                var html   = newRow.innerHTML;

                // Replace __INDEX__ placeholder with the current index
                var regex = /__INDEX__/g;
                html = html.replace(regex, questionIndex);
                newRow.innerHTML = html;

                tbody.appendChild(newRow);
                questionIndex++;

                // Attach remove handler to the new row
                attachRemoveHandler(newRow);
            });

            // Remove Question — delegate to existing rows
            function attachRemoveHandler(row) {
                var removeBtn = row.querySelector('.hl-remove-question');
                if (removeBtn) {
                    removeBtn.addEventListener('click', function() {
                        if (confirm('<?php echo esc_js(__('Remove this question?', 'hl-core')); ?>')) {
                            row.parentNode.removeChild(row);
                        }
                    });
                }
            }

            // Attach remove handlers to all existing rows on load
            var existingRows = tbody.querySelectorAll('.hl-question-row');
            for (var i = 0; i < existingRows.length; i++) {
                attachRemoveHandler(existingRows[i]);
            }
        })();
        </script>
        <?php
    }
}
