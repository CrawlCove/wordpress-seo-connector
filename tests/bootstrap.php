<?php
/**
 * Test bootstrap: minimal WordPress stubs backed by globals, then the plugin
 * classes under test. The REST controller and admin page are exercised
 * against a real WordPress in the pre-release integration smoke, not here.
 */

define( 'ABSPATH', '/tmp/wp/' );

function cc_reset_wp() {
	$GLOBALS['cc_meta']    = array(); // post_id => key => value
	$GLOBALS['cc_options'] = array();
	$GLOBALS['cc_posts']   = array(); // post_id => ['title' => ..., 'url' => ...]
	$GLOBALS['cc_urls']    = array(); // url => post_id
	$GLOBALS['cc_deny']    = array(); // post_ids current user may NOT edit
	$GLOBALS['cc_deny_read'] = array(); // post_ids current user may NOT read (read_post)
	$GLOBALS['cc_saved']   = array(); // wp_update_post calls
	$GLOBALS['cc_actions'] = array(); // do_action() calls (tag name only)
	$GLOBALS['cc_home']    = 'https://example.com';
	$GLOBALS['cc_aioseo']  = array(); // post_id => ['title' => ..., 'description' => ...]
	$GLOBALS['cc_deny_manage_options'] = false;
	$GLOBALS['cc_logged_in']     = true;  // is_user_logged_in() stub
	$GLOBALS['cc_app_passwords'] = true;  // wp_is_application_passwords_available() stub
	$GLOBALS['cc_app_passwords_supported'] = true; // wp_is_application_passwords_supported() stub (HTTPS or local)
	$GLOBALS['cc_terms']            = array(); // term_id => ['taxonomy' => ..., 'name' => ..., 'link' => ...]
	$GLOBALS['cc_term_urls']        = array(); // url => term_id (CCC_Term_Resolver::resolve_pretty_permalink() stub)
	$GLOBALS['cc_term_plain_urls']  = array(); // url => term_id (CCC_Term_Resolver::resolve_plain_query_vars() stub)
	$GLOBALS['cc_term_meta']        = array(); // term_id => key => value
	$GLOBALS['cc_deny_terms']       = array(); // term_ids current user may NOT edit
	$GLOBALS['cc_taxonomy_public']  = array(); // taxonomy => bool (get_taxonomy() stub; default true)
	$GLOBALS['cc_pll_languages']    = array(); // active Polylang language slugs; empty = Polylang not active
	$GLOBALS['cc_pll_default_lang'] = ''; // pll_default_language() stub
	$GLOBALS['cc_pll_strings']      = array(); // lang slug => original string => translation (PLL_MO stub's persisted store)
}

/**
 * Test helper: activate the Polylang stubs with the given active language
 * slugs and default language — mirrors calling PLL()->model->add_language()
 * + update_default_lang() on a real install. Leaving this uncalled (the
 * cc_reset_wp() default) means pll_languages_list() is empty and every
 * function_exists( 'pll_languages_list' ) gate in the plugin behaves exactly
 * as if Polylang were not installed.
 */
function cc_enable_polylang( $languages, $default_lang ) {
	$GLOBALS['cc_pll_languages']    = $languages;
	$GLOBALS['cc_pll_default_lang'] = $default_lang;
}

/**
 * Test helper: activate the TranslatePress stub with the given default
 * language and non-default-language => URL-slug map — mirrors saving
 * `trp_settings` via `TRP_Settings::sanitize_settings()` on a real install.
 * Leaving this uncalled (the cc_reset_wp() default, no `trp_settings`
 * option) means CCC_Service::strip_translatepress_language_prefix()
 * returns every URL unchanged, same as a real site with TranslatePress not
 * installed or not yet configured.
 */
function cc_enable_translatepress( $default_lang, $url_slugs ) {
	$GLOBALS['cc_options']['trp_settings'] = array(
		'default-language' => $default_lang,
		'url-slugs'         => $url_slugs,
	);
}
cc_reset_wp();

