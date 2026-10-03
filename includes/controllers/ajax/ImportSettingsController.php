<?php
/**
 * Import Settings AJAX Controller
 * Handles autosave and profile management for import configurations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Derives a field's "enabled" state from whether it has a real mapping —
 * added 2026-09-12 when the manual Enabled/Show-in-Preview toggles (and
 * their group/master bulk controls) were removed from the Field Mapping
 * table at the user's direction: a field (or, for a multi-supplier field,
 * one supplier's contribution to it) is enabled purely by having a real
 * source path or a constant value configured, never by a separate switch.
 *
 * Mirrors the shape 'source'/'use_constant_value' are already saved in — a
 * plain scalar for a single-source/custom field, or an array keyed by
 * supplier id for a multi-supplier one — so panel-field-mapping.php's own
 * $is_enabled read (is_bool() / is_array() / else) and every real importer
 * code path that already branches on 'enabled' (class-product-import-
 * worker.php, class-dynamic-product-importer.php) needed zero changes: only
 * WHO writes this value changed, not its shape or how it's consumed.
 *
 * @param array $mapping One field's mapping array, AFTER the property this
 *                        request is saving has already been applied to it.
 * @return bool|array
 */
function mmi_pipeline_derive_field_enabled_state( array $mapping ) {
    $source     = $mapping['source'] ?? null;
    $use_const  = $mapping['use_constant_value'] ?? null;
    $is_multi   = is_array( $source ) || is_array( $use_const );

    if ( ! $is_multi ) {
        $has_source = '' !== trim( (string) ( $source ?? '' ) );
        $has_const  = ! empty( $use_const );
        return $has_source || $has_const;
    }

    $suppliers = array_unique( array_merge(
        is_array( $source ) ? array_keys( $source ) : [],
        is_array( $use_const ) ? array_keys( $use_const ) : []
    ) );

    $result = [];
    foreach ( $suppliers as $sid ) {
        $has_source = is_array( $source ) && '' !== trim( (string) ( $source[ $sid ] ?? '' ) );
        $has_const  = is_array( $use_const )
            ? ! empty( $use_const[ $sid ] )
            : ! empty( $use_const ); // legacy field-wide constant, applies to every supplier
        $result[ $sid ] = $has_source || $has_const;
    }
    return $result;
}



// Autosave field mapping property (generic handler for all field properties)
add_action('wp_ajax_mmi_autosave_field_property', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');
    
    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }
    
    $field_name = sanitize_text_field($_POST['field_name'] ?? '');
    $property = sanitize_text_field($_POST['property'] ?? '');
    $value = $_POST['value'] ?? '';
    $supplier = sanitize_text_field($_POST['supplier'] ?? '');
    $profile = sanitize_text_field($_POST['profile'] ?? 'default');
    
    if (empty($field_name) || empty($property)) {
        wp_send_json_error(['message' => 'Field name and property required']);
    }

    $known_properties = [
        'use_constant_value', 'constant_value', 'source', 'file',
        'transform', 'transform_params', 'conditions', 'condition_match_logic', 'condition_fallback_enabled',
        'condition_fallback_value', 'tax_hierarchical', 'tax_hierarchical_leaf_only',
        'tax_hierarchical_delim', 'type', 'group', 'image_array_mode',
    ];
    if ( ! in_array( $property, $known_properties, true ) ) {
        wp_send_json_error(['message' => 'Invalid property']);
    }

    // transform_params/conditions need their JSON validated before anything
    // else — done here, outside the atomic section below, so a validation
    // failure never needs to unwind a lock/mutation that already started.
    $clean_params     = null;
    $clean_conditions = null;

    if ( 'transform_params' === $property ) {
        // $value is a JSON-encoded object from JS: {"find":"x","replace":"y"}
        $decoded = json_decode( wp_unslash( $value ), true );
        if ( ! is_array( $decoded ) ) {
            wp_send_json_error( [ 'message' => 'Invalid transform_params JSON' ] );
            return;
        }
        // Sanitize every param value as text (no HTML allowed in param strings)
        $clean_params = [];
        foreach ( $decoded as $pk => $pv ) {
            $clean_params[ sanitize_key( $pk ) ] = sanitize_text_field( (string) $pv );
        }
    }

    if ( 'conditions' === $property ) {
        // $value is a JSON-encoded array of condition objects
        $decoded = json_decode( wp_unslash( $value ), true );
        if ( ! is_array( $decoded ) ) {
            wp_send_json_error( [ 'message' => 'Invalid conditions JSON' ] );
            return;
        }
        $clean_conditions = [];
        // Shared condition builder shape (what the UI saves) — same
        // sanitizer as Catalog Maintenance, so a condition means the same
        // thing in both places.
        if ( \MannMade\DataPipeline\Importers\Condition_Evaluator::is_builder_shape( $decoded ) ) {
            foreach ( $decoded as $cond ) {
                $c = \MannMade\DataPipeline\Stock_Override_Resolver::sanitize_condition( $cond );
                if ( $c !== null ) {
                    $clean_conditions[] = $c;
                }
            }
            $decoded = [];
        }
        $allowed_ops = [
            'is_not_empty', 'is_empty', 'equals', 'not_equals',
            'contains', 'not_contains', 'greater_than', 'less_than',
        ];
        foreach ( $decoded as $cond ) {
            if ( ! is_array( $cond ) ) {
                continue;
            }
            $op = sanitize_text_field( $cond['operator'] ?? 'is_not_empty' );
            if ( ! in_array( $op, $allowed_ops, true ) ) {
                $op = 'is_not_empty';
            }
            $logic = strtoupper( sanitize_text_field( $cond['logic'] ?? 'AND' ) );
            if ( ! in_array( $logic, [ 'AND', 'OR' ], true ) ) {
                $logic = 'AND';
            }
            $clean_conditions[] = [
                'operator' => $op,
                'compare'  => sanitize_text_field( (string) ( $cond['compare'] ?? '' ) ),
                'logic'    => $logic,
            ];
        }
    }

    // Atomic read-modify-write — see MMI_DB::update_field_mappings()'s docblock.
    $saved = MMI_DB::update_field_mappings( $profile, function ( $mappings ) use (
        $field_name, $property, $value, $supplier, $clean_params, $clean_conditions
    ) {
        if (!isset($mappings[$field_name])) {
            $mappings[$field_name] = [];
        }

        switch ($property) {
            case 'use_constant_value':
                $checked = isset($value) ? (bool)$value : false;
                if (!empty($supplier)) {
                    // Per-source constant — same array-keyed-by-supplier shape as
                    // 'source'/'file' below. Preserves every other supplier's
                    // already-saved state rather than collapsing the whole
                    // field to a single bool.
                    if (!isset($mappings[$field_name]['use_constant_value']) || !is_array($mappings[$field_name]['use_constant_value'])) {
                        $mappings[$field_name]['use_constant_value'] = [];
                    }
                    $mappings[$field_name]['use_constant_value'][$supplier] = $checked;
                } else {
                    // Legacy: applies to every supplier (see resolve_constant()).
                    $mappings[$field_name]['use_constant_value'] = $checked;
                }
                break;

            case 'constant_value':
                $clean_value = sanitize_text_field($value);
                if (!empty($supplier)) {
                    if (!isset($mappings[$field_name]['constant_value']) || !is_array($mappings[$field_name]['constant_value'])) {
                        $mappings[$field_name]['constant_value'] = [];
                    }
                    $mappings[$field_name]['constant_value'][$supplier] = $clean_value;
                } else {
                    $mappings[$field_name]['constant_value'] = $clean_value;
                }
                break;

            case 'source':
                $clean_value = sanitize_text_field($value);
                // Treat "NULL" / "null" / empty as unconfigured — prevent the literal
                // string "NULL" from being persisted when a select has no selection.
                if ($clean_value === 'NULL' || $clean_value === 'null') {
                    $clean_value = '';
                }
                if (!empty($supplier)) {
                    // Per-supplier source
                    if (!isset($mappings[$field_name]['source'])) {
                        $mappings[$field_name]['source'] = [];
                    }
                    $mappings[$field_name]['source'][$supplier] = $clean_value;
                } else {
                    // Legacy single source
                    $mappings[$field_name]['source'] = $clean_value;
                }
                break;

            case 'file':
                if (!empty($supplier)) {
                    if (!isset($mappings[$field_name]['file'])) {
                        $mappings[$field_name]['file'] = [];
                    }
                    $mappings[$field_name]['file'][$supplier] = sanitize_text_field($value);
                }
                break;

            case 'transform':
                $mappings[$field_name][$property] = sanitize_text_field($value);
                break;

            case 'transform_params':
                $mappings[$field_name]['transform_params'] = $clean_params;
                break;

            case 'conditions':
                $mappings[$field_name]['conditions'] = $clean_conditions;
                break;

            case 'condition_match_logic':
                $mappings[$field_name]['condition_match_logic'] = 'any' === $value ? 'any' : 'all';
                break;

            case 'condition_fallback_enabled':
                $mappings[$field_name]['condition_fallback_enabled'] = isset( $value ) ? (bool) $value : false;
                break;

            case 'condition_fallback_value':
                $mappings[$field_name]['condition_fallback_value'] = sanitize_text_field( $value );
                break;

            case 'tax_hierarchical':
            case 'tax_hierarchical_leaf_only':
                $mappings[$field_name][$property] = isset( $value ) ? (bool) $value : false;
                break;

            case 'image_array_mode':
                // Per-supplier, like use_constant_value above — only
                // meaningful on '_product_image_url' (see
                // MMI_Pipeline_Field_Resolver::assemble_product_images()),
                // but not restricted to that field name here since a custom
                // field could theoretically reuse the same toggle.
                $checked = isset( $value ) ? (bool) $value : false;
                if ( ! empty( $supplier ) ) {
                    if ( ! isset( $mappings[$field_name]['image_array_mode'] ) || ! is_array( $mappings[$field_name]['image_array_mode'] ) ) {
                        $mappings[$field_name]['image_array_mode'] = [];
                    }
                    $mappings[$field_name]['image_array_mode'][$supplier] = $checked;
                } else {
                    $mappings[$field_name]['image_array_mode'] = $checked;
                }
                break;

            case 'tax_hierarchical_delim':
                // Kept short and un-trimmed of whitespace-only delimiters (e.g. a
                // lone space is a valid, if unusual, level separator) — just cap
                // the length so nothing absurd gets stored.
                $mappings[$field_name][$property] = mb_substr( sanitize_text_field( $value ), 0, 10 );
                break;

            case 'type':
                // Set once, immediately, when a custom field is first added
                // (import-settings.js's addCustomFieldRow()) — without this,
                // merge()'s fallback for any custom field with no saved 'type'
                // is 'string', so a taxonomy-typed custom field would silently
                // lose its term-picker constant-value UI on every subsequent
                // page load. Restricted to the types
                // $render_constant_input() in panel-field-mapping.php actually
                // branches on; anything else is dropped rather than stored.
                $allowed_types = [ 'taxonomy', 'boolean', 'integer', 'decimal', 'string' ];
                $clean_type    = sanitize_key( $value );
                if ( in_array( $clean_type, $allowed_types, true ) ) {
                    $mappings[$field_name]['type'] = $clean_type;
                }
                break;

            case 'group':
                // Same reasoning as 'type' above, set at the same moment (a
                // custom field's group is derived from the same fieldType the
                // user picked in the Add Custom Field dropdown) — panel-field-
                // mapping.php's section grouping reads $mapping['group']
                // exclusively, not 'type', so a taxonomy custom field with only
                // 'type' persisted still filed itself under "Custom Meta"
                // (merge()'s custom-field-append fallback defaults 'group' to
                // 'meta') instead of "Taxonomies" on every subsequent load.
                // Restricted to $group_labels' known keys in panel-field-
                // mapping.php; anything else is dropped rather than stored.
                $allowed_groups = [ 'core', 'pricing', 'product_type', 'inventory', 'shipping', 'media', 'taxonomy', 'meta' ];
                $clean_group    = sanitize_key( $value );
                if ( in_array( $clean_group, $allowed_groups, true ) ) {
                    $mappings[$field_name]['group'] = $clean_group;
                }
                break;
        }

        // Re-derive 'enabled' from whatever the field's mapping now looks
        // like, after the property change above is applied — see
        // mmi_pipeline_derive_field_enabled_state()'s own docblock.
        // Unconditional (not gated to $property === 'source' etc.) since
        // this is a cheap, pure computation over a small in-memory array,
        // not a query — simpler and safer than enumerating exactly which
        // properties can affect mapped-ness.
        $mappings[ $field_name ]['enabled'] = mmi_pipeline_derive_field_enabled_state( $mappings[ $field_name ] );

        return $mappings;
    } );

    if (!$saved) {
        wp_send_json_error(['message' => 'Could not save — profile is busy, try again']);
    }

    // Sources ticked in the Create Profile wizard — only used while the
    // profile has no saved sources yet (see MMI_Pipeline_Field_Conflicts).
    $draft_sources = json_decode( wp_unslash( $_POST['sources'] ?? '[]' ), true );
    $conflicts     = MMI_Pipeline_Field_Conflicts::for_profile( $profile, is_array( $draft_sources ) ? $draft_sources : [] );

    wp_send_json_success([
        'message' => 'Property saved',
        'field' => $field_name,
        'property' => $property,
        'value' => $value,
        'supplier' => $supplier,
        'profile' => $profile,
        'conflicts' => $conflicts[ $field_name ] ?? [],
    ]);
});

