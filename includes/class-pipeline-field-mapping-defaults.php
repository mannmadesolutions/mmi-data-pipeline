<?php
/**
 * Field Mapping Defaults — Shared Source of Truth
 *
 * Default per-field source paths/transforms for the New Products / Pricing
 * import profiles, and the merge logic that layers saved overrides on top.
 *
 * Used by:
 *  - admin/views/partials/default-field-mappings.php (Field Mapping UI panel)
 *  - ProductImportController::process_import_batch_cron() (actual import)
 *  - MMI_Pipeline_Config_Validator (pre-flight validation)
 *
 * IMPORTANT: Before this class existed, the UI merged these defaults in
 * purely for display, while the importer read MMI_DB::get_field_mappings()
 * raw (no defaults). Fields like post_title/_sku/_regular_price therefore
 * appeared "configured" in the UI but had no 'source' for the importer,
 * which silently skipped them — producing empty placeholder products
 * (post_title="Product", _sku=NULL). get_effective() is now the single
 * source of truth for both the UI and the importer/validator.
 *
 * @package MannMade\DataPipeline
 */

if (!defined('ABSPATH')) {
    exit;
}

class MMI_Pipeline_Field_Mapping_Defaults {

    const DEFAULTS = [
        // Core Product Fields
        'post_title' => [
            'label'    => 'Product Name/Title',
            'source'   => [
                'skuport'  => 'name',
                'xchange'  => 'product',
            ],
            'transform' => 'none',
            'type'     => 'string',
            'group'    => 'core',
            'required' => true,
        ],
        'post_content' => [
            'label'    => 'Product Description',
            'source'   => [
                'skuport'  => 'description',
                'xchange'  => 'descript',
            ],
            'transform' => 'none',
            'type'     => 'longtext',
            'group'    => 'core',
            'required' => false,
        ],
        '_sku' => [
            'label'    => 'Product SKU',
            'source'   => [
                'skuport'  => 'id',
                'xchange'  => 'sku',
            ],
            'transform' => 'none',
            'type'     => 'string',
            'group'    => 'core',
            'required' => true,
        ],
        'post_excerpt' => [
            'label'    => 'Short Description',
            'source'   => [
                'skuport'  => '',
                'xchange'  => '',
            ],
            'transform' => 'none',
            'type'     => 'longtext',
            'group'    => 'core',
            'required' => false,
        ],

        // Pricing
        '_regular_price' => [
            'label'    => 'Regular Price (MAP)',
            'source'   => [
                'skuport'  => 'map',
                'xchange'  => 'map_price',
            ],
            'transform' => 'to_decimal',
            'type'     => 'decimal',
            'group'    => 'pricing',
            'required' => true,
        ],
        '_sale_price' => [
            'label'    => 'Sale Price',
            'source'   => [
                'skuport'  => 'sale_price',
                'xchange'  => 'sale_price',
            ],
            'transform' => 'to_decimal',
            'type'     => 'decimal',
            'group'    => 'pricing',
            'required' => false,
        ],
        '_price' => [
            'label'    => 'Active Price',
            'source'   => [
                'skuport'  => 'map',
                'xchange'  => 'map_price',
            ],
            'transform' => 'to_decimal',
            'type'     => 'decimal',
            'group'    => 'pricing',
            'required' => true,
        ],
        '_sale_price_dates_from' => [
            'label'    => 'Sale Start Date',
            'source'   => [
                'skuport'  => 'sale_start_date',
                'xchange'  => 'sale_start_date',
            ],
            'transform' => 'to_datetime',
            'type'     => 'datetime',
            'group'    => 'pricing',
            'required' => false,
        ],
        '_sale_price_dates_to' => [
            'label'    => 'Sale End Date',
            'source'   => [
                'skuport'  => 'sale_end_date',
                'xchange'  => 'sale_end_date',
            ],
            'transform' => 'to_datetime',
            'type'     => 'datetime',
            'group'    => 'pricing',
            'required' => false,
        ],

        // Product Type
        '_virtual' => [
            'label'    => 'Virtual Product',
            'source'   => [
                'skuport'  => 'virtual',
                'xchange'  => 'virtual',
            ],
            'transform' => 'to_bool',
            'type'     => 'boolean',
            'group'    => 'product_type',
            'required' => false,
            'default'  => true,
            'note'     => 'Virtual products (software, licenses, digital goods) do not require shipping',
        ],
        '_downloadable' => [
            'label'    => 'Downloadable',
            'source'   => [
                'skuport'  => 'downloadable',
                'xchange'  => 'downloadable',
            ],
            'transform' => 'to_bool',
            'type'     => 'boolean',
            'group'    => 'product_type',
            'required' => false,
            'note'     => 'Downloadable products can be downloaded after purchase',
        ],

        // Inventory
        '_stock' => [
            'label'    => 'Stock Quantity',
            'source'   => [
                'skuport'  => 'stock_quantity',
                'xchange'  => 'stock_quantity',
            ],
            'transform' => 'to_int',
            'type'     => 'integer',
            'group'    => 'inventory',
            'required' => false,
        ],
        '_stock_status' => [
            'label'    => 'Availability (In Stock / Out of Stock)',
            'source'   => [
                'skuport'  => 'in_stock',
                'xchange'  => 'available',
            ],
            'transform' => 'map_stock_status',
            'type'     => 'string',
            'group'    => 'inventory',
            'required' => false,
            'note'     => 'Binary availability: instock or outofstock',
        ],
        '_manage_stock' => [
            'label'    => 'Manage Stock',
            'source'   => [
                'skuport'  => 'manage_stock',
                'xchange'  => 'manage_stock',
            ],
            'transform' => 'to_bool',
            'type'     => 'boolean',
            'group'    => 'inventory',
            'required' => false,
        ],

        // Shipping (Not Applicable for Virtual Products)
        '_weight' => [
            'label'    => 'Product Weight',
            'source'   => [
                'skuport'  => 'weight',
                'xchange'  => 'weight',
            ],
            'transform' => 'to_decimal',
            'type'     => 'decimal',
            'group'    => 'shipping',
            'required' => false,
            'note'     => '⚠️ N/A for virtual products (software, licenses, digital goods)',
        ],
        '_length' => [
            'label'    => 'Product Length',
            'source'   => [
                'skuport'  => 'length',
                'xchange'  => 'length',
            ],
            'transform' => 'to_decimal',
            'type'     => 'decimal',
            'group'    => 'shipping',
            'required' => false,
            'note'     => '⚠️ N/A for virtual products (software, licenses, digital goods)',
        ],
        '_width' => [
            'label'    => 'Product Width',
            'source'   => [
                'skuport'  => 'width',
                'xchange'  => 'width',
            ],
            'transform' => 'to_decimal',
            'type'     => 'decimal',
            'group'    => 'shipping',
            'required' => false,
            'note'     => '⚠️ N/A for virtual products (software, licenses, digital goods)',
        ],
        '_height' => [
            'label'    => 'Product Height',
            'source'   => [
                'skuport'  => 'height',
                'xchange'  => 'height',
            ],
            'transform' => 'to_decimal',
            'type'     => 'decimal',
            'group'    => 'shipping',
            'required' => false,
            'note'     => '⚠️ N/A for virtual products (software, licenses, digital goods)',
        ],

        // Images
        '_product_image_url' => [
            'label'    => 'Main Image URL',
            'source'   => [
                'skuport'  => 'image_url',
                'xchange'  => 'image_url',
            ],
            'transform' => 'none',
            'type'     => 'url',
            'group'    => 'media',
            'required' => false,
        ],
        '_product_gallery_urls' => [
            'label'    => 'Gallery Image URLs',
            'source'   => [
                'skuport'  => 'gallery_urls',
                'xchange'  => 'gallery_urls',
            ],
            'transform' => 'none',
            'type'     => 'array',
            'group'    => 'media',
            'required' => false,
        ],

        // Taxonomies
        'product_cat' => [
            'label'    => 'Product Categories',
            // Confirmed live against the real feeds (2026-08-30): Xchange's
            // 'categories' field is an empty array on every one of 4,900
            // real products — the actual category data lives in
            // 'master_category'/'sub_category' instead, which nothing here
            // pointed at until now (Taxonomy Mapping's own alias table had
            // already been built against the correct field, but nothing
            // fed it a real value — see AGENTS.md's Incident History for
            // that date). SkuPort has no category-shaped field in its feed
            // at all (confirmed: a real record's only keys are id, developer,
            // name, cost, map, msrp) — no 'skuport' entry here is correct,
            // not an oversight; there is nothing to map.
            'source'   => [
                'xchange'  => 'master_category+sub_category',
            ],
            'transform' => 'none',
            'type'     => 'taxonomy',
            'group'    => 'taxonomy',
            'required' => false,
        ],
        'product_tag' => [
            'label'    => 'Product Tags',
            // 'tags' is confirmed empty on every real Xchange record and
            // absent entirely from SkuPort's feed — unlike product_cat,
            // there is no alternate field carrying real tag data anywhere
            // in either feed to redirect this to. Left as-is (not a fix
            // pending, a genuine "this data doesn't exist upstream" case).
            'source'   => [
                'skuport'  => 'tags',
                'xchange'  => 'tags',
            ],
            'transform' => 'none',
            'type'     => 'taxonomy',
            'group'    => 'taxonomy',
            'required' => false,
        ],
        'product_brand' => [
            'label'    => 'Product Brand',
            // A real, per-supplier source field DOES exist for brand (unlike
            // product_tag above) — it was only ever treated as if it didn't,
            // special-cased to resolve purely through Taxonomy Mapping's
            // alias table (Product_Import_Worker::apply_non_native_taxonomy_aliases())
            // with no literal Field Mapping source at all. Unified with
            // product_cat/product_tag here per explicit user direction
            // (2026-08-30): brand shouldn't be treated differently from the
            // other taxonomies just because its WC-side assignment mechanism
            // (wp_set_object_terms(), no dedicated CRUD prop) differs from
            // theirs — that's a real, separate technical distinction, kept
            // as-is; this only fixes brand having no known raw source field.
            // Confirmed live: this also fixes scheduled/cron imports
            // silently skipping brand entirely — Taxonomy_Mapping_Handler::
            // apply_taxonomy_mappings() only skipped a field when its
            // source was empty, so brand having a real one now makes that
            // path resolve it correctly with no further code change needed.
            'source'   => [
                'xchange'  => 'brand',
                'skuport'  => 'developer',
            ],
            'transform' => 'none',
            'type'     => 'taxonomy',
            'group'    => 'taxonomy',
            'required' => false,
            // No static 'enabled' override (removed 2026-09-15) — it was a
            // leftover from when this field had no 'source' concept at all
            // (pre-2026-08-30) and needed a scalar switch of its own. Once it
            // gained a real per-supplier 'source' above, that scalar became a
            // second, disagreeing signal: merge()'s shape guard (see its own
            // docblock) treated any per-supplier 'enabled' array a virgin
            // profile saved via blank_mappings() as stale legacy data and
            // silently discarded it, letting this scalar `true` win even on a
            // brand-new profile with a genuinely blank source — the exact
            // "activated on virgin creation" bug. Removing it makes brand
            // fall through to the same auto-derived-from-source 'enabled'
            // every other multi-supplier taxonomy field already uses (see
            // merge()'s backfill block below) — a virgin profile's blanked
            // source now correctly reads as unmapped, and an existing
            // profile whose source already resolves for real (the common
            // case — see panel-field-mapping.php's Taxonomy Mapping
            // enable/disable toggle, which is what actually populates
            // 'source' for this field now that it has no free-text input of
            // its own) keeps reading as mapped exactly as before.
        ],

        // Enrichment (Xchange Web Asset API — see mmi-xchange-integration's
        // write_web_assets_json(), which now passes these through verbatim
        // instead of discarding them; MMI_Pipeline_Field_Resolver::
        // enrich_item_with_web_assets() merges them onto the source item so
        // these paths resolve at import time, not just in the Field Mapping
        // dropdown's sample-value preview. Targets the meta-box schema
        // MMI_Product_Meta_Boxes (mmi-hub) already defines but which nothing
        // in this pipeline wrote to before now. SkuPort has no equivalent
        // data source, so no 'skuport' entry — same precedent as product_cat.
        //
        // Real fill-rate confirmed live against a full production backfill
        // (2026-09-08, 3,218 SKU records): images/videos 100%, requirements/
        // platforms 98%, features 97%, long_description 98%, licensing 45%
        // -- far better than long_description's earlier-observed sparseness
        // on its own suggested. 'requirements' is a plain object keyed
        // directly by OS name ({"mac": {...}, "windows": {...}}), NOT a list
        // of {os: ...} items -- confirmed against a real record, so this
        // uses a plain dot-path (requirements.mac), not the [key=value]
        // filter syntax a first pass at this wrongly assumed (that version
        // silently resolved to null against every real record -- caught
        // before shipping by testing get_nested_value() against real saved
        // data, not by reasoning about the shape from the API's field name
        // alone).
        '_mmi_mac_requirements' => [
            'label'    => 'Mac System Requirements',
            'source'   => [
                'xchange'  => 'requirements.mac',
            ],
            'file'      => [
                'xchange' => 'xchange-web-assets.json',
            ],
            'transform' => 'to_json',
            'type'     => 'json',
            'group'    => 'enrichment',
            'required' => false,
        ],
        '_mmi_windows_requirements' => [
            'label'    => 'Windows System Requirements',
            'source'   => [
                'xchange'  => 'requirements.windows',
            ],
            'file'      => [
                'xchange' => 'xchange-web-assets.json',
            ],
            'transform' => 'to_json',
            'type'     => 'json',
            'group'    => 'enrichment',
            'required' => false,
        ],
        '_mmi_linux_requirements' => [
            'label'    => 'Linux System Requirements',
            'source'   => [
                'xchange'  => 'requirements.linux',
            ],
            'file'      => [
                'xchange' => 'xchange-web-assets.json',
            ],
            'transform' => 'to_json',
            'type'     => 'json',
            'group'    => 'enrichment',
            'required' => false,
        ],
        '_mmi_youtube_urls' => [
            'label'    => 'YouTube Video URLs',
            'source'   => [
                'xchange'  => 'videos',
            ],
            'file'      => [
                'xchange' => 'xchange-web-assets.json',
            ],
            'transform' => 'to_json',
            'type'     => 'json',
            'group'    => 'enrichment',
            'required' => false,
            // '_mmi_youtube_ids' (bare video IDs, the meta box's sibling
            // field) is deliberately left unmapped — the real shape of the
            // Web Asset API's 'videos' field (full URLs vs. bare IDs) hasn't
            // been confirmed against real data yet; don't guess a
            // URL-to-ID extraction regex blind.
        ],
        // The three fields ADR-0004 (mmi-admin/docs/decisions/) originally
        // hard-wired straight into WC postmeta from mmi-xchange-integration,
        // bypassing this wizard entirely — reversed per ADR-0005 at the
        // user's explicit direction ("the option for users to customize it
        // if they wish" outweighs the wizard-complexity concern ADR-0004 was
        // built around). Same shape/source file as requirements.*/videos
        // above; MMI_Xchange_Vendors::sync_enrichment_meta_to_products()
        // (the old direct-write path) is deleted, not left dormant, once
        // these entries are verified against the same real data it wrote.
        '_mmi_features' => [
            'label'    => 'Product Features',
            'source'   => [
                'xchange'  => 'features',
            ],
            'file'      => [
                'xchange' => 'xchange-web-assets.json',
            ],
            'transform' => 'to_json',
            'type'     => 'json',
            'group'    => 'enrichment',
            'required' => false,
        ],
        '_mmi_licensing' => [
            'label'    => 'Licensing Details',
            'source'   => [
                'xchange'  => 'licensing',
            ],
            'file'      => [
                'xchange' => 'xchange-web-assets.json',
            ],
            'transform' => 'to_json',
            'type'     => 'json',
            'group'    => 'enrichment',
            'required' => false,
        ],
        '_mmi_platforms' => [
            'label'    => 'Supported Platforms',
            'source'   => [
                'xchange'  => 'platforms',
            ],
            'file'      => [
                'xchange' => 'xchange-web-assets.json',
            ],
            'transform' => 'to_json',
            'type'     => 'json',
            'group'    => 'enrichment',
            'required' => false,
        ],

        // Custom Meta
        '__mmi_cog' => [
            'label'             => 'Dealer Price / Cost of Goods',
            'source'            => [
                'xchange'  => 'dealer_price',
                'skuport'  => 'dealer_price',
            ],
            'meta_key_resolver' => 'mmi_get_cog_meta_key',
            'transform'         => 'to_decimal',
            'type'              => 'decimal',
            'group'             => 'pricing',
            'required'          => false,
            'note'              => 'Written to the meta key configured in "COG Meta Key" setting (default: cog). Maps to the JetEngine COG ($) field.',
        ],
        '_supplier_name' => [
            'label'    => 'Supplier Name',
            'source'   => [
                'skuport'  => 'supplier',
                'xchange'  => 'supplier',
            ],
            'transform' => 'none',
            'type'     => 'string',
            'group'    => 'meta',
            'required' => false,
        ],
        '_supplier_id' => [
            'label'    => 'Supplier Product ID',
            'source'   => [
                'skuport'  => 'supplier_id',
                'xchange'  => 'supplier_id',
            ],
            'transform' => 'none',
            'type'     => 'string',
            'group'    => 'meta',
            'required' => false,
        ],
        '_supplier_updated_at' => [
            'label'    => 'Supplier Last Updated',
            'source'   => [
                'skuport'  => 'updated_at',
                'xchange'  => 'updated_at',
            ],
            'transform' => 'to_datetime',
            'type'     => 'datetime',
            'group'    => 'meta',
            'required' => false,
        ],
        'sku_xchange' => [
            'label'    => 'Legacy Xchange SKU',
            'source'   => [
                'xchange'  => 'sku',
            ],
            // Xchange-only by design (see 'source' above) — defaulted off for
            // every other supplier so the validator doesn't flag a permanent
            // "enabled but no source" warning for a supplier this field was
            // never meant to apply to.
            'enabled'  => [
                'xchange'  => true,
                'skuport'  => false,
            ],
            'transform' => 'none',
            'type'     => 'string',
            'group'    => 'meta',
            'required' => false,
            'note'     => 'Legacy meta key (no leading underscore) still read as a fallback by mmi-xchange-integration and mmi-ai-content. The canonical Xchange SKU is _mmi_supplier_sku_xchange, set automatically by the configured primary key regardless of this row — this only keeps the older key populated for those two plugins.',
        ],
    ];

