# BB Detachment Design — Plugin Page Template

**Date:** 2026-04-02
**Status:** Approved
**Approach:** Plugin-owned page template via `template_include`

## Problem

BuddyBoss theme fights HL Core for CSS control on every HL page. The plugin currently uses a 3-layer defense (hook removal, queue cleanup, style tag filter) that is fragile and breaks with BB updates. Five consecutive commits have been dedicated to this CSS war.

## Solution

Intercept all HL pages via WordPress's `template_include` filter and serve them through a minimal PHP template owned by the plugin. BB's template files (`header.php`, `footer.php`, `sidebar.php`) never execute for HL pages. Forum pages continue using BB theme unchanged.

## Architecture

### Template Intercept

A `template_include` filter in `HL_Shortcodes` checks if the current page contains `[hl_` shortcodes. If so, it returns `templates/hl-page.php` instead of the theme's template.

```php
add_filter('template_include', array($this, 'use_hl_template'));

public function use_hl_template($template) {
    global $post;
    if (!is_a($post, 'WP_Post')) return $template;
    if (strpos($post->post_content, '[hl_') === false) return $template;
    return HL_CORE_PATH . 'templates/hl-page.php';
}
```

### Template File: `templates/hl-page.php`

Outputs a complete HTML document:

**`<head>` section:**
- `<!DOCTYPE html>` with `language_attributes()`, charset, viewport
- Google Fonts (Inter) preconnect + stylesheet
- Dashicons CSS via targeted `wp_print_styles(['dashicons'])`
- `frontend.css` linked directly
- jQuery via `wp_print_scripts(['jquery'])` — required because `frontend.js` wraps in `(function($){})(jQuery)`
- `hlCoreAjax` JS object providing `ajaxurl` via `esc_url(admin_url('admin-ajax.php'))` for any future AJAX needs

**`<body class="hl-app">` section:**
- HL sidebar (rendered directly, not via `wp_footer` hook)
- HL top bar (rendered directly)
- `<main class="hl-app__content">` wrapping `do_shortcode($post->post_content)`
- **After `do_shortcode()`:** call `wp_print_styles()` + `wp_print_scripts()` to flush any assets enqueued during shortcode rendering (e.g., `hl_docs` enqueues its own CSS/JS/localized data via `wp_enqueue_*` + `wp_localize_script` inside `render_docs()`)
- `frontend.js` loaded at bottom of body

**Does NOT load:** `wp_head()`, `wp_footer()`, BB stylesheets, BB JavaScript, BB template markup. Instead, targeted `wp_print_styles()` / `wp_print_scripts()` calls output only what HL code explicitly enqueues.

**Admin bar:** Omitted intentionally for clean UX. A "WP Admin" sidebar link is added for `manage_options` users instead.

### Sidebar Rendering

The sidebar and top bar HTML currently in `HL_BuddyBoss_Integration::render_hl_sidebar()` moves into the template. The template calls `HL_BuddyBoss_Integration::instance()` to access `get_menu_items_for_current_user()` (made public) for building the nav. The breadcrumb dashboard link is extracted from the menu items array rather than calling `find_shortcode_page_url()` directly.

### Per-Shortcode Assets

Some shortcodes enqueue their own CSS/JS during render (confirmed: `hl_docs` via `HL_Frontend_Docs::enqueue_docs_assets()`). The template handles this by calling `wp_print_styles()` and `wp_print_scripts()` (with no arguments = flush entire queue) **after** `do_shortcode()` runs. This outputs only what HL shortcodes enqueued — BB assets are never enqueued because BB's `wp_enqueue_scripts` hooks never fire (the template never calls `wp_head()` which triggers that action).

## Changes to Existing Files

### `class-hl-shortcodes.php`

**Add:**
- `template_include` filter registration in constructor
- `use_hl_template()` method (6 lines)

**Delete:**
- `remove_bb_enqueue_hooks()` method + hook registration
- `final_style_override()` method + hook registration
- `filter_bb_style_tags()` method + hook registration
- `is_hl_page()` method

**Simplify:**
- `enqueue_assets()` — add early return when page contains `[hl_` shortcode (template handles its own assets). Check: `strpos($post->post_content, '[hl_') !== false` → return early.

### `class-hl-buddyboss-integration.php`

**Keep (used by template + redirects):**
- `get_user_hl_roles()` — role detection for sidebar + redirects
- `get_menu_items_for_current_user()` — sidebar menu builder
- `build_menu_items()` — role-based menu visibility
- `find_shortcode_page_url()` — page URL discovery
- `count_available_components()` — badge counts
- `invalidate_badge_cache()` — cache invalidation hook
- `is_control_group_only()` — control group detection
- `hl_login_redirect()` — post-login redirect
- `redirect_bb_dashboard_to_hl()` — BB dashboard redirect
- `redirect_bb_profile_to_hl()` — BB profile redirect
- `soften_bpnoaccess_message()`, `remove_bp_shake_code()`, `add_bpnoaccess_welcome_message()` — login page fixes

**Delete:**
- `render_hl_sidebar()` — moves into template
- `render_custom_css()` — BB-specific menu CSS
- `render_profile_dropdown_menu()` — BuddyPanel dropdown injection
- `filter_buddypanel_menu_items()` — BuddyPanel sidebar injection
- `render_js_fallback()` — JS DOM injection fallback
- `strip_legacy_buddypanel_items()` — BB menu item cleanup
- `add_sidebar_body_class()` — body class filter

