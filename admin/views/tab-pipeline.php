<?php
/**
 * Import Pipeline Tab - Unified Import Workflow
 *
 * Consolidates data fetching, review/comparison, and import scheduling
 * into a single cohesive 3-step pipeline workflow.
 *
 * @package MannMade\DataPipeline
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current profile from URL parameter and validate it exists.
// Scoped to direction='import' — MMI_DB::get_profiles() returns every
// profile regardless of direction, which previously leaked export-created
// profiles onto this tab (they'd show as "Update Only" / "No sources
// assigned", the raw defaults baked in by the export-creation handler).
$current_profile = isset($_GET['profile']) ? sanitize_text_field($_GET['profile']) : 'default';
$profiles = MMI_DB::get_profiles_by_direction( 'import' );

// Migrate existing profiles to explicit modes (safe to call repeatedly).
MMI_DB::migrate_profiles_to_modes();
$profiles = MMI_DB::get_profiles_by_direction( 'import' );

// Validate profile exists; if not, fall back to the first real profile rather than
// 'default' so field mappings are rendered from actual saved data even when the URL
// carries no ?profile= parameter and no profile named 'default' exists.
if (!isset($profiles[$current_profile])) {
    $current_profile = array_key_first($profiles) ?? 'default';
}

// Load profile-specific field mappings
$option_key = $current_profile === 'default' ? 'mmi_pipeline_field_mappings' : 'mmi_pipeline_field_mappings_' . $current_profile;
$field_mappings = MMI_DB::get_field_mappings( $current_profile );

// Derive mode and allow_create from profile.
// Explicit import_mode is always authoritative — legacy per-profile options are never
// allowed to override a profile that has been explicitly configured as 'update-only'.
$current_profile_meta  = $profiles[ $current_profile ] ?? [];
$current_import_mode   = $current_profile_meta['import_mode'] ?? 'update-only';
// product_scope === 'new_only' always overrides import_mode at import time (see
// class-import-preview.php / class-dynamic-product-importer.php / class-product-import-worker.php) —
// the wizard forces import_mode to 'create-and-update' for this scope (see
// section-profile-wizard.php's locked-mode notice), which would otherwise render this
// profile's badge identically to an ordinary all-products Create & Update profile
// despite behaving very differently (existing products are never touched here).
$current_product_scope = $current_profile_meta['product_scope'] ?? 'all_products';
// Review & Compare's rich preview (pipeline-step-2-review.php) is entirely
// Product-shaped — see DATA_PIPELINE_PHASE2_SCOPING.md Milestone 5, which
// deliberately bypasses it for any other data type rather than generalizing
// it, the same reasoning already applied to Field Mapping (Milestone 2) and
// Attributes (Milestone 3).
$current_data_type = $current_profile_meta['data_type'] ?? 'product';
// Which supplier(s) the Field Mapping table's per-supplier columns should
// show for this profile — an empty array means "all enabled sources" (the
// Source step's own stated default), matching the same array already used
// to scope the actual import run (see ProductImportController.php's
// array_intersect() against this same field). Read by panel-field-mapping.php.
$profile_assigned_sources = $current_profile_meta['sources'] ?? [];
$allow_create_products = ( $current_import_mode === 'create-and-update' );
// 'color' is an "R, G, B" triplet, not a hex string — .mmi-pgc-mode-badge
// (import-pipeline.css) reads it via rgba(var(--badge-raw-color, ...), 0.12);
// a hex string here silently never applied (see the custom-property name
// this must match, set below and in import-settings.js's own copies).
$mode_labels = [
    'create-and-update'  => [ 'label' => 'Create &amp; Update', 'color' => '45, 122, 45',  'icon' => '➕🔄' ],
    'create-only'        => [ 'label' => 'Create Only',         'color' => '5, 150, 105',  'icon' => '➕'    ],
    'update-only'        => [ 'label' => 'Update Only',         'color' => '71, 85, 105',  'icon' => '🔄'   ],
    'availability-sync'  => [ 'label' => 'Availability Sync',   'color' => '180, 83, 9',   'icon' => '📍'   ],
];
$mode_info = $mode_labels[ $current_import_mode ] ?? $mode_labels['update-only'];
// Scope beats mode for the badge too, same as it does for the real import —
// otherwise a new_only profile's forced 'create-and-update' storage value
// renders identically to a genuine all-products Create & Update profile.
if ( $current_product_scope === 'new_only' ) {
    $mode_info = [ 'label' => 'Create Only — new products', 'color' => '5, 150, 105', 'icon' => '✨' ];
}

// Load import rules
$duplicate_strategy = MMI_DB::get_setting( 'mmi_pipeline_import_duplicate_strategy', 'update' );
$price_update = MMI_DB::get_setting( 'mmi_pipeline_import_price_update', true );
$stock_update = MMI_DB::get_setting( 'mmi_pipeline_import_stock_update', true );
$image_sync = MMI_DB::get_setting( 'mmi_pipeline_import_image_sync', false );
$category_mapping = MMI_DB::get_setting( 'mmi_pipeline_import_category_mapping', true );

// Load supplier and scheduling settings
// enabled_suppliers is now authoritative from wp_mmi_data_sources (no Plugivery default)
$enabled_suppliers = MMI_DB::get_setting( 'mmi_pipeline_enabled_suppliers', [] );
$schedule_enabled = MMI_DB::get_setting( 'mmi_pipeline_import_schedule_enabled', false );
$schedule_frequency = MMI_DB::get_setting( 'mmi_pipeline_import_schedule_frequency', 'daily' );
$email_notifications = MMI_DB::get_setting( 'mmi_pipeline_import_email_notifications', false );

// Get import history. This section starts collapsed (see its
// mmi-collapsible-section markup below) — a full 500-row fetch was rendering
// ~400KB of table markup into every single page load regardless of whether
// this section was ever opened. Capped to a small initial page; a "Load
// Full History" button (import-settings.js's refreshHistoryTable(), already
// built for post-import refreshes) fetches the AJAX endpoint's own 50-row
// default on demand instead. The total run count and the set of profiles
// with any history at all are fetched separately so the "Last N runs" text
// and the filter pills stay correct independent of this page size.
$mmi_history_initial_limit = 20;
$import_history = MMI_DB::get_import_history( $mmi_history_initial_limit );
$import_history_total_count = MMI_DB::count_import_history();
$last_import = !empty($import_history) ? $import_history[0] : null;

// Helper: format duration in seconds to a human-readable string.
$format_duration = function( int $secs ): string {
    if ( $secs >= 3600 ) {
        return floor( $secs / 3600 ) . 'h ' . floor( ( $secs % 3600 ) / 60 ) . 'm';
    }
    if ( $secs >= 60 ) {
        return floor( $secs / 60 ) . 'm ' . ( $secs % 60 ) . 's';
    }
    return $secs . 's';
};

// Contains ALL configured sources (enabled or not) with status, so the panel can
// show status indicators for sources that exist but aren't yet active.
$configured_suppliers = MMI_Pipeline_Admin::get_configured_suppliers();

// Determine initial step from URL hash or default to step 1 (kept for backward-compat with any
// direct links; no longer drives the layout since the page is fully single-page now).
$initial_step = 1;
if (isset($_GET['step'])) {
    $initial_step = max(1, min(3, (int) $_GET['step']));
}

// Automation Scheduler — data for the topbar collapsible schedule panel.
$schedule_frequencies = [
    'disabled'   => 'Disabled',
    'hourly'     => 'Every Hour',
    'twicedaily' => 'Twice Daily',
    'daily'      => 'Daily',
    'weekly'     => 'Weekly',
];

/**
 * Renders a schedule row's time-of-day control. An "Every Hour" row only
 * has a meaningful MINUTE component (which hour doesn't matter, it repeats
 * every hour — see MMI_Pipeline_Cron::compute_schedule_anchor()), so it
 * gets a dedicated minute-only <select> instead of a native
 * <input type="time"> — no browser lets you restrict a time input to just
 * one of its segments, so an actual control swap is the only way to make
 * "minutes only" real rather than cosmetic. Every other frequency keeps
 * the full HH:MM time input. Both write to the exact same
 * mmi_schedule_time_{process} setting via the same 'time' POST field
 * (mmi-autosave-schedule-time), so no backend save-path branching is
 * needed for this — see ImportSettingsController.php's mmi_autosave_schedule.
 *
 * assets/js/import-pipeline-ui.js's updateScheduleTimeControlKind() swaps
 * this same markup client-side when the Frequency select changes without a
 * page reload — keep the two in sync if this markup changes.
 *
 * @param bool $disabled A profile currently linked to a source (see
 *                        MMI_Pipeline_Cron's "Fetch → Import Linking"
 *                        section) runs on the source's clock, not its own —
 *                        its Frequency/time controls are locked (disabled
 *                        attribute + the .mmi-schedule-controls-locked
 *                        wrapper class) while linked. Always false for
 *                        Data Fetch source rows, which are never linkable.
 */
