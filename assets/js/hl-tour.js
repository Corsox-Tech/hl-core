/**
 * HL Guided Tours — Frontend Controller
 *
 * Wraps Driver.js to provide multi-page tours, auto-triggering,
 * localStorage state management, and topbar dropdown integration.
 *
 * Expects window.hlTourData (localized by PHP) with:
 *   auto_trigger, available, active_tour, styles, ajax_url, nonce, i18n
 */
(function() {
    'use strict';

    var DATA = window.hlTourData;
    if (!DATA) return;

    var LS_KEY = 'hl_tour_active';
    var i18n   = DATA.i18n || {};

    // ─── Helpers ───

    function lsGet() {
        try {
            var raw = localStorage.getItem(LS_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function lsSet(obj) {
        try {
            localStorage.setItem(LS_KEY, JSON.stringify(obj));
        } catch (e) {}
    }

    function lsClear() {
        try {
            localStorage.removeItem(LS_KEY);
        } catch (e) {}
    }

    function currentPath() {
        return window.location.pathname.replace(/\/+$/, '') + '/';
    }

    function normalizePath(url) {
        if (!url) return '';
        try {
            var a = document.createElement('a');
            a.href = url;
            return a.pathname.replace(/\/+$/, '') + '/';
        } catch (e) {
            return url.replace(/\/+$/, '') + '/';
        }
    }

    function ajaxPost(action, data, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', DATA.ajax_url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && callback) {
                callback(xhr.status === 200);
            }
        };
        var params = 'action=' + encodeURIComponent(action) + '&_nonce=' + encodeURIComponent(DATA.nonce);
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                params += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(data[key]);
            }
        }
        xhr.send(params);
    }

    // ─── Dropdown Population ───

    function populateDropdown() {
        var list  = document.getElementById('hl-tour-dropdown-list');
        var empty = document.getElementById('hl-tour-dropdown-empty');
        if (!list) return;

        var available = DATA.available || [];
        list.innerHTML = '';

        if (available.length === 0) {
            if (empty) empty.hidden = false;
            return;
        }

        if (empty) empty.hidden = true;

        for (var i = 0; i < available.length; i++) {
            var tour = available[i];
            var li   = document.createElement('li');
            var btn  = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('data-tour-slug', tour.slug);
            btn.setAttribute('data-tour-id', tour.tour_id);

            var titleSpan = document.createElement('span');
            titleSpan.textContent = tour.title;
            btn.appendChild(titleSpan);

            var badge = document.createElement('span');
            badge.className = 'hl-tour-dropdown__badge';
            badge.textContent = tour.trigger_type.replace('_', ' ');
            btn.appendChild(badge);

            btn.addEventListener('click', (function(slug) {
                return function() {
                    // Close dropdown.
                    var dd = document.getElementById('hl-tour-dropdown');
                    if (dd) dd.hidden = true;
                    var trigger = document.getElementById('hl-tour-trigger');
                    if (trigger) trigger.setAttribute('aria-expanded', 'false');
                    window.hlTourStart(slug);
                };
            })(tour.slug));

            li.appendChild(btn);
            list.appendChild(li);
        }
    }

    // ─── Tour Engine ───

    function findTourData(slug) {
        // Check active_tour first (PHP includes full steps for cross-page resume).
        if (DATA.active_tour && DATA.active_tour.slug === slug) {
            return DATA.active_tour;
        }
        // Check auto_trigger.
        if (DATA.auto_trigger && DATA.auto_trigger.slug === slug) {
            return DATA.auto_trigger;
        }
        return null;
    }

    function fetchAndStartTour(slug, tourId, startStepIndex) {
        // Fetch full tour data via AJAX, then start.
        var xhr = new XMLHttpRequest();
        xhr.open('POST', DATA.ajax_url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (xhr.status !== 200) {
                console.error('[HL Tour] AJAX failed:', xhr.status, xhr.statusText);
                return;
            }
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.success && resp.data && resp.data.tour) {
                    var tour = resp.data.tour;
                    tour.steps = resp.data.steps || [];
                    initTour(tour, startStepIndex || 0);
                } else {
                    console.error('[HL Tour] AJAX error:', resp.data && resp.data.message || 'Unknown error');
                }
            } catch (e) {
                console.error('[HL Tour] Parse error:', e.message);
            }
        };
        var params = 'action=hl_tour_get_steps&_nonce=' + encodeURIComponent(DATA.nonce) +
                     '&tour_id=' + encodeURIComponent(tourId);
        xhr.send(params);
    }

    function buildDriverSteps(tour) {
        var steps = tour.steps || [];
        var pagePath = currentPath();
        var startPath = normalizePath(tour.start_page_url);
        var visible = [];

        for (var i = 0; i < steps.length; i++) {
            var step = steps[i];
            var stepPath = step.page_url ? normalizePath(step.page_url) : startPath;

            // Only include steps for the current page.
            if (stepPath !== pagePath) {
                // Mark as page-transition step (we'll handle navigation).
                visible.push({
                    _hlStep: step,
                    _hlIndex: i,
                    _hlOffPage: true,
                    _hlPageUrl: step.page_url || tour.start_page_url
                });
                continue;
            }

            // Check if element exists in DOM.
            if (step.target_selector && !document.querySelector(step.target_selector)) {
                continue; // Skip missing elements.
            }

            visible.push({
                _hlStep: step,
                _hlIndex: i,
                _hlOffPage: false
            });
        }

        return visible;
    }

    var _destroying = false; // Guard against onDestroyStarted re-entry.

    function initTour(tour, startFromIndex) {
        _destroying = false;

        // Mobile check.
        if (parseInt(tour.hide_on_mobile, 10) === 1 && window.innerWidth < 640) {
            return;
        }

        var allSteps = buildDriverSteps(tour);
        if (allSteps.length === 0) {
            // All steps skipped — mark as seen silently.
            ajaxPost('hl_tour_mark_seen', { tour_id: tour.tour_id });
            lsClear();
            return;
        }

        var styles = DATA.styles || {};
        var pagePath = currentPath();

        // Find the starting index within the visible steps.
        var driverStartIndex = 0;
        if (startFromIndex > 0) {
            for (var si = 0; si < allSteps.length; si++) {
                if (allSteps[si]._hlIndex >= startFromIndex && !allSteps[si]._hlOffPage) {
                    driverStartIndex = si;
                    break;
                }
            }
        }

        // Count visible on-page steps for progress.
        var onPageSteps = [];
        for (var p = 0; p < allSteps.length; p++) {
            if (!allSteps[p]._hlOffPage) {
                onPageSteps.push(allSteps[p]);
            }
        }

        // Append the auto-generated final step.
        var finalStep = {
            _hlStep: {
                title: i18n.replay_title || 'Replay This Tour',
                description: i18n.replay_desc || 'You can revisit this tour anytime by clicking here.',
                target_selector: '#hl-tour-trigger',
                position: 'bottom',
                step_type: 'informational'
            },
            _hlIndex: 9999,
            _hlOffPage: false,
            _hlFinal: true
        };

        // Build Driver.js step configs for on-page steps.
        var driverSteps = [];
        var totalVisible = onPageSteps.length + 1; // +1 for final step
        var visibleCounter = 0;

        for (var d = driverStartIndex; d < allSteps.length; d++) {
            var item = allSteps[d];
            if (item._hlOffPage) {
                // This step is on a different page — trigger navigation.
                driverSteps.push({
                    element: 'body', // placeholder, won't actually render
                    _hlNavigate: true,
                    _hlPageUrl: item._hlPageUrl,
                    _hlNextIndex: item._hlIndex,
                    popover: { title: '', description: '' }
                });
                break; // Stop adding steps after a page transition.
            }

            visibleCounter++;
            var s = item._hlStep;
            var isInteractive = s.step_type === 'interactive';

            driverSteps.push({
                element: s.target_selector || undefined,
                popover: {
                    title: s.title,
                    description: s.description,
                    side: s.position === 'auto' ? undefined : s.position,
                    showButtons: isInteractive ? ['close'] : ['next', 'previous', 'close'],
                    progressText: visibleCounter + ' ' + (i18n.of || 'of') + ' ' + totalVisible
                },
                _hlInteractive: isInteractive,
                _hlTargetSelector: s.target_selector,
                _hlProgressCurrent: visibleCounter,
                _hlProgressTotal: totalVisible,
                disableActiveInteraction: isInteractive ? false : true
            });
        }

        // If we have no navigation break, append the final step.
        var hasNavBreak = driverSteps.length > 0 && driverSteps[driverSteps.length - 1]._hlNavigate;
        if (!hasNavBreak) {
            var finalEl = document.querySelector('#hl-tour-trigger');
            if (finalEl) {
                driverSteps.push({
                    element: '#hl-tour-trigger',
                    popover: {
                        title: finalStep._hlStep.title,
                        description: finalStep._hlStep.description,
                        side: 'bottom',
                        showButtons: ['next', 'close'],
                        progressText: totalVisible + ' ' + (i18n.of || 'of') + ' ' + totalVisible
                    },
                    _hlFinal: true,
                    _hlProgressCurrent: totalVisible,
                    _hlProgressTotal: totalVisible,
                    disableActiveInteraction: true
                });
            }
        }

        if (driverSteps.length === 0) {
            ajaxPost('hl_tour_mark_seen', { tour_id: tour.tour_id });
            lsClear();
            return;
        }

        // Save active state.
        lsSet({
            tour_slug: tour.slug,
            tour_id: tour.tour_id,
            step_index: startFromIndex || 0,
            start_page_url: tour.start_page_url,
            status: 'navigating'
        });

        // Create Driver.js instance.
        var driverObj = window.driver.js.driver({
            showProgress: false,
            animate: true,
            overlayOpacity: 0.6,
            stagePadding: 10,
            stageRadius: 8,
            allowClose: true,
            overlayClickBehavior: 'close',
            nextBtnText: i18n.next || 'Next',
            prevBtnText: i18n.prev || 'Back',
            doneBtnText: i18n.done || 'Done',
            popoverClass: 'hl-tour-popover',
            steps: driverSteps,

            onPopoverRender: function(popover, options) {
                // Apply global styles.
                var wrapper = popover.wrapper;
                if (wrapper) {
                    wrapper.style.backgroundColor = styles.tooltip_bg || '#ffffff';
                }
                var title = popover.title;
                if (title) {
                    title.style.color = styles.title_color || '#1A2B47';
                    title.style.fontSize = (styles.title_font_size || 16) + 'px';
                }
                var desc = popover.description;
                if (desc) {
                    desc.style.color = styles.desc_color || '#6B7280';
                    desc.style.fontSize = (styles.desc_font_size || 14) + 'px';
                }

                // Style buttons.
                var buttons = wrapper ? wrapper.querySelectorAll('.driver-popover-next-btn, .driver-popover-prev-btn') : [];
                for (var bi = 0; bi < buttons.length; bi++) {
                    buttons[bi].style.backgroundColor = styles.btn_bg || '#6366f1';
                    buttons[bi].style.color = styles.btn_text_color || '#ffffff';
                    buttons[bi].style.borderColor = styles.btn_bg || '#6366f1';
                }

                // Inject progress bar into footer.
                var footer = popover.footer;
                if (footer) {
                    var existing = footer.querySelector('.hl-tour-progress-bar');
                    if (!existing) {
                        var activeStep = options.state.activeStep;
                        var current = activeStep ? activeStep._hlProgressCurrent : 0;
                        var total   = activeStep ? activeStep._hlProgressTotal   : 0;
                        if (current > 0 && total > 0) {
                            var pct = Math.round((current / total) * 100);
                            var bar = document.createElement('div');
                            bar.className = 'hl-tour-progress-bar';
                            bar.style.cssText = 'width:100%;height:3px;background:#e5e7eb;border-radius:2px;margin-top:8px;overflow:hidden;';
                            var fill = document.createElement('div');
                            fill.style.cssText = 'height:100%;border-radius:2px;transition:width 0.3s ease;width:' + pct + '%;background:' + (styles.progress_color || '#6366f1') + ';';
                            bar.appendChild(fill);
                            footer.appendChild(bar);
                        }
                    }
                }
            },

            onHighlightStarted: function(element, step, options) {
                // Offset scroll for the fixed 48px topbar — only when element is near/behind it.
                if (!element) return;
                setTimeout(function() {
                    var rect = element.getBoundingClientRect();
                    if (rect.top < 80) {
                        window.scrollBy({ top: -80, behavior: 'smooth' });
                    }
                }, 300);
            },

            onNextClick: function(element, step, options) {
                var activeIndex = options.state.activeIndex;
                var steps = options.config.steps;

                // Check for page navigation.
                var nextIndex = (activeIndex !== undefined ? activeIndex : -1) + 1;
                if (nextIndex < steps.length && steps[nextIndex]._hlNavigate) {
                    var navStep = steps[nextIndex];
                    lsSet({
                        tour_slug: tour.slug,
                        tour_id: tour.tour_id,
                        step_index: navStep._hlNextIndex,
                        start_page_url: tour.start_page_url,
                        status: 'navigating'
                    });
                    window.location.href = navStep._hlPageUrl + (navStep._hlPageUrl.indexOf('?') >= 0 ? '&' : '?') + 'hl_active_tour=' + encodeURIComponent(tour.slug);
                    return;
                }

                // Check if current step is final.
                if (steps[activeIndex] && steps[activeIndex]._hlFinal) {
                    markSeenAndCleanup(tour.tour_id);
                    driverObj.destroy();
                    return;
                }

                driverObj.moveNext();
            },

            onCloseClick: function(element, step, options) {
                var activeStep = options.state.activeStep;
                // If this is the final step, mark as seen.
                if (activeStep && activeStep._hlFinal) {
                    markSeenAndCleanup(tour.tour_id);
                    driverObj.destroy();
                    return;
                }

                // Exit mid-tour: redirect to start page and show final step.
                handleExitMidTour(tour, driverObj);
            },

            onDestroyStarted: function(element, step, options) {
                if (_destroying) return; // Prevent infinite re-entry.
                _destroying = true;

                var activeStep = options.state.activeStep;
                if (activeStep && activeStep._hlFinal) {
                    markSeenAndCleanup(tour.tour_id);
                    driverObj.destroy();
                    return;
                }

                // Overlay click or Escape — treat as exit.
                handleExitMidTour(tour, driverObj);
            }
        });

        // Set up interactive step listeners after driver starts.
        driverObj.drive(0);
        setupInteractiveListeners(driverObj, driverSteps, tour);
    }

    function setupInteractiveListeners(driverObj, driverSteps, tour) {
        // For each interactive step, add a click listener on the target element.
        for (var i = 0; i < driverSteps.length; i++) {
            if (driverSteps[i]._hlInteractive && driverSteps[i]._hlTargetSelector) {
                (function(stepIndex, selector) {
                    var el = document.querySelector(selector);
                    if (el) {
                        // Check if the next step requires page navigation.
                        var nextStep = driverSteps[stepIndex + 1];
                        var nextNeedsNav = nextStep && nextStep._hlNavigate;

                        var handler = function(e) {
                            el.removeEventListener('click', handler);
                            var currentIndex = driverObj.getActiveIndex();
                            if (currentIndex !== stepIndex) return;

                            if (nextNeedsNav) {
                                // Next step is on a different page. Prevent the natural
                                // link click so we can save state and navigate with the
                                // ?hl_active_tour= param for PHP to include tour data.
                                e.preventDefault();
                                e.stopPropagation();
                                lsSet({
                                    tour_slug: tour.slug,
                                    tour_id: tour.tour_id,
                                    step_index: nextStep._hlNextIndex,
                                    start_page_url: tour.start_page_url,
                                    status: 'navigating'
                                });
                                // Navigate to the target page (use the element's href if
                                // it's a link, otherwise use the step's page URL).
                                var href = el.href || el.closest('a[href]')?.href || nextStep._hlPageUrl;
                                var separator = href.indexOf('?') >= 0 ? '&' : '?';
                                window.location.href = href + separator + 'hl_active_tour=' + encodeURIComponent(tour.slug);
                            } else {
                                driverObj.moveNext();
                            }
                        };
                        el.addEventListener('click', handler, true); // useCapture to fire before navigation
                    }
                })(i, driverSteps[i]._hlTargetSelector);
            }
        }
    }

    function handleExitMidTour(tour, driverObj) {
        var startPath = normalizePath(tour.start_page_url);
        var onStartPage = (currentPath() === startPath);

        if (onStartPage) {
            // Already on start page — destroy current and show final step.
            driverObj.destroy();
            showFinalStepOnly(tour);
        } else {
            // Redirect to start page.
            lsSet({
                tour_slug: tour.slug,
                tour_id: tour.tour_id,
                step_index: 0,
                start_page_url: tour.start_page_url,
                status: 'interrupted'
            });
            driverObj.destroy();
            window.location.href = tour.start_page_url + (tour.start_page_url.indexOf('?') >= 0 ? '&' : '?') + 'hl_active_tour=' + encodeURIComponent(tour.slug);
        }
    }

    function showFinalStepOnly(tour) {
        var triggerEl = document.querySelector('#hl-tour-trigger');
        if (!triggerEl) {
            markSeenAndCleanup(tour.tour_id);
            return;
        }

        var styles = DATA.styles || {};
        var _finalDestroying = false;
        var finalDriver = window.driver.js.driver({
            showProgress: false,
            animate: true,
            overlayOpacity: 0.6,
            stagePadding: 10,
            stageRadius: 8,
            allowClose: true,
            popoverClass: 'hl-tour-popover',
            doneBtnText: i18n.done || 'Done',
            steps: [{
                element: '#hl-tour-trigger',
                popover: {
                    title: i18n.replay_title || 'Replay This Tour',
                    description: i18n.replay_desc || 'You can revisit this tour anytime by clicking here.',
                    side: 'bottom',
                    showButtons: ['next', 'close']
                },
                disableActiveInteraction: true
            }],
            onPopoverRender: function(popover) {
                var wrapper = popover.wrapper;
                if (wrapper) wrapper.style.backgroundColor = styles.tooltip_bg || '#ffffff';
                var title = popover.title;
                if (title) {
                    title.style.color = styles.title_color || '#1A2B47';
                    title.style.fontSize = (styles.title_font_size || 16) + 'px';
                }
                var desc = popover.description;
                if (desc) {
                    desc.style.color = styles.desc_color || '#6B7280';
                    desc.style.fontSize = (styles.desc_font_size || 14) + 'px';
                }
            },
            onNextClick: function() {
                markSeenAndCleanup(tour.tour_id);
                finalDriver.destroy();
            },
            onCloseClick: function() {
                markSeenAndCleanup(tour.tour_id);
                finalDriver.destroy();
            },
            onDestroyStarted: function() {
                if (_finalDestroying) return;
                _finalDestroying = true;
                markSeenAndCleanup(tour.tour_id);
                finalDriver.destroy();
            }
        });

        finalDriver.drive(0);
    }

    function markSeenAndCleanup(tourId) {
        ajaxPost('hl_tour_mark_seen', { tour_id: tourId });
        lsClear();
    }

    // ─── Public API ───

    window.hlTourStart = function(slug) {
        var tour = findTourData(slug);
        if (tour) {
            initTour(tour, 0);
            return;
        }

        // Tour data not in page context — find it from available list and fetch via AJAX.
        var available = DATA.available || [];
        for (var i = 0; i < available.length; i++) {
            if (available[i].slug === slug) {
                fetchAndStartTour(slug, available[i].tour_id, 0);
                return;
            }
        }
    };

    // ─── Init on DOM Ready ───

    function init() {
        populateDropdown();

        // Check localStorage for interrupted/navigating tour.
        var saved = lsGet();
        if (saved && saved.tour_slug) {
            var tourData = findTourData(saved.tour_slug);

            if (!tourData) {
                // Tour was deleted/archived since — clear stale state.
                lsClear();
            } else if (saved.status === 'navigating') {
                // Active page transition — resume from saved step.
                initTour(tourData, saved.step_index || 0);
                return;
            } else if (saved.status === 'interrupted') {
                // Interrupted (exit or browser close) — show final step only.
                var startPath = normalizePath(saved.start_page_url);
                if (currentPath() === startPath) {
                    showFinalStepOnly(tourData);
                } else {
                    // Redirect to start page.
                    lsSet({
                        tour_slug: saved.tour_slug,
                        tour_id: saved.tour_id,
                        step_index: 0,
                        start_page_url: saved.start_page_url,
                        status: 'interrupted'
                    });
                    window.location.href = saved.start_page_url + (saved.start_page_url.indexOf('?') >= 0 ? '&' : '?') + 'hl_active_tour=' + encodeURIComponent(saved.tour_slug);
                }
                return;
            }
        }

        // Auto-trigger check.
        if (DATA.auto_trigger && DATA.auto_trigger.steps && DATA.auto_trigger.steps.length > 0) {
            initTour(DATA.auto_trigger, 0);
        }
    }

    // Run init when DOM is ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
