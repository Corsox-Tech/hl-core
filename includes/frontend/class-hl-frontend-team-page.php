<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_team_page] shortcode.
 *
 * Displays a single team detail view with two tabs:
 * - Team Members: table with name, email, role, completion %
 * - Report: completion report with per-activity detail expansion and CSV export
 *
 * Access: Housman Admin, Coach, Center Leaders, District Leaders, Team Members.
 * URL: ?id={team_id}
 *
 * @package HL_Core
 */
class HL_Frontend_Team_Page {

    /** @var HL_Team_Repository */
    private $team_repo;

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    /** @var HL_Cohort_Repository */
    private $cohort_repo;

    /** @var HL_OrgUnit_Repository */
    private $orgunit_repo;

    /** @var HL_Reporting_Service */
    private $reporting_service;

    public function __construct() {
        $this->team_repo         = new HL_Team_Repository();
        $this->enrollment_repo   = new HL_Enrollment_Repository();
        $this->cohort_repo       = new HL_Cohort_Repository();
        $this->orgunit_repo      = new HL_OrgUnit_Repository();
        $this->reporting_service = HL_Reporting_Service::instance();
    }

    // ========================================================================
    // CSV Export (called from template_redirect, before headers)
    // ========================================================================

    public static function handle_export() {
        if ( empty( $_GET['hl_export_action'] ) || $_GET['hl_export_action'] !== 'team_csv' ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_die( __( 'You must be logged in.', 'hl-core' ) );
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'hl_team_export' ) ) {
            wp_die( __( 'Invalid security token.', 'hl-core' ) );
        }

        $team_id = absint( $_GET['team_id'] ?? 0 );
        if ( ! $team_id ) {
            wp_die( __( 'Missing team ID.', 'hl-core' ) );
        }

        $team_repo = new HL_Team_Repository();
        $team      = $team_repo->get_by_id( $team_id );
        if ( ! $team ) {
            wp_die( __( 'Team not found.', 'hl-core' ) );
        }

        $reporting = HL_Reporting_Service::instance();
        $filters   = array(
            'cohort_id' => $team->cohort_id,
            'team_id'   => $team->team_id,
        );
        $csv = $reporting->export_completion_csv( $filters, true );

        $filename = sanitize_file_name( $team->team_name ) . '-report';

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

