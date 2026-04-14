<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Queue Processor
 *
 * Manages the hl_email_queue table: enqueue emails with dedup
 * protection, process batches via wp_mail() with UUID-based atomic
 * claim pattern, stuck-row recovery, rate limiting, and retry logic.
 *
 * body_html is rendered at queue-insertion time (not send time),
 * except for deferred tags like {{password_reset_url}} which are
 * resolved immediately before wp_mail().
 *
 * @package HL_Core
 */
class HL_Email_Queue_Processor {

    /** @var self|null */
    private static $instance = null;

    /** Max retry attempts before marking as failed. */
    const MAX_ATTEMPTS = 3;

    /** Minutes before a "sending" row is considered stuck. */
    const STUCK_THRESHOLD_MINUTES = 10;

    /** @var object|null The queue row currently being sent, used by wp_mail_failed. */
    private $current_row = null;

    /** @return self */
    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_mail_failed', array( $this, 'handle_wp_mail_failed' ) );
    }

    /**
     * A.6.7: dynamic stuck-row threshold = max(10 * interval, 900s).
     */
    private function stuck_threshold_seconds() {
        $schedules = wp_get_schedules();
        $interval  = 60; // sane default
        if ( isset( $schedules['five_minutes']['interval'] ) ) {
            $interval = (int) $schedules['five_minutes']['interval'];
        } elseif ( isset( $schedules['every_minute']['interval'] ) ) {
            $interval = (int) $schedules['every_minute']['interval'];
        }
        return max( 10 * $interval, 900 );
    }

    // =========================================================================
    // Enqueue
    // =========================================================================

    /**
     * Enqueue an email for sending.
     *
     * @param array $data {
     *     @type int|null    $workflow_id       Workflow ID (NULL for manual sends).
     *     @type int|null    $template_id       Template ID.
     *     @type int|null    $recipient_user_id WordPress user ID.
     *     @type string      $recipient_email   Recipient email address.
     *     @type string      $subject           Email subject.
     *     @type string      $body_html         Fully rendered HTML body.
     *     @type array|null  $context_data      Context snapshot (JSON-encoded on insert).
     *     @type string|null $dedup_token        md5 dedup token (NULL = no dedup check).
     *     @type string      $scheduled_at      UTC datetime (default: now).
     *     @type int|null    $sent_by           User ID for manual sends, NULL for automated.
     * }
     * @return int|false Queue row ID on success, false on dedup match or failure.
     */
    public function enqueue( array $data ) {
        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_queue";

        $dedup_token = $data['dedup_token'] ?? null;

        // Dedup check: skip if token is NULL (admin override).
        if ( $dedup_token !== null ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT queue_id FROM {$table} WHERE dedup_token = %s AND status NOT IN ('cancelled') LIMIT 1",
                $dedup_token
            ) );
            if ( $existing ) {
                return false;
            }
        }

        $context_json = null;
        if ( ! empty( $data['context_data'] ) ) {
            $context_json = is_string( $data['context_data'] )
                ? $data['context_data']
                : wp_json_encode( $data['context_data'] );
        }

        $scheduled_at = $data['scheduled_at'] ?? gmdate( 'Y-m-d H:i:s' );

        $result = $wpdb->insert( $table, array(
            'workflow_id'       => $data['workflow_id']       ?? null,
            'template_id'       => $data['template_id']       ?? null,
            'recipient_user_id' => $data['recipient_user_id'] ?? null,
            'recipient_email'   => $data['recipient_email'],
            'subject'           => $data['subject'],
            'body_html'         => $data['body_html'],
            'context_data'      => $context_json,
            'dedup_token'       => $dedup_token,
            'scheduled_at'      => $scheduled_at,
            'status'            => 'pending',
            'sent_by'           => $data['sent_by'] ?? null,
        ), array(
            '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d',
        ) );

        if ( $result === false ) {
            return false;
        }

        return $wpdb->insert_id;
    }

    // =========================================================================
    // Process Batch
    // =========================================================================

    /**
     * Process a batch of pending emails.
     *
     * Uses a UUID-based atomic claim pattern to prevent double-sends
     * from concurrent cron executions.
     *
     * @param int $limit Max rows to process per batch.
     */
    public function process_batch( $limit = 50 ) {
        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_queue";

        // Recover stuck rows first.
        $this->recover_stuck_rows();

        // Generate a unique claim token.
        $claim_token = wp_generate_uuid4();

        // Atomic claim: mark pending rows as "sending" with our claim token.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'sending', claim_token = %s, updated_at = %s
             WHERE status = 'pending' AND scheduled_at <= %s
             ORDER BY scheduled_at ASC
             LIMIT %d",
            $claim_token,
            gmdate( 'Y-m-d H:i:s' ),
            gmdate( 'Y-m-d H:i:s' ),
            $limit
        ) );

        // Fetch the rows we claimed (defensive: also filter by status).
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE claim_token = %s AND status = 'sending'",
            $claim_token
        ) );

        if ( empty( $rows ) ) {
            return;
        }

        $rate_limiter = HL_Email_Rate_Limit_Service::instance();

        foreach ( $rows as $row ) {
            $this->process_single( $row, $rate_limiter );
        }
    }

    // =========================================================================
    // Single Row Processing
    // =========================================================================

    /**
     * Process a single queue row: rate-check, resolve deferred tags, send.
     *
     * @param object                    $row          Queue row object.
     * @param HL_Email_Rate_Limit_Service $rate_limiter Rate limiter instance.
     */
    private function process_single( $row, $rate_limiter ) {
        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_queue";

        // Rate limit check (if the recipient is a WP user).
        if ( $row->recipient_user_id && ! $rate_limiter->check( (int) $row->recipient_user_id ) ) {
            $wpdb->update( $table, array(
                'status'        => 'rate_limited',
                'claim_token'   => null,
                'failed_reason' => 'Per-user rate limit exceeded',
                'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
            ), array( 'queue_id' => $row->queue_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
            return;
        }

        // Resolve deferred tags in body_html.
        $body_html = $this->resolve_deferred_tags( $row->body_html, $row->recipient_user_id );

        // Temporary safety gate — restrict sends to internal domains during testing.
        // Remove this gate once email system is fully validated.
        if ( ! $this->is_domain_allowed( $row->recipient_email ) ) {
            $this->log_blocked_send( $row->queue_id, $row->recipient_email, 'domain_not_allowed' );
            $wpdb->update(
                $table,
                array( 'status' => 'blocked', 'sent_at' => gmdate( 'Y-m-d H:i:s' ) ),
                array( 'queue_id' => $row->queue_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            return;
        }

        // Send via wp_mail.
        $headers = $this->build_headers( $row );

        $encoded_subject = function_exists( 'mb_encode_mimeheader' )
            ? mb_encode_mimeheader( (string) $row->subject, 'UTF-8', 'B' )
            : (string) $row->subject;

        $this->current_row = $row;
        $sent = wp_mail( $row->recipient_email, $encoded_subject, $body_html, $headers );
        $this->current_row = null;

        if ( $sent ) {
            // Success.
            $wpdb->update( $table, array(
                'status'      => 'sent',
                'sent_at'     => gmdate( 'Y-m-d H:i:s' ),
                'claim_token' => null,
                'attempts'    => (int) $row->attempts + 1,
                'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
            ), array( 'queue_id' => $row->queue_id ), array( '%s', '%s', '%s', '%d', '%s' ), array( '%d' ) );

            // Increment rate limit counters.
            if ( $row->recipient_user_id ) {
                $rate_limiter->increment( (int) $row->recipient_user_id );
            }

            // Audit log.
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_sent', array(
                    'entity_type' => 'email_queue',
                    'entity_id'   => $row->queue_id,
                    'email'       => $row->recipient_email,
                    'subject'     => $row->subject,
                ) );
            }
        } else {
            // Failure.
            $new_attempts = (int) $row->attempts + 1;

            if ( $new_attempts >= self::MAX_ATTEMPTS ) {
                // Final failure.
                $wpdb->update( $table, array(
                    'status'        => 'failed',
                    'claim_token'   => null,
                    'attempts'      => $new_attempts,
                    'failed_reason' => 'wp_mail failed after ' . $new_attempts . ' attempts',
                    'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
                ), array( 'queue_id' => $row->queue_id ), array( '%s', '%s', '%d', '%s', '%s' ), array( '%d' ) );

                if ( class_exists( 'HL_Audit_Service' ) ) {
                    HL_Audit_Service::log( 'email_failed', array(
                        'entity_type' => 'email_queue',
                        'entity_id'   => $row->queue_id,
                        'email'       => $row->recipient_email,
                        'attempts'    => $new_attempts,
                    ) );
                }
            } else {
                // Retry with exponential backoff.
                $delay_seconds  = pow( 2, $new_attempts ) * 60; // 2min, 4min, 8min...
                $next_scheduled = gmdate( 'Y-m-d H:i:s', time() + $delay_seconds );

                $wpdb->update( $table, array(
                    'status'        => 'pending',
                    'claim_token'   => null,
                    'attempts'      => $new_attempts,
                    'scheduled_at'  => $next_scheduled,
                    'failed_reason' => 'wp_mail failed, retry #' . $new_attempts . ' at ' . $next_scheduled,
                    'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
                ), array( 'queue_id' => $row->queue_id ), array( '%s', '%s', '%d', '%s', '%s', '%s' ), array( '%d' ) );
            }
        }
    }

    // =========================================================================
    // Deferred Tag Resolution
    // =========================================================================

    /**
     * Resolve deferred tags in body_html immediately before sending.
     *
     * Uses recipient_user_id from the queue row (NOT from context_data).
     *
     * @param string   $body_html         HTML body with potential deferred tags.
     * @param int|null $recipient_user_id  WP user ID.
     * @return string Body with deferred tags resolved.
     */
    private function resolve_deferred_tags( $body_html, $recipient_user_id ) {
        // {{password_reset_url}} — generate a fresh password reset key.
        if ( strpos( $body_html, '{{password_reset_url}}' ) !== false && $recipient_user_id ) {
            $user = get_userdata( (int) $recipient_user_id );
            if ( $user ) {
                $key = get_password_reset_key( $user );
                if ( ! is_wp_error( $key ) ) {
                    $reset_url = network_site_url(
                        "wp-login.php?action=rp&key={$key}&login=" . rawurlencode( $user->user_login ),
                        'login'
                    );
                    $body_html = str_replace( '{{password_reset_url}}', esc_url( $reset_url ), $body_html );
                }
            }
        }

        // Strip any remaining deferred tags that couldn't be resolved.
        if ( strpos( $body_html, '{{password_reset_url}}' ) !== false ) {
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_deferred_tag_failed', array(
                    'entity_type' => 'email_queue',
                    'tag'         => 'password_reset_url',
                    'user_id'     => $recipient_user_id,
                ) );
            }
            $body_html = str_replace( '{{password_reset_url}}', '#', $body_html );
        }

        return $body_html;
    }

    // =========================================================================
    // Stuck Row Recovery
    // =========================================================================

    /**
     * Reset rows stuck in "sending" status for longer than the threshold.
     * Does NOT increment attempts (stuck rows indicate infrastructure issues,
     * not delivery failures).
     */
    private function recover_stuck_rows() {
        global $wpdb;
        $table     = "{$wpdb->prefix}hl_email_queue";
        $threshold = gmdate( 'Y-m-d H:i:s', time() - $this->stuck_threshold_seconds() );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'pending', claim_token = NULL, updated_at = %s
             WHERE status = 'sending' AND updated_at < %s",
            gmdate( 'Y-m-d H:i:s' ),
            $threshold
        ) );
    }

    // =========================================================================
    // Deliverability: headers + unsubscribe
    // =========================================================================

    private function build_headers( $row ) {
        $from_name  = get_option( 'hl_email_from_name',  get_bloginfo( 'name' ) );
        $from_email = get_option( 'hl_email_from_email', get_option( 'admin_email' ) );
        $reply_to   = get_option( 'hl_email_reply_to',   $from_email );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $from_name, $from_email ),
            sprintf( 'Reply-To: %s', $reply_to ),
        );

        // List-Unsubscribe wired in Task 26 (same bundle).
        $unsubscribe_url = $this->build_unsubscribe_url( $row );
        if ( $unsubscribe_url ) {
            $headers[] = sprintf( 'List-Unsubscribe: <mailto:%s?subject=unsubscribe>, <%s>', $reply_to, $unsubscribe_url );
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }

        return $headers;
    }

    private function unsubscribe_secret() {
        $secret = get_option( 'hl_email_unsubscribe_secret', '' );
        if ( $secret !== '' ) return $secret;

        $candidate = wp_generate_password( 64, true, true );
        // add_option returns false if it already exists — then re-read.
        add_option( 'hl_email_unsubscribe_secret', $candidate, '', 'no' );
        return get_option( 'hl_email_unsubscribe_secret', $candidate );
    }

    /**
     * Build a one-click unsubscribe URL with an HMAC token (A.6.3).
     * Returns '' if the recipient has no WP user (static emails can't unsubscribe).
     */
    private function build_unsubscribe_url( $row ) {
        if ( empty( $row->recipient_user_id ) ) return '';

        $secret = $this->unsubscribe_secret();
        $token  = hash_hmac( 'sha256', $row->recipient_user_id . ':' . $row->queue_id, $secret );

        return add_query_arg( array(
            'action' => 'hl_email_unsubscribe',
            'u'      => (int) $row->recipient_user_id,
            'q'      => (int) $row->queue_id,
            't'      => $token,
        ), home_url( '/' ) );
    }

    // =========================================================================
    // Domain Allowlist (Temporary Safety Gate — C.1)
    // =========================================================================

    /**
     * Check if an email domain is on the temporary allowlist.
     *
     * @param string $email Email address.
     * @return bool True if the domain is allowed.
     */
    public function is_domain_allowed( $email ) {
        $allowed_domains = array( 'housmanlearning.com', 'corsox.com', 'yopmail.com' );
        $domain = substr( strrchr( $email, '@' ), 1 );
        return in_array( strtolower( $domain ), $allowed_domains, true );
    }

    private function log_blocked_send( $queue_id, $to, $reason ) {
        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_send_blocked', array(
                'entity_type' => 'email_queue',
                'entity_id'   => $queue_id,
                'email'       => $to,
                'reason'      => $reason,
            ) );
        }
    }

    // =========================================================================
    // wp_mail_failed handler (A.6.6)
    // =========================================================================

    public function handle_wp_mail_failed( $error ) {
        if ( ! $this->current_row ) return;
        global $wpdb;
        $table = "{$wpdb->prefix}hl_email_queue";

        $wpdb->update(
            $table,
            array(
                'status'        => 'failed',
                'claim_token'   => null,
                'failed_reason' => 'wp_mail_failed: ' . substr( (string) $error->get_error_message(), 0, 200 ),
                'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'queue_id' => $this->current_row->queue_id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( class_exists( 'HL_Audit_Service' ) ) {
            HL_Audit_Service::log( 'email_wp_mail_failed', array(
                'entity_type' => 'email_queue',
                'entity_id'   => $this->current_row->queue_id,
                'reason'      => $error->get_error_message(),
            ) );
        }
    }
}
