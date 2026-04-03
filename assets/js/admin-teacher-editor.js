/**
 * Teacher Assessment Instrument Visual Editor JS
 *
 * Handles: accordion toggle, add/remove sections & items,
 * add/remove scales & labels, contenteditable sync, scale key dropdown sync.
 *
 * @package HL_Core
 */
(function () {
    'use strict';

    var data = window.hlTeacherEditorData || {};
    var sectionIndex = data.sectionCount || 0;
    var scaleIndex = data.scaleCount || 0;
    var i18n = data.i18n || {};

    // Containers
    var sectionsContainer = document.getElementById('hl-te-sections-container');
    var scalesContainer   = document.getElementById('hl-te-scales-container');
    var addSectionBtn     = document.getElementById('hl-te-add-section');
    var addScaleBtn       = document.getElementById('hl-te-add-scale');

    if (!sectionsContainer || !scalesContainer) return;

    // ─── Helpers ──────────────────────────────────────────────

    function escHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Collect current scale keys from the scale panels.
     */
    function getScaleKeys() {
        var keys = [];
        scalesContainer.querySelectorAll('.hl-te-scale-key-input').forEach(function (input) {
            var val = input.value.trim();
            if (val) keys.push(val);
        });
        return keys;
    }

    /**
     * Rebuild all scale-key <select> dropdowns in section panels.
     */
    function refreshScaleKeySelects() {
        var keys = getScaleKeys();
        sectionsContainer.querySelectorAll('.hl-te-scale-key-select').forEach(function (sel) {
            var current = sel.value;
            sel.innerHTML = '<option value="">' + escHtml(i18n.selectScale || '-- Select Scale --') + '</option>';
            keys.forEach(function (k) {
                var opt = document.createElement('option');
                opt.value = k;
                opt.textContent = k;
                if (k === current) opt.selected = true;
                sel.appendChild(opt);
            });
        });
    }

    /**
     * Update the name attributes of a scale panel when its key changes.
     */
    function renameScaleFields(panel, oldKey, newKey) {
        if (!oldKey || oldKey === newKey) return;
        panel.querySelectorAll('[name]').forEach(function (el) {
            el.name = el.name.replace('scale_labels[' + oldKey + ']', 'scale_labels[' + newKey + ']');
        });
        var display = panel.querySelector('.hl-te-scale-key-display');
        if (display) display.textContent = newKey;
        var input = panel.querySelector('.hl-te-scale-key-input');
        if (input) input.setAttribute('data-old-key', newKey);
    }

    // ─── Rich-text sync ──────────────────────────────────────

    function syncRichText(editorEl) {
        var hidden = editorEl.parentNode.querySelector('.hl-te-richtext-hidden');
        if (hidden) {
            hidden.value = editorEl.innerHTML;
        }
    }

    function initRichTextInContainer(container) {
        // Toolbar buttons (guard against double-binding)
        container.querySelectorAll('.hl-te-rt-btn').forEach(function (btn) {
            if (btn._hlBound) return;
            btn._hlBound = true;
            btn.addEventListener('mousedown', function (e) {
                e.preventDefault(); // keep focus in contenteditable
            });
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var cmd = btn.getAttribute('data-command');
                document.execCommand(cmd, false, null);
                // Sync after command
                var editor = btn.closest('.hl-te-richtext-wrap').querySelector('.hl-te-richtext-editor');
                if (editor) syncRichText(editor);
            });
        });

        // Sync on input (guard against double-binding)
        container.querySelectorAll('.hl-te-richtext-editor').forEach(function (editor) {
            if (editor._hlBound) return;
            editor._hlBound = true;
            editor.addEventListener('input', function () {
                syncRichText(editor);
            });
            editor.addEventListener('blur', function () {
                syncRichText(editor);
            });
        });
    }

    // Sync all rich-text fields + TinyMCE before form submit
    var form = document.getElementById('hl-teacher-instrument-form');
    if (form) {
        form.addEventListener('submit', function () {
            // Sync TinyMCE (wp_editor) content to textarea
            if (typeof tinyMCE !== 'undefined') {
                tinyMCE.triggerSave();
            }
            // Sync contenteditable fields to hidden textareas
            document.querySelectorAll('.hl-te-richtext-editor').forEach(function (editor) {
                syncRichText(editor);
            });
        });
    }

    // ─── Section type toggle (show/hide fields per type) ────

    function toggleSectionTypeFields(sectionPanel) {
        var typeSelect = sectionPanel.querySelector('.hl-te-section-type-select');
        if (!typeSelect) return;
        var val = typeSelect.value;
        var isScale     = val === 'scale';
        var isIndicator = val === 'indicator_checklist';
        var isContext    = val === 'context_checkboxes';
        var isSpecial   = isIndicator || isContext;

        // Anchor fields only for scale type
        sectionPanel.querySelectorAll('.hl-te-anchor-fields').forEach(function (el) {
            el.style.display = isScale ? 'flex' : 'none';
        });
        // Hide scored-only rows for non-scored types
        sectionPanel.querySelectorAll('.hl-te-row-scored-only').forEach(function (el) {
            el.style.display = isSpecial ? 'none' : '';
        });
        // Toggle content panels
        var itemsWrap      = sectionPanel.querySelector('.hl-te-items-wrap');
        var indicatorsWrap = sectionPanel.querySelector('.hl-te-indicators-wrap');
        var contextWrap    = sectionPanel.querySelector('.hl-te-context-wrap');
        if (itemsWrap)      itemsWrap.style.display      = isSpecial ? 'none' : '';
        if (indicatorsWrap) indicatorsWrap.style.display  = isIndicator ? '' : 'none';
        if (contextWrap)    contextWrap.style.display     = isContext ? '' : 'none';
        // Update header meta label
        var meta = sectionPanel.querySelector('.hl-te-section-header-meta');
        if (meta) {
            var count, label;
            if (isIndicator) {
                count = sectionPanel.querySelectorAll('.hl-te-indicator-row').length;
                label = i18n.indicators || 'indicators';
            } else if (isContext) {
                count = sectionPanel.querySelectorAll('.hl-te-context-row').length;
                label = i18n.options || 'options';
            } else {
                count = sectionPanel.querySelectorAll('.hl-te-item-row').length;
                label = i18n.items || 'items';
            }
            meta.textContent = count + ' ' + label;
        }
    }

    function initSectionTypeToggle(container) {
        container.querySelectorAll('.hl-te-section-type-select').forEach(function (sel) {
            if (sel._hlTypeBound) return;
            sel._hlTypeBound = true;
            toggleSectionTypeFields(sel.closest('.hl-te-section-panel'));
            sel.addEventListener('change', function () {
                toggleSectionTypeFields(sel.closest('.hl-te-section-panel'));
            });
        });
    }

    // ─── Accordion toggle ────────────────────────────────────

    function initAccordionInContainer(container) {
        container.querySelectorAll('.hl-te-section-header').forEach(function (header) {
            if (header._hlBound) return;
            header._hlBound = true;
            header.addEventListener('click', function (e) {
                // Don't toggle if clicking a button inside the header
                if (e.target.closest('button')) return;
                header.closest('.hl-te-section-panel').classList.toggle('hl-te-collapsed');
            });
        });
    }

    // ─── Section title live sync ─────────────────────────────

    function initTitleSyncInContainer(container) {
        container.querySelectorAll('.hl-te-section-title-input').forEach(function (input) {
            if (input._hlBound) return;
            input._hlBound = true;
            input.addEventListener('input', function () {
                var panel = input.closest('.hl-te-section-panel');
                var titleSpan = panel.querySelector('.hl-te-section-header-title');
                titleSpan.textContent = input.value || (i18n.untitledSection || '(Untitled Section)');
            });
        });
    }

    // ─── Remove handlers ─────────────────────────────────────

    function initRemoveHandlers(container) {
        // Remove section
        container.querySelectorAll('.hl-te-remove-section').forEach(function (btn) {
            if (btn._hlBound) return;
            btn._hlBound = true;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (confirm(i18n.removeSection || 'Remove this section?')) {
                    btn.closest('.hl-te-section-panel').remove();
                }
            });
        });

        // Remove item
        container.querySelectorAll('.hl-te-remove-item').forEach(function (btn) {
            if (btn._hlBound) return;
            btn._hlBound = true;
            btn.addEventListener('click', function () {
                if (confirm(i18n.removeItem || 'Remove this item?')) {
                    btn.closest('.hl-te-item-row').remove();
                }
            });
        });

        // Add item
        container.querySelectorAll('.hl-te-add-item').forEach(function (btn) {
            if (btn._hlBound) return;
            btn._hlBound = true;
            btn.addEventListener('click', function () {
                var panel = btn.closest('.hl-te-section-panel');
                var secIdx = panel.getAttribute('data-section-index');
                var tbody = panel.querySelector('.hl-te-items-body');
                var itemIdx = tbody.querySelectorAll('.hl-te-item-row').length;
                var prefix = 'sections[' + secIdx + '][items][' + itemIdx + ']';

                var isScale = false;
                var typeSelect = panel.querySelector('.hl-te-section-type-select');
                if (typeSelect) isScale = typeSelect.value === 'scale';

                var tr = document.createElement('tr');
                tr.className = 'hl-te-item-row';
                tr.innerHTML =
                    '<td><input type="text" name="' + prefix + '[key]" value="" class="widefat" /></td>' +
                    '<td>' +
                        '<div class="hl-te-richtext-wrap hl-te-richtext-inline">' +
                            '<div class="hl-te-richtext-toolbar hl-te-rt-mini">' +
                                '<button type="button" class="hl-te-rt-btn" data-command="bold" title="Bold"><strong>B</strong></button>' +
                                '<button type="button" class="hl-te-rt-btn" data-command="italic" title="Italic"><em>I</em></button>' +
                                '<button type="button" class="hl-te-rt-btn" data-command="underline" title="Underline"><u>U</u></button>' +
                            '</div>' +
                            '<div class="hl-te-richtext-editor" contenteditable="true"></div>' +
                            '<textarea name="' + prefix + '[text]" class="hl-te-richtext-hidden" style="display:none;"></textarea>' +
                        '</div>' +
                        '<div class="hl-te-anchor-fields" style="display:' + (isScale ? 'flex' : 'none') + ';">' +
                            '<label class="hl-te-anchor-label">0 = <input type="text" name="' + prefix + '[left_anchor]" value="" placeholder="e.g. Not at all" class="regular-text" /></label>' +
                            '<label class="hl-te-anchor-label">10 = <input type="text" name="' + prefix + '[right_anchor]" value="" placeholder="e.g. Very" class="regular-text" /></label>' +
                        '</div>' +
                    '</td>' +
                    '<td><button type="button" class="button-link button-link-delete hl-te-remove-item">' + escHtml(i18n.remove || 'Remove') + '</button></td>';

                tbody.appendChild(tr);
                initRichTextInContainer(tr);
                initRemoveHandlers(tr);
            });
        });

        // Add indicator
        container.querySelectorAll('.hl-te-add-indicator').forEach(function (btn) {
            if (btn._hlBound) return;
            btn._hlBound = true;
            btn.addEventListener('click', function () {
                var panel = btn.closest('.hl-te-section-panel');
                var secIdx = panel.getAttribute('data-section-index');
                var tbody = panel.querySelector('.hl-te-indicators-body');

                var tr = document.createElement('tr');
                tr.className = 'hl-te-indicator-row';
                tr.innerHTML =
                    '<td><input type="text" name="sections[' + secIdx + '][indicators][]" value="" class="widefat" /></td>' +
                    '<td><button type="button" class="button-link button-link-delete hl-te-remove-indicator">' + escHtml(i18n.remove || 'Remove') + '</button></td>';

                tbody.appendChild(tr);
                initRemoveHandlers(tr);
            });
        });

        // Remove indicator
        container.querySelectorAll('.hl-te-remove-indicator').forEach(function (btn) {
            if (btn._hlBound) return;
            btn._hlBound = true;
            btn.addEventListener('click', function () {
                if (confirm(i18n.removeIndicator || 'Remove this indicator?')) {
                    btn.closest('.hl-te-indicator-row').remove();
                }
            });
        });

        // Add context option
        container.querySelectorAll('.hl-te-add-context-opt').forEach(function (btn) {
            if (btn._hlBound) return;
            btn._hlBound = true;
            btn.addEventListener('click', function () {
                var panel = btn.closest('.hl-te-section-panel');
                var secIdx = panel.getAttribute('data-section-index');
                var tbody = panel.querySelector('.hl-te-context-body');

                var tr = document.createElement('tr');
                tr.className = 'hl-te-context-row';
                tr.innerHTML =
                    '<td><input type="text" name="sections[' + secIdx + '][context_keys][]" value="" class="widefat" placeholder="e.g. free_play" /></td>' +
                    '<td><input type="text" name="sections[' + secIdx + '][context_labels][]" value="" class="widefat" placeholder="e.g. Free Play" /></td>' +
                    '<td><button type="button" class="button-link button-link-delete hl-te-remove-context-opt">' + escHtml(i18n.remove || 'Remove') + '</button></td>';

                tbody.appendChild(tr);
                initRemoveHandlers(tr);
            });
        });

        // Remove context option
        container.querySelectorAll('.hl-te-remove-context-opt').forEach(function (btn) {
            if (btn._hlBound) return;
            btn._hlBound = true;
            btn.addEventListener('click', function () {
                if (confirm(i18n.removeOption || 'Remove this option?')) {
                    btn.closest('.hl-te-context-row').remove();
                }
            });
        });
    }

    // ─── Scale panel handlers ────────────────────────────────

    function initScaleHandlers(container) {
        // Type toggle
        container.querySelectorAll('.hl-te-scale-type-select').forEach(function (sel) {
            if (sel._hlBound) return;
            sel._hlBound = true;
            sel.addEventListener('change', function () {
                var panel = sel.closest('.hl-te-scale-panel');
                var hidden = panel.querySelector('.hl-te-scale-type-hidden');
                hidden.value = sel.value;
                panel.querySelector('.hl-te-scale-array-fields').style.display = sel.value === 'array' ? '' : 'none';
                panel.querySelector('.hl-te-scale-object-fields').style.display = sel.value === 'object' ? '' : 'none';
            });
        });

        // Scale key rename
        container.querySelectorAll('.hl-te-scale-key-input').forEach(function (input) {
            if (input._hlBound) return;
            input._hlBound = true;
            input.addEventListener('change', function () {
                var oldKey = input.getAttribute('data-old-key');
                var newKey = input.value.trim();
                if (newKey && newKey !== oldKey) {
                    renameScaleFields(input.closest('.hl-te-scale-panel'), oldKey, newKey);
                    refreshScaleKeySelects();
                }
            });
        });

        // Remove scale
        container.querySelectorAll('.hl-te-remove-scale').forEach(function (btn) {
            if (btn._hlBound) return;
            btn._hlBound = true;
            btn.addEventListener('click', function () {
                if (confirm(i18n.removeScale || 'Remove this scale?')) {
                    btn.closest('.hl-te-scale-panel').remove();
                    refreshScaleKeySelects();
                }
            });
        });

        // Add label
        container.querySelectorAll('.hl-te-add-label').forEach(function (btn) {
            if (btn._hlBound) return;
            btn._hlBound = true;
            btn.addEventListener('click', function () {
                var panel = btn.closest('.hl-te-scale-panel');
                var keyInput = panel.querySelector('.hl-te-scale-key-input');
                var scaleKey = keyInput.value.trim() || 'new_scale';
                var list = panel.querySelector('.hl-te-scale-labels-list');

                var row = document.createElement('div');
                row.className = 'hl-te-scale-label-row';
                row.innerHTML =
                    '<input type="text" name="scale_labels[' + escHtml(scaleKey) + '][labels][]" value="" class="regular-text" />' +
                    '<button type="button" class="button-link hl-te-remove-label">&times;</button>';
                list.appendChild(row);

                row.querySelector('.hl-te-remove-label').addEventListener('click', function () {
                    row.remove();
                });
            });
        });

        // Remove label (existing)
        container.querySelectorAll('.hl-te-remove-label').forEach(function (btn) {
            if (btn._hlBound) return;
            btn._hlBound = true;
            btn.addEventListener('click', function () {
                btn.closest('.hl-te-scale-label-row').remove();
            });
        });
    }

    // ─── Add Scale ───────────────────────────────────────────

    if (addScaleBtn) {
        addScaleBtn.addEventListener('click', function () {
            var newKey = 'scale_' + scaleIndex;
            scaleIndex++;

            var panel = document.createElement('div');
            panel.className = 'hl-te-scale-panel';
            panel.setAttribute('data-scale-index', scaleIndex);
            panel.innerHTML =
                '<div class="hl-te-panel-header">' +
                    '<strong>' + escHtml(i18n.scaleLabel || 'Scale:') + '</strong> ' +
                    '<code class="hl-te-scale-key-display">' + escHtml(newKey) + '</code>' +
                    '<span class="hl-te-panel-actions">' +
                        '<button type="button" class="button-link hl-te-remove-scale">' + escHtml(i18n.remove || 'Remove') + '</button>' +
                    '</span>' +
                '</div>' +
                '<div class="hl-te-panel-body">' +
                    '<p><label>' + escHtml(i18n.scaleKeyLabel || 'Scale Key:') + '</label>' +
                    '<input type="text" class="regular-text hl-te-scale-key-input" value="' + escHtml(newKey) + '" data-old-key="' + escHtml(newKey) + '" /></p>' +
                    '<p><label>' + escHtml(i18n.typeLabel || 'Type:') + '</label>' +
                    '<select class="hl-te-scale-type-select">' +
                        '<option value="array" selected>' + escHtml(i18n.likertType || 'Likert (ordered labels)') + '</option>' +
                        '<option value="object">' + escHtml(i18n.numericType || 'Numeric (low/high anchors)') + '</option>' +
                    '</select>' +
                    '<input type="hidden" name="scale_labels[' + escHtml(newKey) + '][type]" value="array" class="hl-te-scale-type-hidden" /></p>' +
                    '<div class="hl-te-scale-array-fields">' +
                        '<label>' + escHtml(i18n.labelsInOrder || 'Labels (in order):') + '</label>' +
                        '<div class="hl-te-scale-labels-list"></div>' +
                        '<button type="button" class="button button-small hl-te-add-label">' + escHtml(i18n.addLabel || '+ Add Label') + '</button>' +
                    '</div>' +
                    '<div class="hl-te-scale-object-fields" style="display:none;">' +
                        '<p><label>' + escHtml(i18n.lowAnchor || 'Low anchor:') + '</label>' +
                        '<input type="text" name="scale_labels[' + escHtml(newKey) + '][low]" value="" class="regular-text" /></p>' +
                        '<p><label>' + escHtml(i18n.highAnchor || 'High anchor:') + '</label>' +
                        '<input type="text" name="scale_labels[' + escHtml(newKey) + '][high]" value="" class="regular-text" /></p>' +
                    '</div>' +
                '</div>';

            scalesContainer.appendChild(panel);
            initScaleHandlers(panel);
            refreshScaleKeySelects();
        });
    }

    // ─── Add Section ─────────────────────────────────────────

    if (addSectionBtn) {
        addSectionBtn.addEventListener('click', function () {
            var idx = sectionIndex;
            sectionIndex++;
            var prefix = 'sections[' + idx + ']';
            var keys = getScaleKeys();

            var scaleOpts = '<option value="">' + escHtml(i18n.selectScale || '-- Select Scale --') + '</option>';
            keys.forEach(function (k) {
                scaleOpts += '<option value="' + escHtml(k) + '">' + escHtml(k) + '</option>';
            });

            var panel = document.createElement('div');
            panel.className = 'hl-te-section-panel';
            panel.setAttribute('data-section-index', idx);
            panel.innerHTML =
                '<div class="hl-te-section-header" role="button" tabindex="0">' +
                    '<span class="hl-te-section-toggle dashicons dashicons-arrow-down-alt2"></span>' +
                    '<span class="hl-te-section-header-title">' + escHtml(i18n.untitledSection || '(Untitled Section)') + '</span>' +
                    '<span class="hl-te-section-header-meta">0 ' + escHtml(i18n.items || 'items') + '</span>' +
                    '<span class="hl-te-panel-actions">' +
                        '<button type="button" class="button-link hl-te-remove-section">' + escHtml(i18n.remove || 'Remove') + '</button>' +
                    '</span>' +
                '</div>' +
                '<div class="hl-te-section-body">' +
                    '<table class="form-table">' +
                        '<tr><th><label>' + escHtml(i18n.sectionKey || 'Section Key') + '</label></th>' +
                        '<td><input type="text" name="' + prefix + '[section_key]" value="" class="regular-text" required /></td></tr>' +
                        '<tr><th><label>' + escHtml(i18n.titleLabel || 'Title') + '</label></th>' +
                        '<td><input type="text" name="' + prefix + '[title]" value="" class="regular-text hl-te-section-title-input" /></td></tr>' +
                        '<tr class="hl-te-row-scored-only"><th><label>' + escHtml(i18n.descriptionLabel || 'Description') + '</label></th>' +
                        '<td>' +
                            '<div class="hl-te-richtext-wrap">' +
                                '<div class="hl-te-richtext-toolbar">' +
                                    '<button type="button" class="hl-te-rt-btn" data-command="bold" title="Bold"><strong>B</strong></button>' +
                                    '<button type="button" class="hl-te-rt-btn" data-command="italic" title="Italic"><em>I</em></button>' +
                                    '<button type="button" class="hl-te-rt-btn" data-command="underline" title="Underline"><u>U</u></button>' +
                                '</div>' +
                                '<div class="hl-te-richtext-editor" contenteditable="true"></div>' +
                                '<textarea name="' + prefix + '[description]" class="hl-te-richtext-hidden" style="display:none;"></textarea>' +
                            '</div>' +
                        '</td></tr>' +
                        '<tr><th><label>' + escHtml(i18n.typeFieldLabel || 'Type') + '</label></th>' +
                        '<td><select name="' + prefix + '[type]" class="hl-te-section-type-select">' +
                            '<option value="likert">' + escHtml(i18n.likert || 'Likert') + '</option>' +
                            '<option value="scale">' + escHtml(i18n.scaleType || 'Scale (0-10)') + '</option>' +
                            '<option value="indicator_checklist">' + escHtml(i18n.indicatorChecklist || 'Domains & Indicators (Yes/No)') + '</option>' +
                            '<option value="context_checkboxes">' + escHtml(i18n.contextCheckboxes || 'Context Checkboxes') + '</option>' +
                        '</select></td></tr>' +
                        '<tr class="hl-te-row-scored-only"><th><label>' + escHtml(i18n.scaleKeyField || 'Scale Key') + '</label></th>' +
                        '<td><select name="' + prefix + '[scale_key]" class="hl-te-scale-key-select">' + scaleOpts + '</select></td></tr>' +
                    '</table>' +
                    '<div class="hl-te-items-wrap">' +
                        '<h4>' + escHtml(i18n.itemsLabel || 'Items') + '</h4>' +
                        '<table class="widefat hl-te-items-table">' +
                            '<thead><tr>' +
                                '<th style="width:180px;">' + escHtml(i18n.itemKey || 'Item Key') + '</th>' +
                                '<th>' + escHtml(i18n.itemText || 'Item Text') + '</th>' +
                                '<th style="width:80px;">' + escHtml(i18n.actions || 'Actions') + '</th>' +
                            '</tr></thead>' +
                            '<tbody class="hl-te-items-body"></tbody>' +
                        '</table>' +
                        '<p><button type="button" class="button button-small hl-te-add-item">' + escHtml(i18n.addItem || '+ Add Item') + '</button></p>' +
                    '</div>' +
                    '<div class="hl-te-indicators-wrap" style="display:none;">' +
                        '<h4>' + escHtml(i18n.indicatorsLabel || 'Indicators') + '</h4>' +
                        '<table class="widefat hl-te-indicators-table">' +
                            '<thead><tr>' +
                                '<th>' + escHtml(i18n.indicatorText || 'Indicator Text') + '</th>' +
                                '<th style="width:80px;">' + escHtml(i18n.actions || 'Actions') + '</th>' +
                            '</tr></thead>' +
                            '<tbody class="hl-te-indicators-body"></tbody>' +
                        '</table>' +
                        '<p><button type="button" class="button button-small hl-te-add-indicator">' + escHtml(i18n.addIndicator || '+ Add Indicator') + '</button></p>' +
                    '</div>' +
                    '<div class="hl-te-context-wrap" style="display:none;">' +
                        '<h4>' + escHtml(i18n.checkboxOptions || 'Checkbox Options') + '</h4>' +
                        '<table class="widefat hl-te-context-table">' +
                            '<thead><tr>' +
                                '<th style="width:200px;">' + escHtml(i18n.keyLabel || 'Key') + '</th>' +
                                '<th>' + escHtml(i18n.labelLabel || 'Label') + '</th>' +
                                '<th style="width:80px;">' + escHtml(i18n.actions || 'Actions') + '</th>' +
                            '</tr></thead>' +
                            '<tbody class="hl-te-context-body"></tbody>' +
                        '</table>' +
                        '<p><button type="button" class="button button-small hl-te-add-context-opt">' + escHtml(i18n.addOption || '+ Add Option') + '</button></p>' +
                    '</div>' +
                '</div>';

            sectionsContainer.appendChild(panel);
            initAccordionInContainer(panel);
            initTitleSyncInContainer(panel);
            initRemoveHandlers(panel);
            initRichTextInContainer(panel);
            initSectionTypeToggle(panel);
        });
    }

    // ─── Initialize everything on page load ──────────────────

    initAccordionInContainer(sectionsContainer);
    initTitleSyncInContainer(sectionsContainer);
    initRemoveHandlers(sectionsContainer);
    initRichTextInContainer(sectionsContainer);
    initRichTextInContainer(document); // catch description editors outside sections
    initScaleHandlers(scalesContainer);
    initSectionTypeToggle(sectionsContainer);

})();
