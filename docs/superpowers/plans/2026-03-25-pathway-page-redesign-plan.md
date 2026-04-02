# Pathway Page Redesign — "Calm Professional" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the `[hl_program_page]` shortcode output to match the "Calm Professional" v2 mockup — hero banner, two-column layout with sticky sidebar, collapsible info sections, redesigned component cards with course images.

**Architecture:** The PHP renderer (`class-hl-frontend-program-page.php`) outputs new HTML structure. New CSS classes are added to `frontend.css`. No new PHP files — this is a template + CSS overhaul of one existing file. BuddyBoss overrides are scoped to `.hl-program-page` so they don't affect other pages. A small JS snippet handles the collapsible toggle sections.

**Tech Stack:** PHP 7.4+ (WordPress), CSS custom properties, vanilla JS

**Reference mockup:** `.superpowers/brainstorm/14309-1774455429/pathway-full-v2.html`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `assets/css/frontend.css` | Modify (append) | New CSS: design tokens update, program page layout, hero, sidebar, component cards, toggles, BuddyBoss overrides |
| `includes/frontend/class-hl-frontend-program-page.php` | Modify | New HTML structure: hero, two-column grid, sidebar, collapsible sections, redesigned component cards |
| `assets/js/frontend.js` | Modify (append) | Toggle panel JS function (~10 lines) |

---

### Task 1: Update CSS Design Tokens

**Files:**
- Modify: `assets/css/frontend.css:1-78` (`:root` block)

- [ ] **Step 1: Add new design tokens to `:root`**

Add these new tokens below the existing ones (do NOT remove existing tokens — other pages use them):

```css
/* ---- Calm Professional Palette ---- */
--hl-indigo:           #6366f1;
--hl-indigo-dark:      #4f46e5;
--hl-indigo-light:     #818cf8;
--hl-indigo-bg:        #f5f3ff;
--hl-indigo-border:    #c7d2fe;
--hl-violet:           #8b5cf6;
--hl-slate-50:         #f8fafc;
--hl-slate-100:        #f1f5f9;
--hl-slate-200:        #e2e8f0;
--hl-slate-300:        #cbd5e1;
--hl-slate-400:        #94a3b8;
--hl-slate-500:        #64748b;
--hl-slate-600:        #475569;
--hl-slate-700:        #334155;
--hl-slate-800:        #1e293b;
--hl-slate-900:        #0f172a;

/* ---- Typography ---- */
--hl-font:             'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "style: add Calm Professional design tokens to frontend.css"
```

---

### Task 2: Add Program Page CSS — Layout, Hero, and BuddyBoss Overrides

**Files:**
- Modify: `assets/css/frontend.css` (append at END of file, after all existing sections)

- [ ] **Step 1: Add the new program page CSS**

Append the following CSS to `frontend.css`. This is a large block — add it as a new numbered section after the existing program page styles. The old `.hl-program-header`, `.hl-program-details`, etc. styles remain (other pages or legacy might reference them) but are overridden by the more specific `.hl-program-page-v2` selectors.

