#!/usr/bin/env bash
#
# REST route assertions run by run.sh against a live WordPress. Reads its
# fixture data (URL, users, post ids) from environment variables run.sh sets.
# Every check increments PASS/FAIL and prints one line; a nonzero exit means
# at least one assertion failed.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"

echo "-- status --"
req GET /status ""
check "status: unauthenticated is 401" 401 "$RESP_HTTP" '.code' 'rest_forbidden' "$RESP_BODY"

req GET /status "$EDITOR"
check "status: authenticated reports adapter" 200 "$RESP_HTTP" '.seo_plugin' "$CCC_ADAPTER" "$RESP_BODY"
check "status: can_apply true" 200 "$RESP_HTTP" '.can_apply' 'true' "$RESP_BODY"

echo "-- resolve --"
EDITOR_URL="$CCC_URL/?p=$CCC_POST_EDITOR"
req POST /resolve "$EDITOR" "{\"urls\":[\"$EDITOR_URL\"]}"
check "resolve: valid url ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
check "resolve: valid url post_id" 200 "$RESP_HTTP" '.[0].post_id' "$CCC_POST_EDITOR" "$RESP_BODY"

req POST /resolve "$EDITOR" '{"urls":["https://evil.example.com/?p=1"]}'
check "resolve: wrong site rejected" 200 "$RESP_HTTP" '.[0].error' 'ccc_wrong_site' "$RESP_BODY"

req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/?p=999999\"]}"
check "resolve: phantom numeric id (url_to_postid quirk) is unresolvable, not a fake ok" 200 "$RESP_HTTP" '.[0].error' 'ccc_unresolvable' "$RESP_BODY"

req POST /resolve "$SUBSCRIBER" "{\"urls\":[\"$EDITOR_URL\"]}"
check "resolve: subscriber (no edit_posts) forbidden" 403 "$RESP_HTTP" '.code' 'rest_forbidden' "$RESP_BODY"

echo "-- apply --"
req POST /apply "$EDITOR" "{\"dry_run\":true,\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"Dry Run Title\"}]}"
check "apply: dry_run reports changed, no change_id" 200 "$RESP_HTTP" '.[0].applied.title | .changed and (has("change_id")|not)' 'true' "$RESP_BODY"

req GET /changes "$EDITOR"
DRY_LOGGED="$(echo "$RESP_BODY" | jq -r '[.[] | select(.to == "Dry Run Title")] | length')"
if [[ "$DRY_LOGGED" == "0" ]]; then
  PASS=$((PASS+1)); echo "  ok   apply: dry_run wrote nothing to the change log"
else
  FAIL=$((FAIL+1)); echo "  FAIL apply: dry_run leaked into the change log ($DRY_LOGGED entries)"
fi

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"Real Title\",\"description\":\"Real Desc\"}]}"
check "apply: real write ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
CHANGE_ID_TITLE="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"

req POST /apply "$AUTHOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"Steal\"}]}"
check "apply: author cannot edit editor's post" 200 "$RESP_HTTP" '.[0].error' 'ccc_forbidden' "$RESP_BODY"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_AUTHOR,\"title\":\"Editor can edit others (WP core: edit_others_posts)\"}]}"
check "apply: editor CAN edit author's post (WP core capability)" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"

BIG_ITEMS="$(python3 -c "import json; print(json.dumps([{'post_id':$CCC_POST_EDITOR,'title':'x'} for _ in range(51)]))")"
req POST /apply "$EDITOR" "{\"changes\":$BIG_ITEMS}"
check "apply: batch >50 rejected" 400 "$RESP_HTTP" '.code' 'ccc_batch_too_big' "$RESP_BODY"

LONG_TITLE="$(python3 -c "print('x'*513)")"
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"$LONG_TITLE\"}]}"
check "apply: title over 512 chars rejected" 200 "$RESP_HTTP" '.[0].error' 'ccc_too_long' "$RESP_BODY"

