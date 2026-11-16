<?php
/**
 * Connection record: when an authenticated user last called this plugin's
 * REST API, so the Tools page can say whether the desktop app has ever
 * reached the site. Inbound only — nothing is sent anywhere.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Last-seen record, one small non-autoloaded option, written at most once a
 * minute so a push of many batches does not turn into a write per request.
 */
class CrawlCove_Connection {

	const OPTION   = 'crawlcove_last_connection';
	const INTERVAL = 60;

	/**
	 * Record an authenticated call.
	 *
	 * @param string $login Login name of the authenticated user.
	 * @param string $route REST route, e.g. /crawlcove/v1/status.
	 * @param int    $now   Unix time (injectable for tests).
	 * @return bool Whether the option was written.
	 */
	public static function touch( $login, $route, $now ) {
		$last = self::last();
		if ( $last && ( $now - $last['time'] ) < self::INTERVAL && $last['user'] === (string) $login ) {
			return false;
		}
		update_option(
			self::OPTION,
			array(
				'time'  => (int) $now,
				'user'  => (string) $login,
				'route' => (string) $route,
			),
			false
		);
		return true;
	}

	/**
	 * The stored record, or null if nothing has connected yet.
	 *
	 * @return array|null Keys: time (int), user (string), route (string).
	 */
	public static function last() {
		$rec = get_option( self::OPTION, null );
		if ( ! is_array( $rec ) || empty( $rec['time'] ) ) {
			return null;
		}
		return array(
			'time'  => (int) $rec['time'],
			'user'  => isset( $rec['user'] ) ? (string) $rec['user'] : '',
			'route' => isset( $rec['route'] ) ? (string) $rec['route'] : '',
		);
	}
}
