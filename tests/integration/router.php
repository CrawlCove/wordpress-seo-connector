<?php
/**
 * Router for the harness's `php -S` servers: an existing file or directory is
 * served as usual, every other path goes to WordPress's index.php, the same
 * thing nginx's usual try_files and WordPress's Apache rewrite block do.
 *
 * Why: before PHP 8.5 the built-in server answers its own 404 for a missing
 * path that has a file extension, so /<key>.txt (the IndexNow key file) never
 * reached WordPress and indexnow-checks.sh and migrate-checks.sh failed on PHP
 * 7.4 to 8.4 while passing on 8.5. PHP 8.5 falls back to index.php by itself.
 *
 * Usage: php -S 127.0.0.1:PORT -t "$SITE" tests/integration/router.php
 *
 * @package crawl-cove-connector
 */

$crawlcove_path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$crawlcove_file = $_SERVER['DOCUMENT_ROOT'] . $crawlcove_path;

if ( is_file( $crawlcove_file ) || is_dir( $crawlcove_file ) ) {
	return false;
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';
chdir( $_SERVER['DOCUMENT_ROOT'] );
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
