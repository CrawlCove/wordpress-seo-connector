#!/usr/bin/env bash
#
# TranslatePress (multilingual) compatibility verification — a real
# WordPress + real Yoast SEO + real TranslatePress free edition.
#
# WHY THIS EXISTS: Polylang and WPML both create genuinely separate
# per-language storage (Polylang: a real, separate post_id per translation,
# plus a "language" taxonomy with its own rewrite rule that once collided
# with CCC's own resolution — see polylang-checks.sh). TranslatePress is
# architecturally the opposite: verified against its own source
# (includes/queries/class-gettext-table-creation.php) that it has no
# `add_rewrite_rule()` calls anywhere in the plugin at all — a language
# like "/fr/" is not a WordPress rewrite rule, it's string surgery
# (includes/class-url-converter.php) that TranslatePress resolves and
# strips BEFORE WordPress's own URL-to-post_id matching ever runs. There is
# no separate post per language and no separate SEO-plugin storage per
# language to target — a French visitor and an English visitor read the
# exact SAME post_id's SAME Yoast/Rank Math postmeta, with TranslatePress
# translating the rendered STRING at output time via its own gettext-style
# dictionary tables (keyed on the literal original string text).
#
# CONCRETE PREDICTION FROM THAT READING, TESTED HERE FOR REAL rather than
# assumed (LESSONS.md: "reading the code is not running the code"): a
# language-prefixed URL for any post, and for the homepage, resolves to the
# SAME post_id/HOME_ID as its unprefixed counterpart — no Polylang-style
# phantom taxonomy match is even possible, because there is no taxonomy or
# rewrite rule involved at all. And a CCC title/description write (no
# `lang` field — there is nothing per-language for it to mean here) is
# visible identically regardless of which language URL resolved it, because
# it is legitimately the same value.
#
# ONE CONSEQUENCE DOCUMENTED, NOT FIXED: because TranslatePress's
# translation lookup is keyed on the literal original string, ANY edit to
# that string — from CCC, or from a site owner clicking save in Yoast's own
# metabox, there is no difference — orphans whatever translation already
# existed for the old string until a human re-translates it in TranslatePress's
# editor. This is TranslatePress's own designed behaviour for any edit
# source, not a CCC-introduced gap, so nothing to fix; noted in
# SECURITY-NOTES.md's addendum for this session so it's not silently
# rediscovered as "a bug" later.
#
# Self-contained harness (own site dir/port), like polylang-checks.sh
# itself — NOT wired into run.sh. Run manually:
# tests/integration/translatepress-checks.sh
# Requires tests/integration/.cache/translatepress.zip (see check-versions.sh
# for the wordpress.org download pattern; check-versions.sh does not track
# this one yet since TranslatePress isn't one of the four SEO adapters).

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-trp"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8993
URL="http://127.0.0.1:$PORT"
SERVER_PID=""

log() { echo "[translatepress-checks] $*"; }

