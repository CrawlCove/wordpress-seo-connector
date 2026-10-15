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
 * The other common cause is a plugin: Wordfence (default on since 9.x)
 * hooks __return_false onto wp_is_application_passwords_available, so a
 * perfectly good HTTPS site says no. Blaming HTTPS there sends the user
 * after the wrong fix (review ticket #220), so the two are told apart by
 * asking core whether it would have allowed them before any filter ran.
 *
 * This class gives that state one name, two messages and one REST error
 * code so the Tools page and the API tell the user the real cause.
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
	 * Off even though core would allow them here (HTTPS or a local
	 * environment): a plugin or theme filtered them off.
	 *
	 * @return bool
	 */
	public static function off_by_filter() {
		return ! self::available()
			&& function_exists( 'wp_is_application_passwords_supported' )
			&& wp_is_application_passwords_supported();
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
	 * The one-line explanation shown by the REST error.
	 *
	 * @return string
	 */
	public static function message() {
		if ( self::off_by_filter() ) {
			return __( 'Application Passwords are switched off on this WordPress site, so it cannot accept the desktop app login. The site itself supports them, so a plugin or theme has turned them off; in Wordfence that is Firewall, All Firewall Options, Disable WordPress application passwords. Open Tools, then Crawl Cove, on the site for details.', 'crawl-cove-connector' );
		}
		return __( 'Application Passwords are switched off on this WordPress site, so it cannot accept the desktop app login. WordPress only enables them over HTTPS or when WP_ENVIRONMENT_TYPE is local. Open Tools, then Crawl Cove, on the site for the fix.', 'crawl-cove-connector' );
	}
}
