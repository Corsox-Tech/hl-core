# Workflow UX Redesign M2 — Cascading Triggers + Full Summary Panel

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat 22-option trigger dropdown with cascading Category + Event selects, add a timing config panel for cron events, build a full summary panel with plain-English sentence builder and 24h activity display, and implement reverse mapping so existing workflows edit correctly in the new UI.

**Architecture:** Purely presentational change — the `TRIGGER_MAP` JS lookup table maps Category + Event to existing `trigger_key` values. The save handler receives the same payload it does today (no schema changes). The PHP side adds a `get_trigger_categories()` static registry used only for server-side reverse mapping on edit load. The JS cascade replaces the flat `<select name="trigger_key">` with two selects that resolve to the same hidden input. Existing show/hide logic for offset, component type, and status filter rows is preserved — the cascade auto-sets those fields based on the selected event's metadata.

**Tech Stack:** PHP 7.4+, jQuery, WordPress admin AJAX, existing M1 CSS foundation

**Spec:** `docs/superpowers/specs/2026-04-15-workflow-ux-redesign-design.md` (Sections 2.2, 6, 7)
**M1 Plan:** `docs/superpowers/plans/2026-04-15-workflow-ux-redesign-m1.md`
**Mockup:** `.superpowers/brainstorm/1424-1776272106/content/design-v2-triggers.html`

---

## Pre-Read

Before starting any task, read these files to understand the current implementation:

- `includes/admin/class-hl-admin-emails.php` — `render_workflow_form_v2()` at line 1128 (the trigger card is lines 1239-1340)
- `assets/js/admin/email-workflow.js` — all 765 lines; the trigger change handler is inside `initRecipientPicker()` at line 382-398
- `assets/css/admin.css` — `.hl-wf-*` section starting at line 2608
- `includes/admin/class-hl-admin.php` — inline script injection at line 254-269
- `includes/admin/class-hl-admin-emails.php` — `handle_workflow_save()` at line 1571 (trigger validation at line 1667-1689)

---

## File Map

| File | What Changes |
|---|---|
| `includes/admin/class-hl-admin-emails.php` | Replace flat `<select name="trigger_key">` in Card 2 with cascade HTML. Add `get_trigger_categories()` static registry. Add reverse-mapping logic in data loading. Add 24h activity query. |
| `assets/js/admin/email-workflow.js` | New TRIGGER_MAP cascade module. Rewire trigger change handler. Update summary panel to use category/event labels + timing sentence. |
| `assets/css/admin.css` | Add `.hl-wf-trigger-cascade`, `.hl-wf-event-hint`, `.hl-wf-timing-config` styles. |
| `includes/admin/class-hl-admin.php` | Inject `TRIGGER_MAP` via inline script alongside existing registries. |
| `hl-core.php` | Version bump for cache busting. |

---

## Execution Order

Tasks are ordered for incremental testability. Each task produces a working state.

1. **Task 1** — PHP: `get_trigger_categories()` static registry + TRIGGER_MAP injection (~15 min)
2. **Task 2** — JS: TRIGGER_MAP cascade module + hidden input sync (~20 min)
3. **Task 3** — PHP: Replace Card 2 trigger HTML with cascade dropdowns (~15 min)
4. **Task 4** — JS: Event type hint + timing config panel logic (~10 min)
5. **Task 5** — CSS: Cascade, hint, and timing styles (~10 min)
6. **Task 6** — PHP + JS: Reverse mapping for edit mode (~15 min)
7. **Task 7** — JS: Full summary panel — timing sentence + 24h activity (~15 min)
8. **Task 8** — Deploy + smoke test + docs (~15 min)

**Early checkpoint:** After Tasks 1-3, the cascade is functional — you can create/edit workflows with the new UI. Tasks 4-7 are polish.

---

### Task 1: PHP Static Registry + TRIGGER_MAP Injection

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (add `get_trigger_categories()` after `get_recipient_tokens()`)
- Modify: `includes/admin/class-hl-admin.php` (line 256-267, add TRIGGER_MAP to inline script)

This task creates the server-side trigger category registry and injects the TRIGGER_MAP into JS. No UI changes yet — just data availability.

- [ ] **Step 1: Add `get_trigger_categories()` to `HL_Admin_Emails`**

In `includes/admin/class-hl-admin-emails.php`, find the end of the `get_recipient_tokens()` method. After it, add this new static method:

