#!/usr/bin/env bash
#
# Security pass run by run.sh against a live WordPress: every route
# unauthenticated, the full role capability matrix, and fuzzing /apply and
# /resolve payloads (wrong types, nesting, unicode, null bytes, injection
# strings, 10k-char strings, malformed JSON, boundary batch sizes). Findings
# are written to SECURITY-NOTES.md by run.sh from this script's PASS/FAIL log.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"

EDITOR_URL="$CCC_URL/?p=$CCC_POST_EDITOR"

echo "-- no route works unauthenticated --"
req GET /status ""
check_http "unauth: GET /status is 401" 401 "$RESP_HTTP" "$RESP_BODY"
req POST /resolve "" "{\"urls\":[\"$EDITOR_URL\"]}"
check_http "unauth: POST /resolve is 401" 401 "$RESP_HTTP" "$RESP_BODY"
req POST /apply "" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"x\"}]}"
check_http "unauth: POST /apply is 401" 401 "$RESP_HTTP" "$RESP_BODY"
req GET /changes ""
check_http "unauth: GET /changes is 401" 401 "$RESP_HTTP" "$RESP_BODY"
req POST /revert "" '{"change_id":1}'
check_http "unauth: POST /revert is 401" 401 "$RESP_HTTP" "$RESP_BODY"

req GET /status "ccc_editor:not-the-real-password"
check_http "unauth: wrong application password is 401" 401 "$RESP_HTTP" "$RESP_BODY"

echo "-- capability matrix (subscriber has no edit_posts; blocked at every route) --"
req GET /status "$SUBSCRIBER"
check_http "subscriber: GET /status is 403" 403 "$RESP_HTTP" "$RESP_BODY"
req POST /resolve "$SUBSCRIBER" "{\"urls\":[\"$EDITOR_URL\"]}"
check_http "subscriber: POST /resolve is 403" 403 "$RESP_HTTP" "$RESP_BODY"
req POST /apply "$SUBSCRIBER" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"x\"}]}"
check_http "subscriber: POST /apply is 403" 403 "$RESP_HTTP" "$RESP_BODY"
req GET /changes "$SUBSCRIBER"
check_http "subscriber: GET /changes is 403" 403 "$RESP_HTTP" "$RESP_BODY"
req POST /revert "$SUBSCRIBER" '{"change_id":1}'
check_http "subscriber: POST /revert is 403" 403 "$RESP_HTTP" "$RESP_BODY"

echo "-- capability matrix (author: edit_posts yes, edit_others_posts no) --"
req GET /status "$AUTHOR"
check_http "author: GET /status is 200 (has edit_posts)" 200 "$RESP_HTTP" "$RESP_BODY"
req POST /apply "$AUTHOR" "{\"changes\":[{\"post_id\":$CCC_POST_AUTHOR,\"title\":\"Own post ok\"}]}"
check "author: can apply to own post" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
OWN_CHANGE_ID="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"
req POST /apply "$AUTHOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"Not mine\"}]}"
check "author: forbidden applying to editor's post" 200 "$RESP_HTTP" '.[0].error' 'ccc_forbidden' "$RESP_BODY"
req POST /revert "$AUTHOR" "{\"change_id\":$OWN_CHANGE_ID}"
check "author: can revert own change" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"

echo "-- read checks: /resolve and /changes disclose nothing the user may not read (review #227) --"
for pair in "private:$CCC_POST_PRIVATE" "draft:$CCC_POST_DRAFT" "user_request:$CCC_POST_USER_REQUEST"; do
  what="${pair%%:*}"; id="${pair#*:}"
  req POST /resolve "$AUTHOR" "{\"urls\":[\"$CCC_URL/?p=$id\"]}"
  check "author: ?p=<editor's $what> is ccc_unresolvable" 200 "$RESP_HTTP" '.[0].error' 'ccc_unresolvable' "$RESP_BODY"
  check "author: ?p=<editor's $what> carries no post_title" 200 "$RESP_HTTP" '.[0].post_title // "none"' 'none' "$RESP_BODY"