// Autosave primary key configuration
add_action('wp_ajax_mmi_autosave_primary_key', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');
    
    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }
    
    $supplier = sanitize_text_field($_POST['supplier'] ?? '');
    $field_type = sanitize_text_field($_POST['field_type'] ?? ''); // 'source' or 'wc'
    $value = sanitize_text_field($_POST['value'] ?? '');
    
    if (empty($supplier) || empty($field_type)) {
        wp_send_json_error(['message' => 'Supplier and field type required']);
    }
    
    if ($field_type === 'source') {
        MMI_DB::set_primary_key( $supplier, 'source', $value );
    } else if ($field_type === 'wc') {
        MMI_DB::set_primary_key( $supplier, 'wc', $value );
    } else {
        wp_send_json_error(['message' => 'Invalid field type']);
    }
    
    wp_send_json_success([
        'message' => 'Primary key saved',
        'supplier' => $supplier,
        'field_type' => $field_type,
        'value' => $value
    ]);
});

// ─── Primary Key Data Quality — review/dismiss blank & duplicate values ────
//
// Backs the "Primary Key Data Quality" review modal, reachable from the
// Data Sources table's Configure button and the wizard's inline PK editor.
// All three handlers share MMI_Pipeline_Config_Validator's scan/dismissal
// methods with the pre-flight "Configuration warnings" check the wizard's
// Run Import button already used (see that class for the fingerprint-based
// staleness handling: a re-fetched/re-uploaded source automatically voids
// stale dismissals rather than silently misapplying them to different rows).

add_action('wp_ajax_mmi_pipeline_get_pk_quality_report', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $supplier = sanitize_key($_POST['supplier'] ?? '');
    if (empty($supplier)) {
        wp_send_json_error(['message' => 'Supplier required']);
        return;
    }

    // Defaults to the supplier's actual configured source PK — the modal's
    // primary entry points (Configure button, wizard PK editor) always want
    // "review the field currently in use," not an arbitrary one.
    $pk_field = sanitize_text_field($_POST['pk_field'] ?? '');
    if ($pk_field === '') {
        $pk_field = MMI_DB::get_primary_key($supplier, 'source', '');
    }
    if ($pk_field === '') {
        wp_send_json_error(['message' => 'No primary key field is configured for this source yet.']);
        return;
    }

    $json_path = mmi_shared_lib_json_dir();
    $result = MMI_Pipeline_Config_Validator::get_outstanding_pk_quality_issues($supplier, $pk_field, $json_path);

    if ($result['scan']['fingerprint'] === '') {
        wp_send_json_error(['message' => 'No cached data file found for this source yet — fetch or upload it first.']);
        return;
    }

    // Duplicate groups as a list, not a PHP-array-keyed object: a purely
    // numeric primary-key value (a UPC, e.g.) is silently cast to an int key
    // by PHP, which would make json_encode()'s output shape depend on
    // whether every single duplicate value happened to be numeric — a list
    // of {value, rows} objects sidesteps that entirely and is what the modal
    // needs to iterate anyway.
    $duplicate_list = [];
    foreach ($result['outstanding_duplicates'] as $value => $rows) {
        $duplicate_list[] = ['value' => (string) $value, 'rows' => $rows];
    }

    wp_send_json_success([
        'supplier'                  => $supplier,
        'pk_field'                  => $pk_field,
        'fingerprint'               => $result['scan']['fingerprint'],
        'total'                     => $result['scan']['total'],
        'blank_rows'                => array_values($result['outstanding_blank']),
        'duplicate_groups'          => $duplicate_list,
        'dismissed_blank_count'     => count($result['dismissed']['blank_indices']),
        'dismissed_duplicate_count' => count($result['dismissed']['duplicate_values']),
    ]);
});

add_action('wp_ajax_mmi_pipeline_dismiss_pk_quality_issue', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $supplier = sanitize_key($_POST['supplier'] ?? '');
    $pk_field = sanitize_text_field($_POST['pk_field'] ?? '');
    $type     = sanitize_key($_POST['type'] ?? ''); // 'blank' or 'duplicate'
    $value    = sanitize_text_field($_POST['value'] ?? '');

    if (empty($supplier) || $pk_field === '' || !in_array($type, ['blank', 'duplicate'], true) || $value === '') {
        wp_send_json_error(['message' => 'Missing or invalid parameters']);
        return;
    }

    $json_path = mmi_shared_lib_json_dir();

    // Fingerprint is always derived server-side from the file itself, never
    // trusted from the client — a stale client-supplied fingerprint could
    // otherwise silently write a dismissal record under an old file state
    // that get_pk_quality_dismissals() would immediately discard as stale
    // on the very next read anyway, wasting the click for no visible reason.
    $scan = MMI_Pipeline_Config_Validator::scan_primary_key_quality($supplier, $pk_field, $json_path);
    if ($scan['fingerprint'] === '') {
        wp_send_json_error(['message' => 'No cached data file found for this source.']);
        return;
    }

    MMI_Pipeline_Config_Validator::dismiss_pk_quality_issue($supplier, $pk_field, $scan['fingerprint'], $type, $value);

    wp_send_json_success(['message' => 'Dismissed']);
});

add_action('wp_ajax_mmi_pipeline_dismiss_pk_quality_bulk', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $supplier = sanitize_key($_POST['supplier'] ?? '');
    $pk_field = sanitize_text_field($_POST['pk_field'] ?? '');

    if (empty($supplier) || $pk_field === '') {
        wp_send_json_error(['message' => 'Missing or invalid parameters']);
        return;
    }

    $json_path = mmi_shared_lib_json_dir();

    // Recomputes "what's outstanding right now" server-side rather than
    // trusting a client-supplied list — guarantees a "Dismiss All" click
    // always dismisses the true current set, not a copy that could have
    // gone stale between the report loading and the click.
    $result = MMI_Pipeline_Config_Validator::get_outstanding_pk_quality_issues($supplier, $pk_field, $json_path);
    if ($result['scan']['fingerprint'] === '') {
        wp_send_json_error(['message' => 'No cached data file found for this source.']);
        return;
    }

    $blank_indices    = array_column($result['outstanding_blank'], 'index');
    $duplicate_values = array_keys($result['outstanding_duplicates']);

    MMI_Pipeline_Config_Validator::dismiss_pk_quality_all(
        $supplier,
        $pk_field,
        $result['scan']['fingerprint'],
        $blank_indices,
        $duplicate_values
    );

    wp_send_json_success([
        'message'              => 'All outstanding issues dismissed',
        'dismissed_blank'      => count($blank_indices),
        'dismissed_duplicates' => count($duplicate_values),
    ]);
});

