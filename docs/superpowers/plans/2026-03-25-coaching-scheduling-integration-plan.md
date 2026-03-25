# Coaching Session Scheduling Integration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add self-service coaching session scheduling with Microsoft Graph (Outlook calendar) and Zoom meeting auto-creation, integrated into existing component pages and coaching frontend.

**Architecture:** `HL_Scheduling_Service` orchestrates three concerns: slot calculation (availability minus Outlook conflicts), external API calls (Graph + Zoom), and the existing `HL_Coaching_Service` for DB persistence. Two new integration classes handle API authentication and HTTP calls. Frontend scheduling lives inside the existing Component Page dispatcher.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, Microsoft Graph API v1.0 (client credentials OAuth2), Zoom Server-to-Server OAuth API, inline CSS/JS (no build tools — follows existing plugin pattern).

**Design Spec:** `docs/superpowers/specs/2026-03-25-coaching-scheduling-integration-design.md` — READ THIS FIRST for full design rationale and decisions.

---

## CRITICAL: Demo Artifact Cleanup

> **A demo was given to Housman Learning on ~2026-03-24.** Some DB tables, options, pages, and/or calendar-related artifacts may have been created temporarily for that demo. Before building, you MUST:
>
> 1. **Audit for demo artifacts:** Search for any scheduling-related DB tables, wp_options, or pages that are NOT part of the canonical schema in `class-hl-installer.php`. Look for temporary calendar pages, scheduling option rows, extra tables.
> 2. **If you find demo artifacts and are unsure whether they should be kept or deleted — ASK the user.** Do not silently delete anything you're unsure about.
> 3. **You may reuse/recycle demo pages and elements** if they fit the new design. For example, if a "Schedule Session" page already exists, you can repurpose it rather than creating a new one.
> 4. **Preserve all existing forms.** The Action Plan, RP Notes, Classroom Visit, and Self-Reflection forms are production code — do NOT delete them.

---

## Codebase Orientation (Read Before Starting)

### How This Plugin Works

- **Main file:** `hl-core.php` (at plugin root, NOT in `includes/`) — Singleton `HL_Core::instance()`. All classes loaded via explicit `require_once` in `load_dependencies()`. Load order matters (utilities → models → repos → services → integrations → admin → frontend → CLI).
- **Class prefix:** `HL_` — **Table prefix:** `hl_` — **Singleton** pattern on most classes.
- **DB schema:** `includes/class-hl-installer.php` — `create_tables()` defines all tables via `dbDelta()`. Migrations are idempotent private static methods called from `maybe_upgrade()`. Current revision: **23**.
- **Shortcodes:** `includes/frontend/class-hl-shortcodes.php` — `register_shortcodes()` maps tags to render methods. Each renderer is its own class file.
- **AJAX pattern:** `wp_ajax_{action_name}` hooks, nonce verification via `wp_verify_nonce()`, responses via `wp_send_json_success()` / `wp_send_json_error()`.
- **Admin settings:** `includes/admin/class-hl-admin-settings.php` — Tab-based nav. Add tab slug to `$tabs` array (line ~81), add case to `render_page()` switch (line ~57).
- **CSS/JS:** All inline within PHP render methods. No build tools, no external CSS/JS files.
- **Timestamps:** The codebase uses `current_time('mysql')` (WordPress site timezone), NOT UTC. All new code MUST follow this convention.

### Key Existing Files You'll Modify

| File | What It Does | Lines to Know |
|---|---|---|
| `includes/class-hl-installer.php` | DB schema + migrations | `hl_coaching_session` CREATE at line ~1621, `hl_coach_availability` at ~1856, `maybe_upgrade()` at ~124, revision at ~127 |
| `hl-core.php` (plugin root) | Plugin bootstrap, class loading | `load_dependencies()` — services at ~99-119, integrations at ~122-124, frontend at ~148-188 |
| `includes/services/class-hl-coaching-service.php` | Session CRUD, status transitions | `create_session()` at line ~425, `reschedule_session()` at ~204, `$insert_data` array at ~437-452 |
| `includes/frontend/class-hl-frontend-component-page.php` | Component type dispatcher | `coaching_session_attendance` branch at line ~269-273 (currently a placeholder notice) |
| `includes/frontend/class-hl-frontend-my-coaching.php` | Mentor's coaching hub page | Loads enrollment → coach → sessions, renders cards |
| `includes/frontend/class-hl-shortcodes.php` | Shortcode registration | `register_shortcodes()` at ~28, constructor hooks at ~16-26 |
| `includes/admin/class-hl-admin-settings.php` | Admin settings tabs | `$tabs` array at ~80-84, `render_page()` switch at ~57-69 |
| `includes/services/class-hl-coach-dashboard-service.php` | Coach availability CRUD | `get_availability()` at ~340, `save_availability()` at ~352 |

### Key Existing Tables

- `hl_coaching_session` — session_id, session_uuid, cycle_id, coach_user_id, mentor_enrollment_id, session_number, session_title, meeting_url, session_status, session_datetime, etc.
- `hl_coach_availability` — availability_id, coach_user_id, day_of_week (0=Sun,6=Sat), start_time (TIME), end_time (TIME)
- `hl_component` — component_id, pathway_id, component_type (ENUM), title, ordering_hint, complete_by (DATE), external_ref (JSON)
- `hl_component_drip_rule` — rule_id, component_id, drip_type (ENUM: fixed_date, after_completion_delay), release_at_date, base_component_id, delay_days

