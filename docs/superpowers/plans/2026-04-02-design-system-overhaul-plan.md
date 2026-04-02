# Design System Consistency Overhaul — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all design system inconsistencies: replace 3 custom page headers with one reusable hero component, standardize 10 hero gradients to design tokens, fix 4 critical color bugs, and clean up dead BuddyBoss CSS.

**Architecture:** CSS-only changes + PHP template changes in 3 page renderers. No new services, no JS changes, no data model changes. The new `.hl-page-hero` component mirrors the existing `.hl-hero` pattern. Hero gradient standardization is find-and-replace in CSS. Dead code removal is surgical deletion.

**Tech Stack:** PHP 7.4+ (WordPress shortcode renderers), CSS (frontend.css design system)

**Spec:** `docs/superpowers/specs/2026-04-02-page-hero-redesign-design.md`

---

### Task 1: Add `.hl-page-hero` + `.hl-meta-bar` CSS Components

**Files:**
- Modify: `assets/css/frontend.css` — insert after the `.hl-hero` section (~line 6390)

- [ ] **Step 1: Add `.hl-page-hero` CSS after the existing `.hl-hero` section**

Find the end of the `.hl-hero__subtitle` rule (around line 6390) and insert immediately after:

```css
/* ---- Page Hero — reusable header for Team, Classroom, School pages ---- */
.hl-page-hero {
    display: flex;
    align-items: center;
    gap: 20px;
    background: linear-gradient(135deg, var(--hl-primary) 0%, var(--hl-primary-light) 100%);
    color: #fff;
    padding: 28px 32px;
    border-radius: var(--hl-radius);
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}
.hl-page-hero::after {
    content: '';
    position: absolute;
    right: -40px;
    top: -40px;
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: rgba(255,255,255,0.03);
}
.hl-page-hero__icon {
    flex-shrink: 0;
    width: 60px;
    height: 60px;
    background: rgba(255,255,255,0.12);
    border-radius: var(--hl-radius);
    display: flex;
    align-items: center;
    justify-content: center;
}
.hl-page-hero__icon .dashicons {
    font-size: 28px;
    width: 28px;
    height: 28px;
    color: #fff;
}
.hl-page-hero__tag {
    display: inline-block;
    background: var(--hl-accent);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: var(--hl-radius-pill);
    letter-spacing: 0.5px;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.hl-page-hero__title {
    color: #fff;
    font-size: 26px;
    font-weight: 800;
    margin: 0;
    letter-spacing: -0.3px;
}
.hl-page-hero__subtitle {
    color: rgba(255,255,255,0.65);
    font-size: 14px;
    margin: 4px 0 0;
}

/* ---- Meta Bar — info cards row below page heroes ---- */
.hl-meta-bar {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

@media (max-width: 600px) {
    .hl-page-hero {
        flex-direction: column;
        text-align: center;
        padding: 24px 20px;
    }
    .hl-meta-bar {
        flex-direction: column;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "feat: add .hl-page-hero + .hl-meta-bar CSS components"
```

---

### Task 2: Rewrite Team Page Header

**Files:**
- Modify: `includes/frontend/class-hl-frontend-team-page.php:241-294` — rewrite `render_header()` method

- [ ] **Step 1: Replace `render_header()` method body**

Replace the entire `render_header()` method (lines 241-294) with:

