# Guided Tours System — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an unlimited, multi-page guided tour system inside HL Core with visual element picker, role-based targeting, and global styling.

**Architecture:** Driver.js (MIT, 5KB) as frontend tour engine. 3 new DB tables (`hl_tour`, `hl_tour_step`, `hl_tour_seen`). Repository + Service pattern. Admin UI as Settings hub tab. Frontend JS controller for multi-page state, auto-trigger, and topbar "?" button.

**Tech Stack:** PHP 7.4+ (WordPress), Driver.js 1.4.0, jQuery (admin), vanilla JS (frontend), WordPress Settings API, `$wpdb` + `dbDelta`.

**Spec:** `docs/superpowers/specs/2026-04-03-guided-tours-design.md` — read this FIRST for full context.

---

## Codebase Conventions Reference

Before implementing, study these patterns:

- **Schema:** `includes/class-hl-installer.php` — `get_schema()` method (line ~1085). Tables use `{$wpdb->prefix}hl_*`. PK naming: `{entity}_id bigint(20) unsigned NOT NULL AUTO_INCREMENT`. Always include `created_at`/`updated_at` with `DEFAULT CURRENT_TIMESTAMP`. Current revision: **28** (line 130).
- **Repository:** `includes/domain/repositories/class-hl-enrollment-repository.php` — Instance methods, `private function table()` helper, `$wpdb->insert/update/delete/get_results`. Returns domain objects or null. JSON fields encoded via `HL_DB_Utils::json_encode()`.
- **Service:** `includes/services/class-hl-scheduling-service.php` — Singleton via `instance()`. AJAX hooks in `__construct()`. Pattern: `check_ajax_referer()` → sanitize → validate → delegate → `wp_send_json_success/error()`.
- **Admin Settings:** `includes/admin/class-hl-admin-settings.php` — Tabs array in `render_tabs()`, dispatch in `handle_early_actions()`, each tab is a singleton class with `handle_save()` + `render_page_content()`.
- **Core Loader:** `hl-core.php` — `load_dependencies()` requires files, `init()` instantiates services on `plugins_loaded`. Files loaded in dependency order.
- **Frontend Template:** `templates/hl-page.php` — Custom HTML shell bypassing theme. Topbar at line ~100, sidebar at ~143, content at ~183. Assets loaded directly (not via `wp_head`).
- **CSS:** `assets/css/frontend.css` — Design tokens as `:root` variables (`--hl-*`). BEM-like classes: `.hl-{component}__{element}`, `.hl-{component}--{modifier}`.
- **JS:** `assets/js/frontend.js` — jQuery IIFE wrapper. Dropdowns use `hidden` attr + `aria-expanded`. LocalStorage for state persistence.

---

## Phase 1: Database Schema + Repository

### Task 1.1: Add Three Tables to Installer Schema

**Files:**
- Modify: `includes/class-hl-installer.php`

- [ ] **Step 1: Read the current installer**

Read `includes/class-hl-installer.php`. Find `get_schema()` method (~line 1085) and `maybe_upgrade()` method (~line 127). Note the last table definition and the current `$current_revision = 28`.

- [ ] **Step 2: Add hl_tour table to get_schema()**

Add at the end of the `$tables` array in `get_schema()`, before the `return $tables;`:

```php
// --- Guided Tours ---
$tables[] = "CREATE TABLE {$wpdb->prefix}hl_tour (
    tour_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    title varchar(255) NOT NULL,
    slug varchar(100) NOT NULL,
    trigger_type enum('first_login','page_visit','manual_only') NOT NULL,
    trigger_page_url varchar(500) NULL,
    target_roles text NULL COMMENT 'JSON array of HL roles, NULL = all',
    start_page_url varchar(500) NOT NULL,
    status enum('active','draft','archived') NOT NULL DEFAULT 'draft',
    hide_on_mobile tinyint(1) NOT NULL DEFAULT 0,
    sort_order int NOT NULL DEFAULT 0,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tour_id),
    UNIQUE KEY slug (slug),
    KEY status (status),
    KEY trigger_type (trigger_type),
    KEY sort_order (sort_order)
) $charset_collate;";

$tables[] = "CREATE TABLE {$wpdb->prefix}hl_tour_step (
    step_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    tour_id bigint(20) unsigned NOT NULL,
    step_order int NOT NULL DEFAULT 0,
    title varchar(255) NOT NULL,
    description text NOT NULL,
    page_url varchar(500) NULL,
    target_selector varchar(500) NULL,
    position enum('top','bottom','left','right','auto') NOT NULL DEFAULT 'auto',
    step_type enum('informational','interactive') NOT NULL DEFAULT 'informational',
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (step_id),
    KEY tour_step_order (tour_id, step_order)
) $charset_collate;";

$tables[] = "CREATE TABLE {$wpdb->prefix}hl_tour_seen (
    seen_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL,
    tour_id bigint(20) unsigned NOT NULL,
    seen_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (seen_id),
    UNIQUE KEY user_tour (user_id, tour_id),
    KEY tour_id (tour_id)
) $charset_collate;";
```

- [ ] **Step 3: Bump schema revision**

In `maybe_upgrade()`, change `$current_revision = 28;` to `$current_revision = 29;`.

No migration method needed — `dbDelta()` handles new table creation automatically. Only add a migration block if modifying existing tables.

- [ ] **Step 4: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat(tours): add hl_tour, hl_tour_step, hl_tour_seen tables — schema rev 29"
```

---

### Task 1.2: Create HL_Tour_Repository

**Files:**
- Create: `includes/domain/repositories/class-hl-tour-repository.php`

- [ ] **Step 1: Create the repository class**

Create `includes/domain/repositories/class-hl-tour-repository.php`. Follow the pattern from `class-hl-enrollment-repository.php`.

```php
<?php
/**
 * Repository for hl_tour, hl_tour_step, and hl_tour_seen tables.
 */
class HL_Tour_Repository {