```php
/**
 * Trigger category → event → trigger_key mapping.
 *
 * Used for:
 * 1. JS TRIGGER_MAP injection (cascading dropdown)
 * 2. Server-side reverse mapping on edit load
 *
 * Categories with hidden=true are not shown in the dropdown for new
 * workflows but are recognized during reverse mapping so existing
 * workflows using those triggers render correctly.
 *
 * @return array
 */
public static function get_trigger_categories() {
    return array(
        'coaching' => array(
            'label'  => 'Coaching Session',
            'hidden' => false,
            'events' => array(
                'booked' => array(
                    'label' => 'Session Booked',
                    'key'   => 'hl_coaching_session_created',
                    'type'  => 'hook',
                ),
                'attended' => array(
                    'label'        => 'Session Attended',
                    'key'          => 'hl_coaching_session_status_changed',
                    'type'         => 'hook',
                    'statusFilter' => 'attended',
                ),
                'missed' => array(
                    'label'        => 'Session Missed / Not Attended',
                    'key'          => 'hl_coaching_session_status_changed',
                    'type'         => 'hook',
                    'statusFilter' => 'missed',
                ),
                'cancelled' => array(
                    'label'        => 'Session Cancelled',
                    'key'          => 'hl_coaching_session_status_changed',
                    'type'         => 'hook',
                    'statusFilter' => 'cancelled',
                ),
                'rescheduled' => array(
                    'label'        => 'Session Rescheduled',
                    'key'          => 'hl_coaching_session_status_changed',
                    'type'         => 'hook',
                    'statusFilter' => 'rescheduled',
                ),
                'reminder' => array(
                    'label'         => 'Scheduling Reminder',
                    'key'           => 'cron:component_upcoming',
                    'type'          => 'cron',
                    'componentType' => 'coaching_session_attendance',
                ),
            ),
        ),
        'enrollment' => array(
            'label'  => 'Enrollment',
            'hidden' => false,
            'events' => array(
                'created' => array(
                    'label' => 'Enrollment Created',
                    'key'   => 'hl_enrollment_created',
                    'type'  => 'hook',
                ),
                'pathway_assigned' => array(
                    'label' => 'Pathway Assigned',
                    'key'   => 'hl_pathway_assigned',
                    'type'  => 'hook',
                ),
                'pathway_completed' => array(
                    'label' => 'Pathway Completed',
                    'key'   => 'hl_pathway_completed',
                    'type'  => 'hook',
                ),
                'coach_assigned' => array(
                    'label' => 'Coach Assigned',
                    'key'   => 'hl_coach_assigned',
                    'type'  => 'hook',
                ),
            ),
        ),
        'course' => array(
            'label'  => 'Course',
            'hidden' => false,
            'events' => array(
                'completed' => array(
                    'label' => 'Course Completed',
                    'key'   => 'hl_learndash_course_completed',
                    'type'  => 'hook',
                ),
                'reminder' => array(
                    'label'         => 'Course Due Reminder',
                    'key'           => 'cron:component_upcoming',
                    'type'          => 'cron',
                    'componentType' => 'learndash_course',
                ),
                'overdue' => array(
                    'label'         => 'Course Overdue',
                    'key'           => 'cron:component_overdue',
                    'type'          => 'cron',
                    'componentType' => 'learndash_course',
                ),
            ),
        ),
        'classroom_visit' => array(
            'label'  => 'Classroom Visit',
            'hidden' => true,
            'events' => array(
                'submitted' => array(
                    'label' => 'Visit Form Submitted',
                    'key'   => 'hl_classroom_visit_submitted',
                    'type'  => 'hook',
                ),
                'reminder' => array(
                    'label'         => 'Visit Due Reminder',
                    'key'           => 'cron:component_upcoming',
                    'type'          => 'cron',
                    'componentType' => 'classroom_visit',
                ),
            ),
        ),
        'rp_session' => array(
            'label'  => 'RP Session',
            'hidden' => true,
            'events' => array(
                'created' => array(
                    'label' => 'Session Created',
                    'key'   => 'hl_rp_session_created',
                    'type'  => 'hook',
                ),
                'attended' => array(
                    'label'        => 'Session Attended',
                    'key'          => 'hl_rp_session_status_changed',
                    'type'         => 'hook',
                    'statusFilter' => 'attended',
                ),
                'missed' => array(
                    'label'        => 'Session Missed',
                    'key'          => 'hl_rp_session_status_changed',
                    'type'         => 'hook',
                    'statusFilter' => 'missed',
                ),
                'cancelled' => array(
                    'label'        => 'Session Cancelled',
                    'key'          => 'hl_rp_session_status_changed',
                    'type'         => 'hook',
                    'statusFilter' => 'cancelled',
                ),
                'reminder' => array(
                    'label'         => 'Session Due Reminder',
                    'key'           => 'cron:component_upcoming',
                    'type'          => 'cron',
                    'componentType' => 'reflective_practice_session',
                ),
            ),
        ),
        'assessment' => array(
            'label'  => 'Assessment',
            'hidden' => true,
            'events' => array(
                'tsa_submitted' => array(
                    'label' => 'Teacher Assessment Submitted',
                    'key'   => 'hl_teacher_assessment_submitted',
                    'type'  => 'hook',
                ),
                'ca_submitted' => array(
                    'label' => 'Child Assessment Submitted',
                    'key'   => 'hl_child_assessment_submitted',
                    'type'  => 'hook',
                ),
            ),
        ),
        'schedule' => array(
            'label'  => 'Schedule',
            'hidden' => true,
            'events' => array(
                'low_engagement' => array(
                    'label' => 'Low Engagement (14 days)',
                    'key'   => 'cron:low_engagement_14d',
                    'type'  => 'cron',
                ),
                'account_activated' => array(
                    'label' => 'Account Activated',
                    'key'   => 'user_register',
                    'type'  => 'hook',
                ),
            ),
        ),
    );
}
```

- [ ] **Step 2: Inject TRIGGER_MAP into JS inline script**

In `includes/admin/class-hl-admin.php`, find the inline script block (line 256-267). Add the TRIGGER_MAP after the existing registries. Change:

```php
$inline = 'window.hlConditionFields = '    . wp_json_encode( HL_Admin_Emails::get_condition_fields(),    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
        . 'window.hlConditionOperators = ' . wp_json_encode( HL_Admin_Emails::get_condition_operators(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
        . 'window.hlRecipientTokens = '    . wp_json_encode( HL_Admin_Emails::get_recipient_tokens(),    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
        . 'window.hlEmailWorkflowCfg = '   . wp_json_encode( array(
```

To:

