<?php
/**
 * MMI_Batch_Watchdog_Trait — reliable "one item per Action Scheduler tick"
 * batching, extracted from mmi-xchange-integration's all-vendors media
 * import (2026-09-18) so any plugin's own item-at-a-time background job can
 * reuse it, not just XChange's.
 *
 * Why this exists: a single Action Scheduler action looping many
 * rate-limited external items in one request reliably hits AS's own
 * 300-second in-progress watchdog long before a large item list finishes —
 * confirmed live pulling XChange vendor image assets (106 vendors). This
 * trait processes exactly one item per tick and reschedules itself for the
 * next one, so no single request can run long enough to be killed — and
 * pairs that with a single recurring watchdog action (not "check on every
 * read of progress," which was tried first and found to race: Action
 * Scheduler can and does run more than one pending action for the same hook
 * concurrently, so several near-simultaneous readers each independently
 * "resuming" a stalled cursor produced competing, out-of-order chains) that
 * retries a stuck item once, then skips it, using a real MySQL GET_LOCK()
 * for its critical section rather than a TTL flag.
 *
 * A consuming class `use`s this trait and implements four methods; the
 * trait owns cursor tracking, the heartbeat/stall detection, retry-vs-skip,
 * and the watchdog's own re-scheduling. All state lives in MMI_DB (settings
 * for durable values, job_state for TTL'd ones) keyed by the consumer's own
 * $batch_key, so multiple independent batches — across different plugins,
 * or several of the same plugin's own features — never collide.
 *
 * Consumer contract:
 *
 *   abstract protected static function batch_process_item( string $batch_key, $item, int $cursor ): array;
 *     Process exactly one item. Return value is merged into the running
 *     totals via array addition (every key must be numeric) — return
 *     whatever per-item counters matter to the caller (e.g.
 *     ['downloaded' => 3, 'failed' => 0]). Throw on failure; the trait
 *     catches it, logs, and continues to the next item without crediting
 *     this one's totals.
 *
 *   abstract protected static function batch_empty_totals( string $batch_key ): array;
 *     The zero-value shape of the totals array above (same keys, all 0).
 *
 *   abstract protected static function batch_on_complete( string $batch_key, array $totals ): void;
 *     Called once, after the last item's tick, with the final accumulated
 *     totals. Do whatever the caller needs to report completion here
 *     (write a state setting, log a summary, etc.) — the trait itself
 *     doesn't have an opinion on completion reporting.
 *
 *   abstract protected static function batch_hook_group( string $batch_key ): string;
 *     The Action Scheduler group name to schedule both this batch's tick
 *     and watchdog hooks under (e.g. an existing AS_GROUP constant).
 *
 * And registers two Action Scheduler hooks in its own init(), pointing at
 * the two dispatcher methods below:
 *
 *   add_action( '{your_tick_hook}',     [ __CLASS__, 'batch_tick_dispatch' ], 10, 3 );
 *   add_action( '{your_watchdog_hook}', [ __CLASS__, 'batch_watchdog_dispatch' ], 10, 1 );
 *
 * Then starts a run with:
 *
 *   self::batch_start( $batch_key, $items, '{your_tick_hook}', '{your_watchdog_hook}' );
 *
 * See mmi-xchange-integration's MMI_Xchange_Vendors for a real, live
 * consumer (the all-vendors media import this trait was extracted from).
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

trait MMI_Batch_Watchdog_Trait {

	/** How often the single recurring watchdog re-checks for a stalled cursor. */
	const BATCH_WATCHDOG_INTERVAL = 2 * MINUTE_IN_SECONDS;

	/** Longer than AS's own 300s in-progress watchdog, so a genuinely-still-running tick is never mistaken for stalled. */
	const BATCH_HEARTBEAT_TTL = 6 * MINUTE_IN_SECONDS;

	/** Retry a stuck item once, then skip it rather than loop forever. */
	const BATCH_MAX_ATTEMPTS = 2;

	/* ── Consumer contract ────────────────────────────────────────────────── */

	abstract protected static function batch_process_item( string $batch_key, $item, int $cursor ): array;
	abstract protected static function batch_empty_totals( string $batch_key ): array;
	abstract protected static function batch_on_complete( string $batch_key, array $totals ): void;
	abstract protected static function batch_hook_group( string $batch_key ): string;

	/* ── Storage key helpers — all namespaced by $batch_key ──────────────────
	 * MMI_DB::set_setting()/get_setting() are durable (no TTL unless one is
	 * explicitly passed via job_state); MMI_DB::set_job_state()/get_job_state()
	 * are TTL-bound and self-expire — see class-mmi-db.php.
	 */

	private static function batch_queue_key( string $batch_key ): string {
		return $batch_key . '_queue';
	}

	private static function batch_totals_key( string $batch_key ): string {
		return $batch_key . '_totals';
	}

	private static function batch_cursor_key( string $batch_key ): string {
		return $batch_key . '_cursor';
	}

	private static function batch_tick_hook_key( string $batch_key ): string {
		return $batch_key . '_tick_hook';
	}

	private static function batch_heartbeat_key( string $batch_key ): string {
		return $batch_key . '_heartbeat';
	}

	private static function batch_lock_name( string $batch_key ): string {
		return $batch_key . '_watchdog_lock';
	}

	/**
	 * Total item count for the currently-queued run — for a consumer's own
	 * batch_process_item() to report progress ("item N of TOTAL") without
	 * needing to know the queue's storage key itself. Returns 0 if no batch
	 * is currently queued under this key.
	 */
	protected static function batch_total_items( string $batch_key ): int {
		$items = MMI_DB::get_setting( self::batch_queue_key( $batch_key ), null );
		return is_array( $items ) ? count( $items ) : 0;
	}

	/* ── Public entry points ─────────────────────────────────────────────── */

	/**
	 * Starts (or restarts) a batch run. Freezes $items as the queue, resets
	 * totals/cursor state, and schedules the first tick plus the recurring
	 * watchdog.
	 *
	 * @param string $batch_key     Unique key for this batch — e.g. 'mmi_xchange_media_import_batch'.
	 * @param array  $items         The full item list, resolved once (not re-fetched per tick).
	 * @param string $tick_hook     AS hook name your class registered pointing at batch_tick_dispatch().
	 * @param string $watchdog_hook AS hook name your class registered pointing at batch_watchdog_dispatch().
	 */
	protected static function batch_start( string $batch_key, array $items, string $tick_hook, string $watchdog_hook ): void {
		$group = static::batch_hook_group( $batch_key );

		MMI_DB::set_setting( self::batch_queue_key( $batch_key ), array_values( $items ) );
		MMI_DB::set_setting( self::batch_totals_key( $batch_key ), static::batch_empty_totals( $batch_key ) );
		MMI_DB::set_setting( self::batch_tick_hook_key( $batch_key ), $tick_hook );
		MMI_DB::delete_setting( self::batch_cursor_key( $batch_key ) );
		MMI_DB::delete_job_state( self::batch_heartbeat_key( $batch_key ) );

		self::batch_schedule_tick( $batch_key, $tick_hook, $group, 0, 1, 3 );
		self::batch_schedule_watchdog( $batch_key, $watchdog_hook, $group, self::BATCH_WATCHDOG_INTERVAL );
	}

	/**
	 * Action Scheduler hook target for one tick — process exactly one item,
	 * merge its totals, then schedule the next tick or finalize.
	 *
	 * @param string $batch_key
	 * @param int    $cursor
	 * @param int    $attempt
	 */
	public static function batch_tick_dispatch( string $batch_key, int $cursor, int $attempt = 1 ): void {
		$group = static::batch_hook_group( $batch_key );
		$items = MMI_DB::get_setting( self::batch_queue_key( $batch_key ), null );

		if ( ! is_array( $items ) ) {
			return; // No queue — batch never started this way, or was already finalized/reset.
		}

		// Read from storage rather than current_filter() — this must resolve
		// correctly even when called directly (tests, a manual retry from
		// wp-cli), not only from inside a genuine do_action() dispatch.
		$tick_hook = MMI_DB::get_setting( self::batch_tick_hook_key( $batch_key ), null );
		if ( ! is_string( $tick_hook ) || $tick_hook === '' ) {
			return;
		}

		$total_items = count( $items );
		$totals      = MMI_DB::get_setting( self::batch_totals_key( $batch_key ), null );
		if ( ! is_array( $totals ) ) {
			$totals = static::batch_empty_totals( $batch_key );
		}

		if ( $cursor >= $total_items ) {
			self::batch_finish( $batch_key, $totals );
			return;
		}

		// Record cursor/attempt and a heartbeat BEFORE the risky per-item
		// work — if this exact tick gets killed by AS's own 300s watchdog,
		// the heartbeat's expiry is what lets the recurring watchdog notice
		// and resume, per this trait's own docblock.
		MMI_DB::set_setting( self::batch_cursor_key( $batch_key ), [ 'cursor' => $cursor, 'attempt' => $attempt ] );
		MMI_DB::set_job_state( self::batch_heartbeat_key( $batch_key ), 1, self::BATCH_HEARTBEAT_TTL );

		try {
			$delta  = static::batch_process_item( $batch_key, $items[ $cursor ], $cursor );
			$totals = self::batch_merge_totals( $totals, is_array( $delta ) ? $delta : [] );
			MMI_DB::set_setting( self::batch_totals_key( $batch_key ), $totals );
		} catch ( \Throwable $e ) {
			// One item failing shouldn't sink the whole batch — logged and
			// skipped, the run continues with this item's contribution
			// simply absent from the totals.
			if ( class_exists( 'MMI_Logger' ) ) {
				MMI_Logger::error(
					"Batch [{$batch_key}]: item at cursor {$cursor} failed: " . $e->getMessage(),
					[ 'cursor' => $cursor ],
					'general',
					static::class
				);
			}
		}

		$next_cursor = $cursor + 1;
		if ( $next_cursor >= $total_items ) {
			self::batch_finish( $batch_key, $totals );
			return;
		}

		self::batch_schedule_tick( $batch_key, $tick_hook, $group, $next_cursor, 1, 1 );
	}

	/**
	 * Action Scheduler hook target for the single recurring watchdog. Only
	 * ever acts inside a real MySQL GET_LOCK() — see this trait's own
	 * docblock for why a TTL flag alone isn't sufficient (AS can run more
	 * than one pending action for the same hook concurrently).
	 */
	public static function batch_watchdog_dispatch( string $batch_key ): void {
		$watchdog_hook = current_filter();
		$group         = static::batch_hook_group( $batch_key );

		$items = MMI_DB::get_setting( self::batch_queue_key( $batch_key ), null );
		if ( ! is_array( $items ) ) {
			return; // Batch finished (or never started) — let the watchdog chain end here.
		}

		global $wpdb;
		$lock_name = self::batch_lock_name( $batch_key );
		$acquired  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock_name ) );

		if ( 1 === $acquired ) {
			try {
				self::batch_resolve_stalled_cursor( $batch_key );
			} finally {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
		// Lock not acquired: another watchdog tick is already handling this
		// exact check right now — skip this round, the next tick reassesses
		// in BATCH_WATCHDOG_INTERVAL seconds regardless of whether this
		// round did anything.

		self::batch_schedule_watchdog( $batch_key, $watchdog_hook, $group, self::BATCH_WATCHDOG_INTERVAL );
	}

	/* ── Internals ────────────────────────────────────────────────────────── */

	private static function batch_merge_totals( array $totals, array $delta ): array {
		foreach ( $totals as $key => $value ) {
			$totals[ $key ] = $value + ( $delta[ $key ] ?? 0 );
		}
		return $totals;
	}

	private static function batch_finish( string $batch_key, array $totals ): void {
		static::batch_on_complete( $batch_key, $totals );

		MMI_DB::delete_setting( self::batch_queue_key( $batch_key ) );
		MMI_DB::delete_setting( self::batch_totals_key( $batch_key ) );
		MMI_DB::delete_setting( self::batch_cursor_key( $batch_key ) );
		MMI_DB::delete_setting( self::batch_tick_hook_key( $batch_key ) );
		MMI_DB::delete_job_state( self::batch_heartbeat_key( $batch_key ) );
	}

	/** Only ever called from inside batch_watchdog_dispatch()'s GET_LOCK() section. */
	private static function batch_resolve_stalled_cursor( string $batch_key ): void {
		if ( MMI_DB::get_job_state( self::batch_heartbeat_key( $batch_key ) ) !== null ) {
			return; // Still within a live tick's own TTL — nothing stalled.
		}

		$items = MMI_DB::get_setting( self::batch_queue_key( $batch_key ), null );
		if ( ! is_array( $items ) ) {
			return; // Finished/reset between the outer check and here.
		}

		$cursor_state = MMI_DB::get_setting( self::batch_cursor_key( $batch_key ), null );
		if ( ! is_array( $cursor_state ) ) {
			return;
		}

		// The watchdog runs on its own recurring schedule, independent of any
		// live tick request, so it can't rely on current_filter() to know
		// the consumer's tick hook name — batch_start() persists it
		// alongside the queue specifically so this can recover it here.
		$tick_hook = MMI_DB::get_setting( self::batch_tick_hook_key( $batch_key ), null );
		if ( ! is_string( $tick_hook ) || $tick_hook === '' ) {
			return;
		}

		$group         = static::batch_hook_group( $batch_key );
		$stuck_cursor  = (int) ( $cursor_state['cursor'] ?? 0 );
		$stuck_attempt = (int) ( $cursor_state['attempt'] ?? 1 );

		if ( $stuck_attempt < self::BATCH_MAX_ATTEMPTS ) {
			if ( class_exists( 'MMI_Logger' ) ) {
				MMI_Logger::warn(
					"Batch [{$batch_key}]: item at cursor {$stuck_cursor} didn't finish within {$stuck_attempt} attempt(s) — retrying.",
					[ 'cursor' => $stuck_cursor, 'attempt' => $stuck_attempt ],
					'general',
					static::class
				);
			}
			self::batch_schedule_tick( $batch_key, $tick_hook, $group, $stuck_cursor, $stuck_attempt + 1, 1 );
			return;
		}

		if ( class_exists( 'MMI_Logger' ) ) {
			MMI_Logger::warn(
				"Batch [{$batch_key}]: item at cursor {$stuck_cursor} never completed after {$stuck_attempt} attempts — skipping it and moving on.",
				[ 'cursor' => $stuck_cursor, 'attempt' => $stuck_attempt ],
				'general',
				static::class
			);
		}
		self::batch_schedule_tick( $batch_key, $tick_hook, $group, $stuck_cursor + 1, 1, 1 );
	}

	private static function batch_schedule_tick( string $batch_key, string $tick_hook, string $group, int $cursor, int $attempt, int $delay_seconds ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_unschedule_all_actions( $tick_hook, [ $batch_key, $cursor, $attempt ], $group );
			as_schedule_single_action( time() + $delay_seconds, $tick_hook, [ $batch_key, $cursor, $attempt ], $group );
		} else {
			wp_schedule_single_event( time() + $delay_seconds, $tick_hook, [ $batch_key, $cursor, $attempt ] );
		}
	}

	private static function batch_schedule_watchdog( string $batch_key, string $watchdog_hook, string $group, int $delay_seconds ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_unschedule_all_actions( $watchdog_hook, [ $batch_key ], $group );
			as_schedule_single_action( time() + $delay_seconds, $watchdog_hook, [ $batch_key ], $group );
		} else {
			wp_schedule_single_event( time() + $delay_seconds, $watchdog_hook, [ $batch_key ] );
		}
	}
}
