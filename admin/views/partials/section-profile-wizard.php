<?php
/**
 * Shared Partial: Import Profile Wizard — inline section (multi-step)
 *
 * Inline replacement for the old #new-profile-modal overlay — see
 * IMPORT_WIZARD_INLINE_SECTION_HANDOFF.md for the full rationale/build
 * order this partial implements. Lives in normal page flow as a sibling of
 * the Import Profiles section (tab-pipeline.php), hidden by default
 * (.mmi-wizard-section's own display:none — see import-settings.css'
 * "WIZARD SECTION (INLINE)" block) and expanded via slideDown() +
 * scrollIntoView() in openProfileWizardShow() (import-settings.js) when
 * #new-profile-btn / #edit-profile-btn / #no-profiles-create-btn is
 * clicked. The container keeps the SAME id, #new-profile-modal, it had as
 * a modal — every autosave-scoping/close-flush function that reads
 * `$('#new-profile-modal').is(':visible')` (see autosaveScopeProfile() and
 * flushAllPendingWizardAutosaves()'s call sites) keeps working unchanged,
 * since jQuery's :visible check depends only on the element actually being
 * on-screen, not on it being a modal overlay specifically.
 *
 * Steps:
 *   1 – Type (which kind of data this profile imports). CREATE-only — the
 *       whole step (not just its content) is skipped when editing an
 *       existing profile: excluded from wizardSteps() and its breadcrumb
 *       crumb hidden, both keyed off _wizardEditProfileId in
 *       import-settings.js. Nothing downstream branches on data_type yet
 *       (Field Mapping, Attributes, Preview, and the real importer are all
 *       still product-only), so letting an existing profile's type be
 *       changed after creation would silently point a fully product-shaped
 *       wizard/importer at a non-product profile. See
 *       DATA_PIPELINE_PHASE2_SCOPING.md Milestone 1.
 *   2 – Name & Source (Profile Name + which Data Source(s) this profile
 *       pulls from — checkbox list only; the per-source Primary Key editor
 *       is Step 3, see below). Split out from Step 1 so a CREATE-only radio
 *       pick doesn't share a step with fields an edit-mode user still needs.
 *   3 – Sources & Keys (the Primary Key editor for each source checked in
 *       Step 2 — its own step since screen width is no longer constrained
 *       by a fixed 1500px modal box; see wizard-sources-pk-editor.php).
 *   4 – Field Mapping (reuses panel-field-mapping.php's table content
 *       directly — same markup/JS/autosave, previously a separate slide-out
 *       panel, now relocated inline. Curated fields visible by default; a
 *       "Show Advanced Fields" toggle reveals the rest via CSS, not a
 *       second copy of the row template.)
 *   5 – Attributes & Variations (reuses panel-attribute-mapping.php's 5
 *       sections directly, same markup/JS/autosave, previously a separate
 *       slide-out panel, now relocated inline.)
 *   6 – Product Scope (all_products | by_identifier | new_only). Choosing
 *       "by_identifier" reveals its taxonomy-term/post-meta filter config
 *       inline, right under that card — it's a sub-configuration of this
 *       one choice, not an independent step. (Not a primary-key/matching
 *       concern — that's supplier-scoped and lives in Step 3 instead.)
 *   7 – Import Mode + Missing-product action (shares Step 6's panel — see
 *       the note on #mmi-wizard-mode-section below for why these two never
 *       got their own separate steps).
 *
 * Used on both the Import Pipeline tab and the Import Settings tab.
 * Triggered by #new-profile-btn or #no-profiles-create-btn.
 *
 * $configured_suppliers is built by tab-pipeline.php before this partial is
 * included (same variable Edit Profile's source checklist already uses).
 */

if (!defined('ABSPATH')) {
    exit;
}
if (!isset($configured_suppliers)) {
    $configured_suppliers = [];
}

$_mmi_import_data_type_choices = class_exists( 'MMI_Data_Type_Registry' ) ? MMI_Data_Type_Registry::get_choices_for_ui() : [ 'product' => 'Product' ];

/** Same icon-by-key-prefix helper as the Export wizard's picker — kept as an
 * independent copy rather than a shared function, since it's small,
 * purely decorative, and the two pickers are allowed to diverge over time
 * (e.g. Import may eventually want a "not yet supported" badge Export
 * never needs) without one file's edit silently affecting the other.
 */
