<?php
// Usage: CCC_HR_ID=<change id> [CCC_HR_FORCE=1] wp eval-file admin-revert.php
// Drive the Tools page Revert form the way wp-admin/admin-post.php does: admin
// user, real nonce, dispatched by the form's own action through
// admin_post_{action} (1.0.0 hooked the handler under a name no form posted),
// and the redirect captured by throwing out of wp_redirect before the
// handler's exit.
defined( 'ABSPATH' ) || exit;
require_once WP_PLUGIN_DIR . '/crawl-cove-connector/admin/class-ccc-admin.php';
wp_set_current_user( 1 );
$id    = (int) getenv( 'CCC_HR_ID' );
$_POST = array( 'action' => 'crawlcove_revert', 'change_id' => (string) $id, 'paged' => '1', '_wpnonce' => wp_create_nonce( 'crawlcove_revert_' . $id ) );
if ( getenv( 'CCC_HR_FORCE' ) ) {
	$_POST['force'] = '1';
}
$_REQUEST = $_POST;
add_filter( 'wp_redirect', function ( $loc ) { throw new Exception( $loc ); } );
try {
	CrawlCove_Admin::init();
	do_action( 'admin_post_' . sanitize_key( $_REQUEST['action'] ) );
	echo 'no handler for admin_post_' . sanitize_key( $_REQUEST['action'] );
} catch ( Exception $e ) {
	echo $e->getMessage();
}