// Real TranslatePress always defines this class when active; CCC only ever
// calls class_exists() on it, never instantiates it, so an empty stub is
// enough. Defined unconditionally (unlike the pll_* function stubs, which
// gate Polylang-active behaviour via their own globals) because the
// actually-meaningful gate is the `trp_settings` option above, not the
// class's mere existence — matching a real site, where TranslatePress being
// installed but never configured behaves the same as not installed at all.
class TRP_Translate_Press {}

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function __( $text, $domain = null ) { return $text; }

function get_post_meta( $post_id, $key, $single = false ) {
	return isset( $GLOBALS['cc_meta'][ $post_id ][ $key ] ) ? $GLOBALS['cc_meta'][ $post_id ][ $key ] : '';
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['cc_meta'][ $post_id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['cc_meta'][ $post_id ][ $key ] );
	return true;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['cc_options'] ) ? $GLOBALS['cc_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['cc_options'][ $name ] = $value;
	return true;
}

function get_post( $post_id ) {
	return isset( $GLOBALS['cc_posts'][ $post_id ] ) ? (object) array( 'ID' => $post_id ) : null;
}
function get_post_type( $post_id ) {
	return isset( $GLOBALS['cc_posts'][ $post_id ]['type'] ) ? $GLOBALS['cc_posts'][ $post_id ]['type'] : 'post';
}
/** Stub: only core's internal types used in tests are non-viewable. */
function is_post_type_viewable( $post_type ) {
	return ! in_array( $post_type, array( 'user_request', 'wp_block', 'revision' ), true );
}
function get_the_title( $post_id ) {
	return isset( $GLOBALS['cc_posts'][ $post_id ]['title'] ) ? $GLOBALS['cc_posts'][ $post_id ]['title'] : '';
}
function get_permalink( $post_id ) {
	return isset( $GLOBALS['cc_posts'][ $post_id ]['url'] ) ? $GLOBALS['cc_posts'][ $post_id ]['url'] : '';
}
function wp_update_post( $args ) {
	$GLOBALS['cc_saved'][] = $args['ID'];
	return $args['ID'];
}

function home_url( $path = '' ) { return rtrim( $GLOBALS['cc_home'], '/' ) . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function untrailingslashit( $str ) { return rtrim( (string) $str, '/' ); }
function url_to_postid( $url ) {
	return isset( $GLOBALS['cc_urls'][ $url ] ) ? $GLOBALS['cc_urls'][ $url ] : 0;
}

function current_user_can( $cap, $post_id = null ) {
	if ( 'edit_post' === $cap ) {
		return ! in_array( (int) $post_id, $GLOBALS['cc_deny'], true );
	}
	if ( 'read_post' === $cap ) {
		return ! in_array( (int) $post_id, $GLOBALS['cc_deny_read'], true );
	}
	if ( 'edit_term' === $cap ) {
		return ! in_array( (int) $post_id, $GLOBALS['cc_deny_terms'], true );
	}
	if ( 'manage_options' === $cap ) {
		return empty( $GLOBALS['cc_deny_manage_options'] );
	}
	return true;
}

function is_user_logged_in() { return ! empty( $GLOBALS['cc_logged_in'] ); }
function wp_is_application_passwords_supported() { return ! empty( $GLOBALS['cc_app_passwords_supported'] ); }
function wp_is_application_passwords_available() { return ! empty( $GLOBALS['cc_app_passwords'] ); }

// Mirrors core _sanitize_text_fields(), octet removal included, so a call
// site that reaches for sanitize_text_field() on SEO text sees the same
// %category% -> tegory% damage here that it does on a real site.
function sanitize_text_field( $str ) {
	$filtered = wp_check_invalid_utf8( (string) $str );
	if ( false !== strpos( $filtered, '<' ) ) {
		$filtered = wp_strip_all_tags( wp_pre_kses_less_than( $filtered ), false );
		$filtered = str_replace( "<\n", "&lt;\n", $filtered );
	}
	$filtered = trim( preg_replace( '/[\r\n\t ]+/', ' ', $filtered ) );
	$found    = false;
	while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
		$filtered = str_replace( $match[0], '', $filtered );
		$found    = true;
	}
	return $found ? trim( preg_replace( '/ +/', ' ', $filtered ) ) : $filtered;
}

function wp_check_invalid_utf8( $str ) {
	return ( '' === $str || preg_match( '/^./su', $str ) ) ? $str : '';
}

