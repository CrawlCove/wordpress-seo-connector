<?php
/**
 * Plugin Name: Crawl Cove Connector: Bulk Meta Description and SEO Title Fixes
 * Plugin URI:  https://crawlcove.com/wordpress-plugin
 * Update URI:  https://crawlcove.com/wordpress-plugin
 * Description: Receive approved SEO fixes from the Crawl Cove desktop crawler and apply them to your site: titles, descriptions, canonical, robots and social fields in Yoast SEO, Rank Math, SEOPress or AIOSEO, plus link and image alt text fixes, with a full change log and one-click revert.
 * Version:     1.0.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author:      Crawl Cove
 * Author URI:  https://crawlcove.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: crawl-cove-connector
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

define( 'CRAWLCOVE_VERSION', '1.0.1' );
define( 'CRAWLCOVE_PLUGIN_FILE', __FILE__ );
define( 'CRAWLCOVE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-migrate.php';
require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-app-passwords.php';
require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-adapter.php';
require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-change-log.php';
require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-connection.php';
require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-term-resolver.php';
require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-service.php';
require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-url-replacer.php';
require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-site-settings.php';
require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-image-alt.php';
require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-indexnow.php';
require_once CRAWLCOVE_PLUGIN_DIR . 'includes/class-ccc-rest.php';

if ( is_admin() ) {
	require_once CRAWLCOVE_PLUGIN_DIR . 'admin/class-ccc-admin.php';
	add_action( 'plugins_loaded', array( 'CrawlCove_Admin', 'init' ) );
}

add_action( 'plugins_loaded', array( 'CrawlCove_Migrate', 'run' ), 0 );
add_action( 'rest_api_init', array( 'CrawlCove_Rest', 'register_routes' ) );
add_action( 'plugins_loaded', array( 'CrawlCove_Site_Settings', 'enforce' ) );
add_action( 'plugins_loaded', array( 'CrawlCove_IndexNow', 'enforce' ) );

// No load_plugin_textdomain() call: since WP 4.6, translations for a
// wordpress.org-hosted plugin whose Text Domain matches its slug are loaded
// automatically (just-in-time), and a manual call is actively discouraged.