    /**
     * Field keys deliberately excluded from Field Mapping's persisted
     * defaults/custom-field list even if a stale saved row exists for one —
     * i.e. a field whose real configuration lives entirely on another tab and
     * would otherwise resurface as a dead "Custom Meta" row that silently does
     * nothing at import time (see merge() below). Currently empty:
     * product_brand held this role briefly (a real DEFAULTS entry with a
     * working 'enabled'/'show_in_preview' toggle but deliberately no
     * 'source') until 2026-08-30, when it was given a real per-supplier
     * 'source' like product_cat/product_tag — kept here for the next field
     * that needs the same treatment.
     */
    const OBSOLETE_FIELDS = [];

    /**
     * Every valid import_mode value for an Import Profile. Single source of
     * truth for both mmi_save_import_profile (create) and
     * mmi_update_import_profile (update) in ImportSettingsController.php,
     * which each validated against their own copy of this list before —
     * the same anti-pattern that let PRODUCT_SCOPES drift between the two
     * (see the create handler's 'new_only' entry once being missing from
     * the update handler's copy).
     */
    const IMPORT_MODES = [ 'create-and-update', 'create-only', 'update-only', 'availability-sync' ];

    /**
     * Every valid product_scope value for an Import Profile. Single source
     * of truth for the same create/update pair above.
     */
    const PRODUCT_SCOPES = [ 'all_products', 'by_identifier', 'new_only' ];

