<?php

use PHPUnit\Framework\TestCase;

class UrlReplacerTest extends TestCase {

	const OLD = 'https://example.com/old-page/';
	const NEW = 'https://example.com/new-page/';

	protected function setUp(): void {
		cc_reset_wp();
	}

	private function op( array $extra = array() ) {
		$item = array_merge(
			array(
				'from'  => self::OLD,
				'to'    => self::NEW,
				'scope' => 'site',
			),
			$extra
		);
		$op = CCC_Url_Replacer::validate( $item );
		$this->assertIsArray( $op, is_wp_error( $op ) ? $op->get_error_code() : '' );
		return $op;
	}

	private function roundtrip( $text, $where, array $op ) {
		$r = CCC_Url_Replacer::rewrite( $text, $where, $op );
		$this->assertSame( $text, CCC_Url_Replacer::unsplice( $r['text'], $r['splices'] ), 'unsplice must give back the original bytes' );
		return $r;
	}

	public function test_validate_rejects_unsafe_or_pointless_items() {
		$cases = array(
			'from not http'      => array( array( 'from' => 'javascript:alert(1)' ), 'ccc_bad_url' ),
			'to not http'        => array( array( 'to' => 'javascript:alert(1)' ), 'ccc_bad_url' ),
			'to breaks the attr' => array( array( 'to' => 'https://example.com/a"onmouseover="x' ), 'ccc_bad_url' ),
			'to single quote'    => array( array( 'to' => "https://example.com/it's" ), 'ccc_bad_url' ),
			'to relative'        => array( array( 'to' => '/new-page/' ), 'ccc_bad_url' ),
			'same url'           => array( array( 'to' => self::OLD ), 'ccc_nothing_to_do' ),
			'bad rel_strip'      => array( array( 'rel_strip' => 'sponsored' ), 'ccc_bad_value' ),
			'bad scope'          => array( array( 'scope' => 'network' ), 'ccc_bad_value' ),
			'page without url'   => array( array( 'scope' => 'page' ), 'ccc_no_target' ),
			'page is home'       => array( array( 'scope' => 'page', 'url' => 'https://example.com/' ), 'ccc_no_content_target' ),
		);
		foreach ( $cases as $label => $case ) {
			$item = array_merge(
				array(
					'from'  => self::OLD,
					'to'    => self::NEW,
					'scope' => 'site',
				),
				$case[0]
			);
			$got  = CCC_Url_Replacer::validate( $item );
			$this->assertInstanceOf( WP_Error::class, $got, $label );
			$this->assertSame( $case[1], $got->get_error_code(), $label );
		}
	}

	public function test_validate_page_scope_needs_edit_rights_and_allows_same_url_for_rel_strip() {
		cc_add_post( 7, 'https://example.com/hello/' );
		$GLOBALS['cc_deny'] = array( 7 );
		$got = CCC_Url_Replacer::validate( array( 'from' => self::OLD, 'to' => self::NEW, 'scope' => 'page', 'url' => 'https://example.com/hello/' ) );
		$this->assertSame( 'ccc_forbidden', $got->get_error_code() );

		$GLOBALS['cc_deny'] = array();
		$got = CCC_Url_Replacer::validate( array( 'from' => self::OLD, 'to' => self::OLD, 'rel_strip' => 'nofollow', 'scope' => 'page', 'url' => 'https://example.com/hello/' ) );
		$this->assertSame( 7, $got['post_id'] );
		$this->assertSame( array( self::OLD, '/old-page/' ), $got['forms'] );
	}

	public function test_off_site_from_has_no_relative_form() {
		$this->assertSame( array( 'https://other.example/x' ), CCC_Url_Replacer::forms( 'https://other.example/x' ) );
	}

