<?php
/**
 * MMI Shared Library — version-negotiated bootstrap.
 *
 * This file is bundled byte-identical into every mmi-* plugin (each plugin's own
 * copy lives at includes/mmi-shared/bootstrap.php). It never defines
 * MMI_Settings / MMI_Logger / MMI_API_Throttler directly — it registers this
 * copy as a candidate, then lazily autoloads whichever registered copy declares
 * the highest version the first time any of those three class names is actually
 * referenced. On a site running several MMI plugins, every plugin's copy
 * registers, the newest wins, and everyone ends up sharing one loaded set of
 * classes — one settings table, one log set, one real per-host rate limiter.
 * On a site running exactly one MMI plugin, that plugin's own copy is
 * trivially the only (and therefore winning) candidate — fully self-contained,
 * zero cross-plugin dependency either way.
 *
 * Why lazy (spl_autoload_register) and not a plugins_loaded hook: WordPress
 * fires plugins_loaded for already-active plugins BEFORE a plugin being
 * activated in the same request has even been require'd — a hook-based
 * resolver would lock in a winner before the activating plugin's copy could
 * register at all. Autoloading defers resolution until the moment a class is
 * first actually referenced, by which point every MMI plugin whose main file
 * has already been require'd this request — including one mid-activation,
 * since WordPress requires the plugin file before firing its activate_{slug}
 * hook — has had its bootstrap.php run and register its candidate.
 *
 * Known limitation (documented, not solved): if some MMI plugin's top-level
 * code (outside any hook) calls into MMI_Settings/MMI_Logger/MMI_API_Throttler
 * before every other MMI plugin due to load later in this same request has
 * had a chance to require its own bootstrap.php, resolution can lock in a
 * lower-version winner for that request. Mitigation: no plugin may call these
 * classes at file/top-level scope — only from within a hook callback
 * (plugins_loaded or later), which every MMI plugin's own architecture
 * already requires anyway (see AGENTS.md's Standalone Plugin Independence
 * runtime re-check rule). See ADR-0006.
 *
 * Second known-and-now-fixed bug, found live on studiomann.com: the
 * class-name-to-file map used to be a `static` array hardcoded inside
 * mmi_shared_lib_autoload()'s own function body — and since function_exists()
 * means only the FIRST-loaded plugin's copy of that function body ever takes
 * effect, a newly-added class (MMI_Media_Helper) was invisible on any site
 * where an older, not-yet-resynced plugin happened to load first, even
 * though mmi_shared_lib_winner() correctly picked the newer plugin's
 * directory for classes both copies agreed on. Fixed by having each
 * candidate register its OWN class map alongside its version/dir, so the
 * autoloader always looks up the file name from the WINNING candidate's own
 * (newest) map — never a frozen one from whichever copy loaded first.
 *
 * ADR-0007 (2026-09-17) added MMI_DB, MannMade\Integrations\HTTP\HTTPClient,
 * and MannMade\Integrations\Helpers\MMI_Updater_Trait as an 8th/9th/10th
 * bundled class — superseding ADR-0002's decision to keep MMI_DB mmi-hub-only,
 * which stopped making sense once mmi-hub itself started being eliminated.
 *
 * ADR-0008 (2026-09-17) added MMI_License_API and MMI_License_Client as an
 * 11th/12th bundled class, plus a NEW mechanism shape: licensing-helpers.php
 * (mmi_is_licensed() and 3 sibling functions) is `require_once`'d eagerly
 * below, function_exists()-guarded, rather than lazily autoloaded — PHP has
 * no autoload hook for plain functions the way it does for classes/traits.
 * See licensing-helpers.php's own docblock for the accepted version-skew
 * limitation this introduces (lower risk than it sounds — these are thin,
 * stable wrappers around the already-version-negotiated MMI_Settings::get()).
 *
 * mmi-hub-elimination Phase 7 (2026-09-17) added MMI_Subscription_Conflict_Notice
 * as a 13th bundled class — found genuinely unguarded (a real fatal, caught
 * live on studiomann.com) in mmi-admin's subscription-license adapter.
 *
 * Added MMI_Software_Fulfillment (2026-09-23): vendor-neutral software
 * license delivery core — provider registry, per-line-item placed/fulfilled
 * records, customer grouping, one combined customer email. See its docblock.
 *
 * Added MMI_Marketplace_Order_Sources as a 14th bundled class (2026-09-17,
 * same day): it also lived in mmi-hub and was missed during the elimination
 * migration, silently disabling mmi-xchange-integration's duplicate-order
 * protection for Reverb-imported orders (found via Intelephense's undefined-
 * type flags on the dangling class references, then confirmed as a live gap
 * against mmi-reverb-integration.php's registration call and
 * mmi-xchange-integration's two consumers). See class-marketplace-order-
 * sources.php's own docblock for the rebuild rationale.
 *
 * Added the shared suite parent menu (2026-09-18, see the section below) —
 * restores the single "MannMade" top-level admin menu every mmi-* plugin
 * used to attach under via mmi-hub's own MMI_Centralized_Menu, lost when
 * mmi-hub was deleted in Phase 8 and every migrated plugin's dormant
 * class_exists('MMI_Centralized_Menu') fallback started unconditionally
 * registering its own top-level menu instead.
 *
 * Added MMI_Email_Templates as a 15th bundled class (2026-09-18): it also
 * lived in mmi-hub only and was missed by the elimination migration — every
 * call site was already class_exists()-guarded so nothing fataled, but every
 * branded MMI email (the daily data-pipeline digest, Reverb/XChange/license
 * alerts, the mmi-email-customizer "MMI Emails" tab) silently degraded to
 * plain text or stopped rendering once mmi-hub was deleted. Reconstructed
 * from call-site evidence across 6 plugins; its shipped-default token file
 * (email-tokens-default.php, a sibling in this same directory) replaces
 * mmi-hub's deleted includes/email/email-tokens-default.php. See
 * class-email-templates.php's own docblock for the full design and a still-
 * broken "Ship to mmi-hub" packaging pipeline this does not fix.
 *
 * Added MMI_Environment as an 18th bundled class (2026-09-19): its one real
 * call site, MMI_Xchange_API_Client::guard_production_only(), fails CLOSED
 * when the class can't be found (refuses the call rather than assuming it's
 * safe) — so unlike every class_exists()-guarded gap found so far, this one
 * caused no fatal, just a silent, total block on every real XChange order
 * placement (reserve/finalize/void/place_order) since mmi-hub's deletion
 * (1.173.0, 2026-09-17), misreported as an XChange account restriction
 * because the resulting local guard message contains the word "disabled".
 * Confirmed via the order data itself: the last auto-generated PO number
 * belongs to an order placed 2026-09-16 23:35, nothing since. Rebuilt on
 * WordPress core's own wp_get_environment_type() — see
 * class-environment.php's own docblock.
 *
 * Added MMI_UI_Styles as a 19th bundled class (2026-09-19): the Style Manager
 * tab's schema/sanitize/published-tokens source, same shape as
 * MMI_Email_Templates (class-email-templates.php) but for the suite-wide
 * admin UI's CSS custom properties (header/card/table/button/log/stat-tile)
 * instead of email colors. Also lived in mmi-hub only and was never rebuilt —
 * every call site already class_exists()-guarded, so Style Manager degraded
 * to an honest "no schema" placeholder rather than a fatal (see
 * class-ui-styles.php's own docblock for the full rationale and why this one
 * needed a genuine design pass rather than a mechanical restoration). Ship
 * writes ui-style-tokens-default.php (a sibling in this directory, generated
 * the same way email-tokens-default.php is) and this bootstrap's own asset
 * registration below applies it suite-wide via wp_add_inline_style() against
 * the mmi-suite-common handle, only once that file actually exists.
 *
 * Added MMI_Taxonomy_Filter_Handler as a 17th bundled class (2026-09-19):
 * the generic mmi_get_taxonomy_terms AJAX action js/shared/taxonomy-filter.js
 * fires (Reverb's Products tab Brand/Category/Distribution filters) had zero
 * registered listeners — confirmed live via $wp_filter — exactly the same
 * migration gap as MMI_WC_Product_Filter_Handler below, just for its sibling
 * shared JS module. See class-taxonomy-filter-handler.php's own docblock.
 *
 * Added MMI_Guest_Customer_Converter as a 20th bundled class (2026-09-19):
 * also lived in mmi-hub only and was never rebuilt — every call site in both
 * consuming plugins (mmi-reverb-integration, mmi-xchange-integration) was
 * already class_exists()-guarded, so nothing fataled, but the feature went
 * completely dark: Reverb's automatic buyer-email-reply conversion silently
 * no-ops, XChange's manual-fulfillment "verified after external import"
 * check fails permanently closed, and the Orders tab's "Link to Customer"
 * button posts to an AJAX action (mmi_guest_customer_convert) nothing had
 * registered a handler for. Reconstructed from calling-convention evidence
 * across both plugins — see class-guest-customer-converter.php's own
 * docblock. Also registers the mmi_guest_customer_convert AJAX action
 * eagerly below, same shape as the WC filter-bar/taxonomy-filter handlers.
 *
 * Added MMI_Plugin_Update_Client as a 21st bundled class (2026-09-19): a
 * genuinely new feature, not a mmi-hub-elimination casualty — no customer
 * site has ever had a WordPress-native plugin updater. Named "Plugin
 * Update Client" rather than "Update Client" specifically to not collide
 * with MMI_Updater_Trait (trait-updater.php, same directory, an unrelated
 * data-feed helper — see that class's own docblock note). Hooks
 * pre_set_site_transient_update_plugins/plugins_api against mmi-admin's new
 * MMI_Extensions_REST_Controller (GET mmi-hub/v1/extensions[/{slug}/
 * download], itself rebuilding a dead route both MMI_Extensions_Manager and
 * MMI_Package_Publisher's Verify Package check had been silently failing
 * against) so every mmi-* plugin gets a real "Update available" row,
 * version-details popup, and one-click update — license-gated the same way
 * feature access already is. Also registers its filters/actions eagerly
 * below, same shape as every other cross-cutting hook in this file, since
 * every plugin needs this behavior automatically rather than opting in.
 * See class-plugin-update-client.php's own docblock for the full design.
 *
 * Added MMI_WC_Product_Filter_Handler as a 16th bundled class (2026-09-18):
 * the search/stock/image/price product filter bar (js/shared/wc-product-
 * filter-bar.js, MMI_WcFilterBar — already bundled suite-wide) had no
 * surviving PHP counterpart after mmi-hub's deletion. Every call site
 * degraded gracefully (class_exists() guarded) but the filters silently
 * never applied anywhere. Rebuilt once a second real consumer confirmed
 * this is genuinely cross-plugin — see class-wc-product-filter-handler.php's
 * own docblock. Also registers the mmi_get_wc_product_filter_counts AJAX
 * action (the pill-badge counts endpoint) eagerly below, same shape as
 * licensing-helpers.php, since it needs a hook registered once per request
 * rather than a lazily-autoloaded class.
 *
 * @package MannMade\SharedLib
 */