```css
/* =====================================================
   PROGRAM PAGE V2 — CALM PROFESSIONAL REDESIGN
   Scoped to .hl-program-page-v2 so it doesn't affect
   legacy program page rendering if any exists.
   ===================================================== */

/* ---- BuddyBoss overrides for program page ---- */
.hl-program-page-v2 {
    font-family: var(--hl-font);
    max-width: none;
}

body:has(.hl-program-page-v2) .entry-header,
body:has(.hl-program-page-v2) .entry-title {
    display: none !important;
}

.hl-program-page-v2 h1,
.hl-program-page-v2 h2,
.hl-program-page-v2 h3,
.hl-program-page-v2 h4 {
    font-family: var(--hl-font);
    letter-spacing: -0.01em;
}

/* ---- Page layout ---- */
.hl-program-page-v2 .hl-pp-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 24px;
    align-items: start;
}

.hl-program-page-v2 .hl-pp-main {
    min-width: 0;
}

/* ---- Hero banner ---- */
.hl-pp-hero {
    margin-bottom: 24px;
}

.hl-pp-hero-card {
    border-radius: 14px;
    overflow: hidden;
    background: linear-gradient(135deg, var(--hl-slate-900) 0%, #1e3a5f 55%, #2d5a88 100%);
    display: flex;
    min-height: 160px;
    position: relative;
}

.hl-pp-hero-text {
    flex: 1;
    padding: 28px 32px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    z-index: 1;
}

.hl-pp-hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    border-radius: var(--hl-radius-pill);
    background: rgba(99, 102, 241, 0.2);
    border: 1px solid rgba(99, 102, 241, 0.3);
    color: #a5b4fc;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 10px;
    width: fit-content;
}

.hl-pp-hero-title {
    font-size: 22px;
    font-weight: 800;
    color: #fff !important;
    letter-spacing: -0.03em;
    line-height: 1.2;
    margin: 0 0 6px !important;
}

.hl-pp-hero-subtitle {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.55);
    margin: 0;
}

.hl-pp-hero-image {
    width: 280px;
    flex-shrink: 0;
    position: relative;
    overflow: hidden;
}

.hl-pp-hero-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0.4;
    mix-blend-mode: luminosity;
}

.hl-pp-hero-image::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, var(--hl-slate-900) 0%, transparent 50%);
    z-index: 1;
}

/* ---- About brief (no card wrapper) ---- */
.hl-pp-about {
    font-size: 14px;
    color: var(--hl-slate-500);
    line-height: 1.7;
    margin-bottom: 24px;
}

/* ---- Expandable toggle sections ---- */
.hl-pp-toggles {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.hl-pp-toggle-btn {
    padding: 8px 18px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid var(--hl-slate-200);
    background: #fff;
    color: var(--hl-slate-500);
    cursor: pointer;
    transition: var(--hl-transition);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: var(--hl-font);
}

.hl-pp-toggle-btn:hover {
    border-color: var(--hl-indigo-border);
    color: var(--hl-indigo);
    background: var(--hl-indigo-bg);
}

.hl-pp-toggle-btn.active {
    background: var(--hl-indigo);
    color: #fff;
    border-color: var(--hl-indigo);
}

.hl-pp-panel {
    display: none;
    background: #fff;
    border: 1px solid var(--hl-slate-200);
    border-radius: 12px;
    padding: 24px 28px;
    margin-bottom: 20px;
    animation: hlPanelSlide 0.2s ease;
}

.hl-pp-panel.show {
    display: block;
}

@keyframes hlPanelSlide {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}

.hl-pp-panel h3 {
    font-size: 15px;
    font-weight: 700;
    color: var(--hl-slate-900);
    margin: 0 0 10px;
}

.hl-pp-panel p {
    font-size: 14px;
    color: var(--hl-slate-600);
    line-height: 1.7;
}

.hl-pp-syllabus-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 12px;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 600;
    color: var(--hl-indigo);
    text-decoration: none;
    background: var(--hl-indigo-bg);
    border-radius: 8px;
    transition: var(--hl-transition);
}

.hl-pp-syllabus-link:hover {
    background: #ede9fe;
}

/* ---- Section label ---- */
.hl-pp-section-label {
    font-size: 13px;
    font-weight: 700;
    color: var(--hl-slate-400);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin: 8px 0 14px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--hl-slate-100);
    display: flex;
    align-items: center;
    gap: 8px;
}

.hl-pp-section-count {
    background: var(--hl-slate-100);
    color: var(--hl-slate-500);
    padding: 2px 8px;
    border-radius: var(--hl-radius-pill);
    font-size: 11px;
    font-weight: 600;
}

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "style: add program page v2 layout, hero, and toggle CSS"
```

---

### Task 3: Add Program Page CSS — Sidebar Components

**Files:**
- Modify: `assets/css/frontend.css` (append after Task 2 CSS, at end of file)

- [ ] **Step 1: Add sidebar CSS**

```css
/* ---- Sidebar ---- */
.hl-pp-sidebar {
    position: sticky;
    top: 80px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.hl-pp-sidebar-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid var(--hl-slate-200);
    overflow: hidden;
}

/* Progress combo card */
.hl-pp-progress-combo {
    padding: 18px 20px;
}

.hl-pp-progress-top {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 14px;
}

.hl-pp-ring-mini {
    position: relative;
    width: 52px;
    height: 52px;
    flex-shrink: 0;
}

.hl-pp-ring-mini svg {
    width: 100%;
    height: 100%;
    transform: rotate(-90deg);
}

.hl-pp-ring-mini .ring-bg {
    fill: none;
    stroke: var(--hl-slate-100);
    stroke-width: 6;
}

.hl-pp-ring-mini .ring-fill {
    fill: none;
    stroke: var(--hl-indigo);
    stroke-width: 6;
    stroke-linecap: round;
}

.hl-pp-ring-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 13px;
    font-weight: 800;
    color: var(--hl-slate-900);
}

.hl-pp-progress-label {
    font-size: 13px;
    font-weight: 700;
    color: var(--hl-slate-900);
}

.hl-pp-progress-sub {
    font-size: 12px;
    color: var(--hl-slate-400);
    margin-top: 1px;
}

.hl-pp-bar-full {
    width: 100%;
    height: 6px;
    background: var(--hl-slate-100);
    border-radius: var(--hl-radius-pill);
    overflow: hidden;
}

.hl-pp-bar-fill {
    height: 100%;
    border-radius: var(--hl-radius-pill);
    background: linear-gradient(90deg, var(--hl-indigo), var(--hl-violet));
    transition: width 0.5s ease;
}

/* Compact stats row */
.hl-pp-stats-row {
    display: flex;
    gap: 0;
    border-top: 1px solid var(--hl-slate-100);
    margin: 14px -20px -18px;
}

.hl-pp-stat {
    flex: 1;
    padding: 12px 0;
    text-align: center;
    border-right: 1px solid var(--hl-slate-100);
}

.hl-pp-stat:last-child {
    border-right: none;
}

.hl-pp-stat-num {
    font-size: 18px;
    font-weight: 800;
    color: var(--hl-slate-900);
}

.hl-pp-stat-lbl {
    font-size: 10px;
    color: var(--hl-slate-400);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

/* Details card */
.hl-pp-details {
    padding: 0;
}

.hl-pp-detail-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 18px;
    border-bottom: 1px solid var(--hl-slate-50);
}

.hl-pp-detail-row:last-child {
    border-bottom: none;
}

.hl-pp-detail-icon {
    width: 28px;
    height: 28px;
    border-radius: 7px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    background: var(--hl-slate-50);
    flex-shrink: 0;
}

.hl-pp-detail-label {
    font-size: 11px;
    color: var(--hl-slate-400);
}

.hl-pp-detail-value {
    font-size: 12px;
    font-weight: 600;
    color: var(--hl-slate-900);
}

/* Certificate card */
.hl-pp-cert {
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.hl-pp-cert-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    background: var(--hl-slate-50);
    color: var(--hl-slate-300);
}

.hl-pp-cert-icon.available {
    background: var(--hl-indigo-bg);
    color: #7c3aed;
}

.hl-pp-cert-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--hl-slate-900);
}

.hl-pp-cert-desc {
    font-size: 11px;
    color: var(--hl-slate-400);
}

.hl-pp-cert-btn {
    margin-left: auto;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    background: linear-gradient(135deg, var(--hl-indigo), var(--hl-violet));
    color: #fff;
    box-shadow: 0 2px 6px rgba(99, 102, 241, 0.25);
    text-decoration: none;
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "style: add program page v2 sidebar CSS"
```

