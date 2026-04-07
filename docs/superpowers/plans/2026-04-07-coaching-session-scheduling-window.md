# Coaching Session Scheduling Window — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a scheduling window (start + end date) to coaching session components that restricts the frontend calendar date range and displays informational labels.

**Architecture:** Two new nullable DATE columns on `hl_component`. Admin form gets a conditional "Scheduling Window" section for coaching types. Frontend calendar JS clamps selectable dates to the window. Program/component pages show a scheduling window label.

**Tech Stack:** PHP 7.4+, WordPress, vanilla JS (existing calendar), MySQL via `dbDelta`

**Spec:** `docs/superpowers/specs/2026-04-07-coaching-session-scheduling-window-design.md`

---

### Task 1: Add database columns + domain class properties

**Files:**
- Modify: `includes/class-hl-installer.php:1426-1451` (hl_component CREATE TABLE)
- Modify: `includes/domain/class-hl-component.php:5-22` (properties)

- [ ] **Step 1: Add columns to hl_component schema**

In `includes/class-hl-installer.php`, inside the `hl_component` CREATE TABLE statement, add two columns after `complete_by` (line 1438):

```php
            complete_by date DEFAULT NULL COMMENT 'Suggested completion date (not enforced)',
            scheduling_window_start date DEFAULT NULL COMMENT 'Coaching sessions: earliest bookable date',
            scheduling_window_end date DEFAULT NULL COMMENT 'Coaching sessions: latest bookable date',
            visibility enum('all','staff_only') NOT NULL DEFAULT 'all',
```

- [ ] **Step 2: Add properties to HL_Component domain class**

In `includes/domain/class-hl-component.php`, add two properties after `$complete_by` (line 16):

```php
    public $complete_by;
    public $scheduling_window_start;
    public $scheduling_window_end;
    public $visibility;
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-hl-installer.php includes/domain/class-hl-component.php
git commit -m "feat(schema): add scheduling_window_start/end columns to hl_component"
```

---

### Task 2: Admin form — add Scheduling Window fields

**Files:**
- Modify: `includes/admin/class-hl-admin-pathways.php:1966-1987` (form render, after eligibility rules)
- Modify: `includes/admin/class-hl-admin-pathways.php:2159-2167` (JS fieldMap)

- [ ] **Step 1: Add Scheduling Window form fields**

In `includes/admin/class-hl-admin-pathways.php`, in `render_component_form()`, add a new conditional field row after the Eligibility Rules `</tr>` (line 1966) and before the Course Catalog selector (line 1968):

```php
        // --- Scheduling Window (coaching_session_attendance only) ---
        $sw_start = ($is_edit && !empty($component->scheduling_window_start)) ? $component->scheduling_window_start : '';
        $sw_end   = ($is_edit && !empty($component->scheduling_window_end)) ? $component->scheduling_window_end : '';

        echo '<tr class="hl-component-field hl-field-scheduling-window" style="display:none;">';
        echo '<th scope="row">' . esc_html__('Scheduling Window', 'hl-core') . '</th>';
        echo '<td>';
        echo '<label for="scheduling_window_start" style="margin-right:8px;">' . esc_html__('Start:', 'hl-core') . '</label>';
        echo '<input type="date" id="scheduling_window_start" name="scheduling_window_start" value="' . esc_attr($sw_start) . '" style="margin-right:16px;" />';
        echo '<label for="scheduling_window_end" style="margin-right:8px;">' . esc_html__('End:', 'hl-core') . '</label>';
        echo '<input type="date" id="scheduling_window_end" name="scheduling_window_end" value="' . esc_attr($sw_end) . '" />';
        echo '<p class="description">' . esc_html__('Date range during which this coaching session should be scheduled. Both are optional.', 'hl-core') . '</p>';
        echo '</td>';
        echo '</tr>';
```

- [ ] **Step 2: Update JS fieldMap to show Scheduling Window for coaching type**

In `render_component_form_js()`, update the `fieldMap` entry for `coaching_session_attendance` (line 2163):

