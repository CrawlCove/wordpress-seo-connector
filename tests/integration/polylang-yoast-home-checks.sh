#!/usr/bin/env bash
#
# Real per-language "your latest posts" homepage title/description —
# a real WordPress + real Yoast SEO + real Polylang, directory-based URL
# mode (default language at the root, other languages under /xx/).
#
# WHY THIS EXISTS: polylang-checks.sh (Rank Math) proved the SAFETY fix
# (a language-prefixed homepage URL like "/fr/" never misresolves to
# Polylang's own internal "language" taxonomy) and documented that a
# non-default language's "latest posts" homepage was honestly
# ccc_unresolvable — a real but narrow gap, not silently wrong. This harness
# proves the FEATURE that closes that gap, for the one adapter it is real
# for: Yoast SEO has a dedicated Polylang compatibility module
# (integrations/wpseo/wpseo.php, verified against Polylang 3.x source) that
# registers `title-home-wpseo`/`metadesc-home-wpseo` with Polylang's own
# string-translation system (PLL_Translate_Option -> PLL_MO). Rank Math,
# SEOPress and AIOSEO have no such module — their homepage title is one
# shared value site-wide, independent of the active language, so there is
# nothing per-language to target no matter what CCC does; CCC now says so
# explicitly (ccc_language_unsupported) instead of staying silent.
#
# The write mechanism (CCC_Adapter::set_home_field()'s lang branch) is NOT
# Polylang's own `@api`-tagged surface — there is no public
# "set this string's translation for language X" function — it is the exact
# internal mechanism Polylang's OWN "Strings translation" admin screen uses
# (src/settings/table-string.php: save_translations(), and
# PLL_Translate_Option::update_option(), which is how Yoast's own
# default-language save already preserves every other language's
# translation, verified separately in polylang-checks.sh's sibling
# investigation). Proven here end-to-end across SEPARATE HTTP requests
# (write via one REST call, read back via a later one) — not just read from
# source — because this mechanism has no unit-testable real behaviour
# (tests/bootstrap.php's PLL_MO stand-in proves CCC_Service/CCC_Adapter's
# OWN logic, not that Polylang's real class behaves the way the stub
# assumes).
#
# Self-contained harness (own site dir/port), like polylang-checks.sh
# itself — NOT wired into run.sh. Run manually:
# tests/integration/polylang-yoast-home-checks.sh
# Requires tests/integration/.cache/{wordpress,sqlite,yoast,polylang}.zip and
# wp-cli.phar (see check-versions.sh for the wordpress.org download pattern).

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-pll-yoast"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8992
URL="http://127.0.0.1:$PORT"
SERVER_PID=""

log() { echo "[polylang-yoast-home-checks] $*"; }

cleanup() {
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

for f in wordpress.zip sqlite.zip yoast.zip wp-cli.phar polylang.zip; do
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
unzip -q "$CACHE/yoast.zip" -d "$SITE/wp-content/plugins"
unzip -q "$CACHE/polylang.zip" -d "$SITE/wp-content/plugins"
cp "$SITE/wp-content/plugins/sqlite-database-integration/db.copy" "$SITE/wp-content/db.php"
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
"${WPCLI[@]}" core install --path="$SITE" --url="$URL" --title="CCC Polylang+Yoast Home Check" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
"${WPCLI[@]}" rewrite structure '/%postname%/' --path="$SITE" --quiet
"${WPCLI[@]}" plugin activate sqlite-database-integration crawl-cove-connector wordpress-seo polylang --path="$SITE" --quiet

log "configuring Polylang: en (default, no prefix) + fr, directory URL mode, 'your latest posts' homepage"
"${WPCLI[@]}" eval '
PLL()->model->add_language( array( "locale" => "en_US", "slug" => "en", "name" => "English", "rtl" => 0, "term_group" => 0 ) );
PLL()->model->add_language( array( "locale" => "fr_FR", "slug" => "fr", "name" => "French", "rtl" => 0, "term_group" => 1 ) );
PLL()->model->update_default_lang( "en" );
$opts = PLL()->options;
$opts["force_lang"]   = 1;
$opts["hide_default"] = 1;
update_option( "polylang", $opts->get_all() );
' --path="$SITE"
"${WPCLI[@]}" eval 'flush_rewrite_rules();' --path="$SITE"
"${WPCLI[@]}" option update wpseo_titles '{"title-home-wpseo":"English Home Title","metadesc-home-wpseo":"English home description"}' --format=json --path="$SITE" >/dev/null

log "admin user + application password"
ADMIN_PW="$("${WPCLI[@]}" user application-password create admin ccc-test --porcelain --path="$SITE")"

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server-pll-yoast.log" 2>&1 ) &
SERVER_PID=$!
UP=0
for i in $(seq 1 30); do
  if curl -s -o /dev/null "$URL/index.php?rest_route=/"; then UP=1; break; fi
  sleep 0.3
done
if [[ "$UP" -ne 1 ]]; then
  echo "php -S never came up — see $HERE/.server-pll-yoast.log" >&2
  exit 1
fi

BASE="$URL/index.php?rest_route=/crawlcove/v1"
AUTH="admin:$ADMIN_PW"
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
  local method="$1" path="$2" data="${3:-}"
  local out
  local -a curlargs=(-s -X "$method" "$BASE$path" -w '\n%{http_code}' --max-time 10 -u "$AUTH")
  [[ -n "$data" ]] && curlargs+=(-H 'Content-Type: application/json' --data-binary "$data")
  out="$(curl "${curlargs[@]}")"
  RESP_HTTP="$(echo "$out" | tail -1)"
  RESP_BODY="$(echo "$out" | sed '$d')"
}

