<?php
/**
 * Email Module Completion — Phase 3 safety-net tests.
 *
 * Covers the two tooling-safety improvements that landed in Phase 3:
 *   (A) Body-render lint at admin save
 *       — `HL_Admin_Email_Builder::lint_template_merge_tags()` returns
 *         only the tags that are NOT in the registry; legitimate tags
 *         (including deferred ones like `password_reset_url`) are
 *         allowlisted via the fixture context.
 *   (B) Seeder template-category clobber guard
 *       — `bin/seed-email-workflows.php` no longer overwrites an
 *         admin-edited `category` on UPDATE. Fresh rows still get
 *         the seeder's default category on INSERT.
 *
 * Run via:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-email-phase3.php
 *
 * Exit 0 = all pass, exit 1 = one or more failed.
 *
 * Fixture rows are prefixed with FIXTURE_PREFIX and torn down in a
 * finally block so a mid-test exception cannot leave orphans.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file\n" );
    exit( 1 );
}

// Lazy-load admin class (not auto-loaded under WP-CLI).
if ( ! class_exists( 'HL_Admin_Email_Builder' ) ) {
    if ( defined( 'HL_CORE_INCLUDES_DIR' ) ) {
        $candidate = HL_CORE_INCLUDES_DIR . 'admin/class-hl-admin-email-builder.php';
        if ( file_exists( $candidate ) ) {
            require_once $candidate;
        }
    }
}

const HL_P3_FIXTURE_PREFIX = '[Phase3Test]';

$GLOBALS['hl_p3_pass']   = 0;
$GLOBALS['hl_p3_fail']   = 0;
$GLOBALS['hl_p3_errors'] = array();
$GLOBALS['hl_p3_ids']    = array(
    'template_keys' => array(),
);

function hl_p3_assert( $cond, $label ) {
    if ( $cond ) {
        $GLOBALS['hl_p3_pass']++;
        echo "  [PASS] $label\n";
    } else {
        $GLOBALS['hl_p3_fail']++;
        $GLOBALS['hl_p3_errors'][] = $label;
        echo "  [FAIL] $label\n";
    }
}

function hl_p3_cleanup() {
    global $wpdb;
    foreach ( $GLOBALS['hl_p3_ids']['template_keys'] as $key ) {
        $wpdb->delete( $wpdb->prefix . 'hl_email_template', array( 'template_key' => $key ) );
    }
    $GLOBALS['hl_p3_ids']['template_keys'] = array();
}

// ──────────────────────────────────────────────────────────────────────────
// Tests
// ──────────────────────────────────────────────────────────────────────────

try {

    echo "\n╔══════════════════════════════════════════════════════════╗\n";
    echo   "║ Email Module Completion Phase 3 — safety-net tests        ║\n";
    echo   "╚══════════════════════════════════════════════════════════╝\n";

    // =========================================================================
    // (A) Body-render lint at admin save
    // =========================================================================
    echo "\n=== (A) Body-render lint: lint_template_merge_tags() ===\n";

    if ( ! method_exists( 'HL_Admin_Email_Builder', 'lint_template_merge_tags' ) ) {
        hl_p3_assert( false, 'HL_Admin_Email_Builder::lint_template_merge_tags() exists' );
    } else {
        hl_p3_assert( true, 'HL_Admin_Email_Builder::lint_template_merge_tags() exists' );

        // A.1 — Deliberately-broken template flags the typo and NOTHING else.
        $typo_blocks = array(
            array(
                'type'    => 'text',
                'content' => 'Hello {{recipient_first_name}}, your {{pahtway_name}} is ready.',
            ),
            array(
                'type'    => 'button',
                'label'   => 'Open Dashboard',
                'url'     => '{{dashboard_url}}',
            ),
        );
        $typo_subject    = 'Welcome to {{cycle_name}}';
        $typo_unresolved = HL_Admin_Email_Builder::lint_template_merge_tags( $typo_blocks, $typo_subject );
        hl_p3_assert(
            is_array( $typo_unresolved ) && in_array( 'pahtway_name', $typo_unresolved, true ),
            'lint flags deliberate typo `pahtway_name`'
        );
        hl_p3_assert(
            ! in_array( 'recipient_first_name', $typo_unresolved, true )
                && ! in_array( 'dashboard_url', $typo_unresolved, true )
                && ! in_array( 'cycle_name', $typo_unresolved, true ),
            'lint does NOT flag valid tags used alongside the typo'
        );

        // A.2 — Clean template produces zero warnings.
        $clean_blocks = array(
            array(
                'type'    => 'text',
                'content' => 'Hello {{recipient_first_name}}, welcome to {{pathway_name}}.',
            ),
            array(
                'type'  => 'button',
                'label' => 'Go to Dashboard',
                'url'   => '{{dashboard_url}}',
            ),
        );
        $clean_subject    = '{{cycle_name}} — welcome';
        $clean_unresolved = HL_Admin_Email_Builder::lint_template_merge_tags( $clean_blocks, $clean_subject );
        hl_p3_assert(
            is_array( $clean_unresolved ) && count( $clean_unresolved ) === 0,
            'lint returns empty array for an all-valid-tag template'
        );

        // A.3 — Deferred tag (`password_reset_url`) must NOT be flagged.
        // Registered so it appears in the builder, resolved at send-time by
        // the queue processor. A false positive here would train admins to
        // ignore warnings.
        $deferred_blocks    = array(
            array(
                'type'    => 'text',
                'content' => 'Reset your password: {{password_reset_url}}',
            ),
        );
        $deferred_unresolved = HL_Admin_Email_Builder::lint_template_merge_tags( $deferred_blocks, '' );
        hl_p3_assert(
            is_array( $deferred_unresolved ) && ! in_array( 'password_reset_url', $deferred_unresolved, true ),
            'lint does NOT flag the deferred `password_reset_url` tag'
        );

        // A.4 — Multiple typos all surface (not just the first).
        $multi_typo_blocks = array(
            array(
                'type'    => 'text',
                'content' => '{{wrng_tag_one}} and {{another_bad_tag}} and {{recipient_first_name}}.',
            ),
        );
        $multi_unresolved = HL_Admin_Email_Builder::lint_template_merge_tags( $multi_typo_blocks, '' );
        hl_p3_assert(
            in_array( 'wrng_tag_one',     $multi_unresolved, true )
                && in_array( 'another_bad_tag', $multi_unresolved, true ),
            'lint surfaces ALL typos, not just the first'
        );

        // A.5 — Subject-only typo is also flagged (subject is part of scan source).
        $subj_unresolved = HL_Admin_Email_Builder::lint_template_merge_tags(
            array( array( 'type' => 'text', 'content' => 'No tags here.' ) ),
            'Hello {{typo_in_subject}}'
        );
        hl_p3_assert(
            in_array( 'typo_in_subject', $subj_unresolved, true ),
            'lint scans the subject line, not just blocks'
        );

        // A.6 — Empty/edge inputs do not throw and return an empty array.
        $empty_unresolved = HL_Admin_Email_Builder::lint_template_merge_tags( array(), '' );
        hl_p3_assert(
            is_array( $empty_unresolved ) && count( $empty_unresolved ) === 0,
            'lint handles empty blocks+subject without throwing'
        );

        // A.7 — Dedup: same typo twice still yields one entry.
        $dup_unresolved = HL_Admin_Email_Builder::lint_template_merge_tags(
            array( array( 'type' => 'text', 'content' => '{{xtypo}} and {{xtypo}} again' ) ),
            '{{xtypo}}'
        );
        $xtypo_count = 0;
        foreach ( $dup_unresolved as $t ) { if ( $t === 'xtypo' ) $xtypo_count++; }
        hl_p3_assert( $xtypo_count === 1, 'lint deduplicates repeated typos' );
    }

    // =========================================================================
    // (B) Seeder template-category clobber guard
    // =========================================================================
    echo "\n=== (B) Seeder UPDATE: preserves admin-edited category ===\n";

    global $wpdb;
    $tpl_table = $wpdb->prefix . 'hl_email_template';

    // Simulate the seeder's template-upsert branch directly (lines ~736-775
    // of bin/seed-email-workflows.php). We don't invoke the full seed script
    // because that would require valid cycle/trigger fixtures; the clobber
    // logic is isolated to the UPDATE payload.
    $tpl_key = 'p3test_tpl_' . wp_generate_password( 8, false, false );
    $GLOBALS['hl_p3_ids']['template_keys'][] = $tpl_key;

    // Step 1: first-run INSERT — category should land from the seeder row.
    $seeder_row = array(
        'template_key' => $tpl_key,
        'name'         => HL_P3_FIXTURE_PREFIX . ' Seeder Template',
        'subject'      => 'Seeder Subject v1',
        'blocks_json'  => wp_json_encode( array( array( 'type' => 'text', 'content' => 'v1 body' ) ) ),
        'category'     => 'automated',  // seeder's default category
        'merge_tags'   => null,
        'status'       => 'draft',
    );
    $wpdb->insert( $tpl_table, $seeder_row );
    $insert_id = (int) $wpdb->insert_id;
    hl_p3_assert( $insert_id > 0, 'seeder INSERT creates row' );

    $inserted = $wpdb->get_row( $wpdb->prepare(
        "SELECT category, status, subject FROM $tpl_table WHERE template_id = %d",
        $insert_id
    ) );
    hl_p3_assert(
        $inserted && $inserted->category === 'automated',
        'INSERT: category lands as "automated" on fresh row'
    );
    hl_p3_assert(
        $inserted && $inserted->status === 'draft',
        'INSERT: status lands as "draft" on fresh row'
    );

    // Step 2: admin edits category + status in UI.
    $wpdb->update(
        $tpl_table,
        array( 'category' => 'admin-edited-test', 'status' => 'active' ),
        array( 'template_id' => $insert_id )
    );
    $after_edit = $wpdb->get_row( $wpdb->prepare(
        "SELECT category, status FROM $tpl_table WHERE template_id = %d",
        $insert_id
    ) );
    hl_p3_assert(
        $after_edit && $after_edit->category === 'admin-edited-test',
        'admin UPDATE sets category to "admin-edited-test"'
    );

    // Step 3: second-run UPDATE — replicate the seeder's clobber-guard
    // (bin/seed-email-workflows.php: unset status + category before UPDATE).
    // The subject SHOULD be overwritten (seeder is source of truth for copy),
    // but category + status must be preserved.
    $reseed_row = array(
        'template_key' => $tpl_key,
        'name'         => HL_P3_FIXTURE_PREFIX . ' Seeder Template',
        'subject'      => 'Seeder Subject v2',  // changed — must land
        'blocks_json'  => wp_json_encode( array( array( 'type' => 'text', 'content' => 'v2 body' ) ) ),
        'category'     => 'automated', // unset by the guard before UPDATE
        'merge_tags'   => null,
        'status'       => 'draft',     // unset by the guard before UPDATE
    );
    $update_payload = $reseed_row;
    unset( $update_payload['status'], $update_payload['category'] );
    $wpdb->update( $tpl_table, $update_payload, array( 'template_id' => $insert_id ) );

    $after_reseed = $wpdb->get_row( $wpdb->prepare(
        "SELECT category, status, subject FROM $tpl_table WHERE template_id = %d",
        $insert_id
    ) );
    hl_p3_assert(
        $after_reseed && $after_reseed->category === 'admin-edited-test',
        'RE-SEED UPDATE: category stays "admin-edited-test" (not clobbered)'
    );
    hl_p3_assert(
        $after_reseed && $after_reseed->status === 'active',
        'RE-SEED UPDATE: status stays "active" (not reset to draft)'
    );
    hl_p3_assert(
        $after_reseed && $after_reseed->subject === 'Seeder Subject v2',
        'RE-SEED UPDATE: subject is overwritten (seeder-owned copy updates)'
    );

    // Step 4: confirm the seeder file on-disk still carries the guard
    // (tripwire: if someone deletes the unset() line, this test fails).
    $seeder_path = dirname( __DIR__ ) . '/bin/seed-email-workflows.php';
    if ( is_readable( $seeder_path ) ) {
        $seeder_src = file_get_contents( $seeder_path );
        hl_p3_assert(
            strpos( $seeder_src, "unset( \$tpl_update_row['status'], \$tpl_update_row['category'] )" ) !== false,
            'seeder source still carries template UPDATE clobber-guard'
        );
        hl_p3_assert(
            strpos( $seeder_src, "unset( \$wf_update_row['status'] )" ) !== false,
            'seeder source still carries workflow UPDATE clobber-guard'
        );
    } else {
        hl_p3_assert( false, 'seeder file unreadable for tripwire check' );
    }

    echo "\n--- ";
    $pass   = $GLOBALS['hl_p3_pass'];
    $fail   = $GLOBALS['hl_p3_fail'];
    $errors = $GLOBALS['hl_p3_errors'];
    echo "RESULTS: {$pass} passed, {$fail} failed ---\n";

    if ( $fail > 0 ) {
        echo "\nFAILURES:\n";
        foreach ( $errors as $e ) echo "  - $e\n";
    }

} finally {
    echo "\nCleaning up fixtures...\n";
    hl_p3_cleanup();
}

exit( $GLOBALS['hl_p3_fail'] > 0 ? 1 : 0 );
