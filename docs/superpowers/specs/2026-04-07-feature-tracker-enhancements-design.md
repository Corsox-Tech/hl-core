# Feature Tracker Enhancements — Design Spec

**Date:** 2026-04-07
**Scope:** 4 enhancements to the existing Feature Tracker (`[hl_feature_tracker]` shortcode)
**Reviewed by:** Two independent agents (architect + adversarial reviewer). 23 issues found and addressed. Final review pass: 6 additional gaps closed.

---

## Prerequisite: Fix Pre-existing Bug

**Bug:** Line 616 of `assets/js/frontend.js` has a stray `$('#hlft-comments-list').append(html);` outside the `finishComment()` closure. The `html` variable is not in scope there. This causes every comment post to either throw a ReferenceError or append garbage to the DOM.

**Fix:** Remove line 616 entirely. The `finishComment()` function already handles appending the comment HTML correctly.

**This must be fixed before implementing any of the enhancements below**, as the clipboard paste feature (Enhancement 4) routes through the same comment flow.

---

## 1. Category Dropdown

**What:** Add a required "Category" field to ticket creation/edit forms.

**DB change:** Add `category` column to `wp_hl_ticket`:
```sql
category enum('course_content','platform_issue','account_access','forms_assessments','reports_data','other') NOT NULL DEFAULT 'other'
```

> **Note:** `DEFAULT 'other'` is mandatory. Without it, `ALTER TABLE` on existing rows will fail in MySQL strict mode (default since 5.7) or silently assign the first enum value (`course_content`) in non-strict mode.

**Values and labels:**
| Enum value | Label |
|---|---|
| `course_content` | Course Content |
| `platform_issue` | Platform Issue |
| `account_access` | Account & Access |
| `forms_assessments` | Forms & Assessments |
| `reports_data` | Reports & Data |
| `other` | Other |

**Form placement:** After the Title field, before Type.

**Service changes (`HL_Ticket_Service`):**
- Add `VALID_CATEGORIES` constant with the 6 enum values
- `create_ticket()`: validate `category` is in `VALID_CATEGORIES`. Return `WP_Error('invalid_category', ...)` if empty or invalid. **Add `'category' => $category` to the `$wpdb->insert()` data array** (line ~229).
- `update_ticket()`: same validation. **Add `'category' => $category` to the `$update_data` array** (line ~296).
- `get_tickets()`: update the explicit `SELECT` at line 153 to include `category, context_mode, context_user_id` columns
- `enrich_ticket_for_detail()`: include `category` in returned data (already present from `SELECT *` in `get_ticket_raw()`)

**Frontend changes (`HL_Frontend_Feature_Tracker`):**
- `ajax_ticket_create()`: pass `'category' => isset($_POST['category']) ? sanitize_text_field($_POST['category']) : ''`
- `ajax_ticket_update()`: same

**JavaScript changes (`frontend.js`):**
- Add `categoryLabels` map: `{ course_content: 'Course Content', platform_issue: 'Platform Issue', ... }`
- Create form: add required `<select>` with `<option value="" disabled selected>Select category...</option>` placeholder + `required` attribute
- Edit form (`openEditModal()`): set `$('#hlft-form-category').val(ticket.category)`
- Create form (`openCreateModal()`): reset `$('#hlft-form-category').val('')`
- Detail modal: show "Category: [label]" in the meta row using `categoryLabels` map
- Form submit: include `category: $('#hlft-form-category').val()` in the data object

**Display:**
- Create form: required `<select>` with placeholder
- Edit form: populated with current value
- Detail modal: labeled row in meta section (e.g., "Category: Forms & Assessments")
- List table: no column added (visible in detail view only)

---

## 2. Department Field (Read-Only)

**What:** Show the ticket creator's `housman_learning_department` JetEngine user meta as a non-editable field.

> **Implementation prerequisite:** Verify the actual JetEngine meta key on the live site before implementing. Run `wp user meta list <user_id>` via WP-CLI to confirm the key is `housman_learning_department` (not a different slug). If the field hasn't been created yet in JetEngine, the feature shows "Not assigned" for all users until the field is populated.

**No DB change.** Department is read from user meta at display time, not stored on the ticket.

