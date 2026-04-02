# Import Module Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the import system from a global Settings tab with 4 import types to a Cycle Editor tab with 2 import types (Participants + Children), auto-creating classrooms, teams, teaching assignments, and coach assignments from the Participant import.

**Architecture:** Decompose the monolithic `HL_Import_Service` (1,800 lines) into a thin orchestrator + two focused handler classes. Move the admin UI from Settings hub into the Cycle Editor as a new tab. Keep the 3-step wizard UI pattern (Upload → Preview → Commit) and AJAX endpoints.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, jQuery, WordPress AJAX API

**Spec:** `docs/superpowers/specs/2026-04-01-import-redesign-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `includes/services/class-hl-import-service.php` | **Refactor** | Thin orchestrator: CSV parsing, header mapping, run CRUD, delegates validate/commit to handlers |
| `includes/services/class-hl-import-participant-handler.php` | **Create** | Participant validation + commit (enrollment, classroom, team, coach, pathway) |
| `includes/services/class-hl-import-children-handler.php` | **Create** | Children validation + commit (child records, classroom assignment) |
| `includes/admin/class-hl-admin-imports.php` | **Rewrite** | Cycle-scoped UI: render inside Cycle Editor, pass cycle_id to AJAX, render helpers |
| `includes/admin/class-hl-admin-cycles.php` | **Modify** | Add 'import' tab to `$tabs` array and `switch` in `render_tabbed_editor()` |
| `includes/admin/class-hl-admin-settings.php` | **Modify** | Remove imports as default tab, add deprecation notice |
| `includes/admin/class-hl-admin.php` | **Modify** | Update asset enqueue condition to include Cycle Editor import tab |
| `assets/js/admin-import-wizard.js` | **Rewrite** | Cycle-context aware, new columns for participants (team, classroom, coach, pathway), children columns, helper panel |
| `assets/css/admin-import-wizard.css` | **Modify** | Add styles for helper panel, warning badges |
| `hl-core.php` | **No change** | Already requires `class-hl-admin-imports.php` |

---

### Task 1: Add Import Tab to Cycle Editor

**Files:**
- Modify: `includes/admin/class-hl-admin-cycles.php:440-500`

- [ ] **Step 1: Add 'import' to the tabs array**

In `render_tabbed_editor()` at line 440, add `'import'` to the `$tabs` array, after `'assessments'`:

```php
$tabs = array(
    'details'     => __('Details', 'hl-core'),
    'schools'     => __('Schools', 'hl-core'),
    'pathways'    => __('Pathways', 'hl-core'),
    'teams'       => __('Teams', 'hl-core'),
    'enrollments' => __('Enrollments', 'hl-core'),
    'coaching'    => __('Coaching', 'hl-core'),
    'classrooms'  => __('Classrooms', 'hl-core'),
    'assessments' => __('Assessments', 'hl-core'),
    'import'      => __('Import', 'hl-core'),
    'emails'      => __('Emails', 'hl-core'),
);
```

- [ ] **Step 2: Add case to the tab switch**

In the `switch ($current_tab)` block (around line 478), add a case for `'import'` before the `default`:

```php
case 'import':
    HL_Admin_Imports::instance()->render_cycle_import_tab($cycle);
    break;
```

- [ ] **Step 3: Hide import tab for control group and course-type cycles**

Add after the existing conditional tab removals (around line 453):

```php
// Control group and course-type cycles don't use import.
if ($cycle->is_control_group) {
    unset($tabs['import']);
}
if ($cycle_type === 'course') {
    unset($tabs['import']);
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-cycles.php
git commit -m "feat(import): add Import tab to Cycle Editor"
```

---

### Task 2: Rewrite Admin Imports UI for Cycle Context

**Files:**
- Rewrite: `includes/admin/class-hl-admin-imports.php`

- [ ] **Step 1: Rewrite the class with cycle-scoped rendering**

Replace the entire file content with the cycle-aware version. Key changes:
- New method `render_cycle_import_tab($cycle)` that renders inside Cycle Editor
- Remove cycle dropdown (cycle_id comes from `$cycle->cycle_id`)
- Remove classrooms and teaching_assignments from import type options
- Add helper reference panel showing valid schools, roles, pathways, teams
- Pass cycle_id in hidden field for AJAX
- Update AJAX handlers to accept cycle_id from context

```php
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

        // Get cycle's partnership_id
        $partnership_id = $wpdb->get_var($wpdb->prepare(
            "SELECT partnership_id FROM {$prefix}hl_cycle WHERE cycle_id = %d",
            $cycle_id
        ));

        // Schools linked to this cycle
        $schools = $wpdb->get_results($wpdb->prepare(
            "SELECT o.orgunit_id, o.name, o.orgunit_code
             FROM {$prefix}hl_cycle_school cs
             JOIN {$prefix}hl_orgunit o ON cs.school_id = o.orgunit_id
             WHERE cs.cycle_id = %d AND o.status = 'active'
             ORDER BY o.name ASC",
            $cycle_id
        ), ARRAY_A) ?: array();

        // If no direct cycle_school links, try all cycles in the partnership
        if (empty($schools) && $partnership_id) {
            $schools = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT o.orgunit_id, o.name, o.orgunit_code
                 FROM {$prefix}hl_cycle c
                 JOIN {$prefix}hl_cycle_school cs ON c.cycle_id = cs.cycle_id
                 JOIN {$prefix}hl_orgunit o ON cs.school_id = o.orgunit_id
                 WHERE c.partnership_id = %d AND o.status = 'active'
                 ORDER BY o.name ASC",
                $partnership_id
            ), ARRAY_A) ?: array();
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
```

- [ ] **Step 2: Commit**

```bash
git add includes/admin/class-hl-admin-imports.php
git commit -m "feat(import): rewrite admin imports for cycle-scoped context"
```

---

### Task 3: Refactor Import Service as Thin Orchestrator

**Files:**
- Modify: `includes/services/class-hl-import-service.php`

The service keeps: header synonyms, CSV parsing (`parse_csv`, `detect_delimiter`, `map_column_headers`), run CRUD (`create_run`, `save_preview`, `get_preview`, `get_runs`), error report generation, constants, shared lookups. Remove: all `validate_*_rows()` and `commit_*_import()` methods (moved to handlers).

- [ ] **Step 1: Add new header synonyms for new columns**

Add these to the `$header_synonyms` array:

```php
// Team
'team'              => 'team',
'team_name'         => 'team',

// Assigned mentor
'assigned_mentor'   => 'assigned_mentor',
'mentor'            => 'assigned_mentor',
'mentor_email'      => 'assigned_mentor',

// Assigned coach
'assigned_coach'    => 'assigned_coach',
'coach'             => 'assigned_coach',
'coach_email'       => 'assigned_coach',

// Pathway
'pathway'           => 'pathway',
'pathway_name'      => 'pathway',
'lms_pathway'       => 'pathway',
'learning_plan'     => 'pathway',

// Age group
'age_group'         => 'age_group',
'age_band'          => 'age_group',

// Is primary teacher
'is_primary_teacher' => 'is_primary_teacher',
'primary_teacher'    => 'is_primary_teacher',
'is_lead_teacher'    => 'is_primary_teacher',
'lead_teacher'       => 'is_primary_teacher',
'lead'               => 'is_primary_teacher',

// Ethnicity (children)
'ethnicity'          => 'ethnicity',
'race'               => 'ethnicity',
```

- [ ] **Step 2: Remove validate and commit methods**

Delete these methods from the service (they move to handlers):
- `validate_participant_rows()`
- `validate_children_rows()`
- `validate_classroom_rows()`
- `validate_teaching_assignment_rows()`
- `commit_import()`
- `commit_children_import()`
- `commit_classroom_import()`
- `commit_teaching_assignment_import()`

Keep these methods (shared infrastructure):
- `parse_csv()`
- `detect_delimiter()`
- `map_column_headers()`
- `create_run()`
- `save_preview()`
- `get_preview()`
- `get_runs()`
- `generate_error_report()`
- `load_schools_lookup()`
- `load_districts_lookup()`
- `load_classrooms_by_school()`
- `match_school()`
- `match_classroom()`

- [ ] **Step 3: Add Partnership-scoped school lookup method**

Add a new method to load schools scoped to a cycle's Partnership:

```php
/**
 * Load schools linked to the given cycle's Partnership.
 *
 * @param int $cycle_id
 * @return array School rows keyed by orgunit_id.
 */
