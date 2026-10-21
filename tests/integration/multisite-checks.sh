#!/usr/bin/env bash
#
# WordPress Multisite compatibility verification — converts a fresh install
# to a network, creates a subsite, activates CCC + Rank Math ONLY on that
# subsite, and proves the REST routes are correctly scoped per-site rather
# than leaking network-wide.
#
# WHY THIS EXISTS: nothing in CCC touches is_multisite()/switch_to_blog()/
# get_current_blog_id() (grep across includes/ finds nothing) — it relies
# entirely on register_rest_route() firing per-site on rest_api_init and on
# WordPress core's own per-site table scoping (wp_3_posts, wp_3_postmeta,
# etc.). That strongly implies multisite already works with zero code
# changes, but a code read is not a test (see the 5 Sept "reading the code
# is not running the code" LESSONS entry) — this proves it against a real
# multisite network instead of trusting it.
#
# TRAP, found and worked around here: DO NOT `wp plugin activate --network`
# (or otherwise touch the activation state of) sqlite-database-integration
# on this box. It has no mysqli extension, and the plugin's own
# deactivate.php hook (fired mid-activate-cycle by wp-cli in some paths)
# tries to fall back to a real mysqli wpdb and fatals, DELETING
# wp-content/db.php on the way — the SQLite drop-in doesn't need "activation"
# at all, db.php alone makes it load; leave its plugin-list entry alone.
#
# Also: multisite usernames may not contain underscores (unlike single-site)
# — wp-cli's `user create` rejects `ccc_editor` here with "Usernames can only
# contain lowercase letters (a-z) and numbers," hence `ccceditor` below.
#
# Self-contained harness (own site dir/port) — NOT wired into run.sh, since
# multisite conversion + subsite creation is a distinct scenario from the
# per-adapter matrix. Run manually: tests/integration/multisite-checks.sh

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-ms"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8990
URL="http://127.0.0.1:$PORT"
SUBSITE_URL="$URL/subsite"
SERVER_PID=""

log() { echo "[multisite-checks] $*"; }