// Allow the standalone CLI test harness (tests/test-version-negotiation.php)
// to load this file without a WordPress bootstrap.
if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

/**
 * This copy's library version — bump on every change to any file in this
 * directory. Deliberately a local variable, not a define(): every plugin's
 * bootstrap.php is a physically distinct file, so each require_once of it
 * executes this line fresh. A defined() constant would be wrong here — only
 * the first-loaded plugin's define() would ever take effect, so every later
 * plugin's registration would silently report the FIRST plugin's version
 * instead of its own, breaking negotiation entirely.
 */
$mmi_shared_lib_this_copy_version = '1.42.1';

/**
 * This copy's own class-name-to-file map. Registered alongside version/dir
 * (see mmi_shared_lib_register() below) so the autoloader always consults
 * the WINNING candidate's own map, not a map frozen inside whichever copy's
 * function body happened to load first — see the "Second known-and-now-fixed
 * bug" note above.
 */
$mmi_shared_lib_this_copy_class_map = array(
	'MMI_Settings'       => 'class-settings.php',
	'MMI_Logger'         => 'class-logger.php',
	'MMI_API_Throttler'  => 'class-api-throttler.php',
	'MMI_Resource_Guard' => 'class-resource-guard.php',
	'MMI_Model'          => 'class-model.php',
	'MMI_Product'        => 'class-product-model.php',
	'MMI_Media_Helper'   => 'class-media-helper.php',
	'MMI_DB'             => 'class-mmi-db.php',
	'MannMade\\Integrations\\HTTP\\HTTPClient'      => 'class-http-client.php',
	'MannMade\\Integrations\\Helpers\\MMI_Updater_Trait' => 'trait-updater.php',
	'MMI_License_API'    => 'class-license-api.php',
	'MMI_License_Client' => 'class-license-client.php',
	'MMI_Subscription_Conflict_Notice' => 'class-subscription-conflict-notice.php',
	'MMI_Marketplace_Order_Sources' => 'class-marketplace-order-sources.php',
	'MMI_Email_Templates' => 'class-email-templates.php',
	'MMI_WC_Product_Filter_Handler' => 'class-wc-product-filter-handler.php',
	'MMI_Batch_Watchdog_Trait' => 'trait-batch-watchdog.php',
	'MMI_Taxonomy_Filter_Handler' => 'class-taxonomy-filter-handler.php',
	'MMI_Environment'    => 'class-environment.php',
	'MMI_UI_Styles'      => 'class-ui-styles.php',
	'MMI_Guest_Customer_Converter' => 'class-guest-customer-converter.php',
	'MMI_Plugin_Update_Client' => 'class-plugin-update-client.php',
	'MMI_Software_Fulfillment' => 'class-software-fulfillment.php',
	'MMI_Feature_Gate'   => 'class-feature-gate.php',
	'MMI_License_UI'     => 'class-license-ui.php',
	'MMI_License_Gate'   => 'class-license-gate.php',
	'MMI_License_Recheck' => 'class-license-recheck.php',
	'MMI_Condition_Builder' => 'class-condition-builder.php',
	'MMI_Audit_Log'      => 'class-audit-log.php',
);

// ── Candidate registry ──────────────────────────────────────────────────
// A static array inside a by-reference accessor so every copy's identical
// function body reads/writes the same underlying storage for the life of
// the request, regardless of which copy's definition actually "won" the
// function_exists() race below.

if ( ! function_exists( 'mmi_shared_lib_candidates' ) ) {
	function &mmi_shared_lib_candidates(): array {
		static $candidates = array();
		return $candidates;
	}
}

if ( ! function_exists( 'mmi_shared_lib_register' ) ) {
	/**
	 * Register this copy as a candidate. Safe to call more than once for the
	 * same directory (e.g. a plugin that happens to require its own
	 * bootstrap.php twice) — duplicates are harmless, version_compare just
	 * sees the same version twice.
	 */
	function mmi_shared_lib_register( string $version, string $dir, array $class_map ): void {
		$candidates   =& mmi_shared_lib_candidates();
		$candidates[] = array(
			'version'   => $version,
			'dir'       => rtrim( $dir, '/' ),
			'class_map' => $class_map,
		);
	}
}

mmi_shared_lib_register( $mmi_shared_lib_this_copy_version, __DIR__, $mmi_shared_lib_this_copy_class_map );

