<?php
/**
 * Product CRUD Manager
 *
 * Create/update WooCommerce products from mapped field data, plus the
 * category/image side-effects that go with them. Extracted from
 * MMI_Dynamic_Product_Importer as part of the god-class decomposition —
 * this collaborator owns nothing but WooCommerce product mutation; the
 * importer still owns $stats/$log_entries and decides what to do with the
 * deltas this class returns.
 *
 * @package MannMade\DataPipeline\Importers
 */

namespace MannMade\DataPipeline\Importers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Product_CRUD_Manager {

    /** @var array */
    protected $import_rules;

    /** @var callable */
    protected $logger;

    /**
     * Field keys that apply_fields_to_product() deliberately treats as a
     * no-op on the WC product object (see inline comments there for why) —
     * the dirty-check loop in update_product() must skip these too, since
     * comparing them would never reflect what actually gets written.
     */
    private const NOOP_FIELDS = [ 'price', '_price', 'product_brand' ];

    /**
     * @param array    $import_rules  From MMI_Dynamic_Product_Importer::load_configuration().
     * @param callable $logger        function(string $message, string $type = 'info'): void
     */
    public function __construct( array $import_rules, callable $logger ) {
        $this->import_rules = $import_rules;
        $this->logger       = $logger;
    }

    private function log( string $message, string $type = 'info' ): void {
        ( $this->logger )( $message, $type );
    }

    /**
     * Create a new product from mapped data.
     *
     * @param array $data
     * @return array{product_id:int, stock_updated_count:int}
     */
    public function create_product( array $data ): array {
        $product = new \WC_Product_Simple();

        $stock_updated_count = $this->apply_fields_to_product( $product, $data );

        $product_id = $product->save();
        update_post_meta( (int) $product_id, '_mmi_pipeline_updated_at', current_time( 'mysql' ) );

        if ( $this->import_rules['image_sync'] && ! empty( $data['images'] ) ) {
            $this->set_product_images( $product_id, $data['images'] );
        }

        return [ 'product_id' => (int) $product_id, 'stock_updated_count' => $stock_updated_count ];
    }

    /**
     * Update an existing product from mapped data.
     *
     * Decides "unchanged" via an explicit per-field semantic comparison
     * (MMI_Pipeline_Field_Resolver::values_are_equal()) rather than trusting
     * WooCommerce's own $product->get_changes() dirty-tracking, which is known
     * to false-positive on some fields (e.g. set_date_on_sale_from() always
     * reports a change even when given an identical timestamp already stored).
     *
     * @param int    $product_id
     * @param array  $data
     * @param array<string,string> $field_types Optional field name => mapping
     *   'type' (e.g. 'boolean', 'datetime'), so boolean/date fields compare
     *   correctly instead of by raw string identity — see
     *   MMI_Pipeline_Field_Resolver::values_are_equal()'s own docs for why
     *   that matters (WooCommerce stores _virtual/_downloadable/etc. as
     *   'yes'/'no', not '1'/'0'). Omitting this for a caller with no type
     *   metadata handy just falls back to values_are_equal()'s untyped
     *   comparison, same as before this parameter existed.
     * @return array{status:string, stock_updated_count:int}  status: 'updated'|'unchanged'|'failed'
     */
    public function update_product( int $product_id, array $data, array $field_types = [] ): array {
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return [ 'status' => 'failed', 'stock_updated_count' => 0 ];
        }

        // Fields locked on this product (MMI_Pipeline_Field_Locks) are dropped
        // before both the dirty check and the write — including 'images'
        // when the main image is locked.
        $data = \MMI_Pipeline_Field_Locks::filter_data( $data, \MMI_Pipeline_Field_Locks::get( $product_id ) );

