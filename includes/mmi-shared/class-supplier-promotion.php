<?php
/**
 * MMI_Supplier_Promotion
 *
 * One shape for a software distributor's promotion (XChange, SkuPort,
 * Plugivery), so every screen that shows or compares promotions reads the
 * same fields and the same running/upcoming/ended state, and a fix here
 * reaches every supplier at once.
 *
 *   { name, code, cost, regular_cost, map, regular_map, start, end,
 *     start_at, end_at, state }
 *
 * - cost / regular_cost: our dealer cost during / outside the promotion.
 * - map / regular_map: the advertised price floor during / outside it.
 * - start / end: Y-m-d (the supplier's own calendar date), '' if open.
 * - start_at / end_at: the exact Unix time when the feed gives one
 *   (Plugivery), else null.
 * - state: active (running now), upcoming or ended, from the dates alone:
 *   exact instants when the feed has them, else whole calendar days.
 *   A supplier's own status field is not trusted: XChange leaves it empty
 *   and Plugivery's only says which file the record came from.
 *
 * Each supplier maps its raw feed record to the input shape once (its feed
 * catalog adapter's promotion()); everything after that is here.
 *
 * Some feeds (SkuPort, Plugivery) put the running promotion's price in the
 * product record itself; regular_prices() recovers the regular price, so a
 * "Cost" or "MAP" column means the same thing for every supplier.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Supplier_Promotion', false ) ) {

	final class MMI_Supplier_Promotion {

		/** Sort rank: running first, then upcoming, then ended. */
		const STATES = array(
			'active'   => 0,
			'upcoming' => 1,
			'ended'    => 2,
		);

		/** A positive price rounded to cents, else null ("no price"). */
		public static function money( $value ): ?float {
			return is_numeric( $value ) && (float) $value > 0 ? round( (float) $value, 2 ) : null;
		}

		/**
		 * A feed date as Y-m-d, or ''. Unix timestamps (Plugivery) use the
		 * site's timezone; strings keep the supplier's own calendar date, so
		 * SkuPort's "2026-10-28T23:59:59-07:00" stays the 28th. '1900-…' and
		 * '0000-…' mean no date.
		 */
		public static function date( $value ): string {
			if ( is_numeric( $value ) ) {
				return (int) $value > 0 ? wp_date( 'Y-m-d', (int) $value ) : '';
			}
			$value = trim( (string) $value );
			if ( $value === '' || strpos( $value, '1900-' ) === 0 || strpos( $value, '0000-' ) === 0 ) {
				return '';
			}
			return substr( $value, 0, 10 );
		}

		/**
		 * One promotion in the shared shape. A name that only repeats the
		 * product's name is dropped (SkuPort names every promotion after
		 * its product).
		 */
		public static function normalize( array $p, string $product = '' ): array {
			$name = trim( (string) ( $p['name'] ?? '' ) );
			if ( $product !== '' && strcasecmp( $name, trim( $product ) ) === 0 ) {
				$name = '';
			}
			$out = array(
				'name'         => $name,
				'code'         => trim( (string) ( $p['code'] ?? '' ) ),
				'cost'         => self::money( $p['cost'] ?? null ),
				'regular_cost' => self::money( $p['regular_cost'] ?? null ),
				'map'          => self::money( $p['map'] ?? null ),
				'regular_map'  => self::money( $p['regular_map'] ?? null ),
				'start'        => self::date( $p['start'] ?? '' ),
				'end'          => self::date( $p['end'] ?? '' ),
				'start_at'     => self::instant( $p['start'] ?? null ),
				'end_at'       => self::instant( $p['end'] ?? null ),
			);
			$out['state'] = self::state( $out );
			return $out;
		}

		/** A Unix timestamp from the feed, or null (a date string has no exact time we trust). */
		private static function instant( $value ): ?int {
			return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
		}

		/**
		 * active, upcoming or ended, as of now. Exact instants when the feed
		 * gave them (a Plugivery promotion starting at 07:00 is upcoming at
		 * 02:00 that day); otherwise whole calendar days, end day included.
		 * Callers holding a stored promotion (a cached table row) call this
		 * again rather than trusting the state it was saved with.
		 */
		public static function state( array $p ): string {
			$now   = time();
			$today = current_time( 'Y-m-d' );
			$end   = (string) ( $p['end'] ?? '' );
			$start = (string) ( $p['start'] ?? '' );
			$ended = isset( $p['end_at'] ) ? $p['end_at'] < $now : ( $end !== '' && $end < $today );
			if ( $ended ) {
				return 'ended';
			}
			$started = isset( $p['start_at'] ) ? $p['start_at'] <= $now : ( $start === '' || $start <= $today );
			return $started ? 'active' : 'upcoming';
		}

		/**
		 * Raw feed records through $map (raw => input shape), normalized and
		 * sorted: running first, then upcoming, then ended, each soonest
		 * first.
		 *
		 * @param callable(array):array $map
		 */
		public static function all( array $raw, callable $map, string $product = '' ): array {
			$out = array();
			foreach ( $raw as $item ) {
				$out[] = self::normalize( $map( (array) $item ), $product );
			}
			usort( $out, static fn( $a, $b ) => array( self::STATES[ $a['state'] ], $a['start'] ) <=> array( self::STATES[ $b['state'] ], $b['start'] ) );
			return $out;
		}

		/** The promotion that matters today (running, else next to start) from a sorted all() list, or null. */
		public static function current( array $promotions ): ?array {
			foreach ( $promotions as $p ) {
				if ( $p['state'] !== 'ended' ) {
					return $p;
				}
			}
			return null;
		}

		/**
		 * A product's regular cost and MAP, given its current promotion and
		 * the cost/MAP its feed record carries. When a promotion is running
		 * and knows its regular prices, those win (the feed's own fields may
		 * already be the promotional ones).
		 *
		 * @return array{cost:?float, map:?float}
		 */
		public static function regular_prices( ?array $current, $cost, $map ): array {
			$running = $current && $current['state'] === 'active';
			return array(
				'cost' => ( $running && $current['regular_cost'] !== null ) ? $current['regular_cost'] : self::money( $cost ),
				'map'  => ( $running && $current['regular_map'] !== null ) ? $current['regular_map'] : self::money( $map ),
			);
		}

		/** Badge class and label for a state, for server-rendered screens. */
		public static function state_badge( string $state ): array {
			$badges = array(
				'active'   => array( 'success', __( 'Running', 'mmi-shared' ) ),
				'upcoming' => array( 'info', __( 'Upcoming', 'mmi-shared' ) ),
				'ended'    => array( '', __( 'Ended', 'mmi-shared' ) ),
			);
			return $badges[ $state ] ?? array( '', $state );
		}
	}
}
