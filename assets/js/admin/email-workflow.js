/**
 * HL Email System v2 — Workflow admin UI.
 *
 * Condition builder + recipient picker + hidden JSON sync.
 *
 * Enqueued only on the workflow edit/new page (tab=workflows, action in [edit,new]).
 * Registries injected via wp_add_inline_script('hl-email-workflow', ..., 'before'):
 *   window.hlConditionFields    - from HL_Admin_Emails::get_condition_fields()
 *   window.hlConditionOperators - from HL_Admin_Emails::get_condition_operators()
 *   window.hlRecipientTokens    - from HL_Admin_Emails::get_recipient_tokens()
 *   window.hlEmailWorkflowCfg   - { ajaxUrl, nonces: {...}, currentTrigger }
 *
 * A.3.2 — jQuery IIFE noConflict wrapper. A.3.3 — all markup lives inside
 * .hl-email-admin for CSS specificity.
 */
jQuery(function ($) {
    'use strict';

    $('body').addClass('hl-js-loaded');
    window.addEventListener('error', function () {
        $('body').removeClass('hl-js-loaded');
    });

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------
    var FIELDS    = window.hlConditionFields || {};
    var OPERATORS = window.hlConditionOperators || {};
    var TOKENS    = window.hlRecipientTokens || {};

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ---------------------------------------------------------------------
    // Condition Builder
    // ---------------------------------------------------------------------
    var $builder = $('.hl-condition-builder');
    if ($builder.length) initConditionBuilder($builder);

    function initConditionBuilder($wrap) {
        var $rows     = $wrap.find('.hl-condition-rows');
        var $textarea = $wrap.closest('td').find('textarea[name="conditions"]');
        var initial   = [];
        try {
            initial = JSON.parse($wrap.attr('data-initial') || '[]') || [];
        } catch (e) {
            initial = [];
        }

        initial.forEach(function (cond) {
            addConditionRow($rows, cond);
        });

        $wrap.on('click', '.hl-condition-add', function (e) {
            e.preventDefault();
            addConditionRow($rows, null);
            serializeConditions($wrap, $textarea);
        });

        $wrap.on('click', '.hl-condition-remove', function (e) {
            e.preventDefault();
            $(this).closest('.hl-condition-row').remove();
            serializeConditions($wrap, $textarea);
        });

        $wrap.on('change', '.hl-condition-field', function () {
            var $row = $(this).closest('.hl-condition-row');
            rebuildOperatorSelect($row);
            rebuildValueInput($row);
            serializeConditions($wrap, $textarea);
        });

        $wrap.on('change', '.hl-condition-op', function () {
            var $row = $(this).closest('.hl-condition-row');
            rebuildValueInput($row);
            serializeConditions($wrap, $textarea);
        });

        $wrap.on('change input', '.hl-condition-value, .hl-condition-value-pillbox input', function () {
            serializeConditions($wrap, $textarea);
        });

        // A.1.2 — Enter inside a pill input adds a pill without submitting the form.
        $wrap.on('keydown', '.hl-pill-input input', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addPillFromInput($(this));
                serializeConditions($wrap, $textarea);
            } else if (e.key === 'Backspace' && !$(this).val()) {
                $(this).closest('.hl-pill-input').find('.hl-pill').last().remove();
                serializeConditions($wrap, $textarea);
            }
        });

        $wrap.on('click', '.hl-pill-remove', function (e) {
            e.preventDefault();
            $(this).closest('.hl-pill').remove();
            serializeConditions($wrap, $textarea);
        });

        serializeConditions($wrap, $textarea);
    }

    function addConditionRow($rows, cond) {
        cond = cond || { field: '', op: 'eq', value: '' };
        var rowIndex = $rows.children('.hl-condition-row').length;
        var $row = $(
            '<div class="hl-condition-row" role="group" aria-label="Condition ' + (rowIndex + 1) + '">' +
                '<select class="hl-condition-field" aria-label="Field"></select>' +
                '<select class="hl-condition-op"    aria-label="Operator"></select>' +
                '<span class="hl-condition-value-wrap"></span>' +
                '<button type="button" class="hl-condition-remove" aria-label="Remove condition ' + (rowIndex + 1) + '">&times;</button>' +
            '</div>'
        );

        var $field = $row.find('.hl-condition-field');
        var groups = {};
        Object.keys(FIELDS).forEach(function (key) {
            var g = FIELDS[key].group || 'Other';
            (groups[g] = groups[g] || []).push({ key: key, label: FIELDS[key].label });
        });
        $field.append('<option value="">— Select field —</option>');
        Object.keys(groups).forEach(function (g) {
            var $og = $('<optgroup>').attr('label', g);
            groups[g].forEach(function (item) {
                $og.append('<option value="' + escHtml(item.key) + '">' + escHtml(item.label) + '</option>');
            });
            $field.append($og);
        });
        if (cond.field) $field.val(cond.field);

        $rows.append($row);
        rebuildOperatorSelect($row, cond.op);
        rebuildValueInput($row, cond.value);
    }

    function rebuildOperatorSelect($row, preservedOp) {
        var field = $row.find('.hl-condition-field').val();
        var type  = (FIELDS[field] && FIELDS[field].type) || 'text';
        var ops   = OPERATORS[type] || OPERATORS['text'] || {};
        var $op   = $row.find('.hl-condition-op');
        var current = preservedOp || $op.val() || 'eq';
        $op.empty();
        Object.keys(ops).forEach(function (k) {
            $op.append('<option value="' + escHtml(k) + '">' + escHtml(ops[k]) + '</option>');
        });
        if (ops[current]) {
            $op.val(current);
        } else {
            $op.val(Object.keys(ops)[0] || 'eq');
        }
    }

    function rebuildValueInput($row, preservedValue) {
        var field = $row.find('.hl-condition-field').val();
        var op    = $row.find('.hl-condition-op').val();
        var def   = FIELDS[field] || { type: 'text', options: {} };
        var type  = def.type;
        var opts  = def.options || {};
        var $wrap = $row.find('.hl-condition-value-wrap');
        if (preservedValue === undefined) {
            preservedValue = $wrap.find('.hl-condition-value').val();
            if (preservedValue === undefined) {
                var pills = $wrap.find('.hl-pill').map(function () {
                    return $(this).attr('data-value');
                }).get();
                preservedValue = pills.length ? pills : '';
            }
        }
        $wrap.empty();

        if (op === 'is_null' || op === 'not_null') {
            $wrap.addClass('hl-condition-value-hidden');
            return;
        }
        $wrap.removeClass('hl-condition-value-hidden');

        if (type === 'boolean') {
            var boolVal = (preservedValue === true || preservedValue === 'true' || preservedValue === '1') ? '1' : '0';
            var radioName = 'v_' + Math.random().toString(36).slice(2);
            $wrap.append(
                '<span class="hl-toggle-pair" role="radiogroup" aria-label="Value">' +
                    '<label><input type="radio" class="hl-condition-value" name="' + radioName + '" value="1"' + (boolVal === '1' ? ' checked' : '') + '> Yes</label>' +
                    '<label><input type="radio" class="hl-condition-value" name="' + radioName + '" value="0"' + (boolVal === '0' ? ' checked' : '') + '> No</label>' +
                '</span>'
            );
            return;
        }

        if (op === 'in' || op === 'not_in') {
            var values = Array.isArray(preservedValue) ? preservedValue : (preservedValue ? String(preservedValue).split(',') : []);
            var enumOptions = Object.keys(opts);
            var datalistId  = 'hl-dl-' + Math.random().toString(36).slice(2);
            var $box = $(
                '<div class="hl-pill-input" role="list">' +
                    values.map(function (v) {
                        var label = opts[v] || v;
                        return '<span class="hl-pill hl-pill-enum" role="listitem" data-value="' + escHtml(v) + '">' +
                                    escHtml(label) +
                                    ' <button type="button" class="hl-pill-remove" aria-label="Remove ' + escHtml(label) + '">&times;</button>' +
                                '</span>';
                    }).join('') +
                    '<input type="text" placeholder="Type and press Enter"' + (enumOptions.length ? ' list="' + datalistId + '"' : '') + '>' +
                (enumOptions.length
                    ? '<datalist id="' + datalistId + '">' +
                        enumOptions.map(function (k) {
                            return '<option value="' + escHtml(k) + '">' + escHtml(opts[k]) + '</option>';
                        }).join('') +
                      '</datalist>'
                    : '') +
                '</div>'
            );
            $wrap.append($box);
            return;
        }

        if (type === 'enum') {
            var $select = $('<select class="hl-condition-value" aria-label="Value"></select>');
            $select.append('<option value="">— Select —</option>');
            Object.keys(opts).forEach(function (k) {
                $select.append('<option value="' + escHtml(k) + '">' + escHtml(opts[k]) + '</option>');
            });
            if (preservedValue != null) $select.val(preservedValue);
            $wrap.append($select);
            return;
        }

        if (type === 'numeric') {
            $wrap.append('<input type="number" class="hl-condition-value" aria-label="Value" value="' + escHtml(preservedValue) + '">');
            return;
        }

        $wrap.append('<input type="text" class="hl-condition-value" aria-label="Value" value="' + escHtml(preservedValue) + '">');
    }

    function addPillFromInput($input) {
        var val = String($input.val() || '').trim();
        if (!val) return;
        $input.val('');
        var $box = $input.closest('.hl-pill-input');
        var pillClass = $box.hasClass('hl-pill-input-email')
            ? 'hl-pill-email'
            : ($box.hasClass('hl-pill-input-role') ? 'hl-pill-role' : 'hl-pill-enum');

        if (pillClass === 'hl-pill-email') {
            var ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
            if (!ok) {
                $input.addClass('hl-input-invalid').attr('aria-invalid', 'true');
                announceError('Invalid email address: ' + val);
                setTimeout(function () { $input.removeClass('hl-input-invalid').removeAttr('aria-invalid'); }, 2000);
                return;
            }
        }
        // A.2.15 — reject commas silently in role pills (prevents FIND_IN_SET smuggling).
        if (pillClass === 'hl-pill-role' && val.indexOf(',') !== -1) {
            announceError('Role names cannot contain commas');
            return;
        }

        var $pill = $('<span class="hl-pill ' + pillClass + '" role="listitem"></span>')
            .attr('data-value', val)
            .text(val)
            .append(' <button type="button" class="hl-pill-remove" aria-label="Remove ' + escHtml(val) + '">&times;</button>');
        $input.before($pill);
    }

    function announceError(msg) {
        var $region = $('#hl-email-admin-sr');
        if (!$region.length) {
            $region = $('<div id="hl-email-admin-sr" class="screen-reader-text" aria-live="polite" role="status"></div>');
            $('body').append($region);
        }
        $region.text('');
        setTimeout(function () { $region.text(msg); }, 50);
    }

    function serializeConditions($wrap, $textarea) {
        var out = [];
        $wrap.find('.hl-condition-row').each(function () {
            var $row = $(this);
            var field = $row.find('.hl-condition-field').val();
            var op    = $row.find('.hl-condition-op').val();
            if (!field || !op) return;
            var value;
            if (op === 'is_null' || op === 'not_null') {
                value = null;
            } else {
                var $inp = $row.find('.hl-condition-value:not([type=radio])');
                var $radio = $row.find('.hl-condition-value[type=radio]:checked');
                var $pillbox = $row.find('.hl-pill-input');
                if ($pillbox.length) {
                    value = $pillbox.find('.hl-pill').map(function () {
                        return $(this).attr('data-value');
                    }).get();
                } else if ($radio.length) {
                    value = $radio.val() === '1';
                } else if ($inp.length) {
                    value = $inp.val();
                } else {
                    value = '';
                }
            }
            out.push({ field: field, op: op, value: value });
        });
        $textarea.val(JSON.stringify(out));
    }

    // ---------------------------------------------------------------------
    // Recipient Picker
    // ---------------------------------------------------------------------
    var $picker = $('.hl-recipient-picker');
    if ($picker.length) initRecipientPicker($picker);

    function initRecipientPicker($wrap) {
        var $textarea = $wrap.closest('td').find('textarea[name="recipients"]');
        var $triggerSelect = $('select[name="trigger_key"]');
        var initial = { primary: [], cc: [] };
        try {
            var parsed = JSON.parse($wrap.attr('data-initial') || '{}');
            if (parsed && typeof parsed === 'object') {
                initial = {
                    primary: Array.isArray(parsed.primary) ? parsed.primary : [],
                    cc:      Array.isArray(parsed.cc)      ? parsed.cc      : []
                };
            }
        } catch (e) {}

        renderTokenCards($wrap.find('.hl-token-grid'),    'primary', initial.primary);
        renderTokenCards($wrap.find('.hl-token-list-cc'), 'cc',      initial.cc);
        hydratePills($wrap, initial);
        applyTriggerVisibility($wrap, $triggerSelect.val() || $wrap.attr('data-current-trigger') || '');
        applyPrimaryExclusion($wrap);
        serializeRecipients($wrap, $textarea);
        scheduleRecipientCount($wrap);

        $wrap.on('click', '.hl-token-card', function () {
            if ($(this).hasClass('hl-token-disabled')) return;
            var $card = $(this);
            var section = $card.closest('[class*="hl-token-"]').hasClass('hl-token-list-cc') ? 'cc' : 'primary';
            $card.toggleClass('hl-token-checked');
            var isChecked = $card.hasClass('hl-token-checked');
            $card.find('input[type=checkbox]').prop('checked', isChecked);
            $card.attr('aria-checked', isChecked ? 'true' : 'false');
            if (section === 'primary') applyPrimaryExclusion($wrap);
            serializeRecipients($wrap, $textarea);
            scheduleRecipientCount($wrap);
        });

        $wrap.on('keydown', '.hl-token-card', function (e) {
            if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                $(this).trigger('click');
            }
        });

        $wrap.on('keydown', '.hl-pill-input input', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addPillFromInput($(this));
                serializeRecipients($wrap, $textarea);
                scheduleRecipientCount($wrap);
            } else if (e.key === 'Backspace' && !$(this).val()) {
                $(this).closest('.hl-pill-input').find('.hl-pill').last().remove();
                serializeRecipients($wrap, $textarea);
                scheduleRecipientCount($wrap);
            }
        });

        $wrap.on('click', '.hl-pill-remove', function (e) {
            e.preventDefault();
            $(this).closest('.hl-pill').remove();
            serializeRecipients($wrap, $textarea);
            scheduleRecipientCount($wrap);
        });

        $triggerSelect.on('change', function () {
            var val = $(this).val();
            applyTriggerVisibility($wrap, val);
            serializeRecipients($wrap, $textarea);
            scheduleRecipientCount($wrap);

            // Task 7: show/hide offset and component type fields.
            var offsetTriggers = ['cron:component_upcoming', 'cron:component_overdue', 'cron:session_upcoming'];
            var componentTypeTriggers = ['cron:component_upcoming', 'cron:component_overdue'];
            $('.hl-wf-offset-row').toggle(offsetTriggers.indexOf(val) !== -1);
            $('.hl-wf-component-type-row').toggle(componentTypeTriggers.indexOf(val) !== -1);
            $('.hl-wf-session-fuzz-note').toggle(val === 'cron:session_upcoming');

            // Task 8: show/hide status sub-filter.
            var statusFilterTriggers = ['hl_coaching_session_status_changed', 'hl_rp_session_status_changed'];
            $('.hl-wf-status-filter-row').toggle(statusFilterTriggers.indexOf(val) !== -1);
        }).trigger('change');
    }

    function renderTokenCards($container, section, selected) {
        $container.empty();
        Object.keys(TOKENS).forEach(function (tokenKey) {
            var def = TOKENS[tokenKey];
            var isChecked = selected.indexOf(tokenKey) !== -1;
            var id = 'hl-tok-' + section + '-' + tokenKey;
            var $card = $(
                '<div class="hl-token-card" tabindex="0" role="checkbox" aria-checked="' + (isChecked ? 'true' : 'false') + '">' +
                    '<input type="checkbox" id="' + id + '" data-token="' + escHtml(tokenKey) + '"' + (isChecked ? ' checked' : '') + '>' +
                    '<span class="hl-token-label">' + escHtml(def.label) + '</span>' +
                    (section === 'primary' && def.description
                        ? '<span class="hl-token-desc">' + escHtml(def.description) + '</span>'
                        : '') +
                '</div>'
            );
            if (isChecked) $card.addClass('hl-token-checked');
            $container.append($card);
        });
    }

    function hydratePills($wrap, initial) {
        var $primaryRole  = $wrap.find('.hl-recipient-primary .hl-pill-input-role');
        var $primaryEmail = $wrap.find('.hl-recipient-primary .hl-pill-input-email');
        initial.primary.forEach(function (entry) {
            if (typeof entry !== 'string') return;
            if (entry.indexOf('role:') === 0) {
                injectPill($primaryRole, entry.substring(5), 'hl-pill-role');
            } else if (entry.indexOf('static:') === 0) {
                injectPill($primaryEmail, entry.substring(7), 'hl-pill-email');
            }
        });
        var $ccRole  = $wrap.find('.hl-recipient-cc .hl-pill-input-role');
        var $ccEmail = $wrap.find('.hl-recipient-cc .hl-pill-input-email');
        initial.cc.forEach(function (entry) {
            if (typeof entry !== 'string') return;
            if (entry.indexOf('role:') === 0) {
                injectPill($ccRole, entry.substring(5), 'hl-pill-role');
            } else if (entry.indexOf('static:') === 0) {
                injectPill($ccEmail, entry.substring(7), 'hl-pill-email');
            }
        });
    }

    function injectPill($box, value, cssClass) {
        var $input = $box.find('input').first();
        var $pill = $('<span class="hl-pill ' + cssClass + '" role="listitem"></span>')
            .attr('data-value', value)
            .text(value)
            .append(' <button type="button" class="hl-pill-remove" aria-label="Remove ' + escHtml(value) + '">&times;</button>');
        $input.before($pill);
    }

    function applyTriggerVisibility($wrap, trigger) {
        $wrap.find('.hl-token-card').each(function () {
            var tokenKey = $(this).find('input[type=checkbox]').attr('data-token');
            var def = TOKENS[tokenKey];
            if (!def) return;
            var compat = (def.triggers === '*') || (Array.isArray(def.triggers) && def.triggers.indexOf(trigger) !== -1);
            if (compat) {
                $(this).removeClass('hl-token-dim').removeAttr('title');
            } else {
                $(this).addClass('hl-token-dim').attr('title', "Your current trigger doesn't provide this recipient type.");
            }
        });
    }

    function applyPrimaryExclusion($wrap) {
        var primaryTokens = [];
        $wrap.find('.hl-recipient-primary .hl-token-card.hl-token-checked input[type=checkbox]').each(function () {
            primaryTokens.push($(this).attr('data-token'));
        });
        $wrap.find('.hl-recipient-cc .hl-token-card').each(function () {
            var key = $(this).find('input[type=checkbox]').attr('data-token');
            if (primaryTokens.indexOf(key) !== -1) {
                $(this).addClass('hl-token-disabled').attr('title', 'Already selected as Primary recipient');
                $(this).find('input[type=checkbox]').prop('checked', false);
                $(this).removeClass('hl-token-checked');
            } else {
                $(this).removeClass('hl-token-disabled').removeAttr('title');
            }
        });
    }

    function serializeRecipients($wrap, $textarea) {
        var out = { primary: [], cc: [] };
        $wrap.find('.hl-recipient-primary .hl-token-card.hl-token-checked input[type=checkbox]').each(function () {
            out.primary.push($(this).attr('data-token'));
        });
        $wrap.find('.hl-recipient-primary .hl-pill-input-role .hl-pill').each(function () {
            out.primary.push('role:' + $(this).attr('data-value'));
        });
        $wrap.find('.hl-recipient-primary .hl-pill-input-email .hl-pill').each(function () {
            out.primary.push('static:' + $(this).attr('data-value'));
        });
        $wrap.find('.hl-recipient-cc .hl-token-card.hl-token-checked input[type=checkbox]').each(function () {
            out.cc.push($(this).attr('data-token'));
        });
        $wrap.find('.hl-recipient-cc .hl-pill-input-role .hl-pill').each(function () {
            out.cc.push('role:' + $(this).attr('data-value'));
        });
        $wrap.find('.hl-recipient-cc .hl-pill-input-email .hl-pill').each(function () {
            out.cc.push('static:' + $(this).attr('data-value'));
        });
        $textarea.val(JSON.stringify(out));
    }

    // A.2.14 / A.7.7 / A.6.12 — debounced live count hint.
    var _countTimer = null;
    function scheduleRecipientCount($wrap) {
        clearTimeout(_countTimer);
        _countTimer = setTimeout(function () {
            fetchRecipientCount($wrap);
        }, 400);
    }

    function fetchRecipientCount($wrap) {
        var cfg = window.hlEmailWorkflowCfg || {};
        if (!cfg.ajaxUrl) return;
        var trigger = $('select[name="trigger_key"]').val() || '';
        var recipients = $wrap.closest('td').find('textarea[name="recipients"]').val() || '{}';
        var $hint = $wrap.find('.hl-recipient-count-hint');
        $hint.text('').removeClass('hl-hint-error');

        $.post(cfg.ajaxUrl, {
            action: 'hl_email_recipient_count',
            nonce: cfg.nonces.recipientCount,
            trigger: trigger,
            recipients: recipients
        }).done(function (res) {
            if (res && res.success && typeof res.data.count === 'number') {
                var count = res.data.count;
                var text = 'Would match ' + count + ' recipient' + (count === 1 ? '' : 's');
                if (res.data.samples && res.data.samples.length) {
                    text += ': ' + res.data.samples.join(', ');
                    if (count > res.data.samples.length) {
                        text += ' +' + (count - res.data.samples.length) + ' more';
                    }
                }
                $hint.text(text);
            } else {
                $hint.text('');
            }
        }).fail(function () {
            $hint.text('');
        });
    }

    // =====================================================================
    // MODULE: Summary Panel (v2)
    // =====================================================================
    var $summaryPanel = $('.hl-wf-summary-panel');
    if ($summaryPanel.length) {
        function updateSummary() {
            var templateText = $('select[name="template_id"] option:selected').text();
            var templateName = (templateText && templateText !== '— Select —') ? templateText : '';
            var triggerText = $('select[name="trigger_key"] option:selected').text();
            var triggerLabel = (triggerText && triggerText !== '— Select —') ? triggerText : '';

            // Build recipient list from checked tokens.
            var primaryTokens = [];
            $('.hl-recipient-primary .hl-token-card.hl-token-checked .hl-token-label').each(function() {
                primaryTokens.push($(this).text());
            });
            var ccTokens = [];
            $('.hl-recipient-cc .hl-token-card.hl-token-checked .hl-token-label').each(function() {
                ccTokens.push($(this).text());
            });
            // Note: also count pill-based recipients
            $('.hl-recipient-primary .hl-pill-role').each(function() {
                primaryTokens.push('role:' + $(this).attr('data-value'));
            });

            var recipientText = primaryTokens.length ? '<strong>' + primaryTokens.map(escHtml).join(', ') + '</strong>' : '<em style="color:#9CA3AF;">select recipients</em>';
            var ccText = ccTokens.length ? ' (CC: <strong>' + ccTokens.map(escHtml).join(', ') + '</strong>)' : '';

            // Build condition summary from condition rows.
            var condParts = [];
            $('.hl-condition-row').each(function() {
                var fieldLabel = $(this).find('.hl-condition-field option:selected').text();
                var opLabel = $(this).find('.hl-condition-op option:selected').text();
                var $pillbox = $(this).find('.hl-pill-input');
                var valText;
                if ($pillbox.length && $pillbox.find('.hl-pill').length) {
                    valText = $pillbox.find('.hl-pill').map(function(){ return $(this).attr('data-value'); }).get().join(', ');
                } else {
                    valText = $(this).find('.hl-condition-value').val() || '';
                }
                if (fieldLabel && fieldLabel !== '— Select field —') {
                    condParts.push(escHtml(fieldLabel) + ' ' + escHtml(opLabel) + ' <strong>' + escHtml(valText) + '</strong>');
                }
            });
            var condText = condParts.length ? condParts.join(' AND ') : '<em style="color:#9CA3AF;">no conditions (matches all)</em>';

            var sentence = '';
            if (templateName) {
                sentence += 'Send <strong>&ldquo;' + escHtml(templateName) + '&rdquo;</strong>';
            } else {
                sentence += 'Send <em style="color:#9CA3AF;">select a template</em>';
            }
            sentence += ' to ' + recipientText + ccText;
            if (triggerLabel) {
                sentence += '<br><br><strong>When:</strong> ' + escHtml(triggerLabel);
            }
            if (condParts.length) {
                sentence += '<br><br><strong>Only if:</strong> ' + condText;
            }

            $summaryPanel.find('.hl-wf-summary-sentence').html(sentence);
            updateGuardrails();
        }

        // Listen to all form changes.
        $('select[name="trigger_key"], select[name="template_id"]').on('change', updateSummary);
        // Condition/recipient changes fire through their existing modules.
        $(document).on('change click', '.hl-condition-builder, .hl-recipient-picker', function() {
            setTimeout(updateSummary, 100); // slight delay for serialization
        });
        // Initial render.
        setTimeout(updateSummary, 200);
    }

    // =====================================================================
    // MODULE: Guardrails (v2)
    // =====================================================================
    function updateGuardrails() {
        if (!$('.hl-wf-guardrails').length) return;

        var checks = {
            trigger: !!$('select[name="trigger_key"]').val(),
            template: !!$('select[name="template_id"]').val(),
            recipients: ($('.hl-recipient-picker .hl-token-card.hl-token-checked').length > 0
                || $('.hl-recipient-picker .hl-pill').length > 0)
        };

        $.each(checks, function(key, ok) {
            var $item = $('.hl-wf-guardrail[data-check="' + key + '"]');
            $item.toggleClass('hl-wf-guardrail-ok', ok);
            $item.find('.hl-wf-guardrail-icon').html(ok ? '&#10003;' : '&#10007;');
        });

        // Disable Activate button if template is missing (hard gate).
        var $activateBtn = $('.hl-wf-btn-activate');
        if (!checks.template) {
            $activateBtn.prop('disabled', true).attr('title', 'Select a template first');
        } else {
            $activateBtn.prop('disabled', false).removeAttr('title');
        }
    }

    // =====================================================================
    // MODULE: Send Test Email (v2)
    // =====================================================================
    $(document).on('click', '.hl-wf-send-test-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $box = $btn.closest('.hl-wf-send-test');
        var $feedback = $box.find('.hl-wf-send-test-feedback');
        var cfg = window.hlEmailWorkflowCfg || {};

        var templateId = $('select[name="template_id"]').val() || $('input[name="template_id"]').val();
        var enrollmentId = $box.find('select[name="hl_test_enrollment"]').val() || '';
        var toEmail = $box.find('input[name="hl_test_email"]').val() || '';

        if (!templateId) {
            $feedback.text('Select a template first.').css('color', '#DC2626');
            return;
        }
        if (!toEmail) {
            $feedback.text('Enter an email address.').css('color', '#DC2626');
            return;
        }

        $btn.prop('disabled', true).text('Sending...');
        $feedback.text('').css('color', '');

        $.post(cfg.ajaxUrl, {
            action: 'hl_email_send_test',
            nonce: cfg.nonces.sendTest,
            template_id: templateId,
            enrollment_id: enrollmentId,
            to_email: toEmail
        }).done(function(res) {
            if (res.success) {
                $feedback.text(res.data.message).css('color', '#059669');
            } else {
                $feedback.text(res.data || 'Send failed.').css('color', '#DC2626');
            }
        }).fail(function() {
            $feedback.text('Network error.').css('color', '#DC2626');
        }).always(function() {
            $btn.prop('disabled', false).text('Send Test');
        });
    });

    // =====================================================================
    // MODULE: Progressive Disclosure (v2)
    // =====================================================================
    (function() {
        var $progressiveCards = $('.hl-wf-card[data-progressive]');
        if (!$progressiveCards.length) return;

        // On edit (workflowId > 0), reveal immediately.
        if ((window.hlEmailWorkflowCfg || {}).workflowId > 0) {
            $progressiveCards.addClass('hl-wf-revealed');
            return;
        }

        // On new: reveal when trigger is selected.
        $('select[name="trigger_key"]').on('change', function() {
            if ($(this).val()) {
                $progressiveCards.addClass('hl-wf-revealed');
                // Hide onboarding, show summary.
                $('.hl-wf-summary-onboarding').hide();
                $('.hl-wf-summary-sentence').show();
            }
        });

        // Check on load in case trigger is pre-selected.
        if ($('select[name="trigger_key"]').val()) {
            $progressiveCards.addClass('hl-wf-revealed');
            $('.hl-wf-summary-onboarding').hide();
        }
    })();

    // =====================================================================
    // MODULE: Top Bar Name Sync (v2)
    // =====================================================================
    $('input[name="name"]').on('input', function() {
        var val = $(this).val() || 'New Workflow';
        $('.hl-wf-topbar-name').text(val);
    });

    // =====================================================================
    // MODULE: Activate Soft-Warning Dialog (v2)
    // =====================================================================
    $(document).on('click', '.hl-wf-btn-activate', function(e) {
        var templateId = $('select[name="template_id"]').val() || $('input[name="template_id"]').val();
        if (!templateId) {
            e.preventDefault();
            alert('Cannot activate: please select an email template first.');
            return;
        }

        var warnings = [];
        var hasRecipients = ($('.hl-recipient-picker .hl-token-card.hl-token-checked').length > 0
            || $('.hl-recipient-picker .hl-pill').length > 0);
        if (!hasRecipients) warnings.push('No recipients selected');

        if (warnings.length > 0) {
            if (!confirm('Activate with warnings?\n\n- ' + warnings.join('\n- ') + '\n\nContinue anyway?')) {
                e.preventDefault();
            }
        }
    });

    // =====================================================================
    // MODULE: Mobile Drawer Toggle (v2)
    // =====================================================================
    $(document).on('click', '.hl-wf-drawer-toggle', function() {
        var $panel = $('.hl-wf-summary-panel');
        $panel.toggleClass('hl-wf-drawer-open');
        $(this).text($panel.hasClass('hl-wf-drawer-open') ? 'Hide Summary' : 'Show Summary');
    });
});
