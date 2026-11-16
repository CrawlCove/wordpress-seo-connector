#!/usr/bin/env bash
#
# Taxonomy term (category/tag archive) REST checks — the negative post_id
# sentinel (post_id = -$term_id). Unit stubs cannot see any of this: real
# rewrite-rule matching (CrawlCove_Term_Resolver, adapted from WordPress core's own
# url_to_postid()), Yoast's wpseo_taxonomy_meta option shape, and Rank Math's
# real term meta all need a live WordPress.
#
# Two permalink structures are exercised because CrawlCove_Term_Resolver has two
# separate code paths: "Plain" permalinks (WP core's own default — this
# harness never sets a structure) resolve via query-string vars (?cat=N,
# ?category_name=slug, ?tag=slug); pretty permalinks resolve via
# $wp_rewrite's compiled rules. Restores whatever structure was active after.
#
# Run by run.sh for every adapter: yoast/rankmath/seopress must resolve,
# apply, revert and round-trip through the real plugin's own term storage;
# aioseo (its free/Lite tier has no term SEO storage at all, confirmed
# against its own source — see CrawlCove_Adapter::supports_term()) must fail
# cleanly with ccc_term_unsupported.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"

: "${CCC_SITE:?}" "${CCC_CACHE:?}"
WPCLI=(php "$CCC_CACHE/wp-cli.phar")

ORIGINAL_STRUCTURE="$("${WPCLI[@]}" option get permalink_structure --path="$CCC_SITE" 2>/dev/null || true)"
restore_permalinks() {
  "${WPCLI[@]}" rewrite structure "$ORIGINAL_STRUCTURE" --path="$CCC_SITE" --quiet 2>/dev/null || true
}
trap restore_permalinks EXIT

NEWS_TERM_ID="$("${WPCLI[@]}" term create category News --slug=news --porcelain --path="$CCC_SITE")"
TAG_TERM_ID="$("${WPCLI[@]}" term create post_tag Breaking --slug=breaking --porcelain --path="$CCC_SITE")"

echo "-- taxonomy terms: plain-permalink resolution (?cat=N, ?category_name=, ?tag=) --"
req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/?cat=$NEWS_TERM_ID\"]}"
check "resolve: ?cat=N maps to the term (post_id -$NEWS_TERM_ID)" 200 "$RESP_HTTP" '.[0].post_id' "-$NEWS_TERM_ID" "$RESP_BODY"

req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/?category_name=news\"]}"
check "resolve: ?category_name=slug maps to the term" 200 "$RESP_HTTP" '.[0].post_id' "-$NEWS_TERM_ID" "$RESP_BODY"

req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/?tag=breaking\"]}"
check "resolve: ?tag=slug maps to the term" 200 "$RESP_HTTP" '.[0].post_id' "-$TAG_TERM_ID" "$RESP_BODY"

echo "-- taxonomy terms: pretty-permalink resolution (/category/news/, /tag/breaking/) --"
"${WPCLI[@]}" rewrite structure '/%postname%/' --path="$CCC_SITE" --quiet
"${WPCLI[@]}" rewrite flush --path="$CCC_SITE" --quiet

req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/category/news/\"]}"
check "resolve: pretty category archive maps to the term" 200 "$RESP_HTTP" '.[0].post_id' "-$NEWS_TERM_ID" "$RESP_BODY"

req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/tag/breaking/\"]}"
check "resolve: pretty tag archive maps to the term" 200 "$RESP_HTTP" '.[0].post_id' "-$TAG_TERM_ID" "$RESP_BODY"

req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/category/does-not-exist/\"]}"
check "resolve: a term slug that doesn't exist is unresolvable, not a phantom match" 200 "$RESP_HTTP" '.[0].error' 'ccc_unresolvable' "$RESP_BODY"

req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/?p=$CCC_POST_EDITOR\"]}"
check "resolve: an ordinary post URL is still itself, never mistaken for a term" 200 "$RESP_HTTP" '.[0].post_id' "$CCC_POST_EDITOR" "$RESP_BODY"

# The crawler sends what it crawled, i.e. the PRETTY post URL on a pretty-
# permalink site (/editor-post/, not ?p=N). url_to_postid() handles that in
# real WP only; the stubs cannot see it. Ask WP for the permalink rather
# than guessing the slug, so this stays true if the fixture title changes.
PRETTY_POST="$("${WPCLI[@]}" post url "$CCC_POST_EDITOR" --path="$CCC_SITE")"
if [[ "$PRETTY_POST" == "$CCC_URL/"*"/" && "$PRETTY_POST" != *"?p="* ]]; then
  PASS=$((PASS+1)); echo "  ok   fixture post permalink is pretty: $PRETTY_POST"
else
  FAIL=$((FAIL+1)); echo "  FAIL fixture post permalink is not pretty: '$PRETTY_POST'"
