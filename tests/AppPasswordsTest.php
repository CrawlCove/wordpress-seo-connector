<?php

use PHPUnit\Framework\TestCase;

/**
 * CCC_App_Passwords: the permission-callback guard that turns core's silent
 * "Application Passwords are off here" into a named 401 (review ticket #211).
 * The stubs for is_user_logged_in() and wp_is_application_passwords_available()
 * live in bootstrap.php and read the cc_logged_in / cc_app_passwords globals.
 */
class AppPasswordsTest extends TestCase {

	protected function setUp(): void {
		cc_reset_wp();
	}

	public function test_anonymous_request_on_a_site_with_app_passwords_off_is_a_named_401() {
		$GLOBALS['cc_logged_in']               = false;
		$GLOBALS['cc_app_passwords']           = false;
		$GLOBALS['cc_app_passwords_supported'] = false;

		$guard = CCC_App_Passwords::guard();

		$this->assertTrue( is_wp_error( $guard ) );
		$this->assertSame( 'ccc_app_passwords_unavailable', $guard->get_error_code() );
		$this->assertSame( array( 'status' => 401 ), $guard->get_error_data() );
		$this->assertStringContainsString( 'Application Passwords are switched off', $guard->get_error_message() );
		$this->assertStringContainsString( 'HTTPS', $guard->get_error_message() );
	}

	public function test_off_on_a_site_core_would_allow_blames_a_plugin_not_https() {
		// Wordfence's default: HTTPS site, __return_false on the availability filter (review #220).
		$GLOBALS['cc_logged_in']               = false;
		$GLOBALS['cc_app_passwords']           = false;
		$GLOBALS['cc_app_passwords_supported'] = true;

		$this->assertTrue( CCC_App_Passwords::off_by_filter() );
		$guard = CCC_App_Passwords::guard();

		$this->assertTrue( is_wp_error( $guard ) );
		$this->assertSame( 'ccc_app_passwords_unavailable', $guard->get_error_code() );
		$this->assertSame( array( 'status' => 401 ), $guard->get_error_data() );
		$this->assertStringContainsString( 'Application Passwords are switched off', $guard->get_error_message() );
		$this->assertStringContainsString( 'a plugin or theme has turned them off', $guard->get_error_message() );
		$this->assertStringContainsString( 'Disable WordPress application passwords', $guard->get_error_message() );
		$this->assertStringNotContainsString( 'HTTPS', $guard->get_error_message() );
	}

	public function test_off_where_core_would_refuse_keeps_the_https_explanation() {
		$GLOBALS['cc_app_passwords']           = false;
		$GLOBALS['cc_app_passwords_supported'] = false;

		$this->assertFalse( CCC_App_Passwords::off_by_filter() );
		$this->assertStringNotContainsString( 'plugin or theme', CCC_App_Passwords::message() );
	}

	public function test_on_is_never_reported_as_off_by_filter() {
		$GLOBALS['cc_app_passwords']           = true;
		$GLOBALS['cc_app_passwords_supported'] = true;

		$this->assertFalse( CCC_App_Passwords::off_by_filter() );
	}

	public function test_anonymous_request_where_app_passwords_are_on_falls_through_to_the_capability_check() {
		$GLOBALS['cc_logged_in']     = false;
		$GLOBALS['cc_app_passwords'] = true;

		$this->assertTrue( CCC_App_Passwords::guard() );
	}

	public function test_a_cookie_authenticated_user_is_never_blocked_by_the_guard() {
		$GLOBALS['cc_logged_in']     = true;
		$GLOBALS['cc_app_passwords'] = false;

		$this->assertTrue( CCC_App_Passwords::guard() );
	}

	public function test_available_mirrors_core() {
		$GLOBALS['cc_app_passwords'] = false;
		$this->assertFalse( CCC_App_Passwords::available() );
		$GLOBALS['cc_app_passwords'] = true;
		$this->assertTrue( CCC_App_Passwords::available() );
	}

	public function test_error_code_constant_matches_the_documented_contract() {
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/README.md' );
		$this->assertStringContainsString( '"code":"' . CCC_App_Passwords::ERROR_CODE . '"', $readme );
		$this->assertSame( 'ccc_app_passwords_unavailable', CCC_App_Passwords::ERROR_CODE );
	}
}
