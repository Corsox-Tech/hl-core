<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_learners] shortcode.
 *
 * Participant directory with search, filters, pagination (25/page), scope filtering.
 *
 * @package HL_Core
 */
class HL_Frontend_Learners {

    const PER_PAGE = 25;

    public function render( $atts ) {
        ob_start();

        $scope = HL_Scope_Service::get_scope();

        if ( ! $scope['is_staff'] && empty( $scope['enrollment_ids'] ) ) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'You do not have access to this page.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        // Mentors who are not staff/leaders can only see their team members.
        $mentor_only = ! $scope['is_staff']
            && in_array( 'mentor', $scope['hl_roles'], true )
            && ! in_array( 'school_leader', $scope['hl_roles'], true )
            && ! in_array( 'district_leader', $scope['hl_roles'], true );

        $page              = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $filter_partnership = isset( $_GET['partnership_id'] ) ? absint( $_GET['partnership_id'] ) : 0;
        $filter_school     = isset( $_GET['school_id'] ) ? absint( $_GET['school_id'] ) : 0;
        $filter_role       = isset( $_GET['role'] ) ? sanitize_text_field( $_GET['role'] ) : '';
        $filter_search     = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';

        $data = $this->get_learners( $scope, $mentor_only, $page, $filter_partnership, $filter_school, $filter_role, $filter_search );

        $partnerships = $this->get_partnership_options( $scope );
        $schools = $this->get_school_options( $scope );

        ?>
        <div class="hl-dashboard hl-learners hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Learners', 'hl-core' ); ?></h2>
            </div>

            <form class="hl-filters-bar" method="get" action="">
                <input type="text" name="q" class="hl-search-input"
                       value="<?php echo esc_attr( $filter_search ); ?>"
                       placeholder="<?php esc_attr_e( 'Search by name or email...', 'hl-core' ); ?>">
                <?php if ( ! empty( $partnerships ) ) : ?>
                    <select name="partnership_id" class="hl-select" onchange="this.form.submit();">
                        <option value=""><?php esc_html_e( 'All Partnerships', 'hl-core' ); ?></option>
                        <?php foreach ( $partnerships as $p ) : ?>
                            <option value="<?php echo esc_attr( $p['partnership_id'] ); ?>"
                                <?php selected( (int) $p['partnership_id'], $filter_partnership ); ?>>
                                <?php echo esc_html( $p['partnership_name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if ( ! empty( $schools ) ) : ?>
                    <select name="school_id" class="hl-select" onchange="this.form.submit();">
                        <option value=""><?php esc_html_e( 'All Schools', 'hl-core' ); ?></option>
                        <?php foreach ( $schools as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->orgunit_id ); ?>"
                                <?php selected( (int) $c->orgunit_id, $filter_school ); ?>>
                                <?php echo esc_html( $c->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <select name="role" class="hl-select" onchange="this.form.submit();">
                    <option value=""><?php esc_html_e( 'All Roles', 'hl-core' ); ?></option>
                    <option value="teacher" <?php selected( 'teacher', $filter_role ); ?>><?php esc_html_e( 'Teacher', 'hl-core' ); ?></option>
                    <option value="mentor" <?php selected( 'mentor', $filter_role ); ?>><?php esc_html_e( 'Mentor', 'hl-core' ); ?></option>
                    <option value="school_leader" <?php selected( 'school_leader', $filter_role ); ?>><?php esc_html_e( 'School Leader', 'hl-core' ); ?></option>
                    <option value="district_leader" <?php selected( 'district_leader', $filter_role ); ?>><?php esc_html_e( 'District Leader', 'hl-core' ); ?></option>
                </select>
                <button type="submit" class="hl-btn hl-btn-sm hl-btn-primary"><?php esc_html_e( 'Search', 'hl-core' ); ?></button>
            </form>

            <?php if ( empty( $data['rows'] ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No learners found.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-table-container">
                    <table class="hl-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Email', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Role(s)', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'School', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Partnership', 'hl-core' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $data['rows'] as $r ) :
                                // Merge roles from all enrollments into unique list.
                                $all_roles = array();
                                foreach ( explode( '||', $r['all_roles'] ?? '' ) as $json ) {
                                    $decoded = json_decode( $json, true );
                                    if ( is_array( $decoded ) ) {
                                        $all_roles = array_merge( $all_roles, $decoded );
                                    }
                                }
                                $all_roles = array_unique( $all_roles );
                                $roles_display = array_map( function( $rl ) {
                                    return ucfirst( str_replace( '_', ' ', $rl ) );
                                }, $all_roles );

                                $schools = array_filter( array_unique( explode( '||', $r['all_schools'] ?? '' ) ) );
                                $partnerships_list = array_filter( array_unique( explode( '||', $r['all_partnerships'] ?? '' ) ) );
                            ?>
                                <tr class="hl-learner-row">
                                    <td>
                                        <?php
                                        $profile_url = $this->get_profile_url( $r['user_id'] );
                                        if ( $profile_url ) : ?>
                                            <a href="<?php echo esc_url( $profile_url ); ?>" class="hl-profile-link"><?php echo esc_html( $r['display_name'] ); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html( $r['display_name'] ); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $r['user_email'] ); ?></td>
                                    <td><?php echo $roles_display ? implode( '<br>', array_map( 'esc_html', $roles_display ) ) : '—'; ?></td>
                                    <td><?php echo $schools ? implode( '<br>', array_map( 'esc_html', $schools ) ) : '—'; ?></td>
                                    <td><?php echo $partnerships_list ? implode( '<br>', array_map( 'esc_html', $partnerships_list ) ) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php $this->render_pagination( $page, $data['total'], self::PER_PAGE ); ?>

                <?php /* No-results handled by server-side empty check */ ?>
            <?php endif; ?>

        </div>

        <?php /* Filters are now server-side — no client-side JS needed */ ?>
        <?php

        return ob_get_clean();
    }

    // =========================================================================
    // Pagination
    // =========================================================================

    private function render_pagination( $current_page, $total, $per_page ) {
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        if ( $total_pages <= 1 ) return;

        // Build base URL preserving current filters.
        $base_args = array();
        if ( ! empty( $_GET['partnership_id'] ) ) $base_args['partnership_id'] = absint( $_GET['partnership_id'] );
        if ( ! empty( $_GET['school_id'] ) )      $base_args['school_id']      = absint( $_GET['school_id'] );
        if ( ! empty( $_GET['role'] ) )            $base_args['role']           = sanitize_text_field( $_GET['role'] );
        if ( ! empty( $_GET['q'] ) )               $base_args['q']             = sanitize_text_field( $_GET['q'] );

        echo '<div class="hl-pagination">';
        for ( $p = 1; $p <= $total_pages; $p++ ) {
            $args  = array_merge( $base_args, array( 'paged' => $p ) );
            $url   = add_query_arg( $args );
            $class = $p === $current_page ? 'hl-btn hl-btn-sm hl-btn-primary' : 'hl-btn hl-btn-sm hl-btn-secondary';
            printf( '<a href="%s" class="%s">%d</a>', esc_url( $url ), esc_attr( $class ), $p );
        }
        echo '</div>';
    }

    // =========================================================================
    // Data helpers
    // =========================================================================

    private function get_learners( $scope, $mentor_only, $page, $filter_partnership = 0, $filter_school = 0, $filter_role = '', $filter_search = '' ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $offset = ( $page - 1 ) * self::PER_PAGE;

        $base_sql = "FROM {$prefix}hl_enrollment e
                     LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                     LEFT JOIN {$prefix}hl_orgunit ou ON e.school_id = ou.orgunit_id
                     LEFT JOIN {$prefix}hl_cycle cy ON e.cycle_id = cy.cycle_id
                     LEFT JOIN {$prefix}hl_partnership p ON cy.partnership_id = p.partnership_id";

        $where       = array( "e.status = 'active'" );
        $values      = array();
        $suspend_sql = HL_BuddyBoss_Integration::get_suspend_not_exists_sql( 'e.user_id' );

        if ( ! $scope['is_admin'] ) {
            if ( $mentor_only && ! empty( $scope['team_ids'] ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $scope['team_ids'] ), '%d' ) );
                $where[]      = "e.enrollment_id IN (
                    SELECT tm.enrollment_id FROM {$prefix}hl_team_membership tm
                    WHERE tm.team_id IN ({$placeholders})
                )";
                $values = array_merge( $values, $scope['team_ids'] );
            } elseif ( ! empty( $scope['cycle_ids'] ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $scope['cycle_ids'] ), '%d' ) );
                $where[]      = "e.cycle_id IN ({$placeholders})";
                $values       = array_merge( $values, $scope['cycle_ids'] );
            } else {
                return array( 'rows' => array(), 'total' => 0, 'per_page' => self::PER_PAGE );
            }
        }

