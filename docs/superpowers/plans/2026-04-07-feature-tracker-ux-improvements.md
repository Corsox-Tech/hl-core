# Feature Tracker UX Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement 3 UX improvements to the Feature Tracker: (1) wider modal with 2-column form layout, (2) removable image preview thumbnails, and (3) draft ticket saving with dirty-state close confirmation.

**Architecture:** CSS grid wraps the form fields for responsive 2-column layout; a `showFilePreview()` upgrade wraps thumbnails in positioned containers with X buttons; draft tickets use a new DB status value with relaxed-validation service methods, new AJAX endpoints, and JS dirty-state tracking with an inline confirm dialog.

**Tech Stack:** PHP 7.4+, jQuery, WordPress AJAX, MySQL ALTER TABLE, Playwright for E2E tests.

---

## File Map

| File | Changes |
|---|---|
| `assets/css/frontend.css` | Widen form modal, add grid, fix grid margin conflicts, preview remove, draft pill, confirm dialog styles |
| `includes/class-hl-installer.php` | Rev 32→33, MODIFY COLUMN + update `get_schema()` |
| `includes/services/class-hl-ticket-service.php` | VALID_STATUSES, DRAFT_STATUS, change_status() guard, update_ticket() draft guard, get_jet_meta_label(), can_edit(), get_ticket(), get_tickets() with admin bypass, save_draft(), publish_draft(), enrich_ticket_for_detail() |
| `includes/frontend/class-hl-frontend-feature-tracker.php` | Form reorder + grid wrapper, dept label, draft buttons, AJAX registrations, ajax_ticket_save_draft(), ajax_ticket_publish_draft() |
| `assets/js/frontend.js` | showFilePreview() generation counter + remove handler, formDirty (incl. paste), isDraftMode/isPublishMode, closeModal() with confirm + guard, saveDraft(), draft/publish AJAX handlers, openCreateModal/openEditModal, statusLabels, row click for drafts |
| `tests/e2e/feature-tracker.spec.js` | New describe block for grid layout, image remove, draft save/publish, dirty close |

---

## Important Implementation Notes (read before coding)

1. **Schema revision:** `$current_revision` is already `32` (for `hl_user_profile` added in a prior session). The draft migration goes in revision **33**.

2. **Context-user-wrap is now a separate grid row.** Currently `#hlft-context-user-wrap` is nested _inside_ the "Encountered as" `.hlft-form-group`. In the new layout it becomes its own `.hlft-form-group--full` element. Keep `id="hlft-context-user-wrap"` — JS still uses it for show/hide. Keep `class="hlft-context-user-wrap"` too (CSS styles still apply).

3. **`get_jet_meta_label()` must be `public`** — `render()` in the frontend class calls it via `HL_Ticket_Service::instance()->get_jet_meta_label(...)`.

4. **`VALID_STATUSES` includes `'draft'`** but `get_tickets()` checks for `'draft'` explicitly _before_ the generic `in_array` path to apply the correct visibility-filtered query.

5. **`isDraftMode` JS flag** = true when creating new ticket OR editing a draft. Controls: (a) button text, (b) whether "Save as Draft" appears in close confirmation, (c) whether detail modal reopens after close. Always set by `openCreateModal()` / `openEditModal()`.

6. **`isPublishMode` JS flag** = true only when editing a draft. Form submit posts to `hl_ticket_publish_draft` instead of `hl_ticket_update`.

7. **`saveDraft()` is a standalone JS function** called from both the draft button and the close confirmation dialog. It directly hides the modal on success (never calls `closeModal()` to avoid re-entry). It sets `currentUuid = null` on success to prevent stale state.

8. **`wasPublishMode`:** The submit handler captures `isPublishMode` in a local `var wasPublishMode` at the start, preventing a race condition if the shared flag changes while an AJAX call is in flight.

9. **`change_status()` must block 'draft':** Adding 'draft' to `VALID_STATUSES` would otherwise allow admin to re-draft an open ticket via direct POST. An explicit guard prevents this.

10. **`update_ticket()` must reject drafts:** Drafts should only be updated via `save_draft()` or published via `publish_draft()`. Calling `update_ticket()` on a draft would apply strict validation that rejects incomplete drafts.

---

## Task 1: CSS — Wider Modal, 2-Column Grid, Preview Remove, Draft Pill, Confirm Dialog

**Files:**
- Modify: `assets/css/frontend.css`

- [ ] **Step 1: Change modal width and add grid styles**

Find `.hlft-modal-box--form` and replace:
```css
.hlft-modal-box--form {
    max-width: 520px;
}
```
With:
```css
.hlft-modal-box--form {
    max-width: 700px;
    position: relative; /* needed for confirm overlay absolute positioning */
}
.hlft-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.hlft-form-grid .hlft-form-group {
    margin-bottom: 0; /* grid gap handles spacing — prevents doubled gap + margin-bottom */
}
.hlft-form-grid .hlft-form-group--full {
    grid-column: 1 / -1;
}
.hlft-form-grid .hlft-context-user-wrap {
    margin-top: 0; /* was 8px when nested; grid gap now handles it */
}
@media (max-width: 500px) {
    .hlft-form-grid {
        grid-template-columns: 1fr;
    }
}
```

- [ ] **Step 2: Add preview item + remove button styles**

Find the first occurrence of `.hlft-upload-preview {` rule. Add `padding-top: 8px;` inside that rule to give clearance for the absolutely-positioned remove button overflow. Then, after the closing `}` of `.hlft-upload-preview`, add:
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

- [ ] **Step 3: Add draft status pill style**

Find `.hlft-status-pill--closed` rule and add after its closing `}`:
```css
.hlft-status-pill--draft {
    border: 1px dashed var(--hl-text-secondary);
    color: var(--hl-text-secondary);
    background: transparent;
}
```

- [ ] **Step 4: Add confirm dialog overlay styles**

After all `.hlft-` Feature Tracker rules, add:
```css
/* ── Close Confirmation Dialog ── */
.hlft-confirm-overlay {
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,0.93);
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: inherit;
    z-index: 10;
}
.hlft-confirm-dialog {
    padding: 24px;
    text-align: center;
    max-width: 340px;
}
.hlft-confirm-dialog p {
    margin: 0 0 16px;
    font-size: 15px;
    color: var(--hl-text);
    font-weight: 500;
}
.hlft-confirm-actions {
    display: flex;
    gap: 8px;
    justify-content: center;
    flex-wrap: wrap;
}
```

- [ ] **Step 5: Commit**

```bash
git add assets/css/frontend.css
git commit -m "style(tickets): wider modal, 2-col grid, preview remove, draft pill, confirm overlay"
```

---

## Task 2: DB Schema — Installer Migration for Draft Status

**Files:**
- Modify: `includes/class-hl-installer.php`

- [ ] **Step 1: Bump schema revision from 32 to 33**

Change line ~136:
```php
        $current_revision = 32;
```
To:
```php
        $current_revision = 33;
```

- [ ] **Step 2: Add rev 33 migration block**

After the existing rev 32 block:
```php
            // Rev 32: Add hl_user_profile table for auth/profile system.
            if ( (int) $stored < 32 ) {
                // Table created by dbDelta in get_schema(). No ALTER TABLE needed.
            }
```

Add immediately after:
```php
            // Rev 33: Add 'draft' to hl_ticket.status enum.
            if ( (int) $stored < 33 ) {
                self::migrate_ticket_draft_status();
            }
```

- [ ] **Step 3: Add migrate_ticket_draft_status() static method**

Find the closing `}` of `migrate_ticket_enhancements_v2()` method and add this new static method after it:

```php
    /**
     * Rev 33: Add 'draft' to hl_ticket.status ENUM.
     *
     * MODIFY COLUMN rewrites the column definition but preserves all existing row values.
     * Guard prevents running twice if already applied.
     * Explicit CHARACTER SET + COLLATE ensures charset doesn't drift on MODIFY.
     */
    private static function migrate_ticket_draft_status() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_ticket';

        // Check if 'draft' is already in the enum before running.
        $col = $wpdb->get_row( $wpdb->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND COLUMN_NAME = 'status'",
            $table
        ) );

        // If we can't verify (NULL result), proceed — MODIFY COLUMN is safe to re-run.
        if ( $col && strpos( $col->COLUMN_TYPE, "'draft'" ) !== false ) {
            return; // Already applied.
        }

        $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `status`
            enum('draft','open','in_review','in_progress','resolved','closed') NOT NULL DEFAULT 'open'
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
    }
```