public function load_partnership_schools($cycle_id) {
    global $wpdb;
    $prefix = $wpdb->prefix;

    // Get partnership_id for this cycle
    $partnership_id = $wpdb->get_var($wpdb->prepare(
        "SELECT partnership_id FROM {$prefix}hl_cycle WHERE cycle_id = %d",
        $cycle_id
    ));

    // Get all schools linked to any cycle in this partnership
    $sql = $wpdb->prepare(
        "SELECT DISTINCT o.orgunit_id, o.name, o.orgunit_code, o.status
         FROM {$prefix}hl_cycle_school cs
         JOIN {$prefix}hl_orgunit o ON cs.school_id = o.orgunit_id
         WHERE cs.cycle_id IN (
             SELECT cycle_id FROM {$prefix}hl_cycle WHERE partnership_id = %d
         ) AND o.orgunit_type = 'school' AND o.status = 'active'",
        $partnership_id ? $partnership_id : $cycle_id
    );

    // Fallback: if no partnership, just get schools for this cycle
    if (!$partnership_id) {
        $sql = $wpdb->prepare(
            "SELECT o.orgunit_id, o.name, o.orgunit_code, o.status
             FROM {$prefix}hl_cycle_school cs
             JOIN {$prefix}hl_orgunit o ON cs.school_id = o.orgunit_id
             WHERE cs.cycle_id = %d AND o.orgunit_type = 'school' AND o.status = 'active'",
            $cycle_id
        );
    }

    $rows = $wpdb->get_results($sql);
    $lookup = array();
    foreach ($rows ?: array() as $row) {
        $lookup[(int) $row->orgunit_id] = $row;
    }
    return $lookup;
}
```

- [ ] **Step 4: Make existing lookup methods public**

Change `load_schools_lookup()`, `load_districts_lookup()`, `load_classrooms_by_school()`, `match_school()`, `match_classroom()` from `private` to `public` so handlers can use them.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-hl-import-service.php
git commit -m "refactor(import): slim service to orchestrator, extract validate/commit to handlers"
```

---

### Task 4: Create Participant Handler

**Files:**
- Create: `includes/services/class-hl-import-participant-handler.php`

This is the largest task. The handler validates participant rows and commits them with all side effects (classrooms, teams, teaching assignments, coach assignments, pathways).

- [ ] **Step 1: Create the handler class with validate method**

Create `includes/services/class-hl-import-participant-handler.php`:

