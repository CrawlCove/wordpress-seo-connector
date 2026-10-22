<?php
/**
 * Change log: every applied fix is recorded with its previous value so any
 * change can be reverted — from the desktop app or the admin page.
 *
 * Stored as a single capped option: this plugin is a low-volume conduit for
 * reviewed fixes, not a bulk editor, so a custom table would be overkill.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applied-change log, capped, with revert support.
 */
class CCC_Change_Log {

	const OPTION     = 'ccc_change_log';
	const SEQ_OPTION = 'ccc_change_seq';

	/**
	 * Entries kept. A title and a description on one post are two entries,
	 * and the app sends up to CCC_Service::MAX_BATCH changes per request, so
	 * 200 (the cap until 0.10.2) was one medium push: the oldest entries of
	 * that same push were already gone — unrevertable — when it finished.
	 * Worst case at the input caps (512 + 1024 chars per entry) is ~2MB in a
	 * non-autoloaded option that only /apply, /revert and the Tools page
	 * read; typical real titles and descriptions put it well under 500KB.
	 */
	const MAX_ENTRIES = 1000;

	/**
	 * All logged changes, newest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * The logged changes the current user may see, newest first: everything
	 * for an administrator (the Tools page already shows them the full log),
	 * otherwise only entries on targets this user may edit. The log carries
	 * from/to values and the login that made each change, so an Author must
	 * not read another user's history through /changes (review #227).
	 *
	 * @return array[]
	 */
	public static function visible() {
		$log = self::all();
		if ( current_user_can( 'manage_options' ) ) {
			return $log;
		}
		$out = array();
		foreach ( $log as $entry ) {
			if ( self::can_edit_entry( $entry ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Find one logged change by id.
	 *
	 * @param int $change_id Change id.
	 * @return array|null
	 */
	public static function find( $change_id ) {
		foreach ( self::all() as $entry ) {
			if ( (int) $entry['id'] === (int) $change_id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Record one applied change and return its entry.
	 *
	 * @param int         $post_id Post the change was applied to.
	 * @param string      $field   'title' or 'description'.
	 * @param string      $from    Previous stored value ('' = was unset).
	 * @param string      $to      New stored value ('' = override removed).
	 * @param string      $source  Who applied it (REST user login).
	 * @param string      $lang    Non-default Polylang language slug, HOME_ID
	 *                             changes only (see CCC_Adapter::
	 *                             supports_language_home()); '' otherwise.
	 * @param string|null $after   What the SEO plugin reads back right after
	 *                             the write, when that differs from $to: a
	 *                             cleared value that falls back (an empty
	 *                             Polylang title reads as the default
	 *                             language's), or a saver that rewrites the
	 *                             text. revert() compares the current value
	 *                             against this. Null = same as $to.
	 * @return array The stored entry, including its id.
	 */
	public static function record( $post_id, $field, $from, $to, $source, $lang = '', $after = null ) {
		$id = (int) get_option( self::SEQ_OPTION, 0 ) + 1;
		update_option( self::SEQ_OPTION, $id, false );

		$entry = array(
			'id'       => $id,
			'post_id'  => (int) $post_id,
			'lang'     => $lang,
			'field'    => $field,
			'from'     => $from,
			'to'       => $to,
			'source'   => $source,
			'time'     => time(),
			'reverted' => false,
		);
		if ( null !== $after && (string) $after !== (string) $to ) {
			$entry['after'] = (string) $after;
		}

		$log = self::all();
		array_unshift( $log, $entry );
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = self::evict( $log );
		}
		update_option( self::OPTION, $log, false );

		return $entry;
	}

	/**
	 * Record a change of another kind (e.g. replace_urls), whose fields the
	 * caller supplies; id, source, time and reverted are added here.
	 *
	 * @param array  $fields Entry fields, including kind, post_id, field, from and to.
	 * @param string $source Who applied it (REST user login).
	 * @return array The stored entry, including its id.
	 */
	public static function record_entry( array $fields, $source ) {
		$id = (int) get_option( self::SEQ_OPTION, 0 ) + 1;
		update_option( self::SEQ_OPTION, $id, false );

		$entry = array_merge(
			$fields,
			array(
				'id'       => $id,
				'source'   => $source,
				'time'     => time(),
				'reverted' => false,
			)
		);

		$log = self::all();
		array_unshift( $log, $entry );
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = self::evict( $log );
		}
		update_option( self::OPTION, $log, false );

		return $entry;
	}

	/**
	 * Whether the current user may see and revert an entry: edit rights on
	 * its target, or on every location a URL replace touched.
	 *
	 * @param array $entry Log entry.
	 * @return bool
	 */
	public static function can_edit_entry( array $entry ) {
		if ( isset( $entry['kind'] ) && 'replace_urls' === $entry['kind'] ) {
			return CCC_Url_Replacer::can_edit_entry( $entry );
		}
		if ( isset( $entry['kind'] ) && 'site_setting' === $entry['kind'] ) {
			return current_user_can( 'manage_options' );
		}
		if ( isset( $entry['kind'] ) && 'image_alt' === $entry['kind'] ) {
			return CCC_Image_Alt::can_edit_entry( $entry );
		}
		return isset( $entry['post_id'] ) && CCC_Service::can_edit_target( (int) $entry['post_id'] );
	}

	/**
	 * Bring an over-cap log back to MAX_ENTRIES.
	 *
	 * Already-reverted entries go first (oldest first): their "before" value
	 * is back on the site and nothing further can be done with them, so they
	 * are history, not a safety net. Only if the log is still over the cap
	 * after that are the oldest live entries dropped. Order (newest first)
	 * is preserved throughout.
	 *
	 * @param array $log Log entries, newest first, more than MAX_ENTRIES.
	 * @return array Exactly MAX_ENTRIES entries, newest first.
	 */
	public static function evict( array $log ) {
		$excess = count( $log ) - self::MAX_ENTRIES;
		if ( $excess <= 0 ) {
			return $log;
		}
		for ( $i = count( $log ) - 1; $i >= 0 && $excess > 0; $i-- ) {
			if ( ! empty( $log[ $i ]['reverted'] ) ) {
				unset( $log[ $i ] );
				--$excess;
			}
		}
		$log = array_values( $log );
		return array_slice( $log, 0, self::MAX_ENTRIES );
	}

	/**
	 * Revert one change: write its previous value back through the adapter.
	 *
	 * Only while the field still holds what this change wrote: undoing an
	 * older push after a newer one, or after a hand edit in the SEO plugin,
	 * would otherwise silently overwrite the newer value (review #248). On a
	 * mismatch nothing is written and the error carries the current value;
	 * $force reverts anyway.
	 *
	 * A URL replace (kind replace_urls), a site setting (site_setting) or an
	 * image alt (image_alt) needs no SEO plugin and goes through its own
	 * class's revert() instead.
	 *
	 * @param int              $change_id Change id.
	 * @param CCC_Adapter|null $adapter   Active SEO adapter to write the reverted value through.
	 * @param bool             $force     Revert even if the value changed since.
	 * @return true|WP_Error
	 */
	public static function revert( $change_id, $adapter, $force = false ) {
		$entry = self::find( $change_id );
		if ( ! $entry ) {
			return new WP_Error( 'ccc_not_found', __( 'No change with that id.', 'crawl-cove-connector' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $entry['reverted'] ) ) {
			return new WP_Error( 'ccc_already_reverted', __( 'That change has already been reverted.', 'crawl-cove-connector' ), array( 'status' => 409 ) );
		}
		if ( isset( $entry['kind'] ) ) {
			if ( 'site_setting' === $entry['kind'] ) {
				$done = CCC_Site_Settings::revert( $entry, (bool) $force );
			} elseif ( 'image_alt' === $entry['kind'] ) {
				$done = CCC_Image_Alt::revert( $entry, (bool) $force );
			} else {
				$done = CCC_Url_Replacer::revert( $entry, (bool) $force );
			}
			if ( true === $done ) {
				self::mark_reverted( $change_id );
			}
			return $done;
		}
		if ( ! $adapter instanceof CCC_Adapter ) {
			return new WP_Error( 'ccc_no_seo_plugin', __( 'No supported SEO plugin is active (Yoast SEO, Rank Math, SEOPress or AIOSEO), so there is nothing to write to.', 'crawl-cove-connector' ), array( 'status' => 409 ) );
		}
		// The homepage (post_id 0) always "exists" — it has no post to check.
		$target_id = (int) $entry['post_id'];
		if ( CCC_Service::HOME_ID !== $target_id ) {
			if ( $target_id < 0 ) {
				$term = get_term( -$target_id );
				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error( 'ccc_term_gone', __( 'The term this change belongs to no longer exists.', 'crawl-cove-connector' ), array( 'status' => 410 ) );
				}
			} elseif ( ! get_post( $target_id ) ) {
				return new WP_Error( 'ccc_post_gone', __( 'The post this change belongs to no longer exists.', 'crawl-cove-connector' ), array( 'status' => 410 ) );
			}
		}

		// isset(), not array_key_exists(): entries logged before the
		// per-language homepage feature shipped have no 'lang' key at all,
		// and must revert exactly as they always did (the plain/default-
		// language target), not error or misbehave.
		$lang = isset( $entry['lang'] ) ? $entry['lang'] : '';
		// A per-language homepage entry can only go back where it came from.
		// With Polylang gone the Yoast writer silently does nothing, and Rank
		// Math or SEOPress would write the translation over the default
		// language's title. Refused even with force: force overrides a value
		// that drifted, not a target that no longer exists.
		if ( '' !== $lang && ! ( $adapter->supports_language_home() && function_exists( 'pll_languages_list' ) && in_array( $lang, (array) pll_languages_list(), true ) ) ) {
			return new WP_Error( 'ccc_language_unavailable', __( 'That change was made for a language this site no longer serves through a supported SEO plugin, so it cannot be reverted from here.', 'crawl-cove-connector' ), array( 'status' => 409 ) );
		}
		if ( ! $force ) {
			$current  = self::current_value( $entry, $adapter );
			$expected = isset( $entry['after'] ) ? (string) $entry['after'] : (string) $entry['to'];
			if ( $expected !== $current ) {
				return new WP_Error(
					'ccc_value_changed',
					__( 'The value has changed since this change was made, so reverting it would overwrite the newer value. Nothing was written.', 'crawl-cove-connector' ),
					array(
						'status'   => 409,
						'current'  => $current,
						'expected' => $expected,
					)
				);
			}
		}
		$adapter->set_value( (int) $entry['post_id'], $entry['field'], $entry['from'], $lang );
		// Same cache invalidation apply() runs after a real write: without
		// this a revert silently leaves stale cached HTML exactly like an
		// un-invalidated apply would. See CCC_Service::invalidate_caches_for().
		CCC_Service::invalidate_caches_for( (int) $entry['post_id'] );
		self::mark_reverted( $change_id );

		return true;
	}

	/**
	 * Flag one entry as reverted.
	 *
	 * @param int $change_id Change id.
	 */
	private static function mark_reverted( $change_id ) {
		$log = self::all();
		foreach ( $log as $i => $e ) {
			if ( (int) $e['id'] === (int) $change_id ) {
				$log[ $i ]['reverted'] = true;
			}
		}
		update_option( self::OPTION, $log, false );
	}

	/**
	 * The value a logged change's field holds right now.
	 *
	 * @param array       $entry   Log entry.
	 * @param CCC_Adapter $adapter Active SEO adapter.
	 * @return string
	 */
	public static function current_value( array $entry, CCC_Adapter $adapter ) {
		$lang = isset( $entry['lang'] ) ? $entry['lang'] : '';
		return (string) $adapter->get_value( (int) $entry['post_id'], $entry['field'], $lang );
	}

	/**
	 * For each live entry that a later live change on the same target, field
	 * and language has overwritten, the id of the next such change. Reverting
	 * the later one first makes the earlier one revertable again, so
	 * reverted entries never supersede.
	 *
	 * @param array[] $log Log entries, newest first (all()).
	 * @return int[] Superseded entry id => id of the change that superseded it.
	 */
	public static function superseded( array $log ) {
		$newer = array();
		$out   = array();
		foreach ( $log as $entry ) {
			// A URL replace has no single field to supersede; its own revert
			// checks every location it touched.
			if ( ! empty( $entry['reverted'] ) || isset( $entry['kind'] ) ) {
				continue;
			}
			$key = (int) $entry['post_id'] . '|' . $entry['field'] . '|' . ( isset( $entry['lang'] ) ? $entry['lang'] : '' );
			if ( isset( $newer[ $key ] ) ) {
				$out[ (int) $entry['id'] ] = $newer[ $key ];
			}
			$newer[ $key ] = (int) $entry['id'];
		}
		return $out;
	}
}
