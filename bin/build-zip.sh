#!/usr/bin/env bash
#
# Builds the installable plugin zip — the exact production file set that
# ships to wordpress.org SVN and to the GitHub Release, nothing else.
#
#   bash bin/build-zip.sh                  # -> build/crawl-cove-connector.zip
#   bash bin/build-zip.sh --stage-to DIR   # stage the file set into DIR, no zip
#                                          # (plugin-check.sh uses this so the
#                                          # checked set and the shipped set are
#                                          # one list, not two that drift)
#   bash bin/build-zip.sh --expect-version 0.10.0
#                                          # fail unless header + Stable tag say so
#                                          # (the release workflow passes the tag)
#   bash bin/build-zip.sh --wporg          # the wordpress.org SVN copy: identical
#                                          # except the `Update URI:` header line
#                                          # is removed and readme.txt Installation
#                                          # step 1 says "search the directory"
#                                          # instead of "download the GitHub zip"
#                                          # (WPORG_STEP1 below). The directory only serves
#                                          # updates to plugins WITHOUT a foreign
#                                          # Update URI, and Plugin Check flags one;
#                                          # the GitHub zip keeps it so a GitHub
#                                          # install is never offered whatever
#                                          # plugin may one day own this slug on
#                                          # wordpress.org (review ticket #212).
#                                          # Combines with --stage-to.
#
# The zip unpacks to crawl-cove-connector/ so WordPress installs it under
# the right slug. GitHub's own "Download ZIP" of a branch unpacks to
# crawl-cove-connector-main/ with tests/ and composer files inside, which is
# why this script exists. The asset name is deliberately unversioned so
# https://github.com/CrawlCove/wordpress-seo-connector/releases/latest/download/crawl-cove-connector.zip
# is a stable link; the version is in the plugin header, readme.txt and the
# release title.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/.." && pwd)"
SLUG=crawl-cove-connector
BUILD="$PLUGIN_ROOT/build"
STAGE_TO=""
EXPECT_VERSION=""
WPORG=0
GITHUB_STEP1_PREFIX='1. Download `crawl-cove-connector.zip` from the latest GitHub release'
WPORG_STEP1='1. In your WordPress admin go to Plugins → Add Plugin, search for "Crawl Cove Connector", then install and activate it.'

while [[ $# -gt 0 ]]; do
  case "$1" in
    --stage-to) STAGE_TO="$2"; shift 2 ;;
    --expect-version) EXPECT_VERSION="${2#v}"; shift 2 ;;
    --wporg) WPORG=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

log() { echo "[build-zip] $*"; }

# --- version consistency: header, constant and readme.txt must agree -------
HEADER_VERSION="$(sed -nE 's/^ \* Version:[[:space:]]+([0-9][0-9A-Za-z.-]*).*/\1/p' "$PLUGIN_ROOT/$SLUG.php" | head -1)"
CONST_VERSION="$(sed -nE "s/^define\( 'CCC_VERSION', '([^']+)' \);.*/\1/p" "$PLUGIN_ROOT/$SLUG.php" | head -1)"
STABLE_TAG="$(sed -nE 's/^Stable tag:[[:space:]]+([0-9][0-9A-Za-z.-]*).*/\1/p' "$PLUGIN_ROOT/readme.txt" | head -1)"

if [[ -z "$HEADER_VERSION" || -z "$CONST_VERSION" || -z "$STABLE_TAG" ]]; then
  echo "could not read the version from the plugin header, CCC_VERSION or readme.txt Stable tag" >&2
  exit 1
fi
if [[ "$HEADER_VERSION" != "$CONST_VERSION" || "$HEADER_VERSION" != "$STABLE_TAG" ]]; then
  echo "version mismatch: header $HEADER_VERSION, CCC_VERSION $CONST_VERSION, Stable tag $STABLE_TAG" >&2
  exit 1
fi
if [[ -n "$EXPECT_VERSION" && "$EXPECT_VERSION" != "$HEADER_VERSION" ]]; then
  echo "expected version $EXPECT_VERSION but the plugin says $HEADER_VERSION — bump header, CCC_VERSION, Stable tag and CHANGELOG first" >&2
  exit 1
fi
if ! grep -q "^## $HEADER_VERSION " "$PLUGIN_ROOT/CHANGELOG.md"; then
  echo "CHANGELOG.md has no '## $HEADER_VERSION' entry" >&2
  exit 1
