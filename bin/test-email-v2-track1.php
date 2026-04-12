<?php
/**
 * Email System v2 — Track 1 smoke tests.
 *
 * Run via:
 *   wp --path=/opt/bitnami/wordpress eval-file \
 *     wp-content/plugins/hl-core/bin/test-email-v2-track1.php
 *
 * Exit code 0 = all pass. Exit code 1 = one or more failures.
 *
 * Note: HL_Roles assertions live in Track 3's test harness, not here.
 * This script only covers Track 1 surface area: static registries,
 * generate_copy_name, operator_label, validate_workflow_payload.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file\n" );
    exit( 1 );
}

// HL_Admin_Emails is loaded inside is_admin() in hl-core.php,
// but wp eval-file does not set is_admin(). Require it directly.
if ( ! class_exists( 'HL_Admin_Emails' ) ) {
    $plugin_dir = dirname( __DIR__ ) . '/';
    require_once $plugin_dir . 'includes/admin/class-hl-admin-emails.php';
}

$GLOBALS['hl_t1_pass']   = 0;
$GLOBALS['hl_t1_fail']   = 0;
$GLOBALS['hl_t1_errors'] = array();

function hl_t_assert( $cond, $label ) {
    if ( $cond ) {
        $GLOBALS['hl_t1_pass']++;
        echo "  [PASS] $label\n";
    } else {
        $GLOBALS['hl_t1_fail']++;
        $GLOBALS['hl_t1_errors'][] = $label;
        echo "  [FAIL] $label\n";
    }
}

echo "\n=== HL_Admin_Emails registries ===\n";
$fields    = HL_Admin_Emails::get_condition_fields();
$operators = HL_Admin_Emails::get_condition_operators();
$tokens    = HL_Admin_Emails::get_recipient_tokens();

hl_t_assert( isset( $fields['cycle.cycle_type']['type'] ),          'fields: cycle.cycle_type defined' );
hl_t_assert( isset( $fields['enrollment.roles']['type'] ),          'fields: enrollment.roles defined' );
hl_t_assert( $fields['enrollment.roles']['type'] === 'enum',        'fields: enrollment.roles is enum type' );
hl_t_assert( isset( $operators['enum'] ) && isset( $operators['boolean'] ), 'operators: enum + boolean defined' );
hl_t_assert( in_array( 'in', array_keys( $operators['enum'] ), true ), 'operators: enum has in' );
hl_t_assert( ! in_array( 'in', array_keys( $operators['boolean'] ), true ), 'operators: boolean does NOT have in' );
hl_t_assert( isset( $tokens['assigned_mentor'] ),                   'tokens: assigned_mentor defined' );
hl_t_assert( isset( $tokens['observed_teacher'] ),                  'tokens: observed_teacher defined' );
hl_t_assert( ! isset( $tokens['cc_teacher'] ),                      'tokens: cc_teacher NOT in registry (legacy alias only)' );

echo "\n=== generate_copy_name ===\n";
// This asserts the helper produces deterministic "(Copy)" suffixes.
$name1 = HL_Admin_Emails::generate_copy_name( 'hl_email_workflow', 'Welcome Email' );
hl_t_assert( strpos( $name1, 'Welcome Email' ) === 0,               'generate_copy_name starts with source' );
hl_t_assert( strpos( $name1, '(Copy' ) !== false,                   'generate_copy_name contains (Copy' );

echo "\n=== operator_label ===\n";
hl_t_assert( HL_Admin_Emails::operator_label( 'in' ) === 'matches any of', 'operator_label: in -> matches any of' );
hl_t_assert( HL_Admin_Emails::operator_label( 'eq' ) === 'equals',         'operator_label: eq -> equals' );

echo "\n=== validate_workflow_payload ===\n";
$valid = HL_Admin_Emails::validate_workflow_payload(
    array( array( 'field' => 'cycle.cycle_type', 'op' => 'eq', 'value' => 'program' ) ),
    array( 'primary' => array( 'triggering_user' ), 'cc' => array() )
);
hl_t_assert( $valid === true, 'validate_workflow_payload accepts known field/op/token' );

$invalid = HL_Admin_Emails::validate_workflow_payload(
    array( array( 'field' => 'evil.field', 'op' => 'eq', 'value' => 'x' ) ),
    array( 'primary' => array(), 'cc' => array() )
);
hl_t_assert( is_wp_error( $invalid ), 'validate_workflow_payload rejects unknown field' );

$invalid2 = HL_Admin_Emails::validate_workflow_payload(
    array(),
    array( 'primary' => array( 'hacker_token' ), 'cc' => array() )
);
hl_t_assert( is_wp_error( $invalid2 ), 'validate_workflow_payload rejects unknown recipient token' );

echo "\n---\n";
$pass   = $GLOBALS['hl_t1_pass'];
$fail   = $GLOBALS['hl_t1_fail'];
$errors = $GLOBALS['hl_t1_errors'];
echo "RESULTS: {$pass} passed, {$fail} failed\n";

if ( $fail > 0 ) {
    echo "\nFAILURES:\n";
    foreach ( $errors as $e ) echo "  - $e\n";
    exit( 1 );
}
exit( 0 );
