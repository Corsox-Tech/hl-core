# Feature Tracker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a single-page AJAX Feature Tracker on the HL frontend where admins and coaches can submit, view, filter, and comment on tickets (bugs, improvements, feature requests).

**Architecture:** Two new tables (`hl_ticket`, `hl_ticket_comment`), one service class (`HL_Ticket_Service`) handling all DB + business logic, one frontend class (`HL_Frontend_Feature_Tracker`) rendering the shortcode page and handling 6 AJAX endpoints. No repository — service queries DB directly (matches `HL_Coaching_Service` pattern). Modal-based UI for detail/create/edit views.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, jQuery (already loaded via `hl-page.php`), vanilla CSS with HL design tokens.

**Spec:** `docs/superpowers/specs/2026-04-06-feature-tracker-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `includes/services/class-hl-ticket-service.php` | **Create** | CRUD for tickets + comments, permission checks (can_edit, is_admin), search/filter queries, status transitions, audit logging |
| `includes/frontend/class-hl-frontend-feature-tracker.php` | **Create** | Shortcode `[hl_feature_tracker]`, renders page HTML (hero, toolbar, table shell, modal templates), registers 6 AJAX endpoints, handles all AJAX callbacks |
| `includes/class-hl-installer.php` | **Modify** | Add `hl_ticket` + `hl_ticket_comment` to `get_schema()` (after `hl_tour_seen`, line ~1962) |
| `hl-core.php` | **Modify** | Add `require_once` for service (line ~129, after tour service) + frontend class (line ~207, after user profile). Add `HL_Frontend_Feature_Tracker::instance()` initialization in `init()` method (line ~286, after tour service) |
| `includes/integrations/class-hl-buddyboss-integration.php` | **Modify** | Add `feature-tracker` menu item to both coach menu (line ~400) and admin menu (line ~421) in `build_menu_items()` |
| `includes/frontend/class-hl-shortcodes.php` | **Modify** | Register `hl_feature_tracker` shortcode + render method |
| `includes/cli/class-hl-cli-create-pages.php` | **Modify** | Add Feature Tracker page definition (line ~164) |
| `assets/css/frontend.css` | **Modify** | Add `.hlft-*` CSS section at end of file |
| `assets/js/frontend.js` | **Modify** | Add Feature Tracker AJAX + modal handlers at end of file |

---

## Task 1: Database Schema

**Files:**
- Modify: `includes/class-hl-installer.php:1962` (after `hl_tour_seen` table, before `return $tables;`)

- [ ] **Step 1: Add `hl_ticket` and `hl_ticket_comment` tables to `get_schema()`**

In `includes/class-hl-installer.php`, find the line `return $tables;` inside `get_schema()` (around line 1964) and add the two new table definitions before it:

```php
        // Feature Tracker: tickets
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_ticket (
            ticket_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_uuid char(36) NOT NULL,
            title varchar(255) NOT NULL,
            description longtext NOT NULL,
            type enum('bug','improvement','feature_request') NOT NULL,
            priority enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
            status enum('open','in_review','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
            creator_user_id bigint(20) unsigned NOT NULL,
            resolved_at datetime NULL DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (ticket_id),
            UNIQUE KEY ticket_uuid (ticket_uuid),
            KEY status (status),
            KEY creator_user_id (creator_user_id),
            KEY type (type),
            KEY priority (priority)
        ) $charset_collate;";

        // Feature Tracker: ticket comments
        $tables[] = "CREATE TABLE {$wpdb->prefix}hl_ticket_comment (
            comment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            comment_text text NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (comment_id),
            KEY ticket_id (ticket_id)
        ) $charset_collate;";