```js
                'coaching_session_attendance':  ['hl-field-scheduling-window'],
```

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-pathways.php
git commit -m "feat(admin): add Scheduling Window fields to coaching component form"
```

---

### Task 3: Admin save logic — persist scheduling window dates

**Files:**
- Modify: `includes/admin/class-hl-admin-pathways.php:381-383` (save_component, after complete_by save)

- [ ] **Step 1: Save scheduling window fields**

In `save_component()`, after the `complete_by` line (line 382), add:

```php
        // Complete-by date.
        $data['complete_by'] = !empty($_POST['complete_by']) ? sanitize_text_field($_POST['complete_by']) : null;

        // Scheduling window (coaching sessions only).
        if ($component_type === 'coaching_session_attendance') {
            $data['scheduling_window_start'] = !empty($_POST['scheduling_window_start']) ? sanitize_text_field($_POST['scheduling_window_start']) : null;
            $data['scheduling_window_end']   = !empty($_POST['scheduling_window_end']) ? sanitize_text_field($_POST['scheduling_window_end']) : null;
        }
```

- [ ] **Step 2: Commit**

```bash
git add includes/admin/class-hl-admin-pathways.php
git commit -m "feat(admin): persist scheduling window dates on component save"
```

---

### Task 4: Frontend calendar — clamp date range to scheduling window

**Files:**
- Modify: `includes/frontend/class-hl-frontend-schedule-session.php:68-90` (render_scheduling_view, pass window data to JS)
- Modify: `includes/frontend/class-hl-frontend-schedule-session.php:207-218` (JS config object)
- Modify: `includes/frontend/class-hl-frontend-schedule-session.php:271-294` (renderCal function)

- [ ] **Step 1: Pass scheduling window to JS config**

In `render_scheduling_view()`, after loading `$max_lead_days` (line 88), load the scheduling window dates from the component:

```php
        $settings      = HL_Admin_Scheduling_Settings::get_scheduling_settings();
        $max_lead_days = (int) $settings['max_lead_time_days'];
        $duration      = (int) $settings['session_duration'];
        $sw_start      = !empty($component->scheduling_window_start) ? $component->scheduling_window_start : '';
        $sw_end        = !empty($component->scheduling_window_end) ? $component->scheduling_window_end : '';
        $coach_avatar  = get_avatar_url($coach_user_id, array('size' => 80));
```

Then in the JS config object `C` (around line 209-217), add the window dates:

```js
            var C = {
                ajax: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                nonce: '<?php echo esc_js($nonce); ?>',
                coach: <?php echo (int) $coach_user_id; ?>,
                enrollment: <?php echo (int) $enrollment_id; ?>,
                component: <?php echo (int) $component_id; ?>,
                reschedule: <?php echo (int) $reschedule_id; ?>,
                maxDays: <?php echo (int) $max_lead_days; ?>,
                tz: Intl.DateTimeFormat().resolvedOptions().timeZone,
                swStart: '<?php echo esc_js($sw_start); ?>',
                swEnd: '<?php echo esc_js($sw_end); ?>'
            };
```

- [ ] **Step 2: Add window-passed notice HTML**

In the scheduling view HTML, after the coach header `</div>` (line 111) and before the two-column layout `<div class="hls-booking-layout">` (line 114), add:

```php
            <?php if ($sw_start || $sw_end) : ?>
                <?php
                $window_label = '';
                $window_closed = false;
                $today = current_time('Y-m-d');
                if ($sw_start && $sw_end) {
                    $window_label = sprintf(
                        esc_html__('Schedule between %s – %s', 'hl-core'),
                        esc_html(date_i18n('M j', strtotime($sw_start))),
                        esc_html(date_i18n('M j, Y', strtotime($sw_end)))
                    );
                    if ($today > $sw_end) {
                        $window_closed = true;
                        $window_label = sprintf(
                            esc_html__('Scheduling window closed (%s – %s)', 'hl-core'),
                            esc_html(date_i18n('M j', strtotime($sw_start))),
                            esc_html(date_i18n('M j, Y', strtotime($sw_end)))
                        );
                    }
                } elseif ($sw_start) {
                    $window_label = sprintf(esc_html__('Available from %s', 'hl-core'), esc_html(date_i18n('M j, Y', strtotime($sw_start))));
                } elseif ($sw_end) {
                    $window_label = sprintf(esc_html__('Schedule by %s', 'hl-core'), esc_html(date_i18n('M j, Y', strtotime($sw_end))));
                    if ($today > $sw_end) {
                        $window_closed = true;
                        $window_label = sprintf(esc_html__('Scheduling window closed (by %s)', 'hl-core'), esc_html(date_i18n('M j, Y', strtotime($sw_end))));
                    }
                }
                ?>
                <div class="hls-window-notice<?php echo $window_closed ? ' hls-window-closed' : ''; ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?php echo $window_label; ?>
                </div>
            <?php endif; ?>
