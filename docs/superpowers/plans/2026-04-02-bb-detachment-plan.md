# BB Detachment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detach BuddyBoss theme from all HL Core pages using a plugin-owned page template via `template_include`, eliminating the CSS war entirely.

**Architecture:** HL pages get intercepted by a `template_include` filter and served through a clean HTML template (`templates/hl-page.php`) that never loads BB's theme files. BB stays active for forum pages. The 3-layer CSS defense and BuddyPanel injection code are deleted.

**Tech Stack:** WordPress PHP 7.4+, `template_include` filter, `wp_print_styles()` / `wp_print_scripts()` for targeted asset output.

**Spec:** `docs/superpowers/specs/2026-04-02-bb-detachment-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `templates/hl-page.php` | **Create** | Complete HTML shell — head, sidebar, topbar, content, scripts |
| `includes/frontend/class-hl-shortcodes.php` | **Modify** | Add template intercept, delete BB defense code |
| `includes/integrations/class-hl-buddyboss-integration.php` | **Modify** | Delete sidebar/menu injection, keep redirects + menu builder |
| `assets/css/frontend.css` | **Modify** | Rewrite BB selectors, delete BB layout overrides, add `.hl-app` base |

---

### Task 1: Create the page template

**Files:**
- Create: `templates/hl-page.php`

- [ ] **Step 1: Create `templates/` directory and template file**

```php
<?php
/**
 * HL Core Page Template
 *
 * Serves all pages containing [hl_*] shortcodes.
 * Bypasses the active theme entirely — outputs a clean HTML document
 * with only HL Core assets. No wp_head()/wp_footer().
 *
 * @package HL_Core
 */
if (!defined('ABSPATH')) exit;

$bb = HL_BuddyBoss_Integration::instance();
$menu_items = $bb->get_menu_items_for_current_user();
$is_logged_in = is_user_logged_in();

// Current URL for active menu highlighting.
$current_url = trailingslashit(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));

// User info for topbar.
$user = wp_get_current_user();
$display_name = $user->display_name ?: $user->user_login;
$initials = '';
if ($user->first_name) {
    $initials .= strtoupper(substr($user->first_name, 0, 1));
}
if ($user->last_name) {
    $initials .= strtoupper(substr($user->last_name, 0, 1));
}
if (!$initials && $display_name) {
    $initials = strtoupper(substr($display_name, 0, 2));
}

// Breadcrumb: find current page label from menu items.
$current_page_label = '';
foreach ($menu_items as $item) {
    $item_path = trailingslashit(wp_parse_url($item['url'], PHP_URL_PATH) ?: '');
    if ($item_path && $item_path === $current_url) {
        $current_page_label = $item['label'];
        break;
    }
}
// Dashboard URL for breadcrumb link — first menu item is the logical "home".
$dashboard_url = !empty($menu_items) ? $menu_items[0]['url'] : home_url('/');

