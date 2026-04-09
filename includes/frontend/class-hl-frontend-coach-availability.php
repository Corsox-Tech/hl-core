<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_coach_availability] shortcode.
 *
 * Interactive weekly schedule grid that lets coaches set their recurring
 * availability for coaching sessions. Saves 30-minute blocks via
 * HL_Coach_Dashboard_Service.
 *
 * @package HL_Core
 */
class HL_Frontend_Coach_Availability {

    /**
     * Render the coach availability page.
     *
     * @param array $atts Shortcode attributes.
     * @return string Rendered HTML.
     */
    public function render($atts) {
        ob_start();
        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        // Role check: coach WP role or manage_hl_core capability.
        if (!in_array('coach', (array) $user->roles, true) && !current_user_can('manage_hl_core')) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__('You do not have access to this page.', 'hl-core')
                . '</div>';
            return ob_get_clean();
        }

        // Success message after save.
        $show_success = isset($_GET['hl_msg']) && $_GET['hl_msg'] === 'availability_saved';

        // Read coach timezone.
        $coach_tz = get_user_meta($user_id, 'hl_timezone', true);
        if (empty($coach_tz)) {
            $coach_tz = wp_timezone_string();
        }

        $service  = new HL_Coach_Dashboard_Service();
        $existing = $service->get_availability($user_id);

        // Convert existing blocks to a lookup: "day_start" => true.
        $active_slots = array();
        foreach ($existing as $block) {
            $key = $block['day_of_week'] . '_' . substr($block['start_time'], 0, 5);
            $active_slots[$key] = true;
        }

        // Back-link to dashboard.
        $dashboard_url = $this->find_shortcode_page_url('hl_coach_dashboard');

        // Days ordered Mon-Sun for display (data values remain 0=Sun..6=Sat).
        $days = array(
            1 => 'Mon',
            2 => 'Tue',
            3 => 'Wed',
            4 => 'Thu',
            5 => 'Fri',
            6 => 'Sat',
            0 => 'Sun',
        );

        $start_hour = 4;  // 4 AM
        $end_hour   = 22; // 10 PM (last slot starts at 9:30 PM)

        ?>
        <div class="hlca-wrapper">

            <?php if ($dashboard_url) : ?>
            <a href="<?php echo esc_url($dashboard_url); ?>" class="hlca-back-link">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                <?php esc_html_e('Back to Dashboard', 'hl-core'); ?>
            </a>
            <?php endif; ?>

            <!-- Hero header -->
            <div class="hlca-hero">
                <div class="hlca-hero-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/></svg>
                </div>
                <div class="hlca-hero-text">
                    <h2 class="hlca-hero-title"><?php esc_html_e('My Availability', 'hl-core'); ?></h2>
                    <p class="hlca-hero-sub"><?php esc_html_e('Set your recurring weekly coaching hours', 'hl-core'); ?></p>
                </div>
            </div>

            <!-- Success banner -->
            <?php if ($show_success) : ?>
            <div class="hlca-success-banner">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <?php esc_html_e('Your availability has been saved successfully.', 'hl-core'); ?>
            </div>
            <?php endif; ?>

            <!-- Instructions card -->
            <div class="hlca-instructions-card">
                <div class="hlca-instructions-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                </div>
                <div class="hlca-instructions-text">
                    <?php esc_html_e('Click time slots to toggle your availability. Active slots (highlighted) are open for coaching sessions. Click Save when done.', 'hl-core'); ?>
                </div>
            </div>

            <!-- Timezone selector -->
            <div class="hlca-tz-card">
                <div class="hlca-tz-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                </div>
                <div class="hlca-tz-body">
                    <?php HL_Timezone_Helper::render_timezone_select('hlca-tz-select', $coach_tz, 'hlca-tz-dropdown'); ?>
                    <span class="hlca-tz-hint"><?php esc_html_e('Your availability times are in this timezone', 'hl-core'); ?></span>
                </div>
            </div>

            <!-- Weekly grid -->
            <div class="hlca-grid-card">
                <div class="hlca-grid-scroll">
                    <div class="hlca-grid" role="grid" aria-label="<?php esc_attr_e('Weekly availability grid', 'hl-core'); ?>">

                        <!-- Header row -->
                        <div class="hlca-grid-header hlca-grid-time-header"></div>
                        <?php foreach ($days as $day_num => $day_name) : ?>
                        <div class="hlca-grid-header"><?php echo esc_html($day_name); ?></div>
                        <?php endforeach; ?>

