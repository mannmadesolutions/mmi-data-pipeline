<?php
/**
 * Feed catalog tab shell, printed by MMI_Pipeline_Feed_Catalog::render().
 * feed-catalog.js fills it from the data-config JSON.
 *
 * @var MMI_Pipeline_Feed_Catalog $catalog
 * @var array                     $config
 * @var string                    $description
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="mmi-process-section mmi-fc" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
    <div class="mmi-section-header">
        <div>
            <h3 class="mmi-process-section-header"><span class="dashicons dashicons-products"></span> <?php echo esc_html( sprintf( __( '%s Catalog', 'mmi-data-pipeline' ), $catalog->label() ) ); ?></h3>
            <p class="mmi-process-section-description"><?php echo esc_html( $description ); ?></p>
        </div>
    </div>
    <div class="mmi-section-content">
        <div class="mmi-fc-fresh" aria-live="polite">
            <div class="mmi-fc-files"><span class="mmi-loading"></span></div>
            <div class="mmi-fc-fresh-actions">
                <span class="mmi-fc-fetch-state"></span>
                <button type="button" class="button button-primary mmi-fc-fetch" disabled><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Fetch now', 'mmi-data-pipeline' ); ?></button>
            </div>
        </div>

        <?php
        // Filters in the shared "Filter sections & pills" layout (the Reverb
        // Products tab's look, mmi-suite-common.css). Facet values come from
        // the index, so feed-catalog.js fills each .mmi-fc-facet: pills for a
        // short list, a .mmi-multiselect for a long one. Every filter takes
        // several values.
        $fixed = [
            'promo'   => [ __( 'Promotion', 'mmi-data-pipeline' ), [ 'active' => __( 'Running now', 'mmi-data-pipeline' ), 'upcoming' => __( 'Coming up', 'mmi-data-pipeline' ), 'none' => __( 'None', 'mmi-data-pipeline' ) ] ],
            'on_site' => [ __( 'Our site', 'mmi-data-pipeline' ), [ 'publish' => __( 'Published', 'mmi-data-pipeline' ), 'other' => __( 'Not published', 'mmi-data-pipeline' ), 'none' => __( 'Not listed', 'mmi-data-pipeline' ) ] ],
        ];
        ?>
        <div class="mmi-filters-section mmi-fc-filters">
            <div class="mmi-filters-row">
                <div class="mmi-filter-section">
                    <span class="mmi-filter-section-heading"><?php esc_html_e( 'Search', 'mmi-data-pipeline' ); ?></span>
                    <div class="mmi-filter-section-body">
                        <input type="search" class="mmi-fc-q" placeholder="<?php esc_attr_e( 'SKU, product, brand…', 'mmi-data-pipeline' ); ?>" />
                        <button type="button" class="button mmi-fc-clear"><?php esc_html_e( 'Clear filters', 'mmi-data-pipeline' ); ?></button>
                        <span class="mmi-fc-count"></span>
                    </div>
                </div>
                <div class="mmi-filter-section">
                    <span class="mmi-filter-section-heading"><?php esc_html_e( 'Product', 'mmi-data-pipeline' ); ?></span>
                    <div class="mmi-filter-section-body">
                        <?php foreach ( $config['facets'] as $key => $all_label ) : ?>
                            <div class="mmi-fc-facet" data-filter="<?php echo esc_attr( $key ); ?>" data-label="<?php echo esc_attr( ucfirst( preg_replace( '/^(All|Any)\s+/i', '', $all_label ) ) ); ?>"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mmi-filter-section">
                    <span class="mmi-filter-section-heading"><?php esc_html_e( 'Availability', 'mmi-data-pipeline' ); ?></span>
                    <div class="mmi-filter-section-body">
                        <?php foreach ( $fixed as $key => [ $label, $options ] ) : ?>
                            <div class="mmi-filter-group">
                                <span class="mmi-filter-label"><?php echo esc_html( $label ); ?></span>
                                <div class="mmi-filter-pills" data-filter="<?php echo esc_attr( $key ); ?>">
                                    <?php foreach ( $options as $value => $text ) : ?>
                                        <button type="button" class="mmi-pill" data-value="<?php echo esc_attr( $value ); ?>" aria-pressed="false"><?php echo esc_html( $text ); ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="mmi-fc-pager mmi-fc-pager--top"></div>
        <div class="mmi-fc-table-wrap">
            <?php // "-pct" in the id: saved widths from the old pixel layout are dropped. ?>
            <table class="mmi-fc-table" id="mmi-fc-<?php echo esc_attr( $catalog->id() ); ?>-table-pct" data-mmi-resizable>
                <thead>
                    <tr>
                        <?php foreach ( $config['columns'] as $col ) : ?>
                            <th data-sort-key="<?php echo esc_attr( $col['key'] ); ?>" data-resize-col="<?php echo esc_attr( $col['key'] ); ?>" class="sortable mmi-fc-col-<?php echo esc_attr( $col['type'] ); ?>"<?php echo $col['title'] !== '' ? ' title="' . esc_attr( $col['title'] ) . '"' : ''; ?>><?php echo esc_html( $col['label'] ); ?><span class="sort-icon"></span></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="mmi-fc-tbody">
                    <tr><td colspan="<?php echo (int) count( $config['columns'] ); ?>" class="mmi-fc-empty"><span class="mmi-loading"></span> <?php esc_html_e( 'Loading the catalog…', 'mmi-data-pipeline' ); ?></td></tr>
                </tbody>
            </table>
        </div>
        <div class="mmi-fc-pager mmi-fc-pager--bottom"></div>
    </div>
</div>
