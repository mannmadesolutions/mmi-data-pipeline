<?php
/**
 * MMI_Feature_Gate — cross-plugin feature disclosure (bundled/vendored copy).
 *
 * Do not hand-edit this file in a single plugin; edit the canonical source
 * (`mmi-admin/lib/mmi-shared/class-feature-gate.php`) and re-sync via
 * `bash mmi-admin/lib/sync-to-plugins.sh` to every plugin that bundles it.
 *
 * A feature one MMI plugin adds to another stays on screen when the plugin
 * that powers it isn't active: greyed, with a lock, and a hover tooltip
 * saying what it does and linking to the product that unlocks it. Never
 * hidden, never fake-empty — see AGENTS.md › Standalone Plugin Independence ›
 * Gated Feature Disclosure.
 *
 * Three pieces:
 *   - Presence: is_available() answers from the shared-library bootstrap's
 *     list of loaded plugins (mmi_shared_lib_loaded_plugins()); the
 *     `mmi_feature_gate_is_available` filter can still override. Presence
 *     only, never licensing — compose with mmi_is_licensed() if both matter.
 *   - Catalogs: plugins() (label + product URL per sold plugin) and
 *     features() (cross-plugin-features.php, the hover text).
 *   - Rendering: render_overlay() greys an element in place; render_locked()
 *     is the padlock badge for a whole panel; render_works_with() is the
 *     header strip. mmi-gate.js builds the tooltip from mmiGateData, so
 *     JS-rendered rows use MMIGate.overlayHtml() for the same markup.
 *
 * Display vs. logic: is_shown_available() also honours the staff preview
 * (?mmi_gate_preview=<slug|all>). Rendering uses it; anything that decides
 * behaviour (whether to call a sibling class, whether to run a job) uses
 * is_available(), which the preview never touches.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

final class MMI_Feature_Gate {

	const PREVIEW_PARAM  = 'mmi_gate_preview';
	const PREVIEW_COOKIE = 'mmi_gate_preview';

	/**
	 * Plugins merged into another: old slug => new slug. A site still running
	 * an old plugin counts as having the new one, so a rollback to the old
	 * plugins keeps their features unlocked. Remove an entry once no site
	 * runs the old plugin.
	 */
	const MERGED_INTO = array(
		'mmi-xchange-integration'   => 'mmi-distributors',
		'mmi-skuport-integration'   => 'mmi-distributors',
		'mmi-plugivery-integration' => 'mmi-distributors',
	);

	/** @var array<string,array{label?:string,product_url?:string}> Overrides from register(). */
	private static $registry = array();

	/** @var string[]|null */
	private static $preview = null;

	/**
	 * Override a plugin's label or product URL. Optional: every sold MMI
	 * plugin already has an entry in plugins().
	 *
	 * @param string $slug Plugin slug, e.g. 'mmi-data-health'.
	 * @param array{label?:string,product_url?:string} $meta
	 */
	public static function register( string $slug, array $meta ): void {
		$meta = array_filter(
			array(
				'label'       => isset( $meta['label'] ) ? (string) $meta['label'] : '',
				'product_url' => isset( $meta['product_url'] ) ? (string) $meta['product_url'] : '',
			)
		);
		self::$registry[ $slug ] = $meta + ( self::$registry[ $slug ] ?? array() );
	}

	/* ── Presence ────── */

	/**
	 * True if the plugin $slug is active on this site. Behaviour decisions
	 * use this; it ignores the staff preview.
	 */
	public static function is_available( string $slug ): bool {
		$plugins = function_exists( 'mmi_shared_lib_loaded_plugins' ) ? mmi_shared_lib_loaded_plugins() : array();
		$loaded  = isset( $plugins[ $slug ] );
		if ( ! $loaded ) {
			foreach ( self::MERGED_INTO as $old => $new ) {
				if ( $new === $slug && isset( $plugins[ $old ] ) ) {
					$loaded = true;
					break;
				}
			}
		}
		/**
		 * @param bool   $available Whether the plugin's bundled shared library loaded this request.
		 * @param string $slug
		 */
		return (bool) apply_filters( 'mmi_feature_gate_is_available', $loaded, $slug );
	}

	/**
	 * What the screen should show: is_available(), unless a manage_options
	 * user is previewing $slug as locked.
	 */
	public static function is_shown_available( string $slug ): bool {
		if ( ! self::is_available( $slug ) ) {
			return false;
		}
		$preview = self::preview_slugs();
		return ! ( in_array( 'all', $preview, true ) || in_array( $slug, $preview, true ) );
	}

	/* ── Catalogs ────── */

	/**
	 * Every sold MMI plugin: display label and the page that sells it.
	 * mmi-admin is site-only and never listed.
	 *
	 * @return array<string,array{label:string,short:string,product_url:string}>
	 */
	public static function plugins(): array {
		static $base = null;
		if ( $base === null ) {
			$base = array(
				'mmi-accounting'            => 'Accounting',
				'mmi-data-health'           => 'Data Health',
				'mmi-data-pipeline'         => 'Data Pipeline',
				'mmi-distributors'          => 'Distributors',
				'mmi-email-customizer'      => 'Email Customizer',
				'mmi-google-services'       => 'Google Services',
				'mmi-po'                    => 'Purchase Orders',
				'mmi-reverb-integration'    => 'Reverb Integration',
				'mmi-rtsm'                  => 'Real-Time Server Monitor',
				'mmi-sales-tax'             => 'Sales Tax',
				'mmi-seo'                   => 'SEO',
				'mmi-subscriptions'         => 'Subscriptions',
			);
		}
		$plugins = array();
		foreach ( $base as $slug => $short ) {
			$override         = self::$registry[ $slug ] ?? array();
			$plugins[ $slug ] = array(
				'label'       => $override['label'] ?? 'MMI ' . $short,
				'short'       => $short,
				// Same URL the license panel's Buy button uses. The license
				// server redirects /plugins/<slug>/ to the published product,
				// or to the store's plugin list while none is published.
				'product_url' => $override['product_url']
					?? (string) apply_filters( 'mmi_license_purchase_url', 'https://mannmade.us/plugins/' . $slug . '/', $slug ),
			);
		}
		return $plugins;
	}

	/**
	 * One plugin's catalog entry, with a readable fallback for an unknown slug.
	 *
	 * @return array{label:string,short:string,product_url:string}
	 */
	public static function plugin( string $slug ): array {
		return self::plugins()[ $slug ] ?? array(
			'label'       => $slug,
			'short'       => $slug,
			'product_url' => '',
		);
	}

	/**
	 * Cross-plugin features (cross-plugin-features.php). Live entries only
	 * unless $include_planned.
	 *
	 * @return array<string,array{provider:string,consumers:string[],title:string,pitch:string,kind:string,status:string}>
	 */
	public static function features( bool $include_planned = false ): array {
		static $all = null;
		if ( $all === null ) {
			$file = __DIR__ . '/cross-plugin-features.php';
			$all  = is_file( $file ) ? (array) require $file : array();
		}
		if ( $include_planned ) {
			return $all;
		}
		return array_filter(
			$all,
			static function ( $f ) {
				return ( $f['status'] ?? '' ) === 'live';
			}
		);
	}

	/**
	 * @return array{provider:string,consumers:string[],title:string,pitch:string,kind:string,status:string}|null
	 */
	public static function feature( string $id ): ?array {
		return self::features()[ $id ] ?? null;
	}

	/* ── Rendering ────── */

	/**
	 * Wrap $inner_html (already escaped) so it shows greyed in place with a
	 * lock, when the feature's provider isn't shown as available. Returns
	 * $inner_html unchanged when it is.
	 *
	 * @param string $feature_id Key in cross-plugin-features.php.
	 * @param string $inner_html The element exactly as it renders when unlocked.
	 * @param bool   $block      True for a block element (a card, a form row).
	 */
	public static function overlay( string $feature_id, string $inner_html, bool $block = false ): string {
		$feature = self::feature( $feature_id );
		if ( $feature === null || self::is_shown_available( $feature['provider'] ) ) {
			return $inner_html;
		}
		$plugin = self::plugin( $feature['provider'] );
		$tag    = $block ? 'div' : 'span';
		return sprintf(
			'<%1$s class="mmi-gated-feature mmi-gated-feature--overlay%2$s mmi-is-locked" data-gate-slug="%3$s" data-gate-feature="%4$s" tabindex="0" role="button" aria-label="%5$s">'
				. '<%1$s class="mmi-gated-feature__content" inert>%6$s</%1$s>'
				. '<span class="mmi-gate-lock dashicons dashicons-lock" aria-hidden="true"></span>'
				. '</%1$s>',
			$tag,
			$block ? ' mmi-gated-feature--block' : '',
			esc_attr( $feature['provider'] ),
			esc_attr( $feature_id ),
			/* translators: 1: feature title, 2: plugin name */
			esc_attr( sprintf( __( '%1$s — requires %2$s', 'mmi-shared' ), $feature['title'], $plugin['label'] ) ),
			$inner_html
		);
	}

	/** Echoing form of overlay(). */
	public static function render_overlay( string $feature_id, string $inner_html, bool $block = false ): void {
		echo self::overlay( $feature_id, $inner_html, $block ); // phpcs:ignore WordPress.Security.EscapeOutput -- caller-escaped HTML, attributes escaped above.
	}

	/**
	 * Padlock badge for a panel that is locked as a whole. The caller wraps
	 * its real (disabled) controls in `.mmi-gated-feature.mmi-is-locked`.
	 *
	 * @param string $slug          The plugin the feature needs.
	 * @param string $feature_label What is locked, e.g. "Data Health Score".
	 * @param string $feature_id    Optional catalog key, for the catalog's pitch.
	 */
	public static function render_locked( string $slug, string $feature_label, string $feature_id = '' ): void {
		$plugin = self::plugin( $slug );
		printf(
			'<span class="mmi-gate-badge" tabindex="0" role="button" data-gate-slug="%1$s" data-gate-label="%2$s"%3$s aria-label="%4$s"><span class="dashicons dashicons-lock" aria-hidden="true"></span></span>',
			esc_attr( $slug ),
			esc_attr( $feature_label ),
			$feature_id !== '' ? ' data-gate-feature="' . esc_attr( $feature_id ) . '"' : '',
			/* translators: 1: feature label, 2: plugin name */
			esc_attr( sprintf( __( '%1$s — requires %2$s', 'mmi-shared' ), $feature_label, $plugin['label'] ) )
		);
	}

	/**
	 * "Works with" chips for a plugin's page header: one per plugin that adds
	 * live features to $consumer_slug, ✓ when active, locked when not. The
	 * tooltip lists what each one adds. Prints nothing if none apply.
	 * A bare plugin slug also covers its modules' consumers ('slug:module'),
	 * for a page that shows every module at once.
	 */
	public static function render_works_with( string $consumer_slug ): void {
		$providers = array();
		$plugin    = strtok( $consumer_slug, ':' );
		foreach ( self::features() as $feature ) {
			if ( self::consumes( $consumer_slug, $feature['consumers'] ) && $feature['provider'] !== $plugin ) {
				$providers[ $feature['provider'] ] = true;
			}
		}
		if ( ! $providers ) {
			return;
		}
		// Locked first: they are the point of the strip.
		uksort(
			$providers,
			static function ( $a, $b ) {
				return (int) self::is_shown_available( $a ) <=> (int) self::is_shown_available( $b ) ?: strcmp( $a, $b );
			}
		);

		echo '<div class="mmi-works-with"><span class="mmi-works-with__label">' . esc_html__( 'Works with', 'mmi-shared' ) . '</span>';
		foreach ( array_keys( $providers ) as $slug ) {
			$plugin = self::plugin( $slug );
			$active = self::is_shown_available( $slug );
			printf(
				'<span class="mmi-works-with__chip%1$s" tabindex="0" role="button" data-gate-slug="%2$s" data-gate-consumer="%3$s" aria-label="%4$s"><span class="dashicons %5$s" aria-hidden="true"></span>%6$s</span>',
				$active ? ' is-active' : ' mmi-is-locked',
				esc_attr( $slug ),
				esc_attr( $consumer_slug ),
				esc_attr(
					$active
						/* translators: %s: plugin name */
						? sprintf( __( '%s is active', 'mmi-shared' ), $plugin['label'] )
						/* translators: %s: plugin name */
						: sprintf( __( '%s is not installed — see what it adds', 'mmi-shared' ), $plugin['label'] )
				),
				$active ? 'dashicons-yes' : 'dashicons-lock',
				esc_html( $plugin['short'] )
			);
		}
		echo '</div>';
	}

	/** $consumer_slug is in $consumers, or is the bare plugin slug of a 'slug:module' there. */
	private static function consumes( string $consumer_slug, array $consumers ): bool {
		if ( in_array( $consumer_slug, $consumers, true ) ) {
			return true;
		}
		if ( strpos( $consumer_slug, ':' ) !== false ) {
			return false;
		}
		foreach ( $consumers as $consumer ) {
			if ( strpos( $consumer, $consumer_slug . ':' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Data for mmi-gate.js (localized as `mmiGateData`).
	 *
	 * @return array<string,mixed>
	 */
	public static function js_data(): array {
		$plugins = array();
		foreach ( self::plugins() as $slug => $plugin ) {
			$plugins[ $slug ] = $plugin + array( 'available' => self::is_shown_available( $slug ) );
		}
		$features = array();
		foreach ( self::features() as $id => $feature ) {
			$features[ $id ] = array(
				'provider'  => $feature['provider'],
				'consumers' => $feature['consumers'],
				'title'     => $feature['title'],
				'pitch'     => $feature['pitch'],
			);
		}
		return array(
			'plugins'  => $plugins,
			'features' => $features,
			'i18n'     => array(
				/* translators: %s: plugin name */
				'requires'  => __( 'Requires %s.', 'mmi-shared' ),
				/* translators: %s: plugin name */
				'get'       => __( 'Get %s', 'mmi-shared' ),
				/* translators: %s: plugin name */
				'adds'      => __( '%s adds:', 'mmi-shared' ),
				/* translators: %s: plugin name */
				'activeAdds' => __( '%s is active and adds:', 'mmi-shared' ),
				/* translators: %s: plugin name */
				'requiresAria' => __( 'requires %s', 'mmi-shared' ),
			),
		);
	}

	/* ── Staff preview ────── */

	/**
	 * Slugs a manage_options user is previewing as locked ('all' for every
	 * plugin). From ?mmi_gate_preview= on this request, else the session
	 * cookie set by the last such request (so AJAX-rendered rows match).
	 *
	 * @return string[]
	 */
	public static function preview_slugs(): array {
		if ( self::$preview !== null ) {
			return self::$preview;
		}
		self::$preview = array();
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return self::$preview;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- display-only toggle, no state change beyond the viewer's own cookie.
		$raw = isset( $_GET[ self::PREVIEW_PARAM ] ) ? wp_unslash( $_GET[ self::PREVIEW_PARAM ] ) : ( $_COOKIE[ self::PREVIEW_COOKIE ] ?? '' );
		$raw = is_string( $raw ) ? $raw : '';
		if ( $raw === '' || $raw === 'off' ) {
			return self::$preview;
		}
		self::$preview = array_values( array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) ) );
		return self::$preview;
	}

	/**
	 * admin_init: remember ?mmi_gate_preview= for this browser session, or
	 * forget it on ?mmi_gate_preview=off.
	 */
	public static function remember_preview(): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- display-only toggle.
		if ( ! isset( $_GET[ self::PREVIEW_PARAM ] ) || ! current_user_can( 'manage_options' ) || headers_sent() ) {
			return;
		}
		$slugs  = self::preview_slugs();
		$path   = defined( 'ADMIN_COOKIE_PATH' ) ? ADMIN_COOKIE_PATH : '/wp-admin';
		$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
		if ( $slugs ) {
			setcookie( self::PREVIEW_COOKIE, implode( ',', $slugs ), 0, $path, $domain, is_ssl(), true );
		} else {
			setcookie( self::PREVIEW_COOKIE, '', time() - HOUR_IN_SECONDS, $path, $domain, is_ssl(), true );
		}
	}

	/** admin_notices: say a preview is on, and how to end it. */
	public static function render_preview_notice(): void {
		$slugs = self::preview_slugs();
		if ( ! $slugs ) {
			return;
		}
		$names = in_array( 'all', $slugs, true )
			? __( 'every MMI plugin', 'mmi-shared' )
			: implode( ', ', array_map( static function ( $s ) { return self::plugin( $s )['label']; }, $slugs ) );
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			/* translators: %s: plugin names */
			esc_html( sprintf( __( 'Locked-feature preview: screens show %s as not installed. Nothing else changes.', 'mmi-shared' ), $names ) ),
			esc_url( add_query_arg( self::PREVIEW_PARAM, 'off' ) ),
			esc_html__( 'End preview', 'mmi-shared' )
		);
	}
}
