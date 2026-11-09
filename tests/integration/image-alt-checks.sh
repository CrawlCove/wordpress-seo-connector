#!/usr/bin/env bash
#
# POST /image-alt (0.11, app ticket #267) against a real WordPress with real
# Elementor and no SEO plugin.
#
# Pins: a resized copy's URL finds its media library attachment; one call sets
# the attachment alt AND the <img> alt in the page's Gutenberg content; the
# front end serves it there and in an Elementor image widget (which reads the
# attachment); an empty alt is written as alt=""; dry-run writes nothing; an
# Author cannot touch an editor's page; revert restores both places exactly,
# and refuses after a hand edit.
#
# Self-contained (own site dir and port). Run: bash tests/integration/image-alt-checks.sh

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-image-alt"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8996
URL="http://127.0.0.1:$PORT"
SERVER_PID=""

log() { echo "[image-alt] $*"; }

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

log "fixtures: an image, a Gutenberg page showing a resized copy, an Elementor image widget"
php -r '$i = imagecreatetruecolor( 800, 600 ); imagefill( $i, 0, 0, imagecolorallocate( $i, 200, 40, 40 ) ); imagejpeg( $i, "/tmp/ccc-image-alt-door.jpg" );'
IMG_ID="$("${WP[@]}" media import /tmp/ccc-image-alt-door.jpg --porcelain)"
rm -f /tmp/ccc-image-alt-door.jpg
FULL="$("${WP[@]}" eval "echo wp_get_attachment_url( $IMG_ID );")"
MEDIUM="$("${WP[@]}" eval "echo wp_get_attachment_image_src( $IMG_ID, 'medium' )[0];")"
P1="$("${WP[@]}" post create --post_type=page --post_title="Door page" --post_status=publish --post_author="$EDITOR_ID" --porcelain)"
"${WP[@]}" eval "global \$wpdb; \$wpdb->update( \$wpdb->posts, array( 'post_content' => '<!-- wp:image {\"id\":$IMG_ID,\"sizeSlug\":\"medium\"} --><figure class=\"wp-block-image size-medium\"><img src=\"$MEDIUM\" alt=\"\" class=\"wp-image-$IMG_ID\"/></figure><!-- /wp:image --><p>Text.</p>' ), array( 'ID' => $P1 ) ); clean_post_cache( $P1 );" >/dev/null
P2="$("${WP[@]}" post create --post_type=page --post_title="Elementor door" --post_status=publish --post_author="$EDITOR_ID" --porcelain)"
"${WP[@]}" eval "
\$data = array( array( 'id' => 'a1b2c3d', 'elType' => 'section', 'settings' => array(), 'isInner' => false, 'elements' => array(
  array( 'id' => 'b2c3d4e', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ), 'isInner' => false, 'elements' => array(
    array( 'id' => 'c3d4e5f', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'id' => $IMG_ID, 'url' => '$FULL' ) ), 'elements' => array() ),
  ) ),
) ) );
update_post_meta( $P2, '_elementor_edit_mode', 'builder' );
update_post_meta( $P2, '_elementor_template_type', 'wp-page' );
update_post_meta( $P2, '_elementor_version', ELEMENTOR_VERSION );
update_post_meta( $P2, '_elementor_data', wp_slash( wp_json_encode( \$data ) ) );
" >/dev/null
content()  { "${WP[@]}" post get "$1" --field=post_content; }
page()     { curl -sL --max-time 15 "$URL/?p=$1"; }
img_tags() { page "$1" | grep -o '<img [^>]*>' | grep -F "wp-content/uploads"; }
alt_meta() { "${WP[@]}" post meta get "$IMG_ID" _wp_attachment_image_alt; }
ok()    { PASS=$((PASS+1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL+1)); echo "  FAIL $1"; }
has()   { if grep -qF -- "$3" <<<"$2"; then ok "$1"; else fail "$1 — [$3] missing"; fi; }
hasnt() { if grep -qF -- "$3" <<<"$2"; then fail "$1 — [$3] present"; else ok "$1"; fi; }
same()  { if [[ "$2" == "$3" ]]; then ok "$1"; else fail "$1 — want [$2], got [$3]"; fi; }

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server-image-alt.log" 2>&1 ) &
SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$URL/index.php?rest_route=/" && break; sleep 0.3; done

echo "-- fixtures --"
if [[ "$MEDIUM" != "$FULL" && "$MEDIUM" == *-300x* ]]; then ok "fixture: the page shows a resized copy ($MEDIUM)"; else fail "fixture: no resized copy, got [$MEDIUM]"; fi
has "fixture: Gutenberg image renders with an empty alt" "$(img_tags "$P1")" 'alt=""'
has "fixture: Elementor renders the image widget" "$(img_tags "$P2")" "$URL/wp-content/uploads/"
req GET /status "$EDITOR" ""
check "status: capabilities list image_alt" 200 "$RESP_HTTP" '.capabilities | index("image_alt") != null' 'true' "$RESP_BODY"

