<?php
if (!defined('ABSPATH')) exit;

/**
 * Coaching Session Scheduling UI
 *
 * Renders inside the Component Page for coaching_session_attendance components.
 * Two states: scheduling (date picker + time slots) and scheduled (details + action plan).
 *
 * @package HL_Core
 */
class HL_Frontend_Schedule_Session {

    /**
     * Render the scheduling UI for a coaching session component.
     *
     * Called from HL_Frontend_Component_Page::render_available_view().
     *
     * @param object $component  Component domain object.
     * @param object $enrollment Enrollment domain object.
     * @param int    $cycle_id
     */
    public function render($component, $enrollment, $cycle_id) {
        global $wpdb;

        $component_id  = (int) $component->component_id;
        $enrollment_id = (int) $enrollment->enrollment_id;
        $user_id       = get_current_user_id();

        // Get coach assignment.
        $coach_service = new HL_Coach_Assignment_Service();
        $coach         = $coach_service->get_coach_for_enrollment($enrollment_id, $cycle_id);

        if (!$coach) {
            echo '<div class="hl-notice hl-notice-info">'
                . esc_html__('No coach has been assigned yet. Please contact your program coordinator.', 'hl-core')
                . '</div>';
            return;
        }

        $coach_user_id = (int) $coach['coach_user_id'];
        $coach_name    = $coach['coach_name'] ?? '';

        // Check for existing scheduled/attended session for this component.
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT cs.*, u.display_name AS coach_display_name, u.user_email AS coach_email
             FROM {$wpdb->prefix}hl_coaching_session cs
             LEFT JOIN {$wpdb->users} u ON cs.coach_user_id = u.ID
             WHERE cs.component_id = %d
               AND cs.mentor_enrollment_id = %d
               AND cs.session_status IN ('scheduled', 'attended')
             ORDER BY cs.session_status = 'scheduled' DESC, cs.created_at DESC
             LIMIT 1",
            $component_id, $enrollment_id
        ), ARRAY_A);

        // Check for reschedule mode.
        $reschedule_id = isset($_GET['reschedule']) ? absint($_GET['reschedule']) : 0;

        if ($session && !$reschedule_id) {
            $this->render_scheduled_view($session, $component, $enrollment, $coach_user_id);
        } else {
            $this->render_scheduling_view($component, $enrollment, $coach_user_id, $coach_name, $cycle_id, $reschedule_id);
        }
    }

    // =========================================================================
    // State A: Scheduling View (date picker + time slots)
    // =========================================================================

    private function render_scheduling_view($component, $enrollment, $coach_user_id, $coach_name, $cycle_id, $reschedule_id) {
        $component_id  = (int) $component->component_id;
        $enrollment_id = (int) $enrollment->enrollment_id;
        $nonce         = wp_create_nonce('hl_scheduling_nonce');

        // Check drip rule locking.
        $lock_info = $this->check_drip_lock($component_id, $enrollment_id);
        if ($lock_info['locked']) {
            echo '<div class="hl-schedule-locked" style="text-align:center;padding:40px 20px;">';
            echo '<span class="dashicons dashicons-lock" style="font-size:48px;color:#94a3b8;margin-bottom:16px;display:block;width:48px;margin-left:auto;margin-right:auto;"></span>';
            echo '<h3 style="color:#1e3a5f;margin:0 0 8px;">' . esc_html__('Session Not Yet Available', 'hl-core') . '</h3>';
            echo '<p style="color:#64748b;">' . esc_html($lock_info['message']) . '</p>';
            echo '</div>';
            return;
        }

        $settings = HL_Admin_Scheduling_Settings::get_scheduling_settings();
        $max_lead_days = (int) $settings['max_lead_time_days'];

        ?>
        <div class="hl-schedule-session" id="hl-schedule-session">
            <?php if ($reschedule_id) : ?>
                <div class="hl-notice hl-notice-info" style="margin-bottom:20px;">
                    <?php esc_html_e('Select a new date and time for this session.', 'hl-core'); ?>
                </div>
            <?php endif; ?>

            <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;">
                <span class="dashicons dashicons-admin-users" style="font-size:24px;color:#4a90d9;"></span>
                <div>
                    <div style="font-size:13px;color:#64748b;"><?php esc_html_e('Your Coach', 'hl-core'); ?></div>
                    <div style="font-size:16px;font-weight:600;color:#1e3a5f;"><?php echo esc_html($coach_name); ?></div>
                </div>
            </div>

            <!-- Date Picker -->
            <div class="hl-schedule-datepicker" style="margin-bottom:24px;">
                <h4 style="margin:0 0 12px;color:#1e3a5f;font-size:15px;">
                    <span class="dashicons dashicons-calendar-alt" style="margin-right:4px;color:#4a90d9;font-size:16px;"></span>
                    <?php esc_html_e('Select a Date', 'hl-core'); ?>
                </h4>
                <div id="hl-cal-nav" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                    <button type="button" id="hl-cal-prev" class="button button-small">&laquo;</button>
                    <span id="hl-cal-title" style="font-weight:600;color:#1e3a5f;"></span>
                    <button type="button" id="hl-cal-next" class="button button-small">&raquo;</button>
                </div>
                <div id="hl-calendar-grid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;text-align:center;"></div>
            </div>

            <!-- Time Slots -->
            <div id="hl-slots-container" style="display:none;margin-bottom:24px;">
                <h4 style="margin:0 0 12px;color:#1e3a5f;font-size:15px;">
                    <span class="dashicons dashicons-clock" style="margin-right:4px;color:#4a90d9;font-size:16px;"></span>
                    <?php esc_html_e('Available Times', 'hl-core'); ?>
                </h4>
                <div id="hl-slots-list" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
                <div id="hl-slots-loading" style="display:none;text-align:center;padding:20px;color:#64748b;">
                    <?php esc_html_e('Loading available times...', 'hl-core'); ?>
                </div>
                <div id="hl-slots-empty" style="display:none;text-align:center;padding:20px;color:#94a3b8;">
                    <?php esc_html_e('No available times on this date.', 'hl-core'); ?>
                </div>
                <div id="hl-slots-warning" style="display:none;margin-top:8px;padding:8px 12px;background:#fef3cd;border-radius:6px;font-size:13px;color:#856404;">
                    <?php esc_html_e('Could not verify coach\'s Outlook calendar — some slots may conflict with existing meetings.', 'hl-core'); ?>
                </div>
            </div>

            <!-- Confirm Button -->
            <div id="hl-confirm-container" style="display:none;padding:20px;background:#f0f7ff;border-radius:8px;border:1px solid #dbeafe;">
                <p style="margin:0 0 12px;font-size:15px;color:#1e3a5f;">
                    <?php esc_html_e('Confirm your session:', 'hl-core'); ?>
                    <strong id="hl-confirm-date"></strong> <?php esc_html_e('at', 'hl-core'); ?>
                    <strong id="hl-confirm-time"></strong>
                </p>
                <button type="button" id="hl-confirm-book" class="hl-btn hl-btn-primary" style="padding:10px 24px;font-size:15px;">
                    <?php echo $reschedule_id
                        ? esc_html__('Confirm Reschedule', 'hl-core')
                        : esc_html__('Book Session', 'hl-core'); ?>
                </button>
                <span id="hl-booking-status" style="margin-left:12px;font-size:14px;"></span>
            </div>

            <!-- Success message (hidden) -->
            <div id="hl-booking-success" style="display:none;text-align:center;padding:30px;background:#e6f4ea;border-radius:8px;">
                <span class="dashicons dashicons-yes-alt" style="font-size:48px;color:#137333;display:block;width:48px;margin:0 auto 12px;"></span>
                <h3 style="color:#137333;margin:0 0 8px;"><?php esc_html_e('Session Booked!', 'hl-core'); ?></h3>
                <p id="hl-success-details" style="color:#374151;margin:0 0 16px;"></p>
                <a id="hl-success-zoom" href="#" target="_blank" style="display:none;background:#2d8cff;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:600;">
                    <?php esc_html_e('Zoom Meeting Link', 'hl-core'); ?>
                </a>
                <p style="margin:16px 0 0;">
                    <a href="javascript:location.reload()" style="color:#4a90d9;text-decoration:none;"><?php esc_html_e('View Session Details', 'hl-core'); ?></a>
                </p>
            </div>
        </div>

        <?php $this->render_scheduling_css(); ?>

        <script>
        (function() {
            var config = {
                ajaxurl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                nonce: '<?php echo esc_js($nonce); ?>',
                coachUserId: <?php echo (int) $coach_user_id; ?>,
                enrollmentId: <?php echo (int) $enrollment_id; ?>,
                componentId: <?php echo (int) $component_id; ?>,
                rescheduleId: <?php echo (int) $reschedule_id; ?>,
                maxLeadDays: <?php echo (int) $max_lead_days; ?>,
                mentorTz: Intl.DateTimeFormat().resolvedOptions().timeZone
            };

            var state = { currentMonth: new Date(), selectedDate: null, selectedSlot: null };

            // Calendar rendering.
            function renderCalendar() {
                var grid = document.getElementById('hl-calendar-grid');
                var title = document.getElementById('hl-cal-title');
                var year = state.currentMonth.getFullYear();
                var month = state.currentMonth.getMonth();
                var today = new Date(); today.setHours(0,0,0,0);
                var maxDate = new Date(); maxDate.setDate(maxDate.getDate() + config.maxLeadDays);

                var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                title.textContent = months[month] + ' ' + year;

                var html = '';
                var dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                for (var d = 0; d < 7; d++) {
                    html += '<div style="font-size:12px;font-weight:600;color:#94a3b8;padding:4px;">' + dayNames[d] + '</div>';
                }

                var firstDay = new Date(year, month, 1).getDay();
                var daysInMonth = new Date(year, month + 1, 0).getDate();

                for (var i = 0; i < firstDay; i++) {
                    html += '<div></div>';
                }
                for (var day = 1; day <= daysInMonth; day++) {
                    var date = new Date(year, month, day);
                    var isPast = date < today;
                    var isBeyond = date > maxDate;
                    var isSelected = state.selectedDate && state.selectedDate === formatDate(date);
                    var disabled = isPast || isBeyond;

                    var cls = 'hl-cal-day';
                    if (disabled) cls += ' hl-cal-disabled';
                    if (isSelected) cls += ' hl-cal-selected';

                    html += '<div class="' + cls + '" ' + (disabled ? '' : 'data-date="' + formatDate(date) + '"') + '>' + day + '</div>';
                }
                grid.innerHTML = html;

                // Bind click events.
                grid.querySelectorAll('.hl-cal-day[data-date]').forEach(function(el) {
                    el.addEventListener('click', function() {
                        selectDate(this.getAttribute('data-date'));
                    });
                });
            }

            function formatDate(d) {
                return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
            }

            document.getElementById('hl-cal-prev').addEventListener('click', function() {
                state.currentMonth.setMonth(state.currentMonth.getMonth() - 1);
                renderCalendar();
            });
            document.getElementById('hl-cal-next').addEventListener('click', function() {
                state.currentMonth.setMonth(state.currentMonth.getMonth() + 1);
                renderCalendar();
            });

            function selectDate(dateStr) {
                state.selectedDate = dateStr;
                state.selectedSlot = null;
                document.getElementById('hl-confirm-container').style.display = 'none';
                renderCalendar();
                loadSlots(dateStr);
            }

            function loadSlots(dateStr) {
                var container = document.getElementById('hl-slots-container');
                var list = document.getElementById('hl-slots-list');
                var loading = document.getElementById('hl-slots-loading');
                var empty = document.getElementById('hl-slots-empty');
                var warning = document.getElementById('hl-slots-warning');

                container.style.display = 'block';
                list.innerHTML = '';
                list.style.display = 'none';
                loading.style.display = 'block';
                empty.style.display = 'none';
                warning.style.display = 'none';

                fetch(config.ajaxurl + '?action=hl_get_available_slots&_nonce=' + config.nonce
                    + '&coach_user_id=' + config.coachUserId
                    + '&date=' + dateStr
                    + '&timezone=' + encodeURIComponent(config.mentorTz))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    loading.style.display = 'none';
                    if (!data.success) {
                        empty.textContent = data.data && data.data.message ? data.data.message : 'Error loading slots.';
                        empty.style.display = 'block';
                        return;
                    }
                    var slots = data.data.slots || [];
                    if (data.data.outlook_unavailable) {
                        warning.style.display = 'block';
                    }
                    if (slots.length === 0) {
                        empty.style.display = 'block';
                        return;
                    }
                    list.style.display = 'flex';
                    slots.forEach(function(slot) {
                        var pill = document.createElement('button');
                        pill.type = 'button';
                        pill.className = 'hl-slot-pill';
                        pill.textContent = slot.display_label;
                        pill.setAttribute('data-start', slot.start_time);
                        pill.setAttribute('data-label', slot.display_label);
                        pill.addEventListener('click', function() {
                            selectSlot(this);
                        });
                        list.appendChild(pill);
                    });
                })
                .catch(function() {
                    loading.style.display = 'none';
                    empty.textContent = 'Failed to load available times.';
                    empty.style.display = 'block';
                });
            }

            function selectSlot(el) {
                document.querySelectorAll('.hl-slot-pill').forEach(function(p) { p.classList.remove('hl-slot-selected'); });
                el.classList.add('hl-slot-selected');
                state.selectedSlot = el.getAttribute('data-start');

                var parts = state.selectedDate.split('-');
                var dateObj = new Date(parseInt(parts[0]), parseInt(parts[1])-1, parseInt(parts[2]));
                var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                var dateDisplay = months[dateObj.getMonth()] + ' ' + dateObj.getDate() + ', ' + dateObj.getFullYear();

                document.getElementById('hl-confirm-date').textContent = dateDisplay;
                document.getElementById('hl-confirm-time').textContent = el.getAttribute('data-label');
                document.getElementById('hl-confirm-container').style.display = 'block';
            }

            document.getElementById('hl-confirm-book').addEventListener('click', function() {
                if (!state.selectedDate || !state.selectedSlot) return;
                var btn = this;
                var statusEl = document.getElementById('hl-booking-status');
                btn.disabled = true;
                statusEl.textContent = '<?php echo esc_js(__('Booking...', 'hl-core')); ?>';
                statusEl.style.color = '#64748b';

                var formData = new FormData();
                formData.append('action', config.rescheduleId ? 'hl_reschedule_session' : 'hl_book_session');
                formData.append('_nonce', config.nonce);
                formData.append('date', state.selectedDate);
                formData.append('start_time', state.selectedSlot);
                formData.append('timezone', config.mentorTz);

                if (config.rescheduleId) {
                    formData.append('session_id', config.rescheduleId);
                } else {
                    formData.append('mentor_enrollment_id', config.enrollmentId);
                    formData.append('coach_user_id', config.coachUserId);
                    formData.append('component_id', config.componentId);
                }

                fetch(config.ajaxurl, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        document.getElementById('hl-schedule-session').querySelectorAll('.hl-schedule-datepicker, #hl-slots-container, #hl-confirm-container').forEach(function(el) { el.style.display = 'none'; });
                        var successEl = document.getElementById('hl-booking-success');
                        successEl.style.display = 'block';
                        if (data.data.meeting_url) {
                            var zoomLink = document.getElementById('hl-success-zoom');
                            zoomLink.href = data.data.meeting_url;
                            zoomLink.style.display = 'inline-block';
                        }
                    } else {
                        statusEl.textContent = data.data && data.data.message ? data.data.message : 'Booking failed.';
                        statusEl.style.color = '#c5221f';
                        btn.disabled = false;
                    }
                })
                .catch(function() {
                    statusEl.textContent = 'Request failed. Please try again.';
                    statusEl.style.color = '#c5221f';
                    btn.disabled = false;
                });
            });

            renderCalendar();
        })();
        </script>
        <?php
    }

    // =========================================================================
    // State B: Scheduled/Completed View
    // =========================================================================

    private function render_scheduled_view($session, $component, $enrollment, $coach_user_id) {
        $session_id     = (int) $session['session_id'];
        $status         = $session['session_status'];
        $is_completed   = ($status === 'attended');
        $nonce          = wp_create_nonce('hl_scheduling_nonce');
        $current_user   = get_current_user_id();
        $is_admin       = current_user_can('manage_hl_core');
        $is_coach       = ($current_user === (int) $session['coach_user_id']);
        $is_mentor      = ($current_user === (int) $enrollment->user_id);

        // Format time in mentor timezone.
        $display_time = '';
        if (!empty($session['session_datetime'])) {
            try {
                $wp_tz = wp_timezone();
                $dt    = new DateTime($session['session_datetime'], $wp_tz);
                $mentor_tz = $session['mentor_timezone'] ?? wp_timezone_string();
                $dt->setTimezone(new DateTimeZone($mentor_tz));
                $display_time = $dt->format('l, F j, Y \a\t g:i A T');
            } catch (Exception $e) {
                $display_time = $session['session_datetime'];
            }
        }

        // Check if cancel/reschedule is allowed (time-based).
        $can_modify = false;
        if ($status === 'scheduled' && !empty($session['session_datetime'])) {
            $settings         = HL_Admin_Scheduling_Settings::get_scheduling_settings();
            $min_cancel_hours = (int) $settings['min_cancel_notice_hours'];
            $wp_tz            = wp_timezone();
            $session_time     = new DateTime($session['session_datetime'], $wp_tz);
            $now              = new DateTime('now', $wp_tz);
            $diff_hours       = ($session_time->getTimestamp() - $now->getTimestamp()) / 3600;
            $can_modify       = ($diff_hours >= $min_cancel_hours);
        }

        // Active tab.
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'details';

        ?>
        <div class="hl-session-view">
            <!-- Tab nav -->
            <div style="display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:24px;">
                <button type="button" class="hl-session-tab <?php echo $active_tab === 'details' ? 'hl-session-tab-active' : ''; ?>" data-tab="details">
                    <?php esc_html_e('Session Details', 'hl-core'); ?>
                </button>
                <?php if ($status === 'scheduled' || $is_completed) : ?>
                <button type="button" class="hl-session-tab <?php echo $active_tab === 'forms' ? 'hl-session-tab-active' : ''; ?>" data-tab="forms">
                    <?php esc_html_e('Action Plan & Results', 'hl-core'); ?>
                </button>
                <?php endif; ?>
            </div>

            <!-- Details Tab -->
            <div class="hl-session-tab-content" id="hl-tab-details" style="<?php echo $active_tab !== 'details' ? 'display:none;' : ''; ?>">
                <!-- Status Badge -->
                <div style="margin-bottom:20px;">
                    <?php if ($is_completed) : ?>
                        <span style="display:inline-block;padding:4px 14px;border-radius:12px;font-size:13px;font-weight:600;background:#e6f4ea;color:#137333;">
                            <?php esc_html_e('Completed', 'hl-core'); ?>
                        </span>
                    <?php elseif ($status === 'scheduled') : ?>
                        <span style="display:inline-block;padding:4px 14px;border-radius:12px;font-size:13px;font-weight:600;background:#dbeafe;color:#1e40af;">
                            <?php esc_html_e('Scheduled', 'hl-core'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Date/Time -->
                <?php if (!empty($display_time)) : ?>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                    <span class="dashicons dashicons-calendar-alt" style="color:#4a90d9;"></span>
                    <span style="font-size:16px;color:#1e3a5f;font-weight:500;"><?php echo esc_html($display_time); ?></span>
                </div>
                <?php endif; ?>

                <!-- Coach -->
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                    <span class="dashicons dashicons-admin-users" style="color:#4a90d9;"></span>
                    <span style="font-size:15px;color:#374151;">
                        <?php printf(esc_html__('Coach: %s', 'hl-core'), esc_html($session['coach_display_name'] ?? $session['coach_name'] ?? '')); ?>
                    </span>
                </div>

                <!-- Zoom Link -->
                <?php if (!empty($session['meeting_url']) && $status === 'scheduled') : ?>
                <div style="margin:20px 0;">
                    <a href="<?php echo esc_url($session['meeting_url']); ?>" target="_blank"
                       style="display:inline-block;background:#2d8cff;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;font-size:15px;">
                        <span class="dashicons dashicons-video-alt2" style="margin-right:6px;vertical-align:middle;"></span>
                        <?php esc_html_e('Join Zoom Meeting', 'hl-core'); ?>
                    </a>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <?php if ($status === 'scheduled') : ?>
                <div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap;">
                    <?php if ($can_modify && ($is_mentor || $is_coach || $is_admin)) : ?>
                        <?php
                        $reschedule_url = add_query_arg(array(
                            'id'         => $component->component_id,
                            'enrollment' => $enrollment->enrollment_id,
                            'reschedule' => $session_id,
                        ));
                        ?>
                        <a href="<?php echo esc_url($reschedule_url); ?>" class="button">
                            <?php esc_html_e('Reschedule', 'hl-core'); ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($can_modify && ($is_coach || $is_admin)) : ?>
                        <button type="button" id="hl-cancel-btn" class="button" style="color:#c5221f;border-color:#c5221f;">
                            <?php esc_html_e('Cancel Session', 'hl-core'); ?>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Cancel confirmation -->
                <div id="hl-cancel-confirm" style="display:none;margin-top:16px;padding:16px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">
                    <p style="margin:0 0 12px;color:#991b1b;font-weight:500;"><?php esc_html_e('Are you sure you want to cancel this session?', 'hl-core'); ?></p>
                    <button type="button" id="hl-cancel-yes" class="button" style="background:#c5221f;color:#fff;border-color:#c5221f;">
                        <?php esc_html_e('Yes, Cancel', 'hl-core'); ?>
                    </button>
                    <button type="button" id="hl-cancel-no" class="button" style="margin-left:8px;">
                        <?php esc_html_e('No, Keep It', 'hl-core'); ?>
                    </button>
                    <span id="hl-cancel-status" style="margin-left:12px;font-size:14px;"></span>
                </div>

                <script>
                (function() {
                    var cancelBtn = document.getElementById('hl-cancel-btn');
                    var confirmEl = document.getElementById('hl-cancel-confirm');
                    if (!cancelBtn) return;

                    cancelBtn.addEventListener('click', function() { confirmEl.style.display = 'block'; });
                    document.getElementById('hl-cancel-no').addEventListener('click', function() { confirmEl.style.display = 'none'; });

                    document.getElementById('hl-cancel-yes').addEventListener('click', function() {
                        var btn = this;
                        var status = document.getElementById('hl-cancel-status');
                        btn.disabled = true;
                        status.textContent = '<?php echo esc_js(__('Cancelling...', 'hl-core')); ?>';

                        var fd = new FormData();
                        fd.append('action', 'hl_cancel_session');
                        fd.append('_nonce', '<?php echo esc_js($nonce); ?>');
                        fd.append('session_id', <?php echo (int) $session_id; ?>);

                        fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                location.reload();
                            } else {
                                status.textContent = data.data.message || 'Failed';
                                status.style.color = '#c5221f';
                                btn.disabled = false;
                            }
                        });
                    });
                })();
                </script>
                <?php endif; ?>
            </div>

            <!-- Action Plan Tab -->
            <div class="hl-session-tab-content" id="hl-tab-forms" style="<?php echo $active_tab !== 'forms' ? 'display:none;' : ''; ?>">
                <?php $this->render_session_forms($session, $enrollment); ?>
            </div>
        </div>

        <script>
        (function() {
            document.querySelectorAll('.hl-session-tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.hl-session-tab').forEach(function(t) { t.classList.remove('hl-session-tab-active'); });
                    document.querySelectorAll('.hl-session-tab-content').forEach(function(c) { c.style.display = 'none'; });
                    this.classList.add('hl-session-tab-active');
                    document.getElementById('hl-tab-' + this.getAttribute('data-tab')).style.display = 'block';
                });
            });
        })();
        </script>

        <?php $this->render_scheduled_css(); ?>
        <?php
    }

    // =========================================================================
    // Session Forms (Action Plan & Results)
    // =========================================================================

    private function render_session_forms($session, $enrollment) {
        $session_id = (int) $session['session_id'];

        // Load existing submissions.
        $coaching_service = new HL_Coaching_Service();
        $submissions = $coaching_service->get_submissions_for_session($session_id);

        if (empty($submissions)) {
            echo '<div class="hl-empty-state" style="text-align:center;padding:30px;color:#94a3b8;">';
            echo '<span class="dashicons dashicons-clipboard" style="font-size:36px;display:block;margin-bottom:8px;"></span>';
            echo '<p>' . esc_html__('Action plan and session notes will appear here once submitted.', 'hl-core') . '</p>';
            echo '</div>';
            return;
        }

        foreach ($submissions as $sub) {
            echo '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:12px;">';
            echo '<div style="font-weight:600;color:#1e3a5f;margin-bottom:8px;">' . esc_html($sub['form_type'] ?? 'Submission') . '</div>';
            if (!empty($sub['submitted_data'])) {
                $data = is_string($sub['submitted_data']) ? json_decode($sub['submitted_data'], true) : $sub['submitted_data'];
                if (is_array($data)) {
                    foreach ($data as $key => $value) {
                        echo '<div style="margin-bottom:4px;"><span style="color:#64748b;font-size:13px;">' . esc_html(ucwords(str_replace('_', ' ', $key))) . ':</span> ';
                        echo '<span style="color:#374151;">' . esc_html(is_array($value) ? implode(', ', $value) : $value) . '</span></div>';
                    }
                }
            }
            echo '</div>';
        }
    }

    // =========================================================================
    // Drip Rule Check
    // =========================================================================

    private function check_drip_lock($component_id, $enrollment_id) {
        global $wpdb;

        $rule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_component_drip_rule WHERE component_id = %d LIMIT 1",
            $component_id
        ), ARRAY_A);

        if (!$rule) {
            return array('locked' => false, 'message' => '');
        }

        $now = current_time('mysql');

        if ($rule['drip_type'] === 'fixed_date' && !empty($rule['release_at_date'])) {
            if ($rule['release_at_date'] > $now) {
                try {
                    $dt = new DateTime($rule['release_at_date'], wp_timezone());
                    $display = $dt->format('F j, Y');
                } catch (Exception $e) {
                    $display = $rule['release_at_date'];
                }
                return array(
                    'locked'  => true,
                    'message' => sprintf(__('This session will be available on %s.', 'hl-core'), $display),
                );
            }
        }

        if ($rule['drip_type'] === 'after_completion_delay' && !empty($rule['base_component_id'])) {
            $base_completed = $wpdb->get_var($wpdb->prepare(
                "SELECT completed_at FROM {$wpdb->prefix}hl_component_state
                 WHERE component_id = %d AND enrollment_id = %d AND completion_status = 'complete'",
                $rule['base_component_id'], $enrollment_id
            ));

            if (!$base_completed) {
                return array(
                    'locked'  => true,
                    'message' => __('This session requires completion of a prerequisite component.', 'hl-core'),
                );
            }

            $delay_days = (int) ($rule['delay_days'] ?? 0);
            if ($delay_days > 0) {
                try {
                    $release = new DateTime($base_completed, wp_timezone());
                    $release->modify('+' . $delay_days . ' days');
                    $now_dt = new DateTime($now, wp_timezone());
                    if ($now_dt < $release) {
                        return array(
                            'locked'  => true,
                            'message' => sprintf(__('This session will be available on %s.', 'hl-core'), $release->format('F j, Y')),
                        );
                    }
                } catch (Exception $e) {
                    // Fall through to unlocked.
                }
            }
        }

        return array('locked' => false, 'message' => '');
    }

    // =========================================================================
    // CSS
    // =========================================================================

    private function render_scheduling_css() {
        ?>
        <style>
        .hl-cal-day {
            padding: 8px 4px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            color: #374151;
            transition: background 0.15s, color 0.15s;
        }
        .hl-cal-day:hover:not(.hl-cal-disabled) { background: #dbeafe; color: #1e40af; }
        .hl-cal-disabled { color: #d1d5db; cursor: default; }
        .hl-cal-selected { background: #1e3a5f !important; color: #fff !important; font-weight: 600; }
        .hl-slot-pill {
            padding: 8px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 20px;
            background: #fff;
            color: #374151;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .hl-slot-pill:hover { border-color: #4a90d9; color: #1e3a5f; }
        .hl-slot-selected { background: #1e3a5f !important; color: #fff !important; border-color: #1e3a5f !important; font-weight: 600; }
        </style>
        <?php
    }

    private function render_scheduled_css() {
        ?>
        <style>
        .hl-session-tab {
            padding: 10px 20px;
            border: none;
            background: none;
            font-size: 14px;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: color 0.15s, border-color 0.15s;
        }
        .hl-session-tab:hover { color: #1e3a5f; }
        .hl-session-tab-active { color: #1e3a5f; border-bottom-color: #1e3a5f; font-weight: 600; }
        </style>
        <?php
    }
}