    /**
     * Whether an import_mode allows creating new products. Single source of
     * truth for what was three independently-written, identically-shaped
     * `in_array($mode, ['create-only', 'create-and-update'], true)` checks
     * (class-dynamic-product-importer.php, class-import-preview.php,
     * class-pipeline-config-validator.php).
     */
    public static function is_create_capable_mode( ?string $import_mode ): bool {
        return in_array( $import_mode, [ 'create-and-update', 'create-only' ], true );
    }

    /**
     * Merge saved field mappings on top of the defaults.
     *
     * Mirrors the historical merge logic from default-field-mappings.php:
     * deep-merges per-supplier 'source'/'enabled'/'file' arrays so saving one
     * supplier's value doesn't wipe out defaults for other suppliers, and
     * appends any fully-custom fields the user added beyond the defaults.
     *
     * @param array      $field_mappings Saved mappings from MMI_DB::get_field_mappings().
     * @param array|null $base           Base to merge onto — self::DEFAULTS (the plugin's
     *                                   sensible per-supplier defaults) unless a caller passes
     *                                   self::blank_mappings() instead (see get_effective()).
     * @return array Effective mappings (base + saved overrides).
     */
    public static function merge( array $field_mappings, ?array $base = null ): array {
        $default_mappings = $base ?? self::DEFAULTS;

        foreach ( $default_mappings as $field => $defaults ) {
            if ( isset( $field_mappings[ $field ] ) ) {
                $saved = $field_mappings[ $field ];

                // If the default defines an array source but saved has a scalar/empty source,
                // discard the saved source so the default array structure is preserved.
                if ( is_array( $defaults['source'] ?? null ) ) {
                    if ( empty( $saved['source'] ) || is_string( $saved['source'] ) ) {
                        unset( $saved['source'] );
                    }
                } elseif ( ! array_key_exists( 'source', $defaults ) ) {
                    // No current DEFAULTS entry lacks a 'source' key (product_brand was
                    // the last one to, until it gained a real per-supplier source on
                    // 2026-08-30) — kept as a defensive guard for whatever field
                    // eventually takes over that "resolves purely through Taxonomy
                    // Mapping's alias table, no mapped field" role next, so a profile
                    // saved while THAT field had no 'source' concept can't resurrect a
                    // stale 'source'/'source_custom'/per-supplier constant as a
                    // live-looking control the importer never actually reads.
                    unset( $saved['source'], $saved['source_custom'], $saved['use_constant_value'], $saved['constant_value'] );
                }
                // Unlike 'source' (guarded above) or 'enabled' (scalar-vs-array
                // defaults, guarded below), no DEFAULTS entry has ever declared a
                // 'file' key — it's a purely saved, per-profile browsing
                // aid (which file's field names to suggest), never a static default.
                // There is no stale-shape scenario to guard against here, so unlike
                // those two, dropping the `! array_key_exists( 'file', $defaults )`
                // branch below is correct, not just "still passes today's data":
                // that branch used to be unconditionally true for every field and
                // silently discarded a just-autosaved file selection on every single
                // merge()/get_effective() call — the exact bug shape already fixed
                // for 'enabled' below, just missed for 'file' in that same pass.
                if ( is_array( $defaults['file'] ?? null ) && ! is_array( $saved['file'] ?? null ) ) {
                    unset( $saved['file'] );
                }
                if ( is_array( $defaults['enabled'] ?? null ) && ! is_array( $saved['enabled'] ?? null ) ) {
                    unset( $saved['enabled'] );
                } elseif ( array_key_exists( 'enabled', $defaults ) && ! is_array( $defaults['enabled'] ) && is_array( $saved['enabled'] ?? null ) ) {
                    // Mirror image of the check above: the default declares a plain
                    // scalar 'enabled' (one on/off switch, not per-supplier), so a
                    // stale per-supplier array from before this field had a real
                    // DEFAULTS entry must not silently override it — a non-empty
                    // array is always truthy in PHP, so leaving it in place would
                    // make every consumer that does `$mapping['enabled'] ?? true`
                    // read "enabled" regardless of what the stale array actually says.
                    //
                    // Bug fixed here: this used to be `! is_array( $defaults['enabled'] ?? null )`,
                    // which is also true when 'enabled' is simply ABSENT from
                    // $defaults — the normal case for every field except
                    // sku_xchange (post_title, post_content, _sku,
                    // post_excerpt, and most others declare no 'enabled' key at
                    // all). For every one of those, a genuinely-saved, live,
                    // correctly-per-profile per-supplier 'enabled' array was being
                    // silently stripped on every get_effective() call — not a
                    // stale-shape guard at all, since there was never a scalar
                    // default to guard against. The stripped mapping then had no
                    // 'enabled' key whatsoever, so updateFieldMappingsTable() in
                    // import-settings.js fell through to a selector
                    // (.field-enabled-toggle) that doesn't exist on a
                    // multi-supplier row and silently matched nothing — the
                    // checkboxes were never reset on a profile switch, leaving
                    // whichever profile was displayed first looking identical
                    // everywhere. Requiring `array_key_exists( 'enabled', $defaults )`
                    // scopes this guard to the two fields that actually need it.
                    unset( $saved['enabled'] );
                }

                // Deep-merge per-supplier arrays so that autosaving one supplier's value
                // does not wipe out the default values for other suppliers that haven't
                // been explicitly saved yet.
                if ( is_array( $defaults['source'] ?? null ) && is_array( $saved['source'] ?? null ) ) {
                    $saved['source'] = array_merge( $defaults['source'], $saved['source'] );
                }
                if ( is_array( $defaults['enabled'] ?? null ) && is_array( $saved['enabled'] ?? null ) ) {
                    $saved['enabled'] = array_merge( $defaults['enabled'], $saved['enabled'] );
                }
                if ( is_array( $defaults['file'] ?? null ) && is_array( $saved['file'] ?? null ) ) {
                    $saved['file'] = array_merge( $defaults['file'], $saved['file'] );
                }

                // A field with no static DEFAULTS 'enabled' entry at all (every
                // multi-supplier field except product_brand/sku_xchange above,
                // the only two that declare one) relies entirely on autosave-time
                // derivation — see ImportSettingsController.php's
                // mmi_pipeline_derive_field_enabled_state() — to populate
                // 'enabled' per supplier. That derivation only ever sees the RAW
                // saved row, though: a supplier whose 'source' comes purely from
                // THIS DEFAULTS entry (never itself explicitly touched by a save)
                // has no key in the raw row's 'source' either, so the derivation
                // correctly produces no 'enabled' entry for it. Unlike 'source'
                // itself (deep-merged against defaults two blocks up), nothing
                // backfilled the missing 'enabled' entry from that same default
                // source — every real consumer (class-product-import-worker.php,
                // this class's own callers) treats an absent per-supplier
                // 'enabled' key as disabled, so a field whose default correctly
                // maps a supplier that has simply never been individually saved
                // silently imports nothing for it, despite Field Mapping showing
                // it as configured (its 'source' cell reads from this same
                // merged default). Confirmed live: _regular_price's Xchange
                // source ("map_price") was present and correct while its
                // 'enabled' array had no 'xchange' key at all — Xchange pricing
                // was silently never being written on real imports for any
                // profile that had only ever touched this field's SkuPort side.
                //
                // Backfill here: any supplier present in the final merged
                // 'source' (or 'use_constant_value') with no 'enabled' entry at
                // all gets one derived the same way
                // mmi_pipeline_derive_field_enabled_state() would — never
                // overriding a supplier key that's already explicitly present,
                // even if it's false, since that could be a deliberate value.
                if ( ! array_key_exists( 'enabled', $defaults ) ) {
                    $merged_source = $saved['source'] ?? ( $defaults['source'] ?? null );
                    $merged_const  = $saved['use_constant_value'] ?? ( $defaults['use_constant_value'] ?? null );
                    if ( is_array( $merged_source ) ) {
                        $existing  = is_array( $saved['enabled'] ?? null ) ? $saved['enabled'] : [];
                        $suppliers = array_unique( array_merge(
                            array_keys( $merged_source ),
                            is_array( $merged_const ) ? array_keys( $merged_const ) : []
                        ) );
                        foreach ( $suppliers as $sid ) {
                            if ( array_key_exists( $sid, $existing ) ) {
                                continue;
                            }
                            $has_source = '' !== trim( (string) ( $merged_source[ $sid ] ?? '' ) );
                            $has_const  = is_array( $merged_const )
                                ? ! empty( $merged_const[ $sid ] )
                                : ! empty( $merged_const );
                            $existing[ $sid ] = $has_source || $has_const;
                        }
                        $saved['enabled'] = $existing;
                    }
                }

                $default_mappings[ $field ] = array_merge( $defaults, $saved );
            } else {
                $default_mappings[ $field ]['enabled'] = $defaults['enabled'] ?? true;
            }
        }

        // Append any fully-custom fields the user added that aren't in the defaults above.
        foreach ( $field_mappings as $field => $saved ) {
            if ( in_array( $field, self::OBSOLETE_FIELDS, true ) ) {
                continue;
            }
            if ( ! isset( $default_mappings[ $field ] ) ) {
                $default_mappings[ $field ] = array_merge( [
                    'label'     => $field,
                    'source'    => [],
                    'transform' => 'none',
                    'type'      => 'string',
                    'group'     => 'meta',
                    'required'  => false,
                ], $saved );
            }
        }

        return $default_mappings;
    }

