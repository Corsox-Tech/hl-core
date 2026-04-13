<?php
/**
 * Email Block Renderer — Track 2 test harness.
 *
 * Plain PHP assertions (no PHPUnit). Invoked via:
 *   wp hl-core test-email-renderer
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HL_Test_Email_Block_Renderer {

    /** @var int Passed assertion count. */
    public static $passed = 0;

    /** @var array Failure messages. */
    public static $failures = array();

    /**
     * Run all test methods. Returns true if all passed.
     *
     * @return bool
     */
    public static function run_all() {
        self::$passed   = 0;
        self::$failures = array();

        self::test_text_default_has_no_align_override();
        self::test_text_center_emits_inline_td_style();
        self::test_text_right_with_font_size_emits_both();
        self::test_text_invalid_values_fall_back_to_defaults();
        self::test_text_font_size_emits_on_inner_span_for_outlook();
        self::test_columns_split_50_50();
        self::test_columns_split_60_40();
        self::test_columns_split_40_60();
        self::test_columns_split_33_67();
        self::test_columns_split_67_33();
        self::test_columns_invalid_split_falls_back_to_50_50();

        return empty( self::$failures );
    }

    /** Assertion helper: substring contained. */
    private static function assert_contains( $haystack, $needle, $label ) {
        if ( strpos( $haystack, $needle ) !== false ) {
            self::$passed++;
            return;
        }
        self::$failures[] = $label . ' — expected to find: ' . $needle;
    }

    /** Assertion helper: substring NOT contained. */
    private static function assert_not_contains( $haystack, $needle, $label ) {
        if ( strpos( $haystack, $needle ) === false ) {
            self::$passed++;
            return;
        }
        self::$failures[] = $label . ' — did NOT expect: ' . $needle;
    }

    /** Load a fixture by key from the samples file. */
    private static function fixture( $key ) {
        $path = dirname( __DIR__ ) . '/docs/superpowers/fixtures/email-track2-samples.json';
        $json = file_get_contents( $path );
        $all  = json_decode( $json, true );
        return $all[ $key ] ?? array();
    }

    /** Render a block array to HTML (blocks-only, no shell). */
    private static function render( array $blocks ) {
        $renderer = HL_Email_Block_Renderer::instance();
        return $renderer->render_blocks_only( $blocks, array() );
    }

    // =====================================================================
    // Text block assertions
    // =====================================================================

    public static function test_text_default_has_no_align_override() {
        $html = self::render( self::fixture( 'text_default' ) );
        // Default alignment: the <td> must NOT contain "text-align:center" or "text-align:right".
        self::assert_not_contains( $html, 'text-align:center', 'text_default' );
        self::assert_not_contains( $html, 'text-align:right',  'text_default' );
    }

    public static function test_text_center_emits_inline_td_style() {
        $html = self::render( self::fixture( 'text_aligned_center' ) );
        self::assert_contains( $html, 'text-align:center', 'text_aligned_center' );
    }

    public static function test_text_right_with_font_size_emits_both() {
        $html = self::render( self::fixture( 'text_aligned_right_sized' ) );
        self::assert_contains( $html, 'text-align:right', 'text_aligned_right_sized align' );
        self::assert_contains( $html, 'font-size:20px',   'text_aligned_right_sized size' );
    }

    public static function test_text_invalid_values_fall_back_to_defaults() {
        $html = self::render( self::fixture( 'text_invalid_align' ) );
        // text_align "justify" not in allowlist → falls back to left (no alignment style emitted)
        self::assert_not_contains( $html, 'text-align:justify', 'text_invalid_align' );
        // font_size 999 clamped to 48
        self::assert_contains( $html, 'font-size:48px', 'text_invalid_align clamp' );
    }

    public static function test_text_font_size_emits_on_inner_span_for_outlook() {
        // A.3.1: Outlook Word-engine ignores <td> font-size — require inner <span>.
        $html = self::render( self::fixture( 'text_aligned_right_sized' ) );
        self::assert_contains( $html, '<span style="font-size:20px', 'text inner span for outlook' );
    }

    // =====================================================================
    // Columns block assertions
    // =====================================================================

    public static function test_columns_split_50_50() {
        $html = self::render( array(
            array( 'type' => 'columns', 'split' => '50/50', 'left' => array(), 'right' => array() )
        ) );
        self::assert_contains( $html, 'width:50%', 'columns 50/50' );
    }

    public static function test_columns_split_60_40() {
        $html = self::render( self::fixture( 'columns_60_40' ) );
        self::assert_contains( $html, 'width:60%', 'columns 60/40 left' );
        self::assert_contains( $html, 'width:40%', 'columns 60/40 right' );
    }

    public static function test_columns_split_40_60() {
        $html = self::render( array(
            array( 'type' => 'columns', 'split' => '40/60', 'left' => array(), 'right' => array() )
        ) );
        self::assert_contains( $html, 'width:40%', 'columns 40/60 left' );
        self::assert_contains( $html, 'width:60%', 'columns 40/60 right' );
    }

    public static function test_columns_split_33_67() {
        $html = self::render( self::fixture( 'columns_33_67' ) );
        self::assert_contains( $html, 'width:33%', 'columns 33/67 left' );
        self::assert_contains( $html, 'width:67%', 'columns 33/67 right' );
    }

    public static function test_columns_split_67_33() {
        $html = self::render( array(
            array( 'type' => 'columns', 'split' => '67/33', 'left' => array(), 'right' => array() )
        ) );
        self::assert_contains( $html, 'width:67%', 'columns 67/33 left' );
        self::assert_contains( $html, 'width:33%', 'columns 67/33 right' );
    }

    public static function test_columns_invalid_split_falls_back_to_50_50() {
        $html = self::render( self::fixture( 'columns_invalid_split' ) );
        self::assert_contains( $html, 'width:50%', 'columns invalid split fallback' );
    }
}
