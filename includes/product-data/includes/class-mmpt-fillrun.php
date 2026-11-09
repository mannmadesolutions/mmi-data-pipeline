<?php
/**
 * What one Fill wrote (owner 2026-10-25: after Fill, a list of what was written with Undo, per product and for
 * the run). A Fill (VendorMaps' POST /maps/{host}/fill) queues a scrape and the picture runner for the products
 * it gains on; both write later, in the background. So each product gets a row here when the Fill starts, holding
 * a snapshot of what the writers can change (ProductPreview::snapshot()). When the scrape runner has processed the
 * product (Scraper calls scraped()), the row keeps what changed (ProductPreview::changes()). Pictures are read from
 * the picture runner's own ledger: applied after the row was made means this Fill's pictures.
 *
 * Undo puts back only what is still as the Fill left it (ProductPreview::undo_changes()); pictures go back through
 * Pictures::revert(). Every undo is in the audit log with the products it touched.
 *
 * Also here: a person's decision on a product's system requirements where its page differs from what it has
 * (SiteGaps' "To review" cell): use the page's, or keep ours. Either is undone the same way.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FillRun {

	const DB_OPTION  = 'mmpt_fill_items_db_version';
	const DB_VERSION = '1';
	/** A scrape that hasn't processed a product by then never will: its row stops waiting. */
	const WAIT_S = 3 * DAY_IN_SECONDS;
	/** Runs listed per site. */
	const RUNS_SHOWN = 3;
	/** Post meta: a person's decision on the page's requirements { choice: use|keep, sig, rec, at, by }. */
	const REQ_META = '_mmpt_req_decision';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_fill_items';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run varchar(24) NOT NULL,
			host varchar(190) NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			scrape tinyint(1) NOT NULL DEFAULT 0,
			pictures tinyint(1) NOT NULL DEFAULT 0,
			state varchar(12) NOT NULL DEFAULT 'queued',
			before_json longtext NOT NULL,
			rec longtext NULL,
			undo_json longtext NULL,
			created_at datetime NOT NULL,
			done_at datetime NULL,
			PRIMARY KEY  (id),
			KEY run (run),
			KEY product_state (product_id, state),
			KEY host_created (host, created_at)
			) " . $wpdb->get_charset_collate() . ';'
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) === self::table() ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/**
	 * A Fill starts: one row per product with what the writers can change now.
	 * @param int[] $scrape products queued for a scrape
	 * @param int[] $pictures products queued for the picture runner
	 * @return string the run's id
	 */
	public static function start( string $host, array $scrape, array $pictures ): string {
		self::maybe_install();
		global $wpdb;
		$t   = self::table();
		$run = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 4, false, false );
		$now = current_time( 'mysql', true );
		$ids = array_values( array_unique( array_map( 'intval', array_merge( $scrape, $pictures ) ) ) );
		if ( $ids ) {
			// A product a newer Fill takes over stops waiting in the older one.
			$wpdb->query( "UPDATE {$t} SET state = 'superseded' WHERE state = 'queued' AND product_id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL -- ints only
		}
		foreach ( $ids as $id ) {
			$wpdb->insert( $t, [
				'run'         => $run,
				'host'        => VendorMaps::host( $host ),
				'product_id'  => $id,
				'scrape'      => in_array( $id, $scrape, true ) ? 1 : 0,
				'pictures'    => in_array( $id, $pictures, true ) ? 1 : 0,
				'state'       => 'queued',
				'before_json' => (string) wp_json_encode( ProductPreview::snapshot( $id ) ),
				'created_at'  => $now,
			] );
		}
		return $run;
	}

	/** The scrape runner processed product $id: its waiting Fill row keeps what changed. */
	public static function scraped( int $id ): void {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			return; // no Fill has run yet
		}
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, before_json FROM {$t} WHERE product_id = %d AND state = 'queued' AND scrape = 1 ORDER BY id DESC LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return;
		}
		$c = ProductPreview::changes( (array) json_decode( (string) $row['before_json'], true ), ProductPreview::snapshot( $id ) );
		$wpdb->update( $t, [ 'rec' => (string) wp_json_encode( $c ), 'state' => $c['wrote'] ? 'written' : 'nothing', 'done_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $row['id'] ] );
	}

	/**
	 * A site's last runs, newest first: per product what was written, what the picture runner did, what can be undone.
	 * @return array<int,array>
	 */
	public static function runs( string $host ): array {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			return [];
		}
		global $wpdb;
		$t    = self::table();
		$host = VendorMaps::host( $host );
		$runs = $wpdb->get_col( $wpdb->prepare( "SELECT run FROM {$t} WHERE host = %s GROUP BY run ORDER BY MAX(id) DESC LIMIT %d", $host, self::RUNS_SHOWN ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $runs ) {
			return [];
		}
		$rows = $wpdb->get_results( "SELECT id, run, product_id, scrape, pictures, state, rec, undo_json, created_at, done_at FROM {$t} WHERE run IN ('" . implode( "','", array_map( 'esc_sql', $runs ) ) . "') ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ids  = array_map( 'intval', array_column( $rows, 'product_id' ) );
		$pics = self::ledger( $ids );
		_prime_post_caches( $ids, false, false );
		$out = [];
		foreach ( $rows as $r ) {
			$item = self::item( $r, $pics[ (int) $r['product_id'] ] ?? null );
			$run  = $r['run'];
			if ( ! isset( $out[ $run ] ) ) {
				$out[ $run ] = [ 'run' => $run, 'at' => $r['created_at'], 'items' => [], 'counts' => [] ];
			}
			$out[ $run ]['items'][] = $item;
			$out[ $run ]['counts'][ $item['state'] ] = ( $out[ $run ]['counts'][ $item['state'] ] ?? 0 ) + 1;
			$out[ $run ]['undoable'] = ( $out[ $run ]['undoable'] ?? 0 ) + ( $item['undoable'] ? 1 : 0 );
		}
		return array_values( $out );
	}

	/** The picture runner's row per product: status and when it last ran. @return array<int,array{status:string,run_at:string,before:int,after:int}> */
	private static function ledger( array $ids ): array {
		if ( ! $ids ) {
			return [];
		}
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT product_id, status, run_at, reverted_at, pics_before, pics_after FROM ' . Pictures::table() . ' WHERE product_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')', ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ints only
			$out[ (int) $p['product_id'] ] = [ 'status' => (string) $p['status'], 'run_at' => (string) $p['run_at'], 'before' => (int) $p['pics_before'], 'after' => (int) $p['pics_after'] ];
		}
		return $out;
	}

	/** One product's line: state (waiting, written, nothing, undone…), parts written, pictures, whether Undo applies. */
	private static function item( array $r, ?array $pic ): array {
		$rec   = (array) json_decode( (string) $r['rec'], true );
		$state = (string) $r['state'];
		if ( $state === 'queued' && ! (int) $r['scrape'] ) {
			$state = 'written'; // pictures only: what the picture runner did says the rest
		}
		if ( $state === 'queued' && strtotime( $r['created_at'] . ' UTC' ) < time() - self::WAIT_S ) {
			$state = 'stale';
		}
		$pics = '';
		if ( (int) $r['pictures'] && empty( $rec['pictures'] ) ) {
			$mine = $pic && $pic['run_at'] !== '' && $pic['run_at'] >= $r['created_at'];
			if ( $state === 'undone' ) {
				$pics = $mine && $pic['status'] === 'reverted' ? 'undone' : '';
			} elseif ( $mine && $pic['status'] === 'applied' ) {
				$pics = 'added';
			} elseif ( $mine ) {
				$pics = in_array( $pic['status'], [ 'queued', 'working', 'revert' ], true ) ? 'waiting' : 'none';
			} else {
				$pics = $pic && in_array( $pic['status'], [ 'queued', 'working' ], true ) ? 'waiting' : 'none';
			}
		}
		$wrote = (array) ( $rec['wrote'] ?? [] );
		if ( $pics === 'added' && ! in_array( 'pictures', $wrote, true ) ) {
			$wrote[] = 'pictures';
		}
		$id = (int) $r['product_id'];
		return [
			'id'       => $id,
			'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'edit'     => (string) get_edit_post_link( $id, 'raw' ),
			'state'    => $state === 'written' && ! $wrote && $pics !== 'waiting' ? 'nothing' : $state,
			'wrote'    => array_values( $wrote ),
			'pictures' => $pics,
			'pics'     => $pics === 'added' && $pic ? [ $pic['before'], $pic['after'] ] : null,
			'undo'     => $r['undo_json'] ? (array) json_decode( (string) $r['undo_json'], true ) : null,
			'undoable' => $state !== 'undone' && ( $wrote || $pics === 'added' ),
		];
	}

	/**
	 * Undo a run's products (all of them when $ids is empty): only what is still as the Fill left it.
	 * @param int[] $ids
	 * @return array{undone:int,kept:int,queued:int,items:array}
	 */
	public static function undo( string $host, string $run, array $ids = [] ): array {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, run, product_id, scrape, pictures, state, rec, undo_json, created_at FROM {$t} WHERE run = %s AND host = %s AND state <> 'undone'", $run, VendorMaps::host( $host ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ids  = array_map( 'intval', $ids );
		$pics = self::ledger( array_map( 'intval', array_column( $rows, 'product_id' ) ) );
		$out  = [ 'undone' => 0, 'kept' => 0, 'queued' => 0, 'items' => [] ];
		foreach ( $rows as $r ) {
			$id = (int) $r['product_id'];
			if ( $ids && ! in_array( $id, $ids, true ) ) {
				continue;
			}
			$rec = (array) json_decode( (string) $r['rec'], true ) + [ 'meta' => [], 'locks' => [], 'enrich' => [], 'pictures' => false ];
			$pic = $pics[ $id ] ?? null;
			// The picture runner's write after this Fill started is this Fill's (unless the scrape's own record has it).
			if ( (int) $r['pictures'] && empty( $rec['pictures'] ) && $pic && $pic['status'] === 'applied' && $pic['run_at'] >= $r['created_at'] ) {
				$rec['pictures'] = true;
			}
			if ( ! $rec['meta'] && ! $rec['enrich'] && empty( $rec['pictures'] ) ) {
				continue; // nothing written: nothing to put back
			}
			$u = ProductPreview::undo_changes( $id, $rec );
			$wpdb->update( $t, [ 'state' => 'undone', 'undo_json' => (string) wp_json_encode( $u + [ 'at' => current_time( 'mysql', true ) ] ) ], [ 'id' => (int) $r['id'] ] );
			$out['items'][ $id ] = $u;
			$out['undone']      += $u['undone'] ? 1 : 0;
			$out['kept']        += $u['kept'] ? 1 : 0;
			$out['queued']      += $u['queued'] ? 1 : 0;
		}
		if ( class_exists( 'MMI_Audit_Log' ) && $out['items'] ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_maps.fill_undo', [ 'details' => [ 'host' => VendorMaps::host( $host ), 'run' => $run, 'products' => $out['items'] ] ] );
		}
		return $out;
	}

	/* ── Requirements: a person's decision where the page differs ─────────────── */

	/** The page's requirements as the site's gaps read them (the reading that made the cell), or null. */
	private static function page_requirements( string $host, int $id ): ?array {
		$r = SiteGaps::reading_for( $host, $id );
		return is_array( $r['req'] ?? null ) ? (array) $r['req'] : null;
	}

	/** What a decision is tied to: the page's platforms' values. A changed page asks again. */
	public static function req_sig( array $req ): string {
		return md5( (string) wp_json_encode( array_intersect_key( $req, [ 'mac' => 1, 'windows' => 1, 'linux' => 1 ] ) ) );
	}

	/**
	 * Decide product $id's requirements: 'use' writes the page's for each platform that differs (locked against
	 * imports, as a fill is), 'keep' leaves ours and stops asking, 'undo' takes either back.
	 * @return array{state:string,note:string,undone?:array}
	 */
	public static function decide_requirements( string $host, int $id, string $choice ): array {
		$was = get_post_meta( $id, self::REQ_META, true );
		if ( $choice === 'undo' ) {
			if ( ! is_array( $was ) ) {
				return [ 'state' => 'error', 'note' => 'There is no decision to undo.' ];
			}
			$u = $was['choice'] === 'use' && ! empty( $was['rec'] ) ? ProductPreview::undo_changes( $id, (array) $was['rec'] ) : [ 'undone' => [], 'kept' => [], 'queued' => false ];
			delete_post_meta( $id, self::REQ_META );
			self::audit( 'vendor_maps.requirements_undo', $id, [ 'was' => $was['choice'], 'kept' => $u['kept'] ] );
			return [ 'state' => 'undone', 'note' => $u['kept'] ? 'Taken back, except what was edited since: ' . implode( ', ', $u['kept'] ) . '.' : 'Taken back.', 'undone' => $u ];
		}
		$req = self::page_requirements( $host, $id );
		if ( ! $req ) {
			return [ 'state' => 'error', 'note' => 'Its page has no requirements to decide on.' ];
		}
		return self::apply_requirements( $host, $id, $req, $choice );
	}

	/** decide_requirements() with the page's requirements given ($req as PageRequirements::extract() returns them). */
	public static function apply_requirements( string $host, int $id, array $req, string $choice ): array {
		if ( ! class_exists( PageRequirements::class ) || ! class_exists( '\MMI_Product_Content_Registry' ) ) {
			return [ 'state' => 'error', 'note' => 'Requirements can’t be stored on this site.' ];
		}
		$rec = null;
		if ( $choice === 'use' ) {
			$before = ProductPreview::snapshot( $id );
			$wrote  = [];
			foreach ( PageRequirements::plan( $id, $req ) as $os => $action ) {
				if ( ! in_array( $os, [ 'mac', 'windows', 'linux' ], true ) || ! in_array( $action, [ 'fill', 'replace', 'review' ], true ) ) {
					continue; // same, locked or unavailable
				}
				$concept = PageRequirements::OS_CONCEPT[ $os ];
				\MMI_Product_Content_Registry::store( $id, $concept, $req[ $os ] );
				if ( class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
					\MMI_Pipeline_Field_Locks::lock( $id, \MMI_Product_Content_Registry::canonical_key( $concept ) );
				}
				$wrote[] = $os;
			}
			if ( ! empty( $req['platforms'] ) ) {
				\MMI_Product_Content_Registry::merge_platforms( $id, (array) $req['platforms'] ); // platforms only grow
			}
			\MMI_Product_Content_Registry::sync_platforms( $id );
			$rec = ProductPreview::changes( $before, ProductPreview::snapshot( $id ) );
			if ( ! $rec['wrote'] && ! $wrote ) {
				return [ 'state' => 'error', 'note' => 'Nothing to change: it already has its page’s requirements.' ];
			}
		} elseif ( $choice !== 'keep' ) {
			return [ 'state' => 'error', 'note' => 'Choose use or keep.' ];
		}
		update_post_meta( $id, self::REQ_META, [ 'choice' => $choice, 'sig' => self::req_sig( $req ), 'rec' => $rec, 'host' => VendorMaps::host( $host ), 'at' => current_time( 'mysql', true ), 'by' => get_current_user_id() ] );
		self::audit( 'vendor_maps.requirements_' . $choice, $id, $rec ? [ 'meta' => $rec['meta'] ] : [] );
		return [ 'state' => $choice === 'use' ? 'used' : 'kept', 'note' => $choice === 'use' ? 'Its page’s requirements are written and locked against imports.' : 'Ours kept; it won’t be asked again unless its page changes.' ];
	}

	/** Products whose page requirements a person decided to keep theirs over: id → sig. @param int[] $ids */
	public static function kept_requirements( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (" . implode( ',', $ids ) . ')', self::REQ_META ), ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ints only
			$v = maybe_unserialize( $m['meta_value'] );
			if ( is_array( $v ) ) {
				$out[ (int) $m['post_id'] ] = [ 'choice' => (string) $v['choice'], 'sig' => (string) $v['sig'] ];
			}
		}
		return $out;
	}

	private static function audit( string $event, int $id, array $details ): void {
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', $event, [ 'object_type' => 'product', 'object_id' => (string) $id, 'details' => $details ] );
		}
	}
}
