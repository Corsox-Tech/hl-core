/**
 * HL Core Documentation — frontend-docs.js
 *
 * Handles: search filtering, TOC generation, sidebar accordion,
 * mobile sidebar toggle, and scroll spy.
 */
(function () {
    'use strict';

    // Wait for DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        initTOC();
        initSidebarAccordion();
        initMobileSidebar();
        initLandingSearch();
        initSidebarSearch();
    }

    // =========================================================================
    // TOC Generation + Scroll Spy
    // =========================================================================

    function initTOC() {
        var tocContainer = document.getElementById('hl-docs-toc');
        var articleBody = document.getElementById('hl-docs-article-body');
        if (!tocContainer || !articleBody) return;

        var headings = articleBody.querySelectorAll('h2, h3');
        if (headings.length < 2) return; // Don't show TOC for very short articles

        // Build TOC
        var title = document.createElement('div');
        title.className = 'hl-docs-toc-title';
        title.textContent = 'On this page';

        var list = document.createElement('ul');
        list.className = 'hl-docs-toc-list';

        var tocLinks = [];

        headings.forEach(function (heading, index) {
            // Add anchor ID if missing
            if (!heading.id) {
                heading.id = 'section-' + index;
            }

            var li = document.createElement('li');
            var a = document.createElement('a');
            a.href = '#' + heading.id;
            a.textContent = heading.textContent;
            a.setAttribute('data-heading-id', heading.id);

            if (heading.tagName === 'H3') {
                li.className = 'hl-docs-toc-h3';
            }

            li.appendChild(a);
            list.appendChild(li);
            tocLinks.push({ link: a, heading: heading });
        });

        tocContainer.appendChild(title);
        tocContainer.appendChild(list);

        // Scroll spy
        var ticking = false;
        window.addEventListener('scroll', function () {
            if (!ticking) {
                window.requestAnimationFrame(function () {
                    updateScrollSpy(tocLinks);
                    ticking = false;
                });
                ticking = true;
            }
        });
    }

    function updateScrollSpy(tocLinks) {
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        var offset = 120; // Account for fixed headers

        var activeIndex = -1;
        for (var i = tocLinks.length - 1; i >= 0; i--) {
            var rect = tocLinks[i].heading.getBoundingClientRect();
            if (rect.top <= offset) {
                activeIndex = i;
                break;
            }
        }

        tocLinks.forEach(function (item, index) {
            if (index === activeIndex) {
                item.link.classList.add('hl-docs-toc-active');
            } else {
                item.link.classList.remove('hl-docs-toc-active');
            }
        });
    }

    // =========================================================================
    // Sidebar Accordion
    // =========================================================================

    function initSidebarAccordion() {
        var toggles = document.querySelectorAll('.hl-docs-sidebar-category-toggle');
        toggles.forEach(function (toggle) {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                var parent = this.closest('.hl-docs-sidebar-category');
                if (parent) {
                    parent.classList.toggle('hl-docs-sidebar-category--open');
                }
            });
        });
    }

    // =========================================================================
    // Mobile Sidebar
    // =========================================================================

    function initMobileSidebar() {
        var sidebar = document.getElementById('hl-docs-sidebar');
        var toggleBtn = document.getElementById('hl-docs-sidebar-toggle');
        var closeBtn = document.getElementById('hl-docs-sidebar-close');

        if (!sidebar || !toggleBtn) return;

        var overlay = null;

        function openSidebar() {
            sidebar.classList.add('hl-docs-sidebar--open');
            overlay = document.createElement('div');
            overlay.className = 'hl-docs-sidebar-overlay';
            overlay.addEventListener('click', closeSidebar);
            document.body.appendChild(overlay);
        }

        function closeSidebar() {
            sidebar.classList.remove('hl-docs-sidebar--open');
            if (overlay && overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
                overlay = null;
            }
        }

        toggleBtn.addEventListener('click', function () {
            if (sidebar.classList.contains('hl-docs-sidebar--open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', closeSidebar);
        }
    }

    // =========================================================================
    // Landing Page Search
    // =========================================================================

    function initLandingSearch() {
        var searchInput = document.getElementById('hl-docs-search');
        var resultsContainer = document.getElementById('hl-docs-search-results');
        var resultsList = document.getElementById('hl-docs-search-results-list');
        var noResults = document.getElementById('hl-docs-search-no-results');
        var categoryGrid = document.getElementById('hl-docs-category-grid');

        if (!searchInput || !resultsContainer || !resultsList || !categoryGrid) return;

        var articlesData = [];
        var dataEl = document.getElementById('hl-docs-articles-data');
        if (dataEl) {
            try {
                articlesData = JSON.parse(dataEl.textContent);
            } catch (e) {
                // ignore parse errors
            }
        }

        if (!articlesData.length) return;

        var baseUrl = (window.hlDocsConfig && window.hlDocsConfig.baseUrl) || '';

        var debounceTimer = null;
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                var query = searchInput.value.trim().toLowerCase();
                if (query.length < 2) {
                    resultsContainer.style.display = 'none';
                    categoryGrid.style.display = '';
                    // Also show glossary card
                    var glossaryCard = document.querySelector('.hl-docs-glossary-card');
                    if (glossaryCard) glossaryCard.style.display = '';
                    return;
                }

                var matches = articlesData.filter(function (art) {
                    return art.title.toLowerCase().indexOf(query) !== -1
                        || (art.excerpt && art.excerpt.toLowerCase().indexOf(query) !== -1)
                        || (art.cat && art.cat.toLowerCase().indexOf(query) !== -1);
                });

                categoryGrid.style.display = 'none';
                var glossaryCard = document.querySelector('.hl-docs-glossary-card');
                if (glossaryCard) glossaryCard.style.display = 'none';
                resultsContainer.style.display = '';

                resultsList.innerHTML = '';
                if (matches.length === 0) {
                    noResults.style.display = '';
                } else {
                    noResults.style.display = 'none';
                    matches.forEach(function (art) {
                        var url = baseUrl ? baseUrl + (baseUrl.indexOf('?') !== -1 ? '&' : '?') + 'doc=' + encodeURIComponent(art.slug) : '?doc=' + encodeURIComponent(art.slug);
                        var item = document.createElement('a');
                        item.className = 'hl-docs-search-result-item';
                        item.href = url;
                        item.innerHTML =
                            '<h4>' + escapeHtml(art.title) + '</h4>' +
                            (art.cat ? '<p class="hl-docs-result-cat">' + escapeHtml(art.cat) + '</p>' : '') +
                            (art.excerpt ? '<p class="hl-docs-result-excerpt">' + escapeHtml(art.excerpt) + '</p>' : '');
                        resultsList.appendChild(item);
                    });
                }
            }, 200);
        });
    }

    // =========================================================================
    // Sidebar Search (article + glossary pages)
    // =========================================================================

    function initSidebarSearch() {
        var searchInput = document.getElementById('hl-docs-sidebar-search');
        if (!searchInput) return;

        searchInput.addEventListener('input', function () {
            var query = this.value.trim().toLowerCase();
            var categories = document.querySelectorAll('.hl-docs-sidebar-category');

            categories.forEach(function (cat) {
                var articles = cat.querySelectorAll('.hl-docs-sidebar-articles li');
                var anyVisible = false;

                articles.forEach(function (li) {
                    var text = li.textContent.toLowerCase();
                    if (!query || text.indexOf(query) !== -1) {
                        li.style.display = '';
                        anyVisible = true;
                    } else {
                        li.style.display = 'none';
                    }
                });

                if (query && anyVisible) {
                    cat.classList.add('hl-docs-sidebar-category--open');
                }

                // Hide entire category if no matches
                cat.style.display = (query && !anyVisible) ? 'none' : '';
            });
        });
    }

    // =========================================================================
    // Utility
    // =========================================================================

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
})();
