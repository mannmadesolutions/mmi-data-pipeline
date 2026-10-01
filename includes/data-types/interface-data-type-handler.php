<?php
/**
 * Data Type Handler Interface
 *
 * One implementation per exportable/importable WP/WC entity type (product,
 * order, customer, coupon, post, page, cpt:{slug}, taxonomy:{slug}, comment,
 * user). The registry (class-data-type-registry.php) looks handlers up by
 * their type key; the exporter/importer orchestrators drive every data type
 * through this same interface so adding a new type never requires touching
 * the core engine.
 *
 * @package MannMade\DataPipeline\DataTypes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface MMI_Data_Type_Handler {

    /**
     * Stable machine key, e.g. 'product', 'order', 'cpt:book', 'taxonomy:product_cat'.
     */
    public function get_type_key(): string;

    /**
     * Human label for the data-type picker UI, e.g. "WooCommerce Orders".
     */
    public function get_label(): string;

    /**
     * Field schema for the mapping UI + preview columns.
     *
     * @return array<string, array{label:string,type:string,group:string,required:bool}>
     */
    public function get_field_schema(): array;

    /**
     * The field_schema() key that uniquely identifies a record (e.g. 'ID',
     * 'term_id', 'comment_ID'). Lets generic UI (export preview's default
     * visible column) single out the identifier without guessing per type.
     */
    public function get_id_field(): string;

    /**
     * The wp-admin edit-screen URL for a given record ID (e.g. post.php,
     * user-edit.php, term.php — whichever this type uses). Lets generic UI
     * (export preview's clickable ID/title cells) link out without knowing
     * per-type admin routing.
     */
    public function get_edit_url( $id ): string;

    /**
     * EXPORT: query records matching the profile's scope/filter configuration.
     * Returns raw native objects (WC_Order[], WP_Post[], WP_Term[], etc.) —
     * intentionally not pre-flattened, so resolve_record() can pull fields
     * lazily.
     *
     * @param array $scope  Profile's scope/filter config (shape is per-handler).
     * @param int   $limit
     * @param int   $offset
     * @return array{records: array<int, mixed>, total: int}
     */
    public function query_records( array $scope, int $limit, int $offset ): array;

    /**
     * EXPORT: flatten one native record into [field_key => scalar/array value]
     * per this handler's field schema.
     *
     * @param mixed $record One item from query_records()['records'].
     * @return array<string, mixed>
     */
    public function resolve_record( $record ): array;

    /**
     * IMPORT: create-or-update a record from flat field values.
     *
     * @param array    $data        Flat field values keyed by this handler's field schema keys.
     * @param int|null $existing_id Null for pure-create; set when a prior lookup matched.
     * @return array{success:bool, id:int, message:string, action:string}
     */
    public function upsert_record( array $data, ?int $existing_id ): array;

    /**
     * IMPORT: find an existing record ID by the profile's configured primary-key field.
     *
     * @param string $primary_key_field
     * @param mixed  $primary_value
     * @return int|null
     */
    public function find_existing_id( string $primary_key_field, $primary_value ): ?int;

    /**
     * Capability required to read/write this data type (for the AJAX gate).
     */
    public function required_capability(): string;
}