    /**
     * Resolve the constant value (if any) configured for one supplier on a
     * field mapping. 'use_constant_value'/'constant_value' can each be either
     * a legacy scalar (applies to every supplier — pre-dates per-source
     * constants) or an array keyed by supplier ID (independent per source,
     * configured per row in the Field Mapping panel). This is the single
     * choke point every caller (import worker, preview, validator, dynamic
     * importer) resolves through, so the two shapes are only ever handled
     * once.
     *
     * @param array  $mapping  One field's effective mapping.
     * @param string $supplier Supplier ID (ignored for legacy scalar constants).
     * @return string|null The constant value to use, or null if constant mode
     *                      isn't active for this supplier (caller should fall
     *                      back to resolving 'source' instead).
     */
    public static function resolve_constant( array $mapping, string $supplier ): ?string {
        $use = $mapping['use_constant_value'] ?? false;
        if ( is_array( $use ) ) {
            if ( empty( $use[ $supplier ] ) ) {
                return null;
            }
        } elseif ( empty( $use ) ) {
            return null;
        }

        $val = $mapping['constant_value'] ?? '';
        $val = is_array( $val ) ? ( $val[ $supplier ] ?? '' ) : $val;

        return $val !== '' ? (string) $val : null;
    }

    /**
     * Whether this mapping's constant (if active) is a legacy global override
     * that applies regardless of the per-supplier 'enabled' flag — the
     * behavior every caller already had before per-source constants existed.
     * A per-source constant (use_constant_value is an array) is scoped to one
     * supplier the same as a source path, so it respects 'enabled' like any
     * other supplier-specific value instead of bypassing it.
     */
    public static function constant_bypasses_enabled( array $mapping ): bool {
        return ! is_array( $mapping['use_constant_value'] ?? false );
    }