$_mmi_import_type_icon = static function ( string $key ): string {
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

<!-- Import Profile Wizard — inline section, hidden by default, expanded via JS -->
<div class="mmi-process-section mmi-wizard-section" id="new-profile-modal" role="region" aria-labelledby="new-profile-modal-title">

    <!-- ── Header ─────────────────────────────────────────────── -->
    <div class="mmi-wizard-section-header">
        <div class="mmi-wizard-section-header-top">
            <h3 id="new-profile-modal-title" class="mmi-wizard-section-title">➕ Create Import Profile</h3>
            <?php /* Same close/cancel behavior as the Cancel button below (flush +
                 cleanup, see import-settings.js) — this is the section's own
                 collapse-toggle now that there's no backdrop to click past. */ ?>
            <button type="button" class="mmi-modal-close" id="new-profile-modal-close" aria-label="Collapse">&times;</button>
        </div>
        <?php
        /* Step numbers are NOT hardcoded here — a hidden crumb (Type in edit
         * mode, or Attributes for non-Product data types) would otherwise
         * leave a gap like "3 • Fields, 5 • Scope & Mode" with no 4. Each
         * crumb below carries its panel id in data-panel (the one stable
         * identifier JS uses to match a crumb to a wizard step) and an empty
         * .mmi-wizard-crumb-num placeholder; wizardRenumberCrumbs()
         * (import-settings.js) fills every VISIBLE crumb's number in on the
         * fly, contiguously, every time visibility changes. */
        ?>
        <!-- Step breadcrumb -->
        <nav class="mmi-wizard-breadcrumb" id="mmi-wizard-breadcrumb" aria-label="Profile creation steps">
            <span class="mmi-wizard-crumb mmi-is-active" data-panel="mmi-wizard-p1" id="mmi-wizard-crumb-type"><span class="mmi-wizard-crumb-num"></span>&thinsp; • Type</span>
            <span class="mmi-wizard-crumb-sep" id="mmi-wizard-crumb-sep-type">›</span>
            <span class="mmi-wizard-crumb" data-panel="mmi-wizard-p2"><span class="mmi-wizard-crumb-num"></span>&thinsp; • Name &amp; Source</span>
            <span class="mmi-wizard-crumb-sep">›</span>
            <span class="mmi-wizard-crumb" data-panel="mmi-wizard-p3"><span class="mmi-wizard-crumb-num"></span>&thinsp; • Sources &amp; Keys</span>
            <span class="mmi-wizard-crumb-sep">›</span>
            <span class="mmi-wizard-crumb" data-panel="mmi-wizard-p4"><span class="mmi-wizard-crumb-num"></span>&thinsp; • Fields</span>
            <span class="mmi-wizard-crumb-sep">›</span>
            <span class="mmi-wizard-crumb" data-panel="mmi-wizard-p5" id="mmi-wizard-crumb-attributes"><span class="mmi-wizard-crumb-num"></span>&thinsp; • Attributes</span>
            <span class="mmi-wizard-crumb-sep" id="mmi-wizard-crumb-sep-attributes">›</span>
            <span class="mmi-wizard-crumb" data-panel="mmi-wizard-p6"><span class="mmi-wizard-crumb-num"></span>&thinsp; • Scope &amp; Mode</span>
        </nav>
    </div>

    <?php /* Scroll region for the active panel — same max-height/overflow-y
         pattern as .mmi-history-table-wrap, so a long step (Field Mapping's
         table, Attributes' 5 sections) scrolls internally instead of
         stretching this whole section past the viewport; header/breadcrumb
         and footer stay fixed in view either way. */ ?>
    <div class="mmi-wizard-panel-wrap">

    <!-- ── Panel 1 : Type ──────────────────────────────────────── -->
    <?php /* CREATE-only step — the whole step, not just this content, is
         skipped when editing an existing profile: excluded from
         wizardSteps() and its breadcrumb crumb (#mmi-wizard-crumb-type)
         hidden, both keyed off _wizardEditProfileId in
         openProfileWizardShow() (import-settings.js) — so there's no
         separate visibility toggle needed on this inner div the way there
         used to be when Type shared a panel with Name/Source (which DO
         still apply in edit mode). Nothing downstream branches on
         data_type yet (Field Mapping, Attributes, Preview, and the real
         importer are all still product-only), so letting an existing
         profile's type be changed after creation would silently point a
         fully product-shaped wizard/importer at a non-product profile. See
         DATA_PIPELINE_PHASE2_SCOPING.md Milestone 1. */ ?>
    <div class="mmi-wizard-panel" id="mmi-wizard-p1" data-panel="1">
        <div id="mmi-wizard-datatype-section">
            <p class="mmi-wizard-panel-intro"><strong class="mmi-wizard-panel-intro-text">What do you want to import?</strong></p>
            <p class="description mmi-pipeline-mb-14">
                Product import is fully supported today. Other types are listed for future use —
                see their own setup steps once you pick one, but Field Mapping and the import
                engine itself currently only understand Product.
            </p>
            <div class="mmi-scope-choices mmi-scope-choices--scroll" id="mmi-wizard-data-type-cards">
                <?php foreach ( $_mmi_import_data_type_choices as $key => $label ) : ?>
                    <label class="mmi-scope-card mmi-scope-card--compact" data-data-type="<?php echo esc_attr( $key ); ?>">
                        <input type="radio" name="new_profile_data_type" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, 'product' ); ?>>
                        <div class="mmi-scope-card-inner">
                            <span class="mmi-scope-card-icon"><?php echo esc_html( $_mmi_import_type_icon( $key ) ); ?></span>
                            <div><strong><?php echo esc_html( $label ); ?></strong></div>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── Panel 2 : Name & Source ─────────────────────────────── -->
    <?php /* Split out of the old combined Type & Source step so a CREATE-only
         radio pick (now Step 1) doesn't share a step with fields an
         edit-mode user still needs — Name and Source apply whether creating
         or editing. */ ?>
    <div class="mmi-wizard-panel mmi-hidden" id="mmi-wizard-p2" data-panel="2">
        <div id="mmi-wizard-name-source-section">

        <div class="mmi-modal-field">
            <label for="new-profile-name" class="mmi-field-label"><strong class="mmi-field-label-text">Profile Name</strong></label>
            <input type="text" id="new-profile-name" class="widefat"
                   placeholder="e.g. Pricing Sync, New Product Import…" autocomplete="off">
            <p class="description">A short, recognizable name for this import configuration.</p>
        </div>

        <hr class="mmi-wizard-step-divider">

        <p class="mmi-wizard-panel-intro"><strong>Which data source(s) does this profile pull from?</strong></p>
        <p class="description mmi-pipeline-mb-14">
            Select at least one data source below (or add a new one) so this profile only ever
            touches its own feed. Each source's Primary Key is configured in the next step.
        </p>

        <div class="mmi-panel-content">

        <?php /* Upload a new file directly here — no need to leave the wizard.
             Reuses the same drop-zone markup/states as the (now-retired)
             Quick Import wizard and Add Data Source modal's Upload tile;
             IDs prefixed wizard-upload-* to avoid colliding with either. */ ?>
        <div class="mmi-upload-drop-zone" id="wizard-upload-drop-zone" tabindex="0" role="button" aria-label="Click or drag a file to upload a new source">
            <div id="wizard-drop-zone-idle" class="mmi-wizard-upload-idle">
                <span class="dashicons dashicons-upload mmi-drop-icon"></span>
                <p class="mmi-drop-primary">Drag &amp; drop a file to add it as a new source</p>
                <p class="mmi-drop-secondary">or <button type="button" class="mmi-drop-browse-btn">browse files</button></p>
                <p class="mmi-drop-formats">CSV &bull; TSV &bull; JSON &bull; XML</p>
            </div>
            <div id="wizard-drop-zone-analyzing" class="mmi-is-hidden">
                <span class="mmi-spinner-inline"></span>
                <p class="mmi-drop-primary">Reading your file&hellip;</p>
            </div>
            <div id="wizard-drop-zone-result" class="mmi-is-hidden">
                <span class="dashicons dashicons-yes-alt mmi-drop-ok-icon"></span>
                <div class="mmi-drop-result-info">
                    <strong id="wizard-upload-result-filename" class="mmi-wizard-upload-filename">file.csv</strong>
                    <span class="mmi-drop-result-meta" id="wizard-upload-result-meta">CSV &bull; 0 rows</span>
                </div>
                <button type="button" class="button button-small mmi-drop-replace-btn" id="wizard-upload-replace-btn">Replace</button>
            </div>
            <div id="wizard-drop-zone-error" class="mmi-is-hidden">
                <span class="dashicons dashicons-warning mmi-drop-error-icon"></span>
                <p class="mmi-drop-primary" id="wizard-drop-zone-error-msg">Could not read the file.</p>
                <button type="button" class="button button-small" id="wizard-upload-retry-btn">Try Again</button>
            </div>
        </div>
        <input type="file" id="wizard-upload-file-input" class="mmi-is-hidden" accept=".csv,.tsv,.json,.xml,.txt">
        <input type="hidden" id="wizard-upload-attachment-id" class="mmi-wizard-upload-attachment-id" value="">
        <input type="hidden" id="wizard-upload-detected-format" class="mmi-wizard-upload-format" value="">
        <div class="mmi-modal-field mmi-hidden" id="wizard-upload-name-row">
            <label for="wizard-source-name" class="mmi-field-label"><strong class="mmi-field-label-text">Source Name</strong></label>
            <input type="text" id="wizard-source-name" class="widefat" placeholder="e.g. Vendor Music Catalog" autocomplete="off">
            <button type="button" class="button button-primary mmi-action-btn" id="wizard-upload-create-btn">Add as New Source</button>
            <p class="description">Shows up in Data Sources and gets checked below — you can rename it later.</p>
        </div>

        <?php /* Escape hatch for source types the inline drop-zone above can't
             handle (API, URL, Dropbox, Google Drive) — sits right under it
             since both do the same job of "add a source", just for
             different type. */ ?>
        <p class="description mmi-pipeline-mt-xs">
            <button type="button" class="button-link" id="new-profile-add-source-link">+ Add a Data Source</button>
        </p>

        <div id="new-profile-sources-list" class="mmi-source-rows">
            <?php include __DIR__ . '/wizard-sources-checklist.php'; ?>
        </div>
        <p class="mmi-wizard-source-error mmi-is-hidden" id="new-profile-source-error">
            <span class="dashicons dashicons-warning"></span> Select at least one data source to continue.
        </p>
        </div><!-- /.mmi-panel-content -->
        </div><!-- /#mmi-wizard-name-source-section -->
    </div>

    <!-- ── Panel 3 : Sources & Keys ───────────────────────────── -->
    <?php /* Split out of the old combined Name & Source step — the
         per-source Primary Key editor now has room to breathe since it's no
         longer sharing a fixed 1500px modal box with the source checklist.
         No required-field gate here (confirmed with the user): a source
         with no Primary Key set yet is still just a soft warning, same as
         before this split — advancing past this step never blocks on it. */ ?>
    <div class="mmi-wizard-panel mmi-hidden" id="mmi-wizard-p3" data-panel="3">
        <p class="mmi-wizard-panel-intro"><strong class="mmi-wizard-panel-intro-text">Configure how each selected source is matched to your catalog.</strong></p>
        <p class="description mmi-pipeline-mb-14">
            Set a Primary Key for each source you selected in Step 2 — controls how an incoming
            file row is matched to an existing product. Sources you haven't checked yet won't
            appear here.
        </p>
        <div class="mmi-panel-content">
        <div id="new-profile-pk-editors-list" class="mmi-source-rows">
            <?php include __DIR__ . '/wizard-sources-pk-editor.php'; ?>
        </div>
        </div><!-- /.mmi-panel-content -->
    </div>

    <!-- ── Panel 4 : Field Mapping ────────────────────────────── -->
    <?php /* mmi-show-advanced-fields on the panel + checked on the toggle
         below are this control's default ON state — kept in sync with
         the JS reset in openProfileWizard() (import-settings.js) so
         there's no flash of the collapsed state before JS runs. */ ?>
    <div class="mmi-wizard-panel mmi-hidden mmi-show-advanced-fields" id="mmi-wizard-p4" data-panel="4">
        <p class="mmi-wizard-panel-intro"><strong class="mmi-wizard-panel-intro-text">Map your source data to WooCommerce fields.</strong></p>
        <p class="description mmi-pipeline-mb-14">
            The common fields are shown below — changes save automatically. Need sale
            pricing, dimensions, gallery images, or a custom field?
        </p>
        <div class="mmi-wizard-advanced-bar">
            <label class="mmi-toggle-switch" title="Show every mappable field, not just the common ones">
                <input type="checkbox" id="wizard-show-advanced-fields" class="mmi-toggle-input" checked>
                <span class="mmi-toggle-slider"></span>
            </label>
            <label for="wizard-show-advanced-fields" class="mmi-wizard-advanced-label">Show Advanced Fields</label>
        </div>
        <?php /* Populated by wizardValidateFieldMappings() (import-settings.js) — the
             same MMI_Pipeline_Config_Validator check the Import tab runs before a
             manual run/schedule, scoped here to field-mapping issues so they're
             caught while editing this step instead of surfacing later as a popup. */ ?>
        <div id="mmi-wizard-field-warnings" class="mmi-config-issues-panel mmi-is-hidden" aria-live="polite"></div>
        <?php /* Stable wrapper — neither panel-field-mapping.php's placeholder
             branch nor its real-content branch carries this id themselves
             (see that file's own top-of-branch comments); the id lives
             here, on an element neither the page-load render nor the
             AJAX-swapped content ever replaces, so
             wizardMaybeLoadFieldMappingPanel()'s .html() swap
             (import-settings.js) can never silently stop finding its
             target the way the old .replaceWith() did once the
             placeholder's own id got swapped away. */ ?>
        <div id="mmi-wizard-field-mapping-container">
            <?php include __DIR__ . '/panel-field-mapping.php'; ?>
        </div>
    </div>

    <!-- ── Panel 5 : Attributes & Variations ──────────────────── -->
    <div class="mmi-wizard-panel mmi-hidden" id="mmi-wizard-p5" data-panel="5">
        <p class="mmi-wizard-panel-intro"><strong class="mmi-wizard-panel-intro-text">Map attributes and configure variations (optional).</strong></p>
        <p class="description mmi-pipeline-mb-14">
            Only needed for products with variations (size, color, etc.) — skip this step
            entirely for simple products.
        </p>
        <?php include __DIR__ . '/panel-attribute-mapping.php'; ?>
    </div>

    <!-- ── Panel 6 : Scope & Mode ─────────────────────────────── -->
    <?php /* Combined from what used to be two separate steps — like Panel 2's
         Name + Source merge, both Scope and Mode are quick, single-click
         card choices, so splitting them across another Next click added a
         step for no real benefit. The Mode sub-section is hidden and
         replaced with a read-only explanatory notice (#mmi-wizard-mode-locked-notice,
         below) when scope = new_only, whose behavior is fixed (create new,
         never touch existing) — see the scope-change handler in import-settings.js. */ ?>
    <div class="mmi-wizard-panel mmi-hidden" id="mmi-wizard-p6" data-panel="6">
        <p class="mmi-wizard-panel-intro"><strong class="mmi-wizard-panel-intro-text">Which products should this profile affect during import?</strong></p>
        <p class="description mmi-pipeline-mb-14">
            Controls which products in your store this profile is allowed to touch — separate
            from the Primary Key matching configured per source in Step 3.
        </p>
        <div class="mmi-panel-content">
        <div class="mmi-scope-choices">

            <label class="mmi-scope-card mmi-is-selected" id="mmi-scope-card-all" data-scope="all_products">
                <input type="radio" name="new_profile_scope" value="all_products" class="mmi-scope-card-input" checked>
                <div class="mmi-scope-card-inner">
                    <span class="mmi-scope-card-icon">🌐</span>
                    <div class="mmi-scope-card-body">
                        <strong class="mmi-scope-card-title">All products in the store</strong>
                        <p class="mmi-scope-card-desc">Updates apply to every product. Good for single-supplier stores or early-stage setups.</p>
                    </div>
                </div>
            </label>

            <label class="mmi-scope-card" id="mmi-scope-card-id" data-scope="by_identifier">
                <input type="radio" name="new_profile_scope" value="by_identifier" class="mmi-scope-card-input">
                <div class="mmi-scope-card-inner">
                    <span class="mmi-scope-card-icon">🏷️</span>
                    <div class="mmi-scope-card-body">
                        <strong class="mmi-scope-card-title">Only products tagged for this profile</strong>
                        <p class="mmi-scope-card-desc">Limits updates to products carrying a specific taxonomy term or custom field you choose below — separate from each source's Primary Key in Step 3 (which controls how a file row is matched to a product, not which products this profile is allowed to touch). Prevents cross-contamination between profiles in multi-supplier stores.</p>
                    </div>
                </div>
            </label>

            <?php /* Configures the tag/filter above. Deliberately a SIBLING of the
                 card's <label>, not nested inside it — an interactive field
                 (select/checkbox/text input) inside a <label> triggers that
                 label's native click-forwarding to its radio, which would make
                 using these fields behave strangely. Visible only while
                 "by_identifier" is selected (see the scope-change handler). */ ?>
            <div class="mmi-scope-id-config mmi-is-hidden" id="mmi-scope-id-config">
                <p class="description mmi-pipeline-mb-14">
                    This is a separate concept from the Primary Key you set for each source in
                    Step 3. Primary Key controls <em class="mmi-inline-em">how</em> an incoming row is matched to a
                    product; this controls <em class="mmi-inline-em">which</em> products this profile is allowed to
                    touch at all. The import queries WordPress natively (not through any
                    particular plugin), so this configuration will keep working even if you
                    change or remove the plugin that manages the field.
                </p>

                <!-- Storage type toggle -->
                <div class="mmi-identifier-type-choices">
                    <label class="mmi-identifier-type-card mmi-is-active" id="mmi-id-type-card-taxonomy">
                        <input type="radio" name="new_profile_id_type" value="taxonomy" class="mmi-identifier-type-input" checked>
                        <strong class="mmi-identifier-type-title">Custom Taxonomy</strong>
                        <small class="mmi-identifier-type-hint">e.g. a "Distribution" taxonomy with per-supplier terms</small>
                    </label>
                    <label class="mmi-identifier-type-card" id="mmi-id-type-card-meta">
                        <input type="radio" name="new_profile_id_type" value="post_meta" class="mmi-identifier-type-input">
                        <strong class="mmi-identifier-type-title">Custom Post Meta</strong>
                        <small class="mmi-identifier-type-hint">e.g. meta key&nbsp;<code class="mmi-inline-code">_supplier_id</code>&nbsp;= "xchange"</small>
                    </label>
                </div>

                <!-- Taxonomy fields -->
                <div class="mmi-identifier-fields" id="mmi-id-fields-taxonomy">
                    <div class="mmi-modal-field">
                        <label for="new-profile-tax-slug" class="mmi-field-label"><strong class="mmi-field-label-text">Taxonomy</strong></label>
                        <select id="new-profile-tax-slug" class="widefat">
                            <option value="">— select a taxonomy —</option>
                            <!-- options populated by JS from mmiImportSettings.productTaxonomies -->
                        </select>
                        <p class="description">The registered taxonomy — managed by JetEngine, ACF, Meta Box, native WordPress, or any plugin.</p>
                    </div>
                    <div class="mmi-modal-field">
                        <label for="new-profile-tax-term" class="mmi-field-label"><strong class="mmi-field-label-text">Terms to Match</strong></label>
                        <select id="new-profile-tax-term" class="widefat" multiple size="5" disabled>
                            <option value="" disabled>Select a taxonomy first…</option>
                        </select>
                        <p class="description">Products must have <em class="mmi-inline-em">at least one</em> of the selected terms assigned to be included in the import. Hold <kbd class="mmi-inline-kbd">Ctrl</kbd> / <kbd class="mmi-inline-kbd">⌘</kbd> to select multiple.</p>
                    </div>
                </div>

                <!-- Post Meta fields -->
                <div class="mmi-identifier-fields mmi-hidden" id="mmi-id-fields-meta">
                    <div class="mmi-modal-field">
                        <label for="new-profile-meta-key" class="mmi-field-label"><strong class="mmi-field-label-text">Meta Key</strong></label>
                        <input type="text" id="new-profile-meta-key" class="widefat"
                               placeholder="e.g. _supplier_id" autocomplete="off"
                               list="new-profile-meta-key-list">
                        <datalist id="new-profile-meta-key-list"></datalist>
                        <p class="description">Any post meta key — existing or new.</p>
                    </div>
                    <div class="mmi-modal-field">
                        <label for="new-profile-meta-value" class="mmi-field-label"><strong class="mmi-field-label-text">Meta Value</strong></label>
                        <input type="text" id="new-profile-meta-value" class="widefat"
                               placeholder="e.g. xchange" autocomplete="off"
                               list="new-profile-meta-value-list">
                        <datalist id="new-profile-meta-value-list"></datalist>
                        <p class="description">Products must have this exact value in the meta key above to be included.</p>
                    </div>
                </div>

                <!-- Auto-apply toggle -->
                <div class="mmi-modal-field">
                    <label class="mmi-toggle-switch-row">
                        <span class="mmi-toggle-switch">
                            <input type="checkbox" id="new-profile-id-auto-apply" class="mmi-toggle-input" checked>
                            <span class="mmi-toggle-slider"></span>
                        </span>
                        <span>Automatically apply this identifier to newly-created products from this profile</span>
                    </label>
                </div>

                <!-- Plugin onboarding note -->
                <details class="mmi-identifier-plugin-note">
                    <summary>Using a page builder or custom fields plugin? (optional)</summary>
                    <p class="description">
                        If this taxonomy/meta field is managed by a specific plugin (JetEngine,
                        ACF, Meta Box, etc.), selecting it here helps ensure compatibility —
                        purely informational, doesn't change how the identifier itself works.
                    </p>
                    <div class="mmi-plugin-checkboxes">
                        <label><input type="checkbox" name="mmi_id_plugin[]" value="jetengine"> JetEngine</label>
                        <label><input type="checkbox" name="mmi_id_plugin[]" value="acf"> ACF</label>
                        <label><input type="checkbox" name="mmi_id_plugin[]" value="metabox"> Meta Box</label>
                        <label><input type="checkbox" name="mmi_id_plugin[]" value="pods"> Pods</label>
                    </div>
                </details>
            </div>

        </div>
        </div><!-- /.mmi-panel-content -->

        <hr class="mmi-wizard-step-divider">

        <?php /* Shown only for scope = new_only, in place of the Mode section below
             (toggled by the scope-change handler in import-settings.js — see
             wizardShowPanel(), openProfileWizardShow(), and the scope radio's own
             change handler). new_only's behavior is fixed (always create missing
             products, never touch existing ones, never act on items that disappear
             from the feed) — this explains what's locked in instead of silently
             hiding the choice with no explanation. */ ?>
        <div id="mmi-wizard-mode-locked-notice" class="mmi-wizard-mode-locked-notice mmi-hidden">
            <span class="mmi-wizard-mode-locked-icon">✨</span>
            <div class="mmi-wizard-mode-locked-body">
                <strong class="mmi-wizard-mode-locked-title">Mode is fixed for this scope</strong>
                <p class="mmi-wizard-mode-locked-desc">New products from the feed are created automatically. Existing products are never modified, and nothing is done when a product disappears from the feed — regardless of what any mode would otherwise do.</p>
            </div>
        </div>

        <div id="mmi-wizard-mode-section" class="mmi-wizard-mode-section">
        <p class="mmi-wizard-panel-intro"><strong class="mmi-wizard-panel-intro-text">What should this profile do when it runs?</strong></p>
        <p class="description mmi-pipeline-mb-14">
            Sets what happens to matching products each time this profile runs, and how to
            handle items that disappear from the feed.
        </p>
        <div class="mmi-panel-content">

        <div class="mmi-mode-cards">

            <label class="mmi-mode-card" data-mode="create-and-update">
                <input type="radio" name="new_profile_mode" value="create-and-update" class="mmi-mode-card-input">
                <div class="mmi-mode-card-inner">
                    <span class="mmi-mode-card-icon" class="mmi-icon-green-forest">➕🔄</span>
                    <strong class="mmi-mode-card-title">Create &amp; Update</strong>
                    <p class="mmi-mode-card-desc">Adds new products and keeps existing ones current. Use for initial catalog imports and ongoing full syncs.</p>
                </div>
            </label>

            <label class="mmi-mode-card" data-mode="create-only">
                <input type="radio" name="new_profile_mode" value="create-only" class="mmi-mode-card-input">
                <div class="mmi-mode-card-inner">
                    <span class="mmi-mode-card-icon" class="mmi-icon-green-teal">➕</span>
                    <strong class="mmi-mode-card-title">Create Only</strong>
                    <p class="mmi-mode-card-desc">Adds new products from the feed, but never modifies existing ones. Safe for populating a catalog without overwriting manually-curated data.</p>
                </div>
            </label>

            <label class="mmi-mode-card" data-mode="update-only">
                <input type="radio" name="new_profile_mode" value="update-only" class="mmi-mode-card-input" checked>
                <div class="mmi-mode-card-inner">
                    <span class="mmi-mode-card-icon" class="mmi-icon-blue">🔄</span>
                    <strong class="mmi-mode-card-title">Update Only</strong>
                    <p class="mmi-mode-card-desc">Updates pricing, inventory, and scheduling on existing products only. Products not in the database are skipped.</p>
                </div>
            </label>

            <label class="mmi-mode-card" data-mode="availability-sync">
                <input type="radio" name="new_profile_mode" value="availability-sync" class="mmi-mode-card-input">
                <div class="mmi-mode-card-inner">
                    <span class="mmi-mode-card-icon" class="mmi-icon-amber">�</span>
                    <strong class="mmi-mode-card-title">Availability Sync</strong>
                    <p class="mmi-mode-card-desc">Stock and availability updates only. Ignores product content — designed purely to react when products disappear from the feed.</p>
                </div>
            </label>

        </div>

        <!-- Missing product action -->
        <div class="mmi-missing-action-section">
            <p class="mmi-missing-action-label"><strong class="mmi-missing-action-label-text">When a product disappears from the import feed:</strong></p>

            <!-- Contextual hint — updated dynamically by JS -->
            <p class="mmi-missing-action-hint mmi-hidden" id="mmi-missing-action-hint"></p>

            <div class="mmi-missing-action-choices">
                <label class="mmi-missing-radio" data-action="none">
                    <input type="radio" name="new_profile_missing_action" value="none" class="mmi-missing-radio-input" checked>
                    <span class="mmi-missing-radio-body">
                        <strong class="mmi-missing-radio-title">Leave alone</strong>
                        <small class="mmi-missing-radio-hint">— no automatic change</small>
                    </span>
                    <span class="mmi-action-badge mmi-badge mmi-hidden"></span>
                </label>
                <label class="mmi-missing-radio" data-action="stock_zero">
                    <input type="radio" name="new_profile_missing_action" value="stock_zero" class="mmi-missing-radio-input">
                    <span class="mmi-missing-radio-body">
                        <strong class="mmi-missing-radio-title">Set stock to 0</strong>
                        <small class="mmi-missing-radio-hint">— marks as out of stock</small>
                    </span>
                    <span class="mmi-action-badge mmi-badge mmi-hidden"></span>
                </label>
                <label class="mmi-missing-radio" data-action="unpublish">
                    <input type="radio" name="new_profile_missing_action" value="unpublish" class="mmi-missing-radio-input">
                    <span class="mmi-missing-radio-body">
                        <strong class="mmi-missing-radio-title">Unpublish</strong>
                        <small class="mmi-missing-radio-hint">— moves to draft status, hidden from the store</small>
                    </span>
                    <span class="mmi-action-badge mmi-badge mmi-hidden"></span>
                </label>
            </div>
        </div>
        </div><!-- /.mmi-panel-content -->
        </div><!-- /#mmi-wizard-mode-section -->
    </div>

    </div><!-- /.mmi-wizard-panel-wrap -->

    <!-- ── Footer ─────────────────────────────────────────────── -->
    <div class="mmi-wizard-footer">
        <div class="mmi-wizard-footer-left">
            <button type="button" class="mmi-btn-profile" id="new-profile-modal-cancel">Cancel</button>
            <?php /* Only shown when editing an existing profile (see the
                 mmi-edit-mode toggle in openProfileWizard()) — a
                 brand-new profile isn't saved yet, so there's nothing
                 valid to "close" out of early; Next/Back enforce the
                 required-field order for that case instead. */ ?>
            <button type="button" class="mmi-btn-profile mmi-hidden" id="mmi-wizard-save-exit">💾 Save &amp; Close</button>
        </div>
        <div class="mmi-wizard-footer-nav">
            <button type="button" class="mmi-btn-profile mmi-hidden" id="mmi-wizard-back">← Back</button>
            <button type="button" class="button button-primary mmi-action-btn" id="mmi-wizard-next">Next →</button>
            <button type="button" class="button button-primary mmi-action-btn mmi-hidden" id="new-profile-modal-create">➕ Create Profile</button>
        </div>
    </div>

</div>