// Dismiss only the specific blank-row indices / duplicate values the user
// checked in the review modal ("Dismiss Selected") — the checkbox-driven
// sibling of the "Dismiss All" handler above. Both share
// MMI_Pipeline_Config_Validator::dismiss_pk_quality_all()'s merge-safe
// storage; this one just passes the client's explicit selection instead of
// a server-computed "everything outstanding" list.
add_action('wp_ajax_mmi_pipeline_dismiss_pk_quality_selected', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $supplier = sanitize_key($_POST['supplier'] ?? '');
    $pk_field = sanitize_text_field($_POST['pk_field'] ?? '');
    $blank_indices = array_map('intval', (array) ($_POST['blank_indices'] ?? []));
    $duplicate_values = array_map('sanitize_text_field', (array) ($_POST['duplicate_values'] ?? []));

    if (empty($supplier) || $pk_field === '') {
        wp_send_json_error(['message' => 'Missing or invalid parameters']);
        return;
    }
    if (empty($blank_indices) && empty($duplicate_values)) {
        wp_send_json_error(['message' => 'Nothing selected to dismiss']);
        return;
    }

    $json_path = mmi_shared_lib_json_dir();

    // Fingerprint derived server-side from the file itself, same as the
    // single-item endpoint — never trusted from the client.
    $scan = MMI_Pipeline_Config_Validator::scan_primary_key_quality($supplier, $pk_field, $json_path);
    if ($scan['fingerprint'] === '') {
        wp_send_json_error(['message' => 'No cached data file found for this source.']);
        return;
    }

    MMI_Pipeline_Config_Validator::dismiss_pk_quality_all(
        $supplier,
        $pk_field,
        $scan['fingerprint'],
        $blank_indices,
        $duplicate_values
    );

    wp_send_json_success([
        'message'              => 'Selected issues dismissed',
        'dismissed_blank'      => count($blank_indices),
        'dismissed_duplicates' => count($duplicate_values),
    ]);
});

// Ensure a supplier's sample-data cache ({supplier_id}-products.json) exists,
// so the wizard's primary-key "Field in the file" select has real field names
// to offer. Xchange/SkuPort already have this from their scheduled syncs — the
// client-side loadFieldsFromFile() reads it directly with zero server round
// trip. Upload/URL/Dropbox/Google Drive sources only get that file once
// something has actually fetched them, which doesn't happen automatically for
// Upload sources at all (there's no "sync schedule" for a file that's already
// local) — this is the on-demand equivalent, fetched once per source, not
// looped or automatic, so it doesn't need throttler wiring beyond what
// Data_Source_Manager's own fetch paths already apply for network sources.
add_action('wp_ajax_mmi_pipeline_ensure_source_cache', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $supplier_id = sanitize_key($_POST['supplier_id'] ?? '');
    if (empty($supplier_id)) {
        wp_send_json_error(['message' => 'Supplier ID required']);
    }

    $json_dir    = mmi_shared_lib_json_dir();
    $filename    = $supplier_id . '-products.json';
    $cache_file  = $json_dir . $filename;

    if (file_exists($cache_file)) {
        wp_send_json_success(['cached' => true, 'filename' => $filename]);
    }

    // Upload sources need no network fetch at all — the file is already on
    // disk via its WP attachment — so they don't wait on Data_Source_Manager
    // (see MMI_Pipeline_Upload_Source_Fetcher's docblock for why that class
    // can't be relied on here).
    if (class_exists('MMI_Pipeline_Upload_Source_Fetcher') && MMI_Pipeline_Upload_Source_Fetcher::is_upload_source($supplier_id)) {
        try {
            MMI_Pipeline_Upload_Source_Fetcher::fetch($supplier_id);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }
        wp_send_json_success(['cached' => true, 'filename' => $filename]);
        return;
    }

    if (!class_exists('\MannMade\Integrations\Acquisition\Data_Source_Manager')) {
        wp_send_json_error(['message' => 'Data source fetch mechanism unavailable']);
        return;
    }

    try {
        \MannMade\Integrations\Acquisition\Data_Source_Manager::instance()->fetch_from_supplier($supplier_id);
    } catch (\Throwable $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
        return;
    }

    wp_send_json_success(['cached' => true, 'filename' => $filename]);
});

/**
 * Extract field names + one sample value per field from a supplier's cached
 * source-data JSON file, entirely server-side — backs the wizard's Source
 * Field browsing dropdown/datalist (loadFieldsFromFile() in
 * import-settings.js).
 *
 * Previously this data was derived by shipping the ENTIRE raw feed file to
 * the browser and parsing it client-side — for Xchange's live catalog cache
 * that's ~17MB transferred on every page load just to list ~40 field names
 * (confirmed via a real HAR capture: this one client-side call accounted for
 * over half the Data Pipeline admin page's total transfer weight). Reading +
 * json_decode()ing the file server-side is comparatively free — local disk
 * I/O, no network hop to the browser — and the small extracted result is
 * cached in a transient keyed by the file's mtime, so a second request for
 * the same unchanged file doesn't even pay the decode cost again.
 */
add_action('wp_ajax_mmi_pipeline_get_fields_from_file', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $filename = sanitize_file_name($_POST['filename'] ?? '');
    if (empty($filename)) {
        wp_send_json_error(['message' => 'Filename required']);
        return;
    }

    $json_dir = mmi_shared_lib_json_dir();
    $path     = $json_dir . $filename;

    // realpath() on the directory only — it must exist regardless of
    // whether this particular file does.
    $real_dir = realpath($json_dir);
    if (!$real_dir) {
        wp_send_json_error(['message' => 'Invalid filename']);
        return;
    }

    // Check existence BEFORE realpath()ing the file itself: realpath()
    // returns false for a path that simply doesn't exist yet, which is the
    // normal, expected state for a source that's never been fetched (see
    // loadFieldsFromFile()'s comment in import-settings.js). Resolving the
    // file's real path first — as this used to do — made that ordinary case
    // indistinguishable from an actual path-traversal attempt: both hit the
    // same generic "Invalid filename" response, so the client's `not_found`
    // check never fired and the auto-fetch-then-retry flow never ran,
    // permanently starving Upload/URL/Dropbox/Google Drive sources of their
    // one on-demand cache-rebuild path.
    if (!file_exists($path)) {
        // Matches loadFieldsFromFile()'s existing "not found" branch, which
        // calls mmi_pipeline_ensure_source_cache then retries once — flagged
        // explicitly so the client can tell "no file yet" apart from a real
        // decode/permission failure.
        wp_send_json_error(['message' => 'File not found', 'not_found' => true]);
        return;
    }

    // The file exists — NOW resolve its real path and confirm it's still
    // inside $json_dir, as a second, independent guard against a symlink or
    // any crafted value sanitize_file_name() didn't strip.
    $real_path = realpath($path);
    if (!$real_path || strpos($real_path, $real_dir . DIRECTORY_SEPARATOR) !== 0) {
        wp_send_json_error(['message' => 'Invalid filename']);
        return;
    }

    $mtime     = filemtime($real_path);
    $cache_key = 'mmi_pl_fields_' . md5($filename . '|' . $mtime);
    $cached    = get_transient($cache_key);
    if ($cached !== false) {
        wp_send_json_success(['fields' => $cached]);
        return;
    }

    $raw  = file_get_contents($real_path);
    $data = json_decode($raw, true);
    unset($raw);

    if ($data === null) {
        wp_send_json_error(['message' => 'Could not parse JSON']);
        return;
    }

    // Xchange format: { products: [...], api_time: ..., debug: ... }.
    // SkuPort format: [...] (already an array) — used as-is.
    // xchange-web-assets.json format: { generated_at: ..., web_assets: [...] }
    // — unwrapped for the same reason as 'products' above, but unlike
    // xchange-promotions.json's 'promotions' wrapper (deliberately KEPT,
    // since inject_promotion() merges a matched promo under that literal
    // 'promotions.' namespace), web-assets fields are merged flat onto the
    // item root with no prefix at all — see
    // MMI_Pipeline_Field_Resolver::enrich_item_with_web_assets()'s own
    // docblock. Leaving 'web_assets' un-unwrapped here would offer
    // web_assets.requirements.mac-shaped paths that can never resolve any
    // data, since the enriched item never has a 'web_assets' key.
    if (isset($data['products']) && is_array($data['products'])) {
        $products = $data['products'];
    } elseif (isset($data['web_assets']) && is_array($data['web_assets'])) {
        $products = $data['web_assets'];
    } else {
        $products = $data;
    }
    unset($data);

    $samples    = [];
    $fields_set = [];
    MMI_Pipeline_Config_Validator::extract_field_names($products, '', $fields_set, $samples);
    $field_names = array_keys($fields_set);
    unset($products);

    $fields = [];
    foreach ($field_names as $name) {
        // A blank column header (a stray trailing column in a spreadsheet
        // export — confirmed to occur in real uploaded sources) produces a
        // field literally named '' — never a meaningful field-mapping or
        // primary-key choice, so it's excluded here rather than shown as a
        // blank, unselectable-looking option in the dropdown.
        if ($name === '' || substr($name, -1) === '.') {
            continue;
        }
        $fields[] = [
            'name'   => $name,
            'sample' => $samples[$name] ?? '(no sample)',
        ];
    }
    usort($fields, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

    set_transient($cache_key, $fields, 15 * MINUTE_IN_SECONDS);

    wp_send_json_success(['fields' => $fields]);
});

// Field-name extraction lives in MMI_Pipeline_Config_Validator::extract_field_names()
// — the single source of truth for "what fields can this feed populate,"
// shared with field-existence validation so the two can never disagree
// about what a feed contains. See that method's docblock for the
// discriminator-key/positional-index addressing scheme used for arrays of
// nested objects (e.g. Xchange's webassets.requirements/long_description).

// Get import profiles
add_action('wp_ajax_mmi_get_import_profiles', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');
    
    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }
    
    // Scoped to direction='import' — the unfiltered list includes
    // export-created profiles, which would otherwise leak onto this tab's
    // profile picker with bogus defaults (export creation never sets
    // sources/import_mode for real import use).
    $profiles = MMI_DB::get_profiles_by_direction( 'import' );

    // Run the legacy-mode migration at most once per hour.
    // migrate_profiles_to_modes() only touches profiles with a NULL/empty import_mode
    // (i.e. created before the mode column existed), so it is a no-op on current installs.
    // The transient prevents it from running on every page load for large profile sets.
    if ( false === get_transient( 'mmi_profiles_mode_migration_done' ) ) {
        MMI_DB::migrate_profiles_to_modes();
        $profiles = MMI_DB::get_profiles_by_direction( 'import' ); // Refresh after migration.
    }

    wp_send_json_success($profiles);
});

