#!/usr/bin/env bash
#
# Compares the SEO plugin zips cached under tests/integration/.cache/ (used
# by run.sh's real-WordPress integration harness) against the CURRENT stable
# version each plugin has on wordpress.org right now, and flags drift.
#
# WHY THIS EXISTS: run.sh refuses to auto-download — it errors if a cache
# file is missing, and silently reuses whatever's already there otherwise.
# That's correct for repeatable, offline-friendly test runs, but it means
# the cache can go stale with nothing ever saying so: on 26 Sept 2026 the
# cached AIOSEO build was still 5.0.1.1 four days after wordpress.org shipped
# 5.0.2, and every integration run in between was quietly testing against a
# build nobody would actually be running in production anymore. Found by
# accident that session, not by any check — this script makes it a check.
#
# This is a manual pre-release / periodic sanity script, like plugin-check.sh
# — NOT wired into composer test or CI, because it needs network access and
# an offline/CI run should stay deterministic. Run it yourself every so often
# (e.g. at the top of a wp session) and re-run tests/integration/run.sh for
# any adapter it flags as stale.
#
# Usage: tests/integration/check-versions.sh

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CACHE="$HERE/.cache"

log() { echo "[check-versions] $*"; }

current_version() {
  # $1 = wordpress.org plugin slug
  curl -s "https://api.wordpress.org/plugins/info/1.0/$1.json" \
    | python3 -c "import json,sys; print(json.load(sys.stdin).get('version','?'))"
}

cached_version() {
  # $1 = zip file, $2 = path inside zip to the main plugin file
  unzip -p "$CACHE/$1" "$2" 2>/dev/null | grep -m1 -oP '(?<=Version:)\s*\K[0-9][0-9A-Za-z.\-]*'
}

STALE=0

check() {
  local label="$1" slug="$2" zip="$3" mainfile="$4"
  if [[ ! -f "$CACHE/$zip" ]]; then
    log "$label: no cache file $zip — nothing to compare (run.sh will error first anyway)"
    return
  fi
  local have want
  have="$(cached_version "$zip" "$mainfile")"
  want="$(current_version "$slug")"
  if [[ -z "$have" ]]; then
    log "$label: could not read a Version: header from $zip/$mainfile — check by hand"
    return
  fi
  # Exact match, or one side is the other plus a ".N" patch component
  # (e.g. 1.0.279 vs 1.0.279.1). A "-" suffix (28.6-RC4 vs 28.6) is a
  # pre-release and must NOT match here — that prefix match is exactly how
  # a cached RC read as "matches current stable" for a week (29 Sept 2026).
  if [[ "$have" == "$want" || "$have" == "$want".* || "$want" == "$have".* ]]; then
    log "$label: cache matches current stable ($have)"
    return
  fi
  # Strip pre-release suffixes (e.g. "28.6-RC4" -> "28.6") so `sort -V` compares
  # release lines, not RC tags — a cached RC for the NEXT stable is not "stale".
  local have_base="${have%%-*}" want_base="${want%%-*}"
  local lower
  lower="$(printf '%s\n%s\n' "$have_base" "$want_base" | sort -V | head -1)"
  if [[ "$have_base" == "$want_base" && "$have" == *-* && "$want" != *-* ]]; then
    # Same release line, but the cache is a pre-release (RC/beta) build and
    # wordpress.org now ships the final. Found 29 Sept 2026: yoast.zip was
    # 28.6-RC4 (cached 22 Sept) and read as "same line" for a week — including
    # the morning 28.6 stable actually shipped. An RC is not what users run.
    log "$label: STALE — cache has pre-release $have, wordpress.org stable is now $want (final superseded the RC)"
    STALE=1
  elif [[ "$have_base" == "$want_base" ]]; then
    log "$label: cache ($have) and current stable ($want) are the same release line — fine"
  elif [[ "$lower" == "$have_base" ]]; then
    log "$label: STALE — cache has $have, wordpress.org stable is $want (cache is BEHIND)"
    STALE=1
  else
    log "$label: cache has $have, wordpress.org stable is $want — cache is AHEAD (pre-release build), not stale"
  fi
}