```php
<?php
if (!defined('ABSPATH')) exit;

/**
 * Import Participant Handler
 *
 * Validates and commits participant import rows. Auto-creates
 * classrooms, teaching assignments, teams, team memberships,
 * coach assignments, and pathway assignments.
 *
 * @package HL_Core
 */
class HL_Import_Participant_Handler {

    /** @var HL_Import_Service */
    private $import_service;

    public function __construct() {
        $this->import_service = new HL_Import_Service();
    }

    /**
     * Validate participant rows against database.
     *
     * @param array $parsed_rows Associative arrays from CSV parser.
     * @param int   $cycle_id
     * @return array Preview rows with status, messages, parsed data.
     */
    public function validate($parsed_rows, $cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $preview_rows = array();
        $seen_emails  = array();

        // Pre-load lookups scoped to Partnership
        $partnership_schools = $this->import_service->load_partnership_schools($cycle_id);

        // Build name-based and code-based school indexes for matching
        $school_by_name = array();
        $school_by_code = array();
        foreach ($partnership_schools as $s) {
            $school_by_name[strtolower(trim($s->name))] = $s;
            if (!empty($s->orgunit_code)) {
                $school_by_code[strtolower(trim($s->orgunit_code))] = $s;
            }
        }

        // Pre-load pathways for this cycle
        $pathways = $wpdb->get_results($wpdb->prepare(
            "SELECT pathway_id, pathway_name, pathway_code, target_roles
             FROM {$prefix}hl_pathway WHERE cycle_id = %d AND active_status = 1",
            $cycle_id
        ), ARRAY_A) ?: array();

        $pathway_by_name = array();
        $pathway_by_code = array();
        foreach ($pathways as $p) {
            $pathway_by_name[strtolower(trim($p['pathway_name']))] = $p;
            if (!empty($p['pathway_code'])) {
                $pathway_by_code[strtolower(trim($p['pathway_code']))] = $p;
            }
        }

        // Pre-load existing enrollments for this cycle
        $existing_enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, e.user_id, e.roles, e.school_id, e.status,
                    u.user_email
             FROM {$prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.cycle_id = %d",
            $cycle_id
        ), ARRAY_A) ?: array();

        $enrollment_by_email = array();
        foreach ($existing_enrollments as $ee) {
            $enrollment_by_email[strtolower($ee['user_email'])] = $ee;
        }

        // Pre-load existing teams for this cycle
        $existing_teams = $wpdb->get_results($wpdb->prepare(
            "SELECT team_id, team_name, school_id FROM {$prefix}hl_team WHERE cycle_id = %d AND status = 'active'",
            $cycle_id
        ), ARRAY_A) ?: array();

        $team_lookup = array(); // "school_id|team_name_lower" => team row
        foreach ($existing_teams as $t) {
            $key = $t['school_id'] . '|' . strtolower(trim($t['team_name']));
            $team_lookup[$key] = $t;
        }

        // Pre-load existing classrooms
        $classrooms_by_school = $this->import_service->load_classrooms_by_school();

        foreach ($parsed_rows as $index => $row) {
            $preview = array(
                'row_index'           => $index,
                'raw_data'            => $row,
                'status'              => 'ERROR',
                'matched_user_id'     => null,
                'matched_school_id'   => null,
                'existing_enrollment_id' => null,
                'validation_messages' => array(),
                'proposed_actions'    => array(),
                'parsed_email'        => '',
                'parsed_role'         => '',
                'parsed_first_name'   => '',
                'parsed_last_name'    => '',
                'parsed_classroom'    => '',
                'parsed_team'         => '',
                'parsed_pathway'      => '',
                'parsed_coach'        => '',
                'parsed_age_group'    => '',
                'parsed_is_primary'   => false,
                'raw_school'          => '',
                'selected'            => false,
            );

            // --- Email ---
            $raw_email = isset($row['email']) ? $row['email'] : '';
            $email = HL_Normalization::normalize_email($raw_email);
            $preview['parsed_email'] = $email;

            if (empty($email)) {
                $preview['validation_messages'][] = __('Missing required field: email', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            }
            if (!is_email($email)) {
                $preview['validation_messages'][] = sprintf(__('Invalid email format: %s', 'hl-core'), $email);
                $preview_rows[] = $preview;
                continue;
            }
            if (isset($seen_emails[$email])) {
                $preview['status'] = 'ERROR';
                $preview['validation_messages'][] = sprintf(
                    __('Duplicate email in file (first seen on row %d)', 'hl-core'),
                    $seen_emails[$email] + 1
                );
                $preview_rows[] = $preview;
                continue;
            }
            $seen_emails[$email] = $index;

            // --- Role ---
            $raw_role = isset($row['cycle_roles']) ? trim($row['cycle_roles']) : '';
            $parsed_role = $this->resolve_role($raw_role);
            $preview['parsed_role'] = $parsed_role;

            if (empty($parsed_role)) {
                $preview['validation_messages'][] = sprintf(
                    __('Invalid or missing role: "%s". Valid: Teacher, Mentor, School Leader, District Leader', 'hl-core'),
                    $raw_role
                );
                $preview_rows[] = $preview;
                continue;
            }

            // --- Names (optional with warning) ---
            $first_name = isset($row['first_name']) ? trim($row['first_name']) : '';
            $last_name  = isset($row['last_name']) ? trim($row['last_name']) : '';
            $preview['parsed_first_name'] = $first_name;
            $preview['parsed_last_name']  = $last_name;

            if (empty($first_name) && empty($last_name)) {
                $preview['validation_messages'][] = __('Warning: No first_name or last_name provided. User will be created without a name.', 'hl-core');
            }

            // --- School ---
            $raw_school = isset($row['school_name']) ? trim($row['school_name']) : '';
            if (empty($raw_school) && isset($row['school_code'])) {
                $raw_school = trim($row['school_code']);
            }
            $preview['raw_school'] = $raw_school;

            $matched_school = null;
            if (!empty($raw_school)) {
                $key_name = strtolower($raw_school);
                if (isset($school_by_name[$key_name])) {
                    $matched_school = $school_by_name[$key_name];
                } elseif (isset($school_by_code[$key_name])) {
                    $matched_school = $school_by_code[$key_name];
                }
            }

            $is_district_leader = ($parsed_role === 'District Leader');

            if ($matched_school) {
                $preview['matched_school_id'] = (int) $matched_school->orgunit_id;
            } elseif ($is_district_leader && !empty($raw_school)) {
                // District Leaders with unrecognized school → warning, not error
                $preview['validation_messages'][] = sprintf(
                    __('Warning: "%s" is not a school in this Partnership. District Leaders may not need a school.', 'hl-core'),
                    $raw_school
                );
            } elseif ($is_district_leader && empty($raw_school)) {
                // District Leader with no school — fine
            } else {
                // Non-DL without valid school → error
                if (empty($raw_school)) {
                    $preview['validation_messages'][] = __('Missing required field: school', 'hl-core');
                } else {
                    $preview['validation_messages'][] = sprintf(
                        __('School not found in this Partnership: "%s"', 'hl-core'),
                        $raw_school
                    );
                }
                $preview_rows[] = $preview;
                continue;
            }

            // --- Classroom (optional, semicolon-separated) ---
            $raw_classroom = isset($row['classroom_name']) ? trim($row['classroom_name']) : '';
            $preview['parsed_classroom'] = $raw_classroom;

            // --- Age Group (optional) ---
            $raw_age = isset($row['age_group']) ? strtolower(trim($row['age_group'])) : '';
            $valid_ages = array('infant', 'toddler', 'preschool', 'k2', 'mixed', 'preschool/pre-k');
            if (!empty($raw_age)) {
                // Normalize common variants
                if ($raw_age === 'preschool/pre-k' || $raw_age === 'pre-k' || $raw_age === 'prek') {
                    $raw_age = 'preschool';
                }
                if (!in_array($raw_age, array('infant', 'toddler', 'preschool', 'k2', 'mixed'), true)) {
                    $preview['validation_messages'][] = sprintf(
                        __('Warning: Unrecognized age_group "%s". Valid: infant, toddler, preschool, k2, mixed', 'hl-core'),
                        $raw_age
                    );
                    $raw_age = '';
                }
            }
            $preview['parsed_age_group'] = $raw_age;

            // --- Is Primary Teacher (optional) ---
            $raw_primary = isset($row['is_primary_teacher']) ? strtolower(trim($row['is_primary_teacher'])) : '';
            $preview['parsed_is_primary'] = in_array($raw_primary, array('y', 'yes', '1', 'true'), true);
            if (!empty($raw_primary) && !in_array($raw_primary, array('y', 'yes', '1', 'true', 'n', 'no', '0', 'false', ''), true)) {
                $preview['validation_messages'][] = sprintf(
                    __('Warning: Unrecognized is_primary_teacher value "%s". Expected Y/N.', 'hl-core'),
                    $raw_primary
                );
            }

            // --- Team (optional) ---
            $raw_team = isset($row['team']) ? trim($row['team']) : '';
            $preview['parsed_team'] = $raw_team;

            // --- Assigned Mentor (optional) ---
            $raw_mentor = isset($row['assigned_mentor']) ? trim($row['assigned_mentor']) : '';
            if (!empty($raw_mentor) && is_email($raw_mentor)) {
                // Validate mentor exists in file or system
                $mentor_email = HL_Normalization::normalize_email($raw_mentor);
                $mentor_in_file = false;
                foreach ($parsed_rows as $pr) {
                    $pe = isset($pr['email']) ? HL_Normalization::normalize_email($pr['email']) : '';
                    if ($pe === $mentor_email) {
                        $mentor_in_file = true;
                        break;
                    }
                }
                if (!$mentor_in_file && !isset($enrollment_by_email[$mentor_email])) {
                    $preview['validation_messages'][] = sprintf(
                        __('Warning: Assigned mentor "%s" not found in file or existing enrollments.', 'hl-core'),
                        $raw_mentor
                    );
                }
            }

            // --- Assigned Coach (optional) ---
            $raw_coach = isset($row['assigned_coach']) ? trim($row['assigned_coach']) : '';
            $preview['parsed_coach'] = $raw_coach;
            if (!empty($raw_coach)) {
                $coach_email = HL_Normalization::normalize_email($raw_coach);
                if (!empty($coach_email)) {
                    $coach_user = get_user_by('email', $coach_email);
                    if (!$coach_user) {
                        $preview['validation_messages'][] = sprintf(
                            __('Warning: Coach email "%s" not found as a WordPress user.', 'hl-core'),
                            $raw_coach
                        );
                    }
                }
            }

            // --- Pathway (optional) ---
            $raw_pathway = isset($row['pathway']) ? trim($row['pathway']) : '';
            $preview['parsed_pathway'] = $raw_pathway;
            if (!empty($raw_pathway)) {
                $pw_key = strtolower($raw_pathway);
                if (!isset($pathway_by_name[$pw_key]) && !isset($pathway_by_code[$pw_key])) {
                    $preview['validation_messages'][] = sprintf(
                        __('Warning: Pathway "%s" not found in this cycle.', 'hl-core'),
                        $raw_pathway
                    );
                }
            }

            // --- Determine Status ---
            $has_errors = false;
            foreach ($preview['validation_messages'] as $msg) {
                if (strpos($msg, 'Warning:') === false) {
                    $has_errors = true;
                    break;
                }
            }

            if ($has_errors) {
                $preview['status'] = 'ERROR';
            } elseif (isset($enrollment_by_email[$email])) {
                $existing = $enrollment_by_email[$email];
                $preview['existing_enrollment_id'] = (int) $existing['enrollment_id'];
                $preview['matched_user_id'] = (int) $existing['user_id'];

                // Check if data differs
                $existing_roles = json_decode($existing['roles'], true) ?: array();
                $role_changed   = !in_array($parsed_role, $existing_roles, true);
                $school_changed = $preview['matched_school_id'] && (int) $existing['school_id'] !== $preview['matched_school_id'];

                if ($role_changed || $school_changed) {
                    $preview['status'] = 'UPDATE';
                    $preview['proposed_actions'][] = __('Update enrollment (role or school change)', 'hl-core');
                    $preview['selected'] = true;
                } else {
                    $preview['status'] = 'SKIP';
                    $preview['proposed_actions'][] = __('Already enrolled with identical data', 'hl-core');
                }
            } else {
                // Check if WP user exists
                $wp_user = get_user_by('email', $email);
                if ($wp_user) {
                    $preview['matched_user_id'] = $wp_user->ID;
                    $preview['proposed_actions'][] = __('User exists. Create enrollment in this cycle.', 'hl-core');
                } else {
                    $preview['proposed_actions'][] = __('Create new WordPress user and enrollment.', 'hl-core');
                }
                $preview['status'] = 'CREATE';
                $preview['selected'] = true;
            }

            // Add proposed actions for side effects
            if (!empty($raw_classroom) && !$has_errors) {
                $classrooms = array_map('trim', explode(';', $raw_classroom));
                foreach ($classrooms as $cn) {
                    if (empty($cn)) continue;
                    $preview['proposed_actions'][] = sprintf(__('Classroom: %s (create if needed + teaching assignment)', 'hl-core'), $cn);
                }
            }
            if (!empty($raw_team) && !$has_errors) {
                $membership_type = ($parsed_role === 'Mentor') ? 'mentor' : 'member';
                $preview['proposed_actions'][] = sprintf(__('Team: %s (create if needed, as %s)', 'hl-core'), $raw_team, $membership_type);
            }
            if (!empty($raw_pathway) && !$has_errors) {
                $preview['proposed_actions'][] = sprintf(__('Pathway: %s', 'hl-core'), $raw_pathway);
            }
            if (!empty($raw_coach) && !$has_errors && $parsed_role === 'Mentor') {
                $preview['proposed_actions'][] = sprintf(__('Coach assignment: %s', 'hl-core'), $raw_coach);
            }

            // Mark as WARNING if only warnings exist
            if ($preview['status'] === 'ERROR' && !$has_errors && !empty($preview['validation_messages'])) {
                $preview['status'] = 'WARNING';
                $preview['selected'] = true;
            }

            $preview_rows[] = $preview;
        }

        return $preview_rows;
    }

    /**
     * Resolve a raw role string to a canonical role name.
     *
     * @param string $raw
     * @return string Canonical role or empty string if invalid.
     */
    private function resolve_role($raw) {
        $normalized = strtolower(trim($raw));
        $synonyms = array(
            'teacher'         => 'Teacher',
            'maestro'         => 'Teacher',
            'maestra'         => 'Teacher',
            'mentor'          => 'Mentor',
            'school leader'   => 'School Leader',
            'school_leader'   => 'School Leader',
            'lider de centro' => 'School Leader',
            'director'        => 'School Leader',
            'district leader' => 'District Leader',
            'district_leader' => 'District Leader',
        );
        return isset($synonyms[$normalized]) ? $synonyms[$normalized] : '';
    }

    /**
     * Commit selected participant rows. All-or-nothing transaction.
     *
     * @param int   $run_id
     * @param int[] $selected_row_indices
     * @return array Results summary.
     */
    public function commit($run_id, $selected_row_indices) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $import_service = new HL_Import_Service();
        $run = $import_service->get_preview($run_id);
        if (!$run || $run['status'] !== 'preview') {
            return $this->error_result(__('Invalid import run or already committed.', 'hl-core'));
        }

        $preview_rows = $run['preview_data'];
        $cycle_id     = (int) $run['cycle_id'];
        $selected_set = array_flip($selected_row_indices);

        $team_service     = new HL_Team_Service();
        $pathway_service  = new HL_Pathway_Assignment_Service();
        $classroom_service = new HL_Classroom_Service();

        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = array();

        // Collect rows to process
        $rows_to_process = array();
        foreach ($preview_rows as $row) {
            if (!isset($selected_set[$row['row_index']])) {
                $skipped++;
                continue;
            }
            if ($row['status'] === 'ERROR') {
                $skipped++;
                continue;
            }
            $rows_to_process[] = $row;
        }

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Pre-load lookups
            $partnership_schools = $import_service->load_partnership_schools($cycle_id);
            $school_by_name = array();
            foreach ($partnership_schools as $s) {
                $school_by_name[strtolower(trim($s->name))] = $s;
            }

            // Load pathways
            $pathways = $wpdb->get_results($wpdb->prepare(
                "SELECT pathway_id, pathway_name, pathway_code FROM {$prefix}hl_pathway WHERE cycle_id = %d AND active_status = 1",
                $cycle_id
            ), ARRAY_A) ?: array();
            $pathway_by_name = array();
            $pathway_by_code = array();
            foreach ($pathways as $p) {
                $pathway_by_name[strtolower(trim($p['pathway_name']))] = $p;
                if (!empty($p['pathway_code'])) {
                    $pathway_by_code[strtolower(trim($p['pathway_code']))] = $p;
                }
            }

            // Track created teams to avoid duplicates within this import
            $created_teams = array(); // "school_id|team_name_lower" => team_id

            foreach ($rows_to_process as $row) {
                $email      = $row['parsed_email'];
                $role       = $row['parsed_role'];
                $school_id  = isset($row['matched_school_id']) ? (int) $row['matched_school_id'] : null;

                // 1. Create/find WordPress user
                $wp_user = get_user_by('email', $email);
                if (!$wp_user) {
                    $user_id = wp_create_user($email, wp_generate_password(), $email);
                    if (is_wp_error($user_id)) {
                        throw new Exception(sprintf('Failed to create user %s: %s', $email, $user_id->get_error_message()));
                    }
                    // Set names if provided
                    $update_data = array('ID' => $user_id);
                    if (!empty($row['parsed_first_name'])) {
                        $update_data['first_name'] = $row['parsed_first_name'];
                    }
                    if (!empty($row['parsed_last_name'])) {
                        $update_data['last_name'] = $row['parsed_last_name'];
                    }
                    if (!empty($row['parsed_first_name']) || !empty($row['parsed_last_name'])) {
                        $update_data['display_name'] = trim($row['parsed_first_name'] . ' ' . $row['parsed_last_name']);
                        wp_update_user($update_data);
                    }

                    HL_Audit_Service::log('import_user_created', get_current_user_id(), $cycle_id, null, $user_id,
                        sprintf('User created via import: %s', $email));
                } else {
                    $user_id = $wp_user->ID;
                }

                // 2. Create/update enrollment
                $enrollment_repo = new HL_Enrollment_Repository();

                if ($row['status'] === 'CREATE') {
                    $enrollment_data = array(
                        'cycle_id'  => $cycle_id,
                        'user_id'   => $user_id,
                        'roles'     => wp_json_encode(array($role)),
                        'school_id' => $school_id,
                        'status'    => 'active',
                    );
                    $enrollment_id = $enrollment_repo->create($enrollment_data);
                    if (!$enrollment_id) {
                        throw new Exception(sprintf('Failed to create enrollment for %s', $email));
                    }

                    HL_Audit_Service::log('import_enrollment_created', get_current_user_id(), $cycle_id, null, $enrollment_id,
                        sprintf('Enrollment created via import: %s as %s', $email, $role));

                    $created++;
                } elseif ($row['status'] === 'UPDATE' || $row['status'] === 'WARNING') {
                    $enrollment_id = (int) $row['existing_enrollment_id'];
                    $update_data = array(
                        'roles'     => wp_json_encode(array($role)),
                        'school_id' => $school_id,
                    );
                    $enrollment_repo->update($enrollment_id, $update_data);

                    HL_Audit_Service::log('import_enrollment_updated', get_current_user_id(), $cycle_id, null, $enrollment_id,
                        sprintf('Enrollment updated via import: %s to %s', $email, $role));

                    $updated++;
                } else {
                    // SKIP — get enrollment_id for side effects
                    $enrollment_id = (int) $row['existing_enrollment_id'];
                    if (!$enrollment_id) {
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT enrollment_id FROM {$prefix}hl_enrollment WHERE cycle_id = %d AND user_id = %d",
                            $cycle_id, $user_id
                        ));
                        $enrollment_id = (int) $existing;
                    }
                    $skipped++;
                }

                // 3. Classrooms + Teaching Assignments
                if (!empty($row['parsed_classroom']) && $school_id) {
                    $classroom_names = array_map('trim', explode(';', $row['parsed_classroom']));
                    foreach ($classroom_names as $cn) {
                        if (empty($cn)) continue;

                        // Find or create classroom
                        $classroom = $wpdb->get_row($wpdb->prepare(
                            "SELECT classroom_id FROM {$prefix}hl_classroom WHERE school_id = %d AND classroom_name = %s",
                            $school_id, $cn
                        ));

                        if ($classroom) {
                            $classroom_id = (int) $classroom->classroom_id;
                        } else {
                            $classroom_data = array(
                                'school_id'      => $school_id,
                                'classroom_name' => $cn,
                            );
                            if (!empty($row['parsed_age_group'])) {
                                $classroom_data['age_band'] = $row['parsed_age_group'];
                            }
                            $classroom_id = $classroom_service->create_classroom($classroom_data);
                            if (is_wp_error($classroom_id)) {
                                throw new Exception(sprintf('Failed to create classroom "%s": %s', $cn, $classroom_id->get_error_message()));
                            }

                            HL_Audit_Service::log('import_classroom_created', get_current_user_id(), $cycle_id, null, $classroom_id,
                                sprintf('Classroom created via import: %s at school %d', $cn, $school_id));
                        }

                        // Create teaching assignment (skip if exists)
                        $existing_ta = $wpdb->get_var($wpdb->prepare(
                            "SELECT assignment_id FROM {$prefix}hl_teaching_assignment WHERE enrollment_id = %d AND classroom_id = %d",
                            $enrollment_id, $classroom_id
                        ));
                        if (!$existing_ta) {
                            $ta_data = array(
                                'enrollment_id'  => $enrollment_id,
                                'classroom_id'   => $classroom_id,
                                'is_lead_teacher' => $row['parsed_is_primary'] ? 1 : 0,
                            );
                            $classroom_service->create_teaching_assignment($ta_data);

                            HL_Audit_Service::log('import_teaching_assignment_created', get_current_user_id(), $cycle_id, null, $enrollment_id,
                                sprintf('Teaching assignment created via import: enrollment %d → classroom %d', $enrollment_id, $classroom_id));
                        }
                    }
                }

                // 4. Teams + Memberships
                if (!empty($row['parsed_team']) && $school_id) {
                    $team_name = trim($row['parsed_team']);
                    $team_key  = $school_id . '|' . strtolower($team_name);

                    // Check in-memory cache first
                    if (isset($created_teams[$team_key])) {
                        $team_id = $created_teams[$team_key];
                    } else {
                        // Check DB
                        $existing_team = $wpdb->get_var($wpdb->prepare(
                            "SELECT team_id FROM {$prefix}hl_team WHERE cycle_id = %d AND school_id = %d AND LOWER(team_name) = %s AND status = 'active'",
                            $cycle_id, $school_id, strtolower($team_name)
                        ));
                        if ($existing_team) {
                            $team_id = (int) $existing_team;
                        } else {
                            $team_id = $team_service->create_team(array(
                                'cycle_id'  => $cycle_id,
                                'school_id' => $school_id,
                                'team_name' => $team_name,
                            ));
                            if (is_wp_error($team_id)) {
                                throw new Exception(sprintf('Failed to create team "%s": %s', $team_name, $team_id->get_error_message()));
                            }

                            HL_Audit_Service::log('import_team_created', get_current_user_id(), $cycle_id, null, $team_id,
                                sprintf('Team created via import: %s at school %d', $team_name, $school_id));
                        }
                        $created_teams[$team_key] = $team_id;
                    }

                    // Add membership
                    $membership_type = ($role === 'Mentor') ? 'mentor' : 'member';
                    $result = $team_service->add_member($team_id, $enrollment_id, $membership_type);
                    if (is_wp_error($result) && $result->get_error_code() !== 'already_member') {
                        // already_member is OK (idempotent), other errors are real
                        if ($result->get_error_code() !== 'one_team_per_cycle') {
                            throw new Exception(sprintf('Failed to add team member: %s', $result->get_error_message()));
                        }
                    }
                }

                // 5. Coach Assignment (Mentors only)
                if (!empty($row['parsed_coach']) && $role === 'Mentor') {
                    $coach_email = HL_Normalization::normalize_email($row['parsed_coach']);
                    $coach_user = get_user_by('email', $coach_email);
                    if ($coach_user) {
                        // Check if assignment already exists
                        $existing_ca = $wpdb->get_var($wpdb->prepare(
                            "SELECT coach_assignment_id FROM {$prefix}hl_coach_assignment
                             WHERE coach_user_id = %d AND scope_type = 'enrollment' AND scope_id = %d AND cycle_id = %d",
                            $coach_user->ID, $enrollment_id, $cycle_id
                        ));
                        if (!$existing_ca) {
                            $wpdb->insert($prefix . 'hl_coach_assignment', array(
                                'coach_user_id' => $coach_user->ID,
                                'scope_type'    => 'enrollment',
                                'scope_id'      => $enrollment_id,
                                'cycle_id'      => $cycle_id,
                                'effective_from' => current_time('Y-m-d'),
                            ));

                            HL_Audit_Service::log('import_coach_assigned', get_current_user_id(), $cycle_id, null, $enrollment_id,
                                sprintf('Coach %s assigned to mentor enrollment %d via import', $coach_email, $enrollment_id));
                        }
                    }
                }

                // 6. Pathway Assignment
                if (!empty($row['parsed_pathway'])) {
                    $pw_key = strtolower(trim($row['parsed_pathway']));
                    $matched_pw = isset($pathway_by_name[$pw_key]) ? $pathway_by_name[$pw_key] : (isset($pathway_by_code[$pw_key]) ? $pathway_by_code[$pw_key] : null);
                    if ($matched_pw) {
                        $pathway_service->assign_pathway($enrollment_id, (int) $matched_pw['pathway_id'], 'explicit');
                    }
                }
            }

            // Run sync_role_defaults for enrollments that didn't get explicit pathway
            $pathway_service->sync_role_defaults($cycle_id);

            $wpdb->query('COMMIT');

            // Update import run
            $import_service->update_run_status($run_id, 'committed', array(
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'error_count'   => count($errors),
                'errors'        => $errors,
            ));

            HL_Audit_Service::log('import_committed', get_current_user_id(), $cycle_id, null, $run_id,
                sprintf('Participant import committed: %d created, %d updated, %d skipped', $created, $updated, $skipped));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');

            $errors[] = array(
                'row_index' => null,
                'email'     => '',
                'message'   => $e->getMessage(),
            );

            $import_service->update_run_status($run_id, 'failed', array(
                'created_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'error_count'   => 1,
                'errors'        => $errors,
            ));

            return array(
                'created_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'error_count'   => 1,
                'errors'        => $errors,
            );
        }

        return array(
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'error_count'   => count($errors),
            'errors'        => $errors,
        );
    }

    /**
     * Helper: build error result array.
     */
    private function error_result($message) {
        return array(
            'created_count' => 0, 'updated_count' => 0,
            'skipped_count' => 0, 'error_count'   => 1,
            'errors' => array(array('message' => $message)),
        );
    }
}
```

