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
    // A.1.1 / A.2.1 — every Sortable instance ever created is tracked here so we can destroy
    // all of them before each renderAllBlocks() to avoid handle collisions / stale refs.
    var sortableRegistry = [];

    // Undo / redo state (populated in Task C, stubbed here so earlier tasks can call pushUndo safely).
    var undoStack = [];
    var redoStack = [];
    var MAX_UNDO  = 50;

    // Autosave-race debounce window after an undo/redo (A.2.4). 5000ms.
    var autosaveSuppressUntil = 0;

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
        destroyAllSortables();
        closeMiniPalette();

        var $canvas = $('#hl-eb-blocks');
        var scrollTop = $canvas.scrollTop();

        // Remember which contenteditable (if any) had focus so we can restore it after re-render.
        var $focused = $(document.activeElement);
        var focusIndex = null;
        var focusPath  = null; // for nested: [parent, side, colIndex]
        if ($focused.hasClass('hl-eb-text-editor') && !$focused.hasClass('hl-eb-text-editor-nested')) {
            var $card = $focused.closest('.hl-eb-block');
            focusIndex = parseInt($card.data('index'), 10);
        } else if ($focused.hasClass('hl-eb-text-editor-nested')) {
            var $n = $focused.closest('.hl-eb-block-nested');
            focusPath = [
                parseInt($n.data('parent'), 10),
                $n.data('side'),
                parseInt($n.data('col-index'), 10)
            ];
        }

        $canvas.empty();

        if (blocks.length === 0) {
            $canvas.html('<p style="color:#999;text-align:center;padding:40px;">Click "Add Block" to start building your email.</p>');
            return;
        }

        blocks.forEach(function (block, i) {
            $canvas.append(renderBlock(block, i));
        });

        initSortable();

        // Restore scroll + focus.
        $canvas.scrollTop(scrollTop);
        if (focusIndex !== null) {
            var $ed = $canvas.find('.hl-eb-block[data-index="' + focusIndex + '"]')
                             .find('.hl-eb-text-editor').first();
            if ($ed.length) { $ed.focus(); }
        } else if (focusPath) {
            var sel = '.hl-eb-block-nested[data-parent="' + focusPath[0] + '"]' +
                      '[data-side="' + focusPath[1] + '"]' +
                      '[data-col-index="' + focusPath[2] + '"]';
            var $ed2 = $canvas.find(sel).find('.hl-eb-text-editor-nested').first();
            if ($ed2.length) { $ed2.focus(); }
        }
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

                // Apply block-level text-align (CSS only — NOT via execCommand).
                var currentAlign = block.text_align || 'left';
                $editor.css('text-align', currentAlign);

                // Apply block-level font-size (CSS only).
                var currentSize = block.font_size || 16;
                $editor.css('font-size', currentSize + 'px');

                // Text snapshot debounce — push to undo stack on blur OR 2s idle after last keystroke.
                var textSnapshotTimer = null;
                $editor.on('focus', function () {
                    // Capture pre-mutation snapshot into the global pending slot.
                    // flushPendingTextSnapshot() on Ctrl+Z / blur / 2s idle will commit it.
                    if (!window._hlPendingTextSnap) {
                        window._hlPendingTextSnap = JSON.parse(JSON.stringify(blocks));
                    }
                });
                $editor.on('input', function () {
                    blocks[index].content = $(this).html();
                    markDirty();
                    runHealthCheck();
                    clearTimeout(textSnapshotTimer);
                    textSnapshotTimer = setTimeout(function () {
                        // 2s idle → commit the pending pre-mutation snapshot.
                        flushPendingTextSnapshot();
                        updateUndoButtons();
                        // Seed a fresh pre-mutation snapshot for the next edit burst.
                        window._hlPendingTextSnap = JSON.parse(JSON.stringify(blocks));
                    }, 2000);
                });
                $editor.on('blur', function () {
                    clearTimeout(textSnapshotTimer);
                    flushPendingTextSnapshot();
                    updateUndoButtons();
                });
                // A.2.1 Safari — beforeinput historyUndo must flush pending snapshot first.
                $editor.on('beforeinput', function (e) {
                    var ev = e.originalEvent || e;
                    if (ev && (ev.inputType === 'historyUndo' || ev.inputType === 'historyRedo')) {
                        flushPendingTextSnapshot();
                        updateUndoButtons();
                    }
                });

                // Mini toolbar: B | I | link | align | font-size | merge tags
                var $miniBar = $(
                    '<div class="hl-eb-mini-toolbar">' +
                        '<button type="button" data-cmd="bold" title="Bold"><b>B</b></button>' +
                        '<button type="button" data-cmd="italic" title="Italic"><i>I</i></button>' +
                        '<button type="button" data-cmd="createLink" title="Link">&#x1F517;</button>' +
                        '<span class="hl-eb-mini-sep"></span>' +
                        '<div class="hl-eb-align-group" role="group" aria-label="Text alignment">' +
                            '<button type="button" class="hl-eb-align" data-align="left"   title="Align left"><span class="dashicons dashicons-editor-alignleft"></span></button>' +
                            '<button type="button" class="hl-eb-align" data-align="center" title="Align center"><span class="dashicons dashicons-editor-aligncenter"></span></button>' +
                            '<button type="button" class="hl-eb-align" data-align="right"  title="Align right"><span class="dashicons dashicons-editor-alignright"></span></button>' +
                        '</div>' +
                        '<span class="hl-eb-mini-sep"></span>' +
                        '<select class="hl-eb-size-select" title="Font size">' +
                            '<option value="12">12px</option>' +
                            '<option value="14">14px</option>' +
                            '<option value="16">16px</option>' +
                            '<option value="18">18px</option>' +
                            '<option value="20">20px</option>' +
                            '<option value="24">24px</option>' +
                        '</select>' +
                        '<span class="hl-eb-mini-sep"></span>' +
                        '<select class="hl-eb-merge-tag-select"><option value="">Insert tag...</option></select>' +
                    '</div>'
                );

                // Mark the currently-active alignment button (A.2.11 — default "left" is active when text_align absent).
                $miniBar.find('.hl-eb-align[data-align="' + currentAlign + '"]').addClass('active');
                // Set the font size select to current block value.
                $miniBar.find('.hl-eb-size-select').val(String(currentSize));

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

                // Bold / italic / link (existing behavior).
                $miniBar.find('[data-cmd]').on('click', function (e) {
                    e.preventDefault();
                    pushUndo();
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

                // Alignment buttons (radio group, block-level via CSS).
                $miniBar.find('.hl-eb-align').on('click', function (e) {
                    e.preventDefault();
                    pushUndo();
                    var align = $(this).data('align');
                    $miniBar.find('.hl-eb-align').removeClass('active');
                    $(this).addClass('active');
                    if (align === 'left') {
                        delete blocks[index].text_align;
                    } else {
                        blocks[index].text_align = align;
                    }
                    $editor.css('text-align', align);
                    markDirty();
                });

                // Font size select (block-level via CSS).
                $miniBar.find('.hl-eb-size-select').on('change', function () {
                    pushUndo();
                    var val = parseInt($(this).val(), 10);
                    if (val === 16) {
                        delete blocks[index].font_size;
                    } else {
                        blocks[index].font_size = val;
                    }
                    $editor.css('font-size', val + 'px');
                    markDirty();
                });

                // Merge tag insert (existing behavior).
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
                    pushUndo();
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
                        pushUndo();
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
                $label.on('change', function () { pushUndo(); blocks[index].label = $(this).val(); markDirty(); });
                $url.on('change',   function () { pushUndo(); blocks[index].url   = $(this).val(); markDirty(); });
                $wrap.append($label).append($url);
                var $colorWrap = $('<div style="margin-top:8px;"><label>BG: </label></div>');
                var $bgInput = $('<input type="text" class="hl-eb-color" value="' + escHtml(block.bg_color || '#2C7BE5') + '">');
                $colorWrap.append($bgInput);
                $wrap.append($colorWrap);
                setTimeout(function () {
                    $bgInput.wpColorPicker({ change: function (e, ui) {
                        pushUndo();
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
                var spacerDragStarted = false;
                $slider.on('mousedown touchstart', function () {
                    if (!spacerDragStarted) {
                        pushUndo();
                        spacerDragStarted = true;
                    }
                });
                $slider.on('mouseup touchend', function () {
                    spacerDragStarted = false;
                });
                $slider.on('input', function () {
                    var val = parseInt($(this).val());
                    blocks[index].height = val;
                    $spacerPreview.css('height', val + 'px');
                    markDirty();
                });
                $wrap.append($slider).append($spacerPreview);
                break;

            case 'columns':
                // Columns has its own renderer that builds the full wrap (toolbar + body).
                // Bypass the shared $wrap/$toolbar scaffolding from renderBlock() by returning directly.
                return renderColumnsBlock(block, index);
        }

        return $wrap;
    }

    // =========================================================================
    // Columns block (nested editing — Spec §2.1)
    // =========================================================================

    /** Default block factory used by both top-level palette and nested palette. */
    function makeDefaultBlock(type) {
        switch (type) {
            case 'text':    return { type: 'text',    content: '<p>Enter text here...</p>' };
            case 'image':   return { type: 'image',   src: '', alt: '', width: 300 };
            case 'button':  return { type: 'button',  label: 'Click Here', url: '', bg_color: '#2C7BE5', text_color: '#FFFFFF' };
            case 'divider': return { type: 'divider', color: '#E5E7EB', thickness: 1 };
            case 'spacer':  return { type: 'spacer',  height: 24 };
            case 'columns': return { type: 'columns', split: '50/50', left: [], right: [] };
            default:        return { type: type };
        }
    }

    function renderColumnsBlock(block, index) {
        var $wrap = $('<div class="hl-eb-block hl-eb-block-columns" data-index="' + index + '"></div>');

        // Header bar — matches other blocks but with the split selector added.
        var $toolbar = $(
            '<div class="hl-eb-block-toolbar">' +
                '<span class="hl-eb-block-type">Columns</span>' +
                '<span class="hl-eb-block-actions">' +
                    '<select class="hl-eb-col-split" title="Split ratio">' +
                        '<option value="50/50">50 / 50</option>' +
                        '<option value="60/40">60 / 40</option>' +
                        '<option value="40/60">40 / 60</option>' +
                        '<option value="33/67">33 / 67</option>' +
                        '<option value="67/33">67 / 33</option>' +
                    '</select>' +
                    '<button type="button" class="hl-eb-block-dup" title="Duplicate">&#x2398;</button>' +
                    '<button type="button" class="hl-eb-block-del" title="Delete">&times;</button>' +
                '</span>' +
            '</div>'
        );
        $toolbar.find('.hl-eb-col-split').val(block.split || '50/50');
        $toolbar.find('.hl-eb-col-split').on('change', function () {
            pushUndo();
            blocks[index].split = $(this).val();
            markDirty();
            renderAllBlocks();
        });
        $wrap.append($toolbar);

        // Two-column body.
        var widths = getColumnWidthsJS(block.split || '50/50');
        var $body = $('<div class="hl-eb-col-body" style="display:flex;gap:12px;"></div>');
        var $left  = renderColumnContainer(block.left  || [], index, 'left',  widths[0]);
        var $right = renderColumnContainer(block.right || [], index, 'right', widths[1]);
        $body.append($left).append($right);
        $wrap.append($body);

        return $wrap;
    }

    /** Mirror of PHP get_column_widths() for the JS side. */
    function getColumnWidthsJS(split) {
        switch (split) {
            case '60/40': return [60, 40];
            case '40/60': return [40, 60];
            case '33/67': return [33, 67];
            case '67/33': return [67, 33];
            case '50/50':
            default:      return [50, 50];
        }
    }

    function renderColumnContainer(colBlocks, parentIndex, side, widthPct) {
        var label = side === 'left' ? 'Left Column' : 'Right Column';
        var $col = $(
            '<div class="hl-eb-col" style="flex:' + widthPct + ' 1 0;"' +
                 ' data-parent="' + parentIndex + '" data-side="' + side + '">' +
                '<div class="hl-eb-col-label">' + escHtml(label) + ' (' + widthPct + '%)</div>' +
                '<div class="hl-eb-col-blocks"></div>' +
                '<div class="hl-eb-col-add-wrap">' +
                    '<button type="button" class="button button-small hl-eb-col-add-btn">+ Add Block</button>' +
                '</div>' +
            '</div>'
        );

        var $colBlocks = $col.find('.hl-eb-col-blocks');
        colBlocks.forEach(function (nested, colIndex) {
            $colBlocks.append(renderNestedBlock(nested, parentIndex, side, colIndex));
        });

        // Per-column Sortable — distinct group name prevents cross-column drag (A.1.1).
        setTimeout(function () {
            var el = $colBlocks.get(0);
            if (!el || typeof Sortable === 'undefined') return;
            var inst = new Sortable(el, {
                group: { name: 'col-' + parentIndex + '-' + side, pull: false, put: false },
                handle: '.hl-eb-drag-handle-nested',
                animation: 150,
                onEnd: function (evt) {
                    pushUndo();
                    var arr = blocks[parentIndex][side];
                    var item = arr.splice(evt.oldIndex, 1)[0];
                    arr.splice(evt.newIndex, 0, item);
                    markDirty();
                    renderAllBlocks();
                }
            });
            sortableRegistry.push(inst);
        }, 0);

        return $col;
    }

    function renderNestedBlock(block, parentIndex, side, colIndex) {
        var $wrap = $('<div class="hl-eb-block hl-eb-block-nested"' +
                      ' data-parent="' + parentIndex + '"' +
                      ' data-side="' + side + '"' +
                      ' data-col-index="' + colIndex + '"></div>');

        var $toolbar = $(
            '<div class="hl-eb-block-toolbar hl-eb-block-toolbar-nested">' +
                '<span class="hl-eb-block-type hl-eb-block-type-nested hl-eb-drag-handle-nested">' + escHtml(block.type) + '</span>' +
                '<span class="hl-eb-block-actions">' +
                    '<button type="button" class="hl-eb-nested-move" title="Move to other column">&#x21C4;</button>' +
                    '<button type="button" class="hl-eb-nested-dup"  title="Duplicate">&#x2398;</button>' +
                    '<button type="button" class="hl-eb-nested-del"  title="Delete">&times;</button>' +
                '</span>' +
            '</div>'
        );
        $wrap.append($toolbar);

        var shim = new NestedBlockShim(parentIndex, side, colIndex);
        $wrap.append(renderNestedContent(block, shim));
        return $wrap;
    }

    /**
     * NestedBlockShim — proxies reads/writes of a nested block through to
     * blocks[parentIndex][side][colIndex].
     */
    function NestedBlockShim(parentIndex, side, colIndex) {
        this.parentIndex = parentIndex;
        this.side = side;
        this.colIndex = colIndex;
    }
    NestedBlockShim.prototype.get = function () {
        return blocks[this.parentIndex][this.side][this.colIndex];
    };
    NestedBlockShim.prototype.set = function (key, value) {
        blocks[this.parentIndex][this.side][this.colIndex][key] = value;
    };
    NestedBlockShim.prototype.unset = function (key) {
        delete blocks[this.parentIndex][this.side][this.colIndex][key];
    };

    /**
     * Render the inner content (minus toolbar) of a nested block.
     */
    function renderNestedContent(block, shim) {
        var $content = $('<div class="hl-eb-nested-content"></div>');
        switch (block.type) {
            case 'text':
                var $editor = $('<div class="hl-eb-text-editor hl-eb-text-editor-nested" contenteditable="true"></div>');
                $editor.html(block.content || '<p>Text...</p>');
                $editor.css('text-align', block.text_align || 'left');
                $editor.css('font-size',  (block.font_size || 14) + 'px');
                $editor.on('focus', function () {
                    if (!window._hlPendingTextSnap) {
                        window._hlPendingTextSnap = JSON.parse(JSON.stringify(blocks));
                    }
                });
                $editor.on('input', function () {
                    shim.set('content', $(this).html());
                    markDirty();
                });
                $editor.on('blur', function () {
                    flushPendingTextSnapshot();
                    updateUndoButtons();
                });
                $content.append($editor);
                break;
            case 'image':
                var cur = block.src
                    ? '<img src="' + escHtml(block.src) + '" style="max-width:100%;" />'
                    : '<em style="color:#999;">(no image)</em>';
                var $imgPreview = $('<div class="hl-eb-img-preview">' + cur + '</div>');
                var $imgBtn = $('<button type="button" class="button button-small hl-eb-choose-image-nested">Choose</button>');
                $imgBtn.on('click', function () {
                    var frame = wp.media({ title: 'Select Image', multiple: false, library: { type: 'image' } });
                    frame.on('select', function () {
                        pushUndo();
                        var a = frame.state().get('selection').first().toJSON();
                        if (/\.svg$/i.test(a.url)) { alert('SVG files are not allowed in emails.'); return; }
                        shim.set('src', a.url);
                        shim.set('width', Math.min(a.width || 300, 300));
                        $imgPreview.html('<img src="' + escHtml(a.url) + '" style="max-width:100%;" />');
                        markDirty();
                    });
                    frame.open();
                });
                $content.append($imgPreview).append($imgBtn);
                break;
            case 'button':
                var $lbl = $('<input type="text" placeholder="Label" value="' + escHtml(block.label || 'Click') + '">');
                var $u   = $('<input type="text" placeholder="URL" value="' + escHtml(block.url || '') + '">');
                $lbl.on('change', function () { pushUndo(); shim.set('label', $(this).val()); markDirty(); });
                $u.on('change',   function () { pushUndo(); shim.set('url',   $(this).val()); markDirty(); });
                $content.append($lbl).append($u);
                break;
            case 'divider':
                $content.append('<hr style="border-top:' + (block.thickness || 1) + 'px solid ' + escHtml(block.color || '#E5E7EB') + ';">');
                break;
            case 'spacer':
                var h = block.height || 16;
                var $sl = $('<input type="range" min="8" max="80" value="' + h + '">');
                var $pv = $('<div class="hl-eb-spacer-preview" style="height:' + h + 'px;background:#f0f0f0;"></div>');
                var spacerDragStartedN = false;
                $sl.on('mousedown touchstart', function () {
                    if (!spacerDragStartedN) { pushUndo(); spacerDragStartedN = true; }
                });
                $sl.on('mouseup touchend', function () { spacerDragStartedN = false; });
                $sl.on('input', function () {
                    var val = parseInt($(this).val());
                    shim.set('height', val);
                    $pv.css('height', val + 'px');
                    markDirty();
                });
                $content.append($sl).append($pv);
                break;
        }
        return $content;
    }

    // =========================================================================
    // Nested block action handlers (event delegation on .hl-eb-block-nested)
    // =========================================================================

    function nestedCoords($el) {
        var $b = $el.closest('.hl-eb-block-nested');
        return {
            parent:   parseInt($b.data('parent'), 10),
            side:     $b.data('side'),
            colIndex: parseInt($b.data('col-index'), 10)
        };
    }

    function deleteNestedBlock(parentIndex, side, colIndex) {
        pushUndo();
        blocks[parentIndex][side].splice(colIndex, 1);
        markDirty();
        renderAllBlocks();
    }

    function duplicateNestedBlock(parentIndex, side, colIndex) {
        pushUndo();
        var clone = JSON.parse(JSON.stringify(blocks[parentIndex][side][colIndex]));
        blocks[parentIndex][side].splice(colIndex + 1, 0, clone);
        markDirty();
        renderAllBlocks();
    }

    function addNestedBlock(parentIndex, side, type) {
        pushUndo();
        blocks[parentIndex][side].push(makeDefaultBlock(type));
        markDirty();
        renderAllBlocks();
    }

    /** A.2.5 / A.6.9 — move a nested block to the other column in the same Columns block. */
    function moveNestedBlock(parentIndex, fromSide, toSide, colIndex) {
        pushUndo();
        var item = blocks[parentIndex][fromSide].splice(colIndex, 1)[0];
        blocks[parentIndex][toSide].push(item);
        markDirty();
        renderAllBlocks();
    }

    // =========================================================================
    // Mini palette popup (Spec §2.1)
    // =========================================================================

    // Single-open invariant — close any existing popup before showing a new one.
    var $miniPaletteOpen = null;

    function showMiniPalette($button, parentIndex, side) {
        closeMiniPalette();
        var $popup = $(
            '<div class="hl-eb-mini-palette" role="menu" aria-label="Add nested block">' +
                '<button type="button" class="hl-eb-mini-palette-btn" data-type="text">Text</button>' +
                '<button type="button" class="hl-eb-mini-palette-btn" data-type="image">Image</button>' +
                '<button type="button" class="hl-eb-mini-palette-btn" data-type="button">Button</button>' +
                '<button type="button" class="hl-eb-mini-palette-btn" data-type="divider">Divider</button>' +
                '<button type="button" class="hl-eb-mini-palette-btn" data-type="spacer">Spacer</button>' +
                '<button type="button" class="hl-eb-mini-palette-btn hl-eb-mini-palette-disabled" disabled' +
                    ' title="Columns cannot be nested — email clients don\'t render nested tables reliably.">Columns</button>' +
            '</div>'
        );
        $button.after($popup);
        $miniPaletteOpen = $popup;

        $popup.find('.hl-eb-mini-palette-btn:not([disabled])').on('click', function () {
            var type = $(this).data('type');
            closeMiniPalette();
            addNestedBlock(parentIndex, side, type);
        });
    }

    function closeMiniPalette() {
        if ($miniPaletteOpen) {
            $miniPaletteOpen.remove();
            $miniPaletteOpen = null;
        }
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
        destroyAllSortables();
        sortableInstance = new Sortable(el, {
            // A.1.1: distinct handle class for top-level, filter out nested blocks so the
            // top-level Sortable cannot intercept drags that belong to a nested column.
            filter: '.hl-eb-block-nested',
            preventOnFilter: false,
            animation: 150,
            handle: '.hl-eb-block-type:not(.hl-eb-block-type-nested)',
            onEnd: function (evt) {
                pushUndo();
                var item = blocks.splice(evt.oldIndex, 1)[0];
                blocks.splice(evt.newIndex, 0, item);
                renderAllBlocks();
                markDirty();
            }
        });
        sortableRegistry.push(sortableInstance);
    }

    // =========================================================================
    // Events
    // =========================================================================

    function bindEvents() {
        // Add block.
        $(document).on('click', '.hl-eb-add-block', function () {
            pushUndo();
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

        // Delete top-level block. Nested blocks have their own .hl-eb-nested-del class.
        $(document).on('click', '.hl-eb-block-del', function (e) {
            var $card = $(this).closest('.hl-eb-block');
            if ($card.hasClass('hl-eb-block-nested')) return;
            pushUndo();
            var idx = $card.data('index');
            blocks.splice(idx, 1);
            renderAllBlocks();
            markDirty();
        });

        // Duplicate top-level block.
        $(document).on('click', '.hl-eb-block-dup', function (e) {
            var $card = $(this).closest('.hl-eb-block');
            if ($card.hasClass('hl-eb-block-nested')) return;
            pushUndo();
            var idx = $card.data('index');
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

        // Undo / redo buttons.
        $('#hl-eb-undo').on('click', function () { undo(); });
        $('#hl-eb-redo').on('click', function () { redo(); });

        // Keyboard shortcuts — global, but guarded against contenteditable / input focus
        // so the browser's native per-field undo keeps working.
        $(document).on('keydown', function (e) {
            var isMeta = e.ctrlKey || e.metaKey;
            if (!isMeta) return;

            var key = String.fromCharCode(e.which || e.keyCode).toLowerCase();
            var target = e.target || {};
            var tag    = (target.tagName || '').toLowerCase();
            var isEditable = tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable;

            // Inside a contenteditable text editor: let the browser undo the text edits.
            // We do flush any pending snapshot so Ctrl+Z after snapshot still has the
            // pre-mutation state in our stack when focus next leaves the editor.
            if (isEditable) {
                if (key === 'z') {
                    flushPendingTextSnapshot();
                    updateUndoButtons();
                }
                return;
            }

            if (key === 'z' && !e.shiftKey) {
                e.preventDefault();
                undo();
            } else if (key === 'y' || (key === 'z' && e.shiftKey)) {
                e.preventDefault();
                redo();
            }
        });

        // Dismiss undo-clear notice — AJAX write to user meta so it does not reappear.
        $(document).on('click', '.hl-eb-undo-notice-dismiss', function () {
            var $notice = $(this).closest('.hl-eb-undo-notice');
            $notice.hide();
            window.hlEmailUndoNoticeSeen = true;
            $.post(config.ajaxUrl, {
                action: 'hl_email_builder_dismiss_undo_notice',
                nonce: config.nonce,
                template_id: $notice.data('template-id') || 0
            });
        });

        // Nested block delete.
        $(document).on('click', '.hl-eb-nested-del', function (e) {
            e.stopPropagation();
            var c = nestedCoords($(this));
            deleteNestedBlock(c.parent, c.side, c.colIndex);
        });

        // Nested block duplicate.
        $(document).on('click', '.hl-eb-nested-dup', function (e) {
            e.stopPropagation();
            var c = nestedCoords($(this));
            duplicateNestedBlock(c.parent, c.side, c.colIndex);
        });

        // Nested block move-to-other-column.
        $(document).on('click', '.hl-eb-nested-move', function (e) {
            e.stopPropagation();
            var c = nestedCoords($(this));
            var toSide = c.side === 'left' ? 'right' : 'left';
            moveNestedBlock(c.parent, c.side, toSide, c.colIndex);
        });

        // "+ Add Block" inside a column → show mini palette.
        $(document).on('click', '.hl-eb-col-add-btn', function (e) {
            e.stopPropagation();
            var $col = $(this).closest('.hl-eb-col');
            var parentIndex = parseInt($col.data('parent'), 10);
            var side = $col.data('side');
            showMiniPalette($(this), parentIndex, side);
        });

        // Click outside the mini palette closes it.
        $(document).on('click', function (e) {
            if (!$miniPaletteOpen) return;
            var $t = $(e.target);
            if ($t.closest('.hl-eb-mini-palette').length) return;
            if ($t.hasClass('hl-eb-col-add-btn')) return;
            closeMiniPalette();
        });

        // Escape closes the mini palette.
        $(document).on('keydown', function (e) {
            if (e.which === 27 && $miniPaletteOpen) {
                closeMiniPalette();
                e.stopPropagation();
            }
        });

        // Preview modal open / close.
        $('#hl-eb-preview-btn').on('click', openPreviewModal);
        $('#hl-eb-modal-close').on('click', closePreviewModal);
        $(document).on('click', '.hl-eb-modal-overlay', function (e) {
            if (e.target === this) closePreviewModal();
        });

        // Device toggles.
        $(document).on('click', '.hl-eb-modal-device', function () {
            setModalDevice($(this).data('mode'));
        });

        // Modal enrollment search.
        var modalSearchTimer = null;
        $('#hl-eb-modal-enrollment-search').on('input', function () {
            clearTimeout(modalSearchTimer);
            var q = $(this).val();
            modalSearchTimer = setTimeout(function () { modalSearchEnrollments(q); }, 300);
        });
        $(document).on('click', '.hl-eb-modal-search-result', function () {
            modalState.enrollmentId = parseInt($(this).data('enrollment-id'), 10) || 0;
            $('#hl-eb-modal-enrollment-search').val($(this).text());
            $('#hl-eb-modal-search-results').hide();
            modalState.searchDropdownOpen = false;
            refreshModalPreview();
        });

        // Focus trap inside modal.
        $(document).on('keydown', trapModalTab);

        // A.3.17 — Escape precedence: close search dropdown first, then modal.
        $(document).on('keydown', function (e) {
            if (e.which !== 27) return;
            if (!modalState.open) return;
            if (modalState.searchDropdownOpen) {
                $('#hl-eb-modal-search-results').hide();
                modalState.searchDropdownOpen = false;
                e.stopPropagation();
                return;
            }
            closePreviewModal();
            e.stopPropagation();
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

                // A.7.8 — first save with a non-empty undo stack shows the one-time notice.
                var hadHistory = undoStack.length > 0 || redoStack.length > 0;
                undoStack = [];
                redoStack = [];
                updateUndoButtons();
                if (hadHistory && !window.hlEmailUndoNoticeSeen) {
                    $('#hl-eb-undo-notice').show();
                }
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
        var delay = 3000;
        var remaining = autosaveSuppressUntil - Date.now();
        if (remaining > delay) {
            delay = remaining;
        }
        autosaveTimer = setTimeout(doAutosave, delay);

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

    // =========================================================================
    // Preview modal (Spec §2.3)
    // =========================================================================

    var modalState = {
        open: false,
        mode: 'desktop',       // desktop | mobile | dark
        enrollmentId: 0,
        lastFocusEl: null,
        searchDropdownOpen: false
    };

    function openPreviewModal() {
        var $modal = $('#hl-eb-modal');
        if (!$modal.length) return;

        modalState.lastFocusEl = document.activeElement;
        modalState.open = true;
        modalState.mode = 'desktop';
        var existing = parseInt($('#hl-eb-preview-enrollment').val(), 10);
        modalState.enrollmentId = isNaN(existing) ? 0 : existing;

        $modal.find('.hl-eb-modal-device').removeClass('active')
              .filter('[data-mode="desktop"]').addClass('active');
        $modal.find('#hl-eb-modal-title').text($('#hl-eb-name').val() || 'Preview');
        $modal.find('#hl-eb-modal-subtitle').text('');

        $('#hl-eb-modal-iframe').css('width', '600px');

        $modal.attr('aria-hidden', 'false').css('display', 'flex').hide().fadeIn(200);
        $('body').css('overflow', 'hidden');

        refreshModalPreview();

        setTimeout(function () { $('#hl-eb-modal-close').focus(); }, 220);
    }

    function closePreviewModal() {
        var $modal = $('#hl-eb-modal');
        if (!modalState.open) return;
        modalState.open = false;
        modalState.searchDropdownOpen = false;
        $('#hl-eb-modal-search-results').hide().empty();
        $modal.fadeOut(200, function () {
            $modal.attr('aria-hidden', 'true').css('display', 'none');
            $('#hl-eb-modal-iframe').removeAttr('srcdoc').attr('srcdoc', '');
        });
        $('body').css('overflow', '');
        if (modalState.lastFocusEl) {
            try { modalState.lastFocusEl.focus(); } catch (e) {}
        }
    }

    function refreshModalPreview() {
        var $iframe = $('#hl-eb-modal-iframe');
        var $skel   = $('#hl-eb-modal-skeleton');

        $skel.show();
        $iframe.css('visibility', 'hidden');

        var params = new URLSearchParams();
        params.set('action',       'hl_email_preview_render');
        params.set('_wpnonce',     config.previewNonce || '');
        params.set('template_id',  String(config.templateId || 0));
        params.set('enrollment_id', String(modalState.enrollmentId || 0));
        params.set('subject',      $('#hl-eb-subject').val() || '');
        params.set('blocks_json',  JSON.stringify(blocks));
        if (modalState.mode === 'dark') {
            params.set('dark', '1');
        }

        // A.2.2 — use srcdoc instead of Blob URL.
        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(function (r) { return r.text(); }).then(function (html) {
            $iframe.one('load', function () {
                $skel.hide();
                $iframe.css('visibility', 'visible');
            });
            $iframe.get(0).srcdoc = html;
        }).catch(function () {
            $skel.hide();
            $iframe.css('visibility', 'visible');
            $iframe.get(0).srcdoc = '<html><body style="font-family:sans-serif;padding:40px;color:#c00;">Preview failed to load. Check console.</body></html>';
        });
    }

    function setModalDevice(mode) {
        modalState.mode = mode;
        $('.hl-eb-modal-device').removeClass('active').filter('[data-mode="' + mode + '"]').addClass('active');
        var w = (mode === 'mobile') ? '375px' : '600px';
        $('#hl-eb-modal-iframe').css('width', w);
        refreshModalPreview();
    }

    // Focus trap: keep Tab / Shift+Tab cycling within the modal's focusable children.
    function trapModalTab(e) {
        if (!modalState.open || e.which !== 9) return;
        var $focusables = $('#hl-eb-modal').find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible:not([disabled])');
        if (!$focusables.length) return;
        var first = $focusables.get(0);
        var last  = $focusables.get($focusables.length - 1);
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    function modalSearchEnrollments(query) {
        if ((query || '').length < 2) {
            $('#hl-eb-modal-search-results').hide().empty();
            modalState.searchDropdownOpen = false;
            return;
        }
        $.post(config.ajaxUrl, {
            action: 'hl_email_preview_search',
            nonce: config.nonce,
            search: query
        }, function (res) {
            var $list = $('#hl-eb-modal-search-results').empty();
            if (!res.success || !res.data.length) {
                $list.append('<li class="hl-eb-modal-search-empty">No matches.</li>').show();
                modalState.searchDropdownOpen = true;
                return;
            }
            res.data.forEach(function (item) {
                $list.append(
                    '<li class="hl-eb-modal-search-result" role="option"' +
                    ' data-enrollment-id="' + item.enrollment_id + '">' +
                    escHtml(item.label) + '</li>'
                );
            });
            $list.show();
            modalState.searchDropdownOpen = true;
        });
    }

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

    // =========================================================================
    // Undo / Redo
    // =========================================================================

    function pushUndo() {
        undoStack.push(JSON.parse(JSON.stringify(blocks)));
        if (undoStack.length > MAX_UNDO) {
            undoStack.shift();
        }
        redoStack = [];
        updateUndoButtons();
    }

    function undo() {
        if (!undoStack.length) return;
        flushPendingTextSnapshot();
        redoStack.push(JSON.parse(JSON.stringify(blocks)));
        blocks = undoStack.pop();
        // A.2.4 — delay next autosave 5s after an undo/redo to give a redo window.
        autosaveSuppressUntil = Date.now() + 5000;
        renderAllBlocks();
        markDirty();
        updateUndoButtons();
    }

    function redo() {
        if (!redoStack.length) return;
        flushPendingTextSnapshot();
        undoStack.push(JSON.parse(JSON.stringify(blocks)));
        blocks = redoStack.pop();
        autosaveSuppressUntil = Date.now() + 5000;
        renderAllBlocks();
        markDirty();
        updateUndoButtons();
    }

    function updateUndoButtons() {
        $('#hl-eb-undo').prop('disabled', undoStack.length === 0);
        $('#hl-eb-redo').prop('disabled', redoStack.length === 0);
    }

    // Pending text snapshot flush — text blocks debounce snapshot on input/blur;
    // Ctrl+Z must flush the pending pre-mutation snapshot first so undo works correctly.
    // The per-text-block closure stores its pending snapshot in window._hlPendingTextSnap.
    function flushPendingTextSnapshot() {
        if (window._hlPendingTextSnap) {
            undoStack.push(window._hlPendingTextSnap);
            if (undoStack.length > MAX_UNDO) undoStack.shift();
            redoStack = [];
            window._hlPendingTextSnap = null;
        }
    }

    function destroyAllSortables() {
        sortableRegistry.forEach(function (inst) {
            try { inst.destroy(); } catch (e) {}
        });
        sortableRegistry = [];
        sortableInstance = null;
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
