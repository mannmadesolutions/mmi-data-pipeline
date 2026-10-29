<?php
/**
 * Embargoed products: out of stock until the date the supplier names.
 *
 * Suppliers sometimes list a product before it may be sold and say so in its
 * name: "Oberheim Matrix-12 [Embargoed Nov 5th 10am UK]" (XChange, GForce,
 * 2026-10-28). The owner (2026-10-29): the product is out of stock until that
 * time, and the words never reach the title (Product Titles removes them).
 *
 * - Hold: the product's own stock override (`_mmi_stock_override` =
 *   force_outofstock, tier 1 of Stock_Override_Resolver) is set, so every job
 *   that writes stock — the scheduled import, Catalog Maintenance, the bulk
 *   rules — already keeps it out of stock, and Reverb follows through its
 *   `_stock_status` watcher. What it replaced (stock, quantity, any earlier
 *   override) is kept in `_mmi_embargo_prev`.
 * - Release: one Action Scheduler action at the embargo's time puts back what
 *   the hold replaced and runs the product through the stock rules again. Only
 *   holds this class set are released.
 * - Found by: an hourly sweep of product titles and the three supplier feeds
 *   (raw text first; a feed is only decoded when it mentions an embargo), and
 *   a check when a product is created. `wp mmi embargo sweep|list|release`.
 * - Dates: "Nov 5th 10am UK", "28th October 10am UK". No year: this year's date,
 *   or next year's when that is over 180 days past. No time: the end of that
 *   day. No zone: US Pacific (the latest US store hour). No readable date: held
 *   with no end, shown as "date unknown" for a person to release.
 * - A person changing the override on a held product ends the hold for that
 *   wording (`_mmi_embargo_dismissed`); the sweep doesn't put it back.
 *
 * @package MannMade\DataPipeline
 */

namespace MannMade\DataPipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Embargo {

	const HOOK_RELEASE = 'mmi_pipeline_embargo_release';
	const HOOK_SWEEP   = 'mmi_pipeline_embargo_sweep';
	const HOOK_CHECK   = 'mmi_pipeline_embargo_check';
	const GROUP        = 'mmi-pipeline-embargo';

	const META_UNTIL     = '_mmi_embargo_until'; // UTC timestamp; 0 = no readable date
	const META_NOTE      = '_mmi_embargo_note'; // the supplier's words, e.g. "Embargoed Nov 5th 10am UK"
	const META_PREV      = '_mmi_embargo_prev'; // what the hold replaced: status, qty, override
	const META_DISMISSED = '_mmi_embargo_dismissed'; // wording a person released by hand

	/** The supplier's note: bracketed anywhere in the name, or trailing after " - ". */
	const RX = '/[\[(]\s*(embargo(?:ed)?\b[^\])]*)[\])]|(?:^|\s[-–]\s)(embargo(?:ed)?\b.*)$/iu';

	/** Supplier feeds: product meta key => [file, key field, list key, name field] (files in mmi_shared_lib_json_dir()). */
	const FEEDS = [
		'_mmi_supplier_sku_xchange'   => [ 'xchange-products.json', 'sku', 'products', 'product' ],
		'_mmi_supplier_sku_skuport'   => [ 'skuport-products.json', 'id', '', 'name' ],
		'_mmi_supplier_sku_plugivery' => [ 'plugivery-products.json', 'id', '', 'name' ],
	];

	const ZONES = [
		'UK' => 'Europe/London', 'GMT' => 'Europe/London', 'BST' => 'Europe/London', 'UTC' => 'UTC',
		'CET' => 'Europe/Berlin', 'CEST' => 'Europe/Berlin',
		'ET' => 'America/New_York', 'EST' => 'America/New_York', 'EDT' => 'America/New_York',
		'CT' => 'America/Chicago', 'CST' => 'America/Chicago', 'CDT' => 'America/Chicago',
		'PT' => 'America/Los_Angeles', 'PST' => 'America/Los_Angeles', 'PDT' => 'America/Los_Angeles',
	];
	const DEFAULT_ZONE = 'America/Los_Angeles';

