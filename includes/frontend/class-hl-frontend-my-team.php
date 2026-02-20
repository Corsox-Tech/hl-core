<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_my_team] shortcode.
 *
 * Auto-detect mentor's team via hl_team_membership.
 * If 1 team → redirect/render team page inline.
 * If multiple → team selector cards.
 * If none → friendly message.
 *
 * @package HL_Core
 */
class HL_Frontend_My_Team {

    public function render( $atts ) {
        ob_start();

        $user_id = get_current_user_id();
        $teams   = $this->get_user_teams( $user_id );

        if ( empty( $teams ) ) {
            ?>
            <div class="hl-dashboard hl-my-team hl-frontend-wrap">
                <div class="hl-crm-page-header">
                    <h2 class="hl-crm-page-title"><?php esc_html_e( 'My Team', 'hl-core' ); ?></h2>
                </div>
                <div class="hl-empty-state">
                    <p><?php esc_html_e( 'You are not currently assigned to any team.', 'hl-core' ); ?></p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        $team_page_url = $this->find_shortcode_page_url( 'hl_team_page' );

        // If exactly 1 team, render the team page inline.
        if ( count( $teams ) === 1 && $team_page_url ) {
            $team = $teams[0];
            $url  = add_query_arg( 'id', $team['team_id'], $team_page_url );
            // Render via the existing team page renderer.
            $_GET['id'] = $team['team_id'];
            $renderer = new HL_Frontend_Team_Page();
            return $renderer->render( array() );
        }

        // Multiple teams — show selector cards.
        ?>
        <div class="hl-dashboard hl-my-team hl-frontend-wrap">

            <div class="hl-crm-page-header">
                <h2 class="hl-crm-page-title"><?php esc_html_e( 'My Teams', 'hl-core' ); ?></h2>
            </div>

            <div class="hl-crm-card-grid">
                <?php foreach ( $teams as $team ) :
                    $detail_url = $team_page_url
                        ? add_query_arg( 'id', $team['team_id'], $team_page_url )
                        : '';
                ?>
                    <div class="hl-crm-card">
                        <div class="hl-crm-card-body">
                            <h3 class="hl-crm-card-title">
                                <?php if ( $detail_url ) : ?>
                                    <a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $team['team_name'] ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $team['team_name'] ); ?>
                                <?php endif; ?>
                            </h3>
                            <?php if ( $team['center_name'] ) : ?>
                                <div style="font-size:13px; color:#6c757d; margin-bottom:6px;">
                                    <?php echo esc_html( $team['center_name'] ); ?>
                                </div>
                            <?php endif; ?>
                            <div class="hl-crm-card-meta">
                                <span class="hl-crm-card-stat">
                                    <strong><?php echo esc_html( $team['member_count'] ); ?></strong>
                                    <?php echo esc_html( _n( 'Member', 'Members', (int) $team['member_count'], 'hl-core' ) ); ?>
                                </span>
                                <?php if ( $team['cohort_name'] ) : ?>
                                    <span class="hl-crm-card-stat" style="font-size:12px;">
                                        <?php echo esc_html( $team['cohort_name'] ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ( $detail_url ) : ?>
                            <div class="hl-crm-card-action">
                                <a href="<?php echo esc_url( $detail_url ); ?>" class="hl-btn hl-btn-sm hl-btn-secondary">
                                    <?php esc_html_e( 'View Team', 'hl-core' ); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
        <?php

        return ob_get_clean();
    }

    // =========================================================================
    // Data helpers
    // =========================================================================

    private function get_user_teams( $user_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT t.team_id, t.team_name, t.center_id, t.cohort_id,
                    ou.name AS center_name, co.cohort_name,
                    COALESCE(mc.member_count, 0) AS member_count
             FROM {$prefix}hl_team_membership tm
             JOIN {$prefix}hl_team t ON tm.team_id = t.team_id
             JOIN {$prefix}hl_enrollment e ON tm.enrollment_id = e.enrollment_id
             LEFT JOIN {$prefix}hl_orgunit ou ON t.center_id = ou.orgunit_id
             LEFT JOIN {$prefix}hl_cohort co ON t.cohort_id = co.cohort_id
             LEFT JOIN (
                 SELECT team_id, COUNT(*) AS member_count
                 FROM {$prefix}hl_team_membership
                 GROUP BY team_id
             ) mc ON t.team_id = mc.team_id
             WHERE e.user_id = %d AND e.status = 'active'
             ORDER BY t.team_name ASC",
            $user_id
        ), ARRAY_A ) ?: array();
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
