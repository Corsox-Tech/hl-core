# Coach Zoom Meeting Settings (Ticket #31)

**Date:** 2026-04-22
**Status:** Draft v0.4 — Final gate self-critique applied
**Ticket:** #31 "Zoom links for coaches" (Christopher Love, prod, priority: critical)

## Problem

Every LMS coaching booking calls Zoom's Meetings API in `HL_Zoom_Integration::create_meeting()` with a hard-coded `settings` payload at `includes/integrations/class-hl-zoom-integration.php:185-189`:

```php
'settings' => array(
    'join_before_host' => true,
    'waiting_room'     => false,
    'auto_recording'   => 'none',
),
```

This payload **overrides** whatever the host coach has configured at their Zoom account level. Chris (Housman owner) has standardized settings on each coach's Zoom account, but those settings are silently overwritten on every booking. He filed #31 asking to use specific static URLs as a workaround. Static URLs would defeat the future plan of cloud recording + AI Companion summary attribution per session, so we instead need the LMS to honor configured settings on dynamic API-created meetings.

## Goals

1. Per-coach LMS-controlled Zoom meeting settings flow through to API-created meetings.
2. Admin sets a global default profile; each coach can override individual fields.
3. Recording + AI Companion remain configured at the **Zoom account level** (out of LMS scope).
4. Each session keeps a unique Zoom meeting ID/URL so future recording-attribution work is unblocked.
5. Backward compatible: any coach without overrides automatically uses admin defaults; admin defaults default to safe values so existing flows don't break.
6. Failure modes are surfaced clearly — bad config does not silently break bookings.
7. Idempotent and rate-limit-safe in all integration paths (preflight, booking, retry).

## Non-Goals

- Recording retrieval / display in the LMS (separate future ticket).
- AI Companion summary fetch and display (separate future ticket).
- Bulk-edit UI for setting many coaches at once.
- Per-component-type settings (e.g., "different settings for B2E vs ECSELent"). Defer to v2.
- Migrating away from the Zoom API toward static URLs.
- Custom `authentication_option` profiles for `meeting_authentication`.
- Chip / token UX for alternative_hosts entry (textarea is fine for v1).

## Settings Field Catalog

The 6 fields exposed in the LMS:

| Field (UI label) | Zoom API key | Type | Admin default | Coach can override? |
|---|---|---|---|---|
| Waiting room | `settings.waiting_room` | bool | `true` | yes |
| Mute upon entry | `settings.mute_upon_entry` | bool | `false` | yes |
| Join before host | `settings.join_before_host` | bool | `false` | yes (auto-disabled when waiting room is on) |
| Alternative hosts | `settings.alternative_hosts` | string (CSV, ≤ 1024 chars) | `clove@housmanlearning.com` (admin sets) | yes (with explicit "no alternative hosts" override option) |
| Require passcode | `password` (top-level) | bool | `false` | **no — admin only** |
| Require Zoom sign-in | `settings.meeting_authentication` | bool | `false` | **no — admin only ("Advanced" section)** |

**Removed from payload entirely** (so Zoom account defaults inherit untouched):
- `auto_recording` — Zoom account.
- `auto_start_meeting_summary` — AI Companion managed in Zoom account settings.

**Why passcode + auth are admin-only:** both interact with Zoom account-level policies that coaches cannot diagnose or fix. Hiding them from coaches eliminates a class of "I turned it off but it's still on" tickets. Chris (admin) sets them once and lives with the consequences of his Zoom account settings.

**Terminology:** UI uses Zoom's words — "passcode" (not "passkey"), "Zoom sign-in" (not "meeting authentication") — so coaches can google their meaning.

## Architecture: Default + Override

Two storage layers:

1. **Admin defaults** — single record stored under WP option `hl_zoom_coaching_defaults`. Edited via `Settings → Scheduling` admin page by users with `manage_hl_core` capability.
2. **Per-coach overrides** — sparse record per coach in a new `hl_coach_zoom_settings` table. Each field is **nullable** — `NULL` means "inherit admin default". A coach can also explicitly override `alternative_hosts` to empty (zero alternative hosts) — stored as empty string, distinct from NULL.

### Resolution at booking time, in priority order:
- Coach override value if **non-NULL** → use it (including empty string for alternative_hosts).
- Else admin default value → use it.
- Else hard-coded code default (matches the "Admin default" column above) → use it (defensive fallback only).

### Normalization (single canonical place: `validate()`)
- All bools coerced to `0|1` on save.
- `waiting_room=1` AND `join_before_host=1` is normalized server-side at validation time to `join_before_host=0`. The payload builder trusts the resolved array — no second enforcement.
- Alternative hosts: split CSV, trim, lowercase, `sanitize_email()` then `is_email()` per address. Reject if any address equals coach's resolved Zoom email (per `HL_Zoom_Integration::get_coach_email()`, which checks `hl_zoom_email` user-meta first, then WP `user_email`). Reject if more than 10 addresses (sanity bound).