- [ ] **Step 4: Update get_schema() enum**

In `get_schema()`, find the `hl_ticket` CREATE TABLE block. Change:
```php
            status enum('open','in_review','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
```
To:
```php
            status enum('draft','open','in_review','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
```

- [ ] **Step 5: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat(tickets): add draft status to hl_ticket schema (rev 33)"
```

---

## Task 3: Service Layer — Draft Support, JetEngine Label, Visibility Filtering

**Files:**
- Modify: `includes/services/class-hl-ticket-service.php`

- [ ] **Step 1: Add DRAFT_STATUS constant and update VALID_STATUSES**

Change the constants block:
```php
    /** @var string[] Valid statuses (includes draft — checked explicitly in get_tickets()). */
    const VALID_STATUSES = array( 'draft', 'open', 'in_review', 'in_progress', 'resolved', 'closed' );

    /** @var string Draft status identifier. */
    const DRAFT_STATUS = 'draft';
```

- [ ] **Step 2: Guard change_status() against setting a ticket back to draft**

Find `change_status()` (line ~382). After the `is_ticket_admin()` guard at the top of the method, add:
```php
        // Drafts can only be created/updated via save_draft(); never via status change.
        if ( $new_status === self::DRAFT_STATUS ) {
            return new WP_Error( 'invalid_status', __( 'Tickets cannot be moved back to draft status.', 'hl-core' ) );
        }
```

- [ ] **Step 3: Guard update_ticket() against editing draft tickets directly**

Find `update_ticket()` (line ~289). After the `get_ticket_raw()` null check (`if ( ! $ticket ) { return new WP_Error(...) }`), add:
```php
        // Drafts must be updated via save_draft() or published via publish_draft() — even admins.
        if ( $ticket['status'] === self::DRAFT_STATUS ) {
            return new WP_Error( 'use_save_draft', __( 'Draft tickets must be updated via save as draft.', 'hl-core' ) );
        }
```

- [ ] **Step 4: Update can_edit() to bypass 2-hour limit for drafts**

Replace `can_edit()` method body:
```php
    public function can_edit( $ticket ) {
        if ( $this->is_ticket_admin() ) {
            return true;
        }

        $user_id = get_current_user_id();
        if ( (int) $ticket['creator_user_id'] !== $user_id ) {
            return false;
        }

        if ( in_array( $ticket['status'], self::TERMINAL_STATUSES, true ) ) {
            return false;
        }

        // Drafts are always editable by their creator (no time limit).
        if ( $ticket['status'] === self::DRAFT_STATUS ) {
            return true;
        }

        $created = strtotime( $ticket['created_at'] );
        $now     = strtotime( current_time( 'mysql' ) );
        return ( $now - $created ) < self::EDIT_WINDOW_SECONDS;
    }
```

- [ ] **Step 5: Update get_ticket() to hide other users' drafts**

Find `get_ticket()` (line ~181). After the `if ( ! $row ) { return null; }` check, add:
```php
        // Drafts are only visible to their creator and the admin.
        if ( $row['status'] === self::DRAFT_STATUS
             && (int) $row['creator_user_id'] !== get_current_user_id()
             && ! $this->is_ticket_admin() ) {
            return null;
        }
```

- [ ] **Step 6: Update get_tickets() status filter for draft visibility**

Replace the status filter block in `get_tickets()` (lines ~116-125):
```php
        // Status filter.
        $current_uid = get_current_user_id();
        if ( ! empty( $args['status'] ) && $args['status'] === 'all' ) {
            // "all" = include all statuses; still hide other users' drafts (admin sees all).
            if ( ! $this->is_ticket_admin() ) {
                $where[]  = '(t.status != %s OR t.creator_user_id = %d)';
                $values[] = self::DRAFT_STATUS;
                $values[] = $current_uid;
            }
        } elseif ( ! empty( $args['status'] ) && $args['status'] === self::DRAFT_STATUS ) {
            // Draft filter: current user's drafts only (admin sees all drafts).
            $where[]  = 't.status = %s';
            $values[] = self::DRAFT_STATUS;
            if ( ! $this->is_ticket_admin() ) {
                $where[]  = 't.creator_user_id = %d';
                $values[] = $current_uid;
            }
        } elseif ( ! empty( $args['status'] ) && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
            // Specific non-draft status (open, in_review, etc.) — no draft leakage possible.
            $where[]  = 't.status = %s';
            $values[] = $args['status'];
        } else {
            // Default: exclude closed AND other users' drafts (admin sees all).
            $where[]  = "t.status != 'closed'";
            if ( ! $this->is_ticket_admin() ) {
                $where[]  = '(t.status != %s OR t.creator_user_id = %d)';
                $values[] = self::DRAFT_STATUS;
                $values[] = $current_uid;
            }
        }
```

- [ ] **Step 7: Add get_jet_meta_label() public helper**

After the closing `}` of `is_ticket_admin()`, add:

```php
    /**
     * Resolve a JetEngine user meta value to its human-readable label.
     *
     * Includes safety checks for JetEngine not being active, meta_boxes module being
     * disabled, or the field/option not existing — all fall back to the raw value.
     *
     * @param string $meta_key  The JetEngine meta field name.
     * @param string $raw_value The stored raw option key.
     * @return string Human-readable label, or $raw_value as fallback.
     */
    public function get_jet_meta_label( $meta_key, $raw_value ) {
        if ( ! function_exists( 'jet_engine' ) || empty( $raw_value ) ) {
            return $raw_value;
        }
        $meta_boxes_module = jet_engine()->meta_boxes;
        if ( ! is_object( $meta_boxes_module )
             || ! method_exists( $meta_boxes_module, 'get_registered_fields_for_context' ) ) {
            return $raw_value;
        }
        $meta_boxes = $meta_boxes_module->get_registered_fields_for_context( 'user' );
        if ( empty( $meta_boxes ) ) {
            return $raw_value;
        }
        foreach ( $meta_boxes as $fields ) {
            foreach ( $fields as $field ) {
                if ( isset( $field['name'] ) && $field['name'] === $meta_key && ! empty( $field['options'] ) ) {
                    foreach ( $field['options'] as $option ) {
                        if ( isset( $option['key'] ) && $option['key'] === $raw_value ) {
                            return $option['value'];
                        }
                    }
                }
            }
        }
        return $raw_value;
    }
```

- [ ] **Step 8: Update enrich_ticket_for_detail() to use JetEngine label with multi-value support**

Find `enrich_ticket_for_detail()` and replace the department block:
```php
        // Department from JetEngine user meta — resolve each slug to its human-readable label.
        $dept_raw = get_user_meta( $row['creator_user_id'], 'housman_learning_department', true );
        if ( is_array( $dept_raw ) ) {
            // Multi-value: resolve each slug individually, then join.
            $dept_labels = array_map( function( $v ) {
                return $this->get_jet_meta_label( 'housman_learning_department', sanitize_text_field( $v ) );
            }, $dept_raw );
            $dept_label = implode( ', ', $dept_labels );
        } else {
            $dept_raw   = sanitize_text_field( (string) $dept_raw );
            $dept_label = $this->get_jet_meta_label( 'housman_learning_department', $dept_raw );
        }
        $row['creator_department'] = ! empty( $dept_label ) ? $dept_label : __( 'Not assigned', 'hl-core' );
