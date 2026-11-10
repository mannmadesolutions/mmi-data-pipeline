<?php
/**
 * `wp mmpt extension`: the MMI Product Data Chrome extension's packages (migration phase 10e).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ExtensionCli {

	/**
	 * Build the Chrome Web Store package: the copy that names no store, files at the zip's root, ready to upload in
	 * the developer dashboard. Prints its path, version and build.
	 *
	 * ## OPTIONS
	 * --out=<dir>
	 * : Where to put it (the zip is named mmi-product-data-<version>.zip).
	 */
	public function package( $args, $assoc ): void {
		$dir = rtrim( (string) ( $assoc['out'] ?? '' ), '/' );
		if ( $dir === '' || ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			\WP_CLI::error( 'Give --out=<a folder you can write to>.' );
		}
		$tmp = ExtBridge::listing_zip();
		if ( is_wp_error( $tmp ) ) {
			\WP_CLI::error( $tmp->get_error_message() );
		}
		$file = $dir . '/mmi-product-data-' . sanitize_file_name( ExtBridge::version() ) . '.zip';
		if ( ! rename( $tmp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			\WP_CLI::error( 'The package couldn’t be moved to ' . $file );
		}
		\WP_CLI::success( sprintf( '%s (version %s, build %s, %d bytes)', $file, ExtBridge::version(), ExtBridge::build(), (int) filesize( $file ) ) );
	}
}
