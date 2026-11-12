<?php
/**
 * Taxonomy Mapping AJAX Controller
 *
 * Handles AJAX endpoints for the brand/category taxonomy mapping UI.
 * Source values are scanned from the cached supplier JSON files.
 * Mappings are stored in mmi_pipeline_tax_mappings via MMI_DB.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Nonce used by all taxonomy mapping AJAX calls ────────────────────────── */
const TAXMAP_NONCE = 'mmi_pipeline_taxonomy_mapping';

/**
 * Every configured supplier's own "Products" JSON filename, keyed by
 * supplier_id — derived from MMI_Pipeline_Admin::get_configured_suppliers()'s
 * real configuration (the same source Field Mapping's own file-selector
 * dropdown already uses) instead of a hardcoded xchange/skuport/plugivery
 * map. Several handlers in this file each independently hardcoded the same
 * 3-entry map (plus an identical "{$supplier}-products.json" fallback) —
 * consolidated here so a newly-configured/uploaded source's products file is
 * discoverable everywhere in this file the moment it exists, with no new
 * per-handler map to remember to update.
 *
 * @return array<string, string> supplier_id => filename
 */
function mmi_taxmap_build_supplier_file_map(): array {
    $file_map = [];
    foreach ( MMI_Pipeline_Admin::get_configured_suppliers() as $sid => $sinfo ) {
        $file_options   = $sinfo['file_options'] ?? [];
        $products_file  = null;
        foreach ( $file_options as $filename => $file_label ) {
            if ( stripos( $file_label, 'product' ) !== false || stripos( $filename, 'product' ) !== false ) {
                $products_file = $filename;
                break;
            }
        }
        $file_map[ $sid ] = $products_file ?? ( array_key_first( $file_options ) ?: ( $sid . '-products.json' ) );
    }
    return $file_map;
}

/**
 * A record's product name for Sample Products: the API feeds' own name
 * fields first ('product' is XChange's, 'name' SkuPort's), then — for an
 * uploaded spreadsheet, whose columns are whatever the file calls them
 * ("DESCRIPTION", "Item") — the same names in any case, then a
 * description or item column.
 */
function mmi_taxmap_record_label( array $item ): string {
    foreach ( [ 'name', 'product', 'title', 'product_name' ] as $key ) {
        if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) && trim( (string) $item[ $key ] ) !== '' ) {
            return (string) $item[ $key ];
        }
    }
    $lower = [];
    foreach ( $item as $key => $value ) {
        if ( is_scalar( $value ) && trim( (string) $value ) !== '' ) {
            $lower[ strtolower( trim( (string) $key ) ) ] ??= (string) $value;
        }
    }
    foreach ( [ 'name', 'product', 'title', 'product name', 'product_name', 'description', 'item', 'model' ] as $key ) {
        if ( isset( $lower[ $key ] ) ) {
            return $lower[ $key ];
        }
    }
    return '';
}

/**
 * The postmeta key holding a product's own SKU from a given supplier — the
 * primary-key convention every configured source's matched products carry
 * regardless of supplier (see class-stock-override-resolver.php's identical
 * derivation, `'_mmi_supplier_sku_' . $source`, and
 * Product_Import_Worker::update_product()'s "Keep the configured primary-key
 * meta ... in sync" comment for where it's written).
 */
function mmi_taxmap_supplier_sku_meta_key( string $supplier_id ): string {
    return '_mmi_supplier_sku_' . $supplier_id;
}

/* ── Load saved mappings (initial page load) ──────────────────────────────── */
add_action( 'wp_ajax_mmi_load_taxonomy_mappings', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $supplier    = sanitize_text_field( $_POST['supplier'] ?? '' );
    $wc_taxonomy = sanitize_text_field( $_POST['wc_taxonomy'] ?? '' );
    $rows        = MMI_DB::get_tax_mappings( $supplier, $wc_taxonomy );

    // Annotate each row with current WC term name for display
    foreach ( $rows as &$row ) {
        $tid  = (int) $row['wc_term_id'];
        $name = '';
        if ( $tid > 0 ) {
            $term = get_term( $tid, $row['wc_taxonomy'] );
            $name = ( $term && ! is_wp_error( $term ) ) ? $term->name : "(deleted term #{$tid})";
        } elseif ( $tid === -1 ) {
            $name = '__skip__';
        }
        $row['wc_term_name']    = $name;
        $row['missing_term_id'] = ( $tid > 0 && strpos( $name, '(deleted term #' ) === 0 ) ? $tid : 0;
    }
    unset( $row );

    wp_send_json_success( [ 'mappings' => $rows ] );
} );

/* ── Save (upsert) a single mapping ──────────────────────────────────────── */
add_action( 'wp_ajax_mmi_save_taxonomy_mapping', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $supplier     = sanitize_text_field( $_POST['supplier_id']   ?? '' );
    $source_field = sanitize_text_field( $_POST['source_field']  ?? '' );
    $source_value = sanitize_text_field( $_POST['source_value']  ?? '' );
    $wc_taxonomy  = sanitize_text_field( $_POST['wc_taxonomy']   ?? '' );
    $wc_term_id   = (int) ( $_POST['wc_term_id'] ?? 0 );
    $auto_create  = ! empty( $_POST['auto_create'] );
    // '' (the default) = applies to every Import Profile — see
    // MMI_DB::resolve_tax_mapping()'s profile-aware tiering.
    $profile_id   = sanitize_text_field( $_POST['profile_id'] ?? '' );

    if ( empty( $source_field ) || empty( $wc_taxonomy ) ) {
        wp_send_json_error( [ 'message' => 'source_field and wc_taxonomy are required' ] );
    }

    // Imports ignore an excluded value, so a mapping saved now would look
    // applied while doing nothing.
    if ( MMI_Pipeline_Taxmap_Exclusions::is_excluded_any_taxonomy( $supplier, $source_field, $source_value ) ) {
        wp_send_json_error( [ 'message' => "\"{$source_value}\" is excluded from Taxonomy Mapping. Restore it before mapping it." ] );
    }

    // If wc_term_id === 0 and a term_name was passed, try to find or create the term
    $term_name = sanitize_text_field( $_POST['term_name'] ?? '' );
    if ( $wc_term_id === 0 && $term_name !== '' && $term_name !== '__skip__' ) {
        $term = get_term_by( 'name', $term_name, $wc_taxonomy );
        if ( ! $term ) {
            $result = wp_insert_term( $term_name, $wc_taxonomy );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => 'Could not create term: ' . $result->get_error_message() ] );
            }
            $wc_term_id = (int) $result['term_id'];
        } else {
            $wc_term_id = $term->term_id;
        }
    }

    // The autocomplete list can be older than the term: one deleted after
    // it was listed would otherwise be saved as a mapping that never applies.
    if ( $wc_term_id > 0 ) {
        $picked = get_term( $wc_term_id, $wc_taxonomy );
        if ( ! $picked || is_wp_error( $picked ) ) {
            wp_send_json_error( [
                'message'      => "Term #{$wc_term_id} no longer exists in {$wc_taxonomy}. It may have been deleted. Search again and pick another term.",
                'term_missing' => true,
            ] );
        }
    }

    $ok = MMI_DB::set_tax_mapping( $supplier, $source_field, $source_value, $wc_taxonomy, $wc_term_id, $auto_create, $profile_id );

    if ( ! $ok ) {
        wp_send_json_error( [ 'message' => 'Database write failed' ] );
    }

    // Existing products with this value get the term now; see
    // Taxonomy_Mapping_Handler::apply_saved_mapping() for why an import
    // alone isn't enough. Global mappings only: which profile imported a
    // product isn't recorded, matching Apply All.
    $applied = null;
    if ( $wc_term_id > 0 && $profile_id === '' ) {
        $own = [];
        foreach ( MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_source_fields() as $src ) {
            if ( $src['source_field'] === $source_field && ( $supplier === '' || $src['supplier'] === $supplier ) ) {
                $own[] = $src['wc_taxonomy'];
            }
        }
        @set_time_limit( 120 );
        $applied = \MannMade\DataPipeline\Importers\Taxonomy_Mapping_Handler::apply_saved_mapping(
            $supplier, $source_field, $source_value, $wc_taxonomy, $wc_term_id, $own ?: [ $wc_taxonomy ]
        );
        unset( $applied['ids'] ); // counts only, for the audit log and the reply
        // More products than the on-save limit: finish in the background
        // instead of asking for Apply All.
        if ( $applied['too_many'] > 0 ) {
            $applied['queued'] = \MannMade\DataPipeline\Importers\Taxonomy_Mapping_Handler::schedule_heal( [
                'mode'         => 'value',
                'supplier_id'  => $supplier,
                'source_field' => $source_field,
                'source_value' => $source_value,
                'wc_taxonomy'  => $wc_taxonomy,
                'term_id'      => $wc_term_id,
            ] );
        }
        if ( $applied['updated'] > 0 ) {
            mmi_data_pipeline_audit( 'taxonomy.apply', [
                'object_type' => 'taxonomy_mapping',
                'object_id'   => $supplier,
                'outcome'     => 'success',
                'details'     => [ 'source_field' => $source_field, 'source_value' => $source_value, 'wc_taxonomy' => $wc_taxonomy, 'on_save' => $applied ],
            ] );
        }
    }

    $term_display = '';
    if ( $wc_term_id > 0 ) {
        $t            = get_term( $wc_term_id, $wc_taxonomy );
        $term_display = ( $t && ! is_wp_error( $t ) ) ? $t->name : "(#{$wc_term_id})";
    } elseif ( $wc_term_id === -1 ) {
        $term_display = '__skip__';
    }

    // set_tax_mapping()'s upsert doesn't hand back the affected row's id (an
    // ON DUPLICATE KEY UPDATE's LAST_INSERT_ID() isn't reliably the existing
    // row without the UPDATE clause explicitly re-asserting it) — a cheap
    // follow-up read on the same unique key is simpler and safer than
    // changing MMI_DB's shared return contract for this one caller. Needed
    // by the "+ Add profile variation" flow so a just-saved variant can be
    // deleted again without a page reload.
    global $wpdb;
    $mapping_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM " . MMI_DB::tax_mappings_table() . "
         WHERE supplier_id = %s AND profile_id = %s AND source_field = %s AND source_value = %s AND wc_taxonomy = %s
         LIMIT 1",
        $supplier,
        $profile_id,
        $source_field,
        $source_value,
        $wc_taxonomy
    ) );

    wp_send_json_success( [
        'message'       => 'Mapping saved',
        'mapping_id'    => $mapping_id,
        'wc_term_id'    => $wc_term_id,
        'wc_term_name'  => $term_display,
        'applied'       => $applied,
    ] );
} );