- [ ] **Step 2: Add require_once in hl-core.php**

In `hl-core.php`, after the existing import service require, add:

```php
require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-import-participant-handler.php';
require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-import-children-handler.php';
```

- [ ] **Step 3: Add update_run_status method to HL_Import_Service**

Add this method to `class-hl-import-service.php` (it may already exist as part of existing commit logic — if so, make it public):

```php
/**
 * Update import run status and results summary.
 *
 * @param int    $run_id
 * @param string $status  'committed' or 'failed'
 * @param array  $results Results summary array.
 */
public function update_run_status($run_id, $status, $results) {
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'hl_import_run',
        array(
            'status'          => $status,
            'results_summary' => wp_json_encode($results),
        ),
        array('run_id' => absint($run_id))
    );
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-hl-import-participant-handler.php hl-core.php includes/services/class-hl-import-service.php
git commit -m "feat(import): create participant handler with classroom/team/coach/pathway auto-creation"
```

---

### Task 5: Create Children Handler

**Files:**
- Create: `includes/services/class-hl-import-children-handler.php`

- [ ] **Step 1: Create the children handler class**

Create `includes/services/class-hl-import-children-handler.php`. This is largely extracted from the existing `validate_children_rows()` and `commit_children_import()` methods in `HL_Import_Service`, with these changes:
- Classroom is now **required** (not optional)
- School can be inferred from classroom if unambiguous
- Partnership-scoped validation
- All-or-nothing transaction
- Ethnicity column support

