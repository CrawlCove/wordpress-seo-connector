<?php

use PHPUnit\Framework\TestCase;

class ServiceTest extends TestCase {

	private CCC_Adapter $adapter;

	protected function setUp(): void {
		cc_reset_wp();
		$this->adapter = new CCC_Adapter( 'yoast', '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
		cc_add_post( 7, 'https://example.com/hello', 'Hello' );
		cc_add_post( 8, 'https://example.com/world', 'World' );
	}

	// ── resolve_url ────────────────────────────────────────────────

	public function test_resolve_rejects_empty_and_foreign_urls() {
		$this->assertSame( 'ccc_bad_url', CCC_Service::resolve_url( '' )->get_error_code() );
		$this->assertSame( 'ccc_wrong_site', CCC_Service::resolve_url( 'https://other-site.com/hello' )->get_error_code() );
	}

	public function test_resolve_maps_url_to_post_id() {
		$this->assertSame( 7, CCC_Service::resolve_url( 'https://example.com/hello' ) );
	}

	public function test_resolve_host_check_is_case_insensitive() {
		// Uppercase host must pass the same-site check (the stub's
		// url_to_postid is exact-match, so it then reports unresolvable —
		// the point is it is NOT ccc_wrong_site).
		$err = CCC_Service::resolve_url( 'https://EXAMPLE.com/hello' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_resolve_unresolvable_url_404s() {
		$err = CCC_Service::resolve_url( 'https://example.com/category/stuff/' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_resolve_rejects_a_url_to_id_match_with_no_such_post() {
		// Real WordPress's url_to_postid() pattern-matches "?p=N" straight out
		// of the query string and returns N even if no post N exists (confirmed
		// against a live install) — simulate that split here: the URL "resolves"
		// to an id that has no post record.
		$GLOBALS['cc_urls']['https://example.com/?p=999'] = 999;
		$err = CCC_Service::resolve_url( 'https://example.com/?p=999' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_resolve_refuses_a_post_the_user_may_not_read() {
		// "?p=N" reaches any post id; someone else's draft or private post
		// must answer like a missing one, not hand describe() its title.
		cc_add_post( 11, 'https://example.com/?p=11', 'Private: Q4 plan' );
		$GLOBALS['cc_deny_read'] = array( 11 );
		$err = CCC_Service::resolve_url( 'https://example.com/?p=11' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
		$this->assertStringNotContainsString( 'Q4', $err->get_error_message() );
	}

	public function test_resolve_refuses_a_post_type_with_no_public_page() {
		// A user_request's title is the requester's email address.
		cc_add_post( 12, 'https://example.com/?p=12', 'jane.doe@example.org', 'user_request' );
		$err = CCC_Service::resolve_url( 'https://example.com/?p=12' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_resolve_still_maps_a_readable_page() {
		cc_add_post( 13, 'https://example.com/about/', 'About', 'page' );
		$this->assertSame( 13, CCC_Service::resolve_url( 'https://example.com/about/' ) );
	}

	public function test_resolve_root_url_is_the_homepage_when_no_static_front_page() {
		$this->assertSame( CCC_Service::HOME_ID, CCC_Service::resolve_url( 'https://example.com/' ) );
		$this->assertSame( CCC_Service::HOME_ID, CCC_Service::resolve_url( 'https://example.com' ) );
	}

	public function test_resolve_root_url_is_not_the_homepage_with_a_static_front_page() {
		$GLOBALS['cc_options']['show_on_front'] = 'page';
		cc_add_post( 3, 'https://example.com/' );
		$this->assertSame( 3, CCC_Service::resolve_url( 'https://example.com/' ) );
	}

	public function test_resolve_root_url_with_a_query_string_is_not_the_homepage() {
		// A "Plain" permalink post at the site root, e.g. "/?p=5" — must
		// resolve as that post, not be swallowed by the homepage match.
		$GLOBALS['cc_urls']['https://example.com/?p=5'] = 9;
		cc_add_post( 9, 'https://example.com/?p=5' );
		$this->assertSame( 9, CCC_Service::resolve_url( 'https://example.com/?p=5' ) );
	}

	// ── validate_change ────────────────────────────────────────────

	public function test_validate_needs_a_target_and_a_field() {
		$this->assertSame( 'ccc_no_target', CCC_Service::validate_change( array( 'title' => 'X' ) )->get_error_code() );
		$this->assertSame( 'ccc_nothing_to_do', CCC_Service::validate_change( array( 'post_id' => 7 ) )->get_error_code() );
		$this->assertSame( 'ccc_no_post', CCC_Service::validate_change( array( 'post_id' => 999, 'title' => 'X' ) )->get_error_code() );
		$this->assertSame( 'ccc_bad_change', CCC_Service::validate_change( 'not-an-object' )->get_error_code() );
	}

	public function test_validate_rejects_non_string_and_overlong_values() {
		$this->assertSame( 'ccc_bad_value', CCC_Service::validate_change( array( 'post_id' => 7, 'title' => array( 'x' ) ) )->get_error_code() );
		$long = str_repeat( 'a', CCC_Service::MAX_TITLE_LEN + 1 );
		$this->assertSame( 'ccc_too_long', CCC_Service::validate_change( array( 'post_id' => 7, 'title' => $long ) )->get_error_code() );
	}

	public function test_validate_sanitizes_values() {
		$valid = CCC_Service::validate_change( array( 'post_id' => 7, 'title' => "  New <b>title</b>\nline  " ) );
		$this->assertSame( 'New title line', $valid['fields']['title'] );
	}

	public function test_validate_keeps_seo_template_variables_intact() {
		// Review #233: core sanitize_text_field() strips %ca and %da as
		// octets, turning these into "tegory%" and "%te%%" on a live site.
		$cases = array(
			'%title% %sep% %category% %sep% %date%',         // Rank Math
			'%%title%% in %%category%% %%sep%% %%sitename%%', // Yoast, SEOPress
			'Updated %%date%% %%sep%% Shop',
			'50% off %%cf_ab%% deals',
		);
		foreach ( $cases as $sent ) {
			$valid = CCC_Service::validate_change( array( 'post_id' => 7, 'title' => $sent, 'description' => $sent ) );
			$this->assertSame( $sent, $valid['fields']['title'] );
			$this->assertSame( $sent, $valid['fields']['description'] );
		}
	}

	public function test_bootstrap_sanitize_text_field_strips_octets_like_core() {
		// Guards the stub: if it stopped matching core, the test above could
		// pass against a call site that would still corrupt variables.
		$this->assertSame( '%title% %sep% tegory% %sep% te%', sanitize_text_field( '%title% %sep% %category% %sep% %date%' ) );
	}

	public function test_validate_still_strips_tags_and_keeps_a_lone_less_than() {
		$valid = CCC_Service::validate_change( array( 'post_id' => 7, 'title' => 'Sizes <10mm <script>alert(1)</script><i>now</i>' ) );
		$this->assertSame( 'Sizes &lt;10mm now', $valid['fields']['title'] );
	}

	public function test_validate_resolves_url_targets() {
		$valid = CCC_Service::validate_change( array( 'url' => 'https://example.com/world', 'description' => 'D' ) );
		$this->assertSame( 8, $valid['post_id'] );
	}

	public function test_validate_accepts_explicit_post_id_zero_as_the_homepage() {
		$valid = CCC_Service::validate_change( array( 'post_id' => 0, 'title' => 'Home title' ) );
		$this->assertSame( CCC_Service::HOME_ID, $valid['post_id'] );
	}

	public function test_validate_rejects_post_id_zero_with_a_static_front_page() {
		$GLOBALS['cc_options']['show_on_front'] = 'page';
		$err = CCC_Service::validate_change( array( 'post_id' => 0, 'title' => 'X' ) );
		$this->assertSame( 'ccc_no_homepage_target', $err->get_error_code() );
	}

	public function test_validate_rejects_non_numeric_post_id_rather_than_treating_it_as_home() {
		// (int) 'abc' === 0 in PHP — must not silently become the homepage.
		$err = CCC_Service::validate_change( array( 'post_id' => 'abc', 'title' => 'X' ) );
		$this->assertSame( 'ccc_no_target', $err->get_error_code() );
	}

	// ── apply ──────────────────────────────────────────────────────

	public function test_apply_rejects_empty_and_oversized_batches() {
		$this->assertSame( 'ccc_empty_batch', CCC_Service::apply( array(), false, $this->adapter, 'u' )->get_error_code() );
		$batch = array_fill( 0, CCC_Service::MAX_BATCH + 1, array( 'post_id' => 7, 'title' => 'X' ) );
		$this->assertSame( 'ccc_batch_too_big', CCC_Service::apply( $batch, false, $this->adapter, 'u' )->get_error_code() );
	}

	public function test_dry_run_diffs_without_writing() {
		$this->adapter->set_title( 7, 'Old title' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 7, 'title' => 'New title', 'description' => 'New desc' ) ),
			true, $this->adapter, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertTrue( $res[0]['applied']['title']['changed'] );
		$this->assertSame( 'Old title', $res[0]['applied']['title']['from'] );
		$this->assertSame( 'Old title', $this->adapter->get_title( 7 ) );
		$this->assertSame( array(), CCC_Change_Log::all() );
		$this->assertSame( array(), $GLOBALS['cc_saved'] );
	}

	public function test_apply_writes_logs_and_resaves_posts() {
		$this->adapter->set_title( 7, 'Old title' );
		$res = CCC_Service::apply(
			array( array( 'url' => 'https://example.com/hello', 'title' => 'New title', 'description' => 'New desc' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( 'New title', $this->adapter->get_title( 7 ) );
		$this->assertSame( 'New desc', $this->adapter->get_description( 7 ) );
		$this->assertArrayHasKey( 'change_id', $res[0]['applied']['title'] );
		$this->assertCount( 2, CCC_Change_Log::all() );
		$this->assertSame( array( 7 ), $GLOBALS['cc_saved'] );

		$log = CCC_Change_Log::all();
		$this->assertSame( 'bloo', $log[0]['source'] );
	}

	public function test_revert_resaves_the_post_too() {
		// A revert is a real write, same as apply — it must rebuild the
		// Yoast indexable and fire clean_post_cache (via wp_update_post)
		// exactly like the original apply did, or a reverted post keeps
		// serving a stale indexable/cached page.
		$this->adapter->set_title( 7, 'Old title' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 7, 'title' => 'New title' ) ),
			false, $this->adapter, 'bloo'
		);
		$GLOBALS['cc_saved'] = array();
		CCC_Change_Log::revert( $res[0]['applied']['title']['change_id'], $this->adapter );
		$this->assertSame( array( 7 ), $GLOBALS['cc_saved'] );
	}

	public function test_apply_skips_unchanged_values() {
		$this->adapter->set_title( 7, 'Same' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 7, 'title' => 'Same' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertFalse( $res[0]['applied']['title']['changed'] );
		$this->assertArrayNotHasKey( 'change_id', $res[0]['applied']['title'] );
		$this->assertSame( array(), CCC_Change_Log::all() );
		$this->assertSame( array(), $GLOBALS['cc_saved'] );
	}

	public function test_apply_enforces_per_post_capability() {
		$GLOBALS['cc_deny'] = array( 7 );
		$res = CCC_Service::apply(
			array(
				array( 'post_id' => 7, 'title' => 'Blocked' ),
				array( 'post_id' => 8, 'title' => 'Allowed' ),
			),
			false, $this->adapter, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_forbidden', $res[0]['error'] );
		$this->assertSame( '', $this->adapter->get_title( 7 ) );
		$this->assertTrue( $res[1]['ok'] );
		$this->assertSame( 'Allowed', $this->adapter->get_title( 8 ) );
	}

	public function test_one_bad_item_does_not_block_the_rest() {
		$res = CCC_Service::apply(
			array(
				array( 'url' => 'https://elsewhere.com/x', 'title' => 'X' ),
				array( 'post_id' => 8, 'title' => 'Good' ),
			),
			false, $this->adapter, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_wrong_site', $res[0]['error'] );
		$this->assertTrue( $res[1]['ok'] );
		$this->assertSame( 'Good', $this->adapter->get_title( 8 ) );
	}

	public function test_empty_string_removes_override_and_is_revertable() {
		$this->adapter->set_description( 7, 'Bad copy' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 7, 'description' => '' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( '', $this->adapter->get_description( 7 ) );

		CCC_Change_Log::revert( $res[0]['applied']['description']['change_id'], $this->adapter );
		$this->assertSame( 'Bad copy', $this->adapter->get_description( 7 ) );
	}

	// ── homepage (HOME_ID = 0) ────────────────────────────────────

	public function test_apply_writes_the_homepage_title_and_does_not_resave_a_post() {
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'title' => 'New home title' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( 'New home title', $this->adapter->get_title( 0 ) );
		$this->assertArrayHasKey( 'change_id', $res[0]['applied']['title'] );
		// Yoast's own wpseo_titles watcher rebuilds the home indexable on the
		// option write itself; wp_update_post( ['ID' => 0] ) must never run —
		// WordPress core treats ID 0 as "insert a new post", not "no-op".
		$this->assertSame( array(), $GLOBALS['cc_saved'] );
		// No post row for a caching plugin's clean_post_cache to fire against —
		// the best-effort full-cache-purge path runs instead. WP Fastest Cache
		// and LiteSpeed Cache both expose their purge-all as an ACTION, not a
		// function (confirmed against WP Fastest Cache's real source) — pinned
		// here so a future edit can't silently swap one back to a
		// function_exists() guard that would always be false.
		$this->assertContains( 'ccc_after_uncached_write', $GLOBALS['cc_actions'] );
		$this->assertContains( 'wpfc_clear_all_cache', $GLOBALS['cc_actions'] );
		$this->assertContains( 'litespeed_purge_all', $GLOBALS['cc_actions'] );
	}

	public function test_apply_rejects_homepage_changes_for_an_adapter_without_home_support() {
		$aioseo = new CCC_Adapter( 'aioseo', '_aioseo_title', '_aioseo_description' );
		$res    = CCC_Service::apply(
			array( array( 'post_id' => 0, 'title' => 'New home title' ) ),
			false, $aioseo, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_home_unsupported', $res[0]['error'] );
	}

	public function test_apply_blocks_clearing_the_rankmath_home_title_but_allows_description() {
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$rankmath->set_title( 0, 'Existing home title' );
		$rankmath->set_description( 0, 'Existing home description' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'title' => '', 'description' => '' ) ),
			false, $rankmath, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( 'ccc_home_title_clear_unsupported', $res[0]['applied']['title']['error'] );
		$this->assertSame( 'Existing home title', $rankmath->get_title( 0 ), 'blocked field must not be written' );
		$this->assertArrayNotHasKey( 'error', $res[0]['applied']['description'] );
		$this->assertTrue( $res[0]['applied']['description']['changed'] );
	}

	public function test_apply_enforces_manage_options_for_the_homepage_not_edit_post() {
		$GLOBALS['cc_deny_manage_options'] = true;
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'title' => 'X' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_forbidden', $res[0]['error'] );
	}

	public function test_homepage_change_is_revertable() {
		$this->adapter->set_title( 0, 'Old home title' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'title' => 'New home title' ) ),
			false, $this->adapter, 'bloo'
		);
		$GLOBALS['cc_actions'] = array();
		$done                  = CCC_Change_Log::revert( $res[0]['applied']['title']['change_id'], $this->adapter );
		$this->assertTrue( $done );
		$this->assertSame( 'Old home title', $this->adapter->get_title( 0 ) );
		// A revert is a real write too — same cache-purge gap as apply() would
		// have without CCC_Service::invalidate_caches_for().
		$this->assertContains( 'ccc_after_uncached_write', $GLOBALS['cc_actions'] );
	}

	public function test_describe_homepage_target() {
		$this->adapter->set_title( 0, 'Home title' );
		$d = CCC_Service::describe( CCC_Service::HOME_ID, $this->adapter );
		$this->assertSame( 0, $d['post_id'] );
		$this->assertSame( 'https://example.com/', $d['permalink'] );
		$this->assertTrue( $d['editable'] );
		$this->assertSame( 'Home title', $d['current']['title'] );
	}

	public function test_describe_homepage_not_editable_when_adapter_lacks_support() {
		$aioseo = new CCC_Adapter( 'aioseo', '_aioseo_title', '_aioseo_description' );
		$d      = CCC_Service::describe( CCC_Service::HOME_ID, $aioseo );
		$this->assertFalse( $d['editable'] );
	}

	public function test_can_edit_target_uses_manage_options_for_the_homepage() {
		$this->assertTrue( CCC_Service::can_edit_target( CCC_Service::HOME_ID ) );
		$GLOBALS['cc_deny_manage_options'] = true;
		$this->assertFalse( CCC_Service::can_edit_target( CCC_Service::HOME_ID ) );
		// Unaffected: post-level checks still key off edit_post, not manage_options.
		$this->assertTrue( CCC_Service::can_edit_target( 7 ) );
	}

	// ── multilingual homepage (Polylang, lang alongside HOME_ID) ───

	public function test_resolve_language_prefixed_homepage_when_polylang_active() {
		cc_enable_polylang( array( 'en', 'fr' ), 'en' );
		$lang = 'unset';
		$this->assertSame( CCC_Service::HOME_ID, CCC_Service::resolve_url( 'https://example.com/fr/', $lang ) );
		$this->assertSame( 'fr', $lang );
	}

	public function test_resolve_default_language_home_reports_no_lang() {
		cc_enable_polylang( array( 'en', 'fr' ), 'en' );
		$lang = 'unset';
		$this->assertSame( CCC_Service::HOME_ID, CCC_Service::resolve_url( 'https://example.com/', $lang ) );
		$this->assertSame( '', $lang );
	}

	public function test_resolve_language_home_unresolvable_without_polylang() {
		// No cc_enable_polylang() call — pll_languages_list() stub returns [].
		$err = CCC_Service::resolve_url( 'https://example.com/fr/' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_resolve_language_home_not_matched_with_a_static_front_page() {
		// Each language's static front page is its own real, distinct post —
		// already resolved by url_to_postid(), nothing special to do; a
		// "/fr/" that isn't a real post/term stays unresolvable, not HOME_ID.
		cc_enable_polylang( array( 'en', 'fr' ), 'en' );
		$GLOBALS['cc_options']['show_on_front'] = 'page';
		$err = CCC_Service::resolve_url( 'https://example.com/fr/' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_apply_writes_a_non_default_language_homepage_title_via_yoast() {
		cc_enable_polylang( array( 'en', 'fr' ), 'en' );
		$this->adapter->set_title( 0, 'English Home' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'lang' => 'fr', 'title' => 'Accueil Francais' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( 'Accueil Francais', $this->adapter->get_title( 0, 'fr' ) );
		// The default-language value is untouched by a language-specific write.
		$this->assertSame( 'English Home', $this->adapter->get_title( 0 ) );
	}

	public function test_apply_rejects_lang_for_an_adapter_without_language_home_support() {
		cc_enable_polylang( array( 'en', 'fr' ), 'en' );
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$res      = CCC_Service::apply(
			array( array( 'post_id' => 0, 'lang' => 'fr', 'title' => 'X' ) ),
			false, $rankmath, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_language_unsupported', $res[0]['error'] );
	}

	public function test_apply_rejects_lang_on_a_non_homepage_target() {
		cc_enable_polylang( array( 'en', 'fr' ), 'en' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 7, 'lang' => 'fr', 'title' => 'X' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_language_requires_home', $res[0]['error'] );
	}

	public function test_apply_rejects_lang_without_a_multilingual_plugin() {
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'lang' => 'fr', 'title' => 'X' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_multilingual_required', $res[0]['error'] );
	}

	public function test_apply_rejects_an_inactive_language_code() {
		cc_enable_polylang( array( 'en', 'fr' ), 'en' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'lang' => 'de', 'title' => 'X' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_no_such_language', $res[0]['error'] );
	}

	public function test_apply_treats_the_default_language_code_as_plain_homepage() {
		// lang === the site's own default language is not an error and not
		// special-cased as a translation — it IS the plain homepage target.
		cc_enable_polylang( array( 'en', 'fr' ), 'en' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'lang' => 'en', 'title' => 'Plain Home' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( 'Plain Home', $this->adapter->get_title( 0 ) );
	}

	public function test_language_homepage_change_is_revertable() {
		cc_enable_polylang( array( 'en', 'fr' ), 'en' );
		$this->adapter->set_title( 0, 'Old French Title', 'fr' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'lang' => 'fr', 'title' => 'New French Title' ) ),
			false, $this->adapter, 'bloo'
		);
		$done = CCC_Change_Log::revert( $res[0]['applied']['title']['change_id'], $this->adapter );
		$this->assertTrue( $done );
		$this->assertSame( 'Old French Title', $this->adapter->get_title( 0, 'fr' ) );
	}

	public function test_describe_language_homepage_target() {
		cc_enable_polylang( array( 'en', 'fr' ), 'en' );
		$this->adapter->set_title( 0, 'Titre', 'fr' );
		$d = CCC_Service::describe( CCC_Service::HOME_ID, $this->adapter, 'fr' );
		$this->assertSame( 0, $d['post_id'] );
		$this->assertSame( 'fr', $d['lang'] );
		$this->assertSame( 'https://example.com/fr/', $d['permalink'] );
		$this->assertTrue( $d['editable'] );
		$this->assertSame( 'Titre', $d['current']['title'] );
	}

	public function test_describe_language_homepage_not_editable_for_rankmath() {
		cc_enable_polylang( array( 'en', 'fr' ), 'en' );
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$d        = CCC_Service::describe( CCC_Service::HOME_ID, $rankmath, 'fr' );
		$this->assertFalse( $d['editable'] );
	}

	// ── TranslatePress URL resolution (no separate content/storage per
	//    language, unlike Polylang — a language-prefixed URL just needs its
	//    prefix stripped before ordinary resolution runs; no `lang` field
	//    involved at all) ────────────────────────────────────────────

	public function test_resolve_strips_translatepress_language_prefix_from_a_post_url() {
		cc_enable_translatepress( 'en_US', array( 'fr_FR' => 'fr' ) );
		cc_add_post( 9, 'https://example.com/?p=5' );
		$this->assertSame( 9, CCC_Service::resolve_url( 'https://example.com/fr/?p=5' ) );
	}

	public function test_resolve_translatepress_prefixed_homepage_is_home_id_with_no_lang() {
		cc_enable_translatepress( 'en_US', array( 'fr_FR' => 'fr' ) );
		$lang = 'unset';
		$this->assertSame( CCC_Service::HOME_ID, CCC_Service::resolve_url( 'https://example.com/fr/', $lang ) );
		// Unlike Polylang: TranslatePress has no per-language SEO storage, so
		// there is nothing for $lang to report — it stays '' even for a
		// non-default-language URL, correctly.
		$this->assertSame( '', $lang );
	}

	public function test_resolve_translatepress_default_language_url_is_unaffected() {
		cc_enable_translatepress( 'en_US', array( 'fr_FR' => 'fr' ) );
		cc_add_post( 9, 'https://example.com/?p=5' );
		$this->assertSame( 9, CCC_Service::resolve_url( 'https://example.com/?p=5' ) );
	}

	public function test_resolve_translatepress_prefix_ignored_without_configuration() {
		// No cc_enable_translatepress() call — no `trp_settings` option, same
		// as TranslatePress not installed or never configured. A "/fr/"
		// segment is then just an ordinary (unresolvable) path, not stripped.
		$err = CCC_Service::resolve_url( 'https://example.com/fr/?p=5' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_resolve_translatepress_unrecognized_prefix_is_left_alone() {
		cc_enable_translatepress( 'en_US', array( 'fr_FR' => 'fr' ) );
		cc_add_post( 9, 'https://example.com/?p=5' );
		// "/de/" isn't a configured language slug — must not be stripped as
		// if it were, which would wrongly resolve a different, unintended URL.
		$err = CCC_Service::resolve_url( 'https://example.com/de/?p=5' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	// ── taxonomy terms (negative post_id sentinel) ─────────────────

	public function test_resolve_falls_back_to_term_resolution_after_post_lookup_fails() {
		cc_add_term( 5, 'category', 'News', 'https://example.com/category/news/' );
		$this->assertSame( -5, CCC_Service::resolve_url( 'https://example.com/category/news/' ) );
	}

	public function test_resolve_prefers_a_real_post_match_over_a_term_match() {
		// url_to_postid() is checked first; only its miss falls through to
		// term resolution — a URL that resolves as a post never reaches the
		// term resolver at all.
		cc_add_post( 7, 'https://example.com/hello' );
		$this->assertSame( 7, CCC_Service::resolve_url( 'https://example.com/hello' ) );
	}

	public function test_resolve_term_url_for_a_term_that_no_longer_exists_is_unresolvable() {
		// CCC_Term_Resolver "matched" a term id, but CCC_Service re-verifies
		// it with get_term() before trusting it — belt and suspenders against
		// a resolver returning a stale/deleted term id.
		$GLOBALS['cc_term_urls']['https://example.com/category/gone/'] = 999;
		$err = CCC_Service::resolve_url( 'https://example.com/category/gone/' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_validate_accepts_explicit_negative_post_id_as_a_term() {
		cc_add_term( 5, 'category', 'News' );
		$valid = CCC_Service::validate_change( array( 'post_id' => -5, 'title' => 'X' ) );
		$this->assertSame( -5, $valid['post_id'] );
	}

	public function test_validate_rejects_a_term_id_with_no_such_term() {
		$err = CCC_Service::validate_change( array( 'post_id' => -999, 'title' => 'X' ) );
		$this->assertSame( 'ccc_no_term', $err->get_error_code() );
	}

	public function test_validate_rejects_a_term_from_a_non_public_taxonomy() {
		// Real example: Polylang's own internal "language" taxonomy is
		// publicly_queryable (so its rewrite rules work) but public=false.
		// resolve_url() never hands out such a term (CCC_Term_Resolver
		// filters on the same thing), so a caller passing one straight to
		// /apply or /revert must be rejected too, not just silently allowed
		// because the term itself genuinely exists.
		cc_add_term( 5, 'language', 'French' );
		cc_set_taxonomy_public( 'language', false );
		$err = CCC_Service::validate_change( array( 'post_id' => -5, 'title' => 'X' ) );
		$this->assertSame( 'ccc_no_term', $err->get_error_code() );
	}

	public function test_apply_writes_a_term_title_for_a_supporting_adapter() {
		cc_add_term( 5, 'category', 'News' );
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$res      = CCC_Service::apply(
			array( array( 'post_id' => -5, 'title' => 'New term title' ) ),
			false, $rankmath, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( 'New term title', $rankmath->get_title( -5 ) );
		$this->assertArrayHasKey( 'change_id', $res[0]['applied']['title'] );
		// A term is not a post — must never trigger the Yoast-indexable
		// re-save path (this is the exact class of bug wp_update_post(['ID'
		// => 0]) was for the homepage; a negative "ID" would be nonsense to
		// core entirely).
		$this->assertSame( array(), $GLOBALS['cc_saved'] );
		$this->assertContains( 'ccc_after_uncached_write', $GLOBALS['cc_actions'] );
	}

	public function test_apply_rejects_term_changes_for_an_adapter_without_term_support() {
		cc_add_term( 5, 'category', 'News' );
		$aioseo = new CCC_Adapter( 'aioseo', '_aioseo_title', '_aioseo_description' );
		$res    = CCC_Service::apply(
			array( array( 'post_id' => -5, 'title' => 'X' ) ),
			false, $aioseo, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_term_unsupported', $res[0]['error'] );
	}

	public function test_apply_enforces_edit_term_capability() {
		cc_add_term( 5, 'category', 'News' );
		$GLOBALS['cc_deny_terms'] = array( 5 );
		$rankmath                 = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$res                      = CCC_Service::apply(
			array( array( 'post_id' => -5, 'title' => 'X' ) ),
			false, $rankmath, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_forbidden', $res[0]['error'] );
	}

	public function test_term_change_is_revertable() {
		cc_add_term( 5, 'category', 'News' );
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$rankmath->set_title( -5, 'Old term title' );
		$res  = CCC_Service::apply(
			array( array( 'post_id' => -5, 'title' => 'New term title' ) ),
			false, $rankmath, 'bloo'
		);
		$GLOBALS['cc_actions'] = array();
		$done                  = CCC_Change_Log::revert( $res[0]['applied']['title']['change_id'], $rankmath );
		$this->assertTrue( $done );
		$this->assertSame( 'Old term title', $rankmath->get_title( -5 ) );
		$this->assertContains( 'ccc_after_uncached_write', $GLOBALS['cc_actions'] );
	}

	public function test_revert_fails_when_the_term_no_longer_exists() {
		cc_add_term( 5, 'category', 'News' );
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$res      = CCC_Service::apply(
			array( array( 'post_id' => -5, 'title' => 'New term title' ) ),
			false, $rankmath, 'bloo'
		);
		unset( $GLOBALS['cc_terms'][5] );
		$err = CCC_Change_Log::revert( $res[0]['applied']['title']['change_id'], $rankmath );
		$this->assertSame( 'ccc_term_gone', $err->get_error_code() );
	}

	public function test_describe_term_target() {
		cc_add_term( 5, 'category', 'News', null, 'https://example.com/category/news/' );
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$rankmath->set_title( -5, 'Term title' );
		$d = CCC_Service::describe( -5, $rankmath );
		$this->assertSame( -5, $d['post_id'] );
		$this->assertSame( 'News', $d['post_title'] );
		$this->assertSame( 'https://example.com/category/news/', $d['permalink'] );
		$this->assertTrue( $d['editable'] );
		$this->assertSame( 'Term title', $d['current']['title'] );
	}

	public function test_describe_term_not_editable_when_adapter_lacks_support() {
		cc_add_term( 5, 'category', 'News' );
		$aioseo = new CCC_Adapter( 'aioseo', '_aioseo_title', '_aioseo_description' );
		$d      = CCC_Service::describe( -5, $aioseo );
		$this->assertFalse( $d['editable'] );
	}

	public function test_can_edit_target_uses_edit_term_for_terms() {
		cc_add_term( 5, 'category', 'News' );
		$this->assertTrue( CCC_Service::can_edit_target( -5 ) );
		$GLOBALS['cc_deny_terms'] = array( 5 );
		$this->assertFalse( CCC_Service::can_edit_target( -5 ) );
		// Unaffected: post-level checks still key off edit_post.
		$this->assertTrue( CCC_Service::can_edit_target( 7 ) );
	}
}
