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

        $page       = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $filter_cycle  = isset( $_GET['cycle_id'] ) ? absint( $_GET['cycle_id'] ) : 0;
        $filter_school = isset( $_GET['school_id'] ) ? absint( $_GET['school_id'] ) : 0;
        $filter_role   = isset( $_GET['role'] ) ? sanitize_text_field( $_GET['role'] ) : '';
        $filter_search = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';

        $data = $this->get_learners( $scope, $mentor_only, $page, $filter_cycle, $filter_school, $filter_role, $filter_search );

        $cycles = $this->get_cycle_options( $scope );
        $schools = $this->get_school_options( $scope, $filter_cycle );

        ?>
        <div class="hl-dashboard hl-learners hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Learners', 'hl-core' ); ?></h2>
            </div>

            <form class="hl-filters-bar" method="get" action="">
                <input type="text" name="q" class="hl-search-input"
                       value="<?php echo esc_attr( $filter_search ); ?>"
                       placeholder="<?php esc_attr_e( 'Search by name or email...', 'hl-core' ); ?>">
                <?php if ( count( $cycles ) > 1 ) : ?>
                    <select name="cycle_id" class="hl-select" onchange="this.form.submit();">
                        <option value=""><?php esc_html_e( 'All Cycles', 'hl-core' ); ?></option>
                        <?php foreach ( $cycles as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['cycle_id'] ); ?>"
                                <?php selected( (int) $c['cycle_id'], $filter_cycle ); ?>>
                                <?php echo esc_html( $c['cycle_name'] ); ?>
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
                                <th><?php esc_html_e( 'Cycle', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Completion', 'hl-core' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $data['rows'] as $r ) :
                                $roles_arr = json_decode( $r['roles'], true ) ?: array();
                                $roles_display = array_map( function( $rl ) {
                                    return ucfirst( str_replace( '_', ' ', $rl ) );
                                }, $roles_arr );
                                $roles_str = implode( ', ', $roles_display );
                                $pct = isset( $r['overall_percent'] ) ? round( (float) $r['overall_percent'] ) : 0;
                            ?>
                                <tr class="hl-learner-row"
                                    data-search="<?php echo esc_attr( strtolower( $r['display_name'] . ' ' . $r['user_email'] ) ); ?>"
                                    data-cycle="<?php echo esc_attr( $r['cycle_id'] ); ?>"
                                    data-school="<?php echo esc_attr( $r['school_id'] ); ?>"
                                    data-roles="<?php echo esc_attr( strtolower( implode( ',', $roles_arr ) ) ); ?>">
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
                                    <td><?php echo esc_html( $roles_str ?: '—' ); ?></td>
                                    <td><?php echo esc_html( $r['school_name'] ?: '—' ); ?></td>
                                    <td><?php echo esc_html( $r['cycle_name'] ?: '—' ); ?></td>
                                    <td>
                                        <div class="hl-inline-progress" style="width:110px;">
                                            <div class="hl-progress-inline">
                                                <div class="hl-progress-bar-container">
                                                    <div class="hl-progress-bar <?php echo $pct >= 100 ? 'hl-progress-complete' : 'hl-progress-active'; ?>" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
                                                </div>
                                            </div>
                                            <span class="hl-progress-text"><?php echo esc_html( $pct ); ?>%</span>
                                        </div>
                                    </td>
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
        if ( ! empty( $_GET['cycle_id'] ) )  $base_args['cycle_id']  = absint( $_GET['cycle_id'] );
        if ( ! empty( $_GET['school_id'] ) ) $base_args['school_id'] = absint( $_GET['school_id'] );
        if ( ! empty( $_GET['role'] ) )      $base_args['role']      = sanitize_text_field( $_GET['role'] );
        if ( ! empty( $_GET['q'] ) )         $base_args['q']         = sanitize_text_field( $_GET['q'] );

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

    private function get_learners( $scope, $mentor_only, $page, $filter_cycle = 0, $filter_school = 0, $filter_role = '', $filter_search = '' ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $offset = ( $page - 1 ) * self::PER_PAGE;

        $base_sql = "FROM {$prefix}hl_enrollment e
                     LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                     LEFT JOIN {$prefix}hl_orgunit ou ON e.school_id = ou.orgunit_id
                     LEFT JOIN {$prefix}hl_cycle tr ON e.cycle_id = tr.cycle_id
                     LEFT JOIN {$prefix}hl_completion_rollup cr
                         ON cr.enrollment_id = e.enrollment_id";

        $where  = array( "e.status = 'active'" );
        $values = array();

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
        if ( $filter_cycle ) {
            $where[]  = 'e.cycle_id = %d';
            $values[] = $filter_cycle;
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

        $where_clause = ' WHERE ' . implode( ' AND ', $where );

        // Total count.
        $count_sql = "SELECT COUNT(*) {$base_sql}{$where_clause}";
        if ( ! empty( $values ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $values );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Data page.
        $data_sql = "SELECT e.enrollment_id, e.cycle_id, e.school_id, e.roles,
                            e.user_id, u.display_name, u.user_email,
                            ou.name AS school_name, tr.cycle_name,
                            cr.cycle_completion_percent AS overall_percent
                     {$base_sql}{$where_clause}
                     ORDER BY u.display_name ASC
                     LIMIT %d OFFSET %d";
        $data_values = array_merge( $values, array( self::PER_PAGE, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_values ), ARRAY_A ) ?: array();

        return array( 'rows' => $rows, 'total' => $total, 'per_page' => self::PER_PAGE );
    }

    private function get_cycle_options( $scope ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        if ( $scope['is_admin'] ) {
            return $wpdb->get_results(
                "SELECT cycle_id, cycle_name FROM {$prefix}hl_cycle ORDER BY cycle_name ASC",
                ARRAY_A
            ) ?: array();
        }
        if ( empty( $scope['cycle_ids'] ) ) return array();
        $in = implode( ',', $scope['cycle_ids'] );
        return $wpdb->get_results(
            "SELECT cycle_id, cycle_name FROM {$prefix}hl_cycle WHERE cycle_id IN ({$in}) ORDER BY cycle_name ASC",
            ARRAY_A
        ) ?: array();
    }

    private function get_school_options( $scope, $filter_cycle = 0 ) {
        $repo = new HL_OrgUnit_Repository();

        // When a cycle is selected, only show schools with enrollments in that cycle.
        if ( $filter_cycle ) {
            global $wpdb;
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT e.school_id FROM {$wpdb->prefix}hl_enrollment e
                 WHERE e.cycle_id = %d AND e.status = 'active' AND e.school_id IS NOT NULL",
                $filter_cycle
            ) );
            if ( empty( $ids ) ) return array();
            $all = array();
            foreach ( $ids as $id ) {
                $ou = $repo->get_by_id( (int) $id );
                if ( $ou ) $all[] = $ou;
            }
            return HL_Scope_Service::filter_by_ids( $all, 'orgunit_id', $scope['school_ids'], $scope['is_admin'] );
        }

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
