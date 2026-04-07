# Feature Tracker UX Improvements — Design Spec

**Date:** 2026-04-07
**Scope:** 3 enhancements to the Feature Tracker form/modal UX

---

## 1. Wider Modal + 2-Column Layout

**Modal width:** Change `.hlft-modal-box--form` from `max-width: 520px` to `max-width: 700px`.

**Form layout:** Wrap form fields in a CSS grid container `.hlft-form-grid`:

```
Row 1: [Title ................................] (full width)
Row 2: [Category         ] [Type            ] (2 columns)
Row 3: [Priority         ] [Encountered as  ] (2 columns)
Row 4: [Department .............................] (full width, read-only)
Row 5: [Context user search — conditional     ] (full width, shown when view_as)
Row 6: [Description ...........................] (full width, textarea)
Row 7: [Attachments ...........................] (full width)
```

**CSS:**
```css
.hlft-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.hlft-form-grid .hlft-form-group--full {
    grid-column: 1 / -1;
}
@media (max-width: 500px) {
    .hlft-form-grid {
        grid-template-columns: 1fr;
    }
}
```

**HTML changes:** Add `class="hlft-form-group--full"` to Title, Department, Context user wrap, Description, and Attachments form groups. The paired fields (Category, Type, Priority, Encountered as) stay as plain `.hlft-form-group`.

**Field order change:** Move Title from after the hidden UUID input to Row 1 (before Category). Currently the order in the PHP is Title → Category → Department → Encountered as → Type → Priority → Description → Attachments. New order:

1. Title (full width)
2. Category (half) + Type (half)
3. Priority (half) + Encountered as (half)
4. Department (full width)
5. Context user search (full width, conditional)
6. Description (full width)
7. Attachments (full width)

**Department label resolution:** Instead of showing the raw JetEngine meta slug (e.g., `product`), resolve it to the human-readable label using JetEngine's field config API:

```php
/**
 * Get the human-readable label for a JetEngine user meta value.
 */
function get_jet_meta_label( $meta_key, $raw_value ) {
    if ( ! function_exists( 'jet_engine' ) || empty( $raw_value ) ) {
        return $raw_value;
    }
    // Get all user meta boxes from JetEngine.
    $meta_boxes = jet_engine()->meta_boxes->get_registered_fields_for_context( 'user' );
    foreach ( $meta_boxes as $fields ) {
        foreach ( $fields as $field ) {
            if ( isset( $field['name'] ) && $field['name'] === $meta_key && ! empty( $field['options'] ) ) {
                foreach ( $field['options'] as $option ) {
                    if ( isset( $option['key'] ) && $option['key'] === $raw_value ) {
                        return $option['value']; // The label
                    }
                }
            }
        }
    }
    return $raw_value; // Fallback to raw value
}
```

This is called in two places:
- `enrich_ticket_for_detail()` when resolving `creator_department`
- `render()` when setting the `data-user-department` attribute

**Fallback:** If JetEngine is not active or the field isn't found, the raw slug is displayed (same as current behavior). No crash, graceful degradation.

---

## 2. Removable Image Previews

**Current behavior:** `showFilePreview()` renders bare `<img>` tags with no removal option.

**New behavior:** Each thumbnail is wrapped in a positioned container with an X button:

```html
<div class="hlft-preview-item" data-index="0">
    <img src="data:image/png;base64,..." alt="">
    <button class="hlft-preview-remove" type="button">&times;</button>
</div>
```

**`showFilePreview()` update:**

```javascript
function showFilePreview($container, files) {
    $container.empty();
    $.each(files, function(i, f) {
        var reader = new FileReader();
        reader.onload = function(e) {
            $container.append(
                '<div class="hlft-preview-item" data-index="' + i + '">' +
                '<img src="' + e.target.result + '" alt="">' +
                '<button type="button" class="hlft-preview-remove">&times;</button>' +
                '</div>'
            );
        };
        reader.readAsDataURL(f);
    });
}
```

