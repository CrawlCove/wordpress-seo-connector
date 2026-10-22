<?php

use PHPUnit\Framework\TestCase;

class SiteSettingsTest extends TestCase {

	protected function setUp(): void {
		cc_reset_wp();
	}

	public function test_everything_is_off_until_switched_on() {
		$this->assertSame(
			array(
				'disable_xmlrpc'   => false,
				'hide_generator'   => false,
				'security_headers' => false,
				'uploads_no_index' => false,
			),
			CCC_Site_Settings::get()
		);
	}

	public function test_only_an_administrator_may_change_settings() {
		$GLOBALS['cc_deny_manage_options'] = true;
		$got = CCC_Site_Settings::run( array( 'hide_generator' => true ), false, 'editor' );
		$this->assertSame( 'ccc_forbidden', $got->get_error_code() );
		$this->assertFalse( CCC_Site_Settings::get()['hide_generator'] );
	}

	public function test_unknown_names_and_non_booleans_are_refused_per_setting() {
		$got = CCC_Site_Settings::run( array( 'disable_xmlrpc' => 'yes', 'disable_comments' => true, 'hide_generator' => true ), false, 'admin' );
		$this->assertSame( 'ccc_bad_value', $got['settings']['disable_xmlrpc']['error'] );
		$this->assertSame( 'ccc_unknown_setting', $got['settings']['disable_comments']['error'] );
		$this->assertTrue( $got['settings']['hide_generator']['changed'] );
		$this->assertFalse( CCC_Site_Settings::get()['disable_xmlrpc'] );
	}

	public function test_dry_run_reports_and_writes_nothing() {
		$got = CCC_Site_Settings::run( array( 'security_headers' => true ), true, 'admin' );
		$this->assertSame( array( 'from' => false, 'to' => true, 'changed' => true ), $got['settings']['security_headers'] );
		$this->assertFalse( CCC_Site_Settings::get()['security_headers'] );
		$this->assertSame( array(), CCC_Change_Log::all() );
	}

	public function test_a_switch_is_logged_and_reverts_unless_changed_again() {
		$on = CCC_Site_Settings::run( array( 'hide_generator' => true ), false, 'admin' );
		$id = $on['settings']['hide_generator']['change_id'];
		$this->assertTrue( CCC_Site_Settings::get()['hide_generator'] );
		$entry = CCC_Change_Log::find( $id );
		$this->assertSame( array( 'site_setting', 'hide_generator', 'off', 'on' ), array( $entry['kind'], $entry['field'], $entry['from'], $entry['to'] ) );

		$same = CCC_Site_Settings::run( array( 'hide_generator' => true ), false, 'admin' );
		$this->assertFalse( $same['settings']['hide_generator']['changed'] );
		$this->assertArrayNotHasKey( 'change_id', $same['settings']['hide_generator'] );

		CCC_Site_Settings::set( 'hide_generator', false );
		$err = CCC_Change_Log::revert( $id, null );
		$this->assertSame( 'ccc_value_changed', $err->get_error_code() );
		$this->assertSame( 'off', $err->get_error_data()['current'] );

		CCC_Site_Settings::set( 'hide_generator', true );
		$this->assertTrue( CCC_Change_Log::revert( $id, null ) );
		$this->assertFalse( CCC_Site_Settings::get()['hide_generator'] );
	}

	public function test_a_site_setting_entry_is_for_administrators_only() {
		CCC_Site_Settings::run( array( 'hide_generator' => true ), false, 'admin' );
		$this->assertCount( 1, CCC_Change_Log::visible() );
		$GLOBALS['cc_deny_manage_options'] = true;
		$this->assertCount( 0, CCC_Change_Log::visible() );
	}

	public function test_uploads_listing_is_refused_off_apache() {
		$got = CCC_Site_Settings::run( array( 'uploads_no_index' => true ), true, 'admin' );
		$this->assertSame( 'ccc_server_unsupported', $got['settings']['uploads_no_index']['error'] );
	}
}