```php
private function render_header( $team, $cycle, $school ) {
    $members      = $this->team_repo->get_members( $team->team_id );
    $member_count = count( $members );

    // Avg completion.
    $sum = 0;
    foreach ( $members as $m ) {
        $sum += $this->reporting_service->get_enrollment_completion( $m['enrollment_id'] );
    }
    $avg = $member_count > 0 ? round( $sum / $member_count ) : 0;

    // Mentors.
    $mentor_names = array();
    foreach ( $members as $m ) {
        if ( $m['membership_type'] === 'mentor' ) {
            $mentor_names[] = $m['display_name'] ?: $m['user_email'];
        }
    }

    ?>
    <div class="hl-page-hero">
        <div class="hl-page-hero__icon">
            <span class="dashicons dashicons-groups"></span>
        </div>
        <div class="hl-page-hero__text">
            <span class="hl-page-hero__tag"><?php esc_html_e( 'Team', 'hl-core' ); ?></span>
            <h2 class="hl-page-hero__title"><?php echo esc_html( $team->team_name ); ?></h2>
            <?php if ( $school ) : ?>
                <p class="hl-page-hero__subtitle"><?php echo esc_html( $school->name ); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="hl-meta-bar">
        <?php if ( $cycle ) : ?>
            <div class="hl-meta-item">
                <div class="hl-meta-item__icon"><span class="dashicons dashicons-clock"></span></div>
                <div>
                    <div class="hl-meta-item__label"><?php esc_html_e( 'Cycle', 'hl-core' ); ?></div>
                    <div class="hl-meta-item__value"><?php echo esc_html( $cycle->cycle_name ); ?></div>
                </div>
            </div>
        <?php endif; ?>
        <div class="hl-meta-item">
            <div class="hl-meta-item__icon"><span class="dashicons dashicons-admin-users"></span></div>
            <div>
                <div class="hl-meta-item__label"><?php esc_html_e( 'Members', 'hl-core' ); ?></div>
                <div class="hl-meta-item__value"><?php echo esc_html( $member_count ); ?></div>
            </div>
        </div>
        <?php if ( ! empty( $mentor_names ) ) : ?>
            <div class="hl-meta-item">
                <div class="hl-meta-item__icon"><span class="dashicons dashicons-businessman"></span></div>
                <div>
                    <div class="hl-meta-item__label"><?php esc_html_e( 'Mentor(s)', 'hl-core' ); ?></div>
                    <div class="hl-meta-item__value"><?php echo esc_html( implode( ', ', $mentor_names ) ); ?></div>
                </div>
            </div>
        <?php endif; ?>
        <div class="hl-meta-item">
            <div class="hl-meta-item__icon"><span class="dashicons dashicons-chart-area"></span></div>
            <div>
                <div class="hl-meta-item__label"><?php esc_html_e( 'Avg Completion', 'hl-core' ); ?></div>
                <div class="hl-meta-item__value"><?php echo esc_html( $avg . '%' ); ?></div>
            </div>
        </div>
    </div>
    <?php
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-team-page.php
git commit -m "refactor: team page header → .hl-page-hero + .hl-meta-bar"
```

---

### Task 3: Rewrite Classroom Page Header

**Files:**
- Modify: `includes/frontend/class-hl-frontend-classroom-page.php:597-633` — rewrite `render_header()` method

- [ ] **Step 1: Replace `render_header()` method body**

Replace the entire `render_header()` method (lines 597-633) with:

```php
private function render_header( $classroom, $school, $teacher_names ) {
    ?>
    <div class="hl-page-hero">
        <div class="hl-page-hero__icon">
            <span class="dashicons dashicons-welcome-learn-more"></span>
        </div>
        <div class="hl-page-hero__text">
            <span class="hl-page-hero__tag"><?php esc_html_e( 'Classroom', 'hl-core' ); ?></span>
            <h2 class="hl-page-hero__title"><?php echo esc_html( $classroom->classroom_name ); ?></h2>
            <?php if ( $school ) : ?>
                <p class="hl-page-hero__subtitle"><?php echo esc_html( $school->name ); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="hl-meta-bar">
        <?php if ( ! empty( $classroom->age_band ) ) : ?>
            <div class="hl-meta-item">
                <div class="hl-meta-item__icon"><span class="dashicons dashicons-groups"></span></div>
                <div>
                    <div class="hl-meta-item__label"><?php esc_html_e( 'Age Band', 'hl-core' ); ?></div>
                    <div class="hl-meta-item__value"><?php echo esc_html( ucfirst( $classroom->age_band ) ); ?></div>
                </div>
            </div>
        <?php endif; ?>
        <?php if ( ! empty( $teacher_names ) ) : ?>
            <div class="hl-meta-item">
                <div class="hl-meta-item__icon"><span class="dashicons dashicons-admin-users"></span></div>
                <div>
                    <div class="hl-meta-item__label"><?php esc_html_e( 'Teacher(s)', 'hl-core' ); ?></div>
                    <div class="hl-meta-item__value">
                        <?php
                        $links = array();
                        foreach ( $teacher_names as $t ) {
                            $url = $t['user_id'] ? $this->get_profile_url( $t['user_id'] ) : '';
                            if ( $url ) {
                                $links[] = '<a href="' . esc_url( $url ) . '" class="hl-profile-link">' . esc_html( $t['name'] ) . '</a>';
                            } else {
                                $links[] = esc_html( $t['name'] );
                            }
                        }
                        echo implode( ', ', $links );
                        ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-classroom-page.php
git commit -m "refactor: classroom page header → .hl-page-hero + .hl-meta-bar"
```

