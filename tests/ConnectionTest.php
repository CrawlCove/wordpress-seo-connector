<?php

use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase {

	protected function setUp(): void {
		cc_reset_wp();
	}

	public function test_nothing_recorded_until_a_call_is_touched() {
		$this->assertNull( CrawlCove_Connection::last() );
	}

	public function test_touch_records_time_user_and_route() {
		$this->assertTrue( CrawlCove_Connection::touch( 'lewis', '/wp-json/crawlcove/v1/status', 1000 ) );
		$this->assertSame(
			array(
				'time'  => 1000,
				'user'  => 'lewis',
				'route' => '/wp-json/crawlcove/v1/status',
			),
			CrawlCove_Connection::last()
		);
	}

	public function test_writes_are_throttled_to_once_a_minute_per_user() {
		CrawlCove_Connection::touch( 'lewis', '/a', 1000 );
		$this->assertFalse( CrawlCove_Connection::touch( 'lewis', '/b', 1000 + CrawlCove_Connection::INTERVAL - 1 ) );
		$this->assertSame( '/a', CrawlCove_Connection::last()['route'] );
		$this->assertTrue( CrawlCove_Connection::touch( 'lewis', '/b', 1000 + CrawlCove_Connection::INTERVAL ) );
		$this->assertSame( '/b', CrawlCove_Connection::last()['route'] );
	}

	public function test_a_different_user_inside_the_window_is_still_recorded() {
		CrawlCove_Connection::touch( 'lewis', '/a', 1000 );
		$this->assertTrue( CrawlCove_Connection::touch( 'bloo', '/a', 1001 ) );
		$this->assertSame( 'bloo', CrawlCove_Connection::last()['user'] );
	}

	public function test_corrupt_option_reads_as_not_connected() {
		update_option( CrawlCove_Connection::OPTION, 'garbage' );
		$this->assertNull( CrawlCove_Connection::last() );
		update_option( CrawlCove_Connection::OPTION, array( 'user' => 'x' ) );
		$this->assertNull( CrawlCove_Connection::last() );
	}
}