cleanup() {
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

for f in wordpress.zip sqlite.zip yoast.zip wp-cli.phar translatepress.zip; do
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
unzip -q "$CACHE/translatepress.zip" -d "$SITE/wp-content/plugins"
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
"${WPCLI[@]}" core install --path="$SITE" --url="$URL" --title="CCC TranslatePress Check" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
"${WPCLI[@]}" rewrite structure '/%postname%/' --path="$SITE" --quiet
"${WPCLI[@]}" plugin activate sqlite-database-integration crawl-cove-connector wordpress-seo translatepress-multilingual --path="$SITE" --quiet

log "configuring TranslatePress: en_US (default, no prefix) + fr_FR under /fr/"
"${WPCLI[@]}" eval '
$trp        = TRP_Translate_Press::get_trp_instance();
$settings_c = $trp->get_component( "settings" );
$sanitized  = $settings_c->sanitize_settings( array(
	"default-language"       => "en_US",
	"translation-languages"  => array( "en_US", "fr_FR" ),
	"publish-languages"      => array( "en_US", "fr_FR" ),
	"url-slugs"              => array( "fr_FR" => "fr" ),
) );
update_option( "trp_settings", $sanitized );
' --path="$SITE"

log "an English post + editor user + application password"
POST_ID="$("${WPCLI[@]}" post create --post_title="CCC Test Article" --post_name=ccc-test-article --post_status=publish --porcelain --path="$SITE")"
"${WPCLI[@]}" user create ccc_editor editor@example.com --role=editor --user_pass=editor-pass --path="$SITE" --quiet
EDITOR_PW="$("${WPCLI[@]}" user application-password create ccc_editor ccc-test --porcelain --path="$SITE")"

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server-trp.log" 2>&1 ) &
SERVER_PID=$!
UP=0
for i in $(seq 1 30); do
  if curl -s -o /dev/null "$URL/index.php?rest_route=/"; then UP=1; break; fi
  sleep 0.3
done
if [[ "$UP" -ne 1 ]]; then
  echo "php -S never came up — see $HERE/.server-trp.log" >&2
  exit 1
fi

BASE="$URL/index.php?rest_route=/crawlcove/v1"
AUTH="ccc_editor:$EDITOR_PW"
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

echo "-- sanity: TranslatePress really did generate a /fr/ URL space, not silently no-op --"
FR_URL_CHECK="$(curl -s -o /dev/null -w '%{http_code}' "$URL/fr/ccc-test-article/")"
if [[ "$FR_URL_CHECK" == "200" ]]; then
  PASS=$((PASS+1)); echo "  ok   /fr/ccc-test-article/ serves 200 (TranslatePress's language routing is live)"
else
  FAIL=$((FAIL+1)); echo "  FAIL /fr/ccc-test-article/ returned $FR_URL_CHECK — TranslatePress config didn't take, rest of this harness is untrustworthy"
fi

echo "-- the architectural question this harness exists to answer --"
req POST /resolve "{\"urls\":[\"$URL/ccc-test-article/\",\"$URL/fr/ccc-test-article/\"]}"
check "resolve: the default-language URL resolves to the real post" 200 "$RESP_HTTP" '.[0].post_id' "$POST_ID" "$RESP_BODY"
check "resolve: the /fr/-prefixed URL resolves to the SAME post_id — no Polylang-style phantom taxonomy/rewrite match, because TranslatePress strips its own prefix before WP's URL matching runs" 200 "$RESP_HTTP" '.[1].post_id' "$POST_ID" "$RESP_BODY"

echo "-- homepage: same question for the 'your latest posts' front page --"
req POST /resolve "{\"urls\":[\"$URL/\",\"$URL/fr/\"]}"
check "resolve: default-language homepage is HOME_ID" 200 "$RESP_HTTP" '.[0].post_id' '0' "$RESP_BODY"
check "resolve: /fr/ homepage is the SAME HOME_ID target, not ccc_unresolvable and not a phantom match" 200 "$RESP_HTTP" '.[1].post_id' '0' "$RESP_BODY"
check "resolve: ...and lang is empty — TranslatePress has no per-language SEO storage for CCC to report, unlike Polylang" 200 "$RESP_HTTP" '.[1].lang' '' "$RESP_BODY"

echo "-- a CCC write is visible identically from either language's URL, because it's genuinely the same value --"
req POST /apply "{\"changes\":[{\"post_id\":$POST_ID,\"title\":\"Title From CCC\"}]}"
check "apply: post title write ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"

req POST /resolve "{\"urls\":[\"$URL/ccc-test-article/\",\"$URL/fr/ccc-test-article/\"]}"
check "resolve: default-language URL reads back the new title" 200 "$RESP_HTTP" '.[0].current.title' 'Title From CCC' "$RESP_BODY"
check "resolve: /fr/ URL reads back the SAME title — same underlying post, same postmeta, nothing per-language to diverge" 200 "$RESP_HTTP" '.[1].current.title' 'Title From CCC' "$RESP_BODY"

echo
echo "== $PASS passed, $FAIL failed =="
[[ $FAIL -eq 0 ]]
