<?php

use PHPUnit\Framework\TestCase;

class ImageAltTest extends TestCase {

	const IMG = 'https://example.com/wp-content/uploads/2026/10/photo-300x200.jpg';

	protected function setUp(): void {
		cc_reset_wp();
		cc_add_post( 7, 'https://example.com/hello/' );
		cc_add_post( 40, 'https://example.com/?attachment_id=40', 'Photo', 'attachment' );
		$GLOBALS['cc_attachments']['https://example.com/wp-content/uploads/2026/10/photo.jpg'] = 40;
	}

	private function item( $alt, $url = 'https://example.com/hello/' ) {
		return array( 'url' => $url, 'src' => self::IMG, 'alt' => $alt );
	}

	public function test_a_resized_copy_finds_its_attachment() {
		$this->assertSame( 40, CCC_Image_Alt::attachment_for( self::IMG ) );
		$this->assertSame( 0, CCC_Image_Alt::attachment_for( 'https://example.com/other.jpg' ) );
	}

	public function test_rewrite_sets_replaces_or_adds_alt_on_matching_images_only() {
		$html = '<img src="' . self::IMG . '" alt="old"><img class="x" src="/wp-content/uploads/2026/10/photo-300x200.jpg"><img src="https://example.com/other.jpg" alt="keep">';
		$r    = CCC_Image_Alt::rewrite( $html, CCC_Url_Replacer::forms( self::IMG ), 'A "red" door' );
		$this->assertSame( 2, $r['count'] );
		$this->assertStringContainsString( '<img src="' . self::IMG . '" alt="A &quot;red&quot; door">', $r['text'] );
		$this->assertStringContainsString( '<img alt="A &quot;red&quot; door" class="x"', $r['text'] );
		$this->assertStringContainsString( 'alt="keep"', $r['text'] );
		$this->assertSame( $html, CCC_Url_Replacer::unsplice( $r['text'], $r['splices'] ) );
	}

	public function test_an_alt_inside_another_attribute_is_not_the_alt() {
		// Review 22 Oct: the alt text's words must never become attributes.
		$html = '<img title=" alt=\'x\'" src="' . self::IMG . '">';
		$r    = CCC_Image_Alt::rewrite( $html, array( self::IMG ), 'hello data-ccc-marker=1' );
		$this->assertSame( '<img alt="hello data-ccc-marker=1" title=" alt=\'x\'" src="' . self::IMG . '">', $r['text'] );
		$attrs = CCC_Url_Replacer::attributes( $r['text'] );
		$this->assertSame( array( 'alt', 'title', 'src' ), array_column( $attrs, 'name' ) );
	}

	public function test_empty_alt_is_written_as_an_empty_attribute() {
		$r = CCC_Image_Alt::rewrite( '<img src="' . self::IMG . '">', array( self::IMG ), '' );
		$this->assertSame( '<img alt="" src="' . self::IMG . '">', $r['text'] );
	}

	public function test_validate_refuses_bad_input() {
		$this->assertSame( 'ccc_bad_url', CCC_Image_Alt::validate( array( 'url' => 'https://example.com/hello/', 'src' => 'data:image/png;base64,xx', 'alt' => 'x' ) )->get_error_code() );
		$this->assertSame( 'ccc_bad_value', CCC_Image_Alt::validate( array( 'url' => 'https://example.com/hello/', 'src' => self::IMG ) )->get_error_code() );
		$this->assertSame( 'ccc_too_long', CCC_Image_Alt::validate( $this->item( str_repeat( 'é', 251 ) ) )->get_error_code() );
		$this->assertSame( 'A door', CCC_Image_Alt::validate( $this->item( '<b>A door</b>' ) )['alt'] );
	}

	public function test_apply_sets_both_places_logs_once_and_reverts_both() {
		$GLOBALS['cc_posts'][7]['content'] = '<p><img src="' . self::IMG . '"></p>';
		update_post_meta( 40, '_wp_attachment_image_alt', 'Old alt' );

		$dry = CCC_Image_Alt::run( array( $this->item( 'A red door' ) ), true, 'bloo' );
		$this->assertSame( array( 'id' => 40, 'from' => 'Old alt', 'to' => 'A red door', 'changed' => true ), $dry[0]['attachment'] );
		$this->assertSame( 1, $dry[0]['inline'] );
		$this->assertSame( 'Old alt', get_post_meta( 40, '_wp_attachment_image_alt', true ) );

		$res = CCC_Image_Alt::run( array( $this->item( 'A red door' ) ), false, 'bloo' );
		$this->assertSame( 'A red door', get_post_meta( 40, '_wp_attachment_image_alt', true ) );
		$this->assertStringContainsString( 'alt="A red door"', $GLOBALS['cc_posts'][7]['content'] );
		$this->assertSame( array(), $GLOBALS['cc_resaved'] );

		$this->assertTrue( CCC_Change_Log::revert( $res[0]['change_id'], null ) );
		$this->assertSame( 'Old alt', get_post_meta( 40, '_wp_attachment_image_alt', true ) );
		$this->assertSame( '<p><img src="' . self::IMG . '"></p>', $GLOBALS['cc_posts'][7]['content'] );
	}

	public function test_revert_refuses_after_an_edit() {
		$GLOBALS['cc_posts'][7]['content'] = '<img src="' . self::IMG . '">';
		$res = CCC_Image_Alt::run( array( $this->item( 'Door' ) ), false, 'bloo' );
		update_post_meta( 40, '_wp_attachment_image_alt', 'Typed in the media library' );
		$err = CCC_Change_Log::revert( $res[0]['change_id'], null );
		$this->assertSame( 'ccc_value_changed', $err->get_error_code() );
		$this->assertSame( 'Typed in the media library', get_post_meta( 40, '_wp_attachment_image_alt', true ) );

		$GLOBALS['cc_posts'][7]['content'] .= '<p>new</p>';
		$this->assertSame( 'ccc_content_changed', CCC_Change_Log::revert( $res[0]['change_id'], null, true )->get_error_code() );
	}

	public function test_an_image_found_nowhere_is_refused_and_an_uneditable_page_is_forbidden() {
		$GLOBALS['cc_posts'][7]['content'] = '<p>No images.</p>';
		$got = CCC_Image_Alt::run( array( array( 'url' => 'https://example.com/hello/', 'src' => 'https://example.com/x.jpg', 'alt' => 'x' ) ), false, 'bloo' );
		$this->assertSame( 'ccc_image_not_found', $got[0]['error'] );

		$GLOBALS['cc_deny'] = array( 7 );
		$got = CCC_Image_Alt::run( array( $this->item( 'x' ) ), false, 'bloo' );
		$this->assertSame( 'ccc_forbidden', $got[0]['error'] );
		$this->assertSame( '', get_post_meta( 40, '_wp_attachment_image_alt', true ) );
	}
}
