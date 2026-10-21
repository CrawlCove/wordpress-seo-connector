#!/usr/bin/env bash
#
# WooCommerce compatibility verification — a real WordPress + real
# WooCommerce + real Rank Math, exercising CCC's REST routes against a
# WooCommerce PRODUCT (post_type=product) and a PRODUCT_CAT term.
#
# WHY THIS EXISTS: nothing in CCC_Service/CCC_Adapter/CCC_Term_Resolver
# special-cases a post type or a taxonomy — the code is generic (grep for
# post_type across includes/ finds nothing). That strongly implies
# WooCommerce products/categories already work with zero extra code, since
# WooCommerce products are "just" a custom post type and product_cat is
# "just" a public custom taxonomy, and Rank Math/Yoast/SEOPress store their
# title/description the same way for any post type or taxonomy. But a code
# read is not a test (see the "reading the code is not running the code"
# LESSONS entry, 5 Sept) — WooCommerce is a large, real-world plugin with
# its own template/query hooks that could plausibly interfere (e.g. its own
# canonical URL handling, or a shop page acting like a de facto archive).
# This proves the assumption instead of trusting it, using a real
# WooCommerce install, not a guess about its internals.
#
# Self-contained harness (own site dir/port), like plugin-check.sh — NOT
# wired into run.sh's per-adapter loop, because WooCommerce is a large
# (~18MB) dependency only relevant to this one question, not every adapter
# run. Run manually: tests/integration/woocommerce-checks.sh
# Requires tests/integration/.cache/woocommerce.zip (see check-versions.sh
# for the wordpress.org download pattern).
#
# EXPECT NOISE: WooCommerce's own `wc_get_attribute_taxonomies()`/webhook
# queries hit tables the SQLite drop-in's dbDelta translation doesn't create
# (wp_woocommerce_attribute_taxonomies, wp_wc_webhooks) and print harmless
# "WordPress database error" HTML during activation/boot. Unrelated to CCC —
# every check still ran against real WooCommerce post types/taxonomies/
# capabilities; MySQL-backed WordPress wouldn't hit this SQLite-only gap.
# FINDING (not a bug, a documented behavior): WooCommerce grants
# edit_products/edit_product_terms ONLY to shop_manager/administrator
# (verified against WC_Install::create_roles() and
# WC_Post_Types::register_taxonomies() source) — an Editor who can fix
# ordinary posts/pages is correctly ccc_forbidden on products/product_cat
# terms. Documented in README.md's WooCommerce section.

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-woo"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8989
URL="http://127.0.0.1:$PORT"
SERVER_PID=""

log() { echo "[woocommerce-checks] $*"; }

cleanup() {
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

for f in wordpress.zip sqlite.zip rankmath.zip wp-cli.phar woocommerce.zip; do
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
unzip -q "$CACHE/woocommerce.zip" -d "$SITE/wp-content/plugins"
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
"${WPCLI[@]}" core install --path="$SITE" --url="$URL" --title="CCC WooCommerce Check" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
"${WPCLI[@]}" plugin activate sqlite-database-integration crawl-cove-connector woocommerce seo-by-rank-math --path="$SITE" --quiet

log "editor + shop_manager users + application passwords"
"${WPCLI[@]}" user create ccc_editor editor@example.com --role=editor --user_pass=editor-pass --path="$SITE" --quiet
"${WPCLI[@]}" user create ccc_shopmgr shopmgr@example.com --role=shop_manager --user_pass=shopmgr-pass --path="$SITE" --quiet
EDITOR_PW="$("${WPCLI[@]}" user application-password create ccc_editor ccc-test --porcelain --path="$SITE")"
SHOPMGR_PW="$("${WPCLI[@]}" user application-password create ccc_shopmgr ccc-test --porcelain --path="$SITE")"

log "creating a WooCommerce product and a product_cat term"
PRODUCT_ID="$("${WPCLI[@]}" post create --post_type=product --post_title="Test Widget" --post_status=publish --porcelain --path="$SITE")"
"${WPCLI[@]}" post term add "$PRODUCT_ID" product_cat "Widgets" --path="$SITE" --quiet
CAT_TERM_ID="$("${WPCLI[@]}" term list product_cat --field=term_id --path="$SITE" | tail -1)"

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server-woo.log" 2>&1 ) &
SERVER_PID=$!
UP=0
for i in $(seq 1 30); do
  if curl -s -o /dev/null "$URL/index.php?rest_route=/"; then UP=1; break; fi
  sleep 0.3
done
if [[ "$UP" -ne 1 ]]; then
  echo "php -S never came up — see $HERE/.server-woo.log" >&2
  exit 1
fi

BASE="$URL/index.php?rest_route=/crawlcove/v1"
AUTH="ccc_shopmgr:$SHOPMGR_PW"
EDITOR_AUTH="ccc_editor:$EDITOR_PW"
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
  local method="$1" path="$2" data="${3:-}" auth="${4:-$AUTH}"
  local out
  local -a curlargs=(-s -X "$method" "$BASE$path" -w '\n%{http_code}' --max-time 10 -u "$auth")
  [[ -n "$data" ]] && curlargs+=(-H 'Content-Type: application/json' --data-binary "$data")
  out="$(curl "${curlargs[@]}")"
  RESP_HTTP="$(echo "$out" | tail -1)"
  RESP_BODY="$(echo "$out" | sed '$d')"
}