    /**
     * self::DEFAULTS with every field turned off and no source pre-selected —
     * the merge base for a profile that has not been created yet, so the
     * Create Import Profile wizard starts genuinely blank instead of silently
     * pre-populated with the plugin's built-in per-supplier defaults. Built
     * from DEFAULTS (not a hand-maintained parallel list) so a new DEFAULTS
     * entry can never be forgotten here.
     *
     * @return array Same shape as self::DEFAULTS, 'enabled' forced false and
     *                'source' forced blank (per-supplier where the field is
     *                multi-supplier, a single '' where it's a scalar).
     */
    public static function blank_mappings(): array {
        $blank = [];
        foreach ( self::DEFAULTS as $field => $defaults ) {
            $row = $defaults;

            // Multi-supplier shape is decided by 'source' being an array — the
            // same signal the JS/bulk-toggle code already uses elsewhere
            // (is_array($effective['source'] ?? null)) — not by whether
            // DEFAULTS happens to declare a per-supplier 'enabled' (only
            // sku_xchange does; every other multi-supplier field's 'enabled'
            // is implicit). Union both keysets so a field like sku_xchange
            // (enabled declares 'skuport', source doesn't) still gets every
            // relevant supplier blanked.
            $suppliers = array_unique( array_merge(
                array_keys( is_array( $defaults['source'] ?? null ) ? $defaults['source'] : [] ),
                array_keys( is_array( $defaults['enabled'] ?? null ) ? $defaults['enabled'] : [] )
            ) );

            if ( ! empty( $suppliers ) ) {
                if ( array_key_exists( 'source', $defaults ) ) {
                    $row['source'] = array_fill_keys( $suppliers, '' );
                }
                $row['enabled'] = array_fill_keys( $suppliers, false );
            } else {
                if ( array_key_exists( 'source', $defaults ) ) {
                    $row['source'] = '';
                }
                $row['enabled'] = false;
            }

            $blank[ $field ] = $row;
        }
        return $blank;
    }