                        <!-- Time slot rows -->
                        <?php
                        for ($h = $start_hour; $h < $end_hour; $h++) :
                            for ($m = 0; $m < 60; $m += 30) :
                                $time_start = sprintf('%02d:%02d', $h, $m);
                                if ($m === 30) {
                                    $time_end = sprintf('%02d:%02d', $h + 1, 0);
                                } else {
                                    $time_end = sprintf('%02d:%02d', $h, 30);
                                }
                                $label = date('g:i A', strtotime($time_start));
                                $is_hour = ($m === 0);
                        ?>
                        <div class="hlca-grid-time <?php echo $is_hour ? 'hlca-grid-time-hour' : 'hlca-grid-time-half'; ?>">
                            <?php echo esc_html($label); ?>
                        </div>
                        <?php
                                foreach ($days as $day_num => $day_name) :
                                    $key       = $day_num . '_' . $time_start;
                                    $is_active = isset($active_slots[$key]);
                                    $classes   = 'hlca-cell';
                                    if ($is_active) {
                                        $classes .= ' hlca-active';
                                    }
                                    if ($is_hour) {
                                        $classes .= ' hlca-cell-hour';
                                    }
                        ?>
                        <div class="<?php echo esc_attr($classes); ?>"
                             data-day="<?php echo esc_attr($day_num); ?>"
                             data-start="<?php echo esc_attr($time_start); ?>"
                             data-end="<?php echo esc_attr($time_end); ?>"
                             role="gridcell"
                             tabindex="0"
                             aria-label="<?php echo esc_attr($day_name . ' ' . $label); ?>"
                             aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>">
                        </div>
                        <?php
                                endforeach;
                            endfor;
                        endfor;
                        ?>

                    </div>
                </div>
            </div>

            <!-- Legend -->
            <div class="hlca-legend">
                <div class="hlca-legend-item">
                    <span class="hlca-legend-swatch hlca-legend-swatch-active"></span>
                    <?php esc_html_e('Available', 'hl-core'); ?>
                </div>
                <div class="hlca-legend-item">
                    <span class="hlca-legend-swatch hlca-legend-swatch-inactive"></span>
                    <?php esc_html_e('Unavailable', 'hl-core'); ?>
                </div>
                <div class="hlca-legend-count">
                    <span id="hlca-slot-count"><?php echo count($active_slots); ?></span> <?php esc_html_e('slots selected', 'hl-core'); ?>
                    (<span id="hlca-hours-count"><?php echo number_format(count($active_slots) * 0.5, 1); ?></span> <?php esc_html_e('hours/week', 'hl-core'); ?>)
                </div>
            </div>

            <!-- Save form -->
            <form method="post" class="hlca-save-form">
                <?php wp_nonce_field('hl_coach_availability_save', 'hl_coach_availability_nonce'); ?>
                <input type="hidden" name="availability_data" id="hlca-data" value="">
                <input type="hidden" name="coach_timezone" id="hlca-timezone" value="<?php echo esc_attr($coach_tz); ?>">
                <button type="submit" class="hlca-btn hlca-btn-save">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <?php esc_html_e('Save Availability', 'hl-core'); ?>
                </button>
            </form>

        </div>

        <script>
        (function(){
            /* ----- Toggle cells ----- */
            var cells = document.querySelectorAll('.hlca-cell');
            var isDragging = false;
            var dragMode = null; // 'add' or 'remove'

            cells.forEach(function(cell) {
                // Keyboard toggle
                cell.addEventListener('keydown', function(e) {
                    if (e.key === ' ' || e.key === 'Enter') {
                        e.preventDefault();
                        toggleCell(this);
                    }
                });

                // Drag to paint — mousedown starts drag, click is suppressed
                // to avoid double-toggling (mousedown applies, click would undo).
                cell.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    isDragging = true;
                    dragMode = this.classList.contains('hlca-active') ? 'remove' : 'add';
                    applyDragMode(this);
                });

                cell.addEventListener('mouseenter', function() {
                    if (isDragging) {
                        applyDragMode(this);
                    }
                });

                // Touch support for mobile.
                cell.addEventListener('touchstart', function(e) {
                    e.preventDefault();
                    isDragging = true;
                    dragMode = this.classList.contains('hlca-active') ? 'remove' : 'add';
                    applyDragMode(this);
                }, {passive: false});

                cell.addEventListener('touchend', function(e) {
                    e.preventDefault();
                    if (isDragging) {
                        isDragging = false;
                        dragMode = null;
                        updateAvailabilityData();
                    }
                }, {passive: false});
            });

            document.addEventListener('mouseup', function() {
                if (isDragging) {
                    isDragging = false;
                    dragMode = null;
                    updateAvailabilityData();
                }
            });

            function toggleCell(cell) {
                cell.classList.toggle('hlca-active');
                cell.setAttribute('aria-pressed', cell.classList.contains('hlca-active'));
                updateAvailabilityData();
            }

