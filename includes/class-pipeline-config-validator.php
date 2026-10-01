<?php
/**
 * Pipeline Config Validator
 *
 * Surfaces import-profile configuration problems (missing field-mapping
 * sources, disabled constants, missing primary keys, stale/missing source
 * feeds) *before* a manual import runs or a schedule fires — these issues
 * otherwise fail silently inside import_single_product()/update_product()
 * and produce empty placeholder products.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Config_Validator {

    /**
     * Fields whose absence/blank-ness produces a broken or unusable product
     * listing when a new product is created.
     */
    const CRITICAL_FIELDS = [ 'post_title', '_sku', '_regular_price' ];

    /**
     * How old a source feed file can be before it's flagged as stale.
     */
    const STALE_FEED_HOURS = 48;

    /**
     * How long a supplier's parsed field-name index is cached. Keyed by the
     * feed file's own mtime (see get_available_fields()), so a re-fetched
     * feed is picked up immediately regardless of this TTL — this only
     * bounds how long a *stale* index survives if the feed file is deleted
     * without a replacement ever landing.
     */
    const FIELD_INDEX_CACHE_TTL = 10 * MINUTE_IN_SECONDS;

    /**
     * Fields that make poor filters for the same reason they make poor WC
     * attributes/taxonomy candidates (see AttributeMappingController's
     * mmi_discover_attributes and TaxonomyMappingController's
     * mmi_discover_taxonomy_candidates, which each carry their own copy of
     * this exact list) — id/price/date/description-shaped values are either
     * unique-per-row (useless as a checkbox filter) or free text (too many
     * distinct values to enumerate meaningfully).
     */
    const FILTERABLE_FIELD_SKIP_PATTERNS = [
        '/^(id|sku|price|map|msrp|sale_price|cost|upc|ean|gtin|asin)$/i',
        '/^(description|name|title|image|gallery|url|link|permalink)$/i',
        '/^(stock|inventory|quantity|available|shipping|weight|width|height|length)$/i',
        '/^(updated_at|created_at|date|timestamp|status|enabled|active|featured)$/i',
        '/\.(id|sku|price|url|image|description|name)$/i',
    ];

    /**
     * Above this many distinct raw values, a source-data filter field renders
     * as a live text-search input instead of a checkbox list — same cutoff
     * MMI_Taxonomy_Tree_Renderer::TREE_TERM_LIMIT already uses for the
     * identical tree-vs-text-fallback decision, reused rather than a second,
     * independently-chosen number for the same kind of UI judgment call.
     */
    const FILTERABLE_FIELD_VALUE_CAP = 150;

    /**
     * Validate a profile's configuration.
     *
     * @param  string $profile_id Profile ID (e.g. 'new_products').
     * @param  array  $overrides  Optional ['sources' => string[], 'import_mode' => string],
     *                            used in place of the saved profile record when $profile_id
     *                            has no row yet — e.g. the creation wizard validating its own
     *                            in-progress source/mode selections before the profile has
     *                            ever been saved. Ignored once a real saved profile exists;
     *                            that profile's own saved values always take precedence.
     * @return array<int, array{severity:string, field?:string, supplier?:string, message:string}>
     */
    public static function validate( string $profile_id, array $overrides = [] ): array {
        $issues = [];

        $profiles = MMI_DB::get_profiles();
        $profile  = $profiles[ $profile_id ] ?? null;

        if ( ! $profile ) {
            if ( empty( $overrides ) ) {
                $issues[] = [
                    'severity' => 'critical',
                    'message'  => "Profile '{$profile_id}' was not found.",
                ];
                return $issues;
            }
            // Not saved yet — synthesize just enough of a profile record from
            // the wizard's current selections to run the same checks against.
            $profile = [
                'import_mode' => $overrides['import_mode'] ?? 'update-only',
                'sources'     => $overrides['sources'] ?? [],
            ];
        }

        $import_mode       = $profile['import_mode'] ?? 'update-only';
        $is_create_capable = MMI_Pipeline_Field_Mapping_Defaults::is_create_capable_mode( $import_mode );

        $enabled_suppliers = self::get_enabled_suppliers();
        // Scope to the profile's own assigned sources when it has any — mirrors
        // the identical fix in ProductImportController::process_import_batch_cron(),
        // so this validator reports the same picture the importer will actually see
        // rather than every currently-enabled supplier regardless of assignment.
        $profile_sources = $profile['sources'] ?? [];
        if ( ! empty( $profile_sources ) ) {
            $enabled_suppliers = array_values( array_intersect( $enabled_suppliers, $profile_sources ) );
        }
        // Effective mappings (defaults + saved overrides) — must match what
        // ProductImportController::process_import_batch_cron() actually uses,
        // so this validator reports the same picture the importer will see.
        $mappings          = MMI_Pipeline_Field_Mapping_Defaults::get_effective( $profile_id );

        if ( empty( $enabled_suppliers ) ) {
            $issues[] = [
                'severity' => 'critical',
                'message'  => 'No suppliers are validated yet. Configure and test at least one supplier in Data Sources before importing.',
            ];
            return $issues;
        }

        $json_path = mmi_shared_lib_json_dir();

        // Real field names available from each supplier's canonical feed —
        // resolved once per (supplier, file) pair here (not per field
        // mapping) so a profile with dozens of mapped fields doesn't
        // re-parse the same feed file dozens of times in one validate()
        // call. Keyed by file, not just supplier: a field mapping can
        // override its own source file per supplier (e.g. pricing fields
        // reading from a promotions feed instead of the main product feed —
        // see panel-field-mapping.php's per-field file selector), and
        // checking such a field against the wrong (default) file's index
        // would always report it "not found" even when it's genuinely
        // present in the file it actually reads from.
        $available_fields = [];
        foreach ( $enabled_suppliers as $supplier ) {
            $default_filename = $supplier === 'xchange' ? 'xchange-products.json' : "{$supplier}-products.json";
            $available_fields[ $supplier ][ $default_filename ] = self::get_available_fields( $supplier, $json_path, $default_filename );
        }
        foreach ( $mappings as $mapping ) {
            if ( ! is_array( $mapping ) || ! is_array( $mapping['file'] ?? null ) ) {
                continue;
            }
            foreach ( $enabled_suppliers as $supplier ) {
                $override_filename = $mapping['file'][ $supplier ] ?? '';
                if ( $override_filename === '' || isset( $available_fields[ $supplier ][ $override_filename ] ) ) {
                    continue;
                }
                $available_fields[ $supplier ][ $override_filename ] = self::get_available_fields( $supplier, $json_path, $override_filename );
            }
        }

        // 1. Field mapping checks
        foreach ( $mappings as $field_name => $mapping ) {
            if ( ! is_array( $mapping ) ) {
                continue;
            }
            $issues = array_merge(
                $issues,
                self::validate_mapping( $field_name, $mapping, $enabled_suppliers, $is_create_capable, $available_fields )
            );
        }

        // 2. Primary key checks — required for create/update matching
        foreach ( $enabled_suppliers as $supplier ) {
            $pk_source = MMI_DB::get_primary_key( $supplier, 'source', '' );
            $pk_wc     = MMI_DB::get_primary_key( $supplier, 'wc', '' );
            if ( $pk_source === '' || $pk_wc === '' ) {
                $issues[] = [
                    'severity' => 'critical',
                    'supplier' => $supplier,
                    'message'  => "No primary key is configured for supplier '{$supplier}' — products from this supplier cannot be matched for create/update and will all be skipped.",
                ];
                continue;
            }
            // A primary key field can be configured and still be unusable data
            // — blank cells or duplicate values in the actual feed. Neither
            // failure mode is visible from the wizard's picker (it only shows
            // one sampled row), so this scans the real, full feed for it.
            $issues = array_merge( $issues, self::validate_primary_key_data_quality( $supplier, $pk_source, $json_path ) );
        }

        // 3. Source feed file checks
        $source_types  = self::get_supplier_source_types();
        foreach ( $enabled_suppliers as $supplier ) {
            $filename = $supplier === 'xchange' ? 'xchange-products.json' : "{$supplier}-products.json";
            $file     = $json_path . $filename;
            // 'upload' sources have no live remote endpoint to "Data Fetch" —
            // this cached file is only ever (re)written by uploading/re-parsing
            // a file the user chose, so the missing/stale messaging below is
            // phrased around "upload"/"refresh" instead of the API-oriented
            // "run a Data Fetch", which doesn't correspond to any action
            // actually available for this source type (see isUploadOnlySelection()
            // in product-import.js, which relabels the same button "Refresh").
            $is_upload = ( $source_types[ $supplier ] ?? 'api' ) === 'upload';

            if ( ! file_exists( $file ) ) {
                $issues[] = [
                    'severity' => 'critical',
                    'supplier' => $supplier,
                    'message'  => $is_upload
                        ? "No uploaded file found for '{$supplier}' ({$filename}). Upload a file for this data source first."
                        : "No product feed found for '{$supplier}' ({$filename}). Run a Data Fetch first.",
                ];
                continue;
            }

            // Staleness only applies to sources whose data can actually go stale
            // (API/URL/Dropbox/Google Drive — anything with a live remote endpoint
            // that could have newer data available). An 'upload' source's file is
            // static — the user explicitly chose it, and it only changes when they
            // upload a new one — so an "age" warning doesn't apply to it.
            if ( $is_upload ) {
                continue;
            }

            $age_hours = ( time() - filemtime( $file ) ) / 3600;
            if ( $age_hours > self::STALE_FEED_HOURS ) {
                $issues[] = [
                    'severity' => 'warning',
                    'supplier' => $supplier,
                    'message'  => sprintf(
                        "The '%s' product feed is %.0f hours old — consider running a Data Fetch before importing.",
                        $supplier,
                        $age_hours
                    ),
                ];
            }
        }

        return $issues;
    }

    /**
     * Validate a single field-mapping entry.
     *
     * Mirrors the exact logic ProductImportController::update_product() uses
     * to decide whether a mapping is applied, so any combination that would
     * be silently skipped there is caught here instead.
     *
     * @param array $available_fields supplier => [filename => [field_name => true]] index built by get_available_fields().
     * @return array<int, array{severity:string, field:string, message:string}>
     */
    private static function validate_mapping( string $field_name, array $mapping, array $enabled_suppliers, bool $is_create_capable, array $available_fields = [] ): array {
        $issues = [];

        $enabled = $mapping['enabled'] ?? null;

        // Which suppliers is this mapping actually enabled for?
        $applies_to = [];
        if ( is_array( $enabled ) ) {
            foreach ( $enabled_suppliers as $supplier ) {
                if ( ! empty( $enabled[ $supplier ] ) ) {
                    $applies_to[] = $supplier;
                }
            }
        } elseif ( ! empty( $enabled ) ) {
            $applies_to = $enabled_suppliers;
        }

        $is_critical_field = in_array( $field_name, self::CRITICAL_FIELDS, true );

        // Case A: a legacy (scalar) constant value is configured — it applies
        // to all suppliers regardless of the per-supplier enabled flag
        // (mirrors update_product()'s bypass for this shape). A per-source
        // constant (use_constant_value is an array) is scoped per supplier
        // instead — handled in Case C below, same as a source path.
        if ( ! is_array( $mapping['use_constant_value'] ?? false )
            && MMI_Pipeline_Field_Mapping_Defaults::resolve_constant( $mapping, '' ) !== null ) {
            return $issues;
        }

        // Case B: nothing enabled and no constant. Not an issue in general
        // (the field is simply unused) — unless it's a critical field on a
        // create-capable profile, in which case new products will be missing it.
        if ( empty( $applies_to ) ) {
            if ( $is_critical_field && $is_create_capable ) {
                $issues[] = [
                    'severity' => 'critical',
                    'field'    => $field_name,
                    'message'  => sprintf(
                        "'%s' is not enabled for any supplier. New products created by this profile will have no value for this field.",
                        $field_name
                    ),
                ];
            }
            return $issues;
        }

        // Case C: field is enabled for one or more suppliers — each one needs
        // either a per-source constant or a non-empty 'source', or
        // update_product() will `continue` past it for that supplier.
        $source       = $mapping['source'] ?? null;
        $missing_for  = [];
        $not_found_for = []; // supplier => mapped source string not present in that supplier's actual feed
        foreach ( $applies_to as $supplier ) {
            if ( MMI_Pipeline_Field_Mapping_Defaults::resolve_constant( $mapping, $supplier ) !== null ) {
                continue;
            }
            $src = is_array( $source ) ? ( $source[ $supplier ] ?? '' ) : ( $source ?? '' );
            if ( $src === '' || $src === null || $src === 'NULL' || $src === 'null' ) {
                $missing_for[] = $supplier;
                continue;
            }

            // Only check existence when the feed was actually parseable —
            // an empty index means missing/unreadable feed, already reported
            // by section 3 below; flagging every mapped field as "not found"
            // on top of that would just be noise. Checked against whichever
            // file THIS field actually reads from for this supplier (its own
            // 'file' override, e.g. a promotions feed — falling back to the
            // supplier's default product feed when it has none), not always
            // the default — see the matching comment in validate() above.
            $default_filename   = $supplier === 'xchange' ? 'xchange-products.json' : "{$supplier}-products.json";
            $override_filename  = is_array( $mapping['file'] ?? null ) ? ( $mapping['file'][ $supplier ] ?? '' ) : '';
            $filename_for_field = $override_filename !== '' ? $override_filename : $default_filename;
            $available = $available_fields[ $supplier ][ $filename_for_field ] ?? [];
            if ( ! empty( $available ) ) {
                $missing_parts = self::missing_compound_parts( $src, $available );
                if ( ! empty( $missing_parts ) ) {
                    $not_found_for[ $supplier ] = [
                        'src'           => $src,
                        'missing_parts' => $missing_parts,
                    ];
                }
            }
        }

        if ( ! empty( $missing_for ) ) {
            $severity = ( $is_critical_field && $is_create_capable ) ? 'critical' : 'warning';
            $issues[] = [
                'severity' => $severity,
                'field'    => $field_name,
                'message'  => sprintf(
                    "'%s' is enabled for %s but has no source field mapped for %s — values will be left blank/empty.",
                    $field_name,
                    implode( ', ', $applies_to ),
                    implode( ', ', $missing_for )
                ),
            ];
        }

        foreach ( $not_found_for as $supplier => $info ) {
            $src           = $info['src'];
            $missing_parts = $info['missing_parts'];
            // A compound ('+'-joined) source resolves by looking up and
            // joining each part individually (see missing_compound_parts()'s
            // own docblock) — name the specific missing part(s) rather than
            // implying the whole compound string is a literal field name
            // that doesn't exist, which would be misleading when most of it
            // is actually fine.
            $all_parts_missing = count( $missing_parts ) === count( array_filter( explode( '+', $src ), 'strlen' ) );
            $what_is_missing   = $all_parts_missing
                ? sprintf( "the '%s' field", $src )
                : sprintf(
                    "the '%s' %s of the '%s' compound field",
                    implode( "', '", $missing_parts ),
                    ( count( $missing_parts ) === 1 ) ? 'part' : 'parts',
                    $src
                );
            $issues[] = [
                'severity' => ( $is_critical_field && $is_create_capable ) ? 'critical' : 'warning',
                'field'    => $field_name,
                'supplier' => $supplier,
                'message'  => sprintf(
                    "'%s' is mapped to source field '%s' for %s, but %s does not exist in the current %s feed — check for a typo or a feed structure change.",
                    $field_name,
                    $src,
                    strtoupper( $supplier ),
                    $what_is_missing,
                    strtoupper( $supplier )
                ),
            ];
        }

        return $issues;
    }

    /**
     * A mapped source can be a single field path, or a '+'-joined compound
     * of several — e.g. product_cat's Xchange source
     * 'master_category+sub_category' — resolved by
     * MMI_Pipeline_Field_Resolver::get_nested_value() by looking up and
     * joining each part individually, never as one literal combined key.
     * The field-existence index ($available, built by
     * extract_field_names()/get_available_fields()) only ever contains
     * individual part names as keys — it has no notion of '+' at all — so
     * checking the whole compound string against it as a single literal key
     * always reports a false "not found," even when every part it's built
     * from is genuinely present in the feed. This mirrors get_nested_value()'s
     * own compound-splitting exactly, so a mapping that validates here is
     * guaranteed to also actually resolve at import time, and vice versa.
     *
     * @param string $src       The mapped source value (single field or
     *                          '+'-compound).
     * @param array  $available [field_name => true] index for this supplier's
     *                          feed (never empty when this is called — see
     *                          call site).
     * @return string[] The parts NOT found (empty array = fully resolvable).
     */
    private static function missing_compound_parts( string $src, array $available ): array {
        if ( isset( $available[ $src ] ) ) {
            return []; // Exact match — the common, non-compound case.
        }
        if ( strpos( $src, '+' ) === false ) {
            return [ $src ];
        }
        $missing = [];
        foreach ( explode( '+', $src ) as $part ) {
            $part = trim( $part );
            if ( $part !== '' && ! isset( $available[ $part ] ) ) {
                $missing[] = $part;
            }
        }
        return $missing;
    }

    /**
     * Render the validation results as an admin-notice-style panel.
     *
     * Returns an empty string when there are no issues (caller can echo
     * unconditionally without an extra empty-check).
     *
     * @param array $issues Result of validate().
     * @return string Escaped HTML.
     */
    public static function render_html( array $issues ): string {
        if ( empty( $issues ) ) {
            return '';
        }

        $critical = array_values( array_filter( $issues, fn( $i ) => ( $i['severity'] ?? '' ) === 'critical' ) );
        $warning  = array_values( array_filter( $issues, fn( $i ) => ( $i['severity'] ?? '' ) === 'warning' ) );

        $panel_class = ! empty( $critical ) ? 'mmi-config-issues-critical' : 'mmi-config-issues-warning';

        ob_start();
        ?>
        <div class="mmi-config-issues-panel <?php echo esc_attr( $panel_class ); ?>">
            <h4 class="mmi-config-issues-heading">
                <span class="dashicons dashicons-warning"></span>
                <?php if ( ! empty( $critical ) ) : ?>
                    Configuration Issues Found — this import may create incomplete or empty products
                <?php else : ?>
                    Configuration Warnings
                <?php endif; ?>
            </h4>
            <ul class="mmi-config-issues-list">
                <?php foreach ( $critical as $issue ) : ?>
                    <li class="mmi-config-issue mmi-config-issue-critical">
                        <span class="dashicons dashicons-dismiss"></span>
                        <?php echo esc_html( $issue['message'] ); ?>
                    </li>
                <?php endforeach; ?>
                <?php foreach ( $warning as $issue ) : ?>
                    <li class="mmi-config-issue mmi-config-issue-warning">
                        <span class="dashicons dashicons-flag"></span>
                        <?php echo esc_html( $issue['message'] ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="mmi-config-issues-footer">
                Fix these in <strong>Field Mappings</strong> (or <strong>Data Sources</strong> for
                primary key/feed issues) before running this import or enabling its schedule.
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Validated supplier IDs — mirrors ProductImportController::get_enabled_suppliers().
     *
     * A source counts here the moment it's validated — there is no separate
     * manual "enabled" step (see AGENTS.md's "Enabled Toggle Eliminated"
     * entry). The `enabled` column is no longer read for this decision
     * anywhere in the plugin.
     *
     * @return string[]
     */
    private static function get_enabled_suppliers(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'mmi_data_sources';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
            $ids = $wpdb->get_col( "SELECT supplier_id FROM {$table} WHERE config_status = 'validated' ORDER BY display_order ASC" );
            if ( is_array( $ids ) && count( $ids ) > 0 ) {
                return $ids;
            }
        }
        return MMI_DB::get_setting( 'mmi_pipeline_enabled_suppliers', [] );
    }

    /**
     * supplier_id => source_type (api/url/upload/dropbox/gdrive) map, used to
     * tailor feed-file messaging to what's actually possible for that source
     * (e.g. 'upload' sources have no "Data Fetch" action to suggest running).
     *
     * @return array<string, string>
     */
    private static function get_supplier_source_types(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'mmi_data_sources';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return [];
        }
        $rows = $wpdb->get_results( "SELECT supplier_id, source_type FROM {$table}", ARRAY_A );
        $map  = [];
        foreach ( (array) $rows as $row ) {
            $map[ $row['supplier_id'] ] = $row['source_type'] ?: 'api';
        }
        return $map;
    }

    /**
     * Real field names available in a supplier's canonical feed file, as an
     * [field_name => true] lookup set. Mirrors extractFieldsFromObject() /
     * loadFieldsFromFile() in import-settings.js (same flattened dot-path
     * field names, same depth limit, same Xchange {products:[...]} vs. plain
     * array format handling) so a mapping that validates here is guaranteed
     * to also appear as a real option in that JS-driven Source box browser —
     * one shared notion of "what fields does this feed actually have",
     * checked in both places rather than drifting into two definitions.
     *
     * Reads the SAME canonical file used by the "3. Source feed file checks"
     * section below — not a per-row Browse: file selection, which is a
     * cosmetic-only aid and never determines which file the import actually
     * reads (see panel-field-mapping.php).
     *
     * Cached per-request (a profile can have dozens of mapped fields per
     * supplier — parse each feed once, not once per field) and in a
     * transient keyed by the file's own mtime, per this project's rule
     * against reading a multi-MB feed file on every request without a
     * transient cache.
     *
     * @param string      $supplier
     * @param string      $json_path
     * @param string|null $filename  Explicit file to index (e.g. a field
     *   mapping's own per-supplier 'file' override — a promotions feed like
     *   'xchange-promotions.json', not the default product feed). Defaults
     *   to the supplier's main product feed when omitted.
     * @return array<string, true>
     */
    private static function get_available_fields( string $supplier, string $json_path, ?string $filename = null ): array {
        $default_filename = $supplier === 'xchange' ? 'xchange-products.json' : "{$supplier}-products.json";
        $filename          = $filename ?? $default_filename;

        static $memo = [];
        $memo_key = $supplier . '|' . $filename;
        if ( isset( $memo[ $memo_key ] ) ) {
            return $memo[ $memo_key ];
        }

        $file = $json_path . $filename;

        if ( ! file_exists( $file ) ) {
            return $memo[ $memo_key ] = [];
        }

        $cache_key = 'mmi_pl_fidx_' . md5( $memo_key . '|' . filemtime( $file ) );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $memo[ $memo_key ] = $cached;
        }

        $decoded = json_decode( (string) file_get_contents( $file ), true );

        // Only the DEFAULT product feed is unwrapped by its 'products' key.
        // An explicit per-field file override (a promotions feed, etc.) is
        // scanned as-is — extract_field_names() already recurses into a
        // nested wrapper key one level deep, which is exactly what produces
        // the dot-path a mapping's source field expects (e.g. Xchange's
        // {"promotions": [...]} wrapper yields 'promotions.street_price',
        // matching that field's actual configured source verbatim). Blindly
        // unwrapping every file by a 'products' key here would silently
        // index nothing for a file that has no such key at all — e.g.
        // skuport-promos.json is a bare array, not wrapped in anything.
        $products_data = $decoded;
        if ( $filename === $default_filename && is_array( $decoded ) && isset( $decoded['products'] ) && is_array( $decoded['products'] ) ) {
            $products_data = $decoded['products'];
        } elseif ( is_array( $decoded ) && isset( $decoded['web_assets'] ) && is_array( $decoded['web_assets'] ) ) {
            // xchange-web-assets.json is the one exception to "don't unwrap
            // non-default files" above: unlike promotions (whose 'promotions.'
            // wrapper IS the real field-mapping namespace — inject_promotion()
            // merges under that literal prefix), web-assets fields are merged
            // flat onto the item root with no prefix at all (see
            // MMI_Pipeline_Field_Resolver::enrich_item_with_web_assets()'s own
            // docblock). Without unwrapping here, this index would be keyed
            // web_assets.requirements.mac while a real mapping's source is
            // the flat requirements.mac — a permanent, false "field not
            // found" for every web-assets-sourced field. No filename gate
            // needed: 'web_assets' only ever appears in this one file.
            $products_data = $decoded['web_assets'];
        }

        $fields = [];
        if ( is_array( $products_data ) ) {
            self::extract_field_names( $products_data, '', $fields );
        }

        set_transient( $cache_key, $fields, self::FIELD_INDEX_CACHE_TTL );

        return $memo[ $memo_key ] = $fields;
    }

    /**
     * Settings key for a supplier's calculated filterable-fields schema —
     * one record per supplier (the default product feed only), mirroring
     * pk_quality_dismissals_key()'s per-source (not per-profile) scoping,
     * since a data source's own raw fields don't vary by which import
     * profile happens to be viewing them.
     */
    private static function filterable_fields_settings_key( string $supplier_id ): string {
        return 'mmi_pipeline_source_fields_' . $supplier_id;
    }

    /**
     * Returns the supplier's calculated filterable-fields schema, recomputing
     * it on demand if missing or if the feed file has changed since the
     * stored fingerprint was recorded (mirrors get_pk_quality_dismissals()'s
     * fingerprint-invalidation exactly). This is the defense-in-depth lazy
     * path: calculate_filterable_fields() is also fired eagerly right after
     * an upload/API source is fetched or validated (see
     * MMI_Pipeline_Upload_Source_Fetcher::fetch() and
     * DataSourceController::mark_source_validated()), but a scheduled cron
     * re-fetch (Xchange/SkuPort updaters) doesn't go through either of those
     * hooks — reading here always self-heals instead of silently serving a
     * schema built from a now-stale feed.
     *
     * @return array{fields: array<string, array{label:string, unique_count:int, high_cardinality:bool, values?:string[]}>}
     */
    public static function get_filterable_fields( string $supplier_id, string $json_path, ?string $filename = null ): array {
        $default_filename = $supplier_id === 'xchange' ? 'xchange-products.json' : "{$supplier_id}-products.json";
        $filename          = $filename ?? $default_filename;
        $file              = $json_path . $filename;

        if ( ! file_exists( $file ) ) {
            return [ 'fields' => [] ];
        }

        $fingerprint = (string) filemtime( $file );
        $stored      = (array) MMI_DB::get_setting( self::filterable_fields_settings_key( $supplier_id ), [] );

        if ( ( $stored['fingerprint'] ?? '' ) === $fingerprint && isset( $stored['fields'] ) ) {
            return [ 'fields' => (array) $stored['fields'] ];
        }

        return self::calculate_filterable_fields( $supplier_id, $json_path, $filename );
    }

    /**
     * Scans a supplier's full feed (every record, not the 10/50-item sample
     * used elsewhere for name/attribute discovery — this is a one-time
     * calculation triggered on upload/connect, not a per-request cost) and
     * persists, per field, its full unique-value set — the schema the Review
     * & Compare filter bar's "Create Only" source-data filter group is built
     * from. Reuses extract_field_names() for field-NAME discovery (the same
     * convention get_available_fields() already relies on: names are stable
     * across a feed's rows, so a bounded sample is enough to find them) and
     * MMI_Pipeline_Field_Resolver::get_nested_value() per record per
     * candidate field to build the real value corpus, the same pattern
     * scan_primary_key_quality() already uses for one field at a time.
     *
     * @return array{fields: array<string, array{label:string, unique_count:int, high_cardinality:bool, values?:string[]}>}
     */
    public static function calculate_filterable_fields( string $supplier_id, string $json_path, ?string $filename = null ): array {
        $default_filename = $supplier_id === 'xchange' ? 'xchange-products.json' : "{$supplier_id}-products.json";
        $filename          = $filename ?? $default_filename;
        $file              = $json_path . $filename;

        $result = [ 'fields' => [] ];

        if ( ! file_exists( $file ) ) {
            return $result;
        }

        $decoded = json_decode( (string) file_get_contents( $file ), true );
        $records = $decoded;
        if ( $filename === $default_filename && is_array( $decoded ) && isset( $decoded['products'] ) && is_array( $decoded['products'] ) ) {
            $records = $decoded['products'];
        }

        if ( ! is_array( $records ) ) {
            return $result;
        }

        $records = array_values( $records );

        // Field NAMES only — extract_field_names() samples up to 10 records,
        // which is fine here for the same reason it's fine in
        // get_available_fields(): a feed's field shape is consistent across
        // rows, so this only needs to discover which paths exist, not
        // collect their values.
        $candidate_fields = [];
        self::extract_field_names( $records, '', $candidate_fields );

        $fields = [];
        foreach ( array_keys( $candidate_fields ) as $path ) {
            $skip = false;
            foreach ( self::FILTERABLE_FIELD_SKIP_PATTERNS as $pattern ) {
                if ( preg_match( $pattern, $path ) ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) {
                continue;
            }

            $values = [];
            foreach ( $records as $record ) {
                if ( ! is_array( $record ) ) {
                    continue;
                }
                $value = MMI_Pipeline_Field_Resolver::get_nested_value( $record, $path );
                if ( $value === null || $value === '' || is_array( $value ) ) {
                    continue;
                }
                $str = (string) $value;
                if ( strlen( $str ) > 0 && strlen( $str ) <= 100 ) {
                    $values[ $str ] = true; // dedupe as we go
                }
            }

            $unique_count = count( $values );
            if ( $unique_count < 2 ) {
                continue; // Nothing to filter by — constant or entirely blank.
            }

            $last_seg = basename( str_replace( '.', '/', $path ) );
            $label    = ucwords( str_replace( [ '_', '-' ], ' ', $last_seg ) );

            $high_cardinality = $unique_count > self::FILTERABLE_FIELD_VALUE_CAP;

            $entry = [
                'label'            => $label,
                'unique_count'     => $unique_count,
                'high_cardinality' => $high_cardinality,
            ];
            if ( ! $high_cardinality ) {
                $unique_values = array_keys( $values );
                sort( $unique_values );
                $entry['values'] = $unique_values;
            }

            $fields[ $path ] = $entry;
        }

        $result = [ 'fields' => $fields ];

        MMI_DB::set_setting( self::filterable_fields_settings_key( $supplier_id ), [
            'fingerprint' => (string) filemtime( $file ),
            'fields'      => $fields,
        ] );

        return $result;
    }

    /**
     * Full per-record scan of a supplier's real feed for blank/duplicate
     * values in a given primary-key field — every blank row (with a
     * best-effort display label, since the PK field itself is blank) and
     * every duplicate value's full set of affected rows, not capped. Public
     * and reusable: the summary check below (validate_primary_key_data_quality())
     * and the "Primary Key Data Quality" review modal's AJAX endpoints
     * (ImportSettingsController.php) both call this same scan rather than
     * each re-reading/re-walking the feed their own way.
     *
     * Confirmed against a real uploaded source, not a hypothetical: a 409-row
     * catalog with 17 blank UPCs (category-header rows carried into the data)
     * and 2 duplicated UPC values.
     *
     * Cached per-request and in a transient keyed by the feed file's own
     * mtime — the 'fingerprint' returned alongside the scan IS that mtime,
     * and is what dismissal storage uses to detect "the file changed since
     * this was dismissed" and treat stale dismissals as void automatically.
     *
     * @return array{total:int, fingerprint:string, blank_rows:array<int,array{index:int,label:string}>, duplicate_groups:array<string,array<int,array{index:int,label:string}>>}
     */
    public static function scan_primary_key_quality( string $supplier, string $pk_field, string $json_path ): array {
        static $memo = [];
        $memo_key = $supplier . '|' . $pk_field;
        if ( isset( $memo[ $memo_key ] ) ) {
            return $memo[ $memo_key ];
        }

        $empty_result = [ 'total' => 0, 'fingerprint' => '', 'blank_rows' => [], 'duplicate_groups' => [] ];

        $filename = $supplier === 'xchange' ? 'xchange-products.json' : "{$supplier}-products.json";
        $file     = $json_path . $filename;

        if ( ! file_exists( $file ) ) {
            return $memo[ $memo_key ] = $empty_result; // Missing-feed case is already reported by section 3.
        }

        $fingerprint = (string) filemtime( $file );
        $cache_key   = 'mmi_pl_pkscan_' . md5( $memo_key . '|' . $fingerprint );
        $scan        = get_transient( $cache_key );
        if ( is_array( $scan ) ) {
            return $memo[ $memo_key ] = $scan;
        }

        $decoded = json_decode( (string) file_get_contents( $file ), true );
        $records = $decoded;
        if ( is_array( $decoded ) && isset( $decoded['products'] ) && is_array( $decoded['products'] ) ) {
            $records = $decoded['products'];
        }
        if ( ! is_array( $records ) ) {
            return $memo[ $memo_key ] = array_merge( $empty_result, [ 'fingerprint' => $fingerprint ] );
        }

        $total       = 0;
        $blank_rows  = [];
        $value_index = []; // value => [row index, ...]
        $records     = array_values( $records );
        foreach ( $records as $i => $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            $total++;
            $value = MMI_Pipeline_Field_Resolver::get_nested_value( $record, $pk_field );
            if ( $value === null || $value === '' ) {
                $blank_rows[] = [ 'index' => $i, 'label' => self::guess_row_label( $record, $pk_field ) ];
                continue;
            }
            $value_index[ (string) $value ][] = $i;
        }

        $duplicate_groups = [];
        foreach ( $value_index as $value => $indices ) {
            if ( count( $indices ) < 2 ) {
                continue;
            }
            $duplicate_groups[ $value ] = array_map(
                static fn( $i ) => [ 'index' => $i, 'label' => self::guess_row_label( $records[ $i ], $pk_field ) ],
                $indices
            );
        }

        $scan = [
            'total'            => $total,
            'fingerprint'      => $fingerprint,
            'blank_rows'       => $blank_rows,
            'duplicate_groups' => $duplicate_groups,
        ];
        set_transient( $cache_key, $scan, self::FIELD_INDEX_CACHE_TTL );

        return $memo[ $memo_key ] = $scan;
    }

    /**
     * Best-effort human-readable label for a row whose primary-key field is
     * blank (or, for a duplicate group, just to help tell rows apart) — picks
     * the first other non-empty scalar field's value, since there's no
     * guaranteed "name" field across every supplier's feed shape.
     */
    private static function guess_row_label( array $record, string $pk_field ): string {
        foreach ( $record as $key => $value ) {
            if ( $key === $pk_field || ! is_scalar( $value ) || $value === '' ) {
                continue;
            }
            $label = (string) $value;
            return strlen( $label ) > 60 ? substr( $label, 0, 60 ) . '…' : $label;
        }
        return '(no other fields to identify this row)';
    }

    /**
     * Settings key for a supplier+field's dismissed-issue record. One record
     * per (supplier, pk_field) pair — primary keys are source-scoped, not
     * profile-scoped (see MMI_DB::get_primary_key()'s own callers), so a
     * dismissal made from any one profile's pre-flight check or the Data
     * Sources "Primary Key Data Quality" review modal applies everywhere
     * that source is used, matching how the primary key setting itself works.
     */
    private static function pk_quality_dismissals_key( string $supplier, string $pk_field ): string {
        return 'mmi_pk_quality_dismissed_' . $supplier . '_' . md5( $pk_field );
    }

    /**
     * Currently-valid dismissals for a supplier+field, or empty sets if the
     * feed has changed since they were recorded (fingerprint mismatch) — a
     * re-fetched/re-uploaded source could have fixed the exact rows that were
     * dismissed, introduced different ones, or shifted row indices entirely,
     * so a stale dismissal record is discarded rather than silently
     * misapplied to different rows than the user actually reviewed.
     */
    public static function get_pk_quality_dismissals( string $supplier, string $pk_field, string $fingerprint ): array {
        $stored = (array) MMI_DB::get_setting( self::pk_quality_dismissals_key( $supplier, $pk_field ), [] );
        if ( ( $stored['fingerprint'] ?? '' ) !== $fingerprint ) {
            return [ 'blank_indices' => [], 'duplicate_values' => [] ];
        }
        return [
            'blank_indices'    => array_values( (array) ( $stored['blank_indices'] ?? [] ) ),
            'duplicate_values' => array_values( (array) ( $stored['duplicate_values'] ?? [] ) ),
        ];
    }

    /**
     * Dismiss one blank row (by index) or one duplicate value, for the given
     * feed state. Writing with the current $fingerprint means a stale
     * dismissal record (see get_pk_quality_dismissals()) is naturally
     * replaced, not merged with now-meaningless old indices.
     *
     * @param string $type  'blank' or 'duplicate'.
     * @param string $value For 'blank', the row index (as a string); for
     *                      'duplicate', the literal primary-key value.
     * @return array The updated dismissal sets.
     */
    public static function dismiss_pk_quality_issue( string $supplier, string $pk_field, string $fingerprint, string $type, string $value ): array {
        $dismissed = self::get_pk_quality_dismissals( $supplier, $pk_field, $fingerprint );

        if ( $type === 'blank' ) {
            $dismissed['blank_indices'][] = (int) $value;
            $dismissed['blank_indices']   = array_values( array_unique( $dismissed['blank_indices'] ) );
        } elseif ( $type === 'duplicate' ) {
            $dismissed['duplicate_values'][] = $value;
            $dismissed['duplicate_values']   = array_values( array_unique( $dismissed['duplicate_values'] ) );
        }

        MMI_DB::set_setting( self::pk_quality_dismissals_key( $supplier, $pk_field ), [
            'fingerprint'      => $fingerprint,
            'blank_indices'    => $dismissed['blank_indices'],
            'duplicate_values' => $dismissed['duplicate_values'],
        ] );

        return $dismissed;
    }

    /**
     * Dismiss an arbitrary batch of blank rows and duplicate values in one
     * action — the shared implementation behind both "Dismiss All" (the
     * AJAX handler computes and passes every currently-outstanding item) and
     * "Dismiss Selected" (the handler passes only whatever the user
     * checked). Always merges with whatever's already dismissed for this
     * exact file state rather than replacing it outright, so a batch call
     * can never silently un-dismiss something acknowledged earlier.
     */
    public static function dismiss_pk_quality_all( string $supplier, string $pk_field, string $fingerprint, array $blank_indices, array $duplicate_values ): void {
        // Merge with whatever's already dismissed for this exact file state,
        // rather than replacing it outright — a "Dismiss All" click only
        // ever receives the currently-*outstanding* set (see the AJAX
        // handler), which already excludes anything dismissed earlier in the
        // same session; overwriting instead of merging would silently
        // un-dismiss those already-acknowledged items.
        $existing = self::get_pk_quality_dismissals( $supplier, $pk_field, $fingerprint );

        MMI_DB::set_setting( self::pk_quality_dismissals_key( $supplier, $pk_field ), [
            'fingerprint'      => $fingerprint,
            'blank_indices'    => array_values( array_unique( array_map(
                'intval',
                array_merge( $existing['blank_indices'], $blank_indices )
            ) ) ),
            // strval() is required, not cosmetic: a caller building this list
            // from array_keys( $scan['duplicate_groups'] ) gets PHP-cast ints
            // back for any purely-numeric duplicate value (e.g. a UPC) — PHP
            // silently stores a numeric-looking array key as an int, not a
            // string. Stored as ints here, they'd never strictly match the
            // (string) values validate_primary_key_data_quality() compares
            // against, so nothing would actually end up dismissed.
            'duplicate_values' => array_values( array_unique( array_map(
                'strval',
                array_merge( $existing['duplicate_values'], $duplicate_values )
            ) ) ),
        ] );
    }

    /**
     * The scan minus whatever's already been dismissed — the single shared
     * computation both the pre-flight summary warning below and the
     * "Primary Key Data Quality" review modal's AJAX report
     * (ImportSettingsController.php) build on, so "what's outstanding" can
     * never disagree between the two surfaces.
     *
     * @return array{scan:array, dismissed:array, outstanding_blank:array, outstanding_duplicates:array}
     */
    public static function get_outstanding_pk_quality_issues( string $supplier, string $pk_field, string $json_path ): array {
        $scan = self::scan_primary_key_quality( $supplier, $pk_field, $json_path );
        if ( $scan['fingerprint'] === '' ) {
            return [ 'scan' => $scan, 'dismissed' => [ 'blank_indices' => [], 'duplicate_values' => [] ], 'outstanding_blank' => [], 'outstanding_duplicates' => [] ];
        }

        $dismissed = self::get_pk_quality_dismissals( $supplier, $pk_field, $scan['fingerprint'] );

        $outstanding_blank = array_filter(
            $scan['blank_rows'],
            static fn( $row ) => ! in_array( $row['index'], $dismissed['blank_indices'], true )
        );
        // (string) cast on $value is required, not cosmetic: a purely
        // numeric array key (e.g. a UPC like "801813162509") is silently
        // stored by PHP as an int, not a string — strict in_array() against
        // $dismissed['duplicate_values'] (always strings, from AJAX/JSON
        // input) would otherwise never match and no duplicate could ever
        // actually be dismissed.
        $outstanding_duplicates = array_filter(
            $scan['duplicate_groups'],
            static fn( $rows, $value ) => ! in_array( (string) $value, $dismissed['duplicate_values'], true ),
            ARRAY_FILTER_USE_BOTH
        );

        return [
            'scan'                   => $scan,
            'dismissed'              => $dismissed,
            'outstanding_blank'      => array_values( $outstanding_blank ),
            'outstanding_duplicates' => $outstanding_duplicates,
        ];
    }

    /**
     * Scan a supplier's real, full feed for blank or duplicate values in its
     * configured primary-key source field — the two failure modes that leave
     * a field "configured" (this check only runs once a PK source/wc pair
     * already exists) while still producing wrong or dropped products at
     * import time, and that neither the wizard's picker (one sampled row) nor
     * the "is a PK configured at all" check above can see. Issues the user
     * has already dismissed (see dismiss_pk_quality_issue()/_all() above) are
     * excluded here, so an acknowledged, known-bad row/value stops appearing
     * in this pre-flight warning until the feed actually changes.
     *
     * @return array<int, array{severity:string, supplier:string, message:string}>
     */
    private static function validate_primary_key_data_quality( string $supplier, string $pk_field, string $json_path ): array {
        $result = self::get_outstanding_pk_quality_issues( $supplier, $pk_field, $json_path );
        if ( $result['scan']['fingerprint'] === '' ) {
            return []; // No feed file — already reported by section 3.
        }

        $scan                   = $result['scan'];
        $outstanding_blank      = $result['outstanding_blank'];
        $outstanding_duplicates = $result['outstanding_duplicates'];

        $issues = [];

        if ( ! empty( $outstanding_blank ) ) {
            $blank_count = count( $outstanding_blank );
            $issues[] = [
                // Every row blank means this field is effectively not present
                // in this feed at all — the same "nothing will match" outcome
                // as having no primary key configured. A partial blank count
                // still lets the rest of the feed import correctly, so it's a
                // warning, not a hard stop.
                'severity' => ( $blank_count === $scan['total'] ) ? 'critical' : 'warning',
                'supplier' => $supplier,
                'message'  => sprintf(
                    "%d of %d products from '%s' have a blank %s — these can't be matched by primary key and will be skipped or created without a stable identifier.",
                    $blank_count,
                    $scan['total'],
                    $supplier,
                    $pk_field
                ),
            ];
        }

        if ( ! empty( $outstanding_duplicates ) ) {
            $duplicate_row_count = array_sum( array_map( 'count', $outstanding_duplicates ) );
            $examples = [];
            $shown    = 0;
            foreach ( $outstanding_duplicates as $value => $rows ) {
                if ( $shown >= 5 ) {
                    break;
                }
                $examples[] = "'{$value}' (" . count( $rows ) . "x)";
                $shown++;
            }
            $issues[] = [
                'severity' => 'warning',
                'supplier' => $supplier,
                'message'  => sprintf(
                    "%d products from '%s' share a duplicate %s value with at least one other product (%s%s) — only one product per value can be matched correctly; the rest will overwrite it instead of their own record.",
                    $duplicate_row_count,
                    $supplier,
                    $pk_field,
                    implode( ', ', $examples ),
                    ( count( $outstanding_duplicates ) > 5 ) ? ', and more' : ''
                ),
            ];
        }

        return $issues;
    }

    /**
     * Recursively flattens a decoded feed record into dot-path field names,
     * writing into $fields as an [field_name => true] set. Single source of
     * truth for "what fields can this feed populate" — used both by
     * field-existence validation (this class) and by the wizard's
     * field-picker dropdowns (ImportSettingsController.php's
     * mmi_pipeline_get_fields_from_file AJAX handler), which also wants a
     * representative sample value per field (optional $samples).
     *
     * An array of associative items (e.g. Xchange's `webassets.requirements`,
     * one object per OS) is addressed one of two ways, both resolvable by
     * MMI_Pipeline_Field_Resolver::get_nested_value() — never as a single
     * bare merged path like 'requirements.cpu', which always resolves to
     * null at import time (there's no 'cpu' key directly on the list):
     *  - Every item shares a key holding a short, distinct scalar value
     *    (e.g. {os:"mac",...} / {os:"windows",...}) → addressed by that
     *    value: 'requirements[os=mac].cpu' — order-independent and
     *    self-documenting in a saved field mapping, unlike a positional
     *    index into a vendor feed whose item order isn't guaranteed.
     *  - No shared discriminator (heterogeneous blocks that don't all carry
     *    the same key — e.g. a mix of {Heading:...}/{Paragraph:...}/
     *    {List:...} objects) → addressed positionally:
     *    'long_description[0].Heading', 'long_description[1].Paragraph'.
     *
     * @param mixed      $node    Current value being walked (list, assoc array, or scalar).
     * @param string     $prefix  Dot-path built so far ('' at the top level).
     * @param array      $fields  Accumulator, keyed by field name => true.
     * @param array|null $samples Optional by-reference dot-path => sample value map.
     */
    public static function extract_field_names( $node, string $prefix, array &$fields, ?array &$samples = null ): void {
        if ( ! is_array( $node ) ) {
            return;
        }

        $is_list = array_keys( $node ) === range( 0, count( $node ) - 1 );
        if ( $is_list ) {
            // Sample the first few records only — a supplier feed's item
            // shape is consistent across rows; scanning more buys nothing
            // but slower decoding.
            $items = array_slice( $node, 0, 10 );

            // A list reached at the file's own root, or one key below it
            // (prefix has no dot yet — the top-level record list itself,
            // prefix '', or a wrapper key like a promotions file's
            // {"promotions": [...]}, prefix 'promotions') is architecturally
            // "many records of one type": a separate mechanism elsewhere
            // (MMI_Pipeline_Field_Resolver's promotion-injection) looks up
            // and flattens exactly ONE matching record from a list shaped
            // this way before a field is ever resolved out of it, so these
            // stay merged into one shared field namespace — same treatment
            // as the top-level products list itself, and the only shape
            // that produces the flat 'promotions.street_price' dot-path a
            // real field mapping's source value actually expects.
            //
            // A list reached while already at least two levels deep inside
            // a single record's own structure (prefix already has a dot —
            // e.g. webassets.requirements, webassets.long_description) is a
            // genuinely small, distinct set of named sub-parts of THAT one
            // record, never collapsed to a single item by anything
            // upstream — those get discriminator/positional per-item
            // addressing below so each item's fields are individually,
            // reliably selectable.
            if ( strpos( $prefix, '.' ) === false ) {
                foreach ( $items as $item ) {
                    self::extract_field_names( $item, $prefix, $fields, $samples );
                }
                return;
            }

            $discriminator = self::find_list_discriminator_key( $items );

            foreach ( $items as $i => $item ) {
                $item_prefix = ( $discriminator !== null && is_array( $item ) )
                    ? $prefix . '[' . $discriminator . '=' . $item[ $discriminator ] . ']'
                    : $prefix . '[' . $i . ']';
                self::extract_field_names( $item, $item_prefix, $fields, $samples );
            }
            return;
        }

        // Recurse into a nested object only while the CURRENT prefix has no
        // dot yet, i.e. for the top level and one level below it. A bracket
        // segment (from the list branch above) doesn't itself count as a
        // dot-level, matching get_nested_value()'s own treatment of
        // 'foo[0]'/'foo[key=val]' as one path segment.
        $can_recurse = strpos( $prefix, '.' ) === false;

        foreach ( $node as $key => $value ) {
            $full_path             = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;
            $fields[ $full_path ] = true;

            if ( $samples !== null ) {
                self::record_field_sample( $samples, $full_path, $value );
            }

            if ( is_array( $value ) && $can_recurse ) {
                self::extract_field_names( $value, $full_path, $fields, $samples );
            }
        }
    }

    /**
     * Finds a key shared by every item in a list of associative-array items
     * whose value is a short, non-empty scalar in every item — a natural
     * "discriminator" (e.g. 'os': 'mac'/'windows') that lets each item be
     * addressed by value instead of by position. Deliberately generic — no
     * hardcoded field-name guesses — so it applies to any vendor feed shaped
     * this way, not just Xchange's requirements block. Returns null when no
     * key qualifies (e.g. heterogeneous blocks with no shared key at all),
     * in which case the caller falls back to positional indexing.
     *
     * @param array $items Up to the first 10 sampled list items.
     * @return string|null
     */
    private static function find_list_discriminator_key( array $items ): ?string {
        if ( count( $items ) < 2 ) {
            return null;
        }

        $common_keys = null;
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || ( array_keys( $item ) === range( 0, count( $item ) - 1 ) ) ) {
                return null; // Not every item is an associative object.
            }
            $keys        = array_keys( $item );
            $common_keys = ( $common_keys === null ) ? $keys : array_intersect( $common_keys, $keys );
        }

        foreach ( (array) $common_keys as $key ) {
            $qualifies = true;
            foreach ( $items as $item ) {
                $value = $item[ $key ];
                // Must be a short scalar with none of the characters that
                // would break dot-splitting or bracket parsing in
                // get_nested_value() if used as a filter value.
                if ( ! is_scalar( $value ) || $value === ''
                    || strlen( (string) $value ) > 40
                    || strpbrk( (string) $value, '.[]' ) !== false ) {
                    $qualifies = false;
                    break;
                }
            }
            if ( $qualifies ) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Records a representative sample value for one field — used only by
     * the field-picker dropdown ($samples is null during plain validation).
     * A previously-recorded blank scalar is treated the same as "no sample
     * yet" so a category-header row's blank cell in the first sampled
     * record doesn't permanently block a later, real value from the same
     * 10-row scan window.
     *
     * @param array  $samples   By-reference dot-path => sample value map.
     * @param string $full_path
     * @param mixed  $val
     */
    private static function record_field_sample( array &$samples, string $full_path, $val ): void {
        $has_sample = array_key_exists( $full_path, $samples ) && $samples[ $full_path ] !== '';
        if ( $has_sample || $val === null || $val === '' ) {
            return;
        }

        if ( is_array( $val ) ) {
            $is_val_list = array_keys( $val ) === range( 0, count( $val ) - 1 );
            if ( ! $is_val_list ) {
                $samples[ $full_path ] = '{...}';
            } elseif ( count( $val ) > 0 ) {
                $samples[ $full_path ] = is_array( $val[0] )
                    ? '[' . count( $val ) . ' items]'
                    : wp_json_encode( array_slice( $val, 0, 3 ) );
            } else {
                $samples[ $full_path ] = '[]';
            }
            return;
        }

        $sample_value = (string) $val;
        if ( strlen( $sample_value ) > 60 ) {
            $sample_value = substr( $sample_value, 0, 60 ) . '...';
        }
        $samples[ $full_path ] = $sample_value;
    }
}
