# MMI Product Data (Chrome extension)

The Product Data module's browser extension (Manifest V3; Chrome, Edge and Brave). It works on a maker's own product
page: it shows what the page gives each product that uses it, writes it to the product (with Undo in the Product
Workbench's Recent changes), and picks parts, pictures and videos right on the page. Its server side is
`includes/product-data/includes/class-mmpt-extbridge.php` (`ExtBridge`, routes under `mmpt/v1/ext/`).

## One store, chosen when it connects

Nothing here names a store.

- **A copy downloaded from a store** (its Chrome extension page, `admin.php?page=mmi-product-extension`) is made that
  store's as the zip is built (`ExtBridge::zip_file()`): its manifest gets the store's host permission and this
  folder's `bridge.js` on the store's admin pages, and `build.json` names the store. It connects there without asking.
- **Any other copy**, such as the Chrome Web Store's (`ExtBridge::zip_file( false )`), asks: the toolbar button on a
  store's Chrome extension page, or the address typed on `connect.html` (also its Options page). Chrome is asked for
  that site, and the service worker registers the bridge on its admin pages.

Connecting hands the extension a key that works only on the extension's routes, under the person's login. The key
and every call stay in the service worker (`sw.js`); nothing reaches a maker's page. Connecting on another store's
page moves the extension there.

## Files

| File | What it does |
|---|---|
| `sw.js` | The service worker: the store, the key, every call, the panel's state, self-update of unpacked copies |
| `bridge.js` | On the store's admin pages: connecting, and Send's progress |
| `connect.html`, `connect.js` | Which store (a copy that knows none) |
| `panel.js`, `page-copy.js` | The panel on a maker's page, and the page as Chrome shows it |
| `pictures.js` | The picture and video picker |
| `mapper.js` | The live mapper's bridge (`map-select.js` and `live-mapper.js` come from the module's `assets/js`) |
| `send.js` | Send this site, from the maker's own tab |
| `allow.html`, `allow.js` | "Keep it open as you browse": Chrome's per-site permission |

The download adds the live mapper's two scripts and `build.json`. `scripts/check-no-site-rules.sh` checks this folder
whole: no store's name, host or rules belong here.