```php
<?php
if (!defined('ABSPATH')) exit;

/**
 * Import Children Handler
 *
 * Validates and commits children import rows.
 * Classroom is required. School can be inferred from classroom.
 *
 * @package HL_Core
 */
class HL_Import_Children_Handler {

    /** @var HL_Import_Service */
    private $import_service;

    public function __construct() {
        $this->import_service = new HL_Import_Service();
    }

    /**
     * Validate children rows.
     *
     * @param array $parsed_rows
     * @param int   $cycle_id
     * @return array Preview rows.
     */
    public function validate($parsed_rows, $cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $child_repo   = new HL_Child_Repository();
        $preview_rows = array();

        // Load Partnership-scoped schools
        $partnership_schools = $this->import_service->load_partnership_schools($cycle_id);
        $school_by_name = array();
        foreach ($partnership_schools as $s) {
            $school_by_name[strtolower(trim($s->name))] = $s;
        }

        // Load all classrooms grouped by school, filtered to Partnership schools
        $all_classrooms = $wpdb->get_results(
            "SELECT c.classroom_id, c.classroom_name, c.school_id
             FROM {$prefix}hl_classroom c
             WHERE c.status = 'active'
             ORDER BY c.classroom_name",
            ARRAY_A
        ) ?: array();

        // Build lookup: classroom_name_lower => array of {classroom_id, school_id}
        $classroom_lookup = array();
        $partnership_school_ids = array_keys($partnership_schools);
        foreach ($all_classrooms as $cr) {
            if (!in_array((int) $cr['school_id'], $partnership_school_ids, true)) {
                continue;
            }
            $key = strtolower(trim($cr['classroom_name']));
            $classroom_lookup[$key][] = $cr;
        }

        foreach ($parsed_rows as $index => $row) {
            $preview = array(
                'row_index'              => $index,
                'raw_data'               => $row,
                'status'                 => 'ERROR',
                'matched_school_id'      => null,
                'matched_classroom_id'   => null,
                'matched_child_id'       => null,
                'validation_messages'    => array(),
                'proposed_actions'       => array(),
                'parsed_first_name'      => '',
                'parsed_last_name'       => '',
                'parsed_dob'             => '',
                'parsed_child_identifier' => '',
                'parsed_classroom_name'  => '',
                'parsed_ethnicity'       => '',
                'raw_school'             => '',
                'selected'               => false,
            );

            // Names (required: at least one)
            $first_name = isset($row['first_name']) ? trim($row['first_name']) : '';
            $last_name  = isset($row['last_name']) ? trim($row['last_name']) : '';
            $preview['parsed_first_name'] = $first_name;
            $preview['parsed_last_name']  = $last_name;

            if (empty($first_name) && empty($last_name)) {
                $preview['validation_messages'][] = __('Missing required field: first_name or last_name', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            }

            // Classroom (required)
            $classroom_name = isset($row['classroom_name']) ? trim($row['classroom_name']) : '';
            $preview['parsed_classroom_name'] = $classroom_name;

            if (empty($classroom_name)) {
                $preview['validation_messages'][] = __('Missing required field: classroom', 'hl-core');
                $preview_rows[] = $preview;
                continue;
            }

            // School (optional — infer from classroom if unambiguous)
            $raw_school = isset($row['school_name']) ? trim($row['school_name']) : '';
            $preview['raw_school'] = $raw_school;

            $cr_key = strtolower($classroom_name);
            $matching_classrooms = isset($classroom_lookup[$cr_key]) ? $classroom_lookup[$cr_key] : array();

            if (!empty($raw_school)) {
                // School provided — find classroom at that school
                $school_key = strtolower($raw_school);
                $matched_school = isset($school_by_name[$school_key]) ? $school_by_name[$school_key] : null;
                if (!$matched_school) {
                    $preview['validation_messages'][] = sprintf(__('School not found: "%s"', 'hl-core'), $raw_school);
                    $preview_rows[] = $preview;
                    continue;
                }
                $preview['matched_school_id'] = (int) $matched_school->orgunit_id;

                // Find classroom at this school
                $found_cr = null;
                foreach ($matching_classrooms as $mc) {
                    if ((int) $mc['school_id'] === (int) $matched_school->orgunit_id) {
                        $found_cr = $mc;
                        break;
                    }
                }
                if (!$found_cr) {
                    $preview['validation_messages'][] = sprintf(
                        __('Classroom "%s" not found at school "%s"', 'hl-core'),
                        $classroom_name, $raw_school
                    );
                    $preview_rows[] = $preview;
                    continue;
                }
                $preview['matched_classroom_id'] = (int) $found_cr['classroom_id'];
            } else {
                // No school — infer from classroom
                if (count($matching_classrooms) === 1) {
                    $preview['matched_classroom_id'] = (int) $matching_classrooms[0]['classroom_id'];
                    $preview['matched_school_id']    = (int) $matching_classrooms[0]['school_id'];
                } elseif (count($matching_classrooms) > 1) {
                    $preview['validation_messages'][] = sprintf(
                        __('Classroom "%s" exists at multiple schools. Please add a school column.', 'hl-core'),
                        $classroom_name
                    );
                    $preview_rows[] = $preview;
                    continue;
                } else {
                    $preview['validation_messages'][] = sprintf(
                        __('Classroom "%s" not found in this Partnership.', 'hl-core'),
                        $classroom_name
                    );
                    $preview_rows[] = $preview;
                    continue;
                }
            }

            // DOB (optional)
            $raw_dob = isset($row['date_of_birth']) ? trim($row['date_of_birth']) : '';
            $parsed_dob = '';
            if (!empty($raw_dob)) {
                $ts = strtotime($raw_dob);
                if ($ts !== false) {
                    $parsed_dob = date('Y-m-d', $ts);
                } else {
                    $preview['validation_messages'][] = sprintf(__('Invalid date format: %s', 'hl-core'), $raw_dob);
                }
            }
            $preview['parsed_dob'] = $parsed_dob;

            // Child identifier (optional)
            $preview['parsed_child_identifier'] = isset($row['child_identifier']) ? trim($row['child_identifier']) : '';

            // Ethnicity (optional)
            $preview['parsed_ethnicity'] = isset($row['ethnicity']) ? trim($row['ethnicity']) : '';

            // Fingerprint matching
            $school_id = (int) $preview['matched_school_id'];
            $fingerprint_data = array(
                'school_id'         => $school_id,
                'dob'               => $parsed_dob,
                'internal_child_id' => $preview['parsed_child_identifier'],
                'first_name'        => $first_name,
                'last_name'         => $last_name,
            );
            $fingerprint = HL_Child_Repository::compute_fingerprint($fingerprint_data);
            $matches = $child_repo->find_by_fingerprint($fingerprint, $school_id);

            if (count($matches) === 1) {
                $preview['matched_child_id'] = $matches[0]['child_id'];
                $preview['status'] = 'UPDATE';
                $preview['proposed_actions'][] = sprintf(__('Update existing child (ID: %d)', 'hl-core'), $matches[0]['child_id']);
                $preview['selected'] = true;
            } elseif (count($matches) > 1) {
                $preview['status'] = 'WARNING';
                $preview['validation_messages'][] = sprintf(
                    __('Warning: Ambiguous match — %d existing children match this fingerprint.', 'hl-core'),
                    count($matches)
                );
            } else {
                $preview['status'] = 'CREATE';
                $preview['proposed_actions'][] = __('Create new child record', 'hl-core');
                $preview['proposed_actions'][] = sprintf(__('Assign to classroom: %s', 'hl-core'), $classroom_name);
                $preview['selected'] = true;
            }

            $preview_rows[] = $preview;
        }

        return $preview_rows;
    }

    /**
     * Commit selected children rows. All-or-nothing transaction.
     *
     * @param int   $run_id
     * @param int[] $selected_row_indices
     * @return array Results summary.
     */
    public function commit($run_id, $selected_row_indices) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $import_service = new HL_Import_Service();
        $run = $import_service->get_preview($run_id);
        if (!$run || $run['status'] !== 'preview') {
            return array(
                'created_count' => 0, 'updated_count' => 0,
                'skipped_count' => 0, 'error_count'   => 1,
                'errors' => array(array('message' => __('Invalid import run or already committed.', 'hl-core'))),
            );
        }

        $preview_rows = $run['preview_data'];
        $cycle_id     = (int) $run['cycle_id'];
        $child_repo   = new HL_Child_Repository();
        $classroom_service = new HL_Classroom_Service();

        $selected_set = array_flip($selected_row_indices);
        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = array();

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($preview_rows as $row) {
                if (!isset($selected_set[$row['row_index']])) {
                    $skipped++;
                    continue;
                }
                if ($row['status'] === 'ERROR') {
                    $skipped++;
                    continue;
                }

                $school_id    = (int) $row['matched_school_id'];
                $classroom_id = (int) $row['matched_classroom_id'];

                if ($row['status'] === 'CREATE') {
                    $child_data = array(
                        'school_id'         => $school_id,
                        'first_name'        => $row['parsed_first_name'],
                        'last_name'         => $row['parsed_last_name'],
                        'dob'               => !empty($row['parsed_dob']) ? $row['parsed_dob'] : null,
                        'internal_child_id' => !empty($row['parsed_child_identifier']) ? $row['parsed_child_identifier'] : null,
                        'ethnicity'         => !empty($row['parsed_ethnicity']) ? $row['parsed_ethnicity'] : null,
                    );
                    $child_id = $child_repo->create($child_data);
                    if (!$child_id) {
                        throw new Exception(sprintf('Failed to create child: %s %s', $row['parsed_first_name'], $row['parsed_last_name']));
                    }

                    // Assign to classroom
                    $classroom_service->assign_child_to_classroom($child_id, $classroom_id);

                    HL_Audit_Service::log('import_child_created', get_current_user_id(), $cycle_id, null, $child_id,
                        sprintf('Child created via import: %s %s → classroom %d', $row['parsed_first_name'], $row['parsed_last_name'], $classroom_id));

                    $created++;

                } elseif ($row['status'] === 'UPDATE' || $row['status'] === 'WARNING') {
                    $child_id = (int) $row['matched_child_id'];
                    $update_data = array(
                        'first_name' => $row['parsed_first_name'],
                        'last_name'  => $row['parsed_last_name'],
                    );
                    if (!empty($row['parsed_dob'])) {
                        $update_data['dob'] = $row['parsed_dob'];
                    }
                    if (!empty($row['parsed_child_identifier'])) {
                        $update_data['internal_child_id'] = $row['parsed_child_identifier'];
                    }
                    if (!empty($row['parsed_ethnicity'])) {
                        $update_data['ethnicity'] = $row['parsed_ethnicity'];
                    }
                    $child_repo->update($child_id, $update_data);

                    // Update classroom assignment
                    $classroom_service->assign_child_to_classroom($child_id, $classroom_id);

                    HL_Audit_Service::log('import_child_updated', get_current_user_id(), $cycle_id, null, $child_id,
                        sprintf('Child updated via import: ID %d', $child_id));

                    $updated++;
                }
            }

            $wpdb->query('COMMIT');

            $import_service->update_run_status($run_id, 'committed', array(
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'error_count'   => count($errors),
                'errors'        => $errors,
            ));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');

            $errors[] = array('message' => $e->getMessage());

            $import_service->update_run_status($run_id, 'failed', array(
                'created_count' => 0, 'updated_count' => 0,
                'skipped_count' => 0, 'error_count'   => 1,
                'errors'        => $errors,
            ));

            return array(
                'created_count' => 0, 'updated_count' => 0,
                'skipped_count' => 0, 'error_count'   => 1,
                'errors'        => $errors,
            );
        }

        return array(
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'error_count'   => count($errors),
            'errors'        => $errors,
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-import-children-handler.php
git commit -m "feat(import): create children handler with required classroom and Partnership scoping"
```

