# HLA Design System — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement a unified design system across ALL 42 frontend files so every HL Core page shares consistent typography, colors, layout, sidebar, and component styling.

**Architecture:** 3-layer approach — (1) Global CSS base layer with consolidated tokens and component classes, (2) Custom sidebar + layout shell replacing BuddyBoss, (3) Page-by-page inline CSS extraction. Layers 1-2 are sequential (foundation), Layer 3 is split into 4 parallel sessions.

**Tech Stack:** PHP 7.4+ (WordPress), CSS custom properties, vanilla JS, BuddyBoss theme overrides

**Spec:** `docs/superpowers/specs/2026-04-01-design-system-spec.md`
**Reference Mockup:** `.superpowers/brainstorm/6835-1775065694/content/design-system-showcase.html`

---

## Session Map

| Session | Name | Dependencies | Files Changed |
|---------|------|-------------|---------------|
| **1** | Foundation (CSS + Sidebar) | None | `frontend.css`, `class-hl-buddyboss-integration.php`, `class-hl-shortcodes.php` |
| **2** | Forms & Instruments | Session 1 merged | 7 frontend PHP files |
| **3** | Coach Pages | Session 1 merged | 7 frontend PHP files |
| **4** | Program & Navigation Pages | Session 1 merged | 9 frontend PHP files |
| **5** | Directory & Profile Pages | Session 1 merged | 19 frontend PHP files |
| **6** | Review & Polish | Sessions 2-5 merged | Any files needing fixes |

Sessions 2-5 run **in parallel** after Session 1 is merged. Each session works on a non-overlapping set of PHP files. The ONLY shared file is `frontend.css` — each session appends new page-specific CSS sections at the end of the file, clearly labeled with the page name. Sessions must NOT modify other sessions' CSS sections.

---

## File Map

### Session 1 — Foundation
| File | Action | Responsibility |
|------|--------|---------------|
| `assets/css/frontend.css` | Modify (lines 1-100 rewrite, append ~800 lines) | Consolidated tokens, BB overrides, component library, sidebar CSS, layout shell |
| `includes/integrations/class-hl-buddyboss-integration.php` | Modify (lines 140-228 rewrite, lines 485-797 rewrite) | Replace BB sidebar injection with custom HL sidebar renderer |
| `includes/frontend/class-hl-shortcodes.php` | Modify (line 17 area) | Add `body_class` filter for `hl-has-sidebar` |

### Session 2 — Forms & Instruments
| File | Action | Responsibility |
|------|--------|---------------|
| `includes/frontend/class-hl-frontend-action-plan.php` | Modify | Extract 80-line `<style>` block, replace 2 inline styles |
| `includes/frontend/class-hl-frontend-classroom-visit.php` | Modify | Extract 92-line `<style>` block, replace 9 inline styles |
| `includes/frontend/class-hl-frontend-rp-notes.php` | Modify | Extract 139-line `<style>` blocks (×2), replace 12 inline styles |
| `includes/frontend/class-hl-frontend-self-reflection.php` | Modify | Replace 4 inline styles with classes |
| `includes/frontend/class-hl-frontend-child-assessment.php` | Modify | Extract 51-line `<style>` block, modernize 17 existing token refs |
| `includes/frontend/class-hl-instrument-renderer.php` | Modify | Extract 474-line `<style>` block, modernize 24 token refs |
| `includes/frontend/class-hl-teacher-assessment-renderer.php` | Modify | Extract 527-line `<style>` block, modernize 10 token refs |
| `assets/css/frontend.css` | Modify (append) | Add extracted form/instrument CSS using design tokens |

### Session 3 — Coach Pages
| File | Action | Responsibility |
|------|--------|---------------|
| `includes/frontend/class-hl-frontend-coach-dashboard.php` | Modify | Extract 71-line `<style>` block, replace 5 inline styles |
| `includes/frontend/class-hl-frontend-coach-mentors.php` | Modify | Extract 68-line `<style>` block, replace 2 inline styles |
| `includes/frontend/class-hl-frontend-coach-mentor-detail.php` | Modify | Extract 530-line `<style>` blocks (×2), replace 15 inline styles |
| `includes/frontend/class-hl-frontend-coach-reports.php` | Modify | Extract 101-line `<style>` block, replace 3 inline styles |
| `includes/frontend/class-hl-frontend-coach-availability.php` | Modify | Extract 75-line `<style>` block |
| `includes/frontend/class-hl-frontend-coaching-hub.php` | Modify | Extract 15-line `<style>` block, replace 3 inline styles |
| `includes/frontend/class-hl-frontend-my-coaching.php` | Modify | Extract 43-line `<style>` block, replace 3 inline styles |
| `assets/css/frontend.css` | Modify (append) | Add extracted coach CSS using design tokens |

