# HLA Design System — Complete Frontend Redesign Spec

> **Goal:** Create a unified design system and apply it to ALL 40+ frontend pages so every HL Core page looks like it belongs to the same product.

> **Reference mockup:** `.superpowers/brainstorm/6835-1775065694/content/design-system-showcase.html`

> **Approach:** 3-layer implementation — Global Base Layer (CSS-only) → Sidebar + Layout Shell (1 PHP + CSS) → Page-by-Page Extraction (40 PHP files, parallelizable)

---

## 1. Design Tokens (Consolidated Palette)

Merge the current two overlapping palettes (original + "Calm Professional") into one canonical `:root` block in `assets/css/frontend.css`. Remove duplicate definitions. The indigo variables stay but `--hl-interactive` becomes the canonical name for the indigo accent.

```css
:root {
    /* ---- Primary (Navy) ---- */
    --hl-primary:          #1A2B47;
    --hl-primary-light:    #243B5C;
    --hl-primary-rgb:      26, 43, 71;

    /* ---- Accent (Green — CTAs, success) ---- */
    --hl-accent:           #2ECC71;
    --hl-accent-dark:      #059669;
    --hl-accent-darker:    #047857;

    /* ---- Interactive (Indigo — tags, links, secondary actions) ---- */
    --hl-interactive:      #6366f1;
    --hl-interactive-dark: #4f46e5;
    --hl-interactive-light:#818cf8;
    --hl-interactive-bg:   #f5f3ff;
    --hl-interactive-border:#c7d2fe;

    /* ---- Secondary (Blue — legacy compat, info states) ---- */
    --hl-secondary:        #2C7BE5;
    --hl-secondary-dark:   #1D4ED8;

    /* ---- Warning / Error ---- */
    --hl-warning:          #F59E0B;
    --hl-error:            #EF4444;
    --hl-error-dark:       #DC2626;

    /* ---- Text Scale ---- */
    --hl-text:             #374151;
    --hl-text-heading:     #1A2B47;
    --hl-text-secondary:   #6B7280;
    --hl-text-muted:       #9CA3AF;

    /* ---- Surfaces ---- */
    --hl-surface:          #FFFFFF;
    --hl-bg:               #F4F5F7;
    --hl-bg-subtle:        #F9FAFB;
    --hl-bg-card:          #F7F8FA;
    --hl-bg-hover:         #EFF6FF;

    /* ---- Borders ---- */
    --hl-border:           #E8EAF0;
    --hl-border-light:     #F0F1F5;
    --hl-border-medium:    #D4D8E0;

    /* ---- Radius ---- */
    --hl-radius:           16px;
    --hl-radius-sm:        12px;
    --hl-radius-xs:        8px;
    --hl-radius-pill:      100px;

    /* ---- Shadows ---- */
    --hl-shadow:           0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
    --hl-shadow-md:        0 2px 8px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);
    --hl-shadow-lg:        0 4px 16px rgba(0,0,0,0.08);
    --hl-shadow-hover:     0 4px 12px rgba(0,0,0,0.07);

    /* ---- Transitions ---- */
    --hl-transition:       all 0.2s ease;
    --hl-transition-slow:  all 0.3s ease;

    /* ---- Status Colors ---- */
    --hl-status-active-bg:      #D1FAE5;
    --hl-status-active-text:    #065F46;
    --hl-status-complete-bg:    #D1FAE5;
    --hl-status-complete-text:  #065F46;
    --hl-status-progress-bg:    #DBEAFE;
    --hl-status-progress-text:  #1D4ED8;
    --hl-status-paused-bg:      #FEF3C7;
    --hl-status-paused-text:    #92400E;
    --hl-status-draft-bg:       #F3F4F6;
    --hl-status-draft-text:     #6B7280;
    --hl-status-archived-bg:    #FEE2E2;
    --hl-status-archived-text:  #991B1B;

    /* ---- Typography ---- */
    --hl-font:             'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
```