```

- [ ] **Step 2: Commit**

```bash
git add includes/class-hl-installer.php
git commit -m "feat(feature-tracker): add hl_ticket + hl_ticket_comment tables to schema"
```

---

## Task 2: Ticket Service

**Files:**
- Create: `includes/services/class-hl-ticket-service.php`

- [ ] **Step 1: Create `HL_Ticket_Service` with constants, singleton, and core CRUD**

Create `includes/services/class-hl-ticket-service.php`:

```php
<?php
/**
 * Feature Tracker ticket service.
 *
 * Handles all DB operations for tickets and comments, permission checks,
 * search/filter queries, status transitions, and audit logging.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_Ticket_Service {

    /** @var string Admin email with full ticket control (status changes, unrestricted editing). */
    const ADMIN_EMAIL = 'mateo@corsox.com';

    /** @var string[] Valid ticket types. */
    const VALID_TYPES = array( 'bug', 'improvement', 'feature_request' );

    /** @var string[] Valid priority levels. */
    const VALID_PRIORITIES = array( 'low', 'medium', 'high', 'critical' );

    /** @var string[] Valid statuses. */
    const VALID_STATUSES = array( 'open', 'in_review', 'in_progress', 'resolved', 'closed' );

    /** @var string[] Terminal statuses (no editing by creator). */
    const TERMINAL_STATUSES = array( 'resolved', 'closed' );

    /** @var int Edit window in seconds (2 hours). */
    const EDIT_WINDOW_SECONDS = 7200;

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ─── Permission Helpers ───

    /**
     * Check if the current user is the admin (full ticket control).
     */
    public function is_ticket_admin() {
        $user = wp_get_current_user();
        return $user && $user->user_email === self::ADMIN_EMAIL;
    }

    /**
     * Check if the current user can edit a ticket.
     *
     * Rules:
     *   - Admin email can always edit.
     *   - Creator can edit within 2 hours AND ticket is not resolved/closed.
     *
     * @param array $ticket Ticket row (ARRAY_A).
     * @return bool
     */
    public function can_edit( $ticket ) {
        if ( $this->is_ticket_admin() ) {
            return true;
        }

        $user_id = get_current_user_id();
        if ( (int) $ticket['creator_user_id'] !== $user_id ) {
            return false;
        }

        if ( in_array( $ticket['status'], self::TERMINAL_STATUSES, true ) ) {
            return false;
        }

        $created = strtotime( $ticket['created_at'] );
        $now     = strtotime( current_time( 'mysql' ) );
        return ( $now - $created ) < self::EDIT_WINDOW_SECONDS;
    }

    // ─── Ticket CRUD ───

    /**
     * Get a list of tickets with optional filters.
     *
     * @param array $args {
     *     @type string $type     Filter by type enum.
     *     @type string $status   Filter by status enum.
     *     @type string $priority Filter by priority enum.
     *     @type string $search   Search title + description.
     *     @type int    $page     Page number (1-based).
     *     @type int    $per_page Results per page (max 50).
     * }
     * @return array { 'tickets' => array[], 'total' => int }
     */
    public function get_tickets( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_ticket';

        $where  = array( '1=1' );
        $values = array();

        // Type filter.
        if ( ! empty( $args['type'] ) && in_array( $args['type'], self::VALID_TYPES, true ) ) {
            $where[]  = 't.type = %s';
            $values[] = $args['type'];
        }

        // Status filter.
        if ( ! empty( $args['status'] ) && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
            $where[]  = 't.status = %s';
            $values[] = $args['status'];
        } elseif ( empty( $args['status'] ) ) {
            // Default: exclude closed.
            $where[] = "t.status != 'closed'";
        }

        // Priority filter.
        if ( ! empty( $args['priority'] ) && in_array( $args['priority'], self::VALID_PRIORITIES, true ) ) {
            $where[]  = 't.priority = %s';
            $values[] = $args['priority'];
        }

        // Search.
        if ( ! empty( $args['search'] ) && strlen( $args['search'] ) >= 2 ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(t.title LIKE %s OR t.description LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        // Count total.
        $count_sql = "SELECT COUNT(*) FROM {$table} t WHERE {$where_sql}";
        if ( ! empty( $values ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $values );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Pagination.
        $per_page = isset( $args['per_page'] ) ? min( max( absint( $args['per_page'] ), 1 ), 50 ) : 25;
        $page     = isset( $args['page'] ) ? max( absint( $args['page'] ), 1 ) : 1;
        $offset   = ( $page - 1 ) * $per_page;

        // Fetch tickets (without description for list view).
        $select_sql = "SELECT t.ticket_id, t.ticket_uuid, t.title, t.type, t.priority, t.status,
                              t.creator_user_id, t.resolved_at, t.created_at, t.updated_at
                       FROM {$table} t
                       WHERE {$where_sql}
                       ORDER BY t.created_at DESC
                       LIMIT %d OFFSET %d";
        $limit_values   = array_merge( $values, array( $per_page, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $select_sql, $limit_values ), ARRAY_A );

        // Enrich with user data + computed fields.
        $tickets = array_map( array( $this, 'enrich_ticket_for_list' ), $rows ?: array() );

        return array(
            'tickets' => $tickets,
            'total'   => $total,
        );
    }

    /**
     * Get a single ticket by UUID with full details.
     *
     * @param string $uuid Ticket UUID.
     * @return array|null Enriched ticket with comments, or null.
     */
    public function get_ticket( $uuid ) {
        global $wpdb;

        if ( ! wp_is_uuid( $uuid ) ) {
            return null;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_ticket WHERE ticket_uuid = %s",
            $uuid
        ), ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        return $this->enrich_ticket_for_detail( $row );
    }

    /**
     * Create a new ticket.
     *
     * @param array $data { title, type, priority, description }
     * @return array|WP_Error Created ticket or error.
     */
    public function create_ticket( $data ) {
        global $wpdb;

        $title       = isset( $data['title'] ) ? sanitize_text_field( trim( $data['title'] ) ) : '';
        $type        = isset( $data['type'] ) ? $data['type'] : '';
        $priority    = isset( $data['priority'] ) ? $data['priority'] : 'medium';
        $description = isset( $data['description'] ) ? wp_kses_post( trim( $data['description'] ) ) : '';

        // Validate.
        if ( empty( $title ) ) {
            return new WP_Error( 'missing_title', __( 'Title is required.', 'hl-core' ) );
        }
        if ( strlen( $title ) > 255 ) {
            return new WP_Error( 'title_too_long', __( 'Title must be 255 characters or fewer.', 'hl-core' ) );
        }
        if ( ! in_array( $type, self::VALID_TYPES, true ) ) {
            return new WP_Error( 'invalid_type', __( 'Invalid ticket type.', 'hl-core' ) );
        }
        if ( ! in_array( $priority, self::VALID_PRIORITIES, true ) ) {
            $priority = 'medium';
        }
        if ( empty( $description ) ) {
            return new WP_Error( 'missing_description', __( 'Description is required.', 'hl-core' ) );
        }

        $uuid   = HL_DB_Utils::generate_uuid();
        $now    = current_time( 'mysql' );
        $result = $wpdb->insert( $wpdb->prefix . 'hl_ticket', array(
            'ticket_uuid'     => $uuid,
            'title'           => $title,
            'description'     => $description,
            'type'            => $type,
            'priority'        => $priority,
            'status'          => 'open',
            'creator_user_id' => get_current_user_id(),
            'created_at'      => $now,
            'updated_at'      => $now,
        ) );

        if ( ! $result ) {
            return new WP_Error( 'db_error', __( 'Failed to create ticket.', 'hl-core' ) );
        }

        $ticket_id = $wpdb->insert_id;

        HL_Audit_Service::log( 'ticket_created', array(
            'entity_type' => 'ticket',
            'entity_id'   => $ticket_id,
            'after_data'  => array( 'title' => $title, 'type' => $type, 'priority' => $priority ),
        ) );

        return $this->get_ticket( $uuid );
    }

    /**
     * Update an existing ticket.
     *
     * @param string $uuid Ticket UUID.
     * @param array  $data { title, type, priority, description }
     * @return array|WP_Error Updated ticket or error.
     */
    public function update_ticket( $uuid, $data ) {
        global $wpdb;

        $ticket = $this->get_ticket_raw( $uuid );
        if ( ! $ticket ) {
            return new WP_Error( 'not_found', __( 'Ticket not found.', 'hl-core' ) );
        }

        if ( ! $this->can_edit( $ticket ) ) {
            // Check if it's an expired edit window.
            $user_id = get_current_user_id();
            if ( (int) $ticket['creator_user_id'] === $user_id
                 && ! in_array( $ticket['status'], self::TERMINAL_STATUSES, true ) ) {
                return new WP_Error( 'edit_expired', __( 'The 2-hour edit window for this ticket has expired.', 'hl-core' ) );
            }
            return new WP_Error( 'forbidden', __( 'You do not have permission to edit this ticket.', 'hl-core' ) );
        }

        $title       = isset( $data['title'] ) ? sanitize_text_field( trim( $data['title'] ) ) : $ticket['title'];
        $type        = isset( $data['type'] ) && in_array( $data['type'], self::VALID_TYPES, true ) ? $data['type'] : $ticket['type'];
        $priority    = isset( $data['priority'] ) && in_array( $data['priority'], self::VALID_PRIORITIES, true ) ? $data['priority'] : $ticket['priority'];
        $description = isset( $data['description'] ) ? wp_kses_post( trim( $data['description'] ) ) : $ticket['description'];

        if ( empty( $title ) ) {
            return new WP_Error( 'missing_title', __( 'Title is required.', 'hl-core' ) );
        }
        if ( strlen( $title ) > 255 ) {
            return new WP_Error( 'title_too_long', __( 'Title must be 255 characters or fewer.', 'hl-core' ) );
        }
        if ( empty( $description ) ) {
            return new WP_Error( 'missing_description', __( 'Description is required.', 'hl-core' ) );
        }

        $update_data = array(
            'title'       => $title,
            'type'        => $type,
            'priority'    => $priority,
            'description' => $description,
            'updated_at'  => current_time( 'mysql' ),
        );

        $wpdb->update(
            $wpdb->prefix . 'hl_ticket',
            $update_data,
            array( 'ticket_uuid' => $uuid )
        );

        HL_Audit_Service::log( 'ticket_updated', array(
            'entity_type' => 'ticket',
            'entity_id'   => $ticket['ticket_id'],
            'before_data' => array(
                'title'    => $ticket['title'],
                'type'     => $ticket['type'],
                'priority' => $ticket['priority'],
            ),
            'after_data'  => array(
                'title'    => $title,
                'type'     => $type,
                'priority' => $priority,
            ),
        ) );

        return $this->get_ticket( $uuid );
    }

    /**
     * Change the status of a ticket (admin only).
     *
     * @param string $uuid       Ticket UUID.
     * @param string $new_status New status.
     * @return array|WP_Error Updated ticket or error.
     */
    public function change_status( $uuid, $new_status ) {
        global $wpdb;

        if ( ! $this->is_ticket_admin() ) {
            return new WP_Error( 'forbidden', __( 'Only the admin can change ticket status.', 'hl-core' ) );
        }

        if ( ! in_array( $new_status, self::VALID_STATUSES, true ) ) {
            return new WP_Error( 'invalid_status', __( 'Invalid status.', 'hl-core' ) );
        }

        $ticket = $this->get_ticket_raw( $uuid );
        if ( ! $ticket ) {
            return new WP_Error( 'not_found', __( 'Ticket not found.', 'hl-core' ) );
        }

        $old_status = $ticket['status'];

        $update_data = array(
            'status'     => $new_status,
            'updated_at' => current_time( 'mysql' ),
        );

        // Set resolved_at when transitioning to resolved.
        if ( $new_status === 'resolved' && $old_status !== 'resolved' ) {
            $update_data['resolved_at'] = current_time( 'mysql' );
        }
        // Clear resolved_at if moving away from resolved.
        if ( $new_status !== 'resolved' && $old_status === 'resolved' ) {
            $update_data['resolved_at'] = null;
        }

        $wpdb->update(
            $wpdb->prefix . 'hl_ticket',
            $update_data,
            array( 'ticket_uuid' => $uuid )
        );

        HL_Audit_Service::log( 'ticket_status_changed', array(
            'entity_type' => 'ticket',
            'entity_id'   => $ticket['ticket_id'],
            'before_data' => array( 'status' => $old_status ),
            'after_data'  => array( 'status' => $new_status ),
        ) );

        return $this->get_ticket( $uuid );
    }

    // ─── Comments ───

    /**
     * Get comments for a ticket.
     *
     * @param int $ticket_id Ticket ID.
     * @return array[] Comment rows enriched with user data.
     */
    public function get_comments( $ticket_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_ticket_comment WHERE ticket_id = %d ORDER BY created_at ASC",
            $ticket_id
        ), ARRAY_A );

        return array_map( array( $this, 'enrich_comment' ), $rows ?: array() );
    }

    /**
     * Add a comment to a ticket.
     *
     * @param string $uuid         Ticket UUID.
     * @param string $comment_text Comment text.
     * @return array|WP_Error New comment or error.
     */
    public function add_comment( $uuid, $comment_text ) {
        global $wpdb;

        $ticket = $this->get_ticket_raw( $uuid );
        if ( ! $ticket ) {
            return new WP_Error( 'not_found', __( 'Ticket not found.', 'hl-core' ) );
        }

        $text = sanitize_textarea_field( trim( $comment_text ) );
        if ( empty( $text ) ) {
            return new WP_Error( 'empty_comment', __( 'Comment cannot be empty.', 'hl-core' ) );
        }
        if ( strlen( $text ) > 5000 ) {
            return new WP_Error( 'comment_too_long', __( 'Comment must be 5000 characters or fewer.', 'hl-core' ) );
        }

        $result = $wpdb->insert( $wpdb->prefix . 'hl_ticket_comment', array(
            'ticket_id'    => $ticket['ticket_id'],
            'user_id'      => get_current_user_id(),
            'comment_text' => $text,
            'created_at'   => current_time( 'mysql' ),
        ) );

        if ( ! $result ) {
            return new WP_Error( 'db_error', __( 'Failed to add comment.', 'hl-core' ) );
        }

        $comment_id = $wpdb->insert_id;

        HL_Audit_Service::log( 'ticket_comment_added', array(
            'entity_type' => 'ticket',
            'entity_id'   => $ticket['ticket_id'],
            'after_data'  => array( 'comment_id' => $comment_id ),
        ) );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_ticket_comment WHERE comment_id = %d",
            $comment_id
        ), ARRAY_A );

        return $this->enrich_comment( $row );
    }

    // ─── Internal Helpers ───

    /**
     * Get raw ticket row by UUID (no enrichment).
     */
    private function get_ticket_raw( $uuid ) {
        global $wpdb;
        if ( ! wp_is_uuid( $uuid ) ) {
            return null;
        }
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hl_ticket WHERE ticket_uuid = %s",
            $uuid
        ), ARRAY_A );
    }

    /**
     * Enrich a ticket row for list view (no description, no comments).
     */
    private function enrich_ticket_for_list( $row ) {
        $user = get_userdata( $row['creator_user_id'] );
        $row['creator_name']   = $user ? $user->display_name : __( 'Unknown User', 'hl-core' );
        $row['creator_avatar'] = get_avatar_url( $row['creator_user_id'], array( 'size' => 64 ) );
        $row['can_edit']       = $this->can_edit( $row );
        $row['time_ago']       = human_time_diff( strtotime( $row['created_at'] ), strtotime( current_time( 'mysql' ) ) ) . ' ago';
        return $row;
    }

    /**
     * Enrich a ticket row for detail view (with description + comments).
     */
    private function enrich_ticket_for_detail( $row ) {
        $row = $this->enrich_ticket_for_list( $row );
        $row['comments']     = $this->get_comments( $row['ticket_id'] );
        $row['comment_count'] = count( $row['comments'] );
        return $row;
    }

    /**
     * Enrich a comment row with user data.
     */
    private function enrich_comment( $row ) {
        $user = get_userdata( $row['user_id'] );
        $row['user_name']   = $user ? $user->display_name : __( 'Unknown User', 'hl-core' );
        $row['user_avatar'] = get_avatar_url( $row['user_id'], array( 'size' => 64 ) );
        $row['time_ago']    = human_time_diff( strtotime( $row['created_at'] ), strtotime( current_time( 'mysql' ) ) ) . ' ago';
        return $row;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-ticket-service.php
git commit -m "feat(feature-tracker): add HL_Ticket_Service with CRUD, permissions, audit logging"
```

---

## Task 3: Frontend Feature Tracker (Shortcode + AJAX + HTML)

**Files:**
- Create: `includes/frontend/class-hl-frontend-feature-tracker.php`
- Modify: `includes/frontend/class-hl-shortcodes.php` (add shortcode registration + render method)

- [ ] **Step 1: Create `HL_Frontend_Feature_Tracker` class**

Create `includes/frontend/class-hl-frontend-feature-tracker.php`. This is the largest file — it renders the page HTML and handles all 6 AJAX endpoints. The HTML is inline PHP (matching the codebase pattern of `HL_Frontend_Coach_Dashboard`, `HL_Frontend_User_Profile`, etc.).

```php
<?php
/**
 * Feature Tracker frontend page.
 *
 * Single-page AJAX app: filterable ticket table + modal detail/create/edit views.
 * Access: manage_hl_core capability only.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_Frontend_Feature_Tracker {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Register AJAX handlers (logged-in only — no nopriv).
        add_action( 'wp_ajax_hl_ticket_list',    array( $this, 'ajax_ticket_list' ) );
        add_action( 'wp_ajax_hl_ticket_get',     array( $this, 'ajax_ticket_get' ) );
        add_action( 'wp_ajax_hl_ticket_create',  array( $this, 'ajax_ticket_create' ) );
        add_action( 'wp_ajax_hl_ticket_update',  array( $this, 'ajax_ticket_update' ) );
        add_action( 'wp_ajax_hl_ticket_comment', array( $this, 'ajax_ticket_comment' ) );
        add_action( 'wp_ajax_hl_ticket_status',  array( $this, 'ajax_ticket_status' ) );
    }

    // ─── Shortcode Render ───

    /**
     * Render the [hl_feature_tracker] shortcode.
     */
    public function render( $atts ) {
        ob_start();

        if ( ! is_user_logged_in() || ! current_user_can( 'manage_hl_core' ) ) {
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'You do not have permission to view this page.', 'hl-core' ) . '</div>';
            return ob_get_clean();
        }

        $nonce    = wp_create_nonce( 'hl_feature_tracker' );
        $is_admin = HL_Ticket_Service::instance()->is_ticket_admin();
        ?>
        <div class="hlft-wrapper" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-is-admin="<?php echo $is_admin ? '1' : '0'; ?>">

            <!-- Page Hero -->
            <div class="hl-page-hero">
                <div class="hl-page-hero__icon"><span class="dashicons dashicons-feedback"></span></div>
                <h1 class="hl-page-hero__title"><?php esc_html_e( 'Feature Tracker', 'hl-core' ); ?></h1>
                <p class="hl-page-hero__subtitle"><?php esc_html_e( 'Report bugs, suggest improvements, request features', 'hl-core' ); ?></p>
            </div>

            <!-- Toolbar -->
            <div class="hlft-toolbar">
                <button type="button" class="hl-btn hl-btn-primary" id="hlft-new-ticket-btn">+ <?php esc_html_e( 'New Ticket', 'hl-core' ); ?></button>
                <div class="hlft-filters">
                    <select id="hlft-filter-type" class="hlft-filter-select">
                        <option value=""><?php esc_html_e( 'All Types', 'hl-core' ); ?></option>
                        <option value="bug"><?php esc_html_e( 'Bug', 'hl-core' ); ?></option>
                        <option value="improvement"><?php esc_html_e( 'Improvement', 'hl-core' ); ?></option>
                        <option value="feature_request"><?php esc_html_e( 'Feature Request', 'hl-core' ); ?></option>
                    </select>
                    <select id="hlft-filter-status" class="hlft-filter-select">
                        <option value=""><?php esc_html_e( 'Open (default)', 'hl-core' ); ?></option>
                        <option value="open"><?php esc_html_e( 'Open', 'hl-core' ); ?></option>
                        <option value="in_review"><?php esc_html_e( 'In Review', 'hl-core' ); ?></option>
                        <option value="in_progress"><?php esc_html_e( 'In Progress', 'hl-core' ); ?></option>
                        <option value="resolved"><?php esc_html_e( 'Resolved', 'hl-core' ); ?></option>
                        <option value="closed"><?php esc_html_e( 'Closed', 'hl-core' ); ?></option>
                        <option value="all"><?php esc_html_e( 'All Statuses', 'hl-core' ); ?></option>
                    </select>
                    <select id="hlft-filter-priority" class="hlft-filter-select">
                        <option value=""><?php esc_html_e( 'All Priorities', 'hl-core' ); ?></option>
                        <option value="low"><?php esc_html_e( 'Low', 'hl-core' ); ?></option>
                        <option value="medium"><?php esc_html_e( 'Medium', 'hl-core' ); ?></option>
                        <option value="high"><?php esc_html_e( 'High', 'hl-core' ); ?></option>
                        <option value="critical"><?php esc_html_e( 'Critical', 'hl-core' ); ?></option>
                    </select>
                    <input type="text" id="hlft-search" class="hlft-search-input" placeholder="<?php esc_attr_e( 'Search tickets...', 'hl-core' ); ?>">
                </div>
            </div>

            <!-- Filter indicator -->
            <div class="hlft-filter-indicator" id="hlft-filter-indicator">
                <?php esc_html_e( 'Closed tickets hidden', 'hl-core' ); ?> — <a href="#" id="hlft-show-all"><?php esc_html_e( 'show all', 'hl-core' ); ?></a>
            </div>

            <!-- Ticket Table -->
            <div class="hlft-table-wrap" id="hlft-table-wrap">
                <div class="hlft-loading" id="hlft-table-loading"><span class="dashicons dashicons-update hlft-spin"></span></div>
                <table class="hlft-table" id="hlft-table">
                    <thead>
                        <tr>
                            <th class="hlft-th-type"><?php esc_html_e( 'Type', 'hl-core' ); ?></th>
                            <th class="hlft-th-title"><?php esc_html_e( 'Title', 'hl-core' ); ?></th>
                            <th class="hlft-th-priority"><?php esc_html_e( 'Priority', 'hl-core' ); ?></th>
                            <th class="hlft-th-submitter"><?php esc_html_e( 'Submitted by', 'hl-core' ); ?></th>
                            <th class="hlft-th-status"><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                            <th class="hlft-th-date"><?php esc_html_e( 'Date', 'hl-core' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="hlft-table-body"></tbody>
                </table>
                <div class="hlft-empty" id="hlft-empty" style="display:none;">
                    <?php esc_html_e( 'No tickets yet. Click "+ New Ticket" to submit the first one.', 'hl-core' ); ?>
                </div>
                <div class="hlft-no-results" id="hlft-no-results" style="display:none;">
                    <?php esc_html_e( 'No tickets match your filters.', 'hl-core' ); ?> — <a href="#" id="hlft-clear-filters"><?php esc_html_e( 'Clear filters', 'hl-core' ); ?></a>
                </div>
            </div>

            <!-- Detail Modal -->
            <div class="hlft-modal" id="hlft-detail-modal" style="display:none;">
                <div class="hlft-modal-box">
                    <div class="hlft-modal-header">
                        <div class="hlft-modal-title-row">
                            <span class="hlft-type-badge" id="hlft-detail-type"></span>
                            <h2 id="hlft-detail-title"></h2>
                        </div>
                        <button type="button" class="hlft-modal-close" data-close-modal>&times;</button>
                    </div>
                    <div class="hlft-modal-body">
                        <div class="hlft-modal-loading" id="hlft-detail-loading"><span class="dashicons dashicons-update hlft-spin"></span></div>
                        <div id="hlft-detail-content" style="display:none;">
                            <div class="hlft-meta-row" id="hlft-detail-meta"></div>
                            <div class="hlft-description" id="hlft-detail-description"></div>
                            <div class="hlft-detail-actions" id="hlft-detail-actions"></div>

                            <!-- Status change (admin only) -->
                            <?php if ( $is_admin ) : ?>
                            <div class="hlft-status-section">
                                <label for="hlft-status-select"><?php esc_html_e( 'Change Status:', 'hl-core' ); ?></label>
                                <select id="hlft-status-select">
                                    <option value="open"><?php esc_html_e( 'Open', 'hl-core' ); ?></option>
                                    <option value="in_review"><?php esc_html_e( 'In Review', 'hl-core' ); ?></option>
                                    <option value="in_progress"><?php esc_html_e( 'In Progress', 'hl-core' ); ?></option>
                                    <option value="resolved"><?php esc_html_e( 'Resolved', 'hl-core' ); ?></option>
                                    <option value="closed"><?php esc_html_e( 'Closed', 'hl-core' ); ?></option>
                                </select>
                                <button type="button" class="hl-btn hl-btn-small" id="hlft-status-btn"><?php esc_html_e( 'Update', 'hl-core' ); ?></button>
                            </div>
                            <?php endif; ?>

                            <!-- Comments -->
                            <div class="hlft-comments-section">
                                <h3 id="hlft-comments-header"><?php esc_html_e( 'Comments', 'hl-core' ); ?> (<span id="hlft-comment-count">0</span>)</h3>
                                <div id="hlft-comments-list"></div>
                                <div class="hlft-comment-form">
                                    <textarea id="hlft-comment-text" rows="3" placeholder="<?php esc_attr_e( 'Write a comment...', 'hl-core' ); ?>"></textarea>
                                    <button type="button" class="hl-btn hl-btn-primary hl-btn-small" id="hlft-comment-btn"><?php esc_html_e( 'Post', 'hl-core' ); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Create/Edit Modal -->
            <div class="hlft-modal" id="hlft-form-modal" style="display:none;">
                <div class="hlft-modal-box hlft-modal-box--form">
                    <div class="hlft-modal-header">
                        <h2 id="hlft-form-title"><?php esc_html_e( 'New Ticket', 'hl-core' ); ?></h2>
                        <button type="button" class="hlft-modal-close" data-close-modal>&times;</button>
                    </div>
                    <div class="hlft-modal-body">
                        <form id="hlft-ticket-form">
                            <input type="hidden" id="hlft-form-uuid" value="">
                            <div class="hlft-form-group">
                                <label for="hlft-form-title-input"><?php esc_html_e( 'Title', 'hl-core' ); ?> <span class="required">*</span></label>
                                <input type="text" id="hlft-form-title-input" maxlength="255" required>
                            </div>
                            <div class="hlft-form-group">
                                <label for="hlft-form-type"><?php esc_html_e( 'Type', 'hl-core' ); ?> <span class="required">*</span></label>
                                <select id="hlft-form-type" required>
                                    <option value=""><?php esc_html_e( 'Select type...', 'hl-core' ); ?></option>
                                    <option value="bug"><?php esc_html_e( 'Bug — Something is broken or not working correctly', 'hl-core' ); ?></option>
                                    <option value="improvement"><?php esc_html_e( 'Improvement — An existing feature could work better', 'hl-core' ); ?></option>
                                    <option value="feature_request"><?php esc_html_e( "Feature Request — A new capability that doesn't exist yet", 'hl-core' ); ?></option>
                                </select>
                            </div>
                            <div class="hlft-form-group">
                                <label for="hlft-form-priority"><?php esc_html_e( 'Priority', 'hl-core' ); ?></label>
                                <select id="hlft-form-priority">
                                    <option value="low"><?php esc_html_e( 'Low', 'hl-core' ); ?></option>
                                    <option value="medium" selected><?php esc_html_e( 'Medium', 'hl-core' ); ?></option>
                                    <option value="high"><?php esc_html_e( 'High', 'hl-core' ); ?></option>
                                    <option value="critical"><?php esc_html_e( 'Critical', 'hl-core' ); ?></option>
                                </select>
                            </div>
                            <div class="hlft-form-group">
                                <label for="hlft-form-description"><?php esc_html_e( 'Description', 'hl-core' ); ?> <span class="required">*</span></label>
                                <textarea id="hlft-form-description" rows="6" required></textarea>
                            </div>
                            <div class="hlft-form-actions">
                                <button type="submit" class="hl-btn hl-btn-primary" id="hlft-form-submit"><?php esc_html_e( 'Submit', 'hl-core' ); ?></button>
                                <button type="button" class="hl-btn" data-close-modal><?php esc_html_e( 'Cancel', 'hl-core' ); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Toast -->
            <div class="hlft-toast" id="hlft-toast" style="display:none;"></div>

        </div>
        <?php
        return ob_get_clean();
    }

    // ─── AJAX Handlers ───

    /**
     * Verify nonce + capability for all AJAX calls.
     */
    private function verify_ajax() {
        if ( ! check_ajax_referer( 'hl_feature_tracker', 'nonce', false ) ) {
            wp_send_json_error( __( 'Security check failed. Please refresh and try again.', 'hl-core' ) );
        }
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'hl-core' ) );
        }
    }

    public function ajax_ticket_list() {
        $this->verify_ajax();
        $service = HL_Ticket_Service::instance();

        $status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';
        // "all" means no status filter (include closed).
        if ( $status === 'all' ) {
            $status = 'all';
        }

        $result = $service->get_tickets( array(
            'type'     => isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : '',
            'status'   => $status,
            'priority' => isset( $_POST['priority'] ) ? sanitize_text_field( $_POST['priority'] ) : '',
            'search'   => isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '',
            'page'     => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1,
            'per_page' => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 25,
        ) );

        wp_send_json_success( $result );
    }

    public function ajax_ticket_get() {
        $this->verify_ajax();
        $uuid = isset( $_POST['ticket_uuid'] ) ? sanitize_text_field( $_POST['ticket_uuid'] ) : '';

        $ticket = HL_Ticket_Service::instance()->get_ticket( $uuid );
        if ( ! $ticket ) {
            wp_send_json_error( __( 'Ticket not found.', 'hl-core' ) );
        }

        wp_send_json_success( $ticket );
    }

    public function ajax_ticket_create() {
        $this->verify_ajax();

        $result = HL_Ticket_Service::instance()->create_ticket( array(
            'title'       => isset( $_POST['title'] ) ? $_POST['title'] : '',
            'type'        => isset( $_POST['type'] ) ? $_POST['type'] : '',
            'priority'    => isset( $_POST['priority'] ) ? $_POST['priority'] : 'medium',
            'description' => isset( $_POST['description'] ) ? $_POST['description'] : '',
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_ticket_update() {
        $this->verify_ajax();

        $uuid = isset( $_POST['ticket_uuid'] ) ? sanitize_text_field( $_POST['ticket_uuid'] ) : '';

        $result = HL_Ticket_Service::instance()->update_ticket( $uuid, array(
            'title'       => isset( $_POST['title'] ) ? $_POST['title'] : '',
            'type'        => isset( $_POST['type'] ) ? $_POST['type'] : '',
            'priority'    => isset( $_POST['priority'] ) ? $_POST['priority'] : '',
            'description' => isset( $_POST['description'] ) ? $_POST['description'] : '',
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_ticket_comment() {
        $this->verify_ajax();

        $uuid = isset( $_POST['ticket_uuid'] ) ? sanitize_text_field( $_POST['ticket_uuid'] ) : '';
        $text = isset( $_POST['comment_text'] ) ? $_POST['comment_text'] : '';

        $result = HL_Ticket_Service::instance()->add_comment( $uuid, $text );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_ticket_status() {
        $this->verify_ajax();

        $uuid   = isset( $_POST['ticket_uuid'] ) ? sanitize_text_field( $_POST['ticket_uuid'] ) : '';
        $status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';

        $result = HL_Ticket_Service::instance()->change_status( $uuid, $status );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }
}
```

- [ ] **Step 2: Register shortcode in `HL_Shortcodes`**

In `includes/frontend/class-hl-shortcodes.php`, add the shortcode registration in `__construct()` (after the last `add_shortcode` call, around line 205):

```php
        add_shortcode('hl_feature_tracker', array($this, 'render_feature_tracker'));
```

And add the render method (after the last render method, around line 733):

```php
    public function render_feature_tracker($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }
        $this->ensure_frontend_assets();
        $renderer = new HL_Frontend_Feature_Tracker();
        return $renderer->render($atts);
    }
```

Note: This `new HL_Frontend_Feature_Tracker()` creates a throwaway instance just for rendering, which is fine. The singleton instance (created in `init()`) handles AJAX.

- [ ] **Step 3: Commit**

```bash
git add includes/frontend/class-hl-frontend-feature-tracker.php includes/frontend/class-hl-shortcodes.php
git commit -m "feat(feature-tracker): add frontend shortcode page with AJAX endpoints"
```

---

## Task 4: Plugin Wiring (hl-core.php + sidebar + CLI)

**Files:**
- Modify: `hl-core.php` (~lines 129, 207, 286)
- Modify: `includes/integrations/class-hl-buddyboss-integration.php` (~lines 391-426)
- Modify: `includes/cli/class-hl-cli-create-pages.php` (~line 164)

- [ ] **Step 1: Wire require_once + instantiation in `hl-core.php`**

Add the service require (after `class-hl-tour-service.php` require, line 129):

```php
        require_once HL_CORE_INCLUDES_DIR . 'services/class-hl-ticket-service.php';
```

Add the frontend class require (after `class-hl-frontend-user-profile.php` require, line 207):

```php
        require_once HL_CORE_INCLUDES_DIR . 'frontend/class-hl-frontend-feature-tracker.php';
```

Add singleton instantiation in the `init()` method (after `HL_Tour_Service::instance();`, line 286):

```php
        // Initialize feature tracker (registers AJAX hooks)
        HL_Frontend_Feature_Tracker::instance();
```

- [ ] **Step 2: Add sidebar menu item in `HL_BuddyBoss_Integration`**

In `build_menu_items()`, add the Feature Tracker entry to **both** the coach menu and the admin menu.

For the **coach menu** (around line 400, after the `documentation` entry):

```php
                array('feature-tracker', 'hl_feature_tracker', __('Feature Tracker', 'hl-core'), 'dashicons-feedback', true),
```

For the **admin menu** (around line 421, after the `documentation` entry and before the `wp-admin` entry):

```php
                array('feature-tracker', 'hl_feature_tracker', __('Feature Tracker', 'hl-core'), 'dashicons-feedback', $is_staff),
```

- [ ] **Step 3: Add page definition to create-pages CLI**

In `includes/cli/class-hl-cli-create-pages.php`, add after the Coach Availability entry (around line 163):

```php
            // Feature tracker
            array( 'title' => 'Feature Tracker',     'shortcode' => 'hl_feature_tracker' ),
```

- [ ] **Step 4: Commit**

```bash
git add hl-core.php includes/integrations/class-hl-buddyboss-integration.php includes/cli/class-hl-cli-create-pages.php
git commit -m "feat(feature-tracker): wire plugin loading, sidebar menu, CLI page creation"
```

---

## Task 5: CSS Styles

**Files:**
- Modify: `assets/css/frontend.css` (append at end, before the LD Focus Mode section)

- [ ] **Step 1: Add `.hlft-*` CSS section**

Add the following CSS section before the "HIDE LD FOCUS MODE ELEMENTS" section (around line 9790):

```css
/* =====================================================
   FEATURE TRACKER (.hlft-*)
   ===================================================== */

/* Toolbar */
.hlft-toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.hlft-filters {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-left: auto;
}
.hlft-filter-select {
    padding: 6px 10px;
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-sm);
    font-size: 13px;
    background: var(--hl-surface);
    color: var(--hl-text);
}
.hlft-search-input {
    padding: 6px 10px;
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-sm);
    font-size: 13px;
    width: 180px;
    background: var(--hl-surface);
    color: var(--hl-text);
}

/* Filter indicator */
.hlft-filter-indicator {
    font-size: 13px;
    color: var(--hl-text-secondary);
    margin-bottom: 16px;
}
.hlft-filter-indicator a {
    color: var(--hl-interactive);
}

/* Table */
.hlft-table-wrap {
    position: relative;
    min-height: 120px;
}
.hlft-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--hl-surface);
    border-radius: var(--hl-radius);
    overflow: hidden;
    box-shadow: var(--hl-shadow);
}
.hlft-table thead th {
    padding: 10px 14px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--hl-text-secondary);
    background: var(--hl-bg);
    border-bottom: 1px solid var(--hl-border);
    text-align: left;
}
.hlft-table tbody tr {
    cursor: pointer;
    transition: background 0.15s;
}
.hlft-table tbody tr:hover {
    background: var(--hl-bg);
}
.hlft-table tbody td {
    padding: 12px 14px;
    font-size: 14px;
    color: var(--hl-text);
    border-bottom: 1px solid var(--hl-border);
    vertical-align: middle;
}
.hlft-th-type { width: 48px; }
.hlft-th-priority { width: 90px; }
.hlft-th-status { width: 110px; }
.hlft-th-date { width: 100px; }
.hlft-th-submitter { width: 160px; }