	public static function init(): void {
		add_action( self::HOOK_RELEASE, [ __CLASS__, 'release' ] );
		add_action( self::HOOK_SWEEP, [ __CLASS__, 'sweep' ] );
		add_action( self::HOOK_CHECK, [ __CLASS__, 'check' ] );
		add_action( 'woocommerce_new_product', [ __CLASS__, 'on_new_product' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );
		add_filter( 'woocommerce_admin_stock_html', [ __CLASS__, 'stock_column' ], 20, 2 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mmi embargo', [ __CLASS__, 'cli' ] );
		}
	}

	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK_SWEEP, [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + 300, HOUR_IN_SECONDS, self::HOOK_SWEEP, [], self::GROUP );
		}
	}

	/* ── Reading the supplier's words ─────────────────────────────────────── */

	/**
	 * The embargo a product name carries, if any.
	 *
	 * @return array{note:string,until:int}|null until: UTC timestamp, 0 when no date could be read
	 */
	public static function parse( string $text, ?int $now = null ): ?array {
		if ( ! preg_match( self::RX, html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $m ) ) {
			return null;
		}
		$note = trim( (string) ( ( $m[1] ?? '' ) !== '' ? $m[1] : ( $m[2] ?? '' ) ) );
		$when = (string) preg_replace( '/^embargo(?:ed)?\s*(?:until|till|til|to|on|:)?\s*/iu', '', $note );
		return [ 'note' => $note, 'until' => self::when( $when, $now ?? time() ) ];
	}

	/** "Nov 5th 10am UK" → its UTC timestamp; 0 when there is no day and month. */
	public static function when( string $s, int $now ): int {
		if ( ! preg_match( '/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?/iu', $s, $mon ) ) {
			return 0;
		}
		$rest = str_replace( $mon[0], ' ', $s );
		$time = '23:59';
		if ( preg_match( '/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/iu', $rest, $t ) ) {
			$h    = (int) $t[1] % 12 + ( strtolower( $t[3] ) === 'pm' ? 12 : 0 );
			$time = sprintf( '%02d:%02d', $h, (int) ( $t[2] ?? 0 ) );
			$rest = str_replace( $t[0], ' ', $rest );
		} elseif ( preg_match( '/\b(\d{1,2}):(\d{2})\b/u', $rest, $t ) ) {
			$time = sprintf( '%02d:%02d', (int) $t[1], (int) $t[2] );
			$rest = str_replace( $t[0], ' ', $rest );
		}
		$year = preg_match( '/\b(20\d{2})\b/u', $rest, $y ) ? (int) $y[1] : 0;
		if ( $year ) {
			$rest = str_replace( $y[0], ' ', $rest );
		}
		if ( ! preg_match( '/\b(\d{1,2})(?:st|nd|rd|th)?\b/iu', $rest, $d ) ) {
			return 0;
		}
		$zone = self::DEFAULT_ZONE;
		if ( preg_match( '/\b(' . implode( '|', array_keys( self::ZONES ) ) . ')\b/u', strtoupper( $rest ), $z ) ) {
			$zone = self::ZONES[ $z[1] ];
		}
		$month = (int) gmdate( 'n', (int) strtotime( '1 ' . $mon[1] . ' 2000' ) );
		$at    = static function ( int $yr ) use ( $month, $d, $time, $zone ): int {
			$dt = \DateTimeImmutable::createFromFormat( 'Y-n-j H:i', sprintf( '%d-%d-%d %s', $yr, $month, (int) $d[1], $time ), new \DateTimeZone( $zone ) );
			return $dt ? $dt->getTimestamp() : 0;
		};
		if ( $year ) {
			return $at( $year );
		}
		$yr = (int) gmdate( 'Y', $now );
		$ts = $at( $yr );
		return $ts && $ts < $now - 180 * DAY_IN_SECONDS ? $at( $yr + 1 ) : $ts;
	}

	/* ── Hold and release ─────────────────────────────────────────────────── */

	/** Puts one product's hold in line with the words it carries now ($text: its titles and feed names). */
	public static function apply( int $id, string $text ): string {
		$found = self::parse( $text );
		$held  = metadata_exists( 'post', $id, self::META_UNTIL );
		if ( $held && get_post_meta( $id, '_mmi_stock_override', true ) !== 'force_outofstock' ) {
			// A person changed the override on a held product: that ends the hold for this wording.
			update_post_meta( $id, self::META_DISMISSED, (string) get_post_meta( $id, self::META_NOTE, true ) );
			self::forget( $id );
			return 'dismissed';
		}
		if ( ! $found || ( $found['until'] && $found['until'] <= time() ) ) {
			return $held ? ( self::release( $id ) ? 'released' : 'kept' ) : 'none';
		}
		if ( get_post_meta( $id, self::META_DISMISSED, true ) === $found['note'] ) {
			return 'dismissed';
		}
		return self::hold( $id, $found['until'], $found['note'] );
	}

	public static function hold( int $id, int $until, string $note ): string {
		$held = metadata_exists( 'post', $id, self::META_UNTIL );
		if ( ! $held ) {
			update_post_meta( $id, self::META_PREV, [
				'status'   => (string) get_post_meta( $id, '_stock_status', true ),
				'qty'      => (string) get_post_meta( $id, '_stock', true ),
				'override' => (string) get_post_meta( $id, '_mmi_stock_override', true ),
			] );
		} elseif ( (int) get_post_meta( $id, self::META_UNTIL, true ) === $until && get_post_meta( $id, self::META_NOTE, true ) === $note
			&& get_post_meta( $id, '_mmi_stock_override', true ) === 'force_outofstock'
			&& ( $until === 0 || ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::HOOK_RELEASE, [ $id ], self::GROUP ) ) ) {
			return 'held';
		}
		update_post_meta( $id, self::META_UNTIL, $until );
		update_post_meta( $id, self::META_NOTE, $note );
		update_post_meta( $id, '_mmi_stock_override', 'force_outofstock' );
		if ( get_post_meta( $id, '_stock_status', true ) !== 'outofstock' ) {
			Stock_Override_Resolver::force_stock_status( $id, 'outofstock' );
		}
		self::unschedule( $id );
		if ( $until > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $until, self::HOOK_RELEASE, [ $id ], self::GROUP );
		}
		\MMI_Logger::info( "Embargo hold: product {$id} out of stock until " . ( $until ? gmdate( 'Y-m-d H:i', $until ) . ' UTC' : 'a date nobody gave' ), [ 'product_id' => $id, 'note' => $note ], 'sync', 'Embargo' );
		return $held ? 'updated' : 'held';
	}

	/**
	 * Ends a hold this class set: puts back the override it replaced and the stock the stock rules now give.
	 * Early (before the date) only when $force (a person, or the supplier dropped the words).
	 */
	public static function release( int $id, bool $force = false ): bool {
		if ( ! metadata_exists( 'post', $id, self::META_UNTIL ) ) {
			return false;
		}
		$until = (int) get_post_meta( $id, self::META_UNTIL, true );
		$words = self::parse( self::text( $id ) );
		if ( ! $force && $words && ( $until === 0 || $until > time() ) ) {
			return false; // still embargoed (a release action that ran early, or no date)
		}
		$prev = (array) get_post_meta( $id, self::META_PREV, true );
		self::forget( $id );
		if ( ( $prev['override'] ?? '' ) !== '' ) {
			update_post_meta( $id, '_mmi_stock_override', $prev['override'] );
		} else {
			delete_post_meta( $id, '_mmi_stock_override' );
		}
		$source = ( $prev['status'] ?? '' ) === 'outofstock' ? 'outofstock' : 'instock';
		$rules  = class_exists( '\MMI_DB' ) ? Stock_Override_Resolver::normalize_rules( (array) \MMI_DB::get_setting( 'mmi_stock_override_rules', [] ) ) : [];
		$status = ( new Stock_Override_Resolver( $rules ) )->resolve( $id, $source, Stock_Override_Resolver::raw_feed_item( $id ), Stock_Override_Resolver::product_supplier( $id ) );
		if ( $status === 'instock' && get_post_meta( $id, '_manage_stock', true ) === 'yes' && (float) ( $prev['qty'] ?? 0 ) > 0 ) {
			global $wpdb;
			update_post_meta( $id, '_stock', $prev['qty'] );
			$wpdb->update( $wpdb->prefix . 'wc_product_meta_lookup', [ 'stock_quantity' => (float) $prev['qty'] ], [ 'product_id' => $id ], [ '%f' ], [ '%d' ] );
		}
		Stock_Override_Resolver::force_stock_status( $id, $status );
		\MMI_Logger::info( "Embargo released: product {$id} → {$status}", [ 'product_id' => $id, 'until' => $until ], 'sync', 'Embargo' );
		return true;
	}

	private static function forget( int $id ): void {
		foreach ( [ self::META_UNTIL, self::META_NOTE, self::META_PREV ] as $k ) {
			delete_post_meta( $id, $k );
		}
		self::unschedule( $id );
	}

	private static function unschedule( int $id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_RELEASE, [ $id ], self::GROUP );
		}
	}

	/* ── Finding embargoed products ───────────────────────────────────────── */

	/** A product's words: its title and every supplier feed name it is tracked under. */
	public static function text( int $id ): string {
		$names = self::feed_names();
		$parts = [ (string) get_post_field( 'post_title', $id ) ];
		foreach ( array_keys( self::FEEDS ) as $meta ) {
			$key = trim( (string) get_post_meta( $id, $meta, true ) );
			if ( $key !== '' && isset( $names[ $meta ][ $key ] ) ) {
				$parts[] = $names[ $meta ][ $key ];
			}
		}
		return implode( "\n", $parts );
	}

	/** Every product the words or a hold touch, put in line. @return array<string,int> outcome counts */
	public static function sweep(): array {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish','draft','pending','private') AND post_title LIKE '%embargo%'" );
		foreach ( self::feed_names() as $meta => $names ) {
			foreach ( array_keys( $names ) as $key ) {
				$ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", $meta, (string) $key ) ) );
			}
		}
		$ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_UNTIL ) ) );
		$out = [];
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$r         = self::apply( $id, self::text( $id ) );
			$out[ $r ] = ( $out[ $r ] ?? 0 ) + 1;
		}
		return $out;
	}

	/**
	 * Feed entries whose name mentions an embargo, per product meta key: key => name. A feed is only decoded
	 * when its raw text has the word (xchange-products.json is ~14 MB).
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function feed_names(): array {
		static $memo = null;
		if ( $memo !== null ) {
			return $memo;
		}
		$memo = [];
		$dir  = function_exists( 'mmi_shared_lib_json_dir' ) ? mmi_shared_lib_json_dir() : '';
		foreach ( self::FEEDS as $meta => [ $file, $field, $list, $name ] ) {
			$raw = $dir !== '' && is_readable( $dir . $file ) ? (string) file_get_contents( $dir . $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( stripos( $raw, 'embargo' ) === false ) {
				continue;
			}
			$data = json_decode( $raw, true );
			unset( $raw );
			foreach ( (array) ( $list !== '' ? ( $data[ $list ] ?? [] ) : $data ) as $r ) {
				$key = trim( (string) ( $r[ $field ] ?? '' ) );
				if ( $key !== '' && stripos( (string) ( $r[ $name ] ?? '' ), 'embargo' ) !== false ) {
					$memo[ $meta ][ $key ] = (string) $r[ $name ];
				}
			}
		}
		return $memo;
	}

	/** A new product is checked once it is saved (async: never inside the importer's save). */
	public static function on_new_product( $id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) && stripos( (string) get_post_field( 'post_title', (int) $id ), 'embargo' ) !== false ) {
			as_enqueue_async_action( self::HOOK_CHECK, [ (int) $id ], self::GROUP );
		}
	}

	public static function check( $id ): void {
		self::apply( (int) $id, self::text( (int) $id ) );
	}

	/* ── Showing it ───────────────────────────────────────────────────────── */

	/** "Embargoed until Nov 5, 2026 2:00 am" (store time), or "Embargoed — date unknown"; '' when not held. */
	public static function label( int $id ): string {
		if ( ! metadata_exists( 'post', $id, self::META_UNTIL ) ) {
			return '';
		}
		$until = (int) get_post_meta( $id, self::META_UNTIL, true );
		return $until
			? sprintf( /* translators: %s: date and time */ __( 'Embargoed until %s', 'mmi-data-pipeline' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $until ) . ' ' . ( wp_timezone()->getOffset( new \DateTime( '@' . $until ) ) === 0 ? 'UTC' : wp_date( 'T', $until ) ) )
			: __( 'Embargoed — date unknown', 'mmi-data-pipeline' );
	}

	public static function stock_column( $html, $product ) {
		$label = $product ? self::label( (int) $product->get_id() ) : '';
		if ( $label === '' ) {
			return $html;
		}
		$note = (string) get_post_meta( (int) $product->get_id(), self::META_NOTE, true );
		return $html . '<br><span class="mmi-badge mmi-badge--warning" title="' . esc_attr( $note ) . '">' . esc_html( $label ) . '</span>';
	}

	/* ── WP-CLI ───────────────────────────────────────────────────────────── */

	/**
	 * Embargo holds.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : sweep (find and hold/release now), list (every hold), release <id> (end a hold now), parse <text>
	 *
	 * [<arg>]
	 * : Product ID for release; text for parse.
	 */
	public static function cli( array $args ): void {
		$action = $args[0] ?? 'list';
		if ( $action === 'sweep' ) {
			\WP_CLI::log( (string) wp_json_encode( self::sweep() ) );
		} elseif ( $action === 'release' ) {
			\WP_CLI::log( self::release( (int) ( $args[1] ?? 0 ), true ) ? 'released' : 'not held' );
		} elseif ( $action === 'parse' ) {
			$p = self::parse( (string) ( $args[1] ?? '' ) );
			\WP_CLI::log( $p ? $p['note'] . ' → ' . ( $p['until'] ? gmdate( 'Y-m-d H:i', $p['until'] ) . ' UTC' : 'no date' ) : 'no embargo' );
		} else {
			global $wpdb;
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_UNTIL ) ) as $id ) {
				\WP_CLI::log( sprintf( '#%d %s — %s ("%s")', $id, get_the_title( (int) $id ), self::label( (int) $id ), get_post_meta( (int) $id, self::META_NOTE, true ) ) );
			}
		}
	}
}
