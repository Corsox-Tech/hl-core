# Guided Tours — Terminal Execution Prompts

> **Orchestration guide** for running the implementation across multiple Claude Code terminals.

## Execution Order

```
┌─────────────────────────────────────────────────┐
│  PHASE 1 (Terminal 1 — Sequential, run FIRST)   │
│  DB Schema + Repository + Service               │
│  Tasks: 1.1, 1.2, 1.3                           │
│  Est: ~15 min                                    │
└───────────────────────┬─────────────────────────┘
                        │ MUST complete before ↓
        ┌───────────────┴───────────────┐
        ▼                               ▼
┌───────────────────┐    ┌───────────────────────┐
│  PHASE 2A         │    │  PHASE 2B             │
│  (Terminal 2)     │    │  (Terminal 3)         │
│  Admin UI         │    │  Frontend Engine      │
│  Tasks: 2A.1-2A.3 │    │  Tasks: 2B.1-2B.4    │
│  PARALLEL ←─────────────→ PARALLEL             │
│  Est: ~20 min     │    │  Est: ~20 min         │
└────────┬──────────┘    └──────────┬────────────┘
         │                          │
         └──────────┬───────────────┘
                    │ BOTH must complete before ↓
                    ▼
        ┌───────────────────────────┐
        │  PHASE 3 (Terminal 4)     │
        │  Element Picker + Polish  │
        │  Tasks: 3.1-3.3           │
        │  Sequential, run LAST     │
        │  Est: ~15 min             │
        └───────────────────────────┘
```

## Important Rules for ALL Terminals

1. **Read the spec FIRST:** `docs/superpowers/specs/2026-04-03-guided-tours-design.md`
2. **Read the plan FIRST:** `docs/superpowers/plans/2026-04-03-guided-tours-plan.md`
3. **Read CLAUDE.md** for project conventions.
4. **Follow existing patterns:** Before writing any class, read a similar existing class in the codebase (the plan references which ones).
5. **Commit after each task** (not after each step — after each Task block).
6. **Peer review:** After completing ALL tasks in your phase, launch a review agent (instructions included in each prompt).
7. **Do NOT modify files assigned to other terminals** unless the plan explicitly says so.

---

## Terminal 1 Prompt — Phase 1: Foundation (DB + Repository + Service)

> **Run this FIRST. Phases 2A and 2B cannot start until this completes.**

```
You are implementing Phase 1 of the Guided Tours system for the HL Core WordPress plugin.

READ THESE FILES FIRST (in order):
1. CLAUDE.md — project conventions
2. docs/superpowers/specs/2026-04-03-guided-tours-design.md — full spec
3. docs/superpowers/plans/2026-04-03-guided-tours-plan.md — implementation plan (Phase 1 section)

BEFORE WRITING CODE, study these existing patterns:
- includes/class-hl-installer.php — get_schema() method and maybe_upgrade(). Note current revision (28).
- includes/domain/repositories/class-hl-enrollment-repository.php — repository CRUD pattern.
- includes/services/class-hl-scheduling-service.php — singleton + AJAX pattern.
- hl-core.php — load_dependencies() and init() for registration.

YOUR TASKS (execute in order):
1. Task 1.1: Add hl_tour, hl_tour_step, hl_tour_seen tables to installer schema. Bump revision to 29.
2. Task 1.2: Create HL_Tour_Repository in includes/domain/repositories/class-hl-tour-repository.php. Register in hl-core.php.
3. Task 1.3: Create HL_Tour_Service in includes/services/class-hl-tour-service.php. Register in hl-core.php.

The plan has COMPLETE CODE for all three tasks. Follow it exactly, but verify it matches existing codebase patterns before committing.

COMMIT after each task with the commit message specified in the plan.

FILES YOU WILL CREATE OR MODIFY:
- Modify: includes/class-hl-installer.php
- Create: includes/domain/repositories/class-hl-tour-repository.php
- Create: includes/services/class-hl-tour-service.php
- Modify: hl-core.php (add requires + instantiation)

AFTER COMPLETING ALL 3 TASKS, launch a peer review agent with this prompt:
"Review the guided tours Phase 1 implementation. Read the spec at docs/superpowers/specs/2026-04-03-guided-tours-design.md and the plan at docs/superpowers/plans/2026-04-03-guided-tours-plan.md. Then review these files for correctness, security, and adherence to codebase patterns:
1. includes/class-hl-installer.php — verify 3 new CREATE TABLE statements match spec §5 exactly (column names, types, indexes, constraints). Verify revision bumped to 29.
2. includes/domain/repositories/class-hl-tour-repository.php — verify follows same patterns as class-hl-enrollment-repository.php. Check: table() helpers, CRUD methods, JSON encoding via HL_DB_Utils, $wpdb->prepare() on all queries, return types.
3. includes/services/class-hl-tour-service.php — verify singleton pattern, AJAX hooks in __construct(), nonce verification with check_ajax_referer(), role checking, input sanitization, wp_send_json_success/error responses.
4. hl-core.php — verify both files are required in load_dependencies() and service instantiated in init().
Report any bugs, security issues, or pattern deviations."

Fix any issues the reviewer finds before marking Phase 1 as done.

After Phase 1 is complete, update STATUS.md with the first checkbox marked [x] under a new "Guided Tours System" section (follow existing format in the file).
```

