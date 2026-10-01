<?php
/**
 * MMI_Marketplace_Order_Sources
 *
 * Suite-wide registry of "which marketplace, if any, did this order come
 * from" — a marketplace-import plugin (e.g. mmi-reverb-integration)
 * registers itself once on `init`; any other plugin that needs to know
 * whether an order was already sold/fulfilled elsewhere (and must therefore
 * never trigger its own automatic fulfillment for it — see
 * mmi-xchange-integration's MMI_Xchange_Checkout::is_externally_sourced())
 * looks it up here instead of re-deriving marketplace-specific detection
 * logic itself.
 *
 * Originally lived in mmi-hub; deleted with it 2026-09-17
 * (mmi-hub-elimination migration) without being migrated, which silently
 * disabled every consumer's duplicate-order protection until rebuilt here
 * as the 14th class in the shared library (see bootstrap.php). Behavior is
 * unchanged from the original: register(key, config) + get_source_for_order()
 * against a WC_Order — reconstructed from the calling convention already
 * used consistently by every registration/consumption site in the suite
 * (mmi-reverb-integration.php's register() call; MMI_Xchange_Checkout and
 * MMI_Xchange_Fulfillment_Queue's get_source_for_order() calls).
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Marketplace_Order_Sources', false ) ) {

	class MMI_Marketplace_Order_Sources {

		/**
		 * @var array<string, array{label:string, logo_url:string, detect:callable, url:callable}>
		 */
		private static array $sources = array();

		/**
		 * Registers a marketplace source. Safe to call more than once for the
		 * same $key (last registration wins) — mirrors the shared library's
		 * own tolerance for a plugin re-running its own init.
		 *
		 * @param string $key    Stable identifier, e.g. 'reverb'.
		 * @param array  $config {
		 *     @type string   $label    Human-readable marketplace name, e.g. 'Reverb'.
		 *     @type string   $logo_url Absolute URL to a small logo/icon.
		 *     @type callable $detect   function( WC_Order $order ): bool — true if this
		 *                              order originated from this marketplace.
		 *     @type callable $url      function( WC_Order $order ): string — deep link to
		 *                              the order on the marketplace's own site, or '' if
		 *                              none can be resolved.
		 * }
		 */
		public static function register( string $key, array $config ): void {
			self::$sources[ $key ] = array(
				'label'    => (string) ( $config['label'] ?? $key ),
				'logo_url' => (string) ( $config['logo_url'] ?? '' ),
				'detect'   => $config['detect'] ?? null,
				'url'      => $config['url'] ?? null,
			);
		}

		/**
		 * Returns the first registered source whose detect() callback matches
		 * this order, or null if none do (i.e. the order originated from this
		 * site's own checkout). Registration order determines priority when
		 * more than one source could theoretically match — not expected in
		 * practice, since each marketplace's detect() keys off its own
		 * marketplace-specific meta/payment-method.
		 *
		 * @return array{key:string, label:string, logo_url:string, order_url:string}|null
		 */
		public static function get_source_for_order( \WC_Order $order ): ?array {
			foreach ( self::$sources as $key => $source ) {
				if ( ! is_callable( $source['detect'] ) ) {
					continue;
				}
				if ( ! $source['detect']( $order ) ) {
					continue;
				}

				$order_url = '';
				if ( is_callable( $source['url'] ) ) {
					$order_url = (string) $source['url']( $order );
				}

				return array(
					'key'       => $key,
					'label'     => $source['label'],
					'logo_url'  => $source['logo_url'],
					'order_url' => $order_url,
				);
			}

			return null;
		}

		/**
		 * True if any source is registered for $key. Not currently consumed
		 * anywhere, provided for parity with the register/lookup pair should
		 * a caller need to distinguish "no marketplace matched" from
		 * "this marketplace was never registered at all."
		 */
		public static function has_source( string $key ): bool {
			return isset( self::$sources[ $key ] );
		}
	}
}