// Save import profile
add_action('wp_ajax_mmi_save_import_profile', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');
    
    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }
    
    $profile_id         = sanitize_text_field($_POST['profile_id'] ?? '');
    $name               = sanitize_text_field($_POST['name'] ?? '');
    $description        = sanitize_text_field($_POST['description'] ?? '');
    $copy_from          = sanitize_text_field($_POST['copy_from'] ?? '');
    $import_mode        = sanitize_text_field($_POST['import_mode'] ?? 'update-only');
    $product_scope      = sanitize_text_field($_POST['product_scope'] ?? 'all_products');
    $data_type          = sanitize_text_field($_POST['data_type'] ?? 'product');
    $mode_settings      = [];
    $product_identifier = null;

    // Only a value the registry actually knows about is trusted — anything
    // else (a stale client, a tampered request) silently falls back to the
    // schema default rather than persisting an unrecognized data type no
    // handler exists for. See DATA_PIPELINE_PHASE2_SCOPING.md Milestone 1 —
    // this only captures/persists the choice; nothing downstream branches on
    // it yet, so an invalid value here would otherwise sit inert and unnoticed.
    if ( ! class_exists( 'MMI_Data_Type_Registry' ) || ! array_key_exists( $data_type, MMI_Data_Type_Registry::get_choices_for_ui() ) ) {
        $data_type = 'product';
    }

    if (!empty($_POST['mode_settings'])) {
        $decoded = json_decode(stripslashes($_POST['mode_settings']), true);
        if (is_array($decoded)) {
            $mode_settings = $decoded;
        }
    }

    if (!empty($_POST['product_identifier'])) {
        $decoded = json_decode(stripslashes($_POST['product_identifier']), true);
        if (is_array($decoded)) {
            // Allow only the known keys; sanitize each string value.
            $product_identifier = [
                'storage_type'      => sanitize_text_field( $decoded['storage_type'] ?? '' ),
                'storage_config'    => array_map( 'sanitize_text_field', (array) ( $decoded['storage_config'] ?? [] ) ),
                'auto_apply_to_new' => (bool) ( $decoded['auto_apply_to_new'] ?? true ),
                'onboarded_plugins' => array_map( 'sanitize_text_field', (array) ( $decoded['onboarded_plugins'] ?? [] ) ),
            ];
        }
    }

    $sources = [];
    if (!empty($_POST['sources'])) {
        $raw = is_array($_POST['sources'])
            ? $_POST['sources']
            : json_decode(stripslashes($_POST['sources']), true);
        if (is_array($raw)) {
            $sources = array_values(array_map('sanitize_text_field', $raw));
        }
    }

    // Validate allowed modes.
    if (!in_array($import_mode, MMI_Pipeline_Field_Mapping_Defaults::IMPORT_MODES, true)) {
        wp_send_json_error(['message' => 'Invalid import mode']);
    }

    // Validate product_scope.
    if (!in_array($product_scope, MMI_Pipeline_Field_Mapping_Defaults::PRODUCT_SCOPES, true)) {
        wp_send_json_error(['message' => 'Invalid product scope']);
    }
    
    // Validate inputs
    if (empty($profile_id) || empty($name)) {
        wp_send_json_error(['message' => 'Profile ID and name required']);
    }
    
    // Validate profile ID format
    if (!preg_match('/^[a-z0-9_]+$/', $profile_id)) {
        wp_send_json_error(['message' => 'Invalid profile ID format']);
    }
    
    // Get existing profiles — deliberately unfiltered by direction here: profile_id
    // is a single global primary key across import AND export profiles, so the
    // collision check below must see the full table or a new import profile could
    // silently overwrite an existing export profile's row via set_profiles()'s
    // ON DUPLICATE KEY UPDATE.
    $profiles = MMI_DB::get_profiles();

    // Check if profile already exists (allow overwrite only when copying).
    //
    // A row can exist here without a real profile ever having been "created":
    // the wizard's Field Mapping/Attributes steps autosave into this same
    // profile_id while the profile is still being set up, before this
    // handler ever runs — MMI_DB::set_field_mappings() upserts a placeholder
    // row (profile_name = profile_id, everything else left at its column
    // default) so those autosaves have somewhere to land. That placeholder
    // must not read as "already exists" and block the real creation. The
    // wizard's own client-side validation never lets Create Profile fire
    // without at least one source checked, so a genuinely-finalized profile
    // always has a non-empty `sources` — the placeholder never sets it —
    // making it a reliable signal to tell the two apart without a schema change.
    $existing_is_finalized = isset($profiles[$profile_id]) && !empty($profiles[$profile_id]['sources']);
    if ($existing_is_finalized && empty($copy_from)) {
        wp_send_json_error(['message' => 'Profile already exists']);
    }

    // When duplicating, only ever inherit from another *import* profile — an
    // export profile's copy_from match must be treated as not found, both
    // because its mode/scope shape doesn't apply here and to avoid leaking
    // its existence to this tab.
    if (!empty($copy_from) && isset($profiles[$copy_from]) && ($profiles[$copy_from]['direction'] ?? 'import') !== 'import') {
        $copy_from = '';
    }
    if (!empty($copy_from) && isset($profiles[$copy_from])) {
        if (empty($_POST['import_mode'])) {
            $import_mode   = $profiles[$copy_from]['import_mode'] ?? 'update-only';
            $mode_settings = $profiles[$copy_from]['mode_settings'] ?? [];
        }
        if (empty($_POST['product_scope'])) {
            $product_scope      = $profiles[$copy_from]['product_scope'] ?? 'all_products';
            $product_identifier = $profiles[$copy_from]['product_identifier'] ?? null;
        }
    }
    
    // Add profile to list
    $profiles[$profile_id] = [
        'name'               => $name,
        'description'        => $description,
        'import_mode'        => $import_mode,
        'mode_settings'      => $mode_settings,
        'product_scope'      => $product_scope,
        'product_identifier' => $product_identifier,
        'sources'            => $sources,
        'data_type'          => $data_type,
    ];
    
    $saved = MMI_DB::set_profiles( $profiles );
    if ( ! $saved ) {
        MMI_Logger::info(  '[MMI Pipeline] mmi_save_import_profile: set_profiles() failed for profile_id=' . $profile_id , [], 'general', 'MMI_Pipeline_ImportSettings_Controller' );
        wp_send_json_error( [ 'message' => 'Database error: profile could not be saved. Check server error logs.' ] );
    }
    
    // If copying from another profile, copy the field mappings
    if (!empty($copy_from) && isset($profiles[$copy_from])) {
        $source_mappings = MMI_DB::get_field_mappings( $copy_from );
        MMI_DB::set_field_mappings( $profile_id, $source_mappings );
    } elseif ( ! $existing_is_finalized && 'product' === $data_type && class_exists( 'MMI_Pipeline_Field_Mapping_Defaults' ) ) {
        // First-time finalization of a genuinely new PRODUCT profile (not a
        // copy): fill in MMI_Pipeline_Field_Mapping_Defaults::blank_mappings()
        // for every field (and, within a multi-supplier field, every
        // supplier) the wizard's autosave never touched. Without this,
        // get_effective() would silently fall back to the plugin's built-in
        // per-supplier DEFAULTS for those gaps the instant this row's
        // 'sources' becomes non-empty (its is_finalized() signal flips),
        // undoing the wizard's "starts blank" promise the moment the profile
        // is actually created. Anything already autosaved during the wizard
        // is left exactly as the user left it — see fill_blank_gaps()'s own
        // docblock.
        //
        // Gated to data_type === 'product' — blank_mappings()/fill_blank_gaps()
        // are entirely built around MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS
        // (Product's own static field list with its per-supplier source/enabled
        // sub-arrays), which has no meaning for a non-Product profile. Before
        // this fix, every newly-created non-product profile got this full
        // 28-field Product-shaped blob written into its field_mappings column
        // regardless — confirmed live via wp eval to corrupt
        // MMI_Pipeline_Field_Schema_Resolver::get_effective()'s output for
        // such a profile (each bogus field's array-shaped 'enabled' — e.g.
        // {"skuport":false,"xchange":false} — gets appended as a "custom
        // field" with a non-boolean 'enabled', see that resolver's own
        // merge()) the moment Milestone 2 gave that function its first real
        // caller. See DATA_PIPELINE_PHASE2_SCOPING.md.
        $already_saved = MMI_DB::get_field_mappings( $profile_id );
        $already_saved = is_array( $already_saved ) ? $already_saved : [];
        MMI_DB::set_field_mappings(
            $profile_id,
            MMI_Pipeline_Field_Mapping_Defaults::fill_blank_gaps( $already_saved )
        );
    }

    mmi_data_pipeline_audit('import_profile.save', [
        'object_type' => 'import_profile',
        'object_id'   => $profile_id,
        'outcome'     => 'success',
    ]);

    wp_send_json_success([
        'message'    => 'Profile saved',
        'profile_id' => $profile_id,
        'profiles'   => $profiles
    ]);
});

// Delete import profile
add_action('wp_ajax_mmi_delete_import_profile', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');
    
    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }
    
    $profile_id = sanitize_text_field($_POST['profile_id'] ?? '');
    
    if (empty($profile_id)) {
        wp_send_json_error(['message' => 'Profile ID required']);
    }
    
    $profiles = MMI_DB::get_profiles();

    // Reject an export profile the same way as a missing one — this tab has
    // no business deleting a row it doesn't own, and "not found" avoids
    // leaking the other direction's profile IDs.
    if (!isset($profiles[$profile_id]) || ($profiles[$profile_id]['direction'] ?? 'import') !== 'import') {
        wp_send_json_error(['message' => 'Profile not found']);
    }

    // Unset locally so we can determine if any *import* profiles are left —
    // export profiles remaining in the table don't count toward this tab's
    // empty state.
    unset( $profiles[ $profile_id ] );
    $remaining_import_profiles = array_filter(
        $profiles,
        static fn( $p ) => ( $p['direction'] ?? 'import' ) === 'import'
    );

    // Permanently delete from the database (also removes field mappings)
    MMI_DB::delete_profile( $profile_id );

    mmi_data_pipeline_audit('import_profile.delete', [
        'object_type' => 'import_profile',
        'object_id'   => $profile_id,
        'outcome'     => 'success',
    ]);

    wp_send_json_success([
        'message'        => 'Profile deleted',
        'profile_id'     => $profile_id,
        'profiles_empty' => empty( $remaining_import_profiles ),
    ]);
});