---

## Terminal 2 Prompt — Phase 2A: Admin UI (PARALLEL with Terminal 3)

> **Wait for Terminal 1 to complete. Then run in PARALLEL with Terminal 3.**

```
You are implementing Phase 2A of the Guided Tours system for the HL Core WordPress plugin. Phase 1 (DB + Repository + Service) is already complete.

READ THESE FILES FIRST (in order):
1. CLAUDE.md — project conventions
2. docs/superpowers/specs/2026-04-03-guided-tours-design.md — full spec (focus on §6 Admin UI)
3. docs/superpowers/plans/2026-04-03-guided-tours-plan.md — implementation plan (Phase 2A section)

BEFORE WRITING CODE, study these existing patterns:
- includes/admin/class-hl-admin-settings.php — tab registration, handle_early_actions(), render_page() dispatch.
- includes/admin/class-hl-admin-scheduling-settings.php — form rendering, nonce fields, handle_save() pattern, singleton.
- includes/admin/class-hl-admin.php — enqueue_assets() method for conditional asset loading.
- assets/css/admin.css — existing admin styling patterns.
- includes/services/class-hl-tour-service.php (JUST CREATED in Phase 1) — read it to understand the service methods you'll call.
- includes/domain/repositories/class-hl-tour-repository.php (JUST CREATED) — read it to understand repository methods.

YOUR TASKS (execute in order):
1. Task 2A.1: Create HL_Admin_Tours class with Tours List subtab. Register in settings hub and core loader.
2. Task 2A.2: Add Tour Editor subtab with step management, drag-reorder. Create hl-tour-admin.js. Enqueue admin assets.
3. Task 2A.3: Add Tour Styles subtab with WP Iris color pickers.

The plan has detailed code and structure for all tasks. Follow it, but adapt to match exactly how the existing admin classes work.

IMPORTANT — FILES YOU OWN (only modify these):
- Create: includes/admin/class-hl-admin-tours.php
- Modify: includes/admin/class-hl-admin-settings.php (add Tours tab)
- Modify: includes/admin/class-hl-admin.php (enqueue assets)
- Create: assets/js/hl-tour-admin.js
- Modify: assets/css/admin.css (append tour admin styles)
- Modify: hl-core.php (add require for admin class)

DO NOT MODIFY these files (Terminal 3 owns them):
- templates/hl-page.php
- assets/css/frontend.css
- assets/js/frontend.js
- assets/js/hl-tour.js

COMMIT after each task.

NOTE: The element picker in hl-tour-admin.js should be a PLACEHOLDER for now (alert saying "Coming in Phase 3"). Phase 3 will replace it with the full picker modal. The openElementPicker function must exist but can show an alert + expose the manual selector input as fallback.

AFTER COMPLETING ALL 3 TASKS, launch a peer review agent with this prompt:
"Review the guided tours Phase 2A (Admin UI) implementation. Read the spec at docs/superpowers/specs/2026-04-03-guided-tours-design.md (§6 Admin UI) and the plan at docs/superpowers/plans/2026-04-03-guided-tours-plan.md (Phase 2A section). Then review:
1. includes/admin/class-hl-admin-tours.php — verify follows HL_Admin_Scheduling_Settings pattern. Check: singleton, handle_save() with nonce verification + capability check, render methods for all 3 subtabs (list/editor/styles). Verify tour activation validates step count. Verify HTML escaping (esc_attr, esc_html, wp_kses_post for descriptions).
2. includes/admin/class-hl-admin-settings.php — verify Tours tab added correctly to render_tabs(), handle_early_actions(), render_page().
3. assets/js/hl-tour-admin.js — verify: sortable initialization, slug auto-generation, step add/remove, position pills, type toggle, trigger_type visibility toggle, color picker init. Check for XSS in DOM manipulation.
4. assets/css/admin.css — verify new styles don't conflict with existing admin styles.
5. Verify hl-core.php has the require_once for the new admin class.
Report any bugs, security issues, UX problems, or pattern deviations."

Fix any issues before marking Phase 2A as done.
```