```

- [ ] **Step 9: Add save_draft() method**

After the closing `}` of `create_ticket()`, add:

```php
    /**
     * Save a ticket as a draft (relaxed validation — only title required).
     *
     * If $uuid is provided, updates the existing draft. Otherwise creates a new one.
     * Description, category, and type are all optional (category defaults to 'other',
     * type defaults to 'bug') to allow saving partial work.
     *
     * @param array       $data { title, type, priority, category, description, context_mode, context_user_id }
     * @param string|null $uuid Existing draft UUID to update, or null to create.
     * @return array|WP_Error Draft ticket or error.
     */
    public function save_draft( $data, $uuid = null ) {
        global $wpdb;

        $title        = isset( $data['title'] ) ? sanitize_text_field( trim( $data['title'] ) ) : '';
        $type         = isset( $data['type'] ) && in_array( $data['type'], self::VALID_TYPES, true )
            ? $data['type'] : 'bug';
        $priority     = isset( $data['priority'] ) && in_array( $data['priority'], self::VALID_PRIORITIES, true )
            ? $data['priority'] : 'medium';
        $description  = isset( $data['description'] ) ? wp_kses_post( trim( $data['description'] ) ) : '';
        $category     = isset( $data['category'] ) && in_array( $data['category'], self::VALID_CATEGORIES, true )
            ? $data['category'] : 'other';
        $context_mode = isset( $data['context_mode'] ) && $data['context_mode'] === 'view_as'
            ? 'view_as' : 'self';
        // Use explicit null-check (not empty()) so user ID 0 is not treated as "provided".
        $context_user = ( $context_mode === 'view_as' && isset( $data['context_user_id'] )
                          && $data['context_user_id'] !== null && $data['context_user_id'] !== '' )
            ? absint( $data['context_user_id'] ) : null;
        // Treat 0 (absint of empty string) as not provided.
        if ( $context_user === 0 ) {
            $context_user = null;
        }

        if ( empty( $title ) ) {
            return new WP_Error( 'missing_title', __( 'Title is required to save a draft.', 'hl-core' ) );
        }

        if ( $uuid ) {
            // Update existing draft.
            $ticket = $this->get_ticket_raw( $uuid );
            if ( ! $ticket ) {
                return new WP_Error( 'not_found', __( 'Ticket not found.', 'hl-core' ) );
            }
            if ( $ticket['status'] !== self::DRAFT_STATUS ) {
                return new WP_Error( 'not_draft', __( 'Only draft tickets can be updated via save draft.', 'hl-core' ) );
            }
            $user_id = get_current_user_id();
            if ( (int) $ticket['creator_user_id'] !== $user_id && ! $this->is_ticket_admin() ) {
                return new WP_Error( 'forbidden', __( 'You do not have permission to edit this draft.', 'hl-core' ) );
            }

            // Use a raw query to correctly write NULL for context_user_id.
            // $wpdb->update() with any format sends '' for PHP null, which MySQL coerces to 0.
            $ctx_sql      = ( $context_user !== null ) ? 'context_user_id = %d,' : 'context_user_id = NULL,';
            $update_vals  = array_merge(
                array( $title, $type, $priority, $category, $description, $context_mode ),
                $context_user !== null ? array( $context_user ) : array(),
                array( current_time( 'mysql' ), $uuid )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}hl_ticket
                     SET title = %s, type = %s, priority = %s, category = %s,
                         description = %s, context_mode = %s, {$ctx_sql} updated_at = %s
                     WHERE ticket_uuid = %s",
                    $update_vals
                )
            );

            return $this->get_ticket( $uuid );
        }

        // Create new draft.
        $new_uuid    = HL_DB_Utils::generate_uuid();
        $now         = current_time( 'mysql' );
        $insert_data = array(
            'ticket_uuid'     => $new_uuid,
            'title'           => $title,
            'description'     => $description,
            'type'            => $type,
            'priority'        => $priority,
            'category'        => $category,
            'status'          => self::DRAFT_STATUS,
            'creator_user_id' => get_current_user_id(),
            'context_mode'    => $context_mode,
            'created_at'      => $now,
            'updated_at'      => $now,
        );
        // Omit context_user_id when null so MySQL uses the column DEFAULT NULL.
        // $wpdb->insert() with any format sends '' for PHP null, which coerces to 0 in unsigned bigint.
        if ( $context_user !== null ) {
            $insert_data['context_user_id'] = $context_user;
        }
        $result = $wpdb->insert( $wpdb->prefix . 'hl_ticket', $insert_data );

        if ( ! $result ) {
            return new WP_Error( 'db_error', __( 'Failed to save draft.', 'hl-core' ) );
        }

        return $this->get_ticket( $new_uuid );
    }
```

- [ ] **Step 10: Add publish_draft() method**

After `save_draft()`, add:

```php
    /**
     * Publish a draft ticket (full validation, then sets status to 'open').
     *
     * Accepts updated form data so the user can edit fields before publishing.
     * Falls back to the stored draft's type/priority/category when not provided.
     *
     * @param string $uuid Ticket UUID.
     * @param array  $data { title, type, category, description, priority, context_mode, context_user_id }
     * @return array|WP_Error Published ticket or error.
     */
    public function publish_draft( $uuid, $data ) {
        global $wpdb;

        $ticket = $this->get_ticket_raw( $uuid );
        if ( ! $ticket ) {
            return new WP_Error( 'not_found', __( 'Ticket not found.', 'hl-core' ) );
        }
        if ( $ticket['status'] !== self::DRAFT_STATUS ) {
            return new WP_Error( 'not_draft', __( 'Only draft tickets can be published.', 'hl-core' ) );
        }
        $user_id = get_current_user_id();
        if ( (int) $ticket['creator_user_id'] !== $user_id && ! $this->is_ticket_admin() ) {
            return new WP_Error( 'forbidden', __( 'You do not have permission to publish this draft.', 'hl-core' ) );
        }

        // Apply latest form data; fall back to stored values for unprovided fields.
        $title       = isset( $data['title'] ) ? sanitize_text_field( trim( $data['title'] ) ) : $ticket['title'];
        $type        = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : '';
        $type        = in_array( $type, self::VALID_TYPES, true ) ? $type : $ticket['type'];
        $priority    = isset( $data['priority'] ) && in_array( $data['priority'], self::VALID_PRIORITIES, true )
            ? $data['priority'] : $ticket['priority'];
        $description = isset( $data['description'] ) ? wp_kses_post( trim( $data['description'] ) ) : $ticket['description'];
        $category    = isset( $data['category'] ) ? sanitize_text_field( $data['category'] ) : '';
        $category    = in_array( $category, self::VALID_CATEGORIES, true ) ? $category : $ticket['category'];

        $context_mode = isset( $data['context_mode'] ) && $data['context_mode'] === 'view_as' ? 'view_as' : 'self';
        $context_user = null;

        if ( $context_mode === 'self' ) {
            $context_user = null;
        } elseif ( $context_mode === 'view_as' ) {
            $provided_id  = isset( $data['context_user_id'] ) && $data['context_user_id'] !== ''
                ? absint( $data['context_user_id'] ) : null;
            $context_user = $provided_id ?: ( ! empty( $ticket['context_user_id'] ) ? (int) $ticket['context_user_id'] : null );
            if ( ! $context_user ) {
                return new WP_Error( 'missing_context_user', __( 'Please select the user you were viewing as.', 'hl-core' ) );
            }
            if ( ! get_userdata( $context_user ) ) {
                return new WP_Error( 'invalid_context_user', __( 'The selected user does not exist.', 'hl-core' ) );
            }
        }

        // Full validation — same rules as create_ticket().
        if ( empty( $title ) ) {
            return new WP_Error( 'missing_title', __( 'Title is required.', 'hl-core' ) );
        }
        if ( strlen( $title ) > 255 ) {
            return new WP_Error( 'title_too_long', __( 'Title must be 255 characters or fewer.', 'hl-core' ) );
        }
        if ( ! in_array( $type, self::VALID_TYPES, true ) ) {
            return new WP_Error( 'invalid_type', __( 'Please select a ticket type before publishing.', 'hl-core' ) );
        }
        if ( ! in_array( $category, self::VALID_CATEGORIES, true ) ) {
            return new WP_Error( 'invalid_category', __( 'Please select a category before publishing.', 'hl-core' ) );
        }
        if ( empty( $description ) ) {
            return new WP_Error( 'missing_description', __( 'Description is required before publishing.', 'hl-core' ) );
        }

        // Use a raw query to correctly write NULL for context_user_id.
        // $wpdb->update() with any format sends '' for PHP null, which MySQL coerces to 0.
        $ctx_sql  = ( $context_user !== null ) ? 'context_user_id = %d,' : 'context_user_id = NULL,';
        $pub_vals = array_merge(
            array( $title, $type, $priority, $category, $description, $context_mode ),
            $context_user !== null ? array( $context_user ) : array(),
            array( 'open', current_time( 'mysql' ), $uuid )
        );
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}hl_ticket
                 SET title = %s, type = %s, priority = %s, category = %s,
                     description = %s, context_mode = %s, {$ctx_sql} status = %s, updated_at = %s
                 WHERE ticket_uuid = %s",
                $pub_vals
            )
        );

        HL_Audit_Service::log( 'ticket_published', array(
            'entity_type' => 'ticket',
            'entity_id'   => $ticket['ticket_id'],
            'before_data' => array( 'status' => self::DRAFT_STATUS ),
            'after_data'  => array(
                'status'   => 'open',
                'title'    => $title,
                'type'     => $type,
                'category' => $category,
            ),
        ) );

        return $this->get_ticket( $uuid );
    }
