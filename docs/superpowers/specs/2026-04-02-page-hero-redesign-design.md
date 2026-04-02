# Design System Consistency Overhaul — Design Spec

## Goal

Fix all design system inconsistencies found in the 5-agent audit. This is a CSS-focused overhaul — no new features, no data changes, no JS changes. Three workstreams:

1. **Page Hero Redesign** — Replace 3 custom page headers with one reusable `.hl-page-hero` component
2. **Hero Gradient Standardization** — Fix 12 hero banners that use hardcoded hex instead of design tokens
3. **Critical Color Fixes + Dead Code Cleanup** — Fix 4 white-on-white bugs, remove 19 lines of dead BB selectors, add missing button CSS

## Workstream 1: Page Hero Redesign

### Problem

Team, Classroom, and School pages use custom `-page-header` components with their own CSS. They cram metadata cards inside the dark hero banner, causing white-on-white text. They don't match the design system hero pattern used by Coach Mentors, Coach Dashboard, Coach Reports, etc.

### Solution: Hero + Meta Bar Separation

1. **`.hl-page-hero`** — dark gradient banner with icon + tag + title + subtitle
2. **`.hl-meta-bar`** — horizontal row of `.hl-meta-item` cards BELOW the hero, on the light background

#### `.hl-page-hero` HTML

```html
<div class="hl-page-hero">
    <div class="hl-page-hero__icon">
        <span class="dashicons dashicons-groups"></span>
    </div>
    <div class="hl-page-hero__text">
        <span class="hl-page-hero__tag">Team</span>
        <h2 class="hl-page-hero__title">Team 01 - Boston - Beginnings</h2>
        <p class="hl-page-hero__subtitle">Beginnings Boston</p>
    </div>
</div>
```

#### `.hl-page-hero` CSS (reuse existing design tokens)

- Background: `linear-gradient(135deg, var(--hl-primary) 0%, var(--hl-primary-light) 100%)`
- Padding: `28px 32px`, border-radius: `var(--hl-radius)`, margin-bottom: `20px`
- Icon: 60x60, `rgba(255,255,255,0.12)` background, `var(--hl-radius)` border-radius
- Tag: `var(--hl-accent)` background, pill shape, 10px uppercase 700
- Title: `color: #fff`, font-size: 26px, font-weight: 800 (matches `.hl-hero__title`)
- Subtitle: `color: rgba(255,255,255,0.65)`, font-size: 14px
- Decorative `::after` circle (same as `.hl-hero`)
- Responsive at 600px: stack icon/text vertically, center text

#### `.hl-meta-bar` CSS

- `display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;`
- Children are existing `.hl-meta-item` components (already in design system) — no new card CSS needed

#### Pages Affected

| Page | File | Tag | Icon | Title | Subtitle | Meta Items |
|------|------|-----|------|-------|----------|------------|
| Team | `class-hl-frontend-team-page.php` | Team | `dashicons-groups` | team name | school name | Cycle, Members, Mentor(s), Avg Completion |
| Classroom | `class-hl-frontend-classroom-page.php` | Classroom | `dashicons-welcome-learn-more` | classroom name | school name | Cycle, Children, Teacher(s), Age Band |
| School | `class-hl-frontend-school-page.php` | School | `dashicons-building` | school name | district name | Cycle, Teachers, Classrooms, Avg Completion |

#### CSS to Delete

- `.hl-team-page-header` and children (~60 lines)
- `.hl-classroom-page-header` and children (~25 lines)
- `.hl-progress-header` / `.hl-my-partnership-header` hero-duplicate children (~40 lines)
- Header-specific `.hl-meta-item strong` color overrides
- Responsive overrides for old headers

---

## Workstream 2: Hero Gradient Standardization

### Problem

12 of 13 hero banners use hardcoded hex values (`#2d5f8a`, `#1e3a5f`, `#2d5a88`) instead of design tokens. This prevents re-theming and creates visual inconsistency.

### Solution

Replace all hardcoded gradient hex values with the canonical token pair:

```css
background: linear-gradient(135deg, var(--hl-primary) 0%, var(--hl-primary-light) 100%);
```

Also standardize icon background opacity to `rgba(255,255,255,0.12)` consistently (currently varies between 0.12, 0.15, and 0.25).

#### Heroes to Fix (CSS only — no PHP changes)

