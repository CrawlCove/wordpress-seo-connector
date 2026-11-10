<?php
/**
 * /indexnow-key: store an IndexNow key the desktop app chose and serve it at
 * https://site/<key>.txt, so the app can ask Bing and Yandex to recrawl a URL
 * once its fix is verified. The plugin itself never contacts IndexNow or any
 * other service: the app sends the ping, this only proves the site owns the key.
 *
 * The key file is answered by WordPress, not written to disk, so it needs the
 * web server to hand unknown paths to WordPress: true on nginx and LiteSpeed
 * with the usual WordPress config, and on Apache once pretty permalinks have
 * written WordPress's rewrite block to .htaccess.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * IndexNow key storage and the key file.
 */
class CCC_IndexNow {

	const OPTION = 'ccc_indexnow_key';

	/**
	 * The stored key, or '' when none is set.
	 *
	 * @return string
	 */
	public static function get_key() {
		$key = get_option( self::OPTION, '' );
		return is_string( $key ) && self::valid( $key ) ? $key : '';
	}

	/**
	 * IndexNow's key format: 8 to 128 characters of a-z, A-Z, 0-9 and '-'.
	 *
	 * @param mixed $key Candidate key.
	 * @return bool
	 */
	public static function valid( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[a-zA-Z0-9-]{8,128}$/', $key );
	}

	/**
	 * Where the key file is served.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function key_location( $key ) {
		return home_url( '/' . $key . '.txt' );
	}

	/**
	 * The reply GET and POST give.
	 *
	 * @param string $key Key.
	 * @return array { key, keyLocation }
	 */
	public static function describe( $key ) {
		return array(
			'key'         => $key,
			'keyLocation' => self::key_location( $key ),
		);
	}

	/**
	 * Hook the key file only when a key is set, so a site that never opted in
	 * pays one autoloaded option read and nothing else.
	 */
	public static function enforce() {
		if ( '' !== self::get_key() ) {
			add_action( 'init', array( __CLASS__, 'maybe_serve' ), 0 );
		}
	}

	/**
	 * Answer the key file request, before WordPress routes it (and before
	 * redirect_canonical could add a trailing slash).
	 */
	public static function maybe_serve() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared with a fixed path, never stored or printed.
		$key = self::get_key();
		if ( '' === $key || ! self::is_key_path( $uri, $key ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $key );
		exit;
	}

	/**
	 * Whether a request URI asks for the key file. Query strings are ignored;
	 * the path must match exactly, under the site's home path.
	 *
	 * @param string $uri Request URI.
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_key_path( $uri, $key ) {
		$path = wp_parse_url( (string) $uri, PHP_URL_PATH );
		$want = wp_parse_url( self::key_location( $key ), PHP_URL_PATH );
		return is_string( $path ) && $path === $want;
	}

	/**
	 * GET /indexnow-key.
	 *
	 * @return array|WP_Error { key, keyLocation }, or 404 when none is set.
	 */
	public static function read() {
		$key = self::get_key();
		if ( '' === $key ) {
			return new WP_Error( 'ccc_no_indexnow_key', __( 'No IndexNow key is set on this site.', 'crawl-cove-connector' ), array( 'status' => 404 ) );
		}
		return self::describe( $key );
	}

	/**
	 * POST /indexnow-key: store (or replace) the key. Administrators only,
	 * since it makes the site serve a file at its root.
	 *
	 * @param mixed $key Key from the request.
	 * @return array|WP_Error { key, keyLocation }
	 */
	public static function store( $key ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::forbidden();
		}
		if ( ! self::valid( $key ) ) {
			return new WP_Error( 'ccc_bad_indexnow_key', __( 'The key must be 8 to 128 characters of letters, digits and hyphens.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}
		update_option( self::OPTION, $key, true );
		return self::describe( $key );
	}

	/**
	 * DELETE /indexnow-key: stop serving the key file. Administrators only.
	 *
	 * @return array|WP_Error { deleted: bool } (false when no key was set).
	 */
	public static function remove() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::forbidden();
		}
		$had = '' !== self::get_key();
		delete_option( self::OPTION );
		return array( 'deleted' => $had );
	}

	/**
	 * The refusal for a non-administrator.
	 *
	 * @return WP_Error
	 */
	private static function forbidden() {
		return new WP_Error( 'ccc_forbidden', __( 'Only an administrator can change the IndexNow key.', 'crawl-cove-connector' ), array( 'status' => 403 ) );
	}
}