/* ── Delete a mapping by ID ──────────────────────────────────────────────── */
add_action( 'wp_ajax_mmi_delete_taxonomy_mapping', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $id = (int) ( $_POST['mapping_id'] ?? 0 );
    if ( $id <= 0 ) {
        wp_send_json_error( [ 'message' => 'Invalid mapping ID' ] );
    }

    $ok = MMI_DB::delete_tax_mapping( $id );
    if ( $ok ) {
        mmi_data_pipeline_audit( 'taxonomy.delete', [
            'object_type' => 'taxonomy_mapping',
            'object_id'   => $id,
            'outcome'     => 'success',
        ] );
    }
    $ok ? wp_send_json_success( [ 'message' => 'Mapping deleted' ] )
        : wp_send_json_error( [ 'message' => 'Delete failed' ] );
} );

/* ── Exclude a source value from the mapper, or restore it ──────────────────
 * See MMI_Pipeline_Taxmap_Exclusions. wc_taxonomy is the taxonomy the value's
 * field feeds (the row's home taxonomy), not a redirect's. */
add_action( 'wp_ajax_mmi_set_taxonomy_value_excluded', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    // Unslashed: an exclusion must equal the feed value exactly, apostrophes included.
    $supplier     = sanitize_text_field( wp_unslash( $_POST['supplier_id']  ?? '' ) );
    $source_field = sanitize_text_field( wp_unslash( $_POST['source_field'] ?? '' ) );
    $wc_taxonomy  = sanitize_text_field( wp_unslash( $_POST['wc_taxonomy']  ?? '' ) );
    $source_value = sanitize_text_field( wp_unslash( $_POST['source_value'] ?? '' ) );
    $exclude      = ! empty( $_POST['excluded'] );

    if ( $supplier === '' || $source_field === '' || $source_value === '' || ! taxonomy_exists( $wc_taxonomy ) ) {
        wp_send_json_error( [ 'message' => 'supplier_id, source_field, source_value and a real wc_taxonomy are required' ] );
    }

    if ( $exclude ) {
        MMI_Pipeline_Taxmap_Exclusions::exclude( $supplier, $source_field, $wc_taxonomy, $source_value );
    } else {
        MMI_Pipeline_Taxmap_Exclusions::restore( $supplier, $source_field, $wc_taxonomy, $source_value );
    }

    mmi_data_pipeline_audit( $exclude ? 'taxonomy.exclude' : 'taxonomy.restore', [
        'object_type' => 'taxonomy_mapping',
        'object_id'   => $supplier,
        'outcome'     => 'success',
        'details'     => [ 'source_field' => $source_field, 'wc_taxonomy' => $wc_taxonomy, 'source_value' => $source_value ],
    ] );

    wp_send_json_success( MMI_Pipeline_Taxmap_Exclusions::row_fields( $supplier, $source_field, $wc_taxonomy, $source_value ) );
} );

/* ── List every saved profile-specific variation, flat ─────────────────────
 * Powers the "Manage Variations" panel — reviewing/bulk-deleting overrides
 * doesn't need the per-value feed-scanning mmi_get_taxonomy_source_values()/
 * mmi_scan_all_sources() do (this is pure DB data, cheap: one query, no file
 * reads), and a flat list is what bulk selection actually needs, not the
 * per-value nesting the main table uses for adding one variation at a time. */
add_action( 'wp_ajax_mmi_get_taxonomy_variations', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    global $wpdb;
    $table = MMI_DB::tax_mappings_table();
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE profile_id != '' ORDER BY profile_id ASC, source_field ASC, source_value ASC", ARRAY_A );

    $profile_labels  = mmi_taxmap_profile_label_map();
    $taxonomy_labels = [];
    $result          = [];

    foreach ( $rows as $row ) {
        $wc_taxonomy = $row['wc_taxonomy'];
        if ( ! array_key_exists( $wc_taxonomy, $taxonomy_labels ) ) {
            $tax_obj                        = get_taxonomy( $wc_taxonomy );
            $taxonomy_labels[ $wc_taxonomy ] = $tax_obj ? $tax_obj->labels->singular_name : $wc_taxonomy;
        }

        $term = mmi_taxmap_term_display( (int) $row['wc_term_id'], $wc_taxonomy );

        $result[] = [
            'mapping_id'      => (int) $row['id'],
            'supplier_id'     => $row['supplier_id'],
            'source_field'    => $row['source_field'],
            'source_value'    => $row['source_value'],
            'wc_taxonomy'     => $wc_taxonomy,
            'wc_taxonomy_label' => $taxonomy_labels[ $wc_taxonomy ],
            'profile_id'      => $row['profile_id'],
            'profile_label'   => $profile_labels[ $row['profile_id'] ] ?? $row['profile_id'],
            'wc_term_id'      => $term['wc_term_id'],
            'wc_term_name'    => $term['wc_term_name'],
            'missing_term_id' => $term['missing_term_id'],
        ];
    }

    wp_send_json_success( [
        'variations' => $result,
        'total'      => count( $result ),
        'profiles'   => $profile_labels,
    ] );
} );

/* ── Bulk-delete variations — either an explicit id list, or every
 * variation belonging to one profile in a single query ("wipe this
 * profile's overrides"). Both paths are additive to the existing single-id
 * mmi_delete_taxonomy_mapping endpoint above, not a replacement for it —
 * that one still backs each row's own individual delete button. */
add_action( 'wp_ajax_mmi_bulk_delete_taxonomy_variations', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    global $wpdb;
    $table = MMI_DB::tax_mappings_table();

    $wipe_profile = sanitize_text_field( $_POST['wipe_profile_id'] ?? '' );
    if ( $wipe_profile !== '' ) {
        $deleted = $wpdb->delete( $table, [ 'profile_id' => $wipe_profile ], [ '%s' ] );
        mmi_data_pipeline_audit( 'taxonomy.delete', [
            'object_type' => 'taxonomy_mapping',
            'object_id'   => $wipe_profile,
            'outcome'     => 'success',
            'details'     => [ 'scope' => 'profile', 'deleted' => (int) $deleted ],
        ] );
        wp_send_json_success( [
            'message' => sprintf( '%d variation(s) removed for this profile.', (int) $deleted ),
            'deleted' => (int) $deleted,
        ] );
    }

    $ids = array_filter( array_map( 'intval', (array) ( $_POST['mapping_ids'] ?? [] ) ) );
    if ( empty( $ids ) ) {
        wp_send_json_error( [ 'message' => 'No variations selected.' ] );
    }

    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );

    mmi_data_pipeline_audit( 'taxonomy.delete', [
        'object_type' => 'taxonomy_mapping',
        'object_id'   => '',
        'outcome'     => 'success',
        'details'     => [ 'mapping_ids' => array_values( $ids ), 'deleted' => (int) $deleted ],
    ] );
    wp_send_json_success( [
        'message' => sprintf( '%d variation(s) deleted.', (int) $deleted ),
        'deleted' => (int) $deleted,
    ] );
} );

