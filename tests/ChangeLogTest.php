<?php

use PHPUnit\Framework\TestCase;

class ChangeLogTest extends TestCase {

	private CCC_Adapter $adapter;

	protected function setUp(): void {
		cc_reset_wp();
		$this->adapter = new CCC_Adapter( 'yoast', '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
		cc_add_post( 7, 'https://example.com/hello' );
	}

	public function test_record_assigns_incrementing_ids_newest_first() {
		$a = CCC_Change_Log::record( 7, 'title', 'Old', 'New', 'bloo' );
		$b = CCC_Change_Log::record( 7, 'description', '', 'Desc', 'bloo' );
		$this->assertSame( 1, $a['id'] );
		$this->assertSame( 2, $b['id'] );
		$log = CCC_Change_Log::all();
		$this->assertCount( 2, $log );
		$this->assertSame( 2, $log[0]['id'] );
	}

	public function test_visible_hides_other_users_targets_from_a_non_admin() {
		cc_add_post( 8, 'https://example.com/theirs' );
		CCC_Change_Log::record( 7, 'title', 'Old', 'Mine', 'author' );
		CCC_Change_Log::record( 8, 'title', 'Secret', 'Theirs', 'editor' );
		CCC_Change_Log::record( CCC_Service::HOME_ID, 'title', 'Home', 'New home', 'admin' );
		$GLOBALS['cc_deny_manage_options'] = true;
		$GLOBALS['cc_deny']                = array( 8 );

		$visible = CCC_Change_Log::visible();
		$this->assertCount( 1, $visible );
		$this->assertSame( 7, $visible[0]['post_id'] );
		$this->assertCount( 3, CCC_Change_Log::all() );
	}

	public function test_visible_is_the_full_log_for_an_admin() {
		CCC_Change_Log::record( 7, 'title', 'Old', 'New', 'bloo' );
		CCC_Change_Log::record( 999, 'title', 'Gone', 'Post', 'bloo' ); // deleted post: admin still sees it.
		$GLOBALS['cc_deny'] = array( 999 );
		$this->assertCount( 2, CCC_Change_Log::visible() );
	}

	public function test_log_is_capped_and_drops_oldest() {
		for ( $i = 1; $i <= CCC_Change_Log::MAX_ENTRIES + 5; $i++ ) {
			CCC_Change_Log::record( 7, 'title', "old$i", "new$i", 'bloo' );
		}
		$log = CCC_Change_Log::all();
		$this->assertCount( CCC_Change_Log::MAX_ENTRIES, $log );
		$this->assertSame( CCC_Change_Log::MAX_ENTRIES + 5, $log[0]['id'] );
		$this->assertNull( CCC_Change_Log::find( 1 ) );
	}

	public function test_cap_evicts_reverted_entries_before_live_ones() {
		// Fill to the cap, revert two of the NEWER entries, then push one more:
		// the oldest live entry must survive and a reverted newer one must go.
		for ( $i = 1; $i <= CCC_Change_Log::MAX_ENTRIES; $i++ ) {
			CCC_Change_Log::record( 7, 'title', "old$i", "new$i", 'bloo' );
		}
		CCC_Change_Log::revert( CCC_Change_Log::MAX_ENTRIES - 3, $this->adapter );
		CCC_Change_Log::revert( CCC_Change_Log::MAX_ENTRIES - 8, $this->adapter );

		CCC_Change_Log::record( 7, 'title', 'oldX', 'newX', 'bloo' );

		$log = CCC_Change_Log::all();
		$this->assertCount( CCC_Change_Log::MAX_ENTRIES, $log );
		$this->assertNotNull( CCC_Change_Log::find( 1 ), 'oldest live entry kept' );
		$this->assertNotNull( CCC_Change_Log::find( CCC_Change_Log::MAX_ENTRIES - 3 ), 'the newer reverted entry stays until needed' );
		$this->assertNull( CCC_Change_Log::find( CCC_Change_Log::MAX_ENTRIES - 8 ), 'the OLDEST reverted entry is the one evicted' );
		$this->assertSame( CCC_Change_Log::MAX_ENTRIES + 1, $log[0]['id'], 'newest first is preserved' );
		$ids = array_column( $log, 'id' );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
		$sorted = $ids;
		rsort( $sorted );
		$this->assertSame( $sorted, $ids, 'still strictly newest-first after eviction' );
	}

	public function test_evict_falls_back_to_oldest_live_when_no_reverted_entries_suffice() {
		$log = array();
		for ( $i = CCC_Change_Log::MAX_ENTRIES + 3; $i >= 1; $i-- ) {
			$log[] = array( 'id' => $i, 'reverted' => ( 2 === $i ) );
		}
		$out = CCC_Change_Log::evict( $log );
		$this->assertCount( CCC_Change_Log::MAX_ENTRIES, $out );
		$ids = array_column( $out, 'id' );
		$this->assertNotContains( 2, $ids, 'the one reverted entry goes first' );
		$this->assertNotContains( 1, $ids, 'then the oldest live ones' );
		$this->assertNotContains( 3, $ids );
		$this->assertContains( 4, $ids );
		$this->assertSame( CCC_Change_Log::MAX_ENTRIES + 3, $ids[0] );
	}

	public function test_revert_restores_previous_value_and_marks_entry() {
		$this->adapter->set_title( 7, 'Original' );
		$entry = CCC_Change_Log::record( 7, 'title', 'Original', 'Pushed', 'bloo' );
		$this->adapter->set_title( 7, 'Pushed' );

		$this->assertTrue( CCC_Change_Log::revert( $entry['id'], $this->adapter ) );
		$this->assertSame( 'Original', $this->adapter->get_title( 7 ) );
		$this->assertTrue( CCC_Change_Log::find( $entry['id'] )['reverted'] );
	}

	public function test_revert_of_unset_previous_value_deletes_override() {
		$entry = CCC_Change_Log::record( 7, 'description', '', 'Pushed desc', 'bloo' );
		$this->adapter->set_description( 7, 'Pushed desc' );

		CCC_Change_Log::revert( $entry['id'], $this->adapter );
		$this->assertArrayNotHasKey( '_yoast_wpseo_metadesc', $GLOBALS['cc_meta'][7] ?? array() );
	}

	public function test_double_revert_is_refused() {
		$entry = CCC_Change_Log::record( 7, 'title', 'A', 'B', 'bloo' );
		CCC_Change_Log::revert( $entry['id'], $this->adapter );
		$err = CCC_Change_Log::revert( $entry['id'], $this->adapter );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'ccc_already_reverted', $err->get_error_code() );
	}

	public function test_revert_unknown_id_and_deleted_post() {
		$err = CCC_Change_Log::revert( 999, $this->adapter );
		$this->assertSame( 'ccc_not_found', $err->get_error_code() );

		$entry = CCC_Change_Log::record( 42, 'title', 'A', 'B', 'bloo' ); // post 42 never registered
		$err   = CCC_Change_Log::revert( $entry['id'], $this->adapter );
		$this->assertSame( 'ccc_post_gone', $err->get_error_code() );
	}

	public function test_revert_of_a_homepage_change_is_not_blocked_as_post_gone() {
		// post_id 0 (the homepage) has no post row — must not be treated
		// as "the post this change belongs to no longer exists".
		$this->adapter->set_title( 0, 'Pushed home title' );
		$entry = CCC_Change_Log::record( CCC_Service::HOME_ID, 'title', 'Original home title', 'Pushed home title', 'bloo' );

		$this->assertTrue( CCC_Change_Log::revert( $entry['id'], $this->adapter ) );
		$this->assertSame( 'Original home title', $this->adapter->get_title( 0 ) );
	}
}
