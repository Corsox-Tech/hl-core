# Feature Tracker — Design Spec

**Date:** 2026-04-06
**Status:** Approved (Rev 2 — post peer review)
**Author:** Claude (with user review)

## Problem Statement

Housman staff (admins and coaches) have no structured way to report issues, request improvements, or suggest features for the LMS. Communication currently happens ad-hoc, making it hard to track, prioritize, and plan work. Mateo also needs a way to pull pending tickets directly from the system so Claude can help resolve them in development sessions.

## Solution Overview

A **Feature Tracker** — a single-page AJAX application on the HL frontend, accessible to users with `manage_hl_core` capability (admins and coaches). Staff can create tickets, view all tickets in a filterable table, see full details in a popup modal, and add comments. The system is lightweight — no assignment, no workflows, no changelog page — just a clean tracker that gives visibility into what needs attention.

## 1. Data Model

### Table: `hl_ticket`

```sql
CREATE TABLE {prefix}hl_ticket (
    ticket_id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    ticket_uuid       char(36) NOT NULL,
    title             varchar(255) NOT NULL,
    description       longtext NOT NULL,
    type              enum('bug','improvement','feature_request') NOT NULL,
    priority          enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status            enum('open','in_review','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    creator_user_id   bigint(20) unsigned NOT NULL,
    resolved_at       datetime NULL DEFAULT NULL,
    created_at        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ticket_id),
    UNIQUE KEY ticket_uuid (ticket_uuid),
    KEY status (status),
    KEY creator_user_id (creator_user_id),
    KEY type (type),
    KEY priority (priority)
) $charset_collate;
```

### Table: `hl_ticket_comment`

```sql
CREATE TABLE {prefix}hl_ticket_comment (
    comment_id   bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    ticket_id    bigint(20) unsigned NOT NULL,
    user_id      bigint(20) unsigned NOT NULL,
    comment_text text NOT NULL,
    created_at   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id),
    KEY ticket_id (ticket_id)
) $charset_collate;
```

### Key design decisions

- **`ticket_uuid`**: Public-facing identifier. Used in URLs and AJAX requests instead of auto-increment ID.
- **`type` enum**: Three values — `bug`, `improvement`, `feature_request`. Covers all ticket categories without overcomplicating.
- **`priority` enum**: Four levels — `low`, `medium`, `high`, `critical`. Default is `medium`.
- **`status` enum**: Five states — `open` → `in_review` → `in_progress` → `resolved` / `closed`. Only the admin email (see `HL_Ticket_Service::ADMIN_EMAIL`) can change status.
- **`resolved_at`**: Set automatically when status transitions to `resolved`. Enables future changelog queries if ever needed.
- **`description`**: `longtext`, sanitized with `wp_kses_post()` on write. Plain textarea input in V1 (no rich text toolbar). Stored value trusted on read — no double-sanitization.
- **No `assigned_to` column**: The team is small (Mateo + Claude). Assignment can be added later with a single ALTER TABLE if needed.
- **No `updated_at` on comments**: Comments are immutable once posted. No edit/delete for comments.

## 2. Permissions Model

### Access control

| Action | Who |
|--------|-----|
| View tracker page | Any user with `manage_hl_core` capability |
| View all tickets | Any user with `manage_hl_core` capability |
| Create ticket | Any user with `manage_hl_core` capability |
| Edit ticket | Creator within 2 hours of submission AND ticket is not `resolved`/`closed`, OR admin email (always) |
| Change status | Admin email only (`HL_Ticket_Service::ADMIN_EMAIL`) |
| Add comment | Any user with `manage_hl_core` capability |

### Edit window logic

```
can_edit = (
    (current_user == creator AND time_since_creation < 2 hours AND status NOT IN ('resolved', 'closed'))
    OR current_user_email == HL_Ticket_Service::ADMIN_EMAIL
)
```

The 2-hour window prevents stale edits while giving staff enough time to fix typos or add details they forgot.

### Admin email constant