/* ── Replace the entire alias rules list (array order = priority order) ──── */
add_action( 'wp_ajax_mmi_save_taxonomy_alias_rules', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $rules = json_decode( wp_unslash( $_POST['rules'] ?? '[]' ), true );
    if ( ! is_array( $rules ) ) {
        wp_send_json_error( [ 'message' => 'Invalid rules payload' ] );
    }

    $valid_operators = [ 'equals', 'contains', 'starts_with', 'ends_with', 'regex' ];
    $clean = [];
    foreach ( $rules as $r ) {
        $wc_taxonomy = sanitize_text_field( $r['wc_taxonomy'] ?? '' );
        $value       = sanitize_text_field( $r['value']       ?? '' );
        $wc_term_id  = (int) ( $r['wc_term_id'] ?? 0 );

        // Drop incomplete rows rather than saving a rule that can never match/apply.
        if ( $wc_taxonomy === '' || $value === '' || $wc_term_id === 0 ) {
            continue;
        }

        $operator = sanitize_text_field( $r['operator'] ?? 'contains' );
        if ( ! in_array( $operator, $valid_operators, true ) ) {
            $operator = 'contains';
        }

        $clean[] = [
            'label'        => sanitize_text_field( $r['label']        ?? '' ),
            'supplier_id'  => sanitize_text_field( $r['supplier_id']  ?? '' ),
            'source_field' => sanitize_text_field( $r['source_field'] ?? '' ),
            'operator'     => $operator,
            'value'        => $value,
            'wc_taxonomy'  => $wc_taxonomy,
            'wc_term_id'   => $wc_term_id,
        ];
    }

    $ok = MMI_DB::save_taxmap_alias_rules( $clean );
    if ( ! $ok ) {
        wp_send_json_error( [ 'message' => 'Failed to save alias rules' ] );
    }

    foreach ( $clean as &$rule ) {
        $tid = $rule['wc_term_id'];
        // wc_term_id is left as saved (the rule panel posts it back); the
        // missing flag only tells the panel to show the rule as broken.
        $rule['missing_term_id'] = 0;
        if ( $tid > 0 ) {
            $t = get_term( $tid, $rule['wc_taxonomy'] );
            $live = $t && ! is_wp_error( $t );
            $rule['wc_term_name']    = $live ? $t->name : '';
            $rule['missing_term_id'] = $live ? 0 : $tid;
        } elseif ( $tid === -1 ) {
            $rule['wc_term_name'] = '__skip__';
        } else {
            $rule['wc_term_name'] = '';
        }
    }
    unset( $rule );

    // Existing products whose stored value only a rule resolves get the
    // term in the background (Taxonomy_Mapping_Handler::run_heal()).
    $queued = \MannMade\DataPipeline\Importers\Taxonomy_Mapping_Handler::schedule_heal( [ 'mode' => 'rules' ] );

    wp_send_json_success( [ 'message' => 'Alias rules saved', 'rules' => $clean, 'apply_queued' => $queued ] );
} );

/* ── Find-or-create a WC term (used by the Alias Rules term picker) ──────── */
add_action( 'wp_ajax_mmi_create_wc_term', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $taxonomy  = sanitize_text_field( $_POST['taxonomy']  ?? '' );
    $term_name = sanitize_text_field( $_POST['term_name'] ?? '' );

    if ( $taxonomy === '' || $term_name === '' ) {
        wp_send_json_error( [ 'message' => 'taxonomy and term_name are required' ] );
    }

    $term = get_term_by( 'name', $term_name, $taxonomy );
    if ( ! $term ) {
        $result = wp_insert_term( $term_name, $taxonomy );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => 'Could not create term: ' . $result->get_error_message() ] );
        }
        $term_id = (int) $result['term_id'];
    } else {
        $term_id = (int) $term->term_id;
    }

    wp_send_json_success( [ 'wc_term_id' => $term_id, 'wc_term_name' => $term_name ] );
} );

/**
 * Profile ID => display name, for annotating a variant row with a readable
 * label instead of a raw slug. Single source: MMI_DB::get_profiles_by_direction(),
 * same call section-taxonomy-mapping.php itself uses to build the profile-picker
 * template — kept as one owner of "what does this profile_id mean to a human"
 * rather than a second, drifting copy.
 *
 * @return array<string, string> profile_id => name
 */
function mmi_taxmap_profile_label_map(): array {
    $labels = [];
    foreach ( MMI_DB::get_profiles_by_direction( 'import' ) as $id => $info ) {
        $labels[ $id ] = $info['name'] ?? $id;
    }
    return $labels;
}

/**
 * Builds the mapping index for one (supplier, wc_taxonomy) pair: a Global
 * resolution per source_value (the ONE mapping every profile sees unless it
 * has its own explicit override) plus every profile-specific override,
 * grouped by source_value, as a separate "variants" list.
 *
 * There is deliberately no "current profile" concept here — see the
 * 2026-08-30 Incident History entry ("Taxonomy Mapping Table Hardcoded
 * profile_id === ''...") for why an ambient page-wide editing scope caused a
 * real, hard-to-diagnose "my mappings disappeared" report: a value saved
 * under a non-Global profile_id was permanently invisible in this table no
 * matter which profile happened to be selected. The fix wasn't to make the
 * table profile-aware in the same ambient way — it's to stop pretending a
 * single "current" profile exists at all. Global is always shown; a
 * profile-specific override is always additional, explicit, per-value
 * information (see this row's "+ Add variation" affordance in
 * mmi_get_taxonomy_source_values' JSON output), never a substitute for it.
 *
 * @param string $supplier    '' matches only supplier-agnostic rows.
 * @param string $wc_taxonomy
 * @return array{global: array<string, array>, variants: array<string, array<int, array>>}
 */
/**
 * Display fields for a saved term reference. A term deleted in WooCommerce
 * after it was mapped comes back as wc_term_id 0 with its old id in
 * missing_term_id: the mapping no longer does anything at import
 * (resolve_tax_mapping() drops it), so the table must not show it as
 * mapped. Before this the deleted id was shown as a term name,
 * "(deleted #5671)", with a Mapped badge.
 *
 * @return array{wc_term_id:int, wc_term_name:string, missing_term_id:int}
 */
function mmi_taxmap_term_display( int $term_id, string $taxonomy ): array {
    if ( $term_id === -1 ) {
        return [ 'wc_term_id' => -1, 'wc_term_name' => '__skip__', 'missing_term_id' => 0 ];
    }
    if ( $term_id > 0 ) {
        $t = get_term( $term_id, $taxonomy );
        if ( $t && ! is_wp_error( $t ) ) {
            return [ 'wc_term_id' => $term_id, 'wc_term_name' => $t->name, 'missing_term_id' => 0 ];
        }
        return [ 'wc_term_id' => 0, 'wc_term_name' => '', 'missing_term_id' => $term_id ];
    }
    return [ 'wc_term_id' => 0, 'wc_term_name' => '', 'missing_term_id' => 0 ];
}

function mmi_taxmap_build_mapping_index( string $supplier, string $wc_taxonomy ): array {
    // MMI_DB::get_tax_mappings( '', $wc_taxonomy ) applies NO supplier_id
    // filter at all when the arg is '' (see that method's own
    // `if ( $supplier_id !== '' )` guard) — it returns every row for this
    // taxonomy across every supplier, not "supplier-agnostic rows only".
    // One fetch, partitioned below by an EXACT supplier_id match, is both
    // correct and cheaper than the two-query version this replaced — that
    // version's second "any-supplier" query was actually re-fetching the
    // same rows the first one already had, which silently duplicated every
    // variant in the list this function returns (caught live: ATLASSOUNDS'
    // one real Mogami Pricing override showed up twice).
    $all_rows = MMI_DB::get_tax_mappings( '', $wc_taxonomy );

    $global_any      = [];
    $global_supplier = [];
    $variants        = [];

    foreach ( $all_rows as $row ) {
        $rs = $row['supplier_id'] ?? '';
        if ( $rs !== '' && $rs !== $supplier ) {
            continue; // A different supplier's row — not relevant to this call.
        }
        if ( ( $row['profile_id'] ?? '' ) === '' ) {
            if ( $rs === '' ) {
                $global_any[ $row['source_value'] ] = $row;
            } else {
                $global_supplier[ $row['source_value'] ] = $row;
            }
        } else {
            $variants[ $row['source_value'] ][] = $row;
        }
    }

    return [
        // [supplier,''] beats ['',''] for the same source_value — merging
        // $global_supplier second lets it overwrite $global_any.
        'global'   => array_merge( $global_any, $global_supplier ),
        'variants' => $variants,
    ];
}

