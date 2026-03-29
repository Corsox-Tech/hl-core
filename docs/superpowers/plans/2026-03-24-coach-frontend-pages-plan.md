# Coach Frontend Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build 5 coach-facing shortcode pages (Dashboard, My Mentors, Mentor Detail, Reports, Availability) with modern UI matching the Classroom Visit design system.

**Architecture:** Each page is a PHP shortcode class in `includes/frontend/` following the existing `ob_start() → render() → ob_get_clean()` pattern. All CSS is inline `<style>` blocks with component-scoped prefixes. Data comes from existing services (`HL_Coaching_Service`, `HL_Coach_Assignment_Service`) plus new query methods. One new DB table (`hl_coach_availability`) added via installer migration.

**Tech Stack:** PHP 7.4+, WordPress shortcode API, inline CSS, vanilla JS (jQuery available), existing HL service layer.

---

## File Structure

### New Files (7)
| File | Responsibility |
|------|----------------|
| `includes/frontend/class-hl-frontend-coach-dashboard.php` | `[hl_coach_dashboard]` — welcome banner, stats cards, quick links |
| `includes/frontend/class-hl-frontend-coach-mentors.php` | `[hl_coach_mentors]` — mentor card grid with search/filter |
| `includes/frontend/class-hl-frontend-coach-mentor-detail.php` | `[hl_coach_mentor_detail]` — tabbed mentor profile (sessions, team, RP, reports) |
| `includes/frontend/class-hl-frontend-coach-reports.php` | `[hl_coach_reports]` — aggregated reports with CSV export |
| `includes/frontend/class-hl-frontend-coach-availability.php` | `[hl_coach_availability]` — weekly schedule grid for availability |
| `includes/services/class-hl-coach-dashboard-service.php` | Data queries for coach dashboard stats and aggregated reporting |
| (none — hl_coach_availability table) | Added to existing `class-hl-installer.php` get_schema() |

### Modified Files (5)
| File | Change |
|------|--------|
| `hl-core.php` | Add 6 `require_once` lines for new frontend + service files |
| `includes/frontend/class-hl-shortcodes.php` | Register 5 shortcodes + add to `enqueue_assets()` detection + add `template_redirect` hooks |
| `includes/cli/class-hl-cli-create-pages.php` | Add 5 page definitions to `get_page_definitions()` |
| `includes/integrations/class-hl-buddyboss-integration.php` | Add 3 coach menu items to `build_menu_items()` |
| `includes/class-hl-installer.php` | Add `hl_coach_availability` table to `get_schema()` + migration |

---

## Task 1: Coach Dashboard Service (data layer)

**Files:**
- Create: `includes/services/class-hl-coach-dashboard-service.php`

This service provides all data queries for the coach pages. Creating it first means every frontend page has its data layer ready.

- [ ] **Step 1: Create the coach dashboard service**

