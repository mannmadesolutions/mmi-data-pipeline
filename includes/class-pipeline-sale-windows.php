<?php
/**
 * Sale prices follow their dates, within minutes.
 *
 * WooCommerce starts and ends dated sales in one daily job, so a sale can begin up to a day late and, worse, end up to a
 * day late: a price held after the supplier's promotion is over. This runs WooCommerce's own job every 15 minutes. It does
 * nothing when no sale is due, and waits when the server is busy.
 *
 * @package MannMade\DataPipeline
 */

namespace MannMade\DataPipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sale_Windows {

	const HOOK  = 'mmi_pipeline_sale_windows';
	const GROUP = 'mmi-pipeline-sale-windows';
	const EVERY = 15 * MINUTE_IN_SECONDS;

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );
	}

	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, self::EVERY, self::HOOK, [], self::GROUP, true ); // unique: requests race here
		}
	}

	public static function run(): void {
		if ( ! function_exists( 'wc_scheduled_sales' ) ) {
			return;
		}
		if ( class_exists( '\MMI_Resource_Guard' ) && ! \MMI_Resource_Guard::can_proceed() ) {
			return; // the next run takes it
		}
		wc_scheduled_sales();
	}
}
