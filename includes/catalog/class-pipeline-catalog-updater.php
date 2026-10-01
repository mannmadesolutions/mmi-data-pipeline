<?php
use MannMade\Integrations\Helpers\MMI_Updater_Trait;
if (!defined('ABSPATH'))
    exit;

class MMI_Pipeline_Catalog_Updater
{
    use MMI_Updater_Trait;

    /** @var string */
    protected $json_dir;

    public function __construct()
    {
        // Suite-wide JSON feed directory — see mmi_shared_lib_json_dir()
        // for why every reader/writer of these files must resolve the same
        // path (they previously didn't; see changelog).
        $this->json_dir = mmi_shared_lib_json_dir();
    }

    /**
     * Clean up duplicate stock status and stock quantity meta entries.
     * This prevents the accumulation of NULL or duplicate entries that can cause issues.
     */
    protected function cleanup_duplicate_stock_meta(): int
    {
        global $wpdb;

        // Remove NULL or empty _stock_status entries where a valid entry exists
        $removed_status = $wpdb->query("
            DELETE pm1 FROM {$wpdb->postmeta} pm1
            INNER JOIN {$wpdb->postmeta} pm2
            WHERE pm1.post_id = pm2.post_id
              AND pm1.meta_key = '_stock_status'
              AND pm2.meta_key = '_stock_status'
              AND pm1.meta_id > pm2.meta_id
              AND (pm1.meta_value = '' OR pm1.meta_value IS NULL)
              AND pm2.meta_value IN ('instock', 'outofstock')
        ");

        // Remove duplicate identical _stock_status entries (keep the oldest)
        $removed_dups = $wpdb->query("
            DELETE pm1 FROM {$wpdb->postmeta} pm1
            INNER JOIN {$wpdb->postmeta} pm2
            WHERE pm1.post_id = pm2.post_id
              AND pm1.meta_key = '_stock_status'
              AND pm2.meta_key = '_stock_status'
              AND pm1.meta_id > pm2.meta_id
              AND pm1.meta_value = pm2.meta_value
        ");

        if ($removed_status > 0 || $removed_dups > 0) {
            $this->log("Cleaned up duplicate stock meta: {$removed_status} NULL entries, {$removed_dups} duplicate entries");
        }

        return (int) $removed_status + (int) $removed_dups;
    }

    /**
     * Set product catalog visibility to exclude from search (shop only).
     * WooCommerce visibility: 'visible' = shop+search, 'catalog' = shop only, 'search' = search only, 'hidden' = neither
     *
     * @param int $product_id Product ID
     */
    protected function set_catalog_visibility_shop_only(int $product_id): void
    {
        // Set catalog visibility to 'catalog' (shop only, exclude from search)
        update_post_meta($product_id, '_visibility', 'catalog');

        // Also set the product visibility taxonomy term for WooCommerce 3.0+.
        // Append, never replace: a replace (append=false) also stripped the
        // 'outofstock' and 'rated-N' terms, un-hiding out-of-stock products
        // until finalize()'s reconcile put them back — or indefinitely when a
        // run died before finalize (found 2026-09-30: 97 canonical=FALSE
        // products missing 'outofstock').
        wp_set_object_terms($product_id, 'exclude-from-search', 'product_visibility', true);

        // 'catalog' = shown in shop; out-of-stock products shouldn't be featured.
        wp_remove_object_terms($product_id, ['exclude-from-catalog', 'featured'], 'product_visibility');
    }

    /**
     * Restore a product to full visibility (shop + search) when it comes back in stock.
     * Counterpart to set_catalog_visibility_shop_only().
     *
     * @param int $product_id Product ID
     */
    protected function set_catalog_visibility_visible(int $product_id): void
    {
        // Restore to 'visible' (shop + search)
        update_post_meta($product_id, '_visibility', 'visible');

        // Remove the exclude-from-search term so JetSearch and WC search can find it
        wp_remove_object_terms($product_id, 'exclude-from-search', 'product_visibility');
    }

    /**
     * Entry point — wraps run_internal() with a concurrency lock.
     *
     * CatalogUpdater is invoked hourly via the mmi_vip_update_catalog WP-Cron hook
     * and previously had NO lock at all, despite doing heavy multi-distributor SQL
     * work across the entire catalog. If a run legitimately takes longer than an
     * hour (very plausible — it scans 4 distributors' full SKU sets and performs
     * out-of-stock/in-stock diffing for each), the next hourly firing started a
     * SECOND overlapping instance writing to the same postmeta/wc_product_meta_lookup
     * rows the Import Pipeline's per-profile imports also write to. Diagnosed via
     * the pricing profile import: identical code that processed a product's stock
     * override in ~0.1s one day was taking 3-30s/product the next, with no other
     * code change — consistent with InnoDB row-lock contention from concurrent
     * unguarded writers, not a regression in either process's own logic.
     */
    public function run(): void
    {
        $lock_key = 'mmi_catalog_updater_lock';
        // This file is namespaced (MannMade\Integrations\Updaters\CLI) — MMI_DB is a
        // global class, so every reference here must be \MMI_DB, not the bare name
        // (which PHP resolves relative to the current namespace and fails to find).
        if ( class_exists( '\\MMI_DB' ) && \MMI_DB::get_job_state( $lock_key ) ) {
            $this->log( 'Run skipped — another CatalogUpdater run is already in progress' );
            return;
        }

        if ( class_exists( '\\MMI_DB' ) ) {
            // TTL intentionally less than the hourly cron interval (Rule 4: TTL
            // should match actual runtime, not a safe-feeling overestimate) — but
            // generous enough that a legitimately long run isn't double-started.
            \MMI_DB::set_job_state( $lock_key, current_time( 'mysql' ), 3300 );
        }

        register_shutdown_function( static function () use ( $lock_key ) {
            if ( class_exists( '\\MMI_DB' ) ) {
                \MMI_DB::delete_job_state( $lock_key );
            }
        } );

        try {
            $this->run_internal();
        } finally {
            if ( class_exists( '\\MMI_DB' ) ) {
                \MMI_DB::delete_job_state( $lock_key );
            }
        }
    }

    private function run_internal(): void
    {
        $this->log('Run: CatalogUpdater::run() started');

        // Clean up any duplicate stock status entries before processing
        $this->cleanup_duplicate_stock_meta();

        $this->enforce_canonical_stock_rules();
        
        // Auto-assign categories to uncategorized products using comprehensive scanner
        try {
            $this->log('Invoking product category auto-assignment scanner');
            $this->assign_categories_comprehensive('uncategorized', false, 50);
        } catch (\Exception $e) {
            $this->log('Error invoking category auto-assignment: ' . $e->getMessage());
        }

        // Load canonical groups from product meta (no JetEngine relation).
        // Memory-optimized: bulk SQL replaces per-post get_post_meta() loops that
        // filled the WP object cache with all meta for every product.
        global $wpdb;
        $canonicalIds = $wpdb->get_col( "
            SELECT DISTINCT pm.post_id
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = 'canonical'
              AND pm.meta_value = 'TRUE'
              AND p.post_type = 'product'
        " );

        $cfgData = ['groups' => []];

        if ( ! empty( $canonicalIds ) ) {
            $id_list = implode( ',', array_map( 'intval', $canonicalIds ) );

            // Bulk-fetch all three candidate meta keys in a single query.
            $assoc_meta_keys = [ 'canonical_associated_products', 'associated_products', 'associated_product_ids' ];
            $key_placeholders = implode( ',', array_fill( 0, count( $assoc_meta_keys ), '%s' ) );
            $assoc_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_key, meta_value
                     FROM {$wpdb->postmeta}
                     WHERE meta_key IN ({$key_placeholders})
                       AND post_id IN ({$id_list})",
                    ...$assoc_meta_keys
                )
            );

            // Index by post_id and meta_key priority order.
            $assoc_meta = []; // [ post_id => meta_value (first non-empty wins) ]
            $key_priority = array_flip( $assoc_meta_keys ); // lower index = higher priority
            foreach ( $assoc_rows as $r ) {
                $pid_key = (int) $r->post_id;
                $existing_priority = isset( $assoc_meta[ $pid_key ] )
                    ? ( $key_priority[ $assoc_meta[ $pid_key ]['_key'] ] ?? 99 )
                    : 99;
                $this_priority = $key_priority[ $r->meta_key ] ?? 99;
                if ( $this_priority < $existing_priority ) {
                    $assoc_meta[ $pid_key ] = [
                        '_key'  => $r->meta_key,
                        'value' => maybe_unserialize( $r->meta_value ),
                    ];
                }
            }

            // Collect all candidate associated IDs for a bulk type/status lookup.
            $candidate_ids = [];
            $pending_groups = [];
            foreach ( $canonicalIds as $canonId ) {
                $canonId = (int) $canonId;
                $raw     = $assoc_meta[ $canonId ]['value'] ?? null;
                $raw     = empty( $raw ) ? null : $raw;

                $ids = [];
                if ( is_array( $raw ) ) {
                    foreach ( $raw as $row_item ) {
                        $id = 0;
                        if ( is_array( $row_item ) ) {
                            $id = intval(
                                $row_item['associated_product_id']
                                ?? $row_item['product_id']
                                ?? $row_item['ID']
                                ?? $row_item['id']
                                ?? 0
                            );
                        } elseif ( is_object( $row_item ) ) {
                            $id = intval(
                                $row_item->associated_product_id
                                ?? $row_item->product_id
                                ?? $row_item->ID
                                ?? $row_item->id
                                ?? 0
                            );
                        } else {
                            $id = intval( $row_item );
                        }
                        if ( $id && $id !== $canonId ) {
                            $ids[] = $id;
                        }
                    }
                } elseif ( is_string( $raw ) && trim( $raw ) !== '' ) {
                    foreach ( preg_split( '/[,\|\s]+/', $raw ) as $part ) {
                        $id = intval( $part );
                        if ( $id && $id !== $canonId ) {
                            $ids[] = $id;
                        }
                    }
                }

                $ids                      = array_unique( $ids );
                $candidate_ids            = array_merge( $candidate_ids, $ids );
                $pending_groups[ $canonId ] = $ids;
            }

            // Single bulk query for post_type + post_status of all candidate IDs.
            $post_info = []; // [ id => ['type'=>..., 'status'=>...] ]
            if ( ! empty( $candidate_ids ) ) {
                $candidate_ids = array_unique( array_map( 'intval', $candidate_ids ) );
                $cand_list     = implode( ',', $candidate_ids );
                $post_rows     = $wpdb->get_results( "
                    SELECT ID, post_type, post_status
                    FROM {$wpdb->posts}
                    WHERE ID IN ({$cand_list})
                " );
                foreach ( $post_rows as $pr ) {
                    $post_info[ (int) $pr->ID ] = [ 'type' => $pr->post_type, 'status' => $pr->post_status ];
                }
            }

            $valid_statuses = [ 'publish', 'private' ];
            foreach ( $canonicalIds as $canonId ) {
                $canonId  = (int) $canonId;
                $products = [ $canonId ];
                foreach ( $pending_groups[ $canonId ] ?? [] as $id ) {
                    $info = $post_info[ $id ] ?? null;
                    if ( $info && $info['type'] === 'product' && in_array( $info['status'], $valid_statuses, true ) ) {
                        $products[] = $id;
                    } else {
                        $ptype = $info['type'] ?? 'unknown';
                        $pstat = $info['status'] ?? 'unknown';
                        $this->log( "Skipped associated ID {$id} (type/status invalid: {$ptype}/{$pstat})" );
                    }
                }
                $products             = array_values( array_unique( array_map( 'intval', $products ) ) );
                $cfgData['groups'][] = [ 'canonical' => $canonId, 'products' => $products ];
            }
        }

        $this->log( 'Loaded ' . count( $cfgData['groups'] ) . ' canonical products (meta-based).' );

        $skuToCanonical = [];

        // Load current promotions
        $promoData = $this->loadJson($this->json_dir . 'xchange-promotions.json');
        $promoMap = [];
        if (isset($promoData['promotions']) && is_array($promoData['promotions'])) {
            foreach ($promoData['promotions'] as $promo) {
                $now = time();
                $start = strtotime($promo['start_date']);
                $end = strtotime($promo['end_date']);
                if ($now >= $start && $now <= $end) {
                    $promoMap[$promo['sku']] = floatval($promo['promo_price']);
                }
            }
        }
        $feeds = [
            'xchange' => [
                'term_slug' => 'x',
                'file' => 'xchange-products.json',
                'key' => 'products',
                'sku_meta' => '_mmi_supplier_sku_xchange',
                'sku_field' => 'sku'
            ],

            'skuport' => [
                'term_slug' => 's',
                'file' => 'skuport-products.json',
                'key' => null,
                'sku_meta' => '_sku',
                'sku_field' => 'id'
            ],
            // Plugivery feed: products list simplified array file; use primary Woo _sku meta for mapping
            'plugivery' => [
                'term_slug' => 'p',
                'file' => 'plugivery_products_items.json', // simplified array root
                'key' => null, // already an array
                'sku_meta' => '_sku',
                'sku_field' => 'id'
            ],
            // Prism Sound feed: dealer price list converted from CSV
            'prism-sound' => [
                'term_slug' => 'prism-sound',
                'file' => 'prism-sound-products.json',
                'key' => 'products',
                'sku_meta' => '_sku',
                'sku_field' => 'sku'
            ],
        ];
        $allSkusMap = [];
        foreach ($feeds as $dist => $cfg) {
            $raw = $this->loadJson($this->json_dir . $cfg['file']);
            $items = ($cfg['key'] && isset($raw[$cfg['key']]) && is_array($raw[$cfg['key']]))
                ? $raw[$cfg['key']]
                : (is_array($raw) ? $raw : []);
            $skus = array_column($items, $cfg['sku_field']);
            $allSkusMap[$dist] = array_flip($skus);
            $this->log("[{$dist}] Feed items: " . count($items) . ", Feed SKUs: " . count($skus));
        }

        // Build full SKU→dealer_price map from xchange feed
        $dealerPriceMap = [];
        // Load the Xchange feed JSON
        $rawXchange = $this->loadJson($this->json_dir . $feeds['xchange']['file']);
        if (
            $feeds['xchange']['key']
            && isset($rawXchange[$feeds['xchange']['key']])
            && is_array($rawXchange[$feeds['xchange']['key']])
        ) {
            foreach ($rawXchange[$feeds['xchange']['key']] as $item) {
                $dealerPriceMap[$item['sku']] = floatval($item['dealer_price']);
            }
        }

        // Add Prism Sound dealer prices to the map
        $rawPrismSound = $this->loadJson($this->json_dir . $feeds['prism-sound']['file']);
        if (
            $feeds['prism-sound']['key']
            && isset($rawPrismSound[$feeds['prism-sound']['key']])
            && is_array($rawPrismSound[$feeds['prism-sound']['key']])
        ) {
            foreach ($rawPrismSound[$feeds['prism-sound']['key']] as $item) {
                if (!empty($item['dealer_price'])) {
                    $dealerPriceMap[$item['sku']] = floatval($item['dealer_price']);
                }
            }
            $this->log('[prism-sound] Added ' . count($rawPrismSound[$feeds['prism-sound']['key']]) . ' dealer prices to map');
        } else {
            $this->log('[prism-sound] Unable to load dealer prices from JSON file');
        }

        // No dynamic override: respect manually-set canonical products.
        // Associated products will not be modified by this updater.
        $this->log('Canonical override disabled: not touching associated products or flipping stock based on group scoring.');

        // Build list of associated (non-canonical) SKUs to exclude from feed-driven stock updates
        $allGroupSkus = [];
        if ($cfgData && isset($cfgData['groups']) && is_array($cfgData['groups'])) {
            foreach ($cfgData['groups'] as $group) {
                $canonId = isset($group['canonical']) ? (int) $group['canonical'] : 0;
                foreach ($group['products'] as $pid) {
                    $postId = (int) $pid;
                    if ($canonId && $postId === $canonId) {
                        // Do not skip the canonical product; let feed logic manage its stock
                        continue;
                    }
                    $sku = get_post_meta($postId, '_mmi_supplier_sku_xchange', true) ?: get_post_meta($postId, 'sku_xchange', true) ?: get_post_meta($postId, '_sku', true);
                    if ($sku) {
                        $allGroupSkus[] = $sku;
                    }
                }
            }
            $allGroupSkus = array_values(array_unique($allGroupSkus));
            if ($allGroupSkus) {
                $this->log('Feed skiplist: excluding ' . count($allGroupSkus) . ' associated SKUs from stock updates: ' . implode(', ', $allGroupSkus));
            } else {
                $this->log('Feed skiplist: no associated SKUs found to exclude.');
            }
        }

        // Build list of WooCommerce SKUs per distribution
        $wooSkusMap = [];
        foreach ($feeds as $dist => $cfg) {
            $sku_meta = $cfg['sku_meta'];
            $term_slug = $cfg['term_slug'];
            // Get term_id for this distribution term slug
            $this->log("[{$dist}] Looking up term slug '{$term_slug}'");
            $term_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT t.term_id
                     FROM {$wpdb->terms} t
                     JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                     WHERE tt.taxonomy = 'distribution' AND t.slug = %s",
                    $term_slug
                )
            );
            $this->log("[{$dist}] Found distribution term_id {$term_id} for slug '{$term_slug}'");
            if (!$term_id) {
                $wooSkusMap[$dist] = [];
                continue;
            }
            // Fetch all SKUs for products in this distribution
            $SKUs = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT pm.meta_value
                     FROM {$wpdb->postmeta} pm
                     JOIN {$wpdb->term_relationships} tr ON tr.object_id = pm.post_id
                     JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                     WHERE tt.taxonomy = 'distribution'
                       AND tt.term_id = %d
                       AND pm.meta_key = %s",
                    $term_id,
                    $sku_meta
                )
            );
            $wooSkusMap[$dist] = $SKUs;
            $this->log("[{$dist}] Woo SKUs retrieved: " . count($SKUs));
        }

        // Diff/intersect logic: mark gone vs. back SKUs
        foreach (['xchange', 'skuport', 'plugivery', 'prism-sound'] as $dist) {
            $cfg = $feeds[$dist];
            $sku_meta = $cfg['sku_meta'];
            $feedSkus = array_keys($allSkusMap[$dist]);
            $wooSkus = $wooSkusMap[$dist] ?? [];

            // Remove grouped SKUs from feed logic entirely
            if (!empty($allGroupSkus)) {
                $wooSkus = array_diff($wooSkus, $allGroupSkus);
                $feedSkus = array_diff($feedSkus, $allGroupSkus);
            }

            $this->log("[{$dist}] Comparing Woo SKUs (" . count($wooSkus) . ") vs feed SKUs (" . count($feedSkus) . ")");

            // Guard: an empty/missing feed file must never be treated as "every
            // product is gone." array_diff($wooSkus, []) === $wooSkus, so with no
            // guard here a missing feed file (confirmed live: plugivery_products_items.json
            // has never existed on disk, silently marking all 890 Plugivery products
            // out of stock on every run since at least 2026-08-09) marks the ENTIRE
            // distribution out of stock. Skip this distribution's diff entirely when
            // its feed is empty — see the identical guard in sync_supplier_feed_public().
            if (empty($feedSkus)) {
                $this->log("[{$dist}] Feed is empty or missing ('" . $cfg['file'] . "') — skipping diff to avoid marking the whole distribution out of stock.");
                continue;
            }

            // SKUs no longer in feed → mark out of stock + zero quantity
            $skusGone = array_diff($wooSkus, $feedSkus);

            // — log each individual SKU we’re about to mark OOS
            foreach ($skusGone as $sku) {
                $this->log("[{$dist}] Out-of-stock detected: {$sku}");
            }
            if ($skusGone) {
                $this->log("[{$dist}] SKUs gone: " . count($skusGone));
                $this->mark_skus_out_of_stock($skusGone, $sku_meta);
            }

            // SKUs back in feed → mark in stock
            $skusBack = array_intersect($wooSkus, $feedSkus);

            // Filter out SKUs where canonical=FALSE (allow NULL, TRUE, or any other value).
            // Bulk-fetched via one JOIN instead of a per-SKU $wpdb->get_var() + get_post_meta()
            // pair — the per-SKU form triggers update_meta_cache() for every distinct post it
            // touches, growing the WP object cache unboundedly across a large "back in stock"
            // batch until the process exhausts its memory limit (matches the fix already
            // applied in enforce_canonical_stock_rules(), below).
            $eligibleSkusBack = [];
            $false_values = ['FALSE', 'false', 'False', '0', 0, 'not applicable', 'Not Applicable'];
            if (!empty($skusBack)) {
                $sku_list = "'" . implode("','", array_map('esc_sql', $skusBack)) . "'";
                $back_rows = $wpdb->get_results("
                    SELECT pm_sku.meta_value AS sku, pm_canon.meta_value AS canonical
                    FROM {$wpdb->postmeta} pm_sku
                    LEFT JOIN {$wpdb->postmeta} pm_canon
                        ON pm_canon.post_id = pm_sku.post_id AND pm_canon.meta_key = 'canonical'
                    WHERE pm_sku.meta_key = '{$sku_meta}'
                      AND pm_sku.meta_value IN ({$sku_list})
                ");
                foreach ($back_rows as $row) {
                    $canonical = $row->canonical;
                    if (!in_array($canonical, $false_values, true)) {
                        $eligibleSkusBack[] = $row->sku;
                        $this->log("[{$dist}] SKU '{$row->sku}' eligible for in-stock (canonical=" . var_export($canonical, true) . ")");
                    } else {
                        $this->log("[{$dist}] SKU '{$row->sku}' excluded (canonical=FALSE)");
                    }
                }
            }
            if ($eligibleSkusBack) {
                $quoted = "'" . implode("','", array_map('esc_sql', $eligibleSkusBack)) . "'";
                
                // Update stock status to instock
                $updated = $wpdb->query("
                    UPDATE {$wpdb->postmeta} pm_status
                    JOIN {$wpdb->postmeta} pm_sku USING(post_id)
                    SET pm_status.meta_value = 'instock'
                    WHERE pm_status.meta_key   = '_stock_status'
                      AND pm_sku.meta_key       = '{$sku_meta}'
                      AND pm_sku.meta_value     IN ({$quoted})
                      AND pm_status.meta_value != 'instock'
                ");
                
                // Update stock quantity to 9999 for in-stock products
                $wpdb->query("
                    UPDATE {$wpdb->postmeta} pm_qty
                    JOIN {$wpdb->postmeta} pm_sku USING(post_id)
                    SET pm_qty.meta_value = '9999'
                    WHERE pm_qty.meta_key   = '_stock'
                      AND pm_sku.meta_key   = '{$sku_meta}'
                      AND pm_sku.meta_value IN ({$quoted})
                ");
                
                // Ensure products without existing stock quantity meta get it created
                // Use update_post_meta instead of add_post_meta to prevent duplicates
                $post_ids = $wpdb->get_col("
                    SELECT DISTINCT pm_sku.post_id
                    FROM {$wpdb->postmeta} pm_sku
                    LEFT JOIN {$wpdb->postmeta} pm_qty ON pm_sku.post_id = pm_qty.post_id AND pm_qty.meta_key = '_stock'
                    WHERE pm_sku.meta_key = '{$sku_meta}'
                      AND pm_sku.meta_value IN ({$quoted})
                      AND pm_qty.meta_id IS NULL
                ");
                foreach ($post_ids as $pid) {
                    update_post_meta($pid, '_stock', '9999');
                }

                // Ensure products without existing stock_status meta get it created
                // Use update_post_meta instead of add_post_meta to prevent duplicates
                $post_ids_status = $wpdb->get_col("
                    SELECT DISTINCT pm_sku.post_id
                    FROM {$wpdb->postmeta} pm_sku
                    LEFT JOIN {$wpdb->postmeta} pm_status ON pm_sku.post_id = pm_status.post_id AND pm_status.meta_key = '_stock_status'
                    WHERE pm_sku.meta_key = '{$sku_meta}'
                      AND pm_sku.meta_value IN ({$quoted})
                      AND pm_status.meta_id IS NULL
                ");
                foreach ($post_ids_status as $pid) {
                    update_post_meta($pid, '_stock_status', 'instock');
                }

                // Restore search visibility for all products coming back in stock
                $ids_back_instock = $wpdb->get_col("
                    SELECT DISTINCT post_id
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '{$sku_meta}'
                      AND meta_value IN ({$quoted})
                ");
                foreach ($ids_back_instock as $pid) {
                    $this->set_catalog_visibility_visible((int) $pid);
                }

                $this->log("[{$dist}] Marked {$updated} returned products in stock with quantity 9999. SKUs: [" . implode(', ', $eligibleSkusBack) . "]");

                // Raw SQL writes above never fire woocommerce_product_set_stock_status,
                // which mmi-reverb-integration's change detector listens on — queue an
                // explicit async resync so these stock changes actually reach Reverb.
                if (function_exists('mmi_reverb_queue_resync') && !empty($ids_back_instock)) {
                    mmi_reverb_queue_resync(array_map('intval', $ids_back_instock));
                }
            }
            if (empty($skusBack) && !empty($feedSkus)) {
                $this->log("[{$dist}] No canonical SKUs to mark in stock after filtering.");
            }
            if (empty($skusGone) && empty($skusBack)) {
                $this->log("[{$dist}] No SKU changes detected.");
            }
        }
        // Run the SKU-prefix brand assignment scanner in dry-run mode so that
        // proposals are generated and available for admins (and then optionally
        // run apply when invoked from the admin UI by an authorized user).
        try {
            $this->log("Invoking SKU-prefix brand assignment scanner (dry-run)");
            $this->assign_brands_from_sku_prefix('all', false, 50);
        } catch (\Exception $e) {
            $this->log('Error invoking SKU-prefix assignment (dry-run): ' . $e->getMessage());
        }

        // If this updater is being run from the WP Admin UI by a user with
        // sufficient capability, perform the APPLY step immediately so the
        // brand matching runs "for real". The scanner itself enforces the
        // policy to only assign brands to products that currently have no
        // product_brand term and will not create terms.
        try {
            if (!defined('WP_CLI') || !WP_CLI) {
                // Only allow apply from the admin UI context and when the
                // current user can manage options (typically admins).
                if (function_exists('is_admin') && is_admin() && function_exists('current_user_can') && current_user_can( mmi_data_pipeline_required_capability( 'manage_options' ) )) {
                    $this->log('Admin context detected; invoking SKU-prefix brand assignment scanner (APPLY)');
                    // Use a larger batch size for admin-triggered apply
                    $this->assign_brands_from_sku_prefix('all', true, 200);
                } else {
                    $this->log('Not running APPLY: not in admin context or insufficient capability.');
                }
            } else {
                // When run via WP-CLI, do not auto-apply here — use the WP-CLI wrapper.
                $this->log('WP-CLI context detected; skipping admin auto-apply. Use wp mmi assign-brands --apply to perform applies via CLI.');
            }
        } catch (\Exception $e) {
            $this->log('Error invoking SKU-prefix assignment (apply): ' . $e->getMessage());
        }

        $this->log("Catalog update complete.");
    }

    /**
     * Run the scripts/assign_brands_from_wordpress.php scanner via PHP CLI.
     * This delegates to the existing standalone scanner so we don't duplicate
     * the heuristics. Runs in dry-run by default (no --apply).
     * Now scans WordPress directly instead of JSON feed, processing ALL products
     * regardless of stock_status.
     *
     * @param string $prefix Distribution filter: 'all', 'x' (xchange), or 's' (skuport)
     * @param bool $apply Whether to pass --apply (will attempt write operations)
     * @param int $batchSize Batch size when running --apply
     */
    public function assign_brands_from_sku_prefix(string $prefix = 'all', bool $apply = false, int $batchSize = 50): array
    {
        $script = self::scanner_script('assign_brands_from_wordpress.php');
        if ($script === null) {
            $this->log('Brand scanner (scripts/assign_brands_from_wordpress.php) is not installed on this site; skipping.');
            return ['assigned' => 0, 'skipped' => true];
        }

        // Locate a working PHP CLI binary. PHP_BINARY may point to php-fpm on some hosts,
        // so probe a short list until we find a binary whose SAPI is 'cli'.
        $candidates = [];
        if (defined('PHP_BINARY')) $candidates[] = PHP_BINARY;
        $candidates = array_merge($candidates, ['php', '/usr/bin/php', '/usr/local/bin/php']);

        $php = null;

        // helper: robust shell-arg escaping even when escapeshellarg is disabled
        $shellArg = function(string $s) {
            if (function_exists('escapeshellarg')) return escapeshellarg($s);
            // POSIX-safe single-quote escaping fallback
            return "'" . str_replace("'", "'\"'\"'", $s) . "'";
        };

        foreach ($candidates as $cand) {
            $cand = trim((string)$cand);
            if ($cand === '') continue;
            // try to ask the binary what PHP_SAPI is
            $probeCmd = $cand . ' -r ' . $shellArg('echo PHP_SAPI;');
            $probeOut = null;
            $probeExit = 1;
            @exec($probeCmd . ' 2>&1', $probeOut, $probeExit);
            $probeStr = is_array($probeOut) ? trim(implode("\n", $probeOut)) : trim((string)$probeOut);
            if ($probeExit === 0 && $probeStr === 'cli') {
                $php = $cand;
                break;
            }
        }

        if ($php === null) {
            // fallback to PHP_BINARY or plain 'php' — may still work
            $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
            $this->log('Warning: could not find explicit php-cli binary via probes; falling back to ' . $php);
        } else {
            $this->log('Using PHP CLI binary: ' . $php);
        }

        // Build command safely using our shellArg helper
        // Note: $prefix is now 'all', 'x', or 's' for distribution filtering
        $cmd = $php . ' ' . $shellArg($script) . ' ' . $shellArg($prefix);
        if ($apply) {
            $cmd .= ' --apply';
        }
        $cmd .= ' --batch-size=' . intval($batchSize);

        // Log and execute
        $this->log("Executing scanner command: {$cmd}");
        $output = [];
        $exit = 0;
        @exec($cmd . ' 2>&1', $output, $exit);
        if (is_array($output)) {
            foreach ($output as $line) {
                $this->log('[assign-scanner] ' . $line);
            }
        }
        if ($exit !== 0) {
            throw new \RuntimeException('Scanner process exited with code ' . intval($exit) . '. See logs for details.');
        }

        // Parse the scanner's own summary line for a real count instead of
        // returning nothing — see scripts/assign_brands_from_wordpress.php:336
        // ("Apply mode: examined N candidates, applied: N ..."). Dry-run mode
        // (line 240-243) has no "applied:" line at all, so it correctly falls
        // through to 0 — this method is only ever called in --apply mode by
        // the catalog phase runner.
        $assigned = 0;
        $outputText = is_array($output) ? implode("\n", $output) : (string) $output;
        if (preg_match('/applied:\s*(\d+)/i', $outputText, $m)) {
            $assigned = (int) $m[1];
        }

        return ['assigned' => $assigned];
    }

    /**
     * Path of a brand/category scanner in the site root's scripts/ folder,
     * or null where it isn't installed. The scanners are mannmade.us's own
     * catalog heuristics and don't ship with this plugin, so on any other
     * site the brand and category phases skip instead of failing every run.
     */
    private static function scanner_script(string $file): ?string
    {
        if (!defined('ABSPATH')) {
            return null;
        }
        $script = rtrim(ABSPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $file;
        return file_exists($script) ? $script : null;
    }

    /**
     * Bulk-mark given SKUs out of stock (status + qty zero).
     */
    protected function mark_skus_out_of_stock(array $skus, string $sku_meta)
    {
        global $wpdb;
        if (empty($skus)) {
            return;
        }

        // Treat these as "FALSE" for canonical
        $false_values = ['FALSE', 'false', 'False', 0, '0', '', null, 'NULL', 'null', 'not applicable', 'Not Applicable'];

        // Bulk-fetched via one JOIN instead of a per-SKU $wpdb->get_var() + get_post_meta()
        // pair — see the identical fix/rationale in run_internal()'s "eligible SKUs back in
        // stock" loop, above.
        $filtered_skus = [];
        $skipped_skus = [];
        $found_skus = [];
        $sku_list = "'" . implode("','", array_map('esc_sql', $skus)) . "'";
        $gone_rows = $wpdb->get_results("
            SELECT pm_sku.meta_value AS sku, pm_canon.meta_value AS canonical
            FROM {$wpdb->postmeta} pm_sku
            LEFT JOIN {$wpdb->postmeta} pm_canon
                ON pm_canon.post_id = pm_sku.post_id AND pm_canon.meta_key = 'canonical'
            WHERE pm_sku.meta_key = '{$sku_meta}'
              AND pm_sku.meta_value IN ({$sku_list})
        ");
        foreach ($gone_rows as $row) {
            $found_skus[$row->sku] = true;
            $canonical = $row->canonical;
            // Check against all possible "false" values - only exclude if explicitly FALSE
            if (!in_array($canonical, $false_values, true)) {
                $filtered_skus[] = $row->sku;
                $this->log("[stock] Marking SKU '{$row->sku}' out of stock (canonical=" . var_export($canonical, true) . ")");
            } else {
                $skipped_skus[] = $row->sku;
                $this->log("[stock] Skipping SKU '{$row->sku}' (canonical=FALSE)");
            }
        }
        foreach ($skus as $sku) {
            if (!isset($found_skus[$sku])) {
                $skipped_skus[] = $sku;
                $this->log("[stock] Skipping SKU '{$sku}' (no post found)");
            }
        }

        // Summary logs
        if (!empty($filtered_skus)) {
            $this->log("[stock] SKUs marked out of stock: [" . implode(', ', $filtered_skus) . "]");
        }
        if (!empty($skipped_skus)) {
            $this->log("[stock] SKUs skipped (canonical=FALSE or not found): [" . implode(', ', $skipped_skus) . "]");
        }

        if (empty($filtered_skus)) {
            $this->log(__METHOD__ . ": No eligible SKUs to mark out of stock (all canonical=FALSE or not found).");
            return;
        }
        $quoted = "'" . implode("','", array_map('esc_sql', $filtered_skus)) . "'";
        
        // Get product IDs that will be marked out of stock for visibility updates
        $product_ids_to_update = $wpdb->get_col("
            SELECT DISTINCT pm_sku.post_id
            FROM {$wpdb->postmeta} pm_sku
            WHERE pm_sku.meta_key = '{$sku_meta}'
              AND pm_sku.meta_value IN ({$quoted})
        ");
        // Visibility writes only where the product isn't already excluded from search —
        // a taxonomy write per product for thousands of unchanged products is what timed out.
        $already_hidden = $wpdb->get_col( $wpdb->prepare( "
            SELECT tr.object_id FROM {$wpdb->term_relationships} tr
            JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_visibility'
            JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = %s
            WHERE tr.object_id IN (" . ( $product_ids_to_update ? implode( ',', array_map( 'intval', $product_ids_to_update ) ) : '0' ) . ")
        ", 'exclude-from-search' ) );
        $needs_hiding = array_diff( array_map( 'intval', $product_ids_to_update ), array_map( 'intval', $already_hidden ) );
        // Products that really change state this run (not already out of stock) — the only
        // ones Reverb needs to hear about.
        $newly_out = $product_ids_to_update ? array_map( 'intval', $wpdb->get_col( "
            SELECT DISTINCT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_stock_status' AND meta_value != 'outofstock'
              AND post_id IN (" . implode( ',', array_map( 'intval', $product_ids_to_update ) ) . ")
        " ) ) : [];
        
        // status
        $updatedStatus = $wpdb->query("
            UPDATE {$wpdb->postmeta} pm_status
            JOIN {$wpdb->postmeta} pm_sku USING(post_id)
            SET pm_status.meta_value = 'outofstock'
            WHERE pm_status.meta_key = '_stock_status'
              AND pm_sku.meta_key    = '{$sku_meta}'
              AND pm_sku.meta_value IN ({$quoted})
              AND pm_status.meta_value != 'outofstock'
        ");
        
        // Update catalog visibility for products being marked out of stock
        foreach ($needs_hiding as $product_id) {
            $this->set_catalog_visibility_shop_only((int)$product_id);
        }
        // qty
        $updatedQty = $wpdb->query("
            UPDATE {$wpdb->postmeta} pm_qty
            JOIN {$wpdb->postmeta} pm_sku USING(post_id)
            SET pm_qty.meta_value = '0'
            WHERE pm_qty.meta_key = '_stock'
              AND pm_sku.meta_key  = '{$sku_meta}'
              AND pm_sku.meta_value IN ({$quoted})
              AND pm_qty.meta_value != '0'
        ");
        // Ensure products without existing stock meta get created
        // Use update_post_meta instead of add_post_meta to prevent duplicates
        if ($updatedStatus < count($filtered_skus)) {
            $missingStatusIds = $wpdb->get_col("
                SELECT pm2.post_id FROM {$wpdb->postmeta} pm2
                LEFT JOIN {$wpdb->postmeta} pm_status ON pm2.post_id = pm_status.post_id AND pm_status.meta_key = '_stock_status'
                WHERE pm2.meta_key   = '{$sku_meta}'
                  AND pm2.meta_value IN ({$quoted})
                  AND pm_status.meta_id IS NULL
            ");
            foreach ($missingStatusIds as $postId) {
                update_post_meta($postId, '_stock_status', 'outofstock');
                $this->set_catalog_visibility_shop_only((int)$postId);
            }
        }
        if ($updatedQty < count($filtered_skus)) {
            $missingQtyIds = $wpdb->get_col("
                SELECT pm3.post_id FROM {$wpdb->postmeta} pm3
                LEFT JOIN {$wpdb->postmeta} pm_qty ON pm3.post_id = pm_qty.post_id AND pm_qty.meta_key = '_stock'
                WHERE pm3.meta_key   = '{$sku_meta}'
                  AND pm3.meta_value IN ({$quoted})
                  AND pm_qty.meta_id IS NULL
            ");
            foreach ($missingQtyIds as $postId) {
                update_post_meta($postId, '_stock', '0');
            }
        }
        $this->log(__METHOD__ . ": Marked " . count($filtered_skus) . " SKUs as out of stock. SKUs: [" . implode(', ', $filtered_skus) . "]");

        // Raw SQL writes above never fire woocommerce_product_set_stock_status,
        // which mmi-reverb-integration's change detector listens on — queue an
        // explicit async resync so these stock changes actually reach Reverb.
        if (function_exists('mmi_reverb_queue_resync') && !empty($newly_out)) {
            mmi_reverb_queue_resync($newly_out);
        }
    }

    /**
     * Wall-clock budget for one canonical pass. Catalog phases run as Action
     * Scheduler actions, and when the claim lands on scripts/wp-cron.php the
     * whole process is killed at 45 s (crontab `timeout -k 3 45`, AGENTS.md
     * Rule 12) — no fatal, no log, the action just sits in-progress until AS
     * marks it failed and the run never finishes. Every run is idempotent, so
     * work left over when the budget runs out is picked up next run.
     */
    private const CANONICAL_TIME_BUDGET = 20.0;

    /**
     * Enforce canonical/stock status rules: every canonical=FALSE product, and
     * every non-canonical product listed in a canonical=TRUE product's
     * associated_product_ids, is out of stock, canonical=FALSE, and shop-only
     * (catalog visibility, excluded from search, not featured).
     *
     * Writes only products not already in that state. Rewriting all of them
     * every run (283 products, each with meta updates plus term replace +
     * recount, plus a Reverb resync lookup) took 34–48 s — so a run whose
     * phase was claimed by the 45 s wp-cron process was killed about 1 time in
     * 3 (2026-09-29/30). All state is read in bulk up front; the steady state
     * does no writes.
     *
     * @return array{checked:int,targets:int,changed:int,deferred:int}
     */
    protected function enforce_canonical_stock_rules(): array
    {
        global $wpdb;

        $deadline = microtime( true ) + self::CANONICAL_TIME_BUDGET;

        // Single query: load all canonical values for product posts.
        // Avoids per-post get_post_meta() which fills the WP object cache with
        // every meta row for each post, exhausting the 256 MB memory limit.
        $rows = $wpdb->get_results( "
            SELECT pm.post_id, pm.meta_value AS canonical_value
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = 'canonical'
              AND p.post_type = 'product'
        " );

        $canonical_map = [];
        foreach ( $rows as $row ) {
            $canonical_map[ (int) $row->post_id ] = $row->canonical_value;
        }

        // Second query: load associated_product_ids only for canonical=TRUE products.
        $true_ids = array_keys( array_filter( $canonical_map, static fn( $v ) => $v === 'TRUE' ) );
        $assoc_map = [];
        if ( ! empty( $true_ids ) ) {
            $placeholders = implode( ',', array_map( 'intval', $true_ids ) );
            $assoc_rows   = $wpdb->get_results( "
                SELECT post_id, meta_value
                FROM {$wpdb->postmeta}
                WHERE meta_key = 'associated_product_ids'
                  AND post_id IN ({$placeholders})
            " );
            foreach ( $assoc_rows as $r ) {
                $assoc_map[ (int) $r->post_id ] = $r->meta_value;
            }
        }

        // Target id => whether its canonical flag must also be forced to FALSE
        // (associated members of a TRUE group; FALSE products already are).
        $targets = [];
        foreach ( $canonical_map as $pid => $canonical ) {
            if ( $canonical === 'TRUE' ) {
                foreach ( $this->parse_associated_product_ids( $assoc_map[ $pid ] ?? '' ) as $aid ) {
                    $aid = intval( $aid );
                    if ( ! $aid || $aid === $pid || ( $canonical_map[ $aid ] ?? '' ) === 'TRUE' ) {
                        continue; // Skip; associated product is itself canonical.
                    }
                    $targets[ $aid ] = true;
                }
            } elseif ( $canonical === 'FALSE' ) {
                $targets[ $pid ] = $targets[ $pid ] ?? false;
            }
            // Empty / NULL canonical value — no action required.
        }

        $stats = [ 'checked' => count( $canonical_map ), 'targets' => count( $targets ), 'changed' => 0, 'deferred' => 0 ];
        if ( ! $targets ) {
            return $stats;
        }

        // Current state of every target in two bulk reads. Ids with no post row
        // (a deleted associated product) drop out here instead of getting
        // orphan meta written for them.
        $current = [];
        foreach ( array_chunk( array_keys( $targets ), 1000 ) as $chunk ) {
            $in = implode( ',', array_map( 'intval', $chunk ) );
            foreach ( $wpdb->get_results( "
                SELECT p.ID AS id,
                       MAX(CASE WHEN pm.meta_key = 'canonical'     THEN pm.meta_value END) AS canonical,
                       MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) AS stock,
                       MAX(CASE WHEN pm.meta_key = '_visibility'   THEN pm.meta_value END) AS visibility
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('canonical', '_stock_status', '_visibility')
                WHERE p.ID IN ({$in})
                GROUP BY p.ID
            " ) as $r ) {
                $current[ (int) $r->id ] = [ 'canonical' => $r->canonical, 'stock' => $r->stock, 'visibility' => $r->visibility, 'terms' => [] ];
            }
            foreach ( $wpdb->get_results( "
                SELECT tr.object_id, t.slug
                FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_visibility'
                JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                WHERE tr.object_id IN ({$in})
                  AND t.slug IN ('exclude-from-search', 'exclude-from-catalog', 'featured')
            " ) as $r ) {
                if ( isset( $current[ (int) $r->object_id ] ) ) {
                    $current[ (int) $r->object_id ]['terms'][ $r->slug ] = true;
                }
            }
        }

        $touched_ids = [];
        foreach ( $current as $id => $s ) {
            $fix_canonical  = $targets[ $id ] && $s['canonical'] !== 'FALSE';
            $fix_stock      = $s['stock'] !== 'outofstock';
            $fix_visibility = $s['visibility'] !== 'catalog'
                || empty( $s['terms']['exclude-from-search'] )
                || ! empty( $s['terms']['exclude-from-catalog'] )
                || ! empty( $s['terms']['featured'] );
            if ( ! $fix_canonical && ! $fix_stock && ! $fix_visibility ) {
                continue;
            }
            if ( microtime( true ) > $deadline ) {
                $stats['deferred']++;
                continue;
            }
            if ( $fix_canonical ) {
                update_post_meta( $id, 'canonical', 'FALSE' );
            }
            if ( $fix_stock ) {
                update_post_meta( $id, '_stock_status', 'outofstock' );
                $touched_ids[] = $id;
            }
            if ( $fix_visibility ) {
                $this->set_catalog_visibility_shop_only( $id );
            }
            clean_post_cache( $id ); // Release object-cache entries to free RAM.
            $stats['changed']++;
        }

        if ( $stats['deferred'] ) {
            $this->log( "Canonical rules: time budget reached — {$stats['deferred']} product(s) left for the next run" );
        }

        // update_post_meta() above never fires woocommerce_product_set_stock_status,
        // which mmi-reverb-integration's change detector listens on — queue an
        // explicit async resync so these stock changes actually reach Reverb.
        if (function_exists('mmi_reverb_queue_resync') && !empty($touched_ids)) {
            mmi_reverb_queue_resync(array_map('intval', $touched_ids));
        }

        return $stats;
    }

    /**
     * Parses associated_product_ids in whatever shape it's actually stored as.
     * Two real shapes exist in production data: a flat comma/pipe/whitespace-
     * delimited string (written by CanonicalCandidatesController.php's
     * mmi_approve_duplicate_group), and a PHP-serialized nested-array shape
     * (100% of the ~1,000 pre-existing real rows found on this install at the
     * time of this fix: ['item-0' => ['associated_product_id' => '6451'], ...],
     * origin tool not identified — predates the current AJAX handler). A raw
     * $wpdb->get_results() meta_value column is ALWAYS a string — a prior
     * version of this method's is_array() branch on that value was therefore
     * permanently dead code, and every canonical group in the serialized shape
     * silently suppressed nothing. maybe_unserialize() on a genuine flat string
     * returns it unchanged (not valid serialized PHP), so that shape still
     * falls through to the same preg_split as before. Mirrors the fallback-key
     * chain get_associated_sku_skiplist() below already uses for the array
     * shape, generalized to also accept the flat-string shape it doesn't.
     *
     * @param mixed $raw
     * @return int[]
     */
    private function parse_associated_product_ids( $raw ): array
    {
        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            return [];
        }

        $unserialized = maybe_unserialize( $raw );

        if ( is_array( $unserialized ) ) {
            $ids = [];
            foreach ( $unserialized as $item ) {
                $id = is_array( $item )
                    ? (int) ( $item['associated_product_id'] ?? $item['product_id'] ?? $item['ID'] ?? $item['id'] ?? 0 )
                    : (int) $item;
                if ( $id ) {
                    $ids[] = $id;
                }
            }
            return $ids;
        }

        return array_map( 'intval', preg_split( '/[\,\|\s]+/', trim( $raw ) ) );
    }

    /** Memo for get_associated_sku_skiplist() — computed once per instance. */
    private ?array $associated_sku_skiplist_cache = null;

    /**
     * SKUs of products associated with a canonical=TRUE "parent" product —
     * these are ignored by the feed-sync stock diff, since their stock state
     * is driven by canonical grouping (enforce_canonical_stock_rules()), not
     * by whether their own SKU is present in a supplier feed.
     *
     * Two bulk queries, no per-product get_post_meta() — mirrors the fix
     * already applied in enforce_canonical_stock_rules() above, for the
     * identical reason: a per-product get_post_meta() call in a loop fills
     * the WP object cache with every meta row for every touched post,
     * exhausting the memory limit on a large catalog. Memoized on the
     * instance so a full catalog-update run (4 feed_sync phases) computes
     * this once, not once per supplier.
     */
    protected function get_associated_sku_skiplist(): array
    {
        if ( $this->associated_sku_skiplist_cache !== null ) {
            return $this->associated_sku_skiplist_cache;
        }

        global $wpdb;

        $canonical_ids = $wpdb->get_col( "
            SELECT DISTINCT pm.post_id
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = 'canonical'
              AND pm.meta_value = 'TRUE'
              AND p.post_type = 'product'
        " );

        $skiplist = [];
        if ( ! empty( $canonical_ids ) ) {
            $placeholders = implode( ',', array_map( 'intval', $canonical_ids ) );
            $assoc_rows   = $wpdb->get_results( "
                SELECT post_id, meta_key, meta_value
                FROM {$wpdb->postmeta}
                WHERE post_id IN ({$placeholders})
                  AND meta_key IN ('canonical_associated_products', 'associated_products', 'associated_product_ids')
            " );

            // First meta key found wins per parent, matching the original
            // per-product fallback chain's precedence (canonical_associated_products
            // > associated_products > associated_product_ids).
            $priority   = [ 'canonical_associated_products' => 0, 'associated_products' => 1, 'associated_product_ids' => 2 ];
            $chosen_raw = [];
            foreach ( $assoc_rows as $row ) {
                $pid = (int) $row->post_id;
                if ( ! isset( $chosen_raw[ $pid ] ) || $priority[ $row->meta_key ] < $priority[ $chosen_raw[ $pid ]['key'] ] ) {
                    $chosen_raw[ $pid ] = [ 'key' => $row->meta_key, 'value' => $row->meta_value ];
                }
            }

            $associated_ids = [];
            foreach ( $chosen_raw as $cid => $raw ) {
                $assoc = maybe_unserialize( $raw['value'] );
                if ( ! is_array( $assoc ) ) {
                    continue;
                }
                foreach ( $assoc as $item ) {
                    $id = is_array( $item )
                        ? (int) ( $item['associated_product_id'] ?? $item['product_id'] ?? $item['ID'] ?? $item['id'] ?? 0 )
                        : (int) $item;
                    if ( $id && $id !== $cid ) {
                        $associated_ids[ $id ] = true;
                    }
                }
            }

            if ( ! empty( $associated_ids ) ) {
                $id_placeholders = implode( ',', array_map( 'intval', array_keys( $associated_ids ) ) );
                // Legacy fallback: some older products carry a supplier-specific
                // '_mmi_supplier_sku_xchange' meta key instead of the generic
                // '_sku' — same precedence the original inline code used.
                $sku_rows = $wpdb->get_results( "
                    SELECT post_id, meta_key, meta_value
                    FROM {$wpdb->postmeta}
                    WHERE post_id IN ({$id_placeholders})
                      AND meta_key IN ('_mmi_supplier_sku_xchange', '_sku')
                      AND meta_value != ''
                " );
                $sku_priority = [ '_mmi_supplier_sku_xchange' => 0, '_sku' => 1 ];
                $chosen_sku   = [];
                foreach ( $sku_rows as $row ) {
                    $pid = (int) $row->post_id;
                    if ( ! isset( $chosen_sku[ $pid ] ) || $sku_priority[ $row->meta_key ] < $sku_priority[ $chosen_sku[ $pid ]['key'] ] ) {
                        $chosen_sku[ $pid ] = [ 'key' => $row->meta_key, 'value' => $row->meta_value ];
                    }
                }
                foreach ( $chosen_sku as $row ) {
                    $skiplist[] = $row['value'];
                }
            }
        }

        $skiplist = array_values( array_unique( $skiplist ) );
        if ( $skiplist ) {
            $this->log( 'Feed skiplist: excluding ' . count( $skiplist ) . ' associated SKUs from stock updates: ' . implode( ', ', $skiplist ) );
        }

        $this->associated_sku_skiplist_cache = $skiplist;
        return $skiplist;
    }

    /* ── Public API for the phased AJAX catalog updater ─────────────────── */

    /**
     * Public wrapper: cleanup duplicate stock meta entries.
     * Called by CatalogUpdateController for the 'cleanup' phase.
     *
     * @return array{removed: int}
     */
    public function cleanup_duplicate_stock_meta_public(): array
    {
        $removed = $this->cleanup_duplicate_stock_meta();
        return ['removed' => (int) $removed];
    }

    /**
     * Public wrapper: enforce canonical stock rules.
     * Called by CatalogUpdateController for the 'canonical' phase.
     *
     * @return array{checked:int,targets:int,changed:int,deferred:int}
     */
    public function enforce_canonical_stock_rules_public(): array
    {
        return $this->enforce_canonical_stock_rules();
    }

    /**
     * Sync stock for a single supplier's feed diff.
     * Called by CatalogUpdateController for each 'feed_sync' phase.
     *
     * @param string $supplier  Supplier ID: 'xchange', 'skuport', 'plugivery', 'prism-sound'
     * @return array{gone: int, back: int}
     */
    public function sync_supplier_feed_public( string $supplier ): array
    {
        global $wpdb;

        $feeds = [
            'xchange'     => ['term_slug' => 'x',          'file' => 'xchange-products.json',         'key' => 'products', 'sku_meta' => '_mmi_supplier_sku_xchange', 'sku_field' => 'sku'],
            'skuport'     => ['term_slug' => 's',          'file' => 'skuport-products.json',         'key' => null,       'sku_meta' => '_sku',                    'sku_field' => 'id'],
            'plugivery'   => ['term_slug' => 'p',          'file' => 'plugivery_products_items.json', 'key' => null,       'sku_meta' => '_sku',                    'sku_field' => 'id'],
            'prism-sound' => ['term_slug' => 'prism-sound','file' => 'prism-sound-products.json',     'key' => 'products', 'sku_meta' => '_sku',                    'sku_field' => 'sku'],
        ];

        if ( ! isset( $feeds[$supplier] ) ) {
            $this->log( "sync_supplier_feed_public: unrecognised supplier '{$supplier}'" );
            return ['gone' => 0, 'back' => 0];
        }

        $cfg       = $feeds[$supplier];
        $sku_meta  = $cfg['sku_meta'];
        $term_slug = $cfg['term_slug'];

        // 1. Load feed SKUs.
        $raw   = $this->loadJson( $this->json_dir . $cfg['file'] );
        $items = ( $cfg['key'] && isset( $raw[ $cfg['key'] ] ) && is_array( $raw[ $cfg['key'] ] ) )
            ? $raw[ $cfg['key'] ] : ( is_array( $raw ) ? $raw : [] );
        $feedSkus = array_column( $items, $cfg['sku_field'] );
        $this->log( "[{$supplier}] Feed items: " . count( $items ) . ", Feed SKUs: " . count( $feedSkus ) );

        // Guard: an empty/missing feed file must never be treated as "every product
        // is gone." array_diff($wooSkus, []) === $wooSkus, so with no guard here a
        // missing feed file (confirmed live: plugivery_products_items.json has never
        // existed on disk, silently marking all 890 Plugivery products out of stock
        // on every 6-hourly run since at least 2026-08-09) marks the ENTIRE
        // distribution out of stock. Skip the sync entirely when the feed is empty —
        // this is a "we don't know," not a "everything left," signal.
        if ( empty( $feedSkus ) ) {
            $this->log( "[{$supplier}] Feed is empty or missing ('" . $cfg['file'] . "') — skipping sync to avoid marking the whole distribution out of stock." );
            return ['gone' => 0, 'back' => 0, 'skipped' => true];
        }

        // 2. Build canonical skiplist (associated / non-canonical products ignored by feed logic).
        // Memoized per-instance — this was previously rebuilt with per-product
        // get_post_meta() calls on EVERY call to this method (once per
        // supplier per run, so 4x per full catalog update), the exact N+1 /
        // update_meta_cache() object-cache growth pattern this class's own
        // enforce_canonical_stock_rules() already documents fixing once.
        $allGroupSkus = $this->get_associated_sku_skiplist();

        // 3. Load WooCommerce SKUs for this distribution term.
        $term_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT t.term_id FROM {$wpdb->terms} t
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'distribution' AND t.slug = %s",
            $term_slug
        ) );
        if ( ! $term_id ) {
            $this->log( "[{$supplier}] Distribution term '{$term_slug}' not found; skipping feed sync." );
            return ['gone' => 0, 'back' => 0];
        }
        $wooSkus = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.meta_value
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->term_relationships} tr ON tr.object_id = pm.post_id
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tt.taxonomy = 'distribution'
               AND tt.term_id = %d
               AND pm.meta_key = %s",
            $term_id, $sku_meta
        ) ) ?: [];
        $this->log( "[{$supplier}] WooCommerce SKUs: " . count( $wooSkus ) );

        // 4. Apply skiplist.
        if ( ! empty( $allGroupSkus ) ) {
            $wooSkus  = array_values( array_diff( $wooSkus,  $allGroupSkus ) );
            $feedSkus = array_values( array_diff( $feedSkus, $allGroupSkus ) );
        }

        // 5. Gone: SKUs present in WC but missing from feed → out of stock.
        $skusGone = array_diff( $wooSkus, $feedSkus );
        foreach ( $skusGone as $sku ) {
            $this->log( "[{$supplier}] Out-of-stock detected: {$sku}" );
        }
        if ( $skusGone ) {
            $this->mark_skus_out_of_stock( $skusGone, $sku_meta );
        }

        // 6. Back: SKUs in both WC and feed → restore in stock (if canonical rules allow).
        // Bulk-fetched via one JOIN instead of a per-SKU $wpdb->get_var() + get_post_meta()
        // pair — see the identical fix/rationale in run_internal(), above.
        $false_values = ['FALSE', 'false', 'False', '0', 0, 'not applicable', 'Not Applicable'];
        $eligibleBack = [];
        $backCandidates = array_values( array_intersect( $wooSkus, $feedSkus ) );
        if ( ! empty( $backCandidates ) ) {
            $sku_list = "'" . implode( "','", array_map( 'esc_sql', $backCandidates ) ) . "'";
            $back_rows = $wpdb->get_results( "
                SELECT pm_sku.meta_value AS sku, pm_canon.meta_value AS canonical
                FROM {$wpdb->postmeta} pm_sku
                LEFT JOIN {$wpdb->postmeta} pm_canon
                    ON pm_canon.post_id = pm_sku.post_id AND pm_canon.meta_key = 'canonical'
                WHERE pm_sku.meta_key = '{$sku_meta}'
                  AND pm_sku.meta_value IN ({$sku_list})
            " );
            foreach ( $back_rows as $row ) {
                $canonical = $row->canonical;
                if ( ! in_array( $canonical, $false_values, true ) ) {
                    $eligibleBack[] = $row->sku;
                } else {
                    $this->log( "[{$supplier}] SKU '{$row->sku}' excluded (canonical=FALSE)" );
                }
            }
        }
        if ( ! empty( $eligibleBack ) ) {
            $quoted = "'" . implode( "','", array_map( 'esc_sql', $eligibleBack ) ) . "'";
            // Exclude products that have a per-product stock override set to force_outofstock.
            // Without this guard the SQL bypasses the Stock_Override_Resolver entirely,
            // causing any flag_match or per-product override to be silently undone.
            $wpdb->query( "
                UPDATE {$wpdb->postmeta} pm_status
                JOIN {$wpdb->postmeta} pm_sku USING(post_id)
                SET pm_status.meta_value = 'instock'
                WHERE pm_status.meta_key   = '_stock_status'
                  AND pm_sku.meta_key       = '{$sku_meta}'
                  AND pm_sku.meta_value     IN ({$quoted})
                  AND pm_status.meta_value != 'instock'
                  AND NOT EXISTS (
                      SELECT 1 FROM {$wpdb->postmeta} pm_ov
                      WHERE pm_ov.post_id   = pm_status.post_id
                        AND pm_ov.meta_key  = '_mmi_stock_override'
                        AND pm_ov.meta_value = 'force_outofstock'
                  )
            " );
            $wpdb->query( "
                UPDATE {$wpdb->postmeta} pm_qty
                JOIN {$wpdb->postmeta} pm_sku USING(post_id)
                SET pm_qty.meta_value = '9999'
                WHERE pm_qty.meta_key   = '_stock'
                  AND pm_sku.meta_key   = '{$sku_meta}'
                  AND pm_sku.meta_value IN ({$quoted})
                  AND NOT EXISTS (
                      SELECT 1 FROM {$wpdb->postmeta} pm_ov
                      WHERE pm_ov.post_id   = pm_qty.post_id
                        AND pm_ov.meta_key  = '_mmi_stock_override'
                        AND pm_ov.meta_value = 'force_outofstock'
                  )
            " );

            // Backfill: create _stock / _stock_status rows for any matched
            // product that has none at all — the two UPDATEs above only ever
            // touch rows that already exist, so a product with no such
            // postmeta was previously silently never given one. Ported from
            // the retired run_internal() (which had this backfill but no
            // override guard); same _mmi_stock_override exclusion as above
            // applies here too, or a force_outofstock product with no
            // pre-existing _stock_status row would be backfilled to instock,
            // reintroducing the exact bug that guard exists to prevent.
            $override_skus = $wpdb->get_col( "
                SELECT pm_sku.meta_value
                FROM {$wpdb->postmeta} pm_sku
                JOIN {$wpdb->postmeta} pm_ov ON pm_ov.post_id = pm_sku.post_id
                WHERE pm_sku.meta_key   = '{$sku_meta}'
                  AND pm_sku.meta_value IN ({$quoted})
                  AND pm_ov.meta_key    = '_mmi_stock_override'
                  AND pm_ov.meta_value  = 'force_outofstock'
            " );
            $backfillEligible = array_values( array_diff( $eligibleBack, $override_skus ) );
            if ( ! empty( $backfillEligible ) ) {
                $backfillQuoted = "'" . implode( "','", array_map( 'esc_sql', $backfillEligible ) ) . "'";
                $post_ids_qty = $wpdb->get_col( "
                    SELECT DISTINCT pm_sku.post_id
                    FROM {$wpdb->postmeta} pm_sku
                    LEFT JOIN {$wpdb->postmeta} pm_qty ON pm_sku.post_id = pm_qty.post_id AND pm_qty.meta_key = '_stock'
                    WHERE pm_sku.meta_key = '{$sku_meta}'
                      AND pm_sku.meta_value IN ({$backfillQuoted})
                      AND pm_qty.meta_id IS NULL
                " );
                foreach ( $post_ids_qty as $pid ) {
                    update_post_meta( (int) $pid, '_stock', '9999' );
                }
                $post_ids_status = $wpdb->get_col( "
                    SELECT DISTINCT pm_sku.post_id
                    FROM {$wpdb->postmeta} pm_sku
                    LEFT JOIN {$wpdb->postmeta} pm_status ON pm_sku.post_id = pm_status.post_id AND pm_status.meta_key = '_stock_status'
                    WHERE pm_sku.meta_key = '{$sku_meta}'
                      AND pm_sku.meta_value IN ({$backfillQuoted})
                      AND pm_status.meta_id IS NULL
                " );
                foreach ( $post_ids_status as $pid ) {
                    update_post_meta( (int) $pid, '_stock_status', 'instock' );
                }
            }

            $this->log( "[{$supplier}] Restored " . count( $eligibleBack ) . " SKUs to in-stock." );

            // Restore search visibility — only for products that are in stock now AND still
            // excluded from search. This used to touch every SKU still in the feed (~6k for
            // Xchange) with a taxonomy write each, on every run: the phase outlived PHP's
            // 45-60 s limit, so every scheduled run died here and the phases after it
            // (bulk custom rules, categories) never ran (2026-09-27).
            $ids_back_instock = $wpdb->get_col( $wpdb->prepare( "
                SELECT DISTINCT pm_sku.post_id
                FROM {$wpdb->postmeta} pm_sku
                JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = pm_sku.post_id AND pm_status.meta_key = '_stock_status' AND pm_status.meta_value = 'instock'
                JOIN {$wpdb->term_relationships} tr ON tr.object_id = pm_sku.post_id
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_visibility'
                JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = %s
                WHERE pm_sku.meta_key = '{$sku_meta}'
                  AND pm_sku.meta_value IN ({$quoted})
            ", 'exclude-from-search' ) );
            foreach ( $ids_back_instock as $pid ) {
                $this->set_catalog_visibility_visible( (int) $pid );
            }
        }

        return ['gone' => count( $skusGone ), 'back' => count( $eligibleBack )];
    }

    /**
     * WP-CLI audit: validate canonical/associated setup without mutating anything.
     * Usage: wp mmi catalog-audit
     */
    public function audit(): void
    {
        if (!(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))) {
            // Fallback logging if someone runs this outside CLI.
            $this->log('Audit is intended for WP-CLI (wp mmi catalog-audit).');
        }

        global $wpdb;

        // 1) Load canonicals
        $canonicalIds = $wpdb->get_col("
            SELECT DISTINCT pm.post_id
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = 'canonical'
              AND pm.meta_value = 'TRUE'
              AND p.post_type = 'product'
        ");

        $totalAssoc = 0;
        $canonWithNoAssoc = [];
        $invalidAssoc = []; // [canonId => [badId, ...]]
        $assocInStock = []; // [assocId => canonId]

        // 2) Inspect each canonical's associations
        foreach ($canonicalIds as $canonId) {
            $canonId = (int) $canonId;

            $assoc = get_post_meta($canonId, 'canonical_associated_products', true);
            if (empty($assoc)) $assoc = get_post_meta($canonId, 'associated_products', true);
            if (empty($assoc)) $assoc = get_post_meta($canonId, 'associated_product_ids', true);

            $validIds = [];
            $badIds = [];

            $extractAndValidate = function($raw) use ($canonId, &$validIds, &$badIds) {
                $pushIfValid = function($id) use ($canonId, &$validIds, &$badIds) {
                    $id = intval($id);
                    if (!$id || $id === $canonId) {
                        if ($id) $badIds[] = $id;
                        return;
                    }
                    $ptype = get_post_type($id);
                    $pstat = get_post_status($id);
                    if ($ptype === 'product' && in_array($pstat, ['publish','private'], true)) {
                        $validIds[] = $id;
                    } else {
                        $badIds[] = $id;
                    }
                };

                if (is_array($raw)) {
                    foreach ($raw as $row) {
                        if (is_array($row)) {
                            $pushIfValid($row['associated_product_id'] ?? $row['product_id'] ?? $row['ID'] ?? $row['id'] ?? 0);
                        } elseif (is_object($row)) {
                            $pushIfValid($row->associated_product_id ?? $row->product_id ?? $row->ID ?? $row->id ?? 0);
                        } else {
                            $pushIfValid($row);
                        }
                    }
                } elseif (is_string($raw) && trim($raw) !== '') {
                    $parts = preg_split('/[,\|\s]+/', $raw);
                    foreach ($parts as $part) {
                        $pushIfValid($part);
                    }
                }
            };

            $extractAndValidate($assoc);

            $validIds = array_values(array_unique(array_map('intval', $validIds)));
            $badIds   = array_values(array_unique(array_map('intval', $badIds)));

            if (empty($validIds)) {
                $canonWithNoAssoc[] = $canonId;
            }

            if (!empty($badIds)) {
                $invalidAssoc[$canonId] = $badIds;
            }

            // Check stock on valid associated products (for awareness)
            foreach ($validIds as $aid) {
                $status = get_post_meta($aid, '_stock_status', true);
                if ($status === 'instock') {
                    $assocInStock[$aid] = $canonId;
                }
            }

            $totalAssoc += count($validIds);
        }

        // 3) Report
        $canonCount = count($canonicalIds);
        $invalidCount = array_reduce($invalidAssoc, fn($c,$arr) => $c + count($arr), 0);
        $instockCount = count($assocInStock);

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::log("=== Catalog Audit ===");
            \WP_CLI::log("Canonical products: {$canonCount}");
            \WP_CLI::log("Total associated references: {$totalAssoc}");
            \WP_CLI::log("Canonicals with NO associated IDs: " . count($canonWithNoAssoc));
            \WP_CLI::log("Invalid associated IDs: {$invalidCount}");
            \WP_CLI::log("Associated products currently IN STOCK: {$instockCount}");

            if (!empty($canonWithNoAssoc)) {
                \WP_CLI::log("\n-- Canonicals missing associates --");
                foreach ($canonWithNoAssoc as $cid) {
                    \WP_CLI::log("  #{$cid} " . get_the_title($cid));
                }
            }

            if (!empty($invalidAssoc)) {
                \WP_CLI::log("\n-- Invalid associated IDs --");
                foreach ($invalidAssoc as $cid => $ids) {
                    \WP_CLI::log("  Canonical #{$cid} " . get_the_title($cid) . ': ' . implode(', ', $ids));
                }
            }

            if (!empty($assocInStock)) {
                \WP_CLI::log("\n-- Associated products currently IN STOCK (FYI) --");
                foreach ($assocInStock as $aid => $cid) {
                    \WP_CLI::log("  Assoc #{$aid} (" . get_the_title($aid) . ") for Canonical #{$cid} (" . get_the_title($cid) . ")");
                }
            }

            \WP_CLI::success('Audit complete.');
        } else {
            $this->log("Audit complete. Canonicals={$canonCount}, totalAssoc={$totalAssoc}, noAssoc=" . count($canonWithNoAssoc) . ", invalid={$invalidCount}, assocInStock={$instockCount}");
        }
    }

    /**
     * WP-CLI command wrapper.
     */
    public static function cli_audit($args, $assoc_args): void
    {
        $self = new self();
        $self->audit();
    }

    /**
     * WP-CLI wrapper to invoke the WordPress-based brand assignment scanner.
     * Now scans all WordPress products directly (not JSON feed), processing ALL products
     * regardless of stock_status.
     * 
     * Usage: wp mmi assign-brands --prefix=all [--apply] [--batch-size=200]
     *        wp mmi assign-brands --prefix=x --apply (Xchange only)
     *        wp mmi assign-brands --prefix=s --apply (SkuPort only)
     * 
     * @param string $prefix Distribution: 'all', 'x' (xchange), or 's' (skuport)
     */
    public static function cli_assign_brands($args, $assoc_args): void
    {
        $prefix = isset($assoc_args['prefix']) ? $assoc_args['prefix'] : 'all';
        $apply = isset($assoc_args['apply']) && ($assoc_args['apply'] === '1' || $assoc_args['apply'] === true || $assoc_args['apply'] === 'true');
        $batchSize = isset($assoc_args['batch-size']) ? intval($assoc_args['batch-size']) : (isset($assoc_args['batch']) ? intval($assoc_args['batch']) : 50);

        $self = new self();
        try {
            $self->assign_brands_from_sku_prefix($prefix, $apply, $batchSize);
            if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
                \WP_CLI::success('assign-brands completed. Check logs and scripts/ for CSV artifacts.');
            } else {
                $self->log('assign-brands completed. Check logs and scripts/ for CSV artifacts.');
            }
        } catch (\Exception $e) {
            if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
                \WP_CLI::error('assign-brands failed: ' . $e->getMessage());
            } else {
                $self->log('assign-brands failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Run the scripts/assign_categories_from_wordpress.php scanner via PHP CLI.
     * This delegates to the standalone comprehensive category scanner that analyzes
     * titles, descriptions, and metadata to assign appropriate product categories.
     *
     * @param string $scope 'all' or 'uncategorized' (default: uncategorized)
     * @param bool $apply Whether to pass --apply (will attempt write operations)
     * @param int $batchSize Batch size when running --apply
     */
    public function assign_categories_comprehensive(string $scope = 'uncategorized', bool $apply = false, int $batchSize = 50): array
    {
        $script = self::scanner_script('assign_categories_from_wordpress.php');
        if ($script === null) {
            $this->log('Category scanner (scripts/assign_categories_from_wordpress.php) is not installed on this site; skipping.');
            return ['assigned' => 0, 'skipped' => true];
        }

        // Locate a working PHP CLI binary
        $candidates = [];
        if (defined('PHP_BINARY')) $candidates[] = PHP_BINARY;
        $candidates = array_merge($candidates, ['php', '/usr/bin/php', '/usr/local/bin/php']);

        $php = null;

        $shellArg = function(string $s) {
            if (function_exists('escapeshellarg')) return escapeshellarg($s);
            return "'" . str_replace("'", "'\"'\"'", $s) . "'";
        };

        foreach ($candidates as $cand) {
            $cand = trim((string)$cand);
            if ($cand === '') continue;
            $probeCmd = $cand . ' -r ' . $shellArg('echo PHP_SAPI;');
            $probeOut = null;
            $probeExit = 1;
            @exec($probeCmd . ' 2>&1', $probeOut, $probeExit);
            $probeStr = is_array($probeOut) ? trim(implode("\n", $probeOut)) : trim((string)$probeOut);
            if ($probeExit === 0 && $probeStr === 'cli') {
                $php = $cand;
                break;
            }
        }

        if ($php === null) {
            $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
            $this->log('Warning: could not find explicit php-cli binary via probes; falling back to ' . $php);
        } else {
            $this->log('Using PHP CLI binary: ' . $php);
        }


        // Build command
        $cmd = $php . ' ' . $shellArg($script) . ' ' . $shellArg($scope);
        if ($apply) {
            $cmd .= ' --apply';
        }
        $cmd .= ' --batch-size=' . intval($batchSize);

        // Log and execute
        $this->log("Executing category scanner command: {$cmd}");
        $output = [];
        $exit = 0;
        @exec($cmd . ' 2>&1', $output, $exit);
        if (is_array($output)) {
            foreach ($output as $line) {
                $this->log('[category-scanner] ' . $line);
            }
        }
        if ($exit !== 0) {
            throw new \RuntimeException('Category scanner process exited with code ' . intval($exit) . '. See logs for details.');
        }

        // Parse the scanner's own summary line for a real count instead of
        // returning nothing — see scripts/assign_categories_from_wordpress.php:269
        // ("Applied:       N"). In dry-run mode this is the number of
        // proposed-but-not-applied assignments, which is still a meaningful
        // count to surface.
        $assigned = 0;
        $outputText = is_array($output) ? implode("\n", $output) : (string) $output;
        if (preg_match('/^Applied:\s*(\d+)/mi', $outputText, $m)) {
            $assigned = (int) $m[1];
        }

        return ['assigned' => $assigned];
    }

    /**
     * WP-CLI wrapper to invoke the WordPress-based comprehensive category assignment scanner.
     * Analyzes titles, descriptions, and metadata to assign categories.
     * 
     * Usage: wp mmi assign-categories --scope=uncategorized [--apply] [--batch-size=200]
     *        wp mmi assign-categories --scope=all --apply (all products)
     * 
     * @param string $scope 'all' or 'uncategorized'
     */
    public static function cli_assign_categories($args, $assoc_args): void
    {
        $scope = isset($assoc_args['scope']) ? $assoc_args['scope'] : 'uncategorized';
        $apply = isset($assoc_args['apply']) && ($assoc_args['apply'] === '1' || $assoc_args['apply'] === true || $assoc_args['apply'] === 'true');
        $batchSize = isset($assoc_args['batch-size']) ? intval($assoc_args['batch-size']) : (isset($assoc_args['batch']) ? intval($assoc_args['batch']) : 50);

        $self = new self();
        try {
            $self->assign_categories_comprehensive($scope, $apply, $batchSize);
            if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
                \WP_CLI::success('assign-categories completed. Check logs and scripts/ for CSV artifacts.');
            } else {
                $self->log('assign-categories completed. Check logs and scripts/ for CSV artifacts.');
            }
        } catch (\Exception $e) {
            if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
                \WP_CLI::error('assign-categories failed: ' . $e->getMessage());
            } else {
                $self->log('assign-categories failed: ' . $e->getMessage());
            }
        }
    }
}
// Register WP-CLI command: wp mmi catalog-audit
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    \WP_CLI::add_command('mmi catalog-audit', [MMI_Pipeline_Catalog_Updater::class, 'cli_audit']);
    \WP_CLI::add_command('mmi assign-brands', [MMI_Pipeline_Catalog_Updater::class, 'cli_assign_brands']);
    \WP_CLI::add_command('mmi assign-categories', [MMI_Pipeline_Catalog_Updater::class, 'cli_assign_categories']);
}