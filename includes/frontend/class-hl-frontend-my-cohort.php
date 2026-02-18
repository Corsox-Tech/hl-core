<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_my_cohort] shortcode.
 *
 * Auto-scoped cohort workspace for Center Leaders and District Leaders.
 * Tabs: Teams, Staff, Reports, Classrooms.
 *
 * @package HL_Core
 */
class HL_Frontend_My_Cohort {

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    /** @var HL_Cohort_Repository */
    private $cohort_repo;

    /** @var HL_Team_Repository */
    private $team_repo;

    /** @var HL_Classroom_Service */
    private $classroom_service;

    /** @var HL_Reporting_Service */
    private $reporting_service;

    /** @var HL_OrgUnit_Repository */
    private $orgunit_repo;

    public function __construct() {
        $this->enrollment_repo   = new HL_Enrollment_Repository();
        $this->cohort_repo       = new HL_Cohort_Repository();
        $this->team_repo         = new HL_Team_Repository();
        $this->classroom_service = new HL_Classroom_Service();
        $this->reporting_service = HL_Reporting_Service::instance();
        $this->orgunit_repo      = new HL_OrgUnit_Repository();
    }

    // ========================================================================
    // CSV Export (called from template_redirect, before headers)
    // ========================================================================

    /**
     * Handle CSV export requests.
     */
    public static function handle_export() {
        if ( empty( $_GET['hl_export_action'] ) || $_GET['hl_export_action'] !== 'my_cohort_csv' ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_die( __( 'You must be logged in.', 'hl-core' ) );
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'hl_cohort_export' ) ) {
            wp_die( __( 'Invalid security token.', 'hl-core' ) );
        }

        $cohort_id = absint( $_GET['cohort_id'] ?? 0 );
        if ( ! $cohort_id ) {
            wp_die( __( 'Missing cohort ID.', 'hl-core' ) );
        }

        $user_id         = get_current_user_id();
        $enrollment_repo = new HL_Enrollment_Repository();
        $all             = $enrollment_repo->get_all( array( 'cohort_id' => $cohort_id, 'status' => 'active' ) );

        $user_enrollment = null;
        foreach ( $all as $e ) {
            if ( (int) $e->user_id === $user_id ) {
                $user_enrollment = $e;
                break;
            }
        }

        $is_staff = HL_Security::can_manage();
        if ( ! $user_enrollment && ! $is_staff ) {
            wp_die( __( 'Access denied.', 'hl-core' ) );
        }

        // Build scope filters.
        $filters = array( 'cohort_id' => $cohort_id );

        if ( $user_enrollment && ! $is_staff ) {
            $roles = $user_enrollment->get_roles_array();
            if ( in_array( 'center_leader', $roles, true ) && $user_enrollment->center_id ) {
                $filters['center_id'] = (int) $user_enrollment->center_id;
            } elseif ( in_array( 'district_leader', $roles, true ) && $user_enrollment->district_id ) {
                $filters['district_id'] = (int) $user_enrollment->district_id;
            }
        }

        $reporting = HL_Reporting_Service::instance();
        $csv       = $reporting->export_completion_csv( $filters, true );

        $cohort_repo = new HL_Cohort_Repository();
        $cohort      = $cohort_repo->get_by_id( $cohort_id );
        $filename    = $cohort
            ? sanitize_file_name( $cohort->cohort_name ) . '-report'
            : 'cohort-report';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    // ========================================================================
    // Render
    // ========================================================================

