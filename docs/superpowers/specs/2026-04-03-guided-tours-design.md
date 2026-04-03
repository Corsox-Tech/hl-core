# Guided Tours System — Design Spec

> **Date:** 2026-04-03
> **Status:** Draft
> **Replaces:** Simple Tour Guide plugin (3rd-party, free version)

## 1. Problem & Motivation

Housman's LMS uses the "Simple Tour Guide" plugin for guided onboarding. Its free version limits us to **1 tour**, **single-page only**, **no role targeting**, and **Next-button-only navigation**. The paid version still lacks multi-page tours and interactive element clicking. We need a purpose-built tour system inside HL Core that supports unlimited tours, multi-page flows, role-based targeting, interactive steps, and full admin control over copy and styling.

## 2. Goals

1. **Unlimited tours** — no artificial limits on tour count or step count.
2. **Three trigger types** — auto on first login (role-based), auto on first page visit, manual only (Quick Tour button).
3. **Multi-page tours** — steps can span multiple HL pages with automatic page navigation.
4. **Mixed step types** — informational (read + click Next) and interactive (click the actual highlighted element to advance).
5. **Visual element picker** — non-technical admins select target elements by clicking them in an iframe preview, not by typing CSS selectors.
6. **"View as Role" in picker** — admins can preview pages as any HL role to pick elements they don't normally see.
7. **Global styling** — one set of appearance settings (colors, font sizes) applied to all tours, editable by admins.
8. **Mobile responsive** — tours work on mobile devices with auto-sizing tooltips and smooth scrolling.
9. **Translation-ready** — UI chrome uses gettext; DB content supports future locale extension.
10. **Skip missing elements** — steps targeting elements not in the DOM (due to permissions, role visibility) are silently skipped.

## 3. Non-Goals

- Per-tour or per-step styling overrides (global only).
- Tour analytics/reporting (how many users completed, drop-off rates). Can be added later.
- Conditional branching within tours (if user clicks X, go to step Y). Linear only.
- Inline tour content editing on the frontend (admin-only editing in WP Admin).
- Translation of DB-stored tour content (deferred to the translations project).

## 4. Architecture

### 4.1 JS Library: Driver.js

**Version:** 1.4.0 | **License:** MIT | **Size:** ~5 KB gzipped, zero dependencies.

Chosen over:
- **Shepherd.js** — AGPL-3.0 since v14.0. Last MIT version (v13.0.3) is a dead end with no security patches. Includes `@scarf/scarf` telemetry dependency.
- **Intro.js** — AGPL-3.0. Commercial license available but less modern API than Driver.js.

Driver.js provides: tooltip rendering via Floating UI, viewport-aware auto-positioning, modal overlay with element highlighting, smooth auto-scrolling, `disableActiveInteraction` toggle for interactive steps, and rich lifecycle hooks (`onHighlightStarted`, `onHighlighted`, `onDeselected`, `onPopoverRender`, `onNextClick`, `onPrevClick`, `onCloseClick`).

### 4.2 Integration Pattern

Built inside HL Core (not a separate plugin). Tours are tightly coupled to HL roles, enrollments, page structure, and the existing service/repository pattern. New classes:

- `HL_Tour_Repository` (in `includes/domain/repositories/`) — DB CRUD for tours, steps, seen records.
- `HL_Tour_Service` — business logic: tour resolution for page + user + role, seen checks, skip logic.
- `HL_Admin_Tours` — admin UI: list, editor, styles tabs.
- Frontend JS controller (`hl-tour.js`) wrapping Driver.js.

## 5. Data Model

### 5.1 `hl_tour` — Tour Definitions

| Column | Type | Purpose |
|--------|------|---------|
| `tour_id` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `title` | VARCHAR(255) NOT NULL | Admin-facing name (e.g., "Mentor First Login Tour") |
| `slug` | VARCHAR(100) NOT NULL UNIQUE | URL-safe identifier for JS reference |
| `trigger_type` | ENUM('first_login','page_visit','manual_only') NOT NULL | How the tour auto-triggers (see §7.1 for trigger semantics) |
| `trigger_page_url` | VARCHAR(500) NULL | For `page_visit` triggers — the page path (e.g., `/my-program/`) |
| `target_roles` | TEXT NULL | JSON array of HL roles (e.g., `["mentor","teacher"]`). NULL = all roles. |
| `start_page_url` | VARCHAR(500) NOT NULL | Page where tour begins and where user is redirected on exit |
| `status` | ENUM('active','draft','archived') NOT NULL DEFAULT 'draft' | Only `active` tours trigger |
| `hide_on_mobile` | TINYINT NOT NULL DEFAULT 0 | If 1, tour is hidden on viewports below 640px |
| `sort_order` | INT NOT NULL DEFAULT 0 | Order in the topbar "?" dropdown |
| `created_at` | DATETIME NOT NULL | |
| `updated_at` | DATETIME NOT NULL | |

