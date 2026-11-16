#!/usr/bin/env bash
#
# "Desktop app: last connected" status row: only an AUTHORISED call to a
# crawlcove/v1 route may move the crawlcove_last_connection option, and the Tools
# page renders both states. Needs a live WordPress (real REST auth, real
# wp_date, real admin render); unit stubs only cover the throttle.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"

: "${CCC_SITE:?}" "${CCC_CACHE:?}"
WPCLI=(php "$CCC_CACHE/wp-cli.phar")
wp() { "${WPCLI[@]}" "$@" --path="$CCC_SITE"; }

page() { wp eval 'require_once CRAWLCOVE_PLUGIN_DIR . "admin/class-ccc-admin.php"; CrawlCove_Admin::render();' --user=1 2>&1 | tr '\n' ' ' | sed 's/<[^>]*>//g; s/  */ /g'; }
opt() { wp option get crawlcove_last_connection --format=json 2>/dev/null; }

wp option delete crawlcove_last_connection --quiet 2>/dev/null || true

echo "-- fresh install: not connected --"
if page | grep -q 'Not connected yet'; then PASS=$((PASS+1)); echo "  ok   Tools page says Not connected yet"; else FAIL=$((FAIL+1)); echo "  FAIL Tools page missing 'Not connected yet'"; fi

echo "-- rejected calls do not count as a connection --"
req GET /status ""
check_http "unauth GET /status is 401" 401 "$RESP_HTTP" "$RESP_BODY"
req GET /status "ccc_editor:not-the-real-password"
check_http "wrong password is 401" 401 "$RESP_HTTP" "$RESP_BODY"
req GET /status "$SUBSCRIBER"
check_http "subscriber is 403" 403 "$RESP_HTTP" "$RESP_BODY"
if [[ -z "$(opt)" ]]; then PASS=$((PASS+1)); echo "  ok   option still unset after three rejected calls"; else FAIL=$((FAIL+1)); echo "  FAIL option set by a rejected call: $(opt)"; fi

echo "-- one authenticated call flips the row --"
req GET /status "$EDITOR"
check_http "editor GET /status is 200" 200 "$RESP_HTTP" "$RESP_BODY"
if [[ "$(opt | jq -r .user)" == "ccc_editor" ]]; then PASS=$((PASS+1)); echo "  ok   option records user ccc_editor"; else FAIL=$((FAIL+1)); echo "  FAIL option: $(opt)"; fi
if [[ "$(opt | jq -r .route)" == *crawlcove* ]]; then PASS=$((PASS+1)); echo "  ok   option records the crawlcove route"; else FAIL=$((FAIL+1)); echo "  FAIL route: $(opt)"; fi
AUTOLOAD="$(wp eval 'global $wpdb; echo $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = \"crawlcove_last_connection\"" );' 2>/dev/null)"
if [[ "$AUTOLOAD" == "no" || "$AUTOLOAD" == "off" ]]; then PASS=$((PASS+1)); echo "  ok   option is not autoloaded"; else FAIL=$((FAIL+1)); echo "  FAIL option autoload flag is '$AUTOLOAD', not no/off"; fi
if page | grep -q 'Last connected.*ccc_editor'; then PASS=$((PASS+1)); echo "  ok   Tools page says Last connected ... by ccc_editor"; else FAIL=$((FAIL+1)); echo "  FAIL Tools page missing Last connected row"; fi

wp option delete crawlcove_last_connection --quiet 2>/dev/null || true
summary
