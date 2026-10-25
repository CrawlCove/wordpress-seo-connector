#!/usr/bin/env bash
#
# POST /replace-urls (0.11, ticket #262) against a real WordPress with real
# Elementor and NO SEO plugin (the route must not need one).
#
# Pins: /status advertises replace_urls; dry-run writes nothing; a page-scope
# replace changes the served href and nothing else, and reverts to the exact
# original bytes; an Author's replace keeps an admin's iframe (no kses re-save)
# and the modified date; an Author cannot touch someone else's page, and a
# site-scope run by an Author only reaches the Author's own posts; a site-scope
# run by an admin reaches post content, Elementor data (front end re-rendered)
# a classic menu custom link and the block theme's wp_navigation menu, as ONE change with one revert; a revert after a hand
# edit is refused (409) until forced; unlink keeps the text and leaves a
# Gutenberg button alone; rel_strip removes nofollow only.
#
# Self-contained (own site dir and port), like caching-checks.sh.
# Run: bash tests/integration/replace-urls-checks.sh

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-replace"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8994
URL="http://127.0.0.1:$PORT"
SERVER_PID=""

log() { echo "[replace-urls] $*"; }

cleanup() {
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

for f in wordpress.zip sqlite.zip elementor.zip wp-cli.phar; do
  if [[ ! -f "$CACHE/$f" ]]; then
    echo "missing $CACHE/$f (elementor: curl -sL -o $CACHE/elementor.zip https://downloads.wordpress.org/plugin/elementor.latest-stable.zip)" >&2
    exit 1
  fi
done

log "rebuilding site dir"
rm -rf "$SITE"
mkdir -p "$SITE"
unzip -q "$CACHE/wordpress.zip" -d "$SITE/_core"
cp -r "$SITE"/_core/wordpress/. "$SITE/"
rm -rf "$SITE/_core"
mkdir -p "$SITE/wp-content/plugins" "$SITE/wp-content/mu-plugins"
unzip -q "$CACHE/sqlite.zip" -d "$SITE/wp-content/plugins"
unzip -q "$CACHE/elementor.zip" -d "$SITE/wp-content/plugins"
cp "$SITE/wp-content/plugins/sqlite-database-integration/db.copy" "$SITE/wp-content/db.php"
bash "$PLUGIN_ROOT/bin/build-zip.sh" --stage-to "$SITE/wp-content/plugins/crawl-cove-connector" >/dev/null
cat > "$SITE/wp-content/mu-plugins/ccc-test-harness.php" <<'PHP'
<?php
add_filter( 'wp_is_application_passwords_available', '__return_true' );
// Elementor logs the last PHP error at shutdown and, under wp-cli, prints it
// to stdout (a PHP 8.5 deprecation in its own code), which would land in
// every captured post id. WordPress's shutdown hook runs before Elementor's.
add_action( 'shutdown', function () { error_clear_last(); }, PHP_INT_MAX );
PHP

WP=("${WPCLI[@]}" --path="$SITE")
"${WP[@]}" config create --dbname=irrelevant --dbuser=irrelevant --dbpass=irrelevant --skip-check --quiet
"${WP[@]}" core install --url="$URL" --title="CCC Replace URLs" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
"${WP[@]}" plugin activate sqlite-database-integration crawl-cove-connector elementor --quiet

"${WP[@]}" user create ccc_editor editor@example.com --role=editor --user_pass=editor-pass --quiet
"${WP[@]}" user create ccc_author author@example.com --role=author --user_pass=author-pass --quiet
"${WP[@]}" user create ccc_subscriber sub@example.com --role=subscriber --user_pass=sub-pass --quiet
EDITOR_ID="$("${WP[@]}" user get ccc_editor --field=ID)"
AUTHOR_ID="$("${WP[@]}" user get ccc_author --field=ID)"
pw_for() { "${WP[@]}" user application-password create "$1" "ccc-test" --porcelain; }
export CCC_URL="$URL" CCC_EDITOR_PW="$(pw_for ccc_editor)" CCC_AUTHOR_PW="$(pw_for ccc_author)" CCC_SUBSCRIBER_PW="$(pw_for ccc_subscriber)"
export CCC_POST_EDITOR=0 CCC_POST_AUTHOR=0 CCC_POST_HOME=0 CCC_ADAPTER=none
ADMIN="admin:$(pw_for admin)"
source "$HERE/lib.sh"

OLD="$URL/old-page/"
NEW="$URL/new-page/"

# Content written with kses off, as an admin saving it in the editor would.
put_content() { # post_id, php string literal
  "${WP[@]}" eval "kses_remove_filters(); global \$wpdb; \$wpdb->update( \$wpdb->posts, array( 'post_content' => $2 ), array( 'ID' => $1 ) ); clean_post_cache( $1 );" >/dev/null
}
content()  { "${WP[@]}" post get "$1" --field=post_content; }
md5of()    { content "$1" | md5sum | cut -d' ' -f1; }
modified() { "${WP[@]}" post get "$1" --field=post_modified_gmt; }
page()     { curl -sL --max-time 15 "$URL/?p=$1"; }
# The page's own links: the theme's navigation block renders the classic menu on every page.
own_links() { page "$1" | grep -o '<a [^>]*>[^<]*' | grep -v 'wp-block-navigation-item__content'; }
ok()   { PASS=$((PASS+1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL $1"; }
has()    { if grep -qF -- "$3" <<<"$2"; then ok "$1"; else fail "$1 — [$3] missing"; fi; }
hasnt()  { if grep -qF -- "$3" <<<"$2"; then fail "$1 — [$3] still there"; else ok "$1"; fi; }
same()   { if [[ "$2" == "$3" ]]; then ok "$1"; else fail "$1 — want [$2], got [$3]"; fi; }

log "fixtures: editor page (Gutenberg), author post (iframe), Elementor page, nofollow post, menu link"
P1="$("${WP[@]}" post create --post_type=page --post_title="Links page" --post_status=publish --post_author="$EDITOR_ID" --porcelain)"
put_content "$P1" "'<!-- wp:paragraph --><p>Read <a href=\"$OLD\">the old page</a> or <a href=\"/old-page/\">this one</a>. Not <a href=\"${OLD}two/\">this</a>. Text $OLD stays.</p><!-- /wp:paragraph --><!-- wp:buttons --><div class=\"wp-block-buttons\"><!-- wp:button --><div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"$OLD\">Buy</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'"
P2="$("${WP[@]}" post create --post_title="Author post" --post_status=publish --post_author="$AUTHOR_ID" --porcelain)"
put_content "$P2" "'<p>Watch:</p><iframe src=\"https://www.youtube.com/embed/abc\"></iframe><p><a href=\"$OLD\">old</a></p>'"
P3="$("${WP[@]}" post create --post_type=page --post_title="Elementor page" --post_status=publish --post_author="$EDITOR_ID" --porcelain)"
"${WP[@]}" eval "
\$data = array( array( 'id' => 'a1b2c3d', 'elType' => 'section', 'settings' => array(), 'isInner' => false, 'elements' => array(
  array( 'id' => 'b2c3d4e', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ), 'isInner' => false, 'elements' => array(
    array( 'id' => 'c3d4e5f', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Elementor button', 'link' => array( 'url' => '$OLD', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ) ), 'elements' => array() ),
    array( 'id' => 'd4e5f6a', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p><a href=\"$OLD\">elementor inline</a></p>' ), 'elements' => array() ),
  ) ),
) ) );
update_post_meta( $P3, '_elementor_edit_mode', 'builder' );
update_post_meta( $P3, '_elementor_template_type', 'wp-page' );
update_post_meta( $P3, '_elementor_version', ELEMENTOR_VERSION );
update_post_meta( $P3, '_elementor_data', wp_slash( wp_json_encode( \$data ) ) );
" >/dev/null
P4="$("${WP[@]}" post create --post_title="Nofollow post" --post_status=publish --post_author="$EDITOR_ID" --porcelain)"
put_content "$P4" "'<p><a href=\"/old-page/\" rel=\"nofollow noopener\">a</a> <a href=\"/elsewhere/\" rel=\"nofollow\">b</a></p>'"
MENU="$("${WP[@]}" menu create "Main" --porcelain)"
ITEM="$("${WP[@]}" menu item add-custom "$MENU" "Old page" "$OLD" --porcelain)"
menu_html() { "${WP[@]}" eval "echo wp_nav_menu( array( 'menu' => $MENU, 'echo' => false, 'fallback_cb' => false ) );"; }

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server-replace.log" 2>&1 ) &
SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$URL/index.php?rest_route=/" && break; sleep 0.3; done

echo "-- fixtures render --"
E_PAGE="$(page "$P3")"
has "fixture: Elementor renders the button from _elementor_data" "$E_PAGE" "href=\"$OLD\""
has "fixture: Elementor rendered its own markup (not post_content)" "$E_PAGE" "elementor-button"
has "fixture: menu renders the custom link" "$(menu_html)" "href=\"$OLD\""

echo "-- status and auth --"
req GET /status "$EDITOR" ""
check "status: capabilities with no SEO plugin skip title and description" 200 "$RESP_HTTP" '.capabilities' '["replace_urls","site_settings","image_alt"]' "$RESP_BODY"
req POST /replace-urls "$SUBSCRIBER" "{\"changes\":[{\"from\":\"$OLD\",\"to\":\"$NEW\",\"scope\":\"site\"}]}"
check_http "subscriber: refused" 403 "$RESP_HTTP" "$RESP_BODY"
req POST /replace-urls "" "{\"changes\":[{\"from\":\"$OLD\",\"to\":\"$NEW\",\"scope\":\"site\"}]}"
check_http "anonymous: refused" 401 "$RESP_HTTP" "$RESP_BODY"
req POST /replace-urls "$EDITOR" "{\"changes\":[{\"from\":\"$OLD\",\"to\":\"javascript:alert(1)\",\"scope\":\"site\"},{\"from\":\"$OLD\",\"to\":\"$URL/x\\\"onmouseover=\\\"y\",\"scope\":\"site\"}]}"
check "bad to: javascript: refused" 200 "$RESP_HTTP" '[.[].error]' '["ccc_bad_url","ccc_bad_url"]' "$RESP_BODY"

echo "-- page scope (editor, P1) --"
P1_MD5="$(md5of "$P1")"
req POST /replace-urls "$EDITOR" "{\"dry_run\":true,\"changes\":[{\"url\":\"$URL/?page_id=$P1\",\"from\":\"$OLD\",\"to\":\"$NEW\",\"scope\":\"page\"}]}"
check "dry run: 3 links counted (absolute, relative, button)" 200 "$RESP_HTTP" '.[0] | [.post_id == '"$P1"', .count, .locations[0].where, has("change_id")]' '[true,3,"content",false]' "$RESP_BODY"
same "dry run: content untouched" "$P1_MD5" "$(md5of "$P1")"
req POST /replace-urls "$EDITOR" "{\"changes\":[{\"url\":\"$URL/?page_id=$P1\",\"from\":\"$OLD\",\"to\":\"$NEW\",\"scope\":\"page\"}]}"
check "apply: ok with a change_id" 200 "$RESP_HTTP" '.[0] | [.ok, .count, (.change_id|type)]' '[true,3,"number"]' "$RESP_BODY"
C1="$(echo "$RESP_BODY" | jq -r '.[0].change_id')"
P1_PAGE="$(page "$P1")"
has   "front end: link now points at the final URL" "$P1_PAGE" "<a href=\"$NEW\">the old page</a>"
hasnt "front end: no href to the old URL is left in the page body" "$(own_links "$P1")" "href=\"$OLD\""
has   "front end: a longer URL sharing the prefix is untouched" "$P1_PAGE" "href=\"${OLD}two/\""
has   "front end: the URL as plain text is untouched" "$P1_PAGE" "Text $OLD stays."
req POST /revert "$EDITOR" "{\"change_id\":$C1}"
check "revert: ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
same "revert: post_content is byte-identical to before" "$P1_MD5" "$(md5of "$P1")"

echo "-- author --"
P2_BEFORE="$(content "$P2")"; P2_MOD="$(modified "$P2")"; sleep 1
req POST /replace-urls "$AUTHOR" "{\"changes\":[{\"url\":\"$URL/?p=$P2\",\"from\":\"$OLD\",\"to\":\"$NEW\",\"scope\":\"page\"}]}"
check "author: own post ok" 200 "$RESP_HTTP" '.[0].count' '1' "$RESP_BODY"
C2="$(echo "$RESP_BODY" | jq -r '.[0].change_id')"
has  "author: admin's iframe kept (no kses re-save)" "$(content "$P2")" '<iframe src="https://www.youtube.com/embed/abc"></iframe>'
same "author: post_modified unchanged" "$P2_MOD" "$(modified "$P2")"
req POST /revert "$AUTHOR" "{\"change_id\":$C2}"
same "author: revert restores the exact content" "$P2_BEFORE" "$(content "$P2")"
req POST /replace-urls "$AUTHOR" "{\"changes\":[{\"url\":\"$URL/?page_id=$P1\",\"from\":\"$OLD\",\"to\":\"$NEW\",\"scope\":\"page\"}]}"
check "author: editor's page refused" 200 "$RESP_HTTP" '.[0].error' 'ccc_forbidden' "$RESP_BODY"
req POST /replace-urls "$AUTHOR" "{\"dry_run\":true,\"changes\":[{\"from\":\"$OLD\",\"to\":\"$NEW\",\"scope\":\"site\"}]}"
check "author: site scope reaches only the author's own post" 200 "$RESP_HTTP" '[.[0].posts[].post_id]' "[$P2]" "$RESP_BODY"

echo "-- site scope (admin: content + Elementor + menu, one change) --"
req POST /replace-urls "$ADMIN" "{\"changes\":[{\"from\":\"$OLD\",\"to\":\"$NEW\",\"scope\":\"site\"}]}"
check "site: content, elementor and menu all reached" 200 "$RESP_HTTP" '[.[0].locations[] | "\(.where)=\(.count)"] | sort' '["content=5","elementor=2","menu=1","navigation=1"]' "$RESP_BODY"
C3="$(echo "$RESP_BODY" | jq -r '.[0].change_id')"
E_PAGE="$(page "$P3")"
has   "site: Elementor front end serves the new button URL" "$E_PAGE" "elementor-size-sm\" href=\"$NEW\""
has   "site: Elementor front end serves the new inline URL" "$E_PAGE" "<a href=\"$NEW\">elementor inline"
hasnt "site: Elementor front end has no old URL in its own links" "$(own_links "$P3")" "href=\"$OLD\""
has   "site: the theme's navigation serves the new menu URL" "$E_PAGE" "wp-block-navigation-item__content\"  href=\"$NEW\""
has   "site: menu renders the new URL" "$(menu_html)" "href=\"$NEW\""
"${WP[@]}" eval "json_decode( get_post_meta( $P3, '_elementor_data', true ), true ) or exit( 1 );" >/dev/null 2>&1 && ok "site: _elementor_data is still valid JSON" || fail "site: _elementor_data no longer decodes"
req GET /changes "$AUTHOR" ""
check "site: the author cannot see the admin's site-wide change" 200 "$RESP_HTTP" "[.[] | select(.id == $C3)] | length" '0' "$RESP_BODY"
req POST /revert "$EDITOR" "{\"change_id\":$C3}"
check_http "site: an editor (no menu rights) cannot revert it" 403 "$RESP_HTTP" "$RESP_BODY"

echo "-- revert after a hand edit --"
put_content "$P1" "'<p>Edited intro.</p>' . get_post( $P1 )->post_content"
req POST /revert "$ADMIN" "{\"change_id\":$C3}"
check "drift: refused with ccc_value_changed naming the post" 409 "$RESP_HTTP" '[.code, (.data.post_ids | index('"$P1"') != null)]' '["ccc_value_changed",true]' "$RESP_BODY"
has "drift: nothing was written (menu still new)" "$(menu_html)" "href=\"$NEW\""
req POST /revert "$ADMIN" "{\"change_id\":$C3,\"force\":true}"
check "drift: force reverts" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
has   "drift: the hand edit survives the forced revert" "$(content "$P1")" "Edited intro."
hasnt "drift: no new URL is left in P1" "$(content "$P1")" "$NEW"
has   "drift: Elementor is back on the old URL" "$(page "$P3")" "elementor-size-sm\" href=\"$OLD\""
has   "drift: menu is back on the old URL" "$(menu_html)" "href=\"$OLD\""
has   "drift: the theme's navigation is back on the old URL" "$(page "$P3")" "wp-block-navigation-item__content\"  href=\"$OLD\""

echo "-- unlink and rel_strip --"
req POST /replace-urls "$EDITOR" "{\"changes\":[{\"post_id\":$P1,\"from\":\"$OLD\",\"unlink\":true,\"scope\":\"page\"}]}"
check "unlink: two text links unlinked, the button skipped" 200 "$RESP_HTTP" '.[0] | [.count, .skipped]' '[2,1]' "$RESP_BODY"
P1_NOW="$(content "$P1")"
has "unlink: the link text stays" "$P1_NOW" "Read the old page or this one."
has "unlink: the button keeps its link" "$P1_NOW" "wp-element-button\" href=\"$OLD\">Buy</a>"
req POST /replace-urls "$EDITOR" "{\"changes\":[{\"post_id\":$P4,\"from\":\"$OLD\",\"to\":\"$OLD\",\"rel_strip\":\"nofollow\",\"scope\":\"page\"}]}"
check "rel_strip: one anchor changed" 200 "$RESP_HTTP" '.[0].count' '1' "$RESP_BODY"
same "rel_strip: nofollow gone, noopener and the other link kept, href stays relative" '<p><a href="/old-page/" rel="noopener">a</a> <a href="/elsewhere/" rel="nofollow">b</a></p>' "$(content "$P4")"

echo "-- Tools page change log --"
# Each row's Field and Post cells as "field|post", rendered by the real admin page.
TOOLS_ROWS="$("${WP[@]}" eval 'require_once WP_PLUGIN_DIR . "/crawl-cove-connector/admin/class-ccc-admin.php"; require_once ABSPATH . "wp-admin/includes/template.php"; wp_set_current_user( 1 ); ob_start(); CCC_Admin::render(); $h = ob_get_clean(); $h = substr( $h, strpos( $h, "<tbody>", strpos( $h, "widefat striped\">" ) ) ); preg_match_all( "#<tr>\s*<td>[^<]*</td>\s*<td>(.*?)</td>\s*<td>([^<]*)</td>#s", $h, $m ); foreach ( $m[1] as $i => $c ) { echo trim( $m[2][ $i ] ), "|", trim( preg_replace( "/\s+/", " ", wp_strip_all_tags( $c ) ) ), "\n"; }')"
has "tools: a page-scope links row names its page" "$TOOLS_ROWS" "links|Links page"
has "tools: a site-wide links row counts its places" "$TOOLS_ROWS" "links|Links in "
summary
