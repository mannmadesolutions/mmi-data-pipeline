<?php
/**
 * What a maker site's map needs next, step by step (owner 2026-11-14, testing Sick Noise Instruments: "using both the
 * extension and the 'Makers' tab admin UI, it is unclear how to proceed with fixing any sitemap issues"). The same
 * steps show in the Chrome extension's panel on the maker's site (ExtBridge::steps_section()) and in Makers' Map tab
 * (the gaps reply's `steps`).
 *
 * A site "Needs a map" (VendorMaps::status_of()) while its map has no picks and the reader, without one, finds a
 * gallery (2+ pictures) or a full description on fewer than half of its stored pages. So, in order: start the map (a
 * site with none); send a site that refuses the server; approve or reject the parts Claude proposed; pick each part
 * the reader misses, for the whole site, on a page of its largest layout (a pick saved for one layout leaves the site
 * map without it, and the site still needs one); pick what a page shows that nothing reads, on that product's page;
 * then a Fill, whose first run confirms the map.
 *
 * Each step: key, title, why, done (a pick made), and where it is done: `page` (url + part: the live page, the
 * mapper open on that part, saving for the site), `start` (the map is started from the extension or Makers), `send`
 * (from the browser) or `makers` (tab: map or fill). Kept per site for CACHE_S under everything it is built from.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MapSteps {

	const CACHE_S = 600;
	const VERSION = 3; // in the cache key: new wording shows at once
	/** The parts the status reads (coverage field => what the step picks). */
	const WEAK = [
		'pictures'    => [ 'its pictures', 'a gallery (2 or more pictures)', 'a single picture' ],
		'description' => [ 'its description', 'a full description', 'a short one' ],
	];
	/** A gaps column => the map part that reads it (SiteGaps::FIELDS: pictures are images, requirements os). */
	const PART = [ 'images' => 'pictures', 'description' => 'description', 'features' => 'features', 'os' => 'requirements', 'manual' => 'manual', 'videos' => 'videos' ];
	/** Cells where a page shows a part and nothing reads it: on the page, nothing reads it / needs a part (ExtBridge::WORK_CELLS). */
	const PICK_CELLS = [ 'S', 'P' ];

	/**
	 * The site's steps, in order. $m: SiteGaps::matrix() when the caller has it (it takes seconds on a large site).
	 * @return array<int,array{key:string,title:string,why:string,done:bool,where:string,url?:string,part?:string,layout?:int,tab?:string}>
	 */
	public static function of( string $host, ?array $m = null ): array {
		$host = VendorMaps::host( $host );
		$map  = VendorMaps::get( $host );
		if ( ! $map ) {
			return self::unmapped( $host );
		}
		$status = VendorMaps::status_of( $map );
		if ( $status === 'off' ) {
			return [];
		}
		$waiting = count( MapProposals::rows( $host, 'proposed' ) );
		$picks   = array_map( static fn( $f ) => (string) ( $f['sel'] ?? '' ), (array) $map['fields'] );
		$key     = 'mmpt_map_steps_' . md5( (string) wp_json_encode( [ self::VERSION, $host, $status, $picks, $waiting, $map['coverage']['_at'] ?? '', $map['gaps']['at'] ?? '', SiteGaps::stamp( $host ) ] ) );
		$kept    = get_transient( $key );
		if ( is_array( $kept ) ) {
			return $kept;
		}
		$m ??= SiteGaps::matrix( $host, false );
		if ( ! $m ) {
			return [];
		}
		$steps = [];
		if ( $status === 'refused' ) {
			$steps[] = [ 'key' => 'send', 'title' => 'Send this site from your browser', 'why' => 'It refuses the server, so the readers have little of its pages. Send this site stores them as your browser shows them.', 'done' => false, 'where' => 'send' ];
		}
		if ( $waiting ) {
			$steps[] = [ 'key' => 'approve', 'title' => sprintf( 'Approve or reject the %d proposed %s', $waiting, $waiting === 1 ? 'part' : 'parts' ), 'why' => 'Claude found them on the site’s stored pages. Approved, a part is the site’s pick.', 'done' => false, 'where' => 'makers', 'tab' => 'map' ];
		}
		$weak  = self::weak_steps( $host, $map, $status, $picks, self::main_layout( $m ) );
		$steps = array_merge( $steps, $weak, self::page_steps( $m, $weak ), self::fill_step( (array) ( $m['fill'] ?? [] ), $status ) );
		set_transient( $key, $steps, self::CACHE_S );
		return $steps;
	}

	/**
	 * A step per part the status reads that the reader misses without a pick (VendorMaps::status_of()): picked for the
	 * whole site on a page of the largest layout. On a draft the ones picked stay, ticked.
	 */
	private static function weak_steps( string $host, array $map, string $status, array $picks, ?array $main ): array {
		$cov = (array) $map['coverage'];
		$of  = (int) ( $cov['_of'] ?? 0 );
		if ( $status !== 'needs' && ! ( $status === 'draft' && $of ) ) {
			return [];
		}
		$steps = [];
		foreach ( self::WEAK as $part => [ $what, $whole, $some ] ) {
			$found = (int) ( $cov[ $part ][0] ?? 0 );
			if ( $of && $found / $of >= 0.5 ) {
				continue; // the reader finds it without a pick (counted without the map, so a weak part stays weak once picked)
			}
			$partly  = (int) ( $cov[ $part ][1] ?? 0 );
			$picked  = ( $picks[ $part ] ?? '' ) !== '';
			$own     = $picked ? '' : self::layout_only( $host, $part );
			$where   = $main ? sprintf( ' on a Layout %s page (%d of its %d pages)', self::letter( $main['i'] ), $main['pages'], $of ) : '';
			$why     = sprintf( 'Without a pick the reader finds %s on %d of its %d stored pages', $whole, $found, $of ) . ( $partly ? sprintf( ', and only %s on %d', $some, $partly ) : '' ) . '.';
			$tail    = $own !== '' ? ' ' . $own : ' Picked for the whole site, it counts for the map.';
			$steps[] = [
				'key'   => 'pick:' . $part,
				'title' => $picked ? ucfirst( $what ) . ' picked for the site' : 'Pick ' . $what . ' for the whole site' . $where,
				'why'   => $why . ( $picked ? ' Your pick reads it now.' : $tail ),
				'done'  => $picked,
				'where' => 'page',
			] + ( $main ? [ 'url' => $main['url'], 'part' => $part, 'layout' => $main['i'] ] : [ 'part' => $part ] );
		}
		return $steps;
	}

	/** A step per part our products' pages show that nothing reads, on the first such page; not a part $weak already asks for. */
	private static function page_steps( array $m, array $weak ): array {
		$asked = array_flip( array_column( array_filter( $weak, static fn( $s ) => ! $s['done'] ), 'part' ) );
		$steps = [];
		foreach ( self::unread( $m ) as $field => $rows ) {
			$part = self::PART[ $field ];
			if ( isset( $asked[ $part ] ) ) {
				continue; // its step for the whole site comes first
			}
			$label   = strtolower( VendorMaps::FIELDS[ $part ][0] ?? $part );
			$many    = count( $rows );
			$steps[] = [
				'key'   => 'page:' . $part,
				'title' => sprintf( 'Pick its %s on the page of %s', $label, $rows[0]['title'] ),
				'why'   => $many === 1 ? sprintf( 'That page shows %s, and nothing reads them.', $label ) : sprintf( '%d of our products’ pages show %s, and nothing reads them. Picked on one, the part reads them all.', $many, $label ),
				'done'  => false,
				'where' => 'page',
				'url'   => $rows[0]['url'],
				'part'  => $part,
			];
		}
		return $steps;
	}

	/** The Fill: the one that confirms a draft, the one to come once a part is picked, or one with products to fill. */
	private static function fill_step( array $fill, string $status ): array {
		$n     = (int) ( $fill['products'] ?? 0 );
		$wait  = (string) ( $fill['why'] ?? '' );
		$these = sprintf( '%d %s', $n, $n === 1 ? 'product' : 'products' );
		$base  = [ 'key' => 'fill', 'done' => false, 'where' => 'makers', 'tab' => 'fill' ];
		if ( ! empty( $fill['confirm'] ) ) {
			return [ $base + [ 'title' => 'Confirm the map with a Fill', 'why' => 'In Makers → Fill: it writes what the pages add to ' . $these . ', and its first run confirms the map.' . ( $wait !== '' ? ' Waiting: ' . $wait : '' ) ] ];
		}
		if ( $status === 'needs' ) {
			return [ $base + [ 'title' => 'Then confirm the map with a Fill', 'why' => 'Once a part is picked for the site, Makers → Fill writes what the pages add, and its first run confirms the map.' ] ];
		}
		return $n && $wait === '' ? [ $base + [ 'title' => 'Fill ' . $these, 'why' => 'In Makers → Fill: what the pages add, with Undo.' ] ] : [];
	}

	/** The first step not done, or null. */
	public static function now( array $steps ): ?array {
		foreach ( $steps as $s ) {
			if ( ! $s['done'] ) {
				return $s;
			}
		}
		return null;
	}

	/** A site with no map: its pages first, then Start. */
	private static function unmapped( string $host ): array {
		$stored = count( array_filter( VendorMaps::pages( $host ), static fn( $p ) => ( $p['status'] ?? '' ) === 'ok' ) );
		if ( ! $stored ) {
			return [ [ 'key' => 'pages', 'title' => 'Find its products’ pages', 'why' => 'A map starts from our products’ stored pages on the site, and none is stored yet. Decide them under Makers → Pages, or Store this page on one.', 'done' => false, 'where' => 'makers', 'tab' => 'products' ] ];
		}
		return [ [ 'key' => 'start', 'title' => 'Start this site’s map', 'why' => sprintf( 'It reads our products’ %d stored pages for where the site keeps each part, and groups them into layouts.', $stored ), 'done' => false, 'where' => 'start' ] ];
	}

	/** The site's largest layout of 2+ pages, with a stored page of it: {i, pages, url}, or null. */
	private static function main_layout( array $m ): ?array {
		$best = null;
		foreach ( (array) ( $m['layouts'] ?? [] ) as $l ) {
			$url = (string) ( $l['samples'][0] ?? '' );
			if ( (int) $l['pages'] >= 2 && $url !== '' && ( ! $best || (int) $l['pages'] > $best['pages'] ) ) {
				$best = [ 'i' => (int) $l['i'], 'pages' => (int) $l['pages'], 'url' => $url ];
			}
		}
		return $best;
	}

	/** A part picked for a layout only: what that means for the map ('' when none is). */
	private static function layout_only( string $host, string $part ): string {
		foreach ( MapTypes::layouts_view( $host ) as $l ) {
			if ( ! empty( $l['type']['own'][ $part ]['sel'] ) ) {
				return sprintf( 'It is picked for Layout %s only, which leaves the site’s map without it: pick it again and save it for the whole site.', self::letter( (int) $l['i'] ) );
			}
		}
		return '';
	}

	/** Our products' pages that show a part nothing reads, per gaps column, first found first. @return array<string,array<int,array{title:string,url:string}>> */
	private static function unread( array $m ): array {
		$out = [];
		foreach ( (array) $m['rows'] as $r ) {
			foreach ( self::PART as $field => $part ) {
				if ( in_array( (string) ( $r['cells'][ $field ][0] ?? '' ), self::PICK_CELLS, true ) && (string) ( $r['url'] ?? '' ) !== '' ) {
					$out[ $field ][] = [ 'title' => html_entity_decode( (string) $r['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'url' => (string) $r['url'] ];
				}
			}
		}
		return $out;
	}

	/** Layout 0 → A, as the gaps name them. */
	private static function letter( int $i ): string {
		return $i < 26 ? chr( 65 + $i ) : (string) ( $i + 1 );
	}
}