	public function test_rewrites_exact_urls_in_attributes_and_nothing_else() {
		$html = '<p>See <a href="https://example.com/old-page/">old</a>, <a href=\'/old-page/\'>rel</a>, '
			. '<a href="https://example.com/old-page/two">longer</a>, <a href="/old-page/?x=1">query</a>. '
			. 'Plain text https://example.com/old-page/ stays.</p>'
			. '<img src="https://example.com/old-page/" srcset="https://example.com/old-page/ 1x, https://example.com/other.png 2x">'
			. '<div style="background:url(\'https://example.com/old-page/\')"></div>';
		$r    = $this->roundtrip( $html, 'content', $this->op() );

		$this->assertSame( 5, $r['count'] );
		$this->assertStringContainsString( '<a href="https://example.com/new-page/">old</a>', $r['text'] );
		$this->assertStringContainsString( "<a href='https://example.com/new-page/'>rel</a>", $r['text'] );
		$this->assertStringContainsString( 'href="https://example.com/old-page/two"', $r['text'] );
		$this->assertStringContainsString( 'href="/old-page/?x=1"', $r['text'] );
		$this->assertStringContainsString( 'Plain text https://example.com/old-page/ stays.', $r['text'] );
		$this->assertStringContainsString( 'srcset="https://example.com/new-page/ 1x, https://example.com/other.png 2x"', $r['text'] );
		$this->assertStringContainsString( "url('https://example.com/new-page/')", $r['text'] );
	}

	public function test_a_url_that_could_end_a_css_value_is_not_written_into_css() {
		$op = CCC_Url_Replacer::validate( array( 'from' => self::OLD, 'to' => 'https://example.com/x);color:red;', 'scope' => 'site' ) );
		$r  = $this->roundtrip( '<div style="background:url(https://example.com/old-page/)"></div><a href="https://example.com/old-page/">a</a>', 'content', $op );
		$this->assertSame( 1, $r['count'] );
		$this->assertSame( 1, $r['skipped'] );
		$this->assertStringContainsString( 'url(https://example.com/old-page/)', $r['text'] );
	}

	public function test_entity_encoded_ampersands_match_and_stay_encoded() {
		$op = CCC_Url_Replacer::validate( array( 'from' => 'https://example.com/?a=1&b=2', 'to' => 'https://example.com/n/?a=1&b=2', 'scope' => 'site' ) );
		$r  = $this->roundtrip( '<a href="https://example.com/?a=1&amp;b=2">x</a>', 'content', $op );
		$this->assertSame( '<a href="https://example.com/n/?a=1&amp;b=2">x</a>', $r['text'] );
	}

	public function test_block_comment_attributes_are_rewritten_with_wordpress_escaping() {
		$op   = CCC_Url_Replacer::validate( array( 'from' => self::OLD, 'to' => 'https://example.com/new--page/?a=1&b=2', 'scope' => 'site' ) );
		$html = '<!-- wp:navigation-link {"label":"Old","url":"/old-page/","kind":"custom"} /-->'
			. '<!-- wp:embed {"url":"https://example.com/old-page/"} --><figure></figure><!-- /wp:embed -->';
		$r    = $this->roundtrip( $html, 'content', $op );
		$this->assertSame( 2, $r['count'] );
		$this->assertStringContainsString( '"url":"https://example.com/new\u002d\u002dpage/?a=1\u0026b=2","kind"', $r['text'] );
		preg_match( '/wp:embed (\{.*?\}) -->/', $r['text'], $m );
		$this->assertSame( 'https://example.com/new--page/?a=1&b=2', json_decode( $m[1], true )['url'] );
	}

	public function test_unlink_keeps_the_text_and_leaves_buttons_alone() {
		$html = '<p>Go <a href="https://example.com/old-page/" rel="nofollow">here</a> now.</p>'
			. '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com/old-page/">Buy</a></div>'
			. '<img src="https://example.com/old-page/">';
		$r    = $this->roundtrip( $html, 'content', $this->op( array( 'unlink' => true, 'to' => null ) ) );
		$this->assertSame( 1, $r['count'] );
		$this->assertSame( 1, $r['skipped'] );
		$this->assertStringContainsString( '<p>Go here now.</p>', $r['text'] );
		$this->assertStringContainsString( 'href="https://example.com/old-page/">Buy</a>', $r['text'] );
		$this->assertStringContainsString( '<img src="https://example.com/old-page/">', $r['text'] );
	}