        // Server-side filters.
        if ( $filter_partnership ) {
            $where[]  = 'cy.partnership_id = %d';
            $values[] = $filter_partnership;
        }
        if ( $filter_school ) {
            $where[]  = 'e.school_id = %d';
            $values[] = $filter_school;
        }
        if ( $filter_role ) {
            $where[]  = 'e.roles LIKE %s';
            $values[] = '%"' . $wpdb->esc_like( $filter_role ) . '"%';
        }
        if ( $filter_search ) {
            $where[]  = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $filter_search ) . '%';
            $values[] = $like;
            $values[] = $like;
        }

        $where_clause = ' WHERE ' . implode( ' AND ', $where ) . $suspend_sql;

        // Total count (distinct users).
        $count_sql = "SELECT COUNT(DISTINCT e.user_id) {$base_sql}{$where_clause}";
        if ( ! empty( $values ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $values );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Data page — one row per user, aggregating roles/schools/partnerships.
        $data_sql = "SELECT e.user_id, u.display_name, u.user_email,
                            GROUP_CONCAT(DISTINCT e.roles SEPARATOR '||') AS all_roles,
                            GROUP_CONCAT(DISTINCT ou.name ORDER BY ou.name SEPARATOR '||') AS all_schools,
                            GROUP_CONCAT(DISTINCT p.partnership_name ORDER BY p.partnership_name SEPARATOR '||') AS all_partnerships
                     {$base_sql}{$where_clause}
                     GROUP BY e.user_id, u.display_name, u.user_email
                     ORDER BY u.display_name ASC
                     LIMIT %d OFFSET %d";
        $data_values = array_merge( $values, array( self::PER_PAGE, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_values ), ARRAY_A ) ?: array();

        return array( 'rows' => $rows, 'total' => $total, 'per_page' => self::PER_PAGE );
    }

    private function get_partnership_options( $scope ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        if ( $scope['is_admin'] ) {
            return $wpdb->get_results(
                "SELECT partnership_id, partnership_name FROM {$prefix}hl_partnership WHERE status = 'active' ORDER BY partnership_name ASC",
                ARRAY_A
            ) ?: array();
        }
        if ( empty( $scope['cycle_ids'] ) ) return array();
        $in = implode( ',', array_map( 'intval', $scope['cycle_ids'] ) );
        return $wpdb->get_results(
            "SELECT DISTINCT p.partnership_id, p.partnership_name
             FROM {$prefix}hl_partnership p
             INNER JOIN {$prefix}hl_cycle cy ON cy.partnership_id = p.partnership_id
             WHERE cy.cycle_id IN ({$in})
             ORDER BY p.partnership_name ASC",
            ARRAY_A
        ) ?: array();
    }

    private function get_school_options( $scope ) {
        $repo = new HL_OrgUnit_Repository();
        $all = $repo->get_schools();
        return HL_Scope_Service::filter_by_ids( $all, 'orgunit_id', $scope['school_ids'], $scope['is_admin'] );
    }

    private function get_profile_url( $user_id ) {
        static $base_url = null;
        if ( $base_url === null ) {
            global $wpdb;
            $page_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'page' AND post_status = 'publish'
                 AND post_content LIKE %s LIMIT 1",
                '%[' . $wpdb->esc_like( 'hl_user_profile' ) . '%'
            ) );
            $base_url = $page_id ? get_permalink( $page_id ) : '';
        }
        return $base_url ? add_query_arg( 'user_id', (int) $user_id, $base_url ) : '';
    }
}
