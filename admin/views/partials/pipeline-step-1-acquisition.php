<?php
/**
 * Pipeline Step 1: Data Fetch & Sources
 * Configure supplier data sources and trigger fetch operations
 *
 * Dynamic version — loads all sources from wp_mmi_data_sources table.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ── Load dynamic data sources ──────────────────────────────────────────────

global $wpdb;
$ds_table = $wpdb->prefix . 'mmi_data_sources';
$table_exists = ($wpdb->get_var("SHOW TABLES LIKE '{$ds_table}'") === $ds_table);

$all_sources = [];
if ($table_exists) {
    $all_sources = $wpdb->get_results(
        "SELECT * FROM {$ds_table} ORDER BY display_order ASC, supplier_name ASC",
        ARRAY_A
    );
}

// Decode JSON columns
foreach ($all_sources as &$src) {
    $src['configuration'] = json_decode($src['configuration'] ?: '{}', true);
    $src['auth_config']   = json_decode($src['auth_config'] ?: '{}', true);
    $src['file_config']   = json_decode($src['file_config'] ?: '{}', true);
}
unset($src);

// Fallback: if table is empty/missing, show empty state (no ghost legacy rows)
if (empty($all_sources)) {
    $all_sources = [];
}

// Count products from JSON feed files for known legacy suppliers
$json_dir = mmi_shared_lib_json_dir();
$feed_counts = [];

foreach ($all_sources as $src) {
    $sid = $src['supplier_id'];
    $count = 0;

    // Use last_fetch_count from DB when it's a positive number
    if (isset($src['last_fetch_count']) && (int) $src['last_fetch_count'] > 0) {
        $count = (int) $src['last_fetch_count'];
    } else {
        // DB count missing or zero — fall back to counting the JSON file directly
        $json_dir  = mmi_shared_lib_json_dir();
        $json_file = $json_dir . $sid . '-products.json';
        if (file_exists($json_file)) {
            $json_data = json_decode(file_get_contents($json_file), true);
            if (is_array($json_data)) {
                $count = isset($json_data['products']) ? count($json_data['products']) : count($json_data);
            }
        }
    }

    $feed_counts[$sid] = $count;
}

$processes = class_exists('MMI_Pipeline_Cron') ? MMI_Pipeline_Cron::get_supplier_configs() : [];

$schedule_frequencies = [
    'disabled'   => 'Disabled',
    'hourly'     => 'Every Hour (@ :00)',
    'twicedaily' => 'Twice Daily (12:00 AM & PM)',
    'daily'      => 'Daily (12:00 AM)',
];

// Source type labels
$source_type_labels = [
    'api'     => 'API',
    'url'     => 'Direct URL',
    'upload'  => 'Upload',
    'dropbox' => 'Dropbox',
    'gdrive'  => 'Google Drive',
];

// Config status CSS mapping
$status_dot_classes = [
    'validated'    => 'mmi-dot-green',
    'configured'   => 'mmi-dot-yellow',
    'error'        => 'mmi-dot-red',
    'unconfigured' => 'mmi-dot-gray',
];

// Shared by both this row loop's own status badge (below) and, previously,
// the now-removed Feed Status Cards' matching badge — kept here rather than
// re-declared inline, since the row loop is the only consumer left.
$status_badge_map = [
    'validated'    => ['class' => 'info',    'label' => 'Ready'],
    'configured'   => ['class' => 'warning', 'label' => 'Needs Test'],
    'error'        => ['class' => 'error',   'label' => 'Error'],
    'unconfigured' => ['class' => '',        'label' => 'Needs Setup'],
];
?>

    <!-- Section Header (collapsible; drag-to-reorder was removed, so this
         is no longer draggable) -->
    <div class="mmi-section-header mmi-process-section-header-row mmi-section-clickable">
        <div>
            <h3 class="mmi-process-section-header">
                <span class="dashicons dashicons-database"></span>
                Supplier Data Sources
                <span class="mmi-collapse-toggle">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </span>
            </h3>
            <p class="mmi-process-section-description">Configure data sources and fetch supplier data</p>
        </div>
    </div>

    <div class="mmi-section-content">

    <!-- Supplier Data Sources & Fetch Management -->
    <div class="mmi-supplier-section">
        <?php
        // Stale-data banner. The import pre-flight validator already refuses to
        // stay quiet about an old feed, but that warning only appeared at the
        // moment of running an import — this surfaces the same fact where the
        // sources actually live, so it's visible before getting that far.
        // Single owner for the threshold: the same constant the import pre-flight
        // validator uses. Defining a second number here is what let the table and
        // the import warning tell the user two different things in the first place.
        $mmi_stale_after_hours = class_exists( 'MMI_Pipeline_Config_Validator' )
            ? MMI_Pipeline_Config_Validator::STALE_FEED_HOURS
            : 48;

        $mmi_stale_sources = [];
        foreach ( $all_sources as $mmi_src ) {
            // A source is "enabled" the moment it's validated — no separate
            // manual step (see AGENTS.md's "Enabled Toggle Eliminated" entry).
            if ( empty( $mmi_src['last_fetch_at'] ) || ( $mmi_src['config_status'] ?? '' ) !== 'validated' ) {
                continue;
            }
            // Upload sources are excluded from age-based staleness for the same
            // reason MMI_Pipeline_Config_Validator skips them: the file is static
            // by design and only changes when the user uploads a new one, so its
            // age is not a defect. Keeping the two in step matters — this banner
            // exists precisely because they previously disagreed.
            if ( ( $mmi_src['source_type'] ?? 'api' ) === 'upload' ) {
                continue;
            }
            $mmi_age_h = ( current_time( 'timestamp' ) - strtotime( $mmi_src['last_fetch_at'] ) ) / HOUR_IN_SECONDS;
            if ( $mmi_age_h > $mmi_stale_after_hours || ( $mmi_src['last_fetch_status'] ?? '' ) === 'stale' ) {
                $mmi_stale_sources[] = [
                    'name'  => $mmi_src['supplier_name'] ?? $mmi_src['supplier_id'],
                    'hours' => (int) round( $mmi_age_h ),
                ];
            }
        }
        ?>
        <?php if ( ! empty( $mmi_stale_sources ) ) : ?>
            <div class="notice notice-warning mmi-stale-sources-notice">
                <p>
                    <strong>⚠ Stale source data.</strong>
                    <?php
                    $mmi_parts = array_map(
                        static fn( $s ) => sprintf( '%s (%dh old)', $s['name'], $s['hours'] ),
                        $mmi_stale_sources
                    );
                    printf(
                        esc_html( _n(
                            '%s has not been refreshed recently. Running an import now would use out-of-date data — run a Data Fetch first.',
                            '%s have not been refreshed recently. Running an import now would use out-of-date data — run a Data Fetch first.',
                            count( $mmi_stale_sources ),
                            'mmi-data-pipeline'
                        ) ),
                        '<strong>' . esc_html( implode( ', ', $mmi_parts ) ) . '</strong>'
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <?php
        // Fetch warnings from the last 24 hours: what the daily digest's
        // "Completed with Warnings" badge on Data Fetch refers to. A source's
        // last_fetch_status is overwritten by its next run, so the stale
        // notice above alone showed nothing by the time the digest arrived.
        $mmi_fetch_warnings = method_exists( 'MMI_Pipeline_Cron', 'get_recent_fetch_warnings' )
            ? MMI_Pipeline_Cron::get_recent_fetch_warnings()
            : [];
        $mmi_source_names = [];
        foreach ( $all_sources as $mmi_src ) {
            $mmi_source_names[ $mmi_src['supplier_id'] ] = $mmi_src['supplier_name'] ?? $mmi_src['supplier_id'];
        }
        ?>
        <?php if ( ! empty( $mmi_fetch_warnings ) ) : ?>
            <div class="notice notice-warning mmi-fetch-warnings-notice">
                <p><strong>⚠ Data Fetch warnings in the last 24 hours.</strong>
                    <?php esc_html_e( 'These are the warnings behind "Completed with Warnings" in the daily digest.', 'mmi-data-pipeline' ); ?></p>
                <ul class="ul-disc">
                    <?php foreach ( $mmi_fetch_warnings as $mmi_sid => $mmi_reasons ) : ?>
                        <?php foreach ( $mmi_reasons as $mmi_reason => $mmi_w ) : ?>
                            <li>
                                <strong><?php echo esc_html( $mmi_source_names[ $mmi_sid ] ?? $mmi_sid ); ?></strong>:
                                <?php
                                echo esc_html( sprintf(
                                    /* translators: 1: warning reason, 2: number of runs, 3: time of the latest one */
                                    _n( '%1$s (%2$d run, at %3$s)', '%1$s (%2$d runs, latest at %3$s)', $mmi_w['count'], 'mmi-data-pipeline' ),
                                    $mmi_reason,
                                    $mmi_w['count'],
                                    wp_date( 'M j, g:i a', $mmi_w['last'] )
                                ) );
                                ?>
                            </li>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php /* Toolbar + table share one bordered white card, matching
             .mmi-profile-section's exact treatment (see import-settings.css)
             for visual uniformity between the two "cards" areas at the top
             of the Import tab — .mmi-process-actions is the card's top strip
             (border-bottom divider only, like .mmi-profile-section-bar),
             not its own separately-bordered floating box. The Taxonomy
             Mapping panel below is deliberately kept OUTSIDE this card
             (a sibling, not nested inside it) even though its toggle button
             lives in the toolbar above — the card uses overflow:hidden to
             clip square corners to its own border-radius, which would also
             clip the taxonomy table's autocomplete dropdown, resize-handle
             drag, and expandable Alias Rules/Manage Variations panels if
             any of that lived inside it. */ ?>
        <div class="mmi-flat-card">
            <div class="mmi-supplier-toolbar">
                <?php /* Buttons styled identically to .mmi-profile-section-bar's
                     .mmi-btn-profile family (2026-08-31), for visual
                     uniformity between the two toolbars — see
                     import-settings.css. "Configure"/"Preview Data"/"Delete"
                     used to be per-row buttons inside #mmi-supplier-table;
                     they're here now instead, acting on whichever row is
                     currently selected (.is-active), mirroring exactly how
                     Edit/Duplicate/Delete in .mmi-profile-section-bar act on
                     whichever profile card is active — see
                     import-pipeline-sources.js's activateSupplierRow() and
                     the click handlers below it. */ ?>
                <div class="mmi-process-actions">
                    <button type="button" class="mmi-btn-profile mmi-btn-profile--primary" id="mmi-add-data-source-btn">
                        <span class="dashicons dashicons-plus-alt2"></span> Add Data Source
                    </button>
                    <span class="mmi-psb-divider" aria-hidden="true"></span>
                    <?php /* Configure/Preview Data/Delete act on whichever row is
                         selected in the table below — table selection is now
                         single-select only, click-to-select, no checkboxes
                         (see the .mmi-supplier-row click handler in
                         import-pipeline.js), so these are always either
                         "acting on exactly one real source" or "disabled
                         because there are none yet." There is no multi-select
                         state for these to disagree with anymore. */ ?>
                    <button type="button" class="mmi-btn-profile" id="mmi-configure-source-btn" disabled title="Configure selected source">
                        <span class="dashicons dashicons-admin-generic"></span> Configure
                    </button>
                    <button type="button" class="mmi-btn-profile" id="mmi-preview-source-btn" disabled title="Preview sample records from selected source">
                        <span class="dashicons dashicons-visibility"></span> Preview Data
                    </button>
                    <button type="button" class="mmi-btn-profile mmi-btn-profile--danger" id="mmi-delete-source-btn" disabled title="Delete selected source">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                    <span class="mmi-psb-divider" aria-hidden="true"></span>
                    <?php /* Fetch is the one genuinely multi-source action here
                         (2026-08-31) — the table's own select-all/per-row
                         checkboxes were removed (they were a second,
                         un-unified selection concept competing with row
                         click-to-select) and folded into this button's own
                         picker panel instead, so batch selection lives only
                         where it's actually used. See
                         bindFetchSourcesToggle() in product-import.js. */ ?>
                    <button type="button" class="mmi-btn-profile" id="mmi-fetch-sources-toggle">
                        <span class="dashicons dashicons-download"></span> Fetch Data
                    </button>
                    <button type="button" class="mmi-btn-profile" id="view-logs-all">
                        <span class="dashicons dashicons-media-text"></span> View Logs
                    </button>
                    <?php /* Taxonomy Mapping lives here, not in Review & Compare
                         (moved 2026-08-31) — its mapping table is keyed by
                         data source (supplier/source_field/wc_taxonomy, see
                         MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_source_fields()),
                         the exact same ownership this whole table already
                         represents, not by import profile the way Review &
                         Compare's own content is. Same button+panel mechanic as
                         before (taxonomy-mapping.js's bindPanelToggle(),
                         slideToggle() + 'is-active'), just relocated.
                         Deliberately NOT gated by which supplier row is
                         selected — unlike Configure/Preview Data/Delete, this
                         opens a global, all-sources panel (see
                         section-taxonomy-mapping.php), not an action on one
                         record, so tying it to row selection would be
                         incorrect, not just inconsistent. */ ?>
                    <button type="button" class="mmi-btn-profile mmi-taxonomy-toggle-btn" id="mmi-taxonomy-mapping-toggle"
                            title="Map supplier brand/category values to WooCommerce terms — applied automatically during every import">
                        <span class="dashicons dashicons-networking"></span> Taxonomy Mapping
                    </button>
                </div>

                <?php /* Fetch Data picker panel — collapsed by default, toggled
                     by #mmi-fetch-sources-toggle above. Replaces the old
                     select-all-suppliers table column + per-row checkboxes:
                     batch source selection for fetching now lives entirely
                     inside this element instead of being a second selection
                     concept spread across the table. */ ?>
                <div class="mmi-fetch-sources-panel" id="mmi-fetch-sources-panel">
                    <label class="mmi-fetch-source-option mmi-fetch-source-all">
                        <input type="checkbox" id="mmi-fetch-select-all">
                        <strong>All Sources</strong>
                    </label>
                    <div class="mmi-fetch-source-list">
                        <?php foreach ($all_sources as $mmi_fs_src):
                            $mmi_fs_cfg = $mmi_fs_src['config_status'] ?? 'unconfigured';
                        ?>
                            <label class="mmi-fetch-source-option">
                                <input type="checkbox" class="mmi-fetch-source-checkbox"
                                       value="<?php echo esc_attr($mmi_fs_src['supplier_id']); ?>"
                                       data-config-status="<?php echo esc_attr($mmi_fs_cfg); ?>"
                                       data-enabled="<?php echo $mmi_fs_cfg === 'validated' ? '1' : '0'; ?>"
                                       data-source-type="<?php echo esc_attr($mmi_fs_src['source_type'] ?? 'api'); ?>">
                                <?php echo esc_html($mmi_fs_src['supplier_name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="mmi-fetch-sources-actions">
                        <button type="button" class="mmi-btn-profile mmi-btn-profile--primary" id="run-selected-fetch" disabled>
                            <span class="dashicons dashicons-download"></span> Fetch Selected
                        </button>
                    </div>
                </div>
            </div>

            <!-- Supplier Table -->
            <div class="mmi-table-wrapper">
            <table class="mmi-supplier-unified-table mmi-supplier-fetch-table mmi-uniform-table mmi-uniform-table--hoverable" id="mmi-supplier-table">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th title="Whether this source's brand/category values are resolved through Taxonomy Mapping's alias table">Taxonomy Mapping</th>
                        <th>Last Fetch</th>
                        <th>Records</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($all_sources)): ?>
                    <tr class="mmi-empty-row">
                        <td colspan="6">
                            <div class="mmi-empty-state">
                                <span class="dashicons dashicons-database"></span>
                                <p>No data sources yet. Click <strong>+ Add Data Source</strong> to get started.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                <?php
                // Per-source "has a mapping in place" counts for the
                // mmi-taxmap-toggle-cell quick-assign indicator below — one
                // batched, unfiltered query grouped in memory by supplier_id,
                // not a query per row (would be a real N+1 against a table
                // that can hold hundreds of rows).
                $mmi_src_taxmap_counts = [];
                if ( class_exists( 'MMI_DB' ) ) {
                    foreach ( MMI_DB::get_tax_mappings( '', '' ) as $mmi_stc_row ) {
                        if ( (int) ( $mmi_stc_row['wc_term_id'] ?? 0 ) === 0 ) {
                            continue; // Unmapped row — nothing to count yet.
                        }
                        $mmi_stc_sid = (string) ( $mmi_stc_row['supplier_id'] ?? '' );
                        if ( $mmi_stc_sid === '' ) {
                            continue; // Global-across-suppliers row — not attributable to one source row.
                        }
                        $mmi_src_taxmap_counts[ $mmi_stc_sid ] = ( $mmi_src_taxmap_counts[ $mmi_stc_sid ] ?? 0 ) + 1;
                    }
                }
                ?>
                <?php foreach ($all_sources as $loop_index => $src):
                    $sid         = $src['supplier_id'];
                    $name        = $src['supplier_name'];
                    $src_type    = $src['source_type'] ?? 'api';

                    // Determine if this source is assigned to the active profile
                    $active_profile_sources = isset( $profiles, $current_profile )
                        ? ( $profiles[ $current_profile ]['sources'] ?? [] )
                        : [];
                    $in_active_profile = in_array( $sid, $active_profile_sources, true );
                    $row_context_class = $in_active_profile ? 'mmi-source-in-profile' : '';
                    $type_label  = $source_type_labels[$src_type] ?? ucfirst($src_type);
                    $cfg_status  = $src['config_status'] ?? 'unconfigured';
                    $fetch_proc  = $processes[$sid . '_fetch'] ?? [];
                    // A source is "enabled" the moment it's validated — no
                    // separate manual step (see AGENTS.md's "Enabled Toggle
                    // Eliminated" entry).
                    $is_enabled  = ($cfg_status === 'validated');

                    // Per-source Taxonomy Mapping toggle (2026-08-31) — see
                    // MMI_Pipeline_Admin::get_configured_suppliers()'s
                    // matching key. Read directly off this row's own
                    // already-decoded $src['configuration'] rather than
                    // calling get_configured_suppliers() again per row —
                    // that method re-derives the exact same thing from a
                    // fresh query this file has already run once above.
                    $taxonomy_mapping_enabled = ! array_key_exists( 'taxonomy_mapping_enabled', $src['configuration'] ?? [] )
                        || (bool) $src['configuration']['taxonomy_mapping_enabled'];

                    // Determine row status badge — use the same $status_badge_map as the cards
                    $row_badge = $is_enabled
                        ? ['class' => 'success', 'label' => 'Active']
                        : ($status_badge_map[$cfg_status] ?? ['class' => '', 'label' => 'Needs Setup']);
                    $status_badge_class = $row_badge['class'];
                    $status_badge_label = $row_badge['label'];

                    // Last fetch info.
                    // $last_fetch_at now reflects when the feed file on disk was
                    // actually written (see MMI_Pipeline_Cron::refresh_all_source_counts()),
                    // so this doubles as the data's real age — flag it when the
                    // data is old enough that an import would run on stale input.
                    $last_fetch_display = '—';
                    $stale_hours        = null;
                    $is_stale_source    = false;
                    if (!empty($src['last_fetch_at'])) {
                        $stale_hours = ( current_time('timestamp') - strtotime($src['last_fetch_at']) ) / HOUR_IN_SECONDS;
                        // Threshold and upload-exclusion both come from the banner
                        // guard above, which defers to the validator's own constant.
                        $is_stale_source = $src_type !== 'upload'
                            && ( $stale_hours > $mmi_stale_after_hours || ( $src['last_fetch_status'] ?? '' ) === 'stale' );
                    }
                    if (!empty($src['last_fetch_at'])) {
                        $ago = human_time_diff(strtotime($src['last_fetch_at']), current_time('timestamp'));
                        // Record count used to be appended here too ("· N
                        // products") — now shown once, in its own Records
                        // column, rather than duplicated in this cell as well.
                        if ($src_type === 'upload') {
                            // 'upload' sources have no live remote endpoint that gets
                            // periodically "fetched" — last_fetch_at here only records
                            // when the uploaded file was last (re)parsed into the JSON
                            // cache (at creation, or via the "Refresh" action), so label
                            // it as an upload timestamp instead of implying an API fetch.
                            $last_fetch_display = 'Uploaded ' . $ago . ' ago';
                        } else {
                            $dur = !empty($src['last_fetch_duration']) ? ' · ' . $src['last_fetch_duration'] . 's' : '';
                            $last_fetch_display = $ago . ' ago' . $dur;
                        }
                    } elseif (!empty($fetch_proc['status']['last_run'])) {
                        $last_fetch_display = $fetch_proc['status']['last_run'];
                    }

                ?>
                    <tr class="mmi-supplier-row mmi-uniform-row--selectable<?php echo $row_context_class !== '' ? ' ' . $row_context_class : ''; ?><?php echo $loop_index === 0 ? ' is-active' : ''; ?>"
                        data-supplier="<?php echo esc_attr($sid); ?>"
                        data-supplier-name="<?php echo esc_attr($name); ?>"
                        data-config-status="<?php echo esc_attr($cfg_status); ?>"
                        data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>"
                        data-source-type="<?php echo esc_attr($src_type); ?>"
                        role="button" tabindex="0"
                        aria-pressed="<?php echo $loop_index === 0 ? 'true' : 'false'; ?>">
                        <td class="mmi-supplier-name-cell">
                            <strong><?php echo esc_html($name); ?></strong>
                            <?php
                            // Xchange-only Web Assets toggle — moved here from a
                            // standalone banner above the stats grid (2026-08-31),
                            // since it's a per-source setting, not a page-wide one.
                            // Previously also gated behind a 'vip' feature tier —
                            // removed along with the tier/feature-license system;
                            // shown to any site with Xchange configured.
                            if ( $sid === 'xchange' && class_exists('MMI_DB') ) :
                                $web_assets_enabled = (bool) MMI_DB::get_setting('mmi_xchange_web_assets_enabled', false);
                            ?>
                                <div class="mmi-source-sub-toggle">
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mmi-web-assets-form">
                                        <input type="hidden" name="action" value="xchange_toggle_web_assets">
                                        <?php wp_nonce_field('mmi_xchange_toggle_web_assets'); ?>
                                        <label class="mmi-toggle-switch">
                                            <input type="checkbox" name="enabled" value="1"
                                                <?php checked($web_assets_enabled); ?>
                                                onchange="this.form.submit()">
                                            <span class="mmi-toggle-slider"></span>
                                        </label>
                                        <span class="mmi-source-sub-toggle-label">Web Assets</span>
                                        <span class="dashicons dashicons-info-outline mmi-info-icon" tabindex="0"
                                              title="(richer images &amp; descriptions) Pulls per-SKU images and long-form descriptions from Xchange's Web Asset API on each scheduled fetch and merges them into the product feed. Off by default — most sites don't need this."></span>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="mmi-source-type-badge mmi-source-<?php echo esc_attr($src_type); ?>">
                                <?php echo esc_html($type_label); ?>
                            </span>
                        </td>
                        <td>
                            <span class="mmi-badge mmi-status-badge <?php echo esc_attr($status_badge_class); ?>"><?php echo esc_html($status_badge_label); ?></span>
                        </td>
                        <td class="mmi-taxmap-toggle-cell">
                            <label class="mmi-toggle-switch" title="Resolve this source's brand/category values through Taxonomy Mapping's alias table">
                                <input type="checkbox" class="mmi-source-taxmap-toggle" data-supplier="<?php echo esc_attr($sid); ?>"
                                    <?php checked($taxonomy_mapping_enabled); ?>>
                                <span class="mmi-toggle-slider"></span>
                            </label>
                            <?php
                            // "Does this source even have a mapping in place" +
                            // an easy way to assign/view one — the user asked
                            // for both directly, since a source can currently
                            // be validated and importing with zero taxonomy
                            // aliases ever set for it, invisible anywhere else
                            // in this table. Real count, not a placeholder —
                            // see $mmi_src_taxmap_counts above.
                            $mmi_src_mapped_count = $mmi_src_taxmap_counts[ $sid ] ?? 0;
                            // Declared taxonomy fields (Configure > Taxonomies; see
                            // MMI_Pipeline_Admin::resolve_source_taxonomy_fields()) —
                            // read off this row's decoded configuration for the same
                            // no-second-query reason as $taxonomy_mapping_enabled above.
                            $mmi_src_tax_fields = class_exists( 'MMI_Pipeline_Admin' )
                                ? MMI_Pipeline_Admin::resolve_source_taxonomy_fields( (string) $sid, (array) ( $src['configuration'] ?? [] ) )
                                : [];
                            $mmi_src_tax_desc = [];
                            foreach ( $mmi_src_tax_fields as $mmi_stf ) {
                                $mmi_stf_tax = get_taxonomy( $mmi_stf['wc_taxonomy'] );
                                $mmi_src_tax_desc[] = $mmi_stf['source_field'] . ' → ' . ( $mmi_stf_tax ? $mmi_stf_tax->label : $mmi_stf['wc_taxonomy'] );
                            }
                            $mmi_src_tax_title = $mmi_src_tax_desc
                                ? ' Fields: ' . implode( ', ', $mmi_src_tax_desc ) . '.'
                                : ' No taxonomy field declared for this source yet (Configure › Taxonomies).';
                            ?>
                            <button type="button" class="mmi-taxmap-quick-link<?php echo $mmi_src_mapped_count === 0 ? ' is-unmapped' : ''; ?>"
                                    data-taxmap-open-supplier="<?php echo esc_attr($sid); ?>"
                                    data-taxmap-supplier-name="<?php echo esc_attr($name); ?>"
                                    title="<?php echo esc_attr( ( $mmi_src_mapped_count > 0 ? $mmi_src_mapped_count . ' value(s) mapped for this source — view or add more.' : 'No taxonomy mapping set up for this source yet — click to assign one.' ) . $mmi_src_tax_title ); ?>">
                                <?php if ( $mmi_src_mapped_count > 0 ) : ?>
                                    <span class="dashicons dashicons-yes-alt"></span> <?php echo (int) $mmi_src_mapped_count; ?> mapped
                                <?php else : ?>
                                    <span class="dashicons dashicons-warning"></span> Not mapped
                                <?php endif; ?>
                            </button>
                        </td>
                        <td class="mmi-last-fetch-cell<?php echo $is_stale_source ? ' mmi-fetch-stale' : ''; ?>">
                            <?php echo esc_html($last_fetch_display); ?>
                            <?php if ($is_stale_source) : ?>
                                <span class="mmi-stale-badge mmi-badge warning"
                                      title="This source's data has not been refreshed in <?php echo esc_attr(round($stale_hours)); ?> hours. Run a Data Fetch before importing — an import now would run against stale data.">
                                    ⚠ Stale
                                </span>
                            <?php endif; ?>
                        </td>
                        <?php
                        // A data source's own records may be anything — WooCommerce
                        // products, plain posts, taxonomy terms, orders — not always
                        // "products," so this column (and its label) stay generic
                        // rather than assuming what one supplier's rows represent.
                        $mmi_src_record_count = $feed_counts[$sid] ?? 0;
                        ?>
                        <td class="mmi-record-count-cell">
                            <?php if ($mmi_src_record_count > 0) : ?>
                                <?php echo esc_html(number_format($mmi_src_record_count)); ?>
                            <?php else : ?>
                                <span class="status-unavailable">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div><!-- /.mmi-table-wrapper -->
        </div><!-- /.mmi-flat-card -->

        <div class="mmi-taxonomy-mapping-panel" id="mmi-taxonomy-mapping-section" data-section="taxonomy">
            <?php include __DIR__ . '/section-taxonomy-mapping.php'; ?>
        </div>

    </div><!-- /.mmi-supplier-section -->

    </div><!-- /.mmi-section-content -->

<?php /* ═══════════════════════════════════════════════════════════════════════════
     CONFIGURATION MODAL (single instance, populated dynamically via JS)
     ═══════════════════════════════════════════════════════════════════════════ */ ?>
<div id="mmi-config-modal" class="mmi-config-modal mmi-modal-backdrop" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-config-modal-title">
    <div class="mmi-config-modal-dialog">
        <div class="mmi-config-modal-header">
            <h3 id="mmi-config-modal-title">Configure Data Source</h3>
            <button type="button" class="mmi-config-modal-close" data-close title="Close">&times;</button>
        </div>

        <!-- Tab Navigation -->
        <div class="mmi-config-tabs">
            <button type="button" class="mmi-config-tab active" data-tab="connection">Connection</button>
            <button type="button" class="mmi-config-tab mmi-tab-http-only" data-tab="auth">Authentication</button>
            <button type="button" class="mmi-config-tab mmi-tab-http-only" data-tab="endpoints">Endpoints</button>
            <button type="button" class="mmi-config-tab" data-tab="parsing">Data Parsing</button>
            <button type="button" class="mmi-config-tab" data-tab="taxonomies">Taxonomies</button>
            <button type="button" class="mmi-config-tab" data-tab="advanced">Advanced</button>
        </div>

        <div class="mmi-config-modal-body">
            <input type="hidden" id="mmi-config-supplier-id" value="">

            <!-- ─── Connection Tab ─────────────────────────────────────── -->
            <div class="mmi-config-tab-content active" data-tab="connection">
                <div class="mmi-config-field">
                    <label for="cfg-supplier-name">Supplier Name</label>
                    <input type="text" id="cfg-supplier-name" placeholder="e.g. Xchange">
                </div>
                <div class="mmi-config-field">
                    <label for="cfg-source-type">Source Type</label>
                    <select id="cfg-source-type">
                        <option value="api">API</option>
                        <option value="url">Direct URL</option>
                        <option value="upload">File Upload</option>
                        <option value="dropbox">Dropbox</option>
                        <option value="gdrive">Google Drive</option>
                    </select>
                    <span id="cfg-source-type-locked" class="mmi-field-locked mmi-is-hidden"></span>
                    <small class="mmi-source-type-lock-note mmi-is-hidden"><span class="dashicons dashicons-lock"></span> Source type is locked after creation. Add a new data source to use a different type.</small>
                </div>
                <div class="mmi-config-field mmi-field-http-only">
                    <label for="cfg-base-url">Base URL</label>
                    <input type="url" id="cfg-base-url" placeholder="https://api.supplier.com/v1">
                </div>
                <div class="mmi-config-field mmi-field-http-only">
                    <label for="cfg-documentation-url">Documentation URL <small>(optional)</small></label>
                    <input type="url" id="cfg-documentation-url" placeholder="https://docs.supplier.com/api">
                </div>

                <!-- ─── File Upload Panel ──────────────────────────── -->
                <div class="mmi-source-panel mmi-is-hidden" data-source-panel="upload">
                    <div class="mmi-config-field">
                        <label>Uploaded File</label>
                        <div class="mmi-upload-field-wrap">
                            <input type="hidden" id="cfg-upload-attachment-id">
                            <input type="text" id="cfg-upload-filename" readonly placeholder="No file selected" class="mmi-upload-filename-display">
                            <button type="button" class="button" id="mmi-upload-choose-btn">Choose File</button>
                            <button type="button" class="button mmi-btn-danger mmi-is-hidden" id="mmi-upload-clear-btn">Remove</button>
                        </div>
                        <p class="description">Supported formats: CSV, TSV, JSON, XML, XLSX. The file is stored in the WordPress media library and fetched from there during import.</p>
                    </div>
                </div>

                <!-- ─── Dropbox Panel ─────────────────────────────── -->
                <div class="mmi-source-panel mmi-is-hidden" data-source-panel="dropbox">
                    <div class="mmi-config-field">
                        <label for="cfg-dropbox-file-path">Dropbox File Path</label>
                        <input type="text" id="cfg-dropbox-file-path" placeholder="/path/to/products.csv">
                        <p class="description">Full path to the file in your Dropbox, starting with <code>/</code>.</p>
                    </div>
                    <div class="mmi-config-field">
                        <label for="cfg-dropbox-access-token">Access Token</label>
                        <input type="password" id="cfg-dropbox-access-token" placeholder="sl.XXXXXXX..." autocomplete="new-password">
                        <p class="description">Long-lived Dropbox access token. Stored securely in the credential vault.</p>
                    </div>
                </div>

                <!-- ─── Google Drive Panel ────────────────────────── -->
                <div class="mmi-source-panel mmi-is-hidden" data-source-panel="gdrive">
                    <div class="mmi-config-field">
                        <label for="cfg-gdrive-file-id">Google Drive File ID</label>
                        <input type="text" id="cfg-gdrive-file-id" placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms">
                        <p class="description">The file ID from the Google Drive share link. From <code>https://drive.google.com/file/d/<strong>{FILE_ID}</strong>/view</code>. The file must be shared as "Anyone with the link".</p>
                    </div>
                </div>

                <div class="mmi-config-field">
                    <label for="cfg-notes">Notes <small>(optional)</small></label>
                    <textarea id="cfg-notes" rows="3" placeholder="Internal notes about this data source..."></textarea>
                </div>
            </div>

            <!-- ─── Authentication Tab ─────────────────────────────────── -->
            <div class="mmi-config-tab-content" data-tab="auth">
                <div class="mmi-config-field">
                    <label for="cfg-auth-type">Authentication Type</label>
                    <select id="cfg-auth-type">
                        <option value="none">None (Public)</option>
                        <option value="basic">Basic Auth</option>
                        <option value="bearer">Bearer Token</option>
                        <option value="timed_token">Timed Token (Xchange-style)</option>
                        <option value="api_key_header">API Key (Header)</option>
                        <option value="api_key_query">API Key (Query Param)</option>
                        <option value="oauth2_client_credentials">OAuth2 Client Credentials</option>
                        <option value="oauth2_auth_code">OAuth2 Authorization Code</option>
                        <option value="hmac">HMAC Signature</option>
                        <option value="custom_headers">Custom Headers</option>
                    </select>
                </div>
                <div id="mmi-auth-fields-container">
                    <p class="mmi-auth-info"><span class="dashicons dashicons-info"></span> No authentication required for this data source.</p>
                </div>
                <p class="mmi-credential-info"><span class="dashicons dashicons-lock"></span> Credentials are stored securely in the centralized credential vault (<code>wp_mmi</code>). Only key references are saved with this configuration.</p>
            </div>

            <!-- ─── Endpoints Tab ──────────────────────────────────────── -->
            <div class="mmi-config-tab-content" data-tab="endpoints">
                <p class="mmi-config-description">Define one or more API endpoints for this supplier. The primary endpoint is used for testing and fetching.</p>
                <div id="mmi-endpoints-list">
                    <!-- Endpoint rows injected by JS -->
                </div>
                <button type="button" class="button mmi-action-btn" id="mmi-add-endpoint-btn">
                    <span class="dashicons dashicons-plus-alt2"></span> Add Endpoint
                </button>
            </div>

            <!-- ─── Data Parsing Tab ───────────────────────────────────── -->
            <div class="mmi-config-tab-content" data-tab="parsing">
                <div class="mmi-config-field">
                    <label for="cfg-response-format">Response / File Format</label>
                    <select id="cfg-response-format">
                        <optgroup label="Text / API Formats">
                            <option value="json">JSON</option>
                            <option value="csv">CSV (Comma-separated)</option>
                            <option value="tsv">TSV (Tab-separated)</option>
                            <option value="xml">XML</option>
                            <option value="txt">Plain Text (.txt)</option>
                        </optgroup>
                        <optgroup label="Spreadsheet Formats">
                            <option value="excel">Excel (.xlsx / .xls)</option>
                            <option value="numbers">Apple Numbers (.numbers)</option>
                        </optgroup>
                    </select>
                    <small class="mmi-field-description mmi-is-hidden" id="cfg-format-note-excel">
                        <span class="dashicons dashicons-info"></span> Excel import uses the first worksheet by default. Specify a sheet name in the <em>Sheet Name</em> field below if needed.
                    </small>
                    <small class="mmi-field-description mmi-is-hidden" id="cfg-format-note-numbers">
                        <span class="dashicons dashicons-info"></span> Numbers files must be exported as CSV or XLSX before they can be fetched automatically. Use <em>File Upload</em> or <em>Direct URL</em> as the source type for static exports.
                    </small>
                </div>
                <div class="mmi-config-field">
                    <label for="cfg-data-root-path">Data Root Path <small>(optional)</small></label>
                    <input type="text" id="cfg-data-root-path" placeholder="e.g. data.products or products">
                    <small class="mmi-field-description">Dot-notation path to the array of items within the response (e.g. <code>products</code> or <code>data.items</code>).</small>
                </div>
                <div class="mmi-config-field">
                    <?php /* Reviews whichever field is currently saved as this
                         source's primary key (Data Sources wizard sets it) —
                         see MMI_Pipeline_Config_Validator::scan_primary_key_quality()
                         and assets/js/pk-quality-modal.js. Reads
                         #mmi-config-supplier-id at click time; no data-supplier
                         needed here since this markup is shared across every
                         source the Configure modal ever opens for. */ ?>
                    <?php /* Shares .mmi-pk-quality-trigger's single delegated click
                         handler with the wizard's own trigger button, which
                         resolves supplier/field/panel generically per-context
                         (see pk-quality-modal.js) — no data-supplier needed
                         here since this markup is shared across every source
                         the Configure modal ever opens for; the handler reads
                         #mmi-config-supplier-id instead. The alert badge is
                         populated whenever the Configure modal opens (see
                         checkConfigModalPkQualityBadge() in pk-quality-modal.js). */ ?>
                    <button type="button" class="button button-primary mmi-action-btn mmi-pk-quality-trigger" id="cfg-check-pk-quality-btn">
                        <span class="dashicons dashicons-search"></span> Check Primary Key Data Quality
                        <span class="mmi-pk-quality-alert-badge mmi-badge error mmi-is-hidden"></span>
                    </button>
                    <small class="mmi-field-description">Scans the cached feed for blank or duplicate values in this source's configured primary key field.</small>
                    <?php include __DIR__ . '/panel-pk-quality.php'; ?>
                </div>

                <div class="mmi-parsing-csv-options mmi-is-hidden">
                    <div class="mmi-config-field">
                        <label for="cfg-delimiter">Delimiter</label>
                        <select id="cfg-delimiter">
                            <option value=",">, (Comma)</option>
                            <option value=";">; (Semicolon)</option>
                            <option value="|">| (Pipe)</option>
                        </select>
                    </div>
                    <div class="mmi-config-field">
                        <label for="cfg-enclosure">Text Enclosure</label>
                        <select id="cfg-enclosure">
                            <option value="&quot;">" (Double Quote)</option>
                            <option value="'">' (Single Quote)</option>
                        </select>
                    </div>
                    <div class="mmi-config-field">
                        <label>
                            <input type="checkbox" id="cfg-has-header" checked> First row is header
                        </label>
                    </div>
                    <div class="mmi-config-field">
                        <label for="cfg-encoding">File Encoding</label>
                        <select id="cfg-encoding">
                            <option value="UTF-8">UTF-8</option>
                            <option value="ISO-8859-1">ISO-8859-1 (Latin-1)</option>
                            <option value="Windows-1252">Windows-1252</option>
                        </select>
                    </div>
                    <div class="mmi-config-field">
                        <label for="cfg-skip-rows">Skip Rows</label>
                        <input type="number" id="cfg-skip-rows" value="0" min="0" max="100">
                        <small class="mmi-field-description">Number of rows to skip before reading data.</small>
                    </div>
                </div>

                <!-- Spreadsheet-specific options (Excel / Numbers) -->
                <div class="mmi-parsing-excel-options mmi-is-hidden">
                    <div class="mmi-config-field">
                        <label for="cfg-sheet-name">Sheet Name <small>(optional)</small></label>
                        <input type="text" id="cfg-sheet-name" placeholder="e.g. Products (leave blank to use first sheet)">
                        <small class="mmi-field-description">The exact worksheet name to import. Leave blank to use the first sheet in the workbook.</small>
                    </div>
                    <div class="mmi-config-field">
                        <label>
                            <input type="checkbox" id="cfg-excel-has-header" checked> First row is header
                        </label>
                    </div>
                    <div class="mmi-config-field">
                        <label for="cfg-excel-skip-rows">Skip Rows</label>
                        <input type="number" id="cfg-excel-skip-rows" value="0" min="0" max="100">
                        <small class="mmi-field-description">Number of rows to skip at the top before reading data (after any header row).</small>
                    </div>
                </div>
            </div>

            <!-- ─── Advanced Tab ───────────────────────────────────────── -->
            <?php /* ─── Taxonomies Tab (2026-10-07) ─────────────────────────
                 Which raw feed field holds this source's brand, category,
                 etc. — stored as configuration.taxonomy_fields, seeded from
                 the source's template (mmi_ds_complete_template_setup()).
                 This one declaration is what Field Mapping's "Enable for X"
                 toggles on product_brand/product_cat, Taxonomy Mapping's
                 source list and this table's mapped count all read, so a
                 source declared here takes part in Taxonomy Mapping exactly
                 like the built-in integrations do. Rows are built by
                 import-pipeline-config.js (renderTaxonomyFieldRows()) from
                 mmiImportSettings.productTaxonomies; the "Suggest from feed"
                 button calls mmi_discover_taxonomy_candidates, the
                 detection endpoint built 2026-08-30 and wired here. Shown
                 for every source type, template sources included (their
                 other tabs are hidden). */ ?>
            <div class="mmi-config-tab-content" data-tab="taxonomies">
                <div class="mmi-config-field">
                    <label>Taxonomy Fields <small>(which feed field holds the brand, category, …)</small></label>
                    <small class="mmi-field-description">
                        One source field per WooCommerce taxonomy. Use <code>field1+field2</code> for a compound value
                        (e.g. <code>master_category+sub_category</code>). Import profiles turn resolution on per source
                        in Field Mapping ("Enable for …"), and Taxonomy Mapping lists these fields for alias setup.
                        Leave the list empty if this feed carries no brand or category data.
                    </small>
                    <div id="mmi-taxonomy-fields-list" class="mmi-kv-pairs mmi-taxonomy-fields-list">
                        <!-- rows injected by JS -->
                    </div>
                    <div class="mmi-taxonomy-fields-actions">
                        <button type="button" class="button mmi-action-btn" id="mmi-add-taxonomy-field-btn">
                            <span class="dashicons dashicons-plus"></span> Add Field
                        </button>
                        <button type="button" class="button mmi-action-btn" id="mmi-suggest-taxonomy-fields-btn" title="Samples this source's fetched feed for low-cardinality text fields that look like a brand or category">
                            <span class="dashicons dashicons-search"></span> Suggest from feed
                        </button>
                        <button type="button" class="button mmi-action-btn mmi-is-hidden" id="mmi-reset-taxonomy-fields-btn" title="Restore the fields this integration's template declares">
                            <span class="dashicons dashicons-image-rotate"></span> Reset to template
                        </button>
                    </div>
                    <div id="mmi-taxonomy-field-suggestions" class="mmi-taxonomy-field-suggestions mmi-is-hidden"></div>
                </div>
            </div>

            <div class="mmi-config-tab-content" data-tab="advanced">
                <div class="mmi-config-field">
                    <label for="cfg-timeout">Request Timeout (seconds)</label>
                    <input type="number" id="cfg-timeout" value="60" min="5" max="300">
                </div>
                <div class="mmi-config-field">
                    <label for="cfg-retries">Max Retries</label>
                    <input type="number" id="cfg-retries" value="3" min="0" max="10">
                </div>
                <div class="mmi-config-field">
                    <label for="cfg-cache-duration">Cache Duration (seconds, 0 = no cache)</label>
                    <input type="number" id="cfg-cache-duration" value="0" min="0">
                </div>
                <div class="mmi-config-field">
                    <label>
                        <input type="checkbox" id="cfg-detect-changes" checked> Enable change detection (track data changes between fetches)
                    </label>
                </div>
                <div class="mmi-config-field">
                    <label>Custom HTTP Headers <small>(applied to all requests)</small></label>
                    <div id="mmi-custom-headers-list" class="mmi-kv-pairs">
                        <!-- key-value rows injected by JS -->
                    </div>
                    <button type="button" class="button mmi-add-kv-row-btn mmi-action-btn" data-target="mmi-custom-headers-list">
                        <span class="dashicons dashicons-plus"></span> Add Header
                    </button>
                </div>
            </div>
        </div>
        <?php /* Fetch scheduling for this source lives on the Pipeline tab's
             Schedules panel (one row per source) — not in this modal. See
             MMI_Pipeline_Cron::get_schedulable_sources(). */ ?>

        <!-- Modal Footer -->
        <div class="mmi-config-modal-footer">
            <div class="mmi-config-modal-footer-left">
                <button type="button" class="button mmi-action-btn" id="mmi-test-connection-btn" disabled>
                    <span class="dashicons dashicons-admin-links"></span> Test Connection
                </button>
                <span id="mmi-test-result" class="mmi-test-result"><span class="mmi-test-hint">Test the connection before saving to verify credentials and URL.</span></span>
            </div>
            <div class="mmi-config-modal-footer-right">
                <span id="mmi-autosave-status" class="mmi-autosave-status"></span>
                <button type="button" class="button mmi-action-btn" id="mmi-config-cancel-btn">Close</button>
            </div>
        </div>
    </div>
</div>

<?php /* ═══════════════════════════════════════════════════════════════════════════
     DATA SOURCE PREVIEW MODAL (single instance, populated dynamically via JS)
     Shows real sample records from a source's fetched/cached feed so an admin
     can confirm what data is actually available (and its real field names)
     before wiring up Field Mapping — without leaving this tab.
     ═══════════════════════════════════════════════════════════════════════════ */ ?>
<div id="mmi-source-preview-modal" class="mmi-config-modal mmi-modal-backdrop" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-source-preview-title">
    <div class="mmi-config-modal-dialog mmi-source-preview-modal-dialog">
        <div class="mmi-config-modal-header">
            <h3 id="mmi-source-preview-title">Preview Data Source</h3>
            <button type="button" class="mmi-config-modal-close" data-close title="Close">&times;</button>
        </div>
        <div class="mmi-config-modal-body">
            <div class="mmi-source-preview-toolbar">
                <span class="mmi-source-preview-file-picker mmi-is-hidden">
                    <label for="mmi-source-preview-file-select" class="mmi-source-preview-file-label">File:</label>
                    <select id="mmi-source-preview-file-select" class="mmi-source-preview-file-select"></select>
                </span>
                <span id="mmi-source-preview-meta" class="mmi-source-preview-meta"></span>
                <?php /* Freshness of the file being previewed, read from its own
                     mtime server-side. Sits next to the data rather than in the
                     Sources table so "how old is this?" is answerable without
                     closing the modal and cross-referencing a second screen. */ ?>
                <span id="mmi-source-preview-freshness" class="mmi-source-preview-freshness mmi-is-hidden">
                    <span class="dashicons dashicons-clock"></span>
                    <span id="mmi-source-preview-freshness-text"></span>
                </span>
            </div>
            <?php /* Searches every record in the file (not just the rows shown),
                 entirely in the browser — the whole feed is already loaded to
                 render the preview. Used directly, and pre-filled by Run
                 Insights' "View source record" to show one failed record. */ ?>
            <div class="mmi-source-preview-search mmi-is-hidden" role="search">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <label for="mmi-source-preview-search-input" class="screen-reader-text">Search records</label>
                <input type="search" id="mmi-source-preview-search-input" class="mmi-source-preview-search-input"
                       placeholder="Search every record: SKU, name, any value&hellip;" autocomplete="off">
                <label for="mmi-source-preview-search-field" class="mmi-source-preview-search-field-label">in</label>
                <select id="mmi-source-preview-search-field" class="mmi-source-preview-search-field">
                    <option value="">All fields</option>
                </select>
            </div>
            <div id="mmi-source-preview-loading" class="mmi-source-preview-loading">
                <span class="spinner is-active"></span> Loading sample records&hellip;
            </div>
            <div id="mmi-source-preview-error" class="notice notice-error mmi-is-hidden"><p></p></div>
            <div id="mmi-source-preview-empty" class="mmi-source-preview-empty mmi-is-hidden">
                <span class="dashicons dashicons-info"></span>
                <p>No data has been fetched for this source yet.</p>
            </div>
            <div class="mmi-upload-preview" id="mmi-source-preview-wrap">
                <p class="mmi-upload-preview-label">Preview <span id="mmi-source-preview-count"></span></p>
                <div class="mmi-upload-preview-scroll">
                    <table class="mmi-upload-preview-table">
                        <thead><tr id="mmi-source-preview-head"></tr></thead>
                        <tbody id="mmi-source-preview-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="mmi-config-modal-footer">
            <div class="mmi-config-modal-footer-left">
                <?php /* API sources only (shown by JS): file-based sources have no
                     remote endpoint to re-pull — they're refreshed by replacing
                     the file, so a "Fetch Now" here would be a dead control. */ ?>
                <button type="button" class="button mmi-action-btn mmi-is-hidden" id="mmi-source-preview-fetch-btn">
                    <span class="dashicons dashicons-update"></span> Fetch Now
                </button>
                <span id="mmi-source-preview-fetch-status" class="mmi-source-preview-fetch-status"></span>
            </div>
            <div class="mmi-config-modal-footer-right">
                <?php /* Shown only when opened from Run Insights' "View source record". */ ?>
                <button type="button" class="button mmi-action-btn mmi-is-hidden" id="mmi-source-preview-back-btn">
                    <span class="dashicons dashicons-arrow-left-alt2"></span> <span class="mmi-source-preview-back-label">Back to run</span>
                </button>
                <button type="button" class="button mmi-action-btn" id="mmi-source-preview-close-btn">Close</button>
            </div>
        </div>
    </div>
</div>

<?php /* ═══════════════════════════════════════════════════════════════════════════
     ADD DATA SOURCE DIALOG
     ═══════════════════════════════════════════════════════════════════════════ */ ?>
<div id="mmi-add-source-modal" class="mmi-config-modal mmi-modal-backdrop" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-add-source-modal-title">
    <div class="mmi-config-modal-dialog mmi-config-modal-source-wizard">
        <div class="mmi-config-modal-header">
            <h3 id="mmi-add-source-modal-title">Add New Data Source</h3>
            <button type="button" class="mmi-config-modal-close" data-close title="Close">&times;</button>
        </div>
        <div class="mmi-config-modal-body">

            <!-- ── Source type tile selector ── -->
            <div class="mmi-source-type-tiles" id="add-source-type-tiles">
                <button type="button" class="mmi-source-tile active" data-type="upload">
                    <span class="mmi-tile-icon dashicons dashicons-upload"></span>
                    <span class="mmi-tile-label">Upload File</span>
                </button>
                <button type="button" class="mmi-source-tile" data-type="url">
                    <span class="mmi-tile-icon dashicons dashicons-admin-links"></span>
                    <span class="mmi-tile-label">Direct URL</span>
                </button>
                <button type="button" class="mmi-source-tile" data-type="dropbox">
                    <span class="mmi-tile-icon dashicons dashicons-media-archive"></span>
                    <span class="mmi-tile-label">Dropbox</span>
                </button>
                <button type="button" class="mmi-source-tile" data-type="gdrive">
                    <span class="mmi-tile-icon dashicons dashicons-cloud-saved"></span>
                    <span class="mmi-tile-label">Google Drive</span>
                </button>
                <button type="button" class="mmi-source-tile" data-type="api">
                    <span class="mmi-tile-icon dashicons dashicons-rest-api"></span>
                    <span class="mmi-tile-label">API</span>
                </button>
            </div>

            <!-- Hidden tracking field — updated by JS tile clicks -->
            <input type="hidden" id="add-source-type" value="upload">

            <?php /* ══════════════════════════════════════════════════════
                 UPLOAD PANEL
                 Shows drag-and-drop zone; file is analyzed immediately
                 after selection before the source is created.
            ═══════════════════════════════════════════════════════ */ ?>
            <div class="mmi-add-source-panel" id="add-panel-upload">
                <div class="mmi-upload-drop-zone" id="add-upload-drop-zone" tabindex="0" role="button" aria-label="Click or drag a file to upload">

                    <!-- Idle state: invite drag or browse -->
                    <div id="add-drop-zone-idle">
                        <span class="dashicons dashicons-upload mmi-drop-icon"></span>
                        <p class="mmi-drop-primary">Drag &amp; drop your file here</p>
                        <p class="mmi-drop-secondary">or <button type="button" class="mmi-drop-browse-btn">browse files</button></p>
                        <p class="mmi-drop-formats">CSV &bull; TSV &bull; JSON &bull; XML &bull; XLSX &bull; TXT</p>
                    </div>

                    <!-- Analyzing state: spinner while AJAX runs -->
                    <div id="add-drop-zone-analyzing" class="mmi-is-hidden">
                        <span class="mmi-spinner-inline"></span>
                        <p class="mmi-drop-primary">Analyzing file&hellip;</p>
                    </div>

                    <!-- Result state: format + row count confirmed -->
                    <div id="add-drop-zone-result" class="mmi-is-hidden">
                        <span class="dashicons dashicons-yes-alt mmi-drop-ok-icon"></span>
                        <div class="mmi-drop-result-info">
                            <strong id="add-upload-result-filename">file.csv</strong>
                            <span class="mmi-drop-result-meta" id="add-upload-result-meta">CSV &bull; 0 rows</span>
                        </div>
                        <button type="button" class="button button-small mmi-drop-replace-btn" id="add-upload-replace-btn">Replace</button>
                    </div>

                    <!-- Error state -->
                    <div id="add-drop-zone-error" class="mmi-is-hidden">
                        <span class="dashicons dashicons-warning mmi-drop-error-icon"></span>
                        <p class="mmi-drop-primary" id="add-drop-zone-error-msg">Could not analyze the file.</p>
                        <button type="button" class="button button-small" id="add-upload-retry-btn">Try Again</button>
                    </div>
                </div>

                <?php /* Data preview — populated after a successful file analysis so the
                     user can confirm they uploaded the right file before creating
                     the source. Excel files have no preview (analysis is skipped
                     for that format), so this stays hidden for .xls/.xlsx. */ ?>
                <div class="mmi-upload-preview mmi-is-hidden" id="add-upload-preview">
                    <p class="mmi-upload-preview-label">Preview <span id="add-upload-preview-count"></span></p>
                    <div class="mmi-upload-preview-scroll">
                        <table class="mmi-upload-preview-table">
                            <thead><tr id="add-upload-preview-head"></tr></thead>
                            <tbody id="add-upload-preview-body"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Hidden file input triggered by browse / drop -->
                <input type="file" id="add-upload-file-input" class="mmi-is-hidden"
                       accept=".csv,.tsv,.json,.xml,.txt,.xls,.xlsx">

                <!-- Populated by AJAX analysis response -->
                <input type="hidden" id="add-upload-attachment-id" value="">
                <input type="hidden" id="add-upload-detected-format" value="">

                <!-- Supplier name — visible after a successful file analysis -->
                <div class="mmi-config-field mmi-is-hidden" id="add-upload-name-row">
                    <label for="add-supplier-name">Supplier / Source Name <span class="mmi-required">*</span></label>
                    <input type="text" id="add-supplier-name" placeholder="e.g. Vendor Music Catalog" autocomplete="off">
                </div>
            </div><!-- /add-panel-upload -->

            <?php /* ══════════════════════════════════════════════════════
                 DIRECT URL PANEL
            ═══════════════════════════════════════════════════════ */ ?>
            <div class="mmi-add-source-panel mmi-is-hidden" id="add-panel-url">
                <div class="mmi-config-field">
                    <label for="add-url-input">File URL <span class="mmi-required">*</span></label>
                    <input type="url" id="add-url-input" placeholder="https://supplier.com/exports/products.csv" autocomplete="off">
                </div>
                <div class="mmi-config-field">
                    <label for="add-url-supplier-name">Supplier / Source Name <span class="mmi-required">*</span></label>
                    <input type="text" id="add-url-supplier-name" placeholder="e.g. Vendor Music Catalog" autocomplete="off">
                </div>
                <p class="mmi-format-info-row">
                    <span class="mmi-format-info-label">Accepted formats:</span>
                    <span class="mmi-format-pill">CSV</span>
                    <span class="mmi-format-pill">TSV</span>
                    <span class="mmi-format-pill">JSON</span>
                    <span class="mmi-format-pill">XML</span>
                    <span class="mmi-format-pill">XLSX</span>
                </p>
                <p class="description mmi-format-info-note">Authentication (Basic Auth, Bearer Token, API Key) is configured after creation.</p>
            </div><!-- /add-panel-url -->

            <?php /* ══════════════════════════════════════════════════════
                 DROPBOX PANEL
            ═══════════════════════════════════════════════════════ */ ?>
            <div class="mmi-add-source-panel mmi-is-hidden" id="add-panel-dropbox">
                <div class="mmi-config-field">
                    <label for="add-dropbox-path">Dropbox File Path <span class="mmi-required">*</span></label>
                    <input type="text" id="add-dropbox-path" placeholder="/exports/products.csv" autocomplete="off">
                    <small class="mmi-field-description">Full path starting with <code>/</code>, as it appears in your Dropbox.</small>
                </div>
                <div class="mmi-config-field">
                    <label for="add-dropbox-token">Access Token <span class="mmi-required">*</span></label>
                    <input type="password" id="add-dropbox-token" placeholder="sl.Abxxx&hellip;" autocomplete="new-password">
                    <small class="mmi-field-description">Long-lived token from <a href="https://www.dropbox.com/developers/apps" target="_blank" rel="noopener">dropbox.com/developers/apps</a>. Stored securely in the credential vault.</small>
                </div>
                <div class="mmi-config-field">
                    <label for="add-dropbox-supplier-name">Supplier / Source Name <span class="mmi-required">*</span></label>
                    <input type="text" id="add-dropbox-supplier-name" placeholder="e.g. Vendor Music Catalog" autocomplete="off">
                </div>
                <p class="mmi-format-info-row">
                    <span class="mmi-format-info-label">Accepted formats:</span>
                    <span class="mmi-format-pill">CSV</span>
                    <span class="mmi-format-pill">TSV</span>
                    <span class="mmi-format-pill">JSON</span>
                    <span class="mmi-format-pill">XML</span>
                    <span class="mmi-format-pill">XLSX</span>
                </p>
            </div><!-- /add-panel-dropbox -->

            <?php /* ══════════════════════════════════════════════════════
                 GOOGLE DRIVE PANEL
            ═══════════════════════════════════════════════════════ */ ?>
            <div class="mmi-add-source-panel mmi-is-hidden" id="add-panel-gdrive">
                <div class="mmi-config-field">
                    <label for="add-gdrive-input">Google Drive Share URL or File ID <span class="mmi-required">*</span></label>
                    <input type="text" id="add-gdrive-input" placeholder="Paste the share URL or just the file ID" autocomplete="off">
                    <small class="mmi-field-description">From <em>drive.google.com/file/d/<strong>FILE_ID</strong>/view</em>. The file must be shared as &ldquo;Anyone with the link can view.&rdquo;</small>
                </div>
                <div class="mmi-gdrive-extracted mmi-is-hidden" id="add-gdrive-id-display">
                    <span class="dashicons dashicons-yes-alt"></span> Detected file ID: <code id="add-gdrive-id-value"></code>
                </div>
                <div class="mmi-config-field">
                    <label for="add-gdrive-supplier-name">Supplier / Source Name <span class="mmi-required">*</span></label>
                    <input type="text" id="add-gdrive-supplier-name" placeholder="e.g. Vendor Music Catalog" autocomplete="off">
                </div>
                <p class="mmi-format-info-row">
                    <span class="mmi-format-info-label">Accepted formats:</span>
                    <span class="mmi-format-pill">CSV</span>
                    <span class="mmi-format-pill">TSV</span>
                    <span class="mmi-format-pill">JSON</span>
                    <span class="mmi-format-pill">XML</span>
                    <span class="mmi-format-pill">XLSX</span>
                </p>
            </div><!-- /add-panel-gdrive -->

            <?php /* ══════════════════════════════════════════════════════
                 API PANEL
            ═══════════════════════════════════════════════════════ */ ?>
            <div class="mmi-add-source-panel mmi-is-hidden" id="add-panel-api">
                <div class="mmi-config-field">
                    <label for="add-api-integration">API Integration <span class="mmi-required">*</span></label>
                    <?php
                    // From the one template list (mmi_ds_get_preconfigured_templates()),
                    // not a hardcoded pair — Plugivery was missing here, so it could
                    // only ever be added by a script. One instance per integration:
                    // an integration that already has a source is listed but disabled.
                    $mmi_added_source_ids = array_column( $all_sources, 'supplier_id' );
                    ?>
                    <select id="add-api-integration">
                        <option value="" disabled selected>— Select an integration —</option>
                        <?php foreach ( ( function_exists( '\\MannMade\\DataPipeline\\Controllers\\AJAX\\mmi_ds_get_preconfigured_templates' ) ? \MannMade\DataPipeline\Controllers\AJAX\mmi_ds_get_preconfigured_templates() : [] ) as $mmi_tpl_id => $mmi_tpl ) :
                            $mmi_tpl_added = in_array( $mmi_tpl['supplier_id'], $mmi_added_source_ids, true );
                        ?>
                            <option value="<?php echo esc_attr( $mmi_tpl_id ); ?>"<?php disabled( $mmi_tpl_added ); ?>>
                                <?php echo esc_html( $mmi_tpl['supplier_name'] . ( $mmi_tpl_added ? ' (already added)' : '' ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="mmi-field-description">
                        <span class="dashicons dashicons-lock"></span>
                        API integrations are preconfigured. Only your credentials need to be entered.
                        New integrations are added as they are developed.
                    </small>
                </div>
            </div><!-- /add-panel-api -->

        </div><!-- /mmi-config-modal-body -->
        <div class="mmi-config-modal-footer">
            <div class="mmi-config-modal-footer-left"></div>
            <div class="mmi-config-modal-footer-right">
                <button type="button" class="button mmi-action-btn mmi-config-modal-close">Cancel</button>
                <button type="button" class="button button-primary mmi-action-btn" id="mmi-add-source-confirm-btn" disabled>
                    <span class="dashicons dashicons-plus-alt2"></span> Create Data Source
                </button>
            </div>
        </div>
    </div>
</div>
