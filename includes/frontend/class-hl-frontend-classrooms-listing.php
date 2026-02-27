<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_classrooms_listing] shortcode.
 *
 * Searchable classroom directory with school, age band filters.
 * Scope: admin all, coach assigned, leader by school, teacher own assignments.
 *
 * @package HL_Core
 */
class HL_Frontend_Classrooms_Listing {

    public function render( $atts ) {
        ob_start();

        $scope = HL_Scope_Service::get_scope();

        if ( ! $scope['is_staff'] && empty( $scope['enrollment_ids'] ) ) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'You do not have access to this page.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        $classrooms = $this->get_classrooms( $scope );
        $schools    = $this->get_school_options( $scope );
        $age_bands  = $this->get_age_bands();

        $classroom_page_url = $this->find_shortcode_page_url( 'hl_classroom_page' );

        ?>
        <div class="hl-dashboard hl-classrooms-listing hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Classrooms', 'hl-core' ); ?></h2>
            </div>

            <div class="hl-filters-bar">
                <input type="text" class="hl-search-input" id="hl-classroom-search"
                       placeholder="<?php esc_attr_e( 'Search classrooms...', 'hl-core' ); ?>">
                <?php if ( count( $schools ) > 1 ) : ?>
                    <select class="hl-select" id="hl-classroom-school-filter">
                        <option value=""><?php esc_html_e( 'All Schools', 'hl-core' ); ?></option>
                        <?php foreach ( $schools as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->orgunit_id ); ?>">
                                <?php echo esc_html( $c->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if ( ! empty( $age_bands ) ) : ?>
                    <select class="hl-select" id="hl-classroom-age-filter">
                        <option value=""><?php esc_html_e( 'All Age Bands', 'hl-core' ); ?></option>
                        <?php foreach ( $age_bands as $ab ) : ?>
                            <option value="<?php echo esc_attr( $ab ); ?>">
                                <?php echo esc_html( ucfirst( $ab ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <?php if ( empty( $classrooms ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No classrooms found.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-table-container">
                    <table class="hl-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Classroom', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'School', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Age Band', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Children', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Teachers', 'hl-core' ); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $classrooms as $cr ) :
                                $detail_url = $classroom_page_url
                                    ? add_query_arg( 'id', $cr['classroom_id'], $classroom_page_url )
                                    : '';
                            ?>
                                <tr class="hl-classroom-row"
                                    data-search="<?php echo esc_attr( strtolower(
                                        $cr['classroom_name'] . ' ' . $cr['school_name'] . ' ' . ( $cr['teacher_names'] ?: '' )
                                    ) ); ?>"
                                    data-school="<?php echo esc_attr( $cr['school_id'] ); ?>"
                                    data-age="<?php echo esc_attr( $cr['age_band'] ); ?>">
                                    <td>
                                        <?php if ( $detail_url ) : ?>
                                            <a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $cr['classroom_name'] ); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html( $cr['classroom_name'] ); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $cr['school_name'] ); ?></td>
                                    <td>
                                        <?php if ( $cr['age_band'] ) : ?>
                                            <span class="hl-badge"><?php echo esc_html( ucfirst( $cr['age_band'] ) ); ?></span>
                                        <?php else : ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $cr['child_count'] ); ?></td>
                                    <td><?php echo esc_html( $cr['teacher_names'] ?: '—' ); ?></td>
                                    <td>
                                        <?php if ( $detail_url ) : ?>
                                            <a href="<?php echo esc_url( $detail_url ); ?>" class="hl-btn hl-btn-sm hl-btn-secondary">
                                                <?php esc_html_e( 'View', 'hl-core' ); ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="hl-empty-state hl-no-results">
                    <p><?php esc_html_e( 'No classrooms match your filters.', 'hl-core' ); ?></p>
                </div>
            <?php endif; ?>

        </div>

        <script>
        (function($){
            var $rows = $('.hl-classroom-row');
            var $noResults = $('.hl-classrooms-listing .hl-no-results');

            function filterRows() {
                var query  = $('#hl-classroom-search').val().toLowerCase();
                var school = $('#hl-classroom-school-filter').val();
                var age    = $('#hl-classroom-age-filter').val();
                var visible = 0;

                $rows.each(function(){
                    var $r = $(this);
                    var matchSearch = !query || $r.data('search').indexOf(query) !== -1;
                    var matchSchool = !school || String($r.data('school')) === school;
                    var matchAge    = !age || $r.data('age') === age;
                    var show = matchSearch && matchSchool && matchAge;
                    $r.toggle(show);
                    if (show) visible++;
                });
                $noResults.toggle(visible === 0 && $rows.length > 0);
            }

            $('#hl-classroom-search').on('input', filterRows);
            $('#hl-classroom-school-filter, #hl-classroom-age-filter').on('change', filterRows);
        })(jQuery);
        </script>
        <?php

        return ob_get_clean();
    }