**Remove handler:** Delegated click on `.hlft-preview-remove`. Determines which pending array to modify based on whether the clicked element is inside the form modal or the comment section:

```javascript
$(document).on('click', '.hlft-preview-remove', function(e) {
    e.stopPropagation();
    var $item = $(this).closest('.hlft-preview-item');
    var idx = $item.data('index');
    var $container = $item.closest('.hlft-upload-preview');

    // Determine which pending array
    if ($container.attr('id') === 'hlft-form-preview') {
        pendingFormFiles.splice(idx, 1);
        showFilePreview($('#hlft-form-preview'), pendingFormFiles);
    } else if ($container.attr('id') === 'hlft-comment-preview') {
        pendingCommentFiles.splice(idx, 1);
        showFilePreview($('#hlft-comment-preview'), pendingCommentFiles);
    }
});
```

**CSS:**

```css
.hlft-preview-item {
    position: relative;
    display: inline-block;
}
.hlft-preview-remove {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--hl-error, #d32f2f);
    color: #fff;
    border: none;
    font-size: 12px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}
.hlft-preview-remove:hover {
    background: #b71c1c;
}
```

---

## 3. Draft Tickets

### DB change

Add `'draft'` to the status enum, before `'open'`:

```sql
status enum('draft','open','in_review','in_progress','resolved','closed') NOT NULL DEFAULT 'open'
```

Migration: `ALTER TABLE wp_hl_ticket MODIFY COLUMN status enum('draft','open','in_review','in_progress','resolved','closed') NOT NULL DEFAULT 'open'`. Since `MODIFY COLUMN` rewrites the column definition, all existing rows keep their current status values. Guarded by a check that `'draft'` is not already in the enum.

Schema revision: bump from 31 to 32.

### Service changes (`HL_Ticket_Service`)

**Constants:**
- Update `VALID_STATUSES` to include `'draft'` at the beginning
- `TERMINAL_STATUSES` stays unchanged (`['resolved', 'closed']`) — drafts are NOT terminal
- Add `const DRAFT_STATUS = 'draft';`

**New method `save_draft()`:**
- Same as `create_ticket()` but sets `status = 'draft'`
- Relaxed validation: only `title` is required (can be blank description, no category required — defaults to `'other'`)
- Returns the created draft ticket

**New method `publish_draft()`:**
- Takes a ticket UUID
- Validates all fields that `create_ticket()` would validate (title, type, category, description all required)
- Changes status from `'draft'` to `'open'`
- Returns WP_Error if validation fails (e.g., "Please fill in all required fields before publishing")
- Only the author or admin can publish

**`update_ticket()` changes:**
- Drafts bypass the 2-hour edit window — author can edit their drafts indefinitely
- In `can_edit()`: if ticket status is `'draft'` and user is the creator, always return true (no time check)

**`get_tickets()` changes:**
- Default filter (no status specified): exclude both `'closed'` AND `'draft'` tickets from other users
- Add condition: `AND (t.status != 'draft' OR t.creator_user_id = %d)` with `get_current_user_id()`
- When status filter is `'all'`: still hide other users' drafts (same condition)
- When status filter is explicitly `'draft'`: only show current user's drafts

### AJAX handlers (`HL_Frontend_Feature_Tracker`)

**New handlers:**
- `hl_ticket_save_draft` → calls `save_draft()` or `update_ticket()` (if UUID present = editing existing draft)
- `hl_ticket_publish_draft` → calls `publish_draft()`

### JavaScript changes

**Form action buttons:**
- When creating (no UUID): show "Submit" + "Save as Draft" + "Cancel"
- When editing a draft: show "Publish" + "Save as Draft" + "Cancel"
- When editing a non-draft: show "Save Changes" + "Cancel" (no draft option)

**Button layout:**

```html
<div class="hlft-form-actions">
    <button type="submit" class="hl-btn hl-btn-primary" id="hlft-form-submit">Submit</button>
    <button type="button" class="hl-btn hl-btn-secondary" id="hlft-form-draft" style="display:none;">Save as Draft</button>
    <button type="button" class="hl-btn" data-close-modal>Cancel</button>
</div>
```

