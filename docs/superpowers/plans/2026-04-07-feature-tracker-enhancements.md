# Feature Tracker Enhancements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add category dropdown, read-only department field, "Viewing As" context field, and clipboard image paste to the Feature Tracker.

**Architecture:** 4 frontend enhancements layered on the existing jQuery AJAX single-page app. Backend changes in the service layer (validation, enrichment) and installer (schema migration). No new classes or files — all changes go into existing files.

**Tech Stack:** PHP 7.4+ / WordPress / jQuery / MySQL ENUM columns / WP AJAX

**Spec:** `docs/superpowers/specs/2026-04-07-feature-tracker-enhancements-design.md`

---

## File Map

| File | Role | Changes |
|---|---|---|
| `includes/class-hl-installer.php` | Schema + migrations | Add 3 columns to CREATE TABLE, add `migrate_ticket_enhancements_v2()`, bump revision 30→31 |
| `includes/services/class-hl-ticket-service.php` | Business logic | Add `VALID_CATEGORIES`, update `create_ticket()`, `update_ticket()`, `get_tickets()`, `enrich_ticket_for_detail()`, add `search_users()` |
| `includes/frontend/class-hl-frontend-feature-tracker.php` | AJAX handlers + HTML | Add form fields, `data-user-department` attr, register `hl_ticket_user_search`, update create/update handlers |
| `assets/js/frontend.js` | Client-side UI logic | Fix line 616 bug, add category/context/paste logic, update modal functions |
| `assets/css/frontend.css` | Styles | Add department, autocomplete, chip, paste feedback styles |

---

### Task 0: Fix Pre-existing Comment Bug (HARD BLOCKER — must complete before all other tasks)

**Files:**
- Modify: `assets/js/frontend.js:616`

> **DO NOT SKIP THIS TASK.** Tasks 4 and 5 route through the comment flow. If this bug is not fixed first, clipboard paste into comments will produce broken behavior.

