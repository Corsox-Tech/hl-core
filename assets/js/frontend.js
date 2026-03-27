/**
 * HL Core Frontend JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // === Cohort Tab Switching ===
        $('.hl-cohort-tab').on('click', function(e) {
            e.preventDefault();
            var targetId = $(this).data('target');
            var $wrap = $(this).closest('.hl-frontend-wrap');

            // Update active tab
            $wrap.find('.hl-cohort-tab').removeClass('active');
            $(this).addClass('active');

            // Show target content
            $wrap.find('.hl-cohort-content').removeClass('active');
            $wrap.find('#' + targetId).addClass('active');
        });

        // === Member Card Expand/Collapse ===
        $('.hl-member-card-header').on('click', function() {
            var $details = $(this).siblings('.hl-member-details');
            $details.toggleClass('open');
            $(this).toggleClass('expanded');
        });

        // === School Filter (Cohort Dashboard) ===
        $('.hl-school-filter').on('change', function() {
            var schoolId = $(this).val();
            var $table = $(this).closest('.hl-cohort-content, .hl-frontend-wrap').find('.hl-data-table');

            if (!schoolId) {
                $table.find('tbody tr').show();
            } else {
                $table.find('tbody tr').each(function() {
                    var rowSchool = $(this).data('school-id');
                    $(this).toggle(String(rowSchool) === String(schoolId));
                });
            }
        });

        // === Animate Progress Rings on Scroll ===
        var animated = false;
        function animateRings() {
            if (animated) return;
            $('.hl-progress-ring').each(function() {
                var $ring = $(this);
                if ($ring.is(':visible') && isInViewport($ring[0])) {
                    animated = true;
                    var $fill = $ring.find('.ring-fill');
                    var pct = parseFloat($fill.data('percent')) || 0;
                    var r = parseFloat($fill.attr('r'));
                    var circumference = 2 * Math.PI * r;
                    var offset = circumference - (pct / 100) * circumference;
                    $fill.css({
                        'stroke-dasharray': circumference,
                        'stroke-dashoffset': offset
                    });
                }
            });
        }

        function isInViewport(el) {
            var rect = el.getBoundingClientRect();
            return rect.top < window.innerHeight && rect.bottom > 0;
        }

        // Initialize progress rings immediately and on scroll
        animateRings();
        $(window).on('scroll', animateRings);

        // === Staff Table Search ===
        $(document).on('input', '.hl-search-input[data-table]', function() {
            var query  = $(this).val().toLowerCase();
            var tableId = $(this).data('table');
            var $table  = $('#' + tableId);

            $table.find('tbody tr').each(function() {
                var name = $(this).data('name') || '';
                $(this).toggle(name.indexOf(query) !== -1);
            });
        });

        // === Report Filters (school, team, name) ===
        $(document).on('input change', '.hl-report-filter, .hl-report-search', function() {
            var $container = $(this).closest('.hl-reports-container');
            var school = $container.find('[data-filter="school"]').val() || '';
            var team   = $container.find('[data-filter="team"]').val() || '';
            var name   = ($container.find('.hl-report-search').val() || '').toLowerCase();

            $container.find('.hl-report-row').each(function() {
                var $row = $(this);
                var match = true;

                if (school && $row.data('school') !== school) match = false;
                if (team && $row.data('team') !== team) match = false;
                if (name && ($row.data('name') || '').indexOf(name) === -1) match = false;

                $row.toggle(match);

                // Also hide the detail row if its parent is hidden
                var detailId = $row.next('.hl-detail-row').attr('id');
                if (!match && detailId) {
                    $('#' + detailId).hide();
                }
            });
        });

        // === My Cycle Tab Switching ===
        $(document).on('click', '.hl-cycle-tab', function(e) {
            e.preventDefault();
            var targetId = $(this).data('target');
            var $wrap = $(this).closest('.hl-frontend-wrap');

            $wrap.find('.hl-cycle-tab').removeClass('active');
            $(this).addClass('active');

            $wrap.find('.hl-cycle-content').removeClass('active');
            $wrap.find('#' + targetId).addClass('active');

            // Update URL for bookmarkable tabs.
            var tab = targetId.replace('hl-tab-', '');
            if (window.history && window.history.replaceState) {
                var url = new URL(window.location);
                url.searchParams.set('tab', tab);
                window.history.replaceState({}, '', url);
            }
        });

        // === Cycle Workspace Tab Switching ===
        $(document).on('click', '.hl-cw-tab', function(e) {
            e.preventDefault();
            var targetId = $(this).data('target');
            var $wrap = $(this).closest('.hl-cw-v2');

            $wrap.find('.hl-cw-tab').removeClass('active');
            $(this).addClass('active');

            $wrap.find('.hl-cw-panel').removeClass('active');
            $wrap.find('#' + targetId).addClass('active');
        });

        // === Detail Row Toggle (Reports Tab) ===
        $(document).on('click', '.hl-detail-toggle, .hl-cw-view-btn', function(e) {
            e.preventDefault();
            var targetId = $(this).data('target');
            var $detail = $('#' + targetId);
            $detail.toggle();
            $(this).text($detail.is(':visible') ? 'Hide' : 'View');
        });

    });

})(jQuery);

/**
 * Program Page v2 — toggle expandable panels.
 */
function hlTogglePanel(panelId, btn) {
    var panel = document.getElementById(panelId);
    if (!panel) return;

    var isOpen = panel.classList.contains('show');

    // Close all panels and deactivate all toggles.
    document.querySelectorAll('.hl-pp-panel').forEach(function(p) {
        p.classList.remove('show');
    });
    document.querySelectorAll('.hl-pp-toggle-btn').forEach(function(b) {
        b.classList.remove('active');
    });

    // If it wasn't open, open it.
    if (!isOpen) {
        panel.classList.add('show');
        btn.classList.add('active');
    }
}
