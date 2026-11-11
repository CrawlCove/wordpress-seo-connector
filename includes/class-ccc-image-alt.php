<?php
/**
 * POST /image-alt: set an image's alt text where WordPress keeps it.
 *
 * Two places, because both matter: the media library attachment's alt
 * (`_wp_attachment_image_alt`, which new insertions, galleries and page
 * builders such as Elementor read) and the `alt` attribute of the matching
 * <img> tags already in that page's content (which is what the page serves
 * for every image inserted before the change). An empty alt marks the image
 * decorative and is written as alt="", never dropped.
 *
 * The content patch goes through CCC_Url_Replacer's splices, so a revert
 * restores the page's exact bytes or refuses once the content was edited.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Image alt text fixes, with revert.
 */
class CCC_Image_Alt {

	const MAX_ALT_LEN = 250;
	const META_KEY    = '_wp_attachment_image_alt';

	/**
	 * Validate one item.
	 *
	 * @param mixed $item { url, src, alt }.
	 * @return array|WP_Error { post_id (0 if the page has no content of its own), src, forms, alt }
	 */
	public static function validate( $item ) {
		if ( ! is_array( $item ) ) {
			return self::error( 'ccc_bad_change', __( 'Each change must be an object.', 'crawl-cove-connector' ) );
		}
		$src = isset( $item['src'] ) && is_string( $item['src'] ) ? trim( $item['src'] ) : '';
		$sch = strtolower( (string) wp_parse_url( $src, PHP_URL_SCHEME ) );
		if ( '' === $src || strlen( $src ) > CCC_Url_Replacer::MAX_URL_LEN || ( 'http' !== $sch && 'https' !== $sch ) ) {
			return self::error( 'ccc_bad_url', __( 'src must be the absolute http or https URL of the image.', 'crawl-cove-connector' ) );
		}
		if ( ! isset( $item['alt'] ) || ! is_string( $item['alt'] ) ) {
			return self::error( 'ccc_bad_value', __( 'alt must be a string (empty marks the image decorative).', 'crawl-cove-connector' ) );
		}
		$alt = sanitize_text_field( $item['alt'] );
		if ( mb_strlen( $alt ) > self::MAX_ALT_LEN ) {
			/* translators: %d: maximum alt text length */
			return self::error( 'ccc_too_long', sprintf( __( 'alt is longer than %d characters.', 'crawl-cove-connector' ), self::MAX_ALT_LEN ) );
		}
		if ( ! isset( $item['url'] ) ) {
			return self::error( 'ccc_no_target', __( 'A change needs the url of the page that shows the image.', 'crawl-cove-connector' ) );
		}
		$post_id = CCC_Service::resolve_url( $item['url'] );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		return array(
			'post_id' => $post_id > 0 ? (int) $post_id : 0,
			'src'     => $src,
			'forms'   => CCC_Url_Replacer::forms( $src ),
			'alt'     => $alt,
		);
	}