Line 616 has a stray `$('#hlft-comments-list').append(html);` that references `html` outside its scope (it's defined inside the `finishComment()` closure). This causes every comment post to append garbage or throw a ReferenceError.

- [ ] **Step 1: Remove the stray line**

In `assets/js/frontend.js`, delete line 616:

```javascript
// DELETE this line (line 616):
$('#hlft-comments-list').append(html);
```

The `finishComment()` function at line ~606 already appends the comment HTML correctly. The stray line at 616 is a duplicate that runs outside the closure.

- [ ] **Step 2: Verify the fix**

Deploy to test server and verify:
1. Open a ticket detail modal
2. Post a comment (with and without an image attachment)
3. Confirm the comment appears exactly once, no duplicates, no errors in browser console

- [ ] **Step 3: Commit**

```bash
git add assets/js/frontend.js
git commit -m "fix(tickets): remove stray comment append causing duplicate/error on post"
```

---

### Task 1: Database Schema Migration

**Files:**
- Modify: `includes/class-hl-installer.php:136` (revision bump)
- Modify: `includes/class-hl-installer.php:176-181` (add migration call)
- Modify: `includes/class-hl-installer.php:1965-1983` (CREATE TABLE)
- Add method: `includes/class-hl-installer.php` (new `migrate_ticket_enhancements_v2()`)

- [ ] **Step 1: Add columns to the CREATE TABLE statement**

In `includes/class-hl-installer.php`, inside the `CREATE TABLE {$wpdb->prefix}hl_ticket` block (after the `updated_at` column at line ~1976, before the `PRIMARY KEY` line), add three new columns:

```php
            category enum('course_content','platform_issue','account_access','forms_assessments','reports_data','other') NOT NULL DEFAULT 'other',
            context_mode enum('self','view_as') NOT NULL DEFAULT 'self',
            context_user_id bigint(20) unsigned NULL DEFAULT NULL,
```

The full column list should now end with:

```php
            creator_user_id bigint(20) unsigned NOT NULL,
            resolved_at datetime NULL DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            category enum('course_content','platform_issue','account_access','forms_assessments','reports_data','other') NOT NULL DEFAULT 'other',
            context_mode enum('self','view_as') NOT NULL DEFAULT 'self',
            context_user_id bigint(20) unsigned NULL DEFAULT NULL,
            PRIMARY KEY (ticket_id),
```

- [ ] **Step 2: Add the migration method**

Add this method to `HL_Installer` (after the existing migration methods, before the closing `}` of the class):

```php
    /**
     * Rev 31: Feature Tracker enhancements — add category, context_mode, context_user_id.
     */
    private static function migrate_ticket_enhancements_v2() {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_ticket';

        // Helper: check if column exists.
        $col_exists = function ( $col ) use ( $wpdb, $table ) {
            return ! empty( $wpdb->get_results( $wpdb->prepare(
                "SHOW COLUMNS FROM {$table} LIKE %s", $col
            ) ) );
        };

        if ( ! $col_exists( 'category' ) ) {
            $wpdb->query(
                "ALTER TABLE {$table} ADD COLUMN category enum('course_content','platform_issue','account_access','forms_assessments','reports_data','other') NOT NULL DEFAULT 'other'"
            );
        }

        if ( ! $col_exists( 'context_mode' ) ) {
            $wpdb->query(
                "ALTER TABLE {$table} ADD COLUMN context_mode enum('self','view_as') NOT NULL DEFAULT 'self'"
            );
        }

        if ( ! $col_exists( 'context_user_id' ) ) {
            $wpdb->query(
                "ALTER TABLE {$table} ADD COLUMN context_user_id bigint(20) unsigned NULL DEFAULT NULL"
            );
        }
    }
```

- [ ] **Step 3: Bump revision and wire the migration**

In `maybe_upgrade()`, change line 136:

```php
        $current_revision = 31;
```

Add the migration call after the Rev 30 block (after line ~181, before `update_option`):

```php
            // Rev 31: Feature Tracker enhancements — category, context_mode, context_user_id.
            if ( (int) $stored < 31 ) {
                self::migrate_ticket_enhancements_v2();
            }
```

- [ ] **Step 4: Deploy and verify migration**

Deploy to test server, then verify columns were added:

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SHOW COLUMNS FROM wp_hl_ticket"'
```

Expected: `category`, `context_mode`, `context_user_id` appear in the output.

Also verify existing tickets got defaults:

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SELECT ticket_id, category, context_mode, context_user_id FROM wp_hl_ticket"'
```

Expected: all existing tickets show `category=other`, `context_mode=self`, `context_user_id=NULL`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat(tickets): add category, context_mode, context_user_id columns (rev 31)"
```

---

### Task 2: Service Layer — Constants, Validation, Enrichment

**Files:**
- Modify: `includes/services/class-hl-ticket-service.php:20-27` (add constant)
- Modify: `includes/services/class-hl-ticket-service.php:150-160` (get_tickets SELECT)
- Modify: `includes/services/class-hl-ticket-service.php:202-254` (create_ticket)
- Modify: `includes/services/class-hl-ticket-service.php:263-325` (update_ticket)
- Modify: `includes/services/class-hl-ticket-service.php:483-489` (enrich_ticket_for_detail)
- Add method: `includes/services/class-hl-ticket-service.php` (search_users)

- [ ] **Step 1: Add VALID_CATEGORIES constant**

In `includes/services/class-hl-ticket-service.php`, after the `VALID_TYPES` constant (line ~21), add:

```php
    /** @var string[] Valid ticket categories. */
    const VALID_CATEGORIES = array( 'course_content', 'platform_issue', 'account_access', 'forms_assessments', 'reports_data', 'other' );
```

- [ ] **Step 2: Update the get_tickets() SELECT**

In `get_tickets()`, replace the SELECT at line ~153:

```php
        $select_sql = "SELECT t.ticket_id, t.ticket_uuid, t.title, t.type, t.priority, t.status,
                              t.creator_user_id, t.category, t.context_mode, t.context_user_id,
                              t.resolved_at, t.created_at, t.updated_at
                       FROM {$table} t
                       WHERE {$where_sql}
                       ORDER BY t.created_at DESC
                       LIMIT %d OFFSET %d";
```

- [ ] **Step 3: Update create_ticket() with category + context fields**

In `create_ticket()`, after the `$description` extraction (line ~208), add:

```php
        $category      = isset( $data['category'] ) ? $data['category'] : '';
        $context_mode  = isset( $data['context_mode'] ) && $data['context_mode'] === 'view_as' ? 'view_as' : 'self';
        $context_user  = ! empty( $data['context_user_id'] ) ? absint( $data['context_user_id'] ) : null;
```

After the existing validation block (after line ~225 `if ( empty( $description ) )`), add:

```php
        if ( ! in_array( $category, self::VALID_CATEGORIES, true ) ) {
            return new WP_Error( 'invalid_category', __( 'Please select a category.', 'hl-core' ) );
        }

        // Context validation.
        if ( $context_mode === 'self' ) {
            $context_user = null; // Force NULL when mode is self.
        } elseif ( $context_mode === 'view_as' ) {
            if ( ! $context_user ) {
                return new WP_Error( 'missing_context_user', __( 'Please select the user you were viewing as.', 'hl-core' ) );
            }
            if ( ! get_userdata( $context_user ) ) {
                return new WP_Error( 'invalid_context_user', __( 'The selected user does not exist.', 'hl-core' ) );
            }
        }
```

Update the `$wpdb->insert()` data array (line ~229) to include the new fields. Replace the entire insert call:

```php
        $uuid   = HL_DB_Utils::generate_uuid();
        $now    = current_time( 'mysql' );
        $result = $wpdb->insert( $wpdb->prefix . 'hl_ticket', array(
            'ticket_uuid'     => $uuid,
            'title'           => $title,
            'description'     => $description,
            'type'            => $type,
            'priority'        => $priority,
            'category'        => $category,
            'status'          => 'open',
            'creator_user_id' => get_current_user_id(),
            'context_mode'    => $context_mode,
            'context_user_id' => $context_user,
            'created_at'      => $now,
            'updated_at'      => $now,
        ) );
```

Update the audit log (line ~250) to include category:

```php
        HL_Audit_Service::log( 'ticket_created', array(
            'entity_type' => 'ticket',
            'entity_id'   => $ticket_id,
            'after_data'  => array( 'title' => $title, 'type' => $type, 'priority' => $priority, 'category' => $category ),
        ) );
```

- [ ] **Step 4: Update update_ticket() with category + context fields**

In `update_ticket()`, after the existing field extractions (line ~284), add:

```php
        $category     = isset( $data['category'] ) && in_array( $data['category'], self::VALID_CATEGORIES, true ) ? $data['category'] : $ticket['category'];
        $context_mode = isset( $data['context_mode'] ) && $data['context_mode'] === 'view_as' ? 'view_as' : ( isset( $data['context_mode'] ) ? 'self' : $ticket['context_mode'] );
        $context_user = null;

        if ( $context_mode === 'self' ) {
            $context_user = null;
        } elseif ( $context_mode === 'view_as' ) {
            $context_user = ! empty( $data['context_user_id'] ) ? absint( $data['context_user_id'] ) : null;
            if ( ! $context_user ) {
                return new WP_Error( 'missing_context_user', __( 'Please select the user you were viewing as.', 'hl-core' ) );
            }
            if ( ! get_userdata( $context_user ) ) {
                return new WP_Error( 'invalid_context_user', __( 'The selected user does not exist.', 'hl-core' ) );
            }
        }
```

Update the `$update_data` array (line ~296) to include new fields:

```php
        $update_data = array(
            'title'           => $title,
            'type'            => $type,
            'priority'        => $priority,
            'category'        => $category,
            'description'     => $description,
            'context_mode'    => $context_mode,
            'context_user_id' => $context_user,
            'updated_at'      => current_time( 'mysql' ),
        );
```

Update the audit log to include category:

```php
        HL_Audit_Service::log( 'ticket_updated', array(
            'entity_type' => 'ticket',
            'entity_id'   => $ticket['ticket_id'],
            'before_data' => array(
                'title'    => $ticket['title'],
                'type'     => $ticket['type'],
                'priority' => $ticket['priority'],
                'category' => $ticket['category'],
            ),
            'after_data'  => array(
                'title'    => $title,
                'type'     => $type,
                'priority' => $priority,
                'category' => $category,
            ),
        ) );
```

- [ ] **Step 5: Update enrich_ticket_for_detail() with department + context user**

Replace `enrich_ticket_for_detail()` (line ~483):

```php
    private function enrich_ticket_for_detail( $row ) {
        $row = $this->enrich_ticket_for_list( $row );
        $row['comments']      = $this->get_comments( $row['ticket_id'] );
        $row['comment_count'] = count( $row['comments'] );
        $row['attachments']   = $this->get_attachments( (int) $row['ticket_id'] );

        // Department from JetEngine user meta.
        $dept = get_user_meta( $row['creator_user_id'], 'housman_learning_department', true );
        if ( is_array( $dept ) ) {
            $dept = implode( ', ', array_map( 'sanitize_text_field', $dept ) );
        } else {
            $dept = sanitize_text_field( (string) $dept );
        }
        $row['creator_department'] = ! empty( $dept ) ? $dept : __( 'Not assigned', 'hl-core' );

        // Context user resolution (for "Viewing As" feature).
        if ( $row['context_mode'] === 'view_as' && ! empty( $row['context_user_id'] ) ) {
            $ctx_user = get_userdata( $row['context_user_id'] );
            if ( $ctx_user ) {
                $row['context_user_name']   = $ctx_user->display_name;
                $row['context_user_avatar'] = get_avatar_url( $row['context_user_id'], array( 'size' => 64 ) );
                if ( function_exists( 'bp_core_get_user_domain' ) ) {
                    $row['context_user_url'] = bp_core_get_user_domain( $row['context_user_id'] );
                } else {
                    $row['context_user_url'] = get_author_posts_url( $row['context_user_id'] );
                }
            } else {
                $row['context_user_name']   = __( 'Deleted User', 'hl-core' );
                $row['context_user_avatar'] = null;
                $row['context_user_url']    = null;
            }
        }

        return $row;
    }
```

- [ ] **Step 6: Add search_users() method**

Add this method after `get_attachments()` (before the closing `}` of the class):

```php
    /**
     * Search WordPress users by display_name for the "Viewing As" autocomplete.
     *
     * @param string $search Search term (min 3 chars).
     * @return array[] Array of { user_id, display_name, avatar_url }.
     */
    public function search_users( $search ) {
        global $wpdb;

        $search = sanitize_text_field( trim( $search ) );
        if ( strlen( $search ) < 3 ) {
            return array();
        }

        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, display_name FROM {$wpdb->users} WHERE display_name LIKE %s ORDER BY display_name ASC LIMIT 10",
            $like
        ), ARRAY_A );

        $results = array();
        foreach ( $rows ?: array() as $row ) {
            $results[] = array(
                'user_id'      => (int) $row['ID'],
                'display_name' => $row['display_name'],
                'avatar_url'   => get_avatar_url( $row['ID'], array( 'size' => 64 ) ),
            );
        }

        return $results;
    }