if ( ! function_exists( 'mmi_shared_lib_winner' ) ) {
	/**
	 * Resolve (and cache) the winning candidate — highest version_compare()
	 * result, ties broken by registration order. Resolved once per request:
	 * cached in a static so a candidate registering after resolution has
	 * already happened cannot retroactively change the winner mid-request
	 * (see "Known limitation" above).
	 */
	function mmi_shared_lib_winner(): array {
		static $winner = null;
		if ( $winner === null ) {
			$candidates = mmi_shared_lib_candidates();
			usort(
				$candidates,
				static function ( $a, $b ) {
					return version_compare( $b['version'], $a['version'] );
				}
			);
			$winner = $candidates[0];
		}
		return $winner;
	}
}

if ( ! function_exists( 'mmi_shared_lib_autoload' ) ) {
	function mmi_shared_lib_autoload( string $class ): void {
		$winner = mmi_shared_lib_winner();

		if ( ! isset( $winner['class_map'][ $class ] ) ) {
			return;
		}

		$path = $winner['dir'] . '/' . $winner['class_map'][ $class ];

		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
}

if ( ! function_exists( 'mmi_shared_lib_log_dir' ) ) {
	/**
	 * Suite-wide log directory, independent of any single plugin's own
	 * folder — stays stable across requests regardless of which plugin's
	 * copy of the library actually wins the version negotiation, and
	 * survives that plugin later being deactivated as long as at least one
	 * other MMI plugin remains active.
	 *
	 * While mmi-hub is still installed (transition period — see ADR-0006),
	 * it defines MMI_HUB_LOG_DIR very early (mmi-hub.php, before any class
	 * loads) and its own bundled MMI_Logger copy still writes there whenever
	 * mmi-hub's own eager require wins the class_exists() race. Preferring
	 * that same constant here — when it's defined — keeps every log in one
	 * place regardless of which copy of MMI_Logger actually wins a given
	 * request, instead of silently splitting entries between mmi-hub/logs/
	 * and a brand-new wp-content/mmi-logs/ depending on load-order luck.
	 * Once mmi-hub is actually deleted (Phase 8), MMI_HUB_LOG_DIR stops
	 * being defined and this falls through to the real standalone default.
	 */
	function mmi_shared_lib_log_dir(): string {
		if ( defined( 'MMI_SHARED_LOG_DIR' ) ) {
			return untrailingslashit( MMI_SHARED_LOG_DIR );
		}
		if ( defined( 'MMI_HUB_LOG_DIR' ) ) {
			return untrailingslashit( MMI_HUB_LOG_DIR );
		}
		// A site that already logs to wp-content/mmi-logs keeps it (its own
		// server config and scripts point there). Every new install logs into
		// the private root instead: logs carry IPs, emails and API errors,
		// and a guessable wp-content path is downloadable on nginx, which
		// never reads the .htaccess guard.
		static $dir = null;
		if ( $dir === null ) {
			$legacy = WP_CONTENT_DIR . '/mmi-logs';
			$dir    = $legacy;
			if ( ! is_dir( $legacy ) && function_exists( 'mmi_shared_lib_private_subdir' ) ) {
				$private = mmi_shared_lib_private_subdir( 'logs' );
				if ( $private !== '' ) {
					$dir = untrailingslashit( $private );
				}
			}
		}
		return $dir;
	}
}

if ( ! function_exists( 'mmi_shared_lib_private_dir' ) ) {
	/**
	 * Private data root for anything that must never be downloadable by URL —
	 * supplier feeds (vendor contacts, tax IDs, dealer costs), plugin zips.
	 * ADR-0012.
	 *
	 * Lives in uploads (the one directory WordPress guarantees is writable on
	 * every host) under an unguessable name: `mmi-private-<32 hex>`. The
	 * name is the protection that works everywhere; the .htaccess/index.php
	 * written inside are extra layers for Apache and directory listings.
	 * nginx never reads .htaccess — that's exactly how the old `mmi-json/`
	 * and `mmi-packages/` ended up publicly downloadable (found 2026-09-26).
	 *
	 * The directory itself is the record of its name: whichever
	 * `mmi-private-*` exists is the one in use. Nothing is stored in the
	 * database, so the data and its location move together in backups and
	 * site migrations, and this works before WordPress finishes loading.
	 * Creation happens under a file lock so two concurrent first requests
	 * can't each create one.
	 *
	 * @return string Absolute path, trailing slash, or '' if uploads isn't writable.
	 */
	function mmi_shared_lib_private_dir(): string {
		static $dir = null;
		if ( $dir !== null ) {
			return $dir;
		}

		$uploads = WP_CONTENT_DIR . '/uploads';
		$found   = glob( $uploads . '/mmi-private-*', GLOB_ONLYDIR ) ?: array();

		if ( ! $found ) {
			if ( ! is_dir( $uploads ) || ! is_writable( $uploads ) ) {
				return $dir = '';
			}
			$lock = fopen( $uploads . '/.mmi-private.lock', 'c' );
			if ( $lock ) {
				flock( $lock, LOCK_EX );
			}
			$found = glob( $uploads . '/mmi-private-*', GLOB_ONLYDIR ) ?: array();
			if ( ! $found ) {
				$new = $uploads . '/mmi-private-' . bin2hex( random_bytes( 16 ) );
				if ( mkdir( $new, 0755 ) ) {
					$found = array( $new );
				}
			}
			if ( $lock ) {
				flock( $lock, LOCK_UN );
				fclose( $lock );
			}
			if ( ! $found ) {
				return $dir = '';
			}
		}

		sort( $found );
		$dir = trailingslashit( $found[0] );
		mmi_shared_lib_guard_dir( $dir );
		return $dir;
	}
}

if ( ! function_exists( 'mmi_shared_lib_guard_dir' ) ) {
	/**
	 * Drop the Apache deny rule and an empty index.php into $dir (once).
	 * Belt and braces only — see mmi_shared_lib_private_dir().
	 */
	function mmi_shared_lib_guard_dir( string $dir ): void {
		if ( ! file_exists( $dir . 'index.php' ) ) {
			@file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			@file_put_contents( $dir . '.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n" );
		}
	}
}

if ( ! function_exists( 'mmi_shared_lib_merge_dir' ) ) {
	/**
	 * Move everything under $from into $to (newer file wins on a clash),
	 * recursing into subfolders, then remove $from. The legacy public
	 * folder's own .htaccess/index.php are dropped, not carried over.
	 */
	function mmi_shared_lib_merge_dir( string $from, string $to ): void {
		$from = trailingslashit( $from );
		$to   = trailingslashit( $to );
		if ( ! is_dir( $to ) ) {
			wp_mkdir_p( $to );
		}
		foreach ( scandir( $from ) ?: array() as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$src = $from . $entry;
			$dst = $to . $entry;
			if ( is_dir( $src ) ) {
				mmi_shared_lib_merge_dir( $src, $dst );
			} elseif ( $entry === '.htaccess' || $entry === 'index.php' ) {
				@unlink( $src );
			} elseif ( ! file_exists( $dst ) || filemtime( $src ) > filemtime( $dst ) ) {
				@rename( $src, $dst );
			} else {
				@unlink( $src );
			}
		}
		@rmdir( $from );
	}
}

if ( ! function_exists( 'mmi_shared_lib_private_subdir' ) ) {
	/**
	 * A named folder inside the private root, created and guarded on first
	 * use. If $legacy (an old public directory) still exists, its files are
	 * moved in — newer copy wins — and it is removed once empty, so an
	 * upgrade takes the old public copies off the web by itself.
	 *
	 * @param  string $name   Folder name, e.g. 'json'.
	 * @param  string $legacy Absolute path of the old public directory, or ''.
	 * @return string Absolute path, trailing slash; falls back to $legacy
	 *                only if the private root can't be created at all.
	 */
	function mmi_shared_lib_private_subdir( string $name, string $legacy = '' ): string {
		static $resolved = array();
		if ( isset( $resolved[ $name ] ) ) {
			return $resolved[ $name ];
		}

		$root = mmi_shared_lib_private_dir();
		if ( $root === '' ) {
			return $resolved[ $name ] = ( $legacy !== '' ? trailingslashit( $legacy ) : '' );
		}

		$dir = $root . $name . '/';
		if ( ! is_dir( $dir ) ) {
			// Whole-directory move when possible: atomic on one filesystem.
			if ( $legacy !== '' && is_dir( $legacy ) && @rename( $legacy, rtrim( $dir, '/' ) ) ) {
				@unlink( $dir . '.htaccess' );
			} else {
				wp_mkdir_p( $dir );
			}
		}
		mmi_shared_lib_guard_dir( $dir );

		// A stale plugin copy may have re-created the legacy folder after the
		// move — fold anything it wrote back in, then remove it.
		if ( $legacy !== '' && is_dir( $legacy ) ) {
			mmi_shared_lib_merge_dir( $legacy, $dir );
		}

		return $resolved[ $name ] = $dir;
	}
}

if ( ! function_exists( 'mmi_shared_lib_json_dir' ) ) {
	/**
	 * Suite-wide directory for supplier feed JSON (xchange-products.json,
	 * skuport-products.json, etc.) — written by each supplier's *_Updater
	 * class and read back by the import/preview/mapping code, so every one
	 * of those ~40 call sites across mmi-data-pipeline, mmi-reverb-integration,
	 * and mmi-xchange-integration must resolve to the identical directory or
	 * a writer and a reader silently talk past each other.
	 *
	 * `MMI_JSON_PATH` was an mmi-hub-era constant this suite no longer
	 * defines anywhere (confirmed live: defined('MMI_JSON_PATH') === false
	 * on every site) — checking for it here is a no-op today, kept only so
	 * a future reintroduction would still be picked up, same rationale as
	 * MMI_HUB_LOG_DIR above. The real, always-taken path is the standalone
	 * default — WP_CONTENT_DIR rather than wp_upload_dir() so this matches
	 * mmi_shared_lib_log_dir()'s pattern and needs no WP bootstrap to have
	 * finished loading.
	 *
	 * Before this function existed, the fallback was copy-pasted at every
	 * call site — two of those copies had silently drifted to a different,
	 * nonexistent directory (WP_PLUGIN_DIR . '/mmi-data-pipeline/storage/json/'),
	 * and one had no usable fallback at all (bare ''), which together broke
	 * every scheduled/manual product import, several non-product data-type
	 * imports, Attribute Mapping, and the Xchange/SkuPort stock-reconciliation
	 * feed_sync phase — all silently, since a missing file just looks like an
	 * empty feed rather than an error. See changelog for the incident.
	 */
	function mmi_shared_lib_json_dir(): string {
		if ( defined( 'MMI_JSON_PATH' ) ) {
			return trailingslashit( MMI_JSON_PATH );
		}
		// Private since 2026-09-26 (ADR-0012): the old public uploads/mmi-json/
		// is moved in on first call and removed.
		return mmi_shared_lib_private_subdir( 'json', WP_CONTENT_DIR . '/uploads/mmi-json' );
	}
}

if ( ! defined( 'MMI_SHARED_LIB_AUTOLOAD_REGISTERED' ) ) {
	define( 'MMI_SHARED_LIB_AUTOLOAD_REGISTERED', true );
	spl_autoload_register( 'mmi_shared_lib_autoload' );
}

/**
 * ── Licensing helper functions (Phase 6, ADR-0008) ──────────────────────
 *
 * mmi_is_licensed() and its 3 siblings are plain global functions, not
 * classes — PHP has no autoload mechanism for functions, so this can't use
 * the lazy spl_autoload_register() approach above. Eagerly require now,
 * guarded by function_exists() so only the first-loaded plugin's copy of
 * these definitions ever takes effect (same shape as every function defined
 * directly in this bootstrap.php file). See licensing-helpers.php's own
 * docblock for the version-skew trade-off this accepts.
 */
if ( defined( 'ABSPATH' ) || defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	require_once __DIR__ . '/licensing-helpers.php';
}

/**
 * ── Plugin update client: WordPress-native plugin-update hooks ──────────
 *
 * MMI_Plugin_Update_Client itself is lazily autoloaded like any other class
 * here, but the filters/action that make it actually run need registering
 * once per request regardless of which plugin's copy of this file wins
 * version negotiation — same function_exists()-guarded eager-registration
 * shape as the licensing helpers and AJAX handlers in this file. The
 * callables are plain class-string arrays, so referencing
 * MMI_Plugin_Update_Client here doesn't force it to load —
 * spl_autoload_register() only fires the first time one of these hooks
 * actually runs.
 */
if ( ! function_exists( 'mmi_shared_update_client_bootstrap' ) ) {
	function mmi_shared_update_client_bootstrap(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ 'MMI_Plugin_Update_Client', 'check_for_updates' ] );
		add_filter( 'plugins_api', [ 'MMI_Plugin_Update_Client', 'plugin_info' ], 20, 3 );
		add_action( 'upgrader_process_complete', [ 'MMI_Plugin_Update_Client', 'purge_cache_after_update' ], 10, 2 );
		// Client-local activation hooks (MMI_License_Client::persist()/clear(),
		// this site's own activation) — NOT MMI_License_Manager's
		// mmi_license_activated/deactivated, which only ever fire on
		// mannmade.us itself (the server side of the licensing round-trip).
		add_action( 'mmi_license_client_activated', [ 'MMI_Plugin_Update_Client', 'purge_cache' ] );
		add_action( 'mmi_license_client_deactivated', [ 'MMI_Plugin_Update_Client', 'purge_cache' ] );
	}
	mmi_shared_update_client_bootstrap();
}

