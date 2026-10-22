<?php
/**
 * POST /site-settings: four site-wide hardening switches the desktop app's
 * WordPress checks flag, each stored in one plugin option and enforced by
 * hooks while it is on. Turning one off removes its effect completely.
 *
 * - disable_xmlrpc: xmlrpc.php answers 403 before core serves it (so the
 *   crawler's probe no longer sees the XML-RPC server), XML-RPC methods are
 *   switched off, and the X-Pingback header and RSD link go away. Refused
 *   while Jetpack is active: Jetpack talks to WordPress.com over XML-RPC.
 * - hide_generator: no <meta name="generator"> with the WordPress version, in
 *   pages or feeds.
 * - security_headers: X-Content-Type-Options: nosniff, X-Frame-Options:
 *   SAMEORIGIN and Referrer-Policy: strict-origin-when-cross-origin on front-
 *   end responses, unless PHP already set that header. No HSTS or CSP: both
 *   can break a site and belong in the server config.
 * - uploads_no_index: `Options -Indexes` in wp-content/uploads/.htaccess, so
 *   Apache and LiteSpeed stop listing upload folders. Refused on any other
 *   server, where .htaccess does nothing.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide hardening switches.
 */
class CCC_Site_Settings {

	const OPTION = 'ccc_site_settings';
	const NAMES  = array( 'disable_xmlrpc', 'hide_generator', 'security_headers', 'uploads_no_index' );
	const MARKER = 'Crawl Cove Connector';

	/**
	 * Current values, every name present.
	 *
	 * @return bool[]
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( self::NAMES as $name ) {
			$out[ $name ] = is_array( $stored ) && ! empty( $stored[ $name ] );
		}
		return $out;
	}

	/**
	 * Hook the enforcement of every switch that is on. Runs on every request,
	 * so it reads one autoloaded option and nothing else.
	 */
	public static function enforce() {
		$on = self::get();
		if ( $on['disable_xmlrpc'] ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
			add_filter( 'wp_headers', array( __CLASS__, 'drop_pingback_header' ) );
			remove_action( 'wp_head', 'rsd_link' );
			add_action( 'init', array( __CLASS__, 'block_xmlrpc' ), 0 );
		}
		if ( $on['hide_generator'] ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
		if ( $on['security_headers'] ) {
			add_action( 'send_headers', array( __CLASS__, 'send_security_headers' ) );
		}
	}

	/**
	 * Answer xmlrpc.php with 403 before core's XML-RPC server runs.
	 */
	public static function block_xmlrpc() {
		if ( ! defined( 'XMLRPC_REQUEST' ) || ! XMLRPC_REQUEST ) {
			return;
		}
		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'XML-RPC is disabled on this site.', 'crawl-cove-connector' );
		exit;
	}

	/**
	 * Remove the X-Pingback header, which advertises xmlrpc.php.
	 *
	 * @param array $headers Response headers.
	 * @return array
	 */
	public static function drop_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/**
	 * The three hardening headers, each only if nothing set it already.
	 */
	public static function send_security_headers() {
		if ( headers_sent() ) {
			return;
		}
		$sent = array();
		foreach ( headers_list() as $line ) {
			$sent[] = strtolower( trim( strtok( $line, ':' ) ) );
		}
		foreach ( self::headers() as $name => $value ) {
			if ( ! in_array( strtolower( $name ), $sent, true ) ) {
				header( $name . ': ' . $value );
			}
		}
	}

	/**
	 * The headers security_headers sends.
	 *
	 * @return string[] Name => value.
	 */
	public static function headers() {
		return array(
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options'        => 'SAMEORIGIN',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
		);
	}

	/**
	 * Why a switch cannot be turned on here, or null if it can.
	 *
	 * @param string $name Setting name.
	 * @return WP_Error|null
	 */
	private static function cannot_enable( $name ) {
		if ( 'disable_xmlrpc' === $name && class_exists( 'Jetpack' ) ) {
			return new WP_Error( 'ccc_xmlrpc_in_use', __( 'Jetpack is active and connects to WordPress.com over XML-RPC, so it is left on.', 'crawl-cove-connector' ) );
		}
		if ( 'uploads_no_index' === $name && empty( $GLOBALS['is_apache'] ) ) {
			return new WP_Error( 'ccc_server_unsupported', __( 'This server is not Apache or LiteSpeed, so an .htaccess file would do nothing. Turn off directory listing in the server configuration instead (for nginx: autoindex off).', 'crawl-cove-connector' ) );
		}
		return null;
	}