### Session 4 — Program & Navigation Pages
| File | Action | Responsibility |
|------|--------|---------------|
| `includes/frontend/class-hl-frontend-program-page.php` | Modify | Replace 3 inline styles, ensure v2 classes are canonical |
| `includes/frontend/class-hl-frontend-component-page.php` | Modify | Replace 1 inline style |
| `includes/frontend/class-hl-frontend-my-programs.php` | Modify | Replace 2 inline styles |
| `includes/frontend/class-hl-frontend-my-progress.php` | Modify | Replace 1 inline style |
| `includes/frontend/class-hl-frontend-my-cycle.php` | Modify | Replace 38 inline styles (heaviest inline-attr file) |
| `includes/frontend/class-hl-frontend-cycle-workspace.php` | Modify | Replace 6 inline styles |
| `includes/frontend/class-hl-frontend-cycle-dashboard.php` | Modify | Replace 1 inline style |
| `includes/frontend/class-hl-frontend-schedule-session.php` | Modify | Extract 168-line `<style>` block, replace 17 inline styles |
| `includes/frontend/class-hl-frontend-rp-session.php` | Modify | Extract 6-line `<style>` block, replace 2 inline styles |
| `assets/css/frontend.css` | Modify (append) | Add extracted program/nav CSS using design tokens |

### Session 5 — Directory & Profile Pages
| File | Action | Responsibility |
|------|--------|---------------|
| `includes/frontend/class-hl-frontend-user-profile.php` | Modify | Replace 15 inline styles |
| `includes/frontend/class-hl-frontend-team-page.php` | Modify | Replace 2 inline styles |
| `includes/frontend/class-hl-frontend-team-progress.php` | Modify | Replace 1 inline style |
| `includes/frontend/class-hl-frontend-school-page.php` | Modify | Verify uses component classes |
| `includes/frontend/class-hl-frontend-classroom-page.php` | Modify | Replace 18 inline styles |
| `includes/frontend/class-hl-frontend-district-page.php` | Modify | Verify uses component classes |
| `includes/frontend/class-hl-frontend-my-team.php` | Modify | Verify uses component classes |
| `includes/frontend/class-hl-frontend-reports-hub.php` | Modify | Verify uses component classes |
| `includes/frontend/class-hl-frontend-dashboard.php` | Modify | Verify v2 classes, ensure consistency |
| `includes/frontend/class-hl-frontend-docs.php` | Modify | Replace 3 inline styles |
| `includes/frontend/class-hl-frontend-learners.php` | Modify | Replace 2 inline styles |
| `includes/frontend/class-hl-frontend-observations.php` | Modify | Replace 1 inline style |
| `includes/frontend/class-hl-frontend-schools-listing.php` | Verify | Should already be clean |
| `includes/frontend/class-hl-frontend-districts-listing.php` | Verify | Should already be clean |
| `includes/frontend/class-hl-frontend-classrooms-listing.php` | Verify | Should already be clean |
| `includes/frontend/class-hl-frontend-institutions-listing.php` | Verify | Should already be clean |
| `includes/frontend/class-hl-frontend-cycles-listing.php` | Verify | Should already be clean |
| `includes/frontend/class-hl-frontend-pathways-listing.php` | Verify | Should already be clean |
| `includes/frontend/class-hl-frontend-teacher-assessment.php` | Verify | Should already be clean |
| `assets/css/frontend.css` | Modify (append) | Add extracted directory/profile CSS using design tokens |

---

## SESSION 1: Foundation (CSS + Sidebar + Layout Shell)

### Task 1.1: Consolidate Design Tokens

**Files:**
- Modify: `assets/css/frontend.css:1-100` (`:root` block)

- [ ] **Step 1: Rewrite the `:root` block**

Replace lines 1-100 of `frontend.css` with the consolidated token set from the spec (Section 1). This means:

1. Keep the file header comment (lines 1-6)
2. Replace the entire `:root { ... }` block (lines 8-100) with the canonical tokens from the spec
3. The new `:root` block should contain these groups in order:
   - Primary (Navy): `--hl-primary`, `--hl-primary-light`, `--hl-primary-rgb`
   - Accent (Green): `--hl-accent`, `--hl-accent-dark`, `--hl-accent-darker`
   - Interactive (Indigo): `--hl-interactive`, `--hl-interactive-dark`, `--hl-interactive-light`, `--hl-interactive-bg`, `--hl-interactive-border`
   - Secondary (Blue): `--hl-secondary`, `--hl-secondary-dark`
   - Warning/Error: `--hl-warning`, `--hl-error`, `--hl-error-dark`
   - Text Scale: `--hl-text`, `--hl-text-heading`, `--hl-text-secondary`, `--hl-text-muted`
   - Surfaces: `--hl-surface`, `--hl-bg`, `--hl-bg-subtle`, `--hl-bg-card`, `--hl-bg-hover`
   - Borders: `--hl-border`, `--hl-border-light`, `--hl-border-medium`
   - Radius: `--hl-radius` (16px), `--hl-radius-sm` (12px), `--hl-radius-xs` (8px), `--hl-radius-pill` (100px)
   - Shadows: `--hl-shadow`, `--hl-shadow-md`, `--hl-shadow-lg`, `--hl-shadow-hover`
   - Transitions: `--hl-transition`, `--hl-transition-slow`
   - Status Colors: active, complete, progress, paused, draft, archived (bg + text pairs)
   - Typography: `--hl-font` (Inter stack)

4. **Remove** the separate "CRM Palette" section (old lines 73-78) and "Calm Professional Palette" section (old lines 80-97). Their useful values are already absorbed into the tokens above.

5. **Add backward compatibility aliases** after the main tokens block for any code currently referencing old names:

```css
/* Backward compat aliases — remove after full migration */
--hl-indigo:        var(--hl-interactive);
--hl-indigo-dark:   var(--hl-interactive-dark);
--hl-indigo-light:  var(--hl-interactive-light);
--hl-indigo-bg:     var(--hl-interactive-bg);
--hl-indigo-border: var(--hl-interactive-border);
```