// Rename import profile
add_action('wp_ajax_mmi_rename_import_profile', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $profile_id = sanitize_text_field($_POST['profile_id'] ?? '');
    $name       = sanitize_text_field($_POST['name'] ?? '');

    if (empty($profile_id) || empty($name)) {
        wp_send_json_error(['message' => 'Profile ID and name required']);
    }

    $profiles = MMI_DB::get_profiles();

    if (!isset($profiles[$profile_id]) || ($profiles[$profile_id]['direction'] ?? 'import') !== 'import') {
        wp_send_json_error(['message' => 'Profile not found']);
    }

    $profiles[$profile_id]['name'] = $name;
    MMI_DB::set_profiles( $profiles );

    mmi_data_pipeline_audit('import_profile.rename', [
        'object_type' => 'import_profile',
        'object_id'   => $profile_id,
        'outcome'     => 'success',
        'details'     => ['name' => $name],
    ]);

    wp_send_json_success([
        'message'    => 'Profile renamed',
        'profile_id' => $profile_id,
        'name'       => $name,
    ]);
});


// Autosave profile-specific settings (like allow_create_products)
add_action('wp_ajax_mmi_autosave_profile_setting', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');
    
    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }
    
    $setting_name = sanitize_text_field($_POST['setting_name'] ?? '');
    $enabled = isset($_POST['enabled']) ? (bool)$_POST['enabled'] : false;
    $profile = sanitize_text_field($_POST['profile'] ?? 'default');
    
    if (empty($setting_name)) {
        wp_send_json_error(['message' => 'Setting name required']);
    }
    
    // Map setting names to option keys
    $setting_map = [
        'allow_create_products' => 'mmi_pipeline_import_allow_create_products'
    ];
    
    if (!isset($setting_map[$setting_name])) {
        wp_send_json_error(['message' => 'Invalid setting name']);
    }
    
    // Build profile-specific option key
    $base_option = $setting_map[$setting_name];
    $option_key = $profile === 'default' ? $base_option : $base_option . '_' . $profile;
    
    MMI_DB::set_setting( $option_key, $enabled );
    
    wp_send_json_success([
        'message' => 'Setting saved',
        'setting' => $setting_name,
        'enabled' => $enabled,
        'profile' => $profile,
        'option_key' => $option_key
    ]);
});

// Load import profile data (for AJAX profile switching)
add_action('wp_ajax_mmi_load_import_profile', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');
    
    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }
    
    $profile = sanitize_text_field($_POST['profile'] ?? 'default');

    $profiles     = MMI_DB::get_profiles();
    $profile_meta = $profiles[$profile] ?? [];
    // An export profile isn't meant to be readable from this tab's Edit
    // modal — its import_mode/sources are meaningless defaults, not real
    // settings. Treat it the same as a missing profile.
    if ( ( $profile_meta['direction'] ?? 'import' ) !== 'import' ) {
        $profile_meta = [];
    }
    $import_mode   = $profile_meta['import_mode'] ?? 'update-only';
    $mode_settings = $profile_meta['mode_settings'] ?? [];

    // Load field mappings for this profile — effective (defaults + saved
    // overrides), matching what the initial PHP page render shows via
    // default-field-mappings.php. Returning only the raw saved rows here
    // (as before) meant any field/property a profile hadn't explicitly
    // customized was simply absent from this response; updateFieldMappingsTable()
    // in import-settings.js then left whatever value the *previously viewed*
    // profile had sitting in that input, making edits look like they leaked
    // across profiles even though the stored data was correctly separated.
    $field_mappings = class_exists( 'MMI_Pipeline_Field_Mapping_Defaults' )
        ? MMI_Pipeline_Field_Mapping_Defaults::get_effective( $profile )
        : MMI_DB::get_field_mappings( $profile );

    // Load attribute & variation config for this profile — read by the wizard's
    // Attributes step so it doesn't keep showing whichever profile was active
    // when the page first loaded (see mmi_get_attribute_config() below).
    $attribute_config = function_exists( 'mmi_get_attribute_config' )
        ? mmi_get_attribute_config( $profile )
        : [];

    // Derive allow_create exclusively from the stored import_mode.
    // The legacy allow_create_products setting is intentionally not consulted here;
    // migrate_from_wp_options() already migrated it to import_mode on first run.
    $allow_create_products = ( $import_mode === 'create-and-update' );
    
    // Calculate basic statistics (simplified version)
    $stats = [
        'total_items' => 0,
        'will_create' => 0,
        'will_update' => 0,
        'unchanged' => 0
    ];
    
    // Quick stats calculation from JSON files if they exist
    $json_dir = mmi_shared_lib_json_dir();
    $enabled_suppliers = MMI_DB::get_setting( 'mmi_pipeline_enabled_suppliers', [] );
    
    foreach ($enabled_suppliers as $supplier) {
        $filename = $supplier === 'xchange' ? 'xchange-products.json' : "{$supplier}-products.json";
        $file_path = $json_dir . $filename;
        
        if (file_exists($file_path)) {
            $data = json_decode(file_get_contents($file_path), true);
            if (is_array($data)) {
                // Xchange wraps the product list in {"products": [...], "wa_count": ..., ...};
                // SkuPort is a flat array. count($data) on the wrapped form returns the number
                // of top-level keys (~7), not the product count — unwrap 'products' first.
                $products       = $data['products'] ?? $data;
                $supplier_count = is_array( $products ) ? count( $products ) : 0;
                $stats['total_items'] += $supplier_count;
                
                // Rough calculation - if allow_create is false, assume most are updates
                if ($allow_create_products) {
                    $stats['will_create'] += intval($supplier_count * 0.2); // Assume 20% new
                    $stats['will_update'] += intval($supplier_count * 0.8); // Assume 80% updates
                } else {
                    $stats['will_create'] = 0;
                    $stats['will_update'] += $supplier_count;
                }
            }
        }
    }
    
    wp_send_json_success([
        'field_mappings' => $field_mappings,
        'attribute_config' => $attribute_config,
        'settings' => [
            'allow_create_products' => $allow_create_products,
            'import_mode'           => $import_mode,
            'mode_settings'         => $mode_settings,
            // Read by updateProfileSettings() (import-settings.js) so the toolbar
            // mode badge can reflect new_only's locked behavior instead of the
            // raw stored import_mode — see the mode-badge scope override there.
            'product_scope'         => $profile_meta['product_scope'] ?? 'all_products',
        ],
        // Full profile meta — used by the Edit Profile modal to pre-fill fields.
        'profile_meta' => [
            'profile_id'         => $profile,
            'name'               => $profile_meta['name']               ?? $profile,
            'import_mode'        => $import_mode,
            'mode_settings'      => $mode_settings,
            'product_scope'      => $profile_meta['product_scope']      ?? 'all_products',
            'product_identifier' => $profile_meta['product_identifier'] ?? null,
            'sources'            => $profile_meta['sources']            ?? [],
            // Read by openProfileWizardShow() (import-settings.js) so the
            // Attributes step correctly stays skipped when editing an
            // already-non-Product profile — see DATA_PIPELINE_PHASE2_SCOPING.md
            // Milestone 3. The Data Type step itself stays hidden either way
            // while editing (Milestone 1); this is purely so the wizard's
            // step LIST reflects the profile's real type, not a hardcoded
            // 'product' default that would silently re-show Attributes for
            // an existing non-Product profile.
            'data_type'          => $profile_meta['data_type']           ?? 'product',
        ],
        'stats'   => $stats,
        'profile' => $profile
    ]);
});

// Autosave import rule setting
add_action('wp_ajax_mmi_autosave_import_rule', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');
    
    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }
    
    $setting = sanitize_text_field($_POST['setting'] ?? '');
    $value = $_POST['value'] ?? '';
    
    if (empty($setting)) {
        wp_send_json_error(['message' => 'Setting name required']);
    }
    
    // Map setting names to option keys
    $setting_map = [
        'duplicate_strategy' => 'mmi_pipeline_import_duplicate_strategy',
        'price_update' => 'mmi_pipeline_import_price_update',
        'stock_update' => 'mmi_pipeline_import_stock_update',
        'image_sync' => 'mmi_pipeline_import_image_sync',
        'category_mapping' => 'mmi_pipeline_import_category_mapping',
        'email_notifications' => 'mmi_pipeline_import_email_notifications'
    ];
    
    if (!isset($setting_map[$setting])) {
        wp_send_json_error(['message' => 'Invalid setting name']);
    }
    
    $option_key = $setting_map[$setting];
    
    // Convert value based on setting type
    if ($setting === 'duplicate_strategy') {
        // String value
        $value = sanitize_text_field($value);
    } else {
        // Boolean value (checkboxes)
        $value = !empty($value) && $value !== '0' && $value !== 'false';
    }
    
    MMI_DB::set_setting( $option_key, $value );
    
    wp_send_json_success([
        'message' => 'Import rule saved',
        'setting' => $setting,
        'value' => $value
    ]);
});

// Autosave schedule setting
/**
 * Shared schedule-save logic used by both autosave handlers.
 * Resolves the correct WP cron hook name for a process key, clears any stale
 * hooks, and schedules the new event.
 *
 * @param string $process     Process key (e.g. 'source_fetch_xchange', 'profile_pricing').
 * @param string $frequency   WP schedule name (e.g. 'twicedaily') or 'disabled'.
 * @param string $time_of_day Optional site-local anchor time, "HH:MM" 24h (e.g. "18:00").
 *                             Empty string leaves any previously-chosen time untouched.
 * @return string             The cron hook name that was acted on.
 */
function mmi_pipeline_apply_schedule( string $process, string $frequency, string $time_of_day = '' ): string {
    // Save the schedule setting
    MMI_DB::set_setting( 'mmi_schedule_' . $process, $frequency );

    // Site-local time-of-day this schedule anchors to, so different jobs can
    // be deliberately staggered instead of all landing on the same moment.
    // See MMI_Pipeline_Cron::compute_schedule_anchor() for how it's applied.
    if ( $time_of_day !== '' && preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time_of_day ) ) {
        MMI_DB::set_setting( 'mmi_schedule_time_' . $process, $time_of_day );
    }

    // Per-source fetch schedules (process = 'source_fetch_{supplier_id}') and
    // per-profile import schedules (process = 'profile_{id}') both use this
    // same mmi_pipeline_<process> convention — see MMI_Pipeline_Cron's
    // schedulable_source_ids()/get_schedulable_sources() for the source side.
    // Catalog Maintenance predates the convention and keeps its own hook.
    $cron_hook = 'catalog_update' === $process ? 'mmi_scheduled_catalog_update' : 'mmi_pipeline_' . $process;

    // Clear any existing scheduled event for the correct hook
    wp_clear_scheduled_hook( $cron_hook );

    // Schedule a fresh event unless explicitly disabled
    if ( $frequency !== 'disabled' ) {
        $wp_schedules = wp_get_schedules();
        if ( isset( $wp_schedules[ $frequency ] ) ) {
            $anchor = class_exists( 'MMI_Pipeline_Cron' )
                ? MMI_Pipeline_Cron::compute_schedule_anchor( $process, $frequency )
                : time();
            wp_schedule_event( $anchor, $frequency, $cron_hook );
        }
    }

    return $cron_hook;
}

