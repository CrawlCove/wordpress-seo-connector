<?php

use PHPUnit\Framework\TestCase;

class IndexNowTest extends TestCase {

	protected function setUp(): void {
		cc_reset_wp();
	}

	public function test_no_key_until_one_is_set() {
		$this->assertSame( '', CCC_IndexNow::get_key() );
		$this->assertSame( 'ccc_no_indexnow_key', CCC_IndexNow::read()->get_error_code() );
		$this->assertSame( array( 'status' => 404 ), CCC_IndexNow::read()->get_error_data() );
	}

	public function test_store_returns_the_key_and_where_it_is_served() {
		$got = CCC_IndexNow::store( 'a1b2c3d4-e5f6' );
		$this->assertSame(
			array(
				'key'         => 'a1b2c3d4-e5f6',
				'keyLocation' => 'https://example.com/a1b2c3d4-e5f6.txt',
			),
			$got
		);
		$this->assertSame( $got, CCC_IndexNow::read() );
	}

	public function test_keys_outside_the_indexnow_format_are_refused() {
		foreach ( array( 'short12', str_repeat( 'a', 129 ), 'has_underscore1', 'dot.ted.key', '../../etc/x', 'key with space', "abcdef123\n", "abcdef123\r\n", 12345678, null ) as $bad ) {
			$got = CCC_IndexNow::store( $bad );
			$this->assertSame( 'ccc_bad_indexnow_key', $got->get_error_code(), var_export( $bad, true ) );
		}
		$this->assertSame( '', CCC_IndexNow::get_key() );
		$this->assertSame( 'abcdefgh', CCC_IndexNow::store( 'abcdefgh' )['key'] );
		$this->assertSame( str_repeat( 'Z', 128 ), CCC_IndexNow::store( str_repeat( 'Z', 128 ) )['key'] );
	}

	public function test_only_an_administrator_may_set_or_remove_the_key() {
		CCC_IndexNow::store( 'abcdefgh1' );
		$GLOBALS['cc_deny_manage_options'] = true;
		$this->assertSame( 'ccc_forbidden', CCC_IndexNow::store( 'other-key-9' )->get_error_code() );
		$this->assertSame( 'ccc_forbidden', CCC_IndexNow::remove()->get_error_code() );
		$this->assertSame( 'abcdefgh1', CCC_IndexNow::get_key() );
		// Reading is open to any connected user: the key is public at its URL anyway.
		$this->assertSame( 'abcdefgh1', CCC_IndexNow::read()['key'] );
	}

	public function test_a_new_key_replaces_the_old_one() {
		CCC_IndexNow::store( 'first-key-1' );
		CCC_IndexNow::store( 'second-key-2' );
		$this->assertSame( 'second-key-2', CCC_IndexNow::get_key() );
		$this->assertFalse( CCC_IndexNow::is_key_path( '/first-key-1.txt', CCC_IndexNow::get_key() ) );
	}

	public function test_remove_reports_whether_there_was_a_key() {
		$this->assertSame( array( 'deleted' => false ), CCC_IndexNow::remove() );
		CCC_IndexNow::store( 'abcdefgh1' );
		$this->assertSame( array( 'deleted' => true ), CCC_IndexNow::remove() );
		$this->assertSame( '', CCC_IndexNow::get_key() );
	}

	public function test_a_tampered_option_is_treated_as_no_key() {
		update_option( CCC_IndexNow::OPTION, '<script>' );
		$this->assertSame( '', CCC_IndexNow::get_key() );
		update_option( CCC_IndexNow::OPTION, array( 'abcdefgh' ) );
		$this->assertSame( '', CCC_IndexNow::get_key() );
	}

	public function test_the_key_file_is_hooked_only_once_a_key_is_set() {
		CCC_IndexNow::enforce();
		$this->assertArrayNotHasKey( 'init', $GLOBALS['cc_hooks'] );
		CCC_IndexNow::store( 'abcdefgh1' );
		CCC_IndexNow::enforce();
		$this->assertSame( array( array( 'CCC_IndexNow', 'maybe_serve' ) ), $GLOBALS['cc_hooks']['init'] );
	}

	public function test_key_path_matches_exactly_and_ignores_the_query_string() {
		$this->assertTrue( CCC_IndexNow::is_key_path( '/abcdefgh1.txt', 'abcdefgh1' ) );
		$this->assertTrue( CCC_IndexNow::is_key_path( '/abcdefgh1.txt?x=1', 'abcdefgh1' ) );
		$this->assertFalse( CCC_IndexNow::is_key_path( '/abcdefgh1.txt/', 'abcdefgh1' ) );
		$this->assertFalse( CCC_IndexNow::is_key_path( '/blog/abcdefgh1.txt', 'abcdefgh1' ) );
		$this->assertFalse( CCC_IndexNow::is_key_path( '/abcdefgh1', 'abcdefgh1' ) );
		$this->assertFalse( CCC_IndexNow::is_key_path( '/?abcdefgh1.txt', 'abcdefgh1' ) );
		$this->assertFalse( CCC_IndexNow::is_key_path( '', 'abcdefgh1' ) );
	}

	public function test_a_site_in_a_subdirectory_serves_the_key_under_its_home_path() {
		$GLOBALS['cc_home'] = 'https://example.com/blog';
		$this->assertSame( 'https://example.com/blog/abcdefgh1.txt', CCC_IndexNow::store( 'abcdefgh1' )['keyLocation'] );
		$this->assertTrue( CCC_IndexNow::is_key_path( '/blog/abcdefgh1.txt', 'abcdefgh1' ) );
		$this->assertFalse( CCC_IndexNow::is_key_path( '/abcdefgh1.txt', 'abcdefgh1' ) );
	}
}
