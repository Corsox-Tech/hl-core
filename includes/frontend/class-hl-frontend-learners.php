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

        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $data = $this->get_learners( $scope, $mentor_only, $page );

        $cohorts = $this->get_cohort_options( $scope );
        $schools = $this->get_school_options( $scope );

        ?>
        <div class="hl-dashboard hl-learners hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Learners', 'hl-core' ); ?></h2>
            </div>

            <div class="hl-filters-bar">
                <input type="text" class="hl-search-input" id="hl-learner-search"
                       placeholder="<?php esc_attr_e( 'Search by name or email...', 'hl-core' ); ?>">
                <?php if ( count( $cohorts ) > 1 ) : ?>
                    <select class="hl-select" id="hl-learner-cohort-filter">
                        <option value=""><?php esc_html_e( 'All Cohorts', 'hl-core' ); ?></option>
                        <?php foreach ( $cohorts as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['cohort_id'] ); ?>">
                                <?php echo esc_html( $c['cohort_name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if ( count( $schools ) > 1 ) : ?>
                    <select class="hl-select" id="hl-learner-school-filter">
                        <option value=""><?php esc_html_e( 'All Schools', 'hl-core' ); ?></option>
                        <?php foreach ( $schools as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->orgunit_id ); ?>">
                                <?php echo esc_html( $c->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <select class="hl-select" id="hl-learner-role-filter">
                    <option value=""><?php esc_html_e( 'All Roles', 'hl-core' ); ?></option>
                    <option value="teacher"><?php esc_html_e( 'Teacher', 'hl-core' ); ?></option>
                    <option value="mentor"><?php esc_html_e( 'Mentor', 'hl-core' ); ?></option>
                    <option value="school_leader"><?php esc_html_e( 'School Leader', 'hl-core' ); ?></option>
                    <option value="district_leader"><?php esc_html_e( 'District Leader', 'hl-core' ); ?></option>
                </select>
            </div>

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
                                <th><?php esc_html_e( 'Cohort', 'hl-core' ); ?></th>
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
                                    data-cohort="<?php echo esc_attr( $r['cohort_id'] ); ?>"
                                    data-school="<?php echo esc_attr( $r['school_id'] ); ?>"
                                    data-roles="<?php echo esc_attr( strtolower( implode( ',', $roles_arr ) ) ); ?>">
                                    <td>
                                        <?php
                                        $profile_url = function_exists( 'bp_core_get_user_domain' )
                                            ? bp_core_get_user_domain( $r['user_id'] )
                                            : '';
                                        if ( $profile_url ) : ?>
                                            <a href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $r['display_name'] ); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html( $r['display_name'] ); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $r['user_email'] ); ?></td>
                                    <td><?php echo esc_html( $roles_str ?: '—' ); ?></td>
                                    <td><?php echo esc_html( $r['school_name'] ?: '—' ); ?></td>
                                    <td><?php echo esc_html( $r['cohort_name'] ?: '—' ); ?></td>
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

                <div class="hl-empty-state hl-no-results">
                    <p><?php esc_html_e( 'No learners match your filters.', 'hl-core' ); ?></p>
                </div>
            <?php endif; ?>

        </div>

        <script>
        (function($){
            var $rows = $('.hl-learner-row');
            var $noResults = $('.hl-learners .hl-no-results');

            function filterRows() {
                var query  = $('#hl-learner-search').val().toLowerCase();
                var cohort = $('#hl-learner-cohort-filter').val();
                var school = $('#hl-learner-school-filter').val();
                var role   = $('#hl-learner-role-filter').val();
                var visible = 0;

                $rows.each(function(){
                    var $r = $(this);
                    var matchSearch = !query || $r.data('search').indexOf(query) !== -1;
                    var matchCohort = !cohort || String($r.data('cohort')) === cohort;
                    var matchSchool = !school || String($r.data('school')) === school;
                    var matchRole   = !role || ($r.data('roles') && $r.data('roles').indexOf(role) !== -1);
                    var show = matchSearch && matchCohort && matchSchool && matchRole;
                    $r.toggle(show);
                    if (show) visible++;
                });
                $noResults.toggle(visible === 0 && $rows.length > 0);
            }

            $('#hl-learner-search').on('input', filterRows);
            $('#hl-learner-cohort-filter, #hl-learner-school-filter, #hl-learner-role-filter').on('change', filterRows);
        })(jQuery);
        </script>
        <?php

        return ob_get_clean();
    }

    // =========================================================================
    // Pagination
    // =========================================================================

    private function render_pagination( $current_page, $total, $per_page ) {
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        if ( $total_pages <= 1 ) return;

        echo '<div class="hl-pagination">';
        for ( $p = 1; $p <= $total_pages; $p++ ) {
            $url   = add_query_arg( 'paged', $p );
            $class = $p === $current_page ? 'hl-btn hl-btn-sm hl-btn-primary' : 'hl-btn hl-btn-sm hl-btn-secondary';
            printf( '<a href="%s" class="%s">%d</a>', esc_url( $url ), esc_attr( $class ), $p );
        }
        echo '</div>';
    }

    // =========================================================================
    // Data helpers
    // =========================================================================

    private function get_learners( $scope, $mentor_only, $page ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $offset = ( $page - 1 ) * self::PER_PAGE;

        $base_sql = "FROM {$prefix}hl_enrollment e
                     LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                     LEFT JOIN {$prefix}hl_orgunit ou ON e.school_id = ou.orgunit_id
                     LEFT JOIN {$prefix}hl_cohort co ON e.cohort_id = co.cohort_id
                     LEFT JOIN {$prefix}hl_completion_rollup cr
                         ON cr.enrollment_id = e.enrollment_id AND cr.pathway_id IS NULL";

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
            } elseif ( ! empty( $scope['cohort_ids'] ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $scope['cohort_ids'] ), '%d' ) );
                $where[]      = "e.cohort_id IN ({$placeholders})";
                $values       = array_merge( $values, $scope['cohort_ids'] );
            } else {
                return array( 'rows' => array(), 'total' => 0, 'per_page' => self::PER_PAGE );
            }
        }

        $where_clause = ' WHERE ' . implode( ' AND ', $where );

        // Total count.
        $count_sql = "SELECT COUNT(*) {$base_sql}{$where_clause}";
        if ( ! empty( $values ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $values );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Data page.
        $data_sql = "SELECT e.enrollment_id, e.cohort_id, e.school_id, e.roles,
                            e.user_id, u.display_name, u.user_email,
                            ou.name AS school_name, co.cohort_name,
                            cr.overall_percent
                     {$base_sql}{$where_clause}
                     ORDER BY u.display_name ASC
                     LIMIT %d OFFSET %d";
        $data_values = array_merge( $values, array( self::PER_PAGE, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_values ), ARRAY_A ) ?: array();

        return array( 'rows' => $rows, 'total' => $total, 'per_page' => self::PER_PAGE );
    }

    private function get_cohort_options( $scope ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        if ( $scope['is_admin'] ) {
            return $wpdb->get_results(
                "SELECT cohort_id, cohort_name FROM {$prefix}hl_cohort ORDER BY cohort_name ASC",
                ARRAY_A
            ) ?: array();
        }
        if ( empty( $scope['cohort_ids'] ) ) return array();
        $in = implode( ',', $scope['cohort_ids'] );
        return $wpdb->get_results(
            "SELECT cohort_id, cohort_name FROM {$prefix}hl_cohort WHERE cohort_id IN ({$in}) ORDER BY cohort_name ASC",
            ARRAY_A
        ) ?: array();
    }

    private function get_school_options( $scope ) {
        $repo = new HL_OrgUnit_Repository();
        $all  = $repo->get_schools();
        return HL_Scope_Service::filter_by_ids( $all, 'orgunit_id', $scope['school_ids'], $scope['is_admin'] );
    }
}
