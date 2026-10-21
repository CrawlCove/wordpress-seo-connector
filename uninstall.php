<?php
/**
 * Uninstall: remove everything the plugin stored. Applied SEO meta stays —
 * those are the site owner's reviewed choices, not plugin state.
 *
 * On multisite, deleting a plugin runs this file once, on the main site, even
 * when the plugin was activated on subsites. Every site's options are removed,
 * not only the main site's, or each subsite's change log would outlive it.
 *
 * @package crawl-cove-connector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ccc_options = array( 'ccc_change_log', 'ccc_change_seq', 'ccc_last_connection' );

if ( is_multisite() ) {
	$ccc_offset = 0;
	do {
		$ccc_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $ccc_offset,
			)
		);
		foreach ( $ccc_site_ids as $ccc_site_id ) {
			switch_to_blog( $ccc_site_id );
			foreach ( $ccc_options as $ccc_option ) {
				delete_option( $ccc_option );
			}
			restore_current_blog();
		}
		$ccc_offset += 100;
		$ccc_more    = 100 === count( $ccc_site_ids );
	} while ( $ccc_more );
} else {
	foreach ( $ccc_options as $ccc_option ) {
		delete_option( $ccc_option );
	}
}