```

- [ ] **Step 3: Update renderCal() JS to clamp dates to scheduling window**

In the `renderCal()` function (line 271-294), replace the date boundary logic. The current code uses `now` and `max` to determine which days are selectable. Update it to incorporate the scheduling window:

Replace the current `renderCal` function:

```js
            function renderCal() {
                var g=document.getElementById('hls-calendar'), t=document.getElementById('hls-cal-title');
                var y=S.month.getFullYear(), m=S.month.getMonth();
                var now=new Date(); now.setHours(0,0,0,0);
                var max=new Date(); max.setDate(max.getDate()+C.maxDays);

                // Scheduling window overrides: clamp min/max dates
                var minDate = now;
                var maxDate = max;
                if (C.swStart) {
                    var sw0 = new Date(C.swStart + 'T00:00:00');
                    if (sw0 > minDate) minDate = sw0;
                }
                if (C.swEnd) {
                    var sw1 = new Date(C.swEnd + 'T00:00:00');
                    if (sw1 < maxDate) maxDate = sw1;
                }
                // If window has passed, open up all future dates (no max clamp)
                if (C.swEnd) {
                    var swEndDate = new Date(C.swEnd + 'T00:00:00');
                    if (now > swEndDate) {
                        minDate = now;
                        maxDate = max;
                    }
                }

                t.textContent=months[m]+' '+y;
                var h='';
                for(var i=0;i<7;i++) h+='<div class="hls-cal-head">'+days[i]+'</div>';
                var f=new Date(y,m,1).getDay(), n=new Date(y,m+1,0).getDate();
                for(var i=0;i<f;i++) h+='<div class="hls-cal-empty"></div>';
                for(var d=1;d<=n;d++){
                    var dt=new Date(y,m,d), ds=fmt(dt), past=dt<minDate, beyond=dt>maxDate, sel=S.date===ds;
                    var cls='hls-cal-day';
                    if(past||beyond) cls+=' hls-cal-off';
                    else cls+=' hls-cal-on';
                    if(sel) cls+=' hls-cal-sel';
                    if(dt.toDateString()===now.toDateString()) cls+=' hls-cal-today';
                    h+='<div class="'+cls+'"'+(past||beyond?'':' data-d="'+ds+'"')+'>'+d+'</div>';
                }
                g.innerHTML=h;
                g.querySelectorAll('[data-d]').forEach(function(el){
                    el.addEventListener('click',function(){ pickDate(this.getAttribute('data-d')); });
                });
            }