---

## Terminal 3 Prompt — Phase 2B: Frontend Engine (PARALLEL with Terminal 2)

> **Wait for Terminal 1 to complete. Then run in PARALLEL with Terminal 2.**

```
You are implementing Phase 2B of the Guided Tours system for the HL Core WordPress plugin. Phase 1 (DB + Repository + Service) is already complete.

READ THESE FILES FIRST (in order):
1. CLAUDE.md — project conventions
2. docs/superpowers/specs/2026-04-03-guided-tours-design.md — full spec (focus on §7 Frontend)
3. docs/superpowers/plans/2026-04-03-guided-tours-plan.md — implementation plan (Phase 2B section)

BEFORE WRITING CODE, study these existing patterns:
- templates/hl-page.php — full HTML structure, topbar area (~line 100-141), asset loading (~line 185-195).
- assets/js/frontend.js — jQuery IIFE, dropdown patterns (topbar user dropdown), localStorage patterns.
- assets/css/frontend.css — :root design tokens, BEM-like naming, topbar styles, responsive breakpoints.
- includes/services/class-hl-tour-service.php (JUST CREATED in Phase 1) — read to understand get_tours_for_context(), get_global_styles(), get_user_hl_roles().

YOUR TASKS (execute in order):
1. Task 2B.1: Download and bundle Driver.js 1.4.0 (JS + CSS) into assets/js/vendor/ and assets/css/vendor/.
2. Task 2B.2: Add topbar "?" button + dropdown to hl-page.php. Add CSS to frontend.css. Add dropdown toggle to frontend.js.
3. Task 2B.3: Create hl-tour.js — the main frontend tour controller wrapping Driver.js.
4. Task 2B.4: Add PHP tour context localization to hl-page.php (wp_localize_script equivalent).

IMPORTANT — FILES YOU OWN (only modify these):
- Create: assets/js/vendor/driver.js
- Create: assets/css/vendor/driver.css
- Modify: templates/hl-page.php (topbar button + tour data localization)
- Modify: assets/css/frontend.css (append tour + topbar styles)
- Modify: assets/js/frontend.js (add dropdown toggle handler)
- Create: assets/js/hl-tour.js

DO NOT MODIFY these files (Terminal 2 owns them):
- includes/admin/class-hl-admin-tours.php
- includes/admin/class-hl-admin-settings.php
- assets/js/hl-tour-admin.js
- assets/css/admin.css

CRITICAL IMPLEMENTATION DETAILS for hl-tour.js:
- Driver.js API: `const driver = window.driver.js.driver({...})` for IIFE build.
- Steps config: `driver.highlight({element, popover: {title, description, ...}})` or `driver.drive({steps: [...]})`.
- Multi-page: Save to localStorage with key 'hl_tour_active'. Value: JSON {tour_slug, step_index, start_page_url, status}. 'navigating' = active page transition (resume from step). 'interrupted' = exit/crash (show final step only). When navigating to next page, append `?hl_active_tour={slug}` to the URL so PHP can include the full tour data on that page. This is critical — PHP cannot read localStorage.
- Progress bar: Inject via onPopoverRender hook — create a div inside the popover footer.
- Interactive steps: Set `disableActiveInteraction: false` per step. Add event listener on target element to call `driverObj.moveNext()`.
- Exit handler: `onCloseClick` and `onDestroyStarted` hooks — save state, redirect to start_page_url if not already there, then reinitialize to show final step.
- Final step: Auto-appended. Target: '#hl-tour-trigger'. On dismiss → fetch AJAX hl_tour_mark_seen → clear localStorage.
- Skip missing elements: Before each step, check document.querySelector(selector). If null, skip (filter steps array before passing to driver.drive()).
- Auto-scroll: Driver.js scrolls elements into view automatically. To offset for the 48px fixed topbar, use the `onHighlightStarted` hook to adjust scroll: `window.scrollBy(0, -80)`. `stagePadding` controls visual padding around the highlight cutout (set to 10), NOT scroll offset.
- Mobile: Check window.innerWidth < 640 && tour.hide_on_mobile before starting.

COMMIT after each task.

AFTER COMPLETING ALL 4 TASKS, launch a peer review agent with this prompt:
"Review the guided tours Phase 2B (Frontend Engine) implementation. Read the spec at docs/superpowers/specs/2026-04-03-guided-tours-design.md (§7 Frontend) and the plan at docs/superpowers/plans/2026-04-03-guided-tours-plan.md (Phase 2B section). Then review:
1. assets/js/vendor/driver.js — verify it's Driver.js v1.4.0, IIFE/UMD build, MIT license header present.
2. templates/hl-page.php — verify topbar button is before .hl-topbar__user-wrap. Verify tour data localization: check JSON structure matches what hl-tour.js expects (auto_trigger, available, active_tour, styles, ajax_url, nonce, i18n). Verify picker mode detection (hl_picker=1 with manage_hl_core check). Verify no data leaks for non-logged-in users.
3. assets/js/hl-tour.js — verify: dropdown population from hlTourData.available, localStorage state management (navigating vs interrupted), auto-trigger logic, multi-page navigation, exit→redirect→final step flow, AJAX mark_seen call, progress bar rendering, element skip logic, mobile hide_on_mobile check, auto-scroll padding. Check for XSS (are step titles/descriptions sanitized server-side before being put in hlTourData?).
4. assets/css/frontend.css — verify topbar button + dropdown styles. Check mobile bottom sheet at 768px breakpoint. Verify no conflicts with existing .hl-topbar styles.
5. assets/js/frontend.js — verify dropdown toggle follows same pattern as existing topbar user dropdown.
Report any bugs, security issues, UX problems, or missed spec requirements."

Fix any issues before marking Phase 2B as done.
```