// Core: a "<" that cannot open a tag becomes &lt; so strip_tags keeps it.
function wp_pre_kses_less_than( $text ) {
	return preg_replace_callback(
		'%<[^>]*?((?=<)|>|$)%',
		function ( $m ) {
			return false === strpos( $m[0], '>' ) ? htmlspecialchars( $m[0] ) : $m[0];
		},
		$text
	);
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	if ( $remove_breaks ) {
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}
	return trim( $text );
}

function apply_filters( $tag, $value ) { return $value; }
function do_action( $tag ) { $GLOBALS['cc_actions'][] = $tag; }

/** Test helper: register a post with a resolvable URL. */
function cc_add_post( $post_id, $url, $title = 'A post', $type = 'post' ) {
	$GLOBALS['cc_posts'][ $post_id ] = array( 'title' => $title, 'url' => $url, 'type' => $type );
	$GLOBALS['cc_urls'][ $url ]      = $post_id;
}

/** Test helper: register a taxonomy term with a resolvable archive URL. */
function cc_add_term( $term_id, $taxonomy, $name, $url = null, $link = null ) {
	$GLOBALS['cc_terms'][ $term_id ] = array(
		'taxonomy' => $taxonomy,
		'name'     => $name,
		'link'     => null !== $link ? $link : $name,
	);
	if ( null !== $url ) {
		$GLOBALS['cc_term_urls'][ $url ] = $term_id;
	}
}

function get_term( $term, $taxonomy = '' ) {
	$term_id = is_object( $term ) ? $term->term_id : (int) $term;
	if ( ! isset( $GLOBALS['cc_terms'][ $term_id ] ) ) {
		return null;
	}
	$row = $GLOBALS['cc_terms'][ $term_id ];
	if ( $taxonomy && $taxonomy !== $row['taxonomy'] ) {
		return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
	}
	return (object) array(
		'term_id'  => $term_id,
		'name'     => $row['name'],
		'taxonomy' => $row['taxonomy'],
	);
}

function get_term_link( $term ) {
	$term_id = is_object( $term ) ? $term->term_id : (int) $term;
	return isset( $GLOBALS['cc_terms'][ $term_id ] ) ? $GLOBALS['cc_terms'][ $term_id ]['link'] : '';
}

/**
 * Test helper: mark a taxonomy as non-public, e.g. Polylang's own internal
 * "language" taxonomy — public by default, matching every real content
 * taxonomy (category, post_tag, custom) a unit test registers via
 * cc_add_term().
 */
function cc_set_taxonomy_public( $taxonomy, $public ) {
	$GLOBALS['cc_taxonomy_public'][ $taxonomy ] = $public;
}

function get_taxonomy( $taxonomy ) {
	$public = isset( $GLOBALS['cc_taxonomy_public'][ $taxonomy ] ) ? $GLOBALS['cc_taxonomy_public'][ $taxonomy ] : true;
	return (object) array(
		'name'   => $taxonomy,
		'public' => $public,
	);
}

function get_term_meta( $term_id, $key, $single = false ) {
	return isset( $GLOBALS['cc_term_meta'][ $term_id ][ $key ] ) ? $GLOBALS['cc_term_meta'][ $term_id ][ $key ] : '';
}
function update_term_meta( $term_id, $key, $value ) {
	$GLOBALS['cc_term_meta'][ $term_id ][ $key ] = $value;
	return true;
}
function delete_term_meta( $term_id, $key ) {
	unset( $GLOBALS['cc_term_meta'][ $term_id ][ $key ] );
	return true;
}

/**
 * CCC_Term_Resolver's real implementation drives $wp_rewrite/WP_Query rewrite
 * matching (and get_taxonomies()/get_term_by() query-var matching) that
 * cannot be meaningfully stubbed — real behaviour, including the
 * plain-query-vars-must-run-before-url_to_postid() ordering fix, is proven
 * against a live WordPress install in tests/integration/taxonomy-checks.sh,
 * same split as WPSEO_Options/WPSEO_Taxonomy_Meta below. This stand-in lets
 * CCC_Service::resolve_url()'s own logic (calling both in the right order,
 * building the negative sentinel, re-verifying via get_term()) be
 * unit-tested without the real URL-matching mechanism.
 */
