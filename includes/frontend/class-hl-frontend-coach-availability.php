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

        $start_hour = 7;  // 7 AM
        $end_hour   = 19; // 7 PM (last slot starts at 6:30 PM)

        $this->render_styles();
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

            // Initialize on load.
            updateAvailabilityData();

            // Safety: always serialize before form submit.
            var saveForm = document.querySelector('.hlca-save-form');
            if (saveForm) {
                saveForm.addEventListener('submit', function() {
                    updateAvailabilityData();
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

        $blocks_json = sanitize_text_field(isset($_POST['availability_data']) ? $_POST['availability_data'] : '[]');
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
     * All CSS for the Coach Availability page (inline).
     */
    private function render_styles() {
        ?>
        <style>
        /* Wrapper */
        .hlca-wrapper{max-width:1100px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}

        /* Back link */
        .hlca-back-link{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#64748b;text-decoration:none;margin-bottom:16px;transition:color .2s}
        .hlca-back-link:hover{color:#1e3a5f;text-decoration:none}

        /* Hero */
        .hlca-hero{display:flex;align-items:center;gap:20px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;padding:28px 32px;border-radius:16px;margin-bottom:24px}
        .hlca-hero-icon{flex-shrink:0;display:flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:14px;background:rgba(255,255,255,.12)}
        .hlca-hero-title{font-size:22px;font-weight:700;margin:0;letter-spacing:-.3px}
        .hlca-hero-sub{font-size:14px;opacity:.75;margin:4px 0 0}

        /* Success banner */
        .hlca-success-banner{display:flex;align-items:center;gap:10px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:14px 20px;border-radius:12px;margin-bottom:20px;font-size:14px;font-weight:500}
        .hlca-success-banner svg{flex-shrink:0;color:#059669}

        /* Instructions card */
        .hlca-instructions-card{display:flex;align-items:flex-start;gap:12px;background:#f0f7ff;border:1px solid #bfdbfe;padding:16px 20px;border-radius:12px;margin-bottom:24px}
        .hlca-instructions-icon{flex-shrink:0;color:#3b82f6;margin-top:1px}
        .hlca-instructions-text{font-size:14px;color:#1e40af;line-height:1.5}

        /* Grid card */
        .hlca-grid-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;margin-bottom:20px;overflow:hidden}
        .hlca-grid-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}

        /* Grid layout */
        .hlca-grid{display:grid;grid-template-columns:70px repeat(7,1fr);gap:2px;min-width:600px;user-select:none;-webkit-user-select:none}

        /* Header cells */
        .hlca-grid-header{display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#475569;padding:10px 4px;background:#f8fafc;border-radius:6px}
        .hlca-grid-time-header{background:transparent}

        /* Time labels */
        .hlca-grid-time{display:flex;align-items:center;justify-content:flex-end;padding-right:10px;font-size:11px;color:#8896a6;font-weight:500;white-space:nowrap;min-height:28px}
        .hlca-grid-time-hour{font-weight:600;color:#64748b}
        .hlca-grid-time-half{font-size:10px;color:#b0bbc8}

        /* Clickable cells */
        .hlca-cell{min-height:28px;background:#f8f9fb;border-radius:4px;cursor:pointer;transition:background .15s ease,box-shadow .15s ease;position:relative}
        .hlca-cell:hover{background:#e2e8f0;box-shadow:inset 0 0 0 1px #cbd5e1}
        .hlca-cell.hlca-active{background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);box-shadow:inset 0 0 0 1px rgba(255,255,255,.15)}
        .hlca-cell.hlca-active:hover{background:linear-gradient(135deg,#162d4a 0%,#245178 100%)}
        .hlca-cell:focus{outline:2px solid #3b82f6;outline-offset:1px;z-index:1}
        .hlca-cell-hour{border-top:1px solid #e2e8f0}

        /* Legend */
        .hlca-legend{display:flex;align-items:center;gap:24px;margin-bottom:24px;padding:0 4px}
        .hlca-legend-item{display:flex;align-items:center;gap:8px;font-size:13px;color:#64748b;font-weight:500}
        .hlca-legend-swatch{display:inline-block;width:18px;height:14px;border-radius:3px}
        .hlca-legend-swatch-active{background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%)}
        .hlca-legend-swatch-inactive{background:#f8f9fb;border:1px solid #e2e8f0}
        .hlca-legend-count{margin-left:auto;font-size:13px;color:#8896a6;font-weight:500}

        /* Save form */
        .hlca-save-form{text-align:center;margin-bottom:24px}
        .hlca-btn-save{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5f8a 100%);color:#fff;border:none;padding:12px 28px;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;transition:box-shadow .25s ease,transform .25s ease;font-family:inherit}
        .hlca-btn-save:hover{box-shadow:0 8px 25px rgba(30,58,95,.25);transform:translateY(-1px)}
        .hlca-btn-save:active{transform:translateY(0)}

        /* Responsive */
        @media(max-width:600px){
            .hlca-hero{flex-direction:column;text-align:center;padding:24px 20px}
            .hlca-grid-card{padding:16px 12px}
            .hlca-legend{flex-wrap:wrap;gap:12px}
            .hlca-legend-count{margin-left:0;width:100%;text-align:center}
        }
        </style>
        <?php
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