**What gets removed:** The separate "Calm Professional Palette" section (lines 80-97 currently) and the "CRM Palette" section (lines 73-78). Their useful values are absorbed into the canonical tokens above. Old aliases like `--hl-indigo` become `--hl-interactive`, `--hl-slate-*` values are used directly in the tokens above (e.g., `--hl-text-secondary` already maps to the slate-500 value).

---

## 2. Global Base Layer (CSS-only, no PHP changes)

A new section in `frontend.css` that auto-styles HTML elements inside HL Core containers. This gives every existing page an immediate visual uplift without modifying PHP.

### 2.1 Site-Wide Reset

```css
/* Force Inter globally — all pages are HL Core now */
body {
    font-family: var(--hl-font) !important;
    color: var(--hl-text);
    -webkit-font-smoothing: antialiased;
}
```

### 2.2 BuddyBoss Override Layer

Override BB theme styles that bleed into HL pages:

- Kill BB's `font-family` on body, headings, inputs
- Reset BB's link colors to use `--hl-interactive`
- Override BB's `.entry-content` padding/margins
- Hide BB's `.buddypanel` sidebar (we render our own)
- Hide BB's page title bar on HL pages (already partially done, expand selectors)
- Override BB's button styles inside HL containers
- Reset BB's form element styles (inputs, selects, textareas)

Scope aggressively with `body.buddyboss-theme` selectors to avoid specificity wars.

### 2.3 Auto-Styled Elements

Inside any container matching `[class*="hl-"], [class*="hlap-"], [class*="hlcd-"], [class*="hlcm-"], [class*="hlcr-"], [class*="hlca-"], [class*="hlch-"], [class*="hlmc-"], [class*="hlrn-"], [class*="hlss-"], [class*="hlup-"]`:

- **Headings** (`h1`-`h4`): `color: var(--hl-text-heading)`, `font-weight: 700`, scaled sizes
- **Paragraphs**: `color: var(--hl-text)`, `line-height: 1.6`
- **Links** (`a`): `color: var(--hl-interactive)`, hover: `var(--hl-interactive-dark)`
- **Inputs, Selects, Textareas**: border `var(--hl-border)`, radius `var(--hl-radius-xs)`, focus ring `var(--hl-interactive-bg)` + `border-color: var(--hl-interactive)`
- **Tables**: header `var(--hl-text-muted)` uppercase, row hover `var(--hl-bg-subtle)`, border `var(--hl-border-light)`
- **Labels**: `color: var(--hl-text-muted)`, `font-weight: 600`, `text-transform: uppercase`, `letter-spacing: 0.5px`, `font-size: 11px`

### 2.4 Component Classes

These classes form the design system's component library. PHP files will be updated to use them.

**Cards:**
- `.hl-card` — white surface, border, radius-sm, shadow, padding 20px
- `.hl-card-label` — uppercase muted label inside card

**Buttons:**
- `.hl-btn` — base button (Inter font, radius-xs, transition, weight 600)
- `.hl-btn--accent` — green CTA
- `.hl-btn--interactive` — indigo action
- `.hl-btn--outline` — transparent with border
- `.hl-btn--sm` — smaller variant

**Badges:**
- `.hl-badge` — base (pill radius, small font, weight 600)
- `.hl-badge--success`, `--warning`, `--error`, `--info`, `--draft` — status color variants

**Pills/Tags:**
- `.hl-pill` — base pill shape
- `.hl-pill--interactive` — indigo filled
- `.hl-pill--primary` — navy filled
- `.hl-pill--outline` — dashed border, add-more style

**Tabs:**
- `.hl-tabs` — flex container with bottom border
- `.hl-tab` — individual tab, bottom-border on active
- `.hl-tab--active` — indigo bottom border + indigo text

**Hero:**
- `.hl-hero` — navy background, radius, padding
- `.hl-hero__tag` — green pill badge
- `.hl-hero__title` — white, bold, large
- `.hl-hero__subtitle` — white/65% opacity

**Meta Bar:**
- `.hl-meta-bar` — flex row of metadata items
- `.hl-meta-item` — white card with icon, label, value