The admin email is defined as `HL_Ticket_Service::ADMIN_EMAIL = 'mateo@corsox.com'`. All status-change and unrestricted-edit permission checks reference this constant. Defined in exactly one place — if a second admin is needed later, convert to an array or capability in one line.

### Time source

Both `created_at` (MySQL `CURRENT_TIMESTAMP`) and the edit-window PHP check use `current_time('mysql')` (WordPress-configured timezone), consistent with the rest of the codebase.

### Edit window expiry error

If an edit is attempted after the 2-hour window, the server returns: "The 2-hour edit window for this ticket has expired."

## 3. UI Design

### Page structure

Single shortcode page: `[hl_feature_tracker]`

Sidebar menu item: "Feature Tracker" — visible to `manage_hl_core` users, positioned after existing menu items.

Page created by `wp hl-core create-pages` CLI command.

### List view

Standard HL page layout with hero header:

- **Hero**: "Feature Tracker" title, subtitle "Report bugs, suggest improvements, request features"
- **Action bar**: "+ New Ticket" button (left), filter dropdowns for Type / Status / Priority (right), search input
- **Table columns**:
  - Type icon (colored dot: Bug=red, Improvement=amber, Feature Request=green)
  - `#ID` + Title (e.g. "#42 Course X won't load" — clickable, opens detail modal)
  - Priority (colored badge)
  - Submitted by (32px avatar circle + display name)
  - Status (pill badge)
  - Date (relative: "2 hours ago", "Apr 5")
- **Default sort**: newest first (`created_at DESC`)
- **Default filter**: all statuses except `closed` (staff see active work by default)
- **Filter indicator**: When the default filter is active, show a subtle note below the filter bar: "Closed tickets hidden — [show all]". When any filter is manually applied, show active filter values as text labels with an "x" to clear each.
- **Empty state**: "No tickets yet. Click '+ New Ticket' to submit the first one."

### Loading states

- **Table load**: spinner overlay on the table container while fetching
- **Modal open**: spinner inside modal while fetching ticket detail
- **Form submit** (create/edit): disable button, show "Submitting..." text
- **Comment post**: disable button, inline spinner
- **Status change**: disable dropdown + button while saving

### Post-action feedback

- **After ticket create**: modal closes, success toast ("Ticket #N created"), table re-fetches (newest first, new ticket at top)
- **After ticket edit**: modal closes back to detail modal with updated data, success toast
- **After comment post**: comment appended to list, textarea cleared, no modal close
- **After status change**: pill updates in-place, success toast

### Detail modal

Overlay modal (`.hl-modal-overlay` + `.hl-modal-box` pattern from existing codebase) containing:

- **Header**: Type badge + `#ID` + title + close button (✕)
- **Meta row**: Priority badge, Status pill, "By [avatar] [name] • [date]"
- **Description**: Full stored text rendered as HTML
- **Edit button**: Shown only when `can_edit` is true. Opens edit modal.
- **Comments section**: 
  - Header: "Comments (N)"
  - Empty state: "No comments yet" when count is 0
  - Each comment: 32px avatar + display name + timestamp + comment text
  - Comment form at bottom: textarea + "Post" button. Client-side validation: trim whitespace, prevent empty submission.
- **Status section** (admin email only): Status dropdown + "Update" button
- **Close behavior**: Escape key or click on overlay closes modal

### Create modal

Overlay modal with form:
- Title (text input, required)
- Type (dropdown with helper text: Bug — "Something is broken or not working correctly" / Improvement — "An existing feature could work better" / Feature Request — "A new capability that doesn't exist yet", required)
- Priority (dropdown: Low / Medium / High / Critical, default Medium)
- Description (plain textarea, required)
- "Submit" + "Cancel" buttons

### Edit modal

Same as create modal, pre-filled with current values. Only editable fields shown (title, type, priority, description). Status is NOT editable here — it has its own control in the detail modal.

## 4. Architecture

### New files

