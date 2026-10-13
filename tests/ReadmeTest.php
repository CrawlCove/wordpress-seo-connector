<?php

use PHPUnit\Framework\TestCase;

/**
 * Pins facts the two readmes state about the plugin and the desktop app, so
 * the listing text cannot drift from the code or go quiet about cost.
 */
class ReadmeTest extends TestCase {

	private function file( $name ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $name );
	}

	public function test_stable_tag_matches_plugin_version() {
		$main = $this->file( 'crawl-cove-connector.php' );
		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $header );
		preg_match( "/define\(\s*'CCC_VERSION',\s*'([^']+)'/", $main, $constant );
		preg_match( '/^Stable tag:\s*(\S+)/m', $this->file( 'readme.txt' ), $stable );

		$this->assertNotEmpty( $header );
		$this->assertSame( $header[1], $constant[1] );
		$this->assertSame( $header[1], $stable[1] );
	}

	/**
	 * GitHub installs must never be offered an update by api.wordpress.org
	 * for whatever plugin may one day own this unclaimed slug (review ticket
	 * #212): a non-wordpress.org Update URI makes core skip the directory for
	 * this plugin. The SVN copy must NOT carry it (bin/build-zip.sh --wporg
	 * strips it), because the directory only serves updates to plugins
	 * without one.
	 */
	public function test_plugin_header_carries_a_non_wporg_update_uri() {
		$main = (string) file_get_contents( dirname( __DIR__ ) . '/crawl-cove-connector.php' );
		preg_match( '/^\s*\*\s*Update URI:\s*(\S+)/m', $main, $uri );

		$this->assertNotEmpty( $uri );
		$this->assertSame( 'https://crawlcove.com/wordpress-plugin', $uri[1] );
	}

	public function test_readmes_say_the_desktop_app_is_paid_with_a_free_trial() {
		foreach ( array( 'readme.txt', 'README.md' ) as $name ) {
			$text = $this->file( $name );
			$this->assertMatchesRegularExpression( '/desktop app it works with is a paid crawler/', $text, $name );
			$this->assertMatchesRegularExpression( '/free trial/i', $text, $name );
			$this->assertStringContainsString( 'https://crawlcove.com/pricing?utm_source=wordpress-plugin', $text, $name );
		}
	}

	public function test_readme_faqs_say_deleting_the_plugin_removes_the_change_log() {
		foreach ( array( 'readme.txt', 'README.md' ) as $name ) {
			$this->assertStringContainsString( 'What happens to the change log if I delete the plugin?', $this->file( $name ), $name );
		}

		// The FAQ is only true while uninstall.php really deletes the log.
		$this->assertStringContainsString( "delete_option( 'ccc_change_log' );", $this->file( 'uninstall.php' ) );
	}

	public function test_public_docs_carry_no_em_dashes_outside_code_blocks() {
		// CHANGELOG.md is linked from the wordpress.org changelog, SECURITY-NOTES.md from README.md.
		foreach ( array( 'readme.txt', 'README.md', 'CHANGELOG.md', 'SECURITY-NOTES.md', 'wordpress-org/README.md' ) as $name ) {
			$prose = preg_replace( '/^```.*?^```/ms', '', $this->file( $name ) );
			$this->assertStringNotContainsString( "\u{2014}", $prose, $name );
		}
	}

	public function test_wordpress_org_changelog_stays_short() {
		$text = $this->file( 'readme.txt' );
		$this->assertNotFalse( strpos( $text, '== Changelog ==' ) );
		$changelog = substr( $text, strpos( $text, '== Changelog ==' ) );

		$this->assertLessThan( 250, str_word_count( $changelog ) );
		$this->assertStringContainsString( 'CHANGELOG.md', $changelog );
	}
}
