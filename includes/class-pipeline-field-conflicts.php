<?php
/**
 * Field Conflicts — two automated writers fighting over one product field
 *
 * Every automated writer of product data in this plugin is listed here once:
 * each update-capable import profile's mapped fields, Catalog Maintenance's
 * built-in stock-status phases, and each enabled Custom Rule's action. When
 * two of them set the same field on the same supplier's products to
 * different values, each run undoes the other and every product is re-saved
 * forever. That is how 2026-09-29 → 2026-10-03 went: the Pricing profile set
 * _stock to 100, Catalog Maintenance set it back to 9999, and ~5,100
 * products were re-saved every hour (~80k background jobs a day).
 *
 * Used where such a conflict is created — the field-mapping autosave and the
 * Custom Rules save — so the user is told at that moment, beside the field.
 *
 * Deliberately NOT conflicts:
 * - Custom Rule stock overrides (force_instock/force_outofstock) against a
 *   profile's _stock/_stock_status mapping: the importer resolves those same
 *   overrides itself before writing (process_item()), so both agree.
 * - Catalog Maintenance's restock quantity: it only fills a missing or zero
 *   _stock (see MMI_Pipeline_Catalog_Updater::ensure_restock_quantity()).
 * - Two profiles mapping the same field the same way (same source column or
 *   same constant): they write the same value.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Field_Conflicts {

    /** Mapping keys that write the same postmeta as another key. */
    private const KEY_ALIASES = [
        'regular_price'  => '_regular_price',
        'sale_price'     => '_sale_price',
        'price'          => '_price',
        'stock_quantity' => '_stock',
        'stock_status'   => '_stock_status',
        'sku'            => '_sku',
        'name'           => 'post_title',
        'description'    => 'post_content',
        'short_description' => 'post_excerpt',
    ];

    /** Fields Catalog Maintenance's built-in phases write on every run, for every supplier. */
    private const CATALOG_PHASE_WRITES = [
        '_stock_status' => 'Catalog Maintenance sets stock status from feed presence on every run',
    ];

    /** Custom Rule action => fields it writes ('{param}' entries come from action_params). */
    private const RULE_ACTION_WRITES = [
        'set_stock_status_backorder' => [ '_stock_status' ],
        'set_backorders_yes'         => [ '_backorders' ],
        'set_backorders_notify'      => [ '_backorders' ],
        'set_backorders_no'          => [ '_backorders' ],
        'enable_manage_stock'        => [ '_manage_stock' ],
        'disable_manage_stock'       => [ '_manage_stock' ],
        'set_status_publish'         => [ 'post_status' ],
        'set_status_draft'           => [ 'post_status' ],
        'set_status_pending'         => [ 'post_status' ],
        'set_taxonomy_term'          => [ '{taxonomy}' ],
        'set_regular_price'          => [ '_regular_price' ],
        'set_sale_price'             => [ '_sale_price' ],
        'clear_sale_price'           => [ '_sale_price' ],
        'set_sale_dates'             => [ '_sale_price_dates_from', '_sale_price_dates_to' ],
        'copy_source_field_to_meta'  => [ '{meta_key}' ],
    ];

    /**
     * Conflicts for every field one import profile maps.
     *
     * @param string   $profile_id
     * @param string[] $draft_sources Sources ticked in the Create Profile wizard, used
     *                                only while the profile has no saved sources yet.
     * @return array<string, string[]> mapping field key => messages
     */
    public static function for_profile( string $profile_id, array $draft_sources = [] ): array {
        $writers = self::profile_writers( $profile_id, $draft_sources );
        $mine    = array_filter( $writers, static fn( $w ) => $w['profile'] === $profile_id );
        if ( ! $mine ) {
            return [];
        }
        $rules = self::rule_writers();
        $out   = [];
        foreach ( $mine as $w ) {
            $messages = [];
            if ( isset( self::CATALOG_PHASE_WRITES[ $w['key'] ] ) ) {
                $messages[] = sprintf( '%s, so this mapping and it will overwrite each other.', self::CATALOG_PHASE_WRITES[ $w['key'] ] );
            }
            foreach ( $rules as $r ) {
                if ( $r['key'] === $w['key'] && self::suppliers_overlap( $r['supplier'], $w['supplier'] ) ) {
                    $messages[] = sprintf( 'Catalog Maintenance rule “%s” (%s) also writes this field for %s.', $r['rule'], $r['action'], $w['supplier'] );
                }
            }
            foreach ( $writers as $other ) {
                if ( $other['profile'] !== $profile_id && $other['key'] === $w['key']
                    && $other['supplier'] === $w['supplier'] && $other['value'] !== $w['value'] ) {
                    $messages[] = sprintf( 'Import profile “%s” maps this field for %s to %s; this profile maps it to %s.', $other['profile_name'], $w['supplier'], $other['value_label'], $w['value_label'] );
                }
            }
            if ( $messages ) {
                $out[ $w['field'] ] = array_values( array_unique( array_merge( $out[ $w['field'] ] ?? [], $messages ) ) );
            }
        }
        return $out;
    }

    /**
     * Conflicts created by a set of Custom Rules against the import profiles.
     *
     * @param array $rules Raw or normalized rules, in their saved order.
     * @return array<int, string[]> rule index => messages
     */
    public static function for_custom_rules( array $rules ): array {
        $writers = self::profile_writers();
        $out     = [];
        foreach ( self::rule_writers( $rules ) as $r ) {
            foreach ( $writers as $w ) {
                if ( $w['key'] === $r['key'] && self::suppliers_overlap( $r['supplier'], $w['supplier'] ) ) {
                    $out[ $r['index'] ][] = sprintf( 'This rule (%s) writes %s, which import profile “%s” also maps for %s. Each run will undo the other.', $r['action'], $w['label'], $w['profile_name'], $w['supplier'] );
                }
            }
        }
        return array_map( static fn( $m ) => array_values( array_unique( $m ) ), $out );
    }

    /* ── Writers ─────────────────────────────────────────────────────────── */

    /**
     * One row per (profile, field, supplier) an update-capable import profile writes.
     *
     * @param string   $draft_id      Profile being created in the wizard, if any.
     * @param string[] $draft_sources Its ticked sources (it has no saved ones yet).
     * @return array<int, array{profile:string, profile_name:string, field:string, key:string, label:string, supplier:string, value:string, value_label:string}>
     */
    private static function profile_writers( string $draft_id = '', array $draft_sources = [] ): array {
        $profiles = (array) MMI_DB::get_profiles();
        if ( $draft_id !== '' && $draft_sources && empty( $profiles[ $draft_id ]['sources'] ) ) {
            $profiles[ $draft_id ] = array_merge(
                [ 'name' => 'This profile', 'direction' => 'import', 'data_type' => 'product' ],
                $profiles[ $draft_id ] ?? [],
                [ 'sources' => array_values( array_map( 'sanitize_key', $draft_sources ) ) ]
            );
        }
        $rows = [];
        foreach ( $profiles as $profile_id => $p ) {
            if ( ( $p['direction'] ?? 'import' ) !== 'import'
                || ( $p['data_type'] ?? 'product' ) !== 'product'
                || ( $p['import_mode'] ?? '' ) === 'create-only'
                || ( $p['product_scope'] ?? '' ) === 'new_only'
                || empty( $p['sources'] ) ) {
                continue;
            }
            $mappings = MMI_Pipeline_Field_Mapping_Defaults::get_effective( (string) $profile_id );
            foreach ( $mappings as $field => $config ) {
                foreach ( (array) $p['sources'] as $supplier ) {
                    if ( ! self::is_enabled_for( $config, (string) $supplier ) ) {
                        continue;
                    }
                    [ $value, $value_label ] = self::value_of( $config, (string) $supplier );
                    $rows[] = [
                        'profile'      => (string) $profile_id,
                        'profile_name' => (string) ( $p['name'] ?? $profile_id ),
                        'field'        => (string) $field,
                        'key'          => self::write_key( (string) $field, $config ),
                        'label'        => (string) ( $config['label'] ?? $field ),
                        'supplier'     => (string) $supplier,
                        'value'        => $value,
                        'value_label'  => $value_label,
                    ];
                }
            }
        }
        return $rows;
    }

    /**
     * One row per field an enabled Custom Rule writes.
     *
     * @param array|null $rules Defaults to the saved rules.
     * @return array<int, array{index:int, rule:string, action:string, key:string, supplier:string}>
     */
    private static function rule_writers( ?array $rules = null ): array {
        $rules = $rules ?? (array) MMI_DB::get_setting( 'mmi_stock_override_rules', [] );
        $rows  = [];
        foreach ( $rules as $i => $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }
            $rule   = \MannMade\DataPipeline\Stock_Override_Resolver::normalize_rule( $rule );
            $action = (string) ( $rule['action'] ?? '' );
            if ( empty( $rule['enabled'] ) || ! isset( self::RULE_ACTION_WRITES[ $action ] ) ) {
                continue;
            }
            $params = (array) ( $rule['action_params'] ?? [] );
            foreach ( self::RULE_ACTION_WRITES[ $action ] as $key ) {
                if ( $key[0] === '{' ) {
                    $key = (string) ( $params[ trim( $key, '{}' ) ] ?? '' );
                }
                if ( $key === '' ) {
                    continue;
                }
                $rows[] = [
                    'index'    => (int) $i,
                    'rule'     => $rule['name'] !== '' ? $rule['name'] : 'Rule ' . ( (int) $i + 1 ),
                    'action'   => \MannMade\DataPipeline\Custom_Rule_Actions::label_for( $action ),
                    'key'      => $key,
                    'supplier' => (string) ( $rule['supplier'] ?? 'all' ),
                ];
            }
        }
        return $rows;
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    private static function is_enabled_for( array $config, string $supplier ): bool {
        $enabled = $config['enabled'] ?? false;
        return is_array( $enabled ) ? ! empty( $enabled[ $supplier ] ) : (bool) $enabled;
    }

    /** The postmeta/taxonomy key a mapping actually writes. */
    private static function write_key( string $field, array $config ): string {
        if ( ! empty( $config['meta_key_resolver'] ) && is_callable( $config['meta_key_resolver'] ) ) {
            return (string) call_user_func( $config['meta_key_resolver'] );
        }
        return self::KEY_ALIASES[ $field ] ?? $field;
    }

    /**
     * What a mapping writes for one supplier, as a comparable signature and a label.
     *
     * @return array{0:string, 1:string}
     */
    private static function value_of( array $config, string $supplier ): array {
        $pick = static fn( $v ) => is_array( $v ) ? ( $v[ $supplier ] ?? null ) : $v;
        if ( ! empty( $pick( $config['use_constant_value'] ?? false ) ) ) {
            $constant = (string) $pick( $config['constant_value'] ?? '' );
            return [ 'constant:' . $constant, sprintf( 'the constant “%s”', $constant ) ];
        }
        $source     = (string) $pick( $config['source'] ?? '' );
        $conditions = $config['conditions'] ?? [];
        $signature  = 'source:' . $source . ( $conditions ? '|' . md5( (string) wp_json_encode( $conditions ) ) : '' );
        return [ $signature, sprintf( 'the feed column “%s”', $source ) . ( $conditions ? ' (with conditions)' : '' ) ];
    }

    private static function suppliers_overlap( string $rule_supplier, string $supplier ): bool {
        return $rule_supplier === '' || $rule_supplier === 'all' || $rule_supplier === $supplier;
    }
}
