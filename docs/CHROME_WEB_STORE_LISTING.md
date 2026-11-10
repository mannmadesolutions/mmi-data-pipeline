# MMI Product Data: Chrome Web Store listing

What to paste into the Chrome Web Store developer dashboard for the MMI Product Data extension, and how to build the
package. Prepared in Product Data migration phase 10e. The publisher submits it; nothing here is sent automatically.

## The package

```
wp mmpt extension package --out=<folder>
```

It writes `mmi-product-data-<version>.zip`: the copy that names no store (each person connects it to their own store),
with its files at the zip's root and without the `alarms` permission (that only drives the self-update of an unpacked
copy; the Web Store updates its own copies). Upload that zip as a new item, or as a new version of the item.

A store's own download (its Chrome extension page) stays as it is: it is made that store's and is for unpacked use.

## Store listing tab

**Name:** MMI Product Data

**Summary** (132 characters at most; the manifest's description):
> Work on a maker's product page for your store: see what it gives each product, write it, pick parts and pictures on the page.

**Category:** Productivity › Workflow & Planning

**Language:** English

**Description:**

> MMI Product Data is the browser half of MMI Data Pipeline's Product Data, for WooCommerce stores that sell other
> makers' products. Open a maker's product page, click MMI Product Data in the toolbar, and a panel shows what that
> page gives the matching product in your store: description, pictures, videos, audio demos, system requirements,
> manuals and more, each beside what the product has now.
>
> - Commit what the page gives to the product, or send it to your store's review first. Every change shows in the
>   Product Workbench's Recent changes, with Undo.
> - Pick a part on the page (a gallery, a video, the requirements) and save it for the whole maker site, so every
>   product on that site reads it the same way.
> - Pick single pictures, videos or audio for one product.
> - Link a page to a product, find a product's page on its maker's site, and move to the site's next product with
>   work left.
> - Send a page that refuses your server from your own browser.
>
> The extension talks only to the store you connect it to. It needs MMI Data Pipeline 3.0.1 or later on that store,
> with an active license or trial. To connect, type your store's address when the extension asks, then click Connect
> on your store's Chrome extension page.

**Graphic assets:**
- Icon 128×128: `browser-extension/icons/128.png`.
- Screenshots (1280×800) and the small promo tile (440×280): **not decided yet; the owner decides at the end.** No real
  maker or vendor appears in them (owner, 2026-11-10): either a made-up maker's page built for the purpose, or no web
  page at all, showing the extension's panel and the plugin's admin screens instead. `customer-site.sh` can take
  1280×800 shots with `MMI_SHOTS=<folder>`, but it runs on a real maker's stored page, so its shots aren't for the
  listing.

## Privacy practices tab

**Single purpose:**
> Shows and writes product data from a maker's product page to the user's own WooCommerce store, which the user connects.

**Permission justifications:**

| Permission | Why |
| --- | --- |
| `storage` | Keeps the connected store's address and the key that store issued, and the panel's state per tab. Nothing leaves the browser except to that store. |
| `scripting` | Puts the panel, the part mapper and the picture picker on the page the user is working on, and the connection helper on the user's own store's admin page. |
| `activeTab` | The toolbar click opens the panel on the current page without asking for access to every site. |
| Optional host permissions (`https://*/*`, `http://*/*`) | Asked only when the user chooses: for their own store's address when connecting (any domain, so it is not fixed in advance), and "Keep it open as you browse" on a maker's site. Never granted at install. |

**Remote code:** No. All of the extension's scripts are in the package. It sends requests only to the store the user
connected, and that store answers with data, not code.

**Data usage** (tick these in the dashboard):
- **Website content:** the text, pictures and links of the maker page the user opens the panel on, and its HTML when
  the user commits, stores or sends a page. Sent only to the user's own store.
- **Web history:** the addresses of the maker pages the panel is opened on. Sent only to the user's own store.
- **Authentication information:** the key the user's store issues when they connect. Kept in the extension's local
  storage and sent only to that store.

**Certifications** (all true):
- I do not sell or transfer user data to third parties, outside of the approved use cases.
- I do not use or transfer user data for purposes that are unrelated to my item's single purpose.
- I do not use or transfer user data to determine creditworthiness or for lending purposes.

**Privacy policy URL:** the publisher's privacy policy page, at its “MMI Product Data browser extension” section
(`#mmi-product-data-extension`, published 2026-11-10 with the wording below).

### The publisher's privacy policy section (as published)

> **MMI Product Data browser extension.** MMI Product Data is our Chrome extension for stores that use our MMI Data
> Pipeline plugin. It sends data only to the WooCommerce store its user connects it to, and never to us or anyone else.
>
> - **What it sends to that store:** when you open its panel on a web page, the page’s address and content, so your
>   store can show and save what the page holds for your products. Your store keeps what you commit or store.
> - **Your connection key:** to connect, your store issues a key that the extension keeps in your browser’s extension
>   storage and sends only to that store. Disconnecting, or removing the extension, deletes it.
> - **Nothing else:** the extension has no analytics, tracking or advertising, and we receive nothing from it. What a
>   store does with the data is covered by that store’s own privacy policy.

## Distribution tab

- **Visibility:** Unlisted for the first stores (owner decision 6, 2026-11-09), public once a few stores have used it.
- **Regions:** all.
- **Trader status:** trader (owner decision 7); the dashboard asks for the trader's contact details.
