<?php
/**
 * Taxonomy Mapping Handler
 *
 * Post-save taxonomy term assignment (brand/category mapping tables) and
 * profile identifier scope filtering/auto-tagging. Extracted from
 * MMI_Dynamic_Product_Importer as part of the god-class decomposition.
 *
 * @package MannMade\DataPipeline\Importers
 */

namespace MannMade\DataPipeline\Importers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Taxonomy_Mapping_Handler {

    /** @var array */
    protected $field_mappings;

    /** @var string */
    protected $supplier_name;

    /** @var string  'all_products' or 'by_identifier' */
    protected $product_scope;

    /** @var array|null */
    protected $product_identifier;

    /** @var string Import Profile ID — see resolve_tax_mapping()'s profile-aware tiering. */
    protected $profile_id;

    /**
     * Every saved mapping row, indexed [source_field][lowercased source_value],
     * loaded once per request for find_redirect(). The table is small (a few
     * hundred rows) and an import calls find_redirect() once per taxonomy
     * field per product, so one read beats a query per call.
     *
     * @var array|null
     */
    private static $mapping_rows_by_value = null;

    /**
     * Per-source Taxonomy Mapping toggle (2026-08-31) — resolved once here
     * (one instance per supplier per import run) rather than re-checked per
     * product in apply_taxonomy_mappings(), which runs once per product in
     * this supplier's batch. See MMI_Pipeline_Admin::get_configured_suppliers()'s
     * 'taxonomy_mapping_enabled' key.
     *
     * @var bool
     */
    protected $taxonomy_mapping_enabled;

    public function __construct( array $field_mappings, string $supplier_name, string $product_scope, ?array $product_identifier, string $profile_id = '' ) {
        $this->field_mappings      = $field_mappings;
        $this->supplier_name       = $supplier_name;
        $this->product_scope       = $product_scope;
        $this->product_identifier  = $product_identifier;
        $this->profile_id          = $profile_id;

        $configured = class_exists( '\\MMI_Pipeline_Admin' ) ? \MMI_Pipeline_Admin::get_configured_suppliers() : [];
        $this->taxonomy_mapping_enabled = ! isset( $configured[ $supplier_name ] ) || ( $configured[ $supplier_name ]['taxonomy_mapping_enabled'] ?? true );
    }

    /**
     * Check whether a product belongs to this profile's identifier scope.
     *
     * Returns true when:
     * - product_scope is 'all_products' (no filtering), or
     * - identifier config is missing / incomplete, or
     * - the product genuinely matches the taxonomy term / post_meta condition.
     *
     * @param int $product_id WooCommerce product post ID.
     * @return bool
     */
    public function product_matches_identifier( $product_id ) {
        if ( $this->product_scope !== 'by_identifier' || empty( $this->product_identifier ) ) {
            return true;
        }

        $type   = $this->product_identifier['storage_type'] ?? '';
        $config = $this->product_identifier['storage_config'] ?? [];

        if ( $type === 'taxonomy' ) {
            $slug        = $config['taxonomy_slug'] ?? '';
            $term_lookup = $config['term_slug_or_id'] ?? '';
            if ( ! $slug || ! $term_lookup ) {
                return true; // incomplete config — don't block
            }

            // term_slug_or_id is comma-separated when the admin UI's multi-select term
            // picker is used (see import-settings.js: "store as comma-separated for
            // back-compat") — a product matches if it has ANY of the listed terms.
            $lookups = array_filter( array_map( 'trim', explode( ',', (string) $term_lookup ) ) );
            if ( empty( $lookups ) ) {
                return true;
            }

            foreach ( $lookups as $lookup ) {
                $term = is_numeric( $lookup )
                    ? get_term( (int) $lookup, $slug )
                    : get_term_by( 'slug', $lookup, $slug );
                if ( $term && ! is_wp_error( $term ) && has_term( $term->term_id, $slug, $product_id ) ) {
                    return true;
                }
            }

            return false;
        }

        if ( $type === 'post_meta' ) {
            $key = $config['meta_key'] ?? '';
            $val = $config['meta_value'] ?? '';
            if ( ! $key ) {
                return true; // incomplete config — don't block
            }
            return (string) get_post_meta( $product_id, $key, true ) === (string) $val;
        }

        return true; // unknown type — don't block
    }

