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

    /** @var HL_Cycle_Repository */
    private $cycle_repo;

    public function __construct() {
        $this->enrollment_repo = new HL_Enrollment_Repository();
        $this->cycle_repo      = new HL_Cycle_Repository();
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
        <div class="hl-dashboard hl-dashboard-home hl-dashboard-v2">

            <?php $this->render_welcome_v2( $user ); ?>

            <?php if ( $context['has_enrollment'] ) : ?>
                <?php $this->render_participant_section_v2( $context ); ?>
            <?php endif; ?>

            <?php if ( $context['is_coach'] ) : ?>
                <?php $this->render_coach_section_v2(); ?>
            <?php endif; ?>

            <?php if ( $context['is_staff'] ) : ?>
                <?php $this->render_staff_section_v2( $context ); ?>
            <?php endif; ?>

            <?php if ( ! $context['has_enrollment'] && ! $context['is_staff'] && ! $context['is_coach'] ) : ?>
                <?php $this->render_empty_state_v2(); ?>
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
        // Detect actual coach WP role (scope service's is_coach means
        // staff-but-not-admin, which is different from the coach role).
        $wp_user  = get_userdata( $user_id );
        $is_coach = $wp_user && in_array( 'coach', (array) $wp_user->roles, true );

        $context = array(
            'is_admin'          => $scope['is_admin'],
            'is_staff'          => $scope['is_staff'],
            'is_coach'          => $is_coach || $scope['is_coach'],
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
            $cycle = $this->cycle_repo->get_by_id( $enrollment->cycle_id );
            if ( $cycle && empty( $cycle->is_control_group ) ) {
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
            $available_count = $this->count_available_activities( get_current_user_id() );
            $this->render_nav_card(
                'hl_my_programs',
                __( 'My Programs', 'hl-core' ),
                __( 'View your assigned programs and track your progress.', 'hl-core' ),
                'dashicons-welcome-learn-more',
                $available_count
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

                <?php if ( $context['is_mentor'] ) : ?>
                    <?php
                    // My Coaching — mentors only.
                    $this->render_nav_card(
                        'hl_my_coaching',
                        __( 'My Coaching', 'hl-core' ),
                        __( 'View your coaching sessions and schedule.', 'hl-core' ),
                        'dashicons-format-chat'
                    );

                    // My Team — mentors.
                    $this->render_nav_card(
                        'hl_my_team',
                        __( 'My Team', 'hl-core' ),
                        __( 'View your team members and their progress.', 'hl-core' ),
                        'dashicons-admin-users'
                    );
                    ?>
                <?php endif; ?>

                <?php if ( $context['is_teacher'] && ! $context['is_mentor'] ) : ?>
                    <?php
                    // My Team — teachers (skip if already shown for mentor).
                    $this->render_nav_card(
                        'hl_my_team',
                        __( 'My Team', 'hl-core' ),
                        __( 'View your team members and their progress.', 'hl-core' ),
                        'dashicons-admin-users'
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
                    'hl_cycles_listing',
                    __( 'Cycles', 'hl-core' ),
                    __( 'Browse and manage all cycles.', 'hl-core' ),
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
     * @param int    $badge     Optional badge count (shown when > 0).
     */
    private function render_nav_card( $shortcode, $title, $desc, $icon, $badge = 0 ) {
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
                <h4><?php echo esc_html( $title ); ?><?php if ( $badge > 0 ) : ?><span class="hl-menu-badge"><?php echo (int) $badge; ?></span><?php endif; ?></h4>
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
     * Count available (unlocked + not completed) components for a user.
     * Shares the same transient cache as HL_BuddyBoss_Integration.
     *
     * @param int $user_id
     * @return int
     */
    private function count_available_activities( $user_id ) {
        $user_id       = absint( $user_id );
        $transient_key = 'hl_avail_count_' . $user_id;

        $cached = get_transient( $transient_key );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        $pa_service     = new HL_Pathway_Assignment_Service();
        $component_repo = new HL_Component_Repository();
        $rules_engine   = new HL_Rules_Engine_Service();

        $enrollments = $this->enrollment_repo->get_by_user_id( $user_id, 'active' );
        $count       = 0;

        foreach ( $enrollments as $enrollment ) {
            $pathways = $pa_service->get_pathways_for_enrollment( $enrollment->enrollment_id );
            foreach ( $pathways as $pw ) {
                $components = $component_repo->get_by_pathway( $pw['pathway_id'] );
                foreach ( $components as $component ) {
                    if ( $component->visibility === 'staff_only' ) {
                        continue;
                    }
                    $avail = $rules_engine->compute_availability(
                        $enrollment->enrollment_id,
                        $component->component_id
                    );
                    if ( $avail['availability_status'] === 'available' ) {
                        $count++;
                    }
                }
            }
        }

        set_transient( $transient_key, $count, 5 * MINUTE_IN_SECONDS );
        return $count;
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

    // =========================================================================
    // V2 — Calm Professional Dashboard Renderers
    // =========================================================================

    /**
     * V2 Welcome hero with avatar and time-based greeting.
     */
    private function render_welcome_v2( $user ) {
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
        <div class="hl-dv2-welcome">
            <div class="hl-dv2-avatar">
                <?php echo get_avatar( $user->ID, 128 ); ?>
            </div>
            <div class="hl-dv2-welcome-text">
                <h2><?php echo esc_html( $greeting . ', ' . $display_name . '!' ); ?></h2>
                <p><?php esc_html_e( 'Welcome to Housman Learning Academy', 'hl-core' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * V2 Participant section — learning cards visible to enrolled users.
     */
    private function render_participant_section_v2( $context ) {
        $is_participant = $context['is_teacher'] || $context['is_mentor'];
        $available_count = $is_participant ? $this->count_available_activities( get_current_user_id() ) : 0;

        if ( $is_participant ) : ?>
        <div class="hl-dv2-section">
            <div class="hl-dv2-section-label"><?php esc_html_e( 'Your Learning', 'hl-core' ); ?></div>
            <div class="hl-dv2-grid">
                <?php
                $this->render_nav_card_v2(
                    'hl_my_programs',
                    __( 'My Programs', 'hl-core' ),
                    __( 'View your assigned programs and track progress', 'hl-core' ),
                    '&#x1F4DA;',
                    'hl-dv2-icon-programs',
                    $available_count
                );

                $this->render_nav_card_v2(
                    'hl_classrooms_listing',
                    __( 'My Classrooms', 'hl-core' ),
                    __( 'View your classrooms and children', 'hl-core' ),
                    '&#x1F3EB;',
                    'hl-dv2-icon-classrooms'
                );
                ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( ! $context['all_control'] ) : ?>

            <?php if ( $context['is_mentor'] ) : ?>
                <div class="hl-dv2-section">
                    <div class="hl-dv2-section-label"><?php esc_html_e( 'Coaching & Team', 'hl-core' ); ?></div>
                    <div class="hl-dv2-grid">
                        <?php
                        $this->render_nav_card_v2(
                            'hl_my_coaching',
                            __( 'My Coaching', 'hl-core' ),
                            __( 'View coaching sessions and schedule', 'hl-core' ),
                            '&#x1F3AC;',
                            'hl-dv2-icon-coaching'
                        );
                        $this->render_nav_card_v2(
                            'hl_my_team',
                            __( 'My Team', 'hl-core' ),
                            __( 'View team members and their progress', 'hl-core' ),
                            '&#x1F465;',
                            'hl-dv2-icon-team'
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $context['is_teacher'] && ! $context['is_mentor'] ) : ?>
                <div class="hl-dv2-section">
                    <div class="hl-dv2-section-label"><?php esc_html_e( 'Team', 'hl-core' ); ?></div>
                    <div class="hl-dv2-grid">
                        <?php
                        $this->render_nav_card_v2(
                            'hl_my_team',
                            __( 'My Team', 'hl-core' ),
                            __( 'View team members and their progress', 'hl-core' ),
                            '&#x1F465;',
                            'hl-dv2-icon-team'
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <?php if ( $context['is_leader'] ) : ?>
            <div class="hl-dv2-section">
                <div class="hl-dv2-section-label"><?php esc_html_e( 'Leadership', 'hl-core' ); ?></div>
                <div class="hl-dv2-grid">
                    <?php
                    $this->render_nav_card_v2(
                        'hl_my_cycle',
                        __( 'My School', 'hl-core' ),
                        __( 'View your school staff, classrooms, and reports', 'hl-core' ),
                        '&#x1F3EB;',
                        'hl-dv2-icon-cycle'
                    );
                    ?>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * V2 Coach section — coach-specific navigation cards.
     */
    private function render_coach_section_v2() {
        ?>
        <div class="hl-dv2-divider"></div>
        <div class="hl-dv2-section">
            <div class="hl-dv2-section-label"><?php esc_html_e( 'Coaching', 'hl-core' ); ?></div>
            <div class="hl-dv2-grid">
                <?php
                $this->render_nav_card_v2(
                    'hl_coach_dashboard',
                    __( 'Coaching Home', 'hl-core' ),
                    __( 'Sessions overview and quick links', 'hl-core' ),
                    '&#x1F4CA;',
                    'hl-dv2-icon-reports'
                );
                $this->render_nav_card_v2(
                    'hl_coach_mentors',
                    __( 'My Mentors', 'hl-core' ),
                    __( 'View and manage your assigned mentors', 'hl-core' ),
                    '&#x1F465;',
                    'hl-dv2-icon-learners'
                );
                $this->render_nav_card_v2(
                    'hl_coach_availability',
                    __( 'My Availability', 'hl-core' ),
                    __( 'Set your weekly coaching schedule', 'hl-core' ),
                    '&#x1F4C5;',
                    'hl-dv2-icon-cycles'
                );
                $this->render_nav_card_v2(
                    'hl_coach_reports',
                    __( 'Coach Reports', 'hl-core' ),
                    __( 'Completion data and exports', 'hl-core' ),
                    '&#x1F4CB;',
                    'hl-dv2-icon-hub'
                );
                $this->render_nav_card_v2(
                    'hl_user_profile',
                    __( 'My Profile', 'hl-core' ),
                    __( 'View your profile and account info', 'hl-core' ),
                    '&#x1F464;',
                    'hl-dv2-icon-learners'
                );
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * V2 Staff section — admin cards for coaches and admins.
     */
    private function render_staff_section_v2( $context ) {
        ?>
        <div class="hl-dv2-divider"></div>
        <div class="hl-dv2-section">
            <div class="hl-dv2-section-label"><?php esc_html_e( 'Administration', 'hl-core' ); ?></div>
            <div class="hl-dv2-grid">
                <?php
                $this->render_nav_card_v2(
                    'hl_cycles_listing',
                    __( 'Cycles', 'hl-core' ),
                    __( 'Browse and manage all cycles', 'hl-core' ),
                    '&#x1F504;',
                    'hl-dv2-icon-cycles'
                );
                $this->render_nav_card_v2(
                    'hl_institutions_listing',
                    __( 'Institutions', 'hl-core' ),
                    __( 'View districts and schools', 'hl-core' ),
                    '&#x1F3DB;',
                    'hl-dv2-icon-institutions'
                );
                $this->render_nav_card_v2(
                    'hl_learners',
                    __( 'Learners', 'hl-core' ),
                    __( 'Search and view all participants', 'hl-core' ),
                    '&#x1F393;',
                    'hl-dv2-icon-learners'
                );
                $this->render_nav_card_v2(
                    'hl_pathways_listing',
                    __( 'Pathways', 'hl-core' ),
                    __( 'Browse pathway configurations', 'hl-core' ),
                    '&#x1F5FA;',
                    'hl-dv2-icon-pathways'
                );
                $this->render_nav_card_v2(
                    'hl_coaching_hub',
                    __( 'Coaching Hub', 'hl-core' ),
                    __( 'View and manage coaching sessions', 'hl-core' ),
                    '&#x1F4CB;',
                    'hl-dv2-icon-hub'
                );
                $this->render_nav_card_v2(
                    'hl_reports_hub',
                    __( 'Reports', 'hl-core' ),
                    __( 'Access reports and data exports', 'hl-core' ),
                    '&#x1F4CA;',
                    'hl-dv2-icon-reports'
                );
                $this->render_nav_card_v2(
                    'hl_classrooms_listing',
                    __( 'Classrooms', 'hl-core' ),
                    __( 'Browse and manage classrooms', 'hl-core' ),
                    '&#x1F3EB;',
                    'hl-dv2-icon-institutions'
                );
                $this->render_nav_card_v2(
                    'hl_user_profile',
                    __( 'My Profile', 'hl-core' ),
                    __( 'View your profile and account info', 'hl-core' ),
                    '&#x1F464;',
                    'hl-dv2-icon-learners'
                );
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * V2 navigation card with emoji icon and hover arrow.
     *
     * @param string $shortcode Target page shortcode tag.
     * @param string $title     Card title.
     * @param string $desc      Card description.
     * @param string $icon      Emoji HTML entity for the icon.
     * @param string $icon_class CSS class for icon background color.
     * @param int    $badge     Optional badge count.
     */
    private function render_nav_card_v2( $shortcode, $title, $desc, $icon, $icon_class, $badge = 0 ) {
        $url = $this->find_shortcode_page_url( $shortcode );
        if ( empty( $url ) ) {
            return;
        }
        ?>
        <a href="<?php echo esc_url( $url ); ?>" class="hl-dv2-card">
            <div class="hl-dv2-card-icon <?php echo esc_attr( $icon_class ); ?>"><?php echo $icon; ?></div>
            <div>
                <h4>
                    <?php echo esc_html( $title ); ?>
                    <?php if ( $badge > 0 ) : ?>
                        <span class="hl-dv2-badge"><?php echo (int) $badge; ?></span>
                    <?php endif; ?>
                </h4>
                <p><?php echo esc_html( $desc ); ?></p>
            </div>
            <div class="hl-dv2-arrow">&#x2192;</div>
        </a>
        <?php
    }

    /**
     * V2 empty state for users with no enrollment and no staff role.
     */
    private function render_empty_state_v2() {
        ?>
        <div class="hl-dv2-empty">
            <h3><?php esc_html_e( 'Welcome!', 'hl-core' ); ?></h3>
            <p><?php esc_html_e( 'You are not currently enrolled in any programs. If you believe this is an error, please contact your administrator.', 'hl-core' ); ?></p>
        </div>
        <?php
    }
}