---

## File Structure

### New Files (6)

| File | Responsibility |
|---|---|
| `includes/integrations/class-hl-microsoft-graph.php` | Microsoft Graph API client: client-credentials OAuth2 token management, calendar read/create/update/delete. Single class, no dependencies beyond WordPress HTTP API. |
| `includes/integrations/class-hl-zoom-integration.php` | Zoom S2S OAuth API client: token management, meeting create/update/delete. Single class, no dependencies beyond WordPress HTTP API. |
| `includes/services/class-hl-scheduling-service.php` | Orchestrator: available slot calculation, book/reschedule/cancel flows. Calls Graph, Zoom, HL_Coaching_Service, and email service. Contains AJAX handler registration. |
| `includes/services/class-hl-scheduling-email-service.php` | Email notifications: booked/rescheduled/cancelled/fallback templates. Uses `wp_mail()`. |
| `includes/frontend/class-hl-frontend-schedule-session.php` | Component page scheduling UI renderer: date picker, AJAX slot loading, confirm booking. Inline CSS/JS. |
| `includes/admin/class-hl-admin-scheduling-settings.php` | Admin settings subtab: API credentials (encrypted), scheduling rules, test connection buttons. |

### Modified Files (6)

| File | Changes |
|---|---|
| `includes/class-hl-installer.php` | Add 6 columns to `hl_coaching_session`, migration method, bump revision to 24 |
| `hl-core.php` (plugin root) | Add `require_once` for 6 new files in `load_dependencies()` |
| `includes/services/class-hl-coaching-service.php` | `create_session()`: accept + insert `component_id`, add uniqueness check. `reschedule_session()`: forward `component_id`, `session_number`, timezone columns. |
| `includes/frontend/class-hl-frontend-component-page.php` | Replace placeholder notice at line ~269-273 with delegation to `HL_Frontend_Schedule_Session` |
| `includes/frontend/class-hl-frontend-my-coaching.php` | Rewrite to coaching sessions hub: component list table with status, locking, grouped by cycle |
| `includes/admin/class-hl-admin-settings.php` | Add "Scheduling & Integrations" tab |

---

## Tasks

### Task 0: Demo Artifact Audit & Cleanup

**Files:**
- Audit: entire codebase + test server DB
- Potentially delete: demo pages, temporary options, extra tables

- [ ] **Step 0.1: Search for demo scheduling artifacts**

Search the codebase and database for anything scheduling-related that was added for the demo:

```bash
# Search for scheduling-related options
wp option list --search="*schedul*" --search="*calendar*" --search="*zoom*" --search="*microsoft*" --search="*graph*" --format=table

# Search for demo pages
wp post list --post_type=page --post_status=any --fields=ID,post_title,post_content --format=table | grep -i "schedul\|calendar\|booking"

# Search for extra DB tables not in installer
wp db query "SHOW TABLES LIKE '%hl_%'" --skip-column-names
```

Compare the found tables against the canonical list in `class-hl-installer.php` `create_tables()`.

- [ ] **Step 0.2: Catalog findings and ASK the user**

List everything you found. For each item, state whether you think it should be kept, reused, or deleted. **Wait for user confirmation before deleting anything.**

- [ ] **Step 0.3: Clean up confirmed artifacts**

Delete/modify only what the user approved. Commit.

```bash
git add -A && git commit -m "chore: clean up demo scheduling artifacts"
```

---

### Task 1: DB Schema — Add Columns to `hl_coaching_session`

**Files:**
- Modify: `includes/class-hl-installer.php`

- [ ] **Step 1.1: Add columns to CREATE TABLE statement**

In `includes/class-hl-installer.php`, find the `hl_coaching_session` CREATE TABLE (line ~1621). Add these columns BEFORE the `created_at` line:

```php
            component_id bigint(20) unsigned NULL COMMENT 'Links to hl_component for specific coaching component',
            zoom_meeting_id bigint(20) unsigned NULL COMMENT 'Zoom meeting ID for API update/delete',
            outlook_event_id varchar(255) NULL COMMENT 'Microsoft Graph calendar event ID',
            booked_by_user_id bigint(20) unsigned NULL COMMENT 'User who created the booking',
            mentor_timezone varchar(100) NULL COMMENT 'IANA timezone at booking time',
            coach_timezone varchar(100) NULL COMMENT 'IANA timezone at booking time',
```

Also add these indexes after the existing KEY lines:

```php
            KEY component_id (component_id),
            KEY booked_by_user_id (booked_by_user_id)
```

- [ ] **Step 1.2: Create migration method**

Add a new private static method in `HL_Installer`:

```php
/**
 * Migration: Add scheduling integration columns to hl_coaching_session.
 * Idempotent — safe to run multiple times.
 */
private static function migrate_coaching_scheduling_columns() {
    global $wpdb;
    $table = "{$wpdb->prefix}hl_coaching_session";

    $table_exists = $wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s', $table
    )) === $table;
    if (!$table_exists) return;

    $column_exists = function($column) use ($wpdb, $table) {
        return !empty($wpdb->get_row($wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table, $column
        )));
    };

    $columns = array(
        'component_id'      => "ADD COLUMN component_id bigint(20) unsigned NULL COMMENT 'Links to hl_component' AFTER rescheduled_from_session_id",
        'zoom_meeting_id'   => "ADD COLUMN zoom_meeting_id bigint(20) unsigned NULL COMMENT 'Zoom meeting ID' AFTER component_id",
        'outlook_event_id'  => "ADD COLUMN outlook_event_id varchar(255) NULL COMMENT 'Graph event ID' AFTER zoom_meeting_id",
        'booked_by_user_id' => "ADD COLUMN booked_by_user_id bigint(20) unsigned NULL COMMENT 'Booking creator' AFTER outlook_event_id",
        'mentor_timezone'   => "ADD COLUMN mentor_timezone varchar(100) NULL COMMENT 'IANA timezone' AFTER booked_by_user_id",
        'coach_timezone'    => "ADD COLUMN coach_timezone varchar(100) NULL COMMENT 'IANA timezone' AFTER mentor_timezone",
    );

    foreach ($columns as $col => $alter) {
        if (!$column_exists($col)) {
            $wpdb->query("ALTER TABLE `{$table}` {$alter}");
        }
    }

    // Add indexes if missing
    $index_exists = function($index_name) use ($wpdb, $table) {
        return !empty($wpdb->get_row($wpdb->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, $index_name
        )));
    };

    if (!$index_exists('component_id')) {
        $wpdb->query("ALTER TABLE `{$table}` ADD KEY component_id (component_id)");
    }
    if (!$index_exists('booked_by_user_id')) {
        $wpdb->query("ALTER TABLE `{$table}` ADD KEY booked_by_user_id (booked_by_user_id)");
    }
}
```

- [ ] **Step 1.3: Wire migration into maybe_upgrade()**

In `maybe_upgrade()` (line ~124):
1. Change `$current_revision = 23;` to `$current_revision = 24;`
2. Add below the existing migration calls:

```php
if ((int) $stored < 24) {
    self::migrate_coaching_scheduling_columns();
}
```

- [ ] **Step 1.4: Call migration from create_tables()**

In `create_tables()`, add a call to the migration method BEFORE the `dbDelta()` call. This follows the existing pattern for rename migrations (e.g., `migrate_activity_to_component()`) and ensures compatibility with existing installations that run `create_tables()` directly:

```php
self::migrate_coaching_scheduling_columns();
```

Note: For fresh installs, `dbDelta()` will handle the columns via the CREATE TABLE definition from Step 1.1. The migration call here is for existing installations where the table already exists.

- [ ] **Step 1.5: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat: add scheduling columns to hl_coaching_session (revision 24)