	/**
	 * Store one switch and apply its side effect (the uploads .htaccess).
	 *
	 * @param string $name  Setting name.
	 * @param bool   $value New value.
	 * @return true|WP_Error
	 */
	public static function set( $name, $value ) {
		if ( 'uploads_no_index' === $name ) {
			$done = self::write_uploads_htaccess( $value );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		$all          = self::get();
		$all[ $name ] = (bool) $value;
		update_option( self::OPTION, $all, true );
		return true;
	}

	/**
	 * Add or remove the marked `Options -Indexes` block in uploads/.htaccess,
	 * through core's insert_with_markers(), which leaves every other line alone.
	 *
	 * @param bool $on Add (true) or remove (false).
	 * @return true|WP_Error
	 */
	private static function write_uploads_htaccess( $on ) {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return new WP_Error( 'ccc_uploads_unavailable', __( 'The uploads folder could not be found.', 'crawl-cove-connector' ) );
		}
		$file = trailingslashit( $uploads['basedir'] ) . '.htaccess';
		if ( ! $on && ! file_exists( $file ) ) {
			return true;
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! insert_with_markers( $file, self::MARKER, $on ? array( 'Options -Indexes' ) : array() ) ) {
			return new WP_Error( 'ccc_not_writable', __( 'The uploads folder\'s .htaccess file could not be written.', 'crawl-cove-connector' ) );
		}
		return true;
	}

	/**
	 * Run a /site-settings request.
	 *
	 * @param mixed  $settings Name => bool, only the switches to change.
	 * @param bool   $dry_run  Report without writing.
	 * @param string $source   Actor for the change log.
	 * @return array|WP_Error { settings: name => { from, to, changed, change_id? } or { error, message } }
	 */
	public static function run( $settings, $dry_run, $source ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'ccc_forbidden', __( 'Only an administrator can change site settings.', 'crawl-cove-connector' ), array( 'status' => 403 ) );
		}
		if ( ! is_array( $settings ) || ! $settings ) {
			return new WP_Error( 'ccc_bad_request', __( 'settings must be a non-empty object.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}
		$current = self::get();
		$out     = array();
		foreach ( $settings as $name => $value ) {
			$name = (string) $name;
			if ( ! in_array( $name, self::NAMES, true ) ) {
				$out[ $name ] = self::refusal( new WP_Error( 'ccc_unknown_setting', __( 'Unknown setting.', 'crawl-cove-connector' ) ) );
				continue;
			}
			if ( ! is_bool( $value ) ) {
				$out[ $name ] = self::refusal( new WP_Error( 'ccc_bad_value', __( 'Each setting must be true or false.', 'crawl-cove-connector' ) ) );
				continue;
			}
			$from = $current[ $name ];
			if ( $value && ! $from ) {
				$why = self::cannot_enable( $name );
				if ( $why ) {
					$out[ $name ] = self::refusal( $why );
					continue;
				}
			}
			$step = array(
				'from'    => $from,
				'to'      => $value,
				'changed' => $from !== $value,
			);
			if ( ! $dry_run && $from !== $value ) {
				$done = self::set( $name, $value );
				if ( is_wp_error( $done ) ) {
					$out[ $name ] = self::refusal( $done );
					continue;
				}
				$entry             = CCC_Change_Log::record_entry(
					array(
						'kind'    => 'site_setting',
						'post_id' => CCC_Service::HOME_ID,
						'field'   => $name,
						'from'    => $from ? 'on' : 'off',
						'to'      => $value ? 'on' : 'off',
					),
					$source
				);
				$step['change_id'] = $entry['id'];
			}
			$out[ $name ] = $step;
		}
		return array(
			'dry_run'  => (bool) $dry_run,
			'settings' => $out,
		);
	}

	/**
	 * A per-setting refusal for the reply.
	 *
	 * @param WP_Error $error Why.
	 * @return array { error, message }
	 */
	private static function refusal( WP_Error $error ) {
		return array(
			'error'   => $error->get_error_code(),
			'message' => $error->get_error_message(),
		);
	}

	/**
	 * Revert a logged switch: put back its previous value, unless it was
	 * changed again since (then 409 with the current value, unless forced).
	 *
	 * @param array $entry Change log entry (kind site_setting).
	 * @param bool  $force Revert even if the value changed since.
	 * @return true|WP_Error
	 */
	public static function revert( array $entry, $force ) {
		$name = (string) $entry['field'];
		if ( ! in_array( $name, self::NAMES, true ) ) {
			return new WP_Error( 'ccc_not_found', __( 'No change with that id.', 'crawl-cove-connector' ), array( 'status' => 404 ) );
		}
		$now = self::get()[ $name ] ? 'on' : 'off';
		if ( ! $force && $now !== $entry['to'] ) {
			return new WP_Error(
				'ccc_value_changed',
				__( 'The value has changed since this change was made, so reverting it would overwrite the newer value. Nothing was written.', 'crawl-cove-connector' ),
				array(
					'status'   => 409,
					'current'  => $now,
					'expected' => $entry['to'],
				)
			);
		}
		$back = 'on' === $entry['from'];
		if ( $back && ! self::get()[ $name ] ) {
			$why = self::cannot_enable( $name );
			if ( $why ) {
				$why->add_data( array( 'status' => 409 ) );
				return $why;
			}
		}
		$done = self::set( $name, $back );
		if ( is_wp_error( $done ) ) {
			$done->add_data( array( 'status' => 500 ) );
		}
		return $done;
	}
}