### 5.2 `hl_tour_step` — Steps Within a Tour

| Column | Type | Purpose |
|--------|------|---------|
| `step_id` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `tour_id` | BIGINT UNSIGNED NOT NULL (FK → hl_tour) | |
| `step_order` | INT NOT NULL | Sequence within the tour |
| `title` | VARCHAR(255) NOT NULL | Step title shown in tooltip |
| `description` | TEXT NOT NULL | Step content (HTML allowed, sanitized with `wp_kses_post`) |
| `page_url` | VARCHAR(500) NULL | Page this step lives on. NULL = same page as previous step. |
| `target_selector` | VARCHAR(500) NULL | CSS selector to highlight. NULL = centered modal (no element). |
| `position` | ENUM('top','bottom','left','right','auto') NOT NULL DEFAULT 'auto' | Preferred tooltip position (Driver.js flips if no room) |
| `step_type` | ENUM('informational','interactive') NOT NULL DEFAULT 'informational' | `informational` = Next button. `interactive` = click element to advance. |
| `created_at` | DATETIME NOT NULL | |
| `updated_at` | DATETIME NOT NULL | |

INDEX on `(tour_id, step_order)`.

### 5.3 `hl_tour_seen` — Per-User Completion Tracking

| Column | Type | Purpose |
|--------|------|---------|
| `seen_id` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `user_id` | BIGINT UNSIGNED NOT NULL (FK → wp_users) | |
| `tour_id` | BIGINT UNSIGNED NOT NULL (FK → hl_tour) | |
| `seen_at` | DATETIME NOT NULL | When user dismissed the final step |

UNIQUE constraint on `(user_id, tour_id)`. Additional INDEX on `(tour_id)` for admin queries (e.g., count users who have seen a tour, delete seen records for archived tours).

### 5.4 Global Styling — `wp_options`

Stored as serialized array in option `hl_tour_styles`:

| Key | Default | Purpose |
|-----|---------|---------|
| `tooltip_bg` | `#ffffff` | Tooltip background color |
| `title_color` | `var(--hl-text-primary)` mapped hex | Title text color |
| `title_font_size` | `16` (px) | Title font size |
| `desc_color` | `var(--hl-text-secondary)` mapped hex | Description text color |
| `desc_font_size` | `14` (px) | Description font size |
| `btn_bg` | `var(--hl-interactive)` mapped hex | Button background color |
| `btn_text_color` | `#ffffff` | Button text color |
| `progress_color` | `var(--hl-interactive)` mapped hex | Progress bar color |

Defaults map to HL design tokens so tours look native out of the box.

## 6. Admin UI

### 6.1 Location

New **"Tours"** top-level tab in the HL Core Settings hub (alongside Scheduling, Email Templates, etc.). Three subtabs: Tours List, Tour Editor, Tour Styles.

### 6.2 Tours List Subtab

- Table: title, trigger type, target roles, status badge, step count, sort order.
- "Add Tour" button.
- Row actions: Edit, Duplicate, Archive.
- Status filter pills: All / Active / Draft / Archived.

### 6.3 Tour Editor Subtab

**Tour Settings section:**
- Title (text input).
- Slug (auto-generated from title, editable).
- Status dropdown (Draft / Active / Archived).
- Sort order (number input).

**Trigger Config section** (contextual based on trigger type):
- `first_login`: target roles checkboxes (teacher, mentor, school_leader, district_leader, coach).
- `page_visit`: target roles checkboxes + page URL field.
- `manual_only`: target roles checkboxes only.

**Start Page URL** — always visible, required.

