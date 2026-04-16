<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Course Catalog Page
 *
 * CRUD interface for the hl_course_catalog table. Maps logical courses
 * to their EN/ES/PT LearnDash equivalents.
 *
 * @package HL_Core
 */
class HL_Admin_Course_Catalog {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Register AJAX hooks (called from hl-core.php init).
     */
    public static function register_ajax_hooks() {
        add_action('wp_ajax_hl_search_ld_courses', array(self::instance(), 'ajax_search_ld_courses'));
    }

    /**
     * Handle POST saves and GET archive actions before output.
     */
    public function handle_early_actions() {
        if (isset($_POST['hl_course_catalog_nonce'])) {
            $this->handle_save();
        }
        if (isset($_GET['action']) && $_GET['action'] === 'archive' && isset($_GET['id'])) {
            $this->handle_archive();
        }
    }

    // ─── POST Save ──────────────────────────────────────────────────

    private function handle_save() {
        if (!wp_verify_nonce($_POST['hl_course_catalog_nonce'], 'hl_save_course_catalog')) {
            wp_die('Nonce verification failed.');
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die('Unauthorized.');
        }

        $repo = new HL_Course_Catalog_Repository();

        $catalog_id    = absint($_POST['catalog_id'] ?? 0);
        $catalog_code  = strtoupper(sanitize_text_field($_POST['catalog_code'] ?? ''));
        $title         = sanitize_text_field($_POST['title'] ?? '');
        $ld_course_en  = absint($_POST['ld_course_en'] ?? 0);
        $ld_course_es  = absint($_POST['ld_course_es'] ?? 0);
        $ld_course_pt  = absint($_POST['ld_course_pt'] ?? 0);
        $status        = in_array($_POST['status'] ?? '', array('active', 'archived'), true)
                            ? $_POST['status'] : 'active';
        $requires_survey = !empty($_POST['requires_survey']) ? 1 : 0;

        // Validation.
        $errors = array();

        if (empty($catalog_code)) {
            $errors[] = 'Catalog code is required.';
        } elseif (!preg_match('/^[A-Z0-9_]+$/', $catalog_code)) {
            $errors[] = 'Catalog code must contain only uppercase letters, digits, and underscores.';
        }

        if (empty($title)) {
            $errors[] = 'Title is required.';
        }

        if ($ld_course_en === 0) {
            $errors[] = 'English course is required.';
        }

        // Duplicate course ID check (cross-column per spec).
        foreach (array('ld_course_en' => $ld_course_en, 'ld_course_es' => $ld_course_es, 'ld_course_pt' => $ld_course_pt) as $col => $cid) {
            if ($cid === 0) continue;
            $dup = $repo->find_duplicate_course_id($cid, $catalog_id);
            if ($dup) {
                $lang_label = str_replace('ld_course_', '', $col);
                $errors[] = sprintf('The %s course (ID %d) is already assigned to catalog entry #%d.', strtoupper($lang_label), $cid, $dup);
            }
        }

        if (!empty($errors)) {
            set_transient('hl_catalog_errors_' . get_current_user_id(), $errors, 30);
            $redirect = admin_url('admin.php?page=hl-course-catalog&action=' . ($catalog_id ? 'edit&id=' . $catalog_id : 'new') . '&error=1');
            wp_redirect($redirect);
            exit;
        }

        $data = array(
            'catalog_code' => $catalog_code,
            'title'        => $title,
            'ld_course_en' => $ld_course_en ?: null,
            'ld_course_es' => $ld_course_es ?: null,
            'ld_course_pt' => $ld_course_pt ?: null,
            'status'          => $status,
            'requires_survey' => $requires_survey,
        );

        if ($catalog_id) {
            // Update.
            $result = $repo->update($catalog_id, $data);
            if (is_wp_error($result)) {
                set_transient('hl_catalog_errors_' . get_current_user_id(), array($result->get_error_message()), 30);
                wp_redirect(admin_url('admin.php?page=hl-course-catalog&action=edit&id=' . $catalog_id . '&error=1'));
                exit;
            }
            if (class_exists('HL_Audit_Service')) {
                HL_Audit_Service::log('course_catalog.updated', array(
                    'entity_type' => 'course_catalog',
                    'entity_id'   => $catalog_id,
                    'after_data'  => $data,
                ));
            }
            wp_redirect(admin_url('admin.php?page=hl-course-catalog&message=updated'));
            exit;
        } else {
            // Create.
            $result = $repo->create($data);
            if (is_wp_error($result)) {
                set_transient('hl_catalog_errors_' . get_current_user_id(), array($result->get_error_message()), 30);
                wp_redirect(admin_url('admin.php?page=hl-course-catalog&action=new&error=1'));
                exit;
            }
            if (class_exists('HL_Audit_Service')) {
                HL_Audit_Service::log('course_catalog.created', array(
                    'entity_type' => 'course_catalog',
                    'entity_id'   => $result,
                    'after_data'  => $data,
                ));
            }
            wp_redirect(admin_url('admin.php?page=hl-course-catalog&message=created'));
            exit;
        }
    }