// Outside the function_exists() guard on purpose: the guarded body comes from
// whichever copy loads first, which may predate this filter. Every copy's
// top-level code runs, and the winning class is always the newest, so any
// copy that has this line also guarantees the method exists. Without it,
// package downloads carry no license key and the server answers 403.
if ( defined( 'ABSPATH' ) && ! has_filter( 'http_request_args', [ 'MMI_Plugin_Update_Client', 'add_license_header' ] ) ) {
	add_filter( 'http_request_args', [ 'MMI_Plugin_Update_Client', 'add_license_header' ], 10, 2 );
}

/**
 * ── WC product filter bar: pill-count AJAX endpoint ─────────────────────
 *
 * MMI_WcFilterBar (js/shared/wc-product-filter-bar.js) fires this action on
 * its own, unconditionally, whenever the filter bar's markup is present on
 * the page — it has no idea which plugin rendered that markup. Registered
 * once here, function_exists()-guarded like the licensing helpers above,
 * rather than per-plugin, so it works regardless of which MMI plugin(s)
 * happen to be active.
 */
/**
 * ── License activation UI: AJAX handlers ─────────────────────────────────
 *
 * MMI_License_UI itself is lazily autoloaded like any other class here, but
 * the wp_ajax_* actions its panel's form posts to need registering once per
 * request regardless of which plugin's copy of this file wins version
 * negotiation — same function_exists()-guarded eager-registration shape as
 * the update-client bootstrap above. Referencing the class as a
 * class-string callable here doesn't force it to load.
 */
if ( ! function_exists( 'mmi_shared_license_ui_bootstrap' ) ) {
	function mmi_shared_license_ui_bootstrap(): void {
		add_action( 'wp_ajax_mmi_activate_license', [ 'MMI_License_UI', 'ajax_activate' ] );
		add_action( 'wp_ajax_mmi_deactivate_license', [ 'MMI_License_UI', 'ajax_deactivate' ] );
	}
	mmi_shared_license_ui_bootstrap();
}

/**
 * ── License revalidation: weekly + on-demand cron hooks ──────────────────
 *
 * Every site that bundles this library re-checks ALL of its cached
 * licenses once a week (mmi_license_revalidate_all() in licensing-helpers.php)
 * so a key deleted/suspended/expired on the license server stops unlocking
 * plugins here. Weekly is only the backstop: the server pushes a re-check
 * when staff change a license, and a check fires at expiry (both in
 * MMI_License_Recheck). Before this, the only daily check lived in mmi-admin's
 * MMI_License_Cron — which customer sites never have — and it only looked
 * at 'mmi-suite'. Where mmi-admin IS active, MMI_License_Cron owns the
 * daily run (it adds the admin email), so the shared daily event stands
 * down there. The _now hook is the one-off event
 * mmi_license_maybe_revalidate() queues from a License panel view.
 */
