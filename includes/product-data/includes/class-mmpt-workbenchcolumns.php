<?php
/**
 * Product Data's columns in the Product Workbench's results table (owner 2026-11-09: the Workbench's own columns
 * replace the old Product Data page's product grid). Offered in the Columns menu through
 * `mmi_pipeline_workbench_columns`, and filled through `mmi_pipeline_workbench_column_values` only for the rows on
 * screen, only when a person chose them:
 *
 *   pd_page     Maker page: read, proposed, gone or moved, none, the maker doesn't publish one
 *   pd_content  Content: which core parts the product lacks (pictures, description, features, requirements)
 *   pd_waiting  Waiting on you: a title, description, pictures or proposed page to decide
 *
 * A value is [ text, tone, title ]: tone is a badge colour (success, info, warning, error or '').
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorkbenchColumns {

	const COLUMNS = [
		'pd_page'    => 'Maker page',
		'pd_content' => 'Content',
		'pd_waiting' => 'Waiting on you',
	];
	/** Coverage parts that count as missing content (Coverage's keys). */
	const CORE = [ 'images' => 'pictures', 'description' => 'description', 'features' => 'features', 'os' => 'requirements' ];

	public static function register_hooks(): void {
		add_filter( 'mmi_pipeline_workbench_columns', [ __CLASS__, 'columns' ] );
		add_filter( 'mmi_pipeline_workbench_column_values', [ __CLASS__, 'values' ], 10, 3 );
	}

	/** @param array<string,string> $cols key → label */
	public static function columns( $cols ): array {
		return (array) $cols + self::COLUMNS;
	}

	/**
	 * @param array      $values id → key → [ text, tone, title ]
	 * @param int[]      $ids    the rows on screen
	 * @param string[]   $keys   the columns asked for
	 */
	public static function values( $values, $ids, $keys ): array {
		$values = (array) $values;
		$keys   = array_values( array_intersect( (array) $keys, array_keys( self::COLUMNS ) ) );
		$ids    = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( ! $keys || ! $ids ) {
			return $values;
		}
		$rows = array_intersect( $keys, [ 'pd_page', 'pd_content' ] ) ? array_column( PageReview::rows_for( $ids ), null, 'id' ) : [];
		$wait = in_array( 'pd_waiting', $keys, true ) ? self::waiting( $ids ) : [];
		$left = array_flip( Exclusions::ids() ); // products Product Data leaves alone
		foreach ( $ids as $id ) {
			$r = $rows[ $id ] ?? null;
			if ( isset( $left[ $id ] ) ) {
				foreach ( $keys as $k ) {
					$values[ $id ][ $k ] = [ 'Left out', '', 'Product Data leaves this product alone (excluded, not sold, or the store’s own product)' ];
				}
				continue;
			}
			if ( in_array( 'pd_page', $keys, true ) ) {
				$values[ $id ]['pd_page'] = self::page( $id, $r );
			}
			if ( in_array( 'pd_content', $keys, true ) ) {
				$values[ $id ]['pd_content'] = self::content( $r );
			}
			if ( in_array( 'pd_waiting', $keys, true ) ) {
				$w = $wait[ $id ] ?? [];
				$values[ $id ]['pd_waiting'] = $w ? [ implode( ', ', $w ), 'info', 'Decide in the sections above: Title proposals, Description and picture proposals, Maker pages' ] : [ 'Nothing', '', '' ];
			}
		}
		return $values;
	}

	/** The product's maker page as a word. @return array{0:string,1:string,2:string} */
	private static function page( int $id, ?array $r ): array {
		$url = $r ? (string) $r['current'] : (string) get_post_meta( $id, 'vendor_product_url', true );
		if ( ( $r['fetch_status'] ?? '' ) === UrlFinder::NO_PAGE || UrlFinder::is_no_page( $id ) ) {
			return [ 'Maker doesn’t publish it', '', 'A person said the maker has no page for this product' ];
		}
		if ( $r && $r['status'] === 'candidate' && $r['proposed'] !== '' ) {
			return [ 'Page proposed', 'info', $r['proposed'] ];
		}
		if ( ! str_starts_with( $url, 'http' ) ) {
			return [ 'No page', 'warning', 'No maker page yet' ];
		}
		if ( ! $r ) {
			return [ 'Not read yet', 'info', $url ];
		}
		return [ (string) $r['result_label'], (string) $r['result_badge'], $url ];
	}

	/** Which core parts the product lacks. @return array{0:string,1:string,2:string} */
	private static function content( ?array $r ): array {
		$attrs = $r['coverage']['attrs'] ?? null;
		if ( ! is_array( $attrs ) ) {
			return [ '—', '', 'Not measured: no maker page read' ];
		}
		$missing = [];
		$detail  = [];
		foreach ( $attrs as $a ) {
			$detail[] = $a['label'] . ': ' . $a['detail'] . ( $a['page'] !== '' ? ' (its page: ' . $a['page'] . ')' : '' );
			if ( isset( self::CORE[ $a['key'] ] ) && $a['state'] === 'missing' ) {
				$missing[] = self::CORE[ $a['key'] ];
			}
		}
		return $missing ? [ 'No ' . implode( ', ', $missing ), 'warning', implode( "\n", $detail ) ] : [ 'Complete', 'success', implode( "\n", $detail ) ];
	}

	/** What waits for a person, per product. @return array<int,string[]> */
	private static function waiting( array $ids ): array {
		global $wpdb;
		$in  = implode( ',', $ids );
		$out = [];
		foreach ( $wpdb->get_col( 'SELECT product_id FROM ' . Store::table() . " WHERE status IN ('proposed','stale','error') AND product_id IN ($in)" ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL -- table name, integers only
			$out[ (int) $id ][] = 'Title';
		}
		foreach ( $wpdb->get_results( 'SELECT product_id, kind FROM ' . Enrichment::table() . " WHERE status = 'proposed' AND product_id IN ($in)", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL -- table name, integers only
			$out[ (int) $x['product_id'] ][] = $x['kind'] === 'image' ? 'Pictures' : 'Description';
		}
		foreach ( $wpdb->get_col( 'SELECT product_id FROM ' . UrlFinder::table() . " WHERE status = 'candidate' AND product_id IN ($in)" ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL -- table name, integers only
			$out[ (int) $id ][] = 'Maker page';
		}
		return $out;
	}
}
