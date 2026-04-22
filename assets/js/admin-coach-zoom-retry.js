/**
 * Admin — Retry Zoom meeting creation.
 *
 * Wires the per-session "Retry Zoom" button in the Coaching Hub sessions list.
 * POSTs to wp_ajax_hl_retry_zoom_meeting (see HL_Scheduling_Service::ajax_retry_zoom_meeting).
 *
 * Success payload:  { meeting_id, meeting_url [, note] }
 * Error   payload:  { message, error_code }
 *
 * Error-code → UX mapping (per Ticket #31 F3 spec):
 *   retry_inflight       → "Retry already in progress. Please wait ~60s and try again."
 *   already_has_meeting  → inline notice + location.reload() (stale admin view)
 *   not_found            → inline notice + location.reload()
 *   zoom_not_configured  → "Zoom integration is not configured. Contact the site admin."
 *   permission_denied    → "Permission denied."
 *   invalid_datetime     → show message verbatim
 *   db_write_failed      → show message verbatim
 *   (Zoom API codes)     → show message verbatim
 *   (unknown)            → "Retry failed. Please try again."
 *
 * Localized globals:
 *   HLCoachZoomRetry.ajaxUrl
 *
 * @package HL_Core
 */
(function () {
    'use strict';

    if (typeof window.HLCoachZoomRetry === 'undefined') {
        return;
    }

    var ERROR_MESSAGES = {
        retry_inflight:      'Retry already in progress. Please wait ~60s and try again.',
        zoom_not_configured: 'Zoom integration is not configured. Contact the site admin.',
        permission_denied:   'Permission denied.'
    };

    // Error codes that mean the page is stale — reload after showing a brief notice.
    var RELOAD_ON_CODES = { already_has_meeting: 1, not_found: 1 };

    // Error codes where we trust the server's message verbatim.
    var VERBATIM_CODES = { invalid_datetime: 1, db_write_failed: 1 };

    function escapeHtml(s) {
        return String(s).replace(/[<>&"']/g, function (c) {
            return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function showInlineNotice(anchor, msg, kind) {
        var parent = anchor.parentNode;
        if (!parent) return;
        var existing = parent.querySelector('.hlczs-retry-notice');
        if (existing) existing.remove();
        var n = document.createElement('div');
        n.className = 'notice notice-' + (kind === 'error' ? 'error' : 'success') + ' is-dismissible hlczs-retry-notice';
        n.style.margin = '8px 0 0';
        n.innerHTML = '<p>' + escapeHtml(msg) + '</p>';
        parent.appendChild(n);
    }

    function resolveErrorMessage(errorCode, serverMessage) {
        if (errorCode && ERROR_MESSAGES[errorCode]) {
            return { text: ERROR_MESSAGES[errorCode], kind: 'error', reload: false };
        }
        if (errorCode && RELOAD_ON_CODES[errorCode]) {
            var reloadMsg = errorCode === 'already_has_meeting'
                ? 'This session already has a meeting. Reloading…'
                : 'Session not found. Reloading…';
            return { text: reloadMsg, kind: 'error', reload: true };
        }
        if (errorCode && VERBATIM_CODES[errorCode] && serverMessage) {
            return { text: serverMessage, kind: 'error', reload: false };
        }
        // Zoom API codes (or anything unknown with a server message) — use message verbatim.
        if (serverMessage) {
            return { text: serverMessage, kind: 'error', reload: false };
        }
        return { text: 'Retry failed. Please try again.', kind: 'error', reload: false };
    }

    document.addEventListener('click', function (e) {
        var target = e.target;
        var btn = target && target.closest ? target.closest('.hlczs-retry-zoom') : null;
        if (!btn || btn.disabled) return;

        e.preventDefault();

        if (!window.confirm('Retry creating the Zoom meeting for this session?')) {
            return;
        }

        var origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Retrying…';

        var fd = new FormData();
        fd.append('action', 'hl_retry_zoom_meeting');
        fd.append('_nonce', btn.dataset.nonce || '');
        fd.append('session_id', btn.dataset.sessionId || '');

        fetch(window.HLCoachZoomRetry.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success) {
                    var data = json.data || {};
                    var successText = data.note
                        ? ('Zoom link created. ' + data.note)
                        : 'Zoom link created. Reloading…';
                    btn.textContent = 'Zoom link created';
                    showInlineNotice(btn, successText, 'success');
                    setTimeout(function () { location.reload(); }, 1200);
                    return;
                }

                var data = (json && json.data) || {};
                var resolved = resolveErrorMessage(data.error_code || '', data.message || '');
                showInlineNotice(btn, resolved.text, resolved.kind);

                if (resolved.reload) {
                    setTimeout(function () { location.reload(); }, 1200);
                    return;
                }

                btn.disabled = false;
                btn.textContent = origText;
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = origText;
                showInlineNotice(btn, 'Network error during retry.', 'error');
            });
    });
})();