---

### Task 4: Rewrite School Page Header

**Files:**
- Modify: `includes/frontend/class-hl-frontend-school-page.php:159-182` — rewrite `render_header()` method

- [ ] **Step 1: Replace `render_header()` method body**

Replace the entire `render_header()` method (lines 159-182) with:

```php
private function render_header( $school, $parent_district, $district_page_url ) {
    ?>
    <div class="hl-page-hero">
        <div class="hl-page-hero__icon">
            <span class="dashicons dashicons-building"></span>
        </div>
        <div class="hl-page-hero__text">
            <span class="hl-page-hero__tag"><?php esc_html_e( 'School', 'hl-core' ); ?></span>
            <h2 class="hl-page-hero__title"><?php echo esc_html( $school->name ); ?></h2>
            <?php if ( $parent_district ) :
                $d_url = $district_page_url
                    ? add_query_arg( 'id', $parent_district->orgunit_id, $district_page_url )
                    : '';
            ?>
                <p class="hl-page-hero__subtitle">
                    <?php if ( $d_url ) : ?>
                        <a href="<?php echo esc_url( $d_url ); ?>" style="color: rgba(255,255,255,0.65); text-decoration: underline;">
                            <?php echo esc_html( $parent_district->name ); ?>
                        </a>
                    <?php else : ?>
                        <?php echo esc_html( $parent_district->name ); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
```