fi
log "version $HEADER_VERSION (header, CCC_VERSION, Stable tag and CHANGELOG agree)"

# --- stage: everything in git minus dev tooling ---------------------------
# ONE exclude list. plugin-check.sh checks this staged set; the release zips
# it; wordpress.org SVN trunk will be a copy of it.
stage() {
  local dest="$1"
  rm -rf "$dest"
  mkdir -p "$dest"
  rsync -a \
    --exclude=vendor --exclude=tests --exclude=wordpress-org --exclude=.git \
    --exclude=.github --exclude=bin --exclude=build \
    --exclude=composer.json --exclude=composer.lock --exclude=phpcs.xml.dist \
    --exclude=phpcompatibility.xml.dist \
    --exclude=phpunit.xml --exclude=.phpunit.result.cache --exclude=.gitignore \
    --exclude=SECURITY-NOTES.md --exclude=README.md --exclude=CHANGELOG.md \
    --exclude=CLAUDE.md --exclude=.claude --exclude='*.zip' --exclude='*.log' \
    "$PLUGIN_ROOT/" "$dest/"
  if [[ "$WPORG" -eq 1 ]]; then
    sed -i '/^ \* Update URI:/d' "$dest/$SLUG.php"
    if grep -q '^ \* Update URI:' "$dest/$SLUG.php"; then
      echo "--wporg: failed to strip the Update URI header" >&2
      exit 1
    fi
    if [[ "$(grep -cF -- "$GITHUB_STEP1_PREFIX" "$dest/readme.txt")" != 1 ]]; then
      echo "--wporg: readme.txt must have exactly one line starting '$GITHUB_STEP1_PREFIX'" >&2
      exit 1
    fi
    WPORG_STEP1="$WPORG_STEP1" PREFIX="$GITHUB_STEP1_PREFIX" perl -i -pe \
      '$_ = "$ENV{WPORG_STEP1}\n" if index($_, $ENV{PREFIX}) == 0' "$dest/readme.txt"
    if grep -qF -- "$GITHUB_STEP1_PREFIX" "$dest/readme.txt" || ! grep -qxF -- "$WPORG_STEP1" "$dest/readme.txt"; then
      echo "--wporg: failed to rewrite readme.txt Installation step 1" >&2
      exit 1
    fi
    log "wordpress.org copy: Update URI header removed, readme.txt step 1 points at the directory"
  else
    if ! grep -q '^ \* Update URI: *https://crawlcove.com/' "$dest/$SLUG.php"; then
      echo "the GitHub copy must carry a crawlcove.com Update URI header (see --wporg)" >&2
      exit 1
    fi
  fi
}

if [[ -n "$STAGE_TO" ]]; then
  stage "$STAGE_TO"
  log "staged production file set into $STAGE_TO"
  exit 0
fi

rm -rf "$BUILD"
mkdir -p "$BUILD"
stage "$BUILD/$SLUG"

ZIP="$BUILD/$SLUG.zip"
( cd "$BUILD" && zip -q -r -X "$ZIP" "$SLUG" )

# --- verify the artefact, not the intent ----------------------------------
LISTING="$(unzip -Z1 "$ZIP")"
fail=0
for must in "$SLUG/$SLUG.php" "$SLUG/readme.txt" "$SLUG/uninstall.php" "$SLUG/LICENSE" "$SLUG/includes/" "$SLUG/admin/" "$SLUG/languages/"; do
  grep -qx -- "$must" <<<"$LISTING" || grep -q -- "^$must" <<<"$LISTING" || { echo "zip is missing $must" >&2; fail=1; }
done
for never in "$SLUG/vendor/" "$SLUG/tests/" "$SLUG/wordpress-org/" "$SLUG/composer.json" "$SLUG/phpunit.xml" "$SLUG/bin/" "$SLUG/.github/" "$SLUG/README.md" "$SLUG/SECURITY-NOTES.md"; do
  grep -q -- "^$never" <<<"$LISTING" && { echo "zip must not contain $never" >&2; fail=1; }
done
if grep -qv -- "^$SLUG/" <<<"$LISTING"; then
  echo "zip has entries outside $SLUG/ — WordPress would install the wrong slug" >&2
  fail=1
fi
[[ $fail -eq 0 ]] || exit 1

log "built $ZIP ($(wc -c <"$ZIP") bytes, $(grep -c . <<<"$LISTING") entries, all under $SLUG/)"
