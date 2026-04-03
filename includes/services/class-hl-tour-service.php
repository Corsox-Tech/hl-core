<?php
/**
 * Tour resolution, context matching, and AJAX endpoints for guided tours.
 */
if (!defined('ABSPATH')) exit;

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
     * Returns array with keys:
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
        // TODO: optimize N+1 queries (steps + has_seen per tour) when tour count grows.
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

        $tour_id = absint( $_POST['tour_id'] ?? 0 );
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
