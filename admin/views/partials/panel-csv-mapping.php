<?php
/**
 * CSV Column Mapping Panel (Slide-out)
 * Configure CSV column-to-field mappings for file-based data sources
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get available CSV files from data sources.
// file_format lives in the file_config column (format key), NOT in configuration.
// URL lives in configuration.base_url.
global $wpdb;
$csv_sources = [];
$_ds_table = $wpdb->prefix . 'mmi_data_sources';
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$_ds_table}'" ) === $_ds_table ) {
    $_csv_rows = $wpdb->get_results(
        "SELECT supplier_id, configuration, file_config FROM {$_ds_table}",
        ARRAY_A
    );
    foreach ( $_csv_rows as $_row ) {
        $_file_cfg = json_decode( $_row['file_config'] ?: '{}', true ) ?: [];
        if ( ( $_file_cfg['format'] ?? '' ) !== 'csv' ) {
            continue;
        }
        $_conn_cfg = json_decode( $_row['configuration'] ?: '{}', true ) ?: [];
        $csv_sources[ $_row['supplier_id'] ] = [
            'url'          => $_conn_cfg['base_url'] ?? '',
            'csv_delimiter'  => $_file_cfg['delimiter'] ?? ',',
            'csv_has_header' => $_file_cfg['has_header'] ?? true,
        ];
    }
}
?>

<!-- CSV MAPPING PANEL -->
<div id="csv-mapping-panel" class="mmi-config-panel mmi-panel-hidden">
    <div class="mmi-panel-header">
        <h3>📊 CSV Column Mapping</h3>
        <span class="autosave-badge mmi-badge success">
            <span class="dashicons dashicons-yes-alt"></span>
            Auto-save enabled
        </span>
        <button type="button" class="mmi-panel-close" data-panel="csv-mapping-panel">
            <span class="dashicons dashicons-no-alt"></span>
        </button>
    </div>

    <div class="mmi-panel-content">
        <?php if (empty($csv_sources)): ?>
            <div class="mmi-empty-state">
                <span class="dashicons dashicons-media-spreadsheet"></span>
                <h3>No CSV Data Sources Configured</h3>
                <p>Go to <strong>Step 1: Data Fetch & Sources</strong> to configure CSV-based data sources for your suppliers.</p>
                <p>Once configured, you can map CSV columns to WooCommerce fields here.</p>
            </div>
        <?php else: ?>
            <p class="mmi-section-description">
                Map CSV column headers to WooCommerce product fields. Column names are case-sensitive and should match your CSV file headers exactly.
                <strong>Changes are automatically saved.</strong>
            </p>

            <?php foreach ($csv_sources as $supplier => $config): ?>
                <div class="mmi-csv-supplier-section">
                    <h3 class="mmi-csv-supplier-header">
                        <span class="mmi-badge mmi-badge-truncate mmi-badge-supplier-<?php echo esc_attr($supplier); ?>">
                            <?php echo ucfirst($supplier); ?>
                        </span>
                        <span class="mmi-csv-file-path"><?php echo esc_html($config['url'] ?? 'No URL configured'); ?></span>
                    </h3>

                    <div class="mmi-csv-preview-container">
                        <button type="button" class="button mmi-btn-load-csv-preview mmi-action-btn" data-supplier="<?php echo esc_attr($supplier); ?>">
                            <span class="dashicons dashicons-visibility"></span> Load CSV Preview
                        </button>
                        <div class="mmi-csv-preview-table mmi-csv-preview-hidden" id="csv-preview-<?php echo esc_attr($supplier); ?>">
                            <!-- CSV preview will be loaded here via AJAX -->
                        </div>
                    </div>

                    <div class="mmi-csv-mapping-container">
                        <?php
                        // Get existing CSV mappings for this supplier
                        $csv_mappings = MMI_DB::get_csv_mappings( $supplier );
                        $delimiter = $config['csv_delimiter'] ?? ',';
                        $has_header = $config['csv_has_header'] ?? true;
                        ?>

                        <div class="mmi-csv-config-row">
                            <label class="mmi-csv-config-label">
                                <strong>CSV Delimiter:</strong>
                                <select class="mmi-csv-delimiter-select" data-supplier="<?php echo esc_attr($supplier); ?>">
                                    <option value="," <?php selected($delimiter, ','); ?>>Comma (,)</option>
                                    <option value=";" <?php selected($delimiter, ';'); ?>>Semicolon (;)</option>
                                    <option value="\t" <?php selected($delimiter, "\t"); ?>>Tab (\t)</option>
                                    <option value="|" <?php selected($delimiter, '|'); ?>>Pipe (|)</option>
                                </select>
                            </label>
                            <label class="mmi-csv-config-label">
                                <input type="checkbox"
                                       class="mmi-csv-has-header-checkbox"
                                       data-supplier="<?php echo esc_attr($supplier); ?>"
                                       <?php checked($has_header); ?>>
                                <strong>CSV has header row</strong>
                            </label>
                        </div>

                        <h4>Column Mappings</h4>
                        <p class="mmi-field-note">Map CSV columns to WooCommerce fields. Column names should match your CSV headers exactly (case-sensitive).</p>

                        <table class="mmi-csv-mapping-table">
                            <thead>
                                <tr>
                                    <th>WooCommerce Field</th>
                                    <th>CSV Column Name</th>
                                    <th>Transform</th>
                                    <th>Preview</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Core fields for CSV import
                                $csv_fields = [
                                    'name' => ['label' => 'Product Name', 'required' => true],
                                    'sku' => ['label' => 'SKU', 'required' => true],
                                    'description' => ['label' => 'Description', 'required' => false],
                                    'short_description' => ['label' => 'Short Description', 'required' => false],
                                    'regular_price' => ['label' => 'Regular Price', 'required' => true],
                                    'sale_price' => ['label' => 'Sale Price', 'required' => false],
                                    '_stock_quantity' => ['label' => 'Stock Quantity', 'required' => false],
                                    '_stock_status' => ['label' => 'Stock Status', 'required' => false],
                                    'images' => ['label' => 'Image URLs', 'required' => false],
                                    'categories' => ['label' => 'Categories', 'required' => false],
                                    'tags' => ['label' => 'Tags', 'required' => false],
                                ];

                                foreach ($csv_fields as $field_name => $field_info):
                                    $mapping = $csv_mappings[$field_name] ?? [];
                                    $column_name = $mapping['column'] ?? '';
                                    $transform = $mapping['transform'] ?? 'none';
                                ?>
                                    <tr data-field="<?php echo esc_attr($field_name); ?>">
                                        <td>
                                            <strong><?php echo esc_html($field_info['label']); ?></strong>
                                            <?php if ($field_info['required']): ?>
                                                <span class="field-required">*</span>
                                            <?php endif; ?>
                                            <br>
                                            <code class="mmi-pipeline-field-code"><?php echo esc_html($field_name); ?></code>
                                        </td>
                                        <td>
                                            <input type="text"
                                                   class="mmi-csv-column-input"
                                                   name="csv_mappings[<?php echo esc_attr($supplier); ?>][<?php echo esc_attr($field_name); ?>][column]"
                                                   value="<?php echo esc_attr($column_name); ?>"
                                                   placeholder="e.g., Product Name, SKU"
                                                   data-supplier="<?php echo esc_attr($supplier); ?>"
                                                   data-field="<?php echo esc_attr($field_name); ?>">
                                        </td>
                                        <td>
                                            <select class="mmi-csv-transform-select"
                                                    name="csv_mappings[<?php echo esc_attr($supplier); ?>][<?php echo esc_attr($field_name); ?>][transform]"
                                                    data-supplier="<?php echo esc_attr($supplier); ?>"
                                                    data-field="<?php echo esc_attr($field_name); ?>">
                                                <option value="none" <?php selected($transform, 'none'); ?>>None</option>
                                                <option value="uppercase" <?php selected($transform, 'uppercase'); ?>>UPPERCASE</option>
                                                <option value="lowercase" <?php selected($transform, 'lowercase'); ?>>lowercase</option>
                                                <option value="title_case" <?php selected($transform, 'title_case'); ?>>Title Case</option>
                                                <option value="to_decimal" <?php selected($transform, 'to_decimal'); ?>>To Decimal</option>
                                                <option value="to_int" <?php selected($transform, 'to_int'); ?>>To Integer</option>
                                                <option value="strip_html" <?php selected($transform, 'strip_html'); ?>>Strip HTML</option>
                                                <option value="sanitize_url" <?php selected($transform, 'sanitize_url'); ?>>Sanitize URL</option>
                                                <option value="comma_to_array" <?php selected($transform, 'comma_to_array'); ?>>Comma to Array</option>
                                            </select>
                                        </td>
                                        <td>
                                            <span class="mmi-csv-preview-value" data-field="<?php echo esc_attr($field_name); ?>">—</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="mmi-csv-actions">
                            <button type="button" class="button mmi-btn-test-csv-mapping mmi-action-btn" data-supplier="<?php echo esc_attr($supplier); ?>">
                                <span class="dashicons dashicons-admin-links"></span> Test CSV Mapping
                            </button>
                            <button type="button" class="button mmi-btn-auto-detect-columns mmi-action-btn" data-supplier="<?php echo esc_attr($supplier); ?>">
                                <span class="dashicons dashicons-search"></span> Auto-detect Columns
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