    private function tour_table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_tour';
    }

    private function step_table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_tour_step';
    }

    private function seen_table() {
        global $wpdb;
        return $wpdb->prefix . 'hl_tour_seen';
    }

    // ─── Tour CRUD ───

    public function get_all_tours( $filters = array() ) {
        global $wpdb;

        $sql    = "SELECT t.*, (SELECT COUNT(*) FROM {$this->step_table()} s WHERE s.tour_id = t.tour_id) AS step_count FROM {$this->tour_table()} t";
        $where  = array();
        $values = array();

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 't.status = %s';
            $values[] = $filters['status'];
        }
        if ( ! empty( $filters['trigger_type'] ) ) {
            $where[]  = 't.trigger_type = %s';
            $values[] = $filters['trigger_type'];
        }

        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        $sql .= ' ORDER BY t.sort_order ASC, t.created_at DESC';

        if ( $values ) {
            $sql = $wpdb->prepare( $sql, $values );
        }
        return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
    }

    public function get_tour( $tour_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->tour_table()} WHERE tour_id = %d",
            $tour_id
        ), ARRAY_A );
    }

    public function get_tour_by_slug( $slug ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->tour_table()} WHERE slug = %s",
            $slug
        ), ARRAY_A );
    }

    public function create_tour( $data ) {
        global $wpdb;

        if ( isset( $data['target_roles'] ) && is_array( $data['target_roles'] ) ) {
            $data['target_roles'] = HL_DB_Utils::json_encode( $data['target_roles'] );
        }

        $wpdb->insert( $this->tour_table(), $data );
        return $wpdb->insert_id;
    }

    public function update_tour( $tour_id, $data ) {
        global $wpdb;

        if ( isset( $data['target_roles'] ) && is_array( $data['target_roles'] ) ) {
            $data['target_roles'] = HL_DB_Utils::json_encode( $data['target_roles'] );
        }

        $wpdb->update( $this->tour_table(), $data, array( 'tour_id' => $tour_id ) );
        return $this->get_tour( $tour_id );
    }

    public function delete_tour( $tour_id ) {
        global $wpdb;
        // Delete steps first (cascade).
        $wpdb->delete( $this->step_table(), array( 'tour_id' => $tour_id ) );
        // Delete seen records.
        $wpdb->delete( $this->seen_table(), array( 'tour_id' => $tour_id ) );
        // Delete tour.
        return $wpdb->delete( $this->tour_table(), array( 'tour_id' => $tour_id ) );
    }

    public function duplicate_tour( $tour_id ) {
        $tour = $this->get_tour( $tour_id );
        if ( ! $tour ) {
            return false;
        }

        unset( $tour['tour_id'], $tour['created_at'], $tour['updated_at'] );
        $tour['title']  = $tour['title'] . ' (Copy)';
        $tour['slug']   = $tour['slug'] . '-copy-' . time();
        $tour['status'] = 'draft';

        $new_id = $this->create_tour( $tour );
        if ( ! $new_id ) {
            return false;
        }

        // Copy steps.
        $steps = $this->get_steps( $tour_id );
        foreach ( $steps as $step ) {
            unset( $step['step_id'], $step['created_at'], $step['updated_at'] );
            $step['tour_id'] = $new_id;
            $this->create_step( $step );
        }

        return $new_id;
    }

    // ─── Step CRUD ───

    public function get_steps( $tour_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->step_table()} WHERE tour_id = %d ORDER BY step_order ASC",
            $tour_id
        ), ARRAY_A ) ?: array();
    }

    public function get_step( $step_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->step_table()} WHERE step_id = %d",
            $step_id
        ), ARRAY_A );
    }

    public function create_step( $data ) {
        global $wpdb;
        $wpdb->insert( $this->step_table(), $data );
        return $wpdb->insert_id;
    }

    public function update_step( $step_id, $data ) {
        global $wpdb;
        $wpdb->update( $this->step_table(), $data, array( 'step_id' => $step_id ) );
        return $this->get_step( $step_id );
    }

    public function delete_step( $step_id ) {
        global $wpdb;
        return $wpdb->delete( $this->step_table(), array( 'step_id' => $step_id ) );
    }

    public function reorder_steps( $tour_id, $step_ids_in_order ) {
        global $wpdb;
        foreach ( $step_ids_in_order as $index => $step_id ) {
            $wpdb->update(
                $this->step_table(),
                array( 'step_order' => $index ),
                array( 'step_id' => absint( $step_id ), 'tour_id' => absint( $tour_id ) )
            );
        }
    }

    // ─── Seen Tracking ───

    public function mark_seen( $user_id, $tour_id ) {
        global $wpdb;
        // Use REPLACE INTO for idempotency (UNIQUE KEY handles dupes).
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$this->seen_table()} (user_id, tour_id, seen_at) VALUES (%d, %d, NOW())",
            $user_id, $tour_id
        ) );
    }

    public function has_seen( $user_id, $tour_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$this->seen_table()} WHERE user_id = %d AND tour_id = %d LIMIT 1",
            $user_id, $tour_id
        ) );
    }

    public function get_unseen_tour_ids( $user_id ) {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT t.tour_id FROM {$this->tour_table()} t
             WHERE t.status = 'active'
             AND t.tour_id NOT IN (
                 SELECT ts.tour_id FROM {$this->seen_table()} ts WHERE ts.user_id = %d
             )
             ORDER BY t.sort_order ASC",
            $user_id
        ) ) ?: array();
    }

    public function count_seen( $tour_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->seen_table()} WHERE tour_id = %d",
            $tour_id
        ) );
    }
}
```

- [ ] **Step 2: Register in core loader**

In `hl-core.php`, inside `load_dependencies()`, add the require (near the other repository requires):

```php
require_once HL_CORE_INCLUDES_DIR . 'domain/repositories/class-hl-tour-repository.php';
```

- [ ] **Step 3: Commit**

```bash
git add includes/domain/repositories/class-hl-tour-repository.php hl-core.php
git commit -m "feat(tours): add HL_Tour_Repository with CRUD for tours, steps, seen tracking"
```

---

### Task 1.3: Create HL_Tour_Service

**Files:**
- Create: `includes/services/class-hl-tour-service.php`
- Modify: `hl-core.php`

- [ ] **Step 1: Create the service class**

Create `includes/services/class-hl-tour-service.php`. Follow singleton + AJAX pattern from `class-hl-scheduling-service.php`.

```php
<?php
/**
 * Tour resolution, context matching, and AJAX endpoints for guided tours.
 */
class HL_Tour_Service {

    private static $instance = null;
    private $repo;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->repo = new HL_Tour_Repository();

        // Frontend AJAX (logged-in users).
        add_action( 'wp_ajax_hl_tour_mark_seen',  array( $this, 'ajax_mark_seen' ) );
        add_action( 'wp_ajax_hl_tour_get_steps',   array( $this, 'ajax_get_steps' ) );

