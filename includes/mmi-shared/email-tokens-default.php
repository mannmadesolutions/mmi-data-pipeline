<?php
/**
 * MMI Suite — Published Email Template Tokens (shipped defaults).
 *
 * Rebuilt 2026-09-18 — this replaces mmi-hub's deleted
 * includes/email/email-tokens-default.php as MMI_Email_Templates'
 * "published" (shipped-default) token layer. See class-email-templates.php's
 * docblock for the full three-layer design (preview > site > published).
 *
 * Values match mmi-suite-common.css's :root custom properties
 * (mmi-admin/lib/mmi-shared/assets/css/shared/mmi-suite-common.css) so
 * MMI-branded operational emails (alerts, digests) look consistent with the
 * admin UI they link back to. Colors here are literal hex, not CSS custom
 * properties — most email clients (Outlook desktop, Gmail on some paths)
 * don't reliably support `var()`, so every render_* method inlines these as
 * plain hex values rather than injecting a `--token: value` style attribute
 * the way admin-UI templates do (see AGENTS.md's Styling & CSS Architecture
 * section — this is the documented email-HTML exception to that rule).
 *
 * Edited by staff via MMI Admin's Email Customization tab (mmi-admin's own
 * "Ship" workflow) — NOTE: as of this rebuild, that tab's ajax_ship() still
 * writes to a `mmi-hub/...` path that no longer exists (mmi-hub was deleted
 * 2026-09-17, changelog 1.173.0) and its "publish as a plugin update" model
 * has no real target anymore. That's a pre-existing gap from the mmi-hub
 * deletion, not something this rebuild fixes — see changelog.md's 1.187.0
 * entry. published_tokens() below reads this file directly regardless.
 *
 * Do not hand-edit this file while relying on the Email Customization tab's
 * draft/ship workflow to work again in the future — until that pipeline is
 * redesigned to target this file instead of the old mmi-hub path, the only
 * way to change these shipped defaults is to edit this file directly and
 * bump the shared library version (see bootstrap.php).
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

return array(
	// Brand colors — mirror mmi-suite-common.css's :root.
	'primary'            => '#45852C', // --mmi-primary
	'accent'             => '#72aee6', // --mmi-accent
	'neutral'            => '#646970', // --mmi-gray-600 / --mmi-text-secondary
	'text_dark'          => '#1d2327', // --mmi-text-primary

	// Status colors.
	'danger'             => '#d63638', // --mmi-error
	'danger_bg'          => '#f8d7da', // --mmi-error-bg
	'warning'            => '#dba617', // --mmi-warning
	'warning_bg'         => '#fff3cd', // --mmi-warning-bg

	// Layout.
	'bg_panel'           => '#f8f9fa', // --mmi-bg-light
	'border'             => '#e5e5e5', // --mmi-gray-300

	// Branding — site_only in field_schema(): a shipped default here would
	// push MannMade's own logo/footer onto every customer site, so these
	// stay empty at the published layer and are meant to be set at the site
	// layer only (MMI Email Customizer -> MMI Emails tab).
	'logo_url'           => '',
	'footer_text'        => '',
	'digest_footer_text' => '',
);