```php
<?php
if (!defined('ABSPATH')) exit;

/**
 * Coach Dashboard Service
 *
 * Provides data queries for coach frontend pages: stats, mentor lists,
 * aggregated reporting, and availability management.
 *
 * @package HL_Core
 */
class HL_Coach_Dashboard_Service {

    /**
     * Get dashboard stats for a coach.
     *
     * @param int $coach_user_id
     * @return array {assigned_mentors: int, upcoming_sessions: int, sessions_this_month: int}
     */
    public function get_dashboard_stats($coach_user_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $today = current_time('Y-m-d');
        $now   = current_time('mysql');
        $month_start = date('Y-m-01', strtotime($today));
        $month_end   = date('Y-m-t', strtotime($today));

        // Get all active cycle IDs where this coach has assignments.
        $cycle_ids = $this->get_coach_cycle_ids($coach_user_id);
        if (empty($cycle_ids)) {
            return array('assigned_mentors' => 0, 'upcoming_sessions' => 0, 'sessions_this_month' => 0);
        }

        // Count unique mentor enrollments across all cycles.
        $assignment_service = new HL_Coach_Assignment_Service();
        $total_mentors = 0;
        foreach ($cycle_ids as $cid) {
            $roster = $assignment_service->get_coach_roster($coach_user_id, $cid);
            // Filter to mentor-role enrollments only.
            foreach ($roster as $r) {
                $roles = json_decode($r['roles'] ?? '[]', true);
                if (is_array($roles) && in_array('mentor', $roles, true)) {
                    $total_mentors++;
                }
            }
        }

        // Upcoming sessions (scheduled, datetime >= now).
        $upcoming = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}hl_coaching_session
             WHERE coach_user_id = %d AND session_status = 'scheduled' AND session_datetime >= %s",
            $coach_user_id, $now
        ));

        // Sessions this month (any status except cancelled).
        $this_month = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}hl_coaching_session
             WHERE coach_user_id = %d
               AND session_datetime >= %s AND session_datetime <= %s
               AND session_status != 'cancelled'",
            $coach_user_id, $month_start . ' 00:00:00', $month_end . ' 23:59:59'
        ));

        return array(
            'assigned_mentors'   => $total_mentors,
            'upcoming_sessions'  => $upcoming,
            'sessions_this_month' => $this_month,
        );
    }

    /**
     * Get mentors assigned to a coach with enriched data for the mentor cards.
     *
     * @param int $coach_user_id
     * @return array Each: enrollment_id, user_id, display_name, school_name, team_name,
     *               pathway_name, completion_pct, last_session_date, next_session_date, cycle_id
     */
    public function get_mentors_for_coach($coach_user_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $now = current_time('mysql');

        $cycle_ids = $this->get_coach_cycle_ids($coach_user_id);
        if (empty($cycle_ids)) {
            return array();
        }

        $assignment_service = new HL_Coach_Assignment_Service();
        $mentors = array();

        foreach ($cycle_ids as $cid) {
            $roster = $assignment_service->get_coach_roster($coach_user_id, $cid);
            foreach ($roster as $r) {
                $roles = json_decode($r['roles'] ?? '[]', true);
                if (!is_array($roles) || !in_array('mentor', $roles, true)) {
                    continue;
                }

                $eid = (int) $r['enrollment_id'];

                // School name.
                $school_name = '—';
                if (!empty($r['school_id'])) {
                    $school_name = $wpdb->get_var($wpdb->prepare(
                        "SELECT orgunit_name FROM {$prefix}hl_orgunit WHERE orgunit_id = %d",
                        $r['school_id']
                    )) ?: '—';
                }

                // Team name.
                $team_name = $wpdb->get_var($wpdb->prepare(
                    "SELECT t.team_name FROM {$prefix}hl_team_membership tm
                     JOIN {$prefix}hl_team t ON tm.team_id = t.team_id
                     WHERE tm.enrollment_id = %d AND t.cycle_id = %d LIMIT 1",
                    $eid, $cid
                )) ?: '—';

                // Pathway + completion %.
                $pathway_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT p.pathway_name,
                            ROUND(IFNULL(
                                (SELECT COUNT(*) FROM {$prefix}hl_component_state cs
                                 JOIN {$prefix}hl_component c ON cs.component_id = c.component_id
                                 WHERE cs.enrollment_id = %d AND c.pathway_id = pa.pathway_id AND cs.completion_status = 'complete')
                                * 100.0 /
                                NULLIF((SELECT COUNT(*) FROM {$prefix}hl_component c2 WHERE c2.pathway_id = pa.pathway_id AND c2.status = 'active'), 0)
                            , 0)) AS completion_pct
                     FROM {$prefix}hl_pathway_assignment pa
                     JOIN {$prefix}hl_pathway p ON pa.pathway_id = p.pathway_id
                     WHERE pa.enrollment_id = %d LIMIT 1",
                    $eid, $eid
                ), ARRAY_A);

                // Last coaching session date.
                $last_session = $wpdb->get_var($wpdb->prepare(
                    "SELECT MAX(session_datetime) FROM {$prefix}hl_coaching_session
                     WHERE mentor_enrollment_id = %d AND session_status = 'attended'",
                    $eid
                ));

                // Next scheduled session.
                $next_session = $wpdb->get_var($wpdb->prepare(
                    "SELECT MIN(session_datetime) FROM {$prefix}hl_coaching_session
                     WHERE mentor_enrollment_id = %d AND session_status = 'scheduled' AND session_datetime >= %s",
                    $eid, $now
                ));

                $mentors[] = array(
                    'enrollment_id'   => $eid,
                    'user_id'         => (int) $r['user_id'],
                    'display_name'    => $r['display_name'],
                    'user_email'      => $r['user_email'] ?? '',
                    'school_name'     => $school_name,
                    'team_name'       => $team_name,
                    'pathway_name'    => $pathway_data['pathway_name'] ?? '—',
                    'completion_pct'  => (int) ($pathway_data['completion_pct'] ?? 0),
                    'last_session'    => $last_session,
                    'next_session'    => $next_session,
                    'cycle_id'        => $cid,
                );
            }
        }

        return $mentors;
    }

    /**
     * Get full mentor detail data for the Mentor Detail page.
     *
     * @param int $mentor_enrollment_id
     * @param int $coach_user_id For authorization check.
     * @return array|null
     */
    public function get_mentor_detail($mentor_enrollment_id, $coach_user_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, u.display_name, u.user_email,
                    o.orgunit_name AS school_name
             FROM {$prefix}hl_enrollment e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$prefix}hl_orgunit o ON e.school_id = o.orgunit_id
             WHERE e.enrollment_id = %d AND e.status = 'active'",
            $mentor_enrollment_id
        ), ARRAY_A);

        if (!$enrollment) {
            return null;
        }

        // Verify coach assignment.
        $coach_service = new HL_Coach_Assignment_Service();
        $resolved = $coach_service->get_coach_for_enrollment($mentor_enrollment_id, (int) $enrollment['cycle_id']);
        if (!$resolved || (int) $resolved['coach_user_id'] !== $coach_user_id) {
            return null; // Not this coach's mentee.
        }

        $eid = $mentor_enrollment_id;
        $cid = (int) $enrollment['cycle_id'];

        // Team name.
        $team_name = $wpdb->get_var($wpdb->prepare(
            "SELECT t.team_name FROM {$prefix}hl_team_membership tm
             JOIN {$prefix}hl_team t ON tm.team_id = t.team_id
             WHERE tm.enrollment_id = %d AND t.cycle_id = %d LIMIT 1",
            $eid, $cid
        )) ?: '—';

        // Pathway + completion.
        $pathway = $wpdb->get_row($wpdb->prepare(
            "SELECT p.pathway_id, p.pathway_name FROM {$prefix}hl_pathway_assignment pa
             JOIN {$prefix}hl_pathway p ON pa.pathway_id = p.pathway_id
             WHERE pa.enrollment_id = %d LIMIT 1",
            $eid
        ), ARRAY_A);

        $completion_pct = 0;
        if ($pathway) {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}hl_component WHERE pathway_id = %d AND status = 'active'",
                $pathway['pathway_id']
            ));
            $done = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}hl_component_state cs
                 JOIN {$prefix}hl_component c ON cs.component_id = c.component_id
                 WHERE cs.enrollment_id = %d AND c.pathway_id = %d AND cs.completion_status = 'complete'",
                $eid, $pathway['pathway_id']
            ));
            $completion_pct = $total > 0 ? round($done * 100 / $total) : 0;
        }

        return array_merge($enrollment, array(
            'team_name'      => $team_name,
            'pathway_name'   => $pathway['pathway_name'] ?? '—',
            'pathway_id'     => $pathway['pathway_id'] ?? 0,
            'completion_pct' => $completion_pct,
        ));
    }

    /**
     * Get team members for a mentor's team.
     *
     * @param int $mentor_enrollment_id
     * @param int $cycle_id
     * @return array
     */
    public function get_team_members($mentor_enrollment_id, $cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // Find mentor's team.
        $team_id = $wpdb->get_var($wpdb->prepare(
            "SELECT tm.team_id FROM {$prefix}hl_team_membership tm
             JOIN {$prefix}hl_team t ON tm.team_id = t.team_id
             WHERE tm.enrollment_id = %d AND t.cycle_id = %d LIMIT 1",
            $mentor_enrollment_id, $cycle_id
        ));

        if (!$team_id) {
            return array();
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, u.display_name, u.user_email, e.roles,
                    pa.pathway_id,
                    p.pathway_name,
                    ROUND(IFNULL(
                        (SELECT COUNT(*) FROM {$prefix}hl_component_state cs
                         JOIN {$prefix}hl_component c ON cs.component_id = c.component_id
                         WHERE cs.enrollment_id = e.enrollment_id AND c.pathway_id = pa.pathway_id AND cs.completion_status = 'complete')
                        * 100.0 /
                        NULLIF((SELECT COUNT(*) FROM {$prefix}hl_component c2 WHERE c2.pathway_id = pa.pathway_id AND c2.status = 'active'), 0)
                    , 0)) AS completion_pct
             FROM {$prefix}hl_team_membership tm
             JOIN {$prefix}hl_enrollment e ON tm.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$prefix}hl_pathway_assignment pa ON pa.enrollment_id = e.enrollment_id
             LEFT JOIN {$prefix}hl_pathway p ON pa.pathway_id = p.pathway_id
             WHERE tm.team_id = %d AND e.status = 'active'
             ORDER BY u.display_name ASC",
            $team_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get RP sessions for a mentor (as lead/observed teacher).
     *
     * @param int $mentor_enrollment_id
     * @param int $cycle_id
     * @return array
     */
    public function get_mentor_rp_sessions($mentor_enrollment_id, $cycle_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT rs.*, mentor.display_name AS mentor_name, teacher.display_name AS teacher_name
             FROM {$prefix}hl_rp_session rs
             LEFT JOIN {$prefix}hl_enrollment me ON rs.mentor_enrollment_id = me.enrollment_id
             LEFT JOIN {$wpdb->users} mentor ON me.user_id = mentor.ID
             LEFT JOIN {$prefix}hl_enrollment te ON rs.teacher_enrollment_id = te.enrollment_id
             LEFT JOIN {$wpdb->users} teacher ON te.user_id = teacher.ID
             WHERE rs.mentor_enrollment_id = %d AND rs.cycle_id = %d
             ORDER BY rs.session_date DESC",
            $mentor_enrollment_id, $cycle_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Get aggregated report data across all mentors for a coach.
     *
     * @param int   $coach_user_id
     * @param array $filters Optional: cycle_id, school_id
     * @return array
     */
    public function get_aggregated_report($coach_user_id, $filters = array()) {
        $mentors = $this->get_mentors_for_coach($coach_user_id);

        // Apply filters.
        if (!empty($filters['cycle_id'])) {
            $mentors = array_filter($mentors, function ($m) use ($filters) {
                return (int) $m['cycle_id'] === (int) $filters['cycle_id'];
            });
        }
        if (!empty($filters['school_name'])) {
            $mentors = array_filter($mentors, function ($m) use ($filters) {
                return $m['school_name'] === $filters['school_name'];
            });
        }

        return array_values($mentors);
    }

    /**
     * Get active cycle IDs where this coach has assignments.
     *
     * @param int $coach_user_id
     * @return int[]
     */
    public function get_coach_cycle_ids($coach_user_id) {
        global $wpdb;
        $today = current_time('Y-m-d');

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT cycle_id FROM {$wpdb->prefix}hl_coach_assignment
             WHERE coach_user_id = %d
               AND effective_from <= %s
               AND (effective_to IS NULL OR effective_to >= %s)",
            $coach_user_id, $today, $today
        ));

        return array_map('absint', $ids);
    }

    /**
     * Get distinct school names from a coach's mentor roster (for filters).
     *
     * @param int $coach_user_id
     * @return string[]
     */
    public function get_coach_school_options($coach_user_id) {
        $mentors = $this->get_mentors_for_coach($coach_user_id);
        $schools = array();
        foreach ($mentors as $m) {
            if ($m['school_name'] !== '—') {
                $schools[$m['school_name']] = true;
            }
        }
        ksort($schools);
        return array_keys($schools);
    }

    // =========================================================================
    // Availability CRUD
    // =========================================================================

    /**
     * Get availability blocks for a coach.
     *
     * @param int $coach_user_id
     * @return array
     */
    public function get_availability($coach_user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_coach_availability
             WHERE coach_user_id = %d ORDER BY day_of_week ASC, start_time ASC",
            $coach_user_id
        ), ARRAY_A) ?: array();
    }

    /**
     * Save availability blocks (replace all for this coach).
     *
     * @param int   $coach_user_id
     * @param array $blocks Array of {day_of_week: 0-6, start_time: 'HH:MM', end_time: 'HH:MM'}
     * @return bool
     */
    public function save_availability($coach_user_id, $blocks) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_coach_availability';

        // Delete existing.
        $wpdb->delete($table, array('coach_user_id' => $coach_user_id));

        // Insert new blocks.
        foreach ($blocks as $block) {
            $wpdb->insert($table, array(
                'coach_user_id' => absint($coach_user_id),
                'day_of_week'   => absint($block['day_of_week']),
                'start_time'    => sanitize_text_field($block['start_time']),
                'end_time'      => sanitize_text_field($block['end_time']),
            ));
        }

        return true;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-coach-dashboard-service.php
git commit -m "feat: add HL_Coach_Dashboard_Service with stats, mentor queries, availability CRUD"
```

