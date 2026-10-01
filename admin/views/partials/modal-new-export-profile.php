<?php
/**
 * New Export Profile Modal
 *
 * Deliberately built on the same wizard chrome that the Import side's
 * profile wizard used to use as a modal, before it was converted to an
 * inline page section (see section-profile-wizard.php and
 * IMPORT_WIZARD_INLINE_SECTION_HANDOFF.md) — mmi-wizard-modal / breadcrumb /
 * panel / footer-nav classes, all already defined in import-settings.css —
 * no new CSS needed for the shell. This modal is unaffected by that
 * conversion and still uses the classic modal-overlay shell. Import and
 * export are two sides of the same coin and should look like it. The one
 * intentional process variation: export has far fewer decisions to make (no scope/identifier/mode steps — scope lives
 * in Step 1 of the Export tab itself), so this is a 2-step wizard (Name,
 * Data Type) instead of Import's 4-step one, and Data Type is presented as
 * a scrollable list of compact cards rather than the Import wizard's 3
 * full-width descriptive cards — with up to ~28 registered data types on a
 * real install, full descriptive cards for every one would be an actual UX
 * regression, not parity.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$_mmi_export_data_type_choices = class_exists( 'MMI_Data_Type_Registry' ) ? MMI_Data_Type_Registry::get_choices_for_ui() : [];

/**
 * Small icon per data type, grouped by key prefix — purely decorative, to
 * give the compact card list some visual texture like the Import wizard's
 * cards have (🌐🏷️✨ etc).
 */
$_mmi_export_type_icon = static function ( string $key ): string {
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

<div class="mmi-modal-backdrop" id="new-export-profile-modal" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="new-export-profile-modal-title">
    <div class="mmi-modal-box mmi-wizard-modal">

        <!-- ── Header ─────────────────────────────────────────────── -->
        <div class="mmi-modal-header mmi-wizard-header">
            <div class="mmi-modal-header-top">
                <h3 id="new-export-profile-modal-title">📤 Create Export Profile</h3>
                <button type="button" class="mmi-modal-close" id="new-export-profile-modal-close" data-close aria-label="Close">&times;</button>
            </div>
            <!-- Step breadcrumb -->
            <nav class="mmi-wizard-breadcrumb" id="mmi-export-wizard-breadcrumb" aria-label="Export profile creation steps">
                <span class="mmi-wizard-crumb mmi-is-active" data-crumb="1">1&thinsp;Name</span>
                <span class="mmi-wizard-crumb-sep">›</span>
                <span class="mmi-wizard-crumb" data-crumb="2">2&thinsp;Data Type</span>
            </nav>
        </div>

        <!-- ── Panel 1 : Name ─────────────────────────────────────── -->
        <div class="mmi-modal-body mmi-wizard-panel" id="mmi-export-wizard-p1" data-panel="1">
            <div class="mmi-modal-field">
                <label for="new-export-profile-name"><strong>Profile Name</strong></label>
                <input type="text" id="new-export-profile-name" class="widefat"
                       placeholder="e.g. Full Catalog Export, Category Price List…" autocomplete="off">
                <p class="description">A short, recognisable name for this export configuration.</p>
            </div>
        </div>

        <!-- ── Panel 2 : Data Type ───────────────────────────────── -->
        <div class="mmi-modal-body mmi-wizard-panel mmi-hidden" id="mmi-export-wizard-p2" data-panel="2">
            <p class="mmi-wizard-panel-intro"><strong>What do you want to export?</strong></p>
            <div class="mmi-scope-choices mmi-scope-choices--scroll" id="mmi-export-data-type-cards">
                <?php foreach ( $_mmi_export_data_type_choices as $key => $label ) : ?>
                    <label class="mmi-scope-card mmi-scope-card--compact" data-data-type="<?php echo esc_attr( $key ); ?>">
                        <input type="radio" name="new_export_data_type" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, 'product' ); ?>>
                        <div class="mmi-scope-card-inner">
                            <span class="mmi-scope-card-icon"><?php echo esc_html( $_mmi_export_type_icon( $key ) ); ?></span>
                            <div><strong><?php echo esc_html( $label ); ?></strong></div>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Footer ─────────────────────────────────────────────── -->
        <div class="mmi-modal-footer mmi-wizard-footer">
            <button type="button" class="mmi-btn-profile" id="new-export-profile-modal-cancel" data-close>Cancel</button>
            <div class="mmi-wizard-footer-nav">
                <button type="button" class="mmi-btn-profile mmi-hidden" id="mmi-export-wizard-back">← Back</button>
                <button type="button" class="button button-primary mmi-action-btn" id="mmi-export-wizard-next">Next →</button>
                <button type="button" class="button button-primary mmi-action-btn mmi-hidden" id="new-export-profile-modal-create">➕ Create Profile</button>
            </div>
        </div>

    </div>
</div>
