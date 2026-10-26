<?php
/**
 * Export tab — Site export section: package a whole WordPress install and
 * hand its owner a signed, expiring download link (MMI_Site_Export).
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$mmi_site_export_targets = MMI_Site_Export::targets();
?>
<div class="mmi-process-section" data-section="site-export" id="mmi-site-export">
    <div class="mmi-section-header mmi-process-section-header-row">
        <div>
            <h3 class="mmi-process-section-header">
                <span class="dashicons dashicons-archive"></span>
                Site export
            </h3>
            <p class="mmi-process-section-description">A complete copy of a WordPress site in one ZIP: its files, full database, a content export and steps for moving it to another host. Copy link gives its owner a private download link; the package is deleted when the link expires.</p>
        </div>
        <div class="mmi-site-export-controls">
            <label class="screen-reader-text" for="mmi-site-export-target">Site</label>
            <select id="mmi-site-export-target">
                <?php foreach ( $mmi_site_export_targets as $key => $target ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $target['label'] ); ?></option>
                <?php endforeach; ?>
            </select>
            <label class="mmi-site-export-days">
                Link valid for
                <input type="number" id="mmi-site-export-days" min="1" max="<?php echo (int) MMI_Site_Export::MAX_LINK_DAYS; ?>" value="<?php echo (int) MMI_Site_Export::DEFAULT_LINK_DAYS; ?>">
                days
            </label>
            <button type="button" class="button button-primary" id="mmi-site-export-start">Export site</button>
        </div>
    </div>
    <div class="mmi-section-content">
        <p class="mmi-site-export-notice" id="mmi-site-export-notice" role="status" hidden></p>
        <div class="mmi-table-scroll-wrapper">
            <table class="mmi-uniform-table" id="mmi-site-export-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Site</th>
                        <th>Started</th>
                        <th>Status</th>
                        <th>Size</th>
                        <th>Link expires</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="mmi-site-export-rows">
                    <?php echo MMI_Site_Export::render_rows(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_rows(). ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
