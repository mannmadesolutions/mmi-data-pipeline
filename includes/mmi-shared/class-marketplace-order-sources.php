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
		 * Sources registered at runtime by their own plugin.
		 *
		 * @var array<string, array{label:string, logo_url:string, detect:callable|null, url:callable|null, provider:string}>
		 */
		private static array $sources = array();

		/**
		 * Built-in descriptors, keyed like $sources. Detection reads only
		 * plain order data (meta, payment method) that stays on the order
		 * after the marketplace plugin is deactivated, so a marketplace order
		 * is still recognised when its plugin is off. Without this, every
		 * consumer's duplicate-order protection (XChange auto-fulfillment,
		 * SkuPort's buy block, sales tax's marketplace-facilitator status)
		 * silently switched off whenever the marketplace plugin was inactive.
		 * The owning plugin's register() still overrides label, logo and URL.
		 *
		 * @return array<string, array{label:string, logo_url:string, detect:callable, url:callable, provider:string}>
		 */
		private static function builtins(): array {
			static $builtins = null;
			if ( $builtins !== null ) {
				return $builtins;
			}

			$logo_base = function_exists( 'plugin_dir_url' ) ? plugin_dir_url( __FILE__ ) . 'assets/images/marketplaces/' : '';

			$builtins = array(
				'reverb' => array(
					'label'    => 'Reverb',
					'logo_url' => $logo_base !== '' ? $logo_base . 'reverb.png' : '',
					'provider' => 'mmi-reverb-integration',
					'detect'   => static function ( \WC_Order $order ): bool {
						return (string) $order->get_meta( '_mmi_reverb_order_number', true ) !== ''
							|| (string) $order->get_meta( '_mmi_reverb_uuid', true ) !== ''
							|| $order->get_payment_method() === 'reverb';
					},
					'url'      => static function ( \WC_Order $order ): string {
						$order_number = (string) $order->get_meta( '_mmi_reverb_order_number', true );
						return $order_number === '' ? '' : 'https://reverb.com/my/selling/orders/' . rawurlencode( $order_number );
					},
				),
			);
			return $builtins;
		}

		/**
		 * Registers a marketplace source. Safe to call more than once for the
		 * same $key (last registration wins). A key that matches a built-in
		 * keeps the built-in's detection and provider unless the config
		 * supplies its own.
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
		 *     @type string   $provider Slug of the plugin that owns this source.
		 * }
		 */
		public static function register( string $key, array $config ): void {
			$builtin = self::builtins()[ $key ] ?? array();
			self::$sources[ $key ] = array(
				'label'    => (string) ( $config['label'] ?? $builtin['label'] ?? $key ),
				'logo_url' => (string) ( $config['logo_url'] ?? $builtin['logo_url'] ?? '' ),
				'detect'   => $config['detect'] ?? $builtin['detect'] ?? null,
				'url'      => $config['url'] ?? $builtin['url'] ?? null,
				'provider' => (string) ( $config['provider'] ?? $builtin['provider'] ?? '' ),
			);
		}

		/**
		 * Returns the first source whose detect() callback matches this order,
		 * or null if none do (the order came from this site's own checkout).
		 * Registered sources are checked before built-ins.
		 *
		 * `provider_active` is false when the order matched only a built-in,
		 * i.e. the plugin that owns this marketplace isn't running. Callers
		 * that only need "is this a marketplace order?" (duplicate-order
		 * protection) ignore it; display code uses it to show the source as a
		 * locked feature (MMI_Feature_Gate).
		 *
		 * @return array{key:string, label:string, logo_url:string, order_url:string, provider:string, provider_active:bool}|null
		 */
		public static function get_source_for_order( \WC_Order $order ): ?array {
			$all = self::$sources + self::builtins();
			foreach ( $all as $key => $source ) {
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
					'key'             => $key,
					'label'           => $source['label'],
					'logo_url'        => $source['logo_url'],
					'order_url'       => $order_url,
					'provider'        => $source['provider'],
					'provider_active' => isset( self::$sources[ $key ] ),
				);
			}

			return null;
		}

		/**
		 * True if a source is registered at runtime for $key, or built in.
		 */
		public static function has_source( string $key ): bool {
			return isset( self::$sources[ $key ] ) || isset( self::builtins()[ $key ] );
		}
	}
}