echo "-- the gap this feature closes --"
req POST /resolve "{\"urls\":[\"$URL/fr/\"]}"
check "resolve: French homepage URL (/fr/) is HOME_ID, not unresolvable" 200 "$RESP_HTTP" '.[0].post_id' '0' "$RESP_BODY"
check "resolve: ...and its lang is reported as fr" 200 "$RESP_HTTP" '.[0].lang' 'fr' "$RESP_BODY"
check "resolve: ...with the current (untranslated-yet) title falling back to the English original, same as Yoast's own live rendering" 200 "$RESP_HTTP" '.[0].current.title' 'English Home Title' "$RESP_BODY"

echo "-- default-language homepage is unaffected --"
req POST /resolve "{\"urls\":[\"$URL/\"]}"
check "resolve: site root is still the plain homepage, lang empty" 200 "$RESP_HTTP" '.[0].lang' '' "$RESP_BODY"

echo "-- writing the French title --"
req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":\"fr\",\"title\":\"Titre Accueil Francais\",\"description\":\"Description francaise\"}]}"
check "apply: French homepage title/description write ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
CHANGE_ID="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"

req POST /resolve "{\"urls\":[\"$URL/fr/\"]}"
check "resolve: French homepage now reads back the just-written title" 200 "$RESP_HTTP" '.[0].current.title' 'Titre Accueil Francais' "$RESP_BODY"

req POST /resolve "{\"urls\":[\"$URL/\"]}"
check "resolve: the ENGLISH homepage title is untouched by the French-only write" 200 "$RESP_HTTP" '.[0].current.title' 'English Home Title' "$RESP_BODY"

WPSEO_RAW="$("${WPCLI[@]}" option get wpseo_titles --format=json --path="$SITE" | jq -r '.["title-home-wpseo"]')"
if [[ "$WPSEO_RAW" == "English Home Title" ]]; then
  PASS=$((PASS+1)); echo "  ok   the raw (default-language) wpseo_titles option itself was never overwritten by the French write"
else
  FAIL=$((FAIL+1)); echo "  FAIL raw wpseo_titles option was mutated by a French-only write — got '$WPSEO_RAW'"
fi

echo "-- reverting the French title --"
req POST /revert "{\"change_id\":$CHANGE_ID}"
check "revert: ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"
req POST /resolve "{\"urls\":[\"$URL/fr/\"]}"
check "resolve: French homepage title reverted to its pre-write value (the English original, its unset fallback)" 200 "$RESP_HTTP" '.[0].current.title' 'English Home Title' "$RESP_BODY"

echo "-- a change to the DEFAULT language's title does not clobber the French translation Polylang keeps of it --"
req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":\"fr\",\"title\":\"Nouveau Titre\"}]}"
req POST /apply "{\"changes\":[{\"post_id\":0,\"title\":\"A New English Title\"}]}"
check "apply: default-language title write ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
req POST /resolve "{\"urls\":[\"$URL/fr/\"]}"
check "resolve: French translation reattached to the new English original (Polylang's own PLL_Translate_Option behaviour, not CCC's)" 200 "$RESP_HTTP" '.[0].current.title' 'Nouveau Titre' "$RESP_BODY"

