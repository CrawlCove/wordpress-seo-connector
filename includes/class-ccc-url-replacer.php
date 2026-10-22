<?php
/**
 * POST /replace-urls: repoint links from one URL to another (a redirect's
 * final URL), unlink them, or strip rel="nofollow" from them, in post content,
 * Elementor data and nav menu custom links.
 *
 * Only exact URL matches in URL contexts are touched: href/src/srcset/poster
 * attribute values, CSS url(), whole JSON string values (Gutenberg block
 * comment attributes, Elementor data) and a menu item's whole URL. Never a
 * bare substring replace, so "/page" never rewrites "/page-two".
 *
 * Every rewrite is a list of splices on the stored string. The change log
 * keeps the splices (offset, inserted length, original text) and a hash of
 * the value they produced, not a copy of the content, so a revert puts back
 * exactly the original bytes while the value is unchanged, and refuses
 * (ccc_value_changed) once anything else has edited it.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL search-and-replace in content, Elementor data and menus, with revert.
 */
class CCC_Url_Replacer {

	const MAX_URL_LEN = 2048;

	/**
	 * Most posts plus menu items one site-scope change may rewrite. Each one
	 * is a row in the change entry, and the whole entry is one Undo.
	 */
	const MAX_SITE_TARGETS = 200;

	/**
	 * Post statuses a site-scope change looks at. Trash, auto-drafts and
	 * revisions are left alone.
	 */
	const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	const ELEMENTOR_KEY = '_elementor_data';
	const MENU_KEY      = '_menu_item_url';

	/**
	 * Validate one /replace-urls item.
	 *
	 * Shape: { from, to | unlink: true, scope: 'page'|'site', url (page scope),
	 * rel_strip?: 'nofollow' }.
	 *
	 * @param mixed $item Raw item from the request body.
	 * @return array|WP_Error { from, to, unlink, rel_strip, scope, post_id, forms }
	 */
	public static function validate( $item ) {
		if ( ! is_array( $item ) ) {
			return self::error( 'ccc_bad_change', __( 'Each change must be an object.', 'crawl-cove-connector' ) );
		}

		$from = isset( $item['from'] ) && is_string( $item['from'] ) ? trim( $item['from'] ) : '';
		if ( ! self::is_http_url( $from ) ) {
			return self::error( 'ccc_bad_url', __( 'from must be an absolute http or https URL.', 'crawl-cove-connector' ) );
		}

		$unlink = ! empty( $item['unlink'] );
		$to     = '';
		if ( ! $unlink ) {
			$to = isset( $item['to'] ) && is_string( $item['to'] ) ? trim( $item['to'] ) : '';
			// The new URL is written into stored HTML and JSON as-is, so it must
			// be a plain http(s) URL with nothing that could close an attribute,
			// a tag, a JSON string or a block comment.
			if ( ! self::is_http_url( $to ) || esc_url_raw( $to ) !== $to || preg_match( '/["\'<>\\\\\s`]/', $to ) ) {
				return self::error( 'ccc_bad_url', __( 'to must be an absolute http or https URL (or send unlink: true).', 'crawl-cove-connector' ) );
			}
		}

		$rel_strip = '';
		if ( isset( $item['rel_strip'] ) && null !== $item['rel_strip'] && '' !== $item['rel_strip'] ) {
			if ( 'nofollow' !== $item['rel_strip'] ) {
				return self::error( 'ccc_bad_value', __( 'rel_strip can only be "nofollow".', 'crawl-cove-connector' ) );
			}
			$rel_strip = 'nofollow';
		}
		if ( ! $unlink && $from === $to && '' === $rel_strip ) {
			return self::error( 'ccc_nothing_to_do', __( 'from and to are the same URL, so there is nothing to change.', 'crawl-cove-connector' ) );
		}

		$scope = isset( $item['scope'] ) ? $item['scope'] : 'page';
		if ( 'page' !== $scope && 'site' !== $scope ) {
			return self::error( 'ccc_bad_value', __( 'scope must be "page" or "site".', 'crawl-cove-connector' ) );
		}

		$post_id = 0;
		if ( 'page' === $scope ) {
			if ( isset( $item['post_id'] ) && is_numeric( $item['post_id'] ) ) {
				$post_id = (int) $item['post_id'];
				if ( $post_id <= 0 || ! get_post( $post_id ) || ! is_post_type_viewable( get_post_type( $post_id ) ) ) {
					return self::error( 'ccc_no_post', __( 'No post with that id.', 'crawl-cove-connector' ), 404 );
				}
			} elseif ( isset( $item['url'] ) ) {
				$post_id = CCC_Service::resolve_url( $item['url'] );
				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}
				if ( $post_id <= 0 ) {
					return self::error( 'ccc_no_content_target', __( 'That URL is a homepage or archive listing, which has no content of its own to edit. Use scope "site" to change the link everywhere.', 'crawl-cove-connector' ) );
				}
			} else {
				return self::error( 'ccc_no_target', __( 'A page-scope change needs the url (or post_id) of the page that holds the link.', 'crawl-cove-connector' ) );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return self::error( 'ccc_forbidden', __( 'This user may not edit that target.', 'crawl-cove-connector' ), 403 );
			}
		}

