<?php
/**
 * Writes reviewed titles to post_title in throttled batches.
 *
 * What it writes: rows a person accepted or edited, plus — only when the
 * `auto` setting is on — proposals that pass Store::auto_eligible_sql()
 * (every rule approved, confidence floor, no blocking flag). Nothing while
 * applying is disabled (the default).
 *
 * Why throttled: each product save can fire page-cache purges, a changed
 * title queues marketplace listing updates (rate-limited by the marketplace)
 * and can trigger a shopping-feed re-review.
 * So: batches of `batch_size` (TIME_BUDGET s of writing each), GAP s apart,
 * one queued batch at a time. No daily cap (user 2026-10-03: titles are
 * written as soon as they are accepted or edited; the throttle stays).
 *
 * Every write: re-checks the live title still equals what was composed
 * against (else the row goes stale), never touches post_name, locks
 * post_title against imports (MMI_Pipeline_Field_Locks), and keeps the
 * pre-change title for revert().
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Applier {

	const HOOK       = 'mmpt_apply_batch';
	const DAILY_HOOK = 'mmpt_daily_apply';
	const LOCK       = 'mmpt_apply_lock';
	const LOCK_TTL   = 300; // per-batch AS job lock (Operational Continuity Rule 4)
	const GAP        = 120; // seconds between batches
	/**
	 * Seconds of writing per batch. A title write runs ~2.7 s when the site
	 * is quiet but far longer under import load; on 2026-09-30, 10-01 and
	 * 10-02 the day's second batch passed Action Scheduler's 300 s limit, was
	 * killed mid-write, and the chain stopped (25 titles a day). A batch now
	 * stops starting titles after this budget; the rest wait for the next one.
	 */
	const TIME_BUDGET = 40;

	public static function register_hooks(): void {
		add_action( self::HOOK, [ __CLASS__, 'run_batch' ] );
		add_action( self::DAILY_HOOK, [ __CLASS__, 'dispatch' ] );
		add_filter( 'wp_insert_post_data', [ __CLASS__, 'keep_title_ampersands' ], 20, 2 );
	}

	/**
	 * A product title saved with nobody logged in (WP-Cron: the supplier import re-saving a product, a background
	 * title write) passes WordPress's kses title filter, which stores "&" as "&amp;" even when the title didn't
	 * change (2026-10-16: 137 product titles, "Signature R&amp;B"). Tags stay stripped; only that encoding is
	 * undone, so the title is stored the way an editor's save stores it. A title that already said "&amp;"
	 * before the save is left alone (repair_entities() fixes those).
	 */
	public static function keep_title_ampersands( array $data, array $postarr ): array {
		if ( ! in_array( $data['post_type'] ?? '', [ 'product', 'product_variation' ], true ) || strpos( (string) ( $data['post_title'] ?? '' ), '&amp;' ) === false || ! has_filter( 'title_save_pre', 'wp_filter_kses' ) ) {
			return $data;
		}
		if ( strpos( wp_unslash( (string) ( $postarr['post_title'] ?? '' ) ), '&amp;' ) === false ) {
			$data['post_title'] = str_replace( '&amp;', '&', (string) $data['post_title'] );
		}
		return $data;
	}

	/**
	 * Product titles stored with "&amp;" by the kses filter (keep_title_ampersands()) written back with "&".
	 * The same text, so Field Locks, the proposal row and the title's history stay as they are; Reverb gets the
	 * corrected title. @return array{found:int,fixed:int,ids:int[]}
	 */
	public static function repair_entities( bool $dry = true ): array {
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish','draft','pending','private') AND post_title LIKE '%&amp;%' ORDER BY ID" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out = [ 'found' => count( $ids ), 'fixed' => 0, 'ids' => $ids ];
		if ( $dry ) {
			return $out;
		}
		$kses = has_filter( 'title_save_pre', 'wp_filter_kses' );
		if ( $kses ) {
			remove_filter( 'title_save_pre', 'wp_filter_kses' );
		}
		try {
			foreach ( $ids as $id ) {
				$title = (string) get_post_field( 'post_title', $id, 'raw' );
				$fixed = str_replace( '&amp;', '&', $title );
				if ( $fixed === $title ) {
					continue;
				}
				$res = Write::field( $id, 'post_title', $fixed, 'product-titles', 'force' ); // fixing our own earlier write
				if ( $res['result'] === 'written' ) {
					$out['fixed']++;
				}
			}
		} finally {
			if ( $kses ) {
				add_filter( 'title_save_pre', 'wp_filter_kses' );
			}
		}
		if ( $out['fixed'] && class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'product_titles.repair_entities', [ 'object_type' => 'product', 'details' => [ 'count' => $out['fixed'], 'ids' => $ids ] ] );
		}
		Module::info( 'Product titles: "&amp;" written back as "&"', [ 'found' => $out['found'], 'fixed' => $out['fixed'] ] );
		return $out;
	}

	public static function schedule_daily(): void {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			// 06:10 site time — after the 04:50 generation, well before the business day.
			$ts = strtotime( 'tomorrow 06:10', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			wp_schedule_event( $ts, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Queue one batch, unless one is already queued or applying is off.
	 *
	 * $chained: called from inside a running batch to queue the next one. The
	 * running action counts as "scheduled" to as_has_scheduled_action()
	 * (pending OR in-progress), so the chain must look at pending actions only,
	 * or it never continues past the first batch (the 2026-09-29 bug: 25 a day).
	 */
	public static function dispatch( int $delay = 0, bool $chained = false ): bool {
		if ( ! Store::apply_settings()['enabled'] || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$queued = $chained
			? (bool) as_get_scheduled_actions( [ 'hook' => self::HOOK, 'group' => Generator::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ], 'ids' )
			: as_has_scheduled_action( self::HOOK, [], Generator::GROUP );
		if ( $queued ) {
			return false;
		}
		as_schedule_single_action( time() + $delay, self::HOOK, [], Generator::GROUP, false, Generator::AS_PRIORITY );
		return true;
	}

	/** How many rows the applier would still write (next_ids() without the limit). */
	public static function queue_count(): int {
		global $wpdb;
		$t    = Store::table();
		$auto = Store::apply_settings()['auto'] ? ' OR (' . Store::auto_eligible_sql() . ')' : '';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE ( status IN ('accepted','edited'){$auto} )" . Exclusions::sql_not_excluded() );
	}

	/**
	 * Start a run from the Review tab: remember how many titles it covers
	 * (for the status bar) and queue a batch now. Batches then chain on
	 * their own until the queue is empty.
	 */
	public static function start_run(): array {
		$s = Store::apply_settings();
		if ( ! $s['enabled'] ) {
			return [ 'error' => __( 'Writing titles to products is off. Turn it on in the Applying tab first.', 'mmi-admin' ) ] + self::status();
		}
		$queue = self::queue_count();
		if ( $queue === 0 ) {
			return [ 'error' => __( 'There are no accepted or edited titles waiting to be written.', 'mmi-admin' ) ] + self::status();
		}
		Store::set_state(
			Store::APPLY_STATE,
			[
				'run' => [
					'started_at'  => current_time( 'mysql' ),
					'total'       => $queue,
					'done'        => 0,
					'by'          => get_current_user_id(),
					'finished_at' => null,
					'end'         => null,
				],
			]
		);
		self::dispatch();
		Module::info( 'Title apply run started', [ 'queue' => $queue ] );
		return self::status();
	}

	/** Stop after the batch in progress (≤ TIME_BUDGET s); nothing further is queued. */
	public static function stop_run(): array {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], Generator::GROUP );
		}
		self::finish_run( 'stopped' );
		Module::info( 'Title apply run stopped' );
		return self::status();
	}

	private static function finish_run( string $end ): void {
		$run = Store::state( Store::APPLY_STATE )['run'] ?? null;
		if ( is_array( $run ) && empty( $run['finished_at'] ) ) {
			$run['finished_at'] = current_time( 'mysql' );
			$run['end']         = $end;
			Store::set_state( Store::APPLY_STATE, [ 'run' => $run ] );
		}
	}

	/**
	 * What the applier is doing now, for the Review tab's status bar.
	 * phase: disabled | running (a batch is writing) | waiting (next batch
	 * queued; `overdue` when Action Scheduler is behind) | interrupted (the
	 * last batch died while writing and nothing is queued) | idle.
	 */
	public static function status(): array {
		$s     = Store::apply_settings();
		$state = self::today_state();
		$now   = time();
		$next  = null;
		$busy  = false;
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions( [ 'hook' => self::HOOK, 'group' => Generator::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1, 'orderby' => 'date', 'order' => 'ASC' ] );
			foreach ( $pending as $action ) {
				$date = $action->get_schedule()->get_date();
				$next = $date ? $date->getTimestamp() : $now;
			}
			$busy = (bool) as_get_scheduled_actions( [ 'hook' => self::HOOK, 'group' => Generator::GROUP, 'status' => \ActionScheduler_Store::STATUS_RUNNING, 'per_page' => 1 ], 'ids' );
		}
		$started = isset( $state['started_at'] ) ? (int) get_gmt_from_date( $state['started_at'], 'U' ) : 0;
		if ( ! $s['enabled'] ) {
			$phase = 'disabled';
		} elseif ( $busy ) {
			$phase = 'running';
		} elseif ( $next !== null ) {
			$phase = 'waiting';
		} elseif ( ( $state['status'] ?? '' ) === 'running' && $now - $started > self::LOCK_TTL ) {
			$phase = 'interrupted';
		} else {
			$phase = 'idle';
		}
		$run = is_array( $state['run'] ?? null ) ? $state['run'] : null;
		return [
			'phase'         => $phase,
			'enabled'       => (bool) $s['enabled'],
			'queue'         => self::queue_count(),
			'applied_today' => (int) $state['applied_today'],
			'batch_size'    => (int) $s['batch_size'],
			'gap'           => self::GAP,
			'now'           => $now,
			'next_at'       => $next,
			'overdue'       => $next !== null && $next < $now - 60,
			'started_at'    => $state['started_at'] ?? null,
			'last_batch'    => $state['last_batch'] ?? null,
			'run'           => $run,
			'finished_ago'  => $run && ! empty( $run['finished_at'] ) ? $now - (int) get_gmt_from_date( $run['finished_at'], 'U' ) : null,
		];
	}

	/** Rows the applier would write next, in priority order (human decisions first). */
	public static function next_ids( int $limit ): array {
		global $wpdb;
		$t    = Store::table();
		$auto = Store::apply_settings()['auto'] ? ' OR (' . Store::auto_eligible_sql() . ')' : '';
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT product_id FROM {$t}
					 WHERE ( status IN ('accepted','edited'){$auto} )" . Exclusions::sql_not_excluded() . "
					 ORDER BY FIELD(status, 'edited', 'accepted', 'proposed'), confidence DESC, product_id ASC
					 LIMIT %d",
					$limit
				)
			)
		);
	}

	public static function run_batch(): array {
		$s = Store::apply_settings();
		if ( ! $s['enabled'] ) {
			return [ 'skipped' => 'disabled' ];
		}
		if ( ! add_option( self::LOCK, time(), '', 'no' ) ) {
			if ( time() - (int) get_option( self::LOCK ) < self::LOCK_TTL ) {
				return [ 'skipped' => 'locked' ];
			}
			update_option( self::LOCK, time(), false );
		}
		try {
			$state = self::today_state();
			$ids   = self::next_ids( $s['batch_size'] );
			if ( ! $ids ) {
				Store::set_state( Store::APPLY_STATE, [ 'status' => 'complete', 'completed_at' => current_time( 'mysql' ) ] );
				self::finish_run( 'done' );
				return [ 'applied' => 0 ];
			}
			// Queue the next batch before writing anything, so a batch that dies
			// mid-way (killed at the 300 s limit) can't end the chain. Unqueued
			// below when this batch turns out to be the last.
			self::dispatch( self::GAP, true );
			Store::set_state( Store::APPLY_STATE, [ 'status' => 'running', 'started_at' => current_time( 'mysql' ) ] );

			$done = [ 'applied' => 0, 'stale' => 0, 'error' => 0 ];
			$t0   = microtime( true );
			foreach ( $ids as $id ) {
				if ( microtime( true ) - $t0 > self::TIME_BUDGET ) {
					break; // the rest go in the next batch
				}
				$r = self::apply_one( $id, 'queue' );
				$done[ $r['result'] ] = ( $done[ $r['result'] ] ?? 0 ) + 1;
			}
			$state = Store::set_state(
				Store::APPLY_STATE,
				[
					'status'        => 'complete',
					'completed_at'  => current_time( 'mysql' ),
					'applied_today' => (int) $state['applied_today'] + $done['applied'],
					'applied_total' => (int) ( $state['applied_total'] ?? 0 ) + $done['applied'],
					'failed_total'  => (int) ( $state['failed_total'] ?? 0 ) + $done['error'],
					'last_batch'    => $done,
					'last_error'    => $done['error'] ? 'See rows with status "error".' : null,
				]
			);
			$run = $state['run'] ?? null;
			if ( is_array( $run ) && empty( $run['finished_at'] ) ) {
				$run['done'] = (int) $run['done'] + array_sum( $done );
				$state       = Store::set_state( Store::APPLY_STATE, [ 'run' => $run ] );
			}
			if ( ! self::next_ids( 1 ) ) {
				as_unschedule_all_actions( self::HOOK, [], Generator::GROUP );
				self::finish_run( 'done' );
			}
			return $done;
		} catch ( \Throwable $e ) {
			Store::set_state( Store::APPLY_STATE, [ 'status' => 'failed', 'completed_at' => current_time( 'mysql' ), 'last_error' => $e->getMessage() ] );
			Module::error( 'Title apply batch failed', [ 'error' => $e->getMessage() ] );
			return [ 'error' => $e->getMessage() ];
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Write one row's title. Used by the queue and by the review screen's
	 * "Apply now" (a single save, well under the 30 s dispatch threshold).
	 *
	 * @return array{result:string,message:string}
	 */
	public static function apply_one( int $id, string $via ): array {
		$row  = Store::get( $id );
		$post = get_post( $id );
		if ( ! $row || ! $post ) {
			return [ 'result' => 'error', 'message' => 'Proposal or product not found.' ];
		}
		if ( ! in_array( $row['status'], [ 'accepted', 'edited', 'proposed' ], true ) ) {
			return [ 'result' => 'error', 'message' => 'Only accepted, edited or proposed titles can be applied.' ];
		}
		if ( Exclusions::is_excluded( $id ) ) {
			return [ 'result' => 'error', 'message' => 'This product is excluded from Product Data (Rules tab).' ];
		}
		$live = PageName::clean( $post->post_title );
		if ( $live !== $row['current_title'] || ! in_array( $post->post_status, Store::WORK_STATUSES, true ) ) {
			Store::update( $id, [ 'status' => 'stale' ] );
			return [ 'result' => 'stale', 'message' => 'The title changed since this was proposed; it will be recomposed on the next run.' ];
		}

		$new  = $row['status'] === 'edited' && (string) $row['edited_title'] !== '' ? (string) $row['edited_title'] : $row['proposed_title'];
		// Accepted by a person: written even over a lock, then locked so imports can't undo it. The
		// gateway never lets a title change move the product's address.
		$res = Write::field( $id, 'post_title', $new, 'product-titles', 'force', [ 'lock' => true ] );
		if ( $res['result'] === 'error' ) {
			Store::update( $id, [ 'status' => 'error', 'detail' => $row['detail'] + [ 'error' => (string) ( $res['message'] ?? '' ) ] ] );
			Module::error( 'Title write failed', [ 'product_id' => $id, 'error' => (string) ( $res['message'] ?? '' ) ] );
			return [ 'result' => 'error', 'message' => (string) ( $res['message'] ?? '' ) ];
		}
		clean_post_cache( $id );
		$after        = get_post( $id );
		$locked_by_us = empty( $res['lock_added'] ) ? 0 : 1;

		Store::update(
			$id,
			[
				'status'         => 'applied',
				'original_title' => $row['original_title'] ?? $post->post_title, // raw, so revert() is byte-exact (&amp; etc.)
				'current_title'  => PageName::clean( $after->post_title ),
				'applied_at'     => current_time( 'mysql' ),
				'locked_by_us'   => max( $locked_by_us, $row['locked_by_us'] ),
				'reviewed_by'    => $row['reviewed_by'] ?: ( $via === 'queue' ? null : get_current_user_id() ),
			]
		);
		Module::info( 'Title applied', [ 'product_id' => $id, 'from' => $row['current_title'], 'to' => $new, 'via' => $via, 'rules' => $row['rules'] ] );
		return [ 'result' => 'applied', 'message' => $new ];
	}

	/** Put an applied title back exactly as it was; unlock only a lock this module added. */
	public static function revert( int $id ): array {
		$row  = Store::get( $id );
		$post = get_post( $id );
		if ( ! $row || ! $post || $row['status'] !== 'applied' || (string) $row['original_title'] === '' ) {
			return [ 'result' => 'error', 'message' => 'Only an applied title can be reverted.' ];
		}
		if ( PageName::clean( $post->post_title ) !== $row['current_title'] ) {
			return [ 'result' => 'error', 'message' => 'The title was changed after it was applied; edit it on the product instead.' ];
		}
		$res = Write::field( $id, 'post_title', (string) $row['original_title'], 'product-titles', 'force' ); // our own write, put back
		if ( $res['result'] === 'error' ) {
			return [ 'result' => 'error', 'message' => (string) ( $res['message'] ?? '' ) ];
		}
		clean_post_cache( $id );
		if ( $row['locked_by_us'] && class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
			\MMI_Pipeline_Field_Locks::unlock( $id, 'post_title' );
		}
		Store::update( $id, [ 'status' => 'reverted', 'current_title' => $row['original_title'], 'locked_by_us' => 0, 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time( 'mysql' ) ] );
		Module::info( 'Title reverted', [ 'product_id' => $id, 'to' => $row['original_title'] ] );
		return [ 'result' => 'reverted', 'message' => $row['original_title'] ];
	}

	/** The apply state, with the per-day counter rolled over at site midnight. */
	private static function today_state(): array {
		$state = Store::state( Store::APPLY_STATE );
		$today = current_time( 'Y-m-d' );
		if ( ( $state['day'] ?? '' ) !== $today ) {
			$state = Store::set_state( Store::APPLY_STATE, [ 'day' => $today, 'applied_today' => 0 ] );
		}
		return $state + [ 'applied_today' => 0 ];
	}
}
