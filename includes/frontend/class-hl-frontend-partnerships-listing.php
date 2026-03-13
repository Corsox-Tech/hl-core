<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_tracks_listing] shortcode.
 *
 * Card grid of tracks with search and status filter.
 * Scope: admin sees all, coach sees assigned tracks, leaders see enrolled tracks.
 *
 * @package HL_Core
 */
class HL_Frontend_Tracks_Listing {

    public function render( $atts ) {
        ob_start();

        if ( ! is_user_logged_in() ) {
            echo '<div class="hl-notice hl-notice-warning">'
                . esc_html__( 'Please log in to view this page.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        $scope = HL_Scope_Service::get_scope();

        // Must be staff or have at least one enrollment.
        if ( ! $scope['is_staff'] && empty( $scope['enrollment_ids'] ) ) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'You do not have access to this page.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        $track_repo = new HL_Track_Repository();
        $all_tracks = $track_repo->get_all();

        // Scope filter.
        $tracks = HL_Scope_Service::filter_by_ids(
            $all_tracks,
            'track_id',
            $scope['track_ids'],
            $scope['is_admin']
        );

        // Pre-compute counts.
        $participant_counts = $this->get_participant_counts();
        $school_counts      = $this->get_school_counts();

        // Cohort container options for filter.
        $cohort_options = $this->get_cohorts();

        $workspace_url = $this->find_shortcode_page_url( 'hl_track_workspace' );

        ?>
        <div class="hl-dashboard hl-tracks-listing hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Tracks', 'hl-core' ); ?></h2>
            </div>

            <!-- Search + Status + Group Filters -->
            <div class="hl-filters-bar">
                <input type="text" class="hl-search-input" id="hl-track-search"
                       placeholder="<?php esc_attr_e( 'Search tracks...', 'hl-core' ); ?>">
                <?php if ( ! empty( $cohort_options ) ) : ?>
                    <select id="hl-cohort-filter" class="hl-select">
                        <option value=""><?php esc_html_e( 'All Cohorts', 'hl-core' ); ?></option>
                        <?php foreach ( $cohort_options as $gid => $gname ) : ?>
                            <option value="<?php echo esc_attr( $gid ); ?>"><?php echo esc_html( $gname ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <label class="hl-filter-checkbox">
                    <input type="checkbox" class="hl-status-filter" value="active" checked> <?php esc_html_e( 'Active', 'hl-core' ); ?>
                </label>
                <label class="hl-filter-checkbox">
                    <input type="checkbox" class="hl-status-filter" value="future" checked> <?php esc_html_e( 'Future', 'hl-core' ); ?>
                </label>
                <label class="hl-filter-checkbox">
                    <input type="checkbox" class="hl-status-filter" value="paused"> <?php esc_html_e( 'Paused', 'hl-core' ); ?>
                </label>
                <label class="hl-filter-checkbox">
                    <input type="checkbox" class="hl-status-filter" value="archived"> <?php esc_html_e( 'Archived', 'hl-core' ); ?>
                </label>
            </div>

            <?php if ( empty( $tracks ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No tracks found.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-crm-card-grid">
                    <?php foreach ( $tracks as $track ) :
                        $cid           = (int) $track->track_id;
                        $status        = $track->status ?: 'active';
                        $num_participants = isset( $participant_counts[ $cid ] ) ? $participant_counts[ $cid ] : 0;
                        $num_schools   = isset( $school_counts[ $cid ] ) ? $school_counts[ $cid ] : 0;
                        $detail_url    = $workspace_url
                            ? add_query_arg( 'id', $cid, $workspace_url )
                            : '';

                        $start = $track->start_date ? date_i18n( 'M j, Y', strtotime( $track->start_date ) ) : '—';
                        $end   = $track->end_date   ? date_i18n( 'M j, Y', strtotime( $track->end_date ) )   : '—';
                    ?>
                        <div class="hl-crm-card hl-track-card"
                             data-status="<?php echo esc_attr( $status ); ?>"
                             data-name="<?php echo esc_attr( strtolower( $track->track_name . ' ' . $track->track_code ) ); ?>"
                             data-group="<?php echo esc_attr( $track->cohort_id ?: '' ); ?>">
                            <div class="hl-crm-card-body">
                                <div class="hl-crm-card-header">
                                    <h3 class="hl-crm-card-title">
                                        <?php if ( $detail_url ) : ?>
                                            <a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $track->track_name ); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html( $track->track_name ); ?>
                                        <?php endif; ?>
                                    </h3>
                                    <span class="hl-badge hl-badge-<?php echo esc_attr( $status ); ?>">
                                        <?php echo esc_html( ucfirst( $status ) ); ?>
                                    </span>
                                </div>
                                <?php if ( $track->track_code ) : ?>
                                    <div class="hl-crm-card-code">
                                        <?php echo esc_html( $track->track_code ); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="hl-crm-card-dates">
                                    <?php echo esc_html( $start ); ?> &mdash; <?php echo esc_html( $end ); ?>
                                </div>
                                <div class="hl-crm-card-meta">
                                    <span class="hl-crm-card-stat">
                                        <strong><?php echo esc_html( $num_participants ); ?></strong>
                                        <?php echo esc_html( _n( 'Participant', 'Participants', $num_participants, 'hl-core' ) ); ?>
                                    </span>
                                    <span class="hl-crm-card-stat">
                                        <strong><?php echo esc_html( $num_schools ); ?></strong>
                                        <?php echo esc_html( _n( 'School', 'Schools', $num_schools, 'hl-core' ) ); ?>
                                    </span>
                                </div>
                            </div>
                            <?php if ( $detail_url ) : ?>
                                <div class="hl-crm-card-action">
                                    <a href="<?php echo esc_url( $detail_url ); ?>" class="hl-btn hl-btn-sm hl-btn-secondary">
                                        <?php esc_html_e( 'Open Track', 'hl-core' ); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="hl-empty-state hl-no-results">
                    <p><?php esc_html_e( 'No tracks match your search or filters.', 'hl-core' ); ?></p>
                </div>
            <?php endif; ?>

        </div>

        <script>
        (function($){
            var $cards = $('.hl-track-card');
            var $noResults = $('.hl-tracks-listing .hl-no-results');

            function filterCards() {
                var query = $('#hl-track-search').val().toLowerCase();
                var groupFilter = $('#hl-cohort-filter').val() || '';
                var statuses = [];
                $('.hl-status-filter:checked').each(function(){ statuses.push($(this).val()); });

                var visible = 0;
                $cards.each(function(){
                    var $c = $(this);
                    var matchSearch = !query || $c.data('name').indexOf(query) !== -1;
                    var matchStatus = statuses.length === 0 || statuses.indexOf($c.data('status')) !== -1;
                    var matchGroup  = !groupFilter || String($c.data('group')) === groupFilter;
                    var show = matchSearch && matchStatus && matchGroup;
                    $c.toggle(show);
                    if (show) visible++;
                });
                $noResults.toggle(visible === 0 && $cards.length > 0);
            }

            $('#hl-track-search').on('input', filterCards);
            $('.hl-status-filter').on('change', filterCards);
            $('#hl-cohort-filter').on('change', filterCards);
            filterCards();
        })(jQuery);
        </script>
        <?php

        return ob_get_clean();
    }

    // =========================================================================
    // Data helpers
    // =========================================================================

    private function get_participant_counts() {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT track_id, COUNT(*) AS cnt
             FROM {$wpdb->prefix}hl_enrollment
             WHERE status = 'active'
             GROUP BY track_id",
            ARRAY_A
        );
        $map = array();
        foreach ( $results ?: array() as $row ) {
            $map[ (int) $row['track_id'] ] = (int) $row['cnt'];
        }
        return $map;
    }

    private function get_school_counts() {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT track_id, COUNT(DISTINCT school_id) AS cnt
             FROM {$wpdb->prefix}hl_track_school
             GROUP BY track_id",
            ARRAY_A
        );
        $map = array();
        foreach ( $results ?: array() as $row ) {
            $map[ (int) $row['track_id'] ] = (int) $row['cnt'];
        }
        return $map;
    }

    private function get_cohorts() {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT cohort_id, cohort_name FROM {$wpdb->prefix}hl_cohort WHERE status = 'active' ORDER BY cohort_name ASC",
            ARRAY_A
        );
        $map = array();
        foreach ( $results ?: array() as $row ) {
            $map[ (int) $row['cohort_id'] ] = $row['cohort_name'];
        }
        return $map;
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
