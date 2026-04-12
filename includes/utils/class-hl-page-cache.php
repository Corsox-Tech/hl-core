<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Page Cache — shortcode-to-page-ID mapping with persistent cache.
 *
 * Eliminates ~24 duplicate LIKE queries against wp_posts on every frontend
 * page load. Stores a single `hl_shortcode_page_map` option (autoload=no)
 * mapping shortcode tags to page IDs. The map is rebuilt lazily on first
 * miss and invalidated whenever a page is saved.
 *
 * IMPORTANT: only page IDs are cached, never URLs. This preserves WPML
 * language-switch correctness — `wpml_object_id` and `get_permalink()` are
 * applied at call time inside `get_url()`.
 *
 * @package HL_Core
 */
class HL_Page_Cache {

	/** @var string wp_options key */
	const OPTION_KEY = 'hl_shortcode_page_map';

	/** @var array|null Persistent map loaded once per request. */
	private static $map = null;

	/** @var array Per-request URL cache keyed by shortcode + WPML language. */
	private static $url_cache = array();

	/**
	 * Boot hook registrations. Called once from the main plugin loader.
	 */
	public static function init() {
		add_action( 'save_post_page', array( __CLASS__, 'invalidate' ), 10, 0 );
		add_action( 'trashed_post',   array( __CLASS__, 'invalidate_if_page' ), 10, 1 );
	}

	/**
	 * Get the permalink for the page containing a given shortcode tag.
	 *
	 * WPML-aware: applies `wpml_object_id` to translate the page ID into
	 * the current language, then calls `get_permalink()` for the final URL.
	 * Results are cached per-request keyed by shortcode + language code.
	 *
	 * @param string $shortcode Shortcode tag (e.g. 'hl_dashboard').
	 * @return string Page URL or empty string if not found.
	 */
	public static function get_url( $shortcode ) {
		$lang      = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : 'en';
		$cache_key = $shortcode . '|' . $lang;

		if ( isset( self::$url_cache[ $cache_key ] ) ) {
			return self::$url_cache[ $cache_key ];
		}

		$page_id = self::get_id( $shortcode );
		if ( ! $page_id ) {
			self::$url_cache[ $cache_key ] = '';
			return '';
		}

		// WPML translation at call time — never cache the translated ID.
		$translated_id = apply_filters( 'wpml_object_id', $page_id, 'page', true );
		$url = get_permalink( $translated_id );

		self::$url_cache[ $cache_key ] = $url ?: '';
		return self::$url_cache[ $cache_key ];
	}

	/**
	 * Get the page ID for the page containing a given shortcode tag.
	 *
	 * Reads from the persistent option map. On cache miss for a given
	 * shortcode, falls back to a single SQL LIKE query and writes the
	 * result back to the map.
	 *
	 * @param string $shortcode Shortcode tag (e.g. 'hl_dashboard').
	 * @return int Page ID or 0 if not found.
	 */
	public static function get_id( $shortcode ) {
		self::load_map();

		if ( isset( self::$map[ $shortcode ] ) ) {
			return (int) self::$map[ $shortcode ];
		}

		// Cache miss — query DB and persist.
		$page_id = self::query_shortcode_page( $shortcode );
		self::$map[ $shortcode ] = $page_id;
		self::save_map();

		return $page_id;
	}

	/**
	 * Invalidate the entire persistent map.
	 *
	 * Hooked to `save_post_page` — fires whenever any page is created,
	 * updated, published, or trashed. Also clears per-request caches.
	 */
	public static function invalidate() {
		delete_option( self::OPTION_KEY );
		self::$map       = null;
		self::$url_cache = array();
	}

	/**
	 * Invalidate only if the trashed post was a page.
	 *
	 * @param int $post_id Post ID being trashed.
	 */
	public static function invalidate_if_page( $post_id ) {
		if ( get_post_type( $post_id ) === 'page' ) {
			self::invalidate();
		}
	}

	// =========================================================================
	// Internal
	// =========================================================================

	/**
	 * Load the persistent map from wp_options (once per request).
	 */
	private static function load_map() {
		if ( self::$map !== null ) {
			return;
		}

		$stored = get_option( self::OPTION_KEY, null );
		if ( is_array( $stored ) ) {
			self::$map = $stored;
		} else {
			self::$map = array();
		}
	}

	/**
	 * Persist the map to wp_options (autoload=no).
	 */
	private static function save_map() {
		update_option( self::OPTION_KEY, self::$map, false );
	}

	/**
	 * SQL fallback: find the page ID containing a shortcode via LIKE query.
	 *
	 * @param string $shortcode Shortcode tag.
	 * @return int Page ID or 0.
	 */
	private static function query_shortcode_page( $shortcode ) {
		global $wpdb;
		$page_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'page'
			   AND post_status = 'publish'
			   AND post_content LIKE %s
			 LIMIT 1",
			'%[' . $wpdb->esc_like( $shortcode ) . '%'
		) );
		return $page_id ? (int) $page_id : 0;
	}
}
