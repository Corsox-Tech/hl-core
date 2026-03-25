# Coaching Session Scheduling with Microsoft 365 & Zoom Integration

**Date:** 2026-03-25
**Status:** Approved (design)
**Author:** Claude Code + Mateo

---

## Overview

Add self-service coaching session scheduling to the HL Core plugin. Mentors schedule 30-minute coaching sessions by picking available time slots from their coach's calendar. The system automatically creates a Zoom meeting and Outlook calendar event, and sends branded email notifications.

### Goals

- Mentors can schedule coaching sessions from within their pathway component pages
- Available slots = coach's weekly availability MINUS real Outlook calendar conflicts MINUS existing HL sessions
- Each booking creates: DB record, Zoom meeting, Outlook calendar event, notification emails
- Coaches and admins can also schedule, reschedule, and cancel on behalf of mentors
- All scheduling rules (lead time, cancellation window, duration) configurable by admins
- Multi-timezone support throughout

### Not In Scope

- Microsoft Teams meetings (Zoom only)
- Per-cycle scheduling rule overrides (global settings only)
- Mentor-initiated cancellation (mentors can reschedule but not cancel)

---

## Architecture

```
+-----------------------------------------------------+
|                   Admin Settings                     |
|  (Azure creds, Zoom creds, scheduling rules)         |
|  Stored encrypted in wp_options                       |
+----------+------------------------+------------------+
           |                        |
  +--------v-----------+   +--------v--------------+
  | HL_Microsoft_Graph  |   | HL_Zoom_Integration   |
  | - Client credentials|   | - S2S OAuth token     |
  | - Calendar read     |   | - Create meeting      |
  | - Calendar create   |   | - Update meeting      |
  | - Calendar update   |   | - Delete meeting      |
  | - Calendar delete   |   |                       |
  +--------+-----------+   +--------+--------------+
           |                        |
  +--------v------------------------v------------------+
  |              HL_Scheduling_Service                  |
  | - get_available_slots(coach, date, timezone)        |
  |   -> availability MINUS Outlook conflicts           |
  | - book_session(coach, mentor, slot)                 |
  |   -> DB record + Zoom + Outlook + emails            |
  | - reschedule_session(session_id, new_slot)          |
  | - cancel_session(session_id)                        |
  +-----------------------+----------------------------+
                          |
            +-------------v-----------------+
            |    HL_Coaching_Service         | (existing)
            |    hl_coaching_session         | (existing table)
            |    hl_coach_availability       | (existing table)
            +-------------------------------+
```

**Key principle:** `HL_Scheduling_Service` orchestrates. It calls Graph, Zoom, and the existing `HL_Coaching_Service` -- it does not duplicate their logic.

---

## Microsoft Graph Integration

### Authentication

- **Client credentials flow** (application-level, admin consent granted once)
- Azure AD app registration with `Calendars.ReadWrite` + `User.Read.All` application permissions
- Token obtained via `POST https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`
- Tokens cached in WordPress transient (~60 min TTL), auto-refreshed on expiry

### Coach Identity Mapping

- System uses the coach's WordPress email as their Microsoft 365 email by default
- Optional `hl_microsoft_email` usermeta field for coaches whose WP email differs from their M365 email

### Calendar Operations

**Read conflicts (calendarView):**
```
GET /users/{coach-m365-email}/calendar/calendarView
  ?startDateTime=2026-03-30T00:00:00Z
  &endDateTime=2026-03-31T01:00:00Z
```
Returns all events in the range. The UTC range is padded (+1h beyond midnight) to capture events that overlap with timezone-shifted availability blocks at day boundaries. Busy times subtracted from coach availability.

**Create event:**
```
POST /users/{coach-m365-email}/calendar/events
{
  subject: "Coaching Session - {Mentor Name}/{Coach Name}",
  start: { dateTime: "2026-03-30T09:00:00", timeZone: "America/New_York" },
  end:   { dateTime: "2026-03-30T09:30:00", timeZone: "America/New_York" },
  location: { displayName: "Zoom Meeting" },
  body: {
    contentType: "HTML",
    content: "<p>Please use the link below to access your coaching session.</p>
              <p>Coach: Lauren Orf<br>Date: March 30, 2026<br>
              Time: 9:00 AM ET<br>Link: https://zoom.us/j/123...</p>"
  },
  attendees: [{ emailAddress: { address: "mentor@email.com" }, type: "required" }],
  isOnlineMeeting: false
}
```
Store returned event `id` in `hl_coaching_session.outlook_event_id`.