fi
req POST /resolve "$EDITOR" "{\"urls\":[\"$PRETTY_POST\"]}"
check "resolve: the pretty post URL maps to the post" 200 "$RESP_HTTP" '.[0].post_id' "$CCC_POST_EDITOR" "$RESP_BODY"
PRETTY_PAGE="$("${WPCLI[@]}" post url "$CCC_POST_HOME" --path="$CCC_SITE")"
req POST /resolve "$EDITOR" "{\"urls\":[\"$PRETTY_PAGE\"]}"
check "resolve: the static front page's own pretty URL maps to that page" 200 "$RESP_HTTP" '.[0].post_id' "$CCC_POST_HOME" "$RESP_BODY"

if [[ "$CCC_ADAPTER" == "yoast" || "$CCC_ADAPTER" == "rankmath" || "$CCC_ADAPTER" == "seopress" ]]; then
  echo "-- taxonomy terms: capability is edit_term (manage_categories), author blocked --"
  # supports_term() is checked before the capability gate (apply()'s own
  # precedence: "this feature doesn't exist" beats "you may not use it"), so
  # this check only makes sense for an adapter that supports terms at all —
  # Editor role has manage_categories by default; Author does not.
  req POST /apply "$AUTHOR" "{\"changes\":[{\"post_id\":-$NEWS_TERM_ID,\"title\":\"Should Be Blocked\"}]}"
  check "apply: author (no manage_categories) forbidden on a term" 200 "$RESP_HTTP" '.[0].error' 'ccc_forbidden' "$RESP_BODY"
fi

if [[ "$CCC_ADAPTER" == "yoast" || "$CCC_ADAPTER" == "rankmath" || "$CCC_ADAPTER" == "seopress" ]]; then
  echo "-- taxonomy terms: apply + real plugin storage round-trip ($CCC_ADAPTER) --"
  req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":-$NEWS_TERM_ID,\"title\":\"News Title From CCC\",\"description\":\"News Desc From CCC\"}]}"
  check "apply: term write ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
  CHANGE_ID_TERM_TITLE="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"

  if [[ "$CCC_ADAPTER" == "yoast" ]]; then
    STORED="$("${WPCLI[@]}" option get wpseo_taxonomy_meta --format=json --path="$CCC_SITE" | jq -r ".category[\"$NEWS_TERM_ID\"].wpseo_title")"
  elif [[ "$CCC_ADAPTER" == "rankmath" ]]; then
    STORED="$("${WPCLI[@]}" term meta get "$NEWS_TERM_ID" rank_math_title --path="$CCC_SITE")"
  else
    STORED="$("${WPCLI[@]}" term meta get "$NEWS_TERM_ID" _seopress_titles_title --path="$CCC_SITE")"
  fi
  if [[ "$STORED" == "News Title From CCC" ]]; then
    PASS=$((PASS+1)); echo "  ok   apply: term title actually persisted in the SEO plugin's own storage (not just CCC's own read-back)"
  else
    FAIL=$((FAIL+1)); echo "  FAIL apply: term title not found in the SEO plugin's own storage (got: $STORED)"
  fi

  req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/category/news/\"]}"
  check "resolve: term title round-trips" 200 "$RESP_HTTP" '.[0].current.title' 'News Title From CCC' "$RESP_BODY"
  check "resolve: term description round-trips" 200 "$RESP_HTTP" '.[0].current.description' 'News Desc From CCC' "$RESP_BODY"

  echo "-- taxonomy terms: revert --"
  req POST /revert "$EDITOR" "{\"change_id\":$CHANGE_ID_TERM_TITLE}"
  check "revert: term change ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
  req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/category/news/\"]}"
  check "revert: term title restored to its pre-CCC value ('')" 200 "$RESP_HTTP" '.[0].current.title' '' "$RESP_BODY"

  echo "-- taxonomy terms: clearing the title falls back safely (unlike Rank Math's homepage title) --"
  req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":-$NEWS_TERM_ID,\"title\":\"Something to clear\"}]}"
  req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":-$NEWS_TERM_ID,\"title\":\"\"}]}"
  check "apply: clearing a term title is allowed for $CCC_ADAPTER (per-taxonomy template fallback exists)" 200 "$RESP_HTTP" '.[0].applied.title.changed' 'true' "$RESP_BODY"
fi

if [[ "$CCC_ADAPTER" == "aioseo" ]]; then
  echo "-- taxonomy terms: unsupported adapter fails cleanly ($CCC_ADAPTER) --"
  req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":-$NEWS_TERM_ID,\"title\":\"Should Not Write\"}]}"
  check "apply: term unsupported for $CCC_ADAPTER" 200 "$RESP_HTTP" '.[0].error' 'ccc_term_unsupported' "$RESP_BODY"
fi

echo "-- taxonomy terms: a nonexistent term id is rejected, not silently coerced --"
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":-999999,\"title\":\"X\"}]}"
check "apply: no such term" 200 "$RESP_HTTP" '.[0].error' 'ccc_no_term' "$RESP_BODY"

restore_permalinks

summary
