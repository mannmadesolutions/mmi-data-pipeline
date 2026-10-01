<?php
/**
 * MMI_Email_Templates — shared HTML email design system (bundled/vendored copy).
 *
 * Rebuilt from scratch 2026-09-18. This class lived only in mmi-hub
 * (includes/email/class-mmi-email-templates.php) and was missed by the
 * mmi-hub-elimination migration (ADR-0006/0007/0008) — every one of its ~15
 * call sites across 6 plugins was already `class_exists('MMI_Email_Templates')`
 * guarded, so nothing fataled once mmi-hub was deleted (1.173.0); every branded
 * email just silently fell back to plain text (or, for customer-facing sends
 * with no fallback, stopped sending its styled body at all). Confirmed via
 * `class_exists()` and a full-tree grep that no copy of the original file
 * survived anywhere on disk — this is a genuine reconstruction from call-site
 * evidence (exact argument/return shapes read from every consumer) plus the
 * pre-existing design-intent doc comments those consumers still carried,
 * following the same reconstruction pattern used for
 * `\MannMade\Integrations\Acquisition\Data_Source_Manager` (changelog 1.182.0).
 *
 * ── Two visual identities, deliberately ──────────────────────────────────
 * Reading every call site turned up a real design split, not an accident:
 *
 *   - render_alert(), render_daily_digest(), render_notice(), and the digest
 *     card/cell primitives are INTERNAL/OWNER-facing (failure alerts, the
 *     daily process digest, license-invalidation notices, cron-health
 *     warnings) — these use the MMI Suite's own brand tokens (green primary,
 *     etc.), because the recipient is the site owner looking at MMI's own
 *     admin tools, and every plugin's alert should look like it came from
 *     the same suite.
 *   - render_customer_notice() is CUSTOMER-facing (license trial/renewal
 *     mail, the XChange "your software is ready" email) — these deliberately
 *     read WooCommerce's own `woocommerce_email_*` options instead, so a
 *     customer sees the same brand identity their order-confirmation email
 *     already uses, not a visually distinct "MMI Suite" style. This was
 *     stated explicitly in every customer-notice call site's docblock
 *     ("render_customer_notice() ... so it matches WooCommerce's own email
 *     branding rather than a separate MMI Suite style") and is preserved
 *     here rather than "simplified" onto one shared palette.
 *
 * ── Three-layer token system (site-editable colors/logo/footer) ─────────
 * Per the pre-existing design doc (`mmi-email-customization-layers` memory,
 * predates this rebuild), tokens resolve highest-priority first:
 *
 *   1. preview — request-scoped only, staged by either customizer's AJAX
 *      preview/test-send via set_preview_tokens(); never persisted.
 *   2. site    — MMI_Settings['mmi_email_site_tokens'], edited by the
 *      customer in MMI Email Customizer -> MMI Emails; only the diff from
 *      the published layer is stored, so it survives this file's own
 *      updates untouched.
 *   3. published — email-tokens-default.php (sibling file in this same
 *      directory), MMI's shipped defaults. Was mmi-hub's
 *      includes/email/email-tokens-default.php; relocated here since
 *      mmi-hub no longer exists. See that file's own docblock for the
 *      still-broken staff "Ship" pipeline this doesn't fix (found, not
 *      fixed — changelog 1.187.0).
 *
 * ── Sample registry ───────────────────────────────────────────────────────
 * get_samples() is a thin wrapper over the pre-existing
 * `mmi_email_customizer_samples` filter — every consuming plugin already
 * registers its own sample(s) through that filter (9 registrations found
 * live across 6 plugins), all already guarded with
 * `class_exists('MMI_Email_Templates')`. This class deliberately has zero
 * hardcoded knowledge of any specific plugin's email types — exactly the
 * extensibility the mmi-email-customizer doc comment described ("a new
 * plugin's email shows up here with no change to this file").
 *
 * Do not hand-edit this file in a single plugin; edit the canonical source
 * (mmi-admin/lib/mmi-shared/) and re-sync (sync-to-plugins.sh) to every
 * plugin that bundles it. See ADR-0006.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_Email_Templates {

	const SITE_TOKENS_SETTING = 'mmi_email_site_tokens';

	/** Request-scoped preview override layer. Null = not staged. */
	private static ?array $preview_tokens = null;

	/**
	 * Cached published (shipped-default) token layer. Deliberately a plain
	 * private static property (not a method-local static) — mmi-admin's
	 * Email Customization "Ship" workflow resets this via ReflectionProperty
	 * after rewriting the defaults file mid-request, so a later call in the
	 * same request re-reads instead of serving a stale cache.
	 */
	private static ?array $published_tokens = null;

	/* ── Token system ────────────────────────────────────────────────────── */

	/**
	 * Hardcoded last-resort defaults — used only if email-tokens-default.php
	 * is ever missing or returns something malformed, so a broken/missing
	 * defaults file degrades to "usable suite colors" rather than empty
	 * strings or a fatal array-key error.
	 */
	private static function fallback_tokens(): array {
		return array(
			'primary'            => '#45852C',
			'accent'             => '#72aee6',
			'neutral'            => '#646970',
			'text_dark'          => '#1d2327',
			'danger'             => '#d63638',
			'danger_bg'          => '#f8d7da',
			'warning'            => '#dba617',
			'warning_bg'         => '#fff3cd',
			'bg_panel'           => '#f8f9fa',
			'border'             => '#e5e5e5',
			'logo_url'           => '',
			'footer_text'        => '',
			'digest_footer_text' => '',
		);
	}

	/** The shipped-default layer — loaded once per request, cached. */
	public static function published_tokens(): array {
		if ( self::$published_tokens === null ) {
			$file   = __DIR__ . '/email-tokens-default.php';
			$loaded = is_file( $file ) ? include $file : null;
			self::$published_tokens = is_array( $loaded )
				? array_merge( self::fallback_tokens(), $loaded )
				: self::fallback_tokens();
		}
		return self::$published_tokens;
	}

	/** This site's saved overrides only (diff from published) — not merged with defaults. */
	public static function site_tokens(): array {
		if ( ! class_exists( 'MMI_Settings' ) ) {
			return array();
		}
		$tokens = MMI_Settings::get( self::SITE_TOKENS_SETTING, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	public static function has_site_tokens(): bool {
		return ! empty( self::site_tokens() );
	}

	/**
	 * Persist a site override set, storing only values that actually differ
	 * from the published default — so a future change to the shipped default
	 * (a new email-tokens-default.php) is inherited by any token this site
	 * never touched, instead of being permanently pinned to whatever the
	 * default happened to be the day this was first saved.
	 */
	public static function set_site_tokens( array $tokens ): void {
		if ( ! class_exists( 'MMI_Settings' ) ) {
			return;
		}
		$published = self::published_tokens();
		$diff      = array();
		foreach ( $tokens as $key => $value ) {
			if ( ! array_key_exists( $key, $published ) || (string) $published[ $key ] !== (string) $value ) {
				$diff[ $key ] = $value;
			}
		}
		MMI_Settings::set( self::SITE_TOKENS_SETTING, $diff );
	}

	public static function clear_site_tokens(): void {
		if ( class_exists( 'MMI_Settings' ) ) {
			MMI_Settings::delete( self::SITE_TOKENS_SETTING );
		}
	}

	/** Request-scoped-only staging for live preview / test-send — never persisted. */
	public static function set_preview_tokens( array $tokens ): void {
		self::$preview_tokens = $tokens;
	}

	public static function clear_preview_tokens(): void {
		self::$preview_tokens = null;
	}

	/** Fully resolved token map: published, with site overrides, with preview staged on top. */
	public static function effective_tokens(): array {
		$tokens = array_merge( self::published_tokens(), self::site_tokens() );
		if ( self::$preview_tokens !== null ) {
			$tokens = array_merge( $tokens, self::$preview_tokens );
		}
		return $tokens;
	}

	/** A single resolved token value (a color, in almost every call site). */
	public static function token( string $name ): string {
		$tokens = self::effective_tokens();
		if ( isset( $tokens[ $name ] ) && $tokens[ $name ] !== '' ) {
			return (string) $tokens[ $name ];
		}
		$fallback = self::fallback_tokens();
		return $fallback[ $name ] ?? '#000000';
	}

	/**
	 * Preview-highlighting attribute only — NOT a CSS custom property.
	 *
	 * AGENTS.md's Styling & CSS Architecture rule requires dynamic values to
	 * flow through CSS custom properties, never literal inline styles — but
	 * email HTML is the documented exception (see this file's own docblock):
	 * most email clients don't reliably support `var()`, so every render
	 * method below inlines resolved hex values directly. What every call
	 * site actually wants from data_attr() is a plain `data-mmi-token="..."`
	 * marker consumed client-side, in the browser, by
	 * mmi-email-customizer/assets/js/mmi-emails.js's field-highlight and
	 * usage-badge logic (`$doc.find('[data-mmi-token]')` /
	 * `.split(' ')` on the attribute value) — it never reaches a real sent
	 * email's rendering, only the customizer's own preview iframe.
	 *
	 * @param string[] $keys Token names this element's styling depends on.
	 * @return string Leading-space HTML attribute fragment, e.g. ` data-mmi-token="bg_panel border"`.
	 */
	public static function data_attr( array $keys ): string {
		$keys = array_filter( array_map( 'sanitize_key', $keys ) );
		if ( empty( $keys ) ) {
			return '';
		}
		return ' data-mmi-token="' . esc_attr( implode( ' ', $keys ) ) . '"';
	}

	/**
	 * Editable-token schema, grouped for the editor UI.
	 *
	 * @param bool $include_site_only False excludes tokens meaningful only at
	 *   the per-site layer (currently just the logo) — used by mmi-admin's
	 *   staff "published defaults" editor, since a shipped logo default would
	 *   push MannMade's own logo onto every customer site.
	 */
	public static function field_schema( bool $include_site_only = true ): array {
		$schema = array(
			'Brand Colors'  => array(
				'primary'    => array( 'type' => 'color', 'label' => 'Primary / Success' ),
				'accent'     => array( 'type' => 'color', 'label' => 'Accent' ),
				'neutral'    => array( 'type' => 'color', 'label' => 'Neutral' ),
				'text_dark'  => array( 'type' => 'color', 'label' => 'Body Text' ),
			),
			'Status Colors' => array(
				'danger'     => array( 'type' => 'color', 'label' => 'Danger' ),
				'danger_bg'  => array( 'type' => 'color', 'label' => 'Danger Background' ),
				'warning'    => array( 'type' => 'color', 'label' => 'Warning' ),
				'warning_bg' => array( 'type' => 'color', 'label' => 'Warning Background' ),
			),
			'Layout'        => array(
				'bg_panel'   => array( 'type' => 'color', 'label' => 'Panel Background' ),
				'border'     => array( 'type' => 'color', 'label' => 'Border' ),
			),
			'Branding'      => array(
				'logo_url'           => array( 'type' => 'image', 'label' => 'Email Logo', 'site_only' => true ),
				'footer_text'        => array( 'type' => 'textarea', 'label' => 'Footer Text (all emails)' ),
				'digest_footer_text' => array( 'type' => 'textarea', 'label' => 'Footer Text (daily digest only)' ),
			),
		);

		if ( $include_site_only ) {
			return $schema;
		}

		foreach ( $schema as $group => $fields ) {
			foreach ( $fields as $key => $meta ) {
				if ( ! empty( $meta['site_only'] ) ) {
					unset( $schema[ $group ][ $key ] );
				}
			}
			if ( empty( $schema[ $group ] ) ) {
				unset( $schema[ $group ] );
			}
		}
		return $schema;
	}

	/** Sanitize a posted token map down to known keys, validated per field type. */
	public static function sanitize_tokens( array $raw, bool $include_site_only = true ): array {
		$flat = array();
		foreach ( self::field_schema( $include_site_only ) as $fields ) {
			foreach ( $fields as $key => $meta ) {
				$flat[ $key ] = $meta['type'];
			}
		}

		$clean = array();
		foreach ( $flat as $key => $type ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = $raw[ $key ];
			switch ( $type ) {
				case 'color':
					$value = is_string( $value ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? $value : '';
					break;
				case 'image':
					$value = esc_url_raw( (string) $value );
					break;
				case 'textarea':
					$value = wp_kses_post( (string) $value );
					break;
				case 'number':
					$value = (string) absint( $value );
					break;
				default:
					$value = sanitize_text_field( (string) $value );
			}
			$clean[ $key ] = $value;
		}
		return $clean;
	}

	/** Resolve the footer text a template should actually print. */
	private static function resolve_footer( string $caller_footer, string $site_name, ?string $specific_key = null ): string {
		$tokens = self::effective_tokens();
		$footer = $caller_footer;

		if ( ! empty( $tokens['footer_text'] ) ) {
			$footer = $tokens['footer_text'];
		}
		if ( $specific_key !== null && ! empty( $tokens[ $specific_key ] ) ) {
			$footer = $tokens[ $specific_key ];
		}

		return str_replace( '{site_name}', $site_name, $footer );
	}

	/* ── Sample registry ─────────────────────────────────────────────────── */

	/**
	 * Every registered [{id, group, label, render:closure}] — collected via
	 * the pre-existing `mmi_email_customizer_samples` filter every consuming
	 * plugin already hooks into. Not cached: samples' render closures often
	 * close over live option values, and this is only ever called from an
	 * admin-only preview/editor request, never a hot path.
	 */
	public static function get_samples(): array {
		return (array) apply_filters( 'mmi_email_customizer_samples', array() );
	}

	/** Grouped for an <optgroup> picker: [ group label => [ id => label ] ]. */
	public static function get_sample_groups(): array {
		$groups = array();
		foreach ( self::get_samples() as $sample ) {
			if ( empty( $sample['id'] ) ) {
				continue;
			}
			$group = $sample['group'] ?? 'Other';
			$groups[ $group ][ $sample['id'] ] = $sample['label'] ?? $sample['id'];
		}
		return $groups;
	}

	public static function sample_label( string $id ): string {
		foreach ( self::get_samples() as $sample ) {
			if ( ( $sample['id'] ?? '' ) === $id ) {
				return $sample['label'] ?? $id;
			}
		}
		return $id;
	}

	public static function render_sample( string $id ): string {
		foreach ( self::get_samples() as $sample ) {
			if ( ( $sample['id'] ?? '' ) === $id && isset( $sample['render'] ) && is_callable( $sample['render'] ) ) {
				return (string) call_user_func( $sample['render'] );
			}
		}
		return '<p style="padding:24px;font-family:sans-serif;color:#767676;">No sample registered for "' . esc_html( $id ) . '".</p>';
	}

	/* ── Shared HTML scaffolding (internal/owner-facing frame) ──────────── */

	/**
	 * The outer envelope every internal/owner-facing template (render_alert,
	 * render_daily_digest, render_notice) shares: gray page background,
	 * centered white content column, optional logo, colored top status bar,
	 * inner content, footer. Table-based, inline-styled — no external
	 * stylesheet, matches the pattern already embedded inline in
	 * class-pipeline-cron.php's pre-existing fallback/alert HTML.
	 */
	private static function frame_html( string $inner_html, string $status_color, string $footer_text ): string {
		$logo_url = self::token( 'logo_url' );
		$bg_panel = self::token( 'bg_panel' );
		$border   = self::token( 'border' );

		$logo_html = $logo_url !== ''
			? '<tr><td style="padding:24px 32px 0;text-align:center;">'
				. '<img src="' . esc_url( $logo_url ) . '" alt="" style="max-height:48px;max-width:220px;">'
				. '</td></tr>'
			: '';

		return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>'
			. '<body style="margin:0;padding:0;background:' . esc_attr( $bg_panel ) . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . esc_attr( $bg_panel ) . ';padding:24px 12px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid ' . esc_attr( $border ) . ';border-radius:8px;overflow:hidden;">'
			. $logo_html
			. '<tr><td style="height:6px;background:' . esc_attr( $status_color ) . ';font-size:0;line-height:0;">&nbsp;</td></tr>'
			. '<tr><td style="padding:28px 32px;">' . $inner_html . '</td></tr>'
			. '<tr><td style="padding:16px 32px 24px;border-top:1px solid ' . esc_attr( $border ) . ';">'
			. '<p style="margin:0;font-size:12px;line-height:1.5;color:#767676;">' . wp_kses_post( $footer_text ) . '</p>'
			. '</td></tr>'
			. '</table>'
			. '</td></tr>'
			. '</table>'
			. '</body></html>';
	}

	private static function cta_button_html( string $url, string $label, string $color ): string {
		if ( $url === '' || $label === '' ) {
			return '';
		}
		return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0 4px;">'
			. '<tr><td style="border-radius:6px;background:' . esc_attr( $color ) . ';">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:11px 22px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:6px;">'
			. wp_kses_post( $label ) . '</a>'
			. '</td></tr></table>';
	}

	/* ── render_alert() — full incident/status report ────────────────────── */

	/**
	 * @param array $args {
	 *   site_name?, headline, status_color, status_label, completed_at,
	 *   duration, summary_stats: [{label, value, highlight?}], dashboard_url,
	 *   cta_label, footer_text?, detail_table_header?, detail_table_rows?
	 *   (both raw <tr>...</tr> HTML, appended into a detail <table> when either is set)
	 * }
	 */
	public static function render_alert( array $args ): string {
		$site_name     = (string) ( $args['site_name'] ?? get_bloginfo( 'name' ) );
		$headline      = (string) ( $args['headline'] ?? '' );
		$status_color  = (string) ( $args['status_color'] ?? self::token( 'primary' ) );
		$status_label  = (string) ( $args['status_label'] ?? '' );
		$completed_at  = (string) ( $args['completed_at'] ?? '' );
		$duration      = (string) ( $args['duration'] ?? '' );
		$summary_stats = (array) ( $args['summary_stats'] ?? array() );
		$dashboard_url = (string) ( $args['dashboard_url'] ?? admin_url() );
		$cta_label     = (string) ( $args['cta_label'] ?? 'View Dashboard &rarr;' );
		$detail_header = (string) ( $args['detail_table_header'] ?? '' );
		$detail_rows   = (string) ( $args['detail_table_rows'] ?? '' );
		$footer_text   = self::resolve_footer( (string) ( $args['footer_text'] ?? "Sent by the MMI Suite on {$site_name}." ), $site_name );

		$text_dark = self::token( 'text_dark' );
		$border    = self::token( 'border' );

		$stats_html = '';
		$total      = count( $summary_stats );
		$i          = 0;
		foreach ( $summary_stats as $stat ) {
			$i++;
			$color        = ! empty( $stat['highlight'] ) ? self::token( 'danger' ) : $text_dark;
			$border_style = $i < $total ? 'border-right:1px solid ' . esc_attr( $border ) . ';' : '';
			$stats_html  .= '<td style="padding:14px 8px;text-align:center;' . $border_style . '">'
				. '<div style="font-size:19px;font-weight:700;color:' . esc_attr( $color ) . ';">' . esc_html( (string) ( $stat['value'] ?? '' ) ) . '</div>'
				. '<div style="font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#767676;margin-top:3px;">' . esc_html( (string) ( $stat['label'] ?? '' ) ) . '</div>'
				. '</td>';
		}

		$meta_html = '';
		if ( $completed_at !== '' || $duration !== '' ) {
			$bits = array_filter( array(
				$completed_at !== '' ? 'Completed: ' . esc_html( $completed_at ) : '',
				$duration !== '' ? 'Duration: ' . esc_html( $duration ) : '',
			) );
			$meta_html = '<p style="margin:4px 0 16px;font-size:12px;color:#767676;">' . implode( ' &bull; ', $bits ) . '</p>';
		}

		$detail_html = '';
		if ( $detail_header !== '' || $detail_rows !== '' ) {
			$detail_html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px;border:1px solid ' . esc_attr( $border ) . ';border-radius:6px;overflow:hidden;">'
				. $detail_header . $detail_rows . '</table>';
		}

		$inner = '<h1 style="margin:0 0 6px;font-size:19px;color:' . esc_attr( $text_dark ) . ';">' . esc_html( $headline ) . '</h1>'
			. '<p style="margin:0 0 10px;font-size:13px;font-weight:700;color:' . esc_attr( $status_color ) . ';text-transform:uppercase;letter-spacing:.03em;">' . esc_html( $status_label ) . '</p>'
			. $meta_html
			. ( $stats_html !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ' . esc_attr( $border ) . ';border-radius:6px;"><tr>' . $stats_html . '</tr></table>' : '' )
			. $detail_html
			. self::cta_button_html( $dashboard_url, $cta_label, self::token( 'primary' ) );

		return self::frame_html( $inner, $status_color, $footer_text );
	}

	/* ── render_notice() — lightweight single-box internal notice ───────── */

	/**
	 * @param array $args { site_name?, headline, body_html, status_color?,
	 *   cta_url?, cta_label?, footer_text? }
	 */
	public static function render_notice( array $args ): string {
		$site_name    = (string) ( $args['site_name'] ?? get_bloginfo( 'name' ) );
		$headline     = (string) ( $args['headline'] ?? '' );
		$body_html    = (string) ( $args['body_html'] ?? '' );
		$status_color = (string) ( $args['status_color'] ?? self::token( 'primary' ) );
		$cta_url      = (string) ( $args['cta_url'] ?? '' );
		$cta_label    = (string) ( $args['cta_label'] ?? '' );
		$footer_text  = self::resolve_footer( (string) ( $args['footer_text'] ?? "Sent by the MMI Suite on {$site_name}." ), $site_name );

		$inner = '<h1 style="margin:0 0 14px;font-size:19px;color:' . esc_attr( self::token( 'text_dark' ) ) . ';">' . esc_html( $headline ) . '</h1>'
			. '<div style="font-size:14px;line-height:1.6;color:' . esc_attr( self::token( 'text_dark' ) ) . ';">' . wp_kses_post( $body_html ) . '</div>'
			. self::cta_button_html( $cta_url, $cta_label, self::token( 'primary' ) );

		return self::frame_html( $inner, $status_color, $footer_text );
	}

	/* ── Daily digest primitives ─────────────────────────────────────────── */

	public static function render_hero_cell( string $value, string $label, ?string $color = null, bool $has_border = true ): string {
		$color        = $color ?? self::token( 'text_dark' );
		$border_style = $has_border ? 'border-right:1px solid ' . esc_attr( self::token( 'border' ) ) . ';' : '';
		return '<td style="padding:16px 8px;text-align:center;' . $border_style . '">'
			. '<div style="font-size:24px;font-weight:700;color:' . esc_attr( $color ) . ';">' . esc_html( $value ) . '</div>'
			. '<div style="font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#767676;margin-top:4px;">' . esc_html( $label ) . '</div>'
			. '</td>';
	}

	public static function render_card_stat_cell( string $value, string $label, ?string $color = null, bool $has_border = true ): string {
		$color        = $color ?? self::token( 'text_dark' );
		$border_style = $has_border ? 'border-right:1px solid ' . esc_attr( self::token( 'border' ) ) . ';' : '';
		return '<td style="padding:10px 6px;text-align:center;' . $border_style . '">'
			. '<div style="font-size:15px;font-weight:700;color:' . esc_attr( $color ) . ';">' . esc_html( $value ) . '</div>'
			. '<div style="font-size:9px;text-transform:uppercase;letter-spacing:.03em;color:#767676;margin-top:2px;">' . esc_html( $label ) . '</div>'
			. '</td>';
	}

	public static function render_stable_banner( string $text ): string {
		$bg_panel = self::token( 'bg_panel' );
		return '<tr><td colspan="12" style="padding:6px 12px;font-size:11px;color:#767676;background:' . esc_attr( $bg_panel ) . ';">'
			. '&#10003; ' . wp_kses_post( $text ) . '</td></tr>';
	}

	/**
	 * One process card — a colored-accent block with a header (label + status
	 * badge), an optional subtitle/time range, an optional "stable" banner
	 * row, and a row of stat cells (built by render_card_stat_cell()).
	 */
	public static function render_process_card( string $color, string $label, string $badge, string $time_range, string $stable_row_html, string $cells_html ): string {
		$border = self::token( 'border' );
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px;border:1px solid ' . esc_attr( $border ) . ';border-left:4px solid ' . esc_attr( $color ) . ';border-radius:6px;overflow:hidden;">'
			. '<tr><td style="padding:12px 14px 6px;">'
			. '<span style="font-size:14px;font-weight:700;color:' . esc_attr( self::token( 'text_dark' ) ) . ';">' . esc_html( $label ) . '</span>'
			. '&nbsp;&nbsp;<span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:' . esc_attr( $color ) . ';">' . esc_html( $badge ) . '</span>'
			. ( $time_range !== '' ? '<div style="font-size:11px;color:#767676;margin-top:2px;">' . esc_html( $time_range ) . '</div>' : '' )
			. '</td></tr>'
			. $stable_row_html
			. '<tr>' . $cells_html . '</tr>'
			. '</table>';
	}

	/**
	 * A compact table card for a "family" of related processes (e.g. several
	 * per-supplier Data Fetch runs), instead of repeating a full card per member.
	 *
	 * @param string[] $columns Column header labels.
	 * @param array    $rows    [{cells: [colLabel => string|{value,color}]}]
	 */
	public static function render_process_table_card( string $color, string $family_label, string $badge, array $columns, array $rows ): string {
		$border    = self::token( 'border' );
		$text_dark = self::token( 'text_dark' );

		$head = '';
		foreach ( $columns as $col ) {
			$head .= '<th style="padding:6px 8px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.03em;color:#767676;border-bottom:1px solid ' . esc_attr( $border ) . ';">' . esc_html( $col ) . '</th>';
		}

		$body = '';
		foreach ( $rows as $row ) {
			$body .= '<tr>';
			foreach ( $columns as $col ) {
				$cell = $row['cells'][ $col ] ?? '';
				if ( is_array( $cell ) ) {
					$value = esc_html( (string) ( $cell['value'] ?? '' ) );
					$color_cell = esc_attr( $cell['color'] ?? $text_dark );
					$body .= '<td style="padding:6px 8px;font-size:12px;color:' . $color_cell . ';border-bottom:1px solid ' . esc_attr( $border ) . ';">' . $value . '</td>';
				} else {
					$body .= '<td style="padding:6px 8px;font-size:12px;color:' . esc_attr( $text_dark ) . ';border-bottom:1px solid ' . esc_attr( $border ) . ';">' . esc_html( (string) $cell ) . '</td>';
				}
			}
			$body .= '</tr>';
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px;border:1px solid ' . esc_attr( $border ) . ';border-left:4px solid ' . esc_attr( $color ) . ';border-radius:6px;overflow:hidden;">'
			. '<tr><td style="padding:12px 14px 8px;">'
			. '<span style="font-size:14px;font-weight:700;color:' . esc_attr( $text_dark ) . ';">' . esc_html( $family_label ) . '</span>'
			. '&nbsp;&nbsp;<span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:' . esc_attr( $color ) . ';">' . esc_html( $badge ) . '</span>'
			. '</td></tr>'
			. '<tr><td style="padding:0 14px 12px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $head . '</tr>' . $body . '</table>'
			. '</td></tr>'
			. '</table>';
	}

	/**
	 * @param array $args { site_name?, today, hero_cells (raw <td>s), process_cards
	 *   (raw card blocks), dashboard_url, cta_label, footer_text? }
	 */
	public static function render_daily_digest( array $args ): string {
		$site_name     = (string) ( $args['site_name'] ?? get_bloginfo( 'name' ) );
		$today         = (string) ( $args['today'] ?? '' );
		$hero_cells    = (string) ( $args['hero_cells'] ?? '' );
		$process_cards = (string) ( $args['process_cards'] ?? '' );
		$dashboard_url = (string) ( $args['dashboard_url'] ?? admin_url() );
		$cta_label     = (string) ( $args['cta_label'] ?? 'View Dashboard &rarr;' );
		$footer_text   = self::resolve_footer(
			(string) ( $args['footer_text'] ?? "Sent daily by the MMI Suite on {$site_name}." ),
			$site_name,
			'digest_footer_text'
		);

		$border = self::token( 'border' );

		$inner = '<h1 style="margin:0 0 2px;font-size:19px;color:' . esc_attr( self::token( 'text_dark' ) ) . ';">Daily Digest</h1>'
			. '<p style="margin:0 0 16px;font-size:12px;color:#767676;">' . esc_html( $today ) . '</p>'
			. ( $hero_cells !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px;border:1px solid ' . esc_attr( $border ) . ';border-radius:6px;"><tr>' . $hero_cells . '</tr></table>' : '' )
			. $process_cards
			. self::cta_button_html( $dashboard_url, $cta_label, self::token( 'primary' ) );

		return self::frame_html( $inner, self::token( 'primary' ), $footer_text );
	}

	/* ── render_customer_notice() — customer-facing, WooCommerce-branded ──── */

	/**
	 * Envelope for a real customer's inbox (license trial/renewal mail,
	 * XChange fulfillment). Deliberately reads WooCommerce's own
	 * `woocommerce_email_*` options for its chrome instead of this class's
	 * own site/published token layers — see this file's top docblock for why.
	 * Falls back to WooCommerce's own stock defaults when WooCommerce itself
	 * isn't active (a plugin like mmi-admin's licensing can send this without
	 * WooCommerce present).
	 *
	 * @param array $args { box_heading, box_subtext?, body_html, cta_url?, cta_label?, footer_text? }
	 */
	public static function render_customer_notice( array $args ): string {
		$box_heading = (string) ( $args['box_heading'] ?? '' );
		$box_subtext = (string) ( $args['box_subtext'] ?? '' );
		$body_html   = (string) ( $args['body_html'] ?? '' );
		$cta_url     = (string) ( $args['cta_url'] ?? '' );
		$cta_label   = (string) ( $args['cta_label'] ?? '' );
		$site_name   = get_bloginfo( 'name' );

		$accent = get_option( 'woocommerce_email_base_color', '#720eec' );
		$dark   = get_option( 'woocommerce_email_text_color', '#3c3c3c' );
		$muted  = get_option( 'woocommerce_email_footer_text_color', '#767676' );
		$bg     = get_option( 'woocommerce_email_background_color', '#f7f7f7' );
		$body_bg = get_option( 'woocommerce_email_body_background_color', '#ffffff' );
		$logo   = get_option( 'woocommerce_email_header_image', '' );

		$footer_text = (string) ( $args['footer_text'] ?? get_option( 'woocommerce_email_footer_text', "&copy; " . gmdate( 'Y' ) . ' ' . $site_name ) );
		$footer_text = str_replace( '{site_name}', $site_name, $footer_text );
		// WooCommerce's own footer placeholders ({site_title}, {store_address},
		// {site_url}, {store_email}) — WC_Emails replaces them via this filter,
		// registered once its mailer is loaded. Without this, every customer
		// notice showed the raw placeholders in its footer.
		if ( function_exists( 'WC' ) ) {
			WC()->mailer();
			$footer_text = (string) apply_filters( 'woocommerce_email_footer_text', $footer_text );
		}

		$logo_html = $logo !== ''
			? '<tr><td style="padding:24px 32px 0;text-align:center;"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $site_name ) . '" style="max-height:60px;max-width:220px;"></td></tr>'
			: '';

		$box_html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;background:' . esc_attr( $bg ) . ';border-radius:6px;">'
			. '<tr><td style="padding:18px 20px;">'
			. '<p style="margin:0 0 4px;font-size:16px;font-weight:700;color:' . esc_attr( $dark ) . ';">' . esc_html( $box_heading ) . '</p>'
			. ( $box_subtext !== '' ? '<p style="margin:0;font-size:13px;color:' . esc_attr( $muted ) . ';">' . wp_kses_post( $box_subtext ) . '</p>' : '' )
			. '</td></tr></table>';

		$cta_html = ( $cta_url !== '' && $cta_label !== '' )
			? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0 4px;"><tr><td style="border-radius:6px;background:' . esc_attr( $accent ) . ';">'
				. '<a href="' . esc_url( $cta_url ) . '" style="display:inline-block;padding:11px 22px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:6px;">' . wp_kses_post( $cta_label ) . '</a>'
				. '</td></tr></table>'
			: '';

		return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>'
			. '<body style="margin:0;padding:0;background:' . esc_attr( $bg ) . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . esc_attr( $bg ) . ';padding:24px 12px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:' . esc_attr( $body_bg ) . ';border-radius:8px;overflow:hidden;">'
			. $logo_html
			. '<tr><td style="padding:28px 32px;">'
			. $box_html
			. '<div style="font-size:14px;line-height:1.6;color:' . esc_attr( $dark ) . ';">' . wp_kses_post( $body_html ) . '</div>'
			. $cta_html
			. '</td></tr>'
			. '<tr><td style="padding:16px 32px 24px;">'
			. '<p style="margin:0;font-size:12px;line-height:1.5;color:' . esc_attr( $muted ) . ';">' . wp_kses_post( $footer_text ) . '</p>'
			. '</td></tr>'
			. '</table>'
			. '</td></tr>'
			. '</table>'
			. '</body></html>';
	}
}