**Tables:**
- `.hl-table` — full-width, styled headers, hover rows
- `.hl-table__link` — indigo link inside table cells

**Progress Bar:**
- `.hl-progress` — track with fill bar
- `.hl-progress__fill` — colored fill (accent for high, interactive for mid, warning for low)

**Empty State:**
- `.hl-empty` — centered message with muted text

**Breadcrumb:**
- `.hl-breadcrumb` — inline breadcrumb with `>` separators, links in indigo

**Section Divider:**
- `.hl-section-divider` — numbered/icon section header with horizontal rule

**Rating Circles:**
- `.hl-rating` — row of numbered circles
- `.hl-rating__circle`, `.hl-rating__circle--selected` — indigo fill when selected

---

## 3. Sidebar + Layout Shell

### 3.1 Replace BuddyBoss Sidebar

**File:** `includes/integrations/class-hl-buddyboss-integration.php`

Current approach: HL Core injects menu items into BB's BuddyPanel via 3 hooks (`wp_nav_menu_items` filter, `buddyboss_theme_after_bb_profile_menu` action, `wp_footer` JS fallback). This causes inconsistent rendering because BB controls the sidebar container styling.

New approach:
- **Keep** `get_menu_items_for_current_user()` — the menu item data source is good
- **Replace** the 3 injection hooks with a single `render_hl_sidebar()` method
- **Hook** into `wp_footer` to output the sidebar HTML + a JS snippet that moves it into the correct DOM position (before the BB content area)
- **CSS** hides BB's `.buddypanel` and replaces it with our sidebar

### 3.2 Sidebar HTML Structure

```html
<nav class="hl-sidebar" id="hl-sidebar">
  <div class="hl-sidebar__brand">
    <div class="hl-sidebar__logo">HL</div>
    <div class="hl-sidebar__title">Housman Learning</div>
    <div class="hl-sidebar__subtitle">Learning Hub</div>
  </div>
  <div class="hl-sidebar__nav">
    <!-- Rendered from get_menu_items_for_current_user() -->
    <a href="..." class="hl-sidebar__item hl-sidebar__item--active">
      <span class="hl-sidebar__icon dashicons dashicons-dashboard"></span>
      <span class="hl-sidebar__label">Dashboard</span>
      <span class="hl-sidebar__badge">3</span><!-- optional -->
    </a>
    <!-- ... more items ... -->
  </div>
  <div class="hl-sidebar__footer">
    <a href="..." class="hl-sidebar__item">
      <span class="hl-sidebar__icon dashicons dashicons-migrate"></span>
      <span class="hl-sidebar__label">Log Out</span>
    </a>
  </div>
</nav>
```

### 3.3 Layout Grid

CSS creates the layout grid:

```css
/* When HL sidebar is present, restructure the BB layout */
body.hl-has-sidebar .site {
    display: grid;
    grid-template-columns: 240px 1fr;
    min-height: 100vh;
}
body.hl-has-sidebar .buddypanel { display: none !important; }
body.hl-has-sidebar #content { grid-column: 2; }
```

The `hl-has-sidebar` class gets added to `<body>` by the sidebar renderer (via `body_class` filter).

### 3.4 Top Bar

A simple top bar inside the content area with breadcrumb + user info. Injected globally by the sidebar renderer (same hook), so pages don't need to render it themselves. Each page provides its breadcrumb data via a filter or a static method that the renderer calls.

```html
<div class="hl-topbar">
  <div class="hl-breadcrumb"><!-- page-specific --></div>
  <div class="hl-topbar__user">
    <span class="hl-topbar__name">John Teacher-T01</span>
    <div class="hl-topbar__avatar">JT</div>
  </div>
</div>
```

---

## 4. Page-by-Page Migration Map

### 4.1 Files With Inline `<style>` Blocks (Priority — extract to frontend.css)