    // =========================================================================
    // Data helpers
    // =========================================================================

    private function get_classrooms( $scope ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $sql = "SELECT c.classroom_id, c.classroom_name, c.age_band, c.school_id,
                       ou.name AS school_name,
                       COALESCE(cc.child_count, 0) AS child_count
                FROM {$prefix}hl_classroom c
                LEFT JOIN {$prefix}hl_orgunit ou ON c.school_id = ou.orgunit_id
                LEFT JOIN (
                    SELECT classroom_id, COUNT(*) AS child_count
                    FROM {$prefix}hl_child_classroom_current
                    GROUP BY classroom_id
                ) cc ON c.classroom_id = cc.classroom_id";

        $where  = array();
        $values = array();

        if ( ! $scope['is_admin'] ) {
            // Leaders, staff, coaches, mentors: see all classrooms at their schools.
            // Teachers: only classrooms where they have a teaching assignment.
            $broad_roles    = array( 'school_leader', 'district_leader', 'mentor' );
            $has_broad_role = $scope['is_staff'] || array_intersect( $scope['hl_roles'], $broad_roles );

            if ( $has_broad_role && ! empty( $scope['school_ids'] ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $scope['school_ids'] ), '%d' ) );
                $where[]      = "c.school_id IN ({$placeholders})";
                $values       = array_merge( $values, $scope['school_ids'] );
            } else {
                // Teacher: only classrooms they're assigned to.
                $where[]  = "c.classroom_id IN (
                    SELECT ta.classroom_id FROM {$prefix}hl_teaching_assignment ta
                    JOIN {$prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
                    WHERE e.user_id = %d AND e.status = 'active'
                )";
                $values[] = $scope['user_id'];
            }
        }

        if ( ! empty( $where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        $sql .= ' ORDER BY ou.name ASC, c.classroom_name ASC';

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, $values );
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A ) ?: array();

        // Attach teacher names.
        $teacher_map = $this->get_teacher_names_by_classroom();
        foreach ( $rows as &$row ) {
            $cid = (int) $row['classroom_id'];
            $row['teacher_names'] = isset( $teacher_map[ $cid ] ) ? implode( ', ', $teacher_map[ $cid ] ) : '';
        }

        return $rows;
    }

    private function get_teacher_names_by_classroom() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $results = $wpdb->get_results(
            "SELECT ta.classroom_id, u.display_name
             FROM {$prefix}hl_teaching_assignment ta
             LEFT JOIN {$prefix}hl_enrollment e ON ta.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             ORDER BY u.display_name ASC",
            ARRAY_A
        );
        $map = array();
        foreach ( $results ?: array() as $row ) {
            $cid = (int) $row['classroom_id'];
            if ( ! isset( $map[ $cid ] ) ) {
                $map[ $cid ] = array();
            }
            if ( $row['display_name'] ) {
                $map[ $cid ][] = $row['display_name'];
            }
        }
        return $map;
    }

    private function get_school_options( $scope ) {
        $repo = new HL_OrgUnit_Repository();
        $all  = $repo->get_schools();
        return HL_Scope_Service::filter_by_ids( $all, 'orgunit_id', $scope['school_ids'], $scope['is_admin'] );
    }

    private function get_age_bands() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT age_band FROM {$wpdb->prefix}hl_classroom
             WHERE age_band IS NOT NULL AND age_band != ''
             ORDER BY age_band ASC"
        ) ?: array();
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
