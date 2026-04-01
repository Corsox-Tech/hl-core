<?php
if (!defined('ABSPATH')) exit;

/**
 * Coaching Session Scheduling UI
 *
 * Renders inside the Component Page for coaching_session_attendance components.
 * Two states: scheduling (date picker + time slots) and scheduled (details + action plan).
 * Calendly-inspired design with clean calendar, polished time slots, and elegant flow.
 *
 * @package HL_Core
 */
class HL_Frontend_Schedule_Session {

    /**
     * Render the scheduling UI for a coaching session component.
     */
    public function render($component, $enrollment, $cycle_id) {
        global $wpdb;

        // Handle form submissions (Action Plan / RP Notes) before rendering.
        $this->handle_post_submissions();

        $component_id  = (int) $component->component_id;
        $enrollment_id = (int) $enrollment->enrollment_id;
        $user_id       = get_current_user_id();

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

        $reschedule_id = isset($_GET['reschedule']) ? absint($_GET['reschedule']) : 0;

        $this->render_styles();

        if ($session && !$reschedule_id) {
            $this->render_scheduled_view($session, $component, $enrollment, $coach_user_id);
        } else {
            $this->render_scheduling_view($component, $enrollment, $coach_user_id, $coach_name, $cycle_id, $reschedule_id);
        }
    }

    // =========================================================================
    // State A: Scheduling View
    // =========================================================================

