<?php
/**
 * SEO-plugin adapter: one object that knows which post-meta keys the active
 * SEO plugin uses for the title and meta description.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * One SEO-plugin adapter instance: which post-meta keys to read/write.
 */
class CCC_Adapter {

	/**
	 * 'yoast', 'rankmath', 'seopress' or 'aioseo'.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Post meta key the active SEO plugin stores its title override under.
	 * For 'aioseo' this is a legacy compatibility mirror, not the
	 * authoritative value — see get_title()/set_title().
	 *
	 * @var string
	 */
	public $title_key;

	/**
	 * Post meta key the active SEO plugin stores its meta description under.
	 * For 'aioseo' this is a legacy compatibility mirror, not the
	 * authoritative value — see get_description()/set_description().
	 *
	 * @var string
	 */
	public $description_key;

	/**
	 * Version of the detected SEO plugin ('' if unknown).
	 *
	 * @var string
	 */
	public $plugin_version;

	/**
	 * Build an adapter for one detected SEO plugin.
	 *
	 * @param string $id               'yoast', 'rankmath', 'seopress' or 'aioseo'.
	 * @param string $title_key        Post meta key for the title override.
	 * @param string $description_key  Post meta key for the meta description.
	 * @param string $plugin_version   Detected SEO plugin version, '' if unknown.
	 */
	public function __construct( $id, $title_key, $description_key, $plugin_version = '' ) {
		$this->id              = $id;
		$this->title_key       = $title_key;
		$this->description_key = $description_key;
		$this->plugin_version  = $plugin_version;
	}