```

- [ ] **Step 7: Commit**

```bash
git add includes/services/class-hl-ticket-service.php
git commit -m "feat(tickets): add category/context validation, department enrichment, user search"
```

---

### Task 3: Frontend PHP — Form HTML + AJAX Handlers

**Files:**
- Modify: `includes/frontend/class-hl-frontend-feature-tracker.php:26-34` (register new AJAX)
- Modify: `includes/frontend/class-hl-frontend-feature-tracker.php:42-55` (render — data attributes)
- Modify: `includes/frontend/class-hl-frontend-feature-tracker.php:121-173` (detail modal HTML)
- Modify: `includes/frontend/class-hl-frontend-feature-tracker.php:175-227` (create/edit modal HTML)
- Modify: `includes/frontend/class-hl-frontend-feature-tracker.php:285-299` (ajax_ticket_create)
- Modify: `includes/frontend/class-hl-frontend-feature-tracker.php:302-318` (ajax_ticket_update)
- Add method: `includes/frontend/class-hl-frontend-feature-tracker.php` (ajax_user_search)

- [ ] **Step 1: Register the new AJAX handler**

In `__construct()`, after line 34 (`add_action( 'wp_ajax_hl_ticket_upload', ... )`), add:

```php
        add_action( 'wp_ajax_hl_ticket_user_search', array( $this, 'ajax_user_search' ) );
