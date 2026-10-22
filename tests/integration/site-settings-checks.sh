#!/usr/bin/env bash
#
# POST /site-settings (0.11, app ticket #266) against a real WordPress.
#
# Pins, on the front end and over HTTP: the generator meta tag, the three
# hardening headers and xmlrpc.php (the body marker the desktop app's probe
# looks for) before and after each switch, and again after a revert; only an
# administrator may switch; dry-run writes nothing; uploads_no_index is refused
# on a server that is not Apache/LiteSpeed and, on one that is (faked here,
# php -S cannot read .htaccess), writes and removes exactly its marked block.
#
# Self-contained (own site dir and port), like caching-checks.sh.
# Run: bash tests/integration/site-settings-checks.sh

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-settings"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8995
URL="http://127.0.0.1:$PORT"
SERVER_PID=""

log() { echo "[site-settings] $*"; }

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
// php -S is not Apache; this option lets one check stand in for an Apache host.
// core sets $is_apache in vars.php, after mu-plugins load, hence the hook.
add_action( 'plugins_loaded', function () {
	if ( get_option( 'ccc_test_fake_apache' ) ) {
		$GLOBALS['is_apache'] = true;
	}
}, 0 );
// Stand-in for Jetpack (only its class name is checked), switched by an option.
if ( get_option( 'ccc_test_fake_jetpack' ) && ! class_exists( 'Jetpack' ) ) {
	class Jetpack {} // phpcs:ignore
}
PHP

WP=("${WPCLI[@]}" --path="$SITE")
"${WP[@]}" config create --dbname=irrelevant --dbuser=irrelevant --dbpass=irrelevant --skip-check --quiet
"${WP[@]}" core install --url="$URL" --title="CCC Site Settings" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
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
home_head() { curl -s -D - -o /dev/null --max-time 15 "$URL/"; }
home_body() { curl -s --max-time 15 "$URL/"; }
xmlrpc()    { curl -s -w '\nHTTP %{http_code}' --max-time 15 "$URL/xmlrpc.php"; }
MARKER="XML-RPC server accepts POST requests only"
UPLOADS_HT="$SITE/wp-content/uploads/.htaccess"

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server-settings.log" 2>&1 ) &
SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$URL/index.php?rest_route=/" && break; sleep 0.3; done

echo "-- before --"
has   "before: the generator tag names WordPress" "$(home_body)" '<meta name="generator" content="WordPress'
has   "before: xmlrpc.php shows the probe's marker" "$(xmlrpc)" "$MARKER"
hasnt "before: no X-Frame-Options" "$(home_head)" "X-Frame-Options"
req GET /status "$EDITOR" ""
check "status: site_settings all off" 200 "$RESP_HTTP" '[.site_settings[]] | any' 'false' "$RESP_BODY"
check "status: capabilities list site_settings" 200 "$RESP_HTTP" '.capabilities | index("site_settings") != null' 'true' "$RESP_BODY"

echo "-- who may switch --"
req POST /site-settings "$EDITOR" '{"settings":{"hide_generator":true}}'
check "editor: refused" 403 "$RESP_HTTP" '.code' 'ccc_forbidden' "$RESP_BODY"
req POST /site-settings "$SUBSCRIBER" '{"settings":{"hide_generator":true}}'
check_http "subscriber: refused" 403 "$RESP_HTTP" "$RESP_BODY"

echo "-- dry run --"
req POST /site-settings "$ADMIN" '{"dry_run":true,"settings":{"disable_xmlrpc":true,"hide_generator":true,"security_headers":true}}'
check "dry run: all three would change" 200 "$RESP_HTTP" '[.settings[].changed]' '[true,true,true]' "$RESP_BODY"
has "dry run: generator still there" "$(home_body)" '<meta name="generator" content="WordPress'

echo "-- switch on --"
req POST /site-settings "$ADMIN" '{"settings":{"disable_xmlrpc":true,"hide_generator":true,"security_headers":true,"uploads_no_index":true}}'
check "on: three switched, each with a change_id" 200 "$RESP_HTTP" '[.settings.disable_xmlrpc, .settings.hide_generator, .settings.security_headers] | map(.to == true and (.change_id|type) == "number")' '[true,true,true]' "$RESP_BODY"
check "on: uploads_no_index refused off Apache" 200 "$RESP_HTTP" '.settings.uploads_no_index.error' 'ccc_server_unsupported' "$RESP_BODY"
GEN_ID="$(echo "$RESP_BODY" | jq -r '.settings.hide_generator.change_id')"
XML_ID="$(echo "$RESP_BODY" | jq -r '.settings.disable_xmlrpc.change_id')"
hasnt "on: no generator tag" "$(home_body)" '<meta name="generator" content="WordPress'
hasnt "on: no generator in the feed" "$(curl -s --max-time 15 "$URL/?feed=rss2")" '<generator>https://wordpress.org/?v='
HEAD="$(home_head)"
has "on: X-Content-Type-Options: nosniff" "$HEAD" "X-Content-Type-Options: nosniff"
has "on: X-Frame-Options: SAMEORIGIN" "$HEAD" "X-Frame-Options: SAMEORIGIN"
has "on: Referrer-Policy" "$HEAD" "Referrer-Policy: strict-origin-when-cross-origin"
hasnt "on: no HSTS" "$HEAD" "Strict-Transport-Security"
hasnt "on: no X-Pingback" "$HEAD" "X-Pingback"
X="$(xmlrpc)"
hasnt "on: xmlrpc.php no longer shows the probe's marker" "$X" "$MARKER"
has   "on: xmlrpc.php answers 403" "$X" "HTTP 403"
has   "on: a POST to xmlrpc.php is refused too" "$(curl -s -w 'HTTP %{http_code}' --max-time 15 -d '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>' "$URL/xmlrpc.php")" "HTTP 403"
req GET /status "$EDITOR" ""
check "status: reports the switches" 200 "$RESP_HTTP" '.site_settings | [.disable_xmlrpc, .hide_generator, .security_headers, .uploads_no_index]' '[true,true,true,false]' "$RESP_BODY"