**Update event:** On reschedule, `PATCH /users/{coach}/calendar/events/{event_id}` with new times + updated Zoom link.

**Delete event:** On cancellation, `DELETE /users/{coach}/calendar/events/{event_id}` (sends cancellation notices to attendees automatically).

### Error Handling

- **Graph API fails during booking:** DB record + Zoom meeting still created, `outlook_event_id` left NULL. Coach receives fallback email: "{Mentor Name} ({email}) scheduled a coaching session with you at {date/time}, but it couldn't be added to your calendar. Please add it manually. Zoom link: {link}. Admin has been notified." Admin also notified.
- **Graph API unreachable during slot lookup:** Show coach availability without conflict checking, with notice: "Could not verify coach's calendar -- some slots may conflict with existing meetings."
- All API errors logged via `HL_Audit_Service`.

---

## Zoom Integration

### Authentication

- **Server-to-Server OAuth** (account-level, no per-user consent)
- Token obtained via `POST https://zoom.us/oauth/token` with `account_credentials` grant type
- Tokens cached in WordPress transient (~60 min TTL), auto-refreshed
- Required scope: `meeting:write:admin`

### Coach Identity Mapping

- Uses coach's WordPress email as Zoom email by default
- Optional `hl_zoom_email` usermeta field for override

### Meeting Operations

**Create meeting:**
```
POST /users/{coach-zoom-email}/meetings
{
  topic: "Coaching Session - {Mentor Name}/{Coach Name}",
  type: 2,
  start_time: "2026-03-30T09:00:00",
  timezone: "America/New_York",
  duration: 30,
  settings: {
    join_before_host: true,
    waiting_room: false,
    auto_recording: "none"
  }
}
```
Store `id` in `hl_coaching_session.zoom_meeting_id`, `join_url` in `meeting_url`.

**Update meeting:** On reschedule, `PATCH /meetings/{meeting_id}` with new start_time/timezone.

**Delete meeting:** On cancellation, `DELETE /meetings/{meeting_id}`.

### Error Handling

- **Zoom API fails during booking:** DB record created with `meeting_url = NULL` and `zoom_meeting_id = NULL`. Coach receives email: "{Mentor Name} scheduled a coaching session with you at {date/time}, but the Zoom meeting could not be created automatically. Please create one manually and share the link." Admin also notified. Outlook event still created (without Zoom link).
- All API errors logged via `HL_Audit_Service`.

### Booking Orchestration Order

1. **Create `hl_coaching_session` record** (status: `scheduled`, no meeting_url yet)
2. **Create Zoom meeting** -> store `zoom_meeting_id` + `meeting_url` on the record
3. **Create Outlook event** (includes Zoom link in body) -> store `outlook_event_id`
4. **Send WP notification emails** (include Zoom link)

If step 2 fails: skip Zoom link in step 3, still create calendar event, send fallback emails.
If step 3 fails: session + Zoom exist, send fallback email to coach.
If step 4 fails: log error, session still works (calendar invite already sent by Outlook).

---

## Scheduling Service & Slot Calculation

### Available Slot Algorithm

```
1. Input: coach_user_id, date, mentor_timezone
2. Get coach's timezone (from usermeta hl_timezone, default to WP site timezone)
3. Get coach's hl_coach_availability blocks for that day_of_week
4. Slice each availability block into session_duration-minute slots
   e.g., an 08:00-12:00 block with 30-min duration -> [08:00-08:30, 08:30-09:00, ..., 11:30-12:00]
5. Convert sliced slots from coach's local timezone to UTC for comparison
6. Call Graph API: GET calendar/calendarView for that date (UTC range, padded +1h for timezone boundaries)
7. Check short-lived transient cache first; if miss, call Graph API and cache result for 2-5 min
   (per coach per date -- reduces redundant API calls when multiple mentors view same coach)
8. Get existing hl_coaching_session records for that date (status = 'scheduled')
9. Subtract Outlook busy times from availability slots
10. Subtract existing HL sessions from remaining slots
11. Apply booking rules:
    - Remove slots < minimum lead time from now
    - Remove slots > maximum lead time from now
12. Convert remaining slots to mentor's timezone for display
13. Return array of { start_time, end_time, display_label }
```

