<?php
/**
 * Plugin Name: Crawl Cove Connector
 * Plugin URI:  https://crawlcove.com/wordpress-plugin
 * Update URI:  https://crawlcove.com/wordpress-plugin
 * Description: Receive approved title and meta description fixes from the Crawl Cove desktop crawler and apply them to Yoast SEO, Rank Math, SEOPress or AIOSEO, with a full change log and one-click revert.
 * Version:     0.10.6
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

define( 'CCC_VERSION', '0.10.6' );
define( 'CCC_PLUGIN_FILE', __FILE__ );
define( 'CCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once CCC_PLUGIN_DIR . 'includes/class-ccc-app-passwords.php';
require_once CCC_PLUGIN_DIR . 'includes/class-ccc-adapter.php';
require_once CCC_PLUGIN_DIR . 'includes/class-ccc-change-log.php';
require_once CCC_PLUGIN_DIR . 'includes/class-ccc-connection.php';
require_once CCC_PLUGIN_DIR . 'includes/class-ccc-term-resolver.php';
require_once CCC_PLUGIN_DIR . 'includes/class-ccc-service.php';
require_once CCC_PLUGIN_DIR . 'includes/class-ccc-rest.php';

if ( is_admin() ) {
	require_once CCC_PLUGIN_DIR . 'admin/class-ccc-admin.php';
	add_action( 'plugins_loaded', array( 'CCC_Admin', 'init' ) );
}

add_action( 'rest_api_init', array( 'CCC_Rest', 'register_routes' ) );

// No load_plugin_textdomain() call: since WP 4.6, translations for a
// wordpress.org-hosted plugin whose Text Domain matches its slug are loaded
// automatically (just-in-time), and a manual call is actively discouraged.
