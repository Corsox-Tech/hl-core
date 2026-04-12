/**
 * HL Email System v2 — Workflow admin UI.
 *
 * Condition builder + recipient picker + hidden JSON sync.
 *
 * Enqueued only on the workflow edit/new page (tab=workflows, action in [edit,new]).
 * Registries injected via wp_add_inline_script('hl-email-workflow', ..., 'before'):
 *   window.hlConditionFields    - from HL_Admin_Emails::get_condition_fields()
 *   window.hlConditionOperators - from HL_Admin_Emails::get_condition_operators()
 *   window.hlRecipientTokens    - from HL_Admin_Emails::get_recipient_tokens()
 *   window.hlEmailWorkflowCfg   - { ajaxUrl, nonces: {...}, currentTrigger }
 *
 * A.3.2 — jQuery IIFE noConflict wrapper. A.3.3 — all markup lives inside
 * .hl-email-admin for CSS specificity.
 */
jQuery(function ($) {
    'use strict';

    // A.7.4 / A.7.10 — JS loaded signal. CSS hides the raw JSON fallback
    // when this class is present. If this script never runs, admins fall
    // back to editing the hidden textarea directly through a <details>
    // disclosure.
    $('body').addClass('hl-js-loaded');

    // Belt-and-braces failure signal.
    window.addEventListener('error', function () {
        $('body').removeClass('hl-js-loaded');
    });

    // --- Initialization will be added in Tasks 8 and 10. ---
});