/* Type dot */
.hlft-type-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
}
.hlft-type-dot--bug { background: var(--hl-error); }
.hlft-type-dot--improvement { background: var(--hl-warning); }
.hlft-type-dot--feature_request { background: var(--hl-accent); }

/* Priority badge */
.hlft-priority-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.hlft-priority-badge--critical { background: rgba(var(--hl-error-rgb, 239,68,68), 0.12); color: var(--hl-error); }
.hlft-priority-badge--high { background: rgba(var(--hl-warning-rgb, 245,158,11), 0.12); color: var(--hl-warning-dark, #b45309); }
.hlft-priority-badge--medium { background: rgba(var(--hl-interactive-rgb, 99,102,241), 0.12); color: var(--hl-interactive); }
.hlft-priority-badge--low { background: rgba(107,114,128, 0.12); color: var(--hl-text-secondary); }

/* Status pill */
.hlft-status-pill {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.hlft-status-pill--open { background: rgba(var(--hl-interactive-rgb, 99,102,241), 0.12); color: var(--hl-interactive); }
.hlft-status-pill--in_review { background: rgba(var(--hl-warning-rgb, 245,158,11), 0.12); color: var(--hl-warning-dark, #b45309); }
.hlft-status-pill--in_progress { background: rgba(79,70,229, 0.12); color: var(--hl-interactive-dark); }
.hlft-status-pill--resolved { background: rgba(var(--hl-accent-rgb, 46,204,113), 0.12); color: var(--hl-accent-dark); }
.hlft-status-pill--closed { background: rgba(107,114,128, 0.12); color: var(--hl-text-secondary); }

/* Type badge (used in detail modal) */
.hlft-type-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.hlft-type-badge--bug { background: rgba(var(--hl-error-rgb, 239,68,68), 0.12); color: var(--hl-error); }
.hlft-type-badge--improvement { background: rgba(var(--hl-warning-rgb, 245,158,11), 0.12); color: var(--hl-warning-dark, #b45309); }
.hlft-type-badge--feature_request { background: rgba(var(--hl-accent-rgb, 46,204,113), 0.12); color: var(--hl-accent-dark); }

/* Avatar */
.hlft-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    vertical-align: middle;
    margin-right: 6px;
}

/* Submitter cell */
.hlft-submitter {
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Empty / no-results */
.hlft-empty,
.hlft-no-results {
    text-align: center;
    padding: 48px 24px;
    color: var(--hl-text-secondary);
    font-size: 14px;
}
.hlft-no-results a {
    color: var(--hl-interactive);
}

/* Loading / spinner */
.hlft-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,0.7);
    z-index: 5;
}
.hlft-spin {
    animation: hlft-spin 1s linear infinite;
    font-size: 24px;
    color: var(--hl-interactive);
}
@keyframes hlft-spin {
    100% { transform: rotate(360deg); }
}

/* ── Modal ── */
.hlft-modal {
    position: fixed;
    inset: 0;
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.5);
}
.hlft-modal-box {
    background: var(--hl-surface);
    border-radius: var(--hl-radius);
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    max-width: 640px;
    width: 95%;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
}
.hlft-modal-box--form {
    max-width: 520px;
}
.hlft-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--hl-border);
    flex-shrink: 0;
}
.hlft-modal-title-row {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.hlft-modal-title-row h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--hl-text-heading);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.hlft-modal-close {
    background: none;
    border: none;
    font-size: 22px;
    color: var(--hl-text-secondary);
    cursor: pointer;
    padding: 4px 8px;
    line-height: 1;
    flex-shrink: 0;
}
.hlft-modal-close:hover {
    color: var(--hl-text);
}
.hlft-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}
.hlft-modal-loading {
    text-align: center;
    padding: 40px;
}

/* Meta row */
.hlft-meta-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
    font-size: 13px;
    color: var(--hl-text-secondary);
}
.hlft-meta-row .hlft-avatar {
    width: 24px;
    height: 24px;
}

