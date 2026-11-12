<?php
/**
 * Product Data's writes to product fields, through the pipeline's write
 * gateway (MMI_Pipeline_Write, Product Data migration phase 2).
 *
 * Every title, description, picture, vendor link and category/brand change
 * Product Data makes goes through field() here, so each one is lock-checked
 * the same way, shows in the Product Workbench's Recent changes (one job per
 * writer per day, with Undo), and fires `mmi_pipeline_field_written`. Each
 * writer keeps its own review rows and Revert as before. A listed Reverb
 * listing is updated from that action by mmi-reverb-integration (it replaced
 * this module's ReverbSync, 2026-11-09).
 *
 * Modes match what each writer did before the gateway (no behaviour change):
 * a person's accepted change, and a writer putting back its own earlier
 * write, are 'force' (they never stopped at a lock; they add one so imports
 * can't undo them).
 *
 * If the pipeline isn't active, the write still happens here, the same way,
 * without the change log.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Write {

	/** source => [ label, fields, locks what it writes ] */
	const SOURCES = [
		'product-titles'         => [ 'Product Data: titles', [ 'post_title' ], true ],
		'product-descriptions'   => [ 'Product Data: descriptions', [ 'post_content' ], true ],
		'product-pictures'       => [ 'Product Data: pictures', [ '_thumbnail_id', '_product_image_gallery' ], true ],
		'product-first-pictures' => [ 'Product Data: first pictures', [ '_thumbnail_id', '_product_image_gallery' ], false ],
		'product-vendor-links'   => [ 'Product Data: maker page links', [ 'vendor_product_url', 'vendor_product_url_verified' ], false ],
		'product-terms'          => [ 'Product Data: categories and brands', [ 'tax:product_cat', 'tax:product_brand' ], true ],
		'product-issue-fixes'    => [ 'Product Data: fixed from the Workbench', [ '_sku', '_virtual', '_thumbnail_id', '_product_image_gallery', '_weight', '_length', '_width', '_height', '_global_unique_id' ], false ],
		'product-feed-fill'      => [ 'Product Data: filled from distributor data', [ '_mmi_features', '_mmi_youtube_urls', '_mmi_mac_requirements', '_mmi_windows_requirements', '_mmi_linux_requirements', '_mmi_licensing', '_mmi_platforms' ], false ],
		'product-extension'      => [ 'Product Data: Chrome extension', [ '_mmi_disclaimer', '_mmi_youtube_urls', '_mmi_audio', '_mmi_features', '_mmi_manuals', '_mmi_version', '_mmi_mac_requirements', '_mmi_windows_requirements', '_mmi_linux_requirements', '_mmi_platforms', '_weight', '_length', '_width', '_height' ], false ],
		'product-page-fills'     => [ 'Product Data: filled from maker pages', [ '_mmi_features', '_mmi_manuals', '_mmi_youtube_urls', '_mmi_audio', '_mmi_version', '_mmi_disclaimer', '_mmi_mac_requirements', '_mmi_windows_requirements', '_mmi_linux_requirements', '_mmi_platforms', '_weight', '_length', '_width', '_height' ], true ],
	];

	public static function register_hooks(): void {
		add_action( 'init', [ __CLASS__, 'register_sources' ], 1 );
	}

	public static function register_sources(): void {
		if ( ! class_exists( '\MMI_Pipeline_Write' ) ) {
			return;
		}
		foreach ( self::SOURCES as $source => [ $label, $fields, $locks ] ) {
			\MMI_Pipeline_Write::register_source( $source, $label, $fields, $locks );
		}
	}

	/**
	 * A meta change already made through the content registry, logged as $source's (MMI_Pipeline_Write::record()), so
	 * it shows in Recent changes with Undo. Nothing without the pipeline's change log.
	 */
	public static function logged( int $id, string $field, ?string $was, string $source, array $locks_added = [] ): void {
		if ( class_exists( '\MMI_Pipeline_Write' ) && method_exists( '\MMI_Pipeline_Write', 'record' ) ) {
			\MMI_Pipeline_Write::record( $id, $field, $was, $source, $locks_added );
		}
	}

	/**
	 * Write one product field. Same arguments and result as MMI_Pipeline_Write::field().
	 *
	 * @return array{result:string, lock_added?:bool, message?:string}
	 */
	public static function field( int $id, string $field, $value, string $source, string $mode = 'set', array $opts = [] ): array {
		if ( class_exists( '\MMI_Pipeline_Write' ) ) {
			return \MMI_Pipeline_Write::field( $id, $field, $value, $source, $mode, $opts );
		}
		return self::direct( $id, $field, $value, $source, $mode, $opts );
	}

	/** The write without the gateway (pipeline not active): same lock rules, no change log. */
	private static function direct( int $id, string $field, $value, string $source, string $mode, array $opts ): array {
		$locks    = class_exists( '\MMI_Pipeline_Field_Locks' );
		$lock_key = in_array( $field, [ '_thumbnail_id', '_product_image_gallery' ], true ) ? 'images' : $field;
		$locked   = $locks && \MMI_Pipeline_Field_Locks::is_locked( $id, $lock_key );
		if ( $locked && $mode !== 'force' ) {
			return [ 'result' => 'locked' ];
		}
		if ( in_array( $field, [ 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status' ], true ) ) {
			$slug = (string) get_post_field( 'post_name', $id, 'raw' );
			$res  = wp_update_post( wp_slash( [ 'ID' => $id, $field => (string) $value ] ), true );
			if ( is_wp_error( $res ) ) {
				return [ 'result' => 'error', 'message' => $res->get_error_message() ];
			}
			clean_post_cache( $id );
			if ( $field !== 'post_name' && $slug !== '' && (string) get_post_field( 'post_name', $id, 'raw' ) !== $slug ) {
				wp_update_post( [ 'ID' => $id, 'post_name' => $slug ] );
			}
		} elseif ( $field === '_thumbnail_id' ) {
			(int) $value > 0 ? set_post_thumbnail( $id, (int) $value ) : delete_post_thumbnail( $id );
		} elseif ( strpos( $field, 'tax:' ) === 0 ) {
			$res = wp_set_object_terms( $id, array_map( 'intval', (array) $value ), substr( $field, 4 ), false );
			if ( is_wp_error( $res ) ) {
				return [ 'result' => 'error', 'message' => $res->get_error_message() ];
			}
		} elseif ( $value === null ) {
			delete_post_meta( $id, $field );
		} else {
			update_post_meta( $id, $field, is_string( $value ) ? wp_slash( $value ) : $value );
		}
		$lock_added = ! empty( $opts['lock'] ) && $locks && ! $locked;
		if ( $lock_added ) {
			\MMI_Pipeline_Field_Locks::lock( $id, $lock_key );
		}
		do_action( 'mmi_pipeline_field_written', $id, $field, $value, null, $source ); // listeners (Reverb) work without the pipeline too
		return [ 'result' => 'written', 'lock_added' => $lock_added ];
	}
}
