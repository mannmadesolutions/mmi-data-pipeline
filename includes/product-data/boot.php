<?php
/**
 * Product Data boots here (migration phase 9): its tables, hooks, review API, WP-CLI and daily jobs, so a store runs
 * it with this plugin alone. Loaded inside the license gate: on a lapse nothing new is read or proposed, and stored
 * data keeps showing (the registry and field-lock reading load outside the gate).
 *
 * A store's own plugin can carry its own copy of the module and say which runs (the host switch,
 * `mmpt_product_data_host()`: 'pipeline' or 'admin'). This file boots the module unless that switch says another
 * plugin runs it; that plugin then registers everything itself, and here nothing does.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This boot is here and registers the module at `plugins_loaded` 20. A store's own copy of the module stands down
 * only when it sees this (2: registers at once when loaded during `plugins_loaded`; 2026-11-10, the first version's
 * priority-20 callback was added while priority 20 ran and so never ran).
 */
const BOOT = 2;

/** Whether this plugin runs Product Data: no other copy is installed, or the host switch hands it here. */
function hosts(): bool {
	return ! function_exists( 'mmpt_product_data_host' ) || mmpt_product_data_host() === 'pipeline';
}

/** WooCommerce and the shared library are there (the module's two needs). */
function can_boot(): bool {
	return class_exists( 'WooCommerce' ) && class_exists( 'MMI_Settings' );
}

/** The module's classes whose hooks register here, in this order. */
const HOOKED = [
	Generator::class, Write::class, Applier::class, VendorFetcher::class, Picker::class, Descriptions::class, Enrichment::class,
	Rest::class, UrlQueue::class, PageReview::class, MapProposals::class, Pictures::class, PictureChoices::class, FeedFill::class,
	VendorMaps::class, BrandSites::class, SharedPages::class, UrlReview::class, PageList::class, WorkbenchColumns::class,
	BrandNames::class, MapCheck::class, ReleaseRead::class, DescLinks::class,
];

/** The module's tables, hooks, review API and CLI (only where this plugin runs it). */
function boot(): void {
	static $done = false;
	if ( $done || ! can_boot() || ! hosts() ) {
		return;
	}
	$done = true;
	foreach ( [ Store::class, Descriptions::class, UrlFinder::class, PageStore::class, Enrichment::class, BrandSites::class ] as $c ) {
		$c::maybe_install();
	}
	foreach ( HOOKED as $c ) {
		$c::register_hooks();
	}
	add_filter( 'mmi_pipeline_title_rules_screen', '__return_true' ); // the Workbench's title views: their routes are registered (Rest)
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'mmpt images', ImagesCli::class );
		\WP_CLI::add_command( 'mmpt maps', MapsCli::class );
	}
}

// This file is loaded by the licensed boot inside `plugins_loaded` (priority 20): register now, since a callback added
// to the priority that is running is never called. Loaded earlier, it waits for priority 20.
if ( doing_action( 'plugins_loaded' ) || did_action( 'plugins_loaded' ) ) {
	boot();
} else {
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot', 20 );
}

// No activation hook for a module: its daily jobs are (re)scheduled lazily.
add_action(
	'init',
	static function () {
		if ( ! can_boot() || ! hosts() ) {
			return;
		}
		Generator::schedule_daily();
		Applier::schedule_daily();
		Descriptions::schedule_daily();
		Enrichment::schedule_daily();
		VendorMaps::schedule_daily();
	},
	30
);