/* Description */
.hlft-description {
    margin-bottom: 20px;
    font-size: 14px;
    line-height: 1.6;
    color: var(--hl-text);
    white-space: pre-wrap;
    word-wrap: break-word;
}

/* Detail actions */
.hlft-detail-actions {
    margin-bottom: 20px;
}

/* Status section */
.hlft-status-section {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 0;
    border-top: 1px solid var(--hl-border);
    margin-bottom: 16px;
}
.hlft-status-section label {
    font-size: 13px;
    font-weight: 600;
    color: var(--hl-text-secondary);
    white-space: nowrap;
}
.hlft-status-section select {
    padding: 4px 8px;
    border: 1px solid var(--hl-border);
    border-radius: 8px;
    font-size: 13px;
}

/* Comments */
.hlft-comments-section {
    border-top: 1px solid var(--hl-border);
    padding-top: 16px;
}
.hlft-comments-section h3 {
    font-size: 14px;
    font-weight: 600;
    color: var(--hl-text-heading);
    margin: 0 0 12px;
}
.hlft-comment {
    display: flex;
    gap: 10px;
    margin-bottom: 14px;
}
.hlft-comment__body {
    flex: 1;
}
.hlft-comment__header {
    font-size: 13px;
    margin-bottom: 2px;
}
.hlft-comment__name {
    font-weight: 600;
    color: var(--hl-text-heading);
}
.hlft-comment__time {
    color: var(--hl-text-secondary);
    margin-left: 8px;
}
.hlft-comment__text {
    font-size: 14px;
    line-height: 1.5;
    color: var(--hl-text);
}
.hlft-comments-empty {
    color: var(--hl-text-secondary);
    font-size: 13px;
    font-style: italic;
    margin-bottom: 14px;
}
.hlft-comment-form {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    margin-top: 12px;
}
.hlft-comment-form textarea {
    flex: 1;
    padding: 8px 10px;
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-sm);
    font-size: 13px;
    font-family: inherit;
    resize: vertical;
    min-height: 60px;
}

