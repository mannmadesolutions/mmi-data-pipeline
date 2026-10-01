<?php
/**
 * Product Import Worker
 *
 * Single-product import logic for the AJAX/cron batch-import path: primary-key
 * lookup, create/update dispatch, field mapping + dirty-checking, distribution
 * taxonomy assignment. Extracted from ProductImportController as part of the
 * god-class decomposition.
 *
 * Stateless by design — every method takes the data/mappings/options it needs
 * as parameters rather than holding instance state, since the original methods
 * never depended on anything but their arguments and MMI_DB/MMI_Pipeline_Field_Resolver.
 *
 * @package MannMade\DataPipeline\AJAX
 */

namespace MannMade\DataPipeline\AJAX;

use MMI_DB;
use MMI_Pipeline_Field_Resolver;
use MMI_Pipeline_Field_Mapping_Defaults;
use MMI_Pipeline_Field_Locks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Product_Import_Worker {

    /**
     * Max per-(run, supplier, field) detail rows recorded for the "Field
     * changes" table's row-expand spot-check UI. The aggregate 'changed'
     * count in $field_stats stays exact regardless — only this detail list
     * is capped, matching Batch_Import_State::MAX_FAILURE_DETAILS' convention
     * for the same reason (a pathological run can't bloat storage/queries).
     */
    const MAX_FIELD_CHANGE_ITEMS = 500;

    /**
     * Import (create or update) a single product from mapped source data.
     *
     * @param array $data                Raw source item (with promo data already injected).
     * @param array $mappings            Effective field mappings.
     * @param array $options             supplier, profile, product_scope, allow_create, duplicate_strategy.
     * @param array $field_stats         Accumulator, passed by reference: [supplier][field] => [changed, unchanged].
     * @param array $field_change_counts Accumulator, passed by reference: [supplier][field] => detail rows recorded
     *                                   so far this run — caps MAX_FIELD_CHANGE_ITEMS per (run, supplier, field)
     *                                   without a COUNT query per product (see flush_change_details()). A plain
     *                                   per-call default (not by-ref) would reset to empty on every product, since
     *                                   $options is rebuilt fresh per item in ProductImportController's loop —
     *                                   this is why it's a separate parameter rather than folded into $options.
     * @return string  'imported' | 'updated' | 'unchanged' | 'skipped'
     */
    public static function import_single_product( $data, $mappings, $options = [], array &$field_stats = [], array &$field_change_counts = [] ) {
        // Use the per-supplier configured primary key (source field + WC meta key) —
        // this must match class-import-preview.php's existence check. Hardcoding
        // 'sku' / wc_get_product_id_by_sku() breaks suppliers like SkuPort whose
        // source field is 'id', and mismatches Xchange's '_mmi_supplier_sku_xchange'
        // WC key, causing the preview's "will create" count to diverge from the
        // actual created count.
        $supplier            = $options['supplier'] ?? '';
        $primary_key_source  = MMI_DB::get_primary_key( $supplier, 'source', 'id' );
        $primary_key_wc      = MMI_DB::get_primary_key( $supplier, 'wc', '_sku' );
        $primary_value       = MMI_Pipeline_Field_Resolver::get_nested_value( $data, $primary_key_source );

        if ( empty( $primary_value ) ) {
            return 'skipped';
        }

        // Pass through so update_product()/has_product_changes() can keep the
        // configured primary-key meta (e.g. _mmi_supplier_sku_xchange) in sync —
        // this is what stock overrides, COGS, and Reverb sync match against,
        // and previously relied on a separate 'sku_xchange' field mapping that
        // wrote to the wrong meta key entirely (see class-pipeline-field-mapping-defaults.php).
        $options['primary_key_wc'] = $primary_key_wc;
        $options['primary_value']  = $primary_value;

        // Check if product exists, looking up by the configured WC primary key field.
        // A caller processing many items in one pass (the real batch importer,
        // ProductImportController::process_import_batch_cron()) resolves every
        // item's product ID up front in a handful of chunked WHERE-IN queries and
        // passes the answer via 'resolved_product_id' — array_key_exists (not ??
        // or isset) because 0 is 'no existing product' and a genuinely correct,
        // meaningful pre-resolved answer, not an absent-key fallback case. A
        // caller that never batch-resolves (e.g. Import Preview's single-item
        // spot-check, which only ever imports one product per click) omits the
        // key entirely and gets the original one-item lookup, unchanged.
        $product_id = array_key_exists( 'resolved_product_id', $options )
            ? (int) $options['resolved_product_id']
            : MMI_Pipeline_Field_Resolver::find_product_id_by_primary_key( $primary_key_wc, $primary_value );

        if ( $product_id ) {
            if ( $options['duplicate_strategy'] === 'skip' ) {
                return 'skipped';
            }

            // new_only scope: never modify products that already exist in the store.
            $product_scope = $options['product_scope'] ?? 'all_products';
            if ( $product_scope === 'new_only' ) {
                return 'skipped';
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return 'skipped'; // Product ID found but product object is invalid/unavailable
            }
            // Fields this product has locked against imports (see
            // MMI_Pipeline_Field_Locks) — skipped by both the dirty check and
            // the write below, so a locked field neither changes nor keeps the
            // product perpetually "changed". Reads the meta cache
            // wc_get_product() just primed; no extra query.
            $options['locked_fields'] = MMI_Pipeline_Field_Locks::get( (int) $product_id );

            // Skip the save entirely if no mapped field values have changed.
            if ( ! self::has_product_changes( $product, $data, $mappings, $options ) ) {
                return 'unchanged';
            }
            self::update_product( $product, $data, $mappings, $options, $field_stats, $field_change_counts );
            return 'updated';
        } else {
            // Product doesn't exist yet — determine whether creation is allowed.
            // Priority: (1) profile import_mode via options['allow_create'],
            //           (2) new_only scope (always creates),
            //           (3) legacy per-profile setting key.
            $product_scope = $options['product_scope'] ?? 'all_products';
            if ( $product_scope === 'new_only' ) {
                // new_only scope always creates — that is its entire purpose.
                $allow_create = true;
            } elseif ( isset( $options['allow_create'] ) ) {
                $allow_create = (bool) $options['allow_create'];
            } else {
                // Legacy fallback: read from per-profile setting stored via the old UI toggle.
                $profile                 = $options['profile'] ?? 'default';
                $allow_create_option_key = $profile === 'default' ? 'mmi_pipeline_import_allow_create_products' : 'mmi_pipeline_import_allow_create_products_' . $profile;
                $allow_create            = MMI_DB::get_setting( $allow_create_option_key, false );
            }
            if ( ! $allow_create ) {
                return 'skipped';
            }

            // SKU conflict the user has confirmed (via the Review screen's "Skip"
            // action) is a genuine duplicate of an existing product — never retry
            // creating it. Re-checks wc_get_product_id_by_sku() live rather than
            // trusting the dismissal blindly, so a since-deleted/renamed conflicting
            // product doesn't permanently block this SKU forever. Without this,
            // WC_Product::save() below would throw "Invalid or duplicated SKU" and
            // the caller counts it as a failure instead of a clean skip.
            $conflict_sku = self::resolve_mapped_sku( $data, $mappings, $supplier );
            if ( $conflict_sku !== '' && function_exists( 'wc_get_product_id_by_sku' ) ) {
                $profile             = $options['profile'] ?? 'default';
                $dismissed_conflicts = \MMI_Import_Preview::get_dismissed_sku_conflicts( $profile );
                if ( in_array( $conflict_sku, $dismissed_conflicts, true ) && wc_get_product_id_by_sku( $conflict_sku ) ) {
                    return 'skipped';
                }
            }

            $product = new \WC_Product_Simple();
            self::update_product( $product, $data, $mappings, $options, $field_stats, $field_change_counts );

            return 'imported';
        }
    }

    /**
     * Resolve the mapped _sku value from raw source data without applying it —
     * used to pre-flight-check SKU conflicts before create (see import_single_product()).
     */
    private static function resolve_mapped_sku( $data, $mappings, $supplier ) {
        $mapping = $mappings['_sku'] ?? null;
        if ( ! $mapping ) {
            return '';
        }

        $constant_value = MMI_Pipeline_Field_Mapping_Defaults::resolve_constant( $mapping, $supplier );
        if ( $constant_value !== null ) {
            $value = $constant_value;
        } else {
            $source = is_array( $mapping['source'] ?? '' ) ? ( $mapping['source'][ $supplier ] ?? '' ) : ( $mapping['source'] ?? '' );
            if ( empty( $source ) ) {
                return '';
            }
            $value = MMI_Pipeline_Field_Resolver::get_nested_value( $data, $source );
            if ( empty( $value ) && ! empty( $mapping['default_value'] ) ) {
                $value = $mapping['default_value'];
            }
        }

        $value = MMI_Pipeline_Field_Resolver::apply_transform( $value, $mapping['transform'] ?? 'none' );
        return (string) $value;
    }

    /**
     * Update product with mapped data.
     */
    public static function update_product( $product, $data, $mappings, $options, array &$field_stats = [], array &$field_change_counts = [] ) {
        $supplier = $options['supplier'] ?? '';

        // Per-field old/new values for THIS product only, keyed by field name —
        // flushed to MMI_DB::record_field_change_item() after save() below, once
        // a real post ID exists. Powers the "Field changes" table's row-expand
        // spot-check UI (see track_field_change_with_detail()).
        $change_details = [];

        // Term IDs resolved below for non-native taxonomy fields (anything other
        // than product_cat/product_tag, e.g. "Distribution" or any other custom
        // JetEngine taxonomy) — keyed by real taxonomy slug. Applied via
        // wp_set_object_terms() after save() (a brand-new product has no post ID
        // yet here) and passed to assign_distribution_term()/
        // apply_non_native_taxonomy_aliases() so a value this product's own Field
        // Mapping entry already resolved takes priority over their fallbacks
        // instead of being silently overwritten by them.
        $pending_non_native_terms = [];

        // Raw source values behind a taxonomy resolution, keyed by sanitized
        // source field name (_mmi_src_{key}) — written post-save, below,
        // alongside $pending_non_native_terms for the same real-post-ID reason.
        // Matches the convention Taxonomy_Mapping_Handler::apply_taxonomy_mappings()
        // (the scheduled path) already writes, so a product imported manually is
        // just as visible to the "Apply All to Existing Products" batch tool as
        // one imported by the scheduled pipeline — previously this path wrote
        // no tracking meta at all.
        $pending_src_meta = [];

        // Only ever non-empty for an existing product — see import_single_product().
        $locked = $options['locked_fields'] ?? [];

        foreach ( $mappings as $field_name => $mapping ) {
            if ( MMI_Pipeline_Field_Locks::in_list( (string) $field_name, $locked ) ) {
                continue;
            }

            $constant_value = MMI_Pipeline_Field_Mapping_Defaults::resolve_constant( $mapping, $supplier );
            $is_constant    = $constant_value !== null;

            // Legacy (scalar) constants are global overrides — they apply
            // regardless of the per-supplier enabled flag. Per-source
            // constants are scoped to one supplier same as a source path, so
            // they respect 'enabled' like any other supplier-specific value.
            if ( ! $is_constant || ! MMI_Pipeline_Field_Mapping_Defaults::constant_bypasses_enabled( $mapping ) ) {
                // Respect per-supplier enabled flag for source-mapped fields.
                if ( is_array( $mapping['enabled'] ?? null ) ) {
                    if ( empty( $mapping['enabled'][ $supplier ] ) ) {
                        continue;
                    }
                } elseif ( empty( $mapping['enabled'] ) ) {
                    continue;
                }
            }

            // Taxonomy-type fields (product_cat/product_tag) are real term
            // relationships, not postmeta \u2014 route them through set_taxonomy_field()
            // (wp_set_object_terms() via WC_Product::set_category_ids()/set_tag_ids())
            // instead of the generic set_product_field()/get_meta() path below, which
            // was writing/reading a literal 'product_cat' meta key this plugin never
            // otherwise uses, silently failing to assign the real category/tag terms.
            if ( ( $mapping['type'] ?? '' ) === 'taxonomy' ) {
                // Dynamic per-taxonomy fields are named 'tax:{slug}' (see
                // MMI_Taxonomy_Field_Helper); built-ins use the taxonomy slug
                // directly as the field key.
                $real_taxonomy      = ( strpos( $field_name, 'tax:' ) === 0 ) ? substr( $field_name, 4 ) : $field_name;
                $is_native_taxonomy = in_array( $real_taxonomy, [ 'product_cat', 'product_tag' ], true );

                if ( $is_constant ) {
                    $raw_value = $constant_value;
                } else {
                    $source = is_array( $mapping['source'] ) ? ( $mapping['source'][ $supplier ] ?? '' ) : $mapping['source'];
                    if ( empty( $source ) ) {
                        continue;
                    }
                    $raw_value = MMI_Pipeline_Field_Resolver::get_nested_value( $data, $source );
                }

                if ( empty( $raw_value ) && ! empty( $mapping['default_value'] ) ) {
                    $raw_value = $mapping['default_value'];
                }

                // Only a real per-supplier source field (not a constant) has a
                // meaningful "raw value from the feed" to track — matches the
                // scheduled path's identical empty( $source_field ) gate.
                if ( ! $is_constant && ! empty( $source ) && $raw_value !== '' && $raw_value !== null ) {
                    $pending_src_meta[ str_replace( '+', '_', $source ) ] = (string) $raw_value;
                }

                $old = self::get_taxonomy_old_display( $product, $field_name, $real_taxonomy, $is_native_taxonomy );

                // Taxonomy Mapping tab aliases (coded source values → a specific WC
                // term, e.g. Xchange's master_category+sub_category codes) take
                // priority over the raw field-mapping passthrough whenever the admin
                // has built alias rows for this supplier+taxonomy — this is what
                // makes those aliases actually apply here instead of only in the
                // legacy cron importer. Falls back to the raw-value auto-detect
                // path when no alias rows exist (unchanged behavior for suppliers
                // that already feed final term names directly). Same precedence
                // for every taxonomy, native or not. Uses $real_taxonomy, not
                // $field_name — the alias table is keyed by the real taxonomy slug
                // (get_tax_mappings()/resolve_tax_mapping()'s $wc_taxonomy), so a
                // dynamic 'tax:{slug}' field name never matched any alias row here
                // before this was fixed.
                $alias_term_ids = MMI_Pipeline_Field_Resolver::resolve_taxonomy_via_alias_table( $supplier, $data, $real_taxonomy, $options['profile'] ?? 'default' );

                if ( $is_native_taxonomy ) {
                    if ( ! empty( $alias_term_ids ) ) {
                        self::set_taxonomy_field_ids( $product, $real_taxonomy, $alias_term_ids );
                        $display_value = self::terms_display_from_ids( $alias_term_ids, $real_taxonomy );
                    } else {
                        self::set_taxonomy_field( $product, $real_taxonomy, $raw_value );
                        $display_value = self::normalize_taxonomy_display_value( $raw_value );
                    }
                } else {
                    // Non-native taxonomies (e.g. "Distribution", any other custom
                    // JetEngine taxonomy) have no WC_Product CRUD prop to route
                    // through, and wp_set_object_terms() needs a real post ID —
                    // which a brand-new product doesn't have yet at this point (see
                    // apply_non_native_taxonomy_aliases()'s docblock). Queue the
                    // resolved term IDs and apply them once the product is saved,
                    // below.
                    $term_ids = ! empty( $alias_term_ids )
                        ? $alias_term_ids
                        : ( empty( $raw_value ) ? [] : MMI_Pipeline_Field_Resolver::resolve_term_ids_smart( $real_taxonomy, $raw_value, true ) );

                    if ( ! empty( $term_ids ) ) {
                        $pending_non_native_terms[ $real_taxonomy ] = $term_ids;
                        $display_value                              = self::terms_display_from_ids( $term_ids, $real_taxonomy );
                    } else {
                        $display_value = '';
                    }
                }

                self::track_field_change_with_detail( $field_stats, $change_details, $supplier, $field_name, $old, $display_value );
                continue;
            }

            // Apply constant value if configured
            if ( $is_constant ) {
                $value = MMI_Pipeline_Field_Resolver::apply_transform( $constant_value, $mapping['transform'] ?? 'none' );
                $old   = self::get_product_field( $product, $field_name );
                self::set_product_field( $product, $field_name, $value );
                self::track_field_change_with_detail( $field_stats, $change_details, $supplier, $field_name, $old, (string) $value );
                continue;
            }

            // Extract value based on supplier-specific source path
            $source = is_array( $mapping['source'] ) ? ( $mapping['source'][ $supplier ] ?? '' ) : $mapping['source'];
            if ( empty( $source ) ) {
                continue;
            }

            $value = MMI_Pipeline_Field_Resolver::get_nested_value( $data, $source );

            if ( empty( $value ) && ! empty( $mapping['default_value'] ) ) {
                $value = $mapping['default_value'];
            }

            $value = MMI_Pipeline_Field_Resolver::apply_transform( $value, $mapping['transform'] );

            $old = self::get_product_field( $product, $field_name );
            self::set_product_field( $product, $field_name, $value );
            self::track_field_change_with_detail( $field_stats, $change_details, $supplier, $field_name, $old, (string) $value );
        }

        // Apply informational product attributes (Attributes wizard step) — set
        // before save() alongside every other product-object mutation in this
        // method. $options['attribute_manager'] is a Variable_Product_Manager
        // instance built once per batch (see ProductImportController), null
        // when the profile has no attribute config configured/enabled.
        if ( ! empty( $options['attribute_manager'] ) ) {
            $options['attribute_manager']->apply_product_attributes( $product, $data );
        }

        // Keep the configured primary-key meta (e.g. _mmi_supplier_sku_xchange) in
        // sync with the matched/created product — single source of truth is the
        // Primary Keys panel, not a separate field mapping.
        if ( ! empty( $options['primary_key_wc'] ) && ! empty( $options['primary_value'] ) ) {
            $product->update_meta_data( $options['primary_key_wc'], (string) $options['primary_value'] );
        }

        $product->update_meta_data( '_supplier_name', $supplier );
        $product->update_meta_data( '_supplier_updated_at', current_time( 'mysql' ) );
        $product->save();

        // Flush this product's changed-field details, now that a real post ID
        // exists — powers the "Field changes" table's row-expand spot-check UI.
        // Only when a run_id was passed (Import Preview's single-item spot-check
        // omits it, and has nothing worth recording detail rows for anyway).
        if ( ! empty( $change_details ) && ! empty( $options['run_id'] ) ) {
            self::flush_change_details( (int) $options['run_id'], $supplier, (int) $product->get_id(), (string) ( $options['primary_value'] ?? '' ), (string) $product->get_name(), $change_details, $field_change_counts );
        }

        // Apply non-native taxonomy values (e.g. "Distribution", other custom
        // JetEngine taxonomies) resolved from this product's own Field Mapping
        // entries above — deferred until now because wp_set_object_terms() needs
        // a real post ID, which a brand-new product only gets from save() above.
        foreach ( $pending_non_native_terms as $mmi_pending_taxonomy => $mmi_pending_term_ids ) {
            wp_set_object_terms( $product->get_id(), $mmi_pending_term_ids, $mmi_pending_taxonomy );
        }

        // Same real-post-ID constraint as above — see $pending_src_meta's own
        // comment near the top of this method for why this exists.
        foreach ( $pending_src_meta as $mmi_src_key => $mmi_src_value ) {
            update_post_meta( $product->get_id(), '_mmi_src_' . $mmi_src_key, $mmi_src_value );
        }

        // Auto-assign distribution taxonomy term based on supplier — moved after
        // save() (was previously called pre-save, where a brand-new product's
        // get_id() is still 0, silently no-op'ing wp_set_object_terms() for
        // every newly-created product from a supplier with a configured
        // distribution_term_slug). Skips when this product's own Field Mapping
        // entry for Distribution already resolved a value above, so an admin's
        // explicit per-product mapping takes priority over this supplier-wide
        // default rather than being silently overwritten by it.
        // A locked taxonomy counts as "already handled" for both fallbacks
        // below — neither the supplier-wide Distribution default nor the
        // alias table may assign terms the product has locked.
        $skip_taxonomies = array_merge( array_keys( $pending_non_native_terms ), $locked );

        self::assign_distribution_term( $product, $supplier, $skip_taxonomies );

        // Taxonomy Mapping aliases for taxonomies with no WC_Product CRUD prop
        // (e.g. product_brand — there's no set_brand_ids()) can only be applied
        // via wp_set_object_terms() once the product has a real post ID, so this
        // runs after save() rather than alongside product_cat/product_tag above.
        // Taxonomies already resolved via this product's own Field Mapping entry
        // (which already tries the same alias table first, see above) are
        // skipped here to avoid redundant/conflicting re-application.
        self::apply_non_native_taxonomy_aliases( $product->get_id(), $data, $supplier, $mappings, $options['profile'] ?? 'default', $field_stats, $skip_taxonomies );
    }

    /**
     * Non-native taxonomies (i.e. not product_cat/product_tag, which already
     * have WC_Product CRUD props) with saved Taxonomy Mapping alias rows for
     * this supplier — filtered by each taxonomy's own Field Mapping 'enabled'
     * state where one exists (currently only product_brand; any other
     * alias-only taxonomy has no Field Mapping row at all and always runs,
     * matching pre-existing behavior). Shared by
     * apply_non_native_taxonomy_aliases() and has_product_changes()'s backfill
     * check so a profile that disables Brand skips both actual assignment AND
     * "did anything change" detection for it identically.
     */
    private static function get_active_alias_taxonomies( string $supplier, array $mappings ): array {
        return array_unique( array_filter(
            array_column( MMI_DB::get_tax_mappings( $supplier, '' ), 'wc_taxonomy' ),
            static function ( $tax ) use ( $mappings ) {
                if ( ! taxonomy_exists( $tax ) || in_array( $tax, [ 'product_cat', 'product_tag' ], true ) ) {
                    return false;
                }
                // Dynamic per-taxonomy Field Mapping rows are keyed 'tax:{slug}'
                // (see MMI_Taxonomy_Field_Helper), not the bare taxonomy slug —
                // check both so a disabled Distribution/custom-taxonomy row here
                // actually suppresses alias-table assignment instead of the
                // lookup missing every dynamic entry and always treating it as
                // active regardless of its own 'enabled' toggle.
                $mapping_key = $mappings[ $tax ] ?? $mappings[ 'tax:' . $tax ] ?? null;
                return $mapping_key === null || ( $mapping_key['enabled'] ?? true );
            }
        ) );
    }

    /**
     * Assign terms for any taxonomy — other than product_cat/product_tag,
     * which are already handled via WC_Product's own CRUD props above — that
     * has saved Taxonomy Mapping alias rows for this supplier (e.g.
     * product_brand). Driven entirely by what's configured on the Taxonomy
     * Mapping tab, independent of the Field Mapping panel, since fields like
     * product_brand have no Field Mapping entry at all.
     *
     * @param string[] $already_applied_taxonomies Real taxonomy slugs this
     *   product's own Field Mapping entry already resolved and applied (see
     *   $pending_non_native_terms in update_product()) — skipped here so an
     *   admin's explicit per-field mapping isn't redundantly re-resolved
     *   against the alias table a second time.
     */
    private static function apply_non_native_taxonomy_aliases( int $product_id, array $data, string $supplier, array $mappings, string $profile_id, array &$field_stats, array $already_applied_taxonomies = [] ): void {
        if ( ! $product_id ) {
            return;
        }

        foreach ( self::get_active_alias_taxonomies( $supplier, $mappings ) as $taxonomy ) {
            if ( in_array( $taxonomy, $already_applied_taxonomies, true ) ) {
                continue;
            }

            $term_ids = MMI_Pipeline_Field_Resolver::resolve_taxonomy_via_alias_table( $supplier, $data, $taxonomy, $profile_id );
            if ( empty( $term_ids ) ) {
                continue;
            }

            $old = self::terms_display_from_ids( wp_get_object_terms( $product_id, $taxonomy, [ 'fields' => 'ids' ] ) ?: [], $taxonomy );
            wp_set_object_terms( $product_id, $term_ids, $taxonomy );
            self::track_field_change( $field_stats, $supplier, $taxonomy, $old, self::terms_display_from_ids( $term_ids, $taxonomy ) );
        }
    }

    /**
     * Comma-separated term names for a list of term IDs — used for change-log
     * display when terms were resolved via the alias table (IDs already known,
     * no need to re-run normalize_taxonomy_display_value()'s name-guessing).
     */
    private static function terms_display_from_ids( array $term_ids, string $taxonomy ): string {
        $names = array_map( static function ( $term_id ) use ( $taxonomy ) {
            $term = get_term( $term_id, $taxonomy );
            return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
        }, $term_ids );
        return implode( ', ', array_filter( $names ) );
    }

    /**
     * Assign distribution taxonomy term based on supplier — the fallback
     * default when this product's own Field Mapping entry for Distribution
     * didn't already resolve an explicit value (see $already_applied_taxonomies).
     *
     * @param string[] $already_applied_taxonomies Real taxonomy slugs already
     *   resolved+applied via this product's own Field Mapping entry (see
     *   $pending_non_native_terms in update_product()). When 'distribution' is
     *   among them, an admin explicitly configured a source/constant for it —
     *   that value takes priority and this supplier-wide default is skipped
     *   rather than silently overwriting it.
     */
    private static function assign_distribution_term( $product, $supplier, array $already_applied_taxonomies = [] ) {
        if ( in_array( 'distribution', $already_applied_taxonomies, true ) ) {
            return;
        }

        // Distribution taxonomy slug is configured per-supplier in wp_mmi_data_sources
        // (Data Sources admin), not hardcoded — supports any number of suppliers.
        global $wpdb;
        $source = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT supplier_name, distribution_term_slug FROM {$wpdb->prefix}mmi_data_sources WHERE supplier_id = %s",
                $supplier
            ),
            ARRAY_A
        );

        $term_slug = $source['distribution_term_slug'] ?? null;

        if ( ! $term_slug ) {
            return;
        }

        // Get or create the term
        $term = get_term_by( 'slug', $term_slug, 'distribution' );

        if ( ! $term ) {
            // Create the term if it doesn't exist, named after the configured supplier.
            $term_result = wp_insert_term(
                $source['supplier_name'] ?: ucfirst( $supplier ),
                'distribution',
                [ 'slug' => $term_slug ]
            );

            if ( ! is_wp_error( $term_result ) ) {
                $term = get_term( $term_result['term_id'], 'distribution' );
            }
        }

        if ( $term && ! is_wp_error( $term ) ) {
            // Set the distribution taxonomy term (replace any existing)
            wp_set_object_terms( $product->get_id(), [ $term->term_id ], 'distribution', false );
        }
    }

    /**
     * Read the current value of a product field (before overwrite) for diff tracking.
     */
    public static function get_product_field( $product, string $field_name ): string {
        switch ( $field_name ) {
            case 'post_title':     return (string) $product->get_name();
            case 'post_content':   return (string) $product->get_description();
            case '_sku':           return (string) $product->get_sku();
            case '_regular_price': return (string) $product->get_regular_price();
            case '_sale_price':    return (string) $product->get_sale_price();
            case '_price':         return (string) $product->get_price();
            case '_stock':         return (string) $product->get_stock_quantity();
            case '_stock_status':  return (string) $product->get_stock_status();
            case '_manage_stock':  return (string) $product->get_manage_stock();
            case '_virtual':       return $product->is_virtual() ? '1' : '0';
            case '_downloadable':  return $product->is_downloadable() ? '1' : '0';
            case '_weight':        return (string) $product->get_weight();
            case '_length':        return (string) $product->get_length();
            case '_width':         return (string) $product->get_width();
            case '_height':        return (string) $product->get_height();
            // Categories/tags are a taxonomy, not postmeta — falling through to
            // get_meta() below always read '' (this plugin never writes a literal
            // 'product_cat'/'product_tag' meta key), which made has_product_changes()
            // permanently report "unchanged" for any product whose real assigned
            // terms differed from the feed, and silently skipped the actual update.
            case 'product_cat':
            case 'product_tag':
                return self::get_product_terms_display( $product->get_id(), $field_name );
            default:               return (string) $product->get_meta( $field_name );
        }
    }

    /**
     * Comma-separated term names currently assigned to a product for a given
     * taxonomy — mirrors MMI_Import_Preview::get_product_terms_display() so the
     * actual-update path's "current value" agrees with what the preview table
     * already shows the user.
     */
    public static function get_product_terms_display( int $product_id, string $taxonomy ): string {
        if ( ! $product_id ) {
            return '';
        }
        $terms = get_the_terms( $product_id, $taxonomy );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return '';
        }
        return implode( ', ', wp_list_pluck( $terms, 'name' ) );
    }

    /**
     * Current-value reader for a taxonomy-type field, used for diff tracking and
     * change detection alike. get_product_field()'s switch only special-cases
     * product_cat/product_tag (real WC_Product CRUD props); any other taxonomy
     * (Distribution, other custom JetEngine taxonomies) has no meta key backing
     * it and never did, so falling through to get_product_field()'s get_meta()
     * default there always read '' — read the real current terms directly
     * instead.
     */
    private static function get_taxonomy_old_display( $product, string $field_name, string $real_taxonomy, bool $is_native_taxonomy ): string {
        return $is_native_taxonomy
            ? self::get_product_field( $product, $field_name )
            : self::get_product_terms_display( $product->get_id(), $real_taxonomy );
    }

    /**
     * Flatten a taxonomy field's raw resolved source value (array of name
     * strings, or array of {name,...}-shaped rows, per supplier feed format)
     * into the same comma-separated display string get_product_terms_display()
     * returns for the CURRENT side — mirrors
     * MMI_Import_Preview::normalize_taxonomy_display_value() so change-detection
     * isn't skewed by comparing an array (or its "Array" string cast) to a string.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalize_taxonomy_display_value( $value ): string {
        if ( ! is_array( $value ) ) {
            return (string) $value;
        }

        $parts = array_map( function ( $entry ) {
            if ( is_array( $entry ) ) {
                $entry = $entry['name'] ?? $entry['title'] ?? $entry['label'] ?? ( is_scalar( reset( $entry ) ) ? reset( $entry ) : '' );
            } elseif ( is_object( $entry ) ) {
                $entry = $entry->name ?? $entry->title ?? $entry->label ?? '';
            }
            return is_scalar( $entry ) ? trim( (string) $entry ) : '';
        }, $value );

        return implode( ', ', array_filter( $parts, static fn( $p ) => $p !== '' ) );
    }

    /**
     * Assign real taxonomy terms to a product for a mapped taxonomy field
     * (product_cat/product_tag), auto-detecting whether each entry is a term
     * ID, slug, or name via MMI_Pipeline_Field_Resolver::resolve_term_ids_smart() —
     * same resolution the legacy Product_CRUD_Manager::map_categories() path
     * uses. set_category_ids()/set_tag_ids() are WC_Product CRUD props applied
     * via wp_set_object_terms() on $product->save(), so this is safe to call
     * before a new product has been saved/has an ID yet.
     */
    public static function set_taxonomy_field( $product, string $taxonomy, $raw_value ): void {
        $term_ids = MMI_Pipeline_Field_Resolver::resolve_term_ids_smart( $taxonomy, $raw_value, true );
        self::set_taxonomy_field_ids( $product, $taxonomy, $term_ids );
    }

    /**
     * Same as set_taxonomy_field() but for already-resolved term IDs (e.g.
     * from the Taxonomy Mapping alias table) — no ID/slug/name auto-detection.
     */
    public static function set_taxonomy_field_ids( $product, string $taxonomy, array $term_ids ): void {
        if ( $taxonomy === 'product_cat' ) {
            $product->set_category_ids( $term_ids );
        } elseif ( $taxonomy === 'product_tag' ) {
            $product->set_tag_ids( $term_ids );
        }
    }

    /**
     * Check whether any mapped field value would change for an existing product.
     * Returns true if at least one field differs (save is needed), false if all
     * values are semantically equal (save can be skipped).
     */
    public static function has_product_changes( $product, $data, $mappings, $options ): bool {
        $supplier = $options['supplier'] ?? '';
        $locked   = $options['locked_fields'] ?? [];
        foreach ( $mappings as $field_name => $mapping ) {
            if ( MMI_Pipeline_Field_Locks::in_list( (string) $field_name, $locked ) ) {
                continue;
            }
            // Respect enabled flag for constants and source fields alike
            if ( is_array( $mapping['enabled'] ?? null ) ) {
                if ( empty( $mapping['enabled'][ $supplier ] ) ) {
                    continue;
                }
            } elseif ( empty( $mapping['enabled'] ) ) {
                continue;
            }
            $is_taxonomy = ( $mapping['type'] ?? '' ) === 'taxonomy';
            // Dynamic per-taxonomy fields are named 'tax:{slug}' (see
            // MMI_Taxonomy_Field_Helper); built-ins use the taxonomy slug
            // directly as the field key — same derivation as update_product().
            $real_taxonomy      = $is_taxonomy ? ( ( strpos( $field_name, 'tax:' ) === 0 ) ? substr( $field_name, 4 ) : $field_name ) : '';
            $is_native_taxonomy = $is_taxonomy && in_array( $real_taxonomy, [ 'product_cat', 'product_tag' ], true );

            $constant_value = MMI_Pipeline_Field_Mapping_Defaults::resolve_constant( $mapping, $supplier );
            if ( $constant_value !== null ) {
                $raw_new_value = $constant_value;
                if ( $is_taxonomy ) {
                    $new_value = self::normalize_taxonomy_display_value( $raw_new_value );
                    $old_value = self::get_taxonomy_old_display( $product, $field_name, $real_taxonomy, $is_native_taxonomy );
                } else {
                    $new_value = (string) MMI_Pipeline_Field_Resolver::apply_transform( $raw_new_value, $mapping['transform'] ?? 'none' );
                    $old_value = self::get_product_field( $product, $field_name );
                }
                if ( ! MMI_Pipeline_Field_Resolver::values_are_equal( $old_value, $new_value, $mapping['type'] ?? '' ) ) {
                    return true;
                }
                continue;
            }
            $source = is_array( $mapping['source'] ?? null )
                ? ( $mapping['source'][ $supplier ] ?? '' )
                : ( $mapping['source'] ?? '' );
            if ( empty( $source ) ) {
                continue;
            }
            $new_value = MMI_Pipeline_Field_Resolver::get_nested_value( $data, $source );
            if ( empty( $new_value ) && ! empty( $mapping['default_value'] ) ) {
                $new_value = $mapping['default_value'];
            }
            if ( $is_taxonomy ) {
                // $real_taxonomy, not $field_name — see the matching fix/comment
                // in update_product(); the alias table is keyed by the real
                // taxonomy slug.
                $alias_term_ids = MMI_Pipeline_Field_Resolver::resolve_taxonomy_via_alias_table( $supplier, $data, $real_taxonomy, $options['profile'] ?? 'default' );
                $new_value      = ! empty( $alias_term_ids )
                    ? self::terms_display_from_ids( $alias_term_ids, $real_taxonomy )
                    : self::normalize_taxonomy_display_value( $new_value );
                $old_value = self::get_taxonomy_old_display( $product, $field_name, $real_taxonomy, $is_native_taxonomy );
            } else {
                $new_value = (string) MMI_Pipeline_Field_Resolver::apply_transform( $new_value, $mapping['transform'] ?? 'none' );
                $old_value = self::get_product_field( $product, $field_name );
            }
            if ( ! MMI_Pipeline_Field_Resolver::values_are_equal( $old_value, $new_value, $mapping['type'] ?? '' ) ) {
                return true;
            }
        }

        // Backfill check: a taxonomy the Field Mapping panel doesn't even carry
        // (e.g. product_brand — assigned purely from Taxonomy Mapping alias rows,
        // see apply_non_native_taxonomy_aliases()) can still have new/changed
        // aliases the admin just saved — trigger a save so they get applied
        // instead of the product staying stuck as "unchanged" forever.
        foreach ( self::get_active_alias_taxonomies( $supplier, $mappings ) as $taxonomy ) {
            $alias_term_ids = MMI_Pipeline_Field_Resolver::resolve_taxonomy_via_alias_table( $supplier, $data, $taxonomy, $options['profile'] ?? 'default' );
            if ( empty( $alias_term_ids ) ) {
                continue;
            }
            $current_ids = wp_get_object_terms( $product->get_id(), $taxonomy, [ 'fields' => 'ids' ] ) ?: [];
            sort( $alias_term_ids );
            sort( $current_ids );
            if ( $alias_term_ids !== $current_ids ) {
                return true;
            }
        }

        // Backfill check: if the configured primary-key meta (e.g.
        // _mmi_supplier_sku_xchange) is missing or stale on an otherwise-unchanged
        // product, still trigger a save so update_product() can write it.
        if ( ! empty( $options['primary_key_wc'] ) && ! empty( $options['primary_value'] ) ) {
            $current = (string) $product->get_meta( $options['primary_key_wc'] );
            if ( ! MMI_Pipeline_Field_Resolver::values_are_equal( $current, (string) $options['primary_value'] ) ) {
                return true;
            }
        }

        // Backfill check: a product imported before Attributes was configured (or
        // before this profile had it enabled) has none of the configured attribute
        // taxonomies set yet — trigger a save so update_product() can apply them,
        // rather than leaving it stuck as "unchanged" indefinitely.
        if ( ! empty( $options['attribute_manager'] ) ) {
            $existing_attrs = array_keys( $product->get_attributes() );
            foreach ( $options['attribute_config_slugs'] ?? [] as $slug ) {
                if ( ! in_array( $slug, $existing_attrs, true ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Record whether a field value actually changed in the $field_stats accumulator.
     */
    public static function track_field_change( array &$field_stats, string $supplier, string $field_name, string $old_value, string $new_value ): void {
        $changed                                          = ( $old_value !== $new_value ) ? 1 : 0;
        $field_stats[ $supplier ][ $field_name ]['changed']   = ( $field_stats[ $supplier ][ $field_name ]['changed']   ?? 0 ) + $changed;
        $field_stats[ $supplier ][ $field_name ]['unchanged'] = ( $field_stats[ $supplier ][ $field_name ]['unchanged'] ?? 0 ) + ( 1 - $changed );
    }

    /**
     * Same as track_field_change(), plus stashes the old/new value pair into
     * $change_details (keyed by field) when it actually changed — collected
     * per-product by update_product() and flushed to the DB by
     * flush_change_details() once a real post ID exists.
     */
    private static function track_field_change_with_detail( array &$field_stats, array &$change_details, string $supplier, string $field_name, string $old_value, string $new_value ): void {
        self::track_field_change( $field_stats, $supplier, $field_name, $old_value, $new_value );
        if ( $old_value !== $new_value ) {
            $change_details[ $field_name ] = [ 'old' => $old_value, 'new' => $new_value ];
        }
    }

    /**
     * Persist one product's collected $change_details rows, one per changed
     * field, capped per (run, supplier, field) at MAX_FIELD_CHANGE_ITEMS —
     * the aggregate count in $field_stats stays exact regardless of the cap.
     *
     * $field_change_counts[supplier][field] is a running total across this
     * whole run (carried across batches via job-state progress — see
     * ProductImportController), incremented here in memory as each row is
     * written. Checking it avoids a COUNT query per product per field, which
     * calling this from inside the main per-product loop would otherwise turn
     * into exactly the query-in-a-loop pattern AGENTS.md's Scalability rules
     * forbid.
     */
    private static function flush_change_details( int $run_id, string $supplier, int $product_id, string $sku, string $title, array $change_details, array &$field_change_counts ): void {
        foreach ( $change_details as $field_name => $pair ) {
            $count = &$field_change_counts[ $supplier ][ $field_name ];
            $count = $count ?? 0;
            if ( $count >= self::MAX_FIELD_CHANGE_ITEMS ) {
                continue;
            }
            \MMI_DB::record_field_change_item( $run_id, $supplier, $field_name, $product_id, $sku, $title, $pair['old'], $pair['new'] );
            $count++;
        }
    }

    /**
     * Set a product field value.
     */
    public static function set_product_field( $product, $field_name, $value ) {
        if ( $field_name === 'post_title' ) {
            $product->set_name( $value );
        } elseif ( $field_name === 'post_content' ) {
            $product->set_description( $value );
        } elseif ( $field_name === '_sku' ) {
            $product->set_sku( $value );
        } elseif ( $field_name === '_regular_price' ) {
            $product->set_regular_price( $value );
        } elseif ( $field_name === '_sale_price' ) {
            $product->set_sale_price( $value );
        } elseif ( $field_name === '_price' ) {
            $product->set_price( $value );
        } elseif ( $field_name === '_sale_price_dates_from' ) {
            $product->set_date_on_sale_from( $value );
        } elseif ( $field_name === '_sale_price_dates_to' ) {
            $product->set_date_on_sale_to( $value );
        } elseif ( $field_name === '_stock' ) {
            $product->set_stock_quantity( $value );
        } elseif ( $field_name === '_stock_status' ) {
            $product->set_stock_status( $value );
        } elseif ( $field_name === '_manage_stock' ) {
            $product->set_manage_stock( $value );
        } elseif ( $field_name === '_virtual' ) {
            $product->set_virtual( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) );
        } elseif ( $field_name === '_downloadable' ) {
            $product->set_downloadable( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) );
        } elseif ( $field_name === '_weight' ) {
            $product->set_weight( $value );
        } elseif ( $field_name === '_length' ) {
            $product->set_length( $value );
        } elseif ( $field_name === '_width' ) {
            $product->set_width( $value );
        } elseif ( $field_name === '_height' ) {
            $product->set_height( $value );
        } else {
            $product->update_meta_data( $field_name, $value );
        }
    }
}