	/**
	 * Detect the active SEO plugin. Yoast wins over Rank Math, which wins
	 * over SEOPress, which wins over AIOSEO, if more than one is somehow
	 * active — matching the order the crawler reports.
	 *
	 * @return CCC_Adapter|null Null when no supported SEO plugin is active.
	 */
	public static function detect() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return new self( 'yoast', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', WPSEO_VERSION );
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$ver = defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : '';
			return new self( 'rankmath', 'rank_math_title', 'rank_math_description', $ver );
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return new self( 'seopress', '_seopress_titles_title', '_seopress_titles_desc', SEOPRESS_VERSION );
		}
		if ( function_exists( 'aioseo' ) && defined( 'AIOSEO_VERSION' ) ) {
			return new self( 'aioseo', '_aioseo_title', '_aioseo_description', AIOSEO_VERSION );
		}
		return null;
	}

	/**
	 * Human-readable name of the detected SEO plugin, for admin display.
	 *
	 * @return string
	 */
	public function label() {
		$labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'seopress' => 'SEOPress',
			'aioseo'   => 'All in One SEO',
		);
		return isset( $labels[ $this->id ] ) ? $labels[ $this->id ] : $this->id;
	}

	/**
	 * Currently stored SEO title override ('' = plugin default template).
	 *
	 * @param int    $post_id Post id, or CCC_Service::HOME_ID for the homepage.
	 * @param string $lang    Non-default Polylang language slug, homepage only
	 *                        (see get_home_field()); '' for the ordinary target.
	 * @return string
	 */
	public function get_title( $post_id, $lang = '' ) {
		if ( 0 === $post_id ) {
			return $this->get_home_field( 'title', $lang );
		}
		if ( $post_id < 0 ) {
			return $this->get_term_field( 'title', -$post_id );
		}
		if ( 'aioseo' === $this->id ) {
			return (string) $this->aioseo_post( $post_id )->title;
		}
		return (string) get_post_meta( $post_id, $this->title_key, true );
	}

	/**
	 * Currently stored meta description ('' = none set).
	 *
	 * @param int    $post_id Post id, or CCC_Service::HOME_ID for the homepage.
	 * @param string $lang    Non-default Polylang language slug, homepage only
	 *                        (see get_home_field()); '' for the ordinary target.
	 * @return string
	 */
	public function get_description( $post_id, $lang = '' ) {
		if ( 0 === $post_id ) {
			return $this->get_home_field( 'description', $lang );
		}
		if ( $post_id < 0 ) {
			return $this->get_term_field( 'description', -$post_id );
		}
		if ( 'aioseo' === $this->id ) {
			return (string) $this->aioseo_post( $post_id )->description;
		}
		return (string) get_post_meta( $post_id, $this->description_key, true );
	}

	/**
	 * Write a new title override.
	 *
	 * @param int    $post_id Post id, or CCC_Service::HOME_ID for the homepage.
	 * @param string $value   New title override; '' removes it.
	 * @param string $lang    Non-default Polylang language slug, homepage only
	 *                        (see set_home_field()); '' for the ordinary target.
	 */
	public function set_title( $post_id, $value, $lang = '' ) {
		if ( 0 === $post_id ) {
			$this->set_home_field( 'title', $value, $lang );
			return;
		}
		if ( $post_id < 0 ) {
			$this->set_term_field( 'title', -$post_id, $value );
			return;
		}
		if ( 'aioseo' === $this->id ) {
			$this->aioseo_save( $post_id, 'title', $value );
			return;
		}
		$this->set_meta( $post_id, $this->title_key, $value );
	}

	/**
	 * Write a new meta description.
	 *
	 * @param int    $post_id Post id, or CCC_Service::HOME_ID for the homepage.
	 * @param string $value   New meta description; '' removes it.
	 * @param string $lang    Non-default Polylang language slug, homepage only
	 *                        (see set_home_field()); '' for the ordinary target.
	 */
	public function set_description( $post_id, $value, $lang = '' ) {
		if ( 0 === $post_id ) {
			$this->set_home_field( 'description', $value, $lang );
			return;
		}
		if ( $post_id < 0 ) {
			$this->set_term_field( 'description', -$post_id, $value );
			return;
		}
		if ( 'aioseo' === $this->id ) {
			$this->aioseo_save( $post_id, 'description', $value );
			return;
		}
		$this->set_meta( $post_id, $this->description_key, $value );
	}

	/**
	 * Post fields beyond title and description (0.11), on ordinary posts
	 * only; the homepage and taxonomy terms keep title and description.
	 * Values are strings: a URL for canonical and og_image, '' / 'index' /
	 * 'noindex' for robots_index ('' = the SEO plugin's default for the
	 * post type), plain text otherwise; '' clears the override.
	 */
	const EXTRA_FIELDS = array( 'canonical', 'robots_index', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description' );

	/**
	 * Post meta keys per adapter for the plain-text/URL extra fields,
	 * confirmed against each plugin's source on 22 Oct 2026:
	 * - Yoast 28.7: WPSEO_Meta::$meta_prefix '_yoast_wpseo_' + 'canonical'
	 *   and the social fields '{opengraph|twitter}-{title|description|image}'
	 *   (inc/class-wpseo-meta.php), which Indexable_Post_Builder maps to
	 *   canonical, open_graph_* and twitter_* (get_indexable_lookup()).
	 * - Rank Math 1.0.280: rank_math_canonical_url, rank_math_facebook_*,
	 *   rank_math_twitter_* (includes/opengraph, frontend/paper).
	 * - SEOPress 10.3: _seopress_robots_canonical, _seopress_social_fb_*,
	 *   _seopress_social_twitter_* (src/Services/Metas).
	 * AIOSEO keeps them in its own table, see aioseo_columns().
	 *
	 * @return string[] Field => meta key.
	 */
	private function extra_keys() {
		$map = array(
			'yoast'    => array(
				'canonical'           => '_yoast_wpseo_canonical',
				'og_title'            => '_yoast_wpseo_opengraph-title',
				'og_description'      => '_yoast_wpseo_opengraph-description',
				'og_image'            => '_yoast_wpseo_opengraph-image',
				'twitter_title'       => '_yoast_wpseo_twitter-title',
				'twitter_description' => '_yoast_wpseo_twitter-description',
			),
			'rankmath' => array(
				'canonical'           => 'rank_math_canonical_url',
				'og_title'            => 'rank_math_facebook_title',
				'og_description'      => 'rank_math_facebook_description',
				'og_image'            => 'rank_math_facebook_image',
				'twitter_title'       => 'rank_math_twitter_title',
				'twitter_description' => 'rank_math_twitter_description',
			),
			'seopress' => array(
				'canonical'           => '_seopress_robots_canonical',
				'og_title'            => '_seopress_social_fb_title',
				'og_description'      => '_seopress_social_fb_desc',
				'og_image'            => '_seopress_social_fb_img',
				'twitter_title'       => '_seopress_social_twitter_title',
				'twitter_description' => '_seopress_social_twitter_desc',
			),
		);
		return isset( $map[ $this->id ] ) ? $map[ $this->id ] : array();
	}

	/**
	 * The image-id companion each plugin stores next to a social image URL.
	 * Kept in step so the plugin never serves the old image by id.
	 *
	 * @return string Meta key, '' for none.
	 */
	private function og_image_id_key() {
		$keys = array(
			'yoast'    => '_yoast_wpseo_opengraph-image-id',
			'rankmath' => 'rank_math_facebook_image_id',
			'seopress' => '_seopress_social_fb_img_attachment_id',
		);
		return isset( $keys[ $this->id ] ) ? $keys[ $this->id ] : '';
	}

	/**
	 * AIOSEO's aioseo_posts columns for the extra fields, written through
	 * Models\Post::savePost()'s patch-friendly field map (AIOSEO 5.0.3,
	 * app/Common/Models/Post.php getSanitizeFieldMap() and the bespoke
	 * canonical_url branch).
	 *
	 * @return string[] Field => column.
	 */
	private static function aioseo_columns() {
		return array(
			'canonical'           => 'canonical_url',
			'og_title'            => 'og_title',
			'og_description'      => 'og_description',
			'og_image'            => 'og_image_custom_url',
			'twitter_title'       => 'twitter_title',
			'twitter_description' => 'twitter_description',
		);
	}

	/**
	 * Read any supported field. title/description go through their own
	 * getters (homepage, terms, languages); the extra fields are per post.
	 *
	 * @param int    $post_id Post id (> 0 for extra fields).
	 * @param string $field   Field name.
	 * @param string $lang    Language, title/description homepage only.
	 * @return string
	 */
	public function get_value( $post_id, $field, $lang = '' ) {
		if ( 'title' === $field ) {
			return $this->get_title( $post_id, $lang );
		}
		if ( 'description' === $field ) {
			return $this->get_description( $post_id, $lang );
		}
		if ( 'robots_index' === $field ) {
			return $this->get_robots_index( $post_id );
		}
		if ( 'aioseo' === $this->id ) {
			$columns = self::aioseo_columns();
			$post    = $this->aioseo_post( $post_id );
			if ( 'og_image' === $field && 'custom_image' !== $post->og_image_type ) {
				return '';
			}
			if ( 0 === strpos( $field, 'twitter_' ) && $post->twitter_use_og ) {
				return '';
			}
			return (string) $post->{ $columns[ $field ] };
		}
		$keys = $this->extra_keys();
		return (string) get_post_meta( $post_id, $keys[ $field ], true );
	}

	/**
	 * Write any supported field ('' clears it).
	 *
	 * @param int    $post_id Post id (> 0 for extra fields).
	 * @param string $field   Field name.
	 * @param string $value   New value.
	 * @param string $lang    Language, title/description homepage only.
	 */
	public function set_value( $post_id, $field, $value, $lang = '' ) {
		if ( 'title' === $field ) {
			$this->set_title( $post_id, $value, $lang );
			return;
		}
		if ( 'description' === $field ) {
			$this->set_description( $post_id, $value, $lang );
			return;
		}
		if ( 'robots_index' === $field ) {
			$this->set_robots_index( $post_id, $value );
			return;
		}
		if ( 'aioseo' === $this->id ) {
			$this->aioseo_set_extra( $post_id, $field, $value );
			return;
		}
		$keys = $this->extra_keys();
		$this->set_meta( $post_id, $keys[ $field ], $value );
		if ( 'og_image' === $field ) {
			$image_id = '' === $value ? 0 : (int) attachment_url_to_postid( $value );
			$this->set_meta( $post_id, $this->og_image_id_key(), $image_id ? (string) $image_id : '' );
		}
		if ( 'rankmath' === $this->id && 0 === strpos( $field, 'twitter_' ) ) {
			// Rank Math serves the Facebook values on Twitter while this is
			// on (its editor saves it on by default), which would hide the
			// pushed value. Back to unset once both Twitter fields are clear.
			$both_clear = '' === (string) get_post_meta( $post_id, 'rank_math_twitter_title', true ) && '' === (string) get_post_meta( $post_id, 'rank_math_twitter_description', true );
			$this->set_meta( $post_id, 'rank_math_twitter_use_facebook', $both_clear ? '' : 'off' );
		}
	}

	/**
	 * '' (post type default), 'index' or 'noindex', as the plugin stores it:
	 * Yoast meta-robots-noindex '0'/'2'/'1'; Rank Math a rank_math_robots
	 * token array; SEOPress _seopress_robots_index 'yes' for noindex (no
	 * explicit index exists, so index reads as ''); AIOSEO robots_default /
	 * robots_noindex.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private function get_robots_index( $post_id ) {
		switch ( $this->id ) {
			case 'yoast':
				$v = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
				return '1' === $v ? 'noindex' : ( '2' === $v ? 'index' : '' );
			case 'rankmath':
				$robots = get_post_meta( $post_id, 'rank_math_robots', true );
				if ( ! is_array( $robots ) || ! $robots ) {
					return '';
				}
				return in_array( 'noindex', $robots, true ) ? 'noindex' : ( in_array( 'index', $robots, true ) ? 'index' : '' );
			case 'seopress':
				return 'yes' === get_post_meta( $post_id, '_seopress_robots_index', true ) ? 'noindex' : '';
			case 'aioseo':
				$post = $this->aioseo_post( $post_id );
				if ( $post->robots_default ) {
					return '';
				}
				return $post->robots_noindex ? 'noindex' : 'index';
		}
		return '';
	}

	/**
	 * Write robots_index ('' / 'index' / 'noindex'), leaving every other
	 * robots directive (nofollow, noarchive...) as it was.
	 *
	 * @param int    $post_id Post id.
	 * @param string $value   '' / 'index' / 'noindex'.
	 */
	private function set_robots_index( $post_id, $value ) {
		switch ( $this->id ) {
			case 'yoast':
				$this->set_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', 'noindex' === $value ? '1' : ( 'index' === $value ? '2' : '' ) );
				return;
			case 'rankmath':
				$robots = get_post_meta( $post_id, 'rank_math_robots', true );
				$robots = is_array( $robots ) ? array_values( array_diff( $robots, array( 'index', 'noindex' ) ) ) : array();
				if ( '' !== $value ) {
					array_unshift( $robots, $value );
				}
				if ( $robots ) {
					update_post_meta( $post_id, 'rank_math_robots', $robots );
				} else {
					delete_post_meta( $post_id, 'rank_math_robots' );
				}
				return;
			case 'seopress':
				$this->set_meta( $post_id, '_seopress_robots_index', 'noindex' === $value ? 'yes' : '' );
				return;
			case 'aioseo':
				\AIOSEO\Plugin\Common\Models\Post::savePost(
					$post_id,
					'' === $value ? array( 'default' => true ) : array(
						'default' => false,
						'noindex' => 'noindex' === $value,
					)
				);
				return;
		}
	}

	/**
	 * Patch one AIOSEO extra field. A social image also needs the image
	 * source switched to the custom URL (back to the default when cleared),
	 * and a Twitter value needs "use the Open Graph data" off, or AIOSEO
	 * would serve the Open Graph value instead.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Extra field.
	 * @param string $value   New value.
	 */
	private function aioseo_set_extra( $post_id, $field, $value ) {
		$columns = self::aioseo_columns();
		$data    = array( $columns[ $field ] => $value );
		if ( 'og_image' === $field ) {
			$data['og_image_type'] = '' === $value ? 'default' : 'custom_image';
		}
		if ( 0 === strpos( $field, 'twitter_' ) ) {
			$post                   = $this->aioseo_post( $post_id );
			$other                  = 'twitter_title' === $field ? (string) $post->twitter_description : (string) $post->twitter_title;
			$data['twitter_use_og'] = '' === $value && ( '' === $other || $post->twitter_use_og );
		}
		\AIOSEO\Plugin\Common\Models\Post::savePost( $post_id, $data );
	}

	/**
	 * Whether this adapter can read/write the homepage title/description at
	 * all. True for Yoast, Rank Math and SEOPress — verified against real
	 * plugin source (23 Sept 2026): all three store a "your latest posts"
	 * homepage's title/description in a dedicated options-array field (not
	 * postmeta), read via a safe read-modify-write path. AIOSEO is the one
	 * genuine "no" here, not just unresearched: its `getHomePageTitle()`/
	 * `getHomePageDescription()` (app/Common/Meta/Title.php,
	 * app/Common/Meta/Description.php) fall back, for a "your latest posts"
	 * site, to `searchAppearance.global.siteTitle`/`.metaDescription` — the
	 * SAME site-wide template used to fill the `#site_title`/`#tagline`
	 * variables in every OTHER page's title/description template. Writing
	 * to it to "fix the homepage" would silently change title generation
	 * across the whole site, not just "/" — a real corruption risk, so
	 * AIOSEO homepage writes are refused, not attempted.
	 *
	 * @return bool
	 */
	public function supports_home() {
		return in_array( $this->id, array( 'yoast', 'rankmath', 'seopress' ), true );
	}

	/**
	 * Whether this adapter can read/write a taxonomy term's (category,
	 * tag, custom taxonomy) archive title/description. True for Yoast,
	 * Rank Math and SEOPress — verified against real plugin source:
	 * - Yoast (24 Sept 2026) stores per-term SEO data in ONE option,
	 *   `wpseo_taxonomy_meta`, shaped `[taxonomy][term_id][wpseo_*]` —
	 *   read via `WPSEO_Taxonomy_Meta::get_term_meta()`, written via its
	 *   `set_values()` (a safe read-modify-write, same risk class as the
	 *   homepage's `wpseo_titles` option).
	 * - Rank Math (24 Sept 2026) uses real term meta (`rank_math_title`/
	 *   `rank_math_description` via core's own `get_term_meta()`/
	 *   `update_term_meta()`), the exact same key names it uses for
	 *   posts, just against a term id instead of a post id — confirmed
	 *   at `includes/frontend/paper/class-taxonomy.php` (reads via
	 *   `Term::get_meta()`, which resolves to `get_term_meta( $id,
	 *   'rank_math_title', true )`) and `includes/traits/class-meta.php`
	 *   (writes via `update_term_meta()`/`delete_term_meta()`).
	 * - SEOPress (24 Sept 2026) also uses real term meta, and — unlike
	 *   Rank Math — under the EXACT SAME key names as its post-level
	 *   postmeta (`_seopress_titles_title`/`_seopress_titles_desc`,
	 *   `$this->title_key`/`$this->description_key`): confirmed at
	 *   `src/Services/Metas/Title/Specifications/TaxonomySpecification.php`
	 *   (reads via `get_term_meta( $term->term_id, '_seopress_titles_title',
	 *   true )`) and `inc/admin/metaboxes/admin-term-metaboxes.php` (writes
	 *   via plain `update_term_meta()`/`delete_term_meta()`, same as posts).
	 * AIOSEO is the one genuine "no" here, not unresearched: its free/Lite
	 * tier has no term SEO storage at all — its own source says so
	 * explicitly (`app/Common/Main/BulkActions.php`: "Pro only. The term
	 * analysis columns live on the Pro aioseo_terms table"; the REST term
	 * controller's meta-data field registration is a deliberate no-op in
	 * Lite, "Term SEO meta requires the Pro Term model"). Nothing to write
	 * to without the paid plugin, so `ccc_term_unsupported` is correct on
	 * the merits.
	 *
	 * @return bool
	 */
	public function supports_term() {
		return in_array( $this->id, array( 'yoast', 'rankmath', 'seopress' ), true );
	}

	/**
	 * Whether writing '' to the homepage title actually falls back to a
	 * sensible default, or leaves a genuinely blank <title>. Verified
	 * against real plugin source (23 Sept 2026):
	 * - Yoast: `Indexable_Home_Page_Presentation::generate_title()` falls
	 *   back to `Options_Helper::get_title_default()` whenever the stored
	 *   value is empty — '' is safe.
	 * - Rank Math: `Blog::title()` calls
	 *   `Paper::get_from_options( 'homepage_title' )` with no fallback
	 *   argument, so an empty stored value renders as a literally empty
	 *   <title> tag — no separate "default template" is re-applied at read
	 *   time (the template text you see in Rank Math's settings UI is only
	 *   ever a seeded initial value, not a live fallback). Rank Math's
	 *   homepage DESCRIPTION is unaffected: `Blog::description()` passes
	 *   `get_bloginfo( 'description' )` as its fallback, so clearing it is
	 *   safe.
	 * - SEOPress: its title/description generator uses a specification
	 *   chain (`LatestPostsSpecification::isSatisfyBy()`) that explicitly
	 *   returns false — "I don't apply" — whenever the stored home title/
	 *   description is empty, so an empty value correctly falls through to
	 *   the next specification in the chain rather than rendering blank.
	 *   Safe, same class as Yoast.
	 *
	 * @return bool
	 */
	public function can_clear_home_title() {
		return 'rankmath' !== $this->id;
	}

	/**
	 * Whether this adapter can read/write the "your latest posts" homepage
	 * title/description for a Polylang language OTHER than the site's
	 * default one. True for Yoast only — verified against real Polylang
	 * source (26 Sept 2026): `src/integrations/` ships a dedicated
	 * compatibility module ONLY for Yoast (`integrations/wpseo/wpseo.php`,
	 * `PLL_WPSEO::wpseo_translate_options()` registers `title-home-wpseo`/
	 * `metadesc-home-wpseo` with Polylang's own string-translation system);
	 * there is no `integrations/rankmath`, `integrations/seopress` or
	 * `integrations/aioseo` directory at all. Rank Math/SEOPress/AIOSEO's
	 * homepage title/description is one shared value site-wide, independent
	 * of Polylang's active language — nothing for CCC to target per-language
	 * even if it wanted to; a write "for French" would just be the same
	 * write "for every language", which is not what the caller asked for, so
	 * `ccc_language_unsupported` is correct on the merits, not a gap.
	 *
	 * @return bool
	 */
	public function supports_language_home() {
		return 'yoast' === $this->id;
	}

	/**
	 * Read one homepage field ('title' or 'description') from the active
	 * SEO plugin's own storage. '' for an adapter without homepage support
	 * (caller must gate writes on supports_home() first).
	 *
	 * @param string $field 'title' or 'description'.
	 * @param string $lang  Non-default Polylang language slug — reads that
	 *                      language's own translated value (Yoast only;
	 *                      caller must gate on supports_language_home()
	 *                      first). '' reads the plain/default-language value.
	 * @return string
	 */
	private function get_home_field( $field, $lang = '' ) {
		if ( 'yoast' === $this->id && '' !== $lang ) {
			// The registered "original" string IS the plain (default-
			// language) stored value — Polylang re-registers it fresh from
			// get_option() on every request (PLL_WPSEO::
			// wpseo_translate_options(), hooked `wp_loaded`), so it is
			// always this site's actual current default-language value, not
			// a stale snapshot. pll_translate_string() looks up that exact
			// string as a msgid in $lang's own string-translation table and,
			// same as real gettext, returns the msgid itself (i.e. the
			// default-language value) when nothing has been translated yet
			// for $lang — verified against a real Yoast+Polylang install to
			// be exactly what that language's homepage actually renders,
			// not a guess (see SECURITY-NOTES.md's 26 Sept addendum).
			$key      = ( 'title' === $field ) ? 'title-home-wpseo' : 'metadesc-home-wpseo';
			$original = (string) \WPSEO_Options::get( $key, '' );
			return function_exists( 'pll_translate_string' ) ? (string) pll_translate_string( $original, $lang ) : $original;
		}
		if ( 'yoast' === $this->id ) {
			$key = ( 'title' === $field ) ? 'title-home-wpseo' : 'metadesc-home-wpseo';
			return (string) \WPSEO_Options::get( $key, '' );
		}
		if ( 'rankmath' === $this->id ) {
			$titles = (array) get_option( 'rank-math-options-titles', array() );
			$key    = ( 'title' === $field ) ? 'homepage_title' : 'homepage_description';
			return isset( $titles[ $key ] ) ? (string) $titles[ $key ] : '';
		}
		if ( 'seopress' === $this->id ) {
			$titles = (array) get_option( 'seopress_titles_option_name', array() );
			$key    = ( 'title' === $field ) ? 'seopress_titles_home_site_title' : 'seopress_titles_home_site_desc';
			return isset( $titles[ $key ] ) ? (string) $titles[ $key ] : '';
		}
		return '';
	}

	/**
	 * Write one homepage field through the active SEO plugin's own safe
	 * read-modify-write path — never a bare `update_option()` on the whole
	 * settings array, which would silently wipe every other setting it
	 * holds (title templates, social settings, etc.) alongside it.
	 *
	 * @param string $field 'title' or 'description'.
	 * @param string $value New value.
	 * @param string $lang  Non-default Polylang language slug — writes a
	 *                      STRING TRANSLATION of the default-language value
	 *                      for that language (Yoast only; caller must gate
	 *                      on supports_language_home() first), never the
	 *                      default-language value itself. '' writes the
	 *                      plain/default-language value as before.
	 */
	private function set_home_field( $field, $value, $lang = '' ) {
		if ( 'yoast' === $this->id && '' !== $lang ) {
			// Same real mechanism Polylang's own "Strings translation" admin
			// screen uses to save an edited translation
			// (src/settings/table-string.php: save_translations(), and
			// PLL_Translate_Option::update_option(), which is how Yoast's
			// OWN default-language save preserves every other language's
			// translation) — not a public `@api` function, but the plugin's
			// one real internal implementation of "set this string's
			// translation for language X", used consistently in both of
			// Polylang's own places that do this. Verified end-to-end
			// against a real Yoast+Polylang install, across separate HTTP
			// requests (write then a fresh request reads it back): import
			// the language's current translations, add/replace the entry
			// for the CURRENT default-language original string, export back.
			if ( ! function_exists( 'PLL' ) || ! class_exists( 'PLL_MO' ) ) {
				return; // Unreachable: caller already gated on supports_language_home() + a validated active language.
			}
			$language = PLL()->model->get_language( $lang );
			if ( ! $language ) {
				return; // Unreachable for the same reason.
			}
			$key      = ( 'title' === $field ) ? 'title-home-wpseo' : 'metadesc-home-wpseo';
			$original = (string) \WPSEO_Options::get( $key, '' );
			$mo       = new \PLL_MO();
			$mo->import_from_db( $language );
			$mo->add_entry( $mo->make_entry( $original, $value ) );
			$mo->export_to_db( $language );
			return;
		}
		if ( 'yoast' === $this->id ) {
			$key = ( 'title' === $field ) ? 'title-home-wpseo' : 'metadesc-home-wpseo';
			// WPSEO_Options::save_option() reads the full 'wpseo_titles'
			// option, patches this one key, writes the whole array back —
			// the safe pattern this option needs (confirmed at
			// inc/options/class-wpseo-options.php:516 in Yoast 28.6 source).
			\WPSEO_Options::save_option( 'wpseo_titles', $key, $value );
			return;
		}
		if ( 'rankmath' === $this->id ) {
			$key            = ( 'title' === $field ) ? 'homepage_title' : 'homepage_description';
			$titles         = (array) get_option( 'rank-math-options-titles', array() );
			$titles[ $key ] = $value;
			update_option( 'rank-math-options-titles', $titles );
			// Rank Math caches its parsed settings for the rest of the
			// request in a runtime singleton; reset it so anything reading
			// settings later in the same request (e.g. a subsequent /resolve
			// call in the same batch) sees the new value, matching what
			// Rank Math's own Abilities API does after a settings write.
			if ( function_exists( 'rank_math' ) ) {
				$rank_math = rank_math();
				if ( isset( $rank_math->settings ) && is_object( $rank_math->settings ) && method_exists( $rank_math->settings, 'reset' ) ) {
					$rank_math->settings->reset();
				}
			}
			return;
		}
		if ( 'seopress' === $this->id ) {
			$key            = ( 'title' === $field ) ? 'seopress_titles_home_site_title' : 'seopress_titles_home_site_desc';
			$titles         = (array) get_option( 'seopress_titles_option_name', array() );
			$titles[ $key ] = $value;
			// Same pattern SEOPress's own setup-wizard save handler uses
			// (inc/admin/wizard/admin-wizard.php): read the whole option,
			// patch this one key, write the whole array back — never a bare
			// overwrite, which would wipe every other title/meta setting
			// sharing this option (archive templates, separators, etc.).
			update_option( 'seopress_titles_option_name', $titles );
		}
	}

	/**
	 * An empty string means "remove the override, fall back to the SEO
	 * plugin's template" — Yoast, Rank Math and SEOPress all treat absent
	 * meta that way.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Post meta key to write.
	 * @param string $value   New value; '' deletes the meta key instead.
	 */
	private function set_meta( $post_id, $key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Currently stored term title/description ('' = plugin default
	 * template). Empty string for an adapter without term support
	 * (caller must gate writes on supports_term() first) or a term id
	 * whose taxonomy could not be determined.
	 *
	 * @param string $field   'title' or 'description'.
	 * @param int    $term_id Term id (positive — the caller strips the
	 *                        CCC_Service negative-sentinel sign first).
	 * @return string
	 */
	private function get_term_field( $field, $term_id ) {
		if ( 'yoast' === $this->id ) {
			$taxonomy = $this->term_taxonomy( $term_id );
			if ( '' === $taxonomy ) {
				return '';
			}
			$meta  = ( 'title' === $field ) ? 'title' : 'desc';
			$value = \WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy, $meta );
			return false === $value ? '' : (string) $value;
		}
		if ( 'rankmath' === $this->id || 'seopress' === $this->id ) {
			// Both plugins reuse their per-post title and description
			// meta key names for terms too, confirmed against real
			// SEOPress source on 24 Sept 2026: its own term edit screen
			// writes those exact same keys as plain term meta, the same
			// way its post metabox does. Ordinary meta rows, independent
			// per field, no shared-array read-modify-write risk here,
			// unlike Yoast's taxonomy meta option just above.
			$key = ( 'title' === $field ) ? $this->title_key : $this->description_key;
			return (string) get_term_meta( $term_id, $key, true );
		}
		return '';
	}

	/**
	 * Write a term's title/description through the active SEO plugin's own
	 * storage.
	 *
	 * @param string $field   'title' or 'description'.
	 * @param int    $term_id Term id (positive).
	 * @param string $value   New value; '' removes the override.
	 */
	private function set_term_field( $field, $term_id, $value ) {
		if ( 'yoast' === $this->id ) {
			$taxonomy = $this->term_taxonomy( $term_id );
			if ( '' === $taxonomy ) {
				return;
			}
			// WPSEO_Taxonomy_Meta::set_value()/set_values() are NOT a true
			// single-field patch, despite what the name and this method's
			// own previous implementation assumed: validate_term_meta_data()
			// only retains an old value for a specific allowlist (noindex,
			// bctitle, canonical, keywordsynonyms, focuskeywords) — every
			// OTHER field, including wpseo_title/wpseo_desc themselves,
			// resets to its class default the instant it is absent from the
			// $meta_values array passed in. Calling set_value() for just the
			// ONE field being changed was found, against a real WordPress
			// integration run, to silently wipe the OTHER of title/desc
			// back to '' on the very next write — and would do the same to
			// any other Yoast term setting (focus keyword, OG/Twitter
			// overrides, cornerstone flag, ...) a site owner had already
			// set by hand. Read the term's FULL current meta first
			// (get_term_meta() with no $meta arg always returns every key,
			// defaults merged in — confirmed at
			// inc/options/class-wpseo-taxonomy-meta.php:558 in Yoast 28.6
			// source) and pass it all back through set_values() so only
			// this one key actually changes.
			$meta_key             = 'wpseo_' . ( ( 'title' === $field ) ? 'title' : 'desc' );
			$current              = \WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy );
			$current              = is_array( $current ) ? $current : array();
			$current[ $meta_key ] = $value;
			\WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, $current );
			self::rebuild_yoast_term_indexable( $term_id );
			return;
		}
		if ( 'rankmath' === $this->id || 'seopress' === $this->id ) {
			$key = ( 'title' === $field ) ? $this->title_key : $this->description_key;
			if ( '' === $value ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $value );
			}
		}
	}

	/**
	 * Make Yoast serve a term value written straight to wpseo_taxonomy_meta.
	 *
	 * Yoast's front end reads the term's indexable, which its term watcher
	 * rebuilds only on created_term/edited_term, so without this a pushed
	 * category title stayed invisible until someone edited the term (found
	 * 21 Oct 2026). Firing edited_term ourselves would also run every other
	 * plugin's term-form save handler with an empty $_POST, so this calls
	 * Yoast's own watcher instead, and does nothing if Yoast ever moves it.
	 *
	 * @param int $term_id Term id.
	 */
	private static function rebuild_yoast_term_indexable( $term_id ) {
		$watcher_class = 'Yoast\\WP\\SEO\\Integrations\\Watchers\\Indexable_Term_Watcher';
		if ( ! function_exists( 'YoastSEO' ) || ! class_exists( $watcher_class ) ) {
			return;
		}
		try {
			$watcher = YoastSEO()->classes->get( $watcher_class );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( is_object( $watcher ) && method_exists( $watcher, 'build_indexable' ) ) {
			$watcher->build_indexable( $term_id );
		}
	}

	/**
	 * A term's taxonomy, needed by Yoast's per-term storage (keyed
	 * `[taxonomy][term_id]`, unlike Rank Math's plain term meta which needs
	 * no taxonomy to read/write). '' if the term id does not resolve to a
	 * real, unambiguous term — `get_term()` itself returns a WP_Error for a
	 * term id shared between multiple taxonomies (a pre-WP-4.3 leftover;
	 * shared terms have been disallowed by default since), and callers
	 * already treat '' the same as "unsupported here" for either reason.
	 *
	 * @param int $term_id Term id.
	 * @return string
	 */
	private function term_taxonomy( $term_id ) {
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		return $term->taxonomy;
	}

	/**
	 * AIOSEO stores title/description in a custom `wp_aioseo_posts` table
	 * (columns, not postmeta), via its own Model class rather than core WP
	 * functions — confirmed against AIOSEO 4.9 source, `Models\Post` is a
	 * public, patch-style API (`@since 4.0.3`, not `@internal` like the
	 * REST-controller wrapper around it): `getPost()`/`savePost()` only
	 * touch the columns you pass, filling in every other column's default
	 * when the row doesn't exist yet, and leave everything else (noindex
	 * flags, schema, keywords — 38+ other columns) untouched either way.
	 *
	 * @param int $post_id Post id.
	 * @return \AIOSEO\Plugin\Common\Models\Post
	 */
	private function aioseo_post( $post_id ) {
		return \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
	}

	/**
	 * Patch one AIOSEO field ('title' or 'description'). An empty string is
	 * passed straight through, not specially handled: AIOSEO's own title/
	 * description generator uses PHP's empty() on the stored value, which
	 * is true for both '' and null, so an empty string already falls back
	 * to the plugin's default template exactly like the postmeta adapters'
	 * delete-on-empty behaviour (verified against `Meta\Title::getTitle()`
	 * in AIOSEO 4.9 source).
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   'title' or 'description'.
	 * @param string $value   New value.
	 */
	private function aioseo_save( $post_id, $field, $value ) {
		\AIOSEO\Plugin\Common\Models\Post::savePost( $post_id, array( $field => $value ) );
	}
}