            function applyDragMode(cell) {
                if (dragMode === 'add') {
                    cell.classList.add('hlca-active');
                    cell.setAttribute('aria-pressed', 'true');
                } else {
                    cell.classList.remove('hlca-active');
                    cell.setAttribute('aria-pressed', 'false');
                }
                updateCounts();
            }

            /* ----- Serialize active cells into hidden input ----- */
            function updateAvailabilityData() {
                var blocks = [];
                document.querySelectorAll('.hlca-cell.hlca-active').forEach(function(cell) {
                    blocks.push({
                        day_of_week: parseInt(cell.dataset.day, 10),
                        start_time:  cell.dataset.start + ':00',
                        end_time:    cell.dataset.end + ':00'
                    });
                });
                document.getElementById('hlca-data').value = JSON.stringify(blocks);
                updateCounts();
            }

            function updateCounts() {
                var count = document.querySelectorAll('.hlca-cell.hlca-active').length;
                var countEl = document.getElementById('hlca-slot-count');
                var hoursEl = document.getElementById('hlca-hours-count');
                if (countEl) countEl.textContent = count;
                if (hoursEl) hoursEl.textContent = (count * 0.5).toFixed(1);
            }

            /* ----- Timezone sync ----- */
            var tzSelect = document.getElementById('hlca-tz-select');
            var tzHidden = document.getElementById('hlca-timezone');
            if (tzSelect && tzHidden) {
                tzHidden.value = tzSelect.value;
                tzSelect.addEventListener('change', function() {
                    tzHidden.value = this.value;
                });
            }

            // Initialize on load.
            updateAvailabilityData();

            // Safety: always serialize before form submit + guard against empty data.
            var saveForm = document.querySelector('.hlca-save-form');
            if (saveForm) {
                saveForm.addEventListener('submit', function(e) {
                    updateAvailabilityData();
                    var dataField = document.getElementById('hlca-data');
                    var activeCount = document.querySelectorAll('.hlca-cell.hlca-active').length;
                    // Prevent wiping saved data if JS serialization silently failed.
                    if (activeCount > 0 && (!dataField.value || dataField.value === '[]')) {
                        e.preventDefault();
                        alert('Error: could not serialize availability data. Please try again or refresh the page.');
                        return false;
                    }
                });
            }
        })();
        </script>

        <?php
        return ob_get_clean();
    }

    /**
     * Handle POST submission of availability data.
     *
     * Hooked to `template_redirect` so it fires before any output.
     */
    public static function handle_post_actions() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['hl_coach_availability_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['hl_coach_availability_nonce'], 'hl_coach_availability_save')) {
            return;
        }

        $user = wp_get_current_user();
        if (!in_array('coach', (array) $user->roles, true) && !current_user_can('manage_hl_core')) {
            return;
        }

        $raw_post    = isset($_POST['availability_data']) ? $_POST['availability_data'] : '[]';
        $blocks_json = wp_unslash($raw_post);
        $blocks      = json_decode($blocks_json, true);
        if (!is_array($blocks)) {
            $blocks = array();
        }
        $valid_blocks = array();
        foreach ($blocks as $block) {
            $day = isset($block['day_of_week']) ? absint($block['day_of_week']) : 99;
            if ($day > 6) {
                continue;
            }
            if (empty($block['start_time']) || empty($block['end_time'])) {
                continue;
            }
            $valid_blocks[] = array(
                'day_of_week' => $day,
                'start_time'  => sanitize_text_field($block['start_time']),
                'end_time'    => sanitize_text_field($block['end_time']),
            );
        }

        // Save timezone if provided and valid.
        if (!empty($_POST['coach_timezone'])) {
            $tz_value = sanitize_text_field(wp_unslash($_POST['coach_timezone']));
            if (in_array($tz_value, DateTimeZone::listIdentifiers(), true)) {
                update_user_meta($user->ID, 'hl_timezone', $tz_value);
            }
        }

        $service = new HL_Coach_Dashboard_Service();
        $service->save_availability($user->ID, $valid_blocks);

        $redirect_url = add_query_arg('hl_msg', 'availability_saved', remove_query_arg('hl_msg'));
        while (ob_get_level()) { ob_end_clean(); }
        if (!headers_sent()) {
            wp_safe_redirect($redirect_url);
            exit;
        }
        echo '<script>window.location.href=' . wp_json_encode($redirect_url) . ';</script>';
        exit;
    }


    /**
     * Find the published page that contains a given shortcode.
     *
     * @param string $shortcode Shortcode tag (without brackets).
     * @return string Page permalink or empty string.
     */
    private function find_shortcode_page_url($shortcode) {
        global $wpdb;
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
             AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like($shortcode) . '%'
        ));
        return $page_id ? get_permalink($page_id) : '';
    }
}
