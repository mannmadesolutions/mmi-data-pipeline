<?php
/**
 * Prints, as JSON, everything a browser test needs to load the Product
 * Workbench tab without a login: the tab's real markup (rendered by its view)
 * and the exact styles and scripts WordPress queues for it, with their
 * localized settings. AJAX replies are then mocked by the test.
 *
 * Run on a site with this plugin active:
 *   WB_VIEW=<path to tab-workbench.php> wp eval-file tests/ui/harness/render-harness.php
 *
 * WB_LEGACY=1 renders the screen as currently deployed (for before/after comparisons).
 *
 * Read-only: it sets the current user in-process for capability checks and
 * enqueues assets; it doesn't render the page shell (no license check) and
 * creates no session or cookie.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$hook = 'mmi-dashboard_page_mmi-data-pipeline';
set_current_screen( $hook );
$_GET['page']         = 'mmi-data-pipeline';
$_GET['pipeline_tab'] = 'workbench';

// WordPress's own admin styles, then the shared library's and this tab's assets.
wp_enqueue_style( 'wp-admin' );
wp_enqueue_style( 'dashicons' );
wp_enqueue_style( 'buttons' );
if ( function_exists( 'mmi_shared_nonce_refresh_register_script' ) ) {
    mmi_shared_nonce_refresh_register_script();
}
mmi_shared_assets_enqueue( $hook );
$enqueue = new ReflectionMethod( 'MMI_Pipeline_Admin', 'enqueue_assets' );
$enqueue->setAccessible( true );
$enqueue->invoke( null );

// The view to test (a staged copy before deploy, or the live one after).
$view = getenv( 'WB_VIEW' ) ?: MMI_PIPELINE_PATH . 'admin/views/tab-workbench.php';
if ( ! getenv( 'WB_LEGACY' ) && ! property_exists( 'MMI_Pipeline_Admin', 'built_script_problem' ) ) {
    // Pre-deploy: the live class doesn't have the new enqueue yet. Load the build the way it will.
    wp_dequeue_script( 'mmi-pipeline-workbench' );
    $asset = require MMI_PIPELINE_PATH . 'build/workbench.asset.php';
    $deps  = array_values( array_filter( $asset['dependencies'], static fn( $h ) => wp_script_is( $h, 'registered' ) ) );
    wp_enqueue_script( 'mmi-pipeline-workbench-react', MMI_PIPELINE_URL . 'build/workbench.js', $deps, $asset['version'], true );
    $config = [
        'suppliers'        => array_values( array_map( static fn( $id, $s ) => [ 'id' => (string) $id, 'name' => (string) ( $s['supplier_name'] ?? $id ) ],
            array_keys( MMI_Pipeline_Admin::get_configured_suppliers() ), MMI_Pipeline_Admin::get_configured_suppliers() ) ),
        'statuses'         => [ 'publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending', 'private' => 'Private' ],
        'stock'            => [ 'instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'Backorder' ],
        'healthChecks'     => array_values( array_map( static fn( $id, $c ) => [ 'id' => (string) $id, 'label' => (string) $c['label'] ],
            array_keys( \MannMade\DataPipeline\Workbench\Health_Checks::all() ), \MannMade\DataPipeline\Workbench\Health_Checks::all() ) ),
        'perPageOptions'   => \MannMade\DataPipeline\Workbench\Product_Workbench::PER_PAGE_OPTIONS,
        'defaultPerPage'   => 50,
        'maxSelection'     => \MannMade\DataPipeline\Workbench\Product_Workbench::MAX_SELECTION,
        'lockLabels'       => \MannMade\DataPipeline\Workbench\Product_Workbench::lock_labels(),
        'actionLockFields' => \MannMade\DataPipeline\Workbench\Product_Workbench::ACTION_LOCK_FIELDS,
    ];
    wp_localize_script( 'mmi-pipeline-workbench-react', 'mmiWorkbench', $config );
    $source = str_replace( 'MMI_Pipeline_Admin::$built_script_problem', "''", (string) file_get_contents( $view ) );
    $view   = wp_tempnam( 'wb-view' );
    file_put_contents( $view, $source );
}

// Only WordPress core and MMI assets: other plugins' scripts can inline account
// details and REST nonces, which have no place in a test page.
foreach ( [ wp_scripts(), wp_styles() ] as $deps_obj ) {
    foreach ( $deps_obj->queue as $handle ) {
        $src = (string) ( $deps_obj->registered[ $handle ]->src ?? '' );
        if ( $src !== '' && ! preg_match( '#/(wp-includes|wp-admin)/|/plugins/mmi-#', $src ) ) {
            $deps_obj->dequeue( $handle );
        }
    }
}

ob_start();
include $view;
$markup = (string) ob_get_clean();
if ( isset( $source ) ) {
    wp_delete_file( $view );
}

// do_items() prints just the queue and its dependencies, without the print-time
// hooks other plugins use to add their own scripts.
ob_start();
wp_styles()->do_items();
$styles = (string) ob_get_clean();

ob_start();
wp_scripts()->do_items();
$scripts = (string) ob_get_clean();

// Make every URL site-relative so a local server can stand in for the site.
$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$local = static fn( string $html ): string => (string) preg_replace( '#https?:(?://|\\\\/\\\\/)' . preg_quote( $host, '#' ) . '#', '', $html );

echo wp_json_encode( [
    'styles'  => $local( $styles ),
    'scripts' => $local( $scripts ),
    'markup'  => $markup,
] );