// Render shortcode content (may enqueue per-shortcode assets).
global $post;
$page_content = do_shortcode($post->post_content);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php wp_print_styles(['dashicons']); ?>
    <link rel="stylesheet" href="<?php echo esc_url(HL_CORE_ASSETS_URL . 'css/frontend.css'); ?>?ver=<?php echo esc_attr(HL_CORE_VERSION); ?>">
    <?php
    // Flush any styles enqueued during do_shortcode() (e.g. hl_docs).
    wp_print_styles();
    ?>
    <?php wp_print_scripts(['jquery']); ?>
    <script>
        var hlCoreAjax = { ajaxurl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>' };
    </script>
</head>
<body class="hl-app">

<?php if ($is_logged_in && !empty($menu_items)) : ?>
    <!-- Top Bar -->
    <div class="hl-topbar" id="hl-topbar">
        <div class="hl-breadcrumb">
            <?php if ($current_page_label) : ?>
                <a href="<?php echo esc_url($dashboard_url); ?>">Dashboard</a> &rsaquo;
                <span><?php echo esc_html($current_page_label); ?></span>
            <?php else : ?>
                <span>Dashboard</span>
            <?php endif; ?>
        </div>
        <div class="hl-topbar__user">
            <span><?php echo esc_html($display_name); ?></span>
            <div class="hl-topbar__avatar"><?php echo esc_html($initials); ?></div>
        </div>
    </div>

    <!-- Sidebar -->
    <nav class="hl-sidebar" id="hl-sidebar">
        <div class="hl-sidebar__brand">
            <div class="hl-sidebar__logo">HL</div>
            <div class="hl-sidebar__title"><?php esc_html_e('Housman Learning', 'hl-core'); ?></div>
            <div class="hl-sidebar__subtitle"><?php esc_html_e('Learning Hub', 'hl-core'); ?></div>
        </div>
        <div class="hl-sidebar__nav">
            <?php foreach ($menu_items as $item) :
                $item_path = trailingslashit(wp_parse_url($item['url'], PHP_URL_PATH) ?: '');
                $is_active = ($item_path && $item_path === $current_url);
                $active_class = $is_active ? ' hl-sidebar__item--active' : '';
            ?>
                <a href="<?php echo esc_url($item['url']); ?>" class="hl-sidebar__item<?php echo esc_attr($active_class); ?>">
                    <span class="hl-sidebar__icon dashicons <?php echo esc_attr($item['icon']); ?>"></span>
                    <span><?php echo esc_html($item['label']); ?></span>
                    <?php if (!empty($item['badge'])) : ?>
                        <span class="hl-sidebar__badge"><?php echo (int) $item['badge']; ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="hl-sidebar__footer">
            <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="hl-sidebar__item">
                <span class="hl-sidebar__icon dashicons dashicons-migrate"></span>
                <span><?php esc_html_e('Log Out', 'hl-core'); ?></span>
            </a>
        </div>
    </nav>
<?php endif; ?>

<main class="hl-app__content">
    <?php echo $page_content; ?>
</main>

<?php
// Flush any scripts enqueued during do_shortcode() (e.g. hl_docs).
wp_print_scripts();
?>
<script src="<?php echo esc_url(HL_CORE_ASSETS_URL . 'js/frontend.js'); ?>?ver=<?php echo esc_attr(HL_CORE_VERSION); ?>"></script>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add templates/hl-page.php
git commit -m "feat: add HL page template — clean HTML shell bypassing BB theme"
```

---

### Task 2: Wire the template intercept + clean up shortcodes

**Files:**
- Modify: `includes/frontend/class-hl-shortcodes.php`

- [ ] **Step 1: Add `template_include` filter and delete BB defense hooks from constructor**

Replace lines 15-24 (the constructor) with:

```php
private function __construct() {
    add_action('init', array($this, 'register_shortcodes'));
    add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    add_filter('template_include', array($this, 'use_hl_template'));
    add_action('template_redirect', array('HL_Frontend_My_Cycle', 'handle_export'));
    add_action('template_redirect', array('HL_Frontend_Team_Page', 'handle_export'));
    add_action('template_redirect', array('HL_Frontend_Cycle_Workspace', 'handle_export'));
    add_action('template_redirect', array('HL_Frontend_My_Coaching', 'handle_post_actions'));
    add_action('template_redirect', array('HL_Frontend_Classroom_Page', 'handle_post_actions'));
    add_action('template_redirect', array('HL_Frontend_Coach_Mentor_Detail', 'handle_export'));
    add_action('template_redirect', array('HL_Frontend_Coach_Reports', 'handle_export'));
    add_action('template_redirect', array('HL_Frontend_Coach_Availability', 'handle_post_actions'));
    add_action('template_redirect', array('HL_Frontend_User_Profile', 'handle_post_actions'));
}
```

This removes:
- `add_action('template_redirect', ... 'remove_bb_enqueue_hooks')` (line 21)
- `add_action('wp_print_styles', ... 'final_style_override')` (line 22)
- `add_filter('style_loader_tag', ... 'filter_bb_style_tags')` (line 24)

- [ ] **Step 2: Add `use_hl_template()` method after the constructor**

```php
/**
 * Intercept HL pages and serve through the plugin's own template.
 * Bypasses the active theme entirely for pages with [hl_*] shortcodes.
 *
 * @param string $template Theme template path.
 * @return string Template path to use.
 */
public function use_hl_template($template) {
    global $post;
    if (!is_a($post, 'WP_Post')) return $template;
    if (strpos($post->post_content, '[hl_') === false) return $template;
    return HL_CORE_PLUGIN_DIR . 'templates/hl-page.php';
}
```

- [ ] **Step 3: Add early return to `enqueue_assets()` for template pages**

At the top of `enqueue_assets()` (line 81), after the `if (!is_a($post, 'WP_Post')) return;` check, add:

```php
// Template handles its own assets — skip WP enqueue for HL pages.
if (strpos($post->post_content, '[hl_') !== false) return;
```

- [ ] **Step 4: Delete the 3 BB defense methods and `is_hl_page()`**

Delete these methods entirely:
- `is_hl_page()` (lines 134-138)
- `remove_bb_enqueue_hooks()` (lines 145-184)
- `filter_bb_style_tags()` (lines 190-206)
- `final_style_override()` (lines 219-277)

- [ ] **Step 5: Commit**

```bash
git add includes/frontend/class-hl-shortcodes.php
git commit -m "feat: wire template_include intercept, delete BB CSS defense code"
```

---

### Task 3: Gut the BB integration file

**Files:**
- Modify: `includes/integrations/class-hl-buddyboss-integration.php`

- [ ] **Step 1: Make `get_menu_items_for_current_user()` public**

Change line 959 from `private function get_menu_items_for_current_user()` to `public function get_menu_items_for_current_user()`.

- [ ] **Step 2: Add WP Admin menu item to `build_menu_items()`**

In the `$menu_def` array (around line 1045-1068), add this entry at the very end, before the closing `);`:

```php
// --- Admin ---
array('wp-admin', null, __('WP Admin', 'hl-core'), 'dashicons-dashboard', current_user_can('manage_options')),
```

Then in the loop below (around line 1071), modify the URL assignment to handle the `null` shortcode case:

```php
foreach ($menu_def as $def) {
    list($slug, $shortcode, $label, $icon, $visible) = $def;
    if (!$visible) {
        continue;
    }
    if ($shortcode === null) {
        // Direct URL items (no shortcode page lookup).
        $url = ($slug === 'wp-admin') ? admin_url() : '';
    } else {
        $url = $this->find_shortcode_page_url($shortcode);
    }
    if ($url) {
        $items[] = array(
            'slug'  => $slug,
            'label' => $label,
            'url'   => $url,
            'icon'  => $icon,
        );
    }
}
```

- [ ] **Step 3: Delete sidebar, menu injection, and body class methods + their hook registrations**

From the constructor (starting at line 63), remove these hook registrations:

```php
// DELETE these lines:
add_action('wp_head', array($this, 'render_custom_css'));
add_action('buddyboss_theme_after_bb_profile_menu', array($this, 'render_profile_dropdown_menu'));
add_filter('wp_nav_menu_items', array($this, 'filter_buddypanel_menu_items'), 20, 2);
add_action('wp_footer', array($this, 'render_js_fallback'), 99);
add_action('wp_footer', array($this, 'render_hl_sidebar'), 5);
add_filter('body_class', array($this, 'add_sidebar_body_class'));
```

Then delete these methods entirely:
- `render_custom_css()` (lines 146-236)
- `add_sidebar_body_class()` (lines 248-259)
- `render_hl_sidebar()` (lines 267-379) — the full method including the inline `<script>` block
- `render_profile_dropdown_menu()` (lines 584-616)
- `filter_buddypanel_menu_items()` (lines 634-689)
- `strip_legacy_buddypanel_items()` (lines 705-732)
- `render_js_fallback()` (lines 746-945)

Also remove the tracking properties that are no longer needed:
- `$buddypanel_injected` (line 47)
- `$profile_dropdown_rendered` (line 54)

- [ ] **Step 4: Commit**

```bash
git add includes/integrations/class-hl-buddyboss-integration.php
git commit -m "refactor: gut BB integration — delete sidebar, menu injection, body class code"
```

---

### Task 4: CSS — rewrite BB selectors to `.hl-app`

**Files:**
- Modify: `assets/css/frontend.css`

- [ ] **Step 1: Rewrite the typography/form reset block (lines 162-248)**

Replace `body.buddyboss-theme .entry-content` prefix with `.hl-app` on all selectors. The full replacement for lines 148-248:

```css
/* =====================================================
   1b. GLOBAL FONT & BASE STYLES
   Typography, links, headings, form elements, tables.
   ===================================================== */

