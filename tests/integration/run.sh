#!/usr/bin/env bash
#
# Real-WordPress integration smoke for crawl-cove-connector: builds a throwaway
# WP install backed by the SQLite drop-in (no MySQL needed on this box),
# installs the real Rank Math, Yoast SEO, SEOPress or AIOSEO plugin, symlinks
# in this repo's plugin code, and exercises all five REST routes end to end
# (auth, capability checks, validation, dry-run, apply, revert) against a
# live php -S server. Unit stubs can't see this: url_to_postid()'s "?p=N with
# no such post" quirk and Yoast's indexable auto-rebuild were both
# proven/caught here, not in tests/*Test.php.
#
# Usage: tests/integration/run.sh [--adapter=rankmath|yoast|seopress|aioseo]
#
# Downloads are cached under tests/integration/.cache/ (gitignored) so repeat
# runs don't hit wordpress.org again. The site itself is rebuilt from scratch
# every run under tests/integration/.site/ (also gitignored).

set -uo pipefail

ADAPTER="rankmath"
for arg in "$@"; do
  case "$arg" in
    --adapter=*) ADAPTER="${arg#*=}" ;;
    *) echo "unknown arg: $arg" >&2; exit 2 ;;
  esac
done
if [[ "$ADAPTER" != "rankmath" && "$ADAPTER" != "yoast" && "$ADAPTER" != "seopress" && "$ADAPTER" != "aioseo" ]]; then
  echo "adapter must be rankmath, yoast, seopress or aioseo, got: $ADAPTER" >&2
  exit 2
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8987
URL="http://127.0.0.1:$PORT"
SERVER_PID=""

log() { echo "[integration] $*"; }

cleanup() {
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

for f in wordpress.zip sqlite.zip rankmath.zip yoast.zip seopress.zip aioseo.zip wp-cli.phar; do
  if [[ ! -f "$CACHE/$f" ]]; then
    echo "missing $CACHE/$f — see NEXT.md for the download commands" >&2
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
unzip -q "$CACHE/yoast.zip" -d "$SITE/wp-content/plugins"
unzip -q "$CACHE/seopress.zip" -d "$SITE/wp-content/plugins"
unzip -q "$CACHE/aioseo.zip" -d "$SITE/wp-content/plugins"
cp "$SITE/wp-content/plugins/sqlite-database-integration/db.copy" "$SITE/wp-content/db.php"
ln -s "$PLUGIN_ROOT" "$SITE/wp-content/plugins/crawl-cove-connector"

# The harness serves plain HTTP on 127.0.0.1, so core's is_ssl() gate on
# Application Passwords would block every request. Force it available WITHOUT
# setting WP_ENVIRONMENT_TYPE=local: Yoast's `wp yoast index` (and its
# save_post indexable rebuild) refuses to run on any non-"production" type,
# which cost real debugging time to find (see NEXT.md / LESSONS candidate).
cat > "$SITE/wp-content/mu-plugins/ccc-test-harness.php" <<'PHP'
<?php
add_filter( 'wp_is_application_passwords_available', '__return_true' );
PHP

log "wp core config + install (SQLite drop-in, no MySQL)"
"${WPCLI[@]}" config create --path="$SITE" --dbname=irrelevant --dbuser=irrelevant --dbpass=irrelevant --skip-check --quiet
"${WPCLI[@]}" core install --path="$SITE" --url="$URL" --title="CCC Integration" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet

case "$ADAPTER" in
  rankmath) SEO_PLUGIN="seo-by-rank-math" ;;
  yoast)    SEO_PLUGIN="wordpress-seo" ;;
  seopress) SEO_PLUGIN="wp-seopress" ;;
  aioseo)   SEO_PLUGIN="all-in-one-seo-pack" ;;
esac
"${WPCLI[@]}" plugin activate sqlite-database-integration crawl-cove-connector "$SEO_PLUGIN" --path="$SITE" --quiet

log "users: editor (can edit any post), author (own posts only), subscriber (none)"
"${WPCLI[@]}" user create ccc_editor editor@example.com --role=editor --user_pass=editor-pass --path="$SITE" --quiet
"${WPCLI[@]}" user create ccc_author author@example.com --role=author --user_pass=author-pass --path="$SITE" --quiet
"${WPCLI[@]}" user create ccc_subscriber sub@example.com --role=subscriber --user_pass=sub-pass --path="$SITE" --quiet
EDITOR_ID="$("${WPCLI[@]}" user get ccc_editor --field=ID --path="$SITE")"
AUTHOR_ID="$("${WPCLI[@]}" user get ccc_author --field=ID --path="$SITE")"

pw_for() { "${WPCLI[@]}" user application-password create "$1" "ccc-test" --porcelain --path="$SITE"; }
EDITOR_PW="$(pw_for ccc_editor)"
AUTHOR_PW="$(pw_for ccc_author)"
SUBSCRIBER_PW="$(pw_for ccc_subscriber)"

log "posts: one owned by editor, one owned by author"
POST_EDITOR="$("${WPCLI[@]}" post create --post_title="Editor Post" --post_status=publish --post_author="$EDITOR_ID" --porcelain --path="$SITE")"
POST_AUTHOR="$("${WPCLI[@]}" post create --post_title="Author Post" --post_status=publish --post_author="$AUTHOR_ID" --porcelain --path="$SITE")"