**Steps section:**
- Sortable list (drag to reorder via jQuery UI Sortable).
- Each step is a collapsible card with:
  - Title (text input).
  - Description (TinyMCE editor for rich content).
  - Page URL (text input; blank = same page as previous step).
  - Target Element: "Pick Element" button → visual element picker modal. Auto-detected selector shown in a styled read-only field. "Edit manually" toggle exposes raw input as fallback.
  - Position selector (Top / Bottom / Left / Right / Auto pill buttons).
  - Step Type toggle (Informational / Interactive).
- "Add Step" button at bottom.
- Remove step button with confirmation on each card.
- The editor shows a note on the last step: "This step will automatically show users where to replay the tour."

### 6.4 Visual Element Picker

When the admin clicks "Pick Element" on a step:

1. A full-screen modal opens with a **"View as Role"** dropdown at the top (Admin, Teacher, Mentor, School Leader, Coach, District Leader).
2. Below the dropdown, an **iframe** loads the step's page URL with query params: `?hl_picker=1&hl_view_as={role}`.
3. On the PHP side, `hl_picker=1` injects a picker JavaScript file into the page. `hl_view_as` temporarily overrides role checks for the current render — **only for users with `manage_hl_core` capability** (security gate).
4. In the iframe: hovering over elements draws a highlight border. A bottom toolbar shows the auto-detected selector in real-time.
5. Clicking an element locks the selection. The toolbar shows "Selected: .hl-progress-summary" with "Cancel" and "Use This Element" buttons.
6. "Use This Element" sends the selector back to the parent window via `postMessage`. The modal closes and the selector populates the step's Target Element field.

**Selector generation strategy** (in order of preference):
1. Element has an `id` → `#my-element` (most reliable).
2. Element has a unique `hl-*` class → `.hl-progress-summary` (stable across deploys).
3. Closest ancestor with an `hl-*` class + child path → `.hl-card:nth-child(2) > .hl-card-body`.
4. Full DOM path fallback (least stable, flagged with a warning in the UI).

### 6.5 Tour Styles Subtab (Global)

- Color pickers (WP Iris): tooltip background, title color, description color, button background, button text color, progress bar color.
- Number inputs: title font-size, description font-size.
- Live preview mockup showing a sample tooltip with current settings.
- "Reset to Defaults" button (restores HL design token values).

## 7. Frontend — Tour Engine

### 7.1 Page Load Flow

On every HL page load:

1. **PHP resolution:** `HL_Tour_Service::get_tours_for_context( $current_page_url, $current_user )` returns:
   - Auto-trigger tours (active, matching role, matching page, not in `hl_tour_seen`).
   - Available manual tours (active, matching role, for topbar dropdown).
   - **Active tour data** (if the URL contains `?hl_active_tour={slug}` — appended by `hl-tour.js` during page transitions — PHP includes the full step set for that tour regardless of page context. This ensures cross-page resumption always has complete data).
2. **Localize to JS:** `wp_localize_script('hl-tour', 'hlTourData', [...])` passes tour metadata, step definitions, global styles, AJAX URL + nonce.
3. **JS controller init:**
   - Check `localStorage` for `hl_tour_active` (interrupted tour from browser close or mid-tour exit). If found → redirect to `start_page_url` → show final step only. This is NOT a resume — the user sees the "here's how to replay" final step, dismisses it, and the tour is marked seen.
   - If auto-trigger tour exists → initialize Driver.js → start tour.
   - Otherwise → do nothing (tours available via topbar only).

**Trigger semantics:**
- `first_login`: triggers once per user on any page load where the user has not seen this tour (checked via `hl_tour_seen`). Despite the name, this is not tied to the literal login event — it fires on the first HL page load where the tour is active, the user matches `target_roles`, and no `hl_tour_seen` record exists. It is named "first_login" to distinguish its intent (platform onboarding) from `page_visit` (page-specific guidance).
- `page_visit`: triggers on the first visit to `trigger_page_url` where the user matches `target_roles` and has no `hl_tour_seen` record.
- `manual_only`: never auto-triggers. Only available via the topbar "?" dropdown.

### 7.2 During a Tour

**Step rendering:**
- Before each step, check `document.querySelector(target_selector)`. If element not found → skip step. Recalculate progress counter based on visible steps only (e.g., "2 of 5" not "2 of 8").
- **Informational step:** Driver.js renders tooltip with Back / Next buttons (or Done on last visible step). "X" button to close/exit.
- **Interactive step:** Tooltip renders with instructional text. `disableActiveInteraction: false` on that step. Event listener on the target element → `driver.moveNext()` on click. Next/Back buttons hidden (user must click the element).

