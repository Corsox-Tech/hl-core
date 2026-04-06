<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_team_page] shortcode.
 *
 * Displays a single team detail view with a unified table:
 * - Columns: #, Name, Email, Role (badge), Completion, Details (expandable)
 * - Includes search filter and CSV export
 *
 * Access: Housman Admin, Coach, School Leaders, District Leaders, Team Members.
 * URL: ?id={team_id}
 *
 * @package HL_Core
 */
class HL_Frontend_Team_Page {

    /** @var HL_Team_Repository */
    private $team_repo;

    /** @var HL_Enrollment_Repository */
    private $enrollment_repo;

    /** @var HL_Cycle_Repository */
    private $cycle_repo;

    /** @var HL_OrgUnit_Repository */
    private $orgunit_repo;

    /** @var HL_Reporting_Service */
    private $reporting_service;

    public function __construct() {
        $this->team_repo         = new HL_Team_Repository();
        $this->enrollment_repo   = new HL_Enrollment_Repository();
        $this->cycle_repo       = new HL_Cycle_Repository();
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
            'cycle_id' => $team->cycle_id,
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

        $cycle = $this->cycle_repo->get_by_id( $team->cycle_id );
        $school = $team->school_id ? $this->orgunit_repo->get_by_id( $team->school_id ) : null;

        // Breadcrumb URL.
        $back_url = $this->build_back_url( $team );

        ?>
        <div class="hl-dashboard hl-team-page hl-frontend-wrap">

            <?php if ( ! empty( $back_url ) ) : ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="hl-back-link">&larr; <?php
                    if ( $cycle ) {
                        printf( esc_html__( 'Back to %s', 'hl-core' ), esc_html( $cycle->cycle_name ) );
                    } else {
                        esc_html_e( 'Back to My Cycle', 'hl-core' );
                    }
                ?></a>
            <?php endif; ?>

            <?php $this->render_header( $team, $cycle, $school ); ?>

            <?php $this->render_team_table( $team, $cycle ); ?>

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
     * Allowed: staff (manage_hl_core), team members, school/district leaders
     * whose scope includes the team's school.
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

        // Check if user is a leader whose scope includes this team's school.
        $enrollments = $this->enrollment_repo->get_all( array(
            'cycle_id' => $team->cycle_id,
            'status'    => 'active',
        ) );

        foreach ( $enrollments as $enrollment ) {
            if ( (int) $enrollment->user_id !== $user_id ) {
                continue;
            }
            $roles = $enrollment->get_roles_array();

            // School leader — team must be in their school.
            if ( in_array( 'school_leader', $roles, true ) && $enrollment->school_id ) {
                if ( (int) $enrollment->school_id === (int) $team->school_id || empty( $team->school_id ) ) {
                    return true;
                }
            }

            // District leader — team's school must be within their district.
            if ( in_array( 'district_leader', $roles, true ) && $enrollment->district_id ) {
                if ( empty( $team->school_id ) ) {
                    return true;
                }
                $schools = $this->orgunit_repo->get_schools( (int) $enrollment->district_id );
                $school_ids = array_map( function ( $c ) { return (int) $c->orgunit_id; }, $schools );
                if ( in_array( (int) $team->school_id, $school_ids, true ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    // ========================================================================
    // Header
    // ========================================================================

    private function render_header( $team, $cycle, $school ) {
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
    <div class="hl-page-hero">
        <div class="hl-page-hero__icon">
            <span class="dashicons dashicons-groups"></span>
        </div>
        <div class="hl-page-hero__text">
            <span class="hl-page-hero__tag"><?php esc_html_e( 'Team', 'hl-core' ); ?></span>
            <h2 class="hl-page-hero__title"><?php echo esc_html( $team->team_name ); ?></h2>
            <?php if ( $school ) : ?>
                <p class="hl-page-hero__subtitle"><?php echo esc_html( $school->name ); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="hl-meta-bar">
        <?php if ( $cycle ) : ?>
            <div class="hl-meta-item">
                <div class="hl-meta-item__icon"><span class="dashicons dashicons-clock"></span></div>
                <div>
                    <div class="hl-meta-item__label"><?php esc_html_e( 'Cycle', 'hl-core' ); ?></div>
                    <div class="hl-meta-item__value"><?php echo esc_html( $cycle->cycle_name ); ?></div>
                </div>
            </div>
        <?php endif; ?>
        <div class="hl-meta-item">
            <div class="hl-meta-item__icon"><span class="dashicons dashicons-admin-users"></span></div>
            <div>
                <div class="hl-meta-item__label"><?php esc_html_e( 'Members', 'hl-core' ); ?></div>
                <div class="hl-meta-item__value"><?php echo esc_html( $member_count ); ?></div>
            </div>
        </div>
        <?php if ( ! empty( $mentor_names ) ) : ?>
            <div class="hl-meta-item">
                <div class="hl-meta-item__icon"><span class="dashicons dashicons-businessman"></span></div>
                <div>
                    <div class="hl-meta-item__label"><?php esc_html_e( 'Mentor(s)', 'hl-core' ); ?></div>
                    <div class="hl-meta-item__value"><?php echo esc_html( implode( ', ', $mentor_names ) ); ?></div>
                </div>
            </div>
        <?php endif; ?>
        <div class="hl-meta-item">
            <div class="hl-meta-item__icon"><span class="dashicons dashicons-chart-area"></span></div>
            <div>
                <div class="hl-meta-item__label"><?php esc_html_e( 'Avg Completion', 'hl-core' ); ?></div>
                <div class="hl-meta-item__value"><?php echo esc_html( $avg . '%' ); ?></div>
            </div>
        </div>
    </div>
    <?php
    }

    // ========================================================================
    // Unified Team Table
    // ========================================================================

    private function render_team_table( $team, $cycle ) {
        $members = $this->team_repo->get_members( $team->team_id );

        if ( empty( $members ) ) {
            echo '<div class="hl-empty-state"><p>'
                . esc_html__( 'No members in this team.', 'hl-core' )
                . '</p></div>';
            return;
        }

        // Activity detail for expandable rows (needs cycle).
        $activity_detail   = array();
        $pathway_by_eid    = array();

        if ( $cycle ) {
            $enrollment_ids = array_map( function ( $m ) { return $m['enrollment_id']; }, $members );

            if ( ! empty( $enrollment_ids ) && method_exists( $this->reporting_service, 'get_cycle_component_detail' ) ) {
                $activity_detail = $this->reporting_service->get_cycle_component_detail(
                    $cycle->cycle_id,
                    $enrollment_ids
                );

                // Get each enrollment's assigned pathway to filter components.
                global $wpdb;
                $eid_placeholders = implode( ',', array_fill( 0, count( $enrollment_ids ), '%d' ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $pa_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT enrollment_id, pathway_id FROM {$wpdb->prefix}hl_pathway_assignment WHERE enrollment_id IN ({$eid_placeholders})",
                    $enrollment_ids
                ), ARRAY_A );
                foreach ( $pa_rows as $pa ) {
                    $pathway_by_eid[ (int) $pa['enrollment_id'] ] = (int) $pa['pathway_id'];
                }
            }
        }

        // CSV export URL.
        $export_url = add_query_arg( array(
            'hl_export_action' => 'team_csv',
            'team_id'          => $team->team_id,
            '_wpnonce'         => wp_create_nonce( 'hl_team_export' ),
        ) );

        ?>
        <div class="hl-table-container">
            <div class="hl-table-header">
                <h3 class="hl-section-title"><?php esc_html_e( 'Team Members', 'hl-core' ); ?></h3>
                <div class="hl-table-header-actions">
                    <input type="text" class="hl-search-input" data-table="hl-team-members-table"
                           placeholder="<?php esc_attr_e( 'Search by name...', 'hl-core' ); ?>">
                    <a href="<?php echo esc_url( $export_url ); ?>" class="hl-btn hl-btn-sm hl-btn-primary hl-export-btn">
                        <?php esc_html_e( 'Download CSV', 'hl-core' ); ?>
                    </a>
                </div>
            </div>

            <table class="hl-table hl-reports-table" id="hl-team-members-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Completion', 'hl-core' ); ?></th>
                        <th><?php esc_html_e( 'Details', 'hl-core' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $row_num = 0;
                    foreach ( $members as $m ) :
                        $row_num++;
                        $eid        = $m['enrollment_id'];
                        $completion = round( $this->reporting_service->get_enrollment_completion( $eid ) );
                        $pclass     = $completion >= 100 ? 'hl-progress-complete' : ( $completion > 0 ? 'hl-progress-active' : '' );
                        $role_label = ucwords( str_replace( '_', ' ', $m['membership_type'] ) );
                    ?>
                        <tr class="hl-report-row"
                            data-name="<?php echo esc_attr( strtolower( $m['display_name'] ?? '' ) ); ?>">
                            <td><?php echo esc_html( $row_num ); ?></td>
                            <td><strong><?php
                                $profile_url = $this->get_profile_url( $m['user_id'] ?? 0 );
                                if ( $profile_url ) {
                                    echo '<a href="' . esc_url( $profile_url ) . '" class="hl-profile-link">' . esc_html( $m['display_name'] ) . '</a>';
                                } else {
                                    echo esc_html( $m['display_name'] );
                                }
                            ?></strong></td>
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
                            <td>
                                <button class="hl-btn hl-btn-sm hl-btn-secondary hl-detail-toggle"
                                        data-target="hl-team-detail-<?php echo esc_attr( $eid ); ?>">
                                    <?php esc_html_e( 'View', 'hl-core' ); ?>
                                </button>
                            </td>
                        </tr>
                        <tr class="hl-detail-row" id="hl-team-detail-<?php echo esc_attr( $eid ); ?>">
                            <td colspan="6">
                                <div class="hl-detail-content">
                                    <?php if ( ! empty( $activity_detail[ $eid ] ) ) : ?>
                                        <table class="hl-table hl-detail-table">
                                            <thead>
                                                <tr>
                                                    <th><?php esc_html_e( 'Component', 'hl-core' ); ?></th>
                                                    <th><?php esc_html_e( 'Type', 'hl-core' ); ?></th>
                                                    <th><?php esc_html_e( 'Progress', 'hl-core' ); ?></th>
                                                    <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $member_pathway = isset( $pathway_by_eid[ (int) $eid ] ) ? $pathway_by_eid[ (int) $eid ] : 0;
                                                foreach ( $activity_detail[ $eid ] as $aid => $ad ) :
                                                    // Only show components from this member's assigned pathway.
                                                    if ( $member_pathway && isset( $ad['pathway_id'] ) && (int) $ad['pathway_id'] !== $member_pathway ) {
                                                        continue;
                                                    }
                                                    $act_pct    = intval( $ad['completion_percent'] );
                                                    $act_status = $ad['completion_status'];
                                                    $status_lbl = ucwords( str_replace( '_', ' ', $act_status ) );
                                                    $status_cls = 'hl-badge-' . str_replace( '_', '-', $act_status );
                                                    $type_lbl   = ucwords( str_replace( '_', ' ', $ad['component_type'] ) );
                                                ?>
                                                    <tr>
                                                        <td><?php echo esc_html( $ad['title'] ); ?></td>
                                                        <td><span class="hl-activity-type"><?php echo esc_html( $type_lbl ); ?></span></td>
                                                        <td><?php echo esc_html( $act_pct . '%' ); ?></td>
                                                        <td><span class="hl-badge <?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( $status_lbl ); ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else : ?>
                                        <p><?php esc_html_e( 'No component data available.', 'hl-core' ); ?></p>
                                    <?php endif; ?>
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
    // Helpers
    // ========================================================================

    private function build_back_url( $team ) {
        // Link back to My Cycle with the teams tab for the team's cycle.
        $base = apply_filters( 'hl_core_my_cycle_page_url', '' );
        if ( empty( $base ) ) {
            $base = $this->find_shortcode_page_url( 'hl_my_cycle' );
        }
        if ( ! empty( $base ) ) {
            return add_query_arg( array(
                'cycle_id' => $team->cycle_id,
                'tab'             => 'teams',
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

    private function get_profile_url( $user_id ) {
        static $base_url = null;
        if ( $base_url === null ) {
            $base_url = $this->find_shortcode_page_url( 'hl_user_profile' );
        }
        return $base_url ? add_query_arg( 'user_id', (int) $user_id, $base_url ) : '';
    }
}