/* Global Inter — all pages are HL Core now */
body {
    font-family: var(--hl-font) !important;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Base resets inside HL app */
.hl-app [class*="hl-"],
.hl-app [class*="hlap-"],
.hl-app [class*="hlcd-"],
.hl-app [class*="hlcm-"],
.hl-app [class*="hlcr-"],
.hl-app [class*="hlca-"],
.hl-app [class*="hlch-"],
.hl-app [class*="hlmc-"],
.hl-app [class*="hlrn-"],
.hl-app [class*="hlss-"],
.hl-app [class*="hlup-"] {
    font-family: var(--hl-font);
    color: var(--hl-text);
    line-height: 1.6;
}

/* Link colors */
.hl-app [class*="hl-"] a:not(.hl-btn):not([class*="hl-btn"]) {
    color: var(--hl-interactive);
    transition: var(--hl-transition);
}
.hl-app [class*="hl-"] a:not(.hl-btn):hover {
    color: var(--hl-interactive-dark);
}

/* Headings */
.hl-app [class*="hl-"] h1,
.hl-app [class*="hl-"] h2,
.hl-app [class*="hl-"] h3,
.hl-app [class*="hl-"] h4 {
    font-family: var(--hl-font);
    color: var(--hl-text-heading);
    font-weight: 700;
    line-height: 1.3;
}

/* Form elements */
.hl-app [class*="hl-"] input[type="text"],
.hl-app [class*="hl-"] input[type="email"],
.hl-app [class*="hl-"] input[type="number"],
.hl-app [class*="hl-"] input[type="search"],
.hl-app [class*="hl-"] input[type="date"],
.hl-app [class*="hl-"] select,
.hl-app [class*="hl-"] textarea {
    font-family: var(--hl-font);
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-xs);
    padding: 10px 14px;
    font-size: 14px;
    color: var(--hl-text);
    background: var(--hl-surface);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    outline: none;
}
.hl-app [class*="hl-"] input:focus,
.hl-app [class*="hl-"] select:focus,
.hl-app [class*="hl-"] textarea:focus {
    border-color: var(--hl-interactive);
    box-shadow: 0 0 0 3px var(--hl-interactive-bg);
}

