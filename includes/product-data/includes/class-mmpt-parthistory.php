<?php
/**
 * Undo and Redo for a site's map, part by part (owner 2026-11-15: "The user should be able to enter a '.row.pick' element
 * at any time and undo work, redo work, mark 'Not in the layout' or 'not in this product'").
 *
 * Four things say how a part is read, and each change to one is kept here with what it was before and after:
 * - `site`: the site's pick (VendorMaps fields),
 * - `layout`: a layout's own pick (its MapTypes type), the layout known by its signature,
 * - `layoutnone`: the part isn't on a layout's pages (LayoutMaps none),
 * - `productnone`: the part isn't on one product's page (SiteGaps::decide_none()).
 * The models record their own changes (VendorMaps::set_field(), LayoutMaps::set_none(), SiteGaps::decide_none(), and
 * the extension's clear), so a change made from the mapper, the panel or Makers can be undone from any of them.
 *
 * Each target (a part in one of those places) keeps its changes done and undone. Undo on a product's page takes the
 * newest change among the targets that page reads through (the site, its layout, its product); Redo the newest undone.
 * A target changed some other way since (its value isn't the one recorded) is refused rather than overwritten.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PartHistory {

	const OPTION = 'mmpt_part_history'; // host → target key → { done: entry[], undone: entry[] }
	const MAX    = 15;                  // changes kept per target, each way
	const KINDS  = [ 'site', 'layout', 'layoutnone', 'productnone' ];
	/** Parts a product's page can be said not to have, as their gaps column (SiteGaps::FIELDS): a layout's, and audio demos. */
	const PRODUCT_COLS = LayoutMaps::PARTS + [ 'audio' => 'audio' ];

	/** True while an undo or redo applies a change: the models' own record() calls are that change, not a new one. */
	private static bool $applying = false;
	/** The site's layouts, per host, for this request. */
	private static array $layouts = [];

	/**
	 * Keep a change. $ref: the layout's signature (layout, layoutnone), the product's ID (productnone), null (site).
	 * $before / $after: a pick (array) or null, or a none decision (bool). A new change drops the target's Redo.
	 */
	public static function record( string $host, string $kind, $ref, string $part, $before, $after ): void {
		if ( self::$applying || ! in_array( $kind, self::KINDS, true ) || self::same( $kind, $before, $after ) ) {
			return;
		}
		$host = VendorMaps::host( $host );
		$key  = self::key( $kind, $ref, $part );
		$all  = self::all();
		$t    = (array) ( $all[ $host ][ $key ] ?? [ 'done' => [], 'undone' => [] ] );
		$t['done'][] = [ 'kind' => $kind, 'ref' => $ref, 'part' => $part, 'before' => $before, 'after' => $after, 'at' => current_time( 'mysql', true ), 'seq' => microtime( true ), 'by' => get_current_user_id() ];
		$t['done']   = array_slice( $t['done'], -self::MAX );
		$t['undone'] = [];
		$all[ $host ][ $key ] = $t;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * What Undo and Redo would do for $part on a page of layout $sig (null: a page of no layout) whose product is $id:
	 * [ 'undo' => entry|null, 'redo' => entry|null ], each with `said` (in words).
	 */
	public static function state( string $host, string $part, ?array $sig, int $id ): array {
		$all  = (array) ( self::all()[ VendorMaps::host( $host ) ] ?? [] );
		$out  = [ 'undo' => null, 'redo' => null ];
		foreach ( self::keys( $all, $part, $sig, $id ) as $k ) {
			foreach ( [ 'undo' => 'done', 'redo' => 'undone' ] as $way => $list ) {
				$l = (array) ( $all[ $k ][ $list ] ?? [] );
				$e = $l ? end( $l ) : null;
				if ( $e && ( ! $out[ $way ] || (float) $e['seq'] > (float) $out[ $way ]['seq'] ) ) {
					$out[ $way ] = $e;
				}
			}
		}
		foreach ( $out as $way => $e ) {
			if ( $e ) {
				$out[ $way ]['said'] = self::said( $host, $e );
			}
		}
		return $out;
	}

	/**
	 * Undo ($redo false) or Redo the newest change to $part this page reads through (state()). @return string|\WP_Error
	 * what was done, in words
	 */
	public static function step( string $host, string $part, ?array $sig, int $id, bool $redo ) {
		$host = VendorMaps::host( $host );
		$e    = self::state( $host, $part, $sig, $id )[ $redo ? 'redo' : 'undo' ];
		if ( ! $e ) {
			return new \WP_Error( 'mmpt_history', $redo ? 'Nothing to redo for this part.' : 'Nothing to undo for this part.' );
		}
		$from = $redo ? $e['before'] : $e['after'];
		$to   = $redo ? $e['after'] : $e['before'];
		$now  = self::current( $host, $e );
		if ( is_wp_error( $now ) ) {
			return $now;
		}
		if ( ! self::same( $e['kind'], $now, $from ) ) {
			self::drop( $host, $e ); // it can't be taken back any more: keep it from being offered again
			return new \WP_Error( 'mmpt_history_moved', sprintf( '%s changed another way since, so it wasn’t %s. Pick it again on this page.', ucfirst( self::said( $host, $e ) ), $redo ? 'redone' : 'undone' ) );
		}
		self::$applying = true;
		try {
			$res = self::apply( $host, $e, $to );
		} finally {
			self::$applying = false;
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::move( $host, $e, $redo );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', $redo ? 'vendor_maps.part_redo' : 'vendor_maps.part_undo', [ 'object_type' => 'vendor_map', 'object_id' => $host, 'details' => [ 'part' => $part, 'kind' => $e['kind'], 'at' => $e['at'] ] ] );
		}
		return sprintf( '%s: %s.', $redo ? 'Redone' : 'Undone', self::said( $host, $e ) );
	}

	/** An entry in words: "Layout A’s pick of System requirements (Nov 15, 8:04 pm)". */
	public static function said( string $host, array $e ): string {
		$label = VendorMaps::FIELDS[ $e['part'] ][0] ?? $e['part'];
		$when  = mysql2date( 'M j, g:i a', get_date_from_gmt( (string) $e['at'] ) );
		$i     = in_array( $e['kind'], [ 'layout', 'layoutnone' ], true ) ? self::layout_index( $host, (array) $e['ref'] ) : null;
		$lay   = $i === null ? 'a layout that was grouped again' : 'Layout ' . LayoutMaps::letter( $i );
		$what  = match ( $e['kind'] ) {
			'site'        => sprintf( 'the site’s pick of %s', $label ),
			'layout'      => sprintf( '%s’s pick of %s', $lay, $label ),
			'layoutnone'  => sprintf( $e['after'] ? '%s marked not on %s’s pages' : '%s marked on %s’s pages again', $label, $lay ),
			'productnone' => sprintf( $e['after'] ? '%s marked not on this product’s page' : '%s marked on this product’s page again', $label ),
		};
		return sprintf( '%s (%s)', $what, $when );
	}

	/** The layout's index now, from its signature; null once the site's pages were grouped again. */
	public static function layout_index( string $host, array $sig ): ?int {
		$host                   = VendorMaps::host( $host );
		self::$layouts[ $host ] ??= MapTypes::layout_pages( $host ); // said() asks for each row's Undo and Redo
		foreach ( self::$layouts[ $host ] as $i => $l ) {
			if ( $sig && MapTypes::alike( (array) $l['sig'], $sig ) ) {
				return (int) $i;
			}
		}
		return null;
	}

	/* ── Internals ─────────────────────────────────────────────────────── */

	private static function all(): array {
		$all = get_option( self::OPTION, [] );
		return is_array( $all ) ? $all : [];
	}

	private static function key( string $kind, $ref, string $part ): string {
		return $part . '|' . $kind . ( $ref === null ? '' : ':' . ( is_array( $ref ) ? md5( (string) wp_json_encode( $ref ) ) : (string) $ref ) );
	}

	/** The targets (of the host's $targets) a page reads $part through: the site, its layout (pick and none), its product. Layouts match by likeness, so a stored signature stands for the layout as it is regrouped. */
	private static function keys( array $targets, string $part, ?array $sig, int $id ): array {
		$keys = [ self::key( 'site', null, $part ) ];
		if ( $id ) {
			$keys[] = self::key( 'productnone', $id, $part );
		}
		if ( ! $sig ) {
			return $keys;
		}
		foreach ( $targets as $k => $t ) {
			$l = array_merge( (array) ( $t['done'] ?? [] ), (array) ( $t['undone'] ?? [] ) );
			$e = $l ? $l[0] : null;
			if ( $e && $e['part'] === $part && in_array( $e['kind'], [ 'layout', 'layoutnone' ], true ) && MapTypes::alike( (array) $e['ref'], $sig ) ) {
				$keys[] = (string) $k;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/** Two values of a target the same? Picks by what reads (selector, way, page, general reader); decisions as yes/no. */
	private static function same( string $kind, $a, $b ): bool {
		if ( in_array( $kind, [ 'layoutnone', 'productnone' ], true ) ) {
			return (bool) $a === (bool) $b;
		}
		$k = static fn( $p ) => is_array( $p ) && ( ! empty( $p['sel'] ) || ! empty( $p['generic'] ) ) ? [ (string) ( $p['sel'] ?? '' ), (string) ( $p['mode'] ?? '' ), (string) ( $p['src'] ?? '' ), ! empty( $p['generic'] ) ] : null;
		return $k( $a ) === $k( $b );
	}

	/** The target's value now. @return mixed|\WP_Error */
	private static function current( string $host, array $e ) {
		$part = (string) $e['part'];
		switch ( $e['kind'] ) {
			case 'site':
				return VendorMaps::get( $host )['fields'][ $part ] ?? null;
			case 'layout':
			case 'layoutnone':
				$i = self::layout_index( $host, (array) $e['ref'] );
				if ( $i === null ) {
					return new \WP_Error( 'mmpt_history_layout', 'That layout is no longer measured: the site’s pages were grouped again.' );
				}
				if ( $e['kind'] === 'layoutnone' ) {
					return ! empty( LayoutMaps::entry( $host, $i )['none'][ $part ] );
				}
				$t = MapTypes::for_layout( $host, $i );
				return $t && ! is_wp_error( $t ) ? ( $t['fields'][ $part ] ?? null ) : null;
			case 'productnone':
				$col = self::PRODUCT_COLS[ $part ] ?? '';
				$all = get_post_meta( (int) $e['ref'], SiteGaps::NONE_META, true );
				return $col !== '' && is_array( $all ) && isset( $all[ $col ] );
		}
		return null;
	}

	/** Put the target to $to through its own model. @return true|\WP_Error */
	private static function apply( string $host, array $e, $to ) {
		$part = (string) $e['part'];
		switch ( $e['kind'] ) {
			case 'site':
				$res = VendorMaps::put_part( $host, $part, is_array( $to ) ? $to : null );
				return is_wp_error( $res ) ? $res : true;
			case 'layout':
				$i = self::layout_index( $host, (array) $e['ref'] );
				$t = $i === null ? null : MapTypes::for_layout( $host, $i, is_array( $to ) );
				if ( is_wp_error( $t ) ) {
					return $t;
				}
				if ( $t ) {
					MapTypes::set_part( $host, (string) $t['type_key'], $part, is_array( $to ) ? $to : null );
				}
				return true;
			case 'layoutnone':
				$i = self::layout_index( $host, (array) $e['ref'] );
				return $i === null ? new \WP_Error( 'mmpt_history_layout', 'That layout is no longer measured.' ) : LayoutMaps::set_none( $host, $i, $part, (bool) $to );
			case 'productnone':
				$col = self::PRODUCT_COLS[ $part ] ?? '';
				$res = $col === '' ? [ 'state' => 'error', 'note' => 'That part has no gap to decide.' ] : SiteGaps::decide_none( $host, (int) $e['ref'], $col, ! $to );
				return $res['state'] === 'error' ? new \WP_Error( 'mmpt_history_none', (string) $res['note'] ) : true;
		}
		return new \WP_Error( 'mmpt_history', 'Unknown change.' );
	}

	/** Undone → the target's undone list; redone → back on its done list. */
	private static function move( string $host, array $e, bool $redo ): void {
		$all  = self::all();
		$key  = self::key( (string) $e['kind'], $e['ref'], (string) $e['part'] );
		$t    = (array) ( $all[ $host ][ $key ] ?? [ 'done' => [], 'undone' => [] ] );
		$from = $redo ? 'undone' : 'done';
		$to   = $redo ? 'done' : 'undone';
		$t[ $from ] = array_values( array_filter( (array) $t[ $from ], static fn( $x ) => (float) $x['seq'] !== (float) $e['seq'] ) );
		$t[ $to ][] = array_diff_key( $e, [ 'said' => 1 ] );
		$t[ $to ]   = array_slice( $t[ $to ], -self::MAX );
		$all[ $host ][ $key ] = $t;
		update_option( self::OPTION, $all, false );
	}

	/** A change that can't be taken back any more (its target moved on another way): out of both lists. */
	private static function drop( string $host, array $e ): void {
		$all = self::all();
		$key = self::key( (string) $e['kind'], $e['ref'], (string) $e['part'] );
		if ( ! isset( $all[ $host ][ $key ] ) ) {
			return;
		}
		foreach ( [ 'done', 'undone' ] as $l ) {
			$all[ $host ][ $key ][ $l ] = array_values( array_filter( (array) $all[ $host ][ $key ][ $l ], static fn( $x ) => (float) $x['seq'] !== (float) $e['seq'] ) );
		}
		update_option( self::OPTION, $all, false );
	}
}
