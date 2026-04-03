/**
 * HL Tour Admin — Step management, drag-reorder, element picker, color pickers.
 *
 * @package HL_Core
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // ─── WP Iris Color Pickers ───
        if ($.fn.wpColorPicker) {
            $('.hl-color-picker').wpColorPicker();
        }

        // ─── Sortable Steps ───
        if ($('#hl-tour-steps').length && $.fn.sortable) {
            $('#hl-tour-steps').sortable({
                handle: '.hl-tour-step-handle',
                placeholder: 'hl-tour-step-placeholder',
                tolerance: 'pointer',
                update: function() {
                    renumberSteps();
                }
            });
        }

        // ─── Auto-generate Slug from Title ───
        var $slugInput = $('input[name="tour_slug"]');
        $('input[name="tour_title"]').on('blur', function() {
            if (!$slugInput.val() || $slugInput.data('auto')) {
                var slug = $(this).val().toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-|-$/g, '');
                $slugInput.val(slug).data('auto', true);
            }
        });
        $slugInput.on('input', function() {
            $(this).data('auto', false);
        });
        // Mark as auto if slug matches auto-generated value.
        if ($slugInput.val() && $('input[name="tour_title"]').val()) {
            var autoSlug = $('input[name="tour_title"]').val().toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '');
            if ($slugInput.val() === autoSlug) {
                $slugInput.data('auto', true);
            }
        }

        // ─── Toggle Trigger Page URL Visibility ───
        $('select[name="tour_trigger_type"]').on('change', function() {
            var showPageUrl = $(this).val() === 'page_visit';
            $('.hl-tour-trigger-page-url-row').toggle(showPageUrl);
        }).trigger('change');

        // ─── Collapse/Expand Step Cards ───
        $(document).on('click', '.hl-tour-step-header', function(e) {
            // Don't toggle when clicking remove button or drag handle.
            if ($(e.target).closest('.hl-tour-remove-step').length) {
                return;
            }
            $(this).closest('.hl-tour-step-card').toggleClass('collapsed');
        });

        // ─── Update Preview Text on Title Change ───
        $(document).on('input', 'input[name="step_title[]"]', function() {
            var val = $(this).val() || 'New Step';
            $(this).closest('.hl-tour-step-card').find('.hl-tour-step-preview').text(val);
        });

        // ─── Add Step ───
        var stepCounter = $('#hl-tour-steps > .hl-tour-step-card').length;

        $('#hl-tour-add-step').on('click', function() {
            stepCounter++;
            var $template = $('#hl-tour-step-template').children('.hl-tour-step-card').clone();
            $template.find('.hl-tour-step-number').text(stepCounter);
            $template.find('.hl-tour-step-preview').text('New Step');
            // Reset position pills — default to 'auto'.
            $template.find('.hl-tour-position-pill').removeClass('active');
            $template.find('.hl-tour-position-pill[data-value="auto"]').addClass('active');
            $template.find('input[name="step_position[]"]').val('auto');
            // Reset type toggle — default to 'informational'.
            $template.find('.hl-tour-type-toggle span').removeClass('active');
            $template.find('.hl-tour-type-toggle span[data-value="informational"]').addClass('active');
            $template.find('input[name="step_type[]"]').val('informational');
            // Clear inputs.
            $template.find('input[type="text"], textarea').val('');
            $template.find('input[name="step_id[]"]').val('0');
            // Append and expand.
            $template.removeClass('collapsed');
            $('#hl-tour-steps').append($template);
            // Scroll to the new step.
            $('html, body').animate({
                scrollTop: $template.offset().top - 100
            }, 300);
        });

        // ─── Remove Step ───
        $(document).on('click', '.hl-tour-remove-step', function(e) {
            e.stopPropagation();
            if (confirm('Remove this step?')) {
                $(this).closest('.hl-tour-step-card').slideUp(200, function() {
                    $(this).remove();
                    renumberSteps();
                    stepCounter = $('#hl-tour-steps > .hl-tour-step-card').length;
                });
            }
        });

        // ─── Element Picker Launch (Placeholder) ───
        $(document).on('click', '.hl-tour-pick-element', function() {
            var $card = $(this).closest('.hl-tour-step-card');
            var pageUrl = $card.find('input[name="step_page_url[]"]').val()
                || $('input[name="tour_start_page_url"]').val();

            if (!pageUrl) {
                alert('Enter a Page URL for this step first.');
                return;
            }
            openElementPicker(pageUrl, $card);
        });

        // ─── Position Pill Selector ───
        $(document).on('click', '.hl-tour-position-pill', function() {
            var $group = $(this).closest('.hl-tour-position-pills');
            $group.find('.hl-tour-position-pill').removeClass('active');
            $(this).addClass('active');
            $group.find('input[name="step_position[]"]').val($(this).data('value'));
        });

        // ─── Step Type Toggle ───
        $(document).on('click', '.hl-tour-type-toggle span', function() {
            var $group = $(this).closest('.hl-tour-type-toggle');
            $group.find('span').removeClass('active');
            $(this).addClass('active');
            $group.find('input[name="step_type[]"]').val($(this).data('value'));
        });

        // ─── Renumber Steps ───
        function renumberSteps() {
            $('#hl-tour-steps > .hl-tour-step-card').each(function(i) {
                $(this).find('.hl-tour-step-number').text(i + 1);
            });
        }
    });

    /**
     * Element Picker Modal — opens iframe with ?hl_picker=1 for visual element selection.
     */
    window.openElementPicker = function(pageUrl, $stepCard) {
        var siteUrl = hlTourAdmin.site_url;
        var $modal  = $('#hl-picker-modal');

        // Create modal if not exists.
        if (!$modal.length) {
            $modal = $('<div id="hl-picker-modal" class="hl-picker-modal">' +
                '<div class="hl-picker-modal__header">' +
                    '<label>View page as: <select id="hl-picker-role">' +
                        '<option value="">Admin (default)</option>' +
                        '<option value="teacher">Teacher</option>' +
                        '<option value="mentor">Mentor</option>' +
                        '<option value="school_leader">School Leader</option>' +
                        '<option value="district_leader">District Leader</option>' +
                        '<option value="coach">Coach</option>' +
                    '</select></label>' +
                    '<button type="button" class="hl-picker-modal__close">&times;</button>' +
                '</div>' +
                '<iframe id="hl-picker-iframe" class="hl-picker-modal__iframe"></iframe>' +
            '</div>');
            $('body').append($modal);
        }

        function loadIframe(role) {
            var separator = pageUrl.indexOf('?') > -1 ? '&' : '?';
            var url = siteUrl + pageUrl + separator + 'hl_picker=1';
            if (role) {
                url += '&hl_view_as=' + encodeURIComponent(role);
            }
            $('#hl-picker-iframe').attr('src', url);
        }

        function closeModal() {
            $modal.removeClass('open');
            $('#hl-picker-iframe').attr('src', '');
            $(window).off('message.hlPicker');
        }

        // Show modal + load iframe.
        $modal.addClass('open');
        loadIframe($('#hl-picker-role').val());

        // Role change reloads iframe.
        $('#hl-picker-role').off('change.hlPicker').on('change.hlPicker', function() {
            loadIframe($(this).val());
        });

        // Close button.
        $modal.find('.hl-picker-modal__close').off('click.hlPicker').on('click.hlPicker', function() {
            closeModal();
        });

        // ESC to close.
        $(document).off('keydown.hlPicker').on('keydown.hlPicker', function(e) {
            if (e.key === 'Escape' && $modal.hasClass('open')) {
                closeModal();
            }
        });

        // Listen for postMessage from iframe (with origin validation).
        $(window).off('message.hlPicker').on('message.hlPicker', function(e) {
            if (e.originalEvent.origin !== window.location.origin) return;
            var data = e.originalEvent.data;
            if (data && data.type === 'hl-picker-select') {
                $stepCard.find('input[name="step_target_selector[]"]').val(data.selector);
                closeModal();
            }
            if (data && data.type === 'hl-picker-cancel') {
                closeModal();
            }
        });
    };

})(jQuery);