        $has_changes = false;
        foreach ( $data as $field => $new_value ) {
            if ( in_array( $field, self::NOOP_FIELDS, true ) ) {
                continue;
            }
            $old_value = $this->get_product_field( $product, (string) $field );
            if ( $old_value === null ) {
                // Field not comparable via get_product_field() (e.g. an array-valued
                // category list) — fall back to assuming it may have changed rather
                // than silently skipping the save.
                $has_changes = true;
                break;
            }
            if ( ! \MMI_Pipeline_Field_Resolver::values_are_equal( $old_value, $new_value, $field_types[ $field ] ?? '' ) ) {
                $has_changes = true;
                break;
            }
        }

        if ( ! $has_changes ) {
            return [ 'status' => 'unchanged', 'stock_updated_count' => 0 ];
        }

        $stock_updated_count = $this->apply_fields_to_product( $product, $data );
        $product->save();
        update_post_meta( (int) $product_id, '_mmi_pipeline_updated_at', current_time( 'mysql' ) );

        if ( $this->import_rules['image_sync'] && ! empty( $data['images'] ) ) {
            $this->set_product_images( $product_id, $data['images'] );
        }

        return [ 'status' => 'updated', 'stock_updated_count' => $stock_updated_count ];
    }

    /**
     * Read a product's current value for a mapped field, mirroring the field
     * names apply_fields_to_product() switches on. Returns null for fields
     * that aren't meaningfully comparable as a scalar (categories) — callers
     * treat null as "assume changed" rather than risk a false "unchanged".
     */
    public function get_product_field( \WC_Product $product, string $field ): ?string {
        switch ( $field ) {
            case 'name':
            case 'post_title':
                return (string) $product->get_name();

            case 'description':
            case 'post_content':
                return (string) $product->get_description();

            case 'short_description':
            case 'post_excerpt':
                return (string) $product->get_short_description();

            case 'sku':
            case '_sku':
                return (string) $product->get_sku();

            case 'regular_price':
            case '_regular_price':
                return (string) $product->get_regular_price();

            case 'sale_price':
            case '_sale_price':
                return (string) $product->get_sale_price();

            case '_sale_price_dates_from':
                $ts = $product->get_date_on_sale_from();
                return $ts ? $ts->date( 'Y-m-d H:i:s' ) : '';

            case '_sale_price_dates_to':
                $ts = $product->get_date_on_sale_to();
                return $ts ? $ts->date( 'Y-m-d H:i:s' ) : '';

            case 'stock_quantity':
            case '_stock':
                return (string) $product->get_stock_quantity();

            case 'stock_status':
            case '_stock_status':
                return (string) $product->get_stock_status();

            case 'weight':
            case '_weight':
                return (string) $product->get_weight();

            case 'length':
            case '_length':
                return (string) $product->get_length();

            case 'width':
            case '_width':
                return (string) $product->get_width();

            case 'height':
            case '_height':
                return (string) $product->get_height();

            case 'categories':
            case 'product_cat':
            case 'product_cat_ids':
            case 'tags':
            case 'product_tag':
            case 'product_tag_ids':
            case 'images':
                // Array-valued (or, for 'images', assembled fresh from two
                // other mapped fields every run) — not comparable as a
                // scalar string. Falls back to "assume changed" in
                // update_product()'s dirty check, same as categories/tags —
                // download_and_attach_image() already skips re-downloading
                // an image already attached to this URL, so a real no-op
                // run stays cheap even though this always re-checks.
                return null;

            default:
                return (string) $product->get_meta( $field );
        }
    }

    /**
     * Apply mapped fields to a product object (setters only — does not save).
     *
     * @return int  Count of stock-related setter calls (mirrors the prior
     *              double-increment-eligible behavior when both stock_quantity
     *              and stock_status are mapped for the same item).
     */
    public function apply_fields_to_product( $product, array $data ): int {
        $stock_updated_count = 0;

        foreach ( $data as $field => $value ) {
            switch ( $field ) {
                case 'name':
                case 'post_title':
                    $product->set_name( $value );
                    break;

                case 'description':
                case 'post_content':
                    $product->set_description( $value );
                    break;

                case 'short_description':
                case 'post_excerpt':
                    $product->set_short_description( $value );
                    break;

                case 'sku':
                case '_sku':
                    $product->set_sku( $value );
                    break;

                case 'regular_price':
                case '_regular_price':
                    if ( $this->import_rules['price_update'] ) {
                        $product->set_regular_price( $value );
                    }
                    break;

                case 'sale_price':
                case '_sale_price':
                    if ( $this->import_rules['price_update'] ) {
                        $product->set_sale_price( $value );
                    }
                    break;

                case 'price':
                case '_price':
                    // Deliberately a no-op. "_price" is WooCommerce's own computed
                    // effective price — not an independent source field. Let
                    // set_regular_price()/set_sale_price() above drive this instead.
                    break;

                case '_sale_price_dates_from':
                    if ( $this->import_rules['price_update'] ) {
                        $product->set_date_on_sale_from( $value );
                    }
                    break;

                case '_sale_price_dates_to':
                    if ( $this->import_rules['price_update'] ) {
                        $product->set_date_on_sale_to( $value );
                    }
                    break;

                case 'stock_quantity':
                case '_stock':
                    if ( $this->import_rules['stock_update'] ) {
                        $product->set_manage_stock( true );
                        $product->set_stock_quantity( intval( $value ) );
                        $stock_updated_count++;
                    }
                    break;

                case 'stock_status':
                case '_stock_status':
                    if ( $this->import_rules['stock_update'] ) {
                        $product->set_stock_status( $value );
                        $stock_updated_count++;
                    }
                    break;

                case 'virtual':
                case '_virtual':
                    // No case existed here before — a constant/feed value of
                    // '1' fell to the default branch below and was written
                    // as a literal '1' postmeta via update_meta_data(),
                    // bypassing WC's own 'yes'/'no' convention for this meta
                    // key entirely (WC_Product::set_virtual() is what
                    // actually normalizes to that). filter_var(...,
                    // FILTER_VALIDATE_BOOLEAN) mirrors the manual-import path
                    // (class-product-import-worker.php's set_product_field())
                    // exactly, so a constant '1'/'yes'/true all resolve the
                    // same way regardless of which import path ran.
                    $product->set_virtual( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) );
                    break;

                case 'downloadable':
                case '_downloadable':
                    $product->set_downloadable( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) );
                    break;

                case 'manage_stock':
                case '_manage_stock':
                    // _stock's own case above already forces manage_stock on
                    // whenever a real quantity is mapped — this only matters
                    // when _manage_stock is mapped WITHOUT _stock also being
                    // mapped for the same profile.
                    if ( $this->import_rules['stock_update'] ) {
                        $product->set_manage_stock( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) );
                    }
                    break;

                case 'weight':
                case '_weight':
                    $product->set_weight( $value );
                    break;

                case 'length':
                case '_length':
                    $product->set_length( $value );
                    break;

                case 'width':
                case '_width':
                    $product->set_width( $value );
                    break;

                case 'height':
                case '_height':
                    $product->set_height( $value );
                    break;

                case 'categories':
                case 'product_cat':
                case 'product_cat_ids':
                    // product_cat_ids is this plugin's own export re-import
                    // case (see MMI_Product_Data_Type::get_field_schema()) —
                    // map_categories() auto-detects ID/slug/name regardless
                    // of which of the three column variants supplied it.
                    if ( $this->import_rules['category_mapping'] ) {
                        $category_ids = $this->map_categories( $value );
                        // An empty resolution (blank source value, or a source whose
                        // category field never carries real data — e.g. Xchange's, see
                        // MMI_Pipeline_Field_Resolver::resolve_term_ids_smart()) must
                        // never overwrite an existing product's categories. WP All
                        // Import's own taxonomy assignment (associate_terms() /
                        // pmxi_wp_all_import_set_post_terms() in wp-all-import-pro)
                        // treats this the same way: an empty resolved-terms result
                        // preserves whatever the product already has instead of
                        // clearing it. A brand-new product has nothing to preserve,
                        // so it's still fine to leave it at WooCommerce's own
                        // "Uncategorized" default in that case.
                        if ( ! empty( $category_ids ) || ! $product->get_id() ) {
                            $product->set_category_ids( $category_ids );
                        }
                    }
                    break;

                case 'tags':
                case 'product_tag':
                case 'product_tag_ids':
                    if ( $this->import_rules['category_mapping'] ) {
                        $tag_ids = \MMI_Pipeline_Field_Resolver::resolve_term_ids_smart( 'product_tag', $value );
                        $product->set_tag_ids( $tag_ids );
                    }
                    break;

                case 'product_brand':
                    // Raw brand string — taxonomy term assignment is handled after
                    // save via Taxonomy_Mapping_Handler::apply_taxonomy_mappings()
                    // using the mapping table. Store nothing on the WC product object.
                    break;

                case 'images':
                    // Assembled by MMI_Dynamic_Product_Importer::map_product_data()
                    // from _product_image_url/_product_gallery_urls — handled by
                    // create_product()/update_product() calling set_product_images()
                    // directly (it needs the real post ID this method's caller
                    // doesn't have until after save()). Never a literal postmeta key.
                    break;

                default:
                    $product->update_meta_data( $field, $value );
                    break;
            }
        }

        return $stock_updated_count;
    }

    /**
     * Map category names to term IDs, creating missing categories.
     *
     * @param mixed $categories
     * @return int[]
     */
    public function map_categories( $categories ): array {
        if ( empty( $categories ) ) {
            return [];
        }

        // Auto-detects ID vs. slug vs. name per entry — see
        // MMI_Pipeline_Field_Resolver::resolve_term_ids_smart(). Previously
        // this only checked is_numeric() (without validating the ID actually
        // existed) or an exact name match; slug was never recognized.
        return \MMI_Pipeline_Field_Resolver::resolve_term_ids_smart( 'product_cat', $categories );
    }

    /** Owner tag this plugin stamps its own attached images with — see MMI_Media_Helper::OWNER_META_KEY. */
    const IMAGE_OWNER = 'pipeline';

    /**
     * Download/attach image URLs and set featured + gallery images.
     *
     * Default behavior is a blind "feed is truth" overwrite — a pipeline-only
     * customer (no mmi-xchange-integration) expects an image removed from
     * their source feed to also disappear from the gallery, and the pipeline
     * is the only writer touching this product's images at all in that
     * shape. Only switches to append-and-preserve when this product's
     * *current* thumbnail/gallery already contains an entry owned by a
     * different process (mmi-xchange-integration's Vendors tab) — see
     * changelog.md's "Overwrite vs append" decision. Owner-tagging itself is
     * gated on mmi-xchange-integration actually being active at all
     * (class_exists()), so a pipeline-only install never pays for or is
     * affected by any of this.
     *
     * @param int   $product_id
     * @param mixed $image_urls
     */
    public function set_product_images( int $product_id, $image_urls ): void {
        /**
         * Whether imports may set this product's pictures. A site that sources
         * product pictures elsewhere returns false, so no file is downloaded either.
         *
         * @param bool   $allow
         * @param int    $product_id
         * @param string $source 'data_pipeline' | 'xchange_vendor_media'
         */
        if ( ! apply_filters( 'mmi_import_product_images', true, $product_id, 'data_pipeline' ) ) {
            return;
        }
        if ( ! is_array( $image_urls ) ) {
            $image_urls = [ $image_urls ];
        }

        $image_ids = [];

        foreach ( $image_urls as $image_url ) {
            if ( empty( $image_url ) ) {
                continue;
            }

            $attachment_id = $this->get_attachment_by_url( $image_url );

            if ( ! $attachment_id ) {
                $attachment_id = $this->download_and_attach_image( $image_url, $product_id );
            }

            if ( $attachment_id ) {
                $image_ids[] = (int) $attachment_id;
            }
        }

        if ( empty( $image_ids ) ) {
            return;
        }

        $owner_tagging_active = class_exists( 'MMI_Xchange_Vendors' );

        if ( $owner_tagging_active ) {
            foreach ( $image_ids as $image_id ) {
                \MMI_Media_Helper::set_owner( $image_id, self::IMAGE_OWNER );
            }
        }

        $current_thumbnail_id = (int) get_post_thumbnail_id( $product_id );
        $current_gallery_raw  = (string) get_post_meta( $product_id, '_product_image_gallery', true );
        $current_gallery_ids  = $current_gallery_raw !== ''
            ? array_map( 'intval', array_filter( explode( ',', $current_gallery_raw ) ) )
            : [];

        $foreign_thumbnail_id = null;
        $foreign_gallery_ids  = [];

        if ( $owner_tagging_active ) {
            if ( $current_thumbnail_id ) {
                $thumb_owner = \MMI_Media_Helper::get_owner( $current_thumbnail_id );
                if ( $thumb_owner !== '' && $thumb_owner !== self::IMAGE_OWNER ) {
                    $foreign_thumbnail_id = $current_thumbnail_id;
                }
            }
            foreach ( $current_gallery_ids as $gallery_id ) {
                $gallery_owner = \MMI_Media_Helper::get_owner( $gallery_id );
                if ( $gallery_owner !== '' && $gallery_owner !== self::IMAGE_OWNER ) {
                    $foreign_gallery_ids[] = $gallery_id;
                }
            }
        }

        $new_thumbnail_id = array_shift( $image_ids ); // Remainder of $image_ids is now the new gallery set.

        // A foreign-owned thumbnail is never displaced — this run's own would-
        // be thumbnail becomes a gallery entry instead, matching how
        // mmi-xchange-integration's own attach_image_to_product() already
        // treats an existing thumbnail (never overwritten, only added
        // alongside).
        if ( $foreign_thumbnail_id ) {
            array_unshift( $image_ids, $new_thumbnail_id );
        } else {
            set_post_thumbnail( $product_id, $new_thumbnail_id );
        }

        $final_gallery_ids = ! empty( $foreign_gallery_ids )
            ? array_values( array_unique( array_merge( $foreign_gallery_ids, $image_ids ) ) )
            : $image_ids;

        if ( ! empty( $final_gallery_ids ) ) {
            update_post_meta( $product_id, '_product_image_gallery', implode( ',', $final_gallery_ids ) );
        } else {
            delete_post_meta( $product_id, '_product_image_gallery' );
        }

        // Orphan cleanup: this process's own previously-attached images that
        // this run is dropping (superseded by a different resolved set, or
        // the image field is now empty). Deliberately checks for this
        // process's EXACT owner tag, not merely "not foreign" — an untagged
        // image (created before ownership tracking existed, or uploaded
        // manually by a human in wp-admin) was never confirmed to be this
        // process's own, so it's left alone rather than risk deleting
        // something this process never actually attached.
        if ( ! $owner_tagging_active ) {
            return;
        }

        $previously_owned_ids = [];
        if ( $current_thumbnail_id && \MMI_Media_Helper::get_owner( $current_thumbnail_id ) === self::IMAGE_OWNER ) {
            $previously_owned_ids[] = $current_thumbnail_id;
        }
        foreach ( $current_gallery_ids as $gallery_id ) {
            if ( \MMI_Media_Helper::get_owner( $gallery_id ) === self::IMAGE_OWNER ) {
                $previously_owned_ids[] = $gallery_id;
            }
        }

        $kept_ids    = array_merge( [ $new_thumbnail_id ], $image_ids );
        $dropped_ids = array_diff( $previously_owned_ids, $kept_ids );

        foreach ( $dropped_ids as $dropped_id ) {
            \MMI_Media_Helper::maybe_delete_orphan( $dropped_id );
        }
    }

    /**
     * Sets, per field, which member of a duplicate group "wins" — i.e. whose
     * value for that one field gets copied onto the canonical product,
     * overwriting whatever the canonical currently has (or has none of).
     * Lets an admin promote any candidate to canonical while still keeping
     * the best individual data point — an image from one listing, a
     * description from another — rather than being stuck with whichever
     * fields happened to ship with the chosen canonical's own source data.
     * Supersedes an earlier, additive-only "copy this donor's images onto
     * the canonical" checkbox mechanism (image/gallery are now just two of
     * this method's fields, replaced rather than merged).
     *
     * @param  int   $canonical_id
     * @param  array $winners  field => donor product ID (from
     *                         CanonicalCandidatesController's asset field
     *                         registry: image/gallery/content/title/excerpt).
     *                         A donor equal to $canonical_id or 0/empty is a
     *                         no-op for that field — nothing to copy from.
     * @return array{fields_applied: string[]}
     */
    public static function apply_field_winners( int $canonical_id, array $winners ): array {
        $result = [ 'fields_applied' => [] ];
        if ( ! $canonical_id ) {
            return $result;
        }

        $post_update = [];
        foreach ( $winners as $field => $donor_id ) {
            $donor_id = (int) $donor_id;
            if ( $donor_id <= 0 || $donor_id === $canonical_id ) {
                continue; // Nothing to copy — either unset or "wins its own field."
            }

            switch ( $field ) {
                case 'image':
                    $thumb_id = (int) get_post_thumbnail_id( $donor_id );
                    if ( $thumb_id ) {
                        set_post_thumbnail( $canonical_id, $thumb_id );
                        $result['fields_applied'][] = 'image';
                    }
                    break;

                case 'gallery':
                    $gallery = get_post_meta( $donor_id, '_product_image_gallery', true );
                    if ( trim( (string) $gallery ) !== '' ) {
                        update_post_meta( $canonical_id, '_product_image_gallery', $gallery );
                        $result['fields_applied'][] = 'gallery';
                    }
                    break;

                case 'content':
                    $content = get_post_field( 'post_content', $donor_id );
                    if ( trim( wp_strip_all_tags( (string) $content ) ) !== '' ) {
                        $post_update['post_content'] = $content;
                        $result['fields_applied'][]  = 'content';
                    }
                    break;

                case 'title':
                    // Raw stored value, not get_the_title() — that applies
                    // WP's own "Private: "/"Protected: " display prefix for
                    // non-public statuses, which would otherwise get baked
                    // into the canonical's real post_title verbatim.
                    $title = get_post_field( 'post_title', $donor_id );
                    if ( trim( (string) $title ) !== '' ) {
                        $post_update['post_title']  = $title;
                        $result['fields_applied'][] = 'title';
                    }
                    break;

                case 'excerpt':
                    $excerpt = get_post_field( 'post_excerpt', $donor_id );
                    if ( trim( (string) $excerpt ) !== '' ) {
                        $post_update['post_excerpt'] = $excerpt;
                        $result['fields_applied'][]  = 'excerpt';
                    }
                    break;
            }
        }

        if ( ! empty( $post_update ) ) {
            $post_update['ID'] = $canonical_id;
            wp_update_post( $post_update );
        }

        return $result;
    }

    /**
     * Looks up an existing attachment by its real remote source URL, via
     * MMI_Media_Helper (mmi-hub) — never a `guid = %s` comparison, which
     * media_handle_sideload() never sets to the remote URL and so could
     * never match, the reason every re-run used to re-download and
     * re-attach every image with no dedup actually happening. See
     * changelog.md for the full investigation.
     */
    protected function get_attachment_by_url( $url ) {
        return \MMI_Media_Helper::find_by_source_url( (string) $url ) ?: false;
    }

    protected function download_and_attach_image( $url, $product_id ) {
        $id = \MMI_Media_Helper::sideload( $url, $product_id );

        if ( is_wp_error( $id ) ) {
            $this->log( "Failed to attach image: {$url} - " . $id->get_error_message(), 'warning' );
            return false;
        }

        return $id;
    }
}