	public function test_rel_strip_removes_only_nofollow_on_matching_anchors() {
		$html = '<a href="/old-page/" rel="nofollow noopener">a</a><a href="/old-page/" rel="nofollow">b</a>'
			. '<a href="/elsewhere/" rel="nofollow">c</a><a href="/old-page/">d</a>';
		$r    = $this->roundtrip( $html, 'content', $this->op( array( 'to' => self::OLD, 'rel_strip' => 'nofollow' ) ) );
		$this->assertSame( 2, $r['count'] );
		$this->assertSame( '<a href="/old-page/" rel="noopener">a</a><a href="/old-page/">b</a><a href="/elsewhere/" rel="nofollow">c</a><a href="/old-page/">d</a>', $r['text'] );
	}

	public function test_elementor_json_keeps_its_escaping_and_stays_valid_json() {
		$data = wp_json_encode(
			array(
				array(
					'settings' => array(
						'link'   => array(
							'url'      => self::OLD,
							'nofollow' => '',
						),
						'editor' => '<p><a href="https://example.com/old-page/">x</a> https://example.com/old-page/</p>',
						'image'  => array( 'url' => 'https://example.com/old-page/two' ),
					),
				),
			)
		);
		$this->assertStringContainsString( 'https:\/\/example.com\/old-page\/', $data );
		$r       = $this->roundtrip( $data, 'elementor', $this->op() );
		$decoded = json_decode( $r['text'], true );
		$this->assertSame( 2, $r['count'] );
		$this->assertSame( self::NEW, $decoded[0]['settings']['link']['url'] );
		$this->assertSame( '<p><a href="https://example.com/new-page/">x</a> https://example.com/old-page/</p>', $decoded[0]['settings']['editor'] );
		$this->assertSame( 'https://example.com/old-page/two', $decoded[0]['settings']['image']['url'] );
		$this->assertStringContainsString( 'https:\/\/example.com\/new-page\/', $r['text'] );
	}

	public function test_menu_url_is_replaced_only_on_an_exact_match() {
		$this->assertSame( self::NEW, CCC_Url_Replacer::rewrite( '/old-page/', 'menu', $this->op() )['text'] );
		$this->assertSame( 0, CCC_Url_Replacer::rewrite( '/old-page/x', 'menu', $this->op() )['count'] );
		$this->assertSame( 0, CCC_Url_Replacer::rewrite( self::OLD, 'menu', $this->op( array( 'unlink' => true ) ) )['count'] );
	}

	public function test_page_scope_writes_the_row_logs_one_entry_and_reverts_byte_for_byte() {
		$html = '<p><iframe src="https://www.youtube.com/embed/x"></iframe><a href="https://example.com/old-page/">x</a></p>';
		cc_add_post( 7, 'https://example.com/hello/' );
		$GLOBALS['cc_posts'][7]['content'] = $html;
		$item = array( 'from' => self::OLD, 'to' => self::NEW, 'scope' => 'page', 'url' => 'https://example.com/hello/' );

		$dry = CCC_Url_Replacer::run( array( $item ), true, 'bloo' );
		$this->assertSame( 1, $dry[0]['count'] );
		$this->assertSame( array( array( 'where' => 'content', 'count' => 1 ) ), $dry[0]['locations'] );
		$this->assertSame( $html, $GLOBALS['cc_posts'][7]['content'] );
		$this->assertArrayNotHasKey( 'change_id', $dry[0] );

		$res = CCC_Url_Replacer::run( array( $item ), false, 'bloo' );
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( 7, $res[0]['post_id'] );
		$this->assertSame( array( 7 ), $GLOBALS['cc_db_writes'] );
		$this->assertSame( array(), $GLOBALS['cc_resaved'], 'never wp_update_post (kses strips embeds)' );
		$this->assertStringContainsString( '<iframe src="https://www.youtube.com/embed/x">', $GLOBALS['cc_posts'][7]['content'] );
		$this->assertStringContainsString( self::NEW, $GLOBALS['cc_posts'][7]['content'] );

		$entry = CCC_Change_Log::find( $res[0]['change_id'] );
		$this->assertSame( 'replace_urls', $entry['kind'] );
		$this->assertSame( array(), CCC_Change_Log::superseded( CCC_Change_Log::all() ) );

		$this->assertTrue( CCC_Change_Log::revert( $res[0]['change_id'], null ) );
		$this->assertSame( $html, $GLOBALS['cc_posts'][7]['content'] );
		$this->assertSame( 'ccc_already_reverted', CCC_Change_Log::revert( $res[0]['change_id'], null )->get_error_code() );
	}

