<?php
/**
 * Bricks query-filter provider "MMI Product Content"
 *
 * Lets a native Bricks checkbox/radio/select filter (source: Custom field)
 * filter by a Product Content Registry list concept. Pick the provider, then
 * the concept's tag as the meta key ({mmi_spec_platforms}). Each row is
 * indexed as its own option (id as the value, its text as the label), and the
 * query matches the value inside the concept's canonical JSON list, so one
 * product stored as ["mac","windows"] appears under both options.
 *
 * Bricks loads providers by class name from its own namespace
 * (Integrations\Query_Filters\Fields::register_providers()), so this file is
 * required only from that filter, once Bricks is running.
 *
 * @package MannMade\DataPipeline
 */

namespace Bricks\Integrations\Query_Filters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Field_Mmi {

	const KEY = 'mmi';

	/** Storage shapes held as a JSON list of strings, which a LIKE on '"value"' can match. */
	const LIST_SHAPES = [ 'platforms', 'list' ];

	/** @var int[] Products whose `_mmi_*` meta was first added this request; Bricks reindexes only updates and deletes. */
	private $pending = [];

	public function __construct() {
		add_filter( 'bricks/query_filters/index_args', [ $this, 'index_args' ], 10, 3 );
		add_filter( 'bricks/query_filters/index_post/meta_exists', [ $this, 'meta_exists' ], 10, 4 );
		add_filter( 'bricks/query_filters/custom_field_index_rows', [ $this, 'index_rows' ], 10, 5 );
		add_filter( 'bricks/query_filters/custom_field_meta_query', [ $this, 'meta_query' ], 10, 4 );
		add_action( 'added_post_meta', [ $this, 'queue_reindex' ], 10, 3 );
	}

	public function get_name() {
		return 'MMI Product Content';
	}

	public static function is_active() {
		return class_exists( '\MMI_Product_Content_Registry' );
	}

	/** "{mmi_spec_platforms}", "mmi_spec_platforms", "platforms" or "_mmi_platforms" → "platforms" ('' when unknown). */
	public static function concept( $key ): string {
		$key = trim( (string) $key, '{} ' );
		$key = strpos( $key, \MMI_Product_Content_Bricks::QUERY_PREFIX ) === 0 ? substr( $key, strlen( \MMI_Product_Content_Bricks::QUERY_PREFIX ) ) : $key;
		if ( \MMI_Product_Content_Registry::exists( $key ) ) {
			return $key;
		}
		foreach ( \MMI_Product_Content_Registry::storable_concepts() as $concept ) {
			if ( \MMI_Product_Content_Registry::canonical_key( $concept ) === $key ) {
				return $concept;
			}
		}
		return '';
	}

	/** Index only products that hold the concept's canonical key. */
	public function index_args( $args, $filter_source, $filter_settings ) {
		if ( ( $filter_settings['fieldProvider'] ?? '' ) !== self::KEY || $filter_source !== 'customField' ) {
			return $args;
		}
		$meta_key = \MMI_Product_Content_Registry::canonical_key( self::concept( $filter_settings['customFieldKey'] ?? '' ) );
		if ( $meta_key !== '' ) {
			$args['meta_query'] = [ [ 'key' => $meta_key, 'compare' => 'EXISTS' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- limits an index job, as Bricks' own providers do.
		}
		return $args;
	}

	public function meta_exists( $exists, $post_id, $meta_key, $provider ) {
		if ( $provider !== self::KEY ) {
			return $exists;
		}
		$concept = self::concept( $meta_key );
		return $concept !== '' && \MMI_Product_Content_Registry::count( (int) $post_id, $concept ) > 0;
	}

	/** One index row per concept row: the row id (or its text) as the value, its text as the label. */
	public function index_rows( $rows, $object_id, $meta_key, $provider, $object_type ) {
		if ( $provider !== self::KEY || $object_type !== 'post' ) {
			return $rows;
		}
		$concept = self::concept( $meta_key );
		if ( $concept === '' ) {
			return $rows;
		}
		foreach ( \MMI_Product_Content_Registry::get_items( (int) $object_id, $concept ) as $item ) {
			$rows[] = [
				'filter_id'            => '',
				'object_id'            => (int) $object_id,
				'object_type'          => 'post',
				'filter_value'         => $item['id'] !== '' ? $item['id'] : $item['text'],
				'filter_value_display' => $item['text'],
				'filter_value_id'      => 0,
				'filter_value_parent'  => 0,
			];
		}
		return $rows;
	}

	/** Match each selected value as a quoted string inside the canonical JSON list. */
	public function meta_query( $meta_query, $filter, $provider, $query_id ) {
		if ( $provider !== self::KEY ) {
			return $meta_query;
		}
		$settings = (array) ( $filter['settings'] ?? [] );
		$concept  = self::concept( $settings['customFieldKey'] ?? '' );
		$store    = $concept !== '' ? ( \MMI_Product_Content_Registry::CONCEPTS[ $concept ]['store'] ?? [] ) : [];
		if ( ! $store || ! in_array( $store[1], self::LIST_SHAPES, true ) ) {
			return $meta_query;
		}
		$values = array_values( array_filter( array_map( 'strval', (array) ( $filter['value'] ?? [] ) ), 'strlen' ) );
		if ( ! $values ) {
			return [];
		}
		$clauses = array_map(
			static fn( $value ) => [
				'key'     => $store[0],
				'value'   => wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'compare' => 'LIKE',
			],
			$values
		);
		$clauses['relation'] = strtoupper( (string) ( $settings['filterMultiLogic'] ?? 'OR' ) ) === 'AND' ? 'AND' : 'OR';
		return $clauses;
	}

	/** A product's first `_mmi_*` value is an add, which Bricks doesn't reindex on its own. */
	public function queue_reindex( $meta_id, $post_id, $meta_key ) {
		if ( strpos( (string) $meta_key, '_mmi_' ) !== 0 || get_post_type( (int) $post_id ) !== 'product' ) {
			return;
		}
		if ( ! $this->pending ) {
			add_action( 'shutdown', [ $this, 'reindex' ] );
		}
		$this->pending[ (int) $post_id ] = true;
	}

	public function reindex() {
		if ( ! class_exists( '\Bricks\Query_Filters' ) ) {
			return;
		}
		foreach ( array_keys( $this->pending ) as $post_id ) {
			\Bricks\Query_Filters::get_instance()->index_post( $post_id );
		}
		$this->pending = [];
	}
}