```

- [ ] **Step 11: Commit**

```bash
git add includes/services/class-hl-ticket-service.php
git commit -m "feat(tickets): draft support — save_draft(), publish_draft(), visibility filtering, JetEngine label"
```

---

## Task 4: Frontend PHP — Form Reorder + Grid + Draft Buttons + Dept Label + AJAX Handlers

**Files:**
- Modify: `includes/frontend/class-hl-frontend-feature-tracker.php`

- [ ] **Step 1: Register two new AJAX actions in __construct()**

After:
```php
        add_action( 'wp_ajax_hl_ticket_user_search', array( $this, 'ajax_user_search' ) );
```
Add:
```php
        add_action( 'wp_ajax_hl_ticket_save_draft',    array( $this, 'ajax_ticket_save_draft' ) );
        add_action( 'wp_ajax_hl_ticket_publish_draft', array( $this, 'ajax_ticket_publish_draft' ) );
```

- [ ] **Step 2: Update render() — resolve department label via JetEngine with multi-value support**

Replace the department block (lines ~55-64):
```php
        $dept_raw = get_user_meta( get_current_user_id(), 'housman_learning_department', true );
        $service  = HL_Ticket_Service::instance();
        if ( is_array( $dept_raw ) ) {
            $dept_labels = array_map( function( $v ) use ( $service ) {
                return $service->get_jet_meta_label( 'housman_learning_department', sanitize_text_field( $v ) );
            }, $dept_raw );
            $current_user_dept = implode( ', ', $dept_labels );
        } else {
            $dept_raw          = sanitize_text_field( (string) $dept_raw );
            $current_user_dept = $service->get_jet_meta_label( 'housman_learning_department', $dept_raw );
        }
        if ( empty( $current_user_dept ) ) {
            $current_user_dept = __( 'Not assigned', 'hl-core' );
        }
```

- [ ] **Step 3: Add "My Drafts" option to the status filter dropdown**

In the status filter select, add between "Closed" and "All Statuses":
```php
                        <option value="draft"><?php esc_html_e( 'My Drafts', 'hl-core' ); ?></option>
```

- [ ] **Step 4: Replace the form HTML with the reordered, grid-wrapped version**

Find the entire `<form id="hlft-ticket-form">` block and replace it with:

```php
                        <form id="hlft-ticket-form">
                            <input type="hidden" id="hlft-form-uuid" value="">
                            <div class="hlft-form-grid">
                                <!-- Row 1: Title (full width) -->
                                <div class="hlft-form-group hlft-form-group--full">
                                    <label for="hlft-form-title-input"><?php esc_html_e( 'Title', 'hl-core' ); ?> <span class="required">*</span></label>
                                    <input type="text" id="hlft-form-title-input" maxlength="255" required>
                                </div>
                                <!-- Row 2: Category + Type (2 columns) -->
                                <div class="hlft-form-group">
                                    <label for="hlft-form-category"><?php esc_html_e( 'Category', 'hl-core' ); ?> <span class="required">*</span></label>
                                    <select id="hlft-form-category" required>
                                        <option value="" disabled selected><?php esc_html_e( 'Select category...', 'hl-core' ); ?></option>
                                        <option value="course_content"><?php esc_html_e( 'Course Content', 'hl-core' ); ?></option>
                                        <option value="platform_issue"><?php esc_html_e( 'Platform Issue', 'hl-core' ); ?></option>
                                        <option value="account_access"><?php esc_html_e( 'Account & Access', 'hl-core' ); ?></option>
                                        <option value="forms_assessments"><?php esc_html_e( 'Forms & Assessments', 'hl-core' ); ?></option>
                                        <option value="reports_data"><?php esc_html_e( 'Reports & Data', 'hl-core' ); ?></option>
                                        <option value="other"><?php esc_html_e( 'Other', 'hl-core' ); ?></option>
                                    </select>
                                </div>
                                <div class="hlft-form-group">
                                    <label for="hlft-form-type"><?php esc_html_e( 'Type', 'hl-core' ); ?> <span class="required">*</span></label>
                                    <select id="hlft-form-type" required>
                                        <option value=""><?php esc_html_e( 'Select type...', 'hl-core' ); ?></option>
                                        <option value="bug"><?php esc_html_e( 'Bug — Something is broken or not working correctly', 'hl-core' ); ?></option>
                                        <option value="improvement"><?php esc_html_e( 'Improvement — An existing feature could work better', 'hl-core' ); ?></option>
                                        <option value="feature_request"><?php esc_html_e( "Feature Request — A new capability that doesn't exist yet", 'hl-core' ); ?></option>
                                    </select>
                                </div>
                                <!-- Row 3: Priority + Encountered as (2 columns) -->
                                <div class="hlft-form-group">
                                    <label for="hlft-form-priority"><?php esc_html_e( 'Priority', 'hl-core' ); ?></label>
                                    <select id="hlft-form-priority">
                                        <option value="low"><?php esc_html_e( 'Low', 'hl-core' ); ?></option>
                                        <option value="medium" selected><?php esc_html_e( 'Medium', 'hl-core' ); ?></option>
                                        <option value="high"><?php esc_html_e( 'High', 'hl-core' ); ?></option>
                                        <option value="critical"><?php esc_html_e( 'Critical', 'hl-core' ); ?></option>
                                    </select>
                                </div>
                                <div class="hlft-form-group">
                                    <label for="hlft-form-context-mode"><?php esc_html_e( 'Encountered as', 'hl-core' ); ?></label>
                                    <select id="hlft-form-context-mode">
                                        <option value="self"><?php esc_html_e( 'Myself', 'hl-core' ); ?></option>
                                        <option value="view_as"><?php esc_html_e( 'Viewing as another user', 'hl-core' ); ?></option>
                                    </select>
                                </div>
                                <!-- Row 4: Department (full width, read-only) -->
                                <div class="hlft-form-group hlft-form-group--full">
                                    <label><?php esc_html_e( 'Department', 'hl-core' ); ?></label>
                                    <div class="hlft-dept-readonly" id="hlft-form-department"></div>
                                </div>
                                <!-- Row 5: Context user search (full width, shown when view_as) -->
                                <div class="hlft-form-group hlft-form-group--full hlft-context-user-wrap" id="hlft-context-user-wrap" style="display:none;">
                                    <input type="hidden" id="hlft-form-context-user-id" value="">
                                    <div class="hlft-user-search-wrap">
                                        <input type="text" id="hlft-user-search-input" placeholder="<?php esc_attr_e( 'Search by name...', 'hl-core' ); ?>" autocomplete="off">
                                        <div class="hlft-user-search-results" id="hlft-user-search-results" style="display:none;"></div>
                                    </div>
                                    <div class="hlft-context-user-chip" id="hlft-context-user-chip" style="display:none;"></div>
                                </div>
                                <!-- Row 6: Description (full width) -->
                                <div class="hlft-form-group hlft-form-group--full">
                                    <label for="hlft-form-description"><?php esc_html_e( 'Description', 'hl-core' ); ?> <span class="required">*</span></label>
                                    <textarea id="hlft-form-description" rows="6" required></textarea>
                                </div>
                                <!-- Row 7: Attachments (full width) -->
                                <div class="hlft-form-group hlft-form-group--full">
                                    <label><?php esc_html_e( 'Attachments', 'hl-core' ); ?></label>
                                    <div class="hlft-upload-area" id="hlft-form-upload-area">
                                        <input type="file" id="hlft-form-file" accept="image/*" multiple style="display:none;">
                                        <button type="button" class="hl-btn hl-btn-small" id="hlft-form-attach-btn"><span class="dashicons dashicons-paperclip"></span> <?php esc_html_e( 'Attach Images', 'hl-core' ); ?></button>
                                        <span class="hlft-upload-hint"><?php esc_html_e( 'JPG, PNG, GIF, WebP — max 5MB each', 'hl-core' ); ?></span>
                                        <div class="hlft-upload-preview" id="hlft-form-preview"></div>
                                    </div>
                                </div>
                            </div><!-- /.hlft-form-grid -->
                            <div class="hlft-form-actions">
                                <button type="submit" class="hl-btn hl-btn-primary" id="hlft-form-submit"><?php esc_html_e( 'Submit', 'hl-core' ); ?></button>
                                <button type="button" class="hl-btn hl-btn-secondary" id="hlft-form-draft" style="display:none;"><?php esc_html_e( 'Save as Draft', 'hl-core' ); ?></button>
                                <button type="button" class="hl-btn" data-close-modal><?php esc_html_e( 'Cancel', 'hl-core' ); ?></button>
                            </div>
                        </form>