```

- [ ] **Step 2: Add data attributes to the wrapper**

In `render()`, update the wrapper div (line ~53) to include the current user's department. Replace line 53:

```php
        <?php
        // Current user's department for the read-only form field.
        $current_user_dept = get_user_meta( get_current_user_id(), 'housman_learning_department', true );
        if ( is_array( $current_user_dept ) ) {
            $current_user_dept = implode( ', ', array_map( 'sanitize_text_field', $current_user_dept ) );
        } else {
            $current_user_dept = sanitize_text_field( (string) $current_user_dept );
        }
        if ( empty( $current_user_dept ) ) {
            $current_user_dept = __( 'Not assigned', 'hl-core' );
        }
        ?>
        <div class="hlft-wrapper"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-is-admin="<?php echo $is_admin ? '1' : '0'; ?>"
             data-user-department="<?php echo esc_attr( $current_user_dept ); ?>">
```

- [ ] **Step 3: Add new fields to the Create/Edit form modal**

In the `<!-- Create/Edit Modal -->` section, add the new form fields after the Title field (after line ~188, the closing `</div>` of the title form-group) and before the Type field.

Insert these three form groups between the Title and Type fields:

```php
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
                                <label><?php esc_html_e( 'Department', 'hl-core' ); ?></label>
                                <div class="hlft-dept-readonly" id="hlft-form-department"></div>
                            </div>
                            <div class="hlft-form-group">
                                <label for="hlft-form-context-mode"><?php esc_html_e( 'Encountered as', 'hl-core' ); ?></label>
                                <select id="hlft-form-context-mode">
                                    <option value="self"><?php esc_html_e( 'Myself', 'hl-core' ); ?></option>
                                    <option value="view_as"><?php esc_html_e( 'Viewing as another user', 'hl-core' ); ?></option>
                                </select>
                                <div class="hlft-context-user-wrap" id="hlft-context-user-wrap" style="display:none;">
                                    <input type="hidden" id="hlft-form-context-user-id" value="">
                                    <div class="hlft-user-search-wrap">
                                        <input type="text" id="hlft-user-search-input" placeholder="<?php esc_attr_e( 'Search by name...', 'hl-core' ); ?>" autocomplete="off">
                                        <div class="hlft-user-search-results" id="hlft-user-search-results" style="display:none;"></div>
                                    </div>
                                    <div class="hlft-context-user-chip" id="hlft-context-user-chip" style="display:none;"></div>
                                </div>
                            </div>
```

- [ ] **Step 4: Update ajax_ticket_create() to pass new fields**

In `ajax_ticket_create()` (line ~288), replace the `create_ticket()` call:

```php
        $result = HL_Ticket_Service::instance()->create_ticket( array(
            'title'           => isset( $_POST['title'] ) ? $_POST['title'] : '',
            'type'            => isset( $_POST['type'] ) ? $_POST['type'] : '',
            'priority'        => isset( $_POST['priority'] ) ? $_POST['priority'] : 'medium',
            'description'     => isset( $_POST['description'] ) ? $_POST['description'] : '',
            'category'        => isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : '',
            'context_mode'    => isset( $_POST['context_mode'] ) ? sanitize_text_field( $_POST['context_mode'] ) : 'self',
            'context_user_id' => ! empty( $_POST['context_user_id'] ) ? absint( $_POST['context_user_id'] ) : null,
        ) );
```

- [ ] **Step 5: Update ajax_ticket_update() to pass new fields**

In `ajax_ticket_update()` (line ~307), replace the `update_ticket()` call:

```php
        $result = HL_Ticket_Service::instance()->update_ticket( $uuid, array(
            'title'           => isset( $_POST['title'] ) ? $_POST['title'] : '',
            'type'            => isset( $_POST['type'] ) ? $_POST['type'] : '',
            'priority'        => isset( $_POST['priority'] ) ? $_POST['priority'] : '',
            'description'     => isset( $_POST['description'] ) ? $_POST['description'] : '',
            'category'        => isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : '',
            'context_mode'    => isset( $_POST['context_mode'] ) ? sanitize_text_field( $_POST['context_mode'] ) : 'self',
            'context_user_id' => ! empty( $_POST['context_user_id'] ) ? absint( $_POST['context_user_id'] ) : null,
        ) );
