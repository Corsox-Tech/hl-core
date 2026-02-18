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

        // === Center Filter (Cohort Dashboard) ===
        $('.hl-center-filter').on('change', function() {
            var centerId = $(this).val();
            var $table = $(this).closest('.hl-cohort-content, .hl-frontend-wrap').find('.hl-data-table');

            if (!centerId) {
                $table.find('tbody tr').show();
            } else {
                $table.find('tbody tr').each(function() {
                    var rowCenter = $(this).data('center-id');
                    $(this).toggle(String(rowCenter) === String(centerId));
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

        // === Report Filters (center, team, name) ===
        $(document).on('input change', '.hl-report-filter, .hl-report-search', function() {
            var $container = $(this).closest('.hl-reports-container');
            var center = $container.find('[data-filter="center"]').val() || '';
            var team   = $container.find('[data-filter="team"]').val() || '';
            var name   = ($container.find('.hl-report-search').val() || '').toLowerCase();

            $container.find('.hl-report-row').each(function() {
                var $row = $(this);
                var match = true;

                if (center && $row.data('center') !== center) match = false;
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

        // === Detail Row Toggle (Reports Tab) ===
        $(document).on('click', '.hl-detail-toggle', function(e) {
            e.preventDefault();
            var targetId = $(this).data('target');
            var $detail = $('#' + targetId);
            $detail.toggle();
            $(this).text($detail.is(':visible') ? 'Hide' : 'View');
        });

    });

})(jQuery);