		return array(
			'from'      => $from,
			'to'        => $to,
			'unlink'    => $unlink,
			'rel_strip' => $unlink ? '' : $rel_strip,
			'scope'     => $scope,
			'post_id'   => $post_id,
			'forms'     => self::forms( $from ),
		);
	}

	/**
	 * Whether $url is an absolute http(s) URL with a host, within the length cap.
	 *
	 * @param string $url Candidate.
	 * @return bool
	 */
	private static function is_http_url( $url ) {
		if ( '' === $url || strlen( $url ) > self::MAX_URL_LEN ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return ( 'http' === $scheme || 'https' === $scheme ) && '' !== (string) wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * The exact strings that count as "a link to $from": the URL itself and,
	 * when it is on this site, its root-relative form ("/old-page/"), which
	 * hand-written HTML and menus often use.
	 *
	 * @param string $from Absolute URL.
	 * @return string[]
	 */
	public static function forms( $from ) {
		$forms     = array( $from );
		$host      = strtolower( (string) wp_parse_url( $from, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host === $site_host ) {
			$path     = (string) wp_parse_url( $from, PHP_URL_PATH );
			$query    = (string) wp_parse_url( $from, PHP_URL_QUERY );
			$fragment = (string) wp_parse_url( $from, PHP_URL_FRAGMENT );
			$relative = ( '' === $path ? '/' : $path ) . ( '' !== $query ? '?' . $query : '' ) . ( '' !== $fragment ? '#' . $fragment : '' );
			// A port in from would make the relative form match links on another port.
			if ( null === wp_parse_url( $from, PHP_URL_PORT ) || wp_parse_url( $from, PHP_URL_PORT ) === wp_parse_url( home_url(), PHP_URL_PORT ) ) {
				$forms[] = $relative;
			}
		}
		return $forms;
	}

	/**
	 * Rewrite one stored string.
	 *
	 * @param string $text  Stored value.
	 * @param string $where 'content' or 'navigation' (post_content of a post or a block theme's wp_navigation menu), 'elementor' (_elementor_data JSON) or 'menu' (a classic menu item URL).
	 * @param array  $op    Validated item: forms, to, unlink, rel_strip.
	 * @return array { text, count, skipped, splices: list of [ offset in new text, inserted length, original text ] }
	 */
	public static function rewrite( $text, $where, array $op ) {
		$text  = (string) $text;
		$edits = array(); // [ offset, length, replacement ] on the original text.
		$skip  = 0;

		if ( 'menu' === $where ) {
			if ( ! $op['unlink'] && in_array( $text, $op['forms'], true ) && ! self::keeps( $text, $op ) ) {
				$edits[] = array( 0, strlen( $text ), $op['to'], 1 );
			}
			return self::splice( $text, $edits, $skip );
		}

		$json = ( 'elementor' === $where );
		self::rewrite_tags( $text, $json, $op, $edits, $skip );
		if ( ! $op['unlink'] ) {
			self::rewrite_css_urls( $text, $op, $edits, $skip );
			if ( $json ) {
				self::rewrite_json_strings( $text, 0, strlen( $text ), false, $op, $edits );
			} elseif ( preg_match_all( '/<!--\s+\/?wp:[^\s]+\s+(\{.*?\})\s+\/?-->/s', $text, $m, PREG_OFFSET_CAPTURE ) ) {
				// Gutenberg block attributes live in the comment delimiters.
				foreach ( $m[1] as $span ) {
					self::rewrite_json_strings( $text, $span[1], strlen( $span[0] ), true, $op, $edits );
				}
			}
		}
		return self::splice( $text, $edits, $skip );
	}

	/**
	 * Rewrite href/src/srcset/poster values in HTML start tags; unlink or
	 * strip nofollow on matching anchors. In Elementor JSON the HTML sits
	 * inside JSON strings, so quotes arrive as \" and slashes may be \/.
	 *
	 * @param string $text  Stored value.
	 * @param bool   $json  Whether the HTML is inside JSON strings.
	 * @param array  $op    Validated item.
	 * @param array  $edits Edits collected so far (by reference): [ offset, length, replacement, matches it counts as ].
	 * @param int    $skip  Matches deliberately left alone (by reference).
	 */
	private static function rewrite_tags( $text, $json, array $op, array &$edits, &$skip ) {
		if ( ! preg_match_all( '/<([a-zA-Z][a-zA-Z0-9-]*)(\s[^<>]*)?>/', $text, $tags, PREG_OFFSET_CAPTURE ) ) {
			return;
		}
		$attr_re = '/(\s)(href|src|srcset|poster)(\s*=\s*)(\\\\"|"|\')(.*?)\4/is';
		foreach ( $tags[0] as $n => $tag ) {
			$old    = $tag[0];
			$offset = $tag[1];
			$name   = strtolower( $tags[1][ $n ][0] );
			if ( ! isset( $tags[2][ $n ][0] ) || '' === $tags[2][ $n ][0] ) {
				continue;
			}
			$matched = 0;
			$new     = preg_replace_callback(
				$attr_re,
				function ( $a ) use ( $op, $json, &$matched ) {
					$attr = strtolower( $a[2] );
					if ( 'srcset' === $attr ) {
						if ( $op['unlink'] ) {
							return $a[0];
						}
						$parts = explode( ',', $a[5] );
						foreach ( $parts as $i => $part ) {
							if ( ! preg_match( '/^(\s*)(\S+)(.*)$/s', $part, $p ) ) {
								continue;
							}
							$decoded = self::decode_attr( $p[2], $json );
							// A comma would split the new URL into two srcset candidates.
							if ( in_array( $decoded, $op['forms'], true ) && ! self::keeps( $decoded, $op ) && false === strpos( $op['to'], ',' ) ) {
								$parts[ $i ] = $p[1] . self::encode_attr( $op['to'], $p[2], $json ) . $p[3];
								++$matched;
							}
						}
						return $a[1] . $a[2] . $a[3] . $a[4] . implode( ',', $parts ) . $a[4];
					}
					$decoded = self::decode_attr( trim( $a[5] ), $json );
					if ( ! in_array( $decoded, $op['forms'], true ) ) {
						return $a[0];
					}
					if ( 'href' === $attr ) {
						++$matched; // Anchors are finished below (unlink, rel_strip).
						return ( $op['unlink'] || self::keeps( $decoded, $op ) ) ? $a[0] : $a[1] . $a[2] . $a[3] . $a[4] . self::encode_attr( $op['to'], $a[5], $json ) . $a[4];
					}
					if ( $op['unlink'] || self::keeps( $decoded, $op ) ) {
						return $a[0];
					}
					++$matched;
					return $a[1] . $a[2] . $a[3] . $a[4] . self::encode_attr( $op['to'], $a[5], $json ) . $a[4];
				},
				$old
			);
			if ( ! $matched ) {
				continue;
			}
			$href_matched = 'a' === $name && preg_match( '/\shref\s*=\s*(\\\\"|"|\')(.*?)\1/is', $old, $h ) && in_array( self::decode_attr( trim( $h[2] ), $json ), $op['forms'], true );

			if ( $href_matched && $op['unlink'] ) {
				// A Gutenberg button without its link is an invalid block.
				if ( preg_match( '/\sclass\s*=\s*(\\\\"|"|\')[^"\']*\b(wp-block-button__link|wp-element-button)\b/i', $old ) ) {
					++$skip;
					continue;
				}
				$close = $json ? '/<\\\\?\/a\s*>/i' : '/<\/a\s*>/i';
				if ( ! preg_match( $close, $text, $c, PREG_OFFSET_CAPTURE, $offset + strlen( $old ) ) ) {
					++$skip;
					continue;
				}
				$edits[] = array( $offset, strlen( $old ), '', 1 );
				$edits[] = array( $c[0][1], strlen( $c[0][0] ), '', 0 );
				continue;
			}
			if ( $href_matched && '' !== $op['rel_strip'] ) {
				$new = self::strip_rel( $new, $op['rel_strip'] );
			}
			if ( $new !== $old ) {
				$edits[] = array( $offset, strlen( $old ), $new, $matched );
			}
		}
	}

	/**
	 * Whether a matched URL stays as it is: it already is the new URL, or the
	 * change only strips rel (from and to are the same URL), in which case a
	 * relative link stays relative.
	 *
	 * @param string $decoded Matched URL.
	 * @param array  $op      Validated item.
	 * @return bool
	 */
	private static function keeps( $decoded, array $op ) {
		return $decoded === $op['to'] || ( isset( $op['from'] ) && $op['from'] === $op['to'] );
	}

	/**
	 * Remove one token from an anchor tag's rel attribute; drop the attribute
	 * if nothing is left.
	 *
	 * @param string $tag   Start tag.
	 * @param string $token Token to remove ('nofollow').
	 * @return string
	 */
	private static function strip_rel( $tag, $token ) {
		return preg_replace_callback(
			'/\srel\s*=\s*(\\\\"|"|\')(.*?)\1/is',
			function ( $r ) use ( $token ) {
				$tokens = preg_split( '/\s+/', trim( $r[2] ) );
				$keep   = array();
				foreach ( $tokens as $t ) {
					if ( '' !== $t && strtolower( $t ) !== $token ) {
						$keep[] = $t;
					}
				}
				if ( count( $keep ) === count( array_filter( $tokens, 'strlen' ) ) ) {
					return $r[0];
				}
				return $keep ? ' rel=' . $r[1] . implode( ' ', $keep ) . $r[1] : '';
			},
			$tag
		);
	}

	/**
	 * Rewrite CSS url(...) values (style attributes, style blocks, Elementor CSS).
	 *
	 * @param string $text  Stored value.
	 * @param array  $op    Validated item.
	 * @param array  $edits Edits collected so far (by reference): [ offset, length, replacement, matches it counts as ].
	 * @param int    $skip  Matches deliberately left alone (by reference).
	 */
	private static function rewrite_css_urls( $text, array $op, array &$edits, &$skip ) {
		if ( ! preg_match_all( '/url\(\s*(&quot;|\\\\"|"|\'|)([^"\'()\s]+?)\1\s*\)/i', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return;
		}
		foreach ( $m[2] as $u ) {
			$decoded = self::decode_attr( $u[0], true );
			if ( in_array( $decoded, $op['forms'], true ) && ! self::keeps( $decoded, $op ) ) {
				// Brackets, semicolons or commas in a URL would end the CSS value
				// early and let the rest act as CSS, so such a URL is not written here.
				if ( preg_match( '/[();,]/', $op['to'] ) ) {
					++$skip;
					continue;
				}
				$edits[] = array( $u[1], strlen( $u[0] ), self::encode_attr( $op['to'], $u[0], true ), 1 );
			}
		}
	}

	/**
	 * Rewrite whole JSON string values that equal the URL, inside
	 * $text[$start, $start + $len). Keeps the source's escaping style.
	 *
	 * @param string $text   Stored value.
	 * @param int    $start  Span start.
	 * @param int    $len    Span length.
	 * @param bool   $block  Gutenberg block comment (escape "--", "<", ">", "&" as WordPress does).
	 * @param array  $op     Validated item.
	 * @param array  $edits  Edits collected so far (by reference).
	 */
	private static function rewrite_json_strings( $text, $start, $len, $block, array $op, array &$edits ) {
		$span = substr( $text, $start, $len );
		if ( ! preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/s', $span, $m, PREG_OFFSET_CAPTURE ) ) {
			return;
		}
		foreach ( $m[1] as $s ) {
			$raw = $s[0];
			if ( '' === $raw || strlen( $raw ) > self::MAX_URL_LEN * 6 ) {
				continue;
			}
			$decoded = json_decode( '"' . $raw . '"' );
			if ( ! is_string( $decoded ) || ! in_array( $decoded, $op['forms'], true ) || self::keeps( $decoded, $op ) ) {
				continue;
			}
			$flags = JSON_UNESCAPED_UNICODE;
			if ( false === strpos( $raw, '\\/' ) ) {
				$flags |= JSON_UNESCAPED_SLASHES;
			}
			$enc = substr( (string) wp_json_encode( $op['to'], $flags ), 1, -1 );
			if ( $block || false !== strpos( $raw, '\\u0026' ) ) {
				$enc = str_replace( '&', '\\u0026', $enc );
			}
			if ( $block ) {
				$enc = str_replace( '--', '\\u002d\\u002d', $enc );
			}
			$edits[] = array( $start + $s[1], strlen( $raw ), $enc, 1 );
		}
	}

	/**
	 * An attribute value as the URL it holds: entities decoded, and in JSON
	 * the escaped slashes undone.
	 *
	 * @param string $value Raw value.
	 * @param bool   $json  Inside a JSON string.
	 * @return string
	 */
	private static function decode_attr( $value, $json ) {
		if ( $json ) {
			$value = str_replace( array( '\\/', '\\u0026' ), array( '/', '&' ), $value );
		}
		return str_replace( array( '&amp;', '&#038;', '&#38;' ), '&', $value );
	}

	/**
	 * The new URL encoded like the value it replaces.
	 *
	 * @param string $url New URL (validated: no quotes, angle brackets or spaces).
	 * @param string $was Raw value being replaced.
	 * @param bool   $json Inside a JSON string.
	 * @return string
	 */
	private static function encode_attr( $url, $was, $json ) {
		if ( false !== strpos( $was, '&#038;' ) ) {
			$url = str_replace( '&', '&#038;', $url );
		} elseif ( false !== strpos( $was, '\\u0026' ) ) {
			$url = str_replace( '&', '\\u0026', $url );
		} elseif ( false === strpos( $was, '&' ) || false !== strpos( $was, '&amp;' ) ) {
			$url = str_replace( '&', '&amp;', $url );
		}
		if ( $json && false !== strpos( $was, '\\/' ) ) {
			$url = str_replace( '/', '\\/', $url );
		}
		return $url;
	}

	/**
	 * Apply non-overlapping edits and describe them as reversible splices.
	 *
	 * @param string $text    Original value.
	 * @param array  $edits   [ offset, length, replacement, matches it counts as ] on $text, any order.
	 * @param int    $skip    Matches left alone.
	 * @return array { text, count, skipped, splices }
	 */
	private static function splice( $text, array $edits, $skip ) {
		usort(
			$edits,
			function ( $a, $b ) {
				return $a[0] - $b[0];
			}
		);
		$out     = '';
		$count   = 0;
		$pos     = 0;
		$splices = array();
		foreach ( $edits as $e ) {
			if ( $e[0] < $pos ) {
				continue; // Overlaps an earlier edit; never happens with the passes above, but never corrupt.
			}
			$out      .= substr( $text, $pos, $e[0] - $pos );
			$splices[] = array( strlen( $out ), strlen( $e[2] ), substr( $text, $e[0], $e[1] ) );
			$out      .= $e[2];
			$count    += $e[3];
			$pos       = $e[0] + $e[1];
		}
		$out .= substr( $text, $pos );
		return array(
			'text'    => $out,
			'count'   => (int) $count,
			'skipped' => (int) $skip,
			'splices' => $splices,
		);
	}

	/**
	 * Undo splices on the value they produced.
	 *
	 * @param string $text    Value as rewrite() left it.
	 * @param array  $splices From rewrite().
	 * @return string The original value.
	 */
	public static function unsplice( $text, array $splices ) {
		for ( $i = count( $splices ) - 1; $i >= 0; $i-- ) {
			$text = substr_replace( $text, (string) $splices[ $i ][2], (int) $splices[ $i ][0], (int) $splices[ $i ][1] );
		}
		return $text;
	}

	/**
	 * Run a batch: validate, find targets, rewrite (or preview on dry_run),
	 * log one change entry per item that changed anything.
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
			$op = self::validate( $item );
			if ( is_wp_error( $op ) ) {
				$results[] = array(
					'index'   => $i,
					'ok'      => false,
					'error'   => $op->get_error_code(),
					'message' => $op->get_error_message(),
				);
				continue;
			}
			$targets = self::targets( $op );
			if ( is_wp_error( $targets ) ) {
				$results[] = array(
					'index'   => $i,
					'ok'      => false,
					'error'   => $targets->get_error_code(),
					'message' => $targets->get_error_message(),
				);
				continue;
			}

			$done    = array();
			$skipped = 0;
			foreach ( $targets as $t ) {
				$value = self::read( $t['post_id'], $t['where'] );
				if ( null === $value ) {
					continue;
				}
				$r        = self::rewrite( $value, $t['where'], $op );
				$skipped += $r['skipped'];
				if ( ! $r['count'] ) {
					continue;
				}
				$done[] = array(
					'post_id' => $t['post_id'],
					'where'   => $t['where'],
					'count'   => $r['count'],
					'text'    => $r['text'],
					'splices' => $r['splices'],
				);
			}
			if ( 'site' === $op['scope'] && count( $done ) > self::MAX_SITE_TARGETS ) {
				$results[] = array(
					'index'   => $i,
					'ok'      => false,
					'error'   => 'ccc_too_many_targets',
					/* translators: 1: number of places the link was found, 2: the limit */
					'message' => sprintf( __( 'The link is in %1$d places, more than the %2$d one change may edit. Fix it page by page instead.', 'crawl-cove-connector' ), count( $done ), self::MAX_SITE_TARGETS ),
				);
				continue;
			}

			$result = array(
				'index'     => $i,
				'ok'        => true,
				'dry_run'   => (bool) $dry_run,
				'scope'     => $op['scope'],
				'from'      => $op['from'],
				'to'        => $op['to'],
				'unlink'    => $op['unlink'],
				'changed'   => (bool) $done,
				'count'     => array_sum( wp_list_pluck( $done, 'count' ) ),
				'skipped'   => $skipped,
				'locations' => self::locations( $done ),
				'posts'     => self::posts( $done ),
			);
			if ( 'page' === $op['scope'] ) {
				$result['post_id'] = $op['post_id'];
			}

			if ( ! $dry_run && $done ) {
				$stored = array();
				foreach ( $done as $d ) {
					self::write( $d['post_id'], $d['where'], $d['text'] );
					$stored[] = array(
						'post_id' => $d['post_id'],
						'where'   => $d['where'],
						'count'   => $d['count'],
						'hash'    => md5( $d['text'] ),
						'splices' => $d['splices'],
					);
				}
				self::purge( $stored );
				$entry               = CCC_Change_Log::record_entry(
					array(
						'kind'      => 'replace_urls',
						'post_id'   => $op['post_id'],
						'field'     => 'links',
						'from'      => $op['from'],
						'to'        => $op['to'],
						'scope'     => $op['scope'],
						'unlink'    => $op['unlink'],
						'rel_strip' => $op['rel_strip'],
						'targets'   => $stored,
					),
					$source
				);
				$result['change_id'] = $entry['id'];
			}
			$results[] = $result;
		}
		return $results;
	}

	/**
	 * Per-location totals for a reply.
	 *
	 * @param array $done Rewritten targets.
	 * @return array[] [ { where, count } ]
	 */
	private static function locations( array $done ) {
		$by = array();
		foreach ( $done as $d ) {
			$by[ $d['where'] ] = ( isset( $by[ $d['where'] ] ) ? $by[ $d['where'] ] : 0 ) + $d['count'];
		}
		$out = array();
		foreach ( $by as $where => $n ) {
			$out[] = array(
				'where' => $where,
				'count' => $n,
			);
		}
		return $out;
	}

	/**
	 * Per-post breakdown for a reply.
	 *
	 * @param array $done Rewritten targets.
	 * @return array[] [ { post_id, locations: [ { where, count } ] } ]
	 */
	private static function posts( array $done ) {
		$by = array();
		foreach ( $done as $d ) {
			$by[ $d['post_id'] ][] = array(
				'where' => $d['where'],
				'count' => $d['count'],
			);
		}
		$out = array();
		foreach ( $by as $post_id => $locations ) {
			$out[] = array(
				'post_id'   => (int) $post_id,
				'locations' => $locations,
			);
		}
		return $out;
	}

	/**
	 * Every (post, location) this change may edit.
	 *
	 * @param array $op Validated item.
	 * @return array[]|WP_Error [ { post_id, where } ]
	 */
	private static function targets( array $op ) {
		if ( 'page' === $op['scope'] ) {
			$out = array(
				array(
					'post_id' => $op['post_id'],
					'where'   => 'content',
				),
			);
			if ( null !== self::read( $op['post_id'], 'elementor' ) ) {
				$out[] = array(
					'post_id' => $op['post_id'],
					'where'   => 'elementor',
				);
			}
			return $out;
		}

		global $wpdb;
		$types = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( 'attachment' !== $type && is_post_type_viewable( $type ) ) {
				$types[] = $type;
			}
		}
		$like     = '%' . $wpdb->esc_like( self::needle( $op['from'] ) ) . '%';
		$type_in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$state_in = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
		$args     = array_merge( $types, self::STATUSES );
		$out      = array();
		if ( $types ) {
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($type_in) AND post_status IN ($state_in) AND post_content LIKE %s ORDER BY ID", array_merge( $args, array( $like ) ) ) );
			foreach ( $ids as $id ) {
				$out[] = array(
					'post_id' => (int) $id,
					'where'   => 'content',
				);
			}
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type IN ($type_in) AND p.post_status IN ($state_in) AND m.meta_key = %s AND m.meta_value LIKE %s ORDER BY p.ID", array_merge( $args, array( self::ELEMENTOR_KEY, $like ) ) ) );
			// phpcs:enable
			foreach ( $ids as $id ) {
				$out[] = array(
					'post_id' => (int) $id,
					'where'   => 'elementor',
				);
			}
		}
		// Block themes keep their menus as wp_navigation posts (navigation-link
		// blocks, URL in the block comment), often converted from the classic
		// menu on first render, so the header can still link to the old URL
		// after every _menu_item_url is fixed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wp_navigation' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID", $like ) );
		foreach ( $ids as $id ) {
			$out[] = array(
				'post_id' => (int) $id,
				'where'   => 'navigation',
			);
		}
		$keep = array();
		foreach ( $out as $t ) {
			if ( current_user_can( 'edit_post', $t['post_id'] ) ) {
				$keep[] = $t;
			}
		}
		if ( current_user_can( 'edit_theme_options' ) && ! $op['unlink'] ) {
			$forms_in = implode( ',', array_fill( 0, count( $op['forms'] ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'nav_menu_item' AND m.meta_key = %s AND m.meta_value IN ($forms_in) ORDER BY p.ID", array_merge( array( self::MENU_KEY ), $op['forms'] ) ) );
			// phpcs:enable
			foreach ( $ids as $id ) {
				$keep[] = array(
					'post_id' => (int) $id,
					'where'   => 'menu',
				);
			}
		}
		// The LIKE pre-filter is coarse; stop before reading thousands of rows.
		if ( count( $keep ) > self::MAX_SITE_TARGETS * 5 ) {
			/* translators: 1: number of places the link was found, 2: the limit */
			return self::error( 'ccc_too_many_targets', sprintf( __( 'The link is in %1$d places, more than the %2$d one change may edit. Fix it page by page instead.', 'crawl-cove-connector' ), count( $keep ), self::MAX_SITE_TARGETS ) );
		}
		return $keep;
	}

	/**
	 * A literal piece of the URL that every stored form of it contains
	 * (escaped slashes and entities included), for a coarse LIKE pre-filter.
	 *
	 * @param string $from Absolute URL.
	 * @return string
	 */
	public static function needle( $from ) {
		$best = '';
		$path = (string) wp_parse_url( $from, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $from, PHP_URL_QUERY );
		foreach ( preg_split( '#[/?&=\#]+#', $path ) as $piece ) {
			if ( strlen( $piece ) > strlen( $best ) ) {
				$best = $piece;
			}
		}
		return '' !== $best ? $best : (string) wp_parse_url( $from, PHP_URL_HOST );
	}

	/**
	 * Read one stored location raw; null when it does not exist.
	 *
	 * @param int    $post_id Post id.
	 * @param string $where   content|navigation|elementor|menu.
	 * @return string|null
	 */
	public static function read( $post_id, $where ) {
		if ( 'content' === $where || 'navigation' === $where ) {
			$post = get_post( $post_id );
			return $post ? (string) $post->post_content : null;
		}
		$key = 'menu' === $where ? self::MENU_KEY : self::ELEMENTOR_KEY;
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
			return null;
		}
		$value = get_post_meta( $post_id, $key, true );
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Write one location. post_content goes straight to the row: wp_update_post()
	 * would run the whole body through kses as this user and strip an admin's
	 * embeds (the 0.10.8 lesson), and the only bytes that change here are
	 * validated URLs and removed anchor tags.
	 *
	 * @param int    $post_id Post id.
	 * @param string $where   content|navigation|elementor|menu.
	 * @param string $text    New value.
	 */
	private static function write( $post_id, $where, $text ) {
		global $wpdb;
		if ( 'content' === $where || 'navigation' === $where ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $wpdb->posts, array( 'post_content' => $text ), array( 'ID' => $post_id ) );
			clean_post_cache( $post_id );
			return;
		}
		update_post_meta( $post_id, 'menu' === $where ? self::MENU_KEY : self::ELEMENTOR_KEY, wp_slash( $text ) );
		if ( 'elementor' === $where ) {
			// Elementor renders from per-element and CSS caches; drop both so
			// the next view rebuilds them from the new data.
			delete_post_meta( $post_id, '_elementor_element_cache' );
			delete_post_meta( $post_id, '_elementor_css' );
		}
	}

	/**
	 * Purge page caches for what was written: each post, and the whole site
	 * when a menu (on every page) changed.
	 *
	 * @param array $targets Written targets.
	 */
	private static function purge( array $targets ) {
		$full = false;
		$seen = array();
		foreach ( $targets as $t ) {
			if ( 'menu' === $t['where'] || 'navigation' === $t['where'] ) {
				$full = true;
				continue;
			}
			if ( ! isset( $seen[ $t['post_id'] ] ) ) {
				$seen[ $t['post_id'] ] = true;
				CCC_Service::invalidate_caches_for( (int) $t['post_id'] );
			}
		}
		if ( $full ) {
			CCC_Service::invalidate_caches_for( CCC_Service::HOME_ID );
		}
	}

	/**
	 * Whether the current user may edit every location a logged replace touched.
	 *
	 * @param array $entry Change log entry (kind replace_urls).
	 * @return bool
	 */
	public static function can_edit_entry( array $entry ) {
		$targets = isset( $entry['targets'] ) && is_array( $entry['targets'] ) ? $entry['targets'] : array();
		foreach ( $targets as $t ) {
			$ok = 'menu' === $t['where'] ? current_user_can( 'edit_theme_options' ) : current_user_can( 'edit_post', (int) $t['post_id'] );
			if ( ! $ok ) {
				return false;
			}
		}
		return (bool) $targets;
	}

	/**
	 * Revert a logged replace: every location goes back to its exact original
	 * bytes, or nothing is written. A location edited since (its hash no
	 * longer matches) refuses with ccc_value_changed; with $force a plain URL
	 * replace is reversed there by rewriting the new URL back to the old one.
	 * An unlink or nofollow strip cannot be re-derived once the text moved,
	 * so those refuse even with $force.
	 *
	 * @param array $entry Change log entry.
	 * @param bool  $force Revert drifted locations anyway.
	 * @return true|WP_Error
	 */
	public static function revert( array $entry, $force ) {
		$plan    = array();
		$drifted = array();
		foreach ( $entry['targets'] as $t ) {
			$value = self::read( (int) $t['post_id'], $t['where'] );
			if ( null !== $value && md5( $value ) === $t['hash'] ) {
				$plan[] = array( $t, self::unsplice( $value, $t['splices'] ) );
				continue;
			}
			$drifted[] = (int) $t['post_id'];
			if ( ! $force || null === $value ) {
				continue;
			}
			if ( ! empty( $entry['unlink'] ) || ! empty( $entry['rel_strip'] ) ) {
				return self::error( 'ccc_content_changed', __( 'The content has been edited since this change, and a removed link or nofollow cannot be put back safely. Nothing was written.', 'crawl-cove-connector' ), 409 );
			}
			$back   = self::rewrite(
				$value,
				$t['where'],
				array(
					'forms'     => self::forms( $entry['to'] ),
					'from'      => $entry['to'],
					'to'        => $entry['from'],
					'unlink'    => false,
					'rel_strip' => '',
				)
			);
			$plan[] = array( $t, $back['text'] );
		}
		if ( $drifted && ! $force ) {
			return new WP_Error(
				'ccc_value_changed',
				__( 'The content has changed since this change was made, so reverting it would overwrite the newer edits. Nothing was written.', 'crawl-cove-connector' ),
				array(
					'status'   => 409,
					'post_ids' => array_values( array_unique( $drifted ) ),
				)
			);
		}
		$written = array();
		foreach ( $plan as $p ) {
			self::write( (int) $p[0]['post_id'], $p[0]['where'], $p[1] );
			$written[] = $p[0];
		}
		self::purge( $written );
		return true;
	}

	/**
	 * A 400-ish WP_Error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
