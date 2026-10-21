#!/usr/bin/env bash
#
# Polylang (multilingual) compatibility verification — a real WordPress +
# real Rank Math + real Polylang, directory-based URL mode (the default:
# default language at the root, other languages under /xx/).
#
# WHY THIS EXISTS: CCC_Term_Resolver::resolve_pretty_permalink() matches a
# URL against $wp_rewrite's compiled rules and accepts ANY resulting
# taxonomy-archive query (is_tax/is_category/is_tag), with no filter on
# whether that taxonomy is actually public — unlike its sibling
# resolve_plain_query_vars(), which only ever iterates
# get_taxonomies(['public' => true]). Polylang registers its own internal
# "language" taxonomy as public=false/publicly_queryable=true (so its
# language-switcher rewrite rule for the `lang` query var still works), and
# that rewrite rule matches a bare "/fr/" — exactly what a French "your
# latest posts" homepage URL looks like under Polylang's default directory
# mode. Before the fix this session, resolving "/fr/" silently returned
# Polylang's own "French" language TERM (post_id -5 in this harness) instead
# of ccc_unresolvable or the homepage: CCC would report a title/description
# "fix" as successfully applied, write it into Rank Math's term meta for that
# internal language term, and the REAL French homepage's rendered title would
# never change — a silent no-op reported as a success, the worst class of
# bug for a plugin whose whole point is "crawl, fix, push live, verify".
# resolve_pretty_permalink() now checks get_taxonomy($term->taxonomy)->public
# before accepting a match, the same criterion its sibling already used.
#
# SCOPE, DELIBERATELY: this proves the SAFETY fix (never misresolve to an
# internal, non-public taxonomy) and that ordinary translated content
# (Polylang posts/pages, each a real, separate post_id with its own postmeta)
# already works with zero code changes — nothing in CCC special-cases a post
# type, and Polylang's translations are genuinely separate post rows. Uses
# Rank Math specifically to ALSO prove that real per-language homepage
# support (added in a later session — see polylang-yoast-home-checks.sh)
# resolves "/fr/" to HOME_ID + lang=fr on ANY adapter (resolution is adapter-
# independent) while correctly reporting `editable: false` for one, Rank
# Math, with no Polylang integration at all (verified: only Yoast has a
# dedicated integrations/wpseo/ compat layer in Polylang's own source, which
# specifically registers wpseo_titles' title-home-wpseo/metadesc-home-wpseo
# for Polylang's string-translation system; Rank Math's homepage option has
# no such wrapping and stays one shared value across every language,
# independent of CCC) — an *apply* attempt gets ccc_language_unsupported, not
# a silent shared-value write. polylang-yoast-home-checks.sh proves the real
# read/write round-trip end-to-end, for the one adapter it is real for.
#
# Self-contained harness (own site dir/port), like woocommerce/multisite-
# checks.sh — NOT wired into run.sh. Run manually:
# tests/integration/polylang-checks.sh
# Requires tests/integration/.cache/polylang.zip (see check-versions.sh for
# the wordpress.org download pattern; check-versions.sh does not track this
# one yet since Polylang isn't one of the four SEO adapters).

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-pll"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8991
URL="http://127.0.0.1:$PORT"
SERVER_PID=""

log() { echo "[polylang-checks] $*"; }

