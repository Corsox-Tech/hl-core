<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin Email Builder
 *
 * Two-panel block-based email template editor. AJAX-driven save,
 * autosave, preview, and template CRUD. Renders via
 * HL_Email_Block_Renderer on the server side.
 *
 * @package HL_Core
 */
class HL_Admin_Email_Builder {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_hl_email_template_save', array( $this, 'ajax_save' ) );
        add_action( 'wp_ajax_hl_email_template_autosave', array( $this, 'ajax_autosave' ) );
        add_action( 'wp_ajax_hl_email_preview_search', array( $this, 'ajax_preview_search' ) );
        add_action( 'wp_ajax_hl_email_preview_render', array( $this, 'ajax_preview_render' ) );
        add_action( 'wp_ajax_hl_email_template_delete', array( $this, 'ajax_delete' ) );
        add_action( 'wp_ajax_hl_email_builder_dismiss_undo_notice', array( $this, 'ajax_dismiss_undo_notice' ) );
    }

    // =========================================================================
    // Render
    // =========================================================================

    /**
     * Render the builder page.
     *
     * @param int|null $template_id Template ID to edit, or null for new.
     */
    public function render( $template_id = null ) {
        global $wpdb;

        $template = null;
        if ( $template_id ) {
            $template = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_email_template WHERE template_id = %d",
                $template_id
            ) );
        }

        $blocks = array();
        if ( $template && ! empty( $template->blocks_json ) ) {
            $blocks = json_decode( $template->blocks_json, true );
            if ( ! is_array( $blocks ) ) {
                $blocks = array();
            }
        }

        // Check for autosave draft. Unwrap envelope (Task 8): stored value is
        // { created_at, updated_at, payload }. Downstream consumers expect
        // $draft_data to remain either null or the inner payload string.
        $draft_key  = 'hl_email_draft_' . get_current_user_id() . '_' . ( $template_id ?: 'new' );
        $draft_raw  = get_option( $draft_key, null );
        $draft_data = null;
        if ( is_string( $draft_raw ) && $draft_raw !== '' ) {
            $decoded = json_decode( $draft_raw, true );
            if ( is_array( $decoded ) && array_key_exists( 'payload', $decoded ) ) {
                // Envelope format.
                $draft_data = is_string( $decoded['payload'] ) ? $decoded['payload'] : '';
            } else {
                // Legacy raw-payload draft (pre-Task 8).
                $draft_data = $draft_raw;
            }
        }

        // Get merge tags for the toolbar dropdown.
        $registry   = HL_Email_Merge_Tag_Registry::instance();
        $tags_grouped = $registry->get_tags_grouped();

        // Enqueue assets.
        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_style( 'dashicons' );

        ?>
        <div class="wrap hl-email-builder-wrap">
            <h1>
                <?php echo $template ? esc_html__( 'Edit Email Template', 'hl-core' ) : esc_html__( 'New Email Template', 'hl-core' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hl-emails&tab=templates' ) ); ?>" class="page-title-action">&larr; <?php esc_html_e( 'Back to Templates', 'hl-core' ); ?></a>
            </h1>

            <?php if ( $draft_data ) : ?>
                <div class="notice notice-warning hl-email-draft-banner" id="hl-draft-banner">
                    <p>
                        <?php esc_html_e( 'An unsaved draft was found. ', 'hl-core' ); ?>
                        <button type="button" class="button button-small" id="hl-restore-draft"><?php esc_html_e( 'Restore', 'hl-core' ); ?></button>
                        <button type="button" class="button button-small" id="hl-discard-draft"><?php esc_html_e( 'Discard', 'hl-core' ); ?></button>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Template Settings — full-width bar -->
            <div class="hl-eb-settings-bar hl-eb-section">
                <h3><?php esc_html_e( 'Template Settings', 'hl-core' ); ?></h3>
                <div class="hl-eb-settings-fields">
                    <div class="hl-eb-field">
                        <label><?php esc_html_e( 'Template Key', 'hl-core' ); ?></label>
                        <input type="text" id="hl-eb-template-key" value="<?php echo esc_attr( $template->template_key ?? '' ); ?>" <?php echo $template ? 'readonly' : ''; ?>>
                    </div>
                    <div class="hl-eb-field hl-eb-field--wide">
                        <label><?php esc_html_e( 'Name', 'hl-core' ); ?></label>
                        <input type="text" id="hl-eb-name" value="<?php echo esc_attr( $template->name ?? '' ); ?>">
                    </div>
                    <div class="hl-eb-field hl-eb-field--wide">
                        <label><?php esc_html_e( 'Subject Line', 'hl-core' ); ?></label>
                        <input type="text" id="hl-eb-subject" value="<?php echo esc_attr( $template->subject ?? '' ); ?>" maxlength="500">
                    </div>
                    <div class="hl-eb-field">
                        <label><?php esc_html_e( 'Category', 'hl-core' ); ?></label>
                        <select id="hl-eb-category">
                            <?php
                            $cats = array( 'invitation', 'fyi', 'reminder', 'follow_up', 'manual' );
                            $current_cat = $template->category ?? 'manual';
                            foreach ( $cats as $c ) :
                            ?>
                                <option value="<?php echo esc_attr( $c ); ?>" <?php selected( $current_cat, $c ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $c ) ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hl-eb-field">
                        <label><?php esc_html_e( 'Status', 'hl-core' ); ?></label>
                        <select id="hl-eb-status">
                            <option value="draft" <?php selected( $template->status ?? 'draft', 'draft' ); ?>><?php esc_html_e( 'Draft', 'hl-core' ); ?></option>
                            <option value="active" <?php selected( $template->status ?? '', 'active' ); ?>><?php esc_html_e( 'Active', 'hl-core' ); ?></option>
                            <option value="archived" <?php selected( $template->status ?? '', 'archived' ); ?>><?php esc_html_e( 'Archived', 'hl-core' ); ?></option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="hl-email-builder" id="hl-email-builder">
                <!-- Left: Block Palette + Health + Merge Tags -->
                <div class="hl-eb-sidebar-left">
                    <div class="hl-eb-section">
                        <h3><?php esc_html_e( 'Add Block', 'hl-core' ); ?></h3>
                        <div class="hl-eb-block-palette">
                            <button type="button" class="button hl-eb-add-block" data-type="text"><?php esc_html_e( 'Text', 'hl-core' ); ?></button>
                            <button type="button" class="button hl-eb-add-block" data-type="image"><?php esc_html_e( 'Image', 'hl-core' ); ?></button>
                            <button type="button" class="button hl-eb-add-block" data-type="button"><?php esc_html_e( 'Button', 'hl-core' ); ?></button>
                            <button type="button" class="button hl-eb-add-block" data-type="divider"><?php esc_html_e( 'Divider', 'hl-core' ); ?></button>
                            <button type="button" class="button hl-eb-add-block" data-type="spacer"><?php esc_html_e( 'Spacer', 'hl-core' ); ?></button>
                            <button type="button" class="button hl-eb-add-block" data-type="columns"><?php esc_html_e( 'Columns', 'hl-core' ); ?></button>
                        </div>
                    </div>

                    <div class="hl-eb-section">
                        <h3><?php esc_html_e( 'Email Health', 'hl-core' ); ?></h3>
                        <div id="hl-eb-health" class="hl-eb-health">
                            <div class="hl-eb-health-light" id="hl-eb-health-light" data-status="green"></div>
                            <ul id="hl-eb-health-warnings"></ul>
                        </div>
                    </div>

                    <div class="hl-eb-section">
                        <h3><?php esc_html_e( 'Merge Tags', 'hl-core' ); ?></h3>
                        <div class="hl-eb-merge-tags">
                            <?php foreach ( $tags_grouped as $category => $tags ) : ?>
                                <div class="hl-eb-tag-group">
                                    <strong><?php echo esc_html( ucwords( $category ) ); ?></strong>
                                    <?php foreach ( $tags as $key => $label ) : ?>
                                        <code class="hl-eb-tag-item" data-tag="<?php echo esc_attr( $key ); ?>" title="<?php echo esc_attr( $label ); ?>">{{<?php echo esc_html( $key ); ?>}}</code>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Center: Canvas -->
                <div class="hl-eb-canvas">
                    <div class="hl-eb-canvas-header">
                        <div class="hl-eb-toolbar">
                            <div class="hl-eb-undo-group" role="group" aria-label="<?php esc_attr_e( 'Undo and redo', 'hl-core' ); ?>">
                                <button type="button" class="button" id="hl-eb-undo" disabled
                                    title="<?php esc_attr_e( 'Undo (Ctrl+Z) — Undo history clears on save', 'hl-core' ); ?>"
                                    aria-label="<?php esc_attr_e( 'Undo', 'hl-core' ); ?>">&#x21A9;</button>
                                <button type="button" class="button" id="hl-eb-redo" disabled
                                    title="<?php esc_attr_e( 'Redo (Ctrl+Y) — Undo history clears on save', 'hl-core' ); ?>"
                                    aria-label="<?php esc_attr_e( 'Redo', 'hl-core' ); ?>">&#x21AA;</button>
                            </div>
                            <button type="button" class="button button-primary" id="hl-eb-save"><?php esc_html_e( 'Save Template', 'hl-core' ); ?></button>
                            <button type="button" class="button" id="hl-eb-preview-btn">
                                <span class="dashicons dashicons-visibility" style="vertical-align:text-bottom;"></span>
                                <?php esc_html_e( 'Preview', 'hl-core' ); ?>
                            </button>
                            <span class="hl-eb-autosave-status" id="hl-eb-autosave-status"></span>
                        </div>
                        <?php
                        // A.7.8 / A.7.14 — one-time per (user, template) undo-clear notice.
                        $notice_tpl_id = $template_id ?: 0;
                        $notice_seen   = (bool) get_user_meta( get_current_user_id(), 'hl_email_builder_undo_notice_seen_' . $notice_tpl_id, true );
                        ?>
                        <div class="hl-eb-undo-notice"
                             id="hl-eb-undo-notice"
                             style="display:none;"
                             data-template-id="<?php echo (int) $notice_tpl_id; ?>">
                            <span><?php esc_html_e( 'Your undo history was cleared by saving. Undo only works within a single editing session.', 'hl-core' ); ?></span>
                            <button type="button" class="hl-eb-undo-notice-dismiss" aria-label="<?php esc_attr_e( 'Dismiss notice', 'hl-core' ); ?>">&times;</button>
                        </div>
                        <script>window.hlEmailUndoNoticeSeen = <?php echo $notice_seen ? 'true' : 'false'; ?>;</script>
                    </div>
                    <div class="hl-eb-canvas-body" id="hl-eb-blocks" style="max-width:600px;margin:0 auto;background:#fff;min-height:200px;padding:20px;border:1px solid #ddd;">
                        <!-- Blocks rendered by JS -->
                    </div>
                </div>
            </div>

            <!-- Inline Preview — full-width below canvas -->
            <div class="hl-eb-inline-preview hl-eb-section" style="margin-top:20px;">
                <h3><?php esc_html_e( 'Preview', 'hl-core' ); ?></h3>
                <div class="hl-eb-preview-controls">
                    <input type="text" id="hl-eb-preview-search" placeholder="<?php esc_attr_e( 'Search enrollments...', 'hl-core' ); ?>">
                    <select id="hl-eb-preview-enrollment" style="display:none;"></select>
                    <div class="hl-eb-preview-toggles">
                        <button type="button" class="button button-small hl-eb-preview-toggle active" data-mode="desktop"><?php esc_html_e( 'Desktop', 'hl-core' ); ?></button>
                        <button type="button" class="button button-small hl-eb-preview-toggle" data-mode="mobile"><?php esc_html_e( 'Mobile', 'hl-core' ); ?></button>
                        <button type="button" class="button button-small hl-eb-preview-toggle" data-mode="dark"><?php esc_html_e( 'Dark', 'hl-core' ); ?></button>
                    </div>
                </div>
                <div id="hl-eb-preview-frame-wrap" style="margin-top:12px;">
                    <iframe id="hl-eb-preview-frame" style="width:100%;height:500px;border:1px solid var(--eb-border);border-radius:var(--eb-radius);"></iframe>
                </div>
            </div>

            <!-- A.2 / A.6 — Preview modal (hidden until Preview button clicked) -->
            <div class="hl-eb-modal-overlay" id="hl-eb-modal" style="display:none;"
                 role="dialog" aria-modal="true" aria-labelledby="hl-eb-modal-title" aria-hidden="true">
                <div class="hl-eb-modal-header">
                    <div class="hl-eb-modal-title-wrap">
                        <strong class="hl-eb-modal-title" id="hl-eb-modal-title"><?php esc_html_e( 'Preview', 'hl-core' ); ?></strong>
                        <span class="hl-eb-modal-subtitle" id="hl-eb-modal-subtitle"></span>
                    </div>
                    <div class="hl-eb-modal-controls">
                        <div class="hl-eb-modal-devices" role="group" aria-label="<?php esc_attr_e( 'Device preview', 'hl-core' ); ?>">
                            <button type="button" class="hl-eb-modal-device active" data-mode="desktop"><?php esc_html_e( 'Desktop', 'hl-core' ); ?></button>
                            <button type="button" class="hl-eb-modal-device"         data-mode="mobile"><?php esc_html_e( 'Mobile', 'hl-core' ); ?></button>
                            <button type="button" class="hl-eb-modal-device"         data-mode="dark"><?php esc_html_e( 'Dark Backdrop', 'hl-core' ); ?></button>
                        </div>
                        <div class="hl-eb-modal-search">
                            <input type="text" id="hl-eb-modal-enrollment-search"
                                placeholder="<?php esc_attr_e( 'Search enrollments...', 'hl-core' ); ?>"
                                aria-label="<?php esc_attr_e( 'Search enrollments for preview context', 'hl-core' ); ?>">
                            <ul class="hl-eb-modal-search-results" id="hl-eb-modal-search-results" style="display:none;" role="listbox"></ul>
                        </div>
                        <button type="button" class="hl-eb-modal-close" id="hl-eb-modal-close"
                            aria-label="<?php esc_attr_e( 'Close preview', 'hl-core' ); ?>">&times;</button>
                    </div>
                </div>
                <div class="hl-eb-modal-body">
                    <div class="hl-eb-modal-skeleton" id="hl-eb-modal-skeleton">
                        <div class="hl-eb-skeleton-line" style="width:60%;"></div>
                        <div class="hl-eb-skeleton-line" style="width:90%;"></div>
                        <div class="hl-eb-skeleton-line" style="width:75%;"></div>
                        <div class="hl-eb-skeleton-line" style="width:50%;"></div>
                    </div>
                    <iframe id="hl-eb-modal-iframe" title="<?php esc_attr_e( 'Email preview', 'hl-core' ); ?>"
                            sandbox="allow-same-origin allow-popups"></iframe>
                </div>
            </div>
        </div>

        <script>
            var hlEmailBuilder = {
                templateId: <?php echo (int) ( $template_id ?: 0 ); ?>,
                blocks: <?php echo wp_json_encode( $blocks ); ?>,
                draftData: <?php echo wp_json_encode( $draft_data ); ?>,
                nonce: <?php echo wp_json_encode( wp_create_nonce( 'hl_email_builder' ) ); ?>,
                previewNonce: <?php echo wp_json_encode( wp_create_nonce( 'hl_email_preview' ) ); ?>,
                ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                previewUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php?action=hl_email_preview_render&_wpnonce=' . wp_create_nonce( 'hl_email_preview' ) ) ); ?>,
                mergeTagsGrouped: <?php echo wp_json_encode( $tags_grouped ); ?>
            };
        </script>
        <?php

        // Enqueue builder JS (loaded after the config is output).
        wp_enqueue_script(
            'hl-email-builder',
            HL_CORE_ASSETS_URL . 'js/admin/email-builder.js',
            array( 'jquery', 'wp-color-picker' ),
            HL_CORE_VERSION,
            true
        );
    }

    // =========================================================================
    // AJAX: Save Template
    // =========================================================================

    public function ajax_save() {
        check_ajax_referer( 'hl_email_builder', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_template";

        $template_id  = (int) ( $_POST['template_id'] ?? 0 );
        $template_key = sanitize_key( $_POST['template_key'] ?? '' );
        $name         = sanitize_text_field( $_POST['name'] ?? '' );
        $subject      = sanitize_text_field( $_POST['subject'] ?? '' );
        $category     = sanitize_text_field( $_POST['category'] ?? 'manual' );
        $status       = sanitize_text_field( $_POST['status'] ?? 'draft' );
        $blocks_raw   = $_POST['blocks_json'] ?? '[]';

        // Validate required fields.
        if ( empty( $template_key ) || empty( $name ) ) {
            wp_send_json_error( 'Template key and name are required.' );
        }

        // Validate status.
        if ( ! in_array( $status, array( 'draft', 'active', 'archived' ), true ) ) {
            $status = 'draft';
        }

        // Validate category.
        $valid_cats = array( 'invitation', 'fyi', 'reminder', 'follow_up', 'manual' );
        if ( ! in_array( $category, $valid_cats, true ) ) {
            $category = 'manual';
        }

        // Decode and sanitize blocks.
        $blocks = json_decode( wp_unslash( $blocks_raw ), true );
        if ( ! is_array( $blocks ) ) {
            $blocks = array();
        }
        $blocks = $this->sanitize_blocks( $blocks );
        $blocks_json = wp_json_encode( $blocks );

        // Extract merge tags used.
        $merge_tags_used = $this->extract_merge_tags( $blocks_json . ' ' . $subject );

        $data = array(
            'template_key' => $template_key,
            'name'         => $name,
            'subject'      => $subject,
            'blocks_json'  => $blocks_json,
            'category'     => $category,
            'merge_tags'   => wp_json_encode( $merge_tags_used ),
            'status'       => $status,
        );
        $format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

        if ( $template_id > 0 ) {
            // Update.
            $wpdb->update( $table, $data, array( 'template_id' => $template_id ), $format, array( '%d' ) );
        } else {
            // Insert.
            $data['created_by'] = get_current_user_id();
            $format[]           = '%d';
            $wpdb->insert( $table, $data, $format );
            $template_id = $wpdb->insert_id;
        }

        // Clear draft.
        $draft_key = 'hl_email_draft_' . get_current_user_id() . '_' . $template_id;
        delete_option( $draft_key );

        // Audit log.
        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_template_saved', array(
                'entity_type' => 'email_template',
                'entity_id'   => $template_id,
                'template_key' => $template_key,
            ) );
        }

        wp_send_json_success( array(
            'template_id' => $template_id,
            'message'     => __( 'Template saved.', 'hl-core' ),
        ) );
    }

    // =========================================================================
    // AJAX: Autosave Draft
    // =========================================================================

    public function ajax_autosave() {
        global $wpdb;

        check_ajax_referer( 'hl_email_builder', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $template_id = (int) ( $_POST['template_id'] ?? 0 );
        $key         = 'hl_email_draft_' . get_current_user_id() . '_' . ( $template_id ?: 'new' );
        $raw_payload = wp_unslash( $_POST['draft_data'] ?? '' );

        // Defense-in-depth: reject empty payloads so an accidental empty POST
        // never overwrites a good prior draft. JS should not send this in
        // normal operation.
        if ( ! is_string( $raw_payload ) || $raw_payload === '' ) {
            wp_send_json_error( 'Empty draft payload' );
        }

        // Task 8: Wrap in envelope { created_at, updated_at, payload } so the
        // Task 10 daily cleanup job can age-out stale drafts by updated_at.
        // Concurrency note: two overlapping autosaves from the same user are
        // idempotent on created_at — whichever reads the existing envelope
        // preserves the same timestamp. The first-ever save racing against
        // itself can produce slightly-different created_at values across
        // retries, but the window is microseconds and the envelope is for
        // cleanup staleness, not audit.
        $now_iso    = gmdate( 'c' );
        $created_at = $now_iso;
        $existing   = get_option( $key, null );
        if ( is_string( $existing ) && $existing !== '' ) {
            $decoded = json_decode( $existing, true );
            if ( is_array( $decoded ) && ! empty( $decoded['created_at'] ) && is_string( $decoded['created_at'] ) ) {
                // Preserve original creation timestamp across subsequent saves.
                $created_at = $decoded['created_at'];
            }
            // Legacy raw-payload drafts (no envelope) fall through and get $now_iso.
        }

        $envelope = wp_json_encode( array(
            'created_at' => $created_at,
            'updated_at' => $now_iso,
            'payload'    => $raw_payload,
        ) );

        // wp_json_encode returns false on encode failure (invalid UTF-8,
        // recursion, NaN/Inf). Don't silently persist false and lose the
        // user's in-flight draft.
        if ( $envelope === false ) {
            wp_send_json_error( 'Draft encode failed' );
        }

        update_option( $key, $envelope, 'no' ); // autoload=no

        // A.6.6 defense: belt-and-suspenders. Flip autoload=no on any row left
        // over from legacy code that may have written autoload=yes. Safe no-op
        // if the row is already autoload=no.
        $wpdb->update(
            $wpdb->options,
            array( 'autoload' => 'no' ),
            array( 'option_name' => $key )
        );

        wp_send_json_success( array( 'saved_at' => current_time( 'H:i:s' ) ) );
    }

    // =========================================================================
    // AJAX: Preview Search (Enrollment Autocomplete)
    // =========================================================================

    public function ajax_preview_search() {
        check_ajax_referer( 'hl_email_builder', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $search = sanitize_text_field( $_POST['search'] ?? '' );
        if ( strlen( $search ) < 2 ) {
            wp_send_json_success( array() );
        }

        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.enrollment_id, e.user_id, e.cycle_id, u.display_name, c.cycle_name
             FROM {$wpdb->prefix}hl_enrollment e
             JOIN {$wpdb->users} u ON u.ID = e.user_id
             JOIN {$wpdb->prefix}hl_cycle c ON c.cycle_id = e.cycle_id
             WHERE u.display_name LIKE %s AND e.status IN ('active','warning')
             ORDER BY u.display_name ASC
             LIMIT 20",
            $like
        ) );

        $options = array();
        foreach ( $results as $row ) {
            $options[] = array(
                'enrollment_id' => (int) $row->enrollment_id,
                'label'         => esc_html( $row->display_name ) . ' — ' . esc_html( $row->cycle_name ),
            );
        }

        wp_send_json_success( $options );
    }

    // =========================================================================
    // AJAX: Preview Render
    // =========================================================================

    public function ajax_preview_render() {
        check_ajax_referer( 'hl_email_preview', '_wpnonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;

        // Accept both GET (legacy sidebar) and POST (v2 modal — avoids URL length limits).
        $source = ! empty( $_POST ) ? $_POST : $_GET;

        $template_id   = (int) ( $source['template_id'] ?? 0 );
        $enrollment_id = (int) ( $source['enrollment_id'] ?? 0 );
        $blocks_json   = wp_unslash( $source['blocks_json'] ?? '[]' );
        $subject       = sanitize_text_field( $source['subject'] ?? '' );
        $dark          = ! empty( $source['dark'] );

        // A.1.4 — Unicode-safe subject for preview title.
        if ( function_exists( 'mb_encode_mimeheader' ) && preg_match( '/[^\x20-\x7E]/', $subject ) ) {
            $subject_title = mb_encode_mimeheader( $subject, 'UTF-8', 'B' );
        } else {
            $subject_title = $subject;
        }

        $blocks = json_decode( $blocks_json, true );
        if ( ! is_array( $blocks ) ) {
            $blocks = array();
        }
        $blocks = $this->sanitize_blocks( $blocks );

        // Build merge tag context from enrollment.
        $context = array();
        if ( $enrollment_id ) {
            $enrollment = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
                $enrollment_id
            ) );
            if ( $enrollment ) {
                $context['user_id']         = (int) $enrollment->user_id;
                $context['cycle_id']        = (int) $enrollment->cycle_id;
                $context['enrollment_id']   = (int) $enrollment->enrollment_id;
                $context['enrollment_role'] = $enrollment->roles ?? '';

                $user = get_userdata( (int) $enrollment->user_id );
                if ( $user ) {
                    $context['recipient_user_id'] = (int) $user->ID;
                    $context['recipient_name']    = $user->display_name;
                    $context['recipient_email']   = $user->user_email;
                }

                $cycle = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}hl_cycle WHERE cycle_id = %d",
                    $enrollment->cycle_id
                ) );
                if ( $cycle ) {
                    $context['cycle_name'] = $cycle->cycle_name;
                }
            }
        }

        $registry   = HL_Email_Merge_Tag_Registry::instance();
        $merge_tags = $registry->resolve_all( $context );
        $renderer   = HL_Email_Block_Renderer::instance();
        $html       = $renderer->render( $blocks, $subject_title, $merge_tags );

        // A.2.3 — Dark Backdrop: wrap rendered HTML's body content in a dark container.
        if ( $dark ) {
            $html = preg_replace(
                '#<body([^>]*)>#i',
                '<body$1><meta name="color-scheme" content="dark"><div style="background-color:#1a1a2e;color:#e0e0e0;padding:20px;">',
                $html,
                1
            );
            $html = preg_replace( '#</body>#i', '</div></body>', $html, 1 );
        }

        // A.6.5 — CSP + security headers for the preview iframe.
        header( 'Content-Type: text/html; charset=utf-8' );
        header( "Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:" );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );

        echo $html;
        exit;
    }

    // =========================================================================
    // AJAX: Delete Template
    // =========================================================================

    public function ajax_delete() {
        check_ajax_referer( 'hl_email_builder', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $template_id = (int) ( $_POST['template_id'] ?? 0 );
        if ( ! $template_id ) {
            wp_send_json_error( 'Invalid template.' );
        }

        // Archive instead of hard delete.
        $wpdb->update(
            "{$wpdb->prefix}hl_email_template",
            array( 'status' => 'archived' ),
            array( 'template_id' => $template_id ),
            array( '%s' ),
            array( '%d' )
        );

        // Clean up draft options (hl_email_draft_{user_id}_{template_id}).
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'hl_email_draft_' ) . '%' . $wpdb->esc_like( '_' . $template_id )
        ) );

        wp_send_json_success( array( 'message' => __( 'Template archived.', 'hl-core' ) ) );
    }

    // =========================================================================
    // AJAX: Dismiss Undo Notice
    // =========================================================================

    /**
     * Mark the per-template undo-clear notice as seen for the current user.
     * A.7.8 / A.7.14 — meta key hl_email_builder_undo_notice_seen_{template_id}.
     */
    public function ajax_dismiss_undo_notice() {
        check_ajax_referer( 'hl_email_builder', 'nonce' );
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $template_id = (int) ( $_POST['template_id'] ?? 0 );
        update_user_meta(
            get_current_user_id(),
            'hl_email_builder_undo_notice_seen_' . $template_id,
            1
        );
        wp_send_json_success();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Sanitize a blocks array (wp_kses_post on text content).
     *
     * @param array $blocks Raw blocks.
     * @return array Sanitized blocks.
     */
    private function sanitize_blocks( array $blocks ) {
        foreach ( $blocks as &$block ) {
            if ( ! is_array( $block ) || empty( $block['type'] ) ) {
                continue;
            }
            switch ( $block['type'] ) {
                case 'text':
                    $block['content'] = wp_kses_post( $block['content'] ?? '' );
                    break;
                case 'image':
                    $block['src'] = esc_url( $block['src'] ?? '' );
                    $block['alt'] = sanitize_text_field( $block['alt'] ?? '' );
                    $block['link'] = esc_url( $block['link'] ?? '' );
                    // Block SVG uploads.
                    if ( preg_match( '/\.svg$/i', $block['src'] ) ) {
                        $block['src'] = '';
                    }
                    break;
                case 'button':
                    $block['label'] = sanitize_text_field( $block['label'] ?? '' );
                    $block['url']   = esc_url( $block['url'] ?? '' );
                    break;
                case 'columns':
                    $block['left']  = $this->sanitize_blocks( $block['left']  ?? array() );
                    $block['right'] = $this->sanitize_blocks( $block['right'] ?? array() );
                    break;
            }
        }
        return $blocks;
    }

    /**
     * Extract merge tag keys from content.
     *
     * @param string $content Content to scan.
     * @return array Array of tag keys found.
     */
    private function extract_merge_tags( $content ) {
        preg_match_all( '/\{\{([a-zA-Z0-9_]+)\}\}/', $content, $matches );
        return array_unique( $matches[1] ?? array() );
    }
}
