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
check "status: capabilities with an SEO plugin" 200 "$RESP_HTTP" '.capabilities' '["title","description","canonical","robots","social","replace_urls","site_settings","image_alt"]' "$RESP_BODY"

echo "-- resolve --"
EDITOR_URL="$CCC_URL/?p=$CCC_POST_EDITOR"
req POST /resolve "$EDITOR" "{\"urls\":[\"$EDITOR_URL\"]}"
check "resolve: valid url ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
check "resolve: valid url post_id" 200 "$RESP_HTTP" '.[0].post_id' "$CCC_POST_EDITOR" "$RESP_BODY"

req POST /resolve "$EDITOR" '{"urls":["https://evil.example.com/?p=1"]}'
check "resolve: wrong site rejected" 200 "$RESP_HTTP" '.[0].error' 'ccc_wrong_site' "$RESP_BODY"
check "resolve: wrong site names the app's Connections fix (#277)" 200 "$RESP_HTTP" '.[0].message' "URL is not on this site (127.0.0.1). Check the client's site URL in Crawl Cove." "$RESP_BODY"

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
CHANGE_ID_UNICODE="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"
req POST /resolve "$EDITOR" "{\"urls\":[\"$EDITOR_URL\"]}"
check "apply: unicode round-trips exactly" 200 "$RESP_HTTP" '.[0].current.title' '日本語 — émoji 🚀' "$RESP_BODY"

echo "-- changes / revert --"
req GET /changes "$EDITOR"
check "changes: newest first" 200 "$RESP_HTTP" '.[0].to' '日本語 — émoji 🚀' "$RESP_BODY"

# Review #248: "Real Title" has since been overwritten by the unicode push,
# so reverting it must refuse and write nothing until the newer one is undone.
req POST /revert "$EDITOR" "{\"change_id\":$CHANGE_ID_TITLE}"
check "revert: older push under a newer one is 409 value-changed" 409 "$RESP_HTTP" '.code' 'ccc_value_changed' "$RESP_BODY"
check "revert: value-changed reports the current value" 409 "$RESP_HTTP" '.data.current' '日本語 — émoji 🚀' "$RESP_BODY"
req POST /resolve "$EDITOR" "{\"urls\":[\"$EDITOR_URL\"]}"
check "revert: refused revert wrote nothing" 200 "$RESP_HTTP" '.[0].current.title' '日本語 — émoji 🚀' "$RESP_BODY"

req POST /revert "$EDITOR" "{\"change_id\":$CHANGE_ID_UNICODE}"
check "revert: newest push ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
req POST /resolve "$EDITOR" "{\"urls\":[\"$EDITOR_URL\"]}"
check "revert: newest push restores the push before it" 200 "$RESP_HTTP" '.[0].current.title' 'Real Title' "$RESP_BODY"

req POST /revert "$EDITOR" "{\"change_id\":$CHANGE_ID_TITLE}"
check "revert: valid change ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"

req POST /revert "$EDITOR" "{\"change_id\":$CHANGE_ID_TITLE}"
check "revert: repeat is 409 already-reverted" 409 "$RESP_HTTP" '.code' 'ccc_already_reverted' "$RESP_BODY"

req POST /revert "" "{\"change_id\":$CHANGE_ID_TITLE}"
check "revert: unauthenticated is 401" 401 "$RESP_HTTP" '.code' 'rest_forbidden' "$RESP_BODY"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"Force A\"}]}"
CHANGE_ID_FORCE_A="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"
FORCE_A_FROM="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.from')"
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"Force B\"}]}"
req POST /revert "$EDITOR" "{\"change_id\":$CHANGE_ID_FORCE_A,\"force\":true}"
check "revert: force overrides value-changed" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
req POST /resolve "$EDITOR" "{\"urls\":[\"$EDITOR_URL\"]}"
check "revert: forced revert restores the older value" 200 "$RESP_HTTP" '.[0].current.title' "$FORCE_A_FROM" "$RESP_BODY"

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
# A row ends in a Revert form, "reverted" or (review #248) "replaced by a
# later change"; the seeds all hit one title, so most are the last kind.
ROW_MARK='name="change_id"\|<em>reverted</em>\|replaced by a later change'
P1_FORMS="$(echo "$PAGE1" | grep -c "$ROW_MARK")"
P2_FORMS="$(echo "$PAGE2" | grep -c "$ROW_MARK")"
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