```

- [ ] **Step 6: Add ajax_user_search() handler**

Add this method after `ajax_ticket_upload()`:

```php
    public function ajax_user_search() {
        $this->verify_ajax();

        $search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
        $results = HL_Ticket_Service::instance()->search_users( $search );

        wp_send_json_success( $results );
    }
```

- [ ] **Step 7: Commit**

```bash
git add includes/frontend/class-hl-frontend-feature-tracker.php
git commit -m "feat(tickets): add category/context/department form fields + user search AJAX"
```

---

### Task 4: JavaScript — Category, Department, Context Fields

**Files:**
- Modify: `assets/js/frontend.js:201-203` (add label maps)
- Modify: `assets/js/frontend.js:273-318` (detail modal rendering)
- Modify: `assets/js/frontend.js:421-447` (create/edit modal functions)
- Modify: `assets/js/frontend.js:524-568` (form submit handler)

- [ ] **Step 1: Add label maps and state variables**

After the existing `statusLabels` map (line ~203), add:

```javascript
            var categoryLabels = {
                course_content: 'Course Content',
                platform_issue: 'Platform Issue',
                account_access: 'Account & Access',
                forms_assessments: 'Forms & Assessments',
                reports_data: 'Reports & Data',
                other: 'Other'
            };

            var userSearchXhr = null; // Track in-flight user search request for cancellation
            var userSearchTimer = null;
            var userSearchResults = []; // Store search results to avoid data-attribute quote escaping issues
```

- [ ] **Step 2: Update openDetail() to show category, department, and context user**

In `openDetail()`, update the meta row rendering (line ~292). Replace the meta HTML construction:

```javascript
                    // Meta
                    var meta = '<span class="hlft-priority-badge hlft-priority-badge--' + esc(t.priority) + '">' + esc(t.priority) + '</span>' +
                        ' <span class="hlft-status-pill hlft-status-pill--' + esc(t.status) + '">' + esc(statusLabels[t.status] || '') + '</span>' +
                        ' <span class="hlft-meta-category">' + esc(categoryLabels[t.category] || t.category || '') + '</span>' +
                        ' <span>By <img class="hlft-avatar" src="' + esc(t.creator_avatar) + '" alt=""> ' + esc(t.creator_name);

                    if (t.creator_department) {
                        meta += ' &bull; ' + esc(t.creator_department);
                    }

                    meta += ' &bull; ' + esc(t.time_ago) + '</span>';

                    // Context user ("Viewing as")
                    if (t.context_mode === 'view_as' && t.context_user_name) {
                        meta += ' <span class="hlft-meta-context">';
                        if (t.context_user_url) {
                            meta += 'Viewing as <a href="' + esc(t.context_user_url) + '" target="_blank">' + esc(t.context_user_name) + '</a>';
                        } else {
                            meta += 'Viewing as ' + esc(t.context_user_name);
                        }
                        meta += '</span>';
                    }

                    $('#hlft-detail-meta').html(meta);
```

- [ ] **Step 3: Update openCreateModal() to reset new fields**

Replace `openCreateModal()`:

```javascript
            function openCreateModal() {
                $('#hlft-form-title').text('New Ticket');
                $('#hlft-form-uuid').val('');
                $('#hlft-form-title-input').val('');
                $('#hlft-form-category').prop('selectedIndex', 0); // Reset to disabled placeholder
                $('#hlft-form-type').val('');
                $('#hlft-form-priority').val('medium');
                $('#hlft-form-description').val('');
                $('#hlft-form-file').val('');
                $('#hlft-form-preview').empty();
                pendingFormFiles = [];
                // Department (read-only, from data attribute)
                var dept = $wrap.data('user-department') || 'Not assigned';
                var deptClass = dept === 'Not assigned' ? ' hlft-dept-empty' : '';
                $('#hlft-form-department').attr('class', 'hlft-dept-readonly' + deptClass).text(dept);
                // Context fields
                $('#hlft-form-context-mode').val('self');
                $('#hlft-context-user-wrap').hide();
                $('#hlft-form-context-user-id').val('');
                $('#hlft-context-user-chip').hide().empty();
                $('#hlft-user-search-input').val('');
                $('#hlft-user-search-results').hide().empty();
                // Show
                $('#hlft-form-submit').text('Submit').prop('disabled', false);
                $('#hlft-form-modal').show();
                $('#hlft-form-title-input').focus();
            }
