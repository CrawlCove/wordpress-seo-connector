#!/usr/bin/env bash
#
# Caching-plugin compatibility verification — proves, against a real
# WordPress + real Rank Math install, which of CCC's writes fire the core
# `clean_post_cache` action that page-caching plugins hook to purge a
# stale cached page.
#
# WHY THIS EXISTS: a real post apply() calls clean_post_cache() (until
# 0.10.7 it re-saved the post via wp_update_post(), which fired it as a side
# effect but also re-ran the content through kses, see
# content-integrity-checks.sh) — the action WP Super Cache's own
# wp_cache_post_edit()/wp_cache_post_change() hook (confirmed against WP
# Super Cache's real source, wp-cache-phase2.php: both bail immediately on
# `$post_id === 0` and are registered ONLY on clean_post_cache, never on
# any term-edit hook). But the homepage (post_id 0, "your latest posts")
# and a taxonomy archive (a negative term-id sentinel) write through
# update_option()/update_term_meta() directly, with NO wp_update_post()
# call at all (core treats ID 0 as an INSERT signal — see the HOME_ID
# comments in class-ccc-service.php) — so before the fix this harness
# verifies, a caching plugin never learned those pages had changed, and a
# desktop-app "push to WordPress" for a homepage/category-archive title
# could sit behind a stale cache for as long as the cache's TTL. Same gap
# existed for EVERY revert(), including on an ordinary post: CCC_Change_Log
# ::revert() wrote the reverted value directly and never called
# wp_update_post() at all, for any target.
#
# This harness doesn't stand up WP Super Cache's full page-cache machinery
# (its advanced-cache.php drop-in needs a wp-config.php WP_CACHE constant
# and writable cache dirs set up through its own admin-side activation
# flow, not wp-cli) — instead an mu-plugin probe hooks the exact actions/
# functions real caching plugins rely on (clean_post_cache; WP Super
# Cache's own wp_cache_clear_cache(); LiteSpeed Cache's documented
# litespeed_purge_all action) and logs when WordPress fires them, which is
# the actual signal any such plugin depends on. This is a stronger check of
# CCC's OWN behaviour than fighting a specific plugin's file-cache
# machinery would be.
#
# Self-contained harness (own site dir/port), like woocommerce-checks.sh —
# NOT wired into run.sh, this is a single cross-cutting behaviour, not a
# per-adapter one. Run manually: tests/integration/caching-checks.sh

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-cache"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8991
URL="http://127.0.0.1:$PORT"
SERVER_PID=""
LOG="$SITE/ccc-cache-probe.log"

log() { echo "[caching-checks] $*"; }

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
ln -s "$PLUGIN_ROOT" "$SITE/wp-content/plugins/crawl-cove-connector"

cat > "$SITE/wp-content/mu-plugins/ccc-test-harness.php" <<PHP
<?php
add_filter( 'wp_is_application_passwords_available', '__return_true' );

define( 'CCC_PROBE_LOG', '$LOG' );

function ccc_probe_log( \$line ) {
	file_put_contents( CCC_PROBE_LOG, \$line . "\n", FILE_APPEND | LOCK_EX );
}

add_action( 'clean_post_cache', function ( \$post_id ) {
	ccc_probe_log( "clean_post_cache:\$post_id" );
} );

// Mimics WP Super Cache's own public API — confirmed against its real
// source (wp-cache-phase2.php): \`function wp_cache_clear_cache( \$blog_id = 0 )\`.
// Not requiring the full plugin here means this probe doesn't depend on
// WP Super Cache's own admin-side activation flow (WP_CACHE constant +
// advanced-cache.php drop-in), which wp-cli can't drive.
function wp_cache_clear_cache( \$blog_id = 0 ) {
	ccc_probe_log( 'wp_cache_clear_cache' );
}

add_action( 'litespeed_purge_all', function () {
	ccc_probe_log( 'litespeed_purge_all' );
} );

add_action( 'wpfc_clear_all_cache', function () {
	ccc_probe_log( 'wpfc_clear_all_cache' );
} );

add_action( 'litespeed_purge_post', function ( \$post_id ) {
	ccc_probe_log( "litespeed_purge_post:\$post_id" );
} );

add_action( 'wpfc_clear_post_cache_by_id', function ( \$comment_id, \$post_id ) {
	ccc_probe_log( "wpfc_clear_post_cache_by_id:\$post_id" );
}, 10, 2 );

add_action( 'save_post', function ( \$post_id ) {
	ccc_probe_log( "save_post:\$post_id" );
} );

add_action( 'ccc_after_uncached_write', function () {
	ccc_probe_log( 'ccc_after_uncached_write' );
} );
PHP

log "wp core config + install"
"${WPCLI[@]}" config create --path="$SITE" --dbname=irrelevant --dbuser=irrelevant --dbpass=irrelevant --skip-check --quiet
"${WPCLI[@]}" core install --path="$SITE" --url="$URL" --title="CCC Caching Check" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
"${WPCLI[@]}" plugin activate sqlite-database-integration crawl-cove-connector seo-by-rank-math --path="$SITE" --quiet

log "editor user (manage_options, so homepage writes aren't blocked on capability) + application password"
"${WPCLI[@]}" user create ccc_editor editor@example.com --role=editor --user_pass=editor-pass --path="$SITE" --quiet
"${WPCLI[@]}" user add-cap ccc_editor manage_options --path="$SITE" --quiet
EDITOR_PW="$("${WPCLI[@]}" user application-password create ccc_editor ccc-test --porcelain --path="$SITE")"

