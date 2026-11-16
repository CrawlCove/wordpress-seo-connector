<?php

use PHPUnit\Framework\TestCase;

/**
 * 1.0.1 renamed every option from ccc_* to crawlcove_* (wordpress.org asks
 * for a prefix of at least four letters). Sites that ran an earlier version
 * must keep their change log, revert history, hardening settings and
 * IndexNow key.
 */
class MigrateTest extends TestCase {

	protected function setUp(): void {
		cc_reset_wp();
	}

	public function test_every_old_option_moves_to_its_new_name() {
		$GLOBALS['cc_options'] = array(
			'ccc_change_log'      => array( array( 'id' => 7 ) ),
			'ccc_change_seq'      => 7,
			'ccc_last_connection' => array( 'time' => 1, 'user' => 'a', 'route' => '/r' ),
			'ccc_site_settings'   => array( 'hide_generator' => true ),
			'ccc_indexnow_key'    => 'abcdef12',
		);
		CrawlCove_Migrate::run();
		$this->assertSame(
			array(
				'crawlcove_change_log'      => array( array( 'id' => 7 ) ),
				'crawlcove_change_seq'      => 7,
				'crawlcove_last_connection' => array( 'time' => 1, 'user' => 'a', 'route' => '/r' ),
				'crawlcove_site_settings'   => array( 'hide_generator' => true ),
				'crawlcove_indexnow_key'    => 'abcdef12',
			),
			$GLOBALS['cc_options']
		);
		$this->assertSame( array( array( 'id' => 7 ) ), CrawlCove_Change_Log::all() );
	}

	public function test_a_value_already_under_the_new_name_wins_and_the_old_one_is_dropped() {
		$GLOBALS['cc_options'] = array(
			'ccc_indexnow_key'       => 'oldoldold',
			'crawlcove_indexnow_key' => 'newnewnew',
		);
		CrawlCove_Migrate::run();
		$this->assertSame( array( 'crawlcove_indexnow_key' => 'newnewnew' ), $GLOBALS['cc_options'] );
	}

	public function test_a_fresh_site_is_left_alone() {
		CrawlCove_Migrate::run();
		$this->assertSame( array(), $GLOBALS['cc_options'] );
	}

	public function test_it_runs_before_the_settings_and_key_are_enforced() {
		$src = file_get_contents( __DIR__ . '/../crawl-cove-connector.php' );
		$this->assertMatchesRegularExpression( "/add_action\\( 'plugins_loaded', array\\( 'CrawlCove_Migrate', 'run' \\), 0 \\);/", $src );
	}

	public function test_uninstall_removes_the_old_names_too() {
		$src = file_get_contents( __DIR__ . '/../uninstall.php' );
		foreach ( CrawlCove_Migrate::MAP as $old => $new ) {
			$this->assertStringContainsString( "'$old'", $src );
			$this->assertStringContainsString( "'$new'", $src );
		}
	}
}