---

### Task 4: Add Program Page CSS — Component Cards v2

**Files:**
- Modify: `assets/css/frontend.css` (append after Task 3 CSS, at end of file)

- [ ] **Step 1: Add component card v2 CSS**

```css
/* ---- Component cards v2 ---- */
.hl-pp-component {
    display: flex;
    align-items: stretch;
    background: #fff;
    border: 1px solid var(--hl-slate-200);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 10px;
    transition: var(--hl-transition);
    min-height: 100px;
}

.hl-pp-component:hover {
    border-color: var(--hl-indigo-border);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
    transform: translateY(-1px);
}

/* Component with course image */
.hl-pp-component-image {
    width: 160px;
    flex-shrink: 0;
    overflow: hidden;
    position: relative;
}

.hl-pp-component-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.hl-pp-component-image .hl-pp-status-overlay {
    position: absolute;
    top: 8px;
    left: 8px;
    padding: 2px 8px;
    border-radius: 5px;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.hl-pp-overlay-completed {
    background: #22c55e;
    color: #fff;
}

.hl-pp-overlay-in-progress {
    background: var(--hl-indigo);
    color: #fff;
}

.hl-pp-overlay-not-started {
    background: rgba(0, 0, 0, 0.45);
    color: #fff;
}

.hl-pp-overlay-locked {
    background: rgba(0, 0, 0, 0.5);
    color: #fff;
}

/* Non-course component icon */
.hl-pp-component-icon {
    width: 52px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--hl-slate-50);
    border-right: 1px solid var(--hl-slate-100);
    font-size: 22px;
}

/* Component body */
.hl-pp-component-body {
    flex: 1;
    padding: 14px 18px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
}

.hl-pp-component-type {
    font-size: 10px;
    font-weight: 600;
    color: var(--hl-slate-400);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 3px;
}

.hl-pp-component-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--hl-slate-900);
    letter-spacing: -0.01em;
    margin: 0 0 3px;
}

.hl-pp-component-desc {
    font-size: 12px;
    color: var(--hl-slate-500);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: 8px;
}

.hl-pp-component-footer {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: auto;
}

.hl-pp-component-meta {
    font-size: 11px;
    color: var(--hl-slate-400);
    display: flex;
    align-items: center;
    gap: 4px;
}

.hl-pp-component-progress {
    flex: 1;
    max-width: 100px;
    height: 4px;
    background: var(--hl-slate-100);
    border-radius: var(--hl-radius-pill);
    overflow: hidden;
}

.hl-pp-progress-fill {
    height: 100%;
    border-radius: var(--hl-radius-pill);
}

.hl-pp-fill-active {
    background: var(--hl-indigo);
}

.hl-pp-fill-complete {
    background: #22c55e;
}

.hl-pp-fill-none {
    background: var(--hl-slate-200);
}

.hl-pp-component-action {
    margin-left: auto;
}

/* V2 button styles */
.hl-pp-btn {
    padding: 7px 16px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-family: var(--hl-font);
    transition: var(--hl-transition);
}

.hl-pp-btn-start {
    background: var(--hl-indigo);
    color: #fff;
}

.hl-pp-btn-start:hover {
    background: var(--hl-indigo-dark);
    color: #fff;
}

.hl-pp-btn-continue {
    background: #f0fdf4;
    color: #16a34a;
    border: 1px solid #bbf7d0;
}

.hl-pp-btn-continue:hover {
    background: #dcfce7;
    color: #16a34a;
}

.hl-pp-btn-view {
    background: var(--hl-slate-50);
    color: var(--hl-slate-500);
    border: 1px solid var(--hl-slate-200);
}

.hl-pp-btn-view:hover {
    background: var(--hl-slate-100);
    color: var(--hl-slate-500);
}

/* Locked component */
.hl-pp-component.hl-pp-locked {
    opacity: 0.55;
}

.hl-pp-component.hl-pp-locked:hover {
    transform: none;
    box-shadow: none;
    border-color: var(--hl-slate-200);
}

.hl-pp-drip-badge {
    display: inline-flex;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 600;
    background: #FEF3C7;
    color: #92400E;
    margin-left: 6px;
}

/* ---- Responsive ---- */
@media (max-width: 900px) {
    .hl-program-page-v2 .hl-pp-layout {
        grid-template-columns: 1fr;
    }

    .hl-pp-sidebar {
        position: static;
    }

    .hl-pp-hero-image {
        display: none;
    }
}

@media (max-width: 600px) {
    .hl-pp-hero-text {
        padding: 20px 24px;
    }

    .hl-pp-hero-title {
        font-size: 18px;
    }

    .hl-pp-component-image {
        width: 100px;
    }

    .hl-pp-component-desc {
        display: none;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "style: add program page v2 component cards and responsive CSS"
```

