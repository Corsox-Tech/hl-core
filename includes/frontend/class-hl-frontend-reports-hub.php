<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_reports_hub] shortcode.
 *
 * Card grid of available report types linking to existing report views.
 * Cards shown based on user role.
 *
 * @package HL_Core
 */
class HL_Frontend_Reports_Hub {

    public function render( $atts ) {
        ob_start();

        $scope = HL_Scope_Service::get_scope();

        if ( ! $scope['is_staff'] && empty( $scope['enrollment_ids'] ) ) {
            echo '<div class="hl-notice hl-notice-error">'
                . esc_html__( 'You do not have access to this page.', 'hl-core' )
                . '</div>';
            return ob_get_clean();
        }

        $reports = $this->get_report_cards( $scope );

        ?>
        <div class="hl-dashboard hl-reports-hub hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'Reports', 'hl-core' ); ?></h2>
            </div>

            <?php if ( empty( $reports ) ) : ?>
                <div class="hl-empty-state"><p><?php esc_html_e( 'No reports available.', 'hl-core' ); ?></p></div>
            <?php else : ?>
                <div class="hl-crm-card-grid">
                    <?php foreach ( $reports as $report ) : ?>
                        <div class="hl-crm-card">
                            <div class="hl-crm-card-body">
                                <h3 class="hl-crm-card-title"><?php echo esc_html( $report['title'] ); ?></h3>
                                <p class="hl-crm-card-description">
                                    <?php echo esc_html( $report['description'] ); ?>
                                </p>
                            </div>
                            <?php if ( $report['url'] ) : ?>
                                <div class="hl-crm-card-action">
                                    <a href="<?php echo esc_url( $report['url'] ); ?>" class="hl-btn hl-btn-sm hl-btn-primary">
                                        <?php esc_html_e( 'View Report', 'hl-core' ); ?>
                                    </a>
                                </div>
                            <?php else : ?>
                                <div class="hl-crm-card-action">
                                    <span class="hl-badge"><?php esc_html_e( 'Coming Soon', 'hl-core' ); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
        <?php

        return ob_get_clean();
    }

    private function get_report_cards( $scope ) {
        $reports = array();

        // Completion Report — links to Cohort Workspace reports tab.
        $workspace_url = $this->find_shortcode_page_url( 'hl_cohort_workspace' );
        $cohort_url    = $this->find_shortcode_page_url( 'hl_my_cohort' );

        // Completion report — staff use workspace, leaders use my-cohort.
        $completion_url = '';
        if ( $scope['is_staff'] && $workspace_url ) {
            $completion_url = add_query_arg( 'tab', 'reports', $workspace_url );
        } elseif ( $cohort_url ) {
            $completion_url = add_query_arg( 'tab', 'reports', $cohort_url );
        }

        $reports[] = array(
            'title'       => __( 'Completion Report', 'hl-core' ),
            'description' => __( 'View participant completion rates by cohort, school, team, and individual. Export CSV.', 'hl-core' ),
            'url'         => $completion_url,
        );

        // Coaching Report — links to Coaching Hub.
        $coaching_url = $this->find_shortcode_page_url( 'hl_coaching_hub' );
        if ( $scope['is_staff'] || in_array( 'mentor', $scope['hl_roles'], true ) ) {
            $reports[] = array(
                'title'       => __( 'Coaching Report', 'hl-core' ),
                'description' => __( 'View coaching session history, attendance rates, and participant coaching progress.', 'hl-core' ),
                'url'         => $coaching_url,
            );
        }

        // Team Report — leaders and staff.
        if ( $scope['is_staff'] || in_array( 'school_leader', $scope['hl_roles'], true )
            || in_array( 'district_leader', $scope['hl_roles'], true ) ) {
            $team_url = '';
            if ( $scope['is_staff'] && $workspace_url ) {
                $team_url = add_query_arg( 'tab', 'teams', $workspace_url );
            } elseif ( $cohort_url ) {
                $team_url = add_query_arg( 'tab', 'teams', $cohort_url );
            }
            $reports[] = array(
                'title'       => __( 'Team Summary', 'hl-core' ),
                'description' => __( 'View team-level completion averages, member counts, and mentor assignments.', 'hl-core' ),
                'url'         => $team_url,
            );
        }

        // Program Group Report — staff and district leaders.
        if ( $scope['is_staff'] || in_array( 'district_leader', $scope['hl_roles'], true ) ) {
            $reports[] = array(
                'title'       => __( 'Program Group Report', 'hl-core' ),
                'description' => __( 'View cross-cohort aggregate metrics for cohort groups. Compare cohorts within a program.', 'hl-core' ),
                'url'         => '', // TODO: link to dedicated group report page when built
            );
        }

        // Assessment Report — staff only, future.
        if ( $scope['is_staff'] ) {
            $reports[] = array(
                'title'       => __( 'Assessment Report', 'hl-core' ),
                'description' => __( 'View teacher self-assessment and children assessment data. Export responses.', 'hl-core' ),
                'url'         => '',
            );
        }

        return $reports;
    }

    private function find_shortcode_page_url( $shortcode ) {
        static $cache = array();
        if ( isset( $cache[ $shortcode ] ) ) {
            return $cache[ $shortcode ];
        }
        global $wpdb;
        $page_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
             AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like( $shortcode ) . '%'
        ) );
        $url = $page_id ? get_permalink( $page_id ) : '';
        $cache[ $shortcode ] = $url;
        return $url;
    }
}