Step 6 is separate from step 5 because an Outlook event creation may have failed -- a session could exist in `hl_coaching_session` without an Outlook event.

### Timezone Handling

- **Coach timezone:** Stored in `wp_usermeta` as `hl_timezone` (IANA format, e.g., `America/New_York`). Set via Coach Dashboard profile.
- **Mentor timezone:** Detected from browser via JavaScript `Intl.DateTimeFormat().resolvedOptions().timeZone`, passed with booking request. Stored on session record.
- **DB storage:** The existing codebase stores `session_datetime` and other timestamps using `current_time('mysql')` (WordPress site timezone, NOT UTC). New scheduling code MUST follow this same convention for `session_datetime` to remain consistent with existing queries. The `coach_timezone` and `mentor_timezone` columns on each session record enable accurate display conversion. For Graph API and Zoom API calls, convert from WP local time to the coach's IANA timezone.
- **Availability blocks:** Stored in coach's local timezone in `hl_coach_availability` (matches how they set them -- "I'm available Monday 8 AM my time"). For conflict comparison, convert to the same reference frame as Outlook events before subtracting.

### Reschedule Flow

1. Mentor/coach clicks "Reschedule" on existing session
2. Shown the same date -> time slot picker (URL: component page with `?reschedule=SESSION_ID`)
3. On selection:
   - Old session marked `rescheduled` via existing `HL_Coaching_Service::reschedule_session()`
   - New session created (new DB record with new `session_id`)
   - Old Zoom meeting deleted, new Zoom meeting created for new session
   - Old Outlook event deleted, new Outlook event created for new session
   - Reschedule notification emails sent to both parties
4. Preserves audit trail: old session -> rescheduled status, new session -> scheduled status

### Cancellation Flow

1. Coach/admin clicks "Cancel" (mentors cannot cancel)
2. Confirmation modal
3. On confirm:
   - Session marked `cancelled` via existing `HL_Coaching_Service::cancel_session()`
   - Zoom meeting deleted
   - Outlook event deleted (sends cancellation notice to attendees)
   - Cancellation notification emails sent to both parties
4. Respects `min_cancel_notice_hours` setting -- Cancel button hidden if within window

---

## Frontend: Component Page Scheduling UI

### Entry Point

Mentors reach scheduling via: **Pathway -> Component "Coaching Session N" -> Component Page**.

The existing `HL_Frontend_Component_Page` dispatcher routes `coaching_session_attendance` components to the enhanced view.

### Two States

**State A: Not Yet Scheduled**
- Shows: component title, coach info, session number
- Inline scheduling UI: month calendar date picker -> available time slots -> confirm button
- Days with no coach availability greyed out
- Past dates and dates beyond `max_lead_time_days` disabled
- If component has a drip rule with a `release_at_date` in the future: scheduling UI locked with release date notice. Drip rules are in the existing `hl_component_drip_rule` table (`drip_type = 'fixed_date'`, `release_at_date` column). Also supports `after_completion_delay` drip type (delay N days after a base component is completed).