- `openCreateModal()`: show draft button, set submit text to "Submit"
- `openEditModal()`: if `ticket.status === 'draft'`, show draft button, set submit text to "Publish"; otherwise hide draft button, set submit text to "Save Changes"

**Draft button handler:**
- Collects form data (same as submit)
- Posts to `hl_ticket_save_draft` action
- On success: toast "Draft saved", close modal, refresh table

**Publish handler (submit button when editing a draft):**
- Posts to `hl_ticket_publish_draft` action
- On success: toast "Ticket published", close modal, refresh table
- On error (validation fails): toast shows error, button re-enabled

**Dirty state tracking + close confirmation:**

```javascript
var formDirty = false;

// Track changes on any form input
$('#hlft-ticket-form').on('input change', 'input, select, textarea', function() {
    formDirty = true;
});

// Reset dirty state when modal opens or form submits successfully
// In openCreateModal() and openEditModal(): formDirty = false;
// After successful submit/draft save: formDirty = false;
```

**Close confirmation:** Modify `closeModal()` — when closing the form modal and `formDirty` is true:

```javascript
function closeModal($modal) {
    if ($modal.attr('id') === 'hlft-form-modal' && formDirty) {
        showConfirmDialog(
            'You have unsaved changes.',
            [
                { label: 'Save as Draft', action: function() { saveDraft(); } },
                { label: 'Discard', action: function() { formDirty = false; closeModal($modal); } },
                { label: 'Keep Editing', action: function() { /* do nothing, dialog closes */ } }
            ]
        );
        return; // Don't close yet
    }
    $modal.hide();
    // ... existing reopen-detail logic
}
```

**Confirm dialog:** A simple inline dialog rendered inside the form modal (not a separate modal). Three buttons, appears over the form content with a semi-transparent overlay.

**Table display:**
- Draft tickets show a "Draft" status pill with dashed border and muted color
- CSS: `.hlft-status-pill--draft { border: 1px dashed var(--hl-text-secondary); color: var(--hl-text-secondary); background: transparent; }`
- Clicking a draft row opens it in edit mode directly (calls `openEditModal()` instead of `openDetail()`)
- `statusLabels` JS map updated: `draft: 'Draft'`

### Permissions

- Only the **author** sees their own drafts in the table
- Only the **author or admin** can edit/publish a draft
- Drafts do NOT appear in the detail view for other users (if someone somehow navigates to one, `get_ticket()` returns null for non-authors of draft tickets)

---

## File Changes Summary

| File | Changes |
|---|---|
| `includes/class-hl-installer.php` | MODIFY COLUMN to add 'draft' to status enum. Bump revision 31→32. |
| `includes/services/class-hl-ticket-service.php` | Update VALID_STATUSES. Add save_draft(), publish_draft(). Update can_edit() for drafts. Update get_tickets() to filter drafts. Add get_jet_meta_label() helper. Update enrich_ticket_for_detail() department label. |
| `includes/frontend/class-hl-frontend-feature-tracker.php` | Reorder form fields for grid layout. Add hlft-form-grid wrapper. Add draft/publish buttons. Register hl_ticket_save_draft + hl_ticket_publish_draft AJAX. Update department data attribute to use label. |
| `assets/js/frontend.js` | Update showFilePreview() with remove buttons. Add remove handler. Add draft button handler + publish handler. Add formDirty tracking. Update closeModal() with confirm dialog. Update openCreateModal/openEditModal for draft state. Update statusLabels. |
| `assets/css/frontend.css` | Widen modal. Add .hlft-form-grid. Add .hlft-preview-item + .hlft-preview-remove. Add .hlft-status-pill--draft. Add confirm dialog styles. |

---

## What This Spec Does NOT Include

- No auto-save timer (only manual "Save as Draft" + close confirmation)
- No draft expiration/cleanup (drafts persist until published or deleted)
- No notification when a draft exists (author discovers them in the table)
- No bulk draft operations