    // ─── GET Archive ────────────────────────────────────────────────

    private function handle_archive() {
        $catalog_id = absint($_GET['id']);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'hl_archive_catalog_' . $catalog_id)) {
            wp_die('Nonce verification failed.');
        }
        if (!current_user_can('manage_hl_core')) {
            wp_die('Unauthorized.');
        }

        $repo   = new HL_Course_Catalog_Repository();
        $result = $repo->archive($catalog_id);

        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }

        if (class_exists('HL_Audit_Service')) {
            $comp_count = $repo->count_active_components($catalog_id);
            HL_Audit_Service::log('course_catalog.archived', array(
                'entity_type' => 'course_catalog',
                'entity_id'   => $catalog_id,
                'after_data'  => array('active_components_at_archive' => $comp_count),
            ));
        }

        wp_redirect(admin_url('admin.php?page=hl-course-catalog&message=archived'));
        exit;
    }

    // ─── AJAX: Search LD Courses ────────────────────────────────────

    public function ajax_search_ld_courses() {
        check_ajax_referer('hl_catalog_course_search', '_nonce');
        if (!current_user_can('manage_hl_core')) {
            wp_send_json_error('Unauthorized.');
        }

        $q = sanitize_text_field($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            wp_send_json_success(array());
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like($q) . '%';
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type = 'sfwd-courses' AND post_status = 'publish'
             AND post_title LIKE %s
             ORDER BY post_title ASC LIMIT 20",
            $like
        ));

        $output = array();
        foreach (($results ?: array()) as $row) {
            $output[] = array(
                'id'   => (int) $row->ID,
                'text' => $row->post_title . ' (' . $row->ID . ')',
            );
        }

        wp_send_json_success($output);
    }

    // ─── Render Dispatch ────────────────────────────────────────────

    public function render_page() {
        if (!current_user_can('manage_hl_core')) {
            wp_die('Unauthorized.');
        }

        echo '<div class="wrap hl-admin-wrap">';

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

        echo '</div>';
    }

    // ─── List View ──────────────────────────────────────────────────

    private function render_list() {
        $repo    = new HL_Course_Catalog_Repository();
        $filter  = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : null;
        $entries = $filter ? $repo->get_all($filter) : $repo->get_all();

        // Feedback messages.
        $msgs = array(
            'created'  => array('Catalog entry created.', 'success'),
            'updated'  => array('Catalog entry updated.', 'success'),
            'archived' => array('Catalog entry archived.', 'success'),
        );
        $msg_key = sanitize_text_field($_GET['message'] ?? '');
        if (isset($msgs[$msg_key])) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($msgs[$msg_key][1]),
                esc_html($msgs[$msg_key][0])
            );
        }

        HL_Admin::render_page_header('Course Catalog', sprintf(
            '<a href="%s" class="page-title-action">Add New Course</a>',
            esc_url(admin_url('admin.php?page=hl-course-catalog&action=new'))
        ));

        // Status filter pills.
        $current_filter = $filter ?: 'all';
        echo '<ul class="subsubsub" style="margin-bottom:10px;">';
        $filters = array('all' => 'All', 'active' => 'Active', 'archived' => 'Archived');
        $links = array();
        foreach ($filters as $key => $label) {
            $url   = $key === 'all' ? admin_url('admin.php?page=hl-course-catalog') : admin_url('admin.php?page=hl-course-catalog&status=' . $key);
            $class = ($current_filter === $key) ? ' class="current"' : '';
            $links[] = sprintf('<li><a href="%s"%s>%s</a></li>', esc_url($url), $class, esc_html($label));
        }
        echo implode(' | ', $links);
        echo '</ul><br class="clear">';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>Title</th><th>Code</th><th>EN Course</th><th>ES Course</th><th>PT Course</th><th>Status</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        if (empty($entries)) {
            echo '<tr><td colspan="7">No catalog entries found.</td></tr>';
        }

        foreach ($entries as $entry) {
            $edit_url    = admin_url('admin.php?page=hl-course-catalog&action=edit&id=' . $entry->catalog_id);
            $archive_url = wp_nonce_url(
                admin_url('admin.php?page=hl-course-catalog&action=archive&id=' . $entry->catalog_id),
                'hl_archive_catalog_' . $entry->catalog_id
            );
            $comp_count = $repo->count_active_components($entry->catalog_id);

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($entry->title) . '</a></strong></td>';
            echo '<td><code>' . esc_html($entry->catalog_code) . '</code></td>';
            echo '<td>' . $this->format_course_cell($entry->ld_course_en) . '</td>';
            echo '<td>' . $this->format_course_cell($entry->ld_course_es) . '</td>';
            echo '<td>' . $this->format_course_cell($entry->ld_course_pt) . '</td>';
            echo '<td>' . esc_html($entry->status) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '">Edit</a>';
            if ($entry->status !== 'archived') {
                $confirm_msg = $comp_count > 0
                    ? sprintf('Warning: %d active component(s) reference this entry. Archive anyway?', $comp_count)
                    : 'Archive this catalog entry?';
                echo ' | <a href="' . esc_url($archive_url) . '" onclick="return confirm(\'' . esc_js($confirm_msg) . '\')" class="button-link-delete">Archive</a>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Format a course ID cell: show title (ID) or em-dash.
     */
    private function format_course_cell($course_id) {
        if (empty($course_id)) {
            return '&mdash;';
        }
        $course_id = absint($course_id);
        $title = get_the_title($course_id);
        if (!$title) {
            return sprintf('<span style="color:#999;">ID %d (not found)</span>', $course_id);
        }
        return esc_html($title) . ' <small style="color:#999;">(' . $course_id . ')</small>';
    }

    // ─── Add/Edit Form ──────────────────────────────────────────────

    private function render_form() {
        $repo       = new HL_Course_Catalog_Repository();
        $catalog_id = absint($_GET['id'] ?? 0);
        $entry      = $catalog_id ? $repo->get_by_id($catalog_id) : null;
        $is_edit    = (bool) $entry;

        // Retrieve validation errors from transient.
        $errors = get_transient('hl_catalog_errors_' . get_current_user_id());
        if ($errors) {
            delete_transient('hl_catalog_errors_' . get_current_user_id());
            echo '<div class="notice notice-error"><ul>';
            foreach ($errors as $err) {
                echo '<li>' . esc_html($err) . '</li>';
            }
            echo '</ul></div>';
        }

        HL_Admin::render_page_header($is_edit ? 'Edit Catalog Entry' : 'Add New Catalog Entry');

        $nonce = wp_create_nonce('hl_catalog_course_search');

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=hl-course-catalog')); ?>">
            <?php wp_nonce_field('hl_save_course_catalog', 'hl_course_catalog_nonce'); ?>
            <input type="hidden" name="catalog_id" value="<?php echo esc_attr($catalog_id); ?>">

            <table class="form-table">
                <tr>
                    <th><label for="catalog_code">Catalog Code</label></th>
                    <td>
                        <input type="text" name="catalog_code" id="catalog_code"
                               value="<?php echo esc_attr($entry->catalog_code ?? ''); ?>"
                               class="regular-text" required
                               pattern="[A-Za-z0-9_]+" title="Letters, digits, underscores only"
                               oninput="this.value=this.value.toUpperCase()"
                               style="text-transform:uppercase;"
                               <?php echo $is_edit ? '' : 'autofocus'; ?>>
                        <p class="description">e.g. TC1, MC3, TC1_S</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="title">Title</label></th>
                    <td>
                        <input type="text" name="title" id="title"
                               value="<?php echo esc_attr($entry->title ?? ''); ?>"
                               class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th><label>English Course <span style="color:#cc0000;">*</span></label></th>
                    <td>
                        <?php $this->render_course_search_field('ld_course_en', $entry->ld_course_en ?? 0, $nonce); ?>
                    </td>
                </tr>
                <tr>
                    <th><label>Spanish Course</label></th>
                    <td>
                        <?php $this->render_course_search_field('ld_course_es', $entry->ld_course_es ?? 0, $nonce); ?>
                    </td>
                </tr>
                <tr>
                    <th><label>Portuguese Course</label></th>
                    <td>
                        <?php $this->render_course_search_field('ld_course_pt', $entry->ld_course_pt ?? 0, $nonce); ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="status">Status</label></th>
                    <td>
                        <select name="status" id="status">
                            <option value="active" <?php selected($entry->status ?? 'active', 'active'); ?>>Active</option>
                            <option value="archived" <?php selected($entry->status ?? 'active', 'archived'); ?>>Archived</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Survey', 'hl-core'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="requires_survey" value="1" <?php checked($entry->requires_survey ?? 1, 1); ?>>
                            <?php esc_html_e('Requires End of Course Survey', 'hl-core'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When checked, participants must complete the assigned survey after finishing this course.', 'hl-core'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button($is_edit ? 'Update Entry' : 'Create Entry'); ?>
        </form>

        <p><a href="<?php echo esc_url(admin_url('admin.php?page=hl-course-catalog')); ?>">&larr; Back to Course Catalog</a></p>

        <script>
        (function() {
            var debounceTimer;
            var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

            document.querySelectorAll('.hl-course-search').forEach(function(input) {
                var nonce      = input.dataset.nonce;
                var hiddenId   = input.dataset.hidden;
                var hiddenEl   = document.getElementById(hiddenId);
                var dropdown   = input.nextElementSibling;

                input.addEventListener('input', function() {
                    clearTimeout(debounceTimer);
                    var q = input.value.trim();
                    if (q.length < 2) {
                        dropdown.innerHTML = '';
                        dropdown.style.display = 'none';
                        return;
                    }
                    debounceTimer = setTimeout(function() {
                        fetch(ajaxUrl + '?action=hl_search_ld_courses&_nonce=' + encodeURIComponent(nonce) + '&q=' + encodeURIComponent(q))
                            .then(function(r) { return r.json(); })
                            .then(function(resp) {
                                dropdown.innerHTML = '';
                                if (!resp.success || !resp.data.length) {
                                    dropdown.innerHTML = '<div style="padding:6px 10px;color:#999;">No results</div>';
                                    dropdown.style.display = 'block';
                                    return;
                                }
                                resp.data.forEach(function(item) {
                                    var div = document.createElement('div');
                                    div.textContent = item.text;
                                    div.style.cssText = 'padding:6px 10px;cursor:pointer;';
                                    div.addEventListener('mouseenter', function() { div.style.background = '#f0f0f1'; });
                                    div.addEventListener('mouseleave', function() { div.style.background = ''; });
                                    div.addEventListener('click', function() {
                                        hiddenEl.value = item.id;
                                        input.value = item.text;
                                        dropdown.style.display = 'none';
                                        // Show clear button.
                                        var clearBtn = input.parentNode.querySelector('.hl-clear-course');
                                        if (clearBtn) clearBtn.style.display = '';
                                    });
                                    dropdown.appendChild(div);
                                });
                                dropdown.style.display = 'block';
                            });
                    }, 250);
                });

                // Close dropdown on outside click.
                document.addEventListener('click', function(e) {
                    if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                        dropdown.style.display = 'none';
                    }
                });
            });

            // Clear buttons for optional language courses.
            document.querySelectorAll('.hl-clear-course').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var hiddenEl = document.getElementById(btn.dataset.hidden);
                    if (hiddenEl) hiddenEl.value = '';
                    var searchInput = btn.parentNode.querySelector('.hl-course-search');
                    if (searchInput) searchInput.value = '';
                    btn.style.display = 'none';
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render a course search field with hidden ID input and dropdown.
     */
    private function render_course_search_field($name, $current_id, $nonce) {
        $current_id = absint($current_id);
        $display = '';
        if ($current_id) {
            $t = get_the_title($current_id);
            $display = $t ? $t . ' (' . $current_id . ')' : 'ID ' . $current_id;
        }

        $hidden_id = $name . '_hidden';
        ?>
        <input type="hidden" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($hidden_id); ?>"
               value="<?php echo esc_attr($current_id ?: ''); ?>">
        <input type="text" class="hl-course-search regular-text"
               data-nonce="<?php echo esc_attr($nonce); ?>"
               data-hidden="<?php echo esc_attr($hidden_id); ?>"
               value="<?php echo esc_attr($display); ?>"
               placeholder="Type to search LD courses...">
        <button type="button" class="button button-small hl-clear-course"
                data-hidden="<?php echo esc_attr($hidden_id); ?>"
                title="Clear"
                style="<?php echo $current_id ? '' : 'display:none;'; ?>margin-left:4px;">&times;</button>
        <div class="hl-course-dropdown" style="display:none;position:absolute;z-index:9999;background:#fff;border:1px solid #ccc;max-height:200px;overflow-y:auto;width:300px;box-shadow:0 2px 4px rgba(0,0,0,.1);"></div>
        <?php
    }
}
