/**
 * Survey Modal — AJAX check, focus trap, likert keyboard nav, draft save.
 *
 * Conditionally enqueued on trigger pages (Program Page, Dashboard).
 * Checks for pending surveys on load, renders server-built HTML into the
 * modal shell, traps focus, and handles submission with nonce refresh.
 *
 * @package HL_Core
 */
(function ($) {
    'use strict';

    var $shell, $overlay, $modal, $content, $loading;
    var checkUrl, checkNonce, submitNonce, pendingId;
    var submitLabel = 'Submit Survey';

    // ── Init ───────────────────────────────────────────────

    $(document).ready(function () {
        $shell = $('#hl-survey-modal-shell');
        if (!$shell.length) return;

        $overlay = $shell.find('.hl-survey-overlay');
        $modal   = $shell.find('.hl-survey-modal');
        $content = $shell.find('.hl-survey-content');
        $loading = $shell.find('.hl-survey-loading');

        checkUrl   = $shell.data('check-url');
        checkNonce = $shell.data('check-nonce');

        checkPending();
    });

    // ── AJAX: Check Pending ────────────────────────────────

    function checkPending() {
        $.post(checkUrl, {
            action: 'hl_check_pending_surveys',
            nonce:  checkNonce
        }, function (res) {
            if (!res.success || !res.data || !res.data.has_pending) return;

            pendingId   = res.data.pending_id;
            submitNonce = res.data.submit_nonce;

            $content.html(res.data.html);
            restoreDraft();
            showModal();
        }).fail(function () {
            // Silently fail — user continues without survey prompt.
        });
    }

    // ── Show / Hide Modal ──────────────────────────────────

    function showModal() {
        $shell.show();
        $loading.hide();
        $content.show();
        $overlay.show();
        $modal.show();

        // Trap focus — prevent interaction with page behind modal.
        var $page = $('#page');
        if ($page.length) {
            if ('inert' in $page[0]) {
                $page[0].inert = true;
            } else {
                $page.attr('aria-hidden', 'true');
            }
        }

        // Focus the first focusable element in the modal.
        var $focusable = getFocusable();
        if ($focusable.length) {
            $focusable.first().focus();
        }
    }

    function hideModal() {
        $shell.hide();
        $overlay.hide();
        $modal.hide();

        var $page = $('#page');
        if ($page.length) {
            if ('inert' in $page[0]) {
                $page[0].inert = false;
            } else {
                $page.removeAttr('aria-hidden');
            }
        }
    }

    function getFocusable() {
        return $modal.find(
            'input:not([disabled]):not([type="hidden"]), textarea:not([disabled]), ' +
            'button:not([disabled]), [tabindex]:not([tabindex="-1"])'
        ).filter(':visible');
    }

    // ── Focus Trap + Escape ────────────────────────────────

    $(document).on('keydown', function (e) {
        if (!$modal || !$modal.is(':visible')) return;

        // Escape: do NOT close — refocus first element.
        if (e.key === 'Escape') {
            e.preventDefault();
            var $focusable = getFocusable();
            if ($focusable.length) $focusable.first().focus();
            return;
        }

        // Tab trap.
        if (e.key === 'Tab') {
            var $focusable = getFocusable();
            if (!$focusable.length) return;

            var $first = $focusable.first();
            var $last  = $focusable.last();

            if (e.shiftKey) {
                if (document.activeElement === $first[0]) {
                    e.preventDefault();
                    $last.focus();
                }
            } else {
                if (document.activeElement === $last[0]) {
                    e.preventDefault();
                    $first.focus();
                }
            }
        }
    });

    // ── Likert Keyboard Navigation (Arrow Keys) ───────────

    $(document).on('keydown', '.hl-survey-pills [role="radio"]', function (e) {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();

        var $group  = $(this).closest('.hl-survey-pills');
        var $radios = $group.find('[role="radio"]');
        var idx     = $radios.index(this);

        if (e.key === 'ArrowRight') {
            idx = (idx + 1) % $radios.length;
        } else {
            idx = (idx - 1 + $radios.length) % $radios.length;
        }

        // Update roving tabindex.
        $radios.attr('tabindex', '-1').attr('aria-checked', 'false').prop('checked', false);
        var $next = $radios.eq(idx);
        $next.attr('tabindex', '0').attr('aria-checked', 'true').prop('checked', true).focus();

        // Update pill visual state.
        $group.find('.hl-survey-pill').removeClass('selected');
        $next.closest('.hl-survey-pill').addClass('selected');

        saveDraft();
    });

    // ── Pill Click ─────────────────────────────────────────

    $(document).on('change', '.hl-survey-pills input[type="radio"]', function () {
        var $group = $(this).closest('.hl-survey-pills');
        $group.find('.hl-survey-pill').removeClass('selected');
        $group.find('input[type="radio"]').attr('aria-checked', 'false').attr('tabindex', '-1');

        $(this).attr('aria-checked', 'true').attr('tabindex', '0');
        $(this).closest('.hl-survey-pill').addClass('selected');

        saveDraft();
    });

    // ── Input Change (for draft save) ──────────────────────

    $(document).on('input', '#hl-survey-form textarea', function () {
        saveDraft();
    });

    // ── Form Validation + Submit ───────────────────────────

    $(document).on('submit', '#hl-survey-form', function (e) {
        e.preventDefault();

        var $form  = $(this);
        var $error = $form.find('.hl-survey-error');
        var $btn   = $form.find('#hl-survey-submit');

        // Validate required fields.
        var valid     = true;
        var $firstBad = null;

        // Check radio groups (each fieldset with [required] radios).
        $form.find('fieldset').each(function () {
            var $fs   = $(this);
            var $reqs = $fs.find('input[type="radio"][required]');
            if ($reqs.length && !$fs.find('input[type="radio"]:checked').length) {
                valid = false;
                if (!$firstBad) $firstBad = $reqs.first();
            }
        });

        // Check required textareas.
        $form.find('textarea[required]').each(function () {
            if (!$.trim($(this).val())) {
                valid = false;
                if (!$firstBad) $firstBad = $(this);
            }
        });

        if (!valid) {
            $error.text('Please answer all required questions.').show();
            if ($firstBad) $firstBad.focus();
            return;
        }

        $error.hide();

        // Serialize responses to JSON object keyed by question name.
        var responses = {};
        $form.find('.hl-survey-question').each(function () {
            var key = $(this).data('key');
            if (!key) return;

            var $checked = $(this).find('input[type="radio"]:checked');
            if ($checked.length) {
                responses[key] = $checked.val();
                return;
            }

            var $textarea = $(this).find('textarea');
            if ($textarea.length) {
                responses[key] = $.trim($textarea.val());
            }
        });

        // Disable button, show submitting state.
        $btn.prop('disabled', true).text('Submitting...');

        $.post(checkUrl, {
            action:     'hl_submit_survey',
            pending_id: pendingId,
            nonce:      submitNonce,
            responses:  JSON.stringify(responses)
        }, function (res) {
            if (res.success) {
                clearDraft();
                hideModal();
                window.location.reload();
                return;
            }

            // Error handling.
            $btn.prop('disabled', false).text(submitLabel);
            $error.text(res.data || 'An error occurred. Please try again.').show();
        }).fail(function (xhr) {
            // 403 or -1 typically means nonce expired — auto-retry check for fresh nonce.
            if (xhr.status === 403 || (xhr.responseJSON && (xhr.responseJSON.data === '-1' || xhr.responseJSON.data === '0'))) {
                refreshNonceAndRetry($form, $btn, $error, responses);
                return;
            }
            $btn.prop('disabled', false).text(submitLabel);
            $error.text('Network error. Please check your connection and try again.').show();
        });
    });

    /**
     * Nonce expired: re-run check endpoint to get fresh submit nonce, then retry.
     */
    function refreshNonceAndRetry($form, $btn, $error, responses) {
        $.post(checkUrl, {
            action: 'hl_check_pending_surveys',
            nonce:  checkNonce
        }, function (res) {
            if (!res.success || !res.data || !res.data.has_pending) {
                // No longer pending — close modal and reload.
                hideModal();
                window.location.reload();
                return;
            }

            // Update nonce and retry submit.
            submitNonce = res.data.submit_nonce;
            pendingId   = res.data.pending_id;

            $.post(checkUrl, {
                action:     'hl_submit_survey',
                pending_id: pendingId,
                nonce:      submitNonce,
                responses:  JSON.stringify(responses)
            }, function (res2) {
                if (res2.success) {
                    clearDraft();
                    hideModal();
                    window.location.reload();
                    return;
                }
                $btn.prop('disabled', false).text(submitLabel);
                $error.text(res2.data || 'An error occurred. Please try again.').show();
            }).fail(function () {
                $btn.prop('disabled', false).text(submitLabel);
                $error.text('Network error. Please try again.').show();
            });
        }).fail(function () {
            $btn.prop('disabled', false).text(submitLabel);
            $error.text('Session expired. Please reload the page.').show();
        });
    }

    // ── sessionStorage Draft Save / Restore ────────────────

    function getDraftKey() {
        return 'hl_survey_draft_' + pendingId;
    }

    function saveDraft() {
        if (!pendingId) return;
        try {
            var data = {};
            $('#hl-survey-form .hl-survey-question').each(function () {
                var key = $(this).data('key');
                if (!key) return;

                var $checked = $(this).find('input[type="radio"]:checked');
                if ($checked.length) {
                    data[key] = $checked.val();
                    return;
                }

                var $textarea = $(this).find('textarea');
                if ($textarea.length) {
                    data[key] = $textarea.val();
                }
            });
            sessionStorage.setItem(getDraftKey(), JSON.stringify(data));
        } catch (e) {
            // sessionStorage may be unavailable — silently ignore.
        }
    }

    function restoreDraft() {
        if (!pendingId) return;
        try {
            var raw = sessionStorage.getItem(getDraftKey());
            if (!raw) return;
            var data = JSON.parse(raw);
            if (!data || typeof data !== 'object') return;

            $.each(data, function (key, val) {
                if (!val) return;

                var $q = $('#hl-survey-form .hl-survey-question[data-key="' + key + '"]');
                if (!$q.length) return;

                // Radio.
                var $radio = $q.find('input[type="radio"][value="' + val + '"]');
                if ($radio.length) {
                    $radio.prop('checked', true).attr('aria-checked', 'true').attr('tabindex', '0');
                    $radio.closest('.hl-survey-pill').addClass('selected');
                    // Reset siblings.
                    var $siblings = $q.find('input[type="radio"]').not($radio);
                    $siblings.attr('aria-checked', 'false').attr('tabindex', '-1');
                    return;
                }

                // Textarea.
                var $textarea = $q.find('textarea');
                if ($textarea.length) {
                    $textarea.val(val);
                }
            });
        } catch (e) {
            // Corrupted draft — ignore.
        }
    }

    function clearDraft() {
        try {
            sessionStorage.removeItem(getDraftKey());
        } catch (e) {
            // Ignore.
        }
    }

})(jQuery);