    /**
     * Apply this profile's identifier (taxonomy term or post_meta) to a
     * newly created product, when auto_apply_to_new is enabled.
     *
     * @param int $product_id WooCommerce product post ID.
     */
    public function apply_identifier_to_product( $product_id ) {
        if ( $this->product_scope !== 'by_identifier' || empty( $this->product_identifier ) ) {
            return;
        }
        if ( empty( $this->product_identifier['auto_apply_to_new'] ) ) {
            return;
        }

        $type   = $this->product_identifier['storage_type'] ?? '';
        $config = $this->product_identifier['storage_config'] ?? [];

        if ( $type === 'taxonomy' ) {
            $slug = $config['taxonomy_slug'] ?? '';
            $term = $config['term_slug_or_id'] ?? '';
            if ( $slug && $term ) {
                wp_set_object_terms( $product_id, $term, $slug, false );
            }
        } elseif ( $type === 'post_meta' ) {
            $key = $config['meta_key'] ?? '';
            $val = $config['meta_value'] ?? '';
            if ( $key ) {
                update_post_meta( $product_id, $key, $val );
            }
        }
    }

    /**
     * Store raw source values as postmeta and apply any saved taxonomy
     * mappings (brand → product_brand, etc.) to the given product.
     *
     * Called after create_product() / update_product() for every processed item.
     *
     * @param int   $product_id   WC product post ID.
     * @param array $product_data Mapped product data (keyed by WC field name).
     */
    public function apply_taxonomy_mappings( int $product_id, array $product_data ): void {
        if ( ! $this->taxonomy_mapping_enabled ) {
            return;
        }

        $locked = \MMI_Pipeline_Field_Locks::get( $product_id );

        // Which source field feeds which taxonomy, worked out before the
        // main loop: find_redirect() needs every taxonomy a field feeds
        // directly, so a mapping in one of them is never mistaken for a
        // redirect.
        $field_sources  = [];
        $own_taxonomies = [];
        foreach ( $this->field_mappings as $wc_field => $config ) {
            // Only process registered taxonomies (product_brand, product_cat, etc.)
            if ( ! taxonomy_exists( $wc_field ) ) {
                continue;
            }
            $source_field = is_array( $config['source'] ?? '' )
                ? ( $config['source'][ $this->supplier_name ] ?? '' )
                : ( $config['source'] ?? '' );
            if ( empty( $source_field ) || $source_field === 'NULL' || $source_field === 'null' ) {
                continue;
            }
            $field_sources[ $wc_field ]        = $source_field;
            $own_taxonomies[ $source_field ][] = (string) $wc_field;
        }

        // Terms from redirected values, added after the loop (see below).
        $redirected = [];

        foreach ( $field_sources as $wc_field => $source_field ) {
            // A lock on this field's own taxonomy (e.g. a brand set by hand in
            // the Product Workbench) blocks only the write to that taxonomy;
            // a redirect below goes to a different one and checks its own lock.
            $own_locked = \MMI_Pipeline_Field_Locks::in_list( (string) $wc_field, $locked );

            $mapped_value = $product_data[ $wc_field ] ?? '';

            // This single-value resolver (one source value → one term) can't handle a
            // source field that maps to an array (e.g. a feed's multi-category list).
            // Casting an array to string previously produced the literal text "Array",
            // which got written into postmeta and fed into resolve_tax_mapping() as
            // garbage — skip cleanly instead until multi-value taxonomy mapping exists.
            if ( is_array( $mapped_value ) ) {
                continue;
            }

            $raw_value = (string) $mapped_value;
            if ( $raw_value === '' ) {
                continue;
            }

            // Store raw source value so the batch-apply tool can find this product later.
            // Meta key must match TaxonomyMappingController.php's mmi_apply_taxonomy_mappings
            // handler, which normalizes a compound '+' field the same way before its own
            // lookup — a literal '+' here would never match that sanitized key.
            if ( ! $own_locked ) {
                update_post_meta( $product_id, '_mmi_src_' . str_replace( '+', '_', $source_field ), $raw_value );
            }

            // Bad supplier data: a value that belongs to another taxonomy
            // (Xchange sends "BUNDLES" as a brand). Its row in the Taxonomy
            // Mapping table was pointed at that taxonomy, so this field's own
            // taxonomy is left untouched and the term is added over there.
            $redirect = self::find_redirect(
                $this->supplier_name,
                $source_field,
                $raw_value,
                $own_taxonomies[ $source_field ],
                $this->profile_id
            );
            if ( $redirect !== null ) {
                if ( ! \MMI_Pipeline_Field_Locks::in_list( $redirect['wc_taxonomy'], $locked ) ) {
                    $redirected[ $redirect['wc_taxonomy'] ][] = (int) $redirect['wc_term_id'];
                }
                continue;
            }
            if ( $own_locked ) {
                continue;
            }

            // Resolve taxonomy mapping (this profile+supplier, then supplier
            // global, then profile-any-supplier, then fully global — see
            // MMI_DB::resolve_tax_mapping()'s tiering)
            $term_id = \MMI_DB::resolve_tax_mapping(
                $this->supplier_name,
                $source_field,
                $raw_value,
                $wc_field,
                $this->profile_id
            );

            if ( $term_id && $term_id > 0 ) {
                wp_set_object_terms( $product_id, [ $term_id ], $wc_field );
            }
        }

        // Appended, and only after every field's own replace above, so a
        // field that sets the same taxonomy can't wipe the redirected term.
        foreach ( $redirected as $taxonomy => $term_ids ) {
            wp_set_object_terms( $product_id, array_values( array_unique( $term_ids ) ), $taxonomy, true );
        }
    }