/* ── Scan source JSON for unique field values ────────────────────────────── */
add_action( 'wp_ajax_mmi_get_taxonomy_source_values', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $supplier     = sanitize_text_field( $_POST['supplier'] ?? 'xchange' );
    $source_field = sanitize_text_field( $_POST['source_field'] ?? 'brand' );
    $wc_taxonomy  = sanitize_text_field( $_POST['wc_taxonomy'] ?? 'product_brand' );

    // Determine JSON file
    $json_dir     = mmi_shared_lib_json_dir();
    $file_map     = mmi_taxmap_build_supplier_file_map();
    $json_file    = $json_dir . ( $file_map[ $supplier ] ?? $supplier . '-products.json' );

    if ( ! file_exists( $json_file ) ) {
        wp_send_json_error( [ 'message' => "JSON file not found: {$json_file}" ] );
    }

    $data  = json_decode( file_get_contents( $json_file ), true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( [ 'message' => 'Invalid JSON: ' . json_last_error_msg() ] );
    }

    // Extract items list
    if ( isset( $data['products'] ) )    $items = $data['products'];
    elseif ( isset( $data['items'] ) )   $items = $data['items'];
    elseif ( is_array( $data ) )         $items = $data;
    else                                 $items = [];

    // Support compound source fields like "master_category+sub_category"
    $parts      = array_map( 'trim', explode( '+', $source_field ) );
    $name_field = 'name'; // default product-name key

    $counts          = [];
    $sample_products = []; // key => [ up to 3 product names ]

    foreach ( $items as $item ) {
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
        $key = implode( ' / ', $values );
        $counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;

        // Collect up to 3 sample product names per source value
        if ( ! isset( $sample_products[ $key ] ) ) {
            $sample_products[ $key ] = [];
        }
        if ( count( $sample_products[ $key ] ) < 3 ) {
            // 'product' is Xchange's own product-name field (confirmed live:
            // Xchange records have no 'name'/'title'/'product_name' key at
            // all) — every Xchange-sourced row's Sample Products column was
            // silently blank until this was added, since none of the other
            // three checks could ever match. SkuPort records use 'name' and
            // have no 'product' key, so the two suppliers never collide.
            $product_name = mmi_taxmap_record_label( (array) $item );
            if ( $product_name !== '' && ! in_array( $product_name, $sample_products[ $key ], true ) ) {
                $sample_products[ $key ][] = $product_name;
            }
        }
    }

    arsort( $counts ); // Most frequent first

    // Global mapping per value, plus every profile-specific override —
    // there is no "current profile" here; see mmi_taxmap_build_mapping_index()'s
    // docblock.
    $index          = mmi_taxmap_build_mapping_index( $supplier, $wc_taxonomy );
    $profile_labels = mmi_taxmap_profile_label_map();

    $source_file = basename( $json_file );

    $result = [];
    foreach ( $counts as $value => $count ) {
        $mapping = $index['global'][ $value ] ?? null;

        // A row redirected to another taxonomy (see
        // Taxonomy_Mapping_Handler::find_redirect()) is shown under that
        // taxonomy; the index above only holds this taxonomy's rows.
        $row_taxonomy    = $wc_taxonomy;
        $redirected_from = '';
        if ( ! $mapping ) {
            $redirect = \MannMade\DataPipeline\Importers\Taxonomy_Mapping_Handler::find_redirect(
                $supplier, $source_field, (string) $value, [ $wc_taxonomy ], '', false
            );
            if ( $redirect !== null ) {
                $mapping         = $redirect;
                $row_taxonomy    = $redirect['wc_taxonomy'];
                $redirected_from = $wc_taxonomy;
            }
        }

        $wc_term_id   = $mapping ? (int) $mapping['wc_term_id'] : 0;
        $auto_create  = $mapping ? (bool) $mapping['auto_create'] : false;
        $mapping_id   = $mapping ? (int) $mapping['id'] : 0;
        $via_rule     = false;

        if ( $wc_term_id === 0 ) {
            $rule_tid = MMI_DB::match_taxmap_alias_rule( $supplier, $source_field, $value, $wc_taxonomy );
            if ( $rule_tid !== null ) {
                $wc_term_id = $rule_tid;
                $via_rule   = true;
            }
        }

        $term       = mmi_taxmap_term_display( $wc_term_id, $row_taxonomy );
        $wc_term_id = $term['wc_term_id'];
        $term_name  = $term['wc_term_name'];

        // Every profile-specific override for this value, annotated with a
        // readable profile name — the "+ N variations" badge and expandable
        // panel on this row are built from this list client-side.
        $variants = [];
        foreach ( $index['variants'][ $value ] ?? [] as $v_row ) {
            $v_term     = mmi_taxmap_term_display( (int) $v_row['wc_term_id'], $wc_taxonomy );
            $variants[] = [
                'mapping_id'      => (int) $v_row['id'],
                'profile_id'      => $v_row['profile_id'],
                'profile_label'   => $profile_labels[ $v_row['profile_id'] ] ?? $v_row['profile_id'],
                'wc_term_id'      => $v_term['wc_term_id'],
                'wc_term_name'    => $v_term['wc_term_name'],
                'missing_term_id' => $v_term['missing_term_id'],
            ];
        }

        $result[] = [
            // (string) cast is load-bearing, not decorative: $value came from a
            // foreach over $counts, an array keyed by source value. PHP silently
            // casts any array key that looks like a decimal integer (e.g. a raw
            // numeric category ID like "1517") to a real int key — so without this
            // cast, json_encode emits a JSON *number* for that row, and the JS
            // sort comparator's .toLowerCase() throws on it, aborting the sort
            // for the whole table (this is why "Source Value" sort looked broken
            // while other columns didn't).
            'source_value'    => (string) $value,
            'count'           => $count,
            'mapping_id'      => $mapping_id,
            'wc_term_id'      => $wc_term_id,
            'wc_term_name'    => $term_name,
            'missing_term_id' => $term['missing_term_id'],
            'redirected_from' => $redirected_from,
            'row_taxonomy'    => $row_taxonomy,
            'auto_create'     => $auto_create,
            'via_rule'        => $via_rule,
            'source_file'     => $source_file,
            'sample_products' => $sample_products[ $value ] ?? [],
            'variants'        => $variants,
        ] + MMI_Pipeline_Taxmap_Exclusions::row_fields( $supplier, $source_field, $wc_taxonomy, (string) $value );
    }

    // Excluded values are left out of the counts, as the table leaves them out.
    $in_mapper = array_filter( $result, fn( $r ) => ! $r['excluded'] );
    wp_send_json_success( [
        'values'      => $result,
        'total'       => count( $in_mapper ),
        'mapped'      => count( array_filter( $in_mapper, fn( $r ) => $r['wc_term_id'] !== 0 ) ),
        'excluded'    => count( $result ) - count( $in_mapper ),
        'source_file' => $source_file,
    ] );
} );

/* ── Search WC terms for autocomplete ────────────────────────────────────── */
add_action( 'wp_ajax_mmi_search_wc_terms', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $taxonomy = sanitize_text_field( $_POST['taxonomy'] ?? 'product_brand' );
    $search   = sanitize_text_field( $_POST['search']   ?? '' );

    $terms = get_terms( [
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'search'     => $search,
        'number'     => 30,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ] );

    if ( is_wp_error( $terms ) ) {
        wp_send_json_error( [ 'message' => $terms->get_error_message() ] );
    }

    $out = [];
    foreach ( $terms as $term ) {
        $out[] = [
            'id'    => $term->term_id,
            'name'  => $term->name,
            'slug'  => $term->slug,
            'count' => $term->count,
        ];
    }

    wp_send_json_success( [ 'terms' => $out ] );
} );

/* ── Batch-apply saved mappings to existing WC products ─────────────────── */
add_action( 'wp_ajax_mmi_apply_taxonomy_mappings', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    @set_time_limit( 120 );

    $supplier     = sanitize_text_field( $_POST['supplier']      ?? '' );
    $source_field = sanitize_text_field( $_POST['source_field']  ?? '' );
    $wc_taxonomy  = sanitize_text_field( $_POST['wc_taxonomy']   ?? '' );

    if ( empty( $source_field ) || empty( $wc_taxonomy ) ) {
        wp_send_json_error( [ 'message' => 'source_field and wc_taxonomy required' ] );
    }

    // Map: source_value → wc_term_id. Global-only ('' profile_id) — this bulk
    // "apply to existing products" action has no concept of which profile a
    // given product was imported under, so it can only safely apply the
    // Global mapping set; a profile-specific override would otherwise get
    // applied to every product regardless of profile, which is a correctness
    // bug (see the matching comment on the scan-all handler above).
    $mappings = [];
    foreach ( MMI_DB::get_tax_mappings( $supplier, $wc_taxonomy ) as $row ) {
        if ( ( $row['profile_id'] ?? '' ) !== '' ) {
            continue;
        }
        $tid = (int) $row['wc_term_id'];
        if ( $tid !== 0 ) {
            $mappings[ $row['source_value'] ] = $tid;
        }
    }
    // Overlay global mappings (supplier-specific takes priority)
    foreach ( MMI_DB::get_tax_mappings( '', $wc_taxonomy ) as $row ) {
        if ( ( $row['profile_id'] ?? '' ) !== '' ) {
            continue;
        }
        $tid = (int) $row['wc_term_id'];
        if ( $tid !== 0 && ! isset( $mappings[ $row['source_value'] ] ) ) {
            $mappings[ $row['source_value'] ] = $tid;
        }
    }

    if ( empty( $mappings ) ) {
        wp_send_json_success( [ 'message' => 'No mappings to apply', 'updated' => 0, 'skipped' => 0 ] );
    }

    // Products are found by the raw value apply_taxonomy_mappings() stores in
    // _mmi_src_{field}. 2026-11-12: limited to this supplier's products when a
    // supplier is given (it took every supplier's products with that meta),
    // values matched case-sensitively, and field locks respected — all by
    // writing through Taxonomy_Mapping_Handler::apply_saved_mapping(), the
    // same path saving a mapping uses. Values over its on-save limit go to the
    // background pass (schedule_heal()).
    global $wpdb;
    $meta_key = '_mmi_src_' . str_replace( '+', '_', $source_field );

    // Distinct values with how many products carry each. Grouped in PHP: the
    // column's collation would merge "Effects" and "effects".
    if ( $supplier !== '' ) {
        $values = $wpdb->get_col( $wpdb->prepare(
            "SELECT m.meta_value FROM {$wpdb->postmeta} m
             JOIN {$wpdb->postmeta} s ON s.post_id = m.post_id AND s.meta_key = %s
             WHERE m.meta_key = %s",
            '_mmi_supplier_sku_' . $supplier,
            $meta_key
        ) );
    } else {
        $values = $wpdb->get_col( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $meta_key
        ) );
    }
    $value_counts = [];
    foreach ( $values as $value ) {
        $value = (string) $value;
        $value_counts[ $value ] = ( $value_counts[ $value ] ?? 0 ) + 1;
    }

    $updated = 0;
    $already = 0;
    $locked  = 0;
    $skipped = 0;
    $queued  = 0;

    foreach ( $value_counts as $source_val => $count ) {
        $source_val = (string) $source_val;
        if ( $source_val === '' || MMI_Pipeline_Taxmap_Exclusions::is_excluded( $supplier, $source_field, $wc_taxonomy, $source_val ) ) {
            $skipped += $count;
            continue;
        }
        $mapped_tid = $mappings[ $source_val ]
            ?? MMI_DB::match_taxmap_alias_rule( $supplier, $source_field, $source_val, $wc_taxonomy );

        if ( $mapped_tid === null || (int) $mapped_tid <= 0 ) {
            $skipped += $count;
            continue;
        }

        // Verify term exists
        $term = get_term( (int) $mapped_tid, $wc_taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            $skipped += $count;
            continue;
        }

        $out = \MannMade\DataPipeline\Importers\Taxonomy_Mapping_Handler::apply_saved_mapping(
            $supplier, $source_field, $source_val, $wc_taxonomy, (int) $mapped_tid, [ $wc_taxonomy ]
        );
        if ( ! empty( $out['too_many'] ) ) {
            \MannMade\DataPipeline\Importers\Taxonomy_Mapping_Handler::schedule_heal( [
                'mode'         => 'value',
                'supplier_id'  => $supplier,
                'source_field' => $source_field,
                'source_value' => $source_val,
                'wc_taxonomy'  => $wc_taxonomy,
                'term_id'      => (int) $mapped_tid,
            ] );
            $queued += (int) $out['too_many'];
            continue;
        }
        $updated += (int) $out['updated'];
        $already += (int) $out['already'];
        $locked  += (int) $out['locked'];
    }

    mmi_data_pipeline_audit( 'taxonomy.apply', [
        'object_type' => 'taxonomy_mapping',
        'object_id'   => $supplier,
        'outcome'     => 'success',
        'details'     => [ 'source_field' => $source_field, 'wc_taxonomy' => $wc_taxonomy, 'updated' => $updated, 'locked' => $locked, 'queued' => $queued ],
    ] );
    $message = "Applied taxonomy mappings: {$updated} updated, {$already} already set, {$locked} locked, {$skipped} skipped";
    if ( $queued ) {
        $message .= ", {$queued} queued for a background pass";
    }
    wp_send_json_success( [
        'message' => $message,
        'updated' => $updated,
        'already' => $already,
        'locked'  => $locked,
        'skipped' => $skipped,
        'queued'  => $queued,
    ] );
} );

