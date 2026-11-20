<?php

use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase {

	public function test_every_logged_field_key_has_a_label() {
		$keys = array_merge( array( 'title', 'description', 'links', 'alt' ), CrawlCove_Adapter::EXTRA_FIELDS, CrawlCove_Site_Settings::NAMES );
		foreach ( $keys as $key ) {
			$label = CrawlCove_Admin::field_label( $key );
			$this->assertNotSame( $key, $label, "$key has no label" );
			$this->assertStringNotContainsString( '_', $label, "$key label reads like a key" );
		}
	}

	public function test_site_switch_labels_match_the_readme() {
		$readme = file_get_contents( __DIR__ . '/../readme.txt' );
		foreach ( array( 'disable_xmlrpc', 'hide_generator', 'security_headers', 'uploads_no_index' ) as $key ) {
			$this->assertStringContainsStringIgnoringCase( CrawlCove_Admin::field_label( $key ), $readme, $key );
		}
	}

	public function test_setup_step_says_which_plan_can_push() {
		// The app's Free plan (v1.3.15) blocks every push, so "New accounts
		// start on a free trial" leaves a Free plan user stuck at step 4.
		$src = file_get_contents( __DIR__ . '/../admin/class-ccc-admin.php' );
		$this->assertStringNotContainsString( 'New accounts start on a free trial', $src );
		$this->assertStringContainsString( 'Pushing fixes to WordPress needs a paid Crawl Cove plan or the free trial', $src );
	}

	public function test_no_seo_plugin_row_says_which_fixes_still_work() {
		// Since 0.11 link, alt text and hardening fixes need no SEO plugin
		// (readme FAQ, /status capabilities); the Tools row must not deny it
		// (review ticket #341).
		$src = file_get_contents( __DIR__ . '/../admin/class-ccc-admin.php' );
		$this->assertStringNotContainsString( 'fixes cannot be applied without one', $src );
		$this->assertStringContainsString( 'link, image alt text and site hardening fixes work without one', $src );
	}

	public function test_unknown_key_is_shown_as_stored() {
		$this->assertSame( 'something_new', CrawlCove_Admin::field_label( 'something_new' ) );
	}

	public function test_nofollow_strip_on_the_same_url_says_what_changed() {
		$e = array( 'from' => 'http://x/old/', 'to' => 'http://x/old/', 'rel_strip' => 'nofollow', 'unlink' => false );
		$this->assertSame( '(nofollow removed)', CrawlCove_Admin::after_text( $e ) );
	}

	public function test_nofollow_strip_with_a_new_url_shows_both() {
		$e = array( 'from' => 'http://x/old/', 'to' => 'http://x/new/', 'rel_strip' => 'nofollow' );
		$this->assertSame( 'http://x/new/ (nofollow removed)', CrawlCove_Admin::after_text( $e ) );
	}

	public function test_plain_unlink_and_empty_after() {
		$this->assertSame( '(link removed)', CrawlCove_Admin::after_text( array( 'from' => 'http://x/a/', 'to' => '', 'unlink' => true, 'rel_strip' => '' ) ) );
		$this->assertSame( 'http://x/b/', CrawlCove_Admin::after_text( array( 'from' => 'http://x/a/', 'to' => 'http://x/b/', 'rel_strip' => '' ) ) );
		$this->assertSame( '—', CrawlCove_Admin::after_text( array( 'from' => 'Old', 'to' => '' ) ) );
	}

	public function test_revert_anyway_names_the_value_it_restores() {
		$this->assertSame( 'Revert anyway to "Old title"', CrawlCove_Admin::revert_anyway_label( 'Old title' ) );
	}

	public function test_revert_anyway_to_an_empty_value_says_so() {
		$label = CrawlCove_Admin::revert_anyway_label( '' );
		$this->assertSame( 'Revert anyway (leaves it empty)', $label );
		$this->assertStringNotContainsString( "\u{2014}", $label );
	}

	public function test_drift_notice_quotes_the_current_value() {
		$this->assertSame( 'Not reverted: this title has changed since that push and now reads "Typed later". Reverting would overwrite it.', CrawlCove_Admin::changed_notice( 'title', 'Typed later' ) );
		$this->assertSame( 'Not reverted: this Open Graph title has changed since that push and now reads "X". Reverting would overwrite it.', CrawlCove_Admin::changed_notice( 'og_title', 'X' ) );
		$this->assertSame( 'Not reverted: this image\'s alt text has changed since that push and now reads "Cat". Reverting would overwrite it.', CrawlCove_Admin::changed_notice( 'alt', 'Cat' ) );
	}

	public function test_drift_notice_for_a_cleared_value_says_it_is_empty() {
		foreach ( array( 'title', 'description', 'og_title', 'alt' ) as $field ) {
			$msg = CrawlCove_Admin::changed_notice( $field, '' );
			$this->assertStringContainsString( 'has been emptied since that push', $msg, $field );
			$this->assertStringNotContainsString( "\u{2014}", $msg, $field );
		}
	}

	public function test_plugins_row_links_to_the_tools_page_first() {
		$GLOBALS['cc_hooks'] = array();
		CrawlCove_Admin::init();
		$this->assertArrayHasKey( 'plugin_action_links_crawl-cove-connector/crawl-cove-connector.php', $GLOBALS['cc_hooks'] );
		$links = CrawlCove_Admin::action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );
		$this->assertSame( '<a href="https://example.test/wp-admin/tools.php?page=crawl-cove-connector">Settings</a>', $links[0] );
		$this->assertSame( '<a href="#">Deactivate</a>', $links['deactivate'] );
	}

	/**
	 * Every form the Tools page posts to admin-post.php must reach a handler:
	 * admin-post.php fires admin_post_{action} and does nothing when no one is
	 * hooked. 1.0.0 shipped forms posting crawlcove_* to hooks still named ccc_*,
	 * so Revert and Remove key did nothing at all.
	 */
	public function test_every_admin_post_form_action_has_a_handler() {
		$GLOBALS['cc_hooks'] = array();
		CrawlCove_Admin::init();
		$src = file_get_contents( dirname( __DIR__ ) . '/admin/class-ccc-admin.php' );
		preg_match_all( '/name="action" value="([a-z0-9_]+)"/', $src, $m );
		$actions = array_unique( $m[1] );
		$this->assertNotEmpty( $actions );
		foreach ( $actions as $action ) {
			$this->assertArrayHasKey( 'admin_post_' . $action, $GLOBALS['cc_hooks'], $action );
			$this->assertTrue( is_callable( $GLOBALS['cc_hooks'][ 'admin_post_' . $action ][0] ), $action );
		}
		$this->assertSame( array( 'crawlcove_indexnow_remove', 'crawlcove_revert' ), array_values( array_intersect( array( 'crawlcove_indexnow_remove', 'crawlcove_revert' ), $actions ) ) );
	}

	public function test_plugins_row_has_no_settings_link_for_a_user_who_cannot_open_the_page() {
		$GLOBALS['cc_deny_manage_options'] = true;
		try {
			$this->assertSame( array( 'deactivate' => 'x' ), CrawlCove_Admin::action_links( array( 'deactivate' => 'x' ) ) );
		} finally {
			$GLOBALS['cc_deny_manage_options'] = false;
		}
	}

	public function test_times_use_the_site_date_and_time_format() {
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		$this->assertSame( 'November 19, 2026 9:59 pm', CrawlCove_Admin::when( 1795125540 ) );
		delete_option( 'date_format' );
		delete_option( 'time_format' );
		$this->assertSame( '19 Nov 2026 21:59', CrawlCove_Admin::when( 1795125540 ) );
		$this->assertSame( array( '19 Nov 2026', '21:59' ), CrawlCove_Admin::when_parts( 1795125540 ) );
	}
}