# Static front page (the common WP setup: Settings -> Reading -> "A static
# page"). Both Yoast and Rank Math render THIS PAGE'S OWN title/description
# postmeta for the site root in that case (confirmed against real source:
# Yoast's meta-surface.php for_home_page() calls find_by_id_and_type() on
# page_on_front when show_on_front=page; Rank Math's paper/class-paper.php
# maps is_home_static_page() to the same 'Singular' handler as any other
# page) — only the rarer "your latest posts" homepage setting uses the
# separate title-home-wpseo/homepage_title options. So resolving and
# applying to "/" should work through the exact same code path as any
# other page, with zero adapter changes; this proves it rather than assuming it.
POST_HOME="$("${WPCLI[@]}" post create --post_type=page --post_title="Home" --post_status=publish --post_author="$EDITOR_ID" --porcelain --path="$SITE")"
"${WPCLI[@]}" option update show_on_front page --path="$SITE" --quiet
"${WPCLI[@]}" option update page_on_front "$POST_HOME" --path="$SITE" --quiet

log "posts nobody but their owner may resolve: editor private + draft, a privacy request (review #227)"
POST_PRIVATE="$("${WPCLI[@]}" post create --post_title="Private plan" --post_status=private --post_author="$EDITOR_ID" --porcelain --path="$SITE")"
POST_DRAFT="$("${WPCLI[@]}" post create --post_title="Draft plan" --post_status=draft --post_author="$EDITOR_ID" --porcelain --path="$SITE")"
POST_USER_REQUEST="$("${WPCLI[@]}" eval 'echo wp_create_user_request( "jane.doe@example.org", "export_personal_data" );' --path="$SITE")"

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server.log" 2>&1 ) &
SERVER_PID=$!
UP=0
for i in $(seq 1 30); do
  if curl -s -o /dev/null "$URL/index.php?rest_route=/"; then UP=1; break; fi
  sleep 0.3
done
if [[ "$UP" -ne 1 ]]; then
  echo "php -S never came up — see $HERE/.server.log" >&2
  exit 1
fi

export CCC_URL="$URL"
export CCC_EDITOR_PW="$EDITOR_PW"
export CCC_AUTHOR_PW="$AUTHOR_PW"
export CCC_SUBSCRIBER_PW="$SUBSCRIBER_PW"
export CCC_POST_EDITOR="$POST_EDITOR"
export CCC_POST_AUTHOR="$POST_AUTHOR"
export CCC_POST_HOME="$POST_HOME"
export CCC_POST_PRIVATE="$POST_PRIVATE"
export CCC_POST_DRAFT="$POST_DRAFT"
export CCC_POST_USER_REQUEST="$POST_USER_REQUEST"
export CCC_ADAPTER="$ADAPTER"
export CCC_SITE="$SITE"
export CCC_CACHE="$CACHE"

log "running REST route checks (adapter=$ADAPTER)"
bash "$HERE/checks.sh"
CHECKS_STATUS=$?

log "running security pass (auth sweep, capability matrix, fuzzing)"
bash "$HERE/security-checks.sh"
SECURITY_STATUS=$?

log "running homepage ('your latest posts') checks"
bash "$HERE/homepage-checks.sh"
HOMEPAGE_STATUS=$?

log "running taxonomy term (category/tag archive) checks"
bash "$HERE/taxonomy-checks.sh"
TAXONOMY_STATUS=$?

log "running desktop-app connection status checks"
bash "$HERE/connection-checks.sh"
CONNECTION_STATUS=$?

log "running REST root discovery checks (Link header, plain-permalink shape)"
bash "$HERE/rest-root-checks.sh"
RESTROOT_STATUS=$?

log "running Application Passwords off/on checks (named 401 + Tools page row)"
bash "$HERE/app-passwords-checks.sh"
APPPW_STATUS=$?

log "running content integrity checks (a push never re-saves the post body)"
bash "$HERE/content-integrity-checks.sh"
INTEGRITY_STATUS=$?

AIOSEO_STATUS=0
if [[ "$ADAPTER" == "aioseo" ]]; then
  log "running AIOSEO corruption regression check (patch-safety of Post::savePost)"
  bash "$HERE/aioseo-checks.sh"
  AIOSEO_STATUS=$?
fi

STATUS=0
[[ $CHECKS_STATUS -ne 0 || $SECURITY_STATUS -ne 0 || $HOMEPAGE_STATUS -ne 0 || $TAXONOMY_STATUS -ne 0 || $CONNECTION_STATUS -ne 0 || $RESTROOT_STATUS -ne 0 || $APPPW_STATUS -ne 0 || $INTEGRITY_STATUS -ne 0 || $AIOSEO_STATUS -ne 0 ]] && STATUS=1

if [[ $STATUS -eq 0 ]]; then
  log "ALL CHECKS PASSED (route checks + security pass)"
else
  log "CHECKS FAILED — see output above; server log: $HERE/.server.log"
fi
exit $STATUS