| Hero Class | File (CSS) | Current Gradient | Fix |
|------------|------------|------------------|-----|
| `.hlap-hero` | Action Plan section | `var(--hl-primary)` to `#2d5f8a` | Replace `#2d5f8a` with `var(--hl-primary-light)` |
| `.hlcv-hero` | Classroom Visit section | `var(--hl-primary)` to `#2d5f8a` | Same |
| `.hlca-hero` | Coach Availability section | hardcoded gradient | Replace with token pair |
| `.hlcd-hero` | Coach Dashboard section | mostly tokens, icon opacity 0.25 | Fix icon opacity to 0.12, padding to 28px 32px |
| `.hlcm-hero` | Coach Mentors section | tokens, icon opacity 0.12 | Already correct — reference implementation |
| `.hlcmd-hero` | Coach Mentor Detail section | icon opacity 0.25 | Fix icon opacity to 0.12 |
| `.hlcr-hero` | Coach Reports section | tokens, icon opacity 0.12 | Already correct |
| `.hl-cw-hero` | Cycle Workspace section | `var(--hl-slate-900)` + `#1e3a5f` + `#2d5a88` | Replace with token pair |
| `.hl-pp-hero` | Program Page section | `var(--hl-slate-900)` + hardcoded | Replace with token pair |
| `.hlrn-hero` | RP Notes section | `var(--hl-primary)` to `#2d5f8a` | Replace `#2d5f8a` with `var(--hl-primary-light)` |
| `.hlup-hero` | User Profile section | `#1e3a5f` + `#2d5a88` | Replace with token pair |

---

## Workstream 3: Critical Fixes + Dead Code

### 3a. Color Inheritance Fixes

| Selector | Line | Issue | Fix |
|----------|------|-------|-----|
| `.hl-progress-header .hl-meta-item strong` | ~696 | Dark text on dark bg | Change to `color: var(--hl-text)` (already on white-bg card) |
| `.hl-my-partnership-header .hl-meta-item strong` | ~696 | Same | Same |
| `.hlcd-link-card` | ~7218 | `color: inherit` on white bg inherits white from hero | Add `color: var(--hl-text)` |
| `.hlcd-link-card:hover` | ~7225 | Same | Add `color: var(--hl-text)` |

### 3b. Missing Button CSS

| Class | Used In | Fix |
|-------|---------|-----|
| `.hl-btn-small` | 5 PHP files | Add CSS rule: same as `.hl-btn-sm` (alias) |

### 3c. Dead BB Selectors to Remove

| Selector | Lines | Reason |
|----------|-------|--------|
| `body:has(.hl-program-page-v2) .entry-header/.entry-title` | ~2649-2652 | No `.entry-header` in template |
| `body:has(.hl-dashboard-v2) .entry-header/.entry-title` | ~3376-3379 | Same |
| `article:has(.hlup-wrapper) > .entry-header` | ~4508-4513 | No `<article>` in template |
| `body:has(.hlcmd-wrapper) .entry-header .entry-title` | ~7346-7348 | Same |

Total: ~19 lines of dead code.

---

## What Does NOT Change

- Font family, sizes, weights — all from existing design tokens
- Color palette — all from existing CSS variables
- Tab component (`.hl-tab`) — already aligned
- Table component (`.hl-table`) — already aligned
- Progress bars — already use `.hl-progress-bar`
- Badge styles — already use `.hl-badge`
- Back link (`.hl-back-link`) — already aligned
- Search input (`.hl-search-input`) — already aligned
- Shortcode registration, routing, data queries, services
- JavaScript — zero JS changes

## Out of Scope (Future Work)

These were identified by the audit but are NOT included in this spec:

- Consolidating 7 table class patterns to `.hl-table` (separate effort, touches 8+ files)
- Consolidating 6 progress bar implementations (separate effort)
- Consolidating fragmented badge system (`.hlcm-badge` etc.)
- Removing orphaned button CSS (`.hl-btn--accent`, etc.)
- Replacing 58 inline `style=""` attributes in PHP
- Standardizing grid gap (16px vs 20px)
- Removing 174 `!important` declarations
- CSS file splitting / minification

## Testing

- Visual: check all 3 redesigned pages (Team, Classroom, School) render hero + meta bar correctly
- Visual: check all 11 hero banners have consistent gradient after standardization
- Visual: check Coach Dashboard link cards have readable text
- Responsive: verify `.hl-page-hero` stacks at 600px breakpoint
- Data: verify all meta items show correct values
- Tabs: verify tab switching still works
- Report tab: verify expandable rows still work
- Sidebar/topbar: verify no regressions in shell layout