The exact token values are in the spec Section 1. Copy them exactly.

- [ ] **Step 2: Verify no broken references**

Search the entire codebase for any CSS variable references that might break:

```bash
grep -r "var(--hl-" assets/css/ includes/frontend/ --include="*.css" --include="*.php" | grep -v "var(--hl-a-" | sort -u
```

Check that every referenced variable is defined in the new `:root`. The aliases in Step 1 should cover `--hl-indigo-*` and `--hl-slate-*` references.

For any `--hl-slate-*` references, add aliases too:

```css
--hl-slate-50:  #f8fafc;
--hl-slate-100: #f1f5f9;
/* ... etc, keep as direct values since they're used as raw colors */
```

- [ ] **Step 3: Commit**

```bash
git add assets/css/frontend.css
git commit -m "style: consolidate design tokens into single canonical palette"
```

---

### Task 1.2: Global Base Layer — BuddyBoss Overrides

**Files:**
- Modify: `assets/css/frontend.css` (append after the existing Section 1 "HIDE BUDDYBOSS THEME PAGE TITLE")

- [ ] **Step 1: Add site-wide font and BB override layer**

Append a new CSS section after the existing BB title-hiding section (after line ~128). This section forces Inter globally and neutralizes BuddyBoss style bleeding:

```css
/* =====================================================
   1b. GLOBAL FONT & BUDDYBOSS OVERRIDES
   Force Inter everywhere. Neutralize BB theme styles
   that bleed into HL Core page content.
   ===================================================== */

/* Global Inter — all pages are HL Core now */
body {
    font-family: var(--hl-font) !important;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* BB theme resets inside HL containers */
body.buddyboss-theme .entry-content [class*="hl-"],
body.buddyboss-theme .entry-content [class*="hlap-"],
body.buddyboss-theme .entry-content [class*="hlcd-"],
body.buddyboss-theme .entry-content [class*="hlcm-"],
body.buddyboss-theme .entry-content [class*="hlcr-"],
body.buddyboss-theme .entry-content [class*="hlca-"],
body.buddyboss-theme .entry-content [class*="hlch-"],
body.buddyboss-theme .entry-content [class*="hlmc-"],
body.buddyboss-theme .entry-content [class*="hlrn-"],
body.buddyboss-theme .entry-content [class*="hlss-"],
body.buddyboss-theme .entry-content [class*="hlup-"] {
    font-family: var(--hl-font) !important;
    color: var(--hl-text);
    line-height: 1.6;
}

/* BB link color override */
body.buddyboss-theme .entry-content [class*="hl-"] a:not(.hl-btn):not([class*="hl-btn"]),
body.buddyboss-theme .entry-content [class*="hl"] a:not(.hl-btn):not([class*="hl-btn"]) {
    color: var(--hl-interactive);
    transition: var(--hl-transition);
}
body.buddyboss-theme .entry-content [class*="hl-"] a:not(.hl-btn):hover {
    color: var(--hl-interactive-dark);
}

/* BB heading overrides */
body.buddyboss-theme .entry-content [class*="hl-"] h1,
body.buddyboss-theme .entry-content [class*="hl-"] h2,
body.buddyboss-theme .entry-content [class*="hl-"] h3,
body.buddyboss-theme .entry-content [class*="hl-"] h4 {
    font-family: var(--hl-font) !important;
    color: var(--hl-text-heading);
    font-weight: 700;
    line-height: 1.3;
}

/* BB form element overrides */
body.buddyboss-theme .entry-content [class*="hl-"] input[type="text"],
body.buddyboss-theme .entry-content [class*="hl-"] input[type="email"],
body.buddyboss-theme .entry-content [class*="hl-"] input[type="number"],
body.buddyboss-theme .entry-content [class*="hl-"] input[type="search"],
body.buddyboss-theme .entry-content [class*="hl-"] input[type="date"],
body.buddyboss-theme .entry-content [class*="hl-"] select,
body.buddyboss-theme .entry-content [class*="hl-"] textarea {
    font-family: var(--hl-font) !important;
    border: 1px solid var(--hl-border) !important;
    border-radius: var(--hl-radius-xs) !important;
    padding: 10px 14px !important;
    font-size: 14px !important;
    color: var(--hl-text) !important;
    background: var(--hl-surface) !important;
    transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
    outline: none !important;
}
body.buddyboss-theme .entry-content [class*="hl-"] input:focus,
body.buddyboss-theme .entry-content [class*="hl-"] select:focus,
body.buddyboss-theme .entry-content [class*="hl-"] textarea:focus {
    border-color: var(--hl-interactive) !important;
    box-shadow: 0 0 0 3px var(--hl-interactive-bg) !important;
}

/* BB table overrides */
body.buddyboss-theme .entry-content [class*="hl-"] table {
    border-collapse: collapse;
    width: 100%;
}
body.buddyboss-theme .entry-content [class*="hl-"] table th {
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
body.buddyboss-theme .entry-content [class*="hl-"] table td {
    padding: 12px 14px;
    font-size: 14px;
    color: var(--hl-text);
    border-bottom: 1px solid var(--hl-border-light);
}
body.buddyboss-theme .entry-content [class*="hl-"] table tr:hover td {
    background: var(--hl-bg-subtle);
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "style: add global font and BuddyBoss override layer"
```