/* ── Scan ALL configured source profiles at once (unified view) ──────────── */
/**
 * Reads every known source→taxonomy scan profile's JSON feed and returns the
 * raw per-value counts/samples — deliberately WITHOUT any mapping/status
 * annotation. Cached (5 min, or until explicitly force-refreshed), since
 * this is the genuinely expensive part (reading 2-3 supplier JSON files).
 *
 * Mapping status is intentionally NOT part of this cache — see
 * mmi_taxmap_scan_all_sources' own handler below, which annotates this raw
 * scan live, every request, against the CURRENT profile scope. Splitting it
 * out this way means a mapping write never needs to invalidate this cache
 * (nothing about "what values exist in the feed" changed), which is what
 * let five separate delete_transient() call sites be removed entirely — see
 * the 2026-08-30 Incident History entry for why the old combined cache
 * (raw scan + Global-only mapping status baked in together) made a
 * profile-scoped mapping invisible in this table no matter how many times
 * the cache was invalidated and rebuilt.
 *
 * @param bool $force_refresh
 * @return array<int, array{supplier:string, source_field:string, wc_taxonomy:string, source_file:string, counts:array<string,int>, samples:array<string,array<int,string>>}>
 */
function mmi_taxmap_get_raw_source_scan( bool $force_refresh ): array {
    if ( ! $force_refresh ) {
        $cached = get_transient( 'mmi_taxmap_raw_scan_cache' );
        if ( is_array( $cached ) ) {
            return $cached;
        }
    }

    @set_time_limit( 120 );

    $json_dir = mmi_shared_lib_json_dir();

    // Every currently-configured (supplier, source_field, wc_taxonomy) triple —
    // derived from live Field Mapping data across every real profile and every
    // configured data source (API-based or uploaded/custom), not a hardcoded
    // xchange/skuport/plugivery list. See MMI_Pipeline_Field_Mapping_Defaults::
    // get_taxonomy_source_fields()'s own docblock and the 2026-08-30 taxonomy/
    // data-source integration work in AGENTS.md for why this replaced the
    // previous hardcoded array.
    // get_taxonomy_mapping_sources(): also scans a template source's
    // brand/category fields before any profile imports them.
    $scan_profiles = MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_mapping_sources();
    $file_map      = mmi_taxmap_build_supplier_file_map();

    // Cache JSON file reads — each file is loaded at most once
    $json_cache = [];

    $scan = [];

    foreach ( $scan_profiles as $profile ) {
        $supplier      = $profile['supplier'];
        $source_field  = $profile['source_field'];
        $wc_taxonomy   = $profile['wc_taxonomy'];
        $json_filename = $file_map[ $supplier ] ?? ( $supplier . '-products.json' );
        $json_file     = $json_dir . $json_filename;

        // Load and cache JSON
        if ( ! array_key_exists( $json_filename, $json_cache ) ) {
            if ( ! file_exists( $json_file ) ) {
                $json_cache[ $json_filename ] = null;
            } else {
                $decoded = json_decode( file_get_contents( $json_file ), true );
                $json_cache[ $json_filename ] = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : null;
            }
        }

        $data = $json_cache[ $json_filename ];
        if ( $data === null ) { continue; }

        if ( isset( $data['products'] ) )       { $items = $data['products']; }
        elseif ( isset( $data['items'] ) )      { $items = $data['items']; }
        elseif ( is_array( $data ) )            { $items = $data; }
        else                                    { $items = []; }

        $parts   = array_map( 'trim', explode( '+', $source_field ) );
        $counts  = [];
        $samples = [];

        foreach ( $items as $item ) {
            $values = [];
            foreach ( $parts as $part ) {
                $v = (string) ( $item[ $part ] ?? '' );
                if ( $v !== '' ) { $values[] = $v; }
            }
            if ( empty( $values ) ) { continue; }
            $key = implode( ' / ', $values );
            $counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;

            if ( ! isset( $samples[ $key ] ) ) { $samples[ $key ] = []; }
            if ( count( $samples[ $key ] ) < 3 ) {
                // 'product' is Xchange's own product-name field — see the
                // matching comment in mmi_get_taxonomy_source_values above.
                $pname = mmi_taxmap_record_label( (array) $item );
                if ( $pname !== '' && ! in_array( $pname, $samples[ $key ], true ) ) {
                    $samples[ $key ][] = $pname;
                }
            }
        }

        arsort( $counts );

        $scan[] = [
            'supplier'     => $supplier,
            'source_field' => $source_field,
            'wc_taxonomy'  => $wc_taxonomy,
            'source_file'  => $json_filename,
            'counts'       => $counts,
            'samples'      => $samples,
        ];
    }

    set_transient( 'mmi_taxmap_raw_scan_cache', $scan, 5 * MINUTE_IN_SECONDS );

    return $scan;
}

/* ── A source's fetched records (setup-time readers) ──────────────────────── */
/**
 * Reads a source's fetched JSON records. Shared by the candidate discovery
 * below and the per-source setup card.
 *
 * @return array{items: array, file: string}|WP_Error
 */
function mmi_taxmap_load_source_items( string $supplier_id, string $file_key = '' ) {
    $file_map  = mmi_taxmap_build_supplier_file_map();
    $file      = $file_key !== '' ? basename( $file_key ) : ( $file_map[ $supplier_id ] ?? "{$supplier_id}-products.json" );
    $json_path = rtrim( mmi_shared_lib_json_dir(), '/' ) . '/' . $file;

    if ( ! file_exists( $json_path ) ) {
        return new WP_Error( 'no_file', 'No data file found for this source. Run a Data Fetch or upload first.' );
    }
    $data = json_decode( (string) file_get_contents( $json_path ), true );
    if ( ! is_array( $data ) ) {
        return new WP_Error( 'bad_json', 'Could not parse the data file as JSON.' );
    }

    if ( isset( $data['products'] ) && is_array( $data['products'] ) )  { $items = $data['products']; }
    elseif ( isset( $data['items'] ) && is_array( $data['items'] ) )    { $items = $data['items']; }
    elseif ( isset( $data['data'] ) && is_array( $data['data'] ) )      { $items = $data['data']; }
    elseif ( isset( $data[0] ) )                                        { $items = $data; }
    else                                                                { $items = []; }

    if ( empty( $items ) ) {
        return new WP_Error( 'empty', 'No product items found in the file.' );
    }
    return [ 'items' => $items, 'file' => $file ];
}

/**
 * Feed fields that look like a brand or category: short text values that
 * repeat across records. A field whose values are mostly unique (a model
 * code, a description, an image file name) is a free-text field, not a
 * taxonomy, so a value must repeat: distinct values at most half of the
 * filled ones. Numbers, and ids/prices/descriptions by name, are left out.
 * Fields this source already declares or a profile already maps are skipped.
 *
 * @return array<int, array{path:string, label:string, suggested_taxonomy:string, unique_count:int, sample_vals:array<int,string>}>
 */