/* ── Form ── */
.hlft-form-group {
    margin-bottom: 14px;
}
.hlft-form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--hl-text-heading);
    margin-bottom: 4px;
}
.hlft-form-group label .required {
    color: var(--hl-error);
}
.hlft-form-group input[type="text"],
.hlft-form-group select,
.hlft-form-group textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-sm);
    font-size: 14px;
    font-family: inherit;
    color: var(--hl-text);
    background: var(--hl-surface);
    box-sizing: border-box;
}
.hlft-form-group textarea {
    resize: vertical;
    min-height: 100px;
}
.hlft-form-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    padding-top: 8px;
}

/* Toast notification */
.hlft-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: var(--hl-primary);
    color: #fff;
    padding: 10px 20px;
    border-radius: var(--hl-radius-sm);
    font-size: 14px;
    font-weight: 500;
    box-shadow: 0 4px 16px rgba(0,0,0,0.2);
    z-index: 100001;
    transition: opacity 0.3s;
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend.css
git commit -m "feat(feature-tracker): add .hlft-* CSS section for tracker UI"
```

---

## Task 6: JavaScript (AJAX + Modal + Filter Logic)

**Files:**
- Modify: `assets/js/frontend.js` (append inside the main `$(document).ready` block, before the closing `});` on the last line)

- [ ] **Step 1: Add Feature Tracker JavaScript**

Append the following inside the `$(document).ready(function() { ... })` block at the end of `assets/js/frontend.js`:

```js
        // === Feature Tracker ===
        (function() {
            var $wrap = $('.hlft-wrapper');
            if (!$wrap.length) return;

            var nonce   = $wrap.data('nonce');
            var isAdmin = $wrap.data('is-admin') === 1 || $wrap.data('is-admin') === '1';
            var currentUuid = null; // UUID of currently viewed ticket in detail modal

            // ── Helpers ──

            function ajax(action, data, callback) {
                data.action = action;
                data.nonce  = nonce;
                $.post(hlCoreAjax.ajaxurl, data, function(resp) {
                    if (resp.success) {
                        callback(resp.data);
                    } else {
                        showToast(resp.data || 'An error occurred.', true);
                    }
                }).fail(function() {
                    showToast('Request failed. Please try again.', true);
                });
            }

            function showToast(msg, isError) {
                var $t = $('#hlft-toast');
                $t.text(msg).css('background', isError ? 'var(--hl-error)' : 'var(--hl-primary)').fadeIn(200);
                setTimeout(function() { $t.fadeOut(300); }, 3000);
            }

            var typeLabels = { bug: 'Bug', improvement: 'Improvement', feature_request: 'Feature Request' };
            var statusLabels = { open: 'Open', in_review: 'In Review', in_progress: 'In Progress', resolved: 'Resolved', closed: 'Closed' };

            // ── Load Tickets ──

            function loadTickets() {
                var $body = $('#hlft-table-body');
                var $loading = $('#hlft-table-loading');
                var $empty = $('#hlft-empty');
                var $noResults = $('#hlft-no-results');
                var $table = $('#hlft-table');
                var $indicator = $('#hlft-filter-indicator');

                $loading.show();
                $table.css('opacity', '0.5');
                $empty.hide();
                $noResults.hide();

                var statusVal = $('#hlft-filter-status').val();
                var typeVal = $('#hlft-filter-type').val();
                var priorityVal = $('#hlft-filter-priority').val();
                var searchVal = $('#hlft-search').val();

                // Show/hide filter indicator
                var hasFilters = typeVal || statusVal || priorityVal || (searchVal && searchVal.length >= 2);
                if (!hasFilters && !statusVal) {
                    $indicator.show();
                } else {
                    $indicator.hide();
                }

                ajax('hl_ticket_list', {
                    type: typeVal,
                    status: statusVal || '',
                    priority: priorityVal,
                    search: searchVal
                }, function(data) {
                    $loading.hide();
                    $table.css('opacity', '1');
                    $body.empty();

                    if (data.tickets.length === 0) {
                        $table.hide();
                        if (hasFilters) {
                            $noResults.show();
                        } else {
                            $empty.show();
                        }
                        return;
                    }

                    $table.show();
                    $.each(data.tickets, function(i, t) {
                        var row = '<tr data-uuid="' + t.ticket_uuid + '">' +
                            '<td><span class="hlft-type-dot hlft-type-dot--' + t.type + '" title="' + typeLabels[t.type] + '"></span></td>' +
                            '<td><strong>#' + t.ticket_id + '</strong> ' + $('<span>').text(t.title).html() + '</td>' +
                            '<td><span class="hlft-priority-badge hlft-priority-badge--' + t.priority + '">' + t.priority + '</span></td>' +
                            '<td><span class="hlft-submitter"><img class="hlft-avatar" src="' + t.creator_avatar + '" alt=""> ' + $('<span>').text(t.creator_name).html() + '</span></td>' +
                            '<td><span class="hlft-status-pill hlft-status-pill--' + t.status + '">' + statusLabels[t.status] + '</span></td>' +
                            '<td>' + t.time_ago + '</td>' +
                            '</tr>';
                        $body.append(row);
                    });
                });
            }

            // ── Detail Modal ──

            function openDetail(uuid) {
                currentUuid = uuid;
                var $modal = $('#hlft-detail-modal');
                var $loading = $('#hlft-detail-loading');
                var $content = $('#hlft-detail-content');

                $modal.show();
                $loading.show();
                $content.hide();

                ajax('hl_ticket_get', { ticket_uuid: uuid }, function(t) {
                    $loading.hide();
                    $content.show();

                    // Header
                    $('#hlft-detail-type').attr('class', 'hlft-type-badge hlft-type-badge--' + t.type).text(typeLabels[t.type]);
                    $('#hlft-detail-title').text('#' + t.ticket_id + ' ' + t.title);

                    // Meta
                    var meta = '<span class="hlft-priority-badge hlft-priority-badge--' + t.priority + '">' + t.priority + '</span>' +
                        ' <span class="hlft-status-pill hlft-status-pill--' + t.status + '">' + statusLabels[t.status] + '</span>' +
                        ' <span>By <img class="hlft-avatar" src="' + t.creator_avatar + '" alt=""> ' + $('<span>').text(t.creator_name).html() + ' &bull; ' + t.time_ago + '</span>';
                    $('#hlft-detail-meta').html(meta);

                    // Description
                    $('#hlft-detail-description').html(t.description);

                    // Edit button
                    var $actions = $('#hlft-detail-actions');
                    $actions.empty();
                    if (t.can_edit) {
                        $actions.html('<button type="button" class="hl-btn hl-btn-small" id="hlft-edit-btn">Edit</button>');
                    }

                    // Status dropdown (admin only)
                    if (isAdmin) {
                        $('#hlft-status-select').val(t.status);
                    }

                    // Comments
                    renderComments(t.comments, t.comment_count);
                });
            }

            function renderComments(comments, count) {
                var $list = $('#hlft-comments-list');
                $list.empty();
                $('#hlft-comment-count').text(count);

                if (comments.length === 0) {
                    $list.html('<p class="hlft-comments-empty">No comments yet</p>');
                    return;
                }

                $.each(comments, function(i, c) {
                    var html = '<div class="hlft-comment">' +
                        '<img class="hlft-avatar" src="' + c.user_avatar + '" alt="">' +
                        '<div class="hlft-comment__body">' +
                        '<div class="hlft-comment__header"><span class="hlft-comment__name">' + $('<span>').text(c.user_name).html() + '</span><span class="hlft-comment__time">' + c.time_ago + '</span></div>' +
                        '<div class="hlft-comment__text">' + $('<span>').text(c.comment_text).html() + '</div>' +
                        '</div></div>';
                    $list.append(html);
                });
            }

            // ── Create / Edit Modal ──

            function openCreateModal() {
                $('#hlft-form-title').text('New Ticket');
                $('#hlft-form-uuid').val('');
                $('#hlft-form-title-input').val('');
                $('#hlft-form-type').val('');
                $('#hlft-form-priority').val('medium');
                $('#hlft-form-description').val('');
                $('#hlft-form-submit').text('Submit').prop('disabled', false);
                $('#hlft-form-modal').show();
                $('#hlft-form-title-input').focus();
            }

            function openEditModal(ticket) {
                $('#hlft-form-title').text('Edit Ticket');
                $('#hlft-form-uuid').val(ticket.ticket_uuid);
                $('#hlft-form-title-input').val(ticket.title);
                $('#hlft-form-type').val(ticket.type);
                $('#hlft-form-priority').val(ticket.priority);
                $('#hlft-form-description').val(ticket.description);
                $('#hlft-form-submit').text('Save Changes').prop('disabled', false);
                $('#hlft-detail-modal').hide();
                $('#hlft-form-modal').show();
                $('#hlft-form-title-input').focus();
            }

            function closeModal($modal) {
                $modal.hide();
                if ($modal.attr('id') === 'hlft-form-modal' && currentUuid) {
                    // If closing edit modal, reopen detail
                    // Only if we were editing (uuid was set)
                    if ($('#hlft-form-uuid').val()) {
                        openDetail(currentUuid);
                    }
                }
            }

            // ── Event Handlers ──

            // Filter changes
            $('#hlft-filter-type, #hlft-filter-status, #hlft-filter-priority').on('change', loadTickets);

            // Search (debounced 300ms, min 2 chars)
            var searchTimer;
            $('#hlft-search').on('input', function() {
                clearTimeout(searchTimer);
                var val = $(this).val();
                searchTimer = setTimeout(function() {
                    if (val.length === 0 || val.length >= 2) {
                        loadTickets();
                    }
                }, 300);
            });

            // Show all (clear default "hide closed" filter)
            $(document).on('click', '#hlft-show-all', function(e) {
                e.preventDefault();
                $('#hlft-filter-status').val('all');
                loadTickets();
            });

            // Clear filters
            $(document).on('click', '#hlft-clear-filters', function(e) {
                e.preventDefault();
                $('#hlft-filter-type').val('');
                $('#hlft-filter-status').val('');
                $('#hlft-filter-priority').val('');
                $('#hlft-search').val('');
                loadTickets();
            });

            // Row click → open detail
            $(document).on('click', '#hlft-table-body tr', function() {
                var uuid = $(this).data('uuid');
                if (uuid) openDetail(uuid);
            });

            // New ticket button
            $('#hlft-new-ticket-btn').on('click', openCreateModal);

            // Edit button (inside detail modal)
            $(document).on('click', '#hlft-edit-btn', function() {
                // Fetch fresh ticket data for edit form
                ajax('hl_ticket_get', { ticket_uuid: currentUuid }, function(t) {
                    openEditModal(t);
                });
            });

            // Form submit (create or update)
            $('#hlft-ticket-form').on('submit', function(e) {
                e.preventDefault();
                var $btn = $('#hlft-form-submit');
                var uuid = $('#hlft-form-uuid').val();
                var isEdit = !!uuid;

                $btn.prop('disabled', true).text('Submitting...');

                var data = {
                    title: $('#hlft-form-title-input').val(),
                    type: $('#hlft-form-type').val(),
                    priority: $('#hlft-form-priority').val(),
                    description: $('#hlft-form-description').val()
                };

                if (isEdit) {
                    data.ticket_uuid = uuid;
                    ajax('hl_ticket_update', data, function(t) {
                        $btn.prop('disabled', false).text('Save Changes');
                        $('#hlft-form-modal').hide();
                        showToast('Ticket updated');
                        openDetail(t.ticket_uuid);
                        loadTickets();
                    });
                } else {
                    ajax('hl_ticket_create', data, function(t) {
                        $btn.prop('disabled', false).text('Submit');
                        $('#hlft-form-modal').hide();
                        showToast('Ticket #' + t.ticket_id + ' created');
                        currentUuid = null;
                        loadTickets();
                    });
                }
            });

            // Post comment
            $('#hlft-comment-btn').on('click', function() {
                var $btn = $(this);
                var $textarea = $('#hlft-comment-text');
                var text = $.trim($textarea.val());
                if (!text) return;

                $btn.prop('disabled', true).text('Posting...');

                ajax('hl_ticket_comment', {
                    ticket_uuid: currentUuid,
                    comment_text: text
                }, function(comment) {
                    $btn.prop('disabled', false).text('Post');
                    $textarea.val('');

                    // Remove "no comments" message if present
                    $('#hlft-comments-list .hlft-comments-empty').remove();

                    // Append new comment
                    var count = parseInt($('#hlft-comment-count').text(), 10) + 1;
                    $('#hlft-comment-count').text(count);

                    var html = '<div class="hlft-comment">' +
                        '<img class="hlft-avatar" src="' + comment.user_avatar + '" alt="">' +
                        '<div class="hlft-comment__body">' +
                        '<div class="hlft-comment__header"><span class="hlft-comment__name">' + $('<span>').text(comment.user_name).html() + '</span><span class="hlft-comment__time">' + comment.time_ago + '</span></div>' +
                        '<div class="hlft-comment__text">' + $('<span>').text(comment.comment_text).html() + '</div>' +
                        '</div></div>';
                    $('#hlft-comments-list').append(html);
                });
            });

            // Status change (admin only)
            $('#hlft-status-btn').on('click', function() {
                var $btn = $(this);
                var $sel = $('#hlft-status-select');
                var newStatus = $sel.val();

                $btn.prop('disabled', true);
                $sel.prop('disabled', true);

                ajax('hl_ticket_status', {
                    ticket_uuid: currentUuid,
                    status: newStatus
                }, function(t) {
                    $btn.prop('disabled', false);
                    $sel.prop('disabled', false);
                    showToast('Status updated to ' + statusLabels[newStatus]);

                    // Update pill in detail modal
                    $('#hlft-detail-meta .hlft-status-pill').attr('class', 'hlft-status-pill hlft-status-pill--' + t.status).text(statusLabels[t.status]);

                    // Refresh table
                    loadTickets();
                });
            });

            // Close modals
            $(document).on('click', '[data-close-modal]', function() {
                closeModal($(this).closest('.hlft-modal'));
            });
            $(document).on('click', '.hlft-modal', function(e) {
                if ($(e.target).hasClass('hlft-modal')) {
                    closeModal($(this));
                }
            });
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    var $visible = $('.hlft-modal:visible').last();
                    if ($visible.length) {
                        closeModal($visible);
                    }
                }
            });

            // ── Initial Load ──
            loadTickets();

        })();
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/frontend.js
git commit -m "feat(feature-tracker): add AJAX, modal, and filter JavaScript"
```

---

## Task 7: Handle "all" Status in Service

**Files:**
- Modify: `includes/services/class-hl-ticket-service.php`

The `get_tickets()` method needs to handle the `'all'` status value from the frontend (which means "show everything including closed"). Currently the method defaults to excluding closed when status is empty, which is correct, but it doesn't handle the explicit `'all'` value.

- [ ] **Step 1: Update the status filter logic in `get_tickets()`**

In the `get_tickets()` method, change the status filtering block:

Replace:
```php
        // Status filter.
        if ( ! empty( $args['status'] ) && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
            $where[]  = 't.status = %s';
            $values[] = $args['status'];
        } elseif ( empty( $args['status'] ) ) {
            // Default: exclude closed.
            $where[] = "t.status != 'closed'";
        }
