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

# Editors check tsconfig.json in the plugin and look for node_modules in its
# parent folders. Link the workspace's node_modules into the nearest one outside
# the web root ($HOME by default; MMI_UI_EDITOR_LINK="" to skip), so types and
# imports resolve in the editor too. An existing folder or foreign link is left alone.
EDITOR_LINK="${MMI_UI_EDITOR_LINK-$HOME/node_modules}"
if [ -n "$EDITOR_LINK" ] && case "$PLUGIN/" in "$(dirname "$EDITOR_LINK")"/*) true;; *) false;; esac; then
  if [ ! -e "$EDITOR_LINK" ] && [ ! -L "$EDITOR_LINK" ]; then
    ln -s "$WORK/node_modules" "$EDITOR_LINK"
  elif [ -L "$EDITOR_LINK" ] && [ "$(readlink "$EDITOR_LINK")" != "$WORK/node_modules" ] && [[ "$(readlink "$EDITOR_LINK")" == *"/mmi-ui-build/"* ]]; then
    ln -sfn "$WORK/node_modules" "$EDITOR_LINK"
  elif [ "$(readlink "$EDITOR_LINK" 2>/dev/null)" != "$WORK/node_modules" ]; then
    echo "note: $EDITOR_LINK exists and isn't this workspace's; editor type checks may not find the UI packages" >&2
  fi
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