**PHP changes in `HL_Ticket_Service`:**
- In `enrich_ticket_for_detail()` only (list view doesn't display department, so avoid extra `get_user_meta()` calls per row):
  ```php
  $dept = get_user_meta( $row['creator_user_id'], 'housman_learning_department', true );
  // JetEngine multi-select fields may return an array
  if ( is_array( $dept ) ) {
      $dept = implode( ', ', array_map( 'sanitize_text_field', $dept ) );
  } else {
      $dept = sanitize_text_field( (string) $dept );
  }
  $row['creator_department'] = ! empty( $dept ) ? $dept : __( 'Not assigned', 'hl-core' );
  ```
- Not needed in `enrich_comment()` (comments don't show department)

**Create/Edit form:**
- Show a read-only field labeled "Department" with the current user's department value
- Rendered as a `<div class="hlft-form-group">` with a `<span>` styled as a disabled field (gray background, no pointer)
- Populated server-side via a data attribute: `data-user-department="<?php echo esc_attr( $current_user_department ); ?>"`
- PHP uses `esc_attr()` to prevent attribute injection; JS uses `esc()` helper when rendering
- If empty, show "Not assigned" in italic gray text (`.hlft-dept-empty` class)

**Detail modal:**
- Show "Department: [value]" in the meta row, next to the creator name/avatar
- Value comes from enriched ticket data (`creator_department`)
- Escaped via `esc()` JS helper before DOM insertion

---

## 3. "Viewing As" / Context Field

**What:** Let the ticket creator indicate whether the issue happened while browsing as themselves or while using the "View As" feature to impersonate another user.

**DB changes:** Add two columns to `wp_hl_ticket`:
```sql
context_mode enum('self','view_as') NOT NULL DEFAULT 'self',
context_user_id bigint(20) unsigned NULL DEFAULT NULL
```

- `context_mode = 'self'`: the issue happened as the creator's own account (default, most common)
- `context_mode = 'view_as'` + `context_user_id = N`: the issue happened while viewing as user N

### Service changes (`HL_Ticket_Service`)

**`create_ticket()` and `update_ticket()`:**
- Accept `context_mode` and `context_user_id`
- **Add to `$wpdb->insert()` data array** (line ~229): `'context_mode' => $context_mode, 'context_user_id' => $context_user_id`
- **Add to `$update_data` array** (line ~296): same two fields
- Validation rules:
  - If `context_mode === 'self'` (or not provided): **force `context_user_id = NULL`** regardless of what was passed. This prevents orphaned data.
  - If `context_mode === 'view_as'`:
    - `context_user_id` must be a non-zero integer
    - `get_userdata($context_user_id)` must return a valid user (any role — coaches view-as teachers who may not have `manage_hl_core`)
    - If `context_user_id` is empty/0/null: return `WP_Error('missing_context_user', 'Please select the user you were viewing as.')`
    - If `context_user_id` is an invalid user ID: return `WP_Error('invalid_context_user', 'The selected user does not exist.')`

**`enrich_ticket_for_detail()`:**
- If `context_mode === 'view_as'` and `context_user_id` is set:
  - Resolve user via `get_userdata($context_user_id)`
  - If user exists: add `context_user_name` (display_name) and `context_user_url` (profile URL)
  - If user deleted: add `context_user_name = 'Deleted User'`, `context_user_url = null`
  - Profile URL: use `bp_core_get_user_domain($user_id)` if BuddyBoss is active (`function_exists('bp_core_get_user_domain')`), fall back to `get_author_posts_url($user_id)`

### New AJAX endpoint: `hl_ticket_user_search`

- Registered in `HL_Frontend_Feature_Tracker::__construct()` alongside existing AJAX handlers
- Handler: `ajax_user_search()`
- Security: nonce + `manage_hl_core` capability check (same as other ticket endpoints)
- Accepts: `search` (string, **min 3 chars**)
- Query construction:
  ```php
  $like = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
  $wpdb->prepare(
      "SELECT ID, display_name FROM {$wpdb->users} WHERE display_name LIKE %s ORDER BY display_name ASC LIMIT 10",
      $like
  );
  ```
- Returns: array of `{ user_id, display_name, avatar_url }` — **no email** in response to prevent user enumeration
- Max 10 results
- Enrich each result with `get_avatar_url($user_id, ['size' => 64])`

### Frontend changes

**`ajax_ticket_create()` and `ajax_ticket_update()`:**
```php
'context_mode'    => isset( $_POST['context_mode'] ) ? sanitize_text_field( $_POST['context_mode'] ) : 'self',
'context_user_id' => ! empty( $_POST['context_user_id'] ) ? absint( $_POST['context_user_id'] ) : null,
```

### JavaScript changes

**Create/Edit form:**
- Dropdown labeled "Encountered as" with two options:
  - "Myself" (value: `self`) — default
  - "Viewing as another user" (value: `view_as`)
- When `view_as` selected: show user search input (hidden by default via CSS toggle)
- User search: text input with debounced AJAX (300ms, min 3 chars) to `hl_ticket_user_search`
  - **Abort previous in-flight request** before firing a new one (track the XHR object and call `.abort()`) to prevent stale results from overwriting newer ones
  - Clear results dropdown when input length < 3
  - Show "Searching..." spinner while AJAX is in-flight
  - Show "No users found" if 0 results
  - Results rendered as a dropdown list below the input, using `position: absolute` relative to the input wrapper (NOT `position: fixed`), with `z-index` above modal content. The autocomplete container must have `overflow: visible` so the dropdown isn't clipped.
  - Each result: avatar + display_name, clickable
- On select: store user ID in hidden `#hlft-form-context-user-id`, show user as a chip/tag with avatar + name + remove button
- On remove chip: clear hidden field, re-show search input
- All autocomplete rendering uses `esc()` helper for display_name
- **Form submission guard:** if `context_mode === 'view_as'` and `#hlft-form-context-user-id` is empty, prevent submission and show validation message "Please select the user you were viewing as"

**`openCreateModal()` reset:**
- `$('#hlft-form-context-mode').val('self')`
- Hide user search section
- Clear any selected context user chip
- Clear `#hlft-form-context-user-id`

**`openEditModal(ticket)` populate:**
- `$('#hlft-form-context-mode').val(ticket.context_mode)`
- If `ticket.context_mode === 'view_as'` and `ticket.context_user_name`:
  - Show user search section
  - Pre-populate chip with `ticket.context_user_name` and set hidden field to `ticket.context_user_id`

**Form submit data:**
- Include `context_mode: $('#hlft-form-context-mode').val()`
- Include `context_user_id: $('#hlft-form-context-user-id').val() || ''`

**Detail modal display:**
- If `context_mode === 'self'`: show nothing (default, no noise)
- If `context_mode === 'view_as'`: show "Viewing as [User Name]" in the meta row
  - If `context_user_url` is set: user name is a link (`<a href="..." target="_blank">`)
  - If `context_user_url` is null (deleted user): show "Viewing as Deleted User" with no link
  - Placed after department in the meta row

---

## 4. Clipboard Paste for Images

**What:** Allow users to paste images from clipboard (Ctrl+V / Cmd+V) in addition to the existing file picker.

> **Note:** This is a desktop enhancement. Mobile browsers handle clipboard paste inconsistently. The existing file picker remains the primary method for mobile users.

**No DB or PHP changes.** Purely a JavaScript enhancement.

### Implementation

**Paste event listeners:** Attach to these specific elements only (NOT the entire modal body):
- `#hlft-form-description` (create/edit form textarea)
- `#hlft-form-upload-area` (create/edit form upload zone)
- `#hlft-comment-text` (comment textarea)
- `#hlft-comment-preview` parent area

> **Why not the whole modal?** If a user pastes text into the title field while their clipboard contains a cached image, the image would be silently captured as an attachment. This is confusing UX.

**Paste handler logic:**
```javascript
function handlePaste(e, pendingFilesRef, $previewContainer) {
    var items = (e.originalEvent || e).clipboardData && (e.originalEvent || e).clipboardData.items;
    if (!items) return;

    var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    // NOT type.startsWith('image/') — this would match image/svg+xml which can contain JS

    var added = false;
    for (var i = 0; i < items.length; i++) {
        if (allowedTypes.indexOf(items[i].type) === -1) continue;

        var file = items[i].getAsFile();
        if (!file) continue;

        // Client-side size check (server enforces the real 5MB limit)
        // Note: File.size may be 0 for clipboard items in some browsers — allow through to server validation
        if (file.size > 0 && file.size > 5 * 1024 * 1024) {
            showToast('Pasted image exceeds 5MB limit', true);
            continue;
        }

        // Generate unique filename with correct extension
        var extMap = { 'image/jpeg': '.jpg', 'image/png': '.png', 'image/gif': '.gif', 'image/webp': '.webp' };
        var ext = extMap[items[i].type] || '.png';
        var rand = Math.random().toString(16).slice(2, 6);
        var filename = 'pasted-image-' + Date.now() + '-' + rand + ext;

        // Create a new File with the proper name (clipboard files have generic names)
        var namedFile = new File([file], filename, { type: items[i].type });

        pendingFilesRef.push(namedFile);
        added = true;
    }

    if (added) {
        showFilePreview($previewContainer, pendingFilesRef);
        showToast('Image pasted');
        // Do NOT call e.preventDefault() — let text paste through normally
    }
}
```

**Key behaviors:**
- Multiple pastes accumulate (append to existing pending files array)
- If paste contains no image data, ignore silently (text paste works normally)
- If paste contains both text and image, process the image AND let the text paste into the textarea
- Toast "Image pasted" confirms the paste was captured
- Pasted images appear in the same preview gallery as file-picked images

**File picker consistency fix:** The existing file picker handler at line ~502 does `pendingFormFiles = Array.from(this.files || [])` which **replaces** the array. This means if a user pastes an image, then uses the file picker, the pasted image is lost. **Fix:** Change the file picker handler to **append** instead: `pendingFormFiles = pendingFormFiles.concat(Array.from(this.files || []))`. Same fix for comment file picker. This ensures paste and picker accumulate consistently.

---

## Migration Strategy

### Fresh installs
Add all new columns to the `CREATE TABLE` statement in `class-hl-installer.php`. This ensures new sites get the correct schema from the start.

### Existing installs
Add an upgrade routine (alongside existing migration methods in `HL_Installer`) that:

1. **Check if columns exist** before each ALTER (idempotent — safe to re-run):
   ```php
   private function column_exists( $table, $column ) {
       global $wpdb;
       $result = $wpdb->get_results( $wpdb->prepare(
           "SHOW COLUMNS FROM {$table} LIKE %s", $column
       ) );
       return ! empty( $result );
   }
   ```

2. **ALTER TABLE** for each missing column (all with DEFAULTs):
   ```sql
   ALTER TABLE wp_hl_ticket ADD COLUMN category enum('course_content','platform_issue','account_access','forms_assessments','reports_data','other') NOT NULL DEFAULT 'other';
   ALTER TABLE wp_hl_ticket ADD COLUMN context_mode enum('self','view_as') NOT NULL DEFAULT 'self';
   ALTER TABLE wp_hl_ticket ADD COLUMN context_user_id bigint(20) unsigned NULL DEFAULT NULL;
   ```

3. No separate UPDATE needed — `DEFAULT 'other'` and `DEFAULT 'self'` handle existing rows.

> **Why both CREATE TABLE and ALTER TABLE?** This is the standard WordPress plugin pattern. `dbDelta()` (used in `create_tables()`) can add columns to existing tables, but is unreliable with ENUM types. The explicit ALTER TABLE with column-exists guard is the safety net. If `dbDelta` succeeds first, the ALTER TABLE sees the column exists and skips. If `dbDelta` fails (ENUM quirk), the ALTER TABLE picks it up. Idempotent either way.

### Schema version bump
Increment `$current_revision` in `HL_Installer::maybe_upgrade()` from **30 to 31**. Add a conditional migration block:
```php
if ( (int) $stored < 31 ) {
    self::migrate_ticket_enhancements_v2();
}
```
This triggers the ALTER TABLE migration on existing installs. The `hl_core_schema_revision` option is updated automatically at the end of `maybe_upgrade()`.

---

## File Changes Summary

| File | Changes |
|---|---|
| `assets/js/frontend.js` | Fix line 616 bug. Add `categoryLabels` map. Add category + context_mode + user search UI logic. Add clipboard paste handler. Update form submit data. Update `openCreateModal()` reset + `openEditModal()` populate for new fields. Add form submission guard for view_as mode. |
| `includes/services/class-hl-ticket-service.php` | Add `VALID_CATEGORIES` constant. Update `create_ticket()` + `update_ticket()` with category/context validation AND insert/update data arrays. Update `enrich_ticket_for_detail()` with department + context user resolution. Add `search_users()` method. Update `get_tickets()` SELECT to include new columns. |
| `includes/frontend/class-hl-frontend-feature-tracker.php` | Add category + context fields to form HTML. Add department read-only field. Add `data-user-department` attribute. Register `hl_ticket_user_search` AJAX handler. Update `ajax_ticket_create()` + `ajax_ticket_update()` to pass new fields. |
| `includes/class-hl-installer.php` | Add `category`, `context_mode`, `context_user_id` columns to `hl_ticket` CREATE TABLE. Add `migrate_ticket_enhancements_v2()` method. Bump `$current_revision` from 30 to 31. |
| `assets/css/frontend.css` | Styles for: read-only department field (`.hlft-dept-readonly`, `.hlft-dept-empty`), user search autocomplete dropdown + results + loading/empty states, context user chip, paste feedback. |

---

## Security Requirements (verify during code review)

1. All user search queries use `$wpdb->prepare()` + `$wpdb->esc_like()`
2. User search response excludes email (only display_name + avatar_url)
3. Department meta escaped with `esc_attr()` in PHP data attribute, `esc()` in JS rendering
4. All display_name values escaped with `esc()` in JS autocomplete rendering
5. `context_user_id` validated as existing WordPress user on server side
6. `context_user_id` forced to NULL when `context_mode === 'self'`
7. Clipboard paste filters against explicit allowlist `['image/jpeg', 'image/png', 'image/gif', 'image/webp']` — no SVG
8. All new form fields validated both client-side (required, submission guards) and server-side (WP_Error)

## What This Spec Does NOT Include

- No filter-by-category in the toolbar (can be added later if needed)
- No auto-detection of View As mode (self-reported only, per user's request)
- No changes to the ticket list table columns (category visible in detail only)
- No rich text editor for description (stays plain text)
- No mobile-specific clipboard paste support (desktop enhancement; mobile uses file picker)
- No rate limiting on user search endpoint (acceptable for internal tool with small user base behind capability check)
