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

	public function test_an_img_inside_a_shortcode_attribute_is_left_alone() {
		// Review #354: an Editor's alt pushed into [caption caption="<img>"]
		// closed the admin's attribute and dropped the whole figure.
		$GLOBALS['shortcode_tags'] = array( 'caption' => '__return_empty_string', 'column' => '__return_empty_string' );
		$cap  = '[caption id="c1" width="300" caption="Logo <img src=' . self::IMG . '>"]';
		$html = '[column]' . $cap . '<img src="' . self::IMG . '">[/caption][/column]';
		$r    = CCC_Image_Alt::rewrite( $html, array( self::IMG ), 'A door' );
		$this->assertSame( 1, $r['count'] );
		$this->assertSame( '[column]' . $cap . '<img alt="A door" src="' . self::IMG . '">[/caption][/column]', $r['text'] );
	}

	public function test_a_bracket_that_is_not_a_registered_shortcode_changes_nothing() {
		$html = '[note text="<img src=' . self::IMG . '>"]';
		$r    = CCC_Image_Alt::rewrite( $html, array( self::IMG ), 'A door' );
		$this->assertSame( 1, $r['count'] );
	}

	public function test_a_greater_than_sign_inside_an_attribute_value_does_not_hide_the_image() {
		// Review #354 minor: alt="Home > Shop" ended the regex's tag early.
		$html = '<img alt="Home > Shop" src="' . self::IMG . '"><img title=\'a>b\' src="' . self::IMG . '">';
		$r    = CCC_Image_Alt::rewrite( $html, array( self::IMG ), 'A door' );
		$this->assertSame( 2, $r['count'] );
		$this->assertSame( '<img alt="A door" src="' . self::IMG . '"><img alt="A door" title=\'a>b\' src="' . self::IMG . '">', $r['text'] );

		$GLOBALS['cc_posts'][7]['content'] = '<img alt="Home > Shop" src="https://example.com/x.jpg">';
		$got = CCC_Image_Alt::run( array( array( 'url' => 'https://example.com/hello/', 'src' => 'https://example.com/x.jpg', 'alt' => 'x' ) ), false, 'bloo' );
		$this->assertTrue( $got[0]['ok'] );
		$this->assertSame( 1, $got[0]['inline'] );
	}

	public function test_an_unclosed_quote_does_not_stall_the_scan() {
		$html = '<img ' . str_repeat( '"\'', 5000 ) . ' src="' . self::IMG . '"';
		$r    = CCC_Image_Alt::rewrite( $html, array( self::IMG ), 'A door' );
		$this->assertSame( 0, $r['count'] );
		$this->assertSame( PREG_NO_ERROR, preg_last_error() );
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

	public function test_conflict_says_whether_the_alt_or_the_page_changed() {
		$GLOBALS['cc_posts'][7]['content'] = '<img src="' . self::IMG . '">';
		$res   = CCC_Image_Alt::run( array( $this->item( 'Door' ) ), false, 'bloo' );
		$entry = CCC_Change_Log::find( $res[0]['change_id'] );
		$this->assertSame( '', CCC_Image_Alt::conflict( $entry ) );

		update_post_meta( 40, '_wp_attachment_image_alt', 'Typed in the media library' );
		$this->assertSame( 'alt', CCC_Image_Alt::conflict( $entry ) );

		$GLOBALS['cc_posts'][7]['content'] .= '<p>new</p>';
		$this->assertSame( 'page', CCC_Image_Alt::conflict( $entry ) );
	}

	public function test_an_image_found_nowhere_is_refused_and_an_uneditable_page_is_forbidden() {
		$GLOBALS['cc_posts'][7]['content'] = '<p>No images.</p>';
		$got = CCC_Image_Alt::run( array( array( 'url' => 'https://example.com/hello/', 'src' => 'https://example.com/x.jpg', 'alt' => 'x' ) ), false, 'bloo' );
		$this->assertSame( 'ccc_image_not_found', $got[0]['error'] );

		$GLOBALS['cc_deny'] = array( 7 );
		$got = CCC_Image_Alt::run( array( $this->item( 'x' ) ), false, 'bloo' );
		$this->assertSame( 'ccc_forbidden', $got[0]['error'] );
		$GLOBALS['cc_deny'] = array();

		// Review #354 minor: found only where it cannot be safely edited.
		$GLOBALS['shortcode_tags']         = array( 'caption' => '__return_empty_string' );
		$GLOBALS['cc_posts'][7]['content'] = '[caption caption="Logo <img src=\'https://example.com/x.jpg\'>"]Logo[/caption]';
		$got = CCC_Image_Alt::run( array( array( 'url' => 'https://example.com/hello/', 'src' => 'https://example.com/x.jpg', 'alt' => 'x' ) ), false, 'bloo' );
		$this->assertSame( 'ccc_image_not_editable', $got[0]['error'] );
		$this->assertSame( array(), $GLOBALS['cc_db_writes'] );

		// Already carrying the alt is still a plain ok, not a refusal.
		$GLOBALS['cc_posts'][7]['content'] = '<img src="https://example.com/x.jpg" alt="x">';
		$got = CCC_Image_Alt::run( array( array( 'url' => 'https://example.com/hello/', 'src' => 'https://example.com/x.jpg', 'alt' => 'x' ) ), false, 'bloo' );
		$this->assertTrue( $got[0]['ok'] );
		$this->assertSame( 0, $got[0]['inline'] );

		$GLOBALS['cc_deny'] = array( 7 );
		$got = CCC_Image_Alt::run( array( $this->item( 'x' ) ), false, 'bloo' );
		$this->assertSame( 'ccc_forbidden', $got[0]['error'] );
		$this->assertSame( '', get_post_meta( 40, '_wp_attachment_image_alt', true ) );
	}
}
