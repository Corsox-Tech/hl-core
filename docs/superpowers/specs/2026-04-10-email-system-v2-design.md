# Email System v2 — UX Improvements Design Spec

**Date:** 2026-04-10
**Status:** Draft
**Scope:** 12 UX improvements across 3 implementation tracks
**Base:** Email System v1 spec (`docs/superpowers/specs/2026-04-09-email-system-design.md`)

---

## 1. Overview

Email System v1 shipped in April 2026 with 4 DB tables (`hl_email_template`, `hl_email_workflow`, `hl_email_queue`, `hl_email_rate_limit`), 6 block types in a visual builder, an automation engine with hook and cron triggers, and manual sends. v2 is 12 targeted improvements organized in 3 parallel implementation tracks. No new subsystems — this is polish, completeness, and correctness work on the existing v1 foundation.

### Items by Track

**Track 1 — Admin Workflow UX** (4 items):

| # | Item | One-Line |
|---|------|----------|
| 1.1 | Condition Builder UI | Replace JSON textarea with visual row builder (field + operator + value dropdowns) |
| 1.2 | Recipient Picker UI | Replace JSON textarea with token checkboxes, role pills, static email pills |
| 1.3 | Workflow Row Actions | Add Duplicate, Activate/Pause toggle, Delete to workflow list |
| 1.4 | Template Row Actions | Add Duplicate, Archive/Restore to template list |

**Track 2 — Email Builder Enhancements** (4 items):

| # | Item | One-Line |
|---|------|----------|
| 2.1 | Columns Block Editing | Replace "edit in JSON" placeholder with real nested block editing |
| 2.2 | Undo / Redo | Snapshot-based undo/redo stack with Ctrl+Z/Y keyboard shortcuts |
| 2.3 | Preview Modal | Fullscreen modal with device toggles and enrollment search |
| 2.4 | Text Alignment + Font Size | Block-level alignment buttons and font size dropdown on text blocks |

**Track 3 — Backend Fixes** (4 items):

| # | Item | One-Line |
|---|------|----------|
| 3.1 | Component Window Columns | `available_from`/`available_to` DATE columns on `hl_component` |
| 3.2 | Cron Trigger Stubs | Implement 5 of 6 stub triggers (cv_window, cv_overdue, rp_window, coaching_window, coaching_pre_end) |
| 3.3 | Draft Cleanup | Daily cron deletes `hl_email_draft_*` wp_options older than 30 days |
| 3.4 | LIKE on Roles Fix | Replace `LIKE '%role%'` with `FIND_IN_SET()` in recipient resolver |

### Goals

- **Admin self-service** — Create and edit workflows without writing or reading JSON.
- **Complete builder feature set** — Column editing, undo/redo, fullscreen preview, text formatting.
- **Reliable cron triggers** — 5 stub triggers become real queries using component window dates.
- **Data correctness** — `FIND_IN_SET` prevents false-positive role matches; draft cleanup prevents wp_options bloat.

### Non-Goals

- New email types or trigger hooks beyond the existing 25 scenarios.
- Template marketplace or template sharing.
- A/B testing or send-time optimization.
- Analytics dashboard (open rates, click tracking).
- OR-logic conditions (v1 is AND-only; this remains unchanged).

---

## 2. Architecture Impact

**No new tables.** No new service classes. No new admin pages or tabs.

| Change | Detail |
|--------|--------|
| Schema revision 34 → 35 | Two `ALTER TABLE` columns on `hl_component`: `available_from DATE NULL`, `available_to DATE NULL` |
| New backend token | `assigned_mentor` added to `HL_Email_Recipient_Resolver` |
| Renamed token | `cc_teacher` → `observed_teacher` (backward-compat alias retained) |
| New JS file | `assets/js/admin/email-workflow.js` (condition builder + recipient picker) |
| Extended JS file | `assets/js/admin/email-builder.js` (columns, undo/redo, preview modal, text formatting) |
| Extended CSS file | `assets/css/admin.css` (new v2 section) |

Everything else is UI-layer PHP rendering, JS interactions, and CSS additions.

---

## 3. Shared Conventions

### 3.1 Hidden JSON Sync Pattern

Both the condition builder (1.1) and recipient picker (1.2) use the same pattern:

1. A hidden `<textarea>` holds the JSON string (same format as v1).
2. The visual UI reads from this textarea on page load to populate its state.
3. On every user interaction, JS serializes the UI state back into the hidden textarea.
4. The existing form POST submission sends the textarea value — no new AJAX endpoints, no changes to `handle_workflow_save()`.

On page load, if existing JSON is malformed, show an error banner with the raw textarea exposed for manual fix. A "Try Again" button re-attempts parse.

### 3.2 Pill Tag Component

Reusable pill tag input shared across three contexts:

| Context | CSS Class | Color | Stored As |
|---------|-----------|-------|-----------|
| Condition "in list" values | `.hl-pill-enum` | Blue (`#DBEAFE` bg, `#1E40AF` text) | Comma-separated in JSON `value` |
| Recipient role tokens | `.hl-pill-role` | Green (`#D1FAE5` bg, `#065F46` text) | `role:X` in recipients array |
| Recipient static emails | `.hl-pill-email` | Yellow (`#FEF3C7` bg, `#92400E` text) | `static:email` in recipients array |

**Behavior:** Enter to add pill, × to remove, Backspace on empty input removes last pill. Email pills validated with basic regex on Enter; invalid input gets red border for 2 seconds.

Base class `.hl-pill` (inline-flex, border-radius 12px, padding 2px 10px, font-size 12px). Remove button `.hl-pill-remove` (cursor pointer, opacity 0.6, hover 1.0).

### 3.3 CSS Conventions

All new CSS in existing `assets/css/admin.css` under a v2 section comment.

| Prefix | Scope |
|--------|-------|
| `.hl-condition-*` | Condition builder rows, field/op/value controls |
| `.hl-token-*` | Recipient token cards |
| `.hl-recipient-*` | Recipient picker sections (primary, CC) |
| `.hl-pill-*` | Pill tag component (shared) |
| `.hl-eb-*` | Builder enhancements (columns, undo, preview modal, alignment) |
| `.hl-action-*` | Row action links (edit, duplicate, toggle, delete, archive) |

Use existing `--eb-*` CSS custom properties. No new CSS files. No `!important`.

### 3.4 JS Conventions

- jQuery-based. Event delegation on parent containers.
- **New file:** `assets/js/admin/email-workflow.js` — conditions + recipients. Enqueued only on workflow edit/new pages.
- **Existing file:** `assets/js/admin/email-builder.js` — columns, undo/redo, preview modal, text formatting.
- Configuration via `wp_localize_script`. No build step.

### 3.5 PHP Conventions

Static registry methods on `HL_Admin_Emails`:
- `get_condition_fields()` — field definitions (label, group, type, options)
- `get_condition_operators()` — operator sets per field type
- `get_recipient_tokens()` — token definitions (label, description, trigger visibility)

All localized to JS via `wp_localize_script`. Nonce verification + `manage_hl_core` capability check on all handlers. `HL_Audit_Service::log()` on all state changes.

---

## Track 1: Admin Workflow UX

### 1.1 Workflow Condition Builder UI

**Current state:** Raw JSON textarea: `[{"field":"cycle.cycle_type","op":"eq","value":"program"}]`.

#### Field Registry

`HL_Admin_Emails::get_condition_fields()` returns:

```php
[
    // Cycle group
    'cycle.cycle_type'       => ['label' => 'Cycle Type',       'group' => 'Cycle',      'type' => 'enum',    'options' => ['program' => 'Program', 'course' => 'Course']],
    'cycle.status'           => ['label' => 'Cycle Status',     'group' => 'Cycle',      'type' => 'enum',    'options' => ['active' => 'Active', 'archived' => 'Archived']],
    'cycle.is_control_group' => ['label' => 'Is Control Group', 'group' => 'Cycle',      'type' => 'boolean', 'options' => []],
    // Enrollment group
    'enrollment.status'      => ['label' => 'Enrollment Status','group' => 'Enrollment', 'type' => 'enum',    'options' => ['active'=>'Active','warning'=>'Warning','withdrawn'=>'Withdrawn','completed'=>'Completed','expired'=>'Expired']],
    'enrollment.roles'       => ['label' => 'Enrollment Roles', 'group' => 'Enrollment', 'type' => 'text',    'options' => []],
    // User group
    'user.account_activated' => ['label' => 'Account Activated','group' => 'User',       'type' => 'boolean', 'options' => []],
]
```

Fields mirror what `HL_Email_Condition_Evaluator::evaluate()` checks and `HL_Email_Automation_Service::build_context()` populates.

#### Operator Adaptation Per Field Type

| Type | Operators |
|------|-----------|
| `enum` | equals, not equals, in list, not in list, is empty, is not empty |
| `boolean` | equals only (Yes/No toggle, not text input) |
| `text` | equals, not equals, in list, not in list, is empty, is not empty |
| `numeric` | equals, not equals, greater than, less than, is empty, is not empty |

#### Smart Value Input

| Scenario | Renders |
|----------|---------|
| Enum + equals/not_equals | `<select>` from field's `options` |
| Boolean (always equals) | Yes/No radio toggle (`.hl-toggle-pair`) |
| Operator `in`/`not_in` + enum | Pill tag input with dropdown from `options` |
| Operator `in`/`not_in` + text | Pill tag input (free-text entry) |
| Operator `is_empty`/`not_empty` | Value column hidden (`display:none`, flex gap collapses). JSON `value` set to `null`. |
| Text + equals/not_equals | Plain `<input type="text">` |
| Numeric | Plain `<input type="number">` |

#### Row Layout