    private function render_scheduling_view($component, $enrollment, $coach_user_id, $coach_name, $cycle_id, $reschedule_id) {
        $component_id  = (int) $component->component_id;
        $enrollment_id = (int) $enrollment->enrollment_id;
        $nonce         = wp_create_nonce('hl_scheduling_nonce');

        $lock_info = $this->check_drip_lock($component_id, $enrollment_id);
        if ($lock_info['locked']) {
            ?>
            <div class="hls-locked">
                <div class="hls-locked-icon">
                    <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <h3 class="hls-locked-title"><?php esc_html_e('Session Not Yet Available', 'hl-core'); ?></h3>
                <p class="hls-locked-msg"><?php echo esc_html($lock_info['message']); ?></p>
            </div>
            <?php
            return;
        }

        $settings      = HL_Admin_Scheduling_Settings::get_scheduling_settings();
        $max_lead_days = (int) $settings['max_lead_time_days'];
        $duration      = (int) $settings['session_duration'];
        $coach_avatar  = get_avatar_url($coach_user_id, array('size' => 80));
        ?>
        <div class="hls-scheduler" id="hls-scheduler">
            <?php if ($reschedule_id) : ?>
                <div class="hls-reschedule-banner">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    <?php esc_html_e('Rescheduling — select a new date and time', 'hl-core'); ?>
                </div>
            <?php endif; ?>

            <!-- Coach header -->
            <div class="hls-coach-header">
                <img src="<?php echo esc_url($coach_avatar); ?>" alt="" class="hls-coach-avatar">
                <div class="hls-coach-info">
                    <span class="hls-coach-label"><?php esc_html_e('Coaching Session', 'hl-core'); ?></span>
                    <span class="hls-coach-name"><?php echo esc_html($coach_name); ?></span>
                    <span class="hls-duration">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?php printf(esc_html__('%d min', 'hl-core'), $duration); ?>
                    </span>
                </div>
            </div>

            <!-- Two-column layout: calendar + slots -->
            <div class="hls-booking-layout">
                <!-- Left: Calendar -->
                <div class="hls-calendar-panel">
                    <h4 class="hls-section-title"><?php esc_html_e('Select a Date', 'hl-core'); ?></h4>
                    <div class="hls-cal-nav">
                        <button type="button" id="hls-cal-prev" class="hls-cal-arrow" aria-label="Previous month">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                        <span id="hls-cal-title" class="hls-cal-month"></span>
                        <button type="button" id="hls-cal-next" class="hls-cal-arrow" aria-label="Next month">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    </div>
                    <div id="hls-calendar" class="hls-cal-grid"></div>
                </div>

                <!-- Right: Time Slots -->
                <div class="hls-slots-panel" id="hls-slots-panel">
                    <div class="hls-tz-selector">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        <select id="hls-tz-select" aria-label="<?php esc_attr_e('Timezone', 'hl-core'); ?>"></select>
                    </div>
                    <div id="hls-slots-placeholder" class="hls-slots-placeholder">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <p><?php esc_html_e('Select a date to see available times', 'hl-core'); ?></p>
                    </div>
                    <div id="hls-slots-header" class="hls-slots-header" style="display:none;">
                        <h4 class="hls-section-title" id="hls-slots-date-label"></h4>
                    </div>
                    <div id="hls-slots-loading" class="hls-slots-loading" style="display:none;">
                        <div class="hls-spinner"></div>
                        <p><?php esc_html_e('Finding available times...', 'hl-core'); ?></p>
                    </div>
                    <div id="hls-slots-list" class="hls-slots-list" style="display:none;"></div>
                    <div id="hls-slots-empty" class="hls-slots-empty" style="display:none;">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        <p><?php esc_html_e('No available times on this date', 'hl-core'); ?></p>
                    </div>
                    <div id="hls-slots-warning" class="hls-slots-warning" style="display:none;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <?php esc_html_e('Could not verify calendar — some slots may conflict with existing meetings', 'hl-core'); ?>
                    </div>
                </div>
            </div>

            <!-- Confirmation bar -->
            <div id="hls-confirm" class="hls-confirm" style="display:none;">
                <div class="hls-confirm-details">
                    <div class="hls-confirm-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1e40af" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div>
                        <div class="hls-confirm-date" id="hls-confirm-date"></div>
                        <div class="hls-confirm-time" id="hls-confirm-time"></div>
                    </div>
                </div>
                <div class="hls-confirm-actions">
                    <span id="hls-booking-status" class="hls-booking-status"></span>
                    <button type="button" id="hls-confirm-btn" class="hls-book-btn">
                        <?php echo $reschedule_id
                            ? esc_html__('Confirm Reschedule', 'hl-core')
                            : esc_html__('Confirm Booking', 'hl-core'); ?>
                    </button>
                </div>
            </div>

            <!-- Success overlay -->
            <div id="hls-success" class="hls-success" style="display:none;">
                <div class="hls-success-inner">
                    <div class="hls-success-check">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <h2 class="hls-success-title"><?php esc_html_e('You\'re all set!', 'hl-core'); ?></h2>
                    <p class="hls-success-subtitle"><?php esc_html_e('Your coaching session has been booked.', 'hl-core'); ?></p>
                    <div class="hls-success-card">
                        <div class="hls-success-row">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <span><?php echo esc_html($coach_name); ?></span>
                        </div>
                        <div class="hls-success-row" id="hls-success-datetime">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <span></span>
                        </div>
                    </div>
                    <a id="hls-success-zoom" href="#" target="_blank" class="hls-zoom-btn" style="display:none;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                        <?php esc_html_e('Join Zoom Meeting', 'hl-core'); ?>
                    </a>
                    <a href="javascript:location.reload()" class="hls-view-details-link"><?php esc_html_e('View Session Details &rarr;', 'hl-core'); ?></a>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var C = {
                ajax: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                nonce: '<?php echo esc_js($nonce); ?>',
                coach: <?php echo (int) $coach_user_id; ?>,
                enrollment: <?php echo (int) $enrollment_id; ?>,
                component: <?php echo (int) $component_id; ?>,
                reschedule: <?php echo (int) $reschedule_id; ?>,
                maxDays: <?php echo (int) $max_lead_days; ?>,
                tz: Intl.DateTimeFormat().resolvedOptions().timeZone
            };
            var S = { month: new Date(), date: null, slot: null };
            var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

            /* ----- Timezone dropdown ----- */
            (function initTzDropdown() {
                var sel = document.getElementById('hls-tz-select');
                if (!sel) return;
                var commonTzs = ['America/New_York','America/Chicago','America/Denver','America/Los_Angeles','America/Anchorage','Pacific/Honolulu'];
                var allTzs;
                try { allTzs = Intl.supportedValuesOf('timeZone'); } catch(e) {
                    allTzs = commonTzs.concat(['America/Phoenix','America/Indiana/Indianapolis','America/Detroit','America/Boise','America/Juneau','America/Adak','America/Nome','America/Sitka','America/Yakutat','America/Metlakatla','Europe/London','Europe/Paris','Europe/Berlin','Europe/Moscow','Asia/Tokyo','Asia/Shanghai','Asia/Kolkata','Asia/Dubai','Australia/Sydney','Pacific/Auckland']);
                }
                function tzLabel(tz) {
                    try {
                        var short = new Intl.DateTimeFormat('en-US', {timeZone: tz, timeZoneName: 'short'}).formatToParts(new Date()).find(function(p){return p.type==='timeZoneName';});
                        return tz.replace(/_/g,' ') + (short ? ' (' + short.value + ')' : '');
                    } catch(e) { return tz; }
                }
                var commonGroup = document.createElement('optgroup');
                commonGroup.label = 'Common';
                commonTzs.forEach(function(tz) {
                    var opt = document.createElement('option');
                    opt.value = tz; opt.textContent = tzLabel(tz);
                    if (tz === C.tz) opt.selected = true;
                    commonGroup.appendChild(opt);
                });
                sel.appendChild(commonGroup);
                var allGroup = document.createElement('optgroup');
                allGroup.label = 'All Timezones';
                var commonSet = {};
                commonTzs.forEach(function(t){ commonSet[t] = true; });
                allTzs.forEach(function(tz) {
                    if (commonSet[tz]) return;
                    var opt = document.createElement('option');
                    opt.value = tz; opt.textContent = tzLabel(tz);
                    if (tz === C.tz) opt.selected = true;
                    allGroup.appendChild(opt);
                });
                sel.appendChild(allGroup);
                // If browser tz was not found in options, select first common.
                if (!sel.value || sel.selectedIndex < 0) { sel.value = commonTzs[0]; C.tz = commonTzs[0]; }
                else { C.tz = sel.value; }
                sel.addEventListener('change', function() {
                    C.tz = this.value;
                    if (S.date) pickDate(S.date);
                });
            })();

            function fmt(d) { return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
            function prettyDate(s) { var p=s.split('-'); var d=new Date(+p[0],p[1]-1,+p[2]); return days[d.getDay()]+', '+months[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear(); }

            function renderCal() {
                var g=document.getElementById('hls-calendar'), t=document.getElementById('hls-cal-title');
                var y=S.month.getFullYear(), m=S.month.getMonth();
                var now=new Date(); now.setHours(0,0,0,0);
                var max=new Date(); max.setDate(max.getDate()+C.maxDays);
                t.textContent=months[m]+' '+y;
                var h='';
                for(var i=0;i<7;i++) h+='<div class="hls-cal-head">'+days[i]+'</div>';
                var f=new Date(y,m,1).getDay(), n=new Date(y,m+1,0).getDate();
                for(var i=0;i<f;i++) h+='<div class="hls-cal-empty"></div>';
                for(var d=1;d<=n;d++){
                    var dt=new Date(y,m,d), ds=fmt(dt), past=dt<now, beyond=dt>max, sel=S.date===ds;
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

            document.getElementById('hls-cal-prev').onclick=function(){ S.month.setMonth(S.month.getMonth()-1); renderCal(); };
            document.getElementById('hls-cal-next').onclick=function(){ S.month.setMonth(S.month.getMonth()+1); renderCal(); };

            function pickDate(ds) {
                S.date=ds; S.slot=null;
                hide('hls-confirm');
                renderCal();
                var hdr=document.getElementById('hls-slots-header');
                document.getElementById('hls-slots-date-label').textContent=prettyDate(ds);
                show('hls-slots-header'); show('hls-slots-loading');
                hide('hls-slots-placeholder'); hide('hls-slots-list'); hide('hls-slots-empty'); hide('hls-slots-warning');

                fetch(C.ajax+'?action=hl_get_available_slots&_nonce='+C.nonce+'&coach_user_id='+C.coach+'&date='+ds+'&timezone='+encodeURIComponent(C.tz))
                .then(function(r){return r.json();})
                .then(function(r){
                    hide('hls-slots-loading');
                    if(!r.success){document.getElementById('hls-slots-empty').querySelector('p').textContent=r.data&&r.data.message||'Error';show('hls-slots-empty');return;}
                    var sl=r.data.slots||[];
                    if(r.data.outlook_unavailable) show('hls-slots-warning');
                    if(!sl.length){show('hls-slots-empty');return;}
                    var list=document.getElementById('hls-slots-list');
                    list.innerHTML='';
                    sl.forEach(function(s){
                        var b=document.createElement('button');
                        b.type='button'; b.className='hls-slot';
                        b.innerHTML='<span class="hls-slot-time">'+s.display_label+'</span>';
                        b.setAttribute('data-s',s.start_time); b.setAttribute('data-l',s.display_label);
                        b.onclick=function(){pickSlot(this);};
                        list.appendChild(b);
                    });
                    show('hls-slots-list');
                })
                .catch(function(){hide('hls-slots-loading');show('hls-slots-empty');});
            }

            function pickSlot(el) {
                document.querySelectorAll('.hls-slot').forEach(function(b){b.classList.remove('hls-slot-sel');});
                el.classList.add('hls-slot-sel');
                S.slot=el.getAttribute('data-s');
                document.getElementById('hls-confirm-date').textContent=prettyDate(S.date);
                document.getElementById('hls-confirm-time').textContent=el.getAttribute('data-l');
                show('hls-confirm');
                document.getElementById('hls-confirm').scrollIntoView({behavior:'smooth',block:'nearest'});
            }

            document.getElementById('hls-confirm-btn').onclick=function(){
                if(!S.date||!S.slot)return;
                var btn=this,st=document.getElementById('hls-booking-status');
                btn.disabled=true; btn.classList.add('hls-btn-loading');
                st.textContent='';
                var fd=new FormData();
                fd.append('action',C.reschedule?'hl_reschedule_session':'hl_book_session');
                fd.append('_nonce',C.nonce); fd.append('date',S.date); fd.append('start_time',S.slot); fd.append('timezone',C.tz);
                if(C.reschedule){fd.append('session_id',C.reschedule);}
                else{fd.append('mentor_enrollment_id',C.enrollment);fd.append('coach_user_id',C.coach);fd.append('component_id',C.component);}
                fetch(C.ajax,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(r){
                    if(r.success){
                        document.getElementById('hls-success-datetime').querySelector('span').textContent=prettyDate(S.date)+' at '+document.getElementById('hls-confirm-time').textContent;
                        if(r.data.meeting_url){var z=document.getElementById('hls-success-zoom');z.href=r.data.meeting_url;z.style.display='inline-flex';}
                        hide('hls-confirm');
                        document.querySelector('.hls-booking-layout').style.display='none';
                        document.querySelector('.hls-coach-header').style.display='none';
                        var rb=document.querySelector('.hls-reschedule-banner');if(rb)rb.style.display='none';
                        show('hls-success');
                    }else{
                        st.textContent=r.data&&r.data.message||'Booking failed';st.style.color='#dc2626';
                        btn.disabled=false;btn.classList.remove('hls-btn-loading');
                    }
                }).catch(function(){st.textContent='Request failed';st.style.color='#dc2626';btn.disabled=false;btn.classList.remove('hls-btn-loading');});
            };

            function show(id){document.getElementById(id).style.display='';}
            function hide(id){document.getElementById(id).style.display='none';}
            renderCal();
        })();
        </script>
        <?php
    }

    // =========================================================================
    // State B: Scheduled/Completed View
    // =========================================================================

    private function render_scheduled_view($session, $component, $enrollment, $coach_user_id) {
        $session_id   = (int) $session['session_id'];
        $status       = $session['session_status'];
        $is_completed = ($status === 'attended');
        $is_missed    = ($status === 'missed');
        $nonce        = wp_create_nonce('hl_scheduling_nonce');
        $current_user = get_current_user_id();
        $is_admin     = current_user_can('manage_hl_core');
        $is_coach     = ($current_user === (int) $session['coach_user_id']);
        $is_mentor    = ($current_user === (int) $enrollment->user_id);

        $display_date = '';
        $display_time_short = '';
        $display_full = '';
        if (!empty($session['session_datetime'])) {
            try {
                $wp_tz     = wp_timezone();
                $dt        = new DateTime($session['session_datetime'], $wp_tz);
                $mentor_tz = $session['mentor_timezone'] ?? wp_timezone_string();
                $dt->setTimezone(new DateTimeZone($mentor_tz));
                $display_date       = $dt->format('l, F j, Y');
                $display_time_short = $dt->format('g:i A T');
                $display_full       = $display_date . ' at ' . $display_time_short;
            } catch (Exception $e) {
                $display_full = $session['session_datetime'];
            }
        }

        $can_modify = false;
        $is_past    = false;
        if ($status === 'scheduled' && !empty($session['session_datetime'])) {
            $settings         = HL_Admin_Scheduling_Settings::get_scheduling_settings();
            $min_cancel_hours = (int) $settings['min_cancel_notice_hours'];
            $duration_min     = (int) $settings['session_duration'];
            $wp_tz            = wp_timezone();
            $session_time     = new DateTime($session['session_datetime'], $wp_tz);
            $session_end      = clone $session_time;
            $session_end->modify('+' . $duration_min . ' minutes');
            $now              = new DateTime('now', $wp_tz);
            $diff_hours       = ($session_time->getTimestamp() - $now->getTimestamp()) / 3600;
            $can_modify       = ($diff_hours >= $min_cancel_hours);
            $is_past          = ($now > $session_end);
        }

        $active_tab    = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'details';
        $coach_name    = $session['coach_display_name'] ?? $session['coach_name'] ?? '';
        $coach_avatar  = get_avatar_url($session['coach_user_id'], array('size' => 80));
        $duration      = (int) (HL_Admin_Scheduling_Settings::get_scheduling_settings()['session_duration'] ?? 30);
        ?>
        <div class="hls-session">
            <!-- Tab nav -->
            <div class="hls-tabs">
                <button type="button" class="hls-tab <?php echo $active_tab === 'details' ? 'hls-tab-on' : ''; ?>" data-tab="details">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?php esc_html_e('Session Details', 'hl-core'); ?>
                </button>
                <?php if ($status === 'scheduled' || $is_completed) : ?>
                <button type="button" class="hls-tab <?php echo $active_tab === 'forms' ? 'hls-tab-on' : ''; ?>" data-tab="forms">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <?php esc_html_e('Action Plan & Results', 'hl-core'); ?>
                </button>
                <?php endif; ?>
            </div>

            <!-- Details Tab -->
            <div class="hls-panel" id="hls-p-details" style="<?php echo $active_tab !== 'details' ? 'display:none;' : ''; ?>">
                <div class="hls-detail-card">
                    <!-- Status -->
                    <div class="hls-detail-status">
                        <?php if ($is_completed) : ?>
                            <span class="hls-badge hls-badge-green">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php esc_html_e('Completed', 'hl-core'); ?>
                            </span>
                        <?php elseif ($is_missed) : ?>
                            <span class="hls-badge hls-badge-red">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                <?php esc_html_e('No-Show', 'hl-core'); ?>
                            </span>
                        <?php else : ?>
                            <span class="hls-badge hls-badge-blue">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <?php esc_html_e('Scheduled', 'hl-core'); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Info rows -->
                    <div class="hls-detail-rows">
                        <div class="hls-detail-row">
                            <div class="hls-detail-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </div>
                            <div>
                                <div class="hls-detail-label"><?php esc_html_e('Date & Time', 'hl-core'); ?></div>
                                <div class="hls-detail-value"><?php echo esc_html($display_date); ?></div>
                                <div class="hls-detail-sub"><?php echo esc_html($display_time_short); ?> &middot; <?php printf(esc_html__('%d minutes', 'hl-core'), $duration); ?></div>
                            </div>
                        </div>
                        <div class="hls-detail-row">
                            <div class="hls-detail-icon">
                                <img src="<?php echo esc_url($coach_avatar); ?>" alt="" class="hls-detail-avatar">
                            </div>
                            <div>
                                <div class="hls-detail-label"><?php esc_html_e('Coach', 'hl-core'); ?></div>
                                <div class="hls-detail-value"><?php echo esc_html($coach_name); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Zoom button -->
                    <?php if (!empty($session['meeting_url']) && $status === 'scheduled') : ?>
                    <a href="<?php echo esc_url($session['meeting_url']); ?>" target="_blank" class="hls-zoom-btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                        <?php esc_html_e('Join Zoom Meeting', 'hl-core'); ?>
                    </a>
                    <?php endif; ?>

                    <!-- Action buttons -->
                    <?php if ($status === 'scheduled') : ?>
                    <div class="hls-detail-actions">
                        <?php if ($can_modify && ($is_mentor || $is_coach || $is_admin)) :
                            $reschedule_url = add_query_arg(array('id' => $component->component_id, 'enrollment' => $enrollment->enrollment_id, 'reschedule' => $session_id));
                        ?>
                            <a href="<?php echo esc_url($reschedule_url); ?>" class="hls-action-btn">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                                <?php esc_html_e('Reschedule', 'hl-core'); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($can_modify && ($is_coach || $is_admin)) : ?>
                            <button type="button" id="hls-cancel-btn" class="hls-action-btn hls-action-danger">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                <?php esc_html_e('Cancel', 'hl-core'); ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <div id="hls-cancel-confirm" class="hls-cancel-box" style="display:none;">
                        <p><?php esc_html_e('Are you sure you want to cancel this session? This cannot be undone.', 'hl-core'); ?></p>
                        <div class="hls-cancel-btns">
                            <button type="button" id="hls-cancel-yes" class="hls-cancel-confirm-btn"><?php esc_html_e('Yes, Cancel Session', 'hl-core'); ?></button>
                            <button type="button" id="hls-cancel-no" class="hls-action-btn"><?php esc_html_e('Keep It', 'hl-core'); ?></button>
                        </div>
                        <span id="hls-cancel-status" class="hls-booking-status"></span>
                    </div>

                    <script>
                    (function(){
                        var cb=document.getElementById('hls-cancel-btn'),cx=document.getElementById('hls-cancel-confirm');
                        if(!cb)return;
                        cb.onclick=function(){cx.style.display='block';};
                        document.getElementById('hls-cancel-no').onclick=function(){cx.style.display='none';};
                        document.getElementById('hls-cancel-yes').onclick=function(){
                            var b=this,s=document.getElementById('hls-cancel-status');
                            b.disabled=true;s.textContent='<?php echo esc_js(__('Cancelling...', 'hl-core')); ?>';
                            var fd=new FormData();fd.append('action','hl_cancel_session');fd.append('_nonce','<?php echo esc_js($nonce); ?>');fd.append('session_id',<?php echo (int)$session_id; ?>);
                            fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(r){if(r.success)location.reload();else{s.textContent=r.data.message||'Failed';s.style.color='#dc2626';b.disabled=false;}});
                        };
                    })();
                    </script>

                    <?php if ($is_past && ($is_coach || $is_admin)) : ?>
                    <!-- Attendance reporting (coach/admin, after session time) -->
                    <div class="hls-attendance-box">
                        <div class="hls-attendance-header">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                            <span><?php esc_html_e('How did this session go?', 'hl-core'); ?></span>
                        </div>
                        <div class="hls-attendance-btns">
                            <button type="button" class="hls-attend-btn hls-attend-yes" data-status="attended">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php esc_html_e('Attended', 'hl-core'); ?>
                            </button>
                            <button type="button" class="hls-attend-btn hls-attend-no" data-status="missed">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                <?php esc_html_e('No-Show', 'hl-core'); ?>
                            </button>
                        </div>
                        <span id="hls-attend-status" class="hls-booking-status"></span>
                    </div>
                    <script>
                    (function(){
                        document.querySelectorAll('.hls-attend-btn').forEach(function(btn){
                            btn.onclick=function(){
                                var st=document.getElementById('hls-attend-status');
                                document.querySelectorAll('.hls-attend-btn').forEach(function(b){b.disabled=true;});
                                st.textContent='<?php echo esc_js(__('Saving...', 'hl-core')); ?>';st.style.color='#64748b';
                                var fd=new FormData();
                                fd.append('action','hl_mark_attendance');
                                fd.append('_nonce','<?php echo esc_js($nonce); ?>');
                                fd.append('session_id',<?php echo (int)$session_id; ?>);
                                fd.append('attendance',this.getAttribute('data-status'));
                                fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>',{method:'POST',body:fd})
                                .then(function(r){return r.json();})
                                .then(function(r){
                                    if(r.success){location.reload();}
                                    else{st.textContent=r.data.message||'Failed';st.style.color='#dc2626';document.querySelectorAll('.hls-attend-btn').forEach(function(b){b.disabled=false;});}
                                });
                            };
                        });
                    })();
                    </script>
                    <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>

            <!-- Action Plan Tab -->
            <div class="hls-panel" id="hls-p-forms" style="<?php echo $active_tab !== 'forms' ? 'display:none;' : ''; ?>">
                <?php $this->render_session_forms($session, $enrollment); ?>
            </div>
        </div>

        <script>
        (function(){
            document.querySelectorAll('.hls-tab').forEach(function(t){
                t.onclick=function(){
                    document.querySelectorAll('.hls-tab').forEach(function(x){x.classList.remove('hls-tab-on');});
                    document.querySelectorAll('.hls-panel').forEach(function(p){p.style.display='none';});
                    this.classList.add('hls-tab-on');
                    document.getElementById('hls-p-'+this.getAttribute('data-tab')).style.display='';
                };
            });
        })();
        </script>
        <?php
    }

    // =========================================================================
    // Session Forms
    // =========================================================================

    private function render_session_forms($session, $enrollment) {
        global $wpdb;

        $session_id  = (int) $session['session_id'];
        $user_id     = get_current_user_id();
        $is_admin    = current_user_can('manage_hl_core');
        $is_coach    = ($user_id === (int) $session['coach_user_id']);
        $is_mentor   = ($user_id === (int) $enrollment->user_id);

        // Load instruments.
        $action_plan_instrument = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s AND status = 'active'",
            'coaching_action_plan'
        ));
        $rp_notes_instrument = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_teacher_assessment_instrument WHERE instrument_key = %s AND status = 'active'",
            'coaching_rp_notes'
        ));

        // Load existing submissions.
        $coaching_service = new HL_Coaching_Service();
        $submissions      = $coaching_service->get_submissions($session_id);
        $supervisee_sub   = null;
        $supervisor_sub   = null;
        foreach ($submissions as $sub) {
            if ($sub['role_in_session'] === 'supervisee') $supervisee_sub = $sub;
            if ($sub['role_in_session'] === 'supervisor') $supervisor_sub = $sub;
        }

        // Determine which form to show based on role.
        if ($is_mentor) {
            // Mentor sees Action Plan form.
            if ($action_plan_instrument) {
                $renderer = new HL_Frontend_Action_Plan();
                echo $renderer->render('coaching', $session, $enrollment, $action_plan_instrument, $supervisee_sub);
            } else {
                echo '<div class="hls-empty-forms">';
                echo '<p>' . esc_html__('Action Plan instrument not configured. Please contact your administrator.', 'hl-core') . '</p>';
                echo '</div>';
            }
        } elseif ($is_coach) {
            // Coach sees RP Notes form + read-only Action Plan.
            if ($rp_notes_instrument) {
                $mentor_enrollment_id = (int) $session['mentor_enrollment_id'];
                $cycle_id = (int) $session['cycle_id'];
                $renderer = new HL_Frontend_RP_Notes();
                echo $renderer->render('coaching', $session, $enrollment, $rp_notes_instrument, $mentor_enrollment_id, $cycle_id, $supervisor_sub);
            }
            if ($action_plan_instrument && $supervisee_sub) {
                echo '<div style="margin-top:24px;">';
                echo '<h4 style="font-size:15px;font-weight:700;color:#334155;margin:0 0 12px;">' . esc_html__('Mentor\'s Action Plan', 'hl-core') . '</h4>';
                $renderer = new HL_Frontend_Action_Plan();
                echo $renderer->render('coaching', $session, $enrollment, $action_plan_instrument, $supervisee_sub);
                echo '</div>';
            }
        } elseif ($is_admin) {
            // Admin sees both read-only.
            if ($rp_notes_instrument) {
                echo '<h4 style="font-size:15px;font-weight:700;color:#334155;margin:0 0 12px;">' . esc_html__('Coach RP Notes', 'hl-core') . '</h4>';
                if ($supervisor_sub) {
                    $this->render_submission_readonly($supervisor_sub);
                } else {
                    echo '<p style="color:#94a3b8;font-size:14px;margin-bottom:24px;">' . esc_html__('Not yet submitted.', 'hl-core') . '</p>';
                }
            }
            if ($action_plan_instrument) {
                echo '<h4 style="font-size:15px;font-weight:700;color:#334155;margin:0 0 12px;">' . esc_html__('Mentor Action Plan', 'hl-core') . '</h4>';
                if ($supervisee_sub) {
                    $this->render_submission_readonly($supervisee_sub);
                } else {
                    echo '<p style="color:#94a3b8;font-size:14px;">' . esc_html__('Not yet submitted.', 'hl-core') . '</p>';
                }
            }
        } else {
            echo '<div class="hls-empty-forms">';
            echo '<p>' . esc_html__('You do not have permission to view forms for this session.', 'hl-core') . '</p>';
            echo '</div>';
        }
    }

    /**
     * Render a submission as read-only key-value display.
     */
    private function render_submission_readonly($sub) {
        if (empty($sub['responses_json'])) {
            return;
        }
        $data = json_decode($sub['responses_json'], true);
        if (!is_array($data)) {
            return;
        }
        echo '<div class="hls-form-card">';
        foreach ($data as $key => $value) {
            echo '<div class="hls-form-row"><span class="hls-form-label">' . esc_html(ucwords(str_replace('_', ' ', $key))) . '</span>';
            echo '<span class="hls-form-value">' . esc_html(is_array($value) ? implode(', ', $value) : $value) . '</span></div>';
        }
        echo '</div>';
    }

    // =========================================================================
    // Form Submission Handler
    // =========================================================================

    private function handle_post_submissions() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $result = null;

        // RP Notes submission (coach).
        if (!empty($_POST['hl_rp_notes_nonce'])) {
            $result = HL_Frontend_RP_Notes::handle_submission('coaching');
        }

        // Action Plan submission (mentor).
        if (!empty($_POST['hl_action_plan_nonce'])) {
            $result = HL_Frontend_Action_Plan::handle_submission('coaching');
        }

        if ($result && !is_wp_error($result)) {
            $redirect = add_query_arg(array('message' => $result['message'], 'tab' => 'forms'));
            while (ob_get_level()) { ob_end_clean(); }
            if (!headers_sent()) {
                wp_safe_redirect($redirect);
                exit;
            }
            echo '<script>window.location.href=' . wp_json_encode($redirect) . ';</script>';
            exit;
        }

        if (is_wp_error($result)) {
            echo '<div class="hl-notice hl-notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
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
                return array('locked' => true, 'message' => sprintf(__('This session will be available on %s.', 'hl-core'), $display));
            }
        }

        if ($rule['drip_type'] === 'after_completion_delay' && !empty($rule['base_component_id'])) {
            $base_completed = $wpdb->get_var($wpdb->prepare(
                "SELECT completed_at FROM {$wpdb->prefix}hl_component_state
                 WHERE component_id = %d AND enrollment_id = %d AND completion_status = 'complete'",
                $rule['base_component_id'], $enrollment_id
            ));

            if (!$base_completed) {
                return array('locked' => true, 'message' => __('This session requires completion of a prerequisite component.', 'hl-core'));
            }

            $delay_days = (int) ($rule['delay_days'] ?? 0);
            if ($delay_days > 0) {
                try {
                    $release = new DateTime($base_completed, wp_timezone());
                    $release->modify('+' . $delay_days . ' days');
                    if (new DateTime($now, wp_timezone()) < $release) {
                        return array('locked' => true, 'message' => sprintf(__('This session will be available on %s.', 'hl-core'), $release->format('F j, Y')));
                    }
                } catch (Exception $e) {}
            }
        }

        return array('locked' => false, 'message' => '');
    }

    // =========================================================================
    // Styles
    // =========================================================================

    private function render_styles() {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
        <style>
        /* ── Reset: override BuddyBoss / Elementor inherited styles ── */
        .hls-scheduler *,.hls-session *{box-sizing:border-box}
        .hls-scheduler button,.hls-session button{font-family:inherit;outline:none}
        .hls-scheduler a,.hls-session a{text-decoration:none}

        /* ── Base ── */
        .hls-scheduler,.hls-session{max-width:780px;margin:0 auto!important;padding:0!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;color:#1e293b;text-align:left}

        /* ── Locked ── */
        .hls-locked{text-align:center;padding:60px 20px}
        .hls-locked-icon{margin-bottom:20px}
        .hls-locked-title{font-size:20px;font-weight:700;color:#334155;margin:0 0 8px}
        .hls-locked-msg{font-size:15px;color:#64748b;margin:0}

        /* ── Reschedule banner ── */
        .hls-reschedule-banner{display:flex;align-items:center;gap:10px;padding:12px 18px;background:#fef3c7;border:1px solid #fde68a;border-radius:12px;color:#92400e;font-size:14px;font-weight:500;margin-bottom:20px}

        /* ── Coach header ── */
        .hls-coach-header{display:flex;align-items:center;gap:16px;padding:20px 24px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5a8e 100%);border-radius:16px;margin-bottom:24px;color:#fff}
        .hls-coach-avatar{width:64px;height:64px;border-radius:50%;border:3px solid rgba(255,255,255,.25);object-fit:cover}
        .hls-coach-info{display:flex;flex-direction:column;gap:2px}
        .hls-coach-label{font-size:12px;text-transform:uppercase;letter-spacing:.8px;opacity:.7;font-weight:600}
        .hls-coach-name{font-size:20px;font-weight:700}
        .hls-duration{display:inline-flex;align-items:center;gap:5px;font-size:13px;opacity:.8;margin-top:2px}

        /* ── Booking layout ── */
        .hls-booking-layout{display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start}
        @media(max-width:680px){.hls-booking-layout{grid-template-columns:1fr}}

        .hls-calendar-panel,.hls-slots-panel{background:#fff!important;border:1px solid #e8eaf0!important;border-radius:16px;padding:24px;min-height:340px}
        .hls-section-title{font-size:15px;font-weight:700;color:#334155;margin:0 0 16px}

        /* ── Calendar ── */
        .hls-cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
        .hls-cal-month{font-size:16px;font-weight:700;color:#1e293b}
        .hls-cal-arrow{background:#fff!important;border:1.5px solid #e2e8f0!important;border-radius:10px!important;width:38px;height:38px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#64748b!important;transition:all .15s;padding:0!important}
        .hls-cal-arrow:hover{background:#f1f5f9!important;border-color:#cbd5e1!important;color:#334155!important}
        .hls-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center}
        .hls-cal-head{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;padding:8px 0}
        .hls-cal-empty{padding:8px 0}
        .hls-cal-day{padding:10px 0;border-radius:10px;font-size:14px;font-weight:500;cursor:default;transition:all .15s;position:relative}
        .hls-cal-on{cursor:pointer;color:#334155}
        .hls-cal-on:hover{background:#ede9fe;color:#6366f1}
        .hls-cal-off{color:#d1d5db}
        .hls-cal-today{font-weight:700}
        .hls-cal-today::after{content:'';position:absolute;bottom:4px;left:50%;transform:translateX(-50%);width:4px;height:4px;border-radius:50%;background:#6366f1}
        .hls-cal-sel{background:#6366f1!important;color:#fff!important;font-weight:700;box-shadow:0 2px 8px rgba(99,102,241,.35)}
        .hls-cal-sel::after{background:#fff!important}

        /* ── Slots panel ── */
        .hls-slots-placeholder{display:flex;flex-direction:column;align-items:center;justify-content:center;height:260px;gap:12px;color:#94a3b8;font-size:14px}
        .hls-slots-placeholder p{margin:0}
        .hls-slots-header{margin-bottom:12px}
        .hls-slots-loading{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:40px 0;color:#64748b;font-size:14px}
        .hls-slots-loading p{margin:0}
        .hls-spinner{width:32px;height:32px;border:3px solid #e2e8f0;border-top-color:#6366f1;border-radius:50%;animation:hls-spin .7s linear infinite}
        @keyframes hls-spin{to{transform:rotate(360deg)}}
        .hls-slots-list{display:flex;flex-direction:column;gap:6px;max-height:280px;overflow-y:auto;padding-right:4px}
        .hls-slots-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;padding:40px 0;color:#94a3b8;font-size:14px}
        .hls-slots-empty p{margin:0}
        .hls-slots-warning{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#fef3c7;border-radius:8px;font-size:13px;color:#92400e;margin-top:12px}

        /* ── Timezone selector ── */
        .hls-tz-selector{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px}
        .hls-tz-selector svg{flex-shrink:0;color:#6366f1;opacity:.7}
        .hls-tz-selector select{flex:1;border:none;background:transparent;font-size:13px;font-weight:500;color:#334155;font-family:inherit;cursor:pointer;outline:none;-webkit-appearance:none;appearance:none;padding:2px 0}
        .hls-tz-selector select:focus{color:#6366f1}

        /* ── Slot button ── */
        .hls-slot{display:flex;align-items:center;justify-content:center;padding:12px 16px;border:1.5px solid #e2e8f0!important;border-radius:12px!important;background:#fff!important;cursor:pointer;transition:all .2s;font-family:inherit}
        .hls-slot:hover{border-color:#6366f1!important;background:#f5f3ff!important}
        .hls-slot-time{font-size:14px;font-weight:600;color:#334155!important}
        .hls-slot:hover .hls-slot-time{color:#6366f1!important}
        .hls-slot-sel{border-color:#6366f1!important;background:#6366f1!important}
        .hls-slot-sel .hls-slot-time{color:#fff!important}

        /* ── Confirm bar ── */
        .hls-confirm{margin-top:24px;padding:20px 24px;background:#f5f3ff;border:1px solid #e0e7ff;border-radius:14px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;animation:hls-slide .25s ease}
        @keyframes hls-slide{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
        .hls-confirm-details{display:flex;align-items:center;gap:14px}
        .hls-confirm-icon{width:44px;height:44px;background:#e0e7ff;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .hls-confirm-date{font-size:15px;font-weight:700;color:#1e293b}
        .hls-confirm-time{font-size:14px;color:#6366f1;font-weight:600}
        .hls-confirm-actions{display:flex;align-items:center;gap:12px}
        .hls-book-btn{padding:12px 28px;background:#6366f1;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;transition:all .2s;font-family:inherit}
        .hls-book-btn:hover{background:#4f46e5;box-shadow:0 4px 14px rgba(99,102,241,.35)}
        .hls-book-btn:disabled{opacity:.7;cursor:not-allowed}
        .hls-btn-loading{position:relative;color:transparent}
        .hls-btn-loading::after{content:'';position:absolute;top:50%;left:50%;width:18px;height:18px;margin:-9px 0 0 -9px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:hls-spin .6s linear infinite}
        .hls-booking-status{font-size:13px;color:#64748b}

        /* ── Success ── */
        .hls-success{animation:hls-slide .35s ease}
        .hls-success-inner{text-align:center;padding:48px 24px;max-width:440px;margin:0 auto}
        .hls-success-check{margin-bottom:20px}
        .hls-success-title{font-size:26px;font-weight:800;color:#059669;margin:0 0 6px}
        .hls-success-subtitle{font-size:15px;color:#64748b;margin:0 0 28px}
        .hls-success-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:18px 24px;margin-bottom:24px;text-align:left}
        .hls-success-row{display:flex;align-items:center;gap:12px;padding:6px 0;font-size:14px;color:#334155;font-weight:500}
        .hls-zoom-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;background:#2563eb;color:#fff;border-radius:10px;font-size:15px;font-weight:700;text-decoration:none;transition:all .2s;margin-bottom:16px}
        .hls-zoom-btn:hover{background:#1d4ed8;color:#fff;box-shadow:0 4px 14px rgba(37,99,235,.3)}
        .hls-view-details-link{display:inline-block;font-size:14px;color:#6366f1;text-decoration:none;font-weight:600}
        .hls-view-details-link:hover{text-decoration:underline}

        /* ── Scheduled view: Tabs (full override — pill style) ── */
        .hls-tabs{display:inline-flex!important;gap:4px!important;padding:4px!important;margin:0 0 28px!important;background:#f1f5f9!important;border:none!important;border-bottom:none!important;border-radius:12px!important;box-shadow:inset 0 1px 2px rgba(0,0,0,.06)!important}
        .hls-tab{display:inline-flex!important;align-items:center!important;gap:7px!important;padding:10px 22px!important;margin:0!important;border:none!important;border-bottom:none!important;border-radius:9px!important;background:transparent!important;font-size:14px!important;font-weight:600!important;color:#64748b!important;cursor:pointer!important;transition:all .2s ease!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif!important;line-height:1.4!important;white-space:nowrap!important;text-decoration:none!important;box-shadow:none!important;text-transform:none!important;letter-spacing:normal!important}
        .hls-tab:hover{color:#475569!important;background:rgba(255,255,255,.5)!important}
        .hls-tab-on{background:#fff!important;color:#1e293b!important;box-shadow:0 1px 3px rgba(0,0,0,.1),0 1px 2px rgba(0,0,0,.06)!important}
        .hls-tab-on:hover{background:#fff!important;color:#1e293b!important}
        .hls-tab svg{flex-shrink:0;opacity:.6}
        .hls-tab-on svg{opacity:1;color:#6366f1!important;stroke:#6366f1!important}

        .hls-detail-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px}
        .hls-detail-status{margin-bottom:24px}
        .hls-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700}
        .hls-badge-green{background:#d1fae5;color:#065f46}
        .hls-badge-blue{background:#e0e7ff;color:#3730a3}
        .hls-badge-red{background:#fee2e2;color:#991b1b}

        .hls-detail-rows{display:flex;flex-direction:column;gap:20px;margin-bottom:24px}
        .hls-detail-row{display:flex;align-items:flex-start;gap:14px}
        .hls-detail-icon{width:44px;height:44px;background:#f5f3ff;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .hls-detail-avatar{width:44px;height:44px;border-radius:10px;object-fit:cover}
        .hls-detail-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:2px}
        .hls-detail-value{font-size:16px;font-weight:700;color:#1e293b}
        .hls-detail-sub{font-size:13px;color:#64748b;margin-top:2px}

        .hls-detail-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;padding-top:20px;border-top:1px solid #f1f5f9}
        .hls-action-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:1px solid #e2e8f0!important;border-radius:10px!important;background:#fff!important;font-size:13px;font-weight:600;color:#475569!important;cursor:pointer;text-decoration:none;transition:all .15s;font-family:inherit}
        .hls-action-btn:hover{background:#f8fafc!important;border-color:#cbd5e1!important;color:#1e293b!important}
        .hls-action-danger{color:#dc2626;border-color:#fecaca}
        .hls-action-danger:hover{background:#fef2f2;border-color:#fca5a5;color:#b91c1c}

        .hls-cancel-box{margin-top:16px;padding:18px 20px;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;animation:hls-slide .2s ease}
        .hls-cancel-box p{margin:0 0 14px;color:#991b1b;font-size:14px;font-weight:500}
        .hls-cancel-btns{display:flex;gap:10px;flex-wrap:wrap}
        .hls-cancel-confirm-btn{padding:9px 18px;background:#dc2626;color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s}
        .hls-cancel-confirm-btn:hover{background:#b91c1c}

        /* Attendance box */
        .hls-attendance-box{margin-top:24px;padding:20px 24px;background:#f5f3ff;border:1px solid #e0e7ff;border-radius:14px;animation:hls-slide .25s ease}
        .hls-attendance-header{display:flex;align-items:center;gap:10px;font-size:15px;font-weight:600;color:#334155;margin-bottom:14px}
        .hls-attendance-btns{display:flex;gap:10px;flex-wrap:wrap}
        .hls-attend-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 24px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;font-family:inherit;border:2px solid}
        .hls-attend-yes{background:#fff;color:#059669;border-color:#a7f3d0}
        .hls-attend-yes:hover{background:#ecfdf5;border-color:#059669}
        .hls-attend-no{background:#fff;color:#dc2626;border-color:#fecaca}
        .hls-attend-no:hover{background:#fef2f2;border-color:#dc2626}
        .hls-attend-btn:disabled{opacity:.6;cursor:not-allowed}

        /* ── Forms tab ── */
        .hls-empty-forms{text-align:center;padding:48px 20px;color:#94a3b8;font-size:14px}
        .hls-empty-forms p{margin:12px 0 0}
        .hls-form-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px 20px;margin-bottom:10px}
        .hls-form-title{font-size:14px;font-weight:700;color:#334155;margin-bottom:10px;text-transform:uppercase;letter-spacing:.3px}
        .hls-form-row{display:flex;gap:8px;margin-bottom:4px;font-size:14px}
        .hls-form-label{color:#64748b;min-width:120px;flex-shrink:0}
        .hls-form-value{color:#1e293b;font-weight:500}

        /* ── Responsive ── */
        @media(max-width:680px){
            .hls-coach-header{flex-direction:column;text-align:center;align-items:center}
            .hls-confirm{flex-direction:column;text-align:center}
            .hls-confirm-details{flex-direction:column}
            .hls-detail-actions{flex-direction:column}
            .hls-action-btn,.hls-cancel-confirm-btn{width:100%;justify-content:center;text-align:center}
        }
        </style>
        <?php
    }
}
