<?php
/**
 * Core service: URL resolution, validation and applying changes.
 * Kept free of WP_REST_* types so the logic is unit-testable.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core service: URL resolution, validation and applying changes.
 */
class CCC_Service {

	const MAX_TITLE_LEN       = 512;
	const MAX_DESCRIPTION_LEN = 1024;
	const MAX_BATCH           = 50;

	/**
	 * Sentinel post id meaning "the homepage", when the site has no static
	 * front page (Settings -> Reading -> "Your latest posts"). A site with a
	 * static front page has no need of this — that page's own real post id
	 * already resolves and writes through the normal per-post path.
	 */
	const HOME_ID = 0;

	/**
	 * Resolve a URL on this site to a post id, to HOME_ID for the "your
	 * latest posts" homepage, or to a NEGATIVE int (-$term_id) for a
	 * taxonomy archive (e.g. /category/news/).
	 *
	 * @param string $url  URL to resolve.
	 * @param string $lang Out param (by reference, like preg_match()'s
	 *                     $matches) — set to a non-default Polylang language
	 *                     slug when $url resolved to THAT language's "your
	 *                     latest posts" homepage (e.g. "fr" for "/fr/"); ''
	 *                     for the default language, a non-multilingual site,
	 *                     or any non-HOME_ID result. Callers that don't need
	 *                     it can omit the argument entirely, same as
	 *                     preg_match()'s optional $matches.
	 * @return int|WP_Error Post id (>= 0), a negative term-id sentinel, or an error explaining why not.
	 */
	public static function resolve_url( $url, &$lang = null ) {
		$lang = '';

		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return new WP_Error( 'ccc_bad_url', __( 'Empty URL.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}

		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $url_host || strtolower( $url_host ) !== strtolower( (string) $site_host ) ) {
			return new WP_Error(
				'ccc_wrong_site',
				/* translators: %s: the hostname of this WordPress site */
				sprintf( __( 'URL is not on this site (%s). Check the site profile in Crawl Cove.', 'crawl-cove-connector' ), $site_host ),
				array( 'status' => 400 )
			);
		}

		// TranslatePress (unlike Polylang) has no separate post/page per
		// language and no per-language SEO storage — it translates the SAME
		// underlying content's rendered strings at output time, keyed by a
		// URL path prefix it manages itself with plain string manipulation,
		// not a WP rewrite rule (verified against its own source: no
		// add_rewrite_rule() call anywhere in the plugin). url_to_postid()
		// and this class's own rewrite-rule matching have no way to know
		// about that prefix, so a non-default-language URL would otherwise
		// be ccc_unresolvable even though the exact same post/page/term/
		// homepage is one strip away. Stripping it here, before any
		// resolution runs, means every path below (post, term, homepage)
		// handles a TranslatePress URL identically to its default-language
		// counterpart with no further special-casing — and $lang stays ''
		// throughout, correctly: there is nothing per-language to report.
		$url = self::strip_translatepress_language_prefix( $url );

		if ( self::is_home_url( $url ) ) {
			return self::HOME_ID;
		}

		// A NEGATIVE post_id (-$term_id) is this plugin's own sentinel for
		// "this target is a term, not a post", the same trick HOME_ID (0)
		// already uses for the homepage (term ids are always positive, so 0
		// and negative numbers are both free to repurpose).
		//
		// Term resolution by query string runs BEFORE url_to_postid(), not
		// after: on a site with a static front page, url_to_postid() has its
		// own quirk where *any* query string at the site root (e.g. a term
		// archive's "?cat=2") collapses to the front page's post id, because
		// its own "is what's left the home URL?" check runs before rewrite
		// matching. A named taxonomy query var is confirmed against real
		// WordPress to be a far more precise signal than that coarse check.
		$term_id = CCC_Term_Resolver::resolve_plain_query_vars( $url );
		if ( $term_id ) {
			$term = get_term( $term_id );
			if ( $term && ! is_wp_error( $term ) ) {
				return -$term_id;
			}
		}

		$post_id = url_to_postid( $url );
		// url_to_postid() pattern-matches "?p=N" / "?page_id=N" straight out of
		// the query string without checking the post exists — confirmed against
		// real WordPress (a plain-permalink URL for a deleted/never-existing id
		// still returns that id). Verify it ourselves so a stale or guessed
		// numeric URL reports unresolvable instead of a phantom "resolved" post.
		//
		// The same "?p=N" shortcut reaches ANY post id, so a post this user
		// may not read (someone else's draft or private post) or one with no
		// public page at all (a user_request, whose title is the requester's
		// email) must not resolve either: describe() would hand back its title
		// and SEO values. It answers exactly like a missing post, so the reply
		// is not an oracle for which ids exist (review #227).
		if ( $post_id && get_post( $post_id ) && self::can_read_post( $post_id ) ) {
			return $post_id;
		}

		// url_to_postid() only ever matches a *singular* query — a taxonomy
		// archive under pretty permalinks (e.g. /category/news/) never
		// resolves there. Try rewrite-rule term resolution before giving up.
		$term_id = CCC_Term_Resolver::resolve_pretty_permalink( $url );
		if ( $term_id ) {
			$term = get_term( $term_id );
			if ( $term && ! is_wp_error( $term ) ) {
				return -$term_id;
			}
		}

		// Last resort, only when Polylang is active and this site's homepage
		// has no static front page: a language-prefixed URL (e.g. "/fr/")
		// that is none of the above IS the "your latest posts" homepage —
		// just for a language other than the default one, which
		// is_home_url() (correctly) never matches. See BACKLOG.md/
		// SECURITY-NOTES.md's 26 Sept entries for why this was previously
		// left unresolvable rather than misresolved.
		$matched_lang = self::resolve_polylang_home_language( $url );
		if ( '' !== $matched_lang ) {
			$lang = $matched_lang;
			return self::HOME_ID;
		}

		return new WP_Error(
			'ccc_unresolvable',
			__( 'URL does not map to a post, page or taxonomy archive.', 'crawl-cove-connector' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Strip a TranslatePress non-default-language URL slug (e.g. "/fr/" in
	 * "/fr/hello-world/") from $url, so every resolution path below sees the
	 * same URL it would for the default language. '' active-plugin check and
	 * TranslatePress's own `trp_settings` option (`default-language`,
	 * `url-slugs`) are read directly rather than guessed — TranslatePress
	 * ships no public `@api` function for this (unlike Polylang's
	 * `pll_home_url()`), confirmed against its own source.
	 *
	 * Directory URL mode only (TranslatePress's only mode in the free
	 * plugin; subdomain/separate-domain per-language URLs are a paid add-on
	 * this method does not attempt to handle — a subdomain URL simply fails
	 * the site-host check earlier in resolve_url() and is reported
	 * ccc_wrong_site, same as any other URL for a different host, not
	 * silently mismatched).
	 *
	 * @param string $url URL already confirmed to be on this site.
	 * @return string $url with a recognized language slug segment removed, or $url unchanged.
	 */
	private static function strip_translatepress_language_prefix( $url ) {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return $url;
		}
		$settings = get_option( 'trp_settings' );
		if ( empty( $settings['url-slugs'] ) || ! is_array( $settings['url-slugs'] ) ) {
			return $url;
		}
		$default_lang = isset( $settings['default-language'] ) ? $settings['default-language'] : '';
		$home_path    = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$url_path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 0 !== strpos( $url_path, $home_path ) ) {
			return $url; // Not even under this site's own path — resolve_url()'s host check runs before this, but the path itself could still differ.
		}
		$relative = trim( substr( $url_path, strlen( $home_path ) ), '/' );
		if ( '' === $relative ) {
			return $url; // Bare homepage, no language segment present to strip.
		}
		$segments = explode( '/', $relative, 2 );
		$first    = $segments[0];
		foreach ( $settings['url-slugs'] as $lang_code => $slug ) {
			if ( $lang_code === $default_lang || '' === $slug || $slug !== $first ) {
				continue;
			}
			$rest    = isset( $segments[1] ) ? $segments[1] : '';
			$new_url = home_url( '/' . $rest );
			$query   = (string) wp_parse_url( $url, PHP_URL_QUERY );
			return '' !== $query ? $new_url . '?' . $query : $new_url;
		}
		return $url;
	}

	/**
	 * Whether $url is a NON-default Polylang language's "your latest posts"
	 * homepage. '' when Polylang isn't active, this site has a static front
	 * page (each language's front page is then a real, separate post —
	 * already resolved by url_to_postid() above, nothing special needed),
	 * or $url doesn't match any active language's home URL.
	 *
	 * Deliberately uses `pll_home_url()` — Polylang's own public `@api`
	 * function — rather than hand-parsing the URL: it already covers all
	 * three of Polylang's URL modes (directory "/fr/", subdomain
	 * "fr.example.com", and separate per-language domains), verified against
	 * a real Polylang install (directory mode) end-to-end, across separate
	 * HTTP requests, not just read from source.
	 *
	 * @param string $url URL already confirmed to be on this site.
	 * @return string Non-default language slug, or ''.
	 */
	private static function resolve_polylang_home_language( $url ) {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			return '';
		}
		if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_home_url' ) ) {
			return '';
		}
		$default_lang = function_exists( 'pll_default_language' ) ? pll_default_language() : '';
		$url_path     = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' !== (string) wp_parse_url( $url, PHP_URL_QUERY ) ) {
			return ''; // Same rule as is_home_url(): a query string is never a homepage URL.
		}
		foreach ( pll_languages_list() as $lang_slug ) {
			if ( $lang_slug === $default_lang ) {
				continue; // Default language is is_home_url()'s job, already tried above.
			}
			$candidate_path = untrailingslashit( (string) wp_parse_url( pll_home_url( $lang_slug ), PHP_URL_PATH ) );
			if ( $url_path === $candidate_path ) {
				return $lang_slug;
			}
		}
		return '';
	}

	/**
	 * Whether a URL is this site's homepage, only when that homepage has no
	 * static front page assigned (Settings -> Reading -> "Your latest
	 * posts") — a static front page is just a normal page and is left to
	 * resolve through url_to_postid() like any other post. The caller has
	 * already confirmed the host matches this site.
	 *
	 * A URL with any query string is never the homepage, even if its path
	 * matches — the "Plain" permalink structure addresses individual posts
	 * as "/?p=N" and "/?page_id=N", so stripping the query string first
	 * would make a specific post indistinguishable from the homepage (this
	 * was a real bug: `/?p=999` was misread as the homepage before this
	 * check was added). Scheme (http/https) is intentionally not compared.
	 *
	 * @param string $url URL already confirmed to be on this site.
	 * @return bool
	 */
	private static function is_home_url( $url ) {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			return false;
		}
		if ( '' !== (string) wp_parse_url( $url, PHP_URL_QUERY ) ) {
			return false;
		}
		$url_path  = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		return $url_path === $home_path;
	}

	/**
	 * Whether the current user may edit the given target — a real post, the
	 * homepage (which has no post to check `edit_post` against, so
	 * `manage_options` — the capability Settings -> Reading requires — is
	 * used instead), or a taxonomy term (`edit_term`, WordPress core's own
	 * meta capability, maps through to the term's taxonomy — e.g.
	 * `manage_categories` for category/post_tag).
	 *
	 * @param int $post_id Post id, HOME_ID, or a negative term-id sentinel.
	 * @return bool
	 */
	public static function can_edit_target( $post_id ) {
		if ( self::HOME_ID === $post_id ) {
			return current_user_can( 'manage_options' );
		}
		if ( $post_id < 0 ) {
			return current_user_can( 'edit_term', -$post_id );
		}
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Whether the current user may see the given post through /resolve: core's
	 * own `read_post` meta capability (drafts and private posts map through to
	 * the author / read_private_* checks, as on the REST posts endpoint) on a
	 * post type that has a public page at all.
	 *
	 * @param int $post_id Existing post id (> 0).
	 * @return bool
	 */
	public static function can_read_post( $post_id ) {
		return current_user_can( 'read_post', $post_id ) && is_post_type_viewable( get_post_type( $post_id ) );
	}

	/**
	 * Describe one target's current SEO values for the desktop app's diff
	 * view — a real post, or the homepage (HOME_ID).
	 *
	 * @param int         $post_id Post id, or HOME_ID.
	 * @param CCC_Adapter $adapter Active SEO adapter to read current values from.
	 * @param string      $lang    Non-default Polylang language slug, HOME_ID only; '' otherwise.
	 * @return array
	 */
	public static function describe( $post_id, CCC_Adapter $adapter, $lang = '' ) {
		if ( self::HOME_ID === $post_id ) {
			return array(
				'post_id'    => self::HOME_ID,
				'lang'       => $lang,
				'post_title' => '' !== $lang
					/* translators: %s: Polylang language slug, e.g. "fr" */
					? sprintf( __( 'Homepage (latest posts, %s)', 'crawl-cove-connector' ), $lang )
					: __( 'Homepage (latest posts)', 'crawl-cove-connector' ),
				'permalink'  => ( '' !== $lang && function_exists( 'pll_home_url' ) ) ? pll_home_url( $lang ) : home_url( '/' ),
				'editable'   => self::can_edit_target( $post_id ) && $adapter->supports_home() && ( '' === $lang || $adapter->supports_language_home() ),
				'current'    => array(
					'title'       => $adapter->get_title( $post_id, $lang ),
					'description' => $adapter->get_description( $post_id, $lang ),
				),
			);
		}
		if ( $post_id < 0 ) {
			$term = get_term( -$post_id );
			$link = ( $term && ! is_wp_error( $term ) ) ? get_term_link( $term ) : '';
			return array(
				'post_id'    => (int) $post_id,
				'lang'       => '',
				'post_title' => ( $term && ! is_wp_error( $term ) ) ? $term->name : '',
				'permalink'  => is_wp_error( $link ) ? '' : $link,
				'editable'   => self::can_edit_target( $post_id ) && $adapter->supports_term(),
				'current'    => array(
					'title'       => $adapter->get_title( $post_id ),
					'description' => $adapter->get_description( $post_id ),
				),
			);
		}
		return array(
			'post_id'    => (int) $post_id,
			'lang'       => '',
			'post_title' => get_the_title( $post_id ),
			'permalink'  => get_permalink( $post_id ),
			'editable'   => self::can_edit_target( $post_id ),
			'current'    => array(
				'title'       => $adapter->get_title( $post_id ),
				'description' => $adapter->get_description( $post_id ),
			),
		);
	}

	/**
	 * Validate one change payload item. Returns a normalised array or WP_Error.
	 *
	 * Accepted shape: { url? , post_id?, lang?, title?, description? } — at
	 * least one of url/post_id, at least one of title/description. `lang` is
	 * only meaningful alongside post_id 0/a URL that resolves to it (the
	 * homepage) on a Polylang site — see resolve_polylang_home_language()'s
	 * docblock and CCC_Adapter::supports_language_home().
	 *
	 * @param mixed $item One raw change item from the request body.
	 * @return array|WP_Error { post_id, lang, fields: { title?: string, description?: string } }
	 */
	public static function validate_change( $item ) {
		if ( ! is_array( $item ) ) {
			return new WP_Error( 'ccc_bad_change', __( 'Each change must be an object.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}

		// An explicit `lang` on the item wins over one inferred from
		// resolving a `url` below — lets a caller re-target a change
		// discovered via URL, or supply one directly alongside a post_id.
		$lang = '';

		if ( array_key_exists( 'post_id', $item ) && is_numeric( $item['post_id'] ) ) {
			$post_id = (int) $item['post_id'];
			if ( self::HOME_ID === $post_id ) {
				if ( 'page' === get_option( 'show_on_front' ) ) {
					return new WP_Error( 'ccc_no_homepage_target', __( 'This site has a static front page. Pass that page\'s own post_id instead of 0.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
				}
			} elseif ( $post_id < 0 ) {
				$term = get_term( -$post_id );
				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error( 'ccc_no_term', __( 'No term with that id.', 'crawl-cove-connector' ), array( 'status' => 404 ) );
				}
				// resolve_url() never hands out a term whose taxonomy isn't
				// public (CCC_Term_Resolver checks this too, see its
				// docblock) — a caller passing a raw negative post_id
				// straight to /apply or /revert must be held to the same
				// rule, not just discovery via URL. Real example: Polylang's
				// own "language" taxonomy is publicly_queryable (so its
				// rewrite rules work) but public=false; without this check a
				// direct { "post_id": -5 } would silently write an SEO
				// title/description onto Polylang's internal language term.
				$taxonomy = get_taxonomy( $term->taxonomy );
				if ( ! $taxonomy || ! $taxonomy->public ) {
					return new WP_Error( 'ccc_no_term', __( 'No term with that id.', 'crawl-cove-connector' ), array( 'status' => 404 ) );
				}
			} elseif ( ! get_post( $post_id ) || ! is_post_type_viewable( get_post_type( $post_id ) ) ) {
				// Same rule /resolve applies: a type with no public page (a
				// privacy request, a revision, a reusable block) has no SEO
				// title to set, and an Editor passes edit_post on several of
				// them, so a raw post_id must not write meta onto one.
				return new WP_Error( 'ccc_no_post', __( 'No post with that id.', 'crawl-cove-connector' ), array( 'status' => 404 ) );
			}
		} elseif ( isset( $item['url'] ) ) {
			$post_id = self::resolve_url( $item['url'], $lang );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
		} else {
			return new WP_Error( 'ccc_no_target', __( 'A change needs a url or a post_id.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'lang', $item ) && null !== $item['lang'] && '' !== $item['lang'] ) {
			if ( ! is_string( $item['lang'] ) ) {
				return new WP_Error( 'ccc_bad_value', __( 'lang must be a string.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
			}
			$lang = $item['lang'];
		}

		if ( '' !== $lang ) {
			if ( self::HOME_ID !== $post_id ) {
				return new WP_Error( 'ccc_language_requires_home', __( 'lang is only meaningful for the homepage (post_id 0).', 'crawl-cove-connector' ), array( 'status' => 400 ) );
			}
			// function_exists() alone isn't quite the right signal: a real
			// Polylang install with zero languages configured (nothing to
			// target) and "Polylang isn't even active" both need the same
			// ccc_multilingual_required answer, not ccc_no_such_language.
			$active_languages = function_exists( 'pll_languages_list' ) ? pll_languages_list() : array();
			if ( ! $active_languages ) {
				return new WP_Error( 'ccc_multilingual_required', __( 'This site has no supported multilingual plugin active.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
			}
			if ( ! in_array( $lang, $active_languages, true ) ) {
				return new WP_Error( 'ccc_no_such_language', __( 'No active language with that code.', 'crawl-cove-connector' ), array( 'status' => 404 ) );
			}
			// The default language IS the plain homepage target — same
			// value, same storage, nothing "per-language" about it. Treat it
			// identically to lang being omitted so the write path below has
			// exactly one branch to worry about, not two that happen to
			// agree.
			$default_lang = function_exists( 'pll_default_language' ) ? pll_default_language() : '';
			if ( $lang === $default_lang ) {
				$lang = '';
			}
		}

		$fields = array();
		foreach ( array(
			'title'       => self::MAX_TITLE_LEN,
			'description' => self::MAX_DESCRIPTION_LEN,
		) as $field => $max ) {
			if ( ! array_key_exists( $field, $item ) ) {
				continue;
			}
			if ( ! is_string( $item[ $field ] ) ) {
				/* translators: %s: field name, either "title" or "description" */
				return new WP_Error( 'ccc_bad_value', sprintf( __( '%s must be a string.', 'crawl-cove-connector' ), $field ), array( 'status' => 400 ) );
			}
			$value = self::sanitize_meta_text( $item[ $field ] );
			if ( strlen( $value ) > $max ) {
				return new WP_Error(
					'ccc_too_long',
					/* translators: 1: field name, either "title" or "description"; 2: max length allowed */
					sprintf( __( '%1$s is longer than %2$d characters.', 'crawl-cove-connector' ), $field, $max ),
					array( 'status' => 400 )
				);
			}
			$fields[ $field ] = $value;
		}

		if ( ! $fields ) {
			return new WP_Error( 'ccc_nothing_to_do', __( 'A change needs a title and/or a description.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}

		return array(
			'post_id' => $post_id,
			'lang'    => $lang,
			'fields'  => $fields,
		);
	}

	/**
	 * Core's sanitize_text_field() minus its percent-octet removal.
	 *
	 * Core sanitize_text_field() deletes every "%" followed by two hex digits, so
	 * the SEO plugins' template variables lose letters: "%category%" becomes
	 * "tegory%" and "%%date%%" becomes "%te%%" (review #233). Yoast and Rank
	 * Math keep their variables intact in their own savers, so this does the
	 * same: invalid UTF-8 check, lone "<" to entity, strip all tags, collapse
	 * whitespace. The value is stored as plain text and escaped on output by
	 * the SEO plugin, so an encoded octet left in it is inert.
	 *
	 * @param string $value Raw title or description from the request.
	 * @return string
	 */
	private static function sanitize_meta_text( $value ) {
		$filtered = wp_check_invalid_utf8( (string) $value );
		if ( false !== strpos( $filtered, '<' ) ) {
			$filtered = wp_pre_kses_less_than( $filtered );
			$filtered = wp_strip_all_tags( $filtered, false );
			$filtered = str_replace( "<\n", "&lt;\n", $filtered );
		}
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', $filtered ) );
	}

	/**
	 * Apply a batch of changes. Per-item results; one bad item never blocks
	 * the rest. Dry-run validates and diffs without writing anything.
	 *
	 * @param array       $changes Raw items from the request body.
	 * @param bool        $dry_run Validate and diff without writing anything.
	 * @param CCC_Adapter $adapter Active SEO adapter to write through.
	 * @param string      $source  Actor recorded in the change log.
	 * @return array|WP_Error Per-item results, or WP_Error for a bad batch.
	 */
	public static function apply( $changes, $dry_run, CCC_Adapter $adapter, $source ) {
		if ( ! is_array( $changes ) || ! $changes ) {
			return new WP_Error( 'ccc_empty_batch', __( 'changes must be a non-empty array.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}
		if ( count( $changes ) > self::MAX_BATCH ) {
			return new WP_Error(
				'ccc_batch_too_big',
				/* translators: %d: maximum number of changes allowed per request */
				sprintf( __( 'At most %d changes per request.', 'crawl-cove-connector' ), self::MAX_BATCH ),
				array( 'status' => 400 )
			);
		}

		$results         = array();
		$touched_targets = array();

		foreach ( array_values( $changes ) as $i => $item ) {
			$valid = self::validate_change( $item );
			if ( is_wp_error( $valid ) ) {
				$results[] = array(
					'index'   => $i,
					'ok'      => false,
					'error'   => $valid->get_error_code(),
					'message' => $valid->get_error_message(),
				);
				continue;
			}

			$post_id = $valid['post_id'];
			$lang    = $valid['lang'];
			if ( self::HOME_ID === $post_id && ! $adapter->supports_home() ) {
				$results[] = array(
					'index'   => $i,
					'ok'      => false,
					'error'   => 'ccc_home_unsupported',
					/* translators: %s: active SEO plugin's display name */
					'message' => sprintf( __( '%s does not support homepage title/description changes yet.', 'crawl-cove-connector' ), $adapter->label() ),
				);
				continue;
			}
			if ( '' !== $lang && ! $adapter->supports_language_home() ) {
				$results[] = array(
					'index'   => $i,
					'ok'      => false,
					'error'   => 'ccc_language_unsupported',
					/* translators: %s: active SEO plugin's display name */
					'message' => sprintf( __( '%s does not support per-language homepage title/description changes yet.', 'crawl-cove-connector' ), $adapter->label() ),
				);
				continue;
			}
			if ( $post_id < 0 && ! $adapter->supports_term() ) {
				$results[] = array(
					'index'   => $i,
					'ok'      => false,
					'error'   => 'ccc_term_unsupported',
					/* translators: %s: active SEO plugin's display name */
					'message' => sprintf( __( '%s does not support taxonomy term title/description changes yet.', 'crawl-cove-connector' ), $adapter->label() ),
				);
				continue;
			}
			if ( ! self::can_edit_target( $post_id ) ) {
				$results[] = array(
					'index'   => $i,
					'ok'      => false,
					'error'   => 'ccc_forbidden',
					'message' => __( 'This user may not edit that target.', 'crawl-cove-connector' ),
				);
				continue;
			}

			$applied = array();
			foreach ( $valid['fields'] as $field => $to ) {
				// can_clear_home_title() is Rank Math's own gap (no template
				// fallback), and Rank Math never reaches this branch with a
				// non-'' $lang (it fails supports_language_home() above
				// first) — this guard is deliberately unconditional on
				// $lang, not because language homes need it too.
				if ( self::HOME_ID === $post_id && 'title' === $field && '' === $to && ! $adapter->can_clear_home_title() ) {
					$applied[ $field ] = array(
						'from'    => $adapter->get_title( $post_id, $lang ),
						'to'      => '',
						'changed' => false,
						'error'   => 'ccc_home_title_clear_unsupported',
						'message' => __( "This SEO plugin's homepage title has no automatic fallback, so clearing it would leave a blank browser-tab title. Set a new title instead, or clear it from the SEO plugin's own settings directly.", 'crawl-cove-connector' ),
					);
					continue;
				}
				$from = ( 'title' === $field ) ? $adapter->get_title( $post_id, $lang ) : $adapter->get_description( $post_id, $lang );
				$step = array(
					'from'    => $from,
					'to'      => $to,
					'changed' => ( $from !== $to ),
				);
				if ( ! $dry_run && $from !== $to ) {
					if ( 'title' === $field ) {
						$adapter->set_title( $post_id, $to, $lang );
					} else {
						$adapter->set_description( $post_id, $to, $lang );
					}
					// What the SEO plugin serves now, which revert() later
					// checks against: a cleared value can read back as a
					// fallback, and some savers rewrite the text.
					$after                       = ( 'title' === $field ) ? $adapter->get_title( $post_id, $lang ) : $adapter->get_description( $post_id, $lang );
					$entry                       = CCC_Change_Log::record( $post_id, $field, $from, $to, $source, $lang, $after );
					$step['change_id']           = $entry['id'];
					$touched_targets[ $post_id ] = true;
				}
				$applied[ $field ] = $step;
			}

			$results[] = array(
				'index'   => $i,
				'ok'      => true,
				'post_id' => $post_id,
				'dry_run' => (bool) $dry_run,
				'applied' => $applied,
			);
		}

		foreach ( array_keys( $touched_targets ) as $touched_id ) {
			self::invalidate_caches_for( $touched_id );
		}

		return $results;
	}

	/**
	 * After a real (non-dry-run) write, tell WordPress and any active
	 * caching plugin that this target's rendered page is now stale.
	 *
	 * A real post id gets a per-post purge and nothing else. It is NOT
	 * re-saved: wp_update_post() re-runs the whole post through
	 * content_save_pre, which strips iframes, scripts and forms from the
	 * content when the pushing user lacks unfiltered_html (any Author, and
	 * every site admin on multisite), so a title push deleted embeds an
	 * admin had added. Yoast rebuilds the indexable from its own post-meta
	 * watcher at shutdown, so the re-save was never needed for that.
	 * clean_post_cache() fires the action WP Super Cache, W3 Total Cache's
	 * database cache and core's object cache listen to; W3 Total Cache's page
	 * cache, LiteSpeed Cache and WP Fastest Cache only purged on the re-save's
	 * save_post/transition_post_status, so their own per-post purge APIs are
	 * called directly (each verified against the plugin's source). Filterable
	 * off via ccc_touch_post_after_apply.
	 *
	 * HOME_ID (0) and a negative term-id sentinel have no post row, and
	 * neither target's write fires clean_post_cache by any other path, so a
	 * caching plugin never learns the homepage or a taxonomy archive changed
	 * (WP Super Cache's wp_cache_post_edit()/wp_cache_post_change() both bail
	 * on post_id === 0 and are never called for term edits at all). A
	 * best-effort full-site purge covers that gap instead.
	 *
	 * @param int $post_id Post id, HOME_ID, or a negative term-id sentinel.
	 */
	public static function invalidate_caches_for( $post_id ) {
		if ( $post_id > 0 ) {
			if ( apply_filters( 'ccc_touch_post_after_apply', true ) ) {
				self::clear_post_page_cache( $post_id );
			}
			return;
		}
		if ( apply_filters( 'ccc_clear_full_cache_after_write', true ) ) {
			self::clear_full_page_cache();
		}
	}

	/**
	 * Per-post page-cache purge for one real post, without writing the post.
	 *
	 * @param int $post_id Post id (> 0).
	 */
	private static function clear_post_page_cache( $post_id ) {
		clean_post_cache( $post_id );
		if ( function_exists( 'w3tc_flush_post' ) ) { // W3 Total Cache page cache.
			w3tc_flush_post( $post_id );
		}
		do_action( 'litespeed_purge_post', $post_id ); // LiteSpeed Cache's documented API hook.
		do_action( 'wpfc_clear_post_cache_by_id', false, $post_id ); // WP Fastest Cache (comment id, post id).
		do_action( 'ccc_after_post_write', $post_id );
	}

	/**
	 * Best-effort full-site page-cache purge for the common free caching
	 * plugins, since HOME_ID/a term sentinel has no single post row a
	 * per-page purge could target. Every branch is guarded so a site
	 * without that particular plugin active does nothing extra. Always
	 * fires a plugin-agnostic action too, for anything not listed here.
	 */
	private static function clear_full_page_cache() {
		if ( function_exists( 'wp_cache_clear_cache' ) ) { // WP Super Cache.
			wp_cache_clear_cache();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) { // W3 Total Cache.
			w3tc_flush_all();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) { // WP Rocket.
			rocket_clean_domain();
		}
		// WP Fastest Cache and LiteSpeed Cache both expose their full-purge as
		// an ACTION, not a function — confirmed against WP Fastest Cache's
		// real source (wpFastestCache.php: `add_action( 'wpfc_clear_all_cache',
		// array( $this, 'deleteCache' ), 10, 1 )`), so function_exists() would
		// always be false for either and silently never fire.
		do_action( 'wpfc_clear_all_cache' );
		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache's own documented purge-all hook.
		do_action( 'ccc_after_uncached_write' );
	}
}
