#!/usr/bin/env bash
# check-no-site-rules.sh — keep one store's own rules out of this public repo.
#
# This plugin is sold to any store and its repository is public. Product Data
# code moves in from a private site plugin, which keeps that store's own title
# rules, prompts, brand lists, hostnames and scraping policy and attaches them
# through filters. This check stops them coming along by accident.
#
#   scripts/check-no-site-rules.sh <path>...   check these files (what a commit adds)
#   scripts/check-no-site-rules.sh --moved     check every file in the moved folders
#
# "MannMade" as the publisher (the MannMade\ namespace, "MannMade Industries") is allowed;
# the store (mannmade.us, "MannMade's rules") is not.
# Files in the moved folders (includes/product-data/, browser-extension/) are
# checked whole: nothing in them may name a store. Elsewhere only the lines a
# change adds are checked, so older notes don't block unrelated work.
# The bundled shared library (includes/mmi-shared/) is skipped: it is synced from
# its own source, where the license server's address belongs.
#
# A line that must stay as it is can carry the marker "site-rules-ok" with the reason.
# Exit 0 = clean, 1 = found something, 2 = usage error.

set -euo pipefail
cd "$(dirname "$0")/.."

exec python3 -I - "$@" <<'PY'
import os, re, subprocess, sys

MOVED = ('includes/product-data/', 'browser-extension/')
SKIP = ('includes/mmi-shared/', 'node_modules/', 'vendor/', 'assets/build/', 'build/')
TEXT = ('.php', '.js', '.mjs', '.ts', '.tsx', '.jsx', '.css', '.json', '.md', '.txt', '.html', '.sh', '.py')

TERMS = [
    # The publisher's name is fine (namespace MannMade\DataPipeline, "MannMade Industries");
    # the store that runs on it is not.
    (re.compile(r'mannmade(?![\\/]|\s+(?:Industries|Solutions)\b)', re.I), 'the store, not the publisher'),
    (re.compile(r'studiomann|musicmannstudios', re.I), 'a store hostname'),
    (re.compile(r'CatalogBot', re.I), "a store's fetcher name"),
    (re.compile(r'claude\s+-p\b'), 'the Claude subscription CLI (site-only runner)'),
    (re.compile(r'MannMade\\+ProductTitles\b|MMI_Admin_'), "the site plugin's classes (call a pipeline seam instead)"),
]

def brand_names():
    """Brand names from the site's private alias list, when it sits beside this plugin."""
    src = os.path.join('..', 'mmi-admin', 'includes', 'catalog-rules', 'brand-aliases.php')
    if not os.path.isfile(src):
        return None
    try:
        out = subprocess.run(['php', '-r', 'foreach (array_keys((array) include $argv[1]) as $k) echo $k, "\n";', src],
                             capture_output=True, text=True, timeout=20).stdout
    except (OSError, subprocess.TimeoutExpired):
        return None
    # Keys mix names and slugs ("Steinberg" is listed as "steinberg", "Native Instruments"
    # as "native-instruments"), so match either spelling, any case. Short keys ("cad")
    # would match ordinary words and are left out.
    names = set()
    for n in out.splitlines():
        n = n.strip()
        if len(n) >= 4:
            names.add(re.escape(n).replace(r'\-', '[- ]').replace(r'\ ', '[- ]'))
    names = sorted(names, key=len, reverse=True)
    return re.compile(r'(?<![\w-])(?:' + '|'.join(names) + r')(?![\w-])', re.I) if names else None

def git(*args):
    return subprocess.run(['git', *args], capture_output=True, text=True).stdout

def added_lines(path):
    """(line number, text) for each line a change adds; a new file counts whole."""
    if not git('ls-files', '--', path).strip():
        return whole(path)
    out, rows, n = git('diff', '-U0', 'HEAD', '--', path), [], 0
    for line in out.splitlines():
        m = re.match(r'@@ -\d+(?:,\d+)? \+(\d+)', line)
        if m:
            n = int(m.group(1))
        elif line.startswith('+') and not line.startswith('+++'):
            rows.append((n, line[1:]))
            n += 1
    return rows

def whole(path):
    try:
        with open(path, encoding='utf-8', errors='replace') as f:
            return list(enumerate(f.read().splitlines(), 1))
    except OSError:
        return []

args = sys.argv[1:]
if not args:
    print('usage: check-no-site-rules.sh <path>... | --moved', file=sys.stderr)
    sys.exit(2)
if args == ['--moved']:
    paths = [p for p in git('ls-files', '--cached', '--others', '--exclude-standard', '--', *MOVED).splitlines()]
else:
    paths = args

brands = brand_names()
found = []
for p in paths:
    p = os.path.normpath(p)
    if p.startswith(SKIP) or not p.endswith(TEXT) or not os.path.isfile(p):
        continue
    if p == 'scripts/check-no-site-rules.sh':
        continue  # this file names what it looks for
    rows = whole(p) if p.startswith(MOVED) else added_lines(p)
    for n, text in rows:
        if 'site-rules-ok' in text:
            continue
        for rx, why in TERMS:
            m = rx.search(text)
            if m:
                found.append(f'{p}:{n}: {why}: "{m.group(0)}"')
        if brands:
            m = brands.search(text)
            if m:
                found.append(f'{p}:{n}: a brand from the site\'s alias list: "{m.group(0)}"')

if found:
    print('Site-only text in a public repo (move it to the site plugin behind a filter, or mark the line site-rules-ok with why):')
    print('  ' + '\n  '.join(found[:200]))
    if len(found) > 200:
        print(f'  … and {len(found) - 200} more')
    sys.exit(1)
if brands is None:
    print('check-no-site-rules: clean (brand list not found beside this plugin; brand names not checked).')
else:
    print('check-no-site-rules: clean.')
PY
