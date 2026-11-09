<?php
/**
 * Vendor URL triage: closes the review rows a person doesn't need to see.
 *
 * Most held candidates are XChange URLs that differ from a stored URL we
 * already verified (`xchange_feed_differs`). Both pages are read through
 * PageStore and the row is closed when the answer is plain:
 *  - the proposed URL is the stored page under another address (its canonical
 *    link or og:url, or a redirect landing there) → rejected, `auto_same_page`;
 *  - the proposed page is gone (404/410) → rejected, `auto_dead`;
 *  - the proposed URL is a listing ("/products", "/plugins") → rejected, `auto_listing`;
 *  - our page names the product and the proposed one doesn't (another edition
 *    or version: "Restoration Suite" for "Restoration Suite 2") → rejected,
 *    `auto_stored_names`;
 *  - our stored page is gone and the proposed one names the product →
 *    approved and adopted, `xchange_feed_page`.
 * Everything else stays for a person, with a note saying what was found.
 *
 * settle() needs no page reads and covers every open row, triaged or not
 * (owner 2026-10-08, after a bulk approve replaced 69 verified URLs with
 * XChange's family, listing and register pages):
 *  - the proposed URL already is the product's URL → approved, `auto_in_use`;
 *  - our URL is verified and the proposed one is plainly worse → rejected,
 *    `auto_keep_ours`: it's offered for 3+ products (a family page can't be
 *    each one's own page), it's a parent of ours, a listing, category or
 *    malformed address, or our URL names the product and it doesn't.
 *  - the proposed address carries tracking codes or a shop action (`add-to-cart`) → the
 *    address is cleaned (UrlFinder::clean_url()), which may make it the product's URL;
 *  - its stored copy redirects to a basket, checkout, account, login or error page → rejected,
 *    `auto_not_product` (owner 2026-10-22).
 * Every closed row keeps its old reason in the note and can be sent back to
 * review from the Vendor URLs tab.
 *
 * WP-CLI only (`wp mmpt triage-urls`, and nightly inside `find_urls`). Hosts
 * are taken in turn so PageStore's per-host gap rarely makes the run wait.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UrlTriage {

	/** Reasons a row can be closed with (UrlQueue::REASONS labels them). */
	const CLOSED = [ 'auto_same_page', 'auto_dead', 'auto_listing', 'auto_stored_names', 'auto_keep_ours', 'auto_not_product' ];

	/** A proposed URL offered for this many products is a family or brand page. */
	const SHARED_MIN = 3;

	/** A nightly run stops starting new rows after this many seconds. */
	const MAX_SECONDS = 1500;

	/**
	 * @param int  $max Rows this run.
	 * @param bool $dry Fetch and decide, write nothing.
	 * @param int  $seconds Stop starting new rows after this long.
	 * @return array{totals:array<string,int|bool>,rows:array<int,array>}
	 */
	public static function run( int $max = 300, bool $dry = false, int $seconds = self::MAX_SECONDS ): array {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return [ 'totals' => [ 'error' => 'URL triage runs from WP-CLI only.' ], 'rows' => [] ];
		}
		global $wpdb;
		$t    = UrlFinder::table();
		$rows = $wpdb->get_results(
			"SELECT c.product_id, c.url, c.host, c.note, p.post_title, u.meta_value stored,
			   ( SELECT MIN( tm.name ) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id WHERE tr.object_id = c.product_id ) brand
			 FROM {$t} c
			 JOIN {$wpdb->posts} p ON p.ID = c.product_id AND p.post_status = 'publish'
			 JOIN {$wpdb->postmeta} u ON u.post_id = c.product_id AND u.meta_key = 'vendor_product_url' AND u.meta_value <> ''
			 WHERE c.status = 'candidate' AND c.reason = 'xchange_feed_differs' AND c.note NOT LIKE 'triage:%'" . Exclusions::sql_not_excluded( 'c.product_id' ) . "
			 ORDER BY c.product_id",
			ARRAY_A
		);
		$totals = [ 'checked' => 0, 'auto_same_page' => 0, 'auto_dead' => 0, 'auto_listing' => 0, 'auto_stored_names' => 0, 'adopted' => 0, 'kept' => 0, 'not_fetched' => 0, 'dry_run' => $dry ];
		$out    = [];
		$start  = time();
		foreach ( array_slice( self::interleave( $rows ), 0, $max ) as $r ) {
			if ( time() - $start > $seconds ) {
				break;
			}
			if ( PageStore::server_busy() ) {
				sleep( 20 );
				if ( PageStore::server_busy() ) {
					break;
				}
			}
			$totals['checked']++;
			$d = self::decide( $r );
			$totals[ $d['outcome'] ]++;
			$out[] = [ 'id' => (int) $r['product_id'], 'stored' => (string) $r['stored'], 'proposed' => (string) $r['url'] ] + $d;
			if ( ! $dry ) {
				self::write( (int) $r['product_id'], (string) $r['url'], $d );
			}
		}
		if ( ! $dry ) {
			Module::info( 'URL triage run', $totals );
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.triage', [ 'object_type' => 'product', 'details' => $totals ] );
			}
		}
		return [ 'totals' => $totals, 'rows' => $out ];
	}

	/**
	 * One row's decision from the two pages.
	 *
	 * @param array<string,string> $r
	 * @return array{outcome:string,why:string}
	 */
	public static function decide( array $r ): array {
		$proposed = (string) $r['url'];
		$stored   = (string) $r['stored'];
		if ( UrlFinder::same_url( self::plain( $proposed ), self::plain( $stored ) ) ) {
			return [ 'outcome' => 'auto_same_page', 'why' => 'the same address written differently' ];
		}
		if ( self::is_listing( $proposed ) ) {
			return [ 'outcome' => 'auto_listing', 'why' => 'the proposed URL is a listing or non-product page' ];
		}
		$prop = PageStore::get( $proposed );
		if ( in_array( $prop['status'], [ 'http_404', 'http_410' ], true ) ) {
			return [ 'outcome' => 'auto_dead', 'why' => 'the proposed page answers ' . $prop['status'] ];
		}
		if ( $prop['status'] !== 'ok' ) {
			return [ 'outcome' => 'not_fetched', 'why' => 'proposed page: ' . $prop['status'] ];
		}
		$canon = self::canonical( $prop['html'], $proposed );
		if ( $canon !== '' && UrlFinder::same_url( $canon, $stored ) ) {
			return [ 'outcome' => 'auto_same_page', 'why' => 'the proposed page names the stored URL as its canonical address' ];
		}
		$cur = PageStore::get( $stored );
		if ( $cur['status'] === 'ok' ) {
			$cur_canon = self::canonical( $cur['html'], $stored );
			if ( $cur_canon !== '' && ( UrlFinder::same_url( $cur_canon, $proposed ) || ( $canon !== '' && UrlFinder::same_url( $cur_canon, $canon ) ) ) ) {
				return [ 'outcome' => 'auto_same_page', 'why' => 'both URLs name the same canonical page (' . $cur_canon . ')' ];
			}
		}
		$title     = (string) $r['post_title'];
		$brand     = (string) $r['brand'];
		$prop_name = UrlFinder::page_names_product( $prop['title'], $title, $brand );
		if ( in_array( $cur['status'], [ 'http_404', 'http_410' ], true ) ) {
			return $prop_name
				? [ 'outcome' => 'adopted', 'why' => 'the stored page is gone (' . $cur['status'] . '); the proposed page title names the product: ' . $prop['title'] ]
				: [ 'outcome' => 'kept', 'why' => 'the stored page is gone (' . $cur['status'] . '); the proposed page title does not name the product' ];
		}
		if ( $cur['status'] !== 'ok' ) {
			return [ 'outcome' => 'kept', 'why' => 'stored page: ' . $cur['status'] ];
		}
		$cur_name = UrlFinder::page_names_product( $cur['title'], $title, $brand );
		if ( $cur_name && ! $prop_name ) {
			return [ 'outcome' => 'auto_stored_names', 'why' => 'our page "' . $cur['title'] . '" names the product; the proposed "' . $prop['title'] . '" does not' ];
		}
		if ( $cur_name && $prop_name ) {
			return [ 'outcome' => 'kept', 'why' => 'both pages name the product: "' . $cur['title'] . '" / "' . $prop['title'] . '"' ];
		}
		return [ 'outcome' => 'kept', 'why' => $prop_name ? 'only the proposed page names the product: "' . $prop['title'] . '"' : 'neither page title names the product' ];
	}

	/**
	 * Close the open rows whose answer needs no page read (see the class note).
	 *
	 * @return array{totals:array<string,int|bool>,rows:array<int,array>}
	 */
	public static function settle( bool $dry = false ): array {
		global $wpdb;
		$t    = UrlFinder::table();
		$d    = Descriptions::table();
		$rows = $wpdb->get_results(
			"SELECT c.product_id, c.url, c.reason, p.post_title, u.meta_value stored, v.meta_value verified, d.fetch_status,
			   ( SELECT MIN( tm.name ) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id WHERE tr.object_id = c.product_id ) brand
			 FROM {$t} c
			 JOIN {$wpdb->posts} p ON p.ID = c.product_id AND p.post_status = 'publish'
			 LEFT JOIN {$wpdb->postmeta} u ON u.post_id = c.product_id AND u.meta_key = 'vendor_product_url'
			 LEFT JOIN {$wpdb->postmeta} v ON v.post_id = c.product_id AND v.meta_key = 'vendor_product_url_verified'
			 LEFT JOIN {$d} d ON d.product_id = c.product_id
			 WHERE c.status = 'candidate' AND c.url <> ''" . Exclusions::sql_not_excluded( 'c.product_id' ),
			ARRAY_A
		);
		$shared = self::shared_counts();
		$totals = [ 'open' => count( $rows ), 'cleaned' => 0, 'auto_in_use' => 0, 'auto_not_product' => 0, 'auto_keep_ours' => 0, 'dry_run' => $dry ];
		$out    = [];
		foreach ( $rows as $r ) {
			$id     = (int) $r['product_id'];
			$stored = (string) $r['stored'];
			$clean  = UrlFinder::clean_url( (string) $r['url'] );
			if ( $clean !== (string) $r['url'] ) {
				$totals['cleaned']++;
				$out[] = [ 'id' => $id, 'outcome' => 'cleaned', 'stored' => $stored, 'proposed' => $r['url'], 'why' => 'tracking codes or a shop action removed: ' . $clean ];
				if ( ! $dry ) {
					$wpdb->update( $t, [ 'url' => mb_substr( $clean, 0, 500 ) ], [ 'product_id' => $id ] );
				}
				$r['url'] = $clean;
			}
			$pg   = PageStore::row( (string) $r['url'] );
			$land = $pg && in_array( (string) $pg['status'], [ 'moved', 'moved_offsite' ], true ) ? (string) $pg['final_url'] : '';
			if ( UrlFinder::is_not_product_page( (string) $r['url'] ) || ( $land !== '' && UrlFinder::is_not_product_page( $land ) ) ) {
				if ( $stored !== '' && UrlFinder::same_url( self::plain( $stored ), self::plain( (string) $r['url'] ) ) ) {
					continue; // our own URL goes there too: neither adopt nor reject, a person decides
				}
				$totals['auto_not_product']++;
				$why   = $land !== '' ? 'it redirects to ' . $land : 'a basket, checkout, account, login or error address';
				$out[] = [ 'id' => $id, 'outcome' => 'auto_not_product', 'stored' => $stored, 'proposed' => $r['url'], 'why' => $why ];
				if ( ! $dry ) {
					$wpdb->update( $t, [ 'status' => 'rejected', 'reason' => 'auto_not_product', 'note' => mb_substr( 'triage: not a product page: ' . $why . ' (was ' . $r['reason'] . ')', 0, 255 ) ], [ 'product_id' => $id ] );
				}
				continue;
			}
			$title  = html_entity_decode( (string) $r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $stored !== '' && UrlFinder::same_url( self::plain( $stored ), self::plain( (string) $r['url'] ) ) ) {
				$totals['auto_in_use']++;
				$out[] = [ 'id' => $id, 'outcome' => 'auto_in_use', 'stored' => $stored, 'proposed' => $r['url'], 'why' => 'already the product\'s URL' ];
				if ( ! $dry ) {
					$wpdb->update( $t, [ 'status' => 'approved', 'reason' => 'auto_in_use', 'note' => mb_substr( 'triage: already the product\'s URL (was ' . $r['reason'] . ')', 0, 255 ) ], [ 'product_id' => $id ] );
					UrlFinder::adopt( $id, $stored ); // two sources agree: verified
				}
				continue;
			}
			if ( ! str_starts_with( $stored, 'http' ) || ! UrlFinder::is_verified( $r['verified'] ) || in_array( (string) $r['fetch_status'], [ 'http_404', 'http_410' ], true ) ) {
				continue; // no working verified URL of ours to keep
			}
			$why = self::worse_than_ours( (string) $r['url'], $stored, $title, (string) $r['brand'], $shared );
			if ( $why === '' ) {
				continue;
			}
			$totals['auto_keep_ours']++;
			$out[] = [ 'id' => $id, 'outcome' => 'auto_keep_ours', 'stored' => $stored, 'proposed' => $r['url'], 'why' => $why ];
			if ( ! $dry ) {
				$wpdb->update( $t, [ 'status' => 'rejected', 'reason' => 'auto_keep_ours', 'note' => mb_substr( 'triage: our verified URL is better: ' . $why . ' (was ' . $r['reason'] . ')', 0, 255 ) ], [ 'product_id' => $id ] );
			}
		}
		if ( ! $dry && ( $totals['cleaned'] || $totals['auto_in_use'] || $totals['auto_not_product'] || $totals['auto_keep_ours'] ) ) {
			Module::info( 'URL triage settle', $totals );
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.settle', [ 'object_type' => 'product', 'details' => $totals ] );
			}
		}
		return [ 'totals' => $totals, 'rows' => $out ];
	}

	/** Why a proposed URL is plainly worse than our verified one ('' when it isn't). */
	public static function worse_than_ours( string $proposed, string $stored, string $title, string $brand, array $shared ): string {
		$why = [];
		$n   = $shared[ self::key( $proposed ) ] ?? 1;
		if ( $n >= self::SHARED_MIN ) {
			$why[] = "the proposed page is offered for {$n} products";
		}
		if ( strpos( self::key( $stored ), self::key( $proposed ) . '/' ) === 0 ) {
			$why[] = 'the proposed page is a parent of ours';
		}
		$decoded = rawurldecode( (string) wp_parse_url( $proposed, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $proposed, PHP_URL_QUERY ) );
		if ( self::is_listing( $proposed ) || preg_match( '~cat-view|[?&](cat|category|family)=~i', $proposed ) ) {
			$why[] = 'the proposed URL is a listing or category page';
		} elseif ( preg_match( '/\s/', $decoded ) ) {
			$why[] = 'the proposed URL is malformed';
		}
		if ( UrlFinder::slug_in_title( $stored, $title, $brand ) && ! UrlFinder::slug_in_title( $proposed, $title, $brand ) ) {
			$why[] = 'our URL names the product and the proposed one does not';
		}
		return implode( '; ', $why );
	}

	/** Normalised address → how many products it's proposed for (any status). @return array<string,int> */
	private static function shared_counts(): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT url, COUNT( DISTINCT product_id ) n FROM ' . UrlFinder::table() . " WHERE url <> '' GROUP BY url", ARRAY_A ) as $r ) {
			$k         = self::key( (string) $r['url'] );
			$out[ $k ] = ( $out[ $k ] ?? 0 ) + (int) $r['n'];
		}
		return $out;
	}

	/** Host and path without scheme, "www." or a trailing slash, lowercase. */
	private static function key( string $url ): string {
		return strtolower( rtrim( (string) preg_replace( '#^https?://(www\.)?#i', '', self::plain( $url ) ), '/' ) );
	}

	/** Listing segments: the whole last segment must be one ("/plugins", "/products/"); "/all-plugins-bundle" is a product. */
	const LISTING = '/^(plugins|products|instruments|effects|bundles|collections|libraries|packs|sounds|expansions|accessories|marketing|shop|store|software)$/i';

	/** A site root (with or without a query such as a WordPress preview link), a file, or a page whose last segment is a listing word. */
	public static function is_listing( string $url ): bool {
		$p    = wp_parse_url( $url );
		$path = trim( (string) ( $p['path'] ?? '' ), '/' );
		if ( $path === '' ) {
			return true;
		}
		if ( preg_match( '/\.(pdf|zip|dmg|exe|pkg|jpe?g|png)$/i', $path ) ) {
			return true;
		}
		$segs = explode( '/', $path );
		return (bool) preg_match( self::LISTING, (string) end( $segs ) );
	}

	/** An address with escapes decoded and a trailing index page dropped ("/sit/index.html" is "/sit/"). */
	public static function plain( string $url ): string {
		return (string) preg_replace( '#/index\.(html?|php)$#i', '/', rawurldecode( trim( $url ) ) );
	}

	/** The page's canonical link (else og:url), absolute; '' when it has none. */
	public static function canonical( string $html, string $base ): string {
		$href = '';
		if ( preg_match( '/<link\b[^>]*\brel\s*=\s*["\']?canonical\b[^>]*>/i', $html, $m ) && preg_match( '/\bhref\s*=\s*["\']([^"\']+)["\']/i', $m[0], $h ) ) {
			$href = $h[1];
		} elseif ( preg_match( '/<meta\b[^>]*\bproperty\s*=\s*["\']og:url["\'][^>]*>/i', $html, $m ) && preg_match( '/\bcontent\s*=\s*["\']([^"\']+)["\']/i', $m[0], $h ) ) {
			$href = $h[1];
		}
		$href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( $href === '' ) {
			return '';
		}
		if ( strpos( $href, '//' ) === 0 ) {
			return 'https:' . $href;
		}
		if ( $href[0] === '/' ) {
			$p = wp_parse_url( $base );
			return ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . $href;
		}
		return preg_match( '#^https?://#i', $href ) ? $href : '';
	}

	/** @param array{outcome:string,why:string} $d */
	private static function write( int $id, string $url, array $d ): void {
		global $wpdb;
		$t    = UrlFinder::table();
		$note = mb_substr( 'triage: ' . $d['why'] . ' (was xchange_feed_differs)', 0, 255 );
		if ( in_array( $d['outcome'], self::CLOSED, true ) ) {
			$wpdb->update( $t, [ 'status' => 'rejected', 'reason' => $d['outcome'], 'note' => $note ], [ 'product_id' => $id ] );
		} elseif ( $d['outcome'] === 'adopted' ) {
			$wpdb->update( $t, [ 'status' => 'approved', 'reason' => 'xchange_feed_page', 'score' => UrlFinder::FEED_SCORE, 'note' => $note ], [ 'product_id' => $id ] );
			UrlFinder::adopt( $id, $url );
		} elseif ( $d['outcome'] === 'kept' ) {
			$wpdb->update( $t, [ 'note' => $note ], [ 'product_id' => $id ] ); // stays open; the note tells the reviewer what was found
		}
		// not_fetched: untouched, the next run tries again.
	}

	/**
	 * Rows ordered host by host in turn (a, b, c, a, b, c…), so consecutive
	 * fetches rarely hit the same host's gap.
	 *
	 * @param array<int,array> $rows
	 * @return array<int,array>
	 */
	private static function interleave( array $rows ): array {
		$by = [];
		foreach ( $rows as $r ) {
			$by[ (string) $r['host'] ][] = $r;
		}
		$out = [];
		while ( $by ) {
			foreach ( $by as $h => &$list ) {
				$out[] = array_shift( $list );
				if ( ! $list ) {
					unset( $by[ $h ] );
				}
			}
			unset( $list );
		}
		return $out;
	}
}
