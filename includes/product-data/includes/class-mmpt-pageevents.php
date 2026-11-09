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
 * This site answers them in PageFollowUps (which stays in mmi-admin).
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