---

## Task 2: DB migration — hl_coach_availability table

**Files:**
- Modify: `includes/class-hl-installer.php` — add table to `get_schema()` + add migration method

- [ ] **Step 1: Add hl_coach_availability to get_schema()**

Find the last `$tables[] = "CREATE TABLE ...";` line in `get_schema()` (before the `return $tables;`). Add immediately before the return:

```php
        // Coach availability (recurring weekly schedule).
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_coach_availability (
            availability_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            coach_user_id bigint(20) unsigned NOT NULL,
            day_of_week tinyint(1) unsigned NOT NULL COMMENT '0=Sun, 6=Sat',
            start_time time NOT NULL,
            end_time time NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (availability_id),
            KEY coach_user_id (coach_user_id),
            KEY coach_day (coach_user_id, day_of_week)
        ) $charset_collate;";
```

- [ ] **Step 2: Bump schema revision to trigger dbDelta**

In `maybe_upgrade()` (around line 127), change:
```php
        $current_revision = 22;
```
to:
```php
        $current_revision = 23;
```

No additional migration method is needed — `create_tables()` calls `dbDelta()` which picks up the new table from `get_schema()`.

- [ ] **Step 3: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat: add hl_coach_availability table to installer schema + migration"
```

---

## Task 3: Coach Dashboard page [hl_coach_dashboard]

**Files:**
- Create: `includes/frontend/class-hl-frontend-coach-dashboard.php`

- [ ] **Step 1: Create the Coach Dashboard renderer**

Build the page with:
- Gradient hero header with welcome greeting + avatar (like `hlcv-hero` pattern)
- 3 stats cards (assigned mentors, upcoming sessions, sessions this month)
- Quick link cards to other coach pages
- CSS prefix: `hlcd-`
- Role check: only show to users with WP `coach` role

Key structure:
```php
class HL_Frontend_Coach_Dashboard {
    public function render($atts) {
        ob_start();
        $user_id = get_current_user_id();
        $user = wp_get_current_user();

        if (!in_array('coach', (array) $user->roles, true) && !current_user_can('manage_hl_core')) {
            echo '<div class="hl-notice hl-notice-error">...</div>';
            return ob_get_clean();
        }

        $service = new HL_Coach_Dashboard_Service();
        $stats = $service->get_dashboard_stats($user_id);

        // Render: hero header, stats cards, quick links
        $this->render_styles();
        // ... HTML ...
        return ob_get_clean();
    }

