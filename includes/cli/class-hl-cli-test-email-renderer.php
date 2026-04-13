<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CLI command: run the email block renderer test harness.
 *
 * Usage:
 *   wp hl-core test-email-renderer
 *
 * @package HL_Core
 */
class HL_CLI_Test_Email_Renderer {

    public static function register() {
        WP_CLI::add_command( 'hl-core test-email-renderer', array( new self(), 'run' ) );
    }

    public function run() {
        require_once dirname( __DIR__, 2 ) . '/tests/test-email-block-renderer.php';

        $ok = HL_Test_Email_Block_Renderer::run_all();

        WP_CLI::line( sprintf( 'Passed: %d', HL_Test_Email_Block_Renderer::$passed ) );

        if ( ! $ok ) {
            foreach ( HL_Test_Email_Block_Renderer::$failures as $msg ) {
                WP_CLI::warning( $msg );
            }
            WP_CLI::error( sprintf( 'FAIL: %d failure(s).', count( HL_Test_Email_Block_Renderer::$failures ) ) );
        }

        WP_CLI::success( 'All renderer assertions passed.' );
    }
}

HL_CLI_Test_Email_Renderer::register();
