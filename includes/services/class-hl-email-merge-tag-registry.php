<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Merge Tag Registry
 *
 * Central registry for all email merge tags. Each tag has a label,
 * category, and resolver callback. Used by the block renderer
 * (substitution), builder UI (tag hints), and workflow editor.
 *
 * Resolver callbacks receive a context array populated by the
 * automation service or manual-send handler before rendering.
 *
 * @package HL_Core
 */
class HL_Email_Merge_Tag_Registry {

    /** @var self|null */
    private static $instance = null;

    /**
     * Registered tags: key => [ label, category, resolver ].
     * @var array
     */
    private $tags = array();

    /**
     * Deferred tags — stored as literal placeholders in body_html and
     * resolved by the queue processor at send time. These tags bypass
     * esc_html() in resolve_all() and return their raw placeholder.
     */
    const DEFERRED_TAGS = array( 'password_reset_url' );

    /** @return self */
    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_all();
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Resolve all tags against a context array.
     *
     * @param array $context Populated context (user, cycle, enrollment, etc.).
     * @return array Key => escaped value map.
     */
    public function resolve_all( array $context ) {
        $resolved = array();
        foreach ( $this->tags as $key => $tag ) {
            // Deferred tags return their literal placeholder — skip esc_html
            // so the queue processor can find and resolve them at send time.
            if ( in_array( $key, self::DEFERRED_TAGS, true ) ) {
                $resolved[ $key ] = '{{' . $key . '}}';
                continue;
            }
            $value = call_user_func( $tag['resolver'], $context );
            // esc_html on resolved values for XSS safety.
            $resolved[ $key ] = ( $value !== null && $value !== '' )
                ? esc_html( (string) $value )
                : '';
        }
        return $resolved;
    }

    /**
     * Get available tags for a given category (or all).
     * Returns tag key => label for the builder UI.
     *
     * @param string|null $category Category filter or null for all.
     * @return array Key => label.
     */
    public function get_available_tags( $category = null ) {
        $result = array();
        foreach ( $this->tags as $key => $tag ) {
            if ( $category === null || $tag['category'] === $category ) {
                $result[ $key ] = $tag['label'];
            }
        }
        return $result;
    }

    /**
     * Get tags grouped by category for the builder dropdown.
     *
     * @return array category => [ key => label, ... ].
     */
    public function get_tags_grouped() {
        $groups = array();
        foreach ( $this->tags as $key => $tag ) {
            $groups[ $tag['category'] ][ $key ] = $tag['label'];
        }
        return $groups;
    }

    // =========================================================================
    // Registration
    // =========================================================================

    /**
     * Register a single tag.
     *
     * @param string   $key      Tag key (without braces).
     * @param string   $label    Human-readable label.
     * @param string   $category Category for grouping.
     * @param callable $resolver Callback receiving context array, returns string.
     */
    public function register( $key, $label, $category, $resolver ) {
        $this->tags[ $key ] = array(
            'label'    => $label,
            'category' => $category,
            'resolver' => $resolver,
        );
    }

    /**
     * Register all built-in merge tags.
     */
    private function register_all() {
        $this->register_recipient_tags();
        $this->register_cycle_tags();
        $this->register_enrollment_tags();
        $this->register_coaching_tags();
        $this->register_assessment_tags();
        $this->register_course_tags();
        $this->register_url_tags();
    }

    // ── Recipient Tags ──────────────────────────────────────────────────────

    private function register_recipient_tags() {
        $this->register( 'recipient_first_name', 'Recipient First Name', 'recipient', function ( $ctx ) {
            $name = $ctx['recipient_name'] ?? '';
            if ( empty( $name ) && ! empty( $ctx['recipient_user_id'] ) ) {
                $user = get_userdata( (int) $ctx['recipient_user_id'] );
                $name = $user ? $user->display_name : '';
            }
            // First word of display name.
            $parts = explode( ' ', trim( $name ) );
            return $parts[0] ?? '';
        } );

        $this->register( 'recipient_full_name', 'Recipient Full Name', 'recipient', function ( $ctx ) {
            $name = $ctx['recipient_name'] ?? '';
            if ( empty( $name ) && ! empty( $ctx['recipient_user_id'] ) ) {
                $user = get_userdata( (int) $ctx['recipient_user_id'] );
                $name = $user ? $user->display_name : '';
            }
            return $name;
        } );

        $this->register( 'recipient_email', 'Recipient Email', 'recipient', function ( $ctx ) {
            $email = $ctx['recipient_email'] ?? '';
            if ( empty( $email ) && ! empty( $ctx['recipient_user_id'] ) ) {
                $user = get_userdata( (int) $ctx['recipient_user_id'] );
                $email = $user ? $user->user_email : '';
            }
            return $email;
        } );
    }

    // ── Cycle / Program Tags ────────────────────────────────────────────────

    private function register_cycle_tags() {
        $this->register( 'cycle_name', 'Cycle Name', 'cycle', function ( $ctx ) {
            return $ctx['cycle_name'] ?? '';
        } );

        $this->register( 'partnership_name', 'Partnership Name', 'cycle', function ( $ctx ) {
            return $ctx['partnership_name'] ?? '';
        } );

        $this->register( 'school_name', 'School Name', 'cycle', function ( $ctx ) {
            return $ctx['school_name'] ?? '';
        } );

        $this->register( 'school_district', 'School District', 'cycle', function ( $ctx ) {
            return $ctx['school_district'] ?? '';
        } );
    }

