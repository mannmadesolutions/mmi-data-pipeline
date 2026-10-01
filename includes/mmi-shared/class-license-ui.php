<?php
/**
 * MMI License UI (bundled/vendored copy) — shared activation/trial panel.
 *
 * Identical across every mmi-* plugin. Do not hand-edit this file in a
 * single plugin; edit the canonical source (mmi-admin/lib/mmi-shared/) and
 * re-sync (sync-to-plugins.sh) to every plugin that bundles it.
 *
 * Renders the one activation/trial UI every plugin needs (a license-key
 * field, a trial countdown, activate/deactivate buttons) and handles the two
 * AJAX actions behind it. A plugin adopts this by calling
 * MMI_License_UI::render_panel() on its own settings page and
 * MMI_License_UI::render_header_badge() inside its .mmi-header-alerts markup
 * — no per-plugin AJAX handler or markup of its own required.
 *
 * The trial itself is started automatically (see mmi_is_licensed_or_trialing()
 * in licensing-helpers.php) — there is no "Start Trial" button. This class
 * only covers entering a real purchased key and showing time remaining.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_License_UI {

	const NONCE_ACTION = 'mmi_license_ui_nonce';

	/* ── Read helpers ───────────────────────────────────────────────────── */

	/** "mmi-suite" → "MMI Suite" (plain ucwords() gives "Mmi Suite"). */
	private static function scope_label( string $scope ): string {
		return (string) preg_replace( '/\bMmi\b/', 'MMI', ucwords( str_replace( '-', ' ', $scope ) ) );
	}

	/**
	 * @return array{state:string,data:array} state is one of
	 *         'licensed' | 'trial' | 'trial_expired' | 'unlicensed'.
	 */
	private static function resolve_state( string $plugin_slug ): array {
		if ( ! class_exists( 'MMI_Settings' ) ) {
			return [ 'state' => 'unlicensed', 'data' => [] ];
		}

		// Pick up license-server changes (a deleted/suspended key) within the
		// hour rather than at the next daily run — throttled, see the helper.
		if ( function_exists( 'mmi_license_maybe_revalidate' ) ) {
			mmi_license_maybe_revalidate();
		}

		$data = MMI_Settings::get( 'mmi_license_' . $plugin_slug );

		// A paid license covering this plugin wins over its own leftover
		// trial entry — otherwise a suite key activated elsewhere still
		// shows "N days left in trial" here.
		$paid_scope = function_exists( 'mmi_license_covering_scope' ) ? mmi_license_covering_scope( $plugin_slug, true ) : null;
		if ( $paid_scope ) {
			return [
				'state' => 'licensed',
				'scope' => $paid_scope,
				'data'  => MMI_Settings::get( 'mmi_license_' . $paid_scope ) ?: [],
			];
		}

		if ( function_exists( 'mmi_is_licensed' ) && mmi_is_licensed( $plugin_slug ) ) {
			if ( function_exists( 'mmi_is_trial_active' ) && mmi_is_trial_active( $plugin_slug ) ) {
				return [ 'state' => 'trial', 'data' => $data ?: [] ];
			}
			return [ 'state' => 'licensed', 'data' => $data ?: [] ];
		}

		if ( ! empty( $data['is_trial'] ) ) {
			return [ 'state' => 'trial_expired', 'data' => $data ];
		}

		return [ 'state' => 'unlicensed', 'data' => $data ?: [] ];
	}

	/* ── Settings-page panel ───────────────────────────────────────────── */

	/**
	 * Render the full activation/trial panel for a plugin's own settings
	 * page. Safe to call unconditionally — renders the right state on its
	 * own.
	 *
	 * @param string $plugin_slug  e.g. 'mmi-data-pipeline'.
	 * @param string $plugin_label Display name, e.g. 'Data Pipeline'.
	 */
	public static function render_panel( string $plugin_slug, string $plugin_label ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$resolved = self::resolve_state( $plugin_slug );
		$state    = $resolved['state'];
		$data     = $resolved['data'];
		$nonce    = wp_create_nonce( self::NONCE_ACTION );

		echo '<div class="mmi-card mmi-license-panel" data-mmi-license-slug="' . esc_attr( $plugin_slug ) . '">';
		echo '<h2>' . esc_html__( 'License', 'mmi-shared' ) . '</h2>';
		echo '<div class="mmi-card-body">';

		if ( 'licensed' === $state ) {
			$scope   = $resolved['scope'] ?? $plugin_slug;
			$key     = (string) ( $data['key'] ?? '' );
			$via     = $scope !== $plugin_slug;
			echo '<p class="mmi-license-status mmi-license-status--active">';
			echo '<span class="dashicons dashicons-yes-alt"></span> ';
			if ( $via ) {
				printf(
					/* translators: 1: plugin display name, 2: bundle name, e.g. "MMI Suite" */
					esc_html__( '%1$s is licensed through %2$s.', 'mmi-shared' ),
					esc_html( $plugin_label ),
					esc_html( self::scope_label( $scope ) )
				);
			} else {
				printf(
					/* translators: %s: plugin display name */
					esc_html__( '%s is licensed and active.', 'mmi-shared' ),
					esc_html( $plugin_label )
				);
			}
			echo '</p>';
			$grace_end = function_exists( 'mmi_license_grace_ends_at' ) ? mmi_license_grace_ends_at( $data['expiry_date'] ?? null ) : null;
			if ( $grace_end !== null && time() > strtotime( (string) $data['expiry_date'] ) ) {
				echo '<p class="mmi-license-status mmi-license-status--grace"><span class="dashicons dashicons-warning"></span> ' . esc_html(
					sprintf(
						/* translators: 1: expiry date, 2: date features stop */
						__( 'This license expired on %1$s. Everything keeps working until %2$s — renew before then to avoid interruption.', 'mmi-shared' ),
						date_i18n( get_option( 'date_format' ), strtotime( (string) $data['expiry_date'] ) ),
						date_i18n( get_option( 'date_format' ), $grace_end )
					)
				) . '</p>';
			}
			if ( $key !== '' ) {
				echo '<p class="mmi-license-hint">' . esc_html(
					sprintf(
						/* translators: %s: last segment of the license key */
						__( 'License key ending in %s', 'mmi-shared' ),
						substr( $key, -4 )
					)
				) . '</p>';
			}
			if ( $via ) {
				echo '<p class="mmi-license-hint">' . esc_html__( 'Deactivating removes this site from that license for every plugin it covers.', 'mmi-shared' ) . '</p>';
			}
			self::render_deactivate_form( $scope, $nonce );
		} elseif ( 'trial' === $state ) {
			$days = function_exists( 'mmi_trial_days_remaining' ) ? mmi_trial_days_remaining( $plugin_slug ) : null;
			echo '<p class="mmi-license-status mmi-license-status--trial">';
			echo '<span class="dashicons dashicons-clock"></span> ';
			printf(
				/* translators: %1$d: days remaining, %2$s: plugin display name */
				esc_html( _n( 'Free trial — %1$d day left on %2$s.', 'Free trial — %1$d days left on %2$s.', (int) $days, 'mmi-shared' ) ),
				(int) $days,
				esc_html( $plugin_label )
			);
			echo '</p>';
			echo '<p class="mmi-license-hint">' . esc_html__( 'Enter a license key any time to keep full access after the trial ends.', 'mmi-shared' ) . '</p>';
			self::render_activate_form( $plugin_slug, $nonce );
		} elseif ( 'trial_expired' === $state ) {
			echo '<p class="mmi-license-status mmi-license-status--expired">';
			echo '<span class="dashicons dashicons-warning"></span> ';
			printf(
				/* translators: %s: plugin display name */
				esc_html__( 'Your free trial of %s has ended.', 'mmi-shared' ),
				esc_html( $plugin_label )
			);
			echo '</p>';
			self::render_activate_form( $plugin_slug, $nonce );
			echo '<p class="mmi-license-hint"><a href="' . esc_url( self::purchase_url( $plugin_slug ) ) . '" class="mmi-btn mmi-btn-primary" target="_blank" rel="noopener">' . esc_html__( 'Buy a License', 'mmi-shared' ) . '</a></p>';
		} else {
			echo '<p class="mmi-license-status mmi-license-status--unlicensed">' . esc_html__( 'No license active yet.', 'mmi-shared' ) . '</p>';
			self::render_activate_form( $plugin_slug, $nonce );
		}

		echo '</div></div>';
	}

	private static function render_activate_form( string $plugin_slug, string $nonce ): void {
		?>
		<form class="mmi-license-activate-form" data-mmi-license-slug="<?php echo esc_attr( $plugin_slug ); ?>" data-mmi-license-nonce="<?php echo esc_attr( $nonce ); ?>">
			<input type="text" class="regular-text mmi-license-key-field" placeholder="<?php esc_attr_e( 'MMI-XXXX-0000-0000-0000', 'mmi-shared' ); ?>" autocomplete="off" />
			<button type="submit" class="mmi-btn mmi-btn-primary mmi-license-activate-btn">
				<span class="dashicons dashicons-unlock"></span> <?php esc_html_e( 'Activate', 'mmi-shared' ); ?>
			</button>
			<span class="mmi-license-form-message" role="status"></span>
		</form>
		<?php
	}

	private static function render_deactivate_form( string $plugin_slug, string $nonce ): void {
		?>
		<form class="mmi-license-deactivate-form" data-mmi-license-slug="<?php echo esc_attr( $plugin_slug ); ?>" data-mmi-license-nonce="<?php echo esc_attr( $nonce ); ?>">
			<button type="submit" class="mmi-btn mmi-btn-secondary mmi-license-deactivate-btn">
				<span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Deactivate', 'mmi-shared' ); ?>
			</button>
			<span class="mmi-license-form-message" role="status"></span>
		</form>
		<?php
	}

	/* ── Header badge ──────────────────────────────────────────────────── */

	/**
	 * Compact countdown/status chip for a plugin's own .mmi-header-alerts
	 * row (Admin Page Shell convention). Returns '' when there's nothing
	 * worth a header badge for (a fully licensed, non-trial plugin).
	 */
	public static function render_header_badge( string $plugin_slug ): string {
		$resolved = self::resolve_state( $plugin_slug );

		if ( 'trial' === $resolved['state'] ) {
			$days = function_exists( 'mmi_trial_days_remaining' ) ? mmi_trial_days_remaining( $plugin_slug ) : null;
			return sprintf(
				'<span class="mmi-trial-badge mmi-trial-badge--active"><span class="dashicons dashicons-clock"></span> %s</span>',
				esc_html( sprintf(
					/* translators: %d: days remaining */
					_n( '%d day left in trial', '%d days left in trial', (int) $days, 'mmi-shared' ),
					(int) $days
				) )
			);
		}

		if ( 'trial_expired' === $resolved['state'] ) {
			return '<span class="mmi-trial-badge mmi-trial-badge--expired"><span class="dashicons dashicons-warning"></span> '
				. esc_html__( 'Trial expired', 'mmi-shared' ) . '</span>';
		}

		return '';
	}

	private static function purchase_url( string $plugin_slug ): string {
		return apply_filters( 'mmi_license_purchase_url', 'https://mannmade.us/plugins/' . $plugin_slug . '/', $plugin_slug );
	}

	/* ── AJAX ──────────────────────────────────────────────────────────── */

	public static function ajax_activate(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			self::audit( 'license.activate', '', 'denied' );
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'mmi-shared' ) ], 403 );
		}

		$plugin_slug = isset( $_POST['plugin_slug'] ) ? sanitize_key( $_POST['plugin_slug'] ) : '';
		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

		if ( ! $plugin_slug || ! $license_key ) {
			wp_send_json_error( [ 'message' => __( 'A license key is required.', 'mmi-shared' ) ] );
		}

		if ( ! class_exists( 'MMI_License_Client' ) ) {
			wp_send_json_error( [ 'message' => __( 'License system unavailable.', 'mmi-shared' ) ] );
		}

		$result = MMI_License_Client::instance()->activate( $license_key, $plugin_slug );

		if ( empty( $result['valid'] ) ) {
			self::audit( 'license.activate', $plugin_slug, 'failure', [ 'key_hint' => self::key_hint( $license_key ), 'network_error' => ! empty( $result['network_error'] ) ] );
			wp_send_json_error( [ 'message' => $result['error'] ?? __( 'Could not activate that license key.', 'mmi-shared' ) ] );
		}

		self::audit( 'license.activate', $plugin_slug, 'success', [ 'key_hint' => self::key_hint( $license_key ), 'scope' => (string) ( $result['scope'] ?? $plugin_slug ) ] );
		wp_send_json_success( [ 'message' => __( 'License activated.', 'mmi-shared' ) ] );
	}

	public static function ajax_deactivate(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			self::audit( 'license.deactivate', '', 'denied' );
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'mmi-shared' ) ], 403 );
		}

		$plugin_slug = isset( $_POST['plugin_slug'] ) ? sanitize_key( $_POST['plugin_slug'] ) : '';
		if ( ! $plugin_slug || ! class_exists( 'MMI_Settings' ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to deactivate.', 'mmi-shared' ) ] );
		}

		$data = MMI_Settings::get( 'mmi_license_' . $plugin_slug );
		$key  = $data['key'] ?? '';

		if ( $key && class_exists( 'MMI_License_Client' ) ) {
			MMI_License_Client::instance()->deactivate( $key, $plugin_slug );
		} elseif ( class_exists( 'MMI_License_Client' ) ) {
			MMI_License_Client::instance()->clear( $plugin_slug );
		}

		self::audit( 'license.deactivate', $plugin_slug, 'success', [ 'key_hint' => self::key_hint( (string) $key ) ] );
		wp_send_json_success( [ 'message' => __( 'License deactivated.', 'mmi-shared' ) ] );
	}

	/* ── Audit ─────────────────────────────────────────────────────────── */

	/** Last four characters only — never the full key. */
	private static function key_hint( string $key ): string {
		return $key === '' ? '' : '…' . substr( $key, -4 );
	}

	private static function audit( string $action, string $scope, string $outcome, array $details = [] ): void {
		if ( ! class_exists( 'MMI_Audit_Log' ) ) {
			return;
		}
		MMI_Audit_Log::record( 'mmi-shared', $action, [
			'object_type' => 'license',
			'object_id'   => $scope,
			'outcome'     => $outcome,
			'details'     => $details,
		] );
	}
}