        // Admin AJAX.
        add_action( 'wp_ajax_hl_tour_save_step_order', array( $this, 'ajax_save_step_order' ) );
    }

    // ─── Context Resolution ───

    /**
     * Get tours relevant to the current page + user.
     *
     * Returns array with two keys:
     *   'auto_trigger' => single tour array or null (the one to auto-start)
     *   'available'    => array of tours for the "?" dropdown
     *   'active_tour'  => full tour+steps if user has an in-progress tour (for cross-page resume)
     */
    public function get_tours_for_context( $page_url, $user_id, $user_roles = array(), $active_tour_slug = null ) {
        $result = array(
            'auto_trigger' => null,
            'available'    => array(),
            'active_tour'  => null,
        );

        // Normalize page URL to path only.
        $page_path = wp_parse_url( $page_url, PHP_URL_PATH );
        $page_path = rtrim( $page_path, '/' ) . '/';

        // Get all active tours.
        $all_tours = $this->repo->get_all_tours( array( 'status' => 'active' ) );

        foreach ( $all_tours as $tour ) {
            // Role check.
            if ( ! $this->user_matches_roles( $tour, $user_roles ) ) {
                continue;
            }

            // Mobile check delegated to JS (server doesn't know viewport).

            $tour['steps'] = $this->repo->get_steps( $tour['tour_id'] );

            // If this is the active in-progress tour, include it fully.
            if ( $active_tour_slug && $tour['slug'] === $active_tour_slug ) {
                $result['active_tour'] = $tour;
            }

            // Check if this tour is relevant to this page (for dropdown).
            $tour_start_path = rtrim( wp_parse_url( $tour['start_page_url'], PHP_URL_PATH ), '/' ) . '/';
            $has_step_on_page = false;
            foreach ( $tour['steps'] as $step ) {
                $step_path = $step['page_url']
                    ? rtrim( wp_parse_url( $step['page_url'], PHP_URL_PATH ), '/' ) . '/'
                    : $tour_start_path;
                if ( $step_path === $page_path ) {
                    $has_step_on_page = true;
                    break;
                }
            }

            if ( $tour_start_path === $page_path || $has_step_on_page ) {
                $result['available'][] = array(
                    'tour_id'      => (int) $tour['tour_id'],
                    'slug'         => $tour['slug'],
                    'title'        => $tour['title'],
                    'trigger_type' => $tour['trigger_type'],
                    'start_page_url' => $tour['start_page_url'],
                    'step_count'   => count( $tour['steps'] ),
                );
            }

            // Auto-trigger logic.
            if ( $this->repo->has_seen( $user_id, $tour['tour_id'] ) ) {
                continue; // Already seen — skip auto-trigger.
            }

            $should_auto = false;

            if ( $tour['trigger_type'] === 'first_login' ) {
                // Triggers on any page (it's a global onboarding tour).
                $should_auto = true;
            } elseif ( $tour['trigger_type'] === 'page_visit' ) {
                $trigger_path = $tour['trigger_page_url']
                    ? rtrim( wp_parse_url( $tour['trigger_page_url'], PHP_URL_PATH ), '/' ) . '/'
                    : '';
                if ( $trigger_path === $page_path ) {
                    $should_auto = true;
                }
            }
            // 'manual_only' never auto-triggers.

            if ( $should_auto && $result['auto_trigger'] === null ) {
                $result['auto_trigger'] = $tour;
            }
        }

        return $result;
    }

    private function user_matches_roles( $tour, $user_roles ) {
        if ( empty( $tour['target_roles'] ) ) {
            return true; // NULL = all roles.
        }
        $target = HL_DB_Utils::json_decode( $tour['target_roles'] );
        if ( ! is_array( $target ) || empty( $target ) ) {
            return true;
        }
        return ! empty( array_intersect( $user_roles, $target ) );
    }

    /**
     * Get the current user's HL roles from their active enrollments.
     */
    public function get_user_hl_roles( $user_id ) {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT roles FROM {$wpdb->prefix}hl_enrollment WHERE user_id = %d AND status = 'active'",
            $user_id
        ) );
        $roles = array();
        foreach ( $rows as $json ) {
            $decoded = HL_DB_Utils::json_decode( $json );
            if ( is_array( $decoded ) ) {
                $roles = array_merge( $roles, $decoded );
            }
        }
        // Also check WP capabilities for admin/coach.
        if ( user_can( $user_id, 'manage_hl_core' ) ) {
            $roles[] = 'admin';
        }
        return array_unique( $roles );
    }

    // ─── Global Styles ───

    public function get_global_styles() {
        $defaults = array(
            'tooltip_bg'      => '#ffffff',
            'title_color'     => '#1A2B47',
            'title_font_size' => 16,
            'desc_color'      => '#6B7280',
            'desc_font_size'  => 14,
            'btn_bg'          => '#6366f1',
            'btn_text_color'  => '#ffffff',
            'progress_color'  => '#6366f1',
        );
        $saved = get_option( 'hl_tour_styles', array() );
        if ( is_string( $saved ) ) {
            $saved = json_decode( $saved, true ) ?: array();
        }
        return wp_parse_args( $saved, $defaults );
    }

    public function save_global_styles( $styles ) {
        $clean = array(
            'tooltip_bg'      => sanitize_hex_color( $styles['tooltip_bg'] ?? '#ffffff' ),
            'title_color'     => sanitize_hex_color( $styles['title_color'] ?? '#1A2B47' ),
            'title_font_size' => absint( $styles['title_font_size'] ?? 16 ),
            'desc_color'      => sanitize_hex_color( $styles['desc_color'] ?? '#6B7280' ),
            'desc_font_size'  => absint( $styles['desc_font_size'] ?? 14 ),
            'btn_bg'          => sanitize_hex_color( $styles['btn_bg'] ?? '#6366f1' ),
            'btn_text_color'  => sanitize_hex_color( $styles['btn_text_color'] ?? '#ffffff' ),
            'progress_color'  => sanitize_hex_color( $styles['progress_color'] ?? '#6366f1' ),
        );
        update_option( 'hl_tour_styles', wp_json_encode( $clean ) );
        return $clean;
    }

    // ─── Validation ───

    public function validate_tour_can_activate( $tour_id ) {
        $steps = $this->repo->get_steps( $tour_id );
        if ( empty( $steps ) ) {
            return new WP_Error( 'no_steps', __( 'Cannot activate a tour with no steps.', 'hl-core' ) );
        }
        return true;
    }

    // ─── AJAX Handlers ───

    public function ajax_mark_seen() {
        check_ajax_referer( 'hl_tour_nonce', '_nonce' );

        $tour_id = absint( $_POST['tour_id'] ?? 0 );
        if ( ! $tour_id ) {
            wp_send_json_error( array( 'message' => __( 'Missing tour ID.', 'hl-core' ) ) );
        }

        $tour = $this->repo->get_tour( $tour_id );
        if ( ! $tour || $tour['status'] !== 'active' ) {
            wp_send_json_error( array( 'message' => __( 'Tour not found or inactive.', 'hl-core' ) ) );
        }

        $user_id    = get_current_user_id();
        $user_roles = $this->get_user_hl_roles( $user_id );
        if ( ! $this->user_matches_roles( $tour, $user_roles ) ) {
            wp_send_json_error( array( 'message' => __( 'Tour not available for your role.', 'hl-core' ) ) );
        }

        $this->repo->mark_seen( $user_id, $tour_id );
        wp_send_json_success( array( 'marked' => true ) );
    }

    public function ajax_get_steps() {
        check_ajax_referer( 'hl_tour_nonce', '_nonce' );

        $tour_id = absint( $_GET['tour_id'] ?? $_POST['tour_id'] ?? 0 );
        if ( ! $tour_id ) {
            wp_send_json_error( array( 'message' => __( 'Missing tour ID.', 'hl-core' ) ) );
        }

        $tour = $this->repo->get_tour( $tour_id );
        if ( ! $tour ) {
            wp_send_json_error( array( 'message' => __( 'Tour not found.', 'hl-core' ) ) );
        }

        // Role check.
        $user_roles = $this->get_user_hl_roles( get_current_user_id() );
        if ( ! $this->user_matches_roles( $tour, $user_roles ) ) {
            wp_send_json_error( array( 'message' => __( 'Tour not available for your role.', 'hl-core' ) ) );
        }

        $steps = $this->repo->get_steps( $tour_id );
        wp_send_json_success( array(
            'tour'  => $tour,
            'steps' => $steps,
        ) );
    }

    public function ajax_save_step_order() {
        check_ajax_referer( 'hl_tour_admin_nonce', '_nonce' );

        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hl-core' ) ) );
        }

        $tour_id  = absint( $_POST['tour_id'] ?? 0 );
        $step_ids = array_map( 'absint', $_POST['step_ids'] ?? array() );

        if ( ! $tour_id || empty( $step_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing data.', 'hl-core' ) ) );
        }

        $this->repo->reorder_steps( $tour_id, $step_ids );
        wp_send_json_success( array( 'reordered' => true ) );
    }
}
```

- [ ] **Step 2: Register service in core loader**

In `hl-core.php`:
1. In `load_dependencies()`, add: `require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-tour-service.php';`
2. In `init()`, add: `HL_Tour_Service::instance();`

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-hl-tour-service.php hl-core.php
git commit -m "feat(tours): add HL_Tour_Service with context resolution + AJAX endpoints"
```

---

## Phase 2A: Admin UI

### Task 2A.1: Admin Tours Tab — Tours List

**Files:**
- Create: `includes/admin/class-hl-admin-tours.php`
- Modify: `includes/admin/class-hl-admin-settings.php`
- Modify: `hl-core.php`

- [ ] **Step 1: Read the admin settings hub**

Read `includes/admin/class-hl-admin-settings.php` to understand the tab registration pattern. Note the `render_tabs()` array, `handle_early_actions()` switch, and `render_page()` dispatch.

- [ ] **Step 2: Create HL_Admin_Tours class**

Create `includes/admin/class-hl-admin-tours.php` with singleton pattern. This class handles all three subtabs: Tours List, Tour Editor, Tour Styles.

The class should implement:
- `instance()` singleton.
- `handle_save()` — dispatches POST actions (create tour, update tour, save step, save styles, archive, duplicate).
- `render_page_content()` — renders the active subtab (list/editor/styles).
- `render_list()` — table of tours with status filter pills, row actions.
- `render_editor()` — tour settings form + steps section (collapsible cards, sortable).
- `render_styles()` — global style settings with WP Iris color pickers.

Follow the exact patterns from `class-hl-admin-scheduling-settings.php` for form rendering, nonce fields, and save handling. Use `wp_nonce_field('hl_tour_admin', 'hl_tour_admin_nonce')` for all forms.

**Tours List render:** Display an HTML table with columns: Title (linked to editor), Trigger Type (badge), Target Roles, Status (badge), Steps (count), Sort Order. Row actions: Edit | Duplicate | Archive. Status filter pills at top: All / Active / Draft / Archived. "Add Tour" button links to editor with no `tour_id`.

**Key patterns to follow:**
- Status badges: `<span class="hl-badge hl-badge--{status}">` matching existing admin badge CSS.
- Row actions: `<div class="row-actions">` with pipe-separated links (standard WP pattern).
- Filter pills: `<ul class="subsubsub">` with count in parentheses.

- [ ] **Step 3: Register Tours tab in settings hub**

In `class-hl-admin-settings.php`:
1. Add `'tours' => __('Tours', 'hl-core')` to the `$tabs` array in `render_tabs()`.
2. Add case in `handle_early_actions()`:
   ```php
   case 'tours':
       if ( isset( $_POST['hl_tour_admin_nonce'] ) ) {
           HL_Admin_Tours::instance()->handle_save();
       }
       break;
   ```
3. Add case in `render_page()`:
   ```php
   case 'tours':
       HL_Admin_Tours::instance()->render_page_content();
       break;
   ```

- [ ] **Step 4: Register in core loader**

In `hl-core.php`, `load_dependencies()`:
```php
require_once HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-tours.php';
```

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-hl-admin-tours.php includes/admin/class-hl-admin-settings.php hl-core.php
git commit -m "feat(tours): add Tours admin tab with tours list view"
```