function mmi_taxmap_field_candidates( string $supplier_id, array $items ): array {
    $already_mapped = [];
    foreach ( MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_mapping_sources() as $row ) {
        if ( $row['supplier'] === $supplier_id ) {
            foreach ( array_map( 'trim', explode( '+', $row['source_field'] ) ) as $part ) {
                $already_mapped[ $part ] = true;
            }
        }
    }

    // Same non-taxonomy-signal skip list AttributeMappingController.php uses
    // for the identical reason — an id/price/description/date field is never
    // a plausible taxonomy any more than it's a plausible attribute.
    $skip_patterns = [
        '/^(id|sku|price|map|msrp|sale_price|cost|upc|ean|gtin|asin)$/i',
        '/^(description|name|title|image|gallery|url|link|permalink)$/i',
        '/^(stock|inventory|quantity|available|shipping|weight|width|height|length)$/i',
        '/^(updated_at|created_at|date|timestamp|status|enabled|active|featured)$/i',
        '/\.(id|sku|price|url|image|description|name)$/i',
    ];

    $field_values = [];
    foreach ( array_slice( $items, 0, 500 ) as $item ) {
        if ( ! is_array( $item ) ) {
            continue;
        }
        foreach ( mmi_flatten_item( $item ) as $path => $value ) {
            if ( $path === '' || isset( $already_mapped[ $path ] ) || ! is_string( $value ) || is_numeric( $value ) ) {
                continue;
            }
            $str = trim( $value );
            // Links and dates are never a brand or category.
            if ( $str === '' || strlen( $str ) > 100 || preg_match( '#^(https?://|www\.|\d{4}-\d{2}-\d{2})#i', $str ) ) {
                continue;
            }
            foreach ( $skip_patterns as $pattern ) {
                if ( preg_match( $pattern, $path ) ) {
                    continue 2;
                }
            }
            $field_values[ $path ][] = $str;
        }
    }

    $taxonomies = get_object_taxonomies( 'product', 'objects' );
    $candidates = [];
    foreach ( $field_values as $path => $values ) {
        $filled = count( $values );
        $unique = array_values( array_unique( $values ) );
        $count  = count( $unique );
        if ( $count < 2 || $count * 2 > $filled ) {
            continue;
        }

        $last_seg = basename( str_replace( '.', '/', (string) $path ) );
        $label    = ucwords( str_replace( [ '_', '-' ], ' ', $last_seg ) );

        // A starting suggestion only — the person still picks the taxonomy.
        $suggested_taxonomy = '';
        foreach ( $taxonomies as $tax ) {
            $tax_short = preg_replace( '/^(product_|pa_)/', '', $tax->name );
            if ( strcasecmp( $tax_short, $last_seg ) === 0 || strcasecmp( $tax->label, $label ) === 0 ) {
                $suggested_taxonomy = $tax->name;
                break;
            }
        }
        if ( $suggested_taxonomy === '' && preg_match( '/categor|section|group|department|type/i', $last_seg ) ) {
            $suggested_taxonomy = 'product_cat';
        } elseif ( $suggested_taxonomy === '' && preg_match( '/brand|maker|manufacturer|vendor/i', $last_seg ) && taxonomy_exists( 'product_brand' ) ) {
            $suggested_taxonomy = 'product_brand';
        }

        $candidates[] = [
            'path'               => (string) $path,
            'label'              => $label,
            'suggested_taxonomy' => $suggested_taxonomy,
            'unique_count'       => $count,
            'sample_vals'        => array_slice( $unique, 0, 5 ),
        ];
    }

    usort( $candidates, static fn( $a, $b ) => abs( $a['unique_count'] - 10 ) - abs( $b['unique_count'] - 10 ) );
    return array_slice( $candidates, 0, 15 );
}

/**
 * Product taxonomies a source field can feed: categories, brands, tags and
 * any custom product taxonomy — not WooCommerce's internal ones.
 *
 * @return array<int, array{slug:string, label:string}>
 */
function mmi_taxmap_assignable_taxonomies(): array {
    $internal = [ 'product_type', 'product_visibility', 'product_shipping_class' ];
    $out      = [];
    foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax ) {
        if ( in_array( $tax->name, $internal, true ) || strpos( $tax->name, 'pa_' ) === 0 ) {
            continue;
        }
        $out[] = [ 'slug' => $tax->name, 'label' => $tax->label ];
    }
    return $out;
}

/* ── Discover taxonomy candidates from a supplier sample file (setup-time) ──
 * Backs Configure › Taxonomies' "Suggest from feed" button. See
 * mmi_taxmap_field_candidates() for what counts as a candidate.
 */
add_action( 'wp_ajax_mmi_discover_taxonomy_candidates', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $supplier_id = sanitize_text_field( wp_unslash( $_POST['supplier_id'] ?? '' ) );
    $file_key    = sanitize_text_field( wp_unslash( $_POST['file_key'] ?? '' ) );
    if ( $supplier_id === '' ) {
        wp_send_json_error( [ 'message' => 'supplier_id required' ] );
    }

    $loaded = mmi_taxmap_load_source_items( $supplier_id, $file_key );
    if ( is_wp_error( $loaded ) ) {
        wp_send_json_error( [ 'message' => $loaded->get_error_message() ] );
    }

    wp_send_json_success( [
        'candidates'  => mmi_taxmap_field_candidates( $supplier_id, $loaded['items'] ),
        'total_items' => count( $loaded['items'] ),
        'file'        => $loaded['file'],
    ] );
} );

/* ── Per-source setup (2026-11-12) ────────────────────────────────────────
 * A data source row's "Set up" link opens Taxonomy Mapping on that source.
 * A source with no declared brand/category field has no values to map, so
 * instead of an empty table the panel shows what the source's file offers:
 * fields that look like a brand or category, and — for a price list that
 * groups products under heading rows (Mogami's dealer CSV) — the headings,
 * which become a Section field once switched on. Picking one declares it
 * (configuration.taxonomy_fields, the same list Configure › Taxonomies
 * edits) and the values appear for mapping. Declaring a field never turns
 * on an import: a profile still switches it on in Field Mapping
 * ("Enable for …").
 */
add_action( 'wp_ajax_mmi_taxmap_source_setup', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $supplier_id = sanitize_text_field( wp_unslash( $_POST['supplier_id'] ?? '' ) );
    $suppliers   = MMI_Pipeline_Admin::get_configured_suppliers();
    if ( $supplier_id === '' || ! isset( $suppliers[ $supplier_id ] ) ) {
        wp_send_json_error( [ 'message' => 'Unknown data source.' ] );
    }
    $info = $suppliers[ $supplier_id ];

    global $wpdb;
    $file_config = json_decode( (string) $wpdb->get_var( $wpdb->prepare(
        "SELECT file_config FROM {$wpdb->prefix}mmi_data_sources WHERE supplier_id = %s",
        $supplier_id
    ) ), true ) ?: [];

    $result = [
        'supplier_id'   => $supplier_id,
        'supplier_name' => (string) ( $info['supplier_name'] ?? $supplier_id ),
        'source_type'   => (string) ( $info['source_type'] ?? '' ),
        'mapping_on'    => (bool) ( $info['taxonomy_mapping_enabled'] ?? true ),
        'declared'      => array_values( (array) ( $info['taxonomy_fields'] ?? [] ) ),
        'taxonomies'    => mmi_taxmap_assignable_taxonomies(),
        'file'          => '',
        'total_items'   => 0,
        'candidates'    => [],
        'sections'      => [
            'enabled'   => ! empty( $file_config['section_rows'] ),
            'headings'  => [],
            'can_apply' => ( $info['source_type'] ?? '' ) === 'upload',
        ],
        'error'         => '',
    ];

    $loaded = mmi_taxmap_load_source_items( $supplier_id );
    if ( is_wp_error( $loaded ) ) {
        $result['error'] = $loaded->get_error_message();
        wp_send_json_success( $result );
    }
    $result['file']        = $loaded['file'];
    $result['total_items'] = count( $loaded['items'] );
    $result['candidates']  = mmi_taxmap_field_candidates( $supplier_id, $loaded['items'] );

    if ( ! $result['sections']['enabled'] && class_exists( 'MMI_Pipeline_Upload_Source_Fetcher' ) ) {
        foreach ( MMI_Pipeline_Upload_Source_Fetcher::find_section_headings( $loaded['items'] ) as $name => $count ) {
            $result['sections']['headings'][] = [ 'name' => (string) $name, 'count' => (int) $count ];
        }
    }

    wp_send_json_success( $result );
} );

