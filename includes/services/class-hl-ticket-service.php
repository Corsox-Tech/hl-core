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

    /** @var string[] Valid ticket categories. */
    const VALID_CATEGORIES = array( 'course_content', 'platform_issue', 'account_access', 'forms_assessments', 'reports_data', 'other' );

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
        if ( ! empty( $args['status'] ) && $args['status'] === 'all' ) {
            // "all" = no status filter (include closed).
        } elseif ( ! empty( $args['status'] ) && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
            $where[]  = 't.status = %s';
            $values[] = $args['status'];
        } else {
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
                              t.creator_user_id, t.category, t.context_mode, t.context_user_id,
                              t.resolved_at, t.created_at, t.updated_at
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
        $category      = isset( $data['category'] ) ? $data['category'] : '';
        $context_mode  = isset( $data['context_mode'] ) && $data['context_mode'] === 'view_as' ? 'view_as' : 'self';
        $context_user  = ! empty( $data['context_user_id'] ) ? absint( $data['context_user_id'] ) : null;

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

        if ( ! in_array( $category, self::VALID_CATEGORIES, true ) ) {
            return new WP_Error( 'invalid_category', __( 'Please select a category.', 'hl-core' ) );
        }

        // Context validation.
        if ( $context_mode === 'self' ) {
            $context_user = null; // Force NULL when mode is self.
        } elseif ( $context_mode === 'view_as' ) {
            if ( ! $context_user ) {
                return new WP_Error( 'missing_context_user', __( 'Please select the user you were viewing as.', 'hl-core' ) );
            }
            if ( ! get_userdata( $context_user ) ) {
                return new WP_Error( 'invalid_context_user', __( 'The selected user does not exist.', 'hl-core' ) );
            }
        }

        $uuid   = HL_DB_Utils::generate_uuid();
        $now    = current_time( 'mysql' );
        $result = $wpdb->insert( $wpdb->prefix . 'hl_ticket', array(
            'ticket_uuid'     => $uuid,
            'title'           => $title,
            'description'     => $description,
            'type'            => $type,
            'priority'        => $priority,
            'category'        => $category,
            'status'          => 'open',
            'creator_user_id' => get_current_user_id(),
            'context_mode'    => $context_mode,
            'context_user_id' => $context_user,
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
            'after_data'  => array( 'title' => $title, 'type' => $type, 'priority' => $priority, 'category' => $category ),
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
        $category     = isset( $data['category'] ) && in_array( $data['category'], self::VALID_CATEGORIES, true ) ? $data['category'] : $ticket['category'];
        $context_mode = isset( $data['context_mode'] ) && $data['context_mode'] === 'view_as' ? 'view_as' : ( isset( $data['context_mode'] ) ? 'self' : $ticket['context_mode'] );
        $context_user = null;

        if ( $context_mode === 'self' ) {
            $context_user = null;
        } elseif ( $context_mode === 'view_as' ) {
            $context_user = ! empty( $data['context_user_id'] ) ? absint( $data['context_user_id'] ) : null;
            if ( ! $context_user ) {
                return new WP_Error( 'missing_context_user', __( 'Please select the user you were viewing as.', 'hl-core' ) );
            }
            if ( ! get_userdata( $context_user ) ) {
                return new WP_Error( 'invalid_context_user', __( 'The selected user does not exist.', 'hl-core' ) );
            }
        }

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
            'title'           => $title,
            'type'            => $type,
            'priority'        => $priority,
            'category'        => $category,
            'description'     => $description,
            'context_mode'    => $context_mode,
            'context_user_id' => $context_user,
            'updated_at'      => current_time( 'mysql' ),
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
                'category' => $ticket['category'],
            ),
            'after_data'  => array(
                'title'    => $title,
                'type'     => $type,
                'priority' => $priority,
                'category' => $category,
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
        $row['comments']      = $this->get_comments( $row['ticket_id'] );
        $row['comment_count'] = count( $row['comments'] );
        $row['attachments']   = $this->get_attachments( (int) $row['ticket_id'] );

        // Department from JetEngine user meta.
        $dept = get_user_meta( $row['creator_user_id'], 'housman_learning_department', true );
        if ( is_array( $dept ) ) {
            $dept = implode( ', ', array_map( 'sanitize_text_field', $dept ) );
        } else {
            $dept = sanitize_text_field( (string) $dept );
        }
        $row['creator_department'] = ! empty( $dept ) ? $dept : __( 'Not assigned', 'hl-core' );

        // Context user resolution (for "Viewing As" feature).
        if ( $row['context_mode'] === 'view_as' && ! empty( $row['context_user_id'] ) ) {
            $ctx_user = get_userdata( $row['context_user_id'] );
            if ( $ctx_user ) {
                $row['context_user_name']   = $ctx_user->display_name;
                $row['context_user_avatar'] = get_avatar_url( $row['context_user_id'], array( 'size' => 64 ) );
                if ( function_exists( 'bp_core_get_user_domain' ) ) {
                    $row['context_user_url'] = bp_core_get_user_domain( $row['context_user_id'] );
                } else {
                    $row['context_user_url'] = get_author_posts_url( $row['context_user_id'] );
                }
            } else {
                $row['context_user_name']   = __( 'Deleted User', 'hl-core' );
                $row['context_user_avatar'] = null;
                $row['context_user_url']    = null;
            }
        }

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
        $row['attachments'] = $this->get_attachments( null, (int) $row['comment_id'] );
        return $row;
    }

    // ─── Attachments ───

    /**
     * Get attachments for a ticket or comment.
     *
     * @param int|null $ticket_id  Ticket ID (for ticket-level attachments).
     * @param int|null $comment_id Comment ID (for comment-level attachments).
     * @return array[]
     */
    public function get_attachments( $ticket_id = null, $comment_id = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hl_ticket_attachment';

        if ( $comment_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE comment_id = %d ORDER BY created_at ASC",
                $comment_id
            ), ARRAY_A ) ?: array();
        }

        if ( $ticket_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE ticket_id = %d AND comment_id IS NULL ORDER BY created_at ASC",
                $ticket_id
            ), ARRAY_A ) ?: array();
        }

        return array();
    }

    /**
     * Upload and attach a file to a ticket or comment.
     *
     * @param string $ticket_uuid Ticket UUID.
     * @param array  $file        $_FILES entry.
     * @param int|null $comment_id Optional comment ID.
     * @return array|WP_Error Attachment data or error.
     */
    public function add_attachment( $ticket_uuid, $file, $comment_id = null ) {
        $ticket = $this->get_ticket_raw( $ticket_uuid );
        if ( ! $ticket ) {
            return new WP_Error( 'not_found', __( 'Ticket not found.', 'hl-core' ) );
        }

        // Validate file type (images only).
        $allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        if ( ! in_array( $file['type'], $allowed, true ) ) {
            return new WP_Error( 'invalid_type', __( 'Only image files (JPG, PNG, GIF, WebP) are allowed.', 'hl-core' ) );
        }

        // Max 5MB.
        if ( $file['size'] > 5 * 1024 * 1024 ) {
            return new WP_Error( 'file_too_large', __( 'File must be 5MB or smaller.', 'hl-core' ) );
        }

        // Use WP upload handling.
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $upload = wp_handle_upload( $file, array( 'test_form' => false ) );
        if ( isset( $upload['error'] ) ) {
            return new WP_Error( 'upload_failed', $upload['error'] );
        }

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'hl_ticket_attachment', array(
            'ticket_id'  => $ticket['ticket_id'],
            'comment_id' => $comment_id ? absint( $comment_id ) : null,
            'user_id'    => get_current_user_id(),
            'file_url'   => $upload['url'],
            'file_name'  => sanitize_file_name( $file['name'] ),
            'mime_type'  => $upload['type'],
            'created_at' => current_time( 'mysql' ),
        ) );

        return array(
            'attachment_id' => $wpdb->insert_id,
            'file_url'      => $upload['url'],
            'file_name'     => sanitize_file_name( $file['name'] ),
            'mime_type'     => $upload['type'],
        );
    }

    /**
     * Search WordPress users by display_name for the "Viewing As" autocomplete.
     *
     * @param string $search Search term (min 3 chars).
     * @return array[] Array of { user_id, display_name, avatar_url }.
     */
    public function search_users( $search ) {
        global $wpdb;

        $search = sanitize_text_field( trim( $search ) );
        if ( strlen( $search ) < 3 ) {
            return array();
        }

        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, display_name FROM {$wpdb->users} WHERE display_name LIKE %s ORDER BY display_name ASC LIMIT 10",
            $like
        ), ARRAY_A );

        $results = array();
        foreach ( $rows ?: array() as $row ) {
            $results[] = array(
                'user_id'      => (int) $row['ID'],
                'display_name' => $row['display_name'],
                'avatar_url'   => get_avatar_url( $row['ID'], array( 'size' => 64 ) ),
            );
        }

        return $results;
    }
}
