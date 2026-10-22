<?php
/**
 * Taxonomy Mapping exclusions — source values left out of the mapper
 *
 * A supplier sometimes sends a value that should never become a term: a
 * test vendor (XChange "VIRTAPPS" on its test products), a placeholder.
 * Mapping it to Skip still lists it in the table as a mapping decision;
 * excluding it takes it out of the Taxonomy Mapping table and its counts
 * until someone restores it.
 *
 * Excluding never deletes anything. A saved mapping for the value stays in
 * the mappings table and applies again once the value is restored. While
 * excluded, imports, Apply All and the background heal treat the value like
 * Skip: no term is assigned from it and products keep the terms they have.
 *
 * Keyed by supplier, source field, the taxonomy that field feeds and the
 * exact (case-sensitive, like the mappings table) source value, so a brand
 * value excluded for XChange leaves the same text in another field or from
 * another supplier alone.
 *
 * Storage: one MMI_Settings row holding key => entry as JSON. The list is a
 * handful of values, read once per request. JSON, not a PHP array: wp_mmi is
 * latin1 on mannmade.us, and a feed value outside cp1252 (CJK, "→") would
 * break a serialized array's string lengths and lose the whole list;
 * wp_json_encode() escapes those to ASCII.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Taxmap_Exclusions {

    const SETTING_KEY = 'mmi_pipeline_taxmap_excluded_values';

    /** @var array<string, array>|null Request cache of the stored list. */
    private static $cache = null;

    /**
     * Every excluded value, keyed by key().
     *
     * @return array<string, array{supplier:string, source_field:string, wc_taxonomy:string, source_value:string, excluded_at:string, excluded_by:int}>
     */
    public static function all(): array {
        if ( self::$cache === null ) {
            $stored      = json_decode( (string) MMI_Settings::get( self::SETTING_KEY, '' ), true );
            self::$cache = is_array( $stored ) ? $stored : [];
        }
        return self::$cache;
    }

    public static function key( string $supplier, string $source_field, string $wc_taxonomy, string $source_value ): string {
        return $supplier . '|' . $source_field . '|' . $wc_taxonomy . '|' . $source_value;
    }

    public static function get( string $supplier, string $source_field, string $wc_taxonomy, string $source_value ): ?array {
        return self::all()[ self::key( $supplier, $source_field, $wc_taxonomy, $source_value ) ] ?? null;
    }

    public static function is_excluded( string $supplier, string $source_field, string $wc_taxonomy, string $source_value ): bool {
        return self::get( $supplier, $source_field, $wc_taxonomy, $source_value ) !== null;
    }

    /**
     * Whether the value is excluded under any taxonomy its field feeds. The
     * save handler gets the row's current taxonomy, which differs from the
     * field's own one when the row is redirected.
     */
    public static function is_excluded_any_taxonomy( string $supplier, string $source_field, string $source_value ): bool {
        foreach ( self::all() as $entry ) {
            if ( $entry['supplier'] === $supplier && $entry['source_field'] === $source_field && $entry['source_value'] === $source_value ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Excluded entries for one supplier and taxonomy, for the import-time
     * resolver, which works per taxonomy.
     *
     * @return array<int, array>
     */
    public static function for_taxonomy( string $supplier, string $wc_taxonomy ): array {
        $out = [];
        foreach ( self::all() as $entry ) {
            if ( $entry['supplier'] === $supplier && $entry['wc_taxonomy'] === $wc_taxonomy ) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** @return array The stored entry. */
    public static function exclude( string $supplier, string $source_field, string $wc_taxonomy, string $source_value ): array {
        $list  = self::all();
        $key   = self::key( $supplier, $source_field, $wc_taxonomy, $source_value );
        $entry = $list[ $key ] ?? [
            'supplier'     => $supplier,
            'source_field' => $source_field,
            'wc_taxonomy'  => $wc_taxonomy,
            'source_value' => $source_value,
            'excluded_at'  => current_time( 'mysql' ),
            'excluded_by'  => get_current_user_id(),
        ];
        $list[ $key ] = $entry;
        self::save( $list );
        return $entry;
    }

    /** @return bool Whether the value was excluded before this call. */
    public static function restore( string $supplier, string $source_field, string $wc_taxonomy, string $source_value ): bool {
        $list = self::all();
        $key  = self::key( $supplier, $source_field, $wc_taxonomy, $source_value );
        if ( ! isset( $list[ $key ] ) ) {
            return false;
        }
        unset( $list[ $key ] );
        self::save( $list );
        return true;
    }

    /**
     * Fields the Taxonomy Mapping table shows for an excluded row.
     *
     * @return array{excluded:bool, excluded_at:string, excluded_by:string}
     */
    public static function row_fields( string $supplier, string $source_field, string $wc_taxonomy, string $source_value ): array {
        $entry = self::get( $supplier, $source_field, $wc_taxonomy, $source_value );
        if ( $entry === null ) {
            return [ 'excluded' => false, 'excluded_at' => '', 'excluded_by' => '' ];
        }
        $user = $entry['excluded_by'] ? get_userdata( (int) $entry['excluded_by'] ) : false;
        return [
            'excluded'    => true,
            'excluded_at' => (string) $entry['excluded_at'],
            'excluded_by' => $user ? $user->display_name : '',
        ];
    }

    private static function save( array $list ): void {
        MMI_Settings::set( self::SETTING_KEY, (string) wp_json_encode( $list ) );
        self::$cache = $list;
    }
}
