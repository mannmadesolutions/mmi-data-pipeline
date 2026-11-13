<?php
/**
 * What the maker-page block hands to the rest of Product Data, and the small
 * page facts it needs from them.
 *
 * Part of the block that moves to mmi-data-pipeline (Product Data migration
 * phase 5a, PRODUCT_DATA_MIGRATION_SCOPE.md §5c). The block reads and maps
 * maker pages; what follows a read (proposing a description, giving a product
 * its first pictures, dropping proposals for a page that was cleared) is other
 * modules' work. It is asked for here, through neutral hooks, instead of the
 * block calling those modules:
 *
 *   action 'mmi_pipeline_maker_page_read'  ( $id, $may )    a product's page was read; $may: parts this read may fill
 *   filter 'mmi_pipeline_queue_pictures'   ( $res, $ids, $source, $kick ) → { queued, started }
 *   filter 'mmi_pipeline_maker_page_cleared' ( $dropped, $id ) → proposals dropped
 *
 * The module answers them itself (register_hooks(), below: what follows a read is the module's own Enrichment and
 * Pictures work, moved from the site plugin's PageFollowUps in migration phase 10d, when a store without it was found
 * to propose nothing after a read). A store adds to them through the same hooks; this site's Product Variants and
 * Product Paths answers stay in mmi-admin.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageEvents {

	/** A description shorter than this (plain text) is thin: maker copy may fill it. */
	const THIN_CHARS = 200;

	public static function register_hooks(): void {
		add_action( 'mmi_pipeline_maker_page_read', [ __CLASS__, 'after_read' ], 10, 2 );
		add_filter( 'mmi_pipeline_queue_pictures', [ __CLASS__, 'enqueue_pictures' ], 10, 4 );
		add_filter( 'mmi_pipeline_maker_page_cleared', [ __CLASS__, 'drop_proposals' ], 10, 2 );
		add_filter( 'mmi_pipeline_exclusions_changed', [ __CLASS__, 'park_and_refresh' ], 10, 3 );
	}

	/** After a read: a description row, a picker row where it helps, writes that would happen anyway, first pictures. */
	public static function after_read( $id, $may ): void {
		$id   = (int) $id;
		$may  = (array) $may;
		$desc = ! empty( $may['description'] );
		$pics = ! empty( $may['pictures'] );
		Enrichment::generate( [ $id ] );             // description row (or a better description for one we wrote)
		Enrichment::propose_for_imaged( 1, [ $id ] ); // a picker row, only where the picture runner said a person's pick helps
		Enrichment::apply_ready( $id, array_keys( array_filter( [ 'content' => $desc, 'image' => $pics ] ) ) ); // written now when it would be written anyway
		// No picture yet: the picture runner gives it its first pictures (once; while vendor-page pictures are approved).
		if ( $pics && ! (int) get_post_thumbnail_id( $id ) && ! empty( Enrichment::rules()['image:vendor_page']['approved'] ) && ! empty( Enrichment::settings()['enabled'] ) && Pictures::enqueue( [ $id ], 'first' ) ) {
			Pictures::kick( true );
		}
	}

	/** @return array{queued:int, started:bool} */
	public static function enqueue_pictures( $res, $ids, $source, $kick ): array {
		$n       = Pictures::enqueue( (array) $ids, (string) $source );
		$started = $kick === null ? ( $n ? Pictures::kick() : false ) : Pictures::kick( (bool) $kick );
		return [ 'queued' => (int) ( $res['queued'] ?? 0 ) + $n, 'started' => ! empty( $res['started'] ) || $started ];
	}

	/** Proposals made from the product's maker page that a person hasn't applied. */
	public static function drop_proposals( $dropped, $id ): int {
		global $wpdb;
		return (int) $dropped + (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Enrichment::table() . " WHERE product_id = %d AND source = 'vendor_page' AND status IN ('proposed','accepted','stale','error')", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Exclusions changed: the newly excluded parked, the restored refreshed, titles recomposed. @return int rows removed */
	public static function park_and_refresh( $removed, $added, $restored ): int {
		$removed = (int) $removed + self::park( (array) $added );
		if ( $restored ) {
			Descriptions::refresh( (array) $restored );
		}
		Generator::dispatch();
		return $removed;
	}

	/**
	 * Newly excluded products: their open title and description proposals removed, their description rows parked.
	 * Applied titles stay (history; what is written on the product is never changed here). @return int rows removed
	 */
	public static function park( array $ids ): int {
		global $wpdb;
		$removed = 0;
		foreach ( array_chunk( array_values( array_filter( array_map( 'intval', $ids ) ) ), 500 ) as $chunk ) {
			$in       = implode( ',', $chunk );
			// audit-ok: $in is implode of an array_map('intval') chunk
			$removed += (int) $wpdb->query( 'DELETE FROM ' . Store::table() . " WHERE product_id IN ({$in}) AND status IN ('proposed','accepted','stale','error')" ); // phpcs:ignore WordPress.DB.PreparedSQL -- ints
			// audit-ok: $in is implode of an array_map('intval') chunk
			$removed += (int) $wpdb->query( 'DELETE FROM ' . Enrichment::table() . " WHERE product_id IN ({$in}) AND status IN ('proposed','accepted')" ); // phpcs:ignore WordPress.DB.PreparedSQL -- ints
			// audit-ok: $in is implode of an array_map('intval') chunk
			$wpdb->query( 'UPDATE ' . Descriptions::table() . " SET status = 'skipped', note = 'excluded from Product Titles' WHERE product_id IN ({$in}) AND status NOT IN ('approved','rejected','skipped')" ); // phpcs:ignore WordPress.DB.PreparedSQL -- ints
		}
		return $removed;
	}

	/** A product's page was read: let the modules that follow do their part. */
	public static function page_read( int $id, array $may ): void {
		do_action( 'mmi_pipeline_maker_page_read', $id, $may );
	}

	/**
	 * Ask for products' pictures to be (re)done from their maker pages.
	 *
	 * @param string    $source manual | first | …, as the picture queue names it
	 * @param bool|null $kick   start the runner: null = only when something was queued, true/false = start it anyway / with $auto
	 * @return array{queued:int, started:bool}
	 */
	public static function queue_pictures( array $ids, string $source = 'manual', ?bool $kick = null ): array {
		$res = apply_filters( 'mmi_pipeline_queue_pictures', [ 'queued' => 0, 'started' => false ], $ids, $source, $kick );
		return [ 'queued' => (int) ( $res['queued'] ?? 0 ), 'started' => ! empty( $res['started'] ) ];
	}

	/** A product's maker page was cleared: proposals made from it go. @return int proposals dropped */
	public static function page_cleared( int $id ): int {
		return (int) apply_filters( 'mmi_pipeline_maker_page_cleared', 0, $id );
	}

	/** An asset's version for cache busting: the file's own modification time. */
	public static function asset_ver( string $rel ): string {
		$f = dirname( __DIR__ ) . '/assets/' . ltrim( $rel, '/' );
		return is_readable( $f ) ? (string) filemtime( $f ) : '1';
	}

	/**
	 * Whether the code this long-running process started with was replaced on disk (a deploy): it should
	 * stop so a fresh process runs the new code. Asked of filter mmi_pipeline_product_data_code_changed;
	 * the plugin hosting the module compares its version (this site: mmi-admin's).
	 */
	public static function code_changed(): bool {
		return (bool) apply_filters( 'mmi_pipeline_product_data_code_changed', false );
	}

	/** A URL under the module's assets/ folder (filter mmi_pipeline_product_data_asset_url to serve them elsewhere). */
	public static function asset_url( string $rel ): string {
		return (string) apply_filters( 'mmi_pipeline_product_data_asset_url', plugin_dir_url( dirname( __DIR__ ) . '/x' ) . 'assets/' . ltrim( $rel, '/' ), $rel );
	}

	/** A site's home page (path "/" or a bare locale such as "/en-us/"), not a product's own page. */
	public static function is_home( string $url ): bool {
		return $url !== '' && (bool) preg_match( '#^/?(?:[a-z]{2}(?:[-_][a-z]{2})?/?)?$#i', (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}

	/** A product's pictures: its featured picture, then its gallery (attachments that exist). @return int[] */
	public static function pictures( int $id ): array {
		$ids = array_merge( [ (int) get_post_thumbnail_id( $id ) ], array_map( 'intval', explode( ',', (string) get_post_meta( $id, '_product_image_gallery', true ) ) ) );
		return array_values( array_unique( array_filter( $ids, static fn( $a ) => $a > 0 && get_post_type( $a ) === 'attachment' ) ) );
	}
}