Each condition row is a flex container (`.hl-condition-row`): field `<select>` (flex:2, grouped by optgroup) + operator `<select>` (flex:1) + value input (flex:2, type varies) + remove button (×, red). Gray background (#F9FAFB), 1px border, 6px radius.

"+ Add Condition" button below rows (dashed border, blue text, full width). Badge: "All conditions must match (AND)".

#### JS File

`assets/js/admin/email-workflow.js`. Key functions:
- `hlAddConditionRow()` — clones template row, appends, triggers field change
- `hlRemoveConditionRow(e)` — removes row, re-serializes
- `hlOnFieldChange(e)` — swaps operator list + value input based on field type
- `hlOnOperatorChange(e)` — shows/hides value, swaps to pill input for in/not_in
- `hlSerializeConditions()` — iterates rows, builds JSON, writes to hidden textarea
- `hlInitPillInput($container)` — initializes pill tag behavior with Enter/×/dropdown

All handlers attached via event delegation on `.hl-condition-builder`.

---

### 1.2 Workflow Recipient Picker UI

**Current state:** Raw JSON textarea: `{"primary":["triggering_user","assigned_coach"],"cc":["school_director"]}`.

#### Token Registry

`HL_Admin_Emails::get_recipient_tokens()` returns:

```php
[
    'triggering_user' => [
        'label'       => 'Triggering User',
        'description' => 'The user who caused the event',
        'triggers'    => '*',  // all triggers
    ],
    'assigned_coach' => [
        'label'       => "User's Coach",
        'description' => 'Coach assigned to this user via hl_coach_assignment',
        'triggers'    => ['hl_enrollment_created', 'hl_pathway_assigned', 'hl_coaching_session_created', ...],
    ],
    'assigned_mentor' => [
        'label'       => "User's Mentor",
        'description' => 'Mentor of the triggering user (via team membership)',
        'triggers'    => ['hl_classroom_visit_submitted', 'hl_teacher_assessment_submitted', ...],
    ],
    'school_director' => [
        'label'       => 'School Director',
        'description' => "School leader for the user's school",
        'triggers'    => '*',
    ],
    'observed_teacher' => [
        'label'       => 'Observed Teacher',
        'description' => 'Teacher being observed in a classroom visit',
        'triggers'    => ['hl_classroom_visit_submitted'],
    ],
]
```

#### New Backend Token: `assigned_mentor`

Added to `HL_Email_Recipient_Resolver::resolve()`. Resolution query:

```sql
SELECT mentor_tm.user_id
FROM {prefix}hl_team_member AS user_tm
INNER JOIN {prefix}hl_team_member AS mentor_tm ON user_tm.team_id = mentor_tm.team_id
INNER JOIN {prefix}hl_team AS t ON t.id = user_tm.team_id
WHERE user_tm.user_id = %d
  AND FIND_IN_SET('mentor', mentor_tm.roles) > 0
  AND t.cycle_id = %d
LIMIT 1
```

Returns NULL if no mentor found. The existing resolver loop skips NULL results and continues to the next recipient in the fan-out — one missing mentor does not block other recipients. Skipped tokens logged to `HL_Audit_Service`.

#### Renamed Token: `cc_teacher` → `observed_teacher`

Backend switch statement handles both keys (new key first, legacy alias):
```php
case 'observed_teacher':
case 'cc_teacher':  // legacy backward compat
    // existing resolution logic
    break;
```

Admin UI shows only `observed_teacher`. Existing workflows with `cc_teacher` JSON continue to resolve.

#### Trigger-Dependent Visibility

When the trigger `<select>` changes, JS reads `hlRecipientTokens` and shows/hides checkboxes:
- `triggers === '*'` → always visible
- `triggers` array includes selected trigger → visible
- Otherwise → hidden + unchecked (prevents stale selections in JSON)

#### UI Layout — Primary Section

1. **Token checkboxes** — 2-column grid (`.hl-token-grid`). Each is a clickable card (`.hl-token-card`). Checked: blue border + light blue bg (`.hl-token-checked`). Each shows label (bold) + one-line description.
2. **By Role** — `.hl-pill-input` container. Green pills (`.hl-pill-role`). Free-text + Enter.
3. **Static Emails** — `.hl-pill-input` container. Yellow pills (`.hl-pill-email`). Email regex validation.

#### UI Layout — CC Section

Same structure but compact: single-column token list, no descriptions. Tokens checked in Primary are disabled in CC (`.hl-token-disabled`, opacity 0.4, pointer-events none, tooltip: "Already selected as Primary recipient").

#### Hidden JSON Sync

Hidden `<textarea name="recipients">`. `hlSerializeRecipients()` reads checked tokens + pills from both sections, builds `{primary:[], cc:[]}`, writes JSON. Called on every checkbox/pill change.

---

### 1.3 Workflow Row Actions

**Current state:** Only "Edit" link. **New:** Edit | Duplicate | Activate/Pause | Delete.

#### Duplicate
- GET with nonce → server copies all fields, name + " (Copy)", status = draft → redirect to edit form.
- Nonce: `hl_workflow_duplicate_{id}`.

#### Activate / Pause (Inline AJAX)
- Endpoint: `wp_ajax_hl_workflow_toggle_status`.
- Logic: draft/paused → active, active → paused.
- JS updates badge in-place (no reload). Existing badge CSS classes apply.
- Audit logged.

#### Delete
- JS `confirm()` dialog.
- Server guard: `SELECT COUNT(*) FROM hl_email_queue WHERE workflow_id = %d AND status = 'sent'`. If > 0 → error notice "Cannot delete — X emails sent. Pause instead." If 0 → hard DELETE workflow + unsent queue entries.
- Audit logged.

#### Action Link Colors
Edit = blue (#2C7BE5), Duplicate = gray (#6B7280), Activate = green (#059669), Pause = amber (#D97706), Delete = red (#DC2626). Pipe separators (#D1D5DB).

---

### 1.4 Template Row Actions

**Current state:** Only "Edit" link. **New:** Edit | Duplicate | Archive/Restore.

#### Duplicate
- GET with nonce → copies blocks_json, subject, name + " (Copy)", generates unique template_key (`_copy`, `_copy2`, ...), status = draft → redirect to builder.

#### Archive / Restore
- Archive: sets status = `archived`. Row dims (opacity 0.6). Link flips to "Restore".
- Restore: sets status = `draft`.
- Archived templates excluded from workflow template dropdown (`WHERE status != 'archived'`).
- Currently-assigned archived template shows in dropdown with "(archived)" suffix.
- Audit logged.

#### No Hard Delete
Templates are referenced by `hl_email_queue.template_id` for audit history. Archive is the only removal mechanism.

---

## Track 2: Email Builder Enhancements

### 2.1 Columns Block Editing

**Current state:** Two dashed boxes with "(edit in JSON)". Data: `{ type: "columns", split: "50/50", left: [], right: [] }`.

#### Split Options (expanded)

| Label | Left Width | Right Width |
|-------|-----------|-------------|
| 50/50 | 50% | 50% |
| 60/40 | 60% | 40% |
| 40/60 | 40% | 60% |
| 33/67 | 33% | 67% |
| 67/33 | 67% | 33% |

Dropdown in the column block header bar.

#### Nesting Rules

Allowed nested types: **Text, Image, Button, Divider, Spacer**. No nested Columns (one level deep only — email clients can't render nested tables reliably). The mini palette simply doesn't include Columns.

#### Column Block Header Bar

`[⠿ drag] Columns [split ▾] [⧉ dup] [× del]` — same pattern as other blocks with the split selector added.

#### Per-Column Layout

Each column container has: label ("Left Column" / "Right Column"), nested block cards with `.hl-eb-block-nested` class (compact: smaller padding, smaller type labels, smaller controls), and "+ Add Block" button at bottom.

#### Per-Column Sortable.js

Each `.hl-eb-col-blocks` gets its own Sortable instance. `pull: false, put: false` prevents cross-column drag. Users delete + re-add to move blocks between columns.

#### JS Architecture

| Function | Purpose |
|----------|---------|
| `renderColumnsBlock(block, index)` | Full columns block HTML + two Sortable instances |
| `renderNestedBlock(block, colBlocks, colIndex, parentIndex, side)` | Single nested block card, delegates to existing render functions with `nested: true` |
| `addNestedBlock(parentIndex, side, type)` | Push default block to `blocks[parentIndex][side]`, re-render, pushUndo, markDirty |
| `deleteNestedBlock(parentIndex, side, colIndex)` | Splice from array, re-render, pushUndo, markDirty |
| `duplicateNestedBlock(parentIndex, side, colIndex)` | Deep clone + insert after, re-render, pushUndo, markDirty |

Nested block property handlers reference `blocks[parentIndex][side][colIndex]` via `data-parent`, `data-side`, `data-col-index` attributes.

#### Mini Palette Popup

Clicking "+ Add Block" shows a floating popup below the button: 5 horizontal buttons (Text, Image, Button, Divider, Spacer). Closes on click outside. Only one popup open at a time.

#### PHP Renderer Update

`HL_Email_Block_Renderer::render_columns()` — add `get_column_widths($split)` method returning width percentages for all 5 split options. Fallback to 50/50. Nested blocks already render via recursive `render_block()` call.

#### JSON Format Unchanged

`{ type: "columns", split: "60/40", left: [{type:"text",...}], right: [{type:"button",...}] }`

---

### 2.2 Undo / Redo

#### Snapshot Strategy

Full `blocks[]` deep clone (`JSON.parse(JSON.stringify(blocks))`) on every mutation:
- Block add, delete, duplicate, reorder (top-level and nested)
- Block property changes (image src, button label/url/colors, spacer height, columns split)
- Text blocks: snapshot on **blur or 2s idle**, not per-keystroke (debounce timer resets on each `input` event)

Ring buffer: max 50 snapshots. Oldest dropped when full.

#### Stack

```javascript
var undoStack = [];  // pre-mutation snapshots
var redoStack = [];
var MAX_UNDO = 50;

function pushUndo() {
    undoStack.push(JSON.parse(JSON.stringify(blocks)));
    if (undoStack.length > MAX_UNDO) undoStack.shift();
    redoStack = [];
    updateUndoButtons();
}

function undo() {
    if (!undoStack.length) return;
    redoStack.push(JSON.parse(JSON.stringify(blocks)));
    blocks = undoStack.pop();
    renderAllBlocks();
    markDirty();
    updateUndoButtons();
}

function redo() {
    if (!redoStack.length) return;
    undoStack.push(JSON.parse(JSON.stringify(blocks)));
    blocks = redoStack.pop();
    renderAllBlocks();
    markDirty();
    updateUndoButtons();
}
```

#### UI

Two buttons in toolbar, left of Save: `[↩ undo] [↪ redo]` as a button pair (`.hl-eb-undo-group`). Disabled (gray, cursor not-allowed) when stack is empty.

#### Keyboard Shortcuts

Ctrl+Z / Cmd+Z for undo, Ctrl+Y / Cmd+Y / Ctrl+Shift+Z for redo. Guard clause: do NOT intercept when focus is in contenteditable, input, textarea, or select (browser's native undo handles those).

#### Persistence

None. In-memory JS only. Autosave drafts handle persistence independently.

---

### 2.3 Preview Modal

**Current state:** Small iframe (600x400) squeezed into 300px right sidebar.

#### Toolbar Addition

"Preview" button after Save (outlined style, distinct from primary Save). Icon: dashicons-visibility.

#### Modal Structure

Fixed overlay (z-index 100000, above WP admin). Dark header (#1A2B47, 56px height) + full-height iframe body (#111827 background).

**Header left:** Template name (white, bold) + dot separator + enrollment context (gray).
**Header right:** Device toggles (Desktop/Mobile/Dark) + enrollment search input (dark-themed) + close button (×).

#### Device Toggles

| Toggle | Iframe Width | Extra |
|--------|-------------|-------|
| Desktop | 600px | Default |
| Mobile | 375px | — |
| Dark | 600px | `?dark=1` param → renderer wraps HTML in dark container |

Only one active at a time. Button group with shared border.

#### Enrollment Search

Same `hl_email_preview_search` AJAX endpoint as sidebar, dark-themed. 300ms debounce. Results dropdown below input.

#### Open/Close

- **Open:** Click "Preview" → set template name, inherit sidebar enrollment selection, reset to Desktop, show modal (fade in 200ms), load preview. Body overflow hidden.
- **Close:** × button, Escape key, or backdrop click → fade out 200ms, clear iframe, restore body overflow.

#### Preview Refresh

Uses `fetch` + Blob URL to POST current blocks JSON to `hl_email_preview_render` and load response into iframe (avoids GET URL length limits for large block payloads).

#### Backend Change

One addition to `ajax_preview_render()`: if `$_POST['dark'] === '1'`, wrap HTML in `<div style="background-color:#1a1a2e; color:#e0e0e0; padding:20px;">`.

---

### 2.4 Text Block Alignment + Font Size

**Current state:** Mini-toolbar has Bold, Italic, Link, Merge Tag dropdown.

#### Expanded Toolbar

`[B] [I] [🔗] | [⇤] [⇔] [⇥] | [Font Size ▾] | [Merge Tags ▾]`

Separators: 1px vertical divider (#D1D5DB, 20px tall, 4px horizontal margin).

#### Alignment

Three toggle buttons (radio group behavior). Active: white bg + subtle shadow. Uses WordPress Dashicons (`dashicons-editor-alignleft`, `-aligncenter`, `-alignright`).

Stored as `text_align` property: `"left"` (default, omitted from JSON), `"center"`, `"right"`.

Applied via CSS `text-align` on contenteditable container. **NOT via `document.execCommand`** — execCommand alignment wraps content in unpredictable tags that render inconsistently in email clients.

#### Font Size

`<select>` with 6 presets: 12px, 14px, 16px (default), 18px, 20px, 24px.

Stored as `font_size` property: `16` (default, omitted from JSON), or integer.

#### Block-Level, Not Selection-Level

Both alignment and font size apply to the entire text block. Email HTML renders as `<td>` cells — per-word sizing requires inline `<span>` tags that render inconsistently across Outlook/Gmail/Apple Mail.

#### PHP Renderer Update

`HL_Email_Block_Renderer::render_text()`:

```php
$align = in_array($block['text_align'] ?? 'left', ['left','center','right'], true)
       ? ($block['text_align'] ?? 'left') : 'left';
$size  = isset($block['font_size']) ? max(10, min(48, (int)$block['font_size'])) : 16;
// Applied as inline style on <td>: text-align:{$align}; font-size:{$size}px;
```

#### Defaults Omitted From JSON

Existing templates unchanged. Only non-default values stored:

```json
{ "type": "text", "content": "<p>Hello</p>", "text_align": "center", "font_size": 20 }
```

vs. default (no extra properties):

```json
{ "type": "text", "content": "<p>Hello</p>" }
```

---

## Track 3: Backend Fixes

### 3.1 Component Submission Window Columns

#### Schema Change — Revision 34 → 35

```sql
ALTER TABLE {prefix}hl_component
  ADD COLUMN available_from DATE DEFAULT NULL AFTER sort_order,
  ADD COLUMN available_to DATE DEFAULT NULL AFTER available_from;
```

Migration in `HL_Installer::migrate()` under `case 35:`.

#### Admin UI

Two `<input type="date">` fields in the component form (Pathway Editor), after existing fields. Grouped under label "Submission Window" with sub-labels "Opens" / "Closes".

Save handling: `sanitize_text_field()` + regex validate `YYYY-MM-DD`. Empty → NULL. If both set and from > to, swap silently.

Help text: "Optional. When set, cron email triggers can reference this window for reminders and overdue notices."

---

### 3.2 Cron Trigger Stub Implementations

Five of six stubs in `get_cron_trigger_users()` replaced with real queries. Return format per trigger:

```php
[['user_id' => int, 'cycle_id' => int, 'enrollment_id' => int, 'entity_id' => int], ...]
```

#### `cron:cv_window_7d` — Classroom Visit Window Opens in 7 Days

```sql
SELECT DISTINCT en.user_id, en.cycle_id, en.id AS enrollment_id, c.id AS entity_id
FROM {prefix}hl_component c
INNER JOIN {prefix}hl_pathway p ON p.id = c.pathway_id
INNER JOIN {prefix}hl_pathway_assignment pa ON pa.pathway_id = p.id
INNER JOIN {prefix}hl_enrollment en ON en.id = pa.enrollment_id AND en.status = 'active'
WHERE c.component_type = 'classroom_visit'
  AND c.available_from IS NOT NULL
  AND c.available_from BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  AND NOT EXISTS (
      SELECT 1 FROM {prefix}hl_classroom_visit_submission cvs
      WHERE cvs.component_id = c.id AND cvs.enrollment_id = en.id
  )
```

PHP post-filter for `requires_classroom` and `eligible_roles` (cron volumes are small).

#### `cron:cv_overdue_1d` — Classroom Visit Overdue by 1 Day

Same join chain. `WHERE c.available_to = DATE_SUB(CURDATE(), INTERVAL 1 DAY)`. LEFT JOIN `hl_classroom_visit_submission` with `cvs.id IS NULL`. Fires exactly once (date match = single calendar day).

#### `cron:rp_window_7d` — RP Session Window Opens in 7 Days

Same as cv_window_7d with `component_type = 'reflective_practice_session'` and exclusion against `hl_rp_session_submission`.

#### `cron:coaching_window_7d` — Coaching Window Opens in 7 Days

Same structure with `component_type = 'coaching_session'`. Exclusion against `hl_coaching_session` (entity table, not submission — absence means not yet scheduled).

#### `cron:coaching_pre_end` — Cycle Ending, Incomplete Coaching

```sql
SELECT DISTINCT en.user_id, en.cycle_id, en.id AS enrollment_id, c.id AS entity_id
FROM {prefix}hl_cycle cy
INNER JOIN {prefix}hl_enrollment en ON en.cycle_id = cy.id AND en.status = 'active'
INNER JOIN {prefix}hl_pathway_assignment pa ON pa.enrollment_id = en.id
INNER JOIN {prefix}hl_pathway p ON p.id = pa.pathway_id
INNER JOIN {prefix}hl_component c ON c.pathway_id = p.id AND c.component_type = 'coaching_session'
LEFT JOIN {prefix}hl_coaching_session cs
    ON cs.component_id = c.id AND cs.enrollment_id = en.id AND cs.status = 'completed'
WHERE cy.status = 'active'
  AND cy.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
  AND cs.id IS NULL
```

Uses cycle `end_date`, NOT component window dates. Catches both never-scheduled and scheduled-but-incomplete.

#### `cron:client_success` — REMAINS STUB

```php
// Deferred: requires business criteria from Yuyan Huang.
// Placeholder returns empty — no emails fire for this trigger.
return [];
```

#### Dedup Safety

Existing mechanism handles all cron triggers: `md5(trigger_key + workflow_id + user_id + entity_id + cycle_id)`. No changes needed.

---

### 3.3 Draft Cleanup

#### Step 1: Add Timestamps to Autosave

In `HL_Admin_Email_Builder::ajax_autosave()`, inject into draft JSON:
- `created_at` — set once on first save (ISO 8601 UTC)
- `updated_at` — refreshed every autosave

Cleanup checks `updated_at` — actively-edited drafts won't be purged.

#### Step 2: Daily Cleanup

New `cleanup_stale_drafts()` private method on `HL_Email_Automation_Service`, called from `run_daily_checks()`:

1. Query all `wp_options` WHERE `option_name LIKE 'hl_email_draft_%'`
2. For each, JSON decode and check `updated_at` (fallback: `created_at`, fallback: `'2000-01-01'`)
3. If older than 30 days, collect `option_id`
4. Batch DELETE with `WHERE option_id IN (...)`
5. Audit log: `email_draft_cleanup` with deleted count

**Legacy drafts (no timestamp):** Fall back to `'2000-01-01'` → cleaned up on first run. Safe — any pre-v2 draft is already abandoned.

---

### 3.4 LIKE on Roles Column Fix

#### Problem

`LIKE '%role%'` in `HL_Email_Recipient_Resolver` can false-match substrings (e.g., `school_leader` matches `leader`).

#### Fix

Replace with `FIND_IN_SET()` in two methods:

**`resolve_school_director()`:**
```sql
-- Before: AND en.roles LIKE '%school_leader%'
-- After:
AND FIND_IN_SET('school_leader', en.roles) > 0
```

**`resolve_role()`:**
```sql
-- Before: AND en.roles LIKE '%{role}%'
-- After:
AND FIND_IN_SET(%s, en.roles) > 0
```

With `$role` passed via `$wpdb->prepare()`.

#### Why FIND_IN_SET

MySQL built-in for comma-separated lists. Exact match: `FIND_IN_SET('leader', 'school_leader,teacher')` returns 0 (no match). Works with `$wpdb->prepare()`. Same index behavior as LIKE (both require scan of column value).

#### Assumption

Roles stored without spaces (`teacher,mentor`, not `teacher, mentor`). Verified in import handler and admin form save logic. Defensive code comment added at both call sites.

---

## Implementation Tracks — Summary

| Track | Items | Dependencies |
|-------|-------|-------------|
| Track 1: Admin Workflow UX | 1.1, 1.2, 1.3, 1.4 | Independent — parallel with Track 2 |
| Track 2: Builder Enhancements | 2.1, 2.2, 2.3, 2.4 | Independent — parallel with Track 1 |
| Track 3: Backend Fixes | 3.1, 3.2, 3.3, 3.4 | **3.1 blocks 3.2** (cron stubs need window columns). 3.3 and 3.4 independent of everything. |

**Build order within Track 2:** 2.4 (smallest) → 2.2 (undo, needed before columns is safe to use) → 2.1 (largest, benefits from undo) → 2.3 (independent).

---

## Testing Strategy

### UI Features (Tracks 1 and 2)

Manual browser testing on test server:
- Condition builder: add/remove rows, field/operator/value changes, JSON sync, save + reload
- Recipient picker: token checks, role/email pills, trigger-dependent visibility, JSON sync
- Row actions: duplicate, toggle status (AJAX), delete with guard, archive/restore
- Columns: add nested blocks, reorder, delete, split changes, save + reload JSON round-trip
- Undo/redo: add 3 blocks, delete, undo (reappears), redo, text undo, verify 50-cap
- Preview modal: open, device toggles, enrollment search, close via Escape/backdrop/×
- Text formatting: alignment, font size, save + reload, send test email (verify rendered HTML)
- Cross-browser: Chrome, Firefox, Safari

### Backend Features (Track 3)

CLI testing on test server:
- Schema: verify `available_from`/`available_to` columns after rev 35 migration
- Cron stubs: `wp cron event run hl_email_cron_daily` with seeded data, verify queue rows
- FIND_IN_SET: insert enrollments with `teacher`, `school_leader`, `leader` roles, verify no false matches
- Tokens: workflows with `assigned_mentor` and `observed_teacher`, verify resolution; legacy `cc_teacher` alias
- Draft cleanup: create stale draft in wp_options, run daily cron, verify deleted

### Regression

- Existing 37-assertion CLI test suite must pass
- Active workflows on test server still trigger correctly
- Builder save/load round-trip on existing templates (no block format changes — only new optional properties)

---

## Appendix A — Review Consensus & Refinements

This appendix captures the output of a multi-phase review (2 preliminary reviewers + 3 senior experts) with cross-validation. All items below are **accepted by consensus** and must be addressed during implementation.

### A.1 Critical Refinements (must implement)

| # | Issue | Fix |
|---|-------|-----|
| **A.1.1** | Sortable.js nested handle collision — top-level Sortable can intercept nested block drags | Use distinct handle class `.hl-eb-drag-handle-nested`, distinct `group` name per column instance (`col-{parentIndex}-{side}`), `filter: '.hl-eb-block-nested'` on top-level Sortable. Visually distinguish nested handles (smaller, different hover). Track all Sortable instances in a registry and call `.destroy()` before every `renderAllBlocks()`. |
| **A.1.2** | Pill `<input>` inside workflow `<form>`: Enter submits the form | Unconditional `e.preventDefault()` on Enter inside `.hl-pill-input input`. Applies to all 3 pill contexts (condition "in list" values, recipient role pills, recipient static email pills). |
| **A.1.3** | Preview modal iframe XSS surface | Add `sandbox="allow-same-origin allow-popups"` on `<iframe>` (no `allow-scripts`). Confirm no inline JS expected in email HTML. Verify dark-backdrop toggle still works under sandbox. |
| **A.1.4** | Unicode subject line: `wp_mail()` does NOT auto-encode non-ASCII headers | In the queue processor, wrap subject with `mb_encode_mimeheader($subject, 'UTF-8', 'B')` before passing to `wp_mail()`. Required for ES-MX / PT-BR translation rollout. Also apply to admin table rendering of workflow names. |
| **A.1.5** | `CURDATE()` uses server TZ, not site TZ — cron triggers fire on wrong day | Replace all `CURDATE()` / `DATE_ADD(CURDATE(), ...)` in Section 3.2 cron queries with PHP-computed dates via `current_time('Y-m-d')` (respects WP timezone). Pass as bound `%s` parameters through `$wpdb->prepare()`. |
| **A.1.6** | Missed cron runs create permanent reminder gaps (exact-date matching) | Change exact-date matches in Section 3.2 to **range matches**: `available_from BETWEEN %s AND %s` with (today, today+7). Dedup contract: dedup token `md5(trigger + workflow + user + entity + cycle)` has **no date component** → fires exactly once per window, tolerates missed cron runs. Document this explicitly in `HL_Email_Automation_Service`. Track `last_cron_run_at` wp_option and warn if gap > 36h. |
| **A.1.7** | `enrollment.roles` in `HL_Email_Condition_Evaluator` currently uses string equality — silently mismatches recipient resolver (FIND_IN_SET) | Create shared `HL_Roles::has_role($csv, $role)` helper. Route both `HL_Email_Condition_Evaluator::evaluate()` AND `HL_Email_Recipient_Resolver` through it. Condition builder UI should render `enrollment.roles` as `enum` with known role options (teacher, mentor, coach, school_leader, district_leader) and document FIND_IN_SET matching semantics. Operator label "in list" → **"matches any of"** for non-technical clarity. |

### A.2 Major Refinements

**Track 2 (Builder):**
- **A.2.1** `renderAllBlocks()` destroys contenteditable focus + cursor position. Track Sortable instances in registry, destroy before re-render. Preserve canvas scrollTop. On undo/redo, flush any pending text snapshot first (also handle Safari `beforeinput` with `inputType === 'historyUndo'`).
- **A.2.2** Blob URL memory leak + flash in preview iframe. Use `iframe.srcdoc = html` instead of `URL.createObjectURL()`.
- **A.2.3** Rename "Dark" toggle to **"Dark Backdrop"** (honest label — it's cosmetic, not real dark-mode simulation). Optionally inject `<meta name="color-scheme" content="dark">` + `prefers-color-scheme` CSS to test actual dark rendering.
- **A.2.4** Autosave + undo race on reload: autosave debounced 5s after an undo action to give redo window.
- **A.2.5** "Move to other column" button on nested blocks (arrow icon) — preserves state, avoids Sortable cross-container complexity. New JS function `moveNestedBlock(parentIndex, fromSide, toSide, colIndex)`.

**Track 1 (Admin UX):**
- **A.2.6** Preview modal focus trap + ARIA: `role="dialog"`, `aria-modal="true"`, trap Tab/Shift+Tab within modal controls, restore focus to "Preview" button on close. Use `inert` attribute on body siblings (can't trap focus across frame boundary).
- **A.2.7** Condition builder ARIA: wrap each row in `<div role="group" aria-label="Condition N">`, icon-only `×` needs `aria-label="Remove condition N"`.
- **A.2.8** Pill input keyboard nav: `role="list"`/`listitem`, focusable pills with tabindex, arrow keys to navigate, Delete/Backspace to remove focused pill.
- **A.2.9** Invalid email SR feedback: `aria-live="polite"` region announcing "Invalid email address" + inline text (not color-only).
- **A.2.10** Trigger-dependent recipient visibility: **keep incompatible tokens dimmed** (don't delete from JSON). Server-side resolver silently skips incompatible tokens at send time (already the behavior). Document this in the recipient picker help text.
- **A.2.11** Text alignment default "left" button must show active state when `text_align` is absent from JSON.
- **A.2.12** Unified `HL_Admin_Emails::generate_copy_name($table, $source_name)` helper for both workflow and template duplicate-name generation. Cap retries at 10, fall back to `_copy_` + `wp_generate_uuid4()` suffix on final retry.
- **A.2.13** Workflow + template duplicate actions: Convert from GET to **POST** (admin-post.php handler). Prevents browser prefetch / link-scanner CSRF exploits via referer leaks. Per-row nonce format: `hl_workflow_duplicate_{id}`, `hl_template_duplicate_{id}`.
- **A.2.14** Recipient picker recipient count hint: async debounced query "~N recipients for this trigger" below the picker. Reduces mis-sends.

**Track 3 (Backend):**
- **A.2.15** `FIND_IN_SET` hazards: reject comma in role input (`strpos($role, ',') !== false` → return [] + audit log), reject empty role early (avoids trailing-comma false positives). Add `HL_Roles::sanitize_roles($csv)` helper — strip whitespace, lowercase, dedup. Route all enrollment write paths through it. One-time data scrub migration normalizes existing rows.
- **A.2.16** Draft cleanup: use `$wpdb->esc_like('hl_email_draft_')`, target `$wpdb->options` (site-scoped, not `base_prefix`). Corrupt JSON → **skip + audit log** (never delete — dangerous to assume "2000-01-01" fallback for malformed-but-recent). Circuit breaker: max 500 rows per run, resume next day.
- **A.2.17** Autoload migration for existing drafts: rev 35 runs `UPDATE {prefix}options SET autoload='no' WHERE option_name LIKE %s` with `$wpdb->esc_like('hl_email_draft_') . '%'`. All new autosaves use `update_option($name, $value, false)`.
- **A.2.18** Per-workflow-ID nonce: `check_ajax_referer('hl_workflow_toggle_' . $id, 'nonce')` — not a global nonce (CSRF replay prevention). Same pattern for all row actions.
- **A.2.19** Schema migration runs on **plugins_loaded version check** (not just `register_activation_hook`) — required for git-deploy flow. Column-exists guard in every `ALTER TABLE`. Check `$wpdb->query()` return value (`=== false` → abort, do not bump revision number).
- **A.2.20** Queue claim expiry + recovery sweep: `UPDATE ... SET status='sending', claimed_at=NOW() WHERE id=%d AND status='pending'` with `$wpdb->rows_affected === 1` check. Recovery in `run_daily_checks()`: `UPDATE SET status='pending' WHERE status='sending' AND claimed_at < NOW() - INTERVAL 15 MINUTE`. At-least-once + dedup semantics documented.
- **A.2.21** `coaching_pre_end` N+1 risk: add composite indexes `hl_component(component_type, pathway_id)`, `hl_pathway_assignment(enrollment_id, pathway_id)`, `hl_coaching_session(component_id, enrollment_id, status)`. `LIMIT 5000` safety cap — emit audit warning if hit (never silently truncate).
- **A.2.22** Email deliverability headers: default `From`, `Reply-To`, `List-Unsubscribe: <mailto:...>, <https://.../unsubscribe?token=...>`, `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (Google/Yahoo Feb 2024 bulk sender rules, CASL compliance for Canadian clients). Per-workflow opt-out for true transactional (password reset). Unsubscribe token stored in user meta. Admin setting to suppress for specific workflow types.
- **A.2.23** `wp_mail_failed` hook: queue processor listens for silent SMTP rejects. Flip queue row to `failed` + increment retry. Prevents "sent" status when actually rejected.
- **A.2.24** Nonce refresh for long edit sessions: register `wp_refresh_nonces` filter. Autosave inside 24h-stale form would otherwise die silently.
- **A.2.25** Rev 35 migration **gated** before cron registers `cv_window_7d` etc. Cron `get_cron_trigger_users()` returns `[]` early if `available_from` column is missing (git-deploy can land code before admin page triggers migration). Log once per missed-column event.
- **A.2.26** Soft-delete for workflows (and alignment: delete action sets `status='deleted'` instead of hard DELETE). Prevents mid-cron race where `scheduled_at` is seconds away. Queue guard: `status IN ('sent','sending','failed')` for the deletion block check (failed/sending rows also reference workflow_id and must survive postmortem).
- **A.2.27** Server-side allowlist validation in `handle_workflow_save()`: validate every condition `field` + `op` against `HL_Admin_Emails::get_condition_fields()` / `get_condition_operators()`. Validate every recipient token against `get_recipient_tokens()` (allowlist is trigger-agnostic; compatibility is a send-time concern, silently skipped at resolve). Reject unknown fields/operators/tokens with admin notice.
- **A.2.28** `assigned_mentor` resolver signature: requires `$context['cycle_id']`. `HL_Email_Automation_Service::build_context()` must populate `cycle_id` for every trigger that lists `assigned_mentor` in its allowlist. Resolver returns NULL + audit warn if missing. SQL query must include `ORDER BY mentor_tm.id ASC` for deterministic selection.

### A.3 Minor Refinements

- **A.3.1** Outlook Word-engine font-size rendering: emit `font-size` on both `<td>` and inner `<span>` wrapper in `HL_Email_Block_Renderer::render_text()`.
- **A.3.2** jQuery noConflict wrapper: all JS uses `jQuery(function($){...})` IIFE. `wp_enqueue_script` deps: `['jquery']` (explicitly NOT `jquery-ui-sortable` — naming collision with Sortable.js which is separate from jQuery UI's sortable).
- **A.3.3** CSS specificity vs WP admin: wrap all email admin markup in `.hl-email-admin` outer class. Raises specificity naturally without `!important`.
- **A.3.4** `wp_add_inline_script` with position `'before'` (not default `'after'`) for `hlConditionFields` / `hlRecipientTokens` registry injection — JS IIFE references them at init.
- **A.3.5** `wp_json_encode()` (not `json_encode`) with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` for hidden textarea serialization. Check `false` return.
- **A.3.6** Delete guard transaction: `START TRANSACTION` + `SELECT ... FOR UPDATE` on the queue count check. Confirms `hl_email_queue` uses InnoDB.
- **A.3.7** `wp_localize_script` payload trimmed: omit descriptions in CC section, strip translator comments.
- **A.3.8** Audit log failures: try/catch wrapper, never block user action, `error_log` with `[HL_AUDIT_FAIL]` tag + daily aggregate counter in wp_option.
- **A.3.9** Draft cleanup index verification: EXPLAIN the `option_name LIKE 'hl_email_draft_%'` query to confirm `options(option_name)` index is used. Sargable — trailing wildcard only.
- **A.3.10** Cron fan-out rate limiter interaction: cron emits pass through `HL_Email_Rate_Limiter`. On limit hit, remaining rows stay `pending` with `scheduled_at = next_window` — **never drop silently**.
- **A.3.11** Idempotency boundaries documentation appendix: list every idempotency key in the system (draft save, queue claim, dedup token, nonce action names). Prevents drift.
- **A.3.12** Condition builder "matches any of" label instead of "in list" operator (non-technical mental model).
- **A.3.13** Preview modal merge-tag overflow: truncate merge values at 200 chars in preview iframe with `[...]` indicator. Prevents layout break from extreme values.
- **A.3.14** Date inversion notice: when `available_from > available_to`, show admin notice "Dates were reversed — Opens set to earlier date" on save (not silent swap).
- **A.3.15** Bulk actions on workflow/template row tables are **non-goal** for v2 (would reopen delete-race concerns at scale).
- **A.3.16** Empty conditions = "matches all" (explicit helper text + server-side acceptance).
- **A.3.17** Escape key precedence in preview modal: first press closes search dropdown if open, second press closes modal.
- **A.3.18** Delete guard error message wording: "Cannot delete — this workflow has sent X emails (audit trail). Use the Pause action instead to stop future sends."

### A.4 Phase 1 Accessibility Requirements

Codified once for the whole spec:

- All modals: `role="dialog"`, `aria-modal="true"`, focus trap, focus restore on close.
- All icon-only buttons: `aria-label` with action + target.
- All form error states: paired `aria-live="polite"` announcement (not color-only).
- All keyboard shortcuts: documented and non-conflicting with WP core (Ctrl+Z inside contenteditable flushes snapshot first).
- All list tables with row actions: keyboard navigable; row actions reachable by Tab without mouse hover.

### A.5 Resolved Open Questions

| Question | Resolution |
|----------|------------|
| Cron dedup semantics (once-per-window vs once-per-day) | **Once-per-window.** Dedup key has no date component. Range match tolerates missed cron runs. |
| Trigger-dependent visibility silent data loss | **Keep dimmed.** Incompatible tokens stay in JSON; server resolver silently skips at send time. |
| Undo/redo contenteditable guard | **Flush pending text snapshot on Ctrl+Z before delegating.** Browser native undo operates within block after snapshot is captured. |
| Workflow delete | **Soft-delete** (`status='deleted'`). Hard delete + queue cleanup is too racy. |
| Bulk actions on list tables | **Non-goal for v2.** Reopens delete-race at scale. |
| `client_success` cron trigger | **Remains stub.** Requires business criteria from Yuyan Huang. |

### A.6 Phase 4 Refinements (Strict Senior Engineer Pass)

**Semantic clarifications (major):**
- **A.6.1** Workflow edit + dedup semantics must be documented admin-facing: "Dedup is per-window. Editing a workflow does not re-trigger already-sent reminders." Add a **"Force resend"** admin action on workflows that clears dedup tokens for pending future fires.
- **A.6.2** `HL_Roles::sanitize_roles()` one-time scrub migration: explicit rev 35 step, chunked `LIMIT 500` with resume cursor in wp_option `hl_roles_scrub_cursor`, transactional per chunk, audit-logged. On first run if backlog count > 5000, temporarily raise cap to 5000 for that run.
- **A.6.3** `List-Unsubscribe` token: use HMAC-based rotating token — `hash_hmac('sha256', user_id . ':' . queue_id, wp_salt('auth'))`. No storage needed, unforgeable, per-send rotation. Old forwarded emails can't unsubscribe the current user accidentally.

**Security hardening (minor but important):**
- **A.6.4** Every action handler must call `current_user_can('manage_hl_core')` **alongside** the nonce check — defense in depth. Nonces prove intent, not authorization.
- **A.6.5** Preview iframe: serve from a distinct path (`admin-ajax.php?action=hl_email_preview_render`) with `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'` response header. Explicitly omit `allow-top-navigation`, `allow-scripts` from sandbox attribute.
- **A.6.6** Autoload migration race: after the one-shot `UPDATE wp_options SET autoload='no'` rev 35 step, the autosave path must **always** issue `UPDATE ... SET autoload='no'` after `update_option()` write (idempotent, cheap — prevents race with concurrent admin autosaves during migration).

**Reliability refinements (minor):**
- **A.6.7** Queue claim expiry: `max(10 × processor_interval_seconds, 900)` — dynamic based on configured cron frequency.
- **A.6.8** Draft cleanup: first-run check — if count > 5000, raise single-run cap to 5000 to drain backlog from v1→v2 gap. Subsequent runs stay at 500.
- **A.6.9** `moveNestedBlock()` must call `pushUndo()` like every other mutation (consistency).
- **A.6.10** `last_cron_run_at` staleness warning surfaces via: (a) audit log entry, (b) WP Site Health integration (`site_status_tests` filter), (c) admin notice on Emails admin pages if gap > 36h.
- **A.6.11** Recipient token alias deprecation: audit log every `cc_teacher` alias hit. Plan v3 removal after 90 days of zero hits.

**UX polish (minor):**
- **A.6.12** Recipient count hint async failure: hide the hint entirely on error, never leave a stale spinner. Log to console.
- **A.6.13** Dimmed incompatible tokens: tooltip on hover "Your current trigger doesn't provide this recipient type."
- **A.6.14** Single `HL_Admin_Emails::operator_label($op)` helper for consistent label mapping — error messages say "Invalid operator 'matches any of'" not "Invalid operator 'in'".
- **A.6.15** Preview modal loading state: skeleton/spinner overlay during fetch, cleared on iframe `load` event.
- **A.6.16** Undo/redo toolbar tooltips: "Undo (Ctrl+Z)", "Redo (Ctrl+Y)" — plus hint text "Undo history clears on save."
- **A.6.17** Merge-tag truncation: CSS `max-width` + `text-overflow: ellipsis` at the rendered line (not a 200-char cap). Let the email-client's rendering truncate visually.

**Test strategy additions:**
- **A.6.18** Add "Concurrency & Race Tests" subsection to testing strategy covering: two admins duplicating the same template simultaneously, cron running during migration, queue claim expiry recovery, draft cleanup on populated wp_options (seed with 1000+ drafts), autoload migration race.

### A.7 Phase 5 Refinements (Error-Likelihood Reduction)

**Critical — safety rails:**
- **A.7.1** **Force resend scope:** The "Force resend" admin action on workflows **only** clears dedup tokens for queue rows with `status='pending'` AND `workflow_id=%d`. It does NOT re-create already-sent emails. UI shows a scope selector modal before confirming: **(a) all pending (default)**, (b) specific user, (c) specific cycle. Confirmation dialog shows affected count. Audit-logged with admin user + scope. Never blasts sent-status rows.
- **A.7.2** **Rev 35 split into 3 revisions** to keep each migration atomic and independently resumable:
  - **Rev 35:** Schema only — `ALTER TABLE hl_component ADD available_from`, `ADD available_to`, add composite indexes from A.2.21. Idempotent (column-exists / index-exists guards).
  - **Rev 36:** Autoload cleanup — `UPDATE wp_options SET autoload='no' WHERE option_name LIKE 'hl\_email\_draft\_%'`. Idempotent.
  - **Rev 37:** Role scrub — chunked `LIMIT 500` with resume cursor in `hl_roles_scrub_cursor` wp_option. Runs on every `plugins_loaded` until cursor reaches end. Survives restarts.
  Each revision gates on `$wpdb->query() !== false` before bumping the version number. A half-migrated state leaves the revision at the last successful step, not "in between."
- **A.7.3** **HMAC unsubscribe secret:** Use a dedicated `hl_email_unsubscribe_secret` wp_option, generated once on plugin activation via `wp_generate_password(64, true, true)`, **never rotated**. Admin salt rotation does not invalidate outstanding unsubscribe links. If the option is missing (rare — pre-v2 install without activation hook), generate on first unsubscribe-URL request and store.
- **A.7.4** **JS failure fallback:** All workflow-edit form fields render with a `<details>` wrapper labeled "Raw JSON edit mode (JavaScript required for visual editor)". On DOMContentLoaded, `email-workflow.js` sets `<body class="hl-js-loaded">`. CSS hides the `<details>` and shows the visual builder. If after 2 seconds the class is not present, CSS reveals the `<details>` automatically (`body:not(.hl-js-loaded) .hl-js-fallback { display: block; }`). Admin can still save via raw JSON even if JS is broken. Prevents "empty builder saves wipe JSON" catastrophe.

**Major — documentation clarity:**
- **A.7.5** **Mid-window workflow creation behavior:** One-line clarification in A.1.6 — "Workflows created mid-window fire on the next cron run for all users whose `available_from` still falls within the range. Users whose window opened before the workflow existed are NOT retroactively notified (honored by the range match — their date is already in the past)."
- **A.7.6** **Cron timezone precision contract:** Document in `HL_Email_Automation_Service` class docblock: "All window triggers are **date-granular by design**. `current_time('Y-m-d')` respects WP timezone, but cron fires are subject to WP-Cron's visitor-triggered irregularity and may occur at any time of day. Edge-of-window enrollments can fire up to 24h before or after the exact calendar boundary. Any sub-day precision requirement needs a dedicated trigger type."
- **A.7.7** **Recipient count labeling:** Hint label uses live query result with explicit wording: **"Resolves to N recipient(s) at send time (based on current data)"**. Not "~". Recomputed debounced on every picker change. Query respects suspended users and trigger-compatible tokens. On error: hide the hint entirely.
- **A.7.8** **Undo-clear-on-save notice:** On the first save with a non-empty undo stack, show a one-time dismissible inline notice at the top of the builder: "Your undo history was cleared by saving. Undo only works within a single editing session." Stored in user meta `hl_email_builder_undo_notice_seen=1` so it shows exactly once per user. Also in toolbar tooltip on every page.
- **A.7.9** **Columns nesting rule visible in UI:** The mini palette in a columns block shows a **disabled "Columns" button** with tooltip "Columns cannot be nested — email clients don't render nested tables reliably." Makes the rule discoverable rather than invisible.

### A.7.10 Final Hardening (Phase 5 iteration 2)

- **A.7.10** JS failure fallback — **reverse logic**: the `<details>` raw JSON wrapper is visible by default. `email-workflow.js` sets `<body class="hl-js-loaded">` on `DOMContentLoaded`, and CSS `body.hl-js-loaded .hl-js-fallback { display: none; }` hides the fallback. On slow networks, admins see the fallback briefly but never lose editing state mid-flash. Also handles `window.onerror` as a belt-and-braces explicit failure signal.
- **A.7.11** Role scrub transient lock: rev 37's chunked UPDATE wraps each chunk in `set_transient('hl_roles_scrub_lock', 1, 60)`. If the lock is held at the start of a chunk, skip and wait for next `plugins_loaded` firing. Prevents concurrent scrub runs from double-processing a cursor range.
- **A.7.12** Unsubscribe secret race fix: fallback generation uses `add_option('hl_email_unsubscribe_secret', $secret, '', 'no')` (atomic insert-if-missing) followed by `get_option()` re-read. Two simultaneous first-requests will only persist one secret.
- **A.7.13** Force resend history visibility: workflow list row area shows "Last force-resend: 2026-05-14 by Mateo" inline when present (reads from audit log via `HL_Audit_Service::get_last_event($workflow_id, 'workflow_force_resend')`). Non-blocking — can ship post-launch if audit query is expensive; tooltip on the "Force resend" button is sufficient for launch.
- **A.7.14** Undo-clear notice per-user per-template: replace user-meta `hl_email_builder_undo_notice_seen` with template-scoped meta `hl_email_builder_undo_notice_seen_{template_id}=1`. Shows once per (user, template) pair rather than once per user globally. Tooltip remains on every page as a permanent reminder.

### A.7.15 Schema Reality Corrections (Pre-Implementation Audit)

During implementation-plan drafting, a codebase audit found 9 places where earlier appendix items referenced the wrong table/column names. **Plans are correct; this section documents the reality for future reference.**

| Spec reference | Reality in codebase | Correct usage |
|----------------|---------------------|---------------|
| `hl_enrollment.roles` as CSV | **JSON-encoded array** (confirmed in `class-hl-enrollment-repository.php` ~line 94, 103) | `HL_Roles::has_role()` must be format-agnostic. `FIND_IN_SET` works only after the rev 37 scrub normalizes rows. Pre-scrub, use PHP-side filter with `HL_Roles::parse_stored()`. Plans gate the SQL switchover on `HL_Roles::scrub_is_complete()`. |
| `hl_team_member` with `roles` column | **`hl_team_membership`** with `team_id`, `enrollment_id`, `membership_type enum('mentor','member')`. No `user_id` — join through `hl_enrollment`. | `assigned_mentor` SQL rewritten in Track 3 Task 23. |
| `hl_component.sort_order` | **`ordering_hint`** | `ALTER TABLE hl_component ADD ... AFTER ordering_hint` in rev 35. |
| `component_type = 'coaching_session'` | **`'coaching_session_attendance'`** | All cron queries use the full enum value. |
| `hl_classroom_visit_submission.component_id` | **Does not exist.** `component_id` lives on the entity table `hl_classroom_visit`, not the submission table. | NOT EXISTS subqueries join through the entity table. Same for `hl_rp_session_submission` → `hl_rp_session`. |
| `hl_enrollment.status` enum includes `'warning'` | **`('active','inactive')` only** | Existing automation queries already handle this; no change needed. |
| `hl_pathway_assignment` needs UNIQUE index | **Already has `UNIQUE KEY enrollment_pathway`** | Track 3 Task 6 skips adding it. |
| `HL_Audit_Service::get_last_event()` exists | **Does not exist** | Track 3 Task 5 adds it + try/catch wraps `log()`. |
| Migrations in `hl-core.php` bootstrap | **`HL_Installer::maybe_upgrade()` hooked to `plugins_loaded`** via `HL_Core::init()` | A.2.19 requirement already satisfied by existing wiring. |

**Impact on appendix items:** A.1.7, A.2.15, A.2.16, A.2.17, A.2.28, A.6.2, A.6.10, A.7.2, A.7.11, A.7.12 — all were written assuming CSV/wrong-table-names. The plans (track 3 pre-flight section, and track 1 edits) override the spec on these points. **When in conflict, the plan is authoritative.**

### A.8 Review Process Audit Trail

- **Phase 1:** 2 preliminary reviewers (UX lens, Architecture lens) — 26 issues, cross-validated, no conflicts.
- **Phase 2:** 3 senior experts (Frontend, PHP, Backend) — 29 new issues + 6 additions from cross-review.
- **Phase 3:** Experts' findings sent back to Phase 1 reviewers, accepted with 7 additions.
- **Phase 4:** Strict senior engineer pass — 19 additional refinements.
- **Phase 5:** Error-likelihood reduction — 9 more refinements (5 from UX reviewer, 5 from Architecture reviewer, 1 overlap).
- **Total:** ~86 unique issues addressed.
- **Final error-likelihood target:** 0/10 from both reviewers.

---

## Post-M1 Amendment (2026-04-15)

The following changes were made as part of the Workflow Builder UX Redesign M1 (`feature/workflow-ux-m1`). See spec: `docs/superpowers/specs/2026-04-15-workflow-ux-redesign-design.md`.

**Condition field change:**
- `coaching.session_scheduled` (binary yes/no) replaced by `coaching.session_status` (enum: not_scheduled, scheduled, attended, missed, cancelled, rescheduled)
- Existing workflows auto-migrated via `HL_Admin_Emails::migrate_coaching_session_conditions()`
- Context hydration now queries by `component_id` (component-scoped), not just `cycle_id`

**Admin UX:**
- `render_workflow_form_v2()` — two-panel card layout with progressive disclosure, gated by `hl_workflow_ux_version` wp_option (rollback toggle)
- Send Test Email endpoint (`ajax_send_test`) with domain allowlist, rate limit, audit logging
- Activation guardrails: server-side + client-side template validation
- Operator labels changed to "is any of" / "is none of"
- Recipient preview shows sample display names (up to 3)

**New CSS prefix:** `.hl-wf-*` for v2 workflow card layout

**JS modules added to `email-workflow.js`:** Summary panel sync, guardrails, send test UI, progressive disclosure, name sync, activate dialog, mobile drawer toggle

**Version:** 1.2.3