---

### Task 2A.2: Admin Tours Tab — Tour Editor

**Files:**
- Modify: `includes/admin/class-hl-admin-tours.php`
- Create: `assets/js/hl-tour-admin.js`
- Modify: `assets/css/admin.css`

- [ ] **Step 1: Implement render_editor()**

Add `render_editor()` method to `HL_Admin_Tours`. This renders when `$_GET['subtab'] === 'editor'`. Detect `$tour_id = absint( $_GET['tour_id'] ?? 0 )` — if 0, creating new; otherwise editing.

**Tour Settings section:**
- Title: `<input type="text" name="tour_title" required>` with `sanitize_text_field` on save.
- Slug: `<input type="text" name="tour_slug">` auto-generated from title (JS), editable. Pattern: `sanitize_title()` on save.
- Status: `<select name="tour_status">` with draft/active/archived options. On save, if setting to `active`, call `HL_Tour_Service::instance()->validate_tour_can_activate()` — show error if fails.
- Trigger Type: `<select name="tour_trigger_type">` with first_login/page_visit/manual_only.
- Target Roles: Checkboxes for teacher, mentor, school_leader, district_leader, coach. Name: `tour_target_roles[]`.
- Trigger Page URL: `<input type="text" name="tour_trigger_page_url">` — shown only when trigger_type=page_visit (JS toggle).
- Start Page URL: `<input type="text" name="tour_start_page_url" required>`.
- Hide on Mobile: `<input type="checkbox" name="tour_hide_on_mobile" value="1">`.
- Sort Order: `<input type="number" name="tour_sort_order" value="0">`.

**Steps section:**
- Container `<div id="hl-tour-steps" class="hl-tour-steps-sortable">` — jQuery UI Sortable.
- Each step is a collapsible card `<div class="hl-tour-step-card" data-step-id="{id}">`.
- Card header shows step number + title (preview). Click toggles body.
- Card body: title input, description textarea (initialize TinyMCE via `wp_editor()`), page_url input, target_selector display field + "Pick Element" button, position pill selector (top/bottom/left/right/auto), step_type toggle (informational/interactive). Also a "Remove Step" button.
- "Add Step" button at bottom — JS clones a template card, increments step counter.
- Hidden input `<input type="hidden" name="tour_id" value="{id}">`.

**Save handler in handle_save():**
- Verify nonce.
- Create or update tour row.
- For each step: create/update/delete as needed. Steps submitted as arrays: `step_title[]`, `step_description[]`, `step_page_url[]`, `step_target_selector[]`, `step_position[]`, `step_type[]`, `step_id[]` (0 for new).
- Redirect back to editor with `tour_id` and success notice.

- [ ] **Step 2: Create admin JS**

Create `assets/js/hl-tour-admin.js`:

```javascript
(function($) {
    'use strict';

    $(document).ready(function() {
        // Sortable steps.
        if ( $('#hl-tour-steps').length ) {
            $('#hl-tour-steps').sortable({
                handle: '.hl-tour-step-handle',
                placeholder: 'hl-tour-step-placeholder',
                update: function() {
                    renumberSteps();
                }
            });
        }

        // Auto-generate slug from title.
        $('input[name="tour_title"]').on('blur', function() {
            var $slug = $('input[name="tour_slug"]');
            if ( ! $slug.val() || $slug.data('auto') ) {
                var slug = $(this).val().toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-|-$/g, '');
                $slug.val(slug).data('auto', true);
            }
        });
        $('input[name="tour_slug"]').on('input', function() {
            $(this).data('auto', false);
        });

        // Toggle trigger_page_url visibility.
        $('select[name="tour_trigger_type"]').on('change', function() {
            var showPageUrl = $(this).val() === 'page_visit';
            $('.hl-tour-trigger-page-url-row').toggle(showPageUrl);
        }).trigger('change');

        // Collapse/expand step cards.
        $(document).on('click', '.hl-tour-step-header', function() {
            $(this).closest('.hl-tour-step-card').toggleClass('collapsed');
        });

        // Add step.
        var stepCounter = $('.hl-tour-step-card').length;
        $('#hl-tour-add-step').on('click', function() {
            stepCounter++;
            var $template = $('#hl-tour-step-template').clone();
            $template.removeAttr('id').removeClass('hidden');
            $template.find('.hl-tour-step-number').text(stepCounter);
            $template.find('input, textarea, select').each(function() {
                var name = $(this).attr('data-name');
                if (name) $(this).attr('name', name);
            });
            $('#hl-tour-steps').append($template);
        });

        // Remove step.
        $(document).on('click', '.hl-tour-remove-step', function(e) {
            e.stopPropagation();
            if ( confirm('Remove this step?') ) {
                $(this).closest('.hl-tour-step-card').remove();
                renumberSteps();
            }
        });

        // Element picker launch.
        $(document).on('click', '.hl-tour-pick-element', function() {
            var $card = $(this).closest('.hl-tour-step-card');
            var pageUrl = $card.find('input[name="step_page_url[]"]').val()
                || $('input[name="tour_start_page_url"]').val();

            if ( ! pageUrl ) {
                alert('Enter a Page URL for this step first.');
                return;
            }
            openElementPicker(pageUrl, $card);
        });

        // Position pill selector.
        $(document).on('click', '.hl-tour-position-pill', function() {
            var $group = $(this).closest('.hl-tour-position-pills');
            $group.find('.hl-tour-position-pill').removeClass('active');
            $(this).addClass('active');
            $group.find('input[name="step_position[]"]').val($(this).data('value'));
        });

        // Step type toggle.
        $(document).on('click', '.hl-tour-type-toggle span', function() {
            var $group = $(this).closest('.hl-tour-type-toggle');
            $group.find('span').removeClass('active');
            $(this).addClass('active');
            $group.find('input[name="step_type[]"]').val($(this).data('value'));
        });

        function renumberSteps() {
            $('#hl-tour-steps .hl-tour-step-card').each(function(i) {
                $(this).find('.hl-tour-step-number').text(i + 1);
            });
        }
    });

    // Element Picker Modal — placeholder for Phase 3.
    window.openElementPicker = function(pageUrl, $stepCard) {
        alert('Element Picker coming in Phase 3. For now, enter the CSS selector manually.');
        $stepCard.find('.hl-tour-selector-manual').show();
    };

})(jQuery);
```

