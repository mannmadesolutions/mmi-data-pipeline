<?php
/**
 * Product Data boots here (migration phase 9): its tables, hooks, review API, WP-CLI and daily jobs, so a store runs
 * it with this plugin alone. Loaded inside the license gate: on a lapse nothing new is read or proposed, and stored
 * data keeps showing (the registry and field-lock reading load outside the gate).
 *
 * This plugin is the module's only home (migration phase 9c, 2026-11-10: mmi-admin's copy and the host switch
 * between them are gone). A store's own plugin adds its parts through the module's filters and actions.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This boot is here and registers the module at `plugins_loaded` 20; a store's own plugin registers its parts only
 * when it sees this (2: registers at once when loaded during `plugins_loaded`; 2026-11-10, the first version's
 * priority-20 callback was added while priority 20 ran and so never ran).
 */
const BOOT = 2;

/** WooCommerce and the shared library are there (the module's two needs). */
function can_boot(): bool {
	return class_exists( 'WooCommerce' ) && class_exists( 'MMI_Settings' );
}

/** The module's classes whose hooks register here, in this order. */
const HOOKED = [
	Generator::class, Write::class, Applier::class, VendorFetcher::class, Picker::class, Descriptions::class, Enrichment::class,
	Rest::class, UrlQueue::class, PageReview::class, MapProposals::class, Pictures::class, PictureChoices::class, FeedFill::class,
	VendorMaps::class, BrandSites::class, SharedPages::class, UrlReview::class, PageList::class, WorkbenchColumns::class,
	BrandNames::class, MapCheck::class, ReleaseRead::class, DescLinks::class, ExtBridge::class, Capture::class, Background::class, PageEvents::class,
	Issues::class, WorkbenchCounts::class, Waiting::class, Library::class,
];

/** The module's tables, hooks, review API and CLI. */
function boot(): void {
	static $done = false;
	if ( $done || ! can_boot() ) {
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
		\WP_CLI::add_command( 'mmpt runner', RunnerCli::class );
		\WP_CLI::add_command( 'mmpt extension', ExtensionCli::class );
		\WP_CLI::add_command( 'mmpt library', LibraryCli::class );
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
		if ( ! can_boot() ) {
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