---

### Task 5: Add Toggle Panel JavaScript

**Files:**
- Modify: `assets/js/frontend.js` (append at end of file)

- [ ] **Step 1: Read `assets/js/frontend.js` to understand the current structure**

- [ ] **Step 2: Append toggle panel function**

This function MUST be placed AFTER the closing `})(jQuery);` on line 124, NOT inside the IIFE. Inline `onclick` handlers in the HTML require the function to be in global scope:

```javascript
/**
 * Program Page v2 — toggle expandable panels.
 */
function hlTogglePanel(panelId, btn) {
    var panel = document.getElementById(panelId);
    if (!panel) return;

    var isOpen = panel.classList.contains('show');

    // Close all panels and deactivate all toggles.
    document.querySelectorAll('.hl-pp-panel').forEach(function(p) {
        p.classList.remove('show');
    });
    document.querySelectorAll('.hl-pp-toggle-btn').forEach(function(b) {
        b.classList.remove('active');
    });

    // If it wasn't open, open it.
    if (!isOpen) {
        panel.classList.add('show');
        btn.classList.add('active');
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add assets/js/frontend.js
git commit -m "feat: add toggle panel JS for program page v2"
```

---

### Task 6: Rewrite PHP — render() Method with New HTML Structure

This is the main task. Rewrite the `render()` method in `class-hl-frontend-program-page.php` to output the v2 HTML structure. The data-loading logic (lines 63-213) stays exactly the same. Only the HTML output (lines 218-292) changes.

**Files:**
- Modify: `includes/frontend/class-hl-frontend-program-page.php:218-292`

- [ ] **Step 1: Read the full current render method to confirm line numbers**

- [ ] **Step 2: Replace the HTML output section (from `// Render.` to the end of render())**

Replace lines 218-292 with the new v2 HTML structure. Keep everything above line 218 unchanged (all data loading, validation, computation).

The new output should be:

