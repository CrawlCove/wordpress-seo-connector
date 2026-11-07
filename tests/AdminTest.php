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

	public function test_setup_step_says_which_plan_can_push() {
		// The app's Free plan (v1.3.15) blocks every push, so "New accounts
		// start on a free trial" leaves a Free plan user stuck at step 4.
		$src = file_get_contents( __DIR__ . '/../admin/class-ccc-admin.php' );
		$this->assertStringNotContainsString( 'New accounts start on a free trial', $src );
		$this->assertStringContainsString( 'Pushing fixes to WordPress needs a paid Crawl Cove plan or the free trial', $src );
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

	public function test_revert_anyway_names_the_value_it_restores() {
		$this->assertSame( 'Revert anyway to "Old title"', CCC_Admin::revert_anyway_label( 'Old title' ) );
	}

	public function test_revert_anyway_to_an_empty_value_says_so() {
		$label = CCC_Admin::revert_anyway_label( '' );
		$this->assertSame( 'Revert anyway (leaves it empty)', $label );
		$this->assertStringNotContainsString( "\u{2014}", $label );
	}

	public function test_drift_notice_quotes_the_current_value() {
		$this->assertSame( 'Not reverted: this title has changed since that push and now reads "Typed later". Reverting would overwrite it.', CCC_Admin::changed_notice( 'title', 'Typed later' ) );
		$this->assertSame( 'Not reverted: this Open Graph title has changed since that push and now reads "X". Reverting would overwrite it.', CCC_Admin::changed_notice( 'og_title', 'X' ) );
		$this->assertSame( 'Not reverted: this image\'s alt text has changed since that push and now reads "Cat". Reverting would overwrite it.', CCC_Admin::changed_notice( 'alt', 'Cat' ) );
	}

	public function test_drift_notice_for_a_cleared_value_says_it_is_empty() {
		foreach ( array( 'title', 'description', 'og_title', 'alt' ) as $field ) {
			$msg = CCC_Admin::changed_notice( $field, '' );
			$this->assertStringContainsString( 'has been emptied since that push', $msg, $field );
			$this->assertStringNotContainsString( "\u{2014}", $msg, $field );
		}
	}
}
