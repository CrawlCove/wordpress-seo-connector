<?php
/**
 * REST API: namespace crawlcove/v1.
 *
 * Authentication is WordPress core's own (Application Passwords over HTTPS is
 * what the desktop app uses). Every route additionally requires edit_posts,
 * and apply/revert re-check edit_post per post.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the crawlcove/v1 namespace.
 */
class CCC_Rest {

	const NS = 'crawlcove/v1';

	/**
	 * Register all five REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			)
		);

		register_rest_route(
			self::NS,
			'/resolve',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'resolve' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'args'                => array(
					'urls' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'apply' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'args'                => array(
					'changes' => array(
						'required' => true,
						'type'     => 'array',
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/changes',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'changes' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			)
		);

		register_rest_route(
			self::NS,
			'/revert',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'revert' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'args'                => array(
					'change_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);
	}

	/**
	 * Permission callback shared by every route: the base requirement to use
	 * the API at all. apply()/revert() re-check edit_post per post on top.
	 *
	 * @param WP_REST_Request|null $request Current request.
	 * @return bool|WP_Error WP_Error (ccc_app_passwords_unavailable, 401) when
	 *                       the request is anonymous because core has
	 *                       Application Passwords switched off.
	 */
	public static function can_use( $request = null ) {
		$guard = CCC_App_Passwords::guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		// Only an authorised call reaches here, so the Tools page's
		// "last connected" row can never be moved by an anonymous request.
		$route = $request instanceof WP_REST_Request ? $request->get_route() : '';
		CCC_Connection::touch( wp_get_current_user()->user_login, $route, time() );
		return true;
	}

	/**
	 * GET /status — plugin/site/detected-adapter info, no side effects.
	 *
	 * @return WP_REST_Response
	 */
	public static function status() {
		$adapter = CCC_Adapter::detect();
		return rest_ensure_response(
			array(
				'plugin_version' => CCC_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'site_url'       => home_url(),
				'seo_plugin'     => $adapter ? $adapter->id : 'none',
				'seo_version'    => $adapter ? $adapter->plugin_version : '',
				'can_apply'      => (bool) $adapter,
			)
		);
	}

	/**
	 * POST /resolve — map up to 100 URLs to post ids and their current SEO values.
	 *
	 * @param WP_REST_Request $request Request with a 'urls' array param.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function resolve( $request ) {
		$adapter = self::require_adapter();
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$urls = $request->get_param( 'urls' );
		if ( ! is_array( $urls ) || ! $urls ) {
			return new WP_Error( 'ccc_bad_request', __( 'urls must be a non-empty array.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}
		if ( count( $urls ) > 100 ) {
			return new WP_Error( 'ccc_batch_too_big', __( 'At most 100 urls per request.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}

		$out = array();
		foreach ( $urls as $url ) {
			$url     = is_string( $url ) ? esc_url_raw( $url ) : '';
			$post_id = CCC_Service::resolve_url( $url, $lang );
			if ( is_wp_error( $post_id ) ) {
				$out[] = array(
					'url'     => $url,
					'ok'      => false,
					'error'   => $post_id->get_error_code(),
					'message' => $post_id->get_error_message(),
				);
				continue;
			}
			$out[] = array_merge(
				array(
					'url' => $url,
					'ok'  => true,
				),
				CCC_Service::describe( $post_id, $adapter, $lang )
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /apply — write up to 50 title/description changes, dry-run or real.
	 *
	 * @param WP_REST_Request $request Request with 'changes' array and optional 'dry_run' bool.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function apply( $request ) {
		$adapter = self::require_adapter();
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$user    = wp_get_current_user();
		$results = CCC_Service::apply(
			$request->get_param( 'changes' ),
			(bool) $request->get_param( 'dry_run' ),
			$adapter,
			$user ? $user->user_login : 'unknown'
		);
		return is_wp_error( $results ) ? $results : rest_ensure_response( $results );
	}

	/**
	 * GET /changes — the change log entries this user may see, newest first.
	 *
	 * @return WP_REST_Response
	 */
	public static function changes() {
		return rest_ensure_response( CCC_Change_Log::visible() );
	}

	/**
	 * POST /revert — write a logged change's previous value back.
	 *
	 * @param WP_REST_Request $request Request with a 'change_id' integer param.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function revert( $request ) {
		$adapter = self::require_adapter();
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$change_id = (int) $request->get_param( 'change_id' );
		$entry     = CCC_Change_Log::find( $change_id );
		if ( $entry && ! CCC_Service::can_edit_target( $entry['post_id'] ) ) {
			return new WP_Error( 'ccc_forbidden', __( 'This user may not edit that target.', 'crawl-cove-connector' ), array( 'status' => 403 ) );
		}

		$done = CCC_Change_Log::revert( $change_id, $adapter );
		return is_wp_error( $done ) ? $done : rest_ensure_response(
			array(
				'ok'        => true,
				'change_id' => $change_id,
			)
		);
	}

	/**
	 * The active SEO adapter, or a WP_Error if no supported SEO plugin is active.
	 *
	 * @return CCC_Adapter|WP_Error
	 */
	private static function require_adapter() {
		$adapter = CCC_Adapter::detect();
		if ( ! $adapter ) {
			return new WP_Error(
				'ccc_no_seo_plugin',
				__( 'No supported SEO plugin is active (Yoast SEO, Rank Math, SEOPress or AIOSEO), so there is nothing to write to.', 'crawl-cove-connector' ),
				array( 'status' => 409 )
			);
		}
		return $adapter;
	}
}
