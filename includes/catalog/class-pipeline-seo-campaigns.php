<?php
/**
 * Ended campaigns leave search on their own.
 *
 * A campaign (`mmi_campaign` term, /deals/<slug>/) is a sale page. Once its
 * last day has passed it has nothing to sell, so it should not rank or be
 * worked on for SEO. This answers mmi-seo's `mmi_seo_hidden` filter: an ended
 * campaign is noindex, out of the sitemap and out of Meta health's problems
 * ("Hidden from search · Campaign ended"). The page itself stays up, showing
 * "Ended", for anyone holding the link. If its dates move forward again it
 * returns to search the same way.
 *
 * A campaign ends with the calendar, not with an edit, so an hourly check
 * compares each campaign with what it was last time and asks mmi-seo to
 * measure the ones that changed (`mmi_seo_rescan`). mmi-seo then tells Bing
 * through IndexNow. Editing a campaign's end date checks it at once.
 *
 * @package MMI_Data_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMI_Pipeline_SEO_Campaigns {

	const TAXONOMY = 'mmi_campaign';
	const META_END = 'end';          // Y-m-d, the sale's last day (MannMade\DataPipeline\Campaigns::META_END)
	const SEEN     = '_mmi_campaign_seo_hidden'; // '1' while this campaign was last seen as ended
	const HOOK     = 'mmi_pipeline_seo_campaigns_check';

	public static function register_hooks(): void {
		add_filter( 'mmi_seo_hidden', array( __CLASS__, 'hidden' ), 10, 2 );
		add_action( self::HOOK, array( __CLASS__, 'check' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
		add_action( 'updated_term_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'added_term_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'deleted_term_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
	}

	/**
	 * @param string $reason Another filter's reason, or ''.
	 * @param object $item   mmi-seo's Item (kind, id, object).
	 */
	public static function hidden( $reason, $item ): string {
		if ( (string) $reason !== '' || ! is_object( $item ) || ( $item->kind ?? '' ) !== 'term' ) {
			return (string) $reason;
		}
		$term = $item->object ?? null;
		if ( ! $term instanceof \WP_Term || $term->taxonomy !== self::TAXONOMY ) {
			return '';
		}
		return self::ended( (int) $term->term_id ) ? __( 'Campaign ended', 'mmi-data-pipeline' ) : '';
	}

	/** Whether a campaign's last day has passed (store calendar). */
	public static function ended( int $term_id ): bool {
		$end = (string) get_term_meta( $term_id, self::META_END, true );
		return $end !== '' && $end < current_time( 'Y-m-d' );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	/** Every campaign whose ended state differs from last time is measured again by mmi-seo. */
	public static function check(): int {
		$ids = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$changed = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$changed += self::check_one( (int) $id ) ? 1 : 0;
		}
		return $changed;
	}

	private static function check_one( int $term_id ): bool {
		$now  = self::ended( $term_id ) ? '1' : '';
		$seen = (string) get_term_meta( $term_id, self::SEEN, true );
		if ( $now === $seen ) {
			return false;
		}
		if ( $now === '1' ) {
			update_term_meta( $term_id, self::SEEN, '1' );
		} else {
			delete_term_meta( $term_id, self::SEEN );
		}
		do_action( 'mmi_seo_rescan', 'term', $term_id );
		return true;
	}

	/** A campaign's end date edited (by hand or by the sync): check it now, not at the next hour. */
	public static function on_meta( $meta_id, $term_id, $key ): void {
		if ( $key === self::META_END ) {
			$term = get_term( (int) $term_id );
			if ( $term instanceof \WP_Term && $term->taxonomy === self::TAXONOMY ) {
				self::check_one( (int) $term_id );
			}
		}
	}
}