cleanup() {
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

for f in wordpress.zip sqlite.zip rankmath.zip wp-cli.phar; do
  if [[ ! -f "$CACHE/$f" ]]; then
    echo "missing $CACHE/$f" >&2
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
cp "$SITE/wp-content/plugins/sqlite-database-integration/db.copy" "$SITE/wp-content/db.php"
unzip -q "$CACHE/rankmath.zip" -d "$SITE/wp-content/plugins"
# A staged copy of the shipped file set, NOT a symlink to the repo root: the
# site lives inside the repo, so core's plugin_basename() rewrote every other
# plugin's path through the symlink and no plugin's activation hook ever fired
# (SEOPress never enabled its titles module, 21 Oct 2026).
bash "$PLUGIN_ROOT/bin/build-zip.sh" --stage-to "$SITE/wp-content/plugins/crawl-cove-connector" >/dev/null

cat > "$SITE/wp-content/mu-plugins/ccc-test-harness.php" <<'PHP'
<?php
add_filter( 'wp_is_application_passwords_available', '__return_true' );
PHP

log "wp core config + install"
"${WPCLI[@]}" config create --path="$SITE" --dbname=irrelevant --dbuser=irrelevant --dbpass=irrelevant --skip-check --quiet
"${WPCLI[@]}" core install --path="$SITE" --url="$URL" --title="CCC MS" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet

log "converting to a multisite network"
"${WPCLI[@]}" core multisite-convert --path="$SITE" --title="CCC MS Network" --base=/ --quiet

log "creating a subsite"
"${WPCLI[@]}" site create --slug=subsite --title=Subsite --path="$SITE" --quiet

log "activating crawl-cove-connector + Rank Math on the SUBSITE ONLY (not network-wide)"
"${WPCLI[@]}" plugin activate crawl-cove-connector seo-by-rank-math --url="$SUBSITE_URL" --path="$SITE" --quiet

log "editor user + application password (on the subsite)"
"${WPCLI[@]}" user create ccceditor editor@example.com --role=editor --user_pass=editor-pass --url="$SUBSITE_URL" --path="$SITE" --quiet
EDITOR_PW="$("${WPCLI[@]}" user application-password create ccceditor ccc-test --porcelain --url="$SUBSITE_URL" --path="$SITE")"

log "a post on the subsite"
POST_ID="$("${WPCLI[@]}" post create --post_title="Subsite Post" --post_status=publish --url="$SUBSITE_URL" --path="$SITE" --porcelain)"

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server-ms.log" 2>&1 ) &
SERVER_PID=$!
UP=0
for i in $(seq 1 30); do
  if curl -s -o /dev/null "$URL/index.php?rest_route=/"; then UP=1; break; fi
  sleep 0.3
done
if [[ "$UP" -ne 1 ]]; then
  echo "php -S never came up — see $HERE/.server-ms.log" >&2
  exit 1
fi

BASE_SUBSITE="$SUBSITE_URL/index.php?rest_route=/crawlcove/v1"
BASE_NETWORK="$URL/index.php?rest_route=/crawlcove/v1"
AUTH="ccceditor:$EDITOR_PW"
PASS=0
FAIL=0

check() {
  local label="$1" expect_http="$2" got_http="$3" jq_filter="$4" expect_val="$5" body="$6"
  local got_val
  got_val="$(echo "$body" | jq -rc "$jq_filter" 2>/dev/null)"
  if [[ "$got_http" == "$expect_http" && "$got_val" == "$expect_val" ]]; then
    PASS=$((PASS+1)); echo "  ok   $label"
  else
    FAIL=$((FAIL+1)); echo "  FAIL $label — want http=$expect_http $jq_filter=$expect_val, got http=$got_http $jq_filter=$got_val"
    echo "       body: $(echo "$body" | head -c 400)"
  fi
}

req() {
  local method="$1" url="$2" data="${3:-}" auth="${4:-}"
  local out
  local -a curlargs=(-s -X "$method" "$url" -w '\n%{http_code}' --max-time 10)
  [[ -n "$auth" ]] && curlargs+=(-u "$auth")
  [[ -n "$data" ]] && curlargs+=(-H 'Content-Type: application/json' --data-binary "$data")
  out="$(curl "${curlargs[@]}")"
  RESP_HTTP="$(echo "$out" | tail -1)"
  RESP_BODY="$(echo "$out" | sed '$d')"
}

echo "-- per-site scoping: CCC is active on the subsite, absent from the network root --"
req GET "$BASE_SUBSITE/status" "" "$AUTH"
check "subsite: /status reports rankmath active" 200 "$RESP_HTTP" '.seo_plugin' "rankmath" "$RESP_BODY"

req GET "$BASE_NETWORK/status" "" "$AUTH"
check "network root (CCC not active there): route is genuinely absent, not just forbidden" 404 "$RESP_HTTP" '.code' 'rest_no_route' "$RESP_BODY"

echo "-- write path: apply lands in the SUBSITE's own tables --"
req POST "$BASE_SUBSITE/apply" "{\"changes\":[{\"post_id\":$POST_ID,\"title\":\"Subsite Title\",\"description\":\"Subsite Desc\"}]}" "$AUTH"
check "apply: subsite post title/description write ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"

RM_TITLE="$("${WPCLI[@]}" post meta get "$POST_ID" rank_math_title --url="$SUBSITE_URL" --path="$SITE")"
if [[ "$RM_TITLE" == "Subsite Title" ]]; then
  PASS=$((PASS+1)); echo "  ok   subsite post title landed in the subsite's own Rank Math postmeta (wp_3_postmeta, not wp_postmeta)"
else
  FAIL=$((FAIL+1)); echo "  FAIL subsite post title did not land correctly (got: $RM_TITLE)"
fi

echo "-- a subsite admin (no unfiltered_html on multisite) pushing a title keeps the post body --"
"${WPCLI[@]}" user create cccsiteadmin siteadmin@example.com --role=administrator --user_pass=siteadmin-pass --url="$SUBSITE_URL" --path="$SITE" --quiet
SITEADMIN_PW="$("${WPCLI[@]}" user application-password create cccsiteadmin ccc-test --porcelain --url="$SUBSITE_URL" --path="$SITE")"
"${WPCLI[@]}" eval "kses_remove_filters(); wp_update_post( array( 'ID' => $POST_ID, 'post_content' => '<p>Watch:</p><iframe src=\"https://www.youtube.com/embed/abc\"></iframe>' ) );" --url="$SUBSITE_URL" --path="$SITE" >/dev/null 2>&1
BODY_BEFORE="$("${WPCLI[@]}" post get "$POST_ID" --field=post_content --url="$SUBSITE_URL" --path="$SITE")"
req POST "$BASE_SUBSITE/apply" "{\"changes\":[{\"post_id\":$POST_ID,\"title\":\"Site Admin Title\"}]}" "cccsiteadmin:$SITEADMIN_PW"
check "apply: subsite admin title push ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
BODY_AFTER="$("${WPCLI[@]}" post get "$POST_ID" --field=post_content --url="$SUBSITE_URL" --path="$SITE")"
if [[ "$BODY_BEFORE" == *"<iframe"* && "$BODY_AFTER" == "$BODY_BEFORE" ]]; then
  PASS=$((PASS+1)); echo "  ok   subsite admin push left the super admin's iframe in the post body"
else
  FAIL=$((FAIL+1)); echo "  FAIL post body changed: before [$BODY_BEFORE] after [$BODY_AFTER]"
fi

echo "-- uninstall (run once, on the main site, as WordPress does) clears the SUBSITE's change log too --"
"${WPCLI[@]}" option get ccc_change_log --url="$SUBSITE_URL" --path="$SITE" >/dev/null 2>&1 && HAD_LOG=1 || HAD_LOG=0
"${WPCLI[@]}" plugin uninstall crawl-cove-connector --skip-delete --path="$SITE" --quiet 2>/dev/null
if [[ "$HAD_LOG" == "1" ]] && ! "${WPCLI[@]}" option get ccc_change_log --url="$SUBSITE_URL" --path="$SITE" >/dev/null 2>&1 \
  && ! "${WPCLI[@]}" option get ccc_change_seq --url="$SUBSITE_URL" --path="$SITE" >/dev/null 2>&1 \
  && ! "${WPCLI[@]}" option get ccc_last_connection --url="$SUBSITE_URL" --path="$SITE" >/dev/null 2>&1; then
  PASS=$((PASS+1)); echo "  ok   uninstall removed the subsite's ccc_change_log, ccc_change_seq and ccc_last_connection"
else
  FAIL=$((FAIL+1)); echo "  FAIL subsite options survived uninstall (had log before: $HAD_LOG)"
fi

echo
echo "== $PASS passed, $FAIL failed =="
[[ $FAIL -eq 0 ]]