---

### Task 1.3: Component Library CSS

**Files:**
- Modify: `assets/css/frontend.css` (append at END of file)

- [ ] **Step 1: Add the component library section**

Append a new major section at the END of `frontend.css` (after the User Profile section, which currently ends around line 6334). This is the reusable component library that all pages will reference. Build every component class shown in the reference mockup (`.superpowers/brainstorm/6835-1775065694/content/design-system-showcase.html`).

The section must include these components (refer to the mockup HTML for exact styling):

**Cards:**
- `.hl-card` — white surface, 1px border `var(--hl-border)`, `border-radius: var(--hl-radius-sm)`, padding 20px, `box-shadow: var(--hl-shadow)`
- `.hl-card-label` — uppercase muted label: `font-size: 10px; font-weight: 600; color: var(--hl-text-muted); letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 8px`

**Buttons:**
- `.hl-btn` — base: `display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border-radius: var(--hl-radius-xs); font-size: 14px; font-weight: 600; font-family: var(--hl-font); cursor: pointer; border: none; transition: var(--hl-transition)`
- `.hl-btn--accent` — `background: var(--hl-accent); color: #fff` / hover: `var(--hl-accent-dark)`
- `.hl-btn--interactive` — `background: var(--hl-interactive); color: #fff` / hover: `var(--hl-interactive-dark)`
- `.hl-btn--outline` — `background: transparent; border: 1px solid var(--hl-border); color: var(--hl-text-secondary)` / hover: `border-color: var(--hl-interactive); color: var(--hl-interactive)`
- `.hl-btn--sm` — `padding: 6px 14px; font-size: 12px`
- `.hl-btn--danger` — `background: var(--hl-error); color: #fff` / hover: `var(--hl-error-dark)`

**Badges:**
- `.hl-badge` — `display: inline-block; padding: 3px 10px; border-radius: var(--hl-radius-pill); font-size: 11px; font-weight: 600`
- `.hl-badge--success` — `background: var(--hl-status-active-bg); color: var(--hl-status-active-text)`
- `.hl-badge--warning` — `background: var(--hl-status-paused-bg); color: var(--hl-status-paused-text)`
- `.hl-badge--error` — `background: var(--hl-status-archived-bg); color: var(--hl-status-archived-text)`
- `.hl-badge--info` — `background: var(--hl-status-progress-bg); color: var(--hl-status-progress-text)`
- `.hl-badge--draft` — `background: var(--hl-status-draft-bg); color: var(--hl-status-draft-text)`

**Pills/Tags:**
- `.hl-pill` — `display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: var(--hl-radius-pill); font-size: 12px; font-weight: 600`
- `.hl-pill--interactive` — `background: var(--hl-interactive); color: #fff`
- `.hl-pill--primary` — `background: var(--hl-primary); color: #fff`
- `.hl-pill--outline` — `background: transparent; border: 1.5px dashed var(--hl-border); color: var(--hl-text-secondary); cursor: pointer` / hover: `border-color: var(--hl-interactive); color: var(--hl-interactive)`

**Tabs:**
- `.hl-tabs-bar` — `display: flex; gap: 0; border-bottom: 2px solid var(--hl-border-light); margin-bottom: 24px`
- `.hl-tabs-bar__tab` — `padding: 10px 20px; font-size: 14px; font-weight: 500; color: var(--hl-text-secondary); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: var(--hl-transition)`
- `.hl-tabs-bar__tab:hover` — `color: var(--hl-text-heading)`
- `.hl-tabs-bar__tab--active` — `color: var(--hl-interactive); border-bottom-color: var(--hl-interactive); font-weight: 600`

**Hero:**
- `.hl-hero` — `background: var(--hl-primary); border-radius: var(--hl-radius); padding: 28px 32px; margin-bottom: 24px; position: relative; overflow: hidden`
- `.hl-hero::after` — decorative circle: `position: absolute; right: -40px; top: -40px; width: 180px; height: 180px; border-radius: 50%; background: rgba(255,255,255,0.03)`
- `.hl-hero__tag` — green badge: `display: inline-block; background: var(--hl-accent); color: #fff; font-size: 10px; font-weight: 700; padding: 4px 12px; border-radius: var(--hl-radius-pill); letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 12px`
- `.hl-hero__title` — `color: #fff; font-size: 26px; font-weight: 800; margin-bottom: 6px`
- `.hl-hero__subtitle` — `color: rgba(255,255,255,0.65); font-size: 14px`

**Meta Bar:**
- `.hl-meta-bar` — `display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap`
- `.hl-meta-item` — `display: flex; align-items: center; gap: 10px; background: var(--hl-surface); border: 1px solid var(--hl-border); border-radius: var(--hl-radius-sm); padding: 12px 16px; flex: 1; min-width: 160px`
- `.hl-meta-item__icon` — `width: 36px; height: 36px; border-radius: 8px; background: var(--hl-bg); display: flex; align-items: center; justify-content: center; color: var(--hl-text-secondary); font-size: 16px`
- `.hl-meta-item__label` — same style as `.hl-card-label`
- `.hl-meta-item__value` — `font-size: 13px; font-weight: 600; color: var(--hl-text-heading); margin-top: 1px`