```

- [ ] **Step 5: Add ajax_ticket_save_draft() method**

After the closing `}` of `ajax_user_search()`, add:

```php
    public function ajax_ticket_save_draft() {
        $this->verify_ajax();
        $service = HL_Ticket_Service::instance();

        $uuid = isset( $_POST['ticket_uuid'] ) && ! empty( $_POST['ticket_uuid'] )
            ? sanitize_text_field( $_POST['ticket_uuid'] ) : null;

        // Pass null (not 0) for empty context_user_id to avoid overwriting valid values.
        $ctx_uid = isset( $_POST['context_user_id'] ) && $_POST['context_user_id'] !== ''
            ? absint( $_POST['context_user_id'] ) : null;

        $data = array(
            'title'           => isset( $_POST['title'] )        ? sanitize_text_field( $_POST['title'] )         : '',
            'type'            => isset( $_POST['type'] )         ? sanitize_text_field( $_POST['type'] )          : '',
            'priority'        => isset( $_POST['priority'] )     ? sanitize_text_field( $_POST['priority'] )      : 'medium',
            'category'        => isset( $_POST['category'] )     ? sanitize_text_field( $_POST['category'] )      : '',
            'description'     => isset( $_POST['description'] )  ? wp_kses_post( $_POST['description'] )          : '',
            'context_mode'    => isset( $_POST['context_mode'] ) ? sanitize_text_field( $_POST['context_mode'] )  : 'self',
            'context_user_id' => $ctx_uid,
        );

        $result = $service->save_draft( $data, $uuid );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }
```

- [ ] **Step 6: Add ajax_ticket_publish_draft() method**

Immediately after `ajax_ticket_save_draft()`, add:

```php
    public function ajax_ticket_publish_draft() {
        $this->verify_ajax();
        $service = HL_Ticket_Service::instance();

        $uuid = isset( $_POST['ticket_uuid'] ) ? sanitize_text_field( $_POST['ticket_uuid'] ) : '';
        if ( empty( $uuid ) ) {
            wp_send_json_error( __( 'Ticket UUID is required.', 'hl-core' ) );
            return; // Defensive — wp_send_json_error() calls wp_die() but return makes intent clear.
        }

        $ctx_uid = isset( $_POST['context_user_id'] ) && $_POST['context_user_id'] !== ''
            ? absint( $_POST['context_user_id'] ) : null;

        $data = array(
            'title'           => isset( $_POST['title'] )        ? sanitize_text_field( $_POST['title'] )         : '',
            'type'            => isset( $_POST['type'] )         ? sanitize_text_field( $_POST['type'] )          : '',
            'priority'        => isset( $_POST['priority'] )     ? sanitize_text_field( $_POST['priority'] )      : 'medium',
            'category'        => isset( $_POST['category'] )     ? sanitize_text_field( $_POST['category'] )      : '',
            'description'     => isset( $_POST['description'] )  ? wp_kses_post( $_POST['description'] )          : '',
            'context_mode'    => isset( $_POST['context_mode'] ) ? sanitize_text_field( $_POST['context_mode'] )  : 'self',
            'context_user_id' => $ctx_uid,
        );

        $result = $service->publish_draft( $uuid, $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
            return;
        }

        wp_send_json_success( $result );
    }
```

- [ ] **Step 7: Commit**

```bash
git add includes/frontend/class-hl-frontend-feature-tracker.php
git commit -m "feat(tickets): form grid layout, draft buttons, AJAX handlers for save/publish draft"
```

---

## Task 5: JavaScript — Preview Remove, Draft Flow, Dirty State, Close Confirmation

**Files:**
- Modify: `assets/js/frontend.js`

All changes are within the `jQuery(function($) { ... })` wrapper. Use the code patterns below to locate each section.

- [ ] **Step 1: Add statusLabels draft entry**

Find:
```javascript
            var statusLabels = { open: 'Open', in_review: 'In Review', in_progress: 'In Progress', resolved: 'Resolved', closed: 'Closed' };
```
Replace with:
```javascript
            var statusLabels = { draft: 'Draft', open: 'Open', in_review: 'In Review', in_progress: 'In Progress', resolved: 'Resolved', closed: 'Closed' };
```

- [ ] **Step 2: Add data-status attribute to table rows for draft detection**

Find the row-building code (`var row = '<tr data-uuid=...`). Change:
```javascript
                        var row = '<tr data-uuid="' + esc(t.ticket_uuid) + '">' +
```
To:
```javascript
                        var row = '<tr data-uuid="' + esc(t.ticket_uuid) + '" data-status="' + esc(t.status) + '">' +
```

- [ ] **Step 3: Update row click handler to open drafts in edit mode**

Find and replace the row click handler:
```javascript
            $(document).on('click', '#hlft-table-body tr', function() {
                var uuid = $(this).data('uuid');
                var status = $(this).data('status');
                if (!uuid) return;
                if (status === 'draft') {
                    // Drafts have no detail view — open directly in edit mode.
                    ajax('hl_ticket_get', { ticket_uuid: uuid }, function(t) {
                        openEditModal(t);
                    });
                } else {
                    openDetail(uuid);
                }
            });
```

- [ ] **Step 4: Add state flags near pendingFormFiles declaration**

Find:
```javascript
            var pendingFormFiles = [];
            var pendingCommentFiles = [];
```
Replace with:
```javascript
            var pendingFormFiles = [];
            var pendingCommentFiles = [];
            var formDirty = false;     // true when user has changed any form field since modal opened
            var isDraftMode = false;   // true when creating new or editing an existing draft
            var isPublishMode = false; // true when editing a draft (submit = publish)
```

- [ ] **Step 5: Add formDirty tracking listener on form inputs**

After the flags declaration, add:
```javascript
            // Track dirty state on any form field change.
            $('#hlft-ticket-form').on('input change', 'input, select, textarea', function() {
                formDirty = true;
            });
```

- [ ] **Step 6: Set formDirty = true in the image paste handler for the form**

Find the paste binding that calls `handleImagePaste` for `pendingFormFiles`. It looks like:
```javascript
                handleImagePaste(e, pendingFormFiles, $('#hlft-form-preview'));
```
Add `formDirty = true;` on the line after that call:
```javascript
                handleImagePaste(e, pendingFormFiles, $('#hlft-form-preview'));
                formDirty = true; // Pasting images counts as a form change
```

- [ ] **Step 7: Update openCreateModal() for draft state**