| File | Wrapper Class | Style Lines | Inline style= | Tokens Used |
|------|--------------|-------------|----------------|-------------|
| coach-mentor-detail.php | hlcmd-wrapper | 530 | 15 | 0 |
| teacher-assessment-renderer.php | hl-tsa-notice | 527 | 3 | 10 |
| instrument-renderer.php | hl-ca-notice | 474 | 4 | 24 |
| schedule-session.php | hlss-wrapper | 168 | 17 | 0 |
| rp-notes.php | hlrn-form-wrapper | 139 | 12 | 0 |
| coach-reports.php | hlcr-wrapper | 101 | 3 | 0 |
| classroom-visit.php | hl-classroom-visit-wrapper | 92 | 9 | 0 |
| action-plan.php | hlap-form-wrapper | 80 | 2 | 0 |
| coach-availability.php | hlca-wrapper | 75 | 0 | 0 |
| coach-dashboard.php | hlcd-wrapper | 71 | 5 | 0 |
| coach-mentors.php | hlcm-wrapper | 68 | 2 | 0 |
| child-assessment.php | hl-child-assessment | 51 | 6 | 17 |
| my-coaching.php | hlmc-wrap | 43 | 3 | 0 |
| coaching-hub.php | hlch-wrapper | 15 | 3 | 0 |
| rp-session.php | hl-rp-session-mentor-view | 6 | 2 | 0 |

**Total: 15 files, ~2,541 lines of inline CSS to extract.**

### 4.2 Files With Inline `style=` Attributes Only (No style blocks)

| File | Wrapper Class | Inline style= |
|------|--------------|----------------|
| my-cycle.php | hl-my-cycle | 38 |
| classroom-page.php | hl-classroom-page | 18 |
| user-profile.php | hl-user-profile | 15 |
| self-reflection.php | hl-self-reflection | 4 |
| docs.php | hl-docs-wrapper | 3 |
| program-page.php | hl-program-page | 3 |
| my-programs.php | hl-my-programs | 2 |
| team-page.php | hl-team-page | 2 |
| learners.php | hl-learners | 2 |
| rp-session.php (already above) | — | — |
| component-page.php | hl-component-page | 1 |
| cycle-dashboard.php | hl-cycle-dashboard | 1 |
| observations.php | hl-observations | 1 |
| my-progress.php | hl-my-progress | 1 |
| team-progress.php | hl-team-progress | 1 |

### 4.3 Clean Files (No inline CSS at all)

| File | Wrapper Class |
|------|--------------|
| classrooms-listing.php | hl-classrooms-listing |
| cycles-listing.php | hl-cycles-listing |
| dashboard.php | hl-dashboard-home hl-dashboard-v2 |
| district-page.php | hl-district-page |
| districts-listing.php | hl-districts-listing |
| institutions-listing.php | hl-institutions-listing |
| my-team.php | hl-my-team |
| pathways-listing.php | hl-pathways-listing |
| reports-hub.php | hl-reports-hub |
| school-page.php | hl-school-page |
| schools-listing.php | hl-schools-listing |
| teacher-assessment.php | hl-teacher-assessment |

**These 12 files need no PHP changes.** They'll automatically pick up the global base layer styling.

---

## 5. Parallel Session Architecture

### Session 1: Foundation (MUST run first, blocks all others)
- Consolidate `:root` design tokens
- Write global base layer CSS (BB overrides, auto-styled elements, component classes)
- Write sidebar component (PHP + CSS)
- Write layout shell CSS
- Test: deploy to test server, verify all 40 pages render without visual breakage

### Session 2: Forms & Instruments (after Session 1)
Files: action-plan.php, classroom-visit.php, rp-notes.php, self-reflection.php, child-assessment.php, instrument-renderer.php, teacher-assessment-renderer.php
- Extract all `<style>` blocks → `frontend.css` using component classes
- Replace `style=` attributes with semantic classes
- Ensure form elements (selects, textareas, pills, rating circles) use `.hl-*` classes

### Session 3: Coach Pages (after Session 1, parallel with Session 2)
Files: coach-dashboard.php, coach-mentors.php, coach-mentor-detail.php, coach-reports.php, coach-availability.php, coaching-hub.php, my-coaching.php
- Extract all `<style>` blocks → `frontend.css`
- Replace `style=` attributes with semantic classes
- Standardize card grids, stat cards, and data tables to use `.hl-card`, `.hl-table`, `.hl-badge-*`