function mmi_pipeline_render_schedule_time_control( string $process, string $frequency, string $time_value, bool $disabled = false ): void {
    if ( 'hourly' === $frequency ) {
        $current_minute = preg_match( '/^\d{2}:(\d{2})$/', $time_value, $m ) ? $m[1] : '00';
        ?>
        <select class="mmi-schedule-minute-select mmi-autosave-schedule-time"
                data-process="<?php echo esc_attr( $process ); ?>"
                <?php disabled( $disabled ); ?>
                title="Minute past each hour this fetch runs at — stagger sources to avoid overlapping runs">
            <?php for ( $minute = 0; $minute < 60; $minute += 5 ) : $mm = str_pad( (string) $minute, 2, '0', STR_PAD_LEFT ); ?>
            <option value="00:<?php echo esc_attr( $mm ); ?>" <?php selected( $current_minute, $mm ); ?>>
                :<?php echo esc_html( $mm ); ?>
            </option>
            <?php endfor; ?>
        </select>
        <?php
        return;
    }
    ?>
    <input type="time" step="300"
           class="mmi-schedule-time-input mmi-autosave-schedule-time"
           data-process="<?php echo esc_attr( $process ); ?>"
           value="<?php echo esc_attr( $time_value ); ?>"
           <?php disabled( $disabled ); ?>
           title="Time of day (site timezone) this schedule anchors to — stagger jobs to spread out server load">
    <?php
}

// Fetch → Import Linking — see MMI_Pipeline_Cron's "Fetch → Import
// Linking" section. One row per (profile, source) pair — linked or not —
// keyed by profile_id (a profile can list, and link to, more than one
// source), a cheap in-memory lookup over the small, admin-configured
// source/profile lists, not a per-row query.
$mmi_link_rows_by_profile = [];
if ( class_exists( 'MMI_Pipeline_Cron' ) ) {
    foreach ( MMI_Pipeline_Cron::get_link_rows() as $row ) {
        $mmi_link_rows_by_profile[ $row['profile_id'] ][] = $row;
    }
}

// One-time, self-gated migration from the old unified "Data Fetch" schedule
// to one independent schedule per source (see MMI_Pipeline_Cron's docblocks).
MMI_Pipeline_Cron::migrate_sources_to_own_schedules();

// One row per schedulable source (enabled, non-upload — plus any legacy
// supplier not yet migrated into wp_mmi_data_sources) replaces the old
// single "Data Fetch" row below. Self-heals missing WP-Cron events itself.
$schedulable_sources = MMI_Pipeline_Cron::get_schedulable_sources();

// Topbar "Schedules" toggle badge — soonest upcoming fetch across every
// active source schedule (mirrors the old single-hook badge, now scoped to
// whichever source is due soonest instead of one shared tick).
$soonest_fetch      = null;
foreach ( $schedulable_sources as $src ) {
    if ( $src['frequency'] === 'disabled' || ! $src['next'] ) {
        continue;
    }
    if ( $soonest_fetch === null || $src['next'] < $soonest_fetch ) {
        $soonest_fetch = $src['next'];
    }
}
$fetch_badge_text = $soonest_fetch ? human_time_diff( $soonest_fetch, current_time( 'timestamp' ) ) : '';

$p_mode_labels = [
    'create-and-update' => 'Create &amp; Update',
    'create-only'       => 'Create Only',
    'update-only'       => 'Update Only',
    'availability-sync' => 'Availability Sync',
];

// Catalog Maintenance section data — rules, brand OOS rules, stock override
// rules, and last-run stats, all read once here via the single shared
// Catalog_Phase_Runner (also used by the AJAX button, the cron dispatcher,
// and WP-CLI, so this count can never again disagree with what actually ran).
$mmi_catalog_rules       = \MannMade\DataPipeline\Catalog\Catalog_Phase_Runner::get_rules();
$mmi_catalog_rule_count  = \MannMade\DataPipeline\Catalog\Catalog_Phase_Runner::get_active_rule_count();
$mmi_stock_override_rules = MMI_DB::get_setting( 'mmi_stock_override_rules', [] );
if ( ! is_array( $mmi_stock_override_rules ) ) {
    $mmi_stock_override_rules = [];
}
if ( class_exists( 'MannMade\DataPipeline\Stock_Override_Resolver' ) ) {
    $mmi_stock_override_rules = \MannMade\DataPipeline\Stock_Override_Resolver::normalize_rules( $mmi_stock_override_rules );
}
$mmi_catalog_last_run   = MMI_DB::get_setting( 'mmi_last_run_catalog_update', '' );
$mmi_catalog_last_dur   = MMI_DB::get_setting( 'mmi_catalog_update_duration', '' );
$mmi_catalog_last_count = MMI_DB::get_setting( 'mmi_catalog_update_products_processed', 0 );
$mmi_catalog_is_locked  = \MannMade\DataPipeline\Catalog\Catalog_Run_State::is_locked();

// Master enable/disable switches for the Catalog Maintenance and Duplicate
// Products sections — each is a full kill switch for that section's logic
// (not just a UI dim), gated at the same single shared entry point every
// caller (button, scheduled cron, WP-CLI) already goes through: see
// Catalog_Phase_Runner::is_enabled() and mmi_scan_duplicate_candidates's own
// gate in CanonicalCandidatesController.php.
$mmi_catalog_maintenance_enabled = \MannMade\DataPipeline\Catalog\Catalog_Phase_Runner::is_enabled();
$mmi_duplicate_products_enabled  = (bool) MMI_DB::get_setting( 'mmi_duplicate_products_enabled', true );

?>