---

### Task 6: Update JavaScript Wizard for New Columns

**Files:**
- Rewrite: `assets/js/admin-import-wizard.js`

- [ ] **Step 1: Rewrite the JS wizard**

Key changes from existing wizard:
- Remove `$cycleSelect` — cycle_id comes from hidden input `#hl-import-cycle-id`
- Remove classrooms and teaching_assignments from type handling
- Add new columns for participants: Role, School, Classroom, Team, Pathway, Coach
- Add WARNING to selectable statuses
- Update `handleUpload` to send `cycle_id` instead of `partnership_id`
- Update column definitions and cell value getters

```javascript
/**
 * HL Core Import Wizard (v2 — Cycle-Scoped)
 *
 * Supports 2 import types: participants, children.
 * Runs inside the Cycle Editor Import tab.
 */
(function($) {
    'use strict';

    var HLImportWizard = {
        currentStep: 1,
        runId: 0,
        importType: 'participants',
        previewRows: [],

        init: function() {
            this.cacheDOM();
            this.bindEvents();
            this.updateColumnHints();
        },

        cacheDOM: function() {
            this.$wrap       = $('.hl-import-wizard-wrap');
            this.$steps      = this.$wrap.find('.hl-import-step');
            this.$panels     = this.$wrap.find('.hl-import-panel');
            this.$spinner    = this.$wrap.find('.hl-import-spinner');
            this.$spinnerMsg = this.$wrap.find('.hl-import-spinner-msg');
            this.$notices    = this.$wrap.find('.hl-import-notices');

            // Step 1
            this.$cycleId    = $('#hl-import-cycle-id');
            this.$typeSelect = $('#hl-import-type');
            this.$fileInput  = $('#hl-import-file');
            this.$uploadBtn  = $('#hl-import-upload-btn');

            // Step 2
            this.$summary        = $('#hl-import-summary');
            this.$selectAll      = $('#hl-import-select-all');
            this.$selectionCount = $('#hl-import-selection-count');
            this.$previewTable   = $('#hl-import-preview-table');
            this.$commitBtn      = $('#hl-import-commit-btn');
            this.$cancelBtn      = $('#hl-import-cancel-btn');

            // Step 3
            this.$results      = $('#hl-import-results');
            this.$errorList    = $('#hl-import-error-list');
            this.$downloadBtn  = $('#hl-import-download-errors-btn');
            this.$newImportBtn = $('#hl-import-new-btn');
        },

        bindEvents: function() {
            this.$uploadBtn.on('click', $.proxy(this.handleUpload, this));
            this.$commitBtn.on('click', $.proxy(this.handleCommit, this));
            this.$cancelBtn.on('click', $.proxy(this.handleCancel, this));
            this.$downloadBtn.on('click', $.proxy(this.handleDownloadErrors, this));
            this.$newImportBtn.on('click', $.proxy(this.handleNewImport, this));
            this.$selectAll.on('change', $.proxy(this.handleSelectAll, this));
            this.$wrap.on('click', '.hl-import-bulk-actions button', $.proxy(this.handleBulkAction, this));
            this.$previewTable.on('change', '.hl-row-checkbox', $.proxy(this.handleRowToggle, this));
            this.$typeSelect.on('change', $.proxy(this.updateColumnHints, this));
        },

        updateColumnHints: function() {
            var type = this.$typeSelect.val() || 'participants';
            $('#hl-import-column-hints .description').hide();
            $('#hl-import-column-hints .description[data-type="' + type + '"]').show();
        },

        goToStep: function(step) {
            this.currentStep = step;
            this.$steps.removeClass('active completed');
            this.$steps.each(function() {
                var s = parseInt($(this).data('step'), 10);
                if (s < step) $(this).addClass('completed');
                else if (s === step) $(this).addClass('active');
            });
            this.$panels.hide();
            $('#hl-import-step-' + step).show();
        },

        // == Step 1: Upload ==

        handleUpload: function(e) {
            e.preventDefault();

            var cycleId = this.$cycleId.val();
            if (!cycleId) {
                this.showNotice('error', 'Missing cycle context.');
                return;
            }

            var files = this.$fileInput[0].files;
            if (!files.length) {
                this.showNotice('error', hl_import_i18n.select_file);
                return;
            }

            this.importType = this.$typeSelect.val() || 'participants';

            var formData = new FormData();
            formData.append('action', 'hl_import_upload');
            formData.append('nonce', hl_import_i18n.nonce_upload);
            formData.append('cycle_id', cycleId);
            formData.append('import_type', this.importType);
            formData.append('file', files[0]);

            var self = this;
            this.showSpinner(hl_import_i18n.uploading);

            $.ajax({
                url: hl_import_i18n.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(resp) {
                    self.hideSpinner();
                    if (resp.success) {
                        self.runId = resp.data.run_id;
                        self.importType = resp.data.import_type || self.importType;
                        self.previewRows = resp.data.rows;
                        self.renderPreview(resp.data);
                        self.goToStep(2);
                    } else {
                        self.showNotice('error', resp.data.message || hl_import_i18n.unknown_error);
                    }
                },
                error: function() {
                    self.hideSpinner();
                    self.showNotice('error', hl_import_i18n.unknown_error);
                }
            });
        },

        // == Step 2: Preview ==

        getColumns: function() {
            if (this.importType === 'children') {
                return [
                    { key: 'name',      label: hl_import_i18n.col_name || 'Name' },
                    { key: 'classroom', label: hl_import_i18n.col_classroom || 'Classroom' },
                    { key: 'school',    label: hl_import_i18n.col_school || 'School' },
                    { key: 'dob',       label: hl_import_i18n.col_dob || 'DOB' },
                    { key: 'ethnicity', label: 'Ethnicity' }
                ];
            }
            // participants
            return [
                { key: 'email',     label: hl_import_i18n.col_email || 'Email' },
                { key: 'name',      label: hl_import_i18n.col_name || 'Name' },
                { key: 'role',      label: 'Role' },
                { key: 'school',    label: hl_import_i18n.col_school || 'School' },
                { key: 'classroom', label: hl_import_i18n.col_classroom || 'Classroom' },
                { key: 'team',      label: 'Team' },
                { key: 'pathway',   label: 'Pathway' }
            ];
        },

        getCellValue: function(row, colKey) {
            switch (colKey) {
                case 'email':     return row.parsed_email || '';
                case 'name':      return $.trim((row.parsed_first_name || '') + ' ' + (row.parsed_last_name || ''));
                case 'role':      return row.parsed_role || '';
                case 'school':    return row.raw_school || '';
                case 'classroom': return row.parsed_classroom || row.parsed_classroom_name || '';
                case 'team':      return row.parsed_team || '';
                case 'pathway':   return row.parsed_pathway || '';
                case 'dob':       return row.parsed_dob || '';
                case 'ethnicity': return row.parsed_ethnicity || '';
                default:          return '';
            }
        },

        renderPreview: function(data) {
            var counts = data.counts;
            var html = '';
            html += this.summaryCard('create', 'CREATE', counts.CREATE);
            html += this.summaryCard('update', 'UPDATE', counts.UPDATE);
            html += this.summaryCard('skip', 'SKIP', counts.SKIP);
            html += this.summaryCard('warning', 'WARNING', counts.WARNING);
            html += this.summaryCard('error', 'ERROR', counts.ERROR);
            this.$summary.html(html);

            if (data.unmapped && data.unmapped.length > 0) {
                this.$summary.after(
                    '<div class="hl-import-unmapped">' +
                    hl_import_i18n.unmapped_columns + ': <strong>' +
                    $('<span>').text(data.unmapped.join(', ')).html() +
                    '</strong></div>'
                );
            }

            var columns = this.getColumns();
            var $thead = this.$previewTable.find('thead tr');
            $thead.empty();
            $thead.append('<th class="col-checkbox"><input type="checkbox" id="hl-import-select-all-th" /></th>');
            $thead.append($('<th class="col-row-num">').text('#'));
            $thead.append($('<th>').text(hl_import_i18n.col_status));

            for (var c = 0; c < columns.length; c++) {
                $thead.append($('<th>').text(columns[c].label));
            }
            $thead.append($('<th>').text(hl_import_i18n.col_details));

            var self = this;
            $thead.find('#hl-import-select-all-th').on('change', function() {
                self.$selectAll.prop('checked', $(this).prop('checked')).trigger('change');
            });

            var $tbody = this.$previewTable.find('tbody');
            $tbody.empty();

            for (var i = 0; i < this.previewRows.length; i++) {
                var row = this.previewRows[i];
                var selectable = (row.status === 'CREATE' || row.status === 'UPDATE' || row.status === 'WARNING');
                var checked = row.selected && selectable;

                var $tr = $('<tr>').data('row-index', row.row_index);
                if (!checked) $tr.addClass('row-deselected');

                var $cb = $('<input type="checkbox" class="hl-row-checkbox" />')
                    .val(row.row_index)
                    .prop('checked', checked)
                    .prop('disabled', !selectable);
                $tr.append($('<td class="col-checkbox">').append($cb));
                $tr.append($('<td class="col-row-num">').text(row.row_index + 1));

                var statusClass = row.status.toLowerCase().replace('_', '-');
                $tr.append($('<td>').append($('<span class="hl-import-status ' + statusClass + '">').text(row.status)));

                for (var j = 0; j < columns.length; j++) {
                    $tr.append($('<td>').text(this.getCellValue(row, columns[j].key)));
                }

                var $details = $('<td>');
                if (row.proposed_actions && row.proposed_actions.length > 0) {
                    var $actions = $('<ul class="hl-import-cell-messages">');
                    for (var a = 0; a < row.proposed_actions.length; a++) {
                        $actions.append($('<li>').text(row.proposed_actions[a]));
                    }
                    $details.append($actions);
                }
                if (row.validation_messages && row.validation_messages.length > 0) {
                    var $msgs = $('<ul class="hl-import-cell-messages" style="color:#d63638;">');
                    for (var m = 0; m < row.validation_messages.length; m++) {
                        $msgs.append($('<li>').text(row.validation_messages[m]));
                    }
                    $details.append($msgs);
                }
                $tr.append($details);
                $tbody.append($tr);
            }

            this.updateSelectionCounts();
        },

        summaryCard: function(cls, label, count) {
            return '<div class="hl-import-summary-card ' + cls + '">' +
                '<div class="count">' + count + '</div>' +
                '<div class="label">' + label + '</div>' +
                '</div>';
        },

        handleRowToggle: function(e) {
            var $cb = $(e.target);
            var $tr = $cb.closest('tr');
            var idx = parseInt($cb.val(), 10);
            $tr.toggleClass('row-deselected', !$cb.prop('checked'));
            for (var i = 0; i < this.previewRows.length; i++) {
                if (this.previewRows[i].row_index === idx) {
                    this.previewRows[i].selected = $cb.prop('checked');
                    break;
                }
            }
            this.updateSelectionCounts();
        },

        handleSelectAll: function(e) {
            var checked = $(e.target).prop('checked');
            this.$previewTable.find('.hl-row-checkbox:not(:disabled)').each(function() {
                $(this).prop('checked', checked).closest('tr').toggleClass('row-deselected', !checked);
            });
            for (var i = 0; i < this.previewRows.length; i++) {
                var r = this.previewRows[i];
                if (r.status === 'CREATE' || r.status === 'UPDATE' || r.status === 'WARNING') {
                    r.selected = checked;
                }
            }
            this.updateSelectionCounts();
        },

        handleBulkAction: function(e) {
            var action = $(e.target).data('bulk');
            var self = this;
            this.$previewTable.find('.hl-row-checkbox:not(:disabled)').each(function() {
                var $cb = $(this);
                var idx = parseInt($cb.val(), 10);
                var row = null;
                for (var i = 0; i < self.previewRows.length; i++) {
                    if (self.previewRows[i].row_index === idx) { row = self.previewRows[i]; break; }
                }
                if (!row) return;
                var shouldCheck = (action === 'create' && row.status === 'CREATE') || (action === 'update' && row.status === 'UPDATE');
                $cb.prop('checked', shouldCheck).closest('tr').toggleClass('row-deselected', !shouldCheck);
                row.selected = shouldCheck;
            });
            this.updateSelectionCounts();
        },

        updateSelectionCounts: function() {
            var count = this.$previewTable.find('.hl-row-checkbox:checked').length;
            var total = this.$previewTable.find('.hl-row-checkbox:not(:disabled)').length;
            this.$selectionCount.text(count + ' / ' + total + ' ' + hl_import_i18n.selected);
            this.$selectAll.prop('checked', count === total && total > 0);
            this.$wrap.find('#hl-import-select-all-th').prop('checked', count === total && total > 0);
        },

        // == Commit ==

        handleCommit: function(e) {
            e.preventDefault();
            var selected = [];
            this.$previewTable.find('.hl-row-checkbox:checked').each(function() {
                selected.push(parseInt($(this).val(), 10));
            });
            if (selected.length === 0) {
                this.showNotice('error', hl_import_i18n.no_rows_selected);
                return;
            }
            if (!confirm(hl_import_i18n.confirm_commit.replace('%d', selected.length))) return;

            var self = this;
            this.showSpinner(hl_import_i18n.committing);

            $.ajax({
                url: hl_import_i18n.ajax_url,
                type: 'POST',
                data: {
                    action: 'hl_import_commit',
                    nonce: hl_import_i18n.nonce_commit,
                    run_id: this.runId,
                    selected_rows: selected
                },
                success: function(resp) {
                    self.hideSpinner();
                    if (resp.success) {
                        self.renderResults(resp.data);
                        self.goToStep(3);
                    } else {
                        self.showNotice('error', resp.data.message || hl_import_i18n.unknown_error);
                    }
                },
                error: function() {
                    self.hideSpinner();
                    self.showNotice('error', hl_import_i18n.unknown_error);
                }
            });
        },

        handleCancel: function(e) {
            e.preventDefault();
            if (confirm(hl_import_i18n.confirm_cancel)) {
                this.goToStep(1);
                this.resetState();
            }
        },

        // == Step 3: Results ==

        renderResults: function(data) {
            var html = '';
            html += this.resultCard('created', hl_import_i18n.created, data.created_count);
            html += this.resultCard('updated', hl_import_i18n.updated, data.updated_count);
            html += this.resultCard('skipped', hl_import_i18n.skipped, data.skipped_count);
            html += this.resultCard('errors', hl_import_i18n.errors_label, data.error_count);
            this.$results.html(html);

            if (data.errors && data.errors.length > 0) {
                var $table = $('<table class="widefat striped">');
                var $head = $('<thead><tr></tr></thead>');
                $head.find('tr')
                    .append($('<th>').text(hl_import_i18n.col_row))
                    .append($('<th>').text(hl_import_i18n.col_email || 'Email'))
                    .append($('<th>').text(hl_import_i18n.col_error));
                $table.append($head);

                var $body = $('<tbody>');
                for (var i = 0; i < data.errors.length; i++) {
                    var err = data.errors[i];
                    var $row = $('<tr>');
                    $row.append($('<td>').text(err.row_index !== undefined && err.row_index !== null ? err.row_index + 1 : '-'));
                    $row.append($('<td>').text(err.email || err.name || '-'));
                    $row.append($('<td>').text(err.message));
                    $body.append($row);
                }
                $table.append($body);
                this.$errorList.html('<h3>' + hl_import_i18n.commit_errors + '</h3>').append($table);
                this.$downloadBtn.show();
            } else {
                this.$errorList.html('<p style="color:#00a32a;font-weight:600;">' + hl_import_i18n.all_success + '</p>');
                this.$downloadBtn.hide();
            }
        },

        resultCard: function(cls, label, count) {
            return '<div class="hl-import-result-card ' + cls + '">' +
                '<div class="count">' + count + '</div>' +
                '<div class="label">' + label + '</div>' +
                '</div>';
        },

        handleDownloadErrors: function(e) {
            e.preventDefault();
            var self = this;
            this.showSpinner(hl_import_i18n.generating_report);
            $.ajax({
                url: hl_import_i18n.ajax_url,
                type: 'POST',
                data: {
                    action: 'hl_import_error_report',
                    nonce: hl_import_i18n.nonce_error_report,
                    run_id: this.runId
                },
                success: function(resp) {
                    self.hideSpinner();
                    if (resp.success) window.open(resp.data.url, '_blank');
                    else self.showNotice('error', resp.data.message || hl_import_i18n.unknown_error);
                },
                error: function() {
                    self.hideSpinner();
                    self.showNotice('error', hl_import_i18n.unknown_error);
                }
            });
        },

        handleNewImport: function(e) {
            e.preventDefault();
            this.goToStep(1);
            this.resetState();
        },

        // == Helpers ==

        resetState: function() {
            this.runId = 0;
            this.importType = this.$typeSelect.val() || 'participants';
            this.previewRows = [];
            this.$fileInput.val('');
            this.$summary.empty();
            this.$previewTable.find('thead tr').empty();
            this.$previewTable.find('tbody').empty();
            this.$results.empty();
            this.$errorList.empty();
            this.$notices.empty();
            this.$wrap.find('.hl-import-unmapped').remove();
        },

        showSpinner: function(msg) {
            this.$spinnerMsg.text(msg || hl_import_i18n.processing);
            this.$spinner.show();
        },

        hideSpinner: function() {
            this.$spinner.hide();
        },

        showNotice: function(type, message) {
            var cls = (type === 'error') ? 'notice-error' : 'notice-success';
            var $notice = $('<div class="notice ' + cls + ' is-dismissible"><p></p></div>');
            $notice.find('p').text(message);
            this.$notices.html($notice);
            setTimeout(function() { $notice.fadeOut(); }, 8000);
        }
    };

    $(document).ready(function() {
        if ($('.hl-import-wizard-wrap').length) {
            HLImportWizard.init();
        }
    });

})(jQuery);
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/admin-import-wizard.js
git commit -m "feat(import): rewrite JS wizard for cycle context and new participant columns"
```

