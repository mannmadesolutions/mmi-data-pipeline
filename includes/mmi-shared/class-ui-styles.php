<?php
/**
 * MMI Shared Library — UI Design Tokens
 *
 * Schema/defaults/sanitize source for mmi-admin's Style Manager tab
 * (class-style-manager.php), the CSS-custom-property counterpart to
 * MMI_Email_Templates. Like that class, this one lived in mmi-hub only and
 * was deleted with it (1.173.0, 2026-09-17) without ever being rebuilt —
 * unlike MMI_Email_Templates (rebuilt changelog 1.187.0), Style Manager sat
 * with an honest "not currently functional" placeholder until this class was
 * written (2026-09-19).
 *
 * Every field below is bound to a CSS custom property that mmi-suite-common.css
 * consumes with a `var(--mmi-x, <fallback>)` pattern. Each field's 'default'
 * is that same fallback value, so an unshipped install (published_tokens()
 * reads an empty array from a nonexistent tokens file) renders identically
 * to today, and a first Ship with nothing changed by hand is a visual no-op.
 *
 * Header/Cards & Footers/Tables & Rows/Buttons/Logs/Stat Tiles (2026-09-19):
 * confirmed by reading the actual rule blocks for .mmi-header/.mmi-card/
 * .mmi-uniform-table/.mmi-action-btn/.mmi-log-container/.mmi-stat-box before
 * choosing which tokens to expose, not guessed.
 *
 * Pagination/Modals (2026-09-20): .mmi-pagination-* and .mmi-modal-* previously
 * only used generic shared tokens (--mmi-radius-sm, --mmi-text-secondary,
 * etc.) with no component-specific namespace of their own, so mmi-suite-
 * common.css was edited in the same change to introduce dedicated
 * --mmi-pagination-* and --mmi-modal-* custom properties with nested var()
 * fallbacks (e.g. `var(--mmi-modal-radius, var(--mmi-radius-lg, 8px))`) —
 * an unshipped site still resolves to the exact same generic-token value as
 * before, but a Style Manager edit now changes only that one component
 * instead of every other consumer of the generic token. Added because the
 * Style Manager 100%-coverage gap analysis (memory: mmi-admin-style-manager-
 * 100pct-gap-analysis) found the schema/scanner/scaffold pipeline capped at
 * 6 of ~60+ real component families in mmi-suite-common.css, which is what
 * let mmi-data-health reinvent pagination/modal-shaped markup locally
 * (.mmi-dh-pagination) instead of reaching for the shared ones — nothing
 * surfaced that they existed. Pagination/Modals were chosen first as the two
 * components the September suite-wide audit found most fragmented (see
 * [[mmi-style-manager-deploy-audit-and-gap-closure]]).
 *
 * Badges/Collapsible Sections (2026-09-20, same session as Pagination/
 * Modals): same story — .mmi-badge had zero var()-driven properties at all
 * (padding/radius/font-size were plain hardcoded, and its background/color
 * used the shared --mmi-gray-300/--mmi-gray-700 tokens with no component
 * namespace), .mmi-collapsible-section/.mmi-section-* likewise 100%
 * hardcoded. Both refactored the same way: new dedicated custom properties
 * with a literal fallback matching the prior hardcoded value (badges) or a
 * nested var() fallback preserving the prior shared-token chain (n/a here,
 * everything badges/sections touch was already a dead-end literal, not
 * another var()). Badge state-variant colors (.success/.warning/.error/
 * .info) deliberately stay on their existing shared semantic tokens rather
 * than getting their own badge-scoped fields — those tokens are also
 * consumed by logs/stat-tiles/etc., so editing "what a badge looks like"
 * shouldn't silently also restyle every log entry.
 *
 * Toggles/Toolbar/Widget Grid (2026-09-20, continuing the same session):
 * Toggles got the same hardcoded-to-dedicated-token treatment as badges,
 * but colors only — .mmi-toggle-switch's width/height/knob-size and the
 * checked/indeterminate transform: translateX() distances are coupled
 * geometry (resize one without the other and the knob overshoots or leaves
 * a gap), not safely exposable as independent flat fields, so they're left
 * fixed; see the CSS comment there. Toolbar/Widget Grid were genuinely
 * greenfield — unlike every other group here, neither had ANY existing
 * shared class to add tokens to (per the September audit, this was a
 * design/build gap, not a schema one) — so this change also added
 * `.mmi-toolbar`/`.mmi-widget-grid`/`.mmi-widget` to mmi-suite-common.css
 * itself, modeled directly on mmi-data-health's own `.mmi-dh-toolbar`/
 * `.mmi-dh-widgets`/`.mmi-dh-widget` (same values, byte-for-byte, as its
 * first real adopter — migrated the same session, see that plugin's admin.css).
 *
 * Animations (2026-09-20, same session, added mid-turn on direct request):
 * `.mmi-animate-fadeIn` (mmi-suite-common.css's existing generic reveal
 * utility, previously a hardcoded `0.3s`/`-10px`) is this suite's canonical
 * "soft reveal for freshly-inserted content" pattern — the fix for
 * mmi-xchange-integration's PO order-row detail panel appearing with no
 * transition at all. Only duration (a `select` of the 3 existing
 * `--mmi-transition-*` speeds, not a free-text field — keeps values
 * constrained to the suite's existing timing scale rather than letting
 * every plugin drift to its own) and drop-distance are exposed; the
 * underlying keyframe shape itself isn't a token (would need a new field
 * type this schema doesn't have). `.mmi-animate-slideIn`/`-pulse` were left
 * untouched — out of scope for what was actually asked.
 *
 * Tooltips (2026-09-20, same session): `.mmi-tooltip-text` centered itself
 * via `left: 50%; margin-left: -100px` — a fixed half-of-200px offset that
 * only centers correctly at exactly 200px wide, which would have made
 * `tooltip_width` unsafe to expose (the same coupling problem as
 * .mmi-toggle-switch's geometry). Switched to `transform: translateX(-50%)`
 * instead, which centers correctly at any width — verified equivalent to
 * the old rule at the current 200px default, but now genuinely safe to
 * expose. Found while doing this: `mmi-reverb-integration` has its own
 * competing local `.mmi-tooltip .mmi-tooltip-text` (280px, left-aligned
 * text, its own box-shadow) — higher specificity than the shared bare
 * class, so it always wins there regardless of load order. An 8th
 * shared-class-name collision, on top of the 7 already documented in the
 * MMI Style Schema Audit memory — not fixed this session, noted in the
 * schema's own `tooltip_width` field so this doesn't read as a surprise
 * later ("shipped a tooltip width, reverb-integration didn't change").
 *
 * Stat Tiles expansion (2026-09-21, user request — "much more than what is
 * available currently," explicitly including gap/min-height/font-size):
 * brought this one group up to full parity with what every other group
 * already exposes (a color/spacing field for every visually distinct
 * sub-part, including state variants), rather than exceeding that bar —
 * none of the other 14 groups expose font-weight/letter-spacing/
 * text-transform/opacity/line-height either, so those stay hardcoded here
 * too, for consistency, not because they're unimportant. `min_height` and
 * the 4 state-variant gradient pairs (success/warning/error/info) are new
 * CSS properties, not just newly-exposed tokens — `.mmi-stat-box` had no
 * enforced height before, and the variants' backgrounds were hardcoded
 * literals. `stat_border_generic_color` splits the 1px outer border off
 * `--mmi-card-border-color` (nested var() fallback preserves any existing
 * Cards & Footers customization) so editing a stat tile's border doesn't
 * also silently restyle every card, per the same reasoning as Badges'
 * state-variant colors above.
 *
 * Also promoted a real base `.mmi-stats-grid { display: grid; ... }` rule
 * into mmi-suite-common.css (see that file) — it previously carried only
 * responsive breakpoint overrides, forcing every consumer to hand-roll an
 * identical base rule locally (confirmed in mmi-admin, mmi-data-health,
 * mmi-google-services, mmi-reverb-integration — same CSS Ownership gap
 * Toolbar/Widget Grid closed above). `stats_grid_gap`/`_margin`/
 * `_min_col_width` are grouped under Stat Tiles rather than a new group,
 * since class-style-usage-scanner.php's MARKER_CLASSES and
 * tab-style-manager.php's preview both key off the "stat-tiles" slug and
 * `.mmi-stats-grid` has no independent identity apart from the tiles it
 * lays out. Explicitly NOT exposed: box-shadow (rest + hover), the hover
 * lift `transform`, and `transition` timing — no field type in this schema
 * can hold a raw shadow/transform value (same limitation already noted
 * above for Animations' keyframe shapes), and approximating one with a
 * preset select wasn't asked for, so it's flagged here rather than
 * quietly built.
 *
 * Deliberately still narrower than the 198 custom properties mmi-suite-
 * common.css now defines: colors/spacing genuinely wired into these 15
 * components only (matching the 15 groups class-style-usage-scanner.php's
 * MARKER_CLASSES hard-codes, and tab-style-manager.php's preview markup) —
 * not the suite's many other, mostly-duplicate color-token families
 * (--color-*, --mmi-shadow-* vs --shadow-*, --mmi-radius-* vs
 * --border-radius-*, etc. — see the MMI Style Schema Audit memory) that a
 * later session may choose to consolidate before exposing. Every component
 * from the original 100%-coverage gap analysis is now represented here —
 * remaining suite-wide work is adoption/collision-resolution, not schema.
 *
 * Ship pipeline mirrors MMI_Email_Templates' exactly: write a canonical
 * generated data file into this same directory (ui-style-tokens-default.php),
 * run sync-to-plugins.sh so every plugin's bundled copy gets it, and apply it
 * suite-wide via a `wp_add_inline_style( 'mmi-suite-common', ... )` call in
 * this bootstrap's own asset registration (bootstrap.php) — a `:root { }`
 * override block emitted only once a tokens file actually exists, so an
 * unshipped site never emits anything.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_UI_Styles {

	/**
	 * Cached published (shipped-default) token layer. Deliberately a plain
	 * private static property, not a method-local static — mmi-admin's Ship
	 * workflow resets this via ReflectionProperty after rewriting the tokens
	 * file mid-request, same pattern as MMI_Email_Templates::$published_tokens.
	 */
	private static ?array $published_tokens = null;

	/**
	 * Editable-token schema, grouped for the Style Manager editor UI. Group
	 * labels are chosen so sanitize_title() produces exactly the slugs
	 * class-style-usage-scanner.php's MARKER_CLASSES and tab-style-manager.php's
	 * preview markup already hard-code: header, cards-footers, tables-rows,
	 * buttons, logs, stat-tiles, pagination, modals, badges,
	 * collapsible-sections, toggles, toolbar, widget-grid, animations,
	 * tooltips.
	 */
	public static function field_schema(): array {
		return array(
			'Header'        => array(
				'header_bg_start'     => array( 'type' => 'color',   'label' => 'Background Start',  'css_var' => '--mmi-header-bg-start',     'default' => '#ffffff' ),
				'header_bg_end'       => array( 'type' => 'color',   'label' => 'Background End',    'css_var' => '--mmi-header-bg-end',       'default' => '#f8f9fa' ),
				'header_border_color' => array( 'type' => 'color',   'label' => 'Left Border',        'css_var' => '--mmi-header-border-color', 'default' => '#45852C' ),
				'header_radius'       => array( 'type' => 'spacing', 'label' => 'Corner Radius',      'css_var' => '--mmi-header-radius',       'default' => '8px' ),
				'header_padding'      => array( 'type' => 'spacing', 'label' => 'Padding',            'css_var' => '--mmi-header-padding',      'default' => '24px 30px' ),
				'header_margin'       => array( 'type' => 'spacing', 'label' => 'Margin',             'css_var' => '--mmi-header-margin',       'default' => '20px 0' ),
				'header_display'      => array( 'type' => 'select',  'label' => 'Layout',             'css_var' => '--mmi-header-display',      'default' => 'flex', 'options' => array( 'flex' => 'Flex', 'block' => 'Block' ) ),
				'header_flex_wrap'    => array( 'type' => 'select',  'label' => 'Wrap',               'css_var' => '--mmi-header-flex-wrap',    'default' => 'wrap', 'options' => array( 'wrap' => 'Wrap', 'nowrap' => 'No Wrap' ) ),
			),
			'Cards & Footers' => array(
				'card_bg_start'            => array( 'type' => 'color',   'label' => 'Background Start',       'css_var' => '--mmi-card-bg-start',            'default' => '#ffffff' ),
				'card_bg_end'              => array( 'type' => 'color',   'label' => 'Background End',         'css_var' => '--mmi-card-bg-end',              'default' => '#fafafa' ),
				'card_border_color'        => array( 'type' => 'color',   'label' => 'Border',                 'css_var' => '--mmi-card-border-color',        'default' => '#e0e0e0' ),
				'card_radius'              => array( 'type' => 'spacing', 'label' => 'Corner Radius',          'css_var' => '--mmi-card-radius',              'default' => '12px' ),
				'card_padding'             => array( 'type' => 'spacing', 'label' => 'Padding',                'css_var' => '--mmi-card-padding',             'default' => '24px' ),
				'card_margin'              => array( 'type' => 'spacing', 'label' => 'Margin',                 'css_var' => '--mmi-card-margin',              'default' => '0 0 20px' ),
				'card_footer_border_color' => array( 'type' => 'color',   'label' => 'Footer Border',          'css_var' => '--mmi-card-footer-border-color', 'default' => '#f0f0f0' ),
				'card_footer_padding'      => array( 'type' => 'spacing', 'label' => 'Footer Padding',         'css_var' => '--mmi-card-footer-padding',      'default' => '16px 0 0' ),
				'card_footer_margin'       => array( 'type' => 'spacing', 'label' => 'Footer Margin',          'css_var' => '--mmi-card-footer-margin',       'default' => '20px 0 0' ),
				'card_footer_display'      => array( 'type' => 'select',  'label' => 'Footer Layout',          'css_var' => '--mmi-card-footer-display',      'default' => 'block', 'options' => array( 'block' => 'Block', 'flex' => 'Flex' ) ),
				'card_footer_flex_wrap'    => array( 'type' => 'select',  'label' => 'Footer Wrap',            'css_var' => '--mmi-card-footer-flex-wrap',    'default' => 'wrap', 'options' => array( 'wrap' => 'Wrap', 'nowrap' => 'No Wrap' ) ),
			),
			'Tables & Rows' => array(
				'table_head_bg'       => array( 'type' => 'color',   'label' => 'Header Background', 'css_var' => '--mmi-table-head-bg',        'default' => '#f6f7f7' ),
				'table_head_text'     => array( 'type' => 'color',   'label' => 'Header Text',        'css_var' => '--mmi-table-head-text',      'default' => '#3c434a' ),
				'table_border_color'  => array( 'type' => 'color',   'label' => 'Row Border',         'css_var' => '--mmi-table-border-color',   'default' => '#f0f0f0' ),
				'table_row_hover_bg'  => array( 'type' => 'color',   'label' => 'Row Hover Background', 'css_var' => '--mmi-table-row-hover-bg', 'default' => '#f6f9f2' ),
				'table_cell_padding'  => array( 'type' => 'spacing', 'label' => 'Cell Padding',       'css_var' => '--mmi-table-cell-padding',   'default' => '9px 12px', 'note' => 'Also used for header cells; body cells currently fall back to 6px 12px until this is shipped.' ),
			),
			'Buttons'       => array(
				'button_padding' => array( 'type' => 'spacing', 'label' => 'Padding', 'css_var' => '--mmi-button-padding', 'default' => '6px 14px' ),
				'button_margin'  => array( 'type' => 'spacing', 'label' => 'Margin',  'css_var' => '--mmi-button-margin',  'default' => '0' ),
			),
			'Logs'          => array(
				'log_padding'       => array( 'type' => 'spacing', 'label' => 'Container Padding', 'css_var' => '--mmi-log-padding',       'default' => '15px' ),
				'log_entry_margin'  => array( 'type' => 'spacing', 'label' => 'Entry Margin',       'css_var' => '--mmi-log-entry-margin',  'default' => '0 0 2px' ),
			),
			'Stat Tiles'    => array(
				'stat_bg_start'             => array( 'type' => 'color',   'label' => 'Background Start',       'css_var' => '--mmi-stat-bg-start',              'default' => '#fafafa' ),
				'stat_bg_end'               => array( 'type' => 'color',   'label' => 'Background End',         'css_var' => '--mmi-stat-bg-end',                'default' => '#f5f5f5' ),
				'stat_border_color'         => array( 'type' => 'color',   'label' => 'Left Border',            'css_var' => '--mmi-stat-border-color',          'default' => '#2271b1' ),
				'stat_border_generic_color' => array( 'type' => 'color',   'label' => 'Outer Border',           'css_var' => '--mmi-stat-border-generic-color',  'default' => '#e0e0e0', 'note' => 'Falls back to Cards & Footers\' Border color if unset — set here only to make the stat box\'s border independent of card styling.' ),
				'stat_padding'              => array( 'type' => 'spacing', 'label' => 'Padding',                'css_var' => '--mmi-stat-padding',               'default' => '18px 20px' ),
				'stat_radius'               => array( 'type' => 'spacing', 'label' => 'Corner Radius',          'css_var' => '--mmi-stat-radius',                'default' => '8px' ),
				'stat_min_height'           => array( 'type' => 'spacing', 'label' => 'Minimum Height',         'css_var' => '--mmi-stat-min-height',            'default' => '0', 'note' => 'Set a fixed height (e.g. 100px) to make tiles with different content lengths line up evenly. 0 leaves height to content, matching the box\'s current behavior.' ),
				'stat_label_color'          => array( 'type' => 'color',   'label' => 'Label Text',             'css_var' => '--mmi-stat-label-color',           'default' => '#646970' ),
				'stat_label_font_size'      => array( 'type' => 'spacing', 'label' => 'Label Font Size',        'css_var' => '--mmi-stat-label-font-size',       'default' => '12px' ),
				'stat_label_margin'         => array( 'type' => 'spacing', 'label' => 'Label Margin',           'css_var' => '--mmi-stat-label-margin',          'default' => '0 0 10px' ),
				'stat_value_color'          => array( 'type' => 'color',   'label' => 'Value Text',             'css_var' => '--mmi-stat-value-color',           'default' => '#1d2327' ),
				'stat_value_font_size'      => array( 'type' => 'spacing', 'label' => 'Value Font Size',        'css_var' => '--mmi-stat-value-font-size',       'default' => '28px' ),
				'stat_meta_font_size'       => array( 'type' => 'spacing', 'label' => 'Meta Font Size',         'css_var' => '--mmi-stat-meta-font-size',        'default' => '12px' ),
				'stat_meta_margin'          => array( 'type' => 'spacing', 'label' => 'Meta Margin',            'css_var' => '--mmi-stat-meta-margin',           'default' => '6px 0 0' ),
				'stat_success_bg_start'     => array( 'type' => 'color',   'label' => 'Success Background Start', 'css_var' => '--mmi-stat-success-bg-start',    'default' => '#f0fdf4' ),
				'stat_success_bg_end'       => array( 'type' => 'color',   'label' => 'Success Background End',   'css_var' => '--mmi-stat-success-bg-end',      'default' => '#dcfce7' ),
				'stat_warning_bg_start'     => array( 'type' => 'color',   'label' => 'Warning Background Start', 'css_var' => '--mmi-stat-warning-bg-start',    'default' => '#fffbeb' ),
				'stat_warning_bg_end'       => array( 'type' => 'color',   'label' => 'Warning Background End',   'css_var' => '--mmi-stat-warning-bg-end',      'default' => '#fef3c7' ),
				'stat_error_bg_start'       => array( 'type' => 'color',   'label' => 'Error Background Start',   'css_var' => '--mmi-stat-error-bg-start',      'default' => '#fef2f2' ),
				'stat_error_bg_end'         => array( 'type' => 'color',   'label' => 'Error Background End',     'css_var' => '--mmi-stat-error-bg-end',        'default' => '#fee2e2' ),
				'stat_info_bg_start'        => array( 'type' => 'color',   'label' => 'Info Background Start',    'css_var' => '--mmi-stat-info-bg-start',       'default' => '#eff6ff' ),
				'stat_info_bg_end'          => array( 'type' => 'color',   'label' => 'Info Background End',      'css_var' => '--mmi-stat-info-bg-end',         'default' => '#dbeafe' ),
				'stats_grid_min_col_width'  => array( 'type' => 'spacing', 'label' => 'Grid Min Column Width', 'css_var' => '--mmi-stats-grid-min-col-width',   'default' => '200px', 'note' => 'Used inside the grid\'s minmax(); a tile never renders narrower than this before wrapping to the next row.' ),
				'stats_grid_gap'            => array( 'type' => 'spacing', 'label' => 'Grid Gap',              'css_var' => '--mmi-stats-grid-gap',              'default' => '20px' ),
				'stats_grid_margin'         => array( 'type' => 'spacing', 'label' => 'Grid Margin',           'css_var' => '--mmi-stats-grid-margin',           'default' => '20px 0' ),
			),
			'Pagination'    => array(
				'pagination_padding'          => array( 'type' => 'spacing', 'label' => 'Padding',        'css_var' => '--mmi-pagination-padding',          'default' => '10px 12px' ),
				'pagination_gap'               => array( 'type' => 'spacing', 'label' => 'Gap',            'css_var' => '--mmi-pagination-gap',               'default' => '10px' ),
				'pagination_label_color'       => array( 'type' => 'color',   'label' => 'Label Text',     'css_var' => '--mmi-pagination-label-color',       'default' => '#646970' ),
				'pagination_btn_bg'            => array( 'type' => 'color',   'label' => 'Button Background', 'css_var' => '--mmi-pagination-btn-bg',         'default' => '#f6f7f7' ),
				'pagination_btn_border_color'  => array( 'type' => 'color',   'label' => 'Button Border',  'css_var' => '--mmi-pagination-btn-border-color',  'default' => '#ccd0d4' ),
				'pagination_btn_radius'        => array( 'type' => 'spacing', 'label' => 'Button Corner Radius', 'css_var' => '--mmi-pagination-btn-radius', 'default' => '4px' ),
			),
			'Modals'        => array(
				'modal_radius'              => array( 'type' => 'spacing', 'label' => 'Corner Radius',   'css_var' => '--mmi-modal-radius',              'default' => '8px' ),
				'modal_header_padding'      => array( 'type' => 'spacing', 'label' => 'Header Padding',  'css_var' => '--mmi-modal-header-padding',      'default' => '16px 20px' ),
				'modal_header_border_color' => array( 'type' => 'color',   'label' => 'Header Border',   'css_var' => '--mmi-modal-header-border-color', 'default' => '#e5e5e5' ),
				'modal_body_padding'        => array( 'type' => 'spacing', 'label' => 'Body Padding',    'css_var' => '--mmi-modal-body-padding',        'default' => '18px 20px' ),
				'modal_footer_padding'      => array( 'type' => 'spacing', 'label' => 'Footer Padding',  'css_var' => '--mmi-modal-footer-padding',      'default' => '14px 20px' ),
				'modal_close_hover_bg'      => array( 'type' => 'color',   'label' => 'Close Button Hover Background', 'css_var' => '--mmi-modal-close-hover-bg', 'default' => '#f0f0f0' ),
			),
			'Badges'        => array(
				'badge_padding'       => array( 'type' => 'spacing', 'label' => 'Padding',       'css_var' => '--mmi-badge-padding',    'default' => '3px 8px' ),
				'badge_radius'        => array( 'type' => 'spacing', 'label' => 'Corner Radius', 'css_var' => '--mmi-badge-radius',     'default' => '12px' ),
				'badge_font_size'     => array( 'type' => 'spacing', 'label' => 'Font Size',     'css_var' => '--mmi-badge-font-size',  'default' => '11px' ),
				'badge_bg'            => array( 'type' => 'color',   'label' => 'Background (default)', 'css_var' => '--mmi-badge-bg',         'default' => '#e5e5e5', 'note' => 'Success/warning/error/info variants use the shared semantic colors, not this field.' ),
				'badge_text_color'    => array( 'type' => 'color',   'label' => 'Text (default)',       'css_var' => '--mmi-badge-text-color', 'default' => '#50575e' ),
			),
			'Collapsible Sections' => array(
				'section_radius'              => array( 'type' => 'spacing', 'label' => 'Corner Radius',      'css_var' => '--mmi-section-radius',              'default' => '8px' ),
				'section_header_padding'      => array( 'type' => 'spacing', 'label' => 'Header Padding',     'css_var' => '--mmi-section-header-padding',      'default' => '11px 20px' ),
				'section_header_bg'           => array( 'type' => 'color',   'label' => 'Header Background',  'css_var' => '--mmi-section-header-bg',           'default' => '#f5f5f5' ),
				'section_header_border_color' => array( 'type' => 'color',   'label' => 'Header Border',      'css_var' => '--mmi-section-header-border-color', 'default' => '#e0e0e0' ),
				'section_content_padding'     => array( 'type' => 'spacing', 'label' => 'Content Padding',    'css_var' => '--mmi-section-content-padding',     'default' => '14px' ),
				'section_description_color'   => array( 'type' => 'color',   'label' => 'Description Text',   'css_var' => '--mmi-section-description-color',   'default' => '#666666' ),
			),
			'Toggles'       => array(
				'toggle_track_bg' => array( 'type' => 'color', 'label' => 'Track Background (off)', 'css_var' => '--mmi-toggle-track-bg', 'default' => '#cccccc', 'note' => 'Checked color uses the suite brand color (--mmi-primary), not a field here. Size/knob geometry is fixed — see mmi-suite-common.css comment.' ),
				'toggle_knob_bg'  => array( 'type' => 'color', 'label' => 'Knob Color',              'css_var' => '--mmi-toggle-knob-bg',  'default' => '#ffffff' ),
			),
			'Toolbar'       => array(
				'toolbar_gap'     => array( 'type' => 'spacing', 'label' => 'Gap',     'css_var' => '--mmi-toolbar-gap',     'default' => '12px' ),
				'toolbar_padding' => array( 'type' => 'spacing', 'label' => 'Padding', 'css_var' => '--mmi-toolbar-padding', 'default' => '0' ),
				'toolbar_margin'  => array( 'type' => 'spacing', 'label' => 'Margin',  'css_var' => '--mmi-toolbar-margin',  'default' => '16px 0' ),
			),
			'Widget Grid'   => array(
				'widget_grid_gap'        => array( 'type' => 'spacing', 'label' => 'Grid Gap',        'css_var' => '--mmi-widget-grid-gap',        'default' => '16px' ),
				'widget_grid_margin_top' => array( 'type' => 'spacing', 'label' => 'Grid Top Margin', 'css_var' => '--mmi-widget-grid-margin-top', 'default' => '20px' ),
				'widget_padding'         => array( 'type' => 'spacing', 'label' => 'Widget Padding',  'css_var' => '--mmi-widget-padding',         'default' => '12px 16px' ),
				'widget_bg'              => array( 'type' => 'color',   'label' => 'Widget Background', 'css_var' => '--mmi-widget-bg',            'default' => '#ffffff' ),
				'widget_border_color'    => array( 'type' => 'color',   'label' => 'Widget Border',   'css_var' => '--mmi-widget-border-color',    'default' => '#e5e5e5' ),
				'widget_radius'          => array( 'type' => 'spacing', 'label' => 'Widget Corner Radius', 'css_var' => '--mmi-widget-radius',    'default' => '6px' ),
			),
			'Animations'    => array(
				'animate_fade_duration' => array( 'type' => 'select',  'label' => 'Fade-In Speed', 'css_var' => '--mmi-animate-fade-duration', 'default' => '0.3s', 'options' => array( '0.15s' => 'Fast (0.15s)', '0.2s' => 'Normal (0.2s)', '0.3s' => 'Slow (0.3s)' ) ),
				'animate_fade_distance' => array( 'type' => 'spacing', 'label' => 'Fade-In Drop Distance', 'css_var' => '--mmi-animate-fade-distance', 'default' => '-10px', 'note' => 'How far the content drops down into place while fading in. Use a negative value.' ),
			),
			'Tooltips'      => array(
				'tooltip_width'      => array( 'type' => 'spacing', 'label' => 'Width',         'css_var' => '--mmi-tooltip-width',      'default' => '200px', 'note' => 'mmi-reverb-integration has its own competing local tooltip style (a documented shared-class collision) that this does not affect.' ),
				'tooltip_bg'         => array( 'type' => 'color',   'label' => 'Background',    'css_var' => '--mmi-tooltip-bg',         'default' => '#1d2327' ),
				'tooltip_text_color' => array( 'type' => 'color',   'label' => 'Text',          'css_var' => '--mmi-tooltip-text-color', 'default' => '#ffffff' ),
				'tooltip_radius'     => array( 'type' => 'spacing', 'label' => 'Corner Radius', 'css_var' => '--mmi-tooltip-radius',     'default' => '6px' ),
				'tooltip_padding'    => array( 'type' => 'spacing', 'label' => 'Padding',       'css_var' => '--mmi-tooltip-padding',    'default' => '8px' ),
			),
			// .mmi-statusbar, promoted from mmi-reverb-integration 2026-10-03 (shared lib 1.44.0).
			'Status Bar'    => array(
				'statusbar_bg'     => array( 'type' => 'color',   'label' => 'Background',     'css_var' => '--mmi-statusbar-bg',     'default' => '#ffffff' ),
				'statusbar_text'   => array( 'type' => 'color',   'label' => 'Message Text',   'css_var' => '--mmi-statusbar-text',   'default' => '#1d2327' ),
				'statusbar_muted'  => array( 'type' => 'color',   'label' => 'Count Text',     'css_var' => '--mmi-statusbar-muted',  'default' => '#646970' ),
				'statusbar_border' => array( 'type' => 'color',   'label' => 'Border',         'css_var' => '--mmi-statusbar-border', 'default' => '#dcdcde' ),
				'statusbar_accent' => array( 'type' => 'color',   'label' => 'Accent (edge, spinner, progress)', 'css_var' => '--mmi-statusbar-accent', 'default' => '#45852C', 'note' => 'Complete and error states use the suite success/error colors.' ),
				'statusbar_track'  => array( 'type' => 'color',   'label' => 'Progress Track', 'css_var' => '--mmi-statusbar-track',  'default' => '#dbe8d5' ),
				'statusbar_radius' => array( 'type' => 'spacing', 'label' => 'Corner Radius',  'css_var' => '--mmi-statusbar-radius', 'default' => '6px' ),
			),
		);
	}

	/** Flat key => default, derived from the schema above. */
	private static function schema_defaults(): array {
		$defaults = array();
		foreach ( self::field_schema() as $fields ) {
			foreach ( $fields as $key => $meta ) {
				$defaults[ $key ] = $meta['default'];
			}
		}
		return $defaults;
	}

	/**
	 * The shipped-default layer. Reads ui-style-tokens-default.php if a Ship
	 * has ever happened; otherwise returns the schema's own CSS-native
	 * fallback values, which is exactly what every admin page already
	 * renders today (the vars are currently undefined in :root, so the
	 * inline `var(--x, fallback)` fallback is what's live) — an unshipped
	 * site therefore has nothing to apply, by design (see
	 * mmi_shared_assets_enqueue()'s is_file() guard in bootstrap.php).
	 */
	public static function published_tokens(): array {
		if ( self::$published_tokens === null ) {
			$file   = __DIR__ . '/ui-style-tokens-default.php';
			$loaded = is_file( $file ) ? include $file : null;
			self::$published_tokens = is_array( $loaded )
				? array_merge( self::schema_defaults(), $loaded )
				: self::schema_defaults();
		}
		return self::$published_tokens;
	}

	/** Sanitize a posted token map down to known keys, validated per field type. */
	public static function sanitize_tokens( array $raw ): array {
		$flat = array();
		foreach ( self::field_schema() as $fields ) {
			foreach ( $fields as $key => $meta ) {
				$flat[ $key ] = $meta;
			}
		}

		$clean = array();
		foreach ( $flat as $key => $meta ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = $raw[ $key ];
			switch ( $meta['type'] ) {
				case 'color':
					$value = is_string( $value ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? $value : $meta['default'];
					break;
				case 'select':
					$value = is_string( $value ) && array_key_exists( $value, $meta['options'] ) ? $value : $meta['default'];
					break;
				case 'spacing':
					// 1-4 space-separated CSS length values (px/em/rem/% or bare 0) —
					// this is emitted verbatim inside a generated <style> block, so
					// anything outside this charset (';', '{', '}', quotes, url())
					// is rejected outright rather than escaped, matching the
					// AGENTS.md CSS-custom-property injection pattern's intent.
					$length = '(?:-?\d+(?:\.\d+)?(?:px|em|rem|%)|0)';
					$value  = is_string( $value ) && preg_match( '/^' . $length . '(?:\s' . $length . '){0,3}$/', trim( $value ) )
						? trim( $value )
						: $meta['default'];
					break;
				default:
					$value = $meta['default'];
			}
			$clean[ $key ] = $value;
		}
		return $clean;
	}
}