<div class="mmi-data-pipeline-container">

    <!-- ── Hidden profile selector — JS reads/writes this as the single source of truth ── -->
    <?php if ( ! empty( $profiles ) ) : ?>
    <select id="mmi-import-profile" class="mmi-profile-select mmi-profile-select-hidden" aria-hidden="true" tabindex="-1" hidden>
        <?php foreach ( $profiles as $pid => $pdata ) :
            $selected = ( $pid === $current_profile ) ? ' selected="selected"' : '';
            echo '<option value="' . esc_attr( $pid ) . '"' . $selected . '>' . esc_html( $pdata['name'] ) . '</option>';
        endforeach; ?>
    </select>
    <?php endif; ?>

    <?php
    // Captured here (rather than echoed inline) so it can be rendered between
    // the Supplier Data Sources and Review & Compare sections below — the
    // workflow is to identify/set up a data source first, then design the
    // import (this section), then review/compare. It's rendered via
    // ob_start()/ob_get_clean() instead of being physically moved so the huge
    // block of markup below doesn't need to be relocated verbatim. Section
    // order on this tab is fixed (drag-to-reorder was removed) — collapsing
    // still works, but the order below is the only order.
    ob_start();
    ?>

    <!-- ── Import Profile Cards ─────────────────────────────────────────────── -->
    <div class="mmi-profile-section mmi-process-section mmi-collapsible-section">

        <?php /* Section Header (collapsible) — a static title, same pattern as the
             other sections. It intentionally does NOT mirror whichever profile
             card is currently selected below (that used to make this bar's
             text change every time a different card was clicked). */ ?>
        <div class="mmi-section-header mmi-process-section-header-row mmi-section-clickable">
            <div>
                <h3 class="mmi-process-section-header">
                    <span class="dashicons dashicons-index-card"></span>
                    Import Profiles
                    <span class="mmi-collapse-toggle">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </span>
                </h3>
                <p class="mmi-process-section-description">Choose which profile is active below, then edit, duplicate, or create new ones</p>
            </div>
        </div>

        <div class="mmi-section-content">

        <?php /* Section bar: profile management actions only (kept out of the
             header above so clicking a button doesn't also collapse the section) */ ?>
        <div class="mmi-profile-section-bar">
            <?php if ( ! empty( $profiles ) ) : ?>
            <div class="mmi-psb-actions">
                <button type="button" class="mmi-btn-profile mmi-btn-profile--primary" id="run-manual-import">
                    <span class="dashicons dashicons-controls-play"></span> Run Import Now
                </button>
                <span class="mmi-psb-divider" aria-hidden="true"></span>
                <button type="button" class="mmi-btn-profile mmi-btn-profile--primary" id="new-profile-btn">
                    <span class="dashicons dashicons-plus-alt2"></span> New Profile
                </button>
                 <span class="mmi-psb-divider" aria-hidden="true"></span>
                <button type="button" class="mmi-btn-profile" id="edit-profile-btn">
                    <span class="dashicons dashicons-edit"></span> Edit
                </button>
                <button type="button" class="mmi-btn-profile" id="duplicate-profile-btn" title="Duplicate active profile">
                    <span class="dashicons dashicons-admin-page"></span>
                </button>
                <button type="button" class="mmi-btn-profile mmi-btn-profile--danger" id="delete-profile-btn" title="Delete active profile">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
            <?php else : ?>
            <div class="mmi-psb-actions">
                <button type="button" class="mmi-btn-profile mmi-btn-profile--primary" id="new-profile-btn">
                    <span class="dashicons dashicons-plus-alt2"></span> Create Your First Import Profile
                </button>
            </div>
            <?php endif; ?>
        </div><!-- /.mmi-profile-section-bar -->

        <table class="mmi-profile-cards-grid mmi-uniform-table mmi-uniform-table--hoverable">
            <?php if ( ! empty( $profiles ) ) : ?>
            <thead>
                <tr>
                    <th class="mmi-pgc-col-name">Name</th>
                    <th class="mmi-pgc-col-type">Type</th>
                    <th class="mmi-pgc-col-mode">Mode</th>
                    <th class="mmi-pgc-col-sources">Sources</th>
                    <th class="mmi-pgc-col-pending">Pending</th>
                    <th class="mmi-pgc-col-refresh"><span class="screen-reader-text">Refresh</span></th>
                    <th class="mmi-pgc-col-schedule">Schedule</th>
                </tr>
            </thead>
            <?php endif; ?>
            <tbody id="mmi-profile-cards-grid">
            <?php if ( empty( $profiles ) ) : ?>
                <tr>
                    <td class="mmi-pgc-empty-state">
                        <p>No import profiles yet.</p>
                        <button type="button" class="button button-primary mmi-action-btn" id="no-profiles-create-btn-grid">➕ Create Your First Import Profile</button>
                    </td>
                </tr>
            <?php else : ?>
                <?php
                // Grid's Type column — same registry lookup
                // (MMI_Data_Type_Registry::get_choices_for_ui()) and the same
                // icon-by-key-prefix mapping section-profile-wizard.php's own
                // Step 1 picker uses (included further down this same file),
                // kept as an independent copy per that file's own comment on
                // why: small, purely decorative, safe to diverge over time.
                $mmi_data_type_labels = class_exists( 'MMI_Data_Type_Registry' ) ? MMI_Data_Type_Registry::get_choices_for_ui() : [ 'product' => 'Product' ];
                $mmi_data_type_icon   = static function ( string $key ): string {
                    if ( strpos( $key, 'cpt:' ) === 0 ) { return '📄'; }
                    if ( strpos( $key, 'taxonomy:' ) === 0 ) { return '🏷️'; }
                    switch ( $key ) {
                        case 'product':  return '📦';
                        case 'order':    return '🧾';
                        case 'customer': return '🧑‍💼';
                        case 'coupon':   return '🎟️';
                        case 'post':     return '📝';
                        case 'page':     return '📃';
                        case 'comment':  return '💬';
                        case 'user':     return '👤';
                        default:         return '▫️';
                    }
                };
                ?>
                <?php foreach ( $profiles as $pid => $pdata ) :
                    $p_data_type       = $pdata['data_type'] ?? 'product';
                    $p_data_type_label = $mmi_data_type_labels[ $p_data_type ] ?? ucfirst( str_replace( [ 'cpt:', 'taxonomy:' ], '', $p_data_type ) );
                    $p_mode      = $pdata['import_mode'] ?? 'update-only';
                    $p_mode_info = $mode_labels[ $p_mode ] ?? $mode_labels['update-only'];
                    if ( ( $pdata['product_scope'] ?? 'all_products' ) === 'new_only' ) {
                        $p_mode_info = [ 'label' => 'Create Only — new products', 'color' => '5, 150, 105', 'icon' => '✨' ];
                    }
                    $p_sources   = $pdata['sources'] ?? [];
                    $is_active   = ( $pid === $current_profile );
                    $p_freq      = MMI_DB::get_setting( 'mmi_schedule_profile_' . $pid, 'disabled' );
                    // Same canonical helper the Schedules panel and the
                    // mmi_pipeline_toggle_profile_link AJAX handler already use
                    // (MMI_Pipeline_Cron::get_profile_next_run_label()) — a plain
                    // wp_next_scheduled() lookup here would come back empty for
                    // any profile linked to a source's fetch schedule instead of
                    // running its own independent WP-Cron event, silently blanking
                    // this column for exactly those profiles.
                    $p_next_label = class_exists( 'MMI_Pipeline_Cron' )
                        ? MMI_Pipeline_Cron::get_profile_next_run_label( $pid )
                        : ( 'disabled' === $p_freq ? '—' : null );
                ?>
                <tr class="mmi-profile-grid-card mmi-uniform-row--selectable<?php echo $is_active ? ' is-active' : ''; ?>"
                    data-profile-id="<?php echo esc_attr( $pid ); ?>"
                    data-profile-name="<?php echo esc_attr( $pdata['name'] ); ?>"
                    data-import-mode="<?php echo esc_attr( $p_mode ); ?>"
                    data-sources='<?php echo esc_attr( wp_json_encode( array_values( $p_sources ) ) ); ?>'
                    role="button" tabindex="0"
                    aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>">
                    <td class="mmi-pgc-col-name">
                        <span class="mmi-pgc-name"><?php echo esc_html( $pdata['name'] ); ?></span>
                    </td>
                    <td class="mmi-pgc-col-type">
                        <span class="mmi-pgc-mode-badge" style="--badge-raw-color:100, 100, 100" title="Data type this profile imports — see MMI_Data_Type_Registry">
                            <?php echo esc_html( $mmi_data_type_icon( $p_data_type ) ); ?> <?php echo esc_html( $p_data_type_label ); ?>
                        </span>
                    </td>
                    <td class="mmi-pgc-col-mode">
                        <span class="mmi-pgc-mode-badge" style="--badge-raw-color:<?php echo esc_attr( $p_mode_info['color'] ); ?>">
                            <?php echo $p_mode_info['icon']; ?> <?php echo $p_mode_info['label']; ?>
                        </span>
                    </td>
                    <td class="mmi-pgc-col-sources mmi-pgc-sources">
                        <?php if ( ! empty( $p_sources ) ) :
                            foreach ( $p_sources as $src_id ) :
                                if ( isset( $configured_suppliers[ $src_id ] ) ) : ?>
                                    <span class="mmi-pgc-source-pill"><?php echo esc_html( $configured_suppliers[ $src_id ]['supplier_name'] ); ?></span>
                                <?php endif;
                            endforeach;
                        else : ?>
                            <span class="mmi-pgc-no-sources">No sources assigned</span>
                        <?php endif; ?>
                    </td>
                    <td class="mmi-pgc-col-pending" data-profile-id="<?php echo esc_attr( $pid ); ?>">
                        <?php
                        // Read-only — never triggers generate_preview() on a page
                        // render (see AGENTS.md "Server Load & PHP-FPM Impact").
                        // Missing/stale cells are filled in (and periodically
                        // refreshed) by initProfilePendingStats() in
                        // import-pipeline.js, not by this render.
                        $p_relevant_type = MMI_Import_Preview::relevant_pending_type_for( $pdata );
                        $p_pending       = MMI_Import_Preview::get_cached_profile_pending_stats( $pid );
                        ?>
                        <span class="mmi-pgc-pending-wrap" data-relevant-type="<?php echo esc_attr( $p_relevant_type ); ?>">
                        <?php if ( $p_pending !== null ) :
                            $p_create   = (int) ( $p_pending['will_create'] ?? 0 );
                            $p_update   = (int) ( $p_pending['will_update'] ?? 0 );
                            $p_is_stale = ! empty( $p_pending['stale'] );
                        ?>
                            <span class="mmi-pgc-pending-stats<?php echo $p_is_stale ? ' mmi-pgc-pending-stale' : ''; ?>" title="Last checked <?php echo esc_attr( $p_pending['computed_at'] ?? '' ); ?>">
                                <?php if ( $p_relevant_type === 'create' || $p_relevant_type === 'both' ) : ?>
                                <span class="mmi-pgc-pending-stat mmi-stat-create<?php echo $p_create > 0 ? ' mmi-stat-clickable' : ''; ?>"
                                      data-profile-id="<?php echo esc_attr( $pid ); ?>"
                                      title="<?php echo esc_attr( $p_create > 0 ? "{$p_create} new product(s) ready to create — click to review in Review & Compare" : 'No new products pending' ); ?>">
                                    <span class="mmi-stat-value"><?php echo esc_html( number_format_i18n( $p_create ) ); ?></span>
                                    <span class="mmi-pgc-pending-label">new</span>
                                </span>
                                <?php endif; ?>
                                <?php if ( $p_relevant_type === 'update' || $p_relevant_type === 'both' ) : ?>
                                <span class="mmi-pgc-pending-stat mmi-stat-update" title="<?php echo esc_attr( "{$p_update} product(s) with changed data pending" ); ?>">
                                    <span class="mmi-stat-value"><?php echo esc_html( number_format_i18n( $p_update ) ); ?></span>
                                    <span class="mmi-pgc-pending-label">changed</span>
                                </span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        </span>
                    </td>
                    <td class="mmi-pgc-col-refresh" data-profile-id="<?php echo esc_attr( $pid ); ?>">
                        <?php if ( $p_pending === null ) : ?>
                            <button type="button" class="mmi-pgc-pending-check-btn" data-profile-id="<?php echo esc_attr( $pid ); ?>" title="Check the source data for pending create/update work">
                                <span class="dashicons dashicons-search"></span> Check
                            </button>
                        <?php else : ?>
                            <button type="button" class="mmi-pgc-pending-refresh-btn" data-profile-id="<?php echo esc_attr( $pid ); ?>" title="Refresh now">
                                <span class="dashicons dashicons-update"></span>
                            </button>
                        <?php endif; ?>
                    </td>
                    <td class="mmi-pgc-col-schedule">
                        <?php if ( 'disabled' === $p_freq ) : ?>
                        <span class="mmi-pgc-schedule mmi-pgc-schedule-disabled">
                            <span class="dashicons dashicons-marker mmi-pgc-sched-icon"></span>
                            Disabled
                        </span>
                        <?php elseif ( $p_next_label ) : ?>
                        <span class="mmi-pgc-schedule">
                            <span class="dashicons dashicons-clock mmi-pgc-sched-icon"></span>
                            <?php echo esc_html( $p_next_label ); ?>
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table><!-- /.mmi-profile-cards-grid -->

        <!-- ── Pipeline Topbar: configure + run ─────────────────────────────── -->
        <div class="mmi-pipeline-topbar mmi-topbar-standalone">
        <div class="mmi-topbar-main-row">
            <div class="mmi-topbar-actions">
                <div class="mmi-topbar-util-actions">
                    <button type="button" class="button mmi-action-btn mmi-schedule-toggle-btn" id="mmi-schedule-toggle">
                        <span class="dashicons dashicons-clock"></span> Schedules
                        <span class="mmi-badge info" id="mmi-fetch-badge"><?php echo esc_html( $fetch_badge_text ); ?></span>
                    </button>
                    <?php /* Stock Overrides / Catalog Rules / Update Store Catalog buttons
                         removed — merged into the "Catalog Maintenance" section below
                         (between this section and Review & Compare). Attributes button
                         removed earlier — that panel is retired; attribute & variation
                         mapping now lives in the Import Profile Wizard's own step. */ ?>
                </div>
                </div>
        </div><!-- /.mmi-topbar-main-row -->

            <?php /* Status row: results/progress for Run Import Now (above, in
                 .mmi-psb-actions). Deliberately its own full-width row rather than
                 living inside the button row — a tall result panel (field-change
                 breakdown, etc) inside a flex button row stretched the whole topbar
                 to match, making it look abnormally tall. */ ?>
            <div class="mmi-topbar-status-row">
                <!-- Import progress / status panel (shown while manual import is running) -->
                <div id="manual-import-status" class="mmi-pipeline-status-panel"></div>
            </div><!-- /.mmi-topbar-status-row -->

            <!-- Collapsible schedule panel (starts hidden, toggled by #mmi-schedule-toggle) -->
            <div class="mmi-topbar-schedule-panel" id="mmi-topbar-schedule-panel">
                <table class="mmi-topbar-schedule-table">
                    <thead>
                        <tr>
                            <th>Process</th>
                            <th>Frequency</th>
                            <th>Next Run</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php /* Data Fetch rows — one per schedulable source, each with its
                             own independent frequency (see MMI_Pipeline_Cron::
                             get_schedulable_sources()). Upload sources never appear
                             here — they're static files with no live endpoint to
                             refresh on a schedule. */ ?>
                        <?php
                        $src_type_labels = [
                            'api'     => 'API',
                            'url'     => 'Direct URL',
                            'dropbox' => 'Dropbox',
                            'gdrive'  => 'Google Drive',
                        ];
                        ?>
                        <tr class="mmi-tsched-group-header">
                            <td colspan="4">API Data Sources</td>
                        </tr>
                        <?php if ( empty( $schedulable_sources ) ) : ?>
                        <tr class="mmi-tsched-row">
                            <td colspan="4"><span class="mmi-tsched-no-suppliers">No schedulable data sources — add one on the Pipeline tab.</span></td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ( $schedulable_sources as $src ) :
                            $src_next_label = $src['next'] ? human_time_diff( $src['next'], current_time( 'timestamp' ) ) . ' from now' : 'Not scheduled';
                        ?>
                        <tr class="mmi-tsched-row<?php echo ( 'disabled' === $src['frequency'] ) ? ' mmi-sched-is-disabled' : ''; ?>">
                            <td>
                                <div class="mmi-tsched-process">
                                    <span class="dashicons dashicons-download mmi-tsched-icon mmi-tsched-icon--fetch"></span>
                                    <?php echo esc_html( $src['name'] ); ?>
                                </div>
                            </td>
                            <td>
                                <div class="mmi-schedule-controls">
                                <select id="sched-source-<?php echo esc_attr( $src['supplier_id'] ); ?>"
                                        class="mmi-schedule-select mmi-autosave-schedule"
                                        data-process="source_fetch_<?php echo esc_attr( $src['supplier_id'] ); ?>">
                                    <?php foreach ( $schedule_frequencies as $val => $lbl ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $src['frequency'], $val ); ?>>
                                            <?php echo esc_html( $lbl ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php mmi_pipeline_render_schedule_time_control( 'source_fetch_' . $src['supplier_id'], $src['frequency'], $src['time'] ); ?>
                                </div>
                            </td>
                            <td class="mmi-tsched-next">
                                <strong id="sched-source-<?php echo esc_attr( $src['supplier_id'] ); ?>-next"><?php echo esc_html( $src_next_label ); ?></strong>
                            </td>
                            <td class="mmi-tsched-detail">
                                <?php echo esc_html( $src_type_labels[ $src['source_type'] ] ?? ucfirst( $src['source_type'] ) ); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Per-profile import schedule rows -->
                        <tr class="mmi-tsched-group-header">
                            <td colspan="4">Import Profiles</td>
                        </tr>
                        <?php if ( empty( $profiles ) ) : ?>
                        <tr class="mmi-tsched-row">
                            <td colspan="4"><span class="mmi-tsched-no-suppliers">No import profiles yet — create one above.</span></td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ( $profiles as $profile_id => $profile_data ) :
                            $p_freq       = MMI_DB::get_setting( 'mmi_schedule_profile_' . $profile_id, 'disabled' );
                            $p_process    = 'profile_' . $profile_id;
                            $p_time       = MMI_DB::get_setting( 'mmi_schedule_time_' . $p_process, '' );
                            // A profile linked to one or more of its own sources
                            // (see MMI_Pipeline_Cron's "Fetch → Import Linking"
                            // section) has no independent WP-Cron event of its
                            // own — it runs from those sources' post-fetch
                            // triggers instead, so Next Run mirrors the soonest
                            // linked source's own next-fetch time rather than
                            // self-healing a hook that's deliberately not
                            // scheduled. $p_links is empty (not just falsy) when
                            // unlinked, so `! empty( $p_links )` is this file's
                            // "is this profile linked to anything" check.
                            $p_links      = ( 'disabled' !== $p_freq && class_exists( 'MMI_Pipeline_Cron' ) )
                                ? MMI_Pipeline_Cron::get_profile_links( $profile_id )
                                : [];
                            // Same computation the mmi_pipeline_toggle_profile_link AJAX
                            // handler calls after a live link/unlink (see
                            // MMI_Pipeline_Cron::get_profile_next_run_label()'s docblock) —
                            // extracted there so server render and AJAX response can never
                            // disagree about what Next Run should say.
                            $p_next_label = MMI_Pipeline_Cron::get_profile_next_run_label( $profile_id );
                            $p_mode       = $profile_data['import_mode'] ?? 'update-only';
                            $p_mode_label = $p_mode_labels[ $p_mode ] ?? esc_html( ucfirst( str_replace( '-', ' ', $p_mode ) ) );
                            $p_mode_tag_class = 'mmi-mode-' . $p_mode;
                            // Same new_only override as $mode_info/$p_mode_info above — this
                            // row's own separate $p_mode_labels lookup was missed by that fix
                            // and kept showing the raw stored 'Create & Update' label here.
                            if ( ( $profile_data['product_scope'] ?? 'all_products' ) === 'new_only' ) {
                                $p_mode_label     = 'Create Only — new products';
                                $p_mode_tag_class = 'mmi-mode-new-only-scope';
                            }
                        ?>
                        <tr class="mmi-tsched-row<?php echo ( 'disabled' === $p_freq ) ? ' mmi-sched-is-disabled' : ''; ?>">
                            <td>
                                <div class="mmi-tsched-process">
                                    <span class="dashicons dashicons-clipboard mmi-tsched-icon mmi-tsched-icon--profile"></span>
                                    <?php echo esc_html( $profile_data['name'] ); ?>
                                    <span class="mmi-profile-mode-tag <?php echo esc_attr( $p_mode_tag_class ); ?>"><?php echo $p_mode_label; ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mmi-schedule-controls<?php echo ! empty( $p_links ) ? ' mmi-schedule-controls-locked' : ''; ?>"
                                     <?php echo ! empty( $p_links ) ? 'title="Frequency/time are locked while this profile is linked to a data source — unlink below to set them manually"' : ''; ?>>
                                <select id="sched-profile-<?php echo esc_attr( $profile_id ); ?>"
                                        class="mmi-schedule-select mmi-autosave-schedule"
                                        data-process="<?php echo esc_attr( $p_process ); ?>"
                                        <?php disabled( ! empty( $p_links ) ); ?>>
                                    <?php foreach ( $schedule_frequencies as $val => $lbl ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $p_freq, $val ); ?>>
                                            <?php echo esc_html( $lbl ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php mmi_pipeline_render_schedule_time_control( $p_process, $p_freq, $p_time, ! empty( $p_links ) ); ?>
                                </div>
                            </td>
                            <td class="mmi-tsched-next" id="sched-profile-<?php echo esc_attr( $profile_id ); ?>-next">
                                <?php echo esc_html( $p_next_label ); ?>
                            </td>
                            <td class="mmi-tsched-detail">
                                <?php
                                // Skip this scheduled run entirely when the source
                                // data has nothing this profile would actually
                                // create/update — see MMI_Pipeline_Cron::
                                // run_scheduled_profile_import()'s skip gate and
                                // the Import Profiles grid's Pending column above
                                // (same underlying stats, same "which stat is
                                // relevant" logic). Opt-in per profile, not a
                                // blanket default — a profile whose feed always
                                // has real movement (e.g. pricing) gets nothing
                                // from this toggle, so it isn't forced on.
                                $p_skip_no_data = (bool) MMI_DB::get_setting( 'mmi_schedule_skip_if_no_data_' . $profile_id, false );
                                ?>
                                <div class="mmi-tsched-skip-toggle" title="When checked, this profile's scheduled run is skipped whenever the last pending-data check (see the Pending column above) found nothing to create or update — the check itself still runs on this same schedule, just not the full import.">
                                    <label class="mmi-toggle-switch">
                                        <input type="checkbox"
                                               class="mmi-schedule-skip-toggle"
                                               data-profile="<?php echo esc_attr( $profile_id ); ?>"
                                               <?php checked( $p_skip_no_data ); ?>
                                               <?php disabled( 'disabled' === $p_freq ); ?>>
                                        <span class="mmi-toggle-slider"></span>
                                    </label>
                                    <span class="mmi-tsched-skip-toggle-label">Skip if no new data</span>
                                </div>
                                <?php /* ── Fetch → Import Linking ──────────────────────
                                     See MMI_Pipeline_Cron's "Fetch → Import Linking"
                                     section. One unified row per (profile, source) pair
                                     — a profile can link to more than one of its own
                                     sources, so each pair's checkbox/text state is fully
                                     independent of every other row for the same profile
                                     (this is what fixes the earlier bug where checking one
                                     source's toggle hid a sibling source's toggle: that
                                     only happened because the old single-link version had
                                     one shared "status" block covering the whole profile).
                                     Every row always renders regardless of link state (never
                                     PHP-conditionally omitted) so import-pipeline-ui.js's
                                     applyProfileLinkState() can patch just the one row that
                                     changed via the [hidden] attribute after an AJAX link/
                                     unlink, with no page reload — see this file's own
                                     .mmi-tsched-link-row[hidden] CSS rule, required per
                                     AGENTS.md's "[hidden] vs a class that sets display" rule
                                     since these are flex containers. Three states per row,
                                     each its own [hidden]-toggled span: linked / matches-
                                     but-not-linked / mismatched-but-not-linked (checkbox
                                     `disabled` only in the last case — see
                                     MMI_Pipeline_Cron::get_link_rows()'s docblock for why
                                     `linked` and `!matches` can never coexist). The
                                     match/mismatch pair also lets import-pipeline-ui.js's
                                     updateLinkSuggestionMatches() flip a not-yet-linked
                                     row's copy client-side the instant the Frequency select
                                     changes, without a round trip. */ ?>
                                <?php foreach ( $mmi_link_rows_by_profile[ $profile_id ] ?? [] as $link_row ) : ?>
                                <div class="mmi-tsched-link-row" data-profile="<?php echo esc_attr( $profile_id ); ?>"
                                     data-supplier="<?php echo esc_attr( $link_row['supplier_id'] ); ?>"
                                     data-source-frequency="<?php echo esc_attr( $link_row['frequency'] ); ?>">
                                    <label class="mmi-toggle-switch"
                                           title="<?php echo $link_row['linked'] ? 'Unlink — stops this profile running off this source\'s fetch' : 'Link — run this profile right after this source fetches'; ?>">
                                        <input type="checkbox" class="mmi-tsched-link-toggle"
                                               data-profile="<?php echo esc_attr( $profile_id ); ?>"
                                               data-supplier="<?php echo esc_attr( $link_row['supplier_id'] ); ?>"
                                               data-supplier-name="<?php echo esc_attr( $link_row['supplier_name'] ); ?>"
                                               <?php checked( $link_row['linked'] ); ?>
                                               <?php disabled( ! $link_row['linked'] && ! $link_row['matches'] ); ?>>
                                        <span class="mmi-toggle-slider"></span>
                                    </label>
                                    <span class="dashicons <?php echo $link_row['linked'] ? 'dashicons-admin-links mmi-tsched-link-row-icon--linked' : 'dashicons-lightbulb mmi-tsched-link-row-icon--suggestion'; ?>"></span>
                                    <span class="mmi-tsched-link-row-linked" <?php echo $link_row['linked'] ? '' : 'hidden'; ?>>
                                        Linked to <?php echo esc_html( $link_row['supplier_name'] ); ?> fetch
                                    </span>
                                    <span class="mmi-tsched-link-row-match" <?php echo ( ! $link_row['linked'] && $link_row['matches'] ) ? '' : 'hidden'; ?>>
                                        Same <?php echo esc_html( $schedule_frequencies[ $link_row['frequency'] ] ?? $link_row['frequency'] ); ?> cadence as <?php echo esc_html( $link_row['supplier_name'] ); ?>'s fetch —
                                    </span>
                                    <span class="mmi-tsched-link-row-mismatch" <?php echo ( ! $link_row['linked'] && ! $link_row['matches'] ) ? '' : 'hidden'; ?>>
                                        <?php echo esc_html( $link_row['supplier_name'] ); ?> fetches <?php echo esc_html( $schedule_frequencies[ $link_row['frequency'] ] ?? $link_row['frequency'] ); ?> — match this profile's frequency to link:
                                    </span>
                                </div>
                                <?php endforeach; ?>
                                <?php
                                // See MMI_Pipeline_Cron::get_profile_staleness_warning()'s
                                // docblock — only non-empty once this profile has at least
                                // one link, so an unlinked profile (still running entirely
                                // on its own independent clock) never shows it.
                                $p_staleness = class_exists( 'MMI_Pipeline_Cron' ) ? MMI_Pipeline_Cron::get_profile_staleness_warning( $profile_id ) : '';
                                ?>
                                <div class="mmi-tsched-link-staleness" data-profile="<?php echo esc_attr( $profile_id ); ?>" <?php echo $p_staleness ? '' : 'hidden'; ?>>
                                    <span class="dashicons dashicons-warning"></span>
                                    <span class="mmi-tsched-link-staleness-text"><?php echo esc_html( $p_staleness ); ?></span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php
                        // Catalog Maintenance (the section below): its own recurring run, plus one
                        // 5 minutes after every successful data fetch unless this is Disabled.
                        $cat_freq  = (string) MMI_DB::get_setting( 'mmi_schedule_catalog_update', 'sixhourly' );
                        $cat_time  = (string) MMI_DB::get_setting( 'mmi_schedule_time_catalog_update', '' );
                        $cat_next  = wp_next_scheduled( 'mmi_scheduled_catalog_update' );
                        $cat_label = $cat_next ? human_time_diff( $cat_next, time() ) . ' from now' : 'Not scheduled';
                        $cat_freqs = [ 'disabled' => 'Disabled', 'hourly' => 'Every Hour', 'sixhourly' => 'Every 6 Hours', 'twicedaily' => 'Twice Daily', 'daily' => 'Daily' ];
                        ?>
                        <tr class="mmi-tsched-group-header">
                            <td colspan="4">Store Catalog</td>
                        </tr>
                        <tr class="mmi-tsched-row<?php echo ( 'disabled' === $cat_freq ) ? ' mmi-sched-is-disabled' : ''; ?>">
                            <td>
                                <div class="mmi-tsched-process">
                                    <span class="dashicons dashicons-admin-tools mmi-tsched-icon mmi-tsched-icon--catalog"></span>
                                    Catalog Maintenance
                                </div>
                            </td>
                            <td>
                                <div class="mmi-schedule-controls">
                                <select id="sched-catalog-update" class="mmi-schedule-select mmi-autosave-schedule" data-process="catalog_update">
                                    <?php foreach ( $cat_freqs as $val => $lbl ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $cat_freq, $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php mmi_pipeline_render_schedule_time_control( 'catalog_update', $cat_freq, $cat_time ); ?>
                                </div>
                            </td>
                            <td class="mmi-tsched-next" id="sched-catalog-update-next"><?php echo esc_html( $cat_label ); ?></td>
                            <td class="mmi-tsched-detail">Every Catalog Maintenance operation, in order. Also runs 5 minutes after each successful data fetch.</td>
                        </tr>
                    </tbody>
                </table>
                <div class="mmi-topbar-sched-footer">
                    <label>
                        <input type="checkbox"
                               name="email_notifications"
                               value="1"
                               class="mmi-autosave-field"
                               data-setting="email_notifications"
                               <?php checked( $email_notifications ); ?>>
                        Email notifications on import completion / errors
                    </label>
                </div>
            </div><!-- /.mmi-topbar-schedule-panel -->

            <?php /* Stock Overrides and Catalog Rules panels removed — merged into
                 the "Catalog Maintenance" section below. Attribute & Variation
                 Mapping panel removed earlier — its content is now included
                 directly by section-profile-wizard.php's Attributes step. */ ?>

        </div><!-- /.mmi-pipeline-topbar.mmi-topbar-standalone -->

        </div><!-- /.mmi-section-content -->

    </div><!-- /.mmi-profile-section -->

    <?php $mmi_profile_section_html = ob_get_clean(); ?>

    <?php /* ── Fixed-order Sections Container (no longer sortable/draggable —
         each section can still be collapsed/expanded via its header) ── */ ?>
    <div id="mmi-pipeline-sections">

    <!-- ── Supplier Data Sources ────────────────────────────────────────────── -->
    <div class="mmi-process-section mmi-collapsible-section" data-section="sources">
        <?php include __DIR__ . '/partials/pipeline-step-1-acquisition.php'; ?>
    </div>

    <?php /* ── Import Profile section — rendered here, between Data Sources and
         Review & Compare. See ob_start() capture near the top of this file. ── */ ?>
    <?php echo $mmi_profile_section_html; ?>

    <?php /* ── Import Profile Wizard — inline section, new sibling directly below
         Import Profiles (not inside it) so the profile list stays visible/
         scrollable while a profile is being configured. Hidden by default,
         expanded via JS on #new-profile-btn / #edit-profile-btn /
         #no-profiles-create-btn click — see section-profile-wizard.php and
         IMPORT_WIZARD_INLINE_SECTION_HANDOFF.md. Used for both creating a new
         profile and editing an existing one (#edit-profile-btn opens it in
         edit mode via openProfileWizard(currentProfile)). Its Field Mapping
         step includes panel-field-mapping.php's table content directly (see
         section-profile-wizard.php) — that panel no longer has a standalone
         include of its own. */ ?>
    <?php include __DIR__ . '/partials/section-profile-wizard.php'; ?>

    <!-- ── Catalog Maintenance — merges what were Stock Overrides, Catalog
         Rules, and "Update Store Catalog" into one section/table. ── -->
    <?php include __DIR__ . '/partials/panel-catalog-maintenance.php'; ?>

    <!-- ── Duplicate Products — moved here from its own top-level tab
         2026-09-02; see main.php's ?pipeline_tab=duplicates redirect. ── -->
    <?php include __DIR__ . '/partials/section-duplicate-products.php'; ?>

    <!-- ── Review & Compare ──────────────────────────────────────────────── -->
    <div class="mmi-process-section mmi-collapsible-section" id="mmi-review-compare-section" data-section="review">
        <div class="mmi-section-header mmi-process-section-header-row mmi-section-clickable">
            <div>
                <h3 class="mmi-process-section-header">
                    <span class="dashicons dashicons-search"></span>
                    Review &amp; Compare
                    <span class="mmi-collapse-toggle">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </span>
                </h3>
                <p class="mmi-process-section-description">Preview field mappings and compare supplier data against your catalog before importing</p>
            </div>
        </div>

        <div class="mmi-section-content">

            <?php include __DIR__ . '/partials/pipeline-step-2-review.php'; ?>
        </div>
    </div>

    <!-- ── Process Logs (merged fetch + import, collapsible) ─────────────── -->
    <div data-section="logs">
        <?php include __DIR__ . '/partials/section-process-logs.php'; ?>
    </div>

    <?php /* ── Import History — kept last so the most actionable sections
         (Data Sources, Profile, Review & Compare) are seen first ── */ ?>
    <div class="mmi-process-section mmi-collapsible-section collapsed" data-section="history">
        <div class="mmi-section-header mmi-process-section-header-row mmi-section-clickable">
            <div>
                <h3 class="mmi-process-section-header">
                    <span class="dashicons dashicons-backup"></span>
                    Import History
                    <span class="mmi-collapse-toggle">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </span>
                </h3>
                <p class="mmi-process-section-description">Showing <?php echo count( $import_history ); ?> of <?php echo number_format( $import_history_total_count ); ?> import run<?php echo $import_history_total_count !== 1 ? 's' : ''; ?></p>
            </div>
        </div>

        <div class="mmi-section-content">
        <?php
        // Surface the SAME staleness check that drives the email alert, directly on
        // this table — this is the gap that let the email say "Failed" while this
        // table showed nothing but old "Success" rows with no indication they'd gone
        // stale. Both now read from MMI_Pipeline_Cron::get_profile_staleness().
        $stale_profile_banners = [];
        foreach ( array_keys( (array) $profiles ) as $stale_check_pid ) {
            $staleness = MMI_Pipeline_Cron::get_profile_staleness( $stale_check_pid );
            if ( $staleness && $staleness['is_stale'] ) {
                $stale_profile_banners[] = [
                    'name'         => $profiles[ $stale_check_pid ]['name'] ?? ucfirst( $stale_check_pid ),
                    'last_success' => $staleness['last_success'],
                    'age_seconds'  => $staleness['age_seconds'],
                ];
            }
        }
        ?>
        <?php if ( ! empty( $stale_profile_banners ) ) : ?>
            <div class="mmi-history-stale-banner">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <?php foreach ( $stale_profile_banners as $banner ) : ?>
                    <p>
                        <strong><?php echo esc_html( $banner['name'] ); ?></strong> hasn't completed successfully
                        <?php if ( $banner['last_success'] ) : ?>
                            since <?php echo esc_html( $banner['last_success'] ); ?>
                            (<?php echo esc_html( MMI_Pipeline_Cron::format_duration( (float) $banner['age_seconds'] ) ); ?> ago)
                        <?php else : ?>
                            — it has never recorded a successful run
                        <?php endif; ?>
                        — every row below may be older than it looks.
                    </p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ( ! empty( $import_history ) ) : ?>
            <?php
            // Distinct profile list for filter pills — fetched independently
            // of $import_history's own (now capped) page size, so a profile
            // whose runs have all scrolled past the initial page still gets
            // a pill instead of silently disappearing from the filter list.
            $history_profile_ids = MMI_DB::get_import_history_profile_ids();
            sort( $history_profile_ids );
            ?>
            <?php if ( count( $history_profile_ids ) > 1 ) : ?>
            <div class="mmi-history-filter" id="mmi-history-filter">
                <span class="mmi-history-filter-label">Filter:</span>
                <button type="button" class="mmi-history-filter-btn is-active" data-filter-profile="">All Profiles</button>
                <?php foreach ( $history_profile_ids as $hpid ) :
                    $hpname = $profiles[ $hpid ]['name'] ?? ucfirst( $hpid );
                ?>
                <button type="button" class="mmi-history-filter-btn" data-filter-profile="<?php echo esc_attr( $hpid ); ?>">
                    <?php echo esc_html( $hpname ); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="mmi-history-table-wrap">
            <table class="mmi-history-table mmi-uniform-table mmi-uniform-table--hoverable" id="mmi-history-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date &amp; Time</th>
                        <th>Profile</th>
                        <th>Duration</th>
                        <th>Created</th>
                        <th>Updated</th>
                        <th>Skipped</th>
                        <th>Failed</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="mmi-history-tbody">
                    <?php foreach ( $import_history as $entry ) :
                        // ── Duration ────────────────────────────────────────
                        $notes    = ! empty( $entry['notes'] ) ? json_decode( $entry['notes'], true ) : [];
                        $dur_secs = isset( $notes['duration_seconds'] ) ? (int) $notes['duration_seconds'] : null;
                        if ( is_null( $dur_secs ) && ! empty( $entry['completed_at'] ) && ! empty( $entry['started_at'] ) ) {
                            $d = strtotime( $entry['completed_at'] ) - strtotime( $entry['started_at'] );
                            if ( $d > 0 ) { $dur_secs = $d; }
                        }
                        $duration = is_null( $dur_secs ) ? '—' : $format_duration( $dur_secs );

                        // ── Profile name ─────────────────────────────────────
                        $entry_profile_id   = $entry['profile_id'] ?? 'default';
                        $entry_profile_name = $profiles[ $entry_profile_id ]['name'] ?? ucfirst( $entry_profile_id );

                        // ── Status + badge class ─────────────────────────────
                        $status     = $entry['status'] ?? 'Unknown';
                        $tot_ops    = ( $entry['imported'] ?? 0 ) + ( $entry['updated'] ?? 0 )
                                    + ( $entry['skipped']  ?? 0 ) + ( $entry['errors']  ?? 0 );
                        $is_no_data = ( in_array( $status, [ 'Success', 'Partial Success' ], true ) && $tot_ops === 0 );

                        // Normalise legacy 'Partial' → 'Partial Success' for display.
                        $display_status = match ( true ) {
                            $is_no_data                              => 'No Data',
                            $status === 'Partial'                   => 'Partial',
                            $status === 'Partial Success'           => 'Partial',
                            default                                 => $status,
                        };

                        $badge_class = match ( true ) {
                            $is_no_data                              => 'no-data',
                            $status === 'Success'                   => 'success',
                            in_array( $status, [ 'Partial', 'Partial Success' ], true ) => 'partial',
                            $status === 'Aborted'                   => 'aborted',
                            in_array( $status, [ 'Failed', 'Error' ], true )            => 'error',
                            $status === 'In Progress'               => 'in-progress',
                            default                                 => 'unknown',
                        };

                        // Append failure count to Partial label.
                        $err_count = (int) ( $entry['errors'] ?? 0 );
                        if ( in_array( $status, [ 'Partial', 'Partial Success' ], true ) && $err_count > 0 ) {
                            $display_status .= ' (' . number_format( $err_count ) . ' failed)';
                        }

                        // ── Badge tooltip (failure reason / notes) ───────────
                        $badge_title = '';
                        if ( $is_no_data ) {
                            $badge_title = 'No products found — check enabled suppliers and data fetch status.';
                        } elseif ( ! empty( $notes['error'] ) ) {
                            $badge_title = 'Error: ' . $notes['error'];
                        } elseif ( ! empty( $notes['aborted'] ) ) {
                            $badge_title = 'Stopped by user.';
                        } elseif ( $status === 'In Progress' ) {
                            $badge_title = 'Import is currently running.';
                        }

                        // ── Date formatting ──────────────────────────────────
                        $display_date = wp_date( 'M j, Y g:i a', strtotime( $entry['started_at'] ) );

                        // Any run with failures or a run-level problem opens
                        // Run Insights (import-run-insights.js) from its badge.
                        $has_insights = $err_count > 0 || in_array( $badge_class, [ 'partial', 'error', 'aborted' ], true );
                        if ( $has_insights ) {
                            $badge_title = trim( $badge_title . ' Click for details.' );
                        }
                    ?>
                        <tr data-profile="<?php echo esc_attr( $entry_profile_id ); ?>">
                            <td><?php echo (int) $entry['id']; ?></td>
                            <td>
                                <abbr title="<?php echo esc_attr( $entry['started_at'] ); ?>">
                                    <?php echo esc_html( $display_date ); ?>
                                </abbr>
                            </td>
                            <td><?php echo esc_html( $entry_profile_name ); ?></td>
                            <td><?php echo esc_html( $duration ); ?></td>
                            <td><?php echo number_format( $entry['imported'] ?? 0 ); ?></td>
                            <td><?php echo number_format( $entry['updated'] ?? 0 ); ?></td>
                            <td><?php echo number_format( $entry['skipped'] ?? 0 ); ?></td>
                            <td><?php echo number_format( $entry['errors'] ?? 0 ); ?></td>
                            <td>
                                <?php if ( $has_insights ) : ?>
                                <button type="button" class="history-status-badge mmi-badge <?php echo esc_attr( $badge_class ); ?> mmi-run-insights-open"
                                    data-history-id="<?php echo (int) $entry['id']; ?>" title="<?php echo esc_attr( $badge_title ); ?>">
                                    <?php echo esc_html( $display_status ); ?> <span class="dashicons dashicons-search" aria-hidden="true"></span>
                                </button>
                                <?php else : ?>
                                <span class="history-status-badge mmi-badge <?php echo esc_attr( $badge_class ); ?>"
                                    <?php if ( $badge_title ) : ?>title="<?php echo esc_attr( $badge_title ); ?>"<?php endif; ?>>
                                    <?php echo esc_html( $display_status ); ?>
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php if ( $import_history_total_count > count( $import_history ) ) : ?>
            <button type="button" class="button" id="mmi-history-load-full">
                Load Full History (up to 50 most recent)
            </button>
            <?php endif; ?>
        <?php else : ?>
            <p class="mmi-empty-state">
                <span class="dashicons dashicons-info"></span>
                No import history yet. Run your first import to see results here.
            </p>
        <?php endif; ?>
        </div><!-- /.mmi-section-content -->
    </div><!-- /.mmi-process-section[history] -->

    </div><!-- /#mmi-pipeline-sections -->

    <!-- Configuration Panels (slide-outs) -->
    <?php
    // $default_mappings must be populated before panel-field-mapping.php renders
    // its table rows. This is now included from WITHIN section-profile-wizard.php's
    // Field Mapping step (not here directly) — the standalone panel was retired
    // this session — but $default_mappings must still be built here, before that
    // include runs, since it's a plain PHP variable shared across includes in
    // this same request, not fetched independently by the wizard.
    //
    // panel-primary-keys.php was retired the same way — primary key config now
    // lives exclusively in the wizard's Step 2 (Source) editor, as a plain
    // <select> (populatePkFieldSelect() in import-settings.js) rather than the
    // old browse-modal flow, so it no longer shares #test-modal with this
    // step's field browser. The guard below (MMI_FIELD_BROWSER_MODAL_RENDERED)
    // already had a second, identical copy of that modal in
    // panel-field-mapping.php, which still renders it (opened by clicking
    // directly into a data-source input, see import-settings.js).
    require_once __DIR__ . '/partials/default-field-mappings.php';
    ?>
    <?php include __DIR__ . '/partials/panel-csv-mapping.php'; ?>

</div>