if ( ! function_exists( 'mmi_shared_license_revalidate_bootstrap' ) ) {
	function mmi_shared_license_revalidate_bootstrap(): void {
		add_action( 'mmi_shared_license_revalidate_now', 'mmi_license_revalidate_all' );
		add_action( 'mmi_shared_license_revalidate', 'mmi_shared_license_revalidate_daily' );
		add_action( 'init', 'mmi_shared_license_revalidate_schedule' );
	}

	function mmi_shared_license_revalidate_daily(): void {
		if ( class_exists( 'MMI_License_Cron' ) ) {
			return;
		}
		mmi_license_revalidate_all();
	}

	function mmi_shared_license_revalidate_schedule(): void {
		$recurrence = wp_get_schedule( 'mmi_shared_license_revalidate' );
		if ( $recurrence === 'weekly' ) {
			return;
		}
		if ( $recurrence ) {
			// Sites scheduled before 1.35.0 run daily.
			wp_clear_scheduled_hook( 'mmi_shared_license_revalidate' );
		}
		// Deterministic per-site 0-12h offset — same thundering-herd spread
		// MMI_License_Cron uses against the license server.
		$offset = absint( crc32( get_site_url() ) ) % ( 12 * HOUR_IN_SECONDS );
		wp_schedule_event( time() + $offset, 'weekly', 'mmi_shared_license_revalidate' );
	}

	mmi_shared_license_revalidate_bootstrap();
}

/**
 * ── License recheck: server push + check at expiry ───────────────────────
 * See MMI_License_Recheck. The expiry event is re-aimed whenever the cached
 * licenses change, plus on admin_init as a cheap self-heal.
 */
if ( ! function_exists( 'mmi_shared_license_recheck_bootstrap' ) ) {
	function mmi_shared_license_recheck_bootstrap(): void {
		add_action( 'rest_api_init', [ 'MMI_License_Recheck', 'register_route' ] );
		add_action( MMI_License_Recheck::EXPIRY_HOOK, 'mmi_license_revalidate_all' );
		foreach ( [ 'mmi_license_revalidated', 'mmi_license_client_activated', 'mmi_license_client_deactivated', 'admin_init' ] as $hook ) {
			add_action( $hook, [ 'MMI_License_Recheck', 'schedule_expiry_check' ], 20 );
		}
	}
	mmi_shared_license_recheck_bootstrap();
}

/**
 * ── Audit log (MMI_Audit_Log) ────────────────────────────────────────────
 * Prune cron, MannMade → Audit Log viewer, CSV export, core auth events and
 * `wp mmi-audit`. Registered once per request whichever copy loads first;
 * the class itself comes from the version-negotiated winner.
 */
if ( ! function_exists( 'mmi_shared_audit_log_bootstrap' ) && defined( 'ABSPATH' ) ) {
	function mmi_shared_audit_log_bootstrap(): void {
		MMI_Audit_Log::register_hooks();
	}
	add_action( 'plugins_loaded', 'mmi_shared_audit_log_bootstrap', 1 );
}