add_action( 'wp_ajax_mmi_taxmap_declare_source_field', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $supplier_id  = sanitize_text_field( wp_unslash( $_POST['supplier_id'] ?? '' ) );
    $source_field = trim( sanitize_text_field( wp_unslash( $_POST['source_field'] ?? '' ) ) );
    $wc_taxonomy  = sanitize_key( wp_unslash( $_POST['wc_taxonomy'] ?? '' ) );
    $use_sections = ! empty( $_POST['use_sections'] );

    if ( ! in_array( $wc_taxonomy, array_column( mmi_taxmap_assignable_taxonomies(), 'slug' ), true ) ) {
        wp_send_json_error( [ 'message' => 'Pick a product taxonomy.' ] );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mmi_data_sources';
    $row   = $wpdb->get_row( $wpdb->prepare(
        "SELECT supplier_name, source_type, configuration, file_config FROM {$table} WHERE supplier_id = %s",
        $supplier_id
    ), ARRAY_A );
    if ( ! $row ) {
        wp_send_json_error( [ 'message' => 'Unknown data source.' ] );
    }
    $configuration = json_decode( (string) $row['configuration'], true ) ?: [];
    $file_config   = json_decode( (string) $row['file_config'], true ) ?: [];

    // Section headings: switch the option on and rebuild the records, so the
    // Section field exists before it is declared.
    $refetched = false;
    if ( $use_sections ) {
        if ( $row['source_type'] !== 'upload' || ! class_exists( 'MMI_Pipeline_Upload_Source_Fetcher' ) ) {
            wp_send_json_error( [ 'message' => 'Section headings can be read from an uploaded file only.' ] );
        }
        if ( empty( $file_config['section_rows'] ) ) {
            $file_config['section_rows'] = true;
            $wpdb->update( $table, [ 'file_config' => wp_json_encode( $file_config ), 'updated_at' => current_time( 'mysql' ) ], [ 'supplier_id' => $supplier_id ] );
            try {
                MMI_Pipeline_Upload_Source_Fetcher::fetch( $supplier_id );
                $refetched = true;
            } catch ( \RuntimeException $e ) {
                $file_config['section_rows'] = false;
                $wpdb->update( $table, [ 'file_config' => wp_json_encode( $file_config ) ], [ 'supplier_id' => $supplier_id ] );
                wp_send_json_error( [ 'message' => 'Could not re-read the uploaded file: ' . $e->getMessage() ] );
            }
        }
        $source_field = MMI_Pipeline_Upload_Source_Fetcher::SECTION_FIELD;
    }

    if ( $source_field === '' ) {
        wp_send_json_error( [ 'message' => 'Pick a field.' ] );
    }

    // The field must exist in the records (compound a+b: every part).
    $loaded = mmi_taxmap_load_source_items( $supplier_id );
    if ( is_wp_error( $loaded ) ) {
        wp_send_json_error( [ 'message' => $loaded->get_error_message() ] );
    }
    $first = is_array( $loaded['items'][0] ?? null ) ? $loaded['items'][0] : [];
    if ( $use_sections && ! array_key_exists( $source_field, $first ) && array_key_exists( $source_field . ' heading', $first ) ) {
        $source_field .= ' heading'; // The file had its own Section column.
    }
    $seen_paths = [];
    foreach ( array_slice( $loaded['items'], 0, 50 ) as $item ) {
        if ( is_array( $item ) ) {
            $seen_paths += mmi_flatten_item( $item );
        }
    }
    foreach ( array_map( 'trim', explode( '+', $source_field ) ) as $part ) {
        if ( ! array_key_exists( $part, $seen_paths ) ) {
            wp_send_json_error( [ 'message' => sprintf( 'The file has no "%s" field.', $part ) ] );
        }
    }

    $fields = MMI_Pipeline_Admin::resolve_source_taxonomy_fields( $supplier_id, $configuration );
    foreach ( $fields as $existing ) {
        if ( $existing['wc_taxonomy'] === $wc_taxonomy && $existing['source_field'] !== $source_field ) {
            wp_send_json_error( [ 'message' => sprintf( 'This source already reads %s from "%s". Change it in Configure › Taxonomies.', $wc_taxonomy, $existing['source_field'] ) ] );
        }
    }
    $fields[] = [ 'source_field' => $source_field, 'wc_taxonomy' => $wc_taxonomy ];
    $configuration['taxonomy_fields'] = MMI_Pipeline_Field_Mapping_Defaults::normalize_taxonomy_fields( $fields );

    $ok = $wpdb->update(
        $table,
        [ 'configuration' => wp_json_encode( $configuration ), 'updated_at' => current_time( 'mysql' ) ],
        [ 'supplier_id' => $supplier_id ]
    );
    if ( $ok === false ) {
        wp_send_json_error( [ 'message' => 'Database error: ' . $wpdb->last_error ] );
    }
    delete_transient( 'mmi_taxmap_raw_scan_cache' );

    mmi_data_pipeline_audit( 'source.update', [
        'object_type' => 'data_source',
        'object_id'   => $supplier_id,
        'outcome'     => 'success',
        'details'     => [ 'taxonomy_field' => $source_field, 'wc_taxonomy' => $wc_taxonomy, 'section_rows' => $use_sections ],
    ] );

    $tax_obj = get_taxonomy( $wc_taxonomy );
    wp_send_json_success( [
        'fields'       => $configuration['taxonomy_fields'],
        'source_field' => $source_field,
        'wc_taxonomy'  => $wc_taxonomy,
        'pill_label'   => $row['supplier_name'] . ' — ' . ( $tax_obj ? $tax_obj->label : $wc_taxonomy ),
        'records'      => count( $loaded['items'] ),
        'refetched'    => $refetched,
    ] );
} );

add_action( 'wp_ajax_mmi_scan_all_sources', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $force_refresh = ! empty( $_POST['force_refresh'] );

    $scan = mmi_taxmap_get_raw_source_scan( $force_refresh );

    // Pre-load ALL existing mappings in one query to avoid N+1 DB calls, then
    // resolve a Global mapping per (supplier, field, value, taxonomy) key
    // plus every profile-specific override as a separate "variants" group —
    // no ambient "current profile" tier here, matching
    // mmi_taxmap_build_mapping_index()'s docblock (this handler pre-dates
    // that per-(supplier,taxonomy) helper and indexes across all three scan
    // profiles from one combined query instead, so it keeps its own
    // composite-key version of the same idea rather than calling it 3x).
    $all_saved       = MMI_DB::get_tax_mappings();
    $profile_labels  = mmi_taxmap_profile_label_map();
    $global_supplier = [];
    $global_any      = [];
    $variants        = [];

    foreach ( $all_saved as $row ) {
        $rs = $row['supplier_id'] ?? '';
        $k  = ( $rs !== '' ? $rs : '' ) . '|' . $row['source_field'] . '|' . $row['source_value'] . '|' . $row['wc_taxonomy'];

        if ( ( $row['profile_id'] ?? '' ) === '' ) {
            if ( $rs !== '' ) {
                $global_supplier[ $k ] = $row;
            } else {
                $global_any[ $k ] = $row;
            }
        } else {
            $variants[ $k ][] = $row;
        }
    }

    // Taxonomies each supplier field feeds directly — anything else saved
    // for that field is a redirect (see find_redirect()).
    $own_taxonomies = [];
    foreach ( $scan as $source ) {
        $own_taxonomies[ $source['supplier'] . '|' . $source['source_field'] ][] = $source['wc_taxonomy'];
    }

    $result = [];

    foreach ( $scan as $source ) {
        $supplier      = $source['supplier'];
        $source_field  = $source['source_field'];
        $wc_taxonomy   = $source['wc_taxonomy'];
        $json_filename = $source['source_file'];

        foreach ( $source['counts'] as $value => $count ) {
            $sk      = $supplier . '|' . $source_field . '|' . $value . '|' . $wc_taxonomy;
            $gk      = '|' . $source_field . '|' . $value . '|' . $wc_taxonomy;
            $mapping = $global_supplier[ $sk ] ?? $global_any[ $gk ] ?? null;

            // Redirected to another taxonomy: shown, saved and cleared under
            // that taxonomy (the row's taxonomy select already shows it).
            $row_taxonomy    = $wc_taxonomy;
            $redirected_from = '';
            if ( ! $mapping ) {
                $redirect = \MannMade\DataPipeline\Importers\Taxonomy_Mapping_Handler::find_redirect(
                    $supplier, $source_field, (string) $value,
                    $own_taxonomies[ $supplier . '|' . $source_field ], '', false
                );
                if ( $redirect !== null ) {
                    $mapping         = $redirect;
                    $row_taxonomy    = $redirect['wc_taxonomy'];
                    $redirected_from = $wc_taxonomy;
                    $sk              = $supplier . '|' . $source_field . '|' . $value . '|' . $row_taxonomy;
                    $gk              = '|' . $source_field . '|' . $value . '|' . $row_taxonomy;
                }
            }

            $wc_term_id  = $mapping ? (int)  $mapping['wc_term_id']  : 0;
            $auto_create = $mapping ? (bool) $mapping['auto_create'] : false;
            $mapping_id  = $mapping ? (int)  $mapping['id']          : 0;
            $via_rule    = false;

            // No exact mapping — check whether an alias rule covers this value.
            if ( $wc_term_id === 0 ) {
                $rule_tid = MMI_DB::match_taxmap_alias_rule( $supplier, $source_field, $value, $wc_taxonomy );
                if ( $rule_tid !== null ) {
                    $wc_term_id = $rule_tid;
                    $via_rule   = true;
                }
            }

            $term       = mmi_taxmap_term_display( $wc_term_id, $row_taxonomy );
            $wc_term_id = $term['wc_term_id'];
            $term_name  = $term['wc_term_name'];

            // Variants can be saved either supplier-specific or supplier-agnostic
            // for the same value — both apply to this row, so both keys are checked.
            $row_variants = array_merge( $variants[ $sk ] ?? [], $variants[ $gk ] ?? [] );
            $variant_list = [];
            foreach ( $row_variants as $v_row ) {
                $v_term         = mmi_taxmap_term_display( (int) $v_row['wc_term_id'], $row_taxonomy );
                $variant_list[] = [
                    'mapping_id'      => (int) $v_row['id'],
                    'profile_id'      => $v_row['profile_id'],
                    'profile_label'   => $profile_labels[ $v_row['profile_id'] ] ?? $v_row['profile_id'],
                    'wc_term_id'      => $v_term['wc_term_id'],
                    'wc_term_name'    => $v_term['wc_term_name'],
                    'missing_term_id' => $v_term['missing_term_id'],
                ];
            }

            $result[] = [
                // (string) cast load-bearing — see the matching comment in
                // mmi_get_taxonomy_source_values above for why.
                'source_value'    => (string) $value,
                'source_field'    => $source_field,
                'wc_taxonomy'     => $row_taxonomy,
                'redirected_from' => $redirected_from,
                'supplier'        => $supplier,
                'count'           => $count,
                'mapping_id'      => $mapping_id,
                'wc_term_id'      => $wc_term_id,
                'wc_term_name'    => $term_name,
                'missing_term_id' => $term['missing_term_id'],
                'auto_create'     => $auto_create,
                'via_rule'        => $via_rule,
                'source_file'     => $json_filename,
                'sample_products' => $source['samples'][ $value ] ?? [],
                'variants'        => $variant_list,
                // Keyed by the field's own taxonomy, not a redirect's.
            ] + MMI_Pipeline_Taxmap_Exclusions::row_fields( $supplier, $source_field, $wc_taxonomy, (string) $value );
        }
    }

    // Sort by count descending by default
    usort( $result, fn( $a, $b ) => $b['count'] - $a['count'] );

    // Excluded values are left out of the counts, as the table leaves them out.
    $in_mapper = array_filter( $result, fn( $r ) => ! $r['excluded'] );

    wp_send_json_success( [
        'values'   => $result,
        'total'    => count( $in_mapper ),
        'mapped'   => count( array_filter( $in_mapper, fn( $r ) => $r['wc_term_id'] !== 0 ) ),
        'excluded' => count( $result ) - count( $in_mapper ),
    ] );
} );

