<?php
/**
 * MMI_Environment — thin wrapper around WordPress core's own environment
 * detection (wp_get_environment_type(), WP_ENVIRONMENT_TYPE), used to guard
 * real-money XChange API calls (reserve/finalize/void/place_order) against
 * accidentally running on a staging/dev clone, and to warn when Reverb's own
 * sandbox/production API mode setting doesn't match the site's real
 * environment.
 *
 * This class originally lived in mmi-hub ("helps prevent accidental
 * production API calls on staging/dev sites" — see incident-history.md,
 * "XChange Cross-Channel Duplicate-Order Risk", 2026-08-24). It was already
 * correctly class_exists()-guarded at every call site during the
 * mmi-hub-elimination migration — so nothing fataled — but, unlike
 * MMI_Settings/MMI_Media_Helper/MMI_WC_Product_Filter_Handler/etc., it was
 * never actually rebuilt anywhere. Because its one real call site
 * (MMI_Xchange_API_Client::guard_production_only()) fails CLOSED when the
 * class can't be found — refusing the call rather than assuming it's safe —
 * this silently blocked every real XChange order placement from the moment
 * mmi-hub was deleted (1.173.0, 2026-09-17) onward, misreported as an
 * XChange account restriction because the resulting local guard message
 * happens to contain the word "disabled" (see MMI_Xchange_API::place_order()'s
 * message-mapping). Confirmed via the order record itself: the last
 * auto-generated PO number (place_order()'s 'MMI-' . random-string format)
 * belongs to an order placed 2026-09-16 23:35 — the evening before the
 * migration, nothing since.
 *
 * Rebuilt here on WordPress core's own wp_get_environment_type() (5.5+,
 * reads the WP_ENVIRONMENT_TYPE constant / 'production' default) instead of
 * anything plugin-specific — no dependency to vendor, no version
 * negotiation needed for the underlying signal itself.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Environment' ) ) {
	class MMI_Environment {

		/**
		 * @return string One of 'local', 'development', 'staging', 'production'
		 *                 — WordPress core's own vocabulary, unchanged.
		 */
		public static function get_environment(): string {
			return wp_get_environment_type();
		}

		public static function is_production(): bool {
			return self::get_environment() === 'production';
		}

		/**
		 * @return string Human-readable, e.g. for an admin-facing warning notice.
		 */
		public static function get_label(): string {
			$labels = [
				'local'       => 'Local',
				'development' => 'Development',
				'staging'     => 'Staging',
				'production'  => 'Production',
			];
			return $labels[ self::get_environment() ] ?? ucfirst( self::get_environment() );
		}

		/**
		 * The API mode ('production'/'sandbox' — Reverb's own vocabulary;
		 * XChange's 'live'/'test' engine mode is a separate, unrelated
		 * concept and isn't gated through this) a site in this environment
		 * should be using.
		 */
		public static function get_recommended_api_mode(): string {
			return self::is_production() ? 'production' : 'sandbox';
		}

		/**
		 * True when the given API mode doesn't match what this environment
		 * should be using — a non-production site (staging/dev/local) using
		 * the real 'production' API is the dangerous direction (real data,
		 * real side effects, from a throwaway clone); a production site
		 * using 'sandbox' is safe but still worth flagging as probably
		 * unintentional.
		 */
		public static function should_warn_api_mismatch( string $api_mode ): bool {
			return $api_mode !== self::get_recommended_api_mode();
		}
	}
}