echo "-- lang field fuzzing (never added to security-checks.sh's matrix when lang shipped in v0.9.0) --"
req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":123,\"title\":\"x\"}]}"
check "apply: numeric lang -> ccc_bad_value, no crash" 200 "$RESP_HTTP" '.[0].error' 'ccc_bad_value' "$RESP_BODY"

req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":[\"fr\"],\"title\":\"x\"}]}"
check "apply: array lang -> ccc_bad_value, no crash" 200 "$RESP_HTTP" '.[0].error' 'ccc_bad_value' "$RESP_BODY"

req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":{\"a\":1},\"title\":\"x\"}]}"
check "apply: object lang -> ccc_bad_value, no crash" 200 "$RESP_HTTP" '.[0].error' 'ccc_bad_value' "$RESP_BODY"

req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":true,\"title\":\"x\"}]}"
check "apply: boolean lang -> ccc_bad_value, no crash" 200 "$RESP_HTTP" '.[0].error' 'ccc_bad_value' "$RESP_BODY"

req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":\"FR\",\"title\":\"x\"}]}"
check "apply: case-mismatched lang ('FR' vs real 'fr') is not silently accepted -> ccc_no_such_language" 200 "$RESP_HTTP" '.[0].error' 'ccc_no_such_language' "$RESP_BODY"

req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":\" fr\",\"title\":\"x\"}]}"
check "apply: whitespace-padded lang is not silently trimmed-and-accepted -> ccc_no_such_language" 200 "$RESP_HTTP" '.[0].error' 'ccc_no_such_language' "$RESP_BODY"

req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":\"'; DROP TABLE wp_options; --\",\"title\":\"x\"}]}"
check "apply: SQL-injection-shaped lang -> ccc_no_such_language, no crash" 200 "$RESP_HTTP" '.[0].error' 'ccc_no_such_language' "$RESP_BODY"

LANG_10K="$(python3 -c "print('f'*10000)")"
req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":\"$LANG_10K\",\"title\":\"x\"}]}"
check "apply: 10k-char lang doesn't crash/timeout, and is rejected as ccc_no_such_language" 200 "$RESP_HTTP" '.[0].error' 'ccc_no_such_language' "$RESP_BODY"

req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":\"fr\\u0000\",\"title\":\"x\"}]}"
check "apply: null-byte-suffixed lang doesn't crash, and is rejected as ccc_no_such_language, not treated as plain 'fr'" 200 "$RESP_HTTP" '.[0].error' 'ccc_no_such_language' "$RESP_BODY"

echo "-- a valid lang combined with an injection-shaped title exercises the OTHER write mechanism (PLL_MO), not just update_option() --"
req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":\"fr\",\"title\":\"<script>alert(1)</script>\"}]}"
check "apply: script-tag title via the per-language (PLL_MO) path is accepted at HTTP level (sanitized, not blocked)" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
STORED_HAS_SCRIPT="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.to' | grep -c '<script' || true)"
if [[ "$STORED_HAS_SCRIPT" == "0" ]]; then
  PASS=$((PASS+1)); echo "  ok   apply: sanitize_text_field stripped the <script> tag before the PLL_MO write too"
else
  FAIL=$((FAIL+1)); echo "  FAIL apply: raw <script> tag reached the PLL_MO-backed storage — stored value: $(echo "$RESP_BODY" | jq -r '.[0].applied.title.to')"
fi
FR_CHANGE_ID="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"
req POST /revert "{\"change_id\":$FR_CHANGE_ID}"
check "revert: cleans up the fuzzed French title" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"

echo "-- an adapter with no Polylang integration says so explicitly --"
"${WPCLI[@]}" plugin deactivate wordpress-seo --path="$SITE" --quiet
unzip -q "$CACHE/rankmath.zip" -d "$SITE/wp-content/plugins" 2>/dev/null || true
if [[ -f "$CACHE/rankmath.zip" ]]; then
  "${WPCLI[@]}" plugin activate seo-by-rank-math --path="$SITE" --quiet
  req POST /apply "{\"changes\":[{\"post_id\":0,\"lang\":\"fr\",\"title\":\"X\"}]}"
  check "apply: Rank Math has no per-language homepage storage — explicit ccc_language_unsupported, not a silent shared-value write" 200 "$RESP_HTTP" '.[0].error' 'ccc_language_unsupported' "$RESP_BODY"
else
  echo "  skip Rank Math cross-adapter check (.cache/rankmath.zip not present)"
fi

echo
echo "== $PASS passed, $FAIL failed =="
[[ $FAIL -eq 0 ]]