---

## Terminal 4 Prompt — Phase 3: Element Picker + Polish (SEQUENTIAL)

> **Wait for BOTH Terminal 2 AND Terminal 3 to complete before running this.**

```
You are implementing Phase 3 of the Guided Tours system for the HL Core WordPress plugin. Phase 1 (DB + Service), Phase 2A (Admin UI), and Phase 2B (Frontend Engine) are already complete.

READ THESE FILES FIRST (in order):
1. CLAUDE.md — project conventions
2. docs/superpowers/specs/2026-04-03-guided-tours-design.md — full spec (focus on §6.4 Visual Element Picker)
3. docs/superpowers/plans/2026-04-03-guided-tours-plan.md — implementation plan (Phase 3 section)

BEFORE WRITING CODE, study the files created in previous phases:
- assets/js/hl-tour-admin.js — find the placeholder openElementPicker function. You will replace it.
- includes/admin/class-hl-admin-tours.php — understand the step editor card structure.
- templates/hl-page.php — understand picker mode detection ($is_picker_mode).
- includes/services/class-hl-tour-service.php — find get_view_as_role() if it exists, or add it.
- assets/css/admin.css — check if picker modal CSS was already added or needs to be added.

FILES YOU OWN (only modify these):
- Create: assets/js/hl-element-picker.js
- Modify: assets/js/hl-tour-admin.js (replace openElementPicker placeholder)
- Modify: assets/css/admin.css (append picker modal styles)
- Modify: templates/hl-page.php (add picker mode detection + conditional script loading)
- Modify: includes/services/class-hl-tour-service.php (add view-as-role support to get_user_hl_roles)
- Modify: STATUS.md, README.md

YOUR TASKS (execute in order):
1. Task 3.1: Create hl-element-picker.js — the script injected into the iframe when ?hl_picker=1. Implements hover highlighting, click selection, selector generation algorithm, postMessage communication.
2. Task 3.2: Replace the placeholder openElementPicker in hl-tour-admin.js with the full modal (iframe + role dropdown + postMessage listener with origin validation). Add modal CSS to admin.css. Modify get_user_hl_roles() in HL_Tour_Service to return view-as role in picker mode. Add is_picker_mode() static helper. Identify frontend renderers that check user roles directly and add view-as-role override support.
3. Task 3.3: Update STATUS.md and README.md with the guided tours section.

CRITICAL DETAILS for hl-element-picker.js:
- This script runs INSIDE the iframe (injected by hl-page.php when ?hl_picker=1).
- Must prevent normal page interactions (clicks should pick elements, not navigate).
- Hover: outline the element under cursor with a visible highlight (e.g., 2px solid #4F46E5 + box-shadow).
- Click: lock the selection. Show a fixed bottom toolbar with the generated selector + "Cancel" / "Use This Element" buttons.
- Selector generation (in priority order):
  1. Element has id → '#the-id'
  2. Element has a unique hl-* class → '.hl-the-class'
  3. Closest ancestor with hl-* class + child path → '.hl-parent > .child:nth-child(N)'
  4. Full path fallback (flag as less stable)
- Communication: window.parent.postMessage({type: 'hl-picker-select', selector: '...'}, window.location.origin)
- Ignore: the picker toolbar itself, script/style/meta elements, the hl-sidebar, the hl-topbar.

CRITICAL DETAILS for view-as-role:
- hl-page.php already detects ?hl_picker=1&hl_view_as=mentor and sets $GLOBALS['hl_view_as_role'].
- You need to add a static method HL_Tour_Service::get_view_as_role() that returns this value (only for manage_hl_core users).
- Frontend PHP renderers that conditionally show/hide elements based on roles should check this override. Key files to check:
  - includes/frontend/class-hl-frontend-program-page.php (coaching cards, pathway-specific sections)
  - includes/frontend/class-hl-frontend-dashboard.php (role-based cards)
  - includes/frontend/class-hl-frontend-my-coaching.php (coach-specific views)
  - Any file that calls get_user_hl_roles() or checks enrollment roles to show/hide UI elements.
- Add the override at the point where roles are resolved, not in every individual renderer. The cleanest approach: modify HL_Tour_Service::get_user_hl_roles() to return the view-as role when in picker mode.

COMMIT after each task.

AFTER COMPLETING ALL 3 TASKS, launch a peer review agent with this prompt:
"Review the guided tours Phase 3 (Element Picker + Polish) implementation. Read the spec at docs/superpowers/specs/2026-04-03-guided-tours-design.md (§6.4) and plan at docs/superpowers/plans/2026-04-03-guided-tours-plan.md (Phase 3). Then review:
1. assets/js/hl-element-picker.js — verify: prevents default clicks, hover highlighting, selector generation algorithm (id → hl-class → path), postMessage with origin validation, toolbar UI, ignores picker toolbar/sidebar/topbar elements. Check for edge cases: what happens with SVG elements? iframe nesting? elements with no classes or id?
2. assets/js/hl-tour-admin.js — verify openElementPicker creates modal with iframe + role dropdown. Check postMessage listener uses origin check. Verify modal close cleans up (removes src, unbinds listener).
3. includes/services/class-hl-tour-service.php — verify get_view_as_role() only works for manage_hl_core users. Verify get_user_hl_roles() respects view-as override in picker mode.
4. Check that the view-as-role override actually works in the frontend renderers — grep for where roles are checked and verify the override is applied.
5. STATUS.md — verify new Guided Tours section follows existing format, all items marked [x].
6. README.md — verify file tree and What's Implemented updated.
Report any bugs, security issues, or spec deviations."

Fix any issues before marking Phase 3 as done.

FINALLY: After everything passes review, do a git log --oneline to verify all commits are clean and in the right order.
```

---

## Summary

| Phase | Terminal | Dependencies | Can Parallel? | Tasks |
|-------|----------|-------------|---------------|-------|
| 1 | Terminal 1 | None | No — run first | 1.1, 1.2, 1.3 |
| 2A | Terminal 2 | Phase 1 done | YES — parallel with 2B | 2A.1, 2A.2, 2A.3 |
| 2B | Terminal 3 | Phase 1 done | YES — parallel with 2A | 2B.1, 2B.2, 2B.3, 2B.4 |
| 3 | Terminal 4 | Phase 2A + 2B done | No — run last | 3.1, 3.2, 3.3 |

**Total: 4 terminals, 2 sequential blocks + 1 parallel block.**

## After All Terminals Complete

Have Mateo (the orchestrator) review the final state:
1. `git log --oneline` — verify all commits are present
2. Check STATUS.md and README.md are updated
3. Deploy to test server for browser verification
4. Remove the Simple Tour Guide plugin from the LMS
