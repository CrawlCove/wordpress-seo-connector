#!/usr/bin/env bash
#
# The whole real-WordPress gate in one command: run.sh for each of the four
# adapters, then every self-contained scenario script (they build their own
# sites and are NOT run by run.sh). Run before every release bump.
#
# Why it exists: 0.10.7 shipped after the four run.sh passes only, and
# polylang-yoast-home-checks.sh, never run that day, would have caught its
# revert refusing a cleared French homepage title (21 Oct 2026).
#
# Usage: bash tests/integration/all.sh   (about 6 minutes; logs in /tmp)

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOGS="$(mktemp -d /tmp/ccc-integration-all.XXXXXX)"
FAILED=()

run() {
  local name="$1"; shift
  local log="$LOGS/$name.log"
  if "$@" >"$log" 2>&1; then
    echo "  ok   $name ($(grep -c '^  ok' "$log") checks)"
  else
    echo "  FAIL $name (see $log)"
    FAILED+=("$name")
  fi
}

for adapter in yoast rankmath seopress aioseo; do
  run "run-$adapter" bash "$HERE/run.sh" --adapter="$adapter"
done
for script in caching multisite polylang polylang-yoast-home translatepress woocommerce; do
  run "$script" bash "$HERE/$script-checks.sh"
done

echo
if [[ ${#FAILED[@]} -eq 0 ]]; then
  echo "== all integration suites passed (logs: $LOGS) =="
else
  echo "== ${#FAILED[@]} failed: ${FAILED[*]} (logs: $LOGS) =="
  exit 1
fi