    /**
     * Fill blank_mappings() into whatever gaps a profile's saved
     * field_mappings leaves — at the whole-field level for a field never
     * touched at all, and per-supplier for a multi-supplier field only
     * partially touched (e.g. Xchange's source saved, SkuPort's never
     * touched). The per-supplier pass matters because merge() itself always
     * backfills a missing supplier key from its $base — once a profile is
     * finalized that base is self::DEFAULTS (see get_effective()), so a
     * supplier gap left unfilled here would silently resurrect that
     * supplier's real default the moment the profile is created, even though
     * the field looked genuinely blank up to that point.
     *
     * Called once, at profile-finalization time
     * (ImportSettingsController::mmi_save_import_profile) — not on every
     * get_effective() call — so a field a user deliberately re-blanks later
     * isn't fought by this filling logic on the next load.
     *
     * @param array $saved Already-saved field_mappings (may be [] or partial).
     * @return array $saved with every DEFAULTS field/supplier gap filled blank.
     */
    public static function fill_blank_gaps( array $saved ): array {
        foreach ( self::blank_mappings() as $field => $blank_row ) {
            if ( ! isset( $saved[ $field ] ) ) {
                $saved[ $field ] = array_intersect_key( $blank_row, array_flip( [ 'source', 'enabled' ] ) );
                continue;
            }
            foreach ( [ 'source', 'enabled' ] as $prop ) {
                if ( ! array_key_exists( $prop, $blank_row ) ) {
                    continue;
                }
                if ( is_array( $blank_row[ $prop ] ) ) {
                    // Multi-supplier: only fill suppliers not already present —
                    // never overwrite a supplier the user actually configured.
                    $saved[ $field ][ $prop ] = is_array( $saved[ $field ][ $prop ] ?? null )
                        ? array_merge( $blank_row[ $prop ], $saved[ $field ][ $prop ] )
                        : $blank_row[ $prop ];
                } elseif ( ! isset( $saved[ $field ][ $prop ] ) ) {
                    $saved[ $field ][ $prop ] = $blank_row[ $prop ];
                }
            }
        }
        return $saved;
    }