if ( ! function_exists( 'mmi_shared_ajax_get_wc_product_filter_counts' ) ) {
	function mmi_shared_ajax_get_wc_product_filter_counts(): void {
		check_ajax_referer( 'mmi_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}
		wp_send_json_success( MMI_WC_Product_Filter_Handler::global_counts() );
	}
	add_action( 'wp_ajax_mmi_get_wc_product_filter_counts', 'mmi_shared_ajax_get_wc_product_filter_counts' );
}

/**
 * ── Taxonomy filter widget: term-lookup AJAX endpoint ────────────────────
 *
 * js/shared/taxonomy-filter.js fires this action on its own, unconditionally,
 * for any table element carrying data-taxonomy-filters — it has no idea
 * which plugin rendered that markup. Registered once here, same shape as
 * the WC filter-bar handler above. Capability is manage_woocommerce, not
 * manage_options: this widget's only current real consumer (Reverb's
 * Products tab) gates its own page at manage_woocommerce, so requiring the
 * stricter capability here would silently 403 a Shop Manager who can
 * already see and use that page — the same mismatch shape flagged
 * separately in XChange's header Test Connection/Test Order API buttons.
 * Revisit if a future consumer needs a different (stricter) gate.
 */
if ( ! function_exists( 'mmi_shared_ajax_get_taxonomy_terms' ) ) {
	function mmi_shared_ajax_get_taxonomy_terms(): void {
		check_ajax_referer( 'mmi_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}
		$slugs = json_decode( wp_unslash( $_POST['taxonomies'] ?? '[]' ), true );
		if ( ! is_array( $slugs ) ) {
			$slugs = [];
		}
		wp_send_json_success( MMI_Taxonomy_Filter_Handler::get_terms_for( $slugs ) );
	}
	add_action( 'wp_ajax_mmi_get_taxonomy_terms', 'mmi_shared_ajax_get_taxonomy_terms' );
}

// Condition builder's "Load saved conditions" — every saved set in the suite,
// via the 'mmi_condition_library' filter (see MMI_Condition_Builder).
if ( ! function_exists( 'mmi_shared_ajax_condition_library' ) ) {
	function mmi_shared_ajax_condition_library(): void {
		if ( ! class_exists( 'MMI_Condition_Builder' ) ) {
			wp_send_json_error( array( 'message' => 'Condition builder unavailable' ) );
		}
		MMI_Condition_Builder::ajax_library();
	}
	add_action( 'wp_ajax_mmi_condition_library', 'mmi_shared_ajax_condition_library' );
}

/**
 * ── Guest customer conversion: "Link to Customer" AJAX endpoint ─────────
 *
 * mmi-xchange-integration's Orders tab widget (admin-xchange.js's
 * .mmi-x-guest-convert-btn handler) posts this action directly — it has no
 * idea which plugin's shared-library copy will end up serving it. Registered
 * once here, same shape as the two handlers above; the method itself does
 * its own nonce/capability checks (MMI_Guest_Customer_Converter::NONCE_ACTION),
 * so this wrapper only needs to exist because a class has no hook of its own.
 */
if ( ! function_exists( 'mmi_shared_ajax_guest_customer_convert' ) ) {
	function mmi_shared_ajax_guest_customer_convert(): void {
		MMI_Guest_Customer_Converter::ajax_convert();
	}
	add_action( 'wp_ajax_mmi_guest_customer_convert', 'mmi_shared_ajax_guest_customer_convert' );
}

/**
 * ── Shared design-system assets (Phase 3, MMI_HUB_ELIMINATION_HANDOFF.md) ──
 *
 * mmi-hub's Asset_Manager registers mmi-suite-common.css and 8 shared JS
 * handles (ajax-handler, escape-html, table-manager, taxonomy-filter,
 * wc-filter-bar, column-customizer, modal, pagination) that every plugin's
 * own enqueue calls declare as a dependency BY NAME, not by direct file
 * reference — those already degrade gracefully (WordPress silently drops a
 * missing dependency handle) whether or not mmi-hub is present, so they
 * don't strictly need this section to avoid breaking.
 *
 * What DOES need this section: mmi-hub.php's enqueue_global_admin_assets()
 * and Asset_Manager::add_mmi_body_class() apply to EVERY admin page
 * ambiently — no plugin "depends on" them by name, mmi-hub just always
 * provided them. That's the universal column-resizer (CSS+JS), the notice
 * anchor script (required by the Admin Page Shell rule for `.mmi-header` +
 * `.wp-header-end` to actually keep WP core from relocating notices), the
 * product-category AJAX guard, and the `body.mmi-page` class the Admin Page
 * Shell rule's width-capping CSS relies on. Without mmi-hub, NONE of this
 * fires for any migrated plugin's admin pages — not a fatal, but a real,
 * previously-undocumented visual/behavioral gap this section closes.
 *
 * Design: reuses the same version-negotiated candidate-registry pattern as
 * the class loader above, but as a fully separate registry — isolated from
 * the already-proven class-loading mechanism so a bug here can't affect it.
 * Deliberately simpler on one axis: while mmi-hub IS present, this entire
 * section is a hard no-op (guarded by class_exists('MMI_Hub')) — it never
 * competes with or overrides mmi-hub's own Asset_Manager registrations, so
 * there is zero behavior change on any site where mmi-hub is installed.
 * Only once mmi-hub is genuinely absent does the highest-version registered
 * candidate take over providing these same assets from its own bundled copy.
 */

if ( ! function_exists( 'mmi_shared_assets_candidates' ) ) {
	function &mmi_shared_assets_candidates(): array {
		static $candidates = array();
		return $candidates;
	}
}

if ( ! function_exists( 'mmi_shared_assets_register' ) ) {
	function mmi_shared_assets_register( string $version, string $dir, string $url ): void {
		$candidates   =& mmi_shared_assets_candidates();
		$candidates[] = array(
			'version' => $version,
			'dir'     => rtrim( $dir, '/' ),
			'url'     => rtrim( $url, '/' ) . '/',
		);
	}
}

// This copy's assets live in a sibling 'assets/' directory of this same
// bootstrap.php, mirroring mmi-hub's own assets/css/shared + assets/js/shared
// layout so file names below need no translation. Uses this copy's OWN
// version, not a separately-tracked one — the assets and the PHP classes in
// this directory are synced together by the same sync-to-plugins.sh run.
if ( defined( 'ABSPATH' ) ) {
	mmi_shared_assets_register( $mmi_shared_lib_this_copy_version, __DIR__ . '/assets', plugin_dir_url( __FILE__ ) . 'assets' );
}

if ( ! function_exists( 'mmi_shared_assets_winner' ) ) {
	function mmi_shared_assets_winner(): array {
		static $winner = null;
		if ( $winner === null ) {
			$candidates = mmi_shared_assets_candidates();
			usort(
				$candidates,
				static function ( $a, $b ) {
					return version_compare( $b['version'], $a['version'] );
				}
			);
			$winner = $candidates[0];
		}
		return $winner;
	}
}

if ( ! function_exists( 'mmi_shared_assets_should_enqueue_category_guard' ) ) {
	function mmi_shared_assets_should_enqueue_category_guard( string $hook ): bool {
		if ( 'edit-tags.php' !== $hook ) {
			return false;
		}
		$taxonomy  = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		return 'product_cat' === $taxonomy && 'product' === $post_type;
	}
}

if ( ! function_exists( 'mmi_shared_assets_enqueue' ) ) {
	function mmi_shared_assets_enqueue( $hook ): void {
		// Zero-op whenever mmi-hub is present — its own Asset_Manager /
		// mmi-hub.php already register every one of these, and must remain
		// the sole source of truth to avoid any behavior change on a
		// hub-present site (see class doc comment above).
		if ( class_exists( 'MMI_Hub' ) || ! is_admin() ) {
			return;
		}

		$winner = mmi_shared_assets_winner();
		$dir    = $winner['dir'];
		$url    = $winner['url'];
		$ver    = $winner['version'];

		if ( is_file( $dir . '/css/shared/mmi-suite-common.css' ) ) {
			wp_register_style( 'mmi-suite-common', $url . 'css/shared/mmi-suite-common.css', array(), $ver );

			// Style Manager "Ship" overrides — only once a real Ship has
			// happened (the tokens file exists at all). Queued against the
			// handle now via wp_add_inline_style() even though it's only
			// registered, not yet enqueued here; WordPress holds it and
			// outputs it whenever whichever plugin's own page actually
			// enqueues 'mmi-suite-common', after the file it overrides.
			if ( class_exists( 'MMI_UI_Styles' ) && is_file( __DIR__ . '/ui-style-tokens-default.php' ) ) {
				$css_vars = array();
				foreach ( MMI_UI_Styles::field_schema() as $fields ) {
					foreach ( $fields as $key => $meta ) {
						$value = MMI_UI_Styles::published_tokens()[ $key ] ?? $meta['default'];
						$css_vars[] = $meta['css_var'] . ':' . $value . ';';
					}
				}
				if ( $css_vars ) {
					wp_add_inline_style( 'mmi-suite-common', ':root{' . implode( '', $css_vars ) . '}' );
				}
			}
		}
		if ( is_file( $dir . '/css/shared/admin-common.css' ) ) {
			wp_register_style( 'mmi-admin-common', $url . 'css/shared/admin-common.css', array( 'mmi-suite-common' ), $ver );
		}

		$scripts = array(
			'mmi-ajax-handler'      => array( 'file' => 'js/shared/ajax-handler.js', 'deps' => array( 'jquery' ) ),
			'mmi-escape-html'       => array( 'file' => 'js/shared/escape-html.js', 'deps' => array() ),
			'mmi-table-manager'     => array( 'file' => 'js/shared/table-manager.js', 'deps' => array( 'jquery' ) ),
			'mmi-taxonomy-filter'   => array( 'file' => 'js/shared/taxonomy-filter.js', 'deps' => array( 'jquery', 'mmi-ajax-handler', 'mmi-escape-html' ) ),
			'mmi-wc-filter-bar'     => array( 'file' => 'js/shared/wc-product-filter-bar.js', 'deps' => array( 'jquery', 'mmi-ajax-handler' ) ),
			'mmi-column-customizer' => array( 'file' => 'js/shared/mmi-column-customizer.js', 'deps' => array( 'jquery', 'jquery-ui-sortable' ) ),
			'mmi-modal'             => array( 'file' => 'js/shared/mmi-modal.js', 'deps' => array( 'jquery' ) ),
			'mmi-pagination'        => array( 'file' => 'js/shared/mmi-pagination.js', 'deps' => array( 'jquery' ) ),
			'mmi-resize-transition' => array( 'file' => 'js/shared/mmi-resize-transition.js', 'deps' => array() ),
			'mmi-gate'              => array( 'file' => 'js/shared/mmi-gate.js', 'deps' => array( 'jquery' ) ),
			'mmi-license-panel'     => array( 'file' => 'js/shared/license-panel.js', 'deps' => array( 'jquery' ) ),
			'mmi-condition-builder' => array( 'file' => 'js/shared/mmi-condition-builder.js', 'deps' => array( 'jquery', 'mmi-escape-html' ) ),
		);
		foreach ( $scripts as $handle => $spec ) {
			if ( is_file( $dir . '/' . $spec['file'] ) ) {
				wp_register_script( $handle, $url . $spec['file'], $spec['deps'], $ver, true );
			}
		}
		if ( wp_script_is( 'mmi-ajax-handler', 'registered' ) ) {
			wp_localize_script( 'mmi-ajax-handler', 'mmiGlobal', array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'mmi_ajax_nonce' ),
				'version'      => $ver,
				'hasVipAccess' => false,
			) );
		}
		if ( wp_script_is( 'mmi-license-panel', 'registered' ) ) {
			wp_localize_script( 'mmi-license-panel', 'mmiLicensePanel', array(
				'enterKeyLabel'          => __( 'Please enter a license key.', 'mmi-shared' ),
				'genericErrorLabel'      => __( 'Something went wrong. Please try again.', 'mmi-shared' ),
				'confirmDeactivateLabel' => __( 'Deactivate this license on this site?', 'mmi-shared' ),
			) );
		}

		// Ambient, non-dependency-declared assets mmi-hub normally injects on
		// every admin page regardless of which plugin's page is open.
		if ( is_file( $dir . '/css/shared/column-resizer.css' ) ) {
			wp_enqueue_style( 'mmi-column-resizer', $url . 'css/shared/column-resizer.css', array(), $ver );
		}
		if ( is_file( $dir . '/js/shared/column-resizer.js' ) ) {
			wp_enqueue_script( 'mmi-column-resizer', $url . 'js/shared/column-resizer.js', array( 'jquery' ), $ver, true );
		}
		// Stops a column-resize drag from also sorting, for every table on
		// every admin page (any resizer, any sort handler) — see the file.
		if ( is_file( $dir . '/js/shared/mmi-resize-sort-guard.js' ) ) {
			wp_enqueue_script( 'mmi-resize-sort-guard', $url . 'js/shared/mmi-resize-sort-guard.js', array(), $ver, false );
		}
		// Makes every heading of every MMI table sortable (client-side for
		// fully loaded tables, ?orderby= for page-linked ones) — see the file.
		if ( is_file( $dir . '/js/shared/mmi-table-sort.js' ) ) {
			wp_enqueue_script( 'mmi-table-sort', $url . 'js/shared/mmi-table-sort.js', array(), $ver, true );
		}
		if ( is_file( $dir . '/js/shared/mmi-notice-anchor.js' ) ) {
			wp_enqueue_script( 'mmi-notice-anchor', $url . 'js/shared/mmi-notice-anchor.js', array(), $ver, false );
		}
		if ( is_string( $hook ) && mmi_shared_assets_should_enqueue_category_guard( $hook )
			&& is_file( $dir . '/js/shared/product-category-ajax-guard.js' ) ) {
			wp_enqueue_script( 'mmi-product-category-ajax-guard', $url . 'js/shared/product-category-ajax-guard.js', array( 'jquery', 'wp-ajax-response' ), $ver, true );
		}
	}
}

