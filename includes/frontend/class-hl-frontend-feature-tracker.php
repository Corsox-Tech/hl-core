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
        add_action( 'wp_ajax_hl_ticket_cancel',  array( $this, 'ajax_ticket_cancel' ) );
        add_action( 'wp_ajax_hl_ticket_creator_review', array( $this, 'ajax_ticket_creator_review' ) );
        add_action( 'wp_ajax_hl_ticket_upload',  array( $this, 'ajax_ticket_upload' ) );
        add_action( 'wp_ajax_hl_ticket_user_search', array( $this, 'ajax_user_search' ) );
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
        <?php
        // Current user's department for the read-only form field.
        $current_user_dept = get_user_meta( get_current_user_id(), 'housman_learning_department', true );
        if ( is_array( $current_user_dept ) ) {
            $current_user_dept = implode( ', ', array_map( 'sanitize_text_field', $current_user_dept ) );
        } else {
            $current_user_dept = sanitize_text_field( (string) $current_user_dept );
        }
        if ( empty( $current_user_dept ) ) {
            $current_user_dept = __( 'Not assigned', 'hl-core' );
        }
        ?>
        <div class="hlft-wrapper"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-is-admin="<?php echo $is_admin ? '1' : '0'; ?>"
             data-current-user-id="<?php echo esc_attr( get_current_user_id() ); ?>"
             data-user-department="<?php echo esc_attr( $current_user_dept ); ?>">

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
                        <option value="ready_for_test"><?php esc_html_e( 'Ready for Review', 'hl-core' ); ?></option>
                        <option value="test_failed"><?php esc_html_e( 'Needs Revision', 'hl-core' ); ?></option>
                        <option value="resolved"><?php esc_html_e( 'Resolved', 'hl-core' ); ?></option>
                        <option value="closed"><?php esc_html_e( 'Closed', 'hl-core' ); ?></option>
                        <option value="cancelled"><?php esc_html_e( 'Cancelled', 'hl-core' ); ?></option>
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
                <?php esc_html_e( 'Closed and cancelled tickets hidden', 'hl-core' ); ?> — <a href="#" id="hlft-show-all"><?php esc_html_e( 'show all', 'hl-core' ); ?></a>
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
                            <th class="hlft-th-date"><?php esc_html_e( 'Last Updated', 'hl-core' ); ?></th>
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
                            <div class="hlft-attachments" id="hlft-detail-attachments"></div>
                            <div class="hlft-detail-actions" id="hlft-detail-actions"></div>

                            <!-- Status change (admin only) -->
                            <?php if ( $is_admin ) : ?>
                            <div class="hlft-status-section">
                                <label for="hlft-status-select"><?php esc_html_e( 'Change Status:', 'hl-core' ); ?></label>
                                <select id="hlft-status-select">
                                    <option value="open"><?php esc_html_e( 'Open', 'hl-core' ); ?></option>
                                    <option value="in_review"><?php esc_html_e( 'In Review', 'hl-core' ); ?></option>
                                    <option value="in_progress"><?php esc_html_e( 'In Progress', 'hl-core' ); ?></option>
                                    <option value="ready_for_test"><?php esc_html_e( 'Ready for Review', 'hl-core' ); ?></option>
                                    <option value="test_failed"><?php esc_html_e( 'Needs Revision', 'hl-core' ); ?></option>
                                    <option value="resolved"><?php esc_html_e( 'Resolved', 'hl-core' ); ?></option>
                                    <option value="closed"><?php esc_html_e( 'Closed', 'hl-core' ); ?></option>
                                    <option value="cancelled"><?php esc_html_e( 'Cancelled', 'hl-core' ); ?></option>
                                </select>
                                <button type="button" class="hl-btn hl-btn-small" id="hlft-status-btn"><?php esc_html_e( 'Update', 'hl-core' ); ?></button>
                            </div>
                            <?php endif; ?>

                            <!-- Comments -->
                            <div class="hlft-comments-section">
                                <h3 id="hlft-comments-header"><?php esc_html_e( 'Comments', 'hl-core' ); ?> (<span id="hlft-comment-count">0</span>)</h3>
                                <div id="hlft-comments-list"></div>
                                <div class="hlft-comment-form">
                                    <div class="hlft-comment-form__input">
                                        <textarea id="hlft-comment-text" rows="3" placeholder="<?php esc_attr_e( 'Write a comment...', 'hl-core' ); ?>"></textarea>
                                        <div class="hlft-comment-form__attach">
                                            <input type="file" id="hlft-comment-file" accept="image/*" multiple style="display:none;">
                                            <button type="button" class="hlft-attach-icon" id="hlft-comment-attach-btn" title="<?php esc_attr_e( 'Attach image', 'hl-core' ); ?>"><span class="dashicons dashicons-paperclip"></span></button>
                                            <div class="hlft-upload-preview" id="hlft-comment-preview"></div>
                                        </div>
                                    </div>
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
                                <label for="hlft-form-category"><?php esc_html_e( 'Category', 'hl-core' ); ?> <span class="required">*</span></label>
                                <select id="hlft-form-category" required>
                                    <option value="" disabled selected><?php esc_html_e( 'Select category...', 'hl-core' ); ?></option>
                                    <option value="course_content"><?php esc_html_e( 'Course Content', 'hl-core' ); ?></option>
                                    <option value="platform_issue"><?php esc_html_e( 'Platform Issue', 'hl-core' ); ?></option>
                                    <option value="account_access"><?php esc_html_e( 'Account & Access', 'hl-core' ); ?></option>
                                    <option value="forms_assessments"><?php esc_html_e( 'Forms & Assessments', 'hl-core' ); ?></option>
                                    <option value="reports_data"><?php esc_html_e( 'Reports & Data', 'hl-core' ); ?></option>
                                    <option value="other"><?php esc_html_e( 'Other', 'hl-core' ); ?></option>
                                </select>
                            </div>
                            <div class="hlft-form-group">
                                <label><?php esc_html_e( 'Department', 'hl-core' ); ?></label>
                                <div class="hlft-dept-readonly" id="hlft-form-department"></div>
                            </div>
                            <div class="hlft-form-group">
                                <label for="hlft-form-context-mode"><?php esc_html_e( 'Encountered as', 'hl-core' ); ?></label>
                                <select id="hlft-form-context-mode">
                                    <option value="self"><?php esc_html_e( 'Myself', 'hl-core' ); ?></option>
                                    <option value="view_as"><?php esc_html_e( 'Viewing as another user', 'hl-core' ); ?></option>
                                </select>
                                <div class="hlft-context-user-wrap" id="hlft-context-user-wrap" style="display:none;">
                                    <input type="hidden" id="hlft-form-context-user-id" value="">
                                    <div class="hlft-user-search-wrap">
                                        <input type="text" id="hlft-user-search-input" placeholder="<?php esc_attr_e( 'Search by name...', 'hl-core' ); ?>" autocomplete="off">
                                        <div class="hlft-user-search-results" id="hlft-user-search-results" style="display:none;"></div>
                                    </div>
                                    <div class="hlft-context-user-chip" id="hlft-context-user-chip" style="display:none;"></div>
                                </div>
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
                            <div class="hlft-form-group">
                                <label><?php esc_html_e( 'Attachments', 'hl-core' ); ?></label>
                                <div class="hlft-upload-area" id="hlft-form-upload-area">
                                    <input type="file" id="hlft-form-file" accept="image/*" multiple style="display:none;">
                                    <button type="button" class="hl-btn hl-btn-small" id="hlft-form-attach-btn"><span class="dashicons dashicons-paperclip"></span> <?php esc_html_e( 'Attach Images', 'hl-core' ); ?></button>
                                    <span class="hlft-upload-hint"><?php esc_html_e( 'JPG, PNG, GIF, WebP — max 5MB each', 'hl-core' ); ?></span>
                                    <div class="hlft-upload-preview" id="hlft-form-preview"></div>
                                </div>
                            </div>
                            <div class="hlft-form-actions">
                                <button type="submit" class="hl-btn hl-btn-primary" id="hlft-form-submit"><?php esc_html_e( 'Submit', 'hl-core' ); ?></button>
                                <button type="button" class="hl-btn" data-close-modal><?php esc_html_e( 'Cancel', 'hl-core' ); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Cancel Confirmation Modal -->
            <div class="hlft-modal" id="hlft-cancel-modal" style="display:none;">
                <div class="hlft-modal-box hlft-modal-box--confirm">
                    <div class="hlft-modal-header">
                        <h2><?php esc_html_e( 'Cancel this ticket?', 'hl-core' ); ?></h2>
                        <button type="button" class="hlft-modal-close" data-close-modal>&times;</button>
                    </div>
                    <div class="hlft-modal-body">
                        <p class="hlft-cancel-copy"><?php esc_html_e( 'Cancelled tickets are hidden from the default list and comments are preserved. Only an admin can reopen a cancelled ticket.', 'hl-core' ); ?></p>
                        <div class="hlft-form-group">
                            <label for="hlft-cancel-reason"><?php esc_html_e( 'Reason (optional)', 'hl-core' ); ?></label>
                            <textarea id="hlft-cancel-reason" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'Why are you cancelling this?', 'hl-core' ); ?>"></textarea>
                        </div>
                        <div class="hlft-form-actions">
                            <button type="button" class="hl-btn hl-btn-danger" id="hlft-cancel-confirm-btn"><?php esc_html_e( 'Cancel ticket', 'hl-core' ); ?></button>
                            <button type="button" class="hl-btn" data-close-modal><?php esc_html_e( 'Keep ticket', 'hl-core' ); ?></button>
                        </div>
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
            'title'           => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
            'type'            => isset( $_POST['type'] ) ? $_POST['type'] : '',
            'priority'        => isset( $_POST['priority'] ) ? $_POST['priority'] : 'medium',
            'description'     => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '',
            'category'        => isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : '',
            'context_mode'    => isset( $_POST['context_mode'] ) ? sanitize_text_field( $_POST['context_mode'] ) : 'self',
            'context_user_id' => ! empty( $_POST['context_user_id'] ) ? absint( $_POST['context_user_id'] ) : null,
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
            'title'           => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
            'type'            => isset( $_POST['type'] ) ? $_POST['type'] : '',
            'priority'        => isset( $_POST['priority'] ) ? $_POST['priority'] : '',
            'description'     => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '',
            'category'        => isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : '',
            'context_mode'    => isset( $_POST['context_mode'] ) ? sanitize_text_field( $_POST['context_mode'] ) : 'self',
            'context_user_id' => ! empty( $_POST['context_user_id'] ) ? absint( $_POST['context_user_id'] ) : null,
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_ticket_comment() {
        $this->verify_ajax();

        $uuid = isset( $_POST['ticket_uuid'] ) ? sanitize_text_field( $_POST['ticket_uuid'] ) : '';
        $text = isset( $_POST['comment_text'] ) ? wp_unslash( $_POST['comment_text'] ) : '';

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

    public function ajax_ticket_cancel() {
        $this->verify_ajax();

        $uuid   = isset( $_POST['ticket_uuid'] ) ? sanitize_text_field( $_POST['ticket_uuid'] ) : '';
        $reason = isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '';

        $result = HL_Ticket_Service::instance()->cancel_ticket( $uuid, $reason );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_ticket_creator_review() {
        $this->verify_ajax();

        $uuid          = sanitize_text_field( $_POST['ticket_uuid'] ?? '' );
        $review_action = sanitize_text_field( $_POST['review_action'] ?? '' );
        $comment       = isset( $_POST['comment'] ) ? wp_unslash( $_POST['comment'] ) : '';

        $result = HL_Ticket_Service::instance()->creator_review_ticket( $uuid, $review_action, $comment );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    public function ajax_ticket_upload() {
        $this->verify_ajax();

        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( __( 'No file uploaded.', 'hl-core' ) );
        }

        $uuid       = isset( $_POST['ticket_uuid'] ) ? sanitize_text_field( $_POST['ticket_uuid'] ) : '';
        $comment_id = ! empty( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : null;

        $result = HL_Ticket_Service::instance()->add_attachment( $uuid, $_FILES['file'], $comment_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_user_search() {
        $this->verify_ajax();

        $search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
        $results = HL_Ticket_Service::instance()->search_users( $search );

        wp_send_json_success( $results );
    }
}
