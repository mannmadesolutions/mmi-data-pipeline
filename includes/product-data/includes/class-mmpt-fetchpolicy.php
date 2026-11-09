<?php
/**
 * Who the module says it is when it reads a maker's site.
 *
 * Part of the module that moves to mmi-data-pipeline (phase 5b,
 * PRODUCT_DATA_MIGRATION_SCOPE.md §5c). A store's own identity and any
 * browser-style request are the store's policy (owner decision 3: a browser
 * user agent doesn't ship), so they come from filter `mmi_pipeline_fetch_policy`
 * ( $policy ) with keys:
 *   bot_ua      the user agent for ordinary page reads and robots.txt
 *   bot_token   the robots.txt user-agent token this crawler obeys
 *   browser_ua  the user agent where a site refuses anything but a browser
 *   asset_ua    the user agent for downloading pictures
 * With nothing attached, every request uses one honest agent naming this site.
 * This site answers in SitePolicy (stays in mmi-admin).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FetchPolicy {

	/** @return array{bot_ua:string, bot_token:string, browser_ua:string, asset_ua:string} */
	public static function get(): array {
		static $p = null;
		if ( $p === null ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$ua   = 'MMI-Data-Pipeline/1.0 (+' . home_url( '/' ) . ')';
			$def  = [ 'bot_ua' => $ua, 'bot_token' => 'mmi-data-pipeline', 'browser_ua' => $ua, 'asset_ua' => $ua ];
			$got  = apply_filters( 'mmi_pipeline_fetch_policy', $def, $host );
			$p    = array_map( 'strval', array_merge( $def, is_array( $got ) ? array_intersect_key( $got, $def ) : [] ) );
		}
		return $p;
	}

	public static function bot_ua(): string {
		return self::get()['bot_ua'];
	}

	public static function bot_token(): string {
		return strtolower( self::get()['bot_token'] );
	}

	public static function browser_ua(): string {
		return self::get()['browser_ua'];
	}

	public static function asset_ua(): string {
		return self::get()['asset_ua'];
	}
}
