<?php
/**
 * MMI_Environment — thin wrapper around WordPress core's own environment
 * detection (wp_get_environment_type(), WP_ENVIRONMENT_TYPE), used to guard
 * real-money XChange API calls (reserve/finalize/void/place_order) against
 * accidentally running on a staging/dev clone, and to warn when Reverb's own
 * sandbox/production API mode setting doesn't match the site's real
 * environment.
 *
 * See incident-history.md, "XChange Cross-Channel Duplicate-Order Risk",
 * 2026-08-24. Its one real call site
 * (MMI_Xchange_API_Client::guard_production_only()) fails CLOSED when the
 * class can't be found — refusing the call rather than assuming it's safe —
 * so if this class is ever missing, every real XChange order placement is
 * blocked, and the local guard message (which contains the word "disabled";
 * see MMI_Xchange_API::place_order()'s message-mapping) reads like an
 * XChange account restriction.
 *
 * Built on WordPress core's own wp_get_environment_type() (5.5+, reads the
 * WP_ENVIRONMENT_TYPE constant / 'production' default) rather than anything
 * plugin-specific — no dependency to vendor, no version negotiation needed
 * for the underlying signal itself.
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
