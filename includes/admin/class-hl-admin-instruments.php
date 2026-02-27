<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Instruments Page
 *
 * Full CRUD admin page for managing Child Assessment Instruments.
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
     * Handle POST saves and GET deletes before any HTML output.
     */
    public function handle_early_actions() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'children';

        if ($tab === 'teacher') {
            $this->handle_teacher_actions();
            if (isset($_GET['action']) && $_GET['action'] === 'delete') {
                $this->handle_teacher_delete();
            }
        } else {
            $this->handle_actions();
            if (isset($_GET['action']) && $_GET['action'] === 'delete') {
                $this->handle_delete();
            }
        }
    }

    /**
     * Main render entry point
     */
    public function render_page() {
        $tab    = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'children';
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';

        echo '<div class="wrap">';

        // Tab navigation
        $this->render_tabs($tab);

        if ($tab === 'teacher') {
            $this->render_teacher_tab($action);
        } else {
            $this->render_children_tab($action);
        }

        echo '</div>';
    }

    /**
     * Render the tab navigation.
     */
    private function render_tabs($active_tab) {
        $tabs = array(
            'children' => __('Child Assessment Instruments', 'hl-core'),
            'teacher'  => __('Teacher Assessment Instruments', 'hl-core'),
        );

        echo '<nav class="nav-tab-wrapper" style="margin-bottom: 15px;">';
        foreach ($tabs as $tab_key => $tab_label) {
            $url   = admin_url('admin.php?page=hl-instruments&tab=' . $tab_key);
            $class = ($active_tab === $tab_key) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($tab_label) . '</a>';
        }
        echo '</nav>';
    }

    /**
     * Render the children instruments tab.
     */
    private function render_children_tab($action) {
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
            case 'preview':
                $instrument_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $instrument    = $this->get_instrument($instrument_id);
                if ($instrument) {
                    $this->render_child_preview($instrument);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Instrument not found.', 'hl-core') . '</p></div>';
                    $this->render_list();
                }
                break;
            default:
                $this->render_list();
                break;
        }
    }

    /**
     * Render the teacher instruments tab.
     */
    private function render_teacher_tab($action) {
        switch ($action) {
            case 'new':
                $this->render_teacher_form();
                break;
            case 'edit':
                $instrument_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $instrument    = $this->get_teacher_instrument($instrument_id);
                if ($instrument) {
                    $this->render_teacher_form($instrument);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Instrument not found.', 'hl-core') . '</p></div>';
                    $this->render_teacher_list();
                }
                break;
            case 'preview':
                $instrument_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                $instrument    = $this->get_teacher_instrument($instrument_id);
                if ($instrument) {
                    $this->render_teacher_preview($instrument);
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Instrument not found.', 'hl-core') . '</p></div>';
                    $this->render_teacher_list();
                }
                break;
            default:
                $this->render_teacher_list();
                break;
        }
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
        $valid_types = array('children_infant', 'children_toddler', 'children_preschool', 'children_k2', 'children_mixed');
        $instrument_type = sanitize_text_field($_POST['instrument_type']);
        if (!in_array($instrument_type, $valid_types, true)) {
            wp_die(__('Invalid instrument type.', 'hl-core'));
        }

        // Build questions JSON from POST data
        $questions = $this->build_questions_from_post();

        // Instructions (rich text)
        $instructions = isset($_POST['instructions']) ? wp_kses_post(wp_unslash($_POST['instructions'])) : '';
        $instructions = trim($instructions) !== '' ? $instructions : null;

        // Behavior key (5-row table: label, frequency, description)
        $behavior_key = null;
        if (!empty($_POST['behavior_key']) && is_array($_POST['behavior_key'])) {
            $bk_rows = array();
            $all_blank = true;
            foreach ($_POST['behavior_key'] as $row) {
                $label       = isset($row['label']) ? sanitize_text_field($row['label']) : '';
                $frequency   = isset($row['frequency']) ? sanitize_text_field($row['frequency']) : '';
                $description = isset($row['description']) ? sanitize_textarea_field($row['description']) : '';
                if ($label !== '' || $frequency !== '' || $description !== '') {
                    $all_blank = false;
                }
                $bk_rows[] = array(
                    'label'       => $label,
                    'frequency'   => $frequency,
                    'description' => $description,
                );
            }
            if (!$all_blank) {
                $behavior_key = wp_json_encode($bk_rows);
            }
        }

        // Display styles (font sizes and colors).
        $styles_json = $this->build_styles_from_post();

        $data = array(
            'name'            => sanitize_text_field($_POST['name']),
            'instrument_type' => $instrument_type,
            'version'         => sanitize_text_field($_POST['version'] ?: '1.0'),
            'questions'       => wp_json_encode($questions),
            'instructions'    => $instructions,
            'behavior_key'    => $behavior_key,
            'styles_json'     => $styles_json,
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

        wp_redirect(admin_url('admin.php?page=hl-instruments&message=deleted'));
        exit;
    }

    /**
     * Render the instruments list table
     */
    private function render_list() {
        global $wpdb;

        $instruments = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_instrument
             WHERE instrument_type LIKE 'children_%'
             ORDER BY name ASC"
        );

        // Show success messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'created') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Instrument created successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'updated') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Instrument updated successfully.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'deleted') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Instrument deleted successfully.', 'hl-core') . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Child Assessment Instruments', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-instruments&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        if (empty($instruments)) {
            echo '<p>' . esc_html__('No instruments found. Create your first child assessment instrument to get started.', 'hl-core') . '</p>';
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
            'children_k2'        => __('K-2nd Grade', 'hl-core'),
            'children_mixed'     => __('Mixed Age', 'hl-core'),
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
            $preview_url = admin_url('admin.php?page=hl-instruments&action=preview&id=' . $inst->instrument_id);
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($preview_url) . '" class="button button-small" target="_blank">' . esc_html__('Preview', 'hl-core') . '</a> ';
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
                "SELECT COUNT(*) FROM {$wpdb->prefix}hl_child_assessment_instance WHERE instrument_id = %d",
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
            'children_preschool' => __('Children - Preschool / Pre-K', 'hl-core'),
            'children_k2'        => __('Children - K-2nd Grade', 'hl-core'),
            'children_mixed'     => __('Children - Mixed Age', 'hl-core'),
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
        // Instructions (rich text editor)
        // =====================================================================

        echo '<h2>' . esc_html__('Instructions', 'hl-core') . '</h2>';
        echo '<p class="description">' . esc_html__('Custom instructions shown to teachers above the assessment form. Leave blank to use the default instructions.', 'hl-core') . '</p>';

        $instructions_content = ($is_edit && !empty($instrument->instructions)) ? $instrument->instructions : '';
        wp_editor($instructions_content, 'child_instructions', array(
            'textarea_name' => 'instructions',
            'textarea_rows' => 6,
            'media_buttons' => false,
            'teeny'         => true,
            'quicktags'     => false,
            'tinymce'       => array(
                'toolbar1' => 'bold,italic,underline,bullist,numlist,undo,redo',
                'toolbar2' => '',
            ),
        ));

        // =====================================================================
        // Behavior Key (5-row fixed table)
        // =====================================================================

        echo '<h2>' . esc_html__('Behavior Key', 'hl-core') . '</h2>';
        echo '<p class="description">' . esc_html__('Customize the Key & Example Behavior table shown on the assessment form. Leave all fields blank to use the default behavior key for this age band.', 'hl-core') . '</p>';

        $bk_data = array();
        if ($is_edit && !empty($instrument->behavior_key)) {
            $decoded_bk = json_decode($instrument->behavior_key, true);
            if (is_array($decoded_bk)) {
                $bk_data = $decoded_bk;
            }
        }

        $default_labels      = array('Never', 'Rarely', 'Sometimes', 'Usually', 'Almost Always');
        $default_frequencies = array('0% of the time', '~ 20% of the time', '~ 50% of the time', '~ 70% of the time', '~ 90% of the time');

        echo '<table class="widefat" id="hl-behavior-key-table">';
        echo '<thead><tr>';
        echo '<th style="width:150px;">' . esc_html__('Label', 'hl-core') . '</th>';
        echo '<th style="width:180px;">' . esc_html__('Frequency', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Example Behavior Description', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        for ($i = 0; $i < 5; $i++) {
            $label       = isset($bk_data[$i]['label']) ? $bk_data[$i]['label'] : '';
            $frequency   = isset($bk_data[$i]['frequency']) ? $bk_data[$i]['frequency'] : '';
            $description = isset($bk_data[$i]['description']) ? $bk_data[$i]['description'] : '';

            echo '<tr>';
            echo '<td><input type="text" name="behavior_key[' . $i . '][label]" value="' . esc_attr($label) . '" class="widefat" placeholder="' . esc_attr($default_labels[$i]) . '" /></td>';
            echo '<td><input type="text" name="behavior_key[' . $i . '][frequency]" value="' . esc_attr($frequency) . '" class="widefat" placeholder="' . esc_attr($default_frequencies[$i]) . '" /></td>';
            echo '<td><textarea name="behavior_key[' . $i . '][description]" class="widefat" rows="2" placeholder="' . esc_attr__('Example behavior description...', 'hl-core') . '">' . esc_textarea($description) . '</textarea></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        // =====================================================================
        // Questions Editor
        // =====================================================================

        echo '<h2>' . esc_html__('Questions', 'hl-core') . '</h2>';
        echo '<p class="description">' . esc_html__('Define the questions for this child assessment instrument. Each question will appear as a column in the per-child assessment matrix.', 'hl-core') . '</p>';

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

        // Display Styles panel.
        $styles = array();
        if ($is_edit && !empty($instrument->styles_json)) {
            $styles = json_decode($instrument->styles_json, true) ?: array();
        }
        $this->render_styles_panel($styles, array(
            'instructions'  => __('Instructions', 'hl-core'),
            'behavior_key'  => __('Behavior Key', 'hl-core'),
            'item'          => __('Questions / Items', 'hl-core'),
            'scale_label'   => __('Scale Labels', 'hl-core'),
        ));

        echo '<div style="display: flex; align-items: center; gap: 12px;">';
        submit_button($is_edit ? __('Update Instrument', 'hl-core') : __('Create Instrument', 'hl-core'), 'primary', 'submit', false);
        if ($is_edit) {
            $preview_url = admin_url('admin.php?page=hl-instruments&action=preview&id=' . $instrument->instrument_id);
            echo ' <a href="' . esc_url($preview_url) . '" class="button button-secondary" target="_blank">' . esc_html__('Preview', 'hl-core') . '</a>';
        }
        echo '</div>';

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

            // Sync TinyMCE editors (instructions) to their textareas on form submit.
            var childForm = document.getElementById('hl-instrument-form');
            if (childForm) {
                childForm.addEventListener('submit', function() {
                    if (typeof tinyMCE !== 'undefined') {
                        tinyMCE.triggerSave();
                    }
                });
            }
        })();
        </script>
        <?php
    }

    // =====================================================================
    // Teacher Assessment Instruments (Tab 2)
    // =====================================================================

    /**
     * Get a teacher assessment instrument by ID.
     */
    private function get_teacher_instrument($instrument_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_id = %d",
            $instrument_id
        ));
    }

    /**
     * Handle teacher instrument form submissions.
     */
    private function handle_teacher_actions() {
        if (!isset($_POST['hl_teacher_instrument_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['hl_teacher_instrument_nonce'], 'hl_save_teacher_instrument')) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        $instrument_id = isset($_POST['instrument_id']) ? absint($_POST['instrument_id']) : 0;

        $data = array(
            'instrument_name'    => sanitize_text_field($_POST['instrument_name']),
            'instrument_key'     => sanitize_text_field($_POST['instrument_key']),
            'instrument_version' => sanitize_text_field($_POST['instrument_version'] ?: '1.0'),
            'status'             => sanitize_text_field($_POST['status']),
            'instructions'       => wp_kses_post(wp_unslash($_POST['instructions'] ?? '')),
        );

        // Build scale_labels from structured POST fields
        $scale_labels = array();
        if (!empty($_POST['scale_labels']) && is_array($_POST['scale_labels'])) {
            foreach ($_POST['scale_labels'] as $scale_key => $scale_data) {
                $scale_key = sanitize_text_field($scale_key);
                if (isset($scale_data['type']) && $scale_data['type'] === 'object') {
                    // Object scale: {low, high}
                    $scale_labels[$scale_key] = array(
                        'low'  => sanitize_text_field($scale_data['low'] ?? ''),
                        'high' => sanitize_text_field($scale_data['high'] ?? ''),
                    );
                } else {
                    // Array scale: ordered labels
                    $labels = array();
                    if (!empty($scale_data['labels']) && is_array($scale_data['labels'])) {
                        foreach ($scale_data['labels'] as $label) {
                            $label = sanitize_text_field($label);
                            if ($label !== '') {
                                $labels[] = $label;
                            }
                        }
                    }
                    $scale_labels[$scale_key] = $labels;
                }
            }
        }

        // Build sections from structured POST fields
        $sections = array();
        if (!empty($_POST['sections']) && is_array($_POST['sections'])) {
            foreach ($_POST['sections'] as $s) {
                $section_key = sanitize_text_field($s['section_key'] ?? '');
                if (empty($section_key)) {
                    continue;
                }
                $section = array(
                    'section_key' => $section_key,
                    'title'       => sanitize_text_field($s['title'] ?? ''),
                    'description' => wp_kses_post(wp_unslash($s['description'] ?? '')),
                    'type'        => sanitize_text_field($s['type'] ?? 'likert'),
                    'scale_key'   => sanitize_text_field($s['scale_key'] ?? ''),
                );

                $items = array();
                if (!empty($s['items']) && is_array($s['items'])) {
                    foreach ($s['items'] as $item) {
                        $item_key = sanitize_text_field($item['key'] ?? '');
                        if (empty($item_key)) {
                            continue;
                        }
                        $item_entry = array(
                            'key'  => $item_key,
                            'text' => wp_kses_post(wp_unslash($item['text'] ?? '')),
                        );
                        // Preserve left_anchor / right_anchor for scale items
                        if (isset($item['left_anchor'])) {
                            $item_entry['left_anchor'] = sanitize_text_field($item['left_anchor']);
                        }
                        if (isset($item['right_anchor'])) {
                            $item_entry['right_anchor'] = sanitize_text_field($item['right_anchor']);
                        }
                        $items[] = $item_entry;
                    }
                }
                $section['items'] = $items;
                $sections[] = $section;
            }
        }

        $data['sections']     = wp_json_encode($sections);
        $data['scale_labels'] = wp_json_encode($scale_labels);
        $data['styles_json']  = $this->build_styles_from_post();

        global $wpdb;

        if ($instrument_id > 0) {
            $data['updated_at'] = current_time('mysql');
            $wpdb->update($wpdb->prefix . 'hl_teacher_assessment_instrument', $data, array('instrument_id' => $instrument_id));

            HL_Audit_Service::log('teacher_instrument.updated', array(
                'entity_type' => 'teacher_assessment_instrument',
                'entity_id'   => $instrument_id,
                'after_data'  => array(
                    'instrument_name' => $data['instrument_name'],
                    'instrument_key'  => $data['instrument_key'],
                    'version'         => $data['instrument_version'],
                ),
            ));

            $redirect = admin_url('admin.php?page=hl-instruments&tab=teacher&message=updated');
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($wpdb->prefix . 'hl_teacher_assessment_instrument', $data);
            $new_id = $wpdb->insert_id;

            HL_Audit_Service::log('teacher_instrument.created', array(
                'entity_type' => 'teacher_assessment_instrument',
                'entity_id'   => $new_id,
                'after_data'  => array(
                    'instrument_name' => $data['instrument_name'],
                    'instrument_key'  => $data['instrument_key'],
                    'version'         => $data['instrument_version'],
                ),
            ));

            $redirect = admin_url('admin.php?page=hl-instruments&tab=teacher&message=created');
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Handle teacher instrument delete action.
     */
    private function handle_teacher_delete() {
        $instrument_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$instrument_id) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'hl_delete_teacher_instrument_' . $instrument_id)) {
            wp_die(__('Security check failed.', 'hl-core'));
        }

        if (!current_user_can('manage_hl_core')) {
            wp_die(__('You do not have permission to perform this action.', 'hl-core'));
        }

        global $wpdb;
        $instrument = $this->get_teacher_instrument($instrument_id);
        $wpdb->delete($wpdb->prefix . 'hl_teacher_assessment_instrument', array('instrument_id' => $instrument_id));

        if ($instrument) {
            HL_Audit_Service::log('teacher_instrument.deleted', array(
                'entity_type' => 'teacher_assessment_instrument',
                'entity_id'   => $instrument_id,
                'before_data' => array(
                    'instrument_name' => $instrument->instrument_name,
                    'instrument_key'  => $instrument->instrument_key,
                ),
            ));
        }

        wp_redirect(admin_url('admin.php?page=hl-instruments&tab=teacher&message=deleted'));
        exit;
    }

    /**
     * Render the teacher instruments list table.
     */
    private function render_teacher_list() {
        global $wpdb;

        $instruments = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument ORDER BY instrument_name ASC"
        );

        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'created') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Teacher assessment instrument created.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'updated') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Teacher assessment instrument updated.', 'hl-core') . '</p></div>';
            } elseif ($msg === 'deleted') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Teacher assessment instrument deleted.', 'hl-core') . '</p></div>';
            }
        }

        echo '<h1 class="wp-heading-inline">' . esc_html__('Teacher Assessment Instruments', 'hl-core') . '</h1>';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-instruments&tab=teacher&action=new')) . '" class="page-title-action">' . esc_html__('Add New', 'hl-core') . '</a>';
        echo '<hr class="wp-header-end">';

        if (empty($instruments)) {
            echo '<p>' . esc_html__('No teacher assessment instruments found.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Name', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Key', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Version', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Sections', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hl-core') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($instruments as $inst) {
            $edit_url   = admin_url('admin.php?page=hl-instruments&tab=teacher&action=edit&id=' . $inst->instrument_id);
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=hl-instruments&tab=teacher&action=delete&id=' . $inst->instrument_id),
                'hl_delete_teacher_instrument_' . $inst->instrument_id
            );

            $sections = json_decode($inst->sections, true);
            $section_count = is_array($sections) ? count($sections) : 0;
            $item_count = 0;
            if (is_array($sections)) {
                foreach ($sections as $s) {
                    $item_count += isset($s['items']) && is_array($s['items']) ? count($s['items']) : 0;
                }
            }

            echo '<tr>';
            echo '<td>' . esc_html($inst->instrument_id) . '</td>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($inst->instrument_name) . '</a></strong></td>';
            echo '<td><code>' . esc_html($inst->instrument_key) . '</code></td>';
            echo '<td><code>' . esc_html($inst->instrument_version) . '</code></td>';
            echo '<td>' . esc_html(sprintf('%d sections, %d items', $section_count, $item_count)) . '</td>';
            echo '<td>' . esc_html(ucfirst($inst->status)) . '</td>';
            $preview_url = admin_url('admin.php?page=hl-instruments&tab=teacher&action=preview&id=' . $inst->instrument_id);
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($preview_url) . '" class="button button-small" target="_blank">' . esc_html__('Preview', 'hl-core') . '</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this instrument?', 'hl-core')) . '\');">' . esc_html__('Delete', 'hl-core') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render the teacher instrument add/edit form (visual editor).
     *
     * @param object|null $instrument Existing instrument for edit, null for create.
     */
    private function render_teacher_form($instrument = null) {
        $is_edit = ($instrument !== null);
        $title   = $is_edit ? __('Edit Teacher Assessment Instrument', 'hl-core') : __('Add New Teacher Assessment Instrument', 'hl-core');

        // Parse existing data
        $sections     = array();
        $scale_labels = array();
        $instructions = '';
        if ($is_edit) {
            $sections     = json_decode($instrument->sections, true) ?: array();
            $scale_labels = json_decode($instrument->scale_labels, true) ?: array();
            $instructions = isset($instrument->instructions) ? $instrument->instructions : '';
        }

        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=hl-instruments&tab=teacher')) . '">&larr; ' . esc_html__('Back to Teacher Instruments', 'hl-core') . '</a>';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=hl-instruments&tab=teacher')) . '" id="hl-teacher-instrument-form">';
        wp_nonce_field('hl_save_teacher_instrument', 'hl_teacher_instrument_nonce');

        if ($is_edit) {
            echo '<input type="hidden" name="instrument_id" value="' . esc_attr($instrument->instrument_id) . '" />';
        }

        // ── Metadata fields ──────────────────────────────────────────────
        echo '<table class="form-table">';

        echo '<tr>';
        echo '<th scope="row"><label for="instrument_name">' . esc_html__('Instrument Name', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="instrument_name" name="instrument_name" value="' . esc_attr($is_edit ? $instrument->instrument_name : '') . '" class="regular-text" required /></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="instrument_key">' . esc_html__('Instrument Key', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="instrument_key" name="instrument_key" value="' . esc_attr($is_edit ? $instrument->instrument_key : '') . '" class="regular-text" required />';
        echo '<p class="description">' . esc_html__('Unique identifier (e.g., b2e_self_assessment). Used in code and seed data.', 'hl-core') . '</p></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="instrument_version">' . esc_html__('Version', 'hl-core') . '</label></th>';
        echo '<td><input type="text" id="instrument_version" name="instrument_version" value="' . esc_attr($is_edit ? $instrument->instrument_version : '1.0') . '" class="small-text" /></td>';
        echo '</tr>';

        $current_status = $is_edit ? $instrument->status : 'active';
        echo '<tr>';
        echo '<th scope="row"><label for="status">' . esc_html__('Status', 'hl-core') . '</label></th>';
        echo '<td><select id="status" name="status">';
        echo '<option value="active"' . selected($current_status, 'active', false) . '>' . esc_html__('Active', 'hl-core') . '</option>';
        echo '<option value="inactive"' . selected($current_status, 'inactive', false) . '>' . esc_html__('Inactive', 'hl-core') . '</option>';
        echo '</select></td>';
        echo '</tr>';

        echo '</table>';

        // ── Instructions (wp_editor) ─────────────────────────────────────
        echo '<h2>' . esc_html__('Instructions', 'hl-core') . '</h2>';
        echo '<p class="description">' . esc_html__('Displayed to the teacher above the assessment form. Supports bold, italic, and underline formatting.', 'hl-core') . '</p>';
        wp_editor($instructions, 'teacher_instructions', array(
            'textarea_name' => 'instructions',
            'media_buttons' => false,
            'textarea_rows' => 4,
            'teeny'         => true,
            'tinymce'       => array(
                'toolbar1' => 'bold,italic,underline,bullist,numlist',
                'toolbar2' => '',
            ),
            'quicktags'     => array('buttons' => 'strong,em,ul,ol,li'),
        ));

        // ── Scale Labels ─────────────────────────────────────────────────
        echo '<h2 style="margin-top:2em;">' . esc_html__('Scale Labels', 'hl-core') . '</h2>';
        echo '<p class="description">' . esc_html__('Define named scales that sections can reference. Likert scales use ordered labels; numeric scales use low/high anchors.', 'hl-core') . '</p>';
        echo '<div id="hl-te-scales-container">';

        $scale_index = 0;
        foreach ($scale_labels as $scale_key => $scale_data) {
            $this->render_teacher_scale_panel($scale_index, $scale_key, $scale_data);
            $scale_index++;
        }

        echo '</div>';
        echo '<p><button type="button" class="button" id="hl-te-add-scale">' . esc_html__('+ Add Scale', 'hl-core') . '</button></p>';

        // ── Sections ─────────────────────────────────────────────────────
        echo '<h2 style="margin-top:2em;">' . esc_html__('Sections', 'hl-core') . '</h2>';
        echo '<p class="description">' . esc_html__('Each section groups related items. Click a section header to expand/collapse.', 'hl-core') . '</p>';
        echo '<div id="hl-te-sections-container">';

        $scale_keys = array_keys($scale_labels);
        foreach ($sections as $idx => $section) {
            $this->render_teacher_section_panel($idx, $section, $scale_keys);
        }

        echo '</div>';
        echo '<p><button type="button" class="button" id="hl-te-add-section">' . esc_html__('+ Add Section', 'hl-core') . '</button></p>';

        // Display Styles panel.
        $styles = array();
        if ($is_edit && !empty($instrument->styles_json)) {
            $styles = json_decode($instrument->styles_json, true) ?: array();
        }
        $this->render_styles_panel($styles, array(
            'instructions'   => __('Instructions', 'hl-core'),
            'section_title'  => __('Section Titles', 'hl-core'),
            'section_desc'   => __('Section Descriptions', 'hl-core'),
            'item'           => __('Items', 'hl-core'),
            'scale_label'    => __('Scale Labels', 'hl-core'),
        ));

        // Submit
        echo '<div style="display: flex; align-items: center; gap: 12px;">';
        submit_button($is_edit ? __('Update Instrument', 'hl-core') : __('Create Instrument', 'hl-core'), 'primary', 'submit', false);
        if ($is_edit) {
            $preview_url = admin_url('admin.php?page=hl-instruments&tab=teacher&action=preview&id=' . $instrument->instrument_id);
            echo ' <a href="' . esc_url($preview_url) . '" class="button button-secondary" target="_blank">' . esc_html__('Preview', 'hl-core') . '</a>';
        }
        echo '</div>';

        echo '</form>';

        // Render the inline JS data for the editor
        $this->render_teacher_editor_data(count($sections), $scale_index, $scale_keys);
    }

    /**
     * Render a single scale label panel.
     */
    private function render_teacher_scale_panel($index, $scale_key, $scale_data) {
        $is_object = is_array($scale_data) && isset($scale_data['low']);
        $prefix    = 'scale_labels[' . esc_attr($scale_key) . ']';
        ?>
        <div class="hl-te-scale-panel" data-scale-index="<?php echo esc_attr($index); ?>">
            <div class="hl-te-panel-header">
                <strong><?php esc_html_e('Scale:', 'hl-core'); ?></strong>
                <code class="hl-te-scale-key-display"><?php echo esc_html($scale_key); ?></code>
                <span class="hl-te-panel-actions">
                    <button type="button" class="button-link hl-te-remove-scale"><?php esc_html_e('Remove', 'hl-core'); ?></button>
                </span>
            </div>
            <div class="hl-te-panel-body">
                <p>
                    <label><?php esc_html_e('Scale Key:', 'hl-core'); ?></label>
                    <input type="text" class="regular-text hl-te-scale-key-input" value="<?php echo esc_attr($scale_key); ?>" data-old-key="<?php echo esc_attr($scale_key); ?>" />
                </p>
                <p>
                    <label><?php esc_html_e('Type:', 'hl-core'); ?></label>
                    <select class="hl-te-scale-type-select">
                        <option value="array" <?php selected(!$is_object); ?>><?php esc_html_e('Likert (ordered labels)', 'hl-core'); ?></option>
                        <option value="object" <?php selected($is_object); ?>><?php esc_html_e('Numeric (low/high anchors)', 'hl-core'); ?></option>
                    </select>
                    <input type="hidden" name="<?php echo esc_attr($prefix); ?>[type]" value="<?php echo $is_object ? 'object' : 'array'; ?>" class="hl-te-scale-type-hidden" />
                </p>
                <div class="hl-te-scale-array-fields" <?php echo $is_object ? 'style="display:none;"' : ''; ?>>
                    <label><?php esc_html_e('Labels (in order):', 'hl-core'); ?></label>
                    <div class="hl-te-scale-labels-list">
                        <?php if (!$is_object && is_array($scale_data)) : ?>
                            <?php foreach ($scale_data as $label) : ?>
                                <div class="hl-te-scale-label-row">
                                    <input type="text" name="<?php echo esc_attr($prefix); ?>[labels][]" value="<?php echo esc_attr($label); ?>" class="regular-text" />
                                    <button type="button" class="button-link hl-te-remove-label">&times;</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="button button-small hl-te-add-label"><?php esc_html_e('+ Add Label', 'hl-core'); ?></button>
                </div>
                <div class="hl-te-scale-object-fields" <?php echo $is_object ? '' : 'style="display:none;"'; ?>>
                    <p>
                        <label><?php esc_html_e('Low anchor:', 'hl-core'); ?></label>
                        <input type="text" name="<?php echo esc_attr($prefix); ?>[low]" value="<?php echo esc_attr($is_object ? $scale_data['low'] : ''); ?>" class="regular-text" />
                    </p>
                    <p>
                        <label><?php esc_html_e('High anchor:', 'hl-core'); ?></label>
                        <input type="text" name="<?php echo esc_attr($prefix); ?>[high]" value="<?php echo esc_attr($is_object ? $scale_data['high'] : ''); ?>" class="regular-text" />
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single section accordion panel.
     */
    private function render_teacher_section_panel($index, $section, $scale_keys) {
        $section_key = isset($section['section_key']) ? $section['section_key'] : '';
        $title       = isset($section['title']) ? $section['title'] : '';
        $description = isset($section['description']) ? $section['description'] : '';
        $type        = isset($section['type']) ? $section['type'] : 'likert';
        $scale_key   = isset($section['scale_key']) ? $section['scale_key'] : '';
        $items       = isset($section['items']) ? $section['items'] : array();
        $prefix      = 'sections[' . $index . ']';

        ?>
        <div class="hl-te-section-panel" data-section-index="<?php echo esc_attr($index); ?>">
            <div class="hl-te-section-header" role="button" tabindex="0">
                <span class="hl-te-section-toggle dashicons dashicons-arrow-down-alt2"></span>
                <span class="hl-te-section-header-title">
                    <?php echo esc_html($title ?: __('(Untitled Section)', 'hl-core')); ?>
                </span>
                <span class="hl-te-section-header-meta">
                    <?php echo esc_html(sprintf(__('%d items', 'hl-core'), count($items))); ?>
                </span>
                <span class="hl-te-panel-actions">
                    <button type="button" class="button-link hl-te-remove-section"><?php esc_html_e('Remove', 'hl-core'); ?></button>
                </span>
            </div>
            <div class="hl-te-section-body">
                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e('Section Key', 'hl-core'); ?></label></th>
                        <td><input type="text" name="<?php echo esc_attr($prefix); ?>[section_key]" value="<?php echo esc_attr($section_key); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Title', 'hl-core'); ?></label></th>
                        <td><input type="text" name="<?php echo esc_attr($prefix); ?>[title]" value="<?php echo esc_attr($title); ?>" class="regular-text hl-te-section-title-input" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Description', 'hl-core'); ?></label></th>
                        <td>
                            <div class="hl-te-richtext-wrap">
                                <div class="hl-te-richtext-toolbar">
                                    <button type="button" class="hl-te-rt-btn" data-command="bold" title="Bold"><strong>B</strong></button>
                                    <button type="button" class="hl-te-rt-btn" data-command="italic" title="Italic"><em>I</em></button>
                                    <button type="button" class="hl-te-rt-btn" data-command="underline" title="Underline"><u>U</u></button>
                                </div>
                                <div class="hl-te-richtext-editor" contenteditable="true"><?php echo wp_kses_post($description); ?></div>
                                <textarea name="<?php echo esc_attr($prefix); ?>[description]" class="hl-te-richtext-hidden" style="display:none;"><?php echo esc_textarea($description); ?></textarea>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Type', 'hl-core'); ?></label></th>
                        <td>
                            <select name="<?php echo esc_attr($prefix); ?>[type]" class="hl-te-section-type-select">
                                <option value="likert" <?php selected($type, 'likert'); ?>><?php esc_html_e('Likert', 'hl-core'); ?></option>
                                <option value="scale" <?php selected($type, 'scale'); ?>><?php esc_html_e('Scale (0-10)', 'hl-core'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Scale Key', 'hl-core'); ?></label></th>
                        <td>
                            <select name="<?php echo esc_attr($prefix); ?>[scale_key]" class="hl-te-scale-key-select">
                                <option value=""><?php esc_html_e('-- Select Scale --', 'hl-core'); ?></option>
                                <?php foreach ($scale_keys as $sk) : ?>
                                    <option value="<?php echo esc_attr($sk); ?>" <?php selected($scale_key, $sk); ?>><?php echo esc_html($sk); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <h4><?php esc_html_e('Items', 'hl-core'); ?></h4>
                <table class="widefat hl-te-items-table">
                    <thead>
                        <tr>
                            <th style="width:180px;"><?php esc_html_e('Item Key', 'hl-core'); ?></th>
                            <th><?php esc_html_e('Item Text', 'hl-core'); ?></th>
                            <th style="width:80px;"><?php esc_html_e('Actions', 'hl-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="hl-te-items-body">
                        <?php foreach ($items as $item_idx => $item) : ?>
                            <?php $this->render_teacher_item_row($index, $item_idx, $item); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button button-small hl-te-add-item"><?php esc_html_e('+ Add Item', 'hl-core'); ?></button></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single item row inside a section.
     */
    private function render_teacher_item_row($section_index, $item_index, $item) {
        $key          = isset($item['key']) ? $item['key'] : '';
        $text         = isset($item['text']) ? $item['text'] : '';
        $left_anchor  = isset($item['left_anchor']) ? $item['left_anchor'] : '';
        $right_anchor = isset($item['right_anchor']) ? $item['right_anchor'] : '';
        $name_prefix  = 'sections[' . $section_index . '][items][' . $item_index . ']';
        ?>
        <tr class="hl-te-item-row">
            <td>
                <input type="text" name="<?php echo esc_attr($name_prefix); ?>[key]" value="<?php echo esc_attr($key); ?>" class="widefat" />
            </td>
            <td>
                <div class="hl-te-richtext-wrap hl-te-richtext-inline">
                    <div class="hl-te-richtext-toolbar hl-te-rt-mini">
                        <button type="button" class="hl-te-rt-btn" data-command="bold" title="Bold"><strong>B</strong></button>
                        <button type="button" class="hl-te-rt-btn" data-command="italic" title="Italic"><em>I</em></button>
                        <button type="button" class="hl-te-rt-btn" data-command="underline" title="Underline"><u>U</u></button>
                    </div>
                    <div class="hl-te-richtext-editor" contenteditable="true"><?php echo wp_kses_post($text); ?></div>
                    <textarea name="<?php echo esc_attr($name_prefix); ?>[text]" class="hl-te-richtext-hidden" style="display:none;"><?php echo esc_textarea($text); ?></textarea>
                </div>
                <div class="hl-te-anchor-fields" style="display:none;">
                    <label class="hl-te-anchor-label"><?php esc_html_e('0 =', 'hl-core'); ?>
                        <input type="text" name="<?php echo esc_attr($name_prefix); ?>[left_anchor]" value="<?php echo esc_attr($left_anchor); ?>" placeholder="<?php esc_attr_e('e.g. Not at all', 'hl-core'); ?>" class="regular-text" />
                    </label>
                    <label class="hl-te-anchor-label"><?php esc_html_e('10 =', 'hl-core'); ?>
                        <input type="text" name="<?php echo esc_attr($name_prefix); ?>[right_anchor]" value="<?php echo esc_attr($right_anchor); ?>" placeholder="<?php esc_attr_e('e.g. Very', 'hl-core'); ?>" class="regular-text" />
                    </label>
                </div>
            </td>
            <td><button type="button" class="button-link button-link-delete hl-te-remove-item"><?php esc_html_e('Remove', 'hl-core'); ?></button></td>
        </tr>
        <?php
    }

    /**
     * Render inline JS data for the teacher editor (initial counts + scale keys).
     */
    private function render_teacher_editor_data($section_count, $scale_count, $scale_keys) {
        ?>
        <script>
        var hlTeacherEditorData = {
            sectionCount: <?php echo intval($section_count); ?>,
            scaleCount: <?php echo intval($scale_count); ?>,
            scaleKeys: <?php echo wp_json_encode($scale_keys); ?>,
            i18n: {
                removeSection: <?php echo wp_json_encode(__('Remove this entire section and all its items?', 'hl-core')); ?>,
                removeItem: <?php echo wp_json_encode(__('Remove this item?', 'hl-core')); ?>,
                removeScale: <?php echo wp_json_encode(__('Remove this scale? Sections referencing it will need to be updated.', 'hl-core')); ?>,
                untitledSection: <?php echo wp_json_encode(__('(Untitled Section)', 'hl-core')); ?>,
                items: <?php echo wp_json_encode(__('items', 'hl-core')); ?>,
                selectScale: <?php echo wp_json_encode(__('-- Select Scale --', 'hl-core')); ?>,
                scaleLabel: <?php echo wp_json_encode(__('Scale:', 'hl-core')); ?>,
                scaleKeyLabel: <?php echo wp_json_encode(__('Scale Key:', 'hl-core')); ?>,
                typeLabel: <?php echo wp_json_encode(__('Type:', 'hl-core')); ?>,
                likertType: <?php echo wp_json_encode(__('Likert (ordered labels)', 'hl-core')); ?>,
                numericType: <?php echo wp_json_encode(__('Numeric (low/high anchors)', 'hl-core')); ?>,
                lowAnchor: <?php echo wp_json_encode(__('Low anchor:', 'hl-core')); ?>,
                highAnchor: <?php echo wp_json_encode(__('High anchor:', 'hl-core')); ?>,
                labelsInOrder: <?php echo wp_json_encode(__('Labels (in order):', 'hl-core')); ?>,
                addLabel: <?php echo wp_json_encode(__('+ Add Label', 'hl-core')); ?>,
                remove: <?php echo wp_json_encode(__('Remove', 'hl-core')); ?>,
                sectionKey: <?php echo wp_json_encode(__('Section Key', 'hl-core')); ?>,
                titleLabel: <?php echo wp_json_encode(__('Title', 'hl-core')); ?>,
                descriptionLabel: <?php echo wp_json_encode(__('Description', 'hl-core')); ?>,
                typeFieldLabel: <?php echo wp_json_encode(__('Type', 'hl-core')); ?>,
                scaleKeyField: <?php echo wp_json_encode(__('Scale Key', 'hl-core')); ?>,
                likert: <?php echo wp_json_encode(__('Likert', 'hl-core')); ?>,
                scaleType: <?php echo wp_json_encode(__('Scale (0-10)', 'hl-core')); ?>,
                itemsLabel: <?php echo wp_json_encode(__('Items', 'hl-core')); ?>,
                itemKey: <?php echo wp_json_encode(__('Item Key', 'hl-core')); ?>,
                itemText: <?php echo wp_json_encode(__('Item Text', 'hl-core')); ?>,
                actions: <?php echo wp_json_encode(__('Actions', 'hl-core')); ?>,
                addItem: <?php echo wp_json_encode(__('+ Add Item', 'hl-core')); ?>
            }
        };
        </script>
        <?php
    }

    // =====================================================================
    // Preview Renderers
    // =====================================================================

    /**
     * Render a preview of a child assessment instrument using the frontend renderer.
     *
     * @param object $instrument The instrument row from DB.
     */
    private function render_child_preview($instrument) {
        $back_url = admin_url('admin.php?page=hl-instruments&action=edit&id=' . $instrument->instrument_id);

        echo '<div style="margin-bottom: 15px;">';
        echo '<a href="' . esc_url($back_url) . '" class="button">&larr; ' . esc_html__('Back to Editor', 'hl-core') . '</a>';
        echo '</div>';

        echo '<h1>' . esc_html(sprintf(__('Preview: %s', 'hl-core'), $instrument->name)) . '</h1>';
        echo '<div class="notice notice-info"><p>' . esc_html__('This is a read-only preview showing how the instrument will appear to teachers. Sample children are used for demonstration.', 'hl-core') . '</p></div>';

        // Build sample children for the preview matrix.
        $sample_children = array();
        for ($i = 1; $i <= 5; $i++) {
            $sample_children[] = (object) array(
                'child_id'           => $i,
                'first_name'         => 'Sample',
                'last_name'          => 'Child ' . $i,
                'child_display_code' => 'SC-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'dob'                => '2022-01-01',
            );
        }

        $instance_context = array(
            'display_name'    => 'Jane Doe (Sample Teacher)',
            'school_name'     => 'Sample School',
            'classroom_name'  => 'Sample Classroom',
            'phase'           => 'pre',
            'track_name'      => 'Sample Track',
        );

        // Enqueue frontend CSS for the preview.
        wp_enqueue_style('hl-frontend', HL_CORE_ASSETS_URL . 'css/frontend.css', array(), HL_CORE_VERSION);

        $renderer = new HL_Instrument_Renderer($instrument, $sample_children, 0, array(), $instance_context);

        echo '<div class="hl-instrument-preview" style="max-width: 1000px; pointer-events: none; opacity: 0.95;">';
        echo $renderer->render();
        echo '</div>';
    }

    /**
     * Render a preview of a teacher assessment instrument using the frontend renderer.
     *
     * @param object $instrument_row The raw DB row for the teacher instrument.
     */
    private function render_teacher_preview($instrument_row) {
        $back_url = admin_url('admin.php?page=hl-instruments&tab=teacher&action=edit&id=' . $instrument_row->instrument_id);
        $phase    = isset($_GET['phase']) && $_GET['phase'] === 'post' ? 'post' : 'pre';

        echo '<div style="margin-bottom: 15px; display: flex; align-items: center; gap: 12px;">';
        echo '<a href="' . esc_url($back_url) . '" class="button">&larr; ' . esc_html__('Back to Editor', 'hl-core') . '</a>';

        // Phase toggle links.
        $pre_url  = admin_url('admin.php?page=hl-instruments&tab=teacher&action=preview&id=' . $instrument_row->instrument_id . '&phase=pre');
        $post_url = admin_url('admin.php?page=hl-instruments&tab=teacher&action=preview&id=' . $instrument_row->instrument_id . '&phase=post');
        echo '<span style="margin-left: 8px;">' . esc_html__('Phase:', 'hl-core') . ' ';
        echo ($phase === 'pre')
            ? '<strong>' . esc_html__('Pre', 'hl-core') . '</strong>'
            : '<a href="' . esc_url($pre_url) . '">' . esc_html__('Pre', 'hl-core') . '</a>';
        echo ' | ';
        echo ($phase === 'post')
            ? '<strong>' . esc_html__('Post', 'hl-core') . '</strong>'
            : '<a href="' . esc_url($post_url) . '">' . esc_html__('Post', 'hl-core') . '</a>';
        echo '</span>';
        echo '</div>';

        echo '<h1>' . esc_html(sprintf(__('Preview: %s (%s)', 'hl-core'), $instrument_row->instrument_name, ucfirst($phase))) . '</h1>';
        echo '<div class="notice notice-info"><p>' . esc_html__('This is a read-only preview showing how the instrument will appear to teachers.', 'hl-core') . '</p></div>';

        // Build the domain model from the DB row.
        $instrument = new HL_Teacher_Assessment_Instrument((array) $instrument_row);
        $fake_instance = (object) array('instance_id' => 0);

        // For POST preview, generate fake PRE responses so the "Before" column is populated.
        $pre_responses = array();
        if ($phase === 'post') {
            foreach ($instrument->get_sections() as $section) {
                $section_key = isset($section['section_key']) ? $section['section_key'] : '';
                if (!empty($section['items']) && is_array($section['items'])) {
                    foreach ($section['items'] as $item) {
                        $item_key = isset($item['key']) ? $item['key'] : '';
                        if ($item_key) {
                            $pre_responses[$section_key][$item_key] = '3';
                        }
                    }
                }
            }
        }

        // Enqueue frontend CSS for the preview.
        wp_enqueue_style('hl-frontend', HL_CORE_ASSETS_URL . 'css/frontend.css', array(), HL_CORE_VERSION);

        $renderer = new HL_Teacher_Assessment_Renderer(
            $instrument,
            $fake_instance,
            $phase,
            array(),
            $pre_responses,
            true,
            array(
                'show_instrument_name' => true,
                'show_program_name'    => false,
            )
        );

        echo '<div class="hl-instrument-preview" style="max-width: 900px;">';
        echo $renderer->render();
        echo '</div>';
    }

    // =====================================================================
    // Display Styles — shared panel + POST builder
    // =====================================================================

    /**
     * Available font-size options for the dropdown.
     */
    private static $font_size_options = array(
        ''     => 'Default',
        '12px' => '12px',
        '13px' => '13px',
        '14px' => '14px',
        '15px' => '15px',
        '16px' => '16px',
        '17px' => '17px',
        '18px' => '18px',
        '20px' => '20px',
        '22px' => '22px',
        '24px' => '24px',
    );

    /**
     * Render the "Display Styles" collapsible panel.
     *
     * @param array $styles  Current styles_json values (or empty).
     * @param array $elements Associative array of element_key => label for the rows to show.
     */
    private function render_styles_panel( $styles, $elements ) {
        ?>
        <div class="hl-styles-panel" style="margin-top: 2em;">
            <h2 style="cursor: pointer; user-select: none;" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? '' : 'none'; this.querySelector('span').textContent = this.nextElementSibling.style.display === 'none' ? '&#9654;' : '&#9660;';">
                <span>&#9654;</span> <?php esc_html_e( 'Display Styles', 'hl-core' ); ?>
            </h2>
            <div style="display: none;">
                <p class="description"><?php esc_html_e( 'Customize font sizes and colors for the assessment form. Leave blank to use defaults.', 'hl-core' ); ?></p>
                <table class="form-table">
                    <?php foreach ( $elements as $key => $label ) :
                        $size_key  = $key . '_font_size';
                        $color_key = $key . '_color';
                        $cur_size  = isset( $styles[ $size_key ] ) ? $styles[ $size_key ] : '';
                        $cur_color = isset( $styles[ $color_key ] ) ? $styles[ $color_key ] : '';
                    ?>
                        <tr>
                            <th scope="row"><?php echo esc_html( $label ); ?></th>
                            <td>
                                <label style="margin-right: 16px;">
                                    <?php esc_html_e( 'Font size:', 'hl-core' ); ?>
                                    <select name="styles[<?php echo esc_attr( $size_key ); ?>]">
                                        <?php foreach ( self::$font_size_options as $val => $text ) : ?>
                                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $cur_size, $val ); ?>>
                                                <?php echo esc_html( $text ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <?php esc_html_e( 'Color:', 'hl-core' ); ?>
                                    <input type="color" name="styles[<?php echo esc_attr( $color_key ); ?>]"
                                           value="<?php echo esc_attr( $cur_color ?: '#000000' ); ?>"
                                           style="vertical-align: middle; width: 40px; height: 30px; padding: 0 2px;"
                                           oninput="var cb=this.parentNode.querySelector('input[type=checkbox]');if(cb)cb.checked=false;" />
                                    <label style="margin-left: 4px;">
                                        <input type="checkbox" name="styles_clear[<?php echo esc_attr( $color_key ); ?>]" value="1"
                                            <?php checked( empty( $cur_color ) ); ?>
                                            onchange="if(this.checked){var ci=this.closest('label').previousElementSibling;if(ci&&ci.type==='color')ci.value='#000000';}" />
                                        <?php esc_html_e( 'Default', 'hl-core' ); ?>
                                    </label>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Build styles_json value from POST data.
     *
     * @return string|null JSON string or null if all defaults.
     */
    private function build_styles_from_post() {
        if ( empty( $_POST['styles'] ) || ! is_array( $_POST['styles'] ) ) {
            return null;
        }

        $styles   = array();
        $clears   = isset( $_POST['styles_clear'] ) && is_array( $_POST['styles_clear'] ) ? $_POST['styles_clear'] : array();

        foreach ( $_POST['styles'] as $key => $value ) {
            $key   = sanitize_text_field( $key );
            $value = sanitize_text_field( $value );

            // If the "Default" checkbox is checked for a color field, skip it.
            if ( isset( $clears[ $key ] ) ) {
                continue;
            }

            if ( $value !== '' ) {
                $styles[ $key ] = $value;
            }
        }

        return ! empty( $styles ) ? wp_json_encode( $styles ) : null;
    }
}
