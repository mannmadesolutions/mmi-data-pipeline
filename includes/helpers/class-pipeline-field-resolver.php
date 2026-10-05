<?php
/**
 * Pipeline Field Resolver
 *
 * Single canonical implementation of "resolve a source field value and apply
 * a transform" — previously duplicated, with diverging behavior, across
 * class-dynamic-product-importer.php, class-import-preview.php, and
 * ProductImportController.php. See mmi-data-pipeline refactor notes.
 *
 * This is a strict superset of all three prior implementations: every
 * transform key and path syntax any of them recognised is recognised here,
 * so migrating a caller to this class cannot silently change behavior for
 * an existing saved field mapping.
 *
 * @package MannMade\DataPipeline\Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Field_Resolver {

    /** Transform keys that must run even on an empty/null value (e.g. prefix/suffix/template). */
    private const PARAMETERISED_ON_EMPTY = [
        'prefix', 'suffix', 'template', 'str_replace', 'regex_replace',
        'price_multiply', 'price_add', 'price_subtract', 'date_format',
    ];

    /**
     * Per-taxonomy hierarchy settings for the current request, keyed by WC
     * taxonomy slug (e.g. 'product_cat', 'brand'): ['delimiter' => string,
     * 'assign_all_levels' => bool]. Populated once per import/preview run by
     * init_hierarchy_settings() (called from
     * MMI_Pipeline_Field_Mapping_Defaults::get_effective(), the single choke
     * point every caller already goes through to load field mappings) so
     * resolve_term_ids_smart() doesn't need this threaded through every one
     * of its call sites individually.
     *
     * @var array<string, array{delimiter:string, assign_all_levels:bool}>
     */
    private static array $hierarchy_settings = [];

    /**
     * Field Mapping's "When a value isn't in Taxonomy Mapping" setting
     * (tax_unmapped). 'skip' leaves the product's terms as they are, 'match'
     * assigns an existing term with that ID/slug/name, 'create' also creates
     * a missing term (the old behavior for every taxonomy).
     */
    public const UNMAPPED_SKIP   = 'skip';
    public const UNMAPPED_MATCH  = 'match';
    public const UNMAPPED_CREATE = 'create';

    /**
     * Taxonomies whose values are decided by the Taxonomy Mapping tab (the
     * Field Mapping panel shows them as "resolved directly by Taxonomy
     * Mapping"). They default to 'skip': before 2.49.0 an unmapped
     * Xchange value such as "Software / 3D Audio" was created as a new
     * category on every import. Same list as panel-field-mapping.php's
     * $mmi_taxonomy_mapping_only_fields.
     */
    public const TAXONOMY_MAPPING_ONLY = [ 'product_brand', 'product_cat' ];

    /**
     * Effective tax_unmapped setting per taxonomy for the current request,
     * registered by init_hierarchy_settings() next to the hierarchy
     * settings. Read by resolve_term_ids_smart(): a term is only ever
     * created for a taxonomy whose setting is 'create'.
     *
     * @var array<string, string>
     */
    private static array $unmapped_policies = [];

    /**
     * Per-request cache of MMI_DB::get_tax_mappings() results, keyed by
     * "{supplier}|{wc_taxonomy}" — resolve_taxonomy_via_alias_table() is
     * called once per item during preview/import (potentially thousands of
     * times per run), but the alias rows for a given supplier+taxonomy pair
     * never change mid-run, so one query pair per pair is enough.
     *
     * @var array<string, array>
     */
    private static array $tax_mapping_rows_cache = [];

    /**
     * Per-request cache of resolved term IDs, keyed by
     * "{supplier}|{profile_id}|{source_field}|{raw_value}|{wc_taxonomy}" —
     * the same coded value (e.g. "Software / Software Bundles") recurs
     * across hundreds of items in a supplier feed, so this avoids
     * re-querying resolve_tax_mapping() for every repeat. profile_id is part
     * of the key (unlike $tax_mapping_rows_cache above) since a
     * profile-specific override row can make the same raw value resolve
     * differently for two profiles in the same request.
     *
     * @var array<string, int|null>
     */
    private static array $resolved_tax_mapping_cache = [];

    /**
     * Per-request cache of MMI_Pipeline_Admin::get_configured_suppliers() —
     * that call is a fresh DB query every time, and
     * resolve_taxonomy_via_alias_table() checking it on every single item
     * would reintroduce the exact N+1 pattern this class's other two caches
     * above already exist to avoid. The configured-suppliers list (and each
     * one's taxonomy_mapping_enabled flag) never changes mid-run.
     *
     * @var array<string, array>|null
     */
    private static ?array $configured_suppliers_cache = null;

    /**
     * Register hierarchy settings for every taxonomy field that has
     * 'tax_hierarchical' enabled in its saved field mapping — see the
     * class docblock above for why this is populated centrally rather than
     * passed as extra params by every resolve_term_ids_smart() caller.
     *
     * @param array $field_mappings Effective field mappings (as returned by
     *                              MMI_Pipeline_Field_Mapping_Defaults::get_effective()).
     */
    public static function init_hierarchy_settings( array $field_mappings ): void {
        self::$hierarchy_settings = [];
        self::$unmapped_policies  = [];

        foreach ( $field_mappings as $field_name => $mapping ) {
            if ( is_array( $mapping ) && ( $mapping['type'] ?? '' ) === 'taxonomy' ) {
                $policy_taxonomy = ( strpos( (string) $field_name, 'tax:' ) === 0 ) ? substr( (string) $field_name, 4 ) : (string) $field_name;
                self::$unmapped_policies[ $policy_taxonomy ] = self::unmapped_term_policy( $policy_taxonomy, $mapping );
            }
            if ( empty( $mapping['tax_hierarchical'] ) ) {
                continue;
            }
            // Built-in taxonomy fields ('product_cat'/'product_tag') use the
            // taxonomy slug directly as the field key; dynamic per-taxonomy
            // fields from MMI_Taxonomy_Field_Helper are prefixed 'tax:{slug}'
            // (its ':id' companion field is 'taxonomy_ids' typed, never
            // hierarchical, so it's naturally excluded here).
            $taxonomy = ( strpos( $field_name, 'tax:' ) === 0 ) ? substr( $field_name, 4 ) : $field_name;
            $delimiter = isset( $mapping['tax_hierarchical_delim'] ) && $mapping['tax_hierarchical_delim'] !== ''
                ? (string) $mapping['tax_hierarchical_delim']
                : '>';

            self::$hierarchy_settings[ $taxonomy ] = [
                'delimiter'         => $delimiter,
                'assign_all_levels' => empty( $mapping['tax_hierarchical_leaf_only'] ),
            ];
        }
    }

    /**
     * The tax_unmapped setting of one taxonomy field mapping, with its
     * default when unset: 'skip' for TAXONOMY_MAPPING_ONLY taxonomies,
     * 'create' (unchanged behavior) for every other taxonomy.
     *
     * @param string $taxonomy Real taxonomy slug (no 'tax:' prefix).
     * @param array  $mapping  The field's effective mapping.
     */
    public static function unmapped_term_policy( string $taxonomy, array $mapping ): string {
        $policy = (string) ( $mapping['tax_unmapped'] ?? '' );
        if ( in_array( $policy, [ self::UNMAPPED_SKIP, self::UNMAPPED_MATCH, self::UNMAPPED_CREATE ], true ) ) {
            return $policy;
        }
        return in_array( $taxonomy, self::TAXONOMY_MAPPING_ONLY, true ) ? self::UNMAPPED_SKIP : self::UNMAPPED_CREATE;
    }

    /**
     * Whether resolve_term_ids_smart() may create a missing term in this
     * taxonomy: only when the loaded profile's setting for it is 'create'.
     * A TAXONOMY_MAPPING_ONLY taxonomy no loaded profile configures is never
     * created; any other taxonomy keeps the old create-on-miss behavior.
     */
    public static function term_creation_allowed( string $taxonomy ): bool {
        if ( isset( self::$unmapped_policies[ $taxonomy ] ) ) {
            return self::$unmapped_policies[ $taxonomy ] === self::UNMAPPED_CREATE;
        }
        return ! in_array( $taxonomy, self::TAXONOMY_MAPPING_ONLY, true );
    }

    /**
     * Term IDs for a taxonomy value that Taxonomy Mapping did not resolve,
     * following the field's tax_unmapped setting. The one fallback every
     * import path uses after resolve_taxonomy_via_alias_table() comes back
     * empty.
     *
     * @param string $taxonomy           Real taxonomy slug.
     * @param array  $mapping            The field's effective mapping.
     * @param mixed  $raw_value          Mapped (or constant) value.
     * @param bool   $is_constant        A constant is a term the admin picked
     *                                   in Field Mapping, so 'skip' still
     *                                   matches it (never creates it).
     * @param bool   $explicitly_skipped Taxonomy Mapping marks this value Skip.
     * @return int[]|null null = leave the product's terms as they are.
     */
    public static function resolve_unmapped_term_ids( string $taxonomy, array $mapping, $raw_value, bool $is_constant = false, bool $explicitly_skipped = false ): ?array {
        if ( $explicitly_skipped || $raw_value === null || $raw_value === '' || $raw_value === [] ) {
            return null;
        }

        $policy = self::unmapped_term_policy( $taxonomy, $mapping );
        if ( $policy === self::UNMAPPED_SKIP ) {
            if ( ! $is_constant ) {
                return null;
            }
            $policy = self::UNMAPPED_MATCH;
        }

        $term_ids = self::resolve_term_ids_smart( $taxonomy, $raw_value, $policy === self::UNMAPPED_CREATE );
        return $term_ids ?: null;
    }

    /**
     * Resolve a field value: extract via dot/bracket path, then apply transform.
     *
     * @param array  $item              Full source item (also used by 'template').
     * @param string $source_path       Dot-notation path, optionally with foo[0]/foo[] segments.
     * @param string $transform         Transform key (default 'none').
     * @param array  $transform_params  Optional named params for parameterised transforms.
     * @return mixed
     */
    public static function resolve_field_value( array $item, string $source_path, string $transform = 'none', array $transform_params = [] ) {
        $value = self::get_nested_value( $item, $source_path );
        return self::apply_transform( $value, $transform, $transform_params, $item );
    }

    /**
     * Get value from nested array using dot notation, with optional bracket
     * indexing: foo[0] for a specific index, foo[] for the whole sub-array,
     * or foo[key=value] to pick the first item of an array-of-objects whose
     * own `key` equals `value` (e.g. 'requirements[os=mac].cpu' — order
     * -independent and self-documenting, unlike a positional index into a
     * vendor feed whose item order isn't guaranteed to stay stable). Superset
     * of the plain dot-walker used by the importer/preview and the
     * bracket-aware walker previously only in ProductImportController.
     *
     * The dropdown paths MMI_Pipeline_Config_Validator::extract_field_names()
     * generates for an array of objects always use one of these three forms
     * — never a bare merged path like 'requirements.cpu', which this walker
     * cannot resolve (there's no 'cpu' key directly on the list itself).
     *
     * @param mixed  $data
     * @param string $key
     * @return mixed
     */
    public static function get_nested_value( $data, $key ) {
        if ( empty( $key ) ) {
            return null;
        }

        // Compound field syntax ('master_category+sub_category') — resolves
        // each '+'-separated part independently (recursing back into this
        // same function, so every part still gets the full dot/bracket
        // syntax below) and joins the non-empty results with ' / '. This
        // matches a convention the codebase had already reinvented
        // independently in three separate places (Taxonomy Mapping's alias
        // resolution and both of its field-scanning implementations) before
        // any of them shared this function — those all worked, but nothing
        // that called get_nested_value() directly for a compound source
        // ever did, since this function returned null for the whole key
        // outright. Confirmed live (2026-08-30): map_product_data() (the
        // scheduled/cron importer's generic field extractor) calls this
        // function with no compound-parsing of its own, so product_cat's
        // Xchange source ('master_category+sub_category', fixed the same
        // day) silently resolved to nothing for every scheduled import even
        // after that fix — the alias-table lookup path happened to work
        // because it never went through here, not because this was fixed.
        if ( strpos( $key, '+' ) !== false ) {
            $parts  = array_map( 'trim', explode( '+', $key ) );
            $values = [];
            foreach ( $parts as $part ) {
                $part_value = self::get_nested_value( $data, $part );
                if ( $part_value !== null && $part_value !== '' ) {
                    $values[] = (string) $part_value;
                }
            }
            return empty( $values ) ? null : implode( ' / ', $values );
        }

        $keys  = explode( '.', $key );
        $value = $data;

        foreach ( $keys as $k ) {
            if ( preg_match( '/(.+)\[(\d+)\]/', $k, $matches ) ) {
                $sub_key = $matches[1];
                $index   = (int) $matches[2];
                if ( is_array( $value ) && isset( $value[ $sub_key ] ) && is_array( $value[ $sub_key ] ) && isset( $value[ $sub_key ][ $index ] ) ) {
                    $value = $value[ $sub_key ][ $index ];
                } else {
                    return null;
                }
            } elseif ( preg_match( '/(.+)\[([^=\[\]]+)=([^\]]+)\]/', $k, $matches ) ) {
                $sub_key    = $matches[1];
                $filter_key = $matches[2];
                $filter_val = $matches[3];
                $list       = ( is_array( $value ) && isset( $value[ $sub_key ] ) && is_array( $value[ $sub_key ] ) )
                    ? $value[ $sub_key ]
                    : null;
                $match = null;
                if ( $list !== null ) {
                    foreach ( $list as $candidate ) {
                        $candidate_val = is_array( $candidate ) ? ( $candidate[ $filter_key ] ?? null )
                            : ( is_object( $candidate ) ? ( $candidate->$filter_key ?? null ) : null );
                        if ( $candidate_val !== null && (string) $candidate_val === $filter_val ) {
                            $match = $candidate;
                            break;
                        }
                    }
                }
                if ( $match === null ) {
                    return null;
                }
                $value = $match;
            } elseif ( preg_match( '/(.+)\[\]/', $k, $matches ) ) {
                $sub_key = $matches[1];
                if ( is_array( $value ) && isset( $value[ $sub_key ] ) && is_array( $value[ $sub_key ] ) ) {
                    $value = $value[ $sub_key ];
                } else {
                    return null;
                }
            } elseif ( is_array( $value ) && isset( $value[ $k ] ) ) {
                $value = $value[ $k ];
            } elseif ( is_object( $value ) && isset( $value->$k ) ) {
                $value = $value->$k;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Apply a named transform to a value.
     *
     * Canonical superset of the transform keys found across all three prior
     * implementations:
     *   - importer:    uppercase, lowercase, trim, strip_html, html_decode,
     *                  to_decimal/number, to_integer, boolean, ucwords/title_case,
     *                  ucfirst, str_replace, regex_replace, prefix, suffix,
     *                  template, price_multiply, price_add, price_subtract,
     *                  date_format
     *   - controller:  to_int, to_bool, to_datetime, map_stock_status, sanitize_url
     *     (to_int/to_bool are aliases of to_integer/boolean; to_datetime is its
     *     own case — it formats as 'Y-m-d H:i:s' via strtotime(), distinct from
     *     date_format's configurable-format/epoch-aware behavior)
     *   - to_json: wp_json_encode()'s an array/object value resolved via a
     *     nested/filtered source path (e.g. 'requirements[os=mac]') into a
     *     JSON string — added for the Xchange web-assets enrichment fields,
     *     which resolve to whole sub-records, not scalars.
     *
     * @param mixed  $value
     * @param string $transform
     * @param array  $params  Named params for parameterised transforms.
     * @param array  $item    Full source item — only 'template' needs this.
     * @return mixed
     */
    public static function apply_transform( $value, $transform, array $params = [], array $item = [] ) {
        if ( empty( $value ) && ! in_array( $transform, self::PARAMETERISED_ON_EMPTY, true ) ) {
            return $value;
        }

        switch ( $transform ) {
            case 'uppercase':
                return strtoupper( (string) $value );

            case 'lowercase':
                return strtolower( (string) $value );

            case 'trim':
                return trim( (string) $value );

            case 'strip_html':
                return strip_tags( (string) $value );

            case 'html_decode':
                return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            case 'to_decimal':
            case 'number':
                return floatval( $value );

            case 'to_integer':
            case 'to_int':
                return intval( $value );

            case 'boolean':
            case 'to_bool':
                return filter_var( $value, FILTER_VALIDATE_BOOLEAN );

            case 'ucwords':
            case 'title_case':
                return ucwords( strtolower( (string) $value ) );

            case 'ucfirst':
                return ucfirst( strtolower( (string) $value ) );

            case 'str_replace':
                $find    = $params['find']    ?? '';
                $replace = $params['replace'] ?? '';
                return str_replace( $find, $replace, (string) $value );

            case 'regex_replace':
                $pattern     = $params['pattern']     ?? '';
                $replacement = $params['replacement'] ?? '';
                if ( empty( $pattern ) ) {
                    return $value;
                }
                // Security: reject patterns that could invoke the deprecated 'e'
                // modifier (arbitrary code execution via preg_replace).
                $pattern = preg_replace( '/[eE](?=[^a-zA-Z]*$)/', '', $pattern );
                $result  = @preg_replace( $pattern, $replacement, (string) $value );
                return ( $result !== null ) ? $result : $value;

            case 'prefix':
                $text = $params['text'] ?? '';
                return $text . (string) $value;

            case 'suffix':
                $text = $params['text'] ?? '';
                return (string) $value . $text;

            case 'template':
                $tpl = $params['template'] ?? '';
                if ( empty( $tpl ) ) {
                    return $value;
                }
                return preg_replace_callback(
                    '/\{([^}]+)\}/',
                    function ( $m ) use ( $item ) {
                        $resolved = self::get_nested_value( $item, trim( $m[1] ) );
                        return ( $resolved !== null ) ? (string) $resolved : '';
                    },
                    $tpl
                );

            case 'price_multiply':
                $multiplier = floatval( $params['multiplier'] ?? 1 );
                return round( floatval( $value ) * $multiplier, 4 );

            case 'price_add':
                $amount = floatval( $params['amount'] ?? 0 );
                return round( floatval( $value ) + $amount, 4 );

            case 'price_subtract':
                $amount = floatval( $params['amount'] ?? 0 );
                return round( floatval( $value ) - $amount, 4 );

            case 'date_format':
                $format    = $params['format'] ?? 'Y-m-d';
                $timestamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
                if ( $timestamp === false || $timestamp === 0 ) {
                    return $value;
                }
                return date( $format, $timestamp );

            case 'to_datetime':
                $timestamp = strtotime( (string) $value );
                return $timestamp ? date( 'Y-m-d H:i:s', $timestamp ) : $value;

            case 'map_stock_status':
                return self::map_stock_status( $value );

            case 'sanitize_url':
                return esc_url_raw( (string) $value );

            case 'to_json':
                // For an already-resolved array/object value (e.g. a
                // get_nested_value() match against a filtered sub-record like
                // requirements[os=mac]) — encodes to a JSON string matching
                // what MMI_Product_Meta_Boxes::save_json_field() itself
                // stores when a user edits the same field by hand, so both
                // writers produce the identical on-disk shape.
                return is_array( $value ) || is_object( $value )
                    ? wp_json_encode( $value )
                    : (string) $value;

            default:
                return $value;
        }
    }

    /**
     * Normalize a wide range of supplier availability formats to WooCommerce's
     * three stock-status values. Moved here verbatim from
     * ProductImportController::apply_transform() — the only place this transform
     * was previously implemented.
     *
     * @param mixed $value
     * @return string 'instock' | 'outofstock' | 'onbackorder'
     */
    public static function map_stock_status( $value ): string {
        if ( is_bool( $value ) ) {
            return $value ? 'instock' : 'outofstock';
        }

        if ( is_numeric( $value ) ) {
            return intval( $value ) > 0 ? 'instock' : 'outofstock';
        }

        $value_lower = strtolower( trim( (string) $value ) );

        $status_map = [
            'in stock'      => 'instock',
            'instock'       => 'instock',
            'in_stock'      => 'instock',
            'out of stock'  => 'outofstock',
            'outofstock'    => 'outofstock',
            'out_of_stock'  => 'outofstock',
            'on backorder'  => 'onbackorder',
            'onbackorder'   => 'onbackorder',
            'on_backorder'  => 'onbackorder',
            'true'          => 'instock',
            'false'         => 'outofstock',
            'yes'           => 'instock',
            'no'            => 'outofstock',
            'y'             => 'instock',
            'n'             => 'outofstock',
            '1'             => 'instock',
            '0'             => 'outofstock',
            'available'     => 'instock',
            'unavailable'   => 'outofstock',
            'in-stock'      => 'instock',
            'out-of-stock'  => 'outofstock',
        ];

        return $status_map[ $value_lower ] ?? 'instock';
    }

    /**
     * Canonical semantic-equality check for "did this field actually change".
     * Treats null/'' as equivalent and compares numeric strings by magnitude
     * so '49' == '49.00', 49 == 49.0, etc. — avoids false-positive changes
     * caused purely by string/decimal-precision formatting differences.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    public static function values_are_equal( $a, $b, string $type = '' ): bool {
        if ( $a === $b ) {
            return true;
        }
        if ( ( $a === null || $a === '' ) && ( $b === null || $b === '' ) ) {
            return true;
        }

        // Datetime fields need normalising before comparison: WooCommerce
        // stores _sale_price_dates_from/_to as UNIX timestamps (set_date_on_sale_*
        // converts on save), while feeds supply human-readable strings like
        // "2026-06-23 07:00:00". Compared raw, those are never equal — so a
        // sale-dated product stayed flagged as "changed" in the Import Preview
        // permanently, even immediately after being imported successfully.
        if ( $type === 'datetime' ) {
            $ts_a = self::to_timestamp( $a );
            $ts_b = self::to_timestamp( $b );
            if ( $ts_a !== null && $ts_b !== null ) {
                return $ts_a === $ts_b;
            }
        }

        // Boolean fields need the same treatment as datetime, for the same
        // reason: WooCommerce stores its own boolean-shaped meta (_virtual,
        // _downloadable, _manage_stock, etc.) as 'yes'/'no', while a field
        // mapping's constant/transformed value is commonly '1'/'0' or a real
        // PHP bool — compared raw those never match, checked here before the
        // numeric branch below so '1' isn't compared to 'yes' as 0 == 0
        // (is_numeric('yes') is false, so that branch would just fall through
        // to "different" anyway, but explicitly handling it here is what
        // actually makes '1' and 'yes' compare equal). Confirmed live: every
        // xchange/skuport product mapped _virtual's constant value '1'
        // against WooCommerce's stored 'yes' and was permanently flagged
        // "changed" — the entire reason a from-scratch, unmodified profile
        // reported effectively 0% of its catalog as unchanged.
        if ( $type === 'boolean' ) {
            return self::to_bool_string( $a ) === self::to_bool_string( $b );
        }

        if ( is_numeric( $a ) && is_numeric( $b ) ) {
            return (float) $a === (float) $b;
        }
        return false;
    }

    /**
     * Normalise a date value to a UNIX timestamp, accepting either an existing
     * timestamp (how WooCommerce stores sale dates) or any strtotime-parsable
     * string (how supplier feeds supply them). Returns null when the value
     * isn't a usable date, so callers can fall back to a literal comparison
     * rather than treating two unparsable values as equal.
     *
     * @param  mixed $value
     * @return int|null
     */
    private static function to_timestamp( $value ): ?int {
        if ( $value === null || $value === '' ) {
            return null;
        }

        // A bare integer-like value is already a timestamp. Guarded to 9+ digits
        // so a short numeric string (e.g. a numeric SKU or a year) isn't
        // silently reinterpreted as an epoch value.
        if ( is_numeric( $value ) && strlen( (string) (int) $value ) >= 9 ) {
            return (int) $value;
        }

        $parsed = strtotime( (string) $value );

        return $parsed === false ? null : $parsed;
    }

    /**
     * Normalise any boolean-ish representation to WooCommerce's own storage
     * convention ('yes'/'no') so a mapping's constant/transformed value
     * ('1'/'0', true/false) compares correctly against a real product's
     * stored _virtual/_downloadable/_manage_stock/etc. meta. Anything not
     * recognized as truthy is treated as false, matching wc_string_to_bool()'s
     * own default-false behavior for an unrecognized string.
     *
     * @param  mixed $value
     * @return string 'yes' or 'no'
     */
    private static function to_bool_string( $value ): string {
        if ( is_bool( $value ) ) {
            return $value ? 'yes' : 'no';
        }
        $normalized = strtolower( trim( (string) $value ) );
        return in_array( $normalized, [ '1', 'yes', 'true' ], true ) ? 'yes' : 'no';
    }

    /**
     * Load a supplier's promotions file and index records by SKU/ID for O(1)
     * lookup. Canonical version of the logic previously duplicated in
     * class-import-preview.php (indexed) and ProductImportController.php
     * (indexed) — and reimplemented as a slower O(n) linear scan in
     * class-dynamic-product-importer.php (find_promotion()).
     *
     * @param string $supplier
     * @param string $json_path  Directory containing the promo JSON files (with trailing slash).
     * @return array{index: array<string, array>, namespace: string|null}
     */
    public static function load_and_index_promotions( string $supplier, string $json_path ): array {
        $file_map = [
            'xchange' => 'xchange-promotions.json',
            'skuport' => 'skuport-promos.json',
        ];
        $filename = $file_map[ $supplier ] ?? null;
        if ( ! $filename ) {
            return [ 'index' => [], 'namespace' => null ];
        }

        $path = $json_path . $filename;
        if ( ! file_exists( $path ) ) {
            return [ 'index' => [], 'namespace' => null ];
        }

        $content = @file_get_contents( $path );
        if ( ! $content ) {
            return [ 'index' => [], 'namespace' => null ];
        }

        $data = json_decode( $content, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            return [ 'index' => [], 'namespace' => null ];
        }

        // Detect container key — its name becomes the injection namespace so
        // dot-notation source paths (e.g. 'promotions.street_price') resolve.
        $namespace = null;
        if ( isset( $data['promotions'] ) && is_array( $data['promotions'] ) ) {
            $promos    = $data['promotions'];
            $namespace = 'promotions';
        } elseif ( isset( $data['promos'] ) && is_array( $data['promos'] ) ) {
            $promos    = $data['promos'];
            $namespace = 'promos';
        } elseif ( isset( $data['specials'] ) && is_array( $data['specials'] ) ) {
            $promos    = $data['specials'];
            $namespace = 'specials';
        } else {
            $promos = $data; // bare array (e.g. skuport-promos.json)
        }

        $index = [];
        foreach ( $promos as $promo ) {
            $key = $promo['sku'] ?? $promo['id'] ?? $promo['product_id'] ?? null;
            if ( $key !== null && $key !== '' ) {
                $index[ (string) $key ] = $promo;
            }
        }

        return [ 'index' => $index, 'namespace' => $namespace ];
    }

    /**
     * Inject the matched promo record into a raw source item before field
     * mapping, so source paths like 'promotions.street_price' (namespaced)
     * or 'promoPrice' (flat-merged) resolve naturally.
     *
     * @param array      $item
     * @param mixed      $item_key   Value of the item's primary-key field.
     * @param array      $index      From load_and_index_promotions()['index'].
     * @param string|null $namespace From load_and_index_promotions()['namespace'].
     * @return array  The item, with the promo record merged in if matched.
     */
    public static function inject_promotion( array $item, $item_key, array $index, ?string $namespace ): array {
        if ( empty( $index ) || $item_key === null || $item_key === '' ) {
            return $item;
        }
        $key = (string) $item_key;
        if ( ! isset( $index[ $key ] ) ) {
            return $item;
        }
        $promo_record = $index[ $key ];
        if ( $namespace ) {
            $item[ $namespace ] = $promo_record;
        } else {
            $item = array_merge( $item, $promo_record );
        }
        return $item;
    }

    /**
     * A trustworthy "this is what it normally costs" reference off a matched
     * promo record, when one exists and is genuinely higher than the
     * currently-resolved regular price — or null when there's nothing to
     * correct.
     *
     * Exists because at least one supplier's own base product feed bakes an
     * active promotion's discount directly into its regular-price field
     * instead of reporting a stable, undiscounted value: confirmed live
     * (2026-09-05) that SkuPort's Products feed's "map" equals the
     * Promotions feed's "promoPrice" for 124/124 currently-active promos —
     * never above it. Naively trusting that field for _regular_price both
     * stores the discounted value as if it were the real price AND defeats
     * any "promo price < regular price" gate a caller applies afterward,
     * since the two are already equal. Xchange has no such quirk (its base
     * feed's map_price never moved for an active promo — confirmed live,
     * 9/9 active promos), but the same self-discounting behavior could exist
     * for any future supplier, so this is a general-purpose check, not a
     * SkuPort special case.
     *
     * The promo record itself is the one place a trustworthy reference
     * survives regardless of what the base feed currently shows — SkuPort's
     * own promo rows carry it as "regularMap", Xchange's as "map_price"
     * (which already matches its base feed, so this is a no-op there).
     *
     * @param mixed $resolved_regular_price The regular price as currently resolved
     *                                       (may already be the discounted value).
     * @param array $promo_record           The matched promo record (raw, not yet injected).
     * @return float|null
     */
    public static function resolve_true_regular_price( $resolved_regular_price, array $promo_record ): ?float {
        $true_regular = $promo_record['regularMap']
            ?? $promo_record['map_price']
            ?? $promo_record['msrp_price']
            ?? $promo_record['regular_price']
            ?? null;

        if ( $true_regular === null ) {
            return null;
        }

        return ( floatval( $true_regular ) > floatval( $resolved_regular_price ) )
            ? floatval( $true_regular )
            : null;
    }

    /**
     * Correct a raw source item's regular-price field, pre-mapping, when the
     * matched promo record's own trustworthy reference (see
     * resolve_true_regular_price()) shows the item's current value is really
     * the promo-discounted price, not the true regular price. No-ops when
     * the profile has no per-supplier source configured for _regular_price,
     * or when nothing needs correcting.
     *
     * @param array  $item           Raw source item (already promo-injected or not — irrelevant here).
     * @param array  $promo_record   The matched promo record (raw).
     * @param array  $field_mappings Effective field mappings for this profile.
     * @param string $supplier
     * @return array The item, with its regular-price source field corrected if needed.
     */
    public static function correct_self_discounted_regular_price( array $item, array $promo_record, array $field_mappings, string $supplier ): array {
        $regular_config = $field_mappings['_regular_price']['source'] ?? null;
        $source_field   = is_array( $regular_config ) ? ( $regular_config[ $supplier ] ?? '' ) : (string) $regular_config;
        if ( $source_field === '' || ! isset( $item[ $source_field ] ) ) {
            return $item;
        }

        $true_regular = self::resolve_true_regular_price( $item[ $source_field ], $promo_record );
        if ( $true_regular !== null ) {
            $item[ $source_field ] = $true_regular;
        }

        return $item;
    }

    /**
     * Load Xchange's supplemental "web assets" feed (richer images +
     * long-form description, from a separate vendor endpoint than the base
     * product feed) and index it by SKU for O(1) lookup — same shape/pattern
     * as load_and_index_promotions(). Written by
     * MMI_Xchange_Vendors::cron_write_web_assets_json() (mmi-xchange-integration);
     * the file simply won't exist for suppliers/sites that don't have it, in which case
     * this returns an empty index and enrich_item_with_web_assets() is a no-op.
     *
     * @param string $supplier
     * @param string $json_path Directory containing the JSON files (with trailing slash).
     * @return array<string, array> Index keyed by SKU.
     */
    public static function load_web_assets_index( string $supplier, string $json_path ): array {
        if ( $supplier !== 'xchange' ) {
            return [];
        }

        $path = $json_path . 'xchange-web-assets.json';
        if ( ! file_exists( $path ) ) {
            return [];
        }

        $content = @file_get_contents( $path );
        if ( ! $content ) {
            return [];
        }

        $data = json_decode( $content, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            return [];
        }

        $records = $data['web_assets'] ?? ( isset( $data[0] ) ? $data : [] );

        $index = [];
        foreach ( $records as $record ) {
            $sku = $record['sku'] ?? null;
            if ( $sku !== null && $sku !== '' ) {
                $index[ (string) $sku ] = $record;
            }
        }

        return $index;
    }

    /**
     * Backfill a raw Xchange source item's image_url/gallery_urls/descript
     * keys from the richer web-assets record when the base feed's own value
     * is empty — these are the exact same flat keys the 'xchange' entries in
     * MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS already point at, so
     * existing field mappings pick the richer data up with no config change.
     * Never overwrites a non-empty base-feed value except for description,
     * where the web-assets long-form text is preferred when present.
     *
     * @param array $item
     * @param mixed $item_key Value of the item's primary-key field (SKU).
     * @param array $web_assets_index From load_web_assets_index().
     * @return array
     */
    public static function enrich_item_with_web_assets( array $item, $item_key, array $web_assets_index ): array {
        if ( empty( $web_assets_index ) || $item_key === null || $item_key === '' ) {
            return $item;
        }

        $record = $web_assets_index[ (string) $item_key ] ?? null;
        if ( $record === null ) {
            return $item;
        }

        $images = array_values( array_filter( (array) ( $record['images'] ?? [] ) ) );
        if ( ! empty( $images ) ) {
            if ( empty( $item['image_url'] ) ) {
                $item['image_url'] = $images[0];
            }
            if ( empty( $item['gallery_urls'] ) ) {
                $item['gallery_urls'] = implode( ',', $images );
            }
        }

        if ( ! empty( $record['long_description'] ) ) {
            $item['descript'] = $record['long_description'];
        }

        // Passed through verbatim from the Web Asset API's response (see
        // MMI_Xchange_Vendors::write_web_assets_json()) — the base product
        // feed never carries these keys, so there's no collision to guard
        // against. Merged onto the item root (not nested under a 'webassets'
        // key) so plain field-mapping source paths like
        // 'requirements[os=mac].cpu' resolve directly via get_nested_value(),
        // the same way image_url/gallery_urls/descript already do above.
        foreach ( [ 'features', 'requirements', 'videos', 'licensing', 'platforms' ] as $key ) {
            if ( isset( $record[ $key ] ) && $record[ $key ] !== null ) {
                $item[ $key ] = $record[ $key ];
            }
        }

        return $item;
    }

    /**
     * Find a product's post ID by its configured primary-key field.
     * Canonical version of the lookup previously duplicated identically in
     * class-dynamic-product-importer.php, ProductImportController.php, and
     * class-import-preview.php.
     *
     * Uses the indexed wc_product_meta_lookup table via wc_get_product_id_by_sku()
     * when the primary key is _sku (fast path) — previously only the importer
     * had this optimization; the other two call sites always used the raw
     * postmeta query, which is correct but slower for the common _sku case.
     *
     * @param string $primary_key_wc  The WC match target configured as primary key: '_sku',
     *                                one of the reserved sentinels below ('__post_id',
     *                                '__post_title'), or a literal custom meta key.
     * @param mixed  $primary_value   The value to look up.
     * @return int  Product post ID, or 0 if not found.
     */
    public static function find_product_id_by_primary_key( string $primary_key_wc, $primary_value ): int {
        if ( $primary_value === null || $primary_value === '' ) {
            return 0;
        }

        if ( $primary_key_wc === '_sku' && function_exists( 'wc_get_product_id_by_sku' ) ) {
            return (int) wc_get_product_id_by_sku( $primary_value );
        }

        if ( $primary_key_wc === '__post_id' ) {
            $post_id = absint( $primary_value );
            return ( $post_id && get_post_type( $post_id ) === 'product' ) ? $post_id : 0;
        }

        if ( $primary_key_wc === '__post_title' ) {
            // wp_posts has no index on post_title, so this is an uncached table
            // scan — accepted here because "Post Title" is an opt-in, uncommon
            // match choice (SKU/meta lookups above are the indexed fast paths
            // and remain the default), matching WP All Import's own titled
            // caution that title-based matching is a fallback, not the
            // recommended identifier.
            global $wpdb;
            $product_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title = %s LIMIT 1",
                $primary_value
            ) );
            return $product_id ? (int) $product_id : 0;
        }

        global $wpdb;
        $product_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $primary_key_wc,
            $primary_value
        ) );

        return $product_id ? (int) $product_id : 0;
    }

    /**
     * Batch equivalent of find_product_id_by_primary_key() — resolves many
     * values in a handful of chunked queries instead of one query per value.
     *
     * Exists because the Import Preview scans an entire feed (thousands of
     * rows) in a single request; calling the singular method per row made
     * primary-key resolution alone an N-query operation, which combined with
     * the per-row post/meta/term reads to put ~55,000 queries behind one
     * "load the preview" click. See AGENTS.md "Scalability & Algorithmic
     * Efficiency" — batch-fetch with WHERE IN, then map in memory.
     *
     * Values are chunked (not sent as one giant IN list) so the generated SQL
     * stays well inside max_allowed_packet regardless of feed size.
     *
     * @param string $primary_key_wc  Same semantics as the singular method.
     * @param array  $values          Raw primary-key values from the feed.
     * @return array<string,int>      value => product ID, for matches only.
     */
    public static function find_product_ids_by_primary_keys( string $primary_key_wc, array $values ): array {
        global $wpdb;

        $values = array_values( array_unique( array_filter(
            array_map( 'strval', $values ),
            static fn( $v ) => $v !== ''
        ) ) );

        if ( empty( $values ) ) {
            return [];
        }

        $map        = [];
        $chunk_size = 500;

        foreach ( array_chunk( $values, $chunk_size ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

            if ( $primary_key_wc === '__post_id' ) {
                // Values are post IDs themselves — confirm each is a real product.
                $sql  = "SELECT ID FROM {$wpdb->posts}
                          WHERE post_type = 'product' AND post_status != 'trash'
                            AND ID IN ($placeholders)";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $rows = $wpdb->get_col( $wpdb->prepare( $sql, $chunk ) );
                foreach ( $rows as $id ) {
                    $map[ (string) (int) $id ] = (int) $id;
                }
                continue;
            }

            if ( $primary_key_wc === '__post_title' ) {
                // Unindexed by definition (see the singular method's note) —
                // batching at least collapses N scans into one per chunk.
                $sql  = "SELECT ID, post_title FROM {$wpdb->posts}
                          WHERE post_type = 'product' AND post_status != 'trash'
                            AND post_title IN ($placeholders)";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A );
                foreach ( $rows as $row ) {
                    // First match wins, mirroring the singular method's LIMIT 1.
                    if ( ! isset( $map[ $row['post_title'] ] ) ) {
                        $map[ $row['post_title'] ] = (int) $row['ID'];
                    }
                }
                continue;
            }

            // '_sku' and any custom meta key both resolve through postmeta.
            // Joined to wp_posts so trashed/non-product rows never match —
            // wc_get_product_id_by_sku() applies the same restriction.
            $sql = "SELECT pm.post_id, pm.meta_value
                      FROM {$wpdb->postmeta} pm
                      INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = %s
                       AND p.post_type IN ('product','product_variation')
                       AND p.post_status != 'trash'
                       AND pm.meta_value IN ($placeholders)";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare( $sql, array_merge( [ $primary_key_wc ], $chunk ) ),
                ARRAY_A
            );
            foreach ( $rows as $row ) {
                if ( ! isset( $map[ $row['meta_value'] ] ) ) {
                    $map[ $row['meta_value'] ] = (int) $row['post_id'];
                }
            }
        }

        return $map;
    }

    /**
     * Find every primary-key value that matches MORE than one live WC
     * product — a data-integrity defect (two separate posts both carrying
     * the same supplier tracking key), not the "same item at two vendors"
     * case Duplicate Products' candidate scanner solves. When this happens,
     * find_product_id_by_primary_key()'s unordered `LIMIT 1` (and this
     * class's own find_product_ids_by_primary_keys() "first row wins"
     * collapse) silently and permanently hide every product but one — the
     * hidden one never gets touched by any import run again. See the
     * "SKU 1035-2515 / Duplicate WC Products" investigation this method was
     * built for.
     *
     * Deliberately NOT called from the hot import path
     * (find_product_ids_by_primary_keys(), used by the real product
     * importers) — this is a diagnostic/UI-only read, kept as its own method
     * so the already-incident-prone importer call sites are never touched by
     * this change. Costs the same one-query-per-chunk shape as its sibling.
     *
     * `__post_id` is not checked — a post ID value can never resolve to more
     * than one post, by definition, so no collision is structurally
     * possible there.
     *
     * @param string $primary_key_wc Same semantics as find_product_id_by_primary_key().
     * @param array  $values         Raw primary-key values to check.
     * @return array<string,int[]>   value => every matching product ID, for
     *                                values with 2+ matches only.
     */
    public static function find_primary_key_collisions( string $primary_key_wc, array $values ): array {
        global $wpdb;

        if ( $primary_key_wc === '__post_id' ) {
            return [];
        }

        $values = array_values( array_unique( array_filter(
            array_map( 'strval', $values ),
            static fn( $v ) => $v !== ''
        ) ) );

        if ( empty( $values ) ) {
            return [];
        }

        $all_matches = []; // value => [product_id, ...]
        $chunk_size  = 500;

        foreach ( array_chunk( $values, $chunk_size ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

            if ( $primary_key_wc === '__post_title' ) {
                $sql  = "SELECT ID, post_title FROM {$wpdb->posts}
                          WHERE post_type = 'product' AND post_status != 'trash'
                            AND post_title IN ($placeholders)";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A );
                foreach ( $rows as $row ) {
                    $all_matches[ $row['post_title'] ][] = (int) $row['ID'];
                }
                continue;
            }

            // '_sku' and any custom meta key both resolve through postmeta —
            // same join/scope as find_product_ids_by_primary_keys(), just
            // collecting every row instead of collapsing to the first one.
            $sql = "SELECT pm.post_id, pm.meta_value
                      FROM {$wpdb->postmeta} pm
                      INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = %s
                       AND p.post_type IN ('product','product_variation')
                       AND p.post_status != 'trash'
                       AND pm.meta_value IN ($placeholders)";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare( $sql, array_merge( [ $primary_key_wc ], $chunk ) ),
                ARRAY_A
            );
            foreach ( $rows as $row ) {
                $all_matches[ $row['meta_value'] ][] = (int) $row['post_id'];
            }
        }

        return array_filter( $all_matches, static fn( $ids ) => count( $ids ) > 1 );
    }

    /**
     * Whole-catalog version of find_primary_key_collisions() — scans every
     * currently-configured supplier's own tracking-meta key
     * (`_mmi_supplier_sku_{supplier}`, the convention
     * Stock_Override_Resolver::get_supplier_sku_meta_key() already uses
     * independently) for a value shared by more than one live WC product,
     * without needing a feed's raw values as input. Backs the Manage
     * Duplicates > Confirmed Collisions detection mode — unlike
     * find_primary_key_collisions() (scoped to one import's own feed and
     * called from Review & Compare's per-row preview), this finds every
     * collision in the store regardless of whether any profile currently
     * previews that value.
     *
     * One `GROUP BY meta_value HAVING COUNT(*) > 1` query per supplier — cheap
     * (indexed on meta_key) even at full-catalog scale, and no feed file
     * needs to be read at all.
     *
     * @param string[] $suppliers Supplier ids to scan (e.g. from
     *                             MMI_Pipeline_Admin::get_configured_suppliers()).
     * @return array<string,array<string,int[]>> supplier => value => product IDs.
     */
    public static function scan_all_primary_key_collisions( array $suppliers ): array {
        global $wpdb;

        $result = [];

        foreach ( $suppliers as $supplier ) {
            $meta_key = '_mmi_supplier_sku_' . $supplier;

            $sql = "SELECT pm.meta_value
                      FROM {$wpdb->postmeta} pm
                      INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = %s
                       AND p.post_type IN ('product','product_variation')
                       AND p.post_status != 'trash'
                       AND pm.meta_value != ''
                     GROUP BY pm.meta_value
                    HAVING COUNT(*) > 1";
            $colliding_values = $wpdb->get_col( $wpdb->prepare( $sql, $meta_key ) );

            if ( empty( $colliding_values ) ) {
                continue;
            }

            $placeholders = implode( ',', array_fill( 0, count( $colliding_values ), '%s' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pm.post_id, pm.meta_value
                       FROM {$wpdb->postmeta} pm
                      WHERE pm.meta_key = %s
                        AND pm.meta_value IN ($placeholders)",
                    array_merge( [ $meta_key ], $colliding_values )
                ),
                ARRAY_A
            );

            $grouped = [];
            foreach ( $rows as $row ) {
                $grouped[ $row['meta_value'] ][] = (int) $row['post_id'];
            }

            if ( ! empty( $grouped ) ) {
                $result[ $supplier ] = $grouped;
            }
        }

        return $result;
    }

    /**
     * Resolve a raw import-file value for a taxonomy assignment into WP term
     * IDs, auto-detecting whether each entry is a term ID, a slug, or a
     * name/title — so a "Product Category" column can hold whichever of the
     * three an export produced (or whatever a user hand-typed) without the
     * profile needing to declare which one it is. Accepts a comma-separated
     * string (multi-term, matching wp_set_object_terms()'s own convention)
     * or an already-split array.
     *
     * Canonical version of the ad hoc numeric-vs-name check previously
     * duplicated (each slightly differently, and none checking slug) in
     * class-product-crud-manager.php::map_categories(),
     * class-variable-product-manager.php's attribute-term assignment,
     * class-dynamic-product-importer.php's variation-attribute assignment,
     * and class-wp-post-type-data-type.php::upsert_record()'s generic
     * tax:{taxonomy} handling.
     *
     * @param string       $taxonomy
     * @param string|array $raw_value
     * @param bool         $create_missing Whether an entry that matches
     *                     neither an ID, slug, nor name should be created as
     *                     a new term (wp_insert_term()) — true by default,
     *                     matching every existing call site's current
     *                     behavior; pass false for read-only/preview contexts.
     * @return int[] Resolved term IDs. Entries that don't resolve (and
     *               weren't created) are silently skipped, same as before.
     */
    public static function resolve_term_ids_smart( string $taxonomy, $raw_value, bool $create_missing = true ): array {
        // A scalar value containing a comma is ambiguous: it might be several
        // comma-separated term names, or it might be one term name that
        // itself contains a comma (e.g. "Amps, Effects & Pedals"). Try the
        // whole string as a single term first (read-only — never create from
        // this guess) so a real existing term with a comma in its name isn't
        // shredded into fragments that then fail to resolve individually.
        if ( ! is_array( $raw_value ) ) {
            $whole_entry = trim( (string) $raw_value );
            if ( $whole_entry !== '' && strpos( $whole_entry, ',' ) !== false ) {
                $whole_id = self::resolve_one_term_smart( $taxonomy, $whole_entry, false );
                if ( $whole_id ) {
                    return [ $whole_id ];
                }
            }
        }

        $entries = is_array( $raw_value )
            ? $raw_value
            : array_map( 'trim', explode( ',', (string) $raw_value ) );

        $hierarchy = self::$hierarchy_settings[ $taxonomy ] ?? null;

        $term_ids = [];
        foreach ( $entries as $entry ) {
            if ( $entry === null || $entry === '' ) {
                continue;
            }

            // Hierarchical path syntax (e.g. "Sports > Golf > Clubs") only
            // applies when this taxonomy has hierarchy enabled AND the entry
            // actually contains the delimiter — a plain flat value (no
            // delimiter present) still resolves as a single root-level term,
            // same as when hierarchy is off, so mixed flat/hierarchical rows
            // in the same feed both work.
            if ( $hierarchy !== null && is_string( $entry ) && strpos( $entry, $hierarchy['delimiter'] ) !== false ) {
                $level_ids = self::resolve_term_path_smart( $taxonomy, $entry, $hierarchy['delimiter'], $create_missing, $hierarchy['assign_all_levels'] );
                foreach ( $level_ids as $level_id ) {
                    $term_ids[] = $level_id;
                }
                continue;
            }

            $term_id = self::resolve_one_term_smart( $taxonomy, $entry, $create_missing );
            if ( $term_id ) {
                $term_ids[] = $term_id;
            }
        }

        return $term_ids;
    }

    /**
     * Resolve a taxonomy term via the Taxonomy Mapping tab's saved aliases
     * (wp_mmi_taxonomy_mappings), using whichever source_field(s) the admin
     * actually built alias rows for — read from the mapping rows themselves
     * (MMI_DB::get_tax_mappings()), NOT from the Field Mapping panel's
     * "source" config, since those two have historically drifted apart (e.g.
     * Xchange's product_cat field mapping points at the feed's always-empty
     * "categories" array, while its alias rows are keyed off the compound
     * "master_category+sub_category" fields — this reads the latter, which
     * is what the aliases were actually built against).
     *
     * Compound source fields ("fieldA+fieldB") are joined with " / ",
     * mirroring TaxonomyMappingController.php's scan/apply-to-existing tools
     * so aliases built via "Scan All Sources" resolve identically here.
     *
     * @param string $supplier
     * @param array  $item        Raw source item (unmapped feed record).
     * @param string $wc_taxonomy e.g. 'product_cat', 'product_brand'.
     * @return int[] Resolved term ID(s); empty when no alias rows exist for
     *               this supplier+taxonomy or none match the item's value.
     */
    public static function resolve_taxonomy_via_alias_table( string $supplier, array $item, string $wc_taxonomy, string $profile_id = '', ?bool &$explicitly_skipped = null ): array {
        $explicitly_skipped = false;

        // Per-source Taxonomy Mapping toggle (2026-08-31) — a supplier with
        // this off is treated exactly like one with zero alias rows ever
        // built (empty return, same as the natural "nothing configured"
        // case below), letting every existing caller's already-correct
        // literal-value fallback handle it with no special-casing needed.
        // Cached (see $configured_suppliers_cache) so this doesn't re-query
        // the data sources table on every one of potentially thousands of
        // items in a single import/preview run.
        if ( self::$configured_suppliers_cache === null && class_exists( 'MMI_Pipeline_Admin' ) ) {
            self::$configured_suppliers_cache = MMI_Pipeline_Admin::get_configured_suppliers();
        }
        if ( isset( self::$configured_suppliers_cache[ $supplier ] ) && ! ( self::$configured_suppliers_cache[ $supplier ]['taxonomy_mapping_enabled'] ?? true ) ) {
            return [];
        }

        // Pool discovery (which source_fields have ANY alias coverage for this
        // supplier+taxonomy) stays supplier-scoped only, not profile-scoped —
        // a taxonomy with alias rows at any profile scope is still
        // "resolvable via alias" here; only the per-value resolution below
        // needs to respect profile precedence, so this cache key is
        // unaffected by adding profile scoping.
        $cache_key = $supplier . '|' . $wc_taxonomy;
        if ( ! isset( self::$tax_mapping_rows_cache[ $cache_key ] ) ) {
            self::$tax_mapping_rows_cache[ $cache_key ] = array_merge(
                MMI_DB::get_tax_mappings( $supplier, $wc_taxonomy ),
                MMI_DB::get_tax_mappings( '', $wc_taxonomy )
            );
        }
        $rows = self::$tax_mapping_rows_cache[ $cache_key ];
        if ( empty( $rows ) ) {
            return [];
        }

        $source_fields = array_unique( array_filter( array_column( $rows, 'source_field' ) ) );

        $term_ids = [];
        foreach ( $source_fields as $source_field ) {
            $parts  = array_map( 'trim', explode( '+', $source_field ) );
            $values = [];
            foreach ( $parts as $part ) {
                $v = (string) ( $item[ $part ] ?? '' );
                if ( $v !== '' ) {
                    $values[] = $v;
                }
            }
            if ( empty( $values ) ) {
                continue;
            }

            $raw_value  = implode( ' / ', $values );
            // profile_id is part of this cache key (unlike the pool-discovery
            // one above) — the actual resolved term can legitimately differ
            // per profile, so a single PHP request resolving aliases across
            // more than one profile (a batch touching multiple profiles, or
            // Import Preview loading two profiles back to back) must not
            // serve one profile's resolved term to another.
            $cache_key2 = $supplier . '|' . $profile_id . '|' . $source_field . '|' . $raw_value . '|' . $wc_taxonomy;
            if ( ! array_key_exists( $cache_key2, self::$resolved_tax_mapping_cache ) ) {
                self::$resolved_tax_mapping_cache[ $cache_key2 ] = MMI_DB::resolve_tax_mapping( $supplier, $source_field, $raw_value, $wc_taxonomy, $profile_id );
            }
            $term_id = self::$resolved_tax_mapping_cache[ $cache_key2 ];
            if ( $term_id && $term_id > 0 ) {
                $term_ids[] = $term_id;
            } elseif ( $term_id === -1 ) {
                $explicitly_skipped = true;
            }
        }

        return $term_ids;
    }

    /**
     * Resolve a single "Parent > Child > Grandchild"-style path into a chain
     * of term IDs, matching/creating each level under its predecessor —
     * modeled on WP All Import's "entire hierarchy in one field" mode.
     *
     * Each level is matched by exact name + parent (not the ID/slug/name
     * auto-detection resolve_one_term_smart() uses for flat entries) since a
     * hierarchy path is always human-entered/human-readable text, never an ID.
     *
     * @param string $taxonomy
     * @param string $path              Raw delimited value, e.g. "Sports > Golf > Clubs".
     * @param string $delimiter
     * @param bool   $create_missing
     * @param bool   $assign_all_levels When true, every level's term ID is
     *                                  returned (all get assigned to the
     *                                  product); when false, only the
     *                                  deepest/last resolved level is
     *                                  returned. Ancestors are always
     *                                  matched/created either way so the
     *                                  term tree itself is correct.
     * @return int[]
     */
    private static function resolve_term_path_smart( string $taxonomy, string $path, string $delimiter, bool $create_missing, bool $assign_all_levels ): array {
        $levels = array_values( array_filter( array_map( 'trim', explode( $delimiter, $path ) ), static function ( $level ) {
            return $level !== '';
        } ) );

        if ( count( $levels ) < 2 ) {
            // Nothing to chain (e.g. the delimiter appeared but only produced
            // one non-empty segment) — fall back to the normal flat lookup.
            $term_id = self::resolve_one_term_smart( $taxonomy, $path, $create_missing );
            return $term_id ? [ $term_id ] : [];
        }

        $resolved_ids = [];
        $parent_id    = 0;

        foreach ( $levels as $level_name ) {
            $term_id = self::resolve_one_term_in_parent( $taxonomy, $level_name, $parent_id, $create_missing );
            if ( ! $term_id ) {
                // Can't go deeper without a valid parent — stop the chain here.
                break;
            }
            $resolved_ids[] = $term_id;
            $parent_id      = $term_id;
        }

        if ( empty( $resolved_ids ) ) {
            return [];
        }

        return $assign_all_levels ? $resolved_ids : [ end( $resolved_ids ) ];
    }

    /**
     * Find (or create) a term with an exact name match under a specific
     * parent — the hierarchy-aware counterpart to resolve_one_term_smart().
     *
     * @param string $taxonomy
     * @param string $name
     * @param int    $parent_id
     * @param bool   $create_missing
     * @return int 0 if unresolved.
     */
    private static function resolve_one_term_in_parent( string $taxonomy, string $name, int $parent_id, bool $create_missing ): int {
        $existing = get_terms( [
            'taxonomy'   => $taxonomy,
            'name'       => $name,
            'parent'     => $parent_id,
            'hide_empty' => false,
            'number'     => 1,
        ] );

        if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
            return (int) $existing[0]->term_id;
        }

        if ( ! $create_missing || ! taxonomy_exists( $taxonomy ) || ! self::term_creation_allowed( $taxonomy ) ) {
            return 0;
        }

        $inserted = wp_insert_term( $name, $taxonomy, [ 'parent' => $parent_id ] );
        if ( is_wp_error( $inserted ) ) {
            // "term_exists" is the one error worth recovering from here (a
            // race with another process, or a term whose slug collides at
            // this parent level even though the exact-name lookup above
            // missed it) — re-fetch instead of silently dropping this level.
            if ( $inserted->get_error_code() === 'term_exists' ) {
                $existing_id = $inserted->get_error_data();
                if ( $existing_id ) {
                    return (int) $existing_id;
                }
            }
            return 0;
        }

        return (int) $inserted['term_id'];
    }

    /**
     * @param string $taxonomy
     * @param mixed  $entry
     * @param bool   $create_missing
     * @return int 0 if unresolved.
     */
    private static function resolve_one_term_smart( string $taxonomy, $entry, bool $create_missing ): int {
        // Numeric entries are checked as a term ID first — matches the
        // is_numeric() convention class-product-crud-manager.php::map_categories()
        // already used for product_cat. Falls through to slug/name matching
        // below rather than returning 0 outright if the number isn't
        // actually a valid term ID (e.g. a taxonomy with numeric-looking slugs).
        if ( is_numeric( $entry ) ) {
            $term = get_term( (int) $entry, $taxonomy );
            if ( $term && ! is_wp_error( $term ) ) {
                return (int) $term->term_id;
            }
        }

        $entry = trim( (string) $entry );
        if ( $entry === '' ) {
            return 0;
        }

        $by_slug = get_term_by( 'slug', $entry, $taxonomy );
        if ( $by_slug ) {
            return (int) $by_slug->term_id;
        }

        $by_name = get_term_by( 'name', $entry, $taxonomy );
        if ( $by_name ) {
            return (int) $by_name->term_id;
        }

        if ( ! $create_missing || ! taxonomy_exists( $taxonomy ) || ! self::term_creation_allowed( $taxonomy ) ) {
            return 0;
        }

        $inserted = wp_insert_term( $entry, $taxonomy );
        if ( is_wp_error( $inserted ) ) {
            return 0;
        }

        return (int) $inserted['term_id'];
    }

    /**
     * Turns a mapped item's resolved '_product_image_url'/'_product_gallery_urls'
     * values into the single combined image list Product_CRUD_Manager::
     * set_product_images() expects (which itself already treats element 0 as
     * the featured image and the rest as the gallery — that split logic
     * already existed and needed no change, it just had no real caller).
     *
     * Two supported shapes, selected by $image_array_mode:
     *  - false (the ordinary case): '_product_image_url' is one URL and
     *    '_product_gallery_urls' is a separate list — both are merged into
     *    one list, main image first.
     *  - true: some feeds (e.g. Xchange's Web Asset API) bundle every image
     *    for a product into ONE JSON array with no separate main-image
     *    field at all. '_product_image_url' is mapped straight to that whole
     *    array; this mode passes it through unsplit, since
     *    set_product_images() already does the "first item = featured, rest
     *    = gallery" split once it receives one combined list — no double
     *    split needed here.
     *
     * @param mixed $image_value    Resolved '_product_image_url' value (a
     *                              single URL, or a whole array when
     *                              $image_array_mode is true).
     * @param mixed $gallery_value  Resolved '_product_gallery_urls' value
     *                              (ignored when $image_array_mode is true).
     * @param bool  $image_array_mode
     * @return string[] Deduplicated, non-empty URLs, main image first.
     */
    public static function assemble_product_images( $image_value, $gallery_value, bool $image_array_mode ): array {
        $urls = $image_array_mode
            ? (array) $image_value
            : array_merge( (array) $image_value, (array) $gallery_value );

        $urls = array_values( array_filter( $urls, static function ( $url ) {
            return is_string( $url ) && trim( $url ) !== '';
        } ) );

        return array_values( array_unique( $urls ) );
    }
}