- [ ] **Step 3: Add admin CSS for tour editor**

Append to `assets/css/admin.css` — styles for `.hl-tour-step-card`, `.hl-tour-step-handle`, `.hl-tour-step-header`, `.hl-tour-position-pills`, `.hl-tour-type-toggle`, step collapse animation. Follow existing admin.css patterns.

- [ ] **Step 4: Enqueue admin assets**

In `includes/admin/class-hl-admin.php`, in the `enqueue_assets()` method, add conditional enqueue for the tours page:

```php
// Tours admin page.
if ( isset( $_GET['page'] ) && $_GET['page'] === 'hl-settings' && isset( $_GET['tab'] ) && $_GET['tab'] === 'tours' ) {
    wp_enqueue_script( 'jquery-ui-sortable' );
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script( 'wp-color-picker' );
    wp_enqueue_script( 'hl-tour-admin', HL_CORE_ASSETS_URL . 'js/hl-tour-admin.js', array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ), HL_CORE_VERSION, true );
    wp_localize_script( 'hl-tour-admin', 'hlTourAdmin', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'hl_tour_admin_nonce' ),
        'site_url' => site_url(),
    ) );
}
```

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-hl-admin-tours.php assets/js/hl-tour-admin.js assets/css/admin.css includes/admin/class-hl-admin.php
git commit -m "feat(tours): add tour editor with step management, drag-reorder, admin JS"
```

---

### Task 2A.3: Admin Tours Tab — Tour Styles

**Files:**
- Modify: `includes/admin/class-hl-admin-tours.php`

- [ ] **Step 1: Implement render_styles()**

Add `render_styles()` method. Renders when `$_GET['subtab'] === 'styles'`.

```php
public function render_styles() {
    $styles = HL_Tour_Service::instance()->get_global_styles();
    ?>
    <form method="post">
        <?php wp_nonce_field( 'hl_tour_admin', 'hl_tour_admin_nonce' ); ?>
        <input type="hidden" name="hl_tour_action" value="save_styles">

        <h3><?php _e( 'Tour Appearance', 'hl-core' ); ?></h3>
        <p class="description"><?php _e( 'These settings apply to all tours globally.', 'hl-core' ); ?></p>

        <table class="form-table">
            <tr>
                <th><label><?php _e( 'Tooltip Background', 'hl-core' ); ?></label></th>
                <td><input type="text" name="tooltip_bg" value="<?php echo esc_attr( $styles['tooltip_bg'] ); ?>" class="hl-color-picker"></td>
            </tr>
            <tr>
                <th><label><?php _e( 'Title Color', 'hl-core' ); ?></label></th>
                <td><input type="text" name="title_color" value="<?php echo esc_attr( $styles['title_color'] ); ?>" class="hl-color-picker"></td>
            </tr>
            <tr>
                <th><label><?php _e( 'Title Font Size (px)', 'hl-core' ); ?></label></th>
                <td><input type="number" name="title_font_size" value="<?php echo absint( $styles['title_font_size'] ); ?>" min="10" max="32" style="width:80px"></td>
            </tr>
            <tr>
                <th><label><?php _e( 'Description Color', 'hl-core' ); ?></label></th>
                <td><input type="text" name="desc_color" value="<?php echo esc_attr( $styles['desc_color'] ); ?>" class="hl-color-picker"></td>
            </tr>
            <tr>
                <th><label><?php _e( 'Description Font Size (px)', 'hl-core' ); ?></label></th>
                <td><input type="number" name="desc_font_size" value="<?php echo absint( $styles['desc_font_size'] ); ?>" min="10" max="24" style="width:80px"></td>
            </tr>
            <tr>
                <th><label><?php _e( 'Button Background', 'hl-core' ); ?></label></th>
                <td><input type="text" name="btn_bg" value="<?php echo esc_attr( $styles['btn_bg'] ); ?>" class="hl-color-picker"></td>
            </tr>
            <tr>
                <th><label><?php _e( 'Button Text Color', 'hl-core' ); ?></label></th>
                <td><input type="text" name="btn_text_color" value="<?php echo esc_attr( $styles['btn_text_color'] ); ?>" class="hl-color-picker"></td>
            </tr>
            <tr>
                <th><label><?php _e( 'Progress Bar Color', 'hl-core' ); ?></label></th>
                <td><input type="text" name="progress_color" value="<?php echo esc_attr( $styles['progress_color'] ); ?>" class="hl-color-picker"></td>
            </tr>
        </table>

        <?php submit_button( __( 'Save Styles', 'hl-core' ) ); ?>
        <button type="submit" name="hl_tour_action" value="reset_styles" class="button" onclick="return confirm('Reset all tour styles to defaults?');">
            <?php _e( 'Reset to Defaults', 'hl-core' ); ?>
        </button>
    </form>

    <h3><?php _e( 'Preview', 'hl-core' ); ?></h3>
    <div class="hl-tour-style-preview" id="hl-tour-style-preview">
        <div class="hl-tour-preview-tooltip" style="background:<?php echo esc_attr( $styles['tooltip_bg'] ); ?>; max-width:340px; border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,0.12); padding:20px;">
            <div style="color:<?php echo esc_attr( $styles['title_color'] ); ?>; font-size:<?php echo absint( $styles['title_font_size'] ); ?>px; font-weight:600; margin-bottom:8px;">
                <?php _e( 'Sample Step Title', 'hl-core' ); ?>
            </div>
            <div style="color:<?php echo esc_attr( $styles['desc_color'] ); ?>; font-size:<?php echo absint( $styles['desc_font_size'] ); ?>px; line-height:1.5; margin-bottom:16px;">
                <?php _e( 'This is a preview of how your tour tooltips will look with the current style settings.', 'hl-core' ); ?>
            </div>
            <div style="display:flex; gap:8px; justify-content:flex-end;">
                <button style="background:<?php echo esc_attr( $styles['btn_bg'] ); ?>; color:<?php echo esc_attr( $styles['btn_text_color'] ); ?>; border:none; padding:6px 16px; border-radius:4px; font-size:13px; cursor:default;">
                    <?php _e( 'Next', 'hl-core' ); ?>
                </button>
            </div>
            <div style="margin-top:12px; height:4px; background:#e5e7eb; border-radius:2px; overflow:hidden;">
                <div style="width:40%; height:100%; background:<?php echo esc_attr( $styles['progress_color'] ); ?>; border-radius:2px;"></div>
            </div>
        </div>
    </div>
    <?php
}
```

- [ ] **Step 2: Add save_styles handling**

In `handle_save()`, add cases for `save_styles` and `reset_styles` actions:

```php
case 'save_styles':
    HL_Tour_Service::instance()->save_global_styles( $_POST );
    $this->add_notice( 'success', __( 'Tour styles saved.', 'hl-core' ) );
    break;
