<?php
/**
 * Resolves a URL on this site to a taxonomy term id, for archive pages
 * (e.g. /category/news/) that WordPress core's own url_to_postid() cannot
 * see — it only ever returns a match when WP_Query::is_singular is true.
 *
 * Not required by tests/bootstrap.php (real $wp_rewrite/WP_Query behaviour
 * cannot be stubbed meaningfully); CrawlCove_Service calls through this class by
 * name, so unit tests define their own minimal stand-in the same way they
 * already stand in for WPSEO_Options. Real behaviour is proven against a
 * live WordPress install in tests/integration/taxonomy-checks.sh.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL -> term id resolution, adapted from WordPress core's own
 * url_to_postid() (wp-includes/rewrite.php) — same rewrite-rule matching
 * mechanism, GPL-compatible, only the "what did we get" check at the end
 * differs (term-archive query instead of a singular one).
 */
class CrawlCove_Term_Resolver {

	/**
	 * "Plain" permalinks (and any structure) address a term archive by
	 * query string rather than path — WordPress core's own url_to_postid()
	 * has an equivalent early check for "?p=N"/"?page_id=N" before ever
	 * looking at rewrite rules; this is that check's term-archive
	 * equivalent. Handles the numeric `cat=N` query var (category id,
	 * WordPress core's own special case, not a taxonomy's registered
	 * query_var) plus every public taxonomy's own slug-based query_var
	 * (`category_name` for categories, `tag` for post tags, custom
	 * taxonomies' own registered query_var).
	 *
	 * CrawlCove_Service calls this BEFORE url_to_postid(), not after: on a site
	 * with a static front page, url_to_postid() has its own quirk where
	 * *any* query string at the site root (e.g. "?cat=2", nothing to do
	 * with the homepage) collapses to the front page's post id, because its
	 * "trim query string, is what's left the home URL?" check runs before
	 * rewrite-rule matching. A specific, named taxonomy query var is a far
	 * more precise signal than that coarse check, so it must win.
	 *
	 * @param string $url URL already confirmed to be on this site.
	 * @return int
	 */
	public static function resolve_plain_query_vars( $url ) {
		$query_string = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! $query_string ) {
			return 0;
		}
		parse_str( $query_string, $vars );

		if ( isset( $vars['cat'] ) && is_numeric( $vars['cat'] ) ) {
			$term = get_term( (int) $vars['cat'], 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( empty( $tax->query_var ) || ! isset( $vars[ $tax->query_var ] ) || '' === $vars[ $tax->query_var ] ) {
				continue;
			}
			$term = get_term_by( 'slug', $vars[ $tax->query_var ], $tax->name );
			if ( $term ) {
				return (int) $term->term_id;
			}
		}

		return 0;
	}

	/**
	 * Rewrite-rule matching for pretty permalinks — the same loop
	 * url_to_postid() runs (match the path against $wp_rewrite's compiled
	 * rules, turn the matched query vars into a real WP_Query), except the
	 * final check looks for a resolved taxonomy-archive query
	 * (is_tax/is_category/is_tag) instead of is_singular. CrawlCove_Service calls
	 * this only after both resolve_plain_query_vars() and url_to_postid()
	 * have already missed.
	 *
	 * @param string $url URL already confirmed to be on this site.
	 * @return int
	 */
	public static function resolve_pretty_permalink( $url ) {
		global $wp_rewrite, $wp;

		$url_split = explode( '#', $url );
		$url       = $url_split[0];
		$url_split = explode( '?', $url );
		$url       = $url_split[0];

		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		$url    = set_url_scheme( $url, $scheme );

		$rewrite = $wp_rewrite->wp_rewrite_rules();
		if ( empty( $rewrite ) ) {
			return 0;
		}

		if ( ! $wp_rewrite->using_index_permalinks() ) {
			$url = str_replace( $wp_rewrite->index . '/', '', $url );
		}

		if ( 0 === strpos( trailingslashit( $url ), home_url( '/' ) ) ) {
			$url = str_replace( home_url(), '', $url );
		} else {
			$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$url       = preg_replace( sprintf( '#^%s#', preg_quote( $home_path, '#' ) ), '', trailingslashit( $url ) );
		}
		$url = trim( $url, '/' );

		$request       = $url;
		$request_match = $request;

		foreach ( (array) $rewrite as $match => $query ) {
			if ( '' !== $url && $url !== $request && 0 === strpos( $match, $url ) ) {
				$request_match = $url . '/' . $request;
			}

			if ( ! preg_match( "#^$match#", $request_match, $matches ) ) {
				continue;
			}

			$query = preg_replace( '!^.+\?!', '', $query );
			$query = addslashes( WP_MatchesMapRegex::apply( $query, $matches ) );
			parse_str( $query, $query_vars );

			$filtered = array();
			foreach ( (array) $query_vars as $key => $value ) {
				if ( in_array( (string) $key, $wp->public_query_vars, true ) ) {
					$filtered[ $key ] = $value;
				}
			}

			$term_query = new WP_Query( $filtered );
			if ( $term_query->is_tax || $term_query->is_category || $term_query->is_tag ) {
				$term = $term_query->get_queried_object();
				if ( $term instanceof WP_Term ) {
					// Match resolve_plain_query_vars()'s own filter
					// (get_taxonomies(['public' => true])) — a taxonomy
					// registered publicly_queryable (so it gets a rewrite
					// match) but NOT public is internal plumbing, not
					// content a site owner would want an SEO title on.
					// Real example: Polylang's own "language" taxonomy is
					// public=false/publicly_queryable=true so its language
					// switcher works, and its rewrite rule for the "lang"
					// query var (e.g. "/fr/") was otherwise indistinguishable
					// from a real taxonomy archive here — a French "your
					// latest posts" homepage URL was resolving to Polylang's
					// internal "French" language term instead of either the
					// homepage or ccc_unresolvable.
					$taxonomy = get_taxonomy( $term->taxonomy );
					if ( $taxonomy && $taxonomy->public ) {
						return (int) $term->term_id;
					}
				}
				return 0;
			}
			return 0;
		}

		return 0;
	}
}
