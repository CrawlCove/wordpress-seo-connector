<?php
/**
 * One-time move of the options written by 1.0.0 and earlier, which used the
 * three-letter ccc_ prefix, to their crawlcove_ names. The wordpress.org
 * guidelines ask for a prefix of at least four letters.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs on every load before anything reads the options. Once the old names
 * are gone each check is a cached "no such option", so it costs no query.
 */
class CrawlCove_Migrate {

	/**
	 * Old option name => new option name, with the autoload flag the new
	 * name is written with (the same one its class uses).
	 */
	const MAP = array(
		'ccc_change_log'      => 'crawlcove_change_log',
		'ccc_change_seq'      => 'crawlcove_change_seq',
		'ccc_last_connection' => 'crawlcove_last_connection',
		'ccc_site_settings'   => 'crawlcove_site_settings',
		'ccc_indexnow_key'    => 'crawlcove_indexnow_key',
	);

	const AUTOLOAD = array(
		'crawlcove_site_settings' => true,
		'crawlcove_indexnow_key'  => true,
	);

	/**
	 * Copy each old option to its new name unless the new one already holds
	 * a value, then delete the old one.
	 */
	public static function run() {
		foreach ( self::MAP as $old => $new ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}
			if ( null === get_option( $new, null ) ) {
				update_option( $new, $value, isset( self::AUTOLOAD[ $new ] ) );
			}
			delete_option( $old );
		}
	}
}