if ( ! function_exists( 'mmi_shared_assets_body_class' ) ) {
	function mmi_shared_assets_body_class( string $classes ): string {
		if ( class_exists( 'MMI_Hub' ) ) {
			return $classes;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( strncmp( $page, 'mmi-', 4 ) === 0 ) {
			$classes .= ' mmi-page';
		}
		return $classes;
	}
}

if ( ! defined( 'MMI_SHARED_ASSETS_HOOKS_REGISTERED' ) && defined( 'ABSPATH' ) ) {
	define( 'MMI_SHARED_ASSETS_HOOKS_REGISTERED', true );
	add_action( 'admin_enqueue_scripts', 'mmi_shared_assets_enqueue', 5 );
	add_filter( 'admin_body_class', 'mmi_shared_assets_body_class' );
}

/**
 * ── Shared suite parent menu ("MannMade") ────────────────────────────────
 *
 * Before mmi-hub was deleted, every migrated plugin attached its admin page
 * as a single submenu item under mmi-hub's own MMI_Centralized_Menu top-level
 * 'mmi-dashboard' ("MannMade") menu. Each plugin's Phase 4 migration gave it
 * a `class_exists('MMI_Centralized_Menu') ? (attach under hub) : (own
 * add_menu_page())` fallback — correct while mmi-hub was still installed
 * everywhere, but mmi-hub no longer exists anywhere in this project as of
 * Phase 8 (1.173.0), so that class never exists and every migrated plugin
 * has been unconditionally taking the top-level-menu branch since — ~14
 * separate top-level sidebar entries instead of one "MannMade" parent.
 *
 * This section only restores the PARENT. Each plugin's own admin_menu
 * callback still needs its `class_exists('MMI_Centralized_Menu')` branching
 * replaced with an unconditional `add_submenu_page('mmi-dashboard', ...)` —
 * that half of the fix lives in each plugin's own admin-menu class.
 *
 * No version-negotiated candidate/winner dance is needed here, unlike the
 * class loader and shared-assets registries above: add_menu_page() just
 * needs to be called exactly once, by whichever plugin's bootstrap.php
 * copy happens to load first (function_exists()-guarded, same shape as
 * mmi_shared_assets_enqueue() above) — there's no per-plugin file-path
 * dependency the way class loading has, so there's nothing to negotiate.
 *
 * Priority 1 (not the default 10) is required, not stylistic: WordPress's
 * add_submenu_page() computes its hook suffix (used for
 * `admin_enqueue_scripts_{hook}` / `load-{hook}`) by checking whether the
 * given parent slug is ALREADY present in the global $menu array at the
 * moment it's called — if the parent hasn't been registered yet, WP
 * silently generates a "toplevel_page_*" hook suffix instead of the correct
 * "mmi-dashboard_page_*" one, breaking any plugin's per-page asset-enqueue
 * check (see AGENTS.md's Standalone Plugin Independence, bug pattern #3).
 * Registering the parent at priority 1 guarantees it exists in $menu before
 * any plugin's own default-priority-10 admin_menu callback runs.
 */

if ( ! function_exists( 'mmi_shared_menu_dashboard_plugin_info_for_slug' ) ) {
	/**
	 * Static slug -> {group, plugin file} map for the dashboard's plugin
	 * list. "file" is the same relative-to-WP_PLUGIN_DIR path get_plugins()
	 * itself keys its results by — used so each row's version/description
	 * comes from WordPress's own reading of that plugin's real header
	 * (AGENTS.md's Versioning rule already requires every plugin keep that
	 * accurate) instead of guessing at a per-plugin *_VERSION constant name.
	 * A slug not listed here (a new plugin, or one added after this map was
	 * last updated) falls into "Other" with no resolvable file — its row
	 * still renders, just without a version/description — preserving the
	 * landing page's original "reads whatever registered" resilience.
	 */
	function mmi_shared_menu_dashboard_plugin_info_for_slug( string $slug ): array {
		static $info = array(
			'mmi-reverb'           => array( 'group' => 'Commerce & Sync', 'file' => 'mmi-reverb-integration/mmi-reverb-integration.php' ),
			'mmi-data-pipeline'    => array( 'group' => 'Commerce & Sync', 'file' => 'mmi-data-pipeline/mmi-data-pipeline.php' ),
			'mmi-contracts'        => array( 'group' => 'Commerce & Sync', 'file' => 'mmi-contracts/mmi-contracts.php' ),
			'mmi-accounting'       => array( 'group' => 'Commerce & Sync', 'file' => 'mmi-accounting/mmi-accounting.php' ),
			'mmi-xchange'          => array( 'group' => 'Integrations', 'file' => 'mmi-xchange-integration/mmi-xchange-integration.php' ),
			'mmi-google-services'  => array( 'group' => 'Integrations', 'file' => 'mmi-google-services/mmi-google-services.php' ),
			'mmi-cloudflare'       => array( 'group' => 'Infrastructure', 'file' => 'mmi-cloudflare-integration/mmi-cloudflare-integration.php' ),
			'mmi-rtsm'             => array( 'group' => 'Infrastructure', 'file' => 'mmi-rtsm/mmi-rtsm.php' ),
			'mmi-2fa-bridge'       => array( 'group' => 'Infrastructure', 'file' => 'mmi-2fa-bridge/mmi-2fa-bridge.php' ),
			'mmi-admin'            => array( 'group' => 'Admin & Ops', 'file' => 'mmi-admin/mmi-admin.php' ),
			'mmi-email-customizer' => array( 'group' => 'Admin & Ops', 'file' => 'mmi-email-customizer/mmi-email-customizer.php' ),
		);
		return $info[ $slug ] ?? array(
			'group' => __( 'Other', 'mmi-shared' ),
			'file'  => '',
		);
	}
}

if ( ! function_exists( 'mmi_shared_menu_render_dashboard' ) ) {
	/**
	 * Landing page for the bare 'mmi-dashboard' parent slug itself (the item
	 * WordPress auto-creates as the parent's own first flyout entry). Reads
	 * whatever submenus actually got registered under it this request
	 * straight out of $submenu, rather than hardcoding a plugin list, so it
	 * never needs updating as plugins are added, removed, or renamed —
	 * grouped, and matched to their real Version:/Description: header via
	 * get_plugins(), by mmi_shared_menu_dashboard_plugin_info_for_slug() for
	 * the "All plugins" panel. get_plugins() is the same uncached header
	 * read wp-admin's own Plugins screen does on every load — a couple dozen
	 * small local file reads, not a DB query — so it's fine to call directly
	 * on this render path.
	 *
	 * The "Needs attention" panel is fed entirely by the 'mmi_dashboard_alerts'
	 * filter, so any plugin can surface its own already-known issue without
	 * this file knowing anything about it. Per AGENTS.md's Server Load rule, a
	 * filter callback must only report a durable, already-computed fact (a
	 * stored settings value, a cached flag) — never run a fresh query, API
	 * call, or other expensive check on this render path. Each entry:
	 * array( 'severity' => 'critical'|'warning', 'title' => string,
	 *        'description' => string, 'url' => string, 'page_slug' => string
	 *        (optional — matches a submenu slug so its row gets a left-border
	 *        severity flag) ).
	 */
	function mmi_shared_menu_render_dashboard(): void {
		global $submenu;
		$items = isset( $submenu['mmi-dashboard'] ) ? $submenu['mmi-dashboard'] : array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();

		$grouped = array();
		foreach ( $items as $item ) {
			if ( ! isset( $item[0], $item[1], $item[2] ) || 'mmi-dashboard' === $item[2] || ! current_user_can( $item[1] ) ) {
				continue;
			}
			$info                     = mmi_shared_menu_dashboard_plugin_info_for_slug( $item[2] );
			$item['mmi_version']      = $info['file'] && isset( $all_plugins[ $info['file'] ]['Version'] ) ? $all_plugins[ $info['file'] ]['Version'] : '';
			$item['mmi_description']  = $info['file'] && isset( $all_plugins[ $info['file'] ]['Description'] ) ? wp_strip_all_tags( $all_plugins[ $info['file'] ]['Description'] ) : '';
			// class_exists() triggers the version-negotiated autoloader itself
			// (default $autoload = true), so this alone is enough to pull in
			// whichever copy of MMI_License_UI actually won negotiation.
			$item['mmi_license_badge'] = class_exists( 'MMI_License_UI' )
				? MMI_License_UI::render_header_badge( $item[2] )
				: '';
			$grouped[ $info['group'] ][] = $item;
		}

		$alerts        = apply_filters( 'mmi_dashboard_alerts', array() );
		$alert_by_slug = array();
		foreach ( $alerts as $alert ) {
			if ( ! empty( $alert['page_slug'] ) ) {
				$alert_by_slug[ $alert['page_slug'] ] = in_array( $alert['severity'] ?? '', array( 'critical', 'warning' ), true )
					? $alert['severity']
					: 'warning';
			}
		}

		echo '<div class="wrap mmi-page">';
		echo '<div class="mmi-header"><h1><span class="dashicons dashicons-store"></span> ' . esc_html__( 'MannMade', 'mmi-shared' ) . '</h1>';
		echo '<p class="mmi-header-description">' . esc_html__( 'Choose a plugin from the menu on the left.', 'mmi-shared' ) . '</p></div>';
		echo '<div class="wp-header-end"></div>';

		echo '<div class="mmi-dashboard-layout">';

		echo '<div class="mmi-dashboard-attention"><div class="mmi-card mmi-dashboard-panel">';
		echo '<h2>' . esc_html__( 'Needs attention', 'mmi-shared' ) . '</h2>';
		echo '<div class="mmi-card-body">';
		if ( empty( $alerts ) ) {
			echo '<p class="mmi-dashboard-empty">' . esc_html__( 'Nothing needs attention right now.', 'mmi-shared' ) . '</p>';
		} else {
			echo '<div class="mmi-dashboard-alerts">';
			foreach ( $alerts as $alert ) {
				if ( ! isset( $alert['title'], $alert['description'] ) ) {
					continue;
				}
				$severity = in_array( $alert['severity'] ?? '', array( 'critical', 'warning' ), true ) ? $alert['severity'] : 'warning';
				$pill     = 'critical' === $severity ? __( 'Critical', 'mmi-shared' ) : __( 'Warning', 'mmi-shared' );
				$link     = ! empty( $alert['url'] )
					? sprintf( '<a class="mmi-dashboard-alert-link" href="%s">%s &rarr;</a>', esc_url( $alert['url'] ), esc_html__( 'Open', 'mmi-shared' ) )
					: '';
				printf(
					'<div class="mmi-dashboard-alert mmi-dashboard-alert--%1$s"><span class="mmi-dashboard-alert-pill mmi-dashboard-alert-pill--%1$s">%2$s</span><div class="mmi-dashboard-alert-title">%3$s</div><div class="mmi-dashboard-alert-desc">%4$s</div>%5$s</div>',
					esc_attr( $severity ),
					esc_html( $pill ),
					esc_html( $alert['title'] ),
					esc_html( $alert['description'] ),
					$link
				);
			}
			echo '</div>';
		}
		echo '</div></div></div>';

		echo '<div class="mmi-dashboard-plugins"><div class="mmi-card mmi-dashboard-panel">';
		echo '<h2>' . esc_html__( 'All plugins', 'mmi-shared' ) . '</h2>';
		echo '<div class="mmi-card-body mmi-dashboard-groups">';
		foreach ( $grouped as $group_label => $group_items ) {
			echo '<div class="mmi-dashboard-group">';
			echo '<div class="mmi-dashboard-group-label">' . esc_html( $group_label ) . '</div>';
			echo '<ul class="mmi-dashboard-plugin-list">';
			foreach ( $group_items as $item ) {
				$row_class = 'mmi-dashboard-plugin-row';
				if ( isset( $alert_by_slug[ $item[2] ] ) ) {
					$row_class .= ' mmi-dashboard-plugin-row--' . $alert_by_slug[ $item[2] ];
				}
				$version = $item['mmi_version']
					? sprintf( '<span class="mmi-dashboard-plugin-version">%s</span>', esc_html( 'v' . $item['mmi_version'] ) )
					: '';
				$desc    = $item['mmi_description']
					? sprintf( '<span class="mmi-dashboard-plugin-desc" title="%1$s">%2$s</span>', esc_attr( $item['mmi_description'] ), esc_html( $item['mmi_description'] ) )
					: '';
				$badge   = $item['mmi_license_badge'] ?? '';
				printf(
					'<li class="%1$s"><a href="%2$s"><span class="mmi-dashboard-plugin-name">%3$s</span><span class="mmi-dashboard-plugin-meta">%4$s%5$s%6$s</span></a></li>',
					esc_attr( $row_class ),
					esc_url( admin_url( 'admin.php?page=' . $item[2] ) ),
					esc_html( wp_strip_all_tags( $item[0] ) ),
					$version,
					$desc,
					$badge // Already escaped inside MMI_License_UI::render_header_badge().
				);
			}
			echo '</ul></div>';
		}
		echo '</div></div></div>';

		echo '</div>'; // .mmi-dashboard-layout
		echo '</div>'; // .wrap
	}
}

if ( ! function_exists( 'mmi_shared_menu_add_parent' ) ) {
	function mmi_shared_menu_add_parent(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		add_menu_page(
			__( 'MannMade', 'mmi-shared' ),
			__( 'MannMade', 'mmi-shared' ),
			'manage_options',
			'mmi-dashboard',
			'mmi_shared_menu_render_dashboard',
			'dashicons-store',
			3
		);
	}
}

if ( ! defined( 'MMI_SHARED_MENU_HOOK_REGISTERED' ) && defined( 'ABSPATH' ) ) {
	define( 'MMI_SHARED_MENU_HOOK_REGISTERED', true );
	add_action( 'admin_menu', 'mmi_shared_menu_add_parent', 1 );
}