add_action('wp_ajax_mmi_autosave_schedule', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $process   = sanitize_text_field($_POST['process']   ?? '');
    $frequency = sanitize_text_field($_POST['frequency'] ?? 'disabled');
    $time      = sanitize_text_field($_POST['time'] ?? '');

    if (empty($process)) {
        wp_send_json_error(['message' => 'Process name required']);
    }

    // API Data Sources only — two fetches landing within 5 minutes of each
    // other is exactly the concurrent-load spike AGENTS.md's Server Load
    // rules exist to prevent. See MMI_Pipeline_Cron::find_stagger_conflict().
    if ( class_exists( 'MMI_Pipeline_Cron' ) ) {
        $conflict = MMI_Pipeline_Cron::find_stagger_conflict( $process, $frequency, $time );
        if ( $conflict ) {
            wp_send_json_error( [
                'message'  => sprintf(
                    'Too close to "%s"\'s existing %s schedule — data sources must be at least 5 minutes apart to avoid overlapping fetches. Choose a different time.',
                    $conflict['name'],
                    $frequency
                ),
                'conflict' => true,
            ] );
        }
    }

    $cron_hook = mmi_pipeline_apply_schedule( $process, $frequency, $time );

    mmi_data_pipeline_audit('schedule.update', [
        'object_type' => 'schedule',
        'object_id'   => $process,
        'outcome'     => 'success',
        'details'     => ['frequency' => $frequency, 'time' => $time],
    ]);

    $next_ts    = wp_next_scheduled( $cron_hook );
    $next_label = $next_ts
        ? human_time_diff( $next_ts, current_time( 'timestamp' ) ) . ' from now'
        : ( $frequency !== 'disabled' ? 'Pending next cron run' : 'Not scheduled' );

    wp_send_json_success([
        'message'        => 'Schedule saved',
        'process'        => $process,
        'frequency'      => $frequency,
        'cron_hook'      => $cron_hook,
        'next_scheduled' => $next_label,
    ]);
});

// Per-profile "Skip if no new data" schedule gate — see
// MMI_Pipeline_Cron::run_scheduled_profile_import(). A dedicated endpoint
// rather than a mmi_autosave_import_rule entry: that handler's $setting_map
// is a fixed, non-profile-scoped whitelist and can't address one of several
// dynamic profile IDs, so this validates profile_id against the real
// profile list itself instead.
add_action('wp_ajax_mmi_pipeline_toggle_skip_if_no_data', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $profile_id = sanitize_text_field($_POST['profile_id'] ?? '');
    $enabled    = !empty($_POST['enabled']) && $_POST['enabled'] !== '0';

    if (empty($profile_id) || !isset(MMI_DB::get_profiles_by_direction('import')[$profile_id])) {
        wp_send_json_error(['message' => 'Unknown profile']);
    }

    MMI_DB::set_setting( 'mmi_schedule_skip_if_no_data_' . $profile_id, $enabled );

    wp_send_json_success([
        'profile_id' => $profile_id,
        'enabled'    => $enabled,
    ]);
});

// Fetch → Import Linking — link or unlink one specific (profile, source)
// pair. A profile can be linked to more than one of its own sources, so
// unlike the profile-wide toggle this once was, every call here always
// names both the profile AND the exact source row being toggled. See
// MMI_Pipeline_Cron::link_profile_to_source()/unlink_source_from_profile()/
// get_profile_links() for the underlying multi-link storage/semantics.
add_action('wp_ajax_mmi_pipeline_toggle_profile_link', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    if ( ! class_exists( 'MMI_Pipeline_Cron' ) ) {
        wp_send_json_error(['message' => 'Scheduling is unavailable']);
    }

    $profile_id  = sanitize_text_field($_POST['profile_id'] ?? '');
    $supplier_id = sanitize_text_field($_POST['supplier_id'] ?? '');
    $link        = !empty($_POST['link']) && $_POST['link'] !== '0';

    if (empty($profile_id) || empty($supplier_id)) {
        wp_send_json_error(['message' => 'Profile and data source are both required']);
    }

    if ( $link ) {
        $result = MMI_Pipeline_Cron::link_profile_to_source( $profile_id, $supplier_id );
        if ( true !== $result ) {
            wp_send_json_error( [ 'message' => $result ] );
        }
    } else {
        MMI_Pipeline_Cron::unlink_source_from_profile( $profile_id, $supplier_id );
    }

    // any_linked/next_label/staleness_message let the panel update the
    // row's own state plus the profile-wide Next Run cell, locked
    // Frequency/time controls, and staleness note live — see
    // MMI_Pipeline_Cron::get_profile_next_run_label()'s and
    // get_profile_staleness_warning()'s docblocks — instead of requiring a
    // page reload.
    wp_send_json_success([
        'profile_id'        => $profile_id,
        'supplier_id'       => $supplier_id,
        'linked'            => $link,
        'any_linked'        => ! empty( MMI_Pipeline_Cron::get_profile_links( $profile_id ) ),
        'next_label'        => MMI_Pipeline_Cron::get_profile_next_run_label( $profile_id ),
        'staleness_message' => MMI_Pipeline_Cron::get_profile_staleness_warning( $profile_id ),
    ]);
});

// Legacy alias: product-import.js (old tab page) sends action=mmi_save_schedule
// with the value in field 'schedule' instead of 'frequency'.
add_action('wp_ajax_mmi_save_schedule', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $process   = sanitize_text_field($_POST['process']   ?? '');
    // Old JS uses 'schedule'; new JS uses 'frequency'
    $frequency = sanitize_text_field($_POST['schedule'] ?? $_POST['frequency'] ?? 'disabled');

    if (empty($process)) {
        wp_send_json_error(['message' => 'Process name required']);
    }

    $cron_hook = mmi_pipeline_apply_schedule( $process, $frequency );

    mmi_data_pipeline_audit('schedule.update', [
        'object_type' => 'schedule',
        'object_id'   => $process,
        'outcome'     => 'success',
        'details'     => ['frequency' => $frequency],
    ]);

    wp_send_json_success([
        'message'   => 'Schedule saved',
        'process'   => $process,
        'frequency' => $frequency,
        'cron_hook' => $cron_hook,
    ]);
});

// Data source save/test handlers have been moved to DataSourceController.php
// which provides comprehensive data source management via wp_mmi_data_sources table.

/**
 * Update an existing import profile (name, mode, scope, identifier).
 * Unlike mmi_save_import_profile this handler allows overwriting existing profiles.
 */
add_action('wp_ajax_mmi_update_import_profile', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $profile_id    = sanitize_text_field($_POST['profile_id']    ?? '');
    $name          = sanitize_text_field($_POST['name']          ?? '');
    $import_mode   = sanitize_text_field($_POST['import_mode']   ?? 'update-only');
    $product_scope = sanitize_text_field($_POST['product_scope'] ?? 'all_products');
    $mode_settings      = [];
    $product_identifier = null;

    if (empty($profile_id) || empty($name)) {
        wp_send_json_error(['message' => 'Profile ID and name are required']);
        return;
    }

    if (!in_array($import_mode, MMI_Pipeline_Field_Mapping_Defaults::IMPORT_MODES, true)) {
        wp_send_json_error(['message' => 'Invalid import mode']);
        return;
    }

    if (!in_array($product_scope, MMI_Pipeline_Field_Mapping_Defaults::PRODUCT_SCOPES, true)) {
        wp_send_json_error(['message' => 'Invalid product scope']);
        return;
    }

    if (!empty($_POST['mode_settings'])) {
        $decoded = json_decode(stripslashes($_POST['mode_settings']), true);
        if (is_array($decoded)) {
            $mode_settings = $decoded;
        }
    }

    if (!empty($_POST['product_identifier'])) {
        $decoded = json_decode(stripslashes($_POST['product_identifier']), true);
        if (is_array($decoded)) {
            $product_identifier = [
                'storage_type'      => sanitize_text_field($decoded['storage_type']   ?? ''),
                'storage_config'    => array_map('sanitize_text_field', (array)($decoded['storage_config'] ?? [])),
                'auto_apply_to_new' => (bool)($decoded['auto_apply_to_new'] ?? true),
                'onboarded_plugins' => array_map('sanitize_text_field', (array)($decoded['onboarded_plugins'] ?? [])),
            ];
        }
    }

    $profiles = MMI_DB::get_profiles();

    // Reject an export profile the same way as a missing one — this handler
    // overwrites import_mode/product_scope/sources/product_identifier
    // directly, which would corrupt an export profile's settings if it were
    // ever reachable here.
    if (!isset($profiles[$profile_id]) || ($profiles[$profile_id]['direction'] ?? 'import') !== 'import') {
        wp_send_json_error(['message' => 'Profile not found']);
        return;
    }

    // Preserve existing sources unless caller explicitly supplies new ones.
    $sources = $profiles[$profile_id]['sources'] ?? [];
    if (isset($_POST['sources'])) {
        $raw = is_array($_POST['sources'])
            ? $_POST['sources']
            : json_decode(stripslashes($_POST['sources']), true);
        if (is_array($raw)) {
            $sources = array_values(array_map('sanitize_text_field', $raw));
        }
    }

    $profiles[$profile_id]['name']               = $name;
    $profiles[$profile_id]['import_mode']        = $import_mode;
    $profiles[$profile_id]['mode_settings']      = $mode_settings;
    $profiles[$profile_id]['product_scope']      = $product_scope;
    $profiles[$profile_id]['product_identifier'] = $product_identifier;
    $profiles[$profile_id]['sources']            = $sources;

    $saved = MMI_DB::set_profiles($profiles);

    if (!$saved) {
        MMI_Logger::info( '[MMI Pipeline] mmi_update_import_profile: set_profiles() failed for profile_id=' . $profile_id, [], 'general', 'MMI_Pipeline_ImportSettings_Controller' );
        wp_send_json_error(['message' => 'Failed to save profile — database write error']);
        return;
    }

    mmi_data_pipeline_audit('import_profile.update', [
        'object_type' => 'import_profile',
        'object_id'   => $profile_id,
        'outcome'     => 'success',
        'details'     => ['import_mode' => $import_mode, 'product_scope' => $product_scope],
    ]);

    wp_send_json_success([
        'message'    => 'Profile updated',
        'profile_id' => $profile_id,
        'meta'       => [
            'name'               => $name,
            'import_mode'        => $import_mode,
            'mode_settings'      => $mode_settings,
            'product_scope'      => $product_scope,
            'product_identifier' => $product_identifier,
            'sources'            => $sources,
        ],
    ]);
});

