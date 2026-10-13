#!/usr/bin/env bash
#
# Review ticket #211: on a site where core has Application Passwords switched
# off (plain http, or HTTPS behind a proxy that hides the scheme), the plugin
# must say so instead of letting the desktop app's login die as a generic 401
# that looks like a wrong password. The harness site is exactly such a site
# once its mu-plugin filter (run.sh) is lifted, so this script lifts it, checks
# both surfaces, and puts it back. Needs a live WordPress: core's auth path and
# the admin render are the things under test.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"

: "${CCC_SITE:?}" "${CCC_CACHE:?}"
WPCLI=(php "$CCC_CACHE/wp-cli.phar")
wp() { "${WPCLI[@]}" "$@" --path="$CCC_SITE"; }
MU="$CCC_SITE/wp-content/mu-plugins/ccc-test-harness.php"

page() { wp eval 'require_once CCC_PLUGIN_DIR . "admin/class-ccc-admin.php"; CCC_Admin::render();' --user=1 2>&1 | tr '\n\t' '  ' | sed 's/<[^>]*>//g; s/  */ /g'; }

if [[ ! -f "$MU" ]]; then
  echo "  FAIL harness mu-plugin $MU is missing; cannot toggle Application Passwords" >&2
  FAIL=$((FAIL+1)); summary; exit 1
fi
restore() { [[ -f "$MU.off" ]] && mv "$MU.off" "$MU"; }
trap restore EXIT

echo "-- Application Passwords on (harness default) --"
if page | grep -q 'Application Passwords On'; then PASS=$((PASS+1)); echo "  ok   Tools page says Application Passwords On"; else FAIL=$((FAIL+1)); echo "  FAIL Tools page missing 'Application Passwords On'"; fi
req GET /status "$EDITOR"
check_http "editor GET /status is 200" 200 "$RESP_HTTP" "$RESP_BODY"
# A wrong password while passwords are ON must never get our code. Core
# answers incorrect_password when nothing determined the current user before
# its priority-90 reporter ran, and a bare rest_forbidden when something did
# (on this harness a plugin calls wp_get_current_user() at init, before
# REST_REQUEST exists, so the failure is recorded too late to be reported).
# Either way it is core's 401, which the app already reads as bad credentials.
req GET /status "ccc_editor:not-the-real-password"
check "wrong password stays core's own 401, not ours" 401 "$RESP_HTTP" '.code | IN("incorrect_password","rest_forbidden")' "true" "$RESP_BODY"

echo "-- Application Passwords off (filter lifted: plain-http site as core sees it) --"
mv "$MU" "$MU.off"
req GET /status "$EDITOR"
check "valid editor credentials: 401 ccc_app_passwords_unavailable" 401 "$RESP_HTTP" '.code' "ccc_app_passwords_unavailable" "$RESP_BODY"
check "message names HTTPS" 401 "$RESP_HTTP" '.message | contains("HTTPS")' "true" "$RESP_BODY"
req POST /apply "$EDITOR" '{"changes":[]}'
check "POST /apply gets the same named 401" 401 "$RESP_HTTP" '.code' "ccc_app_passwords_unavailable" "$RESP_BODY"
req GET /status ""
check "no credentials at all: same answer (the site cannot take any)" 401 "$RESP_HTTP" '.code' "ccc_app_passwords_unavailable" "$RESP_BODY"
PAGE="$(page)"
if grep -q 'Application Passwords Off' <<<"$PAGE"; then PASS=$((PASS+1)); echo "  ok   Tools page says Application Passwords Off"; else FAIL=$((FAIL+1)); echo "  FAIL Tools page missing 'Application Passwords Off'"; fi
if grep -q 'HTTP_X_FORWARDED_PROTO' <<<"$PAGE"; then PASS=$((PASS+1)); echo "  ok   Tools page carries the proxy hint"; else FAIL=$((FAIL+1)); echo "  FAIL Tools page missing the proxy hint"; fi
if grep -q 'wp_is_application_passwords_available' <<<"$PAGE"; then PASS=$((PASS+1)); echo "  ok   Tools page names the last-resort filter"; else FAIL=$((FAIL+1)); echo "  FAIL Tools page missing the filter name"; fi
# A logged-in admin's own cookie session must not be blocked by the guard:
# wp eval --user=1 is exactly that (current user set, no app password).
GUARD="$(wp eval 'var_export( CCC_App_Passwords::guard() );' --user=1 2>/dev/null)"
if [[ "$GUARD" == "true" ]]; then PASS=$((PASS+1)); echo "  ok   guard lets a logged-in user through while passwords are off"; else FAIL=$((FAIL+1)); echo "  FAIL guard for a logged-in user: $GUARD"; fi

echo "-- filter restored --"
restore
req GET /status "$EDITOR"
check_http "editor GET /status is 200 again" 200 "$RESP_HTTP" "$RESP_BODY"

summary
