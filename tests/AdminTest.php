<?php

use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase {

	public function test_every_logged_field_key_has_a_label() {
		$keys = array_merge( array( 'title', 'description', 'links', 'alt' ), CCC_Adapter::EXTRA_FIELDS, CCC_Site_Settings::NAMES );
		foreach ( $keys as $key ) {
			$label = CCC_Admin::field_label( $key );
			$this->assertNotSame( $key, $label, "$key has no label" );
			$this->assertStringNotContainsString( '_', $label, "$key label reads like a key" );
		}
	}

	public function test_site_switch_labels_match_the_readme() {
		$readme = file_get_contents( __DIR__ . '/../readme.txt' );
		foreach ( array( 'disable_xmlrpc', 'hide_generator', 'security_headers', 'uploads_no_index' ) as $key ) {
			$this->assertStringContainsStringIgnoringCase( CCC_Admin::field_label( $key ), $readme, $key );
		}
	}

	public function test_unknown_key_is_shown_as_stored() {
		$this->assertSame( 'something_new', CCC_Admin::field_label( 'something_new' ) );
	}

	public function test_nofollow_strip_on_the_same_url_says_what_changed() {
		$e = array( 'from' => 'http://x/old/', 'to' => 'http://x/old/', 'rel_strip' => 'nofollow', 'unlink' => false );
		$this->assertSame( '(nofollow removed)', CCC_Admin::after_text( $e ) );
	}

	public function test_nofollow_strip_with_a_new_url_shows_both() {
		$e = array( 'from' => 'http://x/old/', 'to' => 'http://x/new/', 'rel_strip' => 'nofollow' );
		$this->assertSame( 'http://x/new/ (nofollow removed)', CCC_Admin::after_text( $e ) );
	}

	public function test_plain_unlink_and_empty_after() {
		$this->assertSame( '(link removed)', CCC_Admin::after_text( array( 'from' => 'http://x/a/', 'to' => '', 'unlink' => true, 'rel_strip' => '' ) ) );
		$this->assertSame( 'http://x/b/', CCC_Admin::after_text( array( 'from' => 'http://x/a/', 'to' => 'http://x/b/', 'rel_strip' => '' ) ) );
		$this->assertSame( '—', CCC_Admin::after_text( array( 'from' => 'Old', 'to' => '' ) ) );
	}
}
