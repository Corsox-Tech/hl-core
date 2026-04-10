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
