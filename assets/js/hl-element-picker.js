/**
 * HL Element Picker — injected into iframe when ?hl_picker=1.
 *
 * Handles hover highlighting, click selection, CSS selector generation,
 * and postMessage communication back to the admin picker modal.
 *
 * @package HL_Core
 */
(function() {
    'use strict';

    // ─── Polyfills ───

    // CSS.escape polyfill (CSSWG spec) for older browsers.
    if (!window.CSS || !CSS.escape) {
        window.CSS = window.CSS || {};
        CSS.escape = function(value) {
            var str = String(value);
            var length = str.length;
            var result = '';
            for (var i = 0; i < length; i++) {
                var ch = str.charCodeAt(i);
                if (ch === 0) { result += '\uFFFD'; continue; }
                if ((ch >= 0x0001 && ch <= 0x001F) || ch === 0x007F ||
                    (i === 0 && ch >= 0x0030 && ch <= 0x0039) ||
                    (i === 1 && ch >= 0x0030 && ch <= 0x0039 && str.charCodeAt(0) === 0x002D)) {
                    result += '\\' + ch.toString(16) + ' ';
                    continue;
                }
                if (i === 0 && length === 1 && ch === 0x002D) { result += '\\' + str.charAt(i); continue; }
                if (ch >= 0x0080 || ch === 0x002D || ch === 0x005F ||
                    (ch >= 0x0030 && ch <= 0x0039) || (ch >= 0x0041 && ch <= 0x005A) ||
                    (ch >= 0x0061 && ch <= 0x007A)) {
                    result += str.charAt(i);
                    continue;
                }
                result += '\\' + str.charAt(i);
            }
            return result;
        };
    }

    // Element.closest polyfill for older browsers.
    if (!Element.prototype.closest) {
        Element.prototype.closest = function(sel) {
            var el = this;
            while (el && el.nodeType === 1) {
                if (el.matches(sel)) return el;
                el = el.parentElement;
            }
            return null;
        };
    }

    // ─── Constants ───

    var HIGHLIGHT_COLOR  = '#4F46E5';
    var HIGHLIGHT_SHADOW = 'rgba(79, 70, 229, 0.25)';
    var TOOLBAR_ID       = 'hl-picker-toolbar';
    var IGNORED_TAGS     = ['SCRIPT', 'STYLE', 'META', 'LINK', 'HEAD', 'HTML', 'NOSCRIPT'];
    var IGNORED_IDS      = [TOOLBAR_ID];

    // ─── State ───

    var hoveredEl   = null;
    var selectedEl  = null;
    var isLocked    = false;

    // ─── Toolbar ───

    var toolbar = document.createElement('div');
    toolbar.id  = TOOLBAR_ID;
    toolbar.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:999999;' +
        'background:#1a1a2e;color:#fff;padding:12px 20px;display:flex;align-items:center;' +
        'justify-content:space-between;font-family:Inter,system-ui,sans-serif;font-size:14px;' +
        'gap:12px;box-shadow:0 -2px 12px rgba(0,0,0,0.3);';

    var selectorDisplay = document.createElement('code');
    selectorDisplay.style.cssText = 'flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' +
        'background:rgba(255,255,255,0.1);padding:6px 12px;border-radius:4px;font-size:13px;';
    selectorDisplay.textContent = 'Hover over an element to pick it...';

    var btnGroup = document.createElement('div');
    btnGroup.style.cssText = 'display:flex;gap:8px;flex-shrink:0;';

    var cancelBtn = document.createElement('button');
    cancelBtn.textContent  = 'Cancel';
    cancelBtn.style.cssText = 'padding:6px 16px;border:1px solid rgba(255,255,255,0.3);' +
        'border-radius:4px;background:transparent;color:#fff;cursor:pointer;font-size:13px;';

    var useBtn = document.createElement('button');
    useBtn.textContent  = 'Use This Element';
    useBtn.style.cssText = 'padding:6px 16px;border:none;border-radius:4px;' +
        'background:#4F46E5;color:#fff;cursor:pointer;font-size:13px;font-weight:600;';
    useBtn.style.display = 'none';

    btnGroup.appendChild(cancelBtn);
    btnGroup.appendChild(useBtn);
    toolbar.appendChild(selectorDisplay);
    toolbar.appendChild(btnGroup);
    document.body.appendChild(toolbar);

    // ─── Ignore Helpers ───

    function isIgnored(el) {
        if (!el || el.nodeType !== 1) return true;
        if (IGNORED_TAGS.indexOf(el.tagName) !== -1) return true;
        // Walk up to check ignored ancestors.
        var node = el;
        while (node && node !== document.body) {
            if (node.id && IGNORED_IDS.indexOf(node.id) !== -1) return true;
            node = node.parentElement;
        }
        return false;
    }

    // ─── Highlight ───

    var prevOutline    = '';
    var prevBoxShadow  = '';

    function highlight(el) {
        if (!el || isIgnored(el)) return;
        if (hoveredEl === el) return; // Already highlighted.
        if (hoveredEl) {
            clearHighlight(hoveredEl);
        }
        hoveredEl = el;
        prevOutline   = el.style.outline;
        prevBoxShadow = el.style.boxShadow;
        el.style.outline   = '2px solid ' + HIGHLIGHT_COLOR;
        el.style.boxShadow = '0 0 0 4px ' + HIGHLIGHT_SHADOW;
    }

    function clearHighlight(el) {
        if (!el) return;
        el.style.outline   = prevOutline;
        el.style.boxShadow = prevBoxShadow;
        prevOutline   = '';
        prevBoxShadow = '';
    }

    // ─── Selector Generation ───

    /**
     * Generate a stable CSS selector for the given element.
     * Priority: id > unique hl-* class > ancestor hl-* class + path > full path.
     */
    function generateSelector(el) {
        if (!el || el === document.body || el === document.documentElement) {
            return { selector: 'body', stable: true };
        }

        // 1. Element has an id.
        if (el.id && el.id !== TOOLBAR_ID) {
            return { selector: '#' + CSS.escape(el.id), stable: true };
        }

        // 2. Element has a unique hl-* class.
        var hlClass = getUniqueHlClass(el);
        if (hlClass) {
            return { selector: '.' + hlClass, stable: true };
        }

        // 3. Closest ancestor with hl-* class + child path.
        var ancestorResult = getAncestorPath(el);
        if (ancestorResult) {
            return { selector: ancestorResult, stable: true };
        }

        // 4. Full DOM path fallback (less stable).
        return { selector: getFullPath(el), stable: false };
    }

    /**
     * Find a class starting with 'hl-' on this element that is unique in the document.
     */
    function getUniqueHlClass(el) {
        var classes = el.classList;
        for (var i = 0; i < classes.length; i++) {
            if (classes[i].indexOf('hl-') === 0) {
                try {
                    var matches = document.querySelectorAll('.' + CSS.escape(classes[i]));
                    if (matches.length === 1) {
                        return classes[i];
                    }
                } catch (e) {
                    // Invalid selector, skip.
                }
            }
        }
        return null;
    }

    /**
     * Walk up to find an ancestor with an hl-* class, then build a child path down.
     */
    function getAncestorPath(el) {
        var current  = el;
        var pathParts = [];

        while (current && current !== document.body) {
            var parent = current.parentElement;
            if (!parent) break;

            pathParts.unshift(getChildSegment(current));

            // Check if parent has a unique hl-* class.
            var parentHlClass = getUniqueHlClass(parent);
            if (parentHlClass) {
                return '.' + parentHlClass + ' > ' + pathParts.join(' > ');
            }

            // Also check parent's id.
            if (parent.id && parent.id !== TOOLBAR_ID) {
                return '#' + CSS.escape(parent.id) + ' > ' + pathParts.join(' > ');
            }

            current = parent;

            // Limit depth to prevent overly long selectors.
            if (pathParts.length > 5) break;
        }

        return null;
    }

    /**
     * Build a segment for a child element (tag + nth-child if needed).
     */
    function getChildSegment(el) {
        var tag = el.tagName.toLowerCase();

        // If element has a non-hl class that could help identify it, use it.
        var hlClass = null;
        for (var i = 0; i < el.classList.length; i++) {
            if (el.classList[i].indexOf('hl-') === 0) {
                hlClass = el.classList[i];
                break;
            }
        }
        if (hlClass) {
            return '.' + hlClass;
        }

        // Use nth-child for disambiguation.
        var parent = el.parentElement;
        if (parent) {
            var siblings = parent.children;
            var sameTagCount = 0;
            var index = 0;
            for (var j = 0; j < siblings.length; j++) {
                if (siblings[j].tagName === el.tagName) {
                    sameTagCount++;
                    if (siblings[j] === el) {
                        index = sameTagCount;
                    }
                }
            }
            if (sameTagCount > 1) {
                return tag + ':nth-of-type(' + index + ')';
            }
        }

        return tag;
    }

    /**
     * Full DOM path from body — least stable, last resort.
     */
    function getFullPath(el) {
        var parts = [];
        var current = el;
        while (current && current !== document.body && current !== document.documentElement) {
            parts.unshift(getChildSegment(current));
            current = current.parentElement;
            if (parts.length > 8) break;
        }
        return parts.join(' > ');
    }

    // ─── Event Handlers ───

    function onMouseMove(e) {
        if (isLocked) return;
        var target = document.elementFromPoint(e.clientX, e.clientY);
        if (!target || isIgnored(target)) return;
        highlight(target);
        var result = generateSelector(target);
        selectorDisplay.textContent = result.selector;
        if (!result.stable) {
            selectorDisplay.textContent += '  (unstable — consider adding an id or hl-* class)';
        }
    }

    function onClick(e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        if (isLocked) return;

        var target = document.elementFromPoint(e.clientX, e.clientY);
        if (!target || isIgnored(target)) return;

        // Lock selection.
        isLocked   = true;
        selectedEl = target;
        highlight(target);

        var result = generateSelector(target);
        selectorDisplay.textContent = 'Selected: ' + result.selector;
        if (!result.stable) {
            selectorDisplay.textContent += '  (unstable)';
        }
        useBtn.style.display = 'inline-block';
    }

    // Prevent ALL default link/form interactions in the iframe.
    function blockNavigation(e) {
        // Allow clicks on the toolbar buttons.
        if (e.target.closest && e.target.closest('#' + TOOLBAR_ID)) return;
        e.preventDefault();
        e.stopPropagation();
    }

    // ─── PostMessage Communication ───

    function sendToParent(type, data) {
        var msg = { type: type };
        if (data) {
            for (var key in data) {
                if (data.hasOwnProperty(key)) {
                    msg[key] = data[key];
                }
            }
        }
        window.parent.postMessage(msg, window.location.origin);
    }

    // ─── Button Handlers ───

    cancelBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        sendToParent('hl-picker-cancel');
    });

    useBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (!selectedEl) return;
        var result = generateSelector(selectedEl);
        sendToParent('hl-picker-select', { selector: result.selector });
    });

    // ─── Unlock (reset selection without closing) ───

    function unlock() {
        if (selectedEl) {
            clearHighlight(selectedEl);
        }
        isLocked   = false;
        selectedEl = null;
        hoveredEl  = null;
        useBtn.style.display = 'none';
        selectorDisplay.textContent = 'Hover over an element to pick it...';
    }

    // ESC key to unlock or cancel.
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (isLocked) {
                unlock();
            } else {
                sendToParent('hl-picker-cancel');
            }
        }
    });

    // ─── Initialize ───

    // Block all link clicks and form submissions inside the iframe.
    document.addEventListener('click', blockNavigation, true);
    document.addEventListener('submit', function(e) { e.preventDefault(); }, true);

    // Picker interactions.
    document.addEventListener('mousemove', onMouseMove, false);
    document.addEventListener('click', onClick, false);

    // Prevent right-click context menu.
    document.addEventListener('contextmenu', function(e) { e.preventDefault(); }, true);

})();