```

With:
```php
        // Status filter.
        if ( ! empty( $args['status'] ) && $args['status'] === 'all' ) {
            // "all" = no status filter (include closed).
        } elseif ( ! empty( $args['status'] ) && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
            $where[]  = 't.status = %s';
            $values[] = $args['status'];
        } else {
            // Default: exclude closed.
            $where[] = "t.status != 'closed'";
        }
```

- [ ] **Step 2: Commit**

```bash
git add includes/services/class-hl-ticket-service.php
git commit -m "fix(feature-tracker): handle 'all' status filter to include closed tickets"
```

---

## Task 8: Deploy + Verify

- [ ] **Step 1: Update STATUS.md**

Add a new section to the Build Queue:

```markdown
### Feature Tracker (April 2026)
> **Spec:** `docs/superpowers/specs/2026-04-06-feature-tracker-design.md` | **Plan:** `docs/superpowers/plans/2026-04-06-feature-tracker.md`
- [x] **DB schema** — `hl_ticket` + `hl_ticket_comment` tables added to `get_schema()`.
- [x] **Ticket Service** — `HL_Ticket_Service` with CRUD, permissions (2hr edit window, admin email constant), search/filter, status transitions, comments, audit logging.
- [x] **Frontend page** — `HL_Frontend_Feature_Tracker` shortcode `[hl_feature_tracker]`, 6 AJAX endpoints, modal UI (detail, create, edit), filter bar, search, toast notifications.
- [x] **Plugin wiring** — Loaded in `hl-core.php`, sidebar menu item for coaches + admins, CLI create-pages entry.
- [x] **CSS + JS** — `.hlft-*` design system section, jQuery AJAX handlers, modal logic, debounced search.
- [ ] **Deployed to test** — Pending.
```

- [ ] **Step 2: Update README.md "What's Implemented" section**

Add Feature Tracker to the implemented features and update the architecture tree with the new files.

- [ ] **Step 3: Commit STATUS.md + README.md**

```bash
git add STATUS.md README.md
git commit -m "docs: update STATUS.md + README.md for Feature Tracker"
```

- [ ] **Step 4: Deploy to test server**

Read `.claude/skills/deploy.md` for the exact SSH/deploy commands, then deploy the plugin to the test server.

- [ ] **Step 5: Create the Feature Tracker page via CLI**

On the test server:
```bash
wp hl-core create-pages
```

This will create the WordPress page with `[hl_feature_tracker]` shortcode if it doesn't already exist.

- [ ] **Step 6: Verify in browser**

Navigate to the Feature Tracker page on the test server. Verify:
1. Page loads with hero, toolbar, and empty state message
2. Create a test ticket (Bug type, High priority)
3. Ticket appears in table with correct type dot, priority badge, status pill, avatar
4. Click row to open detail modal — all fields populated correctly
5. Add a comment — appears without page reload
6. Edit the ticket (within 2hr window) — changes saved
7. Change status to "In Review" (as mateo@corsox.com) — pill updates
8. Filter by Type, Status, Priority — table updates
9. Search by title — table filters
10. "Closed tickets hidden — show all" link works
11. Escape key and overlay click close modals

---

## Verification Checklist

After all tasks are complete, verify:

- [ ] Both tables exist in the database (check via `wp db query "SHOW TABLES LIKE '%hl_ticket%'"`)
- [ ] Feature Tracker appears in the sidebar for both admin and coach users
- [ ] Page is only accessible to `manage_hl_core` users (test with a non-staff user)
- [ ] All 6 AJAX endpoints work (create, list, get, update, comment, status)
- [ ] Edit button only shows within 2 hours of creation for the ticket creator
- [ ] Status dropdown only shows for mateo@corsox.com
- [ ] Audit log entries created for all mutations (check `hl_audit_log` table)
- [ ] XSS prevention: try `<script>alert(1)</script>` in title and description
- [ ] No `wp_ajax_nopriv_*` handlers registered (grep to confirm)