req POST /apply "$EDITOR" '{"changes":[]}'
check "apply: empty batch rejected" 400 "$RESP_HTTP" '.code' 'ccc_empty_batch' "$RESP_BODY"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"日本語 — émoji 🚀\"}]}"
check "apply: unicode written ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
req POST /resolve "$EDITOR" "{\"urls\":[\"$EDITOR_URL\"]}"
check "apply: unicode round-trips exactly" 200 "$RESP_HTTP" '.[0].current.title' '日本語 — émoji 🚀' "$RESP_BODY"

echo "-- changes / revert --"
req GET /changes "$EDITOR"
check "changes: newest first" 200 "$RESP_HTTP" '.[0].to' '日本語 — émoji 🚀' "$RESP_BODY"

req POST /revert "$EDITOR" "{\"change_id\":$CHANGE_ID_TITLE}"
check "revert: valid change ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"

req POST /revert "$EDITOR" "{\"change_id\":$CHANGE_ID_TITLE}"
check "revert: repeat is 409 already-reverted" 409 "$RESP_HTTP" '.code' 'ccc_already_reverted' "$RESP_BODY"

req POST /revert "" "{\"change_id\":$CHANGE_ID_TITLE}"
check "revert: unauthenticated is 401" 401 "$RESP_HTTP" '.code' 'rest_forbidden' "$RESP_BODY"

echo "-- static front page (site root) --"
# run.sh configures show_on_front=page/page_on_front=$CCC_POST_HOME. Both
# Yoast and Rank Math render that page's own title/description postmeta for
# "/" in this (common) setup, so resolving and applying to the root URL
# should work through the exact same code path as any other page.
req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/\"]}"
check "resolve: site root resolves to the static front page" 200 "$RESP_HTTP" '.[0].post_id' "$CCC_POST_HOME" "$RESP_BODY"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_HOME,\"title\":\"Homepage Title\",\"description\":\"Homepage Description\"}]}"
check "apply: write to the front page ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"

req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/\"]}"
check "resolve: front page title round-trips" 200 "$RESP_HTTP" '.[0].current.title' 'Homepage Title' "$RESP_BODY"
check "resolve: front page description round-trips" 200 "$RESP_HTTP" '.[0].current.description' 'Homepage Description' "$RESP_BODY"

echo "-- admin page --"
# Tools -> Crawl Cove must give someone who found the plugin first a way to the
# desktop app, and that link must keep its utm_* query parameters through
# esc_url() (which rewrites & as &#038; — a browser decodes that back, so the
# check looks for each parameter on its own rather than the literal URL).
# wp-cli is not is_admin(), so the admin class is required explicitly.
ADMIN_HTML="$(php "$CCC_CACHE/wp-cli.phar" eval 'require_once WP_PLUGIN_DIR . "/crawl-cove-connector/admin/class-ccc-admin.php"; wp_set_current_user( 1 ); CCC_Admin::render();' --path="$CCC_SITE" 2>/dev/null)"
# Substring tests, not `echo | grep -q`: under `set -o pipefail` grep -q
# exits on the first match, echo takes SIGPIPE, and the pipeline reads as
# failed — a timing flake that passed on one adapter and failed on three.
if [[ "$ADMIN_HTML" == *'href="https://crawlcove.com/download?utm_source=wordpress-plugin'* \
   && "$ADMIN_HTML" == *'utm_medium=referral'* \
   && "$ADMIN_HTML" == *'utm_campaign=connector-admin"'* ]]; then
  PASS=$((PASS+1)); echo "  ok   admin: setup step links the desktop app download with attribution intact"
else
  FAIL=$((FAIL+1)); echo "  FAIL admin: desktop app link missing or its utm parameters were mangled"; echo "$ADMIN_HTML" | grep -o '<li>.*crawlcove.com[^<]*' | head -3
fi