echo "-- revert --"
req POST /revert "$EDITOR" "{\"change_id\":$GEN_ID}"
check_http "revert: an editor cannot revert a site setting" 403 "$RESP_HTTP" "$RESP_BODY"
req POST /revert "$ADMIN" "{\"change_id\":$GEN_ID}"
check "revert: admin ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
has "revert: the generator tag is back" "$(home_body)" '<meta name="generator" content="WordPress'
req POST /site-settings "$ADMIN" '{"settings":{"disable_xmlrpc":false}}'
req POST /revert "$ADMIN" "{\"change_id\":$XML_ID}"
check "revert: refused after a later change" 409 "$RESP_HTTP" '[.code, .data.current]' '["ccc_value_changed","off"]' "$RESP_BODY"
has "revert: xmlrpc.php serves its marker again once off" "$(xmlrpc)" "$MARKER"

echo "-- Jetpack needs XML-RPC --"
req POST /site-settings "$ADMIN" '{"settings":{"disable_xmlrpc":true}}'
check "jetpack: switched on while Jetpack is absent" 200 "$RESP_HTTP" '.settings.disable_xmlrpc.to' 'true' "$RESP_BODY"
has "jetpack: xmlrpc.php blocked" "$(xmlrpc)" "HTTP 403"
"${WP[@]}" option update ccc_test_fake_jetpack 1 --quiet
has "jetpack: activated later, XML-RPC is served again" "$(xmlrpc)" "$MARKER"
req POST /site-settings "$ADMIN" '{"settings":{"disable_xmlrpc":false}}'
req POST /site-settings "$ADMIN" '{"settings":{"disable_xmlrpc":true}}'
check "jetpack: switching on is refused while it is active" 200 "$RESP_HTTP" '.settings.disable_xmlrpc.error' 'ccc_xmlrpc_in_use' "$RESP_BODY"
"${WP[@]}" option delete ccc_test_fake_jetpack --quiet

echo "-- uploads .htaccess (Apache stand-in) --"
"${WP[@]}" option update ccc_test_fake_apache 1 --quiet
mkdir -p "$SITE/wp-content/uploads"
printf 'Header set X-Test "keep me"\n' > "$UPLOADS_HT"
req POST /site-settings "$ADMIN" '{"settings":{"uploads_no_index":true}}'
check "apache: uploads_no_index on" 200 "$RESP_HTTP" '.settings.uploads_no_index.to' 'true' "$RESP_BODY"
UP_ID="$(echo "$RESP_BODY" | jq -r '.settings.uploads_no_index.change_id')"
HT="$(cat "$UPLOADS_HT")"
has "apache: .htaccess carries Options -Indexes in our block" "$HT" "# BEGIN Crawl Cove Connector"
has "apache: the directive is there" "$HT" "Options -Indexes"
has "apache: the existing line is kept" "$HT" 'Header set X-Test "keep me"'
req POST /revert "$ADMIN" "{\"change_id\":$UP_ID}"
check "apache: revert ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
HT="$(cat "$UPLOADS_HT")"
hasnt "apache: the directive is gone after revert" "$HT" "Options -Indexes"
has   "apache: the existing line is still kept" "$HT" 'Header set X-Test "keep me"'

echo "-- uninstall removes the block and the setting --"
req POST /site-settings "$ADMIN" '{"settings":{"uploads_no_index":true}}'
has "uninstall: block written again first" "$(cat "$UPLOADS_HT")" "Options -Indexes"
"${WP[@]}" plugin deactivate crawl-cove-connector --quiet
"${WP[@]}" plugin uninstall crawl-cove-connector --quiet
HT="$(cat "$UPLOADS_HT")"
hasnt "uninstall: the directive is gone" "$HT" "Options -Indexes"
has   "uninstall: the existing line is still kept" "$HT" 'Header set X-Test "keep me"'
if "${WP[@]}" option get ccc_site_settings >/dev/null 2>&1; then fail "uninstall: ccc_site_settings still stored"; else ok "uninstall: ccc_site_settings deleted"; fi

summary
