<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_my_cycle] shortcode.
 *
 * Auto-scoped cycle workspace for School Leaders and District Leaders.
 * Tabs: Teams, Staff, Reports, Classrooms.
 *
 * @package HL_Core
 */
class HL_Frontend_My_Cycle {

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    /** @var HL_Cycle_Repository */
    private $cycle_repo;

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
        $this->cycle_repo  = new HL_Cycle_Repository();
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
        if ( empty( $_GET['hl_export_action'] ) || $_GET['hl_export_action'] !== 'my_cycle_csv' ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_die( __( 'You must be logged in.', 'hl-core' ) );
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'hl_cycle_export' ) ) {
            wp_die( __( 'Invalid security token.', 'hl-core' ) );
        }

        $cycle_id = absint( $_GET[  'cycle_id'] ?? 0 );
        if ( ! $cycle_id ) {
            wp_die( __( 'Missing cycle ID.', 'hl-core' ) );
        }

        $user_id         = get_current_user_id();
        $enrollment_repo = new HL_Enrollment_Repository();
        $all             = $enrollment_repo->get_all( array(   'cycle_id' => $cycle_id, 'status' => 'active' ) );

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
        $filters = array(   'cycle_id' => $cycle_id );

        if ( $user_enrollment && ! $is_staff ) {
            $roles = $user_enrollment->get_roles_array();
            if ( in_array( 'school_leader', $roles, true ) && $user_enrollment->school_id ) {
                $filters['school_id'] = (int) $user_enrollment->school_id;
            } elseif ( in_array( 'district_leader', $roles, true ) && $user_enrollment->district_id ) {
                $filters['district_id'] = (int) $user_enrollment->district_id;
            }
        }

        $reporting = HL_Reporting_Service::instance();
        $csv       = $reporting->export_completion_csv( $filters, true );

        $cycle_repo = new HL_Cycle_Repository();
        $cycle      = $cycle_repo->get_by_id( $cycle_id );
        $filename    = $cycle
            ? sanitize_file_name( $cycle->cycle_name ) . '-report'
            : 'cycle-report';

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
     * Render the My Cycle shortcode.
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
            if ( in_array( 'school_leader', $roles, true )
                || in_array( 'district_leader', $roles, true )
                || $is_staff
            ) {
                $leader_enrollments[] = $enrollment;
            }
        }

        if ( empty( $leader_enrollments ) ) {
            echo '<div class="hl-notice hl-notice-warning">'
                . esc_html__( 'You do not have access to this page. My Cycle is available for School Leaders and District Leaders.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        // Determine active cycle.
        $active_cycle_id = isset( $_GET[  'cycle_id'] ) ? absint( $_GET[  'cycle_id'] ) : 0;
        $active_enrollment = null;

        if ( $active_cycle_id ) {
            foreach ( $leader_enrollments as $enrollment ) {
                if ( (int) $enrollment->cycle_id === $active_cycle_id ) {
                    $active_enrollment = $enrollment;
                    break;
                }
            }
        }

        if ( ! $active_enrollment ) {
            $active_enrollment = $leader_enrollments[0];
            $active_cycle_id  = (int) $active_enrollment->cycle_id;
        }

        $cycle = $this->cycle_repo->get_by_id( $active_cycle_id );
        if ( ! $cycle ) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'Cycle not found.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        // Resolve scope.
        $scope        = $this->resolve_scope( $active_enrollment, $is_staff );
        $scope_orgunit = ( $scope && ! empty( $scope['orgunit_id'] ) )
            ? $this->orgunit_repo->get_by_id( $scope['orgunit_id'] )
            : null;

        // Build tabs based on cycle type.
        $is_control = ! empty( $cycle->is_control_group );

        // Assessments tab: only for staff until teacher consent is obtained.
        $show_assessments_tab = HL_Security::can_manage();

        if ( $is_control ) {
            // Control groups: Reports + Classrooms (no Teams, no Staff).
            $tabs = array(
                'reports'    => __( 'Reports', 'hl-core' ),
                'classrooms' => __( 'Classrooms', 'hl-core' ),
            );
            if ( $show_assessments_tab ) {
                $tabs = array_slice( $tabs, 0, 1, true )
                    + array( 'assessments' => __( 'Assessments', 'hl-core' ) )
                    + array_slice( $tabs, 1, null, true );
            }
            $default_tab = 'reports';
        } else {
            $tabs = array(
                'teams'      => __( 'Teams', 'hl-core' ),
                'staff'      => __( 'Staff', 'hl-core' ),
                'reports'    => __( 'Reports', 'hl-core' ),
                'classrooms' => __( 'Classrooms', 'hl-core' ),
            );
            $default_tab = 'teams';
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : $default_tab;
        $valid_tabs = array_keys( $tabs );
        if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
            $active_tab = $default_tab;
        }

        ?>
        <div class="hl-dashboard hl-my-cycle hl-frontend-wrap">
            <?php $this->render_header( $cycle, $scope, $scope_orgunit, $leader_enrollments, $active_enrollment ); ?>

            <?php
            // Build tab URLs as plain links — no JS dependency.
            $base_url = strtok( $_SERVER['REQUEST_URI'], '?' );
            $tab_params = array();
            if ( isset( $_GET['cycle_id'] ) ) {
                $tab_params['cycle_id'] = absint( $_GET['cycle_id'] );
            }
            ?>
            <div class="hl-cycle-tabs">
                <?php foreach ( $tabs as $key => $label ) :
                    $tab_url = add_query_arg( array_merge( $tab_params, array( 'tab' => $key ) ), $base_url );
                ?>
                    <a class="hl-tab hl-cycle-tab <?php echo $active_tab === $key ? 'active' : ''; ?>"
                       href="<?php echo esc_url( $tab_url ); ?>"
                       onclick="window.location.href=this.href; return false;">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php
            // Only render the active tab — avoids fatal errors in other tabs
            // crashing the whole page.
            ?>
            <div class="hl-cycle-content active">
                <?php
                switch ( $active_tab ) {
                    case 'teams':
                        $this->render_teams_tab( $cycle, $scope );
                        break;
                    case 'staff':
                        $this->render_staff_tab( $cycle, $scope );
                        break;
                    case 'reports':
                        $this->render_reports_tab( $cycle, $scope );
                        break;
                    case 'assessments':
                        $this->render_assessments_tab( $cycle, $scope );
                        break;
                    case 'classrooms':
                        $this->render_classrooms_tab( $cycle, $scope );
                        break;
                }
                ?>
            </div>
        </div>
        <?php

        // Tabs use plain URL navigation (?tab=staff, ?tab=teams, etc.) — no JS needed.
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
     * @return array ['type' => school|district|all, 'orgunit_id' => int]
     */
    private function resolve_scope( $enrollment, $is_staff = false ) {
        $roles = $enrollment->get_roles_array();

        if ( in_array( 'district_leader', $roles, true ) && $enrollment->district_id ) {
            return array( 'type' => 'district', 'orgunit_id' => (int) $enrollment->district_id );
        }

        if ( in_array( 'school_leader', $roles, true ) && $enrollment->school_id ) {
            return array( 'type' => 'school', 'orgunit_id' => (int) $enrollment->school_id );
        }

        // Staff or leader without org-unit assignment — full cycle.
        return array( 'type' => 'all', 'orgunit_id' => 0 );
    }

    /**
     * Build reporting-service-compatible filters from scope.
     */
    private function get_scope_filters( $cycle_id, $scope ) {
        $filters = array(   'cycle_id' => $cycle_id );
        if ( $scope['type'] === 'school' && $scope['orgunit_id'] ) {
            $filters['school_id'] = $scope['orgunit_id'];
        } elseif ( $scope['type'] === 'district' && $scope['orgunit_id'] ) {
            $filters['district_id'] = $scope['orgunit_id'];
        }
        return $filters;
    }

    /**
     * Get school IDs that fall within the leader's scope.
     *
     * @return int[] Empty array means "all" (no filtering).
     */
    private function get_scoped_school_ids( $scope ) {
        if ( ! $scope || $scope['type'] === 'all' ) {
            return array();
        }
        if ( $scope['type'] === 'school' ) {
            return array( $scope['orgunit_id'] );
        }
        if ( $scope['type'] === 'district' ) {
            $schools = $this->orgunit_repo->get_schools( $scope['orgunit_id'] );
            return array_map( function ( $c ) { return (int) $c->orgunit_id; }, $schools );
        }
        return array();
    }

    // ========================================================================
    // Header
    // ========================================================================

    private function render_header( $cycle, $scope, $scope_orgunit, $leader_enrollments, $active_enrollment ) {
        $status       = $cycle->status ?: 'active';
        $status_class = 'hl-badge-' . sanitize_html_class( $status );

        $scope_label = '';
        if ( $scope_orgunit ) {
            $scope_label = sprintf(
                /* translators: %s: org-unit name */
                __( 'Showing data for %s', 'hl-core' ),
                $scope_orgunit->name
            );
        } elseif ( $scope['type'] === 'all' ) {
            $scope_label = __( 'Showing all cycle data', 'hl-core' );
        }

        ?>
        <div class="hl-my-cycle-header">
            <div class="hl-my-cycle-header-info">
                <h2 class="hl-cycle-title"><?php echo esc_html( $cycle->cycle_name ); ?></h2>
                <?php if ( $scope_label ) : ?>
                    <p class="hl-scope-indicator"><?php echo esc_html( $scope_label ); ?></p>
                <?php endif; ?>
                <div class="hl-cycle-meta">
                    <span class="hl-badge <?php echo esc_attr( $status_class ); ?>">
                        <?php echo esc_html( ucfirst( $status ) ); ?>
                    </span>
                    <?php if ( $cycle->start_date || $cycle->end_date ) : ?>
                        <span class="hl-meta-item">
                            <?php
                            $dates = array();
                            if ( $cycle->start_date ) {
                                $dates[] = date_i18n( 'M j, Y', strtotime( $cycle->start_date ) );
                            }
                            if ( $cycle->end_date ) {
                                $dates[] = date_i18n( 'M j, Y', strtotime( $cycle->end_date ) );
                            }
                            echo esc_html( implode( ' — ', $dates ) );
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( count( $leader_enrollments ) > 1 ) : ?>
                <div class="hl-cycle-selector">
                    <label for="hl-cycle-switcher"><?php esc_html_e( 'Cycle:', 'hl-core' ); ?></label>
                    <select id="hl-cycle-switcher" class="hl-select"
                            onchange="if(this.value){var u=new URL(window.location);u.searchParams.set(  'cycle_id',this.value);window.location=u;}">
                        <?php foreach ( $leader_enrollments as $le ) :
                            $le_cycle = $this->cycle_repo->get_by_id( $le->cycle_id );
                            if ( ! $le_cycle ) continue;
                        ?>
                            <option value="<?php echo esc_attr( $le->cycle_id ); ?>"
                                <?php selected( (int) $le->cycle_id, (int) $active_enrollment->cycle_id ); ?>>
                                <?php echo esc_html( $le_cycle->cycle_name ); ?>
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

    private function render_teams_tab( $cycle, $scope ) {
        $all_teams  = $this->team_repo->get_all( array(   'cycle_id' => $cycle->cycle_id ) );
        $school_ids = $this->get_scoped_school_ids( $scope );

        if ( ! empty( $school_ids ) ) {
            $all_teams = array_filter( $all_teams, function ( $t ) use ( $school_ids ) {
                // Include teams matching a scoped school OR teams with no school assigned.
                return empty( $t->school_id )
                    || in_array( (int) $t->school_id, $school_ids, true );
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

        // School name.
        $school      = $team->school_id ? $this->orgunit_repo->get_by_id( $team->school_id ) : null;
        $school_name = $school ? $school->name : '';

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
                <?php if ( $school_name ) : ?>
                    <p class="hl-team-card-school"><?php echo esc_html( $school_name ); ?></p>
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

    private function render_staff_tab( $cycle, $scope ) {
        $filters      = $this->get_scope_filters( $cycle->cycle_id, $scope );
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
                        $completion = round( floatval( $p['cycle_completion_percent'] ) );
                        $pclass     = $completion >= 100 ? 'hl-progress-complete' : ( $completion > 0 ? 'hl-progress-active' : '' );
                    ?>
                        <tr data-name="<?php echo esc_attr( strtolower( $p['display_name'] ) ); ?>">
                            <td><strong><?php
                                $profile_url = $this->get_profile_url( $p['user_id'] );
                                if ( $profile_url ) {
                                    echo '<a href="' . esc_url( $profile_url ) . '" class="hl-profile-link">' . esc_html( $p['display_name'] ) . '</a>';
                                } else {
                                    echo esc_html( $p['display_name'] );
                                }
                            ?></strong></td>
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

    private function render_reports_tab( $cycle, $scope ) {
        $filters      = $this->get_scope_filters( $cycle->cycle_id, $scope );
        $participants = $this->reporting_service->get_participant_report( $filters );

        // Exclude the current user (the leader viewing this page) from the report.
        // Leaders without a pathway assigned are not participants — they're viewers.
        $current_user_id = get_current_user_id();
        $participants = array_filter( $participants, function ( $p ) use ( $current_user_id ) {
            return (int) $p['user_id'] !== $current_user_id;
        } );
        $participants = array_values( $participants );

        // Activity detail for expandable rows.
        $enrollment_ids  = wp_list_pluck( $participants, 'enrollment_id' );
        $activity_detail = array();
        $activities      = array();

        if ( ! empty( $enrollment_ids ) ) {
            $activity_detail = $this->reporting_service->get_cycle_component_detail(
                $cycle->cycle_id,
                $enrollment_ids
            );
            $activities = $this->reporting_service->get_cycle_components( $cycle->cycle_id );
        }

        // Age bands per enrollment.
        $age_bands_map = $this->get_enrollment_age_bands( $enrollment_ids );

        // Build filter options from participant data.
        $school_options = array();
        $team_options   = array();

        foreach ( $participants as $p ) {
            if ( ! empty( $p['school_name'] ) && ! in_array( $p['school_name'], $school_options, true ) ) {
                $school_options[] = $p['school_name'];
            }
            if ( ! empty( $p['team_name'] ) && ! in_array( $p['team_name'], $team_options, true ) ) {
                $team_options[] = $p['team_name'];
            }
        }
        sort( $school_options );
        sort( $team_options );

        // CSV export URL.
        $export_url = add_query_arg( array(
            'hl_export_action' => 'my_cycle_csv',
              'cycle_id'  => $cycle->cycle_id,
            '_wpnonce'         => wp_create_nonce( 'hl_cycle_export' ),
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
                <?php if ( ! empty( $school_options ) ) : ?>
                    <select class="hl-select hl-report-filter" data-filter="school">
                        <option value=""><?php esc_html_e( 'All Institutions', 'hl-core' ); ?></option>
                        <?php foreach ( $school_options as $co ) : ?>
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
                            <th>#</th>
                            <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Team', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Role', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Institution', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Age Groups', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Completed', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Details', 'hl-core' ); ?></th>
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
                            $completion = round( floatval( $p['cycle_completion_percent'] ) );
                            $pclass     = $completion >= 100 ? 'hl-progress-complete' : ( $completion > 0 ? 'hl-progress-active' : '' );
                            $age_bands  = isset( $age_bands_map[ $eid ] ) ? $age_bands_map[ $eid ] : '—';
                        ?>
                            <tr class="hl-report-row"
                                data-name="<?php echo esc_attr( strtolower( $p['display_name'] ) ); ?>"
                                data-school="<?php echo esc_attr( $p['school_name'] ); ?>"
                                data-team="<?php echo esc_attr( $p['team_name'] ); ?>">
                                <td><?php echo esc_html( $row_num ); ?></td>
                                <td><strong><?php
                                    $profile_url = $this->get_profile_url( $p['user_id'] );
                                    if ( $profile_url ) {
                                        echo '<a href="' . esc_url( $profile_url ) . '" class="hl-profile-link">' . esc_html( $p['display_name'] ) . '</a>';
                                    } else {
                                        echo esc_html( $p['display_name'] );
                                    }
                                ?></strong></td>
                                <td><?php echo esc_html( $p['team_name'] ?: '—' ); ?></td>
                                <td><?php echo esc_html( $roles_str ); ?></td>
                                <td><?php echo esc_html( $p['school_name'] ?: '—' ); ?></td>
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
                            <tr class="hl-detail-row" id="hl-detail-<?php echo esc_attr( $eid ); ?>">
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
                                                        $aid        = $act['component_id'];
                                                        $ad         = isset( $activity_detail[ $eid ][ $aid ] ) ? $activity_detail[ $eid ][ $aid ] : null;
                                                        $act_pct    = $ad ? intval( $ad['completion_percent'] ) : 0;
                                                        $act_status = $ad ? $ad['completion_status'] : 'not_started';
                                                        $status_lbl = ucwords( str_replace( '_', ' ', $act_status ) );
                                                        $status_cls = 'hl-badge-' . str_replace( '_', '-', $act_status );
                                                        $type_lbl   = ucwords( str_replace( '_', ' ', $act['component_type'] ) );
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
    // Tab: Assessments
    // ========================================================================

    private function render_assessments_tab( $cycle, $scope ) {
        // Staff only — teacher consent required before sharing responses with leaders.
        if ( ! HL_Security::can_manage() ) {
            echo '<div class="hl-notice hl-notice-warning">'
                . esc_html__( 'Assessment responses are not available at this time.', 'hl-core' )
                . '</div>';
            return;
        }

        // Check if viewing a specific TSA instance (detail/form view).
        $view_tsa_id = isset( $_GET['view_tsa'] ) ? absint( $_GET['view_tsa'] ) : 0;
        if ( $view_tsa_id ) {
            $this->render_tsa_detail_view( $view_tsa_id, $cycle, $scope );
            return;
        }

        // Otherwise render the list view.
        $this->render_assessments_list( $cycle, $scope );
    }

    /**
     * Assessments list view — admin-style table of all TSA/CA instances.
     */
    private function render_assessments_list( $cycle, $scope ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $cycle_id  = (int) $cycle->cycle_id;
        $school_id = ( $scope['type'] === 'school' && $scope['orgunit_id'] ) ? (int) $scope['orgunit_id'] : 0;
        $current_user_id = get_current_user_id();

        // ── Fetch TSA instances scoped by school ──
        $tsa_where  = array( 'tai.cycle_id = %d', 'e.user_id != %d' );
        $tsa_params = array( $cycle_id, $current_user_id );
        if ( $school_id ) {
            $tsa_where[]  = 'e.school_id = %d';
            $tsa_params[] = $school_id;
        }

        $tsa_sql = "SELECT tai.instance_id, tai.phase, tai.status, tai.submitted_at,
                           tai.instrument_id, u.display_name, u.user_email
                    FROM {$prefix}hl_teacher_assessment_instance tai
                    JOIN {$prefix}hl_enrollment e ON tai.enrollment_id = e.enrollment_id
                    LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                    WHERE " . implode( ' AND ', $tsa_where ) . "
                    ORDER BY tai.phase ASC, u.display_name ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $tsa_instances = $wpdb->get_results( $wpdb->prepare( $tsa_sql, $tsa_params ), ARRAY_A ) ?: array();

        // Group by phase.
        $tsa_by_phase = array();
        foreach ( $tsa_instances as $inst ) {
            $tsa_by_phase[ $inst['phase'] ][] = $inst;
        }

        // Summary counts.
        $tsa_total     = count( $tsa_instances );
        $tsa_submitted = count( array_filter( $tsa_instances, function ( $i ) { return $i['status'] === 'submitted'; } ) );
        $tsa_pending   = $tsa_total - $tsa_submitted;

        // ── Fetch CA instances scoped by school ──
        $ca_where  = array( 'cai.cycle_id = %d', 'e.user_id != %d' );
        $ca_params = array( $cycle_id, $current_user_id );
        if ( $school_id ) {
            $ca_where[]  = '(cai.school_id = %d OR e.school_id = %d)';
            $ca_params[] = $school_id;
            $ca_params[] = $school_id;
        }

        $ca_sql = "SELECT cai.instance_id, cai.phase, cai.status, cai.submitted_at,
                          cai.instrument_age_band, u.display_name, u.user_email,
                          cr.classroom_name,
                          (SELECT COUNT(*) FROM {$prefix}hl_child_assessment_childrow
                           WHERE instance_id = cai.instance_id AND status = 'active') AS children_assessed
                   FROM {$prefix}hl_child_assessment_instance cai
                   JOIN {$prefix}hl_enrollment e ON cai.enrollment_id = e.enrollment_id
                   LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                   LEFT JOIN {$prefix}hl_classroom cr ON cai.classroom_id = cr.classroom_id
                   WHERE " . implode( ' AND ', $ca_where ) . "
                   ORDER BY cai.phase ASC, u.display_name ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ca_instances = $wpdb->get_results( $wpdb->prepare( $ca_sql, $ca_params ), ARRAY_A ) ?: array();

        $ca_by_phase = array();
        foreach ( $ca_instances as $inst ) {
            $ca_by_phase[ $inst['phase'] ][] = $inst;
        }

        $ca_total     = count( $ca_instances );
        $ca_submitted = count( array_filter( $ca_instances, function ( $i ) { return $i['status'] === 'submitted'; } ) );
        $ca_pending   = $ca_total - $ca_submitted;

        // Build the base URL for View links.
        $base_url = strtok( $_SERVER['REQUEST_URI'], '?' );
        $base_params = array( 'tab' => 'assessments' );
        if ( isset( $_GET['cycle_id'] ) ) {
            $base_params['cycle_id'] = absint( $_GET['cycle_id'] );
        }

        ?>
        <div class="hl-assessments-tab">

            <h3 class="hl-section-title"><?php esc_html_e( 'Teacher Assessments', 'hl-core' ); ?></h3>

            <!-- Summary Cards -->
            <div class="hlmyc-stat-row">
                <div class="hlmyc-stat-card">
                    <div class="hlmyc-stat-value"><?php echo esc_html( $tsa_total ); ?></div>
                    <div class="hlmyc-stat-label"><?php esc_html_e( 'Total Instances', 'hl-core' ); ?></div>
                </div>
                <div class="hlmyc-stat-card">
                    <div class="hlmyc-stat-value hlmyc-stat-value--success"><?php echo esc_html( $tsa_submitted ); ?></div>
                    <div class="hlmyc-stat-label"><?php esc_html_e( 'Submitted', 'hl-core' ); ?></div>
                </div>
                <div class="hlmyc-stat-card">
                    <div class="hlmyc-stat-value hlmyc-stat-value--error"><?php echo esc_html( $tsa_pending ); ?></div>
                    <div class="hlmyc-stat-label"><?php esc_html_e( 'Pending', 'hl-core' ); ?></div>
                </div>
            </div>

            <?php foreach ( $tsa_by_phase as $phase => $phase_data ) : ?>
                <div class="hlmyc-phase-divider">
                    <?php echo esc_html( strtoupper( $phase ) . '-Assessment' ); ?>
                    <span class="hlmyc-phase-count">(<?php echo esc_html( count( $phase_data ) ); ?>)</span>
                </div>

                <div class="hl-table-container">
                    <table class="hl-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Teacher', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Email', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Submitted At', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'hl-core' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $phase_data as $inst ) :
                                $status_class = 'hl-badge-' . str_replace( '_', '-', $inst['status'] );
                                $status_label = ucwords( str_replace( '_', ' ', $inst['status'] ) );
                                $is_submitted = ( $inst['status'] === 'submitted' );
                                $view_url     = $is_submitted
                                    ? add_query_arg( array_merge( $base_params, array( 'view_tsa' => $inst['instance_id'] ) ), $base_url )
                                    : '';
                            ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $inst['display_name'] ); ?></strong></td>
                                    <td><?php echo esc_html( $inst['user_email'] ); ?></td>
                                    <td><span class="hl-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
                                    <td><?php echo $inst['submitted_at'] ? esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $inst['submitted_at'] ) ) ) : '—'; ?></td>
                                    <td>
                                        <?php if ( $is_submitted && $view_url ) : ?>
                                            <a href="<?php echo esc_url( $view_url ); ?>" class="hl-btn hl-btn-sm hl-btn-secondary"><?php esc_html_e( 'View', 'hl-core' ); ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

            <?php if ( ! empty( $ca_instances ) ) : ?>
                <h3 class="hl-section-title" style="margin-top:2.5rem;"><?php esc_html_e( 'Child Assessments', 'hl-core' ); ?></h3>

                <div class="hlmyc-stat-row">
                    <div class="hlmyc-stat-card">
                        <div class="hlmyc-stat-value"><?php echo esc_html( $ca_total ); ?></div>
                        <div class="hlmyc-stat-label"><?php esc_html_e( 'Total Instances', 'hl-core' ); ?></div>
                    </div>
                    <div class="hlmyc-stat-card">
                        <div class="hlmyc-stat-value hlmyc-stat-value--success"><?php echo esc_html( $ca_submitted ); ?></div>
                        <div class="hlmyc-stat-label"><?php esc_html_e( 'Submitted', 'hl-core' ); ?></div>
                    </div>
                    <div class="hlmyc-stat-card">
                        <div class="hlmyc-stat-value hlmyc-stat-value--error"><?php echo esc_html( $ca_pending ); ?></div>
                        <div class="hlmyc-stat-label"><?php esc_html_e( 'Pending', 'hl-core' ); ?></div>
                    </div>
                </div>

                <?php foreach ( $ca_by_phase as $phase => $phase_data ) : ?>
                    <div class="hlmyc-phase-divider hlmyc-phase-divider--ca">
                        <?php echo esc_html( strtoupper( $phase ) . '-Assessment' ); ?>
                        <span class="hlmyc-phase-count">(<?php echo esc_html( count( $phase_data ) ); ?>)</span>
                    </div>

                    <div class="hl-table-container">
                        <table class="hl-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Teacher', 'hl-core' ); ?></th>
                                    <th><?php esc_html_e( 'Classroom', 'hl-core' ); ?></th>
                                    <th><?php esc_html_e( 'Age Band', 'hl-core' ); ?></th>
                                    <th><?php esc_html_e( 'Children', 'hl-core' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                                    <th><?php esc_html_e( 'Submitted At', 'hl-core' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $phase_data as $inst ) :
                                    $status_class = 'hl-badge-' . str_replace( '_', '-', $inst['status'] );
                                    $status_label = ucwords( str_replace( '_', ' ', $inst['status'] ) );
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $inst['display_name'] ); ?></strong></td>
                                        <td><?php echo esc_html( $inst['classroom_name'] ?: '—' ); ?></td>
                                        <td><?php echo esc_html( $inst['instrument_age_band'] ? ucfirst( $inst['instrument_age_band'] ) : '—' ); ?></td>
                                        <td><?php echo esc_html( $inst['children_assessed'] ?: '0' ); ?></td>
                                        <td><span class="hl-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
                                        <td><?php echo $inst['submitted_at'] ? esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $inst['submitted_at'] ) ) ) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ( empty( $tsa_instances ) && empty( $ca_instances ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No assessment data found for your school.', 'hl-core' ); ?></p></div>
            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * TSA detail view — renders the submitted assessment form in read-only mode.
     */
    private function render_tsa_detail_view( $instance_id, $cycle, $scope ) {
        $assessment_service = new HL_Assessment_Service();
        $instance = $assessment_service->get_teacher_assessment( $instance_id );

        if ( ! $instance ) {
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'Assessment not found.', 'hl-core' ) . '</div>';
            return;
        }

        // Security: verify the instance belongs to this cycle and the leader's school scope.
        if ( (int) $instance['cycle_id'] !== (int) $cycle->cycle_id ) {
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'Access denied.', 'hl-core' ) . '</div>';
            return;
        }

        if ( $scope['type'] === 'school' && $scope['orgunit_id'] ) {
            global $wpdb;
            $enrollment_school = $wpdb->get_var( $wpdb->prepare(
                "SELECT school_id FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
                $instance['enrollment_id']
            ) );
            if ( (int) $enrollment_school !== (int) $scope['orgunit_id'] ) {
                echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'Access denied.', 'hl-core' ) . '</div>';
                return;
            }
        }

        // Load instrument.
        $instrument = $assessment_service->get_teacher_instrument( $instance['instrument_id'] );
        if ( ! $instrument ) {
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'Instrument not found.', 'hl-core' ) . '</div>';
            return;
        }

        // Decode responses.
        $responses = ! empty( $instance['responses_json'] ) ? json_decode( $instance['responses_json'], true ) : array();

        // For POST phase, load PRE responses for the "Before" column.
        $pre_responses = array();
        if ( $instance['phase'] === 'post' ) {
            $pre_responses = $assessment_service->get_pre_responses_for_post(
                $instance['enrollment_id'],
                $instance['cycle_id']
            );
        }

        // Back link.
        $base_url = strtok( $_SERVER['REQUEST_URI'], '?' );
        $back_params = array( 'tab' => 'assessments' );
        if ( isset( $_GET['cycle_id'] ) ) {
            $back_params['cycle_id'] = absint( $_GET['cycle_id'] );
        }
        $back_url = add_query_arg( $back_params, $base_url );

        ?>
        <div class="hl-tsa-detail-view">
            <a href="<?php echo esc_url( $back_url ); ?>" class="hlmyc-back-link">
                &larr; <?php esc_html_e( 'Back to Assessments', 'hl-core' ); ?>
            </a>

            <div class="hlmyc-detail-grid">
                <div class="hlmyc-detail-grid-inner">
                    <div>
                        <div class="hlmyc-detail-label"><?php esc_html_e( 'Teacher', 'hl-core' ); ?></div>
                        <div class="hlmyc-detail-value"><?php echo esc_html( $instance['display_name'] ); ?></div>
                    </div>
                    <div>
                        <div class="hlmyc-detail-label"><?php esc_html_e( 'Email', 'hl-core' ); ?></div>
                        <div><?php echo esc_html( $instance['user_email'] ); ?></div>
                    </div>
                    <div>
                        <div class="hlmyc-detail-label"><?php esc_html_e( 'Phase', 'hl-core' ); ?></div>
                        <div class="hlmyc-detail-value"><?php echo esc_html( strtoupper( $instance['phase'] ) ); ?></div>
                    </div>
                    <div>
                        <div class="hlmyc-detail-label"><?php esc_html_e( 'Status', 'hl-core' ); ?></div>
                        <div><span class="hl-badge hl-badge-submitted"><?php echo esc_html( ucwords( str_replace( '_', ' ', $instance['status'] ) ) ); ?></span></div>
                    </div>
                    <div>
                        <div class="hlmyc-detail-label"><?php esc_html_e( 'Submitted', 'hl-core' ); ?></div>
                        <div><?php echo $instance['submitted_at'] ? esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $instance['submitted_at'] ) ) ) : '—'; ?></div>
                    </div>
                </div>
            </div>

            <?php
            // Render the submitted form using the existing renderer in read-only mode.
            $renderer = new HL_Teacher_Assessment_Renderer(
                $instrument,
                (object) $instance,
                $instance['phase'],
                $responses,
                $pre_responses,
                true, // read_only = true
                array(
                    'show_instrument_name' => true,
                    'show_program_name'    => false,
                )
            );
            echo $renderer->render();
            ?>
        </div>
        <?php
    }

    // ========================================================================
    // Tab: Classrooms
    // ========================================================================

    private function render_classrooms_tab( $cycle, $scope ) {
        $school_ids = $this->get_scoped_school_ids( $scope );

        // Always get cycle-scoped classrooms (via teaching assignments),
        // then optionally filter by the leader's school scope.
        $classrooms = $this->get_cycle_classrooms( $cycle->cycle_id );

        if ( ! empty( $school_ids ) ) {
            $classrooms = array_filter( $classrooms, function ( $c ) use ( $school_ids ) {
                $cr = is_object( $c ) ? $c : (object) $c;
                // Include classrooms matching a scoped school OR with no school assigned.
                return empty( $cr->school_id )
                    || in_array( (int) $cr->school_id, $school_ids, true );
            } );
            $classrooms = array_values( $classrooms );
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
        $teacher_names = $this->get_classroom_teacher_names( $classroom_ids, $cycle->cycle_id );

        $school_cache       = array();
        $classroom_page_url = $this->find_shortcode_page_url( 'hl_classroom_page' );

        ?>
        <div class="hl-table-container">
            <h3 class="hl-section-title"><?php esc_html_e( 'Classrooms', 'hl-core' ); ?></h3>

            <table class="hl-table" id="hl-classrooms-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Classroom', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'School', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Age Band', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Children', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Teacher(s)', 'hl-core' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $classrooms as $classroom ) :
                        $cr  = is_object( $classroom ) ? $classroom : (object) $classroom;
                        $cid = $cr->classroom_id;

                        if ( ! isset( $school_cache[ $cr->school_id ] ) ) {
                            $school_obj                        = $this->orgunit_repo->get_by_id( $cr->school_id );
                            $school_cache[ $cr->school_id ]    = $school_obj ? $school_obj->name : '';
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
                            <td><?php echo esc_html( $school_cache[ $cr->school_id ] ); ?></td>
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
     * Batch-get teacher names per classroom (scoped to a cycle).
     */
    private function get_classroom_teacher_names( $classroom_ids, $cycle_id ) {
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
               AND e.cycle_id = %d
             GROUP BY ta.classroom_id",
            array_merge( $classroom_ids, array( $cycle_id ) )
        ), ARRAY_A );

        $map = array();
        foreach ( $results as $row ) {
            $map[ $row['classroom_id'] ] = $row['teacher_names'];
        }
        return $map;
    }

    /**
     * Get classrooms that have teaching assignments for a cycle.
     */
    private function get_cycle_classrooms( $cycle_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT cr.*
             FROM {$prefix}hl_classroom cr
             INNER JOIN {$prefix}hl_teaching_assignment ta ON cr.classroom_id = ta.classroom_id
             INNER JOIN {$prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             WHERE e.cycle_id = %d
             ORDER BY cr.classroom_name ASC",
            $cycle_id
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

    /**
     * Get the HL User Profile URL for a user.
     *
     * @param int $user_id
     * @return string URL or empty string if page not found.
     */
    private function get_profile_url( $user_id ) {
        static $base_url = null;
        if ( $base_url === null ) {
            $base_url = $this->find_shortcode_page_url( 'hl_user_profile' );
        }
        if ( empty( $base_url ) ) {
            return '';
        }
        return add_query_arg( 'user_id', (int) $user_id, $base_url );
    }
}
