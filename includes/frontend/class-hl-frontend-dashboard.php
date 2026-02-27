<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_dashboard] shortcode.
 *
 * Role-aware home page that replaces the Elementor LMS Home.
 * Detects user roles via HL Core enrollment data (not WP roles)
 * and renders a card grid appropriate to each user type.
 *
 * @package HL_Core
 */
class HL_Frontend_Dashboard {

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    /** @var HL_Track_Repository */
    private $track_repo;

    public function __construct() {
        $this->enrollment_repo = new HL_Enrollment_Repository();
        $this->track_repo      = new HL_Track_Repository();
    }

    /**
     * Render the dashboard.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render( $atts ) {
        ob_start();

        $user_id = get_current_user_id();
        $scope   = HL_Scope_Service::get_scope( $user_id );
        $user    = wp_get_current_user();

        // Gather user context.
        $context = $this->build_user_context( $user_id, $scope );

        ?>
        <div class="hl-dashboard hl-dashboard-home">

            <?php $this->render_welcome( $user ); ?>

            <?php if ( $context['has_enrollment'] ) : ?>
                <?php $this->render_participant_section( $context ); ?>
            <?php endif; ?>

            <?php if ( $context['is_staff'] ) : ?>
                <?php $this->render_staff_section( $context ); ?>
            <?php endif; ?>

            <?php if ( ! $context['has_enrollment'] && ! $context['is_staff'] ) : ?>
                <?php $this->render_empty_state(); ?>
            <?php endif; ?>

        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Build a context array describing the user's role situation.
     *
     * @param int   $user_id
     * @param array $scope From HL_Scope_Service.
     * @return array
     */
    private function build_user_context( $user_id, $scope ) {
        $context = array(
            'is_admin'          => $scope['is_admin'],
            'is_staff'          => $scope['is_staff'],
            'is_coach'          => $scope['is_coach'],
            'has_enrollment'    => false,
            'all_control'       => true,
            'has_program_track' => false,
            'is_mentor'         => false,
            'is_leader'         => false,
            'is_teacher'        => false,
            'enrollments'       => array(),
        );

        // Get active enrollments.
        $all_enrollments = $this->enrollment_repo->get_all( array( 'status' => 'active' ) );
        $user_enrollments = array_filter( $all_enrollments, function ( $e ) use ( $user_id ) {
            return (int) $e->user_id === $user_id;
        } );
        $user_enrollments = array_values( $user_enrollments );

        $context['enrollments']    = $user_enrollments;
        $context['has_enrollment'] = ! empty( $user_enrollments );

        foreach ( $user_enrollments as $enrollment ) {
            $roles = json_decode( $enrollment->roles, true );
            if ( ! is_array( $roles ) ) {
                $roles = array();
            }

            // Check track type.
            $track = $this->track_repo->get_by_id( $enrollment->track_id );
            if ( $track && empty( $track->is_control_group ) ) {
                $context['all_control']       = false;
                $context['has_program_track'] = true;
            }

            // Check enrollment roles.
            if ( in_array( 'mentor', $roles, true ) ) {
                $context['is_mentor'] = true;
            }
            if ( in_array( 'teacher', $roles, true ) ) {
                $context['is_teacher'] = true;
            }
            if ( in_array( 'school_leader', $roles, true ) || in_array( 'district_leader', $roles, true ) ) {
                $context['is_leader'] = true;
            }
        }

        // If no enrollments, all_control should be false.
        if ( ! $context['has_enrollment'] ) {
            $context['all_control'] = false;
        }

        return $context;
    }

