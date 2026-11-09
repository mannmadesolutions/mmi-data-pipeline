<?php
/**
 * A product released from an embargo gets its maker's page read when it can (owner 2026-11-04).
 *
 * While a product is held, its maker usually hasn't published its page: one product's address redirected to a teaser
 * the night before. So a read before release finds nothing, and nothing read it again afterwards: the product went on sale
 * with no picture. mmi-data-pipeline says when a hold ends (`mmi_pipeline_embargo_released`); the page is then read a few
 * times, since nobody knows when the maker's page goes up, until the product has its picture and a real description. These
 * are the usual reads: they fill what is empty or thin, and leave what a person wrote.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseRead {

	const HOOK  = 'mmpt_release_read';
	const AFTER = [ 5 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, DAY_IN_SECONDS ];

	public static function register_hooks(): void {
		add_action( 'mmi_pipeline_embargo_released', [ __CLASS__, 'released' ] );
		add_action( self::HOOK, [ __CLASS__, 'read' ] );
	}

	/** A hold ended: the reads are booked. */
	public static function released( $id ): void {
		foreach ( self::AFTER as $s ) {
			wp_schedule_single_event( time() + $s, self::HOOK, [ (int) $id, $s ] );
		}
	}

	/** One read, unless the product has what it needs by now. */
	public static function read( $id ): void {
		$id = (int) $id;
		if ( self::complete( $id ) || get_post_status( $id ) !== 'publish' ) {
			return;
		}
		Scraper::enqueue( [ $id ], 'scrape', true, 'manual' );
		Scraper::kick();
		PageEvents::queue_pictures( [ $id ], 'manual' ); // the runner starts if it was queued
	}

	/** It has a picture and more than a thin description. */
	public static function complete( int $id ): bool {
		return (int) get_post_thumbnail_id( $id ) > 0 && mb_strlen( Descriptions::plain( (string) ( get_post( $id )->post_content ?? '' ) ) ) >= PageEvents::THIN_CHARS;
	}
}