---

### Task 7: Update Asset Enqueue and Settings Page

**Files:**
- Modify: `includes/admin/class-hl-admin.php:162-204`
- Modify: `includes/admin/class-hl-admin-settings.php:58-92`

- [ ] **Step 1: Update asset enqueue condition**

In `class-hl-admin.php`, update the import wizard asset detection (around line 162) to also load on the Cycles page when `tab=import`:

```php
// Import wizard assets (on Cycle Editor import tab or legacy settings)
$is_imports = strpos($hook, 'hl-imports') !== false
           || (strpos($hook, 'hl-settings') !== false && (!isset($_GET['tab']) || $_GET['tab'] === 'imports'))
           || (strpos($hook, 'hl-cycles') !== false && isset($_GET['tab']) && $_GET['tab'] === 'import');
```

- [ ] **Step 2: Update Settings page to remove imports as default**

In `class-hl-admin-settings.php`, change the default tab from `'imports'` to `'scheduling'` and replace the imports case with a deprecation notice:

At line 29, change:
```php
$tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'scheduling';
```

At line 58, change:
```php
$tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'scheduling';
```

Replace the `default` case in `render_page()` (lines 86-88) with:
```php
case 'imports':
    echo '<div class="notice notice-info"><p>';
    echo esc_html__('The Import feature has moved! Go to Cycles → [Your Cycle] → Import tab.', 'hl-core');
    echo ' <a href="' . esc_url(admin_url('admin.php?page=hl-cycles')) . '">' . esc_html__('Go to Cycles', 'hl-core') . '</a>';
    echo '</p></div>';
    break;

default:
    HL_Admin_Scheduling_Settings::instance()->render_page_content();
    break;
```