	/**
	 * The media library attachment an image URL belongs to, including a
	 * resized copy ("photo-300x200.jpg") or core's "-scaled" big-image copy.
	 *
	 * @param string $src Image URL.
	 * @return int Attachment id, or 0.
	 */
	public static function attachment_for( $src ) {
		$url = strtok( $src, '?#' );
		$id  = attachment_url_to_postid( $url );
		if ( ! $id ) {
			$base = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url );
			$id   = attachment_url_to_postid( $base );
			if ( ! $id ) {
				$id = attachment_url_to_postid( preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $base ) );
			}
		}
		return ( $id && 'attachment' === get_post_type( $id ) ) ? (int) $id : 0;
	}

	/**
	 * Set alt on every <img> whose src is the image, in one stored string.
	 *
	 * @param string $text  post_content.
	 * @param array  $forms The image URL's matching forms.
	 * @param string $alt   New alt (plain text).
	 * @return array CCC_Url_Replacer::splice() result, plus `found`: images
	 *               with this src, and `held`: those left alone because they
	 *               are not real tags.
	 */
	public static function rewrite( $text, array $forms, $alt ) {
		$edits  = array();
		$held   = 0;
		$found  = 0;
		$inside = self::shortcode_openers( (string) $text );
		// A quoted attribute value may hold '>' (alt="Home > Shop"). Atomic
		// groups keep a stray quote from backtracking without end.
		if ( preg_match_all( '/<img\b(?>"[^"]*"|\'[^\']*\'|[^<>"\']+|["\'])*+>/i', (string) $text, $tags, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $tags[0] as $tag ) {
				// Attributes read in order, so an "alt=" inside another
				// attribute's value is never taken for the real one.
				$attrs = CCC_Url_Replacer::attributes( $tag[0] );
				if ( ! $attrs ) {
					continue;
				}
				$src = null;
				$old = null;
				foreach ( $attrs as $at ) {
					if ( 'src' === $at['name'] && null === $src ) {
						$src = str_replace( array( '&amp;', '&#038;' ), '&', trim( $at['value'] ) );
					}
					if ( 'alt' === $at['name'] && null === $old ) {
						$old = $at;
					}
				}
				if ( null === $src || ! in_array( $src, $forms, true ) ) {
					continue;
				}
				++$found;
				if ( self::in_spans( $tag[1], $inside ) ) {
					++$held;
					continue;
				}
				$attr = ' alt="' . esc_attr( $alt ) . '"';
				if ( $old ) {
					if ( -1 !== $old['vstart'] && esc_attr( $alt ) === $old['value'] ) {
						continue;
					}
					$new = substr_replace( $tag[0], $attr, $old['astart'], $old['alen'] );
				} else {
					$new = '<img' . $attr . substr( $tag[0], 4 );
				}
				$edits[] = array( $tag[1], strlen( $tag[0] ), $new, 1 );
			}
		}
		$result = CCC_Url_Replacer::splice( (string) $text, $edits, 0 );
		if ( $result['count'] && ! self::only_real_images_changed( (string) $text, $result['text'], $forms, $alt, $result['count'] ) ) {
			$held  += $result['count'];
			$result = CCC_Url_Replacer::splice( (string) $text, array(), 0 );
		}
		$result['held']  = $held;
		$result['found'] = $found;
		return $result;
	}

	/**
	 * Byte spans of every registered shortcode's opening tag, nested ones
	 * included. "<img ...>" text there is an attribute value (a [caption]'s
	 * caption="..."), and core's tokenizer cannot tell: it reads it as a real
	 * tag, so the guard below would pass an alt whose quotes close the
	 * shortcode's attribute (review #354).
	 *
	 * @param string $text Content.
	 * @param int    $base Offset of $text in the full content.
	 * @return array List of [ start, end ) pairs.
	 */
	private static function shortcode_openers( $text, $base = 0 ) {
		if ( empty( $GLOBALS['shortcode_tags'] ) || false === strpos( $text, '[' ) ) {
			return array();
		}
		$spans = array();
		if ( preg_match_all( '/' . get_shortcode_regex() . '/', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $all as $m ) {
				$spans[] = array( $base + $m[0][1], $base + $m[3][1] + strlen( $m[3][0] ) );
				if ( isset( $m[5] ) && -1 !== $m[5][1] && '' !== $m[5][0] ) {
					$spans = array_merge( $spans, self::shortcode_openers( $m[5][0], $base + $m[5][1] ) );
				}
			}
		}
		return $spans;
	}

	/**
	 * Whether an offset falls inside any span.
	 *
	 * @param int   $offset Byte offset.
	 * @param array $spans  [ start, end ) pairs.
	 * @return bool
	 */
	private static function in_spans( $offset, array $spans ) {
		foreach ( $spans as $span ) {
			if ( $offset >= $span[0] && $offset < $span[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a rewrite only set the alt on real <img> tags. The regex above
	 * also matches "<img ...>" text that a browser never parses as a tag:
	 * inside another tag's attribute value, a <script> or a comment. There
	 * the inserted alt="..." quotes close the surrounding attribute or JS
	 * string, so the alt text would become markup or script (an Editor
	 * without unfiltered_html could plant it in a page an admin wrote). Core's
	 * HTML tokenizer reads both versions; the rewrite stands only if every
	 * tag kept its name and attributes (a matching image may gain alt) and
	 * exactly $count real matching images now carry the new alt.
	 *
	 * @param string $before Content before the rewrite.
	 * @param string $after  Content after it.
	 * @param array  $forms  The image URL's matching forms.
	 * @param string $alt    New alt (plain text).
	 * @param int    $count  Images the rewrite changed.
	 * @return bool
	 */
	private static function only_real_images_changed( $before, $after, array $forms, $alt, $count ) {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return true; // Core since 6.2, this plugin's minimum; absent only under the unit-test stubs.
		}
		$old = self::real_tags( $before, $forms, $alt );
		$new = self::real_tags( $after, $forms, $alt );
		if ( count( $old['tags'] ) !== count( $new['tags'] ) || $new['with_alt'] - $old['with_alt'] !== $count ) {
			return false;
		}
		foreach ( $old['tags'] as $i => $tag ) {
			$names = $tag['names'];
			if ( $tag['match'] && ! in_array( 'alt', $names, true ) ) {
				$names[] = 'alt';
				sort( $names );
			}
			if ( $new['tags'][ $i ]['name'] !== $tag['name'] || ( $new['tags'][ $i ]['names'] !== $tag['names'] && $new['tags'][ $i ]['names'] !== $names ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Every real opening tag in $html, as core's tokenizer reads it.
	 *
	 * @param string $html  Content.
	 * @param array  $forms The image URL's matching forms.
	 * @param string $alt   Alt text to count.
	 * @return array { tags: [ { name, names (sorted attribute names), match } ], with_alt: matching images whose alt is $alt }
	 */
	private static function real_tags( $html, array $forms, $alt ) {
		$p    = new WP_HTML_Tag_Processor( $html );
		$tags = array();
		$hits = 0;
		while ( $p->next_tag() ) {
			$names = (array) $p->get_attribute_names_with_prefix( '' );
			sort( $names );
			$src   = 'IMG' === $p->get_tag() ? $p->get_attribute( 'src' ) : null;
			$match = is_string( $src ) && in_array( trim( $src ), $forms, true );
			if ( $match && $alt === $p->get_attribute( 'alt' ) ) {
				++$hits;
			}
			$tags[] = array(
				'name'  => $p->get_tag(),
				'names' => $names,
				'match' => $match,
			);
		}
		return array(
			'tags'     => $tags,
			'with_alt' => $hits,
		);
	}

	/**
	 * Run a batch.
	 *
	 * @param mixed  $changes Raw items.
	 * @param bool   $dry_run Preview only.
	 * @param string $source  Actor for the change log.
	 * @return array|WP_Error Per-item results.
	 */
	public static function run( $changes, $dry_run, $source ) {
		if ( ! is_array( $changes ) || ! $changes ) {
			return self::error( 'ccc_empty_batch', __( 'changes must be a non-empty array.', 'crawl-cove-connector' ) );
		}
		if ( count( $changes ) > CCC_Service::MAX_BATCH ) {
			/* translators: %d: maximum number of changes allowed per request */
			return self::error( 'ccc_batch_too_big', sprintf( __( 'At most %d changes per request.', 'crawl-cove-connector' ), CCC_Service::MAX_BATCH ) );
		}
		$results = array();
		foreach ( array_values( $changes ) as $i => $item ) {
			$results[] = array_merge( array( 'index' => $i ), self::one( $item, $dry_run, $source ) );
		}
		return $results;
	}

	/**
	 * One item.
	 *
	 * @param mixed  $item    Raw item.
	 * @param bool   $dry_run Preview only.
	 * @param string $source  Actor for the change log.
	 * @return array Result without its index.
	 */
	private static function one( $item, $dry_run, $source ) {
		$op = self::validate( $item );
		if ( is_wp_error( $op ) ) {
			return self::refusal( $op );
		}

		$attachment_id = self::attachment_for( $op['src'] );
		$attachment    = null;
		if ( $attachment_id ) {
			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				$attachment = self::refusal( self::error( 'ccc_forbidden', __( 'This user may not edit that media library image.', 'crawl-cove-connector' ), 403 ) );
			} else {
				$from       = (string) get_post_meta( $attachment_id, self::META_KEY, true );
				$attachment = array(
					'id'      => $attachment_id,
					'from'    => $from,
					'to'      => $op['alt'],
					'changed' => $from !== $op['alt'],
				);
			}
		}

		$inline = null;
		if ( $op['post_id'] ) {
			if ( ! current_user_can( 'edit_post', $op['post_id'] ) ) {
				return self::refusal( self::error( 'ccc_forbidden', __( 'This user may not edit that target.', 'crawl-cove-connector' ), 403 ) );
			}
			$content = CCC_Url_Replacer::read( $op['post_id'], 'content' );
			$inline  = self::rewrite( (string) $content, $op['forms'], $op['alt'] );
		}

		$found_inline = $inline && $inline['found'] > 0;
		if ( ! $attachment_id && ! $found_inline ) {
			return self::refusal( self::error( 'ccc_image_not_found', __( 'That image is neither in the media library nor in this page\'s content, so there is nothing to set.', 'crawl-cove-connector' ), 404 ) );
		}
		if ( ! $attachment_id && $inline && 0 === $inline['count'] && $inline['held'] > 0 ) {
			return self::refusal( self::error( 'ccc_image_not_editable', __( 'That image appears in this page only inside a shortcode, an attribute, a script or a comment, where the plugin cannot safely set its alt. Edit it in WordPress.', 'crawl-cove-connector' ), 409 ) );
		}

		$result           = array(
			'ok'         => true,
			'dry_run'    => (bool) $dry_run,
			'post_id'    => $op['post_id'],
			'attachment' => $attachment,
			'inline'     => $inline ? $inline['count'] : 0,
		);
		$write_attachment = $attachment && isset( $attachment['changed'] ) && $attachment['changed'];
		$write_inline     = $inline && $inline['count'] > 0;
		if ( $dry_run || ( ! $write_attachment && ! $write_inline ) ) {
			return $result;
		}

		if ( $write_attachment ) {
			update_post_meta( $attachment_id, self::META_KEY, wp_slash( $op['alt'] ) );
			self::attachment_changed( $attachment_id );
		}
		$target = null;
		if ( $op['post_id'] ) {
			// The page the finding was on shows the image even when only the
			// attachment changed (a page builder reads the attachment's alt).
			CCC_Service::invalidate_caches_for( $op['post_id'] );
		}
		if ( $write_inline ) {
			CCC_Url_Replacer::write( $op['post_id'], 'content', $inline['text'] );
			$target = array(
				'post_id' => $op['post_id'],
				'where'   => 'content',
				'count'   => $inline['count'],
				'hash'    => md5( $inline['text'] ),
				'splices' => $inline['splices'],
			);
		}
		$entry               = CCC_Change_Log::record_entry(
			array(
				'kind'       => 'image_alt',
				'post_id'    => $op['post_id'],
				'field'      => 'alt',
				'src'        => $op['src'],
				'from'       => $write_attachment ? $attachment['from'] : '',
				'to'         => $op['alt'],
				'attachment' => $write_attachment ? $attachment_id : 0,
				'inline'     => $target,
			),
			$source
		);
		$result['change_id'] = $entry['id'];
		return $result;
	}

	/**
	 * Whether the current user may see and revert an entry.
	 *
	 * @param array $entry Change log entry (kind image_alt).
	 * @return bool
	 */
	public static function can_edit_entry( array $entry ) {
		if ( ! empty( $entry['attachment'] ) && ! current_user_can( 'edit_post', (int) $entry['attachment'] ) ) {
			return false;
		}
		if ( ! empty( $entry['inline'] ) && ! current_user_can( 'edit_post', (int) $entry['inline']['post_id'] ) ) {
			return false;
		}
		return ! empty( $entry['attachment'] ) || ! empty( $entry['inline'] );
	}

	/**
	 * Revert: the attachment alt goes back to `from` and the page content to
	 * its exact previous bytes, or nothing is written. If either was edited
	 * since, 409 ccc_value_changed; with force the attachment is put back and
	 * an edited page answers ccc_content_changed (the old alt cannot be put
	 * back safely into text that moved).
	 *
	 * @param array $entry Change log entry.
	 * @param bool  $force Revert even if the attachment alt changed since.
	 * @return true|WP_Error
	 */
	public static function revert( array $entry, $force ) {
		$attachment = (int) $entry['attachment'];
		$inline     = $entry['inline'];
		$content    = null;
		if ( $inline ) {
			$value = CCC_Url_Replacer::read( (int) $inline['post_id'], 'content' );
			if ( null === $value || md5( $value ) !== $inline['hash'] ) {
				if ( $force ) {
					return self::error( 'ccc_content_changed', __( 'The page has been edited since this change, so the old alt text cannot be put back into it safely. Nothing was written.', 'crawl-cove-connector' ), 409 );
				}
				return new WP_Error(
					'ccc_value_changed',
					__( 'The value has changed since this change was made, so reverting it would overwrite the newer value. Nothing was written.', 'crawl-cove-connector' ),
					array(
						'status'   => 409,
						'post_ids' => array( (int) $inline['post_id'] ),
					)
				);
			}
			$content = CCC_Url_Replacer::unsplice( $value, $inline['splices'] );
		}
		if ( $attachment ) {
			$now = (string) get_post_meta( $attachment, self::META_KEY, true );
			if ( ! $force && $now !== $entry['to'] ) {
				return new WP_Error(
					'ccc_value_changed',
					__( 'The value has changed since this change was made, so reverting it would overwrite the newer value. Nothing was written.', 'crawl-cove-connector' ),
					array(
						'status'   => 409,
						'current'  => $now,
						'expected' => $entry['to'],
					)
				);
			}
			update_post_meta( $attachment, self::META_KEY, wp_slash( $entry['from'] ) );
			self::attachment_changed( $attachment );
		}
		if ( null !== $content ) {
			CCC_Url_Replacer::write( (int) $inline['post_id'], 'content', $content );
		}
		if ( ! empty( $entry['post_id'] ) ) {
			CCC_Service::invalidate_caches_for( (int) $entry['post_id'] );
		}
		return true;
	}

	/**
	 * Why a revert of this entry would be refused, for the Tools page notice:
	 * 'page' when the page holding the inline alt was edited since (a forced
	 * revert is refused too), 'alt' when only the attachment alt changed (a
	 * forced revert overwrites it), '' when nothing changed.
	 *
	 * @param array $entry Change log entry (kind image_alt).
	 * @return string
	 */
	public static function conflict( array $entry ) {
		$inline = isset( $entry['inline'] ) ? $entry['inline'] : null;
		if ( $inline ) {
			$value = CCC_Url_Replacer::read( (int) $inline['post_id'], 'content' );
			if ( null === $value || md5( $value ) !== $inline['hash'] ) {
				return 'page';
			}
		}
		if ( ! empty( $entry['attachment'] ) && (string) get_post_meta( (int) $entry['attachment'], self::META_KEY, true ) !== $entry['to'] ) {
			return 'alt';
		}
		return '';
	}

	/**
	 * After an attachment's alt changes: purge its own page, and drop
	 * Elementor's rendered-widget cache, which otherwise keeps serving the old
	 * alt from every image widget for up to a day. Only the cached HTML goes
	 * (Elementor rebuilds it on the next view), not its CSS files.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private static function attachment_changed( $attachment_id ) {
		CCC_Service::invalidate_caches_for( $attachment_id );
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			delete_post_meta_by_key( '_elementor_element_cache' );
		}
	}

	/**
	 * A per-item refusal.
	 *
	 * @param WP_Error $error Why.
	 * @return array
	 */
	private static function refusal( WP_Error $error ) {
		return array(
			'ok'      => false,
			'error'   => $error->get_error_code(),
			'message' => $error->get_error_message(),
		);
	}

	/**
	 * A WP_Error with a status.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
