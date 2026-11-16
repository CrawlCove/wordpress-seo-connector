#!/usr/bin/env bash
#
# Publishes one tagged version to the wordpress.org plugin SVN repo:
# trunk = the --wporg production file set, tags/<version> = a copy of trunk,
# assets/ = wordpress-org/*.png (icon, banner, screenshots).
#
#   bash bin/deploy-wporg.sh v1.0.0
#
# Called by .github/workflows/release.yml after the GitHub Release job, so it
# only ever runs on a tag push, and a tag is only pushed after a wp-release
# approval. Credentials come from SVN_USERNAME / SVN_PASSWORD (repo secrets).
# SVN_URL overrides the target, which is how this script is tested against a
# local file:// repo (no credentials needed there).
#
# Refuses to overwrite an existing tags/<version>: wordpress.org serves the
# Stable tag's directory, and rewriting a published version in place would
# hand two different zips the same number.

set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "usage: $0 <tag, e.g. v1.0.0>" >&2
  exit 2
fi

VERSION="${1#v}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/.." && pwd)"
SVN_URL="${SVN_URL:-https://plugins.svn.wordpress.org/crawl-cove-connector}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

log() { echo "[deploy-wporg] $*"; }

AUTH=(--non-interactive --no-auth-cache)
if [[ -n "${SVN_USERNAME:-}" ]]; then
  AUTH+=(--username "$SVN_USERNAME" --password "${SVN_PASSWORD:-}")
fi

# The build checks header, CCC_VERSION, Stable tag and CHANGELOG all say VERSION.
bash "$PLUGIN_ROOT/bin/build-zip.sh" --expect-version "$VERSION" --stage-to "$WORK/stage" --wporg

log "checking out $SVN_URL"
svn checkout "${AUTH[@]}" --depth immediates "$SVN_URL" "$WORK/svn"
cd "$WORK/svn"
for dir in trunk tags assets; do
  if [[ ! -d "$dir" ]]; then
    mkdir "$dir"
    svn add --quiet "$dir"
  fi
done
svn update "${AUTH[@]}" --quiet --set-depth infinity trunk assets

if svn info "${AUTH[@]}" "$SVN_URL/tags/$VERSION" >/dev/null 2>&1; then
  echo "tags/$VERSION already exists on $SVN_URL: bump the version, never republish one" >&2
  exit 1
fi

rsync -a --delete --exclude=.svn "$WORK/stage/" trunk/
rsync -a --delete --exclude=.svn --include='*.png' --exclude='*' "$PLUGIN_ROOT/wordpress-org/" assets/

# Stage adds and deletions (no file in the plugin has a space in its name).
svn add --quiet --force trunk assets
svn status | awk '/^!/ { print $2 }' | while read -r gone; do svn rm --quiet "$gone"; done
# Without this the directory serves the PNGs as downloads instead of images.
for png in assets/*.png; do
  svn propset --quiet svn:mime-type image/png "$png"
done

svn cp --quiet trunk "tags/$VERSION"

log "changes for $VERSION:"
svn status
svn commit "${AUTH[@]}" -m "Release $VERSION"
log "published $VERSION to $SVN_URL (trunk, tags/$VERSION, assets)"