```

- [ ] **Step 4: Add CSS for the window notice**

In `render_styles()` (within the same file), add styles for the scheduling window notice:

```css
.hls-window-notice {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    margin: 0 0 16px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    color: #1e40af;
    font-size: 14px;
    font-weight: 500;
}
.hls-window-notice.hls-window-closed {
    background: #fef3c7;
    border-color: #fde68a;
    color: #92400e;
}
```

- [ ] **Step 5: Commit**

```bash
git add includes/frontend/class-hl-frontend-schedule-session.php
git commit -m "feat(frontend): clamp scheduling calendar to coaching session window"
```

---

### Task 5: Frontend display — scheduling window label on program page cards

**Files:**
- Modify: `includes/frontend/class-hl-frontend-program-page.php:1055-1065` (drip badge area in render_component_card_v2)

- [ ] **Step 1: Add scheduling window label to component cards**

In `render_component_card_v2()`, replace the drip badge block (lines 1055-1065) with logic that checks for scheduling window first for coaching sessions:

```php
        // Drip badge / Scheduling window badge.
        $drip_html = '';
        if ($component->component_type === 'coaching_session_attendance'
            && (!empty($component->scheduling_window_start) || !empty($component->scheduling_window_end))
        ) {
            $today = current_time('Y-m-d');
            $sw_start = $component->scheduling_window_start;
            $sw_end   = $component->scheduling_window_end;
            if ($sw_start && $sw_end) {
                if ($today > $sw_end && $avail_status !== 'completed') {
                    $drip_html = '<span class="hl-pp-drip-badge hl-pp-window-closed">'
                        . sprintf(esc_html__('Window closed (%s – %s)', 'hl-core'),
                            esc_html(date_i18n('M j', strtotime($sw_start))),
                            esc_html(date_i18n('M j, Y', strtotime($sw_end))))
                        . '</span>';
                } else {
                    $drip_html = '<span class="hl-pp-drip-badge">'
                        . sprintf(esc_html__('Schedule %s – %s', 'hl-core'),
                            esc_html(date_i18n('M j', strtotime($sw_start))),
                            esc_html(date_i18n('M j, Y', strtotime($sw_end))))
                        . '</span>';
                }
            } elseif ($sw_start) {
                $drip_html = '<span class="hl-pp-drip-badge">'
                    . sprintf(esc_html__('Available from %s', 'hl-core'), esc_html(date_i18n('M j, Y', strtotime($sw_start))))
                    . '</span>';
            } elseif ($sw_end) {
                if ($today > $sw_end && $avail_status !== 'completed') {
                    $drip_html = '<span class="hl-pp-drip-badge hl-pp-window-closed">'
                        . sprintf(esc_html__('Window closed (by %s)', 'hl-core'), esc_html(date_i18n('M j, Y', strtotime($sw_end))))
                        . '</span>';
                } else {
                    $drip_html = '<span class="hl-pp-drip-badge">'
                        . sprintf(esc_html__('Schedule by %s', 'hl-core'), esc_html(date_i18n('M j, Y', strtotime($sw_end))))
                        . '</span>';
                }
            }
        } elseif ($avail_status === 'locked'
            && !empty($availability['locked_reason'])
            && $availability['locked_reason'] === 'drip'
            && !empty($availability['next_available_at'])
        ) {
            $drip_html = '<span class="hl-pp-drip-badge">'
                . sprintf(esc_html__('Available %s', 'hl-core'), esc_html($this->format_date($availability['next_available_at'])))
                . '</span>';
        }
```

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-program-page.php
git commit -m "feat(frontend): show scheduling window label on coaching session cards"
```

---

### Task 6: Deploy and verify on test server

- [ ] **Step 1: Deploy to test server**

Follow the deploy process in `.claude/skills/deploy.md` to push and deploy to the test server. The `dbDelta` migration will add the new columns automatically on plugin activation/upgrade.

- [ ] **Step 2: Verify admin form**

1. Navigate to a cycle → pathway → edit a coaching session component
2. Confirm the "Scheduling Window" section appears with Start and End date pickers
3. Confirm it is hidden for other component types (e.g., LearnDash Course)
4. Set a start and end date, save, confirm values persist on reload

- [ ] **Step 3: Verify frontend calendar**

1. Log in as a participant enrolled in the cycle
2. Navigate to the coaching session component
3. Confirm the scheduling window notice appears above the calendar
4. Confirm dates outside the window are greyed out / not selectable
5. Set an expired window (end date in the past) and confirm the "window closed" notice appears but booking still works

- [ ] **Step 4: Verify program page label**

1. Navigate to the program page
2. Confirm coaching session cards show "Schedule Mar 15 – Apr 30" style label
3. Confirm expired windows show "Window closed" label

- [ ] **Step 5: Update STATUS.md and README.md**

Per project rules, update both files to reflect the new feature.
