# Campaigns

A campaign is a promotion window with a name, dates and products. Every supplier promotion the distributor feeds carry (XChange, SkuPort, Plugivery) becomes one automatically; the store's own sales are made by hand. The storefront, the Merchant Center feed and a mailing can then name the window ("XLN Audio Black Friday Sale 2026") instead of showing an anonymous sale price.

Built 2026-10-29 (mmi-data-pipeline 2.61.0) for the owner's sales plan: promotional periods are the lever for mannmade.us sales.

## Where it lives

- Taxonomy `mmi_campaign` on products, public, URL base `/deals/` (`Campaigns::REWRITE`). Products › Campaigns in wp-admin lists them; the product editor assigns them like any term.
- Term meta: `start`, `end` (Y-m-d, store calendar), `supplier` (`xchange`, `skuport`, `plugivery`, or blank for the store's own), `code` (the supplier's promotion code, XChange's "Promo ID"), `assets_url` (the vendor's dealer banner and copy folder), `reverb_sale_id` (the Reverb sales event this campaign's listings join; mmi-reverb-integration reads it), `hero_image_id`.
- Product meta mirror `_mmi_campaign_end` (Y-m-d) so a query loop can sort or filter by "ends soon" without a term join.
- Feed-made terms carry `_mmi_campaign_key` = `supplier|lower-cased promotion name` (or `supplier|YYYY-MM` of the end month for suppliers that name nothing). Hand-made terms have no key and the sync never touches them.

## Shopper-facing names and labels (2.61.1)

Distributor promotion names are dealer copy ("2026 McDSP Spooky Sale US Cost", "MAAT — All XCHANGE 30% OCT 2026"). Every sync runs `refresh_display()` on each campaign:

- **Name** (feed campaigns): `display_name()` makes "McDSP Spooky Sale", "MAAT Digital Sale", "UA Black Friday Part 1 Sale" from the supplier name and the members' brands. It removes date ranges, years, prices, the vendor's percentage claims, "with free …" mechanics and dealer words (US Cost, PP, XCHANGE, SkuPort, Plugivery), fixes shouting and brand spelling, puts the maker first when the name doesn't say whose sale it is, and ends with "Sale" when no sale word is left. The slug follows while it is still the automatic one. The supplier's name stays in term meta `supplier_name`. A name the owner edits is kept (`_mmi_campaign_auto_name` records the last automatic name).
- **Nameless supplier promotions** (Plugivery, SkuPort) group by the product's brand and end month: "Overloud Sale", never "Plugivery promotions ending November".
- **Labels** (term meta, read by Bricks as `{term_meta:…}` and by the campaign email): `state` (active, upcoming, ended), `max_discount` (whole percent from our own regular vs sale price, scheduled sale included), `discount_label` ("Up to 65% off", or "Special pricing" under 5%), `summary` ("43 EastWest titles", counting discounted products), `dates_label` ("Ends November 2", "Starts November 4"), `brands` (JSON), `hero_image_id` and `hero_image_url` (the main maker's logo, else the best-discounted product's picture; a hero the owner sets is kept).
- **Description** (feed campaigns, while automatic): "Save up to 65% on 43 EastWest titles. Ends November 2."
- **Cleanup**: empty feed campaigns from the first build's distributor-month groups, and any empty feed campaign over 30 days past its end, are deleted (never one that fed a Reverb sale).

A campaign whose products aren't discounted on the store (a supplier promotion our price doesn't follow yet, e.g. Plugivery until its promo mapping is approved) gets `max_discount` 0. The Deals page and the email skip it.

## The Deals page (`/deals/`, Bricks page #225780)

Built from the homepage's classes (`mmhome-section`, `mmhome-inner`, `mmhome-hero__*`, `mmhome-eyebrow`, `mmhome-h2`, `mmhome-btn--green`, `mmi-lp-grid`, `mmi-lp-card*`). A dark hero with "Shop everything on sale" (the shop's on-sale filter), then "On now" (`state = active`, `max_discount >= 5`, biggest discount first) and "Coming soon" (`state = upcoming`, by start date) term loops. Each card is one link: brand logo on a light panel, green discount badge, name, summary, dates and "Shop the sale →". Campaign archives (`/deals/<slug>/`) use the Product Archive template (#21067, condition `mmi_campaign::all`).

## How membership is decided

`Campaigns::from_feeds()` reads every registered feed catalog's promotion rows (`MMI_Pipeline_Feed_Catalog::rows('promotions')`, the same rows the Distributors › Promotions tab shows). A product joins a campaign while:

- its promotion is `active` or `upcoming` (`MMI_Supplier_Promotion::state()`), and
- the product is published on the site.

An ended promotion drops the product from the campaign; the term stays as history with its dates. Names and codes come from the feed; the owner's edits to a term's name, assets link, Reverb sale or hero image are kept, because only the key links a term to the feed.

`Campaigns::sync()` runs hourly (Action Scheduler, group `mmi-pipeline-campaigns`) and on `wp mmi campaigns sync`. It creates missing terms, updates dates, attaches and detaches products, and fires `mmi_pipeline_campaigns_synced( $stats, $dry )`.

## Commands

```
wp mmi campaigns preview            what the feeds would make, nothing written
wp mmi campaigns sync [--dry-run]   make the taxonomy match the feeds
wp mmi campaigns list               every campaign: state, supplier, dates, product count, URL
```

## Using campaigns on the storefront

Bricks needs no plugin template:

- Campaign page: the `mmi_campaign` term archive at `/deals/<slug>/`. A Bricks archive template with the condition "Taxonomy is mmi_campaign" and a query loop of products (the archive's own query, sorted by price or discount) is the whole page. The term's description and meta (`{term_meta:start}`, `{term_meta:end}`, `{term_meta:assets_url}`) supply the heading, the end date and the hero.
- Index at `/deals/`: a page with a query loop over terms of `mmi_campaign`, ordered by the `end` meta, showing only terms whose `end` is today or later.
- Badge on product cards: `{post_terms_mmi_campaign}` with the condition "is not empty".
- The existing On Sale carousel keeps working; campaigns sit on top of it.

## Elsewhere in the suite

- mmi-google-services (2.13.9) writes the first campaign's name to `g:custom_label_0`, so Merchant Center can report per campaign.
- mmi-reverb-integration reads `reverb_sale_id` to add the campaign's live listings to that Reverb sales event (see its docs).
- mmi-email-customizer's campaign mailing takes a campaign slug and sends its products to opted-in subscribers (see its docs).

## Gotchas

- `rows('promotions')` reads the catalog index built at the last fetch. A promotion that started since shows the right state (state is recomputed), but a promotion added to the feed since the last fetch is unknown until the next fetch.
- A supplier promotion name that only repeats the product name is dropped by `MMI_Supplier_Promotion::normalize()` (SkuPort), so SkuPort promotions group by end month.
- One product, several promotions: the feed catalog keeps one current promotion per SKU (`current_promo()`), so a product is in one feed campaign at a time. Hand-made campaigns can hold anything.