**Page transitions (multi-page tours) — ACTIVE tour only:**
- When the next step's `page_url` differs from the current page:
  - Save to `localStorage`: `{ tour_id, tour_slug, current_step_index, start_page_url, status: 'navigating' }`.
  - Navigate: `window.location.href = next_step_page_url + '?hl_active_tour=' + tour_slug` (URL param tells PHP to include the full tour data on the next page).
- On the new page load, the JS controller reads `localStorage`. If `status` is `'navigating'`, the tour is actively in progress — find the tour in `hlTourData` (PHP always includes it; see §7.1) and resume from `current_step_index`.
- This is the ONLY scenario where a tour resumes from a saved step. The `'navigating'` status distinguishes an intentional page transition from an interruption.

**Exit / dismiss mid-tour:**
- User clicks "X" on any tooltip or presses Escape:
  - Set `localStorage` status to `'interrupted'`.
  - Redirect to `start_page_url`.
  - Show the final step (highlighting the topbar "?" button with the message: "You can replay this tour anytime from here").
  - User dismisses the final step → AJAX `hl_tour_mark_seen` → inserts `hl_tour_seen` record → clears `localStorage`.

**Browser close mid-tour (no graceful exit):**
- `localStorage` has `hl_tour_active` with status `'navigating'` (the user was between pages) or no status update was possible (browser killed).
- Next page load: controller detects stale tour state → sets status to `'interrupted'` → redirects to start page → shows final step → dismiss marks seen.
- The user does NOT resume from their saved step — they always see only the final "here's how to replay" step. This is intentional: a clean exit path is more predictable than resuming mid-flow after an unknown interruption.

### 7.3 Auto-Generated Final Step

Every tour automatically appends a final step (not stored in DB, generated by JS):
- `target_selector`: the topbar "?" button (`#hl-tour-trigger`).
- `title`: "Replay This Tour"
- `description`: "You can revisit this tour anytime by clicking here."
- `step_type`: `informational`
- `position`: `bottom`
- Dismissing this step (clicking "Done" or "X") is the **only action** that marks the tour as "seen."

### 7.4 Topbar "?" Button

- Element: `<button id="hl-tour-trigger">` in the HL topbar (48px bar from BB detachment), positioned immediately left of `.hl-topbar__user-wrap` (the user avatar/dropdown).
- Visible for all logged-in users with active enrollments or staff roles.
- Click → dropdown panel listing available tours for the current page:
  - Each entry: tour title + small badge (trigger type).
  - Clicking a tour starts it immediately (works even for already-seen tours).
- If no tours target the current page → dropdown shows "No tours available for this page."
- On mobile: dropdown renders as a bottom sheet with larger tap targets.

### 7.5 Auto-Scroll

- Driver.js auto-scrolls the target element into view with `behavior: 'smooth'`.
- Custom `padding` option set to `80` (px) to offset the HL topbar height so elements aren't hidden behind it.

### 7.6 Mobile

- Driver.js popovers auto-resize to viewport width on narrow screens.
- Interactive steps work with touch events (tap = click).
- Per-tour `hide_on_mobile` toggle (stored as a column on `hl_tour`, TINYINT DEFAULT 0) allows hiding specific tours on viewports below 640px.
- The topbar "?" dropdown becomes a bottom sheet on mobile (touch-friendly, larger tap targets).

## 8. File Structure

### 8.1 New Files

```
includes/
  domain/repositories/
    class-hl-tour-repository.php          # CRUD for hl_tour, hl_tour_step, hl_tour_seen
  services/
    class-hl-tour-service.php             # Tour resolution, seen checks, skip logic
  admin/
    class-hl-admin-tours.php              # Tours list + editor + styles admin tabs
assets/
  js/
    vendor/driver.js                      # Driver.js 1.4.0 (bundled locally)
    hl-tour.js                            # Frontend tour controller
    hl-tour-admin.js                      # Admin: step reordering, element picker modal, color pickers
    hl-element-picker.js                  # Picker script injected into iframe via ?hl_picker=1
  css/
    vendor/driver.css                     # Driver.js base styles
```