# Review #248 on the Tools page: the 101 seeds overwrite one another, so all
# but the newest are marked replaced, and a value-changed notice offers a
# forced revert for the row that was refused.
P1_SUPERSEDED="$(echo "$PAGE1" | grep -c 'replaced by a later change')"
if [[ "$P1_SUPERSEDED" -ge 99 ]]; then
  PASS=$((PASS+1)); echo "  ok   admin: overwritten pushes say replaced by a later change, not Revert ($P1_SUPERSEDED rows)"
else
  FAIL=$((FAIL+1)); echo "  FAIL admin: expected >=99 replaced rows on page 1, got $P1_SUPERSEDED"
fi
OLD_SEED_ID="$(php "$CCC_CACHE/wp-cli.phar" eval 'foreach ( CCC_Change_Log::all() as $e ) { if ( "seed-new-0" === $e["to"] ) { echo (int) $e["id"]; break; } }' --path="$CCC_SITE" 2>/dev/null)"
CHANGED_HTML="$(php "$CCC_CACHE/wp-cli.phar" eval "\$_GET['ccc_notice'] = 'changed'; \$_GET['change_id'] = '$OLD_SEED_ID'; require_once WP_PLUGIN_DIR . '/crawl-cove-connector/admin/class-ccc-admin.php'; wp_set_current_user( 1 ); CCC_Admin::render();" --path="$CCC_SITE" 2>/dev/null)"
if [[ -n "$OLD_SEED_ID" && "$CHANGED_HTML" == *'Not reverted: this title has changed since that push and now reads &quot;'* \
   && "$CHANGED_HTML" == *'name="force" value="1"'* && "$CHANGED_HTML" == *'Revert anyway to &quot;seed-old-0&quot;'* ]]; then
  PASS=$((PASS+1)); echo "  ok   admin: value-changed notice names the current value and offers Revert anyway"
else
  FAIL=$((FAIL+1)); echo "  FAIL admin: value-changed notice missing or wrong (seed id '$OLD_SEED_ID')"; echo "$CHANGED_HTML" | grep -o 'Not reverted[^<]*' | head -2
fi

# The Tools page form handler itself: refused without force (notice=changed,
# nothing written), reverted with it.
TITLE_BEFORE="$(php "$CCC_CACHE/wp-cli.phar" eval "echo CCC_Adapter::detect()->get_title( $CCC_POST_EDITOR );" --path="$CCC_SITE" 2>/dev/null)"
HR_LOC="$(CCC_HR_ID="$OLD_SEED_ID" php "$CCC_CACHE/wp-cli.phar" eval-file "$HERE/admin-revert.php" --path="$CCC_SITE" 2>/dev/null)"
TITLE_AFTER="$(php "$CCC_CACHE/wp-cli.phar" eval "echo CCC_Adapter::detect()->get_title( $CCC_POST_EDITOR );" --path="$CCC_SITE" 2>/dev/null)"
if [[ "$HR_LOC" == *'ccc_notice=changed'* && "$HR_LOC" == *"change_id=$OLD_SEED_ID"* && "$TITLE_AFTER" == "$TITLE_BEFORE" ]]; then
  PASS=$((PASS+1)); echo "  ok   admin: Revert on a changed value redirects with notice=changed and writes nothing"
else
  FAIL=$((FAIL+1)); echo "  FAIL admin: refused Revert wrong (redirect '$HR_LOC', title '$TITLE_BEFORE' -> '$TITLE_AFTER')"
fi
HR_LOC="$(CCC_HR_ID="$OLD_SEED_ID" CCC_HR_FORCE=1 php "$CCC_CACHE/wp-cli.phar" eval-file "$HERE/admin-revert.php" --path="$CCC_SITE" 2>/dev/null)"
TITLE_AFTER="$(php "$CCC_CACHE/wp-cli.phar" eval "echo CCC_Adapter::detect()->get_title( $CCC_POST_EDITOR );" --path="$CCC_SITE" 2>/dev/null)"
if [[ "$HR_LOC" == *'ccc_notice=reverted'* && "$TITLE_AFTER" == 'seed-old-0' ]]; then
  PASS=$((PASS+1)); echo "  ok   admin: Revert anyway restores the entry's previous value"
else
  FAIL=$((FAIL+1)); echo "  FAIL admin: Revert anyway wrong (redirect '$HR_LOC', title now '$TITLE_AFTER')"
fi

summary