    public function render( $atts ) {
        ob_start();

        $user_id = get_current_user_id();
        $team_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( ! $team_id ) {
            echo '<div class="hl-dashboard hl-team-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'Invalid team link.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $team = $this->team_repo->get_by_id( $team_id );
        if ( ! $team ) {
            echo '<div class="hl-dashboard hl-team-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'Team not found.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        // Access check.
        if ( ! $this->verify_access( $team, $user_id ) ) {
            echo '<div class="hl-dashboard hl-team-page">';
            echo '<div class="hl-notice hl-notice-error">' . esc_html__( 'You do not have access to this team.', 'hl-core' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $cohort = $this->cohort_repo->get_by_id( $team->cohort_id );
        $center = $team->center_id ? $this->orgunit_repo->get_by_id( $team->center_id ) : null;

        // Breadcrumb URL.
        $back_url = $this->build_back_url( $team );

        // Active tab.
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'members';
        $valid_tabs = array( 'members', 'report' );
        if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
            $active_tab = 'members';
        }

        $tabs = array(
            'members' => __( 'Team Members', 'hl-core' ),
            'report'  => __( 'Report', 'hl-core' ),
        );

        ?>
        <div class="hl-dashboard hl-team-page hl-frontend-wrap">

            <?php if ( ! empty( $back_url ) ) : ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="hl-back-link">&larr; <?php
                    if ( $cohort ) {
                        printf( esc_html__( 'Back to %s', 'hl-core' ), esc_html( $cohort->cohort_name ) );
                    } else {
                        esc_html_e( 'Back to My Cohort', 'hl-core' );
                    }
                ?></a>
            <?php endif; ?>

            <?php $this->render_header( $team, $cohort, $center ); ?>

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
                    if ( $key === 'members' ) {
                        $this->render_members_tab( $team );
                    } else {
                        $this->render_report_tab( $team, $cohort );
                    }
                    ?>
                </div>
            <?php endforeach; ?>

        </div>
        <?php

        return ob_get_clean();
    }

    // ========================================================================
    // Access Control
    // ========================================================================

    /**
     * Check if the current user can view this team.
     *
     * Allowed: staff (manage_hl_core), team members, center/district leaders
     * whose scope includes the team's center.
     */
    private function verify_access( $team, $user_id ) {
        // Staff always has access.
        if ( HL_Security::can_manage() ) {
            return true;
        }

        // Check if user is a member of this team.
        $members = $this->team_repo->get_members( $team->team_id );
        foreach ( $members as $m ) {
            if ( (int) $m['user_id'] === $user_id ) {
                return true;
            }
        }

        // Check if user is a leader whose scope includes this team's center.
        $enrollments = $this->enrollment_repo->get_all( array(
            'cohort_id' => $team->cohort_id,
            'status'    => 'active',
        ) );

        foreach ( $enrollments as $enrollment ) {
            if ( (int) $enrollment->user_id !== $user_id ) {
                continue;
            }
            $roles = $enrollment->get_roles_array();

            // Center leader — team must be in their center.
            if ( in_array( 'center_leader', $roles, true ) && $enrollment->center_id ) {
                if ( (int) $enrollment->center_id === (int) $team->center_id || empty( $team->center_id ) ) {
                    return true;
                }
            }

            // District leader — team's center must be within their district.
            if ( in_array( 'district_leader', $roles, true ) && $enrollment->district_id ) {
                if ( empty( $team->center_id ) ) {
                    return true;
                }
                $centers = $this->orgunit_repo->get_centers( (int) $enrollment->district_id );
                $center_ids = array_map( function ( $c ) { return (int) $c->orgunit_id; }, $centers );
                if ( in_array( (int) $team->center_id, $center_ids, true ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    // ========================================================================
    // Header
    // ========================================================================

    private function render_header( $team, $cohort, $center ) {
        $members      = $this->team_repo->get_members( $team->team_id );
        $member_count = count( $members );

        // Avg completion.
        $sum = 0;
        foreach ( $members as $m ) {
            $sum += $this->reporting_service->get_enrollment_completion( $m['enrollment_id'] );
        }
        $avg = $member_count > 0 ? round( $sum / $member_count ) : 0;

        // Mentors.
        $mentor_names = array();
        foreach ( $members as $m ) {
            if ( $m['membership_type'] === 'mentor' ) {
                $mentor_names[] = $m['display_name'] ?: $m['user_email'];
            }
        }

        ?>
        <div class="hl-team-page-header">
            <div class="hl-team-page-header-info">
                <h2 class="hl-cohort-title"><?php echo esc_html( $team->team_name ); ?></h2>
                <?php if ( $center ) : ?>
                    <p class="hl-scope-indicator"><?php echo esc_html( $center->name ); ?></p>
                <?php endif; ?>
                <div class="hl-cohort-meta">
                    <?php if ( $cohort ) : ?>
                        <span class="hl-meta-item">
                            <strong><?php esc_html_e( 'Cohort:', 'hl-core' ); ?></strong>
                            <?php echo esc_html( $cohort->cohort_name ); ?>
                        </span>
                    <?php endif; ?>
                    <span class="hl-meta-item">
                        <strong><?php esc_html_e( 'Members:', 'hl-core' ); ?></strong>
                        <?php echo esc_html( $member_count ); ?>
                    </span>
                    <?php if ( ! empty( $mentor_names ) ) : ?>
                        <span class="hl-meta-item">
                            <strong><?php esc_html_e( 'Mentor(s):', 'hl-core' ); ?></strong>
                            <?php echo esc_html( implode( ', ', $mentor_names ) ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hl-team-page-header-stats">
                <div class="hl-metric-card" style="background:transparent;border:1px solid rgba(255,255,255,0.2);">
                    <div class="hl-metric-value" style="color:#FFFFFF;"><?php echo esc_html( $avg . '%' ); ?></div>
                    <div class="hl-metric-label" style="color:rgba(255,255,255,0.7);"><?php esc_html_e( 'Avg Completion', 'hl-core' ); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    // ========================================================================
    // Tab: Team Members
    // ========================================================================

    private function render_members_tab( $team ) {
        $members = $this->team_repo->get_members( $team->team_id );

        if ( empty( $members ) ) {
            echo '<div class="hl-empty-state"><p>'
                . esc_html__( 'No members in this team.', 'hl-core' )
                . '</p></div>';
            return;
        }

        ?>
        <div class="hl-table-container">
            <div class="hl-table-header">
                <h3 class="hl-section-title"><?php esc_html_e( 'Team Members', 'hl-core' ); ?></h3>
                <div class="hl-table-filters">
                    <input type="text" class="hl-search-input" data-table="hl-team-members-table"
                           placeholder="<?php esc_attr_e( 'Search by name...', 'hl-core' ); ?>">
                </div>
            </div>

            <table class="hl-table" id="hl-team-members-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Completion', 'hl-core' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $members as $m ) :
                        $completion = round( $this->reporting_service->get_enrollment_completion( $m['enrollment_id'] ) );
                        $pclass     = $completion >= 100 ? 'hl-progress-complete' : ( $completion > 0 ? 'hl-progress-active' : '' );
                        $roles_raw  = json_decode( $m['roles'], true );
                        $role_label = ucwords( str_replace( '_', ' ', $m['membership_type'] ) );
                    ?>
                        <tr data-name="<?php echo esc_attr( strtolower( $m['display_name'] ?? '' ) ); ?>">
                            <td><strong><?php echo esc_html( $m['display_name'] ); ?></strong></td>
                            <td><?php echo esc_html( $m['user_email'] ); ?></td>
                            <td><span class="hl-badge hl-badge-role"><?php echo esc_html( $role_label ); ?></span></td>
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
    // Tab: Report
    // ========================================================================

    private function render_report_tab( $team, $cohort ) {
        if ( ! $cohort ) {
            echo '<div class="hl-empty-state"><p>'
                . esc_html__( 'Cohort data unavailable.', 'hl-core' )
                . '</p></div>';
            return;
        }

        $filters      = array(
            'cohort_id' => $cohort->cohort_id,
            'team_id'   => $team->team_id,
        );
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

        // CSV export URL.
        $export_url = add_query_arg( array(
            'hl_export_action' => 'team_csv',
            'team_id'          => $team->team_id,
            '_wpnonce'         => wp_create_nonce( 'hl_team_export' ),
        ) );

        ?>
        <div class="hl-table-container hl-reports-container">
            <div class="hl-table-header">
                <h3 class="hl-section-title"><?php esc_html_e( 'Team Completion Report', 'hl-core' ); ?></h3>
                <a href="<?php echo esc_url( $export_url ); ?>" class="hl-btn hl-btn-sm hl-btn-primary hl-export-btn">
                    <?php esc_html_e( 'Download CSV', 'hl-core' ); ?>
                </a>
            </div>

            <?php if ( empty( $participants ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No participants found.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <table class="hl-table hl-reports-table" id="hl-team-report-table">
                    <thead>
                        <tr>
                            <th style="width:30px">#</th>
                            <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Role', 'hl-core' ); ?></th>
                            <th><?php esc_html_e( 'Completed', 'hl-core' ); ?></th>
                            <th style="width:80px"><?php esc_html_e( 'Details', 'hl-core' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $row_num = 0;
                        foreach ( $participants as $p ) :
                            $row_num++;
                            $eid        = $p['enrollment_id'];
                            $roles_raw  = json_decode( $p['roles'], true );
                            $roles_str  = is_array( $roles_raw )
                                ? implode( ', ', array_map( function ( $r ) {
                                    return ucwords( str_replace( '_', ' ', $r ) );
                                }, $roles_raw ) )
                                : '';
                            $completion = round( floatval( $p['cohort_completion_percent'] ) );
                            $pclass     = $completion >= 100 ? 'hl-progress-complete' : ( $completion > 0 ? 'hl-progress-active' : '' );
                        ?>
                            <tr class="hl-report-row"
                                data-name="<?php echo esc_attr( strtolower( $p['display_name'] ) ); ?>">
                                <td><?php echo esc_html( $row_num ); ?></td>
                                <td><strong><?php echo esc_html( $p['display_name'] ); ?></strong></td>
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
                                <td>
                                    <button class="hl-btn hl-btn-sm hl-btn-secondary hl-detail-toggle"
                                            data-target="hl-team-detail-<?php echo esc_attr( $eid ); ?>">
                                        <?php esc_html_e( 'View', 'hl-core' ); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr class="hl-detail-row" id="hl-team-detail-<?php echo esc_attr( $eid ); ?>" style="display:none">
                                <td colspan="5">
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
    // Helpers
    // ========================================================================

    private function build_back_url( $team ) {
        // Link back to My Cohort with the classrooms tab for the team's cohort.
        $base = apply_filters( 'hl_core_my_cohort_page_url', '' );
        if ( empty( $base ) ) {
            $base = $this->find_shortcode_page_url( 'hl_my_cohort' );
        }
        if ( ! empty( $base ) ) {
            return add_query_arg( array(
                'cohort_id' => $team->cohort_id,
                'tab'       => 'teams',
            ), $base );
        }
        return '';
    }

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