echo "-- template variables (review #233) --"
# Core sanitize_text_field() strips "%" + two hex digits, which ate "%ca" and
# "%da" out of %category% / %%date%%. Each adapter must store its own native
# variable syntax verbatim. Only Rank Math and Yoast protect variables in
# their own savers; SEOPress (register_post_meta sanitize_callback) and
# AIOSEO (Post::savePost) run core sanitize_text_field themselves, so their
# native variables are the ones that survive their own editors too.
case "$CCC_ADAPTER" in
  rankmath) VARS_TITLE='%title% %sep% %category% %sep% %date%'
            VARS_DESC='%excerpt% Filed under %category% on %date%' ;;
  yoast)    VARS_TITLE='%%title%% %%sep%% %%category%% %%sep%% %%date%%'
            VARS_DESC='%%excerpt%% Filed under %%category%% on %%date%%' ;;
  seopress) VARS_TITLE='%%post_title%% %%sep%% %%_category_title%% %%sep%% %%sitetitle%%'
            VARS_DESC='%%post_excerpt%% Filed under %%_category_title%% on %%post_date%%' ;;
  aioseo)   VARS_TITLE='#post_title #separator_sa #categories #separator_sa #post_date'
            VARS_DESC='#post_excerpt Filed under #categories on #post_date' ;;
esac
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_AUTHOR,\"title\":\"$VARS_TITLE\",\"description\":\"$VARS_DESC\"}]}"
check "apply: template-variable values written ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/?p=$CCC_POST_AUTHOR\"]}"
check "apply: $CCC_ADAPTER template variables in the title stored verbatim" 200 "$RESP_HTTP" '.[0].current.title' "$VARS_TITLE" "$RESP_BODY"
check "apply: $CCC_ADAPTER template variables in the description stored verbatim" 200 "$RESP_HTTP" '.[0].current.description' "$VARS_DESC" "$RESP_BODY"

# Pagination: 100 rows a page, page 2 reachable, revert forms carry the page.
# Seeds enough entries to need two pages (the log already holds this run's
# earlier applies, so the total is asserted by shape, not by number).
php "$CCC_CACHE/wp-cli.phar" eval "for (\$i = 0; \$i < 101; \$i++) { CCC_Change_Log::record( $CCC_POST_EDITOR, 'title', 'seed-old-' . \$i, 'seed-new-' . \$i, 'harness' ); }" --path="$CCC_SITE" >/dev/null 2>&1
PAGE1="$(php "$CCC_CACHE/wp-cli.phar" eval 'require_once WP_PLUGIN_DIR . "/crawl-cove-connector/admin/class-ccc-admin.php"; wp_set_current_user( 1 ); CCC_Admin::render();' --path="$CCC_SITE" 2>/dev/null)"
PAGE2="$(php "$CCC_CACHE/wp-cli.phar" eval '$_GET["paged"] = "2"; require_once WP_PLUGIN_DIR . "/crawl-cove-connector/admin/class-ccc-admin.php"; wp_set_current_user( 1 ); CCC_Admin::render();' --path="$CCC_SITE" 2>/dev/null)"
P1_FORMS="$(echo "$PAGE1" | grep -c 'name="change_id"')"
P2_FORMS="$(echo "$PAGE2" | grep -c 'name="change_id"')"
if [[ "$PAGE1" == *'Showing changes 1 to 100 of'* && "$P1_FORMS" == "100" ]]; then
  PASS=$((PASS+1)); echo "  ok   admin: change log page 1 shows exactly 100 rows with the range line"
else
  FAIL=$((FAIL+1)); echo "  FAIL admin: page 1 expected 100 rows + range line, got $P1_FORMS rows"; echo "$PAGE1" | grep -o "Showing changes[^<]*" | head -2
fi
if [[ "$PAGE2" == *'Showing changes 101 to'* && "$P2_FORMS" -ge 1 && "$P2_FORMS" -le 100 && "$PAGE2" == *'name="paged" value="2"'* ]]; then
  PASS=$((PASS+1)); echo "  ok   admin: page 2 shows the remainder and its revert forms carry paged=2"
else
  FAIL=$((FAIL+1)); echo "  FAIL admin: page 2 wrong (rows=$P2_FORMS)"; echo "$PAGE2" | grep -o 'Showing changes[^<]*' | head -2
fi

summary
