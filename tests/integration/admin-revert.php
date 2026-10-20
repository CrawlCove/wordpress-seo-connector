<?php
// Usage: CCC_HR_ID=<change id> [CCC_HR_FORCE=1] wp eval-file admin-revert.php
// Drive CCC_Admin::handle_revert() as the Tools page form would: admin user,
// real nonce, and the redirect captured by throwing out of wp_redirect before
// the handler's exit.
require_once WP_PLUGIN_DIR . '/crawl-cove-connector/admin/class-ccc-admin.php';
wp_set_current_user( 1 );
$id    = (int) getenv( 'CCC_HR_ID' );
$_POST = array( 'change_id' => (string) $id, 'paged' => '1', '_wpnonce' => wp_create_nonce( 'ccc_revert_' . $id ) );
if ( getenv( 'CCC_HR_FORCE' ) ) {
	$_POST['force'] = '1';
}
$_REQUEST = $_POST;
add_filter( 'wp_redirect', function ( $loc ) { throw new Exception( $loc ); } );
try {
	CCC_Admin::handle_revert();
} catch ( Exception $e ) {
	echo $e->getMessage();
}