case 'reset_styles':
    delete_option( 'hl_tour_styles' );
    $this->add_notice( 'success', __( 'Tour styles reset to defaults.', 'hl-core' ) );
    break;
```

- [ ] **Step 3: Initialize color pickers in admin JS**

In `hl-tour-admin.js`, add inside `$(document).ready()`:

```javascript
// WP Iris color pickers.
$('.hl-color-picker').wpColorPicker();
```

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-hl-admin-tours.php assets/js/hl-tour-admin.js
git commit -m "feat(tours): add global tour styles admin tab with color pickers"
```

---

## Phase 2B: Frontend Engine

### Task 2B.1: Bundle Driver.js

**Files:**
- Create: `assets/js/vendor/driver.js`
- Create: `assets/css/vendor/driver.css`

- [ ] **Step 1: Download Driver.js 1.4.0**

Download the Driver.js distribution files. Get the UMD build (works without module bundler):
- JS: `https://cdn.jsdelivr.net/npm/driver.js@1.4.0/dist/driver.js.iife.js`
- CSS: `https://cdn.jsdelivr.net/npm/driver.js@1.4.0/dist/driver.css`

Save to `assets/js/vendor/driver.js` and `assets/css/vendor/driver.css`.

Add a comment header to each file:
```
/* Driver.js v1.4.0 — MIT License — https://driverjs.com */
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/vendor/driver.js assets/css/vendor/driver.css
git commit -m "feat(tours): bundle Driver.js 1.4.0 (MIT, ~5KB gzip)"
```

---

### Task 2B.2: Topbar "?" Button + Dropdown

**Files:**
- Modify: `templates/hl-page.php`
- Modify: `assets/css/frontend.css`
- Modify: `assets/js/frontend.js`

- [ ] **Step 1: Read hl-page.php**

Read `templates/hl-page.php`. Find the topbar section (~line 100) and the user-wrap area (~line 112). The "?" button goes immediately before `.hl-topbar__user-wrap`.

- [ ] **Step 2: Add tour trigger button to topbar**

In `templates/hl-page.php`, immediately before the `.hl-topbar__user-wrap` div, add:

```php
<?php if ( is_user_logged_in() ) : ?>
<div class="hl-topbar__tour-wrap">
    <button id="hl-tour-trigger" class="hl-topbar__tour-btn" aria-expanded="false" aria-label="<?php esc_attr_e( 'Guided Tours', 'hl-core' ); ?>" title="<?php esc_attr_e( 'Guided Tours', 'hl-core' ); ?>">
        <span class="dashicons dashicons-editor-help"></span>
    </button>
    <div id="hl-tour-dropdown" class="hl-topbar__tour-dropdown" hidden>
        <div class="hl-tour-dropdown__header"><?php _e( 'Guided Tours', 'hl-core' ); ?></div>
        <ul class="hl-tour-dropdown__list" id="hl-tour-dropdown-list">
            <!-- Populated by JS from hlTourData.available -->
        </ul>
        <div class="hl-tour-dropdown__empty" id="hl-tour-dropdown-empty" hidden>
            <?php _e( 'No tours available for this page.', 'hl-core' ); ?>
        </div>
    </div>
</div>
<?php endif; ?>
```

- [ ] **Step 3: Add CSS for tour button and dropdown**

Append to `assets/css/frontend.css` in a new section:

```css
/* ========================================
   GUIDED TOURS — Topbar & Dropdown
   ======================================== */
.hl-topbar__tour-wrap {
    position: relative;
    display: flex;
    align-items: center;
    margin-right: 8px;
}
.hl-topbar__tour-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 6px;
    border-radius: var(--hl-radius-xs);
    color: var(--hl-text-secondary);
    transition: var(--hl-transition);
    display: flex;
    align-items: center;
}
.hl-topbar__tour-btn:hover {
    background: var(--hl-bg);
    color: var(--hl-interactive);
}
.hl-topbar__tour-btn .dashicons {
    font-size: 22px;
    width: 22px;
    height: 22px;
}
.hl-topbar__tour-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 4px;
    width: 260px;
    background: var(--hl-surface);
    border-radius: var(--hl-radius-sm);
    box-shadow: var(--hl-shadow-lg);
    border: 1px solid rgba(0,0,0,0.08);
    z-index: 1100;
    overflow: hidden;
}
.hl-tour-dropdown__header {
    padding: 12px 16px;
    font-weight: 600;
    font-size: 13px;
    color: var(--hl-text-heading);
    border-bottom: 1px solid var(--hl-bg);
}
.hl-tour-dropdown__list {
    list-style: none;
    margin: 0;
    padding: 4px 0;
}
.hl-tour-dropdown__list li {
    margin: 0;
}
.hl-tour-dropdown__list li button {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 10px 16px;
    background: none;
    border: none;
    text-align: left;
    cursor: pointer;
    font-size: 13px;
    color: var(--hl-text);
    transition: var(--hl-transition);
}
.hl-tour-dropdown__list li button:hover {
    background: var(--hl-bg-subtle);
    color: var(--hl-interactive);
}
.hl-tour-dropdown__badge {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: var(--hl-radius-pill);
    background: var(--hl-interactive-bg);
    color: var(--hl-interactive);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.hl-tour-dropdown__empty {
    padding: 16px;
    text-align: center;
    color: var(--hl-text-muted);
    font-size: 13px;
}

/* Mobile: bottom sheet */
@media (max-width: 768px) {
    .hl-topbar__tour-dropdown {
        position: fixed;
        top: auto;
        bottom: 0;
        left: 0;
        right: 0;
        width: 100%;
        border-radius: var(--hl-radius) var(--hl-radius) 0 0;
        max-height: 60vh;
        overflow-y: auto;
    }
    .hl-tour-dropdown__list li button {
        padding: 14px 16px;
        font-size: 15px;
    }
}
```

- [ ] **Step 4: Add dropdown toggle JS**

In `assets/js/frontend.js`, inside the `$(document).ready()` block, add the tour dropdown handler (follows the existing topbar user dropdown pattern):

