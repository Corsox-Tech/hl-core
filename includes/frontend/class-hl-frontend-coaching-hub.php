<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_coaching_hub] shortcode.
 *
 * Front-end coaching session management with search, filters, scope filtering.
 *
 * @package HL_Core
 */
class HL_Frontend_Coaching_Hub {

    public function render( $atts ) {
        ob_start();

        $scope = HL_Scope_Service::get_scope();

        if ( ! $scope['is_staff'] && empty( $scope['enrollment_ids'] ) ) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'You do not have access to this page.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        $sessions = $this->get_sessions( $scope );
        $cohorts  = $this->get_cohort_options( $scope );

        $my_coaching_url = $this->find_shortcode_page_url( 'hl_my_coaching' );

        ?>
        <div class="hl-dashboard hl-coaching-hub hl-frontend-wrap">

            <div class="hl-crm-page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Coaching Hub', 'hl-core' ); ?></h2>
                <?php if ( $my_coaching_url && ! $scope['is_staff'] ) : ?>
                    <a href="<?php echo esc_url( $my_coaching_url ); ?>" class="hl-btn hl-btn-primary hl-btn-sm">
                        <?php esc_html_e( 'My Coaching Sessions', 'hl-core' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="hl-filters-bar" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:20px;">
                <input type="text" class="hl-search-input" id="hl-coaching-search"
                       placeholder="<?php esc_attr_e( 'Search by participant, coach, or title...', 'hl-core' ); ?>"
                       style="flex:1; min-width:200px;">
                <select class="hl-select" id="hl-coaching-status-filter">
                    <option value=""><?php esc_html_e( 'All Statuses', 'hl-core' ); ?></option>
                    <option value="scheduled"><?php esc_html_e( 'Scheduled', 'hl-core' ); ?></option>
                    <option value="attended"><?php esc_html_e( 'Attended', 'hl-core' ); ?></option>
                    <option value="missed"><?php esc_html_e( 'Missed', 'hl-core' ); ?></option>
                    <option value="cancelled"><?php esc_html_e( 'Cancelled', 'hl-core' ); ?></option>
                    <option value="rescheduled"><?php esc_html_e( 'Rescheduled', 'hl-core' ); ?></option>
                </select>
                <?php if ( count( $cohorts ) > 1 ) : ?>
                    <select class="hl-select" id="hl-coaching-cohort-filter">
                        <option value=""><?php esc_html_e( 'All Cohorts', 'hl-core' ); ?></option>
                        <?php foreach ( $cohorts as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['cohort_id'] ); ?>">
                                <?php echo esc_html( $c['cohort_name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <?php if ( empty( $sessions ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No coaching sessions found.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-table-container">
                    <table class="hl-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Title', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Participant', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Coach', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Date/Time', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'hl-core' ); ?></th>
                                <th><?php esc_html_e( 'Meeting', 'hl-core' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $sessions as $s ) :
                                $status   = $s['session_status'] ?: 'scheduled';
                                $datetime = $s['session_datetime']
                                    ? date_i18n( 'M j, Y g:i A', strtotime( $s['session_datetime'] ) )
                                    : '—';
                            ?>
                                <tr class="hl-coaching-row"
                                    data-search="<?php echo esc_attr( strtolower(
                                        ( $s['session_title'] ?: '' ) . ' ' .
                                        ( $s['participant_name'] ?: '' ) . ' ' .
                                        ( $s['coach_name'] ?: '' )
                                    ) ); ?>"
                                    data-status="<?php echo esc_attr( $status ); ?>"
                                    data-cohort="<?php echo esc_attr( $s['cohort_id'] ); ?>">
                                    <td><?php echo esc_html( $s['session_title'] ?: __( 'Coaching Session', 'hl-core' ) ); ?></td>
                                    <td><?php echo esc_html( $s['participant_name'] ?: '—' ); ?></td>
                                    <td><?php echo esc_html( $s['coach_name'] ?: '—' ); ?></td>
                                    <td><?php echo esc_html( $datetime ); ?></td>
                                    <td><?php echo wp_kses_post( HL_Coaching_Service::render_status_badge( $status ) ); ?></td>
                                    <td>
                                        <?php if ( ! empty( $s['meeting_url'] ) ) : ?>
                                            <a href="<?php echo esc_url( $s['meeting_url'] ); ?>" target="_blank" class="hl-btn hl-btn-sm hl-btn-secondary">
                                                <?php esc_html_e( 'Join', 'hl-core' ); ?>
                                            </a>
                                        <?php else : ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="hl-empty-state hl-no-results" style="display:none;">
                    <p><?php esc_html_e( 'No sessions match your filters.', 'hl-core' ); ?></p>
                </div>
            <?php endif; ?>

        </div>

        <script>
        (function($){
            var $rows = $('.hl-coaching-row');
            var $noResults = $('.hl-coaching-hub .hl-no-results');

            function filterRows() {
                var query  = $('#hl-coaching-search').val().toLowerCase();
                var status = $('#hl-coaching-status-filter').val();
                var cohort = $('#hl-coaching-cohort-filter').val();
                var visible = 0;

                $rows.each(function(){
                    var $r = $(this);
                    var matchSearch = !query || $r.data('search').indexOf(query) !== -1;
                    var matchStatus = !status || $r.data('status') === status;
                    var matchCohort = !cohort || String($r.data('cohort')) === cohort;
                    var show = matchSearch && matchStatus && matchCohort;
                    $r.toggle(show);
                    if (show) visible++;
                });
                $noResults.toggle(visible === 0 && $rows.length > 0);
            }

            $('#hl-coaching-search').on('input', filterRows);
            $('#hl-coaching-status-filter, #hl-coaching-cohort-filter').on('change', filterRows);
        })(jQuery);
        </script>
        <?php

        return ob_get_clean();
    }

    // =========================================================================
    // Data helpers
    // =========================================================================

    private function get_sessions( $scope ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $sql = "SELECT cs.coaching_session_id, cs.cohort_id, cs.session_title,
                       cs.session_datetime, cs.session_status, cs.meeting_url,
                       cs.coach_user_id, cs.mentor_enrollment_id,
                       coach.display_name AS coach_name,
                       participant.display_name AS participant_name
                FROM {$prefix}hl_coaching_session cs
                LEFT JOIN {$wpdb->users} coach ON cs.coach_user_id = coach.ID
                LEFT JOIN {$prefix}hl_enrollment e ON cs.mentor_enrollment_id = e.enrollment_id
                LEFT JOIN {$wpdb->users} participant ON e.user_id = participant.ID";

        $where  = array();
        $values = array();

        if ( ! $scope['is_admin'] ) {
            if ( $scope['is_coach'] ) {
                if ( ! empty( $scope['cohort_ids'] ) ) {
                    $placeholders = implode( ',', array_fill( 0, count( $scope['cohort_ids'] ), '%d' ) );
                    $where[]      = "cs.cohort_id IN ({$placeholders})";
                    $values       = array_merge( $values, $scope['cohort_ids'] );
                } else {
                    $where[]  = 'cs.coach_user_id = %d';
                    $values[] = $scope['user_id'];
                }
            } else {
                if ( ! empty( $scope['enrollment_ids'] ) ) {
                    $placeholders = implode( ',', array_fill( 0, count( $scope['enrollment_ids'] ), '%d' ) );
                    $where[]      = "cs.mentor_enrollment_id IN ({$placeholders})";
                    $values       = array_merge( $values, $scope['enrollment_ids'] );
                } else {
                    return array();
                }
            }
        }

        if ( ! empty( $where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        $sql .= ' ORDER BY cs.session_datetime DESC LIMIT 200';

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, $values );
        }

        return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
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