**Tables:**
- `.hl-table` — `width: 100%; border-collapse: collapse`
- `.hl-table th` — same as the BB table override styles (already defined above, but standalone class for non-BB contexts)
- `.hl-table td` — same pattern
- `.hl-table tr:hover td` — `background: var(--hl-bg-subtle)`
- `.hl-table__link` — `color: var(--hl-interactive); font-weight: 500; text-decoration: none`

**Progress:**
- `.hl-progress` — `height: 6px; background: var(--hl-border-light); border-radius: 3px; overflow: hidden`
- `.hl-progress__fill` — `height: 100%; border-radius: 3px; transition: width 0.3s ease`
- `.hl-progress__fill--accent` — `background: var(--hl-accent)`
- `.hl-progress__fill--interactive` — `background: var(--hl-interactive)`
- `.hl-progress__fill--warning` — `background: var(--hl-warning)`

**Rating Circles:**
- `.hl-rating` — `display: flex; gap: 8px; align-items: center; justify-content: center; margin: 12px 0 4px`
- `.hl-rating__circle` — `width: 40px; height: 40px; border-radius: 50%; border: 2px solid var(--hl-border); display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; color: var(--hl-text-secondary); cursor: pointer; transition: var(--hl-transition)`
- `.hl-rating__circle:hover` — `border-color: var(--hl-interactive); color: var(--hl-interactive)`
- `.hl-rating__circle--selected` — `background: var(--hl-interactive); border-color: var(--hl-interactive); color: #fff`

**Breadcrumb:**
- `.hl-breadcrumb` — `font-size: 13px; color: var(--hl-text-secondary); margin-bottom: 16px`
- `.hl-breadcrumb a` — `color: var(--hl-interactive); text-decoration: none`
- `.hl-breadcrumb a:hover` — `text-decoration: underline`

**Section Divider:**
- `.hl-section-divider` — `display: flex; align-items: center; gap: 12px; margin: 24px 0 16px; font-size: 15px; font-weight: 700; color: var(--hl-text-heading)`
- `.hl-section-divider__num` — `width: 28px; height: 28px; border-radius: 8px; background: var(--hl-interactive-bg); color: var(--hl-interactive); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700`
- `.hl-section-divider::after` — `content: ''; flex: 1; height: 1px; background: var(--hl-border)`

**Empty State:**
- `.hl-empty` — `text-align: center; padding: 48px 24px; color: var(--hl-text-muted); font-size: 14px`

**Notice (update existing):**
- `.hl-notice` — ensure it uses tokens: `border-radius: var(--hl-radius-xs); padding: 14px 18px; margin-bottom: 16px; font-size: 14px`

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "style: add reusable component library (cards, buttons, badges, tabs, hero, tables, pills, progress)"
```

---

### Task 1.4: Sidebar CSS + Layout Shell

**Files:**
- Modify: `assets/css/frontend.css` (append)

- [ ] **Step 1: Add sidebar and layout CSS**

Append sidebar component CSS and layout grid. Reference the mockup's sidebar styling.

```css
/* =====================================================
   SIDEBAR — HL CORE CUSTOM SIDEBAR
   Replaces BuddyBoss BuddyPanel for all HL pages.
   ===================================================== */

/* Hide BB sidebar when HL sidebar is present */
body.hl-has-sidebar .buddypanel,
body.hl-has-sidebar #buddypress-sidebar,
body.hl-has-sidebar .bb-mobile-panel-wrapper {
    display: none !important;
}

/* Layout grid: sidebar + content */
body.hl-has-sidebar .site {
    display: grid !important;
    grid-template-columns: 240px 1fr !important;
    grid-template-rows: auto 1fr !important;
    min-height: 100vh !important;
}
body.hl-has-sidebar .site > header,
body.hl-has-sidebar .site > .site-header-wrapper {
    grid-column: 1 / -1;
}
body.hl-has-sidebar #content,
body.hl-has-sidebar .site-content {
    grid-column: 2 !important;
    min-width: 0;
}
body.hl-has-sidebar .bb-grid,
body.hl-has-sidebar .bb-content {
    max-width: 100% !important;
    padding: 0 !important;
}

/* Sidebar base */
.hl-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 240px;
    height: 100vh;
    background: var(--hl-primary);
    display: flex;
    flex-direction: column;
    z-index: 1000;
    overflow-y: auto;
    overflow-x: hidden;
}

/* Brand */
.hl-sidebar__brand {
    padding: 20px 20px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    margin-bottom: 8px;
}
.hl-sidebar__logo {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--hl-accent);
    font-weight: 800;
    font-size: 15px;
    margin-bottom: 10px;
}
.hl-sidebar__title {
    color: #fff;
    font-size: 15px;
    font-weight: 700;
}
.hl-sidebar__subtitle {
    color: rgba(255,255,255,0.4);
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    margin-top: 2px;
}

/* Nav section */
.hl-sidebar__nav {
    padding: 4px 12px;
    flex: 1;
}
.hl-sidebar__section-label {
    color: rgba(255,255,255,0.3);
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    padding: 12px 8px 4px;
}