```javascript
// Tour guide dropdown.
$(document).on('click', '#hl-tour-trigger', function(e) {
    e.stopPropagation();
    var $dropdown = $('#hl-tour-dropdown');
    var isOpen = !$dropdown.prop('hidden');
    // Close user dropdown if open.
    $('#hl-topbar-dropdown').prop('hidden', true);
    $('#hl-topbar-user-btn').attr('aria-expanded', 'false');
    // Toggle tour dropdown.
    $dropdown.prop('hidden', isOpen);
    $(this).attr('aria-expanded', !isOpen);
});
$(document).on('click', function(e) {
    if (!$(e.target).closest('.hl-topbar__tour-wrap').length) {
        $('#hl-tour-dropdown').prop('hidden', true);
        $('#hl-tour-trigger').attr('aria-expanded', 'false');
    }
});
```

- [ ] **Step 5: Commit**

```bash
git add templates/hl-page.php assets/css/frontend.css assets/js/frontend.js
git commit -m "feat(tours): add topbar ? button with dropdown (mobile bottom sheet)"
```

---

### Task 2B.3: Frontend Tour Controller (hl-tour.js)

**Files:**
- Create: `assets/js/hl-tour.js`

- [ ] **Step 1: Create the tour controller**

Create `assets/js/hl-tour.js`. This is the core frontend engine that wraps Driver.js and handles multi-page state, auto-triggering, exit flows, and the topbar dropdown population.

The file should be a self-contained IIFE that:
1. Reads `window.hlTourData` (localized by PHP).
2. Populates the topbar dropdown with available tours.
3. Checks localStorage for interrupted tours → redirect to start page → show final step.
4. Checks for auto-trigger tours → start the first one.
5. Exposes `window.hlTourStart(tourSlug)` for manual trigger from dropdown.

**Key behaviors to implement:**
- `initTour(tourData)` — creates Driver.js instance with steps, applying global styles.
- For each step: check `document.querySelector(selector)`. If not found, skip. Track visible step count for progress.
- Informational steps: show Next/Back buttons.
- Interactive steps: set `disableActiveInteraction: false`, add click listener on target to advance.
- Page transitions: save `{tour_slug, step_index, start_page_url, status: 'navigating'}` to localStorage, navigate.
- Exit (X or Escape): set status to `'interrupted'`, redirect to start_page_url, show final step.
- Final step (auto-appended): highlights `#hl-tour-trigger`, title "Replay This Tour", on dismiss → AJAX `hl_tour_mark_seen` → clear localStorage.
- Progress bar: inject into popover footer via `onPopoverRender` hook.
- Auto-scroll: Driver.js auto-scrolls, but to offset for the 48px fixed topbar, use the `onHighlightStarted` hook to adjust scroll position: `window.scrollBy(0, -80)` after Driver.js scrolls the element into view. `stagePadding` controls the visual padding around the highlight cutout (set to 10), NOT scroll offset.
- Mobile: check `window.innerWidth < 640` + `hide_on_mobile` flag → skip tour.

This file will be ~200-300 lines. Write the complete implementation following the spec's §7.1-7.6.

- [ ] **Step 2: Commit**

```bash
git add assets/js/hl-tour.js
git commit -m "feat(tours): add frontend tour controller with multi-page, auto-trigger, exit flow"
```

---

### Task 2B.4: PHP Tour Context + Asset Enqueuing

**Files:**
- Modify: `templates/hl-page.php`
- Modify: `includes/frontend/class-hl-shortcodes.php` (or wherever frontend assets are loaded)

- [ ] **Step 1: Add tour data localization to hl-page.php**

In `templates/hl-page.php`, in the asset loading section (near the bottom, before `</body>`), add after `frontend.js`:

```php
<?php
// Guided Tours — only for logged-in users.
if ( is_user_logged_in() ) :
    $tour_service = HL_Tour_Service::instance();
    $user_id      = get_current_user_id();
    $user_roles   = $tour_service->get_user_hl_roles( $user_id );
    $current_url  = $_SERVER['REQUEST_URI'];

    // Check active tour slug (passed via URL param by hl-tour.js during page transitions).
    $active_slug = isset( $_GET['hl_active_tour'] ) ? sanitize_text_field( $_GET['hl_active_tour'] ) : null;

    $tour_context = $tour_service->get_tours_for_context( $current_url, $user_id, $user_roles, $active_slug );
    $global_styles = $tour_service->get_global_styles();
?>
    <link rel="stylesheet" href="<?php echo HL_CORE_ASSETS_URL; ?>css/vendor/driver.css?v=1.4.0">
    <script src="<?php echo HL_CORE_ASSETS_URL; ?>js/vendor/driver.js?v=1.4.0"></script>
    <script>
        window.hlTourData = <?php echo wp_json_encode( array(
            'auto_trigger' => $tour_context['auto_trigger'],
            'available'    => $tour_context['available'],
            'active_tour'  => $tour_context['active_tour'],
            'styles'       => $global_styles,
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'hl_tour_nonce' ),
            'i18n'         => array(
                'next'          => __( 'Next', 'hl-core' ),
                'prev'          => __( 'Back', 'hl-core' ),
                'done'          => __( 'Done', 'hl-core' ),
                'of'            => __( 'of', 'hl-core' ),
                'replay_title'  => __( 'Replay This Tour', 'hl-core' ),
                'replay_desc'   => __( 'You can revisit this tour anytime by clicking here.', 'hl-core' ),
                'no_tours'      => __( 'No tours available for this page.', 'hl-core' ),
            ),
        ) ); ?>;
    </script>
    <script src="<?php echo HL_CORE_ASSETS_URL; ?>js/hl-tour.js?v=<?php echo HL_CORE_VERSION; ?>"></script>
<?php endif; ?>
```

- [ ] **Step 2: Commit**

```bash
git add templates/hl-page.php
git commit -m "feat(tours): localize tour context data + enqueue Driver.js on frontend"
```

---

## Phase 3: Element Picker

### Task 3.1: Element Picker Script (Iframe Injection)

**Files:**
- Create: `assets/js/hl-element-picker.js`
- Modify: `templates/hl-page.php`

- [ ] **Step 1: Create the picker script**

Create `assets/js/hl-element-picker.js`. This script is injected into the iframe when `?hl_picker=1` is in the URL. It handles hover highlighting, click selection, selector generation, and postMessage communication.