    /**
     * Whether a profile has actually been created via "Create Profile" /
     * "Save Changes", as opposed to only existing as the placeholder row
     * MMI_DB::set_field_mappings() upserts while the wizard autosaves during
     * creation (see that method's docblock). Reuses the exact signal
     * ImportSettingsController::mmi_save_import_profile already established
     * for this same distinction: a placeholder row is never given a non-empty
     * 'sources' array, only a finalized profile is.
     *
     * @param string $profile_id Profile ID.
     * @return bool
     */
    public static function is_finalized( string $profile_id ): bool {
        $profiles = \MMI_DB::get_profiles();
        return isset( $profiles[ $profile_id ] ) && ! empty( $profiles[ $profile_id ]['sources'] );
    }

    /**
     * Load a profile's saved mappings and merge them with the defaults — the
     * single source of truth used by the importer, validator, and UI.
     *
     * A not-yet-finalized profile (still being created in the wizard) merges
     * onto blank_mappings() instead of DEFAULTS, so it reads as genuinely
     * blank rather than pre-populated — see is_finalized()/blank_mappings().
     * ProductImportController::mmi_save_import_profile additionally persists
     * a blank+overrides blob the moment a profile IS finalized, so a field
     * left untouched during creation stays blank permanently instead of
     * silently reverting to DEFAULTS the instant 'sources' becomes non-empty.
     *
     * @param string $profile_id Profile ID (e.g. 'new_products').
     * @return array Effective field mappings.
     */
    public static function get_effective( string $profile_id ): array {
        $saved = \MMI_DB::get_field_mappings( $profile_id );
        $base  = self::is_finalized( $profile_id ) ? self::DEFAULTS : self::blank_mappings();
        $effective = self::merge( is_array( $saved ) ? $saved : [], $base );

        // Registers hierarchical-taxonomy settings (delimiter, leaf-only) for
        // MMI_Pipeline_Field_Resolver::resolve_term_ids_smart() — this is the
        // single choke point every importer/preview/validator caller already
        // uses to load field mappings, so hooking it here means none of them
        // need their own explicit wiring for the new setting.
        if ( class_exists( 'MMI_Pipeline_Field_Resolver' ) ) {
            \MMI_Pipeline_Field_Resolver::init_hierarchy_settings( $effective );
        }

        return $effective;
    }

    /**
     * Normalize a data source's declared taxonomy fields — the
     * `configuration.taxonomy_fields` list a source row carries (seeded from
     * its template by mmi_ds_complete_template_setup(), edited in the
     * Configure modal's Taxonomies tab) — into a clean, deduplicated
     * `[ ['source_field' => ..., 'wc_taxonomy' => ...], ... ]` with exactly
     * one source field per taxonomy. Shared by MMI_Pipeline_Admin::
     * get_configured_suppliers() (the read path) and DataSourceController's
     * save handler (the write path) so both agree on the shape.
     *
     * @param mixed $raw Whatever was stored or posted.
     * @return array<int, array{source_field:string, wc_taxonomy:string}>
     */
    public static function normalize_taxonomy_fields( $raw ): array {
        $out  = [];
        $seen = [];
        foreach ( (array) $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $tax   = sanitize_key( (string) ( $row['wc_taxonomy'] ?? '' ) );
            $field = trim( sanitize_text_field( (string) ( $row['source_field'] ?? '' ) ) );
            if ( $tax === '' || $field === '' || isset( $seen[ $tax ] ) ) {
                continue;
            }
            $seen[ $tax ] = true;
            $out[]        = [ 'source_field' => $field, 'wc_taxonomy' => $tax ];
        }
        return $out;
    }

    /**
     * The fixed per-supplier source field behind a Taxonomy-Mapping-only
     * field (product_brand / product_cat — see panel-field-mapping.php's
     * $mmi_taxonomy_mapping_only_fields), keyed by supplier id: which raw
     * feed field feeds Taxonomy Mapping's alias lookup for this taxonomy.
     *
     * Before 2026-10-07 this was read straight from DEFAULTS[$field]['source'],
     * which only ever knew xchange and skuport — so Plugivery (whose template
     * had declared brand_name/cat_name since 2.56.1, with 34 alias values
     * already mapped) and every source added later had no "Enable for X"
     * toggle in Field Mapping, and could never be switched on. Now every
     * configured source's own declared taxonomy fields
     * (MMI_Pipeline_Admin::get_configured_suppliers()[$sid]['taxonomy_fields'])
     * are the primary source, with DEFAULTS kept only as the fallback for the
     * two original suppliers; a declaration always wins over DEFAULTS.
     *
     * @param string $field_name           Field key ('product_brand', 'product_cat', 'tax:{slug}').
     * @param array  $configured_suppliers MMI_Pipeline_Admin::get_configured_suppliers().
     * @return array<string, string> supplier_id => source field (compound 'a+b' allowed).
     */
    public static function fixed_taxonomy_sources( string $field_name, array $configured_suppliers ): array {
        $wc_taxonomy = ( strpos( $field_name, 'tax:' ) === 0 ) ? substr( $field_name, 4 ) : $field_name;
        $sources     = [];

        foreach ( (array) ( self::DEFAULTS[ $field_name ]['source'] ?? [] ) as $sid => $src ) {
            if ( trim( (string) $src ) !== '' ) {
                $sources[ $sid ] = trim( (string) $src );
            }
        }

        foreach ( $configured_suppliers as $sid => $info ) {
            foreach ( (array) ( $info['taxonomy_fields'] ?? [] ) as $tf ) {
                if ( ( $tf['wc_taxonomy'] ?? '' ) !== $wc_taxonomy ) {
                    continue;
                }
                $field = trim( (string) ( $tf['source_field'] ?? '' ) );
                if ( $field !== '' ) {
                    $sources[ $sid ] = $field;
                }
            }
        }

        // Only sources that exist right now — a DEFAULTS entry for a supplier
        // that was deleted must not resurrect a toggle for it.
        return array_intersect_key( $sources, $configured_suppliers );
    }