Replace the entire `openCreateModal()` function:
```javascript
            function openCreateModal() {
                $('#hlft-form-title').text('New Ticket');
                $('#hlft-form-uuid').val('');
                $('#hlft-form-title-input').val('');
                $('#hlft-form-category').prop('selectedIndex', 0);
                $('#hlft-form-type').val('');
                $('#hlft-form-priority').val('medium');
                $('#hlft-form-description').val('');
                $('#hlft-form-file').val('');
                $('#hlft-form-preview').empty();
                pendingFormFiles = [];
                var dept = $wrap.data('user-department') || 'Not assigned';
                var deptClass = dept === 'Not assigned' ? ' hlft-dept-empty' : '';
                $('#hlft-form-department').attr('class', 'hlft-dept-readonly' + deptClass).text(dept);
                $('#hlft-form-context-mode').val('self');
                $('#hlft-context-user-wrap').hide();
                $('#hlft-form-context-user-id').val('');
                $('#hlft-context-user-chip').hide().empty();
                $('#hlft-user-search-input').val('');
                $('#hlft-user-search-results').hide().empty();
                userSearchResults = [];
                clearTimeout(userSearchTimer);
                if (userSearchXhr && userSearchXhr.readyState !== 4) { userSearchXhr.abort(); }
                formDirty = false;
                isDraftMode = true;
                isPublishMode = false;
                $('#hlft-form-submit').text('Submit').prop('disabled', false);
                $('#hlft-form-draft').show();
                $('#hlft-form-modal').show();
                $('#hlft-form-title-input').focus();
            }
```

- [ ] **Step 8: Update openEditModal() for draft state**

Replace the entire `openEditModal(ticket)` function:
```javascript
            function openEditModal(ticket) {
                var isDraft = ticket.status === 'draft';
                $('#hlft-form-title').text(isDraft ? 'Edit Draft' : 'Edit Ticket');
                $('#hlft-form-uuid').val(ticket.ticket_uuid);
                $('#hlft-form-title-input').val(ticket.title);
                $('#hlft-form-category').val(ticket.category || '');
                $('#hlft-form-type').val(ticket.type);
                $('#hlft-form-priority').val(ticket.priority);
                $('#hlft-form-description').val(ticket.description);
                pendingFormFiles = [];
                $('#hlft-form-file').val('');
                $('#hlft-form-preview').empty();
                var dept = ticket.creator_department || $wrap.data('user-department') || 'Not assigned';
                var deptClass = dept === 'Not assigned' ? ' hlft-dept-empty' : '';
                $('#hlft-form-department').attr('class', 'hlft-dept-readonly' + deptClass).text(dept);
                $('#hlft-form-context-mode').val(ticket.context_mode || 'self');
                if (ticket.context_mode === 'view_as' && ticket.context_user_name) {
                    $('#hlft-context-user-wrap').show();
                    $('#hlft-form-context-user-id').val(ticket.context_user_id);
                    var chipHtml = '';
                    if (ticket.context_user_avatar) {
                        chipHtml += '<img class="hlft-avatar" src="' + esc(ticket.context_user_avatar) + '" alt=""> ';
                    }
                    chipHtml += esc(ticket.context_user_name) +
                        ' <button type="button" class="hlft-chip-remove" title="Remove">&times;</button>';
                    $('#hlft-context-user-chip').show().html(chipHtml);
                    $('#hlft-user-search-input').val('').hide();
                } else {
                    $('#hlft-context-user-wrap').hide();
                    $('#hlft-form-context-user-id').val('');
                    $('#hlft-context-user-chip').hide().empty();
                    $('#hlft-user-search-input').val('').show();
                }
                $('#hlft-user-search-results').hide().empty();
                userSearchResults = [];
                clearTimeout(userSearchTimer);
                if (userSearchXhr && userSearchXhr.readyState !== 4) { userSearchXhr.abort(); }
                formDirty = false;
                isDraftMode = isDraft;
                isPublishMode = isDraft;
                if (isDraft) {
                    $('#hlft-form-submit').text('Publish').prop('disabled', false);
                    $('#hlft-form-draft').show();
                } else {
                    $('#hlft-form-submit').text('Save Changes').prop('disabled', false);
                    $('#hlft-form-draft').hide();
                }
                $('#hlft-detail-modal').hide();
                $('#hlft-form-modal').show();
                $('#hlft-form-title-input').focus();
            }
```

- [ ] **Step 9: Add showConfirmDialog() helper function**

After `openEditModal()`, add:
```javascript
            function showConfirmDialog(message, buttons) {
                // If a confirm dialog is already showing, don't create another.
                if ($('#hlft-confirm-overlay').length) { return; }
                var btnsHtml = '';
                $.each(buttons, function(i, b) {
                    btnsHtml += '<button type="button" class="hl-btn hlft-confirm-btn" data-idx="' + i + '">' + esc(b.label) + '</button>';
                });
                var $overlay = $(
                    '<div id="hlft-confirm-overlay" class="hlft-confirm-overlay">' +
                    '<div class="hlft-confirm-dialog">' +
                    '<p>' + esc(message) + '</p>' +
                    '<div class="hlft-confirm-actions">' + btnsHtml + '</div>' +
                    '</div></div>'
                );
                $('#hlft-form-modal .hlft-modal-box').append($overlay);
                $overlay.on('click', '.hlft-confirm-btn', function() {
                    var idx = parseInt($(this).data('idx'), 10);
                    $overlay.remove();
                    if (buttons[idx] && typeof buttons[idx].action === 'function') {
                        buttons[idx].action();
                    }
                });
            }
```

- [ ] **Step 10: Replace closeModal() with dirty-state confirmation and guard**

Replace the entire `closeModal($modal)` function:
```javascript
            function closeModal($modal) {
                // If confirm overlay is already visible, ignore re-entry (backdrop click, ESC repeat).
                if ($modal.attr('id') === 'hlft-form-modal' && $('#hlft-confirm-overlay').length) {
                    return;
                }
                if ($modal.attr('id') === 'hlft-form-modal' && formDirty) {
                    var confirmBtns = [];
                    if (isDraftMode) {
                        confirmBtns.push({ label: 'Save as Draft', action: function() { saveDraft(); } });
                    }
                    confirmBtns.push({
                        label: 'Discard',
                        // formDirty must be false before the recursive call so the dirty-check
                        // branch is skipped on re-entry. This is intentional — not an infinite loop.
                        action: function() { formDirty = false; closeModal($modal); }
                    });
                    confirmBtns.push({ label: 'Keep Editing', action: function() {} });
                    showConfirmDialog('You have unsaved changes.', confirmBtns);
                    return;
                }
                $modal.hide();
                // Reset flags on close so stale state does not bleed into the next session.
                if ($modal.attr('id') === 'hlft-form-modal') {
                    var wasDraftMode = isDraftMode; // capture BEFORE reset
                    isDraftMode = false;
                    isPublishMode = false;
                    if (currentUuid && $('#hlft-form-uuid').val() && !wasDraftMode) {
                        // Reopen detail only when closing an edit form for a non-draft ticket.
                        openDetail(currentUuid);
                    }
                }
            }
```

**Note:** `wasDraftMode` is captured BEFORE `isDraftMode` is reset to `false`. Using `!wasDraftMode` correctly skips detail reopen for drafts. Using `isDraftMode` directly (after reset) would always be `false` and always reopen detail — even for drafts.

- [ ] **Step 11: Add saveDraft() standalone function**

After `closeModal()`, add:
```javascript
            function saveDraft() {
                formDirty = false; // Optimistic reset: prevents double-trigger if user re-clicks Cancel while AJAX is in-flight.
                var $btn = $('#hlft-form-draft');
                $btn.prop('disabled', true).text('Saving...');
                var uuid = $('#hlft-form-uuid').val();
                var data = {
                    title:           $('#hlft-form-title-input').val(),
                    category:        $('#hlft-form-category').val(),
                    type:            $('#hlft-form-type').val(),
                    priority:        $('#hlft-form-priority').val(),
                    description:     $('#hlft-form-description').val(),
                    context_mode:    $('#hlft-form-context-mode').val(),
                    context_user_id: $('#hlft-form-context-user-id').val() || ''
                };
                if (uuid) data.ticket_uuid = uuid;

                var resetBtn = function() {
                    formDirty = true; // Restore dirty state so user can retry or close normally.
                    $btn.prop('disabled', false).text('Save as Draft');
                    showToast('Failed to save draft. Please try again.', true);
                };

                ajax('hl_ticket_save_draft', data, function(t) {
                    // Write the UUID back to the form — if a new draft was created and the upload
                    // fails, re-clicking "Save as Draft" must UPDATE the existing draft, not INSERT another.
                    $('#hlft-form-uuid').val(t.ticket_uuid);
                    var afterUpload = function() {
                        pendingFormFiles = [];
                        formDirty = false;
                        currentUuid = null; // Drafts have no detail view — clear stale detail UUID.
                        $btn.prop('disabled', false).text('Save as Draft');
                        $('#hlft-form-modal').hide();
                        showToast('Draft saved');
                        loadTickets();
                    };
                    if (pendingFormFiles.length) {
                        uploadFiles(pendingFormFiles, t.ticket_uuid, null, afterUpload);
                    } else {
                        afterUpload();
                    }
                }, resetBtn);
            }
```