```php
$inline = 'window.hlConditionFields = '    . wp_json_encode( HL_Admin_Emails::get_condition_fields(),    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
        . 'window.hlConditionOperators = ' . wp_json_encode( HL_Admin_Emails::get_condition_operators(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
        . 'window.hlRecipientTokens = '    . wp_json_encode( HL_Admin_Emails::get_recipient_tokens(),    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
        . 'window.hlTriggerMap = '         . wp_json_encode( HL_Admin_Emails::get_trigger_categories(),  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
        . 'window.hlEmailWorkflowCfg = '   . wp_json_encode( array(
```

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php includes/admin/class-hl-admin.php
git commit -m "feat(email): trigger category registry + TRIGGER_MAP JS injection for M2 cascade"
```

---

### Task 2: JS TRIGGER_MAP Cascade Module + Hidden Input Sync

**Files:**
- Modify: `assets/js/admin/email-workflow.js` (add new module after the Mobile Drawer Toggle module at line 763)

This task adds the core cascade logic: when Category changes, populate Event dropdown; when Event changes, sync the hidden `trigger_key` input and auto-set `trigger_status_filter` + `component_type_filter`. The existing trigger change handler at line 382-398 already handles offset/component-type/status-filter show/hide — we just need to trigger it after setting the hidden input.

- [ ] **Step 1: Add the Trigger Cascade module**

In `assets/js/admin/email-workflow.js`, find the closing of the Mobile Drawer Toggle module (line 763: `});`). Just ABOVE that final `});` (which closes the outer `jQuery(function($){`), add:

```javascript
// =====================================================================
// MODULE: Trigger Cascade (M2)
// =====================================================================
(function () {
    var MAP = window.hlTriggerMap || {};
    var $catSelect   = $('select[name="trigger_category"]');
    var $eventSelect = $('select[name="trigger_event"]');
    var $keyInput    = $('input[name="trigger_key"]');

    if (!$catSelect.length || !$eventSelect.length || !$keyInput.length) return;

    // Build reverse lookup: trigger_key + statusFilter + componentType -> {cat, event}
    var REVERSE = {};
    $.each(MAP, function (catKey, catDef) {
        $.each(catDef.events || {}, function (evtKey, evtDef) {
            var rKey = evtDef.key;
            if (evtDef.statusFilter) rKey += '|sf:' + evtDef.statusFilter;
            if (evtDef.componentType) rKey += '|ct:' + evtDef.componentType;
            REVERSE[rKey] = { cat: catKey, event: evtKey };
        });
    });

    // Populate event dropdown from category.
    function populateEvents(catKey, preserveEvent) {
        var cat = MAP[catKey];
        $eventSelect.empty().append('<option value="">\u2014 Select Event \u2014</option>');
        if (!cat || !cat.events) return;
        $.each(cat.events, function (evtKey, evtDef) {
            $eventSelect.append(
                '<option value="' + escHtml(evtKey) + '">' + escHtml(evtDef.label) + '</option>'
            );
        });
        if (preserveEvent) {
            $eventSelect.val(preserveEvent);
        }
    }

    // Sync hidden fields from the selected event.
    function syncFromEvent() {
        var catKey = $catSelect.val();
        var evtKey = $eventSelect.val();
        var cat = MAP[catKey];
        if (!cat || !evtKey) {
            $keyInput.val('');
            updateEventHint(null);
            updateTimingPanel(null);
            return;
        }
        var evt = cat.events[evtKey];
        if (!evt) {
            $keyInput.val('');
            return;
        }

        // Set the hidden trigger_key — this is what the save handler reads.
        $keyInput.val(evt.key);

        // Auto-set status filter hidden field.
        var $statusFilter = $('select[name="trigger_status_filter"]');
        if (evt.statusFilter) {
            $statusFilter.val(evt.statusFilter);
        } else {
            $statusFilter.val('');
        }

        // Auto-set component type filter.
        var $compType = $('select[name="component_type_filter"]');
        if (evt.componentType) {
            $compType.val(evt.componentType);
        }

        // Fire the existing trigger_key change handler to update offset/component-type/status-filter visibility.
        $keyInput.trigger('cascade-sync');

        // Update hint + timing panel.
        updateEventHint(evt);
        updateTimingPanel(evt);
    }

    // Category change -> repopulate events.
    $catSelect.on('change', function () {
        populateEvents($(this).val(), null);
        $eventSelect.trigger('change');
    });

    // Event change -> sync hidden fields.
    $eventSelect.on('change', syncFromEvent);

    // Expose reverse lookup for Task 6 (edit mode).
    window._hlTriggerReverse = REVERSE;
    window._hlTriggerPopulateEvents = populateEvents;

    // Event hint updater.
    function updateEventHint(evt) {
        var $hintHook = $('.hl-wf-event-hint-hook');
        var $hintCron = $('.hl-wf-event-hint-cron');
        if (!evt) {
            $hintHook.hide();
            $hintCron.hide();
            return;
        }
        if (evt.type === 'cron') {
            $hintCron.show();
            $hintHook.hide();
        } else {
            $hintHook.show();
            $hintCron.hide();
        }
    }

    // Timing panel updater.
    function updateTimingPanel(evt) {
        var $panel = $('.hl-wf-timing-config');
        if (!evt || evt.type !== 'cron') {
            $panel.hide();
            return;
        }
        $panel.show();

        // Build human-readable translation.
        var offsetVal  = parseInt($('input[name="trigger_offset_value"]').val(), 10) || 0;
        var offsetUnit = $('select[name="trigger_offset_unit"] option:selected').text() || 'Days';
        var catLabel   = MAP[$catSelect.val()] ? MAP[$catSelect.val()].label : '';
        var anchorLabel = 'Display Window Start';
        var translation = offsetVal + ' ' + offsetUnit.toLowerCase() + ' before ' + catLabel.toLowerCase() + ' ' + anchorLabel.toLowerCase();

        $('.hl-wf-timing-translation').text(translation);
    }

    // Update timing translation when offset fields change.
    $('input[name="trigger_offset_value"], select[name="trigger_offset_unit"]').on('change input', function () {
        var catKey = $catSelect.val();
        var evtKey = $eventSelect.val();
        var cat = MAP[catKey];
        if (cat && cat.events && cat.events[evtKey]) {
            updateTimingPanel(cat.events[evtKey]);
        }
    });
})();
```

- [ ] **Step 2: Rewire the existing trigger change handler to listen to `cascade-sync`**

In `assets/js/admin/email-workflow.js`, find the trigger change handler inside `initRecipientPicker()` (line 382-398). It currently listens to `$triggerSelect.on('change', function () {` where `$triggerSelect = $('select[name="trigger_key"]')`. 

The cascade sets the hidden `trigger_key` input and fires `cascade-sync`. We need the existing show/hide logic to run on both events. Change this block:

```javascript
$triggerSelect.on('change', function () {
    var val = $(this).val();
    applyTriggerVisibility($wrap, val);
    serializeRecipients($wrap, $textarea);
    scheduleRecipientCount($wrap);

    // Task 7: show/hide offset and component type fields.
    var offsetTriggers = ['cron:component_upcoming', 'cron:component_overdue', 'cron:session_upcoming'];
    var componentTypeTriggers = ['cron:component_upcoming', 'cron:component_overdue'];
    $('.hl-wf-offset-row').toggle(offsetTriggers.indexOf(val) !== -1);
    $('.hl-wf-component-type-row').toggle(componentTypeTriggers.indexOf(val) !== -1);
    $('.hl-wf-session-fuzz-note').toggle(val === 'cron:session_upcoming');

    // Task 8: show/hide status sub-filter.
    var statusFilterTriggers = ['hl_coaching_session_status_changed', 'hl_rp_session_status_changed'];
    $('.hl-wf-status-filter-row').toggle(statusFilterTriggers.indexOf(val) !== -1);
}).trigger('change');
```

To:

```javascript
function onTriggerChange() {
    var val = $triggerSelect.val();
    applyTriggerVisibility($wrap, val);
    serializeRecipients($wrap, $textarea);
    scheduleRecipientCount($wrap);

    // Show/hide offset and component type fields.
    var offsetTriggers = ['cron:component_upcoming', 'cron:component_overdue', 'cron:session_upcoming'];
    var componentTypeTriggers = ['cron:component_upcoming', 'cron:component_overdue'];
    $('.hl-wf-offset-row').toggle(offsetTriggers.indexOf(val) !== -1);
    $('.hl-wf-component-type-row').toggle(componentTypeTriggers.indexOf(val) !== -1);
    $('.hl-wf-session-fuzz-note').toggle(val === 'cron:session_upcoming');

    // Show/hide status sub-filter.
    var statusFilterTriggers = ['hl_coaching_session_status_changed', 'hl_rp_session_status_changed'];
    $('.hl-wf-status-filter-row').toggle(statusFilterTriggers.indexOf(val) !== -1);
}
$triggerSelect.on('change cascade-sync', onTriggerChange).trigger('change');
```

- [ ] **Step 3: Update progressive disclosure to listen to `trigger_category`**

In the Progressive Disclosure module (line 698-723), the trigger check uses `$('select[name="trigger_key"]')`. Since the cascade now drives trigger selection, also listen to the category select. Find:

```javascript
// On new: reveal when trigger is selected.
$('select[name="trigger_key"]').on('change', function() {
    if ($(this).val()) {
        $progressiveCards.addClass('hl-wf-revealed');
        // Hide onboarding, show summary.
        $('.hl-wf-summary-onboarding').hide();
        $('.hl-wf-summary-sentence').show();
    }
});

// Check on load in case trigger is pre-selected.
if ($('select[name="trigger_key"]').val()) {
```

Change to:

```javascript
// On new: reveal when trigger category is selected.
$('select[name="trigger_category"]').on('change', function() {
    if ($(this).val()) {
        $progressiveCards.addClass('hl-wf-revealed');
        $('.hl-wf-summary-onboarding').hide();
        $('.hl-wf-summary-sentence').show();
    }
});
// Fallback: also listen to hidden trigger_key for v1 compat.
$('input[name="trigger_key"]').on('change cascade-sync', function() {
    if ($(this).val()) {
        $progressiveCards.addClass('hl-wf-revealed');
        $('.hl-wf-summary-onboarding').hide();
        $('.hl-wf-summary-sentence').show();
    }
});

// Check on load in case trigger is pre-selected.
if ($('input[name="trigger_key"]').val() || $('select[name="trigger_category"]').val()) {
```

- [ ] **Step 4: Commit**

```bash
git add assets/js/admin/email-workflow.js
git commit -m "feat(email): TRIGGER_MAP cascade JS module with hidden input sync"
```

---

### Task 3: Replace Card 2 Trigger HTML with Cascade Dropdowns

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (lines 1239-1340, the Card 2 body)

Replace the flat `<select name="trigger_key">` with two cascade selects + a hidden input. The existing offset, component-type, and status-filter rows stay — they're already in the card body and controlled by JS.

- [ ] **Step 1: Replace the trigger dropdown in Card 2**

In `render_workflow_form_v2()`, find the Card 2 body (lines 1248-1340). Replace the entire `<div class="hl-wf-card-body">` contents of Card 2 with:

```php
<div class="hl-wf-card-body">
    <?php // Hidden input — this is what the save handler reads. ?>
    <input type="hidden" name="trigger_key" value="<?php echo esc_attr( $workflow->trigger_key ?? '' ); ?>">

    <?php // Cascading Category + Event dropdowns. ?>
    <div class="hl-wf-trigger-cascade">
        <div class="hl-wf-trigger-cascade-col">
            <label class="hl-wf-form-label"><?php esc_html_e( 'Category', 'hl-core' ); ?></label>
            <select name="trigger_category" class="hl-wf-form-select" required>
                <option value=""><?php esc_html_e( '— Select Category —', 'hl-core' ); ?></option>
                <?php
                $categories = self::get_trigger_categories();
                foreach ( $categories as $cat_key => $cat_def ) :
                    if ( ! empty( $cat_def['hidden'] ) ) continue;
                ?>
                    <option value="<?php echo esc_attr( $cat_key ); ?>"><?php echo esc_html( $cat_def['label'] ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="hl-wf-trigger-cascade-col">
            <label class="hl-wf-form-label"><?php esc_html_e( 'Event', 'hl-core' ); ?></label>
            <select name="trigger_event" class="hl-wf-form-select" required>
                <option value=""><?php esc_html_e( '— Select Event —', 'hl-core' ); ?></option>
            </select>
        </div>
    </div>

    <?php // Event type hints (shown/hidden by JS). ?>
    <div class="hl-wf-event-hint hl-wf-event-hint-hook" style="display:none;">
        &#9889; <span><strong><?php esc_html_e( 'Instant trigger', 'hl-core' ); ?></strong> &mdash; <?php esc_html_e( 'fires immediately when the event happens.', 'hl-core' ); ?></span>
    </div>
    <div class="hl-wf-event-hint hl-wf-event-hint-cron" style="display:none;">
        &#128337; <span><strong><?php esc_html_e( 'Scheduled trigger', 'hl-core' ); ?></strong> &mdash; <?php esc_html_e( 'runs on a timer relative to a date. Configure timing below.', 'hl-core' ); ?></span>
    </div>

    <?php // Timing config panel (shown only for cron events by JS). ?>
    <div class="hl-wf-timing-config" style="display:none;">
        <div class="hl-wf-timing-config-title"><?php esc_html_e( 'Timing', 'hl-core' ); ?></div>
        <div class="hl-wf-timing-row">
            <input type="number" name="trigger_offset_value" min="1" max="9999" value="<?php echo esc_attr( $offset_value ); ?>" class="hl-wf-form-input" style="width:70px;text-align:center;">
            <select name="trigger_offset_unit" class="hl-wf-form-select" style="width:auto;">
                <option value="days" <?php selected( $offset_unit, 'days' ); ?>><?php esc_html_e( 'Days', 'hl-core' ); ?></option>
                <option value="hours" <?php selected( $offset_unit, 'hours' ); ?>><?php esc_html_e( 'Hours', 'hl-core' ); ?></option>
                <option value="minutes" <?php selected( $offset_unit, 'minutes' ); ?>><?php esc_html_e( 'Minutes', 'hl-core' ); ?></option>
            </select>
            <span class="hl-wf-timing-label"><?php esc_html_e( 'before', 'hl-core' ); ?></span>
        </div>
        <div class="hl-wf-timing-anchor">
            &#128197; <?php esc_html_e( 'Translates to:', 'hl-core' ); ?> <strong class="hl-wf-timing-translation"></strong>
        </div>
        <p class="hl-wf-form-hint hl-wf-session-fuzz-note" style="display:none;">
            <?php esc_html_e( 'Session reminders use a tolerance window to account for cron timing.', 'hl-core' ); ?>
        </p>
    </div>

    <?php // Component type row — shown/hidden by JS for cron:component_upcoming/overdue. ?>
    <div class="hl-wf-form-row hl-wf-component-type-row" style="display:none;">
        <label class="hl-wf-form-label"><?php esc_html_e( 'Component Type', 'hl-core' ); ?></label>
        <select name="component_type_filter" class="hl-wf-form-select">
            <option value=""><?php esc_html_e( 'All Component Types', 'hl-core' ); ?></option>
            <option value="learndash_course" <?php selected( $workflow->component_type_filter ?? '', 'learndash_course' ); ?>><?php esc_html_e( 'Course', 'hl-core' ); ?></option>
            <option value="coaching_session_attendance" <?php selected( $workflow->component_type_filter ?? '', 'coaching_session_attendance' ); ?>><?php esc_html_e( 'Coaching Session', 'hl-core' ); ?></option>
            <option value="classroom_visit" <?php selected( $workflow->component_type_filter ?? '', 'classroom_visit' ); ?>><?php esc_html_e( 'Classroom Visit', 'hl-core' ); ?></option>
            <option value="reflective_practice_session" <?php selected( $workflow->component_type_filter ?? '', 'reflective_practice_session' ); ?>><?php esc_html_e( 'Reflective Practice', 'hl-core' ); ?></option>
            <option value="self_reflection" <?php selected( $workflow->component_type_filter ?? '', 'self_reflection' ); ?>><?php esc_html_e( 'Self-Reflection', 'hl-core' ); ?></option>
            <option value="teacher_self_assessment" <?php selected( $workflow->component_type_filter ?? '', 'teacher_self_assessment' ); ?>><?php esc_html_e( 'Teacher Assessment', 'hl-core' ); ?></option>
            <option value="child_assessment" <?php selected( $workflow->component_type_filter ?? '', 'child_assessment' ); ?>><?php esc_html_e( 'Child Assessment', 'hl-core' ); ?></option>
        </select>
    </div>

    <?php // Status filter row — shown/hidden by JS for coaching/RP triggers. ?>
    <div class="hl-wf-form-row hl-wf-status-filter-row" style="display:none;">
        <label class="hl-wf-form-label"><?php esc_html_e( 'Status Filter', 'hl-core' ); ?></label>
        <select name="trigger_status_filter" id="wf-trigger-status-filter" class="hl-wf-form-select" aria-label="<?php esc_attr_e( 'Filter by session status', 'hl-core' ); ?>">
            <option value="" <?php selected( $trigger_status_val, '' ); ?>><?php esc_html_e( 'Any Status Change', 'hl-core' ); ?></option>
            <option value="scheduled" <?php selected( $trigger_status_val, 'scheduled' ); ?>><?php esc_html_e( 'Session Booked', 'hl-core' ); ?></option>
            <option value="attended" <?php selected( $trigger_status_val, 'attended' ); ?>><?php esc_html_e( 'Session Attended', 'hl-core' ); ?></option>
            <option value="cancelled" <?php selected( $trigger_status_val, 'cancelled' ); ?>><?php esc_html_e( 'Session Cancelled', 'hl-core' ); ?></option>
            <option value="missed" <?php selected( $trigger_status_val, 'missed' ); ?>><?php esc_html_e( 'Session Missed', 'hl-core' ); ?></option>
            <option value="rescheduled" <?php selected( $trigger_status_val, 'rescheduled' ); ?>><?php esc_html_e( 'Session Rescheduled', 'hl-core' ); ?></option>
        </select>
    </div>
</div>
```

Note: The old `<div class="hl-wf-form-row hl-wf-offset-row">` is removed — the offset inputs are now inside the timing config panel. The component-type and status-filter rows are preserved as-is. The `$triggerSelect` reference in JS (`$('select[name="trigger_key"]')`) now targets a hidden input — the cascade module handles population.

- [ ] **Step 2: Verify form submission still works**

The save handler at line 1611 reads `$_POST['trigger_key']` — the hidden input ensures this still receives the correct value. No save handler changes needed.

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php
git commit -m "feat(email): cascade Category + Event dropdowns replace flat trigger select"
```

---

### Task 4: JS Event Type Hint + Timing Config Panel Logic

**Files:**
- Modify: `assets/js/admin/email-workflow.js` (minor — the hint/timing logic is already in Task 2's cascade module)

Task 2 already includes `updateEventHint()` and `updateTimingPanel()`. This task verifies they work correctly and adds the timing translation update for the timing row inputs.

- [ ] **Step 1: Verify the hint + timing functions are complete**

The Task 2 cascade module already contains:
- `updateEventHint(evt)` — shows green hint for hooks, blue hint for cron, hides both when no event
- `updateTimingPanel(evt)` — shows/hides the `.hl-wf-timing-config` panel, builds the human-readable translation

Verify these are working by checking that:
1. Select "Coaching Session" category, then "Session Booked" — green hook hint appears, no timing panel
2. Select "Coaching Session" category, then "Scheduling Reminder" — blue cron hint appears, timing panel appears
3. Change offset value — translation updates
4. Switch to "Enrollment" category — hints disappear, timing panel hides

No code changes needed if Task 2 was implemented correctly — this is a verification step.

- [ ] **Step 2: Commit (only if fixes were needed)**

```bash
git add assets/js/admin/email-workflow.js
git commit -m "fix(email): event hint + timing panel edge cases"
```

---

### Task 5: CSS — Cascade, Hint, and Timing Styles

**Files:**
- Modify: `assets/css/admin.css` (add after line 2791, the end of the M1 `.hl-wf-*` section)
- Modify: `hl-core.php` (version bump)

- [ ] **Step 1: Add the M2 cascade CSS**

In `assets/css/admin.css`, after the last M1 rule (line 2791 `}`), add:

```css
/* =================================================================== */
/* Email System v2 M2 — Cascading Triggers + Timing Config             */
/* =================================================================== */

/* --- Trigger cascade layout ---------------------------------------- */
.hl-wf-trigger-cascade {
    display: flex; gap: 10px; align-items: start;
}
.hl-wf-trigger-cascade-col { flex: 1; }

/* --- Event type hints ---------------------------------------------- */
.hl-wf-event-hint {
    margin-top: 8px; padding: 10px 14px; border-radius: 6px;
    font-size: 12px; display: flex; align-items: center; gap: 8px;
}
.hl-wf-event-hint-hook {
    background: #F0FDF4; border: 1px solid #BBF7D0; color: #166534;
}
.hl-wf-event-hint-cron {
    background: #EEF2FF; border: 1px solid #C7D2FE; color: #3730A3;
}

/* --- Timing config panel ------------------------------------------- */
.hl-wf-timing-config {
    margin-top: 14px; padding: 16px; background: #F9FAFB;
    border: 1px solid #E5E7EB; border-radius: 8px;
    animation: hlWfSlideDown 0.2s ease-out;
}
@keyframes hlWfSlideDown {
    from { opacity: 0; transform: translateY(-6px); }
    to { opacity: 1; transform: translateY(0); }
}
.hl-wf-timing-config-title {
    font-size: 12px; font-weight: 700; color: #4F46E5;
    text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 10px;
}
.hl-wf-timing-row {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
}
.hl-wf-timing-row input[type="number"] {
    width: 70px; padding: 8px 10px; border: 1px solid #D1D5DB;
    border-radius: 6px; font-size: 14px; text-align: center;
}
.hl-wf-timing-row select {
    padding: 8px 12px; border: 1px solid #D1D5DB;
    border-radius: 6px; font-size: 14px; background: white;
}
.hl-wf-timing-label {
    font-size: 13px; color: #6B7280; font-weight: 500;
}
.hl-wf-timing-anchor {
    margin-top: 8px; font-size: 11px; color: #9CA3AF;
    padding: 6px 10px; background: #F3F4F6; border-radius: 4px;
    display: inline-block;
}
.hl-wf-timing-anchor strong { color: #374151; }

/* --- Unrecognized trigger warning banner --------------------------- */
.hl-wf-trigger-warning {
    margin-top: 12px; padding: 12px 16px; border-radius: 8px;
    background: #FFFBEB; border: 1px solid #FDE68A;
    font-size: 13px; color: #92400E;
    display: flex; align-items: center; gap: 8px;
}

/* --- 24h activity in summary panel --------------------------------- */
.hl-wf-activity {
    font-size: 13px; color: #6B7280;
}
.hl-wf-activity-stat {
    display: inline-block; margin-right: 12px;
}
.hl-wf-activity-stat strong { color: #374151; }

/* --- Responsive: cascade stacks on narrow screens ------------------ */
@media (max-width: 1100px) {
    .hl-wf-trigger-cascade { flex-direction: column; gap: 8px; }
}
```

- [ ] **Step 2: Bump version for cache busting**

In `hl-core.php`, find `define( 'HL_CORE_VERSION',` and bump (e.g., `1.2.4` → `1.2.5`).

- [ ] **Step 3: Commit**

```bash
git add assets/css/admin.css hl-core.php
git commit -m "style(email): M2 cascade, hint, timing, warning banner CSS + version bump"
```

---

### Task 6: Reverse Mapping for Edit Mode

**Files:**
- Modify: `includes/admin/class-hl-admin-emails.php` (data loading section of `render_workflow_form_v2()`, lines 1128-1195)
- Modify: `assets/js/admin/email-workflow.js` (add init logic to cascade module)

When editing an existing workflow, the form loads with a `trigger_key` + `trigger_status_filter` + `component_type_filter`. The cascade must resolve these back to Category + Event and pre-select the dropdowns. Unrecognized triggers show a yellow warning banner with the trigger section read-only.

- [ ] **Step 1: Add PHP reverse-mapping helper + data attributes**

In `render_workflow_form_v2()`, after the data loading section (line 1193, after `$wf_status = $workflow->status ?? 'draft';`), add:

```php
// Reverse-map stored trigger_key to category + event for cascade pre-selection.
$resolved_cat   = '';
$resolved_event = '';
$trigger_unrecognized = false;

if ( $workflow && ! empty( $workflow->trigger_key ) ) {
    $categories = self::get_trigger_categories();
    $stored_key    = $workflow->trigger_key;
    $stored_status = $trigger_status_val;
    $stored_comp   = $workflow->component_type_filter ?? '';

    foreach ( $categories as $cat_key => $cat_def ) {
        foreach ( $cat_def['events'] as $evt_key => $evt_def ) {
            if ( $evt_def['key'] !== $stored_key ) continue;

            // Disambiguate by statusFilter if present.
            if ( ! empty( $evt_def['statusFilter'] ) && $evt_def['statusFilter'] !== $stored_status ) continue;

            // Disambiguate by componentType if present.
            if ( ! empty( $evt_def['componentType'] ) && $stored_comp && $evt_def['componentType'] !== $stored_comp ) continue;

            $resolved_cat   = $cat_key;
            $resolved_event = $evt_key;
            break 2;
        }
    }

    if ( ! $resolved_cat ) {
        $trigger_unrecognized = true;
    }
}
```

Then, in the cascade HTML (Task 3), add data attributes to the container div so JS can read the resolved values. Find the opening `<div class="hl-wf-trigger-cascade">` and change it to:

```php
<div class="hl-wf-trigger-cascade" data-resolved-cat="<?php echo esc_attr( $resolved_cat ); ?>" data-resolved-event="<?php echo esc_attr( $resolved_event ); ?>" data-unrecognized="<?php echo $trigger_unrecognized ? '1' : '0'; ?>">
```

After the cascade HTML (after the closing `</div>` of `.hl-wf-trigger-cascade` but still inside Card 2 body), add the warning banner:

```php
<?php if ( $trigger_unrecognized ) : ?>
    <div class="hl-wf-trigger-warning">
        &#9888; <?php esc_html_e( 'This workflow uses a trigger that was configured manually. The trigger section is read-only — contact support to modify it.', 'hl-core' ); ?>
        <br><small><?php echo esc_html( sprintf( 'Raw trigger_key: %s', $workflow->trigger_key ) ); ?></small>
    </div>
<?php endif; ?>
```

- [ ] **Step 2: Add JS reverse-mapping initialization**

In `assets/js/admin/email-workflow.js`, inside the Trigger Cascade module (the IIFE from Task 2), after the `window._hlTriggerPopulateEvents = populateEvents;` line, add the initialization block:

```javascript
// ── Edit-mode initialization: pre-select from data attributes. ──
var $cascade = $('.hl-wf-trigger-cascade');
var resolvedCat   = $cascade.attr('data-resolved-cat') || '';
var resolvedEvent = $cascade.attr('data-resolved-event') || '';
var unrecognized  = $cascade.attr('data-unrecognized') === '1';

if (unrecognized) {
    // Disable both dropdowns — trigger section is read-only.
    $catSelect.prop('disabled', true);
    $eventSelect.prop('disabled', true);
} else if (resolvedCat) {
    $catSelect.val(resolvedCat);
    populateEvents(resolvedCat, resolvedEvent);
    // Don't fire syncFromEvent — the hidden trigger_key is already correct from PHP.
    // Just update the visual hint + timing panel.
    var cat = MAP[resolvedCat];
    if (cat && cat.events && cat.events[resolvedEvent]) {
        updateEventHint(cat.events[resolvedEvent]);
        updateTimingPanel(cat.events[resolvedEvent]);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-hl-admin-emails.php assets/js/admin/email-workflow.js
git commit -m "feat(email): reverse mapping resolves stored trigger_key to cascade dropdowns on edit"
```

---

### Task 7: Full Summary Panel — Timing Sentence + 24h Activity

**Files:**
- Modify: `assets/js/admin/email-workflow.js` (update Summary Panel module, lines 548-620)
- Modify: `includes/admin/class-hl-admin-emails.php` (add 24h activity HTML to summary panel, after guardrails)

The M1 summary sentence uses the flat trigger label. M2 needs to use the cascade labels (category + event) and include the timing sentence for cron events. Also add 24h activity display.

- [ ] **Step 1: Update the summary sentence builder to use cascade labels**

In `assets/js/admin/email-workflow.js`, find the `updateSummary()` function (line 553). The old code reads trigger label from `$('select[name="trigger_key"]')` — which is now a hidden input after Task 3. Replace those two lines:

```javascript
var triggerText = $('select[name="trigger_key"] option:selected').text();
var triggerLabel = (triggerText && triggerText !== '— Select —') ? triggerText : '';
```

With:

```javascript
// Build trigger label from cascade dropdowns.
var MAP = window.hlTriggerMap || {};
var catKey = $('select[name="trigger_category"]').val();
var evtKey = $('select[name="trigger_event"]').val();
var catLabel = (MAP[catKey] && MAP[catKey].label) ? MAP[catKey].label : '';
var evtLabel = (MAP[catKey] && MAP[catKey].events && MAP[catKey].events[evtKey])
    ? MAP[catKey].events[evtKey].label : '';
var triggerLabel = catLabel && evtLabel ? catLabel + ' \u2014 ' + evtLabel : (catLabel || evtLabel || '');
var evt = (MAP[catKey] && MAP[catKey].events) ? MAP[catKey].events[evtKey] : null;
```

Then, in the sentence builder, after the trigger line, add a timing sentence for cron events. Find:

```javascript
if (triggerLabel) {
    sentence += '<br><br><strong>When:</strong> ' + escHtml(triggerLabel);
}
```

Change to:

```javascript
if (triggerLabel) {
    sentence += '<br><br><strong>When:</strong> ' + escHtml(triggerLabel);
    // Add timing translation for cron events.
    if (evt && evt.type === 'cron') {
        var offsetVal  = parseInt($('input[name="trigger_offset_value"]').val(), 10) || 0;
        var offsetUnit = $('select[name="trigger_offset_unit"] option:selected').text() || 'Days';
        if (offsetVal > 0) {
            sentence += '<br><em style="color:#6B7280;font-size:12px;">' + escHtml(offsetVal + ' ' + offsetUnit.toLowerCase() + ' before ' + catLabel.toLowerCase() + ' display window start') + '</em>';
        }
    }
}
```

Also listen to the cascade selects for summary updates. Find:

```javascript
$('select[name="trigger_key"], select[name="template_id"]').on('change', updateSummary);
```

Change to:

```javascript
$('select[name="trigger_category"], select[name="trigger_event"], select[name="template_id"]').on('change', updateSummary);
$('input[name="trigger_key"]').on('cascade-sync', updateSummary);
$('input[name="trigger_offset_value"], select[name="trigger_offset_unit"]').on('change input', updateSummary);
```

- [ ] **Step 2: Add 24h activity HTML to the summary panel**

In `includes/admin/class-hl-admin-emails.php`, in `render_workflow_form_v2()`, find the guardrails section (line 1533-1538). After the closing `</div>` of `.hl-wf-guardrails`, add:

```php
<div class="hl-wf-divider"></div>

<?php // 24h Activity. ?>
<div class="hl-wf-activity-section">
    <div class="hl-wf-guardrails-label"><?php esc_html_e( 'Last 24h Activity', 'hl-core' ); ?></div>
    <?php if ( $workflow_id ) :
        $activity = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(status = 'sent') AS sent_count,
                SUM(status = 'failed') AS failed_count,
                SUM(status IN ('pending','processing')) AS pending_count
             FROM {$wpdb->prefix}hl_email_queue
             WHERE workflow_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $workflow_id
        ) );
        $sent    = (int) ( $activity->sent_count ?? 0 );
        $failed  = (int) ( $activity->failed_count ?? 0 );
        $pending = (int) ( $activity->pending_count ?? 0 );
        if ( $sent || $failed || $pending ) :
    ?>
        <div class="hl-wf-activity">
            <span class="hl-wf-activity-stat"><strong><?php echo $sent; ?></strong> <?php esc_html_e( 'sent', 'hl-core' ); ?></span>
            <?php if ( $failed ) : ?>
                <span class="hl-wf-activity-stat" style="color:#DC2626;"><strong><?php echo $failed; ?></strong> <?php esc_html_e( 'failed', 'hl-core' ); ?></span>
            <?php endif; ?>
            <?php if ( $pending ) : ?>
                <span class="hl-wf-activity-stat" style="color:#D97706;"><strong><?php echo $pending; ?></strong> <?php esc_html_e( 'pending', 'hl-core' ); ?></span>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="hl-wf-activity"><?php esc_html_e( 'No activity yet.', 'hl-core' ); ?></div>
    <?php endif; ?>
    <?php else : ?>
        <div class="hl-wf-activity"><?php esc_html_e( 'No activity yet (draft).', 'hl-core' ); ?></div>
    <?php endif; ?>
</div>
```

- [ ] **Step 3: Commit**

```bash
git add assets/js/admin/email-workflow.js includes/admin/class-hl-admin-emails.php
git commit -m "feat(email): full summary panel with cascade labels, timing sentence, 24h activity"
```

---

### Task 8: Deploy + Smoke Test + Docs

**Files:**
- Modify: `STATUS.md` (check off M2 items)
- Modify: `README.md` (update "What's Implemented" section)

- [ ] **Step 1: Run existing CLI tests on test server**

```bash
ssh test-server
cd /path/to/wordpress
wp hl-core email-v2-test
```

Expected: All 65 assertions pass (no backend changes in M2 — pure presentation).

- [ ] **Step 2: Run smoke test**

```bash
wp hl-core smoke-test
```

Expected: 0 new failures.

- [ ] **Step 3: Browser verification with Playwright on test server**

Test these scenarios:

1. **New workflow:** Open "Add Workflow" — verify cascade dropdowns visible, only Cards 1-2 shown
2. **Category select:** Choose "Coaching Session" — Event dropdown populates with 6 options
3. **Hook event:** Select "Session Booked" — green "Instant trigger" hint appears, no timing panel
4. **Cron event:** Select "Scheduling Reminder" — blue "Scheduled trigger" hint appears, timing config panel slides in with offset fields
5. **Timing translation:** Change offset to 7 Days — translation reads "7 days before coaching session display window start"
6. **Summary sentence:** Verify summary panel shows "When: Coaching Session — Scheduling Reminder" with timing line
7. **Category switch:** Switch category to "Enrollment" — Event dropdown repopulates, hints disappear, timing panel hides
8. **Save + reload:** Fill all fields, save as Draft — verify form saves and reloads with cascade pre-selected correctly
9. **Edit existing M1 workflow:** Open a workflow created with M1 (flat trigger) — verify reverse mapping resolves to correct category + event
10. **24h activity:** Check existing active workflow shows sent/failed/pending counts
11. **Responsive:** Resize browser to < 1100px — cascade stacks vertically, summary is a bottom drawer

- [ ] **Step 4: Update STATUS.md**

Add M2 completion entries to the Workflow Builder UX Redesign section:

```markdown
### Workflow Builder UX Redesign — M2 (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-15-workflow-ux-redesign-design.md` | **Plan:** `docs/superpowers/plans/2026-04-16-workflow-ux-redesign-m2.md`
- [x] **Cascading trigger dropdowns** — Category + Event selects replace flat 22-option dropdown. Visible: Coaching Session, Enrollment, Course. Hidden: CV, RP, Assessment, Schedule (functional for reverse mapping).
- [x] **TRIGGER_MAP + injection** — `get_trigger_categories()` static registry. JS lookup table injected via `wp_add_inline_script`.
- [x] **Event type hint** — Green "Instant trigger" / blue "Scheduled trigger" bar below dropdowns.
- [x] **Timing config panel** — Offset + unit + direction + anchor label for cron events. Human-readable translation line.
- [x] **Reverse mapping** — Edit mode resolves trigger_key + status_filter + component_type back to Category + Event. Unrecognized triggers show yellow warning banner + read-only cascade.
- [x] **Full summary panel** — Plain-English sentence with cascade labels + timing, 24h activity display.
- [x] **Responsive** — Cascade stacks on narrow screens, summary as collapsible bottom drawer (CSS from M1).
- [ ] **Deployed to test** — Pending.
```

- [ ] **Step 5: Update README.md**

Add M2 details to the "What's Implemented" section under the Email System entry.

- [ ] **Step 6: Commit docs**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md + README.md with workflow UX M2"
```

- [ ] **Step 7: Push branch**

```bash
git push -u origin feature/workflow-ux-m1
```

(M2 is built on the same branch as M1 per the session prompt.)

---

## Per-Task Acceptance Criteria

| Task | Pass Criteria |
|---|---|
| 1 | `get_trigger_categories()` returns 7 categories (3 visible, 4 hidden). `window.hlTriggerMap` is available in JS console on workflow edit page. |
| 2 | Selecting a category populates the event dropdown. Selecting an event sets the hidden `trigger_key` input. Offset/component-type/status-filter rows show/hide correctly. |
| 3 | Card 2 shows two cascade dropdowns instead of the flat select. Form submission saves the correct `trigger_key` to DB. |
| 4 | Green hint for hook events, blue hint for cron events. Timing panel appears/disappears with slide animation. Translation updates on offset change. |
| 5 | Cascade side-by-side on desktop, stacked on narrow. Hints styled with correct colors. Timing panel has indigo title + gray background. Warning banner is yellow. |
| 6 | Edit an existing workflow — cascade pre-selects correct category + event. Edit a workflow with an unrecognized trigger — warning banner shows, dropdowns disabled. |
| 7 | Summary sentence shows "Coaching Session — Scheduling Reminder" instead of raw trigger key. Timing line appears for cron events. 24h activity shows sent/failed/pending counts. |
| 8 | `email-v2-test` 65/65 PASS. `smoke-test` 0 new failures. Playwright browser verification passes all 11 scenarios. STATUS.md + README.md updated. |