echo "-- WooCommerce product (post_type=product) --"
req POST /resolve "{\"urls\":[\"$URL/?p=$PRODUCT_ID\"]}"
check "resolve: product url resolves to its own post_id" 200 "$RESP_HTTP" '.[0].post_id' "$PRODUCT_ID" "$RESP_BODY"

# WooCommerce grants edit_products/edit_others_products ONLY to shop_manager
# and administrator (verified against WC_Install::create_roles() source) —
# NOT editor, unlike core posts/pages. WP core's edit_post meta capability
# maps through to the post type's OWN capability_type, so an Editor who can
# fix ordinary posts/pages is correctly forbidden here — this is WordPress
# core + WooCommerce's own capability model working as designed, not a CCC
# bug. Prove both halves: editor forbidden, shop_manager allowed.
req POST /apply "{\"changes\":[{\"post_id\":$PRODUCT_ID,\"title\":\"Should not apply\"}]}" "$EDITOR_AUTH"
check "apply: editor role (no edit_products) is correctly forbidden on a product" 200 "$RESP_HTTP" '.[0].error' 'ccc_forbidden' "$RESP_BODY"

req POST /apply "{\"changes\":[{\"post_id\":$PRODUCT_ID,\"title\":\"Widget — Buy Now\",\"description\":\"The best widget.\"}]}"
check "apply: shop_manager role (has edit_products) can write a product's title/description" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"

RM_TITLE="$("${WPCLI[@]}" post meta get "$PRODUCT_ID" rank_math_title --path="$SITE")"
if [[ "$RM_TITLE" == "Widget — Buy Now" ]]; then
  PASS=$((PASS+1)); echo "  ok   product title landed in Rank Math's real postmeta"
else
  FAIL=$((FAIL+1)); echo "  FAIL product title did not land in rank_math_title (got: $RM_TITLE)"
fi

CHANGE_ID_TITLE="$(curl -s -X GET "$BASE/changes" -u "$AUTH" | jq -r "[.[] | select(.post_id==$PRODUCT_ID and .field==\"title\")][0].id")"
req POST /revert "{\"change_id\":$CHANGE_ID_TITLE}"
check "revert: product title reverts ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"

echo "-- WooCommerce category (product_cat term) --"
req POST /resolve "{\"urls\":[\"$URL/?product_cat=widgets\"]}"
check "resolve: ?product_cat=slug maps to the term (post_id -$CAT_TERM_ID)" 200 "$RESP_HTTP" '.[0].post_id' "-$CAT_TERM_ID" "$RESP_BODY"

# Same story as the product itself: product_cat is registered with its own
# term capabilities (edit_product_terms, manage_product_terms — verified
# against WC_Post_Types::register_taxonomies() source), granted only to
# shop_manager/administrator, not editor.
req POST /apply "{\"changes\":[{\"post_id\":-$CAT_TERM_ID,\"title\":\"Should not apply\"}]}" "$EDITOR_AUTH"
check "apply: editor role (no edit_product_terms) is correctly forbidden on a product_cat term" 200 "$RESP_HTTP" '.[0].error' 'ccc_forbidden' "$RESP_BODY"

req POST /apply "{\"changes\":[{\"post_id\":-$CAT_TERM_ID,\"title\":\"Widgets — Shop\",\"description\":\"All our widgets.\"}]}"
check "apply: shop_manager role (has edit_product_terms) can write a product_cat term's title/description" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"

RM_TERM_TITLE="$("${WPCLI[@]}" term meta get "$CAT_TERM_ID" rank_math_title --path="$SITE" 2>/dev/null)"
if [[ "$RM_TERM_TITLE" == "Widgets — Shop" ]]; then
  PASS=$((PASS+1)); echo "  ok   product_cat term title landed in Rank Math's real term meta"
else
  FAIL=$((FAIL+1)); echo "  FAIL product_cat term title did not land in rank_math_title term meta (got: $RM_TERM_TITLE)"
fi

echo
echo "== $PASS passed, $FAIL failed =="
[[ $FAIL -eq 0 ]]