/* Tables */
.hl-app [class*="hl-"] table {
    border-collapse: collapse;
    width: 100%;
}
.hl-app [class*="hl-"] table th {
    text-align: left;
    padding: 10px 14px;
    font-size: 11px;
    font-weight: 600;
    color: var(--hl-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid var(--hl-border);
    background: transparent;
}
.hl-app [class*="hl-"] table td {
    padding: 12px 14px;
    font-size: 14px;
    color: var(--hl-text);
    border-bottom: 1px solid var(--hl-border-light);
}
.hl-app [class*="hl-"] table tr:hover td {
    background: var(--hl-bg-subtle);
}
```

Note: all `!important` declarations are removed since there's no BB CSS to fight.

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "refactor: rewrite BB typography selectors to .hl-app, drop !important"
```

---

### Task 5: CSS — delete BB layout overrides + title-hiding

**Files:**
- Modify: `assets/css/frontend.css`

- [ ] **Step 1: Delete the BB header offset comment and rule (lines 120-128)**

Delete:
```css
/* =====================================================
   0. BUDDYBOSS FIXED-HEADER OFFSET
   ...
   ===================================================== */
html {
    scroll-padding-top: 80px;
}
```

Replace with:
```css
/* =====================================================
   0. SCROLL OFFSET FOR FIXED TOPBAR
   ===================================================== */
html {
    scroll-padding-top: 70px;
}
```

- [ ] **Step 2: Delete the BB page title hiding block (lines 130-146)**

Delete the entire section:
```css
/* =====================================================
   1. HIDE BUDDYBOSS THEME PAGE TITLE ON HL PAGES
   ===================================================== */
.hl-dashboard ~ .entry-title, ...
body:has(.hl-dashboard) .entry-header .entry-title, ...
```

These target BB DOM elements that don't exist in the HL template.

- [ ] **Step 3: Delete the entire BB layout override section (lines ~6660-6742)**

Delete everything from the comment block `/* =================================================================` through line 6742 (the `.container` override). This is the 9-step BB suppression:
1. Hide BB buddypanel sidebar
2. Hide BB top header/navbar
3. Hide WP admin bar
4. Hide BB/Elementor footer
5. Hide BB page title
6. Kill BB's margin-left on #page.site
7. Simple layout: sidebar is fixed
8. Content area fills remaining space
9. Kill BB inner grid/container constraints

- [ ] **Step 4: Delete the `body.buddyboss-theme` sidebar specificity overrides (lines ~6808, 6823, 6830)**

Delete these three rules:
```css
body.buddyboss-theme .hl-sidebar a.hl-sidebar__item { ... }
body.buddyboss-theme .hl-sidebar a.hl-sidebar__item:hover { ... }
body.buddyboss-theme .hl-sidebar a.hl-sidebar__item--active { ... }
```

The unprefixed rules at lines 6806, 6821, 6828 already have the full styling.

- [ ] **Step 5: Replace the content area padding rule (lines ~6906-6914)**

Delete:
```css
body.hl-has-sidebar .entry-content {
    padding: 80px 32px 24px !important;
    background: var(--hl-bg);
    min-height: 100vh;
    max-width: none !important;
}
body.hl-has-sidebar #content {
    padding-top: 0 !important;
}
```

Replace with:
```css
/* Content area — offset for fixed topbar (56px) + sidebar (240px) */
.hl-app__content {
    margin-left: 240px;
    padding: 80px 32px 24px;
    background: var(--hl-bg);
    min-height: 100vh;
}
```

- [ ] **Step 6: Replace the responsive breakpoint (lines ~7044-7057)**

Delete:
```css
@media (max-width: 1024px) {
    body.hl-has-sidebar .site,
    body.hl-has-sidebar #page {
        margin-left: 0 !important;
    }
    .hl-sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    .hl-sidebar.hl-sidebar--open {
        transform: translateX(0);
    }
}
```

Replace with:
```css
@media (max-width: 1024px) {
    .hl-app__content {
        margin-left: 0;
    }
    .hl-sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    .hl-sidebar.hl-sidebar--open {
        transform: translateX(0);
    }
    .hl-topbar {
        left: 0;
    }
}
```

- [ ] **Step 7: Commit**

```bash
git add assets/css/frontend.css
git commit -m "refactor: delete BB layout overrides, add .hl-app__content layout"
```

---

### Task 6: Deploy to test server and verify

**Files:** None (deployment + verification)

- [ ] **Step 1: Deploy to test server**

Read `.claude/skills/deploy.md` for SSH and deployment commands, then deploy the changes to the test server.

- [ ] **Step 2: Verify an HL page loads correctly**

Open any HL page (e.g., Dashboard) in the browser. Check:
- Page loads without BB theme markup (no BB header, no BB footer, no BuddyPanel)
- HL sidebar renders with correct menu items
- Top bar renders with breadcrumb and user avatar
- Page content renders correctly (not behind topbar)
- No JavaScript errors in console (jQuery loaded, `frontend.js` working)
- Fonts render as Inter
- Form elements are styled (not browser defaults)

- [ ] **Step 3: Verify the Docs page**

Open the Documentation page (`[hl_docs]`). Check:
- `frontend-docs.css` loads (the per-shortcode asset)
- `frontend-docs.js` loads and `hlDocsConfig` is defined
- Search/navigation works

- [ ] **Step 4: Verify a forum page still uses BB theme**

Open `/ecsel-community/` or `/forums/`. Check:
- BB theme renders normally (header, footer, BuddyPanel)
- No HL sidebar appears
- Forum functionality works

- [ ] **Step 5: Verify redirects still work**

- Log out and log back in → should redirect to HL Dashboard
- Visit a BB dashboard page → should redirect to HL Dashboard
- Click a forum user's name → should redirect to HL User Profile

- [ ] **Step 6: Verify on mobile viewport**

Resize browser to < 1024px width. Check:
- Sidebar collapses (off-screen)
- Content area uses full width (no left margin)
- Topbar spans full width

- [ ] **Step 7: Commit any fixes, then update STATUS.md + README.md**

Per CLAUDE.md Rule #3: update STATUS.md build queue and README.md "What's Implemented" with the BB detachment work.

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md + README.md for BB detachment"
```