### Session 4: Program & Navigation Pages (after Session 1, parallel with 2 & 3)
Files: program-page.php, component-page.php, my-programs.php, my-progress.php, my-cycle.php, cycle-workspace.php, cycle-dashboard.php, schedule-session.php, rp-session.php
- Extract schedule-session.php `<style>` block (168 lines) → `frontend.css`
- Replace my-cycle.php's 38 inline style attributes with classes
- Standardize hero banners, tabs, component cards across all program-related pages

### Session 5: Directory & Profile Pages (after Session 1, parallel with 2, 3 & 4)
Files: user-profile.php, team-page.php, team-progress.php, school-page.php, classroom-page.php, district-page.php, schools-listing.php, districts-listing.php, classrooms-listing.php, institutions-listing.php, cycles-listing.php, pathways-listing.php, learners.php, observations.php, reports-hub.php, docs.php, dashboard.php, my-team.php
- These are mostly clean files — main work is ensuring they use component classes
- classroom-page.php has 18 inline style attributes to replace
- user-profile.php has 15 inline style attributes to replace
- Standardize CRM directory cards, detail page headers, listing grids

### Session 6: Review & Polish (after Sessions 2-5 complete)
- Visual review of every page on test server
- Fix any inconsistencies between sessions
- Clean up any remaining hardcoded colors in `frontend.css` old sections
- Remove dead CSS sections that were replaced by the component library
- Final STATUS.md + README.md updates

---

## 6. CSS File Strategy

### 6.1 frontend.css Reorganization

The current 6,334-line file has 55+ sections accumulated over time. After the redesign:

**Keep & update:**
- Section 0: `:root` tokens (consolidated per Section 1 above)
- Sections that are already using tokens and working well

**New sections (appended or replacing old equivalents):**
- Global Base Layer (BB overrides, auto-styled elements)
- Component Library (.hl-card, .hl-btn, .hl-badge, .hl-tabs, .hl-hero, .hl-meta-bar, .hl-table, .hl-pill, .hl-progress, .hl-rating, .hl-breadcrumb, .hl-empty, .hl-section-divider)
- Sidebar (.hl-sidebar)
- Layout Shell (grid, top bar)
- Page-specific overrides (only where a page genuinely needs unique styling beyond components)

**Remove (dead CSS after extraction):**
- Old page-specific sections that are fully replaced by component classes
- V2-scoped sections (`.hl-program-page-v2`, `.hl-dashboard-v2`, `.hl-cycle-workspace-v2`) — these were stopgap redesigns; the new global system replaces them
- Duplicate/conflicting style definitions

### 6.2 No New CSS Files

Everything stays in `frontend.css`. One file, one source of truth. The file will likely end up at 4,000-5,000 lines (smaller than current 6,334 because inline CSS extraction replaces page-specific sections with reusable components).

---

## 7. What Does NOT Change

- **PHP business logic** — services, repositories, CLI commands: zero changes
- **Database** — no schema changes
- **Admin pages** — `admin.css` and admin controllers: untouched
- **JavaScript functionality** — no behavior changes, only CSS class names may update in JS selectors
- **LearnDash integration** — untouched
- **API endpoints / AJAX handlers** — untouched

---

## 8. Success Criteria

1. Every frontend page uses Inter font
2. Every page has the dark navy sidebar with consistent menu items
3. Every form uses the same input, select, textarea, button styles
4. Every tab bar uses underline tabs with indigo active state
5. Every hero banner uses the same navy + green tag pattern
6. Every status badge uses `.hl-badge--*` variants
7. Every tag/pill uses `.hl-pill--*` variants
8. Zero `<style>` blocks remain in PHP files
9. Zero hardcoded hex colors in PHP `style=` attributes
10. All pages use only `var(--hl-*)` tokens (in frontend.css) — changing a token value changes the entire site
