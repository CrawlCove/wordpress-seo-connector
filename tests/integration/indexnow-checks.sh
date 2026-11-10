#!/usr/bin/env bash
#
# /indexnow-key (app ticket #349, CA-36) against a real WordPress.
#
# Pins, over HTTP: the key file /<key>.txt answers 200 text/plain with the key
# as its whole body and no redirect, on plain AND pretty permalinks; nothing is
# served before a key is set, after DELETE, or for the old key once replaced;
# only an administrator may set or remove the key; bad keys are refused;
# /status advertises the capability; uninstall deletes the option.
#
# php -S hands every path it has no file for to index.php, the same thing
# nginx's usual try_files and WordPress's Apache rewrite block do.
#
# Self-contained (own site dir and port), like site-settings-checks.sh.
# Run: bash tests/integration/indexnow-checks.sh

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-indexnow"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8997
URL="http://127.0.0.1:$PORT"
SERVER_PID=""

log() { echo "[indexnow] $*"; }

cleanup() {
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

for f in wordpress.zip sqlite.zip wp-cli.phar; do
  [[ -f "$CACHE/$f" ]] || { echo "missing $CACHE/$f" >&2; exit 1; }
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
bash "$PLUGIN_ROOT/bin/build-zip.sh" --stage-to "$SITE/wp-content/plugins/crawl-cove-connector" >/dev/null
cat > "$SITE/wp-content/mu-plugins/ccc-test-harness.php" <<'PHP'
<?php
add_filter( 'wp_is_application_passwords_available', '__return_true' );
PHP

WP=("${WPCLI[@]}" --path="$SITE")
"${WP[@]}" config create --dbname=irrelevant --dbuser=irrelevant --dbpass=irrelevant --skip-check --quiet
"${WP[@]}" core install --url="$URL" --title="CCC IndexNow" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
"${WP[@]}" plugin activate sqlite-database-integration crawl-cove-connector --quiet
"${WP[@]}" user create ccc_editor editor@example.com --role=editor --user_pass=editor-pass --quiet
"${WP[@]}" user create ccc_author author@example.com --role=author --user_pass=author-pass --quiet
"${WP[@]}" user create ccc_subscriber sub@example.com --role=subscriber --user_pass=sub-pass --quiet
pw_for() { "${WP[@]}" user application-password create "$1" "ccc-test" --porcelain; }
export CCC_URL="$URL" CCC_EDITOR_PW="$(pw_for ccc_editor)" CCC_AUTHOR_PW="$(pw_for ccc_author)" CCC_SUBSCRIBER_PW="$(pw_for ccc_subscriber)"
export CCC_POST_EDITOR=0 CCC_POST_AUTHOR=0 CCC_POST_HOME=0 CCC_ADAPTER=none
ADMIN="admin:$(pw_for admin)"
source "$HERE/lib.sh"

ok()    { PASS=$((PASS+1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL+1)); echo "  FAIL $1"; }
has()   { if grep -qiF -- "$3" <<<"$2"; then ok "$1"; else fail "$1 — [$3] missing"; fi; }
hasnt() { if grep -qiF -- "$3" <<<"$2"; then fail "$1 — [$3] present"; else ok "$1"; fi; }
# key_file <path> -> sets KF_HTTP KF_TYPE KF_LOC KF_BODY (no redirects followed)
key_file() {
  local hdr body
  hdr="$(mktemp)"
  body="$(curl -s -D "$hdr" --max-time 15 "$URL$1")"
  KF_HTTP="$(awk 'NR==1{print $2}' "$hdr")"
  KF_TYPE="$(grep -i '^content-type:' "$hdr" | tr -d '\r' | cut -d' ' -f2-)"
  KF_LOC="$(grep -i '^location:' "$hdr" | tr -d '\r')"
  KF_ROBOTS="$(grep -i '^x-robots-tag:' "$hdr" | tr -d '\r' | cut -d' ' -f2-)"
  KF_BODY="$body"
  rm -f "$hdr"
}
# serves_key <label> <key>: the whole contract of the key file
serves_key() {
  key_file "/$2.txt"
  if [[ "$KF_HTTP" == "200" && "$KF_TYPE" == "text/plain; charset=utf-8" && -z "$KF_LOC" && "$KF_BODY" == "$2" && "$KF_ROBOTS" == "noindex" ]]; then
    ok "$1"
  else
    fail "$1 — http=$KF_HTTP type=[$KF_TYPE] loc=[$KF_LOC] robots=[$KF_ROBOTS] body=[$(head -c 80 <<<"$KF_BODY")]"
  fi
}
not_served() {
  key_file "/$2.txt"
  if [[ "$KF_BODY" != "$2" && "$KF_HTTP" != "200" ]]; then ok "$1 (http $KF_HTTP)"; else fail "$1 — http=$KF_HTTP body=[$(head -c 80 <<<"$KF_BODY")]"; fi
}

KEY="ccc0a1b2c3d4e5f6-test"
KEY2="second-key-0123456789"

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server-indexnow.log" 2>&1 ) &
SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$URL/index.php?rest_route=/" && break; sleep 0.3; done

echo "-- before a key is set --"
req GET /status "$EDITOR" ""
check "status: capabilities list indexnow" 200 "$RESP_HTTP" '.capabilities | index("indexnow") != null' 'true' "$RESP_BODY"
req GET /indexnow-key "$EDITOR" ""
check "GET: 404 with no key" 404 "$RESP_HTTP" '.code' 'ccc_no_indexnow_key' "$RESP_BODY"
not_served "no key: /<key>.txt is not served" "$KEY"

echo "-- who may set it --"
req POST /indexnow-key "$EDITOR" "{\"key\":\"$KEY\"}"
check "editor: refused" 403 "$RESP_HTTP" '.code' 'ccc_forbidden' "$RESP_BODY"
req POST /indexnow-key "$SUBSCRIBER" "{\"key\":\"$KEY\"}"
check_http "subscriber: refused" 403 "$RESP_HTTP" "$RESP_BODY"
req POST /indexnow-key "" "{\"key\":\"$KEY\"}"
check_http "anonymous: refused" 401 "$RESP_HTTP" "$RESP_BODY"
not_served "refused: still nothing served" "$KEY"

echo "-- bad keys --"
for bad in 'short1' 'has_underscore_1' '../../wp-config' 'dot.ted.key.1'; do
  req POST /indexnow-key "$ADMIN" "{\"key\":\"$bad\"}"
  check "bad key [$bad]: refused" 400 "$RESP_HTTP" '.code' 'ccc_bad_indexnow_key' "$RESP_BODY"
done
req POST /indexnow-key "$ADMIN" '{}'
check_http "missing key: refused" 400 "$RESP_HTTP" "$RESP_BODY"

echo "-- set, plain permalinks --"
req POST /indexnow-key "$ADMIN" "{\"key\":\"$KEY\"}"
check "POST: key and keyLocation" 200 "$RESP_HTTP" '[.key, .keyLocation]' "[\"$KEY\",\"$URL/$KEY.txt\"]" "$RESP_BODY"
req GET /indexnow-key "$EDITOR" ""
check "GET: an editor can read it" 200 "$RESP_HTTP" '[.key, .keyLocation]' "[\"$KEY\",\"$URL/$KEY.txt\"]" "$RESP_BODY"
serves_key "plain: /<key>.txt answers 200 text/plain, body = key, no redirect" "$KEY"
key_file "/$KEY.txt?cb=1"
[[ "$KF_BODY" == "$KEY" ]] && ok "plain: a query string still gets the key" || fail "plain: query string — http=$KF_HTTP"
not_served "plain: another .txt name is not the key" "wrong-key-12345678"
key_file "/"
hasnt "plain: the home page is untouched" "$KF_BODY" "$KEY"

echo "-- pretty permalinks --"
"${WP[@]}" rewrite structure '/%postname%/' --quiet
"${WP[@]}" rewrite flush --quiet
serves_key "pretty: /<key>.txt answers 200 text/plain, body = key, no redirect" "$KEY"
key_file "/$KEY.txt/"
[[ "$KF_BODY" != "$KEY" ]] && ok "pretty: /<key>.txt/ is not the key file (http $KF_HTTP)" || fail "pretty: trailing slash served the key"
req GET /indexnow-key "$EDITOR" ""
check "pretty: keyLocation unchanged" 200 "$RESP_HTTP" '.keyLocation' "$URL/$KEY.txt" "$RESP_BODY"

echo "-- replace --"
req POST /indexnow-key "$ADMIN" "{\"key\":\"$KEY2\"}"
check "replace: new key stored" 200 "$RESP_HTTP" '.key' "$KEY2" "$RESP_BODY"
serves_key "replace: the new key is served" "$KEY2"
not_served "replace: the old key is gone" "$KEY"

echo "-- delete --"
req DELETE /indexnow-key "$EDITOR" ""
check "delete: editor refused" 403 "$RESP_HTTP" '.code' 'ccc_forbidden' "$RESP_BODY"
serves_key "delete refused: key still served" "$KEY2"
req DELETE /indexnow-key "$ADMIN" ""
check "delete: admin ok" 200 "$RESP_HTTP" '.deleted' 'true' "$RESP_BODY"
not_served "delete: key file gone" "$KEY2"
req GET /indexnow-key "$EDITOR" ""
check "delete: GET is 404 again" 404 "$RESP_HTTP" '.code' 'ccc_no_indexnow_key' "$RESP_BODY"
req DELETE /indexnow-key "$ADMIN" ""
check "delete again: deleted false" 200 "$RESP_HTTP" '.deleted' 'false' "$RESP_BODY"

echo "-- uninstall removes the key --"
req POST /indexnow-key "$ADMIN" "{\"key\":\"$KEY\"}"
"${WP[@]}" plugin deactivate crawl-cove-connector --quiet
not_served "deactivated: key file not served" "$KEY"
"${WP[@]}" plugin uninstall crawl-cove-connector --quiet
if "${WP[@]}" option get ccc_indexnow_key >/dev/null 2>&1; then fail "uninstall: ccc_indexnow_key still stored"; else ok "uninstall: ccc_indexnow_key deleted"; fi

summary
