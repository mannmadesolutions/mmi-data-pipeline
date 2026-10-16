#!/usr/bin/env bash
# Runs the React UI toolchain outside the web root, so node_modules never sits
# where nginx could serve it: mirrors the UI sources to a workspace, runs npm
# there, and copies back what belongs in the plugin (build/, package-lock.json).
#
#   scripts/ui.sh install | build | test | typecheck | check | e2e
#
# e2e renders the Workbench tab's markup and assets with WP-CLI (needs this
# plugin active on the site; WB_VIEW=<file> tests a staged view) and runs the
# browser tests against it with mocked AJAX.
set -euo pipefail
PLUGIN="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${MMI_UI_WORKDIR:-$HOME/.cache/mmi-ui-build/$(basename "$PLUGIN")}"
mkdir -p "$WORK"
rsync -a --delete --exclude node_modules --exclude build \
  --include 'package.json' --include 'package-lock.json' --include 'tsconfig.json' --include 'vitest.config.mts' --include 'playwright.config.ts' \
  --include 'src/***' --include 'tests/' --include 'tests/ui/***' --include 'scripts/' --include 'scripts/build-ui.mjs' \
  --exclude '*' "$PLUGIN/" "$WORK/"
cd "$WORK"
if [ ! -d node_modules ] || [ package.json -nt node_modules ] || { [ -f package-lock.json ] && [ package-lock.json -nt node_modules ]; }; then
  # A changed package.json updates the lockfile; otherwise install exactly what it pins.
  if [ ! -f package-lock.json ] || [ package.json -nt package-lock.json ]; then npm install --no-audit --no-fund; else npm ci --no-audit --no-fund; fi
  touch node_modules
  cp package-lock.json "$PLUGIN/package-lock.json"
fi
cmd="${1:-check}"
[ "$cmd" = install ] && exit 0
if [ "$cmd" = e2e ]; then
  SITE="$(cd "$PLUGIN/../../.." && pwd)"
  (cd "$SITE" && wp eval-file "$PLUGIN/tests/ui/harness/render-harness.php" 2>/dev/null) | grep '^{' > "$WORK/harness.json"
  HARNESS_JSON="$WORK/harness.json" SITE_ROOT="$SITE" npx playwright test "${@:2}"
  exit $?
fi
npm run "$cmd"
if [ "$cmd" = build ] || [ "$cmd" = check ]; then
  mkdir -p "$PLUGIN/build"
  rsync -a --delete "$WORK/build/" "$PLUGIN/build/"
fi