**Key behaviors:**
- On load: add overlay preventing normal page interaction (pointer-events: none on all elements except the picker toolbar).
- On mousemove: detect element under cursor, draw highlight border (2px solid #4F46E5 + box-shadow), show selector in toolbar.
- On click: lock selection, show "Selected: {selector}" with "Cancel" and "Use This Element" buttons.
- Selector generation algorithm (in order):
  1. Element has `id` → `#the-id`
  2. Element has a class starting with `hl-` that is unique on page → `.hl-the-class`
  3. Closest ancestor with an `hl-*` class + child path → `.hl-parent > .child:nth-child(N)`
  4. Full DOM path fallback
- "Use This Element" sends `window.parent.postMessage({type: 'hl-picker-select', selector: '...'}, window.location.origin)`.
- "Cancel" sends `window.parent.postMessage({type: 'hl-picker-cancel'}, window.location.origin)`.

- [ ] **Step 2: Add picker mode detection to hl-page.php**

In `templates/hl-page.php`, near the top (before HTML output), add:

```php
<?php
// Element Picker mode — inject picker script, skip tour loading.
$is_picker_mode = isset( $_GET['hl_picker'] ) && $_GET['hl_picker'] === '1';
if ( $is_picker_mode && current_user_can( 'manage_hl_core' ) ) {
    // View-as-role override.
    if ( ! empty( $_GET['hl_view_as'] ) ) {
        // Store the role override for rendering — services will check this.
        $GLOBALS['hl_view_as_role'] = sanitize_text_field( $_GET['hl_view_as'] );
    }
}
?>
```

At the bottom of hl-page.php, conditionally load the picker script instead of tour scripts:

```php
<?php if ( $is_picker_mode && current_user_can( 'manage_hl_core' ) ) : ?>
    <script src="<?php echo HL_CORE_ASSETS_URL; ?>js/hl-element-picker.js?v=<?php echo HL_CORE_VERSION; ?>"></script>
<?php endif; ?>
```

- [ ] **Step 3: Commit**

```bash
git add assets/js/hl-element-picker.js templates/hl-page.php
git commit -m "feat(tours): add element picker script with selector generation + postMessage"
```

---

### Task 3.2: Admin Picker Modal Integration

**Files:**
- Modify: `assets/js/hl-tour-admin.js`
- Modify: `includes/admin/class-hl-admin-tours.php`

- [ ] **Step 1: Replace placeholder openElementPicker**

In `hl-tour-admin.js`, replace the placeholder `openElementPicker` function with the full modal implementation:

```javascript
window.openElementPicker = function(pageUrl, $stepCard) {
    var siteUrl = hlTourAdmin.site_url;
    var $modal = $('#hl-picker-modal');
    
    // Create modal if not exists.
    if ( ! $modal.length ) {
        $modal = $('<div id="hl-picker-modal" class="hl-picker-modal">' +
            '<div class="hl-picker-modal__header">' +
                '<label>View page as: <select id="hl-picker-role">' +
                    '<option value="">Admin (default)</option>' +
                    '<option value="teacher">Teacher</option>' +
                    '<option value="mentor">Mentor</option>' +
                    '<option value="school_leader">School Leader</option>' +
                    '<option value="district_leader">District Leader</option>' +
                    '<option value="coach">Coach</option>' +
                '</select></label>' +
                '<button class="hl-picker-modal__close">&times;</button>' +
            '</div>' +
            '<iframe id="hl-picker-iframe" class="hl-picker-modal__iframe"></iframe>' +
        '</div>');
        $('body').append($modal);
    }

    function loadIframe(role) {
        var url = siteUrl + pageUrl + (pageUrl.indexOf('?') > -1 ? '&' : '?') + 'hl_picker=1';
        if (role) url += '&hl_view_as=' + role;
        $('#hl-picker-iframe').attr('src', url);
    }

    // Show modal + load iframe.
    $modal.addClass('open');
    loadIframe($('#hl-picker-role').val());

    // Role change → reload iframe.
    $('#hl-picker-role').off('change').on('change', function() {
        loadIframe($(this).val());
    });

    // Close modal.
    $modal.find('.hl-picker-modal__close').off('click').on('click', function() {
        $modal.removeClass('open');
        $('#hl-picker-iframe').attr('src', '');
    });

    // Listen for postMessage from iframe (with origin validation).
    $(window).off('message.picker').on('message.picker', function(e) {
        if ( e.originalEvent.origin !== window.location.origin ) return; // Security: same-origin only.
        var data = e.originalEvent.data;
        if ( data && data.type === 'hl-picker-select' ) {
            $stepCard.find('input[name="step_target_selector[]"]').val(data.selector);
            $stepCard.find('.hl-tour-selector-display').text(data.selector).show();
            $modal.removeClass('open');
            $('#hl-picker-iframe').attr('src', '');
        }
        if ( data && data.type === 'hl-picker-cancel' ) {
            $modal.removeClass('open');
            $('#hl-picker-iframe').attr('src', '');
        }
    });
};
```

- [ ] **Step 2: Add picker modal CSS to admin.css**

Append to `assets/css/admin.css`:

```css
/* Element Picker Modal */
.hl-picker-modal {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.8);
    z-index: 100100;
    flex-direction: column;
}
.hl-picker-modal.open { display: flex; }
.hl-picker-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    background: #1a1a2e;
    color: #fff;
}
.hl-picker-modal__header select {
    margin-left: 8px;
    padding: 4px 8px;
}
.hl-picker-modal__close {
    background: none;
    border: none;
    color: #fff;
    font-size: 24px;
    cursor: pointer;
    padding: 4px 8px;
}
.hl-picker-modal__iframe {
    flex: 1;
    border: none;
    width: 100%;
}
```

- [ ] **Step 3: Add view-as-role PHP support**

In the service layer or a new helper, check `$GLOBALS['hl_view_as_role']` when resolving role-based visibility. This needs to be integrated into the existing role-checking functions used by frontend renderers.

Modify `get_user_hl_roles()` in `HL_Tour_Service` to respect the view-as override. Add this check at the top of the method:

```php
public function get_user_hl_roles( $user_id ) {
    // Picker mode: override with the view-as role (admin only).
    if ( ! empty( $GLOBALS['hl_view_as_role'] ) && current_user_can( 'manage_hl_core' ) ) {
        return array( sanitize_text_field( $GLOBALS['hl_view_as_role'] ) );
    }

    // ... rest of the existing method (enrollment query, etc.) ...
}
```

This is the cleanest approach because all frontend renderers that call `get_user_hl_roles()` will automatically get the overridden role in picker mode. No changes needed in individual renderers.

Also add a static helper for frontend renderers that check roles directly (not via this service):

```php
public static function is_picker_mode() {
    return ! empty( $GLOBALS['hl_view_as_role'] ) && current_user_can( 'manage_hl_core' );
}
```

For renderers that don't go through `get_user_hl_roles()` (e.g., they check `current_user_can()` directly), they should add a check: `if ( HL_Tour_Service::is_picker_mode() ) { /* show the element regardless */ }`. Identify and update these during implementation — grep for role-based visibility checks in `includes/frontend/`.

- [ ] **Step 4: Commit**

```bash
git add assets/js/hl-tour-admin.js assets/css/admin.css assets/js/hl-element-picker.js includes/services/class-hl-tour-service.php
git commit -m "feat(tours): add visual element picker modal with view-as-role support"
```

---

### Task 3.3: Final Polish + Documentation Updates

**Files:**
- Modify: `STATUS.md`
- Modify: `README.md`

- [ ] **Step 1: Update STATUS.md**

Add a new section in the Build Queue:

```markdown
### Guided Tours System (Active — April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-03-guided-tours-design.md` | **Plan:** `docs/superpowers/plans/2026-04-03-guided-tours-plan.md`
- [x] **Schema: 3 new tables** — `hl_tour`, `hl_tour_step`, `hl_tour_seen`. Schema revision 28→29.
- [x] **Repository + Service** — `HL_Tour_Repository` (CRUD), `HL_Tour_Service` (context resolution, AJAX endpoints, global styles).
- [x] **Admin UI** — Tours tab in Settings hub (List + Editor + Styles subtabs). Step drag-reorder, TinyMCE descriptions, position pills, type toggles.
- [x] **Frontend Engine** — Driver.js 1.4.0 wrapper. Multi-page state via localStorage. Auto-trigger (first_login + page_visit) + manual via topbar "?" button. Exit → redirect to start page → final step. Skip missing elements.
- [x] **Element Picker** — Visual selector via iframe. View-as-role. Selector auto-generation (id → hl-class → path).
```

- [ ] **Step 2: Update README.md**

Add Guided Tours to the "What's Implemented" section and update the file tree.

- [ ] **Step 3: Commit**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md + README.md for guided tours system"
```