```

- [ ] **Step 4: Update openEditModal() to populate new fields**

Replace `openEditModal()`:

```javascript
            function openEditModal(ticket) {
                $('#hlft-form-title').text('Edit Ticket');
                $('#hlft-form-uuid').val(ticket.ticket_uuid);
                $('#hlft-form-title-input').val(ticket.title);
                $('#hlft-form-category').val(ticket.category || '');
                $('#hlft-form-type').val(ticket.type);
                $('#hlft-form-priority').val(ticket.priority);
                $('#hlft-form-description').val(ticket.description);
                // Reset pending files from any prior modal interaction.
                pendingFormFiles = [];
                $('#hlft-form-file').val('');
                $('#hlft-form-preview').empty();
                // Department
                var dept = ticket.creator_department || $wrap.data('user-department') || 'Not assigned';
                var deptClass = dept === 'Not assigned' ? ' hlft-dept-empty' : '';
                $('#hlft-form-department').attr('class', 'hlft-dept-readonly' + deptClass).text(dept);
                // Context fields
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
                // Show
                $('#hlft-form-submit').text('Save Changes').prop('disabled', false);
                $('#hlft-detail-modal').hide();
                $('#hlft-form-modal').show();
                $('#hlft-form-title-input').focus();
            }
```

- [ ] **Step 5: Update form submit to include new fields**

In the form submit handler (line ~532), update the `data` object:

```javascript
                var data = {
                    title: $('#hlft-form-title-input').val(),
                    category: $('#hlft-form-category').val(),
                    type: $('#hlft-form-type').val(),
                    priority: $('#hlft-form-priority').val(),
                    description: $('#hlft-form-description').val(),
                    context_mode: $('#hlft-form-context-mode').val(),
                    context_user_id: $('#hlft-form-context-user-id').val() || ''
                };
```

Also update the **edit branch** of the submit handler to upload pending files (the existing code skips uploads during edits). Replace the edit branch:

```javascript
                if (isEdit) {
                    data.ticket_uuid = uuid;
                    ajax('hl_ticket_update', data, function(t) {
                        if (pendingFormFiles.length) {
                            uploadFiles(pendingFormFiles, t.ticket_uuid, null, function() {
                                pendingFormFiles = [];
                                $btn.prop('disabled', false).text('Save Changes');
                                $('#hlft-form-modal').hide();
                                showToast('Ticket updated');
                                openDetail(t.ticket_uuid);
                                loadTickets();
                            });
                        } else {
                            $btn.prop('disabled', false).text('Save Changes');
                            $('#hlft-form-modal').hide();
                            showToast('Ticket updated');
                            openDetail(t.ticket_uuid);
                            loadTickets();
                        }
                    });
                }
```

Add a submission guard before the AJAX call. Right after the `data` object definition, add:

```javascript
                // Submission guard: view_as mode requires a selected user.
                if (data.context_mode === 'view_as' && !data.context_user_id) {
                    showToast('Please select the user you were viewing as.', true);
                    $btn.prop('disabled', false).text(isEdit ? 'Save Changes' : 'Submit');
                    return;
                }
```

- [ ] **Step 6: Add context mode toggle and user search event handlers**

After the existing event handlers section (after the search input handler at line ~475), add:

```javascript
            // Context mode toggle (show/hide user search)
            $(document).on('change', '#hlft-form-context-mode', function() {
                if ($(this).val() === 'view_as') {
                    $('#hlft-context-user-wrap').show();
                    $('#hlft-user-search-input').show().focus();
                } else {
                    $('#hlft-context-user-wrap').hide();
                    $('#hlft-form-context-user-id').val('');
                    $('#hlft-context-user-chip').hide().empty();
                    $('#hlft-user-search-input').val('');
                    $('#hlft-user-search-results').hide().empty();
                }
            });

            // User search autocomplete (debounced, min 3 chars, abort previous)
            $(document).on('input', '#hlft-user-search-input', function() {
                var val = $.trim($(this).val());
                var $results = $('#hlft-user-search-results');

                clearTimeout(userSearchTimer);

                if (val.length < 3) {
                    $results.hide().empty();
                    return;
                }

                userSearchTimer = setTimeout(function() {
                    // Abort previous request.
                    if (userSearchXhr && userSearchXhr.readyState !== 4) {
                        userSearchXhr.abort();
                    }

                    $results.show().html('<div class="hlft-user-search-loading">Searching...</div>');

                    userSearchXhr = $.post(hlCoreAjax.ajaxurl, {
                        action: 'hl_ticket_user_search',
                        nonce: nonce,
                        search: val
                    }, function(resp) {
                        $results.empty();
                        userSearchResults = [];
                        if (!resp.success || !resp.data.length) {
                            $results.html('<div class="hlft-user-search-empty">No users found</div>');
                            return;
                        }
                        userSearchResults = resp.data;
                        // Use array index instead of data attributes to avoid quote-escaping issues in names.
                        $.each(resp.data, function(i, u) {
                            $results.append(
                                '<div class="hlft-user-search-item" data-index="' + i + '">' +
                                '<img class="hlft-avatar" src="' + esc(u.avatar_url) + '" alt=""> ' + esc(u.display_name) +
                                '</div>'
                            );
                        });
                    });
                }, 300);
            });

            // Select user from autocomplete results (read from JS array, not data attributes)
            $(document).on('click', '.hlft-user-search-item', function() {
                var idx = $(this).data('index');
                var u = userSearchResults[idx];
                if (!u) return;
                var userId = u.user_id;
                var userName = u.display_name;
                var userAvatar = u.avatar_url;

                $('#hlft-form-context-user-id').val(userId);
                $('#hlft-user-search-input').val('').hide();
                $('#hlft-user-search-results').hide().empty();
                $('#hlft-context-user-chip').show().html(
                    '<img class="hlft-avatar" src="' + esc(userAvatar) + '" alt=""> ' +
                    esc(userName) +
                    ' <button type="button" class="hlft-chip-remove" title="Remove">&times;</button>'
                );
            });

            // Remove selected context user (chip X button)
            $(document).on('click', '.hlft-chip-remove', function() {
                $('#hlft-form-context-user-id').val('');
                $('#hlft-context-user-chip').hide().empty();
                $('#hlft-user-search-input').show().val('').focus();
            });
