<?php
/**
 * Is this site able to accept the desktop app's login at all?
 *
 * WordPress core only enables Application Passwords when is_ssl() is true
 * or WP_ENVIRONMENT_TYPE is "local" (wp_is_application_passwords_available()
 * in wp-includes/user.php). That rules out every plain-http staging or
 * local site and every HTTPS site behind a proxy that does not pass the
 * scheme through, and when it bites, core silently ignores the Basic auth
 * header: the request arrives anonymous and dies as a generic 401 that the
 * desktop app can only report as wrong credentials (review ticket #211).
 *
 * This class gives that state one name, one message and one REST error code
 * so the Tools page and the API tell the user the real cause.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Names the "core has Application Passwords switched off" state for the
 * Tools page and the REST permission callback.
 */
class CCC_App_Passwords {

	const ERROR_CODE = 'ccc_app_passwords_unavailable';

	/**
	 * Whether core will accept an Application Password on this request.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
	}

	/**
	 * Permission-callback guard. An anonymous request on a site where
	 * Application Passwords are off is almost always the desktop app being
	 * ignored by core, so name that instead of letting it fall through to
	 * the generic rest_forbidden 401.
	 *
	 * @return true|WP_Error True to carry on with the normal capability check.
	 */
	public static function guard() {
		if ( is_user_logged_in() || self::available() ) {
			return true;
		}
		return new WP_Error( self::ERROR_CODE, self::message(), array( 'status' => 401 ) );
	}

	/**
	 * The one-line explanation shown by the REST error and the Tools page.
	 *
	 * @return string
	 */
	public static function message() {
		return __( 'Application Passwords are switched off on this WordPress site, so it cannot accept the desktop app login. WordPress only enables them over HTTPS or when WP_ENVIRONMENT_TYPE is local. Open Tools, then Crawl Cove, on the site for the fix.', 'crawl-cove-connector' );
	}
}