    // ── Enrollment / Pathway Tags ───────────────────────────────────────────

    private function register_enrollment_tags() {
        $this->register( 'pathway_name', 'Pathway Name', 'enrollment', function ( $ctx ) {
            return $ctx['pathway_name'] ?? '';
        } );

        $this->register( 'enrollment_role', 'Enrollment Role', 'enrollment', function ( $ctx ) {
            $role = $ctx['enrollment_role'] ?? '';
            return ucfirst( $role );
        } );
    }

    // ── Coaching Tags ───────────────────────────────────────────────────────

    private function register_coaching_tags() {
        $this->register( 'coach_first_name', 'Coach First Name', 'coaching', function ( $ctx ) {
            $name = $ctx['coach_name'] ?? '';
            $parts = explode( ' ', trim( $name ) );
            return $parts[0] ?? '';
        } );

        $this->register( 'coach_full_name', 'Coach Full Name', 'coaching', function ( $ctx ) {
            return $ctx['coach_name'] ?? '';
        } );

        $this->register( 'coach_email', 'Coach Email', 'coaching', function ( $ctx ) {
            return $ctx['coach_email'] ?? '';
        } );

        $this->register( 'session_date', 'Session Date', 'coaching', function ( $ctx ) {
            return $ctx['session_date'] ?? '';
        } );

        $this->register( 'zoom_link', 'Zoom Link', 'coaching', function ( $ctx ) {
            return $ctx['zoom_link'] ?? $ctx['meeting_url'] ?? '';
        } );

        $this->register( 'old_session_date', 'Old Session Date', 'coaching', function ( $ctx ) {
            return $ctx['old_session_date'] ?? '';
        } );

        $this->register( 'new_session_date', 'New Session Date', 'coaching', function ( $ctx ) {
            return $ctx['new_session_date'] ?? '';
        } );

        $this->register( 'cancelled_by_name', 'Cancelled By', 'coaching', function ( $ctx ) {
            return $ctx['cancelled_by_name'] ?? '';
        } );

        $this->register( 'mentor_full_name', 'Mentor Full Name', 'coaching', function ( $ctx ) {
            return $ctx['mentor_name'] ?? '';
        } );
    }

    // ── Assessment Tags ─────────────────────────────────────────────────────

    private function register_assessment_tags() {
        $this->register( 'assessment_type', 'Assessment Type', 'assessment', function ( $ctx ) {
            $phase = $ctx['assessment_phase'] ?? '';
            if ( strtolower( $phase ) === 'pre' ) {
                return 'Pre-Assessment';
            }
            if ( strtolower( $phase ) === 'post' ) {
                return 'Post-Assessment';
            }
            return $phase;
        } );
    }

    // ── Course Tags ─────────────────────────────────────────────────────────

    private function register_course_tags() {
        $this->register( 'course_title', 'Course Title', 'course', function ( $ctx ) {
            if ( ! empty( $ctx['course_title'] ) ) {
                return $ctx['course_title'];
            }
            if ( ! empty( $ctx['course_id'] ) ) {
                return get_the_title( (int) $ctx['course_id'] );
            }
            return '';
        } );
    }

    // ── URL Tags ────────────────────────────────────────────────────────────

    private function register_url_tags() {
        // Global URLs — cached per process (safe across batch).
        $this->register( 'login_url', 'Login URL', 'url', function ( $ctx ) {
            static $cached = null;
            if ( $cached === null ) {
                $cached = wp_login_url();
            }
            return $cached;
        } );

        $this->register( 'dashboard_url', 'Dashboard URL', 'url', function ( $ctx ) {
            static $cached = null;
            if ( $cached === null ) {
                $cached = HL_Core::get_dashboard_url();
            }
            return $cached;
        } );

        // Per-enrollment URLs — the page_id lookup is cached (it doesn't change),
        // but the full URL varies per enrollment_id so it is NOT cached.
        $this->register( 'program_page_url', 'Program Page URL', 'url', function ( $ctx ) {
            if ( ! empty( $ctx['program_page_url'] ) ) {
                return $ctx['program_page_url'];
            }
            if ( ! empty( $ctx['enrollment_id'] ) ) {
                static $page_id_cache = null;
                if ( $page_id_cache === null ) {
                    global $wpdb;
                    $like = '%' . $wpdb->esc_like( '[hl_program_page' ) . '%';
                    $page_id_cache = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
                            $like
                        )
                    );
                }
                if ( $page_id_cache ) {
                    return add_query_arg( 'enrollment_id', (int) $ctx['enrollment_id'], get_permalink( $page_id_cache ) );
                }
            }
            return '';
        } );

        // Deferred tag — NOT resolved here. Kept as literal in body_html.
        // The queue processor resolves it at send time.
        // Registered so it appears in the builder UI tag list.
        $this->register( 'password_reset_url', 'Password Reset URL (deferred)', 'url', function ( $ctx ) {
            // Return the literal placeholder — queue processor resolves this.
            return '{{password_reset_url}}';
        } );

        $this->register( 'coaching_schedule_url', 'Coaching Schedule URL', 'url', function ( $ctx ) {
            return $ctx['coaching_schedule_url'] ?? '';
        } );

        $this->register( 'cv_form_url', 'Classroom Visit Form URL', 'url', function ( $ctx ) {
            return $ctx['cv_form_url'] ?? '';
        } );

        $this->register( 'rp_session_url', 'RP Session URL', 'url', function ( $ctx ) {
            return $ctx['rp_session_url'] ?? '';
        } );
    }
}