- [ ] **Step 12: Bind the "Save as Draft" button**

After the `$('#hlft-new-ticket-btn').on('click', openCreateModal);` line, add:
```javascript
            // Save as Draft button
            $('#hlft-form-draft').on('click', saveDraft);
```

- [ ] **Step 13: Replace the entire form submit handler**

Find the entire `$('#hlft-ticket-form').on('submit', function(e) { ... });` block and replace it completely with:

```javascript
            // Form submit (create, update, or publish draft)
            $('#hlft-ticket-form').on('submit', function(e) {
                e.preventDefault();
                var $btn = $('#hlft-form-submit');
                var uuid = $('#hlft-form-uuid').val();
                var isEdit = !!uuid;

                // Capture current flag values to avoid race conditions if flags change mid-flight.
                var wasPublishMode = isPublishMode;

                var btnLabel = wasPublishMode ? 'Publishing...' : (isEdit ? 'Saving...' : 'Submitting...');
                $btn.prop('disabled', true).text(btnLabel);

                var data = {
                    title:           $('#hlft-form-title-input').val(),
                    category:        $('#hlft-form-category').val(),
                    type:            $('#hlft-form-type').val(),
                    priority:        $('#hlft-form-priority').val(),
                    description:     $('#hlft-form-description').val(),
                    context_mode:    $('#hlft-form-context-mode').val(),
                    context_user_id: $('#hlft-form-context-user-id').val() || ''
                };

                // Submission guard: view_as mode requires a selected user.
                if (data.context_mode === 'view_as' && !data.context_user_id) {
                    showToast('Please select the user you were viewing as.', true);
                    $btn.prop('disabled', false).text(wasPublishMode ? 'Publish' : (isEdit ? 'Save Changes' : 'Submit'));
                    return;
                }

                var resetBtn = function() {
                    $btn.prop('disabled', false).text(wasPublishMode ? 'Publish' : (isEdit ? 'Save Changes' : 'Submit'));
                };

                if (wasPublishMode) {
                    data.ticket_uuid = uuid;
                    ajax('hl_ticket_publish_draft', data, function(t) {
                        var afterUpload = function() {
                            pendingFormFiles = [];
                            formDirty = false;
                            $btn.prop('disabled', false).text('Publish');
                            $('#hlft-form-modal').hide();
                            showToast('Ticket published');
                            openDetail(t.ticket_uuid);
                            loadTickets();
                        };
                        if (pendingFormFiles.length) {
                            uploadFiles(pendingFormFiles, t.ticket_uuid, null, afterUpload);
                        } else {
                            afterUpload();
                        }
                    }, resetBtn);
                } else if (isEdit) {
                    data.ticket_uuid = uuid;
                    ajax('hl_ticket_update', data, function(t) {
                        var afterUpload = function() {
                            pendingFormFiles = [];
                            formDirty = false;
                            $btn.prop('disabled', false).text('Save Changes');
                            $('#hlft-form-modal').hide();
                            showToast('Ticket updated');
                            openDetail(t.ticket_uuid);
                            loadTickets();
                        };
                        if (pendingFormFiles.length) {
                            uploadFiles(pendingFormFiles, t.ticket_uuid, null, afterUpload);
                        } else {
                            afterUpload();
                        }
                    }, resetBtn);
                } else {
                    ajax('hl_ticket_create', data, function(t) {
                        var afterUpload = function() {
                            pendingFormFiles = [];
                            formDirty = false;
                            $btn.prop('disabled', false).text('Submit');
                            $('#hlft-form-modal').hide();
                            showToast('Ticket #' + t.ticket_id + ' created');
                            currentUuid = null;
                            loadTickets();
                        };
                        if (pendingFormFiles.length) {
                            uploadFiles(pendingFormFiles, t.ticket_uuid, null, afterUpload);
                        } else {
                            afterUpload();
                        }
                    }, resetBtn);
                }
            });
```

- [ ] **Step 14: Replace showFilePreview() with generation-counter version**

Find the existing `showFilePreview()` function and replace it entirely:
```javascript
            // Show file preview thumbnails.
            // Uses a generation counter to prevent stale async FileReader callbacks
            // from re-appending to a container that has already been re-rendered.
            function showFilePreview($container, files) {
                $container.empty();
                var gen = ($container.data('previewGen') || 0) + 1;
                $container.data('previewGen', gen);
                $.each(files, function(i, f) {
                    var reader = new FileReader();
                    reader.onload = (function(idx, capturedGen) {
                        return function(e) {
                            // Bail if a newer render has started (stale callback).
                            if ($container.data('previewGen') !== capturedGen) { return; }
                            $container.append(
                                '<div class="hlft-preview-item" data-index="' + idx + '">' +
                                '<img src="' + e.target.result + '" alt="">' +
                                '<button type="button" class="hlft-preview-remove" title="Remove">&times;</button>' +
                                '</div>'
                            );
                        };
                    }(i, gen));
                    reader.readAsDataURL(f);
                });
            }
```

- [ ] **Step 15: Add preview remove click handler**

After `showFilePreview()`, add:
```javascript
            // Remove pending file from preview and pending array.
            $(document).on('click', '.hlft-preview-remove', function(e) {
                e.stopPropagation();
                var $item = $(this).closest('.hlft-preview-item');
                var idx = parseInt($item.data('index'), 10);
                var $container = $item.closest('.hlft-upload-preview');

                if ($container.attr('id') === 'hlft-form-preview') {
                    pendingFormFiles.splice(idx, 1);
                    showFilePreview($('#hlft-form-preview'), pendingFormFiles);
                } else if ($container.attr('id') === 'hlft-comment-preview') {
                    pendingCommentFiles.splice(idx, 1);
                    showFilePreview($('#hlft-comment-preview'), pendingCommentFiles);
                }
            });
```

- [ ] **Step 16: Commit**

```bash
git add assets/js/frontend.js
git commit -m "feat(tickets): draft save/publish, dirty-state close confirmation, removable image previews"
```

---

## Task 6: E2E Tests

**Files:**
- Modify: `tests/e2e/feature-tracker.spec.js`

- [ ] **Step 1: Add new describe block for UX improvements tests**

Append before the final `});` that closes the outer `test.describe` block:

```javascript
test.describe('Feature Tracker UX Improvements', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(`${BASE_URL}/wp-login.php`);
        await page.fill('#user_login', USERNAME);
        await page.fill('#user_pass', PASSWORD);
        await page.click('#wp-submit');
        await page.waitForURL(/.*(?!wp-login).*/);
        await page.goto(`${BASE_URL}/feature-tracker/`);
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });
    });

    test('Form modal is wider than 520px (grid layout)', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });
        const box = await page.locator('#hlft-form-modal .hlft-modal-box--form').boundingBox();
        expect(box.width).toBeGreaterThan(520);
    });

    test('Category and Type appear on same row (2-column grid)', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });
        const catBox = await page.locator('#hlft-form-category').boundingBox();
        const typeBox = await page.locator('#hlft-form-type').boundingBox();
        // Same vertical position = same row
        expect(Math.abs(catBox.y - typeBox.y)).toBeLessThan(10);
        // Category is left of Type
        expect(typeBox.x).toBeGreaterThan(catBox.x + catBox.width - 10);
    });

    test('Image preview shows remove button and removing works', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        // Attach a tiny 1x1 transparent PNG (Playwright setInputFiles works on hidden file inputs)
        const pngBuffer = Buffer.from(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            'base64'
        );
        await page.locator('#hlft-form-file').setInputFiles({
            name: 'test.png',
            mimeType: 'image/png',
            buffer: pngBuffer,
        });

        await page.waitForSelector('.hlft-preview-item', { timeout: 5000 });
        await expect(page.locator('.hlft-preview-remove')).toBeVisible();

        // Click remove — preview item disappears
        await page.click('.hlft-preview-remove');
        await expect(page.locator('.hlft-preview-item')).toHaveCount(0);
    });

    test('Save as Draft button is visible when creating; creates draft in table', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });
        await expect(page.locator('#hlft-form-draft')).toBeVisible();

        const timestamp = Date.now();
        const title = `Draft Test ${timestamp}`;
        await page.fill('#hlft-form-title-input', title);
        await page.click('#hlft-form-draft');

        await page.waitForSelector('#hlft-toast', { state: 'visible', timeout: 10000 });
        expect(await page.locator('#hlft-toast').textContent()).toContain('Draft saved');

        await page.waitForSelector('#hlft-form-modal', { state: 'hidden', timeout: 5000 });
        await page.waitForTimeout(500);

        const row = page.locator(`#hlft-table-body tr:has-text("${title}")`);
        await expect(row).toBeVisible({ timeout: 5000 });
        await expect(row.locator('.hlft-status-pill--draft')).toBeVisible();
    });

    test('Draft row click opens edit modal with Publish button (not detail view)', async ({ page }) => {
        // Create a draft
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });
        const title = `Click Draft ${Date.now()}`;
        await page.fill('#hlft-form-title-input', title);
        await page.click('#hlft-form-draft');
        await page.waitForSelector('#hlft-form-modal', { state: 'hidden', timeout: 5000 });
        await page.waitForTimeout(500);

        // Click the draft row
        const row = page.locator(`#hlft-table-body tr:has-text("${title}")`);
        await expect(row).toBeVisible({ timeout: 5000 });
        await row.click();

        // Edit modal opens; detail modal stays hidden
        await page.waitForSelector('#hlft-form-modal', { state: 'visible', timeout: 5000 });
        await expect(page.locator('#hlft-detail-modal')).toBeHidden();
        await expect(page.locator('#hlft-form-submit')).toHaveText('Publish');
        await expect(page.locator('#hlft-form-draft')).toBeVisible();
    });

    test('Publish draft transitions ticket to open status', async ({ page }) => {
        // Create draft
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });
        const title = `Publish Draft ${Date.now()}`;
        await page.fill('#hlft-form-title-input', title);
        await page.click('#hlft-form-draft');
        await page.waitForSelector('#hlft-form-modal', { state: 'hidden', timeout: 5000 });
        await page.waitForTimeout(500);

        // Open draft in edit mode
        await page.locator(`#hlft-table-body tr:has-text("${title}")`).click();
        await page.waitForSelector('#hlft-form-modal', { state: 'visible', timeout: 5000 });

        // Fill required fields and click Publish
        await page.selectOption('#hlft-form-category', 'other');
        await page.selectOption('#hlft-form-type', 'bug');
        await page.fill('#hlft-form-description', 'Publishing this draft.');
        await page.click('#hlft-form-submit'); // "Publish" button

        await page.waitForSelector('#hlft-toast', { state: 'visible', timeout: 10000 });
        expect(await page.locator('#hlft-toast').textContent()).toContain('published');

        // Row should now show Open pill
        await page.waitForTimeout(500);
        const updatedRow = page.locator(`#hlft-table-body tr:has-text("${title}")`);
        await expect(updatedRow.locator('.hlft-status-pill--open')).toBeVisible({ timeout: 5000 });
    });

    test('Dirty state confirmation appears when closing with unsaved changes', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        // Make a change to trigger dirty state
        await page.fill('#hlft-form-title-input', 'Unsaved change');

        // Click Cancel — confirm dialog should appear
        await page.locator('[data-close-modal]').first().click();
        await expect(page.locator('#hlft-confirm-overlay')).toBeVisible({ timeout: 3000 });
        await expect(page.locator('.hlft-confirm-dialog')).toContainText('unsaved changes');
    });

    test('Dirty state: Discard closes modal', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });
        await page.fill('#hlft-form-title-input', 'Will be discarded');
        await page.locator('[data-close-modal]').first().click();
        await page.waitForSelector('#hlft-confirm-overlay', { state: 'visible', timeout: 3000 });
        await page.locator('.hlft-confirm-btn:has-text("Discard")').click();
        await page.waitForSelector('#hlft-form-modal', { state: 'hidden', timeout: 5000 });
    });

    test('Dirty state: Keep Editing keeps modal open', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });
        await page.fill('#hlft-form-title-input', 'Keeping');
        await page.locator('[data-close-modal]').first().click();
        await page.waitForSelector('#hlft-confirm-overlay', { state: 'visible', timeout: 3000 });
        await page.locator('.hlft-confirm-btn:has-text("Keep Editing")').click();
        await expect(page.locator('#hlft-confirm-overlay')).toBeHidden();
        await expect(page.locator('#hlft-form-modal')).toBeVisible();
    });
});
```

- [ ] **Step 2: Run tests against test server**

```bash
npx playwright test tests/e2e/feature-tracker.spec.js --reporter=list 2>&1 | tail -30
```

Expected: new "Feature Tracker UX Improvements" tests pass, previously passing tests remain green.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/feature-tracker.spec.js
git commit -m "test(tickets): E2E tests for grid layout, image remove, draft save/publish, dirty state"
```

---

## Self-Review Checklist

**All Spec Requirements Covered:**

| Requirement | Task |
|---|---|
| Modal width → 700px | Task 1 Step 1 |
| CSS grid 2-column, gap | Task 1 Step 1 |
| Grid margin reset (no double gap) | Task 1 Step 1 |
| Context-user-wrap margin reset | Task 1 Step 1 |
| Responsive collapse at 500px | Task 1 Step 1 |
| `position: relative` on modal box | Task 1 Step 1 |
| Preview padding-top for overflow button | Task 1 Step 2 |
| Preview remove button styles | Task 1 Step 2 |
| Draft status pill | Task 1 Step 3 |
| Confirm overlay styles | Task 1 Step 4 |
| Schema rev 33, MODIFY COLUMN | Task 2 Steps 1-3 |
| charset/collate in MODIFY COLUMN | Task 2 Step 3 |
| `get_schema()` enum updated | Task 2 Step 4 |
| `change_status()` blocks 'draft' | Task 3 Step 2 |
| `update_ticket()` blocks drafts | Task 3 Step 3 |
| `can_edit()` draft bypass | Task 3 Step 4 |
| `get_ticket()` draft visibility | Task 3 Step 5 |
| `get_tickets()` draft filter + admin bypass | Task 3 Step 6 |
| `get_jet_meta_label()` with null-safety | Task 3 Step 7 |
| JetEngine multi-value label resolution | Task 3 Step 8 |
| `save_draft()` with context_user null-safety | Task 3 Step 9 |
| `publish_draft()` with type fallback | Task 3 Step 10 |
| JetEngine label in `render()` | Task 4 Step 2 |
| "My Drafts" filter option | Task 4 Step 3 |
| Form field reorder + grid wrapper | Task 4 Step 4 |
| Draft buttons | Task 4 Step 4 |
| `ajax_ticket_save_draft` registration | Task 4 Step 1 |
| `ajax_ticket_publish_draft` registration | Task 4 Step 1 |
| `ajax_ticket_save_draft()` null context_user | Task 4 Step 5 |
| `ajax_ticket_publish_draft()` return guard | Task 4 Step 6 |
| `statusLabels` draft | Task 5 Step 1 |
| `data-status` on table rows | Task 5 Step 2 |
| Draft row click → edit mode | Task 5 Step 3 |
| `formDirty`, `isDraftMode`, `isPublishMode` | Task 5 Step 4 |
| Dirty tracking listener | Task 5 Step 5 |
| Paste handler sets formDirty | Task 5 Step 6 |
| `openCreateModal()` draft state | Task 5 Step 7 |
| `openEditModal()` draft state | Task 5 Step 8 |
| `showConfirmDialog()` with re-entry guard | Task 5 Step 9 |
| `closeModal()` dirty check + flag reset | Task 5 Step 10 |
| `saveDraft()` with currentUuid clear | Task 5 Step 11 |
| Draft button bound | Task 5 Step 12 |
| Submit handler with `wasPublishMode` + all branches have formDirty=false | Task 5 Step 13 |
| `showFilePreview()` generation counter + remove button | Task 5 Step 14 |
| Preview remove click handler | Task 5 Step 15 |

**No placeholders.** All steps contain actual code.
