<?php
/**
 * Cross-plugin feature catalog — every feature one MMI plugin adds to another.
 *
 * Single source for the locked-feature hover text, the "Works with" strip on
 * each plugin's page, and mmi-admin/docs/cross-plugin-features.md. Read by
 * MMI_Feature_Gate::features(). Add an entry in the same change that adds a
 * cross-plugin feature (AGENTS.md › Gated Feature Disclosure).
 *
 * Keys:
 *   provider   Slug of the plugin that must be active for the feature to work.
 *   consumers  Slugs of the plugins whose screens show it, or
 *              '<slug>:<module>' for one module's screens (mmi-distributors
 *              has one page per distributor: 'mmi-distributors:xchange').
 *   title      Short name, shown on the tooltip's first line.
 *   pitch      One sentence, what the customer gets. Plain words, no hype.
 *   kind       'ui' (something on screen is greyed) or 'background' (no screen
 *              element; listed in the "Works with" strip only).
 *   status     'live' or 'planned'. Planned entries are documentation only and
 *              never rendered.
 *
 * mmi-admin is never a provider here: it is site-only and not sold.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

return array(

	/* ── Provider: mmi-reverb-integration ────── */
	'reverb.order_source'   => array(
		'provider'  => 'mmi-reverb-integration',
		'consumers' => array( 'mmi-distributors:xchange' ),
		'title'     => 'Reverb orders',
		'pitch'     => 'Marks every order that came from Reverb and links straight to it on Reverb.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'reverb.closeout'       => array(
		'provider'  => 'mmi-reverb-integration',
		'consumers' => array( 'mmi-distributors:xchange' ),
		'title'     => 'Reverb close-out',
		'pitch'     => 'After the license email goes out, messages the buyer on Reverb and marks the order shipped.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'reverb.email_request'  => array(
		'provider'  => 'mmi-reverb-integration',
		'consumers' => array( 'mmi-distributors:xchange' ),
		'title'     => 'Ask Reverb buyers for their email',
		'pitch'     => 'Asks a Reverb buyer for their real email address through Reverb messages, so the license reaches them.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'reverb.order_fees'     => array(
		'provider'  => 'mmi-reverb-integration',
		'consumers' => array( 'mmi-distributors:xchange' ),
		'title'     => 'Reverb fees in margins',
		'pitch'     => 'Takes Reverb\'s selling and payment fees out of each order, so the margin shown is the real payout.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'reverb.buyer_grouping' => array(
		'provider'  => 'mmi-reverb-integration',
		'consumers' => array( 'mmi-distributors:xchange' ),
		'title'     => 'One buyer, one delivery',
		'pitch'     => 'Groups a Reverb buyer\'s separate orders so they are fulfilled together with one email.',
		'kind'      => 'background',
		'status'    => 'live',
	),
	'reverb.competitors'    => array(
		'provider'  => 'mmi-reverb-integration',
		'consumers' => array( 'mmi-google-services' ),
		'title'     => 'Reverb competitors',
		'pitch'     => 'Lists the Reverb shops selling what you sell, how often they undercut your price and how many sales they make a day.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'reverb.cogs_backfill'  => array(
		'provider'  => 'mmi-reverb-integration',
		'consumers' => array( 'mmi-distributors:xchange' ),
		'title'     => 'Costs on Reverb orders',
		'pitch'     => 'Writes XChange purchase costs onto past Reverb orders, for real profit per sale.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'reverb.stock_push'     => array(
		'provider'  => 'mmi-reverb-integration',
		'consumers' => array( 'mmi-data-pipeline' ),
		'title'     => 'Stock to Reverb',
		'pitch'     => 'Sends stock changes from Catalog Maintenance to your Reverb listings as they happen.',
		'kind'      => 'background',
		'status'    => 'live',
	),
	'reverb.cc_match'       => array(
		'provider'  => 'mmi-reverb-integration',
		'consumers' => array( 'mmi-accounting' ),
		'title'     => 'Match card charges to Reverb orders',
		'pitch'     => 'Matches card and bank charges to Reverb orders and records them as order costs.',
		'kind'      => 'ui',
		'status'    => 'live',
	),

	/* ── Provider: mmi-distributors (XChange module) ────── */
	'xchange.listing_margin'   => array(
		'provider'  => 'mmi-distributors',
		'consumers' => array( 'mmi-reverb-integration' ),
		'title'     => 'Cost and margin per listing',
		'pitch'     => 'Shows each Reverb listing\'s XChange cost, profit and margin on the Products tab.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'xchange.software_delivery' => array(
		'provider'  => 'mmi-distributors',
		'consumers' => array( 'mmi-reverb-integration' ),
		'title'     => 'One-click software delivery',
		'pitch'     => 'Buys the license from XChange, emails it to the buyer and closes the Reverb order in one click.',
		'kind'      => 'background',
		'status'    => 'live',
	),
	'xchange.auto_cogs'        => array(
		'provider'  => 'mmi-distributors',
		'consumers' => array( 'mmi-reverb-integration' ),
		'title'     => 'Automatic order costs',
		'pitch'     => 'Records the XChange cost on every Reverb order as it is imported.',
		'kind'      => 'background',
		'status'    => 'live',
	),
	'xchange.web_assets'       => array(
		'provider'  => 'mmi-distributors',
		'consumers' => array( 'mmi-data-pipeline' ),
		'title'     => 'XChange web assets',
		'pitch'     => 'Adds XChange\'s product images, long descriptions and system requirements to your imports.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'xchange.field_presets'    => array(
		'provider'  => 'mmi-distributors',
		'consumers' => array( 'mmi-data-pipeline' ),
		'title'     => 'XChange import presets',
		'pitch'     => 'Three ready-made Field Mapping presets for XChange products, prices and stock.',
		'kind'      => 'background',
		'status'    => 'live',
	),
	'xchange.credentials'      => array(
		'provider'  => 'mmi-distributors',
		'consumers' => array( 'mmi-data-pipeline' ),
		'title'     => 'One set of XChange credentials',
		'pitch'     => 'Feed fetches use the credentials saved in MMI Distributors, so they are entered once.',
		'kind'      => 'background',
		'status'    => 'live',
	),

	/* ── Provider: mmi-data-pipeline ────── */
	'pipeline.source_filter' => array(
		'provider'  => 'mmi-data-pipeline',
		'consumers' => array( 'mmi-reverb-integration' ),
		'title'     => 'Filter by supplier data',
		'pitch'     => 'Filters products by any field in your supplier feeds, such as vendor, format or promotion.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'pipeline.catalog'       => array(
		'provider'  => 'mmi-data-pipeline',
		'consumers' => array( 'mmi-distributors:xchange', 'mmi-distributors:skuport', 'mmi-distributors:plugivery' ),
		'title'     => 'Supplier catalog browser',
		'pitch'     => 'Fetches the supplier\'s full catalog on a schedule and lets you browse, filter and compare it here.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'pipeline.feed_counts'   => array(
		'provider'  => 'mmi-data-pipeline',
		'consumers' => array( 'mmi-distributors:xchange', 'mmi-distributors:plugivery' ),
		'title'     => 'Catalog counts and read quota',
		'pitch'     => 'Shows how many products and images each vendor has in the feed, and how much of the API quota is left.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'pipeline.merchant_fix'  => array(
		'provider'  => 'mmi-data-pipeline',
		'consumers' => array( 'mmi-google-services' ),
		'title'     => 'Fix product data at the source',
		'pitch'     => 'Links each Merchant Center data problem to the import field mapping that fixes it for good.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'pipeline.po_sizes'      => array(
		'provider'  => 'mmi-data-pipeline',
		'consumers' => array( 'mmi-po' ),
		'title'     => 'Sizes from supplier feeds',
		'pitch'     => 'Fills missing weights and box sizes on purchase orders from your supplier feeds.',
		'kind'      => 'background',
		'status'    => 'live',
	),

	/* ── Provider: mmi-data-health ────── */
	'health.score' => array(
		'provider'  => 'mmi-data-health',
		'consumers' => array( 'mmi-reverb-integration', 'mmi-distributors:xchange', 'mmi-distributors:skuport', 'mmi-distributors:plugivery', 'mmi-seo' ),
		'title'     => 'Data Health score',
		'pitch'     => 'Scores every product\'s data and lists exactly what to fix, using checks from each of your MMI plugins.',
		'kind'      => 'ui',
		'status'    => 'live',
	),

	/* ── Provider: mmi-accounting ────── */
	'accounting.transactions' => array(
		'provider'  => 'mmi-accounting',
		'consumers' => array( 'mmi-reverb-integration' ),
		'title'     => 'Card and bank transactions',
		'pitch'     => 'Imports card and bank transactions and matches them to orders, for profit you can reconcile.',
		'kind'      => 'ui',
		'status'    => 'live',
	),

	/* ── Provider: mmi-po ────── */
	'po.shipstation' => array(
		'provider'  => 'mmi-po',
		'consumers' => array( 'mmi-reverb-integration' ),
		'title'     => 'ShipStation tracking and label costs',
		'pitch'     => 'Sends ShipStation tracking to Reverb automatically and counts each label\'s cost in the order\'s margin.',
		'kind'      => 'ui',
		'status'    => 'live',
	),

	/* ── Provider: mmi-google-services ────── */
	'google.search_data'     => array(
		'provider'  => 'mmi-google-services',
		'consumers' => array( 'mmi-seo' ),
		'title'     => 'Google search data',
		'pitch'     => 'Shows each page\'s Google clicks and impressions in the SEO box, with a one-click index check.',
		'kind'      => 'ui',
		'status'    => 'live',
	),
	'google.sitemap_resubmit' => array(
		'provider'  => 'mmi-google-services',
		'consumers' => array( 'mmi-seo' ),
		'title'     => 'Sitemap resubmitted to Google',
		'pitch'     => 'Resubmits your sitemap to Search Console whenever pages change, and checks it daily.',
		'kind'      => 'background',
		'status'    => 'live',
	),

	/* ── Provider: mmi-seo ────── */
	'seo.product_schema' => array(
		'provider'  => 'mmi-seo',
		'consumers' => array( 'mmi-google-services' ),
		'title'     => 'One product schema',
		'pitch'     => 'Merchant data is added to MMI SEO\'s product schema, so each page has one complete Product entry.',
		'kind'      => 'background',
		'status'    => 'live',
	),

	/* ── Planned (documentation only, never rendered) ────── */
	'seo.registry_schema'      => array(
		'provider'  => 'mmi-data-pipeline',
		'consumers' => array( 'mmi-seo' ),
		'title'     => 'Product specs in schema',
		'pitch'     => 'Adds GTIN, MPN, operating system and other product specs to product schema.',
		'kind'      => 'background',
		'status'    => 'planned',
	),
	'pipeline.supplier_promotions' => array(
		'provider'  => 'mmi-data-pipeline',
		'consumers' => array( 'mmi-distributors:xchange', 'mmi-distributors:skuport', 'mmi-distributors:plugivery' ),
		'title'     => 'Promotions vs. your price',
		'pitch'     => 'Compares each supplier promotion with your current price in the catalog browser.',
		'kind'      => 'ui',
		'status'    => 'planned',
	),
	'pipeline.plugivery_stock' => array(
		'provider'  => 'mmi-data-pipeline',
		'consumers' => array( 'mmi-distributors:plugivery' ),
		'title'     => 'Plugivery stock sync',
		'pitch'     => 'Keeps product stock in step with the Plugivery feed.',
		'kind'      => 'background',
		'status'    => 'planned',
	),
	'pipeline.supplier_presets' => array(
		'provider'  => 'mmi-distributors',
		'consumers' => array( 'mmi-data-pipeline' ),
		'title'     => 'SkuPort and Plugivery import presets',
		'pitch'     => 'Ready-made Field Mapping presets for SkuPort and Plugivery feeds.',
		'kind'      => 'ui',
		'status'    => 'planned',
	),
	'plugivery.fulfillment'    => array(
		'provider'  => 'mmi-distributors',
		'consumers' => array( 'mmi-reverb-integration' ),
		'title'     => 'Plugivery software delivery',
		'pitch'     => 'Buys licenses from Plugivery and delivers them, like XChange and SkuPort.',
		'kind'      => 'background',
		'status'    => 'planned',
	),
	'google.product_health'    => array(
		'provider'  => 'mmi-google-services',
		'consumers' => array( 'mmi-data-health' ),
		'title'     => 'Merchant Center checks in Data Health',
		'pitch'     => 'Adds Merchant Center GTIN and brand checks to the Data Health score.',
		'kind'      => 'ui',
		'status'    => 'planned',
	),
	'seo.organization_policy'  => array(
		'provider'  => 'mmi-google-services',
		'consumers' => array( 'mmi-seo' ),
		'title'     => 'Return and shipping policy schema',
		'pitch'     => 'Publishes your return and shipping policy in Organization schema for Google.',
		'kind'      => 'background',
		'status'    => 'planned',
	),
);