    /**
     * Welcome banner with avatar and greeting.
     */
    private function render_welcome( $user ) {
        $display_name = $user->display_name ?: $user->user_login;
        $hour = (int) current_time( 'G' );

        if ( $hour < 12 ) {
            $greeting = __( 'Good morning', 'hl-core' );
        } elseif ( $hour < 17 ) {
            $greeting = __( 'Good afternoon', 'hl-core' );
        } else {
            $greeting = __( 'Good evening', 'hl-core' );
        }

        ?>
        <div class="hl-dash-welcome">
            <div class="hl-dash-welcome-avatar">
                <?php echo get_avatar( $user->ID, 64 ); ?>
            </div>
            <div class="hl-dash-welcome-text">
                <h2><?php echo esc_html( $greeting . ', ' . $display_name . '!' ); ?></h2>
                <p><?php esc_html_e( 'Welcome to Housman Learning Academy.', 'hl-core' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Participant section — cards visible to enrolled users.
     */
    private function render_participant_section( $context ) {
        ?>
        <div class="hl-dash-cards">

            <?php
            // My Programs — always shown to enrolled users.
            $this->render_nav_card(
                'hl_my_programs',
                __( 'My Programs', 'hl-core' ),
                __( 'View your assigned programs and track your progress.', 'hl-core' ),
                'dashicons-welcome-learn-more'
            );

            // My Classrooms — always shown to enrolled users.
            $this->render_nav_card(
                'hl_classrooms_listing',
                __( 'My Classrooms', 'hl-core' ),
                __( 'View your classrooms and children.', 'hl-core' ),
                'dashicons-groups'
            );
            ?>

            <?php if ( ! $context['all_control'] ) : ?>

                <?php if ( $context['has_program_track'] ) : ?>
                    <?php
                    // My Coaching — program participants (not control group).
                    $this->render_nav_card(
                        'hl_my_coaching',
                        __( 'My Coaching', 'hl-core' ),
                        __( 'View your coaching sessions and schedule.', 'hl-core' ),
                        'dashicons-format-chat'
                    );
                    ?>
                <?php endif; ?>

                <?php if ( $context['is_mentor'] ) : ?>
                    <?php
                    // My Team — mentors only.
                    $this->render_nav_card(
                        'hl_my_team',
                        __( 'My Team', 'hl-core' ),
                        __( 'View your team members and their progress.', 'hl-core' ),
                        'dashicons-admin-users'
                    );

                    // Coaching Hub — mentors.
                    $this->render_nav_card(
                        'hl_coaching_hub',
                        __( 'Coaching Hub', 'hl-core' ),
                        __( 'Manage coaching sessions for your team.', 'hl-core' ),
                        'dashicons-clipboard'
                    );
                    ?>
                <?php endif; ?>

                <?php if ( $context['is_leader'] ) : ?>
                    <?php
                    // My Track — leaders.
                    $this->render_nav_card(
                        'hl_my_track',
                        __( 'My Track', 'hl-core' ),
                        __( 'View your track overview and team performance.', 'hl-core' ),
                        'dashicons-chart-bar'
                    );
                    ?>
                <?php endif; ?>

            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Staff section — cards visible to admins and coaches.
     */
    private function render_staff_section( $context ) {
        ?>
        <div class="hl-dash-section">
            <h3><?php esc_html_e( 'Administration', 'hl-core' ); ?></h3>
            <div class="hl-dash-cards">
                <?php
                $this->render_nav_card(
                    'hl_tracks_listing',
                    __( 'Tracks', 'hl-core' ),
                    __( 'Browse and manage all tracks.', 'hl-core' ),
                    'dashicons-list-view'
                );

                $this->render_nav_card(
                    'hl_institutions_listing',
                    __( 'Institutions', 'hl-core' ),
                    __( 'View districts and schools.', 'hl-core' ),
                    'dashicons-building'
                );

                $this->render_nav_card(
                    'hl_learners',
                    __( 'Learners', 'hl-core' ),
                    __( 'Search and view all participants.', 'hl-core' ),
                    'dashicons-id'
                );

                $this->render_nav_card(
                    'hl_pathways_listing',
                    __( 'Pathways', 'hl-core' ),
                    __( 'Browse pathway configurations.', 'hl-core' ),
                    'dashicons-randomize'
                );

                $this->render_nav_card(
                    'hl_coaching_hub',
                    __( 'Coaching Hub', 'hl-core' ),
                    __( 'View and manage all coaching sessions.', 'hl-core' ),
                    'dashicons-clipboard'
                );

                $this->render_nav_card(
                    'hl_reports_hub',
                    __( 'Reports', 'hl-core' ),
                    __( 'Access reports and data exports.', 'hl-core' ),
                    'dashicons-chart-area'
                );
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single navigation card.
     *
     * @param string $shortcode The shortcode tag for the target page.
     * @param string $title     Card title.
     * @param string $desc      Card description.
     * @param string $icon      Dashicons class name.
     */
    private function render_nav_card( $shortcode, $title, $desc, $icon ) {
        $url = $this->find_shortcode_page_url( $shortcode );
        if ( empty( $url ) ) {
            return; // Page not created yet — hide card silently.
        }

        ?>
        <a href="<?php echo esc_url( $url ); ?>" class="hl-dash-card">
            <div class="hl-dash-card-icon">
                <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
            </div>
            <div class="hl-dash-card-body">
                <h4><?php echo esc_html( $title ); ?></h4>
                <p><?php echo esc_html( $desc ); ?></p>
            </div>
        </a>
        <?php
    }

    /**
     * Empty state for users with no enrollment and no staff role.
     */
    private function render_empty_state() {
        ?>
        <div class="hl-empty-state">
            <h3><?php esc_html_e( 'Welcome!', 'hl-core' ); ?></h3>
            <p><?php esc_html_e( 'You are not currently enrolled in any programs. If you believe this is an error, please contact your administrator.', 'hl-core' ); ?></p>
        </div>
        <?php
    }

    /**
     * Find the URL of a page containing a given shortcode.
     *
     * @param string $shortcode Shortcode tag (without brackets).
     * @return string URL or empty string.
     */
    private function find_shortcode_page_url( $shortcode ) {
        global $wpdb;
        $page_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like( $shortcode ) . '%'
        ) );
        return $page_id ? get_permalink( $page_id ) : '';
    }
}
