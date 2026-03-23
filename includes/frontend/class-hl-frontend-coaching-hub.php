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
        $cycles  = $this->get_cycle_options( $scope );

        $my_coaching_url = $this->find_shortcode_page_url( 'hl_my_coaching' );

        ?>
        <div class="hl-dashboard hl-coaching-hub hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Coaching Hub', 'hl-core' ); ?></h2>
                <?php if ( $my_coaching_url && ! $scope['is_staff'] ) : ?>
                    <a href="<?php echo esc_url( $my_coaching_url ); ?>" class="hl-btn hl-btn-primary hl-btn-sm">
                        <?php esc_html_e( 'My Coaching Sessions', 'hl-core' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php $this->render_coaches_section(); ?>

            <!-- View toggle -->
            <div class="hl-view-toggle">
                <button type="button" class="hl-btn hl-btn-sm hl-btn-primary" id="hl-view-table-btn"><?php esc_html_e( 'Table', 'hl-core' ); ?></button>
                <button type="button" class="hl-btn hl-btn-sm hl-btn-secondary" id="hl-view-calendar-btn"><?php esc_html_e( 'Calendar', 'hl-core' ); ?></button>
            </div>

            <!-- Filters -->
            <div class="hl-filters-bar">
                <input type="text" class="hl-search-input" id="hl-coaching-search"
                       placeholder="<?php esc_attr_e( 'Search by participant, coach, or title...', 'hl-core' ); ?>">
                <select class="hl-select" id="hl-coaching-status-filter">
                    <option value=""><?php esc_html_e( 'All Statuses', 'hl-core' ); ?></option>
                    <option value="scheduled"><?php esc_html_e( 'Scheduled', 'hl-core' ); ?></option>
                    <option value="attended"><?php esc_html_e( 'Attended', 'hl-core' ); ?></option>
                    <option value="missed"><?php esc_html_e( 'Missed', 'hl-core' ); ?></option>
                    <option value="cancelled"><?php esc_html_e( 'Cancelled', 'hl-core' ); ?></option>
                    <option value="rescheduled"><?php esc_html_e( 'Rescheduled', 'hl-core' ); ?></option>
                </select>
                <?php if ( count( $cycles ) > 1 ) : ?>
                    <select class="hl-select" id="hl-coaching-track-filter">
                        <option value=""><?php esc_html_e( 'All Tracks', 'hl-core' ); ?></option>
                        <?php foreach ( $cycles as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['cycle_id'] ); ?>">
                                <?php echo esc_html( $c['cycle_name'] ); ?>
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
                                    data-cycle="<?php echo esc_attr( $s['cycle_id'] ); ?>">
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

                <?php $this->render_calendar_view( $sessions ); ?>

                <div class="hl-empty-state hl-no-results">
                    <p><?php esc_html_e( 'No sessions match your filters.', 'hl-core' ); ?></p>
                </div>
            <?php endif; ?>

        </div>

        <style>
        .hl-coaches-section { margin-bottom: 24px; }
        .hl-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
        .hl-coach-card { display: flex; align-items: center; gap: 12px; padding: 16px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; }
        .hl-coach-card .hl-coach-info { display: flex; flex-direction: column; gap: 2px; }
        .hl-coach-card .hl-coach-info span { font-size: 13px; color: #666; }
        .hl-view-toggle { display: flex; gap: 4px; margin-bottom: 16px; }
        .hl-hub-calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
        .hl-hub-calendar-view .hl-calendar-header { margin-bottom: 8px; font-weight: 600; }
        .hl-hub-calendar-view .hl-calendar-dow { padding: 4px; text-align: center; font-weight: 600; font-size: 12px; color: #666; }
        .hl-hub-calendar-view .hl-calendar-day { padding: 8px; min-height: 60px; border: 1px solid #eee; border-radius: 4px; font-size: 14px; }
        .hl-hub-calendar-view .hl-calendar-today { background: #f0f7ff; }
        .hl-hub-calendar-view .hl-calendar-empty { border: none; }
        .hl-cal-day-num { display: block; font-weight: 600; margin-bottom: 4px; }
        .hl-cal-dots { display: flex; gap: 3px; flex-wrap: wrap; }
        .hl-cal-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        </style>

        <script>
        (function($){
            var $rows = $('.hl-coaching-row');
            var $noResults = $('.hl-coaching-hub .hl-no-results');

            function filterRows() {
                var query  = $('#hl-coaching-search').val().toLowerCase();
                var status = $('#hl-coaching-status-filter').val();
                var track = $('#hl-coaching-track-filter').val();
                var visible = 0;

                $rows.each(function(){
                    var $r = $(this);
                    var matchSearch = !query || $r.data('search').indexOf(query) !== -1;
                    var matchStatus = !status || $r.data('status') === status;
                    var matchTrack = !track || String($r.data('cycle')) === track;
                    var show = matchSearch && matchStatus && matchTrack;
                    $r.toggle(show);
                    if (show) visible++;
                });
                $noResults.toggle(visible === 0 && $rows.length > 0);
            }

            $('#hl-coaching-search').on('input', filterRows);
            $('#hl-coaching-status-filter, #hl-coaching-track-filter').on('change', filterRows);

            // View toggle: table vs calendar.
            var $tableView = $('.hl-table-container');
            var $calView = $('.hl-hub-calendar-view');
            var $tableBtn = $('#hl-view-table-btn');
            var $calBtn = $('#hl-view-calendar-btn');

            $tableBtn.on('click', function() {
                $tableView.show();
                $calView.hide();
                $tableBtn.removeClass('hl-btn-secondary').addClass('hl-btn-primary');
                $calBtn.removeClass('hl-btn-primary').addClass('hl-btn-secondary');
            });

            $calBtn.on('click', function() {
                $tableView.hide();
                $calView.show();
                $calBtn.removeClass('hl-btn-secondary').addClass('hl-btn-primary');
                $tableBtn.removeClass('hl-btn-primary').addClass('hl-btn-secondary');
            });
        })(jQuery);
        </script>
        <?php

        return ob_get_clean();
    }

    // =========================================================================
    // Sub-renders
    // =========================================================================

    /**
     * Render coaches card grid.
     */
    private function render_coaches_section() {
        $coaches = get_users( array( 'role' => 'coach' ) );
        if ( empty( $coaches ) ) {
            return;
        }
        ?>
        <div class="hl-section hl-coaches-section">
            <h3><?php esc_html_e( 'Coaches', 'hl-core' ); ?></h3>
            <div class="hl-card-grid">
                <?php foreach ( $coaches as $coach ) : ?>
                    <div class="hl-card hl-coach-card">
                        <div class="hl-coach-avatar"><?php echo get_avatar( $coach->ID, 64 ); ?></div>
                        <div class="hl-coach-info">
                            <strong><?php echo esc_html( $coach->display_name ); ?></strong>
                            <span><?php echo esc_html( $coach->user_email ); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a calendar month view with session dots.
     */
    private function render_calendar_view( $sessions ) {
        $month = (int) date( 'n' );
        $year  = (int) date( 'Y' );
        $days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
        $first_day     = (int) date( 'w', mktime( 0, 0, 0, $month, 1, $year ) );
        $today         = date( 'Y-m-d' );

        // Group sessions by date.
        $by_date = array();
        foreach ( $sessions as $s ) {
            $date = ! empty( $s['session_datetime'] ) ? substr( $s['session_datetime'], 0, 10 ) : '';
            if ( $date ) {
                $by_date[ $date ][] = $s;
            }
        }

        $status_colors = array(
            'attended'    => '#4caf50',
            'scheduled'   => '#2196f3',
            'missed'      => '#f44336',
            'cancelled'   => '#9e9e9e',
            'rescheduled' => '#ff9800',
        );

        ?>
        <div class="hl-hub-calendar-view" style="display:none;">
            <div class="hl-calendar-header">
                <span class="hl-calendar-title"><?php echo esc_html( date( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) ) ); ?></span>
            </div>
            <div class="hl-calendar-grid hl-hub-calendar-grid">
                <div class="hl-calendar-dow">Sun</div>
                <div class="hl-calendar-dow">Mon</div>
                <div class="hl-calendar-dow">Tue</div>
                <div class="hl-calendar-dow">Wed</div>
                <div class="hl-calendar-dow">Thu</div>
                <div class="hl-calendar-dow">Fri</div>
                <div class="hl-calendar-dow">Sat</div>
                <?php for ( $i = 0; $i < $first_day; $i++ ) : ?>
                    <div class="hl-calendar-day hl-calendar-empty"></div>
                <?php endfor; ?>
                <?php for ( $d = 1; $d <= $days_in_month; $d++ ) :
                    $date_val = sprintf( '%04d-%02d-%02d', $year, $month, $d );
                    $day_sessions = isset( $by_date[ $date_val ] ) ? $by_date[ $date_val ] : array();
                    $classes = 'hl-calendar-day';
                    if ( $date_val === $today ) {
                        $classes .= ' hl-calendar-today';
                    }
                ?>
                    <div class="<?php echo esc_attr( $classes ); ?>">
                        <span class="hl-cal-day-num"><?php echo $d; ?></span>
                        <?php if ( ! empty( $day_sessions ) ) : ?>
                            <div class="hl-cal-dots">
                                <?php foreach ( $day_sessions as $ds ) :
                                    $s_status = $ds['session_status'] ?: 'scheduled';
                                    $color = isset( $status_colors[ $s_status ] ) ? $status_colors[ $s_status ] : '#999';
                                ?>
                                    <span class="hl-cal-dot" style="background:<?php echo esc_attr( $color ); ?>;" title="<?php echo esc_attr( $ds['session_title'] ?: __( 'Session', 'hl-core' ) ); ?>"></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Data helpers
    // =========================================================================

    private function get_sessions( $scope ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $sql = "SELECT cs.coaching_session_id, cs.cycle_id, cs.session_title,
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
                if ( ! empty( $scope['cycle_ids'] ) ) {
                    $placeholders = implode( ',', array_fill( 0, count( $scope['cycle_ids'] ), '%d' ) );
                    $where[]      = "cs.cycle_id IN ({$placeholders})";
                    $values       = array_merge( $values, $scope['cycle_ids'] );
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
