<?php
/**
 * Merged Process Logs Section
 *
 * Combines fetch logs and import logs into a single collapsible section.
 * Expects $all_sources to be set by the parent view.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// $all_sources is NOT set by tab-pipeline.php itself — it's set by a
// sibling include, pipeline-step-1-acquisition.php (included earlier, line
// 735 vs. this file's line 786), and reaches this file only because both
// are bare `include`s sharing tab-pipeline.php's own variable scope. Works
// today because of include ORDER, not a real contract — a future reorder
// of those two includes, or wrapping tab-pipeline.php's body in a
// function/method, would silently break this. Guarded here so that
// breakage degrades to "no filter options" instead of a PHP warning.
$all_sources = $all_sources ?? [];
?>

<div class="mmi-process-section mmi-collapsible-section collapsed mmi-logs-section-hidden" id="mmi-logs-section">
    <div class="mmi-section-header mmi-process-section-header-row mmi-section-clickable">
        <div>
            <h3 class="mmi-process-section-header">
                <span class="dashicons dashicons-media-text"></span>
                Process Logs
                <span class="mmi-collapse-toggle">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </span>
            </h3>
            <p class="mmi-process-section-description">Execution logs for fetch, import, and catalog update operations</p>
        </div>
        <div class="mmi-process-actions">
            <label for="log-supplier-filter" class="log-filter-label">Filter:</label>
            <select id="log-supplier-filter" class="mmi-schedule-select-inline log-supplier-filter">
                <option value="all">All Processes</option>
                <?php if ( ! empty( $all_sources ) ) : ?>
                    <?php foreach ( $all_sources as $src ) : ?>
                        <option value="<?php echo esc_attr( $src['supplier_id'] ); ?>">
                            <?php echo esc_html( $src['supplier_name'] ); ?> — Fetch
                        </option>
                        <option value="<?php echo esc_attr( $src['supplier_id'] ); ?>_import">
                            <?php echo esc_html( $src['supplier_name'] ); ?> — Import
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
                <option value="catalog_update">Catalog Update</option>
            </select>
            <button type="button" class="button mmi-action-btn log-refresh-button" id="refresh-logs">
                <span class="dashicons dashicons-update"></span> Refresh
            </button>
            <button type="button" class="button mmi-action-btn" id="hide-logs">
                <span class="dashicons dashicons-no-alt"></span> Hide
            </button>
        </div>
    </div>

    <div class="mmi-section-content">
        <div id="log-content-wrapper" class="log-content-wrapper">
            <pre id="log-content">Loading logs…</pre>
        </div>
    </div>
</div>
