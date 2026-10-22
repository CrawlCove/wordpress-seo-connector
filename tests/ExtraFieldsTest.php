<?php

use PHPUnit\Framework\TestCase;

class ExtraFieldsTest extends TestCase {

	protected function setUp(): void {
		cc_reset_wp();
		cc_add_post( 7, 'https://example.com/hello/' );
	}

	private function adapter( $id ) {
		$keys = array(
			'yoast'    => array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ),
			'rankmath' => array( 'rank_math_title', 'rank_math_description' ),
			'seopress' => array( '_seopress_titles_title', '_seopress_titles_desc' ),
			'aioseo'   => array( '_aioseo_title', '_aioseo_description' ),
		);
		return new CCC_Adapter( $id, $keys[ $id ][0], $keys[ $id ][1] );
	}

	public function test_every_adapter_round_trips_every_extra_field() {
		$values = array(
			'canonical'           => 'https://example.com/canonical/',
			'og_title'            => 'OG title',
			'og_description'      => 'OG description',
			'og_image'            => 'https://example.com/wp-content/uploads/og.jpg',
			'twitter_title'       => 'Tw title',
			'twitter_description' => 'Tw description',
			'robots_index'        => 'noindex',
		);
		foreach ( array( 'yoast', 'rankmath', 'seopress', 'aioseo' ) as $id ) {
			cc_reset_wp();
			$a = $this->adapter( $id );
			foreach ( $values as $field => $value ) {
				$this->assertSame( '', $a->get_value( 7, $field ), "$id $field starts empty" );
				$a->set_value( 7, $field, $value );
				$this->assertSame( $value, $a->get_value( 7, $field ), "$id $field" );
			}
			foreach ( $values as $field => $value ) {
				$a->set_value( 7, $field, '' );
				$this->assertSame( '', $a->get_value( 7, $field ), "$id $field clears" );
			}
		}
	}

	public function test_yoast_stores_robots_as_its_numeric_flags() {
		$a = $this->adapter( 'yoast' );
		$a->set_value( 7, 'robots_index', 'noindex' );
		$this->assertSame( '1', get_post_meta( 7, '_yoast_wpseo_meta-robots-noindex', true ) );
		$a->set_value( 7, 'robots_index', 'index' );
		$this->assertSame( '2', get_post_meta( 7, '_yoast_wpseo_meta-robots-noindex', true ) );
		$a->set_value( 7, 'robots_index', '' );
		$this->assertArrayNotHasKey( '_yoast_wpseo_meta-robots-noindex', $GLOBALS['cc_meta'][7] );
	}

	public function test_rank_math_robots_keeps_the_other_directives() {
		update_post_meta( 7, 'rank_math_robots', array( 'index', 'nofollow', 'noarchive' ) );
		$a = $this->adapter( 'rankmath' );
		$a->set_value( 7, 'robots_index', 'noindex' );
		$this->assertSame( array( 'noindex', 'nofollow', 'noarchive' ), get_post_meta( 7, 'rank_math_robots', true ) );
		$a->set_value( 7, 'robots_index', '' );
		$this->assertSame( array( 'nofollow', 'noarchive' ), get_post_meta( 7, 'rank_math_robots', true ) );
	}

	public function test_rank_math_twitter_values_switch_off_use_facebook_and_back() {
		update_post_meta( 7, 'rank_math_twitter_use_facebook', 'on' );
		$a = $this->adapter( 'rankmath' );
		$a->set_value( 7, 'twitter_title', 'Tw' );
		$this->assertSame( 'off', get_post_meta( 7, 'rank_math_twitter_use_facebook', true ) );
		$a->set_value( 7, 'twitter_title', '' );
		$this->assertArrayNotHasKey( 'rank_math_twitter_use_facebook', $GLOBALS['cc_meta'][7] );
	}

	public function test_a_social_image_keeps_its_attachment_id_in_step() {
		$GLOBALS['cc_attachments']['https://example.com/wp-content/uploads/og.jpg'] = 40;
		update_post_meta( 7, '_yoast_wpseo_opengraph-image-id', '12' );
		$a = $this->adapter( 'yoast' );
		$a->set_value( 7, 'og_image', 'https://example.com/wp-content/uploads/og.jpg' );
		$this->assertSame( '40', get_post_meta( 7, '_yoast_wpseo_opengraph-image-id', true ) );
		$a->set_value( 7, 'og_image', 'https://cdn.example.org/elsewhere.jpg' );
		$this->assertArrayNotHasKey( '_yoast_wpseo_opengraph-image-id', $GLOBALS['cc_meta'][7] );
	}

	public function test_aioseo_image_and_twitter_flip_their_source_switches() {
		$a = $this->adapter( 'aioseo' );
		$a->set_value( 7, 'og_image', 'https://example.com/og.jpg' );
		$this->assertSame( 'custom_image', $GLOBALS['cc_aioseo'][7]['og_image_type'] );
		$a->set_value( 7, 'twitter_title', 'Tw' );
		$this->assertFalse( $GLOBALS['cc_aioseo'][7]['twitter_use_og'] );
		$a->set_value( 7, 'robots_index', '' );
		$this->assertTrue( $GLOBALS['cc_aioseo'][7]['robots_default'] );
	}

	public function test_apply_validates_logs_and_reverts_extra_fields() {
		$a   = $this->adapter( 'yoast' );
		$bad = CCC_Service::apply(
			array(
				array( 'post_id' => 7, 'canonical' => 'javascript:alert(1)' ),
				array( 'post_id' => 7, 'robots_index' => 'noindex' ),
				array( 'post_id' => 0, 'canonical' => 'https://example.com/' ),
				array( 'post_id' => 7, 'og_title' => str_repeat( 'x', 513 ) ),
			),
			false,
			$a,
			'bloo'
		);
		$this->assertSame( array( 'ccc_bad_url', 'ccc_bad_value', 'ccc_field_unsupported', 'ccc_too_long' ), array_column( $bad, 'error' ) );

		$res = CCC_Service::apply( array( array( 'post_id' => 7, 'canonical' => 'https://example.com/c/', 'robots_index' => false, 'og_title' => '<b>Shared</b>' ) ), false, $a, 'bloo' );
		$this->assertSame( 'Shared', get_post_meta( 7, '_yoast_wpseo_opengraph-title', true ) );
		$this->assertSame( '1', get_post_meta( 7, '_yoast_wpseo_meta-robots-noindex', true ) );
		$this->assertSame( 'noindex', $res[0]['applied']['robots_index']['to'] );

		$this->assertTrue( CCC_Change_Log::revert( $res[0]['applied']['robots_index']['change_id'], $a ) );
		$this->assertSame( '', $a->get_value( 7, 'robots_index' ) );
		update_post_meta( 7, '_yoast_wpseo_canonical', 'https://example.com/typed/' );
		$err = CCC_Change_Log::revert( $res[0]['applied']['canonical']['change_id'], $a );
		$this->assertSame( 'https://example.com/typed/', $err->get_error_data()['current'] );
	}
}