### 8.2 Modified Files

- `class-hl-db-manager.php` — 3 new tables. Schema revision 28 → 29.
- `class-hl-core.php` — Register `HL_Tour_Service`, enqueue tour assets on HL frontend pages.
- `class-hl-admin.php` — Register Tours tab in Settings hub.
- `templates/hl-page.php` — Add topbar "?" button + dropdown markup.
- `assets/css/frontend.css` — Tour dropdown styles, topbar button styles, bottom sheet mobile styles.
- `assets/js/frontend.js` — Topbar dropdown toggle logic (or included in `hl-tour.js`).

### 8.3 AJAX Endpoints

| Action | Auth | Purpose |
|--------|------|---------|
| `hl_tour_mark_seen` | Logged-in user | Insert `hl_tour_seen` record when final step dismissed |
| `hl_tour_get_steps` | Logged-in user | Fetch steps for a tour (manual replay via "?" button) |
| `hl_tour_save_step_order` | `manage_hl_core` | Save drag-and-drop step reordering |

### 8.4 Asset Loading

- `driver.js` + `driver.css` + `hl-tour.js`: enqueued only on HL template pages (not all WordPress pages).
- `hl-tour-admin.js` + `hl-element-picker.js`: enqueued only on the Tours admin page.
- Picker script (`hl-element-picker.js`): injected into the iframe only when `?hl_picker=1` query param is present.

## 9. Security

- All AJAX endpoints verify nonces (`wp_verify_nonce`).
- `hl_tour_mark_seen` checks: user is logged in, tour exists, tour is `active`, and user matches `target_roles`. Prevents pre-marking unseen tours or marking tours for other roles.
- `hl_tour_get_steps` checks: user is logged in and user matches the tour's `target_roles`. Step content is not sensitive, but role-gating prevents data leakage of role-specific instructions.
- Admin endpoints require `manage_hl_core` capability.
- `hl_view_as` query param is only honored for users with `manage_hl_core` — silently ignored for all others.
- Tour step descriptions sanitized with `wp_kses_post()` on save.
- Element picker uses `postMessage` with origin validation against `window.location.origin` (same-origin only; both admin and frontend are on the same WordPress domain).
- **Validation:** Active tours require at least one step. The admin UI prevents activating a tour with zero steps (status cannot be set to `active` if no steps exist).

## 10. Translation Readiness

- **UI chrome** ("Next", "Back", "Done", "Step X of Y", "Replay This Tour", "No tours available for this page"): all wrapped in `__()` / `_e()` for WordPress gettext.
- **Tour content** (titles, descriptions entered by Yuyan): stored in DB as-is. Future options when the translations project starts:
  - (a) Add a `locale` column to `hl_tour` — Yuyan creates locale-specific tour variants. Schema supports this with zero migration pain.
  - (b) Integrate with a WP DB-string translation plugin.
  - Decision deferred; no schema changes needed now.

## 11. Edge Cases

| Scenario | Behavior |
|----------|----------|
| Step target not in DOM (permissions, role visibility) | Skip step silently. Recalculate progress counter. |
| Selector matches multiple elements | Highlight first match (`querySelector` behavior). |
| Page redesign breaks a selector | Step skipped silently. No crash. Admin can re-pick element. |
| User has multiple auto-trigger tours on same page | Show the one with the lowest `sort_order`. Others remain pending for next visit. |
| User has both first_login and page_visit tours | `first_login` takes priority (lower sort_order by convention). |
| Tour has zero visible steps (all skipped) | Tour is silently marked as seen. No UI shown. |
| Admin archives a tour that users haven't seen yet | Tour stops triggering. No `hl_tour_seen` record inserted. If re-activated later, it will trigger for users who haven't seen it. |
| Browser close mid-tour | `localStorage` has active tour, no `hl_tour_seen`. Next page load redirects to start page → final step. |
| User clears localStorage | Same as browser close — no `hl_tour_seen` record → tour re-triggers. |
| Admin tries to activate tour with zero steps | Blocked by validation. Status cannot be set to `active` without at least one step. |
| `localStorage` says resume tour X but `hlTourData` doesn't have it | Cannot happen: PHP always includes full step data for any tour referenced in the user's active tour state (see §7.1). If the tour was deleted/archived since, PHP omits it and JS clears the stale `localStorage` entry. |
