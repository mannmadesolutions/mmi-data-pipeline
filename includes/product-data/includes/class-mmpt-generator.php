<?php
/**
 * Composes a proposal for every eligible product and syncs the proposals
 * table, preserving review decisions. Never writes post_title.
 *
 * One Action Scheduler job (Operational Continuity Rule 3: the whole
 * catalog composes in well under 30 s — ~7 s cold, <1 s with the feed
 * index cached). Runs daily after the Product Paths rebuild, and on demand.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Generator {

	const HOOK       = 'mmpt_generate';
	const DAILY_HOOK = 'mmpt_daily_generate';
	const GROUP      = 'mmi-product-titles';
	const LOCK       = 'mmpt_generate_lock';
	const LOCK_TTL   = 120;
	const CHUNK      = 500;
	/**
	 * Action Scheduler priority for this module's jobs (lower is claimed
	 * first; AS default 10). Same tier as the data pipeline's batches
	 * (MMI_PIPELINE_AS_PRIORITY_BATCH = 5): these are started by a person and
	 * watched on a status bar. On 2026-10-03 a "write titles" batch and a
	 * rule-delete regeneration sat behind ~2,600 default-priority WooCommerce
	 * attribute-lookup jobs queued by a running import.
	 */
	const AS_PRIORITY = 5;

	/** Products whose brand or category changed, recomposed in the background (see on_terms_set()). */
	const RECOMPOSE_HOOK  = 'mmpt_recompose_changed';
	const RECOMPOSE_QUEUE = 'mmpt_recompose_queue';

	/** @var array<int,true> product ids changed during this request */
	private static $pending = [];

	/**
	 * Set while recomposing because a rule was switched off: an accepted row
	 * whose only difference is that rule's contribution gone keeps its
	 * decision (owner, 2026-10-03: Description off must not send 836
	 * accepted titles back to review). Anything else that changed reopens.
	 *
	 * @var string|null
	 */
	private static $dropped_rule = null;

	public static function register_hooks(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( self::DAILY_HOOK, [ __CLASS__, 'dispatch' ] );
		add_action( 'set_object_terms', [ __CLASS__, 'on_terms_set' ], 10, 6 );
		add_action( self::RECOMPOSE_HOOK, [ __CLASS__, 'run_recompose_queue' ] );
	}

	/**
	 * A product's brand or category changed (an import, the Taxonomy Mapper,
	 * the Workbench): its proposal was composed from the old terms, so its
	 * title, "No brand" flag and rules are out of date until the next daily
	 * run. Before this, the Review table showed "No brand assigned" for a
	 * product that had one (#195230, 2026-10-02). Ids are collected for the
	 * request and queued once at shutdown, so a bulk import costs one job.
	 */
	public static function on_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( $taxonomy !== 'product_brand' && $taxonomy !== 'product_cat' ) {
			return;
		}
		$new = array_map( 'intval', (array) $tt_ids );
		$old = array_map( 'intval', (array) $old_tt_ids );
		sort( $new );
		sort( $old );
		if ( $new === $old || get_post_type( (int) $object_id ) !== 'product' ) {
			return;
		}
		if ( ! self::$pending ) {
			add_action( 'shutdown', [ __CLASS__, 'flush_pending' ] );
		}
		self::$pending[ (int) $object_id ] = true;
	}

	/** Queue these products for recomposition at the end of the request (a brand renamed: BrandNames). */
	public static function queue( array $ids ): void {
		foreach ( $ids as $id ) {
			if ( ! self::$pending ) {
				add_action( 'shutdown', [ __CLASS__, 'flush_pending' ] );
			}
			self::$pending[ (int) $id ] = true;
		}
	}

	/** Add this request's changed products to the queue and make sure one job will drain it. */
	public static function flush_pending(): void {
		if ( ! self::$pending ) {
			return;
		}
		$queue = get_option( self::RECOMPOSE_QUEUE, [] );
		$queue = is_array( $queue ) ? $queue : [];
		foreach ( array_keys( self::$pending ) as $id ) {
			$queue[ $id ] = true;
		}
		self::$pending = [];
		update_option( self::RECOMPOSE_QUEUE, $queue, false );
		self::schedule_recompose( 0 );
	}

	private static function schedule_recompose( int $delay ): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::RECOMPOSE_HOOK, [], self::GROUP ) ) {
			return;
		}
		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::RECOMPOSE_HOOK, [], self::GROUP, false, self::AS_PRIORITY );
		} else {
			as_enqueue_async_action( self::RECOMPOSE_HOOK, [], self::GROUP, false, self::AS_PRIORITY );
		}
	}

	/**
	 * Recompose these products now, as part of the current request: the first
	 * CHUNK inline, any rest through the background queue. For changes a
	 * person expects to see at once (deleting a custom rule), which must not
	 * wait behind a busy Action Scheduler queue: on 2026-10-03 a rule deleted
	 * at 00:37 UTC was still in the table minutes later, its regeneration
	 * job stuck behind ~600 overdue import/Reverb actions.
	 *
	 * @return array recompose_many() stats for the inline part, plus 'queued'.
	 */
	public static function recompose_now( array $ids ): array {
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$now   = array_slice( $ids, 0, self::CHUNK );
		$later = array_slice( $ids, self::CHUNK );
		$stats = self::recompose_many( $now );
		if ( $later ) {
			$queue = get_option( self::RECOMPOSE_QUEUE, [] );
			$queue = is_array( $queue ) ? $queue : [];
			foreach ( $later as $id ) {
				$queue[ $id ] = true;
			}
			update_option( self::RECOMPOSE_QUEUE, $queue, false );
			self::schedule_recompose( 0 );
		}
		$stats['queued'] = count( $later );
		return $stats;
	}

	/** Recompose up to CHUNK queued products; a full run in progress is waited out. */
	public static function run_recompose_queue(): array {
		if ( get_option( self::LOCK ) && time() - (int) get_option( self::LOCK ) < self::LOCK_TTL ) {
			self::schedule_recompose( 120 );
			return [ 'skipped' => 'locked' ];
		}
		$queue = get_option( self::RECOMPOSE_QUEUE, [] );
		$queue = is_array( $queue ) ? $queue : [];
		if ( ! $queue ) {
			return [ 'products' => 0 ];
		}
		$ids  = array_slice( array_keys( $queue ), 0, self::CHUNK );
		$rest = array_diff_key( $queue, array_flip( $ids ) );
		update_option( self::RECOMPOSE_QUEUE, $rest, false );

		$stats = self::recompose_many( $ids );
		if ( $rest ) {
			self::schedule_recompose( 0 );
		}
		if ( $stats['changed'] || $stats['new'] || $stats['removed'] || $stats['reopened'] ) {
			Module::info( 'Title proposals recomposed after brand/category changes', $stats );
		}
		return $stats;
	}

	/**
	 * recompose_one() for many products: same eligibility checks, same
	 * process(), so review decisions are kept exactly as in a full run.
	 */
	public static function recompose_many( array $ids ): array {
		$stats = [ 'products' => 0, 'proposals' => 0, 'new' => 0, 'changed' => 0, 'reopened' => 0, 'kept_decisions' => 0, 'removed' => 0, 'unchanged_titles' => 0 ];
		$ids   = array_values( array_filter( array_map( 'intval', $ids ), static fn( $id ) => Store::is_worked( $id ) ) );
		$ids   = $ids ? array_values( array_map( 'intval', (array) Exclusions::filter( $ids ) ) ) : [];
		if ( ! $ids ) {
			return $stats;
		}
		$now = current_time( 'mysql' );
		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$contexts = TitleRules::pack()->contexts( $chunk );
			$existing = Store::get_many( $chunk );
			foreach ( $contexts as $id => $ctx ) {
				$row = $existing[ $id ] ?? null;
				self::process( (int) $id, $ctx, $row, (string) ( $row['run_id'] ?? gmdate( 'Ymd-His' ) ), $now, $stats );
			}
		}
		return $stats;
	}

	public static function schedule_daily(): void {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			// 04:50 site time — after Product Paths' 04:20 rebuild and the 03:xx feed fetches.
			$ts = strtotime( 'tomorrow 04:50', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			wp_schedule_event( $ts, 'daily', self::DAILY_HOOK );
		}
	}

	/** At most one queued job (one background process per action). */
	public static function dispatch(): bool {
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, [], self::GROUP ) ) {
			return false;
		}
		Store::set_state( Store::GEN_STATE, [ 'status' => 'dispatched', 'dispatched_at' => current_time( 'mysql' ) ] );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, [], self::GROUP, false, self::AS_PRIORITY );
			return true;
		}
		self::run();
		return true;
	}

	/** @param string|null $dropped_rule the rule just switched off (see $dropped_rule) */
	public static function run( $dropped_rule = null ): array {
		self::$dropped_rule = is_string( $dropped_rule ) && $dropped_rule !== '' ? $dropped_rule : null;
		if ( ! add_option( self::LOCK, time(), '', 'no' ) ) {
			if ( time() - (int) get_option( self::LOCK ) < self::LOCK_TTL ) {
				return [ 'skipped' => 'locked' ];
			}
			update_option( self::LOCK, time(), false );
		}
		$run_id = gmdate( 'Ymd-His' );
		$t0     = microtime( true );
		Store::maybe_install();
		Store::set_state( Store::GEN_STATE, [ 'run_id' => $run_id, 'status' => 'running', 'started_at' => current_time( 'mysql' ), 'last_error' => null ] );

		try {
			$stats = self::generate( $run_id );
			// The rule pack's follow-up work (this site: purchase advisories and part numbers, built from the titles).
			$stats += TitleRules::pack()->after_generate();
			$stats['seconds']    = round( microtime( true ) - $t0, 1 );
			Store::set_state( Store::GEN_STATE, [ 'status' => 'complete', 'completed_at' => current_time( 'mysql' ), 'stats' => $stats ] );
			Module::info( 'Title proposals generated', $stats );
			return $stats;
		} catch ( \Throwable $e ) {
			Store::set_state( Store::GEN_STATE, [ 'status' => 'failed', 'completed_at' => current_time( 'mysql' ), 'last_error' => $e->getMessage() ] );
			Module::error( 'Title proposal generation failed', [ 'error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine() ] );
			return [ 'error' => $e->getMessage() ];
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Recompose one product now (e.g. after its category changed on the Review tab) and
	 * update its proposal row with the same rules as a full run. The row keeps its run_id,
	 * so the next full run treats it normally; duplicate-title counts and advisories are
	 * refreshed by that next run, not here.
	 *
	 * @return array|null the proposal row, or null when the product has none
	 */
	public static function recompose_one( int $id ): ?array {
		$ctx = TitleRules::pack()->contexts( [ $id ] )[ $id ] ?? null;
		if ( ! $ctx || ! Store::is_worked( $id ) || ! Exclusions::filter( [ $id ] ) ) {
			return Store::get( $id );
		}
		$row   = Store::get( $id );
		$stats = [ 'products' => 0, 'proposals' => 0, 'new' => 0, 'changed' => 0, 'reopened' => 0, 'kept_decisions' => 0, 'removed' => 0, 'unchanged_titles' => 0 ];
		self::process( $id, $ctx, $row, (string) ( $row['run_id'] ?? gmdate( 'Ymd-His' ) ), current_time( 'mysql' ), $stats );
		return Store::get( $id );
	}

	/** The supplier's name changed, not just its case, spacing or entity encoding ('' on either side = not known). */
	private static function renamed( string $was, string $now ): bool {
		$norm = static fn( string $s ) => trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
		return $was !== '' && $now !== '' && $norm( $was ) !== $norm( $now );
	}

	/** Compose one product and sync its proposal row (the body of a full run). */
	private static function process( int $id, array $ctx, ?array $row, string $run_id, string $now, array &$stats ): void {
		global $wpdb;
		$t = Store::table();
		$stats['products']++;
		if ( $row && $row['status'] === 'reverted' ) {
			$wpdb->update( $t, [ 'run_id' => $run_id ], [ 'product_id' => $id ] );
			return; // a person put this title back — never propose it again
		}
		// The supplier's name is kept on the row. A product whose title we wrote is locked against imports, so
		// when the supplier renames it ("Acme Synth 11" → "Acme Synth 12" under the same SKU) nothing else would notice:
		// the row reopens with a title composed from the supplier's new name (owner 2026-10-16: the feed names a
		// product, Product Content owns its title once written).
		$feed_title = (string) ( $ctx['feed_title'] ?? '' );
		if ( $row && $row['status'] === 'applied' && self::renamed( (string) ( $row['feed_title'] ?? '' ), $feed_title ) ) {
			$r    = TitleRules::pack()->compose( [ 'title' => $feed_title ] + $ctx );
			$live = PageName::clean( $ctx['title'] );
			if ( $r['title'] !== $live && ! Store::same_words( $r['title'], $live ) ) {
				$wpdb->update(
					$t,
					[
						'status'         => 'proposed',
						'current_title'  => $live,
						'proposed_title' => $r['title'],
						'confidence'     => $r['confidence'],
						'rules'          => implode( ',', array_keys( $r['rules'] ) ),
						'flags'          => mb_substr( implode( ',', array_merge( [ 'supplier_renamed' ], $r['flags'] ) ), 0, 500 ),
						'feed_title'     => mb_substr( $feed_title, 0, 500 ),
						'detail'         => wp_json_encode( [ 'rules' => $r['rules'], 'parts' => $r['parts'], 'sku' => $ctx['sku'] ?? null, 'sub_category' => $ctx['sub_category'] ?? null, 'digital_why' => $ctx['digital_why'] ?? null, 'renamed' => [ 'from' => (string) $row['feed_title'], 'to' => $feed_title ] ] ),
						'run_id'         => $run_id,
						'composed_at'    => $now,
					],
					[ 'product_id' => $id ]
				);
				$stats['supplier_renamed'] = ( $stats['supplier_renamed'] ?? 0 ) + 1;
				return;
			}
		}
		$keep_feed = mb_substr( $feed_title !== '' ? $feed_title : (string) ( $row['feed_title'] ?? '' ), 0, 500 ); // out of the feed for now: its last name is kept
		$c         = TitleRules::pack()->compose( $ctx );

		if ( ! $c['changed'] ) {
			$stats['unchanged_titles']++;
			if ( $row && in_array( $row['status'], Store::OPEN_STATUSES, true ) ) {
				$wpdb->delete( $t, [ 'product_id' => $id ] );
				$stats['removed']++;
			} elseif ( $row ) {
				$wpdb->update( $t, [ 'run_id' => $run_id, 'feed_title' => $keep_feed ], [ 'product_id' => $id ] );
			}
			return;
		}

		$stats['proposals']++;
		$fields = [
			'current_title'  => PageName::clean( $ctx['title'] ),
			'proposed_title' => $c['title'],
			'confidence'     => $c['confidence'],
			'rules'          => implode( ',', array_keys( $c['rules'] ) ),
			'flags'          => mb_substr( implode( ',', $c['flags'] ), 0, 500 ),
			'brand'          => mb_substr( (string) ( $ctx['brand'] ?? '' ), 0, 150 ),
			'feed'           => mb_substr( (string) ( $ctx['feed'] ?? '' ), 0, 60 ),
			'detail'         => wp_json_encode( [ 'rules' => $c['rules'], 'parts' => $c['parts'], 'sku' => $ctx['sku'] ?? null, 'sub_category' => $ctx['sub_category'] ?? null, 'digital_why' => $ctx['digital_why'] ?? null ] ),
			'run_id'         => $run_id,
			'composed_at'    => $now,
			'feed_title'     => $keep_feed,
		];

		if ( ! $row ) {
			$wpdb->insert( $t, [ 'product_id' => $id, 'status' => 'proposed' ] + $fields );
			$stats['new']++;
			return;
		}

		$same = $row['proposed_title'] === $c['title'] && $row['current_title'] === $fields['current_title'];
		// A rule that only changes capitals, spacing or quotes ("Plug-in" → "Plug-In") keeps a person's
		// decision: the row is not reopened for review (user 2026-10-01).
		$cosmetic = $row['current_title'] === $fields['current_title'] && Store::same_words( $row['proposed_title'], $c['title'] );
		// Store::get()/get_many() hand back `rules` already split into an array.
		$old_rules = array_values( array_filter( is_array( $row['rules'] ) ? $row['rules'] : explode( ',', (string) $row['rules'] ) ) );
		$only_dropped = self::$dropped_rule !== null
			&& $row['status'] === 'accepted'
			&& $row['current_title'] === $fields['current_title']
			&& in_array( self::$dropped_rule, $old_rules, true )
			// No rule appears that the person didn't see; one that only acted on the dropped rule's
			// text (Fix ALL CAPS on the description phrase) may go with it.
			&& ! array_diff( array_keys( $c['rules'] ), array_diff( $old_rules, [ self::$dropped_rule ] ) );
		if ( in_array( $row['status'], [ 'accepted', 'rejected' ], true ) ) {
			if ( $same || $cosmetic ) {
				$stats['kept_decisions']++;
			} elseif ( $only_dropped ) {
				$stats['kept_decisions']++;
				$stats['kept_without_rule'] = ( $stats['kept_without_rule'] ?? 0 ) + 1;
			} else {
				$fields['status'] = 'proposed'; // the decision was about a different title
				$stats['reopened']++;
			}
		} elseif ( $row['status'] === 'edited' ) {
			$stats['kept_decisions']++;
		} elseif ( $row['status'] === 'applied' && Store::same_words( $fields['current_title'], $c['title'] ) ) {
			// The live title was approved and written; a cosmetic rule fix to it is queued for the next apply, not reviewed again.
			$fields['status'] = 'accepted';
			$stats['kept_decisions']++;
		} else {
			// proposed / stale / error, or applied and then changed again (by a person or a better composer)
			$fields['status'] = 'proposed';
			if ( ! $same ) {
				$stats['changed']++;
			}
		}
		$wpdb->update( $t, $fields, [ 'product_id' => $id ] );
	}

	private static function generate( string $run_id ): array {
		global $wpdb;
		$t     = Store::table();
		$now   = current_time( 'mysql' );
		$stats = [ 'products' => 0, 'proposals' => 0, 'new' => 0, 'changed' => 0, 'reopened' => 0, 'kept_decisions' => 0, 'removed' => 0, 'unchanged_titles' => 0 ];

		foreach ( array_chunk( Store::title_ids(), self::CHUNK ) as $chunk ) {
			$contexts = TitleRules::pack()->contexts( $chunk );
			$existing = Store::get_many( $chunk );
			foreach ( $contexts as $id => $ctx ) {
				self::process( $id, $ctx, $existing[ $id ] ?? null, $run_id, $now, $stats );
			}
		}

		// Products no longer eligible (trashed, deleted, excluded on the Rules tab): drop open rows.
		$stats['removed'] += (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$t} WHERE run_id <> %s AND status IN ('proposed','accepted','rejected','stale','error')", $run_id )
		);

		// Two products proposed the same title → neither auto-applies (usually a legacy duplicate listing).
		// Known non-canonical duplicates are left out: they're expected to share their canonical's title.
		$nc = array_values( array_filter( array_map( 'intval', TitleRules::pack()->duplicate_exempt_ids() ) ) );
		$nc = $nc ? ' AND product_id NOT IN (' . implode( ',', $nc ) . ')' : '';
		$wpdb->query( "UPDATE {$t} SET dupes = 0" );
		$wpdb->query(
			"UPDATE {$t} p JOIN (
				SELECT proposed_title, COUNT(*) n FROM {$t} WHERE status IN ('proposed','accepted','edited','stale'){$nc} GROUP BY proposed_title HAVING n > 1
			) d ON d.proposed_title = p.proposed_title
			SET p.dupes = d.n - 1
			WHERE p.status IN ('proposed','accepted','edited','stale')" . str_replace( 'product_id', 'p.product_id', $nc )
		);
		$stats['duplicate_titles'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE dupes > 0" );
		return $stats;
	}
}
