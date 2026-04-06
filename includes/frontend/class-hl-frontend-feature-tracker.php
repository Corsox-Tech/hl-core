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
