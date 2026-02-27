<?php
/**
 * WP-CLI command: wp hl-core seed-docs
 *
 * Seeds documentation articles and glossary terms for the HL Core
 * documentation system. Skip-if-exists by slug.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_CLI_Seed_Docs {

    public static function register() {
        WP_CLI::add_command( 'hl-core seed-docs', array( new self(), 'run' ) );
    }

    /**
     * Seed documentation articles and glossary terms.
     *
     * ## OPTIONS
     *
     * [--clean]
     * : Delete all existing hl_doc posts before seeding.
     *
     * ## EXAMPLES
     *
     *     wp hl-core seed-docs
     *     wp hl-core seed-docs --clean
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function run( $args, $assoc_args ) {
        $clean = isset( $assoc_args['clean'] );

        if ( $clean ) {
            $this->clean_docs();
        }

        // Ensure categories exist
        $cat_ids = $this->ensure_categories();

        // Seed articles
        $articles = $this->get_article_definitions( $cat_ids );
        $created  = 0;
        $skipped  = 0;

        foreach ( $articles as $article ) {
            $existing = get_page_by_path( $article['slug'], OBJECT, 'hl_doc' );
            if ( $existing ) {
                WP_CLI::log( sprintf( '  SKIP: "%s" (already exists)', $article['title'] ) );
                $skipped++;
                continue;
            }

            $post_id = wp_insert_post( array(
                'post_type'    => 'hl_doc',
                'post_title'   => $article['title'],
                'post_name'    => $article['slug'],
                'post_content' => $article['content'],
                'post_status'  => 'publish',
                'post_author'  => get_current_user_id() ?: 1,
            ) );

            if ( is_wp_error( $post_id ) ) {
                WP_CLI::warning( sprintf( 'Failed to create "%s": %s', $article['title'], $post_id->get_error_message() ) );
                continue;
            }

            // Assign category
            wp_set_object_terms( $post_id, array( $article['cat_id'] ), 'hl_doc_category' );

            // Set sort order
            update_post_meta( $post_id, 'hl_doc_sort_order', $article['sort_order'] );

            WP_CLI::log( sprintf( '  CREATED: "%s" (ID %d)', $article['title'], $post_id ) );
            $created++;
        }

        WP_CLI::success( sprintf( 'Done. %d articles created, %d skipped.', $created, $skipped ) );
    }

    /**
     * Delete all hl_doc posts.
     */
    private function clean_docs() {
        $posts = get_posts( array(
            'post_type'      => 'hl_doc',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );

        foreach ( $posts as $id ) {
            wp_delete_post( $id, true );
        }

        WP_CLI::log( sprintf( '  Cleaned %d existing doc articles.', count( $posts ) ) );
    }

    /**
     * Ensure all doc categories exist.
     *
     * @return array<string, int> slug => term_id map.
     */
    private function ensure_categories() {
        $categories = array(
            'getting-started'        => 'Getting Started',
            'core-concepts'          => 'Core Concepts',
            'assessments'            => 'Assessments',
            'coaching-observations'  => 'Coaching & Observations',
            'import-data-management' => 'Import & Data Management',
            'pathways-activities'    => 'Pathways & Activities',
            'reports-exports'        => 'Reports & Exports',
            'glossary'               => 'Glossary',
        );

        $cat_ids = array();

        foreach ( $categories as $slug => $name ) {
            $term = get_term_by( 'slug', $slug, 'hl_doc_category' );
            if ( $term ) {
                $cat_ids[ $slug ] = $term->term_id;
            } else {
                $result = wp_insert_term( $name, 'hl_doc_category', array( 'slug' => $slug ) );
                if ( is_wp_error( $result ) ) {
                    WP_CLI::warning( sprintf( 'Failed to create category "%s": %s', $name, $result->get_error_message() ) );
                    continue;
                }
                $cat_ids[ $slug ] = $result['term_id'];
                WP_CLI::log( sprintf( '  Category created: "%s"', $name ) );
            }
        }

        return $cat_ids;
    }

    /**
     * Get all article definitions.
     *
     * @param array $cat_ids slug => term_id map.
     * @return array
     */
    private function get_article_definitions( $cat_ids ) {
        $articles = array();

        // =====================================================================
        // Getting Started (3 articles)
        // =====================================================================

        $articles[] = array(
            'slug'       => 'welcome-to-hl-core',
            'title'      => 'Welcome to HL Core',
            'cat_id'     => $cat_ids['getting-started'] ?? 0,
            'sort_order' => 1,
            'content'    => $this->content_welcome(),
        );

        $articles[] = array(
            'slug'       => 'quick-start-guide',
            'title'      => 'Quick Start Guide',
            'cat_id'     => $cat_ids['getting-started'] ?? 0,
            'sort_order' => 2,
            'content'    => $this->content_quick_start(),
        );

        $articles[] = array(
            'slug'       => 'understanding-the-dashboard',
            'title'      => 'Understanding the Dashboard',
            'cat_id'     => $cat_ids['getting-started'] ?? 0,
            'sort_order' => 3,
            'content'    => $this->content_dashboard(),
        );

        // =====================================================================
        // Core Concepts (5 articles)
        // =====================================================================

        $articles[] = array(
            'slug'       => 'cohorts-vs-tracks',
            'title'      => 'Cohorts vs Tracks',
            'cat_id'     => $cat_ids['core-concepts'] ?? 0,
            'sort_order' => 1,
            'content'    => $this->content_cohorts_vs_tracks(),
        );

        $articles[] = array(
            'slug'       => 'enrollments-and-roles',
            'title'      => 'Enrollments & Roles',
            'cat_id'     => $cat_ids['core-concepts'] ?? 0,
            'sort_order' => 2,
            'content'    => $this->content_enrollments_roles(),
        );

        $articles[] = array(
            'slug'       => 'organizations-districts-schools',
            'title'      => 'Organizations (Districts & Schools)',
            'cat_id'     => $cat_ids['core-concepts'] ?? 0,
            'sort_order' => 3,
            'content'    => $this->content_organizations(),
        );

        $articles[] = array(
            'slug'       => 'teams-and-mentoring',
            'title'      => 'Teams & Mentoring',
            'cat_id'     => $cat_ids['core-concepts'] ?? 0,
            'sort_order' => 4,
            'content'    => $this->content_teams(),
        );

        $articles[] = array(
            'slug'       => 'control-groups',
            'title'      => 'Control Groups',
            'cat_id'     => $cat_ids['core-concepts'] ?? 0,
            'sort_order' => 5,
            'content'    => $this->content_control_groups(),
        );

        // =====================================================================
        // Assessments (4 articles)
        // =====================================================================

        $articles[] = array(
            'slug'       => 'teacher-self-assessment',
            'title'      => 'Teacher Self-Assessment (Pre & Post)',
            'cat_id'     => $cat_ids['assessments'] ?? 0,
            'sort_order' => 1,
            'content'    => $this->content_teacher_assessment(),
        );

        $articles[] = array(
            'slug'       => 'child-assessment',
            'title'      => 'Child Assessment',
            'cat_id'     => $cat_ids['assessments'] ?? 0,
            'sort_order' => 2,
            'content'    => $this->content_child_assessment(),
        );

        $articles[] = array(
            'slug'       => 'assessment-instruments-versioning',
            'title'      => 'Assessment Instruments & Versioning',
            'cat_id'     => $cat_ids['assessments'] ?? 0,
            'sort_order' => 3,
            'content'    => $this->content_instruments(),
        );

        $articles[] = array(
            'slug'       => 'display-styles',
            'title'      => 'Display Styles (Customizing Fonts & Colors)',
            'cat_id'     => $cat_ids['assessments'] ?? 0,
            'sort_order' => 4,
            'content'    => $this->content_display_styles(),
        );

        // =====================================================================
        // Coaching & Observations (3 articles)
        // =====================================================================

        $articles[] = array(
            'slug'       => 'coaching-sessions',
            'title'      => 'Coaching Sessions',
            'cat_id'     => $cat_ids['coaching-observations'] ?? 0,
            'sort_order' => 1,
            'content'    => $this->content_coaching_sessions(),
        );

        $articles[] = array(
            'slug'       => 'coach-assignments',
            'title'      => 'Coach Assignments',
            'cat_id'     => $cat_ids['coaching-observations'] ?? 0,
            'sort_order' => 2,
            'content'    => $this->content_coach_assignments(),
        );

        $articles[] = array(
            'slug'       => 'observations',
            'title'      => 'Observations (JetFormBuilder)',
            'cat_id'     => $cat_ids['coaching-observations'] ?? 0,
            'sort_order' => 3,
            'content'    => $this->content_observations(),
        );

        // =====================================================================
        // Import & Data Management (2 articles)
        // =====================================================================

        $articles[] = array(
            'slug'       => 'csv-import-guide',
            'title'      => 'CSV Import Guide',
            'cat_id'     => $cat_ids['import-data-management'] ?? 0,
            'sort_order' => 1,
            'content'    => $this->content_csv_import(),
        );

        $articles[] = array(
            'slug'       => 'managing-classrooms-children',
            'title'      => 'Managing Classrooms & Children',
            'cat_id'     => $cat_ids['import-data-management'] ?? 0,
            'sort_order' => 2,
            'content'    => $this->content_classrooms_children(),
        );

        // =====================================================================
        // Pathways & Activities (3 articles)
        // =====================================================================

        $articles[] = array(
            'slug'       => 'pathways-overview',
            'title'      => 'Pathways Overview',
            'cat_id'     => $cat_ids['pathways-activities'] ?? 0,
            'sort_order' => 1,
            'content'    => $this->content_pathways_overview(),
        );

        $articles[] = array(
            'slug'       => 'activity-types-configuration',
            'title'      => 'Activity Types & Configuration',
            'cat_id'     => $cat_ids['pathways-activities'] ?? 0,
            'sort_order' => 2,
            'content'    => $this->content_activity_types(),
        );

        $articles[] = array(
            'slug'       => 'prerequisites-drip-rules',
            'title'      => 'Prerequisites & Drip Rules',
            'cat_id'     => $cat_ids['pathways-activities'] ?? 0,
            'sort_order' => 3,
            'content'    => $this->content_prerequisites(),
        );

        // =====================================================================
        // Reports & Exports (2 articles)
        // =====================================================================

        $articles[] = array(
            'slug'       => 'completion-reports',
            'title'      => 'Completion Reports',
            'cat_id'     => $cat_ids['reports-exports'] ?? 0,
            'sort_order' => 1,
            'content'    => $this->content_completion_reports(),
        );

        $articles[] = array(
            'slug'       => 'program-vs-control-comparison',
            'title'      => 'Program vs Control Group Comparison',
            'cat_id'     => $cat_ids['reports-exports'] ?? 0,
            'sort_order' => 2,
            'content'    => $this->content_comparison_reports(),
        );

        // =====================================================================
        // Glossary (~15 terms)
        // =====================================================================

        $glossary_terms = $this->get_glossary_definitions();
        $sort = 1;
        foreach ( $glossary_terms as $term_slug => $term_data ) {
            $articles[] = array(
                'slug'       => $term_slug,
                'title'      => $term_data['title'],
                'cat_id'     => $cat_ids['glossary'] ?? 0,
                'sort_order' => $sort++,
                'content'    => $term_data['content'],
            );
        }

        return $articles;
    }

    // =========================================================================
    // Article Content Methods
    // =========================================================================

    private function content_welcome() {
        return <<<'HTML'
<h2>What is HL Core?</h2>

HL Core is the system-of-record plugin for Housman Learning Academy. It manages everything related to your B2E (Birth to 5 Educators) programs: track management, participant enrollment, assessments, coaching sessions, observations, and reporting.

<h2>Who is this documentation for?</h2>

This documentation is written for <strong>Housman administrators and staff</strong> who manage programs, enroll participants, configure assessments, and review reports. If you are a teacher, mentor, or school leader, the relevant pages in the sidebar (My Programs, My Coaching, etc.) are designed to be self-explanatory.

<h2>How to navigate</h2>

<ul>
<li><strong>Browse by category</strong> — Use the category cards on the Documentation landing page to find articles grouped by topic.</li>
<li><strong>Search</strong> — Use the search bar to find articles by keyword.</li>
<li><strong>Glossary</strong> — Look up specific terms and definitions in the Glossary.</li>
<li><strong>Cross-links</strong> — Articles link to related topics with blue dashed-underline links.</li>
</ul>

<h2>Getting help</h2>

If you can't find what you need in this documentation, contact the Housman Learning Academy technical team for assistance.
HTML;
    }

    private function content_quick_start() {
        return <<<'HTML'
<h2>Setting up a new program</h2>

Here's the typical workflow to get a new B2E program running in HL Core:

<h3>1. Create the organizational structure</h3>

Go to <strong>WP Admin > HL Core > Org Units</strong> and create the school district and schools that will participate. Each school should be associated with its parent district.

<h3>2. Create a Cohort</h3>

Go to <strong>HL Core > Cohorts</strong> and create a new [hl_doc_link slug="cohorts-vs-tracks" text="Cohort"]. A Cohort is the contract-level container — for example, "B2E Mastery - Palm Beach County 2026." Set the status to Active and optionally assign it to a Cohort Group.

<h3>3. Create a Track</h3>

Inside the Cohort editor, go to the Details tab and create a new Track. A Track is the time-bounded run within the Cohort. Link schools to the Track and set start/end dates.

<h3>4. Configure a Pathway</h3>

Go to the Pathways tab within the Cohort and create a [hl_doc_link slug="pathways-overview" text="Pathway"] with the activities participants need to complete. Assign the pathway to enrollments.

<h3>5. Import participants</h3>

Go to <strong>HL Core > Imports</strong> and upload a CSV file with participant information. The [hl_doc_link slug="csv-import-guide" text="CSV Import Guide"] explains the process in detail.

<h3>6. Assign coaches</h3>

Go to <strong>HL Core > Coach Assignments</strong> to assign [hl_doc_link slug="coach-assignments" text="coaches"] to schools, teams, or individual enrollments.

<h3>7. Monitor progress</h3>

Use the front-end [hl_doc_link slug="completion-reports" text="Reports"] pages or WP Admin > HL Core > Reports to track participant completion.
HTML;
    }

    private function content_dashboard() {
        return <<<'HTML'
<h2>The HL Core Dashboard</h2>

The Dashboard is the home page for all HL Core users. It shows different cards depending on your role:

<h3>For participants (teachers, mentors, leaders)</h3>

<ul>
<li><strong>My Programs</strong> — Your enrolled programs with progress bars and "Continue" buttons.</li>
<li><strong>My Coaching</strong> — Your coaching sessions (hidden for control group participants).</li>
<li><strong>My Classrooms</strong> — Classrooms you're assigned to teach.</li>
<li><strong>My Team</strong> — Your mentoring team (mentors only).</li>
<li><strong>My Track</strong> — Your track workspace (leaders and mentors).</li>
</ul>

<h3>For staff and administrators</h3>

In addition to any participant cards (if enrolled), staff see an <strong>Administration</strong> section with:

<ul>
<li><strong>Tracks</strong> — All tracks in the system.</li>
<li><strong>Institutions</strong> — Districts and schools.</li>
<li><strong>Learners</strong> — Participant directory.</li>
<li><strong>Pathways</strong> — Pathway configuration.</li>
<li><strong>Coaching Hub</strong> — All coaching sessions.</li>
<li><strong>Reports</strong> — Reporting dashboard.</li>
</ul>

<h2>Role detection</h2>

The Dashboard automatically detects your roles from your [hl_doc_link slug="enrollments-and-roles" text="enrollment records"]. A single user can have multiple roles across different tracks — the Dashboard shows the union of all relevant cards.
HTML;
    }

    private function content_cohorts_vs_tracks() {
        return <<<'HTML'
<h2>The two-level hierarchy</h2>

HL Core uses a two-level hierarchy for program management:

<h3>Cohort (the container)</h3>

A <strong>Cohort</strong> is a contract-level entity that represents a program partnership. Think of it as the "project" or "contract." Examples:
<ul>
<li>"B2E Mastery - Palm Beach County 2026"</li>
<li>"B2E Mastery - Lutheran Services Florida"</li>
</ul>

A Cohort has a name, a code (for enrollment), a status (active, paused, archived), and optional start/end dates.

<h3>Track (the run)</h3>

A <strong>Track</strong> is a time-bounded run within a Cohort. Most Cohorts have one Track, but a Cohort can have multiple:
<ul>
<li>A <strong>program track</strong> with the full B2E curriculum (courses, coaching, observations, assessments).</li>
<li>A <strong>control track</strong> with assessment-only activities (for research comparison).</li>
</ul>

Tracks hold the configuration: linked schools, pathways, teams, enrollments, and coaching assignments.

<h3>Why two levels?</h3>

The separation exists because:
<ul>
<li>A single contract may include both a program group and a [hl_doc_link slug="control-groups" text="control group"].</li>
<li>[hl_doc_link slug="program-vs-control-comparison" text="Comparison reports"] operate at the Cohort level, comparing tracks within the same Cohort.</li>
<li>Cohort Groups aggregate multiple Cohorts for cross-program reporting.</li>
</ul>

<blockquote>The front-end shows "Program" to participants (the word "Track" appears only in admin interfaces). See the Glossary for the full label mapping.</blockquote>
HTML;
    }

    private function content_enrollments_roles() {
        return <<<'HTML'
<h2>How enrollment works</h2>

An <strong>enrollment</strong> links a WordPress user to a specific Track. Each enrollment record contains:
<ul>
<li>The user's <strong>roles</strong> within that track (stored as JSON — e.g., <code>["teacher"]</code> or <code>["school_leader", "mentor"]</code>)</li>
<li>A <strong>school</strong> association</li>
<li>A <strong>status</strong> (active, completed, withdrawn)</li>
<li>Optional <strong>pathway assignment</strong></li>
</ul>

<h2>Track roles vs WordPress roles</h2>

This is an important distinction:

<strong>WordPress roles</strong> (like Administrator, Subscriber, Coach) are global — they apply site-wide. HL Core creates only one custom WP role: <strong>Coach</strong>.

<strong>Track roles</strong> (teacher, mentor, school_leader, district_leader) are per-enrollment. A user can be a teacher in one track and a mentor in another. These roles live in the <code>hl_enrollment.roles</code> JSON column, not in WordPress user roles.

<h3>Available track roles</h3>

<ul>
<li><strong>teacher</strong> — A B2E program participant who completes the curriculum.</li>
<li><strong>mentor</strong> — Oversees a team of teachers, conducts observations.</li>
<li><strong>school_leader</strong> — Can view data for their school.</li>
<li><strong>district_leader</strong> — Can view data for all schools in their district.</li>
</ul>

<h2>How roles affect visibility</h2>

The [hl_doc_link slug="understanding-the-dashboard" text="Dashboard"] and sidebar navigation show different pages based on your roles. The scope system filters data so each user only sees what they should — a school leader sees only their school's participants, a mentor sees only their team, etc.
HTML;
    }

    private function content_organizations() {
        return <<<'HTML'
<h2>Organizational hierarchy</h2>

HL Core models organizations (school districts and schools) using the <strong>Org Units</strong> system:

<h3>Districts</h3>

A <strong>District</strong> is the top-level organizational unit. It represents a school district, agency, or organizational partner. Districts contain one or more schools.

<h3>Schools</h3>

A <strong>School</strong> belongs to a district and represents a physical site. Schools are linked to Tracks (a Track can serve multiple schools), and enrollments are associated with schools.

<h2>Managing organizations</h2>

Go to <strong>WP Admin > HL Core > Org Units</strong> to manage the organizational hierarchy. The admin page shows a collapsible view with districts as sections and schools nested beneath them.

<h3>Linking schools to tracks</h3>

When editing a Track (inside the Cohort editor), use the <strong>Schools</strong> tab to link/unlink schools. Only linked schools can have participants enrolled in that Track.
HTML;
    }

    private function content_teams() {
        return <<<'HTML'
<h2>What are teams?</h2>

A <strong>Team</strong> groups teachers together under one or two mentors for coaching and observation purposes. Teams belong to a specific Track and School.

<h2>Team structure</h2>

<ul>
<li>Each team has a <strong>name</strong> and is associated with a <strong>school</strong> and <strong>track</strong>.</li>
<li>A team can have up to <strong>2 mentors</strong> (soft limit — can be overridden).</li>
<li>A teacher can only belong to <strong>one team per track</strong> (hard limit).</li>
<li>Mentors are also enrolled participants — they have their own [hl_doc_link slug="enrollments-and-roles" text="enrollment"] with the "mentor" role.</li>
</ul>

<h2>Creating teams</h2>

Teams can be created in two ways:
<ul>
<li><strong>WP Admin</strong> — Go to HL Core > Teams or use the Teams tab in the Cohort editor.</li>
<li><strong>CSV Import</strong> — The participant import can automatically create teams when importing mentors.</li>
</ul>

<h2>What mentors see</h2>

Mentors see a "My Team" page in the sidebar that shows their team members, completion progress, and links to conduct [hl_doc_link slug="observations" text="observations"].
HTML;
    }

    private function content_control_groups() {
        return <<<'HTML'
<h2>What is a control group?</h2>

A <strong>control group</strong> is a Track where participants only complete assessments — no courses, coaching, or observations. Control groups exist for research purposes: by comparing assessment results between the program group and the control group, Housman can measure the impact of the B2E curriculum.

<h2>How to create a control group</h2>

<ol>
<li>Open the Cohort editor for an existing Cohort (or create a new one).</li>
<li>Create a new Track and check the <strong>"Control Group"</strong> checkbox.</li>
<li>The Track will be marked with a purple "Control" badge.</li>
<li>Create an assessment-only [hl_doc_link slug="pathways-overview" text="pathway"] with 4 activities: Teacher Self-Assessment Pre, Child Assessment Pre, Teacher Self-Assessment Post, Child Assessment Post.</li>
<li>Use [hl_doc_link slug="prerequisites-drip-rules" text="drip rules"] to time-gate the POST assessments.</li>
</ol>

<h2>What's different for control groups?</h2>

<ul>
<li>Coaching and Teams tabs are hidden in both admin and front-end.</li>
<li>The "My Coaching" card is hidden on the Dashboard for control-group-only participants.</li>
<li>Only assessment activities appear in the pathway.</li>
<li>[hl_doc_link slug="program-vs-control-comparison" text="Comparison reports"] show side-by-side results when a Cohort contains both program and control Tracks.</li>
</ul>
HTML;
    }

    private function content_teacher_assessment() {
        return <<<'HTML'
<h2>Overview</h2>

The <strong>Teacher Self-Assessment</strong> (TSA) is a standardized instrument that teachers complete twice — once at the beginning of the program (PRE) and once at the end (POST). It measures teacher knowledge and practices related to social-emotional learning.

<h2>PRE vs POST</h2>

<h3>PRE assessment</h3>
The PRE version is a single-column form. Teachers rate themselves on each item using a Likert scale (e.g., 1-5 or descriptive labels like "Not at all" to "To a great extent").

<h3>POST assessment</h3>
The POST version uses a unique <strong>dual-column retrospective</strong> format. Teachers see their original PRE responses alongside new rating columns. This allows them to re-evaluate their baseline knowledge after completing the program — a research-validated methodology.

<h2>How it works</h2>

<ol>
<li>An admin creates a [hl_doc_link slug="assessment-instruments-versioning" text="Teacher Assessment Instrument"] in WP Admin > HL Core > Instruments.</li>
<li>The instrument is linked to a pathway activity (type: <code>teacher_self_assessment</code>).</li>
<li>When a teacher opens the activity, the custom form renderer displays the instrument.</li>
<li>Responses are stored as structured JSON in the <code>hl_teacher_assessment_instance</code> table.</li>
<li>After submission, the activity is marked complete and the [hl_doc_link slug="completion-reports" text="completion rollup"] updates.</li>
</ol>

<h2>Assessment instances</h2>

Each teacher gets one <strong>instance</strong> per assessment activity. Instances track:
<ul>
<li><strong>Status</strong> — not_started, in_progress, submitted</li>
<li><strong>Responses JSON</strong> — structured answers keyed by section and item</li>
<li><strong>Timestamps</strong> — started_at, submitted_at</li>
</ul>
HTML;
    }

    private function content_child_assessment() {
        return <<<'HTML'
<h2>Overview</h2>

The <strong>Child Assessment</strong> measures children's social-emotional development. Teachers complete the assessment for each child in their classroom using an age-appropriate [hl_doc_link slug="assessment-instruments-versioning" text="instrument"].

<h2>Age groups</h2>

Children are grouped by age at the time of assessment (frozen at snapshot time):
<ul>
<li><strong>Infant</strong> (0-12 months)</li>
<li><strong>Toddler</strong> (12-36 months)</li>
<li><strong>Preschool</strong> (36-60 months)</li>
</ul>

Each age group has its own instrument with age-appropriate items and scales.

<h2>How it works</h2>

<ol>
<li>Children are assigned to classrooms and linked to teaching assignments.</li>
<li>When a child assessment activity becomes available, HL Core generates <strong>assessment instances</strong> for each teacher-classroom pair.</li>
<li>The teacher opens the assessment and sees a matrix of children grouped by age group.</li>
<li>For each child, the teacher rates each item on the instrument's scale.</li>
<li>Teachers can <strong>skip</strong> individual children (e.g., if a child was absent or is new).</li>
<li>Draft saving is supported via AJAX — work is preserved between sessions.</li>
</ol>

<h2>The assessment form</h2>

The child assessment form features:
<ul>
<li><strong>Instructions panel</strong> — customizable per-instrument rich text</li>
<li><strong>Behavior key table</strong> — explains the frequency labels (Never, Rarely, Sometimes, Often, Almost Always)</li>
<li><strong>Age-group sections</strong> — children grouped by infant/toddler/preschool</li>
<li><strong>Transposed Likert matrix</strong> — children as columns, items as rows</li>
<li><strong>Per-child skip toggle</strong> — mark individual children as skipped with a reason</li>
</ul>
HTML;
    }

    private function content_instruments() {
        return <<<'HTML'
<h2>What are instruments?</h2>

An <strong>instrument</strong> defines the structure of an assessment — the sections, items, and rating scales. HL Core has two types of instruments:

<h3>Teacher Assessment Instruments</h3>
Used for [hl_doc_link slug="teacher-self-assessment" text="Teacher Self-Assessments"]. Managed in <strong>WP Admin > HL Core > Instruments</strong> (Teacher tab). Features:
<ul>
<li>Visual section builder with drag-and-drop items</li>
<li>Scale configuration (Likert labels or numeric anchors)</li>
<li>Rich text formatting for items (bold, italic, underline)</li>
<li>Separate PRE and POST instruments (POST includes retrospective format)</li>
</ul>

<h3>Child Assessment Instruments</h3>
Used for [hl_doc_link slug="child-assessment" text="Child Assessments"]. Managed in <strong>WP Admin > HL Core > Instruments</strong> (Child tab). Features:
<ul>
<li>Age-group-specific (infant, toddler, preschool)</li>
<li>Question editor with type, prompt, and allowed values</li>
<li>Custom instructions and behavior key configuration</li>
</ul>

<h2>Versioning</h2>

Instruments support versioning. When an instrument has been used (instances exist), the admin sees a warning before making changes. You can:
<ul>
<li><strong>Edit in place</strong> — changes apply to new instances only (existing submitted responses are frozen).</li>
<li><strong>Create a new version</strong> — create a new instrument and link it to future activities.</li>
</ul>

<h2>Instrument protection</h2>

Instruments are <strong>protected from the nuke command</strong> by default. Running <code>wp hl-core nuke</code> will NOT delete instruments. Use the <code>--include-instruments</code> flag to explicitly include them.
HTML;
    }

    private function content_display_styles() {
        return <<<'HTML'
<h2>Customizing assessment appearance</h2>

Both teacher and child assessment instruments support <strong>admin-customizable display styles</strong>. These let you control font sizes and colors for different elements of the assessment form without changing code.

<h2>Available style options</h2>

The Display Styles panel in the instrument editor lets you customize:
<ul>
<li><strong>Instructions</strong> — font size and color for the instructions panel</li>
<li><strong>Section Titles</strong> — font size and color for section headings</li>
<li><strong>Section Descriptions</strong> — font size and color for section descriptions</li>
<li><strong>Items</strong> — font size and color for assessment items/questions</li>
<li><strong>Scale Labels</strong> — font size and color for Likert scale labels</li>
<li><strong>Behavior Key</strong> — font size and color for the behavior key table (child assessment)</li>
</ul>

<h2>How to use</h2>

<ol>
<li>Open an instrument in <strong>WP Admin > HL Core > Instruments</strong>.</li>
<li>Scroll down to the <strong>Display Styles</strong> collapsible section.</li>
<li>Use the font size dropdowns (12-24px) and color pickers to customize each element.</li>
<li>Click Save. The styles are stored as JSON and applied on the front-end.</li>
</ol>

<h2>Defaults</h2>

If no custom styles are set, the assessment forms use built-in default sizes and colors that are designed for readability and print-friendliness.
HTML;
    }

    private function content_coaching_sessions() {
        return <<<'HTML'
<h2>Overview</h2>

<strong>Coaching sessions</strong> are one-on-one or group meetings between a Housman coach and a teacher. Sessions are managed in both WP Admin and the front-end Coaching Hub.

<h2>Session properties</h2>

Each coaching session has:
<ul>
<li><strong>Title</strong> — descriptive name (e.g., "Coaching Session #3 - Week of Feb 10")</li>
<li><strong>Participant</strong> — the teacher being coached</li>
<li><strong>Coach</strong> — the assigned Housman coach</li>
<li><strong>Date/Time</strong> — scheduled date and time</li>
<li><strong>Meeting URL</strong> — link for virtual sessions (Zoom, Teams, etc.)</li>
<li><strong>Status</strong> — scheduled, attended, missed, or cancelled</li>
<li><strong>Notes</strong> — rich text notes from the coach</li>
<li><strong>Linked observations</strong> — [hl_doc_link slug="observations" text="observations"] conducted during the session</li>
<li><strong>Attachments</strong> — uploaded files via WordPress Media</li>
</ul>

<h2>Session workflow</h2>

<ol>
<li>A session is created with status "scheduled."</li>
<li>After the meeting, the coach updates the status to "attended" (or "missed"/"cancelled").</li>
<li>When marked as "attended," the corresponding coaching activity in the teacher's [hl_doc_link slug="pathways-overview" text="pathway"] is updated.</li>
<li>Terminal statuses (attended, missed, cancelled) lock the session from further status changes.</li>
</ol>
HTML;
    }

    private function content_coach_assignments() {
        return <<<'HTML'
<h2>How coach assignment works</h2>

Coaches are assigned at three scope levels. The "most specific wins" rule determines which coach a participant sees:

<h3>1. School level</h3>
A coach assigned to a school is the default for all participants at that school. This is the broadest assignment.

<h3>2. Team level</h3>
A coach assigned to a team overrides the school-level assignment for all members of that team.

<h3>3. Enrollment level</h3>
A coach assigned to a specific enrollment overrides everything — this is the most specific level.

<h2>Assignment history</h2>

When a coach is reassigned, the old assignment is closed (given an <code>effective_to</code> date) and a new one is created. This preserves the full coaching history. Previous coaching sessions retain their original coach — they don't change when a reassignment happens.

<h2>Managing assignments</h2>

Go to <strong>WP Admin > HL Core > Coach Assignments</strong> to create, edit, or end coach assignments. You can filter by cohort to see assignments for a specific program.
HTML;
    }

    private function content_observations() {
        return <<<'HTML'
<h2>Overview</h2>

<strong>Observations</strong> are mentor-submitted forms that document classroom visits. Unlike assessments (which use custom PHP forms), observations use <strong>JetFormBuilder</strong> forms — this allows Housman admins to customize observation questions using the visual form editor without developer involvement.

<h2>How observations work</h2>

<ol>
<li>An admin creates an observation form in <strong>JetFormBuilder</strong> with the required hidden fields.</li>
<li>The form is linked to a pathway activity (type: <code>observation</code>).</li>
<li>A mentor opens the observation page and selects a teacher from their team.</li>
<li>HL Core renders the JFB form with hidden fields pre-populated (enrollment ID, activity ID, track ID).</li>
<li>On submit, JFB fires the <code>hl_core_form_submitted</code> hook.</li>
<li>HL Core updates the observation status and triggers the completion rollup.</li>
</ol>

<h2>Required JFB setup</h2>

The JetFormBuilder form must include:
<ul>
<li><strong>Hidden fields:</strong> <code>hl_enrollment_id</code>, <code>hl_activity_id</code>, <code>hl_track_id</code>, <code>hl_observation_id</code></li>
<li><strong>Post-submit action:</strong> "Call Hook" with hook name <code>hl_core_form_submitted</code></li>
</ul>

Without these, HL Core cannot link the form submission back to the correct observation record.
HTML;
    }

    private function content_csv_import() {
        return <<<'HTML'
<h2>Overview</h2>

The CSV Import wizard lets you bulk-import data into HL Core. It supports four import types:

<h3>Import types</h3>

<ul>
<li><strong>Participants</strong> — Creates WordPress users, enrolls them in a track, assigns roles and schools. Handles identity matching (existing users are linked, not duplicated).</li>
<li><strong>Children</strong> — Adds children to classrooms. Uses fingerprint-based matching to avoid duplicates.</li>
<li><strong>Classrooms</strong> — Creates classrooms at schools. Detects duplicates by school + name.</li>
<li><strong>Teaching Assignments</strong> — Links teachers (by email) to classrooms within a track.</li>
</ul>

<h2>The import process</h2>

The import uses a 3-step wizard:

<h3>Step 1: Upload</h3>
Select the import type, choose the target track, and upload a CSV file. The system validates columns and maps them to expected fields (with synonym support — e.g., "Email Address" maps to "email").

<h3>Step 2: Preview & Select</h3>
Review the parsed data. Each row shows a status badge: ready, warning, or error. Select which rows to import. Unmapped columns are shown but ignored.

<h3>Step 3: Results</h3>
After committing, the results page shows created, updated, skipped, and errored counts. You can download an error report CSV.

<h2>Tips</h2>

<ul>
<li>Always preview before committing — check for errors and warnings.</li>
<li>The import is idempotent: re-importing the same CSV will skip existing records.</li>
<li>Column headers are case-insensitive and support common synonyms.</li>
</ul>
HTML;
    }

    private function content_classrooms_children() {
        return <<<'HTML'
<h2>Classrooms</h2>

A <strong>classroom</strong> represents a physical classroom at a school. Classrooms have:
<ul>
<li>A <strong>name</strong> (e.g., "Room 101" or "Butterfly Room")</li>
<li>A <strong>school</strong> association</li>
<li>An <strong>age band</strong> (infant, toddler, preschool, mixed)</li>
</ul>

<h3>Teaching assignments</h3>

A <strong>teaching assignment</strong> links an enrolled teacher to a classroom within a specific track. When a teaching assignment is created, HL Core automatically generates [hl_doc_link slug="child-assessment" text="child assessment"] instances for that teacher-classroom pair.

<h2>Children</h2>

Children are the students in a classroom. Each child record includes:
<ul>
<li>First name and last name</li>
<li>Date of birth (used for age group calculation)</li>
<li>Gender</li>
<li>Metadata (JSON — extensible for additional fields)</li>
</ul>

<h3>Classroom history</h3>

When a child moves between classrooms, HL Core tracks the history. The <code>hl_child_classroom_current</code> table shows current assignments, while <code>hl_child_classroom_history</code> records all past and present assignments with effective dates.

<h2>Managing classrooms and children</h2>

<ul>
<li><strong>Admin:</strong> WP Admin > HL Core > Classrooms — full CRUD with detail view, teaching assignments, and children roster.</li>
<li><strong>CSV Import:</strong> Use the "Classrooms" and "Children" import types for bulk creation.</li>
<li><strong>Front-end:</strong> The Classrooms listing page and Classroom detail page provide read access for authorized users.</li>
</ul>
HTML;
    }

    private function content_pathways_overview() {
        return <<<'HTML'
<h2>What is a pathway?</h2>

A <strong>pathway</strong> is a structured sequence of activities that participants must complete. Think of it as the "curriculum" or "program plan" for a track.

Each pathway has:
<ul>
<li>A <strong>name</strong> (e.g., "B2E Mastery Pathway" or "Control Group Assessment Pathway")</li>
<li>A <strong>track</strong> association</li>
<li><strong>Target roles</strong> — which enrollment roles should be auto-assigned this pathway</li>
<li>An ordered list of <strong>activities</strong></li>
</ul>

<h2>Pathway assignments</h2>

Pathways are assigned to enrollments in two ways:

<h3>Role-based (automatic)</h3>
When a pathway has target roles set (e.g., "teacher"), all enrollments with that role automatically get the pathway. This is the default for most programs.

<h3>Explicit (manual)</h3>
You can manually assign/unassign pathways to specific enrollments using the Pathway Assignments section in the pathway editor.

Explicit assignments override role-based defaults.

<h2>Pathway templates</h2>

You can save a pathway as a <strong>template</strong> and use it as a starting point when creating pathways for new tracks. Templates include all activities, prerequisites, and drip rules, with IDs remapped to the new pathway.

<h2>The participant view</h2>

Participants see their assigned pathway as a list of activity cards on the [hl_doc_link slug="understanding-the-dashboard" text="Program Page"]. Each card shows the activity type, status (locked, available, in progress, completed), and an action button.
HTML;
    }

    private function content_activity_types() {
        return <<<'HTML'
<h2>Activity types</h2>

HL Core supports several activity types within a [hl_doc_link slug="pathways-overview" text="pathway"]:

<h3>LearnDash Course</h3>
Links to a LearnDash course. Completion is tracked via LearnDash's own progress system.

<h3>Teacher Self-Assessment</h3>
A custom assessment form powered by HL Core's [hl_doc_link slug="teacher-self-assessment" text="instrument system"]. Can be PRE (single column) or POST (dual-column retrospective).

<h3>Child Assessment</h3>
A per-child assessment matrix using age-group-specific [hl_doc_link slug="child-assessment" text="instruments"].

<h3>Observation</h3>
A mentor-submitted form using JetFormBuilder. See [hl_doc_link slug="observations" text="Observations"] for setup details.

<h3>Coaching Session</h3>
Linked to [hl_doc_link slug="coaching-sessions" text="coaching sessions"]. Completion is managed by coaches when marking sessions as "attended."

<h2>Configuring activities</h2>

When adding an activity to a pathway, you select the type and then configure type-specific options:
<ul>
<li><strong>Course activities</strong> — select a LearnDash course from the dropdown.</li>
<li><strong>Assessment activities</strong> — select an instrument from the dropdown.</li>
<li><strong>Observation activities</strong> — select a JFB form from the dropdown.</li>
</ul>

Each activity can have [hl_doc_link slug="prerequisites-drip-rules" text="prerequisites and drip rules"] that control when it becomes available.
HTML;
    }

    private function content_prerequisites() {
        return <<<'HTML'
<h2>Prerequisites</h2>

<strong>Prerequisites</strong> control which activities must be completed before another activity becomes available. HL Core supports three group types:

<h3>ALL_OF</h3>
Every activity in the group must be completed. Example: "Complete both Course 1 and Course 2 before the Final Assessment."

<h3>ANY_OF</h3>
At least one activity in the group must be completed. Example: "Complete either the Online Workshop or the In-Person Workshop."

<h3>N_OF_M</h3>
A minimum number (N) of activities out of the total (M) must be completed. Example: "Complete at least 3 of 5 coaching sessions."

<h2>Drip rules</h2>

<strong>Drip rules</strong> control <em>when</em> an activity becomes available, independent of prerequisites. Two types:

<h3>Fixed date</h3>
The activity becomes available on a specific date. Example: "POST assessment opens on June 1, 2026."

<h3>Delay after activity</h3>
The activity becomes available a certain number of days after another activity is completed. Example: "POST assessment opens 90 days after PRE assessment is submitted."

<h2>Unlocking logic</h2>

When both prerequisites and drip rules exist, HL Core applies <strong>"most restrictive wins"</strong> — the activity is only available when ALL conditions are satisfied (all prerequisites met AND all drip rules passed).

<h2>Overrides</h2>

Administrators can override unlock restrictions for individual enrollments:
<ul>
<li><strong>Exempt</strong> — permanently bypass the prerequisite</li>
<li><strong>Manual unlock</strong> — one-time unlock</li>
<li><strong>Grace unlock</strong> — unlock with a time limit</li>
</ul>
HTML;
    }

    private function content_completion_reports() {
        return <<<'HTML'
<h2>Overview</h2>

The <strong>Reports</strong> section provides completion tracking and data export capabilities for program administrators and leaders.

<h2>Accessing reports</h2>

Reports are available in two places:
<ul>
<li><strong>WP Admin > HL Core > Reports</strong> — full admin reporting dashboard</li>
<li><strong>Front-end Reports Hub</strong> — accessible from the sidebar for staff and leaders</li>
</ul>

<h2>Report types</h2>

<h3>Completion report</h3>
Shows per-participant completion percentages with filterable columns for cohort, school, district, team, and role. Each row can be expanded to show per-activity status.

<h3>School summary</h3>
Aggregates completion by school — shows average completion, participant count, and completed/in-progress/not-started breakdowns.

<h3>Team summary</h3>
Same as school summary but grouped by team.

<h2>CSV exports</h2>

All reports support CSV download:
<ul>
<li><strong>Completion CSV</strong> — participant metadata + per-activity completion columns</li>
<li><strong>School summary CSV</strong> — school-level aggregation</li>
<li><strong>Team summary CSV</strong> — team-level aggregation</li>
<li><strong>Teacher assessment CSV</strong> — full response data with instrument-derived column headers</li>
<li><strong>Child assessment CSV</strong> — per-child scores with answer columns</li>
</ul>

<h2>Rollup recompute</h2>

The "Recompute Rollups" button recalculates all completion percentages from source data. Use this if completion numbers look stale or after manual data corrections.
HTML;
    }

    private function content_comparison_reports() {
        return <<<'HTML'
<h2>Overview</h2>

When a [hl_doc_link slug="cohorts-vs-tracks" text="Cohort"] contains both a program Track and a [hl_doc_link slug="control-groups" text="control group"] Track, HL Core can generate <strong>comparison reports</strong> that measure program impact.

<h2>How comparison works</h2>

The comparison analyzes Teacher Self-Assessment data from both tracks:
<ul>
<li><strong>Per-section means</strong> — average scores for each assessment section in both program and control groups</li>
<li><strong>Per-item means</strong> — average scores for each individual item</li>
<li><strong>Change values</strong> — difference between PRE and POST scores, color-coded (green for improvement, red for decline)</li>
<li><strong>Cohen's d effect size</strong> — a standardized measure of the program's impact</li>
</ul>

<h2>Accessing comparison reports</h2>

<ol>
<li>Go to <strong>WP Admin > HL Core > Reports</strong>.</li>
<li>Select a <strong>Cohort Group</strong> filter that contains both program and control cohorts.</li>
<li>The comparison section appears automatically below the standard reports.</li>
</ol>

<h2>CSV export</h2>

The comparison data can be exported as a CSV with columns for program means, control means, difference, and Cohen's d for each section and item.
HTML;
    }

    // =========================================================================
    // Glossary Terms
    // =========================================================================

    private function get_glossary_definitions() {
        return array(
            'glossary-cohort' => array(
                'title'   => 'Cohort',
                'content' => 'A contract-level container entity that represents a program partnership. A Cohort holds one or more Tracks. Example: "B2E Mastery - Palm Beach County 2026." See [hl_doc_link slug="cohorts-vs-tracks"] for details.',
            ),
            'glossary-track' => array(
                'title'   => 'Track',
                'content' => 'A time-bounded run within a Cohort. Tracks hold enrollments, schools, pathways, teams, and coaching assignments. Most Cohorts have one Track, but research designs may include both a program Track and a control Track. The front-end shows "Program" instead of "Track" to participants.',
            ),
            'glossary-enrollment' => array(
                'title'   => 'Enrollment',
                'content' => 'A record linking a WordPress user to a specific Track with one or more roles (teacher, mentor, school_leader, district_leader). Enrollment roles are stored per-track, not as WordPress roles. See [hl_doc_link slug="enrollments-and-roles"].',
            ),
            'glossary-pathway' => array(
                'title'   => 'Pathway',
                'content' => 'A structured sequence of activities (the curriculum) assigned to participants within a Track. Pathways can be assigned by role (automatic) or explicitly (manual). See [hl_doc_link slug="pathways-overview"].',
            ),
            'glossary-activity' => array(
                'title'   => 'Activity',
                'content' => 'A single step within a Pathway — can be a LearnDash course, teacher self-assessment, child assessment, observation, or coaching session. Activities can have prerequisites and drip rules.',
            ),
            'glossary-instrument' => array(
                'title'   => 'Instrument',
                'content' => 'The definition of an assessment form — its sections, items, and scales. HL Core has two instrument types: Teacher Assessment Instruments (for self-assessments) and Child Assessment Instruments (for child assessments). See [hl_doc_link slug="assessment-instruments-versioning"].',
            ),
            'glossary-drip-rule' => array(
                'title'   => 'Drip Rule',
                'content' => 'A time-based rule controlling when an activity becomes available. Can be a fixed date or a delay after another activity is completed. Works alongside prerequisites using "most restrictive wins" logic. See [hl_doc_link slug="prerequisites-drip-rules"].',
            ),
            'glossary-prerequisite' => array(
                'title'   => 'Prerequisite',
                'content' => 'A requirement that one or more activities must be completed before another becomes available. Supports ALL_OF, ANY_OF, and N_OF_M group types. See [hl_doc_link slug="prerequisites-drip-rules"].',
            ),
            'glossary-control-group' => array(
                'title'   => 'Control Group',
                'content' => 'A Track where participants only complete assessments (no courses, coaching, or observations). Used for research purposes to measure program impact by comparison. See [hl_doc_link slug="control-groups"].',
            ),
            'glossary-coach-assignment' => array(
                'title'   => 'Coach Assignment',
                'content' => 'A record linking a Housman coach to a scope (school, team, or enrollment). Uses "most specific wins" resolution — an enrollment-level assignment overrides team, which overrides school. See [hl_doc_link slug="coach-assignments"].',
            ),
            'glossary-observation' => array(
                'title'   => 'Observation',
                'content' => 'A mentor-submitted form documenting a classroom visit. Uses JetFormBuilder for the form editor so admins can customize questions. See [hl_doc_link slug="observations"].',
            ),
            'glossary-coaching-session' => array(
                'title'   => 'Coaching Session',
                'content' => 'A scheduled meeting between a Housman coach and a teacher. Tracks attendance, notes, linked observations, and attachments. See [hl_doc_link slug="coaching-sessions"].',
            ),
            'glossary-completion-rollup' => array(
                'title'   => 'Completion Rollup',
                'content' => 'An aggregated completion percentage for an enrollment, calculated as a weighted average across all assigned activities. Stored in the hl_completion_rollup table and recomputed when activity states change.',
            ),
            'glossary-scope' => array(
                'title'   => 'Scope',
                'content' => 'The set of data a user is allowed to see, determined by their roles and assignments. Admin sees everything; a school leader sees only their school; a mentor sees only their team. Managed by the HL_Scope_Service.',
            ),
            'glossary-teaching-assignment' => array(
                'title'   => 'Teaching Assignment',
                'content' => 'A record linking an enrolled teacher to a classroom within a specific Track. Teaching assignments trigger automatic generation of child assessment instances for that teacher-classroom pair.',
            ),
        );
    }
}
