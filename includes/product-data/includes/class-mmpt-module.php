<?php
/**
 * The Product Data module's own plumbing: its REST namespace, capability, background-job group, saved state and log.
 *
 * Part of the module that moves to mmi-data-pipeline (phase 5b). The values are the ones the data and
 * routes were first made under (`mmpt/v1`, the `mmi-product-titles` job group, the same settings keys and log
 * category), so nothing is renamed and the extension keeps working.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Module {

	const NS    = 'mmpt/v1';            // REST namespace
	const CAP   = 'manage_woocommerce'; // who may use its screens and routes
	const GROUP = 'mmi-product-titles'; // Action Scheduler group of its background jobs

	/** A saved state array (an MMI setting). */
	public static function state( string $key ): array {
		$s = \MMI_Settings::get( $key, [] );
		return is_array( $s ) ? $s : [];
	}

	public static function set_state( string $key, array $patch ): array {
		$s = array_merge( self::state( $key ), $patch );
		\MMI_Settings::set( $key, $s );
		return $s;
	}

	public static function info( string $msg, array $ctx = [] ): void {
		self::log( 'info', $msg, $ctx );
	}

	public static function warn( string $msg, array $ctx = [] ): void {
		self::log( 'warn', $msg, $ctx );
	}

	public static function error( string $msg, array $ctx = [] ): void {
		self::log( 'error', $msg, $ctx );
	}

	private static function log( string $level, string $msg, array $ctx ): void {
		if ( class_exists( 'MMI_Logger' ) ) {
			\MMI_Logger::$level( '[ProductTitles] ' . $msg, $ctx, 'sync', 'ProductTitles' );
		}
	}
}