```php
// Render.
// Count component types for sidebar stats.
$type_counts = array('courses' => 0, 'visits' => 0, 'other' => 0);
foreach ($component_data as $cd) {
    $ct = $cd['component']->component_type;
    if ($ct === 'learndash_course') {
        $type_counts['courses']++;
    } elseif (in_array($ct, array('classroom_visit', 'observation'), true)) {
        $type_counts['visits']++;
    } else {
        $type_counts['other']++;
    }
}
$completed_count = 0;
foreach ($component_data as $cd) {
    if ($cd['availability']['availability_status'] === 'completed') {
        $completed_count++;
    }
}
$total_components = count($component_data);

// Pathway label for badge.
$pathway_label = '';
if (stripos($pathway->pathway_name, 'teacher') !== false) {
    $pathway_label = __('Teacher Learning Plan', 'hl-core');
} elseif (stripos($pathway->pathway_name, 'mentor') !== false) {
    $pathway_label = __('Mentor Learning Plan', 'hl-core');
} elseif (stripos($pathway->pathway_name, 'leader') !== false) {
    $pathway_label = __('Leader Learning Plan', 'hl-core');
} else {
    $pathway_label = __('Learning Plan', 'hl-core');
}

// Program status label.
$status_label = ($overall_percent >= 100) ? __('Completed', 'hl-core') : __('In Progress', 'hl-core');
if ($overall_percent <= 0) {
    $status_label = __('Not Started', 'hl-core');
}
?>
<div class="hl-dashboard hl-program-page hl-program-page-v2">

    <?php if (!empty($my_programs_url)) : ?>
        <a href="<?php echo esc_url($my_programs_url); ?>" class="hl-back-link">&larr; <?php esc_html_e('Back to My Programs', 'hl-core'); ?></a>
    <?php endif; ?>

    <!-- Hero Banner -->
    <div class="hl-pp-hero">
        <div class="hl-pp-hero-card">
            <div class="hl-pp-hero-text">
                <div class="hl-pp-hero-badge"><?php echo esc_html($pathway_label); ?></div>
                <h1 class="hl-pp-hero-title"><?php echo esc_html($pathway->pathway_name); ?></h1>
                <p class="hl-pp-hero-subtitle"><?php echo esc_html($cycle ? $cycle->cycle_name : ''); ?></p>
            </div>
            <?php if ($image_id) : ?>
                <div class="hl-pp-hero-image">
                    <?php echo wp_get_attachment_image($image_id, 'large', false, array('loading' => 'lazy')); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Two-Column Layout -->
    <div class="hl-pp-layout">

        <!-- Main Content -->
        <div class="hl-pp-main">

            <?php if (!empty($pathway->description)) : ?>
                <div class="hl-pp-about"><?php echo wp_kses_post($pathway->description); ?></div>
            <?php endif; ?>

            <!-- Expandable Sections -->
            <?php
            $has_objectives = !empty($pathway->objectives);
            $has_syllabus   = !empty($pathway->syllabus_url);
            if ($has_objectives || $has_syllabus) :
            ?>
                <div class="hl-pp-toggles">
                    <?php if ($has_objectives) : ?>
                        <button class="hl-pp-toggle-btn" onclick="hlTogglePanel('hl-pp-objectives', this)">&#x1F3AF; <?php esc_html_e('Objectives', 'hl-core'); ?></button>
                    <?php endif; ?>
                    <?php if ($has_syllabus) : ?>
                        <button class="hl-pp-toggle-btn" onclick="hlTogglePanel('hl-pp-syllabus', this)">&#x1F4D6; <?php esc_html_e('Resources', 'hl-core'); ?></button>
                    <?php endif; ?>
                </div>

                <?php if ($has_objectives) : ?>
                    <div class="hl-pp-panel" id="hl-pp-objectives">
                        <h3><?php esc_html_e('Program Objectives', 'hl-core'); ?></h3>
                        <?php echo wp_kses_post($pathway->objectives); ?>
                    </div>
                <?php endif; ?>

                <?php if ($has_syllabus) : ?>
                    <div class="hl-pp-panel" id="hl-pp-syllabus">
                        <h3><?php esc_html_e('Program Resources', 'hl-core'); ?></h3>
                        <p><?php esc_html_e('Access your program materials and resources.', 'hl-core'); ?></p>
                        <a href="<?php echo esc_url($pathway->syllabus_url); ?>" target="_blank" class="hl-pp-syllabus-link">&#x1F4E5; <?php esc_html_e('Access Materials', 'hl-core'); ?></a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Program Steps -->
            <?php if (!empty($component_data)) : ?>
                <div class="hl-pp-section-label">
                    <?php esc_html_e('Program Steps', 'hl-core'); ?>
                    <span class="hl-pp-section-count"><?php echo esc_html($total_components); ?></span>
                </div>
                <?php foreach ($component_data as $ad) :
                    $this->render_component_card_v2($ad, $pathway, $enrollment);
                endforeach; ?>
            <?php else : ?>
                <div class="hl-notice hl-notice-info">
                    <?php esc_html_e('No learning components have been added to this program yet.', 'hl-core'); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="hl-pp-sidebar">

            <!-- Progress + Stats -->
            <div class="hl-pp-sidebar-card">
                <div class="hl-pp-progress-combo">
                    <?php $this->render_progress_ring_v2($overall_percent, $completed_count, $total_components, $status_label); ?>
                    <div class="hl-pp-stats-row">
                        <div class="hl-pp-stat">
                            <div class="hl-pp-stat-num"><?php echo esc_html($type_counts['courses']); ?></div>
                            <div class="hl-pp-stat-lbl"><?php esc_html_e('Courses', 'hl-core'); ?></div>
                        </div>
                        <div class="hl-pp-stat">
                            <div class="hl-pp-stat-num"><?php echo esc_html($type_counts['visits']); ?></div>
                            <div class="hl-pp-stat-lbl"><?php esc_html_e('Visits', 'hl-core'); ?></div>
                        </div>
                        <div class="hl-pp-stat">
                            <div class="hl-pp-stat-num"><?php echo esc_html($type_counts['other']); ?></div>
                            <div class="hl-pp-stat-lbl"><?php esc_html_e('Other', 'hl-core'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Details -->
            <div class="hl-pp-sidebar-card hl-pp-details">
                <?php if (!empty($pathway->avg_completion_time)) : ?>
                    <div class="hl-pp-detail-row">
                        <div class="hl-pp-detail-icon">&#x23F1;</div>
                        <div>
                            <div class="hl-pp-detail-label"><?php esc_html_e('Avg. Completion Time', 'hl-core'); ?></div>
                            <div class="hl-pp-detail-value"><?php echo esc_html($pathway->avg_completion_time); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($pathway->expiration_date)) : ?>
                    <div class="hl-pp-detail-row">
                        <div class="hl-pp-detail-icon">&#x1F4C5;</div>
                        <div>
                            <div class="hl-pp-detail-label"><?php esc_html_e('Learning Plan Ends', 'hl-core'); ?></div>
                            <div class="hl-pp-detail-value"><?php echo esc_html($this->format_date($pathway->expiration_date)); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($cycle) : ?>
                    <div class="hl-pp-detail-row">
                        <div class="hl-pp-detail-icon">&#x1F504;</div>
                        <div>
                            <div class="hl-pp-detail-label"><?php esc_html_e('Cycle', 'hl-core'); ?></div>
                            <div class="hl-pp-detail-value"><?php echo esc_html($cycle->cycle_name); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="hl-pp-detail-row">
                    <div class="hl-pp-detail-icon">&#x1F4CA;</div>
                    <div>
                        <div class="hl-pp-detail-label"><?php esc_html_e('Status', 'hl-core'); ?></div>
                        <div class="hl-pp-detail-value"><?php echo esc_html($program_status); ?></div>
                    </div>
                </div>
            </div>

            <!-- Certificate -->
            <div class="hl-pp-sidebar-card">
                <div class="hl-pp-cert">
                    <?php if ($overall_percent >= 100) : ?>
                        <div class="hl-pp-cert-icon available">&#x1F3C6;</div>
                        <div>
                            <div class="hl-pp-cert-title"><?php esc_html_e('Certificate', 'hl-core'); ?></div>
                            <div class="hl-pp-cert-desc"><?php esc_html_e('Congratulations!', 'hl-core'); ?></div>
                        </div>
                        <a href="#" class="hl-pp-cert-btn"><?php esc_html_e('Download', 'hl-core'); ?></a><!-- TODO: wire up real certificate download URL -->
                    <?php else : ?>
                        <div class="hl-pp-cert-icon">&#x1F512;</div>
                        <div>
                            <div class="hl-pp-cert-title"><?php esc_html_e('Certificate', 'hl-core'); ?></div>
                            <div class="hl-pp-cert-desc"><?php esc_html_e('Complete all steps to unlock', 'hl-core'); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>
<?php
```