	public function test_revert_refuses_after_an_edit_and_force_reverses_the_url() {
		cc_add_post( 7, 'https://example.com/hello/' );
		$GLOBALS['cc_posts'][7]['content'] = '<a href="https://example.com/old-page/">x</a>';
		$res = CCC_Url_Replacer::run( array( array( 'from' => self::OLD, 'to' => self::NEW, 'scope' => 'page', 'post_id' => 7 ) ), false, 'bloo' );
		$id  = $res[0]['change_id'];

		$GLOBALS['cc_posts'][7]['content'] = 'Intro. ' . $GLOBALS['cc_posts'][7]['content'];
		$err = CCC_Change_Log::revert( $id, null );
		$this->assertSame( 'ccc_value_changed', $err->get_error_code() );
		$this->assertSame( array( 7 ), $err->get_error_data()['post_ids'] );
		$this->assertSame( 'Intro. <a href="https://example.com/new-page/">x</a>', $GLOBALS['cc_posts'][7]['content'] );

		$this->assertTrue( CCC_Change_Log::revert( $id, null, true ) );
		$this->assertSame( 'Intro. <a href="https://example.com/old-page/">x</a>', $GLOBALS['cc_posts'][7]['content'] );
	}

	public function test_forced_revert_of_an_edited_unlink_refuses() {
		cc_add_post( 7, 'https://example.com/hello/' );
		$GLOBALS['cc_posts'][7]['content'] = '<a href="https://example.com/old-page/">x</a>';
		$res = CCC_Url_Replacer::run( array( array( 'from' => self::OLD, 'unlink' => true, 'scope' => 'page', 'post_id' => 7 ) ), false, 'bloo' );
		$this->assertSame( 'x', $GLOBALS['cc_posts'][7]['content'] );
		$GLOBALS['cc_posts'][7]['content'] = 'x!';
		$this->assertSame( 'ccc_content_changed', CCC_Change_Log::revert( $res[0]['change_id'], null, true )->get_error_code() );
		$this->assertSame( 'x!', $GLOBALS['cc_posts'][7]['content'] );
	}

	public function test_a_replace_entry_is_hidden_from_a_user_who_cannot_edit_every_target() {
		cc_add_post( 7, 'https://example.com/a/' );
		cc_add_post( 8, 'https://example.com/b/' );
		CCC_Change_Log::record_entry(
			array(
				'kind'    => 'replace_urls',
				'post_id' => 0,
				'field'   => 'links',
				'from'    => self::OLD,
				'to'      => self::NEW,
				'targets' => array(
					array( 'post_id' => 7, 'where' => 'content' ),
					array( 'post_id' => 8, 'where' => 'content' ),
				),
			),
			'editor'
		);
		$GLOBALS['cc_deny_manage_options'] = true;
		$this->assertCount( 1, CCC_Change_Log::visible() );
		$GLOBALS['cc_deny'] = array( 8 );
		$this->assertCount( 0, CCC_Change_Log::visible() );
	}

	public function test_needle_is_a_literal_piece_every_stored_form_contains() {
		$this->assertSame( 'old-page', CCC_Url_Replacer::needle( self::OLD ) );
		$this->assertSame( 'example.com', CCC_Url_Replacer::needle( 'https://example.com/' ) );
		$this->assertSame( 'campaign', CCC_Url_Replacer::needle( 'https://example.com/a/?utm=campaign&b=1' ) );
	}
}