```

- [ ] **Step 7: Deploy and verify**

Deploy to test server. Verify:
1. New Ticket form shows Category (required), Department (read-only), Encountered as dropdown
2. Selecting "Viewing as another user" shows user search, typing 3+ chars returns results
3. Selecting a user shows chip, removing chip restores search
4. Detail modal shows category, department, and "Viewing as" when applicable
5. Edit modal populates all new fields correctly
6. Submitting with "Viewing as" and no user selected shows error toast

- [ ] **Step 8: Commit**

```bash
git add assets/js/frontend.js
git commit -m "feat(tickets): add category, department, context fields + user search UI"
```

---

### Task 5: JavaScript — Clipboard Paste for Images

**Files:**
- Modify: `assets/js/frontend.js` (add paste handler after existing file upload handlers)
- Modify: `assets/js/frontend.js:502-510` (fix file picker to append instead of replace)

- [ ] **Step 1: Fix file picker to append instead of replace**

In `assets/js/frontend.js`, replace the file picker handlers (line ~502-510):

```javascript
            // File attach buttons
            $('#hlft-form-attach-btn').on('click', function() { $('#hlft-form-file').click(); });
            $('#hlft-form-file').on('change', function() {
                pendingFormFiles = pendingFormFiles.concat(Array.from(this.files || []));
                showFilePreview($('#hlft-form-preview'), pendingFormFiles);
            });
            $('#hlft-comment-attach-btn').on('click', function() { $('#hlft-comment-file').click(); });
            $('#hlft-comment-file').on('change', function() {
                pendingCommentFiles = pendingCommentFiles.concat(Array.from(this.files || []));
                showFilePreview($('#hlft-comment-preview'), pendingCommentFiles);
            });
```

- [ ] **Step 2: Add the clipboard paste handler**

After the file attach handlers, add the paste handler function and event bindings:

```javascript
            // ── Clipboard Paste for Images ──

            var allowedPasteTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            var pasteExtMap = { 'image/jpeg': '.jpg', 'image/png': '.png', 'image/gif': '.gif', 'image/webp': '.webp' };

            function handleImagePaste(e, pendingFilesRef, $previewContainer) {
                var items = (e.originalEvent || e).clipboardData && (e.originalEvent || e).clipboardData.items;
                if (!items) return;

                var added = false;
                for (var i = 0; i < items.length; i++) {
                    if (allowedPasteTypes.indexOf(items[i].type) === -1) continue;

                    var file = items[i].getAsFile();
                    if (!file) continue;

                    // Client-side size check (server enforces the real 5MB limit).
                    // File.size may be 0 for clipboard items in some browsers — allow through.
                    if (file.size > 0 && file.size > 5 * 1024 * 1024) {
                        showToast('Pasted image exceeds 5MB limit', true);
                        continue;
                    }

                    var ext = pasteExtMap[items[i].type] || '.png';
                    var rand = Math.random().toString(16).slice(2, 6);
                    var filename = 'pasted-image-' + Date.now() + '-' + rand + ext;
                    var namedFile = new File([file], filename, { type: items[i].type });

                    pendingFilesRef.push(namedFile);
                    added = true;
                }

                if (added) {
                    showFilePreview($previewContainer, pendingFilesRef);
                    showToast('Image pasted');
                }
            }

            // Bind paste to form textarea + upload area (NOT the whole modal).
            $('#hlft-form-description, #hlft-form-upload-area').on('paste', function(e) {
                handleImagePaste(e, pendingFormFiles, $('#hlft-form-preview'));
            });
            // Bind paste to comment textarea.
            $(document).on('paste', '#hlft-comment-text', function(e) {
                handleImagePaste(e, pendingCommentFiles, $('#hlft-comment-preview'));
            });
```

- [ ] **Step 3: Deploy and verify**

Deploy to test server. Verify:
1. Take a screenshot and Ctrl+V into the description textarea — image preview appears, toast shows "Image pasted"
2. Paste a second image — both appear in preview
3. Use the file picker after pasting — file is added (not replacing the paste)
4. Paste in comment textarea — works the same
5. Paste text only (no image in clipboard) — text pastes normally, no toast, no preview
6. Submit the ticket — pasted images upload and appear as attachments
7. Check browser console for errors — should be clean

- [ ] **Step 4: Commit**

```bash
git add assets/js/frontend.js
git commit -m "feat(tickets): add clipboard image paste + fix file picker to append"
```

---

### Task 6: CSS Styles

**Files:**
- Modify: `assets/css/frontend.css` (add styles after existing Feature Tracker section, before the `HIDE LD FOCUS MODE` section at line ~10343)

- [ ] **Step 1: Add all new styles**

In `assets/css/frontend.css`, before the `/* HIDE LD FOCUS MODE ELEMENTS */` comment (line ~10343), add:

```css
/* ── Department Read-Only Field ── */
.hlft-dept-readonly {
    padding: 8px 10px;
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-sm);
    background: #f5f5f5;
    color: var(--hl-text);
    font-size: 14px;
    cursor: default;
}
.hlft-dept-empty {
    color: var(--hl-text-secondary);
    font-style: italic;
}