    /**
     * Every currently-configured (supplier, source_field, wc_taxonomy) triple
     * with a real, non-empty source — the single derivation Taxonomy Mapping's
     * UI/backend uses in place of a hardcoded xchange/skuport/plugivery list.
     *
     * Previously TaxonomyMappingController.php and section-taxonomy-mapping.php
     * each hardcoded the same 3 (supplier, field, taxonomy) rows independently,
     * so a source added after those lists were written — an uploaded/custom
     * source like "Mogami Packaged Dealer," not just a new API integration —
     * was invisible to Taxonomy Mapping's scan/preset-pill/custom-source-picker
     * UI even once its own profile had a working taxonomy field mapping. This
     * walks every real import profile's own get_effective() output (which
     * already includes both DEFAULTS-derived and fully custom/dynamic 'tax:'
     * fields — see merge()'s own "append fully-custom fields" pass above)
     * against every currently-configured supplier, exactly the way Field
     * Mapping's own panel (panel-field-mapping.php) already iterates
     * $configured_suppliers — so this needs no supplier-name special-casing
     * and picks up a new source or a new per-profile taxonomy field mapping
     * automatically, with no code change, the moment either exists.
     *
     * @return array<int, array{supplier:string, source_field:string, wc_taxonomy:string, label:string}>
     */
    public static function get_taxonomy_source_fields(): array {
        if ( ! class_exists( '\\MMI_Pipeline_Admin' ) || ! class_exists( '\\MMI_DB' ) ) {
            return [];
        }

        $configured_suppliers = \MMI_Pipeline_Admin::get_configured_suppliers();
        if ( empty( $configured_suppliers ) ) {
            return [];
        }

        $profiles = \MMI_DB::get_profiles_by_direction( 'import' );

        $seen   = [];
        $result = [];

        foreach ( array_keys( $profiles ) as $profile_id ) {
            $mappings = self::get_effective( $profile_id );

            foreach ( $mappings as $field_name => $mapping ) {
                if ( ( $mapping['type'] ?? '' ) !== 'taxonomy' ) {
                    continue;
                }

                $wc_taxonomy = ( strpos( $field_name, 'tax:' ) === 0 ) ? substr( $field_name, 4 ) : $field_name;

                foreach ( $configured_suppliers as $supplier_id => $supplier_info ) {
                    // Per-source Taxonomy Mapping toggle (2026-08-31) — a
                    // supplier with this off should never surface a scan
                    // profile/preset pill at all, matching
                    // resolve_taxonomy_via_alias_table() and
                    // Taxonomy_Mapping_Handler::apply_taxonomy_mappings()'s
                    // identical gate at actual resolution time.
                    if ( ! ( $supplier_info['taxonomy_mapping_enabled'] ?? true ) ) {
                        continue;
                    }

                    $source = is_array( $mapping['source'] ?? null )
                        ? ( $mapping['source'][ $supplier_id ] ?? '' )
                        : ( $mapping['source'] ?? '' );

                    if ( empty( $source ) || $source === 'NULL' || $source === 'null' ) {
                        continue;
                    }

                    $dedupe_key = $supplier_id . '|' . $source . '|' . $wc_taxonomy;
                    if ( isset( $seen[ $dedupe_key ] ) ) {
                        continue;
                    }
                    $seen[ $dedupe_key ] = true;

                    $tax_obj      = get_taxonomy( $wc_taxonomy );
                    $tax_label    = $tax_obj && $tax_obj->label ? $tax_obj->label : ( $mapping['label'] ?? $wc_taxonomy );
                    $supplier_label = $supplier_info['supplier_name'] ?? ucfirst( $supplier_id );

                    $result[] = [
                        'supplier'     => $supplier_id,
                        'source_field' => $source,
                        'wc_taxonomy'  => $wc_taxonomy,
                        'label'        => $supplier_label . ' — ' . $tax_label,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * What Taxonomy Mapping lists and scans: get_taxonomy_source_fields() plus
     * each source's own declared brand/category fields that no import profile
     * maps yet (get_configured_suppliers()[$sid]['taxonomy_fields'] — seeded
     * from the source's template, editable per source in the Configure
     * modal, so an uploaded/custom source that declares one is listed here
     * too), flagged 'suggested' => true.
     *
     * Without these, a source only appeared in Taxonomy Mapping after a
     * profile already imported its brand/category field, so its aliases could
     * not be set up before that import ran. Kept apart from
     * get_taxonomy_source_fields() on purpose: that list also drives applying
     * mappings to existing products, which must stay limited to fields an
     * import actually writes.
     *
     * @return array<int, array{supplier:string, source_field:string, wc_taxonomy:string, label:string, suggested:bool}>
     */
    public static function get_taxonomy_mapping_sources(): array {
        $result = array_map(
            static fn( array $row ): array => $row + [ 'suggested' => false ],
            self::get_taxonomy_source_fields()
        );
        if ( ! class_exists( '\\MMI_Pipeline_Admin' ) ) {
            return $result;
        }

        $seen = [];
        foreach ( $result as $row ) {
            $seen[ $row['supplier'] . '|' . $row['wc_taxonomy'] ] = true;
        }

        foreach ( \MMI_Pipeline_Admin::get_configured_suppliers() as $supplier_id => $info ) {
            if ( ! ( $info['taxonomy_mapping_enabled'] ?? true ) ) {
                continue;
            }
            foreach ( (array) ( $info['taxonomy_fields'] ?? [] ) as $field ) {
                // A profile mapping this taxonomy for the source (any field) wins.
                if ( isset( $seen[ $supplier_id . '|' . $field['wc_taxonomy'] ] ) ) {
                    continue;
                }
                $seen[ $supplier_id . '|' . $field['wc_taxonomy'] ] = true;
                $tax_obj  = get_taxonomy( $field['wc_taxonomy'] );
                $result[] = [
                    'supplier'     => (string) $supplier_id,
                    'source_field' => (string) $field['source_field'],
                    'wc_taxonomy'  => (string) $field['wc_taxonomy'],
                    'label'        => ( $info['supplier_name'] ?? ucfirst( $supplier_id ) ) . ' — '
                        . ( $tax_obj && $tax_obj->label ? $tax_obj->label : $field['wc_taxonomy'] )
                        . ' (not imported yet)',
                    'suggested'    => true,
                ];
            }
        }
        return $result;
    }
}