Note: The school page currently uses `.hl-crm-detail-header` (not `.hl-progress-header`). This is a CRM-style header without meta items. The meta bar is not needed here since the school page doesn't display cycle/teacher/completion metadata in its header. The school page's summary data is rendered elsewhere in its body.

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-school-page.php
git commit -m "refactor: school page header → .hl-page-hero"
```

---

### Task 5: Delete Old Page Header CSS

**Files:**
- Modify: `assets/css/frontend.css` — delete old header CSS sections

- [ ] **Step 1: Delete `.hl-team-page-header` section**

Delete the entire `.hl-team-page-header` block and all its children (lines ~1857-1916, approximately 60 lines). This includes:
- `.hl-team-page-header`
- `.hl-team-page-header-info`
- `.hl-team-page-header .hl-partnership-title`
- `.hl-team-page-header .hl-scope-indicator`
- `.hl-team-page-header .hl-partnership-meta`
- `.hl-team-page-header .hl-meta-item strong`
- `.hl-team-page-header-stats`
- `.hl-team-page-header-stats .hl-metric-card`
- `.hl-team-page-header-stats .hl-metric-value`
- `.hl-team-page-header-stats .hl-metric-label`

- [ ] **Step 2: Delete `.hl-classroom-page-header` section**

Delete the entire `.hl-classroom-page-header` block and all its children (lines ~1922-1961). This includes:
- `.hl-classroom-page-header`
- `.hl-classroom-page-header-info`
- `.hl-classroom-page-header .hl-partnership-title`
- `.hl-classroom-page-header .hl-scope-indicator`
- `.hl-classroom-page-header .hl-partnership-meta`
- `.hl-classroom-page-header .hl-meta-item strong`

- [ ] **Step 3: Delete responsive overrides for old headers**

Find and delete the responsive `@media` rules for `.hl-team-page-header` and `.hl-classroom-page-header` (in the responsive section, around lines 2556-2566).

- [ ] **Step 4: Commit**

```bash
git add assets/css/frontend.css
git commit -m "refactor: delete old page header CSS (team, classroom, responsive)"
```

---

### Task 6: Standardize Hero Gradients

**Files:**
- Modify: `assets/css/frontend.css` — find and replace hardcoded hex values in hero gradients

- [ ] **Step 1: Replace 3-stop gradients with 2-stop token gradient**

Find all instances of this pattern:
```css
linear-gradient(135deg, var(--hl-primary) 0%, #1e3a5f 55%, #2d5a88 100%)
```
Replace with:
```css
linear-gradient(135deg, var(--hl-primary) 0%, var(--hl-primary-light) 100%)
```

This affects (find each and replace):
- `.hl-progress-header` (line ~665)
- `.hl-my-partnership-header` (line ~2169)
- `.hlup-hero` (line ~4556)

Also find these 2-stop variants with hardcoded hex:
```css
linear-gradient(135deg, var(--hl-primary) 0%, #2d5f8a 100%)
```
Replace with:
```css
linear-gradient(135deg, var(--hl-primary) 0%, var(--hl-primary-light) 100%)
```

This affects:
- `.hlap-hero` (line ~7003)
- `.hlcv-hero` (line ~7077)
- `.hlrn-hero` (line ~7876)

Also find slate-based gradients:
```css
linear-gradient(135deg, var(--hl-slate-900) 0%, #1e3a5f 55%, #2d5a88 100%)
```
Replace with:
```css
linear-gradient(135deg, var(--hl-primary) 0%, var(--hl-primary-light) 100%)
```

This affects:
- `.hl-student-progress-hero` (line ~2682)
- `.hlst-hero` (line ~3617)

- [ ] **Step 2: Standardize icon background opacity**

Find `rgba(255,255,255,.25)` in hero icon backgrounds and replace with `rgba(255,255,255,.12)`. Check these heroes:
- `.hlcd-hero-icon` (Coach Dashboard)
- `.hlcmd-hero-icon` (Coach Mentor Detail)

- [ ] **Step 3: Fix Coach Dashboard hero padding**

Find `.hlcd-hero` and change padding from `32px 36px` to `28px 32px` (standard).

- [ ] **Step 4: Commit**

```bash
git add assets/css/frontend.css
git commit -m "refactor: standardize all hero gradients to design tokens"
```

---

### Task 7: Fix Critical Color Bugs

**Files:**
- Modify: `assets/css/frontend.css`

- [ ] **Step 1: Fix `.hlcd-link-card` white-on-white**

Find `.hlcd-link-card` (line ~7218). The rule has `color: inherit !important` which inherits white from the dark hero parent. The `.hlcd-link-title` and `.hlcd-link-desc` already have explicit colors with `!important`, so the card text is fine. But for safety, change `color: inherit !important` to `color: var(--hl-text) !important` on both `.hlcd-link-card` and `.hlcd-link-card:hover`.

- [ ] **Step 2: Fix `.hl-progress-header` / `.hl-my-partnership-header` meta item strong**

Find these rules (line ~696):
```css
.hl-progress-header .hl-meta-item strong,
.hl-my-partnership-header .hl-meta-item strong {
    color: var(--hl-text);
}
```

These are already correct (dark text on white-background meta item cards). Verify they match — no change needed if already `var(--hl-text)`.

- [ ] **Step 3: Add missing `.hl-btn-small` CSS**

Find the `.hl-btn-sm` rule in frontend.css and add an alias immediately after it:

```css
.hl-btn-small {
    padding: 6px 14px;
    font-size: 12px;
}
```

This matches the existing `.hl-btn-sm` dimensions. The class is used in 6 PHP files (9 occurrences) as `hl-btn hl-btn-small`.

- [ ] **Step 4: Commit**

```bash
git add assets/css/frontend.css
git commit -m "fix: hlcd-link-card white-on-white, add .hl-btn-small CSS"
```

---

### Task 8: Remove Dead BuddyBoss CSS Selectors

**Files:**
- Modify: `assets/css/frontend.css`

- [ ] **Step 1: Delete dead `.entry-header` / `.entry-title` selectors**

Find and delete these 4 blocks:

1. Program page (lines ~2649-2652):
```css
body:has(.hl-program-page-v2) .entry-header,
body:has(.hl-program-page-v2) .entry-title {
    display: none !important;
}
```

2. Dashboard V2 (lines ~3376-3379):
```css
body:has(.hl-dashboard-v2) .entry-header,
body:has(.hl-dashboard-v2) .entry-title {
    display: none !important;
}
```

3. User Profile (lines ~4506-4513, including the comment above):
```css
/* Hide BB automatic page title on profile page.
   :has() targets the parent article when .hlup-wrapper is inside .entry-content. */
article:has(.hlup-wrapper) > .entry-header {
    display: none !important;
    margin: 0 !important;
    min-height: 0 !important;
    padding: 0 !important;
}
```

4. Coach Mentor Detail (lines ~7346-7348):
```css
body:has(.hlcmd-wrapper) .entry-header .entry-title,
body:has(.hlcmd-wrapper) article > header .entry-title { display: none !important }
body:has(.hlcmd-wrapper) .entry-header { margin-bottom: 0 !important; min-height: 0 !important; padding: 0 !important }
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "cleanup: remove 19 lines of dead BuddyBoss CSS selectors"
```

---

### Task 9: Update STATUS.md + README.md, Deploy, Verify

**Files:**
- Modify: `STATUS.md` — update build queue
- Modify: `README.md` — update What's Implemented if needed

- [ ] **Step 1: Update STATUS.md**

Add under the "BuddyBoss Detachment" section:
```markdown
- [x] **Design system consistency overhaul** — Reusable `.hl-page-hero` + `.hl-meta-bar` components replace 3 custom page headers (Team, Classroom, School). 10 hero gradients standardized to `var(--hl-primary)` → `var(--hl-primary-light)` tokens. Icon opacity normalized to 0.12. Coach Dashboard padding corrected. 4 critical color bugs fixed (hlcd-link-card white-on-white, meta item strong colors). `.hl-btn-small` CSS added. 19 lines dead BB selectors removed.
```

- [ ] **Step 2: Commit status updates**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md with design system overhaul"
```

- [ ] **Step 3: Deploy to test + production**

```bash
# Package
cd "C:/Users/MateoGonzalez/Dev Projects Mateo/housman-learning-academy/app/public/wp-content/plugins"
tar --exclude='.git' --exclude='data' --exclude='vendor' --exclude='node_modules' -czf /tmp/hl-core.tar.gz hl-core

# Test server
scp -i ~/.ssh/hla-test-keypair.pem /tmp/hl-core.tar.gz bitnami@44.221.6.201:/tmp/
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress/wp-content/plugins && sudo rm -rf hl-core && sudo tar -xzf /tmp/hl-core.tar.gz && sudo chown -R bitnami:daemon hl-core'

# Production server
scp -P 65002 /tmp/hl-core.tar.gz u665917738@145.223.76.150:/tmp/
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/wp-content/plugins && rm -rf hl-core && tar -xzf /tmp/hl-core.tar.gz"

# Flush caches
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress cache flush'
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html && wp cache flush"
```

- [ ] **Step 4: Visual verification checklist**

Check these pages on production:
1. `/team/?id=19` — Team page: new `.hl-page-hero` with icon + tag + title + subtitle, meta bar below with Cycle/Members/Mentor/Completion cards
2. `/classroom/` (any classroom) — Classroom page: same hero pattern with classroom icon, meta bar with Age Band/Teacher
3. `/my-school/` (any school page) — School page: hero with building icon
4. `/coach-availability/` — Hero gradient should use design tokens (no hardcoded hex)
5. `/my-mentors/` — Coach Mentors hero (reference, should be unchanged)
6. `/coach-reports/` — Coach Reports hero (reference)
7. `/component/` (any action plan) — Action Plan hero gradient should use tokens
8. `/coaching-home/` — Coach Dashboard: link cards should have dark text on white bg, hero padding corrected
9. Any page with `.hl-btn-small` buttons (assessments, observations) — buttons should be properly sized