- [ ] **Step 3: Commit**

```bash
git add includes/frontend/class-hl-frontend-program-page.php
git commit -m "feat: rewrite program page render() with v2 Calm Professional layout"
```

---

### Task 7: Add PHP — render_progress_ring_v2() and render_component_card_v2() Methods

**Files:**
- Modify: `includes/frontend/class-hl-frontend-program-page.php` (add new methods)

- [ ] **Step 1: Add `render_progress_ring_v2()` method**

Add this new method to the class (after the existing `render_progress_ring()` method which stays for backwards compatibility):

```php
/**
 * Render compact progress ring for v2 sidebar.
 */
private function render_progress_ring_v2($percent, $completed_count, $total, $status_label) {
    $percent       = max(0, min(100, (int) $percent));
    $radius        = 22;
    $circumference = 2 * M_PI * $radius;
    $offset        = $circumference * (1 - $percent / 100);
    ?>
    <div class="hl-pp-progress-top">
        <div class="hl-pp-ring-mini">
            <svg viewBox="0 0 52 52">
                <circle class="ring-bg" cx="26" cy="26" r="<?php echo esc_attr($radius); ?>" />
                <circle class="ring-fill" cx="26" cy="26" r="<?php echo esc_attr($radius); ?>"
                        stroke-dasharray="<?php echo esc_attr(round($circumference, 2)); ?>"
                        stroke-dashoffset="<?php echo esc_attr(round($offset, 2)); ?>" />
            </svg>
            <div class="hl-pp-ring-text"><?php echo esc_html($percent . '%'); ?></div>
        </div>
        <div>
            <div class="hl-pp-progress-label"><?php echo esc_html($status_label); ?></div>
            <div class="hl-pp-progress-sub">
                <?php printf(esc_html__('%d of %d steps completed', 'hl-core'), $completed_count, $total); ?>
            </div>
        </div>
    </div>
    <div class="hl-pp-bar-full">
        <div class="hl-pp-bar-fill" style="width: <?php echo esc_attr($percent); ?>%"></div>
    </div>
    <?php
}
```

- [ ] **Step 2: Add `render_component_card_v2()` method**

Add this new method to the class. It replaces the old `render_component_card()` (which stays for any other code that calls it):