/**
 * Return the terms belonging to a given taxonomy.
 * Used by the new-profile wizard to populate the "Term" select dynamically.
 */
// Named mmi_pipeline_get_taxonomy_terms (not the generic mmi_get_taxonomy_terms)
// to avoid colliding with mmi-hub's MMI_Taxonomy_Filter_Handler, which is
// registered on the exact same generic action name for the Reverb filter-bar
// cascade (a different payload shape: {taxonomies: [...]} vs this endpoint's
// {taxonomy: '...'}). Sharing the name meant mmi-hub's handler — registered
// first — always ran and wp_die()'d before this one ever got a turn, so the
// Identifier step's taxonomy-terms dropdown silently never worked.
add_action('wp_ajax_mmi_pipeline_get_taxonomy_terms', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $taxonomy = sanitize_key($_POST['taxonomy'] ?? '');
    if (empty($taxonomy) || !taxonomy_exists($taxonomy)) {
        wp_send_json_error(['message' => 'Invalid or unregistered taxonomy: ' . esc_html($taxonomy)]);
        return;
    }

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    if (is_wp_error($terms)) {
        wp_send_json_error(['message' => $terms->get_error_message()]);
        return;
    }

    $out = [];
    foreach ($terms as $term) {
        $out[] = [
            'id'    => (int) $term->term_id,
            'slug'  => $term->slug,
            'name'   => $term->name,
            'count'  => (int) $term->count,
            'parent' => (int) $term->parent,
        ];
    }

    wp_send_json_success(['terms' => $out]);
});

/**
 * Suggest existing values for a given post-meta key, for the "Custom Post
 * Meta" identifier field's value datalist. Scoped by meta_key, which is
 * fully indexed (unlike a bare DISTINCT meta_key scan — see the transient-
 * cached key list built in class-pipeline-admin.php), so this is cheap
 * regardless of how large wp_postmeta is.
 */
add_action('wp_ajax_mmi_pipeline_get_meta_values', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $meta_key = sanitize_text_field($_POST['meta_key'] ?? '');
    if ($meta_key === '') {
        wp_send_json_error(['message' => 'Missing meta key']);
        return;
    }

    global $wpdb;
    $values = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
          WHERE meta_key = %s AND meta_value <> ''
          ORDER BY meta_value
          LIMIT 100",
        $meta_key
    ));

    wp_send_json_success(['values' => $values]);
});

// Save the configurable COG (cost-of-goods) meta key used by both the import pipeline
// and mmi-reverb-integration to locate the dealer price on WC products.
add_action('wp_ajax_mmi_save_cog_meta_key', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $meta_key = sanitize_key(wp_unslash($_POST['meta_key'] ?? 'cog'));
    if (empty($meta_key)) {
        $meta_key = 'cog';
    }

    MMI_Settings::set('mmi_cog_meta_key', $meta_key);

    wp_send_json_success(['meta_key' => $meta_key]);
});

if ( ! function_exists( 'mmi_get_cog_meta_key' ) ) {
    /**
     * The configurable COG (cost-of-goods) meta key set by mmi_save_cog_meta_key
     * above. The __mmi_cog field mapping's 'meta_key_resolver' entry
     * (class-pipeline-field-mapping-defaults.php) has named this exact function
     * since it was written, and every consumer (class-dynamic-product-importer.php,
     * CanonicalCandidatesController.php, panel-field-mapping.php) already guards
     * with is_callable()/function_exists() before calling it — but the function
     * itself never existed, so every one of those call sites silently fell back
     * to treating the literal string '__mmi_cog' as the destination meta key
     * instead of the real, configured one. Confirmed live: a product's stale
     * '__mmi_cog' postmeta (135.4) never matched its correct, actively-maintained
     * 'cog' postmeta (172.4, kept current by mmi-reverb-integration's own dealer-
     * price sync), permanently flagging ~300 products/day as "will update" in
     * Import Preview for a write that would have landed on the wrong key anyway.
     */
    function mmi_get_cog_meta_key(): string {
        return (string) MMI_Settings::get( 'mmi_cog_meta_key', 'cog' );
    }
}

/**
 * Well-known WooCommerce/WordPress core product meta keys — hand-maintained
 * since core doesn't expose a queryable registry for most of these (they
 * predate register_post_meta() and were never backfilled into it).
 */
function mmi_pipeline_woocommerce_meta_keys(): array {
    return array_flip([
        '_sku', '_price', '_regular_price', '_sale_price',
        '_sale_price_dates_from', '_sale_price_dates_to',
        '_stock', '_stock_status', '_manage_stock', '_backorders', '_low_stock_amount', '_sold_individually',
        '_weight', '_length', '_width', '_height',
        '_virtual', '_downloadable', '_download_limit', '_download_expiry',
        '_tax_status', '_tax_class',
        '_upsell_ids', '_crosssell_ids', '_purchase_note',
        '_default_attributes', '_product_attributes',
        '_thumbnail_id', '_product_image_gallery',
        '_product_version', '_product_url', '_button_text',
        '_wc_average_rating', '_wc_review_count', '_wc_rating_count',
        '_variation_description', '_subscription_one_time_shipping',
        'total_sales',
    ]);
}

/**
 * Scan JetEngine's registered meta boxes for fields actually applied to
 * product/product_variation — the only meta-registering plugin active on
 * this site (confirmed 2026-08-12: no ACF/Pods/Meta Box/Toolset). Returns
 * empty today (JetEngine's one configured meta box targets 'attachment'),
 * but stays correct automatically if a product meta box is added later —
 * deliberately not hardcoded to today's snapshot.
 */
function mmi_pipeline_jetengine_product_meta_fields(): array {
    $boxes = get_option('jet_engine_meta_boxes', []);
    if (!is_array($boxes)) {
        return [];
    }

    $fields = [];
    foreach ($boxes as $box) {
        $allowed = $box['args']['allowed_post_type'] ?? [];
        if (!is_array($allowed) || (!in_array('product', $allowed, true) && !in_array('product_variation', $allowed, true))) {
            continue;
        }
        foreach ($box['meta_fields'] ?? [] as $field) {
            if (!empty($field['name'])) {
                $fields[$field['name']] = $field['title'] ?? $field['name'];
            }
        }
    }
    return $fields;
}

/**
 * Classify a meta key by which plugin/system owns it, for the branded
 * meta-key picker (Add Custom Field modal + the __mmi_cog-style inline
 * destination-key inputs). Order matters: MannMade's own namespace is
 * checked first since a few of this suite's own keys (__mmi_cog itself)
 * could otherwise coincidentally look WooCommerce-ish.
 */
function mmi_pipeline_classify_meta_key(string $key, array $jetengine_fields, array $wc_keys): ?string {
    if (preg_match('/^_{1,2}mmi_/', $key)) {
        return 'MannMade';
    }
    if (isset($jetengine_fields[$key])) {
        return 'JetEngine';
    }
    if (isset($wc_keys[$key])) {
        return 'WooCommerce';
    }
    return null; // Custom — no known registry claims it
}

// Return all distinct meta keys used on products/product_variations for the
// branded meta-key pickers (Add Custom Field modal, and any inline
// destination-key input like __mmi_cog's) showing users where available
// target keys come from.
add_action('wp_ajax_mmi_get_product_meta_keys', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    global $wpdb;

    $raw_keys = $wpdb->get_col(
        "SELECT DISTINCT pm.meta_key
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.post_type IN ('product', 'product_variation')
           AND p.post_status != 'trash'
         ORDER BY pm.meta_key ASC
         LIMIT 1000"
    );

    $jetengine_fields = mmi_pipeline_jetengine_product_meta_fields();
    $wc_keys          = mmi_pipeline_woocommerce_meta_keys();

    $meta_keys = [];
    foreach ($raw_keys ?: [] as $key) {
        $meta_keys[] = [
            'key'    => $key,
            'label'  => $jetengine_fields[$key] ?? $key,
            'source' => mmi_pipeline_classify_meta_key($key, $jetengine_fields, $wc_keys),
        ];
    }
    // JetEngine fields not yet written to any product's postmeta row (a field
    // defined on the meta box but never saved) wouldn't appear in $raw_keys
    // at all — surface them too, since they're just as legitimate a target.
    foreach ($jetengine_fields as $key => $label) {
        if (!isset($wc_keys[$key]) && !in_array($key, $raw_keys ?: [], true)) {
            $meta_keys[] = ['key' => $key, 'label' => $label, 'source' => 'JetEngine'];
        }
    }

    // Build taxonomy list (exclude high-traffic WC built-ins that already have
    // a permanent DEFAULTS row — product_cat/product_tag/product_brand are all
    // always present in the Field Mapping table, so offering them here would
    // just create a confusing duplicate row; plus MMI_Pipeline_Field_Mapping_Defaults::OBSOLETE_FIELDS
    // for any field whose real configuration lives entirely on another tab —
    // merge() unconditionally strips those from every render regardless of
    // what's saved for them, so offering one here as addable would silently
    // discard whatever the user configured on the very next page load).
    $excluded_tax  = array_merge(
        ['product_type', 'product_visibility', 'product_shipping_class', 'product_cat', 'product_tag', 'product_brand'],
        MMI_Pipeline_Field_Mapping_Defaults::OBSOLETE_FIELDS
    );
    $tax_objects   = get_object_taxonomies('product', 'objects');
    $taxonomies    = [];
    foreach ($tax_objects as $tax_name => $tax_obj) {
        if (!in_array($tax_name, $excluded_tax, true)) {
            $taxonomies[] = [
                'key'   => $tax_name,
                'label' => $tax_obj->labels->singular_name ?? $tax_name,
            ];
        }
    }
    usort($taxonomies, function ($a, $b) { return strcmp($a['label'], $b['label']); });

    wp_send_json_success([
        'meta_keys'  => $meta_keys,
        'taxonomies' => $taxonomies,
    ]);
});

