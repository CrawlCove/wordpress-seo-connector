#!/usr/bin/env bash
#
# Homepage ("your latest posts" mode) REST checks. run.sh's shared site setup
# configures show_on_front=page (a static front page) for checks.sh's route
# coverage — that mode already worked with zero adapter changes (a static
# front page is just a normal page, per the note in checks.sh). This script
# temporarily switches to "your latest posts" mode, WordPress's other
# built-in Settings -> Reading option, to exercise the HOME_ID=0 target: only
# there does CCC read/write Yoast's wpseo_titles option or Rank Math's
# rank-math-options-titles option instead of postmeta — neither of which unit
# stubs can see. Restores show_on_front after.
#
# Run by run.sh for every adapter: yoast/rankmath/seopress must resolve,
# apply, revert and round-trip through the real plugin's own option storage;
# aioseo (its "homepage" title/description is the SAME global template used
# site-wide, not a dedicated field — see CCC_Adapter::supports_home()) must
# fail cleanly with ccc_home_unsupported instead of silently doing nothing or,
# worse, silently rewriting the site-wide template. Also proves the
# manage_options capability gate (not edit_post — there is no post to check
# it against) and Rank Math's one real footgun found while researching this:
# writing '' to its homepage title leaves a genuinely blank <title> (no
# template fallback, unlike Yoast/SEOPress), so CCC refuses that specific
# write.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"

: "${CCC_SITE:?}" "${CCC_CACHE:?}"
WPCLI=(php "$CCC_CACHE/wp-cli.phar")

restore_static_front_page() {
  "${WPCLI[@]}" option update show_on_front page --path="$CCC_SITE" --quiet
}
trap restore_static_front_page EXIT

"${WPCLI[@]}" option update show_on_front posts --path="$CCC_SITE" --quiet

echo "-- homepage (your latest posts): resolve --"
req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/\"]}"
check "resolve: site root is the homepage target (post_id 0)" 200 "$RESP_HTTP" '.[0].post_id' '0' "$RESP_BODY"

req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/?p=$CCC_POST_EDITOR\"]}"
check "resolve: a real post at a query-string URL is still itself, not the homepage" 200 "$RESP_HTTP" '.[0].post_id' "$CCC_POST_EDITOR" "$RESP_BODY"

if [[ "$CCC_ADAPTER" == "yoast" || "$CCC_ADAPTER" == "rankmath" || "$CCC_ADAPTER" == "seopress" ]]; then
  echo "-- homepage: capability is manage_options, not edit_post --"
  # supports_home() is checked before the capability gate (apply()'s own
  # precedence: "this feature doesn't exist" beats "you may not use it"), so
  # this check only makes sense for an adapter that supports the homepage at
  # all — aioseo always reports ccc_home_unsupported here instead.
  req POST /apply "$AUTHOR" "{\"changes\":[{\"post_id\":0,\"title\":\"Should Be Blocked\"}]}"
  check "apply: author (no manage_options) forbidden on the homepage" 200 "$RESP_HTTP" '.[0].error' 'ccc_forbidden' "$RESP_BODY"
fi

"${WPCLI[@]}" user add-cap ccc_editor manage_options --path="$CCC_SITE" --quiet

if [[ "$CCC_ADAPTER" == "yoast" || "$CCC_ADAPTER" == "rankmath" || "$CCC_ADAPTER" == "seopress" ]]; then
  echo "-- homepage: apply + real plugin storage round-trip ($CCC_ADAPTER) --"
  req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":0,\"title\":\"Homepage Title From CCC\",\"description\":\"Homepage Desc From CCC\"}]}"
  check "apply: homepage write ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
  CHANGE_ID_HOME_TITLE="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"

  if [[ "$CCC_ADAPTER" == "yoast" ]]; then
    STORED="$("${WPCLI[@]}" option get wpseo_titles --format=json --path="$CCC_SITE" | jq -r '."title-home-wpseo"')"
  elif [[ "$CCC_ADAPTER" == "rankmath" ]]; then
    STORED="$("${WPCLI[@]}" option get rank-math-options-titles --format=json --path="$CCC_SITE" | jq -r '.homepage_title')"
  else
    STORED="$("${WPCLI[@]}" option get seopress_titles_option_name --format=json --path="$CCC_SITE" | jq -r '.seopress_titles_home_site_title')"
  fi
  if [[ "$STORED" == "Homepage Title From CCC" ]]; then
    PASS=$((PASS+1)); echo "  ok   apply: homepage title actually persisted in the SEO plugin's own option (not just CCC's own read-back)"
  else
    FAIL=$((FAIL+1)); echo "  FAIL apply: homepage title not found in the SEO plugin's own option (got: $STORED)"
  fi

  req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/\"]}"
  check "resolve: homepage title round-trips" 200 "$RESP_HTTP" '.[0].current.title' 'Homepage Title From CCC' "$RESP_BODY"
  check "resolve: homepage description round-trips" 200 "$RESP_HTTP" '.[0].current.description' 'Homepage Desc From CCC' "$RESP_BODY"

  HOME_PAGE="$(curl -sL --max-time 10 "$CCC_URL/")"
  if grep -o '<title>[^<]*</title>' <<<"$HOME_PAGE" | grep -qF 'Homepage Title From CCC'; then
    PASS=$((PASS+1)); echo "  ok   front end: the homepage <title> serves the pushed title"
  else
    FAIL=$((FAIL+1)); echo "  FAIL front end: homepage <title> is [$(grep -o '<title>[^<]*</title>' <<<"$HOME_PAGE")], not the pushed title"
  fi

  echo "-- homepage: revert --"
  req POST /revert "$EDITOR" "{\"change_id\":$CHANGE_ID_HOME_TITLE}"
  check "revert: homepage change ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
fi

if [[ "$CCC_ADAPTER" == "rankmath" ]]; then
  echo "-- homepage: rank math clear-title guard --"
  req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":0,\"title\":\"\"}]}"
  check "apply: clearing rank math home title is blocked (no template fallback)" 200 "$RESP_HTTP" '.[0].applied.title.error' 'ccc_home_title_clear_unsupported' "$RESP_BODY"

  req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":0,\"description\":\"\"}]}"
  check "apply: clearing rank math home description is allowed (falls back to the tagline)" 200 "$RESP_HTTP" '.[0].applied.description.changed' 'true' "$RESP_BODY"
fi

if [[ "$CCC_ADAPTER" == "aioseo" ]]; then
  echo "-- homepage: unsupported adapter fails cleanly ($CCC_ADAPTER) --"
  req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":0,\"title\":\"Should Not Write\"}]}"
  check "apply: homepage unsupported for $CCC_ADAPTER" 200 "$RESP_HTTP" '.[0].error' 'ccc_home_unsupported' "$RESP_BODY"
fi

echo "-- homepage: a static front page rejects post_id 0 --"
restore_static_front_page
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":0,\"title\":\"X\"}]}"
check "apply: post_id 0 rejected once a static front page is set" 200 "$RESP_HTTP" '.[0].error' 'ccc_no_homepage_target' "$RESP_BODY"

summary
