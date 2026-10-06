<?php
/**
 * Run Insights modal — one import run's results and why its records failed
 *
 * Rendered once by main.php, on every Data Pipeline tab, so a page notice's
 * "Investigate" button works wherever the notice shows. Body is filled by
 * assets/js/import-run-insights.js from mmi_pipeline_run_insights
 * (RunInsightsController.php). Opened from Import History status badges and
 * from the page notices.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div id="mmi-run-insights-modal" class="mmi-modal-backdrop" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-run-insights-title">
    <div class="mmi-modal mmi-modal--xlarge mmi-run-insights">
        <div class="mmi-modal-header">
            <h3 id="mmi-run-insights-title">Import Run</h3>
            <button type="button" class="mmi-modal-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="mmi-modal-body" id="mmi-run-insights-body">
            <p class="mmi-modal-loading">Loading run details&hellip;</p>
        </div>
        <div class="mmi-modal-footer">
            <button type="button" class="button" data-close>Close</button>
        </div>
    </div>
</div>