/* ── Field Mapping Presets ─────────────────────────────────────────────────
 * Explicitly-applied, reusable field mapping snapshots — a user saves one
 * profile's current mapping under a name, then applies it to another profile
 * as a starting point. Distinct from field mappings themselves (which stay
 * profile-scoped via MMI_DB::get_field_mappings()/set_field_mappings()):
 * presets are the only thing shared across profiles, and only when a user
 * explicitly asks for it. Stored as one array under a single settings key —
 * expected to hold at most a handful of presets, not a growing dataset.
 *
 * Every preset — user-saved or builtin — carries a data_type and a sources[]
 * list, captured automatically from the wizard's own state at save time (see
 * import-settings.js). A preset built for Product+Xchange has no meaningful
 * use while working on a Taxonomy or Coupon profile, or a profile with a
 * different source checked — so the wizard's dropdown only ever offers a
 * preset whose data_type matches what's currently selected and whose sources
 * are all currently checked (see refreshPresetDropdownForContext() in JS). A
 * preset saved before this field existed has no data_type/sources of its own;
 * treated as data_type=product, sources=[] (unrestricted by source) rather
 * than silently disappearing — see mmi_get_all_field_mapping_presets() below.
 *
 * A sibling MMI plugin (e.g. mmi-xchange-integration) can ship its own
 * ready-made presets without ever writing into this settings key directly —
 * see the 'mmi_pipeline_builtin_field_mapping_presets' filter applied in
 * mmi_get_all_field_mapping_presets(). A builtin preset (builtin => true) can
 * be applied like any other but never deleted through this mechanism — there
 * is no DB row for it to delete.
 */

const MMI_FIELD_MAPPING_PRESETS_KEY = 'mmi_pipeline_field_mapping_presets';

/**
 * The full set of presets the wizard can offer: user-saved presets (this
 * settings key) plus whatever sibling plugins contribute via the
 * 'mmi_pipeline_builtin_field_mapping_presets' filter. The single read path
 * for every consumer (page-load localization, apply, delete) so a builtin
 * preset is never visible in one place and missing in another.
 *
 * @return array<int, array{id:string,name:string,mappings:array,data_type:string,sources:array,builtin?:bool,source_plugin?:string}>
 */
function mmi_get_all_field_mapping_presets(): array {
    $saved = MMI_DB::get_setting(MMI_FIELD_MAPPING_PRESETS_KEY, []);
    if (!is_array($saved)) {
        $saved = [];
    }

    // Backfill data_type/sources on any preset saved before these fields
    // existed — 'product'/unrestricted rather than dropping it from view.
    foreach ($saved as &$mmi_legacy_preset) {
        if (!is_array($mmi_legacy_preset)) {
            continue;
        }
        $mmi_legacy_preset['data_type'] = $mmi_legacy_preset['data_type'] ?? 'product';
        $mmi_legacy_preset['sources']   = is_array($mmi_legacy_preset['sources'] ?? null)
            ? $mmi_legacy_preset['sources']
            : [];
    }
    unset($mmi_legacy_preset);

    $builtin = apply_filters('mmi_pipeline_builtin_field_mapping_presets', []);
    if (!is_array($builtin)) {
        $builtin = [];
    }

    return array_merge($saved, $builtin);
}

// Save the given profile's current field mappings as a new named preset.
add_action('wp_ajax_mmi_save_field_mapping_preset', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $name    = sanitize_text_field($_POST['name'] ?? '');
    $profile = sanitize_text_field($_POST['profile'] ?? 'default');

    if (empty($name)) {
        wp_send_json_error(['message' => 'Preset name required']);
    }

    // Same validation mmi_save_import_profile already applies to this field
    // — an unrecognized/tampered value falls back to 'product' rather than
    // persisting a data_type nothing downstream can filter against.
    $data_type = sanitize_text_field($_POST['data_type'] ?? 'product');
    if (!class_exists('MMI_Data_Type_Registry') || !array_key_exists($data_type, MMI_Data_Type_Registry::get_choices_for_ui())) {
        $data_type = 'product';
    }
    $sources = array_values(array_filter(array_map(
        'sanitize_text_field',
        (array) ($_POST['sources'] ?? [])
    )));

    $mappings = MMI_DB::get_field_mappings($profile);
    if (empty($mappings)) {
        wp_send_json_error(['message' => 'This profile has no field mappings configured yet']);
    }

    // 'file' is a per-profile browsing-aid selection only (it never routes
    // the import to a specific file — see panel-field-mapping.php) and its
    // valid options are scoped to whatever files exist for THIS profile's
    // suppliers. A preset is shared across profiles, so carrying 'file'
    // forward can silently stamp a selection from this profile onto a
    // target profile whose supplier has different (or no) file options.
    // Strip it — applying a preset should always start from the blank
    // default browsing state.
    foreach ($mappings as &$mmi_preset_mapping) {
        if (is_array($mmi_preset_mapping)) {
            unset($mmi_preset_mapping['file']);
        }
    }
    unset($mmi_preset_mapping);

    $presets = MMI_DB::get_setting(MMI_FIELD_MAPPING_PRESETS_KEY, []);
    if (!is_array($presets)) {
        $presets = [];
    }

    $preset_id = 'preset_' . substr(md5($name . microtime()), 0, 12);
    $presets[] = [
        'id'         => $preset_id,
        'name'       => $name,
        'mappings'   => $mappings,
        'data_type'  => $data_type,
        'sources'    => $sources,
        'created_at' => current_time('mysql'),
    ];

    MMI_DB::set_setting(MMI_FIELD_MAPPING_PRESETS_KEY, $presets);

    wp_send_json_success([
        'message' => 'Preset saved',
        'preset'  => ['id' => $preset_id, 'name' => $name],
        'presets' => array_map(function ($p) {
            return [
                'id'        => $p['id'],
                'name'      => $p['name'],
                'data_type' => $p['data_type'] ?? 'product',
                'sources'   => $p['sources'] ?? [],
            ];
        }, mmi_get_all_field_mapping_presets()),
    ]);
});

// Apply a saved (or builtin) preset's mappings onto the given profile (full replace).
add_action('wp_ajax_mmi_apply_field_mapping_preset', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $preset_id = sanitize_text_field($_POST['preset_id'] ?? '');
    $profile   = sanitize_text_field($_POST['profile'] ?? 'default');

    if (empty($preset_id)) {
        wp_send_json_error(['message' => 'Preset ID required']);
    }

    $preset = null;
    foreach (mmi_get_all_field_mapping_presets() as $p) {
        if (($p['id'] ?? '') === $preset_id) {
            $preset = $p;
            break;
        }
    }

    if ($preset === null) {
        wp_send_json_error(['message' => 'Preset not found']);
    }

    MMI_DB::set_field_mappings($profile, $preset['mappings']);

    wp_send_json_success([
        'message'  => 'Preset applied',
        'mappings' => $preset['mappings'],
        'profile'  => $profile,
    ]);
});

// Delete a saved preset. Builtin presets (shipped by a sibling plugin) have
// no row in this settings key to delete — rejected explicitly rather than
// silently no-op'd, so the user isn't told "deleted" when nothing changed.
add_action('wp_ajax_mmi_delete_field_mapping_preset', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $preset_id = sanitize_text_field($_POST['preset_id'] ?? '');
    if (empty($preset_id)) {
        wp_send_json_error(['message' => 'Preset ID required']);
    }

    foreach (mmi_get_all_field_mapping_presets() as $p) {
        if (($p['id'] ?? '') === $preset_id && !empty($p['builtin'])) {
            wp_send_json_error(['message' => 'This preset ships with ' . ($p['source_plugin'] ?? 'a plugin') . ' and can\'t be deleted here.']);
        }
    }

    $presets = MMI_DB::get_setting(MMI_FIELD_MAPPING_PRESETS_KEY, []);
    $presets = array_values(array_filter((array) $presets, function ($p) use ($preset_id) {
        return ($p['id'] ?? '') !== $preset_id;
    }));

    MMI_DB::set_setting(MMI_FIELD_MAPPING_PRESETS_KEY, $presets);

    mmi_data_pipeline_audit('field_mapping_preset.delete', [
        'object_type' => 'field_mapping_preset',
        'object_id'   => $preset_id,
        'outcome'     => 'success',
    ]);

    wp_send_json_success([
        'message' => 'Preset deleted',
        'presets' => array_map(function ($p) {
            return [
                'id'        => $p['id'],
                'name'      => $p['name'],
                'data_type' => $p['data_type'] ?? 'product',
                'sources'   => $p['sources'] ?? [],
            ];
        }, mmi_get_all_field_mapping_presets()),
    ]);
});

// Re-render the new-profile wizard's Step 1 (checklist) and Step 2 (Primary
// Key editor) sources lists fresh — the page's own inline render is baked in
// at page load and goes stale the moment a source is added anywhere else
// (the Data Sources tab, another tab/session) without a full reload. See
// "Wizard Source List Went Stale" in AGENTS.md. Split into two partials
// (wizard-sources-checklist.php / wizard-sources-pk-editor.php) since the
// Primary Key editor is now its own wizard step — see
// IMPORT_WIZARD_INLINE_SECTION_HANDOFF.md.
add_action('wp_ajax_mmi_pipeline_get_wizard_sources_html', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $configured_suppliers = MMI_Pipeline_Admin::get_configured_suppliers();

    ob_start();
    include dirname(__DIR__, 3) . '/admin/views/partials/wizard-sources-checklist.php';
    $checklist_html = ob_get_clean();

    ob_start();
    include dirname(__DIR__, 3) . '/admin/views/partials/wizard-sources-pk-editor.php';
    $pk_editor_html = ob_get_clean();

    wp_send_json_success([
        'checklist_html' => $checklist_html,
        'pk_editor_html' => $pk_editor_html,
    ]);
});
