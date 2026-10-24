<?php

use PHPUnit\Framework\TestCase;

/**
 * House style for text a site owner or the desktop app user reads: no em
 * dashes in string literals (admin page, REST messages, plugin header).
 * Comments are exempt. A lone dash is the empty-cell placeholder in the
 * change log table, a WordPress core convention rather than prose.
 */
class UserFacingStringsTest extends TestCase {

	private function plugin_php_files() {
		$root  = dirname( __DIR__ );
		$files = array( $root . '/crawl-cove-connector.php', $root . '/uninstall.php' );
		foreach ( array( 'admin', 'includes' ) as $dir ) {
			$files = array_merge( $files, glob( $root . '/' . $dir . '/*.php' ) );
		}
		return $files;
	}

	public function test_string_literals_carry_no_em_dashes() {
		$dash  = "\u{2014}";
		$found = array();
		foreach ( $this->plugin_php_files() as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
				if ( ! is_array( $token ) || false === strpos( $token[1], $dash ) ) {
					continue;
				}
				if ( T_CONSTANT_ENCAPSED_STRING === $token[0] && "'" . $dash . "'" === $token[1] ) {
					continue;
				}
				if ( in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ), true ) ) {
					$found[] = basename( $file ) . ':' . $token[2];
				}
			}
		}
		$this->assertSame( array(), $found );
	}

	public function test_plugin_header_description_carries_no_em_dash() {
		$main = (string) file_get_contents( dirname( __DIR__ ) . '/crawl-cove-connector.php' );
		preg_match( '/^\s*\*\s*Description:\s*(.+)$/m', $main, $description );

		$this->assertNotEmpty( $description );
		$this->assertStringNotContainsString( "\u{2014}", $description[1] );
	}

	/**
	 * The app has no "site profile": the WordPress card sits on the client's
	 * Connections page (review ticket #277). Setup steps and error messages
	 * must name the screen the user can actually find.
	 */
	public function test_setup_copy_names_the_apps_connections_page() {
		$root  = dirname( __DIR__ );
		$files = array_merge( $this->plugin_php_files(), array( $root . '/readme.txt', $root . '/README.md' ) );
		$found = array();
		foreach ( $files as $file ) {
			if ( false !== stripos( (string) file_get_contents( $file ), 'site profile' ) ) {
				$found[] = basename( $file );
			}
		}
		$this->assertSame( array(), $found );

		$admin = (string) file_get_contents( $root . '/admin/class-ccc-admin.php' );
		$this->assertStringContainsString( 'then Connections, and fill in the WordPress card', $admin );
		$this->assertStringContainsString( 'then Connections, and fill in the WordPress card', (string) file_get_contents( $root . '/readme.txt' ) );
	}
}
