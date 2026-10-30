<?php
/**
 * Product Workbench tab
 *
 * Find products with the suite's condition builder (plus a quick
 * title/SKU search and any post status), then change them: Once (a preview
 * first and Undo after), or Keep applying as a standing rule that every
 * catalog run applies again. Standing rules and catalog upkeep (the old
 * Catalog Maintenance) are sections of this screen.
 *
 * The screen is a React app (src/workbench, built to build/workbench.js).
 * This view prints its mount point plus the two shared jQuery components it
 * hosts, so they stay identical to Field Mapping's: the suite-wide
 * condition builder and the change picker. They
 * wait in a hidden holder until the app moves them into place. Server side:
 * MannMade\DataPipeline\Workbench\Product_Workbench.
 *
 * @package MannMade\DataPipeline
 */

use MannMade\DataPipeline\Rule_Builder_View;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="mmi-workbench" id="mmi-workbench">

    <?php if ( ! MMI_Pipeline_Admin::is_woocommerce_active() ) : ?>
        <div class="notice notice-warning inline"><p>Product Workbench works on WooCommerce products — activate WooCommerce to use it.</p></div>
    <?php elseif ( MMI_Pipeline_Admin::$built_script_problem !== '' ) : ?>
        <div class="notice notice-error inline"><p><?php echo esc_html( 'Product Workbench could not load: ' . MMI_Pipeline_Admin::$built_script_problem ); ?></p></div>
    <?php else : ?>

    <div id="mmi-wb-app">
        <p class="mmi-wb-empty">Loading Product Workbench…</p>
        <noscript><p class="mmi-wb-empty">Product Workbench needs JavaScript.</p></noscript>
    </div>

    <div class="mmi-wb-islands" hidden>
        <div id="mmi-wb-island-conditions" class="mmi-wb-island">
            <?php
            // The suite-wide condition builder (same as Field Mapping), with
            // its own match logic here. The app
            // renders Save search into the slot.
            MMI_Condition_Builder::render( [
                'sources'      => Rule_Builder_View::source_groups( Rule_Builder_View::sources( MMI_Pipeline_Admin::get_configured_suppliers() ) ),
                'match_logic'  => 'all',
                'label'        => 'Conditions',
                'empty_hint'   => 'No conditions — the search uses only the fields above.',
                'context'      => 'workbench',
                'class'        => 'mmi-wb-conditions',
                'actions_html' => '<span id="mmi-wb-save-search-slot"></span>',
                'ids'          => [ 'root' => 'mmi-wb-cb', 'list' => 'mmi-wb-conditions', 'add' => 'mmi-wb-add-condition', 'match_logic' => 'mmi-wb-match-logic' ],
            ] );
            ?>
        </div>
        <div id="mmi-wb-island-action" class="mmi-wb-island">
            <div class="mmi-wb-action" id="mmi-wb-action-scope">
                <select class="mmi-custom-rule-action" id="mmi-wb-action" aria-label="What to change">
                    <?php // Force In/Out of Stock included: the app enables it only for Keep applying. ?>
                    <?php Rule_Builder_View::action_options( 'set_taxonomy_term' ); ?>
                </select>
                <div class="mmi-custom-rule-params">
                    <?php Rule_Builder_View::action_params( [ 'taxonomy' => 'product_cat', 'mode' => 'replace' ] ); ?>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>
