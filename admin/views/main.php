<?php
/**
 * MMI Data Pipeline — Admin Page Router
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Backward compat: old vip_tab URLs
if ( isset( $_GET['vip_tab'] ) ) {
    wp_redirect( admin_url( 'admin.php?page=mmi-data-pipeline' ) );
    exit;
}

// Backward compat: Taxonomy Mapping was its own top-level tab
// (?pipeline_tab=taxonomy) until 2026-08-30, when it was folded back into
// the Import tab as a collapsible section. Any bookmarked/deep-linked URL
// still naming that tab is redirected to the Import tab with the section
// expanded and scrolled into view (see taxonomy-mapping.js's
// maybeOpenFromUrl(), which mirrors product-import.js's existing
// ?open_schedule=1 pattern) — ?profile= is carried over unchanged, since
// that param still means the same thing (pre-select this profile in the
// section's own variation-adding pickers).
if ( isset( $_GET['pipeline_tab'] ) && 'taxonomy' === $_GET['pipeline_tab'] ) {
    $_mmi_taxonomy_redirect_args = [ 'page' => 'mmi-data-pipeline', 'pipeline_tab' => 'import', 'open_taxonomy' => 1 ];
    if ( isset( $_GET['profile'] ) ) {
        $_mmi_taxonomy_redirect_args['profile'] = sanitize_text_field( wp_unslash( $_GET['profile'] ) );
    }
    wp_redirect( admin_url( 'admin.php?' . http_build_query( $_mmi_taxonomy_redirect_args ) ) );
    exit;
}

// Backward compat: Duplicate Products was its own top-level tab
// (?pipeline_tab=duplicates) until 2026-09-02, when it was folded into the
// Import tab as a collapsible section (section-duplicate-products.php) — the
// same move Taxonomy Mapping made above. ?open_duplicates=1 expands and
// scrolls to #mmi-duplicate-products-section (see duplicate-products.js's
// maybeOpenFromUrl()).
if ( isset( $_GET['pipeline_tab'] ) && 'duplicates' === $_GET['pipeline_tab'] ) {
    $_mmi_dupes_redirect_args = [ 'page' => 'mmi-data-pipeline', 'pipeline_tab' => 'import', 'open_duplicates' => 1 ];
    if ( isset( $_GET['profile'] ) ) {
        $_mmi_dupes_redirect_args['profile'] = sanitize_text_field( wp_unslash( $_GET['profile'] ) );
    }
    wp_redirect( admin_url( 'admin.php?' . http_build_query( $_mmi_dupes_redirect_args ) ) );
    exit;
}

// Collect pipeline process errors to surface as admin notices.
// Every profile is checked (not just whichever one ?profile= happens to name) —
// a scheduled profile import runs with no browser context at all, so scoping
// this to only the currently-viewed profile meant a failure notice only ever
// appeared if a user happened to land on that exact profile's URL. Global
// process errors (Supplier Fetch, Catalog Import) are always shown.
$_mmi_profile_error_candidates = [];
foreach ( array_keys( MMI_DB::get_profiles() ) as $_mmi_err_profile_id ) {
    $_mmi_profile_error_candidates[] = MMI_DB::get_setting( 'mmi_pipeline_process_error_profile_' . $_mmi_err_profile_id );
}
$_mmi_pipeline_errors = array_filter( array_merge( $_mmi_profile_error_candidates, [
    MMI_DB::get_setting( 'mmi_pipeline_process_error_supplier_fetch' ),
    MMI_DB::get_setting( 'mmi_pipeline_process_error_catalog_import' ),
] ), fn( $e ) => ! empty( $e ) && is_array( $e ) );

// Real top-level tab bar: Import / Export. Duplicate Products was a
// first-class tab of its own (tab-duplicate-products.php) until 2026-09-02,
// when it was folded into the Import tab as a collapsible section
// (section-duplicate-products.php) — the same path Taxonomy Mapping already
// took on 2026-08-30: it was promoted out of the Import tab to a
// first-class tab of its own at some point before that, then folded back in
// as a collapsible section (section-taxonomy-mapping.php, included from
// tab-pipeline.php) — then merged into Review & Compare later the same day/
// into 2026-08-31, then relocated again into the Supplier Data Sources
// section's own toolbar (pipeline-step-1-acquisition.php) once its data's
// real ownership (per data source, not per import profile) was pinned down
// — see the backward-compat redirects above and AGENTS.md's Incident
// History for each date's reasoning.
$_mmi_valid_tabs  = [ 'import', 'export', 'workbench' ];
$_mmi_current_tab = isset( $_GET['pipeline_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['pipeline_tab'] ) ) : 'import';
if ( ! in_array( $_mmi_current_tab, $_mmi_valid_tabs, true ) ) {
    $_mmi_current_tab = 'import';
}

$_mmi_tab_labels = [
    'import' => [ 'label' => 'Import', 'icon' => 'dashicons-database-import' ],
    'export' => [ 'label' => 'Export', 'icon' => 'dashicons-database-export' ],
    'workbench' => [ 'label' => 'Product Workbench', 'icon' => 'dashicons-hammer' ],
];

$_mmi_tab_url = function ( string $tab ): string {
    $args = [ 'page' => 'mmi-data-pipeline', 'pipeline_tab' => $tab ];
    if ( isset( $_GET['profile'] ) ) {
        $args['profile'] = sanitize_text_field( wp_unslash( $_GET['profile'] ) );
    }
    return admin_url( 'admin.php?' . http_build_query( $args ) );
};

// Soonest upcoming run across Data Fetch + every Import/Export profile schedule —
// surfaced here (rather than only inside each tab's own Schedules panel) so a user
// on Duplicate Products can still see and reach it.
$_mmi_next_schedule = class_exists( 'MMI_Pipeline_Cron' ) ? MMI_Pipeline_Cron::get_next_scheduled_summary() : null;
$_mmi_next_schedule_url = $_mmi_next_schedule
    ? admin_url( 'admin.php?' . http_build_query( [
        'page'          => 'mmi-data-pipeline',
        'pipeline_tab'  => $_mmi_next_schedule['tab'],
        'open_schedule' => 1,
    ] ) )
    : '';
?>

<div class="wrap">
    <div class="mmi-header">
        <h1><span class="dashicons dashicons-database-import"></span> Data Pipeline</h1>
        <?php if ( class_exists( 'MMI_License_UI' ) ) : ?>
            <div class="mmi-header-actions">
                <?php echo MMI_License_UI::render_header_badge( 'mmi-data-pipeline' ); // phpcs:ignore -- already escaped ?>
                <?php if ( $_mmi_next_schedule ) : ?>
                    <a href="<?php echo esc_url( $_mmi_next_schedule_url ); ?>" class="button mmi-action-btn" id="mmi-next-schedule-link">
                        <span class="dashicons dashicons-clock"></span>
                        Next run: <?php echo esc_html( $_mmi_next_schedule['label'] ); ?> — <?php echo esc_html( $_mmi_next_schedule['process'] ); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php elseif ( $_mmi_next_schedule ) : ?>
            <div class="mmi-header-actions">
                <a href="<?php echo esc_url( $_mmi_next_schedule_url ); ?>" class="button mmi-action-btn" id="mmi-next-schedule-link">
                    <span class="dashicons dashicons-clock"></span>
                    Next run: <?php echo esc_html( $_mmi_next_schedule['label'] ); ?> — <?php echo esc_html( $_mmi_next_schedule['process'] ); ?>
                </a>
            </div>
        <?php endif; ?>
        <p class="mmi-header-description">Unified product data workflow — import from supplier feeds or export your catalog, with profiles and a live preview for both directions</p>
    </div>
    <div class="wp-header-end"></div>

    <?php
    // Panel hides only when a paid license (direct or a bundle like MMI
    // Suite) covers this plugin — this plugin's own leftover trial entry
    // must not keep it on screen once a real key is active.
    $_mmi_pipeline_paid = function_exists( 'mmi_has_paid_license' )
        ? mmi_has_paid_license( 'mmi-data-pipeline' )
        : ( function_exists( 'mmi_is_licensed' ) && mmi_is_licensed( 'mmi-data-pipeline' ) && ! mmi_is_trial_active( 'mmi-data-pipeline' ) );
    ?>
    <?php if ( class_exists( 'MMI_License_UI' ) && ! $_mmi_pipeline_paid ) : ?>
        <?php MMI_License_UI::render_panel( 'mmi-data-pipeline', 'Data Pipeline' ); ?>
    <?php endif; ?>

    <?php foreach ( $_mmi_pipeline_errors as $_mmi_err ) : ?>
        <div class="notice notice-error">
            <p>
                <strong><?php echo esc_html( $_mmi_err['process'] ?? 'Pipeline Process' ); ?> failed</strong>
                &mdash; <?php echo esc_html( $_mmi_err['message'] ?? 'Unknown error' ); ?>
                <?php if ( ! empty( $_mmi_err['time'] ) ) : ?>
                    <em>(<?php echo esc_html( wp_date( 'M j \a\t g:i a', strtotime( $_mmi_err['time'] ) ) ); ?>)</em>
                <?php endif; ?>
                &mdash; <a href="<?php echo esc_url( admin_url( 'admin.php?page=mmi-data-pipeline' ) ); ?>">Check Data Pipeline</a>
            </p>
        </div>
    <?php endforeach; ?>

    <h2 class="nav-tab-wrapper mmi-pipeline-nav-tabs">
        <?php foreach ( $_mmi_tab_labels as $_mmi_tab_key => $_mmi_tab_meta ) :
            $_mmi_is_active = ( $_mmi_current_tab === $_mmi_tab_key );
        ?>
            <a href="<?php echo esc_url( $_mmi_tab_url( $_mmi_tab_key ) ); ?>"
               class="nav-tab<?php echo $_mmi_is_active ? ' nav-tab-active' : ''; ?>">
                <span class="dashicons <?php echo esc_attr( $_mmi_tab_meta['icon'] ); ?>"></span>
                <?php echo esc_html( $_mmi_tab_meta['label'] ); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <?php
    switch ( $_mmi_current_tab ) {
        case 'export':
            include __DIR__ . '/tab-export.php';
            break;
        case 'workbench':
            include __DIR__ . '/tab-workbench.php';
            break;
        case 'import':
        default:
            include __DIR__ . '/tab-pipeline.php';
            break;
    }
    ?>
</div><!-- .wrap -->