    /**
     * Apply one just-saved mapping to the existing products that carry its
     * source value. An import only applies a mapping to products it writes,
     * and its hash gate skips products whose feed data hasn't changed, so a
     * mapping saved after a product's import otherwise never reaches it
     * (2026-10-02: 60 new Steinberg products imported at 16:42 stayed
     * brandless after VST INSTRUMENTS etc. were mapped at 16:43).
     *
     * Products are found by the raw value apply_taxonomy_mappings() stores
     * in _mmi_src_{field}, limited to $supplier_id's products. A field lock
     * on the taxonomy is respected. In one of $own_taxonomies the term
     * replaces the taxonomy's terms, as an import does; anywhere else (a
     * redirect) it is appended.
     *
     * @param string[] $own_taxonomies Taxonomies this source field feeds directly.
     * @return array{updated:int, already:int, locked:int, too_many:int}
     */
    public static function apply_saved_mapping(
        string $supplier_id,
        string $source_field,
        string $source_value,
        string $wc_taxonomy,
        int $term_id,
        array $own_taxonomies,
        int $limit = 2000
    ): array {
        global $wpdb;
        $out = [ 'updated' => 0, 'already' => 0, 'locked' => 0, 'too_many' => 0 ];

        $term = get_term( $term_id, $wc_taxonomy );
        if ( $term_id <= 0 || $source_value === '' || ! $term || is_wp_error( $term ) ) {
            return $out;
        }

        $src_key = '_mmi_src_' . str_replace( '+', '_', $source_field );
        if ( $supplier_id !== '' ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m
                 JOIN {$wpdb->postmeta} s ON s.post_id = m.post_id AND s.meta_key = %s
                 WHERE m.meta_key = %s AND m.meta_value = %s",
                '_mmi_supplier_sku_' . $supplier_id,
                $src_key,
                $source_value
            ) );
        } else {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                $src_key,
                $source_value
            ) );
        }

        if ( count( $ids ) > $limit ) {
            $out['too_many'] = count( $ids );
            return $out;
        }

        $append = ! in_array( $wc_taxonomy, $own_taxonomies, true );
        foreach ( $ids as $pid ) {
            $pid = (int) $pid;
            if ( \MMI_Pipeline_Field_Locks::in_list( $wc_taxonomy, \MMI_Pipeline_Field_Locks::get( $pid ) ) ) {
                $out['locked']++;
                continue;
            }
            $current = wp_get_object_terms( $pid, $wc_taxonomy, [ 'fields' => 'ids' ] );
            $current = is_wp_error( $current ) ? [] : array_map( 'intval', $current );
            if ( $append ? in_array( $term_id, $current, true ) : $current === [ $term_id ] ) {
                $out['already']++;
                continue;
            }
            wp_set_object_terms( $pid, [ $term_id ], $wc_taxonomy, $append );
            $out['updated']++;
        }

        return $out;
    }

    /** Action Scheduler hook for the background pass below. */
    public const HEAL_HOOK = 'mmi_pipeline_taxmap_heal';

    /** Action Scheduler group, shared with the export batches. */
    public const HEAL_GROUP = 'mmi-data-pipeline';

    /** Seconds one heal action works before handing the rest to the next. */
    private const HEAL_BUDGET_SECONDS = 40;

    /**
     * Queue a background pass that gives existing products the term Taxonomy
     * Mapping now resolves for their stored source value, for the two cases
     * saving a mapping row cannot cover itself:
     *  - mode 'rules': an alias rule was saved. Only values with no saved row
     *    at any tier (so resolved by a rule, if at all) are applied; a saved
     *    row was already applied when it was saved.
     *  - mode 'value': one saved row matched more products than
     *    apply_saved_mapping()'s on-save limit.
     * An import leaves an unmapped category unassigned (see
     * MMI_Pipeline_Field_Resolver::resolve_unmapped_term_ids()), so this and
     * the on-save apply are what file those products once the value is mapped.
     *
     * @param array $args ['mode' => 'rules'] or ['mode' => 'value',
     *                    'supplier_id', 'source_field', 'source_value',
     *                    'wc_taxonomy', 'term_id'].
     * @return bool Whether an action is queued (already-queued counts).
     */
    public static function schedule_heal( array $args ): bool {
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            return false;
        }
        if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HEAL_HOOK, [ $args ], self::HEAL_GROUP ) ) {
            return true;
        }
        $priority = defined( 'MMI_PIPELINE_AS_PRIORITY_BATCH' ) ? MMI_PIPELINE_AS_PRIORITY_BATCH : 10;
        return (bool) as_enqueue_async_action( self::HEAL_HOOK, [ $args ], self::HEAL_GROUP, false, $priority );
    }

    /**
     * Action Scheduler callback for schedule_heal().
     *
     * @param array $args See schedule_heal(); 'rules' mode also carries 'cursor'.
     */
    public static function run_heal( $args ): void {
        $args = is_array( $args ) ? $args : [];
        @set_time_limit( 120 );

        if ( ( $args['mode'] ?? '' ) === 'value' ) {
            $own = self::own_taxonomies_for( (string) ( $args['supplier_id'] ?? '' ), (string) ( $args['source_field'] ?? '' ) );
            $out = self::apply_saved_mapping(
                (string) ( $args['supplier_id'] ?? '' ),
                (string) ( $args['source_field'] ?? '' ),
                (string) ( $args['source_value'] ?? '' ),
                (string) ( $args['wc_taxonomy'] ?? '' ),
                (int) ( $args['term_id'] ?? 0 ),
                $own ?: [ (string) ( $args['wc_taxonomy'] ?? '' ) ],
                PHP_INT_MAX
            );
            self::log_heal( 'value', $out, $args );
            return;
        }

        if ( ( $args['mode'] ?? '' ) !== 'rules' ) {
            return;
        }

        $work   = self::rule_resolved_values();
        $cursor = max( 0, (int) ( $args['cursor'] ?? 0 ) );
        $start  = microtime( true );
        $total  = [ 'updated' => 0, 'already' => 0, 'locked' => 0, 'too_many' => 0 ];

        for ( $i = $cursor, $n = count( $work ); $i < $n; $i++ ) {
            if ( microtime( true ) - $start > self::HEAL_BUDGET_SECONDS ) {
                self::log_heal( 'rules', $total, [ 'cursor' => $cursor, 'stopped_at' => $i, 'of' => $n ] );
                self::schedule_heal( [ 'mode' => 'rules', 'cursor' => $i ] );
                return;
            }
            $w   = $work[ $i ];
            $out = self::apply_saved_mapping( $w['supplier_id'], $w['source_field'], $w['source_value'], $w['wc_taxonomy'], $w['term_id'], $w['own'], PHP_INT_MAX );
            foreach ( $total as $k => $v ) {
                $total[ $k ] = $v + (int) ( $out[ $k ] ?? 0 );
            }
        }

        self::log_heal( 'rules', $total, [ 'cursor' => $cursor, 'of' => count( $work ) ] );
    }

    /**
     * Every (source, value) that existing products carry in _mmi_src_{field}
     * and that only an alias rule resolves: no saved row for the value at any
     * tier, and a rule pointing at a live term. Sorted so a 'rules' pass can
     * resume from a cursor.
     *
     * @return array<int, array{supplier_id:string, source_field:string, source_value:string, wc_taxonomy:string, term_id:int, own:string[]}>
     */
    private static function rule_resolved_values(): array {
        global $wpdb;

        if ( ! class_exists( '\\MMI_Pipeline_Field_Mapping_Defaults' ) ) {
            return [];
        }

        $saved = [];
        foreach ( \MMI_DB::get_tax_mappings() as $row ) {
            $saved[ $row['supplier_id'] . '|' . $row['source_field'] . '|' . $row['wc_taxonomy'] . '|' . mb_strtolower( (string) $row['source_value'] ) ] = true;
        }

        $work = [];
        $seen = [];
        foreach ( \MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_source_fields() as $src ) {
            $supplier = (string) $src['supplier'];
            $field    = (string) $src['source_field'];
            $taxonomy = (string) $src['wc_taxonomy'];
            $key      = $supplier . '|' . $field . '|' . $taxonomy;
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

            $values = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m
                 JOIN {$wpdb->postmeta} s ON s.post_id = m.post_id AND s.meta_key = %s
                 WHERE m.meta_key = %s AND m.meta_value <> ''
                 ORDER BY m.meta_value",
                '_mmi_supplier_sku_' . $supplier,
                '_mmi_src_' . str_replace( '+', '_', $field )
            ) );

            $own = self::own_taxonomies_for( $supplier, $field );
            foreach ( $values as $value ) {
                $value = (string) $value;
                $lower = mb_strtolower( $value );
                if ( isset( $saved[ $supplier . '|' . $field . '|' . $taxonomy . '|' . $lower ] )
                    || isset( $saved[ '|' . $field . '|' . $taxonomy . '|' . $lower ] ) ) {
                    continue;
                }
                $term_id = \MMI_DB::match_taxmap_alias_rule( $supplier, $field, $value, $taxonomy );
                if ( $term_id === null || $term_id <= 0 ) {
                    continue;
                }
                $work[] = [
                    'supplier_id'  => $supplier,
                    'source_field' => $field,
                    'source_value' => $value,
                    'wc_taxonomy'  => $taxonomy,
                    'term_id'      => (int) $term_id,
                    'own'          => $own ?: [ $taxonomy ],
                ];
            }
        }

        return $work;
    }

    /**
     * Taxonomies a supplier's source field feeds directly, same derivation
     * as the on-save apply in TaxonomyMappingController.php.
     *
     * @return string[]
     */
    private static function own_taxonomies_for( string $supplier_id, string $source_field ): array {
        if ( ! class_exists( '\\MMI_Pipeline_Field_Mapping_Defaults' ) ) {
            return [];
        }
        $own = [];
        foreach ( \MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_source_fields() as $src ) {
            if ( $src['source_field'] === $source_field && ( $supplier_id === '' || $src['supplier'] === $supplier_id ) ) {
                $own[] = (string) $src['wc_taxonomy'];
            }
        }
        return array_values( array_unique( $own ) );
    }

    private static function log_heal( string $mode, array $out, array $context ): void {
        \MMI_Logger::info(
            "Taxonomy Mapping background apply ({$mode}): {$out['updated']} updated, {$out['already']} already set, {$out['locked']} locked",
            array_merge( $out, $context ),
            'sync',
            'Taxonomy_Mapping_Handler'
        );
        if ( (int) ( $out['updated'] ?? 0 ) > 0 && function_exists( 'mmi_data_pipeline_audit' ) ) {
            mmi_data_pipeline_audit( 'taxonomy.apply', [
                'object_type' => 'taxonomy_mapping',
                'object_id'   => (string) ( $context['supplier_id'] ?? 'rules' ),
                'outcome'     => 'success',
                'details'     => [ 'background' => $mode, 'result' => $out ],
            ] );
        }
    }

    /**
     * Find a saved mapping that sends a source value to a different taxonomy
     * than the one its field feeds: the Taxonomy Mapping table's per-row
     * taxonomy select, used when a supplier puts a value in the wrong field
     * (Xchange "BUNDLES" in brand, sent to Product categories). The row is
     * stored under (source_field = brand, wc_taxonomy = product_cat), a
     * pair that resolve_tax_mapping() never asks about, so before this an
     * import silently ignored every redirect.
     *
     * Uses resolve_tax_mapping()'s tiers (supplier+profile, supplier,
     * profile, global); a row in one of $own_taxonomies at the same or a
     * more specific tier wins over a redirect. Redirect rows that skip (-1)
     * or have no term are ignored.
     *
     * @param string[] $own_taxonomies Taxonomies this source field feeds directly.
     * @param bool     $live_term_only false = also return a redirect whose term
     *                                 was deleted (the admin table shows it as
     *                                 broken; an import must not use it).
     * @return array|null The mapping row, or null.
     */
    public static function find_redirect(
        string $supplier_id,
        string $source_field,
        string $source_value,
        array $own_taxonomies,
        string $profile_id = '',
        bool $live_term_only = true
    ): ?array {
        if ( self::$mapping_rows_by_value === null ) {
            self::$mapping_rows_by_value = [];
            foreach ( \MMI_DB::get_tax_mappings() as $row ) {
                // Lowercased: the SQL lookups in resolve_tax_mapping() compare
                // under a case-insensitive collation, so this must too.
                self::$mapping_rows_by_value[ $row['source_field'] ][ mb_strtolower( (string) $row['source_value'] ) ][] = $row;
            }
        }

        $rows = self::$mapping_rows_by_value[ $source_field ][ mb_strtolower( $source_value ) ] ?? [];
        if ( ! $rows ) {
            return null;
        }

        $tiers = [];
        foreach ( [ [ $supplier_id, $profile_id ], [ $supplier_id, '' ], [ '', $profile_id ], [ '', '' ] ] as $pair ) {
            if ( ! in_array( $pair, $tiers, true ) ) {
                $tiers[] = $pair;
            }
        }

        foreach ( $tiers as [ $sid, $pid ] ) {
            $redirect = null;
            foreach ( $rows as $row ) {
                if ( ( $row['supplier_id'] ?? '' ) !== $sid || ( $row['profile_id'] ?? '' ) !== $pid ) {
                    continue;
                }
                $tid = (int) $row['wc_term_id'];
                if ( in_array( $row['wc_taxonomy'], $own_taxonomies, true ) ) {
                    if ( $tid !== 0 || ! empty( $row['auto_create'] ) ) {
                        return null; // The field's own mapping wins.
                    }
                    continue;
                }
                if ( $tid <= 0 || $redirect !== null ) {
                    continue;
                }
                if ( $live_term_only ) {
                    $term = get_term( $tid, $row['wc_taxonomy'] );
                    if ( ! $term || is_wp_error( $term ) ) {
                        continue;
                    }
                }
                $redirect = $row;
            }
            if ( $redirect !== null ) {
                return $redirect;
            }
        }

        return null;
    }
}

add_action( Taxonomy_Mapping_Handler::HEAL_HOOK, [ Taxonomy_Mapping_Handler::class, 'run_heal' ] );