    private function render_styles() { /* inline <style> with hlcd- prefix */ }
}
```

The hero header should follow this design:
- Background: `linear-gradient(135deg, #1e3a5f 0%, #2d5f8a 100%)` (matches Classroom Visit)
- Avatar on left, "Welcome back, [First Name]" title, "Coach Dashboard" subtitle
- Stats cards below in a 3-column grid with icons, large numbers, labels
- Quick links as icon cards linking to My Mentors, Reports, Availability pages

Include a `private function find_shortcode_page_url($shortcode)` method in the class (copy from any existing frontend class — e.g., `HL_Frontend_Coaching_Hub`). This is the established pattern — each frontend class has its own copy.

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-coach-dashboard.php
git commit -m "feat: add Coach Dashboard shortcode page with hero, stats, quick links"
```

---

## Task 4: My Mentors page [hl_coach_mentors]

**Files:**
- Create: `includes/frontend/class-hl-frontend-coach-mentors.php`

- [ ] **Step 1: Create the My Mentors renderer**

Build the page with:
- Gradient hero header ("My Mentors", mentors icon)
- Search bar + school dropdown filter
- Card grid of mentors. Each card shows:
  - Avatar, name, school, team name
  - Pathway progress bar (% complete)
  - Last session date, next scheduled session
  - Click → Mentor Detail page (`?mentor_enrollment_id=X`)
- CSS prefix: `hlcm-`
- JS: client-side search/filter (same pattern as Coaching Hub)

Key structure:
```php
class HL_Frontend_Coach_Mentors {
    public function render($atts) {
        ob_start();
        // Role check (coach or admin)
        $service = new HL_Coach_Dashboard_Service();
        $mentors = $service->get_mentors_for_coach($user_id);
        $schools = $service->get_coach_school_options($user_id);
        $detail_url = HL_Frontend_My_Coaching::find_shortcode_page_url('hl_coach_mentor_detail');

        // Render: hero, filters, card grid
        return ob_get_clean();
    }
}
```

Mentor cards design:
- White card with subtle border, 8px border-radius
- Progress bar: colored fill bar (green gradient) with % text
- "Last Session" and "Next Session" as small gray meta text
- Hover: subtle shadow lift (`box-shadow` + `translateY(-2px)`)
- Link wraps the entire card (clickable)

Include a `private function find_shortcode_page_url($shortcode)` method in the class (same pattern as Task 3).

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-coach-mentors.php
git commit -m "feat: add My Mentors shortcode page with card grid, search, school filter"
```

---

## Task 5: Mentor Detail page [hl_coach_mentor_detail]

**Files:**
- Create: `includes/frontend/class-hl-frontend-coach-mentor-detail.php`

- [ ] **Step 1: Create the Mentor Detail renderer**

Build the page with:
- Query param: `?mentor_enrollment_id=X`
- Header card: mentor name, school, team, pathway, completion % bar
- 4 tabs (JS-switched, no page reload):
  1. **Coaching Sessions** — list of sessions with status badges, links to session detail
  2. **Team Overview** — team members with progress bars
  3. **RP Sessions** — RP sessions with teacher names, status
  4. **Reports** — completion data table + CSV export button
- CSS prefix: `hlcmd-`
- Back link: "← Back to My Mentors" (use `find_shortcode_page_url('hl_coach_mentors')`)
- Include a `private function find_shortcode_page_url($shortcode)` method (same pattern as Task 3)

Tab switching pattern (vanilla JS):
```javascript
document.querySelectorAll('.hlcmd-tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        // Hide all panels, show target, toggle active class
    });
});
```

For the Reports tab CSV export, use a form POST with nonce:
```php
// In __construct or shortcodes registration:
add_action('template_redirect', array('HL_Frontend_Coach_Mentor_Detail', 'handle_export'));

public static function handle_export() {
    if (!isset($_POST['hl_coach_mentor_export_nonce'])) return;
    if (!wp_verify_nonce($_POST['hl_coach_mentor_export_nonce'], 'hl_coach_mentor_export')) return;
    // Build CSV, send headers, exit
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-coach-mentor-detail.php
git commit -m "feat: add Mentor Detail shortcode page with 4 tabs (sessions, team, RP, reports)"
```

---

## Task 6: Coach Reports page [hl_coach_reports]

**Files:**
- Create: `includes/frontend/class-hl-frontend-coach-reports.php`

- [ ] **Step 1: Create the Coach Reports renderer**

Build the page with:
- Gradient hero header ("Coach Reports", chart icon)
- Filter bar: cycle dropdown, school dropdown
- Summary table: mentor name, school, pathway, % complete
- Team comparison section: grouped by team, average completion
- CSV export button (form POST with nonce)
- CSS prefix: `hlcr-`

Include a `private function find_shortcode_page_url($shortcode)` method (same pattern as Task 3).

CSV export handler:
```php
public static function handle_export() {
    if (!isset($_POST['hl_coach_report_export_nonce'])) return;
    if (!wp_verify_nonce($_POST['hl_coach_report_export_nonce'], 'hl_coach_report_export')) return;

    $user_id = get_current_user_id();
    $user = wp_get_current_user();
    if (!in_array('coach', (array) $user->roles, true) && !current_user_can('manage_hl_core')) return;

    $service = new HL_Coach_Dashboard_Service();
    $mentors = $service->get_aggregated_report($user_id, array(
        'cycle_id'    => absint($_POST['filter_cycle_id'] ?? 0),
        'school_name' => sanitize_text_field($_POST['filter_school'] ?? ''),
    ));

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=coach-report-' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('Mentor', 'School', 'Team', 'Pathway', 'Completion %'));
    foreach ($mentors as $m) {
        fputcsv($out, array($m['display_name'], $m['school_name'], $m['team_name'], $m['pathway_name'], $m['completion_pct'] . '%'));
    }
    fclose($out);
    exit;
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-coach-reports.php
git commit -m "feat: add Coach Reports shortcode page with filters, summary table, CSV export"
```

---

## Task 7: Coach Availability page [hl_coach_availability]

**Files:**
- Create: `includes/frontend/class-hl-frontend-coach-availability.php`

- [ ] **Step 1: Create the Coach Availability renderer**

Build the page with:
- Gradient hero header ("My Availability", calendar icon)
- Weekly grid: 7 columns (Mon-Sun), rows for each 30-min slot (7:00 AM to 7:00 PM)
- Click to toggle slots on/off (JS toggles CSS class + hidden input)
- Save button (form POST with nonce)
- Pre-populate from existing `hl_coach_availability` records
- CSS prefix: `hlca-`

Grid design:
- Day headers at top, time labels on left
- Each cell is a 30-min block
- Selected/active blocks: colored (blue gradient)
- Inactive: light gray
- Hover: subtle highlight

JavaScript interaction:
```javascript
// Toggle cell on click
document.querySelectorAll('.hlca-cell').forEach(function(cell) {
    cell.addEventListener('click', function() {
        this.classList.toggle('hlca-active');
        // Update hidden input with all active blocks as JSON
        updateAvailabilityData();
    });
});

function updateAvailabilityData() {
    var blocks = [];
    document.querySelectorAll('.hlca-cell.hlca-active').forEach(function(cell) {
        blocks.push({
            day_of_week: parseInt(cell.dataset.day),
            start_time: cell.dataset.start,
            end_time: cell.dataset.end
        });
    });
    document.getElementById('hlca-data').value = JSON.stringify(blocks);
}
```

The class must include a `handle_post_actions()` static method (called via `template_redirect`):

```php
public static function handle_post_actions() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['hl_coach_availability_nonce'])) {
        return;
    }
    if (!wp_verify_nonce($_POST['hl_coach_availability_nonce'], 'hl_coach_availability_save')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('coach', (array) $user->roles, true) && !current_user_can('manage_hl_core')) {
        return;
    }

    $blocks_json = sanitize_text_field($_POST['availability_data'] ?? '[]');
    $blocks = json_decode($blocks_json, true);
    if (!is_array($blocks)) {
        $blocks = array();
    }

    // Validate each block.
    $valid_blocks = array();
    foreach ($blocks as $block) {
        $day = isset($block['day_of_week']) ? absint($block['day_of_week']) : -1;
        if ($day < 0 || $day > 6) continue;
        if (empty($block['start_time']) || empty($block['end_time'])) continue;
        $valid_blocks[] = array(
            'day_of_week' => $day,
            'start_time'  => sanitize_text_field($block['start_time']),
            'end_time'    => sanitize_text_field($block['end_time']),
        );
    }

    $service = new HL_Coach_Dashboard_Service();
    $service->save_availability($user->ID, $valid_blocks);

    $redirect_url = add_query_arg('hl_msg', 'availability_saved', remove_query_arg('hl_msg'));
    wp_safe_redirect($redirect_url);
    exit;
}
```

Also include a `private function find_shortcode_page_url($shortcode)` method (copy from any existing frontend class).

- [ ] **Step 2: Commit**

```bash
git add includes/frontend/class-hl-frontend-coach-availability.php
git commit -m "feat: add Coach Availability shortcode page with weekly schedule grid"
```

---

## Task 8: Wire everything together (registration + autoload + nav)

**Files:**
- Modify: `hl-core.php` — add require_once lines
- Modify: `includes/frontend/class-hl-shortcodes.php` — register shortcodes + enqueue + template_redirect
- Modify: `includes/cli/class-hl-cli-create-pages.php` — add page definitions
- Modify: `includes/integrations/class-hl-buddyboss-integration.php` — add coach menu items

- [ ] **Step 1: Add require_once lines to hl-core.php**

After the existing `require_once` block for frontend files (after line ~182), add:

```php
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-coach-dashboard.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-coach-mentors.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-coach-mentor-detail.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-coach-reports.php';
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-coach-availability.php';
```

In the services section (find existing service requires), add:

```php
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-coach-dashboard-service.php';
```

- [ ] **Step 2: Register 5 shortcodes in HL_Shortcodes**

In `register_shortcodes()`, add after the `hl_docs` line:

```php
        add_shortcode('hl_coach_dashboard', array($this, 'render_coach_dashboard'));
        add_shortcode('hl_coach_mentors', array($this, 'render_coach_mentors'));
        add_shortcode('hl_coach_mentor_detail', array($this, 'render_coach_mentor_detail'));
        add_shortcode('hl_coach_reports', array($this, 'render_coach_reports'));
        add_shortcode('hl_coach_availability', array($this, 'render_coach_availability'));
```

In the `__construct()` method, add template_redirect hooks:

```php
        add_action('template_redirect', array('HL_Frontend_Coach_Mentor_Detail', 'handle_export'));
        add_action('template_redirect', array('HL_Frontend_Coach_Reports', 'handle_export'));
        add_action('template_redirect', array('HL_Frontend_Coach_Availability', 'handle_post_actions'));
```

Add the 5 render methods (follow existing pattern):

```php
    public function render_coach_dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view your dashboard.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_coach_dashboard');
        $renderer = new HL_Frontend_Coach_Dashboard();
        return $renderer->render($atts);
    }

    public function render_coach_mentors($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_coach_mentors');
        $renderer = new HL_Frontend_Coach_Mentors();
        return $renderer->render($atts);
    }

    public function render_coach_mentor_detail($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_coach_mentor_detail');
        $renderer = new HL_Frontend_Coach_Mentor_Detail();
        return $renderer->render($atts);
    }

    public function render_coach_reports($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_coach_reports');
        $renderer = new HL_Frontend_Coach_Reports();
        return $renderer->render($atts);
    }

    public function render_coach_availability($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $atts = shortcode_atts(array(), $atts, 'hl_coach_availability');
        $renderer = new HL_Frontend_Coach_Availability();
        return $renderer->render($atts);
    }
```

In `enqueue_assets()`, add the 5 new shortcodes to the `$has_shortcode` detection chain:

```php
            || has_shortcode($post->post_content, 'hl_coach_dashboard')
            || has_shortcode($post->post_content, 'hl_coach_mentors')
            || has_shortcode($post->post_content, 'hl_coach_mentor_detail')
            || has_shortcode($post->post_content, 'hl_coach_reports')
            || has_shortcode($post->post_content, 'hl_coach_availability')
```

- [ ] **Step 3: Add page definitions to create-pages CLI**

In `get_page_definitions()`, add in the "Hub / workspace pages" section:

```php
            // Coach pages
            array( 'title' => 'Coach Dashboard',     'shortcode' => 'hl_coach_dashboard' ),
            array( 'title' => 'My Mentors',           'shortcode' => 'hl_coach_mentors' ),
            array( 'title' => 'Mentor Detail',        'shortcode' => 'hl_coach_mentor_detail' ),
            array( 'title' => 'Coach Reports',        'shortcode' => 'hl_coach_reports' ),
            array( 'title' => 'Coach Availability',   'shortcode' => 'hl_coach_availability' ),
```

- [ ] **Step 4: Add coach menu items to BuddyBoss sidebar**

In `build_menu_items()`, detect coach role and add 3 sidebar items. After the existing role detection (`$is_leader`, `$is_mentor`, `$is_teacher`), add:

```php
        $is_coach = in_array('coach', (array) wp_get_current_user()->roles, true);
```

Then add to `$menu_def` array (after the coaching-hub entry):

```php
            // --- Coach tools ---
            array('coach-dashboard', 'hl_coach_dashboard',   __('Coach Dashboard', 'hl-core'), 'dashicons-dashboard',    $is_coach),
            array('coach-mentors',   'hl_coach_mentors',     __('My Mentors', 'hl-core'),      'dashicons-groups',       $is_coach),
            array('coach-reports',   'hl_coach_reports',     __('Coach Reports', 'hl-core'),   'dashicons-chart-bar',    $is_coach),
```

(Mentor Detail and Availability are navigated to, not in the sidebar.)

**IMPORTANT:** Also modify `get_menu_items_for_current_user()` (around line 741). The method currently returns early if `!$has_enrollment && !$is_staff`. Coaches may have no HL enrollment and no staff capability, so add a coach check to the early return guard:

```php
        $is_coach = in_array('coach', (array) wp_get_current_user()->roles, true);

        if (!$has_enrollment && !$is_staff && !$is_coach) {
            $cached = array();
            return $cached;
        }
```

Pass `$is_coach` into `build_menu_items()` or detect it there as shown above.

- [ ] **Step 5: Commit**

```bash
git add hl-core.php includes/frontend/class-hl-shortcodes.php includes/cli/class-hl-cli-create-pages.php includes/integrations/class-hl-buddyboss-integration.php
git commit -m "feat: wire up 5 coach shortcodes, page definitions, BuddyBoss sidebar nav"
```

---

## Task 9: Update STATUS.md and README.md

**Files:**
- Modify: `STATUS.md` — add coach pages section to build queue
- Modify: `README.md` — add to "What's Implemented" section

- [ ] **Step 1: Add coach pages to STATUS.md build queue**

Add a new section after "Cross-Pathway Events, Forms & Coaching":

```markdown
### Coach Frontend Pages (Active — March 2026)
- [x] **Coach Dashboard Service** — Data queries for stats, mentor roster, availability CRUD.
- [x] **DB: hl_coach_availability table** — Weekly recurring schedule blocks for coaches.
- [x] **Coach Dashboard [hl_coach_dashboard]** — Welcome hero, stats cards, quick links. Coach role only.
- [x] **My Mentors [hl_coach_mentors]** — Card grid of assigned mentors with search/filter.
- [x] **Mentor Detail [hl_coach_mentor_detail]** — 4-tab mentor profile (sessions, team, RP, reports + CSV). **Note:** The HL User Profile (`[hl_user_profile]`) now exists as the unified profile page for all users (6 tabs). Coach Mentor Detail is kept as a coach-specific quick view per plan Option A.
- [x] **Coach Reports [hl_coach_reports]** — Aggregated completion table with cycle/school filters, CSV export.
- [x] **Coach Availability [hl_coach_availability]** — Weekly schedule grid with 30-min toggle blocks.
- [x] **Wiring** — 5 shortcodes registered, 5 pages in create-pages CLI, 3 BuddyBoss sidebar items for Coach role.
```

- [ ] **Step 2: Update README.md "What's Implemented"**

Add the 5 new shortcode pages and 1 new service to the relevant sections.

- [ ] **Step 3: Commit**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md and README.md with coach frontend pages"
```

---

## Design Reference: CSS Color Palette & Patterns

All coach pages share this design language (consistent with Classroom Visit form):

| Token | Value | Usage |
|-------|-------|-------|
| Hero gradient | `linear-gradient(135deg, #1e3a5f 0%, #2d5f8a 100%)` | Page headers |
| Card bg | `#fff` | Card backgrounds |
| Card border | `#e2e8f0` | Card borders |
| Card hover shadow | `0 8px 25px rgba(0,0,0,.08)` | Card hover state |
| Progress bar bg | `#e2e8f0` | Progress bar track |
| Progress bar fill | `linear-gradient(90deg, #059669, #10b981)` | Progress bar fill |
| Info label | `#8896a6`, 11px, uppercase | Small labels |
| Info value | `#1e293b`, 15px, font-weight 600 | Data values |
| Badge green | `background: #d1fae5; color: #065f46` | Attended/complete |
| Badge blue | `background: #dbeafe; color: #1e40af` | Scheduled |
| Badge gray | `background: #f1f5f9; color: #64748b` | Missed/cancelled |
| Badge orange | `background: #fef3c7; color: #92400e` | In progress |
| Font stack | `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif` | All text |
| Max width | `1100px` (dashboard/reports), `820px` (forms) | Page wrappers |
| Border radius | `16px` (hero), `14px` (cards), `10px` (buttons), `8px` (badges) | Consistent rounding |
| Responsive breakpoint | `600px` | Mobile layout shift |

## Execution Notes

- **No test framework** — this is a WordPress plugin. Verification is visual (deploy to test server).
- **Commit after each page** — tasks 1-7 each get their own commit, task 8 is the wiring commit.
- **Deploy with** `wp hl-core create-pages` after all code is pushed to create the WordPress pages.
- **Coach WP role** — uses WordPress native `coach` role, checked via `in_array('coach', $user->roles)`. This role already exists in the system (seeded for Lauren Orf demo coach).
