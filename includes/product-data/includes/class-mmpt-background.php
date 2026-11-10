<?php
/**
 * The module's background work on a server that can't start processes.
 *
 * Product Data starts its runners (the scrape, maps and picture runners, a commit from the Chrome extension, a
 * brand's page search) as detached `wp` commands (Scraper::spawn()). Most hosts disable proc_open for web requests
 * (RunCloud's default does; found on a test store, migration phase 10d), so there nothing ever started: the panel
 * said "Starting…" for ever. Here the same work runs in the site's own PHP instead, through WooCommerce's Action
 * Scheduler (WP-Cron without it), a step at a time: each step works for STEP_S, then queues the next while work is
 * left, or waits WAIT_S when the server is busy. The runners keep their own locks, so a step and a detached runner
 * never work at once.
 *
 * What only a server browser can do (reading a page in Chromium, converting an AVIF) isn't done in a step; a
 * customer's own browser reads such pages with the extension.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Background {

	const HOOK   = 'mmpt_background_step';
	const GROUP  = 'mmpt';
	const STEP_S = 20;  // a step's work, well inside a web request's limit
	const WAIT_S = 60;  // the next step, when the server was busy

	/** True while a step runs: the runners' WP-CLI-only paths may run in it. */
	public static bool $stepping = false;

	public static function register_hooks(): void {
		add_action( self::HOOK, [ __CLASS__, 'step' ], 10, 1 );
	}

	/** Whether this server can start `wp` in the background. */
	public static function can_spawn(): bool {
		return function_exists( 'proc_open' );
	}

	/**
	 * Queue a step of $command (a Scraper::spawn() command line), unless one is waiting already.
	 * @param int $delay seconds from now
	 */
	public static function queue( string $command, int $delay = 0 ): bool {
		$args = [ $command ];
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_get_scheduled_actions' ) ) {
			// Pending only: the step running now counts as scheduled, and its next step must still queue.
			if ( as_get_scheduled_actions( [ 'hook' => self::HOOK, 'args' => $args, 'group' => self::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ], 'ids' ) ) {
				return true;
			}
			$id = $delay > 0 ? as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP ) : as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			return (bool) $id;
		}
		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 0, $delay ), self::HOOK, $args );
		}
		return true;
	}

	/** One step of $command's work; the next is queued while work is left. */
	public static function step( $command ): void {
		$command = (string) $command;
		$words   = preg_split( '/\s+/', trim( $command ) );
		$opts    = [];
		$args    = [];
		foreach ( array_slice( $words, 1 ) as $w ) { // after "mmpt"
			if ( preg_match( '/^--([a-z-]+)(?:=(.*))?$/', $w, $m ) ) {
				$opts[ $m[1] ] = $m[2] ?? true;
			} else {
				$args[] = $w;
			}
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 5 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a host may refuse; the step is short anyway
		}
		self::$stepping = true;
		$res            = [];
		try {
			switch ( implode( ' ', array_slice( $args, 0, 2 ) ) ) {
				case 'maps write-one':
					wp_set_current_user( (int) ( $opts['user'] ?? 0 ) );
					$only = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $opts['only'] ?? '' ) ) ) ) );
					$res  = ProductPreview::write( (string) ( $args[2] ?? '' ), (int) ( $args[3] ?? 0 ), $only );
					break;
				case 'maps scrape-now':
					$res = ProductPreview::scrape( (string) ( $args[2] ?? '' ), (int) ( $args[3] ?? 0 ), false ); // no server browser here
					break;
				case 'maps run':
					$res = VendorMaps::run( self::STEP_S );
					break;
				case 'images run':
					$res = Pictures::run( 0, self::STEP_S );
					break;
				case 'runner scrape':
				case 'scrape-run':
					$res = Scraper::run( self::STEP_S );
					break;
				case 'runner brand-pages':
				case 'brand-pages':
					$res = BrandSites::find_pages( (int) ( $opts['term'] ?? 0 ) );
					break;
				default:
					Module::warn( 'Unknown background step', [ 'command' => $command ] );
					return;
			}
		} catch ( \Throwable $e ) {
			Module::warn( 'Background step failed', [ 'command' => $command, 'error' => $e->getMessage() ] );
			return;
		} finally {
			self::$stepping = false;
		}
		if ( ! empty( $res['more'] ) ) {
			self::queue( $command, ! empty( $res['busy'] ) ? self::WAIT_S : 0 );
		}
	}
}
