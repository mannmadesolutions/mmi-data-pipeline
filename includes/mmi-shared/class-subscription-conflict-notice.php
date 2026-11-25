<?php
/**
 * MMI Subscription Conflict Notice (bundled/vendored copy) — ADR-0006,
 * mmi-admin/docs/decisions/.
 *
 * mmi-admin's MMI_Subscription_License_Adapter::__construct() uses this class
 * unconditionally, so it must always be available. Only ever loaded once per
 * request, by whichever plugin's bundled copy wins version
 * negotiation in bootstrap.php — see ADR-0006. Do not hand-edit this file in
 * a single plugin; edit the canonical source (mmi-admin/lib/mmi-shared/) and
 * re-sync (sync-to-plugins.sh) to every plugin that bundles it.
 *
 * Detects when WooCommerce Subscriptions and MMI Subscriptions are both active
 * simultaneously and surfaces a persistent admin error with one-click deactivation
 * links.  While the conflict exists all subscription license bridges are suspended.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_Subscription_Conflict_Notice {

	/** @var bool True when both subscription plugins are active at the same time. */
	private static bool $conflict = false;

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	/**
	 * Called early on plugins_loaded (priority 5) so the conflict flag is set
	 * before the adapter attempts to wire any hooks at priority 20.
	 */
	public static function init(): void {
		// WC_Subscriptions is the main class registered by woocommerce-subscriptions.
		// MMI_Subscription is the order class registered by mmi-subscriptions.
		$wcs_active = class_exists( 'WC_Subscriptions' );
		$mmi_active = class_exists( 'MMI_Subscription' );

		if ( ! $wcs_active || ! $mmi_active ) {
			return;
		}

		self::$conflict = true;

		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	/**
	 * Returns true while the conflict is unresolved (both plugins active).
	 */
	public static function has_conflict(): bool {
		return self::$conflict;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render the error notice with one-click deactivation buttons.
	 * Only visible to administrators who can manage plugins.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$wcs_slug = 'woocommerce-subscriptions/woocommerce-subscriptions.php';
		$mmi_slug = 'mmi-subscriptions/mmi-subscriptions.php';

		$wcs_url = wp_nonce_url(
			admin_url( 'plugins.php?action=deactivate&plugin=' . urlencode( $wcs_slug ) ),
			'deactivate-plugin_' . $wcs_slug
		);
		$mmi_url = wp_nonce_url(
			admin_url( 'plugins.php?action=deactivate&plugin=' . urlencode( $mmi_slug ) ),
			'deactivate-plugin_' . $mmi_slug
		);
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'MannMade — Subscription plugin conflict', 'mmi-shared' ); ?>:</strong>
				<?php esc_html_e( 'WooCommerce Subscriptions and MMI Subscriptions are both active. Only one subscription plugin may run at a time. License renewals and subscription switching are suspended until you deactivate one.', 'mmi-shared' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $wcs_url ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Deactivate WooCommerce Subscriptions', 'mmi-shared' ); ?>
				</a>
				&nbsp;
				<a href="<?php echo esc_url( $mmi_url ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Deactivate MMI Subscriptions', 'mmi-shared' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
