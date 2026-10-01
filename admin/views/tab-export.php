<?php
/**
 * Export Tab - Data Export Workflow
 *
 * Deliberately structured as a mirror of tab-pipeline.php (Import) — same
 * profile-section-bar / profile-cards-grid / topbar-with-collapsible-panel /
 * step-sections shape — since import and export are two sides of the same
 * coin and should look like the same feature, not two differently-designed
 * screens bolted together. Reads live records from the DB via a
 * MMI_Data_Type_Handler instead of an external supplier feed, and has one
 * collapsible topbar panel (Schedule) instead of Import's four (Schedule,
 * Stock Overrides, Catalog Rules, Attributes) — those three are
 * product-import-specific concepts with no export equivalent.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$export_profiles = MMI_DB::get_profiles_by_direction( 'export' );
$current_profile = isset( $_GET['profile'] ) ? sanitize_text_field( wp_unslash( $_GET['profile'] ) ) : '';
if ( ! isset( $export_profiles[ $current_profile ] ) ) {
    $current_profile = array_key_first( $export_profiles ) ?? '';
}
$current_profile_meta = $export_profiles[ $current_profile ] ?? [];
$current_data_type    = $current_profile_meta['data_type'] ?? 'product';

$data_type_choices = class_exists( 'MMI_Data_Type_Registry' ) ? MMI_Data_Type_Registry::get_choices_for_ui() : [];

$export_schedule_frequencies = [
    'disabled'   => 'Disabled',
    'hourly'     => 'Every Hour',
    'twicedaily' => 'Twice Daily',
    'daily'      => 'Daily',
    'weekly'     => 'Weekly',
];
$export_freq   = MMI_DB::get_setting( 'mmi_schedule_export_profile_' . $current_profile, 'disabled' );
$export_format = MMI_DB::get_setting( 'mmi_schedule_export_format_'  . $current_profile, 'csv' );
$export_state  = ( $current_profile !== '' && class_exists( 'MMI_Pipeline_Export_Cron' ) )
    ? MMI_Pipeline_Export_Cron::get_profile_state( $current_profile )
    : null;

// Self-heal: frequency saved but event missing — re-register it now (mirrors
// the Import tab's identical self-heal for mmi_pipeline_profile_{id}).
if ( $current_profile !== '' && $export_freq !== 'disabled' ) {
    $export_hook = 'mmi_pipeline_export_profile_' . $current_profile;
    if ( ! wp_next_scheduled( $export_hook ) ) {
        $wp_sched = wp_get_schedules();
        if ( isset( $wp_sched[ $export_freq ] ) ) {
            wp_schedule_event( time(), $export_freq, $export_hook );
        }
    }
}

$export_history = MMI_DB::get_export_history( 100 );

?>

<div class="mmi-data-pipeline-container mmi-export-container">

    <!-- ── Hidden profile selector — mirrors the Import tab's pattern ───── -->
    <?php if ( ! empty( $export_profiles ) ) : ?>
    <select id="mmi-export-profile" class="mmi-profile-select mmi-profile-select-hidden" aria-hidden="true" tabindex="-1" hidden>
        <?php foreach ( $export_profiles as $pid => $pdata ) :
            $selected = ( $pid === $current_profile ) ? ' selected="selected"' : '';
            echo '<option value="' . esc_attr( $pid ) . '"' . $selected . '>' . esc_html( $pdata['name'] ) . '</option>';
        endforeach; ?>
    </select>
    <?php endif; ?>

    <!-- ── Export Profile Cards ──────────────────────────────────────────── -->
    <div class="mmi-profile-section mmi-flat-card">

        <!-- Section bar: active-profile identity + management actions -->
        <div class="mmi-profile-section-bar">
            <?php if ( ! empty( $export_profiles ) ) : ?>
            <div class="mmi-psb-identity">
                <span class="mmi-psb-name" id="mmi-export-topbar-active-name">
                    <?php echo esc_html( $current_profile_meta['name'] ?? $current_profile ); ?>
                </span>
                <span class="mmi-pgc-mode-badge mmi-psb-mode-badge"
                      id="mmi-export-profile-datatype-badge"
                      style="--badge-raw-color:69, 133, 44"
                      data-data-type="<?php echo esc_attr( $current_data_type ); ?>">
                    <?php echo esc_html( $data_type_choices[ $current_data_type ] ?? $current_data_type ); ?>
                </span>
            </div>
            <div class="mmi-psb-actions">
                <button type="button" class="mmi-btn-profile" id="edit-export-profile-btn">
                    <span class="dashicons dashicons-edit"></span> Edit
                </button>
                <button type="button" class="mmi-btn-profile" id="duplicate-export-profile-btn" title="Duplicate active export profile">
                    <span class="dashicons dashicons-admin-page"></span>
                </button>
                <button type="button" class="mmi-btn-profile mmi-btn-profile--danger" id="delete-export-profile-btn" title="Delete active export profile">
                    <span class="dashicons dashicons-trash"></span>
                </button>
                <span class="mmi-psb-divider" aria-hidden="true"></span>
                <button type="button" class="mmi-btn-profile mmi-btn-profile--primary" id="new-export-profile-btn">
                    <span class="dashicons dashicons-plus-alt2"></span> New
                </button>
            </div>
            <?php else : ?>
            <div class="mmi-psb-identity">
                <span class="mmi-psb-empty">No export profiles yet</span>
            </div>
            <div class="mmi-psb-actions">
                <button type="button" class="mmi-btn-profile mmi-btn-profile--primary" id="new-export-profile-btn">
                    <span class="dashicons dashicons-plus-alt2"></span> Create First Profile
                </button>
            </div>
            <?php endif; ?>
        </div><!-- /.mmi-profile-section-bar -->

        <table class="mmi-profile-cards-grid mmi-uniform-table mmi-uniform-table--hoverable">
            <?php if ( ! empty( $export_profiles ) ) : ?>
            <thead>
                <tr>
                    <th class="mmi-pgc-col-name">Name</th>
                    <th class="mmi-pgc-col-mode">Data Type</th>
                    <th class="mmi-pgc-col-schedule">Schedule</th>
                </tr>
            </thead>
            <?php endif; ?>
            <tbody id="mmi-export-profile-cards-grid">
            <?php if ( empty( $export_profiles ) ) : ?>
                <tr>
                    <td class="mmi-pgc-empty-state">
                        <p>No export profiles yet.</p>
                        <button type="button" class="button button-primary mmi-action-btn" id="no-export-profiles-create-btn-grid">➕ Create First Export Profile</button>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $export_profiles as $pid => $pdata ) :
                    $p_data_type = $pdata['data_type'] ?? 'product';
                    $p_label     = $data_type_choices[ $p_data_type ] ?? $p_data_type;
                    $is_active   = ( $pid === $current_profile );
                    $p_freq      = MMI_DB::get_setting( 'mmi_schedule_export_profile_' . $pid, 'disabled' );
                    $p_next      = wp_next_scheduled( 'mmi_pipeline_export_profile_' . $pid );
                    $p_sched_label = $p_next ? human_time_diff( $p_next, current_time( 'timestamp' ) ) : null;
                ?>
                <tr class="mmi-profile-grid-card mmi-uniform-row--selectable<?php echo $is_active ? ' is-active' : ''; ?>"
                    data-profile-id="<?php echo esc_attr( $pid ); ?>"
                    data-profile-name="<?php echo esc_attr( $pdata['name'] ); ?>"
                    data-data-type="<?php echo esc_attr( $p_data_type ); ?>"
                    role="button" tabindex="0"
                    aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>">
                    <td class="mmi-pgc-col-name">
                        <span class="mmi-pgc-name"><?php echo esc_html( $pdata['name'] ); ?></span>
                    </td>
                    <td class="mmi-pgc-col-mode">
                        <span class="mmi-pgc-mode-badge" style="--badge-raw-color:69, 133, 44">
                            <?php echo esc_html( $p_label ); ?>
                        </span>
                    </td>
                    <td class="mmi-pgc-col-schedule">
                        <?php if ( $p_sched_label ) : ?>
                        <span class="mmi-pgc-schedule">
                            <span class="dashicons dashicons-clock mmi-pgc-sched-icon"></span>
                            Next: <?php echo esc_html( $p_sched_label ); ?>
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table><!-- /.mmi-profile-cards-grid -->

        <?php if ( ! empty( $export_profiles ) ) : ?>
        <!-- ── Export Topbar: configure + run ───────────────────────────── -->
        <div class="mmi-pipeline-topbar mmi-topbar-standalone">
        <div class="mmi-topbar-main-row">
            <div class="mmi-topbar-actions">
                <div class="mmi-topbar-util-actions">
                    <button type="button" class="button mmi-action-btn mmi-schedule-toggle-btn" id="mmi-export-schedule-toggle">
                        <span class="dashicons dashicons-clock"></span> Schedule
                        <?php if ( $export_freq !== 'disabled' ) : ?>
                            <span class="mmi-badge info"><?php echo esc_html( $export_schedule_frequencies[ $export_freq ] ?? $export_freq ); ?></span>
                        <?php endif; ?>
                    </button>
                </div>
                <span class="mmi-topbar-action-divider" aria-hidden="true"></span>
                <div class="mmi-topbar-run-actions">
                    <label class="mmi-topbar-format-label" for="mmi-export-format">
                        Format
                        <select id="mmi-export-format" class="mmi-topbar-format-select">
                            <option value="csv" <?php selected( $export_format, 'csv' ); ?>>CSV</option>
                            <option value="json" <?php selected( $export_format, 'json' ); ?>>JSON</option>
                            <option value="xml" <?php selected( $export_format, 'xml' ); ?>>XML</option>
                        </select>
                    </label>
                    <button type="button" class="button button-primary mmi-action-btn" id="run-manual-export">
                        <span class="dashicons dashicons-controls-play"></span> Run Export Now
                    </button>
                </div>
            </div>
        </div><!-- /.mmi-topbar-main-row -->

            <div class="mmi-topbar-status-row">
                <div id="manual-export-status" class="mmi-pipeline-status-panel"></div>
            </div>

            <!-- Collapsible schedule panel (starts hidden, toggled by #mmi-export-schedule-toggle) -->
            <div class="mmi-topbar-schedule-panel" id="mmi-export-topbar-schedule-panel">
                <table class="mmi-topbar-schedule-table">
                    <thead>
                        <tr>
                            <th>Process</th>
                            <th>Recurrence</th>
                            <th>Format</th>
                            <th>Last Run</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="mmi-tsched-row">
                            <td>
                                <div class="mmi-tsched-process">
                                    <span class="dashicons dashicons-database-export mmi-tsched-icon mmi-tsched-icon--fetch"></span>
                                    <?php echo esc_html( $current_profile_meta['name'] ?? $current_profile ); ?>
                                </div>
                            </td>
                            <td>
                                <select id="mmi-export-schedule-frequency"
                                        class="mmi-schedule-select mmi-autosave-schedule"
                                        data-profile="<?php echo esc_attr( $current_profile ); ?>">
                                    <?php foreach ( $export_schedule_frequencies as $val => $lbl ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $export_freq, $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select id="mmi-export-schedule-format" class="mmi-schedule-select mmi-autosave-schedule">
                                    <option value="csv" <?php selected( $export_format, 'csv' ); ?>>CSV</option>
                                    <option value="json" <?php selected( $export_format, 'json' ); ?>>JSON</option>
                                    <option value="xml" <?php selected( $export_format, 'xml' ); ?>>XML</option>
                                </select>
                            </td>
                            <td class="mmi-tsched-next">
                                <?php if ( $export_state ) : ?>
                                    <span class="history-status-badge mmi-badge <?php echo $export_state['status'] === 'complete' ? 'success' : ( $export_state['status'] === 'failed' ? 'error' : 'in-progress' ); ?>">
                                        <?php echo esc_html( ucfirst( $export_state['status'] ) ); ?>
                                    </span>
                                    <?php if ( ! empty( $export_state['completed_at'] ) ) : ?>
                                        <?php echo esc_html( human_time_diff( strtotime( $export_state['completed_at'] ), current_time( 'timestamp' ) ) ); ?> ago
                                    <?php endif; ?>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div><!-- /.mmi-topbar-schedule-panel -->

        </div><!-- /.mmi-pipeline-topbar.mmi-topbar-standalone -->
        <?php endif; ?>

    </div><!-- /.mmi-profile-section -->

    <?php if ( ! empty( $export_profiles ) ) : ?>

    <!-- ── Sortable Sections Container ─────────────────────────────────── -->
    <div id="mmi-export-sections">

        <!-- ── Data Type & Scope ────────────────────────────────────────── -->
        <div class="mmi-sortable-item mmi-process-section mmi-collapsible-section" data-section="export-scope">
            <div class="mmi-section-header mmi-process-section-header-row mmi-section-clickable">
                <div>
                    <h3 class="mmi-process-section-header">
                        <span class="dashicons dashicons-filter"></span>
                        Data Type &amp; Scope
                        <span class="mmi-collapse-toggle"><span class="dashicons dashicons-arrow-down-alt2"></span></span>
                    </h3>
                    <p class="mmi-process-section-description">Choose what to export and narrow it down with filters</p>
                </div>
            </div>
            <div class="mmi-section-content">
                <div class="mmi-scope-layout">
                    <div class="mmi-scope-layout-filters">
                        <div class="mmi-modal-field">
                            <label for="mmi-export-data-type"><strong>Data Type</strong></label>
                            <select id="mmi-export-data-type" class="widefat" data-current-value="<?php echo esc_attr( $current_data_type ); ?>">
                                <?php foreach ( $data_type_choices as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $current_data_type ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Changing this here previews a different type without switching your saved profile — use <strong>Edit</strong> above to change the profile's actual data type.</p>
                        </div>
                        <div id="mmi-export-scope-fields">
                            <?php include __DIR__ . '/partials/export-scope-fields.php'; ?>
                        </div>
                    </div>
                    <div class="mmi-scope-layout-preview">
                        <?php include __DIR__ . '/partials/export-preview-table.php'; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Export History ────────────────────────────────────────────── -->
        <div class="mmi-sortable-item mmi-process-section mmi-collapsible-section collapsed" data-section="export-history">
            <div class="mmi-section-header mmi-process-section-header-row mmi-section-clickable">
                <div>
                    <h3 class="mmi-process-section-header">
                        <span class="dashicons dashicons-backup"></span>
                        Export History
                        <span class="mmi-collapse-toggle"><span class="dashicons dashicons-arrow-down-alt2"></span></span>
                    </h3>
                    <p class="mmi-process-section-description">Last <?php echo count( $export_history ); ?> export run<?php echo count( $export_history ) !== 1 ? 's' : ''; ?> and their results</p>
                </div>
            </div>
            <div class="mmi-section-content">
                <?php if ( ! empty( $export_history ) ) : ?>
                    <?php
                    $history_profile_ids = array_unique( array_column( $export_history, 'profile_id' ) );
                    sort( $history_profile_ids );
                    ?>
                    <?php if ( count( $history_profile_ids ) > 1 ) : ?>
                    <div class="mmi-history-filter" id="mmi-export-history-filter">
                        <span class="mmi-history-filter-label">Filter:</span>
                        <button type="button" class="mmi-history-filter-btn is-active" data-filter-profile="">All Profiles</button>
                        <?php foreach ( $history_profile_ids as $hpid ) :
                            $hpname = $export_profiles[ $hpid ]['name'] ?? ucfirst( $hpid );
                        ?>
                        <button type="button" class="mmi-history-filter-btn" data-filter-profile="<?php echo esc_attr( $hpid ); ?>">
                            <?php echo esc_html( $hpname ); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="mmi-history-table-wrap">
                    <table class="mmi-history-table mmi-export-history-table mmi-uniform-table mmi-uniform-table--hoverable" id="mmi-export-history-table">
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Profile</th>
                                <th>Data Type</th>
                                <th>Format</th>
                                <th>Trigger</th>
                                <th>Exported</th>
                                <th>Failed</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="mmi-export-history-tbody">
                            <?php foreach ( $export_history as $entry ) :
                                $entry_profile_id   = $entry['profile_id'] ?? '';
                                $entry_profile_name = $export_profiles[ $entry_profile_id ]['name'] ?? ucfirst( $entry_profile_id );
                                $status             = $entry['status'] ?? 'Unknown';
                                $badge_class = match ( true ) {
                                    $status === 'Success' => 'success',
                                    $status === 'Partial' => 'partial',
                                    $status === 'Failed'  => 'error',
                                    default               => 'unknown',
                                };
                                $display_date = ! empty( $entry['started_at'] ) ? wp_date( 'M j, Y g:i a', strtotime( $entry['started_at'] ) ) : '—';
                            ?>
                                <tr data-profile="<?php echo esc_attr( $entry_profile_id ); ?>">
                                    <td>
                                        <abbr title="<?php echo esc_attr( $entry['started_at'] ?? '' ); ?>">
                                            <?php echo esc_html( $display_date ); ?>
                                        </abbr>
                                    </td>
                                    <td><?php echo esc_html( $entry_profile_name ); ?></td>
                                    <td><?php echo esc_html( $data_type_choices[ $entry['data_type'] ?? '' ] ?? ( $entry['data_type'] ?? '—' ) ); ?></td>
                                    <td><?php echo esc_html( strtoupper( $entry['format'] ?? '—' ) ); ?></td>
                                    <td><?php echo esc_html( ucfirst( $entry['trigger'] ?? 'manual' ) ); ?></td>
                                    <td><?php echo number_format( $entry['records_exported'] ?? 0 ); ?></td>
                                    <td><?php echo number_format( $entry['records_failed'] ?? 0 ); ?></td>
                                    <td>
                                        <span class="history-status-badge mmi-badge <?php echo esc_attr( $badge_class ); ?>"
                                            <?php if ( ! empty( $entry['error'] ) ) : ?>title="<?php echo esc_attr( $entry['error'] ); ?>"<?php endif; ?>>
                                            <?php echo esc_html( $status ); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else : ?>
                    <p class="mmi-empty-state">
                        <span class="dashicons dashicons-info"></span>
                        No export history yet. Run your first export to see results here.
                    </p>
                <?php endif; ?>
            </div>
        </div><!-- /.mmi-process-section[export-history] -->

    </div><!-- /#mmi-export-sections -->

    <?php endif; ?>

    <?php include __DIR__ . '/partials/modal-new-export-profile.php'; ?>
    <?php include __DIR__ . '/partials/modal-edit-export-profile.php'; ?>

</div>
