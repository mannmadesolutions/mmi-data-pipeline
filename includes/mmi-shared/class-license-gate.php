<?php
/**
 * MMI_License_Gate — the whole-plugin license/trial boot gate (bundled/vendored copy).
 *
 * Introduced 2026-09-22 to replace 10 plugins' hand-rolled bare `mmi_is_licensed()`
 * boot gate (no trial support, and the whole plugin silently vanishes — no menu,
 * no way for a locked-out customer to find an activation page) and 3 plugins'
 * complete absence of any gate at all. `mmi-data-pipeline` had already independently
 * arrived at the better pattern (`mmi_is_licensed_or_trialing()` + "menu always
 * renders, only the real feature classes are gated") — this class is that same
 * pattern, extracted once so every other plugin gets it by calling in, rather than
 * by copy-pasting an increasingly-stale gate by hand. Do not hand-edit this file in
 * a single plugin; edit the canonical source (`mmi-admin/lib/mmi-shared/class-license-gate.php`)
 * and re-sync via `bash mmi-admin/lib/sync-to-plugins.sh` to every plugin that bundles it.
 *
 * Deliberately thin, matching MMI_Feature_Gate's own style: two static methods, no
 * state of its own. `is_open()` wraps `mmi_is_licensed_or_trialing()` (itself
 * already shared, unchanged here) so every caller gets automatic trial support for
 * free. `render_locked_page()` reuses `MMI_License_UI::render_panel()` for the real
 * activation form rather than inventing new UI — this class only adds the
 * consistent wrapper/messaging around it.
 *
 * Usage (a plugin's own main file):
 *   add_action('plugins_loaded', function () {
 *       // Menu registration always runs — see class doc comment above for why.
 *       if (is_admin()) { My_Plugin_Admin::init(); }
 *       if (!MMI_License_Gate::is_open('my-plugin-slug')) { return; }
 *       // ...real feature classes, only reached when licensed/trialing...
 *   });
 *
 * And inside that plugin's own page-render callback:
 *   if (!MMI_License_Gate::is_open('my-plugin-slug')) {
 *       MMI_License_Gate::render_locked_page('my-plugin-slug', 'My Plugin');
 *       return;
 *   }
 *   // ...real page content...
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

final class MMI_License_Gate {

	/**
	 * The one check every plugin's boot gate and page-render callback should
	 * use. Wraps `mmi_is_licensed_or_trialing()` — calling this MAY have the
	 * side effect of starting or resuming this domain's trial for $slug (see
	 * that function's own doc comment); that is intentional and is what makes
	 * a fresh install "just work" for 14 days with no manual step.
	 *
	 * @param  string $slug  Plugin slug, as registered with the license server.
	 * @return bool
	 */
	public static function is_open( string $slug ): bool {
		if ( ! function_exists( 'mmi_is_licensed_or_trialing' ) ) {
			// Fail closed: an absent licensing function must never be silently
			// read as "licensed" — see AGENTS.md's Standalone Independence
			// section on the fail-open anti-pattern found 8+ times already.
			return false;
		}
		return mmi_is_licensed_or_trialing( $slug );
	}

	/**
	 * Render the consistent "locked" admin page: a short, honest status line
	 * (trial expired vs. never licensed — whichever actually happened) plus
	 * the real activation form. Caller has already confirmed `!is_open()`
	 * before reaching this — this method does not re-check.
	 *
	 * @param string $slug          Plugin slug, same value passed to is_open().
	 * @param string $plugin_label  Human display name, e.g. "Real-Time Server Monitor".
	 */
	public static function render_locked_page( string $slug, string $plugin_label ): void {
		$trial_expired = false;

		if ( class_exists( 'MMI_Settings' ) ) {
			$data = MMI_Settings::get( 'mmi_license_' . $slug );
			$trial_expired = ! empty( $data['is_trial'] )
				&& ! empty( $data['trial_ends_at'] )
				&& time() > strtotime( $data['trial_ends_at'] );
		}
		?>
		<div class="wrap mmi-page">
			<div class="mmi-header">
				<h1><?php echo esc_html( $plugin_label ); ?></h1>
				<p class="mmi-header-description">
					<?php if ( $trial_expired ) : ?>
						<?php esc_html_e( 'Your free trial has ended. Enter a license key below to keep using this plugin.', 'mmi-hub' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'This plugin requires an active license or trial. Enter a license key below, or reload this page to start a free trial automatically.', 'mmi-hub' ); ?>
					<?php endif; ?>
				</p>
			</div>
			<div class="wp-header-end"></div>
			<?php
			if ( class_exists( 'MMI_License_UI' ) ) {
				MMI_License_UI::render_panel( $slug, $plugin_label );
			}
			?>
		</div>
		<?php
	}
}
