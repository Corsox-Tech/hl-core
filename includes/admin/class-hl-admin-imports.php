<?php if (!defined('ABSPATH')) exit;

/**
 * Admin Imports — Cycle-Scoped Import Wizard
 *
 * Renders inside the Cycle Editor as an "Import" tab.
 * Supports 2 import types: participants and children.
 *
 * @package HL_Core
 */
class HL_Admin_Imports {

    /** @var HL_Admin_Imports|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_hl_import_upload', array($this, 'ajax_upload'));
        add_action('wp_ajax_hl_import_commit', array($this, 'ajax_commit'));
        add_action('wp_ajax_hl_import_error_report', array($this, 'ajax_error_report'));
    }

    /**
     * Render the import tab inside the Cycle Editor.
     *
     * @param object $cycle Cycle row object with cycle_id, cycle_name, etc.
     */
    public function render_cycle_import_tab($cycle) {
        $cycle_id = (int) $cycle->cycle_id;
        $import_service = new HL_Import_Service();
        $runs = $import_service->get_runs($cycle_id);

        // Load helper data scoped to this cycle's Partnership
        $helpers = $this->load_cycle_helpers($cycle_id);
        ?>
        <div class="hl-import-wizard-wrap">

            <!-- Step Indicator -->
            <div class="hl-import-steps">
                <div class="hl-import-step active" data-step="1">
                    <span class="step-number">1</span>
                    <span class="step-label"><?php esc_html_e('Upload', 'hl-core'); ?></span>
                </div>
                <div class="hl-import-step-divider"></div>
                <div class="hl-import-step" data-step="2">
                    <span class="step-number">2</span>
                    <span class="step-label"><?php esc_html_e('Preview & Select', 'hl-core'); ?></span>
                </div>
                <div class="hl-import-step-divider"></div>
                <div class="hl-import-step" data-step="3">
                    <span class="step-number">3</span>
                    <span class="step-label"><?php esc_html_e('Results', 'hl-core'); ?></span>
                </div>
            </div>

            <!-- Spinner Overlay -->
            <div class="hl-import-spinner" style="display:none;">
                <div class="hl-import-spinner-inner">
                    <span class="spinner is-active"></span>
                    <p class="hl-import-spinner-msg"><?php esc_html_e('Processing...', 'hl-core'); ?></p>
                </div>
            </div>

            <!-- Notice Area -->
            <div class="hl-import-notices"></div>

            <!-- Import Guide (collapsible) -->
            <details class="hl-import-guide" style="margin-bottom:20px; background:#f0f6fc; border:1px solid #c3d9ed; border-radius:4px; padding:0;">
                <summary style="padding:12px 16px; cursor:pointer; font-weight:600; color:#1d4ed8; font-size:14px;">
                    <?php esc_html_e('Import Guide & Tips', 'hl-core'); ?>
                </summary>
                <div style="padding:4px 16px 16px; font-size:13px; line-height:1.6;">
                    <h4 style="margin:8px 0 4px;"><?php esc_html_e('Before You Import', 'hl-core'); ?></h4>
                    <ul style="margin:0 0 12px 18px;">
                        <li><?php esc_html_e('Schools must already exist and be linked to this Cycle (use the Schools tab).', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Import Participants first, then Children. Classrooms are created automatically from the Participants import.', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Pathways are auto-assigned based on role and course completion history. You can override by adding a "pathway" column.', 'hl-core'); ?></li>
                    </ul>

                    <h4 style="margin:8px 0 4px;"><?php esc_html_e('Participants CSV Columns', 'hl-core'); ?></h4>
                    <table class="widefat" style="font-size:12px; max-width:700px;">
                        <thead><tr><th><?php esc_html_e('Column', 'hl-core'); ?></th><th><?php esc_html_e('Required', 'hl-core'); ?></th><th><?php esc_html_e('Notes', 'hl-core'); ?></th></tr></thead>
                        <tbody>
                            <tr><td><code>email</code></td><td><strong><?php esc_html_e('Yes', 'hl-core'); ?></strong></td><td><?php esc_html_e('Creates WordPress account if new', 'hl-core'); ?></td></tr>
                            <tr><td><code>role</code></td><td><strong><?php esc_html_e('Yes', 'hl-core'); ?></strong></td><td><?php esc_html_e('Teacher, Mentor, School Leader, or District Leader', 'hl-core'); ?></td></tr>
                            <tr><td><code>school</code></td><td><strong><?php esc_html_e('Yes*', 'hl-core'); ?></strong></td><td><?php esc_html_e('Must match an existing school. *Optional for District Leaders.', 'hl-core'); ?></td></tr>
                            <tr><td><code>first_name</code>, <code>last_name</code></td><td><?php esc_html_e('No', 'hl-core'); ?></td><td><?php esc_html_e('Warning if missing. Existing users keep their names.', 'hl-core'); ?></td></tr>
                            <tr><td><code>classroom</code></td><td><?php esc_html_e('No', 'hl-core'); ?></td><td><?php esc_html_e('Semicolon-separated (e.g., "Room A; Room B"). Auto-creates classrooms.', 'hl-core'); ?></td></tr>
                            <tr><td><code>team</code></td><td><?php esc_html_e('No', 'hl-core'); ?></td><td><?php esc_html_e('Auto-creates team if it doesn\'t exist. Assigns as mentor or member based on role.', 'hl-core'); ?></td></tr>
                            <tr><td><code>assigned_coach</code></td><td><?php esc_html_e('No', 'hl-core'); ?></td><td><?php esc_html_e('Coach email. Creates coach assignment for Mentors.', 'hl-core'); ?></td></tr>
                            <tr><td><code>pathway</code></td><td><?php esc_html_e('No', 'hl-core'); ?></td><td><?php esc_html_e('Overrides auto-routing. Use pathway name or code.', 'hl-core'); ?></td></tr>
                            <tr><td><code>age_group</code></td><td><?php esc_html_e('No', 'hl-core'); ?></td><td><?php esc_html_e('infant, toddler, preschool, k2, or mixed. Applied to new classrooms.', 'hl-core'); ?></td></tr>
                        </tbody>
                    </table>

                    <h4 style="margin:12px 0 4px;"><?php esc_html_e('Children CSV Columns', 'hl-core'); ?></h4>
                    <table class="widefat" style="font-size:12px; max-width:700px;">
                        <thead><tr><th><?php esc_html_e('Column', 'hl-core'); ?></th><th><?php esc_html_e('Required', 'hl-core'); ?></th><th><?php esc_html_e('Notes', 'hl-core'); ?></th></tr></thead>
                        <tbody>
                            <tr><td><code>first_name</code>, <code>last_name</code></td><td><strong><?php esc_html_e('Yes', 'hl-core'); ?></strong></td><td><?php esc_html_e('At least one required.', 'hl-core'); ?></td></tr>
                            <tr><td><code>classroom</code></td><td><strong><?php esc_html_e('Yes', 'hl-core'); ?></strong></td><td><?php esc_html_e('Must match a classroom created in the Participants import.', 'hl-core'); ?></td></tr>
                            <tr><td><code>school</code></td><td><?php esc_html_e('No', 'hl-core'); ?></td><td><?php esc_html_e('Inferred from classroom if unambiguous.', 'hl-core'); ?></td></tr>
                            <tr><td><code>date_of_birth</code></td><td><?php esc_html_e('No', 'hl-core'); ?></td><td><?php esc_html_e('Any standard date format.', 'hl-core'); ?></td></tr>
                            <tr><td><code>internal_child_id</code></td><td><?php esc_html_e('No', 'hl-core'); ?></td><td><?php esc_html_e('External system ID. Helps with deduplication on re-import.', 'hl-core'); ?></td></tr>
                            <tr><td><code>ethnicity</code></td><td><?php esc_html_e('No', 'hl-core'); ?></td><td></td></tr>
                        </tbody>
                    </table>

                    <h4 style="margin:12px 0 4px;"><?php esc_html_e('Tips', 'hl-core'); ?></h4>
                    <ul style="margin:0 0 0 18px;">
                        <li><?php esc_html_e('If errors are found, nothing is imported. Fix the CSV and re-upload.', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Re-importing the same CSV is safe — existing participants will show as SKIP.', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Float teachers (no fixed classroom) can leave the classroom column empty.', 'hl-core'); ?></li>
                        <li><?php esc_html_e('Multiple classrooms use semicolons, not commas (e.g., "Room A; Room B").', 'hl-core'); ?></li>
                    </ul>
                </div>
            </details>

            <!-- Step 1: Upload -->
            <div class="hl-import-panel" id="hl-import-step-1">
                <h2><?php esc_html_e('Import Data', 'hl-core'); ?></h2>

                <!-- Hidden cycle_id for JS -->
                <input type="hidden" id="hl-import-cycle-id" value="<?php echo esc_attr($cycle_id); ?>" />

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="hl-import-type"><?php esc_html_e('Import Type', 'hl-core'); ?></label></th>
                        <td>
                            <select id="hl-import-type" required>
                                <option value="participants"><?php esc_html_e('Participants', 'hl-core'); ?></option>
                                <option value="children"><?php esc_html_e('Children', 'hl-core'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hl-import-file"><?php esc_html_e('CSV File', 'hl-core'); ?></label></th>
                        <td>
                            <input type="file" id="hl-import-file" accept=".csv" required />
                            <p class="description"><?php esc_html_e('Max 2MB. Maximum 5,000 rows.', 'hl-core'); ?></p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" class="button button-primary" id="hl-import-upload-btn">
                        <?php esc_html_e('Upload & Preview', 'hl-core'); ?>
                    </button>
                </p>

                <!-- Column hints per type -->
                <div id="hl-import-column-hints">
                    <p class="description" data-type="participants">
                        <?php esc_html_e('Required: email, role, school. Optional: first_name, last_name, classroom (semicolon-separated), age_group, team, assigned_mentor, assigned_coach, pathway, is_primary_teacher.', 'hl-core'); ?>
                    </p>
                    <p class="description" data-type="children" style="display:none;">
                        <?php esc_html_e('Required: first_name, last_name, classroom. Optional: school, date_of_birth, internal_child_id, ethnicity.', 'hl-core'); ?>
                    </p>
                </div>

                <!-- Helper Reference Panel -->
                <?php $this->render_helper_panel($helpers); ?>
            </div>

            <!-- Step 2: Preview & Select -->
            <div class="hl-import-panel" id="hl-import-step-2" style="display:none;">
                <h2><?php esc_html_e('Preview & Select Rows', 'hl-core'); ?></h2>
                <div class="hl-import-summary" id="hl-import-summary"></div>
                <div class="hl-import-bulk-bar">
                    <label><input type="checkbox" id="hl-import-select-all" /> <?php esc_html_e('Select All', 'hl-core'); ?></label>
                    <span class="hl-import-bulk-actions">
                        <button type="button" class="button button-small" data-bulk="create"><?php esc_html_e('Select All CREATE', 'hl-core'); ?></button>
                        <button type="button" class="button button-small" data-bulk="update"><?php esc_html_e('Select All UPDATE', 'hl-core'); ?></button>
                        <button type="button" class="button button-small" data-bulk="none"><?php esc_html_e('Deselect All', 'hl-core'); ?></button>
                    </span>
                    <span class="hl-import-selection-count" id="hl-import-selection-count"></span>
                </div>
                <div class="hl-import-table-wrap">
                    <table class="widefat striped" id="hl-import-preview-table">
                        <thead><tr></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p class="hl-import-actions">
                    <button type="button" class="button button-primary" id="hl-import-commit-btn">
                        <?php esc_html_e('Commit Import', 'hl-core'); ?>
                    </button>
                    <button type="button" class="button" id="hl-import-cancel-btn">
                        <?php esc_html_e('Cancel', 'hl-core'); ?>
                    </button>
                </p>
            </div>

            <!-- Step 3: Results -->
            <div class="hl-import-panel" id="hl-import-step-3" style="display:none;">
                <h2><?php esc_html_e('Import Results', 'hl-core'); ?></h2>
                <div class="hl-import-results" id="hl-import-results"></div>
                <div class="hl-import-error-list" id="hl-import-error-list"></div>
                <p class="hl-import-actions">
                    <button type="button" class="button" id="hl-import-download-errors-btn" style="display:none;">
                        <?php esc_html_e('Download Error Report', 'hl-core'); ?>
                    </button>
                    <button type="button" class="button button-primary" id="hl-import-new-btn">
                        <?php esc_html_e('New Import', 'hl-core'); ?>
                    </button>
                </p>
            </div>

            <!-- Import History for this Cycle -->
            <hr />
            <h3><?php esc_html_e('Import History', 'hl-core'); ?></h3>
            <?php $this->render_history_table($runs); ?>
        </div>
        <?php
    }

    /**
     * Load helper reference data scoped to this cycle's Partnership.
     *
     * @param int $cycle_id
     * @return array
     */
    private function load_cycle_helpers($cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $import_service = new HL_Import_Service();
        $partnership_schools = $import_service->load_partnership_schools($cycle_id);
        $schools = array();
        foreach ($partnership_schools as $s) {
            $schools[] = array(
                'orgunit_id'  => $s->orgunit_id,
                'name'        => $s->name,
                'orgunit_code' => $s->orgunit_code,
            );
        }

        // Pathways for this cycle
        $pathways = $wpdb->get_results($wpdb->prepare(
            "SELECT pathway_id, pathway_name, pathway_code, target_roles
             FROM {$prefix}hl_pathway
             WHERE cycle_id = %d AND active_status = 1
             ORDER BY pathway_name ASC",
            $cycle_id
        ), ARRAY_A) ?: array();

        // Existing teams for this cycle
        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT t.team_id, t.team_name, o.name AS school_name
             FROM {$prefix}hl_team t
             JOIN {$prefix}hl_orgunit o ON t.school_id = o.orgunit_id
             WHERE t.cycle_id = %d AND t.status = 'active'
             ORDER BY o.name ASC, t.team_name ASC",
            $cycle_id
        ), ARRAY_A) ?: array();