**State B: Scheduled (or Completed)**
- Two tabs: **Session Details** | **Action Plan & Results**
- Session Details tab: status badge, date/time (in mentor's TZ), Zoom join link, reschedule button (mentor/coach/admin), cancel button (coach/admin only)
- Action Plan & Results tab: existing coaching session submission forms (Action Plan + RP Notes) via `HL_Coaching_Service::submit_form()` / `get_submissions()`
- Action Plan tab only visible once session is scheduled

### Coach-Side Scheduling

Coaches can schedule from their **Mentor Detail page** -- "Schedule Next Session" button targets the next unscheduled coaching session component for that mentor.

### AJAX Endpoints

- `wp_ajax_hl_get_available_slots` -- params: `coach_user_id`, `date`, `timezone`. Returns JSON array of slots.
- `wp_ajax_hl_book_session` -- params: `mentor_enrollment_id`, `coach_user_id`, `component_id`, `date`, `start_time`, `timezone`. Returns session_id + zoom link on success.

Additional AJAX endpoints for reschedule and cancel:

- `wp_ajax_hl_reschedule_session` -- params: `session_id`, `date`, `start_time`, `timezone`. Marks old session rescheduled, creates new session + Zoom + Outlook. Returns new session_id + zoom link.
- `wp_ajax_hl_cancel_session` -- params: `session_id`. Cancels session, deletes Zoom meeting + Outlook event. Returns success.

All four endpoints protected by nonce + role verification (mentor books/reschedules own sessions only, coach for assigned mentors, admin for anyone). Cancel endpoint restricted to coach + admin only.

### Permissions

| Action | Mentor | Coach | Admin |
|---|---|---|---|
| Schedule | Own sessions | Assigned mentors | Anyone |
| Reschedule | Own sessions | Assigned mentors | Anyone |
| Cancel | No | Assigned mentors | Anyone |
| View details | Own sessions | Assigned mentors | Anyone |
| Fill Action Plan | Yes (supervisee) | Yes (supervisor) | View only |

---

## Frontend: My Coaching Page Enhancement

The existing `[hl_my_coaching]` shortcode for mentors becomes a coaching sessions hub.

### Layout

Shows coach info at top, then a table of all coaching session components for the mentor's pathway(s):

| Component | Status | Actions |
|---|---|---|
| Coaching Session #1 | Completed 03/16/2026 | [View] |
| Coaching Session #2 | Scheduled 03/28/2026 | [View] |
| Coaching Session #3 | Not Scheduled (Complete by: 04/05/2026) | [View] |
| Coaching Session #4 | Not Scheduled (Release: 05/25/2026) | [View] (locked) |

### Data Source

- Query `hl_component` where `component_type = 'coaching_session_attendance'` for mentor's assigned pathway
- Join `hl_component_state` for completion status
- Join `hl_coaching_session` (via `component_id`) for scheduled/attended status
- Use `hl_component.complete_by` for "complete by" date display
- Use `hl_component_drip_rule` for release date locking (`drip_type = 'fixed_date'` with `release_at_date`, or `after_completion_delay` with `base_component_id` + `delay_days`)

### Multi-Enrollment

If mentor is enrolled in multiple Cycles (same or different Partnerships), group by Cycle with separate coach info for each.

### Locking Behavior

- Component has a drip rule with `release_at_date` in the future (or `after_completion_delay` not yet satisfied): View button disabled/greyed, release date shown
- No drip rule or release date is past: normal access
- `complete_by` shown as a note when session is not yet scheduled

---

## Email Notifications

### Service: `HL_Scheduling_Email_Service`

Branded HTML emails via `wp_mail`.

**Session Booked:**
- To mentor: "Your coaching session has been scheduled with {Coach Name} on {date} at {time} ({mentor TZ}). Zoom link: {link}"
- To coach: "A coaching session has been scheduled with {Mentor Name} on {date} at {time} ({coach TZ}). Zoom link: {link}"

**Session Rescheduled:**
- To both: "Your coaching session has been rescheduled from {old date/time} to {new date/time}. New Zoom link: {link}"

**Session Cancelled:**
- To both: "Your coaching session on {date} at {time} has been cancelled by {cancelled_by name}."

**Fallback -- Outlook event failed:**
- To coach: "{Mentor Name} ({email}) scheduled a coaching session with you at {date/time}, but it couldn't be added to your calendar. Please add it manually. Zoom link: {link}. Admin has been notified."
- To admin: "Outlook calendar event creation failed for session #{id}. Coach: {name}. Error: {message}."

**Fallback -- Zoom meeting failed:**
- To coach: "{Mentor Name} scheduled a coaching session with you at {date/time}, but the Zoom meeting could not be created automatically. Please create one manually and share the link."
- To admin: "Zoom meeting creation failed for session #{id}. Error: {message}."

### Styling

Simple branded HTML -- HLA header, clean body, prominent Zoom link button. Standard WordPress transactional email pattern.

---

## Admin Settings

### Location

New subtab under HL Core > Settings: **"Scheduling & Integrations"**

### Settings (stored in `wp_options` as `hl_scheduling_settings` JSON)

**Scheduling Rules:**

| Setting | Key | Default |
|---|---|---|
| Session duration (minutes) | `session_duration` | 30 |
| Min booking lead time (hours) | `min_lead_time_hours` | 24 |
| Max booking lead time (days) | `max_lead_time_days` | 30 |
| Min cancellation/reschedule notice (hours) | `min_cancel_notice_hours` | 24 |

**Microsoft 365 Integration:**

| Field | Type | Notes |
|---|---|---|
| Tenant ID | Text | Azure AD app registration |
| Client ID | Text | Azure AD app registration |
| Client Secret | Password | Stored encrypted |
| Connection status | Read-only badge | Verified by test API call |
| [Test Connection] button | | |

**Zoom Integration:**

| Field | Type | Notes |
|---|---|---|
| Account ID | Text | Zoom S2S OAuth app |
| Client ID | Text | Zoom S2S OAuth app |
| Client Secret | Password | Stored encrypted |
| Connection status | Read-only badge | Verified by test API call |
| [Test Connection] button | | |

### Encryption

API secrets encrypted using WordPress `AUTH_KEY` salt + `openssl_encrypt` (AES-256-CBC) before storage. A random 16-byte IV is generated per encryption, prepended to the ciphertext, and stripped on decryption. Decrypted at runtime for API calls only. Never displayed in forms -- shown as masked dots with a "Change" button.

### Setup Guides

Collapsible instructions below each integration section with step-by-step Azure AD and Zoom Marketplace setup guidance.

---

## Database Changes

### New Columns on `hl_coaching_session`

| Column | Type | Purpose |
|---|---|---|
| `component_id` | `BIGINT UNSIGNED NULL` | Links session to specific coaching component |
| `zoom_meeting_id` | `BIGINT UNSIGNED NULL` | Zoom meeting ID for update/delete. Store raw numeric ID (Zoom API may return as string -- cast to int). |
| `outlook_event_id` | `VARCHAR(255) NULL` | Graph API event ID for update/delete |
| `booked_by_user_id` | `BIGINT UNSIGNED NULL` | Who created the booking |
| `mentor_timezone` | `VARCHAR(100) NULL` | IANA timezone at booking time |
| `coach_timezone` | `VARCHAR(100) NULL` | IANA timezone at booking time |

### No New Tables

All data fits the existing `hl_coaching_session` + `hl_coach_availability` schema.

---

## New Files

| File | Purpose |
|---|---|
| `includes/integrations/class-hl-microsoft-graph.php` | Graph API client -- token management, calendar CRUD |
| `includes/integrations/class-hl-zoom-integration.php` | Zoom API client -- token management, meeting CRUD |
| `includes/services/class-hl-scheduling-service.php` | Orchestrator -- slot calculation, book/reschedule/cancel |
| `includes/services/class-hl-scheduling-email-service.php` | Notification emails for all session events |
| `includes/frontend/class-hl-frontend-schedule-session.php` | Component page scheduling UI (date picker -> slots -> confirm) |
| `includes/admin/class-hl-admin-scheduling-settings.php` | Admin settings subtab |

## Modified Files

| File | Changes |
|---|---|
| `includes/services/class-hl-coaching-service.php` | `create_session()` accepts `component_id` + enforces uniqueness (max one `scheduled` session per `component_id` + `mentor_enrollment_id`). `reschedule_session()` updated to forward `component_id`, `session_number`, `mentor_timezone`, `coach_timezone` to the replacement session. |
| `includes/frontend/class-hl-frontend-component-page.php` | Enhanced coaching session component: two states, tabbed UI |
| `includes/frontend/class-hl-frontend-my-coaching.php` | Coaching sessions hub with component list, statuses, locking |
| Admin settings loader | Register new subtab |
| DB schema/activator | Add columns, bump revision |

## Cleanup

Remove demo scheduling artifacts created for Housman demo (~2026-03-24): demo DB tables, options, and any related code. Preserve existing forms.