echo "-- set alt --"
P1_BEFORE="$(content "$P1")"
ITEM="{\"url\":\"$URL/?page_id=$P1\",\"src\":\"$MEDIUM\",\"alt\":\"A red door\"}"
req POST /image-alt "$EDITOR" "{\"dry_run\":true,\"changes\":[$ITEM]}"
check "dry run: attachment found from the resized URL, one inline tag" 200 "$RESP_HTTP" '.[0] | [.attachment.id, .attachment.changed, .inline, has("change_id")]' "[$IMG_ID,true,1,false]" "$RESP_BODY"
same "dry run: nothing written" "" "$(alt_meta)"
req POST /image-alt "$AUTHOR" "{\"changes\":[$ITEM]}"
check "author: the editor's page is refused" 200 "$RESP_HTTP" '.[0].error' 'ccc_forbidden' "$RESP_BODY"
req POST /image-alt "$EDITOR" "{\"changes\":[$ITEM]}"
check "apply: ok with a change_id" 200 "$RESP_HTTP" '.[0] | [.ok, (.change_id|type)]' '[true,"number"]' "$RESP_BODY"
CID="$(echo "$RESP_BODY" | jq -r '.[0].change_id')"
same "apply: attachment alt set" "A red door" "$(alt_meta)"
has  "front end: the Gutenberg image serves the alt" "$(img_tags "$P1")" 'alt="A red door"'
has  "front end: the Elementor image (from the attachment) serves it" "$(img_tags "$P2")" 'alt="A red door"'
has  "content: the block markup still parses as the same image block" "$("${WP[@]}" eval "echo wp_json_encode( parse_blocks( get_post( $P1 )->post_content )[0]['attrs'] );")" "{\"id\":$IMG_ID,\"sizeSlug\":\"medium\"}"

echo "-- revert --"
req POST /revert "$EDITOR" "{\"change_id\":$CID}"
check "revert: ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
same "revert: attachment alt back to empty" "" "$(alt_meta)"
same "revert: page content byte-identical" "$P1_BEFORE" "$(content "$P1")"
hasnt "revert: Elementor stops serving the reverted alt (its widget cache was dropped)" "$(img_tags "$P2")" 'alt="A red door"'

echo "-- decorative, then a hand edit --"
"${WP[@]}" post meta update "$IMG_ID" _wp_attachment_image_alt "Old" >/dev/null
req POST /image-alt "$EDITOR" "{\"changes\":[{\"url\":\"$URL/?page_id=$P1\",\"src\":\"$MEDIUM\",\"alt\":\"\"}]}"
check "decorative: attachment alt cleared" 200 "$RESP_HTTP" '.[0].attachment | [.from, .to]' '["Old",""]' "$RESP_BODY"
CID="$(echo "$RESP_BODY" | jq -r '.[0].change_id')"
has "decorative: the img keeps alt=\"\"" "$(img_tags "$P1")" 'alt=""'
"${WP[@]}" post meta update "$IMG_ID" _wp_attachment_image_alt "Typed later" >/dev/null
req POST /revert "$EDITOR" "{\"change_id\":$CID}"
check "drift: refused with the current value" 409 "$RESP_HTTP" '[.code, .data.current]' '["ccc_value_changed","Typed later"]' "$RESP_BODY"
notice() { "${WP[@]}" eval "\$_GET['ccc_notice'] = 'changed'; \$_GET['change_id'] = '$1'; require_once WP_PLUGIN_DIR . '/crawl-cove-connector/admin/class-ccc-admin.php'; wp_set_current_user( 1 ); CCC_Admin::render();" 2>/dev/null | tr '\n\t' '  ' | grep -o 'notice-warning.*</form>\|notice-warning[^/]*</p>'; }
N="$(notice "$CID")"
has   "tools notice: the alt text drift is explained" "$N" "alt text has changed since that push and now reads &quot;Typed later&quot;"
has   "tools notice: offers Revert anyway" "$N" "Revert anyway to &quot;Old&quot;"
req POST /revert "$EDITOR" "{\"change_id\":$CID,\"force\":true}"
check "drift: force puts the old alt back" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
same "drift: attachment alt is the original" "Old" "$(alt_meta)"

