/**
 * HL Auth JS
 *
 * Handles: form submit guards (spec I23), multi-step navigation with
 * per-step validation, localStorage partial saves (spec I18),
 * step error indicators (spec I7).
 */
(function($) {
    'use strict';

    // --- Form Submit Guard (spec I23) ---
    // Generic guard for login + password reset forms (non-profile).
    $('form.hl-auth-form').not('#hl-profile-form').on('submit', function(e) {
        var $btn = $(this).find('button[type="submit"]');
        if ($btn.attr('aria-disabled') === 'true') {
            e.preventDefault();
            return false;
        }
        $btn.attr('aria-disabled', 'true').addClass('hl-auth-btn--submitting');
    });

    // PI6: Password strength meter reserved for Phase 2 custom reset form.
    // The actual password entry happens on wp-login.php?action=rp (WP core),
    // where we cannot inject JS. No-op for now.

    // --- Multi-Step Navigation ---
    var $form = $('#hl-profile-form');
    if ($form.length) {
        var userId = $form.data('user-id');
        var storageKey = 'hl_profile_draft_' + userId;

        // -------------------------------------------------------
        // Restore from localStorage (spec I18)
        // Handles text inputs, selects, radio buttons, checkboxes
        // -------------------------------------------------------
        try {
            var saved = JSON.parse(localStorage.getItem(storageKey));
            if (saved) {
                Object.keys(saved).forEach(function(name) {
                    var $fields = $form.find('[name="' + name + '"]');
                    if (!$fields.length) return;

                    if ($fields.first().is(':radio')) {
                        // Radio: check the one with the matching value
                        $fields.filter('[value="' + saved[name] + '"]').prop('checked', true);
                    } else if ($fields.first().is(':checkbox')) {
                        // Checkboxes (ethnicity): saved value is array
                        if (Array.isArray(saved[name])) {
                            saved[name].forEach(function(val) {
                                $form.find('[name="' + name + '"][value="' + val + '"]').prop('checked', true);
                            });
                        }
                    } else {
                        // Spec FC2: Always restore from localStorage if saved value exists.
                        // Readonly fields are excluded from the save logic.
                        $fields.val(saved[name]);
                    }
                });
            }
        } catch(e) { /* ignore parse errors */ }

        // -------------------------------------------------------
        // Save to localStorage on field change (spec I18)
        // Captures radios, checkboxes, selects, text, URL, tel
        // -------------------------------------------------------
        $form.on('change input', 'input, select, textarea', function() {
            var data = {};
            $form.find('input, select, textarea').each(function() {
                var $el = $(this);
                var name = $el.attr('name');
                if (!name) return;
                // Skip security fields
                if (name.indexOf('nonce') !== -1 || name.indexOf('token') !== -1 || name.indexOf('action') !== -1) return;
                // Skip read-only email
                if ($el.attr('readonly')) return;

                if ($el.is(':radio')) {
                    if ($el.is(':checked')) {
                        data[name] = $el.val();
                    }
                } else if ($el.is(':checkbox')) {
                    if (!data[name]) data[name] = [];
                    if ($el.is(':checked')) data[name].push($el.val());
                } else {
                    data[name] = $el.val();
                }
            });
            try { localStorage.setItem(storageKey, JSON.stringify(data)); } catch(e) {}
        });

        // Spec FC1: Profile form submit handler -- validates step 3 (consent) BEFORE spinner.
        $form.on('submit', function(e) {
            var $btn = $form.find('button[type="submit"]');
            if ($btn.attr('aria-disabled') === 'true') {
                e.preventDefault();
                return false;
            }

            // Validate step 3 (consent checkbox) before adding spinner
            var errors = validateStep(3);
            if (errors.length > 0) {
                var $panel = $form.find('.hl-step-panel[data-step="3"]');
                $panel.find('.hl-step-errors').remove();
                var html = '<div class="hl-step-errors hl-auth-error" role="alert"><span class="dashicons dashicons-warning"></span><div>';
                errors.forEach(function(msg) { html += '<div>' + msg + '</div>'; });
                html += '</div></div>';
                $panel.prepend(html);
                e.preventDefault();
                return false;
            }

            // Validation passed -- add spinner and clear localStorage
            $btn.attr('aria-disabled', 'true').addClass('hl-auth-btn--submitting');
            try { localStorage.removeItem(storageKey); } catch(ex) {}
        });

        // -------------------------------------------------------
        // Per-step validation rules
        // Returns array of error messages; empty = valid
        // -------------------------------------------------------
        function validateStep(step) {
            var errors = [];
            var $panel = $form.find('.hl-step-panel[data-step="' + step + '"]');

            // Clear previous error indicators
            $panel.find('.hl-field-error').removeClass('hl-field-error');

            if (step === 1) {
                if (!$panel.find('[name="hl_first_name"]').val().trim()) {
                    errors.push('First name is required.');
                    $panel.find('[name="hl_first_name"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_last_name"]').val().trim()) {
                    errors.push('Last name is required.');
                    $panel.find('[name="hl_last_name"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_nickname"]').val().trim()) {
                    errors.push('Nickname is required.');
                    $panel.find('[name="hl_nickname"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_gender"]:checked').length) {
                    errors.push('Please select a gender.');
                    $panel.find('[name="hl_gender"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_ethnicity[]"]:checked').length) {
                    errors.push('Please select at least one ethnicity option.');
                    $panel.find('[name="hl_ethnicity[]"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_location_state"]').val()) {
                    errors.push('Location is required.');
                    $panel.find('[name="hl_location_state"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_age_range"]:checked').length) {
                    errors.push('Please select an age range.');
                    $panel.find('[name="hl_age_range"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_preferred_language"]:checked').length) {
                    errors.push('Please select a preferred language.');
                    $panel.find('[name="hl_preferred_language"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                // Phone is OPTIONAL -- no validation
            }

            if (step === 2) {
                // Required: years_exp_industry, years_exp_position. Job title is OPTIONAL.
                if (!$panel.find('[name="hl_years_exp_industry"]:checked').length) {
                    errors.push('Years of experience in industry is required.');
                    $panel.find('[name="hl_years_exp_industry"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_years_exp_position"]:checked').length) {
                    errors.push('Years of experience in current position is required.');
                    $panel.find('[name="hl_years_exp_position"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
            }

            if (step === 3) {
                // Social media fields are ALL optional, but consent is required.
                if (!$panel.find('[name="hl_consent"]').is(':checked')) {
                    errors.push('You must agree to the research participation terms.');
                    $panel.find('.hl-consent-box').addClass('hl-field-error');
                }
            }

            return errors;
        }

        // -------------------------------------------------------
        // Step navigation
        // -------------------------------------------------------
        function showStep(step) {
            $form.find('.hl-step-panel').hide();
            $form.find('.hl-step-panel[data-step="' + step + '"]').show();

            // Update step indicators
            $form.closest('.hl-auth-card').find('.hl-steps__item').each(function() {
                var $item = $(this);
                var itemStep = parseInt($item.data('step'), 10);
                $item.removeClass('hl-steps__item--active hl-steps__item--complete hl-steps__item--error');

                if (itemStep === step) {
                    $item.addClass('hl-steps__item--active');
                    $item.attr('aria-selected', 'true');
                } else if (itemStep < step) {
                    // Check if step has errors (spec I7)
                    var $panel = $form.find('.hl-step-panel[data-step="' + itemStep + '"]');
                    if ($panel.find('.hl-field-error').length) {
                        $item.addClass('hl-steps__item--error');
                    } else {
                        $item.addClass('hl-steps__item--complete');
                    }
                    $item.attr('aria-selected', 'false');
                } else {
                    $item.attr('aria-selected', 'false');
                }
            });

            // Scroll to top of card
            $form.closest('.hl-auth-card')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // Next: validate current step before advancing
        $form.on('click', '.hl-step-next', function() {
            var currentStep = parseInt($(this).closest('.hl-step-panel').data('step'), 10);
            var next = parseInt($(this).data('next'), 10);

            var errors = validateStep(currentStep);
            if (errors.length > 0) {
                // Show inline error summary at top of current step
                var $panel = $form.find('.hl-step-panel[data-step="' + currentStep + '"]');
                $panel.find('.hl-step-errors').remove(); // clear previous
                var html = '<div class="hl-step-errors hl-auth-error" role="alert"><span class="dashicons dashicons-warning"></span><div>';
                errors.forEach(function(msg) { html += '<div>' + msg + '</div>'; });
                html += '</div></div>';
                $panel.prepend(html);
                return; // Don't advance
            }

            // Clear error summary if valid
            $form.find('.hl-step-panel[data-step="' + currentStep + '"] .hl-step-errors').remove();
            showStep(next);
        });

        $form.on('click', '.hl-step-prev', function() {
            var prev = parseInt($(this).data('prev'), 10);
            showStep(prev);
        });
    }

})(jQuery);