    /**
     * Render the My Cohort shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render( $atts ) {
        ob_start();
        $user_id = get_current_user_id();

        // Get all active enrollments for this user.
        $all_enrollments  = $this->enrollment_repo->get_all( array( 'status' => 'active' ) );
        $user_enrollments = array_filter( $all_enrollments, function ( $e ) use ( $user_id ) {
            return (int) $e->user_id === $user_id;
        } );
        $user_enrollments = array_values( $user_enrollments );

        // Filter to leader enrollments (or staff).
        $is_staff           = HL_Security::can_manage();
        $leader_enrollments = array();
        foreach ( $user_enrollments as $enrollment ) {
            $roles = $enrollment->get_roles_array();
            if ( in_array( 'center_leader', $roles, true )
                || in_array( 'district_leader', $roles, true )
                || $is_staff
            ) {
                $leader_enrollments[] = $enrollment;
            }
        }

        if ( empty( $leader_enrollments ) ) {
            echo '<div class="hl-notice hl-notice-warning">'
                . esc_html__( 'You do not have access to this page. My Cohort is available for Center Leaders and District Leaders.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        // Determine active cohort.
        $active_cohort_id = isset( $_GET['cohort_id'] ) ? absint( $_GET['cohort_id'] ) : 0;
        $active_enrollment = null;

        if ( $active_cohort_id ) {
            foreach ( $leader_enrollments as $enrollment ) {
                if ( (int) $enrollment->cohort_id === $active_cohort_id ) {
                    $active_enrollment = $enrollment;
                    break;
                }
            }
        }

        if ( ! $active_enrollment ) {
            $active_enrollment = $leader_enrollments[0];
            $active_cohort_id  = (int) $active_enrollment->cohort_id;
        }

        $cohort = $this->cohort_repo->get_by_id( $active_cohort_id );
        if ( ! $cohort ) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'Cohort not found.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        // Resolve scope.
        $scope        = $this->resolve_scope( $active_enrollment, $is_staff );
        $scope_orgunit = ( $scope && ! empty( $scope['orgunit_id'] ) )
            ? $this->orgunit_repo->get_by_id( $scope['orgunit_id'] )
            : null;

        // Active tab.
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'teams';
        $valid_tabs = array( 'teams', 'staff', 'reports', 'classrooms' );
        if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
            $active_tab = 'teams';
        }

        $tabs = array(
            'teams'      => __( 'Teams', 'hl-core' ),
            'staff'      => __( 'Staff', 'hl-core' ),
            'reports'    => __( 'Reports', 'hl-core' ),
            'classrooms' => __( 'Classrooms', 'hl-core' ),
        );

        ?>
        <div class="hl-dashboard hl-my-cohort hl-frontend-wrap">
            <?php $this->render_header( $cohort, $scope, $scope_orgunit, $leader_enrollments, $active_enrollment ); ?>

            <div class="hl-cohort-tabs">
                <?php foreach ( $tabs as $key => $label ) : ?>
                    <button class="hl-tab hl-cohort-tab <?php echo $active_tab === $key ? 'active' : ''; ?>"
                            data-target="hl-tab-<?php echo esc_attr( $key ); ?>">
                        <?php echo esc_html( $label ); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ( $tabs as $key => $label ) : ?>
                <div id="hl-tab-<?php echo esc_attr( $key ); ?>"
                     class="hl-cohort-content <?php echo $active_tab === $key ? 'active' : ''; ?>">
                    <?php
                    switch ( $key ) {
                        case 'teams':
                            $this->render_teams_tab( $cohort, $scope );
                            break;
                        case 'staff':
                            $this->render_staff_tab( $cohort, $scope );
                            break;
                        case 'reports':
                            $this->render_reports_tab( $cohort, $scope );
                            break;
                        case 'classrooms':
                            $this->render_classrooms_tab( $cohort, $scope );
                            break;
                    }
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    // ========================================================================
    // Scope Resolution
    // ========================================================================

    /**
     * Resolve the leader's visibility scope from their enrollment.
     *
     * @param HL_Enrollment $enrollment
     * @param bool          $is_staff
     * @return array ['type' => center|district|all, 'orgunit_id' => int]
     */
    private function resolve_scope( $enrollment, $is_staff = false ) {
        $roles = $enrollment->get_roles_array();

        if ( in_array( 'district_leader', $roles, true ) && $enrollment->district_id ) {
            return array( 'type' => 'district', 'orgunit_id' => (int) $enrollment->district_id );
        }

        if ( in_array( 'center_leader', $roles, true ) && $enrollment->center_id ) {
            return array( 'type' => 'center', 'orgunit_id' => (int) $enrollment->center_id );
        }

        // Staff or leader without org-unit assignment — full cohort.
        return array( 'type' => 'all', 'orgunit_id' => 0 );
    }

