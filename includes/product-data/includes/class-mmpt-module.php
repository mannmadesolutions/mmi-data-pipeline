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

	/**
	 * A place in the Product Workbench (its landing keys, src/workbench/landing.ts): q (a product ID or words), brand
	 * (term ID), site (a maker host), bundle (a bundle's ID) and sec (data, titles, proposals, pictures, pages, sites,
	 * maps, rules, left, writing, bundles, advisories, parts). Without sec, the section follows what was given.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function workbench_url( array $args = [] ): string {
		$get    = static fn( string $k ): string => isset( $args[ $k ] ) && is_scalar( $args[ $k ] ) && '0' !== (string) $args[ $k ] ? trim( (string) $args[ $k ] ) : '';
		$q      = $get( 'q' );
		$brand  = absint( $get( 'brand' ) );
		$site   = $get( 'site' );
		$bundle = absint( $get( 'bundle' ) );
		$sec    = sanitize_key( $get( 'sec' ) );
		if ( $sec === '' ) {
			$sec = $bundle ? 'bundles' : ( $q !== '' ? 'data' : ( $site !== '' ? 'maps' : ( $brand ? 'sites' : '' ) ) );
		}
		$out = [ 'page' => 'mmi-data-pipeline', 'pipeline_tab' => 'workbench' ];
		foreach ( [ 'sec' => $sec, 'q' => $q, 'brand' => $brand ? (string) $brand : '', 'site' => $site, 'bundle' => $bundle ? (string) $bundle : '' ] as $k => $v ) {
			if ( $v !== '' ) {
				$out[ 'wb_' . $k ] = $v;
			}
		}
		return add_query_arg( array_map( 'rawurlencode', $out ), admin_url( 'admin.php' ) );
	}

	private static function log( string $level, string $msg, array $ctx ): void {
		if ( class_exists( 'MMI_Logger' ) ) {
			\MMI_Logger::$level( '[ProductTitles] ' . $msg, $ctx, 'sync', 'ProductTitles' );
		}
	}
}