/* Nav items */
.hl-sidebar__item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    color: rgba(255,255,255,0.6);
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.15s ease;
    cursor: pointer;
}
.hl-sidebar__item:hover {
    background: rgba(255,255,255,0.06);
    color: rgba(255,255,255,0.85);
    text-decoration: none;
}
.hl-sidebar__item--active {
    background: rgba(255,255,255,0.1);
    color: #fff;
}
.hl-sidebar__icon {
    width: 20px;
    text-align: center;
    font-size: 18px;
}
.hl-sidebar__badge {
    margin-left: auto;
    background: var(--hl-accent);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: var(--hl-radius-pill);
    min-width: 20px;
    text-align: center;
}

/* Footer */
.hl-sidebar__footer {
    padding: 12px;
    border-top: 1px solid rgba(255,255,255,0.08);
}

/* Top bar */
.hl-topbar {
    background: var(--hl-surface);
    border-bottom: 1px solid var(--hl-border);
    padding: 12px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.hl-topbar__user {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--hl-text-secondary);
}
.hl-topbar__avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--hl-interactive-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--hl-interactive);
    font-weight: 700;
    font-size: 12px;
}

/* Content area padding when in sidebar layout */
body.hl-has-sidebar .entry-content {
    padding: 24px 32px !important;
    background: var(--hl-bg);
    min-height: calc(100vh - 60px);
}

