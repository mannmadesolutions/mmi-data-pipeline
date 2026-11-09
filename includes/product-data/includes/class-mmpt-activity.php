<?php
/**
 * Is a person running a sync, import or fetch right now? (Owner D5,
 * 2026-10-08: the picture runner must "stay out of the way of any manual
 * syncs".) One answer from every place the suite records such work; see
 * PRODUCT_IMAGE_REPLACEMENT_HANDOFF.md §4.9 for where each signal is written.
 *
 * `manual`: a person started it — the caller pauses.
 * `busy`:   something heavy runs, whoever started it — the caller slows down.
 * Each source is read only when its plugin is active, so a missing plugin is
 * simply "nothing running there".
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activity {

	const REVERB_MARKER     = 'mmi_reverb_manual_activity'; // set by Reverb's Sync button and small Sync Selected (10 min)
	const REVERB_MANUAL_MAX = 2 * HOUR_IN_SECONDS;          // a manual Reverb job stops itself after 2 h; older "running" is a stalled record
	const REVERB_FULL_MAX   = 20 * HOUR_IN_SECONDS;
	const XCHANGE_SYNC_MAX  = 30 * MINUTE_IN_SECONDS;
	const VENDOR_PAGES_MARKER = 'mmpt_vendor_pages_manual'; // set by a row's Read/Scrape and a drawer's Write, done there and then (MARKER_S)
	/** A one-off read or write is over when its request returns: the runner waits a little while a person clicks on, no
	 *  longer (owner 2026-10-31: 10 minutes after each one held the pictures they had just asked for). Queued reads
	 *  hold it by themselves ("pages queued by hand still to read" below) until they're read. */
	const MARKER_S = 2 * MINUTE_IN_SECONDS;

	/** A person just read, scraped or queued vendor pages (PageReview). */
	public static function mark_vendor_pages(): void {
		set_transient( self::VENDOR_PAGES_MARKER, time(), self::MARKER_S );
	}

	/** @return array{manual:bool, busy:bool, reasons:string[]} */
	public static function check(): array {
		// A runner is one long WP-CLI process: with the Redis object cache it keeps its own copy of every transient and
		// option it has read, so a 10-minute marker read at its start never expired for it (2026-10-31: the picture
		// runner stayed "paused" 30 minutes after a fill's marker had gone, and would have for its whole 6 hours).
		if ( defined( 'WP_CLI' ) && WP_CLI && function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		$manual = [];
		$busy   = [];
		$now    = time();

		// Reverb.
		if ( class_exists( 'MMI_Reverb_Sync_Job' ) ) {
			foreach ( [ 'manual' => self::REVERB_MANUAL_MAX, 'fullstore' => self::REVERB_FULL_MAX ] as $type => $max ) {
				$st = \MMI_Reverb_Sync_Job::get_state( $type );
				if ( \MMI_Reverb_Sync_Job::is_active( $st ) && $now - (int) strtotime( (string) ( $st['started_at'] ?? '' ) ) < $max ) {
					$manual[] = $type === 'manual' ? 'Reverb: Sync Selected running' : 'Reverb: Sync Due Products running';
				}
			}
		}
		if ( get_transient( self::REVERB_MARKER ) ) {
			$manual[] = 'Reverb: a product was synced by hand in the last 10 minutes';
		}
		if ( get_transient( 'mmi_reverb_sync_full_store_running' ) ) {
			$manual[] = 'Reverb: Sync Due Products starting';
		}
		$legacy = get_transient( 'mmi_reverb_full_sync_progress' );
		if ( is_array( $legacy ) && empty( $legacy['cancelled'] ) ) {
			$manual[] = 'Reverb: Sync Entire Store running';
		}
		if ( function_exists( 'as_get_scheduled_actions' ) && function_exists( 'mmi_reverb_async_sync_headroom_cap' ) ) {
			$pending = count( as_get_scheduled_actions( [ 'hook' => 'mmi_reverb_async_sync_product', 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 500 ], 'ids' ) );
			if ( $pending > (int) ( mmi_reverb_async_sync_headroom_cap() / 2 ) ) {
				$busy[] = sprintf( 'Reverb: %d listing syncs waiting', $pending );
			}
		}

		// Data Pipeline.
		if ( class_exists( 'MMI_DB' ) && method_exists( 'MMI_DB', 'get_job_state' ) ) {
			if ( \MMI_DB::get_job_state( 'mmi_manual_import_running' ) ) {
				$manual[] = 'Data Pipeline: an import started by hand is running';
			}
			$cat = \MMI_DB::get_job_state( 'mmi_catalog_updater_lock' );
			if ( is_array( $cat ) ) {
				if ( ( $cat['owner'] ?? '' ) === 'ajax' ) {
					$manual[] = 'Data Pipeline: Catalog Maintenance started by hand';
				} else {
					$busy[] = 'Data Pipeline: Catalog Maintenance running';
				}
			}
			global $wpdb;
			$sources = $wpdb->get_col( "SELECT supplier_id FROM {$wpdb->prefix}mmi_data_sources WHERE enabled = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
			foreach ( (array) $sources as $sid ) {
				if ( get_transient( 'mmi_pipeline_fetch_now_' . $sid ) ) {
					$manual[] = "Data Pipeline: Fetch now on {$sid}";
					continue;
				}
				$run = \MMI_DB::get_job_state( 'mmi_source_fetch_run_' . $sid );
				if ( is_array( $run ) && ( $run['trigger'] ?? '' ) === 'manual' ) {
					$manual[] = "Data Pipeline: Fetch now on {$sid}";
				} elseif ( \MMI_DB::get_job_state( 'mmi_source_fetch_lock_' . $sid ) ) {
					$busy[] = "Data Pipeline: fetching {$sid}";
				}
			}
			$profiles = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mmi_vip_job_state WHERE state_key LIKE 'mmi\\_profile\\_import\\_lock\\_%' AND ( expires_at IS NULL OR expires_at > UTC_TIMESTAMP() )" ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
			if ( $profiles ) {
				$busy[] = 'Data Pipeline: a scheduled import is running';
			}
		}

		// XChange.
		if ( class_exists( 'MMI_Xchange_Vendors' ) && method_exists( 'MMI_Xchange_Vendors', 'web_assets_run' ) && \MMI_Xchange_Vendors::web_assets_run_active() ) {
			$run = (array) \MMI_Xchange_Vendors::web_assets_run();
			if ( ( $run['trigger'] ?? '' ) === 'manual' ) {
				$manual[] = 'XChange: web-assets pull started by hand';
			} else {
				$busy[] = 'XChange: web-assets pull running';
			}
		}
		if ( class_exists( 'MMI_Xchange_Order_Sync' ) && method_exists( 'MMI_Xchange_Order_Sync', 'get_full_sync_progress' ) ) {
			$p = \MMI_Xchange_Order_Sync::get_full_sync_progress();
			if ( in_array( $p['status'] ?? '', [ 'queued', 'running' ], true ) && $now - (int) strtotime( (string) ( $p['updated_at'] ?? '' ) ) < self::XCHANGE_SYNC_MAX ) {
				$manual[] = 'XChange: Full Sync running';
			}
		}

		// Vendor pages tab: a Read/Scrape a person started (bulk queue, or one row read now).
		if ( get_transient( self::VENDOR_PAGES_MARKER ) ) {
			$manual[] = 'Vendor pages: a page was read or written by hand in the last 2 minutes';
		}
		$scrape = Scraper::status();
		if ( $scrape['waiting'] + $scrape['working'] > 0 ) {
			global $wpdb;
			$by_hand = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Scraper::table() . " WHERE done_at IS NULL AND source = 'manual'" ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
			if ( $by_hand ) {
				$manual[] = sprintf( 'Vendor pages: %d pages queued by hand still to read', $by_hand );
			} elseif ( $scrape['running'] ) {
				$busy[] = 'Vendor pages: the nightly read is running';
			}
		}

		if ( PageStore::server_busy() ) {
			$busy[] = 'Server load or memory over its limit';
		}
		return [ 'manual' => (bool) $manual, 'busy' => (bool) $busy, 'reasons' => array_values( array_unique( array_merge( $manual, $busy ) ) ) ];
	}
}
