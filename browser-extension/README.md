# Product Data's part of the Chrome extension

The extension is a shell plus one part per MMI plugin that brings one (`mmi-admin/docs/BROWSER_EXTENSION_PLATFORM_SCOPE.md`).
The shell, in the shared library (`includes/mmi-shared/browser-extension/`, server side `MMI_Browser_Extension`), holds
the store and its key, every call, the panel state per tab, Chrome's per-site permission, the toolbar button, connecting
and self-update. This folder is Product Data's part: the panel on a maker's page and on our own product pages, the live
mapper, the picture picker, Send this site and the reference library. Its server side is
`includes/product-data/includes/class-mmpt-extbridge.php` (`ExtBridge`, routes under `mmpt/v1/ext/`), which registers
the part `product-data` with the shell (`ExtBridge::extension_files()`).

In a download the shell's files sit at the root and this folder's under `parts/product-data/`, with the live mapper's two
scripts from `includes/product-data/assets/js` (`map-select.js`, `live-mapper.js`). The store writes `parts.js`, which
loads `sw-part.js` into the shell's service worker, and puts `bridge-part.js` on the store's admin pages after the
shell's `bridge.js`.

## Files

| File | What it does |
|---|---|
| `sw-part.js` | Product Data's part of the service worker: its messages and routes, the panel, Next and the product in hand, map steps, Send, the reference library |
| `bridge-part.js` | On the store's admin pages: Send's progress, Makers' ↗ and map steps, the Reference library section's site permissions |
| `panel.js`, `page-copy.js` | The panel on a maker's page, and the page as Chrome shows it |
| `pictures.js` | The picture and video picker |
| `mapper.js` | The live mapper's bridge |
| `send.js` | Send this site, from the maker's own tab |

`scripts/check-no-site-rules.sh` checks this folder whole: no store's name, host or rules belong here.

## Reference library

The store lists reference sites (help and knowledge-base pages; `Library` on the server, the Chrome extension page's
Reference library section). When a page on one of them finishes loading in a tab, and Chrome lets the extension onto that
site, `sw-part.js` reads the page as drawn and sends it to `ext/library` (op `keep`), once per address per half hour. The
button shows ✓ for a moment when it is kept. Only pages a person opens are kept: the extension never opens, follows or
fetches a page by itself, so a site's robots rules and bot checks are left alone.