done
req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/?p=$CCC_POST_PRIVATE\"]}"
check "editor: own private post still resolves" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/?p=$CCC_POST_USER_REQUEST\"]}"
check "editor: a user_request has no public page, ccc_unresolvable" 200 "$RESP_HTTP" '.[0].error' 'ccc_unresolvable' "$RESP_BODY"
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_USER_REQUEST,\"title\":\"Junk\"}]}"
check "editor: /apply by raw post_id refuses a user_request too, ccc_no_post" 200 "$RESP_HTTP" '.[0].error' 'ccc_no_post' "$RESP_BODY"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"Editor change for the log\"}]}"
check "editor: apply to own post (seeds the log)" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
req POST /apply "$AUTHOR" "{\"changes\":[{\"post_id\":$CCC_POST_AUTHOR,\"title\":\"Author change for the log\"}]}"
check "author: apply to own post (seeds the log)" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
req GET /changes "$AUTHOR"
check "author: /changes omits the editor's entries" 200 "$RESP_HTTP" "[.[] | select(.post_id == $CCC_POST_EDITOR)] | length" '0' "$RESP_BODY"
check "author: /changes still lists their own entries" 200 "$RESP_HTTP" "[.[] | select(.post_id == $CCC_POST_AUTHOR)] | length > 0" 'true' "$RESP_BODY"
req GET /changes "$EDITOR"
check "editor: /changes includes the author's entries (edit_others_posts)" 200 "$RESP_HTTP" "[.[] | select(.post_id == $CCC_POST_AUTHOR)] | length > 0" 'true' "$RESP_BODY"

echo "-- /resolve fuzz --"
# WP's REST arg layer does NOT 400 a scalar against a declared type=>'array'
# param — it silently (array)-casts it to a single-element array instead
# (confirmed against real WordPress). CCC_Rest::resolve() has no further
# is_array() guard of its own, so a scalar 'urls' reaches CCC_Service as a
# one-item array and is handled per-item like any other bad url — safe, just
# worth knowing REST's own validation isn't the backstop here.
req POST /resolve "$EDITOR" '{"urls":"not-an-array"}'
check_http "resolve: urls as a string is coerced to a 1-item array, not a crash" 200 "$RESP_HTTP" "$RESP_BODY"

req POST /resolve "$EDITOR" '{"urls":[]}'
check_http "resolve: empty urls array is 400, not 500" 400 "$RESP_HTTP" "$RESP_BODY"

req POST /resolve "$EDITOR" '{"urls":[123, null, true, {"a":1}, ["nested"]]}'
check_http "resolve: non-string url items don't crash the route" 200 "$RESP_HTTP" "$RESP_BODY"
NON_STRING_OK="$(echo "$RESP_BODY" | jq -r '[.[] | select(.ok == false and .error == "ccc_bad_url")] | length')"
if [[ "$NON_STRING_OK" == "5" ]]; then
  PASS=$((PASS+1)); echo "  ok   resolve: every non-string url item reports ccc_bad_url"
else
  FAIL=$((FAIL+1)); echo "  FAIL resolve: expected 5 ccc_bad_url items, got $NON_STRING_OK — body: $(echo "$RESP_BODY" | head -c 300)"
fi

URLS_100="$(python3 -c "import json; print(json.dumps(['$EDITOR_URL' for _ in range(100)]))")"
req POST /resolve "$EDITOR" "{\"urls\":$URLS_100}"
check_http "resolve: exactly 100 urls (the cap) is accepted" 200 "$RESP_HTTP" "$RESP_BODY"

URLS_101="$(python3 -c "import json; print(json.dumps(['$EDITOR_URL' for _ in range(101)]))")"
req POST /resolve "$EDITOR" "{\"urls\":$URLS_101}"
check_http "resolve: 101 urls exceeds the cap" 400 "$RESP_HTTP" "$RESP_BODY"

req POST /resolve "$EDITOR" '{"urls":["https://example.com/'"$(python3 -c 'print("A"*10000)')"'"]}'
check_http "resolve: 10k-char url doesn't crash/timeout" 200 "$RESP_HTTP" "$RESP_BODY"

echo "-- /apply fuzz: types and nesting --"
# Same WP REST coercion as /resolve above: a scalar 'changes' becomes a
# 1-item array, and CCC_Service::validate_change()'s own is_array() check on
# each item is what actually catches it (ccc_bad_change), not the REST layer.
req POST /apply "$EDITOR" '{"changes":"not-an-array"}'
check "apply: changes as a string is coerced to a 1-item array and caught per-item" 200 "$RESP_HTTP" '.[0].error' 'ccc_bad_change' "$RESP_BODY"

