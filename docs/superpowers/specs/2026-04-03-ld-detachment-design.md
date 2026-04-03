# LearnDash BB Detachment (Phase B) — Design Spec

> **Phase A** detached HL Core shortcode pages from BuddyBoss theme via `templates/hl-page.php`. **Phase B** extends this to LearnDash Course and Lesson pages with a fully custom template shell.

## Goal

Bypass BuddyBoss theme entirely for LearnDash Course (`sfwd-courses`) and Lesson (`sfwd-lessons`) post types. Render LD content inside the HL design system shell. Zero dependency on BB or LD CSS styling. All LD JavaScript functionality (Grassblade SCORM, navigation, video tracking) preserved.

## Context & Constraints

### Why We Can't Skip wp_head/wp_footer

Unlike HL Core pages (which bypass `wp_head()`/`wp_footer()` entirely), LearnDash pages require both hooks:

- **Grassblade xAPI Companion** enqueues SCORM player scripts via standard WP hooks. 80% of lessons are SCORM-based.
- **LearnDash navigation JS** (`learndash_template_script_js`, `learndash-ld30-shortcodes-script`) provides AJAX and shortcode functionality.
- **Video tracking scripts** are enqueued in `wp_footer()` at priority 1.
- `wp_localize_script()` passes `ajaxurl` and nonces — only works if `wp_head()` runs.

### What We Don't Use

- **Mark Complete button** — hidden everywhere. SCORM auto-completes via Grassblade. Videos and forms use custom auto-completion scripts.
- **LD Quiz engine** (WpProQuiz) — not used. Forms are custom PHP or third-party plugins with scripts that trigger LD completion.
- **Timer** — no timed activities anywhere.
- **LD Focus Mode sidebar** — replaced by our own course outline panel.

### What LD Renders via `the_content()`

LearnDash hooks into WordPress's `the_content` filter (`LEARNDASH_FILTER_PRIORITY_THE_CONTENT`) and outputs all course/lesson markup inside `div.learndash-wrapper`.

**Course pages output:**
- Certificate alert (`.ld-alert.ld-alert-certificate`)
- Progress bar (`.ld-progress-bar-wrapper` > `.ld-progress` > `.ld-progress-fill`)
- Tabs — content + materials (`.ld-tabs` > `.ld-tabs-navigation` + `.ld-tabs-content`)
- Lesson list with expand/collapse (`.ld-item-list.ld-lesson-list` > `.ld-item-list-item`)
- Nested topic/quiz rows (`.ld-table-list.ld-topic-list`)
- Status icons (`.ld-icon-complete`, `.ld-icon-incomplete`, `.ld-icon-progress`)

**Lesson pages output:**
- Infobar (`.ld-infobar`)
- Tabs — content + materials (`.ld-tabs`)
- Lesson body content (inside `.ld-tab-content`) — includes SCORM embed via Grassblade
- Topic/quiz sub-list (`.ld-lesson-topic-list`)
- Assignment section (`.ld-assignment-list`) — if applicable
- Prev/Next navigation + Mark Complete form (`.ld-content-actions`)

## Architecture

### Template Intercept

Two custom templates served via `template_include` filter at priority 1001 (above BB's 999 and LD's 99):

| Template | Post Types | Body Class |
|---|---|---|
| `templates/ld-course.php` | `sfwd-courses` | `hl-app hl-ld-course` |
| `templates/ld-lesson.php` | `sfwd-lessons` | `hl-app hl-ld-lesson` |

Registration in `HL_Frontend_Shortcodes::use_hl_template()` (extend existing method):

```php
// Existing: pages with [hl_*] shortcodes
if (is_singular('page') && strpos($post->post_content, '[hl_') !== false) {
    return HL_CORE_PLUGIN_DIR . 'templates/hl-page.php';
}
// New: LearnDash post types
if (is_singular('sfwd-courses')) {
    return HL_CORE_PLUGIN_DIR . 'templates/ld-course.php';
}
if (is_singular('sfwd-lessons')) {
    return HL_CORE_PLUGIN_DIR . 'templates/ld-lesson.php';
}
```

### Template Structure

Both templates follow this pattern:

```
<!DOCTYPE html>
<html>
<head>
    <meta charset/viewport>
    <title>
    Inter font preconnect + link
    frontend.css (our design system)
    wp_head()          ← LD + Grassblade JS loads; BB + LD CSS dequeued before this
</head>
<body class="hl-app hl-ld-{type}">
    HL Topbar           ← same as hl-page.php
    HL Sidebar          ← same as hl-page.php, with collapse/expand button
    [Course Outline]    ← lesson pages only
    <main>
        the_content()   ← LD renders its functional markup here
    </main>
    wp_footer()         ← Grassblade SCORM tracking, video JS
    frontend.js
</body>
</html>
```

### Dequeue Strategy

Registered on `wp_enqueue_scripts` at priority 9999, conditioned on LD post types:

**CSS — DEQUEUE ALL:**
- `learndash_style` — LD legacy styles
- `learndash` — LD modern styles (4.13.0+)
- `sfwd_front_css` — LD front-end styles
- `learndash_quiz_front_css` — quiz styles
- `learndash_template_style_css` — theme-specific LD styles
- `jquery-dropdown-css` — dropdown plugin styles
- `learndash_pager_css` — pagination styles
- `learndash-ld30-shortcodes-style` — LD30 shortcode styles
- `learndash_lesson_video` — video container styles
- `learndash-presenter-mode-style` — presenter mode
- `buddyboss-theme-learndash` — BB's LD integration CSS
- `buddyboss-theme-css` — BB main theme CSS
- `buddyboss-theme-main-css` — BB main styles
- `buddyboss-theme-fonts` — BB font loading (we load Inter ourselves)

**JS — DEQUEUE:**
- `buddyboss-theme-learndash-js` — BB LD UI enhancements
- `buddyboss-theme-learndash-sidebar-js` — BB sidebar nav
- `wpProQuiz_front_javascript` — quiz engine (not used)
- `buddyboss-theme-main-js` — BB main JS (theme interactions)

**JS — KEEP:**
- `learndash_template_script_js` — AJAX handler, provides `ajaxurl` via `sfwd_data`
- `learndash-ld30-shortcodes-script` — shortcode functionality, REST nonces
- `learndash_video_script_js` — video progress tracking
- `learndash_cookie_script_js` — cookie management for video
- `grassblade-*` — SCORM player + xAPI completion tracking
- `jquery` — dependency for all above
- `jquery-cookie` — state persistence

## Collapsible HL Sidebar

A global enhancement that applies to ALL pages (HL Core, LearnDash, BB theme pages).

### States

**Expanded (default):** 240px wide. Icons + labels visible. Current behavior.

**Collapsed:** 60px wide. Icons only. Labels hidden. Menu item tooltips on hover showing the label text.

### Toggle

- A collapse/expand button at the bottom of the sidebar (above Log Out item)
- Collapsed state: shows a right-arrow or hamburger icon
- Expanded state: shows a left-arrow or collapse icon
- Click toggles between states

### Persistence

State stored in `localStorage` key `hl-sidebar-collapsed` (`'1'` = collapsed, absent/`'0'` = expanded).

On page load, sidebar reads localStorage and applies `.hl-sidebar--collapsed` class before render (in the template PHP via a small inline script, to prevent flash of expanded state).

### CSS

```css
.hl-sidebar--collapsed {
    width: 60px;
}
.hl-sidebar--collapsed .hl-sidebar__item span:not(.hl-sidebar__icon) {
    display: none;
}
.hl-sidebar--collapsed .hl-sidebar__brand { /* hide text, keep logo small */ }
.hl-sidebar--collapsed .hl-sidebar__badge { display: none; }
.hl-sidebar--collapsed .hl-sidebar__item {
    justify-content: center;
    padding: 12px 0;
}
```

Content area offset adjusts:
```css
.hl-sidebar--collapsed ~ .hl-app__content { margin-left: 60px; }
.hl-sidebar--collapsed ~ .hl-topbar { left: 60px; }
/* BB theme pages */
body.hl-has-nav.hl-sidebar-collapsed #page { margin-left: 60px; }
```

### Impact on Existing Templates

- `templates/hl-page.php` — add collapse toggle button HTML + inline localStorage script
- `templates/ld-course.php` — same
- `templates/ld-lesson.php` — same
- BB integration `render_nav_on_theme_pages()` — add collapse toggle button
- `assets/js/frontend.js` — add toggle click handler + localStorage read/write
- `assets/css/frontend.css` — add `.hl-sidebar--collapsed` rules

## Lesson Page: Course Outline Panel

### Purpose

On lesson pages only. Shows the course's lesson hierarchy so learners can navigate between lessons without returning to the course page.

### Layout

Positioned between the HL sidebar and the lesson content area:

```
[HL Sidebar (60/240px)] [Course Outline (~320px)] [Lesson Content (remaining)]
```

### Content

Rendered in PHP using LearnDash API functions:

- **"Back to Course" link** — links to the parent course page
- **Course title** — from `get_the_title($course_id)`
- **Progress bar** — from `learndash_course_progress()` or direct calculation
- **Completion %** — "X% Complete"
- **Last activity date** — from `learndash_user_get_course_progress()`
- **Lesson list** — from `learndash_get_course_lessons_list($course_id)` or `SFWD_LMS::get_course_lessons()`:
  - Section headers (module names) — colored bar, LD stores these as `ld_lesson_section` taxonomy or course steps
  - Lesson rows — title + completion circle (filled = complete, empty = incomplete)
  - Current lesson highlighted (active state)

### Collapse/Expand

- Toggle button at top of panel (hamburger icon or X)
- State persisted in `localStorage` key `hl-course-outline-collapsed`
- When collapsed: panel hidden, lesson content takes full remaining width
- CSS class: `.hl-course-outline--collapsed`

### Styling

