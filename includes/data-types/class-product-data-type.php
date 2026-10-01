<?php
/**
 * Product Data Type Handler
 *
 * Phase 1 target data type. Export side (query_records/resolve_record) is
 * new code. Import side (upsert_record/find_existing_id) wraps the existing
 * Product_CRUD_Manager / MMI_Pipeline_Field_Resolver collaborators that
 * already do this work for MMI_Dynamic_Product_Importer — no new product
 * mutation logic is introduced here. Note: data_type=product import
 * profiles continue to run through MMI_Dynamic_Product_Importer directly
 * (unchanged); this handler's import methods exist so the generic
 * MMI_Dynamic_Record_Importer (Phase 2+) can also target products via the
 * same interface as every other data type, without touching the existing
 * product importer.
 *
 * @package MannMade\DataPipeline\DataTypes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Product_Data_Type implements MMI_Data_Type_Handler {

    public function get_type_key(): string {
        return 'product';
    }

    public function get_label(): string {
        return __( 'WooCommerce Products', 'mmi-data-pipeline' );
    }

    /**
     * Field schema for the export mapping UI / preview columns — derived from
     * the same DEFAULTS the import side already uses, stripped of the
     * per-supplier 'source' sub-array (import-only concept).
     *
     * @return array<string, array{label:string,type:string,group:string,required:bool}>
     */
    public function get_field_schema(): array {
        $schema = [];
        foreach ( MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS as $key => $def ) {
            $schema[ $key ] = [
                'label' => $def['label'] ?? $key,
                'type'  => $def['type'] ?? 'string',
                'group' => $def['group'] ?? 'meta',
                // DEFAULTS' own 'required' means "the importer needs a source
                // mapping for this or it produces an incomplete product" (see
                // that file's docblock) — an import-validity concept, not
                // "must always be exported." _sku/_regular_price/_price are
                // required=true there for that reason alone and must stay
                // freely toggleable in the export Columns popover (this was
                // the cause of a real bug: toggling a price field off in the
                // popover got silently reverted on the next preview reload,
                // because MMI_Pipeline_Field_Schema_Resolver::merge() forces
                // enabled=true for any required=true field). Only post_title
                // doubles as both meanings — every other DEFAULTS field's
                // export-requiredness is always false regardless of its
                // import-requiredness.
                'required' => ( $key === 'post_title' ),
            ];
        }
        // Fields the import side computes/derives rather than maps directly,
        // but that are meaningful to export as their own columns.
        $schema['ID'] = [ 'label' => 'Product ID', 'type' => 'integer', 'group' => 'core', 'required' => true ];
        $schema['post_status'] = [ 'label' => 'Status', 'type' => 'string', 'group' => 'core', 'required' => false ];

        // Term-ID companions for the two legacy unprefixed taxonomy fields
        // above — same reasoning as MMI_Taxonomy_Field_Helper's ':id' fields:
        // a re-import that reassigns categories/tags from a prior export
        // needs an unambiguous value (a name can be renamed or collide; the
        // ID can't). No 'source' entry in DEFAULTS for these — there's no
        // supplier feed that sends raw category/tag IDs, only names.
        $schema['product_cat_ids'] = [ 'label' => 'Product Categories (IDs)', 'type' => 'taxonomy_ids', 'group' => 'taxonomy', 'required' => false ];
        $schema['product_tag_ids'] = [ 'label' => 'Product Tags (IDs)',      'type' => 'taxonomy_ids', 'group' => 'taxonomy', 'required' => false ];

        // Every other taxonomy registered on 'product' — brand, attribute
        // taxonomies (pa_*), and any custom ones — not just the two above,
        // which predate this and are kept as unprefixed keys for backward
        // compatibility with profiles that already reference them.
        $schema += MMI_Taxonomy_Field_Helper::schema_fields( 'product', [ 'product_cat', 'product_tag' ] );

        return $schema;
    }

    public function get_id_field(): string {
        return 'ID';
    }

    public function get_edit_url( $id ): string {
        return admin_url( 'post.php?post=' . absint( $id ) . '&action=edit' );
    }

    /**
     * @param array $scope Supports: category (term_id or slug — product_cat
     *                      shorthand, kept for backward compatibility with
     *                      profiles saved before every taxonomy got its own
     *                      filter), one tax_query clause per 'tax_{taxonomy}'
     *                      key for every other product taxonomy (brand, tags,
     *                      attributes — see export-scope-fields.php),
     *                      status ('publish'|'draft'|...), stock_status,
     *                      search (product name/SKU), and 'meta_conditions'
     *                      (array of key/operator/value rows, built into a
     *                      meta_query via MMI_Pipeline_Meta_Query_Builder).
     */
    public function query_records( array $scope, int $limit, int $offset ): array {
        $args = [
            'status' => $scope['status'] ?? 'publish',
            'limit'  => $limit,
            'offset' => $offset,
            'return' => 'objects',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ];

        if ( ! empty( $scope['category'] ) ) {
            $args['category'] = is_array( $scope['category'] ) ? $scope['category'] : [ $scope['category'] ];
        }
        if ( ! empty( $scope['stock_status'] ) ) {
            $args['stock_status'] = $scope['stock_status'];
        }
        if ( ! empty( $scope['search'] ) ) {
            $args['s'] = $scope['search'];
        }

        $tax_query = [];
        foreach ( $scope as $key => $value ) {
            if ( strpos( $key, 'tax_' ) === 0 && ! empty( $value ) ) {
                $tax_query[] = [
                    'taxonomy' => substr( $key, 4 ),
                    'field'    => 'slug',
                    'terms'    => MMI_Taxonomy_Tree_Renderer::normalize_scope_terms( $value ),
                ];
            }
        }
        if ( ! empty( $tax_query ) ) {
            if ( count( $tax_query ) > 1 ) {
                $tax_query['relation'] = 'AND';
            }
            $args['tax_query'] = $tax_query;
        }

        if ( ! empty( $scope['meta_conditions'] ) && is_array( $scope['meta_conditions'] ) ) {
            $meta_query = MMI_Pipeline_Meta_Query_Builder::build( $scope['meta_conditions'] );
            if ( ! empty( $meta_query ) ) {
                $args['meta_query'] = $meta_query;
            }
        }

        $products = wc_get_products( $args );

        $count_args = $args;
        unset( $count_args['limit'], $count_args['offset'], $count_args['return'] );
        $count_args['limit']  = -1;
        $count_args['return'] = 'ids';
        $total = count( wc_get_products( $count_args ) );

        return [ 'records' => $products, 'total' => $total ];
    }

    /**
     * @param \WC_Product $record
     */
    public function resolve_record( $record ): array {
        if ( ! $record instanceof \WC_Product ) {
            return [];
        }

        $image_id   = $record->get_image_id();
        $image_url  = $image_id ? wp_get_attachment_url( $image_id ) : '';
        $gallery_ids = $record->get_gallery_image_ids();
        $gallery_urls = array_filter( array_map( 'wp_get_attachment_url', $gallery_ids ) );

        $date_from = $record->get_date_on_sale_from();
        $date_to   = $record->get_date_on_sale_to();

        $flat = [
            'ID'                      => $record->get_id(),
            'post_status'             => $record->get_status(),
            'post_title'              => $record->get_name(),
            'post_content'            => $record->get_description(),
            '_sku'                    => $record->get_sku(),
            '_regular_price'          => $record->get_regular_price(),
            '_sale_price'             => $record->get_sale_price(),
            '_price'                  => $record->get_price(),
            '_sale_price_dates_from'  => $date_from ? $date_from->date( 'Y-m-d H:i:s' ) : '',
            '_sale_price_dates_to'    => $date_to ? $date_to->date( 'Y-m-d H:i:s' ) : '',
            '_virtual'                => $record->get_virtual() ? 1 : 0,
            '_downloadable'           => $record->get_downloadable() ? 1 : 0,
            '_stock'                  => $record->get_stock_quantity(),
            '_stock_status'           => $record->get_stock_status(),
            '_manage_stock'           => $record->get_manage_stock() ? 1 : 0,
            '_weight'                 => $record->get_weight(),
            '_length'                 => $record->get_length(),
            '_width'                  => $record->get_width(),
            '_height'                 => $record->get_height(),
            '_product_image_url'      => $image_url ?: '',
            '_product_gallery_urls'   => implode( ',', $gallery_urls ),
            'product_cat'             => implode( ', ', wp_get_post_terms( $record->get_id(), 'product_cat', [ 'fields' => 'names' ] ) ),
            'product_tag'             => implode( ', ', wp_get_post_terms( $record->get_id(), 'product_tag', [ 'fields' => 'names' ] ) ),
            'product_cat_ids'         => implode( ', ', wp_get_post_terms( $record->get_id(), 'product_cat', [ 'fields' => 'ids' ] ) ),
            'product_tag_ids'         => implode( ', ', wp_get_post_terms( $record->get_id(), 'product_tag', [ 'fields' => 'ids' ] ) ),
            '__mmi_cog'               => $record->get_meta( '__mmi_cog' ),
            '_supplier_name'          => $record->get_meta( '_supplier_name' ),
            '_supplier_id'            => $record->get_meta( '_supplier_id' ),
            '_supplier_updated_at'    => $record->get_meta( '_supplier_updated_at' ),
        ];

        // Every other taxonomy registered on 'product' (brand, pa_* attribute
        // taxonomies, custom ones) — see get_field_schema()'s matching call.
        return $flat + MMI_Taxonomy_Field_Helper::resolve_fields( $record->get_id(), 'product', [ 'product_cat', 'product_tag' ] );
    }

    /**
     * Wraps Product_CRUD_Manager — Phase 1 supports simple products only
     * (variable-product parent/variation grouping is a Phase 2+ concern once
     * MMI_Dynamic_Record_Importer starts exercising this path).
     */
    public function upsert_record( array $data, ?int $existing_id ): array {
        if ( ! class_exists( '\MannMade\DataPipeline\Importers\Product_CRUD_Manager' ) ) {
            return [ 'success' => false, 'id' => 0, 'message' => 'Product_CRUD_Manager unavailable', 'action' => 'error' ];
        }

        $import_rules = [
            'price_update'     => true,
            'stock_update'     => true,
            'category_mapping' => true,
            'image_sync'       => false,
        ];
        $logger = static function ( string $message, string $type = 'info' ): void {
            MMI_Logger::info( $message, [], 'data-pipeline', 'MMI_Product_Data_Type' );
        };
        $crud = new \MannMade\DataPipeline\Importers\Product_CRUD_Manager( $import_rules, $logger );

        if ( $existing_id ) {
            $result = $crud->update_product( $existing_id, $data );
            return [
                'success' => $result['status'] !== 'failed',
                'id'      => $existing_id,
                'message' => $result['status'],
                'action'  => $result['status'],
            ];
        }

        $result = $crud->create_product( $data );
        return [
            'success' => $result['product_id'] > 0,
            'id'      => $result['product_id'],
            'message' => $result['product_id'] > 0 ? 'created' : 'failed to create',
            'action'  => 'create',
        ];
    }

    public function find_existing_id( string $primary_key_field, $primary_value ): ?int {
        $id = MMI_Pipeline_Field_Resolver::find_product_id_by_primary_key( $primary_key_field, $primary_value );
        return $id > 0 ? $id : null;
    }

    public function required_capability(): string {
        return 'manage_options';
    }
}
