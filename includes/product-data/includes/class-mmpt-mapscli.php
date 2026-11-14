<?php
/**
 * `wp mmpt maps …` — vendor maps (VendorMaps; VENDOR_MAPS_HANDOFF.md).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MapsCli {

	/**
	 * Measure sites and draft their maps (a confirmed map keeps its fields).
	 *
	 * ## OPTIONS
	 * [<host>...]
	 * : Sites, e.g. antelopeaudio.com.
	 * [--all]
	 * : Every site with a verified product page.
	 */
	public function draft( $args, $assoc ): void {
		$hosts = ! empty( $assoc['all'] ) ? VendorMaps::hosts() : $args;
		if ( ! $hosts ) {
			\WP_CLI::error( 'Name a site or use --all.' );
		}
		foreach ( $hosts as $h ) {
			\WP_CLI::log( wp_json_encode( VendorMaps::draft( (string) $h ) ) );
		}
	}

	/**
	 * Propose every part's element for sites (MapProposals): for the whole site and the layouts it misses. Nothing is
	 * used until a person approves it (the extension's mapper, or Product Data's Site maps).
	 *
	 * ## OPTIONS
	 * [<host>...]
	 * : Sites, e.g. soundiron.com.
	 * [--top=<n>]
	 * : The n sites with the most stored pages.
	 * [--all]
	 * : Every site with a map.
	 */
	public function propose( $args, $assoc ): void {
		$hosts = $args;
		if ( ! empty( $assoc['all'] ) || ! empty( $assoc['top'] ) ) {
			global $wpdb;
			$hosts = $wpdb->get_col( 'SELECT host FROM ' . SiteGaps::table() . ' WHERE product_id = 0 GROUP BY host ORDER BY COUNT(*) DESC' . ( ! empty( $assoc['top'] ) ? ' LIMIT ' . max( 1, (int) $assoc['top'] ) : '' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( ! $hosts ) {
			\WP_CLI::error( 'Name a site, or use --top=<n> or --all.' );
		}
		foreach ( $hosts as $h ) {
			$t = microtime( true );
			\WP_CLI::log( wp_json_encode( MapProposals::propose( (string) $h ) + [ 's' => round( microtime( true ) - $t, 1 ) ] ) );
		}
	}

	/**
	 * A site's proposals with what they read on sample products.
	 *
	 * ## OPTIONS
	 *
	 * <host>
	 * : The site, e.g. soundiron.com.
	 *
	 * [--json]
	 * : All of it, as JSON.
	 */
	public function proposals( $args, $assoc ): void {
		$rows = MapProposals::rows( (string) $args[0] );
		if ( ! empty( $assoc['json'] ) ) {
			\WP_CLI::log( wp_json_encode( array_map( [ MapProposals::class, 'out' ], $rows ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
			return;
		}
		foreach ( $rows as $r ) {
			\WP_CLI::log( sprintf( '#%d %s %s [%s] %s · %d/%d · %s%s', $r['id'], $r['layout'] < 0 ? 'site' : 'layout ' . chr( 65 + $r['layout'] ), $r['field'], $r['mode'], $r['sel'], $r['found'], $r['of'], $r['status'], $r['note'] !== '' ? ' · ' . $r['note'] : '' ) );
			foreach ( $r['samples'] as $x ) {
				\WP_CLI::log( sprintf( '      #%d %s: %d · %s', $x['id'], mb_substr( $x['title'], 0, 50 ), $x['n'], mb_substr( $x['short'] !== '' ? $x['short'] : $x['detail'], 0, 110 ) ) );
			}
		}
	}

	/**
	 * Review a proposal (the reviewer's note, and a better element tested again).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The proposal.
	 *
	 * <note>
	 * : What the reviewer found. Start it with the verdict: "Checked: …" (the element reads the part), "Changed: …"
	 * (a better element, with --sel), "Added: …" or "Careful: …" (it can read the wrong thing: look before approving).
	 *
	 * [--sel=<css>]
	 * : A better element.
	 *
	 * [--mode=<mode>]
	 * : Its way of reading.
	 *
	 * [--verdict=<verdict>]
	 * : careful, changed, added or checked, when the note doesn't start with it.
	 */
	public function proposal( $args, $assoc ): void {
		$res = MapProposals::review( (int) $args[0], (string) $args[1], (string) ( $assoc['sel'] ?? '' ), (string) ( $assoc['mode'] ?? '' ), (string) ( $assoc['verdict'] ?? '' ) );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_message() );
		}
		\WP_CLI::log( sprintf( '#%d %s %s · %d/%d · %s', $res['id'], $res['field'], $res['sel'], $res['found'], $res['of'], $res['note'] ) );
	}

	/**
	 * Add the reviewer's own proposal for a part (a whole site: layout -1).
	 *
	 * ## OPTIONS
	 *
	 * <host>
	 * : The site.
	 *
	 * <layout>
	 * : Its layout index (A = 0), or -1 for the whole site.
	 *
	 * <field>
	 * : The part.
	 *
	 * <sel>
	 * : The element.
	 *
	 * <note>
	 * : What the reviewer found.
	 *
	 * [--mode=<mode>]
	 * : Its way of reading.
	 *
	 * [--verdict=<verdict>]
	 * : careful when it can read the wrong thing; added otherwise (the default).
	 *
	 * @subcommand proposal-add
	 */
	public function proposal_add( $args, $assoc ): void {
		$res = MapProposals::add( (string) $args[0], (int) $args[1], (string) $args[2], (string) $args[3], (string) ( $assoc['mode'] ?? '' ), (string) $args[4], (string) ( $assoc['verdict'] ?? '' ) );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_message() );
		}
		\WP_CLI::log( sprintf( '#%d %s %s · %d/%d · %s', $res['id'], $res['field'], $res['sel'], $res['found'], $res['of'], $res['note'] ) );
	}

	/**
	 * Does a site draw its pages with scripts? Draws a few of its stored pages in the browser and compares (nothing
	 * stored); where most show more, its pages are read in the browser from now on and queued to be read again.
	 *
	 * ## OPTIONS
	 * [<host>...]
	 * : Sites, e.g. airmusictech.com.
	 * [--all]
	 * : Every site whose pages are read as plain HTML.
	 *
	 * @subcommand render-check
	 */
	public function render_check( $args, $assoc ): void {
		$hosts = ! empty( $assoc['all'] ) ? VendorMaps::hosts() : $args;
		if ( ! $hosts ) {
			\WP_CLI::error( 'Name a site or use --all.' );
		}
		foreach ( $hosts as $h ) {
			while ( PageStore::server_busy() ) {
				sleep( 15 );
			}
			\WP_CLI::log( wp_json_encode( VendorMaps::render_check( (string) $h ), JSON_UNESCAPED_SLASHES ) );
		}
	}

	/**
	 * Test a selector on a site's stored pages.
	 *
	 * ## OPTIONS
	 *
	 * <host>
	 * : The site, e.g. antelopeaudio.com.
	 *
	 * <field>
	 * : pictures, description, features, requirements, version, manual or videos.
	 *
	 * <selector>
	 * : Where on the page, e.g. ".product-gallery img"; several parts separated by commas.
	 *
	 * [--mode=<mode>]
	 * : img, text, follow, list, links or embed.
	 */
	public function test( $args, $assoc ): void {
		[ $host, $field, $sel ] = $args;
		$xp = VendorMaps::css_to_xpath( $sel, $field === 'pictures' );
		if ( is_wp_error( $xp ) ) {
			\WP_CLI::error( $xp->get_error_message() );
		}
		\WP_CLI::log( 'XPath: ' . $xp );
		\WP_CLI::log( wp_json_encode( VendorMaps::test( $host, $field, $sel, (string) ( $assoc['mode'] ?? '' ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Screenshot a site's sample page with its mapped parts outlined.
	 *
	 * ## OPTIONS
	 *
	 * <host>
	 * : The site.
	 */
	public function shot( $args ): void {
		\WP_CLI::log( wp_json_encode( VendorMaps::shot( (string) $args[0] ) ) );
	}

	/**
	 * Show a site's map.
	 *
	 * ## OPTIONS
	 *
	 * <host>
	 * : The site.
	 */
	public function show( $args ): void {
		$r = VendorMaps::get( (string) $args[0] );
		if ( ! $r ) {
			\WP_CLI::error( 'No map for this site.' );
		}
		unset( $r['shot']['boxes'] );
		\WP_CLI::log( wp_json_encode( $r + [ 'list_status' => VendorMaps::status_of( $r ) ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Re-test confirmed maps on the stored pages (the nightly health check, at once).
	 * A field that stopped matching is marked broken and the reader stops using it.
	 *
	 * ## OPTIONS
	 * [<host>...]
	 * : Sites; every confirmed map when none is named.
	 */
	public function health( $args ): void {
		foreach ( $args ?: VendorMaps::confirmed_hosts() as $h ) {
			\WP_CLI::log( wp_json_encode( VendorMaps::health( (string) $h ) ) );
		}
	}

	/**
	 * What a site's confirmed map fills on one product, without writing (dry run).
	 *
	 * ## OPTIONS
	 *
	 * <product_id>
	 * : The product.
	 */
	public function extras( $args ): void {
		global $wpdb;
		$url = (string) get_post_meta( (int) $args[0], 'vendor_product_url', true );
		$f   = $url !== '' ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT html_file FROM ' . PageStore::table() . " WHERE url_hash = %s AND status = 'ok'", md5( $url ) ) ) : '';
		if ( $f === '' ) {
			\WP_CLI::error( 'No stored page for this product.' );
		}
		\WP_CLI::log( wp_json_encode( [ 'url' => $url, 'stamp' => VendorMaps::stamp( $url ), 'extras' => VendorMaps::extras( $url, PageStore::read( $f ) ) ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Bring sites' list numbers up to what the reader does now: with a confirmed map, what its
	 * pages give; for every site, who gets vendor pictures (what the runner does after a change).
	 *
	 * ## OPTIONS
	 * [<host>...]
	 * : Sites; every confirmed map when none is named.
	 */
	public function refresh( $args ): void {
		foreach ( $args ?: VendorMaps::confirmed_hosts() as $h ) {
			\WP_CLI::log( wp_json_encode( VendorMaps::refresh( (string) $h ) ) );
		}
	}

	/**
	 * Read each site's pages for its gaps (SiteGaps): every product against its page, with the map as it is now.
	 * Pages already read under the current map are skipped. Writes only the gaps' own table and the map row.
	 *
	 * ## OPTIONS
	 * [<host>...]
	 * : Sites (default: every site with a map row).
	 *
	 * ## EXAMPLES
	 *     wp mmpt maps gaps waves.com
	 */
	public function gaps( $args ): void {
		global $wpdb;
		$hosts = $args ?: $wpdb->get_col( 'SELECT host FROM ' . VendorMaps::table() . ' ORDER BY host' ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $hosts as $h ) {
			while ( PageStore::server_busy() ) {
				sleep( 15 );
			}
			\WP_CLI::log( wp_json_encode( SiteGaps::read( (string) $h ) ) );
		}
	}

	/**
	 * Scrape now: read one product's page afresh (in the server's browser when the site's map says so) for the editor's
	 * preview. Only the page store changes; the product is not written.
	 *
	 * ## OPTIONS
	 *
	 * <host>
	 * : The site.
	 *
	 * <id>
	 * : The product.
	 *
	 * [--browser]
	 * : Read it in the server's browser whatever the site's setting.
	 *
	 * @subcommand scrape-now
	 */
	public function scrape_now( $args, $assoc = [] ): void {
		\WP_CLI::log( wp_json_encode( ProductPreview::scrape( (string) $args[0], (int) $args[1], isset( $assoc['browser'] ) ) ) );
	}

	/**
	 * Write one product from its stored page with the map as it is in the editor (the draft stands in), as Confirm
	 * map & fill would; what changed is kept on the product for Undo. Started by the editor's "Write to this product".
	 *
	 * ## OPTIONS
	 *
	 * <host>
	 * : The site.
	 *
	 * <id>
	 * : The product.
	 *
	 * [--only=<parts>]
	 * : Comma-separated parts to write (the Chrome extension's ticked parts); all when left out.
	 *
	 * @subcommand write-one
	 */
	public function write_one( $args, $assoc = [] ): void {
		$only = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $assoc['only'] ?? '' ) ) ) ) );
		\WP_CLI::log( wp_json_encode( ProductPreview::write( (string) $args[0], (int) $args[1], $only ) ) );
	}

	/** The runner: drafts, screenshots, health checks and refreshes queued from the tab, the scrape runner or the daily event. Safe to run by hand. */
	public function run(): void {
		\WP_CLI::log( wp_json_encode( VendorMaps::run() ) );
	}
}