log "content: one ordinary post, one category term; homepage set to 'your latest posts'"
POST_ID="$("${WPCLI[@]}" post create --post_title="Ordinary Post" --post_status=publish --porcelain --path="$SITE")"
"${WPCLI[@]}" term create category News --path="$SITE" --quiet
TERM_ID="$("${WPCLI[@]}" term list category --field=term_id --path="$SITE" | tail -1)"
"${WPCLI[@]}" option update show_on_front posts --path="$SITE" --quiet

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server-cache.log" 2>&1 ) &
SERVER_PID=$!
UP=0
for i in $(seq 1 30); do
  if curl -s -o /dev/null "$URL/index.php?rest_route=/"; then UP=1; break; fi
  sleep 0.3
done
if [[ "$UP" -ne 1 ]]; then
  echo "php -S never came up — see $HERE/.server-cache.log" >&2
  exit 1
fi

BASE="$URL/index.php?rest_route=/crawlcove/v1"
AUTH="ccc_editor:$EDITOR_PW"
PASS=0
FAIL=0

req() {
  local method="$1" path="$2" data="${3:-}"
  local out
  local -a curlargs=(-s -X "$method" "$BASE$path" -w '\n%{http_code}' --max-time 10 -u "$AUTH")
  [[ -n "$data" ]] && curlargs+=(-H 'Content-Type: application/json' --data-binary "$data")
  out="$(curl "${curlargs[@]}")"
  RESP_HTTP="$(echo "$out" | tail -1)"
  RESP_BODY="$(echo "$out" | sed '$d')"
}

reset_log() { : > "$LOG"; }
probe_has() { grep -qxF "$1" "$LOG"; }

expect_contains() {
  local label="$1" marker="$2"
  if probe_has "$marker"; then
    PASS=$((PASS+1)); echo "  ok   $label"
  else
    FAIL=$((FAIL+1)); echo "  FAIL $label — '$marker' not found in probe log:"
    sed 's/^/       /' "$LOG"
  fi
}

expect_absent() {
  local label="$1" marker="$2"
  if probe_has "$marker"; then
    FAIL=$((FAIL+1)); echo "  FAIL $label — '$marker' unexpectedly found in probe log:"
    sed 's/^/       /' "$LOG"
  else
    PASS=$((PASS+1)); echo "  ok   $label"
  fi
}

echo "-- ordinary post: apply fires clean_post_cache for that post --"
reset_log
req POST /apply "{\"changes\":[{\"post_id\":$POST_ID,\"title\":\"Post Title v1\"}]}"
CHANGE_ID_POST="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"
expect_contains "apply(real post): clean_post_cache fired for post $POST_ID" "clean_post_cache:$POST_ID"
expect_contains "apply(real post): LiteSpeed per-post purge fired" "litespeed_purge_post:$POST_ID"
expect_contains "apply(real post): WP Fastest Cache per-post purge fired" "wpfc_clear_post_cache_by_id:$POST_ID"
expect_absent "apply(real post): the post is never re-saved (save_post would mean wp_update_post ran)" "save_post:$POST_ID"

echo "-- ordinary post: revert ALSO fires clean_post_cache (previously it fired nothing at all) --"
reset_log
req POST /revert "{\"change_id\":$CHANGE_ID_POST}"
expect_contains "revert(real post): clean_post_cache fired for post $POST_ID" "clean_post_cache:$POST_ID"

echo "-- homepage (your latest posts, post_id 0): apply triggers a full-cache purge, never clean_post_cache --"
reset_log
req POST /apply "{\"changes\":[{\"post_id\":0,\"title\":\"Home Title v1\"}]}"
CHANGE_ID_HOME="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"
expect_contains "apply(home): WP Super Cache's wp_cache_clear_cache() called" "wp_cache_clear_cache"
expect_contains "apply(home): LiteSpeed's litespeed_purge_all fired" "litespeed_purge_all"
expect_contains "apply(home): WP Fastest Cache's wpfc_clear_all_cache fired" "wpfc_clear_all_cache"
expect_contains "apply(home): plugin-agnostic ccc_after_uncached_write fired" "ccc_after_uncached_write"
expect_absent "apply(home): clean_post_cache never fires for post_id 0 (would be core's INSERT signal)" "clean_post_cache:0"

echo "-- homepage: revert ALSO triggers the full-cache purge (previously nothing fired) --"
reset_log
req POST /revert "{\"change_id\":$CHANGE_ID_HOME}"
expect_contains "revert(home): wp_cache_clear_cache() called" "wp_cache_clear_cache"
expect_contains "revert(home): ccc_after_uncached_write fired" "ccc_after_uncached_write"

echo "-- taxonomy term: apply triggers a full-cache purge --"
reset_log
req POST /apply "{\"changes\":[{\"post_id\":-$TERM_ID,\"title\":\"Term Title v1\"}]}"
CHANGE_ID_TERM="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"
expect_contains "apply(term): wp_cache_clear_cache() called" "wp_cache_clear_cache"
expect_contains "apply(term): ccc_after_uncached_write fired" "ccc_after_uncached_write"

echo "-- taxonomy term: revert ALSO triggers the full-cache purge (previously nothing fired) --"
reset_log
req POST /revert "{\"change_id\":$CHANGE_ID_TERM}"
expect_contains "revert(term): wp_cache_clear_cache() called" "wp_cache_clear_cache"
expect_contains "revert(term): ccc_after_uncached_write fired" "ccc_after_uncached_write"

echo
echo "== $PASS passed, $FAIL failed =="
[[ $FAIL -eq 0 ]]
STATUS=$?

if [[ $STATUS -eq 0 ]]; then
  log "ALL CHECKS PASSED"
else
  log "CHECKS FAILED — see output above; server log: $HERE/.server-cache.log"
fi
exit $STATUS
