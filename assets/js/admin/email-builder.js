/**
 * HL Email Builder — Block-based email template editor.
 *
 * Dependencies: jQuery, wp-color-picker.
 * Sortable.js loaded from CDN (no build step).
 *
 * Config: window.hlEmailBuilder (set by PHP).
 */
(function ($) {
    'use strict';

    var config = window.hlEmailBuilder || {};
    var blocks = config.blocks || [];
    var autosaveTimer = null;
    var isDirty = false;
    var sortableInstance = null;

    // =========================================================================
    // Init
    // =========================================================================

    $(document).ready(function () {
        renderAllBlocks();
        initSortable();
        bindEvents();
        runHealthCheck();

        // Restore draft if requested.
        $('#hl-restore-draft').on('click', function () {
            if (config.draftData) {
                try {
                    var draft = typeof config.draftData === 'string'
                        ? JSON.parse(config.draftData)
                        : config.draftData;
                    if (draft.blocks) blocks = draft.blocks;
                    if (draft.subject) $('#hl-eb-subject').val(draft.subject);
                    if (draft.name) $('#hl-eb-name').val(draft.name);
                    renderAllBlocks();
                } catch (e) {}
            }
            $('#hl-draft-banner').hide();
        });
        $('#hl-discard-draft').on('click', function () {
            $('#hl-draft-banner').hide();
        });
    });

    // =========================================================================
    // Rendering
    // =========================================================================

    function renderAllBlocks() {
        var $container = $('#hl-eb-blocks');
        $container.empty();

        if (blocks.length === 0) {
            $container.html('<p style="color:#999;text-align:center;padding:40px;">Click "Add Block" to start building your email.</p>');
            return;
        }

        blocks.forEach(function (block, i) {
            $container.append(renderBlock(block, i));
        });

        initSortable();
    }

    function renderBlock(block, index) {
        var $wrap = $('<div class="hl-eb-block" data-index="' + index + '"></div>');
        var $toolbar = $('<div class="hl-eb-block-toolbar">' +
            '<span class="hl-eb-block-type">' + escHtml(block.type) + '</span>' +
            '<span class="hl-eb-block-actions">' +
            '<button type="button" class="hl-eb-block-dup" title="Duplicate">&#x2398;</button>' +
            '<button type="button" class="hl-eb-block-del" title="Delete">&times;</button>' +
            '</span></div>');
        $wrap.append($toolbar);

        switch (block.type) {
            case 'text':
                var $editor = $('<div class="hl-eb-text-editor" contenteditable="true"></div>');
                $editor.html(block.content || '<p>Enter text here...</p>');
                $editor.on('input', function () {
                    blocks[index].content = $(this).html();
                    markDirty();
                    runHealthCheck();
                });
                // Mini toolbar for text formatting.
                var $miniBar = $('<div class="hl-eb-mini-toolbar">' +
                    '<button type="button" data-cmd="bold" title="Bold"><b>B</b></button>' +
                    '<button type="button" data-cmd="italic" title="Italic"><i>I</i></button>' +
                    '<button type="button" data-cmd="createLink" title="Link">&#x1F517;</button>' +
                    '<select class="hl-eb-merge-tag-select"><option value="">Insert tag...</option></select>' +
                    '</div>');
                // Populate merge tag dropdown.
                var $select = $miniBar.find('.hl-eb-merge-tag-select');
                var groups = config.mergeTagsGrouped || {};
                for (var cat in groups) {
                    var $group = $('<optgroup label="' + escHtml(cat) + '"></optgroup>');
                    for (var key in groups[cat]) {
                        $group.append('<option value="{{' + escHtml(key) + '}}">{{' + escHtml(key) + '}}</option>');
                    }
                    $select.append($group);
                }
                $miniBar.find('[data-cmd]').on('click', function (e) {
                    e.preventDefault();
                    var cmd = $(this).data('cmd');
                    if (cmd === 'createLink') {
                        var url = prompt('Enter URL:');
                        if (url) document.execCommand('createLink', false, url);
                    } else {
                        document.execCommand(cmd, false, null);
                    }
                    blocks[index].content = $editor.html();
                    markDirty();
                });
                $select.on('change', function () {
                    var tag = $(this).val();
                    if (tag) {
                        document.execCommand('insertText', false, tag);
                        blocks[index].content = $editor.html();
                        $(this).val('');
                        markDirty();
                    }
                });
                $wrap.append($miniBar).append($editor);
                break;

            case 'image':
                var $imgPreview = $('<div class="hl-eb-img-preview"></div>');
                if (block.src) {
                    $imgPreview.html('<img src="' + escHtml(block.src) + '" style="max-width:100%;" />');
                }
                var $imgBtn = $('<button type="button" class="button hl-eb-choose-image">Choose Image</button>');
                var $altInput = $('<input type="text" placeholder="Alt text" value="' + escHtml(block.alt || '') + '">');
                $altInput.on('change', function () {
                    blocks[index].alt = $(this).val();
                    markDirty();
                });
                $imgBtn.on('click', function () {
                    var frame = wp.media({ title: 'Select Image', multiple: false, library: { type: 'image' } });
                    frame.on('select', function () {
                        var attachment = frame.state().get('selection').first().toJSON();
                        // Block SVGs.
                        if (/\.svg$/i.test(attachment.url)) {
                            alert('SVG files are not allowed in emails.');
                            return;
                        }
                        blocks[index].src = attachment.url;
                        blocks[index].width = Math.min(attachment.width || 600, 600);
                        $imgPreview.html('<img src="' + escHtml(attachment.url) + '" style="max-width:100%;" />');
                        markDirty();
                    });
                    frame.open();
                });
                $wrap.append($imgPreview).append($imgBtn).append($altInput);
                break;

            case 'button':
                var $label = $('<input type="text" placeholder="Button label" value="' + escHtml(block.label || 'Click Here') + '">');
                var $url = $('<input type="text" placeholder="URL or {{merge_tag}}" value="' + escHtml(block.url || '') + '">');
                $label.on('change', function () { blocks[index].label = $(this).val(); markDirty(); });
                $url.on('change', function () { blocks[index].url = $(this).val(); markDirty(); });
                $wrap.append($label).append($url);
                var $colorWrap = $('<div style="margin-top:8px;"><label>BG: </label></div>');
                var $bgInput = $('<input type="text" class="hl-eb-color" value="' + escHtml(block.bg_color || '#2C7BE5') + '">');
                $colorWrap.append($bgInput);
                $wrap.append($colorWrap);
                setTimeout(function () {
                    $bgInput.wpColorPicker({ change: function (e, ui) {
                        blocks[index].bg_color = ui.color.toString();
                        markDirty();
                    }});
                }, 0);
                break;

            case 'divider':
                $wrap.append('<hr style="border-top:' + (block.thickness || 1) + 'px solid ' + escHtml(block.color || '#E5E7EB') + ';">');
                break;

            case 'spacer':
                var h = block.height || 24;
                var $slider = $('<input type="range" min="8" max="80" value="' + h + '">');
                var $spacerPreview = $('<div class="hl-eb-spacer-preview" style="height:' + h + 'px;background:#f0f0f0;"></div>');
                $slider.on('input', function () {
                    var val = parseInt($(this).val());
                    blocks[index].height = val;
                    $spacerPreview.css('height', val + 'px');
                    markDirty();
                });
                $wrap.append($slider).append($spacerPreview);
                break;

            case 'columns':
                var $splitSelect = $('<select><option value="50/50">50/50</option><option value="60/40">60/40</option></select>');
                $splitSelect.val(block.split || '50/50');
                $splitSelect.on('change', function () { blocks[index].split = $(this).val(); markDirty(); });
                $wrap.append($splitSelect);
                $wrap.append('<div style="display:flex;gap:8px;">' +
                    '<div style="flex:1;border:1px dashed #ccc;padding:8px;min-height:60px;">Left column blocks (edit in JSON)</div>' +
                    '<div style="flex:1;border:1px dashed #ccc;padding:8px;min-height:60px;">Right column blocks (edit in JSON)</div>' +
                    '</div>');
                break;
        }

        return $wrap;
    }

    // =========================================================================
    // Sortable
    // =========================================================================

    function initSortable() {
        var el = document.getElementById('hl-eb-blocks');
        if (!el || typeof Sortable === 'undefined') {
            // Load Sortable.js from CDN.
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
            script.onload = function () { initSortable(); };
            document.head.appendChild(script);
            return;
        }
        if (sortableInstance) sortableInstance.destroy();
        sortableInstance = new Sortable(el, {
            animation: 150,
            handle: '.hl-eb-block-type',
            onEnd: function (evt) {
                var item = blocks.splice(evt.oldIndex, 1)[0];
                blocks.splice(evt.newIndex, 0, item);
                renderAllBlocks();
                markDirty();
            }
        });
    }

    // =========================================================================
    // Events
    // =========================================================================

    function bindEvents() {
        // Add block.
        $(document).on('click', '.hl-eb-add-block', function () {
            var type = $(this).data('type');
            var newBlock = { type: type };
            switch (type) {
                case 'text':    newBlock.content = '<p>Enter text here...</p>'; break;
                case 'image':   newBlock.src = ''; newBlock.alt = ''; newBlock.width = 600; break;
                case 'button':  newBlock.label = 'Click Here'; newBlock.url = ''; newBlock.bg_color = '#2C7BE5'; newBlock.text_color = '#FFFFFF'; break;
                case 'divider': newBlock.color = '#E5E7EB'; newBlock.thickness = 1; break;
                case 'spacer':  newBlock.height = 24; break;
                case 'columns': newBlock.split = '50/50'; newBlock.left = []; newBlock.right = []; break;
            }
            blocks.push(newBlock);
            renderAllBlocks();
            markDirty();
        });

        // Delete block.
        $(document).on('click', '.hl-eb-block-del', function () {
            var idx = $(this).closest('.hl-eb-block').data('index');
            blocks.splice(idx, 1);
            renderAllBlocks();
            markDirty();
        });

        // Duplicate block.
        $(document).on('click', '.hl-eb-block-dup', function () {
            var idx = $(this).closest('.hl-eb-block').data('index');
            var clone = JSON.parse(JSON.stringify(blocks[idx]));
            blocks.splice(idx + 1, 0, clone);
            renderAllBlocks();
            markDirty();
        });

        // Save.
        $('#hl-eb-save').on('click', saveTemplate);

        // Subject line health check.
        $('#hl-eb-subject').on('input', function () { markDirty(); runHealthCheck(); });

        // Preview.
        var searchTimer = null;
        $('#hl-eb-preview-search').on('input', function () {
            clearTimeout(searchTimer);
            var val = $(this).val();
            searchTimer = setTimeout(function () { searchEnrollments(val); }, 300);
        });
        $('#hl-eb-preview-enrollment').on('change', refreshPreview);
        $(document).on('click', '.hl-eb-preview-toggle', function () {
            $('.hl-eb-preview-toggle').removeClass('active');
            $(this).addClass('active');
            var mode = $(this).data('mode');
            var $frame = $('#hl-eb-preview-frame');
            if (mode === 'mobile') $frame.css('width', '375px');
            else $frame.css('width', '600px');
            // Dark mode toggled via iframe class (best-effort).
        });

        // Merge tag click-to-copy.
        $(document).on('click', '.hl-eb-tag-item', function () {
            var tag = '{{' + $(this).data('tag') + '}}';
            if (navigator.clipboard) {
                navigator.clipboard.writeText(tag);
            }
        });
    }

    // =========================================================================
    // Save
    // =========================================================================

    function saveTemplate() {
        var $btn = $('#hl-eb-save');
        $btn.prop('disabled', true).text('Saving...');

        $.post(config.ajaxUrl, {
            action: 'hl_email_template_save',
            nonce: config.nonce,
            template_id: config.templateId,
            template_key: $('#hl-eb-template-key').val(),
            name: $('#hl-eb-name').val(),
            subject: $('#hl-eb-subject').val(),
            category: $('#hl-eb-category').val(),
            status: $('#hl-eb-status').val(),
            blocks_json: JSON.stringify(blocks)
        }, function (res) {
            $btn.prop('disabled', false).text('Save Template');
            if (res.success) {
                config.templateId = res.data.template_id;
                isDirty = false;
                $('#hl-eb-autosave-status').text('Saved').fadeIn().delay(2000).fadeOut();
            } else {
                alert(res.data || 'Save failed.');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Save Template');
            alert('Network error. Please try again.');
        });
    }

    // =========================================================================
    // Autosave
    // =========================================================================

    function markDirty() {
        isDirty = true;
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(doAutosave, 3000);

        // localStorage backup.
        try {
            localStorage.setItem('hl_email_draft_backup', JSON.stringify({
                blocks: blocks,
                subject: $('#hl-eb-subject').val(),
                name: $('#hl-eb-name').val()
            }));
        } catch (e) {}
    }

    function doAutosave() {
        if (!isDirty) return;
        $.post(config.ajaxUrl, {
            action: 'hl_email_template_autosave',
            nonce: config.nonce,
            template_id: config.templateId,
            draft_data: JSON.stringify({
                blocks: blocks,
                subject: $('#hl-eb-subject').val(),
                name: $('#hl-eb-name').val()
            })
        }, function (res) {
            if (res.success) {
                $('#hl-eb-autosave-status').text('Draft saved ' + res.data.saved_at).fadeIn();
            }
        });
    }

    // =========================================================================
    // Preview
    // =========================================================================

    function searchEnrollments(query) {
        if (query.length < 2) {
            $('#hl-eb-preview-enrollment').hide();
            return;
        }
        $.post(config.ajaxUrl, {
            action: 'hl_email_preview_search',
            nonce: config.nonce,
            search: query
        }, function (res) {
            if (res.success && res.data.length) {
                var $sel = $('#hl-eb-preview-enrollment').empty().show();
                $sel.append('<option value="">Select enrollment...</option>');
                res.data.forEach(function (item) {
                    $sel.append('<option value="' + item.enrollment_id + '">' + escHtml(item.label) + '</option>');
                });
            }
        });
    }

    function refreshPreview() {
        var enrollmentId = $('#hl-eb-preview-enrollment').val() || 0;
        var url = config.previewUrl +
            '&template_id=' + config.templateId +
            '&enrollment_id=' + enrollmentId +
            '&subject=' + encodeURIComponent($('#hl-eb-subject').val()) +
            '&blocks_json=' + encodeURIComponent(JSON.stringify(blocks));
        $('#hl-eb-preview-frame').attr('src', url);
    }

    // =========================================================================
    // Email Health Check
    // =========================================================================

    function runHealthCheck() {
        var warnings = [];
        var errors = [];
        var subject = $('#hl-eb-subject').val() || '';

        // Subject length.
        if (subject.length > 70) warnings.push('Subject too long (' + subject.length + ' chars). May be truncated on mobile.');
        if (subject.length > 0 && subject.length < 15) warnings.push('Subject too short (' + subject.length + ' chars). May look like spam.');

        // ALL CAPS.
        var capsWords = (subject.match(/\b[A-Z]{2,}\b/g) || []).length;
        if (capsWords > 2) warnings.push('More than 2 ALL CAPS words in subject.');

        // Exclamation marks.
        var exclamations = (subject.match(/!/g) || []).length;
        if (exclamations > 1) warnings.push('More than 1 exclamation mark in subject.');

        // Spam trigger words.
        var spamWords = ['free', 'urgent', 'act now', 'limited', 'winner', 'congratulations'];
        var subjectLower = subject.toLowerCase();
        spamWords.forEach(function (word) {
            if (subjectLower.indexOf(word) !== -1) {
                warnings.push('Spam trigger word "' + word + '" found in subject.');
            }
        });

        // SVG in blocks.
        blocks.forEach(function (b) {
            if (b.type === 'image' && b.src && /\.svg$/i.test(b.src)) {
                errors.push('SVG files are not allowed in emails.');
            }
        });

        // Render.
        var $warnings = $('#hl-eb-health-warnings').empty();
        var $light = $('#hl-eb-health-light');

        if (errors.length) {
            $light.attr('data-status', 'red');
            errors.forEach(function (e) { $warnings.append('<li class="hl-eb-error">' + escHtml(e) + '</li>'); });
        }
        warnings.forEach(function (w) { $warnings.append('<li class="hl-eb-warning">' + escHtml(w) + '</li>'); });

        if (errors.length) $light.attr('data-status', 'red');
        else if (warnings.length) $light.attr('data-status', 'yellow');
        else $light.attr('data-status', 'green');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
