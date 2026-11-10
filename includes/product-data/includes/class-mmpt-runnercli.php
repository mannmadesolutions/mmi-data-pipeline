<?php
/**
 * `wp mmpt runner`: the module's own runners, which Scraper::spawn() starts in the background. They were
 * subcommands of a site plugin's `wp mmpt` until migration phase 10d, so a store without it had none to start.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RunnerCli {

	/**
	 * Work through the maker-page scrape queue until it is empty. Started in the background by a fill, a commit to a
	 * layout's products and the nightly read; safe to run by hand: a second runner exits at once.
	 */
	public function scrape(): void {
		\WP_CLI::log( wp_json_encode( Scraper::run() ) );
	}

	/**
	 * Look for a brand's product pages on its maker site. Matches become proposed URLs waiting for a person.
	 *
	 * ## OPTIONS
	 * --term=<id>
	 * : The brand's term id.
	 *
	 * @subcommand brand-pages
	 */
	public function brand_pages( $args, $assoc ): void {
		$r = BrandSites::find_pages( (int) ( $assoc['term'] ?? 0 ) );
		if ( isset( $r['error'] ) ) {
			\WP_CLI::error( $r['error'] );
		}
		\WP_CLI::log( wp_json_encode( $r ) );
	}
}