**Modify:**
- `build_menu_items()` — add "WP Admin" item for `manage_options` users

**Constructor:** Remove hook registrations for all deleted methods. Keep redirect hooks, login hooks, and badge cache invalidation.

### `assets/css/frontend.css`

**Delete (~200 lines):**
- Entire "BB layout override" section (lines ~6660-6850): the 9-step BB suppression rules
- All `body.buddyboss-theme` specificity qualifiers on sidebar/topbar styles (lines ~6808, 6823, 6830)
- All `!important` declarations that were fighting BB
- Dashboard title-hiding CSS targeting BB DOM nodes (`.entry-header`, `.bb-grid > .entry-title`) — these elements don't exist in the template

**Rewrite (~85 lines at lines 162-246):**
- All `body.buddyboss-theme .entry-content [class*="hl-"]` selectors → `.hl-app [class*="hl-"]` (or just `[class*="hl-"]`). These set font-family, link colors, headings, form inputs, and table styles. Without rewriting, all HL pages lose typography and form styling.

**Rename:**
- `body.hl-has-sidebar` selectors → `.hl-app`
- Responsive breakpoint at ~line 7044: `body.hl-has-sidebar .site` → `.hl-app .hl-app__content` (content margin-left reset on mobile)

**Keep:**
- All sidebar visual styles (`.hl-sidebar`, `.hl-sidebar__brand`, etc.) — remove `body.buddyboss-theme` prefixed duplicates, keep unprefixed rules
- All topbar styles (`.hl-topbar`, `.hl-breadcrumb`)
- All component/page styles (unchanged)

**Add:**
- `.hl-app` base styles: `font-family: 'Inter', sans-serif`, box-sizing reset, basic typography
- `.hl-app__content` layout: `margin-left: 240px`, `padding: 80px 32px 24px` (80px top clears the fixed 56px topbar), `min-height: 100vh`, `background: var(--hl-bg)`
- Update `scroll-padding-top` from 80px to ~70px (was calibrated for BB's header, HL topbar is 56px)

### Summary of Net Changes

| File | Lines Deleted | Lines Added/Modified |
|------|--------------|---------------------|
| `class-hl-shortcodes.php` | ~60 | ~10 |
| `class-hl-buddyboss-integration.php` | ~600 | ~10 |
| `frontend.css` | ~200 deleted, ~85 rewritten | ~50 |
| `templates/hl-page.php` (new) | — | ~90 |
| **Net** | **~860 deleted** | **~160 added** |

## Forum Pages (Phase A)

Forum pages (`/ecsel-community/`, `/forums/`) do not contain `[hl_` shortcodes, so the `template_include` filter does not intercept them. They continue using BB theme templates unchanged.

On forum pages, enrolled users see the **default BB BuddyPanel** (not the HL-customized one) since BuddyPanel injection code is deleted. This is acceptable because forums are temporary — Phase C will remove BB entirely.

Redirects still function on forum pages:
- Login redirect → HL Dashboard
- BB profile click → HL User Profile
- BB dashboard → HL Dashboard

## Phase C Transition

When BB theme is dropped entirely (planned within days):

1. Switch active theme to a minimal theme (Astra or custom)
2. **Extract redirects from `is_active()` guard first** — `hl_login_redirect()`, `redirect_bb_dashboard_to_hl()`, and `redirect_bb_profile_to_hl()` are currently inside the `if (!$this->is_active()) return;` block in the constructor. When BB is removed, `is_active()` returns `false` and these redirects silently stop. They must be moved outside the guard (or into a standalone class) before removing BB.
3. Delete remaining BB-only code (login softening, `is_bb_theme_active()`)
4. The template continues working identically — no changes needed
5. Set up bbPress for forums if needed

The template is designed to be BB-agnostic. It works whether BB is the active theme or not.

## Risk Assessment

- **Low risk:** `template_include` is a standard WordPress pattern used by WooCommerce, LearnDash, and many major plugins
- **No BB dependency:** Template outputs its own complete HTML document
- **jQuery preserved:** Loaded via `wp_print_scripts(['jquery'])` before `frontend.js`
- **Per-shortcode assets preserved:** `wp_print_styles()` + `wp_print_scripts()` after `do_shortcode()` flushes any assets enqueued during render
- **Exports/POST preserved:** `template_redirect` handlers fire before `template_include`
- **Rollback:** Revert the commit to restore BB integration behavior

## Review Notes (2026-04-02)

Issues identified by dual architecture + code review and addressed in this revision:

1. ~~jQuery not loaded~~ → Added `wp_print_scripts(['jquery'])` to template
2. ~~Per-shortcode assets lost~~ → Added `wp_print_styles()` + `wp_print_scripts()` post-render
3. ~~Typography/form CSS dead (lines 162-246)~~ → Added to CSS rewrite scope
4. ~~Content behind topbar~~ → Specified `padding: 80px 32px 24px` for `.hl-app__content`
5. ~~Responsive CSS dead selectors~~ → Added to CSS rename scope
6. ~~Phase C redirect loss~~ → Added explicit extraction step to Phase C plan
7. ~~`hlCoreAjax.nonce` misleading~~ → Simplified to `ajaxurl` only (nonces handled per-handler)