    /**
     * Build reporting-service-compatible filters from scope.
     */
    private function get_scope_filters( $cohort_id, $scope ) {
        $filters = array( 'cohort_id' => $cohort_id );
        if ( $scope['type'] === 'center' && $scope['orgunit_id'] ) {
            $filters['center_id'] = $scope['orgunit_id'];
        } elseif ( $scope['type'] === 'district' && $scope['orgunit_id'] ) {
            $filters['district_id'] = $scope['orgunit_id'];
        }
        return $filters;
    }

    /**
     * Get center IDs that fall within the leader's scope.
     *
     * @return int[] Empty array means "all" (no filtering).
     */
    private function get_scoped_center_ids( $scope ) {
        if ( ! $scope || $scope['type'] === 'all' ) {
            return array();
        }
        if ( $scope['type'] === 'center' ) {
            return array( $scope['orgunit_id'] );
        }
        if ( $scope['type'] === 'district' ) {
            $centers = $this->orgunit_repo->get_centers( $scope['orgunit_id'] );
            return array_map( function ( $c ) { return (int) $c->orgunit_id; }, $centers );
        }
        return array();
    }

    // ========================================================================
    // Header
    // ========================================================================

    private function render_header( $cohort, $scope, $scope_orgunit, $leader_enrollments, $active_enrollment ) {
        $status       = $cohort->status ?: 'active';
        $status_class = 'hl-badge-' . sanitize_html_class( $status );

        $scope_label = '';
        if ( $scope_orgunit ) {
            $scope_label = sprintf(
                /* translators: %s: org-unit name */
                __( 'Showing data for %s', 'hl-core' ),
                $scope_orgunit->name
            );
        } elseif ( $scope['type'] === 'all' ) {
            $scope_label = __( 'Showing all cohort data', 'hl-core' );
        }

        ?>
        <div class="hl-my-cohort-header">
            <div class="hl-my-cohort-header-info">
                <h2 class="hl-cohort-title"><?php echo esc_html( $cohort->cohort_name ); ?></h2>
                <?php if ( $scope_label ) : ?>
                    <p class="hl-scope-indicator"><?php echo esc_html( $scope_label ); ?></p>
                <?php endif; ?>
                <div class="hl-cohort-meta">
                    <span class="hl-badge <?php echo esc_attr( $status_class ); ?>">
                        <?php echo esc_html( ucfirst( $status ) ); ?>
                    </span>
                    <?php if ( $cohort->start_date || $cohort->end_date ) : ?>
                        <span class="hl-meta-item">
                            <?php
                            $dates = array();
                            if ( $cohort->start_date ) {
                                $dates[] = date_i18n( 'M j, Y', strtotime( $cohort->start_date ) );
                            }
                            if ( $cohort->end_date ) {
                                $dates[] = date_i18n( 'M j, Y', strtotime( $cohort->end_date ) );
                            }
                            echo esc_html( implode( ' — ', $dates ) );
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( count( $leader_enrollments ) > 1 ) : ?>
                <div class="hl-cohort-selector">
                    <label for="hl-cohort-switcher"><?php esc_html_e( 'Cohort:', 'hl-core' ); ?></label>
                    <select id="hl-cohort-switcher" class="hl-select"
                            onchange="if(this.value){var u=new URL(window.location);u.searchParams.set('cohort_id',this.value);window.location=u;}">
                        <?php foreach ( $leader_enrollments as $le ) :
                            $le_cohort = $this->cohort_repo->get_by_id( $le->cohort_id );
                            if ( ! $le_cohort ) continue;
                        ?>
                            <option value="<?php echo esc_attr( $le->cohort_id ); ?>"
                                <?php selected( (int) $le->cohort_id, (int) $active_enrollment->cohort_id ); ?>>
                                <?php echo esc_html( $le_cohort->cohort_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ========================================================================
    // Tab: Teams
    // ========================================================================

    private function render_teams_tab( $cohort, $scope ) {
        $all_teams  = $this->team_repo->get_all( array( 'cohort_id' => $cohort->cohort_id ) );
        $center_ids = $this->get_scoped_center_ids( $scope );

        if ( ! empty( $center_ids ) ) {
            $all_teams = array_filter( $all_teams, function ( $t ) use ( $center_ids ) {
                return in_array( (int) $t->center_id, $center_ids, true );
            } );
            $all_teams = array_values( $all_teams );
        }

        if ( empty( $all_teams ) ) {
            echo '<div class="hl-empty-state"><p>'
                . esc_html__( 'No teams found in your scope.', 'hl-core' )
                . '</p></div>';
            return;
        }

        $team_page_url = $this->find_shortcode_page_url( 'hl_team_page' );

        echo '<div class="hl-teams-grid">';
        foreach ( $all_teams as $team ) {
            $this->render_team_card( $team, $team_page_url );
        }
        echo '</div>';
    }

    private function render_team_card( $team, $team_page_url ) {
        $members      = $this->team_repo->get_members( $team->team_id );
        $member_count = count( $members );

        // Mentors.
        $mentor_names = array();
        foreach ( $members as $m ) {
            if ( $m['membership_type'] === 'mentor' ) {
                $mentor_names[] = $m['display_name'] ?: $m['user_email'];
            }
        }

        // Avg completion.
        $sum = 0;
        foreach ( $members as $m ) {
            $sum += $this->reporting_service->get_enrollment_completion( $m['enrollment_id'] );
        }
        $avg = $member_count > 0 ? round( $sum / $member_count ) : 0;

        $progress_class = $avg >= 100 ? 'hl-progress-complete' : ( $avg > 0 ? 'hl-progress-active' : '' );

        // Center name.
        $center      = $team->center_id ? $this->orgunit_repo->get_by_id( $team->center_id ) : null;
        $center_name = $center ? $center->name : '';

        $team_url = $team_page_url
            ? add_query_arg( 'id', $team->team_id, $team_page_url )
            : '';

        ?>
        <div class="hl-team-card-item">
            <div class="hl-team-card-body">
                <h4 class="hl-team-card-name">
                    <?php if ( $team_url ) : ?>
                        <a href="<?php echo esc_url( $team_url ); ?>"><?php echo esc_html( $team->team_name ); ?></a>
                    <?php else : ?>
                        <?php echo esc_html( $team->team_name ); ?>
                    <?php endif; ?>
                </h4>
                <?php if ( $center_name ) : ?>
                    <p class="hl-team-card-center"><?php echo esc_html( $center_name ); ?></p>
                <?php endif; ?>
                <div class="hl-team-card-meta">
                    <?php if ( ! empty( $mentor_names ) ) : ?>
                        <span class="hl-team-card-mentors">
                            <strong><?php esc_html_e( 'Mentor(s):', 'hl-core' ); ?></strong>
                            <?php echo esc_html( implode( ', ', $mentor_names ) ); ?>
                        </span>
                    <?php endif; ?>
                    <span class="hl-team-card-count">
                        <strong><?php esc_html_e( 'Members:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( $member_count ); ?>
                    </span>
                </div>
                <div class="hl-team-card-progress">
                    <div class="hl-inline-progress">
                        <div class="hl-progress-inline">
                            <div class="hl-progress-bar-container">
                                <div class="hl-progress-bar <?php echo esc_attr( $progress_class ); ?>"
                                     style="width: <?php echo esc_attr( $avg ); ?>%"></div>
                            </div>
                        </div>
                        <span class="hl-progress-text"><?php echo esc_html( $avg . '%' ); ?></span>
                    </div>
                </div>
            </div>
            <?php if ( $team_url ) : ?>
                <div class="hl-team-card-action">
                    <a href="<?php echo esc_url( $team_url ); ?>" class="hl-btn hl-btn-sm hl-btn-secondary">
                        <?php esc_html_e( 'View Team', 'hl-core' ); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ========================================================================
    // Tab: Staff
    // ========================================================================

    private function render_staff_tab( $cohort, $scope ) {
        $filters      = $this->get_scope_filters( $cohort->cohort_id, $scope );
        $participants = $this->reporting_service->get_participant_report( $filters );

        if ( empty( $participants ) ) {
            echo '<div class="hl-empty-state"><p>'
                . esc_html__( 'No staff found in your scope.', 'hl-core' )
                . '</p></div>';
            return;
        }

        ?>
        <div class="hl-table-container">
            <div class="hl-table-header">
                <h3 class="hl-section-title"><?php esc_html_e( 'Staff Directory', 'hl-core' ); ?></h3>
                <div class="hl-table-filters">
                    <input type="text" class="hl-search-input" data-table="hl-staff-table"
                           placeholder="<?php esc_attr_e( 'Search by name...', 'hl-core' ); ?>">
                </div>
            </div>

            <table class="hl-table" id="hl-staff-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Team', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Completion', 'hl-core' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $participants as $p ) :
                        $roles_raw  = json_decode( $p['roles'], true );
                        $roles_str  = is_array( $roles_raw )
                            ? implode( ', ', array_map( function ( $r ) {
                                return ucwords( str_replace( '_', ' ', $r ) );
                            }, $roles_raw ) )
                            : '';
                        $completion = round( floatval( $p['cohort_completion_percent'] ) );
                        $pclass     = $completion >= 100 ? 'hl-progress-complete' : ( $completion > 0 ? 'hl-progress-active' : '' );
                    ?>
                        <tr data-name="<?php echo esc_attr( strtolower( $p['display_name'] ) ); ?>">
                            <td><strong><?php echo esc_html( $p['display_name'] ); ?></strong></td>
                            <td><?php echo esc_html( $p['user_email'] ); ?></td>
                            <td><?php echo esc_html( $p['team_name'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $roles_str ); ?></td>
                            <td>
                                <div class="hl-inline-progress">
                                    <div class="hl-progress-inline">
                                        <div class="hl-progress-bar-container">
                                            <div class="hl-progress-bar <?php echo esc_attr( $pclass ); ?>"
                                                 style="width: <?php echo esc_attr( $completion ); ?>%"></div>
                                        </div>
                                    </div>
                                    <span class="hl-progress-text"><?php echo esc_html( $completion . '%' ); ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ========================================================================
    // Tab: Reports
    // ========================================================================

    private function render_reports_tab( $cohort, $scope ) {
        $filters      = $this->get_scope_filters( $cohort->cohort_id, $scope );
        $participants = $this->reporting_service->get_participant_report( $filters );

        // Activity detail for expandable rows.
        $enrollment_ids  = wp_list_pluck( $participants, 'enrollment_id' );
        $activity_detail = array();
        $activities      = array();

        if ( ! empty( $enrollment_ids ) ) {
            $activity_detail = $this->reporting_service->get_cohort_activity_detail(
                $cohort->cohort_id,
                $enrollment_ids
            );
            $activities = $this->reporting_service->get_cohort_activities( $cohort->cohort_id );
        }

        // Age bands per enrollment.
        $age_bands_map = $this->get_enrollment_age_bands( $enrollment_ids );

        // Build filter options from participant data.
        $center_options = array();
        $team_options   = array();

        foreach ( $participants as $p ) {
            if ( ! empty( $p['center_name'] ) && ! in_array( $p['center_name'], $center_options, true ) ) {
                $center_options[] = $p['center_name'];
            }
            if ( ! empty( $p['team_name'] ) && ! in_array( $p['team_name'], $team_options, true ) ) {
                $team_options[] = $p['team_name'];
            }
        }
        sort( $center_options );
        sort( $team_options );

        // CSV export URL.
        $export_url = add_query_arg( array(
            'hl_export_action' => 'my_cohort_csv',
            'cohort_id'        => $cohort->cohort_id,
            '_wpnonce'         => wp_create_nonce( 'hl_cohort_export' ),
        ) );

        ?>
        <div class="hl-table-container hl-reports-container">
            <div class="hl-table-header">
                <h3 class="hl-section-title"><?php esc_html_e( 'Completion Report', 'hl-core' ); ?></h3>
                <a href="<?php echo esc_url( $export_url ); ?>" class="hl-btn hl-btn-sm hl-btn-primary hl-export-btn">
                    <?php esc_html_e( 'Download CSV', 'hl-core' ); ?>
                </a>
            </div>

            <div class="hl-report-filters">
                <?php if ( ! empty( $center_options ) ) : ?>
                    <select class="hl-select hl-report-filter" data-filter="center">
                        <option value=""><?php esc_html_e( 'All Institutions', 'hl-core' ); ?></option>
                        <?php foreach ( $center_options as $co ) : ?>
                            <option value="<?php echo esc_attr( $co ); ?>"><?php echo esc_html( $co ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ( ! empty( $team_options ) ) : ?>
                    <select class="hl-select hl-report-filter" data-filter="team">
                        <option value=""><?php esc_html_e( 'All Teams', 'hl-core' ); ?></option>
                        <?php foreach ( $team_options as $to ) : ?>
                            <option value="<?php echo esc_attr( $to ); ?>"><?php echo esc_html( $to ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <input type="text" class="hl-search-input hl-report-search"
                       placeholder="<?php esc_attr_e( 'Search by name...', 'hl-core' ); ?>">
            </div>

            <?php if ( empty( $participants ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No participants found.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <table class="hl-table hl-reports-table" id="hl-reports-table">
                    <thead>
                        <tr>
                            <th style="width:30px">#</th>
                            <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Team', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Role', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Institution', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Age Groups', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Completed', 'hl-core' ); ?></th>
                            <th style="width:80px"><?php esc_html_e( 'Details', 'hl-core' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $row_num = 0;
                        foreach ( $participants as $p ) :
                            $row_num++;
                            $eid       = $p['enrollment_id'];
                            $roles_raw = json_decode( $p['roles'], true );
                            $roles_str = is_array( $roles_raw )
                                ? implode( ', ', array_map( function ( $r ) {
                                    return ucwords( str_replace( '_', ' ', $r ) );
                                }, $roles_raw ) )
                                : '';
                            $completion = round( floatval( $p['cohort_completion_percent'] ) );
                            $pclass     = $completion >= 100 ? 'hl-progress-complete' : ( $completion > 0 ? 'hl-progress-active' : '' );
                            $age_bands  = isset( $age_bands_map[ $eid ] ) ? $age_bands_map[ $eid ] : '—';
                        ?>
                            <tr class="hl-report-row"
                                data-name="<?php echo esc_attr( strtolower( $p['display_name'] ) ); ?>"
                                data-center="<?php echo esc_attr( $p['center_name'] ); ?>"
                                data-team="<?php echo esc_attr( $p['team_name'] ); ?>">
                                <td><?php echo esc_html( $row_num ); ?></td>
                                <td><strong><?php echo esc_html( $p['display_name'] ); ?></strong></td>
                                <td><?php echo esc_html( $p['team_name'] ?: '—' ); ?></td>
                                <td><?php echo esc_html( $roles_str ); ?></td>
                                <td><?php echo esc_html( $p['center_name'] ?: '—' ); ?></td>
                                <td><?php echo esc_html( $age_bands ); ?></td>
                                <td>
                                    <div class="hl-inline-progress">
                                        <div class="hl-progress-inline">
                                            <div class="hl-progress-bar-container">
                                                <div class="hl-progress-bar <?php echo esc_attr( $pclass ); ?>"
                                                     style="width: <?php echo esc_attr( $completion ); ?>%"></div>
                                            </div>
                                        </div>
                                        <span class="hl-progress-text"><?php echo esc_html( $completion . '%' ); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <button class="hl-btn hl-btn-sm hl-btn-secondary hl-detail-toggle"
                                            data-target="hl-detail-<?php echo esc_attr( $eid ); ?>">
                                        <?php esc_html_e( 'View', 'hl-core' ); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr class="hl-detail-row" id="hl-detail-<?php echo esc_attr( $eid ); ?>" style="display:none">
                                <td colspan="8">
                                    <div class="hl-detail-content">
                                        <?php if ( isset( $activity_detail[ $eid ] ) && ! empty( $activities ) ) : ?>
                                            <table class="hl-table hl-detail-table">
                                                <thead>
                                                    <tr>
                                                        <th><?php esc_html_e( 'Activity', 'hl-core' ); ?></th>
                                                        <th><?php esc_html_e( 'Type', 'hl-core' ); ?></th>
                                                        <th><?php esc_html_e( 'Progress', 'hl-core' ); ?></th>
                                                        <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ( $activities as $act ) :
                                                        $aid        = $act['activity_id'];
                                                        $ad         = isset( $activity_detail[ $eid ][ $aid ] ) ? $activity_detail[ $eid ][ $aid ] : null;
                                                        $act_pct    = $ad ? intval( $ad['completion_percent'] ) : 0;
                                                        $act_status = $ad ? $ad['completion_status'] : 'not_started';
                                                        $status_lbl = ucwords( str_replace( '_', ' ', $act_status ) );
                                                        $status_cls = 'hl-badge-' . str_replace( '_', '-', $act_status );
                                                        $type_lbl   = ucwords( str_replace( '_', ' ', $act['activity_type'] ) );
                                                    ?>
                                                        <tr>
                                                            <td><?php echo esc_html( $act['title'] ); ?></td>
                                                            <td><span class="hl-activity-type"><?php echo esc_html( $type_lbl ); ?></span></td>
                                                            <td><?php echo esc_html( $act_pct . '%' ); ?></td>
                                                            <td><span class="hl-badge <?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( $status_lbl ); ?></span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php else : ?>
                                            <p><?php esc_html_e( 'No activity data available.', 'hl-core' ); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ========================================================================
    // Tab: Classrooms
    // ========================================================================

    private function render_classrooms_tab( $cohort, $scope ) {
        $center_ids = $this->get_scoped_center_ids( $scope );

        // Get classrooms in scope.
        $classrooms = array();
        if ( empty( $center_ids ) ) {
            $classrooms = $this->get_cohort_classrooms( $cohort->cohort_id );
        } else {
            foreach ( $center_ids as $cid ) {
                $center_classrooms = $this->classroom_service->get_classrooms( $cid );
                $classrooms        = array_merge( $classrooms, $center_classrooms );
            }
        }

        if ( empty( $classrooms ) ) {
            echo '<div class="hl-empty-state"><p>'
                . esc_html__( 'No classrooms found in your scope.', 'hl-core' )
                . '</p></div>';
            return;
        }

        // Batch data.
        $classroom_ids = array_map( function ( $c ) {
            return is_object( $c ) ? $c->classroom_id : $c['classroom_id'];
        }, $classrooms );

        $child_counts  = $this->get_classroom_child_counts( $classroom_ids );
        $teacher_names = $this->get_classroom_teacher_names( $classroom_ids, $cohort->cohort_id );

        $center_cache       = array();
        $classroom_page_url = $this->find_shortcode_page_url( 'hl_classroom_page' );

        ?>
        <div class="hl-table-container">
            <h3 class="hl-section-title"><?php esc_html_e( 'Classrooms', 'hl-core' ); ?></h3>

            <table class="hl-table" id="hl-classrooms-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Classroom', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Center', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Age Band', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Children', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Teacher(s)', 'hl-core' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $classrooms as $classroom ) :
                        $cr  = is_object( $classroom ) ? $classroom : (object) $classroom;
                        $cid = $cr->classroom_id;

                        if ( ! isset( $center_cache[ $cr->center_id ] ) ) {
                            $center_obj                        = $this->orgunit_repo->get_by_id( $cr->center_id );
                            $center_cache[ $cr->center_id ]    = $center_obj ? $center_obj->name : '';
                        }

                        $count    = isset( $child_counts[ $cid ] ) ? $child_counts[ $cid ] : 0;
                        $teachers = isset( $teacher_names[ $cid ] ) ? $teacher_names[ $cid ] : '—';
                        $cr_url   = $classroom_page_url
                            ? add_query_arg( 'id', $cid, $classroom_page_url )
                            : '';
                    ?>
                        <tr>
                            <td>
                                <?php if ( $cr_url ) : ?>
                                    <a href="<?php echo esc_url( $cr_url ); ?>">
                                        <strong><?php echo esc_html( $cr->classroom_name ); ?></strong>
                                    </a>
                                <?php else : ?>
                                    <strong><?php echo esc_html( $cr->classroom_name ); ?></strong>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $center_cache[ $cr->center_id ] ); ?></td>
                            <td><?php echo esc_html( $cr->age_band ?: '—' ); ?></td>
                            <td><?php echo esc_html( $count ); ?></td>
                            <td><?php echo esc_html( $teachers ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ========================================================================
    // Data Helpers
    // ========================================================================

    /**
     * Get age-band labels per enrollment (from teaching assignments).
     */
    private function get_enrollment_age_bands( $enrollment_ids ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        if ( empty( $enrollment_ids ) ) {
            return array();
        }

        $enrollment_ids = array_map( 'absint', $enrollment_ids );
        $placeholders   = implode( ',', array_fill( 0, count( $enrollment_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT ta.enrollment_id,
                    GROUP_CONCAT(DISTINCT cr.age_band ORDER BY cr.age_band SEPARATOR ', ') AS age_bands
             FROM {$prefix}hl_teaching_assignment ta
             JOIN {$prefix}hl_classroom cr ON ta.classroom_id = cr.classroom_id
             WHERE ta.enrollment_id IN ({$placeholders})
             GROUP BY ta.enrollment_id",
            $enrollment_ids
        ), ARRAY_A );

        $map = array();
        foreach ( $results as $row ) {
            $map[ $row['enrollment_id'] ] = $row['age_bands'];
        }
        return $map;
    }

    /**
     * Batch-get child counts per classroom.
     */
    private function get_classroom_child_counts( $classroom_ids ) {
        global $wpdb;

        if ( empty( $classroom_ids ) ) {
            return array();
        }

        $classroom_ids = array_map( 'absint', $classroom_ids );
        $placeholders  = implode( ',', array_fill( 0, count( $classroom_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT classroom_id, COUNT(*) AS child_count
             FROM {$wpdb->prefix}hl_child_classroom_current
             WHERE classroom_id IN ({$placeholders})
             GROUP BY classroom_id",
            $classroom_ids
        ), ARRAY_A );

        $map = array();
        foreach ( $results as $row ) {
            $map[ $row['classroom_id'] ] = (int) $row['child_count'];
        }
        return $map;
    }

    /**
     * Batch-get teacher names per classroom (scoped to a cohort).
     */
    private function get_classroom_teacher_names( $classroom_ids, $cohort_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        if ( empty( $classroom_ids ) ) {
            return array();
        }

        $classroom_ids = array_map( 'absint', $classroom_ids );
        $placeholders  = implode( ',', array_fill( 0, count( $classroom_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT ta.classroom_id,
                    GROUP_CONCAT(DISTINCT u.display_name ORDER BY u.display_name SEPARATOR ', ') AS teacher_names
             FROM {$prefix}hl_teaching_assignment ta
             JOIN {$prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE ta.classroom_id IN ({$placeholders})
               AND e.cohort_id = %d
             GROUP BY ta.classroom_id",
            array_merge( $classroom_ids, array( $cohort_id ) )
        ), ARRAY_A );

        $map = array();
        foreach ( $results as $row ) {
            $map[ $row['classroom_id'] ] = $row['teacher_names'];
        }
        return $map;
    }

    /**
     * Get classrooms that have teaching assignments for a cohort.
     */
    private function get_cohort_classrooms( $cohort_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT cr.*
             FROM {$prefix}hl_classroom cr
             INNER JOIN {$prefix}hl_teaching_assignment ta ON cr.classroom_id = ta.classroom_id
             INNER JOIN {$prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             WHERE e.cohort_id = %d
             ORDER BY cr.classroom_name ASC",
            $cohort_id
        ) ) ?: array();
    }

    /**
     * Find the URL of a page containing a given shortcode.
     */
    private function find_shortcode_page_url( $shortcode ) {
        global $wpdb;
        $page_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
             AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like( $shortcode ) . '%'
        ) );
        return $page_id ? get_permalink( $page_id ) : '';
    }
}