```php
/**
 * Render a v2 component card with image support.
 */
private function render_component_card_v2($ad, $pathway, $enrollment) {
    $component          = $ad['component'];
    $availability       = $ad['availability'];
    $completion_percent = (int) $ad['completion_percent'];
    $completed_at       = $ad['completed_at'];
    $course_url         = $ad['course_url'];
    $assess_status      = isset($ad['assess_status']) ? $ad['assess_status'] : 'not_started';
    $children_counts    = isset($ad['children_counts']) ? $ad['children_counts'] : null;
    $avail_status       = $availability['availability_status'];

    if ($assess_status === 'partial' && $children_counts && $children_counts['total'] > 0) {
        $completion_percent = (int) round($children_counts['submitted'] / $children_counts['total'] * 100);
    }

    $type_label = isset(self::$type_labels[$component->component_type])
        ? self::$type_labels[$component->component_type]
        : ucwords(str_replace('_', ' ', $component->component_type));

    // Is this a course with an image?
    $is_course   = ($component->component_type === 'learndash_course');
    $course_image = '';
    if ($is_course) {
        $external_ref = $component->get_external_ref_array();
        $course_id    = isset($external_ref['course_id']) ? absint($external_ref['course_id']) : 0;
        if ($course_id && has_post_thumbnail($course_id)) {
            $course_image = get_the_post_thumbnail($course_id, 'medium', array('loading' => 'lazy'));
        }
        // Lesson count from LearnDash.
        if ($course_id) {
            $lessons = function_exists('learndash_get_lesson_list') ? learndash_get_lesson_list($course_id) : array();
            $lesson_count = is_array($lessons) ? count($lessons) : 0;
            if ($lesson_count > 0) {
                $type_label .= ' · ' . sprintf(_n('%d Lesson', '%d Lessons', $lesson_count, 'hl-core'), $lesson_count);
            }
        }
    }

    // Status overlay text and class.
    $overlay_class = 'hl-pp-overlay-not-started';
    $overlay_text  = __('Not Started', 'hl-core');
    if ($avail_status === 'completed') {
        $overlay_class = 'hl-pp-overlay-completed';
        $overlay_text  = __('Completed', 'hl-core');
    } elseif ($avail_status === 'locked') {
        $overlay_class = 'hl-pp-overlay-locked';
        $overlay_text  = __('Locked', 'hl-core');
    } elseif ($completion_percent > 0) {
        $overlay_class = 'hl-pp-overlay-in-progress';
        $overlay_text  = __('In Progress', 'hl-core');
    }

    // Progress bar fill class.
    $fill_class = 'hl-pp-fill-none';
    if ($avail_status === 'completed') {
        $fill_class = 'hl-pp-fill-complete';
    } elseif ($completion_percent > 0) {
        $fill_class = 'hl-pp-fill-active';
    }

    // Card CSS class.
    $card_class = 'hl-pp-component';
    if ($avail_status === 'locked') {
        $card_class .= ' hl-pp-locked';
    }
    // No extra class needed — .hl-pp-component-icon is self-styled.

    // Progress text.
    $progress_text = '';
    if ($avail_status === 'completed') {
        $progress_text = esc_html__('100% Complete', 'hl-core');
    } elseif ($assess_status === 'partial' && $children_counts) {
        $progress_text = sprintf('%d/%d', $children_counts['submitted'], $children_counts['total']);
    } elseif ($assess_status === 'draft') {
        $progress_text = esc_html__('Draft', 'hl-core');
    } elseif ($completion_percent > 0) {
        $progress_text = $completion_percent . '%';
    } else {
        $progress_text = esc_html__('Not started', 'hl-core');
    }

    // Action button.
    $action_html = '';
    $btn_class   = 'hl-pp-btn hl-pp-btn-start';
    if ($avail_status === 'available') {
        $action_html = $this->get_action_html($component, $enrollment, $pathway, $assess_status);
        // Replace old button classes with v2 classes.
        $action_html = str_replace(
            array('hl-btn hl-btn-sm hl-btn-primary', 'hl-btn hl-btn-sm hl-btn-secondary'),
            array('hl-pp-btn hl-pp-btn-start', 'hl-pp-btn hl-pp-btn-view'),
            $action_html
        );
        // If component has progress, use continue style.
        if ($completion_percent > 0 && $completion_percent < 100) {
            $action_html = str_replace('hl-pp-btn-start', 'hl-pp-btn-continue', $action_html);
        }
    } elseif ($avail_status === 'completed') {
        $action_html = $this->get_completed_action_html($component, $enrollment);
        $action_html = str_replace(
            array('hl-btn hl-btn-sm hl-btn-secondary', 'hl-btn hl-btn-sm hl-btn-primary'),
            array('hl-pp-btn hl-pp-btn-view', 'hl-pp-btn hl-pp-btn-view'),
            $action_html
        );
    }

    // Non-course icon map.
    $icon_map = array(
        'teacher_self_assessment'      => '&#x1F4DD;',
        'child_assessment'             => '&#x1F4DD;',
        'classroom_visit'              => '&#x1F50D;',
        'observation'                  => '&#x1F50D;',
        'coaching_session_attendance'  => '&#x1F3AC;',
        'reflective_practice_session'  => '&#x1F4AD;',
        'self_reflection'              => '&#x1F4AD;',
    );
    $type_icon = isset($icon_map[$component->component_type]) ? $icon_map[$component->component_type] : '&#x1F4CB;';

    // Drip badge.
    $drip_html = '';
    if ($avail_status === 'locked'
        && !empty($availability['locked_reason'])
        && $availability['locked_reason'] === 'drip'
        && !empty($availability['next_available_at'])
    ) {
        $drip_html = '<span class="hl-pp-drip-badge">'
            . sprintf(esc_html__('Available %s', 'hl-core'), esc_html($this->format_date($availability['next_available_at'])))
            . '</span>';
    }
    ?>
    <div class="<?php echo esc_attr($card_class); ?>">
        <?php if ($is_course && !empty($course_image)) : ?>
            <div class="hl-pp-component-image">
                <?php echo $course_image; ?>
                <div class="hl-pp-status-overlay <?php echo esc_attr($overlay_class); ?>">
                    <?php echo esc_html($overlay_text); ?>
                </div>
            </div>
        <?php elseif (!$is_course) : ?>
            <div class="hl-pp-component-icon"><?php echo $type_icon; ?></div>
        <?php endif; ?>

        <div class="hl-pp-component-body">
            <div class="hl-pp-component-type"><?php echo wp_kses_post($type_label); ?></div>
            <h4 class="hl-pp-component-name">
                <?php echo esc_html($component->title); ?>
                <?php if (!empty($drip_html)) echo $drip_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() ?>
            </h4>
            <div class="hl-pp-component-footer">
                <div class="hl-pp-component-meta"><?php echo esc_html($progress_text); ?></div>
                <div class="hl-pp-component-progress">
                    <div class="hl-pp-progress-fill <?php echo esc_attr($fill_class); ?>" style="width: <?php echo esc_attr($completion_percent); ?>%"></div>
                </div>
                <?php if (!empty($action_html)) : ?>
                    <div class="hl-pp-component-action"><?php echo $action_html; ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

```