cleanup() {
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

for f in wordpress.zip sqlite.zip rankmath.zip wp-cli.phar polylang.zip; do
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
unzip -q "$CACHE/rankmath.zip" -d "$SITE/wp-content/plugins"
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
"${WPCLI[@]}" core install --path="$SITE" --url="$URL" --title="CCC Polylang Check" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
"${WPCLI[@]}" rewrite structure '/%postname%/' --path="$SITE" --quiet
"${WPCLI[@]}" plugin activate sqlite-database-integration crawl-cove-connector seo-by-rank-math polylang --path="$SITE" --quiet

log "configuring Polylang: en (default, no prefix) + fr, directory URL mode"
"${WPCLI[@]}" eval '
PLL()->model->add_language( array( "locale" => "en_US", "slug" => "en", "name" => "English", "rtl" => 0, "term_group" => 0 ) );
PLL()->model->add_language( array( "locale" => "fr_FR", "slug" => "fr", "name" => "French", "rtl" => 0, "term_group" => 1 ) );
PLL()->model->update_default_lang( "en" );
$opts = PLL()->options;
$opts["force_lang"]    = 1; // Directory differentiation (e.g. /fr/), Polylangs own default.
$opts["hide_default"]  = 1; // Default language (en) has no URL prefix.
$opts["taxonomies"]    = array( "category" ); // Realistic site: categories are translatable, unlike Polylangs empty-by-default setting.
update_option( "polylang", $opts->get_all() );
' --path="$SITE"

# Polylang reads its "taxonomies" option once at plugins_loaded, before this
# same PHP process's update_option() above takes effect — flushing rewrite
# rules in that SAME eval call would bake in the OLD (pre-update) "which
# taxonomies are translated" snapshot, silently never generating the
# language-prefixed category archive rules. A separate wp-cli invocation is
# a fresh process that re-reads the now-persisted option correctly.
"${WPCLI[@]}" eval 'flush_rewrite_rules();' --path="$SITE"

log "editor user + application password"
"${WPCLI[@]}" user create ccc_editor editor@example.com --role=editor --user_pass=editor-pass --path="$SITE" --quiet
EDITOR_PW="$("${WPCLI[@]}" user application-password create ccc_editor ccc-test --porcelain --path="$SITE")"

log "an English post + its French translation, and an ordinary (untranslated) category"
EN_POST="$("${WPCLI[@]}" post create --post_title="English Post" --post_status=publish --porcelain --path="$SITE")"
FR_POST="$("${WPCLI[@]}" post create --post_title="French Post" --post_status=publish --porcelain --path="$SITE")"
"${WPCLI[@]}" eval "
PLL()->model->post->set_language( $EN_POST, 'en' );
PLL()->model->post->set_language( $FR_POST, 'fr' );
PLL()->model->post->save_translations( $EN_POST, array( 'en' => $EN_POST, 'fr' => $FR_POST ) );
" --path="$SITE"
NEWS_TERM_ID="$("${WPCLI[@]}" term create category News --slug=news --porcelain --path="$SITE")"

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server-pll.log" 2>&1 ) &
SERVER_PID=$!
UP=0
for i in $(seq 1 30); do
  if curl -s -o /dev/null "$URL/index.php?rest_route=/"; then UP=1; break; fi
  sleep 0.3
done
if [[ "$UP" -ne 1 ]]; then
  echo "php -S never came up — see $HERE/.server-pll.log" >&2
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

echo "-- the regression this harness pins --"
req POST /resolve "{\"urls\":[\"$URL/fr/\"]}"
check "resolve: French homepage URL (/fr/) is HOME_ID (lang fr), NOT Polylang's own 'language' term" 200 "$RESP_HTTP" '.[0].post_id' '0' "$RESP_BODY"
check "resolve: ...but not editable — Rank Math has no per-language homepage storage" 200 "$RESP_HTTP" '.[0].editable' 'false' "$RESP_BODY"

req POST /apply "{\"changes\":[{\"post_id\":-5,\"title\":\"Should not silently succeed\"}]}"
check "apply: a direct attempt on Polylang's internal language term is rejected (no such term to CCC — it's non-public, resolve_url() would never hand this out, but apply() is also asked directly here)" 200 "$RESP_HTTP" '.[0].ok' 'false' "$RESP_BODY"

echo "-- default-language homepage (no prefix) still resolves fine --"
req POST /resolve "{\"urls\":[\"$URL/\"]}"
check "resolve: site root (default language) is still the homepage target (post_id 0)" 200 "$RESP_HTTP" '.[0].post_id' '0' "$RESP_BODY"

echo "-- ordinary content taxonomy archives are unaffected by the public-taxonomy filter --"
req POST /resolve "{\"urls\":[\"$URL/category/news/\"]}"
check "resolve: an ordinary (public) category archive still resolves" 200 "$RESP_HTTP" '.[0].post_id' "-$NEWS_TERM_ID" "$RESP_BODY"

req POST /resolve "{\"urls\":[\"$URL/fr/category/news/\"]}"
check "resolve: the SAME category archive under the /fr/ language prefix still resolves too" 200 "$RESP_HTTP" '.[0].post_id' "-$NEWS_TERM_ID" "$RESP_BODY"

echo "-- translated posts are genuinely separate posts, isolated per language --"
req POST /resolve "{\"urls\":[\"$URL/?p=$EN_POST\",\"$URL/?p=$FR_POST\"]}"
check "resolve: the English post resolves to its own post_id" 200 "$RESP_HTTP" '.[0].post_id' "$EN_POST" "$RESP_BODY"
check "resolve: its French translation resolves to a DIFFERENT post_id" 200 "$RESP_HTTP" '.[1].post_id' "$FR_POST" "$RESP_BODY"

req POST /apply "{\"changes\":[{\"post_id\":$EN_POST,\"title\":\"EN Title From CCC\"}]}"
check "apply: English post title write ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"

EN_TITLE="$("${WPCLI[@]}" post meta get "$EN_POST" rank_math_title --path="$SITE")"
FR_TITLE="$("${WPCLI[@]}" post meta get "$FR_POST" rank_math_title --path="$SITE" 2>/dev/null)"
if [[ "$EN_TITLE" == "EN Title From CCC" && -z "$FR_TITLE" ]]; then
  PASS=$((PASS+1)); echo "  ok   writing the English post's title left its French translation's own postmeta untouched"
else
  FAIL=$((FAIL+1)); echo "  FAIL cross-language contamination — EN got '$EN_TITLE', FR got '$FR_TITLE' (expected FR empty)"
fi

echo
echo "== $PASS passed, $FAIL failed =="
[[ $FAIL -eq 0 ]]