/* ── Apply ALL saved mappings to existing products (global batch) ────────── */
add_action( 'wp_ajax_mmi_apply_all_taxonomy_mappings', function () {
    check_ajax_referer( TAXMAP_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    @set_time_limit( 180 );

    // See get_taxonomy_source_fields()'s own docblock — this covers any
    // configured source (uploaded/custom included), not a hardcoded
    // xchange/skuport-only array.
    $all_profiles = array_map(
        static function ( array $row ): array {
            $row['meta_key'] = mmi_taxmap_supplier_sku_meta_key( $row['supplier'] );
            return $row;
        },
        MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_source_fields()
    );

    global $wpdb;
    $total_updated = 0;
    $total_skipped = 0;

    // Pre-load all saved mappings once. Global-only — see the matching comment
    // on the scan handler above.
    $all_saved = array_filter( MMI_DB::get_tax_mappings(), static fn( $row ) => ( $row['profile_id'] ?? '' ) === '' );
    $mapping_index = [];
    foreach ( $all_saved as $row ) {
        $tid = (int) $row['wc_term_id'];
        if ( $tid !== 0 ) {
            $k = $row['supplier_id'] . '|' . $row['source_field'] . '|' . $row['wc_taxonomy'];
            $mapping_index[ $k ][ $row['source_value'] ] = $tid;
        }
    }

    $json_dir = mmi_shared_lib_json_dir();

    $file_map = mmi_taxmap_build_supplier_file_map();

    // Taxonomies each supplier field feeds directly, and whether any saved
    // row for that field points somewhere else (a redirect, see
    // Taxonomy_Mapping_Handler::find_redirect()).
    $own_taxonomies = [];
    foreach ( $all_profiles as $profile ) {
        $own_taxonomies[ $profile['supplier'] . '|' . $profile['source_field'] ][] = $profile['wc_taxonomy'];
    }
    $has_redirects = [];
    foreach ( $all_saved as $row ) {
        foreach ( $own_taxonomies as $sf_key => $taxes ) {
            [ $sup, $field ] = explode( '|', $sf_key, 2 );
            if ( $row['source_field'] === $field
                && in_array( $row['supplier_id'], [ $sup, '' ], true )
                && ! in_array( $row['wc_taxonomy'], $taxes, true )
                && (int) $row['wc_term_id'] > 0 ) {
                $has_redirects[ $sf_key ] = true;
            }
        }
    }
    $redirected = 0;

    foreach ( $all_profiles as $profile ) {
        $index_key = $profile['supplier'] . '|' . $profile['source_field'] . '|' . $profile['wc_taxonomy'];
        $mappings  = $mapping_index[ $index_key ] ?? [];
        $sf_key    = $profile['supplier'] . '|' . $profile['source_field'];

        if ( empty( $mappings ) && empty( $has_redirects[ $sf_key ] ) ) { continue; }

        // Also overlay global ('') mappings
        $gk      = '|' . $profile['source_field'] . '|' . $profile['wc_taxonomy'];
        $globals  = $mapping_index[ $gk ] ?? [];
        foreach ( $globals as $sv => $tid ) {
            if ( ! isset( $mappings[ $sv ] ) ) { $mappings[ $sv ] = $tid; }
        }

        // Build SKU → source_value map from JSON
        $json_filename = $file_map[ $profile['supplier'] ] ?? ( $profile['supplier'] . '-products.json' );
        $json_file = $json_dir . $json_filename;
        if ( ! file_exists( $json_file ) ) { continue; }

        $data = json_decode( file_get_contents( $json_file ), true );
        if ( json_last_error() !== JSON_ERROR_NONE ) { continue; }

        if ( isset( $data['products'] ) )  { $items = $data['products']; }
        elseif ( isset( $data['items'] ) ) { $items = $data['items']; }
        elseif ( is_array( $data ) )       { $items = $data; }
        else                               { continue; }

        $parts   = array_map( 'trim', explode( '+', $profile['source_field'] ) );
        $sku_to_value = [];
        foreach ( $items as $item ) {
            $sku    = (string) ( $item['sku'] ?? '' );
            $values = [];
            foreach ( $parts as $part ) {
                $v = (string) ( $item[ $part ] ?? '' );
                if ( $v !== '' ) { $values[] = $v; }
            }
            if ( $sku !== '' && ! empty( $values ) ) {
                $sku_to_value[ $sku ] = implode( ' / ', $values );
            }
        }

        // Apply to products
        $product_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
                $profile['meta_key']
            ),
            ARRAY_A
        );

        foreach ( $product_rows as $prow ) {
            $product_id   = (int) $prow['post_id'];
            $supplier_sku = (string) $prow['meta_value'];

            $source_value = $sku_to_value[ $supplier_sku ] ?? null;
            if ( $source_value === null ) { $total_skipped++; continue; }
            if ( MMI_Pipeline_Taxmap_Exclusions::is_excluded( $profile['supplier'], $profile['source_field'], $profile['wc_taxonomy'], $source_value ) ) { $total_skipped++; continue; }

            // Added to the other taxonomy, never replacing what is there,
            // and only if that taxonomy is not locked on this product.
            if ( ! empty( $has_redirects[ $sf_key ] ) ) {
                $redirect = \MannMade\DataPipeline\Importers\Taxonomy_Mapping_Handler::find_redirect(
                    $profile['supplier'], $profile['source_field'], $source_value, $own_taxonomies[ $sf_key ], ''
                );
                if ( $redirect !== null ) {
                    $locks = MMI_Pipeline_Field_Locks::get( $product_id );
                    if ( MMI_Pipeline_Field_Locks::in_list( $redirect['wc_taxonomy'], $locks ) ) { $total_skipped++; continue; }
                    wp_set_object_terms( $product_id, [ (int) $redirect['wc_term_id'] ], $redirect['wc_taxonomy'], true );
                    $total_updated++;
                    $redirected++;
                    continue;
                }
            }

            $mapped_tid = $mappings[ $source_value ]
                ?? MMI_DB::match_taxmap_alias_rule( $profile['supplier'], $profile['source_field'], $source_value, $profile['wc_taxonomy'] );
            if ( $mapped_tid === null || $mapped_tid === -1 ) { $total_skipped++; continue; }

            $term = get_term( $mapped_tid, $profile['wc_taxonomy'] );
            if ( ! $term || is_wp_error( $term ) ) { $total_skipped++; continue; }

            // A term set by hand (Product Workbench, Product Titles) locks the
            // field; imports respect that and so must this.
            if ( MMI_Pipeline_Field_Locks::in_list( $profile['wc_taxonomy'], MMI_Pipeline_Field_Locks::get( $product_id ) ) ) { $total_skipped++; continue; }

            wp_set_object_terms( $product_id, [ $mapped_tid ], $profile['wc_taxonomy'] );
            $total_updated++;
        }
    }

    mmi_data_pipeline_audit( 'taxonomy.apply', [
        'object_type' => 'taxonomy_mapping',
        'object_id'   => 'all',
        'outcome'     => 'success',
        'details'     => [ 'scope' => 'all' ],
    ] );
    wp_send_json_success( [
        'message' => "Applied all taxonomy mappings: {$total_updated} updated ({$redirected} via redirect), {$total_skipped} skipped",
        'updated' => $total_updated,
        'skipped' => $total_skipped,
        'redirected' => $redirected,
    ] );
} );
