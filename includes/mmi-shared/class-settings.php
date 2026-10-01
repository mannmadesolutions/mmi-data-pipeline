<?php
/**
 * MMI Settings — Custom Table Options Store (bundled/vendored copy).
 *
 * Identical behavior to the original mmi-hub/includes/class-settings.php this
 * was vendored from — same wp_mmi table, same tab-derivation rules, same
 * transparent wp_options migration. Only ever loaded once per request, by
 * whichever plugin's bundled copy wins version negotiation in bootstrap.php —
 * see ADR-0006. Do not hand-edit this file in a single plugin; edit the
 * canonical source and re-sync to every plugin that bundles it.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_Settings {

	/* ── Request-level cache ──────────────────────────────────────── */
	private static array $cache = array();

	// Sentinel for "not found in cache" (distinguishes from a stored false/null).
	private static $NOT_FOUND;

	/* ── Table name (resolved at first use) ────────────────────────── */
	private static ?string $table = null;
	private static bool $table_ensured = false;

	private static function table(): string {
		if ( self::$table === null ) {
			global $wpdb;
			self::$table = $wpdb->prefix . 'mmi';
		}
		self::maybe_create_table();
		return self::$table;
	}

	/**
	 * Public escape hatch for code with a legitimate reason to write raw SQL
	 * against wp_mmi directly instead of get()/set() — e.g. a caller that
	 * needs an explicit tab_name a suite-wide cross-plugin convention already
	 * depends on (like the shared "Credentials & API Keys" tab several
	 * plugins filter on directly), which set()'s own tab_from_key()
	 * derivation has no way to override. Guarantees the table exists (same
	 * self-healing as every other entry point) and returns its real name.
	 * Still prefer get()/set() whenever the derived tab is correct — this
	 * exists for the narrow case where it isn't, not as a general workaround.
	 */
	public static function ensure_table(): string {
		return self::table();
	}

	/**
	 * Create wp_mmi if it doesn't exist yet — idempotent, cheap after the
	 * first call (short-circuits on the static flag). mmi-hub's own
	 * installer never creates this table either; historically it only ever
	 * existed because a one-time migration script
	 * (mmi-hub/includes/migration/migrate-credentials-to-mmi-table.php)
	 * happened to run once and create it. That script itself requires
	 * mmi-hub, so a genuinely standalone site (mmi-hub never installed)
	 * would otherwise never get this table at all — every get()/set() would
	 * silently no-op against a table that doesn't exist (WPDB degrades
	 * quietly, it doesn't fatal) rather than actually persisting anything.
	 * Schema matches that migration script's CREATE TABLE exactly, so a
	 * table created here is indistinguishable from one mmi-hub created.
	 */
	private static function maybe_create_table(): void {
		if ( self::$table_ensured ) {
			return;
		}
		self::$table_ensured = true;

		global $wpdb;
		$table = self::$table;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
            id INT(11) NOT NULL AUTO_INCREMENT,
            tab_name VARCHAR(255) NOT NULL,
            field_name VARCHAR(255) NOT NULL,
            field_value LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tab_field (tab_name, field_name)
        ) {$charset_collate};";

		dbDelta( $sql );

		if ( class_exists( 'MMI_Logger' ) ) {
			MMI_Logger::info( 'wp_mmi created (self-healing, no mmi-hub required)', array(), 'database', 'MMI_Settings' );
		}
	}

	/* ── Tab derivation ────────────────────────────────────────────── */

	public static function tab_from_key( string $key ): string {
		$prefixes = array(
			'mmi_cf_'         => 'CloudFlare',
			'mmi_cloudflare'  => 'CloudFlare',
			'rtsm_cloudflare' => 'CloudFlare',
			'cloudflare-'     => 'CloudFlare',
			'cf-'             => 'CloudFlare',
			'mmi_reverb'      => 'Reverb',
			'reverb_api'      => 'Reverb',
			'mmi_ga4_'        => 'Google Services',
			'mmi_gmc_'        => 'Google Services',
			'mmi_gsc_'        => 'Google Services',
			'mmi_google'      => 'Google Services',
			'rtsm_'           => 'Server Monitor',
			'wp_throttle_'    => 'Throttler',
			'mmi_vip_'        => 'VIP',
			'mmi_stripe_'     => 'Stripe',
			'mmi_hub_'        => 'MMI Hub',
		);
		foreach ( $prefixes as $prefix => $tab ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return $tab;
			}
		}
		return 'Settings';
	}

	/* ── Core API ──────────────────────────────────────────────────── */

	public static function get( string $key, $default = false ) {
		if ( ! isset( self::$NOT_FOUND ) ) {
			self::$NOT_FOUND = new stdClass();
		}

		if ( array_key_exists( $key, self::$cache ) ) {
			$cached = self::$cache[ $key ];
			return ( $cached === self::$NOT_FOUND ) ? $default : $cached;
		}

		global $wpdb;
		$row = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT field_value FROM ' . self::table() . ' WHERE field_name = %s LIMIT 1',
				$key
			)
		);

		if ( $row !== null ) {
			$value               = maybe_unserialize( $row );
			self::$cache[ $key ] = $value;
			return $value;
		}

		// ── Transparent migration from wp_options ──────────────────────
		self::$cache[ $key ] = self::$NOT_FOUND;
		$legacy               = get_option( $key, null );
		if ( $legacy !== null ) {
			self::set( $key, $legacy ); // persist to wp_mmi
			delete_option( $key );      // remove from wp_options
			return $legacy;
		}

		self::$cache[ $key ] = self::$NOT_FOUND;
		return $default;
	}

	public static function set( string $key, $value ): bool {
		global $wpdb;

		self::$cache[ $key ] = $value;

		$result = $wpdb->replace(
			self::table(),
			array(
				'tab_name'    => self::tab_from_key( $key ),
				'field_name'  => $key,
				'field_value' => maybe_serialize( $value ),
			),
			array( '%s', '%s', '%s' )
		);
		return $result !== false;
	}

	public static function delete( string $key ): bool {
		global $wpdb;
		unset( self::$cache[ $key ] );
		return (bool) $wpdb->delete( self::table(), array( 'field_name' => $key ), array( '%s' ) );
	}

	/* ── Bulk helpers ──────────────────────────────────────────────── */

	public static function get_tab( string $tab ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT field_name, field_value FROM ' . self::table() . ' WHERE tab_name = %s',
				$tab
			)
		);
		$result = array();
		foreach ( $rows as $row ) {
			$value                          = maybe_unserialize( $row->field_value );
			self::$cache[ $row->field_name ] = $value;
			$result[ $row->field_name ]      = $value;
		}
		return $result;
	}

	public static function delete_tab( string $tab ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'tab_name' => $tab ), array( '%s' ) );
		foreach ( array_keys( self::$cache ) as $key ) {
			if ( self::tab_from_key( $key ) === $tab ) {
				unset( self::$cache[ $key ] );
			}
		}
	}

	public static function preload( array $keys ): void {
		if ( empty( $keys ) ) {
			return;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$rows         = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare(
				'SELECT field_name, field_value FROM ' . self::table() . " WHERE field_name IN ($placeholders)",
				...$keys
			)
		);
		$found = array();
		foreach ( $rows as $row ) {
			$value                          = maybe_unserialize( $row->field_value );
			self::$cache[ $row->field_name ] = $value;
			$found[]                        = $row->field_name;
		}
		if ( ! isset( self::$NOT_FOUND ) ) {
			self::$NOT_FOUND = new stdClass();
		}
		foreach ( array_diff( $keys, $found ) as $missing ) {
			self::$cache[ $missing ] = self::$NOT_FOUND;
		}
	}

	public static function clear_cache(): void {
		self::$cache = array();
	}
}