# WordPress core itself: run.sh unpacks wordpress.zip the same way, and the
# same silent staleness applies (26 Sept's cache was 7.1.1 while 7.1.2 was
# current on 29 Sept). Compared against the core version-check API's first
# offer, which is the current stable.
check_core() {
  local zip="wordpress.zip"
  if [[ ! -f "$CACHE/$zip" ]]; then
    log "WordPress core: no cache file $zip — nothing to compare"
    return
  fi
  local have want
  have="$(unzip -p "$CACHE/$zip" wordpress/wp-includes/version.php 2>/dev/null | grep -m1 -oP "wp_version = '\K[0-9][0-9A-Za-z.\-]*")"
  want="$(curl -s 'https://api.wordpress.org/core/version-check/1.7/' \
    | python3 -c "import json,sys; print(json.load(sys.stdin)['offers'][0]['version'])" 2>/dev/null)"
  if [[ -z "$have" || -z "$want" ]]; then
    log "WordPress core: could not read cached ($have) or current ($want) version — check by hand"
    return
  fi
  if [[ "$have" == "$want" ]]; then
    log "WordPress core: cache matches current stable ($have)"
    return
  fi
  local lower
  lower="$(printf '%s\n%s\n' "$have" "$want" | sort -V | head -1)"
  if [[ "$lower" == "$have" ]]; then
    log "WordPress core: STALE — cache has $have, current stable is $want (cache is BEHIND)"
    STALE=1
  else
    log "WordPress core: cache has $have, current stable is $want — cache is AHEAD, not stale"
  fi
}

# readme.txt's "Tested up to" is what wordpress.org shows installers: once
# the current stable's major.minor is past it, the listing carries an
# "untested with your version of WordPress" warning and the plugin drops
# out of some directory searches. The harness verifies against the cached
# core, so this only stays honest if somebody re-runs the matrix on the new
# core and bumps the header. Compared major.minor only (that is what the
# directory compares); a patch release never needs a bump.
check_tested_up_to() {
  local readme="$HERE/../../readme.txt"
  local claim want want_mm
  claim="$(grep -m1 -oP '^Tested up to:\s*\K[0-9]+\.[0-9]+' "$readme")"
  want="$(curl -s 'https://api.wordpress.org/core/version-check/1.7/' \
    | python3 -c "import json,sys; print(json.load(sys.stdin)['offers'][0]['version'])" 2>/dev/null)"
  want_mm="$(printf '%s' "$want" | grep -oP '^[0-9]+\.[0-9]+')"
  if [[ -z "$claim" || -z "$want_mm" ]]; then
    log "Tested up to: could not read readme.txt claim ($claim) or current stable ($want) — check by hand"
    return
  fi
  local lower
  lower="$(printf '%s\n%s\n' "$claim" "$want_mm" | sort -V | head -1)"
  if [[ "$claim" == "$want_mm" ]]; then
    log "Tested up to: readme.txt says $claim, current stable is $want — current"
  elif [[ "$lower" == "$claim" ]]; then
    log "Tested up to: STALE — readme.txt says $claim, current stable is $want. Refresh wordpress.zip, re-run the full matrix, then bump the header (and Plugin Check)"
    STALE=1
  else
    log "Tested up to: readme.txt says $claim, current stable is $want — readme is AHEAD of stable (claiming an unreleased version), fix the header"
    STALE=1
  fi
}

check_core
check_tested_up_to
check "Rank Math" "seo-by-rank-math"        "rankmath.zip"  "seo-by-rank-math/rank-math.php"
check "Yoast SEO"  "wordpress-seo"          "yoast.zip"     "wordpress-seo/wp-seo.php"
check "SEOPress"   "wp-seopress"            "seopress.zip"  "wp-seopress/seopress.php"
check "AIOSEO"     "all-in-one-seo-pack"    "aioseo.zip"    "all-in-one-seo-pack/all_in_one_seo_pack.php"
check "Elementor"  "elementor"              "elementor.zip" "elementor/elementor.php"

if [[ "$STALE" -eq 1 ]]; then
  log "one or more caches are stale. To refresh, e.g.:"
  log "  curl -sL https://downloads.wordpress.org/plugin/<slug>.<version>.zip -o tests/integration/.cache/<name>.zip"
  log "  curl -sL https://wordpress.org/wordpress-<version>.zip -o tests/integration/.cache/wordpress.zip"
  log "then re-run tests/integration/run.sh --adapter=<name> and check for regressions."
  exit 1
fi
log "cached WordPress core and all SEO plugin builds match current wordpress.org stable"
exit 0
