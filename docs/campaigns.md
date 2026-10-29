# Campaigns

A campaign is a promotion window with a name, dates and products. Every supplier promotion the distributor feeds carry (XChange, SkuPort, Plugivery) becomes one automatically; the store's own sales are made by hand. The storefront, the Merchant Center feed and a mailing can then name the window ("XLN Audio Black Friday Sale 2026") instead of showing an anonymous sale price.

Built 2026-10-29 (mmi-data-pipeline 2.61.0) for the owner's sales plan: promotional periods are the lever for mannmade.us sales.

## Where it lives

- Taxonomy `mmi_campaign` on products, public, URL base `/deals/` (`Campaigns::REWRITE`). Products › Campaigns in wp-admin lists them; the product editor assigns them like any term.
- Term meta: `start`, `end` (Y-m-d, store calendar), `supplier` (`xchange`, `skuport`, `plugivery`, or blank for the store's own), `code` (the supplier's promotion code, XChange's "Promo ID"), `assets_url` (the vendor's dealer banner and copy folder), `reverb_sale_id` (the Reverb sales event this campaign's listings join; mmi-reverb-integration reads it), `hero_image_id`.
- Product meta mirror `_mmi_campaign_end` (Y-m-d) so a query loop can sort or filter by "ends soon" without a term join.
- Feed-made terms carry `_mmi_campaign_key` = `supplier|lower-cased promotion name` (or `supplier|YYYY-MM` of the end month for suppliers that name nothing). Hand-made terms have no key and the sync never touches them.

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
