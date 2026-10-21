#!/usr/bin/env bash
#
# A push writes SEO meta and nothing else (21 Oct 2026). Until 0.10.7 every
# real write re-saved the post with wp_update_post() to rebuild Yoast's
# indexable and fire clean_post_cache. That re-runs post_content through
# content_save_pre as the pushing user, and for a user without
# unfiltered_html (any Author, every site admin on multisite) kses deleted
# the iframes, scripts and forms an admin had put in the body. Found by
# hand on this harness: an Author's title push stripped an admin's YouTube
# embed from the Author's own post.
#
# Pins, per adapter: post_content and post_modified are byte-identical after
# an apply and after a revert by the Author; the front end renders the pushed
# title (so the SEO plugin really serves it without a re-save); on Yoast the
# indexable row carries it (its post-meta watcher rebuilds at shutdown).

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"

WP=(php "$CCC_CACHE/wp-cli.phar" --path="$CCC_SITE")

ok()   { PASS=$((PASS+1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL $1"; }
same() { if [[ "$2" == "$3" ]]; then ok "$1"; else fail "$1 — want [$2], got [$3]"; fi; }

AUTHOR_ID="$("${WP[@]}" user get ccc_author --field=ID 2>/dev/null)"
POST_ID="$("${WP[@]}" post create --post_title="Embed post" --post_status=publish --post_author="$AUTHOR_ID" --porcelain 2>/dev/null)"
# An admin's markup that kses would strip for an Author. Written with kses off,
# as an admin (unfiltered_html) saving it in the editor would store it.
"${WP[@]}" eval "kses_remove_filters(); wp_update_post( array( 'ID' => $POST_ID, 'post_content' => '<p>Watch:</p><iframe src=\"https://www.youtube.com/embed/abc\" width=\"560\"></iframe><script>var ccc = 1;</script><form action=\"/subscribe\"><input name=\"email\"></form>' ) );" >/dev/null 2>&1

content()  { "${WP[@]}" post get "$POST_ID" --field=post_content 2>/dev/null; }
modified() { "${WP[@]}" post get "$POST_ID" --field=post_modified_gmt 2>/dev/null; }
CONTENT_BEFORE="$(content)"
MODIFIED_BEFORE="$(modified)"
sleep 1 # a re-save inside the same second would leave post_modified unchanged

echo "-- an Author's push writes SEO meta only, never the post body (adapter=$CCC_ADAPTER) --"
if grep -q '<iframe' <<<"$CONTENT_BEFORE" && grep -q '<script>' <<<"$CONTENT_BEFORE"; then
  ok "fixture: post body holds an iframe and a script before the push"
else
  fail "fixture: post body did not keep the admin markup, so this script tests nothing — got [$CONTENT_BEFORE]"
fi

TITLE="Integrity title $RANDOM"
req POST /apply "$AUTHOR" "{\"changes\":[{\"post_id\":$POST_ID,\"title\":\"$TITLE\"}]}"
check "author: apply title to own embed post is ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
CHANGE_ID="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"
same "apply: post_content unchanged (iframe, script and form kept)" "$CONTENT_BEFORE" "$(content)"
same "apply: post_modified unchanged (no re-save)" "$MODIFIED_BEFORE" "$(modified)"

PAGE="$(curl -s --max-time 10 "$CCC_URL/?p=$POST_ID")"
if grep -o '<title>[^<]*</title>' <<<"$PAGE" | grep -qF "$TITLE"; then
  ok "front end: <title> serves the pushed title without a re-save"
else
  fail "front end: <title> does not carry [$TITLE] — got [$(grep -o '<title>[^<]*</title>' <<<"$PAGE")]"
fi

if [[ "$CCC_ADAPTER" == "yoast" ]]; then
  INDEXABLE_TITLE="$("${WP[@]}" eval "global \$wpdb; echo (string) \$wpdb->get_var( \$wpdb->prepare( \"SELECT title FROM {\$wpdb->prefix}yoast_indexable WHERE object_type = 'post' AND object_id = %d\", $POST_ID ) );" 2>/dev/null)"
  same "yoast: indexable row rebuilt with the pushed title (post-meta watcher)" "$TITLE" "$INDEXABLE_TITLE"
fi

req POST /revert "$AUTHOR" "{\"change_id\":$CHANGE_ID}"
check "author: revert own change is ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
same "revert: post_content unchanged" "$CONTENT_BEFORE" "$(content)"
same "revert: post_modified unchanged" "$MODIFIED_BEFORE" "$(modified)"

if [[ "$CCC_ADAPTER" == "yoast" ]]; then
  INDEXABLE_TITLE="$("${WP[@]}" eval "global \$wpdb; echo (string) \$wpdb->get_var( \$wpdb->prepare( \"SELECT title FROM {\$wpdb->prefix}yoast_indexable WHERE object_type = 'post' AND object_id = %d\", $POST_ID ) );" 2>/dev/null)"
  same "yoast: indexable title cleared again after revert" "" "$INDEXABLE_TITLE"
fi

summary