/* ── Category Meta Badge (detail modal) ── */
.hlft-meta-category {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    background: var(--hl-surface);
    border: 1px solid var(--hl-border);
    font-size: 12px;
    color: var(--hl-text-secondary);
}

/* ── Context "Viewing as" (detail modal) ── */
.hlft-meta-context {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    background: #fff3e0;
    border: 1px solid #ffe0b2;
    font-size: 12px;
    color: #e65100;
}
.hlft-meta-context a {
    color: #e65100;
    text-decoration: underline;
}

/* ── Context User Search ── */
.hlft-context-user-wrap {
    margin-top: 8px;
}
.hlft-user-search-wrap {
    position: relative;
}
.hlft-user-search-wrap input[type="text"] {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-sm);
    font-size: 14px;
    box-sizing: border-box;
}
.hlft-user-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--hl-surface);
    border: 1px solid var(--hl-border);
    border-top: none;
    border-radius: 0 0 var(--hl-radius-sm) var(--hl-radius-sm);
    max-height: 200px;
    overflow-y: auto;
    z-index: 100003;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.hlft-user-search-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    cursor: pointer;
    font-size: 14px;
}
.hlft-user-search-item:hover {
    background: var(--hl-bg-hover, #f0f0f0);
}
.hlft-user-search-loading,
.hlft-user-search-empty {
    padding: 10px;
    text-align: center;
    color: var(--hl-text-secondary);
    font-size: 13px;
}

/* ── Context User Chip ── */
.hlft-context-user-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    background: var(--hl-surface);
    border: 1px solid var(--hl-border);
    font-size: 14px;
    margin-top: 6px;
}
.hlft-context-user-chip .hlft-avatar {
    width: 20px;
    height: 20px;
}
.hlft-chip-remove {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--hl-text-secondary);
    font-size: 16px;
    line-height: 1;
    padding: 0 2px;
}
.hlft-chip-remove:hover {
    color: var(--hl-error, #d32f2f);
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "feat(tickets): add CSS for department, category badge, user search, context chip"
```

---

### Task 7: Verify JetEngine Meta Key + Deploy to Test

**Files:** None (verification only)

- [ ] **Step 1: Verify the JetEngine meta key on production**

```bash
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html && wp user meta list 10 --keys=housman_learning_department"
```

If the key doesn't exist, try listing all meta for user 10 (Yuyan) to find the correct key:

```bash
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html && wp user meta list 10 --format=table" | head -50
```

If the key name is different, update the three places it's referenced:
1. `includes/services/class-hl-ticket-service.php` in `enrich_ticket_for_detail()`
2. `includes/frontend/class-hl-frontend-feature-tracker.php` in `render()`

- [ ] **Step 2: Deploy full changes to test server**

```bash
cd "C:/Users/MateoGonzalez/Dev Projects Mateo/housman-learning-academy/app/public/wp-content/plugins/hl-core"
tar --exclude='.git' --exclude='data' --exclude='./vendor' --exclude='node_modules' --exclude='.superpowers' -czf /tmp/hl-core.tar.gz -C .. hl-core
scp -i ~/.ssh/hla-test-keypair.pem /tmp/hl-core.tar.gz bitnami@44.221.6.201:/tmp/
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress/wp-content/plugins && sudo rm -rf hl-core && sudo tar -xzf /tmp/hl-core.tar.gz && sudo chown -R bitnami:daemon hl-core'
```

- [ ] **Step 3: Verify schema migration ran**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SHOW COLUMNS FROM wp_hl_ticket" && wp --path=/opt/bitnami/wordpress option get hl_core_schema_revision'
```

Expected: 3 new columns visible, schema revision = 31.

- [ ] **Step 4: Full end-to-end verification**

On the test site, verify all features:
1. **Category:** Create a ticket with "Forms & Assessments" category. Open detail — category badge shows. Edit — category is pre-selected.
2. **Department:** Form shows read-only department (or "Not assigned" in italic). Detail modal shows department next to creator name.
3. **Context:** Select "Viewing as another user" → search for a user → select → chip appears. Submit. Open detail — "Viewing as [Name]" shows with link. Edit — chip is pre-populated.
4. **Paste:** Screenshot something, Ctrl+V in description textarea → preview appears. Submit → image shows as attachment.
5. **Edge cases:** Submit with "Viewing as" but no user selected → error toast. Create ticket, then open New Ticket again → all fields properly reset. Paste + file picker → both images preserved.

- [ ] **Step 5: Flush caches**

```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress cache flush'
```