## Database Schema

**New table** in `class-hl-installer.php`:

```sql
CREATE TABLE wp_hl_coach_zoom_settings (
    coach_user_id bigint(20) unsigned NOT NULL,
    waiting_room tinyint(1) NULL,
    mute_upon_entry tinyint(1) NULL,
    join_before_host tinyint(1) NULL,
    alternative_hosts varchar(1024) NULL,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id bigint(20) unsigned NULL,
    PRIMARY KEY  (coach_user_id),
    KEY updated_at (updated_at)
) $charset_collate;
```

Note: only the 4 coach-overridable fields live in this table. `password_required` and `meeting_authentication` are admin-global only (live in the WP option). `alternative_hosts` is `VARCHAR(1024)` (not TEXT) — fits in-row, indexable. `KEY updated_at` supports the admin overview table sort.

### Schema migration — single canonical pattern
Use **`dbDelta` from `get_schema()` only**. Do NOT add a duplicate guarded `IF NOT EXISTS` block in `maybe_upgrade()` — `create_tables()` (which calls `dbDelta` on the schema string) runs whenever `$stored < $current_revision`. A second pattern is a footgun for future ALTERs.

- Bump `$current_revision` from `44` → `45` at `class-hl-installer.php:156`.
- Add the table to `get_schema()` so `dbDelta` creates it.
- **Defensive read** in `HL_Coach_Zoom_Settings_Service::get_coach_overrides()`: wrap the SELECT in a `SHOW TABLES LIKE` guard. On error, log and return empty array → `resolve_for_coach()` falls back to admin defaults. Booking flow MUST NOT die because the table is missing or corrupted.

**WP option** (no migration; created on first save):
```php
get_option( 'hl_zoom_coaching_defaults', array() );
// Always merge against constants in the getter, never via 2nd arg of get_option():
return wp_parse_args( $stored, self::DEFAULTS );
```

`autoload` left at default `yes` — option is small and read on every booking.

## Backend Service Design

**New service:** `includes/services/class-hl-coach-zoom-settings-service.php`

```php
class HL_Coach_Zoom_Settings_Service {
    const OPTION_KEY = 'hl_zoom_coaching_defaults';
    const TABLE_SLUG = 'hl_coach_zoom_settings';

    const DEFAULTS = array(
        'waiting_room'           => 1,
        'mute_upon_entry'        => 0,
        'join_before_host'       => 0,
        'alternative_hosts'      => '',
        'password_required'      => 0,
        'meeting_authentication' => 0,
    );

    /** @return array */ public static function get_admin_defaults();
    /** @return true|WP_Error */ public static function save_admin_defaults( array $values, $actor_user_id );
    /** @return array sparse (NULL fields omitted) */ public static function get_coach_overrides( $coach_user_id );
    /** @return true|WP_Error */ public static function save_coach_overrides( $coach_user_id, array $overrides, $actor_user_id, array $reset_fields = array() );
    /** @return array resolved */ public static function resolve_for_coach( $coach_user_id );
    /** @return array sanitized | WP_Error */ public static function validate( array $values, $coach_user_id_for_alt_hosts_check );
    /** @return true|WP_Error best-effort cleanup; HARD-REJECT on Zoom failure */ public static function preflight_alternative_hosts( $coach_user_id, $alternative_hosts_csv );
}
```