| File | Class | Responsibility |
|------|-------|----------------|
| `includes/services/class-hl-ticket-service.php` | `HL_Ticket_Service` | All DB operations (CRUD for tickets + comments), permission checks, search/filter/sort queries, status transition logic, audit logging |
| `includes/frontend/class-hl-frontend-feature-tracker.php` | `HL_Frontend_Feature_Tracker` | Shortcode registration, page HTML rendering (table shell + modal templates), AJAX endpoint registration + handlers |

### Modified files

| File | Change |
|------|--------|
| `includes/install/class-hl-installer.php` | Add `hl_ticket` + `hl_ticket_comment` to `get_schema()`, bump schema revision (verify current rev at implementation time) |
| `hl-core.php` | Require + instantiate `HL_Ticket_Service` and `HL_Frontend_Feature_Tracker` |
| `includes/integrations/class-hl-buddyboss-integration.php` | Add "Feature Tracker" sidebar menu item for `manage_hl_core` users |
| `assets/css/frontend.css` | New `.hlft-*` CSS section for tracker components |
| `assets/js/frontend.js` | AJAX handlers for all ticket operations, modal logic, filter/search |

### Why no repository class

The service handles DB queries directly. Two simple tables with straightforward CRUD don't warrant a separate repository layer. This matches the pattern of `HL_Coaching_Service`, `HL_Coach_Dashboard_Service`, and other services in the codebase that query directly without a repository.

## 5. AJAX Endpoints

All endpoints registered via `wp_ajax_` hooks only. Do NOT register `wp_ajax_nopriv_*` handlers. All endpoints require valid nonce (`hl_feature_tracker`) + `manage_hl_core` capability.

| Action | Endpoint | Method | Params | Returns |
|--------|----------|--------|--------|---------|
| List tickets | `hl_ticket_list` | POST | type, status, priority, search, page, per_page | Array of ticket objects (WITHOUT description) with creator display_name + avatar_url |
| Get ticket detail | `hl_ticket_get` | POST | ticket_uuid | Ticket object + comments array (each with user display_name + avatar_url) + can_edit flag |
| Create ticket | `hl_ticket_create` | POST | title, type, priority, description | New ticket object |
| Update ticket | `hl_ticket_update` | POST | ticket_uuid, title, type, priority, description | Updated ticket object |
| Add comment | `hl_ticket_comment` | POST | ticket_uuid, comment_text | New comment object |
| Change status | `hl_ticket_status` | POST | ticket_uuid, status | Updated ticket object |

### Response format

All endpoints return `wp_send_json_success(data)` or `wp_send_json_error(message)`.

Ticket objects include computed fields:
- `creator_name`: display_name of creator
- `creator_avatar`: `get_avatar_url()` at 32px
- `can_edit`: boolean, computed per current user
- `time_ago`: human-readable relative time

### Input validation

- **`type`, `status`, `priority`**: Whitelist validation against defined enum arrays. Invalid values silently ignored (param treated as unset).
- **`search`**: `$wpdb->esc_like()` + `$wpdb->prepare()` with `LIKE %s`. Matches `title` and `description` fields. Debounced 300ms client-side, minimum 2 characters. Combined with active filters via AND logic.
- **`per_page`**: `absint()`, clamped to `[1, 50]`, default 25.
- **`page`**: `absint()`, minimum 1.
- **`ticket_uuid`**: Validate format with `wp_is_uuid()` before querying.
- **`comment_text`**: Non-empty after trim. Max length 5000 characters. Error returned if exceeded.
- **`title`**: Non-empty after trim. Max 255 characters.
- **`description`**: Non-empty after trim. Sanitized with `wp_kses_post()` on write.

### Audit logging

All mutations call `HL_Audit_Service::log()` with `entity_type => 'ticket'`:
- Ticket create: logs ticket_id, title, type, priority
- Ticket update: logs ticket_id, changed fields
- Status change: logs ticket_id, `before_data` (old status), `after_data` (new status)
- Comment create: logs ticket_id, comment_id

## 6. CSS Classes

Following `.hlft-` prefix (HL Feature Tracker):