> **Note:** The `get_coaching_action_html()` method returns multi-element HTML (badge + span + button) designed for the old card layout. In the v2 layout, this content may overflow in the compact footer. If coaching session cards look broken after deployment, a `get_coaching_action_html_v2()` method may be needed — treat as a follow-up fix during visual verification.

- [ ] **Step 3: Commit**

```bash
git add includes/frontend/class-hl-frontend-program-page.php
git commit -m "feat: add v2 progress ring, component card, and lesson count methods"
```

---

### Task 8: Load Inter Font

**Files:**
- Modify: `includes/frontend/class-hl-shortcodes.php` (where `wp_enqueue_style('hl-frontend', ...)` is called)

- [ ] **Step 1: Read `class-hl-shortcodes.php` around line 103 to see the enqueue**

- [ ] **Step 2: Add Google Fonts enqueue for Inter**

Before the existing `wp_enqueue_style('hl-frontend', ...)` call, add:

```php
wp_enqueue_style(
    'hl-google-fonts-inter',
    'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap',
    array(),
    null
);
```

- [ ] **Step 3: Commit**

```bash
git add includes/frontend/class-hl-shortcodes.php
git commit -m "style: enqueue Inter font from Google Fonts for v2 pages"
```

---

### Task 9: Deploy and Verify

**Files:** None (deployment only)

- [ ] **Step 1: Read `.claude/skills/deploy.md` for deployment commands**

- [ ] **Step 2: Deploy to test server**

Push updated files to the test server via the deployment method in `deploy.md`.

- [ ] **Step 3: Visual verification checklist**

Navigate to `test.academy.housmanlearning.com/program/?id=76&enrollment=463` and verify:

1. Hero banner renders with gradient background and pathway name
2. Two-column layout: content left, sidebar right
3. Toggle buttons for Objectives/Resources expand/collapse correctly
4. Component cards show course featured images with status overlay
5. Non-course components show icon instead of image
6. Sidebar: compact progress ring, stats row, details, certificate card all visible
7. Sidebar sticks on scroll
8. Progress percentages match the data
9. Action buttons work (Start Course, Continue, View)
10. Page is responsive: sidebar collapses below content on mobile

- [ ] **Step 4: Commit any fixes and redeploy if needed**

---

### Task 10: Update STATUS.md and README.md

Per CLAUDE.md Rule #3, update both tracking files.

**Files:**
- Modify: `STATUS.md`
- Modify: `README.md`

- [ ] **Step 1: Update STATUS.md**

Check off any related build queue items (mark `[x]`). Add a note about the Pathway page redesign if not already in the queue.

- [ ] **Step 2: Update README.md**

In the "What's Implemented" section, note the Pathway page v2 redesign (Calm Professional design, two-column layout, hero banner, collapsible sections, component cards with course images).

- [ ] **Step 3: Commit**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md and README.md with pathway page redesign"
```