class CCC_Term_Resolver {
	public static function resolve_plain_query_vars( $url ) {
		return isset( $GLOBALS['cc_term_plain_urls'][ $url ] ) ? $GLOBALS['cc_term_plain_urls'][ $url ] : 0;
	}
	public static function resolve_pretty_permalink( $url ) {
		return isset( $GLOBALS['cc_term_urls'][ $url ] ) ? $GLOBALS['cc_term_urls'][ $url ] : 0;
	}
}

/**
 * Minimal stand-in for Yoast's \WPSEO_Taxonomy_Meta, shaped like the real
 * get_term_meta()/set_value() (read-modify-write onto the
 * 'wpseo_taxonomy_meta' option, shaped [taxonomy][term_id][wpseo_*], via the
 * same get_option()/update_option() stubs above) so CCC_Adapter's term-title
 * code path is unit-testable without the real plugin installed.
 */
class WPSEO_Taxonomy_Meta {
	private static $defaults_per_term = array(
		'wpseo_title' => '',
		'wpseo_desc'  => '',
	);

	// Real signature is ( $term, $taxonomy, $meta = null ) — omitting $meta
	// returns the full per-term array (every key, defaults merged in), the
	// same "no true single-field patch" shape CCC_Adapter's Yoast term-write
	// path depends on (see includes/class-ccc-adapter.php's set_term_field()
	// for why that distinction matters).
	public static function get_term_meta( $term_id, $taxonomy, $meta = null ) {
		$all   = get_option( 'wpseo_taxonomy_meta', array() );
		$stored = isset( $all[ $taxonomy ][ $term_id ] ) ? $all[ $taxonomy ][ $term_id ] : array();
		if ( null === $meta ) {
			return array_merge( self::$defaults_per_term, $stored );
		}
		$key = 'wpseo_' . $meta;
		return isset( $stored[ $key ] ) ? $stored[ $key ] : false;
	}

	public static function set_value( $term_id, $taxonomy, $meta, $value ) {
		self::set_values( $term_id, $taxonomy, array( 'wpseo_' . $meta => $value ) );
	}

	public static function set_values( $term_id, $taxonomy, array $meta_values ) {
		$all = get_option( 'wpseo_taxonomy_meta', array() );
		if ( ! isset( $all[ $taxonomy ][ $term_id ] ) ) {
			$all[ $taxonomy ][ $term_id ] = array();
		}
		foreach ( $meta_values as $key => $value ) {
			$all[ $taxonomy ][ $term_id ][ $key ] = $value;
		}
		update_option( 'wpseo_taxonomy_meta', $all );
	}
}

/**
 * Minimal stand-in for AIOSEO's own \AIOSEO\Plugin\Common\Models\Post,
 * shaped like the real getPost()/savePost() (patch-style: savePost() only
 * overwrites keys present in $data) so CCC_Adapter's aioseo branch is
 * unit-testable without the real plugin installed. Real-plugin behaviour
 * (defaults on first save, the 38 other columns, empty-string-falls-back-
 * to-template rendering) is proven in tests/integration/, not here.
 */
class CC_Test_Aioseo_Post {
	public $post_id;
	public $title       = '';
	public $description = '';

	public static function getPost( $post_id ) {
		$post              = new self();
		$post->post_id     = $post_id;
		$row               = isset( $GLOBALS['cc_aioseo'][ $post_id ] ) ? $GLOBALS['cc_aioseo'][ $post_id ] : array();
		$post->title       = isset( $row['title'] ) ? $row['title'] : '';
		$post->description = isset( $row['description'] ) ? $row['description'] : '';
		return $post;
	}

	public static function savePost( $post_id, $data ) {
		if ( ! isset( $GLOBALS['cc_aioseo'][ $post_id ] ) ) {
			$GLOBALS['cc_aioseo'][ $post_id ] = array(
				'title'       => '',
				'description' => '',
			);
		}
		foreach ( $data as $key => $value ) {
			$GLOBALS['cc_aioseo'][ $post_id ][ $key ] = $value;
		}
	}
}
class_alias( 'CC_Test_Aioseo_Post', 'AIOSEO\\Plugin\\Common\\Models\\Post' );

