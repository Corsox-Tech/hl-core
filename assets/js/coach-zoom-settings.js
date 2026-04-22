/**
 * Coach Zoom Meeting Settings — frontend interactivity.
 *
 * Ticket #31, Task H2. Handles modal open/close + focus trap, per-row toggle
 * + reset, alt-hosts radio mode switch, "Reset all" styled confirm, AJAX save
 * with field-level error rendering, callout dismiss, admin-context page-nav,
 * and nonce-expiry detection.
 *
 * Localized globals (via wp_localize_script):
 *   HLCoachZoomSettings.ajaxUrl      — admin-ajax.php URL
 *   HLCoachZoomSettings.dashboardUrl — current permalink, used by admin
 *                                      "Edit" links to navigate with
 *                                      ?coach_user_id=N so the modal
 *                                      auto-opens for that coach.
 */
(function(){
    'use strict';

    var modal           = document.querySelector('.hlczs-modal');
    var backdrop        = document.querySelector('.hlczs-modal-backdrop');
    var form            = document.getElementById('hlczs-form');
    var openTriggers    = document.querySelectorAll('.hlczs-edit-trigger');
    var adminEditLinks  = document.querySelectorAll('.hlczs-admin-edit');
    var closeBtn        = modal ? modal.querySelector('.hlczs-modal-close') : null;
    var resetAllBtn     = modal ? modal.querySelector('.hlczs-reset-all') : null;
    var confirmBackdrop = document.querySelector('.hlczs-confirm-backdrop');
    var confirmModal    = document.querySelector('.hlczs-confirm-modal');
    var confirmCancel   = confirmModal ? confirmModal.querySelector('.hlczs-confirm-cancel') : null;
    var confirmOk       = confirmModal ? confirmModal.querySelector('.hlczs-confirm-ok') : null;
    var lastFocused     = null;

    // ------------------------------------------------------------------
    // Modal open/close + focus trap
    // ------------------------------------------------------------------
    function openModal() {
        if (!modal) return;
        lastFocused = document.activeElement;
        if (backdrop) backdrop.hidden = false;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        var focusable = modal.querySelectorAll('button, [href], input, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable[0]) focusable[0].focus();
        document.addEventListener('keydown', trapTab);
        document.addEventListener('keydown', escClose);
    }

    function closeModal() {
        if (!modal) return;
        modal.hidden = true;
        if (backdrop) backdrop.hidden = true;
        document.body.style.overflow = '';
        document.removeEventListener('keydown', trapTab);
        document.removeEventListener('keydown', escClose);
        if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
    }

    function escClose(e) {
        if (e.key === 'Escape') closeModal();
    }

    function trapTab(e) {
        if (e.key !== 'Tab') return;
        var focusable = modal.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
        if (!focusable.length) return;
        var first = focusable[0];
        var last  = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    [].forEach.call(openTriggers, function(btn){
        btn.addEventListener('click', openModal);
    });
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', closeModal);

    // ------------------------------------------------------------------
    // Admin context: page-navigate to ?coach_user_id=N so the modal
    // auto-opens for the target coach (data-auto-open set server-side).
    // ------------------------------------------------------------------
    [].forEach.call(adminEditLinks, function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            var coachId = link.dataset.coachId;
            if (!coachId) return;
            window.location = HLCoachZoomSettings.dashboardUrl + '?coach_user_id=' + encodeURIComponent(coachId);
        });
    });

    // ------------------------------------------------------------------
    // Row state helpers
    // ------------------------------------------------------------------
    function updateRowCaption(row, isOverride) {
        var caption = row.querySelector('.hlczs-row-caption');
        if (caption) {
            caption.textContent = isOverride ? 'Using your override.' : 'Using the company default.';
        }
        var resetBtn = row.querySelector('.hlczs-row-reset');
        if (resetBtn) resetBtn.hidden = !isOverride;
    }

    function resetRow(row) {
        var field = row.dataset.field;
        if (field === 'alternative_hosts') {
            var defaultRadio = row.querySelector('input[value="use_default"]');
            if (defaultRadio) defaultRadio.checked = true;
            var ta = document.getElementById('hlczs-alt-hosts-textarea');
            if (ta) {
                ta.disabled = true;
                ta.value    = '';
                ta.classList.remove('hlczs-invalid');
            }
        } else {
            var toggle = row.querySelector('.hlczs-toggle');
            if (toggle) {
                toggle.setAttribute('aria-pressed', toggle.dataset.defaultValue === '1' ? 'true' : 'false');
            }
        }
        row.dataset.dirty = '0';
        row.dataset.reset = '1';
        var resetBtn = row.querySelector('.hlczs-row-reset');
        if (resetBtn) resetBtn.hidden = true;
        row.classList.remove('hlczs-row-error');
        updateRowCaption(row, false);
    }

    // ------------------------------------------------------------------
    // Toggle / per-row reset / alt-hosts radio handler (event delegation)
    // ------------------------------------------------------------------
    if (modal) {
        modal.addEventListener('click', function(e){
            var toggle = e.target.closest('.hlczs-toggle');
            if (toggle) {
                var pressed = toggle.getAttribute('aria-pressed') === 'true';
                toggle.setAttribute('aria-pressed', pressed ? 'false' : 'true');
                var toggleRow = toggle.closest('.hlczs-row');
                toggleRow.dataset.dirty = '1';
                toggleRow.dataset.reset = '0';
                updateRowCaption(toggleRow, true);
                return;
            }

            var resetBtn = e.target.closest('.hlczs-row-reset');
            if (resetBtn) {
                var resetTargetRow = resetBtn.closest('.hlczs-row');
                resetRow(resetTargetRow);
                // Return focus to the primary control in this row.
                var focusTarget = resetTargetRow.querySelector('.hlczs-toggle, input[type="radio"]:checked, input[type="radio"]');
                if (focusTarget) focusTarget.focus();
                return;
            }
        });

        // Radio change must be handled on the "change" event (clicks on the
        // <label> do not reliably bubble the right target for radios).
        modal.addEventListener('change', function(e){
            if (e.target.matches('input[name="alt_hosts_mode"]')) {
                var altRow = modal.querySelector('.hlczs-row-althosts');
                var ta     = document.getElementById('hlczs-alt-hosts-textarea');
                var mode   = e.target.value;
                if (ta) {
                    ta.disabled = (mode !== 'override_emails');
                }
                altRow.dataset.dirty = '1';
                altRow.dataset.reset = '0';
                var resetBtn = altRow.querySelector('.hlczs-row-reset');
                if (resetBtn) resetBtn.hidden = (mode === 'use_default');
                updateRowCaption(altRow, mode !== 'use_default');
                if (mode === 'override_emails' && ta) ta.focus();
            }
        });
    }

    // ------------------------------------------------------------------
    // "Reset all" styled confirm dialog
    // ------------------------------------------------------------------
    if (resetAllBtn) {
        resetAllBtn.addEventListener('click', function(){
            if (!confirmBackdrop || !confirmModal) return;
            confirmBackdrop.hidden = false;
            confirmModal.hidden    = false;
            if (confirmOk) confirmOk.focus();
        });
    }
    if (confirmCancel) {
        confirmCancel.addEventListener('click', function(){
            confirmBackdrop.hidden = true;
            confirmModal.hidden    = true;
            if (resetAllBtn) resetAllBtn.focus();
        });
    }
    if (confirmOk) {
        confirmOk.addEventListener('click', function(){
            confirmBackdrop.hidden = true;
            confirmModal.hidden    = true;
            var rows = modal ? modal.querySelectorAll('.hlczs-row') : [];
            [].forEach.call(rows, resetRow);
            // Per spec: one-click intent — submit immediately.
            if (form) {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.dispatchEvent(new Event('submit', { cancelable: true }));
                }
            }
        });
    }

    // ------------------------------------------------------------------
    // Alt-hosts textarea: capture paste/typing as dirty; client-side
    // blur validation (server is authoritative).
    // ------------------------------------------------------------------
    var altTextarea = document.getElementById('hlczs-alt-hosts-textarea');
    if (altTextarea) {
        altTextarea.addEventListener('input', function(){
            var altRow = altTextarea.closest('.hlczs-row');
            if (altRow) {
                altRow.dataset.dirty = '1';
                altRow.dataset.reset = '0';
            }
        });
        altTextarea.addEventListener('blur', function(){
            var emails = altTextarea.value.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
            var bad = emails.filter(function(e){ return ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e); });
            altTextarea.classList.toggle('hlczs-invalid', bad.length > 0);
        });
    }

    // ------------------------------------------------------------------
    // AJAX save
    // ------------------------------------------------------------------
    if (form) {
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var saveBtn = form.querySelector('.hlczs-save');
            if (saveBtn) saveBtn.disabled = true;

            // Conditional copy: "Verifying with Zoom…" only when alt_hosts will preflight.
            var altRowEl  = modal.querySelector('.hlczs-row-althosts');
            var altDirty  = altRowEl && altRowEl.dataset.dirty === '1';
            var altModeEl = altDirty ? form.querySelector('input[name="alt_hosts_mode"]:checked') : null;
            var altMode   = altModeEl ? altModeEl.value : null;
            var altValueRaw = altDirty && altTextarea ? altTextarea.value : '';
            var willPreflight = altDirty && altMode === 'override_emails' && altValueRaw.trim() !== '';
            if (saveBtn) saveBtn.textContent = willPreflight ? 'Verifying with Zoom…' : 'Saving…';

            var banner = form.querySelector('.hlczs-banner');
            if (banner) banner.hidden = true;
            [].forEach.call(form.querySelectorAll('.hlczs-row-error'), function(row){
                row.classList.remove('hlczs-row-error');
            });

            var fd = new FormData();
            fd.append('action', 'hl_save_coach_zoom_settings');
            fd.append('_nonce', form.dataset.nonce);
            fd.append('coach_user_id', form.dataset.coachId);

            [].forEach.call(modal.querySelectorAll('.hlczs-row'), function(row){
                var field = row.dataset.field;
                var dirty = row.dataset.dirty === '1';
                var reset = row.dataset.reset === '1';

                if (reset) {
                    fd.append('reset[]', field);
                    return;
                }
                if (!dirty) return;

                if (field === 'alternative_hosts') {
                    var modeEl = form.querySelector('input[name="alt_hosts_mode"]:checked');
                    var mode   = modeEl ? modeEl.value : 'use_default';
                    if (mode === 'use_default') {
                        // Mode = use_default but row still dirty: treat as reset
                        // (this happens when the user flips back from an override).
                        fd.append('reset[]', field);
                    } else if (mode === 'override_none') {
                        fd.append('alternative_hosts', '');
                    } else if (mode === 'override_emails') {
                        fd.append('alternative_hosts', altTextarea ? altTextarea.value : '');
                    }
                } else {
                    var toggle = row.querySelector('.hlczs-toggle');
                    var pressed = toggle && toggle.getAttribute('aria-pressed') === 'true';
                    fd.append(field, pressed ? '1' : '0');
                }
            });

            fetch(HLCoachZoomSettings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
                .then(function(r){ return r.json().catch(function(){ return null; }); })
                .then(function(json){
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'Save';
                    }

                    // Nonce expiry: WP returns 0 or -1 as raw body when
                    // check_ajax_referer fails without die; defensive check.
                    if (json === 0 || json === -1 || json === null) {
                        if (banner) {
                            banner.className   = 'hlczs-banner hlczs-banner-error';
                            banner.textContent = 'Your session expired. Please reload the page and try again.';
                            banner.hidden      = false;
                        }
                        return;
                    }

                    if (json && json.success) {
                        if (banner) {
                            banner.className   = 'hlczs-banner hlczs-banner-success';
                            banner.textContent = 'Settings saved.';
                            banner.hidden      = false;
                        }
                        setTimeout(function(){
                            closeModal();
                            window.location.reload();
                        }, 800);
                        return;
                    }

                    // Error branch.
                    var msg       = (json && json.data && json.data.message) || 'Save failed.';
                    var errorData = (json && json.data && json.data.error_data) || {};
                    var errField  = errorData.field;
                    if (errField) {
                        var errRow = modal.querySelector('.hlczs-row[data-field="' + errField + '"]');
                        if (errRow) {
                            errRow.classList.add('hlczs-row-error');
                            var caption = errRow.querySelector('.hlczs-row-caption');
                            if (caption) caption.textContent = msg;
                        } else if (banner) {
                            banner.className   = 'hlczs-banner hlczs-banner-error';
                            banner.textContent = msg;
                            banner.hidden      = false;
                        }
                    } else if (banner) {
                        banner.className   = 'hlczs-banner hlczs-banner-error';
                        banner.textContent = msg;
                        banner.hidden      = false;
                    }
                })
                .catch(function(){
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'Save';
                    }
                    if (banner) {
                        banner.className   = 'hlczs-banner hlczs-banner-error';
                        banner.textContent = 'Network error. Please try again.';
                        banner.hidden      = false;
                    }
                });
        });
    }

    // ------------------------------------------------------------------
    // First-visit callout dismiss
    // ------------------------------------------------------------------
    var calloutDismiss = document.querySelector('.hlczs-callout-dismiss');
    if (calloutDismiss) {
        calloutDismiss.addEventListener('click', function(){
            var callout = calloutDismiss.closest('.hlczs-callout');
            if (!callout) return;
            var nonce = callout.dataset.calloutNonce;
            callout.style.display = 'none';
            var fd = new FormData();
            fd.append('action', 'hl_dismiss_coach_zoom_callout');
            fd.append('_nonce', nonce);
            fetch(HLCoachZoomSettings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
        });
    }

    // ------------------------------------------------------------------
    // Auto-open modal in admin context (PHP sets data-auto-open="1")
    // ------------------------------------------------------------------
    if (modal && modal.dataset.autoOpen === '1') {
        openModal();
    }
})();
