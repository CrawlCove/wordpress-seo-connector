#!/usr/bin/env bash
#
# /apply's 0.11 fields (canonical, robots_index, og_*, twitter_*) on the front
# end, per adapter: a push must change the tags the SEO plugin actually
# serves, and a revert must put back what it served before. Unit stubs only
# see the meta keys; this proves each plugin reads them (and that the source
# switches, such as Rank Math's twitter_use_facebook or AIOSEO's
# og_image_type, are flipped). Run by run.sh.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"

WP=(php "$CCC_CACHE/wp-cli.phar" --path="$CCC_SITE")
ok()    { PASS=$((PASS+1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL+1)); echo "  FAIL $1"; }
has()   { if grep -qF -- "$3" <<<"$2"; then ok "$1"; else fail "$1 — [$3] missing"; fi; }
hasnt() { if grep -qF -- "$3" <<<"$2"; then fail "$1 — [$3] present"; else ok "$1"; fi; }
head_of() { curl -sL --max-time 15 "$CCC_URL/?p=$1" | tr "'" '"' | grep -oE '<(meta|link) [^>]*>' ; }

EDITOR_ID="$("${WP[@]}" user get ccc_editor --field=ID 2>/dev/null)"
POST_ID="$("${WP[@]}" post create --post_title="Social post" --post_content="Body." --post_status=publish --post_author="$EDITOR_ID" --porcelain 2>/dev/null)"
CANON="$CCC_URL/canonical-target/"
php -r '$i = imagecreatetruecolor( 1200, 630 ); imagejpeg( $i, "/tmp/ccc-og-test.jpg" );'
IMG_ID="$("${WP[@]}" media import /tmp/ccc-og-test.jpg --porcelain 2>/dev/null)"
rm -f /tmp/ccc-og-test.jpg
IMG="$("${WP[@]}" eval "echo wp_get_attachment_url( $IMG_ID );" 2>/dev/null)"
R=$RANDOM

echo "-- extra fields on the front end (adapter=$CCC_ADAPTER) --"
BEFORE="$(head_of "$POST_ID")"
hasnt "before: canonical is not the target" "$BEFORE" "href=\"$CANON\""
hasnt "before: not noindex" "$(grep -i 'name="robots"' <<<"$BEFORE")" "noindex"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$POST_ID,\"canonical\":\"$CANON\",\"og_title\":\"OG title $R\",\"og_description\":\"OG desc $R\",\"og_image\":\"$IMG\",\"twitter_title\":\"Tw title $R\",\"twitter_description\":\"Tw desc $R\"}]}"
check "apply: canonical and five social fields changed" 200 "$RESP_HTTP" '[.[0].applied[] | .changed] | [length, all]' '[6,true]' "$RESP_BODY"
IDS="$(echo "$RESP_BODY" | jq -r '[.[0].applied[] | .change_id] | join(" ")')"
AFTER="$(head_of "$POST_ID")"
has "front end: canonical" "$AFTER" "<link rel=\"canonical\" href=\"$CANON\""
has "front end: og:title" "$AFTER" "content=\"OG title $R\""
has "front end: og:description" "$AFTER" "content=\"OG desc $R\""
has "front end: og:image" "$AFTER" "content=\"$IMG\""
has "front end: twitter:title" "$(grep -i 'twitter:title' <<<"$AFTER")" "Tw title $R"
has "front end: twitter:description" "$(grep -i 'twitter:description' <<<"$AFTER")" "Tw desc $R"

# Separate push: every plugin drops the canonical tag on a noindex page.
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$POST_ID,\"robots_index\":false}]}"
check "apply: robots_index false is noindex" 200 "$RESP_HTTP" '.[0].applied.robots_index | [.from, .to]' '["","noindex"]' "$RESP_BODY"
IDS="$(echo "$RESP_BODY" | jq -r '.[0].applied.robots_index.change_id') $IDS"
has "front end: robots noindex" "$(head_of "$POST_ID" | grep -i 'name="robots"')" "noindex"

for id in $IDS; do
  req POST /revert "$EDITOR" "{\"change_id\":$id}"
  [[ "$RESP_HTTP" == 200 ]] || fail "revert $id — http $RESP_HTTP $RESP_BODY"
done
REVERTED="$(head_of "$POST_ID")"
hasnt "revert: canonical target gone" "$REVERTED" "href=\"$CANON\""
hasnt "revert: noindex gone" "$(grep -i 'name="robots"' <<<"$REVERTED")" "noindex"
hasnt "revert: og:title gone" "$REVERTED" "OG title $R"
hasnt "revert: twitter:title gone" "$REVERTED" "Tw title $R"
if [[ "$(grep -iE 'canonical|robots|og:|twitter:' <<<"$BEFORE" | sort)" == "$(grep -iE 'canonical|robots|og:|twitter:' <<<"$REVERTED" | sort)" ]]; then
  ok "revert: canonical, robots, og and twitter tags are exactly what they were"
else
  fail "revert: tags differ from before — $(diff <(grep -iE 'canonical|robots|og:|twitter:' <<<"$BEFORE" | sort) <(grep -iE 'canonical|robots|og:|twitter:' <<<"$REVERTED" | sort) | head -6 | tr '\n' ' ')"
fi

summary