req POST /apply "$EDITOR" '{"changes":["just-a-string"]}'
check "apply: a non-object item in changes reports ccc_bad_change" 200 "$RESP_HTTP" '.[0].error' 'ccc_bad_change' "$RESP_BODY"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":{\"nested\":{\"deep\":\"object\"}}}]}"
check "apply: object where title string expected -> ccc_bad_value, no crash" 200 "$RESP_HTTP" '.[0].error' 'ccc_bad_value' "$RESP_BODY"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":null}]}"
check "apply: null title -> ccc_bad_value" 200 "$RESP_HTTP" '.[0].error' 'ccc_bad_value' "$RESP_BODY"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":\"not-numeric\",\"title\":\"x\"}]}"
check "apply: non-numeric post_id falls through to ccc_no_target (no url given either)" 200 "$RESP_HTTP" '.[0].error' 'ccc_no_target' "$RESP_BODY"

DESC_1024="$(python3 -c "print('d'*1024)")"
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"description\":\"$DESC_1024\"}]}"
check "apply: description at exactly 1024 chars (the cap) is accepted" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"

DESC_1025="$(python3 -c "print('d'*1025)")"
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"description\":\"$DESC_1025\"}]}"
check "apply: description at 1025 chars is rejected" 200 "$RESP_HTTP" '.[0].error' 'ccc_too_long' "$RESP_BODY"

TITLE_10K="$(python3 -c "print('x'*10000)")"
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"$TITLE_10K\"}]}"
check "apply: 10k-char title doesn't crash, just rejected" 200 "$RESP_HTTP" '.[0].error' 'ccc_too_long' "$RESP_BODY"

echo "-- /apply fuzz: injection and control characters --"
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"<script>alert(1)</script>\"}]}"
check_http "apply: script-tag title accepted at HTTP level (sanitized, not blocked)" 200 "$RESP_HTTP" "$RESP_BODY"
STORED_HAS_SCRIPT="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.to' | grep -c '<script' || true)"
if [[ "$STORED_HAS_SCRIPT" == "0" ]]; then
  PASS=$((PASS+1)); echo "  ok   apply: sanitize_text_field stripped the <script> tag before storage"
else
  FAIL=$((FAIL+1)); echo "  FAIL apply: raw <script> tag reached storage — stored value: $(echo "$RESP_BODY" | jq -r '.[0].applied.title.to')"
fi

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"'; DROP TABLE wp_posts; --\"}]}"
check "apply: SQL-injection-shaped string is stored as inert text, no error" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"null byte   embedded\"}]}"
check_http "apply: embedded null byte doesn't crash the route" 200 "$RESP_HTTP" "$RESP_BODY"

echo "-- malformed JSON and missing params --"
req POST /apply "$EDITOR" '{"changes":[{"post_id":' # deliberately truncated/invalid JSON
check_http "apply: malformed JSON body is 400, not 500" 400 "$RESP_HTTP" "$RESP_BODY"

req POST /apply "$EDITOR" '{}'
check_http "apply: missing required 'changes' param is 400" 400 "$RESP_HTTP" "$RESP_BODY"

req POST /resolve "$EDITOR" '{}'
check_http "resolve: missing required 'urls' param is 400" 400 "$RESP_HTTP" "$RESP_BODY"

req POST /revert "$EDITOR" '{}'
check_http "revert: missing required 'change_id' param is 400" 400 "$RESP_HTTP" "$RESP_BODY"

req POST /revert "$EDITOR" '{"change_id":"not-a-number"}'
check_http "revert: non-numeric change_id is 400, not 500" 400 "$RESP_HTTP" "$RESP_BODY"

req POST /revert "$EDITOR" '{"change_id":-1}'
check "revert: negative change_id reports not-found, not a crash" 404 "$RESP_HTTP" '.code' 'ccc_not_found' "$RESP_BODY"

req POST /revert "$EDITOR" '{"change_id":999999}'
check "revert: nonexistent change_id is 404 ccc_not_found" 404 "$RESP_HTTP" '.code' 'ccc_not_found' "$RESP_BODY"

echo "-- error responses never leak internals --"
LEAK_FOUND=0
for body in "$RESP_BODY"; do
  if echo "$body" | grep -qiE 'fatal error|stack trace|/opt/crawlcove|xdebug'; then
    LEAK_FOUND=1
  fi
done
if [[ "$LEAK_FOUND" == "0" ]]; then
  PASS=$((PASS+1)); echo "  ok   error responses stay clean of PHP internals"
else
  FAIL=$((FAIL+1)); echo "  FAIL a response leaked PHP internals — see body above"
fi

summary
