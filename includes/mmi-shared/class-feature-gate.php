<?php
/**
 * MMI_Feature_Gate — cross-plugin feature disclosure registry (bundled/vendored copy).
 *
 * Introduced alongside `mmi-data-health` (the first real consumer) per AGENTS.md's
 * "Gated Feature Disclosure" section, which documented this class as needed but
 * "does not exist yet" and explicitly forbade hand-rolling a one-off gate ahead of
 * it. Do not hand-edit this file in a single plugin; edit the canonical source
 * (`mmi-admin/lib/mmi-shared/class-feature-gate.php`) and re-sync via
 * `bash mmi-admin/lib/sync-to-plugins.sh` to every plugin that bundles it.
 *
 * A plugin whose own feature only fully works with a sibling MMI plugin present
 * uses this to disclose that visibly (padlock badge + dismissible popover + a
 * real link) instead of silently hiding the feature or rendering empty/zero data
 * — see AGENTS.md §"Standalone Plugin Independence" › "Gated Feature Disclosure."
 *
 * Deliberately minimal: presence-only (`is_available()`), never licensing — a
 * caller wanting "available AND licensed" composes this with `mmi_is_licensed()`
 * itself. No plugin gets a hardcoded slot here; each gated plugin answers its own
 * `is_available()` question via the `mmi_feature_gate_is_available` filter, and
 * registers its own disclosure copy via `register()` — this class owns only the
 * registry and the render, never a specific plugin's identity.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

final class MMI_Feature_Gate {

	/** @var array<string,array{label:string,product_url:string}> */
	private static $registry = array();

	/**
	 * Declare a gated feature's disclosure copy. Call from the GATED plugin's
	 * own `plugins_loaded` (or later) — never at file/top-level scope, since
	 * this class itself is lazily autoloaded by the shared-library bootstrap.
	 *
	 * @param string               $slug Stable id for the plugin being gated on, e.g. 'mmi-data-health'.
	 * @param array{label:string,product_url:string} $meta
	 */
	public static function register( string $slug, array $meta ): void {
		self::$registry[ $slug ] = array(
			'label'       => (string) ( $meta['label'] ?? $slug ),
			'product_url' => (string) ( $meta['product_url'] ?? '' ),
		);
	}

	/**
	 * True if the plugin identified by $slug is active on this site. Answered
	 * by that plugin itself via the filter below — this class has no built-in
	 * knowledge of any specific plugin's own activation signal, and never
	 * checks licensing (see class doc comment).
	 */
	public static function is_available( string $slug ): bool {
		/**
		 * @param bool   $available Default false — absent unless a responder opts in.
		 * @param string $slug
		 */
		return (bool) apply_filters( 'mmi_feature_gate_is_available', false, $slug );
	}

	/**
	 * Render the locked state: a padlock badge that opens a dismissible
	 * popover naming what's gated and linking to the gated plugin's product
	 * page. Caller wraps its own real controls in the SAME `.mmi-gated-feature`
	 * element for a live feature, toggling only the `.mmi-is-locked` class —
	 * never `display:none`/omission, per AGENTS.md's disclosure requirement.
	 *
	 * @param string $slug          The gated plugin's slug, as passed to register().
	 * @param string $feature_label Human label for the feature that's locked, e.g. "Data Health Score".
	 */
	public static function render_locked( string $slug, string $feature_label ): void {
		$meta = self::$registry[ $slug ] ?? array(
			'label'       => $slug,
			'product_url' => '',
		);
		?>
		<span class="mmi-gate-badge" tabindex="0" role="button"
			data-gate-slug="<?php echo esc_attr( $slug ); ?>"
			aria-label="<?php esc_attr_e( 'Locked feature — activate for more info', 'mmi-hub' ); ?>">
			<span class="dashicons dashicons-lock"></span>
		</span>
		<div class="mmi-gate-popover" hidden>
			<p>
				<?php
				printf(
					/* translators: 1: feature label, 2: gated plugin's display label */
					esc_html__( '%1$s requires %2$s.', 'mmi-hub' ),
					esc_html( $feature_label ),
					esc_html( $meta['label'] )
				);
				?>
			</p>
			<?php if ( ! empty( $meta['product_url'] ) ) : ?>
				<a class="mmi-gate-cta" href="<?php echo esc_url( $meta['product_url'] ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Learn more', 'mmi-hub' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}
}