- `.hlft-wrapper` — page container
- `.hlft-toolbar` — action bar (new button + filters)
- `.hlft-table` — tickets table
- `.hlft-row` — table row (clickable)
- `.hlft-type-dot` — colored type indicator
- `.hlft-priority-badge` — priority badge (colored by level)
- `.hlft-status-pill` — status pill (colored by status)
- `.hlft-avatar` — 32px circle avatar
- `.hlft-modal` — modal overlay
- `.hlft-modal-box` — modal content container
- `.hlft-comment` — single comment block
- `.hlft-comment-form` — comment textarea + submit
- `.hlft-empty` — empty state message
- `.hlft-filters` — filter dropdown group

### Color mappings

**Type colors:**
- Bug: `--hl-error`
- Improvement: `--hl-warning`
- Feature Request: `--hl-accent`

**Priority colors:**
- Critical: `--hl-error`
- High: `--hl-warning`
- Medium: `--hl-interactive`
- Low: `--hl-text-secondary`

**Status colors:**
- Open: `--hl-interactive`
- In Review: `--hl-warning`
- In Progress: `--hl-interactive-dark`
- Resolved: `--hl-accent`
- Closed: `--hl-text-secondary`

### Modal sizing

The feature tracker modal uses `.hlft-modal-box` (NOT the generic `.hl-modal-box` which is 400px wide). Sizing: `max-width: 640px`, `max-height: 85vh`, `overflow-y: auto` on the content area. Header pinned at top. On mobile (<768px), modal goes full-screen width.

## 7. Sidebar Menu Integration

Added to `get_menu_items_for_current_user()` in `HL_BuddyBoss_Integration`:

```php
if ( current_user_can( 'manage_hl_core' ) ) {
    $items[] = array(
        'label' => 'Feature Tracker',
        'icon'  => 'dashicons-feedback',
        'url'   => $feature_tracker_url,
    );
}
```

Positioned after existing coach/admin items, before the admin (WP Admin) link.

## 8. Edge Cases

- **Concurrent edits**: Last-write-wins. The 2-hour edit window and small team make conflicts extremely unlikely.
- **Long descriptions**: Modal scrolls internally. Description textarea has no character limit but UI provides reasonable height.
- **Deleted users**: If a ticket creator's WP account is deleted, display "Unknown User" with a default avatar.
- **Empty filters**: If filters return no results, show "No tickets match your filters" with a "Clear filters" link.
- **XSS prevention**: All output escaped with `esc_html()` / `esc_attr()`. Description sanitized with `wp_kses_post()` on write; stored value trusted on read. Comment text is `esc_html()` only (plain text).
- **Edit window expiry**: If a user attempts an edit after the 2-hour window expires (e.g., had modal open for a long time), server returns clear error message rather than a generic failure.

## 9. What This Design Does NOT Include

- **Changelog page**: Resolved tickets have `resolved_at` timestamps. A changelog view can be built later as a separate shortcode if needed.
- **Email notifications**: No emails on ticket creation or status changes. Can be added later.
- **File attachments**: No screenshot/file upload. Staff can describe issues in text. Can be added later with WP media library integration.
- **Assignment**: No assigned_to field. The team is small enough that status + comments provide sufficient coordination.
- **Pagination**: Initial build loads all tickets (without description in list payload). If volume exceeds ~100 tickets, add AJAX pagination. The API already supports `page`/`per_page` params.
- **Mobile-specific layout**: Card view for small screens, full-screen modals. Primary users are desktop-based. Add responsive breakpoints if mobile usage is observed.
- **Rich text toolbar**: V1 uses plain textarea for descriptions. Add a minimal formatting toolbar (bold, italic, list, link) if staff request it.
- **Real-time modal refresh**: If another user updates a ticket while a modal is open, the viewer sees stale data until they reopen. Acceptable for a small team.
- **Comment pagination**: V1 loads all comments in the detail modal. Add pagination if any ticket exceeds ~50 comments.
- **Column sorting**: If user-facing sort-by-column is added later, must use whitelist approach for ORDER BY columns (never interpolate user input into ORDER BY).