Add component_id, zoom_meeting_id, outlook_event_id, booked_by_user_id,
mentor_timezone, coach_timezone columns with idempotent migration."
```

---

### Task 2: Admin Settings — Scheduling & Integrations

**Files:**
- Create: `includes/admin/class-hl-admin-scheduling-settings.php`
- Modify: `includes/admin/class-hl-admin-settings.php`
- Modify: `hl-core.php` (plugin root)

- [ ] **Step 2.1: Create the admin settings class**

Create `includes/admin/class-hl-admin-scheduling-settings.php`.

This class manages:
- Scheduling rules: `session_duration`, `min_lead_time_hours`, `max_lead_time_days`, `min_cancel_notice_hours`
- Microsoft 365 credentials: `tenant_id`, `client_id`, `client_secret`
- Zoom credentials: `account_id`, `client_id`, `client_secret`
- Test Connection buttons for both
- Encryption of secrets using `openssl_encrypt` (AES-256-CBC) with random IV prepended to ciphertext, using `AUTH_KEY` as encryption key

**Settings storage:**
- Scheduling rules stored in `wp_options` as `hl_scheduling_settings` (JSON)
- Microsoft creds stored in `wp_options` as `hl_microsoft_graph_settings` (JSON, secrets encrypted)
- Zoom creds stored in `wp_options` as `hl_zoom_settings` (JSON, secrets encrypted)

**Key methods:**
- `render_page_content()` — renders the form with all sections
- `handle_save()` — processes POST, encrypts secrets, saves to wp_options
- `encrypt_value($plaintext)` / `decrypt_value($ciphertext)` — AES-256-CBC with random IV
- `get_scheduling_settings()` — returns array with defaults merged
- `get_microsoft_settings()` — returns array with decrypted secrets
- `get_zoom_settings()` — returns array with decrypted secrets
- `ajax_test_microsoft_connection()` — AJAX handler, attempts token fetch
- `ajax_test_zoom_connection()` — AJAX handler, attempts token fetch

**Encryption pattern:**
```php
public static function encrypt_value($plaintext) {
    if (empty($plaintext)) return '';
    $key = substr(hash('sha256', AUTH_KEY), 0, 32);
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

public static function decrypt_value($ciphertext) {
    if (empty($ciphertext)) return '';
    $key = substr(hash('sha256', AUTH_KEY), 0, 32);
    $data = base64_decode($ciphertext);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}
```

**Form rendering:** Follow the existing admin page pattern — white card, clean labels, descriptive help text. Secret fields show `********` with a "Change" link. Include collapsible setup guides below each integration section.

- [ ] **Step 2.2: Register the settings tab**

In `includes/admin/class-hl-admin-settings.php`:

1. Add to `$tabs` array (~line 81):
```php
'scheduling' => __('Scheduling & Integrations', 'hl-core'),
```

2. Add case to `render_page()` switch (~line 57):
```php
case 'scheduling':
    HL_Admin_Scheduling_Settings::instance()->render_page_content();
    break;
```

3. Add to `handle_early_actions()` (~line 28). This method has a switch on `$tab`. Add a new case BEFORE the `default` case (note: `default` handles imports, so don't replace it):
```php
case 'scheduling':
    if (isset($_POST['hl_scheduling_settings_nonce'])) {
        HL_Admin_Scheduling_Settings::instance()->handle_save();
    }
    break;
```

- [ ] **Step 2.3: Register in plugin bootstrap**

In `hl-core.php` (plugin root) `load_dependencies()`, add the `require_once` in the services/integrations section (~line 119), **NOT** inside the `is_admin()` block. This is because AJAX hooks (`wp_ajax_*`) fire via `admin-ajax.php` and need the class loaded for both admin and frontend contexts:

```php
require_once plugin_dir_path(__FILE__) . 'admin/class-hl-admin-scheduling-settings.php';
```

- [ ] **Step 2.4: Commit**

```bash
git add includes/admin/class-hl-admin-scheduling-settings.php includes/admin/class-hl-admin-settings.php hl-core.php
git commit -m "feat: add Scheduling & Integrations admin settings tab

Scheduling rules (duration, lead time, cancel window), Microsoft 365
credentials, Zoom credentials. AES-256-CBC encryption for secrets.
Test Connection buttons for both services."
```

---

### Task 3: Microsoft Graph Integration

**Files:**
- Create: `includes/integrations/class-hl-microsoft-graph.php`
- Modify: `hl-core.php` (plugin root) (add require_once)

- [ ] **Step 3.1: Create the Graph API client class**

Create `includes/integrations/class-hl-microsoft-graph.php`.

**Class: `HL_Microsoft_Graph`** (Singleton)

**Methods:**
- `get_access_token()` — Client credentials flow. POST to `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`. Cache in transient `hl_graph_token` (~55 min TTL). Returns token string or WP_Error.
- `get_calendar_events($user_email, $start_datetime, $end_datetime)` — GET `/users/{email}/calendar/calendarView?startDateTime={}&endDateTime={}`. Returns array of events or WP_Error.
- `create_calendar_event($organizer_email, $event_data)` — POST `/users/{email}/calendar/events`. Returns event array (with `id`) or WP_Error.
- `update_calendar_event($organizer_email, $event_id, $event_data)` — PATCH `/users/{email}/calendar/events/{id}`. Returns updated event or WP_Error.
- `delete_calendar_event($organizer_email, $event_id)` — DELETE `/users/{email}/calendar/events/{id}`. Returns true or WP_Error.
- `build_event_payload($session_data)` — Formats session data into Graph API event JSON (subject, start/end with timezone, body HTML with Zoom link, attendees).
- `is_configured()` — Returns true if tenant_id, client_id, client_secret are all set.

**HTTP calls:** Use `wp_remote_post()` / `wp_remote_get()` with `Authorization: Bearer {token}` header. Base URL: `https://graph.microsoft.com/v1.0`.

**Coach email resolution:** Check `get_user_meta($coach_id, 'hl_microsoft_email', true)` first, fall back to `get_userdata($coach_id)->user_email`.

**Error handling:** All methods return `WP_Error` on failure. Log errors via `HL_Audit_Service::log()`.

- [ ] **Step 3.2: Register in bootstrap**

In `hl-core.php` (plugin root) `load_dependencies()`, in the integrations section (~line 122-124):

```php
require_once plugin_dir_path(__FILE__) . 'integrations/class-hl-microsoft-graph.php';
```

- [ ] **Step 3.3: Commit**

```bash
git add includes/integrations/class-hl-microsoft-graph.php hl-core.php
git commit -m "feat: add Microsoft Graph API integration client

Client credentials OAuth2 flow, calendar CRUD operations,
token caching via transients, coach email resolution with
usermeta override support."
```

---

### Task 4: Zoom Integration

**Files:**
- Create: `includes/integrations/class-hl-zoom-integration.php`
- Modify: `hl-core.php` (plugin root) (add require_once)

- [ ] **Step 4.1: Create the Zoom API client class**

Create `includes/integrations/class-hl-zoom-integration.php`.

**Class: `HL_Zoom_Integration`** (Singleton)

**Methods:**
- `get_access_token()` — S2S OAuth. POST to `https://zoom.us/oauth/token` with `grant_type=account_credentials`, `account_id`. Basic auth header with client_id:client_secret. Cache in transient `hl_zoom_token` (~55 min TTL). Returns token string or WP_Error.
- `create_meeting($host_email, $meeting_data)` — POST `/users/{email}/meetings`. Returns meeting array (with `id`, `join_url`) or WP_Error.
- `update_meeting($meeting_id, $meeting_data)` — PATCH `/meetings/{id}`. Returns true or WP_Error.
- `delete_meeting($meeting_id)` — DELETE `/meetings/{id}`. Returns true or WP_Error.
- `build_meeting_payload($session_data)` — Formats: topic, type=2, start_time, timezone, duration, settings (join_before_host=true, waiting_room=false).
- `is_configured()` — Returns true if account_id, client_id, client_secret are all set.

**HTTP calls:** Use `wp_remote_post()` / `wp_remote_get()`. Base URL: `https://api.zoom.us/v2`.

**Coach email resolution:** Check `get_user_meta($coach_id, 'hl_zoom_email', true)` first, fall back to `get_userdata($coach_id)->user_email`.

**Note on zoom_meeting_id:** Zoom API may return meeting ID as string. Cast to `(int)` before storing in DB.

- [ ] **Step 4.2: Register in bootstrap**

In `hl-core.php` (plugin root) `load_dependencies()`, in the integrations section:

```php
require_once plugin_dir_path(__FILE__) . 'integrations/class-hl-zoom-integration.php';
```

- [ ] **Step 4.3: Commit**

```bash
git add includes/integrations/class-hl-zoom-integration.php hl-core.php
git commit -m "feat: add Zoom Server-to-Server OAuth integration client

S2S OAuth token management, meeting CRUD operations, token caching
via transients, coach email resolution with usermeta override."
```

---

### Task 5: Email Notification Service

**Files:**
- Create: `includes/services/class-hl-scheduling-email-service.php`
- Modify: `hl-core.php` (plugin root) (add require_once)

- [ ] **Step 5.1: Create the email service class**

Create `includes/services/class-hl-scheduling-email-service.php`.

**Class: `HL_Scheduling_Email_Service`** (Singleton — use `HL_Scheduling_Email_Service::instance()` from other classes)

**Methods:**
- `send_session_booked($session_data)` — Sends to mentor + coach
- `send_session_rescheduled($old_session, $new_session)` — Sends to both
- `send_session_cancelled($session_data, $cancelled_by_name)` — Sends to both
- `send_outlook_fallback($session_data, $error_message)` — To coach + admin
- `send_zoom_fallback($session_data, $error_message)` — To coach + admin
- `get_email_template($template_name, $vars)` — Builds branded HTML email body
- `get_admin_email()` — Returns `get_option('admin_email')`

**Email body pattern:** Simple HTML with HLA branding header, session details block (coach, mentor, date, time, timezone, Zoom link as button), footer.

**All times displayed in the recipient's timezone** — coach emails use `coach_timezone`, mentor emails use `mentor_timezone`.

**Uses `wp_mail()`** with `Content-Type: text/html` header.

- [ ] **Step 5.2: Register in bootstrap**

In `hl-core.php` (plugin root) `load_dependencies()`, in the services section (~line 99-119):

```php
require_once plugin_dir_path(__FILE__) . 'services/class-hl-scheduling-email-service.php';
```

- [ ] **Step 5.3: Commit**

```bash
git add includes/services/class-hl-scheduling-email-service.php hl-core.php
git commit -m "feat: add scheduling email notification service

Branded HTML emails for booked/rescheduled/cancelled sessions,
plus fallback notifications for Outlook and Zoom API failures."
```

---

### Task 6: Scheduling Service (Orchestrator)

**Files:**
- Create: `includes/services/class-hl-scheduling-service.php`
- Modify: `hl-core.php` (plugin root) (add require_once)
- Modify: `includes/frontend/class-hl-shortcodes.php` (register AJAX hooks)

- [ ] **Step 6.1: Create the scheduling service class**

Create `includes/services/class-hl-scheduling-service.php`.

**Class: `HL_Scheduling_Service`** (Singleton)

**Constructor:** Register AJAX hooks:
```php
add_action('wp_ajax_hl_get_available_slots', array($this, 'ajax_get_available_slots'));
add_action('wp_ajax_hl_book_session', array($this, 'ajax_book_session'));
add_action('wp_ajax_hl_reschedule_session', array($this, 'ajax_reschedule_session'));
add_action('wp_ajax_hl_cancel_session', array($this, 'ajax_cancel_session'));
```

**Core Methods:**

`get_available_slots($coach_user_id, $date_string, $mentor_timezone)`:
1. Get coach timezone from `get_user_meta($coach_user_id, 'hl_timezone', true)`, default to `wp_timezone_string()`
2. Get day_of_week for the requested date (in coach's timezone)
3. Query `hl_coach_availability` for that day_of_week
4. Slice availability blocks into `session_duration`-minute slots (from `hl_scheduling_settings`)
5. Check transient cache: `hl_calendar_{coach_id}_{date}` (2-5 min TTL)
6. If cache miss and Graph is configured: call `HL_Microsoft_Graph::get_calendar_events()` with padded UTC range
7. If Graph fails: proceed with availability only (return flag `outlook_unavailable = true`)
8. Query `hl_coaching_session` for that date where `coach_user_id` matches and `session_status = 'scheduled'`
9. Subtract Outlook busy times from slots
10. Subtract existing HL sessions from slots
11. Apply lead time rules: remove slots before `now + min_lead_time_hours` and after `now + max_lead_time_days`
12. Convert remaining slots to `$mentor_timezone` for display
13. Return array of `{ start_time, end_time, display_label, start_utc }`

`book_session($data)`:
1. Validate: `mentor_enrollment_id`, `coach_user_id`, `component_id`, `date`, `start_time`, `timezone`
2. Permission check: caller is the mentor (owns enrollment), assigned coach, or admin
3. Uniqueness check: no existing `scheduled` session for this `(component_id, mentor_enrollment_id)`
4. Resolve coach + mentor data (names, emails)
5. Build session title: `"Coaching Session - {Mentor Name}/{Coach Name}"`
6. Create `hl_coaching_session` record via `HL_Coaching_Service::create_session()` — pass `component_id`, `booked_by_user_id`, timezone columns
7. If Zoom configured: create Zoom meeting via `HL_Zoom_Integration::create_meeting()`. Update session with `zoom_meeting_id` + `meeting_url`. On failure: send Zoom fallback email.
8. If Graph configured: create Outlook event via `HL_Microsoft_Graph::create_calendar_event()`. Update session with `outlook_event_id`. On failure: send Outlook fallback email.
9. Send booked notification emails via `HL_Scheduling_Email_Service::send_session_booked()`
10. Return `{ session_id, meeting_url }`

`reschedule_session_with_integrations($session_id, $new_date, $new_start_time, $timezone)`:
1. Get existing session record
2. Permission check
3. Check `min_cancel_notice_hours` setting
4. Delete old Zoom meeting (if `zoom_meeting_id` set)
5. Delete old Outlook event (if `outlook_event_id` set)
6. Call `HL_Coaching_Service::reschedule_session()` — returns new session_id
7. Create new Zoom meeting for new session. Update new session record.
8. Create new Outlook event for new session. Update new session record.
9. Send reschedule notification emails
10. Return new session data

`cancel_session_with_integrations($session_id)`:
1. Get existing session record
2. Permission check (coach or admin only — NOT mentor)
3. Check `min_cancel_notice_hours` setting
4. Delete Zoom meeting (if set)
5. Delete Outlook event (if set)
6. Call `HL_Coaching_Service::cancel_session()`
7. Send cancellation notification emails

**AJAX handlers** follow the standard pattern:
```php
public function ajax_get_available_slots() {
    check_ajax_referer('hl_scheduling_nonce', '_nonce');
    // ... validate params, call get_available_slots(), wp_send_json_success()
}
```

- [ ] **Step 6.2: Register in bootstrap and shortcodes**

In `hl-core.php` (plugin root) `load_dependencies()`, services section:
```php
require_once plugin_dir_path(__FILE__) . 'services/class-hl-scheduling-service.php';
```

The AJAX hooks are registered in the class constructor, which fires on `require_once`. No changes needed in `class-hl-shortcodes.php` since AJAX hooks don't go through shortcodes.

**However**, you need to ensure the class is instantiated. Add to `init_hooks()` or similar:
```php
HL_Scheduling_Service::instance();
```

- [ ] **Step 6.3: Commit**

```bash
git add includes/services/class-hl-scheduling-service.php hl-core.php
git commit -m "feat: add scheduling orchestration service

Available slot calculation (availability minus Outlook conflicts minus
existing sessions), book/reschedule/cancel with Zoom + Outlook + email
integration, 4 AJAX endpoints, permission checks, lead time rules."
```

---

### Task 7: Modify HL_Coaching_Service

**Files:**
- Modify: `includes/services/class-hl-coaching-service.php`

- [ ] **Step 7.1: Update create_session() to accept new columns**

In `create_session()` (~line 425), add to the `$insert_data` array (~line 437-452):

```php
'component_id'      => !empty($data['component_id']) ? absint($data['component_id']) : null,
'zoom_meeting_id'   => !empty($data['zoom_meeting_id']) ? absint($data['zoom_meeting_id']) : null,
'outlook_event_id'  => !empty($data['outlook_event_id']) ? sanitize_text_field($data['outlook_event_id']) : null,
'booked_by_user_id' => !empty($data['booked_by_user_id']) ? absint($data['booked_by_user_id']) : null,
'mentor_timezone'   => !empty($data['mentor_timezone']) ? sanitize_text_field($data['mentor_timezone']) : null,
'coach_timezone'    => !empty($data['coach_timezone']) ? sanitize_text_field($data['coach_timezone']) : null,
```

- [ ] **Step 7.2: Add uniqueness check to create_session()**

Add BEFORE the `$wpdb->insert()` call:

```php
// Enforce one scheduled session per component per enrollment.
if (!empty($insert_data['component_id'])) {
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT session_id FROM {$wpdb->prefix}hl_coaching_session
         WHERE component_id = %d AND mentor_enrollment_id = %d AND session_status = 'scheduled'",
        $insert_data['component_id'], $insert_data['mentor_enrollment_id']
    ));
    if ($existing) {
        return new WP_Error('duplicate_session',
            __('A scheduled session already exists for this component.', 'hl-core'));
    }
}
```

- [ ] **Step 7.3: Update reschedule_session() to forward new columns**

In `reschedule_session()` (~line 204), update the `$new_data` array (~line 222-230) to include:

```php
'component_id'      => $session['component_id'] ?? null,
'session_number'    => $session['session_number'] ?? null,
'mentor_timezone'   => $session['mentor_timezone'] ?? null,
'coach_timezone'    => $session['coach_timezone'] ?? null,
'booked_by_user_id' => get_current_user_id(),
```

- [ ] **Step 7.4: Commit**

```bash
git add includes/services/class-hl-coaching-service.php
git commit -m "feat: update coaching service for scheduling integration

create_session() accepts component_id + scheduling columns, enforces
uniqueness (one scheduled session per component per enrollment).
reschedule_session() forwards component_id, session_number, timezones."
```

---

### Task 8: Frontend — Component Page Scheduling UI

**Files:**
- Create: `includes/frontend/class-hl-frontend-schedule-session.php`
- Modify: `includes/frontend/class-hl-frontend-component-page.php`
- Modify: `hl-core.php` (plugin root) (add require_once)

- [ ] **Step 8.1: Create the scheduling UI renderer**

Create `includes/frontend/class-hl-frontend-schedule-session.php`.

**Class: `HL_Frontend_Schedule_Session`**

**Method: `render($component, $enrollment, $cycle_id)`**

This renders INSIDE the component page (not a standalone page). It receives the component and enrollment data from the dispatcher.

**Two states:**

**State A (Not scheduled):**
- Hero header: component title, coach name, session number
- Check drip rule locking: query `hl_component_drip_rule` for this `component_id`. If `drip_type = 'fixed_date'` and `release_at_date > now`, show locked state with release date. If `drip_type = 'after_completion_delay'`, check if base component is completed + delay elapsed.
- If unlocked: render date picker (month calendar) + slot container (populated via AJAX) + confirm button
- JavaScript: on date click, AJAX call to `hl_get_available_slots`, render slot pills. On slot click + confirm, AJAX call to `hl_book_session`.

**State B (Scheduled or completed):**
- Two tabs: "Session Details" | "Action Plan & Results"
- Session Details: status badge, date/time in mentor TZ, Zoom link button, reschedule button (mentor/coach/admin), cancel button (coach/admin only per `min_cancel_notice_hours`)
- Action Plan & Results: delegate to existing `HL_Coaching_Service::get_submissions()` and render forms. Use existing Action Plan renderer pattern.
- Reschedule: on click, toggle to State A UI with `?reschedule=SESSION_ID` context
- Cancel: confirmation modal, AJAX call to `hl_cancel_session`

**JavaScript AJAX pattern:**
```javascript
fetch(ajaxurl + '?action=hl_get_available_slots&_nonce=' + nonce + '&coach_user_id=' + coachId + '&date=' + date + '&timezone=' + tz)
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      renderSlots(data.data.slots);
    } else {
      showError(data.data.message);
    }
  });
```

**Nonce:** Output via `wp_create_nonce('hl_scheduling_nonce')` in a data attribute or inline JS variable.

**Mentor timezone detection:**
```javascript
var mentorTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
```

**CSS:** All inline, following the existing plugin pattern (see `class-hl-frontend-coach-availability.php` for reference). Use the project's design system: navy gradient hero, white cards with subtle borders, pill-style buttons for time slots.

- [ ] **Step 8.2: Update Component Page dispatcher**

In `includes/frontend/class-hl-frontend-component-page.php`, replace the `coaching_session_attendance` branch (~lines 269-273):

**Before:**
```php
if ($type === 'coaching_session_attendance') {
    echo '<div class="hl-notice hl-notice-info">' .
         esc_html__('This component is managed by your coach...', 'hl-core') .
         '</div>';
    return;
}
```

**After:**
```php
if ($type === 'coaching_session_attendance') {
    $renderer = new HL_Frontend_Schedule_Session();
    $renderer->render($component, $enrollment, (int) $enrollment['cycle_id']);
    return;
}
```

Note: `$component` and `$enrollment` are arrays available in scope from `render_available_view()`. `cycle_id` is derived from `$enrollment['cycle_id']` — this is the same pattern used by other renderers in this method (see lines ~278, ~285, ~292 for examples).

- [ ] **Step 8.3: Register in bootstrap**

In `hl-core.php` (plugin root), frontend section:
```php
require_once plugin_dir_path(__FILE__) . 'frontend/class-hl-frontend-schedule-session.php';
```

- [ ] **Step 8.4: Commit**

```bash
git add includes/frontend/class-hl-frontend-schedule-session.php includes/frontend/class-hl-frontend-component-page.php hl-core.php
git commit -m "feat: add scheduling UI to coaching session component pages

Date picker, AJAX-loaded time slots, booking confirmation. Two states:
schedule (date picker + slots) and view (session details + action plan tabs).
Drip rule locking, reschedule flow, cancel flow (coach/admin only)."
```

---

### Task 9: Frontend — My Coaching Page Enhancement

**Files:**
- Modify: `includes/frontend/class-hl-frontend-my-coaching.php`

- [ ] **Step 9.1: Read the current file thoroughly**

Read `includes/frontend/class-hl-frontend-my-coaching.php` in its entirety. Understand the current data loading, rendering, and any existing scheduling UI. The file currently shows coach info + upcoming/past sessions.

- [ ] **Step 9.2: Rewrite as coaching sessions hub**

Transform the page to show a component-based coaching sessions table:

1. **Load data:**
   - Get mentor's active enrollments
   - For each enrollment: get pathway, get coach assignment
   - Query `hl_component WHERE component_type = 'coaching_session_attendance' AND pathway_id = X ORDER BY ordering_hint`
   - For each component: join `hl_component_state` for completion, join `hl_coaching_session` (via `component_id`) for scheduling status
   - Query `hl_component_drip_rule` for release date locking

2. **Group by Cycle** if mentor has multiple enrollments

3. **Render table:**

| Component | Status | Actions |
|---|---|---|
| Coaching Session #1 | Completed 03/16/2026 | [View] |
| Coaching Session #2 | Scheduled 03/28/2026 | [View] |
| Coaching Session #3 | Not Scheduled (Complete by: 04/05/2026) | [View] |
| Coaching Session #4 | Not Scheduled (Release: 05/25/2026) | [View] (locked) |

4. **Status logic:**
   - Has `hl_coaching_session` with `session_status = 'attended'` → "Completed {date}"
   - Has `hl_coaching_session` with `session_status = 'scheduled'` → "Scheduled {date}"
   - No session + drip rule locked → "Not Scheduled (Release: {date})" + locked icon
   - No session + unlocked → "Not Scheduled" + (Complete by: {date}) if `complete_by` is set

5. **View button:** Links to component page URL: `?id={component_id}&enrollment={enrollment_id}` on the `[hl_component_page]` page.

6. **Locking:** If drip rule `release_at_date > now` or `after_completion_delay` not satisfied, View button is greyed/disabled.

- [ ] **Step 9.3: Commit**

```bash
git add includes/frontend/class-hl-frontend-my-coaching.php
git commit -m "feat: rewrite My Coaching as coaching sessions hub

Component-based table showing all coaching sessions with status
(completed/scheduled/not scheduled), locking via drip rules,
complete_by dates, grouped by cycle for multi-enrollment mentors."
```

---

### Task 10: Coach-Side Scheduling Enhancements

**Files:**
- Modify: `includes/frontend/class-hl-frontend-coach-mentor-detail.php`

- [ ] **Step 10.1: Add "Schedule Next Session" to Mentor Detail page**

Find the coach's Mentor Detail frontend file. Add a "Schedule Next Session" button that:
1. Finds the next unscheduled coaching session component for this mentor
2. Links to the component page for that component (so it uses the same scheduling UI)

If no unscheduled components remain, show "All sessions scheduled" instead.

- [ ] **Step 10.2: Commit**

```bash
git add includes/frontend/class-hl-frontend-coach-mentor-detail.php
git commit -m "feat: add Schedule Next Session button to coach mentor detail

Links to next unscheduled coaching component page for streamlined
coach-initiated scheduling."
```

---

### Task 11: Integration Testing & Deploy Verification

- [ ] **Step 11.1: Verify DB migration**

Deploy to test server and verify:
```bash
wp hl-core db-check  # or manually check table structure
wp db query "DESCRIBE wp_hl_coaching_session"
```

Confirm all 6 new columns exist with correct types.

- [ ] **Step 11.2: Verify admin settings**

1. Navigate to HL Core > Settings > Scheduling & Integrations
2. Save scheduling rules (change lead time, verify it persists)
3. Enter test Microsoft 365 credentials, click Test Connection
4. Enter test Zoom credentials, click Test Connection
5. Verify secrets are encrypted in `wp_options`

- [ ] **Step 11.3: End-to-end booking test**

1. As a mentor with a coach assigned, navigate to pathway → coaching session component
2. Verify date picker shows, available slots load via AJAX
3. Select a slot, confirm booking
4. Verify: `hl_coaching_session` record created with `component_id`, `zoom_meeting_id`, `outlook_event_id`
5. Verify: Zoom meeting exists (check Zoom admin)
6. Verify: Outlook calendar event exists on coach's calendar
7. Verify: Both mentor and coach received notification emails

- [ ] **Step 11.4: Reschedule test**

1. On the scheduled session component page, click Reschedule
2. Pick new date/time
3. Verify: old session → `rescheduled`, new session → `scheduled` with new Zoom + Outlook
4. Verify: both parties received reschedule emails

- [ ] **Step 11.5: Cancel test (as coach)**

1. As a coach, go to mentor's session, click Cancel
2. Verify: session → `cancelled`, Zoom meeting deleted, Outlook event deleted
3. Verify: both parties received cancellation emails

- [ ] **Step 11.6: My Coaching page test**

1. As a mentor, navigate to My Coaching
2. Verify: component table shows all coaching sessions with correct statuses
3. Verify: locked sessions show release dates and disabled View buttons
4. Verify: multi-cycle grouping if applicable

- [ ] **Step 11.7: Update STATUS.md and README.md**

Per CLAUDE.md rules, update both files:
- STATUS.md: Check off the scheduling item in Lower Priority, add new entries if needed
- README.md: Update "What's Implemented" section with scheduling feature details

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md and README.md with scheduling feature"
```

---

## Azure AD & Zoom Setup Instructions (For the Human)

While the developer implements the code, you (Mateo) need to set up the external services. Here are step-by-step instructions:

### Microsoft Azure AD App Registration

1. Go to https://portal.azure.com → Azure Active Directory → App Registrations → New Registration
2. Name: "HLA Coaching Calendar Integration"
3. Supported account types: "Accounts in this organizational directory only"
4. Redirect URI: leave blank (not needed for client credentials)
5. Click Register
6. **Copy:** Application (client) ID → this is your Client ID
7. **Copy:** Directory (tenant) ID → this is your Tenant ID
8. Go to Certificates & secrets → New client secret → Description: "HLA Plugin", Expiry: 24 months → Add
9. **Copy:** Secret Value (shown once!) → this is your Client Secret
10. Go to API permissions → Add a permission → Microsoft Graph → Application permissions
11. Add: `Calendars.ReadWrite`, `User.Read.All`
12. Click "Grant admin consent for [your org]" → Yes
13. Enter all three values in HL Core > Settings > Scheduling & Integrations

### Zoom Server-to-Server OAuth App

1. Go to https://marketplace.zoom.us → Develop → Build App
2. Choose "Server-to-Server OAuth"
3. App name: "HLA Coaching Scheduling"
4. **Copy:** Account ID, Client ID, Client Secret
5. Go to Scopes → Add: `meeting:write:admin`
6. Activate the app
7. Enter all three values in HL Core > Settings > Scheduling & Integrations
