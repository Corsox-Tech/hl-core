<?php
/**
 * WP-CLI command: wp hl-core create-pages
 *
 * Creates WordPress pages for all HL Core shortcodes.
 * Skips pages that already exist (by shortcode detection).
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_CLI_Create_Pages {

    /**
     * Register the WP-CLI command.
     */
    public static function register() {
        WP_CLI::add_command( 'hl-core create-pages', array( new self(), 'run' ) );
    }

    /**
     * Create WordPress pages for all HL Core shortcodes.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Recreate pages even if they already exist.
     *
     * [--status=<status>]
     * : Page status. Default: publish.
     * ---
     * default: publish
     * options:
     *   - publish
     *   - draft
     * ---
     *
     * ## EXAMPLES
     *
     *     wp hl-core create-pages
     *     wp hl-core create-pages --force
     *     wp hl-core create-pages --status=draft
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Named args.
     */
    public function run( $args, $assoc_args ) {
        $force  = isset( $assoc_args['force'] );
        $status = $assoc_args['status'] ?? 'publish';

        $pages = $this->get_page_definitions();

        $created = 0;
        $skipped = 0;

        foreach ( $pages as $page ) {
            $shortcode = $page['shortcode'];
            $existing  = $this->find_existing_page( $shortcode );

            if ( $existing && ! $force ) {
                WP_CLI::log( sprintf(
                    '  SKIP: "%s" — page already exists (ID %d)',
                    $page['title'],
                    $existing
                ) );
                $skipped++;
                continue;
            }

            $page_id = wp_insert_post( array(
                'post_title'   => $page['title'],
                'post_content' => '[' . $shortcode . ']',
                'post_status'  => $status,
                'post_type'    => 'page',
                'post_author'  => get_current_user_id() ?: 1,
            ) );

            if ( is_wp_error( $page_id ) ) {
                WP_CLI::warning( sprintf(
                    'Failed to create "%s": %s',
                    $page['title'],
                    $page_id->get_error_message()
                ) );
                continue;
            }

            WP_CLI::log( sprintf(
                '  CREATED: "%s" (ID %d) — [%s]',
                $page['title'],
                $page_id,
                $shortcode
            ) );
            $created++;
        }

        WP_CLI::success( sprintf(
            'Done. %d pages created, %d skipped (already exist).',
            $created,
            $skipped
        ) );
    }

    /**
     * Get the page definitions — title + shortcode for each HL Core page.
     *
     * @return array
     */
    private function get_page_definitions() {
        return array(
            // Dashboard (LMS Home replacement)
            array( 'title' => 'Dashboard',             'shortcode' => 'hl_dashboard' ),

            // Personal pages
            array( 'title' => 'My Programs',          'shortcode' => 'hl_my_programs' ),
            array( 'title' => 'My Coaching',           'shortcode' => 'hl_my_coaching' ),
            array( 'title' => 'My Progress',           'shortcode' => 'hl_my_progress' ),
            array( 'title' => 'My Track',              'shortcode' => 'hl_my_track' ),
            array( 'title' => 'My Team',               'shortcode' => 'hl_my_team' ),
            array( 'title' => 'Team Progress',         'shortcode' => 'hl_team_progress' ),

            // Directory / listing pages
            array( 'title' => 'Tracks',                'shortcode' => 'hl_tracks_listing' ),
            array( 'title' => 'Institutions',          'shortcode' => 'hl_institutions_listing' ),
            array( 'title' => 'Classrooms',            'shortcode' => 'hl_classrooms_listing' ),
            array( 'title' => 'Learners',              'shortcode' => 'hl_learners' ),
            array( 'title' => 'Pathways',              'shortcode' => 'hl_pathways_listing' ),
            array( 'title' => 'School Districts',      'shortcode' => 'hl_districts_listing' ),
            array( 'title' => 'Schools',               'shortcode' => 'hl_schools_listing' ),

            // Hub / workspace pages
            array( 'title' => 'Coaching Hub',          'shortcode' => 'hl_coaching_hub' ),
            array( 'title' => 'Reports',               'shortcode' => 'hl_reports_hub' ),
            array( 'title' => 'Track Workspace',       'shortcode' => 'hl_track_workspace' ),
            array( 'title' => 'Track Dashboard',       'shortcode' => 'hl_track_dashboard' ),

            // Detail pages (navigated to, not in sidebar menu)
            array( 'title' => 'Program',               'shortcode' => 'hl_program_page' ),
            array( 'title' => 'Activity',              'shortcode' => 'hl_activity_page' ),
            array( 'title' => 'Team',                  'shortcode' => 'hl_team_page' ),
            array( 'title' => 'Classroom',             'shortcode' => 'hl_classroom_page' ),
            array( 'title' => 'District',              'shortcode' => 'hl_district_page' ),
            array( 'title' => 'School',                'shortcode' => 'hl_school_page' ),

            // Assessment / observation pages
            array( 'title' => 'Child Assessment',       'shortcode' => 'hl_child_assessment' ),
            array( 'title' => 'Teacher Self-Assessment',   'shortcode' => 'hl_teacher_assessment' ),
            array( 'title' => 'Observations',              'shortcode' => 'hl_observations' ),
        );
    }

    /**
     * Find an existing published page that contains a shortcode.
     *
     * @param string $shortcode The shortcode tag (without brackets).
     * @return int|null Page ID or null.
     */
    private function find_existing_page( $shortcode ) {
        global $wpdb;

        $page_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page'
             AND post_status IN ('publish', 'draft', 'private')
             AND post_content LIKE %s
             LIMIT 1",
            '%[' . $wpdb->esc_like( $shortcode ) . '%'
        ) );

        return $page_id ? (int) $page_id : null;
    }
}