        return array(
            'schools'  => $schools,
            'pathways' => $pathways,
            'teams'    => $teams,
            'roles'    => array('Teacher', 'Mentor', 'School Leader', 'District Leader'),
        );
    }

    /**
     * Render the helper reference panel.
     *
     * @param array $helpers
     */
    private function render_helper_panel($helpers) {
        ?>
        <div class="hl-import-helpers" style="margin-top:20px; padding:15px; background:#f9f9f9; border:1px solid #ddd; border-radius:4px;">
            <h3 style="margin-top:0;"><?php esc_html_e('Reference: Valid Values for This Cycle', 'hl-core'); ?></h3>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div>
                    <strong><?php esc_html_e('Roles', 'hl-core'); ?></strong>
                    <ul style="margin:5px 0;">
                        <?php foreach ($helpers['roles'] as $role) : ?>
                            <li><code><?php echo esc_html($role); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div>
                    <strong><?php esc_html_e('Schools', 'hl-core'); ?></strong>
                    <?php if (empty($helpers['schools'])) : ?>
                        <p class="description"><?php esc_html_e('No schools linked to this cycle. Link schools in the Schools tab first.', 'hl-core'); ?></p>
                    <?php else : ?>
                        <ul style="margin:5px 0;">
                            <?php foreach ($helpers['schools'] as $s) : ?>
                                <li><code><?php echo esc_html($s['name']); ?></code><?php if ($s['orgunit_code']) echo ' (' . esc_html($s['orgunit_code']) . ')'; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div>
                    <strong><?php esc_html_e('Pathways', 'hl-core'); ?></strong>
                    <?php if (empty($helpers['pathways'])) : ?>
                        <p class="description"><?php esc_html_e('No pathways created yet.', 'hl-core'); ?></p>
                    <?php else : ?>
                        <ul style="margin:5px 0;">
                            <?php foreach ($helpers['pathways'] as $p) : ?>
                                <li>
                                    <code><?php echo esc_html($p['pathway_name']); ?></code>
                                    <?php if ($p['pathway_code']) echo ' (' . esc_html($p['pathway_code']) . ')'; ?>
                                    <?php
                                    $roles = json_decode($p['target_roles'], true);
                                    if (is_array($roles) && !empty($roles)) {
                                        echo ' — ' . esc_html(implode(', ', $roles));
                                    }
                                    ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div>
                    <strong><?php esc_html_e('Existing Teams', 'hl-core'); ?></strong>
                    <?php if (empty($helpers['teams'])) : ?>
                        <p class="description"><?php esc_html_e('No teams yet. Teams will be auto-created during import.', 'hl-core'); ?></p>
                    <?php else : ?>
                        <ul style="margin:5px 0;">
                            <?php foreach ($helpers['teams'] as $t) : ?>
                                <li><code><?php echo esc_html($t['team_name']); ?></code> — <?php echo esc_html($t['school_name']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render import history table (unchanged from original, minus cycle column).
     */
    private function render_history_table($runs) {
        if (empty($runs)) {
            echo '<p>' . esc_html__('No import runs yet.', 'hl-core') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Date', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('File', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Type', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Status', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Actor', 'hl-core') . '</th>';
        echo '<th>' . esc_html__('Summary', 'hl-core') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($runs as $run) {
            $status_class = '';
            switch ($run['status']) {
                case 'committed': $status_class = 'active'; break;
                case 'preview':   $status_class = 'draft'; break;
                case 'failed':    $status_class = 'archived'; break;
            }

            $summary_text = '';
            if (!empty($run['results_summary'])) {
                $s = is_string($run['results_summary']) ? json_decode($run['results_summary'], true) : $run['results_summary'];
                if (is_array($s)) {
                    $summary_text = sprintf(
                        '%d created, %d updated, %d skipped, %d errors',
                        isset($s['created_count']) ? $s['created_count'] : 0,
                        isset($s['updated_count']) ? $s['updated_count'] : 0,
                        isset($s['skipped_count']) ? $s['skipped_count'] : 0,
                        isset($s['error_count']) ? $s['error_count'] : 0
                    );
                }
            }

            echo '<tr>';
            echo '<td>' . esc_html($run['run_id']) . '</td>';
            echo '<td>' . esc_html($run['created_at']) . '</td>';
            echo '<td>' . esc_html($run['file_name']) . '</td>';
            echo '<td>' . esc_html($run['import_type']) . '</td>';
            echo '<td><span class="hl-status-badge ' . esc_attr($status_class) . '">' . esc_html($run['status']) . '</span></td>';
            echo '<td>' . esc_html(isset($run['actor_name']) ? $run['actor_name'] : '-') . '</td>';
            echo '<td>' . esc_html($summary_text) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    /**
     * AJAX: Upload and parse CSV, create preview.
     */
    public function ajax_upload() {
        check_ajax_referer('hl_import_upload', 'nonce');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $cycle_id = isset($_POST['cycle_id']) ? absint($_POST['cycle_id']) : 0;
        if (!$cycle_id) {
            wp_send_json_error(array('message' => __('Missing cycle context.', 'hl-core')));
        }

        $import_type = isset($_POST['import_type']) ? sanitize_text_field($_POST['import_type']) : 'participants';
        $valid_types = array('participants', 'children');
        if (!in_array($import_type, $valid_types, true)) {
            wp_send_json_error(array('message' => __('Invalid import type.', 'hl-core')));
        }

        // Verify cycle is not archived
        global $wpdb;
        $cycle_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
            $cycle_id
        ));
        if (!$cycle_status) {
            wp_send_json_error(array('message' => __('Cycle not found.', 'hl-core')));
        }
        if ($cycle_status === 'archived') {
            wp_send_json_error(array('message' => __('Cannot import into an archived cycle.', 'hl-core')));
        }

        // Validate file upload
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => __('No file uploaded or upload error.', 'hl-core')));
        }

        $file = $_FILES['file'];

        if ($file['size'] > HL_Import_Service::MAX_FILE_SIZE) {
            wp_send_json_error(array('message' => __('File exceeds the 2MB size limit.', 'hl-core')));
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            wp_send_json_error(array('message' => __('Only CSV files are supported.', 'hl-core')));
        }

        $import_service = new HL_Import_Service();

        // Parse CSV
        $parsed = $import_service->parse_csv($file['tmp_name']);
        if (is_wp_error($parsed)) {
            wp_send_json_error(array('message' => $parsed->get_error_message()));
        }

        // Validate rows via handler
        switch ($import_type) {
            case 'children':
                $handler = new HL_Import_Children_Handler();
                $preview_rows = $handler->validate($parsed['rows'], $cycle_id);
                break;
            case 'participants':
            default:
                $handler = new HL_Import_Participant_Handler();
                $preview_rows = $handler->validate($parsed['rows'], $cycle_id);
                break;
        }

        // Create import run
        $run_id = $import_service->create_run($cycle_id, $import_type, $file['name']);
        if (!$run_id) {
            wp_send_json_error(array('message' => __('Failed to create import run record.', 'hl-core')));
        }

        // Save preview
        $import_service->save_preview($run_id, $preview_rows);

        @unlink($file['tmp_name']);

        // Build summary counts
        $counts = array('CREATE' => 0, 'UPDATE' => 0, 'SKIP' => 0, 'WARNING' => 0, 'ERROR' => 0);
        foreach ($preview_rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']]++;
            }
        }

        // Prepare rows for JS
        $js_rows = $this->prepare_js_rows($preview_rows, $import_type);

        wp_send_json_success(array(
            'run_id'      => $run_id,
            'import_type' => $import_type,
            'counts'      => $counts,
            'unmapped'    => $parsed['unmapped'],
            'rows'        => $js_rows,
            'total_rows'  => count($js_rows),
        ));
    }

    /**
     * Prepare preview rows for JS depending on import type.
     */
    private function prepare_js_rows($preview_rows, $import_type) {
        $js_rows = array();

        foreach ($preview_rows as $row) {
            $base = array(
                'row_index'           => $row['row_index'],
                'status'              => $row['status'],
                'selected'            => $row['selected'],
                'validation_messages' => $row['validation_messages'],
                'proposed_actions'    => $row['proposed_actions'],
            );

            switch ($import_type) {
                case 'children':
                    $base['parsed_first_name']       = isset($row['parsed_first_name']) ? $row['parsed_first_name'] : '';
                    $base['parsed_last_name']        = isset($row['parsed_last_name']) ? $row['parsed_last_name'] : '';
                    $base['parsed_dob']              = isset($row['parsed_dob']) ? $row['parsed_dob'] : '';
                    $base['parsed_child_identifier'] = isset($row['parsed_child_identifier']) ? $row['parsed_child_identifier'] : '';
                    $base['parsed_classroom_name']   = isset($row['parsed_classroom_name']) ? $row['parsed_classroom_name'] : '';
                    $base['raw_school']              = isset($row['raw_school']) ? $row['raw_school'] : '';
                    $base['parsed_ethnicity']        = isset($row['parsed_ethnicity']) ? $row['parsed_ethnicity'] : '';
                    break;

                case 'participants':
                default:
                    $base['parsed_email']      = isset($row['parsed_email']) ? $row['parsed_email'] : '';
                    $base['parsed_first_name'] = isset($row['parsed_first_name']) ? $row['parsed_first_name'] : '';
                    $base['parsed_last_name']  = isset($row['parsed_last_name']) ? $row['parsed_last_name'] : '';
                    $base['parsed_role']       = isset($row['parsed_role']) ? $row['parsed_role'] : '';
                    $base['raw_school']        = isset($row['raw_school']) ? $row['raw_school'] : '';
                    $base['parsed_classroom']  = isset($row['parsed_classroom']) ? $row['parsed_classroom'] : '';
                    $base['parsed_team']       = isset($row['parsed_team']) ? $row['parsed_team'] : '';
                    $base['parsed_pathway']    = isset($row['parsed_pathway']) ? $row['parsed_pathway'] : '';
                    $base['pathway_source']    = isset($row['pathway_source']) ? $row['pathway_source'] : '';
                    $base['parsed_coach']      = isset($row['parsed_coach']) ? $row['parsed_coach'] : '';
                    break;
            }

            $js_rows[] = $base;
        }

        return $js_rows;
    }

    /**
     * AJAX: Commit selected rows.
     */
    public function ajax_commit() {
        check_ajax_referer('hl_import_commit', 'nonce');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $run_id = isset($_POST['run_id']) ? absint($_POST['run_id']) : 0;
        if (!$run_id) {
            wp_send_json_error(array('message' => __('Missing import run ID.', 'hl-core')));
        }

        global $wpdb;
        $run_row = $wpdb->get_row($wpdb->prepare(
            "SELECT actor_user_id, import_type, cycle_id FROM {$wpdb->prefix}hl_import_run WHERE run_id = %d AND status = 'preview'",
            $run_id
        ));
        if (!$run_row) {
            wp_send_json_error(array('message' => __('Import run not found or already committed.', 'hl-core')));
        }
        if ((int) $run_row->actor_user_id !== get_current_user_id() && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You can only commit your own import runs.', 'hl-core')));
        }

        $selected_raw = isset($_POST['selected_rows']) ? $_POST['selected_rows'] : array();
        $selected = array_map('absint', (array) $selected_raw);

        switch ($run_row->import_type) {
            case 'children':
                $handler = new HL_Import_Children_Handler();
                break;
            case 'participants':
            default:
                $handler = new HL_Import_Participant_Handler();
                break;
        }

        $results = $handler->commit($run_id, $selected);

        wp_send_json_success($results);
    }

    /**
     * AJAX: Generate and return error report URL.
     */
    public function ajax_error_report() {
        check_ajax_referer('hl_import_error_report', 'nonce');

        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'hl-core')));
        }

        $run_id = isset($_POST['run_id']) ? absint($_POST['run_id']) : 0;
        if (!$run_id) {
            wp_send_json_error(array('message' => __('Missing import run ID.', 'hl-core')));
        }

        $import_service = new HL_Import_Service();
        $result = $import_service->generate_error_report($run_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('url' => $result));
    }
}