echo "-- Tools page: empty alt values are said in words (review #304 minor) --"
"${WP[@]}" post meta update "$IMG_ID" _wp_attachment_image_alt "" >/dev/null
req POST /image-alt "$EDITOR" "{\"changes\":[{\"url\":\"$URL/?page_id=$P1\",\"src\":\"$MEDIUM\",\"alt\":\"Pushed onto empty\"}]}"
CID="$(echo "$RESP_BODY" | jq -r '.[0].change_id')"
"${WP[@]}" post meta update "$IMG_ID" _wp_attachment_image_alt "Typed over it" >/dev/null
N="$(notice "$CID")"
has   "tools notice: a push onto an empty alt offers Revert anyway (leaves it empty)" "$N" "Revert anyway (leaves it empty)"
hasnt "tools notice: no quoted dash on the button" "$N" "&quot;—&quot;"
"${WP[@]}" post meta update "$IMG_ID" _wp_attachment_image_alt "" >/dev/null
N="$(notice "$CID")"
has   "tools notice: an alt emptied since the push is said in words" "$N" "alt text has been emptied since that push"
hasnt "tools notice: no quoted dash for the current value" "$N" "&quot;—&quot;"
req POST /revert "$EDITOR" "{\"change_id\":$CID,\"force\":true}"
check "empty: force puts the empty alt back" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
"${WP[@]}" post meta update "$IMG_ID" _wp_attachment_image_alt "Old" >/dev/null

echo "-- Tools page: an edited page cannot be forced --"
req POST /image-alt "$EDITOR" "{\"changes\":[{\"url\":\"$URL/?page_id=$P1\",\"src\":\"$MEDIUM\",\"alt\":\"Blue door\"}]}"
CID="$(echo "$RESP_BODY" | jq -r '.[0].change_id')"
put_content "$P1" "get_post( $P1 )->post_content . '<p>edited</p>'"
N="$(notice "$CID")"
has   "tools notice: says the page was edited" "$N" "the page showing this image has been edited since that push"
hasnt "tools notice: no Revert anyway for an edited page" "$N" "Revert anyway"

echo "-- Tools page: a later alt push to the same page and image supersedes the earlier one --"
req POST /image-alt "$EDITOR" "{\"changes\":[{\"url\":\"$URL/?page_id=$P1\",\"src\":\"$MEDIUM\",\"alt\":\"Green door\"}]}"
G1="$(echo "$RESP_BODY" | jq -r '.[0].change_id')"
req POST /image-alt "$EDITOR" "{\"changes\":[{\"url\":\"$URL/?page_id=$P1\",\"src\":\"$MEDIUM\",\"alt\":\"Yellow door\"}]}"
G2="$(echo "$RESP_BODY" | jq -r '.[0].change_id')"
# Alt rows as "after|action".
ALT_ROWS="$("${WP[@]}" eval 'require_once WP_PLUGIN_DIR . "/crawl-cove-connector/admin/class-ccc-admin.php"; require_once ABSPATH . "wp-admin/includes/template.php"; wp_set_current_user( 1 ); ob_start(); CCC_Admin::render(); $h = ob_get_clean(); foreach ( explode( "</tr>", $h ) as $row ) { if ( preg_match( "#<td>Image alt text</td>\s*<td>[^<]*</td>\s*<td>([^<]*)</td>#", $row, $m ) ) { $act = false !== strpos( $row, "replaced by a later change" ) ? "replaced" : ( false !== strpos( $row, "<em>reverted</em>" ) ? "reverted" : ( false !== strpos( $row, "name=\"change_id\"" ) ? "Revert" : "?" ) ); echo $m[1], "|", $act, "\n"; } }')"
has "tools: the earlier alt push reads replaced by a later change" "$ALT_ROWS" "Green door|replaced"
has "tools: the later alt push keeps its Revert" "$ALT_ROWS" "Yellow door|Revert"
req POST /revert "$EDITOR" "{\"change_id\":$G2}"
check "supersede: the later push reverts" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
req POST /revert "$EDITOR" "{\"change_id\":$G1}"
check "supersede: then the earlier one reverts too" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"

echo "-- an <img> that is only text (in an attribute, a script) is never patched --"
P3="$("${WP[@]}" post create --post_type=page --post_title="Tooltip page" --post_status=publish --post_author="$EDITOR_ID" --porcelain)"
put_content "$P3" "'<div data-tip=\"<img src=\\'$MEDIUM\\'>\">tip</div><script>var s=\"<img src=\\'$MEDIUM\\'>\";</script>'"
P3_BEFORE="$(content "$P3")"
has "fixture: the tooltip page holds both fake tags" "$P3_BEFORE" "data-tip=\"<img src='$MEDIUM'>\""
req POST /image-alt "$EDITOR" "{\"changes\":[{\"url\":\"$URL/?page_id=$P3\",\"src\":\"$MEDIUM\",\"alt\":\"onmouseover=alert(1) x\"}]}"
check "fake tags: no inline change" 200 "$RESP_HTTP" '.[0].inline' '0' "$RESP_BODY"
same "fake tags: page content byte-identical" "$P3_BEFORE" "$(content "$P3")"

summary
