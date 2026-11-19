#!/usr/bin/env bash
#
# Upgrade from 1.0.0 (ccc_* options) to the crawlcove_* names, against a
# real WordPress. Installs the v1.0.0 file set (main at 1558378), stores an
# IndexNow key, a hardening setting and a change log entry through 1.0.0's own
# classes, then swaps in this checkout's code and checks that the key file is
# still served, the setting still applies, the log and its sequence survive,
# and no ccc_* option is left behind.
#
# Self-contained (own site dir and port), like indexnow-checks.sh.
# Run: bash tests/integration/migrate-checks.sh

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-migrate"
OLD_REF="${OLD_REF:-1558378}"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8998
URL="http://127.0.0.1:$PORT"
SERVER_PID=""
KEY="0a1b2c3d4e5f60718293a4b5c6d7e8f9"
OLD="$(mktemp -d)"

log() { echo "[migrate] $*"; }

cleanup() {
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  rm -rf "$OLD"
}
trap cleanup EXIT

for f in wordpress.zip sqlite.zip wp-cli.phar; do
  [[ -f "$CACHE/$f" ]] || { echo "missing $CACHE/$f" >&2; exit 1; }
done

log "rebuilding site dir with the $OLD_REF file set"
rm -rf "$SITE"
mkdir -p "$SITE"
unzip -q "$CACHE/wordpress.zip" -d "$SITE/_core"
cp -r "$SITE"/_core/wordpress/. "$SITE/"
rm -rf "$SITE/_core"
mkdir -p "$SITE/wp-content/plugins"
unzip -q "$CACHE/sqlite.zip" -d "$SITE/wp-content/plugins"
cp "$SITE/wp-content/plugins/sqlite-database-integration/db.copy" "$SITE/wp-content/db.php"
git -C "$PLUGIN_ROOT" archive "$OLD_REF" | tar -x -C "$OLD"
bash "$OLD/bin/build-zip.sh" --stage-to "$SITE/wp-content/plugins/crawl-cove-connector" >/dev/null

WP=("${WPCLI[@]}" --path="$SITE" --quiet)
"${WP[@]}" config create --dbname=irrelevant --dbuser=irrelevant --dbpass=irrelevant --skip-check
"${WP[@]}" core install --url="$URL" --title="CCC Migrate" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
"${WP[@]}" plugin activate sqlite-database-integration crawl-cove-connector
"${WP[@]}" --user=admin eval "
  CCC_IndexNow::store( '$KEY' );
  CCC_Site_Settings::set( 'hide_generator', true );
  CCC_Change_Log::record( 1, 'title', 'Old title', 'New title', 'test' );
  CCC_Change_Log::record( 1, 'description', '', 'New description', 'test' );
"

summary() { echo; echo "== $PASS passed, $FAIL failed =="; [[ $FAIL -eq 0 ]]; }
PASS=0
FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL $1"; }
same() { if [[ "$2" == "$3" ]]; then ok "$1"; else fail "$1 — want [$3], got [$2]"; fi; }
opt()  { "${WP[@]}" option get "$1" --format=json 2>/dev/null || echo "(none)"; }
serve() {
  ( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" "$HERE/router.php" >"$HERE/.server-migrate.log" 2>&1 ) &
  SERVER_PID=$!
  for i in $(seq 1 30); do curl -s -o /dev/null "$URL/index.php?rest_route=/" && break; sleep 0.3; done
}
stop() { kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; SERVER_PID=""; }
generator() { curl -s --max-time 15 "$URL/" | grep -c '<meta name="generator" content="WordPress' || true; }

echo "-- on $OLD_REF --"
serve
same "1.0.0 serves the key file" "$(curl -s --max-time 10 "$URL/$KEY.txt")" "$KEY"
same "1.0.0 hides the generator tag" "$(generator)" "0"
same "1.0.0 stored the key under ccc_indexnow_key" "$(opt ccc_indexnow_key)" "\"$KEY\""
stop

log "swapping in this checkout's code"
bash "$PLUGIN_ROOT/bin/build-zip.sh" --stage-to "$SITE/wp-content/plugins/crawl-cove-connector" >/dev/null

echo "-- after the upgrade (first request runs the migration) --"
serve
same "the key file is still served" "$(curl -s --max-time 10 "$URL/$KEY.txt")" "$KEY"
same "the generator tag is still hidden" "$(generator)" "0"
same "key under the new name" "$(opt crawlcove_indexnow_key)" "\"$KEY\""
same "setting under the new name" "$("${WP[@]}" eval 'echo wp_json_encode( CrawlCove_Site_Settings::get()["hide_generator"] );')" "true"
same "both log entries kept" "$("${WP[@]}" eval 'echo count( CrawlCove_Change_Log::all() );')" "2"
same "sequence kept" "$(opt crawlcove_change_seq)" "\"2\""
same "a new entry continues the sequence" "$("${WP[@]}" eval 'echo CrawlCove_Change_Log::record( 1, "title", "New title", "Newer", "test" )["id"];')" "3"
for o in ccc_change_log ccc_change_seq ccc_last_connection ccc_site_settings ccc_indexnow_key; do
  same "no $o left" "$(opt "$o")" "(none)"
done
stop

summary
