<?php
/**
 * Uninstall: remove everything the plugin stored. Applied fixes stay (SEO
 * meta, repointed links, alt text): those are the site owner's reviewed
 * choices, not plugin state. The one file the plugin writes, the
 * uploads_no_index block in uploads/.htaccess, is removed with it, since the
 * setting it belongs to goes too.
 *
 * On multisite, deleting a plugin runs this file once, on the main site, even
 * when the plugin was activated on subsites. Every site's options are removed,
 * not only the main site's, or each subsite's change log would outlive it.
 *
 * @package crawl-cove-connector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// 1.0.0 and earlier used the ccc_ names (includes/class-ccc-migrate.php).
$crawlcove_options = array(
	'crawlcove_change_log',
	'crawlcove_change_seq',
	'crawlcove_last_connection',
	'crawlcove_site_settings',
	'crawlcove_indexnow_key',
	'ccc_change_log',
	'ccc_change_seq',
	'ccc_last_connection',
	'ccc_site_settings',
	'ccc_indexnow_key',
);

/**
 * Remove the current site's uploads/.htaccess block, if its setting is on.
 */
function crawlcove_uninstall_uploads_block() {
	$settings = get_option( 'crawlcove_site_settings', get_option( 'ccc_site_settings', array() ) );
	if ( ! is_array( $settings ) || empty( $settings['uploads_no_index'] ) ) {
		return;
	}
	$uploads = wp_upload_dir( null, false );
	$file    = trailingslashit( $uploads['basedir'] ) . '.htaccess';
	if ( empty( $uploads['error'] ) && file_exists( $file ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		insert_with_markers( $file, 'Crawl Cove Connector', array() );
	}
}

if ( is_multisite() ) {
	$crawlcove_offset = 0;
	do {
		$crawlcove_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $crawlcove_offset,
			)
		);
		foreach ( $crawlcove_site_ids as $crawlcove_site_id ) {
			switch_to_blog( $crawlcove_site_id );
			crawlcove_uninstall_uploads_block();
			foreach ( $crawlcove_options as $crawlcove_option ) {
				delete_option( $crawlcove_option );
			}
			restore_current_blog();
		}
		$crawlcove_offset += 100;
		$crawlcove_more    = 100 === count( $crawlcove_site_ids );
	} while ( $crawlcove_more );
} else {
	crawlcove_uninstall_uploads_block();
	foreach ( $crawlcove_options as $crawlcove_option ) {
		delete_option( $crawlcove_option );
	}
}
