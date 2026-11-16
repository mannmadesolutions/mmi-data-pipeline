<?php
/**
 * A layout's map (owner 2026-11-15, Sick Noise Instruments: "Some layouts will not have '.row.pick' content … the
 * extension should allow the user to quickly define rendered DOM elements if they exist and omit '.row.pick' if they
 * don't exist in the vendor product URL template. After selection, I want to see a 'Commit vendor product map' button
 * for whatever template the user is working on with a summary of how it will affect the … products … And if the user
 * commits, I want to see that reflected in [Makers] clearly").
 *
 * A layout (a vendor product page template: SiteGaps groups a site's stored pages by structure) reads each part with
 * its own pick (MapTypes), the site map's pick, or the general reader; here a person can also say a part isn't on its
 * pages at all ("Not on this layout"): its products' gaps for that part then read as the page having none (SiteGaps;
 * a page that shows it, with nothing reading it, keeps saying so),
 * and the map's steps stop asking for it (MapSteps). Commit puts the layout's map in use (its type confirmed, with the
 * site's picks it reads kept as they are: MapTypes::set_status()) and fills its products, through the same Fill run
 * Makers uses (FillRun: its Last fills list it, with Undo). It is refused while the map would take away anything the
 * general reader finds on the layout's pages (VendorMaps::losses(); owner 2026-10-10: data only augments).
 *
 * Kept in OPTION per host, per layout, matched by the layout's structure (MapTypes::alike()), so a regrouping that
 * renumbers the layouts keeps the decisions: [ host => [ [ sig, none: { part: {at, by} }, commit: {at, by, stamp,
 * products, scrape, pictures, run} ] ] ].
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LayoutMaps {

	const OPTION = 'mmpt_layout_maps';
	/** The parts a layout's map lists, as map parts → their gaps column (SiteGaps::FIELDS). */
	const PARTS = [ 'pictures' => 'images', 'description' => 'description', 'features' => 'features', 'requirements' => 'os', 'manual' => 'manual', 'videos' => 'videos' ];
	/** Gaps cells by what they mean for the summary. */
	const ADDS   = [ 'A', 'Q' ];
	const HAS    = [ 'H', 'K', 'L' ];
	const UNREAD = [ 'S', 'P', 'N' ];
	/** Pages read twice for what the map would take away while the panel shows a layout (every page at commit). */
	const CHECK_PAGES = 40;

	/** The layout's record (none, commit), or an empty one. */
	public static function entry( string $host, int $i ): array {
		$sig = (array) ( MapTypes::layout_pages( $host )[ $i ]['sig'] ?? [] );
		foreach ( self::all( $host ) as $e ) {
			if ( $sig && MapTypes::alike( (array) $e['sig'], $sig ) ) {
				return $e;
			}
		}
		return [ 'sig' => $sig, 'none' => [], 'commit' => null ];
	}

	/** The gaps columns each layout's pages don't have, by layout index (SiteGaps closes those gaps). @return array<int,string[]> */
	public static function none_by_layout( string $host ): array {
		$all = self::all( $host );
		if ( ! $all ) {
			return [];
		}
		$out = [];
		foreach ( MapTypes::layout_pages( $host ) as $i => $l ) {
			foreach ( $all as $e ) {
				if ( $e['none'] && $l['sig'] && MapTypes::alike( (array) $e['sig'], (array) $l['sig'] ) ) {
					$out[ (int) $i ] = array_values( array_intersect_key( self::PARTS, (array) $e['none'] ) );
				}
			}
		}
		return $out;
	}

	/** A part isn't (or is again, $on false) on layout $i's pages. @return true|\WP_Error */
	public static function set_none( string $host, int $i, string $part, bool $on ) {
		if ( ! isset( self::PARTS[ $part ] ) ) {
			return new \WP_Error( 'mmpt_layout_part', 'That part can’t be set for a layout.' );
		}
		$was = ! empty( self::entry( $host, $i )['none'][ $part ] );
		$res = self::change(
			$host,
			$i,
			static function ( array $e ) use ( $part, $on ): array {
				if ( $on ) {
					$e['none'][ $part ] = [ 'at' => current_time( 'mysql', true ), 'by' => get_current_user_id() ];
				} else {
					unset( $e['none'][ $part ] );
				}
				return $e;
			}
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		VendorMaps::queue( 'refresh', VendorMaps::host( $host ) ); // the site's numbers follow
		PartHistory::record( $host, 'layoutnone', (array) ( MapTypes::layout_pages( $host )[ $i ]['sig'] ?? [] ), $part, $was, $on );
		self::audit( $on ? 'vendor_maps.layout_none' : 'vendor_maps.layout_none_undo', $host, [ 'layout' => $i, 'part' => $part ] );
		return true;
	}

	/**
	 * Layout $i's map as a person reads it: each part's criteria (its own pick, the site's, the general reader, or not on
	 * its pages) with what it does on the layout's products now (the gaps), what a commit fills, what blocks it, and
	 * whether it is committed. $m: SiteGaps::matrix() when the caller has it. $check: what blocks it, read from its pages
	 * (CHECK_PAGES of them, kept under the map's stamp; 0: every page); null leaves it out (Makers' list, the steps).
	 */
	public static function view( string $host, int $i, ?array $m = null, ?int $check = null ): ?array {
		$host = VendorMaps::host( $host );
		$row  = VendorMaps::get( $host );
		$l    = MapTypes::layout_pages( $host )[ $i ] ?? null;
		if ( ! $row || ! $l ) {
			return null;
		}
		$m    ??= SiteGaps::matrix( $host, false );
		$rows   = array_values( array_filter( (array) ( $m['rows'] ?? [] ), static fn( $r ) => (int) $r['layout'] === $i ) );
		$t      = MapTypes::for_layout( $host, $i );
		$t      = $t && ! is_wp_error( $t ) ? $t : null;
		$e      = self::entry( $host, $i );
		$parts  = [];
		foreach ( self::PARTS as $part => $col ) {
			$parts[ $part ] = self::part_view( $part, $col, $rows, $t, $row, ! empty( $e['none'][ $part ] ) );
		}
		$ids    = array_map( static fn( $r ) => (int) $r['id'], $rows );
		$fill   = (array) ( $m['fill'] ?? [] );
		$scrape = array_values( array_intersect( (array) ( $fill['scrape'] ?? [] ), $ids ) );
		$pics   = array_values( array_intersect( (array) ( $fill['pictures'] ?? [] ), $ids ) );
		$stamp  = self::stamp( $t, $row, $e );
		$commit = $e['commit'] ? $e['commit'] + [ 'who' => self::who( (int) ( $e['commit']['by'] ?? 0 ) ) ] : null;
		return [
			'i'         => $i,
			'letter'    => self::letter( $i ),
			'pages'     => count( (array) $l['urls'] ),
			'url'       => (string) ( $l['urls'][0] ?? '' ), // one of its pages, to open in the extension
			'products'  => count( $rows ),
			'parts'     => $parts,
			'fills'     => count( array_unique( array_merge( $scrape, $pics ) ) ),
			'scrape'    => $scrape,
			'pictures'  => $pics,
			'blocked'   => $check === null ? [] : array_values( $why = self::blockers( $host, $m, (array) $l['urls'], $check ) ),
			'losses'    => $check === null ? [] : array_diff_key( $why, [ 'pending' => 1 ] ), // part → why its commit waits on it
			'recheck'   => ! empty( $m['pending'] ) ? [ 'left' => (int) $m['pending'], 'of' => max( (int) $m['pending'], (int) ( $m['ready']['read'] ?? 0 ) ) ] : null, // the re-read a commit waits on
			'status'    => ! $commit ? 'draft' : ( (string) ( $commit['stamp'] ?? '' ) === $stamp && ( $t['status'] ?? '' ) === 'confirmed' ? 'committed' : 'changed' ),
			'committed' => $commit,
			'stamp'     => $stamp,
		];
	}

	/**
	 * Commit layout $i's map: put it in use on its pages (its type confirmed: its own picks, and the site's it reads as
	 * they are now) and fill its products (a Fill run, with Undo in Makers' Last fills). @return array|\WP_Error
	 */
	public static function commit( string $host, int $i ) {
		$host = VendorMaps::host( $host );
		$m    = SiteGaps::matrix( $host, false );
		$v    = $m ? self::view( $host, $i, $m, 0 ) : null; // every page read for what it would take away
		if ( ! $v ) {
			return new \WP_Error( 'mmpt_layout', 'That layout is no longer measured: the site’s pages were grouped again. Open one of its pages again.' );
		}
		if ( $v['blocked'] ) {
			return new \WP_Error( 'mmpt_layout_blocked', implode( ' ', $v['blocked'] ) );
		}
		$t = MapTypes::for_layout( $host, $i, true );
		if ( is_wp_error( $t ) || ! $t ) {
			return is_wp_error( $t ) ? $t : new \WP_Error( 'mmpt_layout', 'The layout couldn’t be kept.' );
		}
		$ok = MapTypes::set_status( $host, (string) $t['type_key'], true );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$run = '';
		$n   = 0;
		$p   = 0;
		if ( ( $v['scrape'] || $v['pictures'] ) && ! empty( $m['writes']['enabled'] ) ) {
			$run = FillRun::start( $host, $v['scrape'], $v['pictures'] );
			$n   = $v['scrape'] ? Scraper::enqueue( $v['scrape'], 'scrape', false, 'manual' ) : 0;
			if ( $n ) {
				Scraper::kick();
			}
			$p = $v['pictures'] ? (int) PageEvents::queue_pictures( $v['pictures'], 'manual' )['queued'] : 0;
		}
		$t      = MapTypes::for_layout( $host, $i );
		$record = [ 'at' => current_time( 'mysql', true ), 'by' => get_current_user_id(), 'stamp' => self::stamp( is_array( $t ) ? $t : null, VendorMaps::get( $host ), self::entry( $host, $i ) ), 'products' => $v['products'], 'scrape' => $n, 'pictures' => $p, 'run' => $run ];
		self::change( $host, $i, static fn( array $e ) => array_merge( $e, [ 'commit' => $record ] ) );
		self::audit( 'vendor_maps.layout_commit', $host, [ 'layout' => $i, 'products' => $v['products'], 'scrape' => $n, 'pictures' => $p, 'run' => $run ] );
		return $record + [ 'letter' => $v['letter'], 'fills' => $v['fills'], 'writes' => ! empty( $m['writes']['enabled'] ) ];
	}

	/** How many of the site's layouts of 2+ pages there are, and how many have a commit (the site list's badge). */
	public static function tally( string $host ): array {
		$all = self::all( $host );
		$n   = 0;
		$k   = 0;
		foreach ( MapTypes::layout_pages( $host ) as $l ) {
			if ( count( (array) $l['urls'] ) < 2 ) {
				continue;
			}
			++$n;
			foreach ( $all as $e ) {
				if ( $e['commit'] && $l['sig'] && MapTypes::alike( (array) $e['sig'], (array) $l['sig'] ) ) {
					++$k;
					break;
				}
			}
		}
		return [ 'layouts' => $n, 'committed' => $k ];
	}

	/** Every layout of 2+ pages, as Makers lists them. @return array<int,array> */
	public static function views( string $host, ?array $m = null ): array {
		$m ??= SiteGaps::matrix( $host, false );
		$out = [];
		foreach ( MapTypes::layout_pages( $host ) as $i => $l ) {
			if ( count( (array) $l['urls'] ) >= 2 ) {
				$v = self::view( $host, (int) $i, $m );
				if ( $v ) {
					$out[] = array_diff_key( $v, [ 'scrape' => 1, 'pictures' => 1 ] );
				}
			}
		}
		return $out;
	}

	/** One part: where its criteria come from and what it does on the layout's products. */
	private static function part_view( string $part, string $col, array $rows, ?array $t, array $site, bool $none ): array {
		$own   = (array) ( $t['fields'][ $part ] ?? [] );
		$sitef = (array) ( $site['fields'][ $part ] ?? [] );
		$n     = [ 'adds' => 0, 'has' => 0, 'gap' => 0, 'unread' => 0, 'none' => 0 ];
		foreach ( $rows as $r ) {
			$c = (string) ( $r['cells'][ $col ][0] ?? '' );
			$k = in_array( $c, self::ADDS, true ) ? 'adds' : ( in_array( $c, self::HAS, true ) ? 'has' : ( $c === 'G' ? 'gap' : ( in_array( $c, self::UNREAD, true ) ? 'unread' : ( $c === '-' ? 'none' : '' ) ) ) );
			if ( $k !== '' ) {
				++$n[ $k ];
			}
		}
		$source = $none ? 'none' : ( ! empty( $own['sel'] ) ? 'layout' : ( ! empty( $own['generic'] ) ? 'generic' : ( ! empty( $sitef['sel'] ) ? 'site' : 'generic' ) ) );
		$sel    = $source === 'layout' ? (string) $own['sel'] : ( $source === 'site' ? (string) $sitef['sel'] : '' );
		return [ 'label' => VendorMaps::FIELDS[ $part ][0] ?? $part, 'source' => $source, 'sel' => $sel, 'found' => (int) ( ( $source === 'layout' ? $own : $sitef )['found'] ?? 0 ), 'of' => (int) ( ( $source === 'layout' ? $own : $sitef )['of'] ?? 0 ), 'counts' => $n, 'pickable' => $part !== 'features' ];
	}

	/**
	 * What stops a commit, keyed: `pending` for the re-read, else each part the map would take away (owner 2026-11-15: "Why not? What
	 * needs to happen for that to happen?": the panel names the part and says on its row what to do). @return array<string,string>
	 */
	private static function blockers( string $host, ?array $m, array $urls, int $max ): array {
		if ( ! empty( $m['pending'] ) ) {
			$of = max( (int) $m['pending'], (int) ( $m['ready']['read'] ?? 0 ) );
			return [ 'pending' => sprintf( 'The site’s pages are being read again with the map as it is now (%d of %d left), so the review can show what committing really writes. Commit opens when they’re done.', (int) $m['pending'], $of ) ];
		}
		$key  = 'mmpt_layout_loss_' . md5( (string) wp_json_encode( [ $host, SiteGaps::stamp( $host ), $urls, $max ] ) );
		$lost = get_transient( $key );
		if ( ! is_array( $lost ) ) {
			$lost = VendorMaps::losses( $host, $urls, $max );
			set_transient( $key, $lost, DAY_IN_SECONDS );
		}
		if ( ! $lost ) {
			return [];
		}
		$by = [];
		foreach ( $lost as $url => $parts ) {
			foreach ( $parts as $part => [ $was, $now ] ) {
				$by[ $part ][] = [ $url, $was, $now ];
			}
		}
		$out = [];
		foreach ( $by as $part => $pages ) {
			[ $url, $was, $now ] = $pages[0];
			$out[ $part ] = sprintf( '%s: this map reads less than the general reader on %d %s (%s: %s → %s). Pick a larger area for the part that covers it, or set that part back to the general reader.', VendorMaps::FIELDS[ $part ][0] ?? ucfirst( $part ), count( $pages ), count( $pages ) === 1 ? 'page' : 'pages', self::short( $url ), number_format( $was ), number_format( $now ) );
		}
		return $out;
	}

	/** What the layout's map is made of now: a commit of another one is out of date. */
	private static function stamp( ?array $t, ?array $row, array $e ): string {
		$picks = static fn( array $f ) => array_map( static fn( $x ) => [ (string) ( $x['sel'] ?? '' ), (string) ( $x['mode'] ?? '' ), ! empty( $x['generic'] ) ], $f );
		return substr( md5( (string) wp_json_encode( [ $picks( (array) ( $t['fields'] ?? [] ) ), $picks( MapTypes::usable( (array) ( $row['fields'] ?? [] ) ) ), array_keys( (array) $e['none'] ) ] ) ), 0, 12 );
	}

	/** @return array<int,array> the host's records */
	private static function all( string $host ): array {
		$all = get_option( self::OPTION, [] );
		return is_array( $all ) ? (array) ( $all[ VendorMaps::host( $host ) ] ?? [] ) : [];
	}

	/** Change layout $i's record. @return true|\WP_Error */
	private static function change( string $host, int $i, callable $edit ) {
		$host = VendorMaps::host( $host );
		$sig  = (array) ( MapTypes::layout_pages( $host )[ $i ]['sig'] ?? [] );
		if ( ! $sig ) {
			return new \WP_Error( 'mmpt_layout', 'That layout is no longer measured: the site’s pages were grouped again. Open one of its pages again.' );
		}
		$all  = get_option( self::OPTION, [] );
		$all  = is_array( $all ) ? $all : [];
		$list = (array) ( $all[ $host ] ?? [] );
		$at   = null;
		foreach ( $list as $k => $e ) {
			if ( MapTypes::alike( (array) $e['sig'], $sig ) ) {
				$at = $k;
				break;
			}
		}
		$e = $edit( $at === null ? [ 'sig' => $sig, 'none' => [], 'commit' => null ] : $list[ $at ] );
		if ( $at === null ) {
			$list[] = $e;
		} else {
			$list[ $at ] = $e;
		}
		$all[ $host ] = array_values( array_filter( $list, static fn( $x ) => $x['none'] || $x['commit'] ) );
		update_option( self::OPTION, $all, false );
		return true;
	}

	private static function who( int $id ): string {
		$u = $id ? get_userdata( $id ) : null;
		return $u ? (string) $u->display_name : '';
	}

	private static function short( string $url ): string {
		$p = wp_parse_url( $url );
		return preg_replace( '/^www\./', '', (string) ( $p['host'] ?? '' ) ) . ( $p['path'] ?? '' );
	}

	/** Layout 0 → A, as the gaps name them. */
	public static function letter( int $i ): string {
		return $i < 26 ? chr( 65 + $i ) : (string) ( $i + 1 );
	}

	private static function audit( string $action, string $host, array $details ): void {
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', $action, [ 'object_type' => 'vendor_map', 'object_id' => VendorMaps::host( $host ), 'details' => $details ] );
		}
	}
}