- Dark background (e.g., `#1a1f2e` or `var(--hl-surface-dark)`) matching the screenshot reference
- White text on dark background
- Current lesson: highlighted row (purple/blue accent, matching `--hl-primary`)
- Section headers: colored left border or background bar (orange/amber)
- Completion circles: outline when incomplete, filled green when complete
- Scrollable if lesson list exceeds viewport height
- Fixed position (doesn't scroll with content)

### Data Source

```php
$course_id = learndash_get_course_id($post->ID);
$lessons = learndash_get_course_lessons_list($course_id, get_current_user_id());
$sections = learndash_30_get_course_sections($course_id);
$progress = learndash_course_progress(array('user_id' => $user_id, 'course_id' => $course_id, 'array' => true));
```

We render our own HTML from this data — no dependency on LD's sidebar template.

## Course Page Layout

Standard 2-column layout (same as HL Core pages):

```
[HL Sidebar (240px)] [Course Content (remaining)]
```

- HL sidebar at full width (not auto-collapsed)
- Topbar with course title breadcrumb
- `the_content()` renders LD's course markup (lesson list, progress, tabs)
- We style all LD output with our design system CSS

## CSS: Styling LD Output

New section in `frontend.css` scoped under `.hl-app .learndash-wrapper`.

### Components to Style

**Progress bar:**
```css
.hl-app .ld-progress { height: 8px; border-radius: 4px; background: var(--hl-border); }
.hl-app .ld-progress-fill { background: var(--hl-success); border-radius: 4px; transition: width 0.3s; }
```

**Tabs:**
- `.ld-tabs-navigation` — horizontal tab bar with our tab styling
- `.ld-tab` — tab buttons with active/hover states
- `.ld-tab-content` — tab panels

**Lesson list (course page):**
- `.ld-item-list` — clean list with our border/spacing
- `.ld-item-list-item` — lesson rows with hover state
- `.ld-item-name` — lesson title link
- `.ld-expand-button` — expand/collapse for topics

**Status icons:**
- `.ld-icon-complete` → green checkmark
- `.ld-icon-incomplete` → gray circle
- `.ld-icon-progress` → blue partial

**Navigation (lesson page):**
- `.ld-content-actions` — flex row with prev/next
- `.ld-button` → styled as `.hl-btn`

**Alerts:**
- `.ld-alert` → styled as our notice pattern

**Table lists (topics):**
- `.ld-table-list` → our table styling
- `.ld-table-list-header` → dark header row
- `.ld-table-list-item` → rows with hover

**Section headers:**
- `.ld-lesson-section-heading` → module dividers with accent color

**Estimated total: ~250-300 lines** of CSS, all using design tokens.

## Scope

### In Scope
- `sfwd-courses` custom template
- `sfwd-lessons` custom template
- Collapsible HL sidebar (global)
- Course outline panel (lesson pages)
- CSS for ~15 LD component types
- Dequeue strategy for BB + LD assets

### Out of Scope (extend later if needed)
- `sfwd-topic` — sub-lessons under lessons
- `sfwd-quiz` — not used
- `sfwd-assignment` — not used
- Course archive pages (listing of all courses)
- Responsive/mobile layout (separate effort)
- LD Focus Mode settings interaction (our template replaces it entirely)

## Files Created/Modified

### New Files
- `templates/ld-course.php` — course page template (~120 lines)
- `templates/ld-lesson.php` — lesson page template (~180 lines, includes course outline panel)

### Modified Files
- `includes/frontend/class-hl-shortcodes.php` — extend `use_hl_template()` for LD post types
- `includes/integrations/class-hl-buddyboss-integration.php` — add dequeue callback, collapse toggle to nav injection
- `assets/css/frontend.css` — LD component styles (~300 lines), collapsible sidebar (~40 lines), course outline panel (~80 lines)
- `assets/js/frontend.js` — sidebar collapse toggle + localStorage, course outline toggle
- `templates/hl-page.php` — add collapse toggle button to sidebar HTML

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Grassblade scripts don't load | Low | High | wp_head/wp_footer preserve standard enqueue pipeline |
| Over-dequeuing breaks LD navigation | Medium | Medium | Test expand/collapse, prev/next after dequeue |
| LD update changes markup classes | Low | Medium | We style LD's public API classes, not internal structure |
| BB theme update adds new CSS | None | None | We bypass BB template entirely |
| SCORM player styling breaks | Low | Medium | Grassblade injects its own inline styles for the player |
| Course outline data API changes | Low | Low | Using documented LD functions |

## Success Criteria

1. Course pages render with HL sidebar + topbar, LD lesson list fully styled
2. Lesson pages render with collapsible HL sidebar + course outline panel + lesson content
3. SCORM content loads and auto-completes via Grassblade
4. Prev/Next navigation works between lessons
5. No BB theme CSS or JS on course/lesson pages (except kept JS handles)
6. Collapsible sidebar works on ALL page types (HL Core, LearnDash, BB theme)
7. Visual consistency with HL design system (typography, colors, spacing)