/* Responsive: collapse sidebar on small screens */
@media (max-width: 1024px) {
    body.hl-has-sidebar .site {
        grid-template-columns: 1fr !important;
    }
    .hl-sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    .hl-sidebar.hl-sidebar--open {
        transform: translateX(0);
    }
    body.hl-has-sidebar #content,
    body.hl-has-sidebar .site-content {
        grid-column: 1 !important;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "style: add sidebar component and layout shell CSS"
```

---

### Task 1.5: Sidebar PHP Renderer

**Files:**
- Modify: `includes/integrations/class-hl-buddyboss-integration.php`

- [ ] **Step 1: Add body_class filter**

In the constructor (around line 87 area, inside the `if ($this->is_active())` block), add:

```php
// Body class for sidebar layout.
add_filter('body_class', array($this, 'add_sidebar_body_class'));
```

Add the method after `render_custom_css()`:

```php
/**
 * Add hl-has-sidebar class to body when the HL sidebar should render.
 *
 * @param array $classes Body classes.
 * @return array
 */
public function add_sidebar_body_class($classes) {
    if (!is_user_logged_in()) {
        return $classes;
    }

    $menu_items = $this->get_menu_items_for_current_user();
    if (!empty($menu_items)) {
        $classes[] = 'hl-has-sidebar';
    }

    return $classes;
}
```

- [ ] **Step 2: Add the sidebar render method**

Add a new method `render_hl_sidebar()` that outputs the sidebar HTML. Hook it to `wp_footer` with priority 5 (before the JS fallback at priority 99):

In the constructor, add:
```php
// 4. HL Custom Sidebar — renders the dark navy sidebar.
add_action('wp_footer', array($this, 'render_hl_sidebar'), 5);
```

The method:

```php
/**
 * Render the HL Core custom sidebar.
 *
 * Replaces the BuddyBoss BuddyPanel with a custom dark navy sidebar.
 * Menu items come from the existing get_menu_items_for_current_user().
 */
public function render_hl_sidebar() {
    if (!is_user_logged_in()) {
        return;
    }

    $menu_items = $this->get_menu_items_for_current_user();
    if (empty($menu_items)) {
        return;
    }

    $current_url = trailingslashit(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
    $user = wp_get_current_user();
    $initials = '';
    if ($user->first_name) {
        $initials .= strtoupper(substr($user->first_name, 0, 1));
    }
    if ($user->last_name) {
        $initials .= strtoupper(substr($user->last_name, 0, 1));
    }
    if (!$initials) {
        $initials = strtoupper(substr($user->display_name, 0, 2));
    }

    ?>
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
    <script>
    (function() {
        // Move sidebar into correct DOM position (before site-content).
        var sidebar = document.getElementById('hl-sidebar');
        var site = document.querySelector('.site');
        if (sidebar && site) {
            var content = site.querySelector('#content, .site-content');
            if (content) {
                site.insertBefore(sidebar, content);
                sidebar.style.position = 'sticky';
                sidebar.style.top = '0';
                sidebar.style.alignSelf = 'start';
            }
        }
    })();
    </script>
    <?php
}
```

- [ ] **Step 3: Update render_custom_css to include HL sidebar styles**

In `render_custom_css()` (line 140), add dashicons styling for the HL sidebar items alongside the existing BB panel styles:

```css
/* HL Sidebar dashicons */
.hl-sidebar .dashicons {
    font-size: 20px !important;
    width: 20px !important;
    height: 20px !important;
    line-height: 20px !important;
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/integrations/class-hl-buddyboss-integration.php
git commit -m "feat: add custom HL sidebar renderer replacing BuddyBoss BuddyPanel"
```

---

### Task 1.6: Deploy & Verify Foundation

**Files:** None (deployment task)

- [ ] **Step 1: Deploy to test server**

Read `.claude/skills/deploy.md` for deployment instructions. Deploy the current branch to the test server.

- [ ] **Step 2: Verify on test server**

Check these pages in the browser (use Chrome DevTools MCP or Playwright):
1. Dashboard (`/dashboard-3/`) — should show new sidebar, Inter font, correct colors
2. My Programs (`/my-programs/`) — sidebar present, font correct
3. Any form page (Action Plan, Classroom Visit) — form inputs should have new border/focus styles from the BB override layer
4. Any coach page (Coach Dashboard) — sidebar present, font correct

Expected: All pages show the dark navy sidebar. All text is in Inter. Form inputs have consistent border/focus styling. No visual breakage.

- [ ] **Step 3: Fix any issues found during verification**

If any page breaks, fix and recommit before proceeding to Sessions 2-5.

- [ ] **Step 4: Push and announce ready for parallel sessions**

```bash
git push origin main
```

Sessions 2-5 can now begin in parallel.

---

## SESSION 2: Forms & Instruments

> **Prerequisite:** Session 1 must be merged to main.
> **Scope:** 7 files — action-plan, classroom-visit, rp-notes, self-reflection, child-assessment, instrument-renderer, teacher-assessment-renderer
> **CSS Section Label:** All CSS appended to `frontend.css` must be under the comment `/* === FORMS & INSTRUMENTS (Session 2) === */`

### Task 2.1: Extract and Migrate Form Page Styles

For each of the 7 files in this session:

1. **Read the file** and identify every `<style>...</style>` block and every `style="..."` attribute
2. **Extract** the CSS from `<style>` blocks into `frontend.css` under the session section header
3. **Replace** hardcoded hex colors with design token `var(--hl-*)` references. Color mapping:
   - Any white (`#fff`, `#ffffff`, `#FFFFFF`) → `var(--hl-surface)`
   - Any near-black text (`#1a2b47`, `#1A2B47`, `#111827`, `#1e293b`) → `var(--hl-text-heading)`
   - Any body text gray (`#374151`, `#4b5563`) → `var(--hl-text)`
   - Any secondary gray (`#6b7280`, `#64748b`) → `var(--hl-text-secondary)`
   - Any muted gray (`#9ca3af`, `#94a3b8`) → `var(--hl-text-muted)`
   - Any green (`#2ecc71`, `#10b981`, `#059669`, `#047857`) → `var(--hl-accent)` or `var(--hl-accent-dark)`
   - Any indigo/purple (`#6366f1`, `#4f46e5`, `#8b5cf6`) → `var(--hl-interactive)` or variants
   - Any blue (`#2c7be5`, `#3b82f6`, `#1d4ed8`) → `var(--hl-secondary)` or `var(--hl-interactive)`
   - Any border gray (`#e5e7eb`, `#e8eaf0`, `#d1d5db`) → `var(--hl-border)` or `var(--hl-border-medium)`
   - Any light bg (`#f3f4f6`, `#f4f5f7`, `#f9fafb`) → `var(--hl-bg)` or `var(--hl-bg-subtle)`
   - Any red (`#ef4444`, `#dc2626`) → `var(--hl-error)` or `var(--hl-error-dark)`
   - Any yellow/amber (`#f59e0b`, `#d97706`) → `var(--hl-warning)`
4. **Replace** `style="..."` inline attributes in PHP with appropriate component classes (`.hl-card`, `.hl-btn--*`, `.hl-badge--*`, `.hl-pill--*`, `.hl-hero`, `.hl-tabs-bar`, etc.)
5. **Delete** the `<style>` block from the PHP file entirely
6. **Test** that the page still renders correctly

**Priority order** (largest inline CSS first):
1. `teacher-assessment-renderer.php` (527 lines)
2. `instrument-renderer.php` (474 lines)
3. `rp-notes.php` (139 lines)
4. `classroom-visit.php` (92 lines)
5. `action-plan.php` (80 lines)
6. `child-assessment.php` (51 lines)
7. `self-reflection.php` (4 inline attrs only)

**Commit after each file** with message format: `style(forms): extract inline CSS from {filename} to frontend.css`

---

## SESSION 3: Coach Pages

> **Prerequisite:** Session 1 must be merged to main.
> **Scope:** 7 files — coach-dashboard, coach-mentors, coach-mentor-detail, coach-reports, coach-availability, coaching-hub, my-coaching
> **CSS Section Label:** `/* === COACH PAGES (Session 3) === */`

### Task 3.1: Extract and Migrate Coach Page Styles

Same process as Session 2. For each of the 7 files:

1. Read, identify all `<style>` blocks and `style="..."` attributes
2. Extract CSS to `frontend.css` under session header, replace colors with tokens
3. Replace inline `style=` with component classes
4. Delete `<style>` blocks from PHP
5. Commit after each file

**Priority order:**
1. `coach-mentor-detail.php` (530 lines — THE biggest file, 2 style blocks)
2. `coach-reports.php` (101 lines)
3. `coach-availability.php` (75 lines)
4. `coach-dashboard.php` (71 lines)
5. `coach-mentors.php` (68 lines)
6. `my-coaching.php` (43 lines)
7. `coaching-hub.php` (15 lines)

**Commit format:** `style(coach): extract inline CSS from {filename} to frontend.css`

---

## SESSION 4: Program & Navigation Pages

> **Prerequisite:** Session 1 must be merged to main.
> **Scope:** 9 files — program-page, component-page, my-programs, my-progress, my-cycle, cycle-workspace, cycle-dashboard, schedule-session, rp-session
> **CSS Section Label:** `/* === PROGRAM & NAVIGATION PAGES (Session 4) === */`

### Task 4.1: Extract and Migrate Program Page Styles

Same process. For each of the 9 files:

1. Read, identify all `<style>` blocks and `style="..."` attributes
2. Extract CSS to `frontend.css` under session header, replace colors with tokens
3. Replace inline `style=` with component classes
4. Delete `<style>` blocks from PHP
5. Commit after each file

**Priority order:**
1. `schedule-session.php` (168 lines CSS + 17 inline attrs)
2. `my-cycle.php` (0 style blocks but 38 inline attrs — most inline attributes of any file)
3. `cycle-workspace.php` (6 inline attrs)
4. `program-page.php` (3 inline attrs)
5. `my-programs.php` (2 inline attrs)
6. `rp-session.php` (6 lines CSS + 2 inline attrs)
7. `component-page.php` (1 inline attr)
8. `my-progress.php` (1 inline attr)
9. `cycle-dashboard.php` (1 inline attr)

**Additional task for this session:** Review the existing V2 CSS sections in `frontend.css` (Program Page V2 at ~line 2773, Dashboard V2 at ~line 3500, Cycle Workspace V2 at ~line 3720). Determine if they can be replaced by the component library classes. If so, mark them as deprecated with a comment but do NOT delete them yet — Session 6 will handle cleanup.

**Commit format:** `style(program): extract inline CSS from {filename} to frontend.css`

---

## SESSION 5: Directory & Profile Pages

> **Prerequisite:** Session 1 must be merged to main.
> **Scope:** 19 files — user-profile, team-page, team-progress, school-page, classroom-page, district-page, my-team, reports-hub, dashboard, docs, learners, observations, and 7 listing/clean files
> **CSS Section Label:** `/* === DIRECTORY & PROFILE PAGES (Session 5) === */`

### Task 5.1: Migrate Pages With Inline Styles

For each file with inline `style=` attributes:

1. `classroom-page.php` (18 inline attrs)
2. `user-profile.php` (15 inline attrs)
3. `docs.php` (3 inline attrs)
4. `team-page.php` (2 inline attrs)
5. `learners.php` (2 inline attrs)
6. `observations.php` (1 inline attr)
7. `team-progress.php` (1 inline attr)

Same extraction process: replace `style=` with component classes, add any page-specific CSS to `frontend.css`.

### Task 5.2: Verify Clean Files

For each of these files that have no inline CSS, verify they're using the design system properly:

1. `classrooms-listing.php`
2. `cycles-listing.php`
3. `dashboard.php`
4. `district-page.php`
5. `districts-listing.php`
6. `institutions-listing.php`
7. `my-team.php`
8. `pathways-listing.php`
9. `reports-hub.php`
10. `school-page.php`
11. `schools-listing.php`
12. `teacher-assessment.php`

For each: read the file, check that its wrapper class is in the `.hl-dashboard` family (so it picks up global base layer styling). If any file uses custom wrapper classes that don't match the BB override selectors, add those selectors to the override layer.

**Commit format:** `style(directory): extract inline CSS from {filename} to frontend.css` or `style(directory): verify {filename} uses design system`

---

## SESSION 6: Review & Polish

> **Prerequisite:** Sessions 2-5 must all be merged to main.

### Task 6.1: Visual Review of All Pages

Deploy to test server and visually review every frontend page. Check:
1. Sidebar renders correctly on every page
2. Inter font is used everywhere
3. Form inputs have consistent focus styles
4. Tabs use underline style with indigo active
5. Buttons match `.hl-btn` variants
6. Badges match `.hl-badge` variants
7. Hero banners match `.hl-hero` pattern
8. Tables match `.hl-table` pattern
9. No orphaned hardcoded colors visible

### Task 6.2: Dead CSS Cleanup

Review `frontend.css` for sections that are now superseded by the component library:
- Old program page styles (Section 29, if replaced by V2/component library)
- Old dashboard styles (Section "DASHBOARD HOME")
- V2 sections (Program Page V2, Dashboard V2, Cycle Workspace V2) if fully replaced
- Any duplicate style definitions between sessions

Remove dead CSS, commit: `style: remove dead CSS sections replaced by design system`

### Task 6.3: Final Grep Audit

```bash
# Find any remaining hardcoded colors in PHP frontend files
grep -rn 'style="' includes/frontend/class-hl-frontend-*.php | wc -l
# Target: 0

# Find any remaining <style> blocks in PHP frontend files
grep -rn '<style' includes/frontend/class-hl-frontend-*.php | wc -l
# Target: 0

# Find any remaining <style> blocks in renderer files
grep -rn '<style' includes/frontend/class-hl-instrument-renderer.php includes/frontend/class-hl-teacher-assessment-renderer.php | wc -l
# Target: 0
```

### Task 6.4: Update STATUS.md and README.md

- STATUS.md: Check off `[ ] Frontend CSS redesign (modernize all 25+ shortcode pages)` → `[x]`
- README.md: Update "What's Implemented" section with design system details
- Commit both files

---

## Success Criteria (from spec)

1. Every frontend page uses Inter font
2. Every page has the dark navy sidebar with consistent menu items
3. Every form uses the same input, select, textarea, button styles
4. Every tab bar uses underline tabs with indigo active state
5. Every hero banner uses the same navy + green tag pattern
6. Every status badge uses `.hl-badge--*` variants
7. Every tag/pill uses `.hl-pill--*` variants
8. Zero `<style>` blocks remain in PHP files
9. Zero hardcoded hex colors in PHP `style=` attributes
10. All pages use only `var(--hl-*)` tokens — changing a token value changes the entire site
