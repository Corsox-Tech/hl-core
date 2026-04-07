# Coaching Session Scheduling Window

**Date:** 2026-04-07
**Status:** Approved

## Problem

Coaching session components are always unlocked — they don't use drip rules for access control. However, admins need to communicate a time range during which each coaching session should be scheduled. Currently, the only date options are the enforced drip rule release date and the soft "Complete By" deadline, neither of which fits this use case cleanly.

Users scheduling coaching sessions see a calendar with no date boundaries, so they can book sessions at any time with no guidance on when the session should happen.

## Solution

Add a **Scheduling Window** (start date + end date) to coaching session components. This restricts the frontend scheduling calendar to only allow date selection within the window, while displaying informational labels on program/component pages.

This is an informational/UI restriction, not an access-control mechanism. The rules engine is not changed.

## Design

### 1. Database Schema

Add two nullable columns to `hl_component`:

```sql
scheduling_window_start DATE NULL
scheduling_window_end DATE NULL
```

- Only meaningful when `component_type = 'coaching_session_attendance'`. NULL for all other types.
- Both are optional. If only one is set, only that boundary is enforced in the calendar.
- Added via the installer's `dbDelta` migration in `class-hl-installer.php`.

### 2. Admin UI — Component Edit Form

**File:** `includes/admin/class-hl-admin-pathways.php`

A "Scheduling Window" section appears on the component edit form **only when** `component_type = coaching_session_attendance`. Uses the same JS show/hide pattern as other type-specific fields.

Two inline `<input type="date">` fields:

```
Scheduling Window
[Start Date ________]  [End Date ________]
```

- Both optional.
- On save, values are stored directly to `hl_component.scheduling_window_start` and `scheduling_window_end`.
- Existing "Release Date" (drip rule) and "Complete By" fields remain visible and functional — they are not hidden or repurposed.

### 3. Frontend Scheduling Calendar

**File:** `includes/frontend/class-hl-frontend-schedule-session.php`

The Calendly-style calendar currently renders dates from today up to `max_lead_days`. When a scheduling window is set on the component:

- **Clamp the calendar date range** to the scheduling window boundaries.
- Dates outside the window are not selectable (greyed out or not rendered).
- If today is **before** `scheduling_window_start`: the calendar starts at the start date.
- If today is **after** `scheduling_window_end`: the calendar still renders and allows booking. A notice is displayed: *"This session's scheduling window (Mar 15 – Apr 30) has passed."* No lock — the user can still book.
- If only one bound is set, only that side is clamped.
- The scheduling window **always wins** over drip rule release dates for calendar restriction purposes.
- No changes to the booking AJAX logic itself — restriction is calendar-display only.

### 4. Frontend Display — Program Page & Component Page

**Files:** `includes/frontend/class-hl-frontend-program-page.php`, `includes/frontend/class-hl-frontend-component-page.php`

For coaching session components with a scheduling window set:

- Show a date range label on the component card / header: **"Schedule between Mar 15 – Apr 30"**
- If past the end date and not yet booked: label changes to **"Scheduling window closed (Mar 15 – Apr 30)"**
- No lock or access restriction — purely informational.
- This label replaces the "Available [date]" drip badge for coaching sessions that use the scheduling window.

### 5. Rules Engine — No Changes

`HL_Rules_Engine_Service::compute_availability()` is not modified. The scheduling window is a UI/display concern only. Coaching sessions remain always available unless they independently have drip rules or prerequisites configured.

## Files to Modify

| File | Change |
|------|--------|
| `includes/class-hl-installer.php` | Add `scheduling_window_start` and `scheduling_window_end` columns to `hl_component` |
| `includes/domain/class-hl-component.php` | Add the two new properties to the domain class |
| `includes/domain/repositories/class-hl-component-repository.php` | Include new fields in save/load |
| `includes/admin/class-hl-admin-pathways.php` | Add Scheduling Window section to component form + save logic |
| `includes/frontend/class-hl-frontend-schedule-session.php` | Clamp calendar range to scheduling window, add "window passed" notice |
| `includes/frontend/class-hl-frontend-program-page.php` | Show scheduling window label on coaching session cards |
| `includes/frontend/class-hl-frontend-component-page.php` | Show scheduling window label on component page header |

## Out of Scope

- Enforcing scheduling window as a hard lock (users can still book after window closes)
- Hiding or repurposing existing drip rule / complete_by fields
- Changes to the rules engine
- Changes to the coaching service or booking AJAX logic