Also update `render_tabs()` — remove 'imports' from the tabs array or rename it to indicate deprecation.

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin.php includes/admin/class-hl-admin-settings.php
git commit -m "feat(import): update asset enqueue for cycle tab, deprecate settings imports"
```

---

### Task 8: Add Warning Badge CSS

**Files:**
- Modify: `assets/css/admin-import-wizard.css`

- [ ] **Step 1: Add warning status styles**

Add these styles to the existing CSS file:

```css
/* Warning status badge */
.hl-import-status.warning {
    background: #fcf0e3;
    color: #996800;
    border: 1px solid #dba617;
}

/* Warning summary card */
.hl-import-summary-card.warning {
    border-left-color: #dba617;
}
.hl-import-summary-card.warning .count {
    color: #996800;
}

/* Helper panel responsive */
.hl-import-helpers ul {
    list-style: none;
    padding-left: 0;
    margin: 5px 0;
}
.hl-import-helpers li {
    margin-bottom: 3px;
}
.hl-import-helpers code {
    background: #fff;
    padding: 1px 5px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-size: 12px;
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/admin-import-wizard.css
git commit -m "style(import): add warning badge and helper panel styles"
```

---

### Task 9: Deploy, Test, and Review

- [ ] **Step 1: Deploy to test server**

Read `.claude/skills/deploy.md` for exact deployment steps. Deploy the updated plugin to the test server.

- [ ] **Step 2: Run code review agent**

Dispatch `superpowers:code-reviewer` agent to review all changed files against the spec at `docs/superpowers/specs/2026-04-01-import-redesign-design.md`. Focus on:
- Partnership-scoped validation completeness
- All-or-nothing transaction correctness (no partial commits)
- AJAX nonce verification on all endpoints
- SQL injection prevention (all queries use `$wpdb->prepare()`)
- XSS prevention (all output escaped)
- Handler method signature consistency between validate/commit

- [ ] **Step 3: Manual browser testing checklist**

Test on the test server:
1. Navigate to Cycles → ELCPB Year 2 → Import tab
2. Verify helper panel shows correct schools, pathways, teams, roles
3. Upload a test CSV with 5 participant rows (1 teacher, 1 mentor, 1 school leader, 1 district leader, 1 float teacher)
4. Verify preview shows correct statuses (CREATE for new, SKIP for existing)
5. Verify District Leader with "ELC Palm Beach" shows WARNING not ERROR
6. Verify float teacher with no classroom shows CREATE (no classroom assignment)
7. Commit — verify all-or-nothing (all succeed or all rollback)
8. Check DB: enrollments, classrooms, teaching assignments, teams, team memberships, coach assignments, pathway assignments
9. Re-upload same CSV — verify all show SKIP
10. Test Children import with 3 rows, verify classroom required validation
11. Check Import History table shows runs for this cycle only
12. Check old Settings → Import shows deprecation notice

- [ ] **Step 4: Fix any issues found**

Address issues from code review and browser testing.

- [ ] **Step 5: Update STATUS.md and README.md**

Per CLAUDE.md Rule #3, update both files to reflect the import redesign completion.

- [ ] **Step 6: Final commit**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md and README.md for import redesign"
```