/**
 * Minimal stand-in for Yoast's \WPSEO_Options, shaped like the real
 * get()/save_option() (read-modify-write onto the named option array, via
 * the same get_option()/update_option() stubs above) so CCC_Adapter's
 * homepage-title code path is unit-testable without the real plugin
 * installed. Real-plugin behaviour (indexable rebuild watcher, the
 * empty-falls-back-to-template render) is proven in tests/integration/.
 */
class WPSEO_Options {
	public static function get( $key, $default = null ) {
		$opts = get_option( 'wpseo_titles', array() );
		return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
	}

	public static function save_option( $group, $key, $value ) {
		$opts         = get_option( $group, array() );
		$opts[ $key ] = $value;
		update_option( $group, $opts );
		return true;
	}
}

/**
 * Minimal stand-in for Polylang's multilingual API, backed by the
 * cc_pll_* globals `cc_enable_polylang()` sets. Real behaviour — PLL_MO's
 * actual on-disk/DB format, PLL()'s real context classes
 * (PLL_Admin/PLL_Frontend/PLL_REST_Request), pll_home_url()'s three URL
 * modes — is proven against a real Polylang(+Yoast) install in
 * tests/integration/, same split as WPSEO_Options/WPSEO_Taxonomy_Meta
 * above. This stand-in only needs to be faithful to the ONE behaviour
 * CCC_Adapter/CCC_Service actually depend on: an untranslated string falls
 * back to itself (real gettext semantics, confirmed against a real
 * Yoast+Polylang install — see SECURITY-NOTES.md's 26 Sept addendum),
 * never to ''.
 */
function pll_languages_list() {
	return $GLOBALS['cc_pll_languages'];
}
function pll_default_language() {
	return $GLOBALS['cc_pll_default_lang'];
}
function pll_home_url( $lang = '' ) {
	return home_url( '/' . $lang . '/' );
}
function pll_translate_string( $string, $lang ) {
	$entries = isset( $GLOBALS['cc_pll_strings'][ $lang ] ) ? $GLOBALS['cc_pll_strings'][ $lang ] : array();
	return array_key_exists( $string, $entries ) ? $entries[ $string ] : $string;
}

class CC_Test_PLL_Language {
	public $slug;
	public function __construct( $slug ) {
		$this->slug = $slug;
	}
}
class CC_Test_PLL_Model {
	public function get_language( $slug ) {
		return in_array( $slug, $GLOBALS['cc_pll_languages'], true ) ? new CC_Test_PLL_Language( $slug ) : false;
	}
}
class CC_Test_PLL {
	public $model;
	public function __construct() {
		$this->model = new CC_Test_PLL_Model();
	}
}
function PLL() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new CC_Test_PLL();
	}
	return $instance;
}

/**
 * Minimal stand-in for Polylang's PLL_MO — the same import/add_entry/
 * export_to_db round trip CCC_Adapter::set_home_field() drives, verified
 * against the real class end-to-end (write via one REST request, read back
 * via pll_translate_string() in a separate one) in SECURITY-NOTES.md's 26
 * Sept addendum.
 */
class PLL_MO {
	private $lang    = '';
	private $entries = array();

	public function import_from_db( $language ) {
		$this->lang    = is_object( $language ) ? $language->slug : $language;
		$this->entries = isset( $GLOBALS['cc_pll_strings'][ $this->lang ] ) ? $GLOBALS['cc_pll_strings'][ $this->lang ] : array();
	}
	public function make_entry( $original, $translation ) {
		return array( $original, $translation );
	}
	public function add_entry( $entry ) {
		list( $original, $translation )       = $entry;
		$this->entries[ $original ] = $translation;
	}
	public function export_to_db( $language ) {
		$lang                             = is_object( $language ) ? $language->slug : $language;
		$GLOBALS['cc_pll_strings'][ $lang ] = $this->entries;
	}
}

require __DIR__ . '/../includes/class-ccc-app-passwords.php';
require __DIR__ . '/../includes/class-ccc-adapter.php';
require __DIR__ . '/../includes/class-ccc-change-log.php';
require __DIR__ . '/../includes/class-ccc-connection.php';
require __DIR__ . '/../includes/class-ccc-service.php';