**PHP 7.4 note:** return types use docblock `@return true|WP_Error`, NOT typed unions in signatures (PHP 7.4 doesn't support union return types).

### Service contracts (explicit per all-wave reviews)

- `validate()` does ALL normalization (incl. waiting_room/join_before_host conflict resolution).
- `validate()` returns `WP_Error` with structured `error_data`:
  ```php
  new WP_Error( 'invalid_alternative_hosts',
      __( 'One or more alternative-host emails are invalid.', 'hl-core' ),
      array( 'field' => 'alternative_hosts', 'invalid_emails' => array( 'lauren@gnail.com' ) )
  );
  ```
  The frontend reads `data.data.error_data.field` to highlight the right input. Banner errors carry `field => ''`.
- `save_coach_overrides()` MUST set `updated_by_user_id = $actor_user_id` on both INSERT and UPDATE.
- **Audit-diff race fix:** `save_coach_overrides()` wraps SELECT-old-row + INSERT/UPDATE in `START TRANSACTION` ... `COMMIT`. Audit diff is computed against the row read inside the same transaction. ROLLBACK on any DB error.
- `save_coach_overrides()` accepts a `$reset_fields` array (4th arg). Inside the transaction, before INSERT/UPDATE, raw-NULL the named columns. Diff calculation compares BEFORE → final state (after both reset and overrides). Audit log fires once for the merged result.
- Audit diff format **excludes `updated_at` and `updated_by_user_id`**.
- **Audit attribution:** `HL_Audit_Service::log()` hardcodes `actor = get_current_user_id()`. Do NOT pass `actor_user_id` in the data array — it would be silently dropped. The `$actor_user_id` parameter on save methods is preserved for the `updated_by_user_id` table column (where it lands correctly).
- `preflight_alternative_hosts()` is **mandatory** when alt_hosts non-empty. Calls `HL_Zoom_Integration::create_meeting()` with a placeholder payload + the proposed alt_hosts, then immediately deletes the test meeting. **A preflight failure HARD-REJECTS the save** — `save_coach_overrides()` and `save_admin_defaults()` return the WP_Error and do NOT persist the change. The cleanup-delete is wrapped in `try`/`catch`; if it fails, the failure is audit-logged but does not affect the save path. The rare Zoom-API-down case will block saves; this is the intended trade-off (one bad config blocks one save, vs. one typo silently breaking every coaching booking until someone notices).
- **Preflight debounce:** transient `hl_zoom_alt_preflight_{coach_user_id}_{md5(alt_hosts_csv)}`, 60s TTL. Same coach + value → skip.
- **Preflight rate-limit lock:** **transient** (NOT `wp_cache_*`) `hl_zoom_inflight_{coach_user_id}`, **TTL 60 seconds** (must exceed Zoom client timeout 15s × retry = 30s with margin). Held during the create+delete; second concurrent save returns *"Verifying with Zoom — try again in a moment."* **Why transient, not `wp_cache_add`:** WP installs without a persistent object cache (Redis/Memcached) treat `wp_cache_*` as request-scoped — useless for cross-process locking. Transients persist via the options table when no object cache is configured.

### `HL_Zoom_Integration::build_meeting_payload()` boot-safe fallback

```php
public function build_meeting_payload( $session_data, array $resolved_settings = array() ) {
    if ( empty( $resolved_settings ) ) {
        // Boot-safe: a caller during early load (or a test/extension that doesn't
        // pass the second arg) must NOT fatal if HL_Coach_Zoom_Settings_Service
        // hasn't loaded yet. Hard-coded literal is the last-resort fallback.
        if ( class_exists( 'HL_Coach_Zoom_Settings_Service' ) ) {
            $resolved_settings = HL_Coach_Zoom_Settings_Service::get_admin_defaults();
        } else {
            $resolved_settings = array(
                'waiting_room' => 1, 'mute_upon_entry' => 0, 'join_before_host' => 0,
                'alternative_hosts' => '', 'password_required' => 0, 'meeting_authentication' => 0,
            );
        }
    }

    $payload = array(
        'topic'      => sprintf( 'Coaching Session - %s/%s', $session_data['mentor_name'], $session_data['coach_name'] ),
        'type'       => 2,
        'start_time' => $session_data['start_datetime'],
        'timezone'   => $session_data['timezone'],
        'duration'   => isset( $session_data['duration'] ) ? (int) $session_data['duration'] : 30,
        'settings'   => array(
            'waiting_room'           => (bool) $resolved_settings['waiting_room'],
            'join_before_host'       => (bool) $resolved_settings['join_before_host'],
            'mute_upon_entry'        => (bool) $resolved_settings['mute_upon_entry'],
            'meeting_authentication' => (bool) $resolved_settings['meeting_authentication'],
        ),
    );

    if ( isset( $resolved_settings['alternative_hosts'] ) && $resolved_settings['alternative_hosts'] !== '' ) {
        $payload['settings']['alternative_hosts'] = $resolved_settings['alternative_hosts'];
    }

    // password key is OMITTED in both cases:
    //   - password_required=1 → omit so Zoom auto-generates one (default behavior).
    //   - password_required=0 → omit; account-level passcode policy may still apply.
    // Sending '' previously caused "passcode is required" rejections on accounts
    // that enforce passcodes globally.

    return $payload;
}
```

### Modified scheduling service

Both `book_session()` (~line 386) AND `reschedule_session_with_integrations()` (~line 593) get the same patch:

```php
// Resolution happens once. Resolve OUTSIDE the is_configured() guard
// (cheap, harmless, prevents future "fix" that nulls out resolution).
$resolved = HL_Coach_Zoom_Settings_Service::resolve_for_coach( $coach_user_id );

if ( $zoom->is_configured() ) {
    $zoom_email   = $zoom->get_coach_email( $coach_user_id );
    $zoom_payload = $zoom->build_meeting_payload( $api_data, $resolved );
    // ... rest unchanged
}
```

### Concurrency note (documented trade-off)
If a coach changes their override in Tab A while Tab B is mid-booking, the booking uses the pre-change settings. Acceptable: the next reschedule picks up the change. We do NOT add row locks — contention not worth it.

## Booking-Time Failure UX

### Mentor email
In `HL_Scheduling_Email_Service::build_branded_body()` (the shared helper at `class-hl-scheduling-email-service.php:412-417`): when `$meeting_url` is empty, replace the link block with *"Your Zoom meeting link will be sent shortly. We'll be in touch."* This benefits `send_session_booked()`, `send_session_rescheduled()`, and any other branded email automatically. Copy is generic ("we'll be in touch") because the reschedule path does NOT call `send_zoom_fallback()` today.

### Admin "Retry Zoom creation" path

**Endpoint:** `wp_ajax_hl_retry_zoom_meeting`. Capability: `manage_hl_core` only. Coaches do NOT see this button.

**Idempotency contract:**
1. `check_admin_referer()` + cap check.
2. Load session BEFORE acquiring the lock (so not-found / already-has-meeting early returns don't leak a transient).
3. Acquire per-session lock via **transient** (NOT `wp_cache_add`): `set_transient( 'hl_zoom_retry_lock_'.$session_id, 1, 60 )` after a `get_transient()` check. If held, return "Retry already in progress." Lock released in `try`/`finally`.
4. Re-resolve coach settings.
5. Call `HL_Zoom_Integration::create_meeting()`.
6. **Atomic write:** `UPDATE wp_hl_coaching_session SET zoom_meeting_id=%d, meeting_url=%s WHERE session_id=%d AND zoom_meeting_id IS NULL`. Capture affected rows.
7. **If `$rows === false`** (SQL error): delete orphan, audit `db_error`, return WP_Error.
8. **If `(int) $rows === 0`** (race won by parallel request): immediately call `delete_meeting()` on the just-created meeting (orphan cleanup) and return success. If delete fails, audit-log `race_lost_orphan_DELETE_FAILED` with the orphan meeting_id.
9. **If `outlook_event_id IS NOT NULL`** on the session: call `HL_Microsoft_Graph::update_calendar_event()` with the new `meeting_url` so the Outlook invite gets the link.
10. Audit-log `zoom_meeting_retried` with `after_data` including Zoom error code + message on failure, success+meeting_id on success.
11. On success: send `send_zoom_link_ready()` follow-up email to the mentor — distinct subject from the original booking confirmation so they don't think it's a duplicate.

## Admin UI

### "Coaching Session Defaults" card on Settings → Scheduling
**File:** `includes/admin/class-hl-admin-scheduling-settings.php`

- Posts to the **same form/nonce** as `handle_save()` — extend `handle_save()` to also process the 6 new fields. Existing capability (`manage_hl_core`) and nonce action stay; the existing `isset()`/`wp_unslash()` gap on the nonce read is a pre-existing bug, **out of scope for this ticket**.
- Use the EXISTING `'hl_scheduling'` `settings_errors` bucket (the only one `render_page_content()` displays at line 257).
- Field controls:
  - 4 coach-overridable: labeled checkbox + `<p class="description">` help text
  - "Require passcode" + "Require Zoom sign-in": same controls but inside an `<details>` "Advanced" disclosure
  - Alternative hosts: textarea, `maxlength="1024"`, helper text
- Above the card: callout *"Recording and AI Companion are configured in your Zoom account settings, not here."* + display the Zoom Account ID (no email field exists in `hl_zoom_settings`).
- `<details>` Advanced state persisted via `localStorage` key `hlczs_admin_advanced_open`. Auto-opens on initial load if any field inside differs from the hard-coded defaults.

### Coach Overrides Overview table
Same admin page, below the defaults card. Read-only table:

```
Coach              Waiting room    Mute on entry   Join before host   Alt hosts          Last edited
Lauren Orf         On (default)    Off (default)   Off (default)      clove@... (default)  —
Shannon Hernandez  Off (override)  Off (default)   Off (default)      [none] (override)    Chris on 2026-04-21
Carly Kuntz        On (default)    On (override)   Off (default)      clove@... (default)  Chris on 2026-04-22
```

Each cell shows resolved value + `(default)` or `(override)` flag. "Last edited by X on Y" comes from the **denormalized** `updated_by_user_id` + `updated_at` columns (NOT a per-row audit query — avoids N+1).

- Pagination: 50 per page using `paginate_links()`.
- Sticky header (CSS `position: sticky`).
- "Override only" filter checkbox (URL param `?overrides_only=1`).
- Use `cache_users()` once for both coach IDs and editor IDs to warm the user cache before the loop.
- Coach role slug is **`'coach'`** (confirmed at `class-hl-installer.php:2337`), NOT `'hl_coach'`.

### Per-coach edit link
**File:** `includes/admin/class-hl-admin-coaching.php`. Two locations:
- **Coaches list** at `class-hl-admin-coaching.php:1115` (`render_coaches_content()`), per-coach row at line 1142 (actions cell, alongside "Remove Coach"). Tab: `?tab=coaches`. Add a "Zoom Settings" link.
- **Sessions list** at `class-hl-admin-coaching.php:110` (`render_sessions_content()`), per-session row at line 484 (actions `<td>`). Default tab. Add the "Retry Zoom creation" button on sessions where `meeting_url IS NULL`.

## Coach UI

**File:** `includes/frontend/class-hl-frontend-coach-dashboard.php` (confirmed). The class has **NO `__construct()`** and is instantiated only when the shortcode renders — AJAX handlers MUST be registered from `HL_Core::register_hooks()` in `hl-core.php` (matches §C `delete_user` hook pattern). Tile: **"My Meeting Settings"** placed in the dashboard render output (inside the `ob_start()` buffer).

### Edit modal — single-toggle UX

For each of the 4 coach-overridable rows:

```
[Toggle: Waiting room]  ON
                        Using your override.  [Reset to default]
```

- Toggle = `aria-pressed` on a `<button role="switch">` (reuse `class-hl-frontend-coach-availability.php:166` pattern).
- Caption span has `aria-live="polite"` so screen readers announce changes.
- "Reset to default" link visible only when overridden. On click: NULL the field → caption flips to "Using the default" → focus moves back to the toggle.

### Alternative hosts row — same row chrome (per FE reviewer, option a)
The 3-radio block sits inside the same row card as the other 4 rows, with caption + "Reset to default" link still rendered above the radios. Radios:

```
( ) Use the default ([resolved-default-display])
( ) Override: no alternative hosts
(•) Override with these emails:  [textarea]
```

`[resolved-default-display]` rendering rules:
- If admin default `alternative_hosts` is non-empty: show the email list in brackets (e.g. `clove@housmanlearning.com`).
- If admin default `alternative_hosts` is empty: show the literal string `(no alternative hosts)` — never an empty `[]` (which looks like a render bug).

Textarea has `maxlength="1024"` matching the column constraint, plus client-side regex validation on `blur` — bad lines get a red underline. Server validation is authoritative; client check is just early feedback.

### Reset behaviors
- Per-row "Reset to default" — instant, no confirm (single field).
- "Reset all to defaults" button — **styled modal-in-modal** (NOT `window.confirm()` — broken on iOS Safari). One-click intent: confirming applies all resets AND submits immediately (no second Save click required).
- Reset semantics: NULL all override fields. Future admin-default changes flow through automatically.

### Discoverability
- First-visit callout on Coach Dashboard: *"Tip: customize your Zoom meeting settings →"* with a dismiss `[x]`. Dismiss-state stored in user-meta `hl_dismissed_coach_zoom_callout`. The dashboard already loads user meta — the read piggybacks on the existing call (no extra DB query).
- Dismiss POST endpoint: `wp_ajax_hl_dismiss_coach_zoom_callout`, own nonce `hl_dismiss_coach_zoom_callout`.

### AJAX endpoint
- `wp_ajax_hl_save_coach_zoom_settings` — registered with `wp_ajax_*` only (NOT `wp_ajax_nopriv_*`).
- `check_ajax_referer( 'hl_save_coach_zoom_settings', '_nonce' )`.
- Capability check uses `get_current_user_id()` (NOT `current_user_id()` which is not a function).
- Authorization: `get_current_user_id() === $coach_user_id` OR `current_user_can('manage_hl_core')`.
- On `WP_Error`: `wp_send_json_error( array( 'message' => $err->get_error_message(), 'error_data' => $err->get_error_data() ) )`. JS reads `data.data.message` and `data.data.error_data`.
- JS detects WP nonce expiry (`json === 0 || json === -1`) and surfaces *"Your session expired — please reload and try again."*

### AJAX failure UX
- Save button: disabled + spinner during in-flight request.
- Conditional save copy: *"Verifying with Zoom…"* only when alt_hosts will preflight; *"Saving…"* otherwise.
- On `WP_Error` with `error_data.field`: highlight that field's input red + render the message inline below it.
- On other failure: non-dismissible inline banner at modal top with the message.
- Pattern reference: `assets/js/survey-modal.js`. Modal shares its focus-trap (Tab loop + `inert` background).

### No live sync
The tile renders resolved values server-side at page load. If admin changes defaults while a coach has the dashboard open in another tab, the tile shows stale values until the coach reloads. Documented; not a bug.

### Admin-only fields visible (read-only) on coach tile
The 4 coach-overridable fields are editable in the modal. The 2 admin-only fields (`Require passcode`, `Require Zoom sign-in`) appear at the bottom of the modal in a separate "Set by your administrator" section, **read-only**, with a small note: *"These settings apply to all coaching sessions. Contact your administrator to change."* Without this, a coach who hits a passcode prompt or sign-in wall on their meeting has no idea why — turning a non-issue into a support ticket.

### Admin-context dismiss state
When an admin opens a coach's modal via the Coaching Hub link, the first-visit callout dismiss state is read from the **coach's** user-meta (the coach is the modal's subject). Admin actions never write to the coach's `hl_dismissed_coach_zoom_callout` meta.

### Auto-open-modal in admin context
PHP emits `data-auto-open="1"` on the modal only when `current_user_can('manage_hl_core')` AND editing a different user. JS reads that data attribute (NOT the querystring) to avoid spurious opens on a coach's own dashboard if `?coach_user_id=N` happens to be on the URL for any reason.

### Mobile
- `<480px`: rows stack vertically (caption below toggle below reset link).
- `<600px`: modal becomes full-screen overlay (matches survey-modal pattern).

### Class prefix `hlczs-` (HL Coach Zoom Settings).

### Z-index
Modal backdrop/modal at `100010`/`100011`; reset-confirm modal-in-modal at `100020`/`100021` — chosen to clear existing frontend.css overlays (feature-tracker etc. use 9998-10000).

## Notification on Alternative-Hosts Change

When a coach saves a change to `alternative_hosts`, dispatch via WP-Cron:

```php
wp_schedule_single_event(
    time(),
    'hl_notify_alt_hosts_change',
    array( $coach_user_id, $actor_user_id, $old, $new )
);
```

Handler queries `get_users(array('capability__in' => array('manage_hl_core'), 'fields' => array('user_email'), 'number' => 50))`, sends one-line email. Why WP-Cron: keeps the AJAX response under 100ms even when SMTP relay is slow.

## Audit Log Events
- `coach_zoom_defaults_updated` — admin defaults changed. Diff excludes `updated_at`/`updated_by_user_id`.
- `coach_zoom_settings_updated` — per-coach override changed. Same diff exclusions. Entity: `coach_zoom_settings` / coach_user_id.
- `coach_zoom_preflight_failed` — preflight Zoom call rejected. Includes alt_hosts CSV + Zoom error code/message.
- `zoom_meeting_retried` — admin retry. `after_data` includes Zoom error code/message on failure, meeting_id on success.

## User-Delete Cleanup

Hook `delete_user` from inside `HL_Core::register_hooks()` (cluster with the existing user-lifecycle hooks at `hl-core.php:296-298 / 353-355`):

```php
add_action( 'delete_user', function( $user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_coach_zoom_settings';

    // Drop any settings row keyed on the deleted user.
    $wpdb->delete( $table, array( 'coach_user_id' => (int) $user_id ), array( '%d' ) );

    // NULL the actor reference where the deleted user was the editor.
    // Use raw query for unambiguous NULL binding.
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET updated_by_user_id = NULL WHERE updated_by_user_id = %d",
        (int) $user_id
    ) );
}, 10, 1 );
```

## Migration & Rollout

1. Bump schema rev 44 → 45 in `class-hl-installer.php`. Add the table to `get_schema()`. **Do NOT add a duplicate guarded `IF NOT EXISTS` block.** `dbDelta` via `create_tables()` is the single canonical pattern.
2. Empty table on deploy — every coach inherits admin defaults.
3. Chris (action item, separate from this ticket): set Zoom account-level cloud recording + AI Companion auto-summary defaults. Document in admin UI help text.
4. Deploy to test → verify a real LMS booking creates a Zoom meeting whose settings match the configured defaults → verify recording + AI Companion show "On" in Zoom UI → verify `meeting_authentication=true` succeeds against Chris's actual Zoom account → deploy to prod.
5. Bump `HL_CORE_VERSION` for cache-bust **at the START of the work** (per `reference_playwright_verify_workflow.md`) so all intermediate test deploys serve fresh JS/CSS.
6. **Backup/restore caveat:** restoring an old DB snapshot reverts coach overrides silently. Document.

## Testing Plan

**PHP-level (WP-CLI eval, snippets under `bin/test-snippets/`):**
- `resolve_for_coach()` returns admin defaults when no override row exists.
- Sparse override merges correctly.
- `validate()` rejects invalid emails, > 10 alt hosts, the coach's own Zoom email; structured `error_data` shape.
- `validate()` normalizes `waiting_room=1, join_before_host=1` → `join_before_host=0`.
- `validate()` distinguishes NULL vs empty string for `alternative_hosts`.
- `save_coach_overrides()` writes `updated_by_user_id` on both INSERT and UPDATE.
- `save_coach_overrides()` audit diff is correct under simulated rapid double-save (transaction holds).
- `save_coach_overrides()` `$reset_fields` parameter NULLs columns within the same transaction.
- `build_meeting_payload()` with no second arg falls back to literal constants if service class missing (boot safety).
- `preflight_alternative_hosts()` debounce hits transient on second identical save within 60s.
- `delete_user` action: row for that user_id removed; rows where they were actor get actor NULL'd.
- Defensive: `get_coach_overrides()` returns empty array if table is missing.

**Integration (manual on test server):**
- Book session → confirm Zoom meeting created with resolved settings.
- Reschedule → confirm new meeting reflects current resolved settings.
- Cancel → confirm Zoom meeting deletion still works.
- Save bad alt_hosts → preflight HARD-REJECTS (no DB write, coach sees field-level error).
- Save valid alt_hosts → preflight succeeds, save persists.
- Mentor email shows "Zoom link will be sent shortly" when meeting_url empty.
- Retry button: success path writes meeting_id, updates Outlook event, sends `send_zoom_link_ready` email.
- Retry button: simulated parallel double-click → exactly one meeting persisted, orphan deleted.
- `meeting_authentication=true` test against Chris's real Zoom account.

**UI smoke (Playwright per `reference_playwright_verify_workflow.md`):**
- Admin defaults page: load → toggle → save → reload → value persists.
- Admin overview table: 50+ coaches paginate; sticky header; "Override only" filter narrows view.
- Coach dashboard tile: load as coach → toggle → save → reload → caption + override flag correct.
- Per-row Reset link → toggle reverts; focus moves back to toggle.
- Reset-all → styled modal-in-modal confirm; confirming NULLs all overrides AND submits.
- AJAX failure: server returns `WP_Error` with `field=alternative_hosts` → red underline on textarea + inline message.
- Mobile <480px: rows stack vertically. <600px: modal full-screen.
- Toggle a11y: `aria-pressed` reflects state; `aria-live="polite"` caption announces changes.

## Edge Cases & Gotchas

1. **Account-level passcode policy** can override our `password` omission. Passcode toggle is admin-only; help text states *"If your Zoom account requires passcodes on all meetings, this setting has no effect — disable that policy in Zoom admin first."*
2. **Alternative hosts** must be Licensed users on the same account. Mandatory preflight HARD-REJECTS bad config at save time (no broken bookings). Edge case: a host that becomes unlicensed AFTER preflight succeeded → booking-time failure triggers `send_zoom_fallback()` + the new "link coming shortly" mentor email.
3. **`meeting_authentication=true`** sends Zoom's default `signIn_*` literal. Custom auth profiles unsupported in v1. Toggle is admin-only inside `<details>` "Advanced".
4. **Existing in-flight sessions** are unaffected. New settings only apply to bookings created after deploy.
5. **No Zoom integration configured.** `is_configured()` check stays. Resolve call intentionally outside the guard.
6. **Service load order.** `HL_Coach_Zoom_Settings_Service` registered in `hl-core.php` BEFORE `HL_Scheduling_Service` (require_once order is explicit, not autoloaded). Boot-safe fallback in `build_meeting_payload()` is belt-and-suspenders.
7. **Reschedule picks up new settings.** Intentional. Document in admin help text.
8. **Concurrent settings change during booking.** Race window of a few hundred ms. One stale session is acceptable; reschedule fixes it. No row lock.
9. **Backup/restore destructive for this table.** Document; recommend exporting before restore.
10. **`bin/seed-*` scripts: no seed required for v1.** Empty table is the correct initial state.

## Files to Create

- `includes/services/class-hl-coach-zoom-settings-service.php`
- `includes/frontend/views/coach-zoom-settings-modal.php`
- `assets/js/coach-zoom-settings.js`
- `assets/js/admin-coach-zoom-retry.js`
- `assets/css/coach-zoom-settings.css`

## Files to Modify

- `hl-core.php` — register `HL_Coach_Zoom_Settings_Service` BEFORE `HL_Scheduling_Service` (explicit require_once order); bump `HL_CORE_VERSION` (do this first so test-deploy cache-bust works). Inside `HL_Core::register_hooks()`: hook `delete_user`, `wp_ajax_hl_dismiss_coach_zoom_callout`, `wp_ajax_hl_save_coach_zoom_settings`, `wp_ajax_hl_retry_zoom_meeting`, `hl_notify_alt_hosts_change`.
- `includes/class-hl-installer.php` — bump rev 44 → 45 at line 156; add new table to `get_schema()` (use `$tables[]` array with lowercase types and bare `$charset_collate`).
- `includes/integrations/class-hl-zoom-integration.php:178-191` — `build_meeting_payload()` 2-arg signature with class_exists-guarded fallback to literal constants.
- `includes/services/class-hl-scheduling-service.php:386-404` — `book_session()` resolve + pass.
- `includes/services/class-hl-scheduling-service.php:591-606` — `reschedule_session_with_integrations()` resolve + pass. **Both call sites updated together.** Plus add `retry_zoom_meeting()` and `ajax_retry_zoom_meeting()` methods.
- `includes/services/class-hl-scheduling-email-service.php:412-417` — `build_branded_body()` shows "link coming shortly" fallback when meeting_url empty. Add `send_zoom_link_ready()` method for retry success.
- `includes/admin/class-hl-admin-scheduling-settings.php` — extend `handle_save()` (existing `manage_hl_core` cap + `hl_scheduling_settings` nonce stay); render "Coaching Session Defaults" card + Coach Overrides Overview table.
- `includes/admin/class-hl-admin-coaching.php` — add "Zoom Settings" link at line 1142 (coaches tab) + "Retry Zoom creation" button at line 484 (sessions tab). Enqueue `admin-coach-zoom-retry.js`.
- `includes/frontend/class-hl-frontend-coach-dashboard.php` — render "My Meeting Settings" tile + first-visit callout inside `render()`'s output buffer. Add static AJAX handlers (`ajax_dismiss_coach_zoom_callout`, `ajax_save_coach_zoom_settings`).
- `includes/frontend/class-hl-shortcodes.php` — enqueue `coach-zoom-settings.css` + `coach-zoom-settings.js` when `[hl_coach_dashboard]` is on the page.
- `STATUS.md`, `README.md` — per CLAUDE.md rule #3.

## Resolved Open Questions

1. **`authentication_option` configurable?** No — hard-code Zoom default for v1. Toggle admin-only inside Advanced.
2. **"Reset to defaults" semantics?** NULL all override fields.
3. **Notify on admin defaults change?** No (noisy). Audit log only.
4. **Synthetic Zoom test on save?** Yes for non-empty alt_hosts — mandatory, with debounce + inflight transient lock. **Hard-reject on failure.**
5. **Per-component-type settings?** No for v1.
6. **Notify coach if admin overwrites their override?** No.
7. **alt_hosts validation strictness?** Mandatory preflight chosen over domain-allowlist.
8. **Migration pattern?** `dbDelta` via `get_schema()` only. No duplicate guarded block.
9. **WP option fallback merge?** `wp_parse_args($stored, self::DEFAULTS)` in the getter.
10. **Coach FK + delete cascade?** No FK (existing convention). `delete_user` hook handles cleanup.
11. **`hl_zoom_coaching_defaults` autoload?** Default `yes`. Read-on-every-booking.
12. **Resolve batching for admin overview?** Not needed — overview uses denormalized columns directly, avoiding N+1.

## Wave 1 + Wave 2 Review Summary

Five independent reviewers (architect, product/UX, frontend senior, PHP senior, backend senior) raised 14 critical and 17 should-fix issues across two rounds. All 31 are incorporated. Key shifts from v0.1 → v0.4:

- **Migration:** dropped duplicate `IF NOT EXISTS` block. Single `dbDelta` pattern.
- **Boot safety:** `build_meeting_payload()` falls back to literal constants if service class missing.
- **Retry idempotency:** atomic `WHERE zoom_meeting_id IS NULL` UPDATE + per-session transient lock + orphan cleanup + Outlook update.
- **alt_hosts validation:** mandatory preflight (with debounce + inflight transient lock) — HARD-REJECTS on failure to catch typoed emails before broken bookings.
- **Audit-diff race:** wrapped in `START TRANSACTION` with ROLLBACK on error.
- **Audit attribution:** `HL_Audit_Service::log()` hardcodes actor — don't pass `actor_user_id` in data array.
- **AJAX failure UX:** structured `error_data` contract for field-level errors; nonce-expiry detection.
- **First-visit callout:** dedicated nonce + dismiss endpoint; piggyback existing user-meta read.
- **Admin overview:** denormalized columns for "last edited"; pagination + sticky header + override filter; `cache_users()` to avoid N+1.
- **Notification:** WP-Cron dispatch to keep save AJAX fast.
- **User delete cleanup:** `delete_user` hook drops row + NULLs actor field (raw SQL for unambiguous NULL binding).
- **Mobile + a11y:** explicit breakpoints + `aria-pressed`/`aria-live` patterns.
- **Schema:** `VARCHAR(1024)` for alt_hosts; `KEY updated_at` for sort.
- **Concurrency:** documented race window between resolve and Zoom create (acceptable).
- **Reset-all:** one-click (confirm modal applies + submits); single canonical save path through `save_coach_overrides()` with `$reset_fields` arg.
- **Auto-open modal:** PHP-emitted `data-auto-open` (admin context only), not querystring.

## v0.3 → v0.4 Self-Critique Gate

Final-gate self-critique surfaced 4 residual issues:

1. **Locks switched to transients** (not `wp_cache_*`) — prod has no persistent object cache; `wp_cache_add` is request-scoped there. TTL bumped 30s → 60s on both retry-session and preflight-inflight locks.
2. **Empty admin-default `alternative_hosts` rendering** — coach radio would have shown `( ) Use the default ([])`. Now renders `(no alternative hosts)`. Textarea also gets `maxlength="1024"` matching the column.
3. **Admin-only fields invisible to coach** — coach hits a passcode/sign-in wall with no explanation. Modal now shows them in a read-only "Set by your administrator" section.
4. **First-visit callout dismiss state ambiguous** in admin-edits-coach context. Now explicitly: coach's user-meta is the source of truth.
